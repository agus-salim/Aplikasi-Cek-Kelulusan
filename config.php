<?php
/**
 * File Konfigurasi Database & Helper Functions
 * Sistem Cek Kelulusan Sekolah
 * 
 * @version 2.1
 * @last_updated 2026-06-07
 */

// ==================== KONFIGURASI DATABASE ====================
define('DB_HOST', 'localhost');
define('DB_USER', 'userdb');
define('DB_PASS', 'passwordkalian');
define('DB_NAME', 'namadb');
define('DB_CHARSET', 'utf8mb4');

// ==================== KONFIGURASI APLIKASI ====================
define('APP_NAME', 'Cek Kelulusan');
define('APP_VERSION', '2.1');
define('APP_TIMEZONE', 'Asia/Jakarta');

date_default_timezone_set(APP_TIMEZONE);

// ==================== ERROR HANDLING ====================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// ==================== FUNGSI KONEKSI DATABASE ====================
function getDbConnection() {
    static $conn = null;
    if ($conn !== null && $conn->ping()) return $conn;
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$conn->set_charset(DB_CHARSET)) throw new Exception("Gagal mengatur charset: " . $conn->error);
        $conn->query("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        return $conn;
    } catch (mysqli_sql_exception $e) {
        error_log("Database connection error: " . $e->getMessage());
        throw new Exception("Koneksi database gagal. Silakan periksa konfigurasi.");
    }
}

// ==================== FUNGSI HELPER PENGATURAN ====================
function getPengaturan($conn, $kunci) {
    try {
        $stmt = $conn->prepare("SELECT nilai FROM pengaturan WHERE kunci = ?");
        if (!$stmt) return null;
        $stmt->bind_param("s", $kunci); $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) { $row = $result->fetch_assoc(); $stmt->close(); return $row['nilai']; }
        $stmt->close(); return null;
    } catch (Exception $e) { error_log("getPengaturan error: " . $e->getMessage()); return null; }
}

function updatePengaturan($conn, $kunci, $nilai) {
    try {
        $stmt = $conn->prepare("UPDATE pengaturan SET nilai = ? WHERE kunci = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ss", $nilai, $kunci); $result = $stmt->execute(); $stmt->close();
        return $result;
    } catch (Exception $e) { error_log("updatePengaturan error: " . $e->getMessage()); return false; }
}

function tambahPengaturan($conn, $kunci, $nilai, $keterangan = '') {
    try {
        $stmt = $conn->prepare("INSERT INTO pengaturan (kunci, nilai, keterangan) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)");
        if (!$stmt) return false;
        $stmt->bind_param("sss", $kunci, $nilai, $keterangan); $result = $stmt->execute(); $stmt->close();
        return $result;
    } catch (Exception $e) { error_log("tambahPengaturan error: " . $e->getMessage()); return false; }
}

// ==================== FUNGSI HELPER TEMA ====================
function getTemaCSS($connParam = null) {
    $db = $connParam ?? ($GLOBALS['conn'] ?? null);
    if (!$db) return '';
    $tema = getPengaturan($db, 'tema_aktif') ?: 'asli';
    $allowed = ['asli', 'cool', 'warm', 'bright', 'dark'];
    if (!in_array($tema, $allowed)) $tema = 'asli';
    $cssFile = __DIR__ . '/tema/' . $tema . '.css';
    if ($tema === 'asli' || !file_exists($cssFile)) return '';
    return 'tema/' . $tema . '.css';
}

// ==================== FUNGSI HELPER LAINNYA ====================
function sanitizeInput($data) { return htmlspecialchars(trim(stripslashes($data)), ENT_QUOTES, 'UTF-8'); }
function formatTanggalIndonesia($date, $withTime = true) {
    try {
        $dt = new DateTime($date);
        $bulan = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
        $fmt = $dt->format('d') . ' ' . $bulan[(int)$dt->format('n')] . ' ' . $dt->format('Y');
        return $withTime ? $fmt . ', ' . $dt->format('H:i') . ' WIB' : $fmt;
    } catch (Exception $e) { return $date; }
}
function formatTanggalSKL($date) { return formatTanggalIndonesia($date, false); }
function redirect($url) {
    if (!headers_sent()) { header('Location: ' . $url); exit; }
    echo '<script>window.location.href="'.htmlspecialchars($url).'";</script>'; exit;
}
function isAdminLoggedIn() { return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true; }
function getAdminUsername() { return $_SESSION['admin_username'] ?? 'Admin'; }

