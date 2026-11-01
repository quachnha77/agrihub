import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { CollectResult, Receivable, ReceivableSummary, Sale } from './api.ts'
import { allocateDebt, sortByDueFirst } from './forms.ts'

type Permissions = Record<string, 'own' | 'branch' | 'tenant'>

// Chủ cửa hàng thu nợ được; kế toán chỉ xem.
const OWNER: Permissions = { 'sales.order.view': 'tenant', 'sales.order.create': 'tenant', 'sales.order.update': 'tenant', 'sales.order.cancel': 'tenant', 'crm.customer.view': 'tenant' }
const VIEWER: Permissions = { 'sales.order.view': 'tenant', 'crm.customer.view': 'tenant' }

const page = <T,>(data: T[], extra: Record<string, unknown> = {}) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 }, ...extra })
const BR1 = { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính' }

const debtor = (overrides: Partial<Receivable> & { name?: string; id?: string } = {}): Receivable => ({
  customer: { id: overrides.id ?? 'c-1', code: 'KH0001', name: overrides.name ?? 'Nguyễn Văn Nông', phone: '0901000111', status: 'active', credit_limit: '5000000.00', credit_days: 30 },
  open_orders: 2, owed: '1000000.00', overdue_amount: '400000.00', is_overdue: true, oldest_overdue_on: '2026-09-01', days_overdue: 61, ...overrides,
})
const LAN = debtor({ id: 'c-2', name: 'Lê Thị Lan', open_orders: 1, owed: '250000.00', overdue_amount: '0.00', is_overdue: false, oldest_overdue_on: null, days_overdue: 0, customer: { id: 'c-2', code: 'KH0002', name: 'Lê Thị Lan', phone: null, status: 'active', credit_limit: '0.00', credit_days: null } })

const SUMMARY: ReceivableSummary = { debtors: 2, owed: '1250000.00', aging: { current: '850000.00', overdue_1_30: '0.00', overdue_31_60: '0.00', overdue_over_60: '400000.00' } }

const sale = (overrides: Partial<Sale> = {}): Sale => ({
  id: 's-1', code: 'BH0001', sold_on: '2026-08-02', due_on: '2026-09-01', is_overdue: true, note: null, customer: { id: 'c-1', code: 'KH0001', name: 'Nguyễn Văn Nông' },
  warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, branch: BR1, goods_total: '400000.00', discount: '0.00', total: '400000.00', paid_amount: '0.00', balance: '400000.00',
  payment_status: 'unpaid', stock_document: { id: 'd-1', code: 'PX0001' }, created_by: null, created_at: '2026-08-02T03:00:00Z', line_count: 1, ...overrides,
})
const OLD = sale()
const NEWER = sale({ id: 's-2', code: 'BH0002', sold_on: '2026-11-01', due_on: '2026-12-01', is_overdue: false, total: '700000.00', paid_amount: '100000.00', balance: '600000.00', payment_status: 'partial' })

const base = (extra: Record<string, unknown> = {}, permissions: Permissions = OWNER) =>
  ({
    'GET /api/v1/auth/me': meOk(tenantUser({ permissions })),
    'GET /api/v1/branches': { json: page([{ id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính', phone: null, address: null, status: 'active' }]) },
    'GET /api/v1/receivables': { json: page([debtor(), LAN], { summary: SUMMARY }) },
    // Phiếu còn nợ của khách c-1 (máy chủ trả mới nhất trước; popup tự xếp lại theo hạn trả).
    'GET /api/v1/sales': { json: page([NEWER, OLD]) },
    ...extra,
  }) as never

const RESULT: CollectResult = { customer: 'c-1', collected: '500000.00', remaining: '500000.00', allocations: [{ order: { id: 's-1', code: 'BH0001' }, amount: '400000.00', balance: '0.00' }, { order: { id: 's-2', code: 'BH0002' }, amount: '100000.00', balance: '500000.00' }] }

describe('công nợ khách hàng: danh sách', () => {
  it('liệt kê khách đang nợ kèm số phiếu, quá hạn bao lâu, hạn mức và tổng theo tuổi nợ; có thanh tab và nút thu nợ', async () => {
    mockApi(base())
    renderApp('/sales/receivables')

    expect(await screen.findByRole('heading', { name: 'Công nợ khách hàng' })).toBeInTheDocument()
    const tabs = screen.getByRole('navigation', { name: 'Bán hàng' })
    expect(within(tabs).getByRole('link', { name: 'Phiếu bán' })).toHaveAttribute('href', '/sales')
    expect(within(tabs).getByRole('link', { name: 'Công nợ khách' })).toHaveAttribute('aria-current', 'page')

    const table = await screen.findByRole('table')
    const nong = within(table).getByText('Nguyễn Văn Nông').closest('tr')!
    expect(within(nong).getByText('Quá hạn 61 ngày')).toBeInTheDocument()
    expect(within(nong).getByText(/400\.000/)).toBeInTheDocument()
    expect(within(nong).getByText(/5\.000\.000/)).toBeInTheDocument()
    expect(within(nong).getByRole('link', { name: /Thu nợ/ })).toHaveAttribute('href', '/sales/receivables?dialog=debt-pay:c-1')

    const lan = within(table).getByText('Lê Thị Lan').closest('tr')!
    expect(within(lan).getByText('Chưa quá hạn')).toBeInTheDocument()
    expect(within(lan).getByText('Không cho nợ')).toBeInTheDocument()

    const summary = screen.getByLabelText('Nợ theo tuổi nợ')
    expect(within(summary).getByText(/1\.250\.000/)).toBeInTheDocument()
    expect(within(summary).getByText('2 khách')).toBeInTheDocument()
    expect(within(summary).getByText(/850\.000/)).toBeInTheDocument()
    expect(within(summary).getByText('Quá hạn trên 60 ngày')).toBeInTheDocument()
  })

  it('người chỉ có quyền xem thấy công nợ nhưng không có nút thu nợ', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/sales/receivables')

    await screen.findByRole('table')
    expect(screen.queryByRole('link', { name: /Thu nợ/ })).not.toBeInTheDocument()
  })

  it('người không có quyền xem bán hàng bị chặn khi vào thẳng đường dẫn', async () => {
    mockApi(base({}, { 'catalog.product.view': 'tenant' }))
    renderApp('/sales/receivables')

    await waitFor(() => expect(screen.queryByRole('table')).not.toBeInTheDocument())
    expect(screen.queryByRole('heading', { name: 'Công nợ khách hàng' })).not.toBeInTheDocument()
  })

  it('không ai nợ: trạng thái rỗng; có bộ lọc thì gợi ý bỏ lọc', async () => {
    mockApi(base({ 'GET /api/v1/receivables': { json: page([], { summary: { debtors: 0, owed: '0.00', aging: { current: '0.00', overdue_1_30: '0.00', overdue_31_60: '0.00', overdue_over_60: '0.00' } } }) } }))
    const user = userEvent.setup()
    renderApp('/sales/receivables')

    expect(await screen.findByText('Chưa có khách nào đang nợ.')).toBeInTheDocument()
    expect(screen.getByText('Khi bán chịu cho khách, số tiền khách còn nợ sẽ hiện ở đây.')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Tình trạng'), '1')
    expect(await screen.findByText('Thử bỏ bớt bộ lọc hoặc tìm bằng từ khác.')).toBeInTheDocument()
  })

  it('tìm theo tên và lọc "có phiếu quá hạn" gửi tham số lên máy chủ', async () => {
    const api = mockApi(base())
    const user = userEvent.setup()
    renderApp('/sales/receivables')

    await screen.findByRole('table')
    await user.selectOptions(screen.getByLabelText('Tình trạng'), '1')
    await user.type(screen.getByRole('searchbox', { name: 'Tìm khách đang nợ' }), 'Nông')

    await waitFor(() => expect(api.calls.some((call) => call.path.startsWith('/api/v1/receivables?') && call.path.includes('overdue=1') && call.path.includes('q=N'))).toBe(true))
  })
})

describe('công nợ khách hàng: thu nợ', () => {
  it('mặc định thu hết nợ; gõ số nhỏ hơn thì xem trước phiếu đến hạn sớm nhất được trừ trước; gửi đúng nội dung rồi đóng popup', async () => {
    const api = mockApi(base({ 'POST /api/v1/receivables/c-1/collect': { json: { data: RESULT } } }))
    const user = userEvent.setup()
    const { router } = renderApp('/sales/receivables?dialog=debt-pay:c-1')

    expect(await screen.findByRole('heading', { name: 'Thu nợ của khách' })).toBeInTheDocument()
    const amount = await screen.findByLabelText(/Số tiền thu/)
    expect(amount).toHaveValue('1000000')
    expect(screen.getByText(/Khách đang nợ 1\.000\.000.*\(2 phiếu\)/)).toBeInTheDocument()

    // Phiếu quá hạn (BH0001, hạn 01/09) đứng trước dù máy chủ trả phiếu mới trước.
    const list = screen.getByRole('list', { name: 'Tiền sẽ trừ vào các phiếu' })
    const rows = within(list).getAllByRole('listitem')
    expect(rows.map((row) => within(row).getByText(/^BH\d+/).textContent)).toEqual(['BH0001', 'BH0002'])
    expect(within(rows[0]!).getByText('Quá hạn')).toBeInTheDocument()

    await user.clear(amount)
    await user.type(amount, '500000')
    expect(within(rows[0]!).getByText(/− 400\.000/)).toBeInTheDocument()
    expect(within(rows[1]!).getByText(/− 100\.000/)).toBeInTheDocument()

    await user.clear(amount)
    await user.type(amount, '300000')
    expect(within(rows[0]!).getByText(/− 300\.000/)).toBeInTheDocument()
    expect(within(rows[1]!).queryByText(/−/)).not.toBeInTheDocument()

    await user.clear(amount)
    await user.type(amount, '500000')
    await user.selectOptions(screen.getByLabelText(/Hình thức thu/), 'bank_transfer')
    await user.type(screen.getByLabelText('Ghi chú'), 'Khách chuyển khoản')
    await user.click(screen.getByRole('button', { name: 'Ghi thu nợ' }))

    await waitFor(() => expect(api.to('POST', '/api/v1/receivables/c-1/collect')).toHaveLength(1))
    expect(api.to('POST', '/api/v1/receivables/c-1/collect')[0].body).toMatchObject({ amount: 500000, method: 'bank_transfer', note: 'Khách chuyển khoản' })
    expect(await screen.findByText(/Đã thu 500\.000.*trừ vào 2 phiếu/)).toBeInTheDocument()
    await waitFor(() => expect(new URLSearchParams(router.state.location.search).get('dialog')).toBeNull())
  })

  it('không cho nhập số tiền lớn hơn số khách còn nợ hoặc bằng 0', async () => {
    const api = mockApi(base())
    const user = userEvent.setup()
    renderApp('/sales/receivables?dialog=debt-pay:c-1')

    const amount = await screen.findByLabelText(/Số tiền thu/)
    await user.clear(amount)
    await user.type(amount, '1000001')
    await user.click(screen.getByRole('button', { name: 'Ghi thu nợ' }))
    expect(await screen.findByText('Số tiền thu không được lớn hơn số khách còn nợ.')).toBeInTheDocument()

    await user.clear(amount)
    await user.type(amount, '0')
    await user.click(screen.getByRole('button', { name: 'Ghi thu nợ' }))
    expect(await screen.findByText('Nhập số tiền lớn hơn 0 (tối đa 2 chữ số thập phân).')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/receivables/c-1/collect')).toHaveLength(0)
  })

  it('máy chủ từ chối số tiền (người khác vừa thu) thì báo ngay ở ô số tiền', async () => {
    mockApi(base({
      'POST /api/v1/receivables/c-1/collect': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', { errors: { amount: ['Số tiền thu (1.000.000 đ) vượt quá số khách còn nợ (200.000 đ).'] } }),
    }))
    const user = userEvent.setup()
    renderApp('/sales/receivables?dialog=debt-pay:c-1')

    await screen.findByLabelText(/Số tiền thu/)
    await user.click(screen.getByRole('button', { name: 'Ghi thu nợ' }))

    expect(await screen.findByText(/vượt quá số khách còn nợ \(200\.000/)).toBeInTheDocument()
  })

  it('khách không còn phiếu nợ thì popup báo đã hết nợ', async () => {
    mockApi(base({ 'GET /api/v1/sales': { json: page([]) } }))
    renderApp('/sales/receivables?dialog=debt-pay:c-9')

    expect(await screen.findByText('Khách này không còn nợ phiếu nào.')).toBeInTheDocument()
  })

  it('người không có quyền thu tiền không mở được popup thu nợ', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/sales/receivables?dialog=debt-pay:c-1')

    expect(await screen.findByRole('heading', { name: /không có quyền/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ghi thu nợ' })).not.toBeInTheDocument()
  })
})

describe('công nợ khách hàng: cách trừ nợ', () => {
  const a = sale({ id: 'a', code: 'BH0003', due_on: null, sold_on: '2026-10-01', balance: '100.00' })
  const b = sale({ id: 'b', code: 'BH0002', due_on: '2026-12-01', sold_on: '2026-11-01', balance: '100.00' })
  const c = sale({ id: 'c', code: 'BH0001', due_on: '2026-09-01', sold_on: '2026-08-01', balance: '100.00' })
  const d = sale({ id: 'd', code: 'BH0004', due_on: '2026-09-01', sold_on: '2026-07-01', balance: '100.00' })

  it('xếp phiếu đến hạn sớm nhất trước, cùng hạn thì phiếu bán trước, phiếu chưa có hạn sau cùng', () => {
    expect(sortByDueFirst([a, b, c, d]).map((s) => s.id)).toEqual(['d', 'c', 'b', 'a'])
  })

  it('chia số tiền theo thứ tự, mỗi phiếu tối đa số còn nợ, phần dư không phát sinh', () => {
    const sorted = sortByDueFirst([a, b, c, d])

    expect(allocateDebt(sorted, 250).map((x) => x.take)).toEqual([100, 100, 50, 0])
    expect(allocateDebt(sorted, 0).map((x) => x.take)).toEqual([0, 0, 0, 0])
    expect(allocateDebt(sorted, 400).map((x) => x.take)).toEqual([100, 100, 100, 100])
    expect(allocateDebt(sorted, 0.1 + 0.2).map((x) => x.take)).toEqual([0.3, 0, 0, 0])
  })
})
