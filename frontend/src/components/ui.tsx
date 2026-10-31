import { ChevronDown, ChevronLeft, ChevronRight, CircleAlert, CircleCheck, Info, TriangleAlert, X, type LucideIcon } from 'lucide-react'
import { useEffect, useId, useRef, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type Ref, type SelectHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, type LinkProps } from 'react-router'
import { buttonStyles, fieldBase, fieldTone, iconButtonStyles, type ButtonSize, type ButtonVariant } from './styles.ts'

export type Tone = 'info' | 'success' | 'warning' | 'danger' | 'neutral'

const toneClasses: Record<Tone, string> = {
  info: 'border-sky-200 bg-sky-50 text-sky-900',
  success: 'border-brand-200 bg-brand-50 text-brand-900',
  warning: 'border-amber-300 bg-amber-50 text-amber-900',
  danger: 'border-red-300 bg-red-50 text-red-900',
  neutral: 'border-slate-200 bg-slate-50 text-slate-700',
}

const toneIcons: Record<Tone, LucideIcon> = { info: Info, success: CircleCheck, warning: TriangleAlert, danger: CircleAlert, neutral: Info }

const badgeClasses: Record<Tone, string> = {
  info: 'bg-sky-50 text-sky-800 ring-sky-600/20',
  success: 'bg-brand-50 text-brand-800 ring-brand-600/20',
  warning: 'bg-amber-50 text-amber-900 ring-amber-600/25',
  danger: 'bg-red-50 text-red-800 ring-red-600/20',
  neutral: 'bg-slate-100 text-slate-700 ring-slate-500/15',
}

const dotClasses: Record<Tone, string> = { info: 'bg-sky-500', success: 'bg-brand-500', warning: 'bg-amber-500', danger: 'bg-red-500', neutral: 'bg-slate-400' }

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: ButtonSize
  loading?: boolean
}

export function Button({ variant = 'primary', size = 'md', loading = false, disabled, className = '', children, type = 'button', ...rest }: ButtonProps) {
  return (
    <button type={type} disabled={disabled || loading} aria-busy={loading || undefined} className={buttonStyles(variant, size, className)} {...rest}>
      {loading && <Spinner className="h-4 w-4" />}
      {children}
    </button>
  )
}

/** Liên kết (react-router) trông như một nút: dùng cho hành động điều hướng như "Thêm sản phẩm". */
export function ButtonLink({ variant = 'primary', size = 'md', className = '', ...rest }: LinkProps & { variant?: ButtonVariant; size?: ButtonSize }) {
  return <Link className={buttonStyles(variant, size, className)} {...rest} />
}

interface IconButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  /** Tên đầy đủ cho trình đọc màn hình và chú giải khi rê chuột (vd. "Sửa Phân NPK"). */
  label: string
  /** Chữ ngắn hiện cạnh biểu tượng trong thẻ trên điện thoại (vd. "Sửa"); trên máy tính chỉ có biểu tượng. */
  text?: string
  tone?: 'neutral' | 'danger'
}

/** Nút chỉ có biểu tượng (sửa, xóa, khóa...): có chú giải khi rê chuột; trên điện thoại hiện thêm chữ ngắn cạnh biểu tượng. */
export function IconButton({ label, text, tone = 'neutral', className = '', children, type = 'button', ...rest }: IconButtonProps) {
  return (
    <button type={type} aria-label={label} title={label} className={iconButtonStyles(tone, className)} {...rest}>
      {children}
      {text && <span className="hidden whitespace-nowrap text-sm font-medium max-md:inline">{text}</span>}
    </button>
  )
}

/** Liên kết có giao diện như <IconButton> (vd. "Sửa" mở trang biểu mẫu). */
export function IconLink({ label, text, className = '', children, ...rest }: LinkProps & { label: string; text?: string }) {
  return (
    <Link aria-label={label} title={label} className={iconButtonStyles('neutral', className)} {...rest}>
      {children}
      {text && <span className="hidden whitespace-nowrap text-sm font-medium max-md:inline">{text}</span>}
    </Link>
  )
}

export function Spinner({ className = 'h-5 w-5' }: { className?: string }) {
  return (
    <svg className={`animate-spin ${className}`} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
      <path d="M4 12a8 8 0 0 1 8-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
    </svg>
  )
}

