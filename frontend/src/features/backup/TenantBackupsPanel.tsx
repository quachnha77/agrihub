import { useQueryClient } from '@tanstack/react-query'
import { Upload } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { TableSkeleton } from '../../components/skeleton.tsx'
import { Alert, Button, Card, Spinner } from '../../components/ui.tsx'
import { describeError } from '../../lib/forms.ts'
import { formatDateTime } from '../../lib/format.ts'
import { backupPath, useBackups, useCreateBackup, useRestoreRuns, type BackupRow, type RestoreRunRow, type TenantBackupSource } from './api.ts'
import { BackupTable } from './BackupTable.tsx'
import { ImportDialog } from './ImportDialog.tsx'
import { RestoreDialog } from './RestoreDialog.tsx'
import { RestoreHistory } from './RestoreHistory.tsx'

const unfinished = (run: RestoreRunRow | undefined) => run?.status === 'pending' || run?.status === 'running'

/**
 * Sao lưu của MỘT doanh nghiệp, dành cho Super Admin (doanh nghiệp không có chức năng này): danh sách theo ngày giờ, sao lưu ngay,
 * tải về (Export), Import (tải file lên), Khôi phục và lịch sử khôi phục. Quyền thật do máy chủ kiểm tra (chỉ Super Admin).
 */
export function TenantBackupsPanel({ source, tenantName }: { source: TenantBackupSource; /** Người khôi phục phải gõ lại đúng tên này để xác nhận. */ tenantName: string }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const backups = useBackups(source)
  const runs = useRestoreRuns(source)
  const create = useCreateBackup(source)
  const [dialog, setDialog] = useState<'import' | { restore: BackupRow } | null>(null)
  // Lần khôi phục vừa yêu cầu: theo dõi đến khi xong để báo kết quả ngay trên trang.
  const [watching, setWatching] = useState<string | null>(null)

  // Trong lúc khôi phục doanh nghiệp ở chế độ bảo trì (người dùng của họ nhận 503); Super Admin vẫn gọi được và theo dõi qua lịch sử khôi phục.
  const restoring = runs.data?.some((run) => unfinished(run)) ?? false
  const creating = backups.data?.some((row) => row.status === 'pending' || row.status === 'running') ?? false
  const outcome = watching === null ? undefined : runs.data?.find((run) => run.id === watching && !unfinished(run))

  // Khôi phục xong thì dữ liệu của doanh nghiệp đã đổi: bắt mọi truy vấn lấy lại.
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
              <Button variant="secondary" onClick={() => setDialog('import')}>
                <Upload className="h-4 w-4" aria-hidden="true" />
                {t('backup.actions.import')}
              </Button>
              <Button onClick={() => create.mutate()} loading={create.isPending} disabled={creating}>
                {create.isPending ? t('backup.actions.creating') : t('backup.actions.create')}
              </Button>
            </div>
          }
        >
          {create.isSuccess && <Alert tone="success" className="mb-3">{t('backup.actions.requested')}</Alert>}
          {create.isError && <Alert tone="danger" className="mb-3">{describeError(create.error, t)}</Alert>}
          <p className="mb-3 text-xs text-slate-500">{t('backup.admin.exportNote')}</p>

          {backups.isPending ? (
            <TableSkeleton columns={6} rows={4} actions minWidth="40rem" />
          ) : backups.isError ? (
            <Alert tone="danger">{t('common.loadError')}</Alert>
          ) : (
            <BackupTable rows={backups.data} downloadHref={(row) => `/api/v1${backupPath(source)}/${row.id}/download`} onRestore={(row) => setDialog({ restore: row })} />
          )}
        </Card>
      )}

      <Card title={t('backup.history.title')}>
        {runs.isPending ? (
          <TableSkeleton columns={5} rows={3} minWidth="40rem" />
        ) : runs.isError ? (
          restoring ? null : <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : (
          <>
            {runCount > 0 && <p className="mb-3 text-xs text-slate-500">{t('backup.history.undoHint')}</p>}
            <RestoreHistory runs={runs.data} backups={backups.data ?? []} />
          </>
        )}
      </Card>

      {dialog === 'import' && <ImportDialog source={source} onClose={() => setDialog(null)} />}
      {dialog !== null && dialog !== 'import' && (
        <RestoreDialog backup={dialog.restore} source={source} tenantName={tenantName} onClose={() => setDialog(null)} onRequested={setWatching} />
      )}
    </div>
  )
}
