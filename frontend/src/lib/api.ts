/**
 * Client gọi API Laravel (/api/v1) bằng cookie phiên của Sanctum.
 *
 * - Mọi lỗi trả về dạng RFC 7807 được đổi thành ApiError (có `code` ổn định để giao diện chọn thông báo).
 * - Yêu cầu ghi tự lấy cookie CSRF trước (/sanctum/csrf-cookie) và gửi lại trong header X-XSRF-TOKEN.
 * - Khi phiên không còn dùng được (hết phiên, hết hạn gói, doanh nghiệp bị khóa...) phát sự kiện để AuthProvider đưa
 *   người dùng về trang đăng nhập kèm lý do.
 */

export class ApiError extends Error {
  status: number
  code: string
  /** Lỗi theo từng trường (HTTP 422): tên trường -> danh sách thông báo. */
  errors: Record<string, string[]>
  /** Số giây nên chờ trước khi thử lại (HTTP 429). */
  retryAfter: number | null
  /** Toàn bộ nội dung lỗi của máy chủ (vd. `login_url` khi đăng nhập sai địa chỉ). */
  extra: Record<string, unknown>

  constructor(status: number, code: string, message: string, errors: Record<string, string[]> = {}, retryAfter: number | null = null, extra: Record<string, unknown> = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.errors = errors
    this.retryAfter = retryAfter
    this.extra = extra
  }
}

/** Các mã lỗi nghĩa là phiên hiện tại không còn dùng được nữa. */
export const SESSION_PROBLEM_CODES = ['unauthenticated', 'subscription_expired', 'tenant_suspended', 'account_disabled'] as const
export type SessionProblemCode = (typeof SESSION_PROBLEM_CODES)[number]

export function isSessionProblem(code: string): code is SessionProblemCode {
  return (SESSION_PROBLEM_CODES as readonly string[]).includes(code)
}

type SessionListener = (code: SessionProblemCode) => void
const listeners = new Set<SessionListener>()

export function onSessionProblem(listener: SessionListener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

function readXsrf(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : null
}

async function ensureCsrf(): Promise<void> {
  if (readXsrf()) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'include', headers: { Accept: 'application/json' } })
}

export interface RequestOptions {
  signal?: AbortSignal
  /** Không phát sự kiện "phiên không còn dùng được" (đăng nhập, lấy hồ sơ lúc khởi động). */
  silent?: boolean
}

export async function api<T = unknown>(method: string, path: string, body?: unknown, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }

  if (method !== 'GET') {
    await ensureCsrf()
    const token = readXsrf()
    if (token) headers['X-XSRF-TOKEN'] = token
  }

  // FormData (tải file lên): để trình duyệt tự đặt Content-Type kèm ranh giới multipart.
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json'

  let response: Response
  try {
    response = await fetch(`/api/v1${path}`, {
      method,
      headers,
      credentials: 'include',
      body: body === undefined ? undefined : isForm ? (body as FormData) : JSON.stringify(body),
      signal: options.signal,
    })
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === 'AbortError') throw cause
    throw new ApiError(0, 'network_error', 'Không kết nối được máy chủ.')
  }

  const text = await response.text()
  let payload: unknown = null

  if (text !== '') {
    try {
      payload = JSON.parse(text)
    } catch {
      payload = null // trang lỗi HTML của proxy/máy chủ
    }
  }

  if (!response.ok) {
    const problem = (payload ?? {}) as { code?: string; title?: string; detail?: string; errors?: Record<string, string[]>; retry_after?: number }
    const error = new ApiError(
      response.status,
      problem.code ?? (response.status >= 500 ? 'server_error' : 'unknown_error'),
      problem.detail ?? problem.title ?? 'Có lỗi xảy ra.',
      problem.errors ?? {},
      problem.retry_after ?? (Number(response.headers.get('Retry-After')) || null),
      (payload ?? {}) as Record<string, unknown>,
    )

    if (!options.silent && isSessionProblem(error.code)) {
      listeners.forEach((listener) => listener(error.code as SessionProblemCode))
    }

    throw error
  }

  return payload as T
}

export const get = <T>(path: string, options?: RequestOptions) => api<T>('GET', path, undefined, options)
export const post = <T>(path: string, body?: unknown, options?: RequestOptions) => api<T>('POST', path, body ?? {}, options)
export const del = <T>(path: string, options?: RequestOptions) => api<T>('DELETE', path, undefined, options)
/** Gửi form có file (multipart). */
export const postForm = <T>(path: string, form: FormData, options?: RequestOptions) => api<T>('POST', path, form, options)
