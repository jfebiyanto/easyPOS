# Stock Transfer Dua Langkah Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transfer stok antar store dalam dua langkah (Shipment → gudang in-transit pengirim, Receipt → gudang penerima), dicatat di buku besar warehouse dan diposting ke SAP B1 sebagai Stock Transfer.

**Architecture:** Dua dokumen baru (Shipment + lines, Receipt + lines) di tabel baru; `StockTransferService` memindahkan stok di `WarehouseStockService` dalam satu transaksi DB lalu mengantrekan job posting SAP (`SapStockTransferPosting`, pola `SapSalePosting`). Mutasi OIVL TransType 67 yang kembali dari SAP ditautkan ke baris ledger yang sudah ada. Alur lama `stock_transfers` menjadi halaman Legacy baca-saja.

**Tech Stack:** Laravel (PHP 8.5), PHPUnit, Blade, Service Layer SAP B1 (`SapServiceLayer`), queue.

**Spec:** `docs/superpowers/specs/2026-11-06-stock-transfer-design.md` (dibaca bersama rencana ini).

## Global Constraints
- Semua teks UI, pesan flash/validasi, dan deskripsi Activity Log baru **berbahasa Inggris**.
- Ikuti `.ai/rules/sap-stock.md` dan `.ai/rules/backoffice-access.md`; baca sebelum mengubah `app/Support/Sap/**`, `BackofficeAccess`, `routes/web.php`.
- UDF header dokumen SAP: `U_SOL_FRST='Y'`, `U_SOL_TRKY` = UUID dokumen easyPOS, `U_SOL_STID` = UUID store pembuat dokumen. Hanya ObjType (67) + DocNum yang ditampilkan; DocEntry tidak ditampilkan.
- `U_SOL_WHTR` = kode gudang in-transit pengirim; gudang in-transit ditandai `U_SOL_ITWH = 'Y'` dan selalu non-retail.
- Syarat Post Shipment: `CompanyDetail::sapIntegrationActive()`, kedua store punya `warehouse_id`, warehouse pengirim punya `transit_warehouse_code` yang cocok dengan warehouse `is_transit` di easyPOS. Tanpa mode standalone.
- Stok pengirim tidak boleh negatif kecuali `StockService::allowsNegative()` (sama dengan `SaleStock::assertAvailable`).
- Receipt: tanpa Draft dan tanpa pembatalan; qty per baris `0 < qty <= sisa`; minimal satu baris. Shipment: Draft → Posted; tidak bisa dibatalkan setelah Posted.
- Store Manager hanya store sendiri (`ScopesToStore`); kirim ulang SAP hanya Owner (`AuthorizesSuperuser`).
- Setiap perubahan PHP: `vendor/bin/pint --dirty --format agent`; uji dengan `php artisan test --compact`. Commit tiap task dengan trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus
Input/kondisi yang tidak disebut spec tetapi mungkin muncul; tiap baris punya test di task pemilik kodenya:
1. Dua Receipt bersamaan untuk Shipment yang sama tidak boleh melewati sisa (Task 3, `lockForUpdate`).
2. Satu produk muncul dua kali di satu Shipment (baris ganda) — sisa dihitung per baris, bukan per produk (Task 3).
3. Receipt diposting saat Shipment-nya masih `pending`/`failed` di SAP — Receipt harus menunggu, lalu terkirim otomatis setelah Shipment terposting (Task 4).
4. Mutasi OIVL TransType 67 datang dua baris per dokumen (keluar dan masuk) — keduanya harus ditautkan, bukan ditambahkan lagi; baris OIVL dari transfer yang dibuat langsung di SAP (tanpa dokumen easyPOS) tetap disalin normal (Task 4).
5. Store Manager membuka Shipment/Receipt milik store lain via URL langsung → 403/404 (Task 5 & 6).

---

### Task 1: Data gudang in-transit dan impor OWHS

**Files:**
- Create: migration `add_transit_columns_to_warehouses_table` (`php artisan make:migration`)
- Modify: `app/Models/Warehouse.php`, `app/Support/Sap/SapImportService.php` (`importWarehouses`), `database/factories/WarehouseFactory.php`
- Test: `tests/Feature/WarehouseTransitImportTest.php` (pola `tests/Feature/SapImportTest.php`)

**Interfaces:**
- Produces: `warehouses.transit_warehouse_code` (string 30, nullable), `warehouses.is_transit` (bool, default false); `Warehouse::transitWarehouse(): ?Warehouse` (cari `code = transit_warehouse_code` di company yang sama dan `is_transit`); factory state `transit()` (non-retail + `is_transit`).

