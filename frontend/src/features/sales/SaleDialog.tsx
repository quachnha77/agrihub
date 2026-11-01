import { zodResolver } from '@hookform/resolvers/zod'
import { Plus, Trash2, X } from 'lucide-react'
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
import type { Product } from '../catalog/api.ts'
import { parseNumber } from '../catalog/form.ts'
import type { Customer } from '../crm/api.ts'
import { useAllWarehouses, type Warehouse } from '../inventory/api.ts'
import { defaultUnit, factorOf } from '../inventory/forms.ts'
import { ProductPicker } from '../inventory/ProductPicker.tsx'
import { PAYMENT_METHODS, useCreateSale } from './api.ts'
import { applySaleErrors, emptySaleLine, goodsTotal, lineTotal, payable, salePayload, saleSchema, suggestedPrice, type PayMode, type SaleFormValues } from './forms.ts'
import { CustomerPicker } from './parts.tsx'

/**
 * Popup lập phiếu bán hàng (`?dialog=sale-new`): chọn hàng (số lượng, đơn giá gợi ý theo giá tiền mặt / giá công nợ), khách (bỏ trống = khách lẻ), số tiền bớt và cách trả.
 * Bán xong là hàng ra kho ngay (không cần duyệt). Khách trả đủ thì xong; ghi nợ thì phải chọn khách và máy chủ chặn khi vượt hạn mức. Sản phẩm theo dõi lô tự lấy lô gần hết hạn trước.
 */
