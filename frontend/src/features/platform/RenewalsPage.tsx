import { PhoneCall } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { EmptyState, FilterBar, FilterSelect, PageHeader } from '../../components/page.tsx'
import { Refreshable, TableSkeleton } from '../../components/skeleton.tsx'
import { DataTable } from '../../components/table.tsx'
import { Alert, Button, Card, Pagination } from '../../components/ui.tsx'
import { formatDate, formatDateTime } from '../../lib/format.ts'
import { useClampPage } from '../../lib/pagination.ts'
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

  useClampPage(renewals.data?.meta, setPage)

  return (
    <div className="space-y-5">
      <PageHeader title={t('platform.renewals.title')} description={t('platform.renewals.intro')} />

      <Card>
        <FilterBar>
          <FilterSelect
            label={t('platform.renewals.within')}
            value={String(withinDays)}
            onChange={(event) => {
              setWithinDays(Number(event.target.value))
              setPage(1)
            }}
          >
            {[15, 30, 60].map((days) => (
              <option key={days} value={days}>{t('platform.renewals.withinDays', { count: days })}</option>
            ))}
          </FilterSelect>
          <FilterSelect
            label={t('platform.renewals.contactedFilter')}
            value={contacted}
            onChange={(event) => {
              setContacted(event.target.value as '' | 'yes' | 'no')
              setPage(1)
            }}
          >
            <option value="">{t('platform.renewals.contactedAll')}</option>
            <option value="no">{t('platform.renewals.contactedNo')}</option>
            <option value="yes">{t('platform.renewals.contactedYes')}</option>
          </FilterSelect>
        </FilterBar>

        {renewals.isPending ? (
          <TableSkeleton columns={5} actions minWidth="56rem" />
        ) : renewals.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : renewals.data.data.length === 0 ? (
          <EmptyState icon={PhoneCall} title={t('platform.renewals.empty')} />
        ) : (
          <Refreshable refreshing={renewals.isPlaceholderData}>
            <DataTable minWidth="56rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('platform.tenants.business')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('platform.renewals.contactPerson')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('platform.tenants.subscription')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('platform.renewals.lastContact')}</th>
                  <th scope="col" data-role="actions" className="py-2.5 text-right"><span className="sr-only">{t('backup.list.actions')}</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {renewals.data.data.map((row) => {
                  const s = row.subscription

                  return (
                    <tr key={row.tenant.id}>
                      <td className="py-3 pr-4">
                        <Link to={`/platform/tenants/${row.tenant.id}`} className="font-medium text-brand-800 hover:underline">{row.tenant.name}</Link>
                      </td>
                      <td className="py-3 pr-4">
                        {row.owner ? (
                          <>
                            <span className="block">{row.owner.name}</span>
                            <span className="block text-xs text-slate-500">{[row.owner.phone ?? row.tenant.phone, row.owner.email].filter(Boolean).join(' · ')}</span>
                          </>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td className="py-3 pr-4">
                        <SubscriptionBadge state={s.state} />
                        <span className="block text-xs text-slate-500">{formatDate(s.period_end)} · {daysLabel(s.days_left, t)}</span>
                      </td>
                      <td className="py-3 pr-4">
                        {s.last_contacted_at ? (
                          <>
                            <span className="block whitespace-nowrap">{formatDateTime(s.last_contacted_at)}</span>
                            <span className="block text-xs text-slate-500">{s.contact_note}</span>
                          </>
                        ) : (
                          <span className="text-slate-500">{t('platform.renewals.notContacted')}</span>
                        )}
                      </td>
                      <td className="py-3 text-right">
                        <div className="flex justify-end gap-2">
                          <Button variant="secondary" onClick={() => setContacting(row)}>{t('platform.renewals.markContacted')}</Button>
                          <Link to={`/platform/tenants/${row.tenant.id}`} className="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-brand-800 hover:bg-brand-50">
                            {t('platform.renewals.open')}
                          </Link>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </DataTable>
            <Pagination page={renewals.data.meta.current_page} lastPage={renewals.data.meta.last_page} total={renewals.data.meta.total} onPage={setPage} />
          </Refreshable>
        )}
      </Card>

      {contacting && <ContactDialog renewal={contacting} onClose={() => setContacting(null)} />}
    </div>
  )
}
