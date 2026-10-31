import { Activity, Building2, ChevronDown, Contact, KeyRound, LayoutDashboard, LogOut, Menu as MenuIcon, Package, PhoneCall, ServerCog, ShieldCheck, Sprout, Store, Tags, Truck, Users, Warehouse, X, type LucideIcon } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, Outlet, useLocation, useNavigate, useNavigation } from 'react-router'
import { DialogHost } from '../app/DialogHost.tsx'
import { Menu, MenuItem } from '../components/menu.tsx'
import { ToastProvider } from '../components/toast.tsx'
import { Avatar } from '../components/ui.tsx'
import { useAuth } from '../features/auth/auth-context.ts'
import { NotificationBell } from '../features/notifications/NotificationBell.tsx'
import { SubscriptionBanner, SupportSessionBanner } from '../features/subscription/Banners.tsx'

interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  visible: boolean
  /** Đường dẫn con cũng làm mục này sáng (vd. mục "Kho hàng" sáng ở cả /stock, /stock/documents và /warehouses). */
  match?: string[]
}

interface NavGroup {
  label?: string
  items: NavItem[]
}

const isWithin = (pathname: string, prefix: string): boolean => (prefix === '/' ? pathname === '/' : pathname === prefix || pathname.startsWith(`${prefix}/`))

