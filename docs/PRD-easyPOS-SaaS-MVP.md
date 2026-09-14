# PRD — easyPOS SaaS (MVP) v1.5 — BASELINE

| Field | Value |
|---|---|
| Produk | easyPOS SaaS |
| Dokumen | Product Requirements Document (PRD) |
| Versi | 1.5 (baseline) |
| Status | Disetujui — siap freeze |
| Pemilik | Product |
| Terakhir diperbarui | 2026-09-14 |
| Stack | Backend Laravel, Frontend React JS |

Dokumen ini adalah baseline yang disetujui. Semua keputusan klarifikasi sudah di-fold-in. Satu constraint teknis dikunci pada Bagian 3 dan 8.

---

## 0. Ringkasan keputusan (traceability)

| Kode | Topik | Keputusan |
|---|---|---|
| OQ-1 | Role | Owner > Store Manager > Kasir; boleh merangkap Owner+Store Manager |
| OQ-2 | Prioritas harga | default → tanggal → customer; ditunda ke V2 untuk customer |
| OQ-3 | Mata uang | Satu mata uang aktif per company |
| OQ-4 | Pajak | Setting inclusive/exclusive; PPN per baris, total di dokumen |
| OQ-5 | Retur | Setting batas waktu (default 30 hari) + wajib/tidak wajib struk (default wajib) |
| OQ-6 | Poin | Hanya mengumpulkan; konfigurasi earn rate + level member |
| OQ-7 | Jaringan | Transaksi disimpan di cache, dilanjutkan saat online |
| OQ-8 | Hardware | WebUSB; fallback HTML print |
| OQ-9 | Laporan customer | Seluruh customer teridentifikasi per struk; retur ikut, void tidak |
| OQ-10 | Transfer | Tujuan wajib 1 company; tidak bisa batal setelah terkirim; terima full lalu kirim balik |
| OQ-11 | SKU | Field selain SKU bisa diedit Owner; SKU salah → inactive + SKU baru |
| OQ-12 | Timezone | Asia/Jakarta (GMT+7) |
| OQ-13 | Negative stock | Setting per company |
| OQ-14 | Ekspor | Excel + PDF |
| OQ-15 | SKU generation | Manual atau system-generated dengan format configurable |
| OQ-16 | Multi-store | Mulai 1 gerai, tanpa batas jumlah |
| OQ-R1 | Level member | Silver/Gold/Platinum; pembeda earn rate; tanpa diskon |
| OQ-R2 | Offline payment | Semua metode diproses offline; siap API gateway |
| OQ-R3 | Konflik stok offline | Catat dengan flag review; diselesaikan saat EOD |
| OQ-R4 | Fallback hardware | Perlu fallback |
| OQ-R5 | Default retur | 30 hari; wajib struk = true |
| OQ-R6 | SKU scope | Global per company, tanpa nomor urut per store |
| OQ-R7 | Pembulatan pajak | Jumlahkan dulu, bulatkan 2 desimal di dokumen |
| OQ-R8 | Kop PDF | Nama, alamat, logo, periode |
| OQ-R9 | Pricelist customer | Ditunda ke V2 |
| OQ-R10 | Rata-rata belanja | Customer teridentifikasi per customer; anonim diagregasi terpisah |
| OQ-R11 | Antrean offline | Persisten; muncul kembali saat kasir login ulang |
| OQ-R12 | Lockout | Minimal 1 Owner aktif |
| OQ-R13 | Pricelist scope | Company-level atau store-level; store memilih pricelist yang dipakai |
| OQ-R14 | Antrean lintas perangkat | Bisa lintas perangkat (via sinkron server) |
| OQ-R15 | Default level | Earn: Silver 10000, Gold 8000, Platinum 6000 |
| OQ-R16 | EOD | Kunci tanggal bisnis berikutnya; kas fisik dihitung per pecahan |
| OQ-R17 | Fallback printer | HTML print dialog |
| OQ-R18 | Anonim | Transaksi tanpa customer ID |
| OQ-R19 | Payment provider | XendIt, QRIS |
| OQ-R20 | Ambang level | Silver 0, Gold 100, Platinum 500 |
| OQ-R21 | Network printer | Tidak di MVP; HTML saja |
| OQ-R22 | Cross-device | Via Hold/Parked Sale tersinkron; constraint terkunci |
| OQ-R23 | Negative stock review | Jika allow negative = true → otomatis sah, tanpa review |
| OQ-R24 | EOD lock | Per store |
| OQ-R25 | XendIt QRIS | Dinamis; belum terdaftar; live di V2 |
| OQ-R26 | Earn rate retroaktif | Tidak berlaku surut |

---

## 1. Problem statement

Pemilik UMKM retail yang tumbuh dari 1 ke banyak gerai kehilangan kontrol: penjualan dan stok dicatat terpisah per gerai, harga tidak seragam, transfer stok tanpa jejak, data pelanggan tidak terpusat, dan laporan diolah manual. Koneksi internet gerai tidak stabil sehingga transaksi berisiko hilang atau tertunda, dan kasir yang pindah perangkat kehilangan transaksi yang belum selesai.

