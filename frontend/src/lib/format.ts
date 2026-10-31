const DISPLAY_TIMEZONE = 'Asia/Ho_Chi_Minh'

const dateTime = new Intl.DateTimeFormat('vi-VN', { dateStyle: 'short', timeStyle: 'short', timeZone: DISPLAY_TIMEZONE })
const dateOnly = new Intl.DateTimeFormat('vi-VN', { dateStyle: 'medium', timeZone: DISPLAY_TIMEZONE })

/** Hệ thống lưu giờ UTC; người dùng luôn thấy giờ Việt Nam. */
export function formatDateTime(iso: string | null | undefined): string {
  return iso ? dateTime.format(new Date(iso)) : '—'
}

export function formatDate(iso: string | null | undefined): string {
  return iso ? dateOnly.format(new Date(iso)) : '—'
}

export function formatBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined) return '—'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`
  return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`
}

/** "5 phút trước", "2 giờ trước"... cho thông báo. */
export function formatAgo(iso: string, now: Date = new Date()): string {
  const seconds = Math.max(0, Math.round((now.getTime() - new Date(iso).getTime()) / 1000))
  if (seconds < 60) return 'vừa xong'
  if (seconds < 3600) return `${Math.floor(seconds / 60)} phút trước`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)} giờ trước`
  if (seconds < 86400 * 30) return `${Math.floor(seconds / 86400)} ngày trước`
  return formatDate(iso)
}

const money = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 })

/** Số tiền đồng Việt Nam: 150.000 ₫. */
export function formatVnd(amount: number | null | undefined): string {
  return amount === null || amount === undefined ? '—' : money.format(amount)
}

const quantity = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 3 })

/** Số lượng kho: "500.000" → "500", "12.500" → "12,5" (dấu phẩy thập phân, dấu chấm ngăn nghìn). Chuỗi không phải số giữ nguyên. */
export function formatQuantity(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'

  const n = Number(value)

  return Number.isFinite(n) ? quantity.format(n) : String(value)
}

/** Ngày "2027-06-30" (không có giờ) → "30/06/2027", không lệch múi giờ. */
export function formatIsoDate(date: string | null | undefined): string {
  const match = date ? /^(\d{4})-(\d{2})-(\d{2})/.exec(date) : null

  return match ? `${match[3]}/${match[2]}/${match[1]}` : '—'
}
