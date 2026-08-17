<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

if (!file_exists(__DIR__ . '/config.php')) {
    die('File config.php tidak ditemukan!');
}

require_once __DIR__ . '/config.php';

/**
 * Escape output HTML.
 */
function admin_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Ambil pengaturan dengan fallback.
 */
function admin_setting(mysqli $conn, string $key, string $default = ''): string
{
    try {
        $value = getPengaturan($conn, $key);
    } catch (Throwable $e) {
        return $default;
    }

    if (!is_scalar($value)) {
        return $default;
    }

    $value = trim((string) $value);

    return $value === '' ? $default : $value;
}

/**
 * Format nomor WA untuk link wa.me: 62812...
 */
function admin_format_wa_link(string $number): string
{
    $number = preg_replace('/[^0-9+]/', '', $number) ?? '';
    $number = ltrim($number, '+');
    $number = preg_replace('/\D+/', '', $number) ?? '';

    if (strpos($number, '0') === 0) {
        return '62' . substr($number, 1);
    }

    if (strpos($number, '8') === 0) {
        return '62' . $number;
    }

    return $number;
}

/**
 * Format nomor WA untuk tampilan: 0812...
 */
function admin_format_wa_display(string $number): string
{
    $number = preg_replace('/\D+/', '', $number) ?? '';

    if (strpos($number, '62') === 0 && strlen($number) > 2) {
        return '0' . substr($number, 2);
    }

    return $number;
}

/**
 * Ambil count dari query sederhana.
 */
