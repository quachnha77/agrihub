# AgriHub – Audit Log, Error Log & Monitoring

Trạng thái: thiết kế; chưa triển khai.

## 1. Audit Log (nghiệp vụ)
Append-only, ghi: actor, tenant, branch, IP, thiết bị (user agent), thời gian, module, action, đối tượng, before/after (khi phù hợp), kết quả thành công/thất bại.

Bắt buộc ghi: đăng nhập/đăng xuất/thất bại, bán hàng (tạo/chốt/hủy/trả), điều chỉnh kho, công nợ & thanh toán, phân quyền, subscription/billing, mọi hành động Super Admin (kể cả impersonation).

Ghi qua `AuditService` trong cùng transaction với nghiệp vụ; che dữ liệu nhạy cảm; partition theo tháng; retention cấu hình (mặc định ≥ 2 năm với dữ liệu tài chính).

## 2. Application/Error Log
Log JSON có cấu trúc (request_id, tenant_id, user_id, route, latency). Cấp độ chuẩn PSR-3. Tập trung về một nơi (Loki/ELK hoặc Sentry cho exception – *chờ xác nhận*). Không log mật khẩu, token, số thẻ.

## 3. Monitoring
| Nhóm | Chỉ số |
|---|---|
| Hạ tầng | CPU, RAM, Disk, network |
| Dịch vụ | PostgreSQL (kết nối, replication lag, slow query), Redis, Nginx/PHP-FPM |
| Ứng dụng | API latency (p50/p95/p99), tỉ lệ lỗi HTTP 4xx/5xx, queue depth/failed jobs, scheduler heartbeat |
| Vận hành | Backup gần nhất, dung lượng storage |
| Tích hợp | Zalo OA, cổng thanh toán (tỉ lệ lỗi/độ trễ) |

Trạng thái: `normal | warning | critical | down` theo ngưỡng cấu hình. Trang health cho Super Admin; endpoint `/health` (liveness) và `/health/ready` (readiness).

## 4. Cảnh báo
Sự cố `critical/down` → gửi ngay (email + kênh chat), chống spam (gộp/cooldown), có runbook cho từng cảnh báo.

Công cụ đề xuất: Prometheus + Grafana + Alertmanager, Uptime check ngoài (*chờ xác nhận*).
