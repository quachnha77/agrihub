import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { ListItem } from '../catalog/api.ts'
import type { Customer, Supplier } from './api.ts'

type Permissions = Record<string, 'own' | 'branch' | 'tenant'>

const FULL: Permissions = {
  'crm.customer.view': 'tenant', 'crm.customer.create': 'tenant', 'crm.customer.update': 'tenant', 'crm.customer.delete': 'tenant',
  'purchase.supplier.view': 'tenant', 'purchase.supplier.create': 'tenant', 'purchase.supplier.update': 'tenant', 'purchase.supplier.delete': 'tenant',
  'tenant.branch.view': 'tenant',
}
// Nhân viên bán hàng: xem và thêm khách trong chi nhánh được gán; không sửa, không xóa; chỉ xem nhà cung cấp.
const SALES: Permissions = { 'crm.customer.view': 'branch', 'crm.customer.create': 'branch', 'purchase.supplier.view': 'branch', 'tenant.branch.view': 'branch' }
const VIEW_ONLY: Permissions = { 'crm.customer.view': 'branch', 'purchase.supplier.view': 'branch', 'tenant.branch.view': 'branch' }

const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 } })

const GROUPS: ListItem[] = [
  { id: 'g-1', name: 'Nông dân', is_active: true },
  { id: 'g-2', name: 'Đại lý', is_active: true },
]
const BRANCHES = [
  { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính', phone: null, address: null, status: 'active' },
  { id: 'br-2', code: 'HN-02', name: 'Chi nhánh Hà Nội', phone: null, address: null, status: 'active' },
]

const customer = (overrides: Partial<Customer> = {}): Customer => ({
  id: 'c1',
  code: 'KH0001',
  type: 'individual',
  type_label: 'Cá nhân',
  name: 'Nguyễn Văn Nông',
  branch: { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính' },
  group: { id: 'g-1', name: 'Nông dân' },
  phone: '0911000001',
  email: null,
  tax_code: null,
  credit_limit: '5000000.00',
  credit_days: 30,
  notes: 'Trồng lúa 5 ha',
  status: 'active',
  addresses: [
    { label: 'Ruộng', address: 'Ấp 3, xã Tân Lợi', ward: null, district: 'Huyện Thới Lai', province: 'Cần Thơ', is_default: true },
    { label: 'Vườn', address: 'Ấp 5, xã Phước Thới', ward: null, district: null, province: 'Cần Thơ', is_default: false },
  ],
  contacts: [{ name: 'Chị Lan', phone: '0911000014', position: 'Kế toán', note: null }],
  created_at: '2026-10-31T00:00:00Z',
  updated_at: '2026-10-31T00:00:00Z',
  ...overrides,
})

const CASH_ONLY = customer({ id: 'c2', code: 'KH0002', name: 'Công ty TNHH Nông sản Xanh', type: 'company', group: null, phone: null, credit_limit: '0.00', credit_days: null, status: 'inactive', addresses: [], contacts: [] })

const supplier = (overrides: Partial<Supplier> = {}): Supplier => ({
  id: 's1',
  code: 'NCC0001',
  name: 'Công ty Phân bón Miền Nam',
  tax_code: '0300000101',
  phone: '0281000001',
  email: null,
  address: '1 Đường số 1, TP.HCM',
  payment_terms_days: 30,
  notes: null,
  status: 'active',
  contacts: [{ name: 'Anh Hùng', phone: '0911000101', email: 'hung@mau.vn', position: 'Kinh doanh' }],
  created_at: '2026-10-31T00:00:00Z',
  updated_at: '2026-10-31T00:00:00Z',
  ...overrides,
})

const BAYER = supplier({ id: 's2', code: 'NCC0002', name: 'Bayer Việt Nam', tax_code: null, phone: null, payment_terms_days: null, status: 'inactive', contacts: [] })

const base = (extra: Record<string, unknown> = {}, permissions: Permissions = FULL) =>
  ({
    'GET /api/v1/auth/me': meOk(tenantUser({ permissions })),
    'GET /api/v1/branches': { json: page(BRANCHES) },
    'GET /api/v1/customer-groups': { json: page(GROUPS) },
    'GET /api/v1/customers': { json: page([customer(), CASH_ONLY]) },
    'GET /api/v1/suppliers': { json: page([supplier(), BAYER]) },
    ...extra,
  }) as never

describe('danh sách khách hàng', () => {
  it('hiện mã, loại, nhóm, hạn mức công nợ kèm số ngày nợ, và "Không ghi nợ" khi hạn mức 0', async () => {
    mockApi(base())
    renderApp('/customers')

    const table = await screen.findByRole('table')
    expect(within(table).getByText('Nguyễn Văn Nông')).toBeInTheDocument()
    expect(within(table).getByText('KH0001')).toBeInTheDocument()
    expect(within(table).getByText('Cá nhân')).toBeInTheDocument()
    expect(within(table).getByText('Nông dân')).toBeInTheDocument()
    expect(within(table).getByText('5.000.000 ₫')).toBeInTheDocument()
    expect(within(table).getByText('Tối đa 30 ngày')).toBeInTheDocument()
    expect(within(table).getByText('Không ghi nợ')).toBeInTheDocument()
    expect(within(table).getByText('Đang giao dịch')).toBeInTheDocument()
    expect(within(table).getByText('Ngừng giao dịch')).toBeInTheDocument()
    // Có nhiều chi nhánh nên có cột chi nhánh phụ trách.
    expect(within(table).getAllByText('CN-01', { selector: 'td' })).toHaveLength(2)
  })

  it('có mục "Khách hàng" và "Nhà cung cấp" trong menu khi có quyền xem', async () => {
    mockApi(base())
    renderApp('/customers')

    const nav = await screen.findByRole('navigation', { name: 'Menu chính' })
    expect(within(nav).getByRole('link', { name: 'Khách hàng' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Nhà cung cấp' })).toBeInTheDocument()
  })

  it('không có quyền xem khách thì không có trong menu và gõ thẳng đường dẫn bị chặn, không gọi API', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser({ permissions: { 'tenant.branch.view': 'tenant' } })) })
    renderApp('/customers')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Menu chính' })).not.toHaveTextContent('Khách hàng')
    expect(server.calls.filter((call) => call.path.startsWith('/api/v1/customers'))).toHaveLength(0)
  })

  it('tìm kiếm và lọc gửi đúng tham số lên máy chủ', async () => {
    const server = mockApi(base())
    renderApp('/customers')
    const user = userEvent.setup()

    await screen.findByRole('table')
    await user.selectOptions(screen.getByLabelText('Loại khách'), 'agent')
    await user.selectOptions(screen.getByLabelText('Nhóm khách'), 'g-2')
    await user.selectOptions(screen.getByLabelText('Chi nhánh phụ trách'), 'br-2')
    await user.selectOptions(screen.getByLabelText('Trạng thái'), 'inactive')
    await user.type(screen.getByLabelText('Tìm kiếm'), 'phát tài')

    await waitFor(() => {
      const last = server.calls.filter((c) => c.method === 'GET' && c.path.startsWith('/api/v1/customers?')).at(-1)?.path ?? ''
      expect(last).toContain('type=agent')
      expect(last).toContain('group=g-2')
      expect(last).toContain('branch=br-2')
      expect(last).toContain('status=inactive')
      expect(new URLSearchParams(last.split('?')[1]).get('q')).toBe('phát tài')
    })
  })

  it('người chỉ có quyền xem: không có nút thêm hay xóa; liên kết là "Xem"', async () => {
    mockApi(base({}, VIEW_ONLY))
    renderApp('/customers')

    const table = await screen.findByRole('table')
    expect(screen.queryByRole('link', { name: 'Thêm khách hàng' })).not.toBeInTheDocument()
    expect(within(table).queryByRole('button', { name: /^Xóa/ })).not.toBeInTheDocument()
    expect(within(table).getByRole('link', { name: 'Xem Nguyễn Văn Nông' })).toBeInTheDocument()
  })

  it('xóa khách hàng: hỏi xác nhận, gọi API rồi lấy lại danh sách', async () => {
    let deleted = false
    const server = mockApi(
      base({
        'GET /api/v1/customers': () => ({ json: page(deleted ? [CASH_ONLY] : [customer(), CASH_ONLY]) }),
        'DELETE /api/v1/customers/c1': () => {
          deleted = true

          return { status: 204 }
        },
      }),
    )
    renderApp('/customers')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Xóa Nguyễn Văn Nông' }))
    const dialog = await screen.findByRole('dialog', { name: 'Xóa khách hàng' })
    expect(within(dialog).getByText(/KH0001/)).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Xóa' }))

    await waitFor(() => expect(server.to('DELETE', '/api/v1/customers/c1')).toHaveLength(1))
    await waitFor(() => expect(screen.queryByText('Nguyễn Văn Nông')).not.toBeInTheDocument())
  })
})

describe('thêm khách hàng', () => {
  it('kiểm tra ngay trên trình duyệt: báo từng ô còn thiếu và không gọi API', async () => {
    const server = mockApi(base())
    renderApp('/notifications?dialog=customer')
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Số điện thoại'), '123')
    await user.type(screen.getByLabelText('Hạn mức công nợ (đồng)'), 'abc')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    expect(await screen.findByText('Vui lòng chọn chi nhánh phụ trách.')).toBeInTheDocument()
    expect(screen.getByText('Vui lòng nhập tên khách hàng.')).toBeInTheDocument()
    expect(screen.getByText('Số điện thoại không hợp lệ (ví dụ 0912 345 678).')).toBeInTheDocument()
    expect(screen.getByText('Nhập số tiền hợp lệ (từ 0, tối đa 2 chữ số thập phân).')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/customers')).toHaveLength(0)
  })

  it('gửi đúng nội dung: tiền theo kiểu Việt Nam, địa chỉ mặc định, người liên hệ; rồi về danh sách kèm thông báo', async () => {
    const server = mockApi(base({ 'POST /api/v1/customers': { status: 201, json: { data: customer({ id: 'c9', name: 'Đại lý Phát Tài' }) } } }))
    renderApp('/notifications?dialog=customer')
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByLabelText('Chi nhánh phụ trách'), 'br-2')
    await user.selectOptions(screen.getByLabelText('Loại khách'), 'agent')
    await user.type(screen.getByLabelText('Tên khách hàng'), 'Đại lý Phát Tài')
    await user.type(screen.getByLabelText('Số điện thoại'), '0912 345 678')
    await user.type(screen.getByLabelText('Mã số thuế'), '0300000011')
    await user.selectOptions(screen.getByLabelText('Nhóm khách'), 'g-2')
    await user.type(screen.getByLabelText('Hạn mức công nợ (đồng)'), '50.000.000')
    await user.type(screen.getByLabelText('Số ngày nợ tối đa'), '45')
    // Hiện lại số tiền đã gõ theo định dạng chuẩn để tránh nhầm dấu chấm/phẩy.
    expect(screen.getByText('50.000.000 ₫')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Thêm địa chỉ' }))
    await user.click(screen.getByRole('button', { name: 'Thêm địa chỉ' }))
    const first = screen.getByRole('group', { name: 'Địa chỉ 1' })
    const second = screen.getByRole('group', { name: 'Địa chỉ 2' })
    // Địa chỉ đầu tiên tự là mặc định.
    expect(within(first).getByRole('checkbox', { name: 'Địa chỉ mặc định' })).toBeChecked()
    expect(within(second).getByRole('checkbox', { name: 'Địa chỉ mặc định' })).not.toBeChecked()
    await user.type(within(first).getByLabelText('Số nhà, đường, ấp/khu vực'), '25 Quốc lộ 91')
    await user.type(within(first).getByLabelText('Tên gọi'), 'Cửa hàng')
    await user.type(within(second).getByLabelText('Số nhà, đường, ấp/khu vực'), 'Kho Ô Môn')
    // Chọn địa chỉ 2 làm mặc định thì địa chỉ 1 bỏ mặc định (chỉ một địa chỉ mặc định).
    await user.click(within(second).getByRole('checkbox', { name: 'Địa chỉ mặc định' }))
    expect(within(first).getByRole('checkbox', { name: 'Địa chỉ mặc định' })).not.toBeChecked()

    await user.click(screen.getByRole('button', { name: 'Thêm người liên hệ' }))
    const contact = screen.getByRole('group', { name: 'Người liên hệ 1' })
    await user.type(within(contact).getByLabelText('Họ tên'), 'Anh Tài')
    await user.type(within(contact).getByLabelText('Chức vụ'), 'Chủ đại lý')

    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/customers')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/customers')[0]?.body).toEqual({
      branch_id: 'br-2',
      type: 'agent',
      name: 'Đại lý Phát Tài',
      phone: '0912 345 678',
      email: null,
      tax_code: '0300000011',
      group_id: 'g-2',
      credit_limit: 50000000,
      credit_days: 45,
      notes: null,
      status: 'active',
      addresses: [
        { label: 'Cửa hàng', address: '25 Quốc lộ 91', ward: null, district: null, province: null, is_default: false },
        { label: null, address: 'Kho Ô Môn', ward: null, district: null, province: null, is_default: true },
      ],
      contacts: [{ name: 'Anh Tài', phone: null, position: 'Chủ đại lý', note: null }],
    })

    expect(await screen.findByText('Đã thêm khách hàng "Đại lý Phát Tài".')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument()) // lưu xong popup tự đóng, không chuyển trang
  })

  it('để trống mã thì không gửi mã (máy chủ tự cấp); hạn mức mặc định 0 và số ngày nợ bỏ trống gửi null', async () => {
    const server = mockApi(base({ 'POST /api/v1/customers': { status: 201, json: { data: customer() } } }))
    renderApp('/notifications?dialog=customer')
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByLabelText('Chi nhánh phụ trách'), 'br-1')
    await user.type(screen.getByLabelText('Tên khách hàng'), 'Khách lẻ')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/customers')).toHaveLength(1))
    const body = server.to('POST', '/api/v1/customers')[0]?.body as Record<string, unknown>
    expect(body).not.toHaveProperty('code')
    expect(body).toMatchObject({ credit_limit: 0, credit_days: null, group_id: null, addresses: [], contacts: [] })
  })

  it('lỗi từ máy chủ hiện đúng ô: số điện thoại, địa chỉ và người liên hệ theo dòng; lỗi chung hiện ở đầu form', async () => {
    mockApi(
      base({
        'POST /api/v1/customers': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', {
          errors: {
            phone: ['Số điện thoại này đã có ở khách hàng khác.'],
            code: ['Mã khách hàng này đã được dùng.'],
            'addresses.1.address': ['Địa chỉ quá dài.'],
            'contacts.0.phone': ['Số điện thoại người liên hệ không hợp lệ.'],
            addresses: ['Chỉ được chọn một địa chỉ mặc định.'],
          },
        }),
      }),
    )
    renderApp('/notifications?dialog=customer')
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByLabelText('Chi nhánh phụ trách'), 'br-1')
    await user.type(screen.getByLabelText('Tên khách hàng'), 'Khách trùng')
    await user.click(screen.getByRole('button', { name: 'Thêm địa chỉ' }))
    await user.click(screen.getByRole('button', { name: 'Thêm địa chỉ' }))
    await user.type(within(screen.getByRole('group', { name: 'Địa chỉ 1' })).getByLabelText('Số nhà, đường, ấp/khu vực'), 'A')
    await user.type(within(screen.getByRole('group', { name: 'Địa chỉ 2' })).getByLabelText('Số nhà, đường, ấp/khu vực'), 'B')
    await user.click(screen.getByRole('button', { name: 'Thêm người liên hệ' }))
    await user.type(within(screen.getByRole('group', { name: 'Người liên hệ 1' })).getByLabelText('Họ tên'), 'Anh C')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    expect(await screen.findByText('Số điện thoại này đã có ở khách hàng khác.')).toBeInTheDocument()
    expect(screen.getByText('Mã khách hàng này đã được dùng.')).toBeInTheDocument()
    expect(within(screen.getByRole('group', { name: 'Địa chỉ 2' })).getByText('Địa chỉ quá dài.')).toBeInTheDocument()
    expect(within(screen.getByRole('group', { name: 'Người liên hệ 1' })).getByText('Số điện thoại người liên hệ không hợp lệ.')).toBeInTheDocument()
    expect(screen.getByText('Chỉ được chọn một địa chỉ mặc định.')).toBeInTheDocument()
  })

  it('chỉ có một chi nhánh được dùng thì chọn sẵn', async () => {
    mockApi(base({ 'GET /api/v1/branches': { json: page([BRANCHES[0]]) } }))
    renderApp('/notifications?dialog=customer')

    await waitFor(() => expect(screen.getByLabelText('Chi nhánh phụ trách')).toHaveValue('br-1'))
  })

  it('nhân viên bán hàng (quyền chi nhánh) vào được trang thêm khách, nhưng chỉ xem khi mở khách đã có', async () => {
    mockApi(base({ 'GET /api/v1/customers/c1': { json: { data: customer() } } }, SALES))
    renderApp('/notifications?dialog=customer')
    expect(await screen.findByRole('heading', { name: 'Thêm khách hàng' })).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Lưu' })).toBeInTheDocument() // khung popup hiện ngay; nút Lưu có khi biểu mẫu đã dựng xong
    expect(screen.queryByText('Bạn chỉ có quyền xem khách hàng này.')).not.toBeInTheDocument()
  })

  it('nhân viên bán hàng mở khách đã có thì form bị khóa (không có quyền sửa)', async () => {
    mockApi(base({ 'GET /api/v1/customers/c1': { json: { data: customer() } } }, SALES))
    renderApp('/notifications?dialog=customer:c1')

    expect(await screen.findByText('Bạn chỉ có quyền xem khách hàng này.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Lưu' })).not.toBeInTheDocument()
  })

  it('người không có quyền tạo không vào được trang thêm', async () => {
    mockApi(base({}, VIEW_ONLY))
    renderApp('/notifications?dialog=customer')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
  })
})

