<?php
session_start();
include "koneksi.php";

// Pastikan pengguna sudah login
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Ambil data pengguna dari session
$nama = $_SESSION['nama'] ?? '';
$role = strtolower(trim($_SESSION['role'] ?? ''));

// Halaman ini khusus admin
if ($role !== 'admin') {
    header("Location: index.php");
    exit;
}

// Ambil data filter pencarian
$cari = trim($_GET['cari'] ?? '');
$tanggal_mulai = $_GET['tanggal_mulai'] ?? '';
$tanggal_akhir = $_GET['tanggal_akhir'] ?? '';

$where = [];
$params = [];
$types = "";

// Filter pencarian nama, aktivitas, dan detail
if ($cari !== '') {
    $where[] = "(u.nama LIKE ? OR l.aktivitas LIKE ? OR l.detail LIKE ?)";

    $keyword = "%" . $cari . "%";

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;

    $types .= "sss";
}

// Filter tanggal mulai
if ($tanggal_mulai !== '') {
    $where[] = "DATE(l.waktu) >= ?";
    $params[] = $tanggal_mulai;
    $types .= "s";
}

// Filter tanggal akhir
if ($tanggal_akhir !== '') {
    $where[] = "DATE(l.waktu) <= ?";
    $params[] = $tanggal_akhir;
    $types .= "s";
}

// Query data log aktivitas
$sql = "
    SELECT
        l.id_log,
        l.aktivitas,
        l.detail,
        l.waktu,
        u.nama AS nama_pengguna,
        u.username
    FROM log_aktivitas l
    LEFT JOIN users u ON l.id_user = u.id_user
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY l.waktu DESC, l.id_log DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Gagal menyiapkan query log aktivitas: " .
        htmlspecialchars(mysqli_error($conn)));
}

// Masukkan parameter pencarian
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

// Jalankan query
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$total_aktivitas = mysqli_num_rows($result);

// Menentukan menu sidebar aktif
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

    <title>Log Aktivitas | FRISDOC</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        .filter-log {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-log .form-group {
            flex: 1;
            min-width: 170px;
        }

        .filter-log label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .filter-log input {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .tombol-filter,
        .tombol-cetak {
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            background: #2677b8;
            color: white;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .tombol-reset {
            padding: 10px 16px;
            border-radius: 6px;
            background: #e5e7eb;
            color: #333;
            text-decoration: none;
            display: inline-block;
        }

        .tabel-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .tabel-log {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        .tabel-log th,
        .tabel-log td {
            padding: 12px;
            border: 1px solid #e2e8f0;
            text-align: left;
            vertical-align: top;
        }

        .tabel-log th {
            background: #eaf5ff;
            color: #174d75;
        }

        .jumlah-log {
            margin-bottom: 15px;
            color: #526579;
        }

        .teks-kosong {
            text-align: center !important;
            padding: 25px !important;
            color: #7895a8;
        }

        @media (max-width: 600px) {
            .filter-log .form-group {
                min-width: 100%;
            }
        }

        @media print {
            .sidebar,
            .topbar,
            .filter-log,
            .tombol-cetak {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .tabel-log {
                min-width: 0;
                font-size: 10px;
            }

            .card {
                box-shadow: none !important;
                border: none !important;
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
            <h2>Log Aktivitas</h2>
            <p>Riwayat aktivitas pengguna dalam sistem FRISDOC.</p>
        </div>

        <div class="user-info">
            <strong><?= htmlspecialchars($nama); ?></strong>
            <p><?= htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <div class="content">

        <!-- FILTER AKTIVITAS -->
        <div class="card">
            <h3>Filter Aktivitas</h3>

            <form method="GET"
                  action="log_aktivitas.php"
                  class="filter-log">

                <div class="form-group">
                    <label for="cari">Cari Aktivitas</label>

                    <input
                        type="text"
                        id="cari"
                        name="cari"
                        placeholder="Nama, aktivitas, atau detail"
                        value="<?= htmlspecialchars($cari); ?>"
                    >
                </div>

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

                <button type="submit" class="tombol-filter">
                    Cari
                </button>

                <a href="log_aktivitas.php"
                   class="tombol-reset">
                    Reset
                </a>

            </form>
        </div>

        <!-- TABEL LOG AKTIVITAS -->
        <div class="card">

            <h3>Riwayat Aktivitas Pengguna</h3>

            <p class="jumlah-log">
                Jumlah aktivitas ditemukan:
                <strong><?= $total_aktivitas; ?></strong>
            </p>

            <button
                type="button"
                onclick="window.print()"
                class="tombol-cetak"
            >
                Cetak Log
            </button>

            <br><br>

            <div class="tabel-wrapper">

                <table class="tabel-log">

                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Tanggal dan Jam</th>
                            <th>Nama Pengguna</th>
                            <th>Username</th>
                            <th>Aktivitas</th>
                            <th>Detail Aktivitas</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if ($total_aktivitas > 0) { ?>

                            <?php $no = 1; ?>

                            <?php while ($data = mysqli_fetch_assoc($result)) { ?>

                                <tr>
                                    <td><?= $no++; ?></td>

                                    <td>
                                        <?php
                                        echo !empty($data['waktu'])
                                            ? date(
                                                "d-m-Y H:i:s",
                                                strtotime($data['waktu'])
                                            )
                                            : '-';
                                        ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $data['nama_pengguna']
                                            ?? 'Pengguna tidak tersedia'
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $data['username'] ?? '-'
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $data['aktivitas'] ?? '-'
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= nl2br(
                                            htmlspecialchars(
                                                $data['detail'] ?? '-'
                                            )
                                        ); ?>
                                    </td>
                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>
                                <td colspan="6" class="teks-kosong">
                                    Belum ada aktivitas yang sesuai
                                    dengan pencarian.
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
