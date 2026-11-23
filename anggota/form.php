<?php
require_once __DIR__ . '/../includes/init.php';
require_editor();

$me   = current_user();
$id   = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
if ($id <= 0) { // fitur tambah anggota dihapus: anggota baru masuk lewat persetujuan akun
    redirect('anggota/index.php');
}
$data = ['nim' => '', 'nama' => '', 'angkatan' => '', 'jabatan' => 'Anggota', 'divisi' => '', 'no_hp' => '', 'email' => '', 'status' => 'aktif'];
$akun = null;      // akun login yang terhubung (berdasarkan NIM)
$nimLama = '';

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM anggota WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        flash('error', 'Data anggota tidak ditemukan.');
        redirect('anggota/index.php');
    }
    $data = array_merge($data, $row);
    $nimLama = $row['nim'];
    $st = db()->prepare('SELECT id, nim, role, status FROM users WHERE nim = ? LIMIT 1');
    $st->execute([$nimLama]);
    $akun = $st->fetch() ?: null;
}

$rolesOk = role_boleh_dibuat();
$errors  = [];
$roleBaru = 'anggota';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (['nim', 'nama', 'angkatan', 'jabatan', 'divisi', 'no_hp', 'email', 'status'] as $f) {
        $data[$f] = trim(isset($_POST[$f]) ? (string)$_POST[$f] : '');
    }
    $pass     = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $pass2    = isset($_POST['password2']) ? (string)$_POST['password2'] : '';
    $roleBaru = isset($_POST['role']) ? (string)$_POST['role'] : 'anggota';

    // Akun login: wajib saat menambah anggota baru; opsional saat mengubah anggota yang belum punya akun
    $buatAkun = !$akun && ($id === 0 || $pass !== '');

    if (!preg_match('/^[0-9]{5,20}$/', $data['nim'])) {
        $errors[] = 'NIM harus berupa angka (5-20 digit).';
    }
    if (mb_strlen($data['nama']) < 3 || mb_strlen($data['nama']) > 100) {
        $errors[] = 'Nama harus 3-100 karakter.';
    }
    if ($data['angkatan'] !== '' && (!ctype_digit($data['angkatan']) || (int)$data['angkatan'] < 2000 || (int)$data['angkatan'] > (int)date('Y') + 1)) {
        $errors[] = 'Angkatan tidak valid (contoh: 2023).';
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }
    if ($data['no_hp'] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $data['no_hp'])) {
        $errors[] = 'Nomor HP hanya boleh berisi angka, spasi, + dan -.';
    }
    if (!in_array($data['status'], ['aktif', 'nonaktif'], true)) {
        $data['status'] = 'aktif';
    }
    if (mb_strlen($data['jabatan']) > 60 || mb_strlen($data['divisi']) > 80) {
        $errors[] = 'Jabatan maksimal 60 karakter dan divisi maksimal 80 karakter.';
    }

    if ($buatAkun) {
        if (strlen($pass) < 6) {
            $errors[] = 'Password akun minimal 6 karakter.';
        }
        if ($pass !== $pass2) {
            $errors[] = 'Konfirmasi password tidak sama.';
        }
        if (!in_array($roleBaru, $rolesOk, true)) {
            $errors[] = 'Hak akses tidak valid.';
        }
    }

    if (!$errors) {
        $cek = db()->prepare('SELECT id FROM anggota WHERE nim = ? AND id <> ?');
        $cek->execute([$data['nim'], $id]);
        if ($cek->fetch()) {
            $errors[] = 'NIM sudah terdaftar pada anggota lain.';
        }
    }

    // Cek bentrok dengan tabel akun
    if (!$errors) {
        $cu = db()->prepare('SELECT id, status FROM users WHERE nim = ? AND id <> ?');
        $cu->execute([$data['nim'], $akun ? (int)$akun['id'] : 0]);
        $bentrok = $cu->fetch();
        if ($bentrok) {
            $errors[] = $bentrok['status'] === 'pending'
                ? 'NIM ini sudah mengajukan akun dan menunggu persetujuan. Proses lewat menu Persetujuan Akun (Admin).'
                : 'NIM ini sudah memiliki akun login. Hubungi Admin untuk mengelolanya di Kelola Pengguna.';
        }
    }
    if (!$errors && $akun && $akun['role'] === 'admin' && !is_admin() && $data['nim'] !== $nimLama) {
        $errors[] = 'NIM anggota yang memiliki akun Admin hanya bisa diubah oleh Admin.';
    }

    if (!$errors) {
        $pdo = db();
        $vals = [
            $data['nim'], $data['nama'],
            $data['angkatan'] === '' ? angkatan_dari_nim($data['nim']) : (int)$data['angkatan'],
            $data['jabatan'] === '' ? null : $data['jabatan'],
            $data['divisi'] === '' ? null : $data['divisi'],
            $data['no_hp'] === '' ? null : $data['no_hp'],
            $data['email'] === '' ? null : $data['email'],
            $data['status'],
        ];
        $pesan = $id > 0 ? 'Data anggota berhasil diperbarui.' : 'Anggota baru berhasil ditambahkan.';
        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $sql = 'UPDATE anggota SET nim=?, nama=?, angkatan=?, jabatan=?, divisi=?, no_hp=?, email=?, status=?, updated_at=? WHERE id=?';
                $vals[] = now();
                $vals[] = $id;
            } else {
                $sql = 'INSERT INTO anggota (nim, nama, angkatan, jabatan, divisi, no_hp, email, status, created_at) VALUES (?,?,?,?,?,?,?,?,?)';
                $vals[] = now();
            }
            $pdo->prepare($sql)->execute($vals);

            if ($akun) {
                // jaga agar NIM (username) & nama akun tetap sama dengan data anggota
                $pdo->prepare('UPDATE users SET nim = ?, nama = ? WHERE id = ?')
                    ->execute([$data['nim'], $data['nama'], (int)$akun['id']]);
            } elseif ($buatAkun) {
                $pdo->prepare("INSERT INTO users (nim, nama, password, role, status, created_at, approved_by, approved_at) VALUES (?,?,?,?, 'aktif', ?, ?, ?)")
                    ->execute([$data['nim'], $data['nama'], password_hash($pass, PASSWORD_DEFAULT), $roleBaru, now(), (int)$me['id'], now()]);
                $uid = (int)$pdo->lastInsertId();
                $pesan .= ' Akun login dibuat: username ' . $data['nim'] . ' dengan hak akses ' . role_label($roleBaru) . '.';
            }
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Data gagal disimpan. Pastikan NIM belum dipakai dan coba lagi.';
        }

        if (!$errors) {
            if (isset($uid)) {
                notify($uid, 'Selamat datang di ' . APP_NAME . '!', 'Akun Anda dibuat oleh ' . $me['nama'] . ' sebagai ' . role_label($roleBaru) . '.', 'dashboard.php');
            }
            flash('success', $pesan);
            redirect('anggota/index.php');
        }
    }
}

