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
