/** Danh sách phân trang của API Laravel (`data` + `meta`). */
export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}
