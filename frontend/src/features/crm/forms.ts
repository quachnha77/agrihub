import type { TFunction } from 'i18next'
import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { z } from 'zod'
import { ApiError } from '../../lib/api.ts'
import { normalizePhone } from '../../lib/phone.ts'
import { parseNumber, toInputNumber } from '../catalog/form.ts'
import type { Customer, CustomerPayload, PartyStatus, Supplier, SupplierPayload } from './api.ts'

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const TAX_CODE = /^[0-9][0-9-]{7,19}$/
const CODE = /^[A-Za-z0-9][A-Za-z0-9._-]*$/

export { parseNumber }

const blank = (value: string): string | null => (value.trim() === '' ? null : value.trim())

export interface AddressValues {
  label: string
  address: string
  ward: string
  district: string
  province: string
  is_default: boolean
}

export interface ContactValues {
  name: string
  phone: string
  position: string
  note: string
}

export interface CustomerFormValues {
  branch_id: string
  type: string
  code: string
  name: string
  phone: string
  email: string
  tax_code: string
  group_id: string
  credit_limit: string
  credit_days: string
  notes: string
  status: PartyStatus
  addresses: AddressValues[]
  contacts: ContactValues[]
}

export interface SupplierContactValues {
  name: string
  phone: string
  email: string
  position: string
}

export interface SupplierFormValues {
  code: string
  name: string
  tax_code: string
  phone: string
  email: string
  address: string
  payment_terms_days: string
  notes: string
  status: PartyStatus
  contacts: SupplierContactValues[]
}

export const emptyAddress = (isDefault = false): AddressValues => ({ label: '', address: '', ward: '', district: '', province: '', is_default: isDefault })
export const emptyContact = (): ContactValues => ({ name: '', phone: '', position: '', note: '' })
export const emptySupplierContact = (): SupplierContactValues => ({ name: '', phone: '', email: '', position: '' })

export function customerDefaults(customer: Customer | undefined): CustomerFormValues {
  if (!customer) {
    return { branch_id: '', type: 'individual', code: '', name: '', phone: '', email: '', tax_code: '', group_id: '', credit_limit: '0', credit_days: '', notes: '', status: 'active', addresses: [], contacts: [] }
  }

  return {
    branch_id: customer.branch.id,
    type: customer.type,
    code: customer.code,
    name: customer.name,
    phone: customer.phone ?? '',
    email: customer.email ?? '',
    tax_code: customer.tax_code ?? '',
    group_id: customer.group?.id ?? '',
    credit_limit: toInputNumber(customer.credit_limit) || '0',
    credit_days: customer.credit_days === null ? '' : String(customer.credit_days),
    notes: customer.notes ?? '',
    status: customer.status,
    addresses: customer.addresses.map((a) => ({ label: a.label ?? '', address: a.address, ward: a.ward ?? '', district: a.district ?? '', province: a.province ?? '', is_default: a.is_default })),
    contacts: customer.contacts.map((c) => ({ name: c.name, phone: c.phone ?? '', position: c.position ?? '', note: c.note ?? '' })),
  }
}

export function supplierDefaults(supplier: Supplier | undefined): SupplierFormValues {
  if (!supplier) return { code: '', name: '', tax_code: '', phone: '', email: '', address: '', payment_terms_days: '', notes: '', status: 'active', contacts: [] }

  return {
    code: supplier.code,
    name: supplier.name,
    tax_code: supplier.tax_code ?? '',
    phone: supplier.phone ?? '',
    email: supplier.email ?? '',
    address: supplier.address ?? '',
    payment_terms_days: supplier.payment_terms_days === null ? '' : String(supplier.payment_terms_days),
    notes: supplier.notes ?? '',
    status: supplier.status,
    contacts: supplier.contacts.map((c) => ({ name: c.name, phone: c.phone ?? '', email: c.email ?? '', position: c.position ?? '' })),
  }
}

/**
 * Nội dung gửi API. Mã khách chỉ gửi khi tạo (để trống thì máy chủ tự sinh); khi sửa mã không đổi được.
 * Chi nhánh phụ trách chỉ gửi khi tạo hoặc khi đổi sang chi nhánh khác (đổi = chuyển khách, máy chủ kiểm tra quyền ở chi nhánh đích).
 */
export function customerPayload(values: CustomerFormValues, existing: Customer | undefined): CustomerPayload {
  const creating = existing === undefined

  return {
    ...(creating || values.branch_id !== existing.branch.id ? { branch_id: values.branch_id } : {}),
    type: values.type as CustomerPayload['type'],
    ...(creating && values.code.trim() !== '' ? { code: values.code.trim() } : {}),
    name: values.name.trim(),
    phone: blank(values.phone),
    email: blank(values.email),
    tax_code: blank(values.tax_code),
    group_id: values.group_id === '' ? null : values.group_id,
    credit_limit: parseNumber(values.credit_limit) ?? 0,
    credit_days: values.credit_days.trim() === '' ? null : (parseNumber(values.credit_days) ?? null),
    notes: blank(values.notes),
    status: values.status,
    addresses: values.addresses.map((a) => ({ label: blank(a.label), address: a.address.trim(), ward: blank(a.ward), district: blank(a.district), province: blank(a.province), is_default: a.is_default })),
    contacts: values.contacts.map((c) => ({ name: c.name.trim(), phone: blank(c.phone), position: blank(c.position), note: blank(c.note) })),
  }
}

