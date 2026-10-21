<?php
session_start();
include "koneksi.php";

// Jika sudah login, arahkan sesuai role
if (isset($_SESSION['id_user'])) {
    $role_login = strtolower(trim($_SESSION['role'] ?? ''));

    if ($role_login === 'peminjam') {
        header("Location: daftar_alat.php");
    } elseif (in_array($role_login, ['admin', 'petugas'], true)) {
        header("Location: index.php");
    } else {
        session_unset();
        session_destroy();
        header("Location: login.php");
    }

    exit;
}

$error = "";
$nama = "";
$username = "";

if (isset($_POST['daftar'])) {
    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $konfirmasi = $_POST['konfirmasi'] ?? '';

    if (
        $nama === '' ||
        $username === '' ||
        $password === '' ||
        $konfirmasi === ''
    ) {
        $error = "Semua kolom wajib diisi.";
    } elseif (strlen($username) < 4) {
        $error = "Username minimal 4 karakter.";
    } elseif (strlen($password) < 8) {
        $error = "Password minimal 8 karakter.";
    } elseif ($password !== $konfirmasi) {
        $error = "Konfirmasi password tidak sama.";
    } else {
        // Periksa username yang sudah digunakan
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id_user FROM users WHERE username = ? LIMIT 1"
        );

        if (!$stmt) {
            $error = "Terjadi kesalahan pada sistem.";
        } else {
            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);

            $hasil = mysqli_stmt_get_result($stmt);
            $username_ada = mysqli_num_rows($hasil) > 0;

            mysqli_stmt_close($stmt);

            if ($username_ada) {
                $error = "Username sudah digunakan. Silakan pilih username lain.";
            } else {
                // Password disimpan menggunakan hash yang aman
                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Pendaftaran umum hanya boleh menjadi peminjam
                $role = "peminjam";

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO users (nama, username, password, role)
                     VALUES (?, ?, ?, ?)"
                );

                if (!$stmt) {
                    $error = "Terjadi kesalahan saat menyiapkan pendaftaran.";
                } else {
                    mysqli_stmt_bind_param(
                        $stmt,
                        "ssss",
                        $nama,
                        $username,
                        $password_hash,
                        $role
                    );

                    if (mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);

                        header("Location: login.php?daftar=berhasil");
                        exit;
                    } else {
                        $error = "Pendaftaran gagal. Silakan coba lagi.";
                        mysqli_stmt_close($stmt);
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun | FRISDOC</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        .pesan-daftar {
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 6px;
            font-size: 14px;
        }

        .pesan-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .tautan-login {
            text-align: center;
            margin-top: 18px;
            font-size: 14px;
        }

        .tautan-login a {
            color: #2677b8;
            font-weight: bold;
            text-decoration: none;
        }

        .tautan-login a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">

        <div class="login-header">
            <h1>FRISDOC</h1>
            <p>Sistem Peminjaman Alat Dokumentasi</p>
        </div>

        <div class="login-body">
            <h2>Daftar Akun</h2>

            <?php if ($error !== '') { ?>
                <div class="pesan-daftar pesan-error">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php } ?>

            <form method="POST" action="daftar.php">

                <div class="form-group">
                    <label for="nama">Nama Lengkap</label>
                    <input
                        type="text"
                        id="nama"
                        name="nama"
                        class="form-control"
                        placeholder="Masukkan nama lengkap"
                        value="<?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8'); ?>"
                        maxlength="100"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="Minimal 4 karakter"
                        value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                        minlength="4"
                        maxlength="50"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Minimal 8 karakter"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="konfirmasi">Konfirmasi Password</label>
                    <input
                        type="password"
                        id="konfirmasi"
                        name="konfirmasi"
                        class="form-control"
                        placeholder="Ulangi password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button
                    type="submit"
                    name="daftar"
                    class="btn btn-primary"
                    style="width: 100%;"
                >
                    Daftar Sekarang
                </button>

            </form>

            <p class="tautan-login">
                Sudah punya akun?
                <a href="login.php">Login di sini</a>
            </p>

            <p style="text-align:center; color:#7895A8; font-size:12px; margin-top:25px;">
                FRISDOC &copy; 2026
            </p>
        </div>

    </div>
</div>

</body>
</html>