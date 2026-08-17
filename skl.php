<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');
ini_set('memory_limit', '256M');
set_time_limit(120);

if (!file_exists(__DIR__ . '/config.php')) {
    die('File config.php tidak ditemukan!');
}

require_once __DIR__ . '/config.php';

if (!function_exists('formatTanggalSKL')) {
    /**
     * Format tanggal untuk SKL dalam Bahasa Indonesia.
     */
    function formatTanggalSKL(?string $tanggal): string
    {
        if ($tanggal === null || trim($tanggal) === '') {
            return date('d F Y');
        }

        try {
            $dt = new DateTime($tanggal);
        } catch (Throwable $e) {
            return $tanggal;
        }

        $bulan = [
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

        return $dt->format('d ') . ($bulan[$dt->format('F')] ?? $dt->format('F')) . $dt->format(' Y');
    }
}

/**
 * Escape output HTML.
 */
function skl_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Ambil pengaturan SKL dari database.
 */
function skl_setting(mysqli $conn, string $key, string $default = ''): string
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
 * Ambil path file lokal yang aman untuk PDF.
 */
function skl_get_absolute_path(string $path): string
{
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return '';
    }

    $base = realpath(__DIR__);

    if ($base === false) {
        return '';
    }

    $normalized = ltrim($path, '/\\');
    $candidates = [
        $path,
        rtrim(__DIR__, '/\\') . '/' . $normalized,
    ];

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);

        if ($real === false || !is_file($real)) {
            continue;
        }

        if (strpos($real, $base) === 0) {
            return $real;
        }
    }

    return '';
}

/**
 * Ambil sumber gambar untuk HTML.
 */
function skl_get_asset_source(string $path, bool $useAbsolutePath): string
{
    if ($path === '') {
        return '';
    }

    $absolute = skl_get_absolute_path($path);

    if ($absolute === '') {
        return '';
    }

    return $useAbsolutePath ? $absolute : $path;
}

/**
 * Ambil data siswa berdasarkan NISN.
 */
function skl_fetch_siswa(mysqli $conn, string $nisn): ?array
{
    try {
        $stmt = $conn->prepare(
            "SELECT nama, nisn, status_kelulusan, IFNULL(cetak_skl, 1) AS cetak_skl
             FROM siswa
             WHERE nisn = ?
             LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param('s', $nisn);

            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                if (is_array($row)) {
                    return $row;
                }
            } else {
                $stmt->close();
            }
        }
    } catch (Throwable $e) {
        error_log('SKL fetch siswa error: ' . $e->getMessage());
    }

    // Fallback jika kolom cetak_skl belum tersedia.
    try {
        $stmt = $conn->prepare(
            "SELECT nama, nisn, status_kelulusan
             FROM siswa
             WHERE nisn = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $nisn);

        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (is_array($row)) {
            $row['cetak_skl'] = 1;
            return $row;
        }

        return null;
    } catch (Throwable $e) {
        error_log('SKL fetch siswa fallback error: ' . $e->getMessage());
        return null;
    }
}

/**
 * CSS untuk PDF Dompdf.
 */
