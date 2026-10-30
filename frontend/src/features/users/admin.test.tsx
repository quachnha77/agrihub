import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { Branch } from '../branches/api.ts'
import type { Role } from '../roles/api.ts'
import type { UserRow } from './api.ts'

const ALL = {
  'tenant.user.view': 'tenant', 'tenant.user.create': 'tenant', 'tenant.user.update': 'tenant', 'tenant.user.delete': 'tenant',
  'tenant.branch.view': 'tenant', 'tenant.branch.create': 'tenant', 'tenant.branch.update': 'tenant', 'tenant.branch.delete': 'tenant',
  'tenant.role.view': 'tenant',
} as const

const owner = (permissions: Record<string, 'own' | 'branch' | 'tenant'> = { ...ALL }) => meOk(tenantUser({ id: 'me', name: 'Chủ Nông Phát', permissions }))

const branch = (overrides: Partial<Branch> = {}): Branch => ({ id: 'br1', code: 'CN-01', name: 'Chi nhánh chính', phone: null, address: '12 Lê Lợi', status: 'active', ...overrides })
const role = (overrides: Partial<Role> = {}): Role => ({ id: 'r-owner', key: 'owner', name: 'Chủ doanh nghiệp', description: 'Toàn quyền', is_system: true, users_count: 1, permissions: [], ...overrides })
const person = (overrides: Partial<UserRow> = {}): UserRow => ({
  id: 'u2', name: 'Lê Thị Bán Hàng', email: 'ban@nongphat.vn', phone: '0912345678', status: 'active', is_platform_admin: false,
  roles: [{ id: 'r-sales', key: 'sales', name: 'Bán hàng' }], branches: [branch()], last_login_at: '2026-10-30T01:00:00Z', ...overrides,
})
const page = <T,>(rows: T[], total = rows.length, last = 1) => ({ json: { data: rows, meta: { current_page: 1, last_page: last, total, per_page: 20 } } })

const roles = [role(), role({ id: 'r-sales', key: 'sales', name: 'Bán hàng', description: null, is_system: true, users_count: 3 })]

const base = (extra: Record<string, unknown> = {}) => ({
  'GET /api/v1/auth/me': owner(),
  'GET /api/v1/users': page([person({ id: 'me', name: 'Chủ Nông Phát', email: 'chu@nongphat.vn', phone: null, roles: [{ id: 'r-owner', key: 'owner', name: 'Chủ doanh nghiệp' }] }), person()]),
  'GET /api/v1/roles': { json: { data: roles } },
  'GET /api/v1/branches': page([branch(), branch({ id: 'br2', code: 'CN-02', name: 'Kho Tân Phú' })]),
  ...extra,
})

async function openRowAction(name: RegExp) {
  const user = userEvent.setup()
  await user.click(await screen.findByRole('button', { name }))

  return user
}

