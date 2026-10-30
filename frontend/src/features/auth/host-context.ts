import { useQuery } from '@tanstack/react-query'
import { get } from '../../lib/api.ts'

/**
 * Mỗi doanh nghiệp một subdomain: địa chỉ đang mở là của nền tảng (`platform`), của một doanh nghiệp (`tenant`) hay chưa phân biệt
 * (`unrestricted`: tính năng subdomain đang tắt). Địa chỉ không có doanh nghiệp nào trả 404 `tenant_not_found`.
 */
export interface HostContext {
  kind: 'platform' | 'tenant' | 'unrestricted'
  tenant: { name: string; slug: string } | null
}

export function useHostContext() {
  return useQuery({
    queryKey: ['host-context'],
    queryFn: async () => (await get<{ data: HostContext }>('/tenant-context', { silent: true })).data,
    staleTime: Infinity,
    retry: false,
  })
}
