import { ArrowRight, ArrowRightLeft, Plus } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { EmptyState, FilterBar, FilterDate, FilterSelect, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Button, ButtonLink, Card, Pagination } from '../../components/ui.tsx'
import { useDialog } from '../../lib/dialog.ts'
import { countActiveFilters } from '../../lib/filters.ts'
import { formatDateTime } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useAllWarehouses, useTransfers, type TransferFilters, type TransferSide } from './api.ts'
import { StockTabs } from './StockTabs.tsx'

const EMPTY_FILTERS: TransferFilters = { q: '', warehouse: '', from: '', to: '', page: 1 }

/** Tên kho kèm mã chi nhánh khi khác chi nhánh với phía còn lại (để thấy ngay đây là chuyển giữa chi nhánh). */
function Side({ side, other }: { side: TransferSide; other: TransferSide }) {
  return (
    <span>
      {side.warehouse.name}
      {side.branch.id !== other.branch.id && <span className="text-slate-500"> ({side.branch.code})</span>}
    </span>
  )
}

/** Các phiếu chuyển kho đã ghi (mới nhất trước). Phiếu không sửa/xóa được: chuyển nhầm thì chuyển ngược lại. */
export default function TransfersPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { link } = useDialog()
  const [filters, setFilters] = useState<TransferFilters>(EMPTY_FILTERS)
  const search = useDeferredValue(filters.q)
  const transfers = useTransfers({ ...filters, q: search })
  const warehouses = useAllWarehouses()
  const rows = transfers.data?.data ?? []
  // Ô lọc và bảng chờ dữ liệu tham chiếu (kho) rồi hiện cùng lúc, để ô lọc thêm vào muộn không làm trang dịch.
  const loading = transfers.isPending || warehouses.isLoading
  const set = <K extends keyof TransferFilters>(key: K, value: TransferFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))
  const activeFilters = countActiveFilters(filters)
  const filtered = activeFilters > 0 || filters.q !== ''
  const canCreate = can('inventory.movement.create')

  useClampPage(transfers.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('inventory.transfer.title')}
        description={t('inventory.transfer.intro')}
        tabs={<StockTabs />}
        actions={
          canCreate && (
            <ButtonLink {...link('transfer-new')}>
              <Plus className="h-4 w-4" aria-hidden="true" />
              {t('inventory.transfer.start')}
            </ButtonLink>
          )
        }
      />

      <Card>
        <FilterBar
          loading={loading}
          selects={3}
          activeCount={activeFilters}
          onClear={() => setFilters(EMPTY_FILTERS)}
          search={<SearchField label={t('inventory.documents.code')} value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder="CK0001" />}
        >
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
        ) : transfers.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={ArrowRightLeft}
            title={t('inventory.transfer.empty')}
            description={filtered ? t('common.tryOtherFilters') : canCreate ? t('inventory.transfer.emptyHint') : undefined}
            action={
              filtered ? (
                <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>{t('common.clearFilters')}</Button>
              ) : canCreate ? (
                <ButtonLink {...link('transfer-new')}>{t('inventory.transfer.start')}</ButtonLink>
              ) : undefined
            }
          />
        ) : (
          <Refreshable refreshing={transfers.isPlaceholderData}>
            <DataTable minWidth="44rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.transfer.columns.code')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.transfer.columns.route')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.lines')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.time')}</th>
                  <th scope="col" className="py-2.5">{t('inventory.columns.by')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="py-3 pr-4 whitespace-nowrap">
                      <Link {...link('transfer', row.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{row.code}</Link>
                    </td>
                    <td className="py-3 pr-4">
                      <span className="inline-flex flex-wrap items-center gap-x-2">
                        <Side side={row.from} other={row.to} />
                        <ArrowRight className="h-4 w-4 text-slate-400" aria-label={t('inventory.transfer.columns.to')} />
                        <Side side={row.to} other={row.from} />
                      </span>
                    </td>
                    <td className="py-3 pr-4 text-right tabular-nums">{row.line_count ?? '—'}</td>
                    <td className="py-3 pr-4 whitespace-nowrap">{formatDateTime(row.performed_at)}</td>
                    <td className="py-3">{row.performed_by?.name ?? t('inventory.documents.system')}</td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
            <Pagination page={transfers.data.meta.current_page} lastPage={transfers.data.meta.last_page} total={transfers.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>
    </div>
  )
}
