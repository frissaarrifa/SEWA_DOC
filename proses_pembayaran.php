
<?php
session_start();
require_once "koneksi.php";

// ========================================
// 1. CEK LOGIN DAN HAK AKSES
// ========================================
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['role'] ?? '') !== 'peminjam') {
    if (in_array($_SESSION['role'] ?? '', ['admin', 'petugas'])) {
        header("Location: index.php");
    } else {
        header("Location: login.php");
    }
    exit;
}

$id_user = (int) $_SESSION['id_user'];
$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Peminjam';

function e($nilai) {
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

function rupiah($angka) {
    return 'Rp ' . number_format((float) $angka, 0, ',', '.');
}

function redirect_pembayaran($id, $status) {
    header(
        "Location: proses_pembayaran.php?id_peminjaman="
        . (int) $id
        . "&status="
        . urlencode($status)
    );
    exit;
}

$id_peminjaman = (int) (
    $_POST['id_peminjaman']
    ?? $_GET['id_peminjaman']
    ?? 0
);

if ($id_peminjaman <= 0) {
    header("Location: pembayaran_saya.php");
    exit;
}

$pesan = '';
$jenis_pesan = 'danger';

// ========================================
// 2. PESAN SETELAH REDIRECT
// ========================================
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'berhasil') {
        $pesan = 'Pengajuan pembayaran berhasil dikirim. Silakan menunggu konfirmasi petugas.';
        $jenis_pesan = 'success';
    } elseif ($_GET['status'] === 'menunggu') {
        $pesan = 'Pembayaran ini masih menunggu konfirmasi petugas.';
        $jenis_pesan = 'warning';
    } elseif ($_GET['status'] === 'lunas') {
        $pesan = 'Peminjaman ini sudah dibayar dan dikonfirmasi.';
        $jenis_pesan = 'success';
    }
}

