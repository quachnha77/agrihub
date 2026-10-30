import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Modal } from '../../components/ui.tsx'

/**
 * Hiện mật khẩu tạm MỘT lần (máy chủ không lưu bản rõ nên không xem lại được) cùng địa chỉ đăng nhập để chuyển cho người dùng
 * qua kênh an toàn. Người dùng phải đổi mật khẩu ở lần đăng nhập đầu.
 */
export function TemporaryPasswordDialog({ userName, password, onClose }: { userName: string; password: string; onClose: () => void }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  async function copy() {
    try {
      await navigator.clipboard.writeText(password)
      setCopied(true)
    } catch {
      setCopied(false) // trình duyệt chặn clipboard: người dùng tự chọn và chép
    }
  }

  return (
    <Modal title={t('users.tempPassword.title', { name: userName })} onClose={onClose}>
      <div className="space-y-4">
        <Alert tone="warning">{t('users.tempPassword.once')}</Alert>
        <div className="flex items-center gap-2">
          <code data-testid="temporary-password" className="block flex-1 select-all rounded bg-slate-100 px-3 py-2 font-mono text-base tracking-wide">{password}</code>
          <Button variant="secondary" onClick={copy}>{copied ? t('common.copied') : t('common.copy')}</Button>
        </div>
        <p className="text-sm text-slate-600">{t('users.tempPassword.loginAt', { url: window.location.origin })}</p>
        <p className="text-sm text-slate-600">{t('users.tempPassword.mustChange')}</p>
        <div className="text-right"><Button onClick={onClose}>{t('common.close')}</Button></div>
      </div>
    </Modal>
  )
}
