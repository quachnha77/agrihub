import { useEffect, useRef, type CSSProperties, type ReactNode } from 'react'

/**
 * Gắn nhãn cột cho từng ô dữ liệu (thuộc tính data-label) và chuyển vai trò của cột (data-role) từ ô tiêu đề xuống các ô của cột đó.
 * Trên điện thoại bảng hiện thành thẻ (xem `.data-table` trong index.css) nên mỗi ô phải tự biết nó là "Giá tiền mặt", "Trạng thái"...
 * Cột chỉ có chữ ẩn (sr-only), ví dụ cột nút thao tác, không có nhãn.
 */
function labelCells(table: HTMLTableElement): void {
  const head = table.tHead?.rows[0]
  if (!head) return

  const columns = Array.from(head.cells).map((th) => ({
    label: th.dataset.label ?? (th.querySelector('.sr-only') ? '' : (th.textContent ?? '').trim()),
    role: th.dataset.role ?? '',
  }))

  for (const body of Array.from(table.tBodies)) {
    for (const row of Array.from(body.rows)) {
      Array.from(row.cells).forEach((cell, index) => {
        const column = columns[index]
        if (!column || cell.colSpan > 1) return

        if (cell.dataset.label !== column.label) cell.dataset.label = column.label
        if (column.role && cell.dataset.role !== column.role) cell.dataset.role = column.role
      })
    }
  }
}

/**
 * Bảng dữ liệu dùng chung: bảng thường trên máy tính (cuộn ngang nếu hẹp), thẻ hai cột trên điện thoại. Đánh dấu cột quan trọng bằng
 * `data-role` trên <th>: "title" (tên, đứng đầu thẻ), "actions" (hàng nút cuối thẻ), "select" (ô tích góc thẻ), "wide" (chiếm cả dòng).
 * `variant="form"`: bảng nhập liệu, trên điện thoại mỗi ô một dòng với nhãn phía trên.
 */
export function DataTable({ minWidth = '40rem', variant = 'list', children }: { minWidth?: string; variant?: 'list' | 'form'; children: ReactNode }) {
  const table = useRef<HTMLTableElement>(null)

  useEffect(() => {
    const element = table.current
    if (!element) return

    let timer: ReturnType<typeof setTimeout> | undefined
    const run = () => {
      clearTimeout(timer)
      timer = setTimeout(() => labelCells(element), 0)
    }

    labelCells(element)
    // Dòng/ô thêm vào sau (đổi trang, thêm dòng trong biểu mẫu) cũng được gắn nhãn.
    const observer = new MutationObserver(run)
    observer.observe(element, { childList: true, subtree: true })

    return () => {
      observer.disconnect()
      clearTimeout(timer)
    }
  }, [])

  return (
    <div className="overflow-x-auto">
      <table ref={table} className={`data-table w-full text-left text-sm ${variant === 'form' ? 'data-table-form' : ''}`} style={{ '--min-w': minWidth } as CSSProperties}>
        {children}
      </table>
    </div>
  )
}
