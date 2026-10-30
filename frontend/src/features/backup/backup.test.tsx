import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import { meOk, mockApi, platformAdmin, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { BackupRow } from './api.ts'
import { DownloadDialog } from './DownloadDialog.tsx'

const row = (overrides: Partial<BackupRow> = {}): BackupRow => ({
  id: 'b1',
  scope: 'platform',
  kind: 'auto',
  status: 'completed',
  size_bytes: 148074,
  checksum_sha256: 'a'.repeat(64),
  key_fingerprint: '0123456789abcdef',
  error: null,
  verify_status: 'passed',
  verified_at: '2026-10-30T04:30:00Z',
  verify_error: null,
  downloaded_at: null,
  created_at: '2026-10-30T01:30:00Z',
  completed_at: '2026-10-30T01:31:00Z',
  ...overrides,
})

const status = (overrides: Record<string, unknown> = {}) => ({
  json: {
    data: {
      state: 'critical',
      enabled: true,
      running: false,
      last_completed: { id: 'b1', completed_at: '2026-10-28T01:31:00Z', age_hours: 40, size_bytes: 148074, verify_status: 'passed', downloaded_at: null },
      last_attempt: null,
      last_verified: { id: 'b1', status: 'passed', at: '2026-10-28T04:30:00Z', error: null },
      last_downloaded_at: null,
      encryption: { algorithm: 'XChaCha20-Poly1305 (libsodium secretstream)', dedicated_key: false, key_fingerprint: '0123456789abcdef' },
      issues: [{ code: 'backup_overdue', level: 'critical', message: 'Bản sao lưu toàn hệ thống hoàn tất gần nhất đã cách đây 40 giờ (quá hạn 36 giờ).' }],
      schedule: { backup_at: '01:30', prune_at: '03:30', verify_day: 0, verify_at: '04:30' },
      ...overrides,
    },
  },
})

const platformApi = (extra: Record<string, unknown> = {}) => ({
  'GET /api/v1/auth/me': meOk(platformAdmin()),
  'GET /api/v1/platform/backups/status': status(),
  'GET /api/v1/platform/backups': { json: { data: [row(), row({ id: 'b2', status: 'failed', size_bytes: null, error: 'Không kết nối được máy chủ cơ sở dữ liệu.', verify_status: null })] } },
  ...extra,
})

describe('trang sao lưu hệ thống (Super Admin)', () => {
  it('hiện tình trạng, vấn đề đang có, mã hóa và danh sách bản sao lưu', async () => {
    mockApi(platformApi())
    renderApp('/platform/backups')

    expect(await screen.findByText('Nghiêm trọng')).toBeInTheDocument()
    expect(screen.getByText(/đã cách đây 40 giờ/)).toBeInTheDocument()
    expect(screen.getByText('Đang dùng chung APP_KEY', { exact: false })).toBeInTheDocument()
    expect(screen.getByText('Sao lưu 01:30 hằng ngày · dọn bản cũ 03:30 · khôi phục thử Chủ nhật 04:30')).toBeInTheDocument()

    const table = await screen.findByRole('table')
    expect(within(table).getByText('Hoàn tất')).toBeInTheDocument()
    expect(within(table).getByText('Thất bại')).toBeInTheDocument()
    expect(within(table).getByText('Không kết nối được máy chủ cơ sở dữ liệu.')).toBeInTheDocument()
    expect(within(table).getAllByText('0123456789abcdef')).not.toHaveLength(0)
    // Chỉ bản hoàn tất mới có nút tải.
    expect(within(table).getAllByRole('button', { name: /Tải về/ })).toHaveLength(1)
  })

  it('"Sao lưu ngay" gửi yêu cầu và báo đã nhận', async () => {
    const server = mockApi(platformApi({ 'POST /api/v1/platform/backups': { status: 202, json: { data: row({ status: 'pending' }) } } }))
    renderApp('/platform/backups')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Sao lưu ngay' }))

    expect(await screen.findByText(/Đã gửi yêu cầu sao lưu/)).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/platform/backups')).toHaveLength(1)
  })

  it('không cho bấm "Sao lưu ngay" khi đang có bản chạy', async () => {
    mockApi(platformApi({ 'GET /api/v1/platform/backups': { json: { data: [row({ status: 'running', size_bytes: null })] } } }))
    renderApp('/platform/backups')

    await screen.findByText('Đang chạy', { selector: 'span' })
    expect(screen.getByRole('button', { name: 'Sao lưu ngay' })).toBeDisabled()
  })

  it('tải về: bắt nhập mật khẩu, báo sai mật khẩu, rồi mới nhận liên kết', async () => {
    const server = mockApi(
      platformApi({
        'POST /api/v1/platform/backups/b1/download-link': (call: { body: unknown }) =>
          (call.body as { password: string }).password === 'dung'
            ? { json: { data: { url: '/api/v1/platform/backups/b1/download?nonce=x', expires_at: '2026-10-30T10:02:00Z' } } }
            : problem(422, 'confirmation_failed', 'Mật khẩu không đúng.', { errors: { password: ['Mật khẩu không đúng.'] } }),
      }),
    )
    renderApp('/platform/backups')
    const user = userEvent.setup()

    const table = await screen.findByRole('table')
    await user.click(within(table).getByRole('button', { name: /Tải về/ }))

    const dialog = await screen.findByRole('dialog', { name: 'Tải bản sao lưu' })
    expect(within(dialog).getByText(/dấu vân tay 0123456789abcdef/)).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Tải về' }))
    expect(await within(dialog).findByText('Vui lòng nhập mật khẩu.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/platform/backups/b1/download-link')).toHaveLength(0)

    await user.type(within(dialog).getByLabelText('Mật khẩu của bạn'), 'sai')
    await user.click(within(dialog).getByRole('button', { name: 'Tải về' }))
    expect(await within(dialog).findByText('Mật khẩu không đúng.')).toBeInTheDocument()
  })

  it('Esc đóng hộp thoại tải về', async () => {
    mockApi(platformApi())
    renderApp('/platform/backups')
    const user = userEvent.setup()

    await user.click(within(await screen.findByRole('table')).getByRole('button', { name: /Tải về/ }))
    await screen.findByRole('dialog')
    await user.keyboard('{Escape}')

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })
})

describe('hộp thoại tải về', () => {
  function renderDialog(response: unknown) {
    const server = mockApi({ 'POST /api/v1/platform/backups/b1/download-link': response as never })
    const navigate = vi.fn()
    const onClose = vi.fn()
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })

    render(
      <QueryClientProvider client={client}>
        <DownloadDialog backup={row()} onClose={onClose} navigate={navigate} />
      </QueryClientProvider>,
    )

    return { server, navigate, onClose }
  }

  it('đúng mật khẩu thì điều hướng trình duyệt tới liên kết dùng một lần và xóa mật khẩu khỏi form', async () => {
    const { server, navigate } = renderDialog({ json: { data: { url: '/api/v1/platform/backups/b1/download?nonce=x&signature=y', expires_at: '2026-10-30T10:02:00Z' } } })
    const user = userEvent.setup()

    await user.type(screen.getByLabelText('Mật khẩu của bạn'), 'Mat-khau-2026')
    await user.click(screen.getByRole('button', { name: 'Tải về' }))

    await waitFor(() => expect(navigate).toHaveBeenCalledWith('/api/v1/platform/backups/b1/download?nonce=x&signature=y'))
    expect(server.to('POST', '/api/v1/platform/backups/b1/download-link')[0]?.body).toEqual({ password: 'Mat-khau-2026' })
    expect(await screen.findByText(/Đang tải file về/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Mật khẩu của bạn')).not.toBeInTheDocument()
  })

  it('báo rõ khi file đã mất khỏi máy chủ', async () => {
    const { navigate } = renderDialog(problem(409, 'backup_file_missing', 'File sao lưu đã bị mất khỏi nơi lưu trữ.'))
    const user = userEvent.setup()

    await user.type(screen.getByLabelText('Mật khẩu của bạn'), 'Mat-khau-2026')
    await user.click(screen.getByRole('button', { name: 'Tải về' }))

    expect(await screen.findByText('Bản sao lưu chưa sẵn sàng hoặc file đã bị mất khỏi máy chủ.')).toBeInTheDocument()
    expect(navigate).not.toHaveBeenCalled()
  })
})

describe('trang sao lưu dữ liệu (Chủ doanh nghiệp)', () => {
  const tenantRow = (overrides: Partial<BackupRow> = {}) => row({ id: 't1', scope: 'tenant', key_fingerprint: null, verify_status: null, ...overrides })

  it('liệt kê bản tự động và thủ công, tải về bằng liên kết trực tiếp, không có cột của sao lưu hệ thống', async () => {
    mockApi({
      'GET /api/v1/auth/me': meOk(tenantUser()),
      'GET /api/v1/backups': { json: { data: [tenantRow(), tenantRow({ id: 't2', kind: 'manual' })] } },
    })
    renderApp('/backups')

    const table = await screen.findByRole('table')
    expect(within(table).getByText('Tự động')).toBeInTheDocument()
    expect(within(table).getByText('Thủ công')).toBeInTheDocument()
    expect(within(table).queryByText('Khôi phục thử')).not.toBeInTheDocument()

    const links = within(table).getAllByRole('link', { name: /Tải về/ })
    expect(links.map((a) => a.getAttribute('href'))).toEqual(['/api/v1/backups/t1/download', '/api/v1/backups/t2/download'])
  })

  it('ẩn nút tạo và nút tải khi không có quyền tương ứng', async () => {
    mockApi({
      'GET /api/v1/auth/me': meOk(tenantUser({ permissions: { 'backup.backup.view': 'tenant' } })),
      'GET /api/v1/backups': { json: { data: [tenantRow()] } },
    })
    renderApp('/backups')

    await screen.findByRole('table')
    expect(screen.queryByRole('button', { name: 'Sao lưu ngay' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Tải về/ })).not.toBeInTheDocument()
  })

  it('tạo bản sao lưu thủ công', async () => {
    const server = mockApi({
      'GET /api/v1/auth/me': meOk(tenantUser()),
      'GET /api/v1/backups': { json: { data: [] } },
      'POST /api/v1/backups': { status: 202, json: { data: tenantRow({ status: 'pending' }) } },
    })
    renderApp('/backups')
    const user = userEvent.setup()

    expect(await screen.findByText('Chưa có bản sao lưu nào.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Sao lưu ngay' }))

    expect(await screen.findByText(/Đã gửi yêu cầu sao lưu/)).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/backups')).toHaveLength(1)
  })
})
