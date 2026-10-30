<?php

namespace App\Support\Rbac;

use InvalidArgumentException;

/** Đọc config/rbac.php thành các cấu trúc dễ dùng. Mọi thứ ở đây là hằng số của ứng dụng, không phụ thuộc DB. */
class Catalog
{
    public const SCOPES = ['own' => 1, 'branch' => 2, 'tenant' => 3];

    public static function scopeRank(?string $scope): int
    {
        return self::SCOPES[$scope] ?? 0;
    }

    /** @return array<string, array{label: string, actions: list<string>}> */
    public static function resources(): array
    {
        $map = config('rbac.actions');
        $out = [];

        foreach (config('rbac.resources') as $resource => [$label, $letters]) {
            $out[$resource] = [
                'label' => $label,
                'actions' => array_map(fn (string $l) => $map[$l], str_split($letters)),
            ];
        }

        return $out;
    }

    /** @return array<string, array{resource: string, action: string, label: string}> key => thông tin */
    public static function permissions(): array
    {
        $out = [];

        foreach (self::resources() as $resource => $info) {
            foreach ($info['actions'] as $action) {
                $out["{$resource}.{$action}"] = ['resource' => $resource, 'action' => $action, 'label' => $info['label']];
            }
        }

        return $out;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::permissions());
    }

    /** @return list<string> */
    public static function roleKeys(): array
    {
        return array_keys(config('rbac.roles'));
    }

    /**
     * Quyền mặc định của vai trò hệ thống, đã mở rộng: permission key => scope (lấy scope rộng nhất nếu trùng).
     *
     * @return array<string, string>
     */
    public static function roleGrants(string $roleKey): array
    {
        $role = config("rbac.roles.{$roleKey}") ?? throw new InvalidArgumentException("Vai trò không tồn tại: {$roleKey}");

        if (isset($role['all'])) {
            return array_fill_keys(array_keys(self::permissions()), $role['all']);
        }

        $actionOf = config('rbac.actions'); // chữ cái => action
        $grants = [];

        foreach ($role['grants'] as $resource => $segments) {
            $supported = self::resources()[$resource]['actions'] ?? throw new InvalidArgumentException("Resource không tồn tại: {$resource}");

            foreach ((array) $segments as $segment) {
                [$scope, $chars] = explode(':', $segment, 2);

                if (! isset(self::SCOPES[$scope])) {
                    throw new InvalidArgumentException("Scope không hợp lệ: {$scope}");
                }

                foreach (str_split($chars) as $char) {
                    $action = $actionOf[$char] ?? throw new InvalidArgumentException("Chữ cái hành động không hợp lệ: {$char}");

                    if (! in_array($action, $supported, true)) {
                        throw new InvalidArgumentException("Resource {$resource} không hỗ trợ hành động {$action}");
                    }

                    $key = "{$resource}.{$action}";
                    if (self::scopeRank($scope) > self::scopeRank($grants[$key] ?? null)) {
                        $grants[$key] = $scope;
                    }
                }
            }
        }

        return $grants;
    }

    /**
     * Quyền $needed có nằm trong phạm vi quyền $have không (mọi key đều có và scope >=).
     *
     * @param  array<string, string>  $have
     * @param  array<string, string>  $needed
     */
    public static function covers(array $have, array $needed): bool
    {
        return self::uncovered($have, $needed) === [];
    }

    /**
     * Các quyền trong $needed mà $have KHÔNG đáp ứng (thiếu quyền hoặc scope hẹp hơn): key => scope yêu cầu.
     *
     * @param  array<string, string>  $have
     * @param  array<string, string>  $needed
     * @return array<string, string>
     */
    public static function uncovered(array $have, array $needed): array
    {
        return array_filter(
            $needed,
            fn (string $scope, string $key) => self::scopeRank($have[$key] ?? null) < self::scopeRank($scope),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
