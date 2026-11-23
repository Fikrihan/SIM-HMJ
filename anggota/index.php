<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

/* Hapus anggota (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_editor();
    csrf_verify();
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $st = db()->prepare('SELECT nama FROM anggota WHERE id = ?');
    $st->execute([$id]);
    $nama = $st->fetchColumn();
    if ($nama !== false) {
        db()->prepare('DELETE FROM anggota WHERE id = ?')->execute([$id]);
        flash('success', 'Anggota "' . $nama . '" berhasil dihapus.');
    } else {
        flash('error', 'Belum ada data anggota yang cocok.');
    }
    redirect('anggota/index.php');
}

$q      = trim(isset($_GET['q']) ? $_GET['q'] : '');
$status = isset($_GET['status']) ? $_GET['status'] : '';
$divisi = trim(isset($_GET['divisi']) ? $_GET['divisi'] : '');

$where = [];
$args  = [];
if ($q !== '') {
    $where[] = '(nim LIKE ? OR nama LIKE ? OR jabatan LIKE ? OR email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
if (in_array($status, ['aktif', 'nonaktif'], true)) {
    $where[] = 'status = ?';
    $args[] = $status;
}
if ($divisi !== '') {
    $where[] = 'divisi = ?';
    $args[] = $divisi;
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = db()->prepare("SELECT COUNT(*) FROM anggota $sqlWhere");
$cnt->execute($args);
$pg = paginate((int)$cnt->fetchColumn(), 10, isset($_GET['page']) ? $_GET['page'] : 1);

$st = db()->prepare("SELECT anggota.*, (SELECT u.role FROM users u WHERE u.nim = anggota.nim LIMIT 1) AS akun_role, (SELECT u.avatar FROM users u WHERE u.nim = anggota.nim LIMIT 1) AS akun_avatar FROM anggota $sqlWhere ORDER BY nama ASC LIMIT " . (int)$pg['per'] . ' OFFSET ' . (int)$pg['offset']);
$st->execute($args);
$rows = $st->fetchAll();

$divisiDipakai = db()->query("SELECT DISTINCT divisi FROM anggota WHERE divisi IS NOT NULL AND divisi <> '' ORDER BY divisi")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Data Anggota';
$active = 'anggota';
require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-head">
        <h2>Daftar Anggota (<?= (int)$pg['total'] ?>)</h2>
        <span class="muted small">Anggota baru masuk otomatis setelah akunnya disetujui Admin.</span>
    </div>

    <form class="toolbar" method="get">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari NIM, nama, jabatan, email...">
        <select name="divisi">
            <option value="">Semua divisi</option>
            <?php foreach ($divisiDipakai as $d): ?>
                <option value="<?= e($d) ?>" <?= $divisi === $d ? 'selected' : '' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">Semua status</option>
            <option value="aktif" <?= $status === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="nonaktif" <?= $status === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
        </select>
        <button class="btn btn-light" type="submit">Cari</button>
        <?php if ($q !== '' || $status !== '' || $divisi !== ''): ?>
            <a class="btn btn-light" href="<?= e(url('anggota/index.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div data-live="anggota-list">
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>NIM</th><th>Nama</th><th>Angkatan</th><th>Jabatan / Divisi</th><th>Kontak</th><th>Status</th><th>Akun Login</th><?php if (can_edit()): ?><th>Aksi</th><?php endif; ?></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="empty">Data anggota tidak ditemukan.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $a): ?>
                <tr>
                    <td><?= e($a['nim']) ?></td>
                    <td><div class="who"><?= avatar_html($a['nama'], $a['akun_avatar'], 'sm') ?><strong><?= e($a['nama']) ?></strong></div></td>
                    <td><?= e($a['angkatan'] ?: '-') ?></td>
                    <td><?= e($a['jabatan'] ?: '-') ?><div class="sub"><?= e($a['divisi'] ?: '-') ?></div></td>
                    <td><?= e($a['no_hp'] ?: '-') ?><div class="sub"><?= e($a['email'] ?: '') ?></div></td>
                    <td><?= badge($a['status']) ?></td>
                    <td><?= $a['akun_role'] ? '<span class="badge badge-info">' . e(ucfirst($a['akun_role'])) . '</span>' : '<span class="sub">Belum ada</span>' ?></td>
                    <?php if (can_edit()): ?>
                    <td>
                        <div class="actions">
                            <a class="btn btn-light btn-sm" href="<?= e(url('anggota/form.php?id=' . (int)$a['id'])) ?>">Ubah</a>
                            <form method="post" data-confirm="Hapus anggota ini? Tindakan tidak bisa dibatalkan.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Hapus</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_html($pg) ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
