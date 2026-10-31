import { useTranslation } from 'react-i18next'
import { SectionTabs } from '../../components/page.tsx'

/** Thanh tab của phân hệ Khách hàng: danh sách khách | nhóm khách. */
export function CustomerTabs() {
  const { t } = useTranslation()

  return (
    <SectionTabs
      label={t('crm.tabs.label')}
      items={[
        { to: '/customers', label: t('crm.tabs.customers'), end: true },
        { to: '/customers/groups', label: t('crm.tabs.groups') },
      ]}
    />
  )
}