function admin_count(mysqli $conn, string $sql): int
{
    try {
        $result = $conn->query($sql);

        if (!$result) {
            return 0;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return (int) ($row['count'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Cek apakah baris import adalah header.
 */
function admin_is_header_row(string $value): bool
{
    $value = strtolower(trim($value));

    $headers = [
        'nisn',
        'nama siswa',
        'nama',
        'status',
        'cetak skl',
        'no',
        'number',
        'template',
        'keterangan',
    ];

    return in_array($value, $headers, true)
        || strpos($value, 'nisn') !== false
        || strpos($value, 'template') !== false;
}

/**
 * Proses satu baris import siswa.
 */
function admin_process_student_row(mysqli $conn, string $nisn, string $nama, string $status, string $cetak): bool
{
    try {
        $nisn = trim($nisn);
        $nama = trim($nama);

        if ($nisn === '' || $nama === '') {
            return false;
        }

        if (!is_numeric($status) || !is_numeric($cetak)) {
            return false;
        }

        $statusInt = (int) $status;
        $cetakInt = (int) $cetak;

        if (!in_array($statusInt, [0, 1], true)) {
            return false;
        }

        if (!in_array($cetakInt, [0, 1], true)) {
            $cetakInt = 1;
        }

        $sql = "INSERT INTO siswa (nisn, nama, status_kelulusan, cetak_skl)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    nama = VALUES(nama),
                    status_kelulusan = VALUES(status_kelulusan),
                    cetak_skl = VALUES(cetak_skl)";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ssii', $nisn, $nama, $statusInt, $cetakInt);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

if (!isset($conn) || !$conn instanceof mysqli) {
    try {
        $conn = getDbConnection();
    } catch (Throwable $e) {
        error_log('Admin DB connection error: ' . $e->getMessage());
        die('Koneksi database tidak tersedia.');
    }
}

if (!$conn instanceof mysqli) {
    die('Koneksi database tidak tersedia.');
}

$hasSpreadsheet = false;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        $hasSpreadsheet = true;
    } catch (Throwable $e) {
        error_log('Spreadsheet load error: ' . $e->getMessage());
    }
}

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    redirect('admin.php');
    exit;
}

// ==================== LOGIN ====================
if (!isAdminLoggedIn()) {
    $loginError = '';
    $loginSuccess = isset($_GET['msg']) && $_GET['msg'] === 'credentials_updated'
        ? 'Username & Password berhasil diubah! Silakan login.'
        : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'login')) {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));

        if ($username === '' || $password === '') {
            $loginError = 'Username dan password harus diisi!';
        } else {
            try {
                $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");

                if ($stmt) {
                    $stmt->bind_param('s', $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $user = $result ? $result->fetch_assoc() : null;
                    $stmt->close();
                } else {
                    $user = null;
                }

                if ($user && password_verify($password, (string) $user['password'])) {
                    session_regenerate_id(true);

                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_username'] = (string) $user['username'];
                    $_SESSION['user_id'] = (int) $user['id'];

                    redirect('admin.php');
                    exit;
                }

                // Fallback akun master dari tabel pengaturan.
                $dbUser = admin_setting($conn, 'admin_username', 'admin');
                $dbPassHash = admin_setting($conn, 'admin_password');

                if ($dbPassHash === '') {
                    $dbPassHash = password_hash('admin123', PASSWORD_DEFAULT);
                }

                if ($username === $dbUser && password_verify($password, $dbPassHash)) {
                    session_regenerate_id(true);

                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_username'] = $username;
                    $_SESSION['user_id'] = 0;

                    redirect('admin.php');
                    exit;
                }

                $loginError = 'Username atau password salah!';
            } catch (Throwable $e) {
                error_log('Admin login error: ' . $e->getMessage());
                $loginError = 'Terjadi kesalahan saat login.';
            }
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login Admin</title>

        <link rel="stylesheet" href="dist/output.css">
        <link rel="stylesheet" href="fontawesome.css">
        <link rel="stylesheet" href="fonts.css">

        <style>
            body { font-family: 'Fira Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        </style>
    </head>
    <body class="bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl p-8">
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-4 text-3xl">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                </div>

                <h2 class="text-2xl font-bold text-gray-800">Login Admin</h2>
                <p class="text-gray-500 text-sm mt-1">Sistem Cek Kelulusan V2.1</p>
            </div>

            <?php if ($loginError !== ''): ?>
                <div class="bg-red-50 text-red-700 p-3 rounded-lg text-sm mb-4 flex items-center gap-2" role="alert">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <span><?= admin_escape($loginError) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($loginSuccess !== ''): ?>
                <div class="bg-emerald-50 text-emerald-700 p-3 rounded-lg text-sm mb-4 flex items-center gap-2" role="status">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <span><?= admin_escape($loginSuccess) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4" autocomplete="off">
                <input type="hidden" name="action" value="login">

                <div>
                    <label for="login-username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text"
                           id="login-username"
                           name="username"
                           required
                           class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                </div>

                <div>
                    <label for="login-password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>

                    <div class="relative">
                        <input type="password"
                               id="login-password"
                               name="password"
                               required
                               class="w-full px-4 py-3 pr-12 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">

                        <button type="button"
                                class="js-toggle-password absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-emerald-600 transition-colors"
                                data-target="login-password"
                                aria-label="Lihat password"
                                title="Lihat Password">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl transition shadow-lg shadow-emerald-200">
                    Masuk
                </button>
            </form>

            <div class="text-center mt-6">
                <a href="index.php" class="text-sm text-gray-500 hover:text-emerald-600 transition flex items-center justify-center gap-1">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    Kembali ke Halaman Utama
                </a>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.js-toggle-password').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const input = document.getElementById(button.dataset.target);
                        const icon = button.querySelector('i');

                        if (!input || !icon) {
                            return;
                        }

                        const showPassword = input.type === 'password';

                        input.type = showPassword ? 'text' : 'password';

                        icon.classList.toggle('fa-eye', !showPassword);
                        icon.classList.toggle('fa-eye-slash', showPassword);
                    });
                });
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ==================== DASHBOARD / ADMIN ====================
$success = '';
$error = '';
$forcedTab = null;

$namaSekolah = admin_setting($conn, 'nama_sekolah', 'MTsN 1 Sekadau');
$tahunPelajaran = admin_setting($conn, 'tahun_pelajaran', '2025/2026');
$nomorWa = admin_setting($conn, 'nomor_wa', '6285752604496');
$logoSekolah = admin_setting($conn, 'logo_sekolah', 'logo.png');

$sklKop = admin_setting($conn, 'skl_kop_surat');
$sklNamaKabupaten = admin_setting($conn, 'skl_nama_kabupaten', 'Sekadau');
$sklAktif = admin_setting($conn, 'skl_aktif', '1');
$sklNomor = admin_setting($conn, 'skl_nomor_surat', '421/MTsN1-SEK/{status}/2026');
$sklTanggal = admin_setting($conn, 'skl_tanggal_surat', date('Y-m-d'));
$sklIsi = admin_setting($conn, 'skl_isi_surat', 'Menerangkan dengan sesungguhnya...');
$sklNamaKepala = admin_setting($conn, 'skl_nama_kepala');
$sklJabatan = admin_setting($conn, 'skl_jabatan_kepala', 'Kepala Madrasah');
$sklNip = admin_setting($conn, 'skl_nip_kepala');
$sklTtd = admin_setting($conn, 'skl_ttd_kepala');

$waktuMulaiCek = admin_setting($conn, 'waktu_mulai_cek');

// ==================== HAPUS USER ====================
if (isset($_GET['hapus_user']) && is_numeric($_GET['hapus_user'])) {
    $userIdToDelete = (int) $_GET['hapus_user'];
    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userIdToDelete !== $currentUserId) {
        try {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");

            if ($stmt) {
                $stmt->bind_param('i', $userIdToDelete);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('Admin delete user error: ' . $e->getMessage());
        }

        redirect('admin.php?success=user_deleted&tab=section-pengguna');
        exit;
    }

    $error = 'Anda tidak dapat menghapus akun Anda sendiri!';
    $forcedTab = 'section-pengguna';
}

// ==================== HAPUS SISWA ====================
if (isset($_GET['hapus_nisn']) && trim((string) $_GET['hapus_nisn']) !== '') {
    $nisnToDelete = trim((string) $_GET['hapus_nisn']);

    try {
        $stmt = $conn->prepare("DELETE FROM siswa WHERE nisn = ?");

        if ($stmt) {
            $stmt->bind_param('s', $nisnToDelete);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Admin delete siswa error: ' . $e->getMessage());
    }

    redirect('admin.php?success=deleted&tab=section-data-siswa');
    exit;
}

// ==================== HANDLE POST ACTIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];

    try {
        switch ($action) {
            case 'simpan_identitas':
                $namaBaru = trim((string) ($_POST['nama_sekolah'] ?? ''));
                $tahunBaru = trim((string) ($_POST['tahun_pelajaran'] ?? ''));

                if ($namaBaru === '' || $tahunBaru === '') {
                    $error = 'Nama Sekolah dan Tahun Pelajaran harus diisi!';
                    break;
                }

                $okNama = updatePengaturan($conn, 'nama_sekolah', $namaBaru);
                $okTahun = updatePengaturan($conn, 'tahun_pelajaran', $tahunBaru);

                if (isset($_FILES['logo_sekolah']) && $_FILES['logo_sekolah']['error'] === UPLOAD_ERR_OK) {
                    $upload = uploadFile($_FILES['logo_sekolah'], 'uploads', 'logo');

                    if (!empty($upload['success'])) {
                        $oldLogo = admin_setting($conn, 'logo_sekolah', '');

                        if ($oldLogo !== '') {
                            deleteFile($oldLogo);
                        }

                        updatePengaturan($conn, 'logo_sekolah', (string) $upload['path']);
                        $logoSekolah = (string) $upload['path'];
                    } else {
                        $error = $upload['error'] ?? 'Gagal mengunggah logo sekolah.';
                    }
                }

                if ($okNama && $okTahun && $error === '') {
                    $namaSekolah = $namaBaru;
                    $tahunPelajaran = $tahunBaru;
                    $success = 'Identitas berhasil diperbarui!';
                } elseif ($error === '') {
                    $error = 'Gagal menyimpan identitas!';
                }

                break;

            case 'simpan_wa':
                $waInput = trim((string) ($_POST['nomor_wa'] ?? ''));
                $waNormalized = admin_format_wa_link($waInput);
                $waDigits = preg_replace('/\D+/', '', $waNormalized) ?? '';

                if ($waInput === '') {
                    $error = 'Nomor WhatsApp harus diisi!';
                } elseif (strlen($waDigits) < 10) {
                    $error = 'Nomor WhatsApp tidak valid!';
                } elseif (updatePengaturan($conn, 'nomor_wa', $waNormalized)) {
                    $nomorWa = $waNormalized;
                    $success = 'Nomor WhatsApp diperbarui!';
                } else {
                    $error = 'Gagal menyimpan nomor WhatsApp!';
                }

                break;

            case 'simpan_skl':
                $sklFields = [
                    'nama_kabupaten' => 'skl_nama_kabupaten',
                    'nomor_surat' => 'skl_nomor_surat',
                    'tanggal_surat' => 'skl_tanggal_surat',
                    'isi_surat' => 'skl_isi_surat',
                    'status_kelulusan' => 'skl_status_kelulusan',
                    'nama_kepala' => 'skl_nama_kepala',
                    'jabatan_kepala' => 'skl_jabatan_kepala',
                    'nip_kepala' => 'skl_nip_kepala',
                    'skl_aktif' => 'skl_aktif',
                ];

                $ok = true;

                foreach ($sklFields as $postKey => $settingKey) {
                    if (!array_key_exists($postKey, $_POST)) {
                        continue;
                    }

                    $value = trim((string) $_POST[$postKey]);

                    if (!updatePengaturan($conn, $settingKey, $value)) {
                        $ok = false;
                    }
                }

                if (isset($_FILES['kop_surat']) && $_FILES['kop_surat']['error'] === UPLOAD_ERR_OK) {
                    $upload = uploadFile($_FILES['kop_surat'], 'uploads', 'kop');

                    if (!empty($upload['success'])) {
                        if ($sklKop !== '') {
                            deleteFile($sklKop);
                        }

                        updatePengaturan($conn, 'skl_kop_surat', (string) $upload['path']);
                        $sklKop = (string) $upload['path'];
                    } else {
                        $ok = false;

                        if ($error === '') {
                            $error = $upload['error'] ?? 'Gagal mengunggah kop surat.';
                        }
                    }
                }

                if (isset($_FILES['ttd_kepala']) && $_FILES['ttd_kepala']['error'] === UPLOAD_ERR_OK) {
                    $upload = uploadFile($_FILES['ttd_kepala'], 'uploads', 'ttd');

                    if (!empty($upload['success'])) {
                        if ($sklTtd !== '') {
                            deleteFile($sklTtd);
                        }

                        updatePengaturan($conn, 'skl_ttd_kepala', (string) $upload['path']);
                        $sklTtd = (string) $upload['path'];
                    } else {
                        $ok = false;

                        if ($error === '') {
                            $error = $upload['error'] ?? 'Gagal mengunggah tanda tangan/cap.';
                        }
                    }
                }

                if ($ok && $error === '') {
                    $sklNamaKabupaten = trim((string) ($_POST['nama_kabupaten'] ?? $sklNamaKabupaten));
                    $sklNomor = trim((string) ($_POST['nomor_surat'] ?? $sklNomor));
                    $sklTanggal = trim((string) ($_POST['tanggal_surat'] ?? $sklTanggal));
                    $sklIsi = trim((string) ($_POST['isi_surat'] ?? $sklIsi));
                    $sklNamaKepala = trim((string) ($_POST['nama_kepala'] ?? $sklNamaKepala));
                    $sklJabatan = trim((string) ($_POST['jabatan_kepala'] ?? $sklJabatan));
                    $sklNip = trim((string) ($_POST['nip_kepala'] ?? $sklNip));
                    $sklAktif = trim((string) ($_POST['skl_aktif'] ?? $sklAktif));

                    $success = 'Pengaturan SKL disimpan!';
                } elseif ($error === '') {
                    $error = 'Gagal menyimpan pengaturan SKL!';
                }

                break;

            case 'simpan_waktu':
                $waktuInput = trim((string) ($_POST['waktu_mulai'] ?? ''));

                if ($waktuInput === '') {
                    $error = 'Waktu mulai harus diisi!';
                    break;
                }

                $dt = DateTime::createFromFormat('Y-m-d\TH:i', $waktuInput);

                if ($dt && $dt->format('Y-m-d\TH:i') === $waktuInput) {
                    $formatted = $dt->format('Y-m-d H:i:00');

                    if (updatePengaturan($conn, 'waktu_mulai_cek', $formatted)) {
                        $waktuMulaiCek = $formatted;
                        $success = 'Waktu pengecekan diperbarui!';
                    } else {
                        $error = 'Gagal menyimpan waktu pengecekan!';
                    }
                } else {
                    $error = 'Format waktu tidak valid!';
                }

                break;

            case 'tambah_siswa':
                $nisn = trim((string) ($_POST['nisn'] ?? ''));
                $nama = trim((string) ($_POST['nama'] ?? ''));
                $status = (int) ($_POST['status'] ?? 0);

                if ($nisn === '' || $nama === '') {
                    $error = 'NISN dan nama siswa harus diisi!';
                } elseif (!ctype_digit($nisn)) {
                    $error = 'NISN hanya boleh berisi angka!';
                } elseif (!in_array($status, [0, 1], true)) {
                    $error = 'Status kelulusan tidak valid!';
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO siswa (nisn, nama, status_kelulusan, cetak_skl)
                         VALUES (?, ?, ?, 1)"
                    );

                    if ($stmt) {
                        $stmt->bind_param('ssi', $nisn, $nama, $status);

                        if ($stmt->execute()) {
                            $success = 'Siswa berhasil ditambahkan!';
                        } else {
                            $error = ($conn->errno === 1062)
                                ? 'NISN sudah terdaftar!'
                                : 'Gagal menambahkan siswa.';
                        }

                        $stmt->close();
                    } else {
                        $error = 'Gagal menyiapkan query tambah siswa.';
                    }
                }

                break;

            case 'tambah_pengguna':
                $usernameBaru = trim((string) ($_POST['new_username'] ?? ''));
                $passwordBaru = trim((string) ($_POST['new_password'] ?? ''));
                $konfirmasiPassword = trim((string) ($_POST['confirm_password'] ?? ''));

                if ($usernameBaru === '' || $passwordBaru === '' || $konfirmasiPassword === '') {
                    $error = 'Semua field pengguna harus diisi!';
                } elseif (strlen($usernameBaru) < 3) {
                    $error = 'Username minimal 3 karakter!';
                } elseif (strlen($passwordBaru) < 6) {
                    $error = 'Password minimal 6 karakter!';
                } elseif ($passwordBaru !== $konfirmasiPassword) {
                    $error = 'Password tidak cocok!';
                } else {
                    $hashedPassword = password_hash($passwordBaru, PASSWORD_DEFAULT);
                    $role = 'admin';

                    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");

                    if ($stmt) {
                        $stmt->bind_param('sss', $usernameBaru, $hashedPassword, $role);

                        if ($stmt->execute()) {
                            $success = 'Pengguna berhasil ditambahkan!';
                        } else {
                            $error = ($conn->errno === 1062)
                                ? 'Username sudah digunakan!'
                                : 'Gagal menambahkan pengguna.';
                        }

                        $stmt->close();
                    } else {
                        $error = 'Gagal menyiapkan query tambah pengguna.';
                    }
                }

                break;

            case 'update_pengguna':
                $userId = (int) ($_POST['user_id'] ?? 0);
                $usernameBaru = trim((string) ($_POST['new_username'] ?? ''));
                $passwordBaru = trim((string) ($_POST['new_password'] ?? ''));
                $konfirmasiPassword = trim((string) ($_POST['confirm_password'] ?? ''));

                if ($userId <= 0) {
                    $error = 'ID pengguna tidak valid!';
                } elseif ($usernameBaru === '') {
                    $error = 'Username tidak boleh kosong!';
                } elseif (strlen($usernameBaru) < 3) {
                    $error = 'Username minimal 3 karakter!';
                } elseif ($passwordBaru !== '' && strlen($passwordBaru) < 6) {
                    $error = 'Password minimal 6 karakter!';
                } elseif ($passwordBaru !== '' && $passwordBaru !== $konfirmasiPassword) {
                    $error = 'Password tidak cocok!';
                } else {
                    if ($passwordBaru !== '') {
                        $hashedPassword = password_hash($passwordBaru, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");

                        if ($stmt) {
                            $stmt->bind_param('ssi', $usernameBaru, $hashedPassword, $userId);
                        }
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");

                        if ($stmt) {
                            $stmt->bind_param('si', $usernameBaru, $userId);
                        }
                    }

                    if (isset($stmt) && $stmt) {
                        if ($stmt->execute()) {
                            $success = 'Pengguna berhasil diperbarui!';

                            if ($userId === (int) ($_SESSION['user_id'] ?? 0)) {
                                $_SESSION['admin_username'] = $usernameBaru;
                            }
                        } else {
                            $error = ($conn->errno === 1062)
                                ? 'Username sudah digunakan!'
                                : 'Gagal memperbarui pengguna.';
                        }

                        $stmt->close();
                    } else {
                        $error = 'Gagal menyiapkan query update pengguna.';
                    }
                }

                break;

            case 'simpan_cetak_skl':
                if (isset($_POST['all_nisn']) && is_array($_POST['all_nisn'])) {
                    $count = 0;

                    foreach ($_POST['all_nisn'] as $nisnItem) {
                        $nisnItem = trim((string) $nisnItem);

                        if ($nisnItem === '') {
                            continue;
                        }

                        $cetak = isset($_POST['cetak_skl'][$nisnItem]) ? 1 : 0;

                        $stmt = $conn->prepare("UPDATE siswa SET cetak_skl = ? WHERE nisn = ?");

                        if ($stmt) {
                            $stmt->bind_param('is', $cetak, $nisnItem);

                            if ($stmt->execute()) {
                                $count++;
                            }

                            $stmt->close();
                        }
                    }

                    $success = "Cetak SKL diperbarui untuk {$count} siswa!";
                } else {
                    $error = 'Tidak ada data untuk disimpan!';
                }

                break;

            case 'delete_selected':
                if (isset($_POST['selected_nisn']) && is_array($_POST['selected_nisn'])) {
                    $list = array_values(
                        array_filter(
                            array_map('trim', array_map('strval', $_POST['selected_nisn']))
                        )
                    );

                    if (empty($list)) {
                        $error = 'Tidak ada data yang dipilih!';
                        break;
                    }

                    $placeholders = implode(',', array_fill(0, count($list), '?'));
                    $stmt = $conn->prepare("DELETE FROM siswa WHERE nisn IN ($placeholders)");

                    if ($stmt) {
                        $types = str_repeat('s', count($list));
                        $stmt->bind_param($types, ...$list);

                        if ($stmt->execute()) {
                            $success = 'Berhasil menghapus ' . count($list) . ' data!';
                        } else {
                            $error = 'Gagal menghapus data yang dipilih!';
                        }

                        $stmt->close();
                    } else {
                        $error = 'Gagal menyiapkan query hapus data!';
                    }
                } else {
                    $error = 'Tidak ada data yang dipilih!';
                }

                break;

            case 'delete_all':
                if ($conn->query("DELETE FROM siswa") !== false) {
                    $success = 'SEMUA data siswa dihapus!';
                } else {
                    $error = 'Gagal menghapus semua data!';
                }

                break;

            case 'import':
                if (!isset($_FILES['file_excel']) || $_FILES['file_excel']['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Pilih file untuk import!';
                    break;
                }

                $file = $_FILES['file_excel'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                    $error = 'Format file tidak didukung! Gunakan xlsx, xls, atau csv.';
                    break;
                }

                if ($file['size'] > 10485760) {
                    $error = 'Ukuran file maksimal 10MB!';
                    break;
                }

                $imported = 0;
                $failed = 0;
                $skipped = 0;

                if ($ext === 'csv') {
                    $handle = fopen($file['tmp_name'], 'r');

                    if ($handle === false) {
                        $error = 'Gagal membaca file CSV!';
                        break;
                    }

                    while (($row = fgetcsv($handle, 0, ',')) !== false) {
                        $nisn = trim((string) ($row[0] ?? ''));
                        $nama = trim((string) ($row[1] ?? ''));
                        $status = trim((string) ($row[2] ?? ''));
                        $cetak = trim((string) ($row[3] ?? ''));

                        if ($status === '') {
                            $status = '1';
                        }

                        if ($cetak === '') {
                            $cetak = '1';
                        }

                        if (is_numeric($nisn)) {
                            $nisn = (string) (int) $nisn;
                        }

                        if ($nisn === '' || admin_is_header_row($nisn) || !ctype_digit($nisn)) {
                            $skipped++;
                            continue;
                        }

                        if (admin_process_student_row($conn, $nisn, $nama, $status, $cetak)) {
                            $imported++;
                        } else {
                            $failed++;
                        }
                    }

                    fclose($handle);
                } elseif ($hasSpreadsheet) {
                    try {
                        $readerType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($file['tmp_name']);
                        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readerType);
                        $reader->setReadDataOnly(true);

                        $spreadsheet = $reader->load($file['tmp_name']);
                        $sheet = $spreadsheet->getActiveSheet();
                        $highestRow = $sheet->getHighestRow();

                        for ($rowNumber = 1; $rowNumber <= $highestRow; $rowNumber++) {
                            $nisn = trim((string) ($sheet->getCell('A' . $rowNumber)->getValue() ?? ''));
                            $nama = trim((string) ($sheet->getCell('B' . $rowNumber)->getValue() ?? ''));
                            $status = trim((string) ($sheet->getCell('C' . $rowNumber)->getValue() ?? ''));
                            $cetak = trim((string) ($sheet->getCell('D' . $rowNumber)->getValue() ?? ''));

                            if (is_numeric($nisn)) {
                                $nisn = (string) (int) $nisn;
                            }

                            if ($status === '') {
                                $status = '1';
                            }

                            if ($cetak === '') {
                                $cetak = '1';
                            }

                            if ($nisn === '' || admin_is_header_row($nisn) || !ctype_digit($nisn)) {
                                $skipped++;
                                continue;
                            }

                            if (admin_process_student_row($conn, $nisn, $nama, $status, $cetak)) {
                                $imported++;
                            } else {
                                $failed++;
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('Admin import Excel error: ' . $e->getMessage());
                        $error = 'Gagal membaca file Excel.';
                    }
                } else {
                    $error = 'Library PhpSpreadsheet belum tersedia. Gunakan CSV atau instal Composer.';
                }

                if ($error === '') {
                    $success = "Import selesai! {$imported} berhasil, {$failed} gagal, {$skipped} dilewati.";
                }

                break;

            default:
                break;
        }
    } catch (Throwable $e) {
        error_log('Admin action error: ' . $e->getMessage());
        $error = 'Terjadi kesalahan saat memproses aksi.';
    }
}

if (isset($_GET['success']) && $success === '') {
    if ($_GET['success'] === 'deleted') {
            $success = 'Data siswa dihapus!';
        } elseif ($_GET['success'] === 'user_deleted') {
            $success = 'Pengguna dihapus!';
        }
}

// ==================== DOWNLOAD TEMPLATE ====================
if (isset($_GET['download_template'])) {
    if (!$hasSpreadsheet) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="template.csv"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Keterangan: Status (1=Lulus, 0=Tidak), Cetak SKL (1=Ya, 0=Tidak)']);
        fputcsv($output, ['NISN', 'Nama', 'Status', 'Cetak SKL']);
        fputcsv($output, ['123456', 'Contoh', '1', '1']);

        fclose($output);
        exit;
    }

    try {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'TEMPLATE DATA SISWA');
        $sheet->mergeCells('A1:D1');

        $sheet->setCellValue('A2', 'Keterangan: Status (1=Lulus, 0=Tidak), Cetak SKL (1=Ya, 0=Tidak)');
        $sheet->mergeCells('A2:D2');

        $sheet->setCellValue('A4', 'NISN');
        $sheet->setCellValue('B4', 'Nama');
        $sheet->setCellValue('C4', 'Status');
        $sheet->setCellValue('D4', 'Cetak SKL');

        $sheet->setCellValue('A5', '123456');
        $sheet->setCellValue('B5', 'Contoh');
        $sheet->setCellValue('C5', 1);
        $sheet->setCellValue('D5', 1);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template.xlsx"');

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        exit;
    } catch (Throwable $e) {
        error_log('Admin template download error: ' . $e->getMessage());
        die('Gagal membuat template.');
    }
}

// ==================== DATA UNTUK TAMPILAN ====================
$totalSiswa = admin_count($conn, "SELECT COUNT(*) AS count FROM siswa");
$lulus = admin_count($conn, "SELECT COUNT(*) AS count FROM siswa WHERE status_kelulusan = 1");
$tidakLulus = max(0, $totalSiswa - $lulus);

$dataSiswa = [];

try {
    $result = $conn->query(
        "SELECT nisn, nama, status_kelulusan, IFNULL(cetak_skl, 1) AS cetak_skl
         FROM siswa
         ORDER BY nama ASC"
    );

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $dataSiswa[] = $row;
        }

        $result->free();
    }
} catch (Throwable $e) {
    error_log('Admin fetch siswa error: ' . $e->getMessage());
}

