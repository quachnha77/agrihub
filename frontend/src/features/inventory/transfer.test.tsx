import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { Product } from '../catalog/api.ts'
import type { StockBalance, StockDocument, StockTransfer, Warehouse } from './api.ts'

type Permissions = Record<string, 'own' | 'branch' | 'tenant'>

// Chủ cửa hàng / thủ kho cấp doanh nghiệp: xem và lập chuyển kho, thấy giá vốn.
const KEEPER: Permissions = { 'inventory.movement.view': 'tenant', 'inventory.movement.create': 'tenant', 'inventory.warehouse.view': 'tenant', 'catalog.product.view': 'tenant', 'tenant.branch.view': 'tenant' }
// Nhân viên bán hàng: chỉ xem (không lập, không thấy giá vốn).
const VIEWER: Permissions = { 'inventory.movement.view': 'branch', 'inventory.warehouse.view': 'branch', 'tenant.branch.view': 'branch' }

const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 } })

const BR1 = { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính' }
const BR2 = { id: 'br-2', code: 'HN-02', name: 'Chi nhánh Hà Nội' }
const KHO1: Warehouse = { id: 'w-1', branch: BR1, code: 'KHO-01', name: 'Kho chính', address: null, status: 'active', stocktake: null }
const KHO2: Warehouse = { id: 'w-2', branch: BR1, code: 'KHO-02', name: 'Kho thuốc', address: null, status: 'active', stocktake: null }
const KHO3: Warehouse = { id: 'w-3', branch: BR2, code: 'KHO-01', name: 'Kho Hà Nội', address: null, status: 'active', stocktake: null }

const KG = { id: 'u-kg', name: 'Kg' }
const BAO = { id: 'u-bao', name: 'Bao' }

const product = (overrides: Partial<Product> = {}): Product => ({
  id: 'p-npk', branch: BR1, type: 'fertilizer', type_label: 'Phân bón', sku: 'NPK-16', name: 'Phân NPK 16-16-8', category: null, brand: null, description: null,
  status: 'active', track_batch: false, min_stock: null, specs: {}, base_unit: KG,
  units: [
    { unit: KG, is_base: true, factor: '1', price_cash: '15500.00', price_credit: '16000.00', barcode: null },
    { unit: BAO, is_base: false, factor: '50', price_cash: '750000.00', price_credit: '780000.00', barcode: null },
  ],
  created_at: '2026-10-31T00:00:00Z', updated_at: '2026-10-31T00:00:00Z', ...overrides,
})
const NPK = product()
const DRUG = product({ id: 'p-ts', type: 'pesticide', type_label: 'Thuốc bảo vệ thực vật', sku: 'TS-01', name: 'Thuốc trừ sâu 25WG', track_batch: true })

/** Ngày "YYYY-MM-DD" cách hôm nay `plus` ngày (giờ Việt Nam, như giao diện). */
const day = (plus: number) => {
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Ho_Chi_Minh' }).format(new Date())

  return new Date(Date.parse(`${today}T00:00:00Z`) + plus * 86_400_000).toISOString().slice(0, 10)
}

const balance = (id: number, batchNo: string, expires: string, qty: string): StockBalance => ({
  id, warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, product: { id: 'p-ts', sku: 'TS-01', name: 'Thuốc trừ sâu 25WG', track_batch: true, base_unit: 'Kg' },
  batch: { id: `b-${batchNo}`, batch_no: batchNo, manufactured_at: null, expires_at: expires }, quantity: qty,
  expired: expires < day(0), days_to_expiry: Math.round((Date.parse(expires) - Date.parse(day(0))) / 86_400_000),
})
const LOTS = [balance(1, 'A-CU', day(-5), '5.000'), balance(2, 'A-SAP', day(30), '10.500')]

const transfer = (overrides: Partial<StockTransfer> = {}): StockTransfer => ({
  id: 't-1', code: 'CK0001', note: null,
  from: { warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, branch: BR1 },
  to: { warehouse: { id: 'w-2', code: 'KHO-02', name: 'Kho thuốc' }, branch: BR1 },
  performed_by: { id: 'u-1', name: 'Nguyễn Văn A' }, performed_at: '2026-10-31T03:00:00Z', line_count: 2, ...overrides,
})
const CROSS = transfer({ id: 't-2', code: 'CK0002', to: { warehouse: { id: 'w-3', code: 'KHO-01', name: 'Kho Hà Nội' }, branch: BR2 }, line_count: 1 })
const DETAIL = transfer({
  note: 'Dồn hàng ra kho thuốc',
  value: '750000.00',
  documents: { out: { id: 'd-x', code: 'CK0001-X' }, in: { id: 'd-n', code: 'CK0001-N' } },
  lines: [
    { product: { id: 'p-npk', sku: 'NPK-16', name: 'Phân NPK 16-16-8', base_unit: 'Kg' }, batch: null, quantity: '100.000', entered: { unit: 'Bao', quantity: '2.000', factor: '50' }, unit_cost: '5000.0000', value: '500000.00' },
    { product: { id: 'p-ts', sku: 'TS-01', name: 'Thuốc trừ sâu 25WG', base_unit: 'Kg' }, batch: { id: 'b-1', batch_no: 'L001', expires_at: '2030-01-31' }, quantity: '50.000', entered: { unit: 'Kg', quantity: '50.000', factor: '1' }, unit_cost: '5000.0000', value: '250000.00' },
  ],
})

const base = (extra: Record<string, unknown> = {}, permissions: Permissions = KEEPER, warehouses: Warehouse[] = [KHO1, KHO2, KHO3]) =>
  ({
    'GET /api/v1/auth/me': meOk(tenantUser({ permissions })),
    'GET /api/v1/branches': { json: page([{ id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính', phone: null, address: null, status: 'active' }, { id: 'br-2', code: 'HN-02', name: 'Chi nhánh Hà Nội', phone: null, address: null, status: 'active' }]) },
    'GET /api/v1/warehouses': { json: page(warehouses) },
    'GET /api/v1/products/types': { json: { data: [{ key: 'fertilizer', label: 'Phân bón', track_batch_required: false, fields: [] }, { key: 'pesticide', label: 'Thuốc bảo vệ thực vật', track_batch_required: true, fields: [] }], meta: { max_units: 8 } } },
    'GET /api/v1/products': { json: page([NPK, DRUG]) },
    'GET /api/v1/stock/balances': { json: page(LOTS) },
    'GET /api/v1/stock/transfers': { json: page([CROSS, transfer()]) },
    'GET /api/v1/stock/transfers/t-1': { json: { data: DETAIL } },
    ...extra,
  }) as never

const CREATED = { status: 201, json: { data: DETAIL } }

async function pickProduct(user: ReturnType<typeof userEvent.setup>, name: RegExp) {
  await user.click(await screen.findByRole('button', { name }))
}

describe('chuyển kho: danh sách', () => {
  it('có tab Chuyển kho, liệt kê phiếu (mới nhất trước) kèm đường đi; khác chi nhánh thì ghi mã chi nhánh', async () => {
    mockApi(base())
    renderApp('/stock/transfers')

    expect(await screen.findByRole('heading', { name: 'Chuyển kho' })).toBeInTheDocument()
    const table = await screen.findByRole('table')
    expect(within(table).getByRole('link', { name: 'CK0001' })).toHaveAttribute('href', '/stock/transfers?dialog=transfer:t-1')

    const rows = within(table).getAllByRole('row').slice(1)
    expect(within(rows[0]).getByText('CK0002')).toBeInTheDocument()
    expect(within(rows[0]).getByText('(HN-02)')).toBeInTheDocument() // khác chi nhánh: chỉ phía khác chi nhánh được ghi mã
    expect(within(rows[1]).getByText('CK0001')).toBeInTheDocument()
    expect(within(rows[1]).queryByText(/CN-01/)).not.toBeInTheDocument() // cùng chi nhánh thì không nhắc chi nhánh
    expect(within(rows[1]).getByText('Kho chính')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Kho thuốc')).toBeInTheDocument()

    const links = screen.getAllByRole('link', { name: 'Chuyển kho' })
    expect(links.map((link) => link.getAttribute('href')).sort()).toEqual(['/stock/transfers', '/stock/transfers?dialog=transfer-new']) // tab và nút lập phiếu
  })

  it('người chỉ có quyền xem thấy danh sách nhưng không có nút chuyển kho', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/stock/transfers')

    await screen.findByRole('table')
    expect(screen.getAllByRole('link', { name: 'Chuyển kho' })).toHaveLength(1) // chỉ còn tab
  })

  it('chưa có phiếu nào: trạng thái rỗng kèm nút chuyển kho', async () => {
    mockApi(base({ 'GET /api/v1/stock/transfers': { json: page([]) } }))
    renderApp('/stock/transfers')

    expect(await screen.findByText('Chưa có phiếu chuyển kho nào.')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Chuyển kho' })).toHaveLength(3) // tab + nút trên đầu trang + nút trong trạng thái rỗng
  })

  it('lọc theo kho gửi tham số lên máy chủ', async () => {
    const api = mockApi(base())
    const user = userEvent.setup()
    renderApp('/stock/transfers')

    await screen.findByRole('table')
    await user.selectOptions(screen.getByLabelText('Kho'), 'w-2')

    await waitFor(() => expect(api.calls.some((call) => call.path.startsWith('/api/v1/stock/transfers?') && call.path.includes('warehouse=w-2'))).toBe(true))
  })

  it('lọc loại phiếu ở trang Phiếu kho có thêm "Chuyển đi" và "Chuyển đến"', async () => {
    mockApi(base({ 'GET /api/v1/stock/documents': { json: page([]) } }))
    renderApp('/stock/documents')

    expect(await screen.findByRole('option', { name: 'Chuyển đi' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Chuyển đến' })).toBeInTheDocument()
  })
})

describe('chuyển kho: lập phiếu', () => {
  it('chọn hai kho và hàng (đơn vị Bao) rồi chuyển: gửi đúng nội dung, báo thành công kèm liên kết xem phiếu', async () => {
    const api = mockApi(base({ 'POST /api/v1/stock/transfers': CREATED }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=transfer-new')

    expect(await screen.findByRole('heading', { name: 'Chuyển kho' })).toBeInTheDocument()
    await user.selectOptions(await screen.findByLabelText(/Kho gửi/), 'w-1')
    await user.selectOptions(screen.getByLabelText(/Kho nhận/), 'w-2')
    await pickProduct(user, /NPK-16/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.selectOptions(within(line).getByLabelText(/Đơn vị/), 'u-bao')
    await user.type(within(line).getByLabelText(/Số lượng/), '2')
    expect(await within(line).findByText('= 100 Kg')).toBeInTheDocument()
    await user.type(screen.getByLabelText('Ghi chú'), 'Dồn hàng')
    await user.click(screen.getByRole('button', { name: 'Chuyển kho' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/stock/transfers')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/stock/transfers')[0].body).toEqual({
      from_warehouse_id: 'w-1', to_warehouse_id: 'w-2', note: 'Dồn hàng',
      lines: [{ product_id: 'p-npk', unit_id: 'u-bao', quantity: 2 }],
    })
    expect(await screen.findByText('Đã chuyển kho: phiếu CK0001. Tồn hai kho đã được cập nhật.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Xem phiếu' })).toHaveAttribute('href', '/notifications?dialog=transfer:t-1')
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Chuyển kho' })).not.toBeInTheDocument()) // popup đã đóng
  })

  it('chưa chọn kho thì báo từng ô và không gọi máy chủ; kho nhận không liệt kê kho gửi', async () => {
    const api = mockApi(base())
    const user = userEvent.setup()
    renderApp('/notifications?dialog=transfer-new')

    expect(screen.queryByText('Vui lòng chọn kho gửi.')).not.toBeInTheDocument()
    await screen.findByLabelText(/Kho gửi/)
    expect(screen.getByText('Chọn kho gửi trước để chọn hàng cần chuyển.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Chuyển kho' }))

    expect(await screen.findByText('Vui lòng chọn kho gửi.')).toBeInTheDocument()
    expect(screen.getByText('Vui lòng chọn kho nhận.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/stock/transfers')).toHaveLength(0)

    await user.selectOptions(screen.getByLabelText(/Kho gửi/), 'w-1')
    const to = screen.getByLabelText(/Kho nhận/)
    expect(within(to).queryByRole('option', { name: /Kho chính/ })).not.toBeInTheDocument()
    expect(within(to).getByRole('option', { name: /Kho thuốc/ })).toBeInTheDocument()
  })

  it('kho đang kiểm kê bị khóa ở cả ô gửi lẫn ô nhận', async () => {
    mockApi(base({}, KEEPER, [{ ...KHO1, stocktake: { id: 's-1', code: 'KK0001' } }, { ...KHO2, stocktake: { id: 's-2', code: 'KK0002' } }, KHO3]))
    renderApp('/notifications?dialog=transfer-new')

    const from = await screen.findByLabelText(/Kho gửi/)
    expect(within(from).getByRole('option', { name: /Kho chính \(đang kiểm kê\)/ })).toBeDisabled()
    expect(within(from).getByRole('option', { name: /Kho thuốc \(đang kiểm kê\)/ })).toBeDisabled()
    expect(within(from).getByRole('option', { name: /Kho Hà Nội/ })).toBeEnabled()
  })

  it('sản phẩm theo dõi lô: phải chọn lô ở kho gửi, lô hết hạn không chọn được, gửi mã lô', async () => {
    const api = mockApi(base({ 'POST /api/v1/stock/transfers': CREATED }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=transfer-new')

    await user.selectOptions(await screen.findByLabelText(/Kho gửi/), 'w-1')
    await user.selectOptions(screen.getByLabelText(/Kho nhận/), 'w-2')
    await pickProduct(user, /TS-01/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText(/Số lượng/), '2')

    expect(await within(line).findByRole('option', { name: /A-CU — hạn .* còn 5 Kg — đã hết hạn, không chuyển được/ })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Chuyển kho' }))
    expect(await within(line).findByText('Vui lòng chọn lô.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/stock/transfers')).toHaveLength(0)

    await user.selectOptions(within(line).getByLabelText(/Lô/), 'b-A-SAP')
    expect(await within(line).findByText('Đang có 10,5 Kg')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Chuyển kho' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/stock/transfers')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/stock/transfers')[0].body).toMatchObject({ lines: [{ product_id: 'p-ts', unit_id: 'u-kg', quantity: 2, batch_id: 'b-A-SAP' }] })
  })

  it('khác chi nhánh: hiện lưu ý ghép theo mã; đổi kho gửi sang chi nhánh khác thì bỏ các dòng đã chọn', async () => {
    mockApi(base())
    const user = userEvent.setup()
    renderApp('/notifications?dialog=transfer-new')

    await user.selectOptions(await screen.findByLabelText(/Kho gửi/), 'w-1')
    await user.selectOptions(screen.getByLabelText(/Kho nhận/), 'w-2')
    expect(screen.queryByText(/ghép theo mã sản phẩm/)).not.toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText(/Kho nhận/), 'w-3')
    expect(await screen.findByText(/ghép theo mã sản phẩm/)).toBeInTheDocument()

    await pickProduct(user, /NPK-16/)
    expect(within(screen.getByRole('group', { name: 'Dòng 1' })).getByRole('button', { name: 'Đổi sản phẩm' })).toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText(/Kho gửi/), 'w-3') // sang chi nhánh Hà Nội
    expect(within(screen.getByRole('group', { name: 'Dòng 1' })).queryByRole('button', { name: 'Đổi sản phẩm' })).not.toBeInTheDocument()
    expect(screen.getByLabelText(/Kho nhận/)).toHaveValue('') // kho nhận trùng kho gửi mới thì bị bỏ chọn
  })

  it('lỗi 422 của máy chủ hiện đúng ô: chi nhánh nhận chưa có sản phẩm, kho đang kiểm kê', async () => {
    mockApi(base({
      'POST /api/v1/stock/transfers': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', {
        errors: {
          'lines.0.product_id': ['Chi nhánh nhận (Chi nhánh Hà Nội) chưa có sản phẩm mã NPK-16: hãy sao chép sản phẩm sang chi nhánh đó (Sản phẩm → Sao chép) rồi chuyển lại.'],
          to_warehouse_id: ['Kho này đang kiểm kê (KK0007): hoàn tất hoặc hủy đợt kiểm kê trước khi ghi phiếu kho.'],
        },
      }),
    }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=transfer-new')

    await user.selectOptions(await screen.findByLabelText(/Kho gửi/), 'w-1')
    await user.selectOptions(screen.getByLabelText(/Kho nhận/), 'w-3')
    await pickProduct(user, /NPK-16/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText(/Số lượng/), '5')
    await user.click(screen.getByRole('button', { name: 'Chuyển kho' }))

    expect(await screen.findByText(/chưa có sản phẩm mã NPK-16: hãy sao chép sản phẩm/)).toBeInTheDocument()
    expect(screen.getByText(/Kho này đang kiểm kê \(KK0007\)/)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Chuyển kho' })).toBeInTheDocument() // lỗi thì popup còn nguyên để sửa
  })

  it('chỉ có một kho đang hoạt động: báo cần ít nhất hai kho', async () => {
    mockApi(base({}, KEEPER, [KHO1, { ...KHO2, status: 'inactive' }]))
    renderApp('/notifications?dialog=transfer-new')

    expect(await screen.findByText(/Cần ít nhất hai kho đang hoạt động/)).toBeInTheDocument()
  })

  it('người không có quyền nhập/xuất kho mở popup thì bị chặn', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/notifications?dialog=transfer-new')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
  })
})

describe('chuyển kho: xem phiếu', () => {
  it('hiện đường đi, người làm, ghi chú, các dòng (đơn vị đã nhập và đơn vị cơ sở, lô), giá trị và liên kết tới hai phiếu kho', async () => {
    mockApi(base())
    renderApp('/notifications?dialog=transfer:t-1')

    expect(await screen.findByRole('heading', { name: 'Phiếu chuyển CK0001' })).toBeInTheDocument()
    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText('Kho chính')).toBeInTheDocument()
    expect(within(dialog).getByText('Kho thuốc')).toBeInTheDocument()
    expect(within(dialog).getByText('Dồn hàng ra kho thuốc')).toBeInTheDocument()
    expect(within(dialog).getByText('Nguyễn Văn A')).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: 'CK0001-X' })).toHaveAttribute('href', '/notifications?dialog=stock-doc:d-x')
    expect(within(dialog).getByRole('link', { name: 'CK0001-N' })).toHaveAttribute('href', '/notifications?dialog=stock-doc:d-n')

    const table = within(dialog).getByRole('table')
    expect(within(table).getByText('2 Bao')).toBeInTheDocument()
    expect(within(table).getByText('100 Kg')).toBeInTheDocument()
    expect(within(table).getByText('L001')).toBeInTheDocument()
    expect(within(dialog).getAllByText(/750\.000/).length).toBeGreaterThan(0)
  })

  it('người không được xem giá vốn thì không thấy cột giá vốn và giá trị', async () => {
    const { value: _value, ...withoutValue } = DETAIL
    void _value
    mockApi(base({ 'GET /api/v1/stock/transfers/t-1': { json: { data: { ...withoutValue, lines: DETAIL.lines?.map(({ unit_cost: _c, value: _v, ...rest }) => rest) } } } }, VIEWER))
    renderApp('/notifications?dialog=transfer:t-1')

    await screen.findByRole('heading', { name: 'Phiếu chuyển CK0001' })
    const table = within(screen.getByRole('dialog')).getByRole('table')
    expect(within(table).queryByText('Giá vốn')).not.toBeInTheDocument()
    expect(within(table).queryByText('Giá trị')).not.toBeInTheDocument()
    expect(screen.queryByText('Giá trị hàng chuyển')).not.toBeInTheDocument()
  })

  it('không tìm thấy phiếu (hoặc không có quyền xem) thì báo rõ', async () => {
    mockApi(base({ 'GET /api/v1/stock/transfers/t-9': problem(404, 'not_found', 'Không tìm thấy.') }))
    renderApp('/notifications?dialog=transfer:t-9')

    expect(await screen.findByText('Không tìm thấy phiếu này hoặc bạn không có quyền xem.')).toBeInTheDocument()
  })

  it('phiếu kho của một lần chuyển có nhãn "Chuyển đi" và liên kết về phiếu chuyển', async () => {
    const doc: StockDocument = {
      id: 'd-x', code: 'CK0001-X', type: 'transfer_out', reason: null, note: null, warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, branch: BR1,
      transfer: { id: 't-1', code: 'CK0001' }, performed_by: { id: 'u-1', name: 'Nguyễn Văn A' }, performed_at: '2026-10-31T03:00:00Z', lines: [],
    }
    mockApi(base({ 'GET /api/v1/stock/documents/d-x': { json: { data: doc } } }))
    renderApp('/notifications?dialog=stock-doc:d-x')

    expect(await screen.findByRole('heading', { name: /Phiếu CK0001-X/ })).toBeInTheDocument()
    expect(screen.getByText('Chuyển đi')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'CK0001' })).toHaveAttribute('href', '/notifications?dialog=transfer:t-1')
  })
})
