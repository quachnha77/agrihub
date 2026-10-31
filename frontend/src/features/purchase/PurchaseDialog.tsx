import { zodResolver } from '@hookform/resolvers/zod'
import { Plus, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useFieldArray, useForm, useWatch, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { DialogSkeleton, FormSection, RequiredNote, UnsavedChangesDialog } from '../../components/dialog-parts.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { Alert, Badge, Button, Modal, ModalActions, SelectField, TextField } from '../../components/ui.tsx'
import { useDialog, type DialogProps } from '../../lib/dialog.ts'
import { formatQuantity, formatVnd } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useToast } from '../../lib/toast.ts'
import { useUnsavedGuard } from '../../lib/unsaved.ts'
import { useAuth } from '../auth/auth-context.ts'
import type { Product } from '../catalog/api.ts'
import { parseNumber } from '../catalog/form.ts'
import { useAllWarehouses, type Warehouse } from '../inventory/api.ts'
import { defaultUnit, factorOf } from '../inventory/forms.ts'
import { ProductPicker } from '../inventory/ProductPicker.tsx'
import { PAYMENT_METHODS, useActiveSuppliers, useCreatePurchase } from './api.ts'
import { applyPurchaseErrors, emptyPurchaseLine, goodsTotal, lineTotal, purchasePayload, purchaseSchema, roundMoney, todayIso, type PurchaseFormValues } from './forms.ts'

/**
 * Popup lập phiếu nhập hàng (`?dialog=purchase-new`): chọn nhà cung cấp, kho nhận, hàng (số lượng, đơn giá nhập, lô), chi phí kèm theo và số tiền trả ngay.
 * Lập xong là hàng vào kho ngay (không cần duyệt). Tiền hàng chưa trả hết ghi thành nợ nhà cung cấp; chi phí kèm theo chỉ cộng vào giá vốn.
 */