export function supplierPayload(values: SupplierFormValues, creating: boolean): SupplierPayload {
  return {
    ...(creating && values.code.trim() !== '' ? { code: values.code.trim() } : {}),
    name: values.name.trim(),
    tax_code: blank(values.tax_code),
    phone: blank(values.phone),
    email: blank(values.email),
    address: blank(values.address),
    payment_terms_days: values.payment_terms_days.trim() === '' ? null : (parseNumber(values.payment_terms_days) ?? null),
    notes: blank(values.notes),
    status: values.status,
    contacts: values.contacts.map((c) => ({ name: c.name.trim(), phone: blank(c.phone), email: blank(c.email), position: blank(c.position) })),
  }
}

const phoneRule = (t: TFunction) => z.string().trim().max(30).refine((v) => v === '' || normalizePhone(v) !== null, t('crm.form.phoneInvalid'))
const emailRule = (t: TFunction) => z.string().trim().max(255).refine((v) => v === '' || EMAIL.test(v), t('validation.email'))
const taxRule = (t: TFunction) => z.string().trim().refine((v) => v === '' || TAX_CODE.test(v), t('crm.form.taxCodeInvalid'))
const codeRule = (t: TFunction) => z.string().trim().max(30, t('validation.maxLength', { count: 30 })).refine((v) => v === '' || CODE.test(v), t('crm.form.codeInvalid'))
const daysRule = (t: TFunction) =>
  z.string().trim().refine((v) => {
    if (v === '') return true
    const n = parseNumber(v)

    return n !== null && Number.isInteger(n) && n >= 0 && n <= 3650
  }, t('crm.form.daysInvalid'))

export function customerSchema(t: TFunction, creating: boolean) {
  return z.object({
    branch_id: z.string().min(1, t('crm.form.branchRequired')),
    type: z.string().min(1),
    code: creating ? codeRule(t) : z.string(),
    name: z.string().trim().min(1, t('crm.form.nameRequired')).max(255, t('validation.maxLength', { count: 255 })),
    phone: phoneRule(t),
    email: emailRule(t),
    tax_code: taxRule(t),
    group_id: z.string(),
    credit_limit: z.string().trim().refine((v) => {
      const n = parseNumber(v)

      return n !== null && n >= 0 && n <= 999_999_999_999 && Math.round(n * 100) / 100 === n
    }, t('crm.form.creditLimitInvalid')),
    credit_days: daysRule(t),
    notes: z.string().max(2000, t('validation.maxLength', { count: 2000 })),
    status: z.enum(['active', 'inactive']),
    addresses: z
      .array(z.object({ label: z.string().max(50), address: z.string().trim().min(1, t('crm.form.addressRequired')).max(255), ward: z.string().max(100), district: z.string().max(100), province: z.string().max(100), is_default: z.boolean() }))
      .max(10, t('crm.form.tooMany', { max: 10 })),
    contacts: z.array(z.object({ name: z.string().trim().min(1, t('crm.form.contactNameRequired')).max(255), phone: phoneRule(t), position: z.string().max(100), note: z.string().max(255) })).max(10, t('crm.form.tooMany', { max: 10 })),
  })
}

export function supplierSchema(t: TFunction, creating: boolean) {
  return z.object({
    code: creating ? codeRule(t) : z.string(),
    name: z.string().trim().min(1, t('crm.form.supplierNameRequired')).max(255, t('validation.maxLength', { count: 255 })),
    tax_code: taxRule(t),
    phone: phoneRule(t),
    email: emailRule(t),
    address: z.string().max(255, t('validation.maxLength', { count: 255 })),
    payment_terms_days: daysRule(t),
    notes: z.string().max(2000, t('validation.maxLength', { count: 2000 })),
    status: z.enum(['active', 'inactive']),
    contacts: z.array(z.object({ name: z.string().trim().min(1, t('crm.form.contactNameRequired')).max(255), phone: phoneRule(t), email: emailRule(t), position: z.string().max(100) })).max(10, t('crm.form.tooMany', { max: 10 })),
  })
}

/**
 * Đưa lỗi 422 của máy chủ vào đúng ô: khóa cấp một (`name`), và khóa lồng của danh sách (`addresses.0.address`, `contacts.1.phone`) trùng tên
 * với tên ô trên form. Lỗi không gắn được vào ô nào (vd. "Chỉ được chọn một địa chỉ mặc định") trả về để hiện chung ở đầu form.
 */
export function applyPartyErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>, known: readonly string[]): { handled: boolean; leftover: string[] } {
  if (!(error instanceof ApiError) || error.status !== 422) return { handled: false, leftover: [] }

  const leftover: string[] = []
  let handled = false

  for (const [key, messages] of Object.entries(error.errors)) {
    const message = messages[0]
    if (!message) continue

    if (known.includes(key) || /^(addresses|contacts)\.\d+\.\w+$/.test(key)) {
      setError(key as Path<T>, { type: 'server', message })
      handled = true
    } else {
      leftover.push(message)
    }
  }

  return { handled: handled || leftover.length > 0, leftover }
}
