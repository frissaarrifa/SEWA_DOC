<?php
// Sidebar bersama untuk akun admin dan petugas.
$halaman_aktif = basename($_SERVER['PHP_SELF'] ?? '');
$role_sidebar = strtolower(trim((string)($_SESSION['role'] ?? $role ?? '')));
?>
<aside class="sidebar">
    <div class="logo">
        <h2>FRISDOC</h2>
        <p>Sistem Peminjaman Alat</p>
    </div>

    <p class="menu-title">Menu Utama</p>
    <a href="index.php" class="menu-link <?= $halaman_aktif === 'index.php' ? 'active' : '' ?>">Dashboard</a>

    <?php if ($role_sidebar === 'admin'): ?>
        <a href="kategori.php" class="menu-link <?= $halaman_aktif === 'kategori.php' ? 'active' : '' ?>">Data Kategori</a>
        <a href="peralatan.php" class="menu-link <?= $halaman_aktif === 'peralatan.php' ? 'active' : '' ?>">Data Peralatan</a>
        <a href="peminjaman.php" class="menu-link <?= $halaman_aktif === 'peminjaman.php' ? 'active' : '' ?>">Data Peminjaman</a>
        <a href="pengembalian.php" class="menu-link <?= $halaman_aktif === 'pengembalian.php' ? 'active' : '' ?>">Data Pengembalian</a>
    <?php else: ?>
        <a href="peminjaman.php" class="menu-link <?= $halaman_aktif === 'peminjaman.php' ? 'active' : '' ?>">Persetujuan Peminjaman</a>
        <a href="pengembalian.php" class="menu-link <?= $halaman_aktif === 'pengembalian.php' ? 'active' : '' ?>">Pemantauan Pengembalian</a>
    <?php endif; ?>

    <a href="konfirmasi_pembayaran.php" class="menu-link <?= $halaman_aktif === 'konfirmasi_pembayaran.php' ? 'active' : '' ?>">Konfirmasi Pembayaran</a>
    <a href="laporan.php" class="menu-link <?= $halaman_aktif === 'laporan.php' ? 'active' : '' ?>">Laporan</a>

    <?php if ($role_sidebar === 'admin'): ?>
        <p class="menu-title">Pengelolaan Sistem</p>
        <a href="log_aktivitas.php" class="menu-link <?= $halaman_aktif === 'log_aktivitas.php' ? 'active' : '' ?>">Log Aktivitas</a>
    <?php endif; ?>

    <a href="logout.php" class="menu-link logout" onclick="return confirm('Yakin ingin keluar dari akun?');">Logout</a>
</aside>
