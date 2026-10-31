import type { TFunction } from 'i18next'
import type { Path, UseFormSetError } from 'react-hook-form'
import { z } from 'zod'
import { ApiError } from '../../lib/api.ts'
import { parseNumber } from '../catalog/form.ts'
import type { Product } from '../catalog/api.ts'
import type { DocumentPayload, DocumentType, LinePayload, TransferPayload } from './api.ts'

/** Giá trị đặc biệt của ô chọn lô: "lô mới" (khai số lô và hạn dùng). */
export const NEW_BATCH = '__new__'

export interface LineValues {
  product_id: string
  unit_id: string
  quantity: string
  /** Chỉ phiếu điều chỉnh: tăng hay giảm tồn (số lượng luôn nhập số dương). */
  direction: 'in' | 'out'
  unit_cost: string
  batch_id: string
  batch_no: string
  manufactured_at: string
  expires_at: string
}

export interface StockFormValues {
  type: DocumentType
  warehouse_id: string
  reason: string
  note: string
  lines: LineValues[]
}

export const emptyLine = (): LineValues => ({ product_id: '', unit_id: '', quantity: '', direction: 'out', unit_cost: '', batch_id: '', batch_no: '', manufactured_at: '', expires_at: '' })

/** Phiếu nhập hàng (có đơn giá và khai lô mới) hay phiếu làm giảm tồn: quyết định ô nào hiện trên mỗi dòng. */
export const isIncoming = (type: DocumentType) => type === 'receipt' || type === 'opening'

/** Dòng này làm GIẢM tồn (xuất kho, hoặc điều chỉnh giảm) → phải chọn lô có sẵn. */
export const isOutgoing = (type: DocumentType, line: Pick<LineValues, 'direction'>) => type === 'issue' || (type === 'adjustment' && line.direction === 'out')

export function stockSchema(t: TFunction, type: DocumentType) {
  const quantity = z.string().trim().refine((v) => {
    const n = parseNumber(v)

    return n !== null && n > 0 && n <= 99_999_999 && Math.round(n * 1000) / 1000 === n
  }, t('inventory.form.quantityInvalid'))

  const cost = z.string().trim().refine((v) => {
    if (!isIncoming(type)) return true
    const n = parseNumber(v)

    return n !== null && n >= 0 && n <= 999_999_999
  }, t('inventory.form.costInvalid'))

  return z
    .object({
      type: z.enum(['opening', 'receipt', 'issue', 'adjustment']),
      warehouse_id: z.string().min(1, t('inventory.form.warehouseRequired')),
      reason: z.string(),
      note: z.string().max(500, t('validation.maxLength', { count: 500 })),
      lines: z
        .array(
          z.object({
            product_id: z.string().min(1, t('inventory.form.productRequired')),
            unit_id: z.string().min(1, t('inventory.form.unitRequired')),
            quantity,
            direction: z.enum(['in', 'out']),
            unit_cost: cost,
            batch_id: z.string(),
            batch_no: z.string().max(50, t('validation.maxLength', { count: 50 })),
            manufactured_at: z.string(),
            expires_at: z.string(),
          }),
        )
        .min(1, t('inventory.form.linesRequired'))
        .max(100, t('inventory.form.tooManyLines', { max: 100 })),
    })
    .superRefine((values, ctx) => {
      if (type !== 'issue' && type !== 'adjustment') return

      if (values.reason === '') ctx.addIssue({ code: 'custom', path: ['reason'], message: t('inventory.form.reasonRequired') })
      else if (values.reason === 'other' && values.note.trim() === '') ctx.addIssue({ code: 'custom', path: ['note'], message: t('inventory.form.noteRequired') })
    })
}

/** Đơn vị hiển thị mặc định của dòng mới: đơn vị cơ sở của sản phẩm. */
export const defaultUnit = (product: Product): string => product.base_unit.id

/** Hệ số quy đổi của đơn vị đã chọn (về đơn vị cơ sở); 1 nếu chưa chọn. */
export function factorOf(product: Product | undefined, unitId: string): number {
  const row = product?.units.find((u) => u.unit.id === unitId)

  return row ? (parseNumber(row.factor) ?? 1) : 1
}

/**
 * Nội dung gửi API. Số lượng phiếu xuất luôn dương (máy chủ tự trừ); điều chỉnh mang dấu theo hướng đã chọn. Lô: dòng nhập hàng và điều chỉnh tăng khai
 * "lô mới" (số lô + hạn dùng), dòng xuất / điều chỉnh giảm chỉ gửi lô có sẵn đã chọn. Sản phẩm không theo dõi lô thì không gửi gì về lô.
 */
export function stockPayload(values: StockFormValues, products: Record<string, Product | undefined>): DocumentPayload {
  const lines: LinePayload[] = values.lines.map((line, index) => {
    const product = products[String(index)]
    const quantity = parseNumber(line.quantity) ?? 0
    const payload: LinePayload = {
      product_id: line.product_id,
      unit_id: line.unit_id,
      quantity: values.type === 'adjustment' && line.direction === 'out' ? -quantity : quantity,
    }

    if (isIncoming(values.type)) payload.unit_cost = parseNumber(line.unit_cost) ?? 0

    if (product?.track_batch) {
      const fresh = isIncoming(values.type) || line.batch_id === NEW_BATCH

      if (fresh) {
        payload.batch_no = line.batch_no.trim() === '' ? null : line.batch_no.trim()
        payload.expires_at = line.expires_at === '' ? null : line.expires_at
        payload.manufactured_at = line.manufactured_at === '' ? null : line.manufactured_at
      } else {
        payload.batch_id = line.batch_id === '' ? null : line.batch_id
      }
    }

    return payload
  })

  return {
    warehouse_id: values.warehouse_id,
    type: values.type,
    reason: values.type === 'issue' || values.type === 'adjustment' ? values.reason : null,
    note: values.note.trim() === '' ? null : values.note.trim(),
    lines,
  }
}

