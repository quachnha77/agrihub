import { createContext, useContext } from 'react'
import type { To } from 'react-router'

/** Nút/liên kết kèm thông báo, vd. "Xem phiếu" sau khi ghi một phiếu kho. */
export interface ToastAction {
  label: string
  to: To
  /** `state` của liên kết (vd. dấu hiệu "popup mở bằng liên kết" của lib/dialog.ts). */
  state?: unknown
}

export interface Toaster {
  /** Báo việc vừa làm xong (vd. "Đã thêm sản phẩm …"). Tự ẩn sau vài giây. */
  success: (message: string, action?: ToastAction) => void
  error: (message: string) => void
}

export const ToastContext = createContext<Toaster | null>(null)

/** Thông báo nổi ở góc màn hình: dùng sau khi một hộp thoại đóng lại, để người dùng vẫn biết việc đã xong mà không phải chuyển trang. */
export function useToast(): Toaster {
  const toaster = useContext(ToastContext)

  if (!toaster) throw new Error('useToast phải được dùng bên trong <ToastProvider>.')

  return toaster
}
