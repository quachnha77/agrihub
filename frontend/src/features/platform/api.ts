import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, get, post } from '../../lib/api.ts'
import type { Paginated } from '../../lib/types.ts'
import type { Branch } from '../branches/api.ts'
import type { SupportSession } from '../auth/types.ts'
import type { UserRow } from '../users/api.ts'

export type TenantStatus = 'active' | 'suspended'
export type SubscriptionState = 'trial' | 'active' | 'grace' | 'reopened' | 'expired'
export type BillingCycle = 'monthly' | 'yearly'

/** Một dòng tóm tắt thuê bao trong danh sách doanh nghiệp. */
export interface SubscriptionSummary {
  state: SubscriptionState
  blocked: boolean
  is_trial: boolean
  plan: string
  seats: number
  period_end: string
  days_left: number
  last_contacted_at: string | null
}

export interface TenantOwner {
  id: string
  name: string
  email: string
  status: 'active' | 'disabled'
  must_change_password: boolean
  last_login_at: string | null
}

export interface TenantRow {
  id: string
  name: string
  slug: string
  /** Địa chỉ đăng nhập riêng (subdomain); null khi chưa bật subdomain. */
  url: string | null
  status: TenantStatus
  phone: string | null
  address: string | null
  tax_code: string | null
  suspended_at: string | null
  suspension_reason: string | null
  created_at: string | null
  users_count?: number
  branches_count?: number
  owners?: TenantOwner[]
  branches?: Branch[]
  subscription?: SubscriptionSummary | null
}

export interface SubscriptionPeriod {
  id: string
  kind: 'trial' | 'renewal' | 'change'
  plan: string
  billing_cycle: BillingCycle
  seats: number
  unit_price: number
  amount: number
  period_start: string
  period_end: string
  paid_at: string | null
  payment_method: string | null
  payment_ref: string | null
  note: string | null
}

/** Thuê bao đầy đủ, kèm thông tin nội bộ của nhân viên AgriHub. */
export interface SubscriptionDetail {
  state: SubscriptionState
  blocked: boolean
  reopened_until: string | null
  is_trial: boolean
  plan: { key: string; name: string }
  billing_cycle: BillingCycle
  seats: number
  unit_price: number
  max_branches: number | null
  storage_mb: number | null
  period_start: string
  period_end: string
  days_left: number
  usage: { users: number; branches: number }
  periods?: SubscriptionPeriod[]
  last_contacted_at: string | null
  contact_note: string | null
  reopen_reason: string | null
}

export interface Plan {
  id: string
  key: string
  name: string
  description: string | null
  price_monthly: number
  price_yearly: number
  max_branches: number | null
  storage_mb: number | null
  is_active: boolean
  sort_order: number
}

export interface Renewal {
  tenant: { id: string; name: string; phone: string | null }
  owner: { name: string; email: string; phone: string | null } | null
  subscription: SubscriptionDetail
}

/** Mật khẩu tạm chỉ được trả MỘT lần (khi onboarding hoặc cấp lại mật khẩu). */
export interface WithTemporaryPassword<T> {
  data: T
  temporary_password?: string
}

const TENANTS = ['platform', 'tenants'] as const

export function useTenants(options: { q?: string; status?: string; page?: number } = {}) {
  const { q = '', status = '', page = 1 } = options
  const params = new URLSearchParams({ page: String(page), per_page: '20' })
  if (q.trim() !== '') params.set('q', q.trim())
  if (status !== '') params.set('status', status)

  return useQuery({
    queryKey: [...TENANTS, 'list', { q: q.trim(), status, page }],
    queryFn: () => get<Paginated<TenantRow>>(`/platform/tenants?${params.toString()}`),
    placeholderData: keepPreviousData,
  })
}

export function useTenant(id: string) {
  return useQuery({ queryKey: [...TENANTS, 'detail', id], queryFn: () => get<{ data: TenantRow }>(`/platform/tenants/${id}`).then((r) => r.data) })
}

export interface CreateTenantInput {
  tenant: { name: string; slug?: string; phone?: string; address?: string; tax_code?: string }
  owner: { name: string; email: string; phone?: string; password?: string }
}

export function useCreateTenant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateTenantInput) => post<WithTemporaryPassword<TenantRow>>('/platform/tenants', input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TENANTS }),
  })
}

export interface UpdateTenantInput {
  name: string
  phone: string | null
  address: string | null
  tax_code: string | null
}

export function useUpdateTenant(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: UpdateTenantInput) => api('PATCH', `/platform/tenants/${id}`, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TENANTS }),
  })
}

export function useSetTenantStatus(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { action: 'suspend'; reason: string } | { action: 'activate' }) =>
      post(`/platform/tenants/${id}/${input.action}`, input.action === 'suspend' ? { reason: input.reason } : undefined),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TENANTS }),
  })
}

