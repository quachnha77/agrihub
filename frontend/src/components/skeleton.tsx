import { createContext, useContext, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Card } from './ui.tsx'

/*
 * Khung xương (skeleton) giữ chỗ cho nội dung đang tải. Mỗi khung bám đúng kích thước của nội dung thật (chiều cao dòng,
 * ô nhập, nút, chân trang phân trang) để khi dữ liệu về, trang không bị nhảy. Quy ước dùng:
 *   - Lần tải đầu (isPending): hiện skeleton đúng hình của vùng đó, không dùng Spinner.
 *   - Đổi trang/bộ lọc (isPlaceholderData): giữ dữ liệu cũ và làm mờ bằng <Refreshable>, không quay lại skeleton.
 *   - Spinner chỉ còn trong nút đang gửi dữ liệu.
 */

const InsideRegion = createContext(false)

/** Khối xám nhấp nháy (tắt chuyển động nếu người dùng chọn giảm chuyển động). Chỉ để trang trí nên ẩn khỏi trình đọc màn hình. */
export function Skeleton({ className = '' }: { className?: string }) {
  return <div aria-hidden="true" className={`rounded bg-slate-200 motion-safe:animate-pulse ${className}`} />
}

/** Vùng đang tải: đọc đúng một lần "Đang tải…" cho trình đọc màn hình dù bên trong có bao nhiêu khối. */
export function SkeletonRegion({ children, className = '' }: { children: ReactNode; className?: string }) {
  const { t } = useTranslation()
  const nested = useContext(InsideRegion)

  if (nested) return <div className={className}>{children}</div>

  return (
    <InsideRegion value>
      <div role="status" aria-busy="true" className={className}>
        <span className="sr-only">{t('common.loading')}</span>
        {children}
      </div>
    </InsideRegion>
  )
}

/** Làm mờ vùng đang đổi trang/bộ lọc mà vẫn giữ nguyên dữ liệu cũ (TanStack `keepPreviousData`), tránh xẹp rồi bung. */
export function Refreshable({ refreshing, children, className = '' }: { refreshing: boolean; children: ReactNode; className?: string }) {
  return (
    <div aria-busy={refreshing || undefined} className={`transition-opacity ${refreshing ? 'opacity-60' : ''} ${className}`}>
      {children}
    </div>
  )
}

/** Độ rộng xen kẽ để các dòng giữ chỗ trông tự nhiên thay vì thành một khối đều. */
const WIDTHS = ['w-28', 'w-36', 'w-20', 'w-32', 'w-24', 'w-40']
const pick = (index: number): string => WIDTHS[index % WIDTHS.length]

/** Dòng chữ `text-sm`: ô cao đúng 20px chứa thanh xám. Không dùng lề để khỏi bị dồn lề dọc với phần tử kế bên (lệch vài px). */
function TextBar({ width, className = '' }: { width: string; className?: string }) {
  return (
    <div className={`flex h-5 items-center ${className}`}>
      <Skeleton className={`h-3.5 ${width}`} />
    </div>
  )
}

/**
 * Bảng đang tải. `actions`: hàng cuối có nút thao tác (cao hơn hàng chữ thường). Dùng thay cho phần `<table>` thật nên giữ cùng
 * `min-w` để cuộn ngang không đổi. Cố ý KHÔNG có chân trang giả: chân trang nằm dưới phần bảng có chiều cao không đoán trước
 * (2 hay 20 dòng) nên giữ chỗ cũng vẫn bị dời; để chân trang thật chỉ xuất hiện ở cuối thì không có nội dung nào bị đẩy.
 */
