import { ClipboardList } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { EmptyState, FilterBar, FilterDate, FilterSelect, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Button, Card, Pagination } from '../../components/ui.tsx'
import { useDialog } from '../../lib/dialog.ts'
import { countActiveFilters } from '../../lib/filters.ts'
import { formatDateTime } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { DOCUMENT_TYPES, useAllWarehouses, useStockDocuments, type DocumentFilters } from './api.ts'
import { TypeBadge } from './parts.tsx'
import { StockTabs } from './StockTabs.tsx'

const EMPTY_FILTERS: DocumentFilters = { q: '', type: '', warehouse: '', from: '', to: '', page: 1 }

/** Các phiếu kho đã ghi (mới nhất trước). Phiếu không sửa/xóa được: sai sót sửa bằng phiếu điều chỉnh. */
export default function StockDocumentsPage() {
  const { t } = useTranslation()
  const { link } = useDialog()
  const [filters, setFilters] = useState<DocumentFilters>(EMPTY_FILTERS)
  const search = useDeferredValue(filters.q)
  const documents = useStockDocuments({ ...filters, q: search })
  const warehouses = useAllWarehouses()
  const rows = documents.data?.data ?? []
  // Ô lọc và bảng chờ dữ liệu tham chiếu (chi nhánh/kho) rồi hiện cùng lúc, để ô lọc/cột "Chi nhánh" thêm vào muộn không làm trang dịch.
  const loading = documents.isPending || warehouses.isLoading
  const set = <K extends keyof DocumentFilters>(key: K, value: DocumentFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))
  const activeFilters = countActiveFilters(filters)
  const filtered = activeFilters > 0 || filters.q !== ''

  useClampPage(documents.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-5">
      <PageHeader title={t('inventory.documents.title')} description={t('inventory.documents.intro')} tabs={<StockTabs />} />

      <Card>
        <FilterBar
          loading={loading}
          selects={4}
          activeCount={activeFilters}
          onClear={() => setFilters(EMPTY_FILTERS)}
          search={<SearchField label={t('inventory.documents.code')} value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder="PN0001" />}
        >
          <FilterSelect label={t('inventory.documents.type')} value={filters.type} onChange={(event) => set('type', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            {DOCUMENT_TYPES.map((type) => <option key={type} value={type}>{t(`inventory.types.${type}`)}</option>)}
          </FilterSelect>
          {(warehouses.data?.length ?? 0) > 1 && (
            <FilterSelect label={t('inventory.filters.warehouse')} value={filters.warehouse} onChange={(event) => set('warehouse', event.target.value)}>
              <option value="">{t('inventory.filters.allWarehouses')}</option>
              {(warehouses.data ?? []).map((w) => <option key={w.id} value={w.id}>{w.branch.code} — {w.name}</option>)}
            </FilterSelect>
          )}
          <FilterDate label={t('inventory.documents.from')} value={filters.from} onChange={(event) => set('from', event.target.value)} />
          <FilterDate label={t('inventory.documents.to')} value={filters.to} onChange={(event) => set('to', event.target.value)} />
        </FilterBar>

        {loading ? (
          <TableSkeleton columns={5} minWidth="44rem" />
        ) : documents.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={ClipboardList}
            title={t('inventory.documents.empty')}
            description={filtered ? t('common.tryOtherFilters') : undefined}
            action={filtered ? <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>{t('common.clearFilters')}</Button> : undefined}
          />
        ) : (
          <Refreshable refreshing={documents.isPlaceholderData}>
            <DataTable minWidth="44rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.columns.document')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.time')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.warehouse')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.lines')}</th>
                  <th scope="col" className="py-2.5">{t('inventory.columns.by')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((document) => (
                  <tr key={document.id}>
                    <td className="py-3 pr-4 whitespace-nowrap">
                      <Link {...link('stock-doc', document.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{document.code}</Link>{' '}
                      <TypeBadge type={document.type} />
                    </td>
                    <td className="py-3 pr-4 whitespace-nowrap">{formatDateTime(document.performed_at)}</td>
                    <td className="py-3 pr-4">{document.warehouse.name}</td>
                    <td className="py-3 pr-4 text-right tabular-nums">{document.line_count ?? '—'}</td>
                    <td className="py-3">{document.performed_by?.name ?? t('inventory.documents.system')}</td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
            <Pagination page={documents.data.meta.current_page} lastPage={documents.data.meta.last_page} total={documents.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>
    </div>
  )
}
