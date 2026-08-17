<?php
declare(strict_types=1);

require_once 'config.php';

/**
 * Escape output HTML.
 */
function lulus_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Ambil pengaturan dari database dengan nilai default.
 */
function lulus_setting(mysqli $conn, string $key, string $default = ''): string
{
    $value = getPengaturan($conn, $key);

    if ($value === null || $value === false || $value === '') {
        return $default;
    }

    return trim((string) $value);
}

/**
 * Ambil hanya digit dari string.
 */
function lulus_digits_only(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

/**
 * Format nomor WhatsApp untuk ditampilkan, contoh: 0812...
 */
function lulus_format_nomor_wa_tampil(string $nomor): string
{
    $digits = lulus_digits_only($nomor);

    if (strpos($digits, '62') === 0 && strlen($digits) > 2) {
        return '0' . substr($digits, 2);
    }

    return $digits;
}

/**
 * Format nomor WhatsApp untuk link wa.me, contoh: 62812...
 */
function lulus_format_nomor_wa_link(string $nomor): string
{
    $digits = lulus_digits_only($nomor);

    if (strpos($digits, '0') === 0) {
        return '62' . substr($digits, 1);
    }

    if (strpos($digits, '62') === 0) {
        return $digits;
    }

    if (strpos($digits, '8') === 0) {
        return '62' . $digits;
    }

    return $digits;
}

/**
 * Parse waktu mulai pengecekan.
 */
function lulus_parse_waktu_mulai(string $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, $timezone);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Daftar nama bulan dalam Bahasa Indonesia.
 */
function lulus_daftar_bulan_indonesia(): array
{
    return [
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember',
    ];
}

/**
 * Format tanggal dalam Bahasa Indonesia.
 */
function lulus_format_tanggal_indonesia(DateTimeInterface $tanggal): string
{
    $bulan = lulus_daftar_bulan_indonesia();
    $namaBulan = $bulan[$tanggal->format('F')] ?? $tanggal->format('F');

    return $tanggal->format('d ') . $namaBulan . $tanggal->format(' Y, H:i');
}

/**
 * Cari siswa berdasarkan NISN.
 */
function lulus_cari_siswa(mysqli $conn, string $nisn): ?array
{
    $sql = "SELECT nama, status_kelulusan, IFNULL(cetak_skl, 1) AS cetak_skl
            FROM siswa
            WHERE nisn = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        return null;
    }

    $stmt->bind_param('s', $nisn);

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $data = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($data) ? $data : null;
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

$timezone = new DateTimeZone('Asia/Jakarta');

$defaultSettings = [
    'nama_sekolah' => 'MTsN 1 Sekadau',
    'tahun_pelajaran' => '2025/2026',
    'nomor_wa' => '6285752604496',
    'logo_sekolah' => 'logo.png',
    'skl_aktif' => '1',
];

$namaSekolah = lulus_setting($conn, 'nama_sekolah', $defaultSettings['nama_sekolah']);
$tahunPelajaran = lulus_setting($conn, 'tahun_pelajaran', $defaultSettings['tahun_pelajaran']);
$nomorWa = lulus_setting($conn, 'nomor_wa', $defaultSettings['nomor_wa']);
$logoSekolah = lulus_setting($conn, 'logo_sekolah', $defaultSettings['logo_sekolah']);
$sklAktif = lulus_setting($conn, 'skl_aktif', $defaultSettings['skl_aktif']) === '1';

$nomorWaTampil = lulus_format_nomor_wa_tampil($nomorWa);
$nomorWaLink = lulus_format_nomor_wa_link($nomorWa);

$waktuMulaiCek = lulus_parse_waktu_mulai(lulus_setting($conn, 'waktu_mulai_cek'), $timezone);
$waktuSekarang = new DateTimeImmutable('now', $timezone);

$sudahBisaCek = ($waktuMulaiCek instanceof DateTimeImmutable) && $waktuSekarang >= $waktuMulaiCek;
$tanggalMulaiTampil = ($waktuMulaiCek instanceof DateTimeImmutable)
    ? lulus_format_tanggal_indonesia($waktuMulaiCek) . ' WIB'
    : 'Belum ditentukan';

$hasil = null;
$error = null;
$nisnDicari = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $sudahBisaCek) {
    $nisnDicari = trim((string) ($_POST['nisn'] ?? ''));

    if ($nisnDicari === '') {
        $error = 'NISN tidak boleh kosong!';
    } elseif (!ctype_digit($nisnDicari)) {
        $error = 'NISN tidak valid. Silakan masukkan angka saja.';
    } else {
        $hasil = lulus_cari_siswa($conn, $nisnDicari);

        if ($hasil === null) {
            $error = 'NISN tidak ditemukan!';
        }
    }
}

