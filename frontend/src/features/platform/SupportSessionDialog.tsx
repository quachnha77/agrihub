import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { z } from 'zod'
import { Alert, Button, Modal, TextField } from '../../components/ui.tsx'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useStartSupportSession, type TenantRow } from './api.ts'

const MIN_REASON = 10
const MIN_MINUTES = 5
const MAX_MINUTES = 120

/**
 * Mở phiên hỗ trợ: Super Admin xem dữ liệu của doanh nghiệp ở chế độ CHỈ ĐỌC, bắt buộc có lý do, tự hết hạn, được ghi nhật ký
 * và hiện trong nhật ký của chính doanh nghiệp. Mở xong thì chuyển tới trang Người dùng của doanh nghiệp đó.
 */
export function SupportSessionDialog({ tenant, onClose }: { tenant: TenantRow; onClose: () => void }) {
  const { t } = useTranslation()
  const { refresh } = useAuth()
  const navigate = useNavigate()
  const start = useStartSupportSession(tenant.id)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        reason: z.string().trim().min(MIN_REASON, t('validation.minLength', { count: MIN_REASON })).max(500),
        minutes: z.string().refine((v) => /^\d+$/.test(v) && Number(v) >= MIN_MINUTES && Number(v) <= MAX_MINUTES, t('platform.support.minutesInvalid', { min: MIN_MINUTES, max: MAX_MINUTES })),
      }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { reason: '', minutes: '30' } })
  const errors = form.formState.errors

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await start.mutateAsync({ reason: values.reason, minutes: Number(values.minutes) })
      await refresh()
      onClose()
      navigate('/users')
    } catch (error) {
      if (!applyServerErrors(error, form.setError, ['reason', 'minutes'])) setFormError(describeError(error, t))
    }
  })

  return (
    <Modal title={t('platform.support.title', { name: tenant.name })} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        <p className="text-sm text-slate-600">{t('platform.support.intro')}</p>
        {formError && <Alert tone="danger">{formError}</Alert>}
        <TextField label={t('platform.support.reason')} hint={t('platform.support.reasonHint')} error={errors.reason?.message} {...form.register('reason')} />
        <TextField label={t('platform.support.minutes')} type="number" min={MIN_MINUTES} max={MAX_MINUTES} error={errors.minutes?.message} {...form.register('minutes')} />
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={start.isPending}>{t('platform.support.submit')}</Button>
        </div>
      </form>
    </Modal>
  )
}
