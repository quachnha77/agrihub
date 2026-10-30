import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { vi } from 'vitest'
import { routes } from '../app/routes.tsx'
import type { Me } from '../features/auth/types.ts'

export interface ApiCall {
  method: string
  path: string
  body: unknown
}

export interface MockResponse {
  status?: number
  json?: unknown
  headers?: Record<string, string>
}

type Handler = MockResponse | ((call: ApiCall) => MockResponse)

/** Các lần gọi tới đường dẫn chưa giả lập: api() nuốt lỗi thành "mất mạng" nên phải kiểm tra riêng sau mỗi test (xem setup.ts). */
const unmocked: string[] = []

export function takeUnmockedCalls(): string[] {
  return unmocked.splice(0, unmocked.length)
}

/** RFC 7807 giống hệt phản hồi lỗi của API Laravel. */
export const problem = (status: number, code: string, title: string, extra: Record<string, unknown> = {}): MockResponse => ({
  status,
  json: { type: `https://agrihub.local/problems/${code}`, title, status, detail: title, code, request_id: null, ...extra },
})

/**
 * Thay `fetch` bằng bộ giả lập theo "METHOD /đường-dẫn" (có hoặc không kèm query). Gọi tới đường dẫn chưa giả lập sẽ làm
 * test thất bại rõ ràng thay vì âm thầm trả về dữ liệu rỗng.
 */
export function mockApi(handlers: Record<string, Handler>) {
  const all: Record<string, Handler> = {
    'GET /sanctum/csrf-cookie': { status: 204 },
    'GET /api/v1/tenant-context': { json: { data: { kind: 'unrestricted', tenant: null } } },
    'GET /api/v1/notifications': { json: { data: [], unread_count: 0, meta: { current_page: 1, last_page: 1, total: 0 } } },
    ...handlers,
  }
  const calls: ApiCall[] = []

  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = new URL(typeof input === 'string' ? input : input instanceof URL ? input.href : input.url, 'http://localhost')
      const method = (init?.method ?? 'GET').toUpperCase()
      const call: ApiCall = { method, path: `${url.pathname}${url.search}`, body: typeof init?.body === 'string' ? JSON.parse(init.body) : undefined }
      calls.push(call)

      const handler = all[`${method} ${call.path}`] ?? all[`${method} ${url.pathname}`]
      if (!handler) {
        unmocked.push(`${method} ${call.path}`)
        throw new Error(`Chưa giả lập: ${method} ${call.path}`)
      }

      const result = typeof handler === 'function' ? handler(call) : handler

      return new Response(result.json === undefined ? null : JSON.stringify(result.json), {
        status: result.status ?? 200,
        headers: { 'Content-Type': 'application/json', ...result.headers },
      })
    }),
  )

  return {
    calls,
    /** Các lần gọi tới đường dẫn (không tính query) bằng phương thức đã cho. */
    to: (method: string, pathname: string) => calls.filter((call) => call.method === method && call.path.split('?')[0] === pathname),
  }
}

export function renderApp(path = '/') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const router = createMemoryRouter(routes, { initialEntries: [path] })

  render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )

  return { router, queryClient }
}

export const tenantUser = (overrides: Partial<Me> = {}): Me => ({
  id: 'user-1',
  name: 'Nguyễn Văn A',
  email: 'a@nongphat.vn',
  is_platform_admin: false,
  tenant: { id: 'tenant-1', name: 'Cửa hàng Nông Phát', slug: 'nong-phat' },
  permissions: { 'backup.backup.view': 'tenant', 'backup.backup.create': 'tenant', 'backup.backup.export': 'tenant' },
  must_change_password: false,
  subscription_notice: null,
  ...overrides,
})

export const platformAdmin = (overrides: Partial<Me> = {}): Me => ({
  id: 'admin-1',
  name: 'Quản trị AgriHub',
  email: 'root@agrihub.vn',
  is_platform_admin: true,
  tenant: null,
  permissions: {},
  must_change_password: false,
  support_session: null,
  ...overrides,
})

export const meOk = (user: Me): MockResponse => ({ json: { data: user } })
export const notSignedIn = (): MockResponse => problem(401, 'unauthenticated', 'Chưa đăng nhập hoặc phiên đã hết hạn.')
