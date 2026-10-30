<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BackupResource;
use App\Models\Backup;
use App\Services\Backup\PlatformBackupMonitor;
use App\Services\Backup\PlatformBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super Admin: sao lưu TOÀN HỆ THỐNG (mọi Tenant). Xem danh sách/trạng thái, yêu cầu chạy một bản mới và TẢI FILE về lưu riêng.
 *
 * Tải về là thao tác nhạy cảm (file chứa dữ liệu mọi khách hàng, tuy đã mã hóa): phải nhập lại mật khẩu để nhận liên kết
 * dùng một lần, và luôn ghi audit. Cố ý KHÔNG có khôi phục qua HTTP: khôi phục chỉ làm được trên máy chủ bằng dòng lệnh
 * (agrihub:backup:restore-platform), vào một database mới.
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

    /** Tình trạng tổng hợp: bản mới nhất, kết quả khôi phục thử, lần tải gần nhất và các vấn đề đang tồn tại. */
    public function status(PlatformBackupMonitor $monitor): JsonResponse
    {
        return response()->json(['data' => $monitor->status()]);
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

    /** Bước 1 của việc tải về: nhập lại mật khẩu để nhận liên kết dùng một lần (hết hạn sau 2 phút). */
    public function downloadLink(Request $request, Backup $backup): JsonResponse
    {
        abort_unless($backup->isPlatform(), 404);

        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $this->backups->issueDownloadLink($request->user(), $backup, $data['password'])]);
    }

    /** Bước 2: tải file bằng liên kết đã ký (middleware `signed:relative` kiểm tra chữ ký và hạn). */
    public function download(Request $request, Backup $backup): StreamedResponse
    {
        abort_unless($backup->isPlatform(), 404);

        $data = $request->validate(['nonce' => ['required', 'string', 'size:40']]);

        return $this->backups->download($backup, $request->user(), $data['nonce']);
    }
}
