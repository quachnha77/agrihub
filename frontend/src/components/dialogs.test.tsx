import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useToast } from '../lib/toast.ts'
import { ToastProvider } from './toast.tsx'
import { Modal } from './ui.tsx'

/*
 * Khung hộp thoại và thông báo nổi dùng chung cho mọi popup (thêm/sửa sản phẩm, khách hàng, phiếu kho...). Những hành vi dưới đây dễ
 * hỏng âm thầm (focus bị giật khi gõ, Esc đóng nhầm hộp, thông báo không tự ẩn) nên có test riêng, không phụ thuộc màn hình nghiệp vụ.
 */

describe('Modal', () => {
  it('Esc chỉ đóng hộp trên cùng: hộp "Bỏ thay đổi?" mở chồng lên biểu mẫu không kéo biểu mẫu đóng theo', async () => {
    const outer = vi.fn()
    const inner = vi.fn()

    function Stacked() {
      const [open, setOpen] = useState(false)

      return (
        <Modal title="Ngoài" onClose={outer}>
          <button onClick={() => setOpen(true)}>Mở hộp trong</button>
          {open && (
            <Modal title="Trong" onClose={inner}>
              <p>nội dung</p>
            </Modal>
          )}
        </Modal>
      )
    }

    render(<Stacked />)
    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Mở hộp trong' }))
    await user.keyboard('{Escape}')

    expect(inner).toHaveBeenCalledTimes(1)
    expect(outer).not.toHaveBeenCalled()
  })

  it('gõ chữ trong biểu mẫu khi hộp cha tạo lại onClose mỗi lần vẽ: focus không bị giật khỏi ô đang gõ', async () => {
    function Page() {
      const [open, setOpen] = useState(false)
      const [text, setText] = useState('')

      // `onClose` là hàm mới sau mỗi lần gõ phím (vì `text` đổi): hiệu ứng của hộp không được chạy lại. Phải có nút mở thật (focus được)
      // vì nếu hiệu ứng chạy lại thì bước dọn dẹp của nó trả focus về nút mở, kéo focus khỏi ô đang gõ.
      return (
        <>
          <button onClick={() => setOpen(true)}>Mở</button>
          {open && (
            <Modal title="Biểu mẫu" onClose={() => setOpen(false)}>
              <input aria-label="Tên" value={text} onChange={(event) => setText(event.target.value)} />
            </Modal>
          )}
        </>
      )
    }

    render(<Page />)
    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Mở' }))
    const input = screen.getByLabelText('Tên')
    await user.click(input)
    await user.keyboard('abc')

    expect(input).toHaveValue('abc')
    expect(input).toHaveFocus()
  })

  it('bấm nền chỉ đóng khi cho phép; biểu mẫu tắt việc này để bấm nhầm không mất dữ liệu', async () => {
    const closable = vi.fn()
    const guarded = vi.fn()
    const { rerender } = render(
      <Modal title="Hộp xem" onClose={closable}>
        <p>x</p>
      </Modal>,
    )
    const user = userEvent.setup()

    await user.pointer({ keys: '[MouseLeft>]', target: screen.getByRole('dialog').parentElement! })
    expect(closable).toHaveBeenCalledTimes(1)

    rerender(
      <Modal title="Biểu mẫu" onClose={guarded} dismissOnBackdrop={false}>
        <p>x</p>
      </Modal>,
    )
    await user.pointer({ keys: '[MouseLeft>]', target: screen.getByRole('dialog').parentElement! })
    expect(guarded).not.toHaveBeenCalled()
  })

  it('Tab quay vòng trong hộp (không rơi ra trang nền): từ nút cuối về nút đầu, Shift+Tab ngược lại', async () => {
    render(
      <Modal title="Hộp" onClose={() => {}}>
        <button>Một</button>
        <button>Hai</button>
      </Modal>,
    )
    const user = userEvent.setup()
    const close = screen.getByRole('button', { name: 'Đóng' }) // nút đầu tiên của hộp (X ở tiêu đề)
    const last = screen.getByRole('button', { name: 'Hai' })

    last.focus()
    await user.tab()
    expect(close).toHaveFocus()

    await user.tab({ shift: true })
    expect(last).toHaveFocus()
  })

  it('khóa cuộn trang nền khi mở và trả lại đúng như cũ khi đóng', () => {
    document.body.style.overflow = 'scroll'
    const { unmount } = render(
      <Modal title="Hộp" onClose={() => {}}>
        <p>x</p>
      </Modal>,
    )

    expect(document.body.style.overflow).toBe('hidden')
    expect(document.documentElement).toHaveClass('modal-gutter') // giữ chỗ cho thanh cuộn của trang để trang phía sau không dịch
    unmount()
    expect(document.body.style.overflow).toBe('scroll')
    expect(document.documentElement).not.toHaveClass('modal-gutter')
    document.body.style.overflow = ''
  })

  it('trả focus về chỗ đã bấm để mở khi đóng hộp', async () => {
    function Page() {
      const [open, setOpen] = useState(false)

      return (
        <>
          <button onClick={() => setOpen(true)}>Mở</button>
          {open && (
            <Modal title="Hộp" onClose={() => setOpen(false)}>
              <p>x</p>
            </Modal>
          )}
        </>
      )
    }

    render(<Page />)
    const user = userEvent.setup()
    const opener = screen.getByRole('button', { name: 'Mở' })
    await user.click(opener)
    expect(screen.getByRole('dialog')).toHaveFocus()

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(opener).toHaveFocus()
  })
})