describe('sửa khách hàng', () => {
  const detail = (c: Customer = customer()) => ({ [`GET /api/v1/customers/${c.id}`]: { json: { data: c } } })

  it('hiện sẵn giá trị đang có; mã chỉ đọc, địa chỉ và người liên hệ được nạp theo dòng', async () => {
    mockApi(base(detail()))
    renderApp('/notifications?dialog=customer:c1')

    const editor = await screen.findByRole('dialog', { name: 'Sửa khách hàng' })

    await waitFor(() => expect(editor).toHaveAccessibleDescription(/Nguyễn Văn Nông/)) // tên bản ghi nằm ở dòng mô tả của popup
    expect(screen.getByLabelText('Tên khách hàng')).toHaveValue('Nguyễn Văn Nông')
    expect(screen.getByLabelText('Nhóm khách')).toHaveValue('g-1')
    expect(screen.getByLabelText('Chi nhánh phụ trách')).toHaveValue('br-1')
    expect(screen.getByLabelText('Hạn mức công nợ (đồng)')).toHaveValue('5000000')
    expect(screen.getByLabelText('Số ngày nợ tối đa')).toHaveValue('30')
    expect(screen.queryByLabelText('Mã')).not.toBeInTheDocument() // mã hiện dạng chỉ đọc
    const first = screen.getByRole('group', { name: 'Địa chỉ 1' })
    expect(within(first).getByLabelText('Số nhà, đường, ấp/khu vực')).toHaveValue('Ấp 3, xã Tân Lợi')
    expect(within(first).getByRole('checkbox', { name: 'Địa chỉ mặc định' })).toBeChecked()
    expect(within(screen.getByRole('group', { name: 'Địa chỉ 2' })).getByRole('checkbox', { name: 'Địa chỉ mặc định' })).not.toBeChecked()
    expect(within(screen.getByRole('group', { name: 'Người liên hệ 1' })).getByLabelText('Họ tên')).toHaveValue('Chị Lan')
  })

  it('lưu bằng PATCH, không gửi mã; bỏ một địa chỉ thì không gửi dòng đó nữa', async () => {
    const server = mockApi(base({ ...detail(), 'PATCH /api/v1/customers/c1': { json: { data: customer({ name: 'Nông Văn Mới' }) } } }))
    renderApp('/notifications?dialog=customer:c1')
    const user = userEvent.setup()

    const name = await screen.findByLabelText('Tên khách hàng')
    await user.clear(name)
    await user.type(name, 'Nông Văn Mới')
    await user.click(screen.getByRole('button', { name: 'Bỏ địa chỉ 2' }))
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/customers/c1')).toHaveLength(1))
    const body = server.to('PATCH', '/api/v1/customers/c1')[0]?.body as { addresses: unknown[]; contacts: unknown[] } & Record<string, unknown>
    expect(body).not.toHaveProperty('code')
    expect(body).not.toHaveProperty('branch_id') // chi nhánh không đổi thì không gửi
    expect(body).toMatchObject({ name: 'Nông Văn Mới', group_id: 'g-1', credit_limit: 5000000, credit_days: 30 })
    expect(body.addresses).toHaveLength(1)
    expect(body.contacts).toHaveLength(1)
    expect(await screen.findByText('Đã lưu thay đổi của khách hàng "Nông Văn Mới".')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument()) // lưu xong popup tự đóng, không chuyển trang
  })

  it('đổi chi nhánh phụ trách = chuyển khách: gửi chi nhánh mới', async () => {
    const server = mockApi(base({ ...detail(), 'PATCH /api/v1/customers/c1': { json: { data: customer() } } }))
    renderApp('/notifications?dialog=customer:c1')
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByLabelText('Chi nhánh phụ trách'), 'br-2')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/customers/c1')).toHaveLength(1))
    expect(server.to('PATCH', '/api/v1/customers/c1')[0]?.body).toMatchObject({ branch_id: 'br-2' })
  })

  it('nhóm khách đã ngừng dùng vẫn hiện để không mất giá trị khi sửa', async () => {
    mockApi(
      base({
        ...detail(),
        'GET /api/v1/customer-groups': { json: page([{ id: 'g-1', name: 'Nông dân', is_active: false }, GROUPS[1]]) },
      }),
    )
    renderApp('/notifications?dialog=customer:c1')

    expect(await screen.findByLabelText('Nhóm khách')).toHaveValue('g-1')
  })

  it('người chỉ có quyền xem: form bị khóa, không có nút Lưu', async () => {
    mockApi(base(detail(), VIEW_ONLY))
    renderApp('/notifications?dialog=customer:c1')

    expect(await screen.findByText('Bạn chỉ có quyền xem khách hàng này.')).toBeInTheDocument()
    expect(screen.getByLabelText('Tên khách hàng')).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Lưu' })).not.toBeInTheDocument()
  })

  it('khách không tồn tại hoặc ngoài phạm vi chi nhánh: báo không tìm thấy', async () => {
    mockApi(base({ 'GET /api/v1/customers/zzz': problem(404, 'not_found', 'Không tìm thấy.') }))
    renderApp('/notifications?dialog=customer:zzz')

    expect(await screen.findByText('Không tìm thấy khách hàng này hoặc bạn không có quyền xem.')).toBeInTheDocument()
  })
})

