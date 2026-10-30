export type PermissionScope = 'own' | 'branch' | 'tenant'

export interface SubscriptionNotice {
  state: 'trial' | 'active' | 'grace' | 'reopened' | 'expired'
  is_trial: boolean
  days_left: number
  period_end: string
  blocked: boolean
  reopened_until: string | null
}

export interface SupportSession {
  id: string
  tenant: { id: string; name: string }
  reason: string
  started_at: string
  expires_at: string
  read_only: boolean
}

/** Hồ sơ người đăng nhập (GET /auth/me) kèm bản đồ quyền hiệu lực. */
export interface Me {
  id: string
  name: string
  email: string
  is_platform_admin: boolean
  tenant: { id: string; name: string; slug: string } | null
  roles?: { id: string; key: string; name: string }[]
  /** resource.action -> phạm vi rộng nhất; rỗng với Platform Admin ngoài phiên hỗ trợ. */
  permissions: Record<string, PermissionScope>
  must_change_password: boolean
  support_session?: SupportSession | null
  subscription_notice?: SubscriptionNotice | null
}

export interface Envelope<T> {
  data: T
}
