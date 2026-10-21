
<?php
session_start();
include "koneksi.php";

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

$role = strtolower(trim($_SESSION['role'] ?? ''));

if ($role !== 'peminjam') {
    if ($role === 'admin' || $role === 'petugas') {
        header("Location: index.php");
    } else {
        session_unset();
        session_destroy();
        header("Location: login.php");
    }
    exit;
}

$nama_user = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Peminjam';
$halaman_aktif = basename($_SERVER['PHP_SELF']);

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Mengambil data alat yang tersedia
$query = mysqli_query($conn, "
    SELECT
        id_peralatan,
        nama_peralatan,
        merk,
        kondisi,
        stok,
        foto,
        keterangan,
        harga_sewa
    FROM peralatan
    WHERE kondisi = 'Baik' AND stok > 0
    ORDER BY nama_peralatan ASC
");

if (!$query) {
    die("Gagal mengambil data alat: " . e(mysqli_error($conn)));
}

$total_alat = mysqli_num_rows($query);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Daftar Alat | SEWA_DOC</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        /* Tombol Keluar */
        .sidebar .menu-link.logout {
            color: #dc3545;
        }

        .sidebar .menu-link.logout:hover {
            background-color: #dc3545;
            color: #ffffff;
        }

        /* Tata letak kartu alat */
        .quick-menu {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            align-items: stretch;
        }

        .kartu-alat {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* Foto alat */
        .foto-alat {
            display: block;
            width: 100%;
            height: 220px;
            object-fit: contain;
            padding: 10px;
            box-sizing: border-box;
            background: #f7fbff;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .foto-tidak-tersedia {
            display: none;
            width: 100%;
            height: 220px;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 15px;
            box-sizing: border-box;
            background: #f0f6fa;
            color: #7895a8;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .harga-alat {
            margin-top: 12px;
            margin-bottom: 15px;
        }

        .harga-alat small {
            font-size: 14px;
            font-weight: normal;
        }

        .tombol-ajukan {
            display: inline-block;
            text-align: center;
            text-decoration: none;
            margin-top: auto;
        }

        .judul-daftar-alat {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
        }

        .judul-daftar-alat .section-title {
            margin: 0;
        }

        .welcome-card {
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .quick-menu {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "sidebar_peminjam.php"; ?>

<main class="main-content">

    <header class="topbar">
        <div>
            <h2>Daftar Alat</h2>
            <p>Pilih alat yang ingin kamu pinjam.</p>
        </div>

        <div class="user-info">
            Halo, <strong><?= e($nama_user) ?></strong>
        </div>
    </header>

    <section class="welcome-card">
        <h2>Selamat Datang di SEWA_DOC!</h2>

        <p>
            Silakan lihat alat yang tersedia dan periksa harga sewa per hari
            sebelum mengajukan peminjaman. Pengajuan akan diproses oleh petugas.
        </p>
    </section>

    <div class="judul-daftar-alat">
        <h3 class="section-title">Alat yang Tersedia</h3>
        <span class="badge"><?= $total_alat ?> jenis alat</span>
    </div>

    <div class="quick-menu">

        <?php if ($total_alat > 0): ?>

            <?php while ($alat = mysqli_fetch_assoc($query)): ?>

                <?php
                /*
                 * Semua foto alat disimpan di folder gambar/.
                 * Bisa membaca:
                 * kamera_sony.jpg
                 * gambar/kamera_sony.jpg
                 */
                $namaFoto = trim((string) ($alat['foto'] ?? ''));
                $namaFoto = str_replace('\\', '/', $namaFoto);
                $foto = '';

                if ($namaFoto !== '') {
                    // Foto yang diunggah admin tersimpan sebagai path relatif uploads/peralatan/...
                    if (strpos($namaFoto, 'uploads/peralatan/') === 0) {
                        $bagian = array_map('rawurlencode', explode('/', $namaFoto));
                        $foto = implode('/', $bagian);
                    } else {
                        // Kompatibilitas dengan foto lama yang hanya menyimpan nama file di folder gambar/.
                        $foto = 'gambar/' . rawurlencode(basename($namaFoto));
                    }
                }

                // Jika kolom foto kosong, gunakan foto berdasarkan ID alat
                if ($foto === '') {
                    if ((int) $alat['id_peralatan'] === 2) {
                        $foto = 'gambar/kamera_sony.jpg';
                    } elseif ((int) $alat['id_peralatan'] === 3) {
                        $foto = 'gambar/microphone_jete.jpg';
                    }
                }
                ?>

                <article class="content-card kartu-alat">

                    <?php if ($foto !== ''): ?>

                        <img
                            src="<?= e($foto) ?>"
                            alt="<?= e($alat['nama_peralatan']) ?>"
                            class="foto-alat"
                            loading="lazy"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                        >

                        <div class="foto-tidak-tersedia">
                            Foto tidak ditemukan. Periksa nama file di folder gambar.
                        </div>

                    <?php else: ?>

                        <div class="foto-tidak-tersedia" style="display:flex;">
                            Foto alat belum ditambahkan.
                        </div>

                    <?php endif; ?>

                    <h3 class="section-title">
                        <?= e($alat['nama_peralatan']) ?>
                    </h3>

                    <p>
                        <strong>Merek:</strong>
                        <?= e($alat['merk'] ?: 'Tidak dicantumkan') ?>
                    </p>

                    <p>
                        <strong>Kondisi:</strong>
                        <?= e($alat['kondisi']) ?>
                    </p>

                    <p>
                        <strong>Stok tersedia:</strong>
                        <?= (int) $alat['stok'] ?> unit
                    </p>

                    <?php if (!empty($alat['keterangan'])): ?>
                        <p><?= e($alat['keterangan']) ?></p>
                    <?php endif; ?>

                    <h3 class="section-title harga-alat">
                        Rp <?= number_format(
                            (float) $alat['harga_sewa'],
                            0,
                            ',',
                            '.'
                        ) ?>
                        <small>/ hari</small>
                    </h3>

                    <a
                        href="ajukan_peminjaman.php?id_peralatan=<?= (int) $alat['id_peralatan'] ?>"
                        class="btn btn-primary tombol-ajukan"
                    >
                        Ajukan Peminjaman
                    </a>

                </article>

            <?php endwhile; ?>

        <?php else: ?>

            <div class="content-card">
                <h3 class="section-title">Belum Ada Alat Tersedia</h3>

                <p>
                    Saat ini tidak ada alat berkondisi baik dengan stok lebih dari nol.
                </p>
            </div>

        <?php endif; ?>

    </div>

    <footer style="text-align:center; color:#7895A8; font-size:12px; margin-top:30px;">
        &copy; <?= date('Y') ?> SEWA_DOC - Sistem Peminjaman Alat
    </footer>

</main>

</body>
</html>
