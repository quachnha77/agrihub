import { Outlet } from 'react-router'
import { AuthProvider } from '../features/auth/AuthProvider.tsx'

/** Gốc của mọi route: cung cấp trạng thái đăng nhập cho cả cây giao diện. */
export default function AuthRoot() {
  return (
    <AuthProvider>
      <Outlet />
    </AuthProvider>
  )
}
