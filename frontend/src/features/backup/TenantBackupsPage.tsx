import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/auth-context.ts'
import { TenantBackupsPanel } from './TenantBackupsPanel.tsx'

/** Chủ doanh nghiệp: danh sách theo ngày giờ, sao lưu ngay, tải về (Export), tải file lên (Import) và khôi phục. */
export default function TenantBackupsPage() {
  const { t } = useTranslation()
  const { user, can } = useAuth()

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('backup.tenant.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('backup.tenant.intro')}</p>
      </div>

      <TenantBackupsPanel
        source="tenant"
        tenantName={user?.tenant?.name ?? ''}
        canCreate={can('backup.backup.create')}
        canExport={can('backup.backup.export')}
        canRestore={can('backup.backup.restore')}
      />
    </div>
  )
}
