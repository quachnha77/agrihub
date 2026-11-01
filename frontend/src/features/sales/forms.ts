import type { TFunction } from 'i18next'
import type { Path, UseFormSetError } from 'react-hook-form'
import { z } from 'zod'
import { ApiError } from '../../lib/api.ts'
import type { Product } from '../catalog/api.ts'
import { parseNumber } from '../catalog/form.ts'
import { roundMoney } from '../purchase/forms.ts'
import type { PaymentPayload, ReturnPayload, Sale, SaleLine, SalePayload } from './api.ts'

export { roundMoney, todayIso } from '../purchase/forms.ts'

export interface SaleLineValues {
  product_id: string
  unit_id: string
  quantity: string
  unit_price: string
}

/** `full` = khách trả đủ ngay; `debt` = ghi nợ (khách trả trước một phần hoặc không trả, phần còn lại là nợ). */
export type PayMode = 'full' | 'debt'

export interface SaleFormValues {
  warehouse_id: string
  customer_id: string
  discount: string
  pay_mode: PayMode
  prepaid: string
  payment_method: 'cash' | 'bank_transfer'
  note: string
  lines: SaleLineValues[]
}

export const emptySaleLine = (): SaleLineValues => ({ product_id: '', unit_id: '', quantity: '', unit_price: '' })

/** Đơn giá gợi ý của đơn vị đã chọn: giá tiền mặt khi khách trả đủ, giá công nợ khi ghi nợ. Chuỗi rỗng nếu chưa chọn đơn vị. */
export function suggestedPrice(product: Product | undefined, unitId: string, mode: PayMode): string {
  const row = product?.units.find((u) => u.unit.id === unitId)

  if (!row) return ''

  const price = parseNumber(mode === 'debt' ? row.price_credit : row.price_cash)

  return price === null ? '' : String(price)
}

/** Thành tiền của một dòng = số lượng × đơn giá, 0 nếu chưa nhập đủ. */
export function lineTotal(line: Pick<SaleLineValues, 'quantity' | 'unit_price'>): number {
  const quantity = parseNumber(line.quantity)
  const price = parseNumber(line.unit_price)

  return quantity !== null && price !== null ? roundMoney(quantity * price) : 0
}

export const goodsTotal = (lines: SaleLineValues[]): number => roundMoney(lines.reduce((sum, line) => sum + lineTotal(line), 0))

/** Tiền khách phải trả = tiền hàng − số bớt (không âm). */
export const payable = (goods: number, discount: number): number => roundMoney(Math.max(goods - discount, 0))

export function saleSchema(t: TFunction) {
  const quantity = z.string().trim().refine((v) => {
    const n = parseNumber(v)

    return n !== null && n > 0 && n <= 99_999_999 && Math.round(n * 1000) / 1000 === n
  }, t('inventory.form.quantityInvalid'))
  const price = z.string().trim().refine((v) => {
    const n = parseNumber(v)

    return n !== null && n >= 0 && n <= 999_999_999 && Math.round(n * 100) / 100 === n
  }, t('sales.form.priceInvalid'))
  /** Ô tiền tùy chọn: để trống = 0. */
  const optionalMoney = z.string().trim().refine((v) => {
    if (v === '') return true
    const n = parseNumber(v)

    return n !== null && n >= 0 && n <= 999_999_999_999 && Math.round(n * 100) / 100 === n
  }, t('sales.form.moneyInvalid'))

  return z
    .object({
      warehouse_id: z.string().min(1, t('sales.form.warehouseRequired')),
      customer_id: z.string(),
      discount: optionalMoney,
      pay_mode: z.enum(['full', 'debt']),
      prepaid: optionalMoney,
      payment_method: z.enum(['cash', 'bank_transfer']),
      note: z.string().max(500, t('validation.maxLength', { count: 500 })),
      lines: z
        .array(z.object({ product_id: z.string().min(1, t('inventory.form.productRequired')), unit_id: z.string().min(1, t('inventory.form.unitRequired')), quantity, unit_price: price }))
        .min(1, t('inventory.form.linesRequired'))
        .max(100, t('inventory.form.tooManyLines', { max: 100 })),
    })
    .superRefine((values, ctx) => {
      const goods = goodsTotal(values.lines)
      const discount = parseNumber(values.discount) ?? 0

      if (discount > goods) ctx.addIssue({ code: 'custom', path: ['discount'], message: t('sales.form.discountTooMuch') })

      if (values.pay_mode === 'debt') {
        if (values.customer_id === '') ctx.addIssue({ code: 'custom', path: ['customer_id'], message: t('sales.form.customerForDebt') })
        if ((parseNumber(values.prepaid) ?? 0) > payable(goods, discount)) ctx.addIssue({ code: 'custom', path: ['prepaid'], message: t('sales.form.prepaidTooMuch') })
      }
    })
}

