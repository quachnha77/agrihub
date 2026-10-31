# AgriHub – Kiến trúc hệ thống

> Hiện trạng: repository trống, nên đây là kiến trúc **đề xuất**, chưa có gì được triển khai.

## 1. Tổng thể

```
Browser (React SPA) ──HTTPS──> Nginx ──> PHP-FPM (Laravel /api/v1)
                                   │            ├── PostgreSQL
                                   │            ├── Redis (cache, queue, session, rate limit)
                                   │            ├── Queue worker / Scheduler
                                   │            └── Object storage (private)
                                   └──> static SPA assets
```

- **Modular Monolith**: một codebase Laravel, chia module theo bounded context; giao tiếp giữa module qua Service/Contract công khai, không truy cập trực tiếp bảng của module khác.
- Không Microservices ở giai đoạn đầu.

## 2. Cấu trúc repository đề xuất (monorepo)

```
/backend      Laravel (app/Modules/<Module>/{Domain,Http,Services,Models,Database})
/frontend     React + TS + Tailwind (src/modules/<module>)
/docker       Dockerfile, nginx, compose theo môi trường
/docs         Tài liệu
```

## 3. Module backend

| Nhóm | Module |
|---|---|
| Platform | Platform, Tenancy, Identity (Auth/User/Session), Access (RBAC), Subscription, Billing, Audit, Monitoring, Notification |
| Business | Customer, Supplier, Catalog (Product), Inventory, Purchase, Sales, Receivable, Payable, Cashbook, Season, Delivery, Report |
| Integration | Zalo, (sau) Bank, SMS/Email |

Phụ thuộc theo chiều: Identity/Tenancy → Access → Catalog/Customer/Supplier → Inventory → Purchase/Sales → Receivable/Payable/Cashbook → Report.

## 4. Quyết định kiến trúc (ADR tóm tắt)

| ADR | Quyết định | Lý do |
|---|---|---|
| 1 | Modular Monolith | Đội nhỏ, giao dịch tài chính cần ACID đơn giản |
| 2 | Shared database + `tenant_id` + PostgreSQL Row-Level Security (phòng thủ nhiều lớp) | Chi phí vận hành thấp; RLS chặn rò rỉ nếu lỗi code. Xem MULTI_TENANT_DESIGN |
| 3 | Auth: Laravel Sanctum (SPA cookie + token cho mobile/API) | Đơn giản, quản lý session/đăng xuất từ xa được. *Chờ xác nhận* |
| 4 | API REST có version `/api/v1`, JSON, lỗi theo RFC 7807 | Ổn định cho client |
| 5 | Queue/Scheduler bằng Redis + Laravel Horizon | Gửi Zalo, báo cáo, nhắc nợ, tính lãi, backup |
| 6 | Tiền = `NUMERIC(18,2)`, tính toán bằng chuỗi/BCMath | Tránh sai số float |
| 7 | Tồn kho = sổ giao dịch bất biến (`stock_movements`) + bảng số dư dẫn xuất | Truy vết, kiểm toán |
| 8 | Idempotency-Key cho API tạo giao dịch tiền/kho | Chống trùng |

## 5. Phân lớp trong module
`Controller (mỏng) → FormRequest (validate) → Service/Action (nghiệp vụ, transaction) → Repository (khi thực sự cần) → Model`. Policy kiểm quyền ở backend cho mọi action.

