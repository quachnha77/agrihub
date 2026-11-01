import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { Product } from '../catalog/api.ts'
import type { Customer } from '../crm/api.ts'
import type { StockDocument, Warehouse } from '../inventory/api.ts'
import type { Sale } from './api.ts'

type Permissions = Record<string, 'own' | 'branch' | 'tenant'>

// Chủ cửa hàng: bán, thu tiền, hủy lần thu.
const OWNER: Permissions = {
  'sales.order.view': 'tenant', 'sales.order.create': 'tenant', 'sales.order.update': 'tenant', 'sales.order.cancel': 'tenant',
  'crm.customer.view': 'tenant', 'inventory.warehouse.view': 'tenant', 'inventory.movement.view': 'tenant', 'catalog.product.view': 'tenant',
}
// Nhân viên bán hàng: bán được, chỉ xem khách của chi nhánh mình.
const SELLER: Permissions = { 'sales.order.view': 'branch', 'sales.order.create': 'branch', 'crm.customer.view': 'branch', 'inventory.warehouse.view': 'branch', 'catalog.product.view': 'branch' }
// Kế toán: chỉ xem.
const VIEWER: Permissions = { 'sales.order.view': 'tenant', 'crm.customer.view': 'tenant', 'inventory.warehouse.view': 'tenant' }

const page = <T,>(data: T[], extra: Record<string, unknown> = {}) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 }, ...extra })

