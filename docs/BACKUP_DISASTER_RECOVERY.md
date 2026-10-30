# AgriHub – Backup & Disaster Recovery

Trạng thái: **Sao lưu/khôi phục theo Tenant (bước 8a) đã triển khai và có test** — xem mục 7. **Chưa làm:** sao lưu toàn hệ thống bằng `pg_dump`, lịch tự động + retention, kiểm thử restore vào môi trường tạm, WAL/PITR, offsite (bước 8b). **Chưa có backup production nào được thiết lập; không được tuyên bố đạt chuẩn DR cho đến khi có bản ghi kiểm thử restore thành công (mục 5).**

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
- Chưa tự động hóa lịch (`auto`), retention (daily 30 ngày / weekly 12 tuần / monthly 12 tháng) và kiểm thử restore định kỳ: bước 8b.
