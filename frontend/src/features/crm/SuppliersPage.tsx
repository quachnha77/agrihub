import { Eye, Pencil, Plus, Trash2 } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation } from 'react-router'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Button, Card, ConfirmDialog, Pagination, SelectField } from '../../components/ui.tsx'
import { describeError } from '../../lib/forms.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useDeleteSupplier, useSuppliers, type Supplier, type SupplierFilters } from './api.ts'

const linkButton = 'inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm font-medium'

/** Nhà cung cấp dùng chung cả doanh nghiệp (không gắn chi nhánh): mọi chi nhánh nhập hàng từ cùng một danh sách. */
export default function SuppliersPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const location = useLocation()
  const saved = (location.state as { saved?: 'created' | 'updated'; name?: string } | null) ?? null

  const [filters, setFilters] = useState<SupplierFilters>({ q: '', status: '', page: 1 })
  const search = useDeferredValue(filters.q)
  const suppliers = useSuppliers({ ...filters, q: search })
  const remove = useDeleteSupplier()

  const [deleting, setDeleting] = useState<Supplier | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const [dismissed, setDismissed] = useState(false)

  const canCreate = can('purchase.supplier.create')
  const canUpdate = can('purchase.supplier.update')
  const canDelete = can('purchase.supplier.delete')
  const rows = suppliers.data?.data ?? []
  const set = <K extends keyof SupplierFilters>(key: K, value: SupplierFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))

  async function confirmDelete() {
    if (!deleting) return
    setDeleteError(null)

    try {
      await remove.mutateAsync(deleting.id)
      setDeleting(null)
    } catch (error) {
      setDeleteError(describeError(error, t))
    }
  }

  useClampPage(suppliers.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('crm.suppliers.title')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('crm.suppliers.intro')}</p>
        </div>
        {canCreate && (
          <Link to="/suppliers/new" className={`${linkButton} border-transparent bg-emerald-700 text-white hover:bg-emerald-800`}>
            <Plus className="h-4 w-4" aria-hidden="true" />
            {t('crm.suppliers.add')}
          </Link>
        )}
      </div>

      {saved?.saved && !dismissed && (
        <Alert tone="success">
          <span className="flex flex-wrap items-center justify-between gap-2">
            {t(saved.saved === 'created' ? 'crm.suppliers.savedCreated' : 'crm.suppliers.savedUpdated', { name: saved.name })}
            <Button variant="ghost" onClick={() => setDismissed(true)}>{t('common.close')}</Button>
          </span>
        </Alert>
      )}

      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-3">
          <div className="sm:col-span-2">
            <label htmlFor="supplier-search" className="mb-1 block text-sm font-medium text-slate-800">{t('common.search')}</label>
            <input
              id="supplier-search"
              type="search"
              value={filters.q}
              onChange={(event) => set('q', event.target.value)}
              placeholder={t('crm.suppliers.searchPlaceholder')}
              className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200"
            />
          </div>
          <SelectField label={t('crm.filters.status')} value={filters.status} onChange={(event) => set('status', event.target.value)}>
            <option value="">{t('crm.filters.all')}</option>
            <option value="active">{t('crm.suppliers.status.active')}</option>
            <option value="inactive">{t('crm.suppliers.status.inactive')}</option>
          </SelectField>
        </div>

        {suppliers.isPending ? (
          <TableSkeleton columns={6} actions minWidth="44rem" />
        ) : suppliers.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('crm.suppliers.empty')}</p>
        ) : (
          <Refreshable refreshing={suppliers.isPlaceholderData}>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[44rem] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="py-2 pr-3">{t('crm.columns.code')}</th>
                    <th scope="col" className="py-2 pr-3">{t('crm.columns.supplier')}</th>
                    <th scope="col" className="py-2 pr-3">{t('crm.columns.phone')}</th>
                    <th scope="col" className="py-2 pr-3">{t('crm.columns.terms')}</th>
                    <th scope="col" className="py-2 pr-3">{t('crm.columns.status')}</th>
                    <th scope="col" className="py-2 text-right">{t('backup.list.actions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.map((supplier) => (
                    <tr key={supplier.id}>
                      <td className="py-2 pr-3 font-mono text-xs whitespace-nowrap">{supplier.code}</td>
                      <td className="py-2 pr-3">
                        <span className="font-medium text-slate-900">{supplier.name}</span>
                        {supplier.tax_code && <span className="block text-xs text-slate-500">{t('crm.suppliers.taxCodeLine', { code: supplier.tax_code })}</span>}
                      </td>
                      <td className="py-2 pr-3 whitespace-nowrap">{supplier.phone ?? '—'}</td>
                      <td className="py-2 pr-3 whitespace-nowrap">{supplier.payment_terms_days === null ? '—' : t('crm.suppliers.termsDays', { count: supplier.payment_terms_days })}</td>
                      <td className="py-2 pr-3">
                        <Badge tone={supplier.status === 'active' ? 'success' : 'neutral'}>{t(`crm.suppliers.status.${supplier.status}`)}</Badge>
                      </td>
                      <td className="py-2 text-right">
                        <div className="flex justify-end gap-1">
                          <Link to={`/suppliers/${supplier.id}`} aria-label={`${canUpdate ? t('common.edit') : t('crm.view')} ${supplier.name}`} className="rounded-md px-3 py-2 text-slate-700 hover:bg-slate-100">
                            {canUpdate ? <Pencil className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
                          </Link>
                          {canDelete && (
                            <Button variant="ghost" onClick={() => { setDeleteError(null); setDeleting(supplier) }} aria-label={`${t('common.delete')} ${supplier.name}`}>
                              <Trash2 className="h-4 w-4 text-red-700" aria-hidden="true" />
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={suppliers.data.meta.current_page} lastPage={suppliers.data.meta.last_page} total={suppliers.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </Card>

      {deleting && (
        <ConfirmDialog title={t('crm.suppliers.deleteTitle')} confirmLabel={t('common.delete')} danger loading={remove.isPending} error={deleteError} onConfirm={confirmDelete} onClose={() => setDeleting(null)}>
          {t('crm.suppliers.deleteText', { name: deleting.name, code: deleting.code })}
        </ConfirmDialog>
      )}
    </div>
  )
}
