import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Alert, Card, Spinner } from '../../components/ui.tsx'
import { useSystemHealth } from './api.ts'
import { HealthBadge } from './HealthPage.tsx'

/** Bản rút gọn cho trang Tổng quan của Super Admin: tình trạng chung và các thành phần đang có vấn đề. */
export function HealthSummary() {
  const { t } = useTranslation()
  const query = useSystemHealth()

  return (
    <Card title={t('dashboard.healthStatus')} actions={<Link to="/platform/health" className="text-sm font-medium text-emerald-700 hover:underline">{t('dashboard.viewDetails')}</Link>}>
      {query.isPending ? (
        <Spinner />
      ) : query.isError ? (
        <Alert tone="danger">{t('common.loadError')}</Alert>
      ) : (
        <div className="space-y-2">
          <HealthBadge status={query.data.status} />
          {query.data.components
            .filter((component) => component.status === 'warning' || component.status === 'critical')
            .map((component) => (
              <Alert key={component.key} tone={component.status === 'critical' ? 'danger' : 'warning'} title={component.label}>
                {component.summary}
              </Alert>
            ))}
        </div>
      )}
    </Card>
  )
}
