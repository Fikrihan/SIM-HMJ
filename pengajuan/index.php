<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$me = current_user();
$verifier = can_edit();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    $st = db()->prepare('SELECT * FROM pengajuan_kegiatan WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();

    if (!$p) {
        flash('error', 'Pengajuan tidak ditemukan.');
    } elseif ($action === 'setuju' || $action === 'tolak') {
        $catatan = trim(isset($_POST['catatan']) ? $_POST['catatan'] : '');
        if (!$verifier) {
            flash('error', 'Hanya Admin dan Pengurus yang dapat memverifikasi pengajuan.');
        } elseif ($p['status'] !== 'menunggu') {
            flash('error', 'Pengajuan ini sudah diverifikasi.');
        } elseif ((int)$p['pengaju_id'] === (int)$me['id'] && !is_admin()) {
            flash('error', 'Anda tidak dapat memverifikasi pengajuan Anda sendiri.');
        } elseif ($action === 'tolak' && $catatan === '') {
            flash('error', 'Isi catatan alasan penolakan terlebih dahulu.');
        } else {
            $status = $action === 'setuju' ? 'disetujui' : 'ditolak';
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE pengajuan_kegiatan SET status = ?, catatan = ?, verifikator_id = ?, verified_at = ? WHERE id = ?')
                    ->execute([$status, $catatan === '' ? null : mb_substr($catatan, 0, 1000), (int)$me['id'], now(), $id]);

                if ($status === 'disetujui') {
                    // Pengaju (jika sudah tercatat di Daftar Anggota) menjadi penanggung jawab
                    $pjSt = $pdo->prepare('SELECT a.id FROM anggota a JOIN users u ON u.nim = a.nim WHERE u.id = ? LIMIT 1');
                    $pjSt->execute([(int)$p['pengaju_id']]);
                    $pjId = $pjSt->fetchColumn();
                    $pdo->prepare("INSERT INTO program_kerja (nama_proker, divisi, deskripsi, lokasi, tanggal_mulai, tanggal_selesai, anggaran, penanggung_jawab, status, created_at)
                                   VALUES (?,?,?,?,?,?,?,?,'rencana',?)")
                        ->execute([$p['judul'], $p['divisi'], $p['deskripsi'], $p['lokasi'], $p['tanggal_mulai'], $p['tanggal_selesai'],
                                   $p['anggaran'], $pjId === false ? null : (int)$pjId, now()]);
                    $pdo->prepare('UPDATE pengajuan_kegiatan SET program_id = ? WHERE id = ?')->execute([(int)$pdo->lastInsertId(), $id]);
                }
                $pdo->commit();
            } catch (Exception $ex) {
                $pdo->rollBack();
                flash('error', 'Gagal memproses pengajuan. Pastikan database_update.sql sudah di-import.');
                redirect('pengajuan/index.php');
            }
            notify(
                (int)$p['pengaju_id'],
                'Pengajuan "' . $p['judul'] . '" ' . $status,
                ($status === 'disetujui' ? 'Kegiatan kini masuk ke Program Kegiatan. ' : '')
                    . 'Diverifikasi oleh ' . $me['nama'] . ($catatan !== '' ? '. Catatan: ' . $catatan : ''),
                $status === 'disetujui' ? 'kegiatan/index.php' : 'pengajuan/index.php'
            );
            if ($status === 'disetujui') {
                notify_roles(null, 'Kegiatan baru: ' . $p['judul'], 'Ditambahkan ke Program Kegiatan.', 'kegiatan/index.php', (int)$p['pengaju_id']);
            }
            flash('success', 'Pengajuan "' . $p['judul'] . '" ' . $status . ($status === 'disetujui' ? ' dan otomatis masuk ke Program Kegiatan.' : '.'));
        }
    } elseif ($action === 'hapus') {
        if ((int)$p['pengaju_id'] === (int)$me['id'] && $p['status'] === 'menunggu' || is_admin()) {
            db()->prepare('DELETE FROM pengajuan_kegiatan WHERE id = ?')->execute([$id]);
            hapus_foto($p['foto']);
            flash('success', 'Pengajuan dihapus.');
        } else {
            flash('error', 'Pengajuan yang sudah diverifikasi tidak dapat dihapus.');
        }
    }
    redirect('pengajuan/index.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

$status = isset($_GET['status']) ? $_GET['status'] : '';
$where = [];
$args  = [];
if (!$verifier) {
    $where[] = 'p.pengaju_id = ?';
    $args[]  = (int)$me['id'];
}
if (in_array($status, ['menunggu', 'disetujui', 'ditolak'], true)) {
    $where[] = 'p.status = ?';
    $args[]  = $status;
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = db()->prepare("SELECT COUNT(*) FROM pengajuan_kegiatan p $sqlWhere");
$cnt->execute($args);
$pg = paginate((int)$cnt->fetchColumn(), 10, isset($_GET['page']) ? $_GET['page'] : 1);

$st = db()->prepare(
    "SELECT p.*, u.nama AS pengaju, v.nama AS verifikator
     FROM pengajuan_kegiatan p
     JOIN users u ON u.id = p.pengaju_id
     LEFT JOIN users v ON v.id = p.verifikator_id
     $sqlWhere
     ORDER BY (p.status = 'menunggu') DESC, p.created_at DESC, p.id DESC
     LIMIT " . (int)$pg['per'] . ' OFFSET ' . (int)$pg['offset']
);
$st->execute($args);
$rows = $st->fetchAll();

$pageTitle = 'Pengajuan Kegiatan';
$active = 'pengajuan';
require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-head">
        <h2><?= $verifier ? 'Semua Pengajuan Kegiatan' : 'Pengajuan Saya' ?></h2>
        <a class="btn" href="<?= e(url('pengajuan/form.php')) ?>">+ Ajukan Kegiatan</a>
    </div>

    <form class="toolbar" method="get">
        <select name="status" onchange="this.form.submit()">
            <option value="">Semua status</option>
            <?php foreach (['menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-light" type="submit">Filter</button></noscript>
    </form>

    <div data-live="pengajuan">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kegiatan</th><th>Pengaju</th><th>Waktu</th><?php if (can_lihat_anggaran()): ?><th>Anggaran</th><?php endif; ?><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= can_lihat_anggaran() ? 6 : 5 ?>" class="empty">Belum ada pengajuan kegiatan.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $p): ?>
                    <?php $own = (int)$p['pengaju_id'] === (int)$me['id']; ?>
                    <tr>
                        <td>
                            <strong><?= e($p['judul']) ?></strong>
                            <div class="sub"><?= e($p['divisi'] ?: '-') ?> &middot; <?= e($p['lokasi'] ?: '-') ?></div>
                            <?php if ($p['status'] === 'disetujui' && $p['program_id']): ?><div class="sub"><a href="<?= e(url('kegiatan/index.php')) ?>">Sudah masuk Program Kegiatan</a></div><?php endif; ?>
                            <?php if ($p['deskripsi']): ?><div class="sub"><?= e(mb_substr($p['deskripsi'], 0, 140)) ?><?= mb_strlen($p['deskripsi']) > 140 ? '...' : '' ?></div><?php endif; ?>
                        </td>
                        <td><?= e($p['pengaju']) ?><div class="sub"><?= e(waktu_lalu($p['created_at'])) ?></div></td>
                        <td><?= e(tgl($p['tanggal_mulai'])) ?><?= $p['tanggal_selesai'] && $p['tanggal_selesai'] !== $p['tanggal_mulai'] ? ' - ' . e(tgl($p['tanggal_selesai'])) : '' ?></td>
                        <?php if (can_lihat_anggaran()): ?><td><?= e(rupiah($p['anggaran'])) ?></td><?php endif; ?>
                        <td>
                            <?= badge($p['status']) ?>
                            <?php if ($p['verifikator']): ?><div class="sub">oleh <?= e($p['verifikator']) ?></div><?php endif; ?>
                            <?php if ($p['catatan']): ?><div class="sub">Catatan: <?= e($p['catatan']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['status'] === 'menunggu' && $verifier && (!$own || is_admin())): ?>
                                <form method="post" class="actions">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <input type="text" name="catatan" placeholder="Catatan (wajib bila ditolak)" style="width:190px">
                                    <button class="btn btn-ok btn-sm" type="submit" name="action" value="setuju">Setujui</button>
                                    <button class="btn btn-danger btn-sm" type="submit" name="action" value="tolak">Tolak</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($own && $p['status'] === 'menunggu'): ?>
                                <div class="actions" style="margin-top:6px">
                                    <a class="btn btn-light btn-sm" href="<?= e(url('pengajuan/form.php?id=' . (int)$p['id'])) ?>">Ubah</a>
                                    <form method="post" data-confirm="Hapus pengajuan ini?"><?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button class="btn btn-light btn-sm" type="submit" name="action" value="hapus">Hapus</button></form>
                                </div>
                            <?php elseif (is_admin()): ?>
                                <form method="post" data-confirm="Hapus pengajuan ini?" style="margin-top:6px"><?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn-light btn-sm" type="submit" name="action" value="hapus">Hapus</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_html($pg) ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
