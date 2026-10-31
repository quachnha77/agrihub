import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import { ApiError, api, onSessionProblem, postForm } from './api.ts'
import { mockApi, problem } from '../test/utils.tsx'

describe('api client', () => {
  it('lấy cookie CSRF trước khi ghi và gửi lại trong header X-XSRF-TOKEN', async () => {
    const server = mockApi({ 'POST /api/v1/ping': { json: { ok: true } } })

    // Lần 1: chưa có cookie => xin cookie rồi mới gửi.
    await api('POST', '/ping', { a: 1 })
    expect(server.to('GET', '/sanctum/csrf-cookie')).toHaveLength(1)

    // Lần 2: đã có cookie (giá trị được mã hóa URL như Laravel gửi) => gửi lại đã giải mã, không xin lại.
    document.cookie = 'XSRF-TOKEN=abc%3D%3D; path=/'
    const fetchMock = vi.mocked(fetch)
    await api('POST', '/ping')

    expect(server.to('GET', '/sanctum/csrf-cookie')).toHaveLength(1)
    const init = fetchMock.mock.calls.at(-1)?.[1] as RequestInit
    expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc==')
    expect(init.credentials).toBe('include')
  })

  it('gửi file bằng multipart: không tự đặt Content-Type JSON và không mã hóa JSON phần thân', async () => {
    const server = mockApi({ 'POST /api/v1/upload': { status: 201, json: { data: 1 } } })
    const form = new FormData()
    form.append('file', new File(['abc'], 'a.agbk'))

    await expect(postForm('/upload', form)).resolves.toEqual({ data: 1 })

    const sent = vi.mocked(fetch).mock.calls.at(-1)?.[1] as RequestInit
    expect(sent.body).toBe(form)
    expect((sent.headers as Record<string, string>)['Content-Type']).toBeUndefined()
    expect((server.to('POST', '/api/v1/upload')[0]?.body as { file?: File } | undefined)?.file?.name).toBe('a.agbk')
  })

  it('không xin CSRF cho yêu cầu đọc (GET)', async () => {
    const server = mockApi({ 'GET /api/v1/x': { json: { data: 1 } } })

    await expect(api('GET', '/x')).resolves.toEqual({ data: 1 })
    expect(server.to('GET', '/sanctum/csrf-cookie')).toHaveLength(0)
  })

  it('đổi lỗi RFC 7807 thành ApiError có mã, thông báo và lỗi từng trường', async () => {
    mockApi({
      'POST /api/v1/a': problem(422, 'validation_failed', 'Dữ liệu không hợp lệ.', { errors: { email: ['Email là bắt buộc.'] } }),
      'POST /api/v1/b': problem(429, 'too_many_attempts', 'Quá nhiều lần thử.', { retry_after: 900 }),
    })

    const validation = await api('POST', '/a').catch((e: unknown) => e)
    expect(validation).toBeInstanceOf(ApiError)
    expect(validation).toMatchObject({ status: 422, code: 'validation_failed', errors: { email: ['Email là bắt buộc.'] } })

    await expect(api('POST', '/b')).rejects.toMatchObject({ status: 429, code: 'too_many_attempts', retryAfter: 900 })
  })

  it('xử lý mất mạng và trang lỗi không phải JSON', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    await expect(api('GET', '/x')).rejects.toMatchObject({ status: 0, code: 'network_error' })

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>Bad gateway</html>', { status: 502 })))
    await expect(api('GET', '/x')).rejects.toMatchObject({ status: 502, code: 'server_error' })
  })

  it('phát sự kiện khi phiên không còn dùng được, trừ khi yêu cầu ở chế độ im lặng', async () => {
    mockApi({
      'GET /api/v1/a': problem(402, 'subscription_expired', 'Gói dịch vụ đã hết hạn.'),
      'GET /api/v1/b': problem(404, 'not_found', 'Không tìm thấy.'),
    })
    const listener = vi.fn()
    const off = onSessionProblem(listener)

    await api('GET', '/b').catch(() => undefined)
    expect(listener).not.toHaveBeenCalled()

    await api('GET', '/a', undefined, { silent: true }).catch(() => undefined)
    expect(listener).not.toHaveBeenCalled()

    await api('GET', '/a').catch(() => undefined)
    expect(listener).toHaveBeenCalledWith('subscription_expired')

    off()
  })
})

describe('thông báo kiểm tra dữ liệu tiếng Việt (zod)', () => {
  const messages = (schema: z.ZodType, value: unknown) => {
    const result = schema.safeParse(value)

    return result.success ? [] : result.error.issues.map((issue) => issue.message)
  }

  it('dùng tiếng Việt cho mọi schema mà không cần ghi đè từng trường', () => {
    expect(messages(z.string(), undefined)).toEqual(['Trường này là bắt buộc.'])
    expect(messages(z.string().min(1), '')).toEqual(['Trường này là bắt buộc.'])
    expect(messages(z.string().min(12), 'ngan')).toEqual(['Phải có ít nhất 12 ký tự.'])
    expect(messages(z.string().max(5), 'qua dai roi')).toEqual(['Không được dài quá 5 ký tự.'])
    expect(messages(z.email(), 'khong-phai-email')).toEqual(['Email không hợp lệ.'])
    expect(messages(z.number().min(3), 1)).toEqual(['Giá trị phải từ 3 trở lên.'])
    expect(messages(z.number(), 'abc')).toEqual(['Giá trị không hợp lệ.'])
  })

  it('thông báo riêng của từng trường vẫn được ưu tiên', () => {
    expect(messages(z.string().min(1, 'Vui lòng nhập email.'), '')).toEqual(['Vui lòng nhập email.'])
  })
})