function skl_pdf_css(): string
{
    return <<<'CSS'
<style>
    @page {
        margin: 15mm 20mm 15mm 20mm;
        size: A4 portrait;
    }

    body {
        font-family: "Times New Roman", Times, serif;
        margin: 0;
        padding: 0;
        font-size: 13pt;
        line-height: 1.5;
        color: #000;
    }

    .skl-container {
        width: 100%;
        margin: 0;
        padding: 0;
    }

    .kop-surat {
        text-align: left;
        margin-bottom: 8px;
        border-bottom: 3px double #000;
        padding-bottom: 8px;
    }

    .kop-img {
        max-width: 100%;
        max-height: 160px;
        display: block;
        margin: 0 auto;
    }

    .judul {
        text-align: center;
        font-size: 14pt;
        font-weight: bold;
        text-decoration: underline;
        margin: 8px 0 4px 0;
        text-transform: uppercase;
        line-height: 1.2;
    }

    .nomor {
        text-align: center;
        font-size: 13pt;
        margin: 0 0 12px 0;
    }

    .pengantar {
        margin: 0 0 8px 0;
        text-align: justify;
        text-indent: 40px;
        line-height: 1.4;
        font-size: 13pt;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin: 8px 0 12px 0;
        font-size: 13pt;
    }

    .data-table td {
        padding: 2px 0;
        vertical-align: top;
        line-height: 1.4;
    }

    .data-table .label {
        width: 130px;
        font-weight: normal;
    }

    .data-table .colon {
        width: 10px;
    }

    .isi-surat {
        margin: 8px 0 12px 0;
        font-size: 13pt;
        line-height: 1.4;
        text-align: justify;
    }

    .isi-surat p {
        text-indent: 40px;
        margin: 0;
    }

    .ttd-section {
        margin-top: 15px;
        width: 100%;
        page-break-inside: avoid;
    }

    .ttd-kanan {
        float: right;
        width: 260px;
        text-align: left;
        margin-right: -100px;
    }

    .ttd-kanan p {
        font-size: 13pt;
        margin: 1px 0;
        line-height: 1.3;
    }

    .tempat-tgl {
        margin-bottom: 2px;
    }

    .jabatan {
        margin-bottom: 0;
    }

    .ttd-img {
        max-width: 100%;
        max-height: 140px;
        margin: 0 0 0 -50px;
        display: block;
    }

    .nama-kepala {
        font-weight: bold;
        text-decoration: underline;
        margin-top: -10px;
        margin-bottom: 0;
    }

    .nip {
        font-size: 13pt;
        margin-top: 0;
    }

    .clear-both {
        clear: both;
    }
</style>
CSS;
}

/**
 * CSS untuk preview browser.
 */
function skl_browser_css(): string
{
    return <<<'CSS'
<style>
    body.page-skl {
        font-family: 'Times New Roman', Times, serif;
        background: #f5f5f5;
        padding: 20px;
    }

    .action-bar {
        max-width: 210mm;
        margin: 0 auto 20px;
        display: flex;
        gap: 10px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
        font-family: 'Fira Sans', sans-serif;
    }

    .btn-print {
        background: #4CAF50;
        color: white;
    }

    .btn-print:hover {
        background: #45a049;
    }

    .btn-pdf {
        background: #f44336;
        color: white;
    }

    .btn-pdf:hover {
        background: #d32f2f;
    }

    .btn-back {
        background: #667eea;
        color: white;
    }

    .btn-back:hover {
        background: #5568d3;
    }

    .skl-container {
        max-width: 210mm;
        margin: 0 auto;
        background: white;
        padding: 15mm 20mm;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        min-height: 297mm;
        font-size: 12pt;
        line-height: 1.5;
    }

    .kop-surat {
        text-align: center;
        margin-bottom: 15px;
        border-bottom: 3px double #000;
        padding-bottom: 10px;
    }

    .kop-img {
        max-width: 100%;
        max-height: 140px;
        display: block;
        margin: 0 auto;
    }

    .judul {
        text-align: center;
        font-size: 16pt;
        font-weight: bold;
        text-decoration: underline;
        margin: 15px 0 5px 0;
        text-transform: uppercase;
    }

    .nomor {
        text-align: center;
        font-size: 12pt;
        margin: 0 0 20px 0;
    }

    .pengantar {
        margin: 0 0 15px 0;
        text-align: justify;
        text-indent: 50px;
        line-height: 1.5;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
    }

    .data-table td {
        padding: 3px 0;
        vertical-align: top;
        line-height: 1.5;
    }

    .data-table .label {
        width: 140px;
    }

    .data-table .colon {
        width: 10px;
    }

    .isi-surat {
        margin: 15px 0;
        text-align: justify;
    }

    .isi-surat p {
        text-indent: 50px;
        margin: 0;
        line-height: 1.5;
    }

    .ttd-section {
        margin-top: 30px;
        width: 100%;
    }

    .ttd-kanan {
        float: right;
        width: 280px;
        text-align: left;
        margin-right: -100px;
    }

    .ttd-kanan p {
        font-size: 12pt;
        margin: 0;
        line-height: 1.4;
    }

    .ttd-img {
        max-width: 100%;
        max-height: 140px;
        margin: 0 0 0 -40px;
        display: block;
    }

    .nama-kepala {
        font-weight: bold;
        text-decoration: underline;
        margin-top: 0;
        margin-bottom: 0;
    }

    .nip {
        font-size: 11pt;
        margin-top: 0;
    }

    .clear-both {
        clear: both;
    }

    @media print {
        body {
            background: white;
            padding: 0;
        }

        .action-bar {
            display: none;
        }

        .skl-container {
            box-shadow: none;
            margin: 0;
            padding: 15mm 20mm;
            width: 100%;
            min-height: auto;
        }

        @page {
            size: A4;
            margin: 15mm 20mm;
        }
    }
</style>
CSS;
}

