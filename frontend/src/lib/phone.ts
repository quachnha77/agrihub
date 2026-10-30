/**
 * Số điện thoại Việt Nam, cùng quy tắc với máy chủ (App\\Support\\Auth\\Phone): bỏ khoảng trắng, dấu chấm, gạch ngang, ngoặc;
 * đổi +84 / 84 thành 0; hợp lệ khi là 10–11 chữ số bắt đầu bằng 0 và chữ số thứ hai từ 2 đến 9.
 * Chỉ để báo lỗi sớm trên trình duyệt; máy chủ luôn kiểm tra lại.
 */
export function normalizePhone(raw: string): string | null {
  let digits = raw.trim().replace(/[\s.\-()]/g, '')

  if (!/^\+?\d+$/.test(digits)) return null

  if (digits.startsWith('+84')) digits = `0${digits.slice(3)}`
  else if (digits.startsWith('84') && digits.length >= 11) digits = `0${digits.slice(2)}`

  return /^0[2-9]\d{8,9}$/.test(digits) ? digits : null
}

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** Ô đăng nhập nhận một email hoặc một số điện thoại. */
export function isValidLogin(value: string): boolean {
  const trimmed = value.trim()

  return trimmed.includes('@') ? EMAIL_PATTERN.test(trimmed) : normalizePhone(trimmed) !== null
}
