import type { TFunction } from 'i18next'

/** "còn 5 ngày", "hết hạn hôm nay", "quá hạn 3 ngày". */
export function daysLabel(days: number, t: TFunction): string {
  if (days > 0) return t('platform.subscription.daysLeft', { count: days })
  if (days === 0) return t('platform.subscription.expiresToday')

  return t('platform.subscription.daysOverdue', { count: -days })
}
