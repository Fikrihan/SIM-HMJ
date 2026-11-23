<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

/* Anggota & Mahasiswa mendaftar / membatalkan pendaftaran program kegiatan (POST). */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('kegiatan/index.php');
}
csrf_verify();

$me     = current_user();
$action = isset($_POST['action']) ? $_POST['action'] : '';
$pid    = (int)(isset($_POST['program_id']) ? $_POST['program_id'] : 0);

if (!bisa_daftar_kegiatan()) {
    http_response_code(403);
    flash('error', 'Pendaftaran kegiatan hanya untuk akun Anggota dan Mahasiswa.');
    redirect('kegiatan/index.php');
}

$st = db()->prepare('SELECT id, nama_proker, status, kuota FROM program_kerja WHERE id = ?');
$st->execute([$pid]);
$prog = $st->fetch();
if (!$prog) {
    flash('error', 'Program kegiatan tidak ditemukan.');
    redirect('kegiatan/index.php');
}

$ex = db()->prepare('SELECT id, status FROM pendaftaran_kegiatan WHERE program_id = ? AND user_id = ?');
$ex->execute([$pid, (int)$me['id']]);
$reg = $ex->fetch();

if ($action === 'daftar') {
    if (!kegiatan_terbuka($prog['status'])) {
        flash('error', 'Pendaftaran untuk kegiatan ini sudah ditutup.');
    } elseif ($reg && in_array($reg['status'], ['menunggu', 'disetujui'], true)) {
        flash('info', 'Anda sudah terdaftar pada kegiatan ini.');
    } elseif ($reg && $reg['status'] === 'ditolak') {
        flash('error', 'Pendaftaran Anda sebelumnya ditolak. Hubungi Admin/Pengurus bila ada kekeliruan.');
    } elseif (kuota_penuh($prog['kuota'], kegiatan_terisi($pid))) {
        flash('error', 'Kuota peserta kegiatan ini sudah penuh.');
    } else {
        if ($reg) { // sebelumnya membatalkan sendiri -> daftar lagi
            db()->prepare("UPDATE pendaftaran_kegiatan SET status = 'menunggu', catatan = NULL, diproses_oleh = NULL, diproses_at = NULL, created_at = ? WHERE id = ?")
                ->execute([now(), (int)$reg['id']]);
        } else {
            db()->prepare("INSERT INTO pendaftaran_kegiatan (program_id, user_id, status, created_at) VALUES (?,?, 'menunggu', ?)")
                ->execute([$pid, (int)$me['id'], now()]);
        }
        notify_roles(['admin', 'pengurus'], 'Pendaftar baru: ' . $me['nama'],
            'Mendaftar ke kegiatan "' . $prog['nama_proker'] . '". Menunggu persetujuan.',
            'kegiatan/peserta.php?id=' . $pid, (int)$me['id']);
        flash('success', 'Pendaftaran terkirim. Anda akan masuk forum kegiatan setelah disetujui Admin/Pengurus.');
    }
} elseif ($action === 'batal') {
    if ($reg && in_array($reg['status'], ['menunggu', 'disetujui'], true)) {
        db()->prepare("UPDATE pendaftaran_kegiatan SET status = 'batal', diproses_at = ? WHERE id = ?")
            ->execute([now(), (int)$reg['id']]);
        flash('success', 'Pendaftaran dibatalkan.' . ($reg['status'] === 'disetujui' ? ' Akses forum kegiatan ikut dicabut.' : ''));
    } else {
        flash('error', 'Tidak ada pendaftaran aktif yang bisa dibatalkan.');
    }
}

redirect('kegiatan/index.php');
