<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BackupResource;
use App\Models\Backup;
use App\Services\Backup\PlatformBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Super Admin: sao lưu TOÀN HỆ THỐNG (mọi Tenant). Chỉ xem danh sách và yêu cầu chạy một bản mới.
 *
 * Cố ý KHÔNG có tải về và KHÔNG có khôi phục qua HTTP: một bản chứa dữ liệu mọi khách hàng, nên việc chép ra nơi lưu ngoài
 * và khôi phục chỉ làm được trên máy chủ bằng dòng lệnh (agrihub:backup:restore-platform).
 */
class PlatformBackupController extends Controller
{
    public function __construct(private PlatformBackupService $backups) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return BackupResource::collection(
            Backup::query()->where('scope', Backup::SCOPE_PLATFORM)->orderByDesc('created_at')->orderByDesc('id')
                ->paginate($request->integer('per_page', 20))->withQueryString(),
        );
    }

    public function show(Backup $backup): BackupResource
    {
        abort_unless($backup->isPlatform(), 404);

        return new BackupResource($backup);
    }

    public function store(Request $request): JsonResponse
    {
        return (new BackupResource($this->backups->request($request->user())))->response()->setStatusCode(202);
    }
}
