import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

export default function NotFoundPage() {
  const { t } = useTranslation()

  return (
    <div className="mx-auto max-w-md py-16 text-center">
      <h1 className="text-xl font-semibold text-slate-900">{t('errors.notFoundTitle')}</h1>
      <p className="mt-2 text-sm text-slate-600">{t('errors.notFoundText')}</p>
      <Link to="/" className="mt-4 inline-block text-sm font-medium text-brand-700 hover:underline">
        {t('errors.backHome')}
      </Link>
    </div>
  )
}
