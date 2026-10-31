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

**Phạm vi**: dữ liệu **nghiệp vụ** của một Tenant, gồm các bảng liệt kê trong `backend/config/backup.php` → `tenant_tables` (thứ tự cha → con). Hiện gồm danh mục sản phẩm (`units`, `categories`, `brands`, `products`, `product_units`); mỗi module Giai đoạn 2 tiếp theo phải thêm bảng của mình vào đây. Một test (`test_every_table_with_a_tenant_id_is_either_backed_up_or_explicitly_excluded`) **fail nếu có bảng `tenant_id` không nằm trong `tenant_tables` hoặc `excluded_tenant_tables`**, nên không thể quên. Cố ý **không** nằm trong sao lưu theo Tenant: tài khoản/mật khẩu, chi nhánh, vai trò/phân quyền (định danh – khôi phục dữ liệu cũ không được đổi quyền hiện tại), thuê bao (do AgriHub quản lý), `audit_logs` (không bao giờ bị ghi đè).

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

**Giao diện** (đã có, 2026-10-31): *Chủ doanh nghiệp* ở `/backups` (quyền `backup.backup.{view,create,export,restore}` quyết định nút nào hiện; quyền thật do API kiểm tra) và *Super Admin* ở `/platform/tenants/:id/backups` (nút "Sao lưu & khôi phục" ở trang chi tiết doanh nghiệp; dùng đường dẫn `/platform/tenants/{id}/backups…`). Cùng một khối giao diện cho cả hai: danh sách theo ngày giờ (tên file với bản tải lên), **Sao lưu ngay**, **Tải về**, **Nhập từ file** (chọn `.agbk`; máy chủ kiểm tra toàn bộ; file hỏng để lại dòng "Thất bại" kèm lý do), **Khôi phục** và **Lịch sử khôi phục** (bản đã dùng, điểm quay lại, kết quả). Hộp Khôi phục nêu rõ hậu quả (dữ liệu nghiệp vụ hiện tại bị thay, tự có điểm quay lại, doanh nghiệp tạm ngừng vài phút, tài khoản/chi nhánh/vai trò/gói/nhật ký không đổi), bắt nhập lại mật khẩu của chính mình và gõ đúng tên doanh nghiệp (nút chỉ mở khi tên khớp; máy chủ kiểm tra lại cả hai) và nói thẳng khi bản sao lưu chưa chứa dữ liệu nghiệp vụ nào. Trong lúc khôi phục máy chủ trả `503 tenant_restoring` cho mọi yêu cầu của doanh nghiệp: giao diện coi đó là "đang khôi phục" (không phải lỗi tải), ẩn danh sách, hỏi lại mỗi 3 giây và báo kết quả khi xong rồi làm mới mọi dữ liệu khác trên màn hình. Hoàn tác = khôi phục lại bản "Điểm quay lại" của lần đó.

**Giới hạn đã biết**
- Chưa có thông báo chủ động cho Owner khi Super Admin khôi phục (chỉ có audit) — chờ nền tảng Notification.
- Cột nhị phân (`bytea`) chưa được hỗ trợ: sao lưu sẽ báo lỗi rõ ràng; module cần lưu file phải dùng bảng `files` (đường dẫn) thay vì bytea.
- Danh sách `tenant_tables` mới có danh mục sản phẩm (2026-10-31; đã kiểm thử khôi phục và hoàn tác bằng bảng thật, kể cả cột JSONB và số thập phân) — chưa có khách hàng, kho, chứng từ. Trước đó bản sao lưu chỉ chứa phần đầu/cuối (đã kiểm thử khuôn khổ bằng bảng giả có khóa ngoại + RLS).
- Dung lượng lớn: job đọc/ghi theo luồng (bộ nhớ không phụ thuộc kích thước) nhưng thời gian khôi phục tỉ lệ với dữ liệu; cần đo lại khi có dữ liệu thật.
- Lịch tự động, retention và kiểm thử restore định kỳ hiện chỉ có cho sao lưu **toàn hệ thống** (mục 8, bao trùm mọi Tenant); sao lưu theo Tenant chưa có lịch riêng — sẽ cân nhắc khi có dữ liệu nghiệp vụ.

## 8. Sao lưu toàn hệ thống bằng pg_dump (bước 8b – đã triển khai)

