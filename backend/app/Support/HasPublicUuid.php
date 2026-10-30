<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Khóa công khai của API là uuid; id số nguyên chỉ dùng nội bộ. */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function ($model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
