import { Plus, Store } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { EmptyState, FilterBar, FilterSelect, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Badge, Button, Card, Pagination } from '../../components/ui.tsx'
import { formatDate } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
import { TemporaryPasswordDialog } from '../users/TemporaryPasswordDialog.tsx'
import { useTenants, type TenantRow } from './api.ts'
import { daysLabel } from './labels.ts'
import { SubscriptionBadge } from './SubscriptionBadge.tsx'
import { CreateTenantDialog } from './TenantDialogs.tsx'

/** Danh sách doanh nghiệp (khách hàng) của AgriHub: tìm, lọc, thêm mới (onboarding) và vào chi tiết. */
export default function TenantsPage() {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const search = useDeferredValue(q)
  const tenants = useTenants({ q: search, status, page })

  const [creating, setCreating] = useState(false)
  const [created, setCreated] = useState<{ tenant: TenantRow; password: string } | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  useClampPage(tenants.data?.meta, setPage)

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('platform.tenants.title')}
        description={t('platform.tenants.intro')}
        actions={
          <Button onClick={() => setCreating(true)}>
            <Plus className="h-4 w-4" aria-hidden="true" />
            {t('platform.tenants.add')}
          </Button>
        }
      />

      {notice && <Alert tone="success">{notice}</Alert>}

      <Card>
        <FilterBar
          activeCount={status !== '' ? 1 : 0}
          onClear={() => {
            setStatus('')
            setPage(1)
          }}
          search={
            <SearchField
              label={t('common.search')}
              value={q}
              onChange={(event) => {
                setQ(event.target.value)
                setPage(1)
              }}
              placeholder={t('platform.tenants.searchPlaceholder')}
            />
          }
        >
          <FilterSelect
            label={t('platform.tenants.statusFilter')}
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
          >
            <option value="">{t('platform.tenants.allStatuses')}</option>
            <option value="active">{t('platform.tenants.status.active')}</option>
            <option value="suspended">{t('platform.tenants.status.suspended')}</option>
          </FilterSelect>
        </FilterBar>

        {tenants.isPending ? (
          <TableSkeleton columns={6} minWidth="56rem" />
        ) : tenants.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : tenants.data.data.length === 0 ? (
          <EmptyState icon={Store} title={t('platform.tenants.empty')} description={q !== '' || status !== '' ? t('common.tryOtherFilters') : undefined} />
        ) : (
          <Refreshable refreshing={tenants.isPlaceholderData}>
            <DataTable minWidth="56rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('platform.tenants.business')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('platform.tenants.owner')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('platform.tenants.subscription')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('platform.tenants.usage')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('users.status')}</th>
                  <th scope="col" className="py-2.5">{t('platform.tenants.createdAt')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {tenants.data.data.map((tenant) => {
                  const owner = tenant.owners?.[0]
                  const subscription = tenant.subscription

                  return (
                    <tr key={tenant.id}>
                      <td className="py-3 pr-4">
                        <Link to={`/platform/tenants/${tenant.id}`} className="font-medium text-brand-800 hover:underline">{tenant.name}</Link>
                        <span className="block text-xs text-slate-500">{tenant.url ?? tenant.slug}</span>
                      </td>
                      <td className="py-3 pr-4">
                        {owner ? (
                          <>
                            <span className="block">{owner.name}</span>
                            <span className="block text-xs text-slate-500">{owner.email}</span>
                          </>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td className="py-3 pr-4">
                        {subscription ? (
                          <>
                            <SubscriptionBadge state={subscription.state} />
                            <span className="block text-xs text-slate-500">
                              {subscription.plan} · {daysLabel(subscription.days_left, t)}
                            </span>
                          </>
                        ) : (
                          <span className="text-slate-500">{t('platform.tenants.noSubscription')}</span>
                        )}
                      </td>
                      <td className="py-3 pr-4 whitespace-nowrap">{t('platform.tenants.usageValue', { users: tenant.users_count ?? 0, branches: tenant.branches_count ?? 0 })}</td>
                      <td className="py-3 pr-4">
                        <Badge tone={tenant.status === 'active' ? 'success' : 'danger'} dot>{t(`platform.tenants.status.${tenant.status}`)}</Badge>
                      </td>
                      <td className="py-3 whitespace-nowrap">{formatDate(tenant.created_at)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </DataTable>
            <Pagination page={tenants.data.meta.current_page} lastPage={tenants.data.meta.last_page} total={tenants.data.meta.total} onPage={setPage} />
          </Refreshable>
        )}
      </Card>

      {creating && <CreateTenantDialog onClose={() => setCreating(false)} onCreated={(tenant, password) => {
          setNotice(t('platform.tenants.created', { name: tenant.name }))
          if (password) setCreated({ tenant, password })
        }} />}

      {created && (
        <TemporaryPasswordDialog
          userName={created.tenant.owners?.[0]?.name ?? created.tenant.name}
          account={created.tenant.owners?.[0]?.email}
          password={created.password}
          loginUrl={created.tenant.url}
          onClose={() => setCreated(null)}
        />
      )}
    </div>
  )
}