Akibatnya:
- Owner buta terhadap performa bisnis dan tidak bisa mengambil keputusan restock/harga berbasis data.
- Store Manager tidak punya alat kontrol stok dan harga.
- Kasir bekerja lambat dan rawan salah hitung.
- Pelanggan menerima pengalaman tidak konsisten dan program loyalitas tidak berjalan.

**Yang dirugikan:** Owner, Store Manager, Kasir, dan pelanggan.
**Kenapa sekarang:** makin banyak gerai, makin mahal merapikan data yang sudah tersebar. Sistem harus dirancang multi-store sejak awal agar tidak migrasi ulang.

---

## 2. Target user + persona

**Segmen:** UMKM retail (minimarket, kelontong, F&B kecil, fashion) dengan 1 gerai dan rencana menambah hingga 2–10 gerai dalam 1 perusahaan.

**Role:** Owner > Store Manager > Kasir. Satu user boleh merangkap Owner + Store Manager pada gerai pertama. Kasir tidak pernah bisa masuk halaman admin. Product master global per company; stok per gerai; pricelist company/store-level dipilih per gerai.

### Persona 1 — Budi, Owner (merangkap Store Manager gerai pertama)
- 38 tahun, memiliki 2 gerai minimarket, masih ikut mengelola langsung.
- Tujuan: kontrol penjualan, stok, dan harga lintas gerai; mengatur kebijakan (pajak, poin/level, retur, negative stock, EOD).
- Frustrasi: laporan dibuat manual, sering beda dengan hitungan kasir; harga beda antar gerai; transfer stok tidak tercatat.
- Perangkat: laptop dan HP; akses admin dari mana saja.

### Persona 2 — Sari, Kasir
- 22 tahun, kerja shift di gerai, non-teknis.
- Tujuan: checkout < 30 detik, cetak struk, buka cash drawer, proses retur/kirim, input member, tetap bisa transaksi saat internet putus, dan bisa lanjut transaksi di perangkat lain.
- Frustrasi: aplikasi lambat dan berlapis, scanner/printer sering gagal, takut salah hitung, transaksi hilang saat pindah perangkat.
- Perangkat: PC/tablet Chromium + scanner + printer thermal.

Store Manager non-owner adalah persona sekunder: mengelola produk, harga, stok, customer, EOD, dan laporan gerainya; tanpa akses setting perusahaan.

---

## 3. Goals dan non-goals

### Goals
- POS menangani penjualan, retur, dan penjualan dengan pengiriman; **semua metode bayar diproses offline** lalu disinkronkan.
- **Hold/Parked Sale**: transaksi berjalan dapat ditahan dan dilanjutkan, termasuk pindah perangkat.
- Product master global per company; harga ber-tanggal efektif; **tiap gerai memilih pricelist**.
- Isolasi data per gerai; siap naik ke multi-tenant tanpa migrasi skema.
- Kontrol akses tegas + anti-lockout (minimal 1 Owner aktif).
- Laporan siap pakai (per gerai, per SKU, terlaris, rata-rata belanja) + ekspor Excel/PDF ber-kop.
- Dukungan hardware: WebUSB dengan fallback HTML print.
- **EOD** per store: hitung kas per pecahan, kunci tanggal bisnis berikutnya, selesaikan review.
- Siap integrasi payment gateway (abstraksi; XendIt QRIS dinamis di V2).

### Non-goals
- Bukan multi-tenant ber-billing otomatis.
- Bukan sistem akuntansi/GL, e-Faktur, atau ERP.
- Bukan aplikasi mobile native.
- Bukan modul pembelian/supplier; bukan gudang lanjutan (batch/expiry/serial).
- **Harga khusus customer bukan MVP** (V2).
- Integrasi payment gateway live bukan MVP (XendIt belum terdaftar).
- Network printer (ESC/POS) tidak di MVP.
- Offline hanya untuk antrean transaksi kasir, bukan operasi admin penuh.
- Local edge server untuk cross-device tanpa sinkron (di luar MVP).

### Locked technical constraints
1. **Cross-device continuation memerlukan minimal satu kali sinkron draft ke server.** Draft yang tidak pernah meninggalkan perangkat asal tidak dapat dilanjutkan di perangkat lain.
2. **Pembayaran non-tunai offline berstatus `pending`** dan tidak dianggap final sampai dikonfirmasi.
3. **SKU immutable; produk global per company.**
4. **PPN dijumlah tanpa pembulatan per baris, dibulatkan 2 desimal di level dokumen.**

**Catatan tegas:** scope MVP besar (POS + master + stok + loyalty + laporan + offline + hardware). Dikerjakan dalam 2 increment (MVP-A dan MVP-B) di bawah satu rilis MVP.

---

## 4. User stories

