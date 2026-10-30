import { useQuery } from '@tanstack/react-query'
import { get } from '../../lib/api.ts'

export type PermissionScope = 'own' | 'branch' | 'tenant'

export interface Role {
  id: string
  key: string
  name: string
  description: string | null
  is_system: boolean
  users_count?: number
  permissions: { key: string; scope: PermissionScope }[]
}

export interface PermissionResource {
  resource: string
  label: string
  actions: string[]
}

/** Vai trò của doanh nghiệp (không phân trang). Cần quyền `tenant.role.view`. */
export function useRoles(enabled = true) {
  return useQuery({
    queryKey: ['roles'],
    queryFn: async () => (await get<{ data: Role[] }>('/roles')).data,
    enabled,
    retry: false,
  })
}

/** Catalog quyền theo resource (nhãn tiếng Việt do máy chủ cung cấp). */
export function usePermissionCatalog() {
  return useQuery({
    queryKey: ['permission-catalog'],
    queryFn: async () => (await get<{ data: PermissionResource[] }>('/permissions')).data,
    staleTime: Infinity,
  })
}
