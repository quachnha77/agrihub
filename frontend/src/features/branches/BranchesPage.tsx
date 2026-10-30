import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Badge, Button, Card, ConfirmDialog, Pagination, Spinner } from '../../components/ui.tsx'
import { describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useBranches, useDeleteBranch, type Branch } from './api.ts'
import { BranchDialog } from './BranchDialog.tsx'

/** Chi nhánh của doanh nghiệp: xem, thêm, sửa, xóa. Quyền theo `tenant.branch.*` (máy chủ kiểm tra lại mọi thao tác). */
export default function BranchesPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const search = useDeferredValue(q)
  const branches = useBranches({ q: search, page })
  const remove = useDeleteBranch()
  const [editing, setEditing] = useState<Branch | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Branch | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  async function confirmDelete() {
    if (!deleting) return
    setDeleteError(null)

    try {
      await remove.mutateAsync(deleting.id)
      setDeleting(null)
    } catch (error) {
      setDeleteError(describeError(error, t))
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('branches.title')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('branches.intro')}</p>
        </div>
        {can('tenant.branch.create') && (
          <Button onClick={() => setEditing('new')}>
            <Plus className="h-4 w-4" aria-hidden="true" />
            {t('branches.add')}
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
          placeholder={t('branches.searchPlaceholder')}
          className="mb-4 block w-full max-w-sm rounded-md border border-slate-300 px-3 py-2 text-sm"
        />

        {branches.isPending ? (
          <div className="flex justify-center py-6"><Spinner /></div>
        ) : branches.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : branches.data.data.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('branches.empty')}</p>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[40rem] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="py-2 pr-3">{t('branches.code')}</th>
                    <th scope="col" className="py-2 pr-3">{t('branches.name')}</th>
                    <th scope="col" className="py-2 pr-3">{t('branches.phone')}</th>
                    <th scope="col" className="py-2 pr-3">{t('branches.address')}</th>
                    <th scope="col" className="py-2 pr-3">{t('branches.status')}</th>
                    <th scope="col" className="py-2 text-right">{t('backup.list.actions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {branches.data.data.map((branch) => (
                    <tr key={branch.id}>
                      <td className="py-2 pr-3 font-mono text-xs">{branch.code}</td>
                      <td className="py-2 pr-3 font-medium text-slate-900">{branch.name}</td>
                      <td className="py-2 pr-3">{branch.phone ?? '—'}</td>
                      <td className="py-2 pr-3">{branch.address ?? '—'}</td>
                      <td className="py-2 pr-3">
                        <Badge tone={branch.status === 'active' ? 'success' : 'neutral'}>{branch.status === 'active' ? t('common.active') : t('common.inactive')}</Badge>
                      </td>
                      <td className="py-2 text-right">
                        <div className="flex justify-end gap-1">
                          {can('tenant.branch.update') && (
                            <Button variant="ghost" onClick={() => setEditing(branch)} aria-label={`${t('common.edit')} ${branch.name}`}>
                              <Pencil className="h-4 w-4" aria-hidden="true" />
                            </Button>
                          )}
                          {can('tenant.branch.delete') && (
                            <Button variant="ghost" onClick={() => { setDeleteError(null); setDeleting(branch) }} aria-label={`${t('common.delete')} ${branch.name}`}>
                              <Trash2 className="h-4 w-4 text-red-700" aria-hidden="true" />
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={branches.data.meta.current_page} lastPage={branches.data.meta.last_page} total={branches.data.meta.total} onPage={setPage} />
          </>
        )}
      </Card>

      {editing && <BranchDialog branch={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}

      {deleting && (
        <ConfirmDialog
          title={t('branches.deleteTitle')}
          confirmLabel={t('common.delete')}
          danger
          loading={remove.isPending}
          error={deleteError}
          onConfirm={confirmDelete}
          onClose={() => setDeleting(null)}
        >
          {t('branches.deleteText', { name: deleting.name })}
        </ConfirmDialog>
      )}
    </div>
  )
}