- [ ] **Step 1: Test.** `test_impor_warehouse_membaca_udf_transit`: fake OWHS dengan `G.001.MN` (`U_SOL_RETAIL=Y`, `U_SOL_WHTR='G.001.IT'`) dan `G.001.IT` (`U_SOL_ITWH='Y'`, `U_SOL_RETAIL` kosong); assert `G.001.MN->transit_warehouse_code === 'G.001.IT'`, `G.001.IT->is_transit === true` dan `is_retail === false`, `G.001.MN->transitWarehouse()->code === 'G.001.IT'`. `test_gudang_in_transit_tidak_ikut_pilihan_warehouse_store`: halaman form store tidak menawarkan gudang `is_transit`.
- [ ] **Step 2: Jalankan**, harus gagal.
- [ ] **Step 3: Migrasi + model.** Tambah kolom; `$fillable` dan casts `is_transit`; `transitWarehouse()`.
- [ ] **Step 4: Impor.** Di `importWarehouses` isi `transit_warehouse_code` (`cut(..., 30)`, null jika kosong) dan `is_transit` (`U_SOL_ITWH === 'Y'`); gudang `is_transit` dipaksa `is_retail = false`.
- [ ] **Step 5: Jalankan test + Pint + commit** (`Warehouse: sinkronkan U_SOL_WHTR dan U_SOL_ITWH`).

### Task 2: Skema dan model Shipment/Receipt

**Files:**
- Create: 4 migrasi (`stock_transfer_shipments`, `stock_transfer_shipment_lines`, `stock_transfer_receipts`, `stock_transfer_receipt_lines`; `uuid('id')->primary()`, pola `create_warehouse_stock_ledgers_table`); model `StockTransferShipment`, `StockTransferShipmentLine`, `StockTransferReceipt`, `StockTransferReceiptLine` (`HasUuid7`), trait `app/Models/Concerns/PostsToSap.php`; factories untuk keempatnya.
- Test: `tests/Feature/StockTransferModelTest.php`

**Interfaces:**
- Kolom sesuai spec (bagian Data). Kolom SAP di kedua header: `sap_post_status`, `sap_post_error`, `sap_doc_num` (string 20), `sap_doc_entry` (unsignedInteger, `$hidden`), `sap_posted_at`. Tambahan: `StockTransferReceipt.company_id`, `shipment_id`, `store_id`, `number`, `note`, `received_at`, `created_by`.
- `PostsToSap`: konstanta `SAP_PENDING='pending'`, `SAP_POSTED='posted'`, `SAP_FAILED='failed'`.
- `StockTransferShipment`: konstanta `STATUS_DRAFT`, `STATUS_POSTED`; relasi `lines()`, `receipts()`, `fromStore()`, `toStore()`, `fromWarehouse()`, `transitWarehouse()`, `toWarehouse()`; `displayStatus(): string` ∈ `Draft|In Transit|Partially Received|Completed`.
- `StockTransferShipmentLine`: `remaining(): float` = `qty - qty_received` (dibulatkan 3 desimal).

- [ ] **Step 1: Test.** `test_status_turunan_shipment` (Draft; posted tanpa terima = In Transit; sebagian = Partially Received; Σ diterima = Σ dikirim = Completed) dan `test_sisa_baris` memakai factory.
- [ ] **Step 2: Jalankan**, gagal. **Step 3:** migrasi, model, trait, factory. **Step 4:** test lulus.
- [ ] **Step 5: Pint + commit** (`Transfer stok: skema dan model Shipment/Receipt`).

### Task 3: StockTransferService (Post Shipment, Receipt)

**Files:**
- Create: `app/Support/StockTransferService.php`, `app/Support/StockTransferException.php` (extends `RuntimeException`)
- Test: `tests/Feature/StockTransferServiceTest.php`

