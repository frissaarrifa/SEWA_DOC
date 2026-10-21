
<?php
session_start();
include "koneksi.php";

// Pastikan pengguna sudah login
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Pastikan hanya peminjam yang dapat mengakses halaman ini
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'peminjam') {
    if (in_array($_SESSION['role'] ?? '', ['admin', 'petugas'])) {
        header("Location: index.php");
    } else {
        session_destroy();
        header("Location: login.php");
    }
    exit;
}

$id_user = $_SESSION['id_user'];
$nama_user = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Peminjam';
$halaman_aktif = basename($_SERVER['PHP_SELF']);

// Mengambil riwayat peminjaman milik pengguna yang login
$query = "
    SELECT
        pm.id_peminjaman,
        pm.kode_peminjaman,
        pm.tanggal_pinjam,
        pm.tanggal_rencana_kembali,
        pm.total_biaya,
        pm.status,
        GROUP_CONCAT(
            CONCAT(pr.nama_peralatan, ' (', dp.jumlah, ' unit)')
            SEPARATOR ', '
        ) AS daftar_peralatan
    FROM peminjaman pm
    LEFT JOIN detail_peminjaman dp
        ON pm.id_peminjaman = dp.id_peminjaman
    LEFT JOIN peralatan pr
        ON dp.id_peralatan = pr.id_peralatan
    WHERE pm.id_user_peminjam = ?
    GROUP BY
        pm.id_peminjaman,
        pm.kode_peminjaman,
        pm.tanggal_pinjam,
        pm.tanggal_rencana_kembali,
        pm.total_biaya,
        pm.status
    ORDER BY pm.id_peminjaman DESC
";

$stmt = mysqli_prepare($conn, $query);

if (!$stmt) {
    die("Gagal menyiapkan query: " . htmlspecialchars(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, "i", $id_user);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Pengembalian Saya | SEWA_DOC</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        /* Tombol Keluar berwarna merah */
        .sidebar .menu-link.menu-logout {
            color: #dc3545;
        }

        .sidebar .menu-link.menu-logout:hover {
            background-color: #dc3545;
            color: #ffffff;
        }
    </style>
</head>

<body>

<?php include "sidebar_peminjam.php"; ?>

<div class="main-content">

    <div class="topbar">
        <div>
            <h2>Pengembalian Saya</h2>
            <p>Riwayat peminjaman dan informasi pengembalian alat.</p>
        </div>

        <div class="user-info">
            Halo,
            <strong><?= htmlspecialchars($nama_user) ?></strong>
        </div>
    </div>

    <div class="content-card">

        <h3 class="section-title">Riwayat Peminjaman</h3>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Peminjaman</th>
                        <th>Daftar Alat</th>
                        <th>Tanggal Pinjam</th>
                        <th>Rencana Kembali</th>
                        <th>Total Biaya</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (mysqli_num_rows($result) > 0): ?>
                        <?php $no = 1; ?>

                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <tr>
                                <td><?= $no++ ?></td>

                                <td>
                                    <?= htmlspecialchars($row['kode_peminjaman'] ?? '-') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['daftar_peralatan'] ?? '-') ?>
                                </td>

                                <td>
                                    <?= !empty($row['tanggal_pinjam'])
                                        ? date('d-m-Y', strtotime($row['tanggal_pinjam']))
                                        : '-' ?>
                                </td>

                                <td>
                                    <?= !empty($row['tanggal_rencana_kembali'])
                                        ? date('d-m-Y', strtotime($row['tanggal_rencana_kembali']))
                                        : '-' ?>
                                </td>

                                <td>
                                    Rp <?= number_format(
                                        (float) ($row['total_biaya'] ?? 0),
                                        0,
                                        ',',
                                        '.'
                                    ) ?>
                                </td>

                                <td>
                                    <span class="badge">
                                        <?= htmlspecialchars($row['status'] ?? '-') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">
                                Belum ada riwayat peminjaman.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <footer>
        &copy; <?= date('Y') ?> SEWA_DOC - Sistem Peminjaman Alat
    </footer>

</div>

</body>
</html>

<?php
mysqli_stmt_close($stmt);
?>
