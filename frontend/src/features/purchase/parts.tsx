import { useTranslation } from 'react-i18next'
import { SectionTabs } from '../../components/page.tsx'
import { Badge } from '../../components/ui.tsx'
import { useAuth } from '../auth/auth-context.ts'
import type { PaymentStatus } from './api.ts'

/** Tình trạng trả tiền của phiếu: chưa trả (đỏ), trả một phần (vàng), đã trả đủ (xanh). */
export function PaymentBadge({ status }: { status: PaymentStatus }) {
  const { t } = useTranslation()

  return <Badge tone={status === 'paid' ? 'success' : status === 'partial' ? 'warning' : 'danger'} dot>{t(`purchase.status.${status}`)}</Badge>
}

/** Thanh tab của phân hệ Nhà cung cấp: Nhà cung cấp | Nhập hàng (mỗi tab chỉ hiện khi có quyền xem; chỉ một tab thì không hiện thanh). */
export function PurchaseTabs() {
  const { t } = useTranslation()
  const { can } = useAuth()

  return (
    <SectionTabs
      label={t('purchase.tabs.label')}
      items={[
        { to: '/suppliers', label: t('purchase.tabs.suppliers'), end: true, visible: can('purchase.supplier.view') },
        { to: '/purchases', label: t('purchase.tabs.purchases'), visible: can('purchase.order.view') },
      ]}
    />
  )
}
