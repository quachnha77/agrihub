/** Đếm số bộ lọc đang bật (khác rỗng), bỏ qua các khóa không phải bộ lọc như từ khóa tìm kiếm và số trang. */
export function countActiveFilters(filters: object, ignore: string[] = ['q', 'page']): number {
  return Object.entries(filters).filter(([key, value]) => !ignore.includes(key) && String(value ?? '') !== '').length
}
