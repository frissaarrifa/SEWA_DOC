<?php
session_start();
require_once "koneksi.php";

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}
if (strtolower(trim($_SESSION['role'] ?? '')) !== 'peminjam') {
    header("Location: index.php");
    exit;
}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rupiah($v) { return 'Rp ' . number_format((float)$v, 0, ',', '.'); }

$id_peminjaman = (int)($_GET['id_peminjaman'] ?? 0);
$id_user = (int)$_SESSION['id_user'];
$stmt = mysqli_prepare($conn, "
    SELECT p.id_peminjaman, p.kode_peminjaman, p.tanggal_pinjam,
           p.tanggal_rencana_kembali, p.total_biaya, p.status,
           p.status_pembayaran, u.nama AS nama_peminjam,
           pb.id_pembayaran, pb.metode_pembayaran, pb.jumlah_bayar,
           pb.status_pembayaran AS status_konfirmasi, pb.waktu_bayar
    FROM peminjaman p
    INNER JOIN users u ON u.id_user = p.id_user_peminjam
    INNER JOIN pembayaran pb ON pb.id_pembayaran = (
        SELECT MAX(pb2.id_pembayaran) FROM pembayaran pb2
        WHERE pb2.id_peminjaman = p.id_peminjaman
    )
    WHERE p.id_peminjaman = ? AND p.id_user_peminjam = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, 'ii', $id_peminjaman, $id_user);
mysqli_stmt_execute($stmt);
$data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$data || $data['status_konfirmasi'] !== 'Dikonfirmasi' || $data['status_pembayaran'] !== 'Sudah Dibayar') {
    http_response_code(403);
    exit('Struk belum tersedia. Struk hanya dapat dicetak setelah pembayaran dikonfirmasi petugas. <a href="pembayaran_saya.php">Kembali</a>');
}

$stmt = mysqli_prepare($conn, "
    SELECT dp.jumlah, dp.harga_sewa, dp.subtotal, pr.nama_peralatan
    FROM detail_peminjaman dp
    LEFT JOIN peralatan pr ON pr.id_peralatan = dp.id_peralatan
    WHERE dp.id_peminjaman = ?
    ORDER BY dp.id_detail ASC
");
mysqli_stmt_bind_param($stmt, 'i', $id_peminjaman);
mysqli_stmt_execute($stmt);
$detail = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Struk Pembayaran - <?= e($data['kode_peminjaman']) ?></title>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#eef5fb;color:#203247;margin:0;padding:24px}.receipt{max-width:760px;margin:0 auto;background:#fff;border:1px solid #d9e6f1;border-radius:14px;padding:30px;box-shadow:0 8px 25px #d9e6f1}.head{text-align:center;border-bottom:2px dashed #b9d3e8;padding-bottom:18px;margin-bottom:20px}.head h1{margin:0;color:#145374;letter-spacing:1px}.head p{margin:7px 0 0;color:#60798d}.paid{text-align:center;background:#e7f8ee;color:#14753c;border:1px solid #b7e4c7;padding:10px;border-radius:8px;font-weight:bold;margin:18px 0}.info{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:22px}.label{color:#71879a;font-size:12px;margin-bottom:4px}.value{font-weight:600;overflow-wrap:anywhere}table{width:100%;border-collapse:collapse;margin:15px 0}th,td{padding:11px 8px;border-bottom:1px solid #e2ebf2;text-align:left;font-size:14px}th{background:#edf6fc;color:#145374}td.num,th.num{text-align:right}.total{display:flex;justify-content:space-between;font-size:19px;font-weight:bold;border-top:2px dashed #b9d3e8;padding-top:16px;color:#145374}.foot{text-align:center;color:#74899b;font-size:12px;margin-top:25px}.actions{max-width:760px;margin:16px auto;display:flex;gap:10px;justify-content:center}.btn{border:0;border-radius:8px;padding:11px 18px;text-decoration:none;cursor:pointer;font-weight:bold}.primary{background:#145374;color:#fff}.secondary{background:#fff;color:#145374;border:1px solid #b9d3e8}@media(max-width:560px){body{padding:10px}.receipt{padding:18px}.info{grid-template-columns:1fr}th,td{font-size:12px;padding:8px 4px}}@media print{body{background:#fff;padding:0}.receipt{box-shadow:none;border:0;max-width:none;padding:0}.actions{display:none}}
</style></head><body>
<div class="receipt">
  <div class="head"><h1>FRISDOC</h1><p>Struk Pembayaran Sewa Peralatan</p></div>
  <div class="paid">✓ PEMBAYARAN LUNAS / SUDAH DIKONFIRMASI</div>
  <div class="info">
    <div><div class="label">Kode Peminjaman</div><div class="value"><?= e($data['kode_peminjaman']) ?></div></div>
    <div><div class="label">Nomor Struk</div><div class="value">FRD-PAY-<?= (int)$data['id_pembayaran'] ?></div></div>
    <div><div class="label">Nama Peminjam</div><div class="value"><?= e($data['nama_peminjam']) ?></div></div>
    <div><div class="label">Metode Pembayaran</div><div class="value"><?= e($data['metode_pembayaran']) ?></div></div>
    <div><div class="label">Tanggal Pinjam</div><div class="value"><?= e($data['tanggal_pinjam']) ?></div></div>
    <div><div class="label">Rencana Kembali</div><div class="value"><?= e($data['tanggal_rencana_kembali']) ?></div></div>
    <div><div class="label">Waktu Pembayaran</div><div class="value"><?= e($data['waktu_bayar'] ?: '-') ?></div></div>
    <div><div class="label">Status Peminjaman</div><div class="value"><?= e($data['status']) ?></div></div>
  </div>
  <h3>Rincian Peralatan</h3>
  <table><thead><tr><th>Nama Peralatan</th><th class="num">Jumlah</th><th class="num">Harga Sewa</th><th class="num">Subtotal</th></tr></thead><tbody>
  <?php while ($item = mysqli_fetch_assoc($detail)): ?>
    <tr><td><?= e($item['nama_peralatan'] ?? 'Peralatan') ?></td><td class="num"><?= (int)$item['jumlah'] ?></td><td class="num"><?= rupiah($item['harga_sewa']) ?></td><td class="num"><?= rupiah($item['subtotal']) ?></td></tr>
  <?php endwhile; ?>
  </tbody></table>
  <div class="total"><span>Total Dibayar</span><span><?= rupiah($data['jumlah_bayar']) ?></span></div>
  <div class="foot">Terima kasih telah menggunakan FRISDOC.<br>Struk ini diterbitkan oleh sistem setelah pembayaran dikonfirmasi.</div>
</div>
<div class="actions"><button class="btn primary" onclick="window.print()">Cetak Struk</button><a class="btn secondary" href="pembayaran_saya.php">Kembali</a></div>
</body></html>
<?php mysqli_stmt_close($stmt); ?>
