import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft, Plus, Search, Trash2 } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useFieldArray, useForm, useWatch, type Resolver, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { FormPageSkeleton, ListSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Button, Card, SelectField, TextField } from '../../components/ui.tsx'
import { formatIsoDate, formatQuantity, formatVnd } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { parseNumber } from '../catalog/form.ts'
import { useProducts, type Product } from '../catalog/api.ts'
import { ADJUSTMENT_REASONS, DOCUMENT_TYPES, ISSUE_REASONS, useAllWarehouses, usePostStockDocument, useStockBalances, type DocumentType, type Warehouse } from './api.ts'
import { NEW_BATCH, applyStockErrors, defaultUnit, emptyLine, factorOf, isIncoming, stockPayload, stockSchema, type StockFormValues } from './forms.ts'

const inputClass = (error: boolean) =>
  `block w-full rounded-md border px-3 py-2 text-sm shadow-sm outline-none transition-colors focus:ring-2 disabled:bg-slate-100 ${
    error ? 'border-red-500 focus:ring-red-200' : 'border-slate-300 focus:border-emerald-600 focus:ring-emerald-200'
  }`

/** Ghi một phiếu kho: nhập kho, xuất kho, điều chỉnh hoặc tồn đầu kỳ (`/stock/new?type=receipt`). Kho tải xong mới dựng form. */
export default function StockDocumentFormPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [params] = useSearchParams()
  const warehouses = useAllWarehouses()

  if (warehouses.isPending) return <FormPageSkeleton fields={4} />

  if (warehouses.isError) {
    return (
      <div className="space-y-3">
        <Alert tone="danger">{t('common.loadError')}</Alert>
        <Link to="/stock" className="text-sm font-medium text-emerald-700 hover:underline">{t('inventory.form.back')}</Link>
      </div>
    )
  }

  const allowed = DOCUMENT_TYPES.filter((type) => (type === 'adjustment' ? can('inventory.adjustment.create') : can('inventory.movement.create')))
  const requested = params.get('type') as DocumentType | null
  const initial = requested && allowed.includes(requested) ? requested : (allowed[0] ?? 'receipt')

  return <StockForm initialType={initial} allowed={allowed} warehouses={warehouses.data.filter((w) => w.status === 'active')} />
}

