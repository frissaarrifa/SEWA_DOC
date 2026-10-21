<?php
session_start();
include "koneksi.php";

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

$id_user = (int)$_SESSION['id_user'];
$nama = $_SESSION['nama'] ?? $_SESSION['nama_user'] ?? 'Pengguna';
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['admin', 'petugas'], true)) {
    header("Location: daftar_alat.php");
    exit;
}

$error = "";

function rupiah($angka)
{
    return "Rp " . number_format((float)$angka, 0, ',', '.');
}

function tanggal_indonesia($tanggal)
{
    return $tanggal ? date('d-m-Y', strtotime($tanggal)) : '-';
}

/*
|--------------------------------------------------------------------------
| TAMBAH PEMINJAMAN LANGSUNG OLEH ADMIN
|--------------------------------------------------------------------------
| Peminjaman langsung mengurangi stok ketika dibuat.
| Pengajuan mandiri mengurangi stok ketika disetujui.
*/

if (isset($_POST['simpan'])) {
    if ($role !== 'admin') {
        $error = "Hanya admin yang dapat menambahkan peminjaman langsung.";
    } else {
        $nama_penyewa = trim($_POST['nama_penyewa'] ?? '');
        $no_hp = trim($_POST['no_hp'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $tanggal_pinjam = $_POST['tanggal_pinjam'] ?? '';
        $tanggal_kembali = $_POST['tanggal_rencana_kembali'] ?? '';
        $keperluan = trim($_POST['keperluan'] ?? '');

        $ids_alat = $_POST['id_peralatan'] ?? [];
        $jumlah_alat = $_POST['jumlah'] ?? [];

        $keranjang = [];

        if (!is_array($ids_alat) || !is_array($jumlah_alat)) {
            $error = "Data peralatan tidak valid.";
        } else {
            foreach ($ids_alat as $index => $id) {
                $id = (int)$id;
                $jumlah = (int)($jumlah_alat[$index] ?? 0);

                if ($id === 0 && $jumlah === 0) {
                    continue;
                }

                if ($id <= 0 || $jumlah <= 0) {
                    $error = "Pilih peralatan dan isi jumlah dengan benar.";
                    break;
                }

                if (!isset($keranjang[$id])) {
                    $keranjang[$id] = 0;
                }

                $keranjang[$id] += $jumlah;
            }
        }

        if ($error === '') {
            if (
                $nama_penyewa === '' ||
                $no_hp === '' ||
                $alamat === '' ||
                $tanggal_pinjam === '' ||
                $tanggal_kembali === '' ||
                $keperluan === '' ||
                empty($keranjang)
            ) {
                $error = "Semua data penyewa, tanggal, keperluan, dan peralatan wajib diisi.";
            } elseif (!preg_match('/^[0-9+\-\s]{9,16}$/', $no_hp)) {
                $error = "Nomor HP penyewa tidak valid.";
            } elseif (
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_pinjam) ||
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_kembali) ||
                !checkdate(
                    (int)substr($tanggal_pinjam, 5, 2),
                    (int)substr($tanggal_pinjam, 8, 2),
                    (int)substr($tanggal_pinjam, 0, 4)
                ) ||
                !checkdate(
                    (int)substr($tanggal_kembali, 5, 2),
                    (int)substr($tanggal_kembali, 8, 2),
                    (int)substr($tanggal_kembali, 0, 4)
                ) ||
                $tanggal_kembali < $tanggal_pinjam
            ) {
                $error = "Tanggal kembali harus sama dengan atau setelah tanggal pinjam.";
            } elseif ($tanggal_pinjam < date('Y-m-d')) {
                $error = "Tanggal pinjam tidak boleh sebelum hari ini.";
            }
        }

        if ($error === '') {
            $lama_sewa = (int)(
                (strtotime($tanggal_kembali) - strtotime($tanggal_pinjam)) / 86400
            ) + 1;

            ksort($keranjang);
            mysqli_begin_transaction($conn);

            try {
                $daftar_item = [];
                $total_biaya = 0;

                foreach ($keranjang as $id_peralatan => $jumlah) {
                    $stmt = mysqli_prepare(
                        $conn,
                        "SELECT id_peralatan, nama_peralatan, stok, kondisi, harga_sewa
                         FROM peralatan
                         WHERE id_peralatan = ?
                         FOR UPDATE"
                    );

                    if (!$stmt) {
                        throw new Exception("Gagal menyiapkan pemeriksaan peralatan.");
                    }

                    mysqli_stmt_bind_param($stmt, "i", $id_peralatan);
                    mysqli_stmt_execute($stmt);
                    $hasil = mysqli_stmt_get_result($stmt);
                    $alat = mysqli_fetch_assoc($hasil);
                    mysqli_stmt_close($stmt);

                    if (!$alat) {
                        throw new Exception("Salah satu peralatan tidak ditemukan.");
                    }

                    if ($alat['kondisi'] !== 'Baik') {
                        throw new Exception(
                            "Peralatan " . $alat['nama_peralatan'] .
                            " tidak dapat disewa karena kondisinya bukan Baik."
                        );
                    }

                    if ((int)$alat['stok'] < $jumlah) {
                        throw new Exception(
                            "Stok " . $alat['nama_peralatan'] .
                            " tidak mencukupi. Stok tersedia: " . $alat['stok'] . "."
                        );
                    }

                    if ((float)$alat['harga_sewa'] <= 0) {
                        throw new Exception(
                            "Harga sewa " . $alat['nama_peralatan'] . " belum diatur."
                        );
                    }

                    $harga = (float)$alat['harga_sewa'];
                    $subtotal = $harga * $jumlah * $lama_sewa;
                    $total_biaya += $subtotal;

                    $daftar_item[] = [
                        'id_peralatan' => (int)$alat['id_peralatan'],
                        'nama_peralatan' => $alat['nama_peralatan'],
                        'jumlah' => $jumlah,
                        'harga_sewa' => $harga,
                        'subtotal' => $subtotal
                    ];
                }

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO penyewa
                     (nama_penyewa, no_hp, alamat, tanggal_dibuat)
                     VALUES (?, ?, ?, NOW())"
                );

                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan data penyewa.");
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    "sss",
                    $nama_penyewa,
                    $no_hp,
                    $alamat
                );

                if (!mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    throw new Exception("Data penyewa gagal disimpan.");
                }

                $id_penyewa = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                $kode_peminjaman = 'SEWA-' . date('YmdHis') . '-' . random_int(100, 999);
                $status = 'Menunggu';
                $status_pembayaran = 'Belum Dibayar';
                $denda = 0;

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO peminjaman
                     (
                        kode_peminjaman,
                        id_user,
                        id_penyewa,
                        tanggal_pinjam,
                        tanggal_rencana_kembali,
                        keperluan,
                        total_biaya,
                        status,
                        status_pembayaran,
                        denda
                     )
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan transaksi peminjaman.");
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    "siisssdssd",
                    $kode_peminjaman,
                    $id_user,
                    $id_penyewa,
                    $tanggal_pinjam,
                    $tanggal_kembali,
                    $keperluan,
                    $total_biaya,
                    $status,
                    $status_pembayaran,
                    $denda
                );

                if (!mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    throw new Exception("Peminjaman gagal disimpan.");
                }

                $id_peminjaman = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                foreach ($daftar_item as $item) {
                    $id_barang = $item['id_peralatan'];
                    $jumlah_barang = $item['jumlah'];
                    $harga_barang = $item['harga_sewa'];
                    $subtotal_barang = $item['subtotal'];

                    $stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO detail_peminjaman
                         (id_peminjaman, id_peralatan, jumlah, harga_sewa, subtotal)
                         VALUES (?, ?, ?, ?, ?)"
                    );

                    if (!$stmt) {
                        throw new Exception("Gagal menyiapkan detail peralatan.");
                    }

                    mysqli_stmt_bind_param(
                        $stmt,
                        "iiidd",
                        $id_peminjaman,
                        $id_barang,
                        $jumlah_barang,
                        $harga_barang,
                        $subtotal_barang
                    );

                    if (!mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);
                        throw new Exception("Detail peralatan gagal disimpan.");
                    }

                    mysqli_stmt_close($stmt);

                    $stmt = mysqli_prepare(
                        $conn,
                        "UPDATE peralatan
                         SET stok = stok - ?
                         WHERE id_peralatan = ?
                           AND stok >= ?"
                    );

                    if (!$stmt) {
                        throw new Exception("Gagal menyiapkan pembaruan stok.");
                    }

                    mysqli_stmt_bind_param(
                        $stmt,
                        "iii",
                        $jumlah_barang,
                        $id_barang,
                        $jumlah_barang
                    );

                    if (
                        !mysqli_stmt_execute($stmt) ||
                        mysqli_stmt_affected_rows($stmt) !== 1
                    ) {
                        mysqli_stmt_close($stmt);
                        throw new Exception(
                            "Stok " . $item['nama_peralatan'] . " gagal diperbarui."
                        );
                    }

                    mysqli_stmt_close($stmt);
                }

                $aktivitas = "Menambahkan peminjaman";
                $detail_log = "Membuat transaksi " . $kode_peminjaman;

                $stmt_log = mysqli_prepare(
                    $conn,
                    "INSERT INTO log_aktivitas (id_user, aktivitas, detail)
                     VALUES (?, ?, ?)"
                );

                if ($stmt_log) {
                    mysqli_stmt_bind_param(
                        $stmt_log,
                        "iss",
                        $id_user,
                        $aktivitas,
                        $detail_log
                    );
                    mysqli_stmt_execute($stmt_log);
                    mysqli_stmt_close($stmt_log);
                }

                mysqli_commit($conn);
                header("Location: peminjaman.php?pesan=tambah");
                exit;
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| UBAH STATUS PEMINJAMAN
|--------------------------------------------------------------------------
| Pengajuan mandiri mengurangi stok ketika disetujui.
| Peminjaman langsung sudah mengurangi stok ketika dibuat.
*/

