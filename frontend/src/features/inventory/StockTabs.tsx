import { useTranslation } from 'react-i18next'
import { SectionTabs } from '../../components/page.tsx'
import { useAuth } from '../auth/auth-context.ts'

/** Thanh tab của phân hệ Kho hàng: Tồn kho | Phiếu kho | Danh sách kho (mỗi tab chỉ hiện khi có quyền xem). */
export function StockTabs() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const movements = can('inventory.movement.view')

  return (
    <SectionTabs
      label={t('inventory.tabs.label')}
      items={[
        { to: '/stock', label: t('inventory.tabs.stock'), end: true, visible: movements },
        { to: '/stock/documents', label: t('inventory.tabs.documents'), visible: movements },
        { to: '/warehouses', label: t('inventory.tabs.warehouses'), visible: can('inventory.warehouse.view') },
      ]}
    />
  )
}
