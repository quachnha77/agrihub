import type { TFunction } from 'i18next'
import type { UseFormSetError } from 'react-hook-form'
import { z } from 'zod'
import { ApiError } from '../../lib/api.ts'
import type { Product, ProductPayload, ProductStatus, TypeDefinition } from './api.ts'

const MAX_MONEY = 999_999_999_999
const SKU_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._/-]*$/
const BARCODE_PATTERN = /^[A-Za-z0-9._-]+$/

/**
 * Đọc số người dùng gõ theo cách viết Việt Nam: dấu chấm ngăn nghìn ("1.500.000"), dấu phẩy là thập phân ("12,5").
 * Chuỗi chỉ có một dấu chấm không theo nhóm 3 chữ số ("12.5") coi là số thập phân. Trả về null nếu không phải số.
 */
export function parseNumber(input: string): number | null {
  const text = input.trim().replace(/\s/g, '')
  if (text === '') return null

  let normalized = text
  if (text.includes(',')) normalized = text.replace(/\./g, '').replace(',', '.')
  else if (/^\d{1,3}(\.\d{3})+$/.test(text)) normalized = text.replace(/\./g, '')

  if (!/^\d+(\.\d+)?$/.test(normalized)) return null

  return Number(normalized)
}

/** "750000.00" → "750000" để hiện lại trong ô nhập (bỏ phần thập phân thừa). */
export function toInputNumber(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''

  const n = Number(value)

  return Number.isFinite(n) ? String(n) : String(value)
}

export interface UnitRowValues {
  unit_id: string
  factor: string
  price_cash: string
  price_credit: string
  barcode: string
}

/** Cố ý khai báo tay (thay vì suy ra từ schema) để các ô nhập đều là chuỗi, đúng như người dùng gõ. */
export interface FormValues {
  branch_id: string
  type: string
  sku: string
  name: string
  category_id: string
  brand_id: string
  description: string
  status: ProductStatus
  track_batch: boolean
  base_unit_id: string
  base_price_cash: string
  base_price_credit: string
  base_barcode: string
  extra_units: UnitRowValues[]
  specs: Record<string, string>
}

export const emptyUnitRow = (): UnitRowValues => ({ unit_id: '', factor: '', price_cash: '', price_credit: '', barcode: '' })

export function toFormValues(product: Product | undefined, defaults: { branch_id: string }): FormValues {
  if (!product) {
    return {
      branch_id: defaults.branch_id, type: '', sku: '', name: '', category_id: '', brand_id: '', description: '', status: 'active', track_batch: false,
      base_unit_id: '', base_price_cash: '', base_price_credit: '', base_barcode: '', extra_units: [], specs: {},
    }
  }

  const base = product.units.find((u) => u.is_base)

  return {
    branch_id: product.branch.id,
    type: product.type,
    sku: product.sku,
    name: product.name,
    category_id: product.category?.id ?? '',
    brand_id: product.brand?.id ?? '',
    description: product.description ?? '',
    status: product.status,
    track_batch: product.track_batch,
    base_unit_id: product.base_unit.id,
    base_price_cash: toInputNumber(base?.price_cash),
    base_price_credit: toInputNumber(base?.price_credit),
    base_barcode: base?.barcode ?? '',
    extra_units: product.units
      .filter((u) => !u.is_base)
      .map((u) => ({ unit_id: u.unit.id, factor: toInputNumber(u.factor), price_cash: toInputNumber(u.price_cash), price_credit: toInputNumber(u.price_credit), barcode: u.barcode ?? '' })),
    specs: Object.fromEntries(Object.entries(product.specs).map(([key, value]) => [key, String(value)])),
  }
}

