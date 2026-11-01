import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { Sale, SaleLine, SaleReturn } from './api.ts'
import { pickedLines, returnPreview, returnable } from './forms.ts'

type Permissions = Record<string, 'own' | 'branch' | 'tenant'>

// Chủ cửa hàng: bán, thu tiền, hủy lần thu, nhận hàng trả. Kế toán: chỉ xem.
const OWNER: Permissions = { 'sales.order.view': 'tenant', 'sales.order.create': 'tenant', 'sales.order.update': 'tenant', 'sales.order.cancel': 'tenant', 'inventory.movement.view': 'tenant' }
const VIEWER: Permissions = { 'sales.order.view': 'tenant', 'inventory.movement.view': 'tenant' }

const BR1 = { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính' }

const line = (overrides: Partial<SaleLine> = {}): SaleLine => ({
  id: 'l-1', product: { id: 'p-npk', sku: 'NPK-16', name: 'Phân NPK 16-16-8', base_unit: 'Kg' }, batch: null, unit: 'Bao', quantity: '2.000', returned_quantity: '0.000',
  unit_price: '750000.00', line_total: '1500000.00', ...overrides,
})

/** Tiền hàng 1.550.000, bớt 50.000 → khách phải trả 1.500.000; đã trả 500.000 nên còn nợ 1.000.000. Dòng 1: 2 Bao × 750.000; dòng 2: 5 Kg × 10.000. */
const sale = (overrides: Partial<Sale> = {}): Sale => ({
  id: 's-1', code: 'BH0001', sold_on: '2026-10-30', due_on: '2026-11-29', is_overdue: false, note: null, customer: { id: 'c-1', code: 'KH0001', name: 'Nguyễn Văn Nông' },
  warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, branch: BR1, goods_total: '1550000.00', discount: '50000.00', total: '1500000.00', returned_amount: '0.00', net_total: '1500000.00',
  paid_amount: '500000.00', balance: '1000000.00', payment_status: 'partial', stock_document: { id: 'd-1', code: 'PX0001' }, created_by: null, created_at: '2026-10-30T03:00:00Z', line_count: 2,
  lines: [line(), line({ id: 'l-2', product: { id: 'p-ts', sku: 'TS-01', name: 'Thuốc trừ sâu 25WG', base_unit: 'Kg' }, unit: 'Kg', quantity: '5.000', unit_price: '10000.00', line_total: '50000.00' })],
  payments: [], returns: [], ...overrides,
})

const RETURN: SaleReturn = {
  id: 'r-1', code: 'TH0001', returned_on: '2026-11-01', note: 'Khách mua nhầm', total: '725806.45', debt_reduction: '725806.45', refund_amount: '0.00', refund_method: null,
  stock_document: { id: 'd-2', code: 'PN0002' }, created_by: { id: 'u-1', name: 'Nguyễn Văn A' }, created_at: '2026-11-01T03:00:00Z',
  lines: [{ product: { id: 'p-npk', sku: 'NPK-16', name: 'Phân NPK 16-16-8' }, unit: 'Bao', quantity: '1.000', line_total: '750000.00' }],
}
const AFTER = sale({
  returned_amount: '725806.45', net_total: '774193.55', balance: '274193.55', returns: [RETURN],
  lines: [line({ returned_quantity: '1.000' }), line({ id: 'l-2', product: { id: 'p-ts', sku: 'TS-01', name: 'Thuốc trừ sâu 25WG', base_unit: 'Kg' }, unit: 'Kg', quantity: '5.000', unit_price: '10000.00', line_total: '50000.00' })],
})
const ALL_BACK = sale({ returned_amount: '1500000.00', net_total: '0.00', paid_amount: '0.00', balance: '0.00', payment_status: 'paid', lines: [line({ returned_quantity: '2.000' }), line({ id: 'l-2', quantity: '5.000', returned_quantity: '5.000', unit: 'Kg' })] })

const base = (extra: Record<string, unknown> = {}, permissions: Permissions = OWNER) =>
  ({
    'GET /api/v1/auth/me': meOk(tenantUser({ permissions })),
    'GET /api/v1/branches': { json: { data: [BR1], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } },
    'GET /api/v1/sales/s-1': { json: { data: sale() } },
    ...extra,
  }) as never

describe('trả hàng: chi tiết phiếu bán', () => {
  it('người có quyền thấy nút Trả hàng mở popup trả hàng; người chỉ xem thì không', async () => {
    mockApi(base())
    renderApp('/notifications?dialog=sale:s-1')

    expect(await screen.findByRole('link', { name: 'Trả hàng' })).toHaveAttribute('href', '/notifications?dialog=sale-return:s-1')
  })

  it('người chỉ có quyền xem không thấy nút Trả hàng', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/notifications?dialog=sale:s-1')

    await screen.findByText('Phân NPK 16-16-8')
    expect(screen.queryByRole('link', { name: 'Trả hàng' })).not.toBeInTheDocument()
  })

  it('phiếu đã trả hết hàng thì không còn nút Trả hàng', async () => {
    mockApi(base({ 'GET /api/v1/sales/s-1': { json: { data: ALL_BACK } } }))
    renderApp('/notifications?dialog=sale:s-1')

    await screen.findAllByText('Phân NPK 16-16-8')
    expect(screen.queryByRole('link', { name: 'Trả hàng' })).not.toBeInTheDocument()
  })

  it('hiện số khách phải trả thực, hàng đã trả của từng dòng và lịch sử các lần trả (trừ nợ / hoàn tiền) kèm liên kết phiếu nhập kho', async () => {
    mockApi(base({ 'GET /api/v1/sales/s-1': { json: { data: AFTER } } }))
    renderApp('/notifications?dialog=sale:s-1')

    expect(await screen.findByText('Các lần trả hàng')).toBeInTheDocument()
    expect(screen.getByText(/Đã trả hàng 725\.806/)).toBeInTheDocument()
    expect(screen.getByText('đã trả 1')).toBeInTheDocument()
    const link = screen.getByRole('link', { name: 'TH0001' })
    expect(link).toHaveAttribute('href', '/notifications?dialog=stock-doc:d-2')
    const row = link.closest('tr')!
    expect(within(row).getByText('Khách mua nhầm')).toBeInTheDocument()
    expect(within(row).getByText(/trừ nợ 725\.806/)).toBeInTheDocument()
  })
})

