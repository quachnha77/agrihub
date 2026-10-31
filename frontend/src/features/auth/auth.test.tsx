import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, notSignedIn, platformAdmin, problem, renderApp, tenantUser } from '../../test/utils.tsx'

async function fillLogin(login: string, password: string) {
  const user = userEvent.setup()
  if (login) await user.type(await screen.findByLabelText('Email hoặc số điện thoại'), login)
  if (password) await user.type(screen.getByLabelText('Mật khẩu'), password)
  await user.click(screen.getByRole('button', { name: 'Đăng nhập' }))
}

describe('đăng nhập', () => {
  it('chuyển người chưa đăng nhập về trang đăng nhập', async () => {
    mockApi({ 'GET /api/v1/auth/me': notSignedIn() })
    const { router } = renderApp('/branches')

    expect(await screen.findByRole('heading', { name: 'Đăng nhập' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/login')
  })

  it('báo lỗi bằng tiếng Việt khi để trống hoặc nhập email/số điện thoại sai, và không gọi API', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': notSignedIn() })
    renderApp('/login')

    await fillLogin('', '')
    expect(await screen.findByText('Vui lòng nhập email hoặc số điện thoại.')).toBeInTheDocument()
    expect(screen.getByText('Vui lòng nhập mật khẩu.')).toBeInTheDocument()

    await fillLogin('khong-phai-email', 'x')
    expect(await screen.findByText('Email hoặc số điện thoại không hợp lệ.')).toBeInTheDocument()

    for (const bad of ['0912', '12345678901234', '0112345678']) {
      await userEvent.clear(screen.getByLabelText('Email hoặc số điện thoại'))
      await fillLogin(bad, 'x')
      expect(await screen.findByText('Email hoặc số điện thoại không hợp lệ.')).toBeInTheDocument()
    }

    expect(server.to('POST', '/api/v1/auth/login')).toHaveLength(0)
  })

  it('đăng nhập thành công thì vào trang tổng quan và lời chào đúng tên', async () => {
    const server = mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/login': meOk(tenantUser()),
    })
    const { router } = renderApp('/login')

    await fillLogin('a@nongphat.vn', 'Mat-khau-2026')

    expect(await screen.findByText('Xin chào, Nguyễn Văn A')).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/')
    expect(server.to('POST', '/api/v1/auth/login')[0]?.body).toEqual({ login: 'a@nongphat.vn', password: 'Mat-khau-2026' })
  })

  it.each(['0912345678', '0912 345 678', '+84 912 345 678', '091-234-5678'])('đăng nhập được bằng số điện thoại viết kiểu "%s"', async (typed) => {
    const server = mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/login': meOk(tenantUser()),
    })
    renderApp('/login')

    await fillLogin(typed, 'Mat-khau-2026')

    expect(await screen.findByText('Xin chào, Nguyễn Văn A')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/auth/login')[0]?.body).toEqual({ login: typed, password: 'Mat-khau-2026' })
  })

  it('quay lại đúng trang đang định vào sau khi đăng nhập', async () => {
    mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/login': meOk(tenantUser()),
      'GET /api/v1/branches': { json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } },
    })
    const { router } = renderApp('/branches')

    await fillLogin('a@nongphat.vn', 'Mat-khau-2026')

    expect(await screen.findByRole('heading', { name: 'Chi nhánh' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/branches')
  })

  it.each([
    ['sai mật khẩu', problem(401, 'invalid_credentials', 'x'), 'Tài khoản hoặc mật khẩu không đúng.'],
    ['gói hết hạn', problem(402, 'subscription_expired', 'x'), 'Gói dịch vụ đã hết hạn nên chưa thể sử dụng. Vui lòng liên hệ AgriHub để gia hạn.'],
    ['doanh nghiệp bị khóa', problem(403, 'tenant_suspended', 'x'), 'Doanh nghiệp đang bị tạm khóa. Vui lòng liên hệ AgriHub.'],
    ['tài khoản bị vô hiệu', problem(403, 'account_disabled', 'x'), 'Tài khoản đã bị vô hiệu hóa. Vui lòng liên hệ quản trị viên của doanh nghiệp.'],
    ['thử quá nhiều lần', problem(429, 'too_many_attempts', 'x', { retry_after: 900 }), 'Bạn đã thử quá nhiều lần. Vui lòng đợi 15 phút rồi thử lại.'],
  ])('hiện thông báo riêng khi %s', async (_name, response, message) => {
    mockApi({ 'GET /api/v1/auth/me': notSignedIn(), 'POST /api/v1/auth/login': response })
    renderApp('/login')

    await fillLogin('a@nongphat.vn', 'Mat-khau-2026')

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
  })

  it('nói rõ lý do khi phiên đã kết thúc vì gói hết hạn', async () => {
    mockApi({ 'GET /api/v1/auth/me': problem(402, 'subscription_expired', 'x') })
    renderApp('/')

    expect(await screen.findByRole('alert')).toHaveTextContent('Gói dịch vụ đã hết hạn nên chưa thể sử dụng')
  })

  it('đưa người đang dùng về trang đăng nhập kèm lý do khi API báo hết phiên giữa chừng', async () => {
    mockApi({
      'GET /api/v1/auth/me': meOk(tenantUser()),
      'GET /api/v1/branches': problem(401, 'unauthenticated', 'Chưa đăng nhập hoặc phiên đã hết hạn.'),
    })
    const { router } = renderApp('/branches')

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(await screen.findByRole('alert')).toHaveTextContent('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.')
  })

  it('đăng xuất rồi quay về trang đăng nhập', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser()), 'POST /api/v1/auth/logout': { status: 204 } })
    const { router } = renderApp('/')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /Đăng xuất/ }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(server.to('POST', '/api/v1/auth/logout')).toHaveLength(1)
  })
})

