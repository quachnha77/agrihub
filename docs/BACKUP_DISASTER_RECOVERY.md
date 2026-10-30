# AgriHub – Backup & Disaster Recovery

Trạng thái: **Sao lưu/khôi phục theo Tenant (bước 8a, mục 7) và sao lưu toàn hệ thống bằng `pg_dump` kèm lịch tự động, retention, kiểm thử restore (bước 8b, mục 8) đã triển khai và có test.** **Chưa làm:** WAL/PITR, sao chép offsite tự động, cảnh báo khi sao lưu thất bại/quá hạn (8c). **Chưa có backup production nào được thiết lập; không được tuyên bố đạt chuẩn DR cho đến khi có bản ghi kiểm thử restore thành công trên môi trường thật (mục 5).**

## 1. Phạm vi backup
Database PostgreSQL; ảnh/file/chứng từ/chữ ký (object storage); cấu hình cần thiết (không gồm secrets dạng rõ – secrets lưu trong secret manager có quy trình riêng).

## 1b. Lưu ý riêng cho audit log
`audit_logs` là bảng phân vùng theo tháng, chỉ ghi thêm. Backup phải gồm mọi phân vùng; trước khi bật `AUDIT_PURGE_ENABLED` (xóa phân vùng quá hạn) cần có bản lưu trữ lâu dài (ví dụ `pg_dump` từng phân vùng, mã hóa, lưu offsite) vì DROP phân vùng là vĩnh viễn. Khi restore theo Tenant, KHÔNG ghi đè audit log: chỉ thêm dòng "restore" mới (xem `restore_runs`).

## 2. Chiến lược
- PostgreSQL: dump logic hằng ngày (`pg_dump -Fc`) + WAL archiving/PITR (mục tiêu RPO ≤ 15 phút khi bật).
- File: đồng bộ tăng dần (versioned bucket).
- Lưu trên hạ tầng **độc lập production** + bản **offsite** (nhà cung cấp/khu vực khác).
- Mã hóa (age/GPG hoặc SSE-KMS), khóa lưu tách khỏi backup.
- Kiểm tra toàn vẹn: checksum SHA-256 + `pg_restore --list`.
- Cảnh báo khi job thất bại hoặc quá hạn (kênh email/Telegram/Zalo – *chờ xác nhận*).
- Ghi `backup_runs` (thời điểm, kích thước, checksum, kết quả).

## 3. Retention (cấu hình được)
Daily 30 ngày · Weekly 12 tuần · Monthly 12 tháng.

## 4. Khôi phục
| Tình huống | Quy trình |
|---|---|
| Toàn hệ thống | Dựng hạ tầng từ Docker/IaC → restore DB (PITR nếu có) → restore file → kiểm tra checklist → mở traffic |
| Theo Tenant | Restore bản backup vào DB tạm → export dữ liệu lọc `tenant_id` → import có kiểm soát vào production sau khi sao lưu trạng thái hiện tại; có audit |
| Xóa nhầm 1 bản ghi | Khôi phục có chọn lọc từ DB tạm |

Mục tiêu tham khảo: RTO ≤ 4 giờ, RPO ≤ 24 giờ (dump) / ≤ 15 phút (PITR). *Chờ xác nhận.*

## 5. Kiểm thử restore
- Tự động hằng tuần: restore bản mới nhất vào môi trường tạm, chạy kiểm tra (đếm bản ghi, đối chiếu tổng công nợ/tồn kho).
- Diễn tập DR toàn phần mỗi quý; ghi kết quả vào tài liệu.
- **Không được tuyên bố backup đạt chuẩn khi chưa có bản ghi kiểm thử restore thành công.**

## 6. Giao diện Backup/Restore cho quản trị viên (đã chốt 2026-10-30)
- Màn hình danh sách backup **theo ngày/giờ** (loại, kích thước, checksum, trạng thái, người tạo).
- **Tạo backup thủ công**, **Export** (tải file đã mã hóa), **Import** (tải lên file backup để restore).
- Phạm vi (đã chốt: cả hai): Super Admin → toàn hệ thống hoặc chọn một Tenant; Tenant Owner → chỉ dữ liệu Tenant của mình.
- Quy tắc an toàn khi Import/Restore:
  1. Chỉ chấp nhận file do AgriHub tạo (chữ ký + checksum + phiên bản schema); từ chối file hỏng/không khớp.
  2. Tự động tạo **backup trước khi restore** (điểm quay lại).
  3. Restore Tenant chạy trong transaction/DB tạm, kiểm tra tenant_id đúng; không bao giờ ghi vào Tenant khác.
  4. Xác nhận 2 bước (nhập lại mật khẩu/2FA + gõ tên Tenant) và đặt Tenant ở chế độ bảo trì trong lúc restore.
  5. Mọi thao tác ghi audit và `restore_runs`; thông báo cho Owner.
  6. File export mã hóa; tải qua link ngắn hạn; giới hạn tần suất và kích thước.

