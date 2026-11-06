# Hak akses Store Manager di backoffice — Desain

Tanggal: 2026-11-06 · Status: menunggu tinjauan · Cakupan: MVP

## 1. Tujuan

Store Manager (SM) login ke halaman admin seperti Owner, tetapi:

- **Menulis** hanya untuk: EOD (termasuk buka ulang), Review Stok, Penyesuaian Stok, Transfer Stok, dan Pengiriman (status; gerainya sendiri).
- **Membaca** hampir semua halaman lain (hanya baca), kecuali empat halaman yang tertutup total.
- Halaman yang berisi **data per gerai** hanya menampilkan data **gerai SM sendiri**.
- Dashboard hanya menampilkan gerai SM sendiri.

Larangan harus berlaku **di server** (403), bukan hanya dengan menyembunyikan menu atau tombol.

**Di luar cakupan:** matriks izin yang dapat diatur lewat UI, peran baru selain Owner/Store Manager/Kasir, dan akses SM ke layar POS untuk transaksi (SM langsung masuk ke admin saat login).

## 2. Kondisi sekarang

- Seluruh grup rute `/admin` hanya dijaga middleware `admin` (`EnsureUserIsAdmin`), yang meloloskan Owner dan SM.
- Pembatasan SM hanya berupa item menu yang disembunyikan dan `ensureSuperuser()` di sebagian aksi (16 controller memakai trait `AuthorizesSuperuser`). Banyak halaman master (Produk, Harga, Promosi, Pelanggan, Pengiriman) bisa dibuka dan diubah SM.
- Stok, Laporan, dan Pembatalan Transaksi sudah dikunci ke gerai user lewat `ScopesToStore` / `reportStoreIds`.
- `activity_logs` **tidak punya kolom gerai**; `pos_sessions` punya `store_id`.
- Pergantian gerai aktif (`stores.switch`) sudah khusus Owner; dashboard memakai `active_store_id()` yang untuk SM bernilai gerainya.
- Pada transfer stok, `ship`, `receive`, dan `cancel` memakai pemeriksaan yang sama (gerai asal **atau** tujuan).

## 3. Matriks akses

Owner tidak berubah. Untuk SM:

| Kelompok | Rute (nama) | Baca (GET) | Tulis |
|---|---|---|---|
| **Tertutup total** | `admin.company.*`, `admin.numberings.*`, `admin.sap-import.*`, `admin.sap-postings.*` | 403 | 403 |
| **Tulis diizinkan** | `admin.eod.*`, `admin.stock-review.*`, `admin.stock-adjustments.*`, `admin.stock-transfers.*`, `admin.deliveries.*` | boleh | boleh |
| **Data per gerai (baca, gerai sendiri)** | `admin.dashboard*`, `admin.reports.*`, `admin.cancellations.*`, `admin.stock.*`, `admin.logs.*`, `admin.pos-sessions.*` | boleh, difilter | 403 |
| **Master company-wide (hanya baca)** | semua rute `admin.*` lainnya (produk, harga, promosi, pelanggan, gerai, warehouse, tax group, mata uang, tipe pembayaran, user, dan seterusnya) | boleh | 403 |

Konsekuensi yang perlu disadari:

- SM **tidak lagi** bisa mengubah poin pelanggan atau mengelola master produk/harga/promosi. Sebelumnya hal-hal ini terbuka. Pengiriman tetap bisa diubah SM (keputusan 2026-11-06), terbatas pada pengiriman gerainya sendiri.
- `override_review` pada EOD tetap khusus Owner (tidak berubah).
- Tombol Reset Password dan seluruh aksi di Daftar User hilang untuk SM; halaman daftarnya tetap bisa dilihat.

## 4. Penegakan di server

### 4.1 Satu sumber kebenaran: `App\Support\BackofficeAccess`

Kelas statis tanpa state:

- Konstanta `CLOSED` (pola nama rute tertutup total), `WRITABLE` (pola nama rute yang boleh ditulis SM), dan `STORE_SCOPED` (pola halaman data per gerai).
- `allows(User $user, string $routeName, string $method): bool`
  - Owner: selalu `true`.
  - Selain Owner dan SM: `false` (Kasir tidak masuk backoffice).
  - SM: `false` bila cocok `CLOSED`; bila metode bukan `GET`/`HEAD`, `true` hanya bila cocok `WRITABLE`; selain itu `true`.
- `isReadOnlyFor(User $user, string $routeName): bool` untuk tampilan (Bagian 5).

Tulis bersifat **deny-by-default** untuk SM: rute baru otomatis tidak bisa ditulis SM sebelum didaftarkan di `WRITABLE`.

### 4.2 Middleware `backoffice.access`

- Ditaruh sekali di grup `/admin`, setelah `admin`.
- Memanggil `BackofficeAccess::allows` dengan nama rute dan metode saat ini; 403 dengan pesan jelas bila ditolak.
- SM tanpa `store_id` → 403 ("Akun Anda belum ditugaskan ke gerai"), konsisten dengan FR-A1-08.
- Membagikan `$readOnly` ke semua view (`view()->share`).

### 4.3 `ensureSuperuser()` di controller

Tetap dipakai sebagai pertahanan berlapis pada **aksi tulis** yang khusus Owner. Pada aksi **baca** (`index`/`show`) yang sekarang memanggilnya (mis. Log Aktivitas, Sesi Kasir, Daftar User), panggilan itu dihapus agar SM dapat melihat; pembatasannya pindah ke matriks.