**Mục đích**: khôi phục thảm họa (mất database hoặc máy chủ). Một bản chứa **dữ liệu của mọi Tenant**, nên được bảo vệ chặt hơn sao lưu theo Tenant:
- Tenant không bao giờ thấy được: bản ghi `scope = 'platform'`, `tenant_id = NULL` (RLS chặn; ràng buộc CHECK `backups_scope_tenant_check` bảo đảm "platform ⇔ tenant_id NULL").
- **Không có API khôi phục qua HTTP.** Super Admin xem danh sách/tình trạng, yêu cầu chạy một bản mới và **tải file (đã mã hóa) về để lưu riêng** (mục "Tải về nơi lưu riêng" bên dưới). Khôi phục chỉ làm được trên máy chủ bằng dòng lệnh.

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

**Audit** (module `backup`, không thuộc Tenant nào, chỉ Super Admin xem): `platform_created|platform_failed|platform_verified|platform_verify_failed|platform_pruned|platform_restored|platform_restore_failed`, và khi tải về: `platform_download_requested|platform_downloaded|platform_download_denied`. Việc khôi phục thảm họa vẫn hoàn tất nếu không ghi được audit (database ứng dụng có thể đã mất).

**Tải về nơi lưu riêng** (bản sao NGOÀI máy chủ là bắt buộc — xem giới hạn bên dưới):
1. `POST /platform/backups/{id}/download-link` với `{password}`: nhập lại **mật khẩu của chính Super Admin**. Sai ⇒ 422 và audit `platform_download_denied`. Đúng ⇒ nhận liên kết **tương đối, đã ký**, hết hạn sau **2 phút**, **dùng được đúng một lần**, chỉ đúng người và đúng bản đã xin (mã dùng một lần lưu ở cache, bị xóa ngay khi dùng).
2. Mở liên kết đó (trình duyệt tải thẳng, không cần header đặc biệt): server trả file `.agbk` theo luồng với `Content-Disposition: attachment; filename=agrihub-platform-<ngày-giờ UTC>.agbk`, `Cache-Control: no-store` và `X-Content-SHA256: <checksum>`. Hệ thống ghi `downloaded_at` và audit `platform_downloaded` (kèm checksum).
3. **Kiểm tra bản đã tải**: `sha256sum agrihub-platform-….agbk` phải bằng `checksum_sha256` trong API (hoặc header `X-Content-SHA256`); khác ⇒ tải lại. Hệ thống không băm lại file trước khi gửi (file lớn sẽ làm treo request); việc đó do khôi phục thử hằng tuần và bước kiểm tra này đảm nhiệm.

**Khóa mã hóa — phần hay bị quên.** File tải về **vô dụng nếu thiếu khóa** (`BACKUP_ENCRYPTION_KEY`, hoặc `APP_KEY` nếu để trống), và ai có cả file lẫn khóa thì đọc được dữ liệu mọi khách hàng. Vì vậy: cất khóa **riêng** ở nơi khác với file (trình quản lý mật khẩu của công ty + bản giấy trong nơi cất giữ an toàn), ghi rõ khóa nào dùng cho bản nào khi xoay khóa, và **mỗi ~3 tháng diễn tập**: lấy một file đã tải + khóa đã cất, chạy `agrihub:backup:restore-platform --file=… --target=…` trên một máy khác để chắc chắn dùng được. Nên tải **mỗi tuần** (hệ thống nhắc sau 7 ngày chưa có lần tải nào — mục 9). File rất lớn (hàng GB) nên dùng sao chép tự động từ máy chủ thay vì trình duyệt.