### Owner
- Sebagai Owner, saya ingin mengatur earn rate dan ambang level (Silver/Gold/Platinum), supaya loyalitas sesuai margin.
- Sebagai Owner, saya ingin menetapkan kebijakan retur dan negative stock sekali saja, supaya konsisten lintas gerai.
- Sebagai Owner, saya ingin memastikan selalu ada 1 Owner aktif, supaya akses tertinggi tidak terkunci.
- Sebagai Owner, saya ingin melihat laporan lintas gerai dan mengekspornya, supaya keputusan bisnis berbasis data.

### Store Manager
- Sebagai Store Manager, saya ingin memilih pricelist yang dipakai gerai saya, supaya harga sesuai kebijakan gerai.
- Sebagai Store Manager, saya ingin menyelesaikan transaksi ber-flag review dan menutup toko dengan hitung kas per pecahan, supaya laporan final benar.
- Sebagai Store Manager, saya ingin transfer stok antar gerai dengan jejak, supaya distribusi terkontrol.
- Sebagai Store Manager, saya ingin mengelola produk, harga, stok, dan customer gerai saya.

### Kasir
- Sebagai Kasir, saya ingin semua metode bayar tetap diproses saat offline, supaya penjualan tidak berhenti.
- Sebagai Kasir, saya ingin menahan transaksi berjalan dan melanjutkannya, termasuk di perangkat lain.
- Sebagai Kasir, saya ingin transaksi menggantung muncul kembali saat login, supaya tidak ada yang hilang atau disalahgunakan.
- Sebagai Kasir, saya ingin cetak struk tetap jalan lewat fallback HTML, supaya antrean lancar.
- Sebagai Kasir, saya ingin tidak bisa membuka halaman admin, supaya batas wewenang jelas.

### Sistem
- Sebagai sistem, saya ingin mencatat semua pergerakan stok di ledger append-only, supaya stok dapat diaudit.
- Sebagai sistem, saya ingin mendeduplikasi transaksi offline via idempotency key, supaya tidak ada transaksi ganda.

---

## 5. Daftar fitur (MVP / V2 / nanti)

### MVP-A (transaksi inti + fondasi)
- A1 Auth & role (min 1 Owner; kasir diblokir dari admin)
- A2 Setting perusahaan (identitas, pajak, timezone Jakarta, negative stock, kebijakan retur, format SKU, earn rate & ambang level)
- A3 Mata uang (1 aktif per company)
- A4 Satuan
- A5 Product group
- A6 Product list (SKU global per company, immutable)
- A7 Pricelist + assignment ke store
- A8 POS penjualan + multi metode bayar + struk + cash drawer
- A9 Stock ledger + saldo per gerai
- A10 Numbering
- A11 Hardware WebUSB + fallback HTML

### MVP-B (menuntaskan scope)
- B1 Retur penjualan
- B2 Penjualan dengan pengiriman
- B3 Stock adjustment
- B4 Stock transfer antar store
- B5 Customer + poin + level member
- B6 Laporan + ekspor Excel/PDF
- B7 Offline queue + sinkronisasi + anti-fraud
- B8 Review queue + Tutup Toko (EOD) + hitung kas per pecahan
- B9 Payment abstraction (siap XendIt QRIS dinamis)
- B10 Hold/Parked Sale (cross-device)

### V2
- Multi-tenant + billing/subscription
- Modul pembelian & supplier
- Integrasi XendIt QRIS dinamis (live)
- Harga khusus customer
- Shift & cash session formal
- Redeem poin
- Promosi/bundling/voucher
- Multi-currency + kurs
- Ekspor laporan terjadwal + notifikasi

### Nanti
- Offline penuh untuk operasi admin
- Mobile native
- Integrasi pajak/e-Faktur dan akuntansi
- Integrasi ERP (SAP B1 dll)
- Manajemen gudang lanjutan (batch/expiry/serial)
- CRM lanjutan, analitik prediktif, API publik
- Local edge server untuk cross-device tanpa sinkron

---

## 6. Functional requirement detail per fitur MVP

### A1 — Auth & Role
- FR-A1-01: Login username + password; tanpa registrasi mandiri.
- FR-A1-02: Role `owner`/`store_manager`/`cashier`; Owner tertinggi; boleh merangkap Owner + Store Manager.
- FR-A1-03: Kasir mengakses route admin → ditolak di server (403).
- FR-A1-04: Store Manager hanya data gerai yang ditugaskan; Owner lintas gerai.
- FR-A1-05: Rate limit login 5/menit/IP; akun nonaktif ditolak.
- FR-A1-06: Minimal 1 Owner aktif; sistem menolak aksi yang menyisakan 0 Owner.
- FR-A1-07: Semua mutasi tercatat di audit log (waktu Asia/Jakarta).

