import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, get, post } from '../../lib/api.ts'
import type { Paginated } from '../../lib/types.ts'

export type ProductTypeKey = 'fertilizer' | 'pesticide' | 'seed' | 'other'
export type ProductStatus = 'active' | 'inactive'
export type ListKind = 'units' | 'categories' | 'brands' | 'customer-groups'

export interface ProductUnitRow {
  unit: { id: string; name: string }
  is_base: boolean
  /** Số đơn vị cơ sở trong một đơn vị này (chuỗi thập phân đúng như lưu trong database). */
  factor: string
  /** Tiền ở dạng chuỗi thập phân ("120000.00"): không qua số thực nên không sai số làm tròn. */
  price_cash: string
  price_credit: string
  barcode: string | null
}

export interface Product {
  id: string
  branch: { id: string; code: string; name: string }
  type: ProductTypeKey
  type_label: string
  sku: string
  name: string
  category: { id: string; name: string } | null
  brand: { id: string; name: string } | null
  description: string | null
  status: ProductStatus
  track_batch: boolean
  /** Mức tồn tối thiểu (đơn vị cơ sở, chuỗi thập phân); null = không đặt. Tồn xuống mức này thì báo "sắp hết hàng". */
  min_stock: string | null
  specs: Record<string, string | number>
  base_unit: { id: string; name: string }
  units: ProductUnitRow[]
  created_at: string
  updated_at: string
}

export interface TypeField {
  key: string
  label: string
  type: 'text' | 'integer' | 'number' | 'select'
  required: boolean
  max?: number
  min?: number
  maxValue?: number
  options?: string[]
}

export interface TypeDefinition {
  key: ProductTypeKey
  label: string
  track_batch_required: boolean
  fields: TypeField[]
}

export interface ListItem {
  id: string
  name: string
  is_active: boolean
  sort_order?: number
}

export interface ProductFilters {
  q: string
  type: string
  status: string
  branch: string
  category: string
  brand: string
  page: number
}

export interface UnitPayload {
  unit_id: string
  factor: number
  price_cash: number
  price_credit: number
  barcode: string | null
}

export interface ProductPayload {
  sku: string
  name: string
  description: string | null
  status: ProductStatus
  track_batch: boolean
  min_stock: number | null
  category_id: string | null
  brand_id: string | null
  specs: Record<string, string | number>
  units: UnitPayload[]
  /** Chỉ khi tạo: loại hàng, chi nhánh và đơn vị cơ sở không đổi được sau đó. */
  type?: ProductTypeKey
  branch_id?: string
  base_unit_id?: string
}

export interface CopyResult {
  data: Product[]
  meta: { copied: number; skipped: { sku: string; name: string; reason: string }[] }
}

const PRODUCTS = ['products'] as const
const LISTS = ['catalog-lists'] as const

/** Các loại hàng và trường đặc thù của từng loại: máy chủ là nguồn duy nhất, giao diện dựng form theo đó. */
export function useProductTypes() {
  return useQuery({
    queryKey: ['product-types'],
    queryFn: async () => (await get<{ data: TypeDefinition[]; meta: { max_units: number } }>('/products/types')),
    staleTime: Infinity,
  })
}

export function useProducts(filters: ProductFilters) {
  const params = new URLSearchParams({ page: String(filters.page), per_page: '20' })
  if (filters.q.trim() !== '') params.set('q', filters.q.trim())
  for (const key of ['type', 'status', 'branch', 'category', 'brand'] as const) {
    if (filters[key] !== '') params.set(key, filters[key])
  }

  return useQuery({
    queryKey: [...PRODUCTS, 'list', { ...filters, q: filters.q.trim() }],
    queryFn: () => get<Paginated<Product>>(`/products?${params.toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function useProduct(id: string | undefined) {
  return useQuery({
    queryKey: [...PRODUCTS, 'detail', id],
    queryFn: async () => (await get<{ data: Product }>(`/products/${id}`)).data,
    enabled: id !== undefined,
  })
}

export function useSaveProduct(existing?: Product) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: ProductPayload) =>
      (await (existing ? api<{ data: Product }>('PATCH', `/products/${existing.id}`, payload) : post<{ data: Product }>('/products', payload))).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: PRODUCTS }),
  })
}

export function useDeleteProduct() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => del(`/products/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: PRODUCTS }),
  })
}

/** Sao chép sản phẩm sang chi nhánh khác: mã đã có ở chi nhánh đích thì được bỏ qua và báo lại. */
export function useCopyProducts() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { product_ids: string[]; branch_id: string }) => post<CopyResult>('/products/copy', input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: PRODUCTS }),
  })
}

/** Tổng số sản phẩm (chỉ xin một bản ghi để lấy số đếm): trang Tổng quan dùng để biết cửa hàng mới chưa có sản phẩm nào. */
export function useProductCount(enabled = true) {
  return useQuery({
    queryKey: [...PRODUCTS, 'count'],
    queryFn: async () => (await get<Paginated<Product>>('/products?per_page=1')).meta.total,
    enabled,
  })
}

/** Danh sách dùng chung toàn doanh nghiệp (đơn vị tính, nhóm hàng, thương hiệu). */
export function useCatalogList(kind: ListKind, enabled = true) {
  return useQuery({
    queryKey: [...LISTS, kind],
    queryFn: async () => (await get<Paginated<ListItem>>(`/${kind}?per_page=200`)).data,
    enabled,
  })
}

/** Một trang của danh sách (10 mục/trang) cho màn hình quản lý; các ô chọn trong biểu mẫu dùng `useCatalogList` để có đủ lựa chọn. */
export function useCatalogListPage(kind: ListKind, page: number) {
  return useQuery({
    queryKey: [...LISTS, kind, 'page', page],
    queryFn: () => get<Paginated<ListItem>>(`/${kind}?per_page=10&page=${page}`),
    placeholderData: keepPreviousData,
  })
}

export interface ListItemInput {
  name: string
  is_active?: boolean
  sort_order?: number
}

export function useSaveListItem(kind: ListKind, existing?: ListItem) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: ListItemInput) =>
      (await (existing ? api<{ data: ListItem }>('PATCH', `/${kind}/${existing.id}`, input) : post<{ data: ListItem }>(`/${kind}`, input))).data,
    // Sản phẩm hiển thị tên nhóm/thương hiệu/đơn vị nên cũng phải lấy lại.
    onSuccess: () => Promise.all([queryClient.invalidateQueries({ queryKey: LISTS }), queryClient.invalidateQueries({ queryKey: PRODUCTS })]),
  })
}

export function useDeleteListItem(kind: ListKind) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => del(`/${kind}/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: LISTS }),
  })
}
