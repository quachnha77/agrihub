import { useTranslation } from 'react-i18next'
import { Link, Navigate, Outlet, useLocation } from 'react-router'
import { AppShellSkeleton } from '../../components/skeleton.tsx'
import { useAuth } from './auth-context.ts'

/** Chỉ cho người đã đăng nhập vào; tài khoản đang dùng mật khẩu tạm bị đưa tới trang đổi mật khẩu. */
export function RequireAuth() {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) return <AppShellSkeleton />

  if (!user) return <Navigate to="/login" replace state={{ from: location }} />

  if (user.must_change_password && location.pathname !== '/change-password') return <Navigate to="/change-password" replace />

  return <Outlet />
}

/**
 * Chặn theo quyền (hoặc theo vai trò quản trị nền tảng). Giao diện chỉ để dẫn đường: việc chặn thật nằm ở API
 * (Policy + RLS), nên ẩn menu/trang không thay thế được kiểm tra phía máy chủ.
 */
export function RequireAccess({ permission, platform = false }: { permission?: string; platform?: boolean }) {
  const { user, can } = useAuth()

  const allowed = platform ? user?.is_platform_admin === true : permission !== undefined && can(permission)

  return allowed ? <Outlet /> : <ForbiddenPage />
}

export function ForbiddenPage() {
  const { t } = useTranslation()

  return (
    <div role="alert" className="mx-auto max-w-md py-16 text-center">
      <h1 className="text-xl font-semibold text-slate-900">{t('errors.forbiddenTitle')}</h1>
      <p className="mt-2 text-sm text-slate-600">{t('errors.forbiddenText')}</p>
      <Link to="/" className="mt-4 inline-block text-sm font-medium text-emerald-700 hover:underline">
        {t('errors.backHome')}
      </Link>
    </div>
  )
}
