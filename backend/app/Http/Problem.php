<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/** Lỗi API theo RFC 7807 (application/problem+json) kèm `code` ổn định cho client. */
class Problem
{
    public static function response(int $status, string $code, string $title, ?string $detail = null, array $extra = []): JsonResponse
    {
        return response()->json(
            array_merge([
                'type' => 'https://agrihub.local/problems/'.str_replace('_', '-', $code),
                'title' => $title,
                'status' => $status,
                'detail' => $detail ?? $title,
                'code' => $code,
                'request_id' => app()->bound('request') ? request()->attributes->get('request_id') : null,
            ], $extra),
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
