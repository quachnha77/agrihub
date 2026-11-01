import { Undo2, Wallet } from 'lucide-react'
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
import { useSale, useVoidSalePayment, type SalePayment } from './api.ts'
import { returnable } from './forms.ts'
import { PaymentBadge } from './parts.tsx'

const money = (value: string | undefined) => formatVnd(Number(value ?? 0))

/**
 * Popup chi tiết một phiếu bán hàng (`?dialog=sale:<id>`): khách, kho, tiền hàng / bớt / phải trả / đã thu / còn nợ (kèm hạn trả), từng dòng hàng (kèm lô đã tự chọn),
 * các lần thu tiền (hủy được lần thu nhầm) và liên kết tới phiếu xuất kho. Từ đây mở popup thu tiền (`sale-pay`).
 */
export default function SaleViewDialog({ arg: id, onClose }: DialogProps) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { link } = useDialog()
  const sale = useSale(id)
  const data = sale.data
  const lines = data?.lines ?? []
  const payments = data?.payments ?? []
  const notFound = sale.error instanceof ApiError && (sale.error.status === 404 || sale.error.status === 403)
  const canCollect = can('sales.order.update') && data !== undefined && Number(data.balance) > 0
  const canVoid = can('sales.order.cancel')
  const returns = data?.returns ?? []
  const canReturn = canVoid && lines.some((line) => returnable(line) > 0)
  const showCost = lines.some((line) => line.cost_amount !== undefined)
  const [voiding, setVoiding] = useState<SalePayment | null>(null)

  return (
    <Modal
      size="xl"
      fixedHeight="sheet"
      onClose={onClose}
      title={t('sales.detail.title')}
      description={<DialogSubject name={data ? `${data.code} · ${data.customer?.name ?? t('sales.walkIn')}` : undefined} badge={data && <PaymentBadge status={data.payment_status} overdue={data.is_overdue} />} />}
    >
      {sale.isError ? (
        <Alert tone="danger">{notFound ? t('sales.detail.notFound') : t('common.loadError')}</Alert>
      ) : !data ? (
        <SkeletonRegion className="space-y-5">
          <FieldsSkeleton fields={4} />
          <TableSkeleton columns={5} rows={3} minWidth="0" />
        </SkeletonRegion>
      ) : (
        <div className="flex min-h-full flex-col gap-5">
          <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt className="text-slate-500">{t('sales.detail.soldOn')}</dt><dd className="font-medium">{formatIsoDate(data.sold_on)}</dd></div>
            <div><dt className="text-slate-500">{t('sales.form.warehouse')}</dt><dd className="font-medium">{data.warehouse.name} <span className="text-slate-500">({data.branch.code})</span></dd></div>
            <div><dt className="text-slate-500">{t('sales.detail.by')}</dt><dd className="font-medium">{data.created_by?.name ?? t('inventory.documents.system')}</dd></div>
            <div>
              <dt className="text-slate-500">{t('sales.detail.stockDocument')}</dt>
              <dd><Link {...link('stock-doc', data.stock_document.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{data.stock_document.code}</Link></dd>
            </div>
            {data.due_on && Number(data.balance) > 0 && (
              <div><dt className="text-slate-500">{t('sales.detail.dueOn')}</dt><dd className={`font-medium ${data.is_overdue ? 'text-red-700' : ''}`}>{formatIsoDate(data.due_on)}</dd></div>
            )}
            {data.note && <div className="sm:col-span-2 lg:col-span-3"><dt className="text-slate-500">{t('inventory.form.note')}</dt><dd>{data.note}</dd></div>}
          </dl>

          <div className="grid gap-3 sm:grid-cols-4">
            <Stat label={t('sales.detail.goodsTotal')} value={money(data.goods_total)} />
            <Stat label={t('sales.detail.discount')} value={money(data.discount)} />
            <Stat label={t('sales.detail.total')} value={money(data.net_total)} hint={Number(data.returned_amount) > 0 ? t('sales.return.returnedHint', { amount: money(data.returned_amount) }) : undefined} />
            <Stat label={t('sales.detail.balance')} value={money(data.balance)} strong={Number(data.balance) > 0} hint={t('sales.detail.paidHint', { amount: money(data.paid_amount) })} />
          </div>

          <FormSection title={t('inventory.form.lines')}>
            <DataTable minWidth="44rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.columns.product')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.batch')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('sales.detail.quantity')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('sales.detail.unitPrice')}</th>
                  <th scope="col" className={`py-2.5 text-right ${showCost ? 'pr-4' : ''}`}>{t('sales.detail.lineTotal')}</th>
                  {showCost && <th scope="col" className="py-2.5 text-right">{t('sales.detail.cost')}</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {lines.map((line, index) => (
                  <tr key={`${line.product.id}-${line.batch?.id ?? index}-${index}`}>
                    <td className="py-3 pr-4"><span className="font-mono text-xs">{line.product.sku}</span> <span className="font-medium text-slate-900">{line.product.name}</span></td>
                    <td className="py-3 pr-4">{line.batch ? <>{line.batch.batch_no}<span className="block text-xs"><ExpiryBadge date={line.batch.expires_at} /></span></> : '—'}</td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap">{formatQuantity(line.quantity)} {line.unit}{Number(line.returned_quantity) > 0 && <span className="block text-xs text-slate-500">{t('sales.return.returnedQty', { quantity: formatQuantity(line.returned_quantity) })}</span>}</td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap">{money(line.unit_price)}</td>
                    <td className={`py-3 text-right whitespace-nowrap font-medium ${showCost ? 'pr-4' : ''}`}>{money(line.line_total)}</td>
                    {showCost && <td className="py-3 text-right whitespace-nowrap text-slate-600">{money(line.cost_amount)}</td>}
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </FormSection>

          <FormSection title={t('sales.detail.payments')}>
            {payments.length === 0 ? (
              <p className="text-sm text-slate-500">{t('sales.detail.noPayments')}</p>
            ) : (
              <DataTable minWidth="36rem">
                <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                  <tr>
                    <th scope="col" data-role="title" className="py-2.5 pr-4">{t('sales.pay.paidOn')}</th>
                    <th scope="col" className="py-2.5 pr-4">{t('sales.form.method')}</th>
                    <th scope="col" className="py-2.5 pr-4 text-right">{t('sales.pay.amount')}</th>
                    <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.by')}</th>
                    <th scope="col" className="py-2.5"><span className="sr-only">{t('sales.detail.actionsCol')}</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {payments.map((payment) => (
                    <tr key={payment.id} className={payment.voided ? 'text-slate-400' : undefined}>
                      <td className="py-3 pr-4 whitespace-nowrap">{formatDate(payment.paid_on)}</td>
                      <td className="py-3 pr-4">{t(`sales.methods.${payment.method}`)}{payment.note && <span className="block text-xs text-slate-500">{payment.note}</span>}</td>
                      <td className={`py-3 pr-4 text-right whitespace-nowrap font-medium ${payment.voided ? 'line-through' : ''}`}>{money(payment.amount)}</td>
                      <td className="py-3 pr-4">
                        {payment.created_by?.name ?? t('inventory.documents.system')}
                        <span className="block text-xs text-slate-500">{formatDateTime(payment.created_at)}</span>
                      </td>
                      <td className="py-3 text-right">
                        {payment.voided ? (
                          <span className="inline-flex flex-col items-end gap-0.5 text-xs">
                            <Badge tone="neutral">{t('sales.detail.voided')}</Badge>
                            {payment.voided.reason && <span className="max-w-48 text-right">{payment.voided.reason}</span>}
                          </span>
                        ) : (
                          canVoid && <Button variant="ghost" size="sm" onClick={() => setVoiding(payment)}>{t('sales.detail.void')}</Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            )}
          </FormSection>

          {returns.length > 0 && (
            <FormSection title={t('sales.return.history')}>
              <DataTable minWidth="40rem">
                <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                  <tr>
                    <th scope="col" data-role="title" className="py-2.5 pr-4">{t('sales.return.columns.code')}</th>
                    <th scope="col" className="py-2.5 pr-4">{t('sales.return.columns.goods')}</th>
                    <th scope="col" className="py-2.5 pr-4 text-right">{t('sales.return.columns.total')}</th>
                    <th scope="col" className="py-2.5">{t('sales.return.columns.effect')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {returns.map((item) => (
                    <tr key={item.id}>
                      <td className="py-3 pr-4 whitespace-nowrap">
                        <Link {...link('stock-doc', item.stock_document.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{item.code}</Link>
                        <span className="block text-xs text-slate-500">{formatDate(item.returned_on)}</span>
                      </td>
                      <td className="py-3 pr-4">
                        {item.lines.map((line) => <span key={`${line.product.id}-${line.quantity}`} className="block">{line.product.name} <span className="text-slate-500">× {formatQuantity(line.quantity)} {line.unit}</span></span>)}
                        {item.note && <span className="block text-xs text-slate-500">{item.note}</span>}
                      </td>
                      <td className="py-3 pr-4 text-right whitespace-nowrap font-medium tabular-nums">{money(item.total)}</td>
                      <td className="py-3 text-slate-600">
                        {Number(item.debt_reduction) > 0 && <span className="block">{t('sales.return.debtOf', { amount: money(item.debt_reduction) })}</span>}
                        {Number(item.refund_amount) > 0 && <span className="block">{t('sales.return.refundOf', { amount: money(item.refund_amount) })}{item.refund_method && ` (${t(`sales.methods.${item.refund_method}`)})`}</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            </FormSection>
          )}

          <ModalActions>
            <Button variant={canCollect ? 'ghost' : 'primary'} onClick={onClose}>{t('common.close')}</Button>
            {canReturn && (
              <ButtonLink variant="secondary" {...link('sale-return', data.id)}>
                <Undo2 className="h-4 w-4" aria-hidden="true" />
                {t('sales.return.button')}
              </ButtonLink>
            )}
            {canCollect && (
              <ButtonLink {...link('sale-pay', data.id)}>
                <Wallet className="h-4 w-4" aria-hidden="true" />
                {t('sales.detail.collect')}
              </ButtonLink>
            )}
          </ModalActions>
        </div>
      )}

      {voiding && data && <VoidDialog saleId={data.id} payment={voiding} onClose={() => setVoiding(null)} />}
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

/** Hủy một lần thu nhầm: bắt buộc nêu lý do; lần thu không bị xóa mà đánh dấu hủy, số khách còn nợ tăng lại. */
function VoidDialog({ saleId, payment, onClose }: { saleId: string; payment: SalePayment; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const voidPayment = useVoidSalePayment(saleId)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  async function confirm() {
    if (reason.trim().length < 2) {
      setError(t('sales.void.reasonRequired'))

      return
    }

    setError(null)

    try {
      await voidPayment.mutateAsync({ payment: payment.id, reason: reason.trim() })
      toast.success(t('sales.void.done'))
      onClose()
    } catch (failure) {
      setError(describeError(failure, t))
    }
  }

  return (
    <ConfirmDialog title={t('sales.void.title')} confirmLabel={t('sales.void.confirm')} danger loading={voidPayment.isPending} error={error} onConfirm={() => void confirm()} onClose={onClose}>
      <p>{t('sales.void.text', { amount: money(payment.amount) })}</p>
      <label htmlFor="void-reason" className="mt-3 mb-1.5 block text-sm font-medium text-slate-800">{t('sales.void.reason')}</label>
      <textarea id="void-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} className={`${fieldBase} ${fieldTone(false)}`} />
    </ConfirmDialog>
  )
}
