
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

$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['admin', 'petugas'], true)) {
    if ($role === 'peminjam') {
        header("Location: pembayaran_saya.php");
    } else {
        header("Location: login.php");
    }
    exit;
}

$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Petugas';

// ========================================
// 2. TOKEN KEAMANAN FORM
// ========================================
if (empty($_SESSION['csrf_pembayaran'])) {
    $_SESSION['csrf_pembayaran'] = bin2hex(random_bytes(32));
}

// ========================================
// 3. FUNGSI BANTU
// ========================================
function e($nilai) {
    return htmlspecialchars(
        (string) $nilai,
        ENT_QUOTES,
        'UTF-8'
    );
}

function rupiah($angka) {
    return 'Rp ' . number_format(
        (float) $angka,
        0,
        ',',
        '.'
    );
}

// ========================================
// 4. PESAN NOTIFIKASI
// ========================================
$pesan = $_SESSION['pesan_konfirmasi'] ?? '';
unset($_SESSION['pesan_konfirmasi']);

$jenis_pesan = $pesan !== '' ? 'danger' : 'info';

if (isset($_GET['hasil']) && $pesan === '') {
    if ($_GET['hasil'] === 'dikonfirmasi') {
        $pesan = 'Pembayaran berhasil dikonfirmasi.';
        $jenis_pesan = 'success';
    } elseif ($_GET['hasil'] === 'ditolak') {
        $pesan = 'Pengajuan pembayaran berhasil ditolak.';
        $jenis_pesan = 'warning';
    } elseif ($_GET['hasil'] === 'gagal') {
        $pesan = 'Proses pembayaran gagal. Periksa kembali data pembayaran.';
        $jenis_pesan = 'danger';
    }
}

// ========================================
// 5. AMBIL PEMBAYARAN MENUNGGU KONFIRMASI
// ========================================
$query = "
    SELECT
        pb.id_pembayaran,
        pb.id_peminjaman,
        pb.metode_pembayaran,
        pb.jumlah_bayar,
        pb.status_pembayaran,
        pb.bukti_pembayaran,
        pb.catatan,
        pb.waktu_bayar,

        p.kode_peminjaman,
        p.id_user_peminjam,
        p.status AS status_peminjaman,
        p.status_pembayaran AS status_tagihan,
        p.total_biaya,

        u.nama AS nama_peminjam,
        u.username

    FROM pembayaran pb

    INNER JOIN peminjaman p
        ON pb.id_peminjaman = p.id_peminjaman

    LEFT JOIN users u
        ON p.id_user_peminjam = u.id_user

    WHERE pb.status_pembayaran = 'Menunggu Konfirmasi'

    ORDER BY pb.id_pembayaran ASC
";

$stmt = mysqli_prepare($conn, $query);

if (!$stmt) {
    exit(
        'Query pembayaran gagal: '
        . e(mysqli_error($conn))
    );
}

mysqli_stmt_execute($stmt);
$data_pembayaran = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Konfirmasi Pembayaran | SEWA_DOC</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
.topbar,
        .content-card {
            background: white;
            padding: 24px;
            border-radius: 14px;
            margin-bottom: 25px;
            box-shadow: 0 3px 15px #dce7ef;
        }

        .table th {
            background: #e4f4fc;
            color: #145374;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .bukti {
            width: 100%;
            max-width: 110px;
            max-height: 140px;
            object-fit: contain;
            border: 1px solid #dce7ef;
            border-radius: 8px;
            background: white;
        }

        .form-keputusan {
            min-width: 240px;
        }

        .btn-konfirmasi {
            background: #198754;
            color: white;
            border: none;
        }

        .btn-konfirmasi:hover {
            background: #146c43;
            color: white;
        }

        .btn-tolak {
            background: #dc3545;
            color: white;
            border: none;
        }

        .btn-tolak:hover {
            background: #bb2d3b;
            color: white;
        }

        @media (max-width: 768px) {
.topbar,
            .content-card {
                padding: 15px;
            }
        }
    </style>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>

<?php include "sidebar_petugas_admin.php"; ?>

