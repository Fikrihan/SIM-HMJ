<?php
/**
 * Endpoint polling ringan: jumlah notifikasi & antrean, serta notifikasi baru untuk toast.
 * Dipanggil oleh assets/js/live.js setiap beberapa detik.
 */
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$u = current_user();
if (!$u) {
    http_response_code(401);
    echo json_encode(['auth' => false, 'reason' => auth_reason()]);
    exit;
}

$pdo   = db();
$after = isset($_GET['after']) ? (int)$_GET['after'] : -1;

$latest = latest_notif_id();
$new = ($after >= 0 && $latest > $after) ? notif_baru($u['id'], $after) : [];

echo json_encode([
    'auth'       => true,
    'latest'     => $latest,
    'notifikasi' => unread_count(),
    'persetujuan' => is_admin() ? pending_count() : 0,
    'pengajuan'  => can_edit() ? pengajuan_pending_count() : 0,
    'kegiatan'   => can_edit() ? pendaftaran_pending_count() : 0,
    'new'        => $new,
    'sisa'       => auth_meta('sisa'),
]);
