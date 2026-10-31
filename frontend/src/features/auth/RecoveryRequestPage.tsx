import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { z } from 'zod'
import { Alert, Button, TextField } from '../../components/ui.tsx'
import { ApiError, post } from '../../lib/api.ts'
import { describeError } from '../../lib/forms.ts'
import { isValidLogin } from '../../lib/phone.ts'
import AuthShell from './AuthShell.tsx'
import { useHostContext } from './host-context.ts'

type Mode = 'password' | 'account'

const ENDPOINT: Record<Mode, string> = { password: '/auth/forgot-password', account: '/auth/forgot-account' }

/**
 * Quên mật khẩu (nhận liên kết đặt lại) hoặc quên tài khoản (nhận lại địa chỉ đăng nhập và thông tin tài khoản).
 * Hai trang giống nhau nên dùng chung; máy chủ luôn trả lời giống nhau dù tài khoản có tồn tại hay không, nên trang cũng không đoán gì thêm.
 */
export default function RecoveryRequestPage({ mode }: { mode: Mode }) {
  const { t } = useTranslation()
  const host = useHostContext()
  const [sent, setSent] = useState<string | null>(null)
  const [failure, setFailure] = useState<string | null>(null)

  const schema = useMemo(
    () => z.object({ login: z.string().trim().min(1, t('auth.login.loginRequired')).refine(isValidLogin, t('auth.login.loginInvalid')) }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { login: '' } })

  const send = useMutation({
    mutationFn: (values: Values) => post<{ message: string }>(ENDPOINT[mode], values, { silent: true }),
    onSuccess: (response) => setSent(response.message),
    onError: (error) => {
      if (error instanceof ApiError && error.status === 429) {
        setFailure(t('auth.errors.too_many_attempts', { minutes: Math.max(1, Math.ceil((error.retryAfter ?? 60) / 60)) }))
      } else {
        setFailure(describeError(error, t))
      }
    },
  })

  const tenantName = host.data?.kind === 'tenant' ? host.data.tenant?.name : undefined
  const subtitle = tenantName ? t('auth.recovery.tenantSubtitle', { name: tenantName }) : undefined

  if (sent !== null) {
    return (
      <AuthShell title={t(`auth.recovery.${mode}.title`)} subtitle={subtitle}>
        <Alert tone="success">{sent}</Alert>
        <p className="mt-3 text-sm text-slate-600">{t('auth.recovery.sentHelp')}</p>
        <Link to="/login" className="mt-4 block text-center text-sm font-medium text-brand-700 hover:underline">
          {t('auth.recovery.backToLogin')}
        </Link>
      </AuthShell>
    )
  }

  return (
    <AuthShell title={t(`auth.recovery.${mode}.title`)} subtitle={subtitle}>
      <p className="mb-4 text-sm text-slate-600">{t(`auth.recovery.${mode}.intro`)}</p>

      {failure && <Alert tone="danger" className="mb-4">{failure}</Alert>}

      <form
        noValidate
        className="space-y-4"
        onSubmit={form.handleSubmit((values) => {
          setFailure(null)
          send.mutate(values)
        })}
      >
        <TextField
          label={t('auth.login.login')}
          type="text"
          autoComplete="username"
          error={form.formState.errors.login?.message}
          {...form.register('login')}
        />
        <Button type="submit" className="w-full" loading={send.isPending}>
          {send.isPending ? t('auth.recovery.submitting') : t('auth.recovery.submit')}
        </Button>
      </form>

      <div className="mt-4 flex items-center justify-between text-sm">
        <Link to="/login" className="font-medium text-brand-700 hover:underline">
          {t('auth.recovery.backToLogin')}
        </Link>
        <Link to={mode === 'password' ? '/forgot-account' : '/forgot-password'} className="font-medium text-brand-700 hover:underline">
          {t(mode === 'password' ? 'auth.login.forgotAccount' : 'auth.login.forgotPassword')}
        </Link>
      </div>
    </AuthShell>
  )
}
