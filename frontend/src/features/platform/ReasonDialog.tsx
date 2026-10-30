import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Modal, TextField } from '../../components/ui.tsx'
import { describeError } from '../../lib/forms.ts'

/**
 * Hộp thoại hỏi MỘT dòng chữ bắt buộc (lý do khóa doanh nghiệp, ghi chú liên hệ...). Lỗi từ máy chủ hiện ngay trong hộp.
 * Độ dài tối thiểu khớp với máy chủ để báo lỗi sớm; máy chủ vẫn kiểm tra lại.
 */
export function ReasonDialog({
  title,
  intro,
  label,
  minLength,
  submitLabel,
  danger = false,
  onSubmit,
  onClose,
}: {
  title: string
  intro?: string
  label: string
  minLength: number
  submitLabel: string
  danger?: boolean
  onSubmit: (text: string) => Promise<unknown>
  onClose: () => void
}) {
  const { t } = useTranslation()
  const [text, setText] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    const value = text.trim()

    if (value.length < minLength) {
      setError(t('validation.minLength', { count: minLength }))
      return
    }

    setError(null)
    setPending(true)

    try {
      await onSubmit(value)
      onClose()
    } catch (failure) {
      setError(describeError(failure, t))
    } finally {
      setPending(false)
    }
  }

  return (
    <Modal title={title} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        {intro && <p className="text-sm text-slate-700">{intro}</p>}
        {error && <Alert tone="danger">{error}</Alert>}
        <TextField label={label} value={text} maxLength={500} onChange={(event) => setText(event.target.value)} />
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" variant={danger ? 'danger' : 'primary'} loading={pending}>{submitLabel}</Button>
        </div>
      </form>
    </Modal>
  )
}
