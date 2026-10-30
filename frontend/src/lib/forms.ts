import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import type { TFunction } from 'i18next'
import { ApiError } from './api.ts'

/**
 * Đưa lỗi theo từng trường mà API trả về (HTTP 422) vào form. Trả về `true` nếu có lỗi trường được gán.
 * Thông báo do máy chủ viết bằng tiếng Việt nên hiển thị nguyên văn.
 */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>, known: readonly string[]): boolean {
  if (!(error instanceof ApiError) || error.status !== 422) return false

  let applied = false

  for (const [field, messages] of Object.entries(error.errors)) {
    if (known.includes(field) && messages[0]) {
      setError(field as Path<T>, { type: 'server', message: messages[0] })
      applied = true
    }
  }

  return applied
}

/** Thông báo chung cho lỗi không gắn với trường nào. */
export function describeError(error: unknown, t: TFunction): string {
  if (error instanceof ApiError) {
    if (error.code === 'network_error') return t('common.networkError')
    if (error.status >= 400 && error.status < 500 && error.message) return error.message
  }

  return t('common.genericError')
}