**Giới hạn đã biết — đọc trước khi tin vào hệ thống**
- **Bản sao lưu trên máy chủ chỉ an toàn khi có bản sao NGOÀI máy chủ.** Hiện việc này là **thủ công** (Super Admin tải về; hệ thống nhắc nếu quá 7 ngày chưa có lần tải) nên an toàn dữ liệu phụ thuộc vào việc người thực hiện tải đều đặn. Chưa có sao chép tự động: cần cron `rclone`/`rsync`/S3 từ `storage/app/backups/platform` sang nơi **độc lập** (nhà cung cấp/khu vực khác). Disk S3 chưa được hỗ trợ trực tiếp (cần tải về thư mục tạm).
- Chỉ là dump logic theo thời điểm: **RPO ≤ 24 giờ**. WAL archiving/PITR chưa có.
- Cảnh báo (mục 9) chỉ hoạt động khi **scheduler và queue worker còn sống**: nếu cả hai cùng chết thì không ai gửi cảnh báo "quá hạn". Cần giám sát từ bên ngoài (heartbeat/uptime — làm ở 8c). Email cần cấu hình `MAIL_*` thật ở production; Zalo OA/SMS chưa có.
- Kiểm thử khôi phục mới đối chiếu **số dòng**; chưa đối chiếu nghiệp vụ (tổng công nợ, tồn kho) vì chưa có module — bổ sung khi có.
- Đếm `count(*)` từng bảng khi sao lưu: chậm với bảng hàng chục triệu dòng; cần đo lại khi có dữ liệu thật.
- Các bản `pre_restore` của Tenant (mục 7) chưa có chính sách dọn.
- **Chưa diễn tập khôi phục thảm họa trên môi trường production thật.** Mới được kiểm thử tự động (database nguồn giả có RLS FORCE, phân vùng, UTF-8) và chạy thử trên database phát triển.


## 9. Thông báo về trạng thái sao lưu (đã triển khai)

**Kênh**: *trong ứng dụng* (hộp thông báo của từng người; giao diện sẽ lấy `GET /notifications?unread=1` khi đăng nhập) và *email* cho Super Admin (cộng thêm các địa chỉ trong `BACKUP_ALERT_EMAILS` — nên là hộp thư **ngoài hệ thống**, vì thông báo trong ứng dụng không đọc được khi cả hệ thống ngừng). Zalo OA/SMS sẽ thêm bằng một channel mới dùng chung nội dung `SystemAlert`, không phải sửa nơi phát sinh sự kiện. Thông báo **không chứa dữ liệu khách hàng** (chỉ trạng thái và lý do đã rút gọn) vì email đi ra ngoài hệ thống.

| Khi nào | Mức | Gửi cho |
|---|---|---|
| Sao lưu toàn hệ thống **thất bại** (kèm giờ bản thành công gần nhất, theo giờ Việt Nam) | critical | mọi Super Admin đang hoạt động: trong ứng dụng + email |
| Khôi phục thử **không đạt** | critical | như trên |
| Sao lưu **bị treo/gián đoạn** (thường do queue worker tắt giữa chừng) | critical | như trên |
| **Quá hạn**: bản hoàn tất gần nhất cũ hơn 36 giờ, hoặc hệ thống đã tồn tại > 36 giờ mà chưa có bản nào | critical | như trên; nhắc lại tối đa mỗi 24 giờ nếu chưa hết |
| Lâu chưa khôi phục thử đạt (> 10 ngày) | warning | như trên |
| Chưa tải bản sao lưu ra nơi lưu riêng (> 7 ngày) | warning | như trên |
| Sao lưu thủ công của Tenant xong / thất bại | info / critical | người yêu cầu (chỉ trong ứng dụng) |
| Khôi phục của Tenant xong / thất bại (dữ liệu hiện tại được giữ nguyên nếu thất bại) | info / critical | người yêu cầu (chỉ trong ứng dụng) |

