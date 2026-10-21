<?php
session_start();
include "koneksi.php";

// Jika sudah login, arahkan sesuai role
if (isset($_SESSION['id_user'])) {
    $role = strtolower(trim($_SESSION['role'] ?? ''));

    if ($role === 'admin') {
        header("Location: index.php");
    } elseif ($role === 'petugas') {
        header("Location: index.php");
    } elseif ($role === 'peminjam') {
        header("Location: daftar_alat.php");
    } else {
        session_unset();
        session_destroy();
        header("Location: login.php");
    }

    exit;
}

$error = "";

if (isset($_GET['daftar']) && $_GET['daftar'] === 'berhasil') {
    $berhasil = "Pendaftaran berhasil! Silakan login dengan akun baru.";
} else {
    $berhasil = "";
}

if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Username dan password wajib diisi.";
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id_user, nama, username, password, role
             FROM users
             WHERE username = ?
             LIMIT 1"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);
            $data = mysqli_fetch_assoc($result);

            $valid = false;

            if ($data) {
                $hash = $data['password'];

                // Verifikasi password hash baru
                if (password_verify($password, $hash)) {
                    $valid = true;
                }
                // Mendukung password MD5 dari akun lama
                elseif (
                    strlen($hash) === 32 &&
                    hash_equals($hash, md5($password))
                ) {
                    $valid = true;

                    // Ubah MD5 menjadi password hash yang lebih aman
                    $hash_baru = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $update = mysqli_prepare(
                        $conn,
                        "UPDATE users SET password = ? WHERE id_user = ?"
                    );

                    if ($update) {
                        mysqli_stmt_bind_param(
                            $update,
                            "si",
                            $hash_baru,
                            $data['id_user']
                        );

                        mysqli_stmt_execute($update);
                        mysqli_stmt_close($update);
                    }
                }
            }

            mysqli_stmt_close($stmt);

            if ($valid) {
                $role = strtolower(trim($data['role']));

                // Hanya izinkan role yang dikenal
                $role_diizinkan = ['admin', 'petugas', 'peminjam'];

                if (!in_array($role, $role_diizinkan, true)) {
                    $error = "Role akun tidak valid. Hubungi admin.";
                } else {
                    session_regenerate_id(true);

                    $_SESSION['id_user'] = (int) $data['id_user'];
                    $_SESSION['nama'] = $data['nama'];
                    $_SESSION['username'] = $data['username'];
                    $_SESSION['role'] = $role;

                    // Arahkan sesuai jenis akun
                    if ($role === 'peminjam') {
                        header("Location: daftar_alat.php");
                    } else {
                        header("Location: index.php");
                    }

                    exit;
                }
            } else {
                $error = "Username atau password salah!";
            }
        } else {
            $error = "Terjadi kesalahan pada sistem login.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | FRISDOC</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        .pesan-login {
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 6px;
            font-size: 14px;
        }

        .pesan-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .pesan-berhasil {
            background: #dcfce7;
            color: #166534;
        }

        .tautan-daftar {
            text-align: center;
            margin-top: 18px;
            font-size: 14px;
        }

        .tautan-daftar a {
            color: #2677b8;
            font-weight: bold;
            text-decoration: none;
        }

        .tautan-daftar a:hover {
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
            <h2>Login Akun</h2>

            <?php if ($berhasil !== '') { ?>
                <div class="pesan-login pesan-berhasil">
                    <?= htmlspecialchars($berhasil); ?>
                </div>
            <?php } ?>

            <?php if ($error !== '') { ?>
                <div class="pesan-login pesan-error">
                    <?= htmlspecialchars($error); ?>
                </div>
            <?php } ?>

            <form method="POST" action="login.php">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="Masukkan username"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Masukkan password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button
                    type="submit"
                    name="login"
                    class="btn btn-primary"
                    style="width: 100%;"
                >
                    Login
                </button>

            </form>

            <p class="tautan-daftar">
                Belum punya akun?
                <a href="daftar.php">Daftar sekarang</a>
            </p>

            <p style="text-align:center; color:#7895A8; font-size:12px; margin-top:25px;">
                FRISDOC &copy; 2026
            </p>
        </div>

    </div>
</div>

</body>
</html>