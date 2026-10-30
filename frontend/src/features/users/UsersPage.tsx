import { KeyRound, Lock, LockOpen, Pencil, Plus, Trash2 } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Badge, Button, Card, ConfirmDialog, Pagination, Spinner } from '../../components/ui.tsx'
import { formatDateTime } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useDeleteUser, useResetPassword, useSetUserStatus, useUsers, type UserRow } from './api.ts'
import { TemporaryPasswordDialog } from './TemporaryPasswordDialog.tsx'
import { UserDialog } from './UserDialog.tsx'

type Action = { kind: 'reset' | 'lock' | 'unlock' | 'delete'; user: UserRow }

/** Người dùng của doanh nghiệp: thêm, sửa, gán vai trò/chi nhánh, khóa/mở khóa, cấp mật khẩu tạm, xóa. */
export default function UsersPage() {
  const { t } = useTranslation()
  const { user: me, can } = useAuth()
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const search = useDeferredValue(q)
  const users = useUsers({ q: search, page })

  const setStatus = useSetUserStatus()
  const reset = useResetPassword()
  const remove = useDeleteUser()

  const [editing, setEditing] = useState<UserRow | 'new' | null>(null)
  const [action, setAction] = useState<Action | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [secret, setSecret] = useState<{ name: string; password: string } | null>(null)

  const busy = setStatus.isPending || reset.isPending || remove.isPending

  async function runAction() {
    if (!action) return
    setActionError(null)

    try {
      if (action.kind === 'reset') {
        const result = await reset.mutateAsync(action.user.id)
        setSecret({ name: action.user.name, password: result.temporary_password ?? '' })
      } else if (action.kind === 'delete') {
        await remove.mutateAsync(action.user.id)
      } else {
        await setStatus.mutateAsync({ id: action.user.id, status: action.kind === 'lock' ? 'disabled' : 'active' })
      }

      setAction(null)
    } catch (error) {
      setActionError(describeError(error, t))
    }
  }

  const open = (kind: Action['kind'], user: UserRow) => {
    setActionError(null)
    setAction({ kind, user })
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('users.title')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('users.intro')}</p>
        </div>
        {can('tenant.user.create', 'tenant') && (
          <Button onClick={() => setEditing('new')}>
            <Plus className="h-4 w-4" aria-hidden="true" />
            {t('users.add')}
          </Button>
        )}
      </div>

      <Card>
        <input
          type="search"
          value={q}
          onChange={(event) => {
            setQ(event.target.value)
            setPage(1)
          }}
          aria-label={t('common.search')}
          placeholder={t('users.searchPlaceholder')}
          className="mb-4 block w-full max-w-sm rounded-md border border-slate-300 px-3 py-2 text-sm"
        />

        {users.isPending ? (
          <div className="flex justify-center py-6"><Spinner /></div>
        ) : users.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : users.data.data.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('users.empty')}</p>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[56rem] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="py-2 pr-3">{t('users.name')}</th>
                    <th scope="col" className="py-2 pr-3">{t('users.contact')}</th>
                    <th scope="col" className="py-2 pr-3">{t('users.roles')}</th>
                    <th scope="col" className="py-2 pr-3">{t('users.branches')}</th>
                    <th scope="col" className="py-2 pr-3">{t('users.status')}</th>
                    <th scope="col" className="py-2 pr-3">{t('users.lastLogin')}</th>
                    <th scope="col" className="py-2 text-right">{t('backup.list.actions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {users.data.data.map((user) => {
                    const self = user.id === me?.id
                    const manage = can('tenant.user.update') && !self

                    return (
                      <tr key={user.id}>
                        <td className="py-2 pr-3 font-medium text-slate-900">
                          {user.name}
                          {self && <span className="ml-2 text-xs font-normal text-slate-500">({t('users.you')})</span>}
                        </td>
                        <td className="py-2 pr-3">
                          <span className="block">{user.email}</span>
                          {user.phone && <span className="block text-xs text-slate-500">{user.phone}</span>}
                        </td>
                        <td className="py-2 pr-3">{user.roles.map((r) => r.name).join(', ') || '—'}</td>
                        <td className="py-2 pr-3">{user.branches.map((b) => b.code).join(', ') || '—'}</td>
                        <td className="py-2 pr-3">
                          <Badge tone={user.status === 'active' ? 'success' : 'danger'}>{user.status === 'active' ? t('users.active') : t('users.disabled')}</Badge>
                        </td>
                        <td className="py-2 pr-3 whitespace-nowrap">{formatDateTime(user.last_login_at)}</td>
                        <td className="py-2 text-right">
                          <div className="flex justify-end gap-1">
                            {can('tenant.user.update') && (
                              <Button variant="ghost" onClick={() => setEditing(user)} aria-label={`${t('common.edit')} ${user.name}`}>
                                <Pencil className="h-4 w-4" aria-hidden="true" />
                              </Button>
                            )}
                            {manage && (
                              <Button variant="ghost" onClick={() => open('reset', user)} aria-label={`${t('users.resetPassword')} ${user.name}`}>
                                <KeyRound className="h-4 w-4" aria-hidden="true" />
                              </Button>
                            )}
                            {manage && (
                              <Button variant="ghost" onClick={() => open(user.status === 'active' ? 'lock' : 'unlock', user)} aria-label={`${user.status === 'active' ? t('users.lock') : t('users.unlock')} ${user.name}`}>
                                {user.status === 'active' ? <Lock className="h-4 w-4" aria-hidden="true" /> : <LockOpen className="h-4 w-4" aria-hidden="true" />}
                              </Button>
                            )}
                            {can('tenant.user.delete') && !self && (
                              <Button variant="ghost" onClick={() => open('delete', user)} aria-label={`${t('common.delete')} ${user.name}`}>
                                <Trash2 className="h-4 w-4 text-red-700" aria-hidden="true" />
                              </Button>
                            )}
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <Pagination page={users.data.meta.current_page} lastPage={users.data.meta.last_page} total={users.data.meta.total} onPage={setPage} />
          </>
        )}
      </Card>

      {editing && (
        <UserDialog
          user={editing === 'new' ? undefined : editing}
          onClose={() => setEditing(null)}
          onCreated={(user, password) => password && setSecret({ name: user.name, password })}
        />
      )}

      {action && (
        <ConfirmDialog
          title={t(`users.confirm.${action.kind}.title`)}
          confirmLabel={t(`users.confirm.${action.kind}.button`)}
          danger={action.kind === 'delete' || action.kind === 'lock'}
          loading={busy}
          error={actionError}
          onConfirm={runAction}
          onClose={() => setAction(null)}
        >
          {t(`users.confirm.${action.kind}.text`, { name: action.user.name })}
        </ConfirmDialog>
      )}

      {secret && <TemporaryPasswordDialog userName={secret.name} password={secret.password} onClose={() => setSecret(null)} />}
    </div>
  )
}
