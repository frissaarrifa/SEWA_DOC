
<?php
session_start();
include "koneksi.php";

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna';
$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));

// Data peralatan hanya boleh dikelola oleh admin.
if ($role !== 'admin') {
    if ($role === 'peminjam') {
        header("Location: daftar_alat.php");
    } elseif ($role === 'petugas') {
        header("Location: index.php");
    } else {
        header("Location: login.php");
    }
    exit;
}

$error = "";

// Folder khusus untuk foto peralatan yang diunggah admin.
$folder_foto = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'peralatan';
$url_folder_foto = 'uploads/peralatan/';

if (isset($_POST['simpan'])) {
    $id_peralatan = (int) ($_POST['id_peralatan'] ?? 0);
    $id_kategori = (int) ($_POST['id_kategori'] ?? 0);
    $nama_peralatan = trim($_POST['nama_peralatan'] ?? '');
    $merk = trim($_POST['merk'] ?? '');
    $kondisi = $_POST['kondisi'] ?? '';
    $stok = (int) ($_POST['stok'] ?? 0);
    $keterangan = trim($_POST['keterangan'] ?? '');
    $harga_sewa = (float) ($_POST['harga_sewa'] ?? 0);
    $foto_lama = trim((string) ($_POST['foto_lama'] ?? ''));
    $foto_baru = '';

    $kondisi_valid = ['Baik', 'Rusak', 'Perbaikan'];

    if (
        $id_kategori <= 0 ||
        $nama_peralatan === "" ||
        $stok < 0 ||
        $harga_sewa <= 0 ||
        !in_array($kondisi, $kondisi_valid, true)
    ) {
        $error = "Periksa kembali data peralatan. Stok tidak boleh negatif dan harga sewa harus lebih dari Rp0.";
    } else {
        // Foto bersifat opsional. Saat edit, foto lama tetap dipakai jika tidak memilih foto baru.
        if (isset($_FILES['foto']) && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = $_FILES['foto'];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = "Foto gagal diunggah. Silakan coba lagi.";
            } elseif ((int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
                $error = "Ukuran foto maksimal 5 MB.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($upload['tmp_name']);
                $ekstensi_valid = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($ekstensi_valid[$mime])) {
                    $error = "Format foto harus JPG, PNG, atau WEBP.";
                } elseif (!is_uploaded_file($upload['tmp_name'])) {
                    $error = "File foto tidak valid.";
                } else {
                    if (!is_dir($folder_foto) && !mkdir($folder_foto, 0755, true) && !is_dir($folder_foto)) {
                        $error = "Folder penyimpanan foto tidak dapat dibuat.";
                    } else {
                        $nama_file = 'alat_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ekstensi_valid[$mime];
                        if (move_uploaded_file($upload['tmp_name'], $folder_foto . DIRECTORY_SEPARATOR . $nama_file)) {
                            $foto_baru = $url_folder_foto . $nama_file;
                        } else {
                            $error = "Foto gagal disimpan ke folder uploads/peralatan.";
                        }
                    }
                }
            }
        }

        if ($error === '') {
            $foto_simpan = $foto_baru !== '' ? $foto_baru : $foto_lama;

            if ($id_peralatan > 0) {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE peralatan
                     SET id_kategori=?, nama_peralatan=?, merk=?, kondisi=?, stok=?, keterangan=?, harga_sewa=?, foto=?
                     WHERE id_peralatan=?"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    "isssisdsi",
                    $id_kategori,
                    $nama_peralatan,
                    $merk,
                    $kondisi,
                    $stok,
                    $keterangan,
                    $harga_sewa,
                    $foto_simpan,
                    $id_peralatan
                );

                $pesan = "edit";
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO peralatan
                     (id_kategori, nama_peralatan, merk, kondisi, stok, keterangan, harga_sewa, foto)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    "isssisds",
                    $id_kategori,
                    $nama_peralatan,
                    $merk,
                    $kondisi,
                    $stok,
                    $keterangan,
                    $harga_sewa,
                    $foto_simpan
                );

                $pesan = "tambah";
            }

            // Tipe parameter update disusun di bawah agar cocok dengan 9 nilai.
            if ($stmt && mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                // Hapus foto lama hanya jika foto lama berada di folder unggahan aplikasi.
                if ($foto_baru !== '' && $foto_lama !== '' && strpos(str_replace('\\', '/', $foto_lama), 'uploads/peralatan/') === 0) {
                    $lama_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $foto_lama);
                    if (is_file($lama_path)) {
                        @unlink($lama_path);
                    }
                }
                header("Location: peralatan.php?pesan=" . $pesan);
                exit;
            } else {
                if (isset($stmt) && $stmt) {
                    mysqli_stmt_close($stmt);
                }
                // Jangan tinggalkan file baru jika penyimpanan database gagal.
                if ($foto_baru !== '') {
                    $file_baru_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $foto_baru);
                    if (is_file($file_baru_path)) @unlink($file_baru_path);
                }
                $error = "Data gagal disimpan. Pastikan kategori tersedia dan data sudah benar.";
            }
        }
    }
}

