<?php
require_once __DIR__ . '/../includes/init.php';
require_editor();

$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$kondisiList = kondisi_list();

$next = (int)db()->query('SELECT COALESCE(MAX(id),0) + 1 FROM inventaris')->fetchColumn();
$data = ['kode' => 'INV-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT), 'nama' => '', 'kategori' => '', 'jumlah' => '1',
         'kondisi' => 'baik', 'lokasi' => '', 'tanggal_perolehan' => '', 'keterangan' => ''];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM inventaris WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        flash('error', 'Data barang tidak ditemukan.');
        redirect('inventaris/index.php');
    }
    foreach ($data as $k => $v) {
        $data[$k] = $row[$k] === null ? '' : (string)$row[$k];
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($data as $k => $v) {
        $data[$k] = trim(isset($_POST[$k]) ? (string)$_POST[$k] : '');
    }

    if (!preg_match('/^[A-Za-z0-9._\/-]{2,30}$/', $data['kode'])) {
        $errors[] = 'Kode barang 2-30 karakter (huruf, angka, titik, strip, garis miring).';
    }
    if (mb_strlen($data['nama']) < 2 || mb_strlen($data['nama']) > 120) {
        $errors[] = 'Nama barang harus 2-120 karakter.';
    }
    if (!ctype_digit($data['jumlah']) || (int)$data['jumlah'] > 1000000) {
        $errors[] = 'Jumlah harus berupa angka bulat.';
    }
    if (!isset($kondisiList[$data['kondisi']])) {
        $errors[] = 'Kondisi tidak valid.';
    }
    if ($data['tanggal_perolehan'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['tanggal_perolehan'])) {
        $errors[] = 'Format tanggal perolehan tidak valid.';
    }
    if (!$errors) {
        $cek = db()->prepare('SELECT id FROM inventaris WHERE kode = ? AND id <> ?');
        $cek->execute([$data['kode'], $id]);
        if ($cek->fetch()) {
            $errors[] = 'Kode barang sudah dipakai barang lain.';
        }
    }

    if (!$errors) {
        $vals = [
            $data['kode'], $data['nama'],
            $data['kategori'] === '' ? null : $data['kategori'],
            (int)$data['jumlah'], $data['kondisi'],
            $data['lokasi'] === '' ? null : $data['lokasi'],
            $data['tanggal_perolehan'] === '' ? null : $data['tanggal_perolehan'],
            $data['keterangan'] === '' ? null : $data['keterangan'],
        ];
        if ($id > 0) {
            $vals[] = now();
            $vals[] = $id;
            db()->prepare('UPDATE inventaris SET kode=?, nama=?, kategori=?, jumlah=?, kondisi=?, lokasi=?, tanggal_perolehan=?, keterangan=?, updated_at=? WHERE id=?')->execute($vals);
            flash('success', 'Data barang diperbarui.');
        } else {
            $vals[] = now();
            db()->prepare('INSERT INTO inventaris (kode, nama, kategori, jumlah, kondisi, lokasi, tanggal_perolehan, keterangan, created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute($vals);
            flash('success', 'Barang baru ditambahkan ke inventaris.');
        }
        redirect('inventaris/index.php');
    }
}

$pageTitle = $id > 0 ? 'Ubah Barang' : 'Tambah Barang';
$active = 'inventaris';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:820px">
    <div class="card-head"><h2><?= $id > 0 ? 'Ubah Data Barang' : 'Tambah Barang Inventaris' ?></h2></div>

    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div>
                <label for="kode">Kode Barang <span class="req">*</span></label>
                <input type="text" id="kode" name="kode" value="<?= e($data['kode']) ?>" required>
            </div>
            <div>
                <label for="nama">Nama Barang <span class="req">*</span></label>
                <input type="text" id="nama" name="nama" value="<?= e($data['nama']) ?>" required>
            </div>
            <div>
                <label for="kategori">Kategori</label>
                <input type="text" id="kategori" name="kategori" value="<?= e($data['kategori']) ?>" list="list-kat">
                <datalist id="list-kat"><option value="Elektronik"><option value="Perlengkapan Kantor"><option value="Perabot"><option value="Dokumentasi"><option value="Perlengkapan Acara"></datalist>
            </div>
            <div>
                <label for="jumlah">Jumlah <span class="req">*</span></label>
                <input type="number" id="jumlah" name="jumlah" min="0" value="<?= e($data['jumlah']) ?>" required>
            </div>
            <div>
                <label for="kondisi">Kondisi</label>
                <select id="kondisi" name="kondisi">
                    <?php foreach ($kondisiList as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $data['kondisi'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="lokasi">Lokasi Penyimpanan</label>
                <input type="text" id="lokasi" name="lokasi" value="<?= e($data['lokasi']) ?>">
            </div>
            <div>
                <label for="tanggal_perolehan">Tanggal Perolehan</label>
                <input type="date" id="tanggal_perolehan" name="tanggal_perolehan" value="<?= e($data['tanggal_perolehan']) ?>">
            </div>
            <div class="full">
                <label for="keterangan">Keterangan</label>
                <textarea id="keterangan" name="keterangan" rows="3"><?= e($data['keterangan']) ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Simpan</button>
            <a class="btn btn-light" href="<?= e(url('inventaris/index.php')) ?>">Batal</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
