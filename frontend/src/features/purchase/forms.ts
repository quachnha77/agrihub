import type { TFunction } from 'i18next'
import type { Path, UseFormSetError } from 'react-hook-form'
import { z } from 'zod'
import { ApiError } from '../../lib/api.ts'
import type { Product } from '../catalog/api.ts'
import { parseNumber } from '../catalog/form.ts'
import type { PaymentPayload, PurchasePayload } from './api.ts'

export interface PurchaseLineValues {
  product_id: string
  unit_id: string
  quantity: string
  unit_cost: string
  batch_no: string
  manufactured_at: string
  expires_at: string
}

export interface PurchaseFormValues {
  supplier_id: string
  warehouse_id: string
  received_on: string
  supplier_ref: string
  extra_cost: string
  paid_amount: string
  payment_method: 'cash' | 'bank_transfer'
  note: string
  lines: PurchaseLineValues[]
}

export const emptyPurchaseLine = (): PurchaseLineValues => ({ product_id: '', unit_id: '', quantity: '', unit_cost: '', batch_no: '', manufactured_at: '', expires_at: '' })

/** Hôm nay theo giờ Việt Nam, "YYYY-MM-DD" (ngày nhập mặc định; máy chủ không nhận ngày ở tương lai). */
export const todayIso = (now: Date = new Date()): string => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Ho_Chi_Minh' }).format(now)

/** Làm tròn tiền tới đồng như máy chủ (2 chữ số thập phân, nửa lên). */
export const roundMoney = (value: number): number => Math.round((value + Number.EPSILON) * 100) / 100

/** Thành tiền của một dòng = số lượng × đơn giá (theo đơn vị đã chọn), 0 nếu chưa nhập đủ. */
export function lineTotal(line: Pick<PurchaseLineValues, 'quantity' | 'unit_cost'>): number {
  const quantity = parseNumber(line.quantity)
  const cost = parseNumber(line.unit_cost)

  return quantity !== null && cost !== null ? roundMoney(quantity * cost) : 0
}

export const goodsTotal = (lines: PurchaseLineValues[]): number => roundMoney(lines.reduce((sum, line) => sum + lineTotal(line), 0))

export function purchaseSchema(t: TFunction) {
  const quantity = z.string().trim().refine((v) => {
    const n = parseNumber(v)

    return n !== null && n > 0 && n <= 99_999_999 && Math.round(n * 1000) / 1000 === n
  }, t('inventory.form.quantityInvalid'))
  const cost = z.string().trim().refine((v) => {
    const n = parseNumber(v)

    return n !== null && n >= 0 && n <= 999_999_999 && Math.round(n * 10_000) / 10_000 === n
  }, t('purchase.form.costInvalid'))
  /** Ô tiền tùy chọn: để trống = 0. */
  const optionalMoney = z.string().trim().refine((v) => {
    if (v === '') return true
    const n = parseNumber(v)

    return n !== null && n >= 0 && n <= 999_999_999_999 && Math.round(n * 100) / 100 === n
  }, t('purchase.form.moneyInvalid'))

  return z
    .object({
      supplier_id: z.string().min(1, t('purchase.form.supplierRequired')),
      warehouse_id: z.string().min(1, t('purchase.form.warehouseRequired')),
      received_on: z.string().min(1, t('purchase.form.dateRequired')),
      supplier_ref: z.string().max(50, t('validation.maxLength', { count: 50 })),
      extra_cost: optionalMoney,
      paid_amount: optionalMoney,
      payment_method: z.enum(['cash', 'bank_transfer']),
      note: z.string().max(500, t('validation.maxLength', { count: 500 })),
      lines: z
        .array(
          z.object({
            product_id: z.string().min(1, t('inventory.form.productRequired')),
            unit_id: z.string().min(1, t('inventory.form.unitRequired')),
            quantity,
            unit_cost: cost,
            batch_no: z.string().max(50, t('validation.maxLength', { count: 50 })),
            manufactured_at: z.string(),
            expires_at: z.string(),
          }),
        )
        .min(1, t('inventory.form.linesRequired'))
        .max(100, t('inventory.form.tooManyLines', { max: 100 })),
    })
    .superRefine((values, ctx) => {
      const paid = parseNumber(values.paid_amount) ?? 0

      if (paid > goodsTotal(values.lines)) ctx.addIssue({ code: 'custom', path: ['paid_amount'], message: t('purchase.form.paidTooMuch') })
    })
}

