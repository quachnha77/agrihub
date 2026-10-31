import type { RouteObject } from 'react-router'
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
 */
export const routes: RouteObject[] = [
  {
    element: <AuthRoot />,
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
                  { path: 'products/:id', lazy: async () => ({ Component: (await import('../features/catalog/ProductFormPage.tsx')).default }) },
                ],
              },
              {
                element: <RequireAccess permission="catalog.product.create" />,
                children: [{ path: 'products/new', lazy: async () => ({ Component: (await import('../features/catalog/ProductFormPage.tsx')).default }) }],
              },
              {
                element: <RequireAccess permission="crm.customer.view" />,
                children: [
                  { path: 'customers', lazy: async () => ({ Component: (await import('../features/crm/CustomersPage.tsx')).default }) },
                  { path: 'customers/groups', lazy: async () => ({ Component: (await import('../features/crm/CustomerGroupsPage.tsx')).default }) },
                  { path: 'customers/:id', lazy: async () => ({ Component: (await import('../features/crm/CustomerFormPage.tsx')).default }) },
                ],
              },
              {
                element: <RequireAccess permission="crm.customer.create" />,
                children: [{ path: 'customers/new', lazy: async () => ({ Component: (await import('../features/crm/CustomerFormPage.tsx')).default }) }],
              },
              {
                element: <RequireAccess permission="purchase.supplier.view" />,
                children: [
                  { path: 'suppliers', lazy: async () => ({ Component: (await import('../features/crm/SuppliersPage.tsx')).default }) },
                  { path: 'suppliers/:id', lazy: async () => ({ Component: (await import('../features/crm/SupplierFormPage.tsx')).default }) },
                ],
              },
              {
                element: <RequireAccess permission="purchase.supplier.create" />,
                children: [{ path: 'suppliers/new', lazy: async () => ({ Component: (await import('../features/crm/SupplierFormPage.tsx')).default }) }],
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