// ==================== FUNGSI UPLOAD FILE ====================
function uploadFile($file, $folder = 'uploads', $prefix = '') {
    $res = ['success'=>false, 'path'=>'', 'error'=>''];
    if (!isset($file) || $file['error']!==UPLOAD_ERR_OK) { $res['error']='File tidak valid!'; return $res; }
    if (!in_array($file['type'], ['image/jpeg','image/jpg','image/png','image/webp'])) { $res['error']='Format harus JPG/PNG/WEBP!'; return $res; }
    if ($file['size']>2097152) { $res['error']='Maksimal 2MB!'; return $res; }
    $dir = __DIR__.'/'.$folder;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = $prefix.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
    if (move_uploaded_file($file['tmp_name'], $dir.'/'.$name)) {
        $res['success']=true; $res['path']=$folder.'/'.$name;
    } else { $res['error']='Gagal upload!'; }
    return $res;
}
function deleteFile($path) {
    if (!empty($path) && file_exists(__DIR__.'/'.$path)) return unlink(__DIR__.'/'.$path);
    return false;
}

// ==================== AUTO SETUP DATABASE ====================
function autoSetupDatabase($conn) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS pengaturan (
            id INT AUTO_INCREMENT PRIMARY KEY, kunci VARCHAR(50) NOT NULL UNIQUE, nilai TEXT NOT NULL,
            keterangan VARCHAR(255), updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_kunci (kunci)
        )");
        $defaultSettings = [
            ['admin_username', 'admin', 'Username login admin'],
            ['admin_password', password_hash('admin123', PASSWORD_DEFAULT), 'Password login admin (hashed)'],
            ['waktu_mulai_cek', '2026-06-10 08:00:00', 'Waktu mulai pengecekan kelulusan'],
            ['tahun_pelajaran', '2025/2026', 'Tahun pelajaran aktif'],
            ['nama_sekolah', 'MTsN 1 Sekadau', 'Nama sekolah'],
            ['logo_sekolah', '', 'Path file logo sekolah'], // BARU DITAMBAHKAN
            ['nomor_wa', '6285752604496', 'Nomor WhatsApp untuk kontak'],
            ['tema_aktif', 'asli', 'Tema tampilan aplikasi'],
            ['skl_nama_kabupaten', 'Sekadau', 'Nama kabupaten untuk SKL'],
            ['skl_aktif', '1', 'Status aktif/nonaktif fitur SKL'],
            ['skl_nomor_surat', '421/MTsN1-SEK/{status}/2026', 'Nomor surat SKL'],
            ['skl_tanggal_surat', date('Y-m-d'), 'Tanggal surat SKL'],
            ['skl_isi_surat', 'Menerangkan dengan sesungguhnya bahwa siswa tersebut di atas adalah benar-benar siswa {sekolah} Tahun Pelajaran {tahun} dan telah menyelesaikan seluruh program pendidikan dengan baik.', 'Isi surat SKL'],
            ['skl_nama_kepala', '', 'Nama kepala sekolah'],
            ['skl_jabatan_kepala', 'Kepala Madrasah', 'Jabatan kepala sekolah'],
            ['skl_nip_kepala', '', 'NIP kepala sekolah'],
            ['skl_ttd_kepala', '', 'Path file tanda tangan + cap kepala sekolah'],
            ['skl_status_kelulusan', '1', 'Status kelulusan default SKL']
        ];
        foreach ($defaultSettings as $s) {
            $chk = $conn->prepare("SELECT COUNT(*) as count FROM pengaturan WHERE kunci = ?");
            $chk->bind_param("s", $s[0]); $chk->execute();
            $res = $chk->get_result()->fetch_assoc(); $chk->close();
            if ($res['count'] == 0) {
                $stmt = $conn->prepare("INSERT INTO pengaturan (kunci, nilai, keterangan) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $s[0], $s[1], $s[2]); $stmt->execute(); $stmt->close();
            }
        }
        return true;
    } catch (Exception $e) { error_log("Auto setup database error: " . $e->getMessage()); return false; }
}

// ==================== INISIALISASI ====================
if (session_status() === PHP_SESSION_NONE) session_start();
try {
    $conn = getDbConnection();
    autoSetupDatabase($conn);
    $GLOBALS['conn'] = $conn;
} catch (Exception $e) { error_log("Init error: " . $e->getMessage()); }
?>