describe('đổi mật khẩu bắt buộc', () => {
  it('tài khoản dùng mật khẩu tạm bị đưa tới trang đổi mật khẩu từ bất kỳ trang nào', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser({ must_change_password: true })) })
    const { router } = renderApp('/branches')

    expect(await screen.findByText('Bạn đang dùng mật khẩu tạm. Hãy đặt mật khẩu mới để tiếp tục.')).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/change-password')
  })

  it('kiểm tra mật khẩu mới ngay trên trình duyệt bằng tiếng Việt', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser({ must_change_password: true })) })
    renderApp('/change-password')
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Mật khẩu hiện tại'), 'Mat-khau-tam')
    await user.type(screen.getByLabelText('Mật khẩu mới'), 'ngan')
    await user.type(screen.getByLabelText('Nhập lại mật khẩu mới'), 'khac')
    await user.click(screen.getByRole('button', { name: 'Đổi mật khẩu' }))

    expect(await screen.findByText('Phải có ít nhất 12 ký tự.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/auth/password')).toHaveLength(0)
  })

  it('hiện lỗi máy chủ đúng ô và vào ứng dụng khi đổi thành công', async () => {
    let attempt = 0
    const server = mockApi({
      'GET /api/v1/auth/me': meOk(tenantUser({ must_change_password: true })),
      'POST /api/v1/auth/password': () =>
        ++attempt === 1
          ? problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', { errors: { current_password: ['Mật khẩu hiện tại không đúng.'] } })
          : meOk(tenantUser()),
    })
    const { router } = renderApp('/change-password')
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Mật khẩu hiện tại'), 'sai-mat-khau')
    await user.type(screen.getByLabelText('Mật khẩu mới'), 'Mat-khau-moi-2026')
    await user.type(screen.getByLabelText('Nhập lại mật khẩu mới'), 'Mat-khau-moi-2026')
    await user.click(screen.getByRole('button', { name: 'Đổi mật khẩu' }))

    expect(await screen.findByText('Mật khẩu hiện tại không đúng.')).toBeInTheDocument()

    await user.clear(screen.getByLabelText('Mật khẩu hiện tại'))
    await user.type(screen.getByLabelText('Mật khẩu hiện tại'), 'Mat-khau-tam')
    await user.click(screen.getByRole('button', { name: 'Đổi mật khẩu' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(server.to('POST', '/api/v1/auth/password')[1]?.body).toEqual({
      current_password: 'Mat-khau-tam',
      password: 'Mat-khau-moi-2026',
      password_confirmation: 'Mat-khau-moi-2026',
    })
  })
})

describe('phân quyền trên giao diện', () => {
  it('chỉ hiện menu mà người dùng có quyền', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser({ permissions: {} })) })
    renderApp('/')

    await screen.findByText('Xin chào, Nguyễn Văn A')
    const nav = screen.getByRole('navigation', { name: 'Menu chính' })

    expect(nav).toHaveTextContent('Tổng quan')
    expect(nav).not.toHaveTextContent('Sao lưu dữ liệu')
    expect(nav).not.toHaveTextContent('Sao lưu hệ thống')
  })

  it('chặn trang khi không có quyền, kể cả gõ thẳng đường dẫn', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser({ permissions: {} })) })
    renderApp('/branches')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
    expect(server.to('GET', '/api/v1/branches')).toHaveLength(0)
  })

  it('người dùng Tenant không vào được trang quản trị nền tảng', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser()) })
    renderApp('/platform/backups')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
  })

  it('Super Admin thấy menu sao lưu hệ thống và không thấy menu của Tenant', async () => {
    mockApi({
      'GET /api/v1/auth/me': meOk(platformAdmin()),
      'GET /api/v1/platform/backups/status': { json: { data: { state: 'ok', enabled: true, issues: [] } } },
      'GET /api/v1/platform/health': { json: { data: { status: 'ok', checked_at: '2026-10-30T10:00:00Z', components: [] } } },
    })
    renderApp('/')

    await screen.findByText('Bạn đang đăng nhập với tư cách quản trị nền tảng AgriHub.')
    const nav = screen.getByRole('navigation', { name: 'Menu chính' })

    expect(nav).toHaveTextContent('Sao lưu hệ thống')
    expect(nav).not.toHaveTextContent('Sao lưu dữ liệu')
  })

  it('đường dẫn không tồn tại hiện trang 404', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser()) })
    renderApp('/khong-co-trang-nay')

    expect(await screen.findByText('Không tìm thấy trang')).toBeInTheDocument()
  })
})
