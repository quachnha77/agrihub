import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowRight, Plus, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useFieldArray, useForm, useWatch, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { DialogSkeleton, FormSection, RequiredNote, UnsavedChangesDialog } from '../../components/dialog-parts.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { Alert, Badge, Button, Modal, ModalActions, SelectField, TextField } from '../../components/ui.tsx'
import { useDialog, type DialogProps } from '../../lib/dialog.ts'
import { formatIsoDate, formatQuantity } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useToast } from '../../lib/toast.ts'
import { useUnsavedGuard } from '../../lib/unsaved.ts'
import { parseNumber } from '../catalog/form.ts'
import type { Product } from '../catalog/api.ts'
import { useAllWarehouses, useCreateTransfer, useStockBalances, type Warehouse } from './api.ts'
import { applyTransferErrors, defaultUnit, emptyTransferLine, factorOf, transferPayload, transferSchema, type TransferFormValues } from './forms.ts'
import { ProductPicker } from './ProductPicker.tsx'

/**
 * Popup chuyển kho (`?dialog=transfer-new`): chọn kho gửi, kho nhận và hàng cần chuyển. Chuyển xong trong MỘT bước: kho gửi trừ, kho nhận cộng ngay.
 * Sản phẩm là sản phẩm của chi nhánh kho gửi; khác chi nhánh thì bên nhận ghép theo mã sản phẩm (máy chủ báo rõ nếu bên nhận chưa có).
 */
export default function TransferDialog({ onClose }: DialogProps) {
  const { t } = useTranslation()
  const warehouses = useAllWarehouses()

  return (
    <Modal size="xl" fixedHeight dismissOnBackdrop={false} onClose={onClose} title={t('inventory.transfer.form.title')} description={t('inventory.transfer.form.intro')}>
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
        <TransferForm warehouses={warehouses.data.filter((w) => w.status === 'active')} onClose={onClose} />
      )}
    </Modal>
  )
}

