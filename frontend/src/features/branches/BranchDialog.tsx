import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { Alert, Button, Modal, TextField } from '../../components/ui.tsx'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { useSaveBranch, type Branch } from './api.ts'

const FIELDS = ['code', 'name', 'phone', 'address'] as const

/** Thêm hoặc sửa chi nhánh. Trạng thái (hoạt động / ngừng) chỉ có khi sửa. */
export function BranchDialog({ branch, onClose }: { branch?: Branch; onClose: () => void }) {
  const { t } = useTranslation()
  const save = useSaveBranch(branch)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        code: z.string().trim().min(1, t('branches.codeRequired')).max(30).regex(/^[A-Za-z0-9._-]+$/, t('branches.codeFormat')),
        name: z.string().trim().min(1, t('branches.nameRequired')).max(255),
        phone: z.string().trim().max(30),
        address: z.string().trim().max(255),
        status: z.enum(['active', 'inactive']),
      }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { code: branch?.code ?? '', name: branch?.name ?? '', phone: branch?.phone ?? '', address: branch?.address ?? '', status: branch?.status ?? 'active' },
  })
  const errors = form.formState.errors

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await save.mutateAsync(branch ? values : { ...values, status: undefined })
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, form.setError, FIELDS)) setFormError(describeError(error, t))
    }
  })

  return (
    <Modal title={branch ? t('branches.editTitle') : t('branches.createTitle')} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert tone="danger">{formError}</Alert>}
        <TextField label={t('branches.code')} hint={t('branches.codeHint')} error={errors.code?.message} {...form.register('code')} />
        <TextField label={t('branches.name')} error={errors.name?.message} {...form.register('name')} />
        <TextField label={t('branches.phone')} type="tel" error={errors.phone?.message} {...form.register('phone')} />
        <TextField label={t('branches.address')} error={errors.address?.message} {...form.register('address')} />

        {branch && (
          <div>
            <label htmlFor="branch-status" className="mb-1 block text-sm font-medium text-slate-800">{t('branches.status')}</label>
            <select id="branch-status" className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm" {...form.register('status')}>
              <option value="active">{t('common.active')}</option>
              <option value="inactive">{t('common.inactive')}</option>
            </select>
          </div>
        )}

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</Button>
        </div>
      </form>
    </Modal>
  )
}
