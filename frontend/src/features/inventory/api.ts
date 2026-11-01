import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, get, post } from '../../lib/api.ts'
import type { Paginated } from '../../lib/types.ts'

export type WarehouseStatus = 'active' | 'inactive'
export type DocumentType = 'opening' | 'receipt' | 'issue' | 'adjustment'

/** Loại phiếu có thể lập trực tiếp. Chuyển kho đi qua phiếu chuyển riêng và để lại hai phiếu kho đi cặp (`transfer_out` ở kho gửi, `transfer_in` ở kho nhận). */
export const DOCUMENT_TYPES: DocumentType[] = ['receipt', 'issue', 'adjustment', 'opening']
/** Mọi loại phiếu có thể xuất hiện trong sổ kho (để lọc và hiển thị). */
export type LedgerType = DocumentType | 'transfer_out' | 'transfer_in'
export const LEDGER_TYPES: LedgerType[] = [...DOCUMENT_TYPES, 'transfer_out', 'transfer_in']
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
  /** Đợt kiểm kê đang đếm (kho bị khóa: không ghi được phiếu kho); null = không khóa. */
  stocktake?: { id: string; code: string } | null
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
  type: LedgerType
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
  type: LedgerType
  reason: string | null
  note: string | null
  warehouse: { id: string; code: string; name: string }
  branch: BranchRef
  /** Phiếu chuyển kho mà phiếu này thuộc về (chỉ phiếu `transfer_out` / `transfer_in`). */
  transfer?: { id: string; code: string } | null
  /** Phiếu nhập hàng (Mua hàng) sinh ra phiếu này, nếu có. */
  purchase?: { id: string; code: string } | null
  sale?: { id: string; code: string } | null
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

// --- Kiểm kê -------------------------------------------------------------------------------------

export type StocktakeStatus = 'counting' | 'completed' | 'cancelled'
export const STOCKTAKE_STATUSES: StocktakeStatus[] = ['counting', 'completed', 'cancelled']
export type LineFilter = '' | 'uncounted' | 'counted' | 'differing'

/** Một đợt kiểm kê. `difference_value` (đồng, âm = thiếu hàng) chỉ có khi được phép xem giá vốn; đã hủy thì không có. */
export interface Stocktake {
  id: string
  code: string
  status: StocktakeStatus
  note: string | null
  warehouse: { id: string; code: string; name: string }
  branch: BranchRef
  started_by: { id: string; name: string } | null
  started_at: string
  finished_by: { id: string; name: string } | null
  finished_at: string | null
  adjustment_document: { id: string; code: string } | null
  summary?: { lines: number; counted: number; differing: number }
  difference_value?: string | null
}

/** Dòng đếm: số lượng là chuỗi thập phân theo ĐƠN VỊ CƠ SỞ; `counted.entered` là số và đơn vị người dùng đã nhập. */
export interface StocktakeLine {
  id: string
  product: {
    id: string
    sku: string
    name: string
    track_batch: boolean
    base_unit: { id: string; name: string }
    units: { unit: { id: string; name: string }; is_base: boolean; factor: string }[]
  }
  batch: { id: string; batch_no: string; expires_at: string | null } | null
  system_quantity: string
  counted: { quantity: string; entered: { quantity: string; unit: { id: string; name: string }; factor: string } } | null
  difference: string | null
}

export interface StocktakeFilters {
  status: string
  warehouse: string
  page: number
}

export interface LineFilters {
  q: string
  status: LineFilter
  page: number
}

const STOCKTAKES = ['stocktakes'] as const

