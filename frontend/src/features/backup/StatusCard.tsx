import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { LinesSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Card, type Tone } from '../../components/ui.tsx'
import { formatBytes, formatDateTime } from '../../lib/format.ts'
import { usePlatformBackupStatus, type PlatformBackupState } from './api.ts'

const stateTone: Record<PlatformBackupState['state'], Tone> = { ok: 'success', warning: 'warning', critical: 'danger', disabled: 'neutral' }
const WEEKDAYS = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy']

export function StateBadge({ state }: { state: PlatformBackupState['state'] }) {
  const { t } = useTranslation()

  return <Badge tone={stateTone[state]}>{t(`backup.status.state.${state}`)}</Badge>
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 py-2 sm:flex-row sm:gap-4">
      <dt className="w-52 shrink-0 text-sm text-slate-500">{label}</dt>
      <dd className="text-sm text-slate-900">{children}</dd>
    </div>
  )
}

/** Tình trạng sao lưu toàn hệ thống: bản mới nhất, khôi phục thử, lần tải gần nhất, mã hóa, lịch và các vấn đề đang có. */
export function PlatformStatusCard() {
  const { t } = useTranslation()
  const query = usePlatformBackupStatus()

  if (query.isPending) {
    return <Card title={t('backup.status.title')}><LinesSkeleton lines={5} /></Card>
  }

  if (query.isError) {
    return <Card title={t('backup.status.title')}><Alert tone="danger">{t('common.loadError')}</Alert></Card>
  }

  const s = query.data
  const never = t('backup.status.never')

  return (
    <Card title={<span className="flex items-center gap-2">{t('backup.status.title')} <StateBadge state={s.state} /></span>}>
      {!s.enabled && <Alert tone="info" className="mb-3">{t('backup.status.disabledHint')}</Alert>}
      {s.running && <Alert tone="info" className="mb-3">{t('backup.status.running')}</Alert>}

      {s.enabled && (
        <div className="mb-3">
          {s.issues.length === 0 ? (
            <p className="text-sm text-brand-800">{t('backup.status.noIssues')}</p>
          ) : (
            <div className="space-y-2" aria-label={t('backup.status.issues')}>
              {s.issues.map((issue) => (
                <Alert key={issue.code} tone={issue.level === 'critical' ? 'danger' : 'warning'}>{issue.message}</Alert>
              ))}
            </div>
          )}
        </div>
      )}

      <dl className="divide-y divide-slate-100">
        <Row label={t('backup.status.lastCompleted')}>
          {s.last_completed
            ? `${formatDateTime(s.last_completed.completed_at)} (${t('backup.status.hoursAgo', { hours: s.last_completed.age_hours })}, ${formatBytes(s.last_completed.size_bytes)})`
            : never}
        </Row>
        <Row label={t('backup.status.lastVerified')}>
          {s.last_verified ? `${formatDateTime(s.last_verified.at)} — ${s.last_verified.status === 'passed' ? t('backup.verify.passed') : t('backup.verify.failed')}` : never}
        </Row>
        <Row label={t('backup.status.lastDownloaded')}>{s.last_downloaded_at ? formatDateTime(s.last_downloaded_at) : never}</Row>
        <Row label={t('backup.status.encryption')}>
          {s.encryption.algorithm} · {s.encryption.dedicated_key ? t('backup.status.dedicatedKey') : t('backup.status.sharedKey')} ·{' '}
          {t('backup.status.keyFingerprint')}: <span className="font-mono">{s.encryption.key_fingerprint ?? '—'}</span>
        </Row>
        <Row label={t('backup.status.schedule')}>
          {t('backup.status.scheduleValue', {
            backup: s.schedule.backup_at,
            prune: s.schedule.prune_at,
            verify: `${WEEKDAYS[s.schedule.verify_day] ?? ''} ${s.schedule.verify_at}`,
          })}
        </Row>
      </dl>
    </Card>
  )
}

/** Bản rút gọn cho trang Tổng quan của Super Admin. */
export function PlatformStatusSummary() {
  const { t } = useTranslation()
  const query = usePlatformBackupStatus()

  return (
    <Card title={t('dashboard.backupStatus')} actions={<Link to="/platform/backups" className="text-sm font-medium text-brand-700 hover:underline">{t('dashboard.viewDetails')}</Link>}>
      {query.isPending ? (
        <LinesSkeleton lines={2} />
      ) : query.isError ? (
        <Alert tone="danger">{t('common.loadError')}</Alert>
      ) : (
        <div className="space-y-2">
          <StateBadge state={query.data.state} />
          {query.data.issues.map((issue) => (
            <Alert key={issue.code} tone={issue.level === 'critical' ? 'danger' : 'warning'}>{issue.message}</Alert>
          ))}
        </div>
      )}
    </Card>
  )
}
