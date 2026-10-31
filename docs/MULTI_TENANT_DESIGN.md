# Thiết kế Multi-tenant & Branch

## 1. Mô hình
Shared database, shared schema. Mọi bảng dữ liệu nghiệp vụ có `tenant_id` (NOT NULL, FK). Bảng theo chi nhánh có thêm `branch_id`.

Phân loại bảng:
- **Global (platform)**: `plans`, `tenants`, `platform_settings`, … – chỉ Super Admin.
- **Tenant-scoped**: có `tenant_id`.
- **Branch-scoped**: có `tenant_id` + `branch_id` (kho, đơn bán, phiếu thu/chi, …).

## 2. Xác định Tenant
- Tenant lấy từ **người dùng đã xác thực** (`users.tenant_id`) hoặc membership; không đọc tenant từ body/query/header do client gửi.
- Super Admin muốn xem dữ liệu một Tenant: dùng **phiên hỗ trợ** tường minh (xem mục 2c), không bao giờ "đăng nhập thay" người dùng.
- **Đã xác nhận (2026-10-30):** mỗi user thuộc đúng 1 Tenant; các Tenant hoàn toàn độc lập. `users.tenant_id` NOT NULL (trừ Super Admin).

## 2b. Hiện thực (đã triển khai)
- `App\Support\Tenancy\TenantContext` (scoped theo request/job) giữ `tenantId` / chế độ nền tảng và đồng bộ sang PostgreSQL bằng `set_config('app.tenant_id' | 'app.is_platform', …)`. Không có context => RLS trả 0 dòng (fail closed).
- Middleware `tenant.context` đặt context từ **user đã xác thực**, chạy sau xác thực và trước route-model-binding; luôn `clear()` ở cuối request. Từ chối user bị vô hiệu hóa / Tenant bị khóa kể cả khi phiên còn hiệu lực.
- `tenant.user` chặn Platform Admin khỏi endpoint dữ liệu Tenant, trừ khi đang trong phiên hỗ trợ (mục 2c).
- Platform Admin **không có context mặc định**: route `/platform/*` chạy trong `platform.admin` (chuyển sang chế độ nền tảng rồi khôi phục); route dữ liệu Tenant chỉ dùng được trong phiên hỗ trợ.
- Trait `BelongsToTenant`: global scope + tự gán `tenant_id` từ context khi tạo (bỏ qua giá trị truyền vào); `tenant_id` không nằm trong `$fillable`.
- RLS bật + FORCE trên `tenants`, `branches`, `users`, `user_branches` (policy `tenant_isolation`, có `WITH CHECK`). Nhờ FORCE, chính owner của bảng cũng bị áp dụng RLS; superuser/BYPASSRLS thì không — ứng dụng KHÔNG được kết nối bằng các role này.
- `users` không có global scope vì xác thực phải tìm user trước khi biết Tenant: `TenantAwareUserProvider` tìm/cập nhật user ở chế độ nền tảng, sau đó context chuyển về Tenant của user.
- Ràng buộc DB: user thuộc đúng 1 Tenant, trừ Platform Admin (`tenant_id IS NULL` và `is_platform_admin`), kiểm bằng CHECK.
- Quy ước cho bảng Tenant-scoped mới: `tenant_id` NOT NULL + FK, unique theo `(tenant_id, …)`, thêm bảng vào RLS (migration mới), dùng `BelongsToTenant`; bảng pivot phải ghi `tenant_id`.

## 2c. Phiên hỗ trợ (impersonation an toàn) — đã triển khai
Platform Admin xem dữ liệu của MỘT Tenant để hỗ trợ khách, với các ràng buộc cứng:
- **Chỉ đọc**: quyền hiệu lực chỉ gồm các quyền `*.view` ở phạm vi Tenant; mọi method ghi bị chặn thêm ở middleware (`403 support_session_read_only`).
- **Bắt buộc có lý do** (≥ 10 ký tự), **có hạn** (mặc định 30 phút, tối đa 120), **một phiên tại một thời điểm**.
- Trạng thái nằm trong **session phía server**, gắn với đúng admin và phiên đăng nhập đó (đăng xuất là hết; session của admin khác bị bác). Client không thể chọn Tenant bằng tham số/header.
- **Không đăng nhập thay người dùng**: người thực hiện luôn là Platform Admin trong mọi audit log.
- **Minh bạch với khách hàng**: `support_session_started/ended/expired` và từng `support_request` (method + đường dẫn, không ghi query string) được ghi với `tenant_id` của Tenant được hỗ trợ, nên **chủ doanh nghiệp thấy trong nhật ký của mình** ai của AgriHub đã vào xem, lúc nào và vì sao. Mọi audit trong phiên mang `metadata.support_session`.
- Có thể hỗ trợ cả Tenant đang bị khóa.
- Ghi dữ liệu thay mặt khách hàng (nếu sau này cần) phải là một cơ chế khác: có phê duyệt riêng, không nằm trong phiên này.