if (isset($_POST['ubah_status'])) {
    $id_ubah = (int)($_POST['id_peminjaman'] ?? 0);
    $status_baru = $_POST['status_baru'] ?? '';

    if ($id_ubah <= 0) {
        $error = "Data peminjaman tidak valid.";
    } else {
        mysqli_begin_transaction($conn);

        try {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT id_peminjaman, id_user_peminjam, status, kode_peminjaman
                 FROM peminjaman
                 WHERE id_peminjaman = ?
                 FOR UPDATE"
            );

            if (!$stmt) {
                throw new Exception("Gagal memeriksa data peminjaman.");
            }

            mysqli_stmt_bind_param($stmt, "i", $id_ubah);
            mysqli_stmt_execute($stmt);
            $hasil = mysqli_stmt_get_result($stmt);
            $pinjaman = mysqli_fetch_assoc($hasil);
            mysqli_stmt_close($stmt);

            if (!$pinjaman) {
                throw new Exception("Data peminjaman tidak ditemukan.");
            }

            $status_lama = $pinjaman['status'];
            $pengajuan_mandiri = !empty($pinjaman['id_user_peminjam']);

            $transisi_valid = [
                'Menunggu' => ['Disetujui', 'Ditolak'],
                'Disetujui' => ['Dipinjam']
            ];

            if (
                !isset($transisi_valid[$status_lama]) ||
                !in_array($status_baru, $transisi_valid[$status_lama], true)
            ) {
                throw new Exception("Perubahan status tidak diperbolehkan.");
            }

            $stmt_detail = mysqli_prepare(
                $conn,
                "SELECT dp.id_peralatan, dp.jumlah, pr.nama_peralatan,
                        pr.stok, pr.kondisi
                 FROM detail_peminjaman dp
                 INNER JOIN peralatan pr
                    ON dp.id_peralatan = pr.id_peralatan
                 WHERE dp.id_peminjaman = ?
                 FOR UPDATE"
            );

            if (!$stmt_detail) {
                throw new Exception("Gagal mengambil detail peralatan.");
            }

            mysqli_stmt_bind_param($stmt_detail, "i", $id_ubah);
            mysqli_stmt_execute($stmt_detail);
            $hasil_detail = mysqli_stmt_get_result($stmt_detail);

            $detail_alat = [];

            while ($detail = mysqli_fetch_assoc($hasil_detail)) {
                $detail_alat[] = $detail;
            }

            mysqli_stmt_close($stmt_detail);

            if (empty($detail_alat)) {
                throw new Exception("Detail peralatan peminjaman tidak ditemukan.");
            }

            if ($status_baru === 'Disetujui' && $pengajuan_mandiri) {
                foreach ($detail_alat as $detail) {
                    if ($detail['kondisi'] !== 'Baik') {
                        throw new Exception(
                            "Peralatan " . $detail['nama_peralatan'] .
                            " tidak dalam kondisi baik."
                        );
                    }

                    if ((int)$detail['stok'] < (int)$detail['jumlah']) {
                        throw new Exception(
                            "Stok " . $detail['nama_peralatan'] .
                            " tidak mencukupi untuk menyetujui pengajuan."
                        );
                    }
                }

                foreach ($detail_alat as $detail) {
                    $id_alat = (int)$detail['id_peralatan'];
                    $jumlah_alat = (int)$detail['jumlah'];

                    $stmt_stok = mysqli_prepare(
                        $conn,
                        "UPDATE peralatan
                         SET stok = stok - ?
                         WHERE id_peralatan = ?
                           AND stok >= ?"
                    );

                    if (!$stmt_stok) {
                        throw new Exception("Gagal menyiapkan pengurangan stok.");
                    }

                    mysqli_stmt_bind_param(
                        $stmt_stok,
                        "iii",
                        $jumlah_alat,
                        $id_alat,
                        $jumlah_alat
                    );

                    if (
                        !mysqli_stmt_execute($stmt_stok) ||
                        mysqli_stmt_affected_rows($stmt_stok) !== 1
                    ) {
                        mysqli_stmt_close($stmt_stok);
                        throw new Exception("Stok gagal diperbarui.");
                    }

                    mysqli_stmt_close($stmt_stok);
                }
            }

            if ($status_baru === 'Ditolak' && !$pengajuan_mandiri) {
                foreach ($detail_alat as $detail) {
                    $id_alat = (int)$detail['id_peralatan'];
                    $jumlah_alat = (int)$detail['jumlah'];

                    $stmt_stok = mysqli_prepare(
                        $conn,
                        "UPDATE peralatan
                         SET stok = stok + ?
                         WHERE id_peralatan = ?"
                    );

                    if (!$stmt_stok) {
                        throw new Exception("Gagal menyiapkan pengembalian stok.");
                    }

                    mysqli_stmt_bind_param(
                        $stmt_stok,
                        "ii",
                        $jumlah_alat,
                        $id_alat
                    );

                    if (!mysqli_stmt_execute($stmt_stok)) {
                        mysqli_stmt_close($stmt_stok);
                        throw new Exception("Stok gagal dikembalikan.");
                    }

                    mysqli_stmt_close($stmt_stok);
                }
            }

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE peminjaman
                 SET status = ?
                 WHERE id_peminjaman = ?"
            );

            if (!$stmt) {
                throw new Exception("Gagal menyiapkan perubahan status.");
            }

            mysqli_stmt_bind_param($stmt, "si", $status_baru, $id_ubah);

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception("Status peminjaman gagal diperbarui.");
            }

            mysqli_stmt_close($stmt);

            $aktivitas = "Mengubah status peminjaman";
            $detail_log = "Kode " . $pinjaman['kode_peminjaman'] .
                ": " . $status_lama . " menjadi " . $status_baru;

            $stmt_log = mysqli_prepare(
                $conn,
                "INSERT INTO log_aktivitas (id_user, aktivitas, detail)
                 VALUES (?, ?, ?)"
            );

            if ($stmt_log) {
                mysqli_stmt_bind_param(
                    $stmt_log,
                    "iss",
                    $id_user,
                    $aktivitas,
                    $detail_log
                );
                mysqli_stmt_execute($stmt_log);
                mysqli_stmt_close($stmt_log);
            }

            mysqli_commit($conn);
            header("Location: peminjaman.php?pesan=status");
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| DATA PERALATAN UNTUK FORM TAMBAH PEMINJAMAN
|--------------------------------------------------------------------------
*/

