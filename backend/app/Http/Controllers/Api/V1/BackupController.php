<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BackupResource;
use App\Http\Resources\Api\V1\RestoreRunResource;
use App\Models\Backup;
use App\Models\RestoreRun;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sao lưu / khôi phục dữ liệu của chính doanh nghiệp (Chủ doanh nghiệp). Quyền: backup.backup.{view,create,export,restore}.
 * Dữ liệu luôn giới hạn theo Tenant (TenantScope + RLS) nên bản sao lưu của Tenant khác trả 404.
 */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('backup.backup.view');

        $f = $request->validate([
            'kind' => ['nullable', Rule::in([Backup::KIND_MANUAL, Backup::KIND_AUTO, Backup::KIND_UPLOAD, Backup::KIND_PRE_RESTORE])],
            'status' => ['nullable', Rule::in([Backup::STATUS_PENDING, Backup::STATUS_RUNNING, Backup::STATUS_COMPLETED, Backup::STATUS_FAILED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return BackupResource::collection(
            Backup::query()
                ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
                ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate($f['per_page'] ?? 20)->withQueryString(),
        );
    }

    public function show(Backup $backup): BackupResource
    {
        Gate::authorize('backup.backup.view');

        return new BackupResource($backup);
    }

    /** Tạo bản sao lưu thủ công (chạy nền; 202). */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('backup.backup.create');

        $backup = $this->backups->request($request->user()->tenant, $request->user());

        return (new BackupResource($backup))->response()->setStatusCode(202);
    }

    /** Import: tải lên file .agbk đã Export trước đó. File được kiểm tra ngay; hợp lệ thì xuất hiện trong danh sách để khôi phục. */
    public function import(Request $request): JsonResponse
    {
        Gate::authorize('backup.backup.create');

        $request->validate(['file' => ['required', 'file', 'max:'.(int) config('backup.max_upload_mb') * 1024]]);

        $backup = $this->backups->storeUpload($request->user()->tenant, $request->user(), $request->file('file'));

        return (new BackupResource($backup))->response()->setStatusCode(201);
    }

    /** Export: tải file đã mã hóa. */
    public function download(Request $request, Backup $backup): StreamedResponse
    {
        Gate::authorize('backup.backup.export');

        return $this->backups->download($backup, $request->user());
    }

    /**
     * Khôi phục dữ liệu nghiệp vụ từ bản sao lưu (chạy nền; 202). Cần nhập lại mật khẩu và gõ đúng tên doanh nghiệp.
     * Hệ thống tự sao lưu hiện trạng trước (điểm quay lại), rồi mới thay dữ liệu.
     */
    public function restore(Request $request, Backup $backup): JsonResponse
    {
        Gate::authorize('backup.backup.restore');

        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'confirm_name' => ['required', 'string', 'max:255'],
        ]);

        $tenant = $request->user()->tenant;
        $this->backups->assertConfirmed($request->user(), $tenant, $data['password'], $data['confirm_name']);

        $run = $this->backups->requestRestore($tenant, $request->user(), $backup);

        return (new RestoreRunResource($run))->response()->setStatusCode(202);
    }

    public function restores(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('backup.backup.view');

        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return RestoreRunResource::collection(
            RestoreRun::query()->with(['backup', 'preBackup'])->orderByDesc('created_at')->orderByDesc('id')
                ->paginate($request->integer('per_page', 20))->withQueryString(),
        );
    }
}
