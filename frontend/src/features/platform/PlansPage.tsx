import { zodResolver } from '@hookform/resolvers/zod'
import { Pencil, Plus } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { TableSkeleton } from '../../components/skeleton.tsx'
import { Alert, Badge, Button, Card, CheckboxField, Modal, TextField } from '../../components/ui.tsx'
import { formatVnd } from '../../lib/format.ts'
import { applyServerErrors, describeError } from '../../lib/forms.ts'
import { usePlans, useSavePlan, type Plan } from './api.ts'

const FIELDS = ['key', 'name', 'description', 'price_monthly', 'price_yearly', 'max_branches', 'storage_mb', 'sort_order'] as const
const KEY_PATTERN = /^[a-z0-9][a-z0-9-]{1,39}$/
const money = (value: string) => /^\d+$/.test(value.trim()) && Number(value) <= 100_000_000_000
const optionalPositive = (value: string) => value.trim() === '' || (/^\d+$/.test(value.trim()) && Number(value) >= 1)

function PlanDialog({ plan, onClose }: { plan?: Plan; onClose: () => void }) {
  const { t } = useTranslation()
  const save = useSavePlan(plan?.id)
  const [formError, setFormError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        key: plan ? z.string() : z.string().trim().refine((v) => KEY_PATTERN.test(v), t('platform.plans.keyInvalid')),
        name: z.string().trim().min(1, t('platform.plans.nameRequired')).max(255),
        description: z.string().trim().max(500),
        price_monthly: z.string().refine(money, t('platform.subscription.moneyInvalid')),
        price_yearly: z.string().refine(money, t('platform.subscription.moneyInvalid')),
        max_branches: z.string().refine(optionalPositive, t('platform.plans.positiveOrEmpty')),
        storage_mb: z.string().refine(optionalPositive, t('platform.plans.positiveOrEmpty')),
        sort_order: z.string().refine((v) => /^\d+$/.test(v) && Number(v) <= 65535, t('platform.plans.orderInvalid')),
        is_active: z.boolean(),
      }),
    [t, plan],
  )
  type Values = z.infer<typeof schema>

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      key: plan?.key ?? '',
      name: plan?.name ?? '',
      description: plan?.description ?? '',
      price_monthly: String(plan?.price_monthly ?? ''),
      price_yearly: String(plan?.price_yearly ?? ''),
      max_branches: plan?.max_branches == null ? '' : String(plan.max_branches),
      storage_mb: plan?.storage_mb == null ? '' : String(plan.storage_mb),
      sort_order: String(plan?.sort_order ?? 0),
      is_active: plan?.is_active ?? true,
    },
  })
  const errors = form.formState.errors
  const active = useWatch({ control: form.control, name: 'is_active' })

  const submit = form.handleSubmit(async (values) => {
    setFormError(null)

    try {
      await save.mutateAsync({
        ...(plan ? {} : { key: values.key }),
        name: values.name,
        description: values.description === '' ? null : values.description,
        price_monthly: Number(values.price_monthly),
        price_yearly: Number(values.price_yearly),
        max_branches: values.max_branches.trim() === '' ? null : Number(values.max_branches),
        storage_mb: values.storage_mb.trim() === '' ? null : Number(values.storage_mb),
        is_active: values.is_active,
        sort_order: Number(values.sort_order),
      })
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, form.setError, FIELDS)) setFormError(describeError(error, t))
    }
  })

  return (
    <Modal title={plan ? t('platform.plans.editTitle') : t('platform.plans.createTitle')} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        {plan && <Alert tone="info">{t('platform.plans.priceNote')}</Alert>}
        {formError && <Alert tone="danger">{formError}</Alert>}
        <TextField label={t('platform.plans.key')} readOnly={Boolean(plan)} hint={plan ? t('platform.plans.keyLocked') : t('platform.plans.keyHint')} error={errors.key?.message} {...form.register('key')} />
        <TextField label={t('platform.plans.name')} error={errors.name?.message} {...form.register('name')} />
        <TextField label={t('platform.plans.description')} error={errors.description?.message} {...form.register('description')} />
        <div className="grid gap-3 sm:grid-cols-2">
          <TextField label={t('platform.plans.priceMonthly')} type="number" min={0} error={errors.price_monthly?.message} {...form.register('price_monthly')} />
          <TextField label={t('platform.plans.priceYearly')} type="number" min={0} error={errors.price_yearly?.message} {...form.register('price_yearly')} />
          <TextField label={t('platform.plans.maxBranches')} type="number" min={1} hint={t('platform.plans.emptyUnlimited')} error={errors.max_branches?.message} {...form.register('max_branches')} />
          <TextField label={t('platform.plans.storage')} type="number" min={1} hint={t('platform.plans.emptyUnlimited')} error={errors.storage_mb?.message} {...form.register('storage_mb')} />
        </div>
        <TextField label={t('platform.plans.order')} type="number" min={0} error={errors.sort_order?.message} {...form.register('sort_order')} />
        <CheckboxField label={t('platform.plans.onSale')} description={t('platform.plans.onSaleHint')} checked={active} onChange={(value) => form.setValue('is_active', value, { shouldDirty: true })} />
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" loading={save.isPending}>{save.isPending ? t('common.saving') : t('common.save')}</Button>
        </div>
      </form>
    </Modal>
  )
}