$data_alat = mysqli_query(
    $conn,
    "SELECT id_peralatan, nama_peralatan, merk, stok, kondisi, harga_sewa
     FROM peralatan
     WHERE kondisi = 'Baik'
       AND stok > 0
       AND harga_sewa > 0
     ORDER BY nama_peralatan ASC"
);

$daftar_alat = [];

if ($data_alat) {
    while ($alat = mysqli_fetch_assoc($data_alat)) {
        $daftar_alat[] = $alat;
    }
}

/*
|--------------------------------------------------------------------------
| PENCARIAN DAN DAFTAR PEMINJAMAN
|--------------------------------------------------------------------------
*/

$cari = trim($_GET['cari'] ?? '');

$sql = "SELECT
            p.id_peminjaman,
            p.kode_peminjaman,
            p.tanggal_pinjam,
            p.tanggal_rencana_kembali,
            p.keperluan,
            p.total_biaya,
            p.status,
            p.status_pembayaran,
            p.denda,

            COALESCE(
                NULLIF(py.nama_penyewa, ''),
                peminjam.nama,
                'Tidak diketahui'
            ) AS nama_penyewa,

            COALESCE(py.no_hp, '-') AS no_hp,
            COALESCE(petugas.nama, '-') AS nama_petugas,

            GROUP_CONCAT(
                DISTINCT CONCAT(
                    pr.nama_peralatan,
                    ' (',
                    dp.jumlah,
                    ' unit)'
                )
                SEPARATOR ', '
            ) AS daftar_alat

        FROM peminjaman p

        LEFT JOIN penyewa py
            ON p.id_penyewa = py.id_penyewa

        LEFT JOIN users petugas
            ON p.id_user = petugas.id_user

        LEFT JOIN users peminjam
            ON p.id_user_peminjam = peminjam.id_user

        LEFT JOIN detail_peminjaman dp
            ON p.id_peminjaman = dp.id_peminjaman

        LEFT JOIN peralatan pr
            ON dp.id_peralatan = pr.id_peralatan";