export function useStocktakes(filters: StocktakeFilters) {
  return useQuery({
    queryKey: [...STOCKTAKES, 'list', filters],
    queryFn: () => get<Paginated<Stocktake>>(`/stocktakes?${params(filters, 20).toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function useStocktake(id: string | undefined) {
  return useQuery({
    queryKey: [...STOCKTAKES, 'detail', id],
    queryFn: async () => (await get<{ data: Stocktake }>(`/stocktakes/${id}`)).data,
    enabled: id !== undefined,
  })
}

/** Một trang dòng đếm của đợt (25 dòng/trang), lọc theo tên/mã/số lô và trạng thái đếm. */
export function useStocktakeLines(id: string | undefined, filters: LineFilters) {
  return useQuery({
    queryKey: [...STOCKTAKES, id, 'lines', filters],
    queryFn: () => get<Paginated<StocktakeLine>>(`/stocktakes/${id}/lines?${params(filters, 25).toString()}`),
    placeholderData: keepPreviousData,
    enabled: id !== undefined,
  })
}

/** Bắt đầu kiểm kê một kho (khóa kho). Kho đổi trạng thái khóa nên làm mới cả danh sách kho. */
export function useStartStocktake() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: { warehouse_id: string; note: string | null }) => (await post<{ data: Stocktake }>('/stocktakes', input)).data,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: STOCKTAKES })
      void queryClient.invalidateQueries({ queryKey: WAREHOUSES })
    },
  })
}

/**
 * Ghi (hoặc xóa, khi `quantity` = null) số đếm của một dòng. Dòng trả về được đặt thẳng vào các trang đang xem nên ô đếm không bị dựng lại giữa chừng;
 * chỉ phần tổng kết của đợt được tải lại.
 */
export function useCountLine(stocktakeId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: { line: string; quantity: string | null; unit_id: string | null }) =>
      (await api<{ data: StocktakeLine }>('PATCH', `/stocktakes/${stocktakeId}/lines/${input.line}`, { quantity: input.quantity, unit_id: input.unit_id })).data,
    onSuccess: (line) => {
      queryClient.setQueriesData<Paginated<StocktakeLine>>({ queryKey: [...STOCKTAKES, stocktakeId, 'lines'] }, (page) => page && { ...page, data: page.data.map((row) => (row.id === line.id ? line : row)) })
      void queryClient.invalidateQueries({ queryKey: [...STOCKTAKES, 'detail', stocktakeId] })
      void queryClient.invalidateQueries({ queryKey: [...STOCKTAKES, 'list'] })
    },
  })
}

/** Hoàn tất (ghi chênh lệch bằng một phiếu điều chỉnh) hoặc hủy đợt; cả hai mở khóa kho, hoàn tất còn làm đổi tồn nên làm mới mọi số liệu kho. */
export function useFinishStocktake(id: string, action: 'complete' | 'cancel') {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async () => (await post<{ data: Stocktake }>(`/stocktakes/${id}/${action}`, {})).data,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: STOCKTAKES })
      void queryClient.invalidateQueries({ queryKey: WAREHOUSES })
      if (action === 'complete') void queryClient.invalidateQueries({ queryKey: STOCK })
    },
  })
}

// --- Chuyển kho ----------------------------------------------------------------------------------

export interface TransferSide {
  warehouse: { id: string; code: string; name: string }
  branch: BranchRef
}

/** Một dòng hàng chuyển: số lượng DƯƠNG theo đơn vị cơ sở kèm đơn vị người dùng đã nhập; giá vốn chỉ có khi được phép xem. */
export interface TransferLine {
  product: { id: string; sku: string; name: string; base_unit: string }
  batch: { id: string; batch_no: string; expires_at: string | null } | null
  quantity: string
  entered: { unit: string; quantity: string; factor: string }
  unit_cost?: string
  value?: string
}

/** Phiếu chuyển kho. `documents` (phiếu xuất ở kho gửi, phiếu nhập ở kho nhận) và `lines` chỉ có khi xem chi tiết. */
export interface StockTransfer {
  id: string
  code: string
  note: string | null
  from: TransferSide
  to: TransferSide
  performed_by: { id: string; name: string } | null
  performed_at: string
  line_count?: number
  documents?: { out: { id: string; code: string }; in: { id: string; code: string } | null }
  lines?: TransferLine[]
  /** Giá trị hàng chuyển (đồng); chỉ có khi được phép xem giá vốn. */
  value?: string
}

export interface TransferFilters {
  q: string
  warehouse: string
  from: string
  to: string
  page: number
}

export interface TransferPayload {
  from_warehouse_id: string
  to_warehouse_id: string
  note: string | null
  lines: { product_id: string; unit_id: string; quantity: number; batch_id?: string | null }[]
}

export function useTransfers(filters: TransferFilters) {
  return useQuery({
    queryKey: [...STOCK, 'transfers', 'list', filters],
    queryFn: () => get<Paginated<StockTransfer>>(`/stock/transfers?${params(filters, 20).toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function useTransfer(id: string | undefined) {
  return useQuery({
    queryKey: [...STOCK, 'transfers', 'detail', id],
    queryFn: async () => (await get<{ data: StockTransfer }>(`/stock/transfers/${id}`)).data,
    enabled: id !== undefined,
  })
}

/** Chuyển kho trong một bước; thành công thì làm mới mọi số liệu tồn (kho gửi trừ, kho nhận cộng) và danh sách phiếu. */
export function useCreateTransfer() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: TransferPayload) => (await post<{ data: StockTransfer }>('/stock/transfers', payload)).data,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: STOCK })
    },
  })
}