## 6. Frontend
React 19 + TypeScript + Vite + Tailwind 4. **Đã triển khai (bước 9):** khung ứng dụng đăng nhập bằng cookie phiên Sanctum (không lưu token ở localStorage; CSRF qua `/sanctum/csrf-cookie` + header `X-XSRF-TOKEN`), `react-router` (route + guard theo quyền, trang tải theo nhu cầu), TanStack Query (cache/refetch), `react-hook-form` + `zod` (kiểm tra dữ liệu; **mọi thông báo mặc định của zod đã được Việt hóa**, lỗi 422 của API cũng tiếng Việt và hiển thị đúng ô), `react-i18next` (chữ giao diện nằm ở `src/i18n/vi.ts`, thêm ngôn ngữ = thêm một file), chuông thông báo + trang Thông báo, banner gói dịch vụ/phiên hỗ trợ, lý do rõ ràng khi bị đưa ra khỏi phiên (hết phiên, gói hết hạn, doanh nghiệp bị khóa), trang Sao lưu hệ thống (Super Admin: tình trạng, danh sách, sao lưu ngay, tải về có nhập lại mật khẩu) (sao lưu dữ liệu từng doanh nghiệp chỉ có ở Super Admin). **Danh mục sản phẩm (đã có):** menu *Sản phẩm* → `/products` (danh sách, lọc, sao chép sang chi nhánh khác), `/products/new` và `/products/:id` (form dựng theo định nghĩa loại hàng từ `GET /products/types`, bảng đơn vị-giá), `/products/lists` (đơn vị tính, nhóm hàng, thương hiệu). Nút và trang hiện theo quyền `catalog.product.*`; quyền thật do API kiểm tra. **Khách hàng & Nhà cung cấp (đã có):** menu *Khách hàng* → `/customers` (danh sách, lọc loại/nhóm/chi nhánh phụ trách/trạng thái), `/customers/new`, `/customers/:id` (địa chỉ và người liên hệ theo dòng bằng `useFieldArray`, hạn mức công nợ + số ngày nợ), `/customers/groups` (nhóm khách, dùng lại `ListCard` của danh mục sản phẩm với tiền tố quyền `crm.customer`); menu *Nhà cung cấp* → `/suppliers`, `/suppliers/new`, `/suppliers/:id`. **Kho (đã có):** menu *Tồn kho* → `/stock` (thẻ cảnh báo bấm để lọc: sắp hết hàng, lô sắp/đã hết hạn; bảng tồn theo sản phẩm; hộp chi tiết tồn theo lô + thẻ kho), `/stock/new?type=receipt|issue|adjustment|opening` (lập phiếu: tìm sản phẩm theo chi nhánh của kho, đơn vị quy đổi hiện "= 500 Kg", chọn lô có sẵn khi xuất), *Phiếu kho* → `/stock/documents`, `/stock/documents/:id`, *Kho* → `/warehouses`. Giá vốn/giá trị chỉ hiện khi API trả về (theo quyền). Code ở `features/inventory/` (`api.ts`, `forms.ts` — schema zod theo loại phiếu, payload, đưa lỗi `lines.N.*` vào đúng dòng —, các trang). Code CRM ở `features/crm/` (`api.ts`, `forms.ts` — schema zod, chuyển đổi form ↔ API, đưa lỗi 422 lồng `addresses.N.*` vào đúng ô —, `PartyEditors.tsx`, các trang).

Cấu trúc `frontend/src`: `app/` (route, gốc), `features/<module>/` (api hooks + trang + component của module), `layout/`, `pages/`, `components/ui.tsx` (Button, TextField, Alert, Modal… dùng chung), `lib/` (api client, định dạng, form), `i18n/`. Module nghiệp vụ mới thêm thư mục trong `features/`, một route lazy trong `app/routes.tsx` dưới `<RequireAccess permission="…">` và mục menu trong `layout/AppLayout.tsx`. **Ẩn menu/trang theo quyền chỉ để dẫn đường; việc chặn thật nằm ở API (Policy + RLS).** Mọi màn hình có trạng thái đang tải, rỗng và lỗi; hỗ trợ bàn phím (liên kết bỏ qua menu, Esc đóng hộp thoại) và nhãn cho trình đọc màn hình.