## 7. Hiện thực sao lưu / khôi phục theo Tenant (bước 8a – đã triển khai)

**Phạm vi**: dữ liệu **nghiệp vụ** của một Tenant, gồm các bảng liệt kê trong `backend/config/backup.php` → `tenant_tables` (thứ tự cha → con). Hiện danh sách này **trống** vì chưa có module nghiệp vụ; mỗi module Giai đoạn 2 phải thêm bảng của mình vào đây. Một test (`test_every_table_with_a_tenant_id_is_either_backed_up_or_explicitly_excluded`) **fail nếu có bảng `tenant_id` không nằm trong `tenant_tables` hoặc `excluded_tenant_tables`**, nên không thể quên. Cố ý **không** nằm trong sao lưu theo Tenant: tài khoản/mật khẩu, chi nhánh, vai trò/phân quyền (định danh – khôi phục dữ liệu cũ không được đổi quyền hiện tại), thuê bao (do AgriHub quản lý), `audit_logs` (không bao giờ bị ghi đè).

**File sao lưu** (`.agbk`): NDJSON nén gzip (đầu file có Tenant, phiên bản định dạng, schema và cột từng bảng; cuối file có số dòng) rồi **mã hóa + xác thực theo luồng bằng libsodium secretstream (XChaCha20-Poly1305)**. Khóa suy ra từ `BACKUP_ENCRYPTION_KEY` (hoặc `APP_KEY` nếu để trống). Sửa/cắt/ghép/đảo khối hoặc sai khóa đều bị phát hiện ⇒ **chỉ chấp nhận file do chính hệ thống này tạo ra và còn nguyên vẹn**. **Mất khóa = mất khả năng đọc bản sao lưu**: cất khóa tách khỏi nơi lưu file, và ghi lại khi xoay `APP_KEY`. Lưu trên disk `backups` (riêng tư, `storage/app/backups`; có thể đổi sang S3 qua `BACKUP_DISK`, khi đó cần bổ sung tải về thư mục tạm trước khi khôi phục).

**Luồng**: yêu cầu qua API → **job nền** (`RunTenantBackupJob`, `RunTenantRestoreJob`; cần `queue:work`) → kết quả trong `backups` / `restore_runs`. Mỗi bản có ngày giờ, loại (`manual|auto|upload|pre_restore`), trạng thái, kích thước, **checksum SHA-256**, số dòng từng bảng, phiên bản schema, người tạo.

**Export / Import**: Export = tải file `.agbk` (xác thực quyền `backup.backup.export`, ghi audit, `Cache-Control: no-store`; không dùng link công khai nên không thể lộ qua URL). Import = tải lên file; hệ thống **giải mã và kiểm tra toàn bộ ngay** (đúng định dạng/phiên bản, **đúng Tenant**, chỉ bảng được phép, cột còn tồn tại, mỗi dòng đủ cột, giá trị là kiểu đơn giản, số dòng khớp phần kết thúc). File không hợp lệ bị từ chối `422 backup_invalid` và để lại một dòng `failed` trong danh sách (kèm lý do) để truy vết. Giới hạn `BACKUP_MAX_UPLOAD_MB` (512) và `BACKUP_MAX_UNCOMPRESSED_MB` (4096, chống file nén phình to), 10 lần/phút/người dùng.

