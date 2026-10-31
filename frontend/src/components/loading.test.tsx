import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, renderApp, tenantUser, type MockResponse } from '../test/utils.tsx'

/*
 * Quy ước tải dữ liệu của cả ứng dụng: lần tải đầu hiện skeleton đúng hình (không Spinner, không giả làm bảng), đổi trang/bộ lọc
 * giữ dữ liệu cũ thay vì xẹp rồi bung, danh sách chia trang theo máy chủ. Dùng trang Kho làm đại diện vì nhỏ nhất có đủ bảng,
 * bộ lọc và phân trang.
 */

const BRANCH = { id: 'br-1', code: 'CN-01', name: 'Chi nhánh chính' }
const PERMISSIONS = { 'inventory.warehouse.view': 'tenant', 'inventory.warehouse.create': 'tenant', 'tenant.branch.view': 'tenant' } as const

const warehouse = (n: number) => ({ id: `w-${n}`, branch: BRANCH, code: `KHO-${n}`, name: `Kho số ${n}`, address: null, status: 'active' })
const pageOf = (rows: unknown[], meta: { current_page: number; last_page: number; total: number }) => ({ data: rows, meta: { per_page: 20, ...meta } })

/** Trả về `GET /warehouses` theo tham số `page` của yêu cầu; `held` giữ riêng phản hồi của một trang. */
function warehouses(handlers: Record<number, (() => MockResponse) | undefined>) {
  return (call: { path: string }): MockResponse => {
    const page = Number(new URL(call.path, 'http://x').searchParams.get('page') ?? '1')

    return handlers[page]?.() ?? { json: pageOf([warehouse(1)], { current_page: 1, last_page: 1, total: 1 }) }
  }
}

const base = (extra: Record<string, unknown> = {}) =>
  ({
    'GET /api/v1/auth/me': meOk(tenantUser({ permissions: PERMISSIONS })),
    'GET /api/v1/branches': { json: pageOf([{ ...BRANCH, phone: null, address: null, status: 'active' }], { current_page: 1, last_page: 1, total: 1 }) },
    ...extra,
  }) as never

const gate = () => {
  let release!: () => void
  const promise = new Promise<void>((resolve) => { release = resolve })

  return { promise, release }
}

