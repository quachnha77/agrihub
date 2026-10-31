import { ArrowLeft, ChevronDown, Search, SlidersHorizontal, type LucideIcon } from 'lucide-react'
import { useId, useState, type ChangeEventHandler, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, NavLink } from 'react-router'
import { Skeleton } from './skeleton.tsx'
import { fieldBase } from './styles.ts'
import { Button } from './ui.tsx'

/*
 * Các khối dùng chung ở mức trang. Mọi màn hình dựng theo cùng một khung để người dùng không phải học lại:
 *   PageHeader  → tiêu đề + một câu mô tả + hành động chính ở bên phải (luôn ở cùng vị trí)
 *   SectionTabs → chuyển giữa các màn hình cùng phân hệ (Tồn kho | Phiếu kho | Kho) thay cho hàng nút rời rạc
 *   FilterBar   → một hàng tìm kiếm + ô lọc gọn; trên điện thoại ô lọc thu vào nút "Bộ lọc"
 *   EmptyState  → danh sách rỗng có hướng dẫn và nút làm ngay
 *   FormActions → thanh Lưu/Hủy dính đáy màn hình để không phải cuộn xuống mới thấy nút Lưu
 */

/** Tiêu đề trang. `back`: liên kết quay lại đặt phía trên (cho trang con như biểu mẫu thêm/sửa). */
export function PageHeader({ title, description, actions, back, tabs }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; back?: { to: string; label: string }; /** Thanh tab của phân hệ, đặt ngay dưới tiêu đề. */ tabs?: ReactNode }) {
  return (
    <header className="space-y-4">
      {back && (
        <Link to={back.to} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
          <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          {back.label}
        </Link>
      )}
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div className="min-w-0">
          <h1 className="text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">{title}</h1>
          {description && <p className="mt-1 max-w-2xl text-sm text-slate-600">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
      {tabs}
    </header>
  )
}

export interface TabItem {
  to: string
  label: string
  /** Chỉ sáng khi đúng đường dẫn này (không tính đường dẫn con). */
  end?: boolean
  visible?: boolean
}

/** Thanh tab của một phân hệ. Cuộn ngang được trên điện thoại. */
export function SectionTabs({ label, items }: { label: string; items: TabItem[] }) {
  const visible = items.filter((item) => item.visible !== false)

  if (visible.length < 2) return null

  return (
    <nav aria-label={label} className="-mx-4 overflow-x-auto border-b border-slate-200 px-4 md:mx-0 md:px-0">
      <ul className="flex min-w-max gap-1">
        {visible.map((item) => (
          <li key={item.to}>
            <NavLink
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                `-mb-px block whitespace-nowrap border-b-2 px-3.5 py-2.5 text-sm font-medium transition-colors ${
                  isActive ? 'border-brand-600 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900'
                }`
              }
            >
              {item.label}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}

/** Danh sách rỗng: biểu tượng, một dòng nói rõ chưa có gì, gợi ý ngắn và (nếu được phép) nút làm ngay. */
export function EmptyState({ icon: Icon, title, description, action }: { icon: LucideIcon; title: string; description?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center px-4 py-10 text-center">
      <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-700">
        <Icon className="h-6 w-6" aria-hidden="true" />
      </div>
      <p className="text-base font-semibold text-slate-900">{title}</p>
      {description && <p className="mt-1 max-w-sm text-sm text-slate-600">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}

/** Thanh nút của biểu mẫu dài: dính đáy màn hình, trải hết bề rộng nội dung. */
export function FormActions({ children }: { children: ReactNode }) {
  return (
    <div className="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur md:-mx-6 md:px-6">{children}</div>
  )
}

/** Ô tìm kiếm có biểu tượng. `label` là tên cho trình đọc màn hình (không hiện chữ). */
export function SearchField({ label, value, onChange, placeholder, id }: { label: string; value: string; onChange: ChangeEventHandler<HTMLInputElement>; placeholder?: string; id?: string }) {
  return (
    <div className="relative">
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
      <input id={id} type="search" aria-label={label} value={value} onChange={onChange} placeholder={placeholder} className={`${fieldBase} border-slate-300 pl-9 text-ellipsis focus:border-brand-600 focus:ring-brand-200`} />
    </div>
  )
}

/** Ô lọc gọn: tên bộ lọc nằm ngay trong ô ("Loại hàng  Tất cả"), đang lọc thì viền xanh để thấy ngay bộ lọc nào đang bật. */
export function FilterSelect({ label, value, onChange, children, className = '' }: { label: string; value: string; onChange: ChangeEventHandler<HTMLSelectElement>; children: ReactNode; className?: string }) {
  const id = useId()
  const active = value !== ''

  return (
    <div
      className={`relative flex min-h-10 min-w-0 items-center rounded-lg border shadow-xs md:flex-[1_1_10rem] transition-colors focus-within:border-brand-600 focus-within:ring-2 focus-within:ring-brand-200 ${
        active ? 'border-brand-600/60 bg-brand-50/50' : 'border-slate-300 bg-white'
      } ${className}`}
    >
      <label htmlFor={id} className="whitespace-nowrap pl-3 text-xs text-slate-500">
        {label}
      </label>
      <select id={id} value={value} onChange={onChange} className="min-w-0 flex-1 appearance-none bg-transparent py-2 pl-2 pr-8 text-base font-medium text-slate-900 outline-none md:text-sm">
        {children}
      </select>
      <ChevronDown className="pointer-events-none absolute right-2.5 h-4 w-4 text-slate-500" aria-hidden="true" />
    </div>
  )
}

/** Ô lọc theo ngày, cùng kiểu với <FilterSelect> (tên nằm trong ô). */
export function FilterDate({ label, value, onChange, className = '' }: { label: string; value: string; onChange: ChangeEventHandler<HTMLInputElement>; className?: string }) {
  const id = useId()
  const active = value !== ''

  return (
    <div
      className={`flex min-h-10 min-w-0 items-center rounded-lg border shadow-xs transition-colors md:flex-[1_1_10rem] focus-within:border-brand-600 focus-within:ring-2 focus-within:ring-brand-200 ${
        active ? 'border-brand-600/60 bg-brand-50/50' : 'border-slate-300 bg-white'
      } ${className}`}
    >
      <label htmlFor={id} className="whitespace-nowrap pl-3 text-xs text-slate-500">
        {label}
      </label>
      <input id={id} type="date" value={value} onChange={onChange} className="min-w-0 flex-1 bg-transparent py-2 pl-2 pr-3 text-base font-medium text-slate-900 outline-none md:text-sm" />
    </div>
  )
}

/**
 * Hàng tìm kiếm + ô lọc. Ô lọc theo chi nhánh/kho chỉ có khi doanh nghiệp có từ 2 chi nhánh/kho trở lên nên chỉ biết sau khi tải danh sách
 * đó: trong lúc chưa biết, hiện khung giữ chỗ và đổi sang ô thật cùng lúc với bảng bên dưới (`loading`), để một ô thêm vào muộn không đẩy
 * các ô còn lại. Trên điện thoại chỉ hiện ô tìm kiếm + nút "Bộ lọc" (kèm số bộ lọc đang bật); bấm nút mới mở các ô lọc.
 */
export function FilterBar({ loading = false, search, selects = 3, activeCount = 0, onClear, children }: { loading?: boolean; search?: ReactNode; /** Số ô lọc tối đa, để vẽ khung giữ chỗ. */ selects?: number; activeCount?: number; onClear?: () => void; children?: ReactNode }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const panel = useId()

  if (loading) {
    return (
      <div aria-hidden="true" className="mb-4 flex flex-wrap items-center gap-2">
        <Skeleton className="h-10 min-w-0 flex-[1_1_10rem] md:flex-[1.4_1_14rem]" />
        <Skeleton className="h-10 w-28 md:hidden" />
        {Array.from({ length: selects }, (_, field) => (
          <Skeleton key={field} className="hidden h-10 min-w-0 flex-[1_1_10rem] md:block" />
        ))}
      </div>
    )
  }

  return (
    <div className="mb-4 flex flex-wrap items-center gap-2">
      {search && <div className={`min-w-0 flex-[1_1_10rem] ${children ? 'md:flex-[1.4_1_14rem]' : 'md:max-w-md'}`}>{search}</div>}
      {children && (
        <Button variant="secondary" className="md:hidden" aria-expanded={open} aria-controls={panel} onClick={() => setOpen((value) => !value)}>
          <SlidersHorizontal className="h-4 w-4" aria-hidden="true" />
          {t('common.filters')}
          {activeCount > 0 && <span className="rounded-full bg-brand-700 px-1.5 text-xs text-white">{activeCount}</span>}
        </Button>
      )}
      <div id={panel} className={`${open ? 'grid w-full gap-2 sm:grid-cols-2' : 'hidden'} md:contents`}>
        {children}
        {activeCount > 0 && onClear && (
          <button type="button" onClick={onClear} className="min-h-10 self-center text-left text-sm font-medium text-brand-700 hover:underline md:px-1">
            {t('common.clearFilters')}
          </button>
        )}
      </div>
    </div>
  )
}
