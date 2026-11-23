<?php
require_once __DIR__ . '/../includes/init.php';
require_editor();

$id   = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$data = [
    'nama_proker' => '', 'divisi' => '', 'deskripsi' => '', 'lokasi' => '',
    'tanggal_mulai' => '', 'tanggal_selesai' => '', 'anggaran' => '0', 'kuota' => '',
    'penanggung_jawab' => '', 'status' => 'rencana',
];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM program_kerja WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        flash('error', 'Program kegiatan tidak ditemukan.');
        redirect('kegiatan/index.php');
    }
    $row['anggaran'] = (string)(int)round((float)$row['anggaran']);
    $data = array_merge($data, array_map(function ($v) { return $v === null ? '' : $v; }, $row));
}

$statusList = status_proker_list();
$pjList = db()->query("SELECT id, nim, nama FROM anggota WHERE status = 'aktif' ORDER BY nama")->fetchAll();
$errors = [];

function valid_date($d)
{
    $t = DateTime::createFromFormat('Y-m-d', $d);
    return $t && $t->format('Y-m-d') === $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (['nama_proker', 'divisi', 'deskripsi', 'lokasi', 'tanggal_mulai', 'tanggal_selesai', 'anggaran', 'kuota', 'penanggung_jawab', 'status'] as $f) {
        $data[$f] = trim(isset($_POST[$f]) ? (string)$_POST[$f] : '');
    }
    $data['anggaran'] = str_replace(['.', ',', ' '], '', $data['anggaran']);

    if (mb_strlen($data['nama_proker']) < 3 || mb_strlen($data['nama_proker']) > 150) {
        $errors[] = 'Nama program kegiatan harus 3-150 karakter.';
    }
    if (mb_strlen($data['divisi']) > 80) {
        $errors[] = 'Nama divisi maksimal 80 karakter.';
    }
    if (mb_strlen($data['lokasi']) > 120) {
        $errors[] = 'Lokasi maksimal 120 karakter.';
    }
    if ($data['tanggal_mulai'] !== '' && !valid_date($data['tanggal_mulai'])) {
        $errors[] = 'Tanggal mulai tidak valid.';
    }
    if ($data['tanggal_selesai'] !== '' && !valid_date($data['tanggal_selesai'])) {
        $errors[] = 'Tanggal selesai tidak valid.';
    }
    if (!$errors && $data['tanggal_mulai'] !== '' && $data['tanggal_selesai'] !== '' && $data['tanggal_selesai'] < $data['tanggal_mulai']) {
        $errors[] = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
    }
    if ($data['anggaran'] === '') {
        $data['anggaran'] = '0';
    }
    if (!ctype_digit($data['anggaran']) || strlen($data['anggaran']) > 13) {
        $errors[] = 'Anggaran harus berupa angka (rupiah, tanpa desimal).';
    }
    if (!isset($statusList[$data['status']])) {
        $errors[] = 'Status tidak valid.';
    }
    $kuota = null;
    if ($data['kuota'] !== '' && $data['kuota'] !== '0') {
        if (!ctype_digit($data['kuota']) || (int)$data['kuota'] > 100000) {
            $errors[] = 'Batas peserta harus berupa angka bulat positif (kosongkan bila tanpa batas).';
        } else {
            $kuota = (int)$data['kuota'];
            if ($id > 0 && $kuota < kegiatan_terisi($id)) {
                $errors[] = 'Batas peserta tidak boleh lebih kecil dari jumlah peserta yang sudah disetujui (' . kegiatan_terisi($id) . ').';
            }
        }
    }
    $pj = null;
    if ($data['penanggung_jawab'] !== '') {
        $pj = (int)$data['penanggung_jawab'];
        $cek = db()->prepare('SELECT id FROM anggota WHERE id = ?');
        $cek->execute([$pj]);
        if (!$cek->fetch()) {
            $errors[] = 'Penanggung jawab tidak ditemukan.';
        }
    }

    if (!$errors) {
        $vals = [
            $data['nama_proker'],
            $data['divisi'] === '' ? null : $data['divisi'],
            $data['deskripsi'] === '' ? null : $data['deskripsi'],
            $data['lokasi'] === '' ? null : $data['lokasi'],
            $data['tanggal_mulai'] === '' ? null : $data['tanggal_mulai'],
            $data['tanggal_selesai'] === '' ? null : $data['tanggal_selesai'],
            $data['anggaran'],
            $kuota,
            $pj,
            $data['status'],
        ];
        if ($id > 0) {
            $sql = 'UPDATE program_kerja SET nama_proker=?, divisi=?, deskripsi=?, lokasi=?, tanggal_mulai=?, tanggal_selesai=?, anggaran=?, kuota=?, penanggung_jawab=?, status=?, updated_at=? WHERE id=?';
            $vals[] = now();
            $vals[] = $id;
            flash('success', 'Program kegiatan berhasil diperbarui.');
        } else {
            $sql = 'INSERT INTO program_kerja (nama_proker, divisi, deskripsi, lokasi, tanggal_mulai, tanggal_selesai, anggaran, kuota, penanggung_jawab, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
            $vals[] = now();
            flash('success', 'Program kegiatan baru berhasil ditambahkan.');
        }
        db()->prepare($sql)->execute($vals);
        if ($id > 0) { // judul forum kegiatan selalu mengikuti nama kegiatan
            db()->prepare('UPDATE forum_threads SET judul = ? WHERE program_id = ?')->execute([mb_substr($data['nama_proker'], 0, 150), $id]);
        }
        redirect('kegiatan/index.php');
    }
}

