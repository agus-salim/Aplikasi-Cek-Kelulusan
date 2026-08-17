<?php
declare(strict_types=1);

require_once 'config.php';

/**
 * Escape output HTML.
 */
function nisn_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Ambil pengaturan dari database dengan nilai default.
 */
function nisn_setting(mysqli $conn, string $key, string $default = ''): string
{
    $value = getPengaturan($conn, $key);

    if (!is_scalar($value)) {
        return $default;
    }

    $value = trim((string) $value);

    return $value === '' ? $default : $value;
}

/**
 * Escape karakter wildcard LIKE agar pencarian sesuai input pengguna.
 */
function nisn_escape_like(string $value): string
{
    return str_replace(
        ['\\', '%', '_'],
        ['\\\\', '\\%', '\\_'],
        $value
    );
}

/**
 * Ambil data siswa berdasarkan pencarian.
 */
function nisn_ambil_data_siswa(mysqli $conn, string $search): array
{
    if ($search === '') {
        $result = $conn->query(
            "SELECT nisn, nama
             FROM siswa
             ORDER BY nama ASC"
        );

        if ($result === false) {
            throw new RuntimeException('Gagal menjalankan query data siswa.');
        }

        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $result->free();

        return $rows;
    }

    $sql = "SELECT nisn, nama
            FROM siswa
            WHERE nama LIKE ? OR nisn LIKE ?
            ORDER BY nama ASC";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException('Gagal menyiapkan query data siswa.');
    }

    $searchParam = '%' . nisn_escape_like($search) . '%';
    $stmt->bind_param('ss', $searchParam, $searchParam);

    if (!$stmt->execute()) {
        throw new RuntimeException('Gagal mengeksekusi query data siswa.');
    }

    $result = $stmt->get_result();
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();

    return $rows;
}

// Header keamanan dasar.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $conn = getDbConnection();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Koneksi database tidak tersedia.');
}

if (!$conn instanceof mysqli) {
    http_response_code(500);
    exit('Koneksi database tidak tersedia.');
}

$defaultSettings = [
    'nama_sekolah' => 'MTsN 1 Sekadau',
    'tahun_pelajaran' => '2025/2026',
    'logo_sekolah' => 'logo.png',
];

$namaSekolah = nisn_setting($conn, 'nama_sekolah', $defaultSettings['nama_sekolah']);
$tahunPelajaran = nisn_setting($conn, 'tahun_pelajaran', $defaultSettings['tahun_pelajaran']);
$logoSekolah = nisn_setting($conn, 'logo_sekolah', $defaultSettings['logo_sekolah']);

$cariRaw = $_GET['cari'] ?? '';
$search = is_scalar($cariRaw) ? trim((string) $cariRaw) : '';

if ($search !== '') {
    $search = function_exists('mb_substr')
        ? mb_substr($search, 0, 100)
        : substr($search, 0, 100);
}

$dataSiswa = [];
$queryError = null;

try {
    $dataSiswa = nisn_ambil_data_siswa($conn, $search);
} catch (Throwable $e) {
    error_log('Query error di nisn.php: ' . $e->getMessage());
    $queryError = 'Terjadi kendala saat mengambil data siswa. Silakan coba lagi.';
}

