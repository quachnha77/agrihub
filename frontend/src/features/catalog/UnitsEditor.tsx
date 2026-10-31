import { Plus, Trash2 } from 'lucide-react'
import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { DataTable } from '../../components/table.tsx'
import { Button, IconButton } from '../../components/ui.tsx'
import { formatVnd } from '../../lib/format.ts'
import type { ListItem } from './api.ts'
import { emptyUnitRow, parseNumber, type FormValues } from './form.ts'

const inputClass = (error: boolean) => `${fieldBase} ${fieldTone(error)}`

/** Số tiền người dùng đang gõ, hiện lại đúng định dạng (750.000 ₫) để tránh nhầm dấu chấm/phẩy. */
function Preview({ value }: { value: string }) {
  const n = parseNumber(value)

  return <p className="mt-0.5 h-4 text-xs text-slate-500">{n === null ? '' : formatVnd(n)}</p>
}

/**
 * Các đơn vị bán của sản phẩm: dòng đầu là đơn vị cơ sở (hệ số 1, tồn kho đếm theo đơn vị này), các dòng sau là đơn vị quy đổi
 * (vd. 1 Bao = 50 Kg). Mỗi đơn vị có giá tiền mặt, giá công nợ và mã vạch riêng.
 */
export function UnitsEditor({
  form,
  unitOptions,
  baseUnitName,
  maxUnits,
}: {
  form: UseFormReturn<FormValues>
  unitOptions: ListItem[]
  baseUnitName: string
  maxUnits: number
}) {
  const { t } = useTranslation()
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'extra_units' })
  const errors = form.formState.errors
  const baseCash = useWatch({ control: form.control, name: 'base_price_cash' })
  const baseCredit = useWatch({ control: form.control, name: 'base_price_credit' })
  const extra = useWatch({ control: form.control, name: 'extra_units' })
  const baseUnitId = useWatch({ control: form.control, name: 'base_unit_id' })
  const usedIds = new Set([baseUnitId, ...(extra ?? []).map((row) => row.unit_id)])
  const unitName = (id: string) => unitOptions.find((u) => u.id === id)?.name ?? ''
  const canAdd = fields.length + 1 < maxUnits
  const base = baseUnitName || t('catalog.form.baseUnitPlaceholder')

  return (
    <div className="space-y-3">
      <DataTable minWidth="48rem" variant="form">
        <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
          <tr>
            <th scope="col" className="w-40 py-2 pr-2">{t('catalog.units.unit')}</th>
            <th scope="col" className="w-36 py-2 pr-2">{t('catalog.units.factor', { base })}</th>
            <th scope="col" className="py-2 pr-2">{t('catalog.units.priceCash')}</th>
            <th scope="col" className="py-2 pr-2">{t('catalog.units.priceCredit')}</th>
            <th scope="col" className="w-40 py-2 pr-2">{t('catalog.units.barcode')}</th>
            <th scope="col" className="w-10 py-2"><span className="sr-only">{t('backup.list.actions')}</span></th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 align-top">
          <tr>
            <td className="py-2 pr-2">
              <span className="block py-1.5 font-medium text-slate-900">{baseUnitName || '—'}</span>
              <span className="text-xs text-brand-700">{t('catalog.units.baseBadge')}</span>
            </td>
            <td className="py-2 pr-2"><span className="block py-1.5 text-slate-600">1</span></td>
            <td className="py-2 pr-2">
              <input inputMode="decimal" aria-label={t('catalog.units.priceCashOf', { unit: base })} aria-invalid={errors.base_price_cash ? true : undefined} className={inputClass(!!errors.base_price_cash)} {...form.register('base_price_cash')} />
              {errors.base_price_cash ? <p role="alert" className="mt-0.5 text-xs text-red-700">{errors.base_price_cash.message}</p> : <Preview value={baseCash} />}
            </td>
            <td className="py-2 pr-2">
              <input inputMode="decimal" aria-label={t('catalog.units.priceCreditOf', { unit: base })} aria-invalid={errors.base_price_credit ? true : undefined} className={inputClass(!!errors.base_price_credit)} {...form.register('base_price_credit')} />
              {errors.base_price_credit ? <p role="alert" className="mt-0.5 text-xs text-red-700">{errors.base_price_credit.message}</p> : <Preview value={baseCredit} />}
            </td>
            <td className="py-2 pr-2">
              <input aria-label={t('catalog.units.barcodeOf', { unit: base })} aria-invalid={errors.base_barcode ? true : undefined} className={inputClass(!!errors.base_barcode)} {...form.register('base_barcode')} />
              {errors.base_barcode && <p role="alert" className="mt-0.5 text-xs text-red-700">{errors.base_barcode.message}</p>}
            </td>
            <td />
          </tr>

          {fields.map((field, index) => {
            const rowErrors = errors.extra_units?.[index]
            const current = extra?.[index]
            const label = unitName(current?.unit_id ?? '') || String(index + 1)

            return (
              <tr key={field.id}>
                <td className="py-2 pr-2">
                  <select aria-label={t('catalog.units.unitOf', { n: index + 2 })} aria-invalid={rowErrors?.unit_id ? true : undefined} className={`${inputClass(!!rowErrors?.unit_id)} bg-white`} {...form.register(`extra_units.${index}.unit_id`)}>
                    <option value="">{t('catalog.form.choose')}</option>
                    {unitOptions.filter((u) => !usedIds.has(u.id) || u.id === current?.unit_id).map((u) => (
                      <option key={u.id} value={u.id}>{u.name}</option>
                    ))}
                  </select>
                  {rowErrors?.unit_id && <p role="alert" className="mt-0.5 text-xs text-red-700">{rowErrors.unit_id.message}</p>}
                </td>
                <td className="py-2 pr-2">
                  <input inputMode="decimal" aria-label={t('catalog.units.factorOf', { unit: label })} aria-invalid={rowErrors?.factor ? true : undefined} className={inputClass(!!rowErrors?.factor)} {...form.register(`extra_units.${index}.factor`)} />
                  {rowErrors?.factor ? <p role="alert" className="mt-0.5 text-xs text-red-700">{rowErrors.factor.message}</p> : <p className="mt-0.5 h-4 text-xs text-slate-500">{current?.unit_id ? t('catalog.units.equals', { unit: label, factor: current.factor || '?', base }) : ''}</p>}
                </td>
                <td className="py-2 pr-2">
                  <input inputMode="decimal" aria-label={t('catalog.units.priceCashOf', { unit: label })} aria-invalid={rowErrors?.price_cash ? true : undefined} className={inputClass(!!rowErrors?.price_cash)} {...form.register(`extra_units.${index}.price_cash`)} />
                  {rowErrors?.price_cash ? <p role="alert" className="mt-0.5 text-xs text-red-700">{rowErrors.price_cash.message}</p> : <Preview value={current?.price_cash ?? ''} />}
                </td>
                <td className="py-2 pr-2">
                  <input inputMode="decimal" aria-label={t('catalog.units.priceCreditOf', { unit: label })} aria-invalid={rowErrors?.price_credit ? true : undefined} className={inputClass(!!rowErrors?.price_credit)} {...form.register(`extra_units.${index}.price_credit`)} />
                  {rowErrors?.price_credit ? <p role="alert" className="mt-0.5 text-xs text-red-700">{rowErrors.price_credit.message}</p> : <Preview value={current?.price_credit ?? ''} />}
                </td>
                <td className="py-2 pr-2">
                  <input aria-label={t('catalog.units.barcodeOf', { unit: label })} aria-invalid={rowErrors?.barcode ? true : undefined} className={inputClass(!!rowErrors?.barcode)} {...form.register(`extra_units.${index}.barcode`)} />
                  {rowErrors?.barcode && <p role="alert" className="mt-0.5 text-xs text-red-700">{rowErrors.barcode.message}</p>}
                </td>
                <td className="py-2 text-right">
                  <IconButton label={t('catalog.units.remove', { unit: label })} text={t('common.delete')} onClick={() => remove(index)} tone="danger">
                    <Trash2 className="h-4 w-4" aria-hidden="true" />
                  </IconButton>
                </td>
              </tr>
            )
          })}
        </tbody>
      </DataTable>

      {errors.extra_units?.root?.message && <p role="alert" className="text-sm text-red-700">{errors.extra_units.root.message}</p>}
      {typeof errors.extra_units?.message === 'string' && <p role="alert" className="text-sm text-red-700">{errors.extra_units.message}</p>}

      <Button variant="secondary" onClick={() => append(emptyUnitRow())} disabled={!canAdd}>
        <Plus className="h-4 w-4" aria-hidden="true" />
        {t('catalog.units.add')}
      </Button>
      {!canAdd && <p className="text-xs text-slate-500">{t('catalog.form.tooManyUnits', { max: maxUnits })}</p>}
    </div>
  )
}