describe('trang Người dùng', () => {
  it('liệt kê người dùng với liên hệ, vai trò, chi nhánh, trạng thái; không cho thao tác lên chính mình', async () => {
    mockApi(base())
    renderApp('/users')

    const table = await screen.findByRole('table')
    expect(within(table).getByText('Lê Thị Bán Hàng')).toBeInTheDocument()
    expect(within(table).getByText('ban@nongphat.vn')).toBeInTheDocument()
    expect(within(table).getByText('0912345678')).toBeInTheDocument()
    expect(within(table).getByText('Bán hàng')).toBeInTheDocument()
    expect(within(table).getAllByText('CN-01').length).toBeGreaterThan(0)
    expect(within(table).getByText('(bạn)')).toBeInTheDocument()

    expect(within(table).getByRole('button', { name: 'Sửa Chủ Nông Phát' })).toBeInTheDocument()
    expect(within(table).queryByRole('button', { name: /Xóa Chủ Nông Phát/ })).not.toBeInTheDocument()
    expect(within(table).queryByRole('button', { name: /Khóa Chủ Nông Phát/ })).not.toBeInTheDocument()
    expect(within(table).getByRole('button', { name: 'Xóa Lê Thị Bán Hàng' })).toBeInTheDocument()
  })

  it('tìm kiếm gửi từ khóa lên máy chủ và quay về trang 1', async () => {
    const server = mockApi(base())
    renderApp('/users')
    const user = userEvent.setup()

    await user.type(await screen.findByRole('searchbox', { name: 'Tìm kiếm' }), 'bán')

    await waitFor(() => expect(server.calls.some((c) => c.path.startsWith('/api/v1/users?') && decodeURIComponent(c.path).includes('q=bán'))).toBe(true))
  })

  it('ẩn nút và chặn trang theo quyền', async () => {
    mockApi(base({ 'GET /api/v1/auth/me': owner({ 'tenant.user.view': 'tenant' }) }))
    renderApp('/users')

    await screen.findByRole('table')
    expect(screen.queryByRole('button', { name: 'Thêm người dùng' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Sửa / })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Xóa / })).not.toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Menu chính' })).not.toHaveTextContent('Chi nhánh')
  })

  it('không vào được trang khi không có quyền xem', async () => {
    mockApi({ 'GET /api/v1/auth/me': owner({}) })
    renderApp('/users')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
  })

  it('thêm người dùng: kiểm tra tại chỗ rồi gửi, và hiện mật khẩu tạm một lần', async () => {
    const server = mockApi(base({ 'POST /api/v1/users': { status: 201, json: { data: person({ id: 'u9', name: 'Trần Kho' }), temporary_password: 'Tam-Th0i-Abc-123' } } }))
    renderApp('/users')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Thêm người dùng' }))
    const dialog = await screen.findByRole('dialog', { name: 'Thêm người dùng' })

    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))
    expect(await within(dialog).findByText('Vui lòng nhập họ tên.')).toBeInTheDocument()
    expect(within(dialog).getByText('Vui lòng nhập email.')).toBeInTheDocument()

    await user.type(within(dialog).getByLabelText('Họ tên'), 'Trần Kho')
    await user.type(within(dialog).getByLabelText('Email'), 'khong-phai-email')
    await user.type(within(dialog).getByLabelText('Số điện thoại (tùy chọn)'), '12345')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))
    expect(await within(dialog).findByText('Email không hợp lệ.')).toBeInTheDocument()
    expect(within(dialog).getByText('Số điện thoại không hợp lệ (ví dụ 0912 345 678).')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/users')).toHaveLength(0)

    await user.clear(within(dialog).getByLabelText('Email'))
    await user.type(within(dialog).getByLabelText('Email'), 'kho@nongphat.vn')
    await user.clear(within(dialog).getByLabelText('Số điện thoại (tùy chọn)'))
    await user.type(within(dialog).getByLabelText('Số điện thoại (tùy chọn)'), '0912 345 678')
    expect(within(dialog).getByText('Sẽ lưu là 0912345678')).toBeInTheDocument()
    await user.click(await within(dialog).findByRole('checkbox', { name: 'Bán hàng' }))
    await user.click(within(dialog).getByRole('checkbox', { name: 'CN-02 — Kho Tân Phú' }))
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    const secret = await screen.findByTestId('temporary-password')
    expect(secret).toHaveTextContent('Tam-Th0i-Abc-123')
    expect(screen.getByText(/chỉ hiển thị MỘT lần/)).toBeInTheDocument()
    expect(screen.getByText(/Người dùng sẽ phải đặt mật khẩu mới/)).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/users')[0]?.body).toEqual({
      name: 'Trần Kho', email: 'kho@nongphat.vn', phone: '0912 345 678', role_ids: ['r-sales'], branch_ids: ['br2'],
    })
  })

  it('hiện lỗi của máy chủ đúng ô (email đã có)', async () => {
    mockApi(base({ 'POST /api/v1/users': problem(422, 'validation_failed', 'x', { errors: { email: ['Email này không khả dụng.'] } }) }))
    renderApp('/users')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Thêm người dùng' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Họ tên'), 'Ai Đó')
    await user.type(within(dialog).getByLabelText('Email'), 'trung@nongphat.vn')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    expect(await within(dialog).findByText('Email này không khả dụng.')).toBeInTheDocument()
  })

  it('sửa người dùng: email bị khóa, chỉ gọi các API cho phần thật sự đổi', async () => {
    const server = mockApi(base({
      'PATCH /api/v1/users/u2': { json: { data: person() } },
      'PUT /api/v1/users/u2/roles': { json: { data: person() } },
      'PUT /api/v1/users/u2/branches': { json: { data: person() } },
    }))
    renderApp('/users')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Sửa Lê Thị Bán Hàng' }))
    const dialog = await screen.findByRole('dialog', { name: 'Sửa người dùng' })

    expect(within(dialog).getByLabelText('Email')).toHaveAttribute('readonly')
    expect(within(dialog).getByRole('checkbox', { name: 'Bán hàng' })).toBeChecked()

    await user.clear(within(dialog).getByLabelText('Họ tên'))
    await user.type(within(dialog).getByLabelText('Họ tên'), 'Lê Thị Quản Lý')
    await user.click(within(dialog).getByRole('checkbox', { name: /Chủ doanh nghiệp/ }))
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PUT', '/api/v1/users/u2/roles')).toHaveLength(1))
    expect(server.to('PATCH', '/api/v1/users/u2')[0]?.body).toEqual({ name: 'Lê Thị Quản Lý', phone: '0912345678' })
    expect(server.to('PUT', '/api/v1/users/u2/roles')[0]?.body).toEqual({ role_ids: ['r-sales', 'r-owner'] })
    expect(server.to('PUT', '/api/v1/users/u2/branches')).toHaveLength(0)
  })

  it('hiện lý do khi máy chủ từ chối gán vai trò vượt quyền', async () => {
    mockApi(base({
      'PATCH /api/v1/users/u2': { json: { data: person() } },
      'PUT /api/v1/users/u2/roles': problem(403, 'role_exceeds_privileges', 'Không thể gán hoặc gỡ vai trò có quyền vượt quá quyền của chính bạn.'),
    }))
    renderApp('/users')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Sửa Lê Thị Bán Hàng' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(await within(dialog).findByRole('checkbox', { name: /Chủ doanh nghiệp/ }))
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('vai trò có quyền vượt quá quyền của chính bạn')
  })

  it('cấp mật khẩu tạm: xác nhận rồi hiện mật khẩu một lần', async () => {
    const server = mockApi(base({ 'POST /api/v1/users/u2/reset-password': { json: { data: person(), temporary_password: 'Moi-Cap-Lai-77' } } }))
    renderApp('/users')
    const user = await openRowAction(/Cấp mật khẩu tạm cho Lê Thị Bán Hàng/)

    const confirm = await screen.findByRole('dialog', { name: 'Cấp mật khẩu tạm' })
    expect(confirm).toHaveTextContent('bị đăng xuất khỏi mọi thiết bị')
    expect(server.to('POST', '/api/v1/users/u2/reset-password')).toHaveLength(0)

    await user.click(within(confirm).getByRole('button', { name: 'Cấp mật khẩu tạm' }))

    expect(await screen.findByTestId('temporary-password')).toHaveTextContent('Moi-Cap-Lai-77')
  })

  it('khóa và mở khóa tài khoản', async () => {
    const server = mockApi(base({ 'PATCH /api/v1/users/u2': { json: { data: person() } } }))
    renderApp('/users')
    const user = await openRowAction(/^Khóa Lê Thị Bán Hàng/)

    await user.click(within(await screen.findByRole('dialog', { name: 'Khóa tài khoản' })).getByRole('button', { name: 'Khóa' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/users/u2')).toHaveLength(1))
    expect(server.to('PATCH', '/api/v1/users/u2')[0]?.body).toEqual({ status: 'disabled' })
  })

  it('xóa người dùng có xác nhận; lỗi của máy chủ hiện ngay trong hộp xác nhận', async () => {
    mockApi(base({ 'DELETE /api/v1/users/u2': problem(422, 'last_owner', 'Doanh nghiệp phải còn ít nhất một Chủ doanh nghiệp đang hoạt động.') }))
    renderApp('/users')
    const user = await openRowAction(/^Xóa Lê Thị Bán Hàng/)

    const confirm = await screen.findByRole('dialog', { name: 'Xóa người dùng' })
    await user.click(within(confirm).getByRole('button', { name: 'Xóa' }))

    expect(await within(confirm).findByRole('alert')).toHaveTextContent('Doanh nghiệp phải còn ít nhất một Chủ doanh nghiệp')
    expect(screen.getByRole('dialog', { name: 'Xóa người dùng' })).toBeInTheDocument()
  })

  it('phân trang', async () => {
    mockApi(base({ 'GET /api/v1/users': { json: { data: [person()], meta: { current_page: 1, last_page: 3, total: 41, per_page: 20 } } } }))
    renderApp('/users')

    expect(await screen.findByText(/Tổng 41 mục · Trang 1\/3/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Trước' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Sau' })).toBeEnabled()
  })
})