export function useStartSupportSession(tenantId: string) {
  return useMutation({
    mutationFn: (input: { reason: string; minutes: number }) => post<{ data: SupportSession }>(`/platform/tenants/${tenantId}/support-session`, input),
  })
}

// --- Người dùng của một doanh nghiệp (hỗ trợ khách hàng) ----------------------------------------

export function useTenantUsers(tenantId: string, page: number) {
  return useQuery({
    queryKey: [...TENANTS, 'users', tenantId, page],
    queryFn: () => get<Paginated<UserRow>>(`/platform/users?tenant=${tenantId}&per_page=20&page=${page}`),
    placeholderData: keepPreviousData,
  })
}

export function useSetPlatformUserStatus(tenantId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { id: string; status: 'active' | 'disabled'; reason?: string }) =>
      api('PATCH', `/platform/users/${input.id}`, { status: input.status, reason: input.reason || undefined }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [...TENANTS, 'users', tenantId] }),
  })
}

export function useResetPlatformUserPassword() {
  return useMutation({ mutationFn: (id: string) => post<WithTemporaryPassword<UserRow>>(`/platform/users/${id}/reset-password`) })
}

// --- Thuê bao ----------------------------------------------------------------------------------

const subscriptionKey = (tenantId: string) => ['platform', 'subscription', tenantId] as const

export function useSubscription(tenantId: string) {
  return useQuery({ queryKey: subscriptionKey(tenantId), queryFn: () => get<{ data: SubscriptionDetail }>(`/platform/tenants/${tenantId}/subscription`).then((r) => r.data) })
}

/** Sau khi đổi thuê bao: tải lại chi tiết, danh sách doanh nghiệp (cột thuê bao) và việc cần liên hệ. */
function useRefreshBilling(tenantId: string) {
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: subscriptionKey(tenantId) }),
      queryClient.invalidateQueries({ queryKey: TENANTS }),
      queryClient.invalidateQueries({ queryKey: ['platform', 'renewals'] }),
    ])
}

export interface ConfigureSubscriptionInput {
  plan_id?: string
  billing_cycle?: BillingCycle
  seats?: number
  unit_price?: number
}

export function useConfigureSubscription(tenantId: string) {
  const refresh = useRefreshBilling(tenantId)

  return useMutation({ mutationFn: (input: ConfigureSubscriptionInput) => api('PATCH', `/platform/tenants/${tenantId}/subscription`, input), onSuccess: refresh })
}

export interface RenewInput {
  periods: number
  amount?: number
  paid_at?: string
  payment_method?: string
  payment_ref?: string
  note?: string
}

export function useRenewSubscription(tenantId: string) {
  const refresh = useRefreshBilling(tenantId)

  return useMutation({ mutationFn: (input: RenewInput) => post(`/platform/tenants/${tenantId}/subscription/renew`, input), onSuccess: refresh })
}

export function useReopenSubscription(tenantId: string) {
  const refresh = useRefreshBilling(tenantId)

  return useMutation({ mutationFn: (input: { days: number; reason: string }) => post(`/platform/tenants/${tenantId}/subscription/reopen`, input), onSuccess: refresh })
}

export function useRecordContact(tenantId: string) {
  const refresh = useRefreshBilling(tenantId)

  return useMutation({ mutationFn: (note: string) => post(`/platform/tenants/${tenantId}/subscription/contact`, { note }), onSuccess: refresh })
}

export function useRenewals(options: { withinDays: number; contacted: '' | 'yes' | 'no'; page: number }) {
  const params = new URLSearchParams({ within_days: String(options.withinDays), page: String(options.page), per_page: '20' })
  if (options.contacted !== '') params.set('contacted', options.contacted === 'yes' ? '1' : '0')

  return useQuery({
    queryKey: ['platform', 'renewals', options],
    queryFn: () => get<Paginated<Renewal>>(`/platform/subscriptions/renewals?${params.toString()}`),
    placeholderData: keepPreviousData,
  })
}

// --- Gói và giá ---------------------------------------------------------------------------------

const PLANS = ['platform', 'plans'] as const

export function usePlans() {
  return useQuery({ queryKey: PLANS, queryFn: () => get<{ data: Plan[] }>('/platform/plans').then((r) => r.data) })
}

export interface PlanInput {
  key?: string
  name: string
  description: string | null
  price_monthly: number
  price_yearly: number
  max_branches: number | null
  storage_mb: number | null
  is_active: boolean
  sort_order: number
}

/** Thêm gói (kèm `key`) hoặc sửa gói có sẵn (`id`). Đổi giá không đổi giá của khách đang dùng. */
export function useSavePlan(id?: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: PlanInput) => (id ? api('PATCH', `/platform/plans/${id}`, input) : post('/platform/plans', input)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: PLANS }),
  })
}

