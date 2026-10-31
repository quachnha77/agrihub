import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { ListItem, Product, TypeDefinition } from './api.ts'

type Permissions = Record<string, 'own' | 'branch' | 'tenant'>

const FULL: Permissions = {
  'catalog.product.view': 'tenant', 'catalog.product.create': 'tenant', 'catalog.product.update': 'tenant', 'catalog.product.delete': 'tenant', 'tenant.branch.view': 'tenant',
}
// Quản lý chi nhánh: xem/thêm/sửa trong chi nhánh được gán, không xóa, không có quyền cấp doanh nghiệp.
const MANAGER: Permissions = { 'catalog.product.view': 'branch', 'catalog.product.create': 'branch', 'catalog.product.update': 'branch', 'tenant.branch.view': 'branch' }
const VIEW_ONLY: Permissions = { 'catalog.product.view': 'branch', 'tenant.branch.view': 'branch' }

const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 } })

const TYPES: TypeDefinition[] = [
  {
    key: 'fertilizer', label: 'Phân bón', track_batch_required: false,
    fields: [
      { key: 'composition', label: 'Thành phần / hàm lượng', type: 'text', required: false, max: 255 },
      { key: 'origin', label: 'Xuất xứ', type: 'text', required: false, max: 100 },
    ],
  },
  {
    key: 'pesticide', label: 'Thuốc bảo vệ thực vật', track_batch_required: true,
    fields: [
      { key: 'registration_no', label: 'Số đăng ký lưu hành', type: 'text', required: true, max: 100 },
      { key: 'active_ingredient', label: 'Hoạt chất', type: 'text', required: true, max: 255 },
      { key: 'toxicity_group', label: 'Nhóm độc', type: 'select', required: false, options: ['I', 'II', 'III', 'IV'] },
      { key: 'phi_days', label: 'Thời gian cách ly (ngày)', type: 'integer', required: false, min: 0, maxValue: 365 },
    ],
  },
  { key: 'seed', label: 'Hạt giống', track_batch_required: true, fields: [{ key: 'variety', label: 'Tên giống', type: 'text', required: true, max: 255 }] },
  { key: 'other', label: 'Vật tư khác', track_batch_required: false, fields: [] },
]

