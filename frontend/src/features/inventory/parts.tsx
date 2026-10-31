import { useTranslation } from 'react-i18next'
import { Badge } from '../../components/ui.tsx'
import { formatIsoDate } from '../../lib/format.ts'
import type { DocumentType, StocktakeStatus } from './api.ts'
import { daysFromToday } from './dates.ts'

/** Hạn dùng kèm trạng thái: đã hết hạn (đỏ), sắp hết hạn trong `warnDays` ngày (vàng), còn lâu (xám). */
export function ExpiryBadge({ date, warnDays = 60 }: { date: string | null; warnDays?: number }) {
  const { t } = useTranslation()

  if (!date) return <span className="text-slate-400">—</span>

  const days = daysFromToday(date)

  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      <span className="whitespace-nowrap">{formatIsoDate(date)}</span>
      {days < 0 && <Badge tone="danger">{t('inventory.expiry.expired', { count: -days })}</Badge>}
      {days >= 0 && days <= warnDays && <Badge tone="warning">{t('inventory.expiry.soon', { count: days })}</Badge>}
    </span>
  )
}

export function TypeBadge({ type }: { type: DocumentType }) {
  const { t } = useTranslation()
  const tone = type === 'receipt' || type === 'opening' ? 'success' : type === 'issue' ? 'warning' : 'info'

  return <Badge tone={tone}>{t(`inventory.types.${type}`)}</Badge>
}

/** Trạng thái đợt kiểm kê: đang đếm (vàng, kho đang khóa), đã hoàn tất (xanh), đã hủy (xám). */
export function StocktakeBadge({ status }: { status: StocktakeStatus }) {
  const { t } = useTranslation()

  return <Badge tone={status === 'counting' ? 'warning' : status === 'completed' ? 'success' : 'neutral'} dot>{t(`inventory.stocktake.status.${status}`)}</Badge>
}