describe('trả hàng: popup', () => {
  it('liệt kê hàng còn trả được; gõ số lượng thì xem trước giá trị trả, trừ vào nợ và hoàn tiền; gửi đúng nội dung rồi quay về chi tiết phiếu', async () => {
    const api = mockApi(base({ 'POST /api/v1/sales/s-1/returns': { status: 201, json: { data: AFTER } } }))
    const user = userEvent.setup()
    const { router } = renderApp('/notifications?dialog=sale-return:s-1')

    expect(await screen.findByRole('heading', { name: 'Khách trả hàng' })).toBeInTheDocument()
    expect(await screen.findByText(/Đã bán 2 · đã trả 0 · còn trả được 2 Bao/)).toBeInTheDocument()
    const inputs = screen.getAllByLabelText('Số lượng trả')
    expect(inputs).toHaveLength(2)
    const summary = screen.getByLabelText('Kết quả trả hàng')
    expect(within(summary).getByText('Chưa chọn hàng nào để trả.')).toBeInTheDocument()

    // 1 Bao = 750.000 chia phần bớt tiền (1.500.000 / 1.550.000) = 725.806: nhỏ hơn số còn nợ nên chỉ trừ nợ, không hoàn tiền.
    await user.type(inputs[0]!, '1')
    expect(within(summary).getAllByText(/725\.806/)).toHaveLength(2)
    expect(within(summary).getByText(/^0\s*₫$/)).toBeInTheDocument()
    expect(screen.queryByLabelText(/Hoàn tiền bằng/)).not.toBeInTheDocument()

    // Trả hết: giá trị = số khách phải trả thực 1.500.000, trừ hết nợ 1.000.000 và hoàn 500.000 khách đã trả.
    await user.clear(inputs[0]!)
    await user.type(inputs[0]!, '2')
    await user.type(inputs[1]!, '5')
    expect(within(summary).getByText(/^1\.500\.000/)).toBeInTheDocument()
    expect(within(summary).getByText(/^1\.000\.000/)).toBeInTheDocument()
    expect(within(summary).getByText(/^500\.000/)).toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText(/Hoàn tiền bằng/), 'bank_transfer')

    // Quay lại chỉ trả 1 Bao rồi ghi.
    await user.clear(inputs[0]!)
    await user.type(inputs[0]!, '1')
    await user.clear(inputs[1]!)
    await user.type(screen.getByLabelText('Lý do / ghi chú'), 'Khách mua nhầm')
    await user.click(screen.getByRole('button', { name: 'Ghi trả hàng' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/sales/s-1/returns')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/sales/s-1/returns')[0].body).toEqual({ returned_on: expect.any(String), note: 'Khách mua nhầm', lines: [{ line_id: 'l-1', quantity: 1 }] })
    await waitFor(() => expect(new URLSearchParams(router.state.location.search).get('dialog')).toBe('sale:s-1'))
    expect(await screen.findByText(/Đã ghi trả hàng TH0001/)).toBeInTheDocument()
  })

  it('có tiền phải hoàn thì gửi kèm hình thức hoàn tiền', async () => {
    const api = mockApi(base({ 'POST /api/v1/sales/s-1/returns': { status: 201, json: { data: ALL_BACK } } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale-return:s-1')

    const inputs = await screen.findAllByLabelText('Số lượng trả')
    await user.type(inputs[0]!, '2')
    await user.type(inputs[1]!, '5')
    await user.selectOptions(await screen.findByLabelText(/Hoàn tiền bằng/), 'bank_transfer')
    await user.click(screen.getByRole('button', { name: 'Ghi trả hàng' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/sales/s-1/returns')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/sales/s-1/returns')[0].body).toMatchObject({ refund_method: 'bank_transfer', lines: [{ line_id: 'l-1', quantity: 2 }, { line_id: 'l-2', quantity: 5 }] })
  })

  it('không cho trả quá số còn trả được, số lượng sai, hoặc ghi khi chưa chọn dòng nào', async () => {
    const api = mockApi(base())
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale-return:s-1')

    const inputs = await screen.findAllByLabelText('Số lượng trả')
    await user.click(screen.getByRole('button', { name: 'Ghi trả hàng' }))
    expect(await screen.findByText('Nhập số lượng trả cho ít nhất một dòng hàng.')).toBeInTheDocument()

    await user.type(inputs[0]!, '3')
    await user.type(inputs[1]!, 'abc')
    await user.click(screen.getByRole('button', { name: 'Ghi trả hàng' }))
    expect(await screen.findByText('Chỉ trả lại được tối đa 2 Bao.')).toBeInTheDocument()
    expect(screen.getByText('Nhập số lượng từ 0 (tối đa 3 chữ số thập phân).')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/sales/s-1/returns')).toHaveLength(0)
  })

  it('máy chủ từ chối thì báo ngay ở ô số lượng của đúng dòng', async () => {
    mockApi(base({
      'POST /api/v1/sales/s-1/returns': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', { errors: { 'lines.0.quantity': ['Chỉ trả lại được tối đa 1 Kg Thuốc trừ sâu 25WG (đã bán 5, đã trả 4).'] } }),
    }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=sale-return:s-1')

    const inputs = await screen.findAllByLabelText('Số lượng trả')
    await user.type(inputs[1]!, '2') // dòng thứ hai trên giao diện, nhưng là dòng gửi đầu tiên (dòng 1 để trống)
    await user.click(screen.getByRole('button', { name: 'Ghi trả hàng' }))

    const error = await screen.findByText(/Chỉ trả lại được tối đa 1 Kg Thuốc trừ sâu/)
    expect(inputs[1]).toHaveAccessibleDescription(/Chỉ trả lại được tối đa 1 Kg/)
    expect(error).toBeInTheDocument()
  })

  it('phiếu đã trả hết hàng thì popup báo không còn gì để trả', async () => {
    mockApi(base({ 'GET /api/v1/sales/s-1': { json: { data: ALL_BACK } } }))
    renderApp('/notifications?dialog=sale-return:s-1')

    expect(await screen.findByText('Phiếu này khách đã trả hết hàng.')).toBeInTheDocument()
  })

  it('người không có quyền không mở được popup trả hàng', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/notifications?dialog=sale-return:s-1')

    expect(await screen.findByRole('heading', { name: /không có quyền/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ghi trả hàng' })).not.toBeInTheDocument()
  })
})

describe('trả hàng: cách tính xem trước', () => {
  const open = sale().lines as SaleLine[]
  const values = (...quantities: string[]) => ({ lines: quantities.map((quantity) => ({ quantity })) })

  it('số còn trả được của dòng', () => {
    expect(returnable(line({ quantity: '5.000', returned_quantity: '1.500' }))).toBe(3.5)
    expect(returnable(line({ quantity: '5.000', returned_quantity: '5.000' }))).toBe(0)
  })

  it('chỉ lấy các dòng có số lượng lớn hơn 0 (chấp nhận dấu phẩy)', () => {
    expect(pickedLines(values('', '2,5', '0', 'x'))).toEqual([{ index: 1, quantity: 2.5 }])
  })

  it('giá trị trả chia phần bớt tiền của phiếu; trừ nợ trước, phần vượt hoàn lại', () => {
    expect(returnPreview(sale(), open, values('1', ''))).toEqual({ goods: 750000, value: 725806.45, debt: 725806.45, refund: 0 })
    // Trả hết: đúng số khách phải trả thực (1.500.000), trừ nợ 1.000.000 và hoàn 500.000.
    expect(returnPreview(sale(), open, values('2', '5'))).toEqual({ goods: 1550000, value: 1500000, debt: 1000000, refund: 500000 })
    expect(returnPreview(sale(), open, values('', ''))).toEqual({ goods: 0, value: 0, debt: 0, refund: 0 })
  })

  it('đã trả một phần từ trước thì trả nốt phần còn lại lấy đúng số khách còn phải trả', () => {
    const half = sale({ returned_amount: '725806.45', net_total: '774193.55', paid_amount: '500000.00', balance: '274193.55', lines: [line({ returned_quantity: '1.000' }), line({ id: 'l-2', quantity: '5.000', unit: 'Kg', unit_price: '10000.00' })] })
    const rest = half.lines!.filter((l) => returnable(l) > 0)

    expect(returnPreview(half, rest, values('1', '5'))).toEqual({ goods: 800000, value: 774193.55, debt: 274193.55, refund: 500000 })
  })
})
