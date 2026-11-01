import { ShoppingCart } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { EmptyState, FilterBar, FilterDate, FilterSelect, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Button, ButtonLink, Card, Pagination } from '../../components/ui.tsx'
import { useDialog } from '../../lib/dialog.ts'
import { countActiveFilters } from '../../lib/filters.ts'
import { formatIsoDate, formatVnd } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useAllWarehouses } from '../inventory/api.ts'
import { PAYMENT_STATUSES, useAllCustomers, useSales, type SaleFilters } from './api.ts'
import { PaymentBadge, SalesTabs } from './parts.tsx'

const EMPTY_FILTERS: SaleFilters = { q: '', customer: '', warehouse: '', payment_status: '', from: '', to: '', page: 1 }

const money = (value: string) => formatVnd(Number(value))

/** Các phiếu bán hàng (mới nhất trước) kèm tổng phải thu, đã thu và khách còn nợ của MỌI phiếu khớp bộ lọc: lọc "Quá hạn" để biết ai cần đòi nợ. */
export default function SalesPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { link } = useDialog()
  const [filters, setFilters] = useState<SaleFilters>(EMPTY_FILTERS)
  const search = useDeferredValue(filters.q)
  const sales = useSales({ ...filters, q: search })
  const canListCustomers = can('crm.customer.view')
  const customers = useAllCustomers(canListCustomers)
  const warehouses = useAllWarehouses()
  const rows = sales.data?.data ?? []
  const summary = sales.data?.summary
  // Ô lọc và bảng chờ dữ liệu tham chiếu (khách, kho) rồi hiện cùng lúc, để ô lọc thêm vào muộn không làm trang dịch.
  const loading = sales.isPending || customers.isLoading || warehouses.isLoading
  const set = <K extends keyof SaleFilters>(key: K, value: SaleFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))
  const activeFilters = countActiveFilters(filters)
  const filtered = activeFilters > 0 || filters.q !== ''
  const canCreate = can('sales.order.create')

  useClampPage(sales.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('sales.title')}
        description={t('sales.intro')}
        tabs={<SalesTabs />}
        actions={
          canCreate && (
            <ButtonLink {...link('sale-new')}>
              <ShoppingCart className="h-4 w-4" aria-hidden="true" />
              {t('sales.start')}
            </ButtonLink>
          )
        }
      />

      <Card>
        <FilterBar
          loading={loading}
          selects={canListCustomers ? 5 : 4}
          activeCount={activeFilters}
          onClear={() => setFilters(EMPTY_FILTERS)}
          search={<SearchField label={t('sales.columns.code')} value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder="BH0001" />}
        >
          {canListCustomers && (
            <FilterSelect label={t('sales.filters.customer')} value={filters.customer} onChange={(event) => set('customer', event.target.value)}>
              <option value="">{t('sales.filters.allCustomers')}</option>
              {(customers.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </FilterSelect>
          )}
          <FilterSelect label={t('sales.filters.payment')} value={filters.payment_status} onChange={(event) => set('payment_status', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            <option value="open">{t('sales.status.open')}</option>
            <option value="overdue">{t('sales.status.overdueOption')}</option>
            {PAYMENT_STATUSES.map((status) => <option key={status} value={status}>{t(`sales.status.${status}`)}</option>)}
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
          <TableSkeleton columns={6} minWidth="52rem" />
        ) : sales.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={ShoppingCart}
            title={t('sales.empty')}
            description={filtered ? t('common.tryOtherFilters') : canCreate ? t('sales.emptyHint') : undefined}
            action={
              filtered ? (
                <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>{t('common.clearFilters')}</Button>
              ) : canCreate ? (
                <ButtonLink {...link('sale-new')}>{t('sales.start')}</ButtonLink>
              ) : undefined
            }
          />
        ) : (
          <Refreshable refreshing={sales.isPlaceholderData}>
            {summary && (
              <dl className="mb-4 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-3" aria-label={t('sales.summary.label')}>
                <div><dt className="text-slate-500">{t('sales.summary.total')}</dt><dd className="font-semibold text-slate-900">{money(summary.total)}</dd></div>
                <div><dt className="text-slate-500">{t('sales.summary.paid')}</dt><dd className="font-semibold text-slate-900">{money(summary.paid_amount)}</dd></div>
                <div><dt className="text-slate-500">{t('sales.summary.balance')}</dt><dd className={`font-semibold ${Number(summary.balance) > 0 ? 'text-red-700' : 'text-slate-900'}`}>{money(summary.balance)}</dd></div>
              </dl>
            )}
            <DataTable minWidth="52rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('sales.columns.code')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('sales.columns.customer')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('sales.columns.warehouse')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('sales.columns.total')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('sales.columns.balance')}</th>
                  <th scope="col" className="py-2.5">{t('sales.columns.status')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="py-3 pr-4 whitespace-nowrap">
                      <Link {...link('sale', row.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{row.code}</Link>
                      <span className="block text-xs text-slate-500">{formatIsoDate(row.sold_on)}</span>
                    </td>
                    <td className="py-3 pr-4">{row.customer?.name ?? <span className="text-slate-500">{t('sales.walkIn')}</span>}</td>
                    <td className="py-3 pr-4">{row.warehouse.name}</td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums">{money(row.net_total)}{Number(row.returned_amount) > 0 && <span className="block text-xs text-slate-500">{t('sales.return.returnedHint', { amount: money(row.returned_amount) })}</span>}</td>
                    <td className={`py-3 pr-4 text-right whitespace-nowrap tabular-nums ${Number(row.balance) > 0 ? 'font-medium text-red-700' : 'text-slate-500'}`}>{money(row.balance)}</td>
                    <td className="py-3 whitespace-nowrap"><PaymentBadge status={row.payment_status} overdue={row.is_overdue} /></td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
            <Pagination page={sales.data.meta.current_page} lastPage={sales.data.meta.last_page} total={sales.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>
    </div>
  )
}
