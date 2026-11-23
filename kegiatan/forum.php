<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

/* Pintu masuk forum kegiatan: hanya Admin, Pengurus, dan peserta yang sudah disetujui. */
$me  = current_user();
$pid = (int)(isset($_GET['id']) ? $_GET['id'] : 0);

$st = db()->prepare('SELECT id, nama_proker FROM program_kerja WHERE id = ?');
$st->execute([$pid]);
$prog = $st->fetch();
if (!$prog) {
    flash('error', 'Program kegiatan tidak ditemukan.');
    redirect('kegiatan/index.php');
}

if (!forum_boleh_akses(['program_id' => $pid])) {
    http_response_code(403);
    flash('error', 'Forum "' . $prog['nama_proker'] . '" hanya untuk peserta yang pendaftarannya sudah disetujui.');
    redirect('kegiatan/index.php');
}

$tid = forum_kegiatan_id($pid, can_edit() ? (int)$me['id'] : null);
if ($tid < 1) {
    flash('error', 'Forum kegiatan belum bisa dibuka. Pastikan database_update.sql sudah di-import.');
    redirect('kegiatan/index.php');
}
redirect('forum/thread.php?id=' . $tid);
