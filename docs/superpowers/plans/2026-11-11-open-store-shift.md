# Open Store dan Shift Kasir Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Toko dibuka kasir dari POS (Open Store), tiap kasir menjalankan shift dengan modal awal, dan EOD hanya untuk toko yang dibuka serta tanpa shift aktif.

**Architecture:** Status toko diturunkan dari `store_closures` (`opened_at`/`opened_by`, `closed_at` = EOD). Shift kasir di tabel baru `cashier_shifts` (+ `cashier_shift_denominations`). Seluruh aturan ada di satu service `App\Support\ShiftService`; controller POS dan EOD tipis. Modal standar lewat `Store::openingFloatStandard()`.

**Tech Stack:** Laravel (PHPUnit), Blade admin, React POS (Vite, vitest).

**Spec:** `docs/superpowers/specs/2026-11-11-open-store-shift-design.md`

## Global Constraints
- Ikuti `.ai/rules`: rute admin baru di bawah prefiks `admin.eod.` (sudah `WRITABLE`); layar POS baru tidak boleh `display: none` pada bagian grid `.pos`; Store Manager hanya data gerainya (`ScopesToStore`).
- Teks UI POS berbahasa Indonesia, teks Admin Console berbahasa Inggris (seperti file yang ada).
- PHP: tipe eksplisit, kurung kurawal, constructor promotion; jalankan `vendor/bin/pint --dirty --format agent` setelah mengubah PHP.
- Model memakai `HasUuid7`; migration bernama `2026_11_11_*`.
- Penjualan `origin=offline` tidak pernah ditolak oleh status toko/shift.
- Modal bukan penjualan: tidak masuk `sales_total`.
- Jangan ubah dependensi; jangan commit kecuali diminta.

## Review Focus
- Penjualan offline setelah EOD / tanpa shift tetap 201.
- Variance modal ≠ 0 tanpa remark ditolak di server (bukan hanya di UI).
- Dua kasir: modal masing-masing dijumlahkan di `cash_expected`; modal tidak masuk `sales_total`.
- Kasir login lagi di hari sama setelah logout: Start Shift, bukan Open Store.
- Toko tanpa baris `store_closures` (tidak pernah dibuka) tidak muncul sebagai "belum EOD".
- SM tidak bisa Force End Shift gerai lain.

## File Structure
- Create: `app/Models/CashierShift.php`, `app/Models/CashierShiftDenomination.php`, `app/Support/ShiftService.php`, `tests/Concerns/StartsShift.php`, `tests/Feature/CashierShiftTest.php`, migration `2026_11_11_000000_create_cashier_shifts_and_opening_float.php`.
- Modify: `app/Models/StoreClosure.php`, `app/Models/Store.php`, `app/Support/navitem.php`, `PosController.php`, `Auth/LoginController.php`, `Admin/StoreClosureController.php`, `Admin/StoreController.php`, `routes/web.php`, `resources/views/admin/eod/index.blade.php`, `resources/views/admin/stores/form.blade.php`, `resources/views/layouts/admin.blade.php`, `resources/js/pos/App.jsx`, `resources/js/pos/api.js`, test lama yang menjual lewat POS.

---

### Task 1: Data, model, dan modal standar

**Files:** migration, `CashierShift`, `CashierShiftDenomination`, `StoreClosure`, `Store`, form Store, `StoreController::validated`, `tests/Feature/CashierShiftTest.php`.

