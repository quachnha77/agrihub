import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { meOk, mockApi, platformAdmin, problem, renderApp, tenantUser } from '../../test/utils.tsx'
import type { BackupRow, RestoreRunRow } from './api.ts'

const backup = (overrides: Partial<BackupRow> = {}): BackupRow => ({
  id: 't1',
  scope: 'tenant',
  kind: 'auto',
  status: 'completed',
  size_bytes: 148074,
  checksum_sha256: 'a'.repeat(64),
  key_fingerprint: null,
  row_counts: {},
  original_name: null,
  error: null,
  verify_status: null,
  verified_at: null,
  verify_error: null,
  downloaded_at: null,
  created_at: '2026-10-30T01:30:00Z',
  completed_at: '2026-10-30T01:31:00Z',
  ...overrides,
})

const restoreRun = (overrides: Partial<RestoreRunRow> = {}): RestoreRunRow => ({
  id: 'r1',
  status: 'completed',
  backup_id: 't1',
  pre_restore_backup_id: 't0',
  row_counts: {},
  error: null,
  started_at: '2026-10-31T02:00:05Z',
  finished_at: '2026-10-31T02:01:00Z',
  created_at: '2026-10-31T02:00:00Z',
  ...overrides,
})

const ownerWithRestore = () =>
  tenantUser({ permissions: { 'backup.backup.view': 'tenant', 'backup.backup.create': 'tenant', 'backup.backup.export': 'tenant', 'backup.backup.restore': 'tenant' } })

const ownerApi = (extra: Record<string, unknown> = {}) =>
  ({
    'GET /api/v1/auth/me': meOk(ownerWithRestore()),
    'GET /api/v1/backups': { json: { data: [backup(), backup({ id: 't0', kind: 'pre_restore' })] } },
    'GET /api/v1/backups/restores': { json: { data: [] } },
    ...extra,
  }) as never

async function openRestoreDialog(user: ReturnType<typeof userEvent.setup>) {
  const table = await screen.findByRole('table')
  await user.click(within(table).getAllByRole('button', { name: /^Khôi phục/ })[0])

  return screen.findByRole('dialog', { name: 'Khôi phục dữ liệu' })
}

async function confirmRestore(user: ReturnType<typeof userEvent.setup>, dialog: HTMLElement, password = 'Mat-khau-2026') {
  await user.type(within(dialog).getByLabelText('Mật khẩu của bạn'), password)
  await user.type(within(dialog).getByLabelText(/Gõ đúng tên doanh nghiệp/), 'Cửa hàng Nông Phát')
  await user.click(within(dialog).getByRole('button', { name: 'Khôi phục dữ liệu' }))
}

describe('Import bản sao lưu (Chủ doanh nghiệp)', () => {
  it('gửi file .agbk bằng multipart và báo file hợp lệ đã vào danh sách', async () => {
    const server = mockApi(ownerApi({ 'POST /api/v1/backups/import': { status: 201, json: { data: backup({ id: 'u1', kind: 'upload', original_name: 'x.agbk' }) } } }))
    renderApp('/backups')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Nhập từ file' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nhập bản sao lưu từ file' })
    await user.upload(within(dialog).getByLabelText('File sao lưu (.agbk)'), new File(['xyz'], 'x.agbk'))
    await user.click(within(dialog).getByRole('button', { name: 'Tải lên và kiểm tra' }))

    expect(await within(dialog).findByText(/File hợp lệ và đã được thêm vào danh sách/)).toBeInTheDocument()
    const sent = server.to('POST', '/api/v1/backups/import')
    expect(sent).toHaveLength(1)
    expect((sent[0]?.body as { file?: File } | undefined)?.file?.name).toBe('x.agbk')
    // Danh sách được lấy lại để bản vừa nhập xuất hiện.
    await waitFor(() => expect(server.to('GET', '/api/v1/backups').length).toBeGreaterThan(1))
  })

  it('chưa chọn file thì báo ngay và không gửi gì', async () => {
    const server = mockApi(ownerApi())
    renderApp('/backups')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Nhập từ file' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Tải lên và kiểm tra' }))

    expect(await within(dialog).findByText('Vui lòng chọn một file.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/backups/import')).toHaveLength(0)
  })

  it('file không hợp lệ: nói rõ lý do do máy chủ trả về và không báo thành công', async () => {
    mockApi(ownerApi({ 'POST /api/v1/backups/import': problem(422, 'backup_invalid', 'File này thuộc về doanh nghiệp khác.') }))
    renderApp('/backups')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Nhập từ file' }))
    const dialog = await screen.findByRole('dialog')
    await user.upload(within(dialog).getByLabelText('File sao lưu (.agbk)'), new File(['xyz'], 'khac.agbk'))
    await user.click(within(dialog).getByRole('button', { name: 'Tải lên và kiểm tra' }))

    expect(await within(dialog).findByText('File không dùng được: File này thuộc về doanh nghiệp khác.')).toBeInTheDocument()
    expect(within(dialog).queryByText(/File hợp lệ/)).not.toBeInTheDocument()
  })

  it('máy chủ từ chối vì file quá lớn (413, thân trả về không phải JSON)', async () => {
    mockApi(ownerApi({ 'POST /api/v1/backups/import': { status: 413 } }))
    renderApp('/backups')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Nhập từ file' }))
    const dialog = await screen.findByRole('dialog')
    await user.upload(within(dialog).getByLabelText('File sao lưu (.agbk)'), new File(['xyz'], 'to.agbk'))
    await user.click(within(dialog).getByRole('button', { name: 'Tải lên và kiểm tra' }))

    expect(await within(dialog).findByText('File quá lớn so với giới hạn của máy chủ.')).toBeInTheDocument()
  })
})

