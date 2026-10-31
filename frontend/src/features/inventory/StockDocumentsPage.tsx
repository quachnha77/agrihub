import { ArrowLeft } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { FilterBar, Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { Alert, Card, Pagination, SelectField } from '../../components/ui.tsx'
import { formatDateTime } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { DOCUMENT_TYPES, useAllWarehouses, useStockDocuments, type DocumentFilters } from './api.ts'
import { TypeBadge } from './parts.tsx'

const inputClass = 'block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200'

/** Các phiếu kho đã ghi (mới nhất trước). Phiếu không sửa/xóa được: sai sót sửa bằng phiếu điều chỉnh. */
export default function StockDocumentsPage() {
  const { t } = useTranslation()
  const [filters, setFilters] = useState<DocumentFilters>({ q: '', type: '', warehouse: '', from: '', to: '', page: 1 })
  const search = useDeferredValue(filters.q)
  const documents = useStockDocuments({ ...filters, q: search })
  const warehouses = useAllWarehouses()
  const rows = documents.data?.data ?? []
  // Ô lọc và bảng chờ dữ liệu tham chiếu (chi nhánh/kho) rồi hiện cùng lúc, để ô lọc/cột "Chi nhánh" thêm vào muộn không làm trang dịch.
  const loading = documents.isPending || warehouses.isLoading
  const set = <K extends keyof DocumentFilters>(key: K, value: DocumentFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))

  useClampPage(documents.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-4">
      <Link to="/stock" className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t('inventory.documents.back')}
      </Link>

      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('inventory.documents.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('inventory.documents.intro')}</p>
      </div>

      <Card>
        <FilterBar loading={loading} fields={['', '', '', '', '']} className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <label htmlFor="document-search" className="mb-1 block text-sm font-medium text-slate-800">{t('inventory.documents.code')}</label>
            <input id="document-search" type="search" value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder="PN0001" className={inputClass} />
          </div>
          <SelectField label={t('inventory.documents.type')} value={filters.type} onChange={(event) => set('type', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            {DOCUMENT_TYPES.map((type) => <option key={type} value={type}>{t(`inventory.types.${type}`)}</option>)}
          </SelectField>
          {(warehouses.data?.length ?? 0) > 1 && (
            <SelectField label={t('inventory.filters.warehouse')} value={filters.warehouse} onChange={(event) => set('warehouse', event.target.value)}>
              <option value="">{t('inventory.filters.allWarehouses')}</option>
              {(warehouses.data ?? []).map((w) => <option key={w.id} value={w.id}>{w.branch.code} — {w.name}</option>)}
            </SelectField>
          )}
          <div>
            <label htmlFor="document-from" className="mb-1 block text-sm font-medium text-slate-800">{t('inventory.documents.from')}</label>
            <input id="document-from" type="date" value={filters.from} onChange={(event) => set('from', event.target.value)} className={inputClass} />
          </div>
          <div>
            <label htmlFor="document-to" className="mb-1 block text-sm font-medium text-slate-800">{t('inventory.documents.to')}</label>
            <input id="document-to" type="date" value={filters.to} onChange={(event) => set('to', event.target.value)} className={inputClass} />
          </div>
        </FilterBar>

        {loading ? (
          <TableSkeleton columns={5} minWidth="44rem" />
        ) : documents.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('inventory.documents.empty')}</p>
        ) : (
          <Refreshable refreshing={documents.isPlaceholderData}>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[44rem] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="py-2 pr-3">{t('inventory.columns.document')}</th>
                    <th scope="col" className="py-2 pr-3">{t('inventory.columns.time')}</th>
                    <th scope="col" className="py-2 pr-3">{t('inventory.columns.warehouse')}</th>
                    <th scope="col" className="py-2 pr-3 text-right">{t('inventory.columns.lines')}</th>
                    <th scope="col" className="py-2">{t('inventory.columns.by')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.map((document) => (
                    <tr key={document.id}>
                      <td className="py-2 pr-3 whitespace-nowrap">
                        <Link to={`/stock/documents/${document.id}`} className="font-mono text-xs font-medium text-emerald-700 hover:underline">{document.code}</Link>{' '}
                        <TypeBadge type={document.type} />
                      </td>
                      <td className="py-2 pr-3 whitespace-nowrap">{formatDateTime(document.performed_at)}</td>
                      <td className="py-2 pr-3">{document.warehouse.name}</td>
                      <td className="py-2 pr-3 text-right">{document.line_count ?? '—'}</td>
                      <td className="py-2">{document.performed_by?.name ?? t('inventory.documents.system')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={documents.data.meta.current_page} lastPage={documents.data.meta.last_page} total={documents.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>
    </div>
  )
}
