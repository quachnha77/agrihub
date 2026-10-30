import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { mockApi, notSignedIn, problem, renderApp } from '../../test/utils.tsx'

const SENT = 'Nếu thông tin khớp với một tài khoản, chúng tôi đã gửi hướng dẫn đặt lại mật khẩu tới email của tài khoản đó.'

describe('từ trang đăng nhập', () => {
  it('có lối vào quên mật khẩu và quên tài khoản', async () => {
    mockApi({ 'GET /api/v1/auth/me': notSignedIn() })
    const { router } = renderApp('/login')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('link', { name: 'Quên mật khẩu?' }))
    expect(await screen.findByRole('heading', { name: 'Quên mật khẩu' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/forgot-password')

    await user.click(screen.getByRole('link', { name: 'Quên tài khoản?' }))
    expect(await screen.findByRole('heading', { name: 'Quên tài khoản' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/forgot-account')

    await user.click(screen.getByRole('link', { name: 'Quay lại đăng nhập' }))
    expect(await screen.findByRole('heading', { name: 'Đăng nhập' })).toBeInTheDocument()
  })
})

describe('quên mật khẩu', () => {
  it('báo lỗi tiếng Việt khi để trống hoặc nhập sai và không gọi API', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': notSignedIn() })
    renderApp('/forgot-password')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Gửi hướng dẫn' }))
    expect(await screen.findByText('Vui lòng nhập email hoặc số điện thoại.')).toBeInTheDocument()

    await user.type(screen.getByLabelText('Email hoặc số điện thoại'), '0912')
    await user.click(screen.getByRole('button', { name: 'Gửi hướng dẫn' }))
    expect(await screen.findByText('Email hoặc số điện thoại không hợp lệ.')).toBeInTheDocument()

    expect(server.to('POST', '/api/v1/auth/forgot-password')).toHaveLength(0)
  })

  it.each(['chu@nongphat.vn', '0912 345 678'])('gửi yêu cầu bằng "%s" và hiện đúng lời máy chủ trả lời', async (typed) => {
    const server = mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/forgot-password': { status: 202, json: { message: SENT } },
    })
    renderApp('/forgot-password')
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Email hoặc số điện thoại'), typed)
    await user.click(screen.getByRole('button', { name: 'Gửi hướng dẫn' }))

    expect(await screen.findByText(SENT)).toBeInTheDocument()
    expect(screen.getByText(/nhờ quản trị viên của doanh nghiệp cấp mật khẩu tạm/)).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/auth/forgot-password')[0]?.body).toEqual({ login: typed })
    expect(screen.queryByRole('button', { name: 'Gửi hướng dẫn' })).not.toBeInTheDocument()
  })

  it('nói rõ khi bị giới hạn số lần thử', async () => {
    mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/forgot-password': problem(429, 'too_many_attempts', 'x', { retry_after: 60 }),
    })
    renderApp('/forgot-password')
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Email hoặc số điện thoại'), 'chu@nongphat.vn')
    await user.click(screen.getByRole('button', { name: 'Gửi hướng dẫn' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Bạn đã thử quá nhiều lần. Vui lòng đợi 1 phút rồi thử lại.')
  })

  it('hiện tên doanh nghiệp khi mở từ địa chỉ riêng của doanh nghiệp', async () => {
    mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'GET /api/v1/tenant-context': { json: { data: { kind: 'tenant', tenant: { name: 'Cửa hàng Nông Phát', slug: 'nong-phat' } } } },
    })
    renderApp('/forgot-password')

    expect(await screen.findByText('Doanh nghiệp: Cửa hàng Nông Phát')).toBeInTheDocument()
  })
})

describe('quên tài khoản', () => {
  it('gọi đúng API và hiện lời máy chủ trả lời', async () => {
    const message = 'Nếu thông tin khớp với một tài khoản, chúng tôi đã gửi thông tin tài khoản tới email của tài khoản đó.'
    const server = mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/forgot-account': { status: 202, json: { message } },
    })
    renderApp('/forgot-account')
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Email hoặc số điện thoại'), 'chu@nongphat.vn')
    await user.click(screen.getByRole('button', { name: 'Gửi hướng dẫn' }))

    expect(await screen.findByText(message)).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/auth/forgot-account')[0]?.body).toEqual({ login: 'chu@nongphat.vn' })
  })

  it('chuyển qua lại với quên mật khẩu', async () => {
    mockApi({ 'GET /api/v1/auth/me': notSignedIn() })
    renderApp('/forgot-account')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('link', { name: 'Quên mật khẩu?' }))

    expect(await screen.findByRole('heading', { name: 'Quên mật khẩu' })).toBeInTheDocument()
  })
})

