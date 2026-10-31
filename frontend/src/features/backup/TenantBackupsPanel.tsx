import { useQueryClient } from '@tanstack/react-query'
import { Upload } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Card, Spinner } from '../../components/ui.tsx'
import { describeError } from '../../lib/forms.ts'
import { formatDateTime } from '../../lib/format.ts'
import { backupPath, isRestoringError, useBackups, useCreateBackup, useRestoreRuns, type BackupRow, type RestoreRunRow, type TenantBackupSource } from './api.ts'
import { BackupTable } from './BackupTable.tsx'
import { ImportDialog } from './ImportDialog.tsx'
import { RestoreDialog } from './RestoreDialog.tsx'
import { RestoreHistory } from './RestoreHistory.tsx'

const unfinished = (run: RestoreRunRow | undefined) => run?.status === 'pending' || run?.status === 'running'

/**
 * Sao lưu của MỘT doanh nghiệp: danh sách theo ngày giờ, sao lưu ngay, Export (tải về), Import (tải file lên), Khôi phục và lịch sử khôi phục.
 * Dùng cho Chủ doanh nghiệp (chính doanh nghiệp mình) và Super Admin (doanh nghiệp được chọn) — quyền thật do máy chủ kiểm tra,
 * các cờ `can*` chỉ để ẩn nút không dùng được.
 */
export function TenantBackupsPanel({
  source,
  tenantName,
  canCreate,
  canExport,
  canRestore,
}: {
  source: TenantBackupSource
  /** Tên doanh nghiệp: người khôi phục phải gõ lại đúng tên này để xác nhận. */
  tenantName: string
  canCreate: boolean
  canExport: boolean
  canRestore: boolean
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const backups = useBackups(source)
  const runs = useRestoreRuns(source)
  const create = useCreateBackup(source)
  const [dialog, setDialog] = useState<'import' | { restore: BackupRow } | null>(null)
  // Lần khôi phục vừa yêu cầu: theo dõi đến khi xong để báo kết quả ngay trên trang.
  const [watching, setWatching] = useState<string | null>(null)

  // Trong lúc khôi phục doanh nghiệp bị đặt ở chế độ bảo trì: máy chủ trả 503 `tenant_restoring` cho mọi yêu cầu.
  const restoring = unfinished(runs.data?.find((run) => unfinished(run))) || isRestoringError(backups.error) || isRestoringError(runs.error)
  const creating = backups.data?.some((row) => row.status === 'pending' || row.status === 'running') ?? false
  const outcome = watching === null ? undefined : runs.data?.find((run) => run.id === watching && !unfinished(run))

  // Khôi phục xong thì dữ liệu ở mọi màn hình khác đã đổi: bắt mọi truy vấn lấy lại.
  const wasRestoring = useRef(false)
  useEffect(() => {
    if (wasRestoring.current && !restoring) void queryClient.invalidateQueries()
    wasRestoring.current = restoring
  }, [restoring, queryClient])

  const runCount = runs.data?.length ?? 0

  return (
    <div className="space-y-4">
      {restoring && (
        <Alert tone="warning" title={t('backup.restoring.title')}>
          <span className="flex items-center gap-2"><Spinner className="h-4 w-4" />{t('backup.restoring.text')}</span>
        </Alert>
      )}

      {outcome && (
        <Alert tone={outcome.status === 'completed' ? 'success' : 'danger'} title={t(`backup.outcome.${outcome.status === 'completed' ? 'completed' : 'failed'}Title`)}>
          <p>{outcome.status === 'completed' ? t('backup.outcome.completedText', { time: formatDateTime(outcome.finished_at) }) : t('backup.outcome.failedText', { reason: outcome.error ?? '—' })}</p>
          <div className="mt-2"><Button variant="secondary" onClick={() => setWatching(null)}>{t('common.close')}</Button></div>
        </Alert>
      )}

      {!restoring && (
        <Card
          title={t('backup.list.title')}
          actions={
            <div className="flex flex-wrap gap-2">
              {canCreate && (
                <Button variant="secondary" onClick={() => setDialog('import')}>
                  <Upload className="h-4 w-4" aria-hidden="true" />
                  {t('backup.actions.import')}
                </Button>
              )}
              {canCreate && (
                <Button onClick={() => create.mutate()} loading={create.isPending} disabled={creating}>
                  {create.isPending ? t('backup.actions.creating') : t('backup.actions.create')}
                </Button>
              )}
            </div>
          }
        >
          {create.isSuccess && <Alert tone="success" className="mb-3">{t('backup.actions.requested')}</Alert>}
          {create.isError && <Alert tone="danger" className="mb-3">{describeError(create.error, t)}</Alert>}
          {canExport && <p className="mb-3 text-xs text-slate-500">{t('backup.tenant.exportNote')}</p>}

          {backups.isPending ? (
            <div className="flex justify-center py-6"><Spinner /></div>
          ) : backups.isError ? (
            <Alert tone="danger">{t('common.loadError')}</Alert>
          ) : (
            <BackupTable
              rows={backups.data}
              downloadHref={canExport ? (row) => `/api/v1${backupPath(source)}/${row.id}/download` : undefined}
              onRestore={canRestore ? (row) => setDialog({ restore: row }) : undefined}
            />
          )}
        </Card>
      )}

      {canRestore && (
        <Card title={t('backup.history.title')}>
          {runs.isPending ? (
            <div className="flex justify-center py-6"><Spinner /></div>
          ) : runs.isError ? (
            restoring ? null : <Alert tone="danger">{t('common.loadError')}</Alert>
          ) : (
            <>
              {runCount > 0 && <p className="mb-3 text-xs text-slate-500">{t('backup.history.undoHint')}</p>}
              <RestoreHistory runs={runs.data} backups={backups.data ?? []} />
            </>
          )}
        </Card>
      )}

      {dialog === 'import' && <ImportDialog source={source} onClose={() => setDialog(null)} />}
      {dialog !== null && dialog !== 'import' && (
        <RestoreDialog backup={dialog.restore} source={source} tenantName={tenantName} onClose={() => setDialog(null)} onRequested={setWatching} />
      )}
    </div>
  )
}
