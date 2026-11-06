# Stock Transfer Return Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.

**Goal:** Pengembalian sisa barang dari gudang in-transit ke gudang pengirim (dokumen Stock Transfer Return) yang diposting ke SAP.

**Architecture:** Meniru Receipt: tabel + model + `StockTransferService::returnToSender()`, ekstensi `SapStockTransferPosting`/job/OIVL-link, controller + view terisi otomatis dari Shipment.

**Tech Stack:** Laravel, PHPUnit, Blade, Service Layer SAP B1.

**Spec:** `docs/superpowers/specs/2026-11-06-stock-transfer-design.md` bagian "Tambahan: Stock Transfer Return" (binding), plus bagian lama untuk Shipment/Receipt.

## Global Constraints
- Teks UI/pesan/Activity Log baru berbahasa Inggris. Ikuti `.ai/rules/sap-stock.md` dan `backoffice-access.md`.
- Nomor dokumen di SAP `JournalMemo` (maks. 50), `Comments` = Notes (maks. 254; validasi `max:254`; tidak dikirim bila kosong).
- Return hanya untuk Shipment `posted`; `0 < qty <= remaining()` per baris; minimal satu baris; tanpa Draft/pembatalan.
- Hak akses: Owner + SM store pengirim (`from_store_id`); kirim ulang hanya Owner untuk dokumen yang sapnya belum posted.
- Jangan mengubah migrasi yang sudah ada; tambah migrasi baru. Pint (`vendor/bin/pint --dirty --format agent`), commit trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus
1. Receipt dan Return bergantian pada baris yang sama tidak boleh melewati sisa (kunci baris, `remaining()` memakai qty_returned).
2. Return untuk Shipment yang belum terposting di SAP harus menunggu lalu terkirim otomatis; Return dengan sap null (integrasi pernah mati) ikut diantrekan.
3. OIVL dua baris per Return ditautkan, tidak menggandakan stok.
4. SM store penerima tidak boleh membuat/melihat form Return (403); SM store lain 403.
5. `displayStatus()` Completed bila Σ(diterima+dikembalikan) ≥ Σ dikirim dan tidak berubah untuk Shipment lama.

### Task 1: Data, model, service
Files: migrasi baru (`stock_transfer_returns`, `stock_transfer_return_lines`, kolom `qty_returned` di `stock_transfer_shipment_lines`), model `StockTransferReturn`/`StockTransferReturnLine` (HasUuid7, `PostsToSap`, `$hidden sap_doc_entry`), factories, `NumberingConfig::TYPES['stock_transfer_return']`, `StockTransferShipmentLine::remaining()`, `StockTransferShipment::displayStatus()` + relasi `returns()`, `StockTransferService::returnToSender(StockTransferShipment $shipment, array $quantities, User $by, ?string $note = null): StockTransferReturn`. Tests: `tests/Feature/StockTransferReturnServiceTest.php` (contoh 12/10/2; sisa & status; return melewati sisa ditolak; receipt+return bergantian; tanpa baris ditolak; shipment draft ditolak; ledger dua baris benar; angka numbering).
### Task 2: Posting SAP
Extend `SapStockTransferPosting` (union type termasuk `StockTransferReturn`; From=in-transit, To=`from_warehouse`; STID = `from_store_id`; JournalMemo/Comments sesuai Global Constraints; menunggu Shipment posted; `queueWaitingReceipts` juga memproses Return), `PostStockTransferToSap` (kelas dokumen baru), `SapStockMutationService` link (`ref_type='stock_transfer_return'`). Tests di `SapStockTransferPostingTest` atau file baru: payload, menunggu shipment, idempoten, OIVL tertaut tanpa duplikasi, null status ikut antre.
### Task 3: UI, akses, dokumentasi
`StockTransferReturnController` (index/create/store/show/retry) + views `stock/returns/*`, rute `stock-transfer-returns.*`, menu "Stock Transfer Return", `BackofficeAccess::WRITABLE` tambah `'admin.stock-transfer-returns.'`, tombol "Return" di shipments index/show (hanya Owner/SM pengirim bila ada sisa), daftar Return di halaman Shipment, kolom "Returned" di tabel baris Shipment/Receipt form bila relevan, PRD (FR baru berurutan), ERD (md+html via `docs/convert_erd_html.py`), `.ai/rules/sap-stock.md` via `record-rule`. Tests: prefill, hapus baris, ubah qty, over-sisa ditolak, SM penerima 403, SM pengirim boleh, Owner retry, menu caption.
