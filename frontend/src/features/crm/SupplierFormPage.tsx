import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { FormActions, PageHeader } from '../../components/page.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { FormPageSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Button, Card, SelectField, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useSaveSupplier, useSupplier, type Supplier } from './api.ts'
import { applyPartyErrors, supplierDefaults, supplierPayload, supplierSchema, type SupplierFormValues } from './forms.ts'
import { SupplierContactsEditor } from './PartyEditors.tsx'

const KNOWN = ['code', 'name', 'tax_code', 'phone', 'email', 'address', 'payment_terms_days', 'notes', 'status']

/** Thêm (`/suppliers/new`) hoặc sửa (`/suppliers/:id`) một nhà cung cấp. */
export default function SupplierFormPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const supplier = useSupplier(id)

  if (id !== undefined && supplier.isPending) return <FormPageSkeleton fields={6} />

  if (supplier.isError) {
    const notFound = supplier.error instanceof ApiError && (supplier.error.status === 404 || supplier.error.status === 403)

    return (
      <div className="space-y-3">
        <Alert tone="danger">{notFound ? t('crm.suppliers.notFound') : t('common.loadError')}</Alert>
        <Link to="/suppliers" className="text-sm font-medium text-brand-700 hover:underline">{t('crm.suppliers.back')}</Link>
      </div>
    )
  }

  return <SupplierForm key={supplier.data?.id ?? 'new'} supplier={supplier.data} />
}

function ReadOnly({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="mb-1.5 text-sm font-medium text-slate-800">{label}</p>
      <p className="flex min-h-10 items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-base text-slate-700 md:text-sm">{value}</p>
    </div>
  )
}

function SupplierForm({ supplier }: { supplier?: Supplier }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = useAuth()
  const creating = supplier === undefined
  const editable = creating ? can('purchase.supplier.create') : can('purchase.supplier.update')

  const save = useSaveSupplier(supplier)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(() => supplierSchema(t, creating), [t, creating])
  const form = useForm<SupplierFormValues>({ resolver: zodResolver(schema), defaultValues: supplierDefaults(supplier) })
  const errors = form.formState.errors

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await save.mutateAsync(supplierPayload(values, creating))
      navigate('/suppliers', { state: { saved: creating ? 'created' : 'updated', name: values.name.trim() } })
    } catch (error) {
      const result = applyPartyErrors(error, form.setError, KNOWN)
      if (result.leftover.length > 0) setFormError(result.leftover.join(' '))
      else if (!result.handled) setFormError(describeError(error, t))
    }
  })

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <PageHeader
        back={{ to: '/suppliers', label: t('crm.suppliers.back') }}
        title={
          <span className="flex flex-wrap items-center gap-2">
            {creating ? t('crm.suppliers.createTitle') : t('crm.suppliers.editTitle', { name: supplier.name })}
            {supplier && <Badge tone="info">{supplier.code}</Badge>}
          </span>
        }
        description={t('crm.suppliers.formIntro')}
      />

      {!editable && <Alert tone="info">{t('crm.suppliers.readOnly')}</Alert>}
      {formError && <Alert tone="danger">{formError}</Alert>}

      <form onSubmit={submit} noValidate className="space-y-5">
        <fieldset disabled={!editable} className="space-y-4">
          <Card title={t('crm.form.general')}>
            <div className="grid gap-4 sm:grid-cols-2">
              {creating ? (
                <TextField label={t('crm.form.code')} hint={t('crm.form.codeHint', { example: 'NCC0001' })} error={errors.code?.message} autoComplete="off" {...form.register('code')} />
              ) : (
                <ReadOnly label={t('crm.form.code')} value={supplier.code} />
              )}
              <TextField label={t('crm.form.supplierName')} error={errors.name?.message} autoComplete="off" {...form.register('name')} />
              <TextField label={t('crm.form.taxCode')} hint={t('crm.form.taxCodeHint')} error={errors.tax_code?.message} inputMode="numeric" autoComplete="off" {...form.register('tax_code')} />
              <TextField label={t('crm.form.phone')} error={errors.phone?.message} inputMode="tel" autoComplete="off" {...form.register('phone')} />
              <TextField label={t('crm.form.email')} error={errors.email?.message} inputMode="email" autoComplete="off" {...form.register('email')} />
              <TextField label={t('crm.form.address')} error={errors.address?.message} autoComplete="off" {...form.register('address')} />
              <TextField label={t('crm.form.paymentTerms')} hint={t('crm.form.paymentTermsHint')} error={errors.payment_terms_days?.message} inputMode="numeric" autoComplete="off" {...form.register('payment_terms_days')} />
              <SelectField label={t('crm.form.status')} error={errors.status?.message} {...form.register('status')}>
                <option value="active">{t('crm.suppliers.status.active')}</option>
                <option value="inactive">{t('crm.suppliers.status.inactive')}</option>
              </SelectField>

              <div className="sm:col-span-2">
                <label htmlFor="supplier-notes" className="mb-1.5 block text-sm font-medium text-slate-800">{t('crm.form.notes')}</label>
                <textarea id="supplier-notes" rows={2} className={`${fieldBase} ${fieldTone(false)}`} {...form.register('notes')} />
                {errors.notes && <p role="alert" className="mt-1 text-sm text-red-700">{errors.notes.message}</p>}
              </div>
            </div>
          </Card>

          <Card title={t('crm.contacts.title')}>
            <SupplierContactsEditor form={form} />
          </Card>
        </fieldset>

        <FormActions>
          <Button variant="ghost" onClick={() => navigate('/suppliers')}>{t('common.cancel')}</Button>
          {editable && <Button type="submit" loading={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</Button>}
        </FormActions>
      </form>
    </div>
  )
}
