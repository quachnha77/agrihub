import { useTranslation } from 'react-i18next'
import { CardGridSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Card } from '../../components/ui.tsx'
import { usePermissionCatalog, useRoles, type PermissionResource, type Role } from './api.ts'

/** Gom quyền "resource.action" theo resource: [{ nhãn resource, ["Xem (toàn doanh nghiệp)", ...] }]. */
function summarize(role: Role, catalog: PermissionResource[], t: (key: string) => string) {
  const byResource = new Map<string, string[]>()

  for (const { key, scope } of role.permissions) {
    const cut = key.lastIndexOf('.')
    const resource = key.slice(0, cut)
    const action = key.slice(cut + 1)
    const items = byResource.get(resource) ?? []
    items.push(`${t(`roles.actions.${action}`)} (${t(`roles.scopes.${scope}`)})`)
    byResource.set(resource, items)
  }

  return catalog.filter((c) => byResource.has(c.resource)).map((c) => ({ resource: c.resource, label: c.label, items: byResource.get(c.resource) ?? [] }))
}

/** Danh sách vai trò và quyền của từng vai trò (chỉ xem). Thêm/sửa vai trò tùy chỉnh làm ở bước sau. */
export default function RolesPage() {
  const { t } = useTranslation()
  const roles = useRoles()
  const catalog = usePermissionCatalog()

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('roles.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('roles.intro')}</p>
      </div>

      {roles.isPending || catalog.isPending ? (
        <CardGridSkeleton cards={4} lines={3} />
      ) : roles.isError || catalog.isError ? (
        <Alert tone="danger">{t('common.loadError')}</Alert>
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {roles.data.map((role) => (
            <Card
              key={role.id}
              title={
                <span className="flex flex-wrap items-center gap-2">
                  {role.name}
                  {role.is_system && <Badge tone="info">{t('roles.system')}</Badge>}
                </span>
              }
            >
              <section aria-label={role.name}>
                {role.description && <p className="text-sm text-slate-600">{role.description}</p>}
                <p className="mt-1 text-xs text-slate-500">{t('roles.usersCount', { count: role.users_count ?? 0 })}</p>
                <details className="mt-3">
                  <summary className="cursor-pointer text-sm font-medium text-emerald-700">{t('roles.viewPermissions', { count: role.permissions.length })}</summary>
                  <dl className="mt-2 divide-y divide-slate-100 text-sm">
                    {summarize(role, catalog.data, t).map((group) => (
                      <div key={group.resource} className="flex flex-col gap-0.5 py-1.5 sm:flex-row sm:gap-4">
                        <dt className="w-44 shrink-0 text-slate-500">{group.label}</dt>
                        <dd className="text-slate-900">{group.items.join(', ')}</dd>
                      </div>
                    ))}
                  </dl>
                </details>
              </section>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}