/**
 * Bangun HTML isi SKL.
 */
function skl_build_document(array $siswa, array $settings, bool $useAbsolutePath = false): string
{
    $kopSrc = skl_get_asset_source((string) ($settings['kop'] ?? ''), $useAbsolutePath);
    $ttdSrc = skl_get_asset_source((string) ($settings['ttd'] ?? ''), $useAbsolutePath);

    $kopHtml = '';

    if ($kopSrc !== '') {
        $kopHtml = '<div class="kop-surat"><img src="' . skl_escape($kopSrc) . '" class="kop-img"></div>';
    }

    $ttdHtml = '';

    if ($ttdSrc !== '') {
        $ttdHtml = '<img src="' . skl_escape($ttdSrc) . '" class="ttd-img" alt="TTD">';
    }

    $nipHtml = '';

    if (trim((string) ($settings['nip'] ?? '')) !== '') {
        $nipHtml = '<p class="nip">NIP. ' . skl_escape((string) $settings['nip']) . '</p>';
    }

    return '
<div class="skl-container">
    ' . $kopHtml . '

    <div class="skl-content">
        <h1 class="judul">' . skl_escape((string) ($settings['judul'] ?? '')) . '</h1>
        <p class="nomor">Nomor: ' . skl_escape((string) ($settings['nomor'] ?? '')) . '</p>

        <p class="pengantar">
            Yang bertanda tangan di bawah ini, Kepala ' . skl_escape((string) ($settings['nama_sekolah'] ?? '')) . ', menerangkan bahwa:
        </p>

        <table class="data-table">
            <tr>
                <td class="label">Nama</td>
                <td class="colon">:</td>
                <td>' . skl_escape((string) ($siswa['nama'] ?? '')) . '</td>
            </tr>
            <tr>
                <td class="label">NISN</td>
                <td class="colon">:</td>
                <td>' . skl_escape((string) ($siswa['nisn'] ?? '')) . '</td>
            </tr>
            <tr>
                <td class="label">Madrasah</td>
                <td class="colon">:</td>
                <td>' . skl_escape((string) ($settings['nama_sekolah'] ?? '')) . '</td>
            </tr>
            <tr>
                <td class="label">Tahun Pelajaran</td>
                <td class="colon">:</td>
                <td>' . skl_escape((string) ($settings['tahun_pelajaran'] ?? '')) . '</td>
            </tr>
        </table>

        <div class="isi-surat">
            <p>' . nl2br(skl_escape((string) ($settings['isi'] ?? ''))) . '</p>
        </div>

        <div class="ttd-section">
            <div class="ttd-kanan">
                <p class="tempat-tgl">' . skl_escape((string) ($settings['kabupaten'] ?? '')) . ', ' . skl_escape(formatTanggalSKL((string) ($settings['tanggal'] ?? ''))) . '</p>
                <p class="jabatan">' . skl_escape((string) ($settings['jabatan'] ?? '')) . '</p>

                ' . $ttdHtml . '

                <p class="nama-kepala">' . skl_escape((string) ($settings['kepala'] ?? '')) . '</p>
                ' . $nipHtml . '
            </div>

            <div class="clear-both"></div>
        </div>
    </div>
</div>';
}

