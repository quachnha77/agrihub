import { ClipboardCheck, Search } from 'lucide-react'
import { useDeferredValue, useState, type KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { DialogSubject, FormSection } from '../../components/dialog-parts.tsx'
import { FilterSelect, SearchField } from '../../components/page.tsx'
import { FieldsSkeleton, Refreshable, SkeletonRegion, TableSkeleton } from '../../components/skeleton.tsx'
import { fieldBase, fieldTone } from '../../components/styles.ts'
import { DataTable } from '../../components/table.tsx'
import { Alert, Button, ConfirmDialog, Modal, ModalActions, Pagination } from '../../components/ui.tsx'
import { ApiError } from '../../lib/api.ts'
import { useDialog, type DialogProps } from '../../lib/dialog.ts'
import { formatDateTime, formatQuantity, formatVnd } from '../../lib/format.ts'
import { describeError } from '../../lib/forms.ts'
import { useToast } from '../../lib/toast.ts'
import { useAuth } from '../auth/auth-context.ts'
import { useCountLine, useFinishStocktake, useStocktake, useStocktakeLines, type LineFilter, type Stocktake, type StocktakeLine } from './api.ts'
import { ExpiryBadge, StocktakeBadge } from './parts.tsx'

/** Số đếm người dùng gõ: số không âm, tối đa 3 chữ số thập phân; chấp nhận dấu phẩy ("12,5"). Trả về chuỗi gửi lên máy chủ, hoặc null nếu không hợp lệ. */
function parseCount(text: string): string | null {
  const value = text.trim().replace(',', '.')

  return /^\d{1,8}(\.\d{1,3})?$/.test(value) ? value : null
}

/** Lỗi đầu tiên của máy chủ (HTTP 422 gắn với trường nào cũng được); không có thì thông báo chung. */
function firstError(error: unknown, t: Parameters<typeof describeError>[1]): string {
  if (error instanceof ApiError && error.status === 422) {
    const message = Object.values(error.errors).flat()[0]
    if (message) return message
  }

  return describeError(error, t)
}

/**
 * Popup đếm / xem một đợt kiểm kê (`?dialog=stocktake:<id>`). Đang đếm: nhập số đếm từng dòng (lưu ngay khi rời ô), hoàn tất hoặc hủy đợt.
 * Đã kết thúc: chỉ xem. Dòng phân trang theo máy chủ nên đợt có hàng nghìn dòng vẫn nhanh.
 */
export default function StocktakeDialog({ arg: id, onClose }: DialogProps) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const stocktake = useStocktake(id)
  const data = stocktake.data
  const notFound = stocktake.error instanceof ApiError && (stocktake.error.status === 404 || stocktake.error.status === 403)
  const editable = data?.status === 'counting' && can('inventory.adjustment.create')

  return (
    <Modal
      size="xl"
      fixedHeight
      dismissOnBackdrop={false}
      onClose={onClose}
      // Tiêu đề cố định; mã đợt và trạng thái nằm ở dòng mô tả cao đúng một dòng (thanh xám lúc đang tải) để khung không đổi cỡ khi dữ liệu về.
      title={t('inventory.stocktake.dialog.loading')}
      description={<DialogSubject name={data?.code} badge={data && <StocktakeBadge status={data.status} />} />}
    >
      {stocktake.isError ? (
        <div className="space-y-4">
          <Alert tone="danger">{notFound ? t('inventory.stocktake.dialog.notFound') : t('common.loadError')}</Alert>
          <div className="flex justify-end">
            <Button onClick={onClose}>{t('common.close')}</Button>
          </div>
        </div>
      ) : !data ? (
        <SkeletonRegion className="space-y-5">
          <FieldsSkeleton fields={4} />
          <TableSkeleton columns={4} rows={5} minWidth="0" />
        </SkeletonRegion>
      ) : (
        <Body stocktake={data} editable={editable} onClose={onClose} />
      )}
    </Modal>
  )
}

