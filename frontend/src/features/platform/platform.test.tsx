import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, platformAdmin, problem, renderApp, tenantUser } from '../../test/utils.tsx'

const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 } })

const summary = (overrides = {}) => ({
  state: 'trial',
  blocked: false,
  is_trial: true,
  plan: 'Tiêu chuẩn',
  seats: 5,
  period_end: '2026-11-07T00:00:00Z',
  days_left: 7,
  last_contacted_at: null,
  ...overrides,
})

const tenant = (overrides = {}) => ({
  id: 't-1',
  name: 'Cửa hàng Nông Phát',
  slug: 'nong-phat',
  url: 'http://nong-phat.agrihub.test',
  status: 'active',
  phone: '0900111222',
  address: 'Hà Nội',
  tax_code: '0100000001',
  suspended_at: null,
  suspension_reason: null,
  created_at: '2026-10-01T00:00:00Z',
  users_count: 3,
  branches_count: 1,
  owners: [{ id: 'u-1', name: 'Nguyễn Chủ', email: 'chu@nongphat.vn', status: 'active', must_change_password: false, last_login_at: null }],
  branches: [{ id: 'b-1', code: 'CN-01', name: 'Chi nhánh chính' }],
  subscription: summary(),
  ...overrides,
})

const detail = (overrides = {}) => ({
  state: 'active',
  blocked: false,
  reopened_until: null,
  is_trial: false,
  plan: { key: 'standard', name: 'Tiêu chuẩn' },
  billing_cycle: 'monthly',
  seats: 5,
  unit_price: 150000,
  max_branches: null,
  storage_mb: null,
  period_start: '2026-10-01T00:00:00Z',
  period_end: '2026-11-01T00:00:00Z',
  days_left: 10,
  usage: { users: 3, branches: 1 },
  periods: [
    { id: 'p-1', kind: 'renewal', plan: 'Tiêu chuẩn', billing_cycle: 'monthly', seats: 5, unit_price: 150000, amount: 750000, period_start: '2026-10-01T00:00:00Z', period_end: '2026-11-01T00:00:00Z', paid_at: null, payment_method: 'bank_transfer', payment_ref: 'FT123', note: null },
  ],
  last_contacted_at: null,
  contact_note: null,
  reopen_reason: null,
  ...overrides,
})

const staff = {
  id: 'u-2',
  name: 'Trần Nhân Viên',
  email: 'nv@nongphat.vn',
  phone: '0911222333',
  status: 'active',
  is_platform_admin: false,
  roles: [{ id: 'r-1', key: 'sales', name: 'Nhân viên bán hàng' }],
  branches: [],
  last_login_at: null,
}

const plan = (overrides = {}) => ({
  id: 'plan-1',
  key: 'standard',
  name: 'Tiêu chuẩn',
  description: null,
  price_monthly: 150000,
  price_yearly: 1500000,
  max_branches: null,
  storage_mb: null,
  is_active: true,
  sort_order: 1,
  ...overrides,
})

const admin = { 'GET /api/v1/auth/me': meOk(platformAdmin()) }

describe('menu và quyền', () => {
  it('Super Admin thấy menu quản lý doanh nghiệp, người dùng doanh nghiệp thì không', async () => {
    mockApi({ ...admin, 'GET /api/v1/platform/health': { json: { data: { status: 'ok', checked_at: '2026-10-30T10:00:00Z', components: [] } } }, 'GET /api/v1/platform/backups/status': { json: { data: { state: 'ok', enabled: true, issues: [] } } } })
    renderApp('/')

    const nav = await screen.findByRole('navigation', { name: 'Menu chính' })
    expect(within(nav).getByRole('link', { name: 'Doanh nghiệp' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Việc cần liên hệ' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Gói & giá' })).toBeInTheDocument()
  })

  it('người dùng của doanh nghiệp không vào được trang quản lý doanh nghiệp', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser()) })
    renderApp('/platform/tenants')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
    expect(server.to('GET', '/api/v1/platform/tenants')).toHaveLength(0)
  })
})

