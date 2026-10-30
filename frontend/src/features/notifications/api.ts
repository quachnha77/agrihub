import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { Tone } from '../../components/ui.tsx'
import { get, post } from '../../lib/api.ts'

export type NoticeLevel = 'info' | 'warning' | 'critical'

export const levelTone: Record<NoticeLevel, Tone> = { info: 'info', warning: 'warning', critical: 'danger' }

export interface NoticeItem {
  id: string
  type: string
  level: NoticeLevel
  title: string
  body: string | null
  payload: Record<string, unknown> | null
  read_at: string | null
  created_at: string
}

export interface NoticeList {
  data: NoticeItem[]
  unread_count: number
  meta?: { current_page: number; last_page: number; total: number }
}

const KEY = ['notifications'] as const

/** Thông báo mới nhất cho chuông trên thanh đầu trang; tự làm mới mỗi phút. */
export function useLatestNotices(options: { unreadOnly?: boolean; perPage?: number } = {}) {
  const { unreadOnly = false, perPage = 8 } = options

  return useQuery({
    queryKey: [...KEY, 'latest', { unreadOnly, perPage }],
    queryFn: () => get<NoticeList>(`/notifications?per_page=${perPage}${unreadOnly ? '&unread=1' : ''}`),
    refetchInterval: 60_000,
    staleTime: 30_000,
  })
}

/** Toàn bộ thông báo, xem thêm từng trang. */
export function useAllNotices() {
  return useInfiniteQuery({
    queryKey: [...KEY, 'all'],
    queryFn: ({ pageParam }) => get<NoticeList>(`/notifications?per_page=20&page=${pageParam}`),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta && last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  })
}

export function useMarkNoticeRead() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => post(`/notifications/${id}/read`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
}

export function useMarkAllNoticesRead() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => post('/notifications/read-all'),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
}
