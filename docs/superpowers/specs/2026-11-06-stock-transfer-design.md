# Stock Transfer Dua Langkah (Shipment & Receipt) — Spec

Tanggal: 2026-11-06. Status: menunggu persetujuan.

## Tujuan
Transfer stok antar store dilakukan dalam dua langkah yang diposting ke SAP B1:

1. **Stock Transfer Shipment** — dari gudang store pengirim ke gudang in-transit milik pengirim.
2. **Stock Transfer Receipt** — dari gudang in-transit pengirim ke gudang store penerima, saat barang tiba.

Contoh: `G.001.MN` mengirim ke `G.002.MN` → Shipment `G.001.MN → G.001.IT`, Receipt `G.001.IT → G.002.MN`.

## Keputusan yang sudah disepakati
| Topik | Keputusan |
|---|---|
| Posting SAP | Shipment dan Receipt masing-masing diposting sebagai Stock Transfer (OWTR) lewat antrean, bukan dicatat lokal saja. |
| Selisih | Receipt boleh parsial dan boleh dibuat beberapa kali; sisa tetap di gudang in-transit; tidak boleh melebihi sisa. |
| Draft | Shipment: Draft → Posted. Draft tanpa efek stok. Setelah Posted tidak bisa dibatalkan dari easyPOS. |
| Syarat | Integrasi SAP aktif; kedua store punya warehouse; warehouse pengirim punya gudang in-transit. Jika tidak, Post ditolak dengan pesan yang menyebut warehouse yang perlu dilengkapi. Tidak ada mode standalone. |
| Data lama | Tabel `stock_transfers` dipertahankan baca-saja (label Legacy); fitur baru memakai tabel dan alur baru. Transfer lama berstatus shipped diselesaikan dulu sebelum rilis. |
| Gudang in-transit | `U_SOL_WHTR` pada warehouse berisi **kode** gudang in-transit pengirim. Gudang in-transit ditandai UDF `U_SOL_ITWH = 'Y'` dan selalu non-retail. |
| Hak akses | Owner semua store; Store Manager hanya store sendiri (Shipment dari store sendiri, Receipt untuk store sendiri). |

## Data
- `warehouses`: tambah `transit_warehouse_code` (nullable, string 30; dari `U_SOL_WHTR`) dan `is_transit` (boolean, default false; dari `U_SOL_ITWH = 'Y'`). Impor OWHS (`SapImportService::importWarehouses`) membaca keduanya. Gudang in-transit tidak ikut pilihan warehouse store (sudah non-retail) tetapi tampil di Saldo Stok.
- `stock_transfer_shipments`: `id`, `company_id`, `number` (nullable sampai Post, numbering `stock_transfer_shipment`), `from_store_id`, `to_store_id`, `from_warehouse_id`, `transit_warehouse_id` (disalin saat Post), `to_warehouse_id`, `status` (`draft`/`posted`), `note`, `posted_at`, `created_by`, kolom status posting SAP (mengikuti pola penjualan: `sap_post_status`, `sap_post_error`, `sap_doc_entry`, `sap_doc_num`).
- `stock_transfer_shipment_lines`: `shipment_id`, `product_id`, `qty` (dikirim), `qty_received` (akumulasi).
- `stock_transfer_receipts`: `id`, `company_id`, `shipment_id`, `number` (numbering `stock_transfer_receipt`), `store_id` (penerima), `note`, `received_at`, `created_by`, kolom status posting SAP yang sama.
- `stock_transfer_receipt_lines`: `receipt_id`, `shipment_line_id`, `qty`.
- Status turunan Shipment untuk daftar: Draft / In Transit (posted, belum ada penerimaan) / Partially Received / Completed (Σ diterima = Σ dikirim). Tidak disimpan; dihitung dari baris.

