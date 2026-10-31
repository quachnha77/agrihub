import { Bell } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Badge } from '../../components/ui.tsx'
import { formatAgo } from '../../lib/format.ts'
import { levelTone, useLatestNotices, useMarkAllNoticesRead, useMarkNoticeRead, type NoticeItem } from './api.ts'

export function NoticeRow({ notice, onOpen }: { notice: NoticeItem; onOpen?: (notice: NoticeItem) => void }) {
  const { t } = useTranslation()
  const unread = notice.read_at === null

  return (
    <li>
      <button
        type="button"
        onClick={() => onOpen?.(notice)}
        className={`block w-full px-4 py-3 text-left hover:bg-slate-50 ${unread ? 'bg-brand-50/50' : ''}`}
      >
        <span className="flex items-center gap-2">
          <Badge tone={levelTone[notice.level]}>{t(`notifications.levels.${notice.level}`)}</Badge>
          <span className={`text-sm ${unread ? 'font-semibold' : 'font-medium'} text-slate-900`}>{notice.title}</span>
        </span>
        {notice.body && <span className="mt-1 block whitespace-pre-line text-sm text-slate-600">{notice.body}</span>}
        <span className="mt-1 block text-xs text-slate-500">{formatAgo(notice.created_at)}</span>
      </button>
    </li>
  )
}

/** Chuông thông báo: số chưa đọc, danh sách gần đây, đánh dấu đã đọc. */
export function NotificationBell() {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const container = useRef<HTMLDivElement>(null)
  const notices = useLatestNotices()
  const markRead = useMarkNoticeRead()
  const markAll = useMarkAllNoticesRead()

  useEffect(() => {
    if (!open) return

    const onPointer = (event: MouseEvent) => {
      if (container.current && !container.current.contains(event.target as Node)) setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false)

    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)

    return () => {
      document.removeEventListener('mousedown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const unread = notices.data?.unread_count ?? 0
  const items = notices.data?.data ?? []

  return (
    <div ref={container} className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-label={unread > 0 ? `${t('notifications.open')} (${t('notifications.unread', { count: unread })})` : t('notifications.open')}
        aria-expanded={open}
        aria-haspopup="true"
        className="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 hover:text-slate-900"
      >
        <Bell className="h-5 w-5" aria-hidden="true" />
        {unread > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white" data-testid="unread-count">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 z-40 mt-2 w-96 max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-pop motion-safe:animate-pop-in">
          <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <strong className="text-sm">{t('notifications.title')}</strong>
            {unread > 0 && (
              <button type="button" onClick={() => markAll.mutate()} className="text-xs font-medium text-brand-700 hover:underline">
                {t('notifications.markAllRead')}
              </button>
            )}
          </div>

          {items.length === 0 ? (
            <p className="px-4 py-6 text-center text-sm text-slate-500">{t('notifications.empty')}</p>
          ) : (
            <ul className="max-h-96 divide-y divide-slate-100 overflow-y-auto">
              {items.map((notice) => (
                <NoticeRow key={notice.id} notice={notice} onOpen={(n) => n.read_at === null && markRead.mutate(n.id)} />
              ))}
            </ul>
          )}

          <Link to="/notifications" onClick={() => setOpen(false)} className="block border-t border-slate-100 px-4 py-2 text-center text-sm font-medium text-brand-700 hover:bg-slate-50">
            {t('notifications.viewAll')}
          </Link>
        </div>
      )}
    </div>
  )
}
