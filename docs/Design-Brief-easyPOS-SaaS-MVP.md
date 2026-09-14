# Design Brief — easyPOS SaaS (MVP)

| Field | Value |
|---|---|
| Produk | easyPOS SaaS |
| Dokumen | Design Brief (pre-code) |
| Versi | 1.0 |
| Berdasarkan | PRD v1.5 baseline (`docs/PRD-easyPOS-SaaS-MVP.md`) |
| Surface | Admin (backoffice) + POS (kasir) |
| Stack | Laravel (backend), React JS (frontend) |
| Status | Untuk review sebelum implementasi UI |

Dokumen ini adalah kontrak desain sebelum satu baris kode UI ditulis. Setiap keputusan visual/UX di bawah bersifat tegas; alasan disertakan.

---

## 0. Konteks desain & keputusan besar

1. **Fondasi komponen: Material 3 (MD3) dipertahankan sebagai lapisan perilaku/komponen**, bukan sebagai identitas visual. Alasan: codebase sudah memakai `md3.css`/`md3-components.css`; MD3 memberi aksesibilitas, state, dan pola komponen yang matang. Menggantinya menambah risiko tanpa nilai. Identitas visual (warna, tipografi, density) **diganti** agar tidak terlihat generik.
2. **Dua postur dalam satu sistem**: POS "Reach" (target besar, kontras tinggi, chrome minimal) dan Admin "Scan" (tabel padat, informasi rapat). Token sama; density berbeda.
3. **Angka adalah warga kelas satu**: semua nominal memakai tabular figures dan font khusus; status uang (`pending`, `review`, `offline`) tidak pernah disembunyikan.
4. **POS tidak didukung di ponsel** (<768px). Alasan: integritas transaksi + printer/scanner/drawer; ponsel memberi ilusi dukungan yang berbahaya. Admin tetap didukung di ponsel untuk baca/approve ringan.

---

## 1. Design principles (3 aturan wajib)

### P1 — Kecepatan di atas kelengkapan (POS)
Setiap aksi yang dibutuhkan saat melayani pelanggan harus bisa dicapai dalam **≤1 interaksi dari state saat ini**, dan alur utama (scan → bayar → cetak) harus bisa dijalankan **tanpa mouse**. Alasan: kasir bekerja dengan scanner dan keyboard; setiap klik tambahan menambah antrean dan risiko salah. Konsekuensi: POS tidak menampilkan menu yang tidak relevan saat transaksi; pengaturan device/hold/return diletakkan di rail, bukan di jalur utama.

### P2 — Angka tidak boleh menipu
Nominal, pajak, stok, dan poin harus selalu menampilkan **presisi, satuan, dan provenance**. Nilai yang belum final wajib diberi penanda eksplisit (`pending`, `review`, `offline`, `snapshot`). Alasan: ini produk uang; kebingungan angka = kerugian finansial dan hilang kepercayaan. Konsekuensi: dilarang menampilkan total tanpa konteks status; pembayaran non-tunai offline tidak boleh tampak seperti "lunas".

### P3 — Satu sistem, dua postur
Kosakata, ikon, status, dan token harus identik di Admin dan POS; yang berbeda hanya **density dan ukuran target**. Alasan: user yang sama (Owner/Manager) berpindah surface; konsistensi bahasa mengurangi learning cost, sedangkan perbedaan density melayani konteks yang berbeda. Konsekuensi: satu `<StatusChip>` yang sama dipakai di tabel admin dan di header POS; ukurannya yang berubah, bukan maknanya.

---

## 2. Visual direction

### Mood
"Tenang dan tepercaya di belakang layar, cepat dan tidak bisa salah di depan pelanggan." Back-office yang serius (banyak angka) bertemu meja kasir yang harus tahan tekanan (antrean, uang fisik, printer).

### Referensi
- **Linear / Stripe Dashboard** — kepadatan informasi admin, tipografi rapi, tabel yang tidak melelahkan.
- **Square POS / Toast POS** — ergonomi meja kasir, tombol besar, alur bayar ringkas.
- **Material 3** — perilaku komponen, state, dan aksesibilitas (bukan warna).
- **Struk termal** — estetika angka monospace, kontras tinggi, tanpa hiasan.