- **Sự kiện** (thất bại, gián đoạn) được báo **ngay lúc xảy ra**. **Trạng thái** (quá hạn, chưa khôi phục thử, chưa tải ra ngoài) do lệnh `agrihub:backup:check` kiểm tra **mỗi giờ** (phút 17); cùng một vấn đề chỉ báo lại sau `BACKUP_ALERT_DEDUPE_HOURS` (24) giờ. `--no-notify` chỉ in tình trạng; lệnh trả mã thoát 1 khi tình trạng nghiêm trọng nên công cụ giám sát bên ngoài (cron, Uptime Kuma…) cũng dùng được.
- `GET /platform/backups/status` trả `state` (`ok|warning|critical|disabled`), bản hoàn tất/lần thử/lần khôi phục thử gần nhất, `last_downloaded_at`, danh sách `issues` và các ngưỡng — để màn hình quản trị hiện đèn trạng thái.
- Cấu hình (`config/backup.php` → `alerts`, biến môi trường trong `.env.example`): `BACKUP_ALERT_EMAILS`, `BACKUP_ALERT_OVERDUE_HOURS` (36), `BACKUP_ALERT_VERIFY_OVERDUE_DAYS` (10), `BACKUP_ALERT_OFFSITE_DAYS` (7; `0` = tắt nhắc), `BACKUP_ALERT_DEDUPE_HOURS` (24), `BACKUP_ALERT_TIMEZONE` (Asia/Ho_Chi_Minh, chỉ để hiển thị giờ trong nội dung). Khi `BACKUP_PLATFORM_ENABLED=false` không đánh giá và không cảnh báo (`state=disabled`).
- **Thông báo lỗi không được làm hỏng việc chính**: mỗi kênh gửi độc lập, lỗi (ví dụ máy chủ mail chết) chỉ ghi log, kênh còn lại vẫn đi và bản sao lưu vẫn hoàn tất.
- Hộp thông báo: bảng `notices` (một dòng cho một người nhận, RLS; thông báo của Super Admin có `tenant_id NULL` nên Tenant không bao giờ thấy), API `GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all`. Mỗi người chỉ thấy và chỉ sửa thông báo của chính mình. `agrihub:notices:prune` (03:45 hằng ngày) xóa thông báo đã đọc > 30 ngày và mọi thông báo > 90 ngày.


## 10. Sao lưu tự động hằng ngày theo Tenant và dấu vân tay khóa mã hóa (đã triển khai)

**Sao lưu tự động theo Tenant** (`agrihub:backup:tenants --scheduled`, 02:00 hằng ngày, chỉ chạy khi `BACKUP_TENANT_AUTO_ENABLED=true`, mặc định bật ở production): với MỌI Tenant, tạo một bản loại `auto` chạy qua queue worker (cần worker + scheduler). Bỏ qua Tenant đang được khôi phục hoặc đang có bản chưa xong; bản chờ/đang chạy quá 12 giờ bị đánh dấu "gián đoạn". Chủ doanh nghiệp thấy bản tự động trong danh sách (theo ngày giờ), tải về và khôi phục như bản thủ công. Thành công thì im lặng; **thất bại thì báo Chủ doanh nghiệp (trong ứng dụng) và nhân viên AgriHub (trong ứng dụng + email)**, mỗi bên tối đa một lần mỗi ngày.
**Giữ bản** (`agrihub:backup:prune`, 03:30, cùng lệnh với phần toàn hệ thống): giữ mọi bản tự động trong `BACKUP_TENANT_KEEP_DAYS` (30) ngày và luôn giữ ít nhất `BACKUP_TENANT_MIN_KEEP` (3) bản mới nhất của mỗi Tenant. Bản **thủ công, tải lên và điểm quay lại không bao giờ bị xóa**; bản đang là nguồn hoặc điểm quay lại của một lần khôi phục được giữ lại. `--dry-run` để xem trước.
Lưu ý dung lượng: mỗi Tenant × số bản × kích thước dữ liệu — cần theo dõi đĩa khi số Tenant và dữ liệu tăng. Dữ liệu nghiệp vụ của Tenant hiện mới có danh mục sản phẩm nên bản tự động còn nhỏ; sẽ lớn dần khi có kho và chứng từ.

**Dấu vân tay khóa** (`backups.key_fingerprint`, 16 ký tự hex): HMAC một chiều của nhãn cố định bằng khóa mã hóa, cắt ngắn — **không suy ngược ra khóa**. Mỗi bản sao lưu ghi dấu vân tay của khóa đã mã hóa nó, hiện trong API/giao diện và header `X-Backup-Key-Fingerprint` khi tải về, để biết **file nào cần khóa nào** khi khóa được đổi theo thời gian (khóa cũ phải được giữ lại cho tới khi hết bản sao lưu dùng nó). `php artisan agrihub:backup:key` in dấu vân tay hiện tại và nguồn khóa; `--generate` tạo khóa ngẫu nhiên 32 byte để đặt vào `BACKUP_ENCRYPTION_KEY`. Ở production mà chưa đặt khóa riêng (đang dùng chung `APP_KEY`) thì có cảnh báo `key_not_dedicated` (đổi `APP_KEY` sẽ làm mọi bản cũ không đọc lại được). Thuật toán: libsodium secretstream XChaCha20-Poly1305, mã hóa + xác thực theo luồng (mục 7).