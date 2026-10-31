import { X } from 'lucide-react'
import { useEffect, useId, useRef, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type Ref, type SelectHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'

export type Tone = 'info' | 'success' | 'warning' | 'danger' | 'neutral'

const toneClasses: Record<Tone, string> = {
  info: 'border-sky-200 bg-sky-50 text-sky-900',
  success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
  warning: 'border-amber-300 bg-amber-50 text-amber-900',
  danger: 'border-red-300 bg-red-50 text-red-900',
  neutral: 'border-slate-200 bg-slate-50 text-slate-700',
}

const badgeClasses: Record<Tone, string> = {
  info: 'bg-sky-100 text-sky-800',
  success: 'bg-emerald-100 text-emerald-800',
  warning: 'bg-amber-100 text-amber-900',
  danger: 'bg-red-100 text-red-800',
  neutral: 'bg-slate-100 text-slate-700',
}

type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost'

const buttonClasses: Record<ButtonVariant, string> = {
  primary: 'bg-emerald-700 text-white hover:bg-emerald-800 focus-visible:outline-emerald-700',
  secondary: 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50 focus-visible:outline-slate-500',
  danger: 'bg-red-700 text-white hover:bg-red-800 focus-visible:outline-red-700',
  ghost: 'text-slate-700 hover:bg-slate-100 focus-visible:outline-slate-500',
}

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  loading?: boolean
}

export function Button({ variant = 'primary', loading = false, disabled, className = '', children, type = 'button', ...rest }: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={`inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ${buttonClasses[variant]} ${className}`}
      {...rest}
    >
      {loading && <Spinner className="h-4 w-4" />}
      {children}
    </button>
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

export function FullPageSpinner() {
  const { t } = useTranslation()

  return (
    <div role="status" className="flex min-h-screen items-center justify-center gap-3 text-slate-600">
      <Spinner />
      <span>{t('common.loading')}</span>
    </div>
  )
}

export function Alert({ tone = 'info', title, children, className = '' }: { tone?: Tone; title?: string; children?: ReactNode; className?: string }) {
  return (
    <div role={tone === 'danger' || tone === 'warning' ? 'alert' : 'status'} className={`rounded-md border px-4 py-3 text-sm ${toneClasses[tone]} ${className}`}>
      {title && <p className="font-semibold">{title}</p>}
      {children && <div className={title ? 'mt-1' : ''}>{children}</div>}
    </div>
  )
}

export function Badge({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  return <span className={`inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ${badgeClasses[tone]}`}>{children}</span>
}

export function Card({ title, actions, children, className = '' }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`rounded-lg border border-slate-200 bg-white shadow-sm ${className}`}>
      {(title || actions) && (
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
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
  /** `ref` của react-hook-form (React 19 nhận ref như một prop thường). */
  ref?: Ref<HTMLInputElement>
}

export function TextField({ label, error, hint, id, className = '', ref, ...rest }: TextFieldProps) {
  const autoId = useId()
  const inputId = id ?? autoId
  const describedBy = [error ? `${inputId}-error` : null, hint ? `${inputId}-hint` : null].filter(Boolean).join(' ') || undefined

  return (
    <div className={className}>
      <label htmlFor={inputId} className="mb-1 block text-sm font-medium text-slate-800">
        {label}
      </label>
      <input
        id={inputId}
        ref={ref}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        className={`block w-full rounded-md border px-3 py-2 text-sm shadow-sm outline-none transition-colors focus:ring-2 ${
          error ? 'border-red-500 focus:ring-red-200' : 'border-slate-300 focus:border-emerald-600 focus:ring-emerald-200'
        }`}
        {...rest}
      />
      {hint && !error && (
        <p id={`${inputId}-hint`} className="mt-1 text-xs text-slate-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${inputId}-error`} role="alert" className="mt-1 text-sm text-red-700">
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

/** Ô chọn một giá trị trong danh sách, cùng kiểu và cách báo lỗi với TextField. */
export function SelectField({ label, error, hint, id, className = '', children, ref, ...rest }: SelectFieldProps) {
  const autoId = useId()
  const selectId = id ?? autoId
  const describedBy = [error ? `${selectId}-error` : null, hint ? `${selectId}-hint` : null].filter(Boolean).join(' ') || undefined

  return (
    <div className={className}>
      <label htmlFor={selectId} className="mb-1 block text-sm font-medium text-slate-800">
        {label}
      </label>
      <select
        id={selectId}
        ref={ref}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        className={`block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-sm outline-none transition-colors focus:ring-2 disabled:bg-slate-100 disabled:text-slate-500 ${
          error ? 'border-red-500 focus:ring-red-200' : 'border-slate-300 focus:border-emerald-600 focus:ring-emerald-200'
        }`}
        {...rest}
      >
        {children}
      </select>
      {hint && !error && (
        <p id={`${selectId}-hint`} className="mt-1 text-xs text-slate-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${selectId}-error`} role="alert" className="mt-1 text-sm text-red-700">
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

/** Trước / Sau cho danh sách phân trang. */
export function Pagination({ page, lastPage, total, onPage }: { page: number; lastPage: number; total: number; onPage: (page: number) => void }) {
  const { t } = useTranslation()

  if (lastPage <= 1) return <p className="pt-3 text-xs text-slate-500">{t('common.total', { count: total })}</p>

  return (
    <div className="flex items-center justify-between gap-2 pt-3 text-sm">
      <span className="text-xs text-slate-500">{t('common.total', { count: total })} · {t('common.page', { page, last: lastPage })}</span>
      <div className="flex gap-2">
        <Button variant="secondary" disabled={page <= 1} onClick={() => onPage(page - 1)}>{t('common.previous')}</Button>
        <Button variant="secondary" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>{t('common.next')}</Button>
      </div>
    </div>
  )
}

/** Hộp tích có nhãn, dùng cho danh sách chọn nhiều (vai trò, chi nhánh). */
export function CheckboxField({ label, description, checked, onChange, disabled }: { label: string; description?: string; checked: boolean; onChange: (checked: boolean) => void; disabled?: boolean }) {
  const id = useId()

  return (
    <div className="flex items-start gap-2">
      <input id={id} type="checkbox" checked={checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} className="mt-1 h-4 w-4 rounded border-slate-300 text-emerald-700" />
      <label htmlFor={id} className="text-sm text-slate-800">
        {label}
        {description && <span className="block text-xs text-slate-500">{description}</span>}
      </label>
    </div>
  )
}

/** Hộp thoại: đóng bằng Esc hoặc bấm nền; focus vào hộp khi mở và trả lại khi đóng. */
export function Modal({ title, onClose, children, size = 'md' }: { title: string; onClose: () => void; children: ReactNode; size?: 'md' | 'lg' }) {
  const { t } = useTranslation()
  const titleId = useId()
  const panel = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    panel.current?.focus()

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)

    return () => {
      document.removeEventListener('keydown', onKey)
      previous?.focus?.()
    }
  }, [onClose])

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div ref={panel} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1} className={`max-h-[90vh] w-full overflow-y-auto rounded-lg bg-white shadow-xl outline-none ${size === 'lg' ? 'max-w-2xl' : 'max-w-md'}`}>
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
          <h2 id={titleId} className="text-base font-semibold">
            {title}
          </h2>
          <button type="button" onClick={onClose} aria-label={t('common.close')} className="rounded p-1 text-slate-500 hover:bg-slate-100">
            <X className="h-5 w-5" aria-hidden="true" />
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  )
}