describe('danh sách doanh nghiệp', () => {
  it('hiện địa chỉ đăng nhập, chủ doanh nghiệp, thuê bao và trạng thái', async () => {
    mockApi({
      ...admin,
      'GET /api/v1/platform/tenants': {
        json: page([
          tenant(),
          tenant({ id: 't-2', name: 'Vật tư Xanh', slug: 'vat-tu-xanh', url: null, status: 'suspended', owners: [], subscription: summary({ state: 'expired', blocked: true, days_left: -3, is_trial: false }) }),
        ]),
      },
    })
    renderApp('/platform/tenants')

    const rows = await screen.findAllByRole('row')
    expect(within(rows[1]).getByRole('link', { name: 'Cửa hàng Nông Phát' })).toHaveAttribute('href', '/platform/tenants/t-1')
    expect(rows[1]).toHaveTextContent('http://nong-phat.agrihub.test')
    expect(rows[1]).toHaveTextContent('chu@nongphat.vn')
    expect(rows[1]).toHaveTextContent('Dùng thử')
    expect(rows[1]).toHaveTextContent('còn 7 ngày')
    expect(rows[1]).toHaveTextContent('3 người dùng · 1 chi nhánh')
    expect(rows[2]).toHaveTextContent('vat-tu-xanh') // chưa bật subdomain: hiện định danh
    expect(rows[2]).toHaveTextContent('Hết hạn')
    expect(rows[2]).toHaveTextContent('quá hạn 3 ngày')
    expect(rows[2]).toHaveTextContent('Đã khóa')
  })

  it('tìm kiếm và lọc trạng thái được gửi lên máy chủ', async () => {
    const server = mockApi({ ...admin, 'GET /api/v1/platform/tenants': { json: page([tenant()]) } })
    renderApp('/platform/tenants')
    const user = userEvent.setup()

    await user.type(await screen.findByRole('searchbox', { name: 'Tìm kiếm' }), 'nong')
    await user.selectOptions(screen.getByRole('combobox', { name: 'Lọc theo trạng thái' }), 'suspended')

    await waitFor(() => {
      const last = server.calls.filter((c) => c.path.startsWith('/api/v1/platform/tenants')).at(-1)
      expect(last?.path).toContain('q=nong')
      expect(last?.path).toContain('status=suspended')
    })
  })

  it('báo khi chưa có doanh nghiệp nào', async () => {
    mockApi({ ...admin, 'GET /api/v1/platform/tenants': { json: page([]) } })
    renderApp('/platform/tenants')

    expect(await screen.findByText('Chưa có doanh nghiệp nào.')).toBeInTheDocument()
  })
})

