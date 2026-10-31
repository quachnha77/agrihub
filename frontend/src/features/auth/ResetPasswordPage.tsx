import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { z } from 'zod'
import { Alert, Button, TextField } from '../../components/ui.tsx'
import { ApiError, post } from '../../lib/api.ts'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import AuthShell from './AuthShell.tsx'

const FIELDS = ['password', 'password_confirmation'] as const

/**
 * Đặt mật khẩu mới từ liên kết trong email (/reset-password?token=...&email=...).
 * Đọc mã và email rồi xóa chúng khỏi thanh địa chỉ ngay: không nằm lại trong lịch sử trình duyệt hay bị gửi đi qua Referer.
 */
export default function ResetPasswordPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [link] = useState(() => ({ token: params.get('token') ?? '', email: params.get('email') ?? '' }))
  const [done, setDone] = useState(false)
  const [formError, setFormError] = useState<{ invalidLink: boolean; message: string } | null>(null)

  useEffect(() => {
    if (params.has('token') || params.has('email')) setParams({}, { replace: true })
  }, [params, setParams])

  const schema = useMemo(
    () =>
      z
        .object({
          password: z.string().min(12, t('validation.minLength', { count: 12 })).refine((v) => /[A-Za-z]/.test(v) && /\d/.test(v), t('auth.changePassword.hint')),
          password_confirmation: z.string().min(1, t('auth.reset.confirmRequired')),
        })
        .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: t('validation.passwordMismatch') }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { password: '', password_confirmation: '' } })
  const errors = form.formState.errors

  const reset = useMutation({
    mutationFn: (values: Values) => post('/auth/reset-password', { ...values, token: link.token, email: link.email }, { silent: true }),
    onSuccess: () => setDone(true),
    onError: (error) => {
      if (applyServerErrors(error, form.setError, FIELDS)) return
      setFormError({ invalidLink: error instanceof ApiError && error.code === 'invalid_reset_token', message: describeError(error, t) })
    },
  })

  if (done) {
    return (
      <AuthShell title={t('auth.reset.doneTitle')}>
        <Alert tone="success">{t('auth.reset.doneText')}</Alert>
        <Button className="mt-4 w-full" onClick={() => navigate('/login', { replace: true })}>
          {t('auth.reset.goLogin')}
        </Button>
      </AuthShell>
    )
  }

  if (link.token === '' || link.email === '' || formError?.invalidLink) {
    return (
      <AuthShell title={t('auth.reset.invalidTitle')}>
        <Alert tone="warning">{t('auth.reset.invalidText')}</Alert>
        <Link to="/forgot-password" className="mt-4 block text-center text-sm font-medium text-brand-700 hover:underline">
          {t('auth.reset.requestNew')}
        </Link>
      </AuthShell>
    )
  }

  return (
    <AuthShell title={t('auth.reset.title')} subtitle={t('auth.reset.forAccount', { email: link.email })}>
      {formError && <Alert tone="danger" className="mb-4">{formError.message}</Alert>}

      <form
        noValidate
        className="space-y-4"
        onSubmit={form.handleSubmit((values) => {
          setFormError(null)
          reset.mutate(values)
        })}
      >
        <TextField label={t('auth.changePassword.next')} type="password" autoComplete="new-password" hint={t('auth.changePassword.hint')} error={errors.password?.message} {...form.register('password')} />
        <TextField label={t('auth.changePassword.confirm')} type="password" autoComplete="new-password" error={errors.password_confirmation?.message} {...form.register('password_confirmation')} />
        <Button type="submit" className="w-full" loading={reset.isPending}>
          {reset.isPending ? t('auth.reset.submitting') : t('auth.reset.submit')}
        </Button>
      </form>
    </AuthShell>
  )
}
