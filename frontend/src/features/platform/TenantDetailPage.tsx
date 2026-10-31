import { ArrowLeft, DatabaseBackup, Eye, Pencil } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { DetailPageSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Button, Card, ConfirmDialog } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { formatDate, formatDateTime } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useSetTenantStatus, useTenant } from './api.ts'
import { ReasonDialog } from './ReasonDialog.tsx'
import { SubscriptionCard } from './SubscriptionCard.tsx'
import { SupportSessionDialog } from './SupportSessionDialog.tsx'
import { EditTenantDialog } from './TenantDialogs.tsx'
import { TenantUsersCard } from './TenantUsersCard.tsx'

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words text-sm text-slate-900">{children}</dd>
    </div>
  )
}

type Dialog = 'edit' | 'suspend' | 'activate' | 'support' | null

/** Chi tiết một doanh nghiệp: thông tin, địa chỉ đăng nhập, thuê bao, người dùng; khóa/mở khóa và mở phiên hỗ trợ. */
export default function TenantDetailPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const tenant = useTenant(id)
  const status = useSetTenantStatus(id)
  const [dialog, setDialog] = useState<Dialog>(null)
  const [error, setError] = useState<string | null>(null)

  if (tenant.isPending) return <DetailPageSkeleton />

  if (tenant.isError) {
    const notFound = tenant.error instanceof ApiError && tenant.error.status === 404

    return (
      <div className="space-y-3">
        <Alert tone="danger">{notFound ? t('platform.tenants.notFound') : t('common.loadError')}</Alert>
        <Link to="/platform/tenants" className="text-sm font-medium text-emerald-700 hover:underline">{t('platform.tenants.back')}</Link>
      </div>
    )
  }

  const data = tenant.data
  const suspended = data.status === 'suspended'
  const close = () => setDialog(null)

  async function activate() {
    setError(null)

    try {
      await status.mutateAsync({ action: 'activate' })
      close()
    } catch (failure) {
      setError(describeError(failure, t))
    }
  }

  return (
    <div className="space-y-4">
      <Link to="/platform/tenants" className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t('platform.tenants.back')}
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold text-slate-900">
            {data.name}
            <Badge tone={suspended ? 'danger' : 'success'}>{t(`platform.tenants.status.${data.status}`)}</Badge>
          </h1>
          <p className="mt-1 text-sm text-slate-600">{t('platform.tenants.createdOn', { date: formatDate(data.created_at) })}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setDialog('support')}>
            <Eye className="h-4 w-4" aria-hidden="true" />
            {t('platform.support.open')}
          </Button>
          <Link
            to={`/platform/tenants/${data.id}/backups`}
            className="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50"
          >
            <DatabaseBackup className="h-4 w-4" aria-hidden="true" />
            {t('platform.tenants.backups')}
          </Link>
          <Button variant="secondary" onClick={() => setDialog('edit')}>
            <Pencil className="h-4 w-4" aria-hidden="true" />
            {t('common.edit')}
          </Button>
          {suspended ? (
            <Button onClick={() => { setError(null); setDialog('activate') }}>{t('platform.tenants.activate')}</Button>
          ) : (
            <Button variant="danger" onClick={() => setDialog('suspend')}>{t('platform.tenants.suspend')}</Button>
          )}
        </div>
      </div>

      {suspended && (
        <Alert tone="danger" title={t('platform.tenants.suspendedTitle')}>
          {t('platform.tenants.suspendedText', { when: formatDateTime(data.suspended_at), reason: data.suspension_reason ?? '—' })}
        </Alert>
      )}

      <Card title={t('platform.tenants.info')}>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label={t('platform.tenants.loginAddress')}>
            {data.url ? <a href={data.url} target="_blank" rel="noreferrer" className="font-medium text-emerald-800 hover:underline">{data.url}</a> : <span className="text-slate-500">{t('platform.tenants.noSubdomain')}</span>}
          </Field>
          <Field label={t('platform.tenants.slug')}>{data.slug}</Field>
          <Field label={t('platform.tenants.taxCode')}>{data.tax_code ?? '—'}</Field>
          <Field label={t('platform.tenants.phone')}>{data.phone ?? '—'}</Field>
          <Field label={t('platform.tenants.address')}>{data.address ?? '—'}</Field>
          <Field label={t('platform.tenants.usage')}>{t('platform.tenants.usageValue', { users: data.users_count ?? 0, branches: data.branches_count ?? 0 })}</Field>
        </dl>
        {data.branches && data.branches.length > 0 && (
          <p className="mt-4 text-sm text-slate-600">{t('platform.tenants.branchList', { list: data.branches.map((b) => `${b.code} — ${b.name}`).join('; ') })}</p>
        )}
      </Card>

      <SubscriptionCard tenantId={data.id} />

      <TenantUsersCard tenantId={data.id} loginUrl={data.url} />

      {dialog === 'edit' && <EditTenantDialog tenant={data} onClose={close} />}
      {dialog === 'support' && <SupportSessionDialog tenant={data} onClose={close} />}
      {dialog === 'suspend' && (
        <ReasonDialog
          title={t('platform.tenants.suspendTitle', { name: data.name })}
          intro={t('platform.tenants.suspendIntro')}
          label={t('platform.tenants.suspendReason')}
          minLength={5}
          danger
          submitLabel={t('platform.tenants.suspend')}
          onSubmit={(reason) => status.mutateAsync({ action: 'suspend', reason })}
          onClose={close}
        />
      )}
      {dialog === 'activate' && (
        <ConfirmDialog title={t('platform.tenants.activateTitle', { name: data.name })} confirmLabel={t('platform.tenants.activate')} loading={status.isPending} error={error} onConfirm={activate} onClose={close}>
          {t('platform.tenants.activateText')}
        </ConfirmDialog>
      )}
    </div>
  )
}
