import type { RouteObject } from 'react-router'
import { AppShellSkeleton } from '../components/skeleton.tsx'
import ChangePasswordPage from '../features/auth/ChangePasswordPage.tsx'
import { RequireAccess, RequireAuth } from '../features/auth/guards.tsx'
import LoginPage from '../features/auth/LoginPage.tsx'
import RecoveryRequestPage from '../features/auth/RecoveryRequestPage.tsx'
import ResetPasswordPage from '../features/auth/ResetPasswordPage.tsx'
import AppLayout from '../layout/AppLayout.tsx'
import DashboardPage from '../pages/DashboardPage.tsx'
import NotFoundPage from '../pages/NotFoundPage.tsx'
import AuthRoot from './AuthRoot.tsx'

/**
 * Cây route của ứng dụng. Chặn theo quyền ở đây chỉ để dẫn đường người dùng; quyền thật do API kiểm tra.
 * Module nghiệp vụ mới thêm route của mình dưới <AppLayout> với <RequireAccess permission="..."> và tải theo nhu cầu (lazy)
 * để bản đầu tiên người dùng tải về luôn nhỏ.
 * Mở thẳng một đường dẫn lazy thì trình duyệt phải tải xong phần mã của trang đó trước; `HydrateFallback` giữ khung ứng dụng
 * trong lúc chờ, thay cho trang trắng.
 */
export const routes: RouteObject[] = [
  {
    element: <AuthRoot />,
    HydrateFallback: AppShellSkeleton,
    children: [
      { path: '/login', element: <LoginPage /> },
      { path: '/forgot-password', element: <RecoveryRequestPage mode="password" /> },
      { path: '/forgot-account', element: <RecoveryRequestPage mode="account" /> },
      { path: '/reset-password', element: <ResetPasswordPage /> },
      {
        element: <RequireAuth />,
        children: [
          { path: '/change-password', element: <ChangePasswordPage /> },
          {
            element: <AppLayout />,
            children: [
              { index: true, element: <DashboardPage /> },
              { path: 'notifications', lazy: async () => ({ Component: (await import('../features/notifications/NotificationsPage.tsx')).default }) },
              {
                element: <RequireAccess permission="tenant.user.view" />,
                children: [{ path: 'users', lazy: async () => ({ Component: (await import('../features/users/UsersPage.tsx')).default }) }],
              },
              {
                element: <RequireAccess permission="catalog.product.view" />,
                children: [
                  { path: 'products', lazy: async () => ({ Component: (await import('../features/catalog/ProductsPage.tsx')).default }) },
                  { path: 'products/lists', lazy: async () => ({ Component: (await import('../features/catalog/CatalogListsPage.tsx')).default }) },
                ],
              },
              {
                element: <RequireAccess permission="crm.customer.view" />,
                children: [
                  { path: 'customers', lazy: async () => ({ Component: (await import('../features/crm/CustomersPage.tsx')).default }) },
                  { path: 'customers/groups', lazy: async () => ({ Component: (await import('../features/crm/CustomerGroupsPage.tsx')).default }) },
                ],
              },
              {
                element: <RequireAccess permission="purchase.supplier.view" />,
                children: [{ path: 'suppliers', lazy: async () => ({ Component: (await import('../features/crm/SuppliersPage.tsx')).default }) }],
              },
              {
                element: <RequireAccess permission="inventory.movement.view" />,
                children: [
                  { path: 'stock', lazy: async () => ({ Component: (await import('../features/inventory/StockPage.tsx')).default }) },
                  { path: 'stock/documents', lazy: async () => ({ Component: (await import('../features/inventory/StockDocumentsPage.tsx')).default }) },
                  { path: 'stock/stocktakes', lazy: async () => ({ Component: (await import('../features/inventory/StocktakesPage.tsx')).default }) },
                ],
              },
              {
                element: <RequireAccess permission="inventory.warehouse.view" />,
                children: [{ path: 'warehouses', lazy: async () => ({ Component: (await import('../features/inventory/WarehousesPage.tsx')).default }) }],
              },
              {
                element: <RequireAccess permission="tenant.branch.view" />,
                children: [{ path: 'branches', lazy: async () => ({ Component: (await import('../features/branches/BranchesPage.tsx')).default }) }],
              },
              {
                element: <RequireAccess permission="tenant.role.view" />,
                children: [{ path: 'roles', lazy: async () => ({ Component: (await import('../features/roles/RolesPage.tsx')).default }) }],
              },
              {
                element: <RequireAccess platform />,
                children: [
                  { path: 'platform/tenants', lazy: async () => ({ Component: (await import('../features/platform/TenantsPage.tsx')).default }) },
                  { path: 'platform/tenants/:id', lazy: async () => ({ Component: (await import('../features/platform/TenantDetailPage.tsx')).default }) },
                  { path: 'platform/tenants/:id/backups', lazy: async () => ({ Component: (await import('../features/backup/TenantBackupsAdminPage.tsx')).default }) },
                  { path: 'platform/renewals', lazy: async () => ({ Component: (await import('../features/platform/RenewalsPage.tsx')).default }) },
                  { path: 'platform/plans', lazy: async () => ({ Component: (await import('../features/platform/PlansPage.tsx')).default }) },
                  { path: 'platform/backups', lazy: async () => ({ Component: (await import('../features/backup/PlatformBackupsPage.tsx')).default }) },
                  { path: 'platform/health', lazy: async () => ({ Component: (await import('../features/health/HealthPage.tsx')).default }) },
                ],
              },
              { path: '*', element: <NotFoundPage /> },
            ],
          },
        ],
      },
    ],
  },
]
