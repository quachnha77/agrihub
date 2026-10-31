import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { Product } from '../catalog/api.ts'
import type { StockBalance, StockDocument, StockMovement, StockProduct, Warehouse } from './api.ts'

type Permissions = Record<string, 'own' | 'branch' | 'tenant'>

const FULL: Permissions = {
  'inventory.movement.view': 'tenant', 'inventory.movement.create': 'tenant', 'inventory.adjustment.create': 'tenant',
  'inventory.warehouse.view': 'tenant', 'inventory.warehouse.create': 'tenant', 'inventory.warehouse.update': 'tenant', 'inventory.warehouse.delete': 'tenant',
  'catalog.product.view': 'tenant', 'tenant.branch.view': 'tenant',
}
// Nhân viên bán hàng: xem tồn và kho của chi nhánh mình, không nhập/xuất, không thấy giá vốn.
const SALES: Permissions = { 'inventory.movement.view': 'branch', 'inventory.warehouse.view': 'branch', 'tenant.branch.view': 'branch' }
// Thủ kho: nhập/xuất/điều chỉnh trong chi nhánh, chỉ xem danh sách kho.
const KEEPER: Permissions = { 'inventory.movement.view': 'branch', 'inventory.movement.create': 'branch', 'inventory.adjustment.create': 'branch', 'inventory.warehouse.view': 'branch', 'tenant.branch.view': 'branch' }
// Chỉ nhập/xuất, không có quyền điều chỉnh.
const RECEIVER: Permissions = { 'inventory.movement.view': 'branch', 'inventory.movement.create': 'branch', 'inventory.warehouse.view': 'branch', 'tenant.branch.view': 'branch' }

const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 } })

/** Ngày "YYYY-MM-DD" cách hôm nay `plus` ngày (giờ Việt Nam, như giao diện). */
const day = (plus: number) => {
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Ho_Chi_Minh' }).format(new Date())

  return new Date(Date.parse(`${today}T00:00:00Z`) + plus * 86_400_000).toISOString().slice(0, 10)
}

