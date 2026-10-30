<?php

namespace App\Services\Monitoring;

use App\Services\Backup\PlatformBackupMonitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Đánh giá sức khỏe hạ tầng và dịch vụ của AgriHub: database (kết nối, độ trễ, RLS), hàng đợi, scheduler, đĩa, cấu hình an toàn
 * và sao lưu. Một nguồn duy nhất cho trang "Sức khỏe hệ thống", endpoint cho công cụ giám sát và cảnh báo.
 *
 * Không gọi dịch vụ ngoài và không ném lỗi: thành phần không kiểm tra được thì báo "nghiêm trọng" kèm lý do ngắn gọn.
 * Ngưỡng nằm ở config/monitoring.php.
 */
class SystemHealth
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    /** Thành phần đang tắt có chủ đích (vd. sao lưu ở môi trường dev): không tính vào tình trạng chung. */
    public const DISABLED = 'disabled';

    private const RANK = [self::DISABLED => -1, self::OK => 0, self::WARNING => 1, self::CRITICAL => 2];

    public function __construct(private Heartbeats $beats, private PlatformBackupMonitor $backups) {}

    /**
     * @return array{status: string, checked_at: string, components: list<array{key: string, label: string, status: string, summary: string, details: list<array{label: string, value: string}>}>}
     */
    public function evaluate(): array
    {
        $components = [
            $this->guard('database', 'Cơ sở dữ liệu', fn () => $this->database()),
            $this->guard('rls', 'Cô lập dữ liệu giữa doanh nghiệp (RLS)', fn () => $this->rls()),
            $this->guard('queue', 'Hàng đợi công việc', fn () => $this->queue()),
            $this->guard('scheduler', 'Bộ lập lịch (scheduler)', fn () => $this->heartbeat(Heartbeats::SCHEDULER, 'Scheduler', 'php artisan schedule:work')),
            $this->guard('queue_worker', 'Queue worker', fn () => $this->heartbeat(Heartbeats::QUEUE_WORKER, 'Queue worker', 'php artisan queue:work')),
            $this->guard('disk', 'Dung lượng đĩa', fn () => $this->disk()),
            $this->guard('config', 'Cấu hình an toàn', fn () => $this->config()),
            $this->guard('backups', 'Sao lưu', fn () => $this->backupsComponent()),
        ];

        $worst = self::OK;

        foreach ($components as $component) {
            if (self::RANK[$component['status']] > self::RANK[$worst]) {
                $worst = $component['status'];
            }
        }

        return ['status' => $worst, 'checked_at' => now()->toIso8601String(), 'components' => $components];
    }

    public static function rank(string $status): int
    {
        return self::RANK[$status] ?? 0;
    }

    // --- Các thành phần ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function database(): array
    {
        $t = (array) config('monitoring.thresholds');

        $started = hrtime(true);
        DB::select('select 1');
        $ms = round((hrtime(true) - $started) / 1_000_000, 1);

        $connections = (int) DB::scalar("select count(*) from pg_stat_activity where backend_type = 'client backend'");
        $max = max(1, (int) DB::scalar('show max_connections'));
        $percent = (int) round($connections * 100 / $max);
        $version = (string) DB::scalar('show server_version');

        $status = self::OK;
        $notes = [];

        if ($ms > $t['db_latency_warn_ms']) {
            $status = self::WARNING;
            $notes[] = "truy vấn chậm ({$ms} ms)";
        }

        if ($percent >= $t['db_connections_critical_percent']) {
            $status = self::CRITICAL;
            $notes[] = "gần hết kết nối ({$connections}/{$max})";
        } elseif ($percent >= $t['db_connections_warn_percent']) {
            $status = $status === self::OK ? self::WARNING : $status;
            $notes[] = "nhiều kết nối ({$connections}/{$max})";
        }

        return $this->component('database', 'Cơ sở dữ liệu', $status,
            $notes === [] ? "Kết nối bình thường ({$ms} ms)" : 'Cần chú ý: '.implode('; ', $notes),
            [['Độ trễ truy vấn', "{$ms} ms"], ['Kết nối đang dùng', "{$connections}/{$max} ({$percent}%)"], ['Phiên bản PostgreSQL', $version]]);
    }

    /** Ứng dụng phải kết nối bằng role thường: superuser/BYPASSRLS bỏ qua RLS nên dữ liệu các doanh nghiệp không còn được cô lập. */
    private function rls(): array
    {
        $role = DB::selectOne('select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user');
        $bypass = $role !== null && ($role->rolsuper || $role->rolbypassrls);

        return $this->component('rls', 'Cô lập dữ liệu giữa doanh nghiệp (RLS)', $bypass ? self::CRITICAL : self::OK,
            $bypass
                ? 'Ứng dụng đang kết nối bằng tài khoản database có quyền bỏ qua RLS: dữ liệu giữa các doanh nghiệp KHÔNG còn được cô lập. Hãy dùng role thường (NOSUPERUSER, NOBYPASSRLS).'
                : 'Ứng dụng kết nối bằng tài khoản thường; RLS có hiệu lực.',
            [['Tài khoản ứng dụng', (string) ($role->name ?? '?')]]);
    }

    private function queue(): array
    {
        $t = (array) config('monitoring.thresholds');
        $driver = (string) config('queue.default');
        $production = app()->environment('production');
        $details = [['Trình điều khiển', $driver]];
        $status = self::OK;
        $notes = [];

        if ($driver === 'sync') {
            if ($production) {
                $status = self::WARNING;
                $notes[] = 'queue chạy đồng bộ (sync): sao lưu và việc nặng sẽ chạy ngay trong yêu cầu web';
            }

            return $this->component('queue', 'Hàng đợi công việc', $status, $notes === [] ? 'Chạy đồng bộ (môi trường phát triển).' : 'Cần chú ý: '.implode('; ', $notes), $details);
        }

        if ($driver !== 'database') {
            return $this->component('queue', 'Hàng đợi công việc', self::OK, "Dùng {$driver}; chưa có chỉ số chi tiết cho trình điều khiển này.", $details);
        }

        $table = (string) config('queue.connections.database.table', 'jobs');
        $pending = DB::table($table)->whereNull('reserved_at')->count();
        $running = DB::table($table)->whereNotNull('reserved_at')->count();
        $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');
        $oldestMinutes = $oldest === null ? 0 : max(0, intdiv(now()->getTimestamp() - (int) $oldest, 60));
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        if ($oldestMinutes >= $t['queue_oldest_critical_minutes']) {
            $status = self::CRITICAL;
            $notes[] = "việc chờ lâu nhất đã {$oldestMinutes} phút (worker có thể đã dừng)";
        } elseif ($oldestMinutes >= $t['queue_oldest_warn_minutes']) {
            $status = self::WARNING;
            $notes[] = "việc chờ lâu nhất đã {$oldestMinutes} phút";
        }

        if ($pending >= $t['queue_backlog_warn']) {
            $status = $status === self::OK ? self::WARNING : $status;
            $notes[] = "{$pending} việc đang chờ";
        }

        if ($failed >= $t['failed_jobs_critical']) {
            $status = self::CRITICAL;
            $notes[] = "{$failed} việc lỗi trong 24 giờ qua";
        } elseif ($failed >= $t['failed_jobs_warn']) {
            $status = $status === self::OK ? self::WARNING : $status;
            $notes[] = "{$failed} việc lỗi trong 24 giờ qua";
        }

        $details = array_merge($details, [['Đang chờ', (string) $pending], ['Đang chạy', (string) $running], ['Chờ lâu nhất', "{$oldestMinutes} phút"], ['Lỗi trong 24 giờ', (string) $failed]]);

        return $this->component('queue', 'Hàng đợi công việc', $status, $notes === [] ? 'Hàng đợi thông suốt.' : 'Cần chú ý: '.implode('; ', $notes), $details);
    }

    private function heartbeat(string $name, string $label, string $command): array
    {
        $t = (array) config('monitoring.thresholds');
        $last = $this->beats->last($name);

        if ($last === null) {
            // Chưa từng có tín hiệu: ở production là sự cố thật; ở nơi khác thường chỉ là chưa bật tiến trình nền.
            return $this->component($name, $label, app()->environment('production') ? self::CRITICAL : self::WARNING,
                "{$label} chưa từng gửi tín hiệu sống. Hãy chạy `{$command}` (và đảm bảo nó được giám sát/tự khởi động lại).", [['Tín hiệu gần nhất', 'chưa có']]);
        }

        $minutes = max(0, intdiv(now()->getTimestamp() - $last->getTimestamp(), 60));
        $status = match (true) {
            $minutes >= $t['heartbeat_critical_minutes'] => self::CRITICAL,
            $minutes >= $t['heartbeat_warn_minutes'] => self::WARNING,
            default => self::OK,
        };

        return $this->component($name, $label, $status,
            $status === self::OK ? "{$label} đang hoạt động (tín hiệu {$minutes} phút trước)." : "{$label} không gửi tín hiệu đã {$minutes} phút; có thể đã dừng. Kiểm tra `{$command}`.",
            [['Tín hiệu gần nhất', $this->when($last)], ['Cách đây', "{$minutes} phút"]]);
    }

    private function disk(): array
    {
        $t = (array) config('monitoring.thresholds');
        $paths = ['Ứng dụng (storage)' => storage_path()];
        $disk = (string) config('backup.disk');

        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            $paths['Sao lưu'] = Storage::disk($disk)->path('');
        }

        $status = self::OK;
        $notes = [];
        $details = [];

        foreach ($paths as $label => $path) {
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);

            if ($free === false || $total === false || $total <= 0) {
                $status = $this->worst($status, self::WARNING);
                $notes[] = "{$label}: không đọc được dung lượng";

                continue;
            }

            $used = (int) round(100 * (1 - $free / $total));
            $details[] = ["Đĩa {$label}", sprintf('%d%% đã dùng, còn trống %.1f GB / %.1f GB', $used, $free / 1073741824, $total / 1073741824)];

            if ($used >= $t['disk_critical_percent']) {
                $status = self::CRITICAL;
                $notes[] = "{$label}: đã dùng {$used}%";
            } elseif ($used >= $t['disk_warn_percent']) {
                $status = $this->worst($status, self::WARNING);
                $notes[] = "{$label}: đã dùng {$used}%";
            }
        }

        if (! is_writable(storage_path('app'))) {
            $status = self::CRITICAL;
            $notes[] = 'không ghi được vào thư mục storage/app';
        }

        return $this->component('disk', 'Dung lượng đĩa', $status, $notes === [] ? 'Còn nhiều dung lượng trống.' : 'Cần chú ý: '.implode('; ', $notes), $details);
    }

    /** Cấu hình có thể làm hệ thống không an toàn khi chạy thật. Ngoài production chỉ thông tin. */
    private function config(): array
    {
        $issues = [];

        if (app()->environment('production')) {
            if (config('app.debug')) {
                $issues[] = [self::CRITICAL, 'APP_DEBUG đang bật ở production: lỗi có thể lộ thông tin nhạy cảm cho người dùng'];
            }

            if (! str_starts_with((string) config('app.url'), 'https://')) {
                $issues[] = [self::WARNING, 'APP_URL không dùng https'];
            }

            if (in_array(config('mail.default'), ['log', 'array'], true)) {
                $issues[] = [self::WARNING, 'MAIL_MAILER đang là "'.config('mail.default').'": email (cảnh báo, quên mật khẩu, quên tài khoản) chưa gửi ra ngoài'];
            }

            if (! in_array(config('session.driver'), ['database', 'redis'], true)) {
                $issues[] = [self::WARNING, 'SESSION_DRIVER nên là database hoặc redis (đăng xuất từ xa và thu hồi phiên cần phiên phía máy chủ)'];
            }

            if (config('queue.default') === 'sync') {
                $issues[] = [self::WARNING, 'QUEUE_CONNECTION=sync: việc nền chạy ngay trong yêu cầu web'];
            }

            if (in_array(config('cache.default'), ['array', 'file'], true)) {
                $issues[] = [self::WARNING, 'CACHE_STORE='.config('cache.default').' không dùng chung được giữa nhiều tiến trình/máy chủ'];
            }
        }

        // Mỗi doanh nghiệp một subdomain: cookie phiên phải theo từng host. SESSION_DOMAIN đặt chung làm mọi subdomain dùng chung cookie.
        $base = config('tenancy.base_domain');

        if ($base && config('session.domain')) {
            $issues[] = [self::CRITICAL, 'SESSION_DOMAIN đang đặt ("'.config('session.domain').'"): cookie phiên bị dùng chung giữa mọi subdomain nên các doanh nghiệp không còn tách biệt phiên. Hãy để trống'];
        }

        if (! $base && app()->environment('production')) {
            $issues[] = [self::WARNING, 'TENANT_BASE_DOMAIN chưa đặt: chưa tách doanh nghiệp theo subdomain'];
        }

        $status = self::OK;

        foreach ($issues as [$level]) {
            $status = $this->worst($status, $level);
        }

        return $this->component('config', 'Cấu hình an toàn', $status,
            $issues === [] ? (app()->environment('production') ? 'Cấu hình production hợp lý.' : 'Môi trường '.app()->environment().': chưa kiểm tra cấu hình production.') : 'Cần chú ý: '.implode('; ', array_column($issues, 1)),
            [['Môi trường', app()->environment()], ['Subdomain doanh nghiệp', $base ? "*.{$base}" : 'tắt'], ['APP_DEBUG', config('app.debug') ? 'bật' : 'tắt'], ['Mail', (string) config('mail.default')], ['Queue', (string) config('queue.default')], ['Session', (string) config('session.driver')]]);
    }

    private function backupsComponent(): array
    {
        $s = $this->backups->status();
        $status = match ($s['state']) {
            PlatformBackupMonitor::CRITICAL => self::CRITICAL,
            PlatformBackupMonitor::WARNING => self::WARNING,
            PlatformBackupMonitor::DISABLED => self::DISABLED,
            default => self::OK,
        };

        $summary = match (true) {
            $status === self::DISABLED => 'Sao lưu toàn hệ thống đang tắt (mặc định chỉ bật ở production).',
            $s['issues'] !== [] => implode(' ', array_column($s['issues'], 'message')),
            $s['last_completed'] !== null => "Bản sao lưu gần nhất {$s['last_completed']['age_hours']} giờ trước.",
            default => 'Chưa có bản sao lưu nào.',
        };

        return $this->component('backups', 'Sao lưu', $status, $summary, [['Chi tiết', 'Xem trang Sao lưu hệ thống']]);
    }

    // --- Trợ giúp -----------------------------------------------------------------------------

    /**
     * @param  list<array{0: string, 1: string}>  $details
     * @return array{key: string, label: string, status: string, summary: string, details: list<array{label: string, value: string}>}
     */
    private function component(string $key, string $label, string $status, string $summary, array $details = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'summary' => $summary,
            'details' => array_map(fn (array $d) => ['label' => $d[0], 'value' => $d[1]], $details),
        ];
    }

    /** Thành phần kiểm tra lỗi (vd. database chết) => nghiêm trọng với lý do ngắn gọn, không lộ chi tiết kết nối. */
    private function guard(string $key, string $label, callable $check): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return $this->component($key, $label, self::CRITICAL, 'Không kiểm tra được ('.class_basename($e).'). Xem nhật ký máy chủ.', []);
        }
    }

    private function worst(string $a, string $b): string
    {
        return self::RANK[$a] >= self::RANK[$b] ? $a : $b;
    }

    private function when(Carbon $at): string
    {
        return $at->copy()->timezone((string) config('backup.alerts.timezone'))->format('H:i:s d/m/Y');
    }
}
