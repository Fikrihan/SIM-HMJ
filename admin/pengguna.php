<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    $st = db()->prepare('SELECT id, nim, nama, status FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();

    if (!$u) {
        flash('error', 'Pengguna tidak ditemukan.');
    } elseif ((int)$u['id'] === (int)$me['id']) {
        flash('error', 'Anda tidak bisa mengubah akun Anda sendiri dari halaman ini. Gunakan menu Akun Saya.');
    } elseif ($action === 'role') {
        $role = isset($_POST['role']) ? $_POST['role'] : '';
        if (array_key_exists($role, role_list())) {
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            flash('success', 'Hak akses ' . $u['nama'] . ' diubah menjadi ' . role_label($role) . '.');
        }
    } elseif ($action === 'nonaktif') {
        db()->prepare("UPDATE users SET status = 'nonaktif' WHERE id = ? AND status = 'aktif'")->execute([$id]);
        cabut_sesi($id);
        flash('success', 'Akun ' . $u['nama'] . ' dinonaktifkan.');
    } elseif ($action === 'aktif') {
        db()->prepare("UPDATE users SET status = 'aktif' WHERE id = ? AND status IN ('nonaktif','ditolak')")->execute([$id]);
        $rl = db()->prepare('SELECT role FROM users WHERE id = ?');
        $rl->execute([$id]);
        if (ctype_digit((string)$u['nim']) && role_masuk_anggota($rl->fetchColumn())) {
            tambah_ke_anggota($u['nim'], $u['nama']);
        }
        flash('success', 'Akun ' . $u['nama'] . ' diaktifkan kembali.');
    } elseif ($action === 'reset') {
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($u['nim'], PASSWORD_DEFAULT), $id]);
        cabut_sesi($id);
        flash('success', 'Password ' . $u['nama'] . ' direset menjadi NIM-nya (' . $u['nim'] . ').');
    } elseif ($action === 'logout') {
        $n = cabut_sesi($id);
        flash('success', $n > 0 ? 'Akun ' . $u['nama'] . ' dikeluarkan paksa dari web.' : 'Akun ' . $u['nama'] . ' sedang tidak login.');
    } elseif ($action === 'hapus') {
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        flash('success', 'Akun ' . $u['nama'] . ' dihapus.');
    }
    redirect('admin/pengguna.php');
}

$onlineIds = [];
$stOn = db()->prepare('SELECT DISTINCT user_id FROM sesi_login WHERE last_activity >= ?');
$stOn->execute([online_cutoff()]);
foreach ($stOn->fetchAll(PDO::FETCH_COLUMN) as $oid) {
    $onlineIds[(int)$oid] = true;
}

$users = db()->query(
    "SELECT * FROM users WHERE status <> 'pending'
     ORDER BY CASE role WHEN 'admin' THEN 0 WHEN 'pengurus' THEN 1 WHEN 'anggota' THEN 2 ELSE 3 END, nama ASC"
)->fetchAll();

$pageTitle = 'Kelola Pengguna';
$active = 'pengguna';
require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-head">
        <h2>Daftar Pengguna (<?= count($users) ?>)</h2>
        <a class="btn btn-light btn-sm" href="<?= e(url('admin/persetujuan.php')) ?>">Pengajuan Akun Baru</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>NIM</th><th>Nama</th><th>Hak Akses</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$me['id']; ?>
                <tr>
                    <td><?= e($u['nim']) ?></td>
                    <td><strong><?= e($u['nama']) ?></strong><?= $self ? ' <span class="muted small">(Anda)</span>' : '' ?><?= isset($onlineIds[(int)$u['id']]) ? ' <span class="badge badge-ok">Online</span>' : '' ?></td>
                    <td>
                        <?php if ($self): ?>
                            <?= e(role_label($u['role'])) ?>
                        <?php else: ?>
                            <form method="post" class="actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="action" value="role">
                                <select name="role" style="width:auto" onchange="this.form.submit()">
                                    <?php foreach (role_list() as $k => $v): ?>
                                        <option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td><?= badge($u['status']) ?></td>
                    <td>
                        <?php if (!$self): ?>
                        <div class="actions">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <?php if ($u['status'] === 'aktif'): ?>
                                    <button class="btn btn-light btn-sm" name="action" value="nonaktif">Nonaktifkan</button>
                                <?php else: ?>
                                    <button class="btn btn-ok btn-sm" name="action" value="aktif">Aktifkan</button>
                                <?php endif; ?>
                            </form>
                            <?php if (isset($onlineIds[(int)$u['id']])): ?>
                            <form method="post" data-confirm="Keluarkan paksa akun ini dari web sekarang?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-light btn-sm" name="action" value="logout">Paksa Logout</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" data-confirm="Reset password akun ini menjadi NIM-nya?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-light btn-sm" name="action" value="reset">Reset Password</button>
                            </form>
                            <form method="post" data-confirm="Hapus akun ini secara permanen?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-danger btn-sm" name="action" value="hapus">Hapus</button>
                            </form>
                        </div>
                        <?php else: ?>
                            <span class="muted small">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Reset password mengatur ulang password menjadi NIM pengguna. Minta pengguna menggantinya lewat menu Akun Saya.</p>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
