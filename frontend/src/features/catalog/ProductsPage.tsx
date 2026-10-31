import { Copy, Eye, ListChecks, Pencil, Plus, Trash2 } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation } from 'react-router'
import { FilterBar, Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Button, Card, ConfirmDialog, Pagination, SelectField } from '../../components/ui.tsx'
import { describeError } from '../../lib/forms.ts'
import { formatVnd } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useAllBranches } from '../branches/api.ts'
import { useCatalogList, useDeleteProduct, useProductTypes, useProducts, type Product, type ProductFilters } from './api.ts'
import { CopyDialog } from './CopyDialog.tsx'

const linkButton = 'inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm font-medium'
const money = (value: string) => formatVnd(Number(value))

/** Danh mục sản phẩm: tìm, lọc, thêm/sửa/xóa, và sao chép sang chi nhánh khác. Mỗi sản phẩm thuộc một chi nhánh. */
export default function ProductsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const location = useLocation()
  const saved = (location.state as { saved?: 'created' | 'updated'; name?: string } | null) ?? null

  const [filters, setFilters] = useState<ProductFilters>({ q: '', type: '', status: '', branch: '', category: '', brand: '', page: 1 })
  const search = useDeferredValue(filters.q)
  const products = useProducts({ ...filters, q: search })
  const types = useProductTypes()
  const branches = useAllBranches()
  const categories = useCatalogList('categories')
  const brands = useCatalogList('brands')
  const remove = useDeleteProduct()

  const [selected, setSelected] = useState<Product[]>([])
  const [deleting, setDeleting] = useState<Product | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const [copying, setCopying] = useState(false)
  const [dismissed, setDismissed] = useState(false)

  const canCreate = can('catalog.product.create')
  const canUpdate = can('catalog.product.update')
  const canDelete = can('catalog.product.delete')
  const rows = products.data?.data ?? []
  const set = <K extends keyof ProductFilters>(key: K, value: ProductFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))
  const isSelected = (product: Product) => selected.some((item) => item.id === product.id)
  const toggle = (product: Product) => setSelected((current) => (isSelected(product) ? current.filter((item) => item.id !== product.id) : [...current, product]))
  const allOnPage = rows.length > 0 && rows.every(isSelected)
  const showBranch = (branches.data?.length ?? 0) > 1
  // Ô lọc và bảng chờ dữ liệu tham chiếu (chi nhánh/kho) rồi hiện cùng lúc, để ô lọc/cột "Chi nhánh" thêm vào muộn không làm trang dịch.
  const loading = products.isPending || branches.isLoading

  async function confirmDelete() {
    if (!deleting) return
    setDeleteError(null)

    try {
      await remove.mutateAsync(deleting.id)
      setSelected((current) => current.filter((item) => item.id !== deleting.id))
      setDeleting(null)
    } catch (error) {
      setDeleteError(describeError(error, t))
    }
  }

  useClampPage(products.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('catalog.title')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('catalog.intro')}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/products/lists" className={`${linkButton} border-slate-300 bg-white text-slate-800 hover:bg-slate-50`}>
            <ListChecks className="h-4 w-4" aria-hidden="true" />
            {t('catalog.manageLists')}
          </Link>
          {canCreate && (
            <Link to="/products/new" className={`${linkButton} border-transparent bg-emerald-700 text-white hover:bg-emerald-800`}>
              <Plus className="h-4 w-4" aria-hidden="true" />
              {t('catalog.add')}
            </Link>
          )}
        </div>
      </div>

      {saved?.saved && !dismissed && (
        <Alert tone="success">
          <span className="flex flex-wrap items-center justify-between gap-2">
            {t(saved.saved === 'created' ? 'catalog.savedCreated' : 'catalog.savedUpdated', { name: saved.name })}
            <Button variant="ghost" onClick={() => setDismissed(true)}>{t('common.close')}</Button>
          </span>
        </Alert>
      )}

      <Card>
        <FilterBar loading={loading} fields={['lg:col-span-2', '', '', '', '', '']} className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-7">
          <div className="lg:col-span-2">
            <label htmlFor="product-search" className="mb-1 block text-sm font-medium text-slate-800">{t('common.search')}</label>
            <input
              id="product-search"
              type="search"
              value={filters.q}
              onChange={(event) => set('q', event.target.value)}
              placeholder={t('catalog.searchPlaceholder')}
              className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200"
            />
          </div>
          <SelectField label={t('catalog.filters.type')} value={filters.type} onChange={(event) => set('type', event.target.value)}>
            <option value="">{t('catalog.filters.all')}</option>
            {(types.data?.data ?? []).map((d) => <option key={d.key} value={d.key}>{d.label}</option>)}
          </SelectField>
          {showBranch && (
            <SelectField label={t('catalog.filters.branch')} value={filters.branch} onChange={(event) => set('branch', event.target.value)}>
              <option value="">{t('catalog.filters.all')}</option>
              {(branches.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
            </SelectField>
          )}
          <SelectField label={t('catalog.filters.category')} value={filters.category} onChange={(event) => set('category', event.target.value)}>
            <option value="">{t('catalog.filters.all')}</option>
            {(categories.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField label={t('catalog.filters.brand')} value={filters.brand} onChange={(event) => set('brand', event.target.value)}>
            <option value="">{t('catalog.filters.all')}</option>
            {(brands.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </SelectField>
          <SelectField label={t('catalog.filters.status')} value={filters.status} onChange={(event) => set('status', event.target.value)}>
            <option value="">{t('catalog.filters.all')}</option>
            <option value="active">{t('catalog.status.active')}</option>
            <option value="inactive">{t('catalog.status.inactive')}</option>
          </SelectField>
        </FilterBar>

        {canCreate && selected.length > 0 && (
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
            <span>{t('catalog.selected', { count: selected.length })}</span>
            <span className="flex gap-2">
              <Button variant="secondary" onClick={() => setCopying(true)}>
                <Copy className="h-4 w-4" aria-hidden="true" />
                {t('catalog.copy.open')}
              </Button>
              <Button variant="ghost" onClick={() => setSelected([])}>{t('catalog.clearSelection')}</Button>
            </span>
          </div>
        )}

        {loading ? (
          <TableSkeleton columns={showBranch ? 9 : 8} actions minWidth="56rem" />
        ) : products.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('catalog.empty')}</p>
        ) : (
          <Refreshable refreshing={products.isPlaceholderData}>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[56rem] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    {canCreate && (
                      <th scope="col" className="w-8 py-2 pr-2">
                        <input
                          type="checkbox"
                          checked={allOnPage}
                          aria-label={t('catalog.selectAll')}
                          onChange={(event) => setSelected((current) => (event.target.checked ? [...current, ...rows.filter((row) => !current.some((item) => item.id === row.id))] : current.filter((item) => !rows.some((row) => row.id === item.id))))}
                          className="h-4 w-4 rounded border-slate-300 text-emerald-700"
                        />
                      </th>
                    )}
                    <th scope="col" className="py-2 pr-3">{t('catalog.columns.sku')}</th>
                    <th scope="col" className="py-2 pr-3">{t('catalog.columns.product')}</th>
                    {showBranch && <th scope="col" className="py-2 pr-3">{t('catalog.columns.branch')}</th>}
                    <th scope="col" className="py-2 pr-3">{t('catalog.columns.unit')}</th>
                    <th scope="col" className="py-2 pr-3 text-right">{t('catalog.columns.priceCash')}</th>
                    <th scope="col" className="py-2 pr-3 text-right">{t('catalog.columns.priceCredit')}</th>
                    <th scope="col" className="py-2 pr-3">{t('catalog.columns.status')}</th>
                    <th scope="col" className="py-2 text-right">{t('backup.list.actions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.map((product) => {
                    const base = product.units.find((u) => u.is_base)
                    const others = product.units.length - 1

                    return (
                      <tr key={product.id}>
                        {canCreate && (
                          <td className="py-2 pr-2">
                            <input type="checkbox" checked={isSelected(product)} onChange={() => toggle(product)} aria-label={t('catalog.select', { name: product.name })} className="h-4 w-4 rounded border-slate-300 text-emerald-700" />
                          </td>
                        )}
                        <td className="py-2 pr-3 font-mono text-xs whitespace-nowrap">{product.sku}</td>
                        <td className="py-2 pr-3">
                          <span className="font-medium text-slate-900">{product.name}</span>
                          <span className="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-slate-500">
                            <Badge tone="info">{product.type_label}</Badge>
                            {product.track_batch && <Badge tone="warning">{t('catalog.batch')}</Badge>}
                            {[product.category?.name, product.brand?.name].filter(Boolean).join(' · ')}
                          </span>
                        </td>
                        {showBranch && <td className="py-2 pr-3">{product.branch.code}</td>}
                        <td className="py-2 pr-3">
                          {product.base_unit.name}
                          {others > 0 && <span className="block text-xs text-slate-500">{t('catalog.moreUnits', { count: others })}</span>}
                        </td>
                        <td className="py-2 pr-3 text-right whitespace-nowrap">{base ? money(base.price_cash) : '—'}</td>
                        <td className="py-2 pr-3 text-right whitespace-nowrap">{base ? money(base.price_credit) : '—'}</td>
                        <td className="py-2 pr-3">
                          <Badge tone={product.status === 'active' ? 'success' : 'neutral'}>{t(`catalog.status.${product.status}`)}</Badge>
                        </td>
                        <td className="py-2 text-right">
                          <div className="flex justify-end gap-1">
                            <Link to={`/products/${product.id}`} aria-label={`${canUpdate ? t('common.edit') : t('catalog.view')} ${product.name}`} className="rounded-md px-3 py-2 text-slate-700 hover:bg-slate-100">
                              {canUpdate ? <Pencil className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
                            </Link>
                            {canDelete && (
                              <Button variant="ghost" onClick={() => { setDeleteError(null); setDeleting(product) }} aria-label={`${t('common.delete')} ${product.name}`}>
                                <Trash2 className="h-4 w-4 text-red-700" aria-hidden="true" />
                              </Button>
                            )}
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <Pagination page={products.data.meta.current_page} lastPage={products.data.meta.last_page} total={products.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>

      {copying && <CopyDialog productIds={selected.map((p) => p.id)} fromBranches={[...new Set(selected.map((p) => p.branch.id))]} onClose={() => { setCopying(false); setSelected([]) }} />}

      {deleting && (
        <ConfirmDialog title={t('catalog.deleteTitle')} confirmLabel={t('common.delete')} danger loading={remove.isPending} error={deleteError} onConfirm={confirmDelete} onClose={() => setDeleting(null)}>
          {t('catalog.deleteText', { name: deleting.name, sku: deleting.sku })}
        </ConfirmDialog>
      )}
    </div>
  )
}