$usersList = [];

try {
    $result = $conn->query(
        "SELECT id, username, role, created_at
         FROM users
         ORDER BY id ASC"
    );

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $usersList[] = $row;
        }

        $result->free();
    }
} catch (Throwable $e) {
    error_log('Admin fetch users error: ' . $e->getMessage());
}

$nomorWaTampil = admin_format_wa_display($nomorWa);
$nomorWaLink = admin_format_wa_link($nomorWa);

$waktuMulaiFormValue = '';
$waktuStatus = 'unconfigured';

if ($waktuMulaiCek !== '') {
    try {
        $waktuDateTime = new DateTime($waktuMulaiCek);
        $waktuMulaiFormValue = $waktuDateTime->format('Y-m-d\TH:i');
        $waktuStatus = ($waktuDateTime <= new DateTime()) ? 'opened' : 'closed';
    } catch (Throwable $e) {
        $waktuStatus = 'unconfigured';
    }
}

$menus = [
    ['id' => 'section-beranda', 'icon' => 'fa-house', 'label' => 'Beranda'],
    ['id' => 'section-identitas', 'icon' => 'fa-school', 'label' => 'Identitas Sekolah'],
    ['id' => 'section-kontak', 'icon' => 'fa-phone', 'label' => 'Kontak SKL'],
    ['id' => 'section-pengaturan-skl', 'icon' => 'fa-file-contract', 'label' => 'Pengaturan SKL'],
    ['id' => 'section-waktu', 'icon' => 'fa-clock', 'label' => 'Waktu Pengecekan'],
    ['id' => 'section-data-siswa', 'icon' => 'fa-users', 'label' => 'Data Siswa'],
    ['id' => 'section-pengguna', 'icon' => 'fa-user-shield', 'label' => 'Pengguna'],
];

