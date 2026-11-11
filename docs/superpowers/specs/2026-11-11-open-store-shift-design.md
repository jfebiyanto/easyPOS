# Open Store dan Shift Kasir — Spesifikasi

Tanggal: 2026-11-11. Status: menunggu review.

## Tujuan
Menandai bahwa toko pada business date tertentu sudah dibuka dan siap bertransaksi (lawan dari End of Day), serta mencatat shift tiap kasir. Hanya toko yang sudah dibuka yang perlu di-EOD, sehingga toko yang tidak beroperasi (mis. renovasi) tidak dianggap "belum ditutup".

## Keputusan yang sudah disepakati
1. Open Store dilakukan **kasir di POS** saat login; timestamp pembukaan sekaligus awal shift kasir itu.
2. Logout mengakhiri **shift** kasir, bukan menutup toko. Toko hanya ditutup lewat **EOD**.
3. Tidak ada status "Store Closed" dan tidak ada Reopen Store.
4. Owner dan Store Manager di Admin Console tidak melakukan Open/Close dan tidak terpengaruh status toko; EOD hanya berdampak pada transaksi di POS.
5. EOD **tidak bisa** dijalankan selama masih ada shift aktif; Owner/Store Manager dapat **Force End Shift**.
6. Logout menampilkan konfirmasi "Akhiri shift Anda?" berisi jam mulai dan jumlah transaksi shift tersebut.
7. Penjualan offline tetap diterima walau toko belum Open atau sudah EOD, dan dicatat ke business date asalnya.
8. Data lama di-backfill agar EOD historis tidak terblokir.
9. **Modal awal (opening float)**: saat memulai shift (Open Store maupun Start Shift), kasir wajib menghitung uang modal kembalian yang diterimanya sesuai denominasi. Modal ini ikut dihitung pada EOD: kas yang harus disetor = modal awal + penjualan tunai (mis. modal 200 ribu + penjualan 700 ribu = 900 ribu).

## Model data
**Status toko** per (store, business date), satu baris `store_closures`:

| Status | Penanda |
|---|---|
| Not opened | tidak ada baris |
| Open | `opened_at` dan `opened_by` terisi, `closed_at` kosong |
| EOD Done | `closed_at` terisi (kolom EOD yang sudah ada) |

Perubahan `store_closures`: tambah `opened_by` (FK users, nullable). `opened_at` sudah ada.

**Tabel baru `cashier_shifts`**: `id` (uuid7), `company_id`, `store_id`, `user_id`, `business_date`, `started_at`, `ended_at` (nullable), `ended_reason` (`logout` | `expired` | `closed_by_admin`, nullable), `opened_store` (boolean), timestamps. Indeks: `(store_id, business_date)`, `(user_id, ended_at)`.

Tambahan modal awal: `cashier_shifts.opening_float`, `opening_float_expected`, `opening_float_variance` (decimal 15,2, default 0), dan `opening_float_remark` (text, nullable). Rinciannya di tabel baru `cashier_shift_denominations` (`cashier_shift_id`, `denomination_value`, `count`, `subtotal`), mengikuti pola `store_closure_denominations`.

Shift sengaja terpisah dari `PosSession` (per perangkat; berganti saat takeover).

### Modal awal dan kas EOD
- Aturan hitung mengikuti Count Type yang sudah ada (`requiresDenominationCount`): bila ada tipe tunai aktif gerai ber-Count Type Detail, kasir menghitung per denominasi (mata uang dasar, denominasi aktif); bila Summary, kasir cukup mengisi satu nominal.
- Modal bukan penjualan: tidak masuk `sales_total`, laporan, maupun dashboard penjualan.
- `cash_expected` EOD = jumlah `opening_float` semua shift pada tanggal itu + penjualan tunai (setelah kembalian). Modal ditampilkan sebagai baris terpisah "Opening float" di halaman EOD, dan hitungan kas EOD per denominasi mencakup modal ini.
- Modal tidak berubah saat shift di-force end; tetap dihitung di EOD.
- Konsep: satu kasir memiliki laci uangnya sendiri dan tidak dipakai bersama, sehingga pertanggungjawaban kas jelas per kasir/shift. Karena itu modal dijumlahkan per shift pada EOD, dan tiap shift menyimpan modal sendiri.

### Modal standar (SOP) per toko
- Kolom baru `stores.opening_float` (decimal 15,2, default 0), diatur di modul Toko (form Store). 0 diperbolehkan (toko tanpa modal).
- Saat memulai shift, layar menampilkan modal standar sebagai acuan; kasir tetap menghitung dan mengisi jumlah yang benar-benar diterima.
- Shift menyimpan `opening_float_expected` (salinan modal standar saat itu) dan `opening_float_variance` (`opening_float` − expected) agar perubahan SOP di kemudian hari tidak mengubah riwayat.
- Bila hitungan kasir berbeda dari modal standar, shift tetap bisa dimulai, tetapi kasir **wajib mengisi remark** atas selisih (`cashier_shifts.opening_float_remark`, wajib bila variance ≠ 0, diperiksa di server). Layar menampilkan peringatan selisih.
- Selisih dan remark dibawa sampai EOD: halaman EOD menampilkan per shift modal standar, modal dihitung, variance, remark, dan kasirnya; ringkasan selisih modal tercatat pada penutupan EOD dan activity log (`shift.started` memuat variance dan remark). Variance modal tidak mengubah perhitungan kas diharapkan (tetap memakai modal yang dihitung kasir), dan dipisahkan dari variance kas EOD.
- Penentuan modal standar lewat satu method (mis. `Store::openingFloatStandard()`), supaya nanti bisa diganti dengan pengaturan per POS terminal tanpa mengubah alur shift.

