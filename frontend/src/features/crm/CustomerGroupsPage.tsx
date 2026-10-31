import { ArrowLeft } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { ListCard } from '../catalog/CatalogListsPage.tsx'

/** Nhóm khách hàng (Đại lý, Nông dân, Khách quen...): chỉ để phân loại và lọc, không ảnh hưởng giá hay công nợ. Dùng chung mọi chi nhánh. */
export default function CustomerGroupsPage() {
  const { t } = useTranslation()

  return (
    <div className="space-y-4">
      <Link to="/customers" className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t('crm.customers.back')}
      </Link>
      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('crm.groups.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('crm.groups.intro')}</p>
      </div>
      <ListCard kind="customer-groups" permission="crm.customer" />
    </div>
  )
}