if (isset($_GET['hapus'])) {
    $id_hapus = (int) $_GET['hapus'];

    if ($id_hapus > 0) {
        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM peralatan WHERE id_peralatan = ?"
        );

        mysqli_stmt_bind_param($stmt, "i", $id_hapus);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            header("Location: peralatan.php?pesan=hapus");
            exit;
        } else {
            mysqli_stmt_close($stmt);
            header("Location: peralatan.php?pesan=gagal_hapus");
            exit;
        }
    }
}

$edit_data = null;

if (isset($_GET['edit'])) {
    $id_edit = (int) $_GET['edit'];

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM peralatan WHERE id_peralatan = ?"
    );

    mysqli_stmt_bind_param($stmt, "i", $id_edit);
    mysqli_stmt_execute($stmt);

    $hasil = mysqli_stmt_get_result($stmt);
    $edit_data = mysqli_fetch_assoc($hasil);

    mysqli_stmt_close($stmt);
}

$kategori = mysqli_query(
    $conn,
    "SELECT * FROM kategori ORDER BY nama_kategori ASC"
);

$cari = trim($_GET['cari'] ?? "");

if ($cari !== "") {
    $pola = "%" . $cari . "%";

    $stmt = mysqli_prepare(
        $conn,
        "SELECT p.*, k.nama_kategori
         FROM peralatan p
         JOIN kategori k ON p.id_kategori = k.id_kategori
         WHERE p.nama_peralatan LIKE ?
            OR p.merk LIKE ?
            OR k.nama_kategori LIKE ?
         ORDER BY p.id_peralatan DESC"
    );

    mysqli_stmt_bind_param($stmt, "sss", $pola, $pola, $pola);
    mysqli_stmt_execute($stmt);
    $data_peralatan = mysqli_stmt_get_result($stmt);
} else {
    $data_peralatan = mysqli_query(
        $conn,
        "SELECT p.*, k.nama_kategori
         FROM peralatan p
         JOIN kategori k ON p.id_kategori = k.id_kategori
         ORDER BY p.id_peralatan DESC"
    );
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Peralatan | FRISDOC</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include "sidebar_petugas_admin.php"; ?>

<main class="main-content">

    <div class="topbar">
        <div>
            <h2>Data Peralatan</h2>
            <p>Kelola stok, kondisi, dan harga sewa alat dokumentasi</p>
        </div>

        <div class="user-info">
            <strong><?= htmlspecialchars($nama); ?></strong>
            <p><?= htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <?php
    $pesan = $_GET['pesan'] ?? "";

    if ($pesan === "tambah") {
        echo '<div class="alert alert-success">Peralatan berhasil ditambahkan.</div>';
    } elseif ($pesan === "edit") {
        echo '<div class="alert alert-success">Peralatan berhasil diperbarui.</div>';
    } elseif ($pesan === "hapus") {
        echo '<div class="alert alert-success">Peralatan berhasil dihapus.</div>';
    } elseif ($pesan === "gagal_hapus") {
        echo '<div class="alert alert-danger">Peralatan tidak bisa dihapus karena sudah digunakan dalam peminjaman.</div>';
    }

    if ($error !== "") {
        echo '<div class="alert alert-danger">' .
            htmlspecialchars($error) .
            '</div>';
    }
    ?>

    <div class="content-card">
        <h3 class="section-title">
            <?= $edit_data ? "Edit Peralatan" : "Tambah Peralatan"; ?>
        </h3>

        <?php if (mysqli_num_rows($kategori) === 0) { ?>
            <div class="alert alert-warning">
                Tambahkan kategori terlebih dahulu sebelum memasukkan peralatan.
                <a href="kategori.php">Buka Data Kategori</a>
            </div>
        <?php } else { ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="id_peralatan"
                   value="<?= (int) ($edit_data['id_peralatan'] ?? 0); ?>">
            <input type="hidden" name="foto_lama"
                   value="<?= htmlspecialchars((string) ($edit_data['foto'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">
                <label for="id_kategori">Kategori</label>
                <select name="id_kategori" id="id_kategori"
                        class="form-control" required>
                    <option value="">-- Pilih Kategori --</option>

                    <?php
                    mysqli_data_seek($kategori, 0);

                    while ($k = mysqli_fetch_assoc($kategori)) {
                        $selected = (
                            (int) ($edit_data['id_kategori'] ?? 0)
                            === (int) $k['id_kategori']
                        ) ? 'selected' : '';
                    ?>
                        <option value="<?= (int) $k['id_kategori']; ?>"
                            <?= $selected; ?>>
                            <?= htmlspecialchars($k['nama_kategori']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label for="nama_peralatan">Nama Peralatan</label>
                <input type="text" name="nama_peralatan"
                       id="nama_peralatan" class="form-control"
                       maxlength="100"
                       value="<?= htmlspecialchars($edit_data['nama_peralatan'] ?? ''); ?>"
                       placeholder="Contoh: Kamera DSLR" required>
            </div>

            <div class="form-group">
                <label for="merk">Merek</label>
                <input type="text" name="merk" id="merk"
                       class="form-control" maxlength="100"
                       value="<?= htmlspecialchars($edit_data['merk'] ?? ''); ?>"
                       placeholder="Contoh: Canon">
            </div>

            <div class="form-group">
                <label for="foto">Foto Peralatan</label>
                <?php
                $foto_edit = trim((string) ($edit_data['foto'] ?? ''));
                if ($foto_edit !== '') {
                    $foto_edit_url = str_replace('\\', '/', $foto_edit);
                    if (strpos($foto_edit_url, '/') === false) {
                        $foto_edit_url = 'gambar/' . rawurlencode($foto_edit_url);
                    } else {
                        $foto_edit_url = implode('/', array_map('rawurlencode', explode('/', $foto_edit_url)));
                    }
                }
                ?>
                <?php if ($edit_data && $foto_edit !== '') { ?>
                    <div style="margin-bottom:10px;">
                        <img src="<?= htmlspecialchars($foto_edit_url, ENT_QUOTES, 'UTF-8'); ?>"
                             alt="Foto peralatan saat ini"
                             style="width:160px;height:120px;object-fit:contain;background:#f7fbff;border-radius:10px;padding:6px;"
                             onerror="this.style.display='none';">
                    </div>
                <?php } ?>
                <input type="file" name="foto" id="foto" class="form-control"
                       accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                <small>Opsional. Format JPG, PNG, atau WEBP; ukuran maksimal 5 MB. Jika mengedit, foto lama tetap digunakan jika tidak memilih foto baru.</small>
            </div>

            <div class="form-group">
                <label for="kondisi">Kondisi</label>
                <select name="kondisi" id="kondisi"
                        class="form-control" required>
                    <?php
                    $pilihan_kondisi = ['Baik', 'Rusak', 'Perbaikan'];
                    $kondisi_sekarang = $edit_data['kondisi'] ?? 'Baik';

                    foreach ($pilihan_kondisi as $pilihan) {
                        $selected = ($kondisi_sekarang === $pilihan)
                            ? 'selected'
                            : '';

                        echo '<option value="' .
                            htmlspecialchars($pilihan) . '" ' .
                            $selected . '>' .
                            htmlspecialchars($pilihan) .
                            '</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label for="stok">Stok</label>
                <input type="number" name="stok" id="stok"
                       class="form-control" min="0"
                       value="<?= (int) ($edit_data['stok'] ?? 0); ?>"
                       required>
            </div>

            <div class="form-group">
                <label for="harga_sewa">Harga Sewa per Hari (Rp)</label>
                <input type="number" name="harga_sewa" id="harga_sewa"
                       class="form-control" min="1" step="0.01"
                       value="<?= htmlspecialchars(
                           (string) ($edit_data['harga_sewa'] ?? '')
                       ); ?>"
                       placeholder="Contoh: 50000" required>
                <small>Masukkan harga sewa untuk satu alat selama satu hari.</small>
            </div>

            <div class="form-group">
                <label for="keterangan">Keterangan</label>
                <textarea name="keterangan" id="keterangan"
                          class="form-control"
                          placeholder="Keterangan tambahan"><?= htmlspecialchars($edit_data['keterangan'] ?? ''); ?></textarea>
            </div>

            <button type="submit" name="simpan" class="btn btn-primary">
                <?= $edit_data ? "Simpan Perubahan" : "Tambah Peralatan"; ?>
            </button>

            <?php if ($edit_data) { ?>
                <a href="peralatan.php" class="btn btn-secondary">Batal</a>
            <?php } ?>
        </form>

        <?php } ?>
    </div>

    <div class="content-card">
        <h3 class="section-title">Daftar Peralatan</h3>

        <form method="GET" class="filter-form">
            <div class="form-group">
                <label for="cari">Cari Peralatan</label>
                <input type="text" name="cari" id="cari"
                       class="form-control"
                       value="<?= htmlspecialchars($cari); ?>"
                       placeholder="Nama alat, merek, atau kategori">
            </div>

            <button type="submit" class="btn btn-primary">Cari</button>
            <a href="peralatan.php" class="btn btn-secondary">Reset</a>
        </form>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Foto</th>
                        <th>Nama Peralatan</th>
                        <th>Kategori</th>
                        <th>Merek</th>
                        <th>Kondisi</th>
                        <th>Stok</th>
                        <th>Harga Sewa/Hari</th>
                        <th>Keterangan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (mysqli_num_rows($data_peralatan) > 0) { ?>
                    <?php $no = 1; ?>

                    <?php while ($row = mysqli_fetch_assoc($data_peralatan)) { ?>
                        <tr>
                            <td><?= $no++; ?></td>

                            <td>
                                <?php
                                $foto_tabel = trim((string) ($row['foto'] ?? ''));
                                if ($foto_tabel !== '') {
                                    $foto_tabel = str_replace('\\', '/', $foto_tabel);
                                    if (strpos($foto_tabel, '/') === false) {
                                        $foto_tabel_url = 'gambar/' . rawurlencode($foto_tabel);
                                    } else {
                                        $foto_tabel_url = implode('/', array_map('rawurlencode', explode('/', $foto_tabel)));
                                    }
                                }
                                ?>
                                <?php if ($foto_tabel !== '') { ?>
                                    <img src="<?= htmlspecialchars($foto_tabel_url, ENT_QUOTES, 'UTF-8'); ?>"
                                         alt="Foto <?= htmlspecialchars($row['nama_peralatan'], ENT_QUOTES, 'UTF-8'); ?>"
                                         style="width:76px;height:58px;object-fit:contain;background:#f7fbff;border-radius:7px;padding:4px;"
                                         onerror="this.style.display='none';">
                                <?php } else { echo '-'; } ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['nama_peralatan']); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['nama_kategori']); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['merk'] ?: '-'); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['kondisi']); ?>
                            </td>

                            <td><?= (int) $row['stok']; ?></td>

                            <td>
                                Rp <?= number_format(
                                    (float) $row['harga_sewa'],
                                    0,
                                    ',',
                                    '.'
                                ); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['keterangan'] ?: '-'); ?>
                            </td>

                            <td>
                                <a href="peralatan.php?edit=<?= (int) $row['id_peralatan']; ?>"
                                   class="btn btn-primary">Edit</a>

                                <a href="peralatan.php?hapus=<?= (int) $row['id_peralatan']; ?>"
                                   class="btn btn-danger"
                                   onclick="return confirm('Yakin ingin menghapus peralatan ini?');">
                                   Hapus
                                </a>
                            </td>
                        </tr>
                    <?php } ?>

                <?php } else { ?>
                    <tr>
                        <td colspan="10" style="text-align:center;">
                            Belum ada data peralatan.
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
