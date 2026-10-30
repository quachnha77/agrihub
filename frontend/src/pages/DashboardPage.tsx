import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Alert, Card } from '../components/ui.tsx'
import { useAuth } from '../features/auth/auth-context.ts'
import { PlatformStatusSummary } from '../features/backup/StatusCard.tsx'
import { HealthSummary } from '../features/health/HealthSummary.tsx'
import { useLatestNotices } from '../features/notifications/api.ts'

/** Thông báo QUAN TRỌNG chưa đọc hiện ngay khi đăng nhập (kênh "in-app" đã chốt). */
function CriticalNotices() {
  const { t } = useTranslation()
  const query = useLatestNotices({ unreadOnly: true, perPage: 20 })
  const critical = query.data?.data.filter((notice) => notice.level === 'critical') ?? []

  if (critical.length === 0) return null

  return (
    <Alert tone="danger" title={t('notifications.criticalTitle')}>
      <ul className="list-disc space-y-1 pl-5">
        {critical.map((notice) => (
          <li key={notice.id}>
            <span className="font-medium">{notice.title}</span>
            {notice.body && <span className="block whitespace-pre-line">{notice.body}</span>}
          </li>
        ))}
      </ul>
      <Link to="/notifications" className="mt-2 inline-block font-medium underline">{t('notifications.viewAll')}</Link>
    </Alert>
  )
}

export default function DashboardPage() {
  const { t } = useTranslation()
  const { user } = useAuth()

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('dashboard.welcome', { name: user?.name })}</h1>
        <p className="mt-1 text-sm text-slate-600">
          {user?.tenant ? t('dashboard.tenantLine', { tenant: user.tenant.name }) : t('dashboard.platformLine')}
        </p>
      </div>

      <CriticalNotices />

      {user?.is_platform_admin && (
        <>
          <HealthSummary />
          <PlatformStatusSummary />
        </>
      )}

      <Card>
        <p className="text-sm text-slate-600">{t('dashboard.comingSoon')}</p>
      </Card>
    </div>
  )
}