/** Nội dung gửi API. Trả đủ: số thu = tiền phải trả; ghi nợ: số thu = khách trả trước (có thể 0). Hình thức thu chỉ gửi khi có thu tiền. */
export function salePayload(values: SaleFormValues): SalePayload {
  const goods = goodsTotal(values.lines)
  const discount = parseNumber(values.discount) ?? 0
  const paid = values.pay_mode === 'full' ? payable(goods, discount) : (parseNumber(values.prepaid) ?? 0)

  return {
    warehouse_id: values.warehouse_id,
    customer_id: values.customer_id === '' ? null : values.customer_id,
    note: values.note.trim() === '' ? null : values.note.trim(),
    discount,
    paid_amount: paid,
    ...(paid > 0 ? { payment_method: values.payment_method } : {}),
    lines: values.lines.map((line) => ({
      product_id: line.product_id,
      unit_id: line.unit_id,
      quantity: parseNumber(line.quantity) ?? 0,
      unit_price: parseNumber(line.unit_price) ?? 0,
    })),
  }
}

const LINE_KEY = /^lines\.(\d+)\.(\w+)$/
// `paid_amount` của máy chủ hiện ở ô "khách trả trước"; `payment_method` không có ô riêng khi trả đủ nên rơi vào lỗi chung nếu có.
const FIELD_OF: Record<string, string> = { warehouse_id: 'warehouse_id', customer_id: 'customer_id', discount: 'discount', paid_amount: 'prepaid', payment_method: 'payment_method', note: 'note' }

