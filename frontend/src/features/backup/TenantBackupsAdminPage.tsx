import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { PageHeader } from '../../components/page.tsx'
import { DetailPageSkeleton } from '../../components/skeleton.tsx'
import { Alert } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { useTenant } from '../platform/api.ts'
import { TenantBackupsPanel } from './TenantBackupsPanel.tsx'

/** Super Admin: sao lưu, Import và Khôi phục dữ liệu của MỘT doanh nghiệp (cùng quy tắc an toàn như Chủ doanh nghiệp). */
export default function TenantBackupsAdminPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const tenant = useTenant(id)

  if (tenant.isPending) return <DetailPageSkeleton />

  if (tenant.isError) {
    return (
      <div className="space-y-3">
        <Alert tone="danger">{tenant.error instanceof ApiError && tenant.error.status === 404 ? t('platform.tenants.notFound') : t('common.loadError')}</Alert>
        <Link to="/platform/tenants" className="text-sm font-medium text-brand-700 hover:underline">{t('platform.tenants.back')}</Link>
      </div>
    )
  }

  return (
    <div className="space-y-5">
      <PageHeader
        back={{ to: `/platform/tenants/${id}`, label: t('backup.admin.back', { name: tenant.data.name }) }}
        title={t('backup.admin.title', { name: tenant.data.name })}
        description={t('backup.admin.intro')}
      />

      <TenantBackupsPanel source={{ tenantId: id }} tenantName={tenant.data.name} />
    </div>
  )
}
