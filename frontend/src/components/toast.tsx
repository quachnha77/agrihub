import { CircleAlert, CircleCheck, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { ToastContext, type ToastAction, type Toaster } from '../lib/toast.ts'

interface ToastItem {
  id: number
  tone: 'success' | 'danger'
  message: string
  action?: ToastAction
}

const MAX_VISIBLE = 3

/**
 * Chỗ hiện thông báo nổi. Đặt một lần ở khung ứng dụng (AppLayout) để thông báo còn nguyên khi đổi trang; hiện ở cuối màn hình, không
 * chiếm chỗ trong trang nên không làm trang dịch. Vùng `aria-live` có sẵn trước khi có thông báo để trình đọc màn hình đọc được nội dung mới.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  const [items, setItems] = useState<ToastItem[]>([])
  const nextId = useRef(0)
  const timers = useRef(new Map<number, number>())

  const dismiss = useCallback((id: number) => {
    window.clearTimeout(timers.current.get(id))
    timers.current.delete(id)
    setItems((current) => current.filter((item) => item.id !== id))
  }, [])

  const push = useCallback(
    (item: Omit<ToastItem, 'id'>, ms: number) => {
      const id = ++nextId.current

      setItems((current) => [...current.slice(1 - MAX_VISIBLE), { ...item, id }])
      timers.current.set(id, window.setTimeout(() => dismiss(id), ms))
    },
    [dismiss],
  )

  useEffect(() => {
    const pending = timers.current

    return () => pending.forEach((timer) => window.clearTimeout(timer))
  }, [])

  const toaster = useMemo<Toaster>(
    () => ({
      success: (message, action) => push({ tone: 'success', message, action }, action ? 9000 : 6000),
      error: (message) => push({ tone: 'danger', message }, 9000),
    }),
    [push],
  )

  return (
    <ToastContext value={toaster}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6">
        {items.map((item) => {
          const Icon = item.tone === 'success' ? CircleCheck : CircleAlert

          return (
            <div
              key={item.id}
              className={`pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-xl border px-4 py-3 shadow-pop motion-safe:animate-pop-in ${
                item.tone === 'success' ? 'border-brand-200 bg-brand-50 text-brand-900' : 'border-red-300 bg-red-50 text-red-900'
              }`}
            >
              <Icon className={`mt-0.5 h-5 w-5 shrink-0 ${item.tone === 'success' ? 'text-brand-600' : 'text-red-600'}`} aria-hidden="true" />
              <p className="min-w-0 flex-1 text-sm font-medium">{item.message}</p>
              {item.action && (
                <Link to={item.action.to} state={item.action.state} onClick={() => dismiss(item.id)} className="shrink-0 rounded text-sm font-semibold underline underline-offset-2 hover:no-underline">
                  {item.action.label}
                </Link>
              )}
              <button type="button" onClick={() => dismiss(item.id)} aria-label={t('common.close')} className="-mr-1.5 -mt-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg opacity-70 hover:bg-black/5 hover:opacity-100">
                <X className="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
          )
        })}
      </div>
    </ToastContext>
  )
}
