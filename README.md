**Aplikasi Cek Kelulusan**

Aplikasi Cek Kelulusan sederhana yang dibangun dengan PHP 8 dan database MySQL. Tampilan saya percayakan Tailwind CSS dan Fontawesome. 
Aplikasi ini opensource, sehingga bisa digunakan atau dikembangkan. Aplikasi ini berjalan baik di webserver Apache 2 karena menggunakan mode rewrite.

1. PERSIAPAN:
   - Pastikan sudah terinstall XAMPP/WAMP/Laragon dengan PHP 8+ dan MySQL
   - Extract file ke folder htdocs (XAMPP) atau www (WAMP)

2. DATABASE:
   - Buka phpMyAdmin (http://localhost/phpmyadmin)
   - Buat database baru atau import file database.sql
   - Atau jalankan query SQL yang ada di file database.sql

3. KONFIGURASI:
   - Buka file config.php
   - Sesuaikan DB_HOST, DB_USER, DB_PASS, dan DB_NAME
   - Default: localhost, root, (kosong), mtsn_sekadau

4. AKSES APLIKASI:
   - Username Admin: admin
   - Password Admin: admin123

5. FITUR ADMIN:
   - Tambah data siswa secara manual
   - Import data dari Excel
   - Download template Excel/CSV
   - Hapus data siswa

6. CARA IMPORT EXCEL:
   - Download template CSV terlebih dahulu
   - Isi data sesuai format (NISN, Nama, Status)
   - Status: 1 = Lulus, 0 = Tidak Lulus
   - Upload file CSV melalui menu Import

7. KEAMANAN:
   - Ganti password admin halaman admin
   - Backup database secara berkala

SELAMAT MENGGUNAKAN!
