/** Kiểu dùng chung của nút và ô nhập. Tách khỏi ui.tsx vì tệp thành phần chỉ nên xuất thành phần (làm mới nóng của Vite). */

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'soft'
export type ButtonSize = 'md' | 'sm'

const buttonVariants: Record<ButtonVariant, string> = {
  primary: 'bg-brand-700 text-white shadow-sm hover:bg-brand-800 focus-visible:outline-brand-700',
  secondary: 'bg-white text-slate-800 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus-visible:outline-slate-500',
  danger: 'bg-red-700 text-white shadow-sm hover:bg-red-800 focus-visible:outline-red-700',
  ghost: 'text-slate-700 hover:bg-slate-100 focus-visible:outline-slate-500',
  soft: 'bg-brand-50 text-brand-800 hover:bg-brand-100 focus-visible:outline-brand-700',
}

const buttonSizes: Record<ButtonSize, string> = { md: 'min-h-10 px-4', sm: 'min-h-9 px-3' }

/** Kiểu nút dùng chung cho <Button> và liên kết trông như nút (<ButtonLink>). Nút cao tối thiểu 40px để dễ bấm trên điện thoại. */
export function buttonStyles(variant: ButtonVariant = 'primary', size: ButtonSize = 'md', className = ''): string {
  return `inline-flex items-center justify-center gap-2 rounded-lg text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ${buttonSizes[size]} ${buttonVariants[variant]} ${className}`
}

/** Kiểu chung của ô nhập/ô chọn. Chữ 16px trên điện thoại: nhỏ hơn thì iOS tự phóng to trang khi chạm vào ô. */
export const fieldBase =
  'block min-h-10 w-full rounded-lg border bg-white px-3 py-2 text-base shadow-xs outline-none transition-colors placeholder:text-slate-400 focus:ring-2 disabled:bg-slate-100 disabled:text-slate-500 read-only:bg-slate-50 md:text-sm'

export const fieldTone = (error: boolean): string => (error ? 'border-red-500 focus:ring-red-200' : 'border-slate-300 focus:border-brand-600 focus:ring-brand-200')


/** Nút/liên kết chỉ có biểu tượng: vùng chạm 36px trên máy tính, 40px trên điện thoại. Xóa chỉ đỏ khi rê chuột để bảng đỡ rối. */
export function iconButtonStyles(tone: 'neutral' | 'danger' = 'neutral', className = ''): string {
  const hover = tone === 'danger' ? 'hover:bg-red-50 hover:text-red-700' : 'hover:bg-slate-100 hover:text-slate-900'

  return `inline-flex h-9 min-w-9 items-center justify-center gap-1.5 rounded-lg px-2 text-slate-500 transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-50 max-md:h-10 max-md:min-w-10 ${hover} ${className}`
}