describe('nhóm khách hàng', () => {
  it('chủ doanh nghiệp thêm và đổi tên được nhóm', async () => {
    const server = mockApi(
      base({
        'POST /api/v1/customer-groups': { status: 201, json: { data: { id: 'g-3', name: 'Khách quen', is_active: true } } },
        'PATCH /api/v1/customer-groups/g-1': { json: { data: { id: 'g-1', name: 'Nông hộ', is_active: true } } },
      }),
    )
    renderApp('/customers/groups')
    const user = userEvent.setup()

    expect(await screen.findByRole('heading', { level: 1, name: 'Nhóm khách hàng' })).toBeInTheDocument()
    expect(await screen.findByText('Đại lý')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Thêm nhóm khách' }))
    const add = await screen.findByRole('dialog', { name: 'Thêm nhóm khách' })
    await user.type(within(add).getByLabelText('Tên nhóm khách'), 'Khách quen')
    await user.click(within(add).getByRole('button', { name: 'Lưu' }))
    await waitFor(() => expect(server.to('POST', '/api/v1/customer-groups')[0]?.body).toEqual({ name: 'Khách quen' }))

    await user.click(screen.getByRole('button', { name: 'Sửa Nông dân' }))
    const edit = await screen.findByRole('dialog', { name: 'Sửa nhóm khách' })
    const name = within(edit).getByLabelText('Tên nhóm khách')
    await user.clear(name)
    await user.type(name, 'Nông hộ')
    await user.click(within(edit).getByRole('button', { name: 'Lưu' }))
    await waitFor(() => expect(server.to('PATCH', '/api/v1/customer-groups/g-1')[0]?.body).toEqual({ name: 'Nông hộ', is_active: true }))
  })

  it('nhân viên bán hàng chỉ thêm được nhóm mới, không sửa hay xóa', async () => {
    mockApi(base({}, SALES))
    renderApp('/customers/groups')

    expect(await screen.findByRole('button', { name: 'Thêm nhóm khách' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sửa Nông dân' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Xóa Nông dân' })).not.toBeInTheDocument()
  })

  it('không xóa được nhóm đang có khách: báo lý do', async () => {
    mockApi(base({ 'DELETE /api/v1/customer-groups/g-1': problem(409, 'catalog_item_in_use', 'Mục này đang được sử dụng.') }))
    renderApp('/customers/groups')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Xóa Nông dân' }))
    const dialog = await screen.findByRole('dialog', { name: 'Xóa mục' })
    await user.click(within(dialog).getByRole('button', { name: 'Xóa' }))

    expect(await within(dialog).findByText(/Đang có khách hàng thuộc nhóm này/)).toBeInTheDocument()
  })
})

describe('nhà cung cấp', () => {
  it('hiện danh sách với mã số thuế, hạn thanh toán và trạng thái', async () => {
    mockApi(base())
    renderApp('/suppliers')

    const table = await screen.findByRole('table')
    expect(within(table).getByText('Công ty Phân bón Miền Nam')).toBeInTheDocument()
    expect(within(table).getByText('NCC0001')).toBeInTheDocument()
    expect(within(table).getByText('MST: 0300000101')).toBeInTheDocument()
    expect(within(table).getByText('30 ngày')).toBeInTheDocument()
    expect(within(table).getByText('Đang nhập hàng')).toBeInTheDocument()
    expect(within(table).getByText('Ngừng nhập hàng')).toBeInTheDocument()
  })

  it('tìm kiếm và lọc gửi đúng tham số; người chỉ có quyền xem không thấy nút thêm hay xóa', async () => {
    const server = mockApi(base({}, VIEW_ONLY))
    renderApp('/suppliers')
    const user = userEvent.setup()

    const table = await screen.findByRole('table')
    expect(screen.queryByRole('link', { name: 'Thêm nhà cung cấp' })).not.toBeInTheDocument()
    expect(within(table).queryByRole('button', { name: /^Xóa/ })).not.toBeInTheDocument()
    expect(within(table).getByRole('link', { name: 'Xem Bayer Việt Nam' })).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Trạng thái'), 'inactive')
    await user.type(screen.getByLabelText('Tìm kiếm'), 'bayer')
    await waitFor(() => {
      const last = server.calls.filter((c) => c.method === 'GET' && c.path.startsWith('/api/v1/suppliers?')).at(-1)?.path ?? ''
      expect(last).toContain('status=inactive')
      expect(last).toContain('q=bayer')
    })
  })

  it('xóa nhà cung cấp: hỏi xác nhận rồi gọi API', async () => {
    const server = mockApi(base({ 'DELETE /api/v1/suppliers/s2': { status: 204 } }))
    renderApp('/suppliers')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Xóa Bayer Việt Nam' }))
    const dialog = await screen.findByRole('dialog', { name: 'Xóa nhà cung cấp' })
    await user.click(within(dialog).getByRole('button', { name: 'Xóa' }))

    await waitFor(() => expect(server.to('DELETE', '/api/v1/suppliers/s2')).toHaveLength(1))
  })

  it('thêm nhà cung cấp: kiểm tra ô còn thiếu, rồi gửi đúng nội dung', async () => {
    const server = mockApi(base({ 'POST /api/v1/suppliers': { status: 201, json: { data: supplier({ id: 's9', name: 'Syngenta' }) } } }))
    renderApp('/notifications?dialog=supplier')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Lưu' }))
    expect(await screen.findByText('Vui lòng nhập tên nhà cung cấp.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/suppliers')).toHaveLength(0)

    await user.type(screen.getByLabelText('Tên nhà cung cấp'), 'Syngenta')
    await user.type(screen.getByLabelText('Mã số thuế'), '0300000103')
    await user.type(screen.getByLabelText('Số ngày thanh toán'), '45')
    await user.click(screen.getByRole('button', { name: 'Thêm người liên hệ' }))
    const contact = screen.getByRole('group', { name: 'Người liên hệ 1' })
    await user.type(within(contact).getByLabelText('Họ tên'), 'Chị Mai')
    await user.type(within(contact).getByLabelText('Email'), 'mai@syngenta.vn')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/suppliers')).toHaveLength(1))
    const body = server.to('POST', '/api/v1/suppliers')[0]?.body as Record<string, unknown>
    expect(body).not.toHaveProperty('code')
    expect(body).toMatchObject({
      name: 'Syngenta',
      tax_code: '0300000103',
      phone: null,
      payment_terms_days: 45,
      status: 'active',
      contacts: [{ name: 'Chị Mai', phone: null, email: 'mai@syngenta.vn', position: null }],
    })
    expect(await screen.findByText('Đã thêm nhà cung cấp "Syngenta".')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument()) // lưu xong popup tự đóng, không chuyển trang
  })

  it('lỗi từ máy chủ hiện đúng ô, kể cả email của người liên hệ theo dòng', async () => {
    mockApi(
      base({
        'POST /api/v1/suppliers': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', {
          errors: { tax_code: ['Mã số thuế này đã có ở nhà cung cấp khác.'], 'contacts.0.email': ['Email người liên hệ không hợp lệ.'] },
        }),
      }),
    )
    renderApp('/notifications?dialog=supplier')
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Tên nhà cung cấp'), 'Trùng MST')
    await user.click(screen.getByRole('button', { name: 'Thêm người liên hệ' }))
    await user.type(within(screen.getByRole('group', { name: 'Người liên hệ 1' })).getByLabelText('Họ tên'), 'Anh D')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    expect(await screen.findByText('Mã số thuế này đã có ở nhà cung cấp khác.')).toBeInTheDocument()
    expect(within(screen.getByRole('group', { name: 'Người liên hệ 1' })).getByText('Email người liên hệ không hợp lệ.')).toBeInTheDocument()
  })

  it('sửa nhà cung cấp: nạp sẵn giá trị, mã chỉ đọc, lưu bằng PATCH không gửi mã', async () => {
    const server = mockApi(base({ 'GET /api/v1/suppliers/s1': { json: { data: supplier() } }, 'PATCH /api/v1/suppliers/s1': { json: { data: supplier({ payment_terms_days: 60 }) } } }))
    renderApp('/notifications?dialog=supplier:s1')
    const user = userEvent.setup()

    const editor = await screen.findByRole('dialog', { name: 'Sửa nhà cung cấp' })

    await waitFor(() => expect(editor).toHaveAccessibleDescription(/Công ty Phân bón Miền Nam/)) // tên bản ghi nằm ở dòng mô tả của popup
    expect(screen.getByLabelText('Mã số thuế')).toHaveValue('0300000101')
    expect(screen.getByLabelText('Số ngày thanh toán')).toHaveValue('30')
    expect(within(screen.getByRole('group', { name: 'Người liên hệ 1' })).getByLabelText('Email')).toHaveValue('hung@mau.vn')
    expect(screen.queryByLabelText('Mã')).not.toBeInTheDocument()

    const days = screen.getByLabelText('Số ngày thanh toán')
    await user.clear(days)
    await user.type(days, '60')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/suppliers/s1')).toHaveLength(1))
    const body = server.to('PATCH', '/api/v1/suppliers/s1')[0]?.body as Record<string, unknown>
    expect(body).not.toHaveProperty('code')
    expect(body).toMatchObject({ payment_terms_days: 60, name: 'Công ty Phân bón Miền Nam' })
  })

  it('người chỉ có quyền xem: form bị khóa, không có nút Lưu; không vào được trang thêm', async () => {
    mockApi(base({ 'GET /api/v1/suppliers/s1': { json: { data: supplier() } } }, VIEW_ONLY))
    renderApp('/notifications?dialog=supplier:s1')

    expect(await screen.findByText('Bạn chỉ có quyền xem nhà cung cấp này.')).toBeInTheDocument()
    expect(screen.getByLabelText('Tên nhà cung cấp')).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Lưu' })).not.toBeInTheDocument()
  })

  it('người không có quyền tạo không vào được trang thêm nhà cung cấp', async () => {
    mockApi(base({}, VIEW_ONLY))
    renderApp('/notifications?dialog=supplier')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
  })
})
