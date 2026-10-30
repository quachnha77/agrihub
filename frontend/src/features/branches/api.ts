import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, del, get, post } from '../../lib/api.ts'
import type { Paginated } from '../../lib/types.ts'

export type BranchStatus = 'active' | 'inactive'

export interface Branch {
  id: string
  code: string
  name: string
  phone: string | null
  address: string | null
  status: BranchStatus
}

export interface BranchInput {
  code: string
  name: string
  phone: string
  address: string
  status?: BranchStatus
}

const KEY = ['branches'] as const

export function useBranches(options: { q?: string; page?: number; perPage?: number } = {}) {
  const { q = '', page = 1, perPage = 20 } = options
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) })
  if (q.trim() !== '') params.set('q', q.trim())

  return useQuery({
    queryKey: [...KEY, { q: q.trim(), page, perPage }],
    queryFn: () => get<Paginated<Branch>>(`/branches?${params.toString()}`),
    placeholderData: keepPreviousData,
  })
}

/** Toàn bộ chi nhánh (để chọn khi gán cho người dùng). */
export function useAllBranches(enabled = true) {
  return useQuery({
    queryKey: [...KEY, 'all'],
    queryFn: async () => (await get<Paginated<Branch>>('/branches?per_page=100')).data,
    enabled,
  })
}

// Chuỗi rỗng ở ô tùy chọn nghĩa là "để trống" => gửi null để máy chủ xóa giá trị.
const clean = (input: BranchInput) => ({ ...input, phone: input.phone.trim() || null, address: input.address.trim() || null })

export function useSaveBranch(existing?: Branch) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: BranchInput) =>
      (await (existing ? api<{ data: Branch }>('PATCH', `/branches/${existing.id}`, clean(input)) : post<{ data: Branch }>('/branches', clean(input)))).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
}

export function useDeleteBranch() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => del(`/branches/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
}