**Khôi phục** (`POST /backups/{id}/restore`), các quy tắc an toàn đã chốt ở mục 6:
1. Cần **nhập lại mật khẩu của chính mình + gõ đúng tên doanh nghiệp** (sai ⇒ `422 confirmation_failed`, ghi audit `denied`, không làm gì).
2. Kiểm tra checksum file trên disk; hỏng ⇒ dừng.
3. Đặt Tenant ở **chế độ bảo trì** (`tenants.restoring_at`): mọi request của Tenant trả `503 tenant_restoring` trong lúc khôi phục (tự hết hiệu lực sau `restore_lock_minutes` = 120 nếu job chết giữa chừng).
4. Tự tạo bản **`pre_restore`** (điểm quay lại) trước khi ghi đè; không tạo được thì **không khôi phục**. Có thể khôi phục lại chính bản này để hoàn tác.
5. Trong **một transaction**: xóa dữ liệu hiện tại của Tenant ở các bảng **có trong file** (con trước cha) rồi nạp lại với **id gốc**; `tenant_id` của mọi dòng bị **ghi đè bằng Tenant đang khôi phục** (file không thể ghi sang Tenant khác; RLS chặn thêm một lớp); lỗi bất kỳ ⇒ rollback toàn bộ, dữ liệu nguyên vẹn. Sau đó đưa sequence lên ≥ id lớn nhất.
6. Bảng không có trong file (module thêm sau thời điểm sao lưu) **không bị xóa**. Nếu dữ liệu con còn tham chiếu dữ liệu cha mà file không chứa con ⇒ vi phạm khóa ngoại ⇒ rollback.
7. Mọi bước ghi audit (`backup.created|uploaded|downloaded|restore_requested|restored|restore_failed|restore_confirmation_failed`) thuộc về Tenant nên Chủ doanh nghiệp xem được trong nhật ký của mình (kể cả khi do Super Admin làm).

**Phân quyền**: chỉ **Chủ doanh nghiệp** (`backup.backup.{view,create,export,restore}`); Admin và các vai trò khác bị 403. **Super Admin** thao tác trên một Tenant được chọn qua `/platform/tenants/{id}/backups…` với cùng quy tắc (xác nhận bằng mật khẩu của chính Super Admin + tên doanh nghiệp). Phiên hỗ trợ (chỉ đọc) không dùng được `export`/`restore`.

**Giới hạn đã biết**
- Chưa có thông báo chủ động cho Owner khi Super Admin khôi phục (chỉ có audit) — chờ nền tảng Notification.
- Cột nhị phân (`bytea`) chưa được hỗ trợ: sao lưu sẽ báo lỗi rõ ràng; module cần lưu file phải dùng bảng `files` (đường dẫn) thay vì bytea.
- Danh sách `tenant_tables` rỗng cho đến khi có module nghiệp vụ ⇒ hiện bản sao lưu chỉ chứa phần đầu/cuối (đã kiểm thử khuôn khổ bằng bảng giả có khóa ngoại + RLS).
- Dung lượng lớn: job đọc/ghi theo luồng (bộ nhớ không phụ thuộc kích thước) nhưng thời gian khôi phục tỉ lệ với dữ liệu; cần đo lại khi có dữ liệu thật.
- Lịch tự động, retention và kiểm thử restore định kỳ hiện chỉ có cho sao lưu **toàn hệ thống** (mục 8, bao trùm mọi Tenant); sao lưu theo Tenant chưa có lịch riêng — sẽ cân nhắc khi có dữ liệu nghiệp vụ.

## 8. Sao lưu toàn hệ thống bằng pg_dump (bước 8b – đã triển khai)

**Mục đích**: khôi phục thảm họa (mất database hoặc máy chủ). Một bản chứa **dữ liệu của mọi Tenant**, nên được bảo vệ chặt hơn sao lưu theo Tenant:
- Tenant không bao giờ thấy được: bản ghi `scope = 'platform'`, `tenant_id = NULL` (RLS chặn; ràng buộc CHECK `backups_scope_tenant_check` bảo đảm "platform ⇔ tenant_id NULL").
- **Không có API tải về, không có API khôi phục.** Super Admin chỉ xem danh sách và yêu cầu chạy một bản mới (`/platform/backups`). Chép ra nơi lưu ngoài và khôi phục chỉ làm được trên máy chủ bằng dòng lệnh.

