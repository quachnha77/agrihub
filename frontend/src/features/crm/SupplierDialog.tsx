import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { DialogSkeleton, DialogSubject, FormSection, RequiredNote, UnsavedChangesDialog } from '../../components/dialog-parts.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { Alert, Badge, Button, Modal, ModalActions, SelectField, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import type { DialogProps } from '../../lib/dialog.ts'
import { describeError } from '../../lib/forms.ts'
import { useToast } from '../../lib/toast.ts'
import { useUnsavedGuard } from '../../lib/unsaved.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useSaveSupplier, useSupplier, type Supplier } from './api.ts'
import { applyPartyErrors, supplierDefaults, supplierPayload, supplierSchema, type SupplierFormValues } from './forms.ts'
import { SupplierContactsEditor } from './PartyEditors.tsx'

const KNOWN = ['code', 'name', 'tax_code', 'phone', 'email', 'address', 'payment_terms_days', 'notes', 'status']

/** Popup thêm (`?dialog=supplier`) hoặc sửa (`?dialog=supplier:<id>`) một nhà cung cấp. */
export default function SupplierDialog({ arg: id, onClose }: DialogProps) {
  const { t } = useTranslation()
  const supplier = useSupplier(id)

  const creating = id === undefined
  const loaded = supplier.data
  const notFound = supplier.error instanceof ApiError && (supplier.error.status === 404 || supplier.error.status === 403)

  return (
    <Modal
      size="xl"
      fixedHeight
      dismissOnBackdrop={false}
      onClose={onClose}
      title={creating ? t('crm.suppliers.createTitle') : t('crm.suppliers.editTitle')}
      description={creating ? t('crm.suppliers.formIntro') : <DialogSubject name={loaded?.name} badge={loaded && <Badge tone="info">{loaded.code}</Badge>} />}
    >
      {supplier.isError ? (
        <div className="space-y-4">
          <Alert tone="danger">{notFound ? t('crm.suppliers.notFound') : t('common.loadError')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : !creating && supplier.isPending ? (
        <DialogSkeleton fields={6} />
      ) : (
        <SupplierForm key={loaded?.id ?? 'new'} supplier={loaded} onClose={onClose} />
      )}
    </Modal>
  )
}

function ReadOnly({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="mb-1.5 text-sm font-medium text-slate-800">{label}</p>
      <p className="flex min-h-10 items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-base text-slate-700 md:text-sm">{value}</p>
    </div>
  )
}

function SupplierForm({ supplier, onClose }: { supplier?: Supplier; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { can } = useAuth()
  const creating = supplier === undefined
  const editable = creating ? can('purchase.supplier.create') : can('purchase.supplier.update')

  const save = useSaveSupplier(supplier)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(() => supplierSchema(t, creating), [t, creating])
  const form = useForm<SupplierFormValues>({ resolver: zodResolver(schema), defaultValues: supplierDefaults(supplier) })
  const errors = form.formState.errors
  const guard = useUnsavedGuard(form.formState.isDirty)

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await save.mutateAsync(supplierPayload(values, creating))
      toast.success(t(creating ? 'crm.suppliers.savedCreated' : 'crm.suppliers.savedUpdated', { name: values.name.trim() }))
      guard.release()
      onClose()
    } catch (error) {
      const result = applyPartyErrors(error, form.setError, KNOWN)
      if (result.leftover.length > 0) setFormError(result.leftover.join(' '))
      else if (!result.handled) setFormError(describeError(error, t))
    }
  })

  return (
    <div className="flex min-h-full flex-col gap-4">
      {!editable && <Alert tone="info">{t('crm.suppliers.readOnly')}</Alert>}
      {formError && <Alert tone="danger">{formError}</Alert>}

      <form onSubmit={submit} noValidate className="flex flex-1 flex-col gap-5">
        {editable && <RequiredNote />}

        <fieldset disabled={!editable} className="space-y-5">
          <FormSection title={t('crm.form.general')}>
            <div className="grid gap-4 sm:grid-cols-2">
              {creating ? (
                <TextField label={t('crm.form.code')} hint={t('crm.form.codeHint', { example: 'NCC0001' })} error={errors.code?.message} autoComplete="off" {...form.register('code')} />
              ) : (
                <ReadOnly label={t('crm.form.code')} value={supplier.code} />
              )}
              <TextField required label={t('crm.form.supplierName')} error={errors.name?.message} autoComplete="off" {...form.register('name')} />
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
          </FormSection>

          <FormSection title={t('crm.contacts.title')}>
            <SupplierContactsEditor form={form} />
          </FormSection>
        </fieldset>

        <ModalActions>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          {editable && <Button type="submit" loading={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</Button>}
        </ModalActions>
      </form>

      <UnsavedChangesDialog guard={guard} />
    </div>
  )
}