**Interfaces:**
- Produces: `CashierShift` (fillable semua kolom; `belongsTo` store/user; `hasMany denominations`; `scopeActive()`; `isActive(): bool`). Kolom `cashier_shifts`: `id uuid7, company_id, store_id, user_id, business_date date, started_at, ended_at null, ended_reason null (logout|expired|closed_by_admin), opened_store bool, opening_float/opening_float_expected/opening_float_variance decimal(15,2) default 0, opening_float_remark text null`. `cashier_shift_denominations`: `cashier_shift_id, denomination_value, count, subtotal`. `store_closures.opened_by` FK users nullable. `stores.opening_float` decimal(15,2) default 0.
- `Store::openingFloatStandard(): float`. `StoreClosure::isOpen(string $storeId, string $date): bool` (baris ada, `opened_at` terisi, `closed_at` null). Migration backfill: `opened_at` dari `MIN(sales.created_at)` bila kosong untuk baris yang ada, dan baris baru dengan `opened_at` untuk (toko, tanggal) yang punya transaksi tapi belum punya baris? — tidak; hanya isi `opened_at` baris yang sudah ada. Tanggal yang punya transaksi tanpa baris diperlakukan lewat Task 4 (lihat catatan di sana).

- [ ] **Step 1:** Test `test_modal_standar_toko_default_nol_dan_bisa_diubah` (store create/update `opening_float`, `openingFloatStandard() === 0.0` lalu `200000.0`) dan `test_isOpen_benar_hanya_untuk_toko_yang_dibuka_dan_belum_eod`. Jalankan → FAIL.
- [ ] **Step 2:** Tulis migration, dua model, `Store::openingFloatStandard`, `StoreClosure::isOpen`, `opened_by` di fillable, field `opening_float` di form dan validasi `StoreController::validated` (`numeric|min:0`).
- [ ] **Step 3:** `php artisan test --compact --filter=CashierShiftTest` → PASS.

### Task 2: ShiftService

**Files:** `app/Support/ShiftService.php`, `tests/Feature/CashierShiftTest.php`.

**Interfaces (Produces):**
- `ShiftService::gate(Store $store, User $user): array{state: 'not_opened'|'open'|'eod_done', has_shift: bool, opened_by: ?string, opened_at: ?string, posting_date: string, opening_float_standard: float, count_by_denomination: bool, denominations: list<array{value: float}>}`
- `ShiftService::openStore(Store $store, User $user, array $count): CashierShift` dan `startShift(Store $store, User $user, array $count): CashierShift`. `$count`: `['denominations' => [value => qty]]` atau `['amount' => float]`, plus `'remark' => ?string`. Tanggal = `StoreClosure::postingDate`.
- `ShiftService::endShift(User $user, string $reason): ?CashierShift`; `ShiftService::activeShifts(string $storeId, ?string $date = null): Collection`; `ShiftService::endActiveShifts(string $storeId, string $date, string $reason): int`; `ShiftService::expireStaleShifts(string $storeId): void` (shift aktif yang user-nya tak punya `PosSession` aktif segar → `expired`); `ShiftService::hasActiveShift(User $user, string $storeId): bool`.
- Pelanggaran aturan melempar `ValidationException` (field `denominations`/`amount`/`remark`) atau `RuntimeException` (toko sudah EOD).

- [ ] **Step 1:** Tests: `test_open_store_membuat_baris_buka_dan_shift_dengan_modal_dari_denominasi`; `test_open_store_kedua_ditolak_kasir_kedua_start_shift`; `test_variance_tanpa_remark_ditolak_dan_dengan_remark_tercatat` (standar 200000, hitung 150000 → variance -50000); `test_nominal_nol_diperbolehkan_bila_standar_nol`; `test_open_ditolak_setelah_eod`; `test_end_shift_tidak_menutup_toko`; `test_kasir_yang_sama_start_shift_lagi_setelah_logout`; `test_end_active_shifts_closed_by_admin`. FAIL.
- [ ] **Step 2:** Implement; semua penulisan dalam `DB::transaction`; hitung subtotal denominasi di server; variance = counted − standar (salin `opening_float_expected`); remark wajib bila variance ≠ 0; Count Type Summary memakai `amount`. Activity log `store.opened`, `shift.started` (memuat variance+remark), `shift.ended`, `shift.force_ended`.
- [ ] **Step 3:** Tests PASS.

### Task 3: Endpoint POS, guard transaksi, logout/expired

