import { Contact, Eye, Pencil, Plus, Trash2 } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, FilterBar, FilterSelect, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Badge, Button, ButtonLink, Card, ConfirmDialog, IconButton, IconLink, Pagination } from '../../components/ui.tsx'
import { useDialog } from '../../lib/dialog.ts'
import { countActiveFilters } from '../../lib/filters.ts'
import { formatVnd } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useAllBranches } from '../branches/api.ts'
import { useCatalogList } from '../catalog/api.ts'
import { CUSTOMER_TYPES, useCustomers, useDeleteCustomer, type Customer, type CustomerFilters } from './api.ts'
import { CustomerTabs } from './CustomerTabs.tsx'

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

const EMPTY_FILTERS: CustomerFilters = { q: '', type: '', status: '', branch: '', group: '', page: 1 }

/** Khách hàng dùng chung cả doanh nghiệp; mỗi khách có một chi nhánh phụ trách (người chỉ có quyền chi nhánh chỉ thấy khách của chi nhánh mình). */
export default function CustomersPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { link } = useDialog()

  const [filters, setFilters] = useState<CustomerFilters>(EMPTY_FILTERS)
  const search = useDeferredValue(filters.q)
  const customers = useCustomers({ ...filters, q: search })
  const branches = useAllBranches()
  const groups = useCatalogList('customer-groups')
  const remove = useDeleteCustomer()

  const [deleting, setDeleting] = useState<Customer | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  const canCreate = can('crm.customer.create')
  const canUpdate = can('crm.customer.update')
  const canDelete = can('crm.customer.delete')
  const rows = customers.data?.data ?? []
  const showBranch = (branches.data?.length ?? 0) > 1
  // Ô lọc và bảng chờ dữ liệu tham chiếu (chi nhánh/kho) rồi hiện cùng lúc, để ô lọc/cột "Chi nhánh" thêm vào muộn không làm trang dịch.
  const loading = customers.isPending || branches.isLoading
  const activeFilters = countActiveFilters(filters)
  const filtered = activeFilters > 0 || filters.q !== ''
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

  useClampPage(customers.data?.meta, (page) => set('page', page))

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('crm.customers.title')}
        description={t('crm.customers.intro')}
        tabs={<CustomerTabs />}
        actions={
          canCreate && (
            <ButtonLink {...link('customer')}>
              <Plus className="h-4 w-4" aria-hidden="true" />
              {t('crm.customers.add')}
            </ButtonLink>
          )
        }
      />

      <Card>
        <FilterBar
          loading={loading}
          selects={4}
          activeCount={activeFilters}
          onClear={() => setFilters(EMPTY_FILTERS)}
          search={<SearchField label={t('common.search')} value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder={t('crm.customers.searchPlaceholder')} />}
        >
          <FilterSelect label={t('crm.filters.type')} value={filters.type} onChange={(event) => set('type', event.target.value)}>
            <option value="">{t('crm.filters.all')}</option>
            {CUSTOMER_TYPES.map((type) => <option key={type} value={type}>{t(`crm.types.${type}`)}</option>)}
          </FilterSelect>
          <FilterSelect label={t('crm.filters.group')} value={filters.group} onChange={(event) => set('group', event.target.value)}>
            <option value="">{t('crm.filters.all')}</option>
            {(groups.data ?? []).map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
          </FilterSelect>
          {showBranch && (
            <FilterSelect label={t('crm.filters.branch')} value={filters.branch} onChange={(event) => set('branch', event.target.value)}>
              <option value="">{t('crm.filters.all')}</option>
              {(branches.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
            </FilterSelect>
          )}
          <FilterSelect label={t('crm.filters.status')} value={filters.status} onChange={(event) => set('status', event.target.value)}>
            <option value="">{t('crm.filters.all')}</option>
            <option value="active">{t('crm.status.active')}</option>
            <option value="inactive">{t('crm.status.inactive')}</option>
          </FilterSelect>
        </FilterBar>

        {loading ? (
          <TableSkeleton columns={showBranch ? 7 : 6} actions minWidth="52rem" />
        ) : customers.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={Contact}
            title={t('crm.customers.empty')}
            description={filtered ? t('common.tryOtherFilters') : undefined}
            action={filtered ? <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>{t('common.clearFilters')}</Button> : canCreate ? <ButtonLink {...link('customer')}>{t('crm.customers.add')}</ButtonLink> : undefined}
          />
        ) : (
          <Refreshable refreshing={customers.isPlaceholderData}>
            <DataTable minWidth="52rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" className="py-2.5 pr-4">{t('crm.columns.code')}</th>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('crm.columns.customer')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('crm.columns.phone')}</th>
                  {showBranch && <th scope="col" className="py-2.5 pr-4">{t('crm.columns.branch')}</th>}
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('crm.columns.credit')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('crm.columns.status')}</th>
                  <th scope="col" data-role="actions" className="py-2.5 text-right"><span className="sr-only">{t('backup.list.actions')}</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((customer) => (
                  <tr key={customer.id}>
                    <td className="py-3 pr-4 font-mono text-xs whitespace-nowrap text-slate-600">{customer.code}</td>
                    <td className="py-3 pr-4">
                      <span className="font-medium text-slate-900">{customer.name}</span>
                      <span className="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-500">
                        <span>{t(`crm.types.${customer.type}`)}</span>
                        {customer.group?.name && (
                          <>
                            <span aria-hidden="true">·</span>
                            <span>{customer.group.name}</span>
                          </>
                        )}
                      </span>
                    </td>
                    <td className="py-3 pr-4 whitespace-nowrap">{customer.phone ?? '—'}</td>
                    {showBranch && <td className="py-3 pr-4">{customer.branch.code}</td>}
                    <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums"><Credit customer={customer} /></td>
                    <td className="py-3 pr-4">
                      <Badge tone={customer.status === 'active' ? 'success' : 'neutral'} dot>{t(`crm.status.${customer.status}`)}</Badge>
                    </td>
                    <td className="py-3 text-right">
                      <div className="flex justify-end gap-1">
                        <IconLink {...link('customer', customer.id)} label={`${canUpdate ? t('common.edit') : t('crm.view')} ${customer.name}`} text={canUpdate ? t('common.edit') : t('crm.view')}>
                          {canUpdate ? <Pencil className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
                        </IconLink>
                        {canDelete && (
                          <IconButton label={`${t('common.delete')} ${customer.name}`} text={t('common.delete')} onClick={() => { setDeleteError(null); setDeleting(customer) }} tone="danger">
                            <Trash2 className="h-4 w-4" aria-hidden="true" />
                          </IconButton>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
            <Pagination page={customers.data.meta.current_page} lastPage={customers.data.meta.last_page} total={customers.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
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