$allowedTabs = array_column($menus, 'id');
$activeTab = $forcedTab ?? (string) ($_POST['active_tab'] ?? $_GET['tab'] ?? 'section-beranda');

if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'section-beranda';
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?= admin_escape($namaSekolah) ?></title>

    <link rel="stylesheet" href="dist/output.css">
    <link rel="stylesheet" href="fontawesome.css">
    <link rel="stylesheet" href="fonts.css">

    <style>
        body { font-family: 'Fira Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <div class="flex min-h-screen">
        <aside class="w-64 bg-white border-r border-gray-200 hidden md:flex flex-col sticky top-0 h-screen z-20">
            <div class="p-6 border-b border-gray-100">
                <h1 class="text-xl font-bold text-emerald-700 flex items-center gap-2">
                    <i class="fa-solid fa-graduation-cap text-2xl" aria-hidden="true"></i>
                    Admin Panel
                </h1>
                <p class="text-xs text-gray-500 mt-1">Versi 2.1</p>
            </div>

            <nav class="flex-1 overflow-y-auto p-4 space-y-1" aria-label="Menu admin">
                <?php foreach ($menus as $menu): ?>
                    <a href="#"
                       class="js-nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-600 hover:bg-emerald-50 hover:text-emerald-700 transition-all"
                       data-target="<?= admin_escape($menu['id']) ?>">
                        <i class="fa-solid <?= admin_escape($menu['icon']) ?> w-5 text-center" aria-hidden="true"></i>
                        <?= admin_escape($menu['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="p-4 border-t border-gray-100 space-y-2">
                <a href="index.php"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-600 hover:bg-emerald-50 hover:text-emerald-700 transition-all w-full">
                    <i class="fa-solid fa-globe w-5 text-center" aria-hidden="true"></i>
                    Lihat Situs
                </a>

                <a href="?logout=1"
                   class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-red-600 hover:bg-red-50 transition-all w-full">
                    <i class="fa-solid fa-right-from-bracket w-5 text-center" aria-hidden="true"></i>
                    Logout
                </a>
            </div>
        </aside>

        <main class="flex-1 p-4 md:p-8 overflow-y-auto relative">
            <div class="md:hidden flex items-center justify-between mb-6 bg-white p-4 rounded-xl shadow-sm sticky top-0 z-30">
                <button id="mobile-menu-btn"
                        class="text-gray-600 hover:text-emerald-700 focus:outline-none p-1 rounded-lg hover:bg-gray-100 transition"
                        aria-label="Buka menu navigasi">
                    <i class="fa-solid fa-bars text-xl" aria-hidden="true"></i>
                </button>

                <h1 class="font-bold text-emerald-700 flex-1 text-center pr-8">Admin Panel</h1>

                <div class="flex items-center gap-1">
                    <a href="index.php"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="text-gray-600 hover:text-emerald-600 p-2 rounded-lg hover:bg-gray-100 transition"
                       aria-label="Lihat situs">
                        <i class="fa-solid fa-globe text-lg" aria-hidden="true"></i>
                    </a>

                    <a href="?logout=1"
                       class="text-red-600 hover:bg-red-50 p-2 rounded-lg transition"
                       aria-label="Logout">
                        <i class="fa-solid fa-right-from-bracket text-lg" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            <?php if ($success !== ''): ?>
                <div id="auto-dismiss-alert"
                     class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl mb-6 flex items-center gap-3 shadow-sm transition-all duration-500 ease-in-out max-h-40 opacity-100"
                     role="status">
                    <i class="fa-solid fa-circle-check text-emerald-600" aria-hidden="true"></i>
                    <span><?= admin_escape($success) ?></span>
                </div>
            <?php elseif ($error !== ''): ?>
                <div id="auto-dismiss-alert"
                     class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl mb-6 flex items-center gap-3 shadow-sm transition-all duration-500 ease-in-out max-h-40 opacity-100"
                     role="alert">
                    <i class="fa-solid fa-circle-exclamation text-red-600" aria-hidden="true"></i>
                    <span><?= admin_escape($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- ==================== BERANDA ==================== -->
            <div id="section-beranda" class="content-section space-y-6">
                <h2 class="text-2xl font-bold text-gray-800">Dashboard</h2>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                        <div class="w-14 h-14 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center text-2xl">
                            <i class="fa-solid fa-users" aria-hidden="true"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Total Siswa</p>
                            <p class="text-3xl font-bold text-gray-800"><?= (int) $totalSiswa ?></p>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                        <div class="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center text-2xl">
                            <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Lulus</p>
                            <p class="text-3xl font-bold text-gray-800"><?= (int) $lulus ?></p>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                        <div class="w-14 h-14 bg-rose-100 text-rose-600 rounded-xl flex items-center justify-center text-2xl">
                            <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Tidak Lulus</p>
                            <p class="text-3xl font-bold text-gray-800"><?= (int) $tidakLulus ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== IDENTITAS ==================== -->
            <div id="section-identitas" class="content-section hidden">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
                    <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-school text-emerald-600 text-xl" aria-hidden="true"></i>
                        Identitas Sekolah
                    </h2>

                    <form method="post" enctype="multipart/form-data" class="space-y-6" autocomplete="off">
                        <input type="hidden" name="active_tab" value="section-identitas">
                        <input type="hidden" name="action" value="simpan_identitas">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="nama_sekolah" class="block text-sm font-medium text-gray-700 mb-2">Nama Sekolah</label>
                                <input type="text"
                                       id="nama_sekolah"
                                       name="nama_sekolah"
                                       value="<?= admin_escape($namaSekolah) ?>"
                                       required
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>

                            <div>
                                <label for="tahun_pelajaran" class="block text-sm font-medium text-gray-700 mb-2">Tahun Pelajaran</label>
                                <input type="text"
                                       id="tahun_pelajaran"
                                       name="tahun_pelajaran"
                                       value="<?= admin_escape($tahunPelajaran) ?>"
                                       required
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>
                        </div>

                        <div>
                            <label for="logo_sekolah" class="block text-sm font-medium text-gray-700 mb-2">Logo Sekolah</label>
                            <input type="file"
                                   id="logo_sekolah"
                                   name="logo_sekolah"
                                   accept=".jpg,.jpeg,.png,.webp"
                                   class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 transition">

                            <?php if ($logoSekolah !== ''): ?>
                                <div class="mt-3 flex items-center gap-3">
                                    <img src="<?= admin_escape($logoSekolah) ?>"
                                         class="h-16 w-16 object-contain border rounded-lg p-1"
                                         alt="Logo sekolah"
                                         loading="lazy">
                                    <span class="text-sm text-gray-500"><?= admin_escape(basename($logoSekolah)) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <button type="submit"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-6 py-3 rounded-xl transition shadow-md shadow-emerald-200 flex items-center gap-2">
                            <i class="fa-solid fa-save" aria-hidden="true"></i>
                            Simpan Identitas
                        </button>
                    </form>
                </div>
            </div>

            <!-- ==================== KONTAK WA ==================== -->
            <div id="section-kontak" class="content-section hidden">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
                    <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-phone text-emerald-600 text-xl" aria-hidden="true"></i>
                        Kontak WhatsApp SKL
                    </h2>

                    <form method="post" autocomplete="off">
                        <input type="hidden" name="active_tab" value="section-kontak">
                        <input type="hidden" name="action" value="simpan_wa">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="nomor_wa" class="block text-sm font-medium text-gray-700 mb-2">Nomor WhatsApp</label>
                                <input type="text"
                                       id="nomor_wa"
                                       name="nomor_wa"
                                       value="<?= admin_escape($nomorWaTampil) ?>"
                                       required
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Preview Link</label>
                                <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                                    <a href="https://wa.me/<?= admin_escape($nomorWaLink) ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="text-green-700 font-medium hover:underline break-all flex items-center gap-2">
                                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                                        https://wa.me/<?= admin_escape($nomorWaLink) ?>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <button type="submit"
                                class="mt-6 bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-3 rounded-xl transition shadow-md shadow-green-200 flex items-center gap-2">
                            <i class="fa-solid fa-save" aria-hidden="true"></i>
                            Simpan WhatsApp
                        </button>
                    </form>
                </div>
            </div>

            <!-- ==================== PENGATURAN SKL ==================== -->
            <div id="section-pengaturan-skl" class="content-section hidden">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8 space-y-8">
                    <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-file-contract text-emerald-600 text-xl" aria-hidden="true"></i>
                        Pengaturan SKL
                    </h2>

                    <form method="post" enctype="multipart/form-data" class="space-y-6" autocomplete="off">
                        <input type="hidden" name="active_tab" value="section-pengaturan-skl">
                        <input type="hidden" name="action" value="simpan_skl">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="nama_kabupaten" class="block text-sm font-medium text-gray-700 mb-2">Kabupaten</label>
                                <input type="text"
                                       id="nama_kabupaten"
                                       name="nama_kabupaten"
                                       value="<?= admin_escape($sklNamaKabupaten) ?>"
                                       required
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>

                            <div>
                                <label for="tanggal_surat" class="block text-sm font-medium text-gray-700 mb-2">Tanggal Surat</label>
                                <input type="date"
                                       id="tanggal_surat"
                                       name="tanggal_surat"
                                       value="<?= admin_escape($sklTanggal) ?>"
                                       required
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>
                        </div>

                        <div>
                            <label for="nomor_surat" class="block text-sm font-medium text-gray-700 mb-2">Nomor Surat</label>
                            <input type="text"
                                   id="nomor_surat"
                                   name="nomor_surat"
                                   value="<?= admin_escape($sklNomor) ?>"
                                   required
                                   class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            <p class="text-xs text-gray-500 mt-1">
                                Gunakan <code class="bg-gray-200 px-1 rounded">{status}</code> untuk LULUS/TIDAK LULUS otomatis.
                            </p>
                        </div>

                        <div>
                            <label for="isi_surat" class="block text-sm font-medium text-gray-700 mb-2">Isi Surat</label>
                            <textarea id="isi_surat"
                                      name="isi_surat"
                                      rows="5"
                                      required
                                      class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition"><?= admin_escape($sklIsi) ?></textarea>
                        </div>

                        <h3 class="text-lg font-semibold text-gray-800 pt-4 border-t">Data Kepala Sekolah</h3>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="nama_kepala" class="block text-sm font-medium text-gray-700 mb-2">Nama</label>
                                <input type="text"
                                       id="nama_kepala"
                                       name="nama_kepala"
                                       value="<?= admin_escape($sklNamaKepala) ?>"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>

                            <div>
                                <label for="jabatan_kepala" class="block text-sm font-medium text-gray-700 mb-2">Jabatan</label>
                                <input type="text"
                                       id="jabatan_kepala"
                                       name="jabatan_kepala"
                                       value="<?= admin_escape($sklJabatan) ?>"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>

                            <div>
                                <label for="nip_kepala" class="block text-sm font-medium text-gray-700 mb-2">NIP</label>
                                <input type="text"
                                       id="nip_kepala"
                                       name="nip_kepala"
                                       value="<?= admin_escape($sklNip) ?>"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>
                        </div>

                        <h3 class="text-lg font-semibold text-gray-800 pt-4 border-t">Upload File</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="kop_surat" class="block text-sm font-medium text-gray-700 mb-2">Kop Surat</label>
                                <input type="file"
                                       id="kop_surat"
                                       name="kop_surat"
                                       accept=".jpg,.jpeg,.png"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 transition">

                                <?php if ($sklKop !== ''): ?>
                                    <div class="mt-3">
                                        <img src="<?= admin_escape($sklKop) ?>" class="h-16 border rounded p-1" alt="Kop surat" loading="lazy">
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label for="ttd_kepala" class="block text-sm font-medium text-gray-700 mb-2">Scan TTD + Cap</label>
                                <input type="file"
                                       id="ttd_kepala"
                                       name="ttd_kepala"
                                       accept=".jpg,.jpeg,.png"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 transition">

                                <?php if ($sklTtd !== ''): ?>
                                    <div class="mt-3">
                                        <img src="<?= admin_escape($sklTtd) ?>" class="h-16 border rounded p-1" alt="Tanda tangan dan cap" loading="lazy">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="bg-gray-50 p-6 rounded-xl border border-gray-200">
                            <label class="block text-sm font-bold text-gray-800 mb-3">Status Fitur SKL</label>

                            <?php $isSklAktif = $sklAktif === '1'; ?>

                            <div class="flex flex-wrap gap-4">
                                <label class="flex-1 min-w-[200px] cursor-pointer p-4 rounded-xl border-2 <?= $isSklAktif ? 'border-emerald-500 bg-emerald-50' : 'border-gray-200 bg-white' ?> transition-all flex items-center gap-3">
                                    <input type="radio" name="skl_aktif" value="1" <?= $isSklAktif ? 'checked' : '' ?> class="w-5 h-5 text-emerald-600">
                                    <div>
                                        <p class="font-bold text-emerald-800">Aktifkan SKL</p>
                                        <p class="text-xs text-gray-600">Tombol Cetak & PDF muncul</p>
                                    </div>
                                </label>

                                <label class="flex-1 min-w-[200px] cursor-pointer p-4 rounded-xl border-2 <?= !$isSklAktif ? 'border-red-500 bg-red-50' : 'border-gray-200 bg-white' ?> transition-all flex items-center gap-3">
                                    <input type="radio" name="skl_aktif" value="0" <?= !$isSklAktif ? 'checked' : '' ?> class="w-5 h-5 text-red-600">
                                    <div>
                                        <p class="font-bold text-red-800">Nonaktifkan SKL</p>
                                        <p class="text-xs text-gray-600">Tombol disembunyikan</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <button type="submit"
                                class="mt-6 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-6 py-3 rounded-xl transition shadow-md shadow-emerald-200 flex items-center gap-2">
                            <i class="fa-solid fa-save" aria-hidden="true"></i>
                            Simpan SKL
                        </button>
                    </form>
                </div>
            </div>

            <!-- ==================== WAKTU ==================== -->
            <div id="section-waktu" class="content-section hidden">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
                    <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-clock text-emerald-600 text-xl" aria-hidden="true"></i>
                        Waktu Pengecekan
                    </h2>

                    <form method="post" autocomplete="off">
                        <input type="hidden" name="active_tab" value="section-waktu">
                        <input type="hidden" name="action" value="simpan_waktu">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="waktu_mulai" class="block text-sm font-medium text-gray-700 mb-2">Waktu Mulai</label>
                                <input type="datetime-local"
                                       id="waktu_mulai"
                                       name="waktu_mulai"
                                       value="<?= admin_escape($waktuMulaiFormValue) ?>"
                                       required
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>

                                <?php if ($waktuStatus === 'opened'): ?>
                                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl font-semibold flex items-center gap-2">
                                        <i class="fa-solid fa-unlock" aria-hidden="true"></i>
                                        SUDAH DIBUKA
                                    </div>
                                <?php elseif ($waktuStatus === 'closed'): ?>
                                    <div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-xl font-semibold flex items-center gap-2">
                                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                        BELUM DIBUKA
                                    </div>
                                <?php else: ?>
                                    <div class="bg-gray-50 border border-gray-200 text-gray-600 px-4 py-3 rounded-xl font-semibold flex items-center gap-2">
                                        <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                                        BELUM DIATUR
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <button type="submit"
                                class="mt-6 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-6 py-3 rounded-xl transition shadow-md shadow-emerald-200 flex items-center gap-2">
                            <i class="fa-solid fa-save" aria-hidden="true"></i>
                            Simpan Waktu
                        </button>
                    </form>
                </div>
            </div>

            <!-- ==================== DATA SISWA ==================== -->
            <div id="section-data-siswa" class="content-section hidden space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
                    <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-user-plus text-emerald-600 text-xl" aria-hidden="true"></i>
                        Tambah Siswa Manual
                    </h2>

                    <form method="post" autocomplete="off">
                        <input type="hidden" name="active_tab" value="section-data-siswa">
                        <input type="hidden" name="action" value="tambah_siswa">

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="nisn" class="block text-sm font-medium text-gray-700 mb-2">NISN</label>
                                <input type="text"
                                       id="nisn"
                                       name="nisn"
                                       required
                                       inputmode="numeric"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>

                            <div>
                                <label for="nama" class="block text-sm font-medium text-gray-700 mb-2">Nama</label>
                                <input type="text"
                                       id="nama"
                                       name="nama"
                                       required
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>

                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                                <select id="status"
                                        name="status"
                                        required
                                        class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                                    <option value="1">Lulus</option>
                                    <option value="0">Tidak Lulus</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit"
                                class="mt-6 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-6 py-3 rounded-xl transition shadow-md shadow-emerald-200 flex items-center gap-2">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            Tambah Siswa
                        </button>
                    </form>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
                    <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-file-import text-emerald-600 text-xl" aria-hidden="true"></i>
                        Import Data Massal
                    </h2>

                    <div class="mb-4">
                        <a href="?download_template=1"
                           class="inline-flex items-center gap-2 text-emerald-700 bg-emerald-50 hover:bg-emerald-100 px-4 py-2 rounded-lg font-medium transition">
                            <i class="fa-solid fa-download" aria-hidden="true"></i>
                            Download Template Excel/CSV
                        </a>
                    </div>

                    <form method="post" enctype="multipart/form-data" autocomplete="off">
                        <input type="hidden" name="active_tab" value="section-data-siswa">
                        <input type="hidden" name="action" value="import">

                        <div class="flex items-center gap-4">
                            <input type="file"
                                   name="file_excel"
                                   accept=".xlsx,.xls,.csv"
                                   required
                                   class="flex-1 px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 transition">

                            <button type="submit"
                                    class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-6 py-3 rounded-xl transition shadow-md shadow-emerald-200 flex items-center gap-2">
                                <i class="fa-solid fa-upload" aria-hidden="true"></i>
                                Import
                            </button>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8 overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                            <i class="fa-solid fa-users text-emerald-600 text-xl" aria-hidden="true"></i>
                            Daftar Data Siswa
                        </h2>

                        <?php if (!empty($dataSiswa)): ?>
                            <div class="flex flex-wrap gap-2">
                                <button type="button"
                                        id="btn-delete-selected"
                                        class="bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-2">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                    Hapus Centang
                                </button>

                                <button type="button"
                                        id="btn-delete-all"
                                        class="bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-2">
                                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                                    Hapus SEMUA
                                </button>

                                <button type="button"
                                        id="btn-save-skl"
                                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-2">
                                    <i class="fa-solid fa-save" aria-hidden="true"></i>
                                    Simpan Cetak SKL
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($dataSiswa)): ?>
                        <div class="text-center py-12 text-gray-500">
                            <i class="fa-solid fa-inbox text-6xl text-gray-300 mx-auto mb-4" aria-hidden="true"></i>
                            <p>Belum ada data siswa.</p>
                        </div>
                    <?php else: ?>
                        <form method="post" id="bulkForm">
                            <input type="hidden" name="active_tab" value="section-data-siswa">
                            <input type="hidden" name="action" id="bulkAction" value="">

                            <div class="overflow-x-auto rounded-xl border border-gray-200">
                                <table class="w-full text-sm text-left">
                                    <thead class="bg-gray-50 text-gray-700 font-semibold border-b border-gray-200">
                                        <tr>
                                            <th class="p-4 w-10 text-center">
                                                <input type="checkbox"
                                                       id="select-all"
                                                       class="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                                       aria-label="Pilih semua data">
                                            </th>
                                            <th class="p-4">No</th>
                                            <th class="p-4">NISN</th>
                                            <th class="p-4">Nama</th>
                                            <th class="p-4">Status</th>
                                            <th class="p-4 text-center">Cetak SKL</th>
                                            <th class="p-4 text-center">Aksi</th>
                                        </tr>
                                    </thead>

                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($dataSiswa as $index => $siswa): ?>
                                            <?php
                                            $nisn = (string) ($siswa['nisn'] ?? '');
                                            $nama = (string) ($siswa['nama'] ?? '');
                                            $statusLulus = (int) ($siswa['status_kelulusan'] ?? 0) === 1;
                                            $cetakSkl = (int) ($siswa['cetak_skl'] ?? 1) === 1;
                                            ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="p-4 text-center">
                                                    <input type="checkbox"
                                                           name="selected_nisn[]"
                                                           value="<?= admin_escape($nisn) ?>"
                                                           class="row-checkbox w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                                           aria-label="Pilih siswa <?= admin_escape($nama) ?>">
                                                    <input type="hidden" name="all_nisn[]" value="<?= admin_escape($nisn) ?>">
                                                </td>

                                                <td class="p-4 text-gray-500"><?= $index + 1 ?></td>

                                                <td class="p-4 font-medium text-gray-800">
                                                    <?= admin_escape($nisn) ?>
                                                </td>

                                                <td class="p-4">
                                                    <?= admin_escape($nama) ?>
                                                </td>

                                                <td class="p-4">
                                                    <span class="px-3 py-1 rounded-full text-xs font-bold <?= $statusLulus ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">
                                                        <?= $statusLulus ? 'Lulus' : 'Tidak' ?>
                                                    </span>
                                                </td>

                                                <td class="p-4 text-center">
                                                    <input type="checkbox"
                                                           name="cetak_skl[<?= admin_escape($nisn) ?>]"
                                                           value="1"
                                                           <?= $cetakSkl ? 'checked' : '' ?>
                                                           class="w-5 h-5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
                                                           aria-label="Cetak SKL untuk <?= admin_escape($nama) ?>">
                                                </td>

                                                <td class="p-4 text-center">
                                                    <a href="?hapus_nisn=<?= urlencode($nisn) ?>"
                                                       class="js-confirm text-red-600 hover:text-red-800 hover:bg-red-50 p-2 rounded-lg transition inline-flex items-center justify-center"
                                                       data-message="Yakin hapus data ini?"
                                                       aria-label="Hapus siswa <?= admin_escape($nama) ?>">
                                                        <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ==================== PENGGUNA ==================== -->
            <div id="section-pengguna" class="content-section hidden space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
                    <h2 id="user-form-title" class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-user-plus text-emerald-600 text-xl" aria-hidden="true"></i>
                        <span id="user-form-title-text">Tambah Pengguna Baru</span>
                    </h2>

                    <form method="post" id="userForm" autocomplete="off">
                        <input type="hidden" name="active_tab" value="section-pengguna">
                        <input type="hidden" name="action" value="tambah_pengguna" id="user_action">
                        <input type="hidden" name="user_id" id="user_id" value="">

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="new_username" class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                                <input type="text"
                                       id="new_username"
                                       name="new_username"
                                       required
                                       minlength="3"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">
                            </div>

                            <div>
                                <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">Password Baru</label>

                                <div class="relative">
                                    <input type="password"
                                           id="new_password"
                                           name="new_password"
                                           required
                                           minlength="6"
                                           class="w-full px-4 py-3 pr-12 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">

                                    <button type="button"
                                            class="js-toggle-password absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-emerald-600 transition-colors"
                                            data-target="new_password"
                                            aria-label="Lihat password"
                                            title="Lihat Password">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <p class="text-xs text-gray-500 mt-1" id="pass-hint">Wajib diisi untuk pengguna baru.</p>
                            </div>

                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Konfirmasi Password</label>

                                <div class="relative">
                                    <input type="password"
                                           id="confirm_password"
                                           name="confirm_password"
                                           required
                                           minlength="6"
                                           class="w-full px-4 py-3 pr-12 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none transition">

                                    <button type="button"
                                            class="js-toggle-password absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-emerald-600 transition-colors"
                                            data-target="confirm_password"
                                            aria-label="Lihat password"
                                            title="Lihat Password">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-3 mt-6">
                            <button type="submit"
                                    id="user_submit_btn"
                                    class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-6 py-3 rounded-xl transition shadow-md shadow-emerald-200 flex items-center gap-2">
                                <i class="fa-solid fa-save" aria-hidden="true"></i>
                                <span id="user-submit-text">Tambah Pengguna</span>
                            </button>

                            <button type="button"
                                    id="cancel_edit_btn"
                                    class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold px-6 py-3 rounded-xl transition flex items-center gap-2 hidden">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                Batal
                            </button>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8 overflow-hidden">
                    <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-user-shield text-emerald-600 text-xl" aria-hidden="true"></i>
                        Daftar Pengguna Admin
                    </h2>

                    <?php if (empty($usersList)): ?>
                        <div class="text-center py-12 text-gray-500">
                            <i class="fa-solid fa-user-slash text-6xl text-gray-300 mx-auto mb-4" aria-hidden="true"></i>
                            <p>Belum ada pengguna di database.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto rounded-xl border border-gray-200">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-gray-50 text-gray-700 font-semibold border-b border-gray-200">
                                    <tr>
                                        <th class="p-4">No</th>
                                        <th class="p-4">Username</th>
                                        <th class="p-4">Role</th>
                                        <th class="p-4">Dibuat Pada</th>
                                        <th class="p-4 text-center">Aksi</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($usersList as $index => $user): ?>
                                        <?php
                                        $userId = (int) ($user['id'] ?? 0);
                                        $username = (string) ($user['username'] ?? '');
                                        $role = (string) ($user['role'] ?? '');
                                        $createdAt = !empty($user['created_at'])
                                            ? date('d M Y', strtotime((string) $user['created_at']))
                                            : '-';
                                        $isCurrentUser = $userId === $currentUserId;
                                        ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-4 text-gray-500"><?= $index + 1 ?></td>

                                            <td class="p-4 font-bold text-gray-800">
                                                <?= admin_escape($username) ?>

                                                <?php if ($isCurrentUser): ?>
                                                    <span class="ml-2 px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-700">ANDA</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="p-4 capitalize"><?= admin_escape($role) ?></td>

                                            <td class="p-4 text-gray-500"><?= admin_escape($createdAt) ?></td>

                                            <td class="p-4 text-center">
                                                <div class="flex items-center justify-center gap-2">
                                                    <button type="button"
                                                            class="js-edit-user text-blue-600 hover:text-blue-800 hover:bg-blue-50 p-2 rounded-lg transition"
                                                            data-id="<?= (string) $userId ?>"
                                                            data-username="<?= admin_escape($username) ?>"
                                                            title="Edit pengguna"
                                                            aria-label="Edit pengguna <?= admin_escape($username) ?>">
                                                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                    </button>

                                                    <?php if (!$isCurrentUser): ?>
                                                        <a href="?hapus_user=<?= (string) $userId ?>"
                                                           class="js-confirm text-red-600 hover:text-red-800 hover:bg-red-50 p-2 rounded-lg transition inline-flex items-center justify-center"
                                                           data-message="Yakin hapus pengguna ini?"
                                                           title="Hapus pengguna"
                                                           aria-label="Hapus pengguna <?= admin_escape($username) ?>">
                                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <button type="button"
                                                                class="text-gray-400 p-2 rounded-lg cursor-not-allowed"
                                                                title="Tidak bisa hapus diri sendiri"
                                                                aria-label="Tidak bisa hapus diri sendiri"
                                                                disabled>
                                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- ==================== MOBILE SIDEBAR ==================== -->
    <div id="mobile-sidebar" class="fixed inset-0 z-50 hidden md:hidden">
        <div id="mobile-sidebar-backdrop" class="absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity duration-300 opacity-0"></div>

        <div class="absolute left-0 top-0 bottom-0 w-72 bg-white shadow-2xl transform transition-transform duration-300 -translate-x-full flex flex-col"
             id="mobile-sidebar-content">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between bg-emerald-50">
                <h1 class="text-lg font-bold text-emerald-700 flex items-center gap-2">
                    <i class="fa-solid fa-bars" aria-hidden="true"></i>
                    Menu Navigasi
                </h1>

                <button id="mobile-sidebar-close"
                        class="text-gray-500 hover:text-red-600 hover:bg-red-50 p-2 rounded-lg transition-colors"
                        aria-label="Tutup menu navigasi">
                    <i class="fa-solid fa-xmark text-xl" aria-hidden="true"></i>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto p-4 space-y-1" aria-label="Menu mobile">
                <?php foreach ($menus as $menu): ?>
                    <a href="#"
                       class="js-nav-link flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-600 hover:bg-emerald-50 hover:text-emerald-700 transition-all"
                       data-target="<?= admin_escape($menu['id']) ?>">
                        <i class="fa-solid <?= admin_escape($menu['icon']) ?> w-5 text-center" aria-hidden="true"></i>
                        <?= admin_escape($menu['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="p-4 border-t border-gray-100 space-y-2 bg-gray-50">
                <a href="index.php"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-600 hover:bg-emerald-50 hover:text-emerald-700 transition-all w-full">
                    <i class="fa-solid fa-globe w-5 text-center" aria-hidden="true"></i>
                    Lihat Situs
                </a>

                <a href="?logout=1"
                   class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-red-600 hover:bg-red-50 transition-all w-full">
                    <i class="fa-solid fa-right-from-bracket w-5 text-center" aria-hidden="true"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const defaultTab = <?= json_encode($activeTab, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            // ==================== TAB NAVIGATION ====================
            function showTab(tabId) {
                document.querySelectorAll('.content-section').forEach(function (section) {
                    section.classList.add('hidden');
                });

                const targetSection = document.getElementById(tabId);

                if (targetSection) {
                    targetSection.classList.remove('hidden');
                }

                document.querySelectorAll('.js-nav-link').forEach(function (link) {
                    const isActive = link.dataset.target === tabId;

                    link.classList.toggle('bg-emerald-50', isActive);
                    link.classList.toggle('text-emerald-700', isActive);
                });
            }

            document.querySelectorAll('.js-nav-link').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    showTab(link.dataset.target);
                });
            });

            // ==================== TOGGLE PASSWORD ====================
            document.querySelectorAll('.js-toggle-password').forEach(function (button) {
                button.addEventListener('click', function () {
                    const input = document.getElementById(button.dataset.target);
                    const icon = button.querySelector('i');

                    if (!input || !icon) {
                        return;
                    }

                    const showPassword = input.type === 'password';

                    input.type = showPassword ? 'text' : 'password';

                    icon.classList.toggle('fa-eye', !showPassword);
                    icon.classList.toggle('fa-eye-slash', showPassword);
                });
            });

            // ==================== CONFIRM LINK ====================
            document.querySelectorAll('.js-confirm').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    const message = link.dataset.message || 'Yakin melanjutkan aksi ini?';

                    if (!window.confirm(message)) {
                        event.preventDefault();
                    }
                });
            });

            // ==================== MOBILE SIDEBAR ====================
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            const mobileSidebar = document.getElementById('mobile-sidebar');
            const mobileSidebarContent = document.getElementById('mobile-sidebar-content');
            const mobileSidebarClose = document.getElementById('mobile-sidebar-close');
            const mobileSidebarBackdrop = document.getElementById('mobile-sidebar-backdrop');

            function openMobileSidebar() {
                if (!mobileSidebar || !mobileSidebarContent || !mobileSidebarBackdrop) {
                    return;
                }

                mobileSidebar.classList.remove('hidden');

                setTimeout(function () {
                    mobileSidebarContent.classList.remove('-translate-x-full');
                    mobileSidebarBackdrop.classList.remove('opacity-0');
                }, 10);
            }

            function closeMobileSidebar() {
                if (!mobileSidebar || !mobileSidebarContent || !mobileSidebarBackdrop) {
                    return;
                }

                mobileSidebarContent.classList.add('-translate-x-full');
                mobileSidebarBackdrop.classList.add('opacity-0');

                setTimeout(function () {
                    mobileSidebar.classList.add('hidden');
                }, 300);
            }

            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', openMobileSidebar);
            }

            if (mobileSidebarClose) {
                mobileSidebarClose.addEventListener('click', closeMobileSidebar);
            }

            if (mobileSidebarBackdrop) {
                mobileSidebarBackdrop.addEventListener('click', closeMobileSidebar);
            }

            // ==================== AUTO DISMISS ALERT ====================
            const alertBox = document.getElementById('auto-dismiss-alert');

            if (alertBox) {
                setTimeout(function () {
                    alertBox.classList.add('max-h-0', 'opacity-0', 'py-0', 'mb-0', 'overflow-hidden');

                    setTimeout(function () {
                        alertBox.style.display = 'none';
                    }, 500);
                }, 3000);
            }

            // ==================== BULK ACTION SISWA ====================
            const selectAll = document.getElementById('select-all');
            const bulkForm = document.getElementById('bulkForm');
            const bulkAction = document.getElementById('bulkAction');

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    document.querySelectorAll('.row-checkbox').forEach(function (checkbox) {
                        checkbox.checked = selectAll.checked;
                    });
                });
            }

            function submitBulkAction(action) {
                if (!bulkForm || !bulkAction) {
                    return;
                }

                bulkAction.value = action;
                bulkForm.submit();
            }

            const btnDeleteSelected = document.getElementById('btn-delete-selected');

            if (btnDeleteSelected) {
                btnDeleteSelected.addEventListener('click', function () {
                    const checked = document.querySelectorAll('.row-checkbox:checked');

                    if (checked.length === 0) {
                        alert('Centang minimal 1 data!');
                        return;
                    }

                    if (confirm(`Hapus ${checked.length} data?`)) {
                        submitBulkAction('delete_selected');
                    }
                });
            }

            const btnDeleteAll = document.getElementById('btn-delete-all');

            if (btnDeleteAll) {
                btnDeleteAll.addEventListener('click', function () {
                    const total = document.querySelectorAll('.row-checkbox').length;

                    if (total === 0) {
                        alert('Tidak ada data!');
                        return;
                    }

                    if (confirm(`Hapus SEMUA ${total} data?`) && confirm('Konfirmasi terakhir?')) {
                        submitBulkAction('delete_all');
                    }
                });
            }

            const btnSaveSkl = document.getElementById('btn-save-skl');

            if (btnSaveSkl) {
                btnSaveSkl.addEventListener('click', function () {
                    if (confirm('Simpan pengaturan Cetak SKL?')) {
                        submitBulkAction('simpan_cetak_skl');
                    }
                });
            }

            // ==================== FORM PENGGUNA ====================
            const userForm = document.getElementById('userForm');
            const userAction = document.getElementById('user_action');
            const userId = document.getElementById('user_id');
            const newUsername = document.getElementById('new_username');
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            const passHint = document.getElementById('pass-hint');
            const cancelEditBtn = document.getElementById('cancel_edit_btn');
            const userSubmitText = document.getElementById('user-submit-text');
            const userFormTitleText = document.getElementById('user-form-title-text');
            const userFormTitleIcon = document.querySelector('#user-form-title i');

            function resetPasswordFieldsToHidden() {
                [newPassword, confirmPassword].forEach(function (input) {
                    if (input) {
                        input.type = 'password';
                    }
                });

                document.querySelectorAll('.js-toggle-password').forEach(function (button) {
                    const target = button.dataset.target;

                    if (target === 'new_password' || target === 'confirm_password') {
                        const icon = button.querySelector('i');

                        if (icon) {
                            icon.classList.remove('fa-eye-slash');
                            icon.classList.add('fa-eye');
                        }
                    }
                });
            }

            function resetUserForm() {
                if (userAction) {
                    userAction.value = 'tambah_pengguna';
                }

                if (userId) {
                    userId.value = '';
                }

                if (userForm) {
                    userForm.reset();
                }

                if (newPassword) {
                    newPassword.required = true;
                    newPassword.value = '';
                }

                if (confirmPassword) {
                    confirmPassword.required = true;
                    confirmPassword.value = '';
                }

                if (passHint) {
                    passHint.textContent = 'Wajib diisi untuk pengguna baru.';
                }

                if (userSubmitText) {
                    userSubmitText.textContent = 'Tambah Pengguna';
                }

                if (userFormTitleText) {
                    userFormTitleText.textContent = 'Tambah Pengguna Baru';
                }

                if (userFormTitleIcon) {
                    userFormTitleIcon.className = 'fa-solid fa-user-plus text-emerald-600 text-xl';
                }

                if (cancelEditBtn) {
                    cancelEditBtn.classList.add('hidden');
                }

                resetPasswordFieldsToHidden();
            }

            document.querySelectorAll('.js-edit-user').forEach(function (button) {
                button.addEventListener('click', function () {
                    const id = button.dataset.id || '';
                    const username = button.dataset.username || '';

                    if (userAction) {
                        userAction.value = 'update_pengguna';
                    }

                    if (userId) {
                        userId.value = id;
                    }

                    if (newUsername) {
                        newUsername.value = username;
                    }

                    if (newPassword) {
                        newPassword.required = false;
                        newPassword.value = '';
                    }

                    if (confirmPassword) {
                        confirmPassword.required = false;
                        confirmPassword.value = '';
                    }

                    if (passHint) {
                        passHint.textContent = 'Kosongkan jika tidak ingin mengubah password.';
                    }

                    if (userSubmitText) {
                        userSubmitText.textContent = 'Perbarui Pengguna';
                    }

                    if (userFormTitleText) {
                        userFormTitleText.textContent = 'Edit Pengguna: ' + username;
                    }

                    if (userFormTitleIcon) {
                        userFormTitleIcon.className = 'fa-solid fa-pen-to-square text-emerald-600 text-xl';
                    }

                    if (cancelEditBtn) {
                        cancelEditBtn.classList.remove('hidden');
                    }

                    if (userForm) {
                        userForm.scrollIntoView({ behavior: 'smooth' });
                    }

                    resetPasswordFieldsToHidden();
                });
            });

            if (cancelEditBtn) {
                cancelEditBtn.addEventListener('click', resetUserForm);
            }

            showTab(defaultTab);
        });
    </script>
</body>
</html>