function Body({ stocktake, editable, onClose }: { stocktake: Stocktake; editable: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { link } = useDialog()
  const [filters, setFilters] = useState<{ q: string; status: LineFilter; page: number }>({ q: '', status: '', page: 1 })
  const search = useDeferredValue(filters.q)
  const lines = useStocktakeLines(stocktake.id, { ...filters, q: search })
  const count = useCountLine(stocktake.id)
  const complete = useFinishStocktake(stocktake.id, 'complete')
  const cancel = useFinishStocktake(stocktake.id, 'cancel')
  const [confirming, setConfirming] = useState<'complete' | 'cancel' | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const summary = stocktake.summary ?? { lines: 0, counted: 0, differing: 0 }
  const value = stocktake.difference_value
  const rows = lines.data?.data ?? []
  const filtered = filters.q !== '' || filters.status !== ''
  const set = <K extends 'q' | 'status' | 'page'>(key: K, next: (typeof filters)[K]) => setFilters((current) => ({ ...current, [key]: next, page: key === 'page' ? (next as number) : 1 }))
  const percent = summary.lines === 0 ? 0 : Math.round((summary.counted / summary.lines) * 100)

  async function finish(action: 'complete' | 'cancel') {
    setActionError(null)

    try {
      const result = await (action === 'complete' ? complete : cancel).mutateAsync()

      if (action === 'cancel') {
        toast.success(t('inventory.stocktake.cancel.done', { code: stocktake.code }))
      } else if (result.adjustment_document) {
        toast.success(t('inventory.stocktake.complete.doneDocument', { code: stocktake.code, document: result.adjustment_document.code }), { label: t('inventory.documents.view'), ...link('stock-doc', result.adjustment_document.id) })
      } else {
        toast.success(t('inventory.stocktake.complete.done', { code: stocktake.code }))
      }

      setConfirming(null)
      onClose()
    } catch (error) {
      setActionError(firstError(error, t))
    }
  }

  return (
    <div className="flex min-h-full flex-col gap-5">
      <p className="text-sm text-slate-600">{t(stocktake.status === 'counting' ? 'inventory.stocktake.dialog.introCounting' : 'inventory.stocktake.dialog.introDone')}</p>

      <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm lg:grid-cols-4">
        <div><dt className="text-slate-500">{t('inventory.stocktake.dialog.warehouse')}</dt><dd className="font-medium">{stocktake.warehouse.name} <span className="text-slate-500">({stocktake.branch.code})</span></dd></div>
        <div><dt className="text-slate-500">{t('inventory.stocktake.dialog.startedBy')}</dt><dd className="font-medium">{stocktake.started_by?.name ?? t('inventory.documents.system')} <span className="font-normal text-slate-500">· {formatDateTime(stocktake.started_at)}</span></dd></div>
        {stocktake.finished_at && (
          <div><dt className="text-slate-500">{t('inventory.stocktake.dialog.finishedBy')}</dt><dd className="font-medium">{stocktake.finished_by?.name ?? t('inventory.documents.system')} <span className="font-normal text-slate-500">· {formatDateTime(stocktake.finished_at)}</span></dd></div>
        )}
        {stocktake.status === 'completed' && (
          <div>
            <dt className="text-slate-500">{t('inventory.stocktake.dialog.document')}</dt>
            <dd className="font-medium">
              {stocktake.adjustment_document ? (
                <Link {...link('stock-doc', stocktake.adjustment_document.id)} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{t('inventory.stocktake.dialog.viewDocument', { code: stocktake.adjustment_document.code })}</Link>
              ) : (
                <span className="font-normal text-slate-600">{t('inventory.stocktake.dialog.noDocument')}</span>
              )}
            </dd>
          </div>
        )}
        {stocktake.note && <div className="col-span-2 lg:col-span-4"><dt className="text-slate-500">{t('inventory.stocktake.dialog.note')}</dt><dd>{stocktake.note}</dd></div>}
      </dl>

      <div className="grid grid-cols-3 gap-2 sm:gap-3">
        <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-2.5 sm:p-3">
          <p className="text-xs text-slate-500">{t('inventory.stocktake.dialog.statCounted')}</p>
          <p className="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">{summary.counted}<span className="text-sm font-normal text-slate-500">/{summary.lines}</span></p>
          <div role="progressbar" aria-label={t('inventory.stocktake.dialog.statCounted')} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent} className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200">
            <div className="h-full rounded-full bg-brand-600 transition-[width]" style={{ width: `${percent}%` }} />
          </div>
        </div>
        <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-2.5 sm:p-3">
          <p className="text-xs text-slate-500">{t('inventory.stocktake.dialog.statDiffering')}</p>
          <p className={`mt-0.5 text-lg font-semibold tabular-nums ${summary.differing > 0 ? 'text-amber-700' : 'text-slate-900'}`}>{summary.differing}</p>
        </div>
        {value !== undefined && value !== null && (
          <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-2.5 sm:p-3">
            <p className="text-xs text-slate-500">{t('inventory.stocktake.dialog.statValue')}</p>
            <p className={`mt-0.5 text-base font-semibold tabular-nums sm:text-lg ${Number(value) < 0 ? 'text-red-700' : Number(value) > 0 ? 'text-brand-700' : 'text-slate-900'}`}>{formatVnd(Number(value))}</p>
          </div>
        )}
      </div>

      <FormSection title={t('inventory.stocktake.dialog.linesTitle')}>
        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem]">
          <SearchField label={t('inventory.stocktake.searchLabel')} value={filters.q} onChange={(event) => set('q', event.target.value)} placeholder={t('inventory.stocktake.dialog.searchPlaceholder')} />
          <FilterSelect label={t('inventory.filters.status')} value={filters.status} onChange={(event) => set('status', event.target.value as LineFilter)}>
            <option value="">{t('inventory.stocktake.dialog.filterAll')}</option>
            <option value="uncounted">{t('inventory.stocktake.dialog.filterUncounted')}</option>
            <option value="counted">{t('inventory.stocktake.dialog.filterCounted')}</option>
            <option value="differing">{t('inventory.stocktake.dialog.filterDiffering')}</option>
          </FilterSelect>
        </div>

        {lines.isPending ? (
          <TableSkeleton columns={4} rows={6} minWidth="0" />
        ) : lines.isError ? (
          <Alert tone="danger">{t('common.loadError')}</Alert>
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-10 text-center text-sm text-slate-600">
            {filtered ? <Search className="h-6 w-6 text-slate-400" aria-hidden="true" /> : <ClipboardCheck className="h-6 w-6 text-slate-400" aria-hidden="true" />}
            <p>{filtered ? t('inventory.stocktake.dialog.noMatch') : t('inventory.stocktake.dialog.noLines')}</p>
          </div>
        ) : (
          <Refreshable refreshing={lines.isPlaceholderData}>
            <DataTable variant="form" minWidth="52rem">
              <thead className="border-b border-slate-200 text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" data-role="title" className="py-2.5 pr-4">{t('inventory.stocktake.line.product')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.stocktake.line.batch')}</th>
                  <th scope="col" className="py-2.5 pr-4 text-right">{t('inventory.stocktake.line.system')}</th>
                  <th scope="col" className="py-2.5 pr-4">{t('inventory.stocktake.line.counted')}</th>
                  <th scope="col" className="py-2.5 text-right">{t('inventory.stocktake.line.difference')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((line) => (
                  <CountRow key={line.id} line={line} editable={editable} save={(input) => count.mutateAsync({ line: line.id, ...input })} />
                ))}
              </tbody>
            </DataTable>
            <Pagination page={lines.data.meta.current_page} lastPage={lines.data.meta.last_page} total={lines.data.meta.total} onPage={(page) => set('page', page)} />
          </Refreshable>
        )}
      </FormSection>

      <ModalActions>
        {editable && (
          <>
            <Button variant="ghost" onClick={() => { setActionError(null); setConfirming('cancel') }}>{t('inventory.stocktake.cancel.button')}</Button>
            <Button variant="secondary" onClick={onClose}>{t('common.close')}</Button>
            <Button onClick={() => { setActionError(null); setConfirming('complete') }}>{t('inventory.stocktake.complete.button')}</Button>
          </>
        )}
        {!editable && <Button onClick={onClose}>{t('common.close')}</Button>}
      </ModalActions>

      {confirming === 'complete' && (
        <ConfirmDialog title={t('inventory.stocktake.complete.title', { code: stocktake.code })} confirmLabel={t('inventory.stocktake.complete.confirm')} loading={complete.isPending} error={actionError} onConfirm={() => void finish('complete')} onClose={() => setConfirming(null)}>
          <div className="space-y-2">
            <p>{t('inventory.stocktake.complete.text')}</p>
            <p className="font-medium">{t('inventory.stocktake.complete.counted', { counted: summary.counted, lines: summary.lines, differing: summary.differing })}</p>
            {summary.lines > summary.counted && <p className="text-amber-800">{t('inventory.stocktake.complete.uncounted', { count: summary.lines - summary.counted })}</p>}
            {value !== undefined && value !== null && <p>{t('inventory.stocktake.complete.value', { value: formatVnd(Number(value)) })}</p>}
          </div>
        </ConfirmDialog>
      )}

      {confirming === 'cancel' && (
        <ConfirmDialog title={t('inventory.stocktake.cancel.title', { code: stocktake.code })} confirmLabel={t('inventory.stocktake.cancel.confirm')} danger loading={cancel.isPending} error={actionError} onConfirm={() => void finish('cancel')} onClose={() => setConfirming(null)}>
          {t('inventory.stocktake.cancel.text')}
        </ConfirmDialog>
      )}
    </div>
  )
}