## Alur
- **Post Shipment** (satu transaksi DB): validasi syarat; qty > 0; stok pengirim di warehouse tidak boleh menjadi negatif (`assertNonNegative` setara untuk buku besar warehouse); catat buku besar warehouse (`WarehouseStockService::record`): pengirim −qty, in-transit +qty (tipe `transfer_out`/`transfer_in`, `ref_type = stock_transfer_shipment`); beri nomor; antrekan job posting SAP `afterCommit`.
- **Receipt**: dibuat oleh store penerima dari Shipment yang posted dan masih punya sisa. Qty per baris ≤ sisa (dikirim − sudah diterima, termasuk Receipt yang antre). Post langsung: in-transit −qty, warehouse penerima +qty (`ref_type = stock_transfer_receipt`); beri nomor; antrekan SAP. Receipt tidak ada Draft dan tidak bisa dibatalkan.
- **Receipt dibuat dari Shipment (prefill):** di menu Receipt, tombol "New Receipt" meminta user memilih satu Shipment yang masih In Transit/Partially Received untuk store penerima (dropdown nomor Shipment, hanya yang `to_store_id` = store user dan masih punya sisa). Setelah dipilih (reload GET `?shipment=<id>`), form terisi otomatis: semua baris Shipment yang masih punya sisa, dengan qty = sisa. Daftar Shipment juga punya tombol "Receive" yang langsung membuka form terisi itu. User tidak memilih produk satu per satu; produk dan Shipment tidak bisa diubah di form. Barang yang tidak diterima: user **menghapus baris** (tombol hapus per baris) atau **mengganti qty** (0 < qty ≤ sisa). Minimal satu baris tersisa; baris yang dihapus tetap berada di gudang in-transit dan bisa diterima pada Receipt berikutnya. Server memvalidasi ulang (baris milik Shipment itu, qty ≤ sisa) dan tidak memercayai data form.
- Semua perhitungan sisa memakai kunci baris (`lockForUpdate`) agar dua Receipt bersamaan tidak melewati sisa.

## Posting SAP
- Job baru mengikuti `PostSaleToSap`/`SapSalePosting`: kelas `SapStockTransferPosting` untuk Shipment dan Receipt. Resource Service Layer `StockTransfers`.
- Header: `FromWarehouse`, `ToWarehouse` (kode warehouse), `U_SOL_FRST = 'Y'`, `U_SOL_TRKY` = UUID dokumen easyPOS, `U_SOL_STID` = UUID store yang membuat dokumen. Baris: `ItemCode` = SKU, `Quantity`, `FromWarehouseCode`, `WarehouseCode`.
- Idempoten: sebelum membuat, cari dokumen dengan `U_SOL_TRKY` yang sama. Simpan DocEntry/DocNum; tampilkan ObjType (67) + DocNum saja.
- Status: `pending` → `posted` atau `failed` (+ pesan). Kirim ulang hanya oleh Owner, lewat tombol di daftar Shipment/Receipt (bukan halaman Integration Monitor penjualan). Receipt tidak diposting sampai Shipment-nya terposting ke SAP; Receipt yang menunggu tetap `pending` dan diantrekan ulang oleh job Shipment begitu terposting.
- **Penautan OIVL**: mutasi OIVL TransType 67 yang `CreatedBy`-nya sama dengan DocEntry dokumen easyPOS ditautkan ke baris ledger yang sudah ada (`ref_type` shipment/receipt), tidak ditambahkan lagi — pola yang sama dengan penjualan (`linkToEasyPosSale`).

## UI dan menu
- Menu **Inventory**: "Stock Transfer Shipment" dan "Stock Transfer Receipt" (menggantikan "Stock Transfers"). Rute `admin.stock-transfer-shipments.*` dan `admin.stock-transfer-receipts.*`; kunci izin Store Manager `WRITABLE` diperbarui sesuai prefiks baru (`BackofficeAccess`), prefiks lama `admin.stock-transfers.` dipertahankan untuk halaman Legacy baca-saja.
- Shipment: daftar (nomor, tanggal, dari, ke, jumlah item, status, status SAP), form (store tujuan, catatan, baris produk), tombol Post. Receipt: daftar, serta form "terima" dari Shipment (kolom dikirim, sudah diterima, sisa, qty diterima).
- Teks UI berbahasa Inggris.