if ($cari !== '') {
    $sql .= " WHERE
                p.kode_peminjaman LIKE ?
                OR py.nama_penyewa LIKE ?
                OR peminjam.nama LIKE ?
                OR py.no_hp LIKE ?
                OR pr.nama_peralatan LIKE ?
                OR p.status_pembayaran LIKE ?
                OR p.status LIKE ?";
}

$sql .= " GROUP BY
            p.id_peminjaman,
            p.kode_peminjaman,
            p.tanggal_pinjam,
            p.tanggal_rencana_kembali,
            p.keperluan,
            p.total_biaya,
            p.status,
            p.status_pembayaran,
            p.denda,
            py.nama_penyewa,
            py.no_hp,
            petugas.nama,
            peminjam.nama
          ORDER BY p.id_peminjaman DESC";

$stmt_cari = null;

if ($cari !== '') {
    $pola = '%' . $cari . '%';
    $stmt_cari = mysqli_prepare($conn, $sql);

    if ($stmt_cari) {
        mysqli_stmt_bind_param(
            $stmt_cari,
            "sssssss",
            $pola,
            $pola,
            $pola,
            $pola,
            $pola,
            $pola,
            $pola
        );

        mysqli_stmt_execute($stmt_cari);
        $data_peminjaman = mysqli_stmt_get_result($stmt_cari);
    } else {
        $data_peminjaman = false;
        $error = "Pencarian peminjaman gagal dijalankan.";
    }
} else {
    $data_peminjaman = mysqli_query($conn, $sql);

    if (!$data_peminjaman) {
        $error = "Data peminjaman gagal dimuat.";
    }
}

