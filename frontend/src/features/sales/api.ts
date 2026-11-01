import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { get, post } from '../../lib/api.ts'
import type { Paginated } from '../../lib/types.ts'
import type { Customer } from '../crm/api.ts'

export type PaymentStatus = 'unpaid' | 'partial' | 'paid'
export type PaymentMethod = 'cash' | 'bank_transfer'

export const PAYMENT_STATUSES: PaymentStatus[] = ['unpaid', 'partial', 'paid']
export const PAYMENT_METHODS: PaymentMethod[] = ['cash', 'bank_transfer']

/** Một dòng hàng của phiếu bán. Tiền là chuỗi thập phân đúng như lưu trong database; `cost_amount` (giá vốn) chỉ có với người được xem giá vốn. */
export interface SaleLine {
  product: { id: string; sku: string; name: string; base_unit: string | null }
  batch: { id: string; batch_no: string; expires_at: string | null } | null
  unit: string
  quantity: string
  unit_price: string
  line_total: string
  cost_amount?: string
}

export interface SalePayment {
  id: string
  amount: string
  method: PaymentMethod
  paid_on: string
  note: string | null
  created_by: { id: string; name: string } | null
  created_at: string
  /** Lần thu đã hủy (không tính vào số đã thu); null = còn hiệu lực. */
  voided: { at: string; by: { id: string; name: string } | null; reason: string | null } | null
}

/** Phiếu bán hàng. `total` = tiền khách phải trả (đã trừ `discount`), `balance` = khách còn nợ phiếu này; `customer` null = khách lẻ. `lines` và `payments` chỉ có khi xem chi tiết. */
export interface Sale {
  id: string
  code: string
  sold_on: string
  due_on: string | null
  is_overdue: boolean
  note: string | null
  customer: { id: string; code: string; name: string } | null
  warehouse: { id: string; code: string; name: string }
  branch: { id: string; code: string; name: string }
  goods_total: string
  discount: string
  total: string
  paid_amount: string
  balance: string
  payment_status: PaymentStatus
  stock_document: { id: string; code: string }
  created_by: { id: string; name: string } | null
  created_at: string
  line_count?: number
  lines?: SaleLine[]
  payments?: SalePayment[]
}

/** Tổng của MỌI phiếu khớp bộ lọc (không chỉ trang đang xem). */
export interface SaleSummary {
  total: string
  paid_amount: string
  balance: string
}

export interface SaleFilters {
  q: string
  customer: string
  warehouse: string
  /** `open` = khách chưa trả đủ, `overdue` = đang nợ quá hạn. */
  payment_status: string
  from: string
  to: string
  page: number
}

export interface SaleLinePayload {
  product_id: string
  unit_id: string
  quantity: number
  unit_price: number
}

export interface SalePayload {
  warehouse_id: string
  customer_id: string | null
  note: string | null
  discount: number
  paid_amount: number
  payment_method?: PaymentMethod
  lines: SaleLinePayload[]
}

export interface PaymentPayload {
  amount: number
  method: PaymentMethod
  paid_on: string | null
  note: string | null
}

const SALES = ['sales'] as const
const STOCK = ['stock'] as const

function params(values: object, perPage: number): URLSearchParams {
  const query = new URLSearchParams({ per_page: String(perPage) })

  for (const [key, value] of Object.entries(values) as [string, string | number | undefined][]) {
    const text = String(value ?? '').trim()
    if (text !== '') query.set(key, text)
  }

  return query
}

export function useSales(filters: SaleFilters) {
  return useQuery({
    queryKey: [...SALES, 'list', filters],
    queryFn: () => get<Paginated<Sale> & { summary: SaleSummary }>(`/sales?${params(filters, 20).toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function useSale(id: string | undefined) {
  return useQuery({
    queryKey: [...SALES, 'detail', id],
    queryFn: async () => (await get<{ data: Sale }>(`/sales/${id}`)).data,
    enabled: id !== undefined,
  })
}

/** Khách đang giao dịch khớp từ khóa (tối đa 8), để người bán gõ vài chữ là chọn được; khách của chi nhánh khác không hiện vì API lọc theo quyền. */
export function useCustomerSearch(q: string) {
  return useQuery({
    queryKey: ['customers', 'pick', q.trim()],
    queryFn: async () => (await get<Paginated<Customer>>(`/customers?${params({ q, status: 'active' }, 8).toString()}`)).data,
    placeholderData: keepPreviousData,
  })
}

/** Mọi khách (kể cả đã ngừng) cho ô lọc danh sách: phiếu cũ vẫn lọc được theo khách đã ngừng giao dịch. */
export function useAllCustomers(enabled = true) {
  return useQuery({
    queryKey: ['customers', 'all-for-filter'],
    queryFn: async () => (await get<Paginated<Customer>>('/customers?per_page=100')).data,
    enabled,
  })
}

/** Lập phiếu bán: hàng ra kho ngay nên làm mới cả số liệu tồn kho. */
export function useCreateSale() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: SalePayload) => (await post<{ data: Sale }>('/sales', payload)).data,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: SALES })
      void queryClient.invalidateQueries({ queryKey: STOCK })
    },
  })
}

/** Ghi một lần thu tiền cho phiếu; phiếu trả về đã cập nhật nên đặt thẳng vào bộ nhớ đệm chi tiết. */
export function useCollectSale(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: PaymentPayload) => (await post<{ data: Sale }>(`/sales/${id}/payments`, payload)).data,
    onSuccess: (sale) => {
      queryClient.setQueryData([...SALES, 'detail', id], sale)
      void queryClient.invalidateQueries({ queryKey: [...SALES, 'list'] })
    },
  })
}

/** Hủy một lần thu (thu nhầm), bắt buộc nêu lý do. */
export function useVoidSalePayment(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: { payment: string; reason: string }) => (await post<{ data: Sale }>(`/sales/${id}/payments/${input.payment}/void`, { reason: input.reason })).data,
    onSuccess: (sale) => {
      queryClient.setQueryData([...SALES, 'detail', id], sale)
      void queryClient.invalidateQueries({ queryKey: [...SALES, 'list'] })
    },
  })
}
