import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, get, post } from '../../lib/api.ts'
import type { Paginated } from '../../lib/types.ts'
import type { Branch } from '../branches/api.ts'

export type UserStatus = 'active' | 'disabled'

export interface UserRow {
  id: string
  name: string
  email: string
  phone: string | null
  status: UserStatus
  is_platform_admin: boolean
  roles: { id: string; key: string; name: string }[]
  branches: Branch[]
  last_login_at: string | null
}

/** Mật khẩu tạm chỉ được máy chủ trả MỘT lần (khi tạo người dùng hoặc cấp lại mật khẩu). */
export interface WithTemporaryPassword {
  data: UserRow
  temporary_password?: string
}

const KEY = ['users'] as const

export function useUsers(options: { q?: string; page?: number } = {}) {
  const { q = '', page = 1 } = options
  const params = new URLSearchParams({ page: String(page), per_page: '20' })
  if (q.trim() !== '') params.set('q', q.trim())

  return useQuery({
    queryKey: [...KEY, { q: q.trim(), page }],
    queryFn: () => get<Paginated<UserRow>>(`/users?${params.toString()}`),
    placeholderData: keepPreviousData,
  })
}

export interface CreateUserInput {
  name: string
  email: string
  phone: string
  role_ids: string[]
  branch_ids: string[]
}

export function useCreateUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateUserInput) => post<WithTemporaryPassword>('/users', { ...input, phone: input.phone.trim() || undefined }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
}

export interface EditUserInput {
  name: string
  phone: string
  /** Chỉ gửi khi người dùng thật sự đổi (tránh gọi API và ghi audit thừa). */
  role_ids?: string[]
  branch_ids?: string[]
}

/** Sửa hồ sơ rồi (nếu có đổi) cập nhật vai trò và chi nhánh. Các bước chạy lần lượt; lỗi ở bước nào dừng ở bước đó. */
export function useEditUser(userId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: EditUserInput) => {
      await api('PATCH', `/users/${userId}`, { name: input.name, phone: input.phone.trim() || null })
      if (input.role_ids) await api('PUT', `/users/${userId}/roles`, { role_ids: input.role_ids })
      if (input.branch_ids) await api('PUT', `/users/${userId}/branches`, { branch_ids: input.branch_ids })
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
}

export function useSetUserStatus() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { id: string; status: UserStatus; reason?: string }) => api('PATCH', `/users/${input.id}`, { status: input.status, reason: input.reason }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
}

export function useResetPassword() {
  return useMutation({
    mutationFn: (id: string) => post<WithTemporaryPassword>(`/users/${id}/reset-password`),
  })
}

export function useDeleteUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => del(`/users/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
}
