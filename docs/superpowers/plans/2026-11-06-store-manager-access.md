# Hak Akses Store Manager Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store Manager (SM) hanya menulis di EOD, Review Stok, Penyesuaian Stok, Transfer Stok, dan Pengiriman; membaca halaman lain; data per gerai hanya gerainya sendiri.

**Architecture:** Satu kelas `BackofficeAccess` memuat matriks berdasarkan nama rute dan metode HTTP; satu middleware `backoffice.access` di grup `/admin` menolak (403) dan membagikan `$readOnly` ke view. Tulis ditolak secara bawaan untuk SM. Filter per gerai memakai `ScopesToStore` yang sudah ada.

**Tech Stack:** Laravel (PHP 8.5), Blade, PHPUnit, Pint.

**Spec:** `docs/superpowers/specs/2026-11-06-store-manager-access-design.md`

## Global Constraints

- Owner tidak berubah perilakunya; seluruh test lama harus tetap hijau kecuali yang secara eksplisit berubah di Task 2.
- Buat file lewat `php artisan make:*` dengan `--no-interaction`; jalankan `vendor/bin/pint --dirty --format agent` sebelum commit.
- Test mengikuti gaya `tests/Feature/*Test.php` (PHPUnit, `RefreshDatabase`, nama metode `test_...` berbahasa Indonesia).
- Teks antarmuka berbahasa Indonesia; tidak menambah dependensi.
- Penegakan di server (403), bukan hanya menyembunyikan menu/tombol.

## Review Focus

- SM tanpa `store_id` → 403 (bukan melihat semua gerai). Dites di Task 1.
- Parameter `?store=<gerai lain>` dari SM diabaikan di Saldo Stok, Laporan, dan Pembatalan. Dites di Task 4.
- Permintaan JSON/AJAX SM yang ditolak mendapat 403 JSON, bukan redirect. Dites di Task 1.
- Rute `GET` yang sebenarnya formulir (`*.create`, `*.edit`) tertutup untuk SM, kecuali `admin.promotion-hierarchy.edit` yang merupakan halaman utamanya. Dites di Task 1.
- Halaman dengan gerai tanpa data tampil kosong tanpa error untuk SM. Dites di Task 4.

---

### Task 1: `BackofficeAccess` dan middleware

**Files:**
- Create: `app/Support/BackofficeAccess.php`, `app/Http/Middleware/EnsureBackofficeAccess.php`, `tests/Feature/BackofficeAccessTest.php`
- Modify: `bootstrap/app.php` (alias `backoffice.access`), `routes/web.php` (grup `/admin`: `['auth', 'admin', 'backoffice.access']`)

**Interfaces:**
- Produces:
  - `BackofficeAccess::CLOSED = ['admin.company.', 'admin.numberings.', 'admin.sap-import.', 'admin.sap-postings.']` (awalan nama rute)
  - `BackofficeAccess::WRITABLE = ['admin.eod.', 'admin.stock-review.', 'admin.stock-adjustments.', 'admin.stock-transfers.', 'admin.deliveries.']`
  - `BackofficeAccess::READABLE_FORMS = ['admin.promotion-hierarchy.edit']`
  - `BackofficeAccess::allows(User $user, ?string $routeName, string $method): bool`
  - `BackofficeAccess::isReadOnly(User $user, ?string $routeName): bool` (true untuk SM di rute selain `WRITABLE`)
  - `BackofficeAccess::visible(User $user, string $routeName): bool` (untuk menu; `allows(..., 'GET')`)
  - Alias middleware `backoffice.access`; view memperoleh `$readOnly` (bool).

- [ ] **Step 1: Tulis test merah di `BackofficeAccessTest`**
  - `test_owner_diizinkan_untuk_semua_rute`
  - `test_store_manager_ditutup_dari_empat_kelompok` (provider: `admin.company.edit`, `admin.numberings.index`, `admin.sap-import.index`, `admin.sap-postings.index`, metode GET dan POST → `allows` false; HTTP GET `route('admin.numberings.index')` sebagai SM → 403)
  - `test_store_manager_boleh_menulis_di_lima_area` (`allows` true untuk POST pada `admin.eod.store`, `admin.stock-review.resolve`, `admin.stock-adjustments.store`, `admin.stock-transfers.store`, `admin.deliveries.update` (PUT); dan GET `admin.stock-adjustments.create`)
  - `test_store_manager_hanya_membaca_master`: GET `admin.products.index` true; POST `admin.products.store`, PUT `admin.users.update`, DELETE `admin.customers.destroy`, GET `admin.products.create`, GET `admin.users.edit` → false; `admin.promotion-hierarchy.edit` GET → true
  - `test_sapu_semua_rute_admin_non_get_untuk_store_manager`: iterasi `Route::getRoutes()`, nama diawali `admin.`, metode selain GET/HEAD; `allows($sm, name, method)` harus false kecuali nama diawali salah satu `WRITABLE`
  - `test_store_manager_tanpa_gerai_ditolak_403` (GET `admin.dashboard` → 403)
  - `test_penolakan_permintaan_json_mengembalikan_403_json` (`getJson(route('admin.numberings.index'))` sebagai SM → 403)
  - `test_kasir_dan_user_nonaktif_ditolak` (tidak berubah dari `EnsureUserIsAdmin`)