$pesanWa = rawurlencode('Assalamualaikum, saya ingin bertanya tentang pengambilan SKL');
$linkWa = sprintf('https://wa.me/%s?text=%s', $nomorWaLink, $pesanWa);
$pageTitle = sprintf('Cek Kelulusan - %s', $namaSekolah);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Cek kelulusan <?= lulus_escape($namaSekolah) ?> Tahun Pelajaran <?= lulus_escape($tahunPelajaran) ?>.">
    <title><?= lulus_escape($pageTitle) ?></title>

    <link rel="stylesheet" href="dist/output.css">
    <link rel="stylesheet" href="fontawesome.css">
    <link rel="stylesheet" href="fonts.css">

    <style>
        body { font-family: 'Fira Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }

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

        /* Ukuran kecil */
        .logo-wrap.logo-sm{margin:2px 0 14px;}
        .logo-sm .logo-glow-1{width:8rem;height:8rem;}
        .logo-sm .logo-glow-2{width:6.5rem;height:6.5rem;}
        .logo-sm .logo-ring-outer{width:7rem;height:7rem;}
        .logo-sm .logo-gold-ring{width:5.9rem;height:5.9rem;}
        .logo-sm .logo-inner{padding:8px;}
        .logo-sm .logo-dot{width:6px;height:6px;}
    </style>
</head>
<body class="bg-gradient-to-br from-emerald-50 via-teal-50 to-cyan-50 min-h-screen flex items-center justify-center p-4">
    <main class="w-full max-w-2xl bg-white/80 backdrop-blur-xl rounded-3xl shadow-2xl border border-white/50 overflow-hidden">
        <header class="bg-gradient-to-r from-emerald-600 to-teal-600 p-8 text-center text-white relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-full bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PGNpcmNsZSBjeD0iMiIgY3k9IjIiIHI9IjIiIGZpbGw9InJnYmEoMjU1LDI1NSwyNTUsMC4xKSIvPjwvc3ZnPg==')] opacity-30" aria-hidden="true"></div>

            <div class="logo-wrap">
                <div class="logo-glow-1"></div>
                <div class="logo-glow-2"></div>

                <div class="logo-ring-outer">
                    <span class="logo-dot top"></span>
                    <span class="logo-dot bottom"></span>
                    <span class="logo-dot left"></span>
                    <span class="logo-dot right"></span>

                    <div class="logo-gold-ring">
                        <div class="logo-inner">
                            <img src="<?= lulus_escape($logoSekolah) ?>"
                                 alt="Logo <?= lulus_escape($namaSekolah) ?>"
                                 loading="lazy"
                                 decoding="async">
                        </div>
                    </div>
                </div>
            </div>

            <h1 class="text-3xl font-bold mb-2">Cek Kelulusan</h1>
            <p class="text-emerald-100 font-medium">
                <?= lulus_escape($namaSekolah) ?> • TP <?= lulus_escape($tahunPelajaran) ?>
            </p>
        </header>

        <section class="p-8">
            <?php if ($error !== null): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-lg mb-6 flex items-center gap-3" role="alert">
                    <i class="fa-solid fa-circle-exclamation text-xl" aria-hidden="true"></i>
                    <span><?= lulus_escape($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if (is_array($hasil)): ?>
                <?php
                $statusLulus = (int) ($hasil['status_kelulusan'] ?? 0) === 1;
                $bolehCetakSkl = $sklAktif && (int) ($hasil['cetak_skl'] ?? 1) === 1;
                $namaSiswa = (string) ($hasil['nama'] ?? '-');

                $urlCetakSkl = 'skl.php?nisn=' . rawurlencode($nisnDicari);
                $urlUnduhPdf = $urlCetakSkl . '&pdf=1';
                ?>

                <div class="text-center mb-8">
                    <div class="inline-flex items-center gap-2 bg-gray-100 px-4 py-2 rounded-full text-sm text-gray-600 mb-4">
                        <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                        NISN: <?= lulus_escape($nisnDicari) ?>
                    </div>

                    <h2 class="text-2xl font-bold text-gray-800 mb-1">
                        <?= lulus_escape($namaSiswa) ?>
                    </h2>

                    <div class="mt-6 mb-8">
                        <?php if ($statusLulus): ?>
                            <div class="inline-flex items-center gap-3 bg-emerald-100 text-emerald-700 px-8 py-4 rounded-2xl text-3xl font-black shadow-sm border border-emerald-200 animate-pulse" role="status">
                                <i class="fa-solid fa-graduation-cap text-4xl" aria-hidden="true"></i>
                                LULUS
                            </div>
                        <?php else: ?>
                            <div class="inline-flex items-center gap-3 bg-rose-100 text-rose-700 px-8 py-4 rounded-2xl text-3xl font-black shadow-sm border border-rose-200" role="status">
                                <i class="fa-solid fa-book-open text-4xl" aria-hidden="true"></i>
                                TIDAK LULUS
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($statusLulus): ?>
                        <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-6 text-left">
                            <h3 class="font-bold text-emerald-800 mb-2 flex items-center gap-2">
                                <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                                Informasi SKL
                            </h3>

                            <p class="text-emerald-700 text-sm mb-4">
                                Untuk pengambilan SKL, silakan datang langsung ke Madrasah atau hubungi WhatsApp berikut:
                            </p>

                            <a href="<?= lulus_escape($linkWa) ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               title="WhatsApp <?= lulus_escape($nomorWaTampil) ?>"
                               class="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white px-5 py-2.5 rounded-xl font-semibold transition-all shadow-md hover:shadow-lg mb-4">
                                <i class="fa-brands fa-whatsapp text-xl" aria-hidden="true"></i>
                                Hubungi via WhatsApp
                            </a>

                            <?php if ($bolehCetakSkl): ?>
                                <div class="border-t border-emerald-200 pt-4 mt-2">
                                    <p class="text-xs text-emerald-600 mb-3 font-medium">
                                        Atau unduh Surat Keterangan Lulus (SKL) secara langsung:
                                    </p>

                                    <div class="flex flex-wrap gap-3 justify-center">
                                        <a href="<?= lulus_escape($urlCetakSkl) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-all">
                                            <i class="fa-solid fa-print" aria-hidden="true"></i>
                                            Cetak SKL
                                        </a>

                                        <a href="<?= lulus_escape($urlUnduhPdf) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-all">
                                            <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                                            Unduh PDF
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-amber-800 text-sm flex items-start gap-2" role="alert">
                                    <i class="fa-solid fa-circle-info mt-0.5 flex-shrink-0" aria-hidden="true"></i>
                                    <span>
                                        Fitur cetak SKL online saat ini tidak tersedia. Silakan hubungi madrasah untuk informasi lebih lanjut.
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-rose-50 border border-rose-100 rounded-2xl p-6 text-center">
                            <i class="fa-solid fa-heart-crack text-5xl text-rose-400 mx-auto mb-3" aria-hidden="true"></i>
                            <p class="text-rose-800 font-medium">
                                Tetap semangat! Kegagalan ini bukan akhir segalanya. Kamu bisa memperbaiki langkah atau mencari jalan lain yang lebih baik.
                            </p>
                        </div>
                    <?php endif; ?>

                    <a href="index.php" class="inline-flex items-center gap-2 mt-8 text-gray-500 hover:text-emerald-600 font-medium transition-colors">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        Cek NISN Lain
                    </a>
                </div>

            <?php elseif (!$sudahBisaCek): ?>
                <div class="text-center py-8">
                    <div class="inline-flex items-center gap-2 bg-amber-100 text-amber-800 px-4 py-2 rounded-full text-sm font-semibold mb-6">
                        <i class="fa-solid fa-clock" aria-hidden="true"></i>
                        Pengecekan Belum Dibuka
                    </div>

                    <p class="text-gray-600 mb-8">
                        Pengecekan akan dibuka pada:<br>
                        <span class="text-xl font-bold text-gray-800">
                            <?= lulus_escape($tanggalMulaiTampil) ?>
                        </span>
                    </p>

                    <?php if ($waktuMulaiCek instanceof DateTimeImmutable): ?>
                        <div class="grid grid-cols-4 gap-4 max-w-md mx-auto mb-8" id="countdown">
                            <div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100">
                                <span class="block text-3xl font-bold text-emerald-600" id="days">00</span>
                                <span class="text-xs text-gray-500 uppercase">Hari</span>
                            </div>

                            <div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100">
                                <span class="block text-3xl font-bold text-emerald-600" id="hours">00</span>
                                <span class="text-xs text-gray-500 uppercase">Jam</span>
                            </div>

                            <div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100">
                                <span class="block text-3xl font-bold text-emerald-600" id="minutes">00</span>
                                <span class="text-xs text-gray-500 uppercase">Menit</span>
                            </div>

                            <div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100">
                                <span class="block text-3xl font-bold text-emerald-600" id="seconds">00</span>
                                <span class="text-xs text-gray-500 uppercase">Detik</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="bg-gray-50 rounded-xl p-4 text-gray-600 text-sm flex items-center justify-center gap-2">
                        <i class="fa-solid fa-lock text-gray-400" aria-hidden="true"></i>
                        <span>
                            <?php if ($waktuMulaiCek instanceof DateTimeImmutable): ?>
                                Form pengecekan terkunci hingga waktu yang ditentukan.
                            <?php else: ?>
                                Form pengecekan akan dibuka setelah jadwal ditentukan.
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

            <?php else: ?>
                <form method="post" class="space-y-6" autocomplete="off">
                    <div>
                        <label for="nisn" class="block text-sm font-semibold text-gray-700 mb-2">
                            Masukkan NISN Anda
                        </label>

                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none" aria-hidden="true">
                                <i class="fa-solid fa-id-card text-gray-400"></i>
                            </div>

                            <input type="text"
                                   id="nisn"
                                   name="nisn"
                                   value="<?= lulus_escape($nisnDicari) ?>"
                                   required
                                   autofocus
                                   inputmode="numeric"
                                   maxlength="20"
                                   placeholder="Contoh: 1234567890"
                                   class="w-full pl-11 pr-4 py-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all outline-none text-lg font-medium text-gray-800 placeholder-gray-400">
                        </div>

                        <p class="mt-2 text-xs text-gray-500">
                            NISN hanya berisi angka.
                        </p>
                    </div>

                    <button type="submit"
                            class="w-full bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-bold py-4 rounded-xl shadow-lg shadow-emerald-200 transition-all transform hover:scale-[1.02] flex items-center justify-center gap-2">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        Cek Kelulusan
                    </button>
                </form>

                <div class="text-center mt-6">
                    <a href="nisn.php" class="text-sm text-emerald-600 hover:text-emerald-800 font-medium flex items-center justify-center gap-1">
                        <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                        Lupa NISN? Cek disini
                    </a>
                </div>
            <?php endif; ?>
        </section>

        <footer class="bg-gray-50 px-8 py-4 text-center border-t border-gray-100">
            <p class="text-xs text-gray-500">
                &copy; <?= lulus_escape($waktuSekarang->format('Y')) ?> <?= lulus_escape($namaSekolah) ?>. All rights reserved.
            </p>
        </footer>
    </main>

    <?php if (!$sudahBisaCek && $waktuMulaiCek instanceof DateTimeImmutable): ?>
        <script>
            const targetDate = <?= $waktuMulaiCek->getTimestamp() ?> * 1000;

            const countdownElements = {
                days: document.getElementById('days'),
                hours: document.getElementById('hours'),
                minutes: document.getElementById('minutes'),
                seconds: document.getElementById('seconds')
            };

            const pad = (value) => String(value).padStart(2, '0');

            function updateCountdown() {
                const now = Date.now();
                const distance = targetDate - now;

                if (distance < 0) {
                    window.location.reload();
                    return;
                }

                countdownElements.days.textContent = pad(Math.floor(distance / (1000 * 60 * 60 * 24)));
                countdownElements.hours.textContent = pad(Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60)));
                countdownElements.minutes.textContent = pad(Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60)));
                countdownElements.seconds.textContent = pad(Math.floor((distance % (1000 * 60)) / 1000));
            }

            updateCountdown();
            setInterval(updateCountdown, 1000);
        </script>
    <?php endif; ?>
</body>
</html>