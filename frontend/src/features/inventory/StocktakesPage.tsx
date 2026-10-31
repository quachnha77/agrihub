import { ClipboardCheck, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { EmptyState, FilterBar, FilterSelect, PageHeader } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Button, ButtonLink, Card, Pagination } from '../../components/ui.tsx'
import { useDialog } from '../../lib/dialog.ts'
import { countActiveFilters } from '../../lib/filters.ts'
import { formatDateTime } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { STOCKTAKE_STATUSES, useAllWarehouses, useStocktakes, type StocktakeFilters } from './api.ts'
import { StocktakeBadge } from './parts.tsx'
import { StockTabs } from './StockTabs.tsx'

const EMPTY_FILTERS: StocktakeFilters = { status: '', warehouse: '', page: 1 }

/** Các đợt kiểm kê (mới nhất trước). Bấm vào một đợt để đếm tiếp (đang đếm) hoặc xem lại kết quả; bắt đầu đợt mới bằng nút phía trên. */
export default function StocktakesPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { link } = useDialog()
  const [filters, setFilters] = useState<StocktakeFilters>(EMPTY_FILTERS)
  const stocktakes = useStocktakes(filters)
  const warehouses = useAllWarehouses()
  const rows = stocktakes.data?.data ?? []
  // Ô lọc và bảng chờ dữ liệu tham chiếu (kho) rồi hiện cùng lúc, để ô lọc thêm vào muộn không làm trang dịch.
  const loading = stocktakes.isPending || warehouses.isLoading
  const set = <K extends keyof StocktakeFilters>(key: K, value: StocktakeFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))
  const activeFilters = countActiveFilters(filters)
  const canStart = can('inventory.adjustment.create')

  useClampPage(stocktakes.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('inventory.stocktake.title')}
        description={t('inventory.stocktake.intro')}
        tabs={<StockTabs />}
        actions={
          canStart && (
            <ButtonLink {...link('stocktake-new')}>
              <Plus className="h-4 w-4" aria-hidden="true" />
              {t('inventory.stocktake.start')}
            </ButtonLink>
          )
        }
      />

      <Card>
        <FilterBar loading={loading} selects={2} activeCount={activeFilters} onClear={() => setFilters(EMPTY_FILTERS)}>
          <FilterSelect label={t('inventory.filters.status')} value={filters.status} onChange={(event) => set('status', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            {STOCKTAKE_STATUSES.map((status) => <option key={status} value={status}>{t(`inventory.stocktake.status.${status}`)}</option>)}
          </FilterSelect>
          {(warehouses.data?.length ?? 0) > 1 && (
            <FilterSelect label={t('inventory.filters.warehouse')} value={filters.warehouse} onChange={(event) => set('warehouse', event.target.value)}>
              <option value="">{t('inventory.filters.allWarehouses')}</option>
              {(warehouses.data ?? []).map((w) => <option key={w.id} value={w.id}>{w.branch.code} — {w.name}</option>)}
            </FilterSelect>
          )}
        </FilterBar>

        {loading ? (
          <TableSkeleton columns={5} minWidth="44rem" />
        ) : stocktakes.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={ClipboardCheck}
            title={t('inventory.stocktake.empty')}
            description={activeFilters > 0 ? t('common.tryOtherFilters') : canStart ? t('inventory.stocktake.emptyHint') : undefined}
            action={
              activeFilters > 0 ? (
                <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>{t('common.clearFilters')}</Button>
              ) : canStart ? (
                <ButtonLink {...link('stocktake-new')}>{t('inventory.stocktake.start')}</ButtonLink>
              ) : undefined
            }
          />
        ) : (
          <Refreshable refreshing={stocktakes.isPlaceholderData}>
            <DataTable minWidth="44rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.stocktake.columns.code')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.stocktake.columns.warehouse')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.stocktake.columns.progress')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.stocktake.columns.time')}</th>
                  <th scope="col" className="py-2.5">{t('inventory.stocktake.columns.by')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((row) => {
                  const summary = row.summary

                  return (
                    <tr key={row.id}>
                      <td className="py-3 pr-4 whitespace-nowrap">
                        <Link {...link('stocktake', row.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{row.code}</Link>{' '}
                        <StocktakeBadge status={row.status} />
                      </td>
                      <td className="py-3 pr-4">{row.warehouse.name}</td>
                      <td className="py-3 pr-4 whitespace-nowrap">
                        {summary ? t('inventory.stocktake.progress', { counted: summary.counted, lines: summary.lines }) : '—'}
                        {summary && summary.differing > 0 && <span className="ml-1.5 text-amber-700">· {t('inventory.stocktake.differing', { count: summary.differing })}</span>}
                      </td>
                      <td className="py-3 pr-4 whitespace-nowrap">{formatDateTime(row.started_at)}</td>
                      <td className="py-3">{row.started_by?.name ?? t('inventory.documents.system')}</td>
                    </tr>
                  )
                })}
              </tbody>
            </DataTable>
            <Pagination page={stocktakes.data.meta.current_page} lastPage={stocktakes.data.meta.last_page} total={stocktakes.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>
    </div>
  )
}