/** Đưa lỗi 422 của máy chủ vào đúng ô (`customer_id`, `discount`, `lines.N.<ô>`...); lỗi không gắn được vào ô nào trả về để hiện chung. */
export function applySaleErrors(error: unknown, setError: UseFormSetError<SaleFormValues>, payMode: PayMode): { handled: boolean; leftover: string[] } {
  if (!(error instanceof ApiError) || error.status !== 422) return { handled: false, leftover: [] }

  const leftover: string[] = []
  let handled = false

  for (const [key, messages] of Object.entries(error.errors)) {
    const message = messages[0]
    if (!message) continue

    // Khách trả đủ thì không có ô "trả trước" để hiện lỗi `paid_amount`: hiện chung.
    const field = key === 'paid_amount' && payMode === 'full' ? undefined : FIELD_OF[key]

    if (field !== undefined || LINE_KEY.test(key)) {
      setError((field ?? key) as Path<SaleFormValues>, { type: 'server', message })
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

/** Biểu mẫu thu tiền: số tiền > 0 và không vượt số khách còn nợ (`balance`, đồng). */
export function paymentSchema(t: TFunction, balance: number) {
  return z.object({
    amount: z.string().trim().refine((v) => {
      const n = parseNumber(v)

      return n !== null && n > 0 && n <= 999_999_999_999 && Math.round(n * 100) / 100 === n
    }, t('sales.pay.amountInvalid')).refine((v) => (parseNumber(v) ?? 0) <= balance, t('sales.pay.tooMuch')),
    method: z.enum(['cash', 'bank_transfer']),
    paid_on: z.string().min(1, t('sales.form.dateRequired')),
    note: z.string().max(255, t('validation.maxLength', { count: 255 })),
  })
}

/** Thứ tự trừ nợ giống máy chủ: phiếu đến hạn sớm nhất trước, phiếu chưa có hạn trả sau cùng, rồi theo ngày bán và mã phiếu. */
export function sortByDueFirst(sales: Sale[]): Sale[] {
  return [...sales].sort((a, b) => {
    if ((a.due_on === null) !== (b.due_on === null)) return a.due_on === null ? 1 : -1

    return (a.due_on ?? '').localeCompare(b.due_on ?? '') || a.sold_on.localeCompare(b.sold_on) || a.code.localeCompare(b.code)
  })
}

/** Chia `amount` đồng cho các phiếu (đã xếp đúng thứ tự trừ nợ): mỗi phiếu nhận tối đa số còn nợ của nó. Phiếu không được trừ có `take` = 0. */
export function allocateDebt(sales: Sale[], amount: number): { sale: Sale; take: number }[] {
  let left = roundMoney(amount)

  return sales.map((sale) => {
    const take = Math.min(left, Number(sale.balance))
    left = roundMoney(left - take)

    return { sale, take }
  })
}

export function paymentPayload(values: PaymentFormValues): PaymentPayload {
  return { amount: parseNumber(values.amount) ?? 0, method: values.method, paid_on: values.paid_on === '' ? null : values.paid_on, note: values.note.trim() === '' ? null : values.note.trim() }
}

/** Số lượng của dòng phiếu còn trả lại được (đã bán − đã trả), theo đơn vị bán. */
export const returnable = (line: Pick<SaleLine, 'quantity' | 'returned_quantity'>): number => Math.round((Number(line.quantity) - Number(line.returned_quantity)) * 1000) / 1000

export interface ReturnFormValues {
  /** Số lượng trả của từng dòng CÒN trả được (cùng thứ tự với danh sách dòng đó); trống = không trả. */
  lines: { quantity: string }[]
  returned_on: string
  refund_method: 'cash' | 'bank_transfer'
  note: string
}

/** Biểu mẫu trả hàng: mỗi ô số lượng trống hoặc từ 0 đến số còn trả được của dòng (`max`, theo đơn vị bán `unit`). Việc chọn ít nhất một dòng kiểm tra khi gửi. */
export function returnSchema(t: TFunction, limits: { max: number; unit: string }[]) {
  return z
    .object({
      lines: z.array(z.object({ quantity: z.string().trim() })),
      returned_on: z.string().min(1, t('sales.form.dateRequired')),
      refund_method: z.enum(['cash', 'bank_transfer']),
      note: z.string().max(500, t('validation.maxLength', { count: 500 })),
    })
    .superRefine((values, ctx) => {
      values.lines.forEach((line, index) => {
        if (line.quantity === '') return

        const n = parseNumber(line.quantity)
        const limit = limits[index]

        if (n === null || n < 0 || Math.round(n * 1000) / 1000 !== n) {
          ctx.addIssue({ code: 'custom', path: ['lines', index, 'quantity'], message: t('sales.return.quantityInvalid') })
        } else if (limit && n > limit.max) {
          ctx.addIssue({ code: 'custom', path: ['lines', index, 'quantity'], message: t('sales.return.tooMany', { max: limit.max, unit: limit.unit }) })
        }
      })
    })
}

/** Các dòng được chọn trả (số lượng > 0): vị trí trong `lines` của biểu mẫu và số lượng. */
export function pickedLines(values: Pick<ReturnFormValues, 'lines'>): { index: number; quantity: number }[] {
  return values.lines.flatMap((line, index) => {
    const quantity = parseNumber(line.quantity) ?? 0

    return quantity > 0 ? [{ index, quantity }] : []
  })
}

/**
 * Xem trước kết quả trả hàng, tính giống máy chủ: giá trị = số lượng × đơn giá chia phần bớt tiền của phiếu (total / goods_total); trả hết hàng còn lại thì lấy đúng số khách còn phải trả thực.
 * Trừ vào nợ của phiếu trước, phần vượt hoàn cho khách. Con số cuối cùng do máy chủ tính.
 */
export function returnPreview(sale: Sale, open: SaleLine[], values: Pick<ReturnFormValues, 'lines'>): { goods: number; value: number; debt: number; refund: number } {
  const picked = pickedLines(values)
  const goods = roundMoney(picked.reduce((sum, { index, quantity }) => sum + roundMoney(quantity * Number(open[index]?.unit_price ?? 0)), 0))
  const everything = (sale.lines ?? []).every((line) => {
    const taken = picked.find(({ index }) => open[index]?.id === line.id)?.quantity ?? 0

    return Math.round((returnable(line) - taken) * 1000) === 0
  })
  const remaining = Number(sale.net_total)
  const ratio = Number(sale.goods_total) > 0 ? Number(sale.total) / Number(sale.goods_total) : 0
  const value = picked.length === 0 ? 0 : Math.min(everything ? remaining : roundMoney(goods * ratio), remaining)
  const debt = Math.min(value, Number(sale.balance))

  return { goods, value, debt: roundMoney(debt), refund: roundMoney(value - debt) }
}

/** Nội dung gửi API: chỉ các dòng có số lượng; hình thức hoàn tiền chỉ gửi khi có tiền phải hoàn. */
export function returnPayload(values: ReturnFormValues, open: SaleLine[], refund: number): ReturnPayload {
  return {
    returned_on: values.returned_on === '' ? null : values.returned_on,
    note: values.note.trim() === '' ? null : values.note.trim(),
    ...(refund > 0 ? { refund_method: values.refund_method } : {}),
    lines: pickedLines(values).map(({ index, quantity }) => ({ line_id: open[index]!.id, quantity })),
  }
}

/** Đưa lỗi 422 của máy chủ vào đúng ô (`lines.N.quantity` theo vị trí trong lần gửi → ô của dòng tương ứng, `returned_on`, `note`...); phần còn lại trả về để hiện chung. */
export function applyReturnErrors(error: unknown, setError: UseFormSetError<ReturnFormValues>, sentIndexes: number[]): { handled: boolean; leftover: string[] } {
  if (!(error instanceof ApiError) || error.status !== 422) return { handled: false, leftover: [] }

  const leftover: string[] = []

  for (const [key, messages] of Object.entries(error.errors)) {
    const message = messages[0]
    if (!message) continue

    const line = /^lines\.(\d+)\.(\w+)$/.exec(key)

    if (line) {
      const formIndex = sentIndexes[Number(line[1])]

      if (formIndex !== undefined) {
        setError(`lines.${formIndex}.quantity`, { type: 'server', message })
        continue
      }
    }

    if (key === 'returned_on' || key === 'note' || key === 'refund_method') setError(key, { type: 'server', message })
    else leftover.push(message)
  }

  return { handled: true, leftover }
}
