import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { subscriptionMessage } from '../subscription/messages.ts'
import { meOk, mockApi, platformAdmin, renderApp, tenantUser } from '../../test/utils.tsx'

const notice = (overrides: Record<string, unknown> = {}) => ({
  id: 'n1',
  type: 'backup.platform.failed',
  level: 'critical',
  title: 'Sao lưu toàn hệ thống THẤT BẠI',
  body: 'Lý do: Không kết nối được máy chủ cơ sở dữ liệu.',
  payload: null,
  read_at: null,
  created_at: new Date().toISOString(),
  ...overrides,
})

const list = (items: unknown[], unread = items.length) => ({ json: { data: items, unread_count: unread, meta: { current_page: 1, last_page: 1, total: items.length } } })

describe('chuông thông báo', () => {
  it('hiện số chưa đọc, mở danh sách và đánh dấu đã đọc khi bấm vào một thông báo', async () => {
    const server = mockApi({
      'GET /api/v1/auth/me': meOk(tenantUser()),
      'GET /api/v1/notifications': list([notice(), notice({ id: 'n2', level: 'info', title: 'Bản sao lưu đã sẵn sàng', body: null })], 2),
      'POST /api/v1/notifications/n1/read': { json: { data: notice({ read_at: new Date().toISOString() }) } },
    })
    renderApp('/')
    const user = userEvent.setup()

    expect(await screen.findByTestId('unread-count')).toHaveTextContent('2')

    await user.click(screen.getByRole('button', { name: /Mở hộp thông báo/ }))
    const panel = within(screen.getByText('Thông báo', { selector: 'strong' }).closest('div')!.parentElement!)
    expect(panel.getByText('Sao lưu toàn hệ thống THẤT BẠI')).toBeInTheDocument()
    expect(panel.getByText('Nghiêm trọng')).toBeInTheDocument()

    await user.click(panel.getByText('Sao lưu toàn hệ thống THẤT BẠI'))

    await waitFor(() => expect(server.to('POST', '/api/v1/notifications/n1/read')).toHaveLength(1))
  })

  it('đánh dấu tất cả đã đọc', async () => {
    const server = mockApi({
      'GET /api/v1/auth/me': meOk(tenantUser()),
      'GET /api/v1/notifications': list([notice()]),
      'POST /api/v1/notifications/read-all': { json: { data: { updated: 1 } } },
    })
    renderApp('/')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /Mở hộp thông báo/ }))
    await user.click(screen.getByRole('button', { name: 'Đánh dấu tất cả đã đọc' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/notifications/read-all')).toHaveLength(1))
  })

  it('không hiện số khi không có gì chưa đọc và báo hộp thư trống', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser()) })
    renderApp('/')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Mở hộp thông báo' }))

    expect(screen.queryByTestId('unread-count')).not.toBeInTheDocument()
    expect(screen.getByText('Chưa có thông báo nào.')).toBeInTheDocument()
  })

  it('thông báo quan trọng chưa đọc hiện ngay trên trang tổng quan khi đăng nhập', async () => {
    mockApi({
      'GET /api/v1/auth/me': meOk(tenantUser()),
      'GET /api/v1/notifications': list([notice(), notice({ id: 'n2', level: 'info', title: 'Chỉ là thông tin', body: null })]),
    })
    renderApp('/')

    const banner = await screen.findByText('Có thông báo quan trọng chưa đọc')
    const alert = banner.closest('[role="alert"]') as HTMLElement

    expect(within(alert).getByText('Sao lưu toàn hệ thống THẤT BẠI')).toBeInTheDocument()
    expect(within(alert).queryByText('Chỉ là thông tin')).not.toBeInTheDocument()
  })

  it('trang Thông báo xem được toàn bộ và tải thêm', async () => {
    mockApi({
      'GET /api/v1/auth/me': meOk(platformAdmin()),
      'GET /api/v1/notifications?per_page=20&page=1': {
        json: { data: [notice({ id: 'a', title: 'Trang một' })], unread_count: 1, meta: { current_page: 1, last_page: 2, total: 2 } },
      },
      'GET /api/v1/notifications?per_page=20&page=2': {
        json: { data: [notice({ id: 'b', title: 'Trang hai', read_at: new Date().toISOString() })], unread_count: 1, meta: { current_page: 2, last_page: 2, total: 2 } },
      },
      'GET /api/v1/platform/backups/status': { json: { data: { state: 'ok', enabled: true, issues: [] } } },
    })
    renderApp('/notifications')
    const user = userEvent.setup()

    expect(await screen.findByText('Trang một')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Xem thêm' }))

    expect(await screen.findByText('Trang hai')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Xem thêm' })).not.toBeInTheDocument()
  })
})

