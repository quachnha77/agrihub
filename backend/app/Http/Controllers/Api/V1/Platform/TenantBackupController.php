<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BackupResource;
use App\Http\Resources\Api\V1\RestoreRunResource;
use App\Models\Backup;
use App\Models\RestoreRun;
use App\Models\Tenant;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super Admin: sao lưu / khôi phục dữ liệu của MỘT doanh nghiệp được chọn (cùng quy tắc an toàn như Chủ doanh nghiệp).
 * Mọi thao tác ghi audit thuộc về Tenant nên Chủ doanh nghiệp thấy trong nhật ký của mình.
 */
class TenantBackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return BackupResource::collection(
            Backup::query()->where('tenant_id', $tenant->id)->orderByDesc('created_at')->orderByDesc('id')
                ->paginate($request->integer('per_page', 20))->withQueryString(),
        );
    }

    public function show(Tenant $tenant, Backup $backup): BackupResource
    {
        return new BackupResource($this->owned($tenant, $backup));
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        return (new BackupResource($this->backups->request($tenant, $request->user())))->response()->setStatusCode(202);
    }

    public function import(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.(int) config('backup.max_upload_mb') * 1024]]);

        $backup = $this->backups->storeUpload($tenant, $request->user(), $request->file('file'));

        return (new BackupResource($backup))->response()->setStatusCode(201);
    }

    public function download(Request $request, Tenant $tenant, Backup $backup): StreamedResponse
    {
        return $this->backups->download($this->owned($tenant, $backup), $request->user());
    }

    public function restore(Request $request, Tenant $tenant, Backup $backup): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'confirm_name' => ['required', 'string', 'max:255'],
        ]);

        $this->backups->assertConfirmed($request->user(), $tenant, $data['password'], $data['confirm_name']);

        $run = $this->backups->requestRestore($tenant, $request->user(), $this->owned($tenant, $backup));

        return (new RestoreRunResource($run))->response()->setStatusCode(202);
    }

    public function restores(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return RestoreRunResource::collection(
            RestoreRun::query()->where('tenant_id', $tenant->id)->with(['backup', 'preBackup'])->orderByDesc('created_at')->orderByDesc('id')
                ->paginate($request->integer('per_page', 20))->withQueryString(),
        );
    }

    /** Bản sao lưu phải thuộc đúng doanh nghiệp trên đường dẫn (chặn lẫn Tenant). */
    private function owned(Tenant $tenant, Backup $backup): Backup
    {
        abort_unless($backup->tenant_id === $tenant->id, 404);

        return $backup;
    }
}
