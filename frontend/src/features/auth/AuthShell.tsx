import { Boxes, Contact, Smartphone, Sprout } from 'lucide-react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

/** Logo AgriHub: ô vuông xanh + chữ. */
function Brand({ light = false }: { light?: boolean }) {
  const { t } = useTranslation()

  return (
    <div className="flex items-center gap-2.5">
      <span className={`grid h-10 w-10 place-items-center rounded-xl shadow-sm ${light ? 'bg-white/15 text-white ring-1 ring-white/25' : 'bg-brand-700 text-white'}`}>
        <Sprout className="h-6 w-6" aria-hidden="true" />
      </span>
      <span className={`text-2xl font-bold tracking-tight ${light ? 'text-white' : 'text-brand-900'}`}>{t('app.name')}</span>
    </div>
  )
}

/**
 * Khung chung của các trang công khai (đăng nhập, quên mật khẩu, đặt lại mật khẩu...). Màn hình rộng: bên trái là giới thiệu ngắn
 * (nền xanh thương hiệu), bên phải là thẻ nội dung. Điện thoại: logo ở trên, thẻ nội dung ở giữa.
 */
export default function AuthShell({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  const { t } = useTranslation()
  const points = [
    { icon: Boxes, text: t('auth.shell.points.stock') },
    { icon: Contact, text: t('auth.shell.points.partners') },
    { icon: Smartphone, text: t('auth.shell.points.devices') },
  ]

  return (
    <div className="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
      <aside className="relative hidden flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-800 via-brand-900 to-brand-950 p-12 text-white lg:flex">
        <div aria-hidden="true" className="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/5" />
        <div aria-hidden="true" className="pointer-events-none absolute -bottom-32 -left-16 h-[28rem] w-[28rem] rounded-full bg-white/5" />
        <Brand light />
        <div className="relative max-w-md">
          <p className="text-3xl font-semibold leading-snug tracking-tight">{t('app.tagline')}</p>
          <ul className="mt-8 space-y-4">
            {points.map((point) => (
              <li key={point.text} className="flex items-start gap-3 text-brand-50/90">
                <span className="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/10">
                  <point.icon className="h-4 w-4" aria-hidden="true" />
                </span>
                <span className="pt-1 text-sm">{point.text}</span>
              </li>
            ))}
          </ul>
        </div>
        <p className="relative text-xs text-brand-100/60">© AgriHub</p>
      </aside>

      <main className="flex items-center justify-center bg-white px-4 py-10 sm:px-8">
        <div className="w-full max-w-sm">
          <div className="mb-8 flex justify-center lg:hidden">
            <Brand />
          </div>

          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
          {subtitle && <p className="mt-1.5 text-sm text-slate-600">{subtitle}</p>}

          <div className="mt-6">{children}</div>
        </div>
      </main>
    </div>
  )
}
