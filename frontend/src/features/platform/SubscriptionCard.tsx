import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Card, Spinner } from '../../components/ui.tsx'
import { formatDate, formatDateTime, formatVnd } from '../../lib/format.ts'
import { ApiError } from '../../lib/api.ts'
import { useRecordContact, useSubscription } from './api.ts'
import { ReasonDialog } from './ReasonDialog.tsx'
import { ConfigureDialog, ReopenDialog, RenewDialog } from './SubscriptionDialogs.tsx'
import { daysLabel } from './labels.ts'
import { SubscriptionBadge } from './SubscriptionBadge.tsx'

type Dialog = 'renew' | 'reopen' | 'configure' | 'contact' | null

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-0.5 text-sm text-slate-900">{children}</dd>
    </div>
  )
}

/** Thuê bao của một doanh nghiệp: trạng thái, gói, kỳ hiện tại, việc cần làm (gia hạn, mở lại, đổi gói, ghi nhận liên hệ) và lịch sử. */
export function SubscriptionCard({ tenantId }: { tenantId: string }) {
  const { t } = useTranslation()
  const subscription = useSubscription(tenantId)
  const contact = useRecordContact(tenantId)
  const [dialog, setDialog] = useState<Dialog>(null)
  const close = () => setDialog(null)

  if (subscription.isPending) {
    return (
      <Card title={t('platform.subscription.title')}>
        <div className="flex justify-center py-4"><Spinner /></div>
      </Card>
    )
  }

  if (subscription.isError) {
    const none = subscription.error instanceof ApiError && subscription.error.status === 404

    return (
      <Card title={t('platform.subscription.title')}>
        <Alert tone={none ? 'info' : 'danger'}>{none ? t('platform.subscription.none') : t('common.loadError')}</Alert>
      </Card>
    )
  }

  const s = subscription.data
  const cycle = t(`platform.subscription.cycles.${s.billing_cycle}`)
  const needsAttention = s.state === 'expired' || s.state === 'grace' || s.state === 'reopened'

  return (
    <Card
      title={t('platform.subscription.title')}
      actions={
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => setDialog('renew')}>{t('platform.subscription.renew')}</Button>
          {needsAttention && <Button variant="secondary" onClick={() => setDialog('reopen')}>{t('platform.subscription.reopen')}</Button>}
          <Button variant="secondary" onClick={() => setDialog('configure')}>{t('platform.subscription.configure')}</Button>
          <Button variant="secondary" onClick={() => setDialog('contact')}>{t('platform.subscription.contact')}</Button>
        </div>
      }
    >
      <div className="space-y-4">
        {s.state === 'expired' && <Alert tone="danger">{t('platform.subscription.blockedNote')}</Alert>}
        {s.state === 'reopened' && s.reopened_until && (
          <Alert tone="warning">{t('platform.subscription.reopenedNote', { date: formatDate(s.reopened_until), reason: s.reopen_reason ?? '—' })}</Alert>
        )}

        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label={t('platform.subscription.status')}>
            <SubscriptionBadge state={s.state} /> <span className="text-slate-600">{daysLabel(s.days_left, t)}</span>
          </Field>
          <Field label={t('platform.subscription.plan')}>{s.plan.name} · {cycle}</Field>
          <Field label={t('platform.subscription.period')}>{formatDate(s.period_start)} → {formatDate(s.period_end)}</Field>
          <Field label={t('platform.subscription.seats')}>{t('platform.subscription.seatsUsed', { used: s.usage.users, seats: s.seats })}</Field>
          <Field label={t('platform.subscription.branches')}>
            {s.max_branches === null ? t('platform.subscription.unlimited', { used: s.usage.branches }) : `${s.usage.branches}/${s.max_branches}`}
          </Field>
          <Field label={t('platform.subscription.unitPrice')}>{t('platform.subscription.perSeat', { price: formatVnd(s.unit_price), cycle })}</Field>
        </dl>

        {s.last_contacted_at && (
          <p className="text-sm text-slate-600">{t('platform.subscription.lastContact', { when: formatDateTime(s.last_contacted_at), note: s.contact_note ?? '—' })}</p>
        )}

        {s.periods && s.periods.length > 0 && (
          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-900">{t('platform.subscription.history')}</h3>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[40rem] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="py-2 pr-3">{t('platform.subscription.kindLabel')}</th>
                    <th scope="col" className="py-2 pr-3">{t('platform.subscription.period')}</th>
                    <th scope="col" className="py-2 pr-3">{t('platform.subscription.plan')}</th>
                    <th scope="col" className="py-2 pr-3 text-right">{t('platform.subscription.amount')}</th>
                    <th scope="col" className="py-2">{t('platform.subscription.paymentMethod')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {s.periods.map((period) => (
                    <tr key={period.id}>
                      <td className="py-2 pr-3">{t(`platform.subscription.kind.${period.kind}`)}</td>
                      <td className="py-2 pr-3 whitespace-nowrap">{formatDate(period.period_start)} → {formatDate(period.period_end)}</td>
                      <td className="py-2 pr-3">{period.plan} · {t(`platform.subscription.cycles.${period.billing_cycle}`)}</td>
                      <td className="py-2 pr-3 text-right whitespace-nowrap">{period.kind === 'change' ? '—' : formatVnd(period.amount)}</td>
                      <td className="py-2">
                        {period.payment_method ? t(`platform.subscription.methods.${period.payment_method}`, { defaultValue: period.payment_method }) : '—'}
                        {period.payment_ref && <span className="block text-xs text-slate-500">{period.payment_ref}</span>}
                        {period.note && <span className="block text-xs text-slate-500">{period.note}</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      {dialog === 'renew' && <RenewDialog tenantId={tenantId} subscription={s} onClose={close} />}
      {dialog === 'reopen' && <ReopenDialog tenantId={tenantId} onClose={close} />}
      {dialog === 'configure' && <ConfigureDialog tenantId={tenantId} subscription={s} onClose={close} />}
      {dialog === 'contact' && (
        <ReasonDialog
          title={t('platform.subscription.contactTitle')}
          intro={t('platform.subscription.contactIntro')}
          label={t('platform.subscription.contactNote')}
          minLength={3}
          submitLabel={t('common.save')}
          onSubmit={(note) => contact.mutateAsync(note)}
          onClose={close}
        />
      )}
    </Card>
  )
}
