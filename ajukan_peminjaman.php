<?php
session_start();
require_once "koneksi.php";

if (!isset($_SESSION['id_user'])) { header("Location: login.php"); exit; }
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($role !== 'peminjam') { header("Location: index.php"); exit; }
$id_user_peminjam = (int)$_SESSION['id_user'];
$nama_user = $_SESSION['nama'] ?? $_SESSION['nama_user'] ?? $_SESSION['username'] ?? 'Peminjam';

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rupiah($v) { return 'Rp ' . number_format((float)$v, 0, ',', '.'); }

$nama_peminjam = trim((string)($_POST['nama_peminjam'] ?? ($_SESSION['nama'] ?? $_SESSION['nama_user'] ?? $_SESSION['username'] ?? '')));
$no_hp = trim((string)($_POST['no_hp'] ?? ''));
$alamat = trim((string)($_POST['alamat'] ?? ''));
$tanggal_pinjam = (string)($_POST['tanggal_pinjam'] ?? '');
$tanggal_kembali = (string)($_POST['tanggal_rencana_kembali'] ?? '');
$keperluan = trim((string)($_POST['keperluan'] ?? ''));
$jumlah_post = is_array($_POST['jumlah'] ?? null) ? $_POST['jumlah'] : [];
$alat_post = is_array($_POST['id_peralatan'] ?? null) ? $_POST['id_peralatan'] : [];
$selected = [];
foreach ($alat_post as $id) { $id=(int)$id; if ($id>0) $selected[$id]=max(1,(int)($jumlah_post[$id] ?? 1)); }
$error = '';
$min_tanggal = date('Y-m-d');

