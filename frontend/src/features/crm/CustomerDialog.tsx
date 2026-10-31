import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { DialogSkeleton, DialogSubject, FormSection, RequiredNote, UnsavedChangesDialog } from '../../components/dialog-parts.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { Alert, Badge, Button, Modal, ModalActions, SelectField, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import type { DialogProps } from '../../lib/dialog.ts'
import { formatVnd } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useToast } from '../../lib/toast.ts'
import { useUnsavedGuard } from '../../lib/unsaved.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useAllBranches, type Branch } from '../branches/api.ts'
import { useCatalogList, type ListItem } from '../catalog/api.ts'
import { CUSTOMER_TYPES, useCustomer, useSaveCustomer, type Customer } from './api.ts'
import { applyPartyErrors, customerDefaults, customerPayload, customerSchema, parseNumber, type CustomerFormValues } from './forms.ts'
import { AddressesEditor, CustomerContactsEditor } from './PartyEditors.tsx'

const KNOWN = ['branch_id', 'code', 'type', 'name', 'phone', 'email', 'tax_code', 'group_id', 'credit_limit', 'credit_days', 'notes', 'status']

/**
 * Popup thêm (`?dialog=customer`) hoặc sửa (`?dialog=customer:<id>`) một khách hàng. Khung popup hiện ngay; biểu mẫu chỉ dựng sau khi
 * danh sách chọn tải xong để ô chọn có giá trị đúng ngay từ đầu.
 */
