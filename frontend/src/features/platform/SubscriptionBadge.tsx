import { useTranslation } from 'react-i18next'
import { Badge, type Tone } from '../../components/ui.tsx'
import type { SubscriptionState } from './api.ts'

const TONES: Record<SubscriptionState, Tone> = { trial: 'info', active: 'success', grace: 'warning', reopened: 'warning', expired: 'danger' }

export function SubscriptionBadge({ state }: { state: SubscriptionState }) {
  const { t } = useTranslation()

  return <Badge tone={TONES[state]}>{t(`platform.subscription.state.${state}`)}</Badge>
}
