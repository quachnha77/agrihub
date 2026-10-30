import { KeyRound, Lock, LockOpen } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Badge, Button, Card, ConfirmDialog, Pagination, Spinner } from '../../components/ui.tsx'
import { formatDateTime } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import type { UserRow } from '../users/api.ts'
import { TemporaryPasswordDialog } from '../users/TemporaryPasswordDialog.tsx'
import { useResetPlatformUserPassword, useSetPlatformUserStatus, useTenantUsers } from './api.ts'

type Action = { kind: 'reset' | 'lock' | 'unlock'; user: UserRow }

/** Người dùng của doanh nghiệp để hỗ trợ khách: cấp mật khẩu tạm khi họ không tự lấy lại được, khóa/mở khóa tài khoản. */
export function TenantUsersCard({ tenantId, loginUrl }: { tenantId: string; loginUrl: string | null }) {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const users = useTenantUsers(tenantId, page)
  const setStatus = useSetPlatformUserStatus(tenantId)
  const reset = useResetPlatformUserPassword()
  const [action, setAction] = useState<Action | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [secret, setSecret] = useState<{ user: UserRow; password: string } | null>(null)

  async function run() {
    if (!action) return
    setError(null)

    try {
      if (action.kind === 'reset') {
        const result = await reset.mutateAsync(action.user.id)
        setSecret({ user: action.user, password: result.temporary_password ?? '' })
      } else {
        await setStatus.mutateAsync({ id: action.user.id, status: action.kind === 'lock' ? 'disabled' : 'active' })
      }

      setAction(null)
    } catch (failure) {
      setError(describeError(failure, t))
    }
  }

  const open = (kind: Action['kind'], user: UserRow) => {
    setError(null)
    setAction({ kind, user })
  }

  return (
    <Card title={t('platform.users.title')}>
      {users.isPending ? (
        <div className="flex justify-center py-4"><Spinner /></div>
      ) : users.isError ? (
        <Alert tone="danger">{t('common.loadError')}</Alert>
      ) : users.data.data.length === 0 ? (
        <p className="py-4 text-center text-sm text-slate-500">{t('users.empty')}</p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[44rem] text-left text-sm">
              <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th scope="col" className="py-2 pr-3">{t('users.name')}</th>
                  <th scope="col" className="py-2 pr-3">{t('users.contact')}</th>
                  <th scope="col" className="py-2 pr-3">{t('users.roles')}</th>
                  <th scope="col" className="py-2 pr-3">{t('users.status')}</th>
                  <th scope="col" className="py-2 pr-3">{t('users.lastLogin')}</th>
                  <th scope="col" className="py-2 text-right">{t('backup.list.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {users.data.data.map((user) => (
                  <tr key={user.id}>
                    <td className="py-2 pr-3 font-medium text-slate-900">{user.name}</td>
                    <td className="py-2 pr-3">
                      <span className="block">{user.email}</span>
                      {user.phone && <span className="block text-xs text-slate-500">{user.phone}</span>}
                    </td>
                    <td className="py-2 pr-3">{user.roles.map((r) => r.name).join(', ') || '—'}</td>
                    <td className="py-2 pr-3">
                      <Badge tone={user.status === 'active' ? 'success' : 'danger'}>{user.status === 'active' ? t('users.active') : t('users.disabled')}</Badge>
                    </td>
                    <td className="py-2 pr-3 whitespace-nowrap">{formatDateTime(user.last_login_at)}</td>
                    <td className="py-2 text-right">
                      <div className="flex justify-end gap-1">
                        <Button variant="ghost" onClick={() => open('reset', user)} aria-label={`${t('users.resetPassword')} ${user.name}`}>
                          <KeyRound className="h-4 w-4" aria-hidden="true" />
                        </Button>
                        <Button variant="ghost" onClick={() => open(user.status === 'active' ? 'lock' : 'unlock', user)} aria-label={`${user.status === 'active' ? t('users.lock') : t('users.unlock')} ${user.name}`}>
                          {user.status === 'active' ? <Lock className="h-4 w-4" aria-hidden="true" /> : <LockOpen className="h-4 w-4" aria-hidden="true" />}
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination page={users.data.meta.current_page} lastPage={users.data.meta.last_page} total={users.data.meta.total} onPage={setPage} />
        </>
      )}

      {action && (
        <ConfirmDialog
          title={t(`users.confirm.${action.kind}.title`)}
          confirmLabel={t(`users.confirm.${action.kind}.button`)}
          danger={action.kind === 'lock'}
          loading={setStatus.isPending || reset.isPending}
          error={error}
          onConfirm={run}
          onClose={() => setAction(null)}
        >
          {t(`users.confirm.${action.kind}.text`, { name: action.user.name })}
        </ConfirmDialog>
      )}

      {secret && <TemporaryPasswordDialog userName={secret.user.name} account={secret.user.email} password={secret.password} loginUrl={loginUrl} onClose={() => setSecret(null)} />}
    </Card>
  )
}
