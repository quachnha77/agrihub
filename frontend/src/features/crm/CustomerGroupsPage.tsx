import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../components/page.tsx'
import { ListCard } from '../catalog/CatalogListsPage.tsx'
import { CustomerTabs } from './CustomerTabs.tsx'

/** Nhóm khách hàng (Đại lý, Nông dân, Khách quen...): chỉ để phân loại và lọc, không ảnh hưởng giá hay công nợ. Dùng chung mọi chi nhánh. */
export default function CustomerGroupsPage() {
  const { t } = useTranslation()

  return (
    <div className="space-y-5">
      <PageHeader title={t('crm.groups.title')} description={t('crm.groups.intro')} tabs={<CustomerTabs />} />
      <div className="max-w-2xl">
        <ListCard kind="customer-groups" permission="crm.customer" />
      </div>
    </div>
  )
}