**Interfaces:**
- Consumes: Task 1 (`Warehouse::transitWarehouse`), Task 2 (model), `WarehouseStockService::record(Warehouse, string $productId, string $type, float $qty, array $options)`, `NumberingConfig::nextNumber`, `SaleStock::assertAvailable`.
- Produces:
  - `StockTransferService::post(StockTransferShipment $shipment, User $by): void` — validasi syarat Post (urutan: integrasi aktif, warehouse kedua store, in-transit pengirim, minimal satu baris qty>0, stok), salin `from/transit/to_warehouse_id`, catat ledger (`TYPE_TRANSFER_OUT` pengirim −qty, `TYPE_TRANSFER_IN` in-transit +qty, `ref_type='stock_transfer_shipment'`, `ref_id=$shipment->id`, `created_by`), set `status=posted`, `posted_at`, `number`, `sap_post_status=pending`, lalu `SapStockTransferPosting::queue($shipment)` (dibuat di Task 4; sampai saat itu dispatch di-stub dengan `Queue::fake()` di test).
  - `StockTransferService::receive(StockTransferShipment $shipment, array $quantities, User $by, ?string $note = null): StockTransferReceipt` — `$quantities` = `[shipment_line_id => qty]`; baris tidak ada di array = tidak diterima; validasi: shipment `posted`, ≥ 1 baris, baris milik shipment, `0 < qty <= remaining()`; dalam transaksi dengan `lockForUpdate` pada baris shipment: ledger in-transit −qty dan warehouse penerima +qty (`TYPE_TRANSFER_OUT`/`TYPE_TRANSFER_IN`, `ref_type='stock_transfer_receipt'`, `ref_id=$receipt->id`), tambah `qty_received`, `number` dari `stock_transfer_receipt`, `sap_post_status=pending`, antre.
  - Semua pelanggaran melempar `StockTransferException` dengan pesan Inggris yang menyebut kode warehouse bermasalah (mis. "Source warehouse G.001.MN has no in-transit warehouse (U_SOL_WHTR).").

- [ ] **Step 1: Tests** (`Queue::fake()`; company `sap_enabled` + config seperti `SapSalePostingTest::setUp`; dua store/warehouse + `G.001.IT` via factory `transit()`): `test_post_memindahkan_stok_ke_in_transit` (saldo pengirim −5, in-transit +5, nomor terisi, status posted); `test_post_ditolak_tanpa_integrasi_sap`; `test_post_ditolak_bila_in_transit_kosong_menyebut_kode`; `test_post_ditolak_bila_store_tanpa_warehouse`; `test_post_ditolak_stok_tidak_cukup` (allow_negative_stock false); `test_receipt_penuh_menyelesaikan_shipment`; `test_receipt_parsial_berulang_sampai_habis` (3 lalu 2 dari 5; ketiga ditolak); `test_receipt_melebihi_sisa_ditolak`; `test_receipt_tanpa_baris_ditolak`; `test_baris_ganda_produk_sama_dihitung_per_baris` (Review Focus 2); `test_receipt_bersamaan_tidak_melewati_sisa` (Review Focus 1: dua `receive` berurutan masing-masing sisa penuh → kedua tidak lolos; gunakan transaksi + `lockForUpdate` dan assert saldo akhir in-transit = 0, bukan negatif).
- [ ] **Step 2:** jalankan → gagal. **Step 3:** implementasi (satu `DB::transaction` per operasi; dispatch SAP `afterCommit`). **Step 4:** lulus.
- [ ] **Step 5: Pint + commit** (`Transfer stok: layanan Post Shipment dan Receipt`).

### Task 4: Posting SAP, job, kirim ulang, dan penautan OIVL

**Files:**
- Create: `app/Support/Sap/SapStockTransferPosting.php`, `app/Jobs/PostStockTransferToSap.php`
- Modify: `app/Support/Sap/SapStockMutationService.php` (tambah `linkToEasyPosTransfer` dan panggil di tempat `linkToEasyPosSale` dipanggil)
- Test: `tests/Feature/SapStockTransferPostingTest.php` (helper `fakeSap` meniru `SapSalePostingTest::fakeSap`)

