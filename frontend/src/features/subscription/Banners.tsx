import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Alert, Button } from '../../components/ui.tsx'
import { del } from '../../lib/api.ts'
import { formatDateTime } from '../../lib/format.ts'
import { useAuth } from '../auth/auth-context.ts'
import { subscriptionMessage } from './messages.ts'

export function SubscriptionBanner() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const notice = user?.subscription_notice

  if (!notice) return null

  const message = subscriptionMessage(notice, t)

  return message ? <Alert tone={message.tone}>{message.text}</Alert> : null
}

/** Platform Admin đang xem dữ liệu của một doanh nghiệp (phiên hỗ trợ, chỉ đọc). */
export function SupportSessionBanner() {
  const { t } = useTranslation()
  const { user, refresh } = useAuth()
  const session = user?.support_session

  const end = useMutation({
    mutationFn: () => del('/platform/support-session'),
    onSuccess: () => refresh(),
  })

  if (!session) return null

  return (
    <Alert tone="warning">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span>
          {t('banner.supportSession', { tenant: session.tenant.name, reason: session.reason, expires: formatDateTime(session.expires_at) })}
        </span>
        <Button variant="secondary" onClick={() => end.mutate()} loading={end.isPending}>
          {t('banner.endSupportSession')}
        </Button>
      </div>
    </Alert>
  )
}
