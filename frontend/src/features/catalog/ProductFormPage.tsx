import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { Alert, Badge, Button, Card, CheckboxField, SelectField, Spinner, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useAllBranches, type Branch } from '../branches/api.ts'
import { useCatalogList, useProduct, useProductTypes, useSaveProduct, type ListItem, type Product, type TypeDefinition } from './api.ts'
import { applyProductErrors, buildSchema, toFormValues, toPayload, type FormValues } from './form.ts'
import { UnitsEditor } from './UnitsEditor.tsx'

/** Thêm (`/products/new`) hoặc sửa (`/products/:id`) một sản phẩm. Dữ liệu tải xong mới dựng form để giá trị ban đầu đúng ngay từ đầu. */
export default function ProductFormPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const product = useProduct(id)
  const types = useProductTypes()
  // Ô chọn (select) chỉ nhận giá trị ban đầu lúc dựng: danh sách lựa chọn phải có sẵn trước, nếu không ô sẽ hiện "Chọn…" dù sản phẩm đã có giá trị.
  const units = useCatalogList('units')
  const categories = useCatalogList('categories')
  const brands = useCatalogList('brands')
  const branches = useAllBranches()

  if ((id !== undefined && product.isPending) || types.isPending || units.isPending || categories.isPending || brands.isPending || branches.isPending) {
    return <div className="flex justify-center py-10"><Spinner /></div>
  }

  if (product.isError || types.isError) {
    const notFound = product.error instanceof ApiError && (product.error.status === 404 || product.error.status === 403)

    return (
      <div className="space-y-3">
        <Alert tone="danger">{notFound ? t('catalog.form.notFound') : t('common.loadError')}</Alert>
        <Link to="/products" className="text-sm font-medium text-emerald-700 hover:underline">{t('catalog.form.back')}</Link>
      </div>
    )
  }

  return (
    <ProductForm
      key={product.data?.id ?? 'new'}
      product={product.data}
      types={types.data.data}
      maxUnits={types.data.meta.max_units}
      lists={{ units: units.data ?? [], categories: categories.data ?? [], brands: brands.data ?? [], branches: branches.data ?? [] }}
    />
  )
}

interface Lists {
  units: ListItem[]
  categories: ListItem[]
  brands: ListItem[]
  branches: Branch[]
}

/** Danh sách chọn chỉ có mục đang dùng, cộng thêm mục sản phẩm đang chọn dù đã ngừng dùng (để không mất giá trị khi sửa). */
function optionsFor(items: ListItem[] | undefined, current: string | undefined): ListItem[] {
  return (items ?? []).filter((item) => item.is_active || item.id === current)
}

