import { Plus, Trash2 } from 'lucide-react'
import type { ReactNode } from 'react'
import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Button, CheckboxField, TextField } from '../../components/ui.tsx'
import { emptyAddress, emptyContact, emptySupplierContact, type CustomerFormValues, type SupplierFormValues } from './forms.ts'

const MAX_ROWS = 10

/** Khung một dòng của danh sách (địa chỉ, người liên hệ): có tiêu đề "Địa chỉ 1" để đọc màn hình và để phân biệt các dòng cùng tên ô. */
function Row({ title, remove, removeLabel, children }: { title: string; remove: () => void; removeLabel: string; children: ReactNode }) {
  return (
    <fieldset className="rounded-md border border-slate-200 p-3">
      <legend className="px-1 text-sm font-medium text-slate-700">{title}</legend>
      <div className="grid gap-3 sm:grid-cols-2">{children}</div>
      <div className="mt-2 flex justify-end">
        <Button variant="ghost" onClick={remove} aria-label={removeLabel}>
          <Trash2 className="h-4 w-4 text-red-700" aria-hidden="true" />
          {removeLabel}
        </Button>
      </div>
    </fieldset>
  )
}

/** Địa chỉ giao hàng / ruộng vườn của khách. Tối đa một địa chỉ mặc định; chưa chọn thì địa chỉ đầu tiên là mặc định. */
export function AddressesEditor({ form }: { form: UseFormReturn<CustomerFormValues> }) {
  const { t } = useTranslation()
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'addresses' })
  const rows = useWatch({ control: form.control, name: 'addresses' })
  const errors = form.formState.errors

  function makeDefault(index: number, checked: boolean) {
    rows.forEach((_, i) => form.setValue(`addresses.${i}.is_default`, i === index ? checked : false, { shouldDirty: true }))
  }

  return (
    <div className="space-y-3">
      {fields.length === 0 && <p className="text-sm text-slate-500">{t('crm.addresses.empty')}</p>}

      {fields.map((field, index) => {
        const rowErrors = errors.addresses?.[index]

        return (
          <Row key={field.id} title={t('crm.addresses.row', { n: index + 1 })} remove={() => remove(index)} removeLabel={t('crm.addresses.remove', { n: index + 1 })}>
            <TextField label={t('crm.addresses.label')} hint={t('crm.addresses.labelHint')} error={rowErrors?.label?.message} autoComplete="off" {...form.register(`addresses.${index}.label`)} />
            <TextField label={t('crm.addresses.address')} error={rowErrors?.address?.message} autoComplete="off" {...form.register(`addresses.${index}.address`)} />
            <TextField label={t('crm.addresses.ward')} error={rowErrors?.ward?.message} autoComplete="off" {...form.register(`addresses.${index}.ward`)} />
            <TextField label={t('crm.addresses.district')} error={rowErrors?.district?.message} autoComplete="off" {...form.register(`addresses.${index}.district`)} />
            <TextField label={t('crm.addresses.province')} error={rowErrors?.province?.message} autoComplete="off" {...form.register(`addresses.${index}.province`)} />
            <div className="flex items-end pb-2">
              <CheckboxField label={t('crm.addresses.default')} checked={rows?.[index]?.is_default ?? false} onChange={(checked) => makeDefault(index, checked)} />
            </div>
          </Row>
        )
      })}

      {typeof errors.addresses?.message === 'string' && <p role="alert" className="text-sm text-red-700">{errors.addresses.message}</p>}
      {typeof errors.addresses?.root?.message === 'string' && <p role="alert" className="text-sm text-red-700">{errors.addresses.root.message}</p>}

      <Button variant="secondary" onClick={() => append(emptyAddress(fields.length === 0))} disabled={fields.length >= MAX_ROWS}>
        <Plus className="h-4 w-4" aria-hidden="true" />
        {t('crm.addresses.add')}
      </Button>
      {fields.length >= MAX_ROWS && <p className="text-xs text-slate-500">{t('crm.form.tooMany', { max: MAX_ROWS })}</p>}
    </div>
  )
}