export function Alert({ tone = 'info', title, children, className = '' }: { tone?: Tone; title?: string; children?: ReactNode; className?: string }) {
  const Icon = toneIcons[tone]

  return (
    <div role={tone === 'danger' || tone === 'warning' ? 'alert' : 'status'} className={`flex gap-3 rounded-lg border px-4 py-3 text-sm ${toneClasses[tone]} ${className}`}>
      <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <div className="min-w-0 flex-1">
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className={title ? 'mt-1' : ''}>{children}</div>}
      </div>
    </div>
  )
}

export function Badge({ tone = 'neutral', dot = false, children }: { tone?: Tone; /** Chấm màu đầu nhãn: dùng cho trạng thái. */ dot?: boolean; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${badgeClasses[tone]}`}>
      {dot && <span className={`h-1.5 w-1.5 rounded-full ${dotClasses[tone]}`} aria-hidden="true" />}
      {children}
    </span>
  )
}

/** Chữ cái đầu của hai từ cuối trong tên ("Nguyễn Văn An" → "VA"): người Việt gọi bằng tên nên lấy từ cuối. */
function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean)

  return (words.length > 1 ? words.slice(-2) : words).map((word) => word[0]?.toUpperCase() ?? '').join('')
}

/** Ảnh đại diện bằng chữ cái đầu của tên. */
export function Avatar({ name, className = 'h-8 w-8 text-xs' }: { name: string; className?: string }) {
  return (
    <span aria-hidden="true" className={`inline-flex shrink-0 items-center justify-center rounded-full bg-brand-100 font-semibold text-brand-800 ${className}`}>
      {initials(name) || '?'}
    </span>
  )
}

export function Card({ title, actions, children, className = '' }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`rounded-xl border border-slate-200/80 bg-white shadow-card ${className}`}>
      {(title || actions) && (
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3.5">
          {title && <h2 className="text-base font-semibold text-slate-900">{title}</h2>}
          {actions}
        </header>
      )}
      <div className="p-5">{children}</div>
    </section>
  )
}

interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  error?: string
  hint?: string
  /** Nút đặt sát mép phải trong ô (vd. hiện/ẩn mật khẩu). */
  trailing?: ReactNode
  /** `ref` của react-hook-form (React 19 nhận ref như một prop thường). */
  ref?: Ref<HTMLInputElement>
}

export function TextField({ label, error, hint, id, className = '', trailing, ref, ...rest }: TextFieldProps) {
  const autoId = useId()
  const inputId = id ?? autoId
  const describedBy = [error ? `${inputId}-error` : null, hint ? `${inputId}-hint` : null].filter(Boolean).join(' ') || undefined

  return (
    <div className={className}>
      <label htmlFor={inputId} className="mb-1.5 block text-sm font-medium text-slate-800">
        {label}
      </label>
      <div className="relative">
        <input id={inputId} ref={ref} aria-invalid={error ? true : undefined} aria-describedby={describedBy} className={`${fieldBase} ${fieldTone(Boolean(error))} ${trailing ? 'pr-11' : ''}`} {...rest} />
        {trailing && <div className="absolute inset-y-0 right-1 flex items-center">{trailing}</div>}
      </div>
      {hint && !error && (
        <p id={`${inputId}-hint`} className="mt-1.5 text-xs text-slate-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${inputId}-error`} role="alert" className="mt-1.5 text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  )
}

interface SelectFieldProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string
  error?: string
  hint?: string
  /** `ref` của react-hook-form (React 19 nhận ref như một prop thường). */
  ref?: Ref<HTMLSelectElement>
}

