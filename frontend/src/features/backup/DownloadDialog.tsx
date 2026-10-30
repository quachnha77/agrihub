import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Modal, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { describeError } from '../../lib/forms.ts'
import { useDownloadLink, type BackupRow } from './api.ts'

/**
 * Tải bản sao lưu toàn hệ thống: nhập lại mật khẩu → nhận liên kết ký (2 phút, dùng một lần) → trình duyệt tải file.
 * Dùng điều hướng thường (không fetch) để file lớn được trình duyệt tải trực tiếp xuống đĩa, không giữ trong bộ nhớ.
 */
export function DownloadDialog({ backup, onClose, navigate = (url) => window.location.assign(url) }: { backup: BackupRow; onClose: () => void; navigate?: (url: string) => void }) {
  const { t } = useTranslation()
  const link = useDownloadLink()
  const [password, setPassword] = useState('')
  const [fieldError, setFieldError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [started, setStarted] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setFieldError(null)
    setFormError(null)

    if (password === '') {
      setFieldError(t('backup.downloadDialog.passwordRequired'))

      return
    }

    try {
      const { url } = await link.mutateAsync({ id: backup.id, password })
      setStarted(true)
      setPassword('')
      navigate(url)
    } catch (error) {
      if (error instanceof ApiError && error.code === 'confirmation_failed') setFieldError(t('backup.downloadDialog.wrongPassword'))
      else if (error instanceof ApiError && (error.code === 'backup_not_ready' || error.code === 'backup_file_missing')) setFormError(t('backup.downloadDialog.notReady'))
      else setFormError(describeError(error, t))
    }
  }

  return (
    <Modal title={t('backup.downloadDialog.title')} onClose={onClose}>
      {started ? (
        <div className="space-y-4">
          <Alert tone="success">{t('backup.downloadDialog.started')}</Alert>
          <div className="text-right"><Button onClick={onClose}>{t('common.close')}</Button></div>
        </div>
      ) : (
        <form onSubmit={submit} noValidate className="space-y-4">
          <p className="text-sm text-slate-700">{t('backup.downloadDialog.intro')}</p>
          <Alert tone="warning">{t('backup.downloadDialog.keyWarning', { fingerprint: backup.key_fingerprint ?? '—' })}</Alert>
          {formError && <Alert tone="danger">{formError}</Alert>}
          <TextField
            label={t('backup.downloadDialog.password')}
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            error={fieldError ?? undefined}
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
            <Button type="submit" loading={link.isPending}>
              {link.isPending ? t('backup.downloadDialog.submitting') : t('backup.downloadDialog.submit')}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