describe('banner gói dịch vụ và phiên hỗ trợ', () => {
  const t = (key: string, options?: Record<string, unknown>) => `${key}${options ? JSON.stringify(options) : ''}`
  const base = { is_trial: false, period_end: '2026-11-10T00:00:00Z', blocked: false, reopened_until: null }

  it('chọn đúng nội dung và mức độ theo trạng thái', () => {
    expect(subscriptionMessage({ ...base, state: 'trial', is_trial: true, days_left: 5 }, t)).toEqual({ tone: 'info', text: 'banner.trial{"days":5}' })
    expect(subscriptionMessage({ ...base, state: 'trial', is_trial: true, days_left: 2 }, t)?.tone).toBe('warning')
    expect(subscriptionMessage({ ...base, state: 'trial', is_trial: true, days_left: 0 }, t)).toEqual({ tone: 'danger', text: 'banner.trialLastDay' })
    expect(subscriptionMessage({ ...base, state: 'active', days_left: 10 }, t)?.tone).toBe('warning')
    expect(subscriptionMessage({ ...base, state: 'active', days_left: 3 }, t)?.tone).toBe('danger')
    expect(subscriptionMessage({ ...base, state: 'active', days_left: 0 }, t)?.text).toBe('banner.expiringToday')
    expect(subscriptionMessage({ ...base, state: 'grace', days_left: -1 }, t)?.text).toBe('banner.grace')
    expect(subscriptionMessage({ ...base, state: 'expired', days_left: -5, blocked: true }, t)?.text).toBe('banner.expired')
  })

  it('hiện banner nhắc gia hạn trong ứng dụng', async () => {
    mockApi({
      'GET /api/v1/auth/me': meOk(tenantUser({ subscription_notice: { ...base, state: 'active', days_left: 6 } })),
    })
    renderApp('/')

    expect(await screen.findByText('Gói dịch vụ còn 6 ngày. Vui lòng liên hệ AgriHub để gia hạn.')).toBeInTheDocument()
  })

  it('không hiện banner khi gói còn dài hạn', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser({ subscription_notice: null })) })
    renderApp('/')

    await screen.findByText('Xin chào, Nguyễn Văn A')
    expect(screen.queryByText(/Vui lòng liên hệ AgriHub để gia hạn/)).not.toBeInTheDocument()
  })

  it('Super Admin đang trong phiên hỗ trợ thấy rõ và kết thúc được', async () => {
    const session = {
      id: 's1',
      tenant: { id: 't1', name: 'Cửa hàng Nông Phát' },
      reason: 'Khách báo lỗi tồn kho',
      started_at: '2026-10-30T01:00:00Z',
      expires_at: '2026-10-30T02:00:00Z',
      read_only: true,
    }
    const server = mockApi({
      'GET /api/v1/auth/me': meOk(platformAdmin({ support_session: session })),
      'GET /api/v1/platform/backups/status': { json: { data: { state: 'ok', enabled: true, issues: [] } } },
      'DELETE /api/v1/platform/support-session': { status: 204 },
    })
    renderApp('/')
    const user = userEvent.setup()

    expect(await screen.findByText(/Cửa hàng Nông Phát" ở chế độ chỉ đọc/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Kết thúc phiên hỗ trợ' }))

    await waitFor(() => expect(server.to('DELETE', '/api/v1/platform/support-session')).toHaveLength(1))
  })
})
