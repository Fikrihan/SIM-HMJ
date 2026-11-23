<?php
require_once __DIR__ . '/../includes/init.php';
require_editor();

$me = current_user();
$pid = (int)(isset($_GET['id']) ? $_GET['id'] : (isset($_POST['program_id']) ? $_POST['program_id'] : 0));

$st = db()->prepare('SELECT id, nama_proker, status, kuota, tanggal_mulai, tanggal_selesai FROM program_kerja WHERE id = ?');
$st->execute([$pid]);
$prog = $st->fetch();
if (!$prog) {
    flash('error', 'Program kegiatan tidak ditemukan.');
    redirect('kegiatan/index.php');
}
$self = 'kegiatan/peserta.php?id=' . $pid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $rid    = (int)(isset($_POST['reg_id']) ? $_POST['reg_id'] : 0);
    $pdo    = db();

    /* --- Atur batas peserta --- */
    if ($action === 'kuota') {
        $raw = trim(isset($_POST['kuota']) ? (string)$_POST['kuota'] : '');
        $kuota = null;
        if ($raw !== '' && $raw !== '0') {
            if (!ctype_digit($raw) || (int)$raw > 100000) {
                flash('error', 'Batas peserta harus berupa angka bulat positif (kosongkan bila tanpa batas).');
                redirect($self);
            }
            $kuota = (int)$raw;
        }
        $terisi = kegiatan_terisi($pid);
        if ($kuota !== null && $kuota < $terisi) {
            flash('error', 'Batas peserta tidak boleh lebih kecil dari jumlah peserta yang sudah disetujui (' . $terisi . ').');
            redirect($self);
        }
        $pdo->prepare('UPDATE program_kerja SET kuota = ?, updated_at = ? WHERE id = ?')->execute([$kuota, now(), $pid]);
        flash('success', $kuota === null ? 'Batas peserta dihapus (tanpa batas).' : 'Batas peserta diatur menjadi ' . $kuota . ' orang.');
        redirect($self);
    }

    /* --- Proses satu pendaftaran --- */
    $rs = $pdo->prepare('SELECT d.*, u.nama FROM pendaftaran_kegiatan d JOIN users u ON u.id = d.user_id WHERE d.id = ? AND d.program_id = ?');
    $rs->execute([$rid, $pid]);
    $reg = $rs->fetch();
    if (!$reg) {
        flash('error', 'Data pendaftaran tidak ditemukan.');
        redirect($self);
    }
    $catatan = trim(isset($_POST['catatan']) ? (string)$_POST['catatan'] : '');
    $catatan = $catatan === '' ? null : mb_substr($catatan, 0, 255);

    if ($action === 'setujui') {
        if ($reg['status'] === 'disetujui') {
            redirect($self);
        }
        if ($reg['status'] === 'batal') {
            flash('error', 'Pendaftar ini sudah membatalkan sendiri pendaftarannya.');
            redirect($self);
        }
        // Cek kuota & setujui dalam satu transaksi agar tidak melampaui batas saat ada persetujuan bersamaan
        $pdo->beginTransaction();
        try {
            $k = $pdo->prepare('SELECT kuota FROM program_kerja WHERE id = ? FOR UPDATE');
            $k->execute([$pid]);
            $kuota = $k->fetchColumn();
            if (kuota_penuh($kuota === false ? null : $kuota, kegiatan_terisi($pid))) {
                $pdo->rollBack();
                flash('error', 'Kuota sudah penuh. Naikkan batas peserta terlebih dahulu bila ingin menyetujui pendaftar ini.');
                redirect($self);
            }
            $pdo->prepare("UPDATE pendaftaran_kegiatan SET status = 'disetujui', catatan = ?, diproses_oleh = ?, diproses_at = ? WHERE id = ?")
                ->execute([$catatan, (int)$me['id'], now(), $rid]);
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('error', 'Gagal memproses pendaftaran.');
            redirect($self);
        }
        // Utas forum kegiatan dibuat saat persetujuan pertama; judulnya mengikuti nama kegiatan
        forum_kegiatan_id($pid, (int)$me['id']);
        notify((int)$reg['user_id'], 'Pendaftaran disetujui: ' . $prog['nama_proker'],
            'Anda kini terdaftar dan bisa mengakses forum diskusi kegiatan ini.', 'kegiatan/forum.php?id=' . $pid);
        flash('success', $reg['nama'] . ' disetujui dan kini bisa mengakses forum kegiatan.');
    } elseif ($action === 'tolak' || $action === 'keluarkan') {
        if ($reg['status'] === 'ditolak' || $reg['status'] === 'batal') {
            redirect($self);
        }
        $pdo->prepare("UPDATE pendaftaran_kegiatan SET status = 'ditolak', catatan = ?, diproses_oleh = ?, diproses_at = ? WHERE id = ?")
            ->execute([$catatan, (int)$me['id'], now(), $rid]);
        $keluar = $reg['status'] === 'disetujui';
        notify((int)$reg['user_id'],
            ($keluar ? 'Keikutsertaan dicabut: ' : 'Pendaftaran ditolak: ') . $prog['nama_proker'],
            ($catatan !== null ? 'Catatan: ' . $catatan : ($keluar ? 'Akses forum kegiatan dicabut.' : 'Silakan hubungi Admin/Pengurus untuk info lebih lanjut.')),
            'kegiatan/index.php');
        flash('success', $keluar ? $reg['nama'] . ' dikeluarkan dari peserta (akses forum dicabut).' : 'Pendaftaran ' . $reg['nama'] . ' ditolak.');
    }
    redirect($self);
}