export default function SaleDialog({ onClose }: DialogProps) {
  const { t } = useTranslation()
  const warehouses = useAllWarehouses()

  return (
    <Modal size="xl" fixedHeight dismissOnBackdrop={false} onClose={onClose} title={t('sales.form.title')} description={t('sales.form.intro')}>
      {warehouses.isError ? (
        <div className="space-y-4">
          <Alert tone="danger">{t('common.loadError')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : warehouses.isPending ? (
        <DialogSkeleton fields={3} />
      ) : (
        <SaleForm warehouses={warehouses.data.filter((w) => w.status === 'active')} onClose={onClose} />
      )}
    </Modal>
  )
}

function SaleForm({ warehouses, onClose }: { warehouses: Warehouse[]; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { link } = useDialog()
  const create = useCreateSale()
  const [formError, setFormError] = useState<string | null>(null)
  /** Sản phẩm đã chọn theo vị trí dòng: chứa các đơn vị và giá để điền đơn giá gợi ý. */
  const [picked, setPicked] = useState<Record<string, Product | undefined>>({})
  const [customer, setCustomer] = useState<Customer | null>(null)
  const [choosingCustomer, setChoosingCustomer] = useState(false)
  const schema = useMemo(() => saleSchema(t), [t])

  const form = useForm<SaleFormValues>({
    resolver: zodResolver(schema),
    defaultValues: { warehouse_id: warehouses.length === 1 ? warehouses[0].id : '', customer_id: '', discount: '', pay_mode: 'full', prepaid: '', payment_method: 'cash', note: '', lines: [emptySaleLine()] },
  })
  const { fields, append, remove, replace } = useFieldArray({ control: form.control, name: 'lines' })
  const errors = form.formState.errors
  const guard = useUnsavedGuard(form.formState.isDirty)
  const warehouseId = useWatch({ control: form.control, name: 'warehouse_id' })
  const lines = useWatch({ control: form.control, name: 'lines' })
  const payMode = useWatch({ control: form.control, name: 'pay_mode' })
  const discountText = useWatch({ control: form.control, name: 'discount' })
  const prepaidText = useWatch({ control: form.control, name: 'prepaid' })
  const warehouse = warehouses.find((w) => w.id === warehouseId)
  const showBranch = new Set(warehouses.map((w) => w.branch.id)).size > 1
  const goods = goodsTotal(lines)
  const discount = parseNumber(discountText) ?? 0
  const due = payable(goods, discount)
  const prepaid = payMode === 'full' ? due : Math.min(parseNumber(prepaidText) ?? 0, due)
  const owed = Math.max(due - prepaid, 0)

  /** Mỗi chi nhánh có danh mục sản phẩm riêng: đổi sang kho của chi nhánh khác thì các dòng đã chọn không còn hợp lệ. */
  function changeWarehouse(nextId: string) {
    const next = warehouses.find((w) => w.id === nextId)

    if (warehouse && next && next.branch.id !== warehouse.branch.id) {
      replace([emptySaleLine()])
      setPicked({})
    }
  }

  /** Đổi cách trả thì đổi luôn đơn giá gợi ý (tiền mặt ↔ công nợ) của những dòng chưa bị sửa tay. */
  function changeMode(next: PayMode) {
    lines.forEach((line, index) => {
      const product = picked[String(index)]
      if (!product) return
      if (line.unit_price === '' || line.unit_price === suggestedPrice(product, line.unit_id, payMode)) form.setValue(`lines.${index}.unit_price`, suggestedPrice(product, line.unit_id, next))
    })
  }

  function chooseCustomer(next: Customer | null) {
    setCustomer(next)
    setChoosingCustomer(false)
    form.setValue('customer_id', next?.id ?? '', { shouldDirty: true, shouldValidate: form.formState.isSubmitted })
  }

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      const sale = await create.mutateAsync(salePayload(values))
      toast.success(t('sales.created', { code: sale.code }), { label: t('sales.view'), ...link('sale', sale.id) })
      guard.release()
      onClose()
    } catch (error) {
      const result = applySaleErrors(error, form.setError, values.pay_mode)
      if (result.leftover.length > 0) setFormError(result.leftover.join(' '))
      else if (!result.handled) setFormError(describeError(error, t))
    }
  })

  return (
    <div className="flex min-h-full flex-col gap-4">
      {formError && <Alert tone="danger">{formError}</Alert>}

      <form onSubmit={submit} noValidate className="flex flex-1 flex-col gap-5">
        <RequiredNote />

        <FormSection title={t('sales.form.general')}>
          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField required label={t('sales.form.warehouse')} error={errors.warehouse_id?.message} {...form.register('warehouse_id', { onChange: (event) => changeWarehouse(event.target.value) })}>
              <option value="">{t('inventory.form.choose')}</option>
              {warehouses.map((w) => {
                const name = showBranch ? `${w.branch.code} — ${w.name}` : w.name

                // Kho đang kiểm kê bị khóa: không bán được cho tới khi hoàn tất hoặc hủy đợt kiểm kê.
                return <option key={w.id} value={w.id} disabled={!!w.stocktake}>{w.stocktake ? t('inventory.stocktake.lockedOption', { name }) : name}</option>
              })}
            </SelectField>

            <div>
              <p className="mb-1.5 text-sm font-medium text-slate-800">{t('sales.form.customer')}</p>
              {choosingCustomer ? (
                <div className="space-y-2">
                  <CustomerPicker onPick={chooseCustomer} />
                  <Button variant="ghost" size="sm" onClick={() => setChoosingCustomer(false)}>{t('common.cancel')}</Button>
                </div>
              ) : customer ? (
                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                  <div className="flex items-center justify-between gap-2">
                    <span><span className="font-medium text-slate-900">{customer.name}</span> <span className="font-mono text-xs text-slate-500">{customer.code}</span></span>
                    <Button variant="ghost" size="sm" onClick={() => chooseCustomer(null)} aria-label={t('sales.form.removeCustomer')}>
                      <X className="h-4 w-4" aria-hidden="true" />
                    </Button>
                  </div>
                  <p className="mt-0.5 text-xs text-slate-600">
                    {Number(customer.credit_limit) > 0
                      ? t('sales.form.creditInfo', { limit: formatVnd(Number(customer.credit_limit)), days: customer.credit_days ?? '—' })
                      : t('sales.form.noCredit')}
                  </p>
                </div>
              ) : (
                <div className="flex items-center justify-between gap-2 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-sm">
                  <span className="text-slate-700">{t('sales.form.walkIn')}</span>
                  <Button variant="secondary" size="sm" onClick={() => setChoosingCustomer(true)}>{t('sales.form.chooseCustomer')}</Button>
                </div>
              )}
              {errors.customer_id && <p role="alert" className="mt-1 text-sm text-red-700">{errors.customer_id.message}</p>}
            </div>
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
                  payMode={payMode}
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

              <Button variant="secondary" onClick={() => append(emptySaleLine())} disabled={fields.length >= 100}>
                <Plus className="h-4 w-4" aria-hidden="true" />
                {t('inventory.form.addLine')}
              </Button>
            </div>
          )}
        </FormSection>

        <FormSection title={t('sales.form.payment')}>
          <div className="space-y-4">
            <fieldset>
              <legend className="mb-1.5 text-sm font-medium text-slate-800">{t('sales.form.payMode')}</legend>
              <div className="grid gap-2 sm:grid-cols-2">
                {(['full', 'debt'] as const).map((mode) => (
                  <label key={mode} className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                    <input type="radio" value={mode} className="mt-1 accent-brand-700" {...form.register('pay_mode', { onChange: (event) => changeMode(event.target.value as PayMode) })} />
                    <span><span className="block font-medium text-slate-900">{t(`sales.form.modes.${mode}`)}</span><span className="block text-xs text-slate-600">{t(`sales.form.modes.${mode}Hint`)}</span></span>
                  </label>
                ))}
              </div>
            </fieldset>

            <div className="grid gap-4 sm:grid-cols-2">
              <TextField label={t('sales.form.discount')} hint={t('sales.form.discountHint')} error={errors.discount?.message} inputMode="decimal" autoComplete="off" {...form.register('discount')} />
              {payMode === 'debt' && <TextField label={t('sales.form.prepaid')} hint={t('sales.form.prepaidHint')} error={errors.prepaid?.message} inputMode="decimal" autoComplete="off" {...form.register('prepaid')} />}
              {prepaid > 0 && (
                <SelectField label={t('sales.form.method')} {...form.register('payment_method')}>
                  {PAYMENT_METHODS.map((method) => <option key={method} value={method}>{t(`sales.methods.${method}`)}</option>)}
                </SelectField>
              )}
            </div>
          </div>

          <dl className="mt-4 grid gap-2 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-4" aria-label={t('sales.form.totals')}>
            <div><dt className="text-slate-500">{t('sales.form.goodsTotal')}</dt><dd className="font-semibold text-slate-900">{formatVnd(goods)}</dd></div>
            <div><dt className="text-slate-500">{t('sales.form.payable')}</dt><dd className="font-semibold text-slate-900">{formatVnd(due)}</dd></div>
            <div><dt className="text-slate-500">{t('sales.form.paidNow')}</dt><dd className="font-semibold text-slate-900">{formatVnd(prepaid)}</dd></div>
            <div><dt className="text-slate-500">{t('sales.form.owed')}</dt><dd className={`font-semibold ${owed > 0 ? 'text-red-700' : 'text-brand-800'}`}>{formatVnd(owed)}</dd></div>
          </dl>
        </FormSection>

        <div>
          <label htmlFor="sale-note" className="mb-1.5 block text-sm font-medium text-slate-800">{t('inventory.form.note')}</label>
          <textarea id="sale-note" rows={2} className={`${fieldBase} ${fieldTone(!!errors.note)}`} {...form.register('note')} />
          {errors.note && <p role="alert" className="mt-1 text-sm text-red-700">{errors.note.message}</p>}
        </div>

        <ModalActions>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={create.isPending}>{create.isPending ? t('common.saving') : t('sales.form.submit')}</Button>
        </ModalActions>
      </form>

      <UnsavedChangesDialog guard={guard} />
    </div>
  )
}

