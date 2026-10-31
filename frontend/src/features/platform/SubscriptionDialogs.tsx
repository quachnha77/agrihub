import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { FieldsSkeleton } from '../../components/skeleton.tsx'
import { Alert, Button, Modal, TextField } from '../../components/ui.tsx'
import { formatVnd } from '../../lib/format.ts'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { useConfigureSubscription, usePlans, useReopenSubscription, useRenewSubscription, type BillingCycle, type SubscriptionDetail } from './api.ts'

const PAYMENT_METHODS = ['bank_transfer', 'momo', 'cash', 'other'] as const
const MAX_PERIODS = 24
const MAX_REOPEN_DAYS = 30

const SELECT_CLASS = 'block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm'
const optionalInt = (value: string) => (value.trim() === '' ? undefined : Number(value))
const isMoney = (value: string) => value.trim() === '' || (/^\d+$/.test(value.trim()) && Number(value) <= 100_000_000_000)

/** Ghi nhận gia hạn sau khi khách đã thanh toán (chưa có cổng thanh toán tự động). */
export function RenewDialog({ tenantId, subscription, onClose }: { tenantId: string; subscription: SubscriptionDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const renew = useRenewSubscription(tenantId)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        periods: z.string().refine((v) => /^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= MAX_PERIODS, t('platform.subscription.periodsInvalid', { max: MAX_PERIODS })),
        amount: z.string().refine(isMoney, t('platform.subscription.moneyInvalid')),
        paid_at: z.string(),
        payment_method: z.string(),
        payment_ref: z.string().max(100),
        note: z.string().max(500),
      }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { periods: '1', amount: '', paid_at: '', payment_method: 'bank_transfer', payment_ref: '', note: '' } })
  const errors = form.formState.errors
  const periods = useWatch({ control: form.control, name: 'periods' })
  const suggested = subscription.unit_price * subscription.seats * (/^\d+$/.test(periods) ? Number(periods) : 1)

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await renew.mutateAsync({
        periods: Number(values.periods),
        amount: optionalInt(values.amount),
        paid_at: values.paid_at || undefined,
        payment_method: values.payment_method || undefined,
        payment_ref: values.payment_ref.trim() || undefined,
        note: values.note.trim() || undefined,
      })
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, form.setError, ['periods', 'amount', 'paid_at', 'payment_method', 'payment_ref', 'note'])) setFormError(describeError(error, t))
    }
  })

  return (
    <Modal title={t('platform.subscription.renewTitle')} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        <p className="text-sm text-slate-600">{t(subscription.blocked ? 'platform.subscription.renewFromToday' : 'platform.subscription.renewFromEnd')}</p>
        {formError && <Alert tone="danger">{formError}</Alert>}
        <TextField label={t(`platform.subscription.periods.${subscription.billing_cycle}`)} type="number" min={1} max={MAX_PERIODS} error={errors.periods?.message} {...form.register('periods')} />
        <TextField
          label={t('platform.subscription.amount')}
          type="number"
          min={0}
          hint={t('platform.subscription.amountHint', { amount: formatVnd(suggested) })}
          error={errors.amount?.message}
          {...form.register('amount')}
        />
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <label htmlFor="renew-method" className="mb-1 block text-sm font-medium text-slate-800">{t('platform.subscription.paymentMethod')}</label>
            <select id="renew-method" className={SELECT_CLASS} {...form.register('payment_method')}>
              {PAYMENT_METHODS.map((method) => (
                <option key={method} value={method}>{t(`platform.subscription.methods.${method}`)}</option>
              ))}
            </select>
          </div>
          <TextField label={t('platform.subscription.paidAt')} type="date" error={errors.paid_at?.message} {...form.register('paid_at')} />
        </div>
        <TextField label={t('platform.subscription.paymentRef')} error={errors.payment_ref?.message} {...form.register('payment_ref')} />
        <TextField label={t('platform.subscription.note')} error={errors.note?.message} {...form.register('note')} />
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={renew.isPending}>{t('platform.subscription.renewSubmit')}</Button>
        </div>
      </form>
    </Modal>
  )
}