$pageTitle = $id > 0 ? 'Ubah Anggota' : 'Tambah Anggota';
$active = 'anggota';
require __DIR__ . '/../includes/header.php';
$tampilBuatAkun = !$akun;
?>

<div class="card" style="max-width:820px">
    <div class="card-head"><h2><?= $id > 0 ? 'Ubah Data Anggota' : 'Tambah Anggota Baru' ?></h2></div>

    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div>
                <label for="nim">NIM <span class="req">*</span></label>
                <input type="text" id="nim" name="nim" value="<?= e($data['nim']) ?>" inputmode="numeric" required>
            </div>
            <div>
                <label for="nama">Nama Lengkap <span class="req">*</span></label>
                <input type="text" id="nama" name="nama" value="<?= e($data['nama']) ?>" required>
            </div>
            <div>
                <label for="angkatan">Angkatan</label>
                <input type="number" id="angkatan" name="angkatan" value="<?= e($data['angkatan']) ?>" min="2000" max="<?= (int)date('Y') + 1 ?>" placeholder="Kosongkan: otomatis dari NIM">
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="aktif" <?= $data['status'] === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                    <option value="nonaktif" <?= $data['status'] === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div>
                <label for="jabatan">Jabatan</label>
                <input type="text" id="jabatan" name="jabatan" value="<?= e($data['jabatan']) ?>" list="list-jabatan">
                <datalist id="list-jabatan"><?php foreach (jabatan_list() as $j): ?><option value="<?= e($j) ?>"><?php endforeach; ?></datalist>
            </div>
            <div>
                <label for="divisi">Divisi</label>
                <input type="text" id="divisi" name="divisi" value="<?= e($data['divisi']) ?>" list="list-divisi">
                <datalist id="list-divisi"><?php foreach (divisi_list() as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
            </div>
            <div>
                <label for="no_hp">No. HP</label>
                <input type="text" id="no_hp" name="no_hp" value="<?= e($data['no_hp']) ?>" placeholder="08xxxxxxxxxx">
            </div>
            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($data['email']) ?>">
            </div>
        </div>

        <div class="form-section">
            <h3>Akun Login</h3>
            <?php if ($akun): ?>
                <div class="alert alert-info" style="margin-bottom:0">
                    Anggota ini sudah punya akun login (username: <strong><?= e($akun['nim']) ?></strong>,
                    hak akses: <?= e(role_label($akun['role'])) ?>, status: <?= e($akun['status']) ?>).
                    <?php if (is_admin()): ?>Kelola password &amp; hak akses di <a href="<?= e(url('admin/pengguna.php')) ?>">Kelola Pengguna</a>.<?php endif; ?>
                </div>
            <?php else: ?>
                <p class="hint" style="margin:0 0 12px">
                    <?= $id > 0 ? 'Anggota ini belum punya akun. Isi password bila ingin membuatkan akun login (boleh dikosongkan).'
                               : 'Isi username &amp; password agar anggota ini bisa langsung login.' ?>
                </p>
                <div class="form-grid">
                    <div class="full">
                        <label for="username">Username</label>
                        <input type="text" id="username" value="<?= e($data['nim']) ?>" readonly tabindex="-1">
                        <div class="hint">Username login otomatis sama dengan NIM.</div>
                    </div>
                    <div>
                        <label for="password">Password <?= $id > 0 ? '' : '<span class="req">*</span>' ?></label>
                        <input type="password" id="password" name="password" minlength="6" autocomplete="new-password" <?= $id > 0 ? '' : 'required' ?>>
                        <div class="hint">Minimal 6 karakter.</div>
                    </div>
                    <div>
                        <label for="password2">Ulangi Password <?= $id > 0 ? '' : '<span class="req">*</span>' ?></label>
                        <input type="password" id="password2" name="password2" minlength="6" autocomplete="new-password" <?= $id > 0 ? '' : 'required' ?>>
                    </div>
                    <div class="full">
                        <label for="role">Hak Akses</label>
                        <select id="role" name="role">
                            <?php $roleLbl = role_list();
                            foreach ($rolesOk as $r): ?>
                                <option value="<?= e($r) ?>" <?= $roleBaru === $r ? 'selected' : '' ?>><?= e($roleLbl[$r]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">Simpan</button>
            <a class="btn btn-light" href="<?= e(url('anggota/index.php')) ?>">Batal</a>
        </div>
    </form>
</div>

<script>
(function () {
    var nim = document.getElementById('nim'), un = document.getElementById('username');
    if (nim && un) { nim.addEventListener('input', function () { un.value = nim.value.trim(); }); }
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
