<?php
session_start();
include "koneksi.php";

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna';
$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));

// Data kategori hanya boleh dikelola oleh admin.
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

if (isset($_POST['tambah'])) {
    $nama_kategori = trim($_POST['nama_kategori']);
    $keterangan = trim($_POST['keterangan']);

    if ($nama_kategori === "") {
        $error = "Nama kategori wajib diisi.";
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO kategori (nama_kategori, keterangan) VALUES (?, ?)"
        );

        mysqli_stmt_bind_param($stmt, "ss", $nama_kategori, $keterangan);

        if (mysqli_stmt_execute($stmt)) {
            header("Location: kategori.php?pesan=tambah");
            exit;
        } else {
            $error = "Kategori gagal ditambahkan.";
        }

        mysqli_stmt_close($stmt);
    }
}

if (isset($_POST['edit'])) {
    $id_kategori = (int) $_POST['id_kategori'];
    $nama_kategori = trim($_POST['nama_kategori']);
    $keterangan = trim($_POST['keterangan']);

    if ($id_kategori <= 0 || $nama_kategori === "") {
        $error = "Data kategori tidak valid.";
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE kategori SET nama_kategori = ?, keterangan = ? WHERE id_kategori = ?"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "ssi",
            $nama_kategori,
            $keterangan,
            $id_kategori
        );

        if (mysqli_stmt_execute($stmt)) {
            header("Location: kategori.php?pesan=edit");
            exit;
        } else {
            $error = "Kategori gagal diperbarui.";
        }

        mysqli_stmt_close($stmt);
    }
}

if (isset($_GET['hapus'])) {
    $id_kategori = (int) $_GET['hapus'];

    if ($id_kategori > 0) {
        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM kategori WHERE id_kategori = ?"
        );

        mysqli_stmt_bind_param($stmt, "i", $id_kategori);

        if (mysqli_stmt_execute($stmt)) {
            header("Location: kategori.php?pesan=hapus");
            exit;
        } else {
            header("Location: kategori.php?pesan=gagal_hapus");
            exit;
        }

        mysqli_stmt_close($stmt);
    }
}

$edit_data = null;

if (isset($_GET['edit'])) {
    $id_edit = (int) $_GET['edit'];

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM kategori WHERE id_kategori = ?"
    );

    mysqli_stmt_bind_param($stmt, "i", $id_edit);
    mysqli_stmt_execute($stmt);

    $hasil_edit = mysqli_stmt_get_result($stmt);
    $edit_data = mysqli_fetch_assoc($hasil_edit);

    mysqli_stmt_close($stmt);
}

$kata_kunci = trim($_GET['cari'] ?? "");

if ($kata_kunci !== "") {
    $pola = "%" . $kata_kunci . "%";

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM kategori
         WHERE nama_kategori LIKE ? OR keterangan LIKE ?
         ORDER BY id_kategori DESC"
    );

    mysqli_stmt_bind_param($stmt, "ss", $pola, $pola);
    mysqli_stmt_execute($stmt);
    $data_kategori = mysqli_stmt_get_result($stmt);
} else {
    $data_kategori = mysqli_query(
        $conn,
        "SELECT * FROM kategori ORDER BY id_kategori DESC"
    );
}

function aktifKategori($file) {
    return basename($_SERVER['PHP_SELF']) === $file ? 'active' : '';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Kategori | FRISDOC</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include "sidebar_petugas_admin.php"; ?>

<main class="main-content">

    <div class="topbar">
        <div>
            <h2>Data Kategori</h2>
            <p>Kelola kategori alat dokumentasi</p>
        </div>
        <div class="user-info">
            <strong><?= htmlspecialchars($nama); ?></strong>
            <p><?= htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <?php
    $pesan = $_GET['pesan'] ?? "";

    if ($pesan === "tambah") {
        echo '<div class="alert alert-success">Kategori berhasil ditambahkan.</div>';
    } elseif ($pesan === "edit") {
        echo '<div class="alert alert-success">Kategori berhasil diperbarui.</div>';
    } elseif ($pesan === "hapus") {
        echo '<div class="alert alert-success">Kategori berhasil dihapus.</div>';
    } elseif ($pesan === "gagal_hapus") {
        echo '<div class="alert alert-danger">Kategori tidak dapat dihapus karena masih digunakan oleh data peralatan.</div>';
    }

    if ($error !== "") {
        echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
    }
    ?>

    <div class="content-card">
        <h3 class="section-title">
            <?= $edit_data ? "Edit Kategori" : "Tambah Kategori"; ?>
        </h3>

        <form method="POST">
            <?php if ($edit_data) { ?>
                <input type="hidden" name="id_kategori"
                       value="<?= (int) $edit_data['id_kategori']; ?>">
            <?php } ?>

            <div class="form-group">
                <label for="nama_kategori">Nama Kategori</label>
                <input
                    type="text"
                    id="nama_kategori"
                    name="nama_kategori"
                    class="form-control"
                    maxlength="100"
                    value="<?= htmlspecialchars($edit_data['nama_kategori'] ?? ''); ?>"
                    placeholder="Contoh: Kamera"
                    required
                >
            </div>

            <div class="form-group">
                <label for="keterangan">Keterangan</label>
                <textarea
                    id="keterangan"
                    name="keterangan"
                    class="form-control"
                    placeholder="Masukkan keterangan kategori"
                ><?= htmlspecialchars($edit_data['keterangan'] ?? ''); ?></textarea>
            </div>

            <?php if ($edit_data) { ?>
                <button type="submit" name="edit" class="btn btn-primary">
                    Simpan Perubahan
                </button>
                <a href="kategori.php" class="btn btn-secondary">Batal</a>
            <?php } else { ?>
                <button type="submit" name="tambah" class="btn btn-primary">
                    Tambah Kategori
                </button>
            <?php } ?>
        </form>
    </div>

    <div class="content-card">
        <h3 class="section-title">Daftar Kategori</h3>

        <form method="GET" class="filter-form">
            <div class="form-group">
                <label for="cari">Cari Kategori</label>
                <input
                    type="text"
                    id="cari"
                    name="cari"
                    class="form-control"
                    value="<?= htmlspecialchars($kata_kunci); ?>"
                    placeholder="Cari nama atau keterangan"
                >
            </div>

            <button type="submit" class="btn btn-primary">Cari</button>
            <a href="kategori.php" class="btn btn-secondary">Reset</a>
        </form>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Kategori</th>
                        <th>Keterangan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($data_kategori) > 0) { ?>
                        <?php $no = 1; ?>
                        <?php while ($row = mysqli_fetch_assoc($data_kategori)) { ?>
                            <tr>
                                <td><?= $no++; ?></td>
                                <td><?= htmlspecialchars($row['nama_kategori']); ?></td>
                                <td><?= htmlspecialchars($row['keterangan'] ?? '-'); ?></td>
                                <td>
                                    <a
                                        href="kategori.php?edit=<?= (int) $row['id_kategori']; ?>"
                                        class="btn btn-primary"
                                    >Edit</a>

                                    <a
                                        href="kategori.php?hapus=<?= (int) $row['id_kategori']; ?>"
                                        class="btn btn-danger"
                                        onclick="return confirm('Yakin ingin menghapus kategori ini?');"
                                    >Hapus</a>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="4" style="text-align:center;">
                                Belum ada data kategori.
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
