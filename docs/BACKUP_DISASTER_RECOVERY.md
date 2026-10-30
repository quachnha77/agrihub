# AgriHub – Backup & Disaster Recovery

Trạng thái: thiết kế; **chưa có backup nào được thiết lập, chưa kiểm thử restore.**

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