### A2 — Setting perusahaan
- FR-A2-01: Nama badan usaha, alamat, logo, NPWP, telepon, email.
- FR-A2-02: Pajak: `ppn_enabled`, `ppn_rate`, `ppn_inclusive`.
- FR-A2-03: PPN dihitung per baris, dijumlahkan tanpa pembulatan, lalu dibulatkan 2 desimal di level dokumen.
- FR-A2-04: Timezone `Asia/Jakarta` (GMT+7); simpan UTC, tampilkan/report Jakarta.
- FR-A2-05: `allow_negative_stock` (level company).
- FR-A2-06: Kebijakan retur: `return_window_days` default 30; `return_require_receipt` default true.
- FR-A2-07: Format SKU system-generated: prefix, separator, padding, reset period, next number; nomor urut global per company.
- FR-A2-08: Level member default Silver(0)/Gold(100)/Platinum(500); earn rate default 10000/8000/6000 Rp per poin; configurable.
- FR-A2-09: Perubahan earn rate tidak berlaku surut.
- FR-A2-10: Hanya Owner yang dapat mengubah setting perusahaan.
- FR-A2-11: Perubahan tarif pajak tidak mengubah transaksi selesai.

### A3 — Mata uang
- FR-A3-01: CRUD kode ISO 4217, simbol, jumlah desimal.
- FR-A3-02: Hanya satu mata uang aktif per company; semua transaksi memakai itu.
- FR-A3-03: Format tampilan mengikuti simbol + desimal.

### A4 — Satuan
- FR-A4-01: CRUD satuan (kode + nama).
- FR-A4-02: Produk wajib memiliki satu satuan.
- FR-A4-03: Satuan yang masih dipakai produk tidak bisa dihapus.

### A5 — Product group
- FR-A5-01: CRUD grup (kode + nama), global per company.
- FR-A5-02: Grup yang masih punya produk tidak bisa dihapus.

### A6 — Product list (global per company)
- FR-A6-01: Field: SKU (unik per company), nama, grup, satuan, barcode (opsional, unik per company), harga dasar, harga pokok, `track_stock`, status.
- FR-A6-02: SKU tidak dapat diubah. Jika salah SKU → nonaktifkan + buat SKU baru (Owner).
- FR-A6-03: Field selain SKU dapat diedit Owner/Store Manager.
- FR-A6-04: Produk baru otomatis ditambahkan ke pricelist default store dengan harga dasar (tier `min_qty=1`).
- FR-A6-05: SKU dapat diisi manual atau di-generate sistem (nomor urut global per company).
- FR-A6-06: Harga tidak boleh negatif; harga pokok opsional.
- FR-A6-07: Produk global dapat dijual di semua gerai; stok tetap per gerai.
- FR-A6-08: Produk nonaktif tidak muncul di POS; riwayat transaksi tetap utuh.

### A7 — Pricelist & assignment ke store
- FR-A7-01: CRUD pricelist: kode, nama, `effective_from`, `effective_until`, `is_default`, status.
- FR-A7-02: Harga per produk per pricelist dengan tier `min_qty`.
- FR-A7-03: Pricelist memiliki scope `company` (dibagi semua gerai) atau `store` (khusus satu gerai).
- FR-A7-04: Setiap store memilih pricelist yang dipakai (satu default + boleh beberapa untuk tanggal efektif). Assignment dilakukan Owner/Store Manager.
- FR-A7-05: Resolusi harga di POS (tanpa customer di MVP): evaluasi pricelist yang di-assign ke store dari prioritas terendah ke tertinggi: (1) pricelist `is_default` aktif & berlaku, (2) pricelist ber-tanggal efektif aktif & berlaku, (3) fallback `base_price`.
- FR-A7-06: Produk baru → baris harga di pricelist default store (tier `min_qty=1`).
- FR-A7-07: Perubahan harga dasar → sinkron harga tier `min_qty=1` di pricelist default store.
- FR-A7-08: Tidak boleh ada dua pricelist default aktif berlaku bersamaan untuk satu store.
- FR-A7-09: Perubahan harga tidak mengubah transaksi lampau.
- FR-A7-10: Harga khusus customer ditunda ke V2.

### A8 — POS penjualan
- FR-A8-01: Cari produk via scan barcode, ketik SKU/nama, atau pilih dari daftar.
- FR-A8-02: Scanner HID keyboard (auto-fokus + terminator enter).
- FR-A8-03: Keranjang: qty, harga, diskon baris, subtotal, PPN per baris, total.
- FR-A8-04: Pembayaran satu atau lebih metode; hitung kembalian.
- FR-A8-05: Simpan snapshot SKU/nama/harga/pajak per baris.
- FR-A8-06: Nomor transaksi dari konfigurasi penomoran; `business_date` store.
- FR-A8-07: Cetak struk thermal; tombol cetak ulang.
- FR-A8-08: Cash drawer terbuka otomatis saat pembayaran tunai (WebUSB kick; fallback bila gagal).
- FR-A8-09: Input nomor HP/kode customer opsional; member dapat poin sesuai level.
- FR-A8-10: Void dibatasi Store Manager/Owner + alasan; mengembalikan stok; tercatat.
- FR-A8-11: Stok tidak cukup → ditolak, kecuali `allow_negative_stock` aktif.
- FR-A8-12: Cetak gagal tidak menggagalkan transaksi.
- FR-A8-13: Checkout idempoten (`client_uuid`).
- FR-A8-14: Transaksi diblokir jika tanggal bisnis store belum dibuka/ditutup (lihat B8).

