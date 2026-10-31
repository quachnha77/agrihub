import { ArrowLeft, Pencil, Plus, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { z } from 'zod'
import { Alert, Badge, Button, Card, ConfirmDialog, Modal, Spinner, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useCatalogList, useDeleteListItem, useSaveListItem, type ListItem, type ListKind } from './api.ts'

const KINDS: ListKind[] = ['units', 'categories', 'brands']
const MAX_NAME: Record<ListKind, number> = { units: 50, categories: 100, brands: 100 }

/** Đơn vị tính, nhóm hàng, thương hiệu: danh sách dùng chung toàn doanh nghiệp (mọi chi nhánh). */
export default function CatalogListsPage() {
  const { t } = useTranslation()

  return (
    <div className="space-y-4">
      <Link to="/products" className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t('catalog.lists.back')}
      </Link>
      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('catalog.lists.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('catalog.lists.intro')}</p>
      </div>
      {KINDS.map((kind) => <ListCard key={kind} kind={kind} />)}
    </div>
  )
}

function ListCard({ kind }: { kind: ListKind }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const items = useCatalogList(kind)
  const remove = useDeleteListItem(kind)
  const [editing, setEditing] = useState<ListItem | 'new' | null>(null)
  const [deleting, setDeleting] = useState<ListItem | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const canCreate = can('catalog.product.create')
  // Đổi tên và xóa ảnh hưởng mọi chi nhánh nên chỉ cấp doanh nghiệp; quản lý chi nhánh chỉ được thêm mới.
  const canUpdate = can('catalog.product.update', 'tenant')
  const canDelete = can('catalog.product.delete', 'tenant')

  async function confirmDelete() {
    if (!deleting) return
    setDeleteError(null)

    try {
      await remove.mutateAsync(deleting.id)
      setDeleting(null)
    } catch (error) {
      setDeleteError(error instanceof ApiError && error.code === 'catalog_item_in_use' ? t('catalog.lists.inUse') : describeError(error, t))
    }
  }

  return (
    <Card
      title={t(`catalog.lists.${kind}.title`)}
      actions={canCreate && (
        <Button variant="secondary" onClick={() => setEditing('new')}>
          <Plus className="h-4 w-4" aria-hidden="true" />
          {t(`catalog.lists.${kind}.add`)}
        </Button>
      )}
    >
      <p className="mb-3 text-xs text-slate-500">{t(`catalog.lists.${kind}.hint`)}</p>

      {items.isPending ? (
        <div className="flex justify-center py-4"><Spinner /></div>
      ) : items.isError ? (
        <Alert tone="danger">{t('common.loadError')}</Alert>
      ) : items.data.length === 0 ? (
        <p className="py-4 text-center text-sm text-slate-500">{t('catalog.lists.empty')}</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {items.data.map((item) => (
            <li key={item.id} className="flex items-center justify-between gap-2 py-2">
              <span className="flex flex-wrap items-center gap-2">
                <span className="font-medium text-slate-900">{item.name}</span>
                {!item.is_active && <Badge tone="neutral">{t('catalog.lists.retired')}</Badge>}
              </span>
              <span className="flex gap-1">
                {canUpdate && (
                  <Button variant="ghost" onClick={() => setEditing(item)} aria-label={`${t('common.edit')} ${item.name}`}>
                    <Pencil className="h-4 w-4" aria-hidden="true" />
                  </Button>
                )}
                {canDelete && (
                  <Button variant="ghost" onClick={() => { setDeleteError(null); setDeleting(item) }} aria-label={`${t('common.delete')} ${item.name}`}>
                    <Trash2 className="h-4 w-4 text-red-700" aria-hidden="true" />
                  </Button>
                )}
              </span>
            </li>
          ))}
        </ul>
      )}

      {editing && <ListItemDialog kind={kind} item={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}

      {deleting && (
        <ConfirmDialog title={t('catalog.lists.deleteTitle')} confirmLabel={t('common.delete')} danger loading={remove.isPending} error={deleteError} onConfirm={confirmDelete} onClose={() => setDeleting(null)}>
          {t('catalog.lists.deleteText', { name: deleting.name })}
        </ConfirmDialog>
      )}
    </Card>
  )
}

/** Thêm hoặc sửa một mục. Nhóm hàng có thêm thứ tự hiển thị; khi sửa có thể ngừng sử dụng (không chọn được nữa nhưng sản phẩm cũ giữ nguyên). */
function ListItemDialog({ kind, item, onClose }: { kind: ListKind; item?: ListItem; onClose: () => void }) {
  const { t } = useTranslation()
  const save = useSaveListItem(kind, item)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        name: z.string().trim().min(1, t('catalog.lists.nameRequired')).max(MAX_NAME[kind], t('validation.maxLength', { count: MAX_NAME[kind] })),
        sort_order: z.string().trim().refine((v) => v === '' || (/^\d+$/.test(v) && Number(v) <= 65535), t('catalog.lists.orderInvalid')),
        is_active: z.boolean(),
      }),
    [t, kind],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { name: item?.name ?? '', sort_order: item?.sort_order === undefined ? '' : String(item.sort_order), is_active: item?.is_active ?? true } })
  const errors = form.formState.errors

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await save.mutateAsync({
        name: values.name,
        ...(kind === 'categories' && values.sort_order !== '' ? { sort_order: Number(values.sort_order) } : {}),
        ...(item ? { is_active: values.is_active } : {}),
      })
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, form.setError, ['name', 'sort_order', 'is_active'])) setFormError(describeError(error, t))
    }
  })

  return (
    <Modal title={item ? t(`catalog.lists.${kind}.edit`) : t(`catalog.lists.${kind}.add`)} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert tone="danger">{formError}</Alert>}
        <TextField label={t(`catalog.lists.${kind}.name`)} error={errors.name?.message} autoComplete="off" {...form.register('name')} />
        {kind === 'categories' && <TextField label={t('catalog.lists.order')} hint={t('catalog.lists.orderHint')} inputMode="numeric" error={errors.sort_order?.message} {...form.register('sort_order')} />}
        {item && (
          <label className="flex items-start gap-2 text-sm text-slate-800">
            <input type="checkbox" className="mt-1 h-4 w-4 rounded border-slate-300 text-emerald-700" {...form.register('is_active')} />
            <span>
              {t('catalog.lists.inUseLabel')}
              <span className="block text-xs text-slate-500">{t('catalog.lists.inUseHint')}</span>
            </span>
          </label>
        )}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</Button>
        </div>
      </form>
    </Modal>
  )
}