$pageTitle = $id > 0 ? 'Ubah Program Kegiatan' : 'Tambah Program Kegiatan';
$active = 'kegiatan';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:820px">
    <div class="card-head"><h2><?= $id > 0 ? 'Ubah Program Kegiatan' : 'Tambah Program Kegiatan Baru' ?></h2></div>

    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="full">
                <label for="nama_proker">Nama Program Kegiatan <span class="req">*</span></label>
                <input type="text" id="nama_proker" name="nama_proker" value="<?= e($data['nama_proker']) ?>" required>
            </div>
            <div>
                <label for="divisi">Divisi Penyelenggara</label>
                <input type="text" id="divisi" name="divisi" value="<?= e($data['divisi']) ?>" list="list-divisi">
                <datalist id="list-divisi"><?php foreach (divisi_list() as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
            </div>
            <div>
                <label for="penanggung_jawab">Penanggung Jawab</label>
                <select id="penanggung_jawab" name="penanggung_jawab">
                    <option value="">- Belum ditentukan -</option>
                    <?php foreach ($pjList as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= (string)$data['penanggung_jawab'] === (string)$a['id'] ? 'selected' : '' ?>>
                            <?= e($a['nama']) ?> (<?= e($a['nim']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="tanggal_mulai">Tanggal Mulai</label>
                <input type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= e($data['tanggal_mulai']) ?>">
            </div>
            <div>
                <label for="tanggal_selesai">Tanggal Selesai</label>
                <input type="date" id="tanggal_selesai" name="tanggal_selesai" value="<?= e($data['tanggal_selesai']) ?>">
            </div>
            <div>
                <label for="anggaran">Anggaran (Rp)</label>
                <input type="text" id="anggaran" name="anggaran" value="<?= e($data['anggaran']) ?>" inputmode="numeric">
            </div>
            <div>
                <label for="kuota">Batas Jumlah Peserta</label>
                <input type="text" id="kuota" name="kuota" value="<?= e($data['kuota']) ?>" inputmode="numeric" placeholder="Kosongkan = tanpa batas">
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach ($statusList as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $data['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label for="lokasi">Lokasi</label>
                <input type="text" id="lokasi" name="lokasi" value="<?= e($data['lokasi']) ?>">
            </div>
            <div class="full">
                <label for="deskripsi">Deskripsi / Tujuan</label>
                <textarea id="deskripsi" name="deskripsi"><?= e($data['deskripsi']) ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Simpan</button>
            <a class="btn btn-light" href="<?= e(url('kegiatan/index.php')) ?>">Batal</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
