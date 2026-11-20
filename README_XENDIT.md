# Pembayaran Xendit — DAFF AIRBNB

Fitur pembayaran memakai **Xendit Invoice**. Selama kredensial belum diisi, aplikasi berjalan dalam **mode simulasi lokal** agar alur tetap bisa diuji.

## 1. Mode simulasi (default)

Tidak perlu konfigurasi. Alur:

1. User booking properti.
2. User diarahkan ke `pembayaran.php`.
3. Klik **Bayar Sekarang** → masuk ke `mock_xendit.php`.
4. Klik **Simulasikan Pembayaran Berhasil**.
5. Order berubah menjadi `paid`, lalu owner klik **Terima** di `admin.php`.

## 2. Memakai Xendit asli (test/live)

1. Buat akun Xendit dan masuk ke dashboard.
2. Ambil **Secret API Key** dari menu *Settings → Developers → API Keys*.
3. Ambil **Callback Verification Token** dari menu *Settings → Developers → Callbacks*.
4. Salin `config/xendit.local.example.php` menjadi `config/xendit.local.php`.
5. Isi `secret_key`, `callback_token`, dan `app_url`:

```php
<?php
return [
    'secret_key'       => 'xnd_development_xxxxxxxx',
    'callback_token'   => 'token-dari-dashboard-xendit',
    'base_url'         => 'https://api.xendit.co',
    'invoice_duration' => 86400, // 24 jam
    'auto_confirm'     => false, // true = langsung confirmed setelah dibayar
    'app_url'          => 'http://localhost/PKK',
];
```

6. Di dashboard Xendit, set **Invoice callback URL** ke:

```text
https://domain-anda.com/PKK/api/xendit_callback.php
```

Untuk localhost, gunakan ngrok, misalnya:

```powershell
ngrok http 80
```

lalu set callback:

```text
https://xxxx.ngrok-free.app/PKK/api/xendit_callback.php
```

7. Buka `pembayaran.php` melalui URL publik yang sama dengan `app_url` agar redirect Xendit kembali ke halaman yang benar.

## 3. Alur status

```text
pending_payment → paid → confirmed → completed
        │
        ├→ expired
        └→ cancelled
```

- `pending_payment` — order dibuat, belum dibayar.
- `paid` — Xendit callback `PAID` diterima.
- `confirmed` — owner menerima pesanan.
- `completed` — masa sewa selesai.
- `expired` — invoice lewat 24 jam.
- `cancelled` — dibatalkan owner/user.

## 4. File utama

| File | Fungsi |
|---|---|
| `api/booking.php` | Membuat order `pending_payment`. |
| `api/create_invoice.php` | Membuat invoice Xendit / invoice simulasi. |
| `api/xendit_callback.php` | Webhook Xendit (validasi `x-callback-token`). |
| `api/xendit_return.php` | Redirect setelah pembayaran, sinkronisasi status. |
| `api/check_payment.php` | Cek status manual. |
| `api/mock_payment.php` | Simulasi pembayaran/expired. |
| `pembayaran.php` | Halaman tagihan user. |
| `mock_xendit.php` | Halaman checkout simulasi. |
| `includes/xendit.php` | Helper cURL Xendit. |
| `includes/payments.php` | Logika status pembayaran/order. |
| `config/xendit.php` | Konfigurasi & deteksi mode simulasi. |
| `database/migration_xendit.sql` | Migrasi database. |

## 5. Keamanan

- Secret key hanya dibaca di server.
- Callback wajib menyertakan `x-callback-token` yang valid.
- Nominal pembayaran dicek terhadap total order.
- Callback bersifat idempotent, aman jika Xendit mengirim ulang.
- Jangan commit `config/xendit.local.php` ke repository publik.
