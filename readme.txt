CARA INSTALASI SISTEM CEK KELULUSAN MTsN 1 SEKADAU
===================================================

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

4. LOGO:
   - Simpan logo yang Anda upload dengan nama "logo.png" 
   - di folder yang sama dengan file index.php

5. AKSES APLIKASI:
   - Halaman Siswa: http://localhost/mtsn_kelulusan/
   - Halaman Admin: http://localhost/mtsn_kelulusan/admin.php
   - Username Admin: admin
   - Password Admin: admin123

6. FITUR ADMIN:
   - Tambah data siswa secara manual
   - Import data dari Excel (format CSV)
   - Download template Excel/CSV
   - Hapus data siswa

7. CARA IMPORT EXCEL:
   - Download template CSV terlebih dahulu
   - Isi data sesuai format (NISN, Nama, Status)
   - Status: 1 = Lulus, 0 = Tidak Lulus
   - Upload file CSV melalui menu Import

8. KEAMANAN:
   - Ganti password admin di file admin.php
   - Backup database secara berkala
   - Jangan share kredensial admin

SELAMAT MENGGUNAKAN!