## 2d. Mỗi doanh nghiệp một subdomain — đã triển khai (xác nhận 2026-10-31)
Mỗi Tenant chạy trên `<slug>.<TENANT_BASE_DOMAIN>` (vd. `nong-phat.agrihub.vn`); Super Admin ở `admin.<gốc>` hoặc chính tên miền gốc. Slug đã duy nhất, bất biến và có danh sách tên dành riêng (`config/platform.php`: `admin`, `www`, `api`, `mail`, `smtp`…), nên chính là subdomain. Bật bằng `TENANT_BASE_DOMAIN` (để trống = tắt, một địa chỉ cho tất cả).
- **Host KHÔNG phải nguồn quyền truy cập.** Tenant của một request vẫn chỉ lấy từ người dùng đã xác thực (mục 2). Host chỉ dùng để (1) trang đăng nhập biết đang ở doanh nghiệp nào (`GET /tenant-context`), (2) từ chối đăng nhập và phiên SAI địa chỉ. Gửi Host giả chỉ cho thấy dữ liệu của chính tài khoản của mình.
- **Đăng nhập**: người dùng chỉ đăng nhập ở đúng subdomain của doanh nghiệp mình; Super Admin ở `admin.`/tên miền gốc. Sai địa chỉ **với mật khẩu đúng** ⇒ `403 wrong_host` kèm `login_url` (đường dẫn đúng của chính họ); mật khẩu sai hay tài khoản không tồn tại ⇒ vẫn là `401 invalid_credentials` chung (không lộ tài khoản thuộc doanh nghiệp nào). Ghi audit `login_failed` lý do `wrong_host`.
- **Số điện thoại duy nhất theo doanh nghiệp** (yêu cầu 2026-10-31): vì mỗi doanh nghiệp có subdomain riêng, `users.phone` chỉ cần duy nhất trong doanh nghiệp (và trong nhóm quản trị nền tảng); hai doanh nghiệp được dùng chung một số. Đăng nhập bằng số tra theo địa chỉ truy cập (`AccountLocator` + `LoginScope`); email cũng vậy (xem mục ngay dưới). Chưa bật subdomain: số dùng chung ở nhiều doanh nghiệp là mơ hồ nên không đăng nhập được bằng số đó.
- **Danh mục sản phẩm theo chi nhánh** (yêu cầu 2026-10-31: "mỗi chi nhánh có danh mục riêng"): `products` và `product_units` có `branch_id`; mã sản phẩm và mã vạch duy nhất trong chi nhánh (hai chi nhánh dùng cùng mã cho cùng mặt hàng); đơn vị tính, nhóm hàng, thương hiệu dùng chung cả doanh nghiệp. Có thao tác **sao chép sản phẩm sang chi nhánh khác** để không phải nhập lại. Hệ quả cho các bước sau: tồn kho, chuyển kho giữa chi nhánh và báo cáo tổng hợp phải ghép sản phẩm theo mã (`sku`) vì cùng một mặt hàng là nhiều bản ghi.
- **Khách hàng: dùng chung, có chi nhánh phụ trách; nhà cung cấp: dùng chung** (yêu cầu 2026-10-31): `customers.branch_id` là chi nhánh phụ trách (quyết định xem/sửa khi quyền ở phạm vi `branch`); khách và nhà cung cấp KHÔNG bị nhân bản theo chi nhánh như sản phẩm, nên một khách mua ở nhiều chi nhánh vẫn chỉ có một hồ sơ và (về sau) một sổ công nợ chung. Nhóm khách chỉ để phân loại. Mã khách/nhà cung cấp duy nhất trong doanh nghiệp; số điện thoại khách không bắt buộc duy nhất.
- **Kho: nhiều kho mỗi chi nhánh** (yêu cầu 2026-10-31): `warehouses.branch_id`; sản phẩm đã thuộc một chi nhánh nên tồn, lô và giá vốn bình quân của sản phẩm cũng nằm gọn trong chi nhánh đó (giá vốn tính trên tổng tồn của sản phẩm ở mọi kho CỦA chi nhánh). Phiếu kho chỉ nhận sản phẩm đúng chi nhánh của kho. Chuyển kho (nội bộ chi nhánh và giữa chi nhánh) và kiểm kê là bước sau; chuyển giữa chi nhánh phải ghép sản phẩm theo mã (`sku`) vì mỗi chi nhánh có danh mục riêng.
- **Email duy nhất theo doanh nghiệp** (yêu cầu 2026-10-31: "từng doanh nghiệp sẽ có tài khoản riêng"): `users.email` chỉ cần duy nhất trong doanh nghiệp (và trong nhóm quản trị nền tảng); hai doanh nghiệp được dùng chung một email, mỗi nơi đăng nhập ở subdomain của mình. Tra theo địa chỉ như số điện thoại (`AccountLocator`), thêm một ngoại lệ: email chỉ MỘT tài khoản trong cả hệ thống dùng thì vẫn nhận ở sai địa chỉ để chỉ đường (`wrong_host`). Chưa bật subdomain thì email vẫn bị bắt duy nhất toàn hệ thống khi tạo (không có địa chỉ để phân biệt; nếu sau này tắt subdomain khi đã có email dùng chung, các tài khoản đó chỉ đăng nhập được bằng số điện thoại duy nhất).
- **Quên mật khẩu / quên tài khoản** theo địa chỉ: tại subdomain, email và số điện thoại được tra trong doanh nghiệp đó (email chỉ một doanh nghiệp dùng thì nhập ở địa chỉ nào cũng được, liên kết luôn trỏ về đúng subdomain của tài khoản nên người quên địa chỉ nhận lại được địa chỉ đăng nhập). Đặt lại mật khẩu cũng theo địa chỉ truy cập và mã gắn với từng tài khoản. Xem docs/SECURITY.md.
- **Phiên**: cookie phiên chỉ theo từng host (`SESSION_DOMAIN` phải để trống); middleware `SetTenantContext` còn từ chối (`403 wrong_host`) phiên dùng ở địa chỉ không phải của người dùng — lớp bảo vệ thứ hai phòng cấu hình làm cookie bị dùng chung. Trang Sức khỏe hệ thống báo **nghiêm trọng** nếu `SESSION_DOMAIN` đang đặt trong khi bật subdomain.
- **Địa chỉ lạ**: subdomain không có doanh nghiệp (hoặc Host ngoài tên miền gốc) ⇒ `404 tenant_not_found` cho mọi API (trừ `/health*` để cân bằng tải gọi bằng IP). `TrustHosts` chỉ chấp nhận Host thuộc tên miền gốc (+ `TENANT_EXTRA_TRUSTED_HOSTS`). Doanh nghiệp tìm theo slug có bộ nhớ đệm 30 giây (chỉ nhớ kết quả "có", nên doanh nghiệp mới dùng được ngay).
- **Sanctum**: mọi subdomain của tên miền gốc được thêm tự động vào danh sách "ứng dụng web" (ký tự đại diện `*`); không cần CORS vì giao diện và API cùng một origin.
- **Hiển thị**: `url` trong API doanh nghiệp (`/platform/tenants`) là địa chỉ đăng nhập riêng; giao diện nêu tên doanh nghiệp ở trang đăng nhập và địa chỉ đăng nhập khi cấp mật khẩu tạm.
- **Chưa làm**: tên miền riêng của khách (custom domain), đổi slug (đổi subdomain), chứng chỉ TLS cho từng doanh nghiệp (dùng chứng chỉ wildcard — xem DEPLOYMENT.md).