Rute formulir (`*.create`, `*.edit`, dan `*.receive-form`) memakai metode GET tetapi memang untuk menulis. Agar SM tidak melihat formulir yang tidak bisa disimpan, `BackofficeAccess::allows` memperlakukan rute berakhiran `.create` dan `.edit` sebagai **tulis** (403 untuk SM kecuali ada di `WRITABLE`). `receive-form` berada di `admin.stock-transfers.*` sehingga tetap diizinkan.

## 5. Tampilan

- Menu samping: satu fungsi `BackofficeAccess::visible()` menggantikan variabel `$isSuperuser` per item; empat halaman tertutup tidak muncul untuk SM, halaman lain tampil.
- Tombol Tambah/Ubah/Hapus/Reset/Sinkron pada halaman baca-saja disembunyikan dengan `@unless ($readOnly)`. Perlu ditinjau view yang memuat form atau tombol aksi (sekitar 50 file di `resources/views/admin`).
- Pengganti `auth()->user()->isSuperuser()` yang sudah ada di view dibiarkan bila artinya "khusus Owner"; selainnya diganti `$readOnly`.

## 6. Data per gerai

| Halaman | Perubahan |
|---|---|
| Dashboard (`DashboardController`, `today`) | Sudah memakai `active_store_id()`; tambahkan test bahwa SM tidak dapat berpindah gerai. |
| Laporan, Pembatalan Transaksi, Saldo Stok, Review Stok, Pengiriman, Penyesuaian, Transfer | Sudah terkunci ke gerai user; hanya perlu test regresi. |
| **Sesi Kasir** | Hapus `ensureSuperuser` pada baca; tambahkan filter `store_id = gerai SM` untuk non-Owner. |
| **Log Aktivitas** | Tambah kolom `activity_logs.store_id` (nullable, indeks). `ActivityLogger` mengisinya dari gerai user yang beraksi (`store_id` user). Migrasi mengisi baris lama dari `users.store_id` aktor bila ada. SM hanya melihat baris dengan `store_id` = gerainya; baris tanpa gerai hanya untuk Owner. |

## 7. Aturan transfer stok

Hanya berlaku untuk non-Owner (Owner tetap bebas):

- Buat: gerai asal = gerai SM; gerai tujuan = gerai mana pun dalam perusahaan.
- Kirim (`ship`) dan batal (`cancel`): hanya jika gerai SM = gerai **asal**.
- Terima (`receive`, `receiveForm`): hanya jika gerai SM = gerai **tujuan**.
- Daftar transfer: tampil bila gerai SM adalah asal atau tujuan (tidak berubah).

Penyesuaian dan transfer stok tetap hanya di buku stok gerai lama dan belum diposting ke SAP (FR-C8-13; tidak berubah).

## 8. Pengujian

1. **Test sapu rute** (`BackofficeAccessTest`): iterasi `Route::getRoutes()` pada grup `admin.*`; untuk setiap rute non-GET, SM harus mendapat 403 kecuali nama rutenya cocok `WRITABLE`. Mencegah rute baru bocor.
2. **Tertutup total:** SM mendapat 403 (GET dan tulis) pada empat kelompok rute.
3. **Hanya baca:** SM bisa membuka daftar Produk/Pelanggan/Harga/User, tetapi POST/PUT/DELETE → 403; tombol aksi tidak muncul.
4. **Tulis diizinkan:** SM bisa EOD, review stok, membuat/memposting penyesuaian, membuat transfer dari gerainya, dan mengubah status pengiriman gerainya (pengiriman gerai lain → 403).
5. **Gerai sendiri:** dengan dua gerai, SM A tidak melihat data gerai B di Dashboard, Laporan, Pembatalan, Saldo Stok, Pengiriman, Sesi Kasir, dan Log Aktivitas; parameter `?store=` diabaikan.
6. **Transfer:** SM tujuan tidak bisa `ship`/`cancel`; SM asal tidak bisa `receive`; keduanya tetap melihat transfer di daftar.
7. **Owner tidak terpengaruh:** test lama tetap hijau.
8. **Log Aktivitas:** `ActivityLogger` mengisi `store_id`; migrasi mengisi baris lama.

## 9. Perubahan PRD

- Persona Store Manager (Bagian 3): ganti "mengelola produk, harga, stok, customer, EOD, dan laporan gerainya" dengan cakupan baru.
- FR-A1 (peran): tambah FR matriks akses (Bagian 3 dokumen ini) dan aturan transfer.
- FR-B8 (EOD), FR-A9-03, FR-B6-01: sesuaikan bahwa data dibatasi ke gerai SM.
- Bagian 10 (Open questions): hapus catatan "konsep Store Manager belum final" yang ditambahkan 2026-11-06.

## 10. Risiko

- **Pengurangan hak:** SM yang terbiasa mengubah master data (produk, harga, promosi, poin pelanggan) akan mendapat 403. Perlu diinformasikan ke pengguna sebelum rilis.
- **Log Aktivitas lama:** baris tanpa `store_id` yang tidak bisa diisi hanya terlihat oleh Owner.
- **View banyak:** menyembunyikan tombol di ±50 view rawan terlewat; server tetap menolak, sehingga dampaknya hanya tampilan.