/** Người liên hệ của khách (kế toán, người nhận hàng...). */
export function CustomerContactsEditor({ form }: { form: UseFormReturn<CustomerFormValues> }) {
  const { t } = useTranslation()
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'contacts' })
  const errors = form.formState.errors

  return (
    <div className="space-y-3">
      {fields.length === 0 && <p className="text-sm text-slate-500">{t('crm.contacts.empty')}</p>}

      {fields.map((field, index) => {
        const rowErrors = errors.contacts?.[index]

        return (
          <Row key={field.id} title={t('crm.contacts.row', { n: index + 1 })} remove={() => remove(index)} removeLabel={t('crm.contacts.remove', { n: index + 1 })}>
            <TextField label={t('crm.contacts.name')} error={rowErrors?.name?.message} autoComplete="off" {...form.register(`contacts.${index}.name`)} />
            <TextField label={t('crm.contacts.phone')} error={rowErrors?.phone?.message} inputMode="tel" autoComplete="off" {...form.register(`contacts.${index}.phone`)} />
            <TextField label={t('crm.contacts.position')} error={rowErrors?.position?.message} autoComplete="off" {...form.register(`contacts.${index}.position`)} />
            <TextField label={t('crm.contacts.note')} error={rowErrors?.note?.message} autoComplete="off" {...form.register(`contacts.${index}.note`)} />
          </Row>
        )
      })}

      {typeof errors.contacts?.message === 'string' && <p role="alert" className="text-sm text-red-700">{errors.contacts.message}</p>}

      <Button variant="secondary" onClick={() => append(emptyContact())} disabled={fields.length >= MAX_ROWS}>
        <Plus className="h-4 w-4" aria-hidden="true" />
        {t('crm.contacts.add')}
      </Button>
      {fields.length >= MAX_ROWS && <p className="text-xs text-slate-500">{t('crm.form.tooMany', { max: MAX_ROWS })}</p>}
    </div>
  )
}

/** Người liên hệ của nhà cung cấp (có thêm email, không có ghi chú). */
export function SupplierContactsEditor({ form }: { form: UseFormReturn<SupplierFormValues> }) {
  const { t } = useTranslation()
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'contacts' })
  const errors = form.formState.errors

  return (
    <div className="space-y-3">
      {fields.length === 0 && <p className="text-sm text-slate-500">{t('crm.contacts.empty')}</p>}

      {fields.map((field, index) => {
        const rowErrors = errors.contacts?.[index]

        return (
          <Row key={field.id} title={t('crm.contacts.row', { n: index + 1 })} remove={() => remove(index)} removeLabel={t('crm.contacts.remove', { n: index + 1 })}>
            <TextField label={t('crm.contacts.name')} error={rowErrors?.name?.message} autoComplete="off" {...form.register(`contacts.${index}.name`)} />
            <TextField label={t('crm.contacts.phone')} error={rowErrors?.phone?.message} inputMode="tel" autoComplete="off" {...form.register(`contacts.${index}.phone`)} />
            <TextField label={t('crm.contacts.email')} error={rowErrors?.email?.message} inputMode="email" autoComplete="off" {...form.register(`contacts.${index}.email`)} />
            <TextField label={t('crm.contacts.position')} error={rowErrors?.position?.message} autoComplete="off" {...form.register(`contacts.${index}.position`)} />
          </Row>
        )
      })}

      {typeof errors.contacts?.message === 'string' && <p role="alert" className="text-sm text-red-700">{errors.contacts.message}</p>}

      <Button variant="secondary" onClick={() => append(emptySupplierContact())} disabled={fields.length >= MAX_ROWS}>
        <Plus className="h-4 w-4" aria-hidden="true" />
        {t('crm.contacts.add')}
      </Button>
      {fields.length >= MAX_ROWS && <p className="text-xs text-slate-500">{t('crm.form.tooMany', { max: MAX_ROWS })}</p>}
    </div>
  )
}