describe('thêm doanh nghiệp (onboarding)', () => {
  async function openDialog() {
    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Thêm doanh nghiệp' }))

    return { user, dialog: await screen.findByRole('dialog') }
  }

  it('kiểm tra dữ liệu ngay trên trình duyệt và không gọi API khi còn lỗi', async () => {
    const server = mockApi({ ...admin, 'GET /api/v1/platform/tenants': { json: page([]) } })
    renderApp('/platform/tenants')
    const { user, dialog } = await openDialog()

    await user.type(within(dialog).getByLabelText('Định danh (địa chỉ đăng nhập)'), 'Nông Phát!')
    await user.type(within(dialog).getByLabelText('Mã số thuế'), '123')
    await user.click(within(dialog).getByRole('button', { name: 'Tạo doanh nghiệp' }))

    expect(await within(dialog).findByText('Vui lòng nhập tên doanh nghiệp.')).toBeInTheDocument()
    expect(within(dialog).getByText(/Chỉ dùng chữ thường không dấu/)).toBeInTheDocument()
    expect(within(dialog).getByText(/Mã số thuế gồm 10 số/)).toBeInTheDocument()
    expect(within(dialog).getByText('Vui lòng nhập họ tên.')).toBeInTheDocument()
    expect(within(dialog).getByText('Vui lòng nhập email.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/platform/tenants')).toHaveLength(0)
  })

  it('tạo thành công thì hiện địa chỉ đăng nhập và mật khẩu tạm MỘT lần cho Chủ doanh nghiệp', async () => {
    const created = tenant({ id: 't-9', name: 'Cửa hàng Mới', url: 'http://cua-hang-moi.agrihub.test', owners: [{ id: 'u-9', name: 'Chủ Mới', email: 'chu@moi.vn', status: 'active', must_change_password: true, last_login_at: null }] })
    const server = mockApi({
      ...admin,
      'GET /api/v1/platform/tenants': { json: page([]) },
      'POST /api/v1/platform/tenants': { status: 201, json: { data: created, temporary_password: 'Tam-Mat-Khau-77' } },
    })
    renderApp('/platform/tenants')
    const { user, dialog } = await openDialog()

    await user.type(within(dialog).getByLabelText('Tên doanh nghiệp'), 'Cửa hàng Mới')
    await user.type(within(dialog).getByLabelText('Họ tên'), 'Chủ Mới')
    await user.type(within(dialog).getByLabelText('Email'), 'chu@moi.vn')
    await user.type(within(dialog).getByLabelText('Số điện thoại (tùy chọn)'), '0977 123 456')
    await user.click(within(dialog).getByRole('button', { name: 'Tạo doanh nghiệp' }))

    expect(await screen.findByTestId('temporary-password')).toHaveTextContent('Tam-Mat-Khau-77')
    expect(screen.getByText('Địa chỉ đăng nhập: http://cua-hang-moi.agrihub.test')).toBeInTheDocument()
    expect(screen.getByText('Tài khoản đăng nhập: chu@moi.vn')).toBeInTheDocument()
    expect(screen.getByText('Đã tạo doanh nghiệp "Cửa hàng Mới".')).toBeInTheDocument()
    // Ô bỏ trống không được gửi lên (slug tự đặt, mật khẩu tự sinh).
    expect(server.to('POST', '/api/v1/platform/tenants')[0]?.body).toEqual({
      tenant: { name: 'Cửa hàng Mới' },
      owner: { name: 'Chủ Mới', email: 'chu@moi.vn', phone: '0977 123 456' },
    })
  })

  it('hiện lỗi của máy chủ đúng ô (email đã dùng)', async () => {
    mockApi({
      ...admin,
      'GET /api/v1/platform/tenants': { json: page([]) },
      'POST /api/v1/platform/tenants': problem(422, 'validation_failed', 'x', { errors: { 'owner.email': ['Email này không khả dụng.'] } }),
    })
    renderApp('/platform/tenants')
    const { user, dialog } = await openDialog()

    await user.type(within(dialog).getByLabelText('Tên doanh nghiệp'), 'Cửa hàng Mới')
    await user.type(within(dialog).getByLabelText('Họ tên'), 'Chủ Mới')
    await user.type(within(dialog).getByLabelText('Email'), 'chu@moi.vn')
    await user.click(within(dialog).getByRole('button', { name: 'Tạo doanh nghiệp' }))

    expect(await within(dialog).findByText('Email này không khả dụng.')).toBeInTheDocument()
  })
})