## Pengujian
Feature test: prefill Receipt dari Shipment (semua baris bersisa, qty = sisa), hapus baris, ubah qty, qty melebihi sisa ditolak, Receipt tanpa baris ditolak, Shipment store lain ditolak; syarat Post (integrasi mati, warehouse belum terhubung, U_SOL_WHTR kosong, pesan menyebut warehouse); perpindahan stok dua langkah di buku besar warehouse; stok negatif ditolak; Receipt parsial berulang dan batas sisa; status turunan; hak akses SM per store; payload SAP (header, baris, UDF); idempotensi kirim ulang; penautan OIVL tidak menggandakan ledger; impor OWHS membaca `U_SOL_WHTR`/`U_SOL_ITWH`; halaman Legacy baca-saja.

## Dokumentasi
Perbarui `.ai/rules/sap-stock.md` (transfer kini lewat buku besar warehouse dan diposting ke SAP), PRD, dan ERD.

## Di luar cakupan
Pembatalan Shipment setelah Posted; Receipt ke store selain tujuan Shipment; kelebihan terima (melebihi dikirim); transfer antar-warehouse tanpa store; migrasi otomatis data Legacy.

## Tambahan: Stock Transfer Return (pengembalian dari in-transit ke gudang pengirim)
Tanggal: 2026-11-06. Disetujui lewat diskusi (hak akses dan status ditetapkan pengguna).

**Tujuan:** barang yang tidak diterima (hilang/rusak di jalan) dikembalikan dari gudang in-transit ke gudang pengirim, untuk selanjutnya di-adjust manual. Contoh: kirim 12, terima 10, sisa 2 → Return 2: `G.001.IT → G.001.MN`.

- **Dokumen:** `stock_transfer_returns` (+ `stock_transfer_return_lines`), meniru Receipt: `company_id`, `shipment_id`, `store_id` (= store pengirim), `number` (tipe penomoran baru `stock_transfer_return`, caption "Stock Transfer Return"), `note`, `returned_at`, `created_by`, kolom status posting SAP + trait `PostsToSap`; baris menunjuk `shipment_line_id` + `qty`. Tanpa Draft dan tanpa pembatalan.
- **Sisa:** `stock_transfer_shipment_lines.qty_returned` (baru, default 0). `remaining() = qty − qty_received − qty_returned`. Receipt dan Return sama-sama dibatasi `0 < qty ≤ remaining()` per baris (kunci baris `lockForUpdate`, minimal satu baris).
- **Stok:** in-transit −qty, gudang pengirim (`from_warehouse_id`) +qty (`TYPE_TRANSFER_OUT`/`TYPE_TRANSFER_IN`, `ref_type='stock_transfer_return'`). Hanya untuk Shipment `posted`.
- **Status Shipment:** Completed bila Σ(diterima + dikembalikan) ≥ Σ dikirim; Partially Received bila ada diterima atau dikembalikan tetapi belum habis; In Transit bila keduanya 0. Jumlah dikembalikan tampil di halaman Shipment.
- **Hak akses:** Owner dan Store Manager store **pengirim** (`from_store_id`); store penerima tidak bisa membuat Return.
- **SAP:** Stock Transfer `FromWarehouse` = in-transit, `ToWarehouse` = gudang pengirim; `U_SOL_FRST/TRKY/STID` (STID = store pengirim); `JournalMemo` = nomor Return; `Comments` = Notes (alasan, maks. 254, tidak dikirim bila kosong). Return menunggu sampai Shipment terposting di SAP; diantrekan ulang oleh job Shipment seperti Receipt (status pending atau null). Mutasi OIVL TransType 67 ditautkan ke baris ledger (`ref_type='stock_transfer_return'`), tidak dihitung dua kali. Kirim ulang hanya Owner (status bukan posted).
- **UI:** menu Inventory "Stock Transfer Return"; form terisi otomatis dari Shipment (pemilih Shipment → semua baris bersisa, qty = sisa, hapus baris atau ubah qty, kolom Notes); tombol "Return" di daftar/halaman Shipment untuk pengguna yang berhak bila masih ada sisa; halaman Shipment menampilkan daftar Return. Teks UI Inggris.
- **Di luar cakupan:** pembatalan Return; Stock Adjustment otomatis (tetap manual); Return oleh store penerima.