// ========================================
// 3. PROSES PENGAJUAN PEMBAYARAN
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $metode = $_POST['metode_pembayaran'] ?? '';
    $file_bukti = null;
    $file_baru = null;
    $path_file_baru = null;
    $transaksi_dimulai = false;

    try {
        if (!in_array($metode, ['Tunai', 'DANA'], true)) {
            throw new Exception('Pilih metode pembayaran yang tersedia.');
        }

        mysqli_begin_transaction($conn);
        $transaksi_dimulai = true;

        // Kunci data peminjaman agar tidak diproses bersamaan
        $stmt = mysqli_prepare($conn, "
            SELECT
                id_peminjaman,
                kode_peminjaman,
                status,
                total_biaya,
                status_pembayaran
            FROM peminjaman
            WHERE id_peminjaman = ?
              AND id_user_peminjam = ?
            FOR UPDATE
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "ii",
            $id_peminjaman,
            $id_user
        );

        mysqli_stmt_execute($stmt);
        $hasil = mysqli_stmt_get_result($stmt);
        $pinjam = mysqli_fetch_assoc($hasil);
        mysqli_stmt_close($stmt);

        if (!$pinjam) {
            throw new Exception(
                'Peminjaman tidak ditemukan atau bukan milik akunmu.'
            );
        }

        if (!in_array($pinjam['status'], ['Disetujui', 'Dipinjam'], true)) {
            throw new Exception(
                'Pembayaran hanya dapat diajukan setelah peminjaman disetujui petugas.'
            );
        }

        if ($pinjam['status_pembayaran'] === 'Sudah Dibayar') {
            throw new Exception(
                'Peminjaman ini sudah dinyatakan lunas.'
            );
        }

        // Periksa pembayaran terakhir
        $stmt = mysqli_prepare($conn, "
            SELECT id_pembayaran, status_pembayaran
            FROM pembayaran
            WHERE id_peminjaman = ?
            ORDER BY id_pembayaran DESC
            LIMIT 1
            FOR UPDATE
        ");

        mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
        mysqli_stmt_execute($stmt);
        $hasil = mysqli_stmt_get_result($stmt);
        $pembayaran_terakhir = mysqli_fetch_assoc($hasil);
        mysqli_stmt_close($stmt);

        if ($pembayaran_terakhir) {
            if (
                $pembayaran_terakhir['status_pembayaran']
                === 'Menunggu Konfirmasi'
            ) {
                throw new Exception(
                    'Pembayaran sebelumnya masih menunggu konfirmasi petugas.'
                );
            }

            if (
                $pembayaran_terakhir['status_pembayaran']
                === 'Dikonfirmasi'
            ) {
                throw new Exception(
                    'Pembayaran sebelumnya sudah dikonfirmasi.'
                );
            }
        }

        // Untuk pembayaran DANA, peminjam wajib mengunggah gambar bukti transfer.
        // Petugas tetap harus memeriksa transaksi sebelum menyatakan lunas.
        if ($metode === 'DANA') {
            if (!isset($_FILES['bukti_pembayaran']) || $_FILES['bukti_pembayaran']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Unggah foto atau tangkapan layar bukti pembayaran DANA terlebih dahulu.');
            }

            $unggahan = $_FILES['bukti_pembayaran'];
            if ((int) $unggahan['size'] > 5 * 1024 * 1024) {
                throw new Exception('Ukuran bukti pembayaran maksimal 5 MB.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($unggahan['tmp_name']);
            $ekstensi_valid = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];
            if (!isset($ekstensi_valid[$mime])) {
                throw new Exception('Bukti harus berupa gambar JPG, PNG, atau WEBP.');
            }

            $folder_bukti = __DIR__ . '/uploads/bukti_pembayaran';
            if (!is_dir($folder_bukti) && !mkdir($folder_bukti, 0755, true) && !is_dir($folder_bukti)) {
                throw new Exception('Folder penyimpanan bukti pembayaran tidak dapat dibuat.');
            }

            $nama_file = 'bukti_' . $id_user . '_' . bin2hex(random_bytes(8)) . '.' . $ekstensi_valid[$mime];
            $path_file_baru = $folder_bukti . '/' . $nama_file;
            if (!move_uploaded_file($unggahan['tmp_name'], $path_file_baru)) {
                $path_file_baru = null;
                throw new Exception('Bukti pembayaran gagal disimpan. Coba lagi.');
            }
            $file_bukti = 'uploads/bukti_pembayaran/' . $nama_file;
        }

        // Nominal diambil dari database, bukan dari input pengguna
        $jumlah_bayar = (float) $pinjam['total_biaya'];

        if ($jumlah_bayar <= 0) {
            throw new Exception('Total tagihan tidak valid.');
        }

        // Simpan sebagai menunggu konfirmasi.
        // Tidak langsung mengubah status peminjaman menjadi lunas.
        $stmt = mysqli_prepare($conn, "
            INSERT INTO pembayaran (
                id_peminjaman,
                metode_pembayaran,
                jumlah_bayar,
                status_pembayaran,
                bukti_pembayaran,
                catatan,
                waktu_bayar
            ) VALUES (?, ?, ?, 'Menunggu Konfirmasi', ?, NULL, NOW())
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "isds",
            $id_peminjaman,
            $metode,
            $jumlah_bayar,
            $file_bukti
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Catat aktivitas jika tabel log tersedia
        $aktivitas = "Mengajukan pembayaran";
        $detail = "Kode peminjaman: "
            . $pinjam['kode_peminjaman']
            . "; metode: "
            . $metode
            . "; nominal: "
            . rupiah($jumlah_bayar);

        $stmt_log = @mysqli_prepare($conn, "
            INSERT INTO log_aktivitas (id_user, aktivitas, detail)
            VALUES (?, ?, ?)
        ");

        if ($stmt_log) {
            mysqli_stmt_bind_param(
                $stmt_log,
                "iss",
                $id_user,
                $aktivitas,
                $detail
            );

            @mysqli_stmt_execute($stmt_log);
            mysqli_stmt_close($stmt_log);
        }

        mysqli_commit($conn);
        $transaksi_dimulai = false;

        redirect_pembayaran($id_peminjaman, 'berhasil');

    } catch (Throwable $e) {
        if ($transaksi_dimulai) {
            mysqli_rollback($conn);
        }

        // Hapus file jika sudah terunggah tetapi transaksi gagal
        if ($path_file_baru && is_file($path_file_baru)) {
            @unlink($path_file_baru);
        }

        $pesan = $e->getMessage();
        $jenis_pesan = 'danger';
    }
}

// ========================================
// 4. AMBIL DATA PEMINJAMAN MILIK PENGGUNA
// ========================================
$stmt = mysqli_prepare($conn, "
    SELECT
        p.id_peminjaman,
        p.kode_peminjaman,
        p.tanggal_pinjam,
        p.tanggal_rencana_kembali,
        p.status,
        p.total_biaya,
        p.status_pembayaran
    FROM peminjaman p
    WHERE p.id_peminjaman = ?
      AND p.id_user_peminjam = ?
    LIMIT 1
");

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id_peminjaman,
    $id_user
);

mysqli_stmt_execute($stmt);
$hasil = mysqli_stmt_get_result($stmt);
$pinjam = mysqli_fetch_assoc($hasil);
mysqli_stmt_close($stmt);

if (!$pinjam) {
    http_response_code(404);
    exit('Data peminjaman tidak ditemukan atau bukan milik akunmu.');
}

// Ambil status pembayaran terbaru
$stmt = mysqli_prepare($conn, "
    SELECT
        status_pembayaran,
        metode_pembayaran,
        catatan
    FROM pembayaran
    WHERE id_peminjaman = ?
    ORDER BY id_pembayaran DESC
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
mysqli_stmt_execute($stmt);
$hasil = mysqli_stmt_get_result($stmt);
$pembayaran_terakhir = mysqli_fetch_assoc($hasil);
mysqli_stmt_close($stmt);

$status_terakhir = $pembayaran_terakhir['status_pembayaran'] ?? '';
$status_pinjam = $pinjam['status'];

$sudah_lunas = (
    $pinjam['status_pembayaran'] === 'Sudah Dibayar'
    || $status_terakhir === 'Dikonfirmasi'
);

$masih_menunggu = (
    $status_terakhir === 'Menunggu Konfirmasi'
);

$boleh_bayar = (
    in_array($status_pinjam, ['Disetujui', 'Dipinjam'], true)
    && !$sudah_lunas
    && !$masih_menunggu
);

// QR DANA statis milik pengguna
$qr_path = __DIR__ . '/gambar/qr_dana.png';
$qr_tersedia = is_file($qr_path);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Proses Pembayaran | SEWA_DOC</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f2f7fc;
            font-family: Arial, sans-serif;
            color: #26384a;
        }


        .card-custom {
            background: white;
            padding: 25px;
            border-radius: 14px;
            margin-bottom: 22px;
            box-shadow: 0 3px 15px #dce7ef;
        }

        .label {
            color: #718096;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .value {
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .total {
            color: #145374;
            font-size: 25px;
            font-weight: bold;
        }

        .btn-blue {
            background: #287eaa;
            color: white;
            border: none;
        }

        .btn-blue:hover {
            background: #145374;
            color: white;
        }

        .qr-image {
            display: block;
            width: 100%;
            max-width: 300px;
            height: auto;
            margin: 15px auto;
            border: 1px solid #dce7ef;
            border-radius: 12px;
            padding: 8px;
            background: white;
        }

        .method-box {
            background: #f7fbff;
            border: 1px solid #dce7ef;
            border-radius: 10px;
            padding: 16px;
        }

        @media (max-width: 768px) {
            .card-custom { padding: 17px; }
        }
    </style>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>

<?php include "sidebar_peminjam.php"; ?>

<main class="main-content">

    <div class="card-custom">
        <h3 class="mb-2">Proses Pembayaran</h3>
        <div class="text-secondary">
            Halo, <?= e($nama) ?>. Periksa tagihan sebelum mengajukan pembayaran.
        </div>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= e($jenis_pesan) ?> alert-dismissible fade show">
            <?= e($pesan) ?>
            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Tutup"
            ></button>
        </div>
    <?php endif; ?>

    <div class="card-custom">
        <h5 class="mb-4">Rincian Tagihan</h5>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="label">Kode Peminjaman</div>
                <div class="value"><?= e($pinjam['kode_peminjaman']) ?></div>
            </div>

            <div class="col-md-6">
                <div class="label">Status Peminjaman</div>
                <div class="value"><?= e($pinjam['status']) ?></div>
            </div>

            <div class="col-md-6">
                <div class="label">Tanggal Pinjam</div>
                <div class="value"><?= e($pinjam['tanggal_pinjam']) ?></div>
            </div>

            <div class="col-md-6">
                <div class="label">Rencana Kembali</div>
                <div class="value"><?= e($pinjam['tanggal_rencana_kembali']) ?></div>
            </div>

            <div class="col-12">
                <hr>
                <div class="label">Total Tagihan</div>
                <div class="total"><?= rupiah($pinjam['total_biaya']) ?></div>
            </div>
        </div>
    </div>

    <?php if ($sudah_lunas): ?>

        <div class="alert alert-success">
            <h5>Pembayaran Sudah Dikonfirmasi</h5>
            Tagihan ini telah dinyatakan lunas oleh petugas.
        </div>

    <?php elseif ($masih_menunggu): ?>

        <div class="alert alert-warning">
            <h5>Menunggu Konfirmasi Petugas</h5>
            Pengajuan pembayaranmu sedang diperiksa.

            <?php if ($pembayaran_terakhir): ?>
                <div class="mt-2">
                    Metode:
                    <strong><?= e($pembayaran_terakhir['metode_pembayaran']) ?></strong>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif (!$boleh_bayar): ?>

        <div class="alert alert-info">
            Pembayaran belum dapat diajukan. Pastikan peminjaman sudah disetujui petugas.
        </div>

    <?php else: ?>

        <div class="card-custom">
            <h5 class="mb-3">Pilih Metode Pembayaran</h5>

            <form
                method="POST"
                action="proses_pembayaran.php?id_peminjaman=<?= (int) $id_peminjaman ?>"
                enctype="multipart/form-data"
                id="formPembayaran"
            >
                <input
                    type="hidden"
                    name="id_peminjaman"
                    value="<?= (int) $id_peminjaman ?>"
                >

                <div class="method-box mb-3">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="radio"
                            name="metode_pembayaran"
                            id="tunai"
                            value="Tunai"
                            required
                        >

                        <label class="form-check-label" for="tunai">
                            <strong>Tunai</strong>
                            <div class="small text-secondary">
                                Bayar langsung kepada petugas. Petugas harus memeriksa uang yang diterima.
                            </div>
                        </label>
                    </div>
                </div>

                <div class="method-box mb-4">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="radio"
                            name="metode_pembayaran"
                            id="dana"
                            value="DANA"
                            required
                        >

                        <label class="form-check-label" for="dana">
                            <strong>DANA</strong>
                            <div class="small text-secondary">
                                Bayar dengan memindai kode QR menggunakan aplikasi DANA, lalu unggah tangkapan layar bukti transfer.
                            </div>
                        </label>
                    </div>
                </div>

                <div id="bagianDana" class="border rounded-3 p-3 mb-4" hidden>
                    <h6>Bayar dengan DANA</h6>

                    <?php if ($qr_tersedia): ?>

                        <img
                            src="gambar/qr_dana.png"
                            alt="Kode QR pembayaran"
                            class="qr-image"
                        >

                        <p class="small text-secondary text-center">
                            Pindai QR di atas menggunakan aplikasi DANA dan pastikan nominal transfer sesuai tagihan.
                        </p>
                        <label for="bukti_pembayaran" class="form-label mt-3">Bukti Pembayaran DANA <span class="text-danger">*</span></label>
                        <input type="file" name="bukti_pembayaran" id="bukti_pembayaran" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <div class="form-text">Unggah tangkapan layar transaksi berhasil (JPG, PNG, atau WEBP, maksimal 5 MB).</div>

                    <?php else: ?>

                        <div class="alert alert-warning">
                            QR DANA belum tersedia. Simpan gambar QR milikmu dengan nama
                            <strong>qr_dana.png</strong> di folder
                            <strong>gambar</strong> pada proyek SEWA_DOC.
                        </div>

                    <?php endif; ?>
                </div>

                <div class="alert alert-info">
                    Pengajuan pembayaran belum berarti lunas. Status akan berubah setelah petugas memverifikasi pembayaran.
                </div>

                <button type="submit" class="btn btn-blue px-4">
                    Kirim Pengajuan Pembayaran
                </button>

                <a href="pembayaran_saya.php" class="btn btn-outline-secondary ms-2">
                    Kembali
                </a>
            </form>
        </div>

    <?php endif; ?>

    <a href="pembayaran_saya.php" class="btn btn-outline-secondary">
        Kembali ke Pembayaran Saya
    </a>

</main>

<script>
const pilihanMetode = document.querySelectorAll(
    'input[name="metode_pembayaran"]'
);

const bagianDana = document.getElementById('bagianDana');
const inputBukti = document.getElementById('bukti_pembayaran');

function aturMetodePembayaran() {
    const pilihan = document.querySelector(
        'input[name="metode_pembayaran"]:checked'
    );

    const gunakanDana = pilihan && pilihan.value === 'DANA';

    bagianDana.hidden = !gunakanDana;
    if (inputBukti) inputBukti.required = !!gunakanDana;
}

pilihanMetode.forEach(function (radio) {
    radio.addEventListener('change', aturMetodePembayaran);
});

aturMetodePembayaran();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
