import { useCallback } from 'react'
import { useLocation, useNavigate, useSearchParams, type To } from 'react-router'

/*
 * Popup (hộp thoại biểu mẫu) mở bằng tham số `?dialog=` trên địa chỉ của trang đang xem:
 *   `?dialog=product`           thêm sản phẩm        `?dialog=product:<id>`     sửa/xem sản phẩm
 *   `?dialog=stock-new:receipt` lập phiếu nhập kho   `?dialog=stock-doc:<id>`  xem phiếu kho
 *   `?dialog=stocktake-new`     bắt đầu kiểm kê      `?dialog=stocktake:<id>`   đếm / xem một đợt kiểm kê
 *   `?dialog=transfer-new`      chuyển kho           `?dialog=transfer:<id>`    xem một phiếu chuyển kho
 *   `?dialog=purchase-new`      nhập hàng            `?dialog=purchase:<id>`    xem một phiếu nhập hàng   `?dialog=purchase-pay:<id>`  trả tiền nhà cung cấp
 *   `?dialog=sale-new`          bán hàng             `?dialog=sale:<id>`        xem một phiếu bán hàng    `?dialog=sale-pay:<id>`      thu tiền của khách
 *   `?dialog=sale-return:<id>`  khách trả hàng của một phiếu bán (id phiếu): hàng nhập lại kho, trừ nợ hoặc hoàn tiền
 *   `?dialog=debt-pay:<id>`     thu nợ của một khách (id khách): một khoản tiền trừ vào nhiều phiếu, phiếu đến hạn sớm nhất trước
 * Mở được trên MỌI trang (kể cả Tổng quan) mà trang phía sau vẫn nguyên: không chuyển trang, giữ bộ lọc và vị trí cuộn. Vì nằm trên địa chỉ
 * nên nút Quay lại của trình duyệt/điện thoại đóng popup, tải lại trang vẫn mở đúng popup, và gửi liên kết cho người khác được.
 */

const PARAM = 'dialog'

export type DialogName = 'product' | 'customer' | 'supplier' | 'stock-new' | 'stock-doc' | 'stocktake-new' | 'stocktake' | 'transfer-new' | 'transfer' | 'purchase-new' | 'purchase' | 'purchase-pay' | 'sale-new' | 'sale' | 'sale-pay' | 'sale-return' | 'debt-pay'

/** Thuộc tính mà mọi popup nhận từ khung (DialogHost). `arg` là phần sau dấu ":" (mã bản ghi cần sửa/xem, hoặc loại phiếu). */
export interface DialogProps {
  arg?: string
  onClose: () => void
}

export interface DialogRef {
  name: string
  arg?: string
}

export function parseDialog(value: string | null): DialogRef | null {
  if (!value) return null

  const split = value.indexOf(':')
  const name = split === -1 ? value : value.slice(0, split)
  const arg = split === -1 ? '' : value.slice(split + 1)

  return name ? { name, arg: arg || undefined } : null
}

/** Dấu hiệu "popup này do một liên kết trong ứng dụng mở": đóng popup thì lùi về đúng chỗ vừa bấm thay vì thêm một mục lịch sử. */
interface DialogState {
  dialog?: boolean
}

/** Chuỗi tham số cho địa chỉ; dấu ":" hợp lệ trong tham số nên giữ nguyên cho dễ đọc (`stock-new:receipt`, không phải `stock-new%3Areceipt`). */
const stringify = (params: URLSearchParams) => params.toString().replace(/%3A/gi, ':')

export function useDialog() {
  const [params] = useSearchParams()
  const location = useLocation()
  const navigate = useNavigate()
  const current = parseDialog(params.get(PARAM))
  const openedByLink = (location.state as DialogState | null)?.dialog === true

  /** Thuộc tính cho `<Link>` / `<ButtonLink>`: ở lại trang hiện tại, thêm `?dialog=…` và giữ nguyên các tham số khác (bộ lọc...). */
  const link = useCallback(
    (name: DialogName, arg?: string): { to: To; state: DialogState } => {
      const next = new URLSearchParams(params)
      next.set(PARAM, arg ? `${name}:${arg}` : name)

      return { to: { pathname: location.pathname, search: `?${stringify(next)}` }, state: { dialog: true } }
    },
    [params, location.pathname],
  )

  /** Đổi sang popup khác NGAY TẠI chỗ popup hiện tại (vd. vừa bắt đầu kiểm kê thì mở luôn màn đếm): không thêm mục lịch sử nên đóng một lần là về trang. */
  const replace = useCallback(
    (name: DialogName, arg?: string) => {
      const next = new URLSearchParams(params)
      next.set(PARAM, arg ? `${name}:${arg}` : name)
      navigate({ pathname: location.pathname, search: `?${stringify(next)}` }, { replace: true, state: location.state })
    },
    [navigate, params, location.pathname, location.state],
  )

  const close = useCallback(() => {
    // Mở bằng liên kết: lùi một bước là về đúng trang trước đó (cùng địa chỉ nhưng chưa có popup).
    if (openedByLink) {
      navigate(-1)

      return
    }

    // Vào thẳng bằng địa chỉ (hoặc tải lại ở chỗ không có lịch sử): bỏ tham số popup, giữ nguyên phần còn lại.
    const next = new URLSearchParams(params)
    next.delete(PARAM)
    navigate({ pathname: location.pathname, search: next.size > 0 ? `?${stringify(next)}` : '' }, { replace: true })
  }, [openedByLink, navigate, params, location.pathname])

  return { current, link, replace, close }
}
