import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, get, post } from '../../lib/api.ts'
import type { Paginated } from '../../lib/types.ts'

export type WarehouseStatus = 'active' | 'inactive'
export type DocumentType = 'opening' | 'receipt' | 'issue' | 'adjustment'

export const DOCUMENT_TYPES: DocumentType[] = ['receipt', 'issue', 'adjustment', 'opening']
export const ISSUE_REASONS = ['damaged', 'expired', 'internal_use', 'sample', 'other'] as const
export const ADJUSTMENT_REASONS = ['count_correction', 'damaged', 'expired', 'other'] as const

export interface BranchRef {
  id: string
  code: string
  name: string
}

export interface Warehouse {
  id: string
  branch: BranchRef
  code: string
  name: string
  address: string | null
  status: WarehouseStatus
}

export interface WarehouseInput {
  code: string
  name: string
  address: string | null
  status?: WarehouseStatus
  /** Chỉ khi tạo: kho không đổi chi nhánh sau đó. */
  branch_id?: string
}

/** Một dòng của trang Tồn kho: một sản phẩm. Số lượng là chuỗi thập phân theo ĐƠN VỊ CƠ SỞ; giá vốn/giá trị chỉ có khi được phép xem. */
export interface StockProduct {
  id: string
  sku: string
  name: string
  type: string
  type_label: string
  status: string
  track_batch: boolean
  branch: BranchRef
  base_unit: { id: string; name: string }
  total_qty: string
  min_stock: string | null
  is_low: boolean
  expired_qty: string
  expiring_qty: string
  next_expiry: string | null
  avg_cost?: string | null
  value?: string
}

export interface StockBalance {
  id: number
  warehouse: { id: string; code: string; name: string }
  product: { id: string; sku: string; name: string; track_batch: boolean; base_unit: string | null }
  batch: { id: string; batch_no: string; manufactured_at: string | null; expires_at: string | null } | null
  quantity: string
  expired: boolean
  days_to_expiry: number | null
}

export interface StockMovement {
  id: number
  type: DocumentType
  document: { id: string; code: string; reason: string | null }
  warehouse: { id: string; code: string; name: string }
  product: { id: string; sku: string; name: string; base_unit: string | null }
  batch: { id: string; batch_no: string; expires_at: string | null } | null
  quantity_delta: string
  balance_after: string
  entered: { unit: string; quantity: string; factor: string }
  unit_cost?: string
  value?: string
  performed_at: string
}

export interface StockDocument {
  id: string
  code: string
  type: DocumentType
  reason: string | null
  note: string | null
  warehouse: { id: string; code: string; name: string }
  branch: BranchRef
  performed_by: { id: string; name: string } | null
  performed_at: string
  line_count?: number
  lines?: Omit<StockMovement, 'document' | 'warehouse'>[]
  /** Giá trị biến động tồn (đồng, có dấu); chỉ có khi được phép xem giá vốn. */
  value?: string
}

/** Số ngày "sắp hết hạn" mặc định; dùng để dựng nhãn thẻ giữ chỗ trước khi máy chủ trả số ngày thật. */
export const DEFAULT_EXPIRY_WARNING_DAYS = 60

export interface StockSummary {
  low_products: number
  expired_batches: number
  expiring_batches: number
  expiry_warning_days: number
  stock_value?: string
}

export interface StockFilters {
  q: string
  type: string
  warehouse: string
  stock: string
  expiry: string
  page: number
}

export interface DocumentFilters {
  q: string
  type: string
  warehouse: string
  from: string
  to: string
  page: number
}

export interface LinePayload {
  product_id: string
  unit_id: string
  quantity: number
  unit_cost?: number | null
  batch_id?: string | null
  batch_no?: string | null
  manufactured_at?: string | null
  expires_at?: string | null
}

export interface DocumentPayload {
  warehouse_id: string
  type: DocumentType
  reason: string | null
  note: string | null
  lines: LinePayload[]
}

const WAREHOUSES = ['warehouses'] as const
const STOCK = ['stock'] as const

/** Tham số truy vấn: bỏ qua bộ lọc để trống. */
function params(values: object, perPage: number): URLSearchParams {
  const query = new URLSearchParams({ per_page: String(perPage) })

  for (const [key, value] of Object.entries(values) as [string, string | number | undefined][]) {
    const text = String(value ?? '').trim()
    if (text !== '') query.set(key, text)
  }

  return query
}

export interface WarehouseFilters {
  q: string
  branch: string
  status: string
  page: number
}

