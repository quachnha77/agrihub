import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft, Pencil, Plus, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { z } from 'zod'
import { Alert, Badge, Button, Card, ConfirmDialog, Modal, SelectField, Spinner, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useAllBranches } from '../branches/api.ts'
import { useDeleteWarehouse, useSaveWarehouse, useWarehouses, type Warehouse } from './api.ts'

const CODE = /^[A-Za-z0-9][A-Za-z0-9._-]*$/

/** Kho của từng chi nhánh (một chi nhánh có nhiều kho). Kho đã có phiếu thì không xóa được, chỉ chuyển sang "ngừng hoạt động". */
export default function WarehousesPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [filters, setFilters] = useState({ q: '', branch: '', status: '' })
  const warehouses = useWarehouses(filters)
  const branches = useAllBranches()
  const remove = useDeleteWarehouse()
  const [editing, setEditing] = useState<Warehouse | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Warehouse | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  const canCreate = can('inventory.warehouse.create')
  const canUpdate = can('inventory.warehouse.update')
  const canDelete = can('inventory.warehouse.delete')
  const rows = warehouses.data?.data ?? []
  const showBranch = (branches.data?.length ?? 0) > 1

  async function confirmDelete() {
    if (!deleting) return
    setDeleteError(null)

    try {
      await remove.mutateAsync(deleting.id)
      setDeleting(null)
    } catch (error) {
      setDeleteError(error instanceof ApiError && error.code === 'warehouse_in_use' ? t('inventory.warehouses.inUse') : describeError(error, t))
    }
  }

  return (
    <div className="space-y-4">
      <Link to="/stock" className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t('inventory.warehouses.back')}
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('inventory.warehouses.title')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('inventory.warehouses.intro')}</p>
        </div>
        {canCreate && (
          <Button onClick={() => setEditing('new')}>
            <Plus className="h-4 w-4" aria-hidden="true" />
            {t('inventory.warehouses.add')}
          </Button>
        )}
      </div>

      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-3">
          <div>
            <label htmlFor="warehouse-search" className="mb-1 block text-sm font-medium text-slate-800">{t('common.search')}</label>
            <input
              id="warehouse-search"
              type="search"
              value={filters.q}
              onChange={(event) => setFilters({ ...filters, q: event.target.value })}
              placeholder={t('inventory.warehouses.searchPlaceholder')}
              className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200"
            />
          </div>
          {showBranch && (
            <SelectField label={t('inventory.filters.branch')} value={filters.branch} onChange={(event) => setFilters({ ...filters, branch: event.target.value })}>
              <option value="">{t('inventory.filters.all')}</option>
              {(branches.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
            </SelectField>
          )}
          <SelectField label={t('inventory.filters.status')} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
            <option value="">{t('inventory.filters.all')}</option>
            <option value="active">{t('inventory.warehouses.status.active')}</option>
            <option value="inactive">{t('inventory.warehouses.status.inactive')}</option>
          </SelectField>
        </div>

        {warehouses.isPending ? (
          <div className="flex justify-center py-6"><Spinner /></div>
        ) : warehouses.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('inventory.warehouses.empty')}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[40rem] text-left text-sm">
              <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th scope="col" className="py-2 pr-3">{t('inventory.columns.code')}</th>
                  <th scope="col" className="py-2 pr-3">{t('inventory.columns.warehouseName')}</th>
                  {showBranch && <th scope="col" className="py-2 pr-3">{t('inventory.columns.branch')}</th>}
                  <th scope="col" className="py-2 pr-3">{t('inventory.columns.address')}</th>
                  <th scope="col" className="py-2 pr-3">{t('inventory.columns.status')}</th>
                  <th scope="col" className="py-2 text-right">{t('backup.list.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((warehouse) => (
                  <tr key={warehouse.id}>
                    <td className="py-2 pr-3 font-mono text-xs whitespace-nowrap">{warehouse.code}</td>
                    <td className="py-2 pr-3 font-medium text-slate-900">{warehouse.name}</td>
                    {showBranch && <td className="py-2 pr-3">{warehouse.branch.code}</td>}
                    <td className="py-2 pr-3 text-slate-600">{warehouse.address ?? '—'}</td>
                    <td className="py-2 pr-3"><Badge tone={warehouse.status === 'active' ? 'success' : 'neutral'}>{t(`inventory.warehouses.status.${warehouse.status}`)}</Badge></td>
                    <td className="py-2 text-right">
                      <div className="flex justify-end gap-1">
                        {canUpdate && (
                          <Button variant="ghost" onClick={() => setEditing(warehouse)} aria-label={`${t('common.edit')} ${warehouse.name}`}>
                            <Pencil className="h-4 w-4" aria-hidden="true" />
                          </Button>
                        )}
                        {canDelete && (
                          <Button variant="ghost" onClick={() => { setDeleteError(null); setDeleting(warehouse) }} aria-label={`${t('common.delete')} ${warehouse.name}`}>
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
        )}
      </Card>

      {editing && <WarehouseDialog warehouse={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}

      {deleting && (
        <ConfirmDialog title={t('inventory.warehouses.deleteTitle')} confirmLabel={t('common.delete')} danger loading={remove.isPending} error={deleteError} onConfirm={confirmDelete} onClose={() => setDeleting(null)}>
          {t('inventory.warehouses.deleteText', { name: deleting.name })}
        </ConfirmDialog>
      )}
    </div>
  )
}

/** Thêm hoặc sửa kho. Chi nhánh chọn lúc thêm và không đổi được sau đó (tồn và sổ kho gắn với chi nhánh). */
function WarehouseDialog({ warehouse, onClose }: { warehouse?: Warehouse; onClose: () => void }) {
  const { t } = useTranslation()
  const creating = warehouse === undefined
  const save = useSaveWarehouse(warehouse)
  const branches = useAllBranches()
  const [formError, setFormError] = useState<string | null>(null)
  const open = (branches.data ?? []).filter((branch) => branch.status === 'active')

  const schema = useMemo(
    () =>
      z.object({
        branch_id: creating ? z.string().min(1, t('inventory.warehouses.branchRequired')) : z.string(),
        code: z.string().trim().min(1, t('inventory.warehouses.codeRequired')).max(30, t('validation.maxLength', { count: 30 })).regex(CODE, t('inventory.warehouses.codeFormat')),
        name: z.string().trim().min(1, t('inventory.warehouses.nameRequired')).max(150, t('validation.maxLength', { count: 150 })),
        address: z.string().max(255, t('validation.maxLength', { count: 255 })),
        status: z.enum(['active', 'inactive']),
      }),
    [t, creating],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { branch_id: warehouse?.branch.id ?? '', code: warehouse?.code ?? '', name: warehouse?.name ?? '', address: warehouse?.address ?? '', status: warehouse?.status ?? 'active' },
  })
  const errors = form.formState.errors

  // Chỉ có một chi nhánh đang hoạt động thì chọn sẵn.
  useEffect(() => {
    if (creating && open.length === 1 && form.getValues('branch_id') === '') form.setValue('branch_id', open[0].id)
  }, [creating, open, form])

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await save.mutateAsync({
        code: values.code.trim(),
        name: values.name.trim(),
        address: values.address.trim() === '' ? null : values.address.trim(),
        status: values.status,
        ...(creating ? { branch_id: values.branch_id } : {}),
      })
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, form.setError, ['branch_id', 'code', 'name', 'address', 'status'])) setFormError(describeError(error, t))
    }
  })

  return (
    <Modal title={creating ? t('inventory.warehouses.add') : t('inventory.warehouses.edit')} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert tone="danger">{formError}</Alert>}
        {creating ? (
          <SelectField label={t('inventory.warehouses.branch')} error={errors.branch_id?.message} {...form.register('branch_id')}>
            <option value="">{t('inventory.form.choose')}</option>
            {open.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} — {branch.name}</option>)}
          </SelectField>
        ) : (
          <div>
            <p className="mb-1 text-sm font-medium text-slate-800">{t('inventory.warehouses.branch')}</p>
            <p className="rounded-md bg-slate-100 px-3 py-2 text-sm text-slate-700">{warehouse.branch.code} — {warehouse.branch.name}</p>
          </div>
        )}
        <TextField label={t('inventory.warehouses.code')} hint={t('inventory.warehouses.codeHint')} error={errors.code?.message} autoComplete="off" {...form.register('code')} />
        <TextField label={t('inventory.warehouses.name')} error={errors.name?.message} autoComplete="off" {...form.register('name')} />
        <TextField label={t('inventory.warehouses.address')} error={errors.address?.message} autoComplete="off" {...form.register('address')} />
        <SelectField label={t('inventory.warehouses.statusLabel')} hint={t('inventory.warehouses.statusHint')} error={errors.status?.message} {...form.register('status')}>
          <option value="active">{t('inventory.warehouses.status.active')}</option>
          <option value="inactive">{t('inventory.warehouses.status.inactive')}</option>
        </SelectField>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</Button>
        </div>
      </form>
    </Modal>
  )
}
