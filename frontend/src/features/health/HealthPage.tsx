import { RefreshCw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Alert, Badge, Button, Card, Spinner, type Tone } from '../../components/ui.tsx'
import { formatDateTime } from '../../lib/format.ts'
import { useSystemHealth, type HealthStatus } from './api.ts'

const statusTone: Record<HealthStatus, Tone> = { ok: 'success', warning: 'warning', critical: 'danger', disabled: 'neutral' }

export function HealthBadge({ status }: { status: HealthStatus }) {
  const { t } = useTranslation()

  return <Badge tone={statusTone[status]}>{t(`health.status.${status}`)}</Badge>
}

/** Super Admin: sức khỏe hạ tầng và dịch vụ. Chỉ đọc; cảnh báo chủ động gửi qua thông báo và email. */
export default function HealthPage() {
  const { t } = useTranslation()
  const query = useSystemHealth()

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('health.title')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('health.intro')}</p>
        </div>
        <Button variant="secondary" onClick={() => query.refetch()} loading={query.isFetching && !query.isPending}>
          <RefreshCw className="h-4 w-4" aria-hidden="true" />
          {t('health.refresh')}
        </Button>
      </div>

      {query.isPending ? (
        <div className="flex justify-center py-10"><Spinner /></div>
      ) : query.isError ? (
        <Alert tone="danger">{t('common.loadError')}</Alert>
      ) : (
        <>
          <Card>
            <div className="flex flex-wrap items-center gap-3">
              <span className="text-sm font-medium text-slate-700">{t('health.overall')}</span>
              <HealthBadge status={query.data.status} />
              <span className="text-xs text-slate-500">{t('health.checkedAt', { time: formatDateTime(query.data.checked_at) })}</span>
            </div>
          </Card>

          <div className="grid gap-4 lg:grid-cols-2">
            {query.data.components.map((component) => (
              <Card key={component.key} title={<span className="flex items-center gap-2">{component.label} <HealthBadge status={component.status} /></span>}>
                <section aria-label={component.label}>
                  {component.status === 'ok' || component.status === 'disabled' ? (
                    <p className="text-sm text-slate-700">{component.summary}</p>
                  ) : (
                    <Alert tone={component.status === 'critical' ? 'danger' : 'warning'}>{component.summary}</Alert>
                  )}
                  {component.details.length > 0 && (
                    <dl className="mt-3 divide-y divide-slate-100 text-sm">
                      {component.details.map((detail) => (
                        <div key={detail.label} className="flex flex-col gap-0.5 py-1.5 sm:flex-row sm:gap-4">
                          <dt className="w-44 shrink-0 text-slate-500">{detail.label}</dt>
                          <dd className="break-words text-slate-900">{detail.value}</dd>
                        </div>
                      ))}
                    </dl>
                  )}
                </section>
              </Card>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
