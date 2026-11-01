import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { DialogSkeleton, DialogSubject, RequiredNote, UnsavedChangesDialog } from '../../components/dialog-parts.tsx'
import { Alert, Button, Modal, ModalActions, SelectField, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { useDialog, type DialogProps } from '../../lib/dialog.ts'
import { formatVnd } from '../../lib/format.ts'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { useToast } from '../../lib/toast.ts'
import { useUnsavedGuard } from '../../lib/unsaved.ts'
import { PAYMENT_METHODS, useCollectSale, useSale, type Sale } from './api.ts'
import { paymentPayload, paymentSchema, todayIso, type PaymentFormValues } from './forms.ts'

/**
 * Popup ghi một lần thu tiền của khách (`?dialog=sale-pay:<id>`). Số tiền mặc định là số khách còn nợ (thu đủ), sửa nhỏ hơn nếu khách trả một phần;
 * không được vượt số còn nợ. Ghi xong quay lại chi tiết phiếu.
 */
export default function SalePayDialog({ arg: id, onClose }: DialogProps) {
  const { t } = useTranslation()
  const sale = useSale(id)
  const data = sale.data
  const notFound = sale.error instanceof ApiError && (sale.error.status === 404 || sale.error.status === 403)

  return (
    <Modal size="md" dismissOnBackdrop={false} onClose={onClose} title={t('sales.pay.title')} description={<DialogSubject name={data ? `${data.code} · ${data.customer?.name ?? t('sales.walkIn')}` : undefined} />}>
      {sale.isError ? (
        <div className="space-y-4">
          <Alert tone="danger">{notFound ? t('sales.detail.notFound') : t('common.loadError')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : !data ? (
        <DialogSkeleton fields={3} />
      ) : (
        <PayForm sale={data} onClose={onClose} />
      )}
    </Modal>
  )
}

function PayForm({ sale, onClose }: { sale: Sale; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { replace } = useDialog()
  const collect = useCollectSale(sale.id)
  const [formError, setFormError] = useState<string | null>(null)
  const balance = Number(sale.balance)
  const schema = useMemo(() => paymentSchema(t, balance), [t, balance])

  const form = useForm<PaymentFormValues>({ resolver: zodResolver(schema), defaultValues: { amount: String(balance), method: 'cash', paid_on: todayIso(), note: '' } })
  const errors = form.formState.errors
  const guard = useUnsavedGuard(form.formState.isDirty)

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await collect.mutateAsync(paymentPayload(values))
      toast.success(t('sales.pay.done', { code: sale.code }))
      guard.release()
      replace('sale', sale.id)
    } catch (error) {
      if (!applyServerErrors(error, form.setError, ['amount', 'method', 'paid_on', 'note'])) setFormError(describeError(error, t))
    }
  })

  if (balance <= 0) {
    return (
      <div className="space-y-4">
        <Alert tone="info">{t('sales.pay.settled')}</Alert>
        <div className="flex justify-end">
          <Button onClick={onClose}>{t('common.close')}</Button>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      {formError && <Alert tone="danger">{formError}</Alert>}

      <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{t('sales.pay.owed', { amount: formatVnd(balance) })}</p>

      <form onSubmit={submit} noValidate className="flex flex-col gap-4">
        <RequiredNote />
        <TextField required label={t('sales.pay.amount')} error={errors.amount?.message} inputMode="decimal" autoComplete="off" {...form.register('amount')} />
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField required label={t('sales.form.method')} error={errors.method?.message} {...form.register('method')}>
            {PAYMENT_METHODS.map((method) => <option key={method} value={method}>{t(`sales.methods.${method}`)}</option>)}
          </SelectField>
          <TextField required label={t('sales.pay.paidOn')} type="date" max={todayIso()} error={errors.paid_on?.message} {...form.register('paid_on')} />
        </div>
        <TextField label={t('sales.pay.note')} error={errors.note?.message} autoComplete="off" {...form.register('note')} />

        <ModalActions>
          <Button variant="ghost" onClick={() => replace('sale', sale.id)}>{t('common.cancel')}</Button>
          <Button type="submit" loading={collect.isPending}>{collect.isPending ? t('common.saving') : t('sales.pay.submit')}</Button>
        </ModalActions>
      </form>

      <UnsavedChangesDialog guard={guard} />
    </div>
  )
}