export function TableSkeleton({ columns, rows = 8, minWidth = '40rem', actions = false }: { columns: number; rows?: number; minWidth?: string; actions?: boolean }) {
  const cols = Array.from({ length: columns }, (_, index) => index)

  return (
    <SkeletonRegion>
      <div className="overflow-x-auto">
        <table aria-hidden="true" className="w-full text-left text-sm" style={{ minWidth }}>
          <thead className="border-b border-slate-200">
            <tr>
              {cols.map((col) => (
                <th key={col} scope="col" className="py-2 pr-3"><Skeleton className="my-0.5 h-3 w-16" /></th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {Array.from({ length: rows }, (_, row) => (
              <tr key={row}>
                {cols.map((col) => (
                  <td key={col} className="py-2 pr-3">
                    {actions && col === columns - 1 ? <Skeleton className="ml-auto h-9 w-24" /> : <TextBar width={pick(row + col * 2)} />}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </SkeletonRegion>
  )
}

/** Danh sách dạng `<ul>` (thông báo, danh mục): mỗi dòng một tên, tùy chọn có nút thao tác bên phải. */
export function ListSkeleton({ rows = 4, actions = false, subtitle = false }: { rows?: number; actions?: boolean; subtitle?: boolean }) {
  return (
    <SkeletonRegion>
      <ul className="divide-y divide-slate-100">
        {Array.from({ length: rows }, (_, row) => (
          <li key={row} className="flex items-center justify-between gap-2 py-2">
            <div className="min-w-0 flex-1">
              <TextBar width={pick(row)} />
              {subtitle && <Skeleton className={`mt-1 h-3 ${pick(row + 3)}`} />}
            </div>
            {actions && <Skeleton className="h-9 w-24" />}
          </li>
        ))}
      </ul>
    </SkeletonRegion>
  )
}

/** Vài dòng chữ trong một thẻ (tóm tắt trạng thái, thông tin gói...). */
export function LinesSkeleton({ lines = 3 }: { lines?: number }) {
  return (
    <SkeletonRegion className="space-y-1">
      {Array.from({ length: lines }, (_, line) => (
        <TextBar key={line} width={line === lines - 1 ? 'w-1/2' : 'w-full max-w-md'} />
      ))}
    </SkeletonRegion>
  )
}

/** Lưới các thẻ có vài dòng chữ (danh sách vai trò, tình trạng hệ thống). */
export function CardGridSkeleton({ cards = 4, lines = 3 }: { cards?: number; lines?: number }) {
  return (
    <SkeletonRegion className="grid gap-4 lg:grid-cols-2">
      {Array.from({ length: cards }, (_, card) => (
        <Card key={card}>
          <Skeleton className="mb-3 h-5 w-40" />
          <LinesSkeleton lines={lines} />
        </Card>
      ))}
    </SkeletonRegion>
  )
}

/** Danh sách ô tích (vai trò, chi nhánh) trong hộp thoại: ô vuông + tên. */
export function ChecklistSkeleton({ rows = 3 }: { rows?: number }) {
  return (
    <SkeletonRegion className="space-y-2">
      {Array.from({ length: rows }, (_, row) => (
        <div key={row} className="flex items-center gap-2">
          <Skeleton className="h-4 w-4" />
          <TextBar width={pick(row + 1)} />
        </div>
      ))}
    </SkeletonRegion>
  )
}

/** Lưới ô nhập (nhãn + ô): nhãn `text-sm` 20px + 4px, ô nhập `py-2` 38px. */
export function FieldsSkeleton({ fields = 6, columns = 2 }: { fields?: number; columns?: 1 | 2 | 3 }) {
  const grid = columns === 3 ? 'sm:grid-cols-3' : columns === 2 ? 'sm:grid-cols-2' : ''

  return (
    <SkeletonRegion className={`grid gap-4 ${grid}`}>
      {Array.from({ length: fields }, (_, field) => (
        <div key={field}>
          <TextBar className="mb-1" width={field % 2 === 0 ? 'w-24' : 'w-32'} />
          <Skeleton className="h-[38px] w-full" />
        </div>
      ))}
    </SkeletonRegion>
  )
}

/** Hàng thẻ số liệu (biểu tượng tròn + số `text-2xl` + nhãn `text-xs`, cao 76px): giữ chỗ để bảng bên dưới không bị đẩy xuống khi số liệu về. */
export function StatCardsSkeleton({ count = 3 }: { count?: number }) {
  return (
    <SkeletonRegion className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      {Array.from({ length: count }, (_, card) => (
        <div key={card} className="flex min-h-[4.75rem] items-center gap-3 rounded-xl border border-slate-200/80 bg-white p-3.5 shadow-card">
          <Skeleton className="h-10 w-10 shrink-0 rounded-full" />
          <div className="min-w-0 flex-1">
            <div className="flex h-[30px] items-center"><Skeleton className="h-5 w-10" /></div>
            <div className="flex h-4 items-center"><Skeleton className="h-3 w-28 max-w-full" /></div>
          </div>
        </div>
      ))}
    </SkeletonRegion>
  )
}

/** Tiêu đề trang (h1 `text-xl` 28px + mô tả 20px), tùy chọn có nút hành động bên phải. */
export function PageHeaderSkeleton({ action = false }: { action?: boolean }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div>
        <Skeleton className="h-7 w-48" />
        <Skeleton className="mt-2 h-4 w-72 max-w-full" />
      </div>
      {action && <Skeleton className="h-9 w-32" />}
    </div>
  )
}

/** Trang danh sách: tiêu đề + thanh lọc + bảng. */
export function ListPageSkeleton({ columns = 5, action = true }: { columns?: number; action?: boolean }) {
  return (
    <SkeletonRegion className="space-y-4">
      <PageHeaderSkeleton action={action} />
      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-3">
          {[0, 1, 2].map((field) => (
            <div key={field}>
              <TextBar className="mb-1" width="w-20" />
              <Skeleton className="h-[38px] w-full" />
            </div>
          ))}
        </div>
        <TableSkeleton columns={columns} actions />
      </Card>
    </SkeletonRegion>
  )
}

/** Trang biểu mẫu (thêm/sửa): tiêu đề + một thẻ các ô nhập + hàng nút. */
export function FormPageSkeleton({ fields = 8 }: { fields?: number }) {
  return (
    <SkeletonRegion className="space-y-4">
      <PageHeaderSkeleton />
      <Card>
        <FieldsSkeleton fields={fields} />
      </Card>
      <div className="flex justify-end gap-2">
        <Skeleton className="h-9 w-20" />
        <Skeleton className="h-9 w-24" />
      </div>
    </SkeletonRegion>
  )
}

/** Trang chi tiết: tiêu đề + thẻ thông tin + thẻ bảng chi tiết. */
export function DetailPageSkeleton({ columns = 5 }: { columns?: number }) {
  return (
    <SkeletonRegion className="space-y-4">
      <PageHeaderSkeleton />
      <Card>
        <LinesSkeleton lines={4} />
      </Card>
      <Card>
        <TableSkeleton columns={columns} rows={4} />
      </Card>
    </SkeletonRegion>
  )
}

/**
 * Khung cả ứng dụng (thanh trên + menu + nội dung) khi đang xác định phiên đăng nhập hoặc tải trang lần đầu. Cùng kích thước
 * với <AppLayout> để lúc vào trang thật, thanh trên và menu đứng yên, chỉ phần nội dung đổi.
 */
export function AppShellSkeleton() {
  const { t } = useTranslation()

  return (
    <SkeletonRegion className="min-h-screen bg-slate-50">
      <header className="flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4">
        <span className="text-lg font-bold text-brand-800">{t('app.name')}</span>
        <Skeleton className="ml-auto h-5 w-32" />
      </header>
      <div className="flex">
        <nav className="hidden p-2 md:block md:min-h-[calc(100vh-3.5rem)] md:w-56 md:shrink-0 md:border-r md:border-slate-200 md:bg-white">
          <ul className="space-y-1">
            {Array.from({ length: 7 }, (_, item) => (
              <li key={item} className="flex h-9 items-center gap-2 px-3">
                <Skeleton className="h-4 w-4" />
                <Skeleton className={`h-3.5 ${pick(item)}`} />
              </li>
            ))}
          </ul>
        </nav>
        <main className="min-w-0 flex-1 p-4 md:p-6">
          <ListPageSkeleton />
        </main>
      </div>
    </SkeletonRegion>
  )
}
