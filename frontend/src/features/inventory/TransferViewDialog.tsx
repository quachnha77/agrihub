import { ArrowRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { FormSection } from '../../components/dialog-parts.tsx'
import { FieldsSkeleton, SkeletonRegion, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Button, Modal, ModalActions } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { useDialog, type DialogProps } from '../../lib/dialog.ts'
import { formatDateTime, formatQuantity, formatVnd } from '../../lib/format.ts'
import { useTransfer } from './api.ts'
import { ExpiryBadge } from './parts.tsx'

const money = (value: string | undefined) => (value === undefined ? '—' : formatVnd(Number(value)))

/**
 * Popup chi tiết một phiếu chuyển kho (`?dialog=transfer:<id>`): kho gửi → kho nhận, người làm, từng dòng hàng (số lượng theo đơn vị đã nhập và đơn vị cơ sở, lô,
 * giá vốn nếu được xem) và liên kết tới hai phiếu kho đi kèm (phiếu xuất ở kho gửi, phiếu nhập ở kho nhận).
 */
export default function TransferViewDialog({ arg: id, onClose }: DialogProps) {
  const { t } = useTranslation()
  const { link } = useDialog()
  const transfer = useTransfer(id)
  const data = transfer.data
  const lines = data?.lines ?? []
  const costs = data?.value !== undefined
  const notFound = transfer.error instanceof ApiError && (transfer.error.status === 404 || transfer.error.status === 403)

  return (
    <Modal
      size="xl"
      fixedHeight="sheet"
      onClose={onClose}
      title={data ? t('inventory.transfer.detail.title', { code: data.code }) : t('inventory.transfer.detail.loading')}
      description={t('inventory.transfer.detail.immutable')}
    >
      {transfer.isError ? (
        <Alert tone="danger">{notFound ? t('inventory.transfer.detail.notFound') : t('common.loadError')}</Alert>
      ) : !data ? (
        <SkeletonRegion className="space-y-5">
          <FieldsSkeleton fields={4} />
          <TableSkeleton columns={5} rows={3} minWidth="0" />
        </SkeletonRegion>
      ) : (
        <div className="flex min-h-full flex-col gap-5">
          <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div className="sm:col-span-2">
              <dt className="text-slate-500">{t('inventory.transfer.detail.route')}</dt>
              <dd className="flex flex-wrap items-center gap-x-2 font-medium">
                <span>{data.from.warehouse.name} <span className="font-normal text-slate-500">({data.from.branch.code})</span></span>
                <ArrowRight className="h-4 w-4 text-slate-400" aria-hidden="true" />
                <span>{data.to.warehouse.name} <span className="font-normal text-slate-500">({data.to.branch.code})</span></span>
              </dd>
            </div>
            <div><dt className="text-slate-500">{t('inventory.columns.time')}</dt><dd className="font-medium">{formatDateTime(data.performed_at)}</dd></div>
            <div><dt className="text-slate-500">{t('inventory.columns.by')}</dt><dd className="font-medium">{data.performed_by?.name ?? t('inventory.documents.system')}</dd></div>
            {costs && <div><dt className="text-slate-500">{t('inventory.transfer.detail.value')}</dt><dd className="font-medium">{money(data.value)}</dd></div>}
            {data.documents && (
              <div className="sm:col-span-2">
                <dt className="text-slate-500">{t('inventory.transfer.detail.documents')}</dt>
                <dd className="flex flex-wrap gap-x-4 gap-y-1 font-medium">
                  <Link {...link('stock-doc', data.documents.out.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{data.documents.out.code}</Link>
                  {data.documents.in && <Link {...link('stock-doc', data.documents.in.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{data.documents.in.code}</Link>}
                </dd>
              </div>
            )}
            {data.note && <div className="sm:col-span-2 lg:col-span-4"><dt className="text-slate-500">{t('inventory.form.note')}</dt><dd>{data.note}</dd></div>}
          </dl>

          <FormSection title={t('inventory.transfer.detail.lines')}>
            <DataTable minWidth="40rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.columns.product')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.batch')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.entered')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.quantity')}</th>
                  {costs && <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.unitCost')}</th>}
                  {costs && <th scope="col" className="py-2.5 text-right">{t('inventory.columns.value')}</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {lines.map((line, index) => (
                  <tr key={`${line.product.id}-${line.batch?.id ?? index}`}>
                    <td className="py-3 pr-4"><span className="font-mono text-xs">{line.product.sku}</span> <span className="font-medium text-slate-900">{line.product.name}</span></td>
                    <td className="py-3 pr-4">{line.batch ? <>{line.batch.batch_no}<span className="block text-xs"><ExpiryBadge date={line.batch.expires_at} /></span></> : '—'}</td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap">{formatQuantity(line.entered.quantity)} {line.entered.unit}</td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap font-medium">{formatQuantity(line.quantity)} {line.product.base_unit}</td>
                    {costs && <td className="py-3 pr-4 text-right whitespace-nowrap">{money(line.unit_cost)}</td>}
                    {costs && <td className="py-3 text-right whitespace-nowrap">{money(line.value)}</td>}
                  </tr>
                ))}
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
