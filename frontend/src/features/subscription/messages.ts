import type { Tone } from '../../components/ui.tsx'
import { formatDate } from '../../lib/format.ts'
import type { SubscriptionNotice } from '../auth/types.ts'

type TFn = (key: string, options?: Record<string, unknown>) => string

/** Nội dung và mức độ của banner gói dịch vụ (null = không cần nhắc). Hết hạn thật sự thì người dùng không vào được (đăng nhập bị chặn). */
export function subscriptionMessage(notice: SubscriptionNotice, t: TFn): { tone: Tone; text: string } | null {
  const days = notice.days_left

  switch (notice.state) {
    case 'trial':
      return days <= 0
        ? { tone: 'danger', text: t('banner.trialLastDay') }
        : { tone: days <= 2 ? 'warning' : 'info', text: t('banner.trial', { days }) }
    case 'active':
      return days <= 0
        ? { tone: 'danger', text: t('banner.expiringToday') }
        : { tone: days <= 3 ? 'danger' : 'warning', text: t('banner.expiring', { days }) }
    case 'grace':
      return { tone: 'danger', text: t('banner.grace') }
    case 'reopened':
      return { tone: 'warning', text: t('banner.reopened', { date: formatDate(notice.reopened_until) }) }
    case 'expired':
      return { tone: 'danger', text: t('banner.expired') }
    default:
      return null
  }
}
