import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { DialogSkeleton, DialogSubject, FormSection, RequiredNote, UnsavedChangesDialog } from '../../components/dialog-parts.tsx'
import { Alert, Button, Modal, ModalActions, SelectField, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { useDialog, type DialogProps } from '../../lib/dialog.ts'
import { formatQuantity, formatVnd } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useToast } from '../../lib/toast.ts'
import { useUnsavedGuard } from '../../lib/unsaved.ts'
import { PAYMENT_METHODS, useReturnSale, useSale, type Sale, type SaleLine } from './api.ts'
import { applyReturnErrors, pickedLines, returnable, returnPayload, returnPreview, returnSchema, todayIso, type ReturnFormValues } from './forms.ts'

const money = (value: number) => formatVnd(value)

/**
 * Popup khách trả hàng của một phiếu bán (`?dialog=sale-return:<id phiếu>`): nhập số lượng trả cho từng dòng (tối đa số còn trả được). Hàng nhập lại kho (đúng lô, giá vốn lúc bán);
 * giá trị hàng trả trừ vào nợ còn lại của phiếu trước, phần vượt (khách đã trả) hoàn lại cho khách bằng tiền mặt hoặc chuyển khoản. Bảng kết quả cập nhật ngay khi gõ.
 */
export default function SaleReturnDialog({ arg: id, onClose }: DialogProps) {
  const { t } = useTranslation()
  const sale = useSale(id)
  const data = sale.data
  const notFound = sale.error instanceof ApiError && (sale.error.status === 404 || sale.error.status === 403)

  return (
    <Modal size="lg" fixedHeight dismissOnBackdrop={false} onClose={onClose} title={t('sales.return.title')} description={<DialogSubject name={data ? `${data.code} · ${data.customer?.name ?? t('sales.walkIn')}` : undefined} />}>
      {sale.isError ? (
        <div className="space-y-4">
          <Alert tone="danger">{notFound ? t('sales.detail.notFound') : t('common.loadError')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : !data ? (
        <DialogSkeleton fields={4} />
      ) : (data.lines ?? []).every((line) => returnable(line) <= 0) ? (
        <div className="space-y-4">
          <Alert tone="info">{t('sales.return.none')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : (
        <ReturnForm sale={data} />
      )}
    </Modal>
  )
}

function ReturnForm({ sale }: { sale: Sale }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { replace } = useDialog()
  const giveBack = useReturnSale(sale.id)
  const [formError, setFormError] = useState<string | null>(null)
  const open = useMemo<SaleLine[]>(() => (sale.lines ?? []).filter((line) => returnable(line) > 0), [sale.lines])
  const schema = useMemo(() => returnSchema(t, open.map((line) => ({ max: returnable(line), unit: line.unit }))), [t, open])

  const form = useForm<ReturnFormValues>({
    resolver: zodResolver(schema),
    defaultValues: { lines: open.map(() => ({ quantity: '' })), returned_on: todayIso(), refund_method: 'cash', note: '' },
  })
  const errors = form.formState.errors
  const guard = useUnsavedGuard(form.formState.isDirty)
  const watched = useWatch({ control: form.control, name: 'lines' })
  const preview = useMemo(() => returnPreview(sale, open, { lines: watched ?? [] }), [sale, open, watched])
  const any = preview.value > 0 || (watched ?? []).some((line) => (Number(line.quantity.replace(',', '.')) || 0) > 0)

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    const picked = pickedLines(values)

    if (picked.length === 0) {
      setFormError(t('sales.return.pickSome'))

      return
    }

    try {
      const result = await giveBack.mutateAsync(returnPayload(values, open, preview.refund))
      toast.success(t('sales.return.done', { code: result.returns?.at(-1)?.code ?? sale.code }))
      guard.release()
      replace('sale', sale.id)
    } catch (error) {
      const { handled, leftover } = applyReturnErrors(error, form.setError, picked.map(({ index }) => index))

      if (!handled) setFormError(describeError(error, t))
      else if (leftover.length > 0) setFormError(leftover.join(' '))
    }
  })

  return (
    <form onSubmit={submit} noValidate className="flex min-h-full flex-col gap-5">
      <p className="text-sm text-slate-600">{t('sales.return.intro')}</p>
      {formError && <Alert tone="danger">{formError}</Alert>}
      <RequiredNote />

      <FormSection title={t('sales.return.lines')}>
        <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
          {open.map((line, index) => (
            <li key={line.id} className="grid gap-3 p-3 sm:grid-cols-[1fr_11rem] sm:items-start">
              <div className="min-w-0 text-sm">
                <p><span className="font-mono text-xs">{line.product.sku}</span> <span className="font-medium text-slate-900">{line.product.name}</span></p>
                <p className="text-xs text-slate-500">
                  {line.batch && `${line.batch.batch_no} · `}
                  {t('sales.return.available', { sold: formatQuantity(line.quantity), returned: formatQuantity(line.returned_quantity), left: formatQuantity(String(returnable(line))), unit: line.unit })}
                </p>
                <p className="text-xs text-slate-500">{money(Number(line.unit_price))} / {line.unit}</p>
              </div>
              <TextField label={t('sales.return.quantity')} inputMode="decimal" autoComplete="off" error={errors.lines?.[index]?.quantity?.message} {...form.register(`lines.${index}.quantity`)} />
            </li>
          ))}
        </ul>
      </FormSection>

      <dl className="grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-3" aria-label={t('sales.return.summary.label')}>
        {any ? (
          <>
            <div><dt className="text-slate-500">{t('sales.return.summary.goods')}</dt><dd className="font-semibold text-slate-900">{money(preview.value)}</dd></div>
            <div><dt className="text-slate-500">{t('sales.return.summary.debt')}</dt><dd className="font-semibold text-slate-900">{money(preview.debt)}</dd></div>
            <div><dt className="text-slate-500">{t('sales.return.summary.refund')}</dt><dd className={`font-semibold ${preview.refund > 0 ? 'text-brand-800' : 'text-slate-900'}`}>{money(preview.refund)}</dd></div>
          </>
        ) : (
          <p className="text-slate-500 sm:col-span-3">{t('sales.return.summary.empty')}</p>
        )}
      </dl>

      <div className="grid gap-4 sm:grid-cols-2">
        <TextField required label={t('sales.return.returnedOn')} type="date" max={todayIso()} error={errors.returned_on?.message} {...form.register('returned_on')} />
        {preview.refund > 0 && (
          <SelectField required label={t('sales.return.refundMethod')} error={errors.refund_method?.message} {...form.register('refund_method')}>
            {PAYMENT_METHODS.map((method) => <option key={method} value={method}>{t(`sales.methods.${method}`)}</option>)}
          </SelectField>
        )}
      </div>
      <TextField label={t('sales.return.note')} error={errors.note?.message} autoComplete="off" {...form.register('note')} />

      <ModalActions>
        <Button variant="ghost" onClick={() => replace('sale', sale.id)}>{t('common.cancel')}</Button>
        <Button type="submit" loading={giveBack.isPending}>{giveBack.isPending ? t('common.saving') : t('sales.return.submit')}</Button>
      </ModalActions>

      <UnsavedChangesDialog guard={guard} />
    </form>
  )
}