const BR1 = { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính' }
const BRANCHES = [
  { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính', phone: null, address: null, status: 'active' },
  { id: 'br-2', code: 'HN-02', name: 'Chi nhánh Hà Nội', phone: null, address: null, status: 'active' },
]
const WAREHOUSES: Warehouse[] = [
  { id: 'w-1', branch: BR1, code: 'KHO-01', name: 'Kho chính', address: null, status: 'active' },
  { id: 'w-2', branch: BR1, code: 'KHO-02', name: 'Kho thuốc', address: 'Sau nhà', status: 'active' },
]
const ONE_WAREHOUSE = [WAREHOUSES[0]]

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

const stockProduct = (overrides: Partial<StockProduct> = {}): StockProduct => ({
  id: 'p-npk', sku: 'NPK-16', name: 'Phân NPK 16-16-8', type: 'fertilizer', type_label: 'Phân bón', status: 'active', track_batch: false, branch: BR1, base_unit: KG,
  total_qty: '80.000', min_stock: '100.000', is_low: true, expired_qty: '0.000', expiring_qty: '0.000', next_expiry: null, avg_cost: '15000.0000', value: '1200000.00', ...overrides,
})
const LOW = stockProduct()
const EXPIRING = stockProduct({
  id: 'p-ts', sku: 'TS-01', name: 'Thuốc trừ sâu 25WG', type: 'pesticide', type_label: 'Thuốc bảo vệ thực vật', track_batch: true, total_qty: '15.500', min_stock: null, is_low: false,
  expired_qty: '5.000', expiring_qty: '10.500', next_expiry: day(-5), avg_cost: '5000.0000', value: '77500.00',
})

const balance = (id: number, batchNo: string | null, expires: string | null, qty: string, overrides: Partial<StockBalance> = {}): StockBalance => ({
  id, warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, product: { id: 'p-ts', sku: 'TS-01', name: 'Thuốc trừ sâu 25WG', track_batch: true, base_unit: 'Kg' },
  batch: batchNo ? { id: `b-${batchNo}`, batch_no: batchNo, manufactured_at: null, expires_at: expires } : null, quantity: qty,
  expired: expires !== null && expires < day(0), days_to_expiry: expires === null ? null : Math.round((Date.parse(expires) - Date.parse(day(0))) / 86_400_000), ...overrides,
})
const DRUG_BATCHES = [balance(1, 'A-CU', day(-5), '5.000'), balance(2, 'A-SAP', day(30), '10.500')]

const movement = (id: number, type: StockMovement['type'], delta: string, after: string, extra: Partial<StockMovement> = {}): StockMovement => ({
  id, type, document: { id: `d-${id}`, code: type === 'issue' ? 'PX0001' : 'PN0001', reason: null }, warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' },
  product: { id: 'p-npk', sku: 'NPK-16', name: 'Phân NPK 16-16-8', base_unit: 'Kg' }, batch: null, quantity_delta: delta, balance_after: after,
  entered: { unit: 'Bao', quantity: '1.000', factor: '50' }, unit_cost: '15000.0000', value: '750000.00', performed_at: '2026-10-31T03:00:00Z', ...extra,
})

const SUMMARY = { low_products: 1, expired_batches: 1, expiring_batches: 2, expiry_warning_days: 60, stock_value: '1277500.00' }

const document = (overrides: Partial<StockDocument> = {}): StockDocument => ({
  id: 'd-1', code: 'PN0001', type: 'receipt', reason: null, note: 'Nhập lô đầu', warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, branch: BR1,
  performed_by: { id: 'u-1', name: 'Nguyễn Văn A' }, performed_at: '2026-10-31T03:00:00Z', line_count: 1, ...overrides,
})
const DETAIL = document({
  value: '7500000.00',
  lines: [{
    id: 10, type: 'receipt', product: { id: 'p-npk', sku: 'NPK-16', name: 'Phân NPK 16-16-8', base_unit: 'Kg' }, batch: null, quantity_delta: '500.000', balance_after: '500.000',
    entered: { unit: 'Bao', quantity: '10.000', factor: '50' }, unit_cost: '15000.0000', value: '7500000.00', performed_at: '2026-10-31T03:00:00Z',
  }],
})

const base = (extra: Record<string, unknown> = {}, permissions: Permissions = FULL, warehouses: Warehouse[] = WAREHOUSES) =>
  ({
    'GET /api/v1/auth/me': meOk(tenantUser({ permissions })),
    'GET /api/v1/branches': { json: page(BRANCHES) },
    'GET /api/v1/warehouses': { json: page(warehouses) },
    'GET /api/v1/products/types': { json: { data: [{ key: 'fertilizer', label: 'Phân bón', track_batch_required: false, fields: [] }, { key: 'pesticide', label: 'Thuốc bảo vệ thực vật', track_batch_required: true, fields: [] }], meta: { max_units: 8 } } },
    'GET /api/v1/stock/products': { json: page([LOW, EXPIRING]) },
    'GET /api/v1/stock/summary': { json: { data: SUMMARY } },
    'GET /api/v1/stock/balances': { json: page(DRUG_BATCHES) },
    'GET /api/v1/stock/movements': { json: page([movement(2, 'issue', '-50.000', '30.000'), movement(1, 'receipt', '80.000', '80.000')]) },
    'GET /api/v1/stock/documents': { json: page([document(), document({ id: 'd-2', code: 'PX0001', type: 'issue', reason: 'damaged', line_count: 2 })]) },
    'GET /api/v1/products': { json: page([NPK, DRUG]) },
    ...extra,
  }) as never

describe('trang Tồn kho', () => {
  it('hiện số lượng, mức tối thiểu, hạn gần nhất, giá vốn, giá trị và các cảnh báo', async () => {
    mockApi(base())
    renderApp('/stock')

    const table = await screen.findByRole('table')
    const npk = within(table).getByText('Phân NPK 16-16-8').closest('tr')!
    expect(within(npk).getByText('80')).toBeInTheDocument()
    expect(within(npk).getByText('Sắp hết hàng')).toBeInTheDocument()
    expect(within(npk).getByText('100')).toBeInTheDocument()
    expect(within(npk).getByText('15.000 ₫')).toBeInTheDocument()
    expect(within(npk).getByText('1.200.000 ₫')).toBeInTheDocument()

    const drug = within(table).getByText('Thuốc trừ sâu 25WG').closest('tr')!
    expect(within(drug).getByText('15,5')).toBeInTheDocument() // dấu phẩy thập phân
    expect(within(drug).getByText('Theo dõi lô')).toBeInTheDocument()
    expect(within(drug).getByText('5 Kg hết hạn')).toBeInTheDocument()
    expect(within(drug).getByText('Hết hạn 5 ngày')).toBeInTheDocument()

    const cards = screen.getByRole('group', { name: 'Cảnh báo tồn kho' })
    expect(within(cards).getByRole('button', { name: /Sắp hết hàng/ })).toHaveTextContent('1')
    expect(within(cards).getByRole('button', { name: /Lô sắp hết hạn \(≤ 60 ngày\)/ })).toHaveTextContent('2')
    expect(within(cards).getByRole('button', { name: /Lô đã hết hạn/ })).toHaveTextContent('1')
    expect(within(cards).getByText('1.277.500 ₫')).toBeInTheDocument()
  })

  it('menu có một mục "Kho hàng"; Tồn kho, Phiếu kho, Danh sách kho là ba tab trong phân hệ', async () => {
    mockApi(base())
    renderApp('/stock')

    const nav = await screen.findByRole('navigation', { name: 'Menu chính' })
    expect(within(nav).getByRole('link', { name: 'Kho hàng' })).toHaveAttribute('aria-current', 'page')
    expect(within(nav).queryByRole('link', { name: 'Phiếu kho' })).not.toBeInTheDocument()

    const tabs = await screen.findByRole('navigation', { name: 'Kho hàng' })
    expect(within(tabs).getByRole('link', { name: 'Tồn kho' })).toHaveAttribute('aria-current', 'page')
    expect(within(tabs).getByRole('link', { name: 'Phiếu kho' })).toHaveAttribute('href', '/stock/documents')
    expect(within(tabs).getByRole('link', { name: 'Danh sách kho' })).toHaveAttribute('href', '/warehouses')
  })

  it('tab chỉ hiện các màn hình có quyền xem; không có quyền xem tồn thì mục "Kho hàng" dẫn tới danh sách kho', async () => {
    mockApi(base({}, { 'inventory.warehouse.view': 'branch', 'tenant.branch.view': 'branch' }))
    renderApp('/warehouses')

    const nav = await screen.findByRole('navigation', { name: 'Menu chính' })
    expect(within(nav).getByRole('link', { name: 'Kho hàng' })).toHaveAttribute('href', '/warehouses')
    expect(screen.queryByRole('navigation', { name: 'Kho hàng' })).not.toBeInTheDocument() // chỉ một tab thì không cần thanh tab
  })

  it('không có quyền xem kho thì không có trong menu và gõ thẳng đường dẫn bị chặn, không gọi API', async () => {
    const server = mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser({ permissions: { 'tenant.branch.view': 'tenant' } })) })
    renderApp('/stock')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Menu chính' })).not.toHaveTextContent('Tồn kho')
    expect(server.calls.filter((call) => call.path.startsWith('/api/v1/stock'))).toHaveLength(0)
  })

  it('bấm thẻ cảnh báo lọc danh sách; tìm kiếm và lọc gửi đúng tham số', async () => {
    const server = mockApi(base())
    renderApp('/stock')
    const user = userEvent.setup()

    await screen.findByRole('table')
    await user.click(screen.getByRole('button', { name: /Lô đã hết hạn/ }))
    await waitFor(() => expect(server.calls.filter((c) => c.path.startsWith('/api/v1/stock/products?')).at(-1)?.path).toContain('expiry=expired'))
    expect(screen.getByRole('button', { name: /Lô đã hết hạn/ })).toHaveAttribute('aria-pressed', 'true')
    await user.click(screen.getByRole('button', { name: /Lô đã hết hạn/ })) // bấm lại là bỏ lọc
    await waitFor(() => expect(server.calls.filter((c) => c.path.startsWith('/api/v1/stock/products?')).at(-1)?.path).not.toContain('expiry='))

    await user.selectOptions(screen.getByLabelText('Loại hàng'), 'pesticide')
    await user.selectOptions(screen.getByLabelText('Kho'), 'w-2')
    await user.selectOptions(screen.getByLabelText('Tồn kho', { selector: 'select' }), 'low')
    await user.type(screen.getByLabelText('Tìm kiếm'), 'thuốc')

    await waitFor(() => {
      const last = server.calls.filter((c) => c.method === 'GET' && c.path.startsWith('/api/v1/stock/products?')).at(-1)?.path ?? ''
      const query = new URLSearchParams(last.split('?')[1])
      expect(query.get('type')).toBe('pesticide')
      expect(query.get('warehouse')).toBe('w-2')
      expect(query.get('stock')).toBe('low')
      expect(query.get('q')).toBe('thuốc')
    })
  })

  it('chi tiết một sản phẩm: tồn theo lô kèm hạn dùng và thẻ kho', async () => {
    mockApi(base())
    renderApp('/stock')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Chi tiết Thuốc trừ sâu 25WG' }))
    const dialog = await screen.findByRole('dialog', { name: 'Tồn kho: Thuốc trừ sâu 25WG' })

    expect(within(dialog).getByText(/Tổng tồn 15,5 Kg/)).toBeInTheDocument()
    expect(within(dialog).getByText(/Giá vốn bình quân 5.000 ₫\/Kg/)).toBeInTheDocument()
    expect(await within(dialog).findByText('A-CU')).toBeInTheDocument()
    expect(within(dialog).getByText('A-SAP')).toBeInTheDocument()
    expect(within(dialog).getByText('Hết hạn 5 ngày')).toBeInTheDocument()
    expect(within(dialog).getByText('Còn 30 ngày')).toBeInTheDocument()
    expect(await within(dialog).findByText('PX0001')).toBeInTheDocument()
    expect(within(dialog).getByText('-50')).toBeInTheDocument()
    expect(within(dialog).getAllByText('đã nhập 1 Bao').length).toBeGreaterThan(0)
  })

  it('thẻ kho chia trang trong hộp chi tiết: 10 dòng mỗi trang, bấm "Sau" gửi trang kế', async () => {
    const server = mockApi(base({
      'GET /api/v1/stock/movements': (call: { path: string }) => {
        const current = Number(new URL(call.path, 'http://x').searchParams.get('page') ?? '1')

        return { json: { data: [movement(current, 'receipt', '1.000', '1.000')], meta: { current_page: current, last_page: 2, total: 12, per_page: 10 } } }
      },
    }))
    renderApp('/stock')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Chi tiết Thuốc trừ sâu 25WG' }))
    const dialog = await screen.findByRole('dialog', { name: 'Tồn kho: Thuốc trừ sâu 25WG' })
    expect(await within(dialog).findByText(/Tổng 12 mục · Trang 1\/2/)).toBeInTheDocument()
    expect(server.calls.some((c) => c.path.startsWith('/api/v1/stock/movements') && c.path.includes('per_page=10') && c.path.includes('page=1'))).toBe(true)

    await user.click(within(dialog).getByRole('button', { name: 'Sau' }))
    expect(await within(dialog).findByText(/Trang 2\/2/)).toBeInTheDocument()
    expect(server.calls.some((c) => c.path.startsWith('/api/v1/stock/movements') && c.path.includes('page=2'))).toBe(true)
  })

  it('đang tải: giữ chỗ cho bốn thẻ cảnh báo và bảng, nên khi số liệu về bảng không bị đẩy xuống', async () => {
    let release!: () => void
    const held = new Promise<void>((resolve) => { release = resolve })
    mockApi(base({ 'GET /api/v1/stock/summary': { json: { data: SUMMARY }, after: held } }))
    renderApp('/stock')

    await screen.findByRole('table') // bảng về trước, thẻ số liệu còn chờ: chỗ của thẻ đã được giữ bằng skeleton
    expect(screen.getByText('Đang tải…')).toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Cảnh báo tồn kho' })).not.toBeInTheDocument()

    release()
    expect(await screen.findByRole('button', { name: /Sắp hết hàng/ })).toBeInTheDocument()
    expect(screen.queryByText('Đang tải…')).not.toBeInTheDocument()
  })

  it('nhân viên bán hàng thấy số lượng nhưng không có giá vốn, giá trị tồn hay nút nhập/xuất', async () => {
    mockApi(base({
      'GET /api/v1/stock/products': { json: page([{ ...LOW, avg_cost: undefined, value: undefined }]) },
      'GET /api/v1/stock/summary': { json: { data: { ...SUMMARY, stock_value: undefined } } },
    }, SALES))
    renderApp('/stock')

    const table = await screen.findByRole('table')
    expect(within(table).getByText('Phân NPK 16-16-8')).toBeInTheDocument()
    expect(within(table).queryByText('Giá vốn bình quân')).not.toBeInTheDocument()
    expect(within(table).queryByText('Giá trị')).not.toBeInTheDocument()
    expect(screen.queryByText('Giá trị tồn')).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Nhập kho' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Xuất kho' })).not.toBeInTheDocument()
  })

  it('nút lập phiếu hiện theo quyền: không có quyền điều chỉnh thì không có nút "Điều chỉnh"', async () => {
    mockApi(base({}, RECEIVER))
    renderApp('/stock')

    expect(await screen.findByRole('link', { name: 'Nhập kho' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Xuất kho' })).toBeInTheDocument()

    // Hai phiếu ít dùng nằm trong menu "Phiếu khác" cho đỡ rối.
    await userEvent.setup().click(screen.getByRole('button', { name: 'Phiếu khác' }))
    const menu = await screen.findByRole('menu', { name: 'Phiếu khác' })
    expect(within(menu).getByRole('menuitem', { name: 'Tồn đầu kỳ' })).toHaveAttribute('href', '/stock/new?type=opening')
    expect(within(menu).queryByRole('menuitem', { name: 'Điều chỉnh' })).not.toBeInTheDocument()
  })

  it('người có quyền điều chỉnh thấy mục "Điều chỉnh" trong menu "Phiếu khác"', async () => {
    mockApi(base())
    renderApp('/stock')

    await userEvent.setup().click(await screen.findByRole('button', { name: 'Phiếu khác' }))
    const menu = await screen.findByRole('menu', { name: 'Phiếu khác' })
    expect(within(menu).getByRole('menuitem', { name: 'Điều chỉnh' })).toHaveAttribute('href', '/stock/new?type=adjustment')
  })
})

describe('phiếu kho', () => {
  it('danh sách có mã, loại, kho, số dòng, người ghi; lọc gửi đúng tham số', async () => {
    const server = mockApi(base())
    renderApp('/stock/documents')
    const user = userEvent.setup()

    const table = await screen.findByRole('table')
    expect(within(table).getByRole('link', { name: 'PN0001' })).toHaveAttribute('href', '/stock/documents/d-1')
    expect(within(table).getByText('Nhập kho')).toBeInTheDocument()
    expect(within(table).getByText('Xuất kho')).toBeInTheDocument()
    expect(within(table).getAllByText('Nguyễn Văn A')).toHaveLength(2)

    await user.selectOptions(screen.getByLabelText('Loại phiếu'), 'issue')
    await user.type(screen.getByLabelText('Mã phiếu'), 'px')
    await waitFor(() => {
      const query = new URLSearchParams((server.calls.filter((c) => c.method === 'GET' && c.path.startsWith('/api/v1/stock/documents?')).at(-1)?.path ?? '').split('?')[1])
      expect(query.get('type')).toBe('issue')
      expect(query.get('q')).toBe('px')
    })
  })

  it('chi tiết: từng dòng với số lượng đã nhập, thay đổi theo đơn vị cơ sở, tồn sau, giá vốn và giá trị', async () => {
    mockApi(base({ 'GET /api/v1/stock/documents/d-1': { json: { data: DETAIL } } }))
    renderApp('/stock/documents/d-1')

    expect(await screen.findByRole('heading', { name: /Phiếu PN0001/ })).toBeInTheDocument()
    expect(screen.getByText('Nhập lô đầu')).toBeInTheDocument()
    expect(screen.getByText('Nguyễn Văn A', { selector: 'dd' })).toBeInTheDocument()
    expect(screen.getByText('7.500.000 ₫', { selector: 'dd' })).toBeInTheDocument()
    const row = screen.getByText('Phân NPK 16-16-8').closest('tr')!
    expect(within(row).getByText('10 Bao')).toBeInTheDocument()
    expect(within(row).getAllByText('+500 Kg').length).toBe(1)
    expect(within(row).getByText('15.000 ₫')).toBeInTheDocument()
    expect(screen.getByText(/không sửa hoặc xóa được/)).toBeInTheDocument()
  })

  it('chi tiết cho nhân viên bán hàng không có cột giá vốn', async () => {
    const hidden = { ...DETAIL, value: undefined, lines: DETAIL.lines!.map((l) => ({ ...l, unit_cost: undefined, value: undefined })) }
    mockApi(base({ 'GET /api/v1/stock/documents/d-1': { json: { data: hidden } } }, SALES))
    renderApp('/stock/documents/d-1')

    expect(await screen.findByRole('heading', { name: /Phiếu PN0001/ })).toBeInTheDocument()
    expect(screen.queryByText('Giá vốn')).not.toBeInTheDocument()
    expect(screen.queryByText('Giá trị biến động tồn')).not.toBeInTheDocument()
  })

  it('phiếu không có hoặc ngoài phạm vi: báo không tìm thấy', async () => {
    mockApi(base({ 'GET /api/v1/stock/documents/zzz': problem(404, 'not_found', 'Không tìm thấy.') }))
    renderApp('/stock/documents/zzz')

    expect(await screen.findByText('Không tìm thấy phiếu này hoặc bạn không có quyền xem.')).toBeInTheDocument()
  })
})

describe('danh sách kho', () => {
  it('thêm kho cho một chi nhánh, sửa không gửi chi nhánh, xóa có xác nhận', async () => {
    const server = mockApi(base({
      'POST /api/v1/warehouses': { status: 201, json: { data: { ...WAREHOUSES[0], id: 'w-9', code: 'KHO-LANH', name: 'Kho lạnh' } } },
      'PATCH /api/v1/warehouses/w-2': { json: { data: WAREHOUSES[1] } },
      'DELETE /api/v1/warehouses/w-2': { status: 204 },
    }))
    renderApp('/warehouses')
    const user = userEvent.setup()

    const table = await screen.findByRole('table')
    expect(within(table).getByText('Kho thuốc')).toBeInTheDocument()
    expect(within(table).getAllByText('CN-01').length).toBe(2) // có hai chi nhánh nên có cột chi nhánh

    await user.click(screen.getByRole('button', { name: 'Thêm kho' }))
    const add = await screen.findByRole('dialog', { name: 'Thêm kho' })
    await user.click(within(add).getByRole('button', { name: 'Lưu' }))
    expect(await within(add).findByText('Vui lòng chọn chi nhánh.')).toBeInTheDocument()
    expect(within(add).getByText('Vui lòng nhập mã kho.')).toBeInTheDocument()
    await user.selectOptions(within(add).getByLabelText('Chi nhánh'), 'br-2')
    await user.type(within(add).getByLabelText('Mã kho'), 'KHO-LANH')
    await user.type(within(add).getByLabelText('Tên kho'), 'Kho lạnh')
    await user.click(within(add).getByRole('button', { name: 'Lưu' }))
    await waitFor(() => expect(server.to('POST', '/api/v1/warehouses')[0]?.body).toEqual({ code: 'KHO-LANH', name: 'Kho lạnh', address: null, status: 'active', branch_id: 'br-2' }))

    await user.click(screen.getByRole('button', { name: 'Sửa Kho thuốc' }))
    const edit = await screen.findByRole('dialog', { name: 'Sửa kho' })
    expect(within(edit).queryByLabelText('Chi nhánh')).not.toBeInTheDocument() // chi nhánh không đổi được
    const name = within(edit).getByLabelText('Tên kho')
    await user.clear(name)
    await user.type(name, 'Kho thuốc BVTV')
    await user.click(within(edit).getByRole('button', { name: 'Lưu' }))
    await waitFor(() => expect(server.to('PATCH', '/api/v1/warehouses/w-2')[0]?.body).toEqual({ code: 'KHO-02', name: 'Kho thuốc BVTV', address: 'Sau nhà', status: 'active' }))

    await user.click(screen.getByRole('button', { name: 'Xóa Kho thuốc' }))
    const confirm = await screen.findByRole('dialog', { name: 'Xóa kho' })
    await user.click(within(confirm).getByRole('button', { name: 'Xóa' }))
    await waitFor(() => expect(server.to('DELETE', '/api/v1/warehouses/w-2')).toHaveLength(1))
  })

  it('kho đã có phiếu thì không xóa được: báo lý do', async () => {
    mockApi(base({ 'DELETE /api/v1/warehouses/w-1': problem(409, 'warehouse_in_use', 'Kho đã có phiếu kho.') }))
    renderApp('/warehouses')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Xóa Kho chính' }))
    const dialog = await screen.findByRole('dialog', { name: 'Xóa kho' })
    await user.click(within(dialog).getByRole('button', { name: 'Xóa' }))

    expect(await within(dialog).findByText(/đã có phiếu nhập\/xuất nên không xóa được/)).toBeInTheDocument()
  })

  it('lỗi từ máy chủ hiện đúng ô (mã trùng)', async () => {
    mockApi(base({ 'POST /api/v1/warehouses': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', { errors: { code: ['Mã kho này đã được dùng trong chi nhánh.'] } }) }))
    renderApp('/warehouses')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Thêm kho' }))
    const add = await screen.findByRole('dialog', { name: 'Thêm kho' })
    await user.selectOptions(within(add).getByLabelText('Chi nhánh'), 'br-1')
    await user.type(within(add).getByLabelText('Mã kho'), 'KHO-01')
    await user.type(within(add).getByLabelText('Tên kho'), 'Trùng')
    await user.click(within(add).getByRole('button', { name: 'Lưu' }))

    expect(await within(add).findByText('Mã kho này đã được dùng trong chi nhánh.')).toBeInTheDocument()
  })

  it('người chỉ có quyền xem kho: không có nút thêm, sửa, xóa', async () => {
    mockApi(base({}, SALES))
    renderApp('/warehouses')

    const table = await screen.findByRole('table')
    expect(within(table).getByText('Kho thuốc')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Thêm kho' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Sửa/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Xóa/ })).not.toBeInTheDocument()
  })
})

describe('lập phiếu kho', () => {
  const CREATED = { status: 201, json: { data: DETAIL } }

  async function pickProduct(user: ReturnType<typeof userEvent.setup>, name: RegExp) {
    await user.click(await screen.findByRole('button', { name }))
  }

  it('nhập kho: chọn sản phẩm, đơn vị Bao, số lượng và đơn giá; hiện quy đổi và gửi đúng nội dung', async () => {
    const server = mockApi(base({ 'POST /api/v1/stock/documents': CREATED, 'GET /api/v1/stock/documents/d-1': { json: { data: DETAIL } } }))
    renderApp('/stock/new?type=receipt')
    const user = userEvent.setup()

    expect(await screen.findByRole('heading', { name: 'Nhập kho' })).toBeInTheDocument()
    expect(screen.getByText('Chọn kho trước để chọn sản phẩm của chi nhánh đó.')).toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText('Kho'), 'w-1')
    await pickProduct(user, /NPK-16/)

    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.selectOptions(within(line).getByLabelText('Đơn vị'), 'u-bao')
    await user.type(within(line).getByLabelText('Số lượng'), '10')
    await user.type(within(line).getByLabelText('Đơn giá nhập (đồng)'), '750.000')
    expect(within(line).getByText('= 500 Kg')).toBeInTheDocument()
    expect(within(line).getByText('750.000 ₫ — 15.000 ₫/Kg')).toBeInTheDocument()
    await user.type(screen.getByLabelText('Ghi chú'), 'Nhập lô đầu')
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/stock/documents')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/stock/documents')[0]?.body).toEqual({
      warehouse_id: 'w-1', type: 'receipt', reason: null, note: 'Nhập lô đầu',
      lines: [{ product_id: 'p-npk', unit_id: 'u-bao', quantity: 10, unit_cost: 750000 }],
    })
    expect(await screen.findByRole('heading', { name: /Phiếu PN0001/ })).toBeInTheDocument()
    expect(screen.getByText('Đã ghi phiếu PN0001. Tồn kho đã được cập nhật.')).toBeInTheDocument()
  })

  it('kiểm tra ngay trên trình duyệt: thiếu kho, sản phẩm, số lượng, đơn giá thì không gọi API', async () => {
    const server = mockApi(base())
    renderApp('/stock/new?type=receipt')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Ghi phiếu' }))
    expect(await screen.findByText('Vui lòng chọn kho.')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Kho'), 'w-1')
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))
    expect(await screen.findByText('Vui lòng chọn sản phẩm.')).toBeInTheDocument()
    await pickProduct(user, /NPK-16/)
    await user.type(screen.getByLabelText('Số lượng'), '0')
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))
    expect(await screen.findByText('Nhập số lượng lớn hơn 0 (tối đa 3 chữ số thập phân).')).toBeInTheDocument()
    expect(screen.getByText('Nhập đơn giá hợp lệ (từ 0).')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/stock/documents')).toHaveLength(0)
  })

  it('hàng theo dõi lô: nhập kho phải khai số lô và hạn dùng, rồi gửi kèm lô mới', async () => {
    const server = mockApi(base({ 'POST /api/v1/stock/documents': CREATED, 'GET /api/v1/stock/documents/d-1': { json: { data: DETAIL } } }))
    renderApp('/stock/new?type=receipt')
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByLabelText('Kho'), 'w-1')
    await pickProduct(user, /TS-01/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText('Số lượng'), '12,5')
    await user.type(within(line).getByLabelText('Đơn giá nhập (đồng)'), '5000')
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))

    expect(await within(line).findByText('Sản phẩm này theo dõi lô: vui lòng nhập số lô.')).toBeInTheDocument()
    expect(within(line).getByText('Sản phẩm này theo dõi lô: vui lòng nhập hạn dùng.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/stock/documents')).toHaveLength(0)

    await user.type(within(line).getByLabelText('Số lô'), 'L001')
    await user.type(within(line).getByLabelText('Hạn dùng'), '2030-01-31')
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/stock/documents')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/stock/documents')[0]?.body).toMatchObject({
      lines: [{ product_id: 'p-ts', unit_id: 'u-kg', quantity: 12.5, unit_cost: 5000, batch_no: 'L001', expires_at: '2030-01-31', manufactured_at: null }],
    })
  })

  it('xuất kho: chọn lô có sẵn (kèm hạn và số còn), bắt buộc có lý do, gửi mã lô', async () => {
    const server = mockApi(base({ 'POST /api/v1/stock/documents': CREATED, 'GET /api/v1/stock/documents/d-1': { json: { data: DETAIL } } }))
    renderApp('/stock/new?type=issue')
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByLabelText('Kho'), 'w-1')
    await pickProduct(user, /TS-01/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText('Số lượng'), '2')
    expect(within(line).queryByLabelText('Đơn giá nhập (đồng)')).not.toBeInTheDocument()
    expect(within(line).queryByLabelText('Số lô')).not.toBeInTheDocument() // xuất thì chỉ chọn lô có sẵn

    // Lô đã hết hạn được đánh dấu để người dùng biết.
    expect(await within(line).findByRole('option', { name: /A-CU — hạn .* còn 5 Kg — đã hết hạn/ })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))
    expect(await screen.findByText('Vui lòng chọn lý do.')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Lý do'), 'damaged')
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))
    expect(await within(line).findByText('Vui lòng chọn lô.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/stock/documents')).toHaveLength(0)

    await user.selectOptions(within(line).getByLabelText('Lô'), 'b-A-SAP')
    expect(await within(line).findByText('Đang có 10,5 Kg')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/stock/documents')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/stock/documents')[0]?.body).toEqual({
      warehouse_id: 'w-1', type: 'issue', reason: 'damaged', note: null,
      lines: [{ product_id: 'p-ts', unit_id: 'u-kg', quantity: 2, batch_id: 'b-A-SAP' }],
    })
  })

  it('điều chỉnh: nhập số dương, chọn "Giảm tồn" thì gửi số âm; lý do "Khác" bắt buộc ghi chú', async () => {
    const server = mockApi(base({ 'POST /api/v1/stock/documents': CREATED, 'GET /api/v1/stock/documents/d-1': { json: { data: DETAIL } } }))
    renderApp('/stock/new?type=adjustment')
    const user = userEvent.setup()

    expect(await screen.findByRole('heading', { name: 'Điều chỉnh tồn kho' })).toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText('Kho'), 'w-1')
    await pickProduct(user, /NPK-16/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    expect(within(line).getByLabelText('Tăng hay giảm')).toHaveValue('out')
    await user.type(within(line).getByLabelText('Số lượng'), '3')
    await user.selectOptions(screen.getByLabelText('Lý do'), 'other')
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))
    expect(await screen.findByText('Vui lòng ghi chú cụ thể khi chọn lý do "Khác".')).toBeInTheDocument()

    await user.type(screen.getByLabelText('Ghi chú'), 'Chuột cắn bao')
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))
    await waitFor(() => expect(server.to('POST', '/api/v1/stock/documents')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/stock/documents')[0]?.body).toEqual({
      warehouse_id: 'w-1', type: 'adjustment', reason: 'other', note: 'Chuột cắn bao',
      lines: [{ product_id: 'p-npk', unit_id: 'u-kg', quantity: -3 }],
    })
  })

  it('điều chỉnh tăng hàng theo dõi lô có thể khai lô mới', async () => {
    const server = mockApi(base({ 'POST /api/v1/stock/documents': CREATED, 'GET /api/v1/stock/documents/d-1': { json: { data: DETAIL } } }))
    renderApp('/stock/new?type=adjustment')
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByLabelText('Kho'), 'w-1')
    await pickProduct(user, /TS-01/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.selectOptions(within(line).getByLabelText('Tăng hay giảm'), 'in')
    await user.type(within(line).getByLabelText('Số lượng'), '4')
    await user.selectOptions(within(line).getByLabelText('Lô'), '__new__')
    await user.type(within(line).getByLabelText('Số lô'), 'L-MOI')
    await user.type(within(line).getByLabelText('Hạn dùng'), '2031-05-01')
    await user.selectOptions(screen.getByLabelText('Lý do'), 'count_correction')
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))

    await waitFor(() => expect(server.to('POST', '/api/v1/stock/documents')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/stock/documents')[0]?.body).toMatchObject({
      reason: 'count_correction', lines: [{ product_id: 'p-ts', quantity: 4, batch_no: 'L-MOI', expires_at: '2031-05-01' }],
    })
  })

  it('lỗi từ máy chủ hiện đúng dòng (không đủ tồn) và lỗi chung ở đầu form', async () => {
    mockApi(base({
      'POST /api/v1/stock/documents': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', {
        errors: { 'lines.0.quantity': ['Không đủ tồn: còn 20 Kg, cần 25 Kg.'], reason: ['Vui lòng chọn lý do.'], lines: ['Phiếu có lỗi.'] },
      }),
    }))
    renderApp('/stock/new?type=issue')
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByLabelText('Kho'), 'w-1')
    await pickProduct(user, /NPK-16/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText('Số lượng'), '25')
    await user.selectOptions(screen.getByLabelText('Lý do'), 'damaged')
    await user.click(screen.getByRole('button', { name: 'Ghi phiếu' }))

    expect(await within(line).findByText('Không đủ tồn: còn 20 Kg, cần 25 Kg.')).toBeInTheDocument()
    expect(screen.getByText('Phiếu có lỗi.')).toBeInTheDocument()
  })

  it('thêm và bỏ dòng; chỉ có một kho thì chọn sẵn; đổi sản phẩm của dòng', async () => {
    mockApi(base({}, FULL, ONE_WAREHOUSE))
    renderApp('/stock/new?type=receipt')
    const user = userEvent.setup()

    await waitFor(() => expect(screen.getByLabelText('Kho')).toHaveValue('w-1'))
    await pickProduct(user, /NPK-16/)
    await user.click(screen.getByRole('button', { name: 'Thêm dòng' }))
    expect(screen.getByRole('group', { name: 'Dòng 2' })).toBeInTheDocument()

    await user.click(within(screen.getByRole('group', { name: 'Dòng 1' })).getByRole('button', { name: 'Đổi sản phẩm' }))
    expect(within(screen.getByRole('group', { name: 'Dòng 1' })).getByPlaceholderText('Tìm theo tên, mã hoặc mã vạch')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Bỏ dòng 2' }))
    expect(screen.queryByRole('group', { name: 'Dòng 2' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Bỏ dòng/ })).not.toBeInTheDocument() // dòng cuối cùng không bỏ được
  })

  it('tìm sản phẩm theo từ khóa gửi lên máy chủ kèm chi nhánh của kho', async () => {
    const server = mockApi(base())
    renderApp('/stock/new?type=receipt')
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByLabelText('Kho'), 'w-1')
    await user.type(await screen.findByPlaceholderText('Tìm theo tên, mã hoặc mã vạch'), 'npk')
    await waitFor(() => {
      const query = new URLSearchParams((server.calls.filter((c) => c.method === 'GET' && c.path.startsWith('/api/v1/products?')).at(-1)?.path ?? '').split('?')[1])
      expect(query.get('q')).toBe('npk')
      expect(query.get('branch')).toBe('br-1')
      expect(query.get('status')).toBe('active')
    })
  })

  it('loại phiếu theo quyền: không có quyền điều chỉnh thì không chọn được và đường dẫn ?type=adjustment rơi về nhập kho', async () => {
    mockApi(base({}, RECEIVER))
    renderApp('/stock/new?type=adjustment')

    expect(await screen.findByRole('heading', { name: 'Nhập kho' })).toBeInTheDocument()
    const types = within(screen.getByRole('group', { name: 'Loại phiếu' }))
    expect(types.queryByRole('radio', { name: 'Điều chỉnh' })).not.toBeInTheDocument()
    expect(types.getByRole('radio', { name: 'Tồn đầu kỳ' })).toBeInTheDocument()
    expect(types.getByRole('radio', { name: 'Nhập kho' })).toBeChecked() // đường dẫn ?type=adjustment rơi về loại đầu tiên được phép
  })

  it('người không có quyền lập phiếu không vào được trang lập phiếu', async () => {
    mockApi(base({}, SALES))
    renderApp('/stock/new')

    expect(await screen.findByText('Bạn không có quyền truy cập')).toBeInTheDocument()
  })

  it('thủ kho (quyền chi nhánh) lập được cả nhập, xuất, điều chỉnh', async () => {
    mockApi(base({}, KEEPER))
    renderApp('/stock/new?type=adjustment')

    expect(await screen.findByRole('heading', { name: 'Điều chỉnh tồn kho' })).toBeInTheDocument()
    const types = within(screen.getByRole('group', { name: 'Loại phiếu' }))
    for (const name of ['Nhập kho', 'Xuất kho', 'Điều chỉnh', 'Tồn đầu kỳ']) expect(types.getByRole('radio', { name })).toBeInTheDocument()
    expect(types.getByRole('radio', { name: 'Điều chỉnh' })).toBeChecked()
  })
})