function StockForm({ initialType, allowed, warehouses }: { initialType: DocumentType; allowed: DocumentType[]; warehouses: Warehouse[] }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const post = usePostStockDocument()
  const [formError, setFormError] = useState<string | null>(null)
  /** Sản phẩm đã chọn theo vị trí dòng: chứa đơn vị quy đổi và cờ theo dõi lô để dựng ô nhập. */
  const [picked, setPicked] = useState<Record<string, Product | undefined>>({})

  const resolver: Resolver<StockFormValues> = (values, context, options) => zodResolver(stockSchema(t, values.type))(values, context, options)
  const form = useForm<StockFormValues>({
    resolver,
    defaultValues: { type: initialType, warehouse_id: warehouses.length === 1 ? warehouses[0].id : '', reason: '', note: '', lines: [emptyLine()] },
  })
  const { fields, append, remove, replace } = useFieldArray({ control: form.control, name: 'lines' })
  const errors = form.formState.errors
  const type = useWatch({ control: form.control, name: 'type' })
  const warehouseId = useWatch({ control: form.control, name: 'warehouse_id' })
  const warehouse = warehouses.find((w) => w.id === warehouseId)
  const showBranch = new Set(warehouses.map((w) => w.branch.id)).size > 1
  const reasons = type === 'issue' ? ISSUE_REASONS : type === 'adjustment' ? ADJUSTMENT_REASONS : []

  /** Mỗi chi nhánh có danh mục sản phẩm riêng: đổi sang kho của chi nhánh khác thì các dòng đã chọn không còn hợp lệ. */
  function changeWarehouse(nextId: string) {
    const next = warehouses.find((w) => w.id === nextId)

    if (warehouse && next && next.branch.id !== warehouse.branch.id) {
      replace([emptyLine()])
      setPicked({})
    }
  }

  function changeType() {
    form.setValue('reason', '')
    form.clearErrors()
    // Đổi giữa nhập (có đơn giá, khai lô mới) và xuất/điều chỉnh (chọn lô có sẵn): lô đã chọn dở không còn ý nghĩa.
    form.getValues('lines').forEach((_, index) => {
      form.setValue(`lines.${index}.batch_id`, '')
      form.setValue(`lines.${index}.unit_cost`, '')
    })
  }

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    // Sản phẩm theo dõi lô: kiểm tra sớm số lô và hạn dùng (máy chủ vẫn kiểm tra lại).
    let missing = false
    values.lines.forEach((line, index) => {
      if (!picked[String(index)]?.track_batch) return

      const fresh = isIncoming(values.type) || line.batch_id === NEW_BATCH

      if (fresh && line.batch_no.trim() === '') {
        form.setError(`lines.${index}.batch_no`, { type: 'validate', message: t('inventory.form.batchNoRequired') })
        missing = true
      }

      if (fresh && line.expires_at === '') {
        form.setError(`lines.${index}.expires_at`, { type: 'validate', message: t('inventory.form.expiresRequired') })
        missing = true
      }

      if (!fresh && line.batch_id === '') {
        form.setError(`lines.${index}.batch_id`, { type: 'validate', message: t('inventory.form.batchRequired') })
        missing = true
      }
    })

    if (missing) return

    try {
      const document = await post.mutateAsync(stockPayload(values, picked))
      navigate(`/stock/documents/${document.id}`, { state: { created: document.code } })
    } catch (error) {
      const result = applyStockErrors(error, form.setError)
      if (result.leftover.length > 0) setFormError(result.leftover.join(' '))
      else if (!result.handled) setFormError(describeError(error, t))
    }
  })

  return (
    <div className="space-y-4">
      <Link to="/stock" className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t('inventory.form.back')}
      </Link>

      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t(`inventory.form.title.${type}`)}</h1>
        <p className="mt-1 text-sm text-slate-600">{t(`inventory.form.intro.${type}`)}</p>
      </div>

      {formError && <Alert tone="danger">{formError}</Alert>}

      <form onSubmit={submit} noValidate className="space-y-4">
        <Card title={t('inventory.form.general')}>
          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField
              label={t('inventory.form.type')}
              error={errors.type?.message}
              {...form.register('type', { onChange: changeType })}
            >
              {allowed.map((value) => <option key={value} value={value}>{t(`inventory.types.${value}`)}</option>)}
            </SelectField>

            <SelectField
              label={t('inventory.form.warehouse')}
              error={errors.warehouse_id?.message}
              {...form.register('warehouse_id', { onChange: (event) => changeWarehouse(event.target.value) })}
            >
              <option value="">{t('inventory.form.choose')}</option>
              {warehouses.map((w) => <option key={w.id} value={w.id}>{showBranch ? `${w.branch.code} — ${w.name}` : w.name}</option>)}
            </SelectField>

            {reasons.length > 0 && (
              <SelectField label={t('inventory.form.reason')} error={errors.reason?.message} {...form.register('reason')}>
                <option value="">{t('inventory.form.choose')}</option>
                {reasons.map((reason) => <option key={reason} value={reason}>{t(`inventory.reasons.${reason}`)}</option>)}
              </SelectField>
            )}

            <div className={reasons.length > 0 ? '' : 'sm:col-span-2'}>
              <label htmlFor="stock-note" className="mb-1 block text-sm font-medium text-slate-800">{t('inventory.form.note')}</label>
              <textarea id="stock-note" rows={2} className={inputClass(!!errors.note)} {...form.register('note')} />
              {errors.note && <p role="alert" className="mt-1 text-sm text-red-700">{errors.note.message}</p>}
            </div>
          </div>
        </Card>

        <Card title={t('inventory.form.lines')}>
          {!warehouse ? (
            <p className="text-sm text-slate-500">{t('inventory.form.pickWarehouseFirst')}</p>
          ) : (
            <div className="space-y-3">
              {fields.map((field, index) => (
                <LineEditor
                  key={field.id}
                  index={index}
                  form={form}
                  type={type}
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

              <Button variant="secondary" onClick={() => append(emptyLine())} disabled={fields.length >= 100}>
                <Plus className="h-4 w-4" aria-hidden="true" />
                {t('inventory.form.addLine')}
              </Button>
            </div>
          )}
        </Card>

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => navigate('/stock')}>{t('common.cancel')}</Button>
          <Button type="submit" loading={post.isPending}>{post.isPending ? t('common.saving') : t('inventory.form.submit')}</Button>
        </div>
      </form>
    </div>
  )
}