describe('thông báo nổi', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  function Triggers() {
    const toast = useToast()

    return (
      <>
        <button onClick={() => toast.success('Đã lưu xong.')}>lưu</button>
        <button onClick={() => toast.success('Đã ghi phiếu PN0001.', { label: 'Xem phiếu', to: '/phieu/1' })}>ghi</button>
        <button onClick={() => toast.error('Không lưu được.')}>lỗi</button>
      </>
    )
  }

  function renderToasts() {
    render(
      <MemoryRouter>
        <ToastProvider>
          <Triggers />
        </ToastProvider>
      </MemoryRouter>,
    )
  }

  it('hiện thông báo trong vùng aria-live và tự ẩn sau vài giây', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    renderToasts()
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })

    await user.click(screen.getByRole('button', { name: 'lưu' }))
    const message = screen.getByText('Đã lưu xong.')
    expect(message.closest('[aria-live="polite"]')).not.toBeNull()

    act(() => {
      vi.advanceTimersByTime(6500)
    })
    expect(screen.queryByText('Đã lưu xong.')).not.toBeInTheDocument()
  })

  it('nút đóng ẩn thông báo ngay', async () => {
    renderToasts()
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'lỗi' }))
    expect(screen.getByText('Không lưu được.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Đóng' }))
    expect(screen.queryByText('Không lưu được.')).not.toBeInTheDocument()
  })

  it('thông báo có liên kết hành động ("Xem phiếu") và bấm vào thì thông báo biến mất', async () => {
    renderToasts()
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'ghi' }))
    const link = screen.getByRole('link', { name: 'Xem phiếu' })
    expect(link).toHaveAttribute('href', '/phieu/1')

    await user.click(link)
    expect(screen.queryByText('Đã ghi phiếu PN0001.')).not.toBeInTheDocument()
  })

  it('chỉ giữ ba thông báo mới nhất để không che màn hình', async () => {
    renderToasts()
    const user = userEvent.setup()

    for (const name of ['lưu', 'ghi', 'lỗi', 'lưu']) await user.click(screen.getByRole('button', { name }))

    expect(screen.queryByText('Đã lưu xong.', { selector: 'p' })).toBeInTheDocument()
    expect(screen.getAllByText(/Đã lưu xong\.|Đã ghi phiếu PN0001\.|Không lưu được\./)).toHaveLength(3)
  })
})
