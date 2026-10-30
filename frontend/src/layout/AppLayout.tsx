import { DatabaseBackup, LayoutDashboard, LogOut, Menu, ServerCog, X, type LucideIcon } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { NavLink, Outlet, useNavigate } from 'react-router'
import { useAuth } from '../features/auth/auth-context.ts'
import { NotificationBell } from '../features/notifications/NotificationBell.tsx'
import { SubscriptionBanner, SupportSessionBanner } from '../features/subscription/Banners.tsx'

interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  visible: boolean
  end?: boolean
}

export default function AppLayout() {
  const { t } = useTranslation()
  const { user, can, signOut } = useAuth()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)

  const items: NavItem[] = [
    { to: '/', label: t('nav.dashboard'), icon: LayoutDashboard, visible: true, end: true },
    { to: '/backups', label: t('nav.backups'), icon: DatabaseBackup, visible: can('backup.backup.view') },
    { to: '/platform/backups', label: t('nav.platformBackups'), icon: ServerCog, visible: user?.is_platform_admin === true },
  ]

  async function logout() {
    await signOut()
    navigate('/login', { replace: true })
  }

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">
        {t('common.skipToContent')}
      </a>

      <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4">
        <button
          type="button"
          className="rounded p-2 text-slate-700 hover:bg-slate-100 md:hidden"
          onClick={() => setMenuOpen((value) => !value)}
          aria-label={menuOpen ? t('nav.closeMenu') : t('nav.openMenu')}
          aria-expanded={menuOpen}
        >
          {menuOpen ? <X className="h-5 w-5" aria-hidden="true" /> : <Menu className="h-5 w-5" aria-hidden="true" />}
        </button>

        <span className="text-lg font-bold text-emerald-800">{t('app.name')}</span>
        {user?.tenant && <span className="hidden truncate text-sm text-slate-500 sm:inline">· {user.tenant.name}</span>}

        <div className="ml-auto flex items-center gap-1">
          <NotificationBell />
          <span className="hidden max-w-40 truncate px-2 text-sm text-slate-700 sm:inline" title={user?.email}>
            {user?.name}
          </span>
          <button type="button" onClick={logout} className="flex items-center gap-1 rounded px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-100">
            <LogOut className="h-4 w-4" aria-hidden="true" />
            <span>{t('auth.logout')}</span>
          </button>
        </div>
      </header>

      <div className="flex">
        <nav
          aria-label={t('nav.main')}
          className={`${menuOpen ? 'block' : 'hidden'} fixed inset-x-0 top-14 z-20 border-b border-slate-200 bg-white p-2 md:static md:block md:min-h-[calc(100vh-3.5rem)] md:w-56 md:shrink-0 md:border-b-0 md:border-r`}
        >
          <ul className="space-y-1">
            {items
              .filter((item) => item.visible)
              .map((item) => (
                <li key={item.to}>
                  <NavLink
                    to={item.to}
                    end={item.end}
                    onClick={() => setMenuOpen(false)}
                    className={({ isActive }) =>
                      `flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium ${isActive ? 'bg-emerald-50 text-emerald-800' : 'text-slate-700 hover:bg-slate-100'}`
                    }
                  >
                    <item.icon className="h-4 w-4" aria-hidden="true" />
                    {item.label}
                  </NavLink>
                </li>
              ))}
          </ul>
        </nav>

        <main id="main" className="min-w-0 flex-1 space-y-4 p-4 md:p-6">
          <SupportSessionBanner />
          <SubscriptionBanner />
          <Outlet />
        </main>
      </div>
    </div>
  )
}
