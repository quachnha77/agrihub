import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

/** Khung chung của các trang công khai (quên mật khẩu, đặt lại mật khẩu...): tên ứng dụng ở trên, thẻ nội dung ở giữa. */
export default function AuthShell({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  const { t } = useTranslation()

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <h1 className="text-3xl font-bold text-emerald-800">{t('app.name')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('app.tagline')}</p>
        </div>

        <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
          {subtitle && <p className="mb-4 text-sm text-slate-600">{subtitle}</p>}
          {children}
        </div>
      </div>
    </main>
  )
}