**Tài khoản database RIÊNG (bắt buộc)**: RLS đang bật FORCE nên tài khoản ứng dụng chỉ thấy dữ liệu của Tenant hiện tại; `pg_dump` bằng tài khoản đó sẽ lỗi hoặc cho bản rỗng. Hệ thống **từ chối chạy** nếu tài khoản sao lưu không có `BYPASSRLS` (hoặc superuser). Tạo role chuyên dụng, **không phải superuser**:
```sql
CREATE ROLE agrihub_backup LOGIN PASSWORD '<mật khẩu mạnh>' NOSUPERUSER CREATEDB BYPASSRLS;
GRANT pg_read_all_data TO agrihub_backup;      -- đọc mọi bảng
GRANT <role_của_ứng_dụng> TO agrihub_backup;   -- để tạo database đích thuộc sở hữu của ứng dụng khi khôi phục
```
Đặt `BACKUP_DB_USERNAME` / `BACKUP_DB_PASSWORD` (và `BACKUP_PG_BIN` nếu `pg_dump` không nằm trong PATH). Chỉ **queue worker, scheduler và dòng lệnh** cần các biến này; tiến trình web không cần và không nên có. `pg_dump`/`pg_restore` phải **cùng phiên bản chính** với máy chủ PostgreSQL.

**Cách sao lưu** (`PlatformDump`): mở một transaction `REPEATABLE READ`, xuất snapshot, **đếm số dòng từng bảng trong chính snapshot đó**, rồi chạy `pg_dump --format=custom --no-owner --no-acl --snapshot=…` — nên số dòng đối chiếu là chính xác dù hệ thống đang chạy. Nội dung `AGRIHUB-PLATFORM-DUMP/1` + một dòng JSON mô tả (manifest: thời điểm, phiên bản máy chủ, migration mới nhất, bảng bỏ dữ liệu, số dòng từng bảng) + kho `pg_dump`, được **mã hóa theo luồng ngay từ đầu ra của `pg_dump`** (cùng cơ chế và khóa như mục 7; **không có bản rõ nào ra đĩa**). Manifest nằm trong file nên **chỉ cần file + khóa** là khôi phục và đối chiếu được, kể cả khi database đã mất. Lưu ở `platform/YYYY/MM/<uuid>.agbk` trên disk `backups`, kèm checksum SHA-256. `pg_dump` thoát mã khác 0 thì bản ghi `failed`, **không có file nào được lưu**.

**Dữ liệu bỏ có chủ đích** (giữ cấu trúc, bỏ dữ liệu; cấu hình `backup.platform.exclude_table_data`): `sessions`, `password_reset_tokens`, `personal_access_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`. Lý do: sau khôi phục, phiên/token cũ (kể cả đã bị thu hồi sau thời điểm sao lưu) không được sống lại, hàng đợi không chạy lại việc đã làm. **Hệ quả: sau khôi phục mọi người phải đăng nhập lại.**

**Lịch tự động** (scheduler phải chạy; chỉ hoạt động khi `BACKUP_PLATFORM_ENABLED=true`, mặc định bật ở production):
| Giờ | Lệnh | Việc |
|---|---|---|
| 01:30 hằng ngày | `agrihub:backup:platform --scheduled` | sao lưu, loại `auto` |
| 03:30 hằng ngày | `agrihub:backup:prune --scheduled` | dọn bản cũ theo retention |
| 04:30 Chủ nhật | `agrihub:backup:verify --scheduled` | khôi phục thử bản mới nhất |

Giờ/ngày đổi qua `BACKUP_PLATFORM_AT`, `BACKUP_PRUNE_AT`, `BACKUP_VERIFY_AT`, `BACKUP_VERIFY_DAY`. Các lệnh luôn được đăng ký (thấy trong `schedule:list`); cờ `--scheduled` làm chúng bỏ qua khi tắt, còn chạy tay không cờ thì luôn chạy. Không chạy chồng: một bản "chờ/đang chạy" chưa quá `stale_hours` (12 giờ) chặn bản mới (`409 backup_in_progress` / lệnh báo lỗi); quá hạn thì được đánh dấu "bị gián đoạn".

**Giữ bản (retention)** — chỉ áp cho bản **tự động** đã hoàn tất, cấu hình ở `backup.platform.retention`: mọi bản trong **30 ngày**; sau đó **mỗi tuần một bản** (mới nhất của tuần) trong **12 tuần**; sau đó **mỗi tháng một bản** trong **12 tháng**; còn lại bị xóa (cả file lẫn bản ghi). **Luôn giữ ít nhất 3 bản mới nhất** (lịch ngừng chạy cũng không bị xóa sạch). **Bản thủ công không bao giờ tự xóa.** Bản lỗi quá 30 ngày được dọn. Có `--dry-run` để xem trước; mỗi lần xóa ghi audit `backup.platform_pruned`.