/**
 * Tampilkan halaman error yang rapi.
 */
function skl_error_page(string $title, string $message, string $nisn = ''): void
{
    $backUrl = $nisn !== ''
        ? 'skl.php?nisn=' . rawurlencode($nisn)
        : 'index.php';

    $titleSafe = skl_escape($title);
    $messageSafe = skl_escape($message);
    $backUrlSafe = skl_escape($backUrl);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= $titleSafe ?></title>

        <link rel="stylesheet" href="dist/output.css">
        <link rel="stylesheet" href="fontawesome.css">
        <link rel="stylesheet" href="fonts.css">

        <style>
            body { font-family: 'Fira Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        </style>
    </head>
    <body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
        <div class="bg-white p-8 rounded-2xl shadow-lg max-w-md text-center">
            <h2 class="text-2xl font-bold text-red-600 mb-4 flex items-center justify-center gap-2">
                <i class="fa-solid fa-triangle-exclamation text-3xl" aria-hidden="true"></i>
                <?= $titleSafe ?>
            </h2>

            <p class="text-gray-600 mb-6"><?= $messageSafe ?></p>

            <a href="<?= $backUrlSafe ?>"
               class="inline-block bg-emerald-600 text-white px-6 py-3 rounded-xl font-semibold hover:bg-emerald-700 transition flex items-center justify-center gap-2">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Kembali
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    $conn = getDbConnection();
} catch (Throwable $e) {
    error_log('SKL DB connection error: ' . $e->getMessage());
    http_response_code(500);
    skl_error_page('Kesalahan Sistem', 'Database tidak tersedia. Silakan coba beberapa saat lagi.');
}

if (!$conn instanceof mysqli) {
    http_response_code(500);
    skl_error_page('Kesalahan Sistem', 'Koneksi database tidak tersedia.');
}

$sklAktif = skl_setting($conn, 'skl_aktif', '1');

if ($sklAktif !== '1') {
    header('Location: index.php');
    exit;
}

$rawNisn = $_POST['nisn'] ?? $_GET['nisn'] ?? '';
$nisn = is_scalar($rawNisn) ? trim((string) $rawNisn) : '';

if ($nisn === '' || !ctype_digit($nisn)) {
    header('Location: index.php');
    exit;
}

$siswa = skl_fetch_siswa($conn, $nisn);

if ($siswa === null) {
    header('Location: index.php');
    exit;
}

// Jika admin menonaktifkan cetak SKL untuk siswa tertentu.
if ((int) ($siswa['cetak_skl'] ?? 1) !== 1) {
    header('Location: index.php');
    exit;
}

$namaSekolah = skl_setting($conn, 'nama_sekolah', 'MTsN 1 Sekadau');
$tahunPelajaran = skl_setting($conn, 'tahun_pelajaran', '2025/2026');

$sklKop = skl_setting($conn, 'skl_kop_surat');
$sklNamaKabupaten = skl_setting($conn, 'skl_nama_kabupaten', 'Sekadau');
$sklNomor = skl_setting($conn, 'skl_nomor_surat', '421/MTsN1-SEK/2026');
$sklTanggal = skl_setting($conn, 'skl_tanggal_surat', date('Y-m-d'));
$sklIsi = skl_setting($conn, 'skl_isi_surat', 'Menerangkan dengan sesungguhnya bahwa siswa tersebut telah mengikuti proses pembelajaran dan dinyatakan lulus/tidak lulus.');
$sklNamaKepala = skl_setting($conn, 'skl_nama_kepala');
$sklJabatan = skl_setting($conn, 'skl_jabatan_kepala', 'Kepala Madrasah');
$sklNip = skl_setting($conn, 'skl_nip_kepala');
$sklTtd = skl_setting($conn, 'skl_ttd_kepala');

$statusLulus = (int) ($siswa['status_kelulusan'] ?? 0) === 1;

$judulSkl = $statusLulus
    ? 'SURAT KETERANGAN LULUS'
    : 'SURAT KETERANGAN TIDAK LULUS';

$statusTeks = $statusLulus ? 'LULUS' : 'TIDAK LULUS';

$nomorSkl = str_replace('{status}', $statusTeks, $sklNomor);

$isiSurat = str_replace(
    [
        '{nama}',
        '{nisn}',
        '{sekolah}',
        '{tahun}',
        '{tanggal}',
        '{nomor}',
        '{kepala}',
        '{jabatan}',
        '{kabupaten}',
    ],
    [
        (string) ($siswa['nama'] ?? ''),
        (string) ($siswa['nisn'] ?? ''),
        $namaSekolah,
        $tahunPelajaran,
        formatTanggalSKL($sklTanggal),
        $nomorSkl,
        $sklNamaKepala,
        $sklJabatan,
        $sklNamaKabupaten,
    ],
    $sklIsi
);

$settings = [
    'judul' => $judulSkl,
    'nomor' => $nomorSkl,
    'isi' => $isiSurat,
    'nama_sekolah' => $namaSekolah,
    'tahun_pelajaran' => $tahunPelajaran,
    'kabupaten' => $sklNamaKabupaten,
    'tanggal' => $sklTanggal,
    'kepala' => $sklNamaKepala,
    'jabatan' => $sklJabatan,
    'nip' => $sklNip,
    'kop' => $sklKop,
    'ttd' => $sklTtd,
];

$modePdf = isset($_GET['pdf']) && $_GET['pdf'] === '1';

// ==================== MODE PDF ====================
if ($modePdf) {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        http_response_code(500);
        skl_error_page(
            'Dompdf Belum Terinstall',
            'Gunakan fitur Cetak SKL lalu pilih "Save as PDF" pada dialog print browser, atau instal Dompdf melalui Composer.',
            $nisn
        );
    }

    try {
        require_once __DIR__ . '/vendor/autoload.php';

        if (!class_exists('Dompdf\Dompdf')) {
            throw new RuntimeException('Library Dompdf tidak ditemukan.');
        }

        $htmlContent = skl_build_document($siswa, $settings, true);

        $fullHtml = '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    ' . skl_pdf_css() . '
</head>
<body>
    ' . $htmlContent . '
</body>
</html>';

        $options = new \Dompdf\Options([
            'defaultFont' => 'times',
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => false,
            'chroot' => __DIR__,
        ]);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($fullHtml, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        if (ob_get_level()) {
            ob_end_clean();
        }

        $filename = 'SKL_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($siswa['nisn'] ?? $nisn)) . '.pdf';

        $dompdf->stream($filename, [
            'Attachment' => true,
            'compress' => true,
        ]);

        exit;
    } catch (Throwable $e) {
        error_log('SKL PDF error: ' . $e->getMessage());
        http_response_code(500);
        skl_error_page(
            'Gagal Membuat PDF',
            'Terjadi kesalahan saat membuat file PDF. Silakan gunakan tombol Cetak SKL lalu pilih "Save as PDF".',
            $nisn
        );
    }
}

$pdfUrl = 'skl.php?nisn=' . rawurlencode($nisn) . '&pdf=1';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= skl_escape($judulSkl) ?> - <?= skl_escape((string) ($siswa['nama'] ?? '')) ?></title>

    <link rel="stylesheet" href="dist/output.css">
    <link rel="stylesheet" href="fontawesome.css">
    <link rel="stylesheet" href="fonts.css">

    <?= skl_browser_css() ?>
</head>
<body class="page-skl">
    <div class="action-bar no-print">
        <a href="index.php" class="btn btn-back">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            Kembali
        </a>

        <button type="button" id="btn-print" class="btn btn-print">
            <i class="fa-solid fa-print" aria-hidden="true"></i>
            Cetak SKL
        </button>

        <a href="<?= skl_escape($pdfUrl) ?>" class="btn btn-pdf">
            <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
            Unduh PDF
        </a>
    </div>

    <?= skl_build_document($siswa, $settings, false) ?>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const printButton = document.getElementById('btn-print');

            if (printButton) {
                printButton.addEventListener('click', function () {
                    window.print();
                });
            }
        });
    </script>
</body>
</html>