$pesan = $_GET['pesan'] ?? '';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Peminjaman | FRISDOC</title>
    <link rel="stylesheet" href="css/style.css">

    <style>
        .barang-row {
            display: grid;
            grid-template-columns: minmax(200px, 2fr) minmax(100px, 1fr) auto;
            gap: 12px;
            align-items: end;
            margin-bottom: 12px;
            padding: 14px;
            border: 1px solid #dce7ef;
            border-radius: 10px;
        }

        .barang-row .form-group {
            margin-bottom: 0;
        }

        .barang-row select,
        .barang-row input {
            width: 100%;
            box-sizing: border-box;
        }

        .total-box {
            padding: 16px;
            margin: 18px 0;
            background: #eef8ff;
            border-radius: 10px;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .status-menunggu {
            color: #946200;
            font-weight: bold;
        }

        .status-disetujui,
        .status-dipinjam,
        .status-selesai {
            color: #16804a;
            font-weight: bold;
        }

        .status-ditolak {
            color: #dc3545;
            font-weight: bold;
        }

        .aksi-form {
            margin-bottom: 6px;
        }

        .aksi-form button {
            margin-top: 5px;
        }

        .table-container {
            overflow-x: auto;
        }

        .status-bayar {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 13px;
            white-space: nowrap;
        }

        .status-bayar.lunas {
            background: #e2f7eb;
            color: #16804a;
        }

        .status-bayar.belum {
            background: #fff3d6;
            color: #946200;
        }

        @media (max-width: 650px) {
            .barang-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "sidebar_petugas_admin.php"; ?>

<main class="main-content">

    <div class="topbar">
        <div>
            <h2>Data Peminjaman</h2>
            <p>Kelola pengajuan dan transaksi peminjaman alat</p>
        </div>

        <div class="user-info">
            <strong><?= htmlspecialchars($nama); ?></strong>
            <p><?= htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <?php if ($pesan === 'tambah') { ?>
        <div class="alert alert-success">
            Peminjaman berhasil ditambahkan.
        </div>
    <?php } elseif ($pesan === 'status') { ?>
        <div class="alert alert-success">
            Status peminjaman berhasil diperbarui.
        </div>
    <?php } ?>

    <?php if ($error !== '') { ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error); ?>
        </div>
    <?php } ?>

    <?php if (isset($_GET['tambah']) && $role === 'admin') { ?>

        <div class="content-card">
            <h3 class="section-title">Tambah Peminjaman</h3>

            <p>
                Pilih beberapa jenis peralatan dalam satu transaksi.
                Harga dihitung berdasarkan harga sewa per hari.
            </p>

            <?php if (empty($daftar_alat)) { ?>
                <div class="alert alert-warning">
                    Belum ada peralatan yang tersedia untuk disewa.
                    Pastikan stok, kondisi, dan harga sewa sudah benar.
                    <a href="peralatan.php">Buka Data Peralatan</a>
                </div>
            <?php } else { ?>

                <form method="POST" id="formPeminjaman">

                    <div class="form-group">
                        <label for="nama_penyewa">Nama Penyewa</label>
                        <input type="text"
                               name="nama_penyewa"
                               id="nama_penyewa"
                               class="form-control"
                               maxlength="100"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="no_hp">Nomor HP</label>
                        <input type="text"
                               name="no_hp"
                               id="no_hp"
                               class="form-control"
                               maxlength="20"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="alamat">Alamat Penyewa</label>
                        <textarea name="alamat"
                                  id="alamat"
                                  class="form-control"
                                  required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="tanggal_pinjam">Tanggal Mulai Sewa</label>
                        <input type="date"
                               name="tanggal_pinjam"
                               id="tanggal_pinjam"
                               class="form-control"
                               min="<?= date('Y-m-d'); ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="tanggal_rencana_kembali">
                            Tanggal Rencana Kembali
                        </label>
                        <input type="date"
                               name="tanggal_rencana_kembali"
                               id="tanggal_rencana_kembali"
                               class="form-control"
                               min="<?= date('Y-m-d'); ?>"
                               required>
                    </div>

                    <h4>Daftar Barang yang Dipinjam</h4>

                    <div id="daftarBarang">
                        <div class="barang-row">
                            <div class="form-group">
                                <label>Pilih Peralatan</label>
                                <select name="id_peralatan[]"
                                        class="form-control pilih-alat"
                                        required>
                                    <option value="">-- Pilih Peralatan --</option>

                                    <?php foreach ($daftar_alat as $alat) { ?>
                                        <option
                                            value="<?= (int)$alat['id_peralatan']; ?>"
                                            data-harga="<?= (float)$alat['harga_sewa']; ?>"
                                            data-stok="<?= (int)$alat['stok']; ?>">
                                            <?= htmlspecialchars($alat['nama_peralatan']); ?>
                                            <?= $alat['merk']
                                                ? ' - ' . htmlspecialchars($alat['merk'])
                                                : ''; ?>
                                            | Stok: <?= (int)$alat['stok']; ?>
                                            | <?= rupiah($alat['harga_sewa']); ?>/hari
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Jumlah</label>
                                <input type="number"
                                       name="jumlah[]"
                                       class="form-control jumlah-alat"
                                       min="1"
                                       value="1"
                                       required>
                            </div>

                            <button type="button"
                                    class="btn btn-danger hapus-barang"
                                    style="display:none;">
                                Hapus
                            </button>
                        </div>
                    </div>

                    <button type="button"
                            id="tambahBarang"
                            class="btn btn-secondary">
                        + Tambah Barang Lain
                    </button>

                    <div class="form-group" style="margin-top:20px;">
                        <label for="keperluan">Keperluan Peminjaman</label>
                        <textarea name="keperluan"
                                  id="keperluan"
                                  class="form-control"
                                  placeholder="Contoh: Dokumentasi acara sekolah"
                                  required></textarea>
                    </div>

                    <div class="total-box">
                        <p>
                            Lama sewa:
                            <strong id="lamaSewa">0 hari</strong>
                        </p>

                        <p>
                            Jumlah jenis barang:
                            <strong id="jumlahJenis">0 jenis</strong>
                        </p>

                        <h3>
                            Total Biaya:
                            <span id="totalBiaya">Rp 0</span>
                        </h3>

                        <small>
                            Total dihitung dari harga sewa per hari × jumlah barang × lama sewa.
                        </small>
                    </div>

                    <button type="submit"
                            name="simpan"
                            class="btn btn-primary">
                        Simpan Peminjaman
                    </button>

                    <a href="peminjaman.php" class="btn btn-secondary">
                        Batal
                    </a>

                </form>
            <?php } ?>
        </div>

    <?php } elseif ($role === 'admin') { ?>

        <div style="margin-bottom:20px;">
            <a href="peminjaman.php?tambah=1" class="btn btn-primary">
                + Tambah Peminjaman
            </a>
        </div>

    <?php } ?>

    <div class="content-card">
        <h3 class="section-title">Daftar Peminjaman</h3>

        <form method="GET" class="filter-form">
            <div class="form-group">
                <label for="cari">Cari Peminjaman</label>
                <input type="text"
                       name="cari"
                       id="cari"
                       class="form-control"
                       value="<?= htmlspecialchars($cari); ?>"
                       placeholder="Kode, nama, nomor HP, alat, atau status">
            </div>

            <button type="submit" class="btn btn-primary">Cari</button>
            <a href="peminjaman.php" class="btn btn-secondary">Reset</a>
        </form>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Peminjaman</th>
                        <th>Nama Penyewa</th>
                        <th>Nomor HP</th>
                        <th>Peralatan</th>
                        <th>Tanggal Pinjam</th>
                        <th>Tanggal Kembali</th>
                        <th>Total Biaya</th>
                        <th>Denda</th>
                        <th>Status Peminjaman</th>
                        <th>Aksi Status</th>
                        <th>Status Pembayaran</th>
                        <th>Petugas</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($data_peminjaman && mysqli_num_rows($data_peminjaman) > 0) { ?>
                    <?php $no = 1; ?>

                    <?php while ($row = mysqli_fetch_assoc($data_peminjaman)) { ?>
                        <tr>
                            <td><?= $no++; ?></td>

                            <td>
                                <?= htmlspecialchars($row['kode_peminjaman']); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['nama_penyewa']); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['no_hp']); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['daftar_alat'] ?: '-'); ?>
                            </td>

                            <td>
                                <?= tanggal_indonesia($row['tanggal_pinjam']); ?>
                            </td>

                            <td>
                                <?= tanggal_indonesia($row['tanggal_rencana_kembali']); ?>
                            </td>

                            <td><?= rupiah($row['total_biaya']); ?></td>

                            <td><?= rupiah($row['denda']); ?></td>

                            <td>
                                <span class="status-<?= strtolower($row['status']); ?>">
                                    <?= htmlspecialchars($row['status']); ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($row['status'] === 'Menunggu') { ?>

                                    <form method="POST"
                                          class="aksi-form"
                                          onsubmit="return confirm('Setujui peminjaman ini?');">
                                        <input type="hidden"
                                               name="id_peminjaman"
                                               value="<?= (int)$row['id_peminjaman']; ?>">
                                        <input type="hidden"
                                               name="status_baru"
                                               value="Disetujui">

                                        <button type="submit"
                                                name="ubah_status"
                                                class="btn btn-primary">
                                            Setujui
                                        </button>
                                    </form>

                                    <form method="POST"
                                          class="aksi-form"
                                          onsubmit="return confirm('Tolak peminjaman ini?');">
                                        <input type="hidden"
                                               name="id_peminjaman"
                                               value="<?= (int)$row['id_peminjaman']; ?>">
                                        <input type="hidden"
                                               name="status_baru"
                                               value="Ditolak">

                                        <button type="submit"
                                                name="ubah_status"
                                                class="btn btn-danger">
                                            Tolak
                                        </button>
                                    </form>

                                <?php } elseif ($row['status'] === 'Disetujui') { ?>

                                    <form method="POST"
                                          onsubmit="return confirm('Pastikan barang sudah diserahkan kepada penyewa. Lanjutkan?');">
                                        <input type="hidden"
                                               name="id_peminjaman"
                                               value="<?= (int)$row['id_peminjaman']; ?>">
                                        <input type="hidden"
                                               name="status_baru"
                                               value="Dipinjam">

                                        <button type="submit"
                                                name="ubah_status"
                                                class="btn btn-primary">
                                            Tandai Dipinjam
                                        </button>
                                    </form>

                                <?php } else { ?>
                                    -
                                <?php } ?>
                            </td>

                            <td>
                                <?php if ($row['status_pembayaran'] === 'Sudah Dibayar') { ?>
                                    <span class="status-bayar lunas">
                                        Sudah Dibayar
                                    </span>
                                <?php } else { ?>
                                    <span class="status-bayar belum">
                                        Belum Dibayar
                                    </span>

                                    <div style="margin-top:8px;">
                                        <a href="konfirmasi_pembayaran.php"
                                           class="btn btn-primary">
                                            Periksa Pembayaran
                                        </a>
                                    </div>
                                <?php } ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['nama_petugas']); ?>
                            </td>
                        </tr>
                    <?php } ?>

                <?php } else { ?>
                    <tr>
                        <td colspan="13" style="text-align:center;">
                            Belum ada data peminjaman.
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<script>
const daftarBarang = document.getElementById('daftarBarang');
const tombolTambah = document.getElementById('tambahBarang');
const tanggalPinjam = document.getElementById('tanggal_pinjam');
const tanggalKembali = document.getElementById('tanggal_rencana_kembali');
const lamaSewaTampil = document.getElementById('lamaSewa');
const jumlahJenisTampil = document.getElementById('jumlahJenis');
const totalBiayaTampil = document.getElementById('totalBiaya');

function rupiahJS(angka) {
    return 'Rp ' + angka.toLocaleString('id-ID');
}

function hitungBiaya() {
    if (!daftarBarang) return;

    let total = 0;
    let jenis = 0;
    let hari = 0;

    if (tanggalPinjam.value && tanggalKembali.value) {
        const mulai = new Date(tanggalPinjam.value + 'T00:00:00');
        const kembali = new Date(tanggalKembali.value + 'T00:00:00');

        const selisih = Math.round(
            (kembali - mulai) / (1000 * 60 * 60 * 24)
        );

        if (selisih >= 0) {
            hari = selisih + 1;
        }
    }

    const baris = daftarBarang.querySelectorAll('.barang-row');

    baris.forEach(function(row) {
        const select = row.querySelector('.pilih-alat');
        const inputJumlah = row.querySelector('.jumlah-alat');
        const opsi = select.options[select.selectedIndex];
        const harga = Number(opsi?.dataset.harga || 0);
        const stok = Number(opsi?.dataset.stok || 0);
        const jumlah = Number(inputJumlah.value || 0);

        if (select.value !== '') {
            jenis++;
            inputJumlah.max = stok;

            inputJumlah.setCustomValidity(
                jumlah > stok
                    ? 'Jumlah melebihi stok yang tersedia.'
                    : ''
            );

            if (jumlah > 0 && hari > 0) {
                total += harga * jumlah * hari;
            }
        } else {
            inputJumlah.removeAttribute('max');
            inputJumlah.setCustomValidity('');
        }
    });

    lamaSewaTampil.textContent = hari + ' hari';
    jumlahJenisTampil.textContent = jenis + ' jenis';
    totalBiayaTampil.textContent = rupiahJS(total);

    const pilihanTerpakai = [];

    baris.forEach(function(row) {
        const select = row.querySelector('.pilih-alat');

        if (select.value !== '') {
            pilihanTerpakai.push(select.value);
        }
    });

    baris.forEach(function(row) {
        const select = row.querySelector('.pilih-alat');

        Array.from(select.options).forEach(function(option) {
            if (option.value === '') return;

            option.disabled =
                pilihanTerpakai.includes(option.value) &&
                option.value !== select.value;
        });

        const tombolHapus = row.querySelector('.hapus-barang');

        if (tombolHapus) {
            tombolHapus.style.display =
                baris.length > 1 ? 'inline-block' : 'none';
        }
    });
}

function tambahBarisBarang() {
    const barisPertama = daftarBarang.querySelector('.barang-row');
    const barisBaru = barisPertama.cloneNode(true);

    barisBaru.querySelector('.pilih-alat').value = '';

    const inputJumlah = barisBaru.querySelector('.jumlah-alat');
    inputJumlah.value = '1';
    inputJumlah.removeAttribute('max');
    inputJumlah.setCustomValidity('');

    daftarBarang.appendChild(barisBaru);

    barisBaru.querySelector('.hapus-barang').addEventListener(
        'click',
        function() {
            barisBaru.remove();
            hitungBiaya();
        }
    );

    barisBaru.querySelector('.pilih-alat').addEventListener(
        'change',
        hitungBiaya
    );

    inputJumlah.addEventListener('input', hitungBiaya);

    hitungBiaya();
}

if (tombolTambah && daftarBarang) {
    tombolTambah.addEventListener('click', tambahBarisBarang);
}

if (daftarBarang) {
    daftarBarang.addEventListener('change', hitungBiaya);
    daftarBarang.addEventListener('input', hitungBiaya);
}

if (tanggalPinjam && tanggalKembali) {
    tanggalPinjam.addEventListener('change', function() {
        tanggalKembali.min = tanggalPinjam.value;

        if (
            tanggalKembali.value &&
            tanggalKembali.value < tanggalPinjam.value
        ) {
            tanggalKembali.value = tanggalPinjam.value;
        }

        hitungBiaya();
    });

    tanggalKembali.addEventListener('change', hitungBiaya);
}

const formPeminjaman = document.getElementById('formPeminjaman');

if (formPeminjaman && daftarBarang) {
    formPeminjaman.addEventListener('submit', function(event) {
        const baris = daftarBarang.querySelectorAll('.barang-row');
        let valid = true;

        baris.forEach(function(row) {
            const select = row.querySelector('.pilih-alat');
            const inputJumlah = row.querySelector('.jumlah-alat');

            const stok = Number(
                select.options[select.selectedIndex]?.dataset.stok || 0
            );

            const jumlah = Number(inputJumlah.value || 0);

            if (
                select.value === '' ||
                jumlah < 1 ||
                jumlah > stok
            ) {
                valid = false;

                select.setCustomValidity(
                    select.value === ''
                        ? 'Pilih peralatan terlebih dahulu.'
                        : ''
                );

                if (jumlah > stok) {
                    inputJumlah.setCustomValidity(
                        'Jumlah melebihi stok yang tersedia.'
                    );
                }
            } else {
                select.setCustomValidity('');
                inputJumlah.setCustomValidity('');
            }
        });

        if (!valid) {
            event.preventDefault();
            formPeminjaman.reportValidity();
        }
    });
}

hitungBiaya();
</script>

</body>
</html>
