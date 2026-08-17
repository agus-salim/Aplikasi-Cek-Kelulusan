-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jun 07, 2026 at 09:10 AM
-- Server version: 10.11.14-MariaDB-0+deb12u2
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cek_kelulusan`
--

-- --------------------------------------------------------

--
-- Table structure for table `pengaturan`
--

CREATE TABLE `pengaturan` (
  `id` int(11) NOT NULL,
  `kunci` varchar(50) NOT NULL,
  `nilai` text NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `pengaturan`
--

INSERT INTO `pengaturan` (`id`, `kunci`, `nilai`, `keterangan`, `updated_at`) VALUES
(1, 'waktu_mulai_cek', '2027-06-07 08:00:00', 'Waktu mulai pengecekan kelulusan', '2026-06-07 01:30:22'),
(2, 'tahun_pelajaran', '2026/2027', 'Tahun pelajaran aktif', '2026-06-06 10:06:18'),
(3, 'nama_sekolah', 'Nama Sekolah', 'Nama sekolah', '2026-06-07 00:27:40'),
(4, 'nomor_wa', '6285387669820', 'Nomor WhatsApp untuk kontak', '2026-06-07 00:28:01'),
(5, 'skl_nomor_surat', '421/MTsN1-SEK/{status}/2026', 'Nomor surat SKL', '2026-06-06 11:09:00'),
(6, 'skl_tanggal_surat', '2026-06-07', 'Tanggal surat SKL', '2026-06-07 00:28:25'),
(7, 'skl_isi_surat', 'Menerangkan dengan sesungguhnya bahwa siswa tersebut di atas adalah benar-benar siswa {sekolah} Tahun Pelajaran {tahun} dan telah menyelesaikan seluruh program pendidikan dengan baik, sehingga siswa tersebut dinyatakan LULUS.', 'Isi surat SKL', '2026-06-06 15:35:41'),
(8, 'skl_nama_kepala', 'Samsul Mu\'arif, S.Ag', 'Nama kepala sekolah', '2026-06-06 11:10:19'),
(9, 'skl_jabatan_kepala', 'Kepala Madrasah', 'Jabatan kepala sekolah', '2026-06-06 11:09:00'),
(10, 'skl_nip_kepala', '199004192025051003', 'NIP kepala sekolah', '2026-06-06 11:10:19'),
(11, 'skl_status_kelulusan', '1', 'Status kelulusan default SKL', '2026-06-06 11:09:00'),
(12, 'skl_nama_kabupaten', 'Sekadau', 'Nama kabupaten untuk SKL', '2026-06-06 11:49:16'),
(13, 'skl_kop_surat', 'uploads/kop_1780756745_9cf06ab7.jpg', 'Path file kop surat SKL', '2026-06-06 14:39:05'),
(14, 'skl_ttd_kepala', 'uploads/ttd_1780755234_32ad2f9f.png', 'Path file tanda tangan kepala sekolah', '2026-06-06 14:13:54'),
(24, 'skl_aktif', '1', 'Status aktif/nonaktif fitur SKL (1=aktif, 0=nonaktif)', '2026-06-06 23:37:47'),
(25, 'tema_aktif', 'asli', 'Tema tampilan aplikasi (asli/cool/warm/bright/dark)', '2026-06-07 01:27:59'),
(26, 'admin_username', 'admin', 'Username login admin', '2026-06-07 00:02:04'),
(27, 'admin_password', '$2y$10$nJ5xYuV5es1Yo3xD9xPHtOxZGWiMaVumc4uO5c5n6To6oG0ST2KDW', 'Password login admin (hashed)', '2026-06-07 00:02:04');

-- --------------------------------------------------------

--
-- Table structure for table `siswa`
--

CREATE TABLE `siswa` (
  `id` int(11) NOT NULL,
  `nisn` varchar(20) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `status_kelulusan` tinyint(1) NOT NULL COMMENT '1=Lulus, 0=Tidak Lulus',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `siswa`
--

INSERT INTO `siswa` (`id`, `nisn`, `nama`, `status_kelulusan`, `created_at`, `updated_at`) VALUES
(136, '0119807323', 'ADE IQBAL APRIYAN', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(138, '3115079921', 'AFRIZAL ADIWANGSA MAWAVI', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(139, '0102234911', 'AHMAD ARIF IRFANSYAH', 0, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(140, '0105898553', 'AILA RIZQIA', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(141, '0119039023', 'AISYKA TANISHA', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(142, '0114719704', 'AJI SAPUTRO', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(143, '0117372645', 'ANNISA RAHMADINI', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(144, '0112496519', 'AURELIA NIKITA PUTRI', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(145, '0112285930', 'BELLA', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(146, '0104378019', 'CIKO PRATAMA', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(147, '0112350519', 'EVIANA', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(148, '0115590537', 'FEBY ARINA PUTRI', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(149, '0115914174', 'FEBY NATASYA', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(150, '0112026175', 'FITRIA A\'IDA FADILLA', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(151, '0117711910', 'KHAILA INDRI', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(152, '0116445343', 'MUHAMMAD ABI WAQAS', 1, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(153, '0118257845', 'MUHAMMAD IBNU ZULFIKAR', 0, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(154, '0109928462', 'MUHAMMAD LUTFI KURNIAWAN', 0, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(155, '0117632617', 'NEISHYLLA NORMAN', 0, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(156, '0115417740', 'NENI DOTI ZABELA', 0, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(157, '0117222627', 'SUWANDI FIRMASAH', 0, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(158, '0108309836', 'SYARIF DWI RAMADAN', 0, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(159, '0112817296', 'SYIFAURRAHMAH', 0, '2026-06-06 09:34:00', '2026-06-06 09:34:00'),
(160, '0111505728', 'TIARA AMANDA PRATAMA', 0, '2026-06-06 09:34:00', '2026-06-06 09:34:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `pengaturan`
--
ALTER TABLE `pengaturan`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kunci` (`kunci`);

--
-- Indexes for table `siswa`
--
ALTER TABLE `siswa`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nisn` (`nisn`),
  ADD KEY `idx_nisn` (`nisn`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `pengaturan`
--
ALTER TABLE `pengaturan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `siswa`
--
ALTER TABLE `siswa`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=164;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