- [ ] **Step 2: Jalankan** `php artisan test --compact tests/Feature/BackofficeAccessTest.php` — Expected: FAIL (kelas belum ada).
- [ ] **Step 3: Implementasi `BackofficeAccess`.** Aturan `allows`: Owner true; bukan SM false; nama kosong → hanya GET; `CLOSED` false; rute berakhiran `.create`/`.edit` (kecuali `READABLE_FORMS`) dianggap tulis; metode non-GET/HEAD dan tulis diizinkan hanya jika diawali `WRITABLE`; selain itu true.
- [ ] **Step 4: Implementasi `EnsureBackofficeAccess`** (`php artisan make:middleware`): ambil `$request->user()` dan `$request->route()?->getName()`; SM tanpa `store_id` → `abort(403, 'Akun Anda belum ditugaskan ke gerai. Hubungi Owner.')`; tolak dengan `abort(403, 'Anda tidak memiliki akses ke halaman atau aksi ini.')`; `view()->share('readOnly', BackofficeAccess::isReadOnly(...))`. Daftarkan alias di `bootstrap/app.php`, pasang di grup `/admin` di `routes/web.php`.
- [ ] **Step 5: Jalankan test Task 1** — Expected: PASS.
- [ ] **Step 6: Jalankan seluruh suite**, catat test lama yang gagal akibat pembatasan SM (hanya yang menyangkut SM yang diperbolehkan berubah; perbaikannya di Task 2). Expected: hanya test SM di `PromotionHierarchyTest`/`CustomerLoyaltyTest`/sejenisnya yang terdampak.
- [ ] **Step 7: Commit** `Hak akses SM: BackofficeAccess dan middleware backoffice.access`.

### Task 2: Halaman master dapat dibaca SM

**Files:**
- Modify: `CurrencyController`, `CustomerGroupController`, `MemberLevelController`, `PaymentTypeController`, `StoreController`, `TaxGroupController`, `UserController`, `WarehouseController`, `PromotionHierarchyController` (semua di `app/Http/Controllers/Admin/`)
- Modify test: `tests/Feature/CustomerLoyaltyTest.php` (`test_member_level_hanya_untuk_owner`), `tests/Feature/PromotionHierarchyTest.php` (test SM), test lain yang menyatakan SM dilarang melihat halaman ini
- Test: `tests/Feature/BackofficeAccessTest.php`

**Interfaces:**
- Consumes: middleware dari Task 1 (menolak tulis dan formulir).
- Produces: aksi baca (`index`, `show`, `PromotionHierarchyController@edit`) tidak lagi memanggil `ensureSuperuser`; aksi tulis tetap memanggilnya (pertahanan berlapis).

- [ ] **Step 1: Tulis test merah** `test_store_manager_dapat_membuka_halaman_baca` (provider nama rute GET: `admin.stores.index`, `admin.warehouses.index`, `admin.tax-groups.index`, `admin.users.index`, `admin.member-levels.index`, `admin.currencies.index`, `admin.payment-types.index`, `admin.customer-groups.index`, `admin.promotion-hierarchy.edit`, `admin.products.index`, `admin.customers.index`, `admin.price-lists.index`, `admin.promotions.index`, `admin.groups.index`, `admin.units.index`, `admin.brands.index`) → 200. Dan `test_store_manager_tidak_dapat_menyimpan_master` (PUT `admin.promotion-hierarchy.update`, POST `admin.users.reset-password`, POST `admin.customers.adjust-points` → 403).
- [ ] **Step 2: Jalankan** — Expected: FAIL pada halaman yang masih memanggil `ensureSuperuser` di `index`.
- [ ] **Step 3: Hapus `ensureSuperuser` dari aksi baca** pada controller di atas (biarkan di aksi tulis). Jika view meminta data khusus Owner (mis. daftar gerai untuk pemindahan), pastikan tidak error bagi SM.
- [ ] **Step 4: Ubah test lama** yang mengharapkan SM 403 pada halaman baca: `test_member_level_hanya_untuk_owner` menjadi `test_member_level_dapat_dilihat_store_manager_tetapi_tidak_diubah` (GET index 200; POST `admin.member-levels.store` 403); samakan untuk `PromotionHierarchyTest`.
- [ ] **Step 5: Jalankan seluruh suite** — Expected: PASS.
- [ ] **Step 6: Commit** `Hak akses SM: halaman master dapat dilihat (hanya baca)`.