### A9 — Stock ledger & saldo
- FR-A9-01: Tipe entri: `opening`, `sale`, `sale_return`, `adjustment`, `transfer_out`, `transfer_in`.
- FR-A9-02: Saldo per (store, product) = akumulasi ledger (boleh di-cache).
- FR-A9-03: Halaman stok: Owner semua gerai, Store Manager gerainya.
- FR-A9-04: Ledger append-only; koreksi via entri baru.
- FR-A9-05: Tiap entri mencantumkan dokumen sumber + aktor.

### A10 — Numbering
- FR-A10-01: Per tipe: prefix, padding, separator, reset period.
- FR-A10-02: Nomor unik per company per tipe; aman terhadap konkurensi.
- FR-A10-03: Reset mengikuti timezone Asia/Jakarta.

### A11 — Hardware: WebUSB + fallback HTML
- FR-A11-01: Printer thermal via WebUSB (ESC/POS), 58mm/80mm.
- FR-A11-02: Fallback cetak = HTML print dialog (template struk HTML); network printer tidak di-MVP.
- FR-A11-03: Cash drawer via kick command printer; jika gagal, buka manual (tidak menggagalkan transaksi).
- FR-A11-04: Barcode scanner HID tanpa konfigurasi khusus.
- FR-A11-05: Halaman diagnosa device: status, test print, test drawer, pilih printer default.
- FR-A11-06: POS optimal di Chromium desktop; browser lain otomatis memakai fallback HTML.

### B1 — Retur penjualan
- FR-B1-01: Merujuk nomor transaksi penjualan asal.
- FR-B1-02: Per baris + qty; qty retur ≤ qty terjual − retur sebelumnya.
- FR-B1-03: `return_require_receipt=true` → nomor struk asal wajib.
- FR-B1-04: `return_window_days=30` → retur di luar rentang ditolak.
- FR-B1-05: Retur menambah stok (`sale_return`) dan mengurangi poin dari transaksi asal.
- FR-B1-06: Dokumen retur bernomor sendiri; metode pengembalian dana dicatat.
- FR-B1-07: Retur ≤ nilai transaksi asal; transaksi void tidak bisa diretur.

### B2 — Penjualan dengan pengiriman
- FR-B2-01: Checkout dapat ditandai "kirim": nama penerima, telepon, alamat, biaya kirim, catatan.
- FR-B2-02: Biaya kirim tercatat terpisah dari subtotal produk.
- FR-B2-03: Status `pending`, `shipped`, `delivered`, `cancelled`.
- FR-B2-04: Alamat disimpan sebagai snapshot transaksi.
- FR-B2-05: Owner/Store Manager dapat memperbarui status & melihat daftar pengiriman.

### B3 — Stock adjustment
- FR-B3-01: Gerai, tanggal, alasan, baris produk (qty sebelum/sesudah/selisih).
- FR-B3-02: Setelah diposting tidak bisa diedit; koreksi via adjustment baru.
- FR-B3-03: Hanya Store Manager/Owner.
- FR-B3-04: Masuk ledger + audit log.

### B4 — Stock transfer antar store
- FR-B4-01: Alur `draft` → `requested` → `shipped` → `received`; `cancelled` hanya sebelum `shipped`.
- FR-B4-02: Saat `shipped`: stok keluar gerai asal (`transfer_out`).
- FR-B4-03: Saat `received`: stok masuk gerai tujuan (`transfer_in`); qty diterima bisa berbeda.
- FR-B4-04: Selisih qty wajib alasan, tercatat.
- FR-B4-05: Transfer hanya antar gerai dalam 1 company.
- FR-B4-06: Tidak ada pembatalan setelah `shipped`; solusi = terima full lalu kirim balik.

### B5 — Customer, poin & level member
- FR-B5-01: CRUD customer: kode customer, nama, nomor HP (unik), email, alamat, status member.
- FR-B5-02: Pencarian/pendaftaran di POS via nomor HP atau kode customer.
- FR-B5-03: Level default Silver(0)/Gold(100)/Platinum(500); earn rate 10000/8000/6000 Rp per poin.
- FR-B5-04: Poin hanya dikumpulkan di MVP (belum ada redeem).
- FR-B5-05: Customer otomatis naik level saat poin melewati ambang; level tidak turun otomatis.
- FR-B5-06: Poin bertambah saat transaksi member selesai → loyalty ledger.
- FR-B5-07: Penyesuaian poin manual (manager) wajib alasan, tercatat.
- FR-B5-08: Nomor HP duplikat ditolak.

