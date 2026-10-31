import { useTranslation } from 'react-i18next'
import { Badge, type Tone } from '../../components/ui.tsx'
import { formatDateTime } from '../../lib/format.ts'
import type { BackupRow, BackupStatusValue, RestoreRunRow } from './api.ts'

const statusTone: Record<BackupStatusValue, Tone> = { pending: 'neutral', running: 'info', completed: 'success', failed: 'danger' }

/** Lịch sử các lần khôi phục: dùng bản nào, điểm quay lại để hoàn tác, kết quả. */
export function RestoreHistory({ runs, backups }: { runs: RestoreRunRow[]; backups: BackupRow[] }) {
  const { t } = useTranslation()
  const byId = new Map(backups.map((backup) => [backup.id, backup]))

  const describe = (id: string | null) => {
    const backup = id === null ? undefined : byId.get(id)

    return backup ? `${formatDateTime(backup.created_at)} (${t(`backup.kinds.${backup.kind}`)})` : '—'
  }

  if (runs.length === 0) return <p className="py-4 text-center text-sm text-slate-500">{t('backup.history.empty')}</p>

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[40rem] text-left text-sm">
        <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th scope="col" className="py-2 pr-3">{t('backup.history.requested')}</th>
            <th scope="col" className="py-2 pr-3">{t('backup.history.from')}</th>
            <th scope="col" className="py-2 pr-3">{t('backup.history.undoPoint')}</th>
            <th scope="col" className="py-2 pr-3">{t('backup.history.status')}</th>
            <th scope="col" className="py-2">{t('backup.history.finished')}</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {runs.map((run) => (
            <tr key={run.id}>
              <td className="py-2 pr-3 whitespace-nowrap">{formatDateTime(run.created_at)}</td>
              <td className="py-2 pr-3">{describe(run.backup_id)}</td>
              <td className="py-2 pr-3">{describe(run.pre_restore_backup_id)}</td>
              <td className="py-2 pr-3">
                <Badge tone={statusTone[run.status]}>{t(`backup.statuses.${run.status}`)}</Badge>
                {run.error && <span className="mt-1 block max-w-xs text-xs text-red-700">{run.error}</span>}
              </td>
              <td className="py-2 whitespace-nowrap">{formatDateTime(run.finished_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
