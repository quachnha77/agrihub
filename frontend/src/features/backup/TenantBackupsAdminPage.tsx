import { ArrowLeft } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { Alert, Spinner } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { useTenant } from '../platform/api.ts'
import { TenantBackupsPanel } from './TenantBackupsPanel.tsx'

/** Super Admin: sao lưu, Import và Khôi phục dữ liệu của MỘT doanh nghiệp (cùng quy tắc an toàn như Chủ doanh nghiệp). */
export default function TenantBackupsAdminPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const tenant = useTenant(id)

  if (tenant.isPending) return <div className="flex justify-center py-10"><Spinner /></div>

  if (tenant.isError) {
    return (
      <div className="space-y-3">
        <Alert tone="danger">{tenant.error instanceof ApiError && tenant.error.status === 404 ? t('platform.tenants.notFound') : t('common.loadError')}</Alert>
        <Link to="/platform/tenants" className="text-sm font-medium text-emerald-700 hover:underline">{t('platform.tenants.back')}</Link>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <Link to={`/platform/tenants/${id}`} className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t('backup.admin.back', { name: tenant.data.name })}
      </Link>

      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('backup.admin.title', { name: tenant.data.name })}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('backup.admin.intro')}</p>
      </div>

      <TenantBackupsPanel source={{ tenantId: id }} tenantName={tenant.data.name} canCreate canExport canRestore />
    </div>
  )
}