### B6 — Laporan
- FR-B6-01: Filter rentang tanggal (Jakarta); Owner filter per gerai, Store Manager terkunci gerainya.
- FR-B6-02: Penjualan per store: omzet, jumlah transaksi, jumlah item, pajak, retur.
- FR-B6-03: Penjualan per SKU: qty, omzet, harga rata-rata.
- FR-B6-04: Produk terlaris: ranking berdasarkan qty dan omzet.
- FR-B6-05: Rata-rata belanja per customer: customer teridentifikasi dihitung per customer; Anonim (transaksi tanpa customer ID) diagregasi terpisah; retur ikut, void dikecualikan.
- FR-B6-06: Ekspor Excel (.xlsx) dan PDF dengan kop: nama, alamat, logo, periode.

### B7 — Offline queue & sinkronisasi
- FR-B7-01: Saat offline, transaksi disimpan di IndexedDB dengan `client_uuid` + waktu perangkat + `sync_status=pending`.
- FR-B7-02: Semua metode bayar dapat diproses offline; non-tunai berstatus `pending`.
- FR-B7-03: Antrean terikat akun kasir; begitu online, antrean diunggah ke server; kasir menarik transaksi pending miliknya.
- FR-B7-04: Login ulang → transaksi menggantung muncul kembali (anti-fraud).
- FR-B7-05: Sinkron otomatis (retry backoff); server menolak duplikat via `client_uuid`.
- FR-B7-06: Indikator online/offline + jumlah transaksi tertunda.
- FR-B7-07: Validasi stok saat sinkron; jika kurang & negative dilarang → flag review.
- FR-B7-08: Nomor final diberikan server saat sinkron; POS menampilkan nomor sementara.
- FR-B7-09: Antrean tidak hilang karena logout; selesai setelah tersinkron atau dibatalkan ber-alasan oleh manager.

### B10 — Hold/Parked Sale (cross-device)
- FR-B10-01: Kasir dapat menahan (hold) transaksi berjalan; keranjang disimpan sebagai draft dengan `client_uuid` + pemilik (cashier + store).
- FR-B10-02: Draft disinkronkan ke server segera saat koneksi tersedia (bukan menunggu finalisasi), agar dapat dilanjutkan di perangkat lain.
- FR-B10-03: Saat kasir login di perangkat lain, sistem menarik draft miliknya dari server dan menampilkannya untuk dilanjutkan.
- FR-B10-04: Constraint: draft hanya bisa dilanjutkan di perangkat lain jika perangkat asal sudah pernah mengunggahnya ke server. Draft yang masih murni lokal hanya dapat dilanjutkan di perangkat asal.
- FR-B10-05: Draft tidak memengaruhi stok, nomor transaksi, atau laporan sampai difinalisasi.
- FR-B10-06: Saat finalisasi, draft menjadi `sales` (stok & nomor diproses); dedupe via `client_uuid` bila perangkat lain juga menyentuh draft yang sama.
- FR-B10-07: Draft dapat dibatalkan ber-alasan; tercatat di audit log.
- FR-B10-08: Draft menampilkan perangkat & waktu terakhir diperbarui.

### B8 — Review queue & Tutup Toko (EOD)
- FR-B8-01: Semua transaksi ber-flag review masuk Review Queue gerai.
- FR-B8-02: Store Manager menyelesaikan review saat EOD: adjustment koreksi, void ber-alasan, atau tandai sah.
- FR-B8-03: Jika `allow_negative_stock=true`, transaksi offline negative-stock otomatis sah dan tidak masuk review queue.
- FR-B8-04: EOD mencatat: gerai, tanggal bisnis, waktu buka/tutup, total penjualan, kas seharusnya vs kas dihitung, selisih, petugas.
- FR-B8-05: Kas fisik wajib dihitung per pecahan mata uang (ref. pecahan IDR); total pecahan = kas dihitung.
- FR-B8-06: EOD mengunci tanggal bisnis berikutnya per store sampai EOD selesai.
- FR-B8-07: EOD tidak dapat diselesaikan selama ada review pending (kecuali override Owner, tercatat).
- FR-B8-08: Setelah EOD, laporan harian final tersedia.

### B9 — Payment abstraction (siap XendIt QRIS dinamis)
- FR-B9-01: `sale_payments`: method, amount, `status` (pending/confirmed/void), provider, provider_ref, confirmed_at.
- FR-B9-02: Transaksi offline non-tunai → status `pending` sampai dikonfirmasi.
- FR-B9-03: Interface `PaymentProvider` + endpoint webhook idempoten; provider pertama XendIt kanal QRIS dinamis (live di V2; belum terdaftar).
- FR-B9-04: Kredensial provider disimpan terenkripsi, hanya Owner.

---

## 7. Sketsa data model (entitas + field kunci)