### Yang dihindari
- Warna ungu Material default dan gradien "SaaS startup".
- Glassmorphism, blur dekoratif, ilustrasi besar di jalur transaksi.
- Teks abu-abu tipis di atas abu-abu (gagal kontras).
- Status hanya dibedakan lewat warna.
- Modal bertumpuk (modal di atas modal) di POS.
- Animasi yang menghambat input (durasi > 200ms di POS).
- Menyembunyikan status `pending`/`offline` demi tampilan bersih.

### Arah warna
Basis netral **hangat** (off-white, bukan abu biru) agar meja kasir terasa "kertas/struk", dengan aksen **teal gelap** sebagai warna kepercayaan/finansial (bukan biru korporat, bukan ungu), dan **amber** khusus untuk perhatian/pending. Merah hanya untuk destruktif/error, hijau hanya untuk sukses/final.

---

## 3. Design tokens

### 3.1 Palet warna (light theme — default)

**Brand / Primary — "Ledger Teal"**
| Token | Hex | Penggunaan |
|---|---|---|
| `--color-primary` | `#0F6E63` | Aksi utama, link, focus ring |
| `--color-primary-hover` | `#0B5A51` | Hover/pressed |
| `--color-on-primary` | `#FFFFFF` | Teks di atas primary |
| `--color-primary-container` | `#B8E3DA` | Background chip/selected |
| `--color-on-primary-container` | `#04332D` | Teks di primary-container |

**Aksen / Attention — "Signal Amber"**
| Token | Hex | Penggunaan |
|---|---|---|
| `--color-accent` | `#B45309` | Pending, butuh perhatian |
| `--color-accent-container` | `#FCE7C8` | Background badge pending |
| `--color-on-accent-container` | `#5A2B00` | Teks badge pending |

**Semantic**
| Token | Hex | Penggunaan |
|---|---|---|
| `--color-success` | `#137A4B` | Sukses, final, lunas |
| `--color-success-container` | `#C9E9D7` | Background sukses |
| `--color-warning` | `#B45309` | Peringatan |
| `--color-danger` | `#B3261E` | Error, destruktif, void |
| `--color-danger-container` | `#F9DEDC` | Background error |
| `--color-info` | `#0B6BCB` | Informasi netral |
| `--color-info-container` | `#D6E7FB` | Background info |

**Netral (hangat)**
| Token | Hex | Penggunaan |
|---|---|---|
| `--color-bg` | `#FBFBFA` | Latar aplikasi |
| `--color-surface` | `#FFFFFF` | Kartu, tabel, panel |
| `--color-surface-2` | `#F1F1EE` | Header tabel, section |
| `--color-outline` | `#D6D6D0` | Garis, border input |
| `--color-outline-strong` | `#9AA1A9` | Border fokus/hover |
| `--color-text` | `#1B1F23` | Teks utama |
| `--color-text-muted` | `#5A6472` | Teks sekunder |
| `--color-text-disabled` | `#9AA1A9` | Disabled |

**POS high-contrast (mode kasir)**
| Token | Hex | Penggunaan |
|---|---|---|
| `--pos-bg` | `#FFFFFF` | Latar POS |
| `--pos-text` | `#0B0F14` | Teks POS |
| `--pos-border` | `#0B0F14` | Border tegas (bukan abu) |
| `--pos-primary` | `#0F6E63` | Tombol bayar |

**Dark theme**: ditunda ke V2. Alasan: POS butuh kontras tinggi di bawah lampu gerai; dark theme menambah biaya QA dan risiko kontras. Admin dark theme menyusul bila diminta.

### 3.2 Tipografi

**Keputusan font: `Inter` (UI) + `IBM Plex Mono` (angka/kode).**

- **Inter** untuk seluruh teks UI. Alasan: x-height tinggi sehingga terbaca di 12–14px (tabel admin padat), mendukung `tnum` (tabular numerals) untuk kolom angka, variabel (payload kecil), dan cakupan Latin yang baik.
- **IBM Plex Mono** untuk **semua nominal uang, qty, SKU, nomor transaksi, dan kode**. Alasan: (1) monospace mencegah "digit shift" saat kasir membaca angka panjang; (2) membedakan tegas 0/O dan 1/l; (3) memberi identitas "struk" yang sesuai produk; (4) menandai data yang di-generate sistem vs teks manusia.
- Bukan Roboto (generik, sudah dipakai default MD3), bukan Space Grotesk/Sora untuk body (terlalu display untuk data padat).