interface LineProps {
  index: number
  form: UseFormReturn<SaleFormValues>
  warehouse: Warehouse
  payMode: PayMode
  product: Product | undefined
  onPick: (product: Product | undefined) => void
  onRemove: () => void
  canRemove: boolean
}

/** Một dòng hàng bán: chọn sản phẩm của chi nhánh kho → đơn vị, số lượng, đơn giá (gợi ý theo giá tiền mặt / công nợ, sửa được). Lô do máy chủ tự chọn. */
function LineEditor({ index, form, warehouse, payMode, product, onPick, onRemove, canRemove }: LineProps) {
  const { t } = useTranslation()
  const errors = form.formState.errors.lines?.[index]
  const line = useWatch({ control: form.control, name: `lines.${index}` })
  const baseName = product?.base_unit.name ?? ''
  const factor = factorOf(product, line.unit_id)
  const quantity = parseNumber(line.quantity)
  const total = lineTotal(line)

  function choose(next: Product) {
    const unit = defaultUnit(next)
    onPick(next)
    form.setValue(`lines.${index}.product_id`, next.id, { shouldValidate: true })
    form.setValue(`lines.${index}.unit_id`, unit, { shouldValidate: true })
    form.setValue(`lines.${index}.unit_price`, suggestedPrice(next, unit, payMode))
  }

  /** Đổi đơn vị thì đổi đơn giá gợi ý theo đơn vị mới, trừ khi người bán đã sửa giá tay. */
  function changeUnit(unitId: string) {
    if (line.unit_price === '' || line.unit_price === suggestedPrice(product, line.unit_id, payMode)) form.setValue(`lines.${index}.unit_price`, suggestedPrice(product, unitId, payMode))
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
              {product.track_batch && <Badge tone="neutral">{t('sales.form.autoBatch')}</Badge>}
            </p>
            <Button variant="secondary" size="sm" onClick={() => { onPick(undefined); form.setValue(`lines.${index}.product_id`, '') }}>
              {t('inventory.form.changeProduct')}
            </Button>
          </div>
          {errors?.product_id && <p role="alert" className="text-sm text-red-700">{errors.product_id.message}</p>}

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <SelectField required label={t('inventory.form.unit')} error={errors?.unit_id?.message} {...form.register(`lines.${index}.unit_id`, { onChange: (event) => changeUnit(event.target.value) })}>
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
            <TextField required label={t('sales.form.unitPrice')} error={errors?.unit_price?.message} inputMode="decimal" autoComplete="off" {...form.register(`lines.${index}.unit_price`)} />
            <div>
              <p className="mb-1.5 text-sm font-medium text-slate-800">{t('sales.form.lineTotal')}</p>
              <p className="flex h-11 items-center text-sm font-semibold text-slate-900" aria-live="polite">{formatVnd(total)}</p>
            </div>
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
