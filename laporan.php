<?php
session_start();
include "koneksi.php";

// Pastikan pengguna sudah login
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Ambil data pengguna
$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna';
$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));

// Laporan internal hanya boleh diakses admin dan petugas.
if (!in_array($role, ['admin', 'petugas'], true)) {
    if ($role === 'peminjam') {
        header("Location: daftar_alat.php");
    } else {
        header("Location: login.php");
    }
    exit;
}

// Filter laporan
$tanggal_mulai = $_GET['tanggal_mulai'] ?? '';
$tanggal_akhir = $_GET['tanggal_akhir'] ?? '';
$status = $_GET['status'] ?? '';

$status_valid = [
    '',
    'Menunggu',
    'Disetujui',
    'Ditolak',
    'Dipinjam',
    'Selesai'
];

// Cegah nilai status yang tidak sesuai
if (!in_array($status, $status_valid, true)) {
    $status = '';
}

$where = [];
$params = [];
$types = "";

// Filter tanggal mulai
if ($tanggal_mulai !== '') {
    $where[] = "p.tanggal_pinjam >= ?";
    $params[] = $tanggal_mulai;
    $types .= "s";
}

// Filter tanggal akhir
if ($tanggal_akhir !== '') {
    $where[] = "p.tanggal_pinjam <= ?";
    $params[] = $tanggal_akhir;
    $types .= "s";
}

// Filter status
if ($status !== '') {
    $where[] = "p.status = ?";
    $params[] = $status;
    $types .= "s";
}

// Query laporan
$sql = "
    SELECT
        p.id_peminjaman,
        p.kode_peminjaman,
        p.tanggal_pinjam,
        p.tanggal_rencana_kembali,
        p.keperluan,
        p.status,
        u.nama AS nama_peminjam,
        GROUP_CONCAT(
            CONCAT(per.nama_peralatan, ' (', d.jumlah, ')')
            ORDER BY per.nama_peralatan
            SEPARATOR ', '
        ) AS daftar_peralatan,
        COALESCE(SUM(d.jumlah), 0) AS total_alat,
        pg.tanggal_kembali,
        pg.kondisi_saat_kembali,
        pg.catatan
    FROM peminjaman p
    JOIN users u
        ON p.id_user = u.id_user
    LEFT JOIN detail_peminjaman d
        ON p.id_peminjaman = d.id_peminjaman
    LEFT JOIN peralatan per
        ON d.id_peralatan = per.id_peralatan
    LEFT JOIN pengembalian pg
        ON p.id_peminjaman = pg.id_peminjaman
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
    GROUP BY
        p.id_peminjaman,
        p.kode_peminjaman,
        p.tanggal_pinjam,
        p.tanggal_rencana_kembali,
        p.keperluan,
        p.status,
        u.nama,
        pg.tanggal_kembali,
        pg.kondisi_saat_kembali,
        pg.catatan
    ORDER BY p.tanggal_pinjam DESC, p.id_peminjaman DESC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Gagal menyiapkan laporan: " .
        htmlspecialchars(mysqli_error($conn)));
}

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Siapkan data laporan
$data_laporan = [];

$total_transaksi = 0;
$total_menunggu = 0;
$total_disetujui = 0;
$total_dipinjam = 0;
$total_selesai = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $data_laporan[] = $row;
    $total_transaksi++;

    if ($row['status'] === 'Menunggu') {
        $total_menunggu++;
    } elseif ($row['status'] === 'Disetujui') {
        $total_disetujui++;
    } elseif ($row['status'] === 'Dipinjam') {
        $total_dipinjam++;
    } elseif ($row['status'] === 'Selesai') {
        $total_selesai++;
    }
}

// Format tanggal
function tanggalIndonesia($tanggal) {
    if (empty($tanggal)) {
        return '-';
    }

    $timestamp = strtotime($tanggal);

    if ($timestamp === false) {
        return '-';
    }

    return date("d-m-Y", $timestamp);
}