**Files:** `routes/web.php`, `PosController`, `LoginController`, `EnsurePosSessionIsActive` (tidak diubah; expired ditangani di service), `tests/Concerns/StartsShift.php`, test lama, `CashierShiftTest`.

**Interfaces:**
- Produces: `POST /pos/store/open` (`pos.store.open`), `POST /pos/shift/start` (`pos.shift.start`), `POST /pos/shift/end` (`pos.shift.end`), semua dalam grup `pos.store`+`pos.session`; body `{denominations?: {value: qty}, amount?: number, remark?: string}`. `bootstrap` menambah `shift_gate` = `ShiftService::gate(...)` plus `shift: {started_at, opened_store}|null` dan `shift_stats: {sales_count}` untuk dialog logout. `storeSale` (origin ≠ offline): 422 "Toko belum dibuka…" bila `! StoreClosure::isOpen` dan 422 "Mulai shift…" bila kasir tanpa shift aktif; offline dikecualikan. Owner/SM yang memakai POS memakai alur yang sama (hanya akun admin console tidak terpengaruh).
- `Tests\Concerns\StartsShift::startShiftFor(User $cashier, Store $store, float $float = 0): void` memanggil `ShiftService::openStore/startShift` lewat service.
- `LoginController::logout` memanggil `ShiftService::endShift($user, 'logout')` bila kasir.

- [ ] **Step 1:** Tests di `CashierShiftTest`: `test_penjualan_online_ditolak_sebelum_toko_dibuka`, `..._tanpa_shift_aktif`, `test_penjualan_online_diterima_setelah_open_store`, `test_penjualan_offline_tetap_diterima_setelah_eod_dan_tanpa_shift`, `test_logout_mengakhiri_shift_dan_toko_tetap_open`, `test_bootstrap_memuat_shift_gate_untuk_tiga_kondisi`, `test_endpoint_open_store_validasi_remark`. FAIL.
- [ ] **Step 2:** Implement route/controller/guard/logout. Setelah itu tambahkan `startShiftFor` di `setUp` test lama (`PosTest`, `PosCartTest`, `PosSessionTest`, `EodTest`, `ReportTest`, `CancellationReportTest`, `CustomerLoyaltyTest`, `SapSalePostingTest`, `SapReturnPostingTest`) yang menjual lewat POS.
- [ ] **Step 3:** `php artisan test --compact` untuk file di atas → PASS.

### Task 4: EOD (guard, modal, force end shift)

**Files:** `StoreClosureController`, `StoreClosure::expectedCash`, view EOD, `routes/web.php`, `tests/Feature/EodTest.php`.

**Interfaces:** rute `POST /admin/eod/force-end-shifts` (`admin.eod.force-end-shifts`) param `store_id`, `business_date`. `StoreClosure::expectedCash(...)` + `ShiftService::openingFloatTotal(string $storeId, string $date): float` (jumlah `opening_float` semua shift tanggal itu) sehingga `cash_expected` EOD = penjualan tunai + modal. `index` view data: `opening`: `{opened_by, opened_at, shifts: Collection}`, `openingFloatTotal`, `activeShifts`.
- `store`: ditolak bila toko tidak Open (`! isOpen`) → flash error; ditolak bila `activeShifts` ada → flash error + daftar. Toko tanpa baris ditolak EOD ("Store was not opened").
- `reopen`: hasil kembali ke Open (sudah demikian; pertahankan `opened_at`).
- Catatan backfill: transaksi lama di tanggal tanpa baris `store_closures` dianggap sudah dibuka implisit — `isOpen` tidak dipakai untuk tanggal sebelum `config`/tanggal rilis; implementasi: migration membuat baris `store_closures` (opened_at = transaksi pertama, closed_at null) untuk (toko, tanggal) yang punya transaksi dan belum punya baris.