**Interfaces:**
- Produces: `SapStockTransferPosting::OBJ_STOCK_TRANSFER = 67`; `static queue(StockTransferShipment|StockTransferReceipt $doc): void` (abaikan bila integrasi mati atau sudah `posted`; set `pending`, `PostStockTransferToSap::dispatch($doc::class, $doc->id)->afterCommit()`); `post(StockTransferShipment|StockTransferReceipt $doc): void`. Job: `__construct(public string $documentClass, public string $documentId)`, `$tries = 1`.
- `post()` membangun `StockTransfers`: `FromWarehouse`/`ToWarehouse` = kode (Shipment: from→transit; Receipt: transit→to), `DocDate` = tanggal posting/terima, `Comments` = "easyPOS {number}", marker UDF (Global Constraints; `U_SOL_STID` = store pembuat: from store untuk Shipment, receipt `store_id`), `StockTransferLines` = `[ItemCode=SKU, Quantity, FromWarehouseCode, WarehouseCode]`. Cari dulu `client->find('StockTransfers', ['$select'=>'DocEntry,DocNum','$filter'=>"U_SOL_TRKY eq '{$id}'"])`; simpan `sap_doc_entry`, `sap_doc_num`, `sap_post_status=posted`, `sap_posted_at`. Gagal → `failed` + `sap_post_error` (potong 500).
- Receipt: jika `$receipt->shipment->sap_post_status !== posted`, biarkan `pending` dan return; setelah Shipment terposting, `post()` mengantrekan semua Receipt `pending` milik Shipment itu.
- Kirim ulang: lihat Task 5/6 (controller memanggil `queue()` setelah reset `failed` → `pending`).
- `linkToEasyPosTransfer(array $row): bool` — `TransType == 67` dan `CreatedBy` = `sap_doc_entry` Shipment (ref `stock_transfer_shipment`) atau Receipt (ref `stock_transfer_receipt`) milik company; cari satu baris `WarehouseStockLedger` dengan `ref_type`, `ref_id`, warehouse (`LocCode`), produk (`ItemCode`), `qty` (InQty−OutQty), `sap_trans_seq` null; isi `sapAttributes($row)`. Dua baris OIVL per dokumen tertaut masing-masing ke baris ledger lawannya.

- [ ] **Step 1: Tests:** `test_shipment_diposting_dengan_payload_benar` (assert body request `StockTransfers`: FromWarehouse `G.001.MN`, ToWarehouse `G.001.IT`, UDF, baris); `test_receipt_memakai_in_transit_ke_warehouse_penerima`; `test_kirim_ulang_idempoten_memakai_u_sol_trky` (find mengembalikan dokumen → tidak ada POST baru); `test_kegagalan_sap_menandai_failed_dengan_pesan`; `test_receipt_menunggu_shipment_terposting` lalu terkirim setelahnya (Review Focus 3, `Queue::fake` lalu jalankan job manual); `test_oivl_transtype_67_menaut_bukan_menggandakan` (jalankan `SapStockMutationService` dengan dua baris OIVL; jumlah baris ledger dan `on_hand` tidak berubah, `sap_trans_seq` terisi) dan `test_oivl_transfer_dari_sap_tanpa_dokumen_easypos_tetap_disalin` (Review Focus 4; pola `SapStockMutationTest`).
- [ ] **Step 2:** gagal. **Step 3:** implementasi; sambungkan `StockTransferService` ke `SapStockTransferPosting::queue` (hapus stub Task 3). **Step 4:** lulus, termasuk `SapStockMutationTest` yang lama.
- [ ] **Step 5: Pint + commit** (`Transfer stok: posting SAP dan penautan OIVL`).

### Task 5: UI Shipment, rute, menu, akses

**Files:**
- Create: `app/Http/Controllers/Admin/StockTransferShipmentController.php`, views `resources/views/admin/stock/shipments/{index,form,show}.blade.php`
- Modify: `routes/web.php`, `app/Support/BackofficeAccess.php` (`WRITABLE`: ganti `'admin.stock-transfers.'` dengan `'admin.stock-transfer-shipments.'`, `'admin.stock-transfer-receipts.'`), `resources/views/layouts/admin.blade.php` (ganti item "Stock Transfers" dengan "Stock Transfer Shipment" dan "Stock Transfer Receipt"), `tests/Feature/BackofficeAccessTest.php` (data provider `writableRoutes`: `admin.stock-transfer-shipments.store`)
- Test: `tests/Feature/StockTransferShipmentPageTest.php`

**Interfaces:**
- Rute (`name`): `stock-transfer-shipments.index|create|store|show|edit|update|destroy|post|retry` (`{shipment}` = `StockTransferShipment`). Controller memakai `AuthorizesCompany`, `ScopesToStore`; `destroy`/`edit`/`update` hanya Draft; `post` memanggil `StockTransferService::post` dan menangkap `StockTransferException` → `back()->with('error', $e->getMessage())`; `retry` memakai `ensureSuperuser` dan hanya untuk `failed`.
- Form: store tujuan (store lain di company), catatan, baris produk (`track_stock`) dengan tambah/hapus baris (ikuti `stock/transfers/form.blade.php` lama); store asal = `resolveStoreId`.
- Index: kolom nomor, tanggal, dari, ke, jumlah item, status (`displayStatus`), status SAP (+ DocNum bila posted); tombol Post (Draft), Receive (In Transit/Partially Received, tampil bila user boleh menerima — mengarah ke Task 6), Retry (Owner, `failed`). Tautan kecil "Legacy transfers" ke `admin.stock-transfers.index`.

