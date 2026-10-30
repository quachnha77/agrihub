import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, platformAdmin, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { SystemHealthData } from './api.ts'

const health = (overrides: Partial<SystemHealthData> = {}): { json: { data: SystemHealthData } } => ({
  json: {
    data: {
      status: 'critical',
      checked_at: '2026-10-30T10:00:00Z',
      components: [
        { key: 'database', label: 'Cơ sở dữ liệu', status: 'ok', summary: 'Kết nối bình thường (3 ms)', details: [{ label: 'Phiên bản PostgreSQL', value: '16.13' }] },
        { key: 'queue_worker', label: 'Queue worker', status: 'critical', summary: 'Queue worker không gửi tín hiệu đã 20 phút; có thể đã dừng.', details: [{ label: 'Cách đây', value: '20 phút' }] },
        { key: 'disk', label: 'Dung lượng đĩa', status: 'warning', summary: 'Cần chú ý: Ứng dụng (storage): đã dùng 82%', details: [] },
        { key: 'backups', label: 'Sao lưu', status: 'disabled', summary: 'Sao lưu toàn hệ thống đang tắt.', details: [] },
      ],
      ...overrides,
    },
  },
})

const backupStatus = { json: { data: { state: 'ok', enabled: true, running: false, issues: [], last_completed: null, last_attempt: null, last_verified: null, last_downloaded_at: null, encryption: { algorithm: 'x', dedicated_key: true, key_fingerprint: 'abc' }, schedule: { backup_at: '01:30', prune_at: '03:30', verify_day: 0, verify_at: '04:30' } } } }

describe('trang Sức khỏe hệ thống', () => {
  it('hiện tình trạng chung và từng thành phần với mức độ, tóm tắt và chi tiết', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(platformAdmin()), 'GET /api/v1/platform/health': health() })
    renderApp('/platform/health')

    expect(await screen.findByRole('heading', { name: 'Sức khỏe hệ thống' })).toBeInTheDocument()

    const overall = (await screen.findByText('Tình trạng chung')).parentElement as HTMLElement
    expect(within(overall).getByText('Nghiêm trọng')).toBeInTheDocument()

    const worker = screen.getByRole('region', { name: 'Queue worker' })
    expect(within(worker).getByRole('alert')).toHaveTextContent('không gửi tín hiệu đã 20 phút')
    expect(within(worker).getByText('Cách đây')).toBeInTheDocument()

    const db = screen.getByRole('region', { name: 'Cơ sở dữ liệu' })
    expect(within(db).getByText('Kết nối bình thường (3 ms)')).toBeInTheDocument()
    expect(within(db).getByText('16.13')).toBeInTheDocument()

    expect(within(screen.getByRole('region', { name: 'Dung lượng đĩa' })).getByRole('alert')).toHaveTextContent('đã dùng 82%')
    expect(within(screen.getByRole('region', { name: 'Sao lưu' })).queryByRole('alert')).not.toBeInTheDocument()
  })

  it('"Kiểm tra lại" gọi lại API', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': meOk(platformAdmin()), 'GET /api/v1/platform/health': health() })
    renderApp('/platform/health')
    const user = userEvent.setup()

    await screen.findByText('Tình trạng chung')
    await user.click(screen.getByRole('button', { name: 'Kiểm tra lại' }))

    await screen.findByText('Tình trạng chung')
    expect(server.to('GET', '/api/v1/platform/health').length).toBeGreaterThanOrEqual(2)
  })

  it('báo lỗi khi không tải được', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(platformAdmin()), 'GET /api/v1/platform/health': problem(500, 'server_error', 'x') })
    renderApp('/platform/health')

    expect(await screen.findByText('Không tải được dữ liệu. Vui lòng thử lại.')).toBeInTheDocument()
  })

  it('chỉ Super Admin thấy menu và vào được trang', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser()) })
    renderApp('/platform/health')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Menu chính' })).not.toHaveTextContent('Sức khỏe hệ thống')
  })

  it('trang Tổng quan của Super Admin nêu các thành phần đang có vấn đề', async () => {
    mockApi({
      'GET /api/v1/auth/me': meOk(platformAdmin()),
      'GET /api/v1/platform/health': health(),
      'GET /api/v1/platform/backups/status': backupStatus,
    })
    renderApp('/')

    const card = (await screen.findByText('Sức khỏe hệ thống', { selector: 'h2' })).closest('section') as HTMLElement

    expect(await within(card).findByText('Queue worker')).toBeInTheDocument()
    expect(within(card).getByText('Dung lượng đĩa')).toBeInTheDocument()
    expect(within(card).queryByText('Cơ sở dữ liệu')).not.toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Menu chính' })).toHaveTextContent('Sức khỏe hệ thống')
  })
})
