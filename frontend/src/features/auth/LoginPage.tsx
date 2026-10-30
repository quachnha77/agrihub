import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Navigate, useLocation } from 'react-router'
import { z } from 'zod'
import { Alert, Button, TextField } from '../../components/ui.tsx'
import { ApiError, post } from '../../lib/api.ts'
import { useAuth } from './auth-context.ts'
import type { Envelope, Me } from './types.ts'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** Chọn thông báo dễ hiểu cho từng mã lỗi đăng nhập. */
function loginErrorMessage(error: unknown, t: (key: string, options?: Record<string, unknown>) => string): string {
  if (!(error instanceof ApiError)) return t('common.genericError')

  if (error.code === 'network_error') return t('common.networkError')

  if (error.code === 'too_many_attempts') {
    return t('auth.errors.too_many_attempts', { minutes: Math.max(1, Math.ceil((error.retryAfter ?? 60) / 60)) })
  }

  const known = ['invalid_credentials', 'account_disabled', 'tenant_suspended', 'subscription_expired', 'stateful_request_required']

  return known.includes(error.code) ? t(`auth.errors.${error.code}`) : t('common.genericError')
}

export default function LoginPage() {
  const { t } = useTranslation()
  const auth = useAuth()
  const location = useLocation()
  const from = (location.state as { from?: { pathname: string; search?: string } } | null)?.from
  const target = from ? `${from.pathname}${from.search ?? ''}` : '/'
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        email: z.string().trim().min(1, t('auth.login.emailRequired')).refine((value) => EMAIL_PATTERN.test(value), t('validation.email')),
        password: z.string().min(1, t('auth.login.passwordRequired')),
      }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: '', password: '' } })

  const login = useMutation({
    mutationFn: (values: Values) => post<Envelope<Me>>('/auth/login', values, { silent: true }),
    // Có hồ sơ là bên dưới tự chuyển tới `target` (một nơi duy nhất quyết định đích đến).
    onSuccess: ({ data }) => auth.signIn(data),
    onError: (error) => setFormError(loginErrorMessage(error, t)),
  })

  if (!auth.isLoading && auth.user) return <Navigate to={target} replace />

  const notice = auth.sessionNotice

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <h1 className="text-3xl font-bold text-emerald-800">{t('app.name')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('app.tagline')}</p>
        </div>

        <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="text-lg font-semibold text-slate-900">{t('auth.login.title')}</h2>
          <p className="mb-4 text-sm text-slate-600">{t('auth.login.subtitle')}</p>

          {notice && <Alert tone="warning" className="mb-4">{t(`auth.sessionNotice.${notice}`)}</Alert>}
          {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}

          <form
            noValidate
            className="space-y-4"
            onSubmit={form.handleSubmit((values) => {
              setFormError(null)
              login.mutate(values)
            })}
          >
            <TextField
              label={t('auth.login.email')}
              type="email"
              autoComplete="username"
              inputMode="email"
              error={form.formState.errors.email?.message}
              {...form.register('email')}
            />
            <TextField
              label={t('auth.login.password')}
              type="password"
              autoComplete="current-password"
              error={form.formState.errors.password?.message}
              {...form.register('password')}
            />
            <Button type="submit" className="w-full" loading={login.isPending}>
              {login.isPending ? t('auth.login.submitting') : t('auth.login.submit')}
            </Button>
          </form>
        </div>
      </div>
    </main>
  )
}
