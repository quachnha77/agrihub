import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { get, post } from '../../lib/api.ts'
import type { Paginated } from '../../lib/types.ts'
import type { Supplier } from '../crm/api.ts'

export type PaymentStatus = 'unpaid' | 'partial' | 'paid'
export type PaymentMethod = 'cash' | 'bank_transfer'

export const PAYMENT_STATUSES: PaymentStatus[] = ['unpaid', 'partial', 'paid']
export const PAYMENT_METHODS: PaymentMethod[] = ['cash', 'bank_transfer']

/** Một dòng hàng của phiếu nhập hàng. Tiền là chuỗi thập phân đúng như lưu trong database; `cost` = giá vốn mỗi đơn vị đã nhập (đã gồm chi phí kèm theo phân bổ). */
export interface PurchaseLine {
  product: { id: string; sku: string; name: string; base_unit: string | null }
  batch: { id: string; batch_no: string; expires_at: string | null } | null
  unit: string
  quantity: string
  unit_cost: string
  line_total: string
  extra_allocated: string
  cost: string
}

export interface PurchasePayment {
  id: string
  amount: string
  method: PaymentMethod
  paid_on: string
  note: string | null
  created_by: { id: string; name: string } | null
  created_at: string
  /** Lần trả đã hủy (không tính vào số đã trả); null = còn hiệu lực. */
  voided: { at: string; by: { id: string; name: string } | null; reason: string | null } | null
}

/** Phiếu nhập hàng. `balance` là tiền hàng còn nợ nhà cung cấp (chi phí kèm theo không tính vào nợ). `lines` và `payments` chỉ có khi xem chi tiết. */
export interface Purchase {
  id: string
  code: string
  supplier_ref: string | null
  received_on: string
  note: string | null
  supplier: { id: string; code: string; name: string }
  warehouse: { id: string; code: string; name: string }
  branch: { id: string; code: string; name: string }
  goods_total: string
  extra_cost: string
  paid_amount: string
  balance: string
  payment_status: PaymentStatus
  stock_document: { id: string; code: string }
  created_by: { id: string; name: string } | null
  created_at: string
  line_count?: number
  lines?: PurchaseLine[]
  payments?: PurchasePayment[]
}

/** Tổng của MỌI phiếu khớp bộ lọc (không chỉ trang đang xem). */
export interface PurchaseSummary {
  goods_total: string
  paid_amount: string
  balance: string
}

export interface PurchaseFilters {
  q: string
  supplier: string
  warehouse: string
  /** `open` = chưa trả đủ (chưa trả + trả một phần). */
  payment_status: string
  from: string
  to: string
  page: number
}

export interface PurchaseLinePayload {
  product_id: string
  unit_id: string
  quantity: number
  unit_cost: number
  batch_no?: string | null
  manufactured_at?: string | null
  expires_at?: string | null
}

export interface PurchasePayload {
  warehouse_id: string
  supplier_id: string
  supplier_ref: string | null
  received_on: string | null
  note: string | null
  extra_cost: number
  paid_amount: number
  payment_method?: PaymentMethod
  lines: PurchaseLinePayload[]
}

export interface PaymentPayload {
  amount: number
  method: PaymentMethod
  paid_on: string | null
  note: string | null
}

const PURCHASES = ['purchases'] as const
const STOCK = ['stock'] as const
const SUPPLIERS = ['suppliers'] as const

function params(values: object, perPage: number): URLSearchParams {
  const query = new URLSearchParams({ per_page: String(perPage) })

  for (const [key, value] of Object.entries(values) as [string, string | number | undefined][]) {
    const text = String(value ?? '').trim()
    if (text !== '') query.set(key, text)
  }

  return query
}

export function usePurchases(filters: PurchaseFilters) {
  return useQuery({
    queryKey: [...PURCHASES, 'list', filters],
    queryFn: () => get<Paginated<Purchase> & { summary: PurchaseSummary }>(`/purchases?${params(filters, 20).toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function usePurchase(id: string | undefined) {
  return useQuery({
    queryKey: [...PURCHASES, 'detail', id],
    queryFn: async () => (await get<{ data: Purchase }>(`/purchases/${id}`)).data,
    enabled: id !== undefined,
  })
}

/** Nhà cung cấp đang giao dịch để chọn khi nhập hàng (tối đa 100, sắp theo tên). */
export function useActiveSuppliers(enabled = true) {
  return useQuery({
    queryKey: [...SUPPLIERS, 'active'],
    queryFn: async () => (await get<Paginated<Supplier>>('/suppliers?status=active&per_page=100')).data,
    enabled,
  })
}

/** Mọi nhà cung cấp (kể cả đã ngừng) cho ô lọc danh sách: phiếu cũ vẫn lọc được theo nhà cung cấp đã ngừng giao dịch. */
export function useAllSuppliers(enabled = true) {
  return useQuery({
    queryKey: [...SUPPLIERS, 'all'],
    queryFn: async () => (await get<Paginated<Supplier>>('/suppliers?per_page=100')).data,
    enabled,
  })
}

/** Lập phiếu nhập hàng: hàng vào kho ngay nên làm mới cả số liệu tồn kho. */
export function useCreatePurchase() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: PurchasePayload) => (await post<{ data: Purchase }>('/purchases', payload)).data,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PURCHASES })
      void queryClient.invalidateQueries({ queryKey: STOCK })
    },
  })
}

/** Ghi một lần trả tiền cho phiếu; phiếu trả về đã cập nhật nên đặt thẳng vào bộ nhớ đệm chi tiết. */
export function usePayPurchase(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: PaymentPayload) => (await post<{ data: Purchase }>(`/purchases/${id}/payments`, payload)).data,
    onSuccess: (purchase) => {
      queryClient.setQueryData([...PURCHASES, 'detail', id], purchase)
      void queryClient.invalidateQueries({ queryKey: [...PURCHASES, 'list'] })
    },
  })
}

/** Hủy một lần trả (trả nhầm), bắt buộc nêu lý do. */
export function useVoidPayment(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: { payment: string; reason: string }) => (await post<{ data: Purchase }>(`/purchases/${id}/payments/${input.payment}/void`, { reason: input.reason })).data,
    onSuccess: (purchase) => {
      queryClient.setQueryData([...PURCHASES, 'detail', id], purchase)
      void queryClient.invalidateQueries({ queryKey: [...PURCHASES, 'list'] })
    },
  })
}
