import { DatabaseBackup, Eye, Pencil } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { PageHeader } from '../../components/page.tsx'
import { DetailPageSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Button, ButtonLink, Card, ConfirmDialog } from '../../components/ui.tsx'
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
      <dt className="text-xs font-medium text-slate-500">{label}</dt>
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
        <Link to="/platform/tenants" className="text-sm font-medium text-brand-700 hover:underline">{t('platform.tenants.back')}</Link>
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
      <PageHeader
        back={{ to: '/platform/tenants', label: t('platform.tenants.back') }}
        title={
          <span className="flex flex-wrap items-center gap-2">
            {data.name}
            <Badge tone={suspended ? 'danger' : 'success'} dot>{t(`platform.tenants.status.${data.status}`)}</Badge>
          </span>
        }
        description={t('platform.tenants.createdOn', { date: formatDate(data.created_at) })}
        actions={
          <>
            <Button variant="secondary" onClick={() => setDialog('support')}>
              <Eye className="h-4 w-4" aria-hidden="true" />
              {t('platform.support.open')}
            </Button>
            <ButtonLink variant="secondary" to={`/platform/tenants/${data.id}/backups`}>
              <DatabaseBackup className="h-4 w-4" aria-hidden="true" />
              {t('platform.tenants.backups')}
            </ButtonLink>
            <Button variant="secondary" onClick={() => setDialog('edit')}>
              <Pencil className="h-4 w-4" aria-hidden="true" />
              {t('common.edit')}
            </Button>
            {suspended ? (
              <Button onClick={() => { setError(null); setDialog('activate') }}>{t('platform.tenants.activate')}</Button>
            ) : (
              <Button variant="danger" onClick={() => setDialog('suspend')}>{t('platform.tenants.suspend')}</Button>
            )}
          </>
        }
      />

      {suspended && (
        <Alert tone="danger" title={t('platform.tenants.suspendedTitle')}>
          {t('platform.tenants.suspendedText', { when: formatDateTime(data.suspended_at), reason: data.suspension_reason ?? '—' })}
        </Alert>
      )}

      <Card title={t('platform.tenants.info')}>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label={t('platform.tenants.loginAddress')}>
            {data.url ? <a href={data.url} target="_blank" rel="noreferrer" className="font-medium text-brand-800 hover:underline">{data.url}</a> : <span className="text-slate-500">{t('platform.tenants.noSubdomain')}</span>}
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
