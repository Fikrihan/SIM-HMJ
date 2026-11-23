<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_editor();
    csrf_verify();
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $st = db()->prepare('SELECT nama FROM inventaris WHERE id = ?');
    $st->execute([$id]);
    $nama = $st->fetchColumn();
    if ($nama !== false) {
        db()->prepare('DELETE FROM inventaris WHERE id = ?')->execute([$id]);
        flash('success', 'Barang "' . $nama . '" dihapus dari inventaris.');
    } else {
        flash('error', 'Data barang tidak ditemukan.');
    }
    redirect('inventaris/index.php');
}

$q       = trim(isset($_GET['q']) ? $_GET['q'] : '');
$kondisi = isset($_GET['kondisi']) ? $_GET['kondisi'] : '';

$where = [];
$args  = [];
if ($q !== '') {
    $where[] = '(kode LIKE ? OR nama LIKE ? OR kategori LIKE ? OR lokasi LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
$daftarKondisi = kondisi_list();
if (isset($daftarKondisi[$kondisi])) {
    $where[] = 'kondisi = ?';
    $args[]  = $kondisi;
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = db()->prepare("SELECT COUNT(*) FROM inventaris $sqlWhere");
$cnt->execute($args);
$pg = paginate((int)$cnt->fetchColumn(), 10, isset($_GET['page']) ? $_GET['page'] : 1);

$st = db()->prepare("SELECT * FROM inventaris $sqlWhere ORDER BY nama ASC LIMIT " . (int)$pg['per'] . ' OFFSET ' . (int)$pg['offset']);
$st->execute($args);
$rows = $st->fetchAll();

$sum = db()->query(
    "SELECT COUNT(*) AS jenis, COALESCE(SUM(jumlah),0) AS unit,
            COALESCE(SUM(kondisi = 'baik'),0) AS baik,
            COALESCE(SUM(kondisi IN ('rusak ringan','rusak berat')),0) AS rusak,
            COALESCE(SUM(kondisi = 'hilang'),0) AS hilang
     FROM inventaris"
)->fetch();

$pageTitle = 'Inventaris Organisasi';
$active = 'inventaris';
require __DIR__ . '/../includes/header.php';
?>

<div data-live="inv-stats">
    <div class="stats">
        <div class="stat"><div class="num"><?= (int)$sum['jenis'] ?></div><div class="lbl">Jenis Barang</div></div>
        <div class="stat info"><div class="num"><?= (int)$sum['unit'] ?></div><div class="lbl">Total Unit</div></div>
        <div class="stat ok"><div class="num"><?= (int)$sum['baik'] ?></div><div class="lbl">Kondisi Baik</div></div>
        <div class="stat warn"><div class="num"><?= (int)$sum['rusak'] ?></div><div class="lbl">Perlu Perbaikan</div></div>
        <div class="stat"><div class="num"><?= (int)$sum['hilang'] ?></div><div class="lbl">Hilang</div></div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Daftar Inventaris (<?= (int)$pg['total'] ?>)</h2>
        <?php if (can_edit()): ?><a class="btn" href="<?= e(url('inventaris/form.php')) ?>">+ Tambah Barang</a><?php endif; ?>
    </div>

    <form class="toolbar" method="get">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari kode, nama, kategori, lokasi...">
        <select name="kondisi">
            <option value="">Semua kondisi</option>
            <?php foreach (kondisi_list() as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $kondisi === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-light" type="submit">Cari</button>
        <?php if ($q !== '' || $kondisi !== ''): ?><a class="btn btn-light" href="<?= e(url('inventaris/index.php')) ?>">Reset</a><?php endif; ?>
    </form>

    <div data-live="inv-table">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kode</th><th>Nama Barang</th><th>Kategori</th><th>Jumlah</th><th>Kondisi</th><th>Lokasi</th><?php if (can_edit()): ?><th>Aksi</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="empty">Belum ada data inventaris.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $b): ?>
                    <tr>
                        <td><?= e($b['kode']) ?></td>
                        <td><strong><?= e($b['nama']) ?></strong>
                            <?php if ($b['keterangan']): ?><div class="sub"><?= e(mb_substr($b['keterangan'], 0, 100)) ?></div><?php endif; ?></td>
                        <td><?= e($b['kategori'] ?: '-') ?></td>
                        <td><?= (int)$b['jumlah'] ?></td>
                        <td><?= badge($b['kondisi']) ?></td>
                        <td><?= e($b['lokasi'] ?: '-') ?><div class="sub"><?= $b['tanggal_perolehan'] ? 'Diperoleh ' . e(tgl($b['tanggal_perolehan'])) : '' ?></div></td>
                        <?php if (can_edit()): ?>
                        <td>
                            <div class="actions">
                                <a class="btn btn-light btn-sm" href="<?= e(url('inventaris/form.php?id=' . (int)$b['id'])) ?>">Ubah</a>
                                <form method="post" data-confirm="Hapus barang ini dari inventaris?">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
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