describe('đang tải', () => {
  it('chờ phiên đăng nhập: hiện khung ứng dụng giữ chỗ thay cho trang trắng, xong thì thay bằng giao diện thật', async () => {
    const auth = gate()
    mockApi(base({
      'GET /api/v1/auth/me': { ...meOk(tenantUser({ permissions: PERMISSIONS })), after: auth.promise },
      'GET /api/v1/warehouses': warehouses({}),
    }))
    renderApp('/warehouses')

    expect(await screen.findByText('Đang tải…')).toBeInTheDocument()
    expect(screen.getByText('AgriHub')).toBeInTheDocument() // thanh trên đã có chỗ, không nhảy khi vào trang thật
    expect(screen.queryByRole('navigation', { name: 'Menu chính' })).not.toBeInTheDocument()

    auth.release()
    expect(await screen.findByRole('navigation', { name: 'Menu chính' })).toBeInTheDocument()
    expect(await screen.findByRole('table')).toBeInTheDocument()
  })

  it('danh sách: skeleton có thông báo "Đang tải…" cho trình đọc màn hình và không giả làm bảng; dữ liệu về thì thay hẳn', async () => {
    const list = gate()
    mockApi(base({ 'GET /api/v1/warehouses': { ...warehouses({})({ path: '/x' }), after: list.promise } }))
    renderApp('/warehouses')

    await screen.findByRole('navigation', { name: 'Menu chính' })
    const loading = await screen.findByRole('status')
    expect(within(loading).getByText('Đang tải…')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument() // bảng giữ chỗ bị ẩn khỏi trình đọc màn hình

    list.release()
    const table = await screen.findByRole('table')
    expect(within(table).getByText('Kho số 1')).toBeInTheDocument()
    expect(screen.queryByText('Đang tải…')).not.toBeInTheDocument()
  })

  it('ô lọc và bảng chờ danh sách chi nhánh rồi hiện cùng lúc: ô lọc/cột "Chi nhánh" có thêm muộn không làm trang dịch', async () => {
    const branches = gate()
    const two = [BRANCH, { id: 'br-2', code: 'HN-02', name: 'Chi nhánh Hà Nội' }].map((b) => ({ ...b, phone: null, address: null, status: 'active' }))
    const server = mockApi(base({
      'GET /api/v1/branches': { json: pageOf(two, { current_page: 1, last_page: 1, total: 2 }), after: branches.promise },
      'GET /api/v1/warehouses': warehouses({}),
    }))
    renderApp('/warehouses')

    await screen.findByRole('navigation', { name: 'Menu chính' })
    await waitFor(() => expect(server.calls.some((c) => c.path.startsWith('/api/v1/warehouses?'))).toBe(true))
    await new Promise((resolve) => setTimeout(resolve, 50)) // danh sách kho đã về, chi nhánh còn chờ

    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Trạng thái')).not.toBeInTheDocument()
    expect(screen.getByText('Đang tải…')).toBeInTheDocument()

    branches.release()
    expect(await screen.findByLabelText('Chi nhánh')).toBeInTheDocument() // ô lọc chi nhánh có mặt ngay từ lần hiện đầu
    expect(screen.getByLabelText('Trạng thái')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByRole('columnheader', { name: 'Chi nhánh' })).toBeInTheDocument()
  })

  it('có lỗi tải thì hiện thông báo lỗi, không kẹt ở skeleton', async () => {
    mockApi(base({ 'GET /api/v1/warehouses': { status: 500, json: { title: 'Lỗi máy chủ', status: 500 } } }))
    renderApp('/warehouses')

    expect(await screen.findByText('Không tải được dữ liệu. Vui lòng thử lại.')).toBeInTheDocument()
    expect(screen.queryByText('Đang tải…')).not.toBeInTheDocument()
  })
})

describe('phân trang', () => {
  const three = (n: number) => pageOf([warehouse(n)], { current_page: n, last_page: 3, total: 45 })

  it('xin 20 mục mỗi trang, đổi trang gửi đúng số trang và hiện "Trang x/y"', async () => {
    const server = mockApi(base({ 'GET /api/v1/warehouses': warehouses({ 1: () => ({ json: three(1) }), 2: () => ({ json: three(2) }) }) }))
    renderApp('/warehouses')
    const user = userEvent.setup()

    expect(await screen.findByText('Kho số 1')).toBeInTheDocument()
    expect(screen.getByText(/Tổng 45 mục · Trang 1\/3/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Trước' })).toBeDisabled()
    expect(server.calls.some((c) => c.path.startsWith('/api/v1/warehouses?per_page=20') && c.path.includes('page=1'))).toBe(true)

    await user.click(screen.getByRole('button', { name: 'Sau' }))
    expect(await screen.findByText('Kho số 2')).toBeInTheDocument()
    expect(screen.getByText(/Trang 2\/3/)).toBeInTheDocument()
    expect(server.calls.some((c) => c.path.startsWith('/api/v1/warehouses?per_page=20') && c.path.includes('page=2'))).toBe(true)
  })

  it('đổi bộ lọc thì về trang 1', async () => {
    const server = mockApi(base({ 'GET /api/v1/warehouses': warehouses({ 1: () => ({ json: three(1) }), 2: () => ({ json: three(2) }) }) }))
    renderApp('/warehouses')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Sau' }))
    await screen.findByText('Kho số 2')

    await user.selectOptions(screen.getByLabelText('Trạng thái'), 'inactive')

    await waitFor(() => {
      const last = server.calls.filter((c) => c.path.startsWith('/api/v1/warehouses?per_page=20')).at(-1)
      expect(last?.path).toContain('status=inactive')
      expect(last?.path).toContain('page=1')
    })
  })

  it('sang trang khác: giữ danh sách cũ (làm mờ) cho đến khi trang mới về, không quay lại skeleton', async () => {
    const second = gate()
    mockApi(base({ 'GET /api/v1/warehouses': warehouses({ 1: () => ({ json: three(1) }), 2: () => ({ json: three(2), after: second.promise }) }) }))
    renderApp('/warehouses')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Sau' }))

    // Trang 2 chưa về: dòng của trang 1 vẫn còn, vùng bảng đánh dấu đang làm mới, không có skeleton.
    expect(screen.getByText('Kho số 1')).toBeInTheDocument()
    expect(screen.getByRole('table').closest('[aria-busy="true"]')).not.toBeNull()
    expect(screen.queryByText('Đang tải…')).not.toBeInTheDocument()

    second.release()
    expect(await screen.findByText('Kho số 2')).toBeInTheDocument()
    expect(screen.queryByText('Kho số 1')).not.toBeInTheDocument()
    expect(screen.getByRole('table').closest('[aria-busy="true"]')).toBeNull()
  })

  it('trang hiện tại vượt quá số trang (vừa xóa mục cuối của trang cuối): lùi về trang cuối thay vì báo trống', async () => {
    const server = mockApi(base({
      'GET /api/v1/warehouses': warehouses({
        1: () => ({ json: pageOf([warehouse(1)], { current_page: 1, last_page: 3, total: 41 }) }),
        2: () => ({ json: pageOf([warehouse(2)], { current_page: 2, last_page: 3, total: 41 }) }), // chưa biết dữ liệu đã giảm
        // Trang 3 không còn (dữ liệu giảm ở nơi khác): máy chủ trả danh sách rỗng và số trang mới.
        3: () => ({ json: pageOf([], { current_page: 3, last_page: 2, total: 21 }) }),
      }),
    }))
    renderApp('/warehouses')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Sau' }))
    await screen.findByText('Kho số 2')
    await user.click(screen.getByRole('button', { name: 'Sau' }))

    // Thứ tự yêu cầu: 1 → 2 → 3 (rỗng) → tự lùi về 2. Kiểm tra cả dãy để không khớp nhầm với lần gọi trang 2 trước đó.
    const pages = () => server.calls.filter((c) => c.path.startsWith('/api/v1/warehouses?per_page=20')).map((c) => new URL(c.path, 'http://x').searchParams.get('page'))
    await waitFor(() => expect(pages()).toEqual(['1', '2', '3', '2']))
    expect(await screen.findByText('Kho số 2')).toBeInTheDocument()
    expect(screen.queryByText('Chưa có kho nào.')).not.toBeInTheDocument()
  })
})
