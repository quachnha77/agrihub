import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { Product } from '../catalog/api.ts'
import type { Supplier } from '../crm/api.ts'
import type { StockDocument, Warehouse } from '../inventory/api.ts'
import type { Purchase } from './api.ts'

type Permissions = Record<string, 'own' | 'branch' | 'tenant'>

// Chủ cửa hàng: lập phiếu, trả tiền, hủy lần trả.
const OWNER: Permissions = {
  'purchase.order.view': 'tenant', 'purchase.order.create': 'tenant', 'purchase.order.update': 'tenant', 'purchase.order.cancel': 'tenant',
  'purchase.supplier.view': 'tenant', 'inventory.warehouse.view': 'tenant', 'inventory.movement.view': 'tenant', 'catalog.product.view': 'tenant',
}
// Thủ kho: lập phiếu nhưng không trả tiền, không hủy.
const KEEPER: Permissions = { 'purchase.order.view': 'branch', 'purchase.order.create': 'branch', 'purchase.supplier.view': 'branch', 'inventory.warehouse.view': 'branch', 'catalog.product.view': 'branch' }
// Kế toán: chỉ xem.
const VIEWER: Permissions = { 'purchase.order.view': 'tenant', 'purchase.supplier.view': 'tenant', 'inventory.warehouse.view': 'tenant' }

const page = <T,>(data: T[], extra: Record<string, unknown> = {}) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 }, ...extra })