export default function PurchaseDialog({ onClose }: DialogProps) {
  const { t } = useTranslation()
  const warehouses = useAllWarehouses()
  const suppliers = useActiveSuppliers()
  const failed = warehouses.isError || suppliers.isError

  return (
    <Modal size="xl" fixedHeight dismissOnBackdrop={false} onClose={onClose} title={t('purchase.form.title')} description={t('purchase.form.intro')}>
      {failed ? (
        <div className="space-y-4">
          <Alert tone="danger">{t('common.loadError')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : warehouses.isPending || suppliers.isPending ? (
        <DialogSkeleton fields={3} />
      ) : (
        <PurchaseForm warehouses={warehouses.data.filter((w) => w.status === 'active')} suppliers={suppliers.data} onClose={onClose} />
      )}
    </Modal>
  )
}

function PurchaseForm({ warehouses, suppliers, onClose }: { warehouses: Warehouse[]; suppliers: { id: string; code: string; name: string }[]; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { can } = useAuth()
  const { link } = useDialog()
  const create = useCreatePurchase()
  const [formError, setFormError] = useState<string | null>(null)
  /** Sản phẩm đã chọn theo vị trí dòng: chứa đơn vị quy đổi và cờ theo dõi lô để dựng ô nhập. */
  const [picked, setPicked] = useState<Record<string, Product | undefined>>({})
  const schema = useMemo(() => purchaseSchema(t), [t])
  const canPay = can('purchase.order.update')

  const form = useForm<PurchaseFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      supplier_id: '',
      warehouse_id: warehouses.length === 1 ? warehouses[0].id : '',
      received_on: todayIso(),
      supplier_ref: '',
      extra_cost: '',
      paid_amount: '',
      payment_method: 'cash',
      note: '',
      lines: [emptyPurchaseLine()],
    },
  })
  const { fields, append, remove, replace } = useFieldArray({ control: form.control, name: 'lines' })
  const errors = form.formState.errors
  const guard = useUnsavedGuard(form.formState.isDirty)
  const warehouseId = useWatch({ control: form.control, name: 'warehouse_id' })
  const lines = useWatch({ control: form.control, name: 'lines' })
  const paidText = useWatch({ control: form.control, name: 'paid_amount' })
  const warehouse = warehouses.find((w) => w.id === warehouseId)
  const showBranch = new Set(warehouses.map((w) => w.branch.id)).size > 1
  const goods = goodsTotal(lines)
  const paid = parseNumber(paidText) ?? 0
  const owed = roundMoney(Math.max(goods - paid, 0))

  /** Mỗi chi nhánh có danh mục sản phẩm riêng: đổi sang kho của chi nhánh khác thì các dòng đã chọn không còn hợp lệ. */
  function changeWarehouse(nextId: string) {
    const next = warehouses.find((w) => w.id === nextId)

    if (warehouse && next && next.branch.id !== warehouse.branch.id) {
      replace([emptyPurchaseLine()])
      setPicked({})
    }
  }

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    // Sản phẩm theo dõi lô: kiểm tra sớm số lô và hạn dùng (máy chủ vẫn kiểm tra lại).
    let missing = false
    values.lines.forEach((line, index) => {
      if (!picked[String(index)]?.track_batch) return

      if (line.batch_no.trim() === '') {
        form.setError(`lines.${index}.batch_no`, { type: 'validate', message: t('inventory.form.batchNoRequired') })
        missing = true
      }

      if (line.expires_at === '') {
        form.setError(`lines.${index}.expires_at`, { type: 'validate', message: t('inventory.form.expiresRequired') })
        missing = true
      }
    })

    if (missing) return

    try {
      const receipt = await create.mutateAsync(purchasePayload(values, picked))
      toast.success(t('purchase.created', { code: receipt.code }), { label: t('purchase.view'), ...link('purchase', receipt.id) })
      guard.release()
      onClose()
    } catch (error) {
      const result = applyPurchaseErrors(error, form.setError)
      if (result.leftover.length > 0) setFormError(result.leftover.join(' '))
      else if (!result.handled) setFormError(describeError(error, t))
    }
  })

  return (
    <div className="flex min-h-full flex-col gap-4">
      {formError && <Alert tone="danger">{formError}</Alert>}

      <form onSubmit={submit} noValidate className="flex flex-1 flex-col gap-5">
        <RequiredNote />

        <FormSection title={t('purchase.form.general')}>
          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField required label={t('purchase.form.supplier')} error={errors.supplier_id?.message} {...form.register('supplier_id')}>
              <option value="">{t('inventory.form.choose')}</option>
              {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </SelectField>

            <SelectField required label={t('purchase.form.warehouse')} error={errors.warehouse_id?.message} {...form.register('warehouse_id', { onChange: (event) => changeWarehouse(event.target.value) })}>
              <option value="">{t('inventory.form.choose')}</option>
              {warehouses.map((w) => {
                const name = showBranch ? `${w.branch.code} — ${w.name}` : w.name

                // Kho đang kiểm kê bị khóa: không nhập hàng được cho tới khi hoàn tất hoặc hủy đợt kiểm kê.
                return <option key={w.id} value={w.id} disabled={!!w.stocktake}>{w.stocktake ? t('inventory.stocktake.lockedOption', { name }) : name}</option>
              })}
            </SelectField>

            <TextField required label={t('purchase.form.receivedOn')} type="date" max={todayIso()} error={errors.received_on?.message} {...form.register('received_on')} />
            <TextField label={t('purchase.form.supplierRef')} hint={t('purchase.form.supplierRefHint')} error={errors.supplier_ref?.message} autoComplete="off" {...form.register('supplier_ref')} />
          </div>
        </FormSection>

        <FormSection title={t('inventory.form.lines')}>
          {!warehouse ? (
            <p className="text-sm text-slate-500">{t('inventory.form.pickWarehouseFirst')}</p>
          ) : (
            <div className="space-y-3">
              {fields.map((field, index) => (
                <LineEditor
                  key={field.id}
                  index={index}
                  form={form}
                  warehouse={warehouse}
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

              <Button variant="secondary" onClick={() => append(emptyPurchaseLine())} disabled={fields.length >= 100}>
                <Plus className="h-4 w-4" aria-hidden="true" />
                {t('inventory.form.addLine')}
              </Button>
            </div>
          )}
        </FormSection>

        <FormSection title={t('purchase.form.payment')}>
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={t('purchase.form.extraCost')} hint={t('purchase.form.extraCostHint')} error={errors.extra_cost?.message} inputMode="decimal" autoComplete="off" {...form.register('extra_cost')} />
            {canPay && (
              <TextField label={t('purchase.form.paidNow')} hint={t('purchase.form.paidNowHint')} error={errors.paid_amount?.message} inputMode="decimal" autoComplete="off" {...form.register('paid_amount')} />
            )}
            {canPay && paid > 0 && (
              <SelectField label={t('purchase.form.method')} {...form.register('payment_method')}>
                {PAYMENT_METHODS.map((method) => <option key={method} value={method}>{t(`purchase.methods.${method}`)}</option>)}
              </SelectField>
            )}
          </div>

          <dl className="mt-4 grid gap-2 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-3" aria-label={t('purchase.form.totals')}>
            <div><dt className="text-slate-500">{t('purchase.form.goodsTotal')}</dt><dd className="font-semibold text-slate-900">{formatVnd(goods)}</dd></div>
            <div><dt className="text-slate-500">{t('purchase.form.paidTotal')}</dt><dd className="font-semibold text-slate-900">{formatVnd(paid)}</dd></div>
            <div><dt className="text-slate-500">{t('purchase.form.owed')}</dt><dd className="font-semibold text-brand-800">{formatVnd(owed)}</dd></div>
          </dl>
        </FormSection>

        <div>
          <label htmlFor="purchase-note" className="mb-1.5 block text-sm font-medium text-slate-800">{t('inventory.form.note')}</label>
          <textarea id="purchase-note" rows={2} className={`${fieldBase} ${fieldTone(!!errors.note)}`} {...form.register('note')} />
          {errors.note && <p role="alert" className="mt-1 text-sm text-red-700">{errors.note.message}</p>}
        </div>

        <ModalActions>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={create.isPending}>{create.isPending ? t('common.saving') : t('purchase.form.submit')}</Button>
        </ModalActions>
      </form>

      <UnsavedChangesDialog guard={guard} />
    </div>
  )
}

interface LineProps {
  index: number
  form: UseFormReturn<PurchaseFormValues>
  warehouse: Warehouse
  product: Product | undefined
  onPick: (product: Product | undefined) => void
  onRemove: () => void
  canRemove: boolean
}

/** Một dòng hàng nhập: chọn sản phẩm của chi nhánh kho nhận → đơn vị, số lượng, đơn giá nhập (theo đơn vị đã chọn); sản phẩm theo dõi lô khai thêm số lô, hạn dùng. */
function LineEditor({ index, form, warehouse, product, onPick, onRemove, canRemove }: LineProps) {
  const { t } = useTranslation()
  const errors = form.formState.errors.lines?.[index]
  const line = useWatch({ control: form.control, name: `lines.${index}` })
  const baseName = product?.base_unit.name ?? ''
  const factor = factorOf(product, line.unit_id)
  const quantity = parseNumber(line.quantity)
  const total = lineTotal(line)

  function choose(next: Product) {
    onPick(next)
    form.setValue(`lines.${index}.product_id`, next.id, { shouldValidate: true })
    form.setValue(`lines.${index}.unit_id`, defaultUnit(next), { shouldValidate: true })
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
            </p>
            <Button variant="secondary" size="sm" onClick={() => { onPick(undefined); form.setValue(`lines.${index}.product_id`, '') }}>
              {t('inventory.form.changeProduct')}
            </Button>
          </div>
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
            <TextField required label={t('inventory.form.unitCost')} hint={t('inventory.form.unitCostHint')} error={errors?.unit_cost?.message} inputMode="decimal" autoComplete="off" {...form.register(`lines.${index}.unit_cost`)} />
            <div>
              <p className="mb-1.5 text-sm font-medium text-slate-800">{t('purchase.form.lineTotal')}</p>
              <p className="flex h-11 items-center text-sm font-semibold text-slate-900" aria-live="polite">{formatVnd(total)}</p>
            </div>
          </div>

          {product.track_batch && (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <TextField required label={t('inventory.form.batchNo')} error={errors?.batch_no?.message} autoComplete="off" {...form.register(`lines.${index}.batch_no`)} />
              <TextField required label={t('inventory.form.expiresAt')} type="date" error={errors?.expires_at?.message} {...form.register(`lines.${index}.expires_at`)} />
              <TextField label={t('inventory.form.manufacturedAt')} type="date" error={errors?.manufactured_at?.message} {...form.register(`lines.${index}.manufactured_at`)} />
            </div>
          )}
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
