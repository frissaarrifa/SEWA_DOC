<?php
// Sidebar bersama untuk akun peminjam.
$halaman_aktif = basename($_SERVER['PHP_SELF'] ?? '');
?>
<aside class="sidebar">
    <div class="logo"><h2>FRISDOC</h2><p>Sistem Peminjaman Alat</p></div>
    <p class="menu-title">Menu Peminjam</p>
    <a href="daftar_alat.php" class="menu-link <?= $halaman_aktif === 'daftar_alat.php' ? 'active' : '' ?>">Daftar Alat</a>
    <a href="ajukan_peminjaman.php" class="menu-link <?= $halaman_aktif === 'ajukan_peminjaman.php' ? 'active' : '' ?>">Ajukan Peminjaman</a>
    <a href="pembayaran_saya.php" class="menu-link <?= $halaman_aktif === 'pembayaran_saya.php' ? 'active' : '' ?>">Pembayaran Saya</a>
    <a href="pengembalian_saya.php" class="menu-link <?= $halaman_aktif === 'pengembalian_saya.php' ? 'active' : '' ?>">Pengembalian Saya</a>
    <a href="logout.php" class="menu-link logout" onclick="return confirm('Yakin ingin keluar dari akun?');">Keluar</a>
</aside>
