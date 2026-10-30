import { Download } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, type Tone } from '../../components/ui.tsx'
import { formatBytes, formatDateTime } from '../../lib/format.ts'
import type { BackupRow, BackupStatusValue } from './api.ts'

const statusTone: Record<BackupStatusValue, Tone> = { pending: 'neutral', running: 'info', completed: 'success', failed: 'danger' }

/** Bảng bản sao lưu dùng chung cho Super Admin (toàn hệ thống) và Chủ doanh nghiệp. */
export function BackupTable({
  rows,
  showPlatformColumns = false,
  onDownload,
  downloadHref,
}: {
  rows: BackupRow[]
  /** Cột khôi phục thử và dấu vân tay khóa (chỉ có ý nghĩa với sao lưu toàn hệ thống). */
  showPlatformColumns?: boolean
  /** Có thì hiện nút "Tải về" gọi hàm này (cần nhập mật khẩu trước). */
  onDownload?: (row: BackupRow) => void
  /** Có thì "Tải về" là liên kết trực tiếp (Tenant: quyền đã kiểm tra ở máy chủ). */
  downloadHref?: (row: BackupRow) => string
}) {
  const { t } = useTranslation()

  if (rows.length === 0) return <p className="py-6 text-center text-sm text-slate-500">{t('backup.list.empty')}</p>

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[40rem] text-left text-sm">
        <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th scope="col" className="py-2 pr-3">{t('backup.list.time')}</th>
            <th scope="col" className="py-2 pr-3">{t('backup.list.kind')}</th>
            <th scope="col" className="py-2 pr-3">{t('backup.list.status')}</th>
            <th scope="col" className="py-2 pr-3">{t('backup.list.size')}</th>
            {showPlatformColumns && <th scope="col" className="py-2 pr-3">{t('backup.list.verify')}</th>}
            <th scope="col" className="py-2 pr-3">{t('backup.list.downloaded')}</th>
            {showPlatformColumns && <th scope="col" className="py-2 pr-3">{t('backup.list.key')}</th>}
            <th scope="col" className="py-2 text-right">{t('backup.list.actions')}</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((row) => (
            <tr key={row.id}>
              <td className="py-2 pr-3 whitespace-nowrap">{formatDateTime(row.created_at)}</td>
              <td className="py-2 pr-3">{t(`backup.kinds.${row.kind}`)}</td>
              <td className="py-2 pr-3">
                <Badge tone={statusTone[row.status]}>{t(`backup.statuses.${row.status}`)}</Badge>
                {row.error && <span className="mt-1 block max-w-xs text-xs text-red-700">{row.error}</span>}
              </td>
              <td className="py-2 pr-3 whitespace-nowrap">{formatBytes(row.size_bytes)}</td>
              {showPlatformColumns && (
                <td className="py-2 pr-3">
                  {row.verify_status ? (
                    <span title={row.verify_error ?? undefined}>
                      <Badge tone={row.verify_status === 'passed' ? 'success' : 'danger'}>{t(`backup.verify.${row.verify_status}`)}</Badge>
                    </span>
                  ) : (
                    <span className="text-slate-400">{t('backup.verify.none')}</span>
                  )}
                </td>
              )}
              <td className="py-2 pr-3 whitespace-nowrap">{formatDateTime(row.downloaded_at)}</td>
              {showPlatformColumns && <td className="py-2 pr-3 font-mono text-xs">{row.key_fingerprint ?? '—'}</td>}
              <td className="py-2 text-right">
                {row.status === 'completed' && onDownload && (
                  <Button variant="secondary" onClick={() => onDownload(row)} aria-label={`${t('backup.actions.download')} ${formatDateTime(row.created_at)}`}>
                    <Download className="h-4 w-4" aria-hidden="true" />
                    {t('backup.actions.download')}
                  </Button>
                )}
                {row.status === 'completed' && downloadHref && (
                  <a
                    href={downloadHref(row)}
                    aria-label={`${t('backup.actions.download')} ${formatDateTime(row.created_at)}`}
                    className="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50"
                  >
                    <Download className="h-4 w-4" aria-hidden="true" />
                    {t('backup.actions.download')}
                  </a>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