### Task 3: Menu dan tombol aksi mengikuti matriks

**Files:**
- Modify: `resources/views/layouts/admin.blade.php` (menu), view di `resources/views/admin/**` yang memuat link/form tulis (cari rute `*.create`, `*.edit`, `*.destroy`, form `POST/PUT/DELETE`)
- Test: `tests/Feature/BackofficeAccessTest.php`

**Interfaces:**
- Consumes: `BackofficeAccess::visible()` dan `$readOnly` dari Task 1.
- Produces: item menu memakai `BackofficeAccess::visible(auth()->user(), '<nama rute>')` menggantikan `$isSuperuser` untuk visibilitas; tombol tulis dibungkus `@unless ($readOnly)`.

- [ ] **Step 1: Tulis test merah.**
  - `test_menu_store_manager_menyembunyikan_empat_halaman_tertutup`: GET `admin.dashboard` sebagai SM; `assertDontSee` URL `admin.company.edit`, `admin.numberings.index`; `assertSee` URL `admin.users.index`, `admin.eod.index`, `admin.logs.index`.
  - `test_halaman_baca_saja_tidak_menampilkan_tombol_tulis` (provider: `admin.products.index`, `admin.customers.index`, `admin.price-lists.index`, `admin.promotions.index`, `admin.groups.index`, `admin.units.index`, `admin.brands.index`, `admin.stores.index`, `admin.warehouses.index`, `admin.tax-groups.index`, `admin.users.index`, `admin.currencies.index`, `admin.payment-types.index`, `admin.customer-groups.index`, `admin.member-levels.index`): sebagai SM `assertDontSee` URL `.create` rute itu dan `name="_method" value="DELETE"`; sebagai Owner `assertSee` URL `.create`.
  - `test_area_tulis_menampilkan_tombol_untuk_store_manager`: GET `admin.stock-adjustments.index` sebagai SM masih memuat URL `admin.stock-adjustments.create`.
- [ ] **Step 2: Jalankan** — Expected: FAIL.
- [ ] **Step 3: Ganti `$isSuperuser` di menu** dengan `BackofficeAccess::visible(...)` per item (pertahankan kondisi `sapIntegrationActive()`); item Log Aktivitas dan Sesi Kasir kini terlihat SM.
- [ ] **Step 4: Bungkus tombol/form tulis** di view yang gagal pada test dengan `@unless ($readOnly)`; ganti `isSuperuser()` yang artinya "boleh menulis master" dengan `$readOnly`.
- [ ] **Step 5: Jalankan test Task 3 dan seluruh suite** — Expected: PASS.
- [ ] **Step 6: Commit** `Hak akses SM: menu dan tombol aksi mengikuti matriks`.

### Task 4: Data per gerai (Sesi Kasir, Log Aktivitas, regresi)

**Files:**
- Create: migrasi `add_store_id_to_activity_logs_table` (`php artisan make:migration`), `tests/Feature/StoreManagerScopeTest.php`
- Modify: `app/Models/ActivityLog.php` (fillable/relasi `store`), `app/Support/ActivityLogger.php`, `app/Http/Controllers/Admin/ActivityLogController.php`, `app/Http/Controllers/Admin/PosSessionController.php`

**Interfaces:**
- Produces: kolom `activity_logs.store_id` (uuid nullable, FK `stores` null-on-delete, indeks); `ActivityLogger::log` mengisinya dari `store_id` user yang beraksi; non-Owner hanya melihat baris dengan `store_id` miliknya (Log Aktivitas) dan `pos_sessions.store_id` miliknya (Sesi Kasir). Baris lama diisi dari `users.store_id` aktor di migrasi.