$totalSiswa = count($dataSiswa);
$adaPencarian = $search !== '';
$pageTitle = sprintf('Daftar NISN Siswa - %s', $namaSekolah);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Daftar siswa dan NISN <?= nisn_escape($namaSekolah) ?> Tahun Pelajaran <?= nisn_escape($tahunPelajaran) ?>.">
    <title><?= nisn_escape($pageTitle) ?></title>

    <link rel="stylesheet" href="dist/output.css">
    <link rel="stylesheet" href="fontawesome.css">
    <link rel="stylesheet" href="fonts.css">

    <style>
        body { font-family: 'Fira Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }

        .custom-scrollbar::-webkit-scrollbar { height: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        @keyframes toast-in {
            from { opacity: 0; transform: translate(-50%, 20px); }
            to { opacity: 1; transform: translate(-50%, 0); }
        }

        @keyframes toast-out {
            from { opacity: 1; transform: translate(-50%, 0); }
            to { opacity: 0; transform: translate(-50%, 20px); }
        }

        .toast-show { animation: toast-in 0.3s ease-out forwards; }
        .toast-hide { animation: toast-out 0.3s ease-in forwards; }

        .copy-success {
            color: #10b981 !important;
            transform: scale(1.2);
        }

        .btn-copy {
            transition: all 0.2s ease;
        }

        .btn-copy:active {
            transform: scale(0.9);
        }

        /* ============ LOGO ELEGAN ============ */
        .logo-wrap{position:relative;display:inline-flex;align-items:center;justify-content:center;margin:6px 0 20px;animation:logoFloat 4.5s ease-in-out infinite;}
        .logo-glow-1{position:absolute;width:10rem;height:10rem;border-radius:50%;background:rgba(255,255,255,.12);filter:blur(24px);}
        .logo-glow-2{position:absolute;width:8rem;height:8rem;border-radius:50%;background:rgba(252,211,77,.22);filter:blur(18px);}
        .logo-ring-outer{position:relative;width:8.5rem;height:8.5rem;border-radius:50%;border:1px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;}
        .logo-dot{position:absolute;width:8px;height:8px;border-radius:50%;background:#fcd34d;box-shadow:0 0 10px rgba(252,211,77,.95);}
        .logo-dot.top{top:-4px;left:50%;transform:translateX(-50%);}
        .logo-dot.bottom{bottom:-4px;left:50%;transform:translateX(-50%);}
        .logo-dot.left{left:-4px;top:50%;transform:translateY(-50%);}
        .logo-dot.right{right:-4px;top:50%;transform:translateY(-50%);}
        .logo-gold-ring{width:7.25rem;height:7.25rem;border-radius:50%;padding:3px;background:linear-gradient(135deg,#fde68a,#f59e0b 45%,#fbbf24 70%,#fde68a);box-shadow:0 12px 30px rgba(0,0,0,.28);}
        .logo-inner{width:100%;height:100%;border-radius:50%;background:#fff;padding:10px;box-sizing:border-box;box-shadow:inset 0 0 0 1px rgba(251,191,36,.45);display:flex;align-items:center;justify-content:center;}
        .logo-inner img{width:100%;height:100%;object-fit:contain;}
        @keyframes logoFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}

        /* Ukuran kecil untuk halaman NISN */
        .logo-wrap.logo-sm{margin:2px 0 14px;}
        .logo-sm .logo-glow-1{width:8rem;height:8rem;}
        .logo-sm .logo-glow-2{width:6.5rem;height:6.5rem;}
        .logo-sm .logo-ring-outer{width:7rem;height:7rem;}
        .logo-sm .logo-gold-ring{width:5.9rem;height:5.9rem;}
        .logo-sm .logo-inner{padding:8px;}
        .logo-sm .logo-dot{width:6px;height:6px;}
    </style>
</head>
<body class="bg-gradient-to-br from-emerald-50 via-teal-50 to-cyan-50 min-h-screen flex flex-col items-center justify-center p-4">
    <main class="w-full max-w-4xl bg-white/80 backdrop-blur-xl rounded-3xl shadow-2xl border border-white/50 overflow-hidden">
        <header class="bg-gradient-to-r from-emerald-600 to-teal-600 p-8 text-center text-white relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-full bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PGNpcmNsZSBjeD0iMiIgY3k9IjIiIHI9IjIiIGZpbGw9InJnYmEoMjU1LDI1NSwyNTUsMC4xKSIvPjwvc3ZnPg==')] opacity-30" aria-hidden="true"></div>

            <div class="logo-wrap logo-sm">
                <div class="logo-glow-1"></div>
                <div class="logo-glow-2"></div>

                <div class="logo-ring-outer">
                    <span class="logo-dot top"></span>
                    <span class="logo-dot bottom"></span>
                    <span class="logo-dot left"></span>
                    <span class="logo-dot right"></span>

                    <div class="logo-gold-ring">
                        <div class="logo-inner">
                            <img src="<?= nisn_escape($logoSekolah) ?>"
                                 alt="Logo <?= nisn_escape($namaSekolah) ?>"
                                 loading="lazy"
                                 decoding="async">
                        </div>
                    </div>
                </div>
            </div>

            <h1 class="text-2xl md:text-3xl font-bold mb-2">Daftar Siswa & NISN</h1>
            <p class="text-emerald-100 font-medium">
                <?= nisn_escape($namaSekolah) ?> • TP <?= nisn_escape($tahunPelajaran) ?>
            </p>
        </header>

        <section class="p-6 md:p-8">
            <a href="index.php" class="inline-flex items-center gap-2 text-gray-600 hover:text-emerald-600 font-semibold transition-colors mb-6 group">
                <i class="fa-solid fa-arrow-left group-hover:-translate-x-1 transition-transform" aria-hidden="true"></i>
                Kembali ke Halaman Cek Kelulusan
            </a>

            <form method="get" action="nisn.php" class="mb-6" role="search">
                <label for="cari" class="sr-only">Cari nama atau NISN siswa</label>

                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none" aria-hidden="true">
                        <i class="fa-solid fa-magnifying-glass text-gray-400"></i>
                    </div>

                    <input type="text"
                           id="cari"
                           name="cari"
                           value="<?= nisn_escape($search) ?>"
                           placeholder="Ketik nama atau NISN disini..."
                           autofocus
                           maxlength="100"
                           class="w-full pl-11 pr-12 py-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all outline-none text-base font-medium text-gray-800 placeholder-gray-400 shadow-sm">

                    <?php if ($adaPencarian): ?>
                        <a href="nisn.php"
                           class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-red-500 transition-colors"
                           title="Hapus Pencarian"
                           aria-label="Hapus Pencarian">
                            <i class="fa-solid fa-circle-xmark text-xl" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="mb-4">
                <p class="text-sm text-gray-600 flex items-center gap-2">
                    <i class="fa-solid fa-users text-emerald-600" aria-hidden="true"></i>
                    Menampilkan <strong class="text-gray-800"><?= $totalSiswa ?></strong> data siswa

                    <?php if ($adaPencarian): ?>
                        <span class="bg-emerald-100 text-emerald-800 text-xs px-2 py-0.5 rounded-full font-semibold">
                            Hasil Pencarian
                        </span>
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($queryError !== null): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-lg flex items-center gap-3" role="alert">
                    <i class="fa-solid fa-circle-exclamation text-xl" aria-hidden="true"></i>
                    <span><?= nisn_escape($queryError) ?></span>
                </div>
            <?php elseif ($totalSiswa === 0): ?>
                <div class="text-center py-16 bg-gray-50 rounded-2xl border border-dashed border-gray-300">
                    <i class="fa-solid fa-inbox text-6xl text-gray-300 mx-auto mb-4" aria-hidden="true"></i>

                    <p class="text-gray-500 font-medium text-lg">
                        <?= $adaPencarian
                            ? 'Tidak ada data siswa yang cocok dengan pencarian Anda.'
                            : 'Belum ada data siswa.' ?>
                    </p>

                    <?php if ($adaPencarian): ?>
                        <a href="nisn.php" class="inline-flex items-center gap-2 mt-4 text-emerald-600 hover:text-emerald-800 font-semibold transition-colors">
                            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                            Lihat Semua Data
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto rounded-2xl border border-gray-200 shadow-sm custom-scrollbar">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 text-gray-700 font-semibold border-b border-gray-200 uppercase tracking-wider text-xs">
                            <tr>
                                <th scope="col" class="p-4 w-16 text-center">No</th>
                                <th scope="col" class="p-4">NISN</th>
                                <th scope="col" class="p-4">Nama Siswa</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100 bg-white">
                            <?php foreach ($dataSiswa as $index => $siswa): ?>
                                <?php
                                $nisn = (string) ($siswa['nisn'] ?? '');
                                $nama = (string) ($siswa['nama'] ?? '');
                                ?>
                                <tr class="hover:bg-emerald-50/50 transition-colors duration-200 group">
                                    <td class="p-4 text-center text-gray-500 font-medium">
                                        <?= $index + 1 ?>
                                    </td>

                                    <td class="p-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg font-mono font-semibold text-sm group-hover:bg-emerald-100 group-hover:text-emerald-800 transition-colors">
                                                <i class="fa-solid fa-id-card opacity-60" aria-hidden="true"></i>
                                                <?= nisn_escape($nisn) ?>
                                            </span>

                                            <button type="button"
                                                    class="btn-copy js-copy-nisn inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-emerald-600 hover:bg-emerald-100 transition-all"
                                                    data-nisn="<?= nisn_escape($nisn) ?>"
                                                    title="Salin NISN <?= nisn_escape($nisn) ?>"
                                                    aria-label="Salin NISN <?= nisn_escape($nisn) ?>">
                                                <i class="fa-solid fa-copy" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </td>

                                    <td class="p-4 font-medium text-gray-800">
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid fa-user text-gray-400 text-xs" aria-hidden="true"></i>
                                            <span><?= nisn_escape($nama) ?></span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <footer class="bg-gray-50 px-8 py-4 text-center border-t border-gray-100">
            <p class="text-xs text-gray-500">
                &copy; <?= date('Y') ?> <?= nisn_escape($namaSekolah) ?>. All rights reserved.
            </p>
        </footer>
    </main>

    <div id="toast-container"
         class="fixed bottom-6 left-1/2 z-50 pointer-events-none"
         style="transform: translateX(-50%);"
         aria-live="polite"></div>

    <script>
        /**
         * Salin teks ke clipboard.
         */
        function copyToClipboard(text) {
            return new Promise((resolve, reject) => {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text)
                        .then(() => resolve(true))
                        .catch(() => fallbackCopy(text, resolve, reject));
                } else {
                    fallbackCopy(text, resolve, reject);
                }
            });
        }

        /**
         * Fallback copy untuk browser lama atau non-HTTPS.
         */
        function fallbackCopy(text, resolve, reject) {
            try {
                const textarea = document.createElement('textarea');

                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.top = '0';
                textarea.style.left = '0';
                textarea.style.opacity = '0';

                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();
                textarea.setSelectionRange(0, text.length);

                const success = document.execCommand('copy');

                document.body.removeChild(textarea);

                if (success) {
                    resolve(true);
                } else {
                    reject(new Error('execCommand gagal.'));
                }
            } catch (error) {
                reject(error);
            }
        }

        /**
         * Tampilkan notifikasi toast.
         */
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');

            if (!container) {
                return;
            }

            const existingToast = container.querySelector('.toast');

            if (existingToast) {
                existingToast.remove();
            }

            const toast = document.createElement('div');

            toast.className = 'toast flex items-center gap-3 px-5 py-3 rounded-xl shadow-2xl text-sm font-semibold text-white ' +
                (type === 'success'
                    ? 'bg-gradient-to-r from-emerald-600 to-teal-600'
                    : 'bg-gradient-to-r from-red-500 to-rose-600');

            toast.setAttribute('role', 'status');

            const icon = document.createElement('i');
            icon.className = type === 'success'
                ? 'fa-solid fa-circle-check text-lg'
                : 'fa-solid fa-circle-exclamation text-lg';

            const text = document.createElement('span');
            text.textContent = message;

            toast.append(icon, text);
            container.appendChild(toast);

            toast.classList.add('toast-show');

            setTimeout(() => {
                toast.classList.remove('toast-show');
                toast.classList.add('toast-hide');

                setTimeout(() => toast.remove(), 300);
            }, 2500);
        }

        /**
         * Salin satu NISN.
         */
        function copyNISN(nisn, button) {
            copyToClipboard(nisn)
                .then(() => {
                    const icon = button.querySelector('i');

                    if (icon) {
                        const originalClasses = icon.className;

                        icon.className = 'fa-solid fa-check copy-success';

                        setTimeout(() => {
                            icon.className = originalClasses;
                        }, 1500);
                    }

                    showToast('NISN ' + nisn + ' berhasil disalin!');
                })
                .catch(() => {
                    showToast('Gagal menyalin NISN. Silakan salin manual.', 'error');
                });
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.js-copy-nisn').forEach((button) => {
                button.addEventListener('click', () => {
                    copyNISN(button.dataset.nisn || '', button);
                });
            });
        });
    </script>
</body>
</html>