interface LineProps {
  index: number
  form: UseFormReturn<StockFormValues>
  type: DocumentType
  warehouse: Warehouse
  product: Product | undefined
  onPick: (product: Product | undefined) => void
  onRemove: () => void
  canRemove: boolean
}

/** Một dòng hàng của phiếu: chọn sản phẩm → đơn vị, số lượng, (đơn giá nhập), lô/hạn dùng nếu sản phẩm theo dõi lô. */
function LineEditor({ index, form, type, warehouse, product, onPick, onRemove, canRemove }: LineProps) {
  const { t } = useTranslation()
  const errors = form.formState.errors.lines?.[index]
  const line = useWatch({ control: form.control, name: `lines.${index}` })
  const incoming = isIncoming(type)
  const outgoing = type === 'issue' || (type === 'adjustment' && line.direction === 'out')
  const baseName = product?.base_unit.name ?? ''
  const factor = factorOf(product, line.unit_id)
  const quantity = parseNumber(line.quantity)
  const cost = parseNumber(line.unit_cost)
  const balances = useStockBalances({ warehouse: warehouse.id, product: product?.id ?? '' }, product !== undefined)
  const rows = balances.data ?? []
  const batchRows = rows.filter((row) => row.batch !== null)
  const batch = batchRows.find((row) => row.batch?.id === line.batch_id)
  const onHand = product?.track_batch ? (batch ? Number(batch.quantity) : null) : rows.reduce((sum, row) => sum + Number(row.quantity), 0)
  const fresh = incoming || line.batch_id === NEW_BATCH

  function choose(next: Product) {
    onPick(next)
    form.setValue(`lines.${index}.product_id`, next.id, { shouldValidate: true })
    form.setValue(`lines.${index}.unit_id`, defaultUnit(next), { shouldValidate: true })
    form.setValue(`lines.${index}.batch_id`, '')
  }

  return (
    <fieldset className="rounded-md border border-slate-200 p-3">
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
              {onHand !== null && <span className="ml-2 text-slate-500">{t('inventory.form.onHand', { qty: formatQuantity(onHand), unit: baseName })}</span>}
            </p>
            <Button variant="ghost" onClick={() => { onPick(undefined); form.setValue(`lines.${index}.product_id`, ''); form.setValue(`lines.${index}.batch_id`, '') }}>
              {t('inventory.form.changeProduct')}
            </Button>
          </div>

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {type === 'adjustment' && (
              <SelectField label={t('inventory.form.direction')} {...form.register(`lines.${index}.direction`)}>
                <option value="out">{t('inventory.form.directionOut')}</option>
                <option value="in">{t('inventory.form.directionIn')}</option>
              </SelectField>
            )}
            <SelectField label={t('inventory.form.unit')} error={errors?.unit_id?.message} {...form.register(`lines.${index}.unit_id`)}>
              {product.units.map((u) => <option key={u.unit.id} value={u.unit.id}>{u.unit.name}{u.is_base ? '' : ` (= ${formatQuantity(u.factor)} ${baseName})`}</option>)}
            </SelectField>
            <TextField
              label={t('inventory.form.quantity')}
              hint={quantity !== null && factor !== 1 ? t('inventory.form.equals', { qty: formatQuantity(quantity * factor), unit: baseName }) : undefined}
              error={errors?.quantity?.message}
              inputMode="decimal"
              autoComplete="off"
              {...form.register(`lines.${index}.quantity`)}
            />
            {incoming && (
              <TextField
                label={t('inventory.form.unitCost')}
                hint={cost !== null ? `${formatVnd(cost)}${factor !== 1 ? ` — ${t('inventory.form.perBase', { cost: formatVnd(Math.round((cost / factor) * 100) / 100), unit: baseName })}` : ''}` : t('inventory.form.unitCostHint')}
                error={errors?.unit_cost?.message}
                inputMode="decimal"
                autoComplete="off"
                {...form.register(`lines.${index}.unit_cost`)}
              />
            )}
          </div>

          {product.track_batch && (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {!incoming && (
                <SelectField label={t('inventory.form.batch')} className="sm:col-span-2" error={errors?.batch_id?.message} {...form.register(`lines.${index}.batch_id`)}>
                  <option value="">{t('inventory.form.choose')}</option>
                  {batchRows.map((row) => (
                    <option key={row.batch!.id} value={row.batch!.id}>
                      {row.batch!.batch_no} — {t('inventory.form.batchOption', { expiry: formatIsoDate(row.batch!.expires_at), qty: formatQuantity(row.quantity), unit: baseName })}{row.expired ? ` — ${t('inventory.form.batchExpired')}` : ''}
                    </option>
                  ))}
                  {type === 'adjustment' && !outgoing && <option value={NEW_BATCH}>{t('inventory.form.newBatch')}</option>}
                </SelectField>
              )}
              {fresh && (
                <>
                  <TextField label={t('inventory.form.batchNo')} error={errors?.batch_no?.message} autoComplete="off" {...form.register(`lines.${index}.batch_no`)} />
                  <TextField label={t('inventory.form.expiresAt')} type="date" error={errors?.expires_at?.message} {...form.register(`lines.${index}.expires_at`)} />
                  <TextField label={t('inventory.form.manufacturedAt')} type="date" error={errors?.manufactured_at?.message} {...form.register(`lines.${index}.manufactured_at`)} />
                </>
              )}
            </div>
          )}
        </div>
      )}

      {canRemove && (
        <div className="mt-2 flex justify-end">
          <Button variant="ghost" onClick={onRemove} aria-label={t('inventory.form.removeLine', { n: index + 1 })}>
            <Trash2 className="h-4 w-4 text-red-700" aria-hidden="true" />
            {t('inventory.form.removeLine', { n: index + 1 })}
          </Button>
        </div>
      )}
    </fieldset>
  )
}

