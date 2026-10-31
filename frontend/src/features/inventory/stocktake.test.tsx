import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { Stocktake, StocktakeLine, Warehouse } from './api.ts'

type Permissions = Record<string, 'own' | 'branch' | 'tenant'>

// Thủ kho / chủ cửa hàng: xem và lập kiểm kê; có xem giá vốn nhờ quyền xem phiếu kho.
const COUNTER: Permissions = { 'inventory.adjustment.view': 'tenant', 'inventory.adjustment.create': 'tenant', 'inventory.movement.view': 'tenant', 'inventory.warehouse.view': 'tenant' }
// Chỉ xem (không có quyền lập).
const VIEWER: Permissions = { 'inventory.adjustment.view': 'branch', 'inventory.movement.view': 'branch', 'inventory.warehouse.view': 'branch' }

const page = <T,>(data: T[], extra: Partial<{ last_page: number; total: number; current_page: number }> = {}) => ({
  data,
  meta: { current_page: 1, last_page: 1, total: data.length, per_page: 25, ...extra },
})

const BR1 = { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính' }
const KHO1: Warehouse = { id: 'w-1', branch: BR1, code: 'KHO-01', name: 'Kho chính', address: null, status: 'active', stocktake: null }
const KHO2: Warehouse = { id: 'w-2', branch: BR1, code: 'KHO-02', name: 'Kho thuốc', address: null, status: 'active', stocktake: null }

const KG = { id: 'u-kg', name: 'Kg' }
const BAO = { id: 'u-bao', name: 'Bao' }

const stocktake = (overrides: Partial<Stocktake> = {}): Stocktake => ({
  id: 's-1', code: 'KK0001', status: 'counting', note: null, warehouse: { id: 'w-1', code: 'KHO-01', name: 'Kho chính' }, branch: BR1,
  started_by: { id: 'u-1', name: 'Nguyễn Văn A' }, started_at: '2026-10-31T03:00:00Z', finished_by: null, finished_at: null, adjustment_document: null,
  summary: { lines: 2, counted: 0, differing: 0 }, ...overrides,
})

const line = (id: string, sku: string, name: string, system: string, extra: Partial<StocktakeLine> = {}): StocktakeLine => ({
  id,
  product: {
    id: `p-${sku}`, sku, name, track_batch: false, base_unit: KG,
    units: [{ unit: KG, is_base: true, factor: '1' }, { unit: BAO, is_base: false, factor: '50' }],
  },
  batch: null, system_quantity: system, counted: null, difference: null, ...extra,
})
const NPK = line('l-1', 'NPK-16', 'Phân NPK 16-16-8', '500.000')
const DRUG = line('l-2', 'TS-01', 'Thuốc trừ sâu 25WG', '10.000', {
  product: { ...line('x', 'TS-01', 'Thuốc trừ sâu 25WG', '0').product, track_batch: true },
  batch: { id: 'b-1', batch_no: 'L001', expires_at: '2030-01-31' },
})

const base = (extra: Record<string, unknown> = {}, permissions: Permissions = COUNTER, warehouses: Warehouse[] = [KHO1, KHO2]) =>
  ({
    'GET /api/v1/auth/me': meOk(tenantUser({ permissions })),
    'GET /api/v1/branches': { json: page([{ id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính', phone: null, address: null, status: 'active' }]) },
    'GET /api/v1/warehouses': { json: page(warehouses) },
    'GET /api/v1/stocktakes': { json: page([stocktake({ summary: { lines: 2, counted: 1, differing: 1 } }), stocktake({ id: 's-0', code: 'KK0000', status: 'completed', finished_at: '2026-10-30T03:00:00Z', summary: { lines: 5, counted: 5, differing: 0 } })]) },
    'GET /api/v1/stocktakes/s-1': { json: { data: stocktake() } },
    'GET /api/v1/stocktakes/s-1/lines': { json: page([NPK, DRUG]) },
    ...extra,
  }) as never

describe('kiểm kê: danh sách', () => {
  it('có tab Kiểm kê, liệt kê các đợt kèm tiến độ và mở popup đếm khi bấm mã đợt', async () => {
    mockApi(base())
    renderApp('/stock/stocktakes')

    expect(await screen.findByRole('heading', { name: 'Kiểm kê kho' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Kiểm kê' })).toHaveAttribute('href', '/stock/stocktakes')

    const table = await screen.findByRole('table')
    const first = within(table).getByRole('link', { name: 'KK0001' })
    expect(first).toHaveAttribute('href', '/stock/stocktakes?dialog=stocktake:s-1')
    expect(within(table).getByText('1/2 dòng')).toBeInTheDocument()
    expect(within(table).getByText('· 1 lệch')).toBeInTheDocument()
    expect(within(table).getByText('Đang đếm')).toBeInTheDocument()
    expect(within(table).getByText('Đã hoàn tất')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Bắt đầu kiểm kê' })).toHaveAttribute('href', '/stock/stocktakes?dialog=stocktake-new')
  })

  it('người chỉ có quyền xem thấy danh sách nhưng không có nút bắt đầu', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/stock/stocktakes')

    await screen.findByRole('table')
    expect(screen.queryByRole('link', { name: 'Bắt đầu kiểm kê' })).not.toBeInTheDocument()
  })

  it('chưa có đợt nào: trạng thái rỗng kèm nút bắt đầu', async () => {
    mockApi(base({ 'GET /api/v1/stocktakes': { json: page([]) } }))
    renderApp('/stock/stocktakes')

    expect(await screen.findByText('Chưa có đợt kiểm kê nào.')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Bắt đầu kiểm kê' })).toHaveLength(2)
  })

  it('lọc theo trạng thái gửi tham số lên máy chủ', async () => {
    const api = mockApi(base())
    const user = userEvent.setup()
    renderApp('/stock/stocktakes')

    await screen.findByRole('table')
    await user.selectOptions(screen.getByLabelText('Trạng thái'), 'completed')

    await waitFor(() => expect(api.calls.some((call) => call.path.startsWith('/api/v1/stocktakes?') && call.path.includes('status=completed'))).toBe(true))
  })
})

describe('kiểm kê: bắt đầu', () => {
  it('chọn kho rồi bắt đầu: gọi API và mở luôn màn đếm của đợt mới', async () => {
    const api = mockApi(base({ 'POST /api/v1/stocktakes': { status: 201, json: { data: stocktake() } } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake-new')

    expect(await screen.findByRole('heading', { name: 'Bắt đầu kiểm kê' })).toBeInTheDocument()
    const select = await screen.findByLabelText(/Kho cần kiểm kê/)
    await user.click(screen.getByRole('button', { name: 'Bắt đầu đếm' }))
    expect(await screen.findByText('Vui lòng chọn kho.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/stocktakes')).toHaveLength(0)

    await user.selectOptions(select, 'w-1')
    await user.type(screen.getByLabelText('Ghi chú'), 'Cuối tháng')
    await user.click(screen.getByRole('button', { name: 'Bắt đầu đếm' }))

    expect(await screen.findByText('KK0001')).toBeInTheDocument()
    expect(await screen.findByLabelText('Số đếm Phân NPK 16-16-8')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/stocktakes')[0].body).toEqual({ warehouse_id: 'w-1', note: 'Cuối tháng' })
  })

  it('kho đang kiểm kê không chọn được; hết kho để kiểm kê thì báo rõ', async () => {
    const locked = { ...KHO1, stocktake: { id: 's-1', code: 'KK0001' } }
    mockApi(base({}, COUNTER, [locked, KHO2]))
    renderApp('/notifications?dialog=stocktake-new')

    const option = await screen.findByRole('option', { name: 'Kho chính (đang kiểm kê)' })
    expect(option).toBeDisabled()
    expect(screen.getByRole('option', { name: 'Kho thuốc' })).toBeEnabled()
  })

  it('mọi kho đều đang kiểm kê: thông báo không còn kho để bắt đầu', async () => {
    mockApi(base({}, COUNTER, [{ ...KHO1, stocktake: { id: 's-1', code: 'KK0001' } }]))
    renderApp('/notifications?dialog=stocktake-new')

    expect(await screen.findByText(/Không còn kho nào để kiểm kê/)).toBeInTheDocument()
  })

  it('máy chủ từ chối (kho vừa bị người khác khóa): lỗi hiện ngay ở ô chọn kho', async () => {
    mockApi(base({
      'POST /api/v1/stocktakes': { status: 422, json: { message: 'x', errors: { warehouse_id: ['Kho này đang có đợt kiểm kê chưa kết thúc (KK0005).'] } } },
    }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake-new')

    await user.selectOptions(await screen.findByLabelText(/Kho cần kiểm kê/), 'w-2')
    await user.click(screen.getByRole('button', { name: 'Bắt đầu đếm' }))

    expect(await screen.findByText('Kho này đang có đợt kiểm kê chưa kết thúc (KK0005).')).toBeInTheDocument()
  })

  it('người không có quyền lập kiểm kê gặp thông báo không đủ quyền', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/notifications?dialog=stocktake-new')

    expect(await screen.findByText(/không có quyền/i)).toBeInTheDocument()
    expect(screen.queryByLabelText(/Kho cần kiểm kê/)).not.toBeInTheDocument()
  })
})

describe('kiểm kê: đếm hàng', () => {
  /** Giả lập PATCH một dòng: lưu số đếm như máy chủ (quy ra đơn vị cơ sở, tính chênh lệch). */
  const patch = (lines: StocktakeLine[]) => (call: { path: string; body: unknown }) => {
    const id = call.path.split('/lines/')[1].split('?')[0]
    const row = lines.find((l) => l.id === id) as StocktakeLine
    const { quantity, unit_id: unitId } = call.body as { quantity: string | null; unit_id: string | null }

    if (quantity === null) return { json: { data: { ...row, counted: null, difference: null } } }

    const unit = row.product.units.find((u) => u.unit.id === unitId) as StocktakeLine['product']['units'][number]
    const base = (Number(quantity) * Number(unit.factor)).toFixed(3)

    return {
      json: {
        data: { ...row, counted: { quantity: base, entered: { quantity: Number(quantity).toFixed(3), unit: unit.unit, factor: unit.factor } }, difference: (Number(base) - Number(row.system_quantity)).toFixed(3) },
      },
    }
  }

  it('hiện thông tin đợt, tiến độ và từng dòng (sản phẩm, lô, tồn hệ thống)', async () => {
    mockApi(base({ 'GET /api/v1/stocktakes/s-1': { json: { data: stocktake({ note: 'Cuối tháng', summary: { lines: 2, counted: 1, differing: 1 }, difference_value: '-98000.00' }) } } }))
    renderApp('/notifications?dialog=stocktake:s-1')

    expect(await screen.findByText('KK0001')).toBeInTheDocument()
    const table = await screen.findByRole('table')
    expect(within(table).getByText('Phân NPK 16-16-8')).toBeInTheDocument()
    expect(within(table).getByText('L001')).toBeInTheDocument()
    expect(within(table).getByText('500 Kg')).toBeInTheDocument()
    expect(screen.getByText('Cuối tháng')).toBeInTheDocument()
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '50')
    expect(screen.getByText('Giá trị chênh lệch')).toBeInTheDocument()
    expect(screen.getByText(/98\.000/)).toBeInTheDocument()
  })

  it('gõ số đếm bằng đơn vị lớn rồi rời ô: lưu ngay, hiện quy đổi và chênh lệch', async () => {
    const api = mockApi(base({ 'PATCH /api/v1/stocktakes/s-1/lines/l-1': patch([NPK]) }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake:s-1')

    const input = await screen.findByLabelText('Số đếm Phân NPK 16-16-8')
    await user.selectOptions(screen.getByLabelText('Đơn vị đếm Phân NPK 16-16-8'), 'u-bao')
    await user.type(input, '8')
    await user.tab()

    await waitFor(() => expect(api.to('PATCH', '/api/v1/stocktakes/s-1/lines/l-1')).toHaveLength(1))
    expect(api.to('PATCH', '/api/v1/stocktakes/s-1/lines/l-1')[0].body).toEqual({ quantity: '8', unit_id: 'u-bao' })
    // 8 Bao × 50 = 400 Kg, hệ thống 500 Kg → thiếu 100.
    expect(await screen.findByText('-100 Kg')).toBeInTheDocument()
  })

  it('xóa số đã gõ thì xóa số đếm (gửi null); không đổi gì thì không gọi lại máy chủ', async () => {
    const counted = line('l-1', 'NPK-16', 'Phân NPK 16-16-8', '500.000', { counted: { quantity: '500.000', entered: { quantity: '500.000', unit: KG, factor: '1' } }, difference: '0.000' })
    const api = mockApi(base({
      'GET /api/v1/stocktakes/s-1/lines': { json: page([counted]) },
      'PATCH /api/v1/stocktakes/s-1/lines/l-1': patch([counted]),
    }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake:s-1')

    const input = await screen.findByLabelText('Số đếm Phân NPK 16-16-8')
    expect(input).toHaveValue('500')
    expect(screen.getByText('Khớp')).toBeInTheDocument()

    await user.click(input)
    await user.tab()
    expect(api.to('PATCH', '/api/v1/stocktakes/s-1/lines/l-1')).toHaveLength(0)

    await user.clear(input)
    await user.tab()
    await waitFor(() => expect(api.to('PATCH', '/api/v1/stocktakes/s-1/lines/l-1')).toHaveLength(1))
    expect(api.to('PATCH', '/api/v1/stocktakes/s-1/lines/l-1')[0].body).toEqual({ quantity: null, unit_id: null })
  })

  it('số không hợp lệ báo lỗi ngay tại dòng và không gọi máy chủ; dấu phẩy thập phân được chấp nhận', async () => {
    const api = mockApi(base({ 'PATCH /api/v1/stocktakes/s-1/lines/l-1': patch([NPK]) }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake:s-1')

    const input = await screen.findByLabelText('Số đếm Phân NPK 16-16-8')
    await user.type(input, '-3')
    await user.tab()
    expect(await screen.findByText('Nhập số từ 0 (tối đa 3 chữ số thập phân).')).toBeInTheDocument()
    expect(api.to('PATCH', '/api/v1/stocktakes/s-1/lines/l-1')).toHaveLength(0)

    await user.clear(input)
    await user.type(input, '12,5')
    await user.tab()
    await waitFor(() => expect(api.to('PATCH', '/api/v1/stocktakes/s-1/lines/l-1')).toHaveLength(1))
    expect(api.to('PATCH', '/api/v1/stocktakes/s-1/lines/l-1')[0].body).toEqual({ quantity: '12.5', unit_id: 'u-kg' })
  })

  it('Enter lưu và chuyển sang ô đếm của dòng kế tiếp', async () => {
    mockApi(base({ 'PATCH /api/v1/stocktakes/s-1/lines/l-1': patch([NPK]) }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake:s-1')

    const first = await screen.findByLabelText('Số đếm Phân NPK 16-16-8')
    await user.type(first, '490{Enter}')

    expect(screen.getByLabelText('Số đếm Thuốc trừ sâu 25WG')).toHaveFocus()
  })

  it('máy chủ từ chối (đợt vừa bị hoàn tất ở máy khác): lỗi hiện ngay dưới ô đếm', async () => {
    mockApi(base({
      'PATCH /api/v1/stocktakes/s-1/lines/l-1': { status: 422, json: { message: 'x', errors: { stocktake: ['Đợt kiểm kê KK0001 đã hoàn tất, không thay đổi được nữa.'] } } },
    }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake:s-1')

    await user.type(await screen.findByLabelText('Số đếm Phân NPK 16-16-8'), '5')
    await user.tab()

    expect(await screen.findByText('Đợt kiểm kê KK0001 đã hoàn tất, không thay đổi được nữa.')).toBeInTheDocument()
  })

  it('tìm và lọc dòng gửi tham số lên máy chủ; dòng phân trang theo máy chủ', async () => {
    const api = mockApi(base({ 'GET /api/v1/stocktakes/s-1/lines': { json: page([NPK], { last_page: 3, total: 60 }) } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake:s-1')

    await screen.findByLabelText('Số đếm Phân NPK 16-16-8')
    await user.selectOptions(screen.getByLabelText('Trạng thái'), 'differing')
    await waitFor(() => expect(api.calls.some((c) => c.path.includes('/lines?') && c.path.includes('status=differing'))).toBe(true))

    await user.type(screen.getByRole('searchbox', { name: 'Tìm trong đợt' }), 'npk')
    await waitFor(() => expect(api.calls.some((c) => c.path.includes('/lines?') && c.path.includes('q=npk'))).toBe(true))

    await user.click(screen.getByRole('button', { name: /Sau/ }))
    await waitFor(() => expect(api.calls.some((c) => c.path.includes('/lines?') && c.path.includes('page=2'))).toBe(true))
  })
})

describe('kiểm kê: hoàn tất và hủy', () => {
  const counting = stocktake({ summary: { lines: 5, counted: 3, differing: 2 }, difference_value: '-98000.00' })

  it('hoàn tất: xác nhận nêu rõ số dòng lệch, dòng chưa đếm và giá trị; xong thì đóng popup và báo kèm liên kết tới phiếu điều chỉnh', async () => {
    const api = mockApi(base({
      'GET /api/v1/stocktakes/s-1': { json: { data: counting } },
      'POST /api/v1/stocktakes/s-1/complete': { json: { data: stocktake({ status: 'completed', finished_at: '2026-10-31T04:00:00Z', adjustment_document: { id: 'd-9', code: 'DC0009' } }) } },
    }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake:s-1')

    await screen.findByLabelText('Số đếm Phân NPK 16-16-8')
    await user.click(screen.getByRole('button', { name: 'Hoàn tất kiểm kê' }))

    const confirm = await screen.findByRole('heading', { name: 'Hoàn tất kiểm kê KK0001' })
    const box = confirm.closest('[role="dialog"]') as HTMLElement
    expect(within(box).getByText('Đã đếm 3/5 dòng, trong đó 2 dòng lệch.')).toBeInTheDocument()
    expect(within(box).getByText(/Còn 2 dòng chưa đếm/)).toBeInTheDocument()
    expect(within(box).getByText(/98\.000/)).toBeInTheDocument()
    await user.click(within(box).getByRole('button', { name: 'Hoàn tất' }))

    expect(await screen.findByText('Đã hoàn tất kiểm kê KK0001 và ghi phiếu điều chỉnh DC0009.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/stocktakes/s-1/complete')).toHaveLength(1)
    expect(screen.getByRole('link', { name: 'Xem phiếu' })).toHaveAttribute('href', '/notifications?dialog=stock-doc:d-9')
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('hoàn tất bị máy chủ từ chối: lỗi hiện trong hộp xác nhận và popup vẫn mở', async () => {
    mockApi(base({
      'GET /api/v1/stocktakes/s-1': { json: { data: counting } },
      'POST /api/v1/stocktakes/s-1/complete': { status: 422, json: { message: 'x', errors: { warehouse_id: ['Số lượng giảm lớn hơn tồn hiện có.'] } } },
    }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake:s-1')

    await screen.findByLabelText('Số đếm Phân NPK 16-16-8')
    await user.click(screen.getByRole('button', { name: 'Hoàn tất kiểm kê' }))
    await user.click(within((await screen.findByRole('heading', { name: 'Hoàn tất kiểm kê KK0001' })).closest('[role="dialog"]') as HTMLElement).getByRole('button', { name: 'Hoàn tất' }))

    expect(await screen.findByText('Số lượng giảm lớn hơn tồn hiện có.')).toBeInTheDocument()
    expect(screen.getByLabelText('Số đếm Phân NPK 16-16-8')).toBeInTheDocument()
  })

  it('hủy: hỏi xác nhận, gọi API hủy rồi đóng popup', async () => {
    const api = mockApi(base({ 'POST /api/v1/stocktakes/s-1/cancel': { json: { data: stocktake({ status: 'cancelled', finished_at: '2026-10-31T04:00:00Z' }) } } }))
    const user = userEvent.setup()
    renderApp('/notifications?dialog=stocktake:s-1')

    await screen.findByLabelText('Số đếm Phân NPK 16-16-8')
    await user.click(screen.getByRole('button', { name: 'Hủy kiểm kê' }))
    const box = (await screen.findByRole('heading', { name: 'Hủy kiểm kê KK0001' })).closest('[role="dialog"]') as HTMLElement
    expect(within(box).getByText(/Tồn kho giữ nguyên/)).toBeInTheDocument()
    await user.click(within(box).getByRole('button', { name: 'Hủy kiểm kê' }))

    expect(await screen.findByText('Đã hủy kiểm kê KK0001.')).toBeInTheDocument()
    expect(api.to('POST', '/api/v1/stocktakes/s-1/cancel')).toHaveLength(1)
  })

  it('đợt đã hoàn tất chỉ để xem: không có ô đếm hay nút hoàn tất/hủy, có liên kết tới phiếu điều chỉnh', async () => {
    const done = stocktake({ status: 'completed', finished_at: '2026-10-31T04:00:00Z', finished_by: { id: 'u-1', name: 'Nguyễn Văn A' }, adjustment_document: { id: 'd-9', code: 'DC0009' }, summary: { lines: 1, counted: 1, differing: 1 }, difference_value: '-100000.00' })
    const counted = line('l-1', 'NPK-16', 'Phân NPK 16-16-8', '500.000', { counted: { quantity: '400.000', entered: { quantity: '8.000', unit: BAO, factor: '50' } }, difference: '-100.000' })
    mockApi(base({ 'GET /api/v1/stocktakes/s-1': { json: { data: done } }, 'GET /api/v1/stocktakes/s-1/lines': { json: page([counted]) } }))
    renderApp('/notifications?dialog=stocktake:s-1')

    expect(await screen.findByText('8 Bao')).toBeInTheDocument()
    expect(screen.getByText('-100 Kg')).toBeInTheDocument()
    expect(screen.queryByLabelText('Số đếm Phân NPK 16-16-8')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Hoàn tất kiểm kê' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Hủy kiểm kê' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Xem phiếu DC0009' })).toHaveAttribute('href', '/notifications?dialog=stock-doc:d-9')
  })

  it('người chỉ có quyền xem mở đợt đang đếm: xem được nhưng không sửa được', async () => {
    mockApi(base({}, VIEWER))
    renderApp('/notifications?dialog=stocktake:s-1')

    expect(await screen.findByText('Phân NPK 16-16-8')).toBeInTheDocument()
    expect(screen.queryByLabelText('Số đếm Phân NPK 16-16-8')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Hoàn tất kiểm kê' })).not.toBeInTheDocument()
  })

  it('đợt không tồn tại hoặc không có quyền: thông báo rõ ràng', async () => {
    mockApi(base({ 'GET /api/v1/stocktakes/zzz': problem(404, 'not_found', 'Không tìm thấy.') }))
    renderApp('/notifications?dialog=stocktake:zzz')

    expect(await screen.findByText('Không tìm thấy đợt kiểm kê này hoặc bạn không có quyền xem.')).toBeInTheDocument()
  })
})

describe('kiểm kê: kho bị khóa', () => {
  it('trang Danh sách kho đánh dấu kho đang kiểm kê và liên kết tới màn đếm', async () => {
    mockApi(base({}, COUNTER, [{ ...KHO1, stocktake: { id: 's-1', code: 'KK0001' } }, KHO2]))
    renderApp('/warehouses')

    const badge = await screen.findByText('Đang kiểm kê')
    expect(badge.closest('a')).toHaveAttribute('href', '/warehouses?dialog=stocktake:s-1')
    expect(screen.getAllByText('Đang kiểm kê')).toHaveLength(1)
  })

  it('popup lập phiếu kho không cho chọn kho đang kiểm kê', async () => {
    mockApi(base({}, { ...COUNTER, 'inventory.movement.create': 'tenant' }, [{ ...KHO1, stocktake: { id: 's-1', code: 'KK0001' } }, KHO2]))
    renderApp('/notifications?dialog=stock-new:receipt')

    expect(await screen.findByRole('option', { name: 'Kho chính (đang kiểm kê)' })).toBeDisabled()
    expect(screen.getByRole('option', { name: 'Kho thuốc' })).toBeEnabled()
  })
})
