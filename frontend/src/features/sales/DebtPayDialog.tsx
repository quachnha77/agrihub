import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { DialogSkeleton, DialogSubject, FormSection, RequiredNote, UnsavedChangesDialog } from '../../components/dialog-parts.tsx'
import { Alert, Badge, Button, Modal, ModalActions, SelectField, TextField } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { type DialogProps } from '../../lib/dialog.ts'
import { formatIsoDate, formatVnd } from '../../lib/format.ts'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { useToast } from '../../lib/toast.ts'
import { useUnsavedGuard } from '../../lib/unsaved.ts'
import { parseNumber } from '../catalog/form.ts'
import { PAYMENT_METHODS, useCollectReceivable, useCustomerOpenSales, type Sale } from './api.ts'
import { allocateDebt, paymentPayload, paymentSchema, sortByDueFirst, todayIso, type PaymentFormValues } from './forms.ts'

const money = (value: number) => formatVnd(value)

/**
 * Popup thu nợ của một khách (`?dialog=debt-pay:<id khách>`): một khoản tiền được trừ vào nhiều phiếu, phiếu đến hạn sớm nhất trước. Số tiền mặc định là toàn bộ số khách còn nợ;
 * nhập nhỏ hơn nếu khách trả một phần. Bảng "Tiền sẽ trừ vào" cập nhật ngay khi gõ để người thu thấy trước kết quả.
 */
export default function DebtPayDialog({ arg: customerId, onClose }: DialogProps) {
  const { t } = useTranslation()
  const sales = useCustomerOpenSales(customerId)
  const open = useMemo(() => sortByDueFirst(sales.data ?? []), [sales.data])
  const name = open[0]?.customer?.name
  const notFound = sales.error instanceof ApiError && (sales.error.status === 404 || sales.error.status === 403)

  return (
    <Modal size="lg" dismissOnBackdrop={false} onClose={onClose} title={t('sales.debts.pay.title')} description={<DialogSubject name={name} />}>
      {sales.isError ? (
        <div className="space-y-4">
          <Alert tone="danger">{notFound ? t('sales.debts.pay.notFound') : t('common.loadError')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : !sales.data || customerId === undefined ? (
        <DialogSkeleton fields={3} />
      ) : open.length === 0 ? (
        <div className="space-y-4">
          <Alert tone="info">{t('sales.debts.pay.settled')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : (
        <PayForm customerId={customerId} open={open} onClose={onClose} />
      )}
    </Modal>
  )
}

function PayForm({ customerId, open, onClose }: { customerId: string; open: Sale[]; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const collect = useCollectReceivable(customerId)
  const [formError, setFormError] = useState<string | null>(null)
  const owed = useMemo(() => open.reduce((sum, sale) => sum + Number(sale.balance), 0), [open])
  const schema = useMemo(() => paymentSchema(t, owed), [t, owed])

  const form = useForm<PaymentFormValues>({ resolver: zodResolver(schema), defaultValues: { amount: String(owed), method: 'cash', paid_on: todayIso(), note: '' } })
  const errors = form.formState.errors
  const guard = useUnsavedGuard(form.formState.isDirty)
  const typed = parseNumber(useWatch({ control: form.control, name: 'amount' }) ?? '')
  const plan = useMemo(() => allocateDebt(open, typed !== null && typed > 0 ? Math.min(typed, owed) : 0), [open, typed, owed])

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      const result = await collect.mutateAsync(paymentPayload(values))
      toast.success(t('sales.debts.pay.done', { amount: formatVnd(Number(result.collected)), count: result.allocations.length }))
      guard.release()
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, form.setError, ['amount', 'method', 'paid_on', 'note'])) setFormError(describeError(error, t))
    }
  })

  return (
    <div className="flex flex-col gap-4">
      {formError && <Alert tone="danger">{formError}</Alert>}

      <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{t('sales.debts.pay.owed', { amount: money(owed), count: open.length })}</p>

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

        <FormSection title={t('sales.debts.pay.allocation')} description={t('sales.debts.pay.allocationHint')}>
          <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200" aria-label={t('sales.debts.pay.allocation')}>
            {plan.map(({ sale, take }) => (
              <li key={sale.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3 py-2.5 text-sm">
                <span>
                  <span className="font-mono text-xs font-semibold text-slate-900">{sale.code}</span>
                  <span className="ml-2 text-xs text-slate-500">{sale.due_on ? t('sales.debts.pay.dueOn', { date: formatIsoDate(sale.due_on) }) : t('sales.debts.pay.noDue')}</span>
                  {sale.is_overdue && <span className="ml-2"><Badge tone="danger" dot>{t('sales.status.overdue')}</Badge></span>}
                </span>
                <span className="tabular-nums text-slate-600">
                  {money(Number(sale.balance))}
                  <span className={`ml-3 font-semibold ${take > 0 ? 'text-brand-800' : 'text-slate-400'}`}>{take > 0 ? `− ${money(take)}` : '—'}</span>
                </span>
              </li>
            ))}
          </ul>
        </FormSection>

        <ModalActions>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={collect.isPending}>{collect.isPending ? t('common.saving') : t('sales.debts.pay.submit')}</Button>
        </ModalActions>
      </form>

      <UnsavedChangesDialog guard={guard} />
    </div>
  )
}