/** Nội dung gửi API. Khi sửa không gửi loại hàng, chi nhánh, đơn vị cơ sở (máy chủ không cho đổi). */
export function toPayload(values: FormValues, definition: TypeDefinition, creating: boolean): ProductPayload {
  const specs: Record<string, string | number> = {}

  for (const field of definition.fields) {
    const raw = (values.specs[field.key] ?? '').trim()
    if (raw === '') continue
    specs[field.key] = field.type === 'integer' || field.type === 'number' ? (parseNumber(raw) ?? raw) : raw
  }

  const row = (unitId: string, factor: number, cash: string, credit: string, barcode: string) => ({
    unit_id: unitId,
    factor,
    price_cash: parseNumber(cash) ?? 0,
    price_credit: parseNumber(credit) ?? 0,
    barcode: barcode.trim() === '' ? null : barcode.trim(),
  })

  const payload: ProductPayload = {
    sku: values.sku.trim(),
    name: values.name.trim(),
    description: values.description.trim() === '' ? null : values.description.trim(),
    status: values.status,
    track_batch: definition.track_batch_required ? true : values.track_batch,
    category_id: values.category_id === '' ? null : values.category_id,
    brand_id: values.brand_id === '' ? null : values.brand_id,
    specs,
    units: [
      row(values.base_unit_id, 1, values.base_price_cash, values.base_price_credit, values.base_barcode),
      ...values.extra_units.map((u) => row(u.unit_id, parseNumber(u.factor) ?? 0, u.price_cash, u.price_credit, u.barcode)),
    ],
  }

  return creating ? { ...payload, type: definition.key, branch_id: values.branch_id, base_unit_id: values.base_unit_id } : payload
}

/** Luật kiểm tra trên trình duyệt (máy chủ vẫn kiểm tra lại tất cả). Trường đặc thù lấy từ định nghĩa của loại hàng đang chọn. */
export function buildSchema(t: TFunction, types: TypeDefinition[], creating: boolean, maxUnits: number) {
  const money = z.string().trim().refine((v) => {
    const n = parseNumber(v)

    return n !== null && n >= 0 && n <= MAX_MONEY && Math.round(n * 100) / 100 === n
  }, t('catalog.form.priceInvalid'))

  const factor = z.string().trim().refine((v) => {
    const n = parseNumber(v)

    return n !== null && n > 0 && n <= 1_000_000_000 && Math.round(n * 1e6) / 1e6 === n
  }, t('catalog.form.factorInvalid'))

  const barcode = z.string().trim().max(64, t('validation.maxLength', { count: 64 })).refine((v) => v === '' || BARCODE_PATTERN.test(v), t('catalog.form.barcodeFormat'))

  return z
    .object({
      branch_id: creating ? z.string().min(1, t('catalog.form.branchRequired')) : z.string(),
      type: creating ? z.string().min(1, t('catalog.form.typeRequired')) : z.string(),
      sku: z.string().trim().min(1, t('catalog.form.skuRequired')).max(50, t('validation.maxLength', { count: 50 })).regex(SKU_PATTERN, t('catalog.form.skuFormat')),
      name: z.string().trim().min(1, t('catalog.form.nameRequired')).max(255, t('validation.maxLength', { count: 255 })),
      category_id: z.string(),
      brand_id: z.string(),
      description: z.string().max(2000, t('validation.maxLength', { count: 2000 })),
      status: z.enum(['active', 'inactive']),
      track_batch: z.boolean(),
      base_unit_id: creating ? z.string().min(1, t('catalog.form.baseUnitRequired')) : z.string(),
      base_price_cash: money,
      base_price_credit: money,
      base_barcode: barcode,
      extra_units: z
        .array(z.object({ unit_id: z.string().min(1, t('catalog.form.unitRequired')), factor, price_cash: money, price_credit: money, barcode }))
        .max(Math.max(0, maxUnits - 1), t('catalog.form.tooManyUnits', { max: maxUnits })),
      specs: z.record(z.string(), z.string()),
    })
    .superRefine((values, ctx) => {
      const definition = types.find((type) => type.key === values.type)

      if (!definition) {
        if (creating && values.type !== '') ctx.addIssue({ code: 'custom', path: ['type'], message: t('catalog.form.typeRequired') })

        return
      }

      for (const field of definition.fields) {
        const raw = (values.specs[field.key] ?? '').trim()
        const path = ['specs', field.key]

        if (raw === '') {
          if (field.required) ctx.addIssue({ code: 'custom', path, message: t('catalog.form.specRequired', { name: field.label.toLowerCase() }) })
          continue
        }

        if (field.type === 'text' && field.max !== undefined && raw.length > field.max) {
          ctx.addIssue({ code: 'custom', path, message: t('validation.maxLength', { count: field.max }) })
        } else if (field.type === 'integer' || field.type === 'number') {
          const n = parseNumber(raw)
          const bad = n === null || (field.min !== undefined && n < field.min) || (field.maxValue !== undefined && n > field.maxValue) || (field.type === 'integer' && !Number.isInteger(n))

          if (bad) ctx.addIssue({ code: 'custom', path, message: t('catalog.form.specRange', { min: field.min ?? 0, max: field.maxValue ?? '' }) })
        } else if (field.type === 'select' && !(field.options ?? []).includes(raw)) {
          ctx.addIssue({ code: 'custom', path, message: t('catalog.form.specRequired', { name: field.label.toLowerCase() }) })
        }
      }

      const used = new Set<string>([values.base_unit_id])
      values.extra_units.forEach((unit, index) => {
        if (unit.unit_id !== '' && used.has(unit.unit_id)) ctx.addIssue({ code: 'custom', path: ['extra_units', index, 'unit_id'], message: t('catalog.form.unitDuplicate') })
        used.add(unit.unit_id)
      })

      const barcodes = new Map<string, string>()
      const check = (code: string, path: (string | number)[]) => {
        const value = code.trim()
        if (value === '') return
        if (barcodes.has(value)) ctx.addIssue({ code: 'custom', path, message: t('catalog.form.barcodeDuplicate') })
        barcodes.set(value, value)
      }
      check(values.base_barcode, ['base_barcode'])
      values.extra_units.forEach((unit, index) => check(unit.barcode, ['extra_units', index, 'barcode']))
    })
}

