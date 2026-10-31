import { zodResolver } from '@hookform/resolvers/zod'
import { Lock } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { DialogSkeleton, RequiredNote, UnsavedChangesDialog } from '../../components/dialog-parts.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { Alert, Button, Modal, ModalActions, SelectField } from '../../components/ui.tsx'
import { useDialog, type DialogProps } from '../../lib/dialog.ts'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { useUnsavedGuard } from '../../lib/unsaved.ts'
import { useAllWarehouses, useStartStocktake, type Warehouse } from './api.ts'

/**
 * Popup bắt đầu kiểm kê (`?dialog=stocktake-new`): chọn kho và (tùy chọn) ghi chú. Bắt đầu xong thì mở luôn màn đếm của đợt mới ngay tại chỗ này.
 * Kho đang có đợt kiểm kê chưa kết thúc không chọn được (máy chủ cũng từ chối).
 */
export default function StocktakeStartDialog({ onClose }: DialogProps) {
  const { t } = useTranslation()
  const warehouses = useAllWarehouses()

  return (
    <Modal size="md" dismissOnBackdrop={false} onClose={onClose} title={t('inventory.stocktake.new.title')} description={t('inventory.stocktake.new.intro')}>
      {warehouses.isError ? (
        <div className="space-y-4">
          <Alert tone="danger">{t('common.loadError')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : warehouses.isPending ? (
        <DialogSkeleton fields={2} />
      ) : (
        <StartForm warehouses={warehouses.data.filter((w) => w.status === 'active')} onClose={onClose} />
      )}
    </Modal>
  )
}

function StartForm({ warehouses, onClose }: { warehouses: Warehouse[]; onClose: () => void }) {
  const { t } = useTranslation()
  const { replace } = useDialog()
  const start = useStartStocktake()
  const [formError, setFormError] = useState<string | null>(null)
  const available = warehouses.filter((w) => !w.stocktake)
  const showBranch = new Set(warehouses.map((w) => w.branch.id)).size > 1

  const schema = useMemo(
    () =>
      z.object({
        warehouse_id: z.string().min(1, t('inventory.stocktake.new.warehouseRequired')),
        note: z.string().max(500, t('validation.maxLength', { count: 500 })),
      }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { warehouse_id: available.length === 1 ? available[0].id : '', note: '' } })
  const errors = form.formState.errors
  const guard = useUnsavedGuard(form.formState.isDirty)

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      const stocktake = await start.mutateAsync({ warehouse_id: values.warehouse_id, note: values.note.trim() || null })
      guard.release()
      replace('stocktake', stocktake.id)
    } catch (error) {
      if (!applyServerErrors(error, form.setError, ['warehouse_id', 'note'])) setFormError(describeError(error, t))
    }
  })

  return (
    <div className="flex flex-col gap-4">
      {formError && <Alert tone="danger">{formError}</Alert>}

      {available.length === 0 ? (
        <>
          <Alert tone="info">{t('inventory.stocktake.new.noWarehouse')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </>
      ) : (
        <form onSubmit={submit} noValidate className="flex flex-col gap-4">
          <RequiredNote />

          <SelectField required label={t('inventory.stocktake.new.warehouse')} error={errors.warehouse_id?.message} {...form.register('warehouse_id')}>
            <option value="">{t('inventory.form.choose')}</option>
            {warehouses.map((w) => {
              const name = showBranch ? `${w.branch.code} — ${w.name}` : w.name

              return <option key={w.id} value={w.id} disabled={!!w.stocktake}>{w.stocktake ? t('inventory.stocktake.lockedOption', { name }) : name}</option>
            })}
          </SelectField>

          <div>
            <label htmlFor="stocktake-note" className="mb-1.5 block text-sm font-medium text-slate-800">{t('inventory.stocktake.new.noteLabel')}</label>
            <textarea id="stocktake-note" rows={2} placeholder={t('inventory.stocktake.new.notePlaceholder')} className={`${fieldBase} ${fieldTone(!!errors.note)}`} {...form.register('note')} />
            {errors.note && <p role="alert" className="mt-1 text-sm text-red-700">{errors.note.message}</p>}
          </div>

          <p className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
            <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            {t('inventory.stocktake.new.lockNote')}
          </p>

          <ModalActions>
            <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
            <Button type="submit" loading={start.isPending}>{start.isPending ? t('common.saving') : t('inventory.stocktake.new.submit')}</Button>
          </ModalActions>
        </form>
      )}

      <UnsavedChangesDialog guard={guard} />
    </div>
  )
}