**Skala (base 16, rem = 16px)**
| Token | Size / Line-height | Weight | Penggunaan |
|---|---|---|---|
| `--font-display-xl` | 40 / 48 | 700 (mono) | Total pembayaran POS |
| `--font-display-lg` | 32 / 40 | 700 | Angka KPI dashboard |
| `--font-headline` | 24 / 32 | 600 | Judul halaman |
| `--font-title` | 20 / 28 | 600 | Judul section/card |
| `--font-body-lg` | 16 / 24 | 400 | Body POS |
| `--font-body` | 14 / 20 | 400 | Body admin (default) |
| `--font-label` | 13 / 18 | 500 | Label form, header tabel |
| `--font-caption` | 12 / 16 | 400 | Helper text, timestamp |

Aturan angka: semua elemen uang memakai `font-feature-settings: "tnum" 1, "zero" 1;` dan rata kanan pada kolom.

### 3.3 Spacing
Skala 4px: `2, 4, 8, 12, 16, 20, 24, 32, 40, 48, 64`.
- `--space-1: 4px`, `--space-2: 8px`, `--space-3: 12px`, `--space-4: 16px`, `--space-5: 20px`, `--space-6: 24px`, `--space-8: 32px`, `--space-10: 40px`, `--space-12: 48px`, `--space-16: 64px`.
- Padding kartu admin: 16–24. Padding POS panel: 20–24. Gap grid tabel: 8.

### 3.4 Radius
| Token | Value | Penggunaan |
|---|---|---|
| `--radius-xs` | 4px | Badge kecil, input kecil |
| `--radius-sm` | 6px | Input, tombol compact |
| `--radius-md` | 8px | Tombol, kartu kecil |
| `--radius-lg` | 12px | Kartu, panel, modal |
| `--radius-xl` | 16px | Bottom sheet |
| `--radius-pill` | 999px | Status chip, filter |

### 3.5 Shadow
| Token | Value |
|---|---|
| `--shadow-1` | `0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.10)` |
| `--shadow-2` | `0 4px 8px -2px rgba(16,24,40,.10), 0 2px 4px -2px rgba(16,24,40,.06)` |
| `--shadow-3` | `0 12px 24px -6px rgba(16,24,40,.14), 0 4px 8px -4px rgba(16,24,40,.08)` |
| `--focus-ring` | `0 0 0 3px rgba(15,110,99,.35)` |

Aturan: POS memakai `--shadow-2` untuk action bar; dilarang shadow di dalam tabel.

### 3.6 Motion
| Token | Value | Penggunaan |
|---|---|---|
| `--motion-fast` | 120ms | Hover, toggle |
| `--motion-base` | 200ms | Panel, drawer, toast |
| `--motion-slow` | 320ms | Modal, bottom sheet |
| `--ease-standard` | `cubic-bezier(0.2, 0, 0, 1)` | Umum |
| `--ease-emphasized` | `cubic-bezier(0.05, 0.7, 0.1, 1)` | Elemen masuk |

Wajib hormati `prefers-reduced-motion: reduce` → durasi 0.

### 3.7 Z-index
`content:0, sticky-header:10, action-bar:20, drawer:40, modal:50, toast:60, tooltip:70`.

---

## 4. Screen inventory

### Admin (desktop-first)
| # | Screen | Tujuan | Role |
|---|---|---|---|
| A-01 | Login | Autentikasi | Semua |
| A-02 | Dashboard | Ringkasan penjualan/stok/karyawan per store & lintas store | Owner, Manager |
| A-03 | Company Settings | Identitas, pajak, timezone, negative stock, kebijakan retur, format SKU | Owner |
| A-04 | Loyalty Settings | Level (Silver/Gold/Platinum), ambang, earn rate | Owner |
| A-05 | Payment Providers | Kredensial provider (XendIt), status | Owner |
| A-06 | Stores list | Daftar gerai, status, akses | Owner |
| A-07 | Store form | Buat/edit gerai + assign pricelist | Owner |
| A-08 | Users list | Daftar user, role, store, status | Owner |
| A-09 | User form | Buat/edit user + reset password | Owner |
| A-10 | Units | Master satuan | Owner, Manager |
| A-11 | Currencies | Master mata uang + pecahan | Owner |
| A-12 | Product Groups | Master grup | Owner, Manager |
| A-13 | Products list | Katalog global, filter, status | Owner, Manager |
| A-14 | Product form | Buat/edit produk (SKU immutable) | Owner, Manager |
| A-15 | Pricelists list | Daftar pricelist company/store | Owner, Manager |
| A-16 | Pricelist form | Header + tanggal efektif + default | Owner, Manager |
| A-17 | Pricelist items | Harga per produk + tier min_qty | Owner, Manager |
| A-18 | Customers list | Daftar customer + poin + level | Owner, Manager |
| A-19 | Customer form | Buat/edit customer, riwayat poin | Owner, Manager |
| A-20 | Stock overview | Saldo stok per store (matrix produk × store) | Owner, Manager |
| A-21 | Stock ledger | Riwayat pergerakan stok per produk/store | Owner, Manager |
| A-22 | Stock adjustment form | Buat adjustment + alasan | Owner, Manager |
| A-23 | Stock transfers list | Daftar transfer + status | Owner, Manager |
| A-24 | Stock transfer form | Buat/kirim/terima transfer + selisih | Owner, Manager |
| A-25 | Review Queue | Transaksi ber-flag review | Manager, Owner |
| A-26 | EOD / Tutup Toko | Hitung kas per pecahan, selesaikan review, tutup | Manager, Owner |
| A-27 | Reports hub | Penjualan/store, SKU, terlaris, rata-rata belanja | Owner, Manager |
| A-28 | Report detail | Tabel + filter + ekspor Excel/PDF | Owner, Manager |
| A-29 | Activity Log | Audit trail | Owner |
| A-30 | Numbering | Konfigurasi penomoran transaksi | Owner |