**Kiểm thử khôi phục** (`agrihub:backup:verify [uuid]`): kiểm tra checksum file, tạo database tạm `agrihub_verify_<ngẫu nhiên>`, xác thực **toàn bộ** file rồi nạp bằng `pg_restore --exit-on-error`, **đối chiếu số dòng từng bảng với manifest** (đủ bảng, không thừa bảng, bảng bỏ dữ liệu phải rỗng), rồi xóa database tạm (chỉ xóa được tên mang tiền tố `agrihub_verify_` hoặc database đích do chính lần chạy tạo ra; từ chối xóa database đang chạy). Kết quả lưu vào `backups.verify_status` (`passed|failed`), `verified_at`, `verify_error` và audit `platform_verified|platform_verify_failed`; lệnh trả mã thoát ≠ 0 khi không đạt.

**Khôi phục thảm họa** (chỉ dòng lệnh, trên máy chủ): `php artisan agrihub:backup:restore-platform {uuid | --file=<đường dẫn .agbk>} --target=<tên database MỚI> [--owner=<role>|--no-owner] [--yes]`. Lệnh **không bao giờ ghi đè database đang chạy**: từ chối nếu `--target` trùng database của ứng dụng/đang sao lưu hoặc đã tồn tại; tạo database mới (mặc định thuộc role của ứng dụng), nạp, đối chiếu số dòng, và **xóa database vừa tạo nếu bất kỳ bước nào thất bại** (không để lại bản khôi phục dở dang). `--file` dùng được khi mất cả bảng `backups`.
Quy trình khi mất database/máy chủ:
1. Dựng PostgreSQL cùng phiên bản chính, tạo role ứng dụng và role sao lưu (SQL ở trên), cài ứng dụng với **đúng `BACKUP_ENCRYPTION_KEY` / `APP_KEY` đã dùng lúc sao lưu**.
2. Chép file `.agbk` mới nhất từ nơi lưu ngoài về máy chủ.
3. `php artisan agrihub:backup:restore-platform --file=/đường/dẫn/x.agbk --target=agrihub_restored`.
4. Kiểm tra dữ liệu; đặt `DB_DATABASE=agrihub_restored`, khởi động lại ứng dụng + queue + scheduler; chạy `php artisan migrate --force` nếu mã nguồn mới hơn bản sao lưu.
5. Chạy `php artisan agrihub:backup:platform` để có bản đầu tiên trên hệ thống mới; ghi kết quả vào sổ diễn tập.

**Audit** (module `backup`, không thuộc Tenant nào, chỉ Super Admin xem): `platform_created|platform_failed|platform_verified|platform_verify_failed|platform_pruned|platform_restored|platform_restore_failed`. Việc khôi phục thảm họa vẫn hoàn tất nếu không ghi được audit (database ứng dụng có thể đã mất).

**Giới hạn đã biết — đọc trước khi tin vào hệ thống**
- **Bản sao lưu hiện nằm cùng máy với database.** Chưa có sao chép offsite tự động: cần cron `rclone`/`rsync`/S3 từ `storage/app/backups/platform` sang nơi **độc lập** (nhà cung cấp/khu vực khác). Cho tới khi có, chưa chống được mất máy chủ. Disk S3 chưa được hỗ trợ trực tiếp (cần tải về thư mục tạm).
- Chỉ là dump logic theo thời điểm: **RPO ≤ 24 giờ**. WAL archiving/PITR chưa có.
- Chưa có cảnh báo chủ động khi sao lưu thất bại hoặc quá hạn (sẽ làm ở 8c); hiện chỉ có `backups.status=failed`, audit, log và mã thoát của lệnh.
- Kiểm thử khôi phục mới đối chiếu **số dòng**; chưa đối chiếu nghiệp vụ (tổng công nợ, tồn kho) vì chưa có module — bổ sung khi có.
- Đếm `count(*)` từng bảng khi sao lưu: chậm với bảng hàng chục triệu dòng; cần đo lại khi có dữ liệu thật.
- Các bản `pre_restore` của Tenant (mục 7) chưa có chính sách dọn.
- **Chưa diễn tập khôi phục thảm họa trên môi trường production thật.** Mới được kiểm thử tự động (database nguồn giả có RLS FORCE, phân vùng, UTF-8) và chạy thử trên database phát triển.
