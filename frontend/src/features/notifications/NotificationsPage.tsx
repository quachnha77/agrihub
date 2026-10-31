import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../components/page.tsx'
import { ListSkeleton } from '../../components/skeleton.tsx'
import { Alert, Button, Card } from '../../components/ui.tsx'
import { useAllNotices, useMarkAllNoticesRead, useMarkNoticeRead } from './api.ts'
import { NoticeRow } from './NotificationBell.tsx'

export default function NotificationsPage() {
  const { t } = useTranslation()
  const query = useAllNotices()
  const markRead = useMarkNoticeRead()
  const markAll = useMarkAllNoticesRead()

  const items = query.data?.pages.flatMap((page) => page.data) ?? []
  const unread = query.data?.pages[0]?.unread_count ?? 0

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('notifications.title')}
        actions={
          unread > 0 && (
            <Button variant="secondary" onClick={() => markAll.mutate()} loading={markAll.isPending}>
              {t('notifications.markAllRead')} ({unread})
            </Button>
          )
        }
      />

      <Card className="overflow-hidden [&>div]:p-0">
        {query.isPending ? (
          <div className="px-4"><ListSkeleton rows={6} subtitle /></div>
        ) : query.isError ? (
          <Alert tone="danger" className="m-4">{t('common.loadError')}</Alert>
        ) : items.length === 0 ? (
          <p className="p-8 text-center text-sm text-slate-500">{t('notifications.empty')}</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {items.map((notice) => (
              <NoticeRow key={notice.id} notice={notice} onOpen={(n) => n.read_at === null && markRead.mutate(n.id)} />
            ))}
          </ul>
        )}
      </Card>

      {query.hasNextPage && (
        <div className="text-center">
          <Button variant="secondary" onClick={() => query.fetchNextPage()} loading={query.isFetchingNextPage}>
            {t('notifications.loadMore')}
          </Button>
        </div>
      )}
    </div>
  )
}