describe('Khôi phục dữ liệu (Chủ doanh nghiệp)', () => {
  it('cảnh báo hậu quả, chỉ mở nút khi gõ đúng tên doanh nghiệp, nói rõ khi bản chưa có dữ liệu nghiệp vụ', async () => {
    mockApi(ownerApi())
    renderApp('/backups')
    const user = userEvent.setup()

    const dialog = await openRestoreDialog(user)
    expect(within(dialog).getByText(/chưa chứa dữ liệu nghiệp vụ nào/)).toBeInTheDocument()
    expect(within(dialog).getByText(/Hệ thống tự sao lưu hiện trạng trước/)).toBeInTheDocument()
    expect(within(dialog).getByText(/Tài khoản, chi nhánh, vai trò, gói dịch vụ và nhật ký hoạt động không bị thay đổi/)).toBeInTheDocument()

    const submit = within(dialog).getByRole('button', { name: 'Khôi phục dữ liệu' })
    expect(submit).toBeDisabled()

    await user.type(within(dialog).getByLabelText(/Gõ đúng tên doanh nghiệp/), 'Cửa hàng Nông Ph')
    expect(submit).toBeDisabled()
    await user.type(within(dialog).getByLabelText(/Gõ đúng tên doanh nghiệp/), 'át')
    expect(submit).toBeEnabled()
  })

  it('hiện số bảng và số dòng khi bản sao lưu có dữ liệu', async () => {
    mockApi(ownerApi({ 'GET /api/v1/backups': { json: { data: [backup({ row_counts: { products: 120, customers: 30 } })] } } }))
    renderApp('/backups')
    const user = userEvent.setup()

    const dialog = await openRestoreDialog(user)
    expect(within(dialog).getByText('Bản này chứa 2 bảng dữ liệu, tổng 150 dòng.')).toBeInTheDocument()
  })

  it('bắt nhập mật khẩu; sai mật khẩu báo ngay ở ô mật khẩu và không đóng hộp', async () => {
    const server = mockApi(
      ownerApi({ 'POST /api/v1/backups/t1/restore': problem(422, 'confirmation_failed', 'Mật khẩu không đúng.', { errors: { password: ['Mật khẩu không đúng.'] } }) }),
    )
    renderApp('/backups')
    const user = userEvent.setup()

    const dialog = await openRestoreDialog(user)
    await user.type(within(dialog).getByLabelText(/Gõ đúng tên doanh nghiệp/), 'Cửa hàng Nông Phát')
    await user.click(within(dialog).getByRole('button', { name: 'Khôi phục dữ liệu' }))
    expect(await within(dialog).findByText('Vui lòng nhập mật khẩu.')).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/backups/t1/restore')).toHaveLength(0)

    await user.type(within(dialog).getByLabelText('Mật khẩu của bạn'), 'sai')
    await user.click(within(dialog).getByRole('button', { name: 'Khôi phục dữ liệu' }))

    expect(await within(dialog).findByText('Mật khẩu không đúng.')).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Khôi phục dữ liệu' })).toBeInTheDocument()
  })

  it('gửi mật khẩu + tên doanh nghiệp, đóng hộp rồi báo kết quả khi lần khôi phục xong', async () => {
    let requested = false
    const server = mockApi(
      ownerApi({
        'POST /api/v1/backups/t1/restore': () => {
          requested = true

          return { status: 202, json: { data: restoreRun({ status: 'pending', finished_at: null, started_at: null }) } }
        },
        'GET /api/v1/backups/restores': () => ({ json: { data: requested ? [restoreRun()] : [] } }),
      }),
    )
    renderApp('/backups')
    const user = userEvent.setup()

    await confirmRestore(user, await openRestoreDialog(user))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(server.to('POST', '/api/v1/backups/t1/restore')[0]?.body).toEqual({ password: 'Mat-khau-2026', confirm_name: 'Cửa hàng Nông Phát' })
    expect(await screen.findByText('Khôi phục xong')).toBeInTheDocument()
    expect(screen.getByText(/Dữ liệu đã được khôi phục lúc/)).toBeInTheDocument()
  })

  it('lần khôi phục thất bại: báo dữ liệu được giữ nguyên và lý do', async () => {
    let requested = false
    mockApi(
      ownerApi({
        'POST /api/v1/backups/t1/restore': () => {
          requested = true

          return { status: 202, json: { data: restoreRun({ status: 'pending', finished_at: null }) } }
        },
        'GET /api/v1/backups/restores': () => ({
          json: { data: requested ? [restoreRun({ status: 'failed', error: 'File sao lưu bị mất hoặc đã bị thay đổi (checksum không khớp).' })] : [] },
        }),
      }),
    )
    renderApp('/backups')
    const user = userEvent.setup()

    await confirmRestore(user, await openRestoreDialog(user))

    expect(await screen.findByText('Khôi phục thất bại')).toBeInTheDocument()
    expect(screen.getByText(/Dữ liệu hiện tại được giữ nguyên, chưa bị thay đổi\. Lý do: File sao lưu bị mất/)).toBeInTheDocument()
  })

  it('đang có lần khôi phục khác: báo đã có lần đang chạy và không đóng hộp', async () => {
    mockApi(ownerApi({ 'POST /api/v1/backups/t1/restore': problem(409, 'restore_in_progress', 'Đang có một lần khôi phục khác chưa hoàn tất.') }))
    renderApp('/backups')
    const user = userEvent.setup()

    const dialog = await openRestoreDialog(user)
    await confirmRestore(user, dialog)

    expect(await within(dialog).findByText('Đang có một lần khôi phục khác chưa xong. Hãy đợi lần đó hoàn tất.')).toBeInTheDocument()
  })

  it('đang khôi phục: báo đang khôi phục, ẩn danh sách và các nút thao tác', async () => {
    mockApi(ownerApi({ 'GET /api/v1/backups/restores': { json: { data: [restoreRun({ status: 'running', finished_at: null })] } } }))
    renderApp('/backups')

    expect(await screen.findByText('Đang khôi phục dữ liệu')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sao lưu ngay' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nhập từ file' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Khôi phục \d/ })).not.toBeInTheDocument()
  })

  it('máy chủ trả 503 tenant_restoring: là "đang khôi phục" chứ không phải lỗi tải dữ liệu', async () => {
    const busy = problem(503, 'tenant_restoring', 'Doanh nghiệp đang được khôi phục dữ liệu. Vui lòng thử lại sau ít phút.')
    mockApi(ownerApi({ 'GET /api/v1/backups': busy, 'GET /api/v1/backups/restores': busy }))
    renderApp('/backups')

    expect(await screen.findByText('Đang khôi phục dữ liệu')).toBeInTheDocument()
    expect(screen.queryByText('Không tải được dữ liệu. Vui lòng thử lại.')).not.toBeInTheDocument()
  })

  it('lịch sử khôi phục: bản đã dùng, điểm quay lại để hoàn tác và lý do thất bại', async () => {
    mockApi(
      ownerApi({
        'GET /api/v1/backups/restores': {
          json: { data: [restoreRun({ id: 'r2', status: 'failed', pre_restore_backup_id: null, error: 'Không tạo được điểm quay lại.' }), restoreRun({ id: 'r1' })] },
        },
      }),
    )
    renderApp('/backups')

    expect(await screen.findByText(/Muốn hoàn tác một lần khôi phục/)).toBeInTheDocument()
    expect(screen.getByText('Lịch sử khôi phục')).toBeInTheDocument()
    const history = (await screen.findAllByRole('table'))[1]
    expect(within(history).getByText('Không tạo được điểm quay lại.')).toBeInTheDocument()
    expect(within(history).getByText('Thất bại')).toBeInTheDocument()
    expect(within(history).getAllByText('Hoàn tất').length).toBeGreaterThan(0)
    // Tên bản sao lưu được tra từ danh sách: bản dùng để khôi phục (tự động) và điểm quay lại.
    expect(within(history).getAllByText(/\(Tự động\)/).length).toBeGreaterThan(0)
    expect(within(history).getByText(/\(Điểm quay lại\)/)).toBeInTheDocument()
  })
})

