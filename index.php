<?php
session_start();
include "koneksi.php";

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna';
$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));

if (!in_array($role, ['admin', 'petugas'], true)) {
    if ($role === 'peminjam') {
        header("Location: daftar_alat.php");
    } else {
        header("Location: login.php");
    }
    exit;
}

function hitungData($conn, $sql) {
    $hasil = mysqli_query($conn, $sql);

    if ($hasil) {
        $data = mysqli_fetch_assoc($hasil);
        return (int) array_values($data)[0];
    }

    return 0;
}

$total_kategori = hitungData($conn, "SELECT COUNT(*) AS total FROM kategori");
$total_peralatan = hitungData($conn, "SELECT COUNT(*) AS total FROM peralatan");
$total_peminjaman = hitungData($conn, "SELECT COUNT(*) AS total FROM peminjaman");
$total_menunggu = hitungData(
    $conn,
    "SELECT COUNT(*) AS total FROM peminjaman WHERE status = 'Menunggu'"
);
$total_dipinjam = hitungData(
    $conn,
    "SELECT COUNT(*) AS total FROM peminjaman WHERE status IN ('Disetujui', 'Dipinjam')"
);

function aktif($file) {
    return basename($_SERVER['PHP_SELF']) === $file ? 'active' : '';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | FRISDOC</title>
    <link rel="stylesheet" href="css/style.css">

    <style>
        .sidebar .menu-link {
            display: block;
        }

        .sidebar .logo h2 {
            letter-spacing: 2px;
        }

        .welcome-card {
            background: linear-gradient(120deg, #70B8E5, #BDE7FA);
        }

        .stat-card {
            border: 1px solid #E2F1FA;
        }

        .stat-card h2 {
            margin-top: 5px;
        }

        .section-title {
            margin-top: 5px;
        }

        .dashboard-note {
            background: #FFFFFF;
            border-left: 4px solid #8EC9F2;
            padding: 20px;
            border-radius: 10px;
            color: #52768C;
            font-size: 14px;
            line-height: 1.7;
        }

        @media (max-width: 600px) {
            .sidebar .menu-title {
                margin-top: 15px;
            }
        }
    </style>
</head>
<body>

<?php include "sidebar_petugas_admin.php"; ?>

<main class="main-content">

    <div class="topbar">
        <div>
            <h2>Dashboard</h2>
            <p>Ringkasan sistem peminjaman alat dokumentasi</p>
        </div>

        <div class="user-info">
            <strong><?= htmlspecialchars($nama); ?></strong>
            <p><?= htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <section class="welcome-card">
        <h2>Selamat Datang di FRISDOC</h2>
        <p>
            Halo, <?= htmlspecialchars($nama); ?>.
            Selamat datang di sistem peminjaman alat dokumentasi.
            Kelola data peralatan, peminjaman, dan pengembalian melalui menu yang tersedia.
        </p>
    </section>

    <h3 class="section-title">Ringkasan Data</h3>

    <div class="stats-container">
        <div class="stat-card">
            <p>Total Kategori</p>
            <h2><?= $total_kategori; ?></h2>
        </div>

        <div class="stat-card">
            <p>Total Peralatan</p>
            <h2><?= $total_peralatan; ?></h2>
        </div>

        <div class="stat-card">
            <p>Total Peminjaman</p>
            <h2><?= $total_peminjaman; ?></h2>
        </div>

        <div class="stat-card">
            <p>Peminjaman Menunggu</p>
            <h2><?= $total_menunggu; ?></h2>
        </div>

        <div class="stat-card">
            <p>Peralatan Sedang Dipinjam</p>
            <h2><?= $total_dipinjam; ?></h2>
        </div>
    </div>

    <h3 class="section-title">Akses Cepat</h3>

    <div class="quick-menu">
        <a href="kategori.php" class="quick-card">
            <h3>Data Kategori</h3>
            <p>Kelola kategori kamera, tripod, mikrofon, dan peralatan lainnya.</p>
        </a>

        <a href="peralatan.php" class="quick-card">
            <h3>Data Peralatan</h3>
            <p>Lihat dan kelola daftar alat dokumentasi beserta stok dan kondisinya.</p>
        </a>

        <a href="peminjaman.php" class="quick-card">
            <h3>Data Peminjaman</h3>
            <p>Kelola permintaan peminjaman dan status peminjaman peralatan.</p>
        </a>

        <a href="pengembalian.php" class="quick-card">
            <h3>Data Pengembalian</h3>
            <p>Catat pengembalian serta kondisi peralatan setelah digunakan.</p>
        </a>
    </div>

    <div class="dashboard-note" style="margin-top:25px;">
        <strong>Informasi:</strong>
        Pastikan data peralatan dan stok selalu diperbarui agar proses peminjaman
        berjalan dengan baik.
    </div>

</main>

</body>
</html>
