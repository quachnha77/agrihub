import { HandCoins } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, FilterBar, FilterSelect, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Badge, Button, ButtonLink, Card, Pagination } from '../../components/ui.tsx'
import { useDialog } from '../../lib/dialog.ts'
import { countActiveFilters } from '../../lib/filters.ts'
import { formatIsoDate, formatVnd } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useReceivables, type ReceivableFilters, type ReceivableSummary } from './api.ts'
import { SalesTabs } from './parts.tsx'

const EMPTY_FILTERS: ReceivableFilters = { q: '', overdue: '', page: 1 }

const money = (value: string) => formatVnd(Number(value))

/** Công nợ khách hàng: ai đang nợ bao nhiêu, quá hạn bao lâu (nợ nhiều nhất trước) và nút thu nợ. Tiền thu tự trừ vào phiếu đến hạn sớm nhất trước. */
export default function ReceivablesPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { link } = useDialog()
  const [filters, setFilters] = useState<ReceivableFilters>(EMPTY_FILTERS)
  const search = useDeferredValue(filters.q)
  const debts = useReceivables({ ...filters, q: search })
  const rows = debts.data?.data ?? []
  const summary = debts.data?.summary
  const set = <K extends keyof ReceivableFilters>(key: K, value: ReceivableFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))
  const activeFilters = countActiveFilters(filters)
  const filtered = activeFilters > 0 || filters.q !== ''
  const canCollect = can('sales.order.update')

  useClampPage(debts.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-5">
      <PageHeader title={t('sales.debts.title')} description={t('sales.debts.intro')} tabs={<SalesTabs />} />

      <Card>
        <FilterBar
          loading={debts.isPending}
          selects={1}
          activeCount={activeFilters}
          onClear={() => setFilters(EMPTY_FILTERS)}
          search={<SearchField label={t('sales.debts.searchLabel')} value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder={t('sales.debts.searchPlaceholder')} />}
        >
          <FilterSelect label={t('sales.debts.filters.status')} value={filters.overdue} onChange={(event) => set('overdue', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            <option value="1">{t('sales.debts.filters.overdue')}</option>
          </FilterSelect>
        </FilterBar>

        {debts.isPending ? (
          <TableSkeleton columns={6} minWidth="48rem" actions />
        ) : debts.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={HandCoins}
            title={t('sales.debts.empty')}
            description={filtered ? t('common.tryOtherFilters') : t('sales.debts.emptyHint')}
            action={filtered ? <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>{t('common.clearFilters')}</Button> : undefined}
          />
        ) : (
          <Refreshable refreshing={debts.isPlaceholderData}>
            {summary && <AgingSummary summary={summary} />}
            <DataTable minWidth="48rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('sales.debts.columns.customer')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('sales.debts.columns.orders')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('sales.debts.columns.owed')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('sales.debts.columns.overdue')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('sales.debts.columns.limit')}</th>
                  <th scope="col" data-role="actions" className="py-2.5"><span className="sr-only">{t('sales.detail.actionsCol')}</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <tr key={row.customer.id}>
                    <td className="py-3 pr-4">
                      <span className="font-medium text-slate-900">{row.customer.name}</span>
                      <span className="block text-xs text-slate-500"><span className="font-mono">{row.customer.code}</span>{row.customer.phone && ` · ${row.customer.phone}`}</span>
                    </td>
                    <td className="py-3 pr-4 text-right tabular-nums">{row.open_orders}</td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap font-semibold tabular-nums text-slate-900">{money(row.owed)}</td>
                    <td className="py-3 pr-4 whitespace-nowrap">
                      {row.is_overdue ? (
                        <>
                          <Badge tone="danger" dot>{t('sales.debts.overdueDays', { count: row.days_overdue })}</Badge>
                          <span className="block text-xs text-slate-500">{money(row.overdue_amount)}{row.oldest_overdue_on && ` · ${t('sales.debts.since', { date: formatIsoDate(row.oldest_overdue_on) })}`}</span>
                        </>
                      ) : (
                        <span className="text-slate-500">{t('sales.debts.onTime')}</span>
                      )}
                    </td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums text-slate-600">{Number(row.customer.credit_limit) > 0 ? money(row.customer.credit_limit) : t('sales.debts.noLimit')}</td>
                    <td className="py-3 text-right">
                      {canCollect && (
                        <ButtonLink variant="secondary" size="sm" {...link('debt-pay', row.customer.id)}>
                          <HandCoins className="h-4 w-4" aria-hidden="true" />
                          {t('sales.debts.collect')}
                        </ButtonLink>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
            <Pagination page={debts.data.meta.current_page} lastPage={debts.data.meta.last_page} total={debts.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>
    </div>
  )
}

/** Tổng nợ của mọi khách khớp bộ lọc, chia theo tuổi nợ: càng quá hạn lâu màu càng đậm. */
function AgingSummary({ summary }: { summary: ReceivableSummary }) {
  const { t } = useTranslation()
  const { aging } = summary
  const tiles: { key: string; label: string; value: string; tone: string; hint?: string }[] = [
    { key: 'owed', label: t('sales.debts.summary.owed'), value: summary.owed, tone: 'text-slate-900', hint: t('sales.debts.summary.debtors', { count: summary.debtors }) },
    { key: 'current', label: t('sales.debts.summary.current'), value: aging.current, tone: 'text-slate-900' },
    { key: 'd1', label: t('sales.debts.summary.d1_30'), value: aging.overdue_1_30, tone: Number(aging.overdue_1_30) > 0 ? 'text-amber-700' : 'text-slate-900' },
    { key: 'd31', label: t('sales.debts.summary.d31_60'), value: aging.overdue_31_60, tone: Number(aging.overdue_31_60) > 0 ? 'text-red-700' : 'text-slate-900' },
    { key: 'd61', label: t('sales.debts.summary.d61'), value: aging.overdue_over_60, tone: Number(aging.overdue_over_60) > 0 ? 'text-red-700' : 'text-slate-900' },
  ]

  return (
    <dl className="mb-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-3 lg:grid-cols-5" aria-label={t('sales.debts.summary.label')}>
      {tiles.map((tile) => (
        <div key={tile.key}>
          <dt className="text-slate-500">{tile.label}</dt>
          <dd className={`font-semibold ${tile.tone}`}>{money(tile.value)}</dd>
          {tile.hint && <dd className="text-xs text-slate-500">{tile.hint}</dd>}
        </div>
      ))}
    </dl>
  )
}
