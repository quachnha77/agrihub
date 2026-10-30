# AgriHub – Backup & Disaster Recovery

Trạng thái: thiết kế; **chưa có backup nào được thiết lập, chưa kiểm thử restore.**

## 1. Phạm vi backup
Database PostgreSQL; ảnh/file/chứng từ/chữ ký (object storage); cấu hình cần thiết (không gồm secrets dạng rõ – secrets lưu trong secret manager có quy trình riêng).

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
