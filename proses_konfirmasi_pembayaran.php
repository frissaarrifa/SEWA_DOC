
<?php
session_start();
require_once "koneksi.php";

// ========================================
// 1. CEK AKSES
// ========================================
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['admin', 'petugas'], true)) {
    if ($role === 'peminjam') {
        header("Location: pembayaran_saya.php");
    } else {
        header("Location: login.php");
    }
    exit;
}

// Hanya menerima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: konfirmasi_pembayaran.php");
    exit;
}

// Periksa token keamanan
$token_form = $_POST['csrf_token'] ?? '';
$token_session = $_SESSION['csrf_pembayaran'] ?? '';

if (
    !is_string($token_form)
    || !is_string($token_session)
    || $token_session === ''
    || !hash_equals($token_session, $token_form)
) {
    header("Location: konfirmasi_pembayaran.php?hasil=gagal");
    exit;
}

$id_user = (int) $_SESSION['id_user'];
$id_pembayaran = (int) ($_POST['id_pembayaran'] ?? 0);
$aksi = $_POST['aksi'] ?? '';
$catatan = trim($_POST['catatan'] ?? '');

if (
    $id_pembayaran <= 0
    || !in_array($aksi, ['konfirmasi', 'tolak'], true)
) {
    header("Location: konfirmasi_pembayaran.php?hasil=gagal");
    exit;
}

if ($aksi === 'tolak' && $catatan === '') {
    $_SESSION['pesan_konfirmasi'] =
        'Catatan wajib diisi ketika menolak pembayaran.';

    header("Location: konfirmasi_pembayaran.php?hasil=gagal");
    exit;
}

if (strlen($catatan) > 10000) {
    $_SESSION['pesan_konfirmasi'] = 'Catatan terlalu panjang.';
    header("Location: konfirmasi_pembayaran.php?hasil=gagal");
    exit;
}

$transaksi_dimulai = false;

