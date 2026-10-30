import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import { z } from 'zod'
import vi from './vi.ts'

void i18n.use(initReactI18next).init({
  lng: 'vi',
  fallbackLng: 'vi',
  resources: { vi: { translation: vi } },
  interpolation: { escapeValue: false }, // React đã tự thoát ký tự
  initAsync: false, // tài nguyên nằm sẵn trong bundle nên nạp đồng bộ: t() dùng được ngay
})

interface RawIssue {
  code: string
  input?: unknown
  origin?: string
  minimum?: number | bigint
  maximum?: number | bigint
  format?: string
}

/**
 * Thông báo kiểm tra dữ liệu bằng tiếng Việt cho MỌI schema zod (thay vì tiếng Anh mặc định).
 * Schema vẫn có thể ghi đè bằng thông báo riêng theo từng trường.
 */
z.config({
  customError: (issue) => {
    const iss = issue as unknown as RawIssue
    const t = i18n.t.bind(i18n)

    switch (iss.code) {
      case 'invalid_type':
        return iss.input === undefined || iss.input === null ? t('validation.required') : t('validation.invalidType')
      case 'too_small':
        if (iss.origin === 'string') {
          return Number(iss.minimum) <= 1 ? t('validation.required') : t('validation.minLength', { count: Number(iss.minimum) })
        }
        return t('validation.minValue', { min: String(iss.minimum) })
      case 'too_big':
        if (iss.origin === 'string') return t('validation.maxLength', { count: Number(iss.maximum) })
        return t('validation.maxValue', { max: String(iss.maximum) })
      case 'invalid_format':
        return iss.format === 'email' ? t('validation.email') : t('validation.format')
      default:
        return undefined
    }
  },
})

export default i18n