const UNITS: ListItem[] = ['Kg', 'Bao', 'Chai', 'Thùng'].map((name, i) => ({ id: `u-${i}`, name, is_active: true }))
const unit = (name: string) => UNITS.find((u) => u.name === name)!.id
const CATEGORIES: ListItem[] = [{ id: 'c-1', name: 'Phân bón', is_active: true, sort_order: 1 }]
const BRANDS: ListItem[] = [{ id: 'b-1', name: 'Đầu Trâu', is_active: true }]
const BRANCHES = [
  { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính', phone: null, address: null, status: 'active' },
  { id: 'br-2', code: 'HN-02', name: 'Chi nhánh Hà Nội', phone: null, address: null, status: 'active' },
]

const product = (overrides: Partial<Product> = {}): Product => ({
  id: 'p1',
  branch: { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính' },
  type: 'fertilizer',
  type_label: 'Phân bón',
  sku: 'NPK-16',
  name: 'Phân NPK 16-16-8',
  category: { id: 'c-1', name: 'Phân bón' },
  brand: { id: 'b-1', name: 'Đầu Trâu' },
  description: null,
  status: 'active',
  track_batch: false,
  specs: { composition: 'N16 P16 K8' },
  base_unit: { id: unit('Kg'), name: 'Kg' },
  units: [
    { unit: { id: unit('Kg'), name: 'Kg' }, is_base: true, factor: '1', price_cash: '15500.00', price_credit: '16000.00', barcode: null },
    { unit: { id: unit('Bao'), name: 'Bao' }, is_base: false, factor: '50', price_cash: '750000.00', price_credit: '780000.00', barcode: '8930000000012' },
  ],
  created_at: '2026-10-31T00:00:00Z',
  updated_at: '2026-10-31T00:00:00Z',
  ...overrides,
})

const URE = product({ id: 'p2', sku: 'URE-01', name: 'Phân Urê', units: [{ ...product().units[0], price_cash: '13000.00', price_credit: '13500.00' }], brand: null })
const PESTICIDE = product({ id: 'p3', type: 'pesticide', type_label: 'Thuốc bảo vệ thực vật', sku: 'TS-01', name: 'Thuốc trừ sâu 25WG', track_batch: true, specs: { registration_no: 'DEMO-1', active_ingredient: 'X' }, units: [{ ...product().units[0], price_cash: '95000.00', price_credit: '100000.00' }] })

const asUser = (permissions: Permissions) => meOk(tenantUser({ permissions }))

const base = (extra: Record<string, unknown> = {}, permissions: Permissions = FULL) =>
  ({
    'GET /api/v1/auth/me': asUser(permissions),
    'GET /api/v1/products/types': { json: { data: TYPES, meta: { max_units: 8 } } },
    'GET /api/v1/branches': { json: page(BRANCHES) },
    'GET /api/v1/units': { json: page(UNITS) },
    'GET /api/v1/categories': { json: page(CATEGORIES) },
    'GET /api/v1/brands': { json: page(BRANDS) },
    'GET /api/v1/products': { json: page([product(), URE, PESTICIDE]) },
    ...extra,
  }) as never

const labelled = (name: string | RegExp) => screen.findByLabelText(name)

describe('danh sách sản phẩm', () => {
  it('hiện sản phẩm với loại hàng, giá tiền mặt/công nợ của đơn vị cơ sở, số đơn vị khác và cờ theo dõi lô', async () => {
    mockApi(base())
    renderApp('/products')

    const table = await screen.findByRole('table')
    expect(within(table).getByText('Phân NPK 16-16-8')).toBeInTheDocument()
    expect(within(table).getByText('NPK-16')).toBeInTheDocument()
    expect(within(table).getAllByText('Phân bón').length).toBeGreaterThan(0)
    expect(within(table).getByText('15.500 ₫')).toBeInTheDocument()
    expect(within(table).getByText('16.000 ₫')).toBeInTheDocument()
    expect(within(table).getByText('+1 đơn vị khác')).toBeInTheDocument()
    expect(within(table).getAllByText('Theo dõi lô/HSD')).toHaveLength(1) // chỉ thuốc BVTV
    expect(within(table).getAllByText('Đang kinh doanh').length).toBe(3)
  })

  it('có mục "Sản phẩm" trong menu khi có quyền xem', async () => {
    mockApi(base())
    renderApp('/products')

    expect(within(await screen.findByRole('navigation', { name: 'Menu chính' })).getByRole('link', { name: 'Sản phẩm' })).toBeInTheDocument()
  })

  it('không có mục "Sản phẩm" trong menu khi không có quyền xem, và gõ thẳng đường dẫn thì bị chặn', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': asUser({ 'tenant.branch.view': 'tenant' }) })
    renderApp('/products')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Menu chính' })).not.toHaveTextContent('Sản phẩm')
    expect(server.calls.filter((call) => call.path.startsWith('/api/v1/products'))).toHaveLength(0)
  })

  it('tìm kiếm và lọc gửi đúng tham số lên máy chủ', async () => {
    const server = mockApi(base())
    renderApp('/products')
    const user = userEvent.setup()

    await screen.findByRole('table')
    await user.selectOptions(screen.getByLabelText('Loại hàng'), 'pesticide')
    await user.selectOptions(screen.getByLabelText('Nhóm hàng'), 'c-1')
    await user.selectOptions(screen.getByLabelText('Chi nhánh'), 'br-2')
    await user.type(screen.getByLabelText('Tìm kiếm'), 'urê')

    await waitFor(() => {
      const last = server.calls.filter((c) => c.method === 'GET' && c.path.startsWith('/api/v1/products?')).at(-1)?.path ?? ''
      expect(last).toContain('type=pesticide')
      expect(last).toContain('category=c-1')
      expect(last).toContain('branch=br-2')
      expect(decodeURIComponent(last)).toContain('q=urê')
    })
  })

  it('người chỉ có quyền xem: không có nút thêm, ô chọn hay nút xóa; liên kết là "Xem"', async () => {
    mockApi(base({}, VIEW_ONLY))
    renderApp('/products')

    const table = await screen.findByRole('table')
    expect(screen.queryByRole('link', { name: 'Thêm sản phẩm' })).not.toBeInTheDocument()
    expect(within(table).queryByRole('checkbox')).not.toBeInTheDocument()
    expect(within(table).queryByRole('button', { name: /^Xóa/ })).not.toBeInTheDocument()
    expect(within(table).getByRole('link', { name: 'Xem Phân NPK 16-16-8' })).toBeInTheDocument()
  })

  it('xóa sản phẩm: hỏi xác nhận, gọi API rồi lấy lại danh sách', async () => {
    let deleted = false
    const server = mockApi(
      base({
        'GET /api/v1/products': () => ({ json: page(deleted ? [URE, PESTICIDE] : [product(), URE, PESTICIDE]) }),
        'DELETE /api/v1/products/p1': () => {
          deleted = true

          return { status: 204 }
        },
      }),
    )
    renderApp('/products')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Xóa Phân NPK 16-16-8' }))
    const dialog = await screen.findByRole('dialog', { name: 'Xóa sản phẩm' })
    expect(within(dialog).getByText(/NPK-16/)).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Xóa' }))

    await waitFor(() => expect(server.to('DELETE', '/api/v1/products/p1')).toHaveLength(1))
    await waitFor(() => expect(screen.queryByText('Phân NPK 16-16-8')).not.toBeInTheDocument())
  })

  it('sao chép sang chi nhánh khác: gửi mã sản phẩm đã chọn và báo mã bị bỏ qua', async () => {
    const server = mockApi(
      base({
        'POST /api/v1/products/copy': { json: { data: [product({ id: 'p9', branch: { id: 'br-2', code: 'HN-02', name: 'Chi nhánh Hà Nội' } })], meta: { copied: 1, skipped: [{ sku: 'URE-01', name: 'Phân Urê', reason: 'sku_exists' }] } } },
      }),
    )
    renderApp('/products')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('checkbox', { name: 'Chọn Phân NPK 16-16-8' }))
    await user.click(screen.getByRole('checkbox', { name: 'Chọn Phân Urê' }))
    expect(screen.getByText('Đã chọn 2 sản phẩm')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Sao chép sang chi nhánh khác' }))
    const dialog = await screen.findByRole('dialog', { name: 'Sao chép sản phẩm' })
    // Chi nhánh nguồn (cả hai sản phẩm cùng ở CN-01) không có trong danh sách đích.
    expect(within(dialog).queryByRole('option', { name: /CN-01/ })).not.toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Sao chép' }))
    expect(await within(dialog).findByText('Vui lòng chọn chi nhánh đích.')).toBeInTheDocument()

    await user.selectOptions(within(dialog).getByLabelText('Chi nhánh đích'), 'br-2')
    await user.click(within(dialog).getByRole('button', { name: 'Sao chép' }))

    expect(await within(dialog).findByText('Đã sao chép 1 sản phẩm; bỏ qua 1 sản phẩm.')).toBeInTheDocument()
    expect(within(dialog).getByText('URE-01')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/products/copy')[0]?.body).toEqual({ product_ids: ['p1', 'p2'], branch_id: 'br-2' })
  })
})

describe('thêm sản phẩm', () => {
  it('chọn loại hàng thì hiện đúng trường đặc thù; thuốc BVTV bắt buộc số đăng ký và theo dõi lô', async () => {
    mockApi(base())
    renderApp('/products/new')
    const user = userEvent.setup()

    await user.selectOptions(await labelled('Loại hàng'), 'fertilizer')
    expect(await screen.findByText('Thông tin đặc thù — Phân bón')).toBeInTheDocument()
    expect(screen.getByLabelText('Thành phần / hàm lượng')).toBeInTheDocument()
    expect(screen.queryByLabelText(/Số đăng ký lưu hành/)).not.toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: /Theo dõi lô và hạn dùng/ })).not.toBeChecked()

    await user.selectOptions(screen.getByLabelText('Loại hàng'), 'pesticide')
    expect(await screen.findByText('Thông tin đặc thù — Thuốc bảo vệ thực vật')).toBeInTheDocument()
    expect(screen.getByLabelText('Số đăng ký lưu hành *')).toBeInTheDocument()
    expect(screen.getByLabelText('Hoạt chất *')).toBeInTheDocument()
    expect(screen.getByLabelText('Nhóm độc')).toBeInTheDocument()
    expect(screen.queryByLabelText('Thành phần / hàm lượng')).not.toBeInTheDocument()
    const batch = screen.getByRole('checkbox', { name: /Theo dõi lô và hạn dùng/ })
    expect(batch).toBeChecked()
    expect(batch).toBeDisabled()

    await user.selectOptions(screen.getByLabelText('Loại hàng'), 'other')
    expect(screen.queryByText(/Thông tin đặc thù —/)).not.toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: /Theo dõi lô và hạn dùng/ })).toBeEnabled()
  })

  it('kiểm tra ngay trên trình duyệt: báo từng ô còn thiếu và không gọi API', async () => {
    const server = mockApi(base())
    renderApp('/products/new')
    const user = userEvent.setup()

    await user.selectOptions(await labelled('Loại hàng'), 'pesticide')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    expect(await screen.findByText('Vui lòng chọn chi nhánh.')).toBeInTheDocument()
    expect(screen.getByText('Vui lòng nhập mã sản phẩm.')).toBeInTheDocument()
    expect(screen.getByText('Vui lòng nhập tên sản phẩm.')).toBeInTheDocument()
    expect(screen.getByText('Vui lòng chọn đơn vị cơ sở.')).toBeInTheDocument()
    expect(screen.getByText('Vui lòng nhập số đăng ký lưu hành.')).toBeInTheDocument()
    expect(screen.getByText('Vui lòng nhập hoạt chất.')).toBeInTheDocument()
    expect(screen.getAllByText('Nhập số tiền hợp lệ (từ 0, tối đa 2 chữ số thập phân).').length).toBe(2)
    expect(server.to('POST', '/api/v1/products')).toHaveLength(0)
  })

  async function fillPesticide(user: ReturnType<typeof userEvent.setup>) {
    await user.selectOptions(await labelled('Chi nhánh'), 'br-1')
    await user.selectOptions(screen.getByLabelText('Loại hàng'), 'pesticide')
    await user.type(screen.getByLabelText('Mã sản phẩm'), 'TS-NEW')
    await user.type(screen.getByLabelText('Tên sản phẩm'), 'Thuốc trừ nấm 250EC')
    await user.selectOptions(screen.getByLabelText('Nhóm hàng'), 'c-1')
    await user.type(await screen.findByLabelText('Số đăng ký lưu hành *'), '1234/CP-BVTV')
    await user.type(screen.getByLabelText('Hoạt chất *'), 'Difenoconazole')
    await user.selectOptions(screen.getByLabelText('Nhóm độc'), 'III')
    await user.type(screen.getByLabelText('Thời gian cách ly (ngày)'), '7')
    await user.selectOptions(screen.getByLabelText('Đơn vị cơ sở'), unit('Chai'))
    await user.type(screen.getByLabelText('Giá tiền mặt của Chai'), '95.000')
    await user.type(screen.getByLabelText('Giá công nợ của Chai'), '100000')
  }

  it('gửi đúng nội dung: số tiền theo kiểu Việt Nam, đơn vị quy đổi, thông tin đặc thù, theo dõi lô; rồi về danh sách kèm thông báo', async () => {
    const created = product({ id: 'p9', name: 'Thuốc trừ nấm 250EC', sku: 'TS-NEW' })
    const server = mockApi(base({ 'POST /api/v1/products': { status: 201, json: { data: created } } }))
    renderApp('/products/new')
    const user = userEvent.setup()

    await fillPesticide(user)
    await user.click(screen.getByRole('button', { name: 'Thêm đơn vị quy đổi' }))
    await user.selectOptions(screen.getByLabelText('Đơn vị quy đổi 2'), unit('Thùng'))
    await user.type(screen.getByLabelText('Hệ số quy đổi của Thùng'), '12')
    await user.type(screen.getByLabelText('Giá tiền mặt của Thùng'), '1.100.000')
    await user.type(screen.getByLabelText('Giá công nợ của Thùng'), '1150000')
    await user.type(screen.getByLabelText('Mã vạch của Thùng'), '893001')
    // Hiện lại số tiền đã gõ theo định dạng chuẩn để tránh nhầm dấu chấm/phẩy.
    expect(screen.getByText('1.100.000 ₫')).toBeInTheDocument()
    expect(screen.getByText('1 Thùng = 12 Chai')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/products')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/products')[0]?.body).toEqual({
      branch_id: 'br-1',
      type: 'pesticide',
      base_unit_id: unit('Chai'),
      sku: 'TS-NEW',
      name: 'Thuốc trừ nấm 250EC',
      description: null,
      status: 'active',
      track_batch: true,
      category_id: 'c-1',
      brand_id: null,
      specs: { registration_no: '1234/CP-BVTV', active_ingredient: 'Difenoconazole', toxicity_group: 'III', phi_days: 7 },
      units: [
        { unit_id: unit('Chai'), factor: 1, price_cash: 95000, price_credit: 100000, barcode: null },
        { unit_id: unit('Thùng'), factor: 12, price_cash: 1100000, price_credit: 1150000, barcode: '893001' },
      ],
    })

    expect(await screen.findByText('Đã thêm sản phẩm "Thuốc trừ nấm 250EC".')).toBeInTheDocument()
  })

  it('lỗi từ máy chủ hiện đúng ô: mã trùng, giá, mã vạch của đơn vị quy đổi, trường đặc thù; lỗi chung hiện ở đầu form', async () => {
    mockApi(
      base({
        'POST /api/v1/products': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', {
          errors: {
            sku: ['Mã sản phẩm này đã được dùng trong chi nhánh.'],
            'specs.registration_no': ['Số đăng ký lưu hành là bắt buộc.'],
            'units.0.price_cash': ['Giá tiền mặt không được âm.'],
            'units.1.barcode': ['Mã vạch này đã được dùng cho sản phẩm khác trong chi nhánh.'],
            units: ['Danh sách đơn vị phải có đúng một dòng là đơn vị cơ sở của sản phẩm.'],
          },
        }),
      }),
    )
    renderApp('/products/new')
    const user = userEvent.setup()

    await fillPesticide(user)
    await user.click(screen.getByRole('button', { name: 'Thêm đơn vị quy đổi' }))
    await user.selectOptions(screen.getByLabelText('Đơn vị quy đổi 2'), unit('Thùng'))
    await user.type(screen.getByLabelText('Hệ số quy đổi của Thùng'), '12')
    await user.type(screen.getByLabelText('Giá tiền mặt của Thùng'), '1100000')
    await user.type(screen.getByLabelText('Giá công nợ của Thùng'), '1150000')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    expect(await screen.findByText('Mã sản phẩm này đã được dùng trong chi nhánh.')).toBeInTheDocument()
    expect(screen.getByText('Số đăng ký lưu hành là bắt buộc.')).toBeInTheDocument()
    expect(screen.getByText('Giá tiền mặt không được âm.')).toBeInTheDocument()
    expect(screen.getByText('Mã vạch này đã được dùng cho sản phẩm khác trong chi nhánh.')).toBeInTheDocument()
    expect(screen.getByText('Danh sách đơn vị phải có đúng một dòng là đơn vị cơ sở của sản phẩm.')).toBeInTheDocument()
  })

  it('không cho chọn trùng đơn vị và báo khi hệ số không hợp lệ', async () => {
    const server = mockApi(base())
    renderApp('/products/new')
    const user = userEvent.setup()

    await fillPesticide(user)
    await user.click(screen.getByRole('button', { name: 'Thêm đơn vị quy đổi' }))
    const row = screen.getByLabelText('Đơn vị quy đổi 2')
    // Đơn vị cơ sở (Chai) không còn trong danh sách chọn của dòng quy đổi.
    expect(within(row).queryByRole('option', { name: 'Chai' })).not.toBeInTheDocument()
    await user.selectOptions(row, unit('Thùng'))
    await user.type(screen.getByLabelText('Hệ số quy đổi của Thùng'), '0')
    await user.type(screen.getByLabelText('Giá tiền mặt của Thùng'), '1')
    await user.type(screen.getByLabelText('Giá công nợ của Thùng'), '1')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    expect(await screen.findByText('Nhập số lớn hơn 0 (tối đa 6 chữ số thập phân).')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/products')).toHaveLength(0)
  })

  it('chỉ có một chi nhánh được dùng thì chọn sẵn', async () => {
    mockApi(base({ 'GET /api/v1/branches': { json: page([BRANCHES[0]]) } }))
    renderApp('/products/new')

    await waitFor(() => expect(screen.getByLabelText('Chi nhánh')).toHaveValue('br-1'))
  })

  it('người không có quyền tạo không vào được trang thêm', async () => {
    mockApi(base({}, VIEW_ONLY))
    renderApp('/products/new')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
  })
})

