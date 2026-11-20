# Prompt Remotion — Video Promosi DAFF AIRBNB

> **Cara pakai:** berikan seluruh isi dokumen ini kepada AI coding agent (OpenCode, Claude Code, Cursor, dll.) atau developer. Prompt ini sudah memuat brief, storyboard, spesifikasi animasi, aset, dan acceptance criteria.
>
> **Asumsi default:** video vertikal **9:16 (1080×1920)**, durasi **30 detik**, **30 fps**, teks **Bahasa Indonesia**, tanpa voice-over (musik + teks di layar). Semua parameter bisa diubah di bagian [Parameter](#1-parameter).

---

## 1. Parameter

| Parameter | Nilai default | Catatan |
|---|---|---|
| Composition ID | `DaffAirbnbPromo` | |
| Resolusi | `1080 × 1920` | Vertikal untuk Reels/TikTok/Shorts |
| FPS | `30` | |
| Durasi | `30 detik` = `900 frame` | |
| Format output | MP4 H.264, `yuv420p`, CRF 18 | |
| Bahasa | Indonesia | |
| Gaya | Elegant tropical, cinematic, hangat | Mengikuti tema “Elegant Espresso & Batik” |
| Voice-over | Tidak ada (opsional) | Musik + teks di layar |
| Target platform | Instagram Reels, TikTok, YouTube Shorts | |

---

## 2. Prompt utama (ringkas — untuk agent)

> Buat project **Remotion 4 + React + TypeScript** di dalam folder `promo-video/` pada repo DAFF AIRBNB. Jangan mengubah aplikasi PHP yang sudah ada.
>
> Hasilkan video promosi vertikal **1080×1920, 30 fps, 30 detik (900 frame)** dengan Composition ID `DaffAirbnbPromo`. Gunakan identitas visual DAFF AIRBNB: warna espresso `#3E2723`, cokelat `#6B3A2A`, gold `#C8A951`, krem `#F7F2E9`, font **Inter**, dan logo `image-removebg-preview.png`. Gunakan foto properti asli dari `images/properties/`.
>
> Struktur video: (1) logo reveal 0–3 dtk, (2) hook + montase destinasi 3–9 dtk, (3) kartu properti 9–16 dtk, (4) fitur utama 16–22 dtk, (5) grid destinasi + rating 22–27 dtk, (6) CTA 27–30 dtk dengan **QR code** `promo-assets/qr/qr-lan.png` dan teks “Scan untuk booking”. Gunakan animasi `spring` dan `interpolate` yang halus, transisi `@remotion/transitions`, Ken Burns pada foto, dan teks stagger per kata. Semua teks Bahasa Indonesia. Sertakan musik royalty-free (opsional, fallback tanpa audio), README, dan script `npm run render` yang menghasilkan `out/daff-airbnb-promo.mp4`. Pastikan `npm install` dan `npm run render` berhasil dari kondisi bersih.

---

## 3. Brief kreatif

**Produk:** DAFF AIRBNB — platform booking villa, rumah, dan penginapan di Indonesia.

**Pesan utama:** Liburan impian dimulai di sini — pilih properti, cek ketersediaan real-time, bayar aman, dan berangkat tanpa ribet.

**Nuansa:** hangat, elegan, premium tapi ramah. Bukan gaya tech startup yang dingin. Perpaduan warna espresso, krem, dan gold dengan aksen pola batik halus.

**Emosi yang ingin ditimbulkan:**
1. Terpesona oleh destinasi (0–9 dtk)
2. Percaya pada pilihan properti (9–22 dtk)
3. Terdorong untuk booking (22–30 dtk)

**Hal yang harus dihindari:**
- Klaim palsu seperti “1 juta tamu” atau “terbaik di dunia”.
- Animasi berlebihan, bounce berlebihan, atau transisi yang terlalu ramai.
- Teks kecil yang tidak terbaca di layar HP.
- Musik/aset yang memerlukan koneksi internet saat render.

---

## 4. Design system

### Warna

| Token | Hex | Penggunaan |
|---|---|---|
| Espresso | `#3E2723` | Background gelap, teks utama |
| Cokelat | `#6B3A2A` | Aksen utama, tombol |
| Cokelat terang | `#8B5E3C` | Gradient, highlight |
| Gold | `#C8A951` | Garis, ikon, angka rating |
| Gold lembut | `#E6D5A4` | Glow, partikel |
| Krem | `#F7F2E9` | Background terang |
| Putih gading | `#FFFDF8` | Kartu, teks di background gelap |
| Hijau sukses | `#1E6B34` | Checkmark / status aman |

### Tipografi

- Font: **Inter** (gunakan `@remotion/google-fonts/Inter`, bukan CDN runtime).
- Judul: `800`, ukuran 84–110 px, line-height 1.05, letter-spacing -1 px.
- Subjudul: `500`, 40–52 px.
- Body/kartu: `600`, 30–36 px.
- Label kecil: `700`, 24–28 px, uppercase, letter-spacing 2 px.
- Semua teks minimum **28 px** pada kanvas 1080 px.

### Elemen visual

- Logo: `image-removebg-preview.png` (di-copy ke `public/logo.png`).
- Pola batik halus: SVG/PNG opacity 4–8% sebagai overlay.
- Grain film halus: opacity 3–5%.
- Gold divider line: 2–3 px, animasi scaleX.
- Card radius: 28 px, shadow lembut `0 18px 44px rgba(62,39,35,0.18)`.
- Gradient overlay foto: `linear-gradient(180deg, rgba(44,24,16,0) 35%, rgba(44,24,16,0.82) 100%)`.

---

## 5. Storyboard (900 frame @ 30 fps)

### Scene 1 — Logo Reveal `0–90` (0–3 dtk)

- Background krem `#F7F2E9` dengan pola batik opacity rendah.
- Logo masuk dari scale `0.6 → 1` dengan spring halus (damping 200), disertai glow gold tipis.
- Gold line melebar dari tengah (scaleX `0 → 1`) di bawah logo.
- Teks muncul stagger per kata:
  - **DAFF AIRBNB** (800, espresso)
  - **“Sewa Villa & Rumah Impianmu”** (500, cokelat)
- Efek suara: soft chime (opsional).
- Exit: fade + scale 1 → 1.04 selama 12 frame.

### Scene 2 — Hook + Montase Destinasi `90–270` (3–9 dtk)

- Full-bleed foto destinasi berganti tiap 60 frame: **Bali → Lombok → Raja Ampat**.
- Efek Ken Burns: `scale 1.06 → 1.14`, `translateY 0 → -20px`, ease-out.
- Overlay gradient gelap di bawah agar teks terbaca.
- Headline stagger per kata: **“Liburan impian, tanpa ribet.”**
- Subteks: **“Villa, rumah, dan penginapan pilihan di seluruh Indonesia.”**
- Label destinasi kecil di atas headline: **BALI • LOMBOK • RAJA AMPAT**.
- Transisi antar foto: crossfade 12 frame + slight blur.

### Scene 3 — Kartu Properti `270–480` (9–16 dtk)

- Background krem dengan blob gold lembut di belakang.
- Judul: **“Properti pilihan”** + subjudul **“Harga transparan, stok real-time.”**
- Tiga kartu properti masuk staggered dari bawah (delay 8 frame), spring damping 18:
  1. **Rumah Tropis Seminyak** — Seminyak, Bali — `Rp285.000/malam` — ⭐ 4.92
  2. **Villa Ubud Hijau** — Ubud, Bali — `Rp420.000/malam` — ⭐ 4.88
  3. **Villa Raja Ampat** — Raja Ampat, Papua — `Rp650.000/malam` — ⭐ 4.95
- Setiap kartu: foto, nama, lokasi dengan ikon pin, harga, rating gold.
- Kartu sedikit miring (`rotate -2° / 0° / 2°`) dan bergerak parallax halus.
- Efek suara: pop lembut saat kartu muncul.

### Scene 4 — Fitur Utama `480–660` (16–22 dtk)

Tiga baris fitur muncul bergantian (stagger 10 frame), masing-masing dengan ikon gold dan checkmark hijau:

1. **Cek ketersediaan real-time** — “Stok kamar selalu akurat.”
2. **Pembayaran aman** — “Transaksi diproses dengan aman.”
3. **Konfirmasi cepat** — “Owner merespons pesanan Anda.”

Animasi: ikon scale `0.7 → 1` + rotate -8° → 0°, teks slide dari kiri, garis gold tumbuh dari kiri.

### Scene 5 — Grid Destinasi + Rating `660–810` (22–27 dtk)

- Grid 2×3 foto destinasi kecil (Seminyak, Ubud, Lombok, Raja Ampat, Bromo, Yogyakarta).
- Foto muncul staggered scale `0.85 → 1` + fade.
- Overlay teks: **“Dari Bali sampai Raja Ampat.”**
- Rating besar: **4.9/5** dengan bintang gold, subteks **“Dipercaya para pelancong.”**
- Pin lokasi gold muncul satu per satu dengan spring.

### Scene 6 — CTA `810–900` (27–30 dtk)

- Background espresso `#3E2723` dengan glow gold di tengah.
- Logo kecil di atas.
- Headline: **“Booking sekarang.”**
- Subteks: **“DAFF AIRBNB — Liburan impianmu menanti.”**
- Tombol CTA: **“Mulai Cari Properti”** dengan pulse halus (scale 1 → 1.04, 2 siklus).
- **QR code** masuk dari bawah dengan spring + fade pada frame 825, ditempatkan di kartu putih rounded 280×280 px, disertai teks **“Scan untuk booking”**.
- URL kecil di bawah QR: `10.227.43.170/PKK` *(ganti dengan domain asli saat sudah ada; QR harus di-generate ulang jika URL berubah)*.
- Gold line + fade out 10 frame terakhir.

---

## 6. Copywriting final

| Scene | Teks |
|---|---|
| 1 | `DAFF AIRBNB` / `Sewa Villa & Rumah Impianmu` |
| 2 | `BALI • LOMBOK • RAJA AMPAT` / `Liburan impian, tanpa ribet.` / `Villa, rumah, dan penginapan pilihan di seluruh Indonesia.` |
| 3 | `Properti pilihan` / `Harga transparan, stok real-time.` / nama properti, lokasi, harga, rating |
| 4 | `Cek ketersediaan real-time` / `Pembayaran aman` / `Konfirmasi cepat` + deskripsi singkat |
| 5 | `Dari Bali sampai Raja Ampat.` / `4.9/5` / `Dipercaya para pelancong.` |
| 6 | `Booking sekarang.` / `DAFF AIRBNB — Liburan impianmu menanti.` / `Mulai Cari Properti` / `Scan untuk booking` / `10.227.43.170/PKK` |

**Aturan copy:**
- Gunakan kalimat pendek, maksimal 2 baris per layar.
- Jangan menambahkan angka/klaim yang tidak ada datanya.
- Gunakan tanda baca Indonesia yang benar.

---

## 7. Aset

Copy aset ke `public/` (jangan memakai path absolut):

| Aset | Sumber | Tujuan |
|---|---|---|
| Logo | `image-removebg-preview.png` | `public/logo.png` |
| Hero | `images/hero-villa.jpg` | `public/images/hero-villa.jpg` |
| Destinasi | `images/properties/seminyak-1.jpg`, `ubud-1.jpg`, `lombok-1.jpg`, `raja-ampat-1.jpg`, `bromo-1.jpg`, `yogyakarta-1.jpg` | `public/images/` |
| Kartu 2 | `images/properties/ubud-2.jpg` | `public/images/` |
| Kartu 3 | `images/properties/raja-ampat-2.jpg` | `public/images/` |
| Pola batik | buat SVG sederhana sendiri | `public/patterns/batik.svg` |
| QR code | `promo-assets/qr/qr-lan.png` | `public/qr/qr-lan.png` |
| Kartu QR | `promo-assets/qr/qr-card.png` | `public/qr/qr-card.png` (opsional untuk end card/poster) |
| Musik | `public/audio/tropical-house.mp3` (royalty-free, 120 BPM) | opsional |
| SFX | whoosh, pop, chime (royalty-free) | `public/audio/sfx/` |

**Aturan aset:**
- Jika musik/SFX tidak tersedia, render tetap harus berhasil tanpa audio.
- Jangan mengunduh aset dari internet saat render; semua aset lokal.
- Optimalkan foto agar total folder `public/` < 15 MB.

---

## 8. Aturan animasi

- Gunakan `useCurrentFrame()` dan `useVideoConfig()` dari Remotion.
- Entrance utama memakai `spring({ frame, fps, config: { damping: 18–22, stiffness: 110–140, mass: 0.9–1.1 } })`.
- Fade/opacity memakai `interpolate()` dengan `extrapolateLeft: 'clamp'`, `extrapolateRight: 'clamp'`.
- Easing: `Easing.out(Easing.cubic)` untuk pergerakan, `Easing.inOut(Easing.quad)` untuk transisi.
- Stagger teks: 3–5 frame per kata.
- Durasi animasi UI: 12–20 frame. Jangan lebih dari 24 frame kecuali Ken Burns.
- Ken Burns: `scale 1.06 → 1.14` sepanjang durasi scene, `translateY 0 → -20px`.
- Transisi antar scene memakai `@remotion/transitions` (`fade()`, `slide()`, atau `wipe()`), durasi 12–18 frame.
- Hindari animasi yang menyebabkan layout shift atau teks keluar safe area.
- Gunakan `AbsoluteFill` untuk layer background, overlay, dan konten.

---

## 9. Struktur project yang diminta

```text
promo-video/
├─ package.json
├─ tsconfig.json
├─ remotion.config.ts
├─ README.md
├─ public/
│  ├─ logo.png
│  ├─ images/...
│  ├─ patterns/batik.svg
│  ├─ qr/qr-lan.png
│  └─ audio/...
└─ src/
   ├─ index.ts
   ├─ Root.tsx
   ├─ DaffPromo.tsx
   ├─ theme.ts
   ├─ components/
   │  ├─ Logo.tsx
   │  ├─ GoldDivider.tsx
   │  ├─ PropertyCard.tsx
   │  ├─ FeatureRow.tsx
   │  └─ SafeArea.tsx
   └─ scenes/
      ├─ SceneLogo.tsx
      ├─ SceneDestinations.tsx
      ├─ SceneProperties.tsx
      ├─ SceneFeatures.tsx
      ├─ SceneGrid.tsx
      └─ SceneCTA.tsx
```

**Ketentuan teknis:**
- TypeScript strict, tanpa `any`.
- `registerRoot()` di `src/index.ts`; Composition di `src/Root.tsx`.
- `defaultProps` boleh dipakai untuk data properti.
- Tidak boleh ada error/warning di console saat `npm run studio`.
- `README.md` berisi cara install, preview, render, dan cara mengganti musik/domain.

---

## 10. Perintah & render

`package.json` minimal:

```json
{
  "scripts": {
    "studio": "remotion studio",
    "render": "remotion render DaffAirbnbPromo out/daff-airbnb-promo.mp4 --codec=h264 --crf=18 --pixel-format=yuv420p",
    "render:15s": "remotion render DaffAirbnbPromo15 out/daff-airbnb-promo-15s.mp4 --codec=h264 --crf=18 --pixel-format=yuv420p"
  }
}
```

Perintah verifikasi:

```powershell
cd promo-video
npm install
npm run studio   # preview di browser
npm run render   # hasil: out/daff-airbnb-promo.mp4
```

---

## 11. Acceptance checklist

- [ ] `npm install` dan `npm run render` berhasil dari clone bersih.
- [ ] Durasi tepat 30 detik (900 frame), 1080×1920, 30 fps.
- [ ] Tidak ada frame hitam, gambar hilang, atau teks terpotong.
- [ ] Logo, warna, dan font konsisten dengan design system.
- [ ] Semua teks Bahasa Indonesia dan tidak ada typo.
- [ ] Teks utama terbaca di layar HP (minimal 28 px, kontras ≥ 4.5:1).
- [ ] Animasi halus, tidak ada bounce berlebihan.
- [ ] QR code tampil minimal 2 detik dan berhasil discan dari hasil render.
- [ ] QR hanya mengarah ke URL yang benar (`http://10.227.43.170/PKK/` untuk demo LAN).
- [ ] Musik/SFX sinkron dan tidak clipping (jika audio disertakan).
- [ ] Ukuran file MP4 wajar (< 20 MB).
- [ ] `README.md` menjelaskan cara mengganti domain, musik, dan foto.
- [ ] Aplikasi PHP di root repo tidak berubah.

---

## 12. Variasi opsional

### Versi 15 detik

Composition `DaffAirbnbPromo15`, 450 frame:
- Scene 1 dipersingkat jadi 45 frame.
- Scene 2 jadi 90 frame.
- Scene 3 jadi 120 frame.
- Scene 4 jadi 90 frame.
- Scene 5 dilewati.
- Scene 6 jadi 105 frame.

### Versi 16:9 (YouTube)

Composition `DaffAirbnbPromoWide`, 1920×1080, 30 fps:
- Layout dua kolom: teks di kiri, visual di kanan.
- Tetap gunakan design system dan copy yang sama.
- Safe area 120 px.

### Voice-over (opsional)

Script 30 detik, tempo santai:

> “Liburan impian dimulai di sini. Temukan villa, rumah, dan penginapan pilihan di seluruh Indonesia. Harga transparan, stok real-time, dan pembayaran yang aman. Dari Bali sampai Raja Ampat. Booking sekarang di DAFF AIRBNB — liburan impianmu menanti.”

---

## 13. Catatan untuk agent

- Jangan memodifikasi file PHP, SQL, atau aset aplikasi utama.
- Jangan meng-commit `node_modules/`, `out/`, atau file audio berhak cipta.
- Tambahkan `promo-video/node_modules/` dan `promo-video/out/` ke `.gitignore`.
- Jika ada instruksi yang bertentangan, prioritaskan: (1) acceptance checklist, (2) design system, (3) storyboard.
- Setelah selesai, laporkan: struktur file, perintah render, dan path hasil video.