/** Mở lại tạm thời cho doanh nghiệp đã hết hạn (bắt buộc có lý do; không đổi ngày hết hạn). */
export function ReopenDialog({ tenantId, onClose }: { tenantId: string; onClose: () => void }) {
  const { t } = useTranslation()
  const reopen = useReopenSubscription(tenantId)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        days: z.string().refine((v) => /^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= MAX_REOPEN_DAYS, t('platform.subscription.daysInvalid', { max: MAX_REOPEN_DAYS })),
        reason: z.string().trim().min(10, t('validation.minLength', { count: 10 })).max(500),
      }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { days: '7', reason: '' } })
  const errors = form.formState.errors

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await reopen.mutateAsync({ days: Number(values.days), reason: values.reason })
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, form.setError, ['days', 'reason'])) setFormError(describeError(error, t))
    }
  })

  return (
    <Modal title={t('platform.subscription.reopenTitle')} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        <p className="text-sm text-slate-600">{t('platform.subscription.reopenIntro')}</p>
        {formError && <Alert tone="danger">{formError}</Alert>}
        <TextField label={t('platform.subscription.reopenDays')} type="number" min={1} max={MAX_REOPEN_DAYS} error={errors.days?.message} {...form.register('days')} />
        <TextField label={t('platform.subscription.reopenReason')} error={errors.reason?.message} {...form.register('reason')} />
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={reopen.isPending}>{t('platform.subscription.reopenSubmit')}</Button>
        </div>
      </form>
    </Modal>
  )
}

/** Đổi gói / chu kỳ / số người dùng / đơn giá riêng. Có hiệu lực ngay, không đổi ngày hết hạn và không tính tiền. */
export function ConfigureDialog({ tenantId, subscription, onClose }: { tenantId: string; subscription: SubscriptionDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const plans = usePlans()
  const configure = useConfigureSubscription(tenantId)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        plan_id: z.string(),
        billing_cycle: z.enum(['monthly', 'yearly']),
        seats: z.string().refine((v) => /^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= 100000, t('platform.subscription.seatsInvalid')),
        unit_price: z.string().refine(isMoney, t('platform.subscription.moneyInvalid')),
      }),
    [t],
  )
  type Values = z.infer<typeof schema>

  const currentPlanId = plans.data?.find((plan) => plan.key === subscription.plan.key)?.id ?? ''
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    values: { plan_id: currentPlanId, billing_cycle: subscription.billing_cycle, seats: String(subscription.seats), unit_price: '' },
  })
  const errors = form.formState.errors

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await configure.mutateAsync({
        plan_id: values.plan_id !== currentPlanId ? values.plan_id : undefined,
        billing_cycle: values.billing_cycle !== subscription.billing_cycle ? (values.billing_cycle as BillingCycle) : undefined,
        seats: Number(values.seats) !== subscription.seats ? Number(values.seats) : undefined,
        unit_price: optionalInt(values.unit_price),
      })
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, form.setError, ['plan_id', 'billing_cycle', 'seats', 'unit_price'])) setFormError(describeError(error, t))
    }
  })

  return (
    <Modal title={t('platform.subscription.configureTitle')} onClose={onClose}>
      {plans.isPending ? (
        <FieldsSkeleton fields={4} columns={1} />
      ) : (
        <form onSubmit={submit} noValidate className="space-y-4">
          <p className="text-sm text-slate-600">{t('platform.subscription.configureIntro')}</p>
          {formError && <Alert tone="danger">{formError}</Alert>}
          <div>
            <label htmlFor="cfg-plan" className="mb-1 block text-sm font-medium text-slate-800">{t('platform.subscription.plan')}</label>
            <select id="cfg-plan" className={SELECT_CLASS} {...form.register('plan_id')}>
              {(plans.data ?? [])
                .filter((plan) => plan.is_active || plan.id === currentPlanId)
                .map((plan) => (
                  <option key={plan.id} value={plan.id}>{plan.name}</option>
                ))}
            </select>
            {errors.plan_id?.message && <p role="alert" className="mt-1 text-sm text-red-700">{errors.plan_id.message}</p>}
          </div>
          <div>
            <label htmlFor="cfg-cycle" className="mb-1 block text-sm font-medium text-slate-800">{t('platform.subscription.cycle')}</label>
            <select id="cfg-cycle" className={SELECT_CLASS} {...form.register('billing_cycle')}>
              <option value="monthly">{t('platform.subscription.cycles.monthly')}</option>
              <option value="yearly">{t('platform.subscription.cycles.yearly')}</option>
            </select>
          </div>
          <TextField label={t('platform.subscription.seats')} type="number" min={1} hint={t('platform.subscription.seatsHint', { users: subscription.usage.users })} error={errors.seats?.message} {...form.register('seats')} />
          <TextField label={t('platform.subscription.unitPriceOverride')} type="number" min={0} hint={t('platform.subscription.unitPriceHint', { price: formatVnd(subscription.unit_price) })} error={errors.unit_price?.message} {...form.register('unit_price')} />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
            <Button type="submit" loading={configure.isPending}>{t('common.save')}</Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
