import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { FormSection } from '../../components/dialog-parts.tsx'
import { FieldsSkeleton, SkeletonRegion, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Button, Modal, ModalActions } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { useDialog, type DialogProps } from '../../lib/dialog.ts'
import { formatDateTime, formatQuantity, formatVnd } from '../../lib/format.ts'
import { useStockDocument } from './api.ts'
import { ExpiryBadge, TypeBadge } from './parts.tsx'

const money = (value: string | undefined) => (value === undefined ? '—' : formatVnd(Number(value)))

/** Popup chi tiết một phiếu kho (`?dialog=stock-doc:<id>`): người ghi, kho, lý do và từng dòng (số lượng theo đơn vị đã nhập và theo đơn vị cơ sở, lô, giá vốn nếu được xem). */
export default function StockDocumentViewDialog({ arg: id, onClose }: DialogProps) {
  const { t } = useTranslation()
  const { link } = useDialog()
  const document = useStockDocument(id)
  const data = document.data
  const lines = data?.lines ?? []
  const costs = data?.value !== undefined
  const notFound = document.error instanceof ApiError && (document.error.status === 404 || document.error.status === 403)

  return (
    <Modal
      size="xl"
      fixedHeight="sheet"
      onClose={onClose}
      title={
        data ? (
          <span className="flex flex-wrap items-center gap-2">
            {t('inventory.documents.detailTitle', { code: data.code })}
            <TypeBadge type={data.type} />
          </span>
        ) : (
          t('inventory.documents.detailLoading')
        )
      }
      description={t('inventory.documents.immutable')}
    >
      {document.isError ? (
        <Alert tone="danger">{notFound ? t('inventory.documents.notFound') : t('common.loadError')}</Alert>
      ) : !data ? (
        <SkeletonRegion className="space-y-5">
          <FieldsSkeleton fields={4} />
          <TableSkeleton columns={5} rows={3} minWidth="0" />
        </SkeletonRegion>
      ) : (
        <div className="flex min-h-full flex-col gap-5">
          <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt className="text-slate-500">{t('inventory.columns.time')}</dt><dd className="font-medium">{formatDateTime(data.performed_at)}</dd></div>
            <div><dt className="text-slate-500">{t('inventory.columns.warehouse')}</dt><dd className="font-medium">{data.warehouse.name} <span className="text-slate-500">({data.branch.code})</span></dd></div>
            <div><dt className="text-slate-500">{t('inventory.columns.by')}</dt><dd className="font-medium">{data.performed_by?.name ?? t('inventory.documents.system')}</dd></div>
            {data.transfer && (
              <div>
                <dt className="text-slate-500">{t('inventory.documents.transfer')}</dt>
                <dd><Link {...link('transfer', data.transfer.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{data.transfer.code}</Link></dd>
              </div>
            )}
            {data.purchase && (
              <div>
                <dt className="text-slate-500">{t('inventory.documents.purchase')}</dt>
                <dd><Link {...link('purchase', data.purchase.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{data.purchase.code}</Link></dd>
              </div>
            )}
            {data.reason && <div><dt className="text-slate-500">{t('inventory.form.reason')}</dt><dd className="font-medium">{t(`inventory.reasons.${data.reason}`)}</dd></div>}
            {costs && <div><dt className="text-slate-500">{t('inventory.documents.value')}</dt><dd className="font-medium">{money(data.value)}</dd></div>}
            {data.note && <div className="sm:col-span-2 lg:col-span-4"><dt className="text-slate-500">{t('inventory.form.note')}</dt><dd>{data.note}</dd></div>}
          </dl>

          <FormSection title={t('inventory.form.lines')}>
            <DataTable minWidth="48rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.columns.product')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.batch')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.entered')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.change')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.balanceAfter')}</th>
                  {costs && <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.unitCost')}</th>}
                  {costs && <th scope="col" className="py-2.5 text-right">{t('inventory.columns.value')}</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {lines.map((line) => {
                  const unit = line.product.base_unit ?? ''
                  const negative = Number(line.quantity_delta) < 0

                  return (
                    <tr key={line.id}>
                      <td className="py-3 pr-4"><span className="font-mono text-xs">{line.product.sku}</span> <span className="font-medium text-slate-900">{line.product.name}</span></td>
                      <td className="py-3 pr-4">{line.batch ? <>{line.batch.batch_no}<span className="block text-xs"><ExpiryBadge date={line.batch.expires_at} /></span></> : '—'}</td>
                      <td className="py-3 pr-4 text-right whitespace-nowrap">{formatQuantity(line.entered.quantity)} {line.entered.unit}</td>
                      <td className={`py-3 pr-4 text-right whitespace-nowrap font-medium ${negative ? 'text-red-700' : 'text-brand-700'}`}>{negative ? '' : '+'}{formatQuantity(line.quantity_delta)} {unit}</td>
                      <td className="py-3 pr-4 text-right whitespace-nowrap">{formatQuantity(line.balance_after)} {unit}</td>
                      {costs && <td className="py-3 pr-4 text-right whitespace-nowrap">{money(line.unit_cost)}</td>}
                      {costs && <td className="py-3 text-right whitespace-nowrap">{money(line.value)}</td>}
                    </tr>
                  )
                })}
              </tbody>
            </DataTable>
          </FormSection>

          <ModalActions>
            <Button onClick={onClose}>{t('common.close')}</Button>
          </ModalActions>
        </div>
      )}
    </Modal>
  )
}