export default function CustomerDialog({ arg: id, onClose }: DialogProps) {
  const { t } = useTranslation()
  const customer = useCustomer(id)
  const groups = useCatalogList('customer-groups')
  const branches = useAllBranches()

  const creating = id === undefined
  const loaded = customer.data
  const notFound = customer.error instanceof ApiError && (customer.error.status === 404 || customer.error.status === 403)
  const loading = (!creating && customer.isPending) || groups.isPending || branches.isPending

  return (
    <Modal
      size="xl"
      fixedHeight
      dismissOnBackdrop={false}
      onClose={onClose}
      title={creating ? t('crm.customers.createTitle') : t('crm.customers.editTitle')}
      description={creating ? t('crm.customers.createIntro') : <DialogSubject name={loaded?.name} badge={loaded && <Badge tone="info">{loaded.code}</Badge>} />}
    >
      {customer.isError ? (
        <div className="space-y-4">
          <Alert tone="danger">{notFound ? t('crm.customers.notFound') : t('common.loadError')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : loading ? (
        <DialogSkeleton fields={8} />
      ) : (
        <CustomerForm key={loaded?.id ?? 'new'} customer={loaded} groups={groups.data ?? []} branches={branches.data ?? []} onClose={onClose} />
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

function CustomerForm({ customer, groups, branches, onClose }: { customer?: Customer; groups: ListItem[]; branches: Branch[]; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { can } = useAuth()
  const creating = customer === undefined
  const editable = creating ? can('crm.customer.create') : can('crm.customer.update')

  const save = useSaveCustomer(customer)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(() => customerSchema(t, creating), [t, creating])
  const form = useForm<CustomerFormValues>({ resolver: zodResolver(schema), defaultValues: customerDefaults(customer) })
  const errors = form.formState.errors
  const guard = useUnsavedGuard(form.formState.isDirty)

  const creditLimit = useWatch({ control: form.control, name: 'credit_limit' })
  const branchId = useWatch({ control: form.control, name: 'branch_id' })

  // Chỉ có một chi nhánh đang hoạt động thì chọn sẵn.
  const openBranches = branches.filter((branch) => branch.status === 'active' || branch.id === customer?.branch.id)
  const onlyBranch = openBranches.length === 1 ? openBranches[0].id : ''
  useEffect(() => {
    if (creating && branchId === '' && onlyBranch !== '') form.setValue('branch_id', onlyBranch)
  }, [creating, branchId, onlyBranch, form])

  const limit = parseNumber(creditLimit)
  const groupOptions = groups.filter((group) => group.is_active || group.id === customer?.group?.id)

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await save.mutateAsync(customerPayload(values, customer))
      toast.success(t(creating ? 'crm.customers.savedCreated' : 'crm.customers.savedUpdated', { name: values.name.trim() }))
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
      {!editable && <Alert tone="info">{t('crm.customers.readOnly')}</Alert>}
      {formError && <Alert tone="danger">{formError}</Alert>}

      <form onSubmit={submit} noValidate className="flex flex-1 flex-col gap-5">
        {editable && <RequiredNote />}

        <fieldset disabled={!editable} className="space-y-5">
          <FormSection title={t('crm.form.general')}>
            <div className="grid gap-4 sm:grid-cols-2">
              <SelectField required label={t('crm.form.type')} error={errors.type?.message} {...form.register('type')}>
                {CUSTOMER_TYPES.map((type) => <option key={type} value={type}>{t(`crm.types.${type}`)}</option>)}
              </SelectField>

              {creating ? (
                <TextField label={t('crm.form.code')} hint={t('crm.form.codeHint', { example: 'KH0001' })} error={errors.code?.message} autoComplete="off" {...form.register('code')} />
              ) : (
                <ReadOnly label={t('crm.form.code')} value={customer.code} />
              )}

              <TextField required label={t('crm.form.name')} error={errors.name?.message} autoComplete="off" className="sm:col-span-2" {...form.register('name')} />
              <TextField label={t('crm.form.phone')} error={errors.phone?.message} inputMode="tel" autoComplete="off" {...form.register('phone')} />
              <TextField label={t('crm.form.email')} error={errors.email?.message} inputMode="email" autoComplete="off" {...form.register('email')} />
              <TextField label={t('crm.form.taxCode')} hint={t('crm.form.taxCodeHint')} error={errors.tax_code?.message} inputMode="numeric" autoComplete="off" {...form.register('tax_code')} />

              <SelectField label={t('crm.form.group')} hint={t('crm.form.groupHint')} error={errors.group_id?.message} {...form.register('group_id')}>
                <option value="">{t('crm.form.none')}</option>
                {groupOptions.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
              </SelectField>

              <SelectField required label={t('crm.form.branch')} hint={creating ? t('crm.form.branchHint') : t('crm.form.branchMoveHint')} error={errors.branch_id?.message} {...form.register('branch_id')}>
                <option value="">{t('crm.form.choose')}</option>
                {openBranches.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} — {branch.name}</option>)}
              </SelectField>

              <SelectField label={t('crm.form.status')} error={errors.status?.message} {...form.register('status')}>
                <option value="active">{t('crm.status.active')}</option>
                <option value="inactive">{t('crm.status.inactive')}</option>
              </SelectField>

              <div className="sm:col-span-2">
                <label htmlFor="customer-notes" className="mb-1.5 block text-sm font-medium text-slate-800">{t('crm.form.notes')}</label>
                <textarea id="customer-notes" rows={2} className={`${fieldBase} ${fieldTone(false)}`} {...form.register('notes')} />
                {errors.notes && <p role="alert" className="mt-1 text-sm text-red-700">{errors.notes.message}</p>}
              </div>
            </div>
          </FormSection>

          <FormSection title={t('crm.form.creditTitle')} description={t('crm.form.creditIntro')}>
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField
                label={t('crm.form.creditLimit')}
                hint={limit !== null ? `${formatVnd(limit)}${limit === 0 ? ` — ${t('crm.form.creditLimitZero')}` : ''}` : t('crm.form.creditLimitHint')}
                error={errors.credit_limit?.message}
                inputMode="decimal"
                autoComplete="off"
                {...form.register('credit_limit')}
              />
              <TextField label={t('crm.form.creditDays')} hint={t('crm.form.creditDaysHint')} error={errors.credit_days?.message} inputMode="numeric" autoComplete="off" {...form.register('credit_days')} />
            </div>
          </FormSection>

          <FormSection title={t('crm.addresses.title')} description={t('crm.addresses.intro')}>
            <AddressesEditor form={form} />
          </FormSection>

          <FormSection title={t('crm.contacts.title')}>
            <CustomerContactsEditor form={form} />
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
