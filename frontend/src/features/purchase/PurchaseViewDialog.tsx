import { Wallet } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { DialogSubject, FormSection } from '../../components/dialog-parts.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { FieldsSkeleton, SkeletonRegion, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Badge, Button, ButtonLink, ConfirmDialog, Modal, ModalActions } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { useDialog, type DialogProps } from '../../lib/dialog.ts'
import { formatDate, formatDateTime, formatIsoDate, formatQuantity, formatVnd } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useToast } from '../../lib/toast.ts'
import { useAuth } from '../auth/auth-context.ts'
import { ExpiryBadge } from '../inventory/parts.tsx'
import { usePurchase, useVoidPayment, type PurchasePayment } from './api.ts'
import { PaymentBadge } from './parts.tsx'

const money = (value: string | undefined) => formatVnd(Number(value ?? 0))

/**
 * Popup chi tiết một phiếu nhập hàng (`?dialog=purchase:<id>`): nhà cung cấp, kho, tiền hàng / chi phí kèm theo / đã trả / còn nợ, từng dòng hàng (đơn giá nhập và giá vốn đã gồm
 * chi phí kèm theo), các lần trả tiền (hủy được lần trả nhầm) và liên kết tới phiếu nhập kho. Từ đây mở popup trả tiền (`purchase-pay`).
 */
