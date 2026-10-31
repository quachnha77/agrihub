<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Mã tự sinh theo doanh nghiệp: tiền tố + số tăng dần (KH0001, NCC0001). Số kế tiếp = số lớn nhất đã dùng + 1, tính cả bản ghi đã xóa mềm
 * để không phát lại mã cũ. Hai yêu cầu cùng lúc có thể sinh trùng: chỉ mục duy nhất của database chặn, và `retrying` thử lại với mã mới.
 */
final class CodeSequence
{
    /**
     * @param  class-string<Model>  $model  model có cột `code` và TenantScope (chỉ đếm trong doanh nghiệp hiện tại)
     */
    public static function next(string $model, string $prefix): string
    {
        $query = $model::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        $max = (int) $query->whereRaw('code ~ ?', ['^'.$prefix.'[0-9]+$'])->max(DB::raw('substr(code, '.(strlen($prefix) + 1).')::int'));

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Chạy $attempt với mã mới; nếu trùng mã (người khác vừa lấy) thì thử lại tối đa 5 lần.
     *
     * @template T
     *
     * @param  Closure(string): T  $attempt
     * @return T
     */
    public static function retrying(string $model, string $prefix, Closure $attempt): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $attempt(self::next($model, $prefix));
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 5) {
                    throw $e;
                }
            }
        }
    }
}