describe('trang Chi nhánh', () => {
  it('liệt kê và thêm chi nhánh (kiểm tra mã ngay trên trình duyệt)', async () => {
    const server = mockApi(base({ 'POST /api/v1/branches': { status: 201, json: { data: branch({ id: 'br3', code: 'CN-03' }) } } }))
    renderApp('/branches')
    const user = userEvent.setup()

    expect(await screen.findByText('Kho Tân Phú')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Thêm chi nhánh' }))
    const dialog = await screen.findByRole('dialog', { name: 'Thêm chi nhánh' })

    await user.type(within(dialog).getByLabelText('Mã chi nhánh'), 'CN 03!')
    await user.type(within(dialog).getByLabelText('Tên chi nhánh'), 'Chi nhánh Bình Dương')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))
    expect(await within(dialog).findByText('Mã chỉ gồm chữ, số, dấu chấm, gạch ngang hoặc gạch dưới.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/branches')).toHaveLength(0)

    await user.clear(within(dialog).getByLabelText('Mã chi nhánh'))
    await user.type(within(dialog).getByLabelText('Mã chi nhánh'), 'CN-03')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/branches')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/branches')[0]?.body).toEqual({ code: 'CN-03', name: 'Chi nhánh Bình Dương', phone: null, address: null })
  })

  it('sửa chi nhánh và đổi trạng thái', async () => {
    const server = mockApi(base({ 'PATCH /api/v1/branches/br2': { json: { data: branch({ id: 'br2' }) } } }))
    renderApp('/branches')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Sửa Kho Tân Phú' }))
    const dialog = await screen.findByRole('dialog', { name: 'Sửa chi nhánh' })
    await user.selectOptions(within(dialog).getByLabelText('Trạng thái'), 'inactive')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/branches/br2')).toHaveLength(1))
    expect(server.to('PATCH', '/api/v1/branches/br2')[0]?.body).toMatchObject({ code: 'CN-02', status: 'inactive' })
  })

  it('không xóa được chi nhánh còn người dùng: hiện lý do', async () => {
    mockApi(base({ 'DELETE /api/v1/branches/br1': problem(409, 'branch_in_use', 'Chi nhánh còn người dùng nên không thể xóa.') }))
    renderApp('/branches')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Xóa Chi nhánh chính' }))
    const confirm = await screen.findByRole('dialog', { name: 'Xóa chi nhánh' })
    await user.click(within(confirm).getByRole('button', { name: 'Xóa' }))

    expect(await within(confirm).findByRole('alert')).toHaveTextContent('Chi nhánh còn người dùng nên không thể xóa.')
  })

  it('ẩn nút thêm/sửa/xóa khi chỉ có quyền xem', async () => {
    mockApi(base({ 'GET /api/v1/auth/me': owner({ 'tenant.branch.view': 'branch' }) }))
    renderApp('/branches')

    await screen.findByRole('table')
    expect(screen.queryByRole('button', { name: 'Thêm chi nhánh' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Sửa / })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Xóa / })).not.toBeInTheDocument()
  })
})

