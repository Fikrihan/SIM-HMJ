<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

/* Hapus program kegiatan (POST) - hanya Admin & Pengurus */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_editor();
    csrf_verify();
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $st = db()->prepare('SELECT nama_proker, foto FROM program_kerja WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        db()->prepare('DELETE FROM program_kerja WHERE id = ?')->execute([$id]);
        hapus_foto($row['foto']);
        flash('success', 'Program kegiatan "' . $row['nama_proker'] . '" berhasil dihapus.');
    } else {
        flash('error', 'Program kegiatan tidak ditemukan.');
    }
    redirect('kegiatan/index.php');
}

$q      = trim(isset($_GET['q']) ? $_GET['q'] : '');
$status = isset($_GET['status']) ? $_GET['status'] : '';
$statusList = status_proker_list();

$where = [];
$args  = [];
if ($q !== '') {
    $where[] = '(p.nama_proker LIKE ? OR p.divisi LIKE ? OR a.nama LIKE ? OR p.lokasi LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
if (isset($statusList[$status])) {
    $where[] = 'p.status = ?';
    $args[] = $status;
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$from = 'FROM program_kerja p LEFT JOIN anggota a ON a.id = p.penanggung_jawab';

$cnt = db()->prepare("SELECT COUNT(*) $from $sqlWhere");
$cnt->execute($args);
$pg = paginate((int)$cnt->fetchColumn(), 9, isset($_GET['page']) ? $_GET['page'] : 1);

$meId = (int)current_user()['id'];
$st = db()->prepare(
    "SELECT p.*, a.nama AS pj_nama,
            (SELECT COUNT(*) FROM pendaftaran_kegiatan d WHERE d.program_id = p.id AND d.status = 'disetujui') AS terisi,
            (SELECT COUNT(*) FROM pendaftaran_kegiatan d WHERE d.program_id = p.id AND d.status = 'menunggu') AS menunggu,
            (SELECT d.status FROM pendaftaran_kegiatan d WHERE d.program_id = p.id AND d.user_id = $meId) AS my_status
     $from $sqlWhere
     ORDER BY (p.tanggal_mulai IS NULL), p.tanggal_mulai DESC, p.id DESC
     LIMIT " . (int)$pg['per'] . ' OFFSET ' . (int)$pg['offset']
);
$st->execute($args);
$rows = $st->fetchAll();
if (!can_lihat_anggaran()) {
    foreach ($rows as &$__r) { unset($__r['anggaran']); }
    unset($__r);
}

$lihatAnggaran = can_lihat_anggaran();
$totalAnggaran = $lihatAnggaran
    ? db()->query("SELECT COALESCE(SUM(anggaran), 0) FROM program_kerja WHERE status <> 'batal'")->fetchColumn()
    : 0;

$pageTitle = 'Program Kegiatan';
$active = 'kegiatan';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h2>Program Kegiatan (<?= (int)$pg['total'] ?>)</h2>
        <?php if ($lihatAnggaran): ?>
            <p class="muted">Total anggaran (di luar yang batal): <strong><?= e(rupiah($totalAnggaran)) ?></strong></p>
        <?php endif; ?>
    </div>
    <?php if (can_edit()): ?>
        <a class="btn" href="<?= e(url('kegiatan/form.php')) ?>">+ Tambah Kegiatan</a>
    <?php endif; ?>
</div>

<form class="toolbar" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari kegiatan, divisi, lokasi, penanggung jawab...">
    <select name="status">
        <option value="">Semua status</option>
        <?php foreach ($statusList as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-light" type="submit">Cari</button>
    <?php if ($q !== '' || $status !== ''): ?><a class="btn btn-light" href="<?= e(url('kegiatan/index.php')) ?>">Reset</a><?php endif; ?>
</form>

<div data-live="kegiatan-list">
    <?php if (!$rows): ?>
        <div class="card"><div class="empty">Belum ada program kegiatan.</div></div>
    <?php endif; ?>
    <div class="kegiatan-grid">
        <?php foreach ($rows as $p): ?>
            <article class="kegiatan-card">
                <div class="kegiatan-head">
                    <div class="kegiatan-status"><?= badge($p['status']) ?></div>
                </div>
                <div class="kegiatan-body">
                    <h3><?= e($p['nama_proker']) ?></h3>
                    <div class="kegiatan-info">
                        <div>&#128197; <?= e(tgl($p['tanggal_mulai'])) ?><?= $p['tanggal_selesai'] && $p['tanggal_selesai'] !== $p['tanggal_mulai'] ? ' - ' . e(tgl($p['tanggal_selesai'])) : '' ?></div>
                        <?php if ($p['lokasi']): ?><div>&#128205; <?= e($p['lokasi']) ?></div><?php endif; ?>
                        <div>&#128101; <?= e($p['divisi'] ?: '-') ?> &middot; PJ: <?= e($p['pj_nama'] ?: '-') ?></div>
                        <?php if ($lihatAnggaran): ?><div>&#128176; <?= e(rupiah($p['anggaran'])) ?></div><?php endif; ?>
                        <?php $kq = $p['kuota'] !== null && (int)$p['kuota'] > 0 ? (int)$p['kuota'] : null; $penuh = kuota_penuh($kq, $p['terisi']); ?>
                        <div>&#127915; <?= e(kuota_label($kq, $p['terisi'])) ?><?= $penuh ? ' &middot; <strong>Penuh</strong>' : '' ?></div>
                    </div>
                    <?php if ($kq): ?>
                        <div class="kuota-bar" title="<?= (int)$p['terisi'] ?> dari <?= $kq ?>"><span style="width:<?= min(100, round($p['terisi'] / $kq * 100)) ?>%"></span></div>
                    <?php endif; ?>
                    <?php if ($p['deskripsi']): ?>
                        <details><summary>Deskripsi</summary><p><?= nl2br(e($p['deskripsi'])) ?></p></details>
                    <?php endif; ?>
                    <?php if (bisa_daftar_kegiatan()): ?>
                        <div class="actions kegiatan-aksi">
                        <?php $ms = $p['my_status']; ?>
                        <?php if ($ms === 'disetujui'): ?>
                            <span class="badge badge-ok">Terdaftar</span>
                            <a class="btn btn-sm" href="<?= e(url('kegiatan/forum.php?id=' . (int)$p['id'])) ?>">Buka Forum</a>
                            <form method="post" action="<?= e(url('kegiatan/daftar.php')) ?>" data-confirm="Batalkan keikutsertaan? Akses forum kegiatan akan dicabut.">
                                <?= csrf_field() ?><input type="hidden" name="action" value="batal"><input type="hidden" name="program_id" value="<?= (int)$p['id'] ?>">
                                <button class="btn btn-light btn-sm" type="submit">Batal ikut</button></form>
                        <?php elseif ($ms === 'menunggu'): ?>
                            <span class="badge badge-warn">Menunggu persetujuan</span>
                            <form method="post" action="<?= e(url('kegiatan/daftar.php')) ?>" data-confirm="Batalkan pendaftaran?">
                                <?= csrf_field() ?><input type="hidden" name="action" value="batal"><input type="hidden" name="program_id" value="<?= (int)$p['id'] ?>">
                                <button class="btn btn-light btn-sm" type="submit">Batalkan</button></form>
                        <?php elseif ($ms === 'ditolak'): ?>
                            <span class="badge badge-danger">Pendaftaran ditolak</span>
                        <?php elseif (!kegiatan_terbuka($p['status'])): ?>
                            <span class="badge badge-muted">Pendaftaran ditutup</span>
                        <?php elseif ($penuh): ?>
                            <span class="badge badge-danger">Kuota penuh</span>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('kegiatan/daftar.php')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="action" value="daftar"><input type="hidden" name="program_id" value="<?= (int)$p['id'] ?>">
                                <button class="btn btn-sm" type="submit">Daftar</button></form>
                        <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (can_edit()): ?>
                        <div class="actions kegiatan-aksi">
                            <a class="btn btn-sm" href="<?= e(url('kegiatan/peserta.php?id=' . (int)$p['id'])) ?>">Peserta (<?= (int)$p['terisi'] ?><?= (int)$p['menunggu'] > 0 ? ' &middot; ' . (int)$p['menunggu'] . ' baru' : '' ?>)</a>
                            <a class="btn btn-light btn-sm" href="<?= e(url('kegiatan/forum.php?id=' . (int)$p['id'])) ?>">Forum</a>
                            <a class="btn btn-light btn-sm" href="<?= e(url('kegiatan/form.php?id=' . (int)$p['id'])) ?>">Edit</a>
                            <form method="post" data-confirm="Hapus program kegiatan ini? Tindakan tidak bisa dibatalkan.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Hapus</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?= pagination_html($pg) ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
