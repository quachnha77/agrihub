import { ChevronDown, Clock, PackageMinus, PackagePlus, PackageSearch, TriangleAlert, Wallet, type LucideIcon } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { Menu, MenuItem } from '../../components/menu.tsx'
import { EmptyState, FilterBar, FilterSelect, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, StatCardsSkeleton, TableSkeleton } from '../../components/skeleton.tsx'
import { buttonStyles } from '../../components/styles.ts'
import { DataTable } from '../../components/table.tsx'
import { Alert, Badge, Button, ButtonLink, Card, Modal, Pagination } from '../../components/ui.tsx'
import { useDialog } from '../../lib/dialog.ts'
import { countActiveFilters } from '../../lib/filters.ts'
import { formatDateTime, formatQuantity, formatVnd } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useProductTypes } from '../catalog/api.ts'
import { DEFAULT_EXPIRY_WARNING_DAYS, useAllWarehouses, useStockBalancePage, useStockMovementPage, useStockProducts, useStockSummary, type StockFilters, type StockProduct } from './api.ts'
import { ExpiryBadge, TypeBadge } from './parts.tsx'
import { StockTabs } from './StockTabs.tsx'

const money = (value: string | null | undefined) => (value === null || value === undefined ? '—' : formatVnd(Number(value)))

const EMPTY_FILTERS: StockFilters = { q: '', type: '', warehouse: '', stock: '', expiry: '', page: 1 }

