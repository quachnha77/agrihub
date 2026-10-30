import { Plus } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Alert, Badge, Button, Card, Pagination, Spinner } from '../../components/ui.tsx'
import { formatDate } from '../../lib/format.ts'
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

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('platform.tenants.title')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('platform.tenants.intro')}</p>
        </div>
        <Button onClick={() => setCreating(true)}>
          <Plus className="h-4 w-4" aria-hidden="true" />
          {t('platform.tenants.add')}
        </Button>
      </div>

      {notice && <Alert tone="success">{notice}</Alert>}

      <Card>
        <div className="mb-4 flex flex-wrap gap-2">
          <input
            type="search"
            value={q}
            onChange={(event) => {
              setQ(event.target.value)
              setPage(1)
            }}
            aria-label={t('common.search')}
            placeholder={t('platform.tenants.searchPlaceholder')}
            className="block w-full max-w-sm rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
          <select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
            aria-label={t('platform.tenants.statusFilter')}
            className="rounded-md border border-slate-300 px-3 py-2 text-sm"
          >
            <option value="">{t('platform.tenants.allStatuses')}</option>
            <option value="active">{t('platform.tenants.status.active')}</option>
            <option value="suspended">{t('platform.tenants.status.suspended')}</option>
          </select>
        </div>

        {tenants.isPending ? (
          <div className="flex justify-center py-6"><Spinner /></div>
        ) : tenants.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : tenants.data.data.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('platform.tenants.empty')}</p>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[56rem] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="py-2 pr-3">{t('platform.tenants.business')}</th>
                    <th scope="col" className="py-2 pr-3">{t('platform.tenants.owner')}</th>
                    <th scope="col" className="py-2 pr-3">{t('platform.tenants.subscription')}</th>
                    <th scope="col" className="py-2 pr-3">{t('platform.tenants.usage')}</th>
                    <th scope="col" className="py-2 pr-3">{t('users.status')}</th>
                    <th scope="col" className="py-2">{t('platform.tenants.createdAt')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {tenants.data.data.map((tenant) => {
                    const owner = tenant.owners?.[0]
                    const subscription = tenant.subscription

                    return (
                      <tr key={tenant.id}>
                        <td className="py-2 pr-3">
                          <Link to={`/platform/tenants/${tenant.id}`} className="font-medium text-emerald-800 hover:underline">{tenant.name}</Link>
                          <span className="block text-xs text-slate-500">{tenant.url ?? tenant.slug}</span>
                        </td>
                        <td className="py-2 pr-3">
                          {owner ? (
                            <>
                              <span className="block">{owner.name}</span>
                              <span className="block text-xs text-slate-500">{owner.email}</span>
                            </>
                          ) : (
                            '—'
                          )}
                        </td>
                        <td className="py-2 pr-3">
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
                        <td className="py-2 pr-3 whitespace-nowrap">{t('platform.tenants.usageValue', { users: tenant.users_count ?? 0, branches: tenant.branches_count ?? 0 })}</td>
                        <td className="py-2 pr-3">
                          <Badge tone={tenant.status === 'active' ? 'success' : 'danger'}>{t(`platform.tenants.status.${tenant.status}`)}</Badge>
                        </td>
                        <td className="py-2 whitespace-nowrap">{formatDate(tenant.created_at)}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <Pagination page={tenants.data.meta.current_page} lastPage={tenants.data.meta.last_page} total={tenants.data.meta.total} onPage={setPage} />
          </>
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