function TransferForm({ warehouses, onClose }: { warehouses: Warehouse[]; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { link } = useDialog()
  const create = useCreateTransfer()
  const [formError, setFormError] = useState<string | null>(null)
  /** Sản phẩm đã chọn theo vị trí dòng: chứa đơn vị quy đổi và cờ theo dõi lô để dựng ô nhập. */
  const [picked, setPicked] = useState<Record<string, Product | undefined>>({})
  const schema = useMemo(() => transferSchema(t), [t])

  const form = useForm<TransferFormValues>({
    resolver: zodResolver(schema),
    defaultValues: { from_warehouse_id: '', to_warehouse_id: '', note: '', lines: [emptyTransferLine()] },
  })
  const { fields, append, remove, replace } = useFieldArray({ control: form.control, name: 'lines' })
  const errors = form.formState.errors
  const guard = useUnsavedGuard(form.formState.isDirty)
  const fromId = useWatch({ control: form.control, name: 'from_warehouse_id' })
  const toId = useWatch({ control: form.control, name: 'to_warehouse_id' })
  const from = warehouses.find((w) => w.id === fromId)
  const to = warehouses.find((w) => w.id === toId)
  const showBranch = new Set(warehouses.map((w) => w.branch.id)).size > 1
  const crossBranch = from !== undefined && to !== undefined && from.branch.id !== to.branch.id
  const label = (w: Warehouse) => (showBranch ? `${w.branch.code} — ${w.name}` : w.name)

  /** Mỗi chi nhánh có danh mục sản phẩm riêng: đổi kho gửi sang chi nhánh khác thì các dòng đã chọn không còn hợp lệ; cùng chi nhánh thì chỉ lô (tồn theo kho) phải chọn lại. */
  function changeFrom(nextId: string) {
    const next = warehouses.find((w) => w.id === nextId)

    if (from && next && next.branch.id !== from.branch.id) {
      replace([emptyTransferLine()])
      setPicked({})
    } else {
      form.getValues('lines').forEach((_, index) => form.setValue(`lines.${index}.batch_id`, ''))
    }

    if (nextId !== '' && nextId === form.getValues('to_warehouse_id')) form.setValue('to_warehouse_id', '')
  }

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    // Sản phẩm theo dõi lô phải chọn lô ở kho gửi (máy chủ vẫn kiểm tra lại).
    let missing = false
    values.lines.forEach((line, index) => {
      if (picked[String(index)]?.track_batch && line.batch_id === '') {
        form.setError(`lines.${index}.batch_id`, { type: 'validate', message: t('inventory.form.batchRequired') })
        missing = true
      }
    })

    if (missing) return

    try {
      const transfer = await create.mutateAsync(transferPayload(values, picked))
      toast.success(t('inventory.transfer.created', { code: transfer.code }), { label: t('inventory.transfer.view'), ...link('transfer', transfer.id) })
      guard.release()
      onClose()
    } catch (error) {
      const result = applyTransferErrors(error, form.setError)
      if (result.leftover.length > 0) setFormError(result.leftover.join(' '))
      else if (!result.handled) setFormError(describeError(error, t))
    }
  })

  if (warehouses.length < 2) {
    return (
      <div className="space-y-4">
        <Alert tone="info">{t('inventory.transfer.form.needTwo')}</Alert>
        <div className="flex justify-end">
          <Button onClick={onClose}>{t('common.close')}</Button>
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-full flex-col gap-4">
      {formError && <Alert tone="danger">{formError}</Alert>}

      <form onSubmit={submit} noValidate className="flex flex-1 flex-col gap-5">
        <RequiredNote />

        <FormSection title={t('inventory.form.general')}>
          <div className="grid items-start gap-4 sm:grid-cols-[1fr_auto_1fr]">
            <SelectField required label={t('inventory.transfer.form.from')} error={errors.from_warehouse_id?.message} {...form.register('from_warehouse_id', { onChange: (event) => changeFrom(event.target.value) })}>
              <option value="">{t('inventory.form.choose')}</option>
              {warehouses.map((w) => (
                // Kho đang kiểm kê bị khóa: không chuyển đi hay chuyển đến được cho tới khi hoàn tất hoặc hủy đợt kiểm kê.
                <option key={w.id} value={w.id} disabled={!!w.stocktake}>{w.stocktake ? t('inventory.stocktake.lockedOption', { name: label(w) }) : label(w)}</option>
              ))}
            </SelectField>

            <ArrowRight className="mt-9 hidden h-5 w-5 text-slate-400 sm:block" aria-hidden="true" />

            <SelectField required label={t('inventory.transfer.form.to')} error={errors.to_warehouse_id?.message} {...form.register('to_warehouse_id')}>
              <option value="">{t('inventory.form.choose')}</option>
              {warehouses.filter((w) => w.id !== fromId).map((w) => (
                <option key={w.id} value={w.id} disabled={!!w.stocktake}>{w.stocktake ? t('inventory.stocktake.lockedOption', { name: label(w) }) : label(w)}</option>
              ))}
            </SelectField>
          </div>

          {crossBranch && <Alert tone="info">{t('inventory.transfer.form.crossBranch')}</Alert>}
        </FormSection>

        <FormSection title={t('inventory.form.lines')}>
          {!from ? (
            <p className="text-sm text-slate-500">{t('inventory.transfer.form.pickFromFirst')}</p>
          ) : (
            <div className="space-y-3">
              {fields.map((field, index) => (
                <LineEditor
                  key={field.id}
                  index={index}
                  form={form}
                  warehouse={from}
                  product={picked[String(index)]}
                  onPick={(product) => setPicked((current) => ({ ...current, [String(index)]: product }))}
                  onRemove={() => {
                    remove(index)
                    // Dịch các sản phẩm đã chọn của những dòng phía sau lên một vị trí.
                    setPicked((current) => {
                      const next: Record<string, Product | undefined> = {}
                      Object.entries(current).forEach(([key, value]) => {
                        const position = Number(key)
                        if (position < index) next[key] = value
                        else if (position > index) next[String(position - 1)] = value
                      })

                      return next
                    })
                  }}
                  canRemove={fields.length > 1}
                />
              ))}

              {typeof errors.lines?.message === 'string' && <p role="alert" className="text-sm text-red-700">{errors.lines.message}</p>}
              {typeof errors.lines?.root?.message === 'string' && <p role="alert" className="text-sm text-red-700">{errors.lines.root.message}</p>}

              <Button variant="secondary" onClick={() => append(emptyTransferLine())} disabled={fields.length >= 100}>
                <Plus className="h-4 w-4" aria-hidden="true" />
                {t('inventory.form.addLine')}
              </Button>
            </div>
          )}
        </FormSection>

        <div>
          <label htmlFor="transfer-note" className="mb-1.5 block text-sm font-medium text-slate-800">{t('inventory.form.note')}</label>
          <textarea id="transfer-note" rows={2} className={`${fieldBase} ${fieldTone(!!errors.note)}`} {...form.register('note')} />
          {errors.note && <p role="alert" className="mt-1 text-sm text-red-700">{errors.note.message}</p>}
        </div>

        <ModalActions>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={create.isPending}>{create.isPending ? t('common.saving') : t('inventory.transfer.form.submit')}</Button>
        </ModalActions>
      </form>

      <UnsavedChangesDialog guard={guard} />
    </div>
  )
}

interface LineProps {
  index: number
  form: UseFormReturn<TransferFormValues>
  /** Kho gửi: tồn và lô lấy từ kho này. */
  warehouse: Warehouse
  product: Product | undefined
  onPick: (product: Product | undefined) => void
  onRemove: () => void
  canRemove: boolean
}