/** Tìm sản phẩm của chi nhánh theo tên / mã / mã vạch (chỉ sản phẩm đang kinh doanh). */
function ProductPicker({ branchId, onPick }: { branchId: string; onPick: (product: Product) => void }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const search = useDeferredValue(q)
  const products = useProducts({ q: search, type: '', status: 'active', branch: branchId, category: '', brand: '', page: 1 })
  const rows = (products.data?.data ?? []).slice(0, 8)

  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-slate-800">
        {t('inventory.form.product')}
        <div className="relative mt-1">
          <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" aria-hidden="true" />
          <input type="search" value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('inventory.form.productPlaceholder')} autoComplete="off" className={`${inputClass(false)} pl-9 font-normal`} />
        </div>
      </label>
      {products.isPending ? (
        <div className="mt-2 rounded-md border border-slate-200 px-3"><ListSkeleton rows={3} subtitle /></div>
      ) : rows.length === 0 ? (
        <p className="mt-2 text-sm text-slate-500">{t('inventory.form.noProducts')}</p>
      ) : (
        <ul className="mt-2 max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-md border border-slate-200">
          {rows.map((product) => (
            <li key={product.id}>
              <button type="button" onClick={() => onPick(product)} className="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-emerald-50">
                <span><span className="font-mono text-xs">{product.sku}</span> <span className="font-medium text-slate-900">{product.name}</span></span>
                <span className="text-xs text-slate-500">{product.type_label} · {product.base_unit.name}{product.track_batch ? ` · ${t('inventory.stock.batch')}` : ''}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
