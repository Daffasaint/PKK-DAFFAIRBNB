# DAFF AIRBNB

Aplikasi pemesanan properti (villa, rumah, dan penginapan) berbasis **PHP native + MySQL** dengan dashboard owner, manajemen stok kamar, dan pembayaran online melalui **Xendit** (tersedia juga mode simulasi untuk pengembangan lokal).

## Fitur

- Katalog properti dengan pencarian, filter kategori, dan detail properti
- Registrasi & login user (session-based)
- Pemesanan dengan pengecekan ketersediaan dan stok kamar
- Halaman **Pesanan Saya** dan invoice
- Pembayaran via Xendit Invoice + webhook callback (atau mode simulasi lokal)
- Dashboard owner: kelola properti, pesanan, stok kamar, dan blokir tanggal
- Alur status pesanan: `pending_payment → paid → confirmed → completed` (dengan `expired`/`cancelled`)

## Teknologi

- PHP 8+ (PDO, session)
- MySQL / MariaDB
- HTML, CSS, JavaScript vanilla
- Xendit API (opsional)

## Struktur Folder

```text
api/          endpoint JSON (auth, properti, booking, pembayaran)
config/       konfigurasi database & Xendit
database/     skema + seed data dan file migrasi
images/       gambar properti & hero
includes/     helper auth, pricing, availability, pembayaran
storage/      log runtime
uploads/      file upload properti (tidak di-commit)
admin.php     dashboard owner
index.php     halaman utama
pembayaran.php, invoice.php, pesananku.php
```

## Instalasi (Laragon)

1. Clone repo ini ke folder web server, misalnya `C:\laragon\www\PKK`:
   ```powershell
   git clone https://github.com/<username>/PKK-DAFFAIRBNB.git C:\laragon\www\PKK
   ```
2. Import database melalui phpMyAdmin atau CLI:
   ```powershell
   mysql -u root < database/daff_airbnb.sql
   ```
   Jika diperlukan, jalankan juga file migrasi di `database/` (`migration_*.sql`).
3. Sesuaikan koneksi database bila perlu. `config/db.php` membaca environment variable berikut (default Laragon: host `127.0.0.1`, user `root`, password kosong):
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
4. Buka `http://localhost/PKK/` di browser.

## Akun Demo

| Role | Email | Password |
|---|---|---|
| Owner/Admin | `owner@daff.com` | `admin123` |
| User | `user@daff.com` | `user123` |

> **Penting:** ganti password akun demo sebelum aplikasi dipakai di server publik.

## Konfigurasi Xendit (opsional)

Secara default aplikasi berjalan dalam **mode simulasi lokal** sehingga alur pembayaran tetap bisa dicoba tanpa kredensial.

Untuk memakai Xendit asli:

1. Salin `config/xendit.local.example.php` menjadi `config/xendit.local.php`.
2. Isi `secret_key` dan `callback_token` dari dashboard Xendit.
3. Set callback URL ke `https://domain-anda.com/PKK/api/xendit_callback.php`.

Detail lengkap ada di [`README_XENDIT.md`](README_XENDIT.md).

## Keamanan

- `config/xendit.local.php` tidak di-commit (sudah masuk `.gitignore`).
- Isi `uploads/properties/`, log runtime, dan file editor/OS juga diabaikan.
- Kredensial database dibaca dari environment variable dengan fallback default lokal.

## Lisensi & Kredit

Gambar properti menggunakan sumber gratis; lihat [`images/properties/CREDITS.md`](images/properties/CREDITS.md).