$rows = db()->prepare(
    "SELECT d.*, u.nim, u.nama, u.role, u.avatar, p.nama AS pemroses
     FROM pendaftaran_kegiatan d
     JOIN users u ON u.id = d.user_id
     LEFT JOIN users p ON p.id = d.diproses_oleh
     WHERE d.program_id = ?
     ORDER BY FIELD(d.status, 'menunggu', 'disetujui', 'ditolak', 'batal'), d.created_at ASC"
);
$rows->execute([$pid]);
$rows = $rows->fetchAll();

$terisi = 0; $menunggu = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'disetujui') { $terisi++; }
    if ($r['status'] === 'menunggu') { $menunggu++; }
}
$kuota = $prog['kuota'] !== null && (int)$prog['kuota'] > 0 ? (int)$prog['kuota'] : null;
$penuh = kuota_penuh($kuota, $terisi);

$pageTitle = 'Peserta Kegiatan';
$active = 'kegiatan';
require __DIR__ . '/../includes/header.php';
?>

<p class="fx-back"><a href="<?= e(url('kegiatan/index.php')) ?>">&larr; Kembali ke Program Kegiatan</a></p>

<div class="page-head">
    <div>
        <h2><?= e($prog['nama_proker']) ?></h2>
        <p class="muted"><?= badge($prog['status']) ?> &middot; <?= e(tgl($prog['tanggal_mulai'])) ?> &middot;
            <strong><?= e(kuota_label($kuota, $terisi)) ?></strong><?= $penuh ? ' &middot; <span class="badge badge-danger">Kuota penuh</span>' : '' ?>
            <?= kegiatan_terbuka($prog['status']) ? '' : ' &middot; <span class="badge badge-muted">Pendaftaran ditutup</span>' ?></p>
    </div>
    <a class="btn" href="<?= e(url('kegiatan/forum.php?id=' . $pid)) ?>">Buka Forum Kegiatan</a>
</div>

<div class="card" style="margin-bottom:16px">
    <div class="card-head"><h2>Batas Jumlah Peserta</h2><span class="sub">Kosongkan atau isi 0 untuk tanpa batas.</span></div>
    <form method="post" class="toolbar" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="kuota">
        <input type="hidden" name="program_id" value="<?= (int)$pid ?>">
        <input type="text" name="kuota" value="<?= $kuota === null ? '' : (int)$kuota ?>" inputmode="numeric" placeholder="Tanpa batas" style="max-width:200px">
        <button class="btn" type="submit">Simpan Batas</button>
        <span class="muted small">Disetujui saat ini: <?= (int)$terisi ?> orang.</span>
    </form>
</div>

<div class="card">
    <div class="card-head"><h2>Daftar Pendaftar (<?= count($rows) ?>)</h2>
        <span class="sub"><?= (int)$menunggu ?> menunggu persetujuan</span></div>
    <div data-live="peserta-list">
    <div class="table-wrap">
        <table>
            <thead><tr><th>NIM</th><th>Nama</th><th>Akun</th><th>Mendaftar</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="empty">Belum ada yang mendaftar.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['nim']) ?></td>
                    <td><div class="who"><?= avatar_html($r['nama'], $r['avatar'], 'sm') ?><strong><?= e($r['nama']) ?></strong></div></td>
                    <td><span class="badge badge-info"><?= e(ucfirst($r['role'])) ?></span></td>
                    <td><?= e(tgl_waktu($r['created_at'])) ?></td>
                    <td><?= badge($r['status']) ?>
                        <?php if ($r['pemroses'] && in_array($r['status'], ['disetujui', 'ditolak'], true)): ?><div class="sub">oleh <?= e($r['pemroses']) ?></div><?php endif; ?>
                        <?php if ($r['catatan']): ?><div class="sub"><?= e($r['catatan']) ?></div><?php endif; ?></td>
                    <td>
                        <div class="actions">
                        <?php if ($r['status'] === 'menunggu' || $r['status'] === 'ditolak'): ?>
                            <form method="post"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="setujui"><input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="program_id" value="<?= (int)$pid ?>">
                                <button class="btn btn-sm" type="submit" <?= $penuh ? 'disabled title="Kuota penuh"' : '' ?>>Setujui</button></form>
                        <?php endif; ?>
                        <?php if ($r['status'] === 'menunggu'): ?>
                            <form method="post" data-confirm="Tolak pendaftaran ini?"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="tolak"><input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="program_id" value="<?= (int)$pid ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Tolak</button></form>
                        <?php elseif ($r['status'] === 'disetujui'): ?>
                            <form method="post" data-confirm="Keluarkan peserta ini? Akses forum kegiatan akan dicabut."><?= csrf_field() ?>
                                <input type="hidden" name="action" value="keluarkan"><input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="program_id" value="<?= (int)$pid ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Keluarkan</button></form>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
