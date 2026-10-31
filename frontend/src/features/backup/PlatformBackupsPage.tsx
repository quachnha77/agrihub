import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../components/page.tsx'
import { TableSkeleton } from '../../components/skeleton.tsx'
import { Alert, Button, Card } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { describeError } from '../../lib/forms.ts'
import { useBackups, useCreateBackup, type BackupRow } from './api.ts'
import { BackupTable } from './BackupTable.tsx'
import { DownloadDialog } from './DownloadDialog.tsx'
import { PlatformStatusCard } from './StatusCard.tsx'

/** Super Admin: tình trạng, danh sách, sao lưu ngay và tải bản sao lưu toàn hệ thống về lưu riêng. */
export default function PlatformBackupsPage() {
  const { t } = useTranslation()
  const backups = useBackups('platform')
  const create = useCreateBackup('platform')
  const [target, setTarget] = useState<BackupRow | null>(null)

  const running = backups.data?.some((row) => row.status === 'pending' || row.status === 'running') ?? false

  return (
    <div className="space-y-5">
      <PageHeader title={t('backup.platform.title')} description={t('backup.platform.intro')} />

      <PlatformStatusCard />

      <Card
        title={t('backup.list.title')}
        actions={
          <Button onClick={() => create.mutate()} loading={create.isPending} disabled={running}>
            {create.isPending ? t('backup.actions.creating') : t('backup.actions.create')}
          </Button>
        }
      >
        {create.isSuccess && <Alert tone="success" className="mb-3">{t('backup.actions.requested')}</Alert>}
        {create.isError && (
          <Alert tone="danger" className="mb-3">
            {create.error instanceof ApiError && create.error.code === 'backup_in_progress' ? create.error.message : describeError(create.error, t)}
          </Alert>
        )}

        {backups.isPending ? (
          <TableSkeleton columns={8} rows={4} actions minWidth="40rem" />
        ) : backups.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : (
          <BackupTable rows={backups.data} showPlatformColumns onDownload={setTarget} />
        )}
      </Card>

      {target && <DownloadDialog backup={target} onClose={() => setTarget(null)} />}
    </div>
  )
}