const SAME_NAME = ['sku', 'name', 'description', 'status', 'category_id', 'brand_id', 'track_batch', 'type', 'branch_id', 'base_unit_id'] as const

/**
 * Đưa lỗi 422 của máy chủ vào đúng ô trên form. Máy chủ đánh số đơn vị theo danh sách gửi lên [đơn vị cơ sở, ...đơn vị khác]
 * nên `units.0.*` là dòng đơn vị cơ sở và `units.N.*` là dòng quy đổi thứ N−1. Lỗi không gắn được vào ô nào trả về để hiện chung.
 */
export function applyProductErrors(error: unknown, setError: UseFormSetError<FormValues>): { handled: boolean; leftover: string[] } {
  if (!(error instanceof ApiError) || error.status !== 422) return { handled: false, leftover: [] }

  const leftover: string[] = []
  let handled = false

  for (const [key, messages] of Object.entries(error.errors)) {
    const message = messages[0]
    if (!message) continue

    const unit = /^units\.(\d+)\.(\w+)$/.exec(key)
    const spec = /^specs\.(\w+)$/.exec(key)

    if ((SAME_NAME as readonly string[]).includes(key)) {
      setError(key as (typeof SAME_NAME)[number], { type: 'server', message })
      handled = true
    } else if (spec) {
      setError(`specs.${spec[1]}`, { type: 'server', message })
      handled = true
    } else if (unit && unit[2] !== 'factor' && unit[2] !== 'unit_id' && Number(unit[1]) === 0) {
      setError(`base_${unit[2]}` as 'base_price_cash' | 'base_price_credit' | 'base_barcode', { type: 'server', message })
      handled = true
    } else if (unit && Number(unit[1]) >= 1) {
      setError(`extra_units.${Number(unit[1]) - 1}.${unit[2]}` as `extra_units.${number}.barcode`, { type: 'server', message })
      handled = true
    } else {
      leftover.push(message)
    }
  }

  return { handled: handled || leftover.length > 0, leftover }
}
