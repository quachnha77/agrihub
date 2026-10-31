import { useEffect, useRef } from 'react'
import { useBlocker, type Blocker } from 'react-router'

export interface UnsavedGuard {
  /** `state === 'blocked'` khi người dùng định rời đi (đóng popup, nút Quay lại, bấm liên kết...) mà biểu mẫu còn dữ liệu chưa lưu. */
  blocker: Blocker
  /** Gọi ngay trước khi đóng popup sau khi LƯU THÀNH CÔNG, để không hỏi "Bỏ thay đổi?". */
  release: () => void
}

/**
 * Chặn việc rời khỏi biểu mẫu đang sửa dở: mọi lối thoát (Hủy, X, Esc, nút Quay lại của điện thoại, liên kết) đều đi qua điều hướng của
 * router nên một chỗ chặn là đủ; hiện hộp xác nhận bằng `<UnsavedChangesDialog>`. Tải lại/đóng tab thì dùng hộp hỏi của trình duyệt.
 */
export function useUnsavedGuard(dirty: boolean): UnsavedGuard {
  const dirtyRef = useRef(dirty)
  const released = useRef(false)

  useEffect(() => {
    dirtyRef.current = dirty
  })

  const blocker = useBlocker(() => dirtyRef.current && !released.current)

  useEffect(() => {
    if (!dirty) return

    const warn = (event: BeforeUnloadEvent) => {
      if (!released.current) event.preventDefault()
    }
    window.addEventListener('beforeunload', warn)

    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  return {
    blocker,
    release: () => {
      released.current = true
    },
  }
}
