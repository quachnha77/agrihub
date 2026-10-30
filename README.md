# AgriHub – Agricultural Business Management Platform

AgriHub là nền tảng SaaS đa doanh nghiệp (multi-tenant) quản lý kinh doanh vật tư nông nghiệp: khách hàng, sản phẩm, kho, mua hàng, bán hàng, công nợ, mùa vụ, giao hàng, thu chi, cổng khách hàng, Zalo OA, subscription/billing.

> Trạng thái: **Giai đoạn 0 – Khảo sát & chuẩn hóa tài liệu.** Chưa có source code. Xem [IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) để biết tiến độ.

## Mô hình tổ chức

Platform (Super Admin) → Tenant (doanh nghiệp) → Branch (chi nhánh) → User (nhân viên) ; Customer (khách hàng của Tenant).

## Stack mục tiêu

| Lớp | Công nghệ |
|---|---|
| Frontend | React + TypeScript + Tailwind CSS |
| Backend | Laravel REST API (`/api/v1`) |
| Database | PostgreSQL |
| Kiến trúc | Modular Monolith |
| Triển khai | Docker + Nginx (dev / staging / production tách biệt) |
| File | Local private storage hoặc S3-compatible |

## Tài liệu

| Tài liệu | Nội dung |
|---|---|
| [PROJECT_SPECIFICATION](docs/PROJECT_SPECIFICATION.md) | Đặc tả sản phẩm & phạm vi từng phân hệ |
| [SYSTEM_ARCHITECTURE](docs/SYSTEM_ARCHITECTURE.md) | Kiến trúc tổng thể, module, quyết định (ADR) |
| [MULTI_TENANT_DESIGN](docs/MULTI_TENANT_DESIGN.md) | Cô lập Tenant/Branch |
| [DATABASE_DESIGN](docs/DATABASE_DESIGN.md) | Quy ước & thiết kế database |
| [ERD](docs/ERD.md) | Sơ đồ quan hệ thực thể |
| [RBAC_PERMISSION_MATRIX](docs/RBAC_PERMISSION_MATRIX.md) | Vai trò & quyền |
| [SUBSCRIPTION_BILLING](docs/SUBSCRIPTION_BILLING.md) | Gói dịch vụ, thanh toán |
| [API_DOCUMENTATION](docs/API_DOCUMENTATION.md) | Quy ước API |
| [SECURITY](docs/SECURITY.md) | Bảo mật |
| [BACKUP_DISASTER_RECOVERY](docs/BACKUP_DISASTER_RECOVERY.md) | Backup & khôi phục |
| [MONITORING_LOGGING](docs/MONITORING_LOGGING.md) | Audit log, log, monitoring |
| [DEPLOYMENT](docs/DEPLOYMENT.md) | Môi trường & triển khai |
| [TESTING](docs/TESTING.md) | Chiến lược kiểm thử |
| [IMPLEMENTATION_PLAN](docs/IMPLEMENTATION_PLAN.md) | Tiến độ & lộ trình |
| [CHANGELOG](CHANGELOG.md) | Lịch sử thay đổi |

## Quy tắc Git

Không commit `.env`, secrets, dữ liệu thật. Tách commit theo module. Không force push / reset khi chưa được đồng ý.
