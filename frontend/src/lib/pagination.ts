import { useEffect } from 'react'

/**
 * Trang đang xem vượt quá số trang hiện có (vừa xóa mục cuối của trang cuối, hoặc người khác xóa bớt dữ liệu): API trả danh sách
 * rỗng nên giao diện sẽ báo "chưa có dữ liệu" sai. Lùi về trang cuối thay vì để người dùng tưởng dữ liệu đã mất.
 */
export function useClampPage(meta: { current_page: number; last_page: number } | undefined, onPage: (page: number) => void): void {
  const current = meta?.current_page
  const last = meta?.last_page

  useEffect(() => {
    if (current !== undefined && last !== undefined && last >= 1 && current > last) onPage(last)
  }, [current, last, onPage])
}
