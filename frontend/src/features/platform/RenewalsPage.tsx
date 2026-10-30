import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Alert, Button, Card, Pagination, Spinner } from '../../components/ui.tsx'
import { formatDate, formatDateTime } from '../../lib/format.ts'
import { useRecordContact, useRenewals, type Renewal } from './api.ts'
import { ReasonDialog } from './ReasonDialog.tsx'
import { daysLabel } from './labels.ts'
import { SubscriptionBadge } from './SubscriptionBadge.tsx'

/** Hộp thoại ghi nhận liên hệ cho đúng một doanh nghiệp (hook cần mã doanh nghiệp nên tách riêng). */
function ContactDialog({ renewal, onClose }: { renewal: Renewal; onClose: () => void }) {
  const { t } = useTranslation()
  const contact = useRecordContact(renewal.tenant.id)

  return (
    <ReasonDialog
      title={t('platform.subscription.contactTitle')}
      intro={t('platform.renewals.contactIntro', { name: renewal.tenant.name })}
      label={t('platform.subscription.contactNote')}
      minLength={3}
      submitLabel={t('common.save')}
      onSubmit={(note) => contact.mutateAsync(note)}
      onClose={onClose}
    />
  )
}

/** "Việc cần liên hệ": doanh nghiệp sắp hết hạn hoặc đã hết hạn, hạn gần nhất lên trước. Ghi nhận đã gọi, rồi vào chi tiết để gia hạn. */
export default function RenewalsPage() {
  const { t } = useTranslation()
  const [withinDays, setWithinDays] = useState(15)
  const [contacted, setContacted] = useState<'' | 'yes' | 'no'>('')
  const [page, setPage] = useState(1)
  const renewals = useRenewals({ withinDays, contacted, page })
  const [contacting, setContacting] = useState<Renewal | null>(null)

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('platform.renewals.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('platform.renewals.intro')}</p>
      </div>

      <Card>
        <div className="mb-4 flex flex-wrap gap-2">
          <select
            value={withinDays}
            onChange={(event) => {
              setWithinDays(Number(event.target.value))
              setPage(1)
            }}
            aria-label={t('platform.renewals.within')}
            className="rounded-md border border-slate-300 px-3 py-2 text-sm"
          >
            {[15, 30, 60].map((days) => (
              <option key={days} value={days}>{t('platform.renewals.withinDays', { count: days })}</option>
            ))}
          </select>
          <select
            value={contacted}
            onChange={(event) => {
              setContacted(event.target.value as '' | 'yes' | 'no')
              setPage(1)
            }}
            aria-label={t('platform.renewals.contactedFilter')}
            className="rounded-md border border-slate-300 px-3 py-2 text-sm"
          >
            <option value="">{t('platform.renewals.contactedAll')}</option>
            <option value="no">{t('platform.renewals.contactedNo')}</option>
            <option value="yes">{t('platform.renewals.contactedYes')}</option>
          </select>
        </div>

        {renewals.isPending ? (
          <div className="flex justify-center py-6"><Spinner /></div>
        ) : renewals.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : renewals.data.data.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('platform.renewals.empty')}</p>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[56rem] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="py-2 pr-3">{t('platform.tenants.business')}</th>
                    <th scope="col" className="py-2 pr-3">{t('platform.renewals.contactPerson')}</th>
                    <th scope="col" className="py-2 pr-3">{t('platform.tenants.subscription')}</th>
                    <th scope="col" className="py-2 pr-3">{t('platform.renewals.lastContact')}</th>
                    <th scope="col" className="py-2 text-right">{t('backup.list.actions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {renewals.data.data.map((row) => {
                    const s = row.subscription

                    return (
                      <tr key={row.tenant.id}>
                        <td className="py-2 pr-3">
                          <Link to={`/platform/tenants/${row.tenant.id}`} className="font-medium text-emerald-800 hover:underline">{row.tenant.name}</Link>
                        </td>
                        <td className="py-2 pr-3">
                          {row.owner ? (
                            <>
                              <span className="block">{row.owner.name}</span>
                              <span className="block text-xs text-slate-500">{[row.owner.phone ?? row.tenant.phone, row.owner.email].filter(Boolean).join(' · ')}</span>
                            </>
                          ) : (
                            '—'
                          )}
                        </td>
                        <td className="py-2 pr-3">
                          <SubscriptionBadge state={s.state} />
                          <span className="block text-xs text-slate-500">{formatDate(s.period_end)} · {daysLabel(s.days_left, t)}</span>
                        </td>
                        <td className="py-2 pr-3">
                          {s.last_contacted_at ? (
                            <>
                              <span className="block whitespace-nowrap">{formatDateTime(s.last_contacted_at)}</span>
                              <span className="block text-xs text-slate-500">{s.contact_note}</span>
                            </>
                          ) : (
                            <span className="text-slate-500">{t('platform.renewals.notContacted')}</span>
                          )}
                        </td>
                        <td className="py-2 text-right">
                          <div className="flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setContacting(row)}>{t('platform.renewals.markContacted')}</Button>
                            <Link to={`/platform/tenants/${row.tenant.id}`} className="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-50">
                              {t('platform.renewals.open')}
                            </Link>
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <Pagination page={renewals.data.meta.current_page} lastPage={renewals.data.meta.last_page} total={renewals.data.meta.total} onPage={setPage} />
          </>
        )}
      </Card>

      {contacting && <ContactDialog renewal={contacting} onClose={() => setContacting(null)} />}
    </div>
  )
}
