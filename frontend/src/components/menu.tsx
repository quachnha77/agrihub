import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { Link, type To } from 'react-router'
import type { LucideIcon } from 'lucide-react'

/**
 * Menu thả xuống (menu tài khoản, thao tác phụ). Bàn phím: Enter/Space mở, ↑ ↓ Home End di chuyển, Esc đóng và trả focus về nút,
 * Tab đóng. Bấm ra ngoài hoặc chọn một mục cũng đóng. `children` nhận hàm `close` để mục tự đóng menu sau khi chọn.
 */
export function Menu({ label, trigger, triggerClassName = '', align = 'right', children }: { label: string; trigger: ReactNode; triggerClassName?: string; align?: 'left' | 'right'; children: (close: () => void) => ReactNode }) {
  const [open, setOpen] = useState(false)
  const root = useRef<HTMLDivElement>(null)
  const button = useRef<HTMLButtonElement>(null)
  const id = useId()

  const items = (): HTMLElement[] => Array.from(root.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])

  useEffect(() => {
    if (!open) return

    items()[0]?.focus()

    const onPointer = (event: PointerEvent) => {
      if (!root.current?.contains(event.target as Node)) setOpen(false)
    }
    const onKey = (event: globalThis.KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setOpen(false)
      button.current?.focus()
    }
    document.addEventListener('pointerdown', onPointer)
    document.addEventListener('keydown', onKey)

    return () => {
      document.removeEventListener('pointerdown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  function onMenuKey(event: KeyboardEvent<HTMLDivElement>) {
    const list = items()
    const index = list.indexOf(document.activeElement as HTMLElement)
    const move = (to: number) => {
      event.preventDefault()
      list[(to + list.length) % list.length]?.focus()
    }

    if (event.key === 'ArrowDown') move(index + 1)
    else if (event.key === 'ArrowUp') move(index - 1)
    else if (event.key === 'Home') move(0)
    else if (event.key === 'End') move(list.length - 1)
    else if (event.key === 'Tab') setOpen(false)
  }

  return (
    <div ref={root} className="relative">
      <button
        ref={button}
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? id : undefined}
        aria-label={label}
        onClick={() => setOpen((value) => !value)}
        className={`focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${triggerClassName}`}
      >
        {trigger}
      </button>
      {open && (
        <div id={id} role="menu" aria-label={label} onKeyDown={onMenuKey} className={`absolute z-40 mt-2 min-w-60 rounded-xl border border-slate-200 bg-white p-1.5 shadow-pop motion-safe:animate-pop-in ${align === 'right' ? 'right-0' : 'left-0'}`}>
          {children(() => setOpen(false))}
        </div>
      )}
    </div>
  )
}

/** Một mục của <Menu>: là liên kết nếu có `to` (kèm `state` nếu cần, vd. từ `useDialog().link()`), ngược lại là nút. */
export function MenuItem({ icon: Icon, to, state, onSelect, danger = false, children }: { icon?: LucideIcon; to?: To; state?: unknown; onSelect?: () => void; danger?: boolean; children: ReactNode }) {
  const className = `flex w-full items-center gap-2.5 rounded-lg px-3 py-2.5 text-left text-sm font-medium outline-none focus-visible:bg-slate-100 ${danger ? 'text-red-700 hover:bg-red-50 focus-visible:bg-red-50' : 'text-slate-800 hover:bg-slate-100'}`
  const content = (
    <>
      {Icon && <Icon className="h-4 w-4 shrink-0 text-slate-500" aria-hidden="true" />}
      {children}
    </>
  )

  if (to) {
    return (
      <Link role="menuitem" tabIndex={-1} to={to} state={state} onClick={onSelect} className={className}>
        {content}
      </Link>
    )
  }

  return (
    <button role="menuitem" tabIndex={-1} type="button" onClick={onSelect} className={className}>
      {content}
    </button>
  )
}
