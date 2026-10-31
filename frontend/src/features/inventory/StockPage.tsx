import { AlertTriangle, ClipboardList, Warehouse as WarehouseIcon } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { FilterBar, Refreshable, StatCardsSkeleton, TableSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Button, Card, Modal, Pagination, SelectField } from '../../components/ui.tsx'
import { formatDateTime, formatQuantity, formatVnd } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useProductTypes } from '../catalog/api.ts'
import { useAllWarehouses, useStockBalancePage, useStockMovementPage, useStockProducts, useStockSummary, type StockFilters, type StockProduct } from './api.ts'
import { ExpiryBadge, TypeBadge } from './parts.tsx'

const linkButton = 'inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm font-medium'
const money = (value: string | null | undefined) => (value === null || value === undefined ? '—' : formatVnd(Number(value)))

/** Tồn kho theo sản phẩm: số lượng (đơn vị cơ sở), mức tối thiểu, hạn dùng gần nhất, cảnh báo sắp hết hàng / sắp hết hạn / đã hết hạn. */
export default function StockPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [filters, setFilters] = useState<StockFilters>({ q: '', type: '', warehouse: '', stock: '', expiry: '', page: 1 })
  const search = useDeferredValue(filters.q)
  const products = useStockProducts({ ...filters, q: search })
  const summary = useStockSummary(filters.warehouse)
  const warehouses = useAllWarehouses()
  const types = useProductTypes()
  const [detail, setDetail] = useState<StockProduct | null>(null)

  const canMove = can('inventory.movement.create')
  const canAdjust = can('inventory.adjustment.create')
  // Chỉ để giữ chỗ: thẻ "Giá trị tồn" chỉ có với người xem được giá vốn (quyền thật do máy chủ quyết định).
  const canSeeCosts = canMove || can('report.report.view')
  const rows = products.data?.data ?? []
  // Ô lọc và bảng chờ dữ liệu tham chiếu (chi nhánh/kho) rồi hiện cùng lúc, để ô lọc/cột "Chi nhánh" thêm vào muộn không làm trang dịch.
  const loading = products.isPending || warehouses.isLoading
  const costs = rows.some((row) => row.avg_cost !== undefined) || summary.data?.stock_value !== undefined
  const set = <K extends keyof StockFilters>(key: K, value: StockFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))
  const toggle = (key: 'stock' | 'expiry', value: string) => set(key, filters[key] === value ? '' : value)
  const warnDays = summary.data?.expiry_warning_days ?? 60

  useClampPage(products.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('inventory.stock.title')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('inventory.stock.intro')}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/stock/documents" className={`${linkButton} border-slate-300 bg-white text-slate-800 hover:bg-slate-50`}>
            <ClipboardList className="h-4 w-4" aria-hidden="true" />
            {t('inventory.stock.documents')}
          </Link>
          {can('inventory.warehouse.view') && (
            <Link to="/warehouses" className={`${linkButton} border-slate-300 bg-white text-slate-800 hover:bg-slate-50`}>
              <WarehouseIcon className="h-4 w-4" aria-hidden="true" />
              {t('inventory.stock.warehouses')}
            </Link>
          )}
        </div>
      </div>

      {(canMove || canAdjust) && (
        <div className="flex flex-wrap gap-2">
          {canMove && <Link to="/stock/new?type=receipt" className={`${linkButton} border-transparent bg-emerald-700 text-white hover:bg-emerald-800`}>{t('inventory.types.receipt')}</Link>}
          {canMove && <Link to="/stock/new?type=issue" className={`${linkButton} border-slate-300 bg-white text-slate-800 hover:bg-slate-50`}>{t('inventory.types.issue')}</Link>}
          {canAdjust && <Link to="/stock/new?type=adjustment" className={`${linkButton} border-slate-300 bg-white text-slate-800 hover:bg-slate-50`}>{t('inventory.types.adjustment')}</Link>}
          {canMove && <Link to="/stock/new?type=opening" className={`${linkButton} border-slate-300 bg-white text-slate-800 hover:bg-slate-50`}>{t('inventory.types.opening')}</Link>}
        </div>
      )}

      {summary.isPending && <StatCardsSkeleton count={canSeeCosts ? 4 : 3} />}

      {summary.data && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label={t('inventory.summary.title')} role="group">
          <SummaryCard label={t('inventory.summary.low')} value={summary.data.low_products} tone="warning" active={filters.stock === 'low'} onClick={() => toggle('stock', 'low')} />
          <SummaryCard label={t('inventory.summary.expiring', { days: warnDays })} value={summary.data.expiring_batches} tone="warning" active={filters.expiry === 'soon'} onClick={() => toggle('expiry', 'soon')} />
          <SummaryCard label={t('inventory.summary.expired')} value={summary.data.expired_batches} tone="danger" active={filters.expiry === 'expired'} onClick={() => toggle('expiry', 'expired')} />
          {summary.data.stock_value !== undefined && (
            <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
              <p className="text-sm text-slate-600">{t('inventory.summary.value')}</p>
              <p className="mt-1 text-xl font-semibold text-slate-900">{money(summary.data.stock_value)}</p>
            </div>
          )}
        </div>
      )}

      <Card>
        <FilterBar loading={loading} fields={['lg:col-span-2', '', '', '', '']} className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
          <div className="lg:col-span-2">
            <label htmlFor="stock-search" className="mb-1 block text-sm font-medium text-slate-800">{t('common.search')}</label>
            <input
              id="stock-search"
              type="search"
              value={filters.q}
              onChange={(event) => set('q', event.target.value)}
              placeholder={t('inventory.stock.searchPlaceholder')}
              className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200"
            />
          </div>
          <SelectField label={t('inventory.filters.type')} value={filters.type} onChange={(event) => set('type', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            {(types.data?.data ?? []).map((d) => <option key={d.key} value={d.key}>{d.label}</option>)}
          </SelectField>
          {(warehouses.data?.length ?? 0) > 1 && (
            <SelectField label={t('inventory.filters.warehouse')} value={filters.warehouse} onChange={(event) => set('warehouse', event.target.value)}>
              <option value="">{t('inventory.filters.allWarehouses')}</option>
              {(warehouses.data ?? []).map((w) => <option key={w.id} value={w.id}>{w.branch.code} — {w.name}</option>)}
            </SelectField>
          )}
          <SelectField label={t('inventory.filters.stock')} value={filters.stock} onChange={(event) => set('stock', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            <option value="in">{t('inventory.filters.in')}</option>
            <option value="out">{t('inventory.filters.out')}</option>
            <option value="low">{t('inventory.filters.low')}</option>
          </SelectField>
          <SelectField label={t('inventory.filters.expiry')} value={filters.expiry} onChange={(event) => set('expiry', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            <option value="soon">{t('inventory.filters.soon', { days: warnDays })}</option>
            <option value="expired">{t('inventory.filters.expired')}</option>
          </SelectField>
        </FilterBar>

        {loading ? (
          <TableSkeleton columns={costs ? 8 : 6} actions minWidth="56rem" />
        ) : products.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('inventory.stock.empty')}</p>
        ) : (
          <Refreshable refreshing={products.isPlaceholderData}>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[56rem] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="py-2 pr-3">{t('inventory.columns.sku')}</th>
                    <th scope="col" className="py-2 pr-3">{t('inventory.columns.product')}</th>
                    <th scope="col" className="py-2 pr-3 text-right">{t('inventory.columns.stock')}</th>
                    <th scope="col" className="py-2 pr-3 text-right">{t('inventory.columns.min')}</th>
                    <th scope="col" className="py-2 pr-3">{t('inventory.columns.nextExpiry')}</th>
                    {costs && <th scope="col" className="py-2 pr-3 text-right">{t('inventory.columns.avgCost')}</th>}
                    {costs && <th scope="col" className="py-2 pr-3 text-right">{t('inventory.columns.value')}</th>}
                    <th scope="col" className="py-2 text-right"><span className="sr-only">{t('backup.list.actions')}</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.map((product) => (
                    <tr key={product.id}>
                      <td className="py-2 pr-3 font-mono text-xs whitespace-nowrap">{product.sku}</td>
                      <td className="py-2 pr-3">
                        <span className="font-medium text-slate-900">{product.name}</span>
                        <span className="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-slate-500">
                          <Badge tone="info">{product.type_label}</Badge>
                          {product.track_batch && <Badge tone="neutral">{t('inventory.stock.batch')}</Badge>}
                          {product.is_low && <Badge tone="warning">{t('inventory.stock.low')}</Badge>}
                          {Number(product.expired_qty) > 0 && <Badge tone="danger">{t('inventory.stock.hasExpired', { qty: formatQuantity(product.expired_qty), unit: product.base_unit.name })}</Badge>}
                        </span>
                      </td>
                      <td className="py-2 pr-3 text-right whitespace-nowrap">
                        <span className="font-medium">{formatQuantity(product.total_qty)}</span> <span className="text-slate-500">{product.base_unit.name}</span>
                      </td>
                      <td className="py-2 pr-3 text-right whitespace-nowrap text-slate-600">{product.min_stock === null ? '—' : formatQuantity(product.min_stock)}</td>
                      <td className="py-2 pr-3"><ExpiryBadge date={product.next_expiry} warnDays={warnDays} /></td>
                      {costs && <td className="py-2 pr-3 text-right whitespace-nowrap">{money(product.avg_cost)}</td>}
                      {costs && <td className="py-2 pr-3 text-right whitespace-nowrap">{money(product.value)}</td>}
                      <td className="py-2 text-right whitespace-nowrap">
                        <Button variant="ghost" onClick={() => setDetail(product)} aria-label={`${t('inventory.stock.detail')} ${product.name}`}>{t('inventory.stock.detail')}</Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={products.data.meta.current_page} lastPage={products.data.meta.last_page} total={products.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>

      {detail && <ProductStockDialog product={detail} warehouse={filters.warehouse} warnDays={warnDays} onClose={() => setDetail(null)} />}
    </div>
  )
}

function SummaryCard({ label, value, tone, active, onClick }: { label: string; value: number; tone: 'warning' | 'danger'; active: boolean; onClick: () => void }) {
  const { t } = useTranslation()
  const color = value === 0 ? 'text-slate-900' : tone === 'danger' ? 'text-red-700' : 'text-amber-700'

  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      title={t('inventory.summary.filterHint')}
      className={`rounded-lg border p-4 text-left shadow-sm transition-colors ${active ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200 bg-white hover:bg-slate-50'}`}
    >
      <span className="flex items-center gap-1 text-sm text-slate-600">
        {value > 0 && <AlertTriangle className={`h-4 w-4 ${tone === 'danger' ? 'text-red-600' : 'text-amber-600'}`} aria-hidden="true" />}
        {label}
      </span>
      <span className={`mt-1 block text-xl font-semibold ${color}`}>{value}</span>
    </button>
  )
}

/** Chi tiết tồn của một sản phẩm: từng lô (và kho) với hạn dùng, và thẻ kho gần đây. */
function ProductStockDialog({ product, warehouse, warnDays, onClose }: { product: StockProduct; warehouse: string; warnDays: number; onClose: () => void }) {
  const { t } = useTranslation()
  const [balancePage, setBalancePage] = useState(1)
  const [movementPage, setMovementPage] = useState(1)
  const balances = useStockBalancePage({ product: product.id, warehouse }, balancePage)
  const movements = useStockMovementPage({ product: product.id, warehouse }, movementPage)
  const showCost = product.avg_cost !== undefined
  useClampPage(balances.data?.meta, setBalancePage)
  useClampPage(movements.data?.meta, setMovementPage)

  return (
    <Modal title={t('inventory.detail.title', { name: product.name })} onClose={onClose} size="xl">
      <div className="space-y-5">
        <p className="text-sm text-slate-600">
          {t('inventory.detail.summary', { total: formatQuantity(product.total_qty), unit: product.base_unit.name })}
          {product.min_stock !== null && ` ${t('inventory.detail.min', { min: formatQuantity(product.min_stock), unit: product.base_unit.name })}`}
          {showCost && ` ${t('inventory.detail.cost', { cost: money(product.avg_cost), unit: product.base_unit.name })}`}
        </p>

        <section>
          <h3 className="mb-2 text-sm font-semibold text-slate-900">{t('inventory.detail.balances')}</h3>
          {balances.isPending ? (
            <TableSkeleton columns={4} rows={3} minWidth="32rem" />
          ) : balances.isError ? (
            <Alert tone="danger">{t('common.loadError')}</Alert>
          ) : balances.data.data.length === 0 ? (
            <p className="text-sm text-slate-500">{t('inventory.detail.noBalances')}</p>
          ) : (
            <Refreshable refreshing={balances.isPlaceholderData}>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[32rem] text-left text-sm">
                  <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                      <th scope="col" className="py-2 pr-3">{t('inventory.columns.warehouse')}</th>
                      <th scope="col" className="py-2 pr-3">{t('inventory.columns.batch')}</th>
                      <th scope="col" className="py-2 pr-3">{t('inventory.columns.expiry')}</th>
                      <th scope="col" className="py-2 text-right">{t('inventory.columns.quantity')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {balances.data.data.map((row) => (
                      <tr key={row.id}>
                        <td className="py-2 pr-3">{row.warehouse.name}</td>
                        <td className="py-2 pr-3">{row.batch?.batch_no ?? '—'}</td>
                        <td className="py-2 pr-3"><ExpiryBadge date={row.batch?.expires_at ?? null} warnDays={warnDays} /></td>
                        <td className="py-2 text-right whitespace-nowrap">{formatQuantity(row.quantity)} {product.base_unit.name}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pagination page={balances.data.meta.current_page} lastPage={balances.data.meta.last_page} total={balances.data.meta.total} onPage={setBalancePage} />
            </Refreshable>
          )}
        </section>

        <section>
          <h3 className="mb-2 text-sm font-semibold text-slate-900">{t('inventory.detail.card')}</h3>
          {movements.isPending ? (
            <TableSkeleton columns={5} rows={5} minWidth="40rem" />
          ) : movements.isError ? (
            <Alert tone="danger">{t('common.loadError')}</Alert>
          ) : movements.data.data.length === 0 ? (
            <p className="text-sm text-slate-500">{t('inventory.detail.noMovements')}</p>
          ) : (
            <Refreshable refreshing={movements.isPlaceholderData}>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[40rem] text-left text-sm">
                  <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                      <th scope="col" className="py-2 pr-3">{t('inventory.columns.time')}</th>
                      <th scope="col" className="py-2 pr-3">{t('inventory.columns.document')}</th>
                      <th scope="col" className="py-2 pr-3">{t('inventory.columns.batch')}</th>
                      <th scope="col" className="py-2 pr-3 text-right">{t('inventory.columns.change')}</th>
                      <th scope="col" className="py-2 text-right">{t('inventory.columns.balanceAfter')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {movements.data.data.map((m) => (
                      <tr key={m.id}>
                        <td className="py-2 pr-3 whitespace-nowrap">{formatDateTime(m.performed_at)}</td>
                        <td className="py-2 pr-3">
                          <Link to={`/stock/documents/${m.document.id}`} onClick={onClose} className="font-mono text-xs text-emerald-700 hover:underline">{m.document.code}</Link>
                          <span className="ml-1"><TypeBadge type={m.type} /></span>
                        </td>
                        <td className="py-2 pr-3">{m.batch?.batch_no ?? '—'}</td>
                        <td className={`py-2 pr-3 text-right whitespace-nowrap font-medium ${Number(m.quantity_delta) < 0 ? 'text-red-700' : 'text-emerald-700'}`}>
                          {Number(m.quantity_delta) > 0 ? '+' : ''}{formatQuantity(m.quantity_delta)}
                          <span className="block text-xs font-normal text-slate-500">{t('inventory.detail.entered', { quantity: formatQuantity(m.entered.quantity), unit: m.entered.unit })}</span>
                        </td>
                        <td className="py-2 text-right whitespace-nowrap">{formatQuantity(m.balance_after)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pagination page={movements.data.meta.current_page} lastPage={movements.data.meta.last_page} total={movements.data.meta.total} onPage={setMovementPage} />
            </Refreshable>
          )}
        </section>

        <div className="flex justify-end"><Button variant="ghost" onClick={onClose}>{t('common.close')}</Button></div>
      </div>
    </Modal>
  )
}