## 3. Phòng thủ nhiều lớp (thiết kế)
1. **Application**: `TenantScope` (global scope) + trait `BelongsToTenant` tự gán `tenant_id` khi tạo; mọi query lọc theo tenant.
2. **Policy**: kiểm tra `resource.tenant_id == auth.tenant_id` và phạm vi Branch.
3. **Database**: PostgreSQL RLS – policy `tenant_id = current_setting('app.tenant_id')::bigint`; middleware đặt `SET LOCAL app.tenant_id` mỗi request/job. App dùng DB role không phải owner/superuser để RLS có hiệu lực.
4. **Queue/Job/Command**: job mang `tenant_id` và thiết lập context trước khi chạy.
5. **Storage**: đường dẫn `tenants/{tenant_id}/…`, truy cập qua signed URL/controller kiểm quyền.
6. **Cache key** có tiền tố tenant.

## 4. Unique & index
Ràng buộc unique là **theo tenant**: `UNIQUE (tenant_id, code)`. Index ưu tiên bắt đầu bằng `tenant_id`.

## 5. Phạm vi Branch
User có danh sách branch được phép (`user_branches`) và/hoặc "tất cả chi nhánh". Quyền kèm phạm vi dữ liệu: `own | branch | tenant`.

## 6. Kiểm thử bắt buộc
- Tenant A không đọc/sửa/xóa được bản ghi Tenant B qua mọi endpoint (test tự động quét route).
- Cố tình gửi `tenant_id` của Tenant khác trong payload → bị bỏ qua/từ chối.
- Job chạy nền không rò context giữa các tenant.
- RLS: truy vấn thô không đặt context trả 0 dòng.

## 7. Khôi phục theo Tenant
Do dùng shared schema, restore theo Tenant = export có lọc `tenant_id` từ bản backup khôi phục vào DB tạm rồi import có kiểm soát. Xem BACKUP_DISASTER_RECOVERY.md.