- **company**: `id`, `name`, `address`, `logo_path`, `npwp`, `phone`, `email`, `ppn_enabled`, `ppn_rate`, `ppn_inclusive`, `base_currency_code`, `timezone`, `allow_negative_stock`, `return_window_days`, `return_require_receipt`, `sku_config` (JSON), `payment_providers` (terenkripsi).
- **stores**: `id`, `company_id`, `code`, `name`, `address`, `pic_name`, `pic_phone`, `ppn_enabled`, `ppn_rate`, `currency_code`, `is_active`.
- **users**: `id`, `company_id`, `store_id` (null untuk Owner), `name`, `username`, `email`, `phone`, `role`, `is_active`, `password`.
- **units**: `id`, `company_id`, `code`, `name`, `is_active`.
- **currencies**: `id`, `company_id`, `code`, `symbol`, `decimal_places`, `is_active`.
- **product_groups**: `id`, `company_id`, `code` (unik per company), `name`, `is_active`.
- **products**: `id`, `company_id`, `product_group_id`, `unit_id`, `sku` (unik per company, immutable), `barcode`, `name`, `description`, `base_price`, `cost_price`, `track_stock`, `is_active`.
- **price_lists**: `id`, `company_id`, `store_id` (nullable; null = company-level), `code`, `name`, `effective_from`, `effective_until`, `is_default`, `is_active`.
- **store_price_lists**: `id`, `store_id`, `price_list_id`, `is_default`.
- **price_list_items**: `id`, `price_list_id`, `product_id`, `min_qty`, `price`.
- **stock_ledger**: `id`, `store_id`, `product_id`, `type`, `qty` (signed), `unit_cost`, `ref_type`, `ref_id`, `note`, `created_by`, `created_at`.
- **stock_adjustments**: `id`, `store_id`, `number`, `date`, `reason`, `status`, `created_by`, `posted_at`.
- **stock_adjustment_lines**: `id`, `stock_adjustment_id`, `product_id`, `qty_before`, `qty_after`, `delta`.
- **stock_transfers**: `id`, `company_id`, `number`, `from_store_id`, `to_store_id`, `status`, `shipped_at`, `received_at`, `note`, `created_by`.
- **stock_transfer_lines**: `id`, `stock_transfer_id`, `product_id`, `qty_requested`, `qty_shipped`, `qty_received`, `variance_reason`.
- **customers**: `id`, `company_id`, `code`, `name`, `phone` (unique), `email`, `address`, `is_member`, `member_level_id`, `points_balance`, `joined_at`.
- **member_levels**: `id`, `company_id`, `name` (Silver/Gold/Platinum), `min_points`, `earn_amount_per_point`, `order`, `is_active`.
- **loyalty_config**: `id`, `company_id`, `rounding`, `min_spend`, `expiry_days`, `is_active`.
- **loyalty_ledger**: `id`, `customer_id`, `type` (earn/adjust/expire), `points`, `balance_after`, `ref_type`, `ref_id`, `note`, `created_by`, `created_at`.
- **sale_drafts**: `id`, `company_id`, `store_id`, `client_uuid` (unique), `cashier_id`, `device_label`, `payload` (JSON keranjang), `status` (held/finalized/cancelled), `updated_at`, `synced_at`.
- **sales**: `id`, `company_id`, `store_id`, `number`, `client_uuid` (unique), `origin` (online/offline), `sync_status` (pending/synced), `business_date`, `type`, `customer_id` (nullable), `cashier_id`, `status` (completed/void), `stock_review_status` (none/pending/resolved), `review_reason`, `resolved_by`, `resolved_at`, `subtotal`, `discount_total`, `tax_total`, `shipping_cost`, `total`, `paid_total`, `change_total`, `currency_code`, `sold_at`, `voided_at`, `void_reason`.
- **sale_lines**: `id`, `sale_id`, `product_id`, `sku_snapshot`, `name_snapshot`, `qty`, `unit_price`, `discount`, `tax_amount`, `line_total`.
- **sale_payments**: `id`, `sale_id`, `payment_type_id`, `amount`, `status` (pending/confirmed/void), `provider`, `provider_ref`, `confirmed_at`.
- **sales_returns**: `id`, `number`, `original_sale_id`, `store_id`, `customer_id`, `reason`, `total`, `refund_method`, `receipt_verified`, `created_by`, `created_at`.
- **sales_return_lines**: `id`, `sales_return_id`, `sale_line_id`, `product_id`, `qty`, `unit_price`, `line_total`.
- **deliveries**: `id`, `sale_id`, `recipient_name`, `phone`, `address`, `shipping_cost`, `status`, `note`, `shipped_at`, `delivered_at`.
- **store_closures**: `id`, `store_id`, `business_date`, `opened_at`, `closed_at`, `sales_total`, `cash_expected`, `cash_counted`, `cash_variance`, `closed_by`, `note`.
- **store_closure_denominations**: `id`, `store_closure_id`, `denomination_value`, `count`, `subtotal`.
- **payment_types**: `id`, `company_id`, `code`, `count_type`, `order_number`, `is_offline_allowed`, `is_active` (+ pivot stores).
- **numbering_configs**: `id`, `company_id`, `transaction_type`, `prefix`, `padding`, `separator`, `reset_period`, `last_period`, `last_number`.
- **activity_logs**: `id`, `actor_id`, `actor_name`, `action`, `subject_type`, `subject_id`, `description`, `properties`, `created_at`.

---

## 8. Edge case dan failure state