describe('đặt lại mật khẩu từ liên kết trong email', () => {
  const link = '/reset-password?token=ma-bi-mat-dai&email=chu%40nongphat.vn'

  async function fill(password: string, confirmation = password) {
    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Mật khẩu mới'), password)
    await user.type(screen.getByLabelText('Nhập lại mật khẩu mới'), confirmation)
    await user.click(screen.getByRole('button', { name: 'Đặt mật khẩu' }))
  }

  it('cho biết đang đặt cho tài khoản nào và xóa mã khỏi thanh địa chỉ ngay', async () => {
    mockApi({ 'GET /api/v1/auth/me': notSignedIn() })
    const { router } = renderApp(link)

    expect(await screen.findByText('Tài khoản: chu@nongphat.vn')).toBeInTheDocument()
    await waitFor(() => expect(router.state.location.search).toBe(''))
  })

  it('kiểm tra mật khẩu mới ngay trên trình duyệt', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': notSignedIn() })
    renderApp(link)

    await fill('ngan', 'khac')

    expect(await screen.findByText('Phải có ít nhất 12 ký tự.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/auth/reset-password')).toHaveLength(0)
  })

  it('đặt thành công thì báo xong và đưa về trang đăng nhập, gửi đủ mã + email + mật khẩu', async () => {
    const server = mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/reset-password': { json: { message: 'Đã đặt lại mật khẩu.' } },
    })
    const { router } = renderApp(link)
    const user = userEvent.setup()

    await fill('Mat-khau-moi-2027-x')

    expect(await screen.findByRole('heading', { name: 'Đã đặt lại mật khẩu' })).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/auth/reset-password')[0]?.body).toEqual({
      password: 'Mat-khau-moi-2027-x',
      password_confirmation: 'Mat-khau-moi-2027-x',
      token: 'ma-bi-mat-dai',
      email: 'chu@nongphat.vn',
    })

    await user.click(screen.getByRole('button', { name: 'Đăng nhập' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
  })

  it('hiện lỗi mật khẩu của máy chủ ngay tại ô và vẫn giữ liên kết để thử lại', async () => {
    mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/reset-password': problem(422, 'validation_failed', 'x', { errors: { password: ['Mật khẩu không được chứa tên đăng nhập (email).'] } }),
    })
    renderApp(link)

    await fill('chu-nongphat-2027')

    expect(await screen.findByText('Mật khẩu không được chứa tên đăng nhập (email).')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Đặt mật khẩu' })).toBeInTheDocument()
  })

  it('liên kết hết hạn hoặc đã dùng thì hướng người dùng xin liên kết mới', async () => {
    mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/reset-password': problem(422, 'invalid_reset_token', 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.'),
    })
    const { router } = renderApp(link)
    const user = userEvent.setup()

    await fill('Mat-khau-moi-2027-x')

    expect(await screen.findByRole('heading', { name: 'Liên kết không dùng được' })).toBeInTheDocument()
    await user.click(screen.getByRole('link', { name: 'Yêu cầu liên kết mới' }))
    expect(router.state.location.pathname).toBe('/forgot-password')
  })

  it('mở trang mà không có mã thì báo liên kết không dùng được, không gọi API', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': notSignedIn() })
    renderApp('/reset-password')

    expect(await screen.findByRole('heading', { name: 'Liên kết không dùng được' })).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/auth/reset-password')).toHaveLength(0)
  })
})