/** Gói dịch vụ và giá. Giá hiện tại là giá tạm do Super Admin chỉnh; đổi giá không đổi giá của khách đang dùng. */
export default function PlansPage() {
  const { t } = useTranslation()
  const plans = usePlans()
  const [editing, setEditing] = useState<Plan | 'new' | null>(null)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('platform.plans.title')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('platform.plans.intro')}</p>
        </div>
        <Button onClick={() => setEditing('new')}>
          <Plus className="h-4 w-4" aria-hidden="true" />
          {t('platform.plans.add')}
        </Button>
      </div>

      <Card>
        {plans.isPending ? (
          <TableSkeleton columns={6} rows={3} actions minWidth="48rem" />
        ) : plans.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : plans.data.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">{t('platform.plans.empty')}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[48rem] text-left text-sm">
              <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th scope="col" className="py-2 pr-3">{t('platform.plans.name')}</th>
                  <th scope="col" className="py-2 pr-3 text-right">{t('platform.plans.priceMonthly')}</th>
                  <th scope="col" className="py-2 pr-3 text-right">{t('platform.plans.priceYearly')}</th>
                  <th scope="col" className="py-2 pr-3">{t('platform.plans.limits')}</th>
                  <th scope="col" className="py-2 pr-3">{t('users.status')}</th>
                  <th scope="col" className="py-2 text-right">{t('backup.list.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {plans.data.map((plan) => (
                  <tr key={plan.id}>
                    <td className="py-2 pr-3">
                      <span className="block font-medium text-slate-900">{plan.name}</span>
                      <span className="block text-xs text-slate-500">{plan.key}</span>
                    </td>
                    <td className="py-2 pr-3 text-right whitespace-nowrap">{formatVnd(plan.price_monthly)}</td>
                    <td className="py-2 pr-3 text-right whitespace-nowrap">{formatVnd(plan.price_yearly)}</td>
                    <td className="py-2 pr-3 text-xs text-slate-600">
                      {t('platform.plans.branchesLimit', { value: plan.max_branches ?? t('platform.plans.unlimited') })}
                      <br />
                      {t('platform.plans.storageLimit', { value: plan.storage_mb === null ? t('platform.plans.unlimited') : `${plan.storage_mb} MB` })}
                    </td>
                    <td className="py-2 pr-3">
                      <Badge tone={plan.is_active ? 'success' : 'neutral'}>{plan.is_active ? t('platform.plans.onSaleShort') : t('platform.plans.stopped')}</Badge>
                    </td>
                    <td className="py-2 text-right">
                      <Button variant="ghost" onClick={() => setEditing(plan)} aria-label={`${t('common.edit')} ${plan.name}`}>
                        <Pencil className="h-4 w-4" aria-hidden="true" />
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {editing && <PlanDialog plan={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}
    </div>
  )
}
