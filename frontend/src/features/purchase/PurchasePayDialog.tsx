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
import { PAYMENT_METHODS, usePayPurchase, usePurchase, type Purchase } from './api.ts'
import { paymentPayload, paymentSchema, todayIso, type PaymentFormValues } from './forms.ts'

/**
 * Popup ghi một lần trả tiền nhà cung cấp (`?dialog=purchase-pay:<id>`). Số tiền mặc định là số còn nợ (trả đủ), sửa nhỏ hơn nếu trả một phần;
 * không được vượt số còn nợ. Ghi xong quay lại chi tiết phiếu.
 */
export default function PurchasePayDialog({ arg: id, onClose }: DialogProps) {
  const { t } = useTranslation()
  const purchase = usePurchase(id)
  const data = purchase.data
  const notFound = purchase.error instanceof ApiError && (purchase.error.status === 404 || purchase.error.status === 403)

  return (
    <Modal size="md" dismissOnBackdrop={false} onClose={onClose} title={t('purchase.pay.title')} description={<DialogSubject name={data ? `${data.code} · ${data.supplier.name}` : undefined} />}>
      {purchase.isError ? (
        <div className="space-y-4">
          <Alert tone="danger">{notFound ? t('purchase.detail.notFound') : t('common.loadError')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : !data ? (
        <DialogSkeleton fields={3} />
      ) : (
        <PayForm purchase={data} onClose={onClose} />
      )}
    </Modal>
  )
}

function PayForm({ purchase, onClose }: { purchase: Purchase; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { replace } = useDialog()
  const pay = usePayPurchase(purchase.id)
  const [formError, setFormError] = useState<string | null>(null)
  const balance = Number(purchase.balance)
  const schema = useMemo(() => paymentSchema(t, balance), [t, balance])

  const form = useForm<PaymentFormValues>({ resolver: zodResolver(schema), defaultValues: { amount: String(balance), method: 'cash', paid_on: todayIso(), note: '' } })
  const errors = form.formState.errors
  const guard = useUnsavedGuard(form.formState.isDirty)

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await pay.mutateAsync(paymentPayload(values))
      toast.success(t('purchase.pay.done', { code: purchase.code }))
      guard.release()
      replace('purchase', purchase.id)
    } catch (error) {
      if (!applyServerErrors(error, form.setError, ['amount', 'method', 'paid_on', 'note'])) setFormError(describeError(error, t))
    }
  })

  if (balance <= 0) {
    return (
      <div className="space-y-4">
        <Alert tone="info">{t('purchase.pay.settled')}</Alert>
        <div className="flex justify-end">
          <Button onClick={onClose}>{t('common.close')}</Button>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      {formError && <Alert tone="danger">{formError}</Alert>}

      <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{t('purchase.pay.owed', { amount: formatVnd(balance) })}</p>

      <form onSubmit={submit} noValidate className="flex flex-col gap-4">
        <RequiredNote />
        <TextField required label={t('purchase.pay.amount')} error={errors.amount?.message} inputMode="decimal" autoComplete="off" {...form.register('amount')} />
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField required label={t('purchase.form.method')} error={errors.method?.message} {...form.register('method')}>
            {PAYMENT_METHODS.map((method) => <option key={method} value={method}>{t(`purchase.methods.${method}`)}</option>)}
          </SelectField>
          <TextField required label={t('purchase.pay.paidOn')} type="date" max={todayIso()} error={errors.paid_on?.message} {...form.register('paid_on')} />
        </div>
        <TextField label={t('purchase.pay.note')} error={errors.note?.message} autoComplete="off" {...form.register('note')} />

        <ModalActions>
          <Button variant="ghost" onClick={() => replace('purchase', purchase.id)}>{t('common.cancel')}</Button>
          <Button type="submit" loading={pay.isPending}>{pay.isPending ? t('common.saving') : t('purchase.pay.submit')}</Button>
        </ModalActions>
      </form>

      <UnsavedChangesDialog guard={guard} />
    </div>
  )
}
