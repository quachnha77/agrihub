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
  id: string
  product: { id: string; sku: string; name: string; base_unit: string | null }
  batch: { id: string; batch_no: string; expires_at: string | null } | null
  unit: string
  quantity: string
  /** Số lượng khách đã trả lại (theo đơn vị bán); còn trả được = quantity − returned_quantity. */
  returned_quantity: string
  unit_price: string
  line_total: string
  cost_amount?: string
}

/** Một lần khách trả hàng: hàng nhập lại kho; `total` = `debt_reduction` (trừ vào nợ của phiếu) + `refund_amount` (hoàn tiền cho khách). */
export interface SaleReturn {
  id: string
  code: string
  returned_on: string
  note: string | null
  total: string
  debt_reduction: string
  refund_amount: string
  refund_method: PaymentMethod | null
  stock_document: { id: string; code: string }
  created_by: { id: string; name: string } | null
  created_at: string
  lines: { product: { id: string; sku: string; name: string }; unit: string; quantity: string; line_total: string }[]
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
  /** Giá trị hàng khách đã trả lại; `net_total` = total − returned_amount là số khách phải trả thực (`balance` và `payment_status` tính theo số này). */
  returned_amount: string
  net_total: string
  paid_amount: string
  balance: string
  payment_status: PaymentStatus
  stock_document: { id: string; code: string }
  created_by: { id: string; name: string } | null
  created_at: string
  line_count?: number
  lines?: SaleLine[]
  payments?: SalePayment[]
  returns?: SaleReturn[]
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

export interface ReturnPayload {
  returned_on: string | null
  note: string | null
  refund_method?: PaymentMethod
  lines: { line_id: string; quantity: number }[]
}

export interface PaymentPayload {
  amount: number
  method: PaymentMethod
  paid_on: string | null
  note: string | null
}

/** Một khách đang nợ: tổng hợp từ các phiếu bán còn nợ trong phạm vi quyền của người xem (khách mua ở nhiều chi nhánh thì mỗi người thấy phần nợ của chi nhánh mình). */
export interface Receivable {
  customer: { id: string; code: string; name: string; phone: string | null; status: string; credit_limit: string; credit_days: number | null }
  open_orders: number
  owed: string
  overdue_amount: string
  is_overdue: boolean
  oldest_overdue_on: string | null
  days_overdue: number
}

/** Tổng của MỌI khách khớp bộ lọc, nợ chia theo tuổi: chưa đến hạn, quá hạn 1–30 / 31–60 / trên 60 ngày. */
export interface ReceivableSummary {
  debtors: number
  owed: string
  aging: { current: string; overdue_1_30: string; overdue_31_60: string; overdue_over_60: string }
}

export interface ReceivableFilters {
  q: string
  /** `1` = chỉ khách có phiếu quá hạn. */
  overdue: string
  page: number
}

/** Kết quả thu một khoản cho nhiều phiếu: phần đã trừ vào từng phiếu (đến hạn sớm nhất trước) và số khách còn nợ sau khi thu. */
export interface CollectResult {
  customer: string
  collected: string
  remaining: string
  allocations: { order: { id: string; code: string }; amount: string; balance: string }[]
}

const SALES = ['sales'] as const
const RECEIVABLES = ['receivables'] as const
const STOCK = ['stock'] as const

/** Thu tiền, hủy lần thu hay lập phiếu mới đều đổi số nợ: làm mới cả danh sách công nợ. */
const refreshDebts = (queryClient: ReturnType<typeof useQueryClient>) => queryClient.invalidateQueries({ queryKey: RECEIVABLES })

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

export function useReceivables(filters: ReceivableFilters) {
  return useQuery({
    queryKey: [...RECEIVABLES, 'list', filters],
    queryFn: () => get<Paginated<Receivable> & { summary: ReceivableSummary }>(`/receivables?${params(filters, 20).toString()}`),
    placeholderData: keepPreviousData,
  })
}

/** Các phiếu còn nợ của một khách (cũ nhất trước ở phía máy khách), để người thu thấy tiền sẽ trừ vào phiếu nào. Tối đa 100 phiếu. */
export function useCustomerOpenSales(customerId: string | undefined) {
  return useQuery({
    queryKey: [...SALES, 'open', customerId],
    queryFn: async () => (await get<Paginated<Sale>>(`/sales?${params({ customer: customerId, payment_status: 'open' }, 100).toString()}`)).data,
    enabled: customerId !== undefined,
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
      void refreshDebts(queryClient)
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
      void queryClient.invalidateQueries({ queryKey: [...SALES, 'open'] })
      void refreshDebts(queryClient)
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
      void queryClient.invalidateQueries({ queryKey: [...SALES, 'open'] })
      void refreshDebts(queryClient)
    },
  })
}

/** Thu một khoản của khách cho nhiều phiếu cùng lúc (tự trừ vào phiếu đến hạn sớm nhất trước). */
export function useCollectReceivable(customerId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: PaymentPayload) => (await post<{ data: CollectResult }>(`/receivables/${customerId}/collect`, payload)).data,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: SALES })
      void refreshDebts(queryClient)
    },
  })
}

/** Khách trả hàng: hàng nhập lại kho nên làm mới cả tồn kho; phiếu trả về đã cập nhật nên đặt thẳng vào bộ nhớ đệm chi tiết. */
export function useReturnSale(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: ReturnPayload) => (await post<{ data: Sale }>(`/sales/${id}/returns`, payload)).data,
    onSuccess: (sale) => {
      queryClient.setQueryData([...SALES, 'detail', id], sale)
      void queryClient.invalidateQueries({ queryKey: [...SALES, 'list'] })
      void queryClient.invalidateQueries({ queryKey: [...SALES, 'open'] })
      void queryClient.invalidateQueries({ queryKey: STOCK })
      void refreshDebts(queryClient)
    },
  })
}
