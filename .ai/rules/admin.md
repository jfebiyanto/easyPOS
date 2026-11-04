---
paths:
  - app/Http/Controllers/Admin/CompanyController.php
---

# Admin

## Koneksi SAP B1 terkunci setelah impor awal
Titik kunci = `sap_initial_imported_at` terisi. Sebelum itu semua field SAP bebas diubah tanpa konfirmasi.
Setelah impor awal: CompanyDB (`sap_db_name`) terkunci permanen (ditolak di server, bukan hanya di UI) karena data impor tidak boleh tercampur dengan database SAP lain; pindah database = reimplementasi easyPOS, tidak ada tombol Reset Integrasi.
Endpoint URL, username, password, self-signed, dan toggle aktif/nonaktif boleh diubah hanya oleh Owner, lewat form SAP terpisah dari form identitas, dengan konfirmasi password akun easyPOS (per simpan, bukan middleware password.confirm). Perubahan koneksi wajib lolos tes login SAP di server. Data `source = sap` tetap read-only walau integrasi dimatikan.