function ProductForm({ product, types, maxUnits, lists }: { product?: Product; types: TypeDefinition[]; maxUnits: number; lists: Lists }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = useAuth()
  const creating = product === undefined
  const editable = creating ? can('catalog.product.create') : can('catalog.product.update')

  const save = useSaveProduct(product)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(() => buildSchema(t, types, creating, maxUnits), [t, types, creating, maxUnits])
  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: toFormValues(product, { branch_id: '' }) })
  const errors = form.formState.errors

  const type = useWatch({ control: form.control, name: 'type' })
  const trackBatch = useWatch({ control: form.control, name: 'track_batch' })
  const baseUnitId = useWatch({ control: form.control, name: 'base_unit_id' })
  const branchId = useWatch({ control: form.control, name: 'branch_id' })
  const definition = types.find((candidate) => candidate.key === type)
  const batchForced = definition?.track_batch_required ?? false

  // Chỉ có một chi nhánh được phép dùng thì chọn sẵn.
  const openBranches = lists.branches.filter((branch) => branch.status === 'active')
  const onlyBranch = openBranches.length === 1 ? openBranches[0].id : ''
  useEffect(() => {
    if (creating && branchId === '' && onlyBranch !== '') form.setValue('branch_id', onlyBranch)
  }, [creating, branchId, onlyBranch, form])

  const unitOptions = optionsFor(lists.units, undefined)
  const baseUnitName = lists.units.find((u) => u.id === baseUnitId)?.name ?? ''

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)
    const current = types.find((candidate) => candidate.key === values.type)
    if (!current) return

    try {
      await save.mutateAsync(toPayload(values, current, creating))
      navigate('/products', { state: { saved: creating ? 'created' : 'updated', name: values.name.trim() } })
    } catch (error) {
      const result = applyProductErrors(error, form.setError)
      if (result.leftover.length > 0) setFormError(result.leftover.join(' '))
      else if (!result.handled) setFormError(describeError(error, t))
    }
  })

  return (
    <div className="space-y-4">
      <Link to="/products" className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t('catalog.form.back')}
      </Link>

      <div>
        <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold text-slate-900">
          {creating ? t('catalog.form.createTitle') : t('catalog.form.editTitle', { name: product.name })}
          {product && <Badge tone="info">{product.type_label}</Badge>}
        </h1>
        <p className="mt-1 text-sm text-slate-600">{creating ? t('catalog.form.createIntro') : t('catalog.form.editIntro', { branch: product.branch.name })}</p>
      </div>

      {!editable && <Alert tone="info">{t('catalog.form.readOnly')}</Alert>}
      {formError && <Alert tone="danger">{formError}</Alert>}

      <form onSubmit={submit} noValidate className="space-y-4">
        <fieldset disabled={!editable} className="space-y-4">
          <Card title={t('catalog.form.general')}>
            <div className="grid gap-4 sm:grid-cols-2">
              {creating ? (
                <SelectField label={t('catalog.form.branch')} error={errors.branch_id?.message} hint={t('catalog.form.branchHint')} {...form.register('branch_id')}>
                  <option value="">{t('catalog.form.choose')}</option>
                  {openBranches.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} — {branch.name}</option>)}
                </SelectField>
              ) : (
                <ReadOnly label={t('catalog.form.branch')} value={`${product.branch.code} — ${product.branch.name}`} />
              )}

              {creating ? (
                <SelectField
                  label={t('catalog.form.type')}
                  error={errors.type?.message}
                  {...form.register('type', {
                    // Đổi loại hàng thì thông tin đặc thù của loại cũ không còn áp dụng; loại bắt buộc theo dõi lô thì bật sẵn.
                    onChange: (event) => {
                      form.setValue('specs', {})
                      form.clearErrors('specs')
                      form.setValue('track_batch', types.find((d) => d.key === event.target.value)?.track_batch_required ?? false)
                    },
                  })}
                >
                  <option value="">{t('catalog.form.choose')}</option>
                  {types.map((d) => <option key={d.key} value={d.key}>{d.label}</option>)}
                </SelectField>
              ) : (
                <ReadOnly label={t('catalog.form.type')} value={product.type_label} />
              )}

              <TextField label={t('catalog.form.sku')} hint={t('catalog.form.skuHint')} error={errors.sku?.message} autoComplete="off" {...form.register('sku')} />
              <TextField label={t('catalog.form.name')} error={errors.name?.message} {...form.register('name')} />

              <SelectField label={t('catalog.form.category')} error={errors.category_id?.message} {...form.register('category_id')}>
                <option value="">{t('catalog.form.none')}</option>
                {optionsFor(lists.categories, product?.category?.id).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </SelectField>
              <SelectField label={t('catalog.form.brand')} error={errors.brand_id?.message} {...form.register('brand_id')}>
                <option value="">{t('catalog.form.none')}</option>
                {optionsFor(lists.brands, product?.brand?.id).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </SelectField>

              <div className="sm:col-span-2">
                <label htmlFor="product-description" className="mb-1 block text-sm font-medium text-slate-800">{t('catalog.form.description')}</label>
                <textarea id="product-description" rows={2} className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200" {...form.register('description')} />
                {errors.description && <p role="alert" className="mt-1 text-sm text-red-700">{errors.description.message}</p>}
              </div>

              <SelectField label={t('catalog.form.status')} error={errors.status?.message} {...form.register('status')}>
                <option value="active">{t('catalog.status.active')}</option>
                <option value="inactive">{t('catalog.status.inactive')}</option>
              </SelectField>

              <div className="flex items-end pb-2">
                <CheckboxField
                  label={t('catalog.form.trackBatch')}
                  description={batchForced ? t('catalog.form.trackBatchForced') : t('catalog.form.trackBatchHint')}
                  checked={batchForced || trackBatch}
                  disabled={batchForced || !editable}
                  onChange={(checked) => form.setValue('track_batch', checked, { shouldDirty: true })}
                />
              </div>
            </div>
          </Card>

          {definition && definition.fields.length > 0 && (
            <Card title={t('catalog.form.specs', { type: definition.label })}>
              <div className="grid gap-4 sm:grid-cols-2">
                {definition.fields.map((field) => {
                  const name = `specs.${field.key}` as const
                  const label = field.required ? `${field.label} *` : field.label
                  const error = errors.specs?.[field.key]?.message

                  return field.type === 'select' ? (
                    <SelectField key={field.key} label={label} error={error} {...form.register(name)}>
                      <option value="">{t('catalog.form.none')}</option>
                      {(field.options ?? []).map((option) => <option key={option} value={option}>{option}</option>)}
                    </SelectField>
                  ) : (
                    <TextField key={field.key} label={label} error={error} inputMode={field.type === 'text' ? undefined : 'decimal'} autoComplete="off" {...form.register(name)} />
                  )
                })}
              </div>
            </Card>
          )}

          <Card title={t('catalog.form.unitsTitle')}>
            <p className="mb-4 text-sm text-slate-600">{t('catalog.form.unitsIntro')}</p>
            <div className="mb-4 max-w-sm">
              {creating ? (
                <SelectField label={t('catalog.form.baseUnit')} error={errors.base_unit_id?.message} hint={t('catalog.form.baseUnitHint')} {...form.register('base_unit_id')}>
                  <option value="">{t('catalog.form.choose')}</option>
                  {unitOptions.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                </SelectField>
              ) : (
                <ReadOnly label={t('catalog.form.baseUnit')} value={product.base_unit.name} />
              )}
            </div>
            <UnitsEditor form={form} unitOptions={unitOptions} baseUnitName={baseUnitName} maxUnits={maxUnits} />
            <p className="mt-3 text-xs text-slate-500">{t('catalog.form.creditHint')}</p>
          </Card>
        </fieldset>

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => navigate('/products')}>{t('common.cancel')}</Button>
          {editable && <Button type="submit" loading={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</Button>}
        </div>
      </form>
    </div>
  )
}

function ReadOnly({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="mb-1 text-sm font-medium text-slate-800">{label}</p>
      <p className="rounded-md bg-slate-100 px-3 py-2 text-sm text-slate-700">{value}</p>
    </div>
  )
}