/** Một dòng hàng chuyển: chọn sản phẩm của kho gửi → đơn vị, số lượng và (sản phẩm theo dõi lô) lô cần chuyển. Lô đã hết hạn không chọn được. */
function LineEditor({ index, form, warehouse, product, onPick, onRemove, canRemove }: LineProps) {
  const { t } = useTranslation()
  const errors = form.formState.errors.lines?.[index]
  const line = useWatch({ control: form.control, name: `lines.${index}` })
  const baseName = product?.base_unit.name ?? ''
  const factor = factorOf(product, line.unit_id)
  const quantity = parseNumber(line.quantity)
  const balances = useStockBalances({ warehouse: warehouse.id, product: product?.id ?? '' }, product !== undefined)
  const rows = balances.data ?? []
  const batchRows = rows.filter((row) => row.batch !== null && Number(row.quantity) > 0)
  const batch = batchRows.find((row) => row.batch?.id === line.batch_id)
  const onHand = product?.track_batch ? (batch ? Number(batch.quantity) : null) : rows.reduce((sum, row) => sum + Number(row.quantity), 0)

  function choose(next: Product) {
    onPick(next)
    form.setValue(`lines.${index}.product_id`, next.id, { shouldValidate: true })
    form.setValue(`lines.${index}.unit_id`, defaultUnit(next), { shouldValidate: true })
    form.setValue(`lines.${index}.batch_id`, '')
  }

  return (
    <fieldset className="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
      <legend className="px-1 text-sm font-medium text-slate-700">{t('inventory.form.line', { n: index + 1 })}</legend>

      {!product ? (
        <>
          <ProductPicker branchId={warehouse.branch.id} onPick={choose} />
          {errors?.product_id && <p role="alert" className="mt-1 text-sm text-red-700">{errors.product_id.message}</p>}
        </>
      ) : (
        <div className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm">
              <span className="font-mono text-xs">{product.sku}</span> <span className="font-medium text-slate-900">{product.name}</span>{' '}
              {product.track_batch && <Badge tone="neutral">{t('inventory.stock.batch')}</Badge>}
              {onHand !== null && !balances.isPending && <span className="ml-2 text-slate-500">{t('inventory.form.onHand', { qty: formatQuantity(onHand), unit: baseName })}</span>}
            </p>
            <Button variant="secondary" size="sm" onClick={() => { onPick(undefined); form.setValue(`lines.${index}.product_id`, ''); form.setValue(`lines.${index}.batch_id`, '') }}>
              {t('inventory.form.changeProduct')}
            </Button>
          </div>

          {/* Lỗi của sản phẩm sau khi đã chọn (vd. chi nhánh nhận chưa có sản phẩm cùng mã) do máy chủ báo về. */}
          {errors?.product_id && <p role="alert" className="text-sm text-red-700">{errors.product_id.message}</p>}

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <SelectField required label={t('inventory.form.unit')} error={errors?.unit_id?.message} {...form.register(`lines.${index}.unit_id`)}>
              {product.units.map((u) => <option key={u.unit.id} value={u.unit.id}>{u.unit.name}{u.is_base ? '' : ` (= ${formatQuantity(u.factor)} ${baseName})`}</option>)}
            </SelectField>
            <TextField
              required
              label={t('inventory.form.quantity')}
              hint={quantity !== null && factor !== 1 ? t('inventory.form.equals', { qty: formatQuantity(quantity * factor), unit: baseName }) : undefined}
              error={errors?.quantity?.message}
              inputMode="decimal"
              autoComplete="off"
              {...form.register(`lines.${index}.quantity`)}
            />
            {product.track_batch && (
              <SelectField required label={t('inventory.form.batch')} className="sm:col-span-2" error={errors?.batch_id?.message} {...form.register(`lines.${index}.batch_id`)}>
                <option value="">{t('inventory.form.choose')}</option>
                {batchRows.map((row) => (
                  <option key={row.batch!.id} value={row.batch!.id} disabled={row.expired}>
                    {row.batch!.batch_no} — {t('inventory.form.batchOption', { expiry: formatIsoDate(row.batch!.expires_at), qty: formatQuantity(row.quantity), unit: baseName })}{row.expired ? ` — ${t('inventory.transfer.form.batchExpired')}` : ''}
                  </option>
                ))}
              </SelectField>
            )}
          </div>
        </div>
      )}

      {canRemove && (
        <div className="mt-2 flex justify-end">
          <Button variant="ghost" size="sm" onClick={onRemove} aria-label={t('inventory.form.removeLine', { n: index + 1 })} className="text-red-700 hover:bg-red-50">
            <Trash2 className="h-4 w-4" aria-hidden="true" />
            {t('inventory.form.removeLine', { n: index + 1 })}
          </Button>
        </div>
      )}
    </fieldset>
  )
}