const BR1 = { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính' }
const KHO1: Warehouse = { id: 'w-1', branch: BR1, code: 'KHO-01', name: 'Kho chính', address: null, status: 'active', stocktake: null }
const KHO2: Warehouse = { id: 'w-2', branch: BR1, code: 'KHO-02', name: 'Kho thuốc', address: null, status: 'active', stocktake: null }

const KG = { id: 'u-kg', name: 'Kg' }
const BAO = { id: 'u-bao', name: 'Bao' }

const supplier = (id: string, name: string, status: 'active' | 'inactive' = 'active'): Supplier => ({
  id, code: id.toUpperCase(), name, tax_code: null, phone: null, email: null, address: null, payment_terms_days: null, notes: null, status, contacts: [], created_at: '2026-10-31T00:00:00Z', updated_at: '2026-10-31T00:00:00Z',
})
const NCC1 = supplier('s-1', 'Công ty Phân bón Bình Điền')
const NCC2 = supplier('s-2', 'Đại lý Thuốc Xanh')

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

const purchase = (overrides: Partial<Purchase> = {}): Purchase => ({
  id: 'p-1', code: 'NH0001', supplier_ref: 'HD-0042', received_on: '2026-10-30', note: 'Giao tận kho', supplier: { id: 's-1', code: 'S-1', name: NCC1.name },
  warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, branch: BR1, goods_total: '3300000.00', extra_cost: '100000.00', paid_amount: '1000000.00', balance: '2300000.00',
  payment_status: 'partial', stock_document: { id: 'd-1', code: 'PN0001' }, created_by: { id: 'u-1', name: 'Nguyễn Văn A' }, created_at: '2026-10-30T03:00:00Z', line_count: 2, ...overrides,
})
const PAID_ONE = purchase({ id: 'p-2', code: 'NH0002', supplier: { id: 's-2', code: 'S-2', name: NCC2.name }, supplier_ref: null, goods_total: '500000.00', paid_amount: '500000.00', balance: '0.00', payment_status: 'paid', line_count: 1 })
const DETAIL = purchase({
  lines: [
    { product: { id: 'p-npk', sku: 'NPK-16', name: 'Phân NPK 16-16-8', base_unit: 'Kg' }, batch: null, unit: 'Bao', quantity: '4.000', unit_cost: '700000.0000', line_total: '2800000.00', extra_allocated: '84848.00', cost: '721212.0000' },
    { product: { id: 'p-ts', sku: 'TS-01', name: 'Thuốc trừ sâu 25WG', base_unit: 'Kg' }, batch: { id: 'b-1', batch_no: 'L001', expires_at: '2030-01-31' }, unit: 'Kg', quantity: '10.000', unit_cost: '50000.0000', line_total: '500000.00', extra_allocated: '15152.00', cost: '51515.0000' },
  ],
  payments: [
    { id: 'pay-1', amount: '600000.00', method: 'bank_transfer', paid_on: '2026-10-30', note: null, created_by: { id: 'u-1', name: 'Nguyễn Văn A' }, created_at: '2026-10-30T03:00:00Z', voided: null },
    { id: 'pay-0', amount: '400000.00', method: 'cash', paid_on: '2026-10-29', note: 'Ghi nhầm', created_by: null, created_at: '2026-10-29T03:00:00Z', voided: { at: '2026-10-29T04:00:00Z', by: { id: 'u-1', name: 'Nguyễn Văn A' }, reason: 'Trả nhầm phiếu' } },
  ],
})

const base = (extra: Record<string, unknown> = {}, permissions: Permissions = OWNER, warehouses: Warehouse[] = [KHO1, KHO2]) =>
  ({
    'GET /api/v1/auth/me': meOk(tenantUser({ permissions })),
    'GET /api/v1/branches': { json: page([{ id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính', phone: null, address: null, status: 'active' }]) },
    'GET /api/v1/warehouses': { json: page(warehouses) },
    'GET /api/v1/suppliers': { json: page([NCC1, NCC2]) },
    'GET /api/v1/products/types': { json: { data: [{ key: 'fertilizer', label: 'Phân bón', track_batch_required: false, fields: [] }, { key: 'pesticide', label: 'Thuốc bảo vệ thực vật', track_batch_required: true, fields: [] }], meta: { max_units: 8 } } },
    'GET /api/v1/products': { json: page([NPK, DRUG]) },
    'GET /api/v1/purchases': { json: page([purchase(), PAID_ONE], { summary: { goods_total: '3800000.00', paid_amount: '1500000.00', balance: '2300000.00' } }) },
    'GET /api/v1/purchases/p-1': { json: { data: DETAIL } },
    ...extra,
  }) as never

async function pickProduct(user: ReturnType<typeof userEvent.setup>, name: RegExp) {
  await user.click(await screen.findByRole('button', { name }))
}

describe('nhập hàng: danh sách', () => {
  it('liệt kê phiếu kèm nhà cung cấp, còn nợ, tình trạng trả và tổng theo bộ lọc; có tab Nhập hàng', async () => {
    mockApi(base())
    renderApp('/purchases')

    expect(await screen.findByRole('heading', { name: 'Nhập hàng' })).toBeInTheDocument()
    const table = await screen.findByRole('table')
    expect(within(table).getByRole('link', { name: 'NH0001' })).toHaveAttribute('href', '/purchases?dialog=purchase:p-1')
    expect(within(table).getByText('Công ty Phân bón Bình Điền')).toBeInTheDocument()
    expect(within(table).getByText('HD-0042')).toBeInTheDocument()
    expect(within(table).getByText('Trả một phần')).toBeInTheDocument()
    expect(within(table).getByText('Đã trả đủ')).toBeInTheDocument()
    expect(within(table).getByText(/2\.300\.000/)).toBeInTheDocument()

    const summary = screen.getByLabelText('Tổng theo bộ lọc')
    expect(within(summary).getByText(/3\.800\.000/)).toBeInTheDocument()
    expect(within(summary).getByText(/1\.500\.000/)).toBeInTheDocument()
    expect(within(summary).getAllByText(/2\.300\.000/)).toHaveLength(1)

    const hrefs = screen.getAllByRole('link', { name: 'Nhập hàng' }).map((link) => link.getAttribute('href')).sort()
    expect(hrefs).toEqual(['/purchases', '/purchases?dialog=purchase-new']) // tab và nút lập phiếu
  })

  it('trang Nhà cung cấp cũng có thanh tab sang Nhập hàng', async () => {
    mockApi(base({ 'GET /api/v1/suppliers': { json: page([NCC1]) } }))
    renderApp('/suppliers')

    const nav = await screen.findByRole('navigation', { name: 'Mua hàng' })
    expect(within(nav).getByRole('link', { name: 'Nhập hàng' })).toHaveAttribute('href', '/purchases')
  })

  it('người chỉ có quyền xem thấy danh sách nhưng không có nút nhập hàng', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/purchases')

    await screen.findByRole('table')
    expect(screen.getAllByRole('link', { name: 'Nhập hàng' })).toHaveLength(1) // chỉ còn tab
  })

  it('chưa có phiếu nào: trạng thái rỗng kèm nút nhập hàng', async () => {
    mockApi(base({ 'GET /api/v1/purchases': { json: page([], { summary: { goods_total: '0.00', paid_amount: '0.00', balance: '0.00' } }) } }))
    renderApp('/purchases')

    expect(await screen.findByText('Chưa có phiếu nhập hàng nào.')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Nhập hàng' })).toHaveLength(3) // tab + nút trên đầu trang + nút trong trạng thái rỗng
  })

  it('lọc theo nhà cung cấp và tình trạng trả gửi tham số lên máy chủ', async () => {
    const api = mockApi(base())
    const user = userEvent.setup()
    renderApp('/purchases')

    await screen.findByRole('table')
    await user.selectOptions(screen.getByLabelText('Nhà cung cấp'), 's-1')
    await user.selectOptions(screen.getByLabelText('Thanh toán'), 'open')

    await waitFor(() => expect(api.calls.some((call) => call.path.startsWith('/api/v1/purchases?') && call.path.includes('supplier=s-1') && call.path.includes('payment_status=open'))).toBe(true))
  })
})

describe('nhập hàng: lập phiếu', () => {
  it('chọn nhà cung cấp, kho, hàng (Bao) rồi nhập: hiện thành tiền và còn nợ, gửi đúng nội dung, báo thành công kèm liên kết xem phiếu', async () => {
    const api = mockApi(base({ 'POST /api/v1/purchases': { status: 201, json: { data: DETAIL } } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=purchase-new')

    expect(await screen.findByRole('heading', { name: 'Nhập hàng' })).toBeInTheDocument()
    await user.selectOptions(await screen.findByLabelText(/Nhà cung cấp/), 's-1')
    await user.selectOptions(screen.getByLabelText(/Kho nhận/), 'w-1')
    await user.type(screen.getByLabelText(/Số chứng từ nhà cung cấp/), 'HD-0042')
    await pickProduct(user, /NPK-16/)

    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.selectOptions(within(line).getByLabelText(/Đơn vị/), 'u-bao')
    await user.type(within(line).getByLabelText(/Số lượng/), '4')
    await user.type(within(line).getByLabelText(/Đơn giá nhập/), '700000')
    expect(await within(line).findByText(/2\.800\.000/)).toBeInTheDocument() // thành tiền

    await user.type(screen.getByLabelText(/Chi phí kèm theo/), '100000')
    await user.type(screen.getByLabelText(/Trả ngay/), '1000000')
    await user.selectOptions(screen.getByLabelText('Hình thức trả'), 'bank_transfer')
    const totals = screen.getByLabelText('Tổng tiền')
    expect(within(totals).getAllByText(/1\.000\.000/)).toHaveLength(1)
    expect(within(totals).getByText(/1\.800\.000/)).toBeInTheDocument() // còn nợ nhà cung cấp, không tính chi phí kèm theo

    await user.click(screen.getByRole('button', { name: 'Nhập hàng' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/purchases')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/purchases')[0].body).toMatchObject({
      warehouse_id: 'w-1', supplier_id: 's-1', supplier_ref: 'HD-0042', note: null, extra_cost: 100000, paid_amount: 1000000, payment_method: 'bank_transfer',
      lines: [{ product_id: 'p-npk', unit_id: 'u-bao', quantity: 4, unit_cost: 700000 }],
    })
    expect(await screen.findByText('Đã nhập hàng: phiếu NH0001. Tồn kho đã được cập nhật.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Xem phiếu' })).toHaveAttribute('href', '/notifications?dialog=purchase:p-1')
  })

  it('chưa chọn gì thì báo từng ô và không gọi máy chủ; trả nhiều hơn tiền hàng bị chặn', async () => {
    const api = mockApi(base())
    const user = userEvent.setup()
    renderApp('/notifications?dialog=purchase-new')

    await screen.findByLabelText(/Nhà cung cấp/)
    expect(screen.getByText('Chọn kho trước để chọn sản phẩm của chi nhánh đó.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Nhập hàng' }))
    expect(await screen.findByText('Vui lòng chọn nhà cung cấp.')).toBeInTheDocument()
    expect(screen.getByText('Vui lòng chọn kho nhận.')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText(/Nhà cung cấp/), 's-1')
    await user.selectOptions(screen.getByLabelText(/Kho nhận/), 'w-1')
    await pickProduct(user, /NPK-16/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText(/Số lượng/), '10')
    await user.type(within(line).getByLabelText(/Đơn giá nhập/), '1000')
    await user.type(screen.getByLabelText(/Trả ngay/), '10001')
    await user.click(screen.getByRole('button', { name: 'Nhập hàng' }))

    expect(await screen.findByText('Số tiền trả không được lớn hơn tiền hàng.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/purchases')).toHaveLength(0)
  })

  it('sản phẩm theo dõi lô phải khai số lô và hạn dùng; gửi kèm lô', async () => {
    const api = mockApi(base({ 'POST /api/v1/purchases': { status: 201, json: { data: DETAIL } } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=purchase-new')

    await user.selectOptions(await screen.findByLabelText(/Nhà cung cấp/), 's-2')
    await user.selectOptions(screen.getByLabelText(/Kho nhận/), 'w-2')
    await pickProduct(user, /TS-01/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText(/Số lượng/), '10')
    await user.type(within(line).getByLabelText(/Đơn giá nhập/), '50000')
    await user.click(screen.getByRole('button', { name: 'Nhập hàng' }))

    expect(await within(line).findByText('Sản phẩm này theo dõi lô: vui lòng nhập số lô.')).toBeInTheDocument()
    expect(within(line).getByText('Sản phẩm này theo dõi lô: vui lòng nhập hạn dùng.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/purchases')).toHaveLength(0)

    await user.type(within(line).getByLabelText(/Số lô/), 'L001')
    await user.type(within(line).getByLabelText(/Hạn dùng/), '2030-01-31')
    await user.click(screen.getByRole('button', { name: 'Nhập hàng' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/purchases')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/purchases')[0].body).toMatchObject({ lines: [{ product_id: 'p-ts', quantity: 10, unit_cost: 50000, batch_no: 'L001', expires_at: '2030-01-31', manufactured_at: null }] })
  })

  it('thủ kho không có quyền trả tiền thì không thấy ô "Trả ngay"', async () => {
    mockApi(base({}, KEEPER))
    renderApp('/notifications?dialog=purchase-new')

    await screen.findByLabelText(/Nhà cung cấp/)
    expect(screen.getByLabelText(/Chi phí kèm theo/)).toBeInTheDocument()
    expect(screen.queryByLabelText(/Trả ngay/)).not.toBeInTheDocument()
  })

  it('kho đang kiểm kê bị khóa; lỗi 422 của máy chủ hiện đúng ô', async () => {
    mockApi(base({
      'POST /api/v1/purchases': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', {
        errors: { 'lines.0.quantity': ['Số lượng quá nhỏ.'], paid_amount: ['Số tiền trả (2 đ) không được lớn hơn tổng tiền hàng (1 đ).'], warehouse_id: ['Kho này đang kiểm kê (KK0007): hoàn tất hoặc hủy đợt kiểm kê trước khi ghi phiếu kho.'] },
      }),
    }, OWNER, [{ ...KHO2, stocktake: { id: 's-1', code: 'KK0001' } }, KHO1]))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=purchase-new')

    const warehouse = await screen.findByLabelText(/Kho nhận/)
    expect(within(warehouse).getByRole('option', { name: 'Kho thuốc (đang kiểm kê)' })).toBeDisabled()
    await user.selectOptions(screen.getByLabelText(/Nhà cung cấp/), 's-1')
    await user.selectOptions(warehouse, 'w-1')
    await pickProduct(user, /NPK-16/)
    const line = screen.getByRole('group', { name: 'Dòng 1' })
    await user.type(within(line).getByLabelText(/Số lượng/), '1')
    await user.type(within(line).getByLabelText(/Đơn giá nhập/), '1')
    await user.click(screen.getByRole('button', { name: 'Nhập hàng' }))

    expect(await within(line).findByText('Số lượng quá nhỏ.')).toBeInTheDocument()
    expect(screen.getByText(/Số tiền trả \(2 đ\) không được lớn hơn/)).toBeInTheDocument()
    expect(screen.getByText(/Kho này đang kiểm kê \(KK0007\)/)).toBeInTheDocument()
  })
})

describe('nhập hàng: xem phiếu và trả tiền', () => {
  it('hiện tiền hàng, chi phí, đã trả, còn nợ, các dòng (đơn giá và giá vốn), các lần trả (kể cả lần đã hủy) và liên kết phiếu nhập kho', async () => {
    mockApi(base())
    renderApp('/notifications?dialog=purchase:p-1')

    expect(await screen.findByRole('heading', { name: 'Phiếu nhập hàng' })).toBeInTheDocument()
    const dialog = screen.getByRole('dialog')
    expect(await within(dialog).findByText(/NH0001 · Công ty Phân bón Bình Điền/)).toBeInTheDocument()
    expect(within(dialog).getByText('Trả một phần')).toBeInTheDocument()
    expect(within(dialog).getByText('Đã cộng vào giá vốn')).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: 'PN0001' })).toHaveAttribute('href', '/notifications?dialog=stock-doc:d-1')

    const [lines, payments] = within(dialog).getAllByRole('table')
    expect(within(lines).getByText('4 Bao')).toBeInTheDocument()
    expect(within(lines).getByText(/721\.212/)).toBeInTheDocument() // giá vốn đã gồm chi phí phân bổ
    expect(within(lines).getByText('L001')).toBeInTheDocument()
    expect(within(payments).getByText('Chuyển khoản')).toBeInTheDocument()
    expect(within(payments).getByText('Đã hủy')).toBeInTheDocument()
    expect(within(payments).getByText('Trả nhầm phiếu')).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: 'Trả tiền' })).toHaveAttribute('href', '/notifications?dialog=purchase-pay:p-1')
  })

  it('người không có quyền trả/hủy không thấy nút Trả tiền và Hủy lần trả; phiếu đã trả đủ cũng không có nút Trả tiền', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/notifications?dialog=purchase:p-1')

    await screen.findByText(/NH0001 · /)
    expect(screen.queryByRole('link', { name: 'Trả tiền' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Hủy lần trả' })).not.toBeInTheDocument()
  })

  it('hủy lần trả: bắt buộc nêu lý do rồi gửi lên máy chủ', async () => {
    const after = { ...DETAIL, paid_amount: '400000.00', balance: '2900000.00', payments: DETAIL.payments?.map((p) => (p.id === 'pay-1' ? { ...p, voided: { at: '2026-10-31T01:00:00Z', by: null, reason: 'Ghi nhầm số tiền' } } : p)) }
    const api = mockApi(base({ 'POST /api/v1/purchases/p-1/payments/pay-1/void': { json: { data: after } } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=purchase:p-1')

    await user.click(await screen.findByRole('button', { name: 'Hủy lần trả' }))
    const confirm = await screen.findByRole('heading', { name: 'Hủy lần trả tiền' })
    const box = confirm.closest('[role=dialog]') as HTMLElement
    await user.click(within(box).getByRole('button', { name: 'Hủy lần trả' }))
    expect(await within(box).findByText('Vui lòng nhập lý do (ít nhất 2 ký tự).')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/purchases/p-1/payments/pay-1/void')).toHaveLength(0)

    await user.type(within(box).getByLabelText('Lý do hủy'), 'Ghi nhầm số tiền')
    await user.click(within(box).getByRole('button', { name: 'Hủy lần trả' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/purchases/p-1/payments/pay-1/void')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/purchases/p-1/payments/pay-1/void')[0].body).toEqual({ reason: 'Ghi nhầm số tiền' })
    expect(await screen.findByText('Đã hủy lần trả tiền.')).toBeInTheDocument()
  })

  it('không tìm thấy phiếu (hoặc không có quyền xem) thì báo rõ', async () => {
    mockApi(base({ 'GET /api/v1/purchases/p-9': problem(404, 'not_found', 'Không tìm thấy.') }))
    renderApp('/notifications?dialog=purchase:p-9')

    expect(await screen.findByText('Không tìm thấy phiếu này hoặc bạn không có quyền xem.')).toBeInTheDocument()
  })

  it('popup trả tiền: mặc định số còn nợ, không cho vượt, gửi đúng nội dung rồi quay về chi tiết phiếu', async () => {
    const after = { ...DETAIL, paid_amount: '3300000.00', balance: '0.00', payment_status: 'paid' as const }
    const api = mockApi(base({ 'POST /api/v1/purchases/p-1/payments': { json: { data: after } } }))
    const user = userEvent.setup()
    const { router } = renderApp('/notifications?dialog=purchase-pay:p-1')

    expect(await screen.findByRole('heading', { name: 'Trả tiền nhà cung cấp' })).toBeInTheDocument()
    const amount = await screen.findByLabelText(/Số tiền trả/)
    expect(amount).toHaveValue('2300000')
    expect(screen.getByText(/Còn nợ 2\.300\.000/)).toBeInTheDocument()

    await user.clear(amount)
    await user.type(amount, '2300001')
    await user.click(screen.getByRole('button', { name: 'Ghi trả tiền' }))
    expect(await screen.findByText('Số tiền trả không được lớn hơn số còn nợ.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/purchases/p-1/payments')).toHaveLength(0)

    await user.clear(amount)
    await user.type(amount, '2300000')
    await user.selectOptions(screen.getByLabelText(/Hình thức trả/), 'bank_transfer')
    await user.type(screen.getByLabelText('Ghi chú'), 'Trả nốt')
    await user.click(screen.getByRole('button', { name: 'Ghi trả tiền' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/purchases/p-1/payments')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/purchases/p-1/payments')[0].body).toMatchObject({ amount: 2300000, method: 'bank_transfer', note: 'Trả nốt' })
    await waitFor(() => expect(new URLSearchParams(router.state.location.search).get('dialog')).toBe('purchase:p-1'))
  })

  it('máy chủ từ chối số tiền (vượt nợ do người khác vừa trả) thì báo ngay ở ô số tiền', async () => {
    mockApi(base({
      'POST /api/v1/purchases/p-1/payments': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', { errors: { amount: ['Số tiền trả (2.300.000 đ) vượt quá số còn nợ của phiếu (100.000 đ).'] } }),
    }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=purchase-pay:p-1')

    await screen.findByLabelText(/Số tiền trả/)
    await user.click(screen.getByRole('button', { name: 'Ghi trả tiền' }))

    expect(await screen.findByText(/vượt quá số còn nợ của phiếu/)).toBeInTheDocument()
  })

  it('phiếu đã trả đủ thì popup trả tiền báo đã trả đủ', async () => {
    mockApi(base({ 'GET /api/v1/purchases/p-2': { json: { data: { ...PAID_ONE, lines: [], payments: [] } } } }))
    renderApp('/notifications?dialog=purchase-pay:p-2')

    expect(await screen.findByText('Phiếu này đã trả đủ.')).toBeInTheDocument()
  })

  it('phiếu kho do nhập hàng sinh ra có liên kết về phiếu nhập hàng', async () => {
    const doc: StockDocument = {
      id: 'd-1', code: 'PN0001', type: 'receipt', reason: null, note: 'Nhập hàng NH0001', warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, branch: BR1,
      purchase: { id: 'p-1', code: 'NH0001' }, performed_by: null, performed_at: '2026-10-31T03:00:00Z', lines: [],
    }
    mockApi(base({ 'GET /api/v1/stock/documents/d-1': { json: { data: doc } } }))
    renderApp('/notifications?dialog=stock-doc:d-1')

    expect(await screen.findByRole('link', { name: 'NH0001' })).toHaveAttribute('href', '/notifications?dialog=purchase:p-1')
  })
})