### POS (tablet/desktop)
| # | Screen | Tujuan | Role |
|---|---|---|---|
| P-01 | Login | Autentikasi kasir | Kasir |
| P-02 | POS Main | Scan/cari produk, keranjang, total | Kasir |
| P-03 | Payment | Pilih metode, hitung kembalian, konfirmasi | Kasir |
| P-04 | Receipt | Cetak/cetak ulang struk | Kasir |
| P-05 | Parked Sales | Daftar hold, lanjutkan | Kasir |
| P-06 | Return | Retur berdasarkan transaksi asal | Kasir |
| P-07 | Delivery form | Data kirim + biaya | Kasir |
| P-08 | Customer lookup/create | Cari/daftar member | Kasir |
| P-09 | Offline/Queue status | Indikator offline + antrean tertunda | Kasir |
| P-10 | Device settings | WebUSB printer/drawer, test, pilih default | Kasir, Manager |
| P-11 | Cash count | Input per pecahan (EOD) | Kasir, Manager |
| P-12 | Review resolution | Selesaikan review (dari POS) | Manager |

---

## 5. User flows

### F1 — Penjualan tunai (happy path)
1. Kasir login → POS Main (field scan auto-fokus).
2. Scan barcode / ketik SKU → baris masuk keranjang (qty 1).
3. Ulangi; ubah qty via keypad jika perlu.
4. Tekan `F2` / tombol **Bayar** → Payment.
5. Pilih **Tunai**; masukkan uang diterima (keypad besar atau quick-amount).
6. Sistem menampilkan kembalian; tekan **Konfirmasi**.
7. Transaksi disimpan; drawer kick; struk dicetak.
8. Screen Receipt (auto-dismiss 5s) → kembali ke POS Main (keranjang kosong, scan auto-fokus).

### F2 — Penjualan non-tunai offline (pending)
1. POS offline (indikator merah di header).
2. Keranjang → Bayar → pilih metode non-tunai.
3. Sistem menandai pembayaran **Pending** (badge amber), transaksi masuk antrean offline.
4. Struk dicetak dengan penanda `BELUM TERKONFIRMASI`.
5. Saat online → antrean tersinkron; pembayaran tetap `pending` sampai webhook/konfirmasi.
6. Manager melihat transaksi pending di Review/Reports sampai dikonfirmasi.

### F3 — Penjualan dengan pengiriman
1. POS Main → tombol **Kirim**.
2. Isi penerima, telepon, alamat, biaya kirim.
3. Kembali ke keranjang; biaya kirim muncul sebagai baris terpisah dari subtotal produk.
4. Bayar (F1/F2).
5. Status pengiriman `pending`; Manager update ke `shipped`/`delivered` di admin.

### F4 — Retur penjualan
1. POS Main → **Retur**.
2. Cari transaksi asal via nomor struk (wajib jika `return_require_receipt=true`).
3. Sistem menampilkan baris + qty sisa yang bisa diretur.
4. Pilih qty retur; sistem memvalidasi batas waktu 30 hari.
5. Pilih metode refund (tunai/transfer/none) → konfirmasi.
6. Stok bertambah; poin dari transaksi asal dikurangi; struk retur dicetak.