**Quy ước tải dữ liệu (áp dụng cho mọi màn hình mới):** (1) *Skeleton thay Spinner*: lần tải đầu hiện khung xám đúng hình của vùng đó (`components/skeleton.tsx`: `TableSkeleton`, `ListSkeleton`, `FieldsSkeleton`, `FormPageSkeleton`, `DetailPageSkeleton`, `StatCardsSkeleton`, `CardGridSkeleton`, `LinesSkeleton`, `FilterBar`, `AppShellSkeleton`); kích thước bám nội dung thật (dòng chữ 20px, ô nhập 38px, nút 36px, không dùng lề dọc để khỏi bị dồn lề), chỉ có *một* vùng `role="status"` "Đang tải…" cho trình đọc màn hình, bảng giữ chỗ ẩn khỏi cây trợ năng (không giả làm bảng dữ liệu), chuyển động tắt khi người dùng chọn giảm chuyển động. Spinner chỉ còn trong nút đang gửi và thông báo một thao tác đang chạy. Chờ phiên đăng nhập/tải mã trang lazy thì hiện `AppShellSkeleton` (thanh trên + menu giữ chỗ; `HydrateFallback` ở route gốc); chuyển trang lazy giữ trang cũ và hiện thanh mảnh dưới thanh trên. (2) *Đổi trang/bộ lọc không xẹp rồi bung*: `placeholderData: keepPreviousData` + `<Refreshable>` làm mờ dữ liệu cũ thay vì quay lại skeleton. (3) *Không để phần tử xuất hiện muộn làm trang dịch*: ô lọc theo chi nhánh/kho và cột "Chi nhánh" chỉ có khi có ≥ 2 chi nhánh/kho, nên `FilterBar` và bảng chờ danh sách đó rồi hiện cùng lúc; thẻ số liệu giữ chỗ bằng `StatCardsSkeleton`. (4) *Phân trang theo máy chủ*: mọi danh sách có thể lớn dùng `?page=&per_page=` (20 mục/trang; 10 trong hộp chi tiết và thẻ danh mục) và `<Pagination>` (cao cố định); `useClampPage` lùi về trang cuối khi trang hiện tại vượt quá số trang (vừa xóa mục cuối). Danh sách nhỏ có giới hạn tự nhiên (vai trò, gói, bản sao lưu theo chính sách lưu giữ) và các ô chọn (≤ 100/200 mục) lấy một lượt. Đo trên trình duyệt thật với API chậm 500ms: tổng CLS các trang danh sách/biểu mẫu 0,38 → 0,00 (đo ở cả khung máy tính 1280px và điện thoại 390px/360px, trên bản build production; khối có chữ cố định như nhãn thẻ số liệu thì skeleton hiện chữ thật để ngắt dòng giống hệt trên điện thoại).

**Hệ thống thiết kế (đơn giản, dễ dùng, dùng được trên điện thoại):** *Màu và kiểu* khai báo một chỗ ở `index.css` (`@theme`): thang `brand-*` (xanh lá thương hiệu; màn hình không viết `emerald-…` trực tiếp), nền `canvas`, bóng `shadow-card`/`shadow-pop`, hiệu ứng ngắn (tắt khi người dùng chọn giảm chuyển động); chữ hệ thống (không tải font ngoài để khỏi chậm và khỏi giật chữ), số dùng `tabular-nums` cho thẳng cột, tiêu đề cột chữ thường (chữ IN HOA có dấu tiếng Việt khó đọc). *Thành phần nguyên tử* ở `components/ui.tsx` (Button cao ≥ 40px, IconButton/IconLink có chú giải và vùng chạm ≥ 36/40px, TextField/SelectField chữ 16px trên điện thoại để iOS không tự phóng to, Modal thành tấm trượt từ cạnh dưới trên điện thoại, Alert/Badge có biểu tượng/chấm) + `styles.ts`. *Khối cấp trang* ở `components/page.tsx`: `PageHeader` (tiêu đề + một câu mô tả + hành động chính luôn ở bên phải), `SectionTabs` (chuyển giữa các màn hình cùng phân hệ), `FilterBar`/`FilterSelect`/`FilterDate`/`SearchField` (một hàng; tên bộ lọc nằm trong ô; bộ lọc đang bật có viền xanh và nút "Xóa bộ lọc"; trên điện thoại thu vào nút "Bộ lọc"), `EmptyState` (biểu tượng + câu rõ ràng + nút làm ngay), `FormActions` (thanh Lưu/Hủy dính đáy màn hình). `components/table.tsx`: `DataTable` là bảng thường trên máy tính và **thẻ hai cột trên điện thoại** (tự gắn nhãn cột cho từng ô; đánh dấu cột bằng `data-role`: `title`, `actions`, `select`, `wide`, `optional` = ẩn trong thẻ). `components/menu.tsx`: `Menu` thả xuống có bàn phím đầy đủ (↑ ↓ Home End Esc Tab, trả focus). *Điều hướng:* mục chính ≤ 8 (Tổng quan, Sản phẩm, Kho hàng, Khách hàng, Nhà cung cấp; nhóm "Quản lý": Người dùng, Chi nhánh, Vai trò), các màn hình liên quan gom thành tab trong phân hệ (Kho hàng = Tồn kho | Phiếu kho | Danh sách kho; Sản phẩm; Khách hàng); tên nhóm chỉ hiện khi nhóm có mục; đăng xuất và đổi mật khẩu nằm trong menu tài khoản (avatar). Trang Tổng quan là nơi bắt đầu việc: cảnh báo kho bấm được (sang danh sách đã lọc sẵn), lối tắt theo quyền, phiếu kho gần đây, và hướng dẫn "Bắt đầu với AgriHub" cho cửa hàng chưa có sản phẩm. Quy tắc cho màn hình mới: dựng theo `PageHeader` → (tab) → `Card` chứa `FilterBar` + `DataTable`; mỗi màn hình một hành động chính; không thêm nút/ô khi có thể gom vào menu hoặc tab; thử ở khung 390px (không được tràn ngang).

