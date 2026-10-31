import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, get, post } from '../../lib/api.ts'
import type { Paginated } from '../../lib/types.ts'

export type CustomerType = 'individual' | 'agent' | 'cooperative' | 'company'
export type PartyStatus = 'active' | 'inactive'

export const CUSTOMER_TYPES: CustomerType[] = ['individual', 'agent', 'cooperative', 'company']

export interface CustomerAddress {
  label: string | null
  address: string
  ward: string | null
  district: string | null
  province: string | null
  is_default: boolean
}

export interface CustomerContact {
  name: string
  phone: string | null
  position: string | null
  note: string | null
}

export interface Customer {
  id: string
  code: string
  type: CustomerType
  type_label: string
  name: string
  branch: { id: string; code: string; name: string }
  group: { id: string; name: string } | null
  phone: string | null
  email: string | null
  tax_code: string | null
  /** Hạn mức công nợ (đồng) dạng chuỗi thập phân đúng như lưu trong database; "0.00" = không cho ghi nợ. */
  credit_limit: string
  /** Số ngày nợ tối đa; null = không quy định. */
  credit_days: number | null
  notes: string | null
  status: PartyStatus
  addresses: CustomerAddress[]
  contacts: CustomerContact[]
  created_at: string
  updated_at: string
}

export interface SupplierContact {
  name: string
  phone: string | null
  email: string | null
  position: string | null
}

export interface Supplier {
  id: string
  code: string
  name: string
  tax_code: string | null
  phone: string | null
  email: string | null
  address: string | null
  payment_terms_days: number | null
  notes: string | null
  status: PartyStatus
  contacts: SupplierContact[]
  created_at: string
  updated_at: string
}

export interface CustomerFilters {
  q: string
  type: string
  status: string
  branch: string
  group: string
  page: number
}

export interface SupplierFilters {
  q: string
  status: string
  page: number
}

export interface CustomerPayload {
  branch_id?: string
  type?: CustomerType
  code?: string | null
  name: string
  phone: string | null
  email: string | null
  tax_code: string | null
  group_id: string | null
  credit_limit: number
  credit_days: number | null
  notes: string | null
  status: PartyStatus
  addresses: CustomerAddress[]
  contacts: CustomerContact[]
}

export interface SupplierPayload {
  code?: string | null
  name: string
  tax_code: string | null
  phone: string | null
  email: string | null
  address: string | null
  payment_terms_days: number | null
  notes: string | null
  status: PartyStatus
  contacts: SupplierContact[]
}

const CUSTOMERS = ['customers'] as const
const SUPPLIERS = ['suppliers'] as const

/** Tham số truy vấn danh sách: bỏ qua bộ lọc để trống (và trang "0"). */
const listParams = (filters: CustomerFilters | SupplierFilters) => {
  const params = new URLSearchParams({ per_page: '20' })

  for (const [key, value] of Object.entries(filters) as [string, string | number][]) {
    const text = String(value).trim()
    if (text !== '' && text !== '0') params.set(key, text)
  }

  return params
}

export function useCustomers(filters: CustomerFilters) {
  return useQuery({
    queryKey: [...CUSTOMERS, 'list', { ...filters, q: filters.q.trim() }],
    queryFn: () => get<Paginated<Customer>>(`/customers?${listParams(filters).toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function useCustomer(id: string | undefined) {
  return useQuery({
    queryKey: [...CUSTOMERS, 'detail', id],
    queryFn: async () => (await get<{ data: Customer }>(`/customers/${id}`)).data,
    enabled: id !== undefined,
  })
}

export function useSaveCustomer(existing?: Customer) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: CustomerPayload) =>
      (await (existing ? api<{ data: Customer }>('PATCH', `/customers/${existing.id}`, payload) : post<{ data: Customer }>('/customers', payload))).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: CUSTOMERS }),
  })
}

export function useDeleteCustomer() {
  const queryClient = useQueryClient()

  return useMutation({ mutationFn: (id: string) => del(`/customers/${id}`), onSuccess: () => queryClient.invalidateQueries({ queryKey: CUSTOMERS }) })
}

export function useSuppliers(filters: SupplierFilters) {
  return useQuery({
    queryKey: [...SUPPLIERS, 'list', { ...filters, q: filters.q.trim() }],
    queryFn: () => get<Paginated<Supplier>>(`/suppliers?${listParams(filters).toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function useSupplier(id: string | undefined) {
  return useQuery({
    queryKey: [...SUPPLIERS, 'detail', id],
    queryFn: async () => (await get<{ data: Supplier }>(`/suppliers/${id}`)).data,
    enabled: id !== undefined,
  })
}

export function useSaveSupplier(existing?: Supplier) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: SupplierPayload) =>
      (await (existing ? api<{ data: Supplier }>('PATCH', `/suppliers/${existing.id}`, payload) : post<{ data: Supplier }>('/suppliers', payload))).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: SUPPLIERS }),
  })
}

export function useDeleteSupplier() {
  const queryClient = useQueryClient()

  return useMutation({ mutationFn: (id: string) => del(`/suppliers/${id}`), onSuccess: () => queryClient.invalidateQueries({ queryKey: SUPPLIERS }) })
}