- [ ] **Step 1:** Tests di `EodTest`: `test_modal_dijumlahkan_ke_kas_diharapkan_200rb_plus_700rb_900rb`; `test_modal_tidak_masuk_sales_total`; `test_eod_ditolak_toko_belum_dibuka`; `test_eod_ditolak_saat_ada_shift_aktif`; `test_force_end_shift_oleh_owner_lalu_eod_berhasil`; `test_sm_tidak_bisa_force_end_gerai_lain`; `test_halaman_eod_menampilkan_variance_dan_remark_modal`; `test_reopen_eod_kembali_open`; `test_backfill_migration` (di Task 1 bila perlu). FAIL.
- [ ] **Step 2:** Implement controller + view (kartu "Store Opening & Shifts": pembuka toko, daftar shift, modal standar/dihitung/variance/remark, tombol Force End Shift; baris "Opening float" di ringkasan kas). Hitungan denominasi EOD tetap seperti ada.
- [ ] **Step 3:** `php artisan test --compact tests/Feature/EodTest.php tests/Feature/StoreManagerScopeTest.php tests/Feature/BackofficeAccessTest.php` → PASS.

### Task 5: Status bar admin dan `store_business_status`

**Files:** `app/Support/navitem.php`, `layouts/admin.blade.php`, `DashboardService` bila menampilkan EOD, tests.

**Interfaces:** `store_business_status()` ditambah `opened: bool`, `opened_by: ?string`, `active_shifts: int`. `unclosedPriorDate` hanya menghitung tanggal dengan baris yang `opened_at` terisi (toko yang tak pernah dibuka tidak terkunci) namun tetap menghormati tanggal yang punya transaksi (backfill menjamin barisnya ada). Status bar: "Belum dibuka", "Open · N shift aktif".

- [ ] **Step 1:** Test: toko tanpa baris tidak memicu `locked_date`; `store_business_status` memuat field baru; halaman admin tampil teks status. FAIL → implement → PASS.

### Task 6: React POS gate, header, dialog logout

**Files:** `resources/js/pos/App.jsx`, `api.js`, `resources/js/pos/shiftGate.js` + `shiftGate.test.js`, `public/css/pos.css`, `layout.test.js`.

**Interfaces:** `api.openStore(body)`, `api.startShift(body)`, `api.endShift()`. `shiftGate.js` mengekspor `gateScreen(gate): 'open_store'|'start_shift'|'blocked'|null` dan `countFloat(denominations: Record<string,number>): number` dan `floatVariance(counted: number, standard: number): number`; murni, diuji vitest. Komponen `ShiftGateModal` (hitung per denominasi atau nominal; peringatan selisih; remark wajib bila selisih ≠ 0; teks Indonesia) dirender setelah `SessionPromptModal`; `LogoutConfirm` ("Akhiri shift Anda?" + jam mulai + jumlah transaksi) menggantikan `LogoutForm` langsung. Bagian grid tetap item grid (tanpa `display: none`).

- [ ] **Step 1:** Tests vitest `shiftGate.test.js` (layar tiap state, `countFloat({"100000":2})=200000`, `floatVariance(150000,200000)=-50000`). FAIL → implement helper → PASS.
- [ ] **Step 2:** Implement komponen di `App.jsx` dan sambungkan ke bootstrap; tampilkan jam shift di header. Jalankan `npm test` dan `npm run build`.

### Task 7: Verifikasi akhir
- [ ] `vendor/bin/pint --dirty --format agent`, `php artisan test --compact` (suite penuh), `npm test`, `npm run build`. Laporkan hasil apa adanya.

## Self-review
Semua butir spec punya task: status toko (1,5), shift+modal (2,3), POS gate/logout/expired (3,6), EOD guard/force end/modal/variance (4), admin status (5), backfill (1,4), offline (3), SM scope (4). Nama konsisten: `ShiftService::{gate,openStore,startShift,endShift,activeShifts,endActiveShifts,expireStaleShifts,hasActiveShift,openingFloatTotal}`.
