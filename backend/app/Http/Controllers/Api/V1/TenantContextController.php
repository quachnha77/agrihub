<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\HostTenant;
use Illuminate\Http\JsonResponse;

/**
 * Công khai (chưa đăng nhập): địa chỉ này là của nền tảng hay của doanh nghiệp nào, để trang đăng nhập hiển thị đúng tên.
 * Chỉ trả tên và slug (vốn đã nằm trong chính địa chỉ). Địa chỉ không có doanh nghiệp đã bị middleware từ chối với 404.
 */
class TenantContextController extends Controller
{
    public function show(HostTenant $host): JsonResponse
    {
        $tenant = $host->tenant();

        return response()->json(['data' => [
            'kind' => $host->kind(),
            'tenant' => $tenant === null ? null : ['name' => $tenant['name'], 'slug' => $tenant['slug']],
        ]]);
    }
}