**Giao diện Super Admin (đã có):** *Doanh nghiệp* (`/platform/tenants`: tìm, lọc trạng thái, thêm doanh nghiệp/onboarding với địa chỉ đăng nhập subdomain và mật khẩu tạm hiển thị một lần, cột thuê bao: trạng thái + còn/quá hạn bao nhiêu ngày), *chi tiết doanh nghiệp* (`/platform/tenants/:id`: thông tin, sửa, khóa/mở khóa có lý do, mở phiên hỗ trợ chỉ đọc có lý do và thời lượng rồi chuyển sang xem dữ liệu của doanh nghiệp; thuê bao với gia hạn có ghi nhận thanh toán, mở lại tạm thời, đổi gói/số người dùng/đơn giá riêng, ghi nhận liên hệ và lịch sử kỳ; người dùng của doanh nghiệp để cấp mật khẩu tạm và khóa/mở khóa), *Việc cần liên hệ* (`/platform/renewals`: sắp/đã hết hạn, lọc theo số ngày và tình trạng liên hệ, ghi nhận đã gọi) và *Gói & giá* (`/platform/plans`: thêm/sửa gói, giá tháng/năm, giới hạn, ngừng bán; đổi giá không đổi giá khách đang dùng). Chỉ Super Admin vào được (giao diện chỉ dẫn đường; quyền thật do API kiểm tra). Từ trang chi tiết doanh nghiệp, *Sao lưu & khôi phục* (`/platform/tenants/:id/backups`) cho nhập file và khôi phục dữ liệu của đúng doanh nghiệp đó (nơi DUY NHẤT sao lưu, tải về, nhập file và khôi phục dữ liệu của một doanh nghiệp; Chủ doanh nghiệp không có chức năng này).

**Quản lý doanh nghiệp (đã có giao diện):** *Người dùng* (tìm, thêm với email/số điện thoại, sửa, gán vai trò và chi nhánh, khóa/mở khóa, cấp mật khẩu tạm hiển thị một lần kèm địa chỉ đăng nhập, xóa; mọi thao tác nguy hiểm có hộp xác nhận và lý do lỗi của máy chủ hiện ngay trong hộp), *Chi nhánh* (thêm/sửa/đổi trạng thái/xóa) và *Vai trò & phân quyền* (chỉ xem; tạo/sửa vai trò tùy chỉnh làm sau). Nút và menu ẩn theo quyền; máy chủ vẫn kiểm tra lại từng thao tác (chống leo thang quyền nằm ở API). **Mỗi doanh nghiệp một subdomain:** trang đăng nhập hiển thị tên doanh nghiệp theo địa chỉ (`GET /tenant-context`), đăng nhập nhầm địa chỉ được chỉ đúng đường dẫn, subdomain không tồn tại hiện trang "Không tìm thấy doanh nghiệp".

## 7. Môi trường
Dev / Staging / Production tách biệt (DB, secrets, storage, domain). Xem DEPLOYMENT.md.