describe('sửa sản phẩm', () => {
  const detail = (p: Product = product()) => ({ [`GET /api/v1/products/${p.id}`]: { json: { data: p } } })

  it('hiện sẵn giá trị đang có; loại hàng, chi nhánh, đơn vị cơ sở chỉ đọc', async () => {
    mockApi(base(detail()))
    renderApp('/products/p1')

    expect(await screen.findByRole('heading', { name: /Sửa sản phẩm: Phân NPK 16-16-8/ })).toBeInTheDocument()
    expect(screen.getByLabelText('Mã sản phẩm')).toHaveValue('NPK-16')
    expect(screen.getByLabelText('Nhóm hàng')).toHaveValue('c-1')
    expect(screen.getByLabelText('Thương hiệu')).toHaveValue('b-1')
    expect(screen.getByLabelText('Thành phần / hàm lượng')).toHaveValue('N16 P16 K8')
    expect(screen.getByLabelText('Giá tiền mặt của Kg')).toHaveValue('15500')
    expect(screen.getByLabelText('Giá công nợ của Bao')).toHaveValue('780000')
    expect(screen.getByLabelText('Hệ số quy đổi của Bao')).toHaveValue('50')
    expect(screen.getByLabelText('Đơn vị quy đổi 2')).toHaveValue(unit('Bao'))
    expect(screen.getByLabelText('Mã vạch của Bao')).toHaveValue('8930000000012')
    expect(screen.getByText('CN-01 — Chi nhánh chính')).toBeInTheDocument()
    expect(screen.queryByLabelText('Chi nhánh')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Loại hàng')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Đơn vị cơ sở')).not.toBeInTheDocument()
  })

  it('lưu bằng PATCH và không gửi loại hàng, chi nhánh, đơn vị cơ sở', async () => {
    const server = mockApi(base({ ...detail(), 'PATCH /api/v1/products/p1': { json: { data: product({ name: 'NPK mới' }) } } }))
    renderApp('/products/p1')
    const user = userEvent.setup()

    const name = await screen.findByLabelText('Tên sản phẩm')
    await user.clear(name)
    await user.type(name, 'NPK mới')
    const price = screen.getByLabelText('Giá tiền mặt của Kg')
    await user.clear(price)
    await user.type(price, '16.500')
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/products/p1')).toHaveLength(1))
    const body = server.to('PATCH', '/api/v1/products/p1')[0]?.body as Record<string, unknown>
    expect(body).not.toHaveProperty('type')
    expect(body).not.toHaveProperty('branch_id')
    expect(body).not.toHaveProperty('base_unit_id')
    expect(body).toMatchObject({ name: 'NPK mới', category_id: 'c-1', brand_id: 'b-1', specs: { composition: 'N16 P16 K8' } })
    expect((body.units as { price_cash: number }[])[0].price_cash).toBe(16500)
    expect(await screen.findByText('Đã lưu thay đổi của sản phẩm "NPK mới".')).toBeInTheDocument()
  })

  it('bỏ một đơn vị quy đổi thì không gửi dòng đó nữa', async () => {
    const server = mockApi(base({ ...detail(), 'PATCH /api/v1/products/p1': { json: { data: product() } } }))
    renderApp('/products/p1')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Bỏ đơn vị Bao' }))
    await user.click(screen.getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/products/p1')).toHaveLength(1))
    expect((server.to('PATCH', '/api/v1/products/p1')[0]?.body as { units?: unknown[] } | undefined)?.units).toHaveLength(1)
  })

  it('người chỉ có quyền xem: form bị khóa, không có nút Lưu', async () => {
    mockApi(base(detail(), VIEW_ONLY))
    renderApp('/products/p1')

    expect(await screen.findByText('Bạn chỉ có quyền xem sản phẩm này.')).toBeInTheDocument()
    expect(screen.getByLabelText('Mã sản phẩm')).toBeDisabled()
    expect(screen.getByLabelText('Giá tiền mặt của Kg')).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Lưu' })).not.toBeInTheDocument()
  })

  it('sản phẩm không tồn tại hoặc ngoài phạm vi: báo không tìm thấy', async () => {
    mockApi(base({ 'GET /api/v1/products/zzz': problem(404, 'not_found', 'Không tìm thấy.') }))
    renderApp('/products/zzz')

    expect(await screen.findByText('Không tìm thấy sản phẩm này hoặc bạn không có quyền xem.')).toBeInTheDocument()
  })
})

