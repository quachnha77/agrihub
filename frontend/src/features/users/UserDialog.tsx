import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { Alert, Button, CheckboxField, Modal, Spinner, TextField } from '../../components/ui.tsx'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { isValidLogin, normalizePhone } from '../../lib/phone.ts'
import { useAllBranches } from '../branches/api.ts'
import { useRoles } from '../roles/api.ts'
import { useCreateUser, useEditUser, type UserRow } from './api.ts'

const FIELDS = ['name', 'email', 'phone', 'role_ids', 'branch_ids'] as const
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const sameSet = (a: string[], b: string[]) => a.length === b.length && a.every((id) => b.includes(id))

/**
 * Thêm hoặc sửa người dùng. Email là định danh nên chỉ nhập khi thêm; số điện thoại (tùy chọn) cũng dùng để đăng nhập.
 * Danh sách vai trò/chi nhánh chỉ hiện khi tải được (máy chủ vẫn kiểm tra quyền gán từng vai trò).
 */
export function UserDialog({ user, onClose, onCreated }: { user?: UserRow; onClose: () => void; onCreated?: (user: UserRow, temporaryPassword: string | undefined) => void }) {
  const { t } = useTranslation()
  const roles = useRoles()
  const branches = useAllBranches()
  const create = useCreateUser()
  const edit = useEditUser(user?.id ?? '')
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        name: z.string().trim().min(1, t('users.nameRequired')).max(255),
        email: user ? z.string() : z.string().trim().min(1, t('users.emailRequired')).max(255).refine((v) => EMAIL_PATTERN.test(v), t('validation.email')),
        phone: z.string().trim().max(30).refine((v) => v === '' || (!v.includes('@') && isValidLogin(v)), t('users.phoneInvalid')),
        role_ids: z.array(z.string()),
        branch_ids: z.array(z.string()),
      }),
    [t, user],
  )
  type Values = z.infer<typeof schema>

  const initialRoles = useMemo(() => user?.roles.map((r) => r.id) ?? [], [user])
  const initialBranches = useMemo(() => user?.branches.map((b) => b.id) ?? [], [user])

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '', role_ids: initialRoles, branch_ids: initialBranches },
  })
  const errors = form.formState.errors
  const selectedRoles = useWatch({ control: form.control, name: 'role_ids' })
  const selectedBranches = useWatch({ control: form.control, name: 'branch_ids' })
  const typedPhone = useWatch({ control: form.control, name: 'phone' })

  const toggle = (field: 'role_ids' | 'branch_ids', id: string, on: boolean) => {
    const current = form.getValues(field)
    form.setValue(field, on ? [...current, id] : current.filter((x) => x !== id), { shouldDirty: true })
  }

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      if (user) {
        await edit.mutateAsync({
          name: values.name,
          phone: values.phone,
          role_ids: roles.isSuccess && !sameSet(values.role_ids, initialRoles) ? values.role_ids : undefined,
          branch_ids: branches.isSuccess && !sameSet(values.branch_ids, initialBranches) ? values.branch_ids : undefined,
        })
        onClose()
      } else {
        const result = await create.mutateAsync({ name: values.name, email: values.email, phone: values.phone, role_ids: values.role_ids, branch_ids: values.branch_ids })
        onCreated?.(result.data, result.temporary_password)
        onClose()
      }
    } catch (error) {
      if (!applyServerErrors(error, form.setError, FIELDS)) setFormError(describeError(error, t))
    }
  })

  const pending = create.isPending || edit.isPending
  const normalizedPhone = typedPhone === '' ? null : normalizePhone(typedPhone)
  const phoneHint = normalizedPhone ? t('users.phoneSavedAs', { phone: normalizedPhone }) : t('users.phoneHint')

  return (
    <Modal title={user ? t('users.editTitle') : t('users.createTitle')} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert tone="danger">{formError}</Alert>}

        <TextField label={t('users.name')} error={errors.name?.message} {...form.register('name')} />
        <TextField label={t('users.email')} type="email" readOnly={Boolean(user)} hint={user ? t('users.emailLocked') : undefined} error={errors.email?.message} {...form.register('email')} />
        <TextField label={t('users.phone')} type="tel" hint={phoneHint} error={errors.phone?.message} {...form.register('phone')} />

        <fieldset>
          <legend className="mb-1 text-sm font-medium text-slate-800">{t('users.roles')}</legend>
          {roles.isPending ? (
            <Spinner className="h-4 w-4" />
          ) : roles.isError ? (
            <p className="text-xs text-slate-500">{t('users.rolesUnavailable')}</p>
          ) : (
            <div className="space-y-2">
              {roles.data.map((role) => (
                <CheckboxField key={role.id} label={role.name} description={role.description ?? undefined} checked={selectedRoles.includes(role.id)} onChange={(on) => toggle('role_ids', role.id, on)} />
              ))}
            </div>
          )}
          {errors.role_ids?.message && <p role="alert" className="mt-1 text-sm text-red-700">{errors.role_ids.message}</p>}
        </fieldset>

        <fieldset>
          <legend className="mb-1 text-sm font-medium text-slate-800">{t('users.branches')}</legend>
          {branches.isPending ? (
            <Spinner className="h-4 w-4" />
          ) : branches.isError ? (
            <p className="text-xs text-slate-500">{t('users.branchesUnavailable')}</p>
          ) : (
            <div className="space-y-2">
              {branches.data.map((branch) => (
                <CheckboxField key={branch.id} label={`${branch.code} — ${branch.name}`} checked={selectedBranches.includes(branch.id)} onChange={(on) => toggle('branch_ids', branch.id, on)} />
              ))}
            </div>
          )}
          {errors.branch_ids?.message && <p role="alert" className="mt-1 text-sm text-red-700">{errors.branch_ids.message}</p>}
        </fieldset>

        {!user && <p className="text-xs text-slate-500">{t('users.passwordNote')}</p>}

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={pending}>{pending ? t('common.saving') : t('common.save')}</Button>
        </div>
      </form>
    </Modal>
  )
}