describe('trang Tổng quan', () => {
  const OWNER_LIKE: Permissions = { ...FULL, 'catalog.product.create': 'tenant', 'crm.customer.create': 'tenant', 'purchase.supplier.create': 'tenant' }

  it('hiện việc cần chú ý của kho (bấm sang danh sách đã lọc sẵn), lối tắt theo quyền và phiếu kho gần đây', async () => {
    const server = mockApi(base({}, OWNER_LIKE))
    const { router } = renderApp('/')
    const user = userEvent.setup()

    expect(await screen.findByText('Xin chào, Nguyễn Văn A')).toBeInTheDocument()

    const attention = await screen.findByRole('region', { name: 'Cần chú ý' })
    expect(await within(attention).findByRole('link', { name: /Sắp hết hàng/ })).toHaveAttribute('href', '/stock?stock=low')
    expect(within(attention).getByRole('link', { name: /Lô sắp hết hạn/ })).toHaveAttribute('href', '/stock?expiry=soon')
    expect(within(attention).getByRole('link', { name: /Lô đã hết hạn/ })).toHaveAttribute('href', '/stock?expiry=expired')

    const quick = screen.getByRole('region', { name: 'Làm nhanh' })
    expect(within(quick).getByRole('link', { name: 'Nhập kho' })).toHaveAttribute('href', '/stock/new?type=receipt')
    expect(within(quick).getByRole('link', { name: 'Xuất kho' })).toHaveAttribute('href', '/stock/new?type=issue')
    expect(within(quick).getByRole('link', { name: 'Thêm sản phẩm' })).toHaveAttribute('href', '/products/new')
    expect(within(quick).getByRole('link', { name: 'Thêm khách hàng' })).toHaveAttribute('href', '/customers/new')
    expect(within(quick).getByRole('link', { name: 'Thêm nhà cung cấp' })).toHaveAttribute('href', '/suppliers/new')

    expect(await screen.findByRole('link', { name: /PN0001/ })).toHaveAttribute('href', '/stock/documents/d-1')
    expect(screen.queryByText('Bắt đầu với AgriHub')).not.toBeInTheDocument() // đã có sản phẩm

    // Bấm thẻ cảnh báo: sang Tồn kho và danh sách đã lọc sẵn theo đúng loại cảnh báo.
    await user.click(within(attention).getByRole('link', { name: /Lô đã hết hạn/ }))
    await waitFor(() => expect(router.state.location.search).toBe('?expiry=expired'))
    expect(await screen.findByLabelText('Hạn dùng')).toHaveValue('expired')
    await waitFor(() => expect(server.calls.some((c) => c.path.startsWith('/api/v1/stock/products?') && c.path.includes('expiry=expired'))).toBe(true))
  })

  it('cửa hàng mới chưa có sản phẩm: hiện ba việc đầu tiên theo đúng thứ tự', async () => {
    mockApi(base({ 'GET /api/v1/products': { json: page([]) } }, OWNER_LIKE))
    renderApp('/')

    const start = await screen.findByRole('region', { name: 'Bắt đầu với AgriHub' })
    const steps = within(start).getAllByRole('link')
    expect(steps.map((step) => step.getAttribute('href'))).toEqual(['/products/new', '/stock/new?type=opening', '/customers/new'])
    expect(within(start).getByText('Thêm sản phẩm đầu tiên')).toBeInTheDocument()
  })

  it('người không có quyền xem kho không thấy cảnh báo hay phiếu kho và không gọi API kho', async () => {
    const server = mockApi(base({}, { 'catalog.product.view': 'tenant', 'catalog.product.create': 'tenant' }))
    renderApp('/')

    expect(await screen.findByRole('link', { name: 'Thêm sản phẩm' })).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Cần chú ý' })).not.toBeInTheDocument()
    expect(screen.queryByText('Phiếu kho gần đây')).not.toBeInTheDocument()
    expect(server.calls.filter((call) => call.path.startsWith('/api/v1/stock'))).toHaveLength(0)
  })

  it('tài khoản chưa được cấp quyền nào thì được dặn liên hệ chủ cửa hàng, không có khối rỗng', async () => {
    mockApi(base({}, {}))
    renderApp('/')

    expect(await screen.findByText(/chưa được cấp quyền nào/)).toBeInTheDocument()
    expect(screen.queryByRole('region')).not.toBeInTheDocument()
  })
})