$res = mysqli_query($conn, "SELECT id_peralatan, nama_peralatan, merk, stok, harga_sewa, foto FROM peralatan WHERE kondisi='Baik' AND stok>0 ORDER BY nama_peralatan ASC");
if (!$res) { exit('Gagal mengambil daftar peralatan.'); }
$daftar_alat=[]; while($r=mysqli_fetch_assoc($res)) $daftar_alat[]=$r;

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajukan'])) {
    if ($nama_peminjam==='' || $no_hp==='' || $alamat==='' || !$selected || $tanggal_pinjam==='' || $tanggal_kembali==='' || $keperluan==='') {
        $error='Lengkapi data diri, pilih minimal satu peralatan, isi jumlah setiap alat, tanggal, dan keperluan.';
    } elseif (!preg_match('/^[0-9+()\-\s]{9,20}$/', $no_hp)) {
        $error='Nomor HP/WhatsApp tidak valid.';
    } elseif (mb_strlen($nama_peminjam)>100 || mb_strlen($alamat)<8 || mb_strlen($alamat)>500 || mb_strlen($keperluan)>5000) {
        $error='Nama, alamat, atau keperluan melewati batas yang diperbolehkan.';
    } else {
        $mulai=DateTime::createFromFormat('!Y-m-d',$tanggal_pinjam);
        $akhir=DateTime::createFromFormat('!Y-m-d',$tanggal_kembali);
        $today=new DateTime('today');
        if (!$mulai || !$akhir || $mulai->format('Y-m-d')!==$tanggal_pinjam || $akhir->format('Y-m-d')!==$tanggal_kembali || $mulai<$today || $akhir<=$mulai) {
            $error='Tanggal pinjam tidak boleh di masa lalu dan tanggal kembali harus setelah tanggal pinjam.';
        } else {
            $lama=(int)$mulai->diff($akhir)->days;
            $file_contact_saved=false;
            mysqli_begin_transaction($conn);
            try {
                // Lock all selected equipment in stable ID order; verify current stock and price.
                ksort($selected, SORT_NUMERIC);
                $items=[]; $total=0;
                $stmtAlat=mysqli_prepare($conn,"SELECT id_peralatan,nama_peralatan,stok,harga_sewa,kondisi FROM peralatan WHERE id_peralatan=? FOR UPDATE");
                if(!$stmtAlat) throw new Exception('Gagal memeriksa stok peralatan.');
                foreach($selected as $idAlat=>$qty) {
                    if($qty<1 || $qty>999) throw new Exception('Jumlah peralatan tidak valid.');
                    mysqli_stmt_bind_param($stmtAlat,'i',$idAlat);
                    mysqli_stmt_execute($stmtAlat);
                    $alat=mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAlat));
                    if(!$alat || $alat['kondisi']!=='Baik') throw new Exception('Salah satu peralatan tidak tersedia atau kondisinya tidak baik.');
                    if((int)$alat['stok']<$qty) throw new Exception('Stok '.$alat['nama_peralatan'].' hanya tersisa '.(int)$alat['stok'].' unit.');
                    $harga=(float)$alat['harga_sewa'];
                    if($harga<=0) throw new Exception('Harga sewa '.$alat['nama_peralatan'].' belum valid.');
                    $subtotal=$harga*$qty*$lama;
                    $total += $subtotal;
                    $items[]=['id'=>(int)$idAlat,'nama'=>$alat['nama_peralatan'],'jumlah'=>$qty,'harga'=>$harga,'subtotal'=>$subtotal];
                }
                mysqli_stmt_close($stmtAlat);

                // Save contact details per application in penyewa; no users.no_hp/alamat columns needed.
                $stmtP=mysqli_prepare($conn,"INSERT INTO penyewa (nama_penyewa,no_hp,alamat,tanggal_dibuat) VALUES (?,?,?,NOW())");
                if(!$stmtP) throw new Exception('Gagal menyiapkan data diri peminjam.');
                mysqli_stmt_bind_param($stmtP,'sss',$nama_peminjam,$no_hp,$alamat);
                if(!mysqli_stmt_execute($stmtP)) { mysqli_stmt_close($stmtP); throw new Exception('Data diri peminjam gagal disimpan.'); }
                $id_penyewa=mysqli_insert_id($conn); mysqli_stmt_close($stmtP);

                $kode='';
                for($i=0;$i<5;$i++) {
                    $candidate='SWD-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
                    $cek=mysqli_prepare($conn,"SELECT id_peminjaman FROM peminjaman WHERE kode_peminjaman=? LIMIT 1");
                    mysqli_stmt_bind_param($cek,'s',$candidate); mysqli_stmt_execute($cek);
                    $exists=mysqli_num_rows(mysqli_stmt_get_result($cek))>0; mysqli_stmt_close($cek);
                    if(!$exists){$kode=$candidate;break;}
                }
                if($kode==='') throw new Exception('Kode peminjaman gagal dibuat. Coba lagi.');

                $stmtPinjam=mysqli_prepare($conn,"INSERT INTO peminjaman (kode_peminjaman,id_user,id_user_peminjam,tanggal_pinjam,tanggal_rencana_kembali,keperluan,status,id_penyewa,total_biaya,status_pembayaran,denda) VALUES (?,NULL,?,?,?,?, 'Menunggu',?,?, 'Belum Dibayar',0)");
                if(!$stmtPinjam) throw new Exception('Gagal menyiapkan pengajuan peminjaman.');
                mysqli_stmt_bind_param($stmtPinjam,'sisssid',$kode,$id_user_peminjam,$tanggal_pinjam,$tanggal_kembali,$keperluan,$id_penyewa,$total);
                if(!mysqli_stmt_execute($stmtPinjam)){mysqli_stmt_close($stmtPinjam);throw new Exception('Pengajuan peminjaman gagal disimpan.');}
                $idPinjam=mysqli_insert_id($conn); mysqli_stmt_close($stmtPinjam);

                $stmtDetail=mysqli_prepare($conn,"INSERT INTO detail_peminjaman (id_peminjaman,id_peralatan,jumlah,harga_sewa,subtotal) VALUES (?,?,?,?,?)");
                if(!$stmtDetail) throw new Exception('Gagal menyiapkan rincian peralatan.');
                foreach($items as $it) {
                    mysqli_stmt_bind_param($stmtDetail,'iiidd',$idPinjam,$it['id'],$it['jumlah'],$it['harga'],$it['subtotal']);
                    if(!mysqli_stmt_execute($stmtDetail)) throw new Exception('Rincian peralatan gagal disimpan.');
                }
                mysqli_stmt_close($stmtDetail);

                // Intentionally do not deduct stock yet; admin/petugas deducts once on approval.
                mysqli_commit($conn);
                header('Location: pengembalian_saya.php?pengajuan=berhasil'); exit;
            } catch(Throwable $ex) {
                mysqli_rollback($conn);
                $error=$ex->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ajukan Peminjaman | FRISDOC</title><link rel="stylesheet" href="css/style.css">
<style>
.product-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin:14px 0 22px}.product-choice{border:1px solid #d8e5ef;border-radius:12px;padding:14px;background:#fff;display:flex;gap:12px;align-items:flex-start}.product-choice.selected{border-color:#2980b9;box-shadow:0 0 0 2px #dceefa}.product-choice img{width:78px;height:78px;object-fit:contain;border-radius:8px;background:#f5f9fc}.product-info{flex:1;min-width:0}.product-info label{font-weight:700;display:block}.product-meta{font-size:13px;color:#60798d;margin:6px 0}.qty-wrap{display:flex;align-items:center;gap:8px;margin-top:8px}.qty-wrap input{width:88px}.estimate-table{width:100%;border-collapse:collapse;margin-top:12px}.estimate-table th,.estimate-table td{padding:9px;border-bottom:1px solid #e2ebf2;text-align:left}.estimate-table th:last-child,.estimate-table td:last-child{text-align:right}.small-muted{font-size:13px;color:#60798d}@media(max-width:560px){.product-choice img{width:60px;height:60px}.estimate-table{font-size:12px}}
</style></head><body>
<?php include "sidebar_peminjam.php"; ?>
<main class="main-content"><header class="topbar"><div><h2>Ajukan Peminjaman</h2><p>Pilih satu atau beberapa jenis peralatan dalam satu pengajuan.</p></div><div class="user-info">Halo, <strong><?=e($nama_user)?></strong></div></header>
<section class="content-card"><h3 class="section-title">Formulir Peminjaman Alat</h3>
<?php if($error!==''): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
<div class="alert alert-warning"><strong>Informasi:</strong> Harga dihitung per unit per hari. Stok baru dikurangi setelah pengajuan disetujui petugas. Total belum termasuk denda keterlambatan.</div>
<form method="POST" action="ajukan_peminjaman.php" id="formPengajuan">
<h4>Data Diri Peminjam</h4><p>Data ini disimpan otomatis bersama pengajuan.</p>
<div class="form-group"><label for="nama_peminjam">Nama Lengkap</label><input type="text" name="nama_peminjam" id="nama_peminjam" class="form-control" maxlength="100" value="<?=e($nama_peminjam)?>" required></div>
<div class="form-group"><label for="no_hp">Nomor HP / WhatsApp</label><input type="tel" name="no_hp" id="no_hp" class="form-control" maxlength="20" pattern="[0-9+()\s-]{9,20}" value="<?=e($no_hp)?>" placeholder="Contoh: 081234567890" required></div>
<div class="form-group"><label for="alamat">Alamat Lengkap</label><textarea name="alamat" id="alamat" class="form-control" rows="3" minlength="8" maxlength="500" required><?=e($alamat)?></textarea></div>
<hr><h4>Pilih Peralatan</h4><p class="small-muted">Centang beberapa alat yang diperlukan, lalu tentukan jumlah setiap alat.</p>
<?php if(!$daftar_alat): ?><div class="alert alert-warning">Belum ada peralatan yang tersedia.</div><?php else: ?><div class="product-grid">
<?php foreach($daftar_alat as $alat): $aid=(int)$alat['id_peralatan']; $checked=isset($selected[$aid]); $foto=trim((string)($alat['foto']??'')); $img=$foto!==''?$foto:('gambar/kamera.jpg'); if(!preg_match('~^(uploads/peralatan|gambar)/[a-zA-Z0-9_./-]+$~',$img)) $img='gambar/kamera.jpg'; ?>
<div class="product-choice <?=$checked?'selected':''?>" data-product="<?=$aid?>"><img src="<?=e($img)?>" alt="<?=e($alat['nama_peralatan'])?>" onerror="this.src='gambar/kamera.jpg'"><div class="product-info"><label><input type="checkbox" name="id_peralatan[]" value="<?=$aid?>" <?=$checked?'checked':''?>> <?=e($alat['nama_peralatan'])?></label><div class="product-meta"><?=e($alat['merk']??'')?> · Stok: <span class="stock"><?= (int)$alat['stok']?></span><br><?=rupiah($alat['harga_sewa'])?> / unit / hari</div><div class="qty-wrap"><label for="jumlah_<?=$aid?>">Jumlah</label><input class="form-control qty-input" type="number" name="jumlah[<?=$aid?>]" id="jumlah_<?=$aid?>" min="1" max="<?=(int)$alat['stok']?>" value="<?=isset($selected[$aid])?(int)$selected[$aid]:1?>" data-stok="<?=(int)$alat['stok']?>" data-harga="<?=e($alat['harga_sewa'])?>" <?=$checked?'':'disabled'?>></div></div></div>
<?php endforeach; ?></div><?php endif; ?>
<div class="form-group"><label for="tanggal_pinjam">Tanggal Pinjam</label><input type="date" name="tanggal_pinjam" id="tanggal_pinjam" class="form-control" min="<?=$min_tanggal?>" value="<?=e($tanggal_pinjam)?>" required></div>
<div class="form-group"><label for="tanggal_rencana_kembali">Tanggal Rencana Kembali</label><input type="date" name="tanggal_rencana_kembali" id="tanggal_rencana_kembali" class="form-control" min="<?=$min_tanggal?>" value="<?=e($tanggal_kembali)?>" required></div>
<div class="form-group"><label for="keperluan">Keperluan Peminjaman</label><textarea name="keperluan" id="keperluan" class="form-control" rows="4" maxlength="5000" required><?=e($keperluan)?></textarea></div>
<div class="content-card" id="estimasi" aria-live="polite">Pilih alat dan tanggal untuk melihat estimasi biaya.</div>
<button type="submit" name="ajukan" class="btn btn-primary">Kirim Pengajuan</button> <a href="daftar_alat.php" class="btn btn-secondary">Kembali ke Daftar Alat</a>
</form></section><footer class="page-footer">&copy; 2026 FRISDOC - Sistem Peminjaman Alat</footer></main>
<script>
const cards=[...document.querySelectorAll('.product-choice')];const start=document.getElementById('tanggal_pinjam');const end=document.getElementById('tanggal_rencana_kembali');const estimate=document.getElementById('estimasi');
function updateEstimate(){let days=0;if(start.value&&end.value){days=Math.round((new Date(end.value+'T00:00:00')-new Date(start.value+'T00:00:00'))/86400000)}let total=0,lines=[],invalid=false;cards.forEach(card=>{const cb=card.querySelector('input[type=checkbox]');const qty=card.querySelector('.qty-input');qty.disabled=!cb.checked;card.classList.toggle('selected',cb.checked);if(cb.checked){const q=Number(qty.value||0),stock=Number(qty.dataset.stok||0),price=Number(qty.dataset.harga||0);if(q<1||q>stock){invalid=true;lines.push('<tr><td>'+card.querySelector('.product-info label').textContent.trim()+'</td><td colspan="2">Jumlah melebihi stok ('+stock+')</td></tr>')}else{const sub=price*q*Math.max(days,0);total+=sub;lines.push('<tr><td>'+card.querySelector('.product-info label').textContent.trim()+'</td><td>'+q+' × '+Math.max(days,0)+' hari</td><td>'+new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(sub)+'</td></tr>')}}});if(!cards.some(c=>c.querySelector('input[type=checkbox]').checked)){estimate.textContent='Pilih minimal satu peralatan.';return}if(days<=0){estimate.textContent='Tanggal kembali harus setelah tanggal pinjam. Pilih tanggal untuk menghitung biaya.';return}estimate.innerHTML='<strong>Estimasi biaya ('+days+' hari)</strong><table class="estimate-table"><thead><tr><th>Peralatan</th><th>Jumlah × Hari</th><th>Subtotal</th></tr></thead><tbody>'+lines.join('')+'</tbody></table><p><strong>Total: '+new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(total)+'</strong></p>'+(invalid?'<p style="color:#b42318">Perbaiki jumlah yang melebihi stok sebelum mengirim.</p>':'');}
cards.forEach(card=>{card.querySelector('input[type=checkbox]').addEventListener('change',updateEstimate);card.querySelector('.qty-input').addEventListener('input',updateEstimate)});start.addEventListener('change',updateEstimate);end.addEventListener('change',updateEstimate);document.getElementById('formPengajuan').addEventListener('submit',ev=>{if(!cards.some(c=>c.querySelector('input[type=checkbox]').checked)){ev.preventDefault();alert('Pilih minimal satu peralatan.');return}for(const c of cards){const cb=c.querySelector('input[type=checkbox]'),q=c.querySelector('.qty-input');if(cb.checked&&(Number(q.value)<1||Number(q.value)>Number(q.dataset.stok))){ev.preventDefault();alert('Periksa jumlah peralatan yang dipilih.');return}}if(new Date(end.value+'T00:00:00')<=new Date(start.value+'T00:00:00')){ev.preventDefault();alert('Tanggal kembali harus setelah tanggal pinjam.')}});updateEstimate();
</script></body></html>
