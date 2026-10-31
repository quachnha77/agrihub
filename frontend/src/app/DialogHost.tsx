import { lazy, Suspense, useEffect, type ComponentType, type LazyExoticComponent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal, Spinner } from '../components/ui.tsx'
import { useAuth } from '../features/auth/auth-context.ts'
import { useDialog, type DialogProps } from '../lib/dialog.ts'

type Can = (permission: string) => boolean

interface Definition {
  Component: LazyExoticComponent<ComponentType<DialogProps>>
  /** Quyền để MỞ popup (giao diện chỉ dẫn đường; quyền thật do API kiểm tra). `arg` có nghĩa là sửa/xem một bản ghi có sẵn. */
  allowed: (can: Can, arg?: string) => boolean
}

const loaders = {
  product: () => import('../features/catalog/ProductDialog.tsx'),
  customer: () => import('../features/crm/CustomerDialog.tsx'),
  supplier: () => import('../features/crm/SupplierDialog.tsx'),
  stockNew: () => import('../features/inventory/StockDocumentDialog.tsx'),
  stockDoc: () => import('../features/inventory/StockDocumentViewDialog.tsx'),
  stocktakeNew: () => import('../features/inventory/StocktakeStartDialog.tsx'),
  stocktake: () => import('../features/inventory/StocktakeDialog.tsx'),
  transferNew: () => import('../features/inventory/TransferDialog.tsx'),
  transfer: () => import('../features/inventory/TransferViewDialog.tsx'),
  purchaseNew: () => import('../features/purchase/PurchaseDialog.tsx'),
  purchase: () => import('../features/purchase/PurchaseViewDialog.tsx'),
  purchasePay: () => import('../features/purchase/PurchasePayDialog.tsx'),
}

/**
 * Các popup biểu mẫu của ứng dụng, mở bằng `?dialog=<tên>[:<đối số>]` (xem lib/dialog.ts). Popup mới (Kiểm kê, Chuyển kho, Mua hàng, Bán hàng...)
 * thêm một dòng ở đây. Mỗi popup tải theo nhu cầu (lazy) và được tải sẵn lúc rảnh để bấm là mở ngay.
 */
const DIALOGS: Record<string, Definition> = {
  product: { Component: lazy(loaders.product), allowed: (can, arg) => can(arg ? 'catalog.product.view' : 'catalog.product.create') },
  customer: { Component: lazy(loaders.customer), allowed: (can, arg) => can(arg ? 'crm.customer.view' : 'crm.customer.create') },
  supplier: { Component: lazy(loaders.supplier), allowed: (can, arg) => can(arg ? 'purchase.supplier.view' : 'purchase.supplier.create') },
  // Phiếu điều chỉnh có quyền riêng: người chỉ có quyền điều chỉnh vẫn mở được (popup tự chỉ cho chọn loại phiếu mà người đó được lập).
  'stock-new': { Component: lazy(loaders.stockNew), allowed: (can) => can('inventory.movement.create') || can('inventory.adjustment.create') },
  'stock-doc': { Component: lazy(loaders.stockDoc), allowed: (can) => can('inventory.movement.view') },
  // Kiểm kê dùng quyền "Kiểm kê & điều chỉnh kho": xem đợt cần quyền xem, bắt đầu cần quyền tạo (đếm/hoàn tất/hủy do popup tự ẩn nút và API kiểm tra lại).
  'stocktake-new': { Component: lazy(loaders.stocktakeNew), allowed: (can) => can('inventory.adjustment.create') },
  stocktake: { Component: lazy(loaders.stocktake), allowed: (can) => can('inventory.adjustment.view') },
  // Chuyển kho dùng quyền nhập/xuất kho (máy chủ còn đòi quyền ở CẢ HAI chi nhánh của hai kho).
  'transfer-new': { Component: lazy(loaders.transferNew), allowed: (can) => can('inventory.movement.create') },
  transfer: { Component: lazy(loaders.transfer), allowed: (can) => can('inventory.movement.view') },
  // Mua hàng dùng quyền `purchase.order`: lập phiếu = tạo, xem = xem, trả tiền = cập nhật (API còn kiểm tra theo chi nhánh của kho).
  'purchase-new': { Component: lazy(loaders.purchaseNew), allowed: (can) => can('purchase.order.create') },
  purchase: { Component: lazy(loaders.purchase), allowed: (can) => can('purchase.order.view') },
  'purchase-pay': { Component: lazy(loaders.purchasePay), allowed: (can) => can('purchase.order.update') },
}

/** Tải sẵn mã của các popup khi trình duyệt rảnh, để lần bấm đầu tiên không phải chờ mạng (nhất là trên điện thoại). Bỏ qua khi chạy test. */
function usePreloadDialogs() {
  useEffect(() => {
    if (import.meta.env.MODE === 'test') return

    const load = () => Object.values(loaders).forEach((loader) => void loader())

    if (typeof window.requestIdleCallback === 'function') {
      const handle = window.requestIdleCallback(load)

      return () => window.cancelIdleCallback(handle)
    }

    const timer = window.setTimeout(load, 2000)

    return () => window.clearTimeout(timer)
  }, [])
}

/** Chờ tải mã của popup (chỉ lần đầu khi chưa kịp tải sẵn): chỉ hiện nếu chờ quá ~0,3 giây, để lần nhanh không bị chớp. */
function DialogLoading() {
  const { t } = useTranslation()

  return (
    <div aria-live="polite" className="fixed inset-0 z-50 grid place-items-center bg-slate-900/30 [animation-delay:300ms] [animation-fill-mode:backwards] motion-safe:animate-fade-in">
      <span className="rounded-full bg-white p-3 shadow-pop">
        <Spinner className="h-6 w-6 text-brand-700" />
      </span>
      <span className="sr-only">{t('common.loading')}</span>
    </div>
  )
}

/** Vẽ popup đang được yêu cầu trên địa chỉ (nếu có). Đặt một lần trong khung ứng dụng nên hoạt động trên mọi trang. */
export function DialogHost() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { current, close } = useDialog()
  usePreloadDialogs()

  const definition = current ? DIALOGS[current.name] : undefined

  if (!current || !definition) return null

  if (!definition.allowed(can, current.arg)) {
    return (
      <Modal title={t('errors.forbiddenTitle')} onClose={close}>
        <div className="space-y-4">
          <p className="text-sm text-slate-700">{t('errors.forbiddenText')}</p>
          <div className="flex justify-end">
            <Button onClick={close}>{t('common.close')}</Button>
          </div>
        </div>
      </Modal>
    )
  }

  const Dialog = definition.Component

  return (
    <Suspense fallback={<DialogLoading />}>
      <Dialog key={`${current.name}:${current.arg ?? ''}`} arg={current.arg} onClose={close} />
    </Suspense>
  )
}
