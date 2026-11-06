---
paths:
  - app/Support/BackofficeAccess.php
  - routes/web.php
  - app/Http/Middleware/EnsureBackofficeAccess.php
---

# Backoffice

## Rute admin baru otomatis tertutup untuk Store Manager
Hak akses Store Manager (SM) ditentukan satu tempat: `BackofficeAccess` (dipasang lewat middleware `backoffice.access` di grup `/admin` routes/web.php). Tulis (non-GET, plus rute `*.create`/`*.edit`) ditolak 403 untuk SM kecuali awalan nama rutenya ada di `WRITABLE` (EOD, review stok, penyesuaian, transfer, pengiriman). Rute yang tertutup total ada di `CLOSED` (company, numberings, sap-import, sap-postings). Menambah rute admin baru: daftarkan di `WRITABLE` bila SM boleh menulis, di `CLOSED` bila tertutup; kalau tidak, SM hanya bisa membacanya. Tombol tulis di view dibungkus `@unless ($readOnly)`. Data per gerai wajib difilter ke `store_id` SM (ScopesToStore / `where('store_id', $user->store_id)`), termasuk `activity_logs.store_id`. Pada transfer stok: kirim/batal hanya gerai asal, terima hanya gerai tujuan. Dijaga oleh tests/Feature/BackofficeAccessTest.php (test sapu rute).
