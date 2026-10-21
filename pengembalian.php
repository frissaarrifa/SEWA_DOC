<?php
session_start();
include "koneksi.php";

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

$id_user = (int) $_SESSION['id_user'];
$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna';
$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));

// Pemeriksaan dan pencatatan pengembalian hanya untuk admin/petugas.
if (!in_array($role, ['admin', 'petugas'], true)) {
    if ($role === 'peminjam') {
        header("Location: pengembalian_saya.php");
    } else {
        header("Location: login.php");
    }
    exit;
}

$error = "";

function catatAktivitasKembali($conn, $id_user, $aktivitas, $detail) {
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO log_aktivitas (id_user, aktivitas, detail)
         VALUES (?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "iss", $id_user, $aktivitas, $detail);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/* PROSES PENGEMBALIAN */
if (isset($_POST['kembalikan'])) {
    $id_peminjaman = (int) ($_POST['id_peminjaman'] ?? 0);
    $tanggal_kembali = $_POST['tanggal_kembali'] ?? date('Y-m-d');
    $kondisi = $_POST['kondisi_saat_kembali'] ?? '';
    $catatan = trim($_POST['catatan'] ?? '');

    $obj_tanggal_kembali = DateTime::createFromFormat('!Y-m-d', $tanggal_kembali);
    if (
        $id_peminjaman <= 0 ||
        !in_array($kondisi, ['Baik', 'Rusak', 'Hilang'], true) ||
        $tanggal_kembali === '' ||
        !$obj_tanggal_kembali || $obj_tanggal_kembali->format('Y-m-d') !== $tanggal_kembali ||
        $tanggal_kembali > date('Y-m-d')
    ) {
        $error = "Data pengembalian tidak valid.";
    } else {
        mysqli_begin_transaction($conn);

        try {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT kode_peminjaman, status
                 FROM peminjaman
                 WHERE id_peminjaman = ?
                 FOR UPDATE"
            );
            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);
            $pinjam = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (
                !$pinjam ||
                !in_array($pinjam['status'], ['Disetujui', 'Dipinjam'], true)
            ) {
                throw new Exception(
                    "Peminjaman tidak ditemukan atau belum dapat dikembalikan."
                );
            }

            $stmt = mysqli_prepare(
                $conn,
                "SELECT id_pengembalian
                 FROM pengembalian
                 WHERE id_peminjaman = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);
            $sudah_kembali = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if ($sudah_kembali) {
                throw new Exception("Pengembalian untuk transaksi ini sudah tercatat.");
            }

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO pengembalian
                 (id_peminjaman, tanggal_kembali, kondisi_saat_kembali, catatan)
                 VALUES (?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param(
                $stmt,
                "isss",
                $id_peminjaman,
                $tanggal_kembali,
                $kondisi,
                $catatan
            );

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception("Data pengembalian gagal disimpan.");
            }
            mysqli_stmt_close($stmt);

            /* Stok tersedia hanya bertambah untuk alat yang kembali dalam kondisi Baik.
               Alat Rusak/Hilang tidak dihitung sebagai stok siap pinjam. */
            $stmt = mysqli_prepare(
                $conn,
                "SELECT id_peralatan, jumlah
                 FROM detail_peminjaman
                 WHERE id_peminjaman = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);
            $detail = mysqli_stmt_get_result($stmt);

            while ($item = mysqli_fetch_assoc($detail)) {
                $id_alat_kembali = (int)$item['id_peralatan'];
                if ($kondisi === 'Baik') {
                    $update = mysqli_prepare($conn,
                        "UPDATE peralatan SET stok = stok + ? WHERE id_peralatan = ?");
                    mysqli_stmt_bind_param($update, "ii", $item['jumlah'], $id_alat_kembali);
                    if (!mysqli_stmt_execute($update)) {
                        mysqli_stmt_close($update);
                        throw new Exception("Stok peralatan gagal diperbarui.");
                    }
                    mysqli_stmt_close($update);
                } elseif ($kondisi === 'Rusak') {
                    // Jangan tambah stok barang rusak; tandai agar tidak bisa dipinjam kembali.
                    $update = mysqli_prepare($conn,
                        "UPDATE peralatan SET kondisi = 'Rusak' WHERE id_peralatan = ?");
                    mysqli_stmt_bind_param($update, "i", $id_alat_kembali);
                    if (!mysqli_stmt_execute($update)) {
                        mysqli_stmt_close($update);
                        throw new Exception("Kondisi peralatan gagal diperbarui.");
                    }
                    mysqli_stmt_close($update);
                }
                // Jika Hilang, stok tidak bertambah. Catatan pengembalian menyimpan kondisi tersebut.
            }
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE peminjaman SET status = 'Selesai'
                 WHERE id_peminjaman = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            catatAktivitasKembali(
                $conn,
                $id_user,
                "Mencatat pengembalian",
                "Kode " . $pinjam['kode_peminjaman'] .
                ", kondisi: " . $kondisi
            );

            mysqli_commit($conn);
            header("Location: pengembalian.php?pesan=sukses");
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

/* PEMINJAMAN YANG BELUM SELESAI */
$data_belum = mysqli_query(
    $conn,
    "SELECT p.id_peminjaman, p.kode_peminjaman, p.tanggal_pinjam,
            p.tanggal_rencana_kembali, u.nama,
            GROUP_CONCAT(
                CONCAT(a.nama_peralatan, ' (', d.jumlah, ')')
                SEPARATOR ', '
            ) AS daftar_alat
     FROM peminjaman p
     JOIN users u ON p.id_user = u.id_user
     JOIN detail_peminjaman d ON p.id_peminjaman = d.id_peminjaman
     JOIN peralatan a ON d.id_peralatan = a.id_peralatan
     LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman
     WHERE p.status IN ('Disetujui', 'Dipinjam')
       AND pg.id_pengembalian IS NULL
     GROUP BY p.id_peminjaman
     ORDER BY p.tanggal_rencana_kembali ASC"
);

/* RIWAYAT PENGEMBALIAN */
$data_riwayat = mysqli_query(
    $conn,
    "SELECT pg.*, p.kode_peminjaman, u.nama,
            GROUP_CONCAT(
                CONCAT(a.nama_peralatan, ' (', d.jumlah, ')')
                SEPARATOR ', '
            ) AS daftar_alat
     FROM pengembalian pg
     JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
     JOIN users u ON p.id_user = u.id_user
     JOIN detail_peminjaman d ON p.id_peminjaman = d.id_peminjaman
     JOIN peralatan a ON d.id_peralatan = a.id_peralatan
     GROUP BY pg.id_pengembalian
     ORDER BY pg.id_pengembalian DESC"
);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengembalian | FRISDOC</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include "sidebar_petugas_admin.php"; ?>

<main class="main-content">
    <div class="topbar">
        <div>
            <h2>Data Pengembalian</h2>
            <p>Catat pengembalian dan kondisi alat</p>
        </div>
        <div class="user-info">
            <strong><?= htmlspecialchars($nama); ?></strong>
            <p><?= htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <?php
    if (($_GET['pesan'] ?? '') === 'sukses') {
        echo '<div class="alert alert-success">Pengembalian berhasil dicatat.</div>';
    }

    if ($error !== '') {
        echo '<div class="alert alert-danger">' .
            htmlspecialchars($error) . '</div>';
    }
    ?>

    <div class="content-card">
        <h3 class="section-title">Peminjaman Belum Dikembalikan</h3>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode</th>
                        <th>Peminjam</th>
                        <th>Peralatan</th>
                        <th>Tanggal Pinjam</th>
                        <th>Rencana Kembali</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($data_belum && mysqli_num_rows($data_belum) > 0) { ?>
                    <?php $no = 1; ?>
                    <?php while ($row = mysqli_fetch_assoc($data_belum)) { ?>
                        <tr>
                            <td><?= $no++; ?></td>
                            <td><?= htmlspecialchars($row['kode_peminjaman']); ?></td>
                            <td><?= htmlspecialchars($row['nama']); ?></td>
                            <td><?= htmlspecialchars($row['daftar_alat']); ?></td>
                            <td><?= htmlspecialchars($row['tanggal_pinjam']); ?></td>
                            <td><?= htmlspecialchars($row['tanggal_rencana_kembali']); ?></td>
                            <td>
                                <form method="POST">
                                    <input type="hidden" name="id_peminjaman"
                                           value="<?= (int) $row['id_peminjaman']; ?>">

                                    <div class="form-group">
                                        <label>Tanggal Kembali</label>
                                        <input type="date" name="tanggal_kembali"
                                               class="form-control"
                                               value="<?= date('Y-m-d'); ?>"
                                               required>
                                    </div>

                                    <div class="form-group">
                                        <label>Kondisi Alat</label>
                                        <select name="kondisi_saat_kembali"
                                                class="form-control" required>
                                            <option value="Baik">Baik</option>
                                            <option value="Rusak">Rusak</option>
                                            <option value="Hilang">Hilang</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Catatan</label>
                                        <textarea name="catatan" class="form-control"
                                                  placeholder="Catatan tambahan"></textarea>
                                    </div>

                                    <button type="submit" name="kembalikan"
                                            class="btn btn-primary"
                                            onclick="return confirm('Catat pengembalian alat ini?');">
                                        Proses Pengembalian
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                <?php } else { ?>
                    <tr>
                        <td colspan="7" style="text-align:center;">
                            Tidak ada peminjaman yang menunggu pengembalian.
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="content-card">
        <h3 class="section-title">Riwayat Pengembalian</h3>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Peminjaman</th>
                        <th>Peminjam</th>
                        <th>Peralatan</th>
                        <th>Tanggal Kembali</th>
                        <th>Kondisi</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($data_riwayat && mysqli_num_rows($data_riwayat) > 0) { ?>
                    <?php $no = 1; ?>
                    <?php while ($row = mysqli_fetch_assoc($data_riwayat)) { ?>
                        <tr>
                            <td><?= $no++; ?></td>
                            <td><?= htmlspecialchars($row['kode_peminjaman']); ?></td>
                            <td><?= htmlspecialchars($row['nama']); ?></td>
                            <td><?= htmlspecialchars($row['daftar_alat']); ?></td>
                            <td><?= htmlspecialchars($row['tanggal_kembali']); ?></td>
                            <td><?= htmlspecialchars($row['kondisi_saat_kembali']); ?></td>
                            <td><?= htmlspecialchars($row['catatan'] ?: '-'); ?></td>
                        </tr>
                    <?php } ?>
                <?php } else { ?>
                    <tr>
                        <td colspan="7" style="text-align:center;">
                            Belum ada riwayat pengembalian.
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

</body>
</html>