type SaveInput = { quantity: string | null; unit_id: string | null }

/**
 * Một dòng đếm: ô số đếm + đơn vị (đếm bằng Bao, Thùng... cũng được, hệ thống tự quy về đơn vị cơ sở). Số được lưu ngay khi rời ô hoặc đổi đơn vị;
 * Enter chuyển sang ô của dòng kế tiếp để đếm liền tay. Để trống ô là xóa số đếm (dòng quay về "chưa đếm").
 */
function CountRow({ line, editable, save }: { line: StocktakeLine; editable: boolean; save: (input: SaveInput) => Promise<StocktakeLine> }) {
  const { t } = useTranslation()
  const baseUnit = line.product.base_unit
  const [text, setText] = useState(line.counted ? String(Number(line.counted.entered.quantity)) : '')
  const [unitId, setUnitId] = useState(line.counted?.entered.unit.id ?? baseUnit.id)
  const [state, setState] = useState<'idle' | 'saving' | 'saved'>('idle')
  const [error, setError] = useState<string | null>(null)
  const factor = Number(line.product.units.find((u) => u.unit.id === unitId)?.factor ?? '1')
  const parsed = parseCount(text)

  async function commit(nextText: string, nextUnit: string) {
    const trimmed = nextText.trim()
    const quantity = parseCount(nextText)

    if (trimmed !== '' && quantity === null) {
      setError(t('inventory.stocktake.line.invalid'))

      return
    }

    setError(null)

    // Không đổi gì so với số đã lưu thì khỏi gọi máy chủ.
    const saved = line.counted
    if (quantity === null ? saved === null : saved !== null && Number(saved.entered.quantity) === Number(quantity) && saved.entered.unit.id === nextUnit) return

    setState('saving')

    try {
      await save(quantity === null ? { quantity: null, unit_id: null } : { quantity, unit_id: nextUnit })
      setState('saved')
    } catch (failure) {
      setState('idle')
      setError(firstError(failure, t))
    }
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key !== 'Enter') return

    event.preventDefault()
    const inputs = Array.from(event.currentTarget.closest('table')?.querySelectorAll<HTMLInputElement>('input[data-count-input]') ?? [])
    const next = inputs[inputs.indexOf(event.currentTarget) + 1]

    // Chuyển focus làm ô hiện tại mất focus nên tự lưu; dòng cuối thì chỉ lưu.
    if (next) next.focus()
    else event.currentTarget.blur()
  }

  const difference = line.difference === null ? null : Number(line.difference)

  return (
    <tr>
      <td className="py-3 pr-4">
        <span className="font-mono text-xs">{line.product.sku}</span> <span className="font-medium text-slate-900">{line.product.name}</span>
      </td>
      <td className="py-3 pr-4">{line.batch ? <>{line.batch.batch_no}<span className="block text-xs"><ExpiryBadge date={line.batch.expires_at} /></span></> : '—'}</td>
      <td className="py-3 pr-4 text-right whitespace-nowrap tabular-nums">{formatQuantity(line.system_quantity)} {baseUnit.name}</td>
      <td className="py-3 pr-4">
        {editable ? (
          <div>
            <div className="flex gap-2">
              <input
                data-count-input
                inputMode="decimal"
                autoComplete="off"
                aria-label={t('inventory.stocktake.line.countLabel', { name: line.product.name })}
                aria-invalid={error !== null}
                value={text}
                onChange={(event) => { setText(event.target.value); setState('idle') }}
                onBlur={() => void commit(text, unitId)}
                onKeyDown={onKeyDown}
                className={`${fieldBase} ${fieldTone(error !== null)} w-28 text-right tabular-nums`}
              />
              <select
                aria-label={t('inventory.stocktake.line.unitLabel', { name: line.product.name })}
                value={unitId}
                onChange={(event) => { setUnitId(event.target.value); void commit(text, event.target.value) }}
                className={`${fieldBase} ${fieldTone(false)} w-auto min-w-0`}
              >
                {line.product.units.map((u) => <option key={u.unit.id} value={u.unit.id}>{u.unit.name}</option>)}
              </select>
            </div>
            <p className="mt-1 min-h-5 text-xs" aria-live="polite">
              {error ? (
                <span role="alert" className="text-red-700">{error}</span>
              ) : state === 'saving' ? (
                <span className="text-slate-500">{t('inventory.stocktake.line.saving')}</span>
              ) : parsed !== null && factor !== 1 ? (
                <span className="text-slate-500">{t('inventory.stocktake.line.equals', { qty: formatQuantity(Number(parsed) * factor), unit: baseUnit.name })}</span>
              ) : state === 'saved' ? (
                <span className="text-brand-700">{t('inventory.stocktake.line.saved')}</span>
              ) : null}
            </p>
          </div>
        ) : line.counted ? (
          <span className="whitespace-nowrap">
            {formatQuantity(line.counted.entered.quantity)} {line.counted.entered.unit.name}
            {line.counted.entered.unit.id !== baseUnit.id && <span className="text-slate-500"> ({t('inventory.stocktake.line.equals', { qty: formatQuantity(line.counted.quantity), unit: baseUnit.name })})</span>}
          </span>
        ) : (
          <span className="text-slate-400">{t('inventory.stocktake.line.notCounted')}</span>
        )}
      </td>
      <td className={`py-3 text-right whitespace-nowrap font-medium tabular-nums ${difference === null ? 'text-slate-400' : difference < 0 ? 'text-red-700' : difference > 0 ? 'text-brand-700' : 'text-slate-600'}`}>
        {difference === null ? '—' : difference === 0 ? t('inventory.stocktake.line.match') : `${difference > 0 ? '+' : ''}${formatQuantity(difference)} ${baseUnit.name}`}
      </td>
    </tr>
  )
}