const BR1 = { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính' }
const KHO1: Warehouse = { id: 'w-1', branch: BR1, code: 'KHO-01', name: 'Kho chính', address: null, status: 'active', stocktake: null }
const KHO2: Warehouse = { id: 'w-2', branch: BR1, code: 'KHO-02', name: 'Kho thuốc', address: null, status: 'active', stocktake: null }

const KG = { id: 'u-kg', name: 'Kg' }
const BAO = { id: 'u-bao', name: 'Bao' }

const customer = (id: string, name: string, limit = '1000000.00', days: number | null = 30): Customer => ({
  id, code: id.toUpperCase(), type: 'individual', type_label: 'Cá nhân', name, branch: BR1, group: null, phone: '0912345678', email: null, tax_code: null, credit_limit: limit, credit_days: days,
  notes: null, status: 'active', addresses: [], contacts: [], created_at: '2026-10-31T00:00:00Z', updated_at: '2026-10-31T00:00:00Z',
})
const NONG = customer('c-1', 'Nguyễn Văn Nông')
const KHONG_NO = customer('c-2', 'Trần Thị Mặt', '0.00', null)

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

const sale = (overrides: Partial<Sale> = {}): Sale => ({
  id: 's-1', code: 'BH0001', sold_on: '2026-10-30', due_on: '2026-11-29', is_overdue: false, note: 'Lấy tại quầy', customer: { id: 'c-1', code: 'C-1', name: NONG.name },
  warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, branch: BR1, goods_total: '1550000.00', discount: '50000.00', total: '1500000.00', paid_amount: '500000.00', balance: '1000000.00',
  payment_status: 'partial', stock_document: { id: 'd-1', code: 'PX0001' }, created_by: { id: 'u-1', name: 'Nguyễn Văn A' }, created_at: '2026-10-30T03:00:00Z', line_count: 2, ...overrides,
})
const PAID_ONE = sale({ id: 's-2', code: 'BH0002', customer: null, note: null, due_on: null, goods_total: '155000.00', discount: '0.00', total: '155000.00', paid_amount: '155000.00', balance: '0.00', payment_status: 'paid', line_count: 1 })
const OVERDUE_ONE = sale({ id: 's-3', code: 'BH0003', customer: { id: 'c-2', code: 'C-2', name: KHONG_NO.name }, due_on: '2026-09-01', is_overdue: true, payment_status: 'unpaid', paid_amount: '0.00', balance: '1500000.00' })
const DETAIL = sale({
  lines: [
    { product: { id: 'p-npk', sku: 'NPK-16', name: 'Phân NPK 16-16-8', base_unit: 'Kg' }, batch: null, unit: 'Bao', quantity: '2.000', unit_price: '750000.00', line_total: '1500000.00', cost_amount: '1000000.00' },
    { product: { id: 'p-ts', sku: 'TS-01', name: 'Thuốc trừ sâu 25WG', base_unit: 'Kg' }, batch: { id: 'b-1', batch_no: 'L-GAN', expires_at: '2030-01-31' }, unit: 'Kg', quantity: '5.000', unit_price: '10000.00', line_total: '50000.00', cost_amount: '25000.00' },
  ],
  payments: [
    { id: 'pay-1', amount: '300000.00', method: 'bank_transfer', paid_on: '2026-10-30', note: null, created_by: { id: 'u-1', name: 'Nguyễn Văn A' }, created_at: '2026-10-30T03:00:00Z', voided: null },
    { id: 'pay-0', amount: '200000.00', method: 'cash', paid_on: '2026-10-29', note: 'Ghi nhầm', created_by: null, created_at: '2026-10-29T03:00:00Z', voided: { at: '2026-10-29T04:00:00Z', by: { id: 'u-1', name: 'Nguyễn Văn A' }, reason: 'Thu nhầm phiếu' } },
  ],
})

const base = (extra: Record<string, unknown> = {}, permissions: Permissions = OWNER, warehouses: Warehouse[] = [KHO1, KHO2]) =>
  ({
    'GET /api/v1/auth/me': meOk(tenantUser({ permissions })),
    'GET /api/v1/branches': { json: page([{ id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính', phone: null, address: null, status: 'active' }]) },
    'GET /api/v1/warehouses': { json: page(warehouses) },
    'GET /api/v1/customers': { json: page([NONG, KHONG_NO]) },
    'GET /api/v1/products/types': { json: { data: [{ key: 'fertilizer', label: 'Phân bón', track_batch_required: false, fields: [] }, { key: 'pesticide', label: 'Thuốc bảo vệ thực vật', track_batch_required: true, fields: [] }], meta: { max_units: 8 } } },
    'GET /api/v1/products': { json: page([NPK, DRUG]) },
    'GET /api/v1/sales': { json: page([sale(), PAID_ONE, OVERDUE_ONE], { summary: { total: '3155000.00', paid_amount: '655000.00', balance: '2500000.00' } }) },
    'GET /api/v1/sales/s-1': { json: { data: DETAIL } },
    ...extra,
  }) as never

async function pickProduct(user: ReturnType<typeof userEvent.setup>, name: RegExp) {
  await user.click(await screen.findByRole('button', { name }))
}

describe('bán hàng: danh sách', () => {
  it('liệt kê phiếu kèm khách (hoặc khách lẻ), còn nợ, tình trạng thu (kể cả quá hạn) và tổng theo bộ lọc; có nút bán hàng', async () => {
    mockApi(base())
    renderApp('/sales')

    expect(await screen.findByRole('heading', { name: 'Bán hàng' })).toBeInTheDocument()
    const table = await screen.findByRole('table')
    expect(within(table).getByRole('link', { name: 'BH0001' })).toHaveAttribute('href', '/sales?dialog=sale:s-1')
    expect(within(table).getByText('Nguyễn Văn Nông')).toBeInTheDocument()
    expect(within(table).getByText('Khách lẻ')).toBeInTheDocument()
    expect(within(table).getByText('Thu một phần')).toBeInTheDocument()
    expect(within(table).getByText('Đã thu đủ')).toBeInTheDocument()
    expect(within(table).getByText('Quá hạn')).toBeInTheDocument()

    const summary = screen.getByLabelText('Tổng theo bộ lọc')
    expect(within(summary).getByText(/3\.155\.000/)).toBeInTheDocument()
    expect(within(summary).getByText(/655\.000/)).toBeInTheDocument()
    expect(within(summary).getByText(/2\.500\.000/)).toBeInTheDocument()

    expect(screen.getAllByRole('link', { name: 'Bán hàng' }).map((link) => link.getAttribute('href'))).toEqual(expect.arrayContaining(['/sales', '/sales?dialog=sale-new']))
  })

  it('người chỉ có quyền xem thấy danh sách nhưng không có nút bán hàng', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/sales')

    await screen.findByRole('table')
    expect(screen.getAllByRole('link', { name: 'Bán hàng' }).map((link) => link.getAttribute('href'))).not.toContain('/sales?dialog=sale-new')
  })

  it('người không có quyền xem bán hàng không thấy mục menu và bị chặn khi vào thẳng đường dẫn', async () => {
    mockApi(base({}, { 'catalog.product.view': 'tenant' }))
    renderApp('/sales')

    await waitFor(() => expect(screen.queryByRole('table')).not.toBeInTheDocument())
    expect(screen.queryByRole('link', { name: 'Bán hàng' })).not.toBeInTheDocument()
  })

  it('chưa có phiếu nào: trạng thái rỗng kèm nút bán hàng', async () => {
    mockApi(base({ 'GET /api/v1/sales': { json: page([], { summary: { total: '0.00', paid_amount: '0.00', balance: '0.00' } }) } }))
    renderApp('/sales')

    expect(await screen.findByText('Chưa có phiếu bán hàng nào.')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Bán hàng' }).map((link) => link.getAttribute('href')).filter((href) => href === '/sales?dialog=sale-new').length).toBeGreaterThanOrEqual(2)
  })

  it('lọc theo khách và "nợ quá hạn" gửi tham số lên máy chủ', async () => {
    const api = mockApi(base())
    const user = userEvent.setup()
    renderApp('/sales')

    await screen.findByRole('table')
    await user.selectOptions(screen.getByLabelText('Khách hàng'), 'c-1')
    await user.selectOptions(screen.getByLabelText('Thanh toán'), 'overdue')

    await waitFor(() => expect(api.calls.some((call) => call.path.startsWith('/api/v1/sales?') && call.path.includes('customer=c-1') && call.path.includes('payment_status=overdue'))).toBe(true))
  })
})

describe('bán hàng: lập phiếu', () => {
  it('khách lẻ trả đủ: đơn giá gợi ý là giá tiền mặt theo đơn vị, tính thành tiền, bớt tiền, gửi đúng nội dung, báo thành công kèm liên kết xem phiếu', async () => {
    const api = mockApi(base({ 'POST /api/v1/sales': { status: 201, json: { data: DETAIL } } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale-new')

    expect(await screen.findByRole('heading', { name: 'Bán hàng' })).toBeInTheDocument()
    await user.selectOptions(await screen.findByLabelText(/Kho bán/), 'w-1')
    expect(screen.getByText('Khách lẻ (không ghi tên)')).toBeInTheDocument()
    await pickProduct(user, /NPK-16/)

    const line = screen.getByRole('group', { name: 'Dòng 1' })
    expect(within(line).getByLabelText(/Đơn giá bán/)).toHaveValue('15500') // giá tiền mặt theo Kg
    await user.selectOptions(within(line).getByLabelText(/Đơn vị/), 'u-bao')
    expect(within(line).getByLabelText(/Đơn giá bán/)).toHaveValue('750000') // đổi đơn vị thì đổi giá gợi ý
    await user.type(within(line).getByLabelText(/Số lượng/), '2')
    expect(await within(line).findByText(/1\.500\.000/)).toBeInTheDocument()

    await user.type(screen.getByLabelText(/Bớt tiền/), '50000')
    const totals = screen.getByLabelText('Tổng tiền')
    expect(within(totals).getByText(/1\.500\.000/)).toBeInTheDocument()
    expect(within(totals).getAllByText(/1\.450\.000/)).toHaveLength(2) // khách phải trả và thu ngay

    await user.click(screen.getByRole('button', { name: 'Bán hàng' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/sales')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/sales')[0].body).toMatchObject({
      warehouse_id: 'w-1', customer_id: null, note: null, discount: 50000, paid_amount: 1450000, payment_method: 'cash',
      lines: [{ product_id: 'p-npk', unit_id: 'u-bao', quantity: 2, unit_price: 750000 }],
    })
    expect(await screen.findByText('Đã bán hàng: phiếu BH0001. Tồn kho đã được cập nhật.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Xem phiếu' })).toHaveAttribute('href', '/notifications?dialog=sale:s-1')
  })

  it('ghi nợ: đổi sang giá công nợ, bắt buộc chọn khách, hiện hạn mức của khách, gửi số trả trước', async () => {
    const api = mockApi(base({ 'POST /api/v1/sales': { status: 201, json: { data: DETAIL } } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale-new')

    await user.selectOptions(await screen.findByLabelText(/Kho bán/), 'w-1')
    await pickProduct(user, /NPK-16/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText(/Số lượng/), '10')

    await user.click(screen.getByRole('radio', { name: /Ghi nợ/ }))
    expect(within(line).getByLabelText(/Đơn giá bán/)).toHaveValue('16000') // giá công nợ
    expect(screen.getByLabelText(/Khách trả trước/)).toBeInTheDocument()

    // Chưa chọn khách thì không ghi nợ được.
    await user.click(screen.getByRole('button', { name: 'Bán hàng' }))
    expect(await screen.findByText('Ghi nợ cần chọn khách hàng.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/sales')).toHaveLength(0)

    await user.click(screen.getByRole('button', { name: 'Chọn khách' }))
    await user.click(await screen.findByRole('button', { name: /Nguyễn Văn Nông/ }))
    expect(screen.getByText(/Hạn mức nợ 1\.000\.000/)).toBeInTheDocument()
    expect(screen.getByText(/nợ tối đa 30 ngày/)).toBeInTheDocument()

    await user.type(screen.getByLabelText(/Khách trả trước/), '60000')
    const totals = screen.getByLabelText('Tổng tiền')
    expect(within(totals).getAllByText(/160\.000/)).toHaveLength(2) // tiền hàng và khách phải trả
    expect(within(totals).getByText(/100\.000/)).toBeInTheDocument() // ghi nợ

    await user.click(screen.getByRole('button', { name: 'Bán hàng' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/sales')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/sales')[0].body).toMatchObject({ customer_id: 'c-1', discount: 0, paid_amount: 60000, payment_method: 'cash', lines: [{ quantity: 10, unit_price: 16000 }] })
  })

  it('khách chưa được ghi nợ thì ghi rõ; bỏ chọn khách quay về khách lẻ; giá người bán đã sửa tay không bị đổi khi đổi cách trả', async () => {
    mockApi(base())
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale-new')

    await user.selectOptions(await screen.findByLabelText(/Kho bán/), 'w-1')
    await user.click(screen.getByRole('button', { name: 'Chọn khách' }))
    await user.click(await screen.findByRole('button', { name: /Trần Thị Mặt/ }))
    expect(screen.getByText('Khách này chưa được ghi nợ: phải trả đủ tiền.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Bỏ chọn khách hàng' }))
    expect(screen.getByText('Khách lẻ (không ghi tên)')).toBeInTheDocument()

    await pickProduct(user, /NPK-16/)
    const price = within(screen.getByRole('group', { name: 'Dòng 1' })).getByLabelText(/Đơn giá bán/)
    await user.clear(price)
    await user.type(price, '14000')
    await user.click(screen.getByRole('radio', { name: /Ghi nợ/ }))
    expect(price).toHaveValue('14000')
  })

  it('chưa chọn gì thì báo từng ô và không gọi máy chủ; bớt nhiều hơn tiền hàng bị chặn', async () => {
    const api = mockApi(base())
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale-new')

    await screen.findByLabelText(/Kho bán/)
    expect(screen.getByText('Chọn kho trước để chọn sản phẩm của chi nhánh đó.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Bán hàng' }))
    expect(await screen.findByText('Vui lòng chọn kho bán.')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText(/Kho bán/), 'w-1')
    await pickProduct(user, /NPK-16/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText(/Số lượng/), '1')
    await user.type(screen.getByLabelText(/Bớt tiền/), '15501')
    await user.click(screen.getByRole('button', { name: 'Bán hàng' }))

    expect(await screen.findByText('Số tiền bớt không được lớn hơn tiền hàng.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/sales')).toHaveLength(0)
  })

  it('sản phẩm theo dõi lô báo "tự chọn lô" và không bắt nhập lô', async () => {
    const api = mockApi(base({ 'POST /api/v1/sales': { status: 201, json: { data: DETAIL } } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale-new')

    await user.selectOptions(await screen.findByLabelText(/Kho bán/), 'w-2')
    await pickProduct(user, /TS-01/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    expect(within(line).getByText('Tự chọn lô')).toBeInTheDocument()
    expect(within(line).queryByLabelText(/Số lô/)).not.toBeInTheDocument()
    await user.type(within(line).getByLabelText(/Số lượng/), '8')
    await user.click(screen.getByRole('button', { name: 'Bán hàng' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/sales')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/sales')[0].body).toMatchObject({ lines: [{ product_id: 'p-ts', quantity: 8 }] })
    expect(api.to('POST', '/api/v1/sales')[0].body).not.toHaveProperty('lines.0.batch_id')
  })

  it('nhân viên bán hàng lập được phiếu (không cần quyền thu tiền)', async () => {
    mockApi(base({}, SELLER))
    renderApp('/notifications?dialog=sale-new')

    expect(await screen.findByLabelText(/Kho bán/)).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /Khách trả đủ/ })).toBeChecked()
  })

  it('lỗi 422 của máy chủ hiện đúng ô: hạn mức công nợ ở khách, thiếu tồn ở dòng, kho đang kiểm kê ở kho', async () => {
    mockApi(base({
      'POST /api/v1/sales': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', {
        errors: {
          customer_id: ['Vượt hạn mức công nợ: khách đang nợ 700.000 đ, phiếu này ghi nợ thêm 400.000 đ, hạn mức 1.000.000 đ.'],
          'lines.0.quantity': ['Không đủ tồn: còn 20 Kg, cần 25 Kg.'],
          warehouse_id: ['Kho này đang kiểm kê (KK0007): hoàn tất hoặc hủy đợt kiểm kê trước khi ghi phiếu kho.'],
        },
      }),
    }, OWNER, [{ ...KHO2, stocktake: { id: 's-1', code: 'KK0001' } }, KHO1]))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale-new')

    const warehouse = await screen.findByLabelText(/Kho bán/)
    expect(within(warehouse).getByRole('option', { name: 'Kho thuốc (đang kiểm kê)' })).toBeDisabled()
    await user.selectOptions(warehouse, 'w-1')
    await pickProduct(user, /NPK-16/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText(/Số lượng/), '25')
    await user.click(screen.getByRole('button', { name: 'Bán hàng' }))

    expect(await within(line).findByText('Không đủ tồn: còn 20 Kg, cần 25 Kg.')).toBeInTheDocument()
    expect(screen.getByText(/Vượt hạn mức công nợ: khách đang nợ 700\.000 đ/)).toBeInTheDocument()
    expect(screen.getByText(/Kho này đang kiểm kê \(KK0007\)/)).toBeInTheDocument()
  })
})

describe('bán hàng: xem phiếu và thu tiền', () => {
  it('hiện tiền hàng, bớt, phải trả, còn nợ và hạn trả, các dòng (kèm lô tự chọn và giá vốn), các lần thu (kể cả lần đã hủy) và liên kết phiếu xuất kho', async () => {
    mockApi(base())
    renderApp('/notifications?dialog=sale:s-1')

    expect(await screen.findByRole('heading', { name: 'Phiếu bán hàng' })).toBeInTheDocument()
    const dialog = screen.getByRole('dialog')
    expect(await within(dialog).findByText(/BH0001 · Nguyễn Văn Nông/)).toBeInTheDocument()
    expect(within(dialog).getByText('Thu một phần')).toBeInTheDocument()
    expect(within(dialog).getByText('Hạn trả nợ')).toBeInTheDocument()
    expect(within(dialog).getByText('Đã thu 500.000 ₫')).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: 'PX0001' })).toHaveAttribute('href', '/notifications?dialog=stock-doc:d-1')

    const [lines, payments] = within(dialog).getAllByRole('table')
    expect(within(lines).getByText('2 Bao')).toBeInTheDocument()
    expect(within(lines).getByText('L-GAN')).toBeInTheDocument()
    expect(within(lines).getByText('Giá vốn')).toBeInTheDocument()
    expect(within(payments).getByText('Chuyển khoản')).toBeInTheDocument()
    expect(within(payments).getByText('Đã hủy')).toBeInTheDocument()
    expect(within(payments).getByText('Thu nhầm phiếu')).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: 'Thu tiền' })).toHaveAttribute('href', '/notifications?dialog=sale-pay:s-1')
  })

  it('khách lẻ hiện "Khách lẻ"; người không được xem giá vốn thì không có cột giá vốn', async () => {
    const noCost = { ...DETAIL, lines: DETAIL.lines?.map(({ cost_amount: _cost, ...line }) => line) }
    mockApi(base({ 'GET /api/v1/sales/s-1': { json: { data: { ...noCost, customer: null } } } }))
    renderApp('/notifications?dialog=sale:s-1')

    expect(await screen.findByText(/BH0001 · Khách lẻ/)).toBeInTheDocument()
    expect(screen.queryByText('Giá vốn')).not.toBeInTheDocument()
  })

  it('người không có quyền thu/hủy không thấy nút Thu tiền và Hủy lần thu', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/notifications?dialog=sale:s-1')

    await screen.findByText(/BH0001 · /)
    expect(screen.queryByRole('link', { name: 'Thu tiền' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Hủy lần thu' })).not.toBeInTheDocument()
  })

  it('hủy lần thu: bắt buộc nêu lý do rồi gửi lên máy chủ', async () => {
    const after = { ...DETAIL, paid_amount: '200000.00', balance: '1300000.00' }
    const api = mockApi(base({ 'POST /api/v1/sales/s-1/payments/pay-1/void': { json: { data: after } } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale:s-1')

    await user.click(await screen.findByRole('button', { name: 'Hủy lần thu' }))
    const confirm = await screen.findByRole('heading', { name: 'Hủy lần thu tiền' })
    const box = confirm.closest('[role=dialog]') as HTMLElement
    await user.click(within(box).getByRole('button', { name: 'Hủy lần thu' }))
    expect(await within(box).findByText('Vui lòng nhập lý do (ít nhất 2 ký tự).')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/sales/s-1/payments/pay-1/void')).toHaveLength(0)

    await user.type(within(box).getByLabelText('Lý do hủy'), 'Ghi nhầm số tiền')
    await user.click(within(box).getByRole('button', { name: 'Hủy lần thu' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/sales/s-1/payments/pay-1/void')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/sales/s-1/payments/pay-1/void')[0].body).toEqual({ reason: 'Ghi nhầm số tiền' })
    expect(await screen.findByText('Đã hủy lần thu tiền.')).toBeInTheDocument()
  })

  it('không tìm thấy phiếu (hoặc không có quyền xem) thì báo rõ', async () => {
    mockApi(base({ 'GET /api/v1/sales/s-9': problem(404, 'not_found', 'Không tìm thấy.') }))
    renderApp('/notifications?dialog=sale:s-9')

    expect(await screen.findByText('Không tìm thấy phiếu này hoặc bạn không có quyền xem.')).toBeInTheDocument()
  })

  it('popup thu tiền: mặc định số khách còn nợ, không cho vượt, gửi đúng nội dung rồi quay về chi tiết phiếu', async () => {
    const after = { ...DETAIL, paid_amount: '1500000.00', balance: '0.00', payment_status: 'paid' as const }
    const api = mockApi(base({ 'POST /api/v1/sales/s-1/payments': { json: { data: after } } }))
    const user = userEvent.setup()
    const { router } = renderApp('/notifications?dialog=sale-pay:s-1')

    expect(await screen.findByRole('heading', { name: 'Thu tiền của khách' })).toBeInTheDocument()
    const amount = await screen.findByLabelText(/Số tiền thu/)
    expect(amount).toHaveValue('1000000')
    expect(screen.getByText(/Khách còn nợ 1\.000\.000/)).toBeInTheDocument()

    await user.clear(amount)
    await user.type(amount, '1000001')
    await user.click(screen.getByRole('button', { name: 'Ghi thu tiền' }))
    expect(await screen.findByText('Số tiền thu không được lớn hơn số khách còn nợ.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/sales/s-1/payments')).toHaveLength(0)

    await user.clear(amount)
    await user.type(amount, '1000000')
    await user.selectOptions(screen.getByLabelText(/Hình thức thu/), 'bank_transfer')
    await user.type(screen.getByLabelText('Ghi chú'), 'Khách chuyển khoản')
    await user.click(screen.getByRole('button', { name: 'Ghi thu tiền' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/sales/s-1/payments')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/sales/s-1/payments')[0].body).toMatchObject({ amount: 1000000, method: 'bank_transfer', note: 'Khách chuyển khoản' })
    await waitFor(() => expect(new URLSearchParams(router.state.location.search).get('dialog')).toBe('sale:s-1'))
  })

  it('máy chủ từ chối số tiền (người khác vừa thu) thì báo ngay ở ô số tiền', async () => {
    mockApi(base({
      'POST /api/v1/sales/s-1/payments': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', { errors: { amount: ['Số tiền thu (1.000.000 đ) vượt quá số khách còn nợ của phiếu (100.000 đ).'] } }),
    }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale-pay:s-1')

    await screen.findByLabelText(/Số tiền thu/)
    await user.click(screen.getByRole('button', { name: 'Ghi thu tiền' }))

    expect(await screen.findByText(/vượt quá số khách còn nợ của phiếu/)).toBeInTheDocument()
  })

  it('phiếu khách đã trả đủ thì popup thu tiền báo đã trả đủ', async () => {
    mockApi(base({ 'GET /api/v1/sales/s-2': { json: { data: { ...PAID_ONE, lines: [], payments: [] } } } }))
    renderApp('/notifications?dialog=sale-pay:s-2')

    expect(await screen.findByText('Phiếu này khách đã trả đủ.')).toBeInTheDocument()
  })

  it('phiếu kho do bán hàng sinh ra có liên kết về phiếu bán hàng và ghi lý do "Bán hàng"', async () => {
    const doc: StockDocument = {
      id: 'd-1', code: 'PX0001', type: 'issue', reason: 'sale', note: 'Bán hàng BH0001', warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, branch: BR1,
      sale: { id: 's-1', code: 'BH0001' }, performed_by: null, performed_at: '2026-10-31T03:00:00Z', lines: [],
    }
    mockApi(base({ 'GET /api/v1/stock/documents/d-1': { json: { data: doc } } }))
    renderApp('/notifications?dialog=stock-doc:d-1')

    expect(await screen.findByRole('link', { name: 'BH0001' })).toHaveAttribute('href', '/notifications?dialog=sale:s-1')
    expect(within(screen.getByRole('dialog')).getByText('Bán hàng')).toBeInTheDocument() // lý do của phiếu kho
  })
})
