import { useTranslation } from 'react-i18next'
import { Alert, Button, Card, Spinner } from '../../components/ui.tsx'
import { describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useBackups, useCreateBackup } from './api.ts'
import { BackupTable } from './BackupTable.tsx'

/** Chủ doanh nghiệp: xem danh sách theo ngày giờ, tạo bản thủ công, tải về (Export). Import/Khôi phục sẽ bổ sung ở bước sau. */
export default function TenantBackupsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const backups = useBackups('tenant')
  const create = useCreateBackup('tenant')

  const running = backups.data?.some((row) => row.status === 'pending' || row.status === 'running') ?? false

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('backup.tenant.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('backup.tenant.intro')}</p>
      </div>

      <Card
        title={t('backup.list.title')}
        actions={
          can('backup.backup.create') && (
            <Button onClick={() => create.mutate()} loading={create.isPending} disabled={running}>
              {create.isPending ? t('backup.actions.creating') : t('backup.actions.create')}
            </Button>
          )
        }
      >
        {create.isSuccess && <Alert tone="success" className="mb-3">{t('backup.actions.requested')}</Alert>}
        {create.isError && <Alert tone="danger" className="mb-3">{describeError(create.error, t)}</Alert>}
        {can('backup.backup.export') && <p className="mb-3 text-xs text-slate-500">{t('backup.tenant.exportNote')}</p>}

        {backups.isPending ? (
          <div className="flex justify-center py-6"><Spinner /></div>
        ) : backups.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : (
          <BackupTable rows={backups.data} downloadHref={can('backup.backup.export') ? (row) => `/api/v1/backups/${row.id}/download` : undefined} />
        )}
      </Card>
    </div>
  )
}
