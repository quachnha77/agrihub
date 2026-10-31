import { Copy, Eye, Package, Pencil, Plus, Trash2 } from 'lucide-react'
import { Fragment, useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { EmptyState, FilterBar, FilterSelect, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Badge, Button, ButtonLink, Card, ConfirmDialog, IconButton, IconLink, Pagination } from '../../components/ui.tsx'
import { useDialog } from '../../lib/dialog.ts'
import { countActiveFilters } from '../../lib/filters.ts'
import { describeError } from '../../lib/forms.ts'
import { formatVnd } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useAllBranches } from '../branches/api.ts'
import { useCatalogList, useDeleteProduct, useProductTypes, useProducts, type Product, type ProductFilters } from './api.ts'
import { CopyDialog } from './CopyDialog.tsx'
import { ProductTabs } from './ProductTabs.tsx'

const money = (value: string) => formatVnd(Number(value))

/** Loại hàng · nhóm hàng · thương hiệu, bỏ phần trùng tên (nhóm "Phân bón" của loại "Phân bón" không cần nhắc lại). */
function subtitleOf(product: Product): string[] {
  const seen = new Set<string>()

  return [product.type_label, product.category?.name, product.brand?.name].filter((part): part is string => {
    if (!part || seen.has(part.toLowerCase())) return false
    seen.add(part.toLowerCase())

    return true
  })
}

const EMPTY_FILTERS: ProductFilters = { q: '', type: '', status: '', branch: '', category: '', brand: '', page: 1 }

