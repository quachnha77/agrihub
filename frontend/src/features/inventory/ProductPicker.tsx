import { Search } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ListSkeleton } from '../../components/skeleton.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { useProducts, type Product } from '../catalog/api.ts'

/** Tìm sản phẩm của chi nhánh theo tên / mã / mã vạch (chỉ sản phẩm đang kinh doanh). Dùng chung cho phiếu kho và phiếu chuyển kho. */
export function ProductPicker({ branchId, onPick }: { branchId: string; onPick: (product: Product) => void }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const search = useDeferredValue(q)
  const products = useProducts({ q: search, type: '', status: 'active', branch: branchId, category: '', brand: '', page: 1 })
  const rows = (products.data?.data ?? []).slice(0, 8)

  return (
    <div>
      <label className="mb-1.5 block text-sm font-medium text-slate-800">
        {t('inventory.form.product')}
        <div className="relative mt-1.5">
          <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-slate-400" aria-hidden="true" />
          <input type="search" value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('inventory.form.productPlaceholder')} autoComplete="off" className={`${fieldBase} ${fieldTone(false)} pl-9 font-normal`} />
        </div>
      </label>
      {products.isPending ? (
        <div className="mt-2 rounded-lg border border-slate-200 bg-white px-3"><ListSkeleton rows={3} subtitle /></div>
      ) : rows.length === 0 ? (
        <p className="mt-2 text-sm text-slate-500">{t('inventory.form.noProducts')}</p>
      ) : (
        <ul className="mt-2 max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 bg-white">
          {rows.map((product) => (
            <li key={product.id}>
              <button type="button" onClick={() => onPick(product)} className="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-left text-sm hover:bg-brand-50">
                <span><span className="font-mono text-xs">{product.sku}</span> <span className="font-medium text-slate-900">{product.name}</span></span>
                <span className="text-xs text-slate-500">{product.type_label} · {product.base_unit.name}{product.track_batch ? ` · ${t('inventory.stock.batch')}` : ''}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