export default function PurchaseViewDialog({ arg: id, onClose }: DialogProps) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { link } = useDialog()
  const purchase = usePurchase(id)
  const data = purchase.data
  const lines = data?.lines ?? []
  const payments = data?.payments ?? []
  const notFound = purchase.error instanceof ApiError && (purchase.error.status === 404 || purchase.error.status === 403)
  const canPay = can('purchase.order.update') && data !== undefined && Number(data.balance) > 0
  const canVoid = can('purchase.order.cancel')
  const [voiding, setVoiding] = useState<PurchasePayment | null>(null)

  return (
    <Modal
      size="xl"
      fixedHeight="sheet"
      onClose={onClose}
      title={t('purchase.detail.title')}
      description={<DialogSubject name={data ? `${data.code} · ${data.supplier.name}` : undefined} badge={data && <PaymentBadge status={data.payment_status} />} />}
    >
      {purchase.isError ? (
        <Alert tone="danger">{notFound ? t('purchase.detail.notFound') : t('common.loadError')}</Alert>
      ) : !data ? (
        <SkeletonRegion className="space-y-5">
          <FieldsSkeleton fields={4} />
          <TableSkeleton columns={5} rows={3} minWidth="0" />
        </SkeletonRegion>
      ) : (
        <div className="flex min-h-full flex-col gap-5">
          <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt className="text-slate-500">{t('purchase.form.receivedOn')}</dt><dd className="font-medium">{formatIsoDate(data.received_on)}</dd></div>
            <div><dt className="text-slate-500">{t('purchase.form.warehouse')}</dt><dd className="font-medium">{data.warehouse.name} <span className="text-slate-500">({data.branch.code})</span></dd></div>
            <div><dt className="text-slate-500">{t('purchase.form.supplierRef')}</dt><dd className="font-medium">{data.supplier_ref ?? '—'}</dd></div>
            <div><dt className="text-slate-500">{t('purchase.detail.by')}</dt><dd className="font-medium">{data.created_by?.name ?? t('inventory.documents.system')}</dd></div>
            <div>
              <dt className="text-slate-500">{t('purchase.detail.stockDocument')}</dt>
              <dd><Link {...link('stock-doc', data.stock_document.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{data.stock_document.code}</Link></dd>
            </div>
            {data.note && <div className="sm:col-span-2 lg:col-span-3"><dt className="text-slate-500">{t('inventory.form.note')}</dt><dd>{data.note}</dd></div>}
          </dl>

          <div className="grid gap-3 sm:grid-cols-4">
            <Stat label={t('purchase.detail.goodsTotal')} value={money(data.goods_total)} />
            <Stat label={t('purchase.detail.extraCost')} value={money(data.extra_cost)} hint={Number(data.extra_cost) > 0 ? t('purchase.detail.extraHint') : undefined} />
            <Stat label={t('purchase.detail.paid')} value={money(data.paid_amount)} />
            <Stat label={t('purchase.detail.balance')} value={money(data.balance)} strong={Number(data.balance) > 0} />
          </div>

          <FormSection title={t('inventory.form.lines')}>
            <DataTable minWidth="44rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.columns.product')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.batch')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('purchase.detail.quantity')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('purchase.detail.unitCost')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('purchase.detail.lineTotal')}</th>
                  <th scope="col" className="py-2.5 text-right">{t('purchase.detail.cost')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {lines.map((line, index) => (
                  <tr key={`${line.product.id}-${line.batch?.id ?? index}`}>
                    <td className="py-3 pr-4"><span className="font-mono text-xs">{line.product.sku}</span> <span className="font-medium text-slate-900">{line.product.name}</span></td>
                    <td className="py-3 pr-4">{line.batch ? <>{line.batch.batch_no}<span className="block text-xs"><ExpiryBadge date={line.batch.expires_at} /></span></> : '—'}</td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap">{formatQuantity(line.quantity)} {line.unit}</td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap">{money(line.unit_cost)}</td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap font-medium">{money(line.line_total)}</td>
                    <td className="py-3 text-right whitespace-nowrap">{money(line.cost)}<span className="block text-xs text-slate-500">/ {line.unit}</span></td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </FormSection>

          <FormSection title={t('purchase.detail.payments')}>
            {payments.length === 0 ? (
              <p className="text-sm text-slate-500">{t('purchase.detail.noPayments')}</p>
            ) : (
              <DataTable minWidth="36rem">
                <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                  <tr>
                    <th scope="col" data-role="title" className="py-2.5 pr-4">{t('purchase.pay.paidOn')}</th>
                    <th scope="col" className="py-2.5 pr-4">{t('purchase.form.method')}</th>
                    <th scope="col" className="py-2.5 pr-4 text-right">{t('purchase.pay.amount')}</th>
                    <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.by')}</th>
                    <th scope="col" className="py-2.5"><span className="sr-only">{t('purchase.detail.actionsCol')}</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {payments.map((payment) => (
                    <tr key={payment.id} className={payment.voided ? 'text-slate-400' : undefined}>
                      <td className="py-3 pr-4 whitespace-nowrap">{formatDate(payment.paid_on)}</td>
                      <td className="py-3 pr-4">{t(`purchase.methods.${payment.method}`)}{payment.note && <span className="block text-xs text-slate-500">{payment.note}</span>}</td>
                      <td className={`py-3 pr-4 text-right whitespace-nowrap font-medium ${payment.voided ? 'line-through' : ''}`}>{money(payment.amount)}</td>
                      <td className="py-3 pr-4">
                        {payment.created_by?.name ?? t('inventory.documents.system')}
                        <span className="block text-xs text-slate-500">{formatDateTime(payment.created_at)}</span>
                      </td>
                      <td className="py-3 text-right">
                        {payment.voided ? (
                          <span className="inline-flex flex-col items-end gap-0.5 text-xs">
                            <Badge tone="neutral">{t('purchase.detail.voided')}</Badge>
                            {payment.voided.reason && <span className="max-w-48 text-right">{payment.voided.reason}</span>}
                          </span>
                        ) : (
                          canVoid && <Button variant="ghost" size="sm" onClick={() => setVoiding(payment)}>{t('purchase.detail.void')}</Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            )}
          </FormSection>

          <ModalActions>
            <Button variant={canPay ? 'ghost' : 'primary'} onClick={onClose}>{t('common.close')}</Button>
            {canPay && (
              <ButtonLink {...link('purchase-pay', data.id)}>
                <Wallet className="h-4 w-4" aria-hidden="true" />
                {t('purchase.detail.pay')}
              </ButtonLink>
            )}
          </ModalActions>
        </div>
      )}

      {voiding && data && <VoidDialog purchaseId={data.id} payment={voiding} onClose={() => setVoiding(null)} />}
    </Modal>
  )
}

function Stat({ label, value, hint, strong = false }: { label: string; value: string; hint?: string; strong?: boolean }) {
  return (
    <div className="rounded-xl bg-slate-50 px-4 py-3">
      <p className="text-xs text-slate-500">{label}</p>
      <p className={`text-base font-semibold ${strong ? 'text-red-700' : 'text-slate-900'}`}>{value}</p>
      {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
    </div>
  )
}

/** Hủy một lần trả nhầm: bắt buộc nêu lý do; lần trả không bị xóa mà đánh dấu hủy, số còn nợ tăng lại. */
function VoidDialog({ purchaseId, payment, onClose }: { purchaseId: string; payment: PurchasePayment; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const voidPayment = useVoidPayment(purchaseId)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  async function confirm() {
    if (reason.trim().length < 2) {
      setError(t('purchase.void.reasonRequired'))

      return
    }

    setError(null)

    try {
      await voidPayment.mutateAsync({ payment: payment.id, reason: reason.trim() })
      toast.success(t('purchase.void.done'))
      onClose()
    } catch (failure) {
      setError(describeError(failure, t))
    }
  }

  return (
    <ConfirmDialog title={t('purchase.void.title')} confirmLabel={t('purchase.void.confirm')} danger loading={voidPayment.isPending} error={error} onConfirm={() => void confirm()} onClose={onClose}>
      <p>{t('purchase.void.text', { amount: money(payment.amount) })}</p>
      <label htmlFor="void-reason" className="mt-3 mb-1.5 block text-sm font-medium text-slate-800">{t('purchase.void.reason')}</label>
      <textarea id="void-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} className={`${fieldBase} ${fieldTone(false)}`} />
    </ConfirmDialog>
  )
}
