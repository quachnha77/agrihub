import { Search } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ListSkeleton } from '../../components/skeleton.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { Badge } from '../../components/ui.tsx'
import type { Customer } from '../crm/api.ts'
import { useCustomerSearch, type PaymentStatus } from './api.ts'

/** Tình trạng thu tiền của phiếu: chưa thu (đỏ), thu một phần (vàng), đã thu đủ (xanh); khách nợ quá hạn thì đổi sang "Quá hạn" (đỏ). */
export function PaymentBadge({ status, overdue = false }: { status: PaymentStatus; overdue?: boolean }) {
  const { t } = useTranslation()

  if (overdue) return <Badge tone="danger" dot>{t('sales.status.overdue')}</Badge>

  return <Badge tone={status === 'paid' ? 'success' : status === 'partial' ? 'warning' : 'danger'} dot>{t(`sales.status.${status}`)}</Badge>
}

/** Tìm khách đang giao dịch theo tên / mã / số điện thoại; bấm một dòng để chọn. Dùng khi bán chịu (hoặc ghi tên khách cho phiếu bán). */
export function CustomerPicker({ onPick }: { onPick: (customer: Customer) => void }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const search = useDeferredValue(q)
  const customers = useCustomerSearch(search)
  const rows = customers.data ?? []

  return (
    <div>
      <label className="mb-1.5 block text-sm font-medium text-slate-800">
        {t('sales.form.customer')}
        <div className="relative mt-1.5">
          <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-slate-400" aria-hidden="true" />
          <input type="search" value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('sales.form.customerPlaceholder')} autoComplete="off" className={`${fieldBase} ${fieldTone(false)} pl-9 font-normal`} />
        </div>
      </label>
      {customers.isPending ? (
        <div className="mt-2 rounded-lg border border-slate-200 bg-white px-3"><ListSkeleton rows={3} subtitle /></div>
      ) : rows.length === 0 ? (
        <p className="mt-2 text-sm text-slate-500">{t('sales.form.noCustomers')}</p>
      ) : (
        <ul className="mt-2 max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 bg-white">
          {rows.map((customer) => (
            <li key={customer.id}>
              <button type="button" onClick={() => onPick(customer)} className="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-left text-sm hover:bg-brand-50">
                <span><span className="font-medium text-slate-900">{customer.name}</span> <span className="font-mono text-xs text-slate-500">{customer.code}</span></span>
                <span className="text-xs text-slate-500">{customer.phone ?? customer.branch.name}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
