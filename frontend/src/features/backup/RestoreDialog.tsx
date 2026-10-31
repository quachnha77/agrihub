import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Modal, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { describeError } from '../../lib/forms.ts'
import { formatBytes, formatDateTime } from '../../lib/format.ts'
import { useRestoreBackup, type BackupRow, type TenantBackupSource } from './api.ts'

/**
 * Khôi phục dữ liệu từ một bản sao lưu — thao tác nguy hiểm nên xác nhận hai bước: nhập lại mật khẩu của chính mình và gõ đúng
 * tên doanh nghiệp. Máy chủ kiểm tra lại cả hai; nút chỉ mở khi tên đã khớp để tránh bấm nhầm.
 */
export function RestoreDialog({
  backup,
  source,
  tenantName,
  onClose,
  onRequested,
}: {
  backup: BackupRow
  source: TenantBackupSource
  tenantName: string
  onClose: () => void
  /** Gọi khi máy chủ đã nhận yêu cầu (202), kèm mã lần khôi phục để theo dõi kết quả. */
  onRequested: (runId: string) => void
}) {
  const { t } = useTranslation()
  const restore = useRestoreBackup(source)
  const [password, setPassword] = useState('')
  const [confirmName, setConfirmName] = useState('')
  const [passwordError, setPasswordError] = useState<string | null>(null)
  const [nameError, setNameError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)

  const tables = Object.keys(backup.row_counts ?? {}).length
  const rows = Object.values(backup.row_counts ?? {}).reduce((sum, count) => sum + count, 0)
  const nameMatches = confirmName === tenantName

  async function submit(event: FormEvent) {
    event.preventDefault()
    setPasswordError(null)
    setNameError(null)
    setFormError(null)

    if (password === '') {
      setPasswordError(t('backup.restoreDialog.passwordRequired'))

      return
    }

    try {
      const run = await restore.mutateAsync({ id: backup.id, password, confirm_name: confirmName })
      setPassword('')
      onRequested(run.id)
      onClose()
    } catch (error) {
      if (error instanceof ApiError && error.code === 'confirmation_failed') {
        if (error.errors.password?.[0]) setPasswordError(t('backup.restoreDialog.wrongPassword'))
        else setNameError(t('backup.restoreDialog.nameMismatch'))
      } else if (error instanceof ApiError && error.code === 'restore_in_progress') setFormError(t('backup.restoreDialog.busy'))
      else if (error instanceof ApiError && (error.code === 'backup_not_ready' || error.code === 'backup_file_missing')) setFormError(t('backup.restoreDialog.notReady'))
      else setFormError(describeError(error, t))
    }
  }

  return (
    <Modal title={t('backup.restoreDialog.title')} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        <p className="text-sm text-slate-700">
          {t('backup.restoreDialog.source', { time: formatDateTime(backup.created_at), kind: t(`backup.kinds.${backup.kind}`), size: formatBytes(backup.size_bytes) })}
        </p>

        {tables === 0 ? (
          <Alert tone="info">{t('backup.restoreDialog.noTables')}</Alert>
        ) : (
          <p className="text-sm text-slate-700">{t('backup.restoreDialog.contents', { tables, rows })}</p>
        )}

        <Alert tone="danger" title={t('backup.restoreDialog.warningTitle')}>
          <ul className="list-disc space-y-1 pl-5">
            <li>{t('backup.restoreDialog.warnReplace')}</li>
            <li>{t('backup.restoreDialog.warnUndo')}</li>
            <li>{t('backup.restoreDialog.warnDowntime')}</li>
            <li>{t('backup.restoreDialog.warnKept')}</li>
          </ul>
        </Alert>

        {formError && <Alert tone="danger">{formError}</Alert>}

        <TextField
          label={t('backup.restoreDialog.password')}
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          error={passwordError ?? undefined}
        />
        <TextField
          label={t('backup.restoreDialog.confirmName', { name: tenantName })}
          autoComplete="off"
          value={confirmName}
          onChange={(event) => setConfirmName(event.target.value)}
          error={nameError ?? undefined}
        />

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" variant="danger" loading={restore.isPending} disabled={!nameMatches}>
            {restore.isPending ? t('backup.restoreDialog.submitting') : t('backup.restoreDialog.submit')}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
