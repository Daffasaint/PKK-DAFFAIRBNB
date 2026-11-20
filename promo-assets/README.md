# Aset Promosi — QR Code

QR code untuk demo/promo DAFF AIRBNB. Mengarah ke aplikasi lokal di jaringan WiFi yang sama:

```text
http://10.227.43.170/PKK/
```

| File | Ukuran | Kegunaan |
|---|---|---|
| `qr/qr-lan.png` | 1200×1200 | QR polos untuk video/overlay |
| `qr/qr-lan.svg` | vektor | QR untuk desain cetak/resolusi bebas |
| `qr/qr-card.png` | 1080×1350 | Kartu QR ber-branding untuk poster/end card video |

## Cara pakai

- **HP** harus terhubung ke WiFi yang sama dengan komputer.
- Scan dengan kamera HP, atau buka URL di atas langsung.
- Jika komputer memakai IP lain, generate ulang QR (lihat di bawah).

## Generate ulang QR

Ganti `10.227.43.170` dengan IP LAN komputer saat ini:

```powershell
npx --yes qrcode -t png -w 1200 -e H -q 4 -l FFFDF8FF -d 3E2723FF `
  -o promo-assets/qr/qr-lan.png "http://10.227.43.170/PKK/"

npx --yes qrcode -t svg -e H -q 4 -l FFFDF8F8 -d 3E2723FF `
  -o promo-assets/qr/qr-lan.svg "http://10.227.43.170/PKK/"
```

Cek IP LAN:

```powershell
Get-NetIPAddress -AddressFamily IPv4 |
  Where-Object { $_.IPAddress -notlike '127.*' } |
  Select-Object InterfaceAlias,IPAddress
```

> Catatan: QR ini hanya berfungsi di jaringan lokal. Saat aplikasi sudah punya domain publik, generate ulang QR dengan URL domain tersebut.