### Offline & sinkronisasi
- Offline → transaksi disimpan di IndexedDB (`client_uuid`, `sync_status=pending`); semua metode bayar dicatat.
- Koneksi kembali → unggah otomatis; dedupe via `client_uuid`.
- Stok kurang saat sinkron: `allow_negative_stock=true` → otomatis sah; `false` → flag review.
- Non-tunai offline → `pending` sampai konfirmasi.
- Jam perangkat salah → `sold_at` final dari server.

### Hold/Parked Sale (cross-device)
- Draft disinkron saat ada koneksi; muncul di perangkat lain setelah tersinkron.
- Draft yang belum pernah tersinkron tidak muncul di perangkat lain; perangkat asal harus online dulu.
- Dua perangkat memfinalisasi draft yang sama → dedupe `client_uuid`; hanya satu menang; konflik dicatat.
- Draft lama menggantung → ditampilkan dengan penanda waktu; dapat dibatalkan manager.

### EOD
- Review pending → EOD diblokir (kecuali override Owner).
- Kas dihitung per pecahan; selisih dicatat, tidak mengubah transaksi.
- Tanggal bisnis berikutnya terkunci sampai EOD selesai.

### Hardware
- WebUSB gagal/tidak didukung → fallback HTML print.
- Kertas habis → transaksi tetap tersimpan; cetak ulang.
- Drawer gagal → buka manual; tidak menggagalkan transaksi.

### Stok
- Penjualan melebihi stok → ditolak, kecuali `allow_negative_stock`.
- Konkurensi kasir → lock transaksi DB.
- Transfer `shipped` → tidak bisa batal; terima full lalu kirim balik.
- Produk nonaktif dengan stok → tetap tampil di stok, tidak bisa dijual.

### Harga / pajak
- Dua pricelist default aktif beririsan → ditolak saat simpan.
- Pricelist kedaluwarsa → jatuh ke `base_price`.
- PPN per baris dijumlah lalu dibulatkan 2 desimal di dokumen; konsisten antara struk, laporan, dan pajak.

### Retur
- Qty retur > sisa → ditolak.
- Tanpa struk saat wajib → ditolak.
- Lewat 30 hari → ditolak.
- Transaksi void → tidak bisa diretur.

### Customer / loyalty
- Nomor HP duplikat → ditolak.
- Poin pecahan → pembulatan dari konfigurasi.
- Naik level otomatis; tidak turun otomatis.

### Role
- Upaya menonaktifkan/menghapus Owner terakhir → ditolak.

### Laporan
- Periode memakai Asia/Jakarta.
- Void dikecualikan.
- Anonim (tanpa customer ID) diagregasi terpisah.

---

## 9. Success metrics

### Adopsi pilot
- Merchant go-live dan ≥ 300 transaksi dalam 30 hari.
- ≥ 80% transaksi harian tercatat di sistem.
- 100% kasir dapat menyelesaikan transaksi tanpa pendampingan setelah training ≤ 1 jam.

### Efisiensi & keandalan
- Checkout rata-rata < 30 detik untuk 1–3 item.
- Keberhasilan cetak struk pertama ≥ 99% (termasuk fallback HTML).
- Sinkronisasi transaksi offline berhasil ≥ 99% tanpa duplikat.
- Nol kejadian double charge.
- 100% transaksi menggantung muncul kembali saat kasir login.
- Draft yang sudah tersinkron dapat dilanjutkan di perangkat lain ≥ 99%.

### Kontrol & kepercayaan
- 100% perubahan harga, adjustment, dan transfer tercatat di audit log.
- 0 akses kasir ke halaman admin (uji otorisasi).
- 100% transaksi review diselesaikan saat EOD (kecuali negative stock diizinkan).
- Kas fisik terhitung per pecahan.
- Laporan 30 hari tampil < 5 detik; ekspor Excel/PDF berhasil.
- Akurasi stok ≤ 1% selisih saat opname pertama.

### Kesiapan skala
- Menambah gerai ke-2 tidak memerlukan perubahan skema/migrasi data.
- Isolasi data antar gerai terverifikasi.

---

## 10. Open questions

Tidak ada OQ yang memblokir. Satu constraint dikunci sebagai keputusan teknis:

- **Locked:** Cross-device Hold/Parked Sale hanya berlaku untuk draft yang sudah tersinkron ke server. Jika dibutuhkan melanjutkan transaksi tanpa perangkat asal pernah online, satu-satunya jalan adalah local edge server di gerai (LAN server) — di luar MVP dan masuk kategori "Nanti".

---

## Lampiran — Catatan implementasi (non-normatif)

- Backend Laravel, frontend React JS.
- Otorisasi harus ditegakkan di server, bukan hanya UI.
- Semua tabel inti membawa `company_id`/`store_id` untuk kesiapan multi-tenant.
- Simpan waktu dalam UTC, tampilkan dalam Asia/Jakarta.
- Idempotency key (`client_uuid`) wajib untuk semua transaksi yang berasal dari POS.
