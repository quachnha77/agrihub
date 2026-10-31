import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { meOk, mockApi, renderApp, tenantUser } from '../test/utils.tsx'
import { FilterBar, FilterSelect, SearchField } from './page.tsx'
import { DataTable } from './table.tsx'

/*
 * Hệ thống thiết kế dùng chung: bảng tự gắn nhãn cột để hiện thành thẻ trên điện thoại, thanh lọc thu gọn trên điện thoại,
 * ngăn kéo menu. CSS không chạy trong jsdom nên test kiểm tra phần hành vi/thuộc tính mà CSS dựa vào.
 */

describe('bảng dữ liệu (DataTable)', () => {
  const Header = () => (
    <thead>
      <tr>
        <th data-role="title">Sản phẩm</th>
        <th>Giá tiền mặt</th>
        <th data-role="actions"><span className="sr-only">Thao tác</span></th>
      </tr>
    </thead>
  )

  it('gắn nhãn cột và vai trò cột vào từng ô để thẻ trên điện thoại biết ô nào là gì', () => {
    render(
      <DataTable>
        <Header />
        <tbody>
          <tr>
            <td>Phân NPK</td>
            <td>15.500 ₫</td>
            <td><button type="button">Sửa</button></td>
          </tr>
          <tr>
            <td colSpan={3}>Hàng gộp cột</td>
          </tr>
        </tbody>
      </DataTable>,
    )

    const title = screen.getByText('Phân NPK')
    expect(title).toHaveAttribute('data-label', 'Sản phẩm')
    expect(title).toHaveAttribute('data-role', 'title')
    expect(screen.getByText('15.500 ₫')).toHaveAttribute('data-label', 'Giá tiền mặt')
    expect(screen.getByText('15.500 ₫')).not.toHaveAttribute('data-role')

    const actions = screen.getByRole('button', { name: 'Sửa' }).closest('td')!
    expect(actions).toHaveAttribute('data-label', '') // cột chỉ có chữ ẩn thì không có nhãn
    expect(actions).toHaveAttribute('data-role', 'actions')

    expect(screen.getByText('Hàng gộp cột')).not.toHaveAttribute('data-label') // ô gộp nhiều cột không gắn nhãn
  })

  it('dòng thêm vào sau (đổi trang, thêm dòng) cũng được gắn nhãn', async () => {
    function Rows() {
      const [rows, setRows] = useState(['Dây tưới'])

      return (
        <>
          <button type="button" onClick={() => setRows((current) => [...current, 'Lúa giống'])}>Thêm</button>
          <DataTable>
            <Header />
            <tbody>
              {rows.map((name) => (
                <tr key={name}>
                  <td>{name}</td>
                  <td>1</td>
                  <td />
                </tr>
              ))}
            </tbody>
          </DataTable>
        </>
      )
    }

    render(<Rows />)
    expect(screen.getByText('Dây tưới')).toHaveAttribute('data-label', 'Sản phẩm')

    await userEvent.setup().click(screen.getByRole('button', { name: 'Thêm' }))
    await waitFor(() => expect(screen.getByText('Lúa giống')).toHaveAttribute('data-label', 'Sản phẩm'))
  })

  it('bảng nhập liệu dùng kiểu riêng cho điện thoại (mỗi ô một dòng, nhãn phía trên)', () => {
    render(
      <DataTable variant="form">
        <Header />
        <tbody />
      </DataTable>,
    )

    expect(screen.getByRole('table')).toHaveClass('data-table', 'data-table-form')
  })
})

