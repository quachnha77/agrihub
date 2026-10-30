#!/bin/sh
# Chạy một lần khi khởi tạo volume PostgreSQL.
# Tài khoản superuser của container chỉ để quản trị; ứng dụng kết nối bằng role thường (NOSUPERUSER, NOBYPASSRLS)
# để Row-Level Security có hiệu lực (superuser và BYPASSRLS bỏ qua RLS kể cả khi FORCE).
set -e

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres <<SQL
CREATE ROLE "$APP_DB_USER" LOGIN PASSWORD '$APP_DB_PASSWORD' NOSUPERUSER NOBYPASSRLS;
CREATE DATABASE "$APP_DB_NAME" OWNER "$APP_DB_USER";
CREATE DATABASE "${APP_DB_NAME}_test" OWNER "$APP_DB_USER";

-- Tài khoản sao lưu toàn hệ thống (pg_dump): đọc được dữ liệu mọi Tenant (BYPASSRLS) và tạo database tạm khi kiểm thử khôi phục.
-- Chỉ queue worker / scheduler nhận mật khẩu này; tiến trình web KHÔNG có (xem docs/BACKUP_DISASTER_RECOVERY.md mục 8).
CREATE ROLE "$BACKUP_DB_USER" LOGIN PASSWORD '$BACKUP_DB_PASSWORD' NOSUPERUSER CREATEDB BYPASSRLS;
GRANT pg_read_all_data TO "$BACKUP_DB_USER";
GRANT "$APP_DB_USER" TO "$BACKUP_DB_USER";
SQL