### Di luar cakupan (rencana jangka panjang)
Beberapa POS terminal dalam satu toko dengan modal standar per terminal belum ada di easyPOS dan **tidak dikerjakan sekarang**. Desain saat ini tidak menghalanginya: shift per kasir dan modal standar diambil lewat satu titik, sehingga penambahan entitas terminal (dan `pos_terminal_id` pada shift) nanti bersifat tambahan.

## Perilaku

### POS
- Gerbang di depan layar penjualan, berdasarkan status toko pada `postingDate`:
  - Not opened → layar **Open Store**: kasir menghitung modal awal, lalu membuat/mengisi `store_closures.opened_*` dan shift (`opened_store = true`, `opening_float`) dalam satu transaksi.
  - Open dan kasir belum punya shift aktif → layar **Start Shift** (menampilkan dibuka oleh siapa dan kapan) dengan penghitungan modal awal yang sama.
  - Open dan shift aktif → langsung ke POS.
  - EOD Done → diblokir seperti sekarang.
- Tanggal yang dibuka selalu `StoreClosure::postingDate` (hari ini, atau tanggal lama yang belum di-EOD, dengan konfirmasi backdate yang sudah ada).
- `storeSale` menolak transaksi online bila toko tidak Open atau kasir tanpa shift aktif. Origin `offline` dikecualikan.
- Logout: dialog konfirmasi akhiri shift → shift ditutup (`logout`); toko tetap Open. Login ulang di hari yang sama hanya Start Shift.
- Heartbeat mati (`PosSession::STALE_AFTER_SECONDS`) → shift ditutup `expired`.

### Admin Console
- Tidak ada Open/Close di admin; Owner/SM tidak dibatasi status toko.
- EOD hanya untuk toko Open (`opened_at` terisi). Toko yang tidak pernah dibuka tidak dihitung "belum di-EOD".
- EOD diblokir bila ada shift aktif; halaman menampilkan daftar shift aktif dan tombol **Force End Shift** (Owner/SM; menutup dengan `closed_by_admin`).
- Halaman EOD menampilkan pembuka toko (siapa, kapan) dan daftar shift hari itu.
- Reopen EOD mengembalikan status ke Open (aturan "hanya hari ini" tetap).
- Status bar/dashboard: Belum dibuka, Open (jumlah shift aktif), Belum EOD.

### Aturan akses
Rute baru ikut prefiks `admin.eod.` (sudah di `WRITABLE` BackofficeAccess), jadi SM otomatis boleh; Force End Shift dibatasi ke gerai SM lewat `ScopesToStore`.

## Perubahan kode
- Migration: `opened_by`, `cashier_shifts`, backfill (`opened_at` dari transaksi pertama pada tanggal yang punya transaksi; shift lama tidak dibuat ulang).
- Model `CashierShift`; `StoreClosure::isOpen()`, `openFor()`; `unclosedPriorDate` disesuaikan.
- `store_business_status()` (app/Support/navitem.php): tambah `opened`, `opened_by`, `opened_at`, `shift`.
- `PosController`/`PosSessionController`: `POST /pos/store/open`, `POST /pos/shift/start`, `POST /pos/shift/end`; guard di `storeSale` dan `bootstrap`.
- `LoginController::logout` dan heartbeat: mengakhiri shift.
- `StoreClosureController`: guard EOD, daftar shift, force end.
- React POS: layar gerbang, dialog logout, jam shift di header; `layout.test.js` disesuaikan (setiap bagian grid `.pos` tetap item grid, lihat `.ai/rules/css.md`).
- View EOD, layout admin (status bar), dashboard.
- Activity log: `store.opened`, `shift.started`, `shift.ended`, `shift.force_ended`.

## Pengujian
- Open Store: hanya sekali per tanggal; kasir kedua Start Shift; tanggal = postingDate.
- Variance modal: shift ditolak bila variance ≠ 0 tanpa remark; remark dan variance tampil di halaman EOD per shift dan di activity log.
- Modal standar toko: tersimpan di form Toko; shift menyimpan expected dan variance; mengubah SOP tidak mengubah shift lama.
- Modal awal: wajib diisi (per denominasi atau nominal sesuai Count Type); subtotal denominasi dihitung server; `cash_expected` EOD = modal + penjualan tunai (contoh 200 ribu + 700 ribu = 900 ribu); modal tidak masuk `sales_total`.
- `storeSale` ditolak saat toko Not opened/EOD Done/tanpa shift; offline tetap diterima.
- Logout mengakhiri shift tanpa menutup toko; heartbeat basi → `expired`.
- EOD ditolak untuk toko Not opened dan saat ada shift aktif; Force End Shift lalu EOD berhasil; SM hanya gerainya.
- Reopen EOD → Open. Backfill migration.
- Update `EodTest`, `PosTest`, `PosSessionTest`, `BackofficeAccessTest`; baru `CashierShiftTest`.