describe('trang Vai trò & phân quyền', () => {
  it('nêu vai trò hệ thống, số người dùng và quyền theo nhóm với phạm vi dữ liệu', async () => {
    mockApi(base({
      'GET /api/v1/roles': {
        json: {
          data: [
            role({ permissions: [] }),
            role({ id: 'r-sales', key: 'sales', name: 'Bán hàng', description: 'Bán hàng tại quầy', users_count: 3, permissions: [
              { key: 'tenant.user.view', scope: 'branch' }, { key: 'tenant.branch.view', scope: 'own' }, { key: 'tenant.branch.update', scope: 'tenant' },
            ] }),
          ],
        },
      },
      'GET /api/v1/permissions': { json: { data: [
        { resource: 'tenant.user', label: 'Người dùng', actions: ['view'] },
        { resource: 'tenant.branch', label: 'Chi nhánh', actions: ['view', 'update'] },
      ], scopes: ['own', 'branch', 'tenant'] } },
    }))
    renderApp('/roles')

    const sales = await screen.findByRole('region', { name: 'Bán hàng' })
    expect(within(sales).getByText('3 người dùng')).toBeInTheDocument()
    expect(within(sales).getByText('Bán hàng tại quầy')).toBeInTheDocument()
    expect(within(sales).getByText('Xem quyền (3)')).toBeInTheDocument()

    expect(within(sales).getByText('Người dùng').nextSibling).toHaveTextContent('Xem (trong chi nhánh)')
    expect(within(sales).getByText('Chi nhánh').nextSibling).toHaveTextContent('Xem (của mình), Sửa (toàn doanh nghiệp)')
    expect(screen.getAllByText('Vai trò hệ thống').length).toBe(2)
  })
})