- [ ] **Step 1: Tests:** Owner membuat Draft lalu Post (stok berpindah, flash sukses); Draft bisa diubah/dihapus, Posted tidak (`422`/redirect error); Post ditolak menampilkan pesan Task 3; SM store A melihat Shipment A↔B tetapi 403/404 pada Shipment C↔D (Review Focus 5); SM tidak bisa membuat Shipment dari store lain; `retry` ditolak untuk SM; menu menampilkan kedua caption. Jalankan `BackofficeAccessTest` (sweep rute non-GET harus tetap lulus).
- [ ] **Step 2:** gagal. **Step 3:** implementasi. **Step 4:** lulus. **Step 5: Pint + commit** (`Transfer stok: halaman Shipment`).

### Task 6: UI Receipt dengan prefill dari Shipment

**Files:**
- Create: `app/Http/Controllers/Admin/StockTransferReceiptController.php`, views `resources/views/admin/stock/receipts/{index,create,show}.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/StockTransferReceiptPageTest.php`

**Interfaces:**
- Rute: `stock-transfer-receipts.index|create|store|show|retry`. `create` menerima `?shipment={id}`: tanpa parameter → halaman pemilih (dropdown nomor Shipment `posted` yang `to_store_id` ∈ `allowedStoreIds` dan masih punya sisa; kosong → empty state); dengan parameter → form terisi **semua baris yang `remaining() > 0`** dengan qty = sisa, produk/Shipment tidak bisa diubah, tiap baris punya input qty (`name="lines[{shipment_line_id}][qty]"`, `max` = sisa) dan tombol hapus baris (JS: hapus `<tr>`; baris terhapus tidak terkirim). `store` meneruskan `[line_id => qty]` ke `StockTransferService::receive`; `StockTransferException` → kembali ke form dengan input dan pesan.
- Otorisasi: Shipment harus `posted` dan `to_store_id` ∈ `allowedStoreIds`; selainnya 403.

- [ ] **Step 1: Tests:** prefill menampilkan semua baris bersisa dengan qty = sisa dan menyembunyikan baris yang sudah habis; kirim form dengan satu baris dihapus → baris itu tetap di in-transit (`remaining` utuh); ubah qty lebih kecil → `qty_received` sesuai, Shipment `Partially Received`; qty melebihi sisa ditolak dengan pesan; semua baris dihapus ditolak; Shipment milik store lain ditolak (403) dan SM store pengirim tidak bisa menerima; tombol "Receive" di daftar Shipment mengarah ke form terisi; `retry` hanya Owner.
- [ ] **Step 2:** gagal. **Step 3:** implementasi. **Step 4:** lulus. **Step 5: Pint + commit** (`Transfer stok: halaman Receipt terisi otomatis dari Shipment`).

### Task 7: Halaman Legacy, pembersihan alur lama, dokumentasi

**Files:**
- Modify: `app/Http/Controllers/Admin/StockTransferController.php` (sisakan `index`), `routes/web.php` (hapus rute `create|store|ship|receive-form|receive|cancel` lama), `resources/views/admin/stock/transfers/index.blade.php` (judul "Legacy Stock Transfers", tanpa tombol aksi, banner "Read-only — replaced by Stock Transfer Shipment/Receipt"); hapus `form.blade.php` dan `receive.blade.php` lama; `.ai/rules/sap-stock.md` (bagian "Stok penjualan POS": transfer kini lewat buku besar warehouse dan diposting; tambah aturan OIVL TransType 67 + `U_SOL_WHTR`/`U_SOL_ITWH`) lewat Boost `record-rule`; PRD; `docs/ERD.md`/`ERD.html` (tabel baru + kolom warehouse); `tests/Feature/{FoundationTest,StockTest,StoreManagerScopeTest,BackofficeAccessTest}.php` (hapus/porting tes alur lama ke tes baru Task 5–6).
- Test: `tests/Feature/LegacyStockTransferPageTest.php` (halaman lama hanya bisa dilihat; rute tulis lama tidak ada → 404/405).

- [ ] **Step 1: Test** halaman Legacy baca-saja untuk Owner dan SM. **Step 2:** gagal. **Step 3:** pangkas controller/rute/view, perbarui tes lama dan dokumentasi. **Step 4:** `php artisan test --compact` penuh, `npm run build`, `vendor/bin/pint --dirty --format agent`. **Step 5: Commit** (`Transfer stok: halaman Legacy dan dokumentasi`).