/** Danh mục sản phẩm: tìm, lọc, thêm/sửa/xóa, và sao chép sang chi nhánh khác. Mỗi sản phẩm thuộc một chi nhánh. */
export default function ProductsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { link } = useDialog()

  const [filters, setFilters] = useState<ProductFilters>(EMPTY_FILTERS)
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
  const activeFilters = countActiveFilters(filters)
  const filtered = activeFilters > 0 || filters.q !== ''

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
    <div className="space-y-5">
      <PageHeader
        title={t('catalog.title')}
        description={t('catalog.intro')}
        tabs={<ProductTabs />}
        actions={
          canCreate && (
            <ButtonLink {...link('product')}>
              <Plus className="h-4 w-4" aria-hidden="true" />
              {t('catalog.add')}
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
          search={<SearchField label={t('common.search')} value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder={t('catalog.searchPlaceholder')} />}
        >
          <FilterSelect label={t('catalog.filters.type')} value={filters.type} onChange={(event) => set('type', event.target.value)}>
            <option value="">{t('catalog.filters.all')}</option>
            {(types.data?.data ?? []).map((d) => <option key={d.key} value={d.key}>{d.label}</option>)}
          </FilterSelect>
          {showBranch && (
            <FilterSelect label={t('catalog.filters.branch')} value={filters.branch} onChange={(event) => set('branch', event.target.value)}>
              <option value="">{t('catalog.filters.all')}</option>
              {(branches.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
            </FilterSelect>
          )}
          <FilterSelect label={t('catalog.filters.category')} value={filters.category} onChange={(event) => set('category', event.target.value)}>
            <option value="">{t('catalog.filters.all')}</option>
            {(categories.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </FilterSelect>
          <FilterSelect label={t('catalog.filters.brand')} value={filters.brand} onChange={(event) => set('brand', event.target.value)}>
            <option value="">{t('catalog.filters.all')}</option>
            {(brands.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </FilterSelect>
          <FilterSelect label={t('catalog.filters.status')} value={filters.status} onChange={(event) => set('status', event.target.value)}>
            <option value="">{t('catalog.filters.all')}</option>
            <option value="active">{t('catalog.status.active')}</option>
            <option value="inactive">{t('catalog.status.inactive')}</option>
          </FilterSelect>
        </FilterBar>

        {canCreate && selected.length > 0 && (
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-900">
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
          <EmptyState
            icon={Package}
            title={t('catalog.empty')}
            description={filtered ? t('common.tryOtherFilters') : undefined}
            action={filtered ? <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>{t('common.clearFilters')}</Button> : canCreate ? <ButtonLink {...link('product')}>{t('catalog.add')}</ButtonLink> : undefined}
          />
        ) : (
          <Refreshable refreshing={products.isPlaceholderData}>
            <DataTable minWidth="56rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  {canCreate && (
                    <th scope="col" data-role="select" className="w-8 py-2.5 pr-2">
                      <input
                        type="checkbox"
                        checked={allOnPage}
                        aria-label={t('catalog.selectAll')}
                        onChange={(event) => setSelected((current) => (event.target.checked ? [...current, ...rows.filter((row) => !current.some((item) => item.id === row.id))] : current.filter((item) => !rows.some((row) => row.id === item.id))))}
                        className="h-5 w-5 rounded border-slate-300 accent-brand-700"
                      />
                    </th>
                  )}
                  <th scope="col" className="py-2.5 pr-4">{t('catalog.columns.sku')}</th>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('catalog.columns.product')}</th>
                  {showBranch && <th scope="col" className="py-2.5 pr-4">{t('catalog.columns.branch')}</th>}
                  <th scope="col" className="py-2.5 pr-4">{t('catalog.columns.unit')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('catalog.columns.priceCash')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('catalog.columns.priceCredit')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('catalog.columns.status')}</th>
                  <th scope="col" data-role="actions" className="py-2.5 text-right"><span className="sr-only">{t('backup.list.actions')}</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((product) => {
                  const base = product.units.find((u) => u.is_base)
                  const others = product.units.length - 1

                  return (
                    <tr key={product.id}>
                      {canCreate && (
                        <td className="py-3 pr-2">
                          <input type="checkbox" checked={isSelected(product)} onChange={() => toggle(product)} aria-label={t('catalog.select', { name: product.name })} className="h-5 w-5 rounded border-slate-300 accent-brand-700" />
                        </td>
                      )}
                      <td className="py-3 pr-4 font-mono text-xs whitespace-nowrap text-slate-600">{product.sku}</td>
                      <td className="py-3 pr-4">
                        <Link {...link('product', product.id)} className="font-medium text-slate-900 hover:text-brand-700 hover:underline">{product.name}</Link>
                        <span className="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-500">
                          {subtitleOf(product).map((part, index) => (
                            <Fragment key={part}>
                              {index > 0 && <span aria-hidden="true">·</span>}
                              <span>{part}</span>
                            </Fragment>
                          ))}
                          {product.track_batch && <Badge tone="warning">{t('catalog.batch')}</Badge>}
                        </span>
                      </td>
                      {showBranch && <td className="py-3 pr-4">{product.branch.code}</td>}
                      <td className="py-3 pr-4">
                        {product.base_unit.name}
                        {others > 0 && <span className="block text-xs text-slate-500">{t('catalog.moreUnits', { count: others })}</span>}
                      </td>
                      <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums">{base ? money(base.price_cash) : '—'}</td>
                      <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums">{base ? money(base.price_credit) : '—'}</td>
                      <td className="py-3 pr-4">
                        <Badge tone={product.status === 'active' ? 'success' : 'neutral'} dot>{t(`catalog.status.${product.status}`)}</Badge>
                      </td>
                      <td className="py-3 text-right">
                        <div className="flex justify-end gap-1">
                          <IconLink {...link('product', product.id)} labelled label={`${canUpdate ? t('common.edit') : t('catalog.view')} ${product.name}`} text={canUpdate ? t('common.edit') : t('catalog.view')}>
                            {canUpdate ? <Pencil className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
                          </IconLink>
                          {canDelete && (
                            <IconButton labelled label={`${t('common.delete')} ${product.name}`} text={t('common.delete')} onClick={() => { setDeleteError(null); setDeleting(product) }} tone="danger">
                              <Trash2 className="h-4 w-4" aria-hidden="true" />
                            </IconButton>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </DataTable>
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
