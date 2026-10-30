import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, notSignedIn, problem, renderApp, tenantUser } from '../../test/utils.tsx'

async function submit(login: string, password = 'Mat-khau-2026') {
  const user = userEvent.setup()
  await user.type(await screen.findByLabelText('Email hoặc số điện thoại'), login)
  await user.type(screen.getByLabelText('Mật khẩu'), password)
  await user.click(screen.getByRole('button', { name: 'Đăng nhập' }))
}

describe('mỗi doanh nghiệp một subdomain', () => {
  it('trang đăng nhập của một doanh nghiệp ghi đúng tên doanh nghiệp', async () => {
    mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'GET /api/v1/tenant-context': { json: { data: { kind: 'tenant', tenant: { name: 'Cửa hàng Nông Phát', slug: 'nong-phat' } } } },
    })
    renderApp('/login')

    expect(await screen.findByText('Đăng nhập vào Cửa hàng Nông Phát')).toBeInTheDocument()
  })

  it('trang đăng nhập của quản trị nền tảng', async () => {
    mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'GET /api/v1/tenant-context': { json: { data: { kind: 'platform', tenant: null } } },
    })
    renderApp('/login')

    expect(await screen.findByText('Cổng quản trị nền tảng AgriHub')).toBeInTheDocument()
  })

  it('subdomain không thuộc doanh nghiệp nào thì không cho đăng nhập', async () => {
    mockApi({
      'GET /api/v1/auth/me': problem(404, 'tenant_not_found', 'Không tìm thấy doanh nghiệp tại địa chỉ này.'),
      'GET /api/v1/tenant-context': problem(404, 'tenant_not_found', 'Không tìm thấy doanh nghiệp tại địa chỉ này.'),
    })
    renderApp('/login')

    expect(await screen.findByText('Không tìm thấy doanh nghiệp')).toBeInTheDocument()
    expect(screen.queryByLabelText('Mật khẩu')).not.toBeInTheDocument()
  })

  it('đăng nhập nhầm địa chỉ thì chỉ đúng địa chỉ của chính họ', async () => {
    mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/login': problem(403, 'wrong_host', 'x', { login_url: 'https://nong-phat.agrihub.vn' }),
    })
    renderApp('/login')

    await submit('chu@nongphat.vn')

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Tài khoản này không đăng nhập tại địa chỉ này.')
    expect(screen.getByRole('link', { name: 'Mở địa chỉ đăng nhập của bạn' })).toHaveAttribute('href', 'https://nong-phat.agrihub.vn')
  })

  it('không bao giờ đưa liên kết lạ (không phải http/https) lên màn hình', async () => {
    mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'POST /api/v1/auth/login': problem(403, 'wrong_host', 'x', { login_url: 'javascript:alert(1)' }),
    })
    renderApp('/login')

    await submit('chu@nongphat.vn')

    expect(await screen.findByRole('alert')).toHaveTextContent('Tài khoản này không đăng nhập tại địa chỉ này.')
    expect(screen.queryByRole('link', { name: 'Mở địa chỉ đăng nhập của bạn' })).not.toBeInTheDocument()
  })

  it('đăng nhập thành công ở đúng địa chỉ vẫn vào ứng dụng bình thường', async () => {
    mockApi({
      'GET /api/v1/auth/me': notSignedIn(),
      'GET /api/v1/tenant-context': { json: { data: { kind: 'tenant', tenant: { name: 'Cửa hàng Nông Phát', slug: 'nong-phat' } } } },
      'POST /api/v1/auth/login': meOk(tenantUser()),
    })
    renderApp('/login')

    await submit('chu@nongphat.vn')

    expect(await screen.findByText('Xin chào, Nguyễn Văn A')).toBeInTheDocument()
  })
})
