import { KeyRound, Lock, LockOpen, Pencil, Plus, Trash2, Users } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, FilterBar, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Badge, Button, Card, ConfirmDialog, IconButton, Pagination } from '../../components/ui.tsx'
import { formatDateTime } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useClampPage } from '../../lib/pagination.ts'
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
  const canCreate = can('tenant.user.create', 'tenant')

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

  useClampPage(users.data?.meta, setPage)

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('users.title')}
        description={t('users.intro')}
        actions={
          canCreate && (
            <Button onClick={() => setEditing('new')}>
              <Plus className="h-4 w-4" aria-hidden="true" />
              {t('users.add')}
            </Button>
          )
        }
      />

      <Card>
        <FilterBar
          search={
            <SearchField
              label={t('common.search')}
              value={q}
              onChange={(event) => {
                setQ(event.target.value)
                setPage(1)
              }}
              placeholder={t('users.searchPlaceholder')}
            />
          }
        />

        {users.isPending ? (
          <TableSkeleton columns={7} actions minWidth="56rem" />
        ) : users.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : users.data.data.length === 0 ? (
          <EmptyState icon={Users} title={t('users.empty')} description={q !== '' ? t('common.tryOtherFilters') : undefined} />
        ) : (
          <Refreshable refreshing={users.isPlaceholderData}>
            <DataTable minWidth="56rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('users.name')}</th>
                  <th scope="col" data-role="wide" className="py-2.5 pr-4">{t('users.contact')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('users.roles')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('users.branches')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('users.status')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('users.lastLogin')}</th>
                  <th scope="col" data-role="actions" className="py-2.5 text-right"><span className="sr-only">{t('backup.list.actions')}</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {users.data.data.map((user) => {
                  const self = user.id === me?.id
                  const manage = can('tenant.user.update') && !self

                  return (
                    <tr key={user.id}>
                      <td className="py-3 pr-4 font-medium text-slate-900">
                        {user.name}
                        {self && <span className="ml-2 text-xs font-normal text-slate-500">({t('users.you')})</span>}
                      </td>
                      <td className="py-3 pr-4">
                        <span className="block md:whitespace-nowrap">{user.email}</span>
                        {user.phone && <span className="block text-xs text-slate-500">{user.phone}</span>}
                      </td>
                      <td className="py-3 pr-4">{user.roles.map((r) => r.name).join(', ') || '—'}</td>
                      <td className="py-3 pr-4">{user.branches.map((b) => b.code).join(', ') || '—'}</td>
                      <td className="py-3 pr-4">
                        <Badge tone={user.status === 'active' ? 'success' : 'danger'} dot>{user.status === 'active' ? t('users.active') : t('users.disabled')}</Badge>
                      </td>
                      <td className="py-3 pr-4 whitespace-nowrap">{formatDateTime(user.last_login_at)}</td>
                      <td className="py-3 text-right">
                        <div className="flex flex-wrap justify-end gap-0.5">
                          {can('tenant.user.update') && (
                            <IconButton label={`${t('common.edit')} ${user.name}`} text={t('common.edit')} onClick={() => setEditing(user)}>
                              <Pencil className="h-4 w-4" aria-hidden="true" />
                            </IconButton>
                          )}
                          {manage && (
                            <IconButton label={`${t('users.resetPassword')} ${user.name}`} text={t('users.resetPasswordShort')} onClick={() => open('reset', user)}>
                              <KeyRound className="h-4 w-4" aria-hidden="true" />
                            </IconButton>
                          )}
                          {manage && (
                            <IconButton
                              label={`${user.status === 'active' ? t('users.lock') : t('users.unlock')} ${user.name}`}
                              text={user.status === 'active' ? t('users.lock') : t('users.unlock')}
                              onClick={() => open(user.status === 'active' ? 'lock' : 'unlock', user)}
                            >
                              {user.status === 'active' ? <Lock className="h-4 w-4" aria-hidden="true" /> : <LockOpen className="h-4 w-4" aria-hidden="true" />}
                            </IconButton>
                          )}
                          {can('tenant.user.delete') && !self && (
                            <IconButton label={`${t('common.delete')} ${user.name}`} text={t('common.delete')} onClick={() => open('delete', user)} tone="danger">
                              <Trash2 className="h-4 w-4" aria-hidden="true" />
                            </IconButton>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </DataTable>
            <Pagination page={users.data.meta.current_page} lastPage={users.data.meta.last_page} total={users.data.meta.total} onPage={setPage} />
          </Refreshable>
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