/** Danh sách kho phân trang (20 kho/trang); các ô chọn kho dùng `useAllWarehouses`. */
export function useWarehouses(filters: WarehouseFilters = { q: '', branch: '', status: '', page: 1 }) {
  return useQuery({
    queryKey: [...WAREHOUSES, 'list', filters],
    queryFn: () => get<Paginated<Warehouse>>(`/warehouses?${params(filters, 20).toString()}`),
    placeholderData: keepPreviousData,
  })
}

/** Toàn bộ kho người dùng được phép thấy (để chọn kho khi nhập/xuất và lọc). */
export function useAllWarehouses(enabled = true) {
  return useQuery({
    queryKey: [...WAREHOUSES, 'all'],
    queryFn: async () => (await get<Paginated<Warehouse>>('/warehouses?per_page=100')).data,
    enabled,
  })
}

export function useSaveWarehouse(existing?: Warehouse) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: WarehouseInput) =>
      (await (existing ? api<{ data: Warehouse }>('PATCH', `/warehouses/${existing.id}`, input) : post<{ data: Warehouse }>('/warehouses', input))).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: WAREHOUSES }),
  })
}

export function useDeleteWarehouse() {
  const queryClient = useQueryClient()

  return useMutation({ mutationFn: (id: string) => del(`/warehouses/${id}`), onSuccess: () => queryClient.invalidateQueries({ queryKey: WAREHOUSES }) })
}

export function useStockProducts(filters: StockFilters) {
  return useQuery({
    queryKey: [...STOCK, 'products', filters],
    queryFn: () => get<Paginated<StockProduct>>(`/stock/products?${params({ ...filters, page: filters.page }, 20).toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function useStockSummary(warehouse: string, enabled = true) {
  return useQuery({
    queryKey: [...STOCK, 'summary', warehouse],
    queryFn: async () => (await get<{ data: StockSummary }>(`/stock/summary?${params({ warehouse }, 1).toString()}`)).data,
    enabled,
  })
}

/** Vài phiếu kho mới nhất cho trang Tổng quan. */
export function useRecentStockDocuments(enabled = true) {
  return useQuery({
    queryKey: [...STOCK, 'documents', 'recent'],
    queryFn: async () => (await get<Paginated<StockDocument>>('/stock/documents?per_page=5')).data,
    enabled,
  })
}

/** Số dư từng lô/kho của một sản phẩm (hoặc theo bộ lọc): dùng chọn lô khi xuất và xem hạn dùng. */
export function useStockBalances(filters: { warehouse?: string; product?: string; expiry?: string }, enabled = true) {
  return useQuery({
    queryKey: [...STOCK, 'balances', filters],
    queryFn: async () => (await get<Paginated<StockBalance>>(`/stock/balances?${params(filters, 100).toString()}`)).data,
    enabled,
  })
}

/** Một trang số dư theo lô/kho cho hộp chi tiết tồn (phân trang); ô chọn lô khi xuất dùng `useStockBalances` ở trên. */
export function useStockBalancePage(filters: { warehouse?: string; product?: string }, page: number) {
  return useQuery({
    queryKey: [...STOCK, 'balances', 'page', filters, page],
    queryFn: () => get<Paginated<StockBalance>>(`/stock/balances?${params({ ...filters, page }, 10).toString()}`),
    placeholderData: keepPreviousData,
  })
}

/** Một trang thẻ kho (mới nhất trước) của một sản phẩm. */
export function useStockMovementPage(filters: { product?: string; warehouse?: string }, page: number) {
  return useQuery({
    queryKey: [...STOCK, 'movements', filters, page],
    queryFn: () => get<Paginated<StockMovement>>(`/stock/movements?${params({ ...filters, page }, 10).toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function useStockDocuments(filters: DocumentFilters) {
  return useQuery({
    queryKey: [...STOCK, 'documents', filters],
    queryFn: () => get<Paginated<StockDocument>>(`/stock/documents?${params(filters, 20).toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function useStockDocument(id: string | undefined) {
  return useQuery({
    queryKey: [...STOCK, 'document', id],
    queryFn: async () => (await get<{ data: StockDocument }>(`/stock/documents/${id}`)).data,
    enabled: id !== undefined,
  })
}

/** Ghi một phiếu kho; thành công thì làm mới mọi số liệu tồn đang hiển thị. */
export function usePostStockDocument() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: DocumentPayload) => (await post<{ data: StockDocument }>('/stock/documents', payload)).data,
    // Không `await`: popup đóng ngay khi máy chủ đã nhận, tồn kho phía sau tự làm mới.
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: STOCK })
    },
  })
}
