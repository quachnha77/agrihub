import { PackagePlus } from 'lucide-react'
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
import { PAYMENT_STATUSES, useAllSuppliers, usePurchases, type PurchaseFilters } from './api.ts'
import { PaymentBadge, PurchaseTabs } from './parts.tsx'

const EMPTY_FILTERS: PurchaseFilters = { q: '', supplier: '', warehouse: '', payment_status: '', from: '', to: '', page: 1 }

const money = (value: string) => formatVnd(Number(value))

/** Các phiếu nhập hàng (mới nhất trước) kèm tổng tiền hàng, đã trả và còn nợ của MỌI phiếu khớp bộ lọc: lọc theo nhà cung cấp để biết đang nợ họ bao nhiêu. */
export default function PurchasesPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { link } = useDialog()
  const [filters, setFilters] = useState<PurchaseFilters>(EMPTY_FILTERS)
  const search = useDeferredValue(filters.q)
  const purchases = usePurchases({ ...filters, q: search })
  const suppliers = useAllSuppliers()
  const warehouses = useAllWarehouses()
  const rows = purchases.data?.data ?? []
  const summary = purchases.data?.summary
  // Ô lọc và bảng chờ dữ liệu tham chiếu (nhà cung cấp, kho) rồi hiện cùng lúc, để ô lọc thêm vào muộn không làm trang dịch.
  const loading = purchases.isPending || suppliers.isLoading || warehouses.isLoading
  const set = <K extends keyof PurchaseFilters>(key: K, value: PurchaseFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))
  const activeFilters = countActiveFilters(filters)
  const filtered = activeFilters > 0 || filters.q !== ''
  const canCreate = can('purchase.order.create')

  useClampPage(purchases.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('purchase.title')}
        description={t('purchase.intro')}
        tabs={<PurchaseTabs />}
        actions={
          canCreate && (
            <ButtonLink {...link('purchase-new')}>
              <PackagePlus className="h-4 w-4" aria-hidden="true" />
              {t('purchase.start')}
            </ButtonLink>
          )
        }
      />

      <Card>
        <FilterBar
          loading={loading}
          selects={5}
          activeCount={activeFilters}
          onClear={() => setFilters(EMPTY_FILTERS)}
          search={<SearchField label={t('purchase.columns.code')} value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder="NH0001" />}
        >
          <FilterSelect label={t('purchase.filters.supplier')} value={filters.supplier} onChange={(event) => set('supplier', event.target.value)}>
            <option value="">{t('purchase.filters.allSuppliers')}</option>
            {(suppliers.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </FilterSelect>
          <FilterSelect label={t('purchase.filters.payment')} value={filters.payment_status} onChange={(event) => set('payment_status', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            <option value="open">{t('purchase.status.open')}</option>
            {PAYMENT_STATUSES.map((status) => <option key={status} value={status}>{t(`purchase.status.${status}`)}</option>)}
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
        ) : purchases.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={PackagePlus}
            title={t('purchase.empty')}
            description={filtered ? t('common.tryOtherFilters') : canCreate ? t('purchase.emptyHint') : undefined}
            action={
              filtered ? (
                <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>{t('common.clearFilters')}</Button>
              ) : canCreate ? (
                <ButtonLink {...link('purchase-new')}>{t('purchase.start')}</ButtonLink>
              ) : undefined
            }
          />
        ) : (
          <Refreshable refreshing={purchases.isPlaceholderData}>
            {summary && (
              <dl className="mb-4 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-3" aria-label={t('purchase.summary.label')}>
                <div><dt className="text-slate-500">{t('purchase.summary.goods')}</dt><dd className="font-semibold text-slate-900">{money(summary.goods_total)}</dd></div>
                <div><dt className="text-slate-500">{t('purchase.summary.paid')}</dt><dd className="font-semibold text-slate-900">{money(summary.paid_amount)}</dd></div>
                <div><dt className="text-slate-500">{t('purchase.summary.balance')}</dt><dd className={`font-semibold ${Number(summary.balance) > 0 ? 'text-red-700' : 'text-slate-900'}`}>{money(summary.balance)}</dd></div>
              </dl>
            )}
            <DataTable minWidth="52rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('purchase.columns.code')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('purchase.columns.supplier')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('purchase.columns.warehouse')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('purchase.columns.total')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('purchase.columns.balance')}</th>
                  <th scope="col" className="py-2.5">{t('purchase.columns.status')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="py-3 pr-4 whitespace-nowrap">
                      <Link {...link('purchase', row.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{row.code}</Link>
                      <span className="block text-xs text-slate-500">{formatIsoDate(row.received_on)}</span>
                    </td>
                    <td className="py-3 pr-4">{row.supplier.name}{row.supplier_ref && <span className="block text-xs text-slate-500">{row.supplier_ref}</span>}</td>
                    <td className="py-3 pr-4">{row.warehouse.name}</td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums">{money(row.goods_total)}</td>
                    <td className={`py-3 pr-4 text-right whitespace-nowrap tabular-nums ${Number(row.balance) > 0 ? 'font-medium text-red-700' : 'text-slate-500'}`}>{money(row.balance)}</td>
                    <td className="py-3 whitespace-nowrap"><PaymentBadge status={row.payment_status} /></td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
            <Pagination page={purchases.data.meta.current_page} lastPage={purchases.data.meta.last_page} total={purchases.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>
    </div>
  )
}