export default function AppLayout() {
  const { t } = useTranslation()
  const { user, can, signOut } = useAuth()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  // Sang trang chưa tải mã (lazy): giữ trang cũ nguyên vẹn và báo đang chuyển bằng thanh mảnh dưới thanh trên, không để trang đứng im.
  const navigating = useNavigation().state === 'loading'
  // Ngăn kéo menu (điện thoại) nhớ đường dẫn lúc mở: đổi trang thì tự coi như đã đóng, không cần effect.
  const [openedAt, setOpenedAt] = useState<string | null>(null)
  const menuOpen = openedAt === pathname
  const setMenuOpen = (open: boolean) => setOpenedAt(open ? pathname : null)
  const platform = user?.is_platform_admin === true

  const canStock = can('inventory.movement.view')
  const canWarehouses = can('inventory.warehouse.view')
  const canSuppliers = can('purchase.supplier.view')
  const canPurchases = can('purchase.order.view')

  // Mục chính ≤ 8 (Kho hàng gom Tồn kho, Phiếu kho, Danh sách kho thành tab trong trang); mục quản trị tách thành nhóm riêng.
  const groups: NavGroup[] = [
    {
      items: [
        { to: '/', label: t('nav.dashboard'), icon: LayoutDashboard, visible: true },
        { to: '/products', label: t('nav.products'), icon: Package, visible: can('catalog.product.view') },
        { to: canStock ? '/stock' : '/warehouses', label: t('nav.stockModule'), icon: Warehouse, visible: canStock || canWarehouses, match: ['/stock', '/warehouses'] },
        { to: '/customers', label: t('nav.customers'), icon: Contact, visible: can('crm.customer.view') },
        { to: canSuppliers ? '/suppliers' : '/purchases', label: t('nav.suppliers'), icon: Truck, visible: canSuppliers || canPurchases, match: ['/suppliers', '/purchases'] },
        { to: '/platform/tenants', label: t('nav.tenants'), icon: Store, visible: platform },
        { to: '/platform/renewals', label: t('nav.renewals'), icon: PhoneCall, visible: platform },
        { to: '/platform/plans', label: t('nav.plans'), icon: Tags, visible: platform },
      ],
    },
    {
      label: t('nav.groups.manage'),
      items: [
        { to: '/users', label: t('nav.users'), icon: Users, visible: can('tenant.user.view') },
        { to: '/branches', label: t('nav.branches'), icon: Building2, visible: can('tenant.branch.view') },
        { to: '/roles', label: t('nav.roles'), icon: ShieldCheck, visible: can('tenant.role.view') },
      ],
    },
    {
      label: t('nav.groups.system'),
      items: [
        { to: '/platform/health', label: t('nav.health'), icon: Activity, visible: platform },
        { to: '/platform/backups', label: t('nav.platformBackups'), icon: ServerCog, visible: platform },
      ],
    },
  ]

  async function logout() {
    await signOut()
    navigate('/login', { replace: true })
  }

  return (
    <ToastProvider>
      <div className="min-h-screen text-slate-900">
        <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">
          {t('common.skipToContent')}
        </a>

        <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-slate-200 bg-white/95 px-3 backdrop-blur md:px-5">
          <button
            type="button"
            className="inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-100 md:hidden"
            onClick={() => setMenuOpen(!menuOpen)}
            aria-label={menuOpen ? t('nav.closeMenu') : t('nav.openMenu')}
            aria-expanded={menuOpen}
          >
            {menuOpen ? <X className="h-5 w-5" aria-hidden="true" /> : <MenuIcon className="h-5 w-5" aria-hidden="true" />}
          </button>

          <Link to="/" className="flex items-center gap-2 rounded-lg px-1 py-1">
            <span className="grid h-8 w-8 place-items-center rounded-lg bg-brand-700 text-white shadow-sm">
              <Sprout className="h-5 w-5" aria-hidden="true" />
            </span>
            <span className="text-lg font-bold tracking-tight text-brand-900">{t('app.name')}</span>
          </Link>
          {user?.tenant && <span className="ml-1 hidden max-w-60 truncate rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700 sm:inline">{user.tenant.name}</span>}

          <div className="ml-auto flex items-center gap-1">
            <NotificationBell />
            {user && (
              <Menu
                label={t('nav.account')}
                triggerClassName="flex items-center gap-2 rounded-lg py-1 pl-1 pr-1 hover:bg-slate-100 md:pr-2"
                trigger={
                  <>
                    <Avatar name={user.name} />
                    <span className="hidden max-w-40 truncate text-sm font-medium text-slate-800 md:inline">{user.name}</span>
                    <ChevronDown className="hidden h-4 w-4 text-slate-500 md:block" aria-hidden="true" />
                  </>
                }
              >
                {(close) => (
                  <>
                    <div className="px-3 py-2">
                      <p className="truncate text-sm font-semibold text-slate-900">{user.name}</p>
                      <p className="truncate text-xs text-slate-500">{user.email}</p>
                    </div>
                    <div role="separator" className="my-1 h-px bg-slate-100" />
                    <MenuItem icon={KeyRound} to="/change-password" onSelect={close}>
                      {t('nav.changePassword')}
                    </MenuItem>
                    <MenuItem
                      icon={LogOut}
                      onSelect={() => {
                        close()
                        void logout()
                      }}
                    >
                      {t('auth.logout')}
                    </MenuItem>
                  </>
                )}
              </Menu>
            )}
          </div>

          {navigating && <div aria-hidden="true" className="absolute inset-x-0 bottom-0 h-0.5 bg-brand-600 motion-safe:animate-pulse" />}
        </header>

        <div className="flex">
          {menuOpen && <div aria-hidden="true" className="fixed inset-0 top-14 z-30 bg-slate-900/40 motion-safe:animate-fade-in md:hidden" onClick={() => setMenuOpen(false)} />}

          <nav
            aria-label={t('nav.main')}
            className={`fixed inset-y-0 left-0 top-14 z-30 w-72 overflow-y-auto border-r border-slate-200 bg-white p-3 shadow-pop motion-safe:transition-transform md:sticky md:top-14 md:h-[calc(100vh-3.5rem)] md:w-60 md:shrink-0 md:translate-x-0 md:shadow-none ${
              menuOpen ? 'translate-x-0' : 'max-md:invisible max-md:-translate-x-full'
            }`}
          >
            {groups
              .map((group) => ({ ...group, items: group.items.filter((item) => item.visible) }))
              .filter((group) => group.items.length > 0)
              .map((group, index) => (
                <div key={group.label ?? index} className={index > 0 ? 'mt-5' : ''}>
                  {group.label && <p className="mb-1 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">{group.label}</p>}
                  <ul className="space-y-0.5">
                    {group.items.map((item) => {
                      const active = (item.match ?? [item.to]).some((prefix) => isWithin(pathname, prefix))

                      return (
                        <li key={item.to}>
                          <Link
                            to={item.to}
                            aria-current={active ? 'page' : undefined}
                            className={`flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                              active ? 'bg-brand-50 text-brand-800' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900'
                            }`}
                          >
                            <item.icon className={`h-5 w-5 shrink-0 ${active ? 'text-brand-700' : 'text-slate-400'}`} aria-hidden="true" />
                            {item.label}
                          </Link>
                        </li>
                      )
                    })}
                  </ul>
                </div>
              ))}
          </nav>

          <main id="main" className="min-w-0 flex-1 px-4 py-5 md:px-6 md:py-7">
            <div className="mx-auto w-full max-w-7xl space-y-6">
              <SupportSessionBanner />
              <SubscriptionBanner />
              <Outlet />
            </div>
          </main>
        </div>

        <DialogHost />
      </div>
    </ToastProvider>
  )
}
