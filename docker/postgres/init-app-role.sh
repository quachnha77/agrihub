#!/bin/sh
# Chạy một lần khi khởi tạo volume PostgreSQL.
# Tài khoản superuser của container chỉ để quản trị; ứng dụng kết nối bằng role thường (NOSUPERUSER, NOBYPASSRLS)
# để Row-Level Security có hiệu lực (superuser và BYPASSRLS bỏ qua RLS kể cả khi FORCE).
set -e

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres <<SQL
CREATE ROLE "$APP_DB_USER" LOGIN PASSWORD '$APP_DB_PASSWORD' NOSUPERUSER NOBYPASSRLS;
CREATE DATABASE "$APP_DB_NAME" OWNER "$APP_DB_USER";
CREATE DATABASE "${APP_DB_NAME}_test" OWNER "$APP_DB_USER";
SQL
