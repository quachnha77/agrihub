import { useTranslation } from 'react-i18next'
import { SectionTabs } from '../../components/page.tsx'

/** Thanh tab của phân hệ Sản phẩm: danh sách sản phẩm | đơn vị tính, nhóm hàng, thương hiệu. */
export function ProductTabs() {
  const { t } = useTranslation()

  return (
    <SectionTabs
      label={t('catalog.tabs.label')}
      items={[
        { to: '/products', label: t('catalog.tabs.products'), end: true },
        { to: '/products/lists', label: t('catalog.tabs.lists') },
      ]}
    />
  )
}
