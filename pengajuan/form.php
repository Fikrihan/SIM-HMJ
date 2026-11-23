<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$me = current_user();
$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);

$data = ['judul' => '', 'divisi' => '', 'deskripsi' => '', 'lokasi' => '', 'tanggal_mulai' => '', 'tanggal_selesai' => '', 'anggaran' => '0'];

if ($id > 0) {
    $st = db()->prepare("SELECT * FROM pengajuan_kegiatan WHERE id = ? AND pengaju_id = ? AND status = 'menunggu'");
    $st->execute([$id, (int)$me['id']]);
    $row = $st->fetch();
    if (!$row) {
        flash('error', 'Pengajuan tidak ditemukan atau sudah diverifikasi sehingga tidak dapat diubah.');
        redirect('pengajuan/index.php');
    }
    foreach ($data as $k => $v) {
        $data[$k] = $row[$k] === null ? '' : (string)$row[$k];
    }
    $data['anggaran'] = (string)(int)$row['anggaran'];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($data as $k => $v) {
        $data[$k] = trim(isset($_POST[$k]) ? (string)$_POST[$k] : '');
    }
    $data['anggaran'] = preg_replace('/[^0-9]/', '', $data['anggaran']);
    if ($data['anggaran'] === '') {
        $data['anggaran'] = '0';
    }

    if (mb_strlen($data['judul']) < 3 || mb_strlen($data['judul']) > 150) {
        $errors[] = 'Judul kegiatan harus 3-150 karakter.';
    }
    foreach (['tanggal_mulai', 'tanggal_selesai'] as $f) {
        if ($data[$f] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[$f])) {
            $errors[] = 'Format tanggal tidak valid.';
            break;
        }
    }
    if ($data['tanggal_mulai'] !== '' && $data['tanggal_selesai'] !== '' && $data['tanggal_selesai'] < $data['tanggal_mulai']) {
        $errors[] = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
    }
    if (strlen($data['anggaran']) > 13) {
        $errors[] = 'Anggaran terlalu besar.';
    }

    if (!$errors) {
        $vals = [
            $data['judul'],
            $data['divisi'] === '' ? null : $data['divisi'],
            $data['deskripsi'] === '' ? null : $data['deskripsi'],
            $data['lokasi'] === '' ? null : $data['lokasi'],
            $data['tanggal_mulai'] === '' ? null : $data['tanggal_mulai'],
            $data['tanggal_selesai'] === '' ? null : $data['tanggal_selesai'],
            $data['anggaran'],
        ];
        if ($id > 0) {
            $vals[] = now();
            $vals[] = $id;
            db()->prepare('UPDATE pengajuan_kegiatan SET judul=?, divisi=?, deskripsi=?, lokasi=?, tanggal_mulai=?, tanggal_selesai=?, anggaran=?, updated_at=? WHERE id=?')->execute($vals);
            flash('success', 'Pengajuan berhasil diperbarui.');
        } else {
            $vals[] = (int)$me['id'];
            $vals[] = now();
            db()->prepare('INSERT INTO pengajuan_kegiatan (judul, divisi, deskripsi, lokasi, tanggal_mulai, tanggal_selesai, anggaran, pengaju_id, created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute($vals);
            notify_roles(['admin', 'pengurus'], 'Pengajuan kegiatan baru', '"' . $data['judul'] . '" diajukan oleh ' . $me['nama'], 'pengajuan/index.php', (int)$me['id']);
            flash('success', 'Pengajuan terkirim. Admin/Pengurus akan memverifikasinya.');
        }
        redirect('pengajuan/index.php');
    }
}

$pageTitle = $id > 0 ? 'Ubah Pengajuan' : 'Ajukan Kegiatan';
$active = 'pengajuan';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:820px">
    <div class="card-head"><h2><?= $id > 0 ? 'Ubah Pengajuan Kegiatan' : 'Ajukan Kegiatan Baru' ?></h2></div>

    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="full">
                <label for="judul">Nama Kegiatan <span class="req">*</span></label>
                <input type="text" id="judul" name="judul" value="<?= e($data['judul']) ?>" required>
            </div>
            <div>
                <label for="divisi">Divisi Penyelenggara</label>
                <input type="text" id="divisi" name="divisi" value="<?= e($data['divisi']) ?>" list="list-divisi">
                <datalist id="list-divisi"><?php foreach (divisi_list() as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
            </div>
            <div>
                <label for="lokasi">Lokasi</label>
                <input type="text" id="lokasi" name="lokasi" value="<?= e($data['lokasi']) ?>">
            </div>
            <div>
                <label for="tanggal_mulai">Tanggal Mulai</label>
                <input type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= e($data['tanggal_mulai']) ?>">
            </div>
            <div>
                <label for="tanggal_selesai">Tanggal Selesai</label>
                <input type="date" id="tanggal_selesai" name="tanggal_selesai" value="<?= e($data['tanggal_selesai']) ?>">
            </div>
            <div class="full">
                <label for="anggaran">Rencana Anggaran (Rp)</label>
                <input type="text" id="anggaran" name="anggaran" value="<?= e($data['anggaran']) ?>" inputmode="numeric">
            </div>
            <div class="full">
                <label for="deskripsi">Deskripsi / Tujuan Kegiatan</label>
                <textarea id="deskripsi" name="deskripsi" rows="5"><?= e($data['deskripsi']) ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit"><?= $id > 0 ? 'Simpan Perubahan' : 'Kirim Pengajuan' ?></button>
            <a class="btn btn-light" href="<?= e(url('pengajuan/index.php')) ?>">Batal</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
