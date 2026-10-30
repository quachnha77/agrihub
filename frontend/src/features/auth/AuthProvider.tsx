import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { ApiError, get, isSessionProblem, onSessionProblem, post, type SessionProblemCode } from '../../lib/api.ts'
import { AuthContext, hasPermission, type AuthState } from './auth-context.ts'
import type { Envelope, Me, PermissionScope } from './types.ts'

const ME_KEY = ['me'] as const

/**
 * Giữ hồ sơ người đăng nhập (GET /auth/me). `null` = chưa đăng nhập. Khi API báo phiên không còn dùng được
 * (hết phiên, hết hạn gói, doanh nghiệp bị khóa...) thì xóa hồ sơ và nhớ lý do để trang đăng nhập hiển thị.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [sessionNotice, setSessionNotice] = useState<SessionProblemCode | null>(null)

  const me = useQuery<Me | null>({
    queryKey: ME_KEY,
    queryFn: async () => {
      try {
        return (await get<Envelope<Me>>('/auth/me', { silent: true })).data
      } catch (error) {
        if (error instanceof ApiError && isSessionProblem(error.code)) {
          // 401 lúc mở trang là bình thường (chưa đăng nhập); các mã còn lại là lý do cần cho người dùng biết.
          if (error.code !== 'unauthenticated') setSessionNotice(error.code)

          return null
        }

        throw error
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  })

  useEffect(
    () =>
      onSessionProblem((code) => {
        setSessionNotice(code)
        queryClient.setQueryData(ME_KEY, null)
        queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'me' })
      }),
    [queryClient],
  )

  const signIn = useCallback(
    (user: Me) => {
      setSessionNotice(null)
      queryClient.setQueryData(ME_KEY, user)
    },
    [queryClient],
  )

  const signOut = useCallback(async () => {
    try {
      await post('/auth/logout', undefined, { silent: true })
    } finally {
      // Không dùng queryClient.clear(): nó ngắt truy vấn hồ sơ đang được theo dõi nên giao diện vẫn tưởng còn đăng nhập.
      queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'me' })
      queryClient.setQueryData(ME_KEY, null)
    }
  }, [queryClient])

  const refresh = useCallback(async () => {
    await queryClient.invalidateQueries({ queryKey: ME_KEY })
  }, [queryClient])

  const user = me.data ?? null

  const value = useMemo<AuthState>(
    () => ({
      user,
      isLoading: me.isPending,
      sessionNotice,
      clearSessionNotice: () => setSessionNotice(null),
      can: (key: string, minScope?: PermissionScope) => hasPermission(user?.permissions, key, minScope),
      signIn,
      signOut,
      refresh,
    }),
    [user, me.isPending, sessionNotice, signIn, signOut, refresh],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
