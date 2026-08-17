# 🎓 Aplikasi Cek Kelulusan Siswa

Sistem informasi sederhana, cepat, dan elegan untuk memfasilitasi pengecekan status kelulusan siswa. Proyek ini bersifat *open-source* dan dirancang agar mudah diimplementasikan maupun dikembangkan lebih lanjut oleh pihak sekolah, instansi, maupun pengembang independen.

## ✨ Fitur Utama

* **Manajemen Data Siswa**: Tambah, hapus, dan kelola data siswa dengan mudah melalui *dashboard* admin.
* **Import Data Massal**: Dukungan impor data siswa secara massal menggunakan format *Excel*.
* **Antarmuka Modern**: UI/UX yang responsif dan ramah pengguna, dibangun menggunakan **Tailwind CSS** dan ikon dari **FontAwesome**.
* **Routing URL (SEO Friendly)**: Berjalan optimal di Web Server Apache dengan dukungan `mod_rewrite`.
* **Pengecekan Mandiri**: Halaman publik yang memudahkan siswa atau wali murid mengecek status kelulusan secara *real-time*.

---

## 🛠️ Teknologi yang Digunakan

* **Backend**: PHP 8+ (Native)
* **Database**: MySQL / MariaDB
* **Frontend**: Tailwind CSS, FontAwesome
* **Web Server**: Apache 2 (dengan `mod_rewrite` aktif)

---

## ⚙️ Persyaratan Sistem

Sebelum melakukan instalasi, pastikan lingkungan server Anda telah memenuhi kriteria berikut:
1. **Web Server Lokal / Hosting**: XAMPP, WAMP, Laragon, atau cPanel.
2. **PHP**: Versi 8.0 atau yang lebih baru.
3. **Database**: MySQL atau MariaDB.
4. **Ekstensi Apache**: `mod_rewrite` harus dalam keadaan aktif (*enabled*).

---

## 🚀 Panduan Instalasi

Ikuti langkah-langkah di bawah ini untuk menjalankan aplikasi di *local server* (XAMPP/WAMP/Laragon):

### 1. Persiapan File
* Unduh repositori ini atau *clone* menggunakan Git:
  ```bash
  git clone https://github.com/agus-salim/Aplikasi-Cek-Kelulusan.git
  ```
* Ekstrak (atau pindahkan) folder proyek ke dalam direktori root web server Anda:
  * **XAMPP**: `C:\xampp\htdocs\`
  * **WAMP**: `C:\wamp\www\`
  * **Laragon**: `C:\laragon\www\`

### 2. Konfigurasi Database
* Buka **phpMyAdmin** melalui browser: `http://localhost/phpmyadmin`
* Buat database baru (contoh: `mtsn_sekadau`).
* Pilih database tersebut, lalu masuk ke tab **Import** dan unggah file `database.sql` yang tersedia di dalam folder proyek.
* *(Alternatif)*: Anda juga dapat menyalin dan menjalankan *query* SQL yang ada di dalam file `database.sql` secara manual melalui tab *SQL*.

### 3. Konfigurasi Koneksi Aplikasi
* Buka file `config.php` yang berada di direktori utama proyek menggunakan *code editor* (VS Code, Sublime, Notepad++, dll).
* Sesuaikan kredensial database dengan pengaturan server Anda:
  ```php
  define('DB_HOST', 'localhost');
  define('DB_USER', 'root');
  define('DB_PASS', ''); // Kosongkan jika menggunakan XAMPP/WAMP default
  define('DB_NAME', 'mtsn_sekadau'); // Sesuaikan dengan nama database yang Anda buat
  ```
* Simpan perubahan file `config.php`.

---

## 🔐 Akses Dashboard Admin

Setelah instalasi selesai, Anda dapat mengakses halaman administrator melalui browser:

* **URL Admin**: `http://localhost/nama-folder-proyek/admin`
* **Username**: `admin`
* **Password**: `admin123`

---

## 📊 Panduan Import Data via Excel

Untuk mengimpor data siswa dalam jumlah banyak, ikuti prosedur berikut:
1. Login ke halaman Admin.
2. Klik tombol **Download Template** untuk mengunduh format Excel yang telah disediakan.
3. Buka file Excel tersebut dan isi data dengan format kolom sebagai berikut:
   * **NISN**: Nomor Induk Siswa Nasional
   * **Nama**: Nama Lengkap Siswa
   * **Status**: Gunakan angka `1` untuk **Lulus**, dan `0` untuk **Tidak Lulus**.
4. Simpan file Excel, lalu kembali ke halaman Admin dan gunakan menu **Import Excel** untuk mengunggah file tersebut.

---

## ⚠️ Rekomendasi Keamanan & Pemeliharaan

Demi menjaga keamanan dan integritas data aplikasi, sangat disarankan untuk:
1. **Mengganti Password Default**: Segera ubah *password* akun `admin` melalui pengaturan di halaman administrator setelah instalasi pertama.
2. **Backup Database Berkala**: Lakukan ekspor (*export*) database secara rutin melalui phpMyAdmin untuk mencegah kehilangan data akibat *error* sistem atau hal yang tidak diinginkan.
3. **Hapus File Sensitif**: Jika diunggah ke *hosting* publik, pastikan direktori atau file konfigurasi tidak dapat diakses secara langsung oleh publik.

---

## 📄 Lisensi & Kontribusi

Aplikasi ini dirilis sebagai perangkat lunak *Open-Source*. Anda bebas menggunakan, memodifikasi, dan mendistribusikan kembali kode sumber ini untuk keperluan pendidikan, instansi, maupun komersial.

Kontribusi berupa *Pull Request*, pelaporan *Bug* (*Issues*), maupun saran pengembangan sangat diapresiasi.

---

<p align="center">
  <b>Selamat Menggunakan & Semoga Bermanfaat!</b>
</p>