### F5 — Hold & lanjut lintas perangkat
1. POS Main → **Hold** (`F4`) → transaksi jadi draft, tersinkron ke server bila online.
2. Di perangkat lain, kasir login → sistem menarik draft miliknya.
3. Parked Sales menampilkan draft (perangkat & waktu terakhir) → **Lanjutkan**.
4. Keranjang dipulihkan; finalisasi seperti F1.
5. Constraint: draft yang belum pernah tersinkron hanya muncul di perangkat asal.

### F6 — Transaksi offline → sinkron & review
1. Offline → transaksi tersimpan lokal (`client_uuid`).
2. Online → sinkron otomatis; indikator antrean turun.
3. Jika stok kurang & negative stock dilarang → transaksi masuk **Review Queue**.
4. Saat EOD, Manager menyelesaikan review (adjustment/void/tandai sah).
5. Jika `allow_negative_stock=true`, tidak ada review; transaksi otomatis sah.

### F7 — Tutup Toko (EOD)
1. Manager buka **EOD** → sistem menampilkan ringkasan penjualan hari bisnis + review pending.
2. Selesaikan semua review (blokir jika belum, kecuali override Owner).
3. **Cash count**: input jumlah per pecahan IDR; sistem menjumlahkan.
4. Sistem membandingkan kas seharusnya vs dihitung → tampilkan selisih.
5. Konfirmasi tutup → tanggal bisnis berikutnya terkunci sampai EOD berikutnya.

### F8 — Stock transfer
1. Buat transfer (gerai asal → tujuan, baris produk + qty).
2. `requested` → `shipped` (stok keluar) → `received` (stok masuk).
3. Saat terima, isi qty diterima; selisih wajib alasan.
4. Setelah `shipped` tidak bisa batal; solusi terima full lalu kirim balik.

### F9 — Stock adjustment
1. Pilih gerai + tanggal + alasan.
2. Tambah baris produk; isi qty sesudah; sistem menampilkan selisih.
3. Posting → masuk ledger + audit log; tidak bisa diedit.

### F10 — Enrollment member & poin
1. POS → Customer lookup → cari nomor HP/kode.
2. Jika belum ada → daftar (HP unik).
3. Transaksi selesai → poin bertambah sesuai earn rate level; level naik otomatis saat ambang terlewati.
4. Admin: customer form menampilkan saldo + riwayat poin.

### F11 — Assign pricelist ke store
1. Admin → Store form → bagian Pricelist.
2. Pilih pricelist (company/store-level), tandai satu default.
3. Simpan; validasi tidak boleh dua default aktif beririsan.

### F12 — Laporan & ekspor
1. Admin → Reports hub → pilih jenis laporan.
2. Set rentang tanggal (timezone Jakarta) + filter gerai (Owner).
3. Lihat tabel; klik **Ekspor** → pilih Excel atau PDF.
4. PDF memakai kop: nama, alamat, logo, periode.

---

## 6. Layout per screen

### A-02 Dashboard
- **Sections**: (1) Header halaman + store switcher, (2) KPI row, (3) Grafik penjualan 30 hari, (4) Tabel ringkas per store, (5) Aksi cepat.
- **Hierarki**: KPI row paling menonjol (angka display-lg, mono); grafik sekunder; tabel tersier.
- **Primary action**: pilih store / lihat laporan.
- **Komponen**: `PageHeader`, `StoreSwitcher`, `KpiCard`, `LineChart`, `DataTable`, `StatusChip`.

```
┌──────────────────────────────────────────────┐
│ Dashboard            [Store: STR001 ▾]       │
├──────────┬──────────┬──────────┬─────────────┤
│ Omzet    │ Transaksi│ Item     │ Pending     │
│ Rp 12.4jt│ 318      │ 1.204    │ 3           │
├──────────┴──────────┴──────────┴─────────────┤
│ Grafik penjualan 30 hari                     │
├──────────────────────────────────────────────┤
│ Tabel per store: store | omzet | txn | stok  │
└──────────────────────────────────────────────┘
```

### A-13 Products list
- **Sections**: toolbar (search, filter grup/status, tombol Tambah), tabel, pagination.
- **Hierarki**: toolbar sticky; kolom SKU & nama paling kiri; harga rata kanan mono.
- **Primary action**: **Tambah Produk**.
- **Komponen**: `TableToolbar`, `SearchInput`, `FilterSelect`, `DataTable`, `StatusChip`, `Pagination`, `EmptyState`.