const LINE_KEY = /^lines\.(\d+)\.(\w+)$/

/**
 * Đưa lỗi 422 của máy chủ vào đúng ô: `warehouse_id`, `reason`, `note`, và `lines.N.<ô>`. Lỗi lô của một dòng (`batch_id`, `batch_no`, `expires_at`,
 * `manufactured_at`) đều hiện ở ô đại diện của dòng đó; lỗi không gắn được vào ô nào (vd. `lines`) trả về để hiện chung.
 */
export function applyStockErrors(error: unknown, setError: UseFormSetError<StockFormValues>): { handled: boolean; leftover: string[] } {
  if (!(error instanceof ApiError) || error.status !== 422) return { handled: false, leftover: [] }

  const leftover: string[] = []
  let handled = false

  for (const [key, messages] of Object.entries(error.errors)) {
    const message = messages[0]
    if (!message) continue

    if (['warehouse_id', 'type', 'reason', 'note'].includes(key) || LINE_KEY.test(key)) {
      setError(key as Path<StockFormValues>, { type: 'server', message })
      handled = true
    } else {
      leftover.push(message)
    }
  }

  return { handled: handled || leftover.length > 0, leftover }
}

// --- Chuyển kho ----------------------------------------------------------------------------------

export interface TransferLineValues {
  product_id: string
  unit_id: string
  quantity: string
  batch_id: string
}

export interface TransferFormValues {
  from_warehouse_id: string
  to_warehouse_id: string
  note: string
  lines: TransferLineValues[]
}

export const emptyTransferLine = (): TransferLineValues => ({ product_id: '', unit_id: '', quantity: '', batch_id: '' })

export function transferSchema(t: TFunction) {
  const quantity = z.string().trim().refine((v) => {
    const n = parseNumber(v)

    return n !== null && n > 0 && n <= 99_999_999 && Math.round(n * 1000) / 1000 === n
  }, t('inventory.form.quantityInvalid'))

  return z
    .object({
      from_warehouse_id: z.string().min(1, t('inventory.transfer.form.fromRequired')),
      to_warehouse_id: z.string().min(1, t('inventory.transfer.form.toRequired')),
      note: z.string().max(500, t('validation.maxLength', { count: 500 })),
      lines: z
        .array(
          z.object({
            product_id: z.string().min(1, t('inventory.form.productRequired')),
            unit_id: z.string().min(1, t('inventory.form.unitRequired')),
            quantity,
            batch_id: z.string(),
          }),
        )
        .min(1, t('inventory.form.linesRequired'))
        .max(100, t('inventory.form.tooManyLines', { max: 100 })),
    })
    .superRefine((values, ctx) => {
      if (values.from_warehouse_id !== '' && values.from_warehouse_id === values.to_warehouse_id) {
        ctx.addIssue({ code: 'custom', path: ['to_warehouse_id'], message: t('inventory.transfer.form.sameWarehouse') })
      }
    })
}

/** Nội dung gửi API. Sản phẩm theo dõi lô thì gửi lô đã chọn ở kho gửi; sản phẩm không theo dõi lô không gửi gì về lô. */
export function transferPayload(values: TransferFormValues, products: Record<string, Product | undefined>): TransferPayload {
  return {
    from_warehouse_id: values.from_warehouse_id,
    to_warehouse_id: values.to_warehouse_id,
    note: values.note.trim() === '' ? null : values.note.trim(),
    lines: values.lines.map((line, index) => ({
      product_id: line.product_id,
      unit_id: line.unit_id,
      quantity: parseNumber(line.quantity) ?? 0,
      ...(products[String(index)]?.track_batch ? { batch_id: line.batch_id === '' ? null : line.batch_id } : {}),
    })),
  }
}

/** Đưa lỗi 422 của máy chủ vào đúng ô (`from_warehouse_id`, `to_warehouse_id`, `note`, `lines.N.<ô>`); lỗi không gắn được vào ô nào trả về để hiện chung. */
export function applyTransferErrors(error: unknown, setError: UseFormSetError<TransferFormValues>): { handled: boolean; leftover: string[] } {
  if (!(error instanceof ApiError) || error.status !== 422) return { handled: false, leftover: [] }

  const leftover: string[] = []
  let handled = false

  for (const [key, messages] of Object.entries(error.errors)) {
    const message = messages[0]
    if (!message) continue

    if (['from_warehouse_id', 'to_warehouse_id', 'note'].includes(key) || LINE_KEY.test(key)) {
      setError(key as Path<TransferFormValues>, { type: 'server', message })
      handled = true
    } else {
      leftover.push(message)
    }
  }

  return { handled: handled || leftover.length > 0, leftover }
}
