import { createContext, useContext } from 'react'
import type { SessionProblemCode } from '../../lib/api.ts'
import type { Me, PermissionScope } from './types.ts'

export interface AuthState {
  user: Me | null
  isLoading: boolean
  /** Lý do phiên bị kết thúc (hiện ở trang đăng nhập), nếu có. */
  sessionNotice: SessionProblemCode | null
  clearSessionNotice: () => void
  /** Có quyền `key` với phạm vi tối thiểu `minScope` (mặc định: bất kỳ phạm vi nào). */
  can: (key: string, minScope?: PermissionScope) => boolean
  signIn: (user: Me) => void
  signOut: () => Promise<void>
  refresh: () => Promise<void>
}

export const AuthContext = createContext<AuthState | null>(null)

export function useAuth(): AuthState {
  const value = useContext(AuthContext)

  if (value === null) throw new Error('useAuth phải được dùng bên trong <AuthProvider>.')

  return value
}

const RANK: Record<PermissionScope, number> = { own: 1, branch: 2, tenant: 3 }

export function hasPermission(permissions: Me['permissions'] | undefined, key: string, minScope: PermissionScope = 'own'): boolean {
  const scope = permissions?.[key]

  return scope !== undefined && RANK[scope] >= RANK[minScope]
}
