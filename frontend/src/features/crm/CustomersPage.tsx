import { Eye, Pencil, Plus, Trash2, Users } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation } from 'react-router'
import { Alert, Badge, Button, Card, ConfirmDialog, Pagination, SelectField, Spinner } from '../../components/ui.tsx'
import { formatVnd } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useAllBranches } from '../branches/api.ts'
import { useCatalogList } from '../catalog/api.ts'
import { CUSTOMER_TYPES, useCustomers, useDeleteCustomer, type Customer, type CustomerFilters } from './api.ts'

const linkButton = 'inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm font-medium'

/** Hạn mức công nợ ở dạng đọc nhanh: "Không ghi nợ" khi hạn mức 0, ngược lại số tiền kèm số ngày nợ tối đa nếu có. */
function Credit({ customer }: { customer: Customer }) {
  const { t } = useTranslation()
  const limit = Number(customer.credit_limit)

  if (limit <= 0) return <span className="text-slate-500">{t('crm.customers.noCredit')}</span>

  return (
    <>
      {formatVnd(limit)}
      {customer.credit_days !== null && <span className="block text-xs text-slate-500">{t('crm.customers.creditDays', { count: customer.credit_days })}</span>}
    </>
  )
}

/** Khách hàng dùng chung cả doanh nghiệp; mỗi khách có một chi nhánh phụ trách (người chỉ có quyền chi nhánh chỉ thấy khách của chi nhánh mình). */
export default function CustomersPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const location = useLocation()
  const saved = (location.state as { saved?: 'created' | 'updated'; name?: string } | null) ?? null

  const [filters, setFilters] = useState<CustomerFilters>({ q: '', type: '', status: '', branch: '', group: '', page: 1 })
  const search = useDeferredValue(filters.q)
  const customers = useCustomers({ ...filters, q: search })
  const branches = useAllBranches()
  const groups = useCatalogList('customer-groups')
  const remove = useDeleteCustomer()

  const [deleting, setDeleting] = useState<Customer | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const [dismissed, setDismissed] = useState(false)

  const canCreate = can('crm.customer.create')
  const canUpdate = can('crm.customer.update')
  const canDelete = can('crm.customer.delete')
  const rows = customers.data?.data ?? []
  const showBranch = (branches.data?.length ?? 0) > 1
  const set = <K extends keyof CustomerFilters>(key: K, value: CustomerFilters[K]) => setFilters((current) => ({ ...current, [key]: value, page: key === 'page' ? (value as number) : 1 }))

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

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('crm.customers.title')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('crm.customers.intro')}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/customers/groups" className={`${linkButton} border-slate-300 bg-white text-slate-800 hover:bg-slate-50`}>
            <Users className="h-4 w-4" aria-hidden="true" />
            {t('crm.customers.manageGroups')}
          </Link>
          {canCreate && (
            <Link to="/customers/new" className={`${linkButton} border-transparent bg-emerald-700 text-white hover:bg-emerald-800`}>
              <Plus className="h-4 w-4" aria-hidden="true" />
              {t('crm.customers.add')}
            </Link>
          )}
        </div>
      </div>

      {saved?.saved && !dismissed && (
        <Alert tone="success">
          <span className="flex flex-wrap items-center justify-between gap-2">
            {t(saved.saved === 'created' ? 'crm.customers.savedCreated' : 'crm.customers.savedUpdated', { name: saved.name })}
            <Button variant="ghost" onClick={() => setDismissed(true)}>{t('common.close')}</Button>
          </span>
        </Alert>
      )}

      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
          <div className="lg:col-span-2">
            <label htmlFor="customer-search" className="mb-1 block text-sm font-medium text-slate-800">{t('common.search')}</label>
            <input
              id="customer-search"
              type="search"
              value={filters.q}
              onChange={(event) => set('q', event.target.value)}
              placeholder={t('crm.customers.searchPlaceholder')}
              className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200"
            />
          </div>
          <SelectField label={t('crm.filters.type')} value={filters.type} onChange={(event) => set('type', event.target.value)}>
            <option value="">{t('crm.filters.all')}</option>
            {CUSTOMER_TYPES.map((type) => <option key={type} value={type}>{t(`crm.types.${type}`)}</option>)}
          </SelectField>
          <SelectField label={t('crm.filters.group')} value={filters.group} onChange={(event) => set('group', event.target.value)}>
            <option value="">{t('crm.filters.all')}</option>
            {(groups.data ?? []).map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
          </SelectField>
          {showBranch && (
            <SelectField label={t('crm.filters.branch')} value={filters.branch} onChange={(event) => set('branch', event.target.value)}>
              <option value="">{t('crm.filters.all')}</option>
              {(branches.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
            </SelectField>
          )}
          <SelectField label={t('crm.filters.status')} value={filters.status} onChange={(event) => set('status', event.target.value)}>
            <option value="">{t('crm.filters.all')}</option>
            <option value="active">{t('crm.status.active')}</option>
            <option value="inactive">{t('crm.status.inactive')}</option>
          </SelectField>
        </div>

        {customers.isPending ? (
          <div className="flex justify-center py-6"><Spinner /></div>
        ) : customers.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('crm.customers.empty')}</p>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[52rem] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="py-2 pr-3">{t('crm.columns.code')}</th>
                    <th scope="col" className="py-2 pr-3">{t('crm.columns.customer')}</th>
                    <th scope="col" className="py-2 pr-3">{t('crm.columns.phone')}</th>
                    {showBranch && <th scope="col" className="py-2 pr-3">{t('crm.columns.branch')}</th>}
                    <th scope="col" className="py-2 pr-3 text-right">{t('crm.columns.credit')}</th>
                    <th scope="col" className="py-2 pr-3">{t('crm.columns.status')}</th>
                    <th scope="col" className="py-2 text-right">{t('backup.list.actions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.map((customer) => (
                    <tr key={customer.id}>
                      <td className="py-2 pr-3 font-mono text-xs whitespace-nowrap">{customer.code}</td>
                      <td className="py-2 pr-3">
                        <span className="font-medium text-slate-900">{customer.name}</span>
                        <span className="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-slate-500">
                          <Badge tone="info">{t(`crm.types.${customer.type}`)}</Badge>
                          {customer.group?.name}
                        </span>
                      </td>
                      <td className="py-2 pr-3 whitespace-nowrap">{customer.phone ?? '—'}</td>
                      {showBranch && <td className="py-2 pr-3">{customer.branch.code}</td>}
                      <td className="py-2 pr-3 text-right whitespace-nowrap"><Credit customer={customer} /></td>
                      <td className="py-2 pr-3">
                        <Badge tone={customer.status === 'active' ? 'success' : 'neutral'}>{t(`crm.status.${customer.status}`)}</Badge>
                      </td>
                      <td className="py-2 text-right">
                        <div className="flex justify-end gap-1">
                          <Link to={`/customers/${customer.id}`} aria-label={`${canUpdate ? t('common.edit') : t('crm.view')} ${customer.name}`} className="rounded-md px-3 py-2 text-slate-700 hover:bg-slate-100">
                            {canUpdate ? <Pencil className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
                          </Link>
                          {canDelete && (
                            <Button variant="ghost" onClick={() => { setDeleteError(null); setDeleting(customer) }} aria-label={`${t('common.delete')} ${customer.name}`}>
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
            <Pagination page={customers.data.meta.current_page} lastPage={customers.data.meta.last_page} total={customers.data.meta.total} onPage={(page) => set('page', page)} />
          </>
        )}
      </Card>

      {deleting && (
        <ConfirmDialog title={t('crm.customers.deleteTitle')} confirmLabel={t('common.delete')} danger loading={remove.isPending} error={deleteError} onConfirm={confirmDelete} onClose={() => setDeleting(null)}>
          {t('crm.customers.deleteText', { name: deleting.name, code: deleting.code })}
        </ConfirmDialog>
      )}
    </div>
  )
}