/** Ô chọn một giá trị trong danh sách, cùng kiểu và cách báo lỗi với TextField. Mũi tên vẽ riêng để giống nhau trên mọi hệ điều hành. */
export function SelectField({ label, error, hint, id, className = '', children, ref, ...rest }: SelectFieldProps) {
  const autoId = useId()
  const selectId = id ?? autoId
  const describedBy = [error ? `${selectId}-error` : null, hint ? `${selectId}-hint` : null].filter(Boolean).join(' ') || undefined

  return (
    <div className={className}>
      <label htmlFor={selectId} className="mb-1.5 block text-sm font-medium text-slate-800">
        {label}
      </label>
      <div className="relative">
        <select id={selectId} ref={ref} aria-invalid={error ? true : undefined} aria-describedby={describedBy} className={`${fieldBase} appearance-none pr-10 ${fieldTone(Boolean(error))}`} {...rest}>
          {children}
        </select>
        <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
      </div>
      {hint && !error && (
        <p id={`${selectId}-hint`} className="mt-1.5 text-xs text-slate-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${selectId}-error`} role="alert" className="mt-1.5 text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  )
}

/** Hộp xác nhận cho thao tác khó hoàn tác (khóa, xóa, cấp mật khẩu...). Lỗi hiện ngay trong hộp để người dùng đọc được. */
export function ConfirmDialog({
  title,
  children,
  confirmLabel,
  danger = false,
  loading = false,
  error,
  onConfirm,
  onClose,
}: {
  title: string
  children: ReactNode
  confirmLabel: string
  danger?: boolean
  loading?: boolean
  error?: string | null
  onConfirm: () => void
  onClose: () => void
}) {
  const { t } = useTranslation()

  return (
    <Modal title={title} onClose={onClose}>
      <div className="space-y-4">
        <div className="text-sm text-slate-700">{children}</div>
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant={danger ? 'danger' : 'primary'} onClick={onConfirm} loading={loading}>{confirmLabel}</Button>
        </div>
      </div>
    </Modal>
  )
}

/** Trước / Sau cho danh sách phân trang. Chân trang luôn cao bằng một hàng nút (kể cả khi chỉ có một trang) nên không đổi chiều cao khi sang trang. */
export function Pagination({ page, lastPage, total, onPage }: { page: number; lastPage: number; total: number; onPage: (page: number) => void }) {
  const { t } = useTranslation()

  if (lastPage <= 1) return <p className="flex min-h-12 items-center pt-3 text-xs text-slate-500">{t('common.total', { count: total })}</p>

  return (
    <div className="flex min-h-12 items-center justify-between gap-2 pt-3 text-sm">
      <span className="text-xs text-slate-500">{t('common.total', { count: total })} · {t('common.page', { page, last: lastPage })}</span>
      <div className="flex gap-2">
        <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          <ChevronLeft className="h-4 w-4" aria-hidden="true" />
          {t('common.previous')}
        </Button>
        <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
          {t('common.next')}
          <ChevronRight className="h-4 w-4" aria-hidden="true" />
        </Button>
      </div>
    </div>
  )
}

/** Hộp tích có nhãn, dùng cho danh sách chọn nhiều (vai trò, chi nhánh). Cả nhãn đều bấm được. */
export function CheckboxField({ label, description, checked, onChange, disabled }: { label: string; description?: string; checked: boolean; onChange: (checked: boolean) => void; disabled?: boolean }) {
  const id = useId()

  return (
    <div className="flex items-start gap-2.5">
      <input id={id} type="checkbox" checked={checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} className="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-300 accent-brand-700" />
      <label htmlFor={id} className="text-sm text-slate-800">
        {label}
        {description && <span className="block text-xs text-slate-500">{description}</span>}
      </label>
    </div>
  )
}

/**
 * Hộp thoại: đóng bằng Esc hoặc bấm nền; focus vào hộp khi mở và trả lại khi đóng; khóa cuộn trang nền. Trên điện thoại hiện như tấm
 * trượt lên từ cạnh dưới (gần ngón cái), trên máy tính ở giữa màn hình.
 */
export function Modal({ title, onClose, children, size = 'md' }: { title: string; onClose: () => void; children: ReactNode; size?: 'md' | 'lg' | 'xl' }) {
  const { t } = useTranslation()
  const titleId = useId()
  const panel = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    const overflow = document.body.style.overflow
    panel.current?.focus()
    document.body.style.overflow = 'hidden'

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)

    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = overflow
      previous?.focus?.()
    }
  }, [onClose])

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 motion-safe:animate-fade-in sm:items-center sm:p-4" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        className={`max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white shadow-pop outline-none motion-safe:animate-sheet-in sm:rounded-2xl sm:motion-safe:animate-pop-in ${size === 'xl' ? 'sm:max-w-4xl' : size === 'lg' ? 'sm:max-w-2xl' : 'sm:max-w-md'}`}
      >
        <div className="sticky top-0 z-10 flex items-center justify-between rounded-t-2xl border-b border-slate-100 bg-white px-5 py-3.5">
          <h2 id={titleId} className="text-base font-semibold text-slate-900">
            {title}
          </h2>
          <button type="button" onClick={onClose} aria-label={t('common.close')} className="-mr-2 inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900">
            <X className="h-5 w-5" aria-hidden="true" />
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  )
}