describe('Sao lưu của một doanh nghiệp (Super Admin)', () => {
  const adminApi = (extra: Record<string, unknown> = {}) =>
    ({
      'GET /api/v1/auth/me': meOk(platformAdmin()),
      'GET /api/v1/platform/tenants/tn1': { json: { data: { id: 'tn1', name: 'Cửa hàng Nông Phát', slug: 'nong-phat', status: 'active' } } },
      'GET /api/v1/platform/tenants/tn1/backups': { json: { data: [backup({ id: 'b9' })] } },
      'GET /api/v1/platform/tenants/tn1/backups/restores': { json: { data: [] } },
      ...extra,
    }) as never

  it('dùng đường dẫn theo doanh nghiệp: tải về và khôi phục đều nhắm đúng doanh nghiệp, xác nhận bằng tên doanh nghiệp đó', async () => {
    const server = mockApi(
      adminApi({ 'POST /api/v1/platform/tenants/tn1/backups/b9/restore': { status: 202, json: { data: restoreRun({ status: 'pending', finished_at: null }) } } }),
    )
    renderApp('/platform/tenants/tn1/backups')
    const user = userEvent.setup()

    expect(await screen.findByRole('heading', { name: 'Sao lưu của Cửa hàng Nông Phát' })).toBeInTheDocument()
    const table = await screen.findByRole('table')
    expect(within(table).getByRole('link', { name: /Tải về/ })).toHaveAttribute('href', '/api/v1/platform/tenants/tn1/backups/b9/download')

    await user.click(within(table).getByRole('button', { name: /^Khôi phục/ }))
    await confirmRestore(user, await screen.findByRole('dialog', { name: 'Khôi phục dữ liệu' }), 'Mat-khau-admin-2026')

    await waitFor(() => expect(server.to('POST', '/api/v1/platform/tenants/tn1/backups/b9/restore')).toHaveLength(1))
    expect(server.to('POST', '/api/v1/platform/tenants/tn1/backups/b9/restore')[0]?.body).toEqual({ password: 'Mat-khau-admin-2026', confirm_name: 'Cửa hàng Nông Phát' })
  })

  it('nhập file gửi tới đường dẫn của doanh nghiệp được chọn', async () => {
    const server = mockApi(adminApi({ 'POST /api/v1/platform/tenants/tn1/backups/import': { status: 201, json: { data: backup({ id: 'u1', kind: 'upload' }) } } }))
    renderApp('/platform/tenants/tn1/backups')
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Nhập từ file' }))
    const dialog = await screen.findByRole('dialog')
    await user.upload(within(dialog).getByLabelText('File sao lưu (.agbk)'), new File(['xyz'], 'x.agbk'))
    await user.click(within(dialog).getByRole('button', { name: 'Tải lên và kiểm tra' }))

    expect(await within(dialog).findByText(/File hợp lệ/)).toBeInTheDocument()
    expect(server.to('POST', '/api/v1/platform/tenants/tn1/backups/import')).toHaveLength(1)
  })

  it('doanh nghiệp không tồn tại thì báo không tìm thấy', async () => {
    mockApi({
      'GET /api/v1/auth/me': meOk(platformAdmin()),
      'GET /api/v1/platform/tenants/none': problem(404, 'not_found', 'Không tìm thấy.'),
    })
    renderApp('/platform/tenants/none/backups')

    expect(await screen.findByText(/Không tìm thấy doanh nghiệp/)).toBeInTheDocument()
  })

  it('người không phải Super Admin không vào được trang này', async () => {
    mockApi({ 'GET /api/v1/auth/me': meOk(ownerWithRestore()) })
    renderApp('/platform/tenants/tn1/backups')

    await waitFor(() => expect(screen.queryByRole('heading', { name: /Sao lưu của/ })).not.toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Nhập từ file' })).not.toBeInTheDocument()
  })
})
