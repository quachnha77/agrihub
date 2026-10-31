/** Ngày chênh lệch từ hôm nay (giờ Việt Nam) đến một ngày "YYYY-MM-DD": âm = đã qua. */
export function daysFromToday(isoDate: string, now: Date = new Date()): number {
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Ho_Chi_Minh' }).format(now) // YYYY-MM-DD
  const diff = Date.parse(`${isoDate}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)

  return Math.round(diff / 86_400_000)
}
