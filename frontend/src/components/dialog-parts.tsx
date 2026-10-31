import { ChevronDown } from 'lucide-react'
import { useId, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type { UnsavedGuard } from '../lib/unsaved.ts'
import { FieldsSkeleton, Skeleton, SkeletonRegion } from './skeleton.tsx'
import { Button, Modal } from './ui.tsx'

/** Một nhóm ô trong biểu mẫu của popup: tiêu đề nhỏ + (mô tả) + các ô, ngăn với nhóm trước bằng đường kẻ mảnh (nhẹ hơn thẻ lồng thẻ). */
export function FormSection({ title, description, children }: { title: ReactNode; description?: ReactNode; children: ReactNode }) {
  return (
    <section className="space-y-4 border-t border-slate-100 pt-5 first:border-t-0 first:pt-0">
      <div>
        <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
        {description && <p className="mt-0.5 text-sm text-slate-600">{description}</p>}
      </div>
      {children}
    </section>
  )
}

/**
 * Nhóm ô thu gọn ("Thông tin thêm — không bắt buộc"): người dùng chỉ thấy các ô cần thiết, ô ít dùng nằm sau một dòng bấm mở. Nội dung vẫn nằm
 * trong biểu mẫu (chỉ ẩn bằng `hidden`) nên giá trị đã nhập không mất khi gập lại. `forceOpen`: tự mở khi có lỗi ở một ô bên trong, để người dùng
 * thấy được lỗi thay vì bấm Lưu mà không biết tại sao không được.
 */
export function Disclosure({ title, hint, forceOpen = false, children }: { title: string; hint?: string; forceOpen?: boolean; children: ReactNode }) {
  const [open, setOpen] = useState(false)
  const id = useId()
  const shown = open || forceOpen

  return (
    <section className="border-t border-slate-100 pt-5">
      <button
        type="button"
        aria-expanded={shown}
        aria-controls={id}
        onClick={() => setOpen(!shown)}
        className="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
      >
        <span>
          <span className="block text-sm font-semibold text-slate-900">{title}</span>
          {hint && <span className="block text-sm text-slate-600">{hint}</span>}
        </span>
        <ChevronDown className={`h-5 w-5 shrink-0 text-slate-500 transition-transform ${shown ? 'rotate-180' : ''}`} aria-hidden="true" />
      </button>
      <div id={id} hidden={!shown} className="mt-4">
        {children}
      </div>
    </section>
  )
}

/**
 * Giữ chỗ cho biểu mẫu trong popup trong lúc tải danh sách chọn: khung popup hiện ngay, ruột là các ô xám. Có cùng phần đầu với biểu mẫu thật
 * (dòng chú thích dấu *, [thanh chọn loại], tiêu đề nhóm) để khi biểu mẫu thay vào, các ô không bị đẩy xuống.
 * `tabs`: biểu mẫu có thanh chọn loại ở đầu (lập phiếu kho).
 */
export function DialogSkeleton({ fields = 8, tabs = false }: { fields?: number; tabs?: boolean }) {
  return (
    <SkeletonRegion className="space-y-5">
      <div className="flex h-4 items-center">
        <Skeleton className="h-3 w-44" />
      </div>
      {tabs && <Skeleton className="h-11 w-full max-w-md rounded-xl" />}
      <div className="space-y-4">
        <div className="flex h-5 items-center">
          <Skeleton className="h-3.5 w-28" />
        </div>
        <FieldsSkeleton fields={fields} />
      </div>
    </SkeletonRegion>
  )
}

/**
 * Dòng mô tả của popup SỬA: tên bản ghi đang sửa (+ nhãn), luôn cao đúng một dòng. Đang tải thì là thanh xám cùng chiều cao: nếu tiêu đề đổi cỡ khi
 * dữ liệu về (tên dài xuống dòng, thêm mô tả) thì cả biểu mẫu bị đẩy xuống, nhất là trên điện thoại.
 */
export function DialogSubject({ name, badge }: { name?: string; badge?: ReactNode }) {
  if (name === undefined) return <span aria-hidden="true" className="block h-5 w-56 max-w-full rounded bg-slate-200 motion-safe:animate-pulse" />

  return (
    <span className="flex h-5 items-center gap-2">
      <span className="truncate font-medium text-slate-800">{name}</span>
      {badge}
    </span>
  )
}

/** Chú thích dấu * ở đầu biểu mẫu: người dùng biết ô nào bắt buộc ngay từ đầu thay vì chờ báo lỗi. */
export function RequiredNote() {
  const { t } = useTranslation()

  return <p className="text-xs text-slate-500">{t('dialogs.required')}</p>
}

/**
 * Hộp "Bỏ thay đổi chưa lưu?" khi người dùng định rời popup đang sửa dở (xem `useUnsavedGuard`). Nút an toàn ("Tiếp tục nhập") là nút chính
 * và là nút nhận focus đầu tiên; bấm nền hoặc Esc cũng là ở lại.
 */
export function UnsavedChangesDialog({ guard }: { guard: UnsavedGuard }) {
  const { t } = useTranslation()
  const { blocker } = guard

  if (blocker.state !== 'blocked') return null

  return (
    <Modal title={t('dialogs.discardTitle')} onClose={() => blocker.reset()}>
      <div className="space-y-4">
        <p className="text-sm text-slate-700">{t('dialogs.discardText')}</p>
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="danger" onClick={() => blocker.proceed()}>{t('dialogs.discard')}</Button>
          <Button autoFocus onClick={() => blocker.reset()}>{t('dialogs.keepEditing')}</Button>
        </div>
      </div>
    </Modal>
  )
}
