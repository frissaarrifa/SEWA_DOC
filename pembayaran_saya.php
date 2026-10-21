
<?php
session_start();
include "koneksi.php";

// Pastikan pengguna sudah login
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Halaman ini khusus untuk peminjam
$role = strtolower(trim($_SESSION['role'] ?? ''));

if ($role !== 'peminjam') {
    if ($role === 'admin' || $role === 'petugas') {
        header("Location: index.php");
    } else {
        session_unset();
        session_destroy();
        header("Location: login.php");
    }
    exit;
}

$id_user_peminjam = (int) $_SESSION['id_user'];
$nama_user = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Peminjam';
$halaman_aktif = basename($_SERVER['PHP_SELF']);

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah($angka) {
    return 'Rp ' . number_format((float) $angka, 0, ',', '.');
}

// Ambil transaksi dan pembayaran milik peminjam yang login
$sql = "
    SELECT
        pm.id_peminjaman,
        pm.kode_peminjaman,
        pm.tanggal_pinjam,
        pm.tanggal_rencana_kembali,
        pm.total_biaya,
        pm.status AS status_peminjaman,
        pm.status_pembayaran AS status_bayar_transaksi,
        pb.id_pembayaran,
        pb.metode_pembayaran,
        pb.jumlah_bayar,
        pb.status_pembayaran AS status_konfirmasi,
        pb.bukti_pembayaran,
        pb.catatan,
        pb.waktu_bayar
    FROM peminjaman pm
    LEFT JOIN pembayaran pb
        ON pb.id_pembayaran = (
            SELECT MAX(pb2.id_pembayaran)
            FROM pembayaran pb2
            WHERE pb2.id_peminjaman = pm.id_peminjaman
        )
    WHERE pm.id_user_peminjam = ?
    ORDER BY pm.id_peminjaman DESC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Gagal mengambil data pembayaran. Periksa struktur tabel database.");
}

mysqli_stmt_bind_param($stmt, "i", $id_user_peminjam);
mysqli_stmt_execute($stmt);
$query = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Pembayaran Saya | SEWA_DOC</title>

    <link rel="stylesheet" href="css/style.css">
</head>

<body>

<?php include "sidebar_peminjam.php"; ?>


<main class="main-content">

    <header class="topbar">
        <div>
            <h2>Pembayaran Saya</h2>
            <p>Pantau pembayaran penyewaan alat milikmu.</p>
        </div>

        <div class="user-info">
            Halo, <strong><?= e($nama_user) ?></strong>
        </div>
    </header>

    <section class="content-card">
        <h3 class="section-title">Daftar Pembayaran</h3>

        <p>
            Halaman ini menampilkan transaksi peminjaman milikmu
            beserta status pembayaran masing-masing transaksi.
        </p>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Peminjaman</th>
                        <th>Tanggal Pinjam</th>
                        <th>Rencana Kembali</th>
                        <th>Total Biaya</th>
                        <th>Metode Pembayaran</th>
                        <th>Status Pembayaran</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (mysqli_num_rows($query) > 0): ?>

                    <?php $no = 1; ?>

                    <?php while ($data = mysqli_fetch_assoc($query)): ?>
                        <?php
                        $status_konfirmasi = trim(
                            $data['status_konfirmasi'] ?? ''
                        );

                        $status_transaksi = trim(
                            $data['status_bayar_transaksi'] ?? ''
                        );

                        if ($status_konfirmasi === 'Dikonfirmasi') {
                            $status_tampil = 'Sudah Dibayar';
                            $status_class = 'status-lunas';

                        } elseif (
                            $status_konfirmasi === 'Menunggu Konfirmasi'
                        ) {
                            $status_tampil = 'Menunggu Konfirmasi';
                            $status_class = 'status-menunggu';

                        } elseif ($status_konfirmasi === 'Ditolak') {
                            $status_tampil = 'Pembayaran Ditolak';
                            $status_class = 'status-ditolak';

                        } elseif ($status_transaksi === 'Sudah Dibayar') {
                            $status_tampil = 'Sudah Dibayar';
                            $status_class = 'status-lunas';

                        } else {
                            $status_tampil = 'Belum Dibayar';
                            $status_class = 'status-belum';
                        }
                        ?>

                        <tr>
                            <td><?= $no++ ?></td>

                            <td>
                                <strong>
                                    <?= e($data['kode_peminjaman']) ?>
                                </strong>
                            </td>

                            <td><?= e($data['tanggal_pinjam']) ?></td>

                            <td><?= e($data['tanggal_rencana_kembali']) ?></td>

                            <td><?= rupiah($data['total_biaya']) ?></td>

                            <td>
                                <?= !empty($data['metode_pembayaran'])
                                    ? e($data['metode_pembayaran'])
                                    : '-' ?>
                            </td>

                            <td>
                                <span class="badge <?= e($status_class) ?>">
                                    <?= e($status_tampil) ?>
                                </span>

                                <?php if (!empty($data['catatan'])): ?>
                                    <p style="margin-top: 8px;">
                                        <small>
                                            Catatan:
                                            <?= e($data['catatan']) ?>
                                        </small>
                                    </p>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($status_tampil === 'Sudah Dibayar'): ?>

                                    <a href="cetak_struk.php?id_peminjaman=<?= (int) $data['id_peminjaman'] ?>" class="btn btn-primary" target="_blank" rel="noopener">Cetak Struk</a>

                                <?php elseif (
                                    $status_tampil === 'Menunggu Konfirmasi'
                                ): ?>

                                    <span class="badge status-menunggu">
                                        Menunggu Konfirmasi
                                    </span>

                                <?php else: ?>

                                    <a
                                        href="proses_pembayaran.php?id_peminjaman=<?= (int) $data['id_peminjaman'] ?>"
                                        class="btn btn-primary"
                                    >
                                        <?= $status_tampil === 'Pembayaran Ditolak'
                                            ? 'Bayar Ulang'
                                            : 'Bayar Sekarang' ?>
                                    </a>

                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" style="text-align: center;">
                            Kamu belum memiliki transaksi peminjaman.
                        </td>
                    </tr>

                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer style="text-align:center; color:#7895A8; font-size:12px; margin-top:30px;">
        &copy; 2026 SEWA_DOC - Sistem Peminjaman Alat
    </footer>

</main>

</body>
</html>

<?php
mysqli_stmt_close($stmt);
?>