/** Tồn kho theo sản phẩm: số lượng (đơn vị cơ sở), mức tối thiểu, hạn dùng gần nhất, cảnh báo sắp hết hàng / sắp hết hạn / đã hết hạn. */
export default function StockPage() {
  const { t } = useTranslation()
  const { link } = useDialog()
  const { can } = useAuth()
  // Trang Tổng quan dẫn thẳng tới danh sách đã lọc (?stock=low, ?expiry=soon|expired).
  const [params] = useSearchParams()
  const [filters, setFilters] = useState<StockFilters>(() => ({
    ...EMPTY_FILTERS,
    stock: ['in', 'out', 'low'].includes(params.get('stock') ?? '') ? (params.get('stock') as string) : '',
    expiry: ['soon', 'expired'].includes(params.get('expiry') ?? '') ? (params.get('expiry') as string) : '',
  }))
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
  const warnDays = summary.data?.expiry_warning_days ?? DEFAULT_EXPIRY_WARNING_DAYS
  const activeFilters = countActiveFilters(filters)
  const filtered = activeFilters > 0 || filters.q !== ''

  useClampPage(products.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('inventory.stock.title')}
        description={t('inventory.stock.intro')}
        tabs={<StockTabs />}
        actions={
          (canMove || canAdjust) && (
            <>
              {canMove && (
                <ButtonLink {...link('stock-new', 'receipt')}>
                  <PackagePlus className="h-4 w-4" aria-hidden="true" />
                  {t('inventory.types.receipt')}
                </ButtonLink>
              )}
              {canMove && (
                <ButtonLink variant="secondary" {...link('stock-new', 'issue')}>
                  {t('inventory.types.issue')}
                </ButtonLink>
              )}
              <Menu
                label={t('inventory.stock.moreDocuments')}
                triggerClassName={`${buttonStyles('secondary')} cursor-pointer`}
                trigger={
                  <>
                    {t('inventory.stock.moreDocuments')}
                    <ChevronDown className="h-4 w-4" aria-hidden="true" />
                  </>
                }
              >
                {(close) => (
                  <>
                    {canAdjust && <MenuItem {...link('stock-new', 'adjustment')} onSelect={close}>{t('inventory.types.adjustment')}</MenuItem>}
                    {canMove && <MenuItem {...link('stock-new', 'opening')} onSelect={close}>{t('inventory.types.opening')}</MenuItem>}
                  </>
                )}
              </Menu>
            </>
          )
        }
      />

      {summary.isPending && (
        <StatCardsSkeleton
          labels={[t('inventory.summary.low'), t('inventory.summary.expiring', { days: warnDays }), t('inventory.summary.expired'), ...(canSeeCosts ? [t('inventory.summary.value')] : [])]}
          money={canSeeCosts}
        />
      )}

      {summary.data && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label={t('inventory.summary.title')} role="group">
          <SummaryCard icon={PackageMinus} label={t('inventory.summary.low')} value={summary.data.low_products} tone="warning" active={filters.stock === 'low'} onClick={() => toggle('stock', 'low')} />
          <SummaryCard icon={Clock} label={t('inventory.summary.expiring', { days: warnDays })} value={summary.data.expiring_batches} tone="warning" active={filters.expiry === 'soon'} onClick={() => toggle('expiry', 'soon')} />
          <SummaryCard icon={TriangleAlert} label={t('inventory.summary.expired')} value={summary.data.expired_batches} tone="danger" active={filters.expiry === 'expired'} onClick={() => toggle('expiry', 'expired')} />
          {summary.data.stock_value !== undefined && (
            <div className="flex min-h-[4.75rem] items-center gap-3 rounded-xl border border-slate-200/80 bg-white p-3.5 shadow-card">
              <span className="hidden h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700 sm:grid">
                <Wallet className="h-5 w-5" aria-hidden="true" />
              </span>
              <span className="min-w-0">
                <span className="block break-words text-base font-semibold leading-tight tabular-nums text-slate-900 sm:text-lg">{money(summary.data.stock_value)}</span>
                <span className="block text-xs text-slate-600">{t('inventory.summary.value')}</span>
              </span>
            </div>
          )}
        </div>
      )}

      <Card>
        <FilterBar
          loading={loading}
          selects={4}
          activeCount={activeFilters}
          onClear={() => setFilters(EMPTY_FILTERS)}
          search={<SearchField label={t('common.search')} value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder={t('inventory.stock.searchPlaceholder')} />}
        >
          <FilterSelect label={t('inventory.filters.type')} value={filters.type} onChange={(event) => set('type', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            {(types.data?.data ?? []).map((d) => <option key={d.key} value={d.key}>{d.label}</option>)}
          </FilterSelect>
          {(warehouses.data?.length ?? 0) > 1 && (
            <FilterSelect label={t('inventory.filters.warehouse')} value={filters.warehouse} onChange={(event) => set('warehouse', event.target.value)}>
              <option value="">{t('inventory.filters.allWarehouses')}</option>
              {(warehouses.data ?? []).map((w) => <option key={w.id} value={w.id}>{w.branch.code} — {w.name}</option>)}
            </FilterSelect>
          )}
          <FilterSelect label={t('inventory.filters.stock')} value={filters.stock} onChange={(event) => set('stock', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            <option value="in">{t('inventory.filters.in')}</option>
            <option value="out">{t('inventory.filters.out')}</option>
            <option value="low">{t('inventory.filters.low')}</option>
          </FilterSelect>
          <FilterSelect label={t('inventory.filters.expiry')} value={filters.expiry} onChange={(event) => set('expiry', event.target.value)}>
            <option value="">{t('inventory.filters.all')}</option>
            <option value="soon">{t('inventory.filters.soon', { days: warnDays })}</option>
            <option value="expired">{t('inventory.filters.expired')}</option>
          </FilterSelect>
        </FilterBar>

        {loading ? (
          <TableSkeleton columns={costs ? 8 : 6} actions minWidth="56rem" />
        ) : products.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={PackageSearch}
            title={t('inventory.stock.empty')}
            description={filtered ? t('common.tryOtherFilters') : undefined}
            action={filtered ? <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>{t('common.clearFilters')}</Button> : undefined}
          />
        ) : (
          <Refreshable refreshing={products.isPlaceholderData}>
            <DataTable minWidth="56rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.sku')}</th>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.columns.product')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.stock')}</th>
                  <th scope="col" data-role="optional" className="py-2.5 pr-4 text-right">{t('inventory.columns.min')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.nextExpiry')}</th>
                  {costs && <th scope="col" data-role="optional" className="py-2.5 pr-4 text-right">{t('inventory.columns.avgCost')}</th>}
                  {costs && <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.value')}</th>}
                  <th scope="col" data-role="actions" className="py-2.5 text-right"><span className="sr-only">{t('backup.list.actions')}</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((product) => (
                  <tr key={product.id}>
                    <td className="py-3 pr-4 font-mono text-xs whitespace-nowrap text-slate-600">{product.sku}</td>
                    <td className="py-3 pr-4">
                      <span className="font-medium text-slate-900">{product.name}</span>
                      <span className="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-500">
                        <span>{product.type_label}</span>
                        {product.track_batch && (
                          <>
                            <span aria-hidden="true">·</span>
                            <span>{t('inventory.stock.batch')}</span>
                          </>
                        )}
                        {product.is_low && <Badge tone="warning">{t('inventory.stock.low')}</Badge>}
                        {Number(product.expired_qty) > 0 && <Badge tone="danger">{t('inventory.stock.hasExpired', { qty: formatQuantity(product.expired_qty), unit: product.base_unit.name })}</Badge>}
                      </span>
                    </td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums">
                      <span className="font-semibold text-slate-900">{formatQuantity(product.total_qty)}</span> <span className="text-slate-500">{product.base_unit.name}</span>
                    </td>
                    <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums text-slate-600">{product.min_stock === null ? '—' : formatQuantity(product.min_stock)}</td>
                    <td className="py-3 pr-4"><ExpiryBadge date={product.next_expiry} warnDays={warnDays} /></td>
                    {costs && <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums">{money(product.avg_cost)}</td>}
                    {costs && <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums">{money(product.value)}</td>}
                    <td className="py-3 text-right whitespace-nowrap">
                      <Button variant="soft" size="sm" onClick={() => setDetail(product)} aria-label={`${t('inventory.stock.detail')} ${product.name}`}>{t('inventory.stock.detail')}</Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
            <Pagination page={products.data.meta.current_page} lastPage={products.data.meta.last_page} total={products.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>

      {detail && <ProductStockDialog product={detail} warehouse={filters.warehouse} warnDays={warnDays} onClose={() => setDetail(null)} />}
    </div>
  )
}

function SummaryCard({ icon: Icon, label, value, tone, active, onClick }: { icon: LucideIcon; label: string; value: number; tone: 'warning' | 'danger'; active: boolean; onClick: () => void }) {
  const { t } = useTranslation()
  const circle = value === 0 ? 'bg-slate-100 text-slate-400' : tone === 'danger' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-600'
  const color = value === 0 ? 'text-slate-900' : tone === 'danger' ? 'text-red-700' : 'text-amber-700'

  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      title={t('inventory.summary.filterHint')}
      className={`flex min-h-[4.75rem] items-center gap-3 rounded-xl border p-3.5 text-left shadow-card transition-colors ${active ? 'border-brand-600 bg-brand-50' : 'border-slate-200/80 bg-white hover:border-slate-300'}`}
    >
      <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-full ${circle}`}>
        <Icon className="h-5 w-5" aria-hidden="true" />
      </span>
      <span className="min-w-0">
        <span className={`block text-2xl font-semibold leading-tight tabular-nums ${color}`}>{value}</span>
        <span className="block text-xs text-slate-600">{label}</span>
      </span>
    </button>
  )
}

/** Chi tiết tồn của một sản phẩm: từng lô (và kho) với hạn dùng, và thẻ kho gần đây. */
function ProductStockDialog({ product, warehouse, warnDays, onClose }: { product: StockProduct; warehouse: string; warnDays: number; onClose: () => void }) {
  const { t } = useTranslation()
  const { link } = useDialog()
  const [balancePage, setBalancePage] = useState(1)
  const [movementPage, setMovementPage] = useState(1)
  const balances = useStockBalancePage({ product: product.id, warehouse }, balancePage)
  const movements = useStockMovementPage({ product: product.id, warehouse }, movementPage)
  const showCost = product.avg_cost !== undefined
  useClampPage(balances.data?.meta, setBalancePage)
  useClampPage(movements.data?.meta, setMovementPage)

  return (
    <Modal title={t('inventory.detail.title', { name: product.name })} onClose={onClose} size="xl" fixedHeight="sheet">
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
              <DataTable minWidth="32rem">
                <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                  <tr>
                    <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.columns.warehouse')}</th>
                    <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.batch')}</th>
                    <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.expiry')}</th>
                    <th scope="col" className="py-2.5 text-right">{t('inventory.columns.quantity')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {balances.data.data.map((row) => (
                    <tr key={row.id}>
                      <td className="py-3 pr-4">{row.warehouse.name}</td>
                      <td className="py-3 pr-4">{row.batch?.batch_no ?? '—'}</td>
                      <td className="py-3 pr-4"><ExpiryBadge date={row.batch?.expires_at ?? null} warnDays={warnDays} /></td>
                      <td className="py-3 text-right whitespace-nowrap">{formatQuantity(row.quantity)} {product.base_unit.name}</td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
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
              <DataTable minWidth="40rem">
                <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                  <tr>
                    <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.columns.time')}</th>
                    <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.document')}</th>
                    <th scope="col" className="py-2.5 pr-4">{t('inventory.columns.batch')}</th>
                    <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.columns.change')}</th>
                    <th scope="col" className="py-2.5 text-right">{t('inventory.columns.balanceAfter')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {movements.data.data.map((m) => (
                    <tr key={m.id}>
                      <td className="py-3 pr-4 whitespace-nowrap">{formatDateTime(m.performed_at)}</td>
                      <td className="py-3 pr-4">
                        <Link {...link('stock-doc', m.document.id)} onClick={onClose} className="font-mono text-xs text-brand-700 hover:underline">{m.document.code}</Link>
                        <span className="ml-1"><TypeBadge type={m.type} /></span>
                      </td>
                      <td className="py-3 pr-4">{m.batch?.batch_no ?? '—'}</td>
                      <td className={`py-3 pr-4 text-right whitespace-nowrap font-medium ${Number(m.quantity_delta) < 0 ? 'text-red-700' : 'text-brand-700'}`}>
                        {Number(m.quantity_delta) > 0 ? '+' : ''}{formatQuantity(m.quantity_delta)}
                        <span className="block text-xs font-normal text-slate-500">{t('inventory.detail.entered', { quantity: formatQuantity(m.entered.quantity), unit: m.entered.unit })}</span>
                      </td>
                      <td className="py-3 text-right whitespace-nowrap">{formatQuantity(m.balance_after)}</td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
              <Pagination page={movements.data.meta.current_page} lastPage={movements.data.meta.last_page} total={movements.data.meta.total} onPage={setMovementPage} />
            </Refreshable>
          )}
        </section>

        <div className="flex justify-end"><Button variant="ghost" onClick={onClose}>{t('common.close')}</Button></div>
      </div>
    </Modal>
  )
}
