import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { FormActions, PageHeader } from '../../components/page.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { FormPageSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Button, Card, SelectField, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { formatVnd } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useAllBranches, type Branch } from '../branches/api.ts'
import { useCatalogList, type ListItem } from '../catalog/api.ts'
import { CUSTOMER_TYPES, useCustomer, useSaveCustomer, type Customer } from './api.ts'
import { applyPartyErrors, customerDefaults, customerPayload, customerSchema, parseNumber, type CustomerFormValues } from './forms.ts'
import { AddressesEditor, CustomerContactsEditor } from './PartyEditors.tsx'

const KNOWN = ['branch_id', 'code', 'type', 'name', 'phone', 'email', 'tax_code', 'group_id', 'credit_limit', 'credit_days', 'notes', 'status']

/** Thêm (`/customers/new`) hoặc sửa (`/customers/:id`) một khách hàng. Danh sách chọn tải xong mới dựng form để ô chọn có giá trị đúng ngay từ đầu. */
export default function CustomerFormPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const customer = useCustomer(id)
  const groups = useCatalogList('customer-groups')
  const branches = useAllBranches()

  if ((id !== undefined && customer.isPending) || groups.isPending || branches.isPending) {
    return <FormPageSkeleton fields={8} />
  }

  if (customer.isError) {
    const notFound = customer.error instanceof ApiError && (customer.error.status === 404 || customer.error.status === 403)

    return (
      <div className="space-y-3">
        <Alert tone="danger">{notFound ? t('crm.customers.notFound') : t('common.loadError')}</Alert>
        <Link to="/customers" className="text-sm font-medium text-brand-700 hover:underline">{t('crm.customers.back')}</Link>
      </div>
    )
  }

  return <CustomerForm key={customer.data?.id ?? 'new'} customer={customer.data} groups={groups.data ?? []} branches={branches.data ?? []} />
}

function ReadOnly({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="mb-1.5 text-sm font-medium text-slate-800">{label}</p>
      <p className="flex min-h-10 items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-base text-slate-700 md:text-sm">{value}</p>
    </div>
  )
}

function CustomerForm({ customer, groups, branches }: { customer?: Customer; groups: ListItem[]; branches: Branch[] }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = useAuth()
  const creating = customer === undefined
  const editable = creating ? can('crm.customer.create') : can('crm.customer.update')

  const save = useSaveCustomer(customer)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(() => customerSchema(t, creating), [t, creating])
  const form = useForm<CustomerFormValues>({ resolver: zodResolver(schema), defaultValues: customerDefaults(customer) })
  const errors = form.formState.errors

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
      navigate('/customers', { state: { saved: creating ? 'created' : 'updated', name: values.name.trim() } })
    } catch (error) {
      const result = applyPartyErrors(error, form.setError, KNOWN)
      if (result.leftover.length > 0) setFormError(result.leftover.join(' '))
      else if (!result.handled) setFormError(describeError(error, t))
    }
  })

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <PageHeader
        back={{ to: '/customers', label: t('crm.customers.back') }}
        title={
          <span className="flex flex-wrap items-center gap-2">
            {creating ? t('crm.customers.createTitle') : t('crm.customers.editTitle', { name: customer.name })}
            {customer && <Badge tone="info">{customer.code}</Badge>}
          </span>
        }
        description={creating ? t('crm.customers.createIntro') : t('crm.customers.editIntro')}
      />

      {!editable && <Alert tone="info">{t('crm.customers.readOnly')}</Alert>}
      {formError && <Alert tone="danger">{formError}</Alert>}

      <form onSubmit={submit} noValidate className="space-y-5">
        <fieldset disabled={!editable} className="space-y-4">
          <Card title={t('crm.form.general')}>
            <div className="grid gap-4 sm:grid-cols-2">
              <SelectField label={t('crm.form.type')} error={errors.type?.message} {...form.register('type')}>
                {CUSTOMER_TYPES.map((type) => <option key={type} value={type}>{t(`crm.types.${type}`)}</option>)}
              </SelectField>

              {creating ? (
                <TextField label={t('crm.form.code')} hint={t('crm.form.codeHint', { example: 'KH0001' })} error={errors.code?.message} autoComplete="off" {...form.register('code')} />
              ) : (
                <ReadOnly label={t('crm.form.code')} value={customer.code} />
              )}

              <TextField label={t('crm.form.name')} error={errors.name?.message} autoComplete="off" className="sm:col-span-2" {...form.register('name')} />
              <TextField label={t('crm.form.phone')} error={errors.phone?.message} inputMode="tel" autoComplete="off" {...form.register('phone')} />
              <TextField label={t('crm.form.email')} error={errors.email?.message} inputMode="email" autoComplete="off" {...form.register('email')} />
              <TextField label={t('crm.form.taxCode')} hint={t('crm.form.taxCodeHint')} error={errors.tax_code?.message} inputMode="numeric" autoComplete="off" {...form.register('tax_code')} />

              <SelectField label={t('crm.form.group')} hint={t('crm.form.groupHint')} error={errors.group_id?.message} {...form.register('group_id')}>
                <option value="">{t('crm.form.none')}</option>
                {groupOptions.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
              </SelectField>

              <SelectField label={t('crm.form.branch')} hint={creating ? t('crm.form.branchHint') : t('crm.form.branchMoveHint')} error={errors.branch_id?.message} {...form.register('branch_id')}>
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
          </Card>

          <Card title={t('crm.form.creditTitle')}>
            <p className="mb-4 text-sm text-slate-600">{t('crm.form.creditIntro')}</p>
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
          </Card>

          <Card title={t('crm.addresses.title')}>
            <p className="mb-3 text-sm text-slate-600">{t('crm.addresses.intro')}</p>
            <AddressesEditor form={form} />
          </Card>

          <Card title={t('crm.contacts.title')}>
            <CustomerContactsEditor form={form} />
          </Card>
        </fieldset>

        <FormActions>
          <Button variant="ghost" onClick={() => navigate('/customers')}>{t('common.cancel')}</Button>
          {editable && <Button type="submit" loading={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</Button>}
        </FormActions>
      </form>
    </div>
  )
}
