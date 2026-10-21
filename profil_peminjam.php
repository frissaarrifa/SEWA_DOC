<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header('Location: login.php');
    exit;
}
// Data diri sekarang diisi langsung pada formulir pengajuan peminjaman.
header('Location: ajukan_peminjaman.php?isi_data_diri=1');
exit;