describe('đơn vị tính, nhóm hàng, thương hiệu', () => {
  it('hiện ba danh sách; chủ doanh nghiệp thêm, đổi tên được', async () => {
    const server = mockApi(
      base({
        'POST /api/v1/units': { status: 201, json: { data: { id: 'u-new', name: 'Lốc', is_active: true } } },
        'PATCH /api/v1/brands/b-1': { json: { data: { id: 'b-1', name: 'Đầu Trâu Việt', is_active: true } } },
      }),
    )
    renderApp('/products/lists')
    const user = userEvent.setup()

    expect(await screen.findByRole('heading', { name: 'Đơn vị tính, nhóm hàng, thương hiệu' })).toBeInTheDocument()
    expect(await screen.findByText('Bao')).toBeInTheDocument()
    expect(screen.getByText('Phân bón')).toBeInTheDocument()
    expect(screen.getByText('Đầu Trâu')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Thêm đơn vị tính' }))
    const add = await screen.findByRole('dialog', { name: 'Thêm đơn vị tính' })
    await user.click(within(add).getByRole('button', { name: 'Lưu' }))
    expect(await within(add).findByText('Vui lòng nhập tên.')).toBeInTheDocument()
    await user.type(within(add).getByLabelText('Tên đơn vị'), 'Lốc')
    await user.click(within(add).getByRole('button', { name: 'Lưu' }))
    await waitFor(() => expect(server.to('POST', '/api/v1/units')[0]?.body).toEqual({ name: 'Lốc' }))

    await user.click(screen.getByRole('button', { name: 'Sửa Đầu Trâu' }))
    const edit = await screen.findByRole('dialog', { name: 'Sửa thương hiệu' })
    const name = within(edit).getByLabelText('Tên thương hiệu')
    await user.clear(name)
    await user.type(name, 'Đầu Trâu Việt')
    await user.click(within(edit).getByRole('button', { name: 'Lưu' }))
    await waitFor(() => expect(server.to('PATCH', '/api/v1/brands/b-1')[0]?.body).toEqual({ name: 'Đầu Trâu Việt', is_active: true }))
  })

  it('nhóm hàng có thêm thứ tự hiển thị', async () => {
    const server = mockApi(base({ 'PATCH /api/v1/categories/c-1': { json: { data: CATEGORIES[0] } } }))
    renderApp('/products/lists')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Sửa Phân bón' }))
    const dialog = await screen.findByRole('dialog', { name: 'Sửa nhóm hàng' })
    const order = within(dialog).getByLabelText('Thứ tự hiển thị')
    expect(order).toHaveValue('1')
    await user.clear(order)
    await user.type(order, '5')
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/categories/c-1')[0]?.body).toEqual({ name: 'Phân bón', sort_order: 5, is_active: true }))
  })

  it('xóa mục đang được sản phẩm dùng: báo rõ và hướng dẫn chuyển sang "ngừng sử dụng"', async () => {
    mockApi(base({ 'DELETE /api/v1/brands/b-1': problem(409, 'catalog_item_in_use', 'Thương hiệu đang được sản phẩm sử dụng.') }))
    renderApp('/products/lists')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Xóa Đầu Trâu' }))
    const dialog = await screen.findByRole('dialog', { name: 'Xóa mục' })
    await user.click(within(dialog).getByRole('button', { name: 'Xóa' }))

    expect(await within(dialog).findByText(/đang được sản phẩm sử dụng nên không xóa được/)).toBeInTheDocument()
  })

  it('quản lý chi nhánh chỉ được thêm: không có nút sửa hay xóa', async () => {
    mockApi(base({}, MANAGER))
    renderApp('/products/lists')

    expect(await screen.findByRole('button', { name: 'Thêm đơn vị tính' })).toBeInTheDocument()
    await screen.findByText('Bao')
    expect(screen.queryByRole('button', { name: /^Sửa / })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Xóa / })).not.toBeInTheDocument()
  })

  it('mục đã ngừng sử dụng được đánh dấu và có thể bật lại khi sửa', async () => {
    const retired = [{ id: 'b-2', name: 'Hãng cũ', is_active: false }]
    const server = mockApi(base({ 'GET /api/v1/brands': { json: page([...BRANDS, ...retired]) }, 'PATCH /api/v1/brands/b-2': { json: { data: { ...retired[0], is_active: true } } } }))
    renderApp('/products/lists')
    const user = userEvent.setup()

    expect(await screen.findByText('Ngừng sử dụng')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Sửa Hãng cũ' }))
    const dialog = await screen.findByRole('dialog', { name: 'Sửa thương hiệu' })
    await user.click(within(dialog).getByRole('checkbox', { name: /Đang sử dụng/ }))
    await user.click(within(dialog).getByRole('button', { name: 'Lưu' }))

    await waitFor(() => expect(server.to('PATCH', '/api/v1/brands/b-2')[0]?.body).toEqual({ name: 'Hãng cũ', is_active: true }))
  })
})
