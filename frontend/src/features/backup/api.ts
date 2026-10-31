import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, get, post, postForm } from '../../lib/api.ts'

export type BackupScope = 'platform' | 'tenant'
export type BackupStatusValue = 'pending' | 'running' | 'completed' | 'failed'

/**
 * Nơi lấy bản sao lưu: toàn hệ thống (Super Admin), chính doanh nghiệp của người đăng nhập ('tenant'),
 * hoặc MỘT doanh nghiệp do Super Admin chọn.
 */
export type BackupSource = BackupScope | { tenantId: string }

/** Nguồn có Import / Khôi phục (sao lưu toàn hệ thống chỉ khôi phục bằng dòng lệnh trên máy chủ). */
export type TenantBackupSource = Exclude<BackupSource, 'platform'>

export interface BackupRow {
  id: string
  scope: BackupScope
  kind: 'manual' | 'auto' | 'upload' | 'pre_restore'
  status: BackupStatusValue
  size_bytes: number | null
  checksum_sha256: string | null
  key_fingerprint: string | null
  /** Số dòng từng bảng dữ liệu nghiệp vụ trong bản sao lưu (trống khi chưa có module nghiệp vụ). */
  row_counts: Record<string, number> | null
  /** Tên file người dùng đã tải lên (chỉ bản `upload`). */
  original_name: string | null
  error: string | null
  verify_status: 'passed' | 'failed' | null
  verified_at: string | null
  verify_error: string | null
  downloaded_at: string | null
  created_at: string
  completed_at: string | null
}

export interface RestoreRunRow {
  id: string
  status: BackupStatusValue
  /** Bản sao lưu đã dùng để khôi phục. */
  backup_id: string | null
  /** Bản "điểm quay lại" tự tạo trước khi ghi đè: khôi phục lại bản này để hoàn tác. */
  pre_restore_backup_id: string | null
  row_counts: Record<string, number> | null
  error: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string
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

/** Đường dẫn API (sau /api/v1) của danh sách bản sao lưu theo nguồn. */
export const backupPath = (source: BackupSource): string =>
  source === 'platform' ? '/platform/backups' : source === 'tenant' ? '/backups' : `/platform/tenants/${source.tenantId}/backups`

const sourceKey = (source: BackupSource): string => (typeof source === 'string' ? source : source.tenantId)

const inFlight = (rows: { status: BackupStatusValue }[] | undefined) => rows?.some((row) => row.status === 'pending' || row.status === 'running') ?? false

/** Trong lúc khôi phục, máy chủ trả 503 `tenant_restoring` cho mọi yêu cầu của doanh nghiệp: đó là tín hiệu "đang khôi phục", không phải lỗi. */
export const isRestoringError = (error: unknown): boolean => error instanceof ApiError && error.code === 'tenant_restoring'

/** Không thử lại lỗi "đang khôi phục" (đã có vòng làm mới riêng); các lỗi khác thử lại một lần như mặc định của ứng dụng. */
const retryUnlessRestoring = (count: number, error: unknown) => !isRestoringError(error) && count < 1

/** Danh sách bản sao lưu; tự làm mới nhanh hơn khi đang có bản chạy dở hoặc doanh nghiệp đang khôi phục. */
export function useBackups(source: BackupSource) {
  return useQuery({
    queryKey: ['backups', sourceKey(source)],
    queryFn: async () => (await get<{ data: BackupRow[] }>(`${backupPath(source)}?per_page=50`)).data,
    refetchInterval: (query) => (inFlight(query.state.data) || isRestoringError(query.state.error) ? 4_000 : 60_000),
    retry: retryUnlessRestoring,
  })
}

/** Lịch sử các lần khôi phục (mới nhất trước). Làm mới mỗi vài giây khi đang có lần khôi phục dở. */
export function useRestoreRuns(source: TenantBackupSource) {
  return useQuery({
    queryKey: ['backups', sourceKey(source), 'restores'],
    queryFn: async () => (await get<{ data: RestoreRunRow[] }>(`${backupPath(source)}/restores?per_page=10`)).data,
    refetchInterval: (query) => (inFlight(query.state.data) || isRestoringError(query.state.error) ? 3_000 : 60_000),
    retry: retryUnlessRestoring,
  })
}

export function usePlatformBackupStatus() {
  return useQuery({
    queryKey: ['backups', 'platform', 'status'],
    queryFn: async () => (await get<{ data: PlatformBackupState }>('/platform/backups/status')).data,
    refetchInterval: 30_000,
  })
}

export function useCreateBackup(source: BackupSource) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => post(backupPath(source)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['backups'] }),
  })
}

/**
 * Import: tải lên file .agbk đã Export trước đó. Máy chủ giải mã và kiểm tra toàn bộ ngay; file hỏng vẫn để lại một dòng
 * "Thất bại" trong danh sách để truy vết nên làm mới danh sách cả khi lỗi.
 */
export function useImportBackup(source: TenantBackupSource) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData()
      form.append('file', file)

      return (await postForm<{ data: BackupRow }>(`${backupPath(source)}/import`, form)).data
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['backups'] }),
  })
}

/** Khôi phục từ một bản sao lưu (chạy nền, 202): cần mật khẩu của chính người thực hiện và gõ đúng tên doanh nghiệp. */
export function useRestoreBackup(source: TenantBackupSource) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: { id: string; password: string; confirm_name: string }) =>
      (await post<{ data: RestoreRunRow }>(`${backupPath(source)}/${input.id}/restore`, { password: input.password, confirm_name: input.confirm_name })).data,
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['backups'] }),
  })
}

/** Bước 1 của việc tải bản sao lưu toàn hệ thống: nhập lại mật khẩu để nhận liên kết dùng một lần. */
export function useDownloadLink() {
  return useMutation({
    mutationFn: async (input: { id: string; password: string }) =>
      (await post<{ data: { url: string; expires_at: string } }>(`/platform/backups/${input.id}/download-link`, { password: input.password })).data,
  })
}
