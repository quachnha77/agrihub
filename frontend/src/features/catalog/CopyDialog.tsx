import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Modal, SelectField } from '../../components/ui.tsx'
import { describeError } from '../../lib/forms.ts'
import { useAllBranches } from '../branches/api.ts'
import { useCopyProducts, type CopyResult } from './api.ts'

/**
 * Sao chép sản phẩm đã chọn sang một chi nhánh khác (mỗi chi nhánh có danh mục riêng nên đỡ phải nhập lại): cùng mã, loại, thông tin đặc thù,
 * đơn vị, giá và mã vạch. Mã đã có ở chi nhánh đích thì bỏ qua và được liệt kê lại.
 */
export function CopyDialog({ productIds, fromBranches, onClose }: { productIds: string[]; fromBranches: string[]; onClose: () => void }) {
  const { t } = useTranslation()
  const branches = useAllBranches()
  const copy = useCopyProducts()
  const [target, setTarget] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<CopyResult | null>(null)

  // Chi nhánh đích: đang hoạt động và không phải nơi duy nhất mà các sản phẩm đã chọn đang thuộc về.
  const options = (branches.data ?? []).filter((branch) => branch.status === 'active' && !(fromBranches.length === 1 && fromBranches[0] === branch.id))

  async function submit() {
    setError(null)

    if (target === '') {
      setError(t('catalog.copy.chooseTarget'))

      return
    }

    try {
      setResult(await copy.mutateAsync({ product_ids: productIds, branch_id: target }))
    } catch (failure) {
      setError(describeError(failure, t))
    }
  }

  return (
    <Modal title={t('catalog.copy.title')} onClose={onClose}>
      {result ? (
        <div className="space-y-4">
          <Alert tone={result.meta.copied > 0 ? 'success' : 'warning'}>{t('catalog.copy.done', { copied: result.meta.copied, skipped: result.meta.skipped.length })}</Alert>
          {result.meta.skipped.length > 0 && (
            <div>
              <p className="mb-1 text-sm font-medium text-slate-800">{t('catalog.copy.skippedTitle')}</p>
              <ul className="list-disc space-y-0.5 pl-5 text-sm text-slate-700">
                {result.meta.skipped.map((item) => <li key={item.sku}><span className="font-mono text-xs">{item.sku}</span> — {item.name}</li>)}
              </ul>
              <p className="mt-1 text-xs text-slate-500">{t('catalog.copy.skippedHint')}</p>
            </div>
          )}
          <div className="text-right"><Button onClick={onClose}>{t('common.close')}</Button></div>
        </div>
      ) : (
        <div className="space-y-4">
          <p className="text-sm text-slate-700">{t('catalog.copy.intro', { count: productIds.length })}</p>
          {error && <Alert tone="danger">{error}</Alert>}
          <SelectField label={t('catalog.copy.target')} value={target} onChange={(event) => setTarget(event.target.value)}>
            <option value="">{t('catalog.form.choose')}</option>
            {options.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} — {branch.name}</option>)}
          </SelectField>
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
            <Button onClick={submit} loading={copy.isPending}>{copy.isPending ? t('catalog.copy.copying') : t('catalog.copy.submit')}</Button>
          </div>
        </div>
      )}
    </Modal>
  )
}
