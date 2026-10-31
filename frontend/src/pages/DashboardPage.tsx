import { CircleCheck, Clock, Contact, Package, PackageMinus, PackagePlus, PhoneCall, Store, Tags, Truck, TriangleAlert, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { PageHeader } from '../components/page.tsx'
import { ListSkeleton, Skeleton, SkeletonRegion, StatCardsSkeleton } from '../components/skeleton.tsx'
import { Alert, ButtonLink, Card } from '../components/ui.tsx'
import { useAuth } from '../features/auth/auth-context.ts'
import { PlatformStatusSummary } from '../features/backup/StatusCard.tsx'
import { useProductCount } from '../features/catalog/api.ts'
import { HealthSummary } from '../features/health/HealthSummary.tsx'
import { useRecentStockDocuments, useStockSummary } from '../features/inventory/api.ts'
import { TypeBadge } from '../features/inventory/parts.tsx'
import { useLatestNotices } from '../features/notifications/api.ts'
import { formatAgo, formatToday } from '../lib/format.ts'

/** Thông báo QUAN TRỌNG chưa đọc hiện ngay khi đăng nhập (kênh "in-app" đã chốt). */
function CriticalNotices() {
  const { t } = useTranslation()
  const query = useLatestNotices({ unreadOnly: true, perPage: 20 })
  const critical = query.data?.data.filter((notice) => notice.level === 'critical') ?? []

  if (critical.length === 0) return null

  return (
    <Alert tone="danger" title={t('notifications.criticalTitle')}>
      <ul className="list-disc space-y-1 pl-5">
        {critical.map((notice) => (
          <li key={notice.id}>
            <span className="font-medium">{notice.title}</span>
            {notice.body && <span className="block whitespace-pre-line">{notice.body}</span>}
          </li>
        ))}
      </ul>
      <Link to="/notifications" className="mt-2 inline-block font-medium underline">{t('notifications.viewAll')}</Link>
    </Alert>
  )
}

interface Shortcut {
  to: string
  label: string
  icon: LucideIcon
  visible: boolean
}

/** Ô lối tắt: biểu tượng + tên việc, bấm là làm ngay. */
function ShortcutGrid({ items }: { items: Shortcut[] }) {
  return (
    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
      {items
        .filter((item) => item.visible)
        .map((item) => (
          <li key={item.to}>
            <Link to={item.to} className="flex h-full min-h-24 flex-col items-start justify-between gap-3 rounded-xl border border-slate-200/80 bg-white p-4 shadow-card transition-colors hover:border-brand-300 hover:bg-brand-50/40">
              <span className="grid h-10 w-10 place-items-center rounded-full bg-brand-50 text-brand-700">
                <item.icon className="h-5 w-5" aria-hidden="true" />
              </span>
              <span className="text-sm font-semibold text-slate-900">{item.label}</span>
            </Link>
          </li>
        ))}
    </ul>
  )
}

/** Một ô cảnh báo kho: bấm để sang danh sách tồn kho đã lọc sẵn. */
function AlertTile({ to, icon: Icon, label, value, tone }: { to: string; icon: LucideIcon; label: string; value: number; tone: 'warning' | 'danger' }) {
  const circle = value === 0 ? 'bg-slate-100 text-slate-400' : tone === 'danger' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-600'
  const color = value === 0 ? 'text-slate-900' : tone === 'danger' ? 'text-red-700' : 'text-amber-700'

  return (
    <Link to={to} className="flex min-h-[4.75rem] items-center gap-3 rounded-xl border border-slate-200/80 bg-white p-3.5 shadow-card transition-colors hover:border-slate-300">
      <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-full ${circle}`}>
        <Icon className="h-5 w-5" aria-hidden="true" />
      </span>
      <span className="min-w-0">
        <span className={`block text-2xl font-semibold leading-tight tabular-nums ${color}`}>{value}</span>
        <span className="block text-xs text-slate-600">{label}</span>
      </span>
    </Link>
  )
}

/** Cảnh báo kho (sắp hết hàng, lô sắp/đã hết hạn): chỉ cho người xem được tồn kho. */
function StockAttention() {
  const { t } = useTranslation()
  const summary = useStockSummary('')

  return (
    <section aria-labelledby="dash-attention" className="space-y-3">
      <h2 id="dash-attention" className="text-base font-semibold text-slate-900">{t('dashboard.attention')}</h2>

      {summary.isPending ? (
        <StatCardsSkeleton count={3} />
      ) : summary.isError ? (
        <Alert tone="danger">{t('common.loadError')}</Alert>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <AlertTile to="/stock?stock=low" icon={PackageMinus} label={t('inventory.summary.low')} value={summary.data.low_products} tone="warning" />
            <AlertTile to="/stock?expiry=soon" icon={Clock} label={t('inventory.summary.expiring', { days: summary.data.expiry_warning_days })} value={summary.data.expiring_batches} tone="warning" />
            <AlertTile to="/stock?expiry=expired" icon={TriangleAlert} label={t('inventory.summary.expired')} value={summary.data.expired_batches} tone="danger" />
          </div>
          {summary.data.low_products + summary.data.expiring_batches + summary.data.expired_batches === 0 && (
            <p className="flex items-center gap-2 text-sm text-slate-600">
              <CircleCheck className="h-4 w-4 text-brand-600" aria-hidden="true" />
              {t('dashboard.allClear')}
            </p>
          )}
        </>
      )}
    </section>
  )
}

/** Vài phiếu kho mới nhất. */
function RecentDocuments({ canCreate }: { canCreate: boolean }) {
  const { t } = useTranslation()
  const query = useRecentStockDocuments()
  const rows = query.data ?? []

  return (
    <Card
      title={t('dashboard.recentDocs')}
      actions={
        <Link to="/stock/documents" className="text-sm font-medium text-brand-700 hover:underline">
          {t('dashboard.viewAll')}
        </Link>
      }
    >
      {query.isPending ? (
        <ListSkeleton rows={4} subtitle />
      ) : query.isError ? (
        <Alert tone="danger">{t('common.loadError')}</Alert>
      ) : rows.length === 0 ? (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-slate-600">{t('dashboard.noRecentDocs')}</p>
          {canCreate && <ButtonLink to="/stock/new?type=receipt" size="sm">{t('inventory.types.receipt')}</ButtonLink>}
        </div>
      ) : (
        <ul className="divide-y divide-slate-100">
          {rows.map((document) => (
            <li key={document.id}>
              <Link to={`/stock/documents/${document.id}`} className="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 hover:bg-slate-50">
                <span className="min-w-0">
                  <span className="flex items-center gap-2">
                    <span className="font-mono text-xs font-semibold text-brand-700">{document.code}</span>
                    <TypeBadge type={document.type} />
                  </span>
                  <span className="mt-0.5 block truncate text-xs text-slate-500">{document.warehouse.name}</span>
                </span>
                <span className="shrink-0 text-xs text-slate-500">{formatAgo(document.performed_at)}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

/** Cửa hàng mới chưa có sản phẩm: ba việc đầu tiên, mỗi việc một nút. */
function GettingStarted({ canProduct, canStock, canCustomer }: { canProduct: boolean; canStock: boolean; canCustomer: boolean }) {
  const { t } = useTranslation()
  const steps = [
    { to: '/products/new', title: t('dashboard.start.step1'), hint: t('dashboard.start.step1Hint'), visible: canProduct },
    { to: '/stock/new?type=opening', title: t('dashboard.start.step2'), hint: t('dashboard.start.step2Hint'), visible: canStock },
    { to: '/customers/new', title: t('dashboard.start.step3'), hint: t('dashboard.start.step3Hint'), visible: canCustomer },
  ].filter((step) => step.visible)

  if (steps.length === 0) return null

  return (
    <section aria-labelledby="dash-start" className="rounded-xl border border-brand-200 bg-brand-50/60 p-5">
      <h2 id="dash-start" className="text-base font-semibold text-brand-900">{t('dashboard.start.title')}</h2>
      <p className="mt-0.5 text-sm text-brand-900/80">{t('dashboard.start.intro')}</p>
      <ol className="mt-4 grid gap-3 sm:grid-cols-3">
        {steps.map((step, index) => (
          <li key={step.to}>
            <Link to={step.to} className="flex h-full items-start gap-3 rounded-lg bg-white p-3.5 shadow-card ring-1 ring-brand-100 transition-colors hover:ring-brand-300">
              <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-700 text-sm font-semibold text-white">{index + 1}</span>
              <span>
                <span className="block text-sm font-semibold text-slate-900">{step.title}</span>
                <span className="block text-xs text-slate-600">{step.hint}</span>
              </span>
            </Link>
          </li>
        ))}
      </ol>
    </section>
  )
}

/**
 * Khung giữ chỗ của cả trang Tổng quan, cùng bố cục với trang thật. Khối "Bắt đầu với AgriHub" chỉ có khi cửa hàng chưa có sản phẩm,
 * nên trang chờ biết số sản phẩm rồi mới hiện một lượt: nếu hiện khối đó muộn nó sẽ bật ra ở đầu trang và đẩy mọi thứ xuống.
 */
function DashboardSkeleton({ stock, quick }: { stock: boolean; quick: boolean }) {
  return (
    <SkeletonRegion className="space-y-6">
      {stock && (
        <div className="space-y-3">
          <Skeleton className="h-6 w-28" />
          <StatCardsSkeleton count={3} />
        </div>
      )}
      {quick && (
        <div className="space-y-3">
          <Skeleton className="h-6 w-24" />
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {Array.from({ length: 5 }, (_, tile) => (
              <Skeleton key={tile} className="h-24 rounded-xl" />
            ))}
          </div>
        </div>
      )}
      {stock && (
        <Card title={<Skeleton className="h-5 w-40" />}>
          <ListSkeleton rows={4} subtitle />
        </Card>
      )}
    </SkeletonRegion>
  )
}

/** Tổng quan của người dùng doanh nghiệp: việc cần chú ý, lối tắt làm ngay, hoạt động gần đây. Mỗi khối chỉ hiện khi có quyền. */
function TenantDashboard() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const canStockView = can('inventory.movement.view')
  const canMove = can('inventory.movement.create')
  const canProduct = can('catalog.product.create')
  const canCustomer = can('crm.customer.create')
  const canSupplier = can('purchase.supplier.create')
  const products = useProductCount(can('catalog.product.view'))

  const shortcuts: Shortcut[] = [
    { to: '/stock/new?type=receipt', label: t('inventory.types.receipt'), icon: PackagePlus, visible: canMove },
    { to: '/stock/new?type=issue', label: t('inventory.types.issue'), icon: PackageMinus, visible: canMove },
    { to: '/products/new', label: t('catalog.add'), icon: Package, visible: canProduct },
    { to: '/customers/new', label: t('crm.customers.add'), icon: Contact, visible: canCustomer },
    { to: '/suppliers/new', label: t('crm.suppliers.add'), icon: Truck, visible: canSupplier },
  ]
  const hasShortcuts = shortcuts.some((item) => item.visible)
  const nothing = !canStockView && !hasShortcuts

  if (products.isLoading) return <DashboardSkeleton stock={canStockView} quick={hasShortcuts} />

  return (
    <>
      {products.data === 0 && <GettingStarted canProduct={canProduct} canStock={canMove} canCustomer={canCustomer} />}

      {canStockView && <StockAttention />}

      {hasShortcuts && (
        <section aria-labelledby="dash-quick" className="space-y-3">
          <h2 id="dash-quick" className="text-base font-semibold text-slate-900">{t('dashboard.quick')}</h2>
          <ShortcutGrid items={shortcuts} />
        </section>
      )}

      {canStockView && <RecentDocuments canCreate={canMove} />}

      {nothing && (
        <Card>
          <p className="text-sm text-slate-600">{t('dashboard.noAccess')}</p>
        </Card>
      )}
    </>
  )
}

/** Tổng quan của quản trị nền tảng: lối tắt, sức khỏe hệ thống và tình trạng sao lưu. */
function PlatformDashboard() {
  const { t } = useTranslation()
  const links: Shortcut[] = [
    { to: '/platform/tenants', label: t('nav.tenants'), icon: Store, visible: true },
    { to: '/platform/renewals', label: t('nav.renewals'), icon: PhoneCall, visible: true },
    { to: '/platform/plans', label: t('nav.plans'), icon: Tags, visible: true },
  ]

  return (
    <>
      <section aria-labelledby="dash-platform" className="space-y-3">
        <h2 id="dash-platform" className="text-base font-semibold text-slate-900">{t('dashboard.platformLinks')}</h2>
        <ShortcutGrid items={links} />
      </section>
      <div className="grid gap-4 lg:grid-cols-2">
        <HealthSummary />
        <PlatformStatusSummary />
      </div>
    </>
  )
}

export default function DashboardPage() {
  const { t } = useTranslation()
  const { user } = useAuth()

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('dashboard.welcome', { name: user?.name })}
        description={user?.tenant ? t('dashboard.tenantLine', { tenant: user.tenant.name, date: formatToday() }) : t('dashboard.platformLine')}
      />

      <CriticalNotices />

      {user?.is_platform_admin ? <PlatformDashboard /> : <TenantDashboard />}
    </div>
  )
}