try {
    mysqli_begin_transaction($conn);
    $transaksi_dimulai = true;

    // Kunci data pembayaran
    $stmt = mysqli_prepare($conn, "
        SELECT
            id_pembayaran,
            id_peminjaman,
            metode_pembayaran,
            jumlah_bayar,
            status_pembayaran,
            bukti_pembayaran
        FROM pembayaran
        WHERE id_pembayaran = ?
        FOR UPDATE
    ");

    mysqli_stmt_bind_param($stmt, "i", $id_pembayaran);
    mysqli_stmt_execute($stmt);

    $hasil = mysqli_stmt_get_result($stmt);
    $pembayaran = mysqli_fetch_assoc($hasil);

    mysqli_stmt_close($stmt);

    if (!$pembayaran) {
        throw new Exception('Data pembayaran tidak ditemukan.');
    }

    if ($pembayaran['status_pembayaran'] !== 'Menunggu Konfirmasi') {
        throw new Exception(
            'Pembayaran ini sudah diproses sebelumnya.'
        );
    }

    $id_peminjaman = (int) $pembayaran['id_peminjaman'];

    // Kunci data peminjaman
    $stmt = mysqli_prepare($conn, "
        SELECT
            id_peminjaman,
            kode_peminjaman,
            status_pembayaran,
            total_biaya
        FROM peminjaman
        WHERE id_peminjaman = ?
        FOR UPDATE
    ");

    mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
    mysqli_stmt_execute($stmt);

    $hasil = mysqli_stmt_get_result($stmt);
    $pinjam = mysqli_fetch_assoc($hasil);

    mysqli_stmt_close($stmt);

    if (!$pinjam) {
        throw new Exception('Data peminjaman tidak ditemukan.');
    }

    if ($aksi === 'konfirmasi') {
        // Jangan mengonfirmasi pembayaran jika tagihan sudah lunas.
        if ($pinjam['status_pembayaran'] === 'Sudah Dibayar') {
            throw new Exception('Tagihan ini sudah lunas. Periksa data pembayaran terlebih dahulu.');
        }

        // Nominal pembayaran wajib sama dengan total tagihan di database.
        if (abs((float)$pembayaran['jumlah_bayar'] - (float)$pinjam['total_biaya']) > 0.01) {
            throw new Exception('Nominal pembayaran tidak sama dengan total tagihan. Jangan konfirmasi sebelum diperiksa.');
        }

        // Untuk DANA, pastikan bukti benar-benar tersimpan dan berupa gambar yang diizinkan.
        if (strtoupper((string)$pembayaran['metode_pembayaran']) === 'DANA') {
            $bukti = (string)($pembayaran['bukti_pembayaran'] ?? '');
            if (!preg_match('~\Auploads/bukti_pembayaran/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)\z~i', $bukti)) {
                throw new Exception('Bukti pembayaran DANA belum diunggah atau lokasinya tidak valid.');
            }
            $pathBukti = __DIR__ . '/' . $bukti;
            if (!is_file($pathBukti)) {
                throw new Exception('File bukti pembayaran DANA tidak ditemukan di server. Minta peminjam mengirim ulang bukti.');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeBukti = $finfo->file($pathBukti);
            if (!in_array($mimeBukti, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new Exception('File bukti pembayaran bukan gambar yang valid.');
            }
        }
        // Bukti gambar bukan verifikasi otomatis: petugas tetap harus mencocokkan transaksi DANA yang masuk.

        // Catatan hanya diubah jika petugas mengisinya
        $catatan_simpan = $catatan !== '' ? $catatan : null;

        $stmt = mysqli_prepare($conn, "
            UPDATE pembayaran
            SET
                status_pembayaran = 'Dikonfirmasi',
                dikonfirmasi_oleh = ?,
                catatan = ?
            WHERE id_pembayaran = ?
              AND status_pembayaran = 'Menunggu Konfirmasi'
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "isi",
            $id_user,
            $catatan_simpan,
            $id_pembayaran
        );

        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) !== 1) {
            mysqli_stmt_close($stmt);
            throw new Exception(
                'Pembayaran gagal dikonfirmasi. Silakan periksa kembali.'
            );
        }

        mysqli_stmt_close($stmt);

        // Tandai tagihan peminjaman sebagai lunas
        $stmt = mysqli_prepare($conn, "
            UPDATE peminjaman
            SET status_pembayaran = 'Sudah Dibayar'
            WHERE id_peminjaman = ?
              AND status_pembayaran <> 'Sudah Dibayar'
        ");

        mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) !== 1) {
            mysqli_stmt_close($stmt);
            throw new Exception(
                'Status tagihan gagal diperbarui.'
            );
        }

        mysqli_stmt_close($stmt);

        $aktivitas = 'Mengonfirmasi pembayaran';
        $detail = 'Kode peminjaman: '
            . $pinjam['kode_peminjaman']
            . '; metode: '
            . $pembayaran['metode_pembayaran']
            . '; nominal: Rp '
            . number_format(
                (float) $pembayaran['jumlah_bayar'],
                0,
                ',',
                '.'
            );

        $hasil_redirect = 'dikonfirmasi';

    } else {
        // Tolak pembayaran, tetapi jangan menandai tagihan sebagai lunas
        $stmt = mysqli_prepare($conn, "
            UPDATE pembayaran
            SET
                status_pembayaran = 'Ditolak',
                dikonfirmasi_oleh = ?,
                catatan = ?
            WHERE id_pembayaran = ?
              AND status_pembayaran = 'Menunggu Konfirmasi'
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "isi",
            $id_user,
            $catatan,
            $id_pembayaran
        );

        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) !== 1) {
            mysqli_stmt_close($stmt);
            throw new Exception(
                'Pembayaran gagal ditolak. Silakan periksa kembali.'
            );
        }

        mysqli_stmt_close($stmt);

        $aktivitas = 'Menolak pembayaran';
        $detail = 'Kode peminjaman: '
            . $pinjam['kode_peminjaman']
            . '; metode: '
            . $pembayaran['metode_pembayaran']
            . '; nominal: Rp '
            . number_format(
                (float) $pembayaran['jumlah_bayar'],
                0,
                ',',
                '.'
            )
            . '; alasan: '
            . $catatan;

        $hasil_redirect = 'ditolak';
    }

    // Catat aktivitas jika tabel tersedia
    $stmt_log = @mysqli_prepare($conn, "
        INSERT INTO log_aktivitas (id_user, aktivitas, detail)
        VALUES (?, ?, ?)
    ");

    if ($stmt_log) {
        mysqli_stmt_bind_param(
            $stmt_log,
            "iss",
            $id_user,
            $aktivitas,
            $detail
        );

        @mysqli_stmt_execute($stmt_log);
        mysqli_stmt_close($stmt_log);
    }

    mysqli_commit($conn);
    $transaksi_dimulai = false;

    header(
        "Location: konfirmasi_pembayaran.php?hasil="
        . $hasil_redirect
    );
    exit;

} catch (Throwable $e) {
    if ($transaksi_dimulai) {
        mysqli_rollback($conn);
    }

    // Simpan pesan untuk ditampilkan pada halaman konfirmasi
    $_SESSION['pesan_konfirmasi'] = $e->getMessage();

    header("Location: konfirmasi_pembayaran.php?hasil=gagal");
    exit;
}
?>
