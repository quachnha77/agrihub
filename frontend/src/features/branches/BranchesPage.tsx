import { Building2, Pencil, Plus, Trash2 } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, FilterBar, PageHeader, SearchField } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Badge, Button, Card, ConfirmDialog, IconButton, Pagination } from '../../components/ui.tsx'
import { describeError } from '../../lib/forms.ts'
import { useClampPage } from '../../lib/pagination.ts'
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

  useClampPage(branches.data?.meta, setPage)

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('branches.title')}
        description={t('branches.intro')}
        actions={
          can('tenant.branch.create') && (
            <Button onClick={() => setEditing('new')}>
              <Plus className="h-4 w-4" aria-hidden="true" />
              {t('branches.add')}
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
              placeholder={t('branches.searchPlaceholder')}
            />
          }
        />

        {branches.isPending ? (
          <TableSkeleton columns={6} actions minWidth="40rem" />
        ) : branches.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : branches.data.data.length === 0 ? (
          <EmptyState icon={Building2} title={t('branches.empty')} description={q !== '' ? t('common.tryOtherFilters') : undefined} />
        ) : (
          <Refreshable refreshing={branches.isPlaceholderData}>
            <DataTable minWidth="40rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" className="py-2.5 pr-4">{t('branches.code')}</th>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('branches.name')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('branches.phone')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('branches.address')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('branches.status')}</th>
                  <th scope="col" data-role="actions" className="py-2.5 text-right"><span className="sr-only">{t('backup.list.actions')}</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {branches.data.data.map((branch) => (
                  <tr key={branch.id}>
                    <td className="py-3 pr-4 font-mono text-xs">{branch.code}</td>
                    <td className="py-3 pr-4 font-medium text-slate-900">{branch.name}</td>
                    <td className="py-3 pr-4">{branch.phone ?? '—'}</td>
                    <td className="py-3 pr-4">{branch.address ?? '—'}</td>
                    <td className="py-3 pr-4">
                      <Badge tone={branch.status === 'active' ? 'success' : 'neutral'}>{branch.status === 'active' ? t('common.active') : t('common.inactive')}</Badge>
                    </td>
                    <td className="py-3 text-right">
                      <div className="flex justify-end gap-1">
                        {can('tenant.branch.update') && (
                          <IconButton label={`${t('common.edit')} ${branch.name}`} text={t('common.edit')} onClick={() => setEditing(branch)}>
                            <Pencil className="h-4 w-4" aria-hidden="true" />
                          </IconButton>
                        )}
                        {can('tenant.branch.delete') && (
                          <IconButton label={`${t('common.delete')} ${branch.name}`} text={t('common.delete')} onClick={() => { setDeleteError(null); setDeleting(branch) }} tone="danger">
                            <Trash2 className="h-4 w-4" aria-hidden="true" />
                          </IconButton>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
            <Pagination page={branches.data.meta.current_page} lastPage={branches.data.meta.last_page} total={branches.data.meta.total} onPage={setPage} />
          </Refreshable>
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