<main class="main-content">

    <div class="topbar">
        <h3 class="mb-2">Konfirmasi Pembayaran</h3>

        <div class="text-secondary">
            Halo, <?= e($nama) ?>. Periksa setiap pembayaran sebelum memberikan keputusan.
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

    <div class="content-card">

        <h5 class="mb-3">Pembayaran Menunggu Konfirmasi</h5>

        <div class="alert alert-info">
            <strong>Petunjuk pemeriksaan:</strong>

            <ul class="mb-0 mt-2">
                <li>
                    Pembayaran DANA harus dicocokkan dengan transaksi masuk
                    pada akun DANA penerima.
                </li>
                <li>
                    Pembayaran tunai hanya boleh dikonfirmasi setelah uang
                    benar-benar diterima.
                </li>
                <li>
                    Jika pembayaran ditolak, isi catatan alasan penolakan.
                </li>
                <li>
                    Periksa bukti gambar dan cocokkan dengan transaksi masuk pada akun penerima sebelum mengonfirmasi.
                </li>
            </ul>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Peminjaman</th>
                        <th>Nama Peminjam</th>
                        <th>Metode</th>
                        <th>Nominal Pembayaran</th>
                        <th>Total Tagihan</th>
                        <th>Waktu Pengajuan</th>
                        <th>Bukti Pembayaran</th>
                        <th>Keputusan</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (mysqli_num_rows($data_pembayaran) > 0): ?>

                    <?php $no = 1; ?>

                    <?php while ($row = mysqli_fetch_assoc($data_pembayaran)): ?>

                        <?php
                        $bukti = $row['bukti_pembayaran'] ?? '';

                        $bukti_valid = (
                            $bukti !== ''
                            && preg_match(
                                '~\Auploads/bukti_pembayaran/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)\z~i',
                                $bukti
                            )
                            && is_file(__DIR__ . '/' . $bukti)
                        );
                        ?>

                        <tr>

                            <td><?= $no++ ?></td>

                            <td>
                                <strong>
                                    <?= e($row['kode_peminjaman']) ?>
                                </strong>

                                <div class="small text-secondary">
                                    ID: <?= (int) $row['id_peminjaman'] ?>
                                </div>
                            </td>

                            <td>
                                <?= e(
                                    $row['nama_peminjam']
                                    ?: $row['username']
                                    ?: 'Peminjam tidak tersedia'
                                ) ?>
                            </td>

                            <td>
                                <?php if ($row['metode_pembayaran'] === 'DANA'): ?>
                                    <span class="badge text-bg-primary">
                                        DANA
                                    </span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">
                                        Tunai
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <strong>
                                    <?= rupiah($row['jumlah_bayar']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= rupiah($row['total_biaya']) ?>
                            </td>

                            <td>
                                <?= e($row['waktu_bayar'] ?? '-') ?>
                            </td>

                            <td>
                                <?php if (
                                    $row['metode_pembayaran'] === 'DANA'
                                    && $bukti_valid
                                ): ?>

                                    <a
                                        href="<?= e($bukti) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <img
                                            src="<?= e($bukti) ?>"
                                            alt="Bukti pembayaran DANA"
                                            class="bukti"
                                        >

                                        <div class="small mt-1">
                                            Lihat gambar
                                        </div>
                                    </a>

                                <?php elseif (
                                    $row['metode_pembayaran'] === 'DANA'
                                ): ?>

                                    <span class="text-danger">
                                        Bukti belum diunggah oleh peminjam
                                    </span>

                                <?php else: ?>

                                    <span class="text-secondary">
                                        Pembayaran tunai
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <form
                                    method="POST"
                                    action="proses_konfirmasi_pembayaran.php"
                                    class="form-keputusan"
                                    onsubmit="return konfirmasiKeputusan(this)"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($_SESSION['csrf_pembayaran']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id_pembayaran"
                                        value="<?= (int) $row['id_pembayaran'] ?>"
                                    >

                                    <label class="form-label small">
                                        Catatan / alasan penolakan
                                    </label>

                                    <textarea
                                        name="catatan"
                                        class="form-control form-control-sm mb-2"
                                        rows="2"
                                        maxlength="10000"
                                        placeholder="Isi jika pembayaran ditolak"
                                    ></textarea>

                                    <div class="d-flex flex-wrap gap-2">

                                        <button
                                            type="submit"
                                            name="aksi"
                                            value="konfirmasi"
                                            class="btn btn-konfirmasi btn-sm"
                                        >
                                            Konfirmasi
                                        </button>

                                        <button
                                            type="submit"
                                            name="aksi"
                                            value="tolak"
                                            class="btn btn-tolak btn-sm"
                                        >
                                            Tolak
                                        </button>

                                    </div>

                                </form>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <div class="text-secondary">
                                <h6>Belum Ada Pembayaran Menunggu Konfirmasi</h6>

                                <p class="mb-0">
                                    Pengajuan pembayaran yang baru akan muncul di sini.
                                </p>
                            </div>
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>
        </div>

    </div>

</main>

<script>
function konfirmasiKeputusan(form) {
    const aksi = document.activeElement.value;

    if (aksi === 'tolak') {
        const catatan = form.querySelector('[name="catatan"]').value.trim();

        if (catatan === '') {
            alert('Isi alasan penolakan terlebih dahulu.');
            return false;
        }

        return confirm('Yakin ingin menolak pembayaran ini?');
    }

    return confirm(
        'Pastikan uang sudah diterima dan pembayaran telah diverifikasi. Konfirmasi sekarang?'
    );
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

<?php
mysqli_stmt_close($stmt);
?>