### A-16/A-17 Pricelist
- **Sections**: header pricelist (kode, nama, tanggal efektif, default), tabel item harga (produk, min_qty, harga), tombol tambah item.
- **Primary action**: **Simpan** / **Tambah item**.
- **Komponen**: `FormSection`, `DateRangeField`, `DataTable`, `CurrencyInput`, `ConfirmDialog`.

### A-26 EOD / Tutup Toko
- **Sections**: (1) ringkasan penjualan, (2) Review Queue, (3) Cash count per pecahan, (4) selisih, (5) aksi tutup.
- **Hierarki**: review & selisih kas paling menonjol; tombol tutup disabled sampai valid.
- **Primary action**: **Tutup Toko**.
- **Komponen**: `SummaryPanel`, `ReviewList`, `DenominationCounter`, `VarianceDisplay`, `ConfirmDialog`.

### A-27/A-28 Reports
- **Sections**: filter bar (tanggal, gerai, jenis), hasil tabel, tombol ekspor.
- **Primary action**: **Ekspor Excel/PDF**.
- **Komponen**: `FilterBar`, `DataTable`, `ExportMenu`, `EmptyState`, `SkeletonTable`.

### P-02 POS Main (paling kritis)
- **Sections**: (1) Top bar: store, kasir, indikator online/offline + antrean, jam, tombol device, (2) Kolom kiri: search/scan + grid produk, (3) Kolom kanan: keranjang + total + action bar.
- **Hierarki**: total (display-xl mono) dan tombol **Bayar** paling dominan; search/scan auto-fokus; grid produk sekunder.
- **Primary action**: **Bayar** (F2).
- **Komponen**: `ScanInput`, `ProductGrid`, `ProductTile`, `CartList`, `CartLine`, `QuantityStepper`, `TotalsPanel`, `ActionBar`, `OfflineBanner`, `HoldButton`, `ReturnButton`, `CustomerButton`, `DeliveryButton`.

```
┌───────────────────────────────────────────────────────────┐
│ STR001 · Sari   ● Online  ⟳0     [Device] 12:30          │
├──────────────────────────────┬────────────────────────────┤
│ [Scan / cari produk    ]     │ Keranjang                  │
│                              │ ─ Aqua 600ml  2 x 4.000    │
│  ┌────┐ ┌────┐ ┌────┐        │ ─ Roti Tawar  1 x 15.000   │
│  │Aqua│ │Roti│ │Teh │        │ ─────────────────────────  │
│  └────┘ └────┘ └────┘        │ Subtotal          Rp 23.000│
│  ┌────┐ ┌────┐ ┌────┐        │ PPN 11%           Rp  2.530│
│  │ ...│ │ ...│ │ ...│        │ Biaya kirim       Rp      0│
│  └────┘ └────┘ └────┘        │ TOTAL        Rp 25.530     │
│                              │ [ Hold ][ Retur ][ Bayar F2]│
└──────────────────────────────┴────────────────────────────┘
```

### P-03 Payment
- **Sections**: (1) ringkasan total, (2) pilihan metode (tile), (3) input jumlah (keypad), (4) kembalian, (5) tombol konfirmasi.
- **Hierarki**: total + kembalian mono besar; metode sebagai tile besar; konfirmasi tidak bisa di-miss.
- **Primary action**: **Konfirmasi Pembayaran**.
- **Komponen**: `PaymentMethodTile`, `NumericKeypad`, `QuickAmountChips`, `ChangeDisplay`, `PendingBadge`, `Button`.

### P-11 Cash count (EOD)
- **Sections**: daftar pecahan (Rp100.000 … Rp100) dengan input jumlah, subtotal per pecahan, total, pembanding kas seharusnya, selisih.
- **Primary action**: **Simpan hitungan** / bagian dari Tutup Toko.
- **Komponen**: `DenominationCounter`, `MoneyInput`, `VarianceDisplay`.

---

## 7. Component library

Semua komponen punya state: `default, hover, focus-visible, pressed, disabled, loading, error`. Ringkas:

| Komponen | Variants | States penting |
|---|---|---|
| `Button` | filled, tonal, outlined, text, danger | hover/focus/pressed/disabled/loading |
| `IconButton` | standard, toggle | + `aria-pressed` |
| `TextField` | text, password, search, scan | error, helper, disabled, readonly |
| `Select` | single, multi | empty, searchable |
| `Combobox` | async (customer/product) | loading, no-result, error |
| `CurrencyInput` | rupiah, decimal | tabular mono, error, min/max |
| `QuantityStepper` | compact, POS-large | min=1, disabled |
| `NumericKeypad` | POS | key press feedback |
| `DateField` / `DateRangeField` | single, range | invalid, disabled |
| `Toggle` / `Checkbox` / `Radio` | — | checked, indeterminate, disabled |
| `StatusChip` | success, warning, danger, info, neutral, pending, offline | icon + label (warna bukan satu-satunya penanda) |
| `Badge` | count, dot | max 99+ |
| `Card` | default, interactive, selected | hover, selected |
| `DataTable` | compact, comfortable | sortable, selectable, loading skeleton, empty, error, sticky header |
| `Pagination` | — | first/last/prev/next, page size |
| `Drawer` / `SideSheet` | right, bottom | open/close, focus trap |
| `Dialog` | confirm, form | destructive, loading |
| `Toast` / `Snackbar` | info, success, warning, error | auto-dismiss, action |
| `Tabs` | underline, segmented | active, disabled |
| `Stepper` | horizontal | current, done, upcoming |
| `Tooltip` | — | hover/focus |
| `Breadcrumb` | — | truncation |
| `ScanInput` | POS | autofocus, beep feedback, not-found |
| `ProductTile` | grid, list | out-of-stock, inactive |
| `CartLine` | — | qty edit, remove, discount |
| `TotalsPanel` | POS | subtotal/tax/shipping/total |
| `ActionBar` | POS, admin sticky | — |
| `PaymentMethodTile` | cash, card, qris, manual | selected, pending |
| `DenominationCounter` | — | subtotal auto |
| `MoneyText` | — | mono, tnum, size variants |
| `OfflineBanner` | POS | offline, syncing, synced |
| `QueueIndicator` | POS | count, syncing |
| `DeviceStatusIndicator` | printer, drawer, scanner | connected, disconnected, error |
| `ReceiptPreview` | thermal, HTML | pending watermark |
| `ReviewItem` | — | pending/resolved |
| `EmptyState` | — | icon + title + action |
| `InlineError` | — | field-level |
| `ConfirmDialog` | — | destructive emphasis |
| `PermissionGate` | — | renders fallback (bukan menyembunyikan diam-diam) |
| `RoleBadge` | owner/manager/cashier | — |
| `AuditTimeline` | — | actor, action, time |

---

## 8. State (empty, loading, error, success, offline)

| Screen | Empty | Loading | Error | Success | Offline |
|---|---|---|---|---|---|
| P-02 POS Main | "Scan atau cari produk untuk mulai" + ikon scanner | skeleton grid + spinner total | banner error "Produk gagal dimuat" + Retry | baris masuk keranjang + total update (aria-live) | banner "Offline — transaksi disimpan & disinkron" + queue count |
| P-03 Payment | tidak berlaku (selalu ada total) | tombol Konfirmasi loading | "Pembayaran gagal disimpan" + Retry (transaksi tidak hilang) | Receipt + drawer + cetak | metode non-tunai ditandai `Pending`; struk "belum terkonfirmasi" |
| P-05 Parked Sales | "Tidak ada transaksi ditahan" | skeleton list | "Gagal memuat draft" + Retry | draft hilang dari list setelah finalisasi | draft lokal ditandai "perangkat ini" |
| P-09 Offline status | "Tidak ada antrean" | syncing spinner | "Sinkron gagal" + Retry | "Semua tersinkron" (hijau) | "Offline" (amber) + jumlah |
| A-13 Products | "Belum ada produk" + Tambah | skeleton table | inline error + Retry | toast "Produk disimpan" | banner "Data mungkin belum terbaru" |
| A-26 EOD | "Belum ada penjualan hari ini" | skeleton ringkasan | "Gagal memuat EOD" + Retry | "Toko ditutup" + lock tanggal berikutnya | transaksi offline ditandai; EOD menunggu sinkron |
| A-27 Reports | "Tidak ada data pada periode ini" + ubah filter | skeleton chart/table | error + Retry | ekspor berhasil (toast + unduhan) | banner "Sebagian data offline belum tersinkron" |
| A-02 Dashboard | "Belum ada transaksi" + CTA | skeleton KPI | error + Retry | data tampil | banner data sementara |