/** Nội dung gửi API. Chỉ gửi lô cho sản phẩm theo dõi lô; hình thức trả chỉ gửi khi có trả tiền ngay. */
export function purchasePayload(values: PurchaseFormValues, products: Record<string, Product | undefined>): PurchasePayload {
  const paid = parseNumber(values.paid_amount) ?? 0

  return {
    warehouse_id: values.warehouse_id,
    supplier_id: values.supplier_id,
    supplier_ref: values.supplier_ref.trim() === '' ? null : values.supplier_ref.trim(),
    received_on: values.received_on === '' ? null : values.received_on,
    note: values.note.trim() === '' ? null : values.note.trim(),
    extra_cost: parseNumber(values.extra_cost) ?? 0,
    paid_amount: paid,
    ...(paid > 0 ? { payment_method: values.payment_method } : {}),
    lines: values.lines.map((line, index) => ({
      product_id: line.product_id,
      unit_id: line.unit_id,
      quantity: parseNumber(line.quantity) ?? 0,
      unit_cost: parseNumber(line.unit_cost) ?? 0,
      ...(products[String(index)]?.track_batch
        ? {
            batch_no: line.batch_no.trim() === '' ? null : line.batch_no.trim(),
            expires_at: line.expires_at === '' ? null : line.expires_at,
            manufactured_at: line.manufactured_at === '' ? null : line.manufactured_at,
          }
        : {}),
    })),
  }
}

const LINE_KEY = /^lines\.(\d+)\.(\w+)$/
const TOP_LEVEL = ['supplier_id', 'warehouse_id', 'received_on', 'supplier_ref', 'extra_cost', 'paid_amount', 'payment_method', 'note']

/** Đưa lỗi 422 của máy chủ vào đúng ô (`warehouse_id`, `paid_amount`, `lines.N.<ô>`...); lỗi không gắn được vào ô nào trả về để hiện chung. */
export function applyPurchaseErrors(error: unknown, setError: UseFormSetError<PurchaseFormValues>): { handled: boolean; leftover: string[] } {
  if (!(error instanceof ApiError) || error.status !== 422) return { handled: false, leftover: [] }

  const leftover: string[] = []
  let handled = false

  for (const [key, messages] of Object.entries(error.errors)) {
    const message = messages[0]
    if (!message) continue

    if (TOP_LEVEL.includes(key) || LINE_KEY.test(key)) {
      setError(key as Path<PurchaseFormValues>, { type: 'server', message })
      handled = true
    } else {
      leftover.push(message)
    }
  }

  return { handled: handled || leftover.length > 0, leftover }
}

export interface PaymentFormValues {
  amount: string
  method: 'cash' | 'bank_transfer'
  paid_on: string
  note: string
}

/** Biểu mẫu trả tiền: số tiền > 0 và không vượt số còn nợ (`balance`, đồng). */
export function paymentSchema(t: TFunction, balance: number) {
  return z.object({
    amount: z.string().trim().refine((v) => {
      const n = parseNumber(v)

      return n !== null && n > 0 && n <= 999_999_999_999 && Math.round(n * 100) / 100 === n
    }, t('purchase.pay.amountInvalid')).refine((v) => (parseNumber(v) ?? 0) <= balance, t('purchase.pay.tooMuch')),
    method: z.enum(['cash', 'bank_transfer']),
    paid_on: z.string().min(1, t('purchase.form.dateRequired')),
    note: z.string().max(255, t('validation.maxLength', { count: 255 })),
  })
}

export function paymentPayload(values: PaymentFormValues): PaymentPayload {
  return { amount: parseNumber(values.amount) ?? 0, method: values.method, paid_on: values.paid_on === '' ? null : values.paid_on, note: values.note.trim() === '' ? null : values.note.trim() }
}
