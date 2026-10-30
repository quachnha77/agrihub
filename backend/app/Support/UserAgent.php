<?php

namespace App\Support;

/** Tóm tắt User-Agent thành "Trình duyệt trên Hệ điều hành" để hiển thị cho người dùng (không dùng để phân quyền). */
final class UserAgent
{
    public static function summary(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $has = fn (string ...$needles) => collect($needles)->contains(fn ($n) => stripos($userAgent, $n) !== false);

        // Thứ tự quan trọng: Edge/Opera/Chrome đều chứa "Safari", Edge chứa "Chrome".
        $browser = match (true) {
            $has('Edg/', 'EdgA/', 'EdgiOS/') => 'Edge',
            $has('OPR/', 'Opera') => 'Opera',
            $has('Firefox/', 'FxiOS/') => 'Firefox',
            $has('Chrome/', 'CriOS/') => 'Chrome',
            $has('Safari/') => 'Safari',
            $has('curl/') => 'curl',
            default => 'Không rõ trình duyệt',
        };

        // Android chứa "Linux"; iPhone/iPad chứa "Mac OS X".
        $os = match (true) {
            $has('Android') => 'Android',
            $has('iPhone', 'iPad', 'iPod') => 'iOS',
            $has('Windows') => 'Windows',
            $has('Macintosh', 'Mac OS X') => 'macOS',
            $has('Linux', 'X11') => 'Linux',
            default => null,
        };

        return $os ? "{$browser} trên {$os}" : $browser;
    }
}
