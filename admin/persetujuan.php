<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $role   = isset($_POST['role']) ? $_POST['role'] : 'pengurus';
    if (!array_key_exists($role, role_list())) {
        $role = 'pengurus';
    }

    $st = db()->prepare("SELECT id, nim, nama FROM users WHERE id = ? AND status = 'pending'");
    $st->execute([$id]);
    $u = $st->fetch();

    if (!$u) {
        flash('error', 'Pengajuan tidak ditemukan atau sudah diproses.');
    } elseif ($action === 'setuju') {
        $up = db()->prepare("UPDATE users SET status = 'aktif', role = ?, approved_by = ?, approved_at = ? WHERE id = ?");
        $up->execute([$role, (int)$me['id'], now(), $id]);
        $baru = role_masuk_anggota($role) ? tambah_ke_anggota($u['nim'], $u['nama']) : false;
        notify($id, 'Selamat datang di ' . APP_NAME . '!', 'Akun Anda disetujui sebagai ' . role_label($role) . '.', 'dashboard.php');
        flash('success', 'Akun ' . $u['nama'] . ' (' . $u['nim'] . ') disetujui sebagai ' . role_label($role) . '.'
            . ($baru ? ' Otomatis ditambahkan ke Daftar Anggota.' : ''));
    } elseif ($action === 'tolak') {
        $up = db()->prepare("UPDATE users SET status = 'ditolak', approved_by = ?, approved_at = ? WHERE id = ?");
        $up->execute([(int)$me['id'], now(), $id]);
        flash('success', 'Pengajuan akun ' . $u['nama'] . ' (' . $u['nim'] . ') ditolak.');
    }
    redirect('admin/persetujuan.php');
}

$pending = db()->query("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at ASC")->fetchAll();
$history = db()->query(
    "SELECT u.*, ap.nama AS approver FROM users u
     LEFT JOIN users ap ON ap.id = u.approved_by
     WHERE u.status IN ('aktif','ditolak') AND u.approved_at IS NOT NULL AND u.approved_by IS NOT NULL
     ORDER BY u.approved_at DESC LIMIT 10"
)->fetchAll();

$pageTitle = 'Persetujuan Akun';
$active = 'persetujuan';
require __DIR__ . '/../includes/header.php';
?>

<div data-live="persetujuan">
<div class="card">
    <div class="card-head">
        <h2>Menunggu Persetujuan (<?= count($pending) ?>)</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>NIM</th><th>Nama</th><th>Diajukan</th><th>Hak Akses</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php if (!$pending): ?>
                <tr><td colspan="5" class="empty">Tidak ada pengajuan akun yang menunggu.</td></tr>
            <?php endif; ?>
            <?php foreach ($pending as $u): ?>
                <tr>
                    <td><?= e($u['nim']) ?><?php $ang = angkatan_dari_nim($u['nim']); if ($ang): ?><div class="sub">Angkatan <?= (int)$ang ?></div><?php endif; ?></td>
                    <td><strong><?= e($u['nama']) ?></strong></td>
                    <td><?= e(tgl_waktu($u['created_at'])) ?></td>
                    <td colspan="2">
                        <form method="post" class="actions">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <select name="role" style="width:auto">
                                <option value="pengurus">Pengurus (kelola data)</option>
                                <option value="anggota">Anggota (lihat saja)</option>
                                <option value="mahasiswa">Mahasiswa (lihat saja)</option>
                                <option value="admin">Admin</option>
                            </select>
                            <button class="btn btn-ok btn-sm" type="submit" name="action" value="setuju">Setujui</button>
                            <button class="btn btn-danger btn-sm" type="submit" name="action" value="tolak"
                                    onclick="return confirm('Tolak pengajuan akun ini?')">Tolak</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Riwayat Keputusan Terakhir</h2></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>NIM</th><th>Nama</th><th>Keputusan</th><th>Hak Akses</th><th>Oleh</th><th>Waktu</th></tr></thead>
            <tbody>
            <?php if (!$history): ?>
                <tr><td colspan="6" class="empty">Belum ada riwayat.</td></tr>
            <?php endif; ?>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= e($h['nim']) ?></td>
                    <td><?= e($h['nama']) ?></td>
                    <td><?= badge($h['status']) ?></td>
                    <td><?= $h['status'] === 'aktif' ? e(role_label($h['role'])) : '-' ?></td>
                    <td><?= e($h['approver'] ?: '-') ?></td>
                    <td><?= e(tgl_waktu($h['approved_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
