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
Baris dengan item/warehouse yang belum ada di easyPOS menghentikan penyalinan (tidak dilewati) agar saldo tidak bolong; kunci unik `(company_id, sap_trans_seq)` + baca ulang 100 TransSeq terakhir mencegah dobel dan baris terlambat.
Saat posting transaksi easyPOS ke SAP dibuat, dokumen easyPOS harus ditandai (mis. UDF referensi) supaya mutasinya tidak tercatat dua kali ketika OIVL disalin kembali.

## Cost item hanya di SAP
Harga mutasi OIVL, `AvgStdPrice` (OITM), dan `StandardAveragePrice` (OITW) tidak diimpor. Kolom lama `products.cost_price`, `warehouse_stocks.avg_price`, dan `warehouse_stock_ledgers.unit_cost` dibiarkan tapi disembunyikan ($hidden) dan tidak ditampilkan di UI/API.