- [ ] **Step 1: Tulis test merah di `StoreManagerScopeTest`** (dua gerai A/B, SM A, Owner):
  - `test_log_aktivitas_store_manager_hanya_gerainya` dan `test_owner_melihat_semua_log`
  - `test_activity_logger_mengisi_store_id_dari_user`
  - `test_migrasi_mengisi_store_id_log_lama_dari_aktor` (sisipkan log tanpa `store_id` lalu jalankan SQL isi ulang yang sama — atau cukup uji `ActivityLogger`; migrasi dicek manual di Step 5)
  - `test_sesi_kasir_store_manager_hanya_gerainya`
  - `test_parameter_store_diabaikan_store_manager` (GET `admin.stock.index?store=B`, `admin.reports.show?store=B`, `admin.cancellations.index?store=B` → data hanya gerai A)
  - `test_pengiriman_store_manager_gerainya_saja` (PUT `admin.deliveries.update` pengiriman gerai A → sukses; gerai B → 403)
  - `test_halaman_data_per_gerai_kosong_tidak_error` (SM gerai tanpa data: dashboard, laporan, stok, log, sesi kasir → 200)
- [ ] **Step 2: Jalankan** — Expected: FAIL.
- [ ] **Step 3: Migrasi dan `ActivityLogger`.** Kolom + indeks; backfill dengan `UPDATE activity_logs SET store_id = (SELECT store_id FROM users WHERE users.id = activity_logs.actor_id) WHERE actor_id IS NOT NULL`; `ActivityLogger` menambah `'store_id' => $user?->store_id`.
- [ ] **Step 4: Controller.** `ActivityLogController@index` dan `PosSessionController@index`: hapus `ensureSuperuser`; untuk non-Owner tambahkan `where('store_id', $user->store_id)`.
- [ ] **Step 5: Jalankan** `php artisan migrate` pada DB lokal untuk memastikan migrasi berjalan, lalu test Task 4 dan seluruh suite — Expected: PASS.
- [ ] **Step 6: Commit** `Hak akses SM: Log Aktivitas dan Sesi Kasir per gerai, regresi data per gerai`.

### Task 5: Aturan peran transfer stok

**Files:**
- Modify: `app/Http/Controllers/Admin/StockTransferController.php`
- Test: `tests/Feature/StockTest.php` (atau `StoreManagerScopeTest`)

**Interfaces:**
- Produces: `private function authorizeTransfer(Request $request, StockTransfer $transfer, string $side = 'either'): void` dengan `$side` ∈ `'from'|'to'|'either'`; `ship` dan `cancel` memakai `'from'`, `receiveForm` dan `receive` memakai `'to'`; Owner selalu lolos.

- [ ] **Step 1: Tulis test merah:** `test_hanya_gerai_asal_yang_mengirim_dan_membatalkan` (SM gerai tujuan → 403 pada `ship`/`cancel`), `test_hanya_gerai_tujuan_yang_menerima` (SM gerai asal → 403 pada `receive-form`/`receive`), `test_sm_asal_mengirim_dan_sm_tujuan_menerima_sukses` (alur lengkap), `test_owner_tetap_bebas`.
- [ ] **Step 2: Jalankan** — Expected: FAIL.
- [ ] **Step 3: Implementasi** `authorizeTransfer` dengan parameter `$side` dan perbarui empat pemanggil.
- [ ] **Step 4: Jalankan test Task 5 dan `tests/Feature/StockTest.php`** — Expected: PASS.
- [ ] **Step 5: Commit** `Hak akses SM: kirim/batal transfer oleh gerai asal, terima oleh gerai tujuan`.

### Task 6: PRD, aturan proyek, dan verifikasi akhir

**Files:**
- Modify: `docs/PRD-easyPOS-SaaS-MVP.md`

- [ ] **Step 1: Perbarui PRD:** persona SM (Bagian 3), FR baru matriks akses di A1 (nomor berikutnya yang kosong) beserta aturan transfer, FR-A9-03 / FR-B6-01 / FR-B8 sesuaikan, hapus butir terbuka "Store Manager" di Bagian 10, ringkasan di kepala dokumen.
- [ ] **Step 2: Catat aturan proyek** dengan Boost `record-rule` (glob `app/Support/BackofficeAccess.php`, `routes/web.php`): rute admin baru otomatis tertutup untuk SM; daftarkan di `BackofficeAccess::WRITABLE` bila SM boleh menulis, di `CLOSED` bila tertutup total.
- [ ] **Step 3: Jalankan** `vendor/bin/pint --dirty --format agent` lalu `php artisan test --compact` — Expected: seluruh suite PASS.
- [ ] **Step 4: Commit** `PRD dan aturan: hak akses Store Manager`.
