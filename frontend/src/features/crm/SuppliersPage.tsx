import { Eye, Pencil, Plus, Trash2, Truck } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation } from 'react-router'
import { EmptyState, FilterBar, FilterSelect, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Badge, Button, ButtonLink, Card, ConfirmDialog, IconButton, IconLink, Pagination } from '../../components/ui.tsx'
import { countActiveFilters } from '../../lib/filters.ts'
import { describeError } from '../../lib/forms.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useDeleteSupplier, useSuppliers, type Supplier, type SupplierFilters } from './api.ts'

const EMPTY_FILTERS: SupplierFilters = { q: '', status: '', page: 1 }

/** Nhà cung cấp dùng chung cả doanh nghiệp (không gắn chi nhánh): mọi chi nhánh nhập hàng từ cùng một danh sách. */
export default function SuppliersPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const location = useLocation()
  const saved = (location.state as { saved?: 'created' | 'updated'; name?: string } | null) ?? null

  const [filters, setFilters] = useState<SupplierFilters>(EMPTY_FILTERS)
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
  const activeFilters = countActiveFilters(filters)
  const filtered = activeFilters > 0 || filters.q !== ''
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
    <div className="space-y-5">
      <PageHeader
        title={t('crm.suppliers.title')}
        description={t('crm.suppliers.intro')}
        actions={
          canCreate && (
            <ButtonLink to="/suppliers/new">
              <Plus className="h-4 w-4" aria-hidden="true" />
              {t('crm.suppliers.add')}
            </ButtonLink>
          )
        }
      />

      {saved?.saved && !dismissed && (
        <Alert tone="success">
          <span className="flex flex-wrap items-center justify-between gap-2">
            {t(saved.saved === 'created' ? 'crm.suppliers.savedCreated' : 'crm.suppliers.savedUpdated', { name: saved.name })}
            <Button variant="ghost" onClick={() => setDismissed(true)}>{t('common.close')}</Button>
          </span>
        </Alert>
      )}

      <Card>
        <FilterBar
          activeCount={activeFilters}
          onClear={() => setFilters(EMPTY_FILTERS)}
          search={<SearchField label={t('common.search')} value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder={t('crm.suppliers.searchPlaceholder')} />}
        >
          <FilterSelect label={t('crm.filters.status')} value={filters.status} onChange={(event) => set('status', event.target.value)}>
            <option value="">{t('crm.filters.all')}</option>
            <option value="active">{t('crm.suppliers.status.active')}</option>
            <option value="inactive">{t('crm.suppliers.status.inactive')}</option>
          </FilterSelect>
        </FilterBar>

        {suppliers.isPending ? (
          <TableSkeleton columns={6} actions minWidth="44rem" />
        ) : suppliers.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={Truck}
            title={t('crm.suppliers.empty')}
            description={filtered ? t('common.tryOtherFilters') : undefined}
            action={filtered ? <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>{t('common.clearFilters')}</Button> : canCreate ? <ButtonLink to="/suppliers/new">{t('crm.suppliers.add')}</ButtonLink> : undefined}
          />
        ) : (
          <Refreshable refreshing={suppliers.isPlaceholderData}>
            <DataTable minWidth="44rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" className="py-2.5 pr-4">{t('crm.columns.code')}</th>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('crm.columns.supplier')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('crm.columns.phone')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('crm.columns.terms')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('crm.columns.status')}</th>
                  <th scope="col" data-role="actions" className="py-2.5 text-right"><span className="sr-only">{t('backup.list.actions')}</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((supplier) => (
                  <tr key={supplier.id}>
                    <td className="py-3 pr-4 font-mono text-xs whitespace-nowrap text-slate-600">{supplier.code}</td>
                    <td className="py-3 pr-4">
                      <span className="font-medium text-slate-900">{supplier.name}</span>
                      {supplier.tax_code && <span className="block text-xs text-slate-500">{t('crm.suppliers.taxCodeLine', { code: supplier.tax_code })}</span>}
                    </td>
                    <td className="py-3 pr-4 whitespace-nowrap">{supplier.phone ?? '—'}</td>
                    <td className="py-3 pr-4 whitespace-nowrap">{supplier.payment_terms_days === null ? '—' : t('crm.suppliers.termsDays', { count: supplier.payment_terms_days })}</td>
                    <td className="py-3 pr-4">
                      <Badge tone={supplier.status === 'active' ? 'success' : 'neutral'} dot>{t(`crm.suppliers.status.${supplier.status}`)}</Badge>
                    </td>
                    <td className="py-3 text-right">
                      <div className="flex justify-end gap-1">
                        <IconLink to={`/suppliers/${supplier.id}`} label={`${canUpdate ? t('common.edit') : t('crm.view')} ${supplier.name}`} text={canUpdate ? t('common.edit') : t('crm.view')}>
                          {canUpdate ? <Pencil className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
                        </IconLink>
                        {canDelete && (
                          <IconButton label={`${t('common.delete')} ${supplier.name}`} text={t('common.delete')} onClick={() => { setDeleteError(null); setDeleting(supplier) }} tone="danger">
                            <Trash2 className="h-4 w-4" aria-hidden="true" />
                          </IconButton>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
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
