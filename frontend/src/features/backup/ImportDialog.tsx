import { FileUp } from 'lucide-react'
import { useId, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { buttonStyles } from '../../components/styles.ts'
import { Alert, Button, Modal } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { describeError } from '../../lib/forms.ts'
import { formatBytes } from '../../lib/format.ts'
import { useImportBackup, type TenantBackupSource } from './api.ts'

/**
 * Import: chọn file .agbk đã tải về trước đó. Máy chủ giải mã và kiểm tra toàn bộ (đúng định dạng, đúng doanh nghiệp, còn nguyên vẹn)
 * trước khi lưu; file hợp lệ xuất hiện trong danh sách để khôi phục — nhập file không tự thay đổi dữ liệu nào.
 */
export function ImportDialog({ source, onClose }: { source: TenantBackupSource; onClose: () => void }) {
  const { t } = useTranslation()
  const upload = useImportBackup(source)
  const inputId = useId()
  const [file, setFile] = useState<File | null>(null)
  const [fieldError, setFieldError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [done, setDone] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setFieldError(null)
    setFormError(null)

    if (file === null) {
      setFieldError(t('backup.importDialog.fileRequired'))

      return
    }

    try {
      await upload.mutateAsync(file)
      setDone(true)
    } catch (error) {
      if (error instanceof ApiError && error.code === 'backup_invalid') setFormError(t('backup.importDialog.invalid', { reason: error.message }))
      else if (error instanceof ApiError && error.status === 413) setFormError(t('backup.importDialog.tooLarge'))
      else if (error instanceof ApiError && error.status === 422 && error.errors.file?.[0]) setFieldError(error.errors.file[0])
      else setFormError(describeError(error, t))
    }
  }

  return (
    <Modal title={t('backup.importDialog.title')} onClose={onClose}>
      {done ? (
        <div className="space-y-4">
          <Alert tone="success">{t('backup.importDialog.done')}</Alert>
          <div className="text-right"><Button onClick={onClose}>{t('common.close')}</Button></div>
        </div>
      ) : (
        <form onSubmit={submit} noValidate className="space-y-4">
          <p className="text-sm text-slate-700">{t('backup.importDialog.intro')}</p>
          {formError && <Alert tone="danger">{formError}</Alert>}

          <div>
            <p className="mb-1 text-sm font-medium text-slate-800">{t('backup.importDialog.file')}</p>
            {/* Ô chọn file gốc của trình duyệt hiện chữ theo ngôn ngữ trình duyệt: ẩn đi và dùng nút tiếng Việt, vẫn dùng được bằng bàn phím. */}
            <label className={`${buttonStyles('secondary')} cursor-pointer has-focus-visible:ring-2 has-focus-visible:ring-brand-200`}>
              <FileUp className="h-4 w-4" aria-hidden="true" />
              {file ? t('backup.importDialog.change') : t('backup.importDialog.choose')}
              <input
                id={inputId}
                type="file"
                accept=".agbk"
                aria-label={t('backup.importDialog.file')}
                aria-invalid={fieldError ? true : undefined}
                aria-describedby={fieldError ? `${inputId}-error` : undefined}
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                className="sr-only"
              />
            </label>
            <p className="mt-1 text-xs text-slate-500">{file ? `${file.name} · ${formatBytes(file.size)}` : t('backup.importDialog.none')}</p>
            {fieldError && <p id={`${inputId}-error`} role="alert" className="mt-1 text-sm text-red-700">{fieldError}</p>}
          </div>

          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
            <Button type="submit" loading={upload.isPending}>
              {upload.isPending ? t('backup.importDialog.submitting') : t('backup.importDialog.submit')}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
