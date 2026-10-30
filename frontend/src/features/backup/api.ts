import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { get, post } from '../../lib/api.ts'

export type BackupScope = 'platform' | 'tenant'
export type BackupStatusValue = 'pending' | 'running' | 'completed' | 'failed'

export interface BackupRow {
  id: string
  scope: BackupScope
  kind: 'manual' | 'auto' | 'upload' | 'pre_restore'
  status: BackupStatusValue
  size_bytes: number | null
  checksum_sha256: string | null
  key_fingerprint: string | null
  error: string | null
  verify_status: 'passed' | 'failed' | null
  verified_at: string | null
  verify_error: string | null
  downloaded_at: string | null
  created_at: string
  completed_at: string | null
}

export interface BackupIssue {
  code: string
  level: 'warning' | 'critical'
  message: string
}

export interface PlatformBackupState {
  state: 'ok' | 'warning' | 'critical' | 'disabled'
  enabled: boolean
  running: boolean
  last_completed: { id: string; completed_at: string; age_hours: number; size_bytes: number | null; verify_status: string | null; downloaded_at: string | null } | null
  last_attempt: { id: string; status: string; at: string; error: string | null } | null
  last_verified: { id: string; status: string | null; at: string; error: string | null } | null
  last_downloaded_at: string | null
  encryption: { algorithm: string; dedicated_key: boolean; key_fingerprint: string | null }
  issues: BackupIssue[]
  schedule: { backup_at: string; prune_at: string; verify_day: number; verify_at: string }
}

const base = (scope: BackupScope) => (scope === 'platform' ? '/platform/backups' : '/backups')

const inFlight = (rows: BackupRow[] | undefined) => rows?.some((row) => row.status === 'pending' || row.status === 'running') ?? false

/** Danh sách bản sao lưu; tự làm mới nhanh hơn khi đang có bản chạy dở. */
export function useBackups(scope: BackupScope) {
  return useQuery({
    queryKey: ['backups', scope],
    queryFn: async () => (await get<{ data: BackupRow[] }>(`${base(scope)}?per_page=50`)).data,
    refetchInterval: (query) => (inFlight(query.state.data) ? 4_000 : 60_000),
  })
}

export function usePlatformBackupStatus() {
  return useQuery({
    queryKey: ['backups', 'platform', 'status'],
    queryFn: async () => (await get<{ data: PlatformBackupState }>('/platform/backups/status')).data,
    refetchInterval: 30_000,
  })
}

export function useCreateBackup(scope: BackupScope) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => post(base(scope)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['backups'] }),
  })
}

/** Bước 1 của việc tải bản sao lưu toàn hệ thống: nhập lại mật khẩu để nhận liên kết dùng một lần. */
export function useDownloadLink() {
  return useMutation({
    mutationFn: async (input: { id: string; password: string }) =>
      (await post<{ data: { url: string; expires_at: string } }>(`/platform/backups/${input.id}/download-link`, { password: input.password })).data,
  })
}