// Menu sidebar aktif
function aktif($file) {
    return basename($_SERVER['PHP_SELF']) === $file
        ? 'active'
        : '';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Laporan Peminjaman | FRISDOC</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        .filter-laporan {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-laporan .form-group {
            flex: 1;
            min-width: 160px;
        }

        .filter-laporan label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .filter-laporan input,
        .filter-laporan select {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .tombol-filter,
        .tombol-cetak {
            display: inline-block;
            padding: 11px 18px;
            border: none;
            border-radius: 6px;
            background: #2677b8;
            color: white;
            cursor: pointer;
            text-decoration: none;
        }

        .tombol-reset {
            display: inline-block;
            padding: 11px 18px;
            border-radius: 6px;
            background: #e5e7eb;
            color: #333;
            text-decoration: none;
        }

        .tabel-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .tabel-laporan {
            width: 100%;
            min-width: 1150px;
            border-collapse: collapse;
        }

        .tabel-laporan th,
        .tabel-laporan td {
            padding: 11px;
            border: 1px solid #e2e8f0;
            text-align: left;
            vertical-align: top;
        }

        .tabel-laporan th {
            background: #eaf5ff;
            color: #174d75;
        }

        .status-label {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 5px;
            background: #e5e7eb;
            white-space: nowrap;
        }

        .ringkasan-laporan {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .ringkasan-item {
            padding: 18px;
            background: #f0f8ff;
            border: 1px solid #d6eafa;
            border-radius: 8px;
        }

        .ringkasan-item p {
            margin: 0 0 8px;
            color: #526579;
        }

        .ringkasan-item h3 {
            margin: 0;
            color: #174d75;
            font-size: 25px;
        }

        @media (max-width: 800px) {
            .ringkasan-laporan {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 500px) {
            .ringkasan-laporan {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .sidebar,
            .topbar,
            .filter-laporan,
            .tombol-cetak,
            .no-print {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            body {
                background: white !important;
            }

            .tabel-laporan {
                min-width: 0;
                font-size: 9px;
            }

            .tabel-laporan th,
            .tabel-laporan td {
                padding: 5px;
            }
        }
    </style>
</head>

<body>

<!-- SIDEBAR -->
<?php include "sidebar_petugas_admin.php"; ?>

<!-- KONTEN UTAMA -->
<main class="main-content">

    <div class="topbar">
        <div>
            <h2>Laporan Peminjaman</h2>
            <p>Rekap data peminjaman dan pengembalian alat.</p>
        </div>

        <div class="user-info">
            Halo, <strong><?= htmlspecialchars($nama); ?></strong>
            <p><?= htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <div class="content">

        <!-- RINGKASAN -->
        <div class="ringkasan-laporan">

            <div class="ringkasan-item">
                <p>Total Transaksi</p>
                <h3><?= $total_transaksi; ?></h3>
            </div>

            <div class="ringkasan-item">
                <p>Menunggu</p>
                <h3><?= $total_menunggu; ?></h3>
            </div>

            <div class="ringkasan-item">
                <p>Disetujui</p>
                <h3><?= $total_disetujui; ?></h3>
            </div>

            <div class="ringkasan-item">
                <p>Sedang Dipinjam</p>
                <h3><?= $total_dipinjam; ?></h3>
            </div>

        </div>

        <!-- FILTER LAPORAN -->
        <div class="card">
            <h3>Filter Laporan</h3>

            <form method="GET"
                  action="laporan.php"
                  class="filter-laporan">

                <div class="form-group">
                    <label for="tanggal_mulai">Tanggal Mulai</label>

                    <input
                        type="date"
                        id="tanggal_mulai"
                        name="tanggal_mulai"
                        value="<?= htmlspecialchars($tanggal_mulai); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="tanggal_akhir">Tanggal Akhir</label>

                    <input
                        type="date"
                        id="tanggal_akhir"
                        name="tanggal_akhir"
                        value="<?= htmlspecialchars($tanggal_akhir); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="status">Status Peminjaman</label>

                    <select id="status" name="status">
                        <option value=""
                            <?= $status === '' ? 'selected' : ''; ?>>
                            Semua Status
                        </option>

                        <option value="Menunggu"
                            <?= $status === 'Menunggu' ? 'selected' : ''; ?>>
                            Menunggu
                        </option>

                        <option value="Disetujui"
                            <?= $status === 'Disetujui' ? 'selected' : ''; ?>>
                            Disetujui
                        </option>

                        <option value="Ditolak"
                            <?= $status === 'Ditolak' ? 'selected' : ''; ?>>
                            Ditolak
                        </option>

                        <option value="Dipinjam"
                            <?= $status === 'Dipinjam' ? 'selected' : ''; ?>>
                            Dipinjam
                        </option>

                        <option value="Selesai"
                            <?= $status === 'Selesai' ? 'selected' : ''; ?>>
                            Selesai
                        </option>
                    </select>
                </div>

                <button type="submit" class="tombol-filter">
                    Tampilkan
                </button>

                <a href="laporan.php" class="tombol-reset">
                    Reset
                </a>

            </form>
        </div>

        <!-- TABEL LAPORAN -->
        <div class="card">

            <h3>Data Laporan Peminjaman</h3>

            <button
                type="button"
                onclick="window.print()"
                class="tombol-cetak"
            >
                Cetak Laporan
            </button>

            <br><br>

            <div class="tabel-wrapper">

                <table class="tabel-laporan">

                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Kode Peminjaman</th>
                            <th>Nama Peminjam</th>
                            <th>Daftar Peralatan (Jumlah)</th>
                            <th>Total Alat</th>
                            <th>Tanggal Pinjam</th>
                            <th>Rencana Kembali</th>
                            <th>Keperluan</th>
                            <th>Status</th>
                            <th>Tanggal Kembali</th>
                            <th>Kondisi Kembali</th>
                            <th>Catatan Pengembalian</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (!empty($data_laporan)) { ?>

                            <?php $no = 1; ?>

                            <?php foreach ($data_laporan as $data) { ?>

                                <tr>
                                    <td><?= $no++; ?></td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $data['kode_peminjaman'] ?? '-'
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $data['nama_peminjam'] ?? '-'
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $data['daftar_peralatan'] ?? '-'
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= (int) ($data['total_alat'] ?? 0); ?>
                                    </td>

                                    <td>
                                        <?= tanggalIndonesia(
                                            $data['tanggal_pinjam']
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= tanggalIndonesia(
                                            $data['tanggal_rencana_kembali']
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= nl2br(
                                            htmlspecialchars(
                                                $data['keperluan'] ?? '-'
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <span class="status-label">
                                            <?= htmlspecialchars(
                                                $data['status'] ?? '-'
                                            ); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= tanggalIndonesia(
                                            $data['tanggal_kembali']
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $data['kondisi_saat_kembali'] ?? '-'
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= nl2br(
                                            htmlspecialchars(
                                                $data['catatan'] ?? '-'
                                            )
                                        ); ?>
                                    </td>
                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>
                                <td colspan="12"
                                    style="text-align:center; padding:20px;">
                                    Belum ada data laporan yang sesuai.
                                </td>
                            </tr>

                        <?php } ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</main>

</body>
</html>

<?php
mysqli_stmt_close($stmt);
?>
