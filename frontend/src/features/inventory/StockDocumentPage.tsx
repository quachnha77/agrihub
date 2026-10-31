import { ArrowLeft } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useParams } from 'react-router'
import { Alert, Card, Spinner } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { formatDateTime, formatQuantity, formatVnd } from '../../lib/format.ts'
import { useStockDocument } from './api.ts'
import { ExpiryBadge, TypeBadge } from './parts.tsx'

const money = (value: string | undefined) => (value === undefined ? '—' : formatVnd(Number(value)))

/** Chi tiết một phiếu kho: người ghi, kho, lý do và từng dòng (số lượng theo đơn vị đã nhập và theo đơn vị cơ sở, lô, giá vốn nếu được xem). */
export default function StockDocumentPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const location = useLocation()
  const created = (location.state as { created?: string } | null)?.created
  const document = useStockDocument(id)

  if (document.isPending) return <div className="flex justify-center py-10"><Spinner /></div>

  if (document.isError) {
    const notFound = document.error instanceof ApiError && (document.error.status === 404 || document.error.status === 403)

    return (
      <div className="space-y-3">
        <Alert tone="danger">{notFound ? t('inventory.documents.notFound') : t('common.loadError')}</Alert>
        <Link to="/stock/documents" className="text-sm font-medium text-emerald-700 hover:underline">{t('inventory.documents.back')}</Link>
      </div>
    )
  }

  const data = document.data
  const lines = data.lines ?? []
  const costs = data.value !== undefined

  return (
    <div className="space-y-4">
      <Link to="/stock/documents" className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t('inventory.documents.back')}
      </Link>

      {created && <Alert tone="success">{t('inventory.documents.created', { code: created })}</Alert>}

      <div>
        <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold text-slate-900">
          {t('inventory.documents.detailTitle', { code: data.code })}
          <TypeBadge type={data.type} />
        </h1>
        <p className="mt-1 text-sm text-slate-600">{t('inventory.documents.immutable')}</p>
      </div>

      <Card>
        <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div><dt className="text-slate-500">{t('inventory.columns.time')}</dt><dd className="font-medium">{formatDateTime(data.performed_at)}</dd></div>
          <div><dt className="text-slate-500">{t('inventory.columns.warehouse')}</dt><dd className="font-medium">{data.warehouse.name} <span className="text-slate-500">({data.branch.code})</span></dd></div>
          <div><dt className="text-slate-500">{t('inventory.columns.by')}</dt><dd className="font-medium">{data.performed_by?.name ?? t('inventory.documents.system')}</dd></div>
          {data.reason && <div><dt className="text-slate-500">{t('inventory.form.reason')}</dt><dd className="font-medium">{t(`inventory.reasons.${data.reason}`)}</dd></div>}
          {costs && <div><dt className="text-slate-500">{t('inventory.documents.value')}</dt><dd className="font-medium">{money(data.value)}</dd></div>}
          {data.note && <div className="sm:col-span-2 lg:col-span-4"><dt className="text-slate-500">{t('inventory.form.note')}</dt><dd>{data.note}</dd></div>}
        </dl>
      </Card>

      <Card title={t('inventory.form.lines')}>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[48rem] text-left text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th scope="col" className="py-2 pr-3">{t('inventory.columns.product')}</th>
                <th scope="col" className="py-2 pr-3">{t('inventory.columns.batch')}</th>
                <th scope="col" className="py-2 pr-3 text-right">{t('inventory.columns.entered')}</th>
                <th scope="col" className="py-2 pr-3 text-right">{t('inventory.columns.change')}</th>
                <th scope="col" className="py-2 pr-3 text-right">{t('inventory.columns.balanceAfter')}</th>
                {costs && <th scope="col" className="py-2 pr-3 text-right">{t('inventory.columns.unitCost')}</th>}
                {costs && <th scope="col" className="py-2 text-right">{t('inventory.columns.value')}</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {lines.map((line) => {
                const unit = line.product.base_unit ?? ''
                const negative = Number(line.quantity_delta) < 0

                return (
                  <tr key={line.id}>
                    <td className="py-2 pr-3"><span className="font-mono text-xs">{line.product.sku}</span> <span className="font-medium text-slate-900">{line.product.name}</span></td>
                    <td className="py-2 pr-3">{line.batch ? <>{line.batch.batch_no}<span className="block text-xs"><ExpiryBadge date={line.batch.expires_at} /></span></> : '—'}</td>
                    <td className="py-2 pr-3 text-right whitespace-nowrap">{formatQuantity(line.entered.quantity)} {line.entered.unit}</td>
                    <td className={`py-2 pr-3 text-right whitespace-nowrap font-medium ${negative ? 'text-red-700' : 'text-emerald-700'}`}>{negative ? '' : '+'}{formatQuantity(line.quantity_delta)} {unit}</td>
                    <td className="py-2 pr-3 text-right whitespace-nowrap">{formatQuantity(line.balance_after)} {unit}</td>
                    {costs && <td className="py-2 pr-3 text-right whitespace-nowrap">{money(line.unit_cost)}</td>}
                    {costs && <td className="py-2 text-right whitespace-nowrap">{money(line.value)}</td>}
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  )
}
