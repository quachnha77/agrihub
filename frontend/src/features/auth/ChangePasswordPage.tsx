import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { z } from 'zod'
import { Alert, Button, TextField } from '../../components/ui.tsx'
import { post } from '../../lib/api.ts'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { useAuth } from './auth-context.ts'
import type { Envelope, Me } from './types.ts'

const FIELDS = ['current_password', 'password', 'password_confirmation'] as const

export default function ChangePasswordPage() {
  const { t } = useTranslation()
  const auth = useAuth()
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z
        .object({
          current_password: z.string().min(1),
          password: z.string().min(12, t('validation.minLength', { count: 12 })).refine((v) => /[A-Za-z]/.test(v) && /\d/.test(v), t('auth.changePassword.hint')),
          password_confirmation: z.string().min(1),
        })
        .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: t('validation.passwordMismatch') }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { current_password: '', password: '', password_confirmation: '' } })
  const errors = form.formState.errors

  const change = useMutation({
    mutationFn: (values: Values) => post<Envelope<Me>>('/auth/password', values),
    onSuccess: ({ data }) => {
      auth.signIn(data)
      navigate('/', { replace: true })
    },
    onError: (error) => {
      if (!applyServerErrors(error, form.setError, FIELDS)) setFormError(describeError(error, t))
    },
  })

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h1 className="text-lg font-semibold text-slate-900">{t('auth.changePassword.title')}</h1>

        {auth.user?.must_change_password && <Alert tone="warning" className="my-4">{t('auth.changePassword.forced')}</Alert>}
        {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}

        <form
          noValidate
          className="mt-4 space-y-4"
          onSubmit={form.handleSubmit((values) => {
            setFormError(null)
            change.mutate(values)
          })}
        >
          <TextField label={t('auth.changePassword.current')} type="password" autoComplete="current-password" error={errors.current_password?.message} {...form.register('current_password')} />
          <TextField label={t('auth.changePassword.next')} type="password" autoComplete="new-password" hint={t('auth.changePassword.hint')} error={errors.password?.message} {...form.register('password')} />
          <TextField label={t('auth.changePassword.confirm')} type="password" autoComplete="new-password" error={errors.password_confirmation?.message} {...form.register('password_confirmation')} />
          <Button type="submit" className="w-full" loading={change.isPending}>
            {change.isPending ? t('auth.changePassword.submitting') : t('auth.changePassword.submit')}
          </Button>
        </form>
      </div>
    </main>
  )
}