describe('đổi loại phiếu và bộ lọc từ liên kết', () => {
  it('đổi loại phiếu bằng thanh chọn: tiêu đề đổi theo và ô "Lý do" xuất hiện cho phiếu xuất', async () => {
    mockApi(base())
    renderApp('/stock/new?type=receipt')
    const user = userEvent.setup()

    expect(await screen.findByRole('heading', { name: 'Nhập kho' })).toBeInTheDocument()
    expect(screen.queryByLabelText('Lý do')).not.toBeInTheDocument()

    await user.click(screen.getByRole('radio', { name: 'Xuất kho' }))
    expect(await screen.findByRole('heading', { name: 'Xuất kho' })).toBeInTheDocument()
    expect(screen.getByLabelText('Lý do')).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Xuất kho' })).toBeChecked()
  })

  it('mở Tồn kho với ?stock=low thì danh sách lọc sẵn "Sắp hết hàng" và gửi đúng tham số', async () => {
    const server = mockApi(base())
    renderApp('/stock?stock=low')

    expect(await screen.findByLabelText('Tồn kho')).toHaveValue('low')
    await waitFor(() => expect(server.calls.some((c) => c.path.startsWith('/api/v1/stock/products?') && c.path.includes('stock=low'))).toBe(true))
    expect(screen.getAllByRole('button', { name: 'Xóa bộ lọc' }).length).toBeGreaterThan(0)
  })

  it('giá trị lạ trong đường dẫn (?stock=abc) bị bỏ qua, không gửi lên máy chủ', async () => {
    const server = mockApi(base())
    renderApp('/stock?stock=abc&expiry=<script>')

    await screen.findByRole('table')
    expect(server.calls.filter((c) => c.path.startsWith('/api/v1/stock/products?')).every((c) => !c.path.includes('abc') && !c.path.includes('script'))).toBe(true)
  })
})