Prinsip state: **error tidak pernah menghapus input** (khusus POS, keranjang dipertahankan); **loading memakai skeleton**, bukan spinner layar penuh, kecuali operasi blok (bayar, tutup toko).

---

## 9. Responsive behaviour

| Breakpoint | POS | Admin |
|---|---|---|
| Mobile `<768` | **Tidak didukung** — tampil layar "Gunakan tablet/desktop" + alasan | Didukung terbatas: tabel → card list, sidebar → bottom nav; form stacked; aksi destruktif butuh konfirmasi ekstra |
| Tablet `768–1279` | Didukung penuh (mode sentuh): target ≥48px, grid produk 3–4 kolom, keranjang sebagai panel bawah (bottom sheet) | Sidebar collapse; tabel horizontal scroll; filter jadi sheet |
| Desktop `≥1280` | Layout dua kolom penuh (grid + keranjang) | Sidebar tetap; tabel full; multi-kolom |

Aturan POS tablet: keranjang tidak pernah tersembunyi; total selalu terlihat (sticky bottom bar) karena ini angka uang.

---

## 10. Accessibility

### Kontras
- Teks normal ≥ **4.5:1**; teks besar (≥24px/19px bold) ≥ **3:1**; komponen UI & border penting ≥ **3:1**.
- Pasangan yang sudah diverifikasi: `#1B1F23` di `#FBFBFA` ≈ 15:1; `#FFFFFF` di `#0F6E63` ≈ 5.8:1; `#FFFFFF` di `#B3261E` ≈ 5.9:1; `#FFFFFF` di `#137A4B` ≈ 4.8:1; `#5A6472` di `#FBFBFA` ≈ 6.5:1.
- Status **tidak boleh** hanya warna: selalu ikon + label (contoh `StatusChip`: ikon jam + teks "Pending").

### Fokus & keyboard
- `:focus-visible` wajib terlihat (`--focus-ring` 3px) di semua elemen interaktif.
- Urutan fokus logis (DOM order); POS: fokus awal di `ScanInput`; setelah tambah produk fokus kembali ke scan.
- Skip link "Lewati ke konten" di Admin.
- Dialog/Drawer: focus trap + `Esc` menutup + fokus kembali ke pemicu.
- Shortcut POS (didokumentasikan di UI): `F2` Bayar, `F4` Hold, `F6` Retur, `Esc` batal, `Enter` tambah scan. Tidak ada shortcut satu-tombol untuk void/destruktif.

### ARIA
- Keranjang & total: `aria-live="polite"` saat berubah.
- Offline/sync: `role="status"` `aria-live="polite"` untuk jumlah antrean; error sinkron `role="alert"`.
- `DataTable`: `<table>` semantik + `<caption>` (bisa visually hidden) + `aria-sort` pada header.
- `Combobox` (customer/produk): pola `combobox` + `listbox` + `aria-activedescendant`.
- Icon-only button wajib `aria-label`; ikon dekoratif `aria-hidden="true"`.
- Modal: `role="dialog"` `aria-modal="true"` + `aria-labelledby`.
- Field error: `aria-describedby` + `aria-invalid`.
- `prefers-reduced-motion`: matikan animasi.

### Target & input
- Target sentuh POS ≥ **48×48px**; Admin ≥ **44×44px** (WCAG 2.5.8).
- Input nominal memakai `inputmode="numeric"` + font mono; hindari `type=number` (masalah scroll/format) — pakai text + mask.

---

## Lampiran — Handoff notes

- Implementasi token sebagai CSS custom properties (selaras `md3.css`) + TypeScript types.
- Komponen React: satu direktori per komponen, satu file `.stories` untuk state/variant.
- Font: self-host `Inter` (variable) + `IBM Plex Mono` (subset 400/600), `font-display: swap`.
- Ikon: Material Symbols Rounded (sudah dipakai) — konsisten dengan codebase.
- Uji aksesibilitas wajib: axe pada setiap screen kunci + uji keyboard manual untuk alur F1, F4, F7.
- Uji kontras otomatis di CI.

### Pertanyaan desain terbuka (bukan blocker coding fondasi)
1. Apakah perlu dark theme untuk POS (lampu gerai malam)? Rekomendasi: V2.
2. Apakah dashboard menampilkan grafik interaktif (zoom/tooltip) atau statis? Rekomendasi: tooltip dasar saja di MVP.
3. Layout struk thermal 58mm vs 80mm — template HTML perlu dua varian; konfirmasi printer pilot.
