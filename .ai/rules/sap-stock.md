---
paths:
  - app/Support/Sap/**
  - app/Support/WarehouseStockService.php
  - app/Console/Commands/SapSync*.php
---

# Stok SAP B1

## on_hand berasal dari buku besar mutasi, bukan OITW
`warehouse_stocks.on_hand` = jumlah `warehouse_stock_ledgers`. Mutasi SAP disalin dari log OIVL lewat SQL Query tersimpan `EPOS_STOCK_MUTATION` (`SapStockMutationService`, `sap:sync-stock` tiap menit). OINM ditolak Service Layer (`Table 'OINM' not accessible`, tidak ada di b1s_sqltable.conf), jadi jangan pindah ke OINM tanpa konfigurasi server SAP.
Saldo OITW hanya ditulis ke `sap_on_hand` untuk rekonsiliasi; jangan pernah menimpa `on_hand` dari OITW.
SQLQueries List harus `POST .../List` dengan body `{"ParamList":"lastTransSeq=N"}` (GET ditolak, error 704).
Baris dengan item/warehouse yang belum ada di easyPOS menghentikan penyalinan (tidak dilewati) agar saldo tidak bolong; kunci unik `(company_id, sap_trans_seq)` mencegah dobel. Hemat bandwidth: per menit hanya `TransSeq > cursor`; baca ulang 100 TransSeq terakhir (baris terlambat) hanya tiap 10 menit (`Cache::add` kunci `sap-stock-lookback:*`) dan saat impor/delta master (`lookback: true`). `ensureSqlQuery` hanya saat hash teks SQL berubah (cache `sap-sql-query:*`) atau List 404.
Dokumen yang diposting easyPOS ke SAP diberi UDF header: `U_SOL_FRST = 'Y'`, `U_SOL_TRKY` = UUID dokumen easyPOS (50 char), `U_SOL_STID` = UUID store (50 char). Tersedia (default `U_SOL_FRST = 'N'`) di marketing documents, OIGN/OIGE, OWTR/OWTQ, dan pembayaran (ORCT/OVPM/ARCT/OPDF). Dari respons posting simpan ObjType, DocNum, dan DocEntry. Yang ditampilkan ke user hanya ObjType + DocNum; DocEntry disimpan tapi tidak ditampilkan (kunci teknis: OIVL.CreatedBy dan URL Service Layer; DocNum bisa berulang antar-series). Mutasinya tidak boleh tercatat dua kali saat OIVL disalin kembali.

## Cost item hanya di SAP
Harga mutasi OIVL, `AvgStdPrice` (OITM), dan `StandardAveragePrice` (OITW) tidak diimpor. Kolom lama `products.cost_price`, `warehouse_stocks.avg_price`, dan `warehouse_stock_ledgers.unit_cost` dibiarkan tapi disembunyikan ($hidden) dan tidak ditampilkan di UI/API.

## Posting penjualan POS ke SAP
Setiap penjualan POS yang selesai diposting lewat antrean: A/R Invoice (13) lalu Incoming Payment (24) yang menunjuk DocEntry invoice di `PaymentInvoices` (rekonsiliasi otomatis). Satu Incoming Payment per penjualan (boleh multi metode). Metode dikenali dari kode tipe pembayaran: `CASH` → `CashAccount` = UDF `U_SOL_CASH_ACC` pada warehouse gerai (OWHS, 25 char), `CashSum` = total invoice dikurangi QRIS/EDC (tanpa kembalian); `QRIS`/`EDC` → `PaymentCreditCards` (Credit Card) dengan `CreditAcct` = `U_SOL_QRIS_ACC`/`U_SOL_EDC_ACC` warehouse, `CreditCardNumber` = `1234`, dan `CreditCard` = kode OCRC yang `GLAccount`-nya sama dengan akun itu (dicari lewat `CreditCards?$filter=GLAccount eq ...`, sudah dicek berfungsi). Pembayaran QRIS/EDC pending menahan posting; webhook konfirmasi mengantrekan ulang. Tipe pembayaran lain gagal dengan pesan. Baris invoice: `OcrCode` dan `CogsOcrCode` = `U_SOL_PRFC` warehouse, tidak dikirim bila kosong. CardCode = customer penjualan (default pelanggan retail gerai bila kasir tidak memilih). Numbering series default SAP. Kirim ulang harus idempoten: cari dulu dokumen dengan `U_SOL_TRKY` yang sama sebelum membuat dokumen baru.

## Customer manual dikirim ke SAP
Customer yang dibuat di easyPOS dikirim ke SAP (BusinessPartners, CardType cCustomer) lewat antrean setelah disimpan, dengan `U_SOL_FRST = 'Y'` dan `U_SOL_TRKY` = UUID customer (OCRD tidak punya `U_SOL_STID`). CardCode dibuat SAP dari numbering series BP Customer yang punya UDF `U_SOL_RETAIL = 'Y'` (dibaca dari `SeriesService_GetDocumentSeries`, bukan series default — default SAP pelanggan adalah PARTAI/CD), lalu disimpan sebagai `customers.code`; BP diisi `U_SOL_RETAIL = 'Y'`. GroupCode = kode customer group easyPOS (harus grup yang berasal dari SAP). Posting penjualan menunggu sampai customer punya CardCode. Selama integrasi aktif, form customer hanya menawarkan customer group yang berasal dari SAP. Perubahan customer manual (nama, HP, email, alamat, grup) setelah tersinkron dikirim ke SAP (PATCH BusinessPartners); customer yang berasal dari SAP tetap hanya-baca.

## Query OIVL bisa tertahan sementara
Teramati di SAP pelanggan: `SQLQueries('EPOS_STOCK_MUTATION')/List` kadang tidak merespons beberapa menit sesudah dokumen stok baru diposting (sesi SL ikut tertahan, Logout pun timeout), lalu normal lagi (0,1 detik). Jangan perpanjang timeout; biarkan sinkronisasi per menit mencoba ulang. SAP menulis ulang teks query (mis. `"OIVL"`), jadi bandingkan teks secara ternormalisasi agar tidak PATCH berulang.

## Stok penjualan POS
Pakai `App\Support\SaleStock` (bukan `StockService`) untuk stok penjualan POS: gerai berwarehouse → buku besar warehouse, tanpa warehouse → `stock_ledger` lama. Retur/penyesuaian/transfer sengaja tetap di `stock_ledger` karena belum diposting ke SAP (kalau dipindah, saldo warehouse berselisih dengan SAP). Mutasi OIVL TransType 13 dengan `CreatedBy` = `sales.sap_invoice_doc_entry` ditautkan ke baris ledger `ref_type = sale` (isi kolom sap_*), bukan ditambah. Void ditolak setelah invoice SAP ada.

## Delta master hanya yang berubah
Hanya Items dan BusinessPartners punya `UpdateDate`/`UpdateTime` di Service Layer (master lain menolak properti itu). Penanda waktu diambil dari baris SAP (jam SAP, bukan jam easyPOS) ke `company_details.sap_sync_watermarks`, filter `UpdateDate gt d or (UpdateDate eq d and UpdateTime ge t)`. OITW delta hanya item dengan baris ledger sesudah `warehouse_stocks.sap_checked_at`; penuh bila `sap_stock_reconciled_at` > 24 jam. Langkah mutasi stok yang gagal tidak menggagalkan run; panggil `SapServiceLayer::renewSession()` karena sesi SL yang timeout tetap tertahan.