describe('chi tiết doanh nghiệp', () => {
  const base = (extra: Record<string, unknown> = {}) => ({
    ...admin,
    'GET /api/v1/platform/tenants/t-1': { json: { data: tenant() } },
    'GET /api/v1/platform/tenants/t-1/subscription': { json: { data: detail() } },
    'GET /api/v1/platform/users': { json: page([staff]) },
    ...extra,
  })

  it('hiện thông tin, thuê bao với lịch sử và người dùng', async () => {
    const server = mockApi(base())
    renderApp('/platform/tenants/t-1')

    expect(await screen.findByRole('heading', { name: /Cửa hàng Nông Phát/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'http://nong-phat.agrihub.test' })).toHaveAttribute('href', 'http://nong-phat.agrihub.test')
    expect(screen.getByText('Chi nhánh: CN-01 — Chi nhánh chính')).toBeInTheDocument()
    expect(await screen.findByText('3/5 người dùng')).toBeInTheDocument()
    expect(screen.getByText('150.000 ₫ / người dùng / tháng')).toBeInTheDocument()
    expect(screen.getByText('FT123')).toBeInTheDocument()
    expect(await screen.findByText('Trần Nhân Viên')).toBeInTheDocument()
    expect(server.calls.some((c) => c.path.startsWith('/api/v1/platform/users?tenant=t-1'))).toBe(true)
  })

  it('doanh nghiệp không tồn tại thì báo rõ', async () => {
    mockApi({ ...admin, 'GET /api/v1/platform/tenants/t-404': problem(404, 'not_found', 'Không tìm thấy.') })
    renderApp('/platform/tenants/t-404')

    expect(await screen.findByText('Không tìm thấy doanh nghiệp này.')).toBeInTheDocument()
  })

  it('sửa thông tin: chỉ gửi các trường cho phép, ô bỏ trống thành null', async () => {
    const server = mockApi(base({ 'PATCH /api/v1/platform/tenants/t-1': { json: { data: tenant() } } }))
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Sửa' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/Định danh "nong-phat" là địa chỉ đăng nhập/)).toBeInTheDocument()
    await user.clear(within(dialog).getByLabelText('Địa chỉ'))
    await user.clear(within(dialog).getByLabelText('Tên doanh nghiệp'))
    await user.type(within(dialog).getByLabelText('Tên doanh nghiệp'), 'Nông Phát Group')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/platform/tenants/t-1')).toHaveLength(1))
    expect(server.to('PATCH', '/api/v1/platform/tenants/t-1')[0]?.body).toEqual({ name: 'Nông Phát Group', phone: '0900111222', address: null, tax_code: '0100000001' })
  })

  it('khóa doanh nghiệp bắt buộc có lý do', async () => {
    const server = mockApi(base({ 'POST /api/v1/platform/tenants/t-1/suspend': { json: { data: tenant({ status: 'suspended' }) } } }))
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Khóa doanh nghiệp' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Lý do khóa (ít nhất 5 ký tự)'), 'abc')
    await user.click(within(dialog).getByRole('button', { name: 'Khóa doanh nghiệp' }))

    expect(await within(dialog).findByText('Phải có ít nhất 5 ký tự.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/platform/tenants/t-1/suspend')).toHaveLength(0)

    await user.type(within(dialog).getByLabelText('Lý do khóa (ít nhất 5 ký tự)'), 'de')
    await user.click(within(dialog).getByRole('button', { name: 'Khóa doanh nghiệp' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/platform/tenants/t-1/suspend')[0]?.body).toEqual({ reason: 'abcde' }))
  })

  it('doanh nghiệp đang bị khóa hiện lý do và cho mở khóa', async () => {
    const server = mockApi(
      base({
        'GET /api/v1/platform/tenants/t-1': { json: { data: tenant({ status: 'suspended', suspended_at: '2026-10-20T03:00:00Z', suspension_reason: 'Nợ phí' }) } },
        'POST /api/v1/platform/tenants/t-1/activate': { json: { data: tenant() } },
      }),
    )
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    expect(await screen.findByText(/Lý do: Nợ phí/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Mở khóa' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Mở khóa' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/platform/tenants/t-1/activate')).toHaveLength(1))
  })

  it('cấp mật khẩu tạm cho người dùng và hiện kèm địa chỉ đăng nhập của doanh nghiệp (không phải địa chỉ của Super Admin)', async () => {
    const server = mockApi(base({ 'POST /api/v1/platform/users/u-2/reset-password': { json: { data: staff, temporary_password: 'Mat-Khau-Tam-55' } } }))
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Cấp mật khẩu tạm cho Trần Nhân Viên' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cấp mật khẩu tạm' }))

    expect(await screen.findByTestId('temporary-password')).toHaveTextContent('Mat-Khau-Tam-55')
    expect(screen.getByText('Địa chỉ đăng nhập: http://nong-phat.agrihub.test')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/platform/users/u-2/reset-password')).toHaveLength(1)
  })

  it('khóa người dùng của doanh nghiệp', async () => {
    const server = mockApi(base({ 'PATCH /api/v1/platform/users/u-2': { json: { data: { ...staff, status: 'disabled' } } } }))
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Khóa Trần Nhân Viên' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Khóa' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/platform/users/u-2')[0]?.body).toEqual({ status: 'disabled' }))
  })
})

describe('thuê bao', () => {
  const open = (subscription = detail(), extra: Record<string, unknown> = {}) =>
    mockApi({
      ...admin,
      'GET /api/v1/platform/tenants/t-1': { json: { data: tenant() } },
      'GET /api/v1/platform/tenants/t-1/subscription': { json: { data: subscription } },
      'GET /api/v1/platform/users': { json: page([]) },
      'GET /api/v1/platform/plans': { json: { data: [plan(), plan({ id: 'plan-2', key: 'pro', name: 'Nâng cao', price_monthly: 250000 }), plan({ id: 'plan-3', key: 'old', name: 'Gói cũ', is_active: false })] } },
      ...extra,
    })

  it('gia hạn: gợi ý số tiền mặc định và gửi đúng nội dung', async () => {
    const server = open(detail(), { 'POST /api/v1/platform/tenants/t-1/subscription/renew': { json: { data: detail() } } })
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Gia hạn' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/Bỏ trống để dùng số tiền mặc định 750\.000 ₫/)).toBeInTheDocument()

    await user.clear(within(dialog).getByLabelText('Số tháng'))
    await user.type(within(dialog).getByLabelText('Số tháng'), '2')
    expect(within(dialog).getByText(/mặc định 1\.500\.000 ₫/)).toBeInTheDocument()

    await user.selectOptions(within(dialog).getByLabelText('Hình thức thanh toán'), 'momo')
    await user.type(within(dialog).getByLabelText('Mã giao dịch / ghi chú thanh toán'), 'MM987')
    await user.click(within(dialog).getByRole('button', { name: 'Ghi nhận gia hạn' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/platform/tenants/t-1/subscription/renew')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/platform/tenants/t-1/subscription/renew')[0]?.body).toEqual({ periods: 2, payment_method: 'momo', payment_ref: 'MM987' })
  })

  it('số kỳ gia hạn không hợp lệ bị chặn trên trình duyệt', async () => {
    const server = open()
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Gia hạn' }))
    const dialog = await screen.findByRole('dialog')
    await user.clear(within(dialog).getByLabelText('Số tháng'))
    await user.type(within(dialog).getByLabelText('Số tháng'), '99')
    await user.click(within(dialog).getByRole('button', { name: 'Ghi nhận gia hạn' }))

    expect(await within(dialog).findByText('Nhập số từ 1 đến 24.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/platform/tenants/t-1/subscription/renew')).toHaveLength(0)
  })

  it('thuê bao đã hết hạn: báo bị chặn, cho mở lại tạm thời với lý do bắt buộc', async () => {
    const server = open(detail({ state: 'expired', blocked: true, days_left: -4 }), { 'POST /api/v1/platform/tenants/t-1/subscription/reopen': { json: { data: detail() } } })
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    expect(await screen.findByText(/Thuê bao đã hết hạn: người dùng của doanh nghiệp không đăng nhập/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Mở lại tạm thời' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Lý do (ít nhất 10 ký tự)'), 'ngắn')
    await user.click(within(dialog).getByRole('button', { name: 'Mở lại' }))

    expect(await within(dialog).findByText('Phải có ít nhất 10 ký tự.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/platform/tenants/t-1/subscription/reopen')).toHaveLength(0)

    await user.clear(within(dialog).getByLabelText('Lý do (ít nhất 10 ký tự)'))
    await user.type(within(dialog).getByLabelText('Lý do (ít nhất 10 ký tự)'), 'Khách hẹn chuyển khoản thứ Hai')
    await user.click(within(dialog).getByRole('button', { name: 'Mở lại' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/platform/tenants/t-1/subscription/reopen')[0]?.body).toEqual({ days: 7, reason: 'Khách hẹn chuyển khoản thứ Hai' }))
  })

  it('doanh nghiệp chưa có thuê bao thì giải thích thay vì báo lỗi tải', async () => {
    mockApi({
      ...admin,
      'GET /api/v1/platform/tenants/t-1': { json: { data: tenant({ subscription: null }) } },
      'GET /api/v1/platform/tenants/t-1/subscription': problem(404, 'not_found', 'Không tìm thấy.'),
      'GET /api/v1/platform/users': { json: page([]) },
    })
    renderApp('/platform/tenants/t-1')

    expect(await screen.findByText(/Doanh nghiệp này chưa có thuê bao nên chưa bị giới hạn/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Gia hạn' })).not.toBeInTheDocument()
  })

  it('thuê bao còn hạn không có nút mở lại tạm thời', async () => {
    open()
    renderApp('/platform/tenants/t-1')

    await screen.findByRole('button', { name: 'Gia hạn' })
    expect(screen.queryByRole('button', { name: 'Mở lại tạm thời' })).not.toBeInTheDocument()
  })

  it('đổi gói chỉ gửi phần thay đổi và không đề xuất gói đã ngừng bán', async () => {
    const server = open(detail(), { 'PATCH /api/v1/platform/tenants/t-1/subscription': { json: { data: detail() } } })
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Đổi gói / số người dùng' }))
    const dialog = await screen.findByRole('dialog')
    const planSelect = await within(dialog).findByLabelText('Gói')
    expect(within(planSelect).queryByRole('option', { name: 'Gói cũ' })).not.toBeInTheDocument()

    await user.selectOptions(planSelect, 'plan-2')
    await user.clear(within(dialog).getByLabelText('Số người dùng'))
    await user.type(within(dialog).getByLabelText('Số người dùng'), '8')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/platform/tenants/t-1/subscription')).toHaveLength(1))
    expect(server.to('PATCH', '/api/v1/platform/tenants/t-1/subscription')[0]?.body).toEqual({ plan_id: 'plan-2', seats: 8 })
  })

  it('hiện lỗi của máy chủ khi giảm số người dùng xuống dưới số đang dùng', async () => {
    open(detail(), {
      'PATCH /api/v1/platform/tenants/t-1/subscription': problem(422, 'validation_failed', 'x', { errors: { seats: ['Đang có 3 người dùng hoạt động, không thể giảm xuống 1.'] } }),
    })
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Đổi gói / số người dùng' }))
    const dialog = await screen.findByRole('dialog')
    await user.clear(await within(dialog).findByLabelText('Số người dùng'))
    await user.type(within(dialog).getByLabelText('Số người dùng'), '1')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    expect(await within(dialog).findByText('Đang có 3 người dùng hoạt động, không thể giảm xuống 1.')).toBeInTheDocument()
  })

  it('ghi nhận đã liên hệ khách', async () => {
    const server = open(detail(), { 'POST /api/v1/platform/tenants/t-1/subscription/contact': { json: { data: detail() } } })
    renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Ghi nhận liên hệ' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText(/Ghi chú \(ví dụ/), 'Đã gọi, hẹn thứ Sáu')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/platform/tenants/t-1/subscription/contact')[0]?.body).toEqual({ note: 'Đã gọi, hẹn thứ Sáu' }))
  })
})

describe('phiên hỗ trợ', () => {
  it('mở phiên với lý do bắt buộc rồi chuyển sang xem dữ liệu của doanh nghiệp', async () => {
    let meCalls = 0
    const session = { id: 's-1', tenant: { id: 't-1', name: 'Cửa hàng Nông Phát' }, reason: 'Khách báo lỗi chi nhánh', started_at: '2026-10-30T10:00:00Z', expires_at: '2026-10-30T10:30:00Z', read_only: true }
    const server = mockApi({
      'GET /api/v1/auth/me': () => meOk(++meCalls === 1 ? platformAdmin() : platformAdmin({ permissions: { 'tenant.user.view': 'tenant' }, support_session: session })),
      'GET /api/v1/platform/tenants/t-1': { json: { data: tenant() } },
      'GET /api/v1/platform/tenants/t-1/subscription': { json: { data: detail() } },
      'GET /api/v1/platform/users': { json: page([]) },
      'POST /api/v1/platform/tenants/t-1/support-session': { status: 201, json: { data: session } },
      'GET /api/v1/users': { json: page([]) },
    })
    const { router } = renderApp('/platform/tenants/t-1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Mở phiên hỗ trợ' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Mở phiên' }))
    expect(await within(dialog).findByText('Phải có ít nhất 10 ký tự.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/platform/tenants/t-1/support-session')).toHaveLength(0)

    await user.type(within(dialog).getByLabelText('Lý do hỗ trợ (ít nhất 10 ký tự)'), 'Khách báo lỗi chi nhánh')
    await user.click(within(dialog).getByRole('button', { name: 'Mở phiên' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/users'))
    expect(server.to('POST', '/api/v1/platform/tenants/t-1/support-session')[0]?.body).toEqual({ reason: 'Khách báo lỗi chi nhánh', minutes: 30 })
    expect(await screen.findByText(/Bạn đang xem dữ liệu của "Cửa hàng Nông Phát" ở chế độ chỉ đọc/)).toBeInTheDocument()
  })
})

describe('việc cần liên hệ', () => {
  const row = {
    tenant: { id: 't-1', name: 'Cửa hàng Nông Phát', phone: '0900111222' },
    owner: { name: 'Nguyễn Chủ', email: 'chu@nongphat.vn', phone: '0911000111' },
    subscription: detail({ state: 'expired', blocked: true, days_left: -2, last_contacted_at: null }),
  }

  it('liệt kê doanh nghiệp sắp/đã hết hạn kèm cách liên hệ và lọc theo tình trạng', async () => {
    const server = mockApi({ ...admin, 'GET /api/v1/platform/subscriptions/renewals': { json: page([row]) } })
    renderApp('/platform/renewals')
    const user = userEvent.setup()

    const rows = await screen.findAllByRole('row')
    expect(rows[1]).toHaveTextContent('Cửa hàng Nông Phát')
    expect(rows[1]).toHaveTextContent('0911000111 · chu@nongphat.vn')
    expect(rows[1]).toHaveTextContent('Hết hạn')
    expect(rows[1]).toHaveTextContent('quá hạn 2 ngày')
    expect(rows[1]).toHaveTextContent('Chưa liên hệ')
    expect(server.calls.at(-1)?.path).toContain('within_days=15')

    await user.selectOptions(screen.getByRole('combobox', { name: 'Lọc theo tình trạng liên hệ' }), 'no')
    await user.selectOptions(screen.getByRole('combobox', { name: 'Hết hạn trong vòng' }), '30')

    await waitFor(() => {
      const last = server.calls.filter((c) => c.path.startsWith('/api/v1/platform/subscriptions/renewals')).at(-1)
      expect(last?.path).toContain('contacted=0')
      expect(last?.path).toContain('within_days=30')
    })
  })

  it('ghi nhận đã liên hệ ngay trên danh sách', async () => {
    const server = mockApi({
      ...admin,
      'GET /api/v1/platform/subscriptions/renewals': { json: page([row]) },
      'POST /api/v1/platform/tenants/t-1/subscription/contact': { json: { data: detail() } },
    })
    renderApp('/platform/renewals')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Đã liên hệ' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText(/Ghi chú \(ví dụ/), 'Đã gọi, khách hẹn trả tiền')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/platform/tenants/t-1/subscription/contact')[0]?.body).toEqual({ note: 'Đã gọi, khách hẹn trả tiền' }))
  })

  it('báo khi không có việc nào cần liên hệ', async () => {
    mockApi({ ...admin, 'GET /api/v1/platform/subscriptions/renewals': { json: page([]) } })
    renderApp('/platform/renewals')

    expect(await screen.findByText('Không có doanh nghiệp nào cần liên hệ.')).toBeInTheDocument()
  })
})

describe('gói và giá', () => {
  it('liệt kê gói với giá tiền Việt Nam và trạng thái bán', async () => {
    mockApi({ ...admin, 'GET /api/v1/platform/plans': { json: { data: [plan(), plan({ id: 'plan-3', key: 'old', name: 'Gói cũ', is_active: false, max_branches: 3, storage_mb: 500 })] } } })
    renderApp('/platform/plans')

    const rows = await screen.findAllByRole('row')
    expect(rows[1]).toHaveTextContent('Tiêu chuẩn')
    expect(rows[1]).toHaveTextContent('150.000 ₫')
    expect(rows[1]).toHaveTextContent('1.500.000 ₫')
    expect(rows[1]).toHaveTextContent('Chi nhánh: không giới hạn')
    expect(rows[1]).toHaveTextContent('Đang bán')
    expect(rows[2]).toHaveTextContent('Ngừng bán')
    expect(rows[2]).toHaveTextContent('Chi nhánh: 3')
    expect(rows[2]).toHaveTextContent('Dung lượng: 500 MB')
  })

  it('sửa giá: mã gói không đổi được, chỉ gửi giá trị đã nhập', async () => {
    const server = mockApi({ ...admin, 'GET /api/v1/platform/plans': { json: { data: [plan()] } }, 'PATCH /api/v1/platform/plans/plan-1': { json: { data: plan() } } })
    renderApp('/platform/plans')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Sửa Tiêu chuẩn' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText('Mã gói')).toHaveAttribute('readonly')
    expect(within(dialog).getByText(/Đổi giá chỉ áp dụng cho khách đăng ký hoặc đổi gói sau này/)).toBeInTheDocument()

    await user.clear(within(dialog).getByLabelText('Giá theo tháng'))
    await user.type(within(dialog).getByLabelText('Giá theo tháng'), '180000')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/platform/plans/plan-1')).toHaveLength(1))
    expect(server.to('PATCH', '/api/v1/platform/plans/plan-1')[0]?.body).toEqual({
      name: 'Tiêu chuẩn',
      description: null,
      price_monthly: 180000,
      price_yearly: 1500000,
      max_branches: null,
      storage_mb: null,
      is_active: true,
      sort_order: 1,
    })
  })

  it('thêm gói mới: kiểm tra mã gói rồi gửi kèm mã', async () => {
    const server = mockApi({ ...admin, 'GET /api/v1/platform/plans': { json: { data: [] } }, 'POST /api/v1/platform/plans': { status: 201, json: { data: plan({ id: 'plan-9', key: 'pro' }) } } })
    renderApp('/platform/plans')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Thêm gói' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Mã gói'), 'Gói Pro!')
    await user.type(within(dialog).getByLabelText('Tên gói'), 'Nâng cao')
    await user.type(within(dialog).getByLabelText('Giá theo tháng'), '250000')
    await user.type(within(dialog).getByLabelText('Giá theo năm'), '2500000')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    expect(await within(dialog).findByText(/Mã gói gồm chữ thường, số, gạch ngang/)).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/platform/plans')).toHaveLength(0)

    await user.clear(within(dialog).getByLabelText('Mã gói'))
    await user.type(within(dialog).getByLabelText('Mã gói'), 'pro')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/platform/plans')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/platform/plans')[0]?.body).toEqual({
      key: 'pro',
      name: 'Nâng cao',
      description: null,
      price_monthly: 250000,
      price_yearly: 2500000,
      max_branches: null,
      storage_mb: null,
      is_active: true,
      sort_order: 0,
    })
  })
})
