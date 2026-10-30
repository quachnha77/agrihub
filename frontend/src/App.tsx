import { useEffect, useState } from 'react'

type Status = 'loading' | 'ok' | 'error'

export default function App() {
  const [status, setStatus] = useState<Status>('loading')

  useEffect(() => {
    fetch('/api/v1/health')
      .then((r) => setStatus(r.ok ? 'ok' : 'error'))
      .catch(() => setStatus('error'))
  }, [])

  const label: Record<Status, string> = {
    loading: 'Đang kiểm tra kết nối máy chủ…',
    ok: 'Máy chủ hoạt động bình thường',
    error: 'Không kết nối được máy chủ',
  }

  return (
    <main className="min-h-screen bg-slate-50 text-slate-900 flex items-center justify-center p-4">
      <div className="max-w-md w-full rounded-lg bg-white p-6 shadow">
        <h1 className="text-2xl font-semibold">AgriHub</h1>
        <p className="mt-1 text-sm text-slate-600">Nền tảng quản lý kinh doanh vật tư nông nghiệp</p>
        <p role="status" className="mt-4 text-sm">{label[status]}</p>
      </div>
    </main>
  )
}
