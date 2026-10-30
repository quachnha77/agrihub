import { useQuery } from '@tanstack/react-query'
import { get } from '../../lib/api.ts'

export type HealthStatus = 'ok' | 'warning' | 'critical' | 'disabled'

export interface HealthComponent {
  key: string
  label: string
  status: HealthStatus
  summary: string
  details: { label: string; value: string }[]
}

export interface SystemHealthData {
  /** Trạng thái xấu nhất của các thành phần đang bật. */
  status: Exclude<HealthStatus, 'disabled'>
  checked_at: string
  components: HealthComponent[]
}

/** Sức khỏe hạ tầng (Super Admin); tự làm mới mỗi 30 giây. */
export function useSystemHealth() {
  return useQuery({
    queryKey: ['health'],
    queryFn: async () => (await get<{ data: SystemHealthData }>('/platform/health')).data,
    refetchInterval: 30_000,
  })
}
