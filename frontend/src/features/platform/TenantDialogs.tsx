import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { Alert, Button, Modal, TextField } from '../../components/ui.tsx'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { isValidLogin } from '../../lib/phone.ts'
import { useCreateTenant, useUpdateTenant, type TenantRow } from './api.ts'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const TENANT_PHONE = /^[0-9+\-\s().]{6,30}$/
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/
const TAX_CODE = /^\d{10}(-\d{3})?$/

const CREATE_FIELDS = ['tenant.name', 'tenant.slug', 'tenant.phone', 'tenant.address', 'tenant.tax_code', 'owner.name', 'owner.email', 'owner.phone', 'owner.password'] as const
const EDIT_FIELDS = ['name', 'phone', 'address', 'tax_code'] as const

const blank = (value: string) => (value.trim() === '' ? undefined : value.trim())

/**
 * Onboarding một doanh nghiệp mới: doanh nghiệp + Chủ doanh nghiệp (+ chi nhánh đầu tiên và gói dùng thử do hệ thống tạo).
 * Slug chính là subdomain nên không đổi được sau khi tạo.
 */
export function CreateTenantDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (tenant: TenantRow, temporaryPassword: string | undefined) => void }) {
  const { t } = useTranslation()
  const create = useCreateTenant()
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        tenant: z.object({
          name: z.string().trim().min(1, t('platform.tenants.nameRequired')).max(255),
          slug: z.string().trim().max(50).refine((v) => v === '' || SLUG.test(v), t('platform.tenants.slugInvalid')),
          phone: z.string().trim().refine((v) => v === '' || TENANT_PHONE.test(v), t('platform.tenants.phoneInvalid')),
          address: z.string().trim().max(255),
          tax_code: z.string().trim().refine((v) => v === '' || TAX_CODE.test(v), t('platform.tenants.taxInvalid')),
        }),
        owner: z.object({
          name: z.string().trim().min(1, t('users.nameRequired')).max(255),
          email: z.string().trim().min(1, t('users.emailRequired')).max(255).refine((v) => EMAIL_PATTERN.test(v), t('validation.email')),
          phone: z.string().trim().max(30).refine((v) => v === '' || (!v.includes('@') && isValidLogin(v)), t('users.phoneInvalid')),
          password: z.string().refine((v) => v === '' || (v.length >= 10 && /[A-Za-z]/.test(v) && /\d/.test(v)), t('platform.tenants.passwordInvalid')),
        }),
      }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { tenant: { name: '', slug: '', phone: '', address: '', tax_code: '' }, owner: { name: '', email: '', phone: '', password: '' } },
  })
  const errors = form.formState.errors

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      const result = await create.mutateAsync({
        tenant: { name: values.tenant.name, slug: blank(values.tenant.slug), phone: blank(values.tenant.phone), address: blank(values.tenant.address), tax_code: blank(values.tenant.tax_code) },
        owner: { name: values.owner.name, email: values.owner.email, phone: blank(values.owner.phone), password: blank(values.owner.password) },
      })
      onCreated(result.data, result.temporary_password)
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, form.setError, CREATE_FIELDS)) setFormError(describeError(error, t))
    }
  })

  return (
    <Modal title={t('platform.tenants.createTitle')} onClose={onClose} size="lg">
      <form onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert tone="danger">{formError}</Alert>}

        <fieldset className="space-y-3">
          <legend className="mb-1 text-sm font-semibold text-slate-900">{t('platform.tenants.business')}</legend>
          <TextField label={t('platform.tenants.name')} error={errors.tenant?.name?.message} {...form.register('tenant.name')} />
          <TextField label={t('platform.tenants.slug')} hint={t('platform.tenants.slugHint')} error={errors.tenant?.slug?.message} {...form.register('tenant.slug')} />
          <div className="grid gap-3 sm:grid-cols-2">
            <TextField label={t('platform.tenants.phone')} type="tel" error={errors.tenant?.phone?.message} {...form.register('tenant.phone')} />
            <TextField label={t('platform.tenants.taxCode')} error={errors.tenant?.tax_code?.message} {...form.register('tenant.tax_code')} />
          </div>
          <TextField label={t('platform.tenants.address')} error={errors.tenant?.address?.message} {...form.register('tenant.address')} />
        </fieldset>

        <fieldset className="space-y-3">
          <legend className="mb-1 text-sm font-semibold text-slate-900">{t('platform.tenants.owner')}</legend>
          <TextField label={t('users.name')} error={errors.owner?.name?.message} {...form.register('owner.name')} />
          <div className="grid gap-3 sm:grid-cols-2">
            <TextField label={t('users.email')} type="email" error={errors.owner?.email?.message} {...form.register('owner.email')} />
            <TextField label={t('users.phone')} type="tel" error={errors.owner?.phone?.message} {...form.register('owner.phone')} />
          </div>
          <TextField label={t('platform.tenants.ownerPassword')} type="password" autoComplete="new-password" hint={t('platform.tenants.ownerPasswordHint')} error={errors.owner?.password?.message} {...form.register('owner.password')} />
        </fieldset>

        <p className="text-xs text-slate-500">{t('platform.tenants.createNote')}</p>

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={create.isPending}>{create.isPending ? t('common.saving') : t('platform.tenants.createSubmit')}</Button>
        </div>
      </form>
    </Modal>
  )
}

/** Sửa thông tin liên hệ của doanh nghiệp. Slug (địa chỉ đăng nhập) và trạng thái không sửa ở đây. */
export function EditTenantDialog({ tenant, onClose }: { tenant: TenantRow; onClose: () => void }) {
  const { t } = useTranslation()
  const update = useUpdateTenant(tenant.id)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        name: z.string().trim().min(1, t('platform.tenants.nameRequired')).max(255),
        phone: z.string().trim().refine((v) => v === '' || TENANT_PHONE.test(v), t('platform.tenants.phoneInvalid')),
        address: z.string().trim().max(255),
        tax_code: z.string().trim().refine((v) => v === '' || TAX_CODE.test(v), t('platform.tenants.taxInvalid')),
      }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: tenant.name, phone: tenant.phone ?? '', address: tenant.address ?? '', tax_code: tenant.tax_code ?? '' },
  })
  const errors = form.formState.errors

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await update.mutateAsync({ name: values.name, phone: blank(values.phone) ?? null, address: blank(values.address) ?? null, tax_code: blank(values.tax_code) ?? null })
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, form.setError, EDIT_FIELDS)) setFormError(describeError(error, t))
    }
  })

  return (
    <Modal title={t('platform.tenants.editTitle')} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert tone="danger">{formError}</Alert>}
        <TextField label={t('platform.tenants.name')} error={errors.name?.message} {...form.register('name')} />
        <TextField label={t('platform.tenants.phone')} type="tel" error={errors.phone?.message} {...form.register('phone')} />
        <TextField label={t('platform.tenants.taxCode')} error={errors.tax_code?.message} {...form.register('tax_code')} />
        <TextField label={t('platform.tenants.address')} error={errors.address?.message} {...form.register('address')} />
        <p className="text-xs text-slate-500">{t('platform.tenants.slugLocked', { slug: tenant.slug })}</p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={update.isPending}>{update.isPending ? t('common.saving') : t('common.save')}</Button>
        </div>
      </form>
    </Modal>
  )
}