describe('thanh lọc (FilterBar)', () => {
  function Bar({ onClear = () => {}, activeCount = 0 }: { onClear?: () => void; activeCount?: number }) {
    const [type, setType] = useState('')

    return (
      <FilterBar activeCount={activeCount} onClear={onClear} search={<SearchField label="Tìm kiếm" value="" onChange={() => {}} placeholder="Tên hoặc mã" />}>
        <FilterSelect label="Loại hàng" value={type} onChange={(event) => setType(event.target.value)}>
          <option value="">Tất cả</option>
          <option value="seed">Hạt giống</option>
        </FilterSelect>
      </FilterBar>
    )
  }

  it('ô lọc gắn nhãn đúng: tên bộ lọc nằm trong ô nhưng vẫn là nhãn của ô chọn (đọc màn hình, test tìm theo nhãn)', async () => {
    render(<Bar />)

    const select = screen.getByLabelText('Loại hàng')
    expect(select.tagName).toBe('SELECT')
    await userEvent.setup().selectOptions(select, 'seed')
    expect(select).toHaveValue('seed')
    expect(screen.getByRole('searchbox', { name: 'Tìm kiếm' })).toBeInTheDocument()
  })

  it('điện thoại: nút "Bộ lọc" mở/đóng vùng ô lọc và hiện số bộ lọc đang bật', async () => {
    render(<Bar activeCount={2} />)
    const user = userEvent.setup()

    const toggle = screen.getByRole('button', { name: /Bộ lọc/ })
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    expect(within(toggle).getByText('2')).toBeInTheDocument()
    const panel = document.getElementById(toggle.getAttribute('aria-controls')!)!
    expect(panel).toHaveClass('hidden') // đóng: ẩn trên điện thoại (md:contents trả lại hàng ngang trên máy tính)

    await user.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
    expect(panel).not.toHaveClass('hidden')

    await user.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
  })

  it('có bộ lọc đang bật thì hiện "Xóa bộ lọc"; không có thì không hiện', async () => {
    const onClear = vi.fn()
    const { rerender } = render(<Bar onClear={onClear} activeCount={0} />)
    expect(screen.queryByRole('button', { name: 'Xóa bộ lọc' })).not.toBeInTheDocument()

    rerender(<Bar onClear={onClear} activeCount={1} />)
    await userEvent.setup().click(screen.getByRole('button', { name: 'Xóa bộ lọc' }))
    expect(onClear).toHaveBeenCalledTimes(1)
  })

  it('đang chờ dữ liệu tham chiếu thì chỉ hiện khung giữ chỗ, ẩn khỏi trình đọc màn hình', () => {
    const { container } = render(
      <FilterBar loading selects={2} search={<SearchField label="Tìm kiếm" value="" onChange={() => {}} />}>
        <FilterSelect label="Loại hàng" value="" onChange={() => {}}>
          <option value="">Tất cả</option>
        </FilterSelect>
      </FilterBar>,
    )

    expect(screen.queryByLabelText('Loại hàng')).not.toBeInTheDocument()
    expect(container.firstElementChild).toHaveAttribute('aria-hidden', 'true')
  })
})

describe('khung ứng dụng', () => {
  it('điện thoại: nút menu mở ngăn kéo, chọn mục thì sang trang và ngăn kéo tự đóng', async () => {
    mockApi({
      'GET /api/v1/auth/me': meOk(tenantUser({ permissions: { 'catalog.product.view': 'tenant', 'tenant.branch.view': 'tenant' } })),
      'GET /api/v1/products': { json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } },
      'GET /api/v1/branches': { json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } },
      'GET /api/v1/products/types': { json: { data: [], meta: { max_units: 8 } } },
      'GET /api/v1/categories': { json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 100 } } },
      'GET /api/v1/brands': { json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 100 } } },
    })
    const { router } = renderApp('/')
    const user = userEvent.setup()

    const open = await screen.findByRole('button', { name: 'Mở menu' })
    expect(open).toHaveAttribute('aria-expanded', 'false')
    await user.click(open)
    const close = screen.getByRole('button', { name: 'Đóng menu' })
    expect(close).toHaveAttribute('aria-expanded', 'true')

    await user.click(within(screen.getByRole('navigation', { name: 'Menu chính' })).getByRole('link', { name: 'Sản phẩm' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/products'))
    expect(await screen.findByRole('button', { name: 'Mở menu' })).toHaveAttribute('aria-expanded', 'false')
  })

  it('mục menu của trang đang mở được đánh dấu aria-current; mục quản trị nằm trong nhóm "Quản lý"', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser({ permissions: { 'tenant.branch.view': 'tenant' } })) })
    renderApp('/')

    const nav = await screen.findByRole('navigation', { name: 'Menu chính' })
    expect(within(nav).getByRole('link', { name: 'Tổng quan' })).toHaveAttribute('aria-current', 'page')
    expect(within(nav).getByText('Quản lý')).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Chi nhánh' })).not.toHaveAttribute('aria-current')
  })

  it('không có mục nào của nhóm thì không hiện tên nhóm', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(tenantUser({ permissions: {} })) })
    renderApp('/')

    const nav = await screen.findByRole('navigation', { name: 'Menu chính' })
    expect(within(nav).queryByText('Quản lý')).not.toBeInTheDocument()
    expect(within(nav).queryByText('Hệ thống')).not.toBeInTheDocument()
  })
})
