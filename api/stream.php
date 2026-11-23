<?php
/**
 * Real-time lewat Server-Sent Events (SSE).
 * Koneksi dibiarkan terbuka beberapa detik; begitu ada data yang berubah di database
 * (lihat live_bump_query di config/database.php) browser langsung diberi tahu (< 1 detik)
 * lalu menyegarkan bagian halaman yang berubah. Setelah habis masa hidupnya, browser
 * otomatis tersambung lagi (sekaligus memeriksa ulang login).
 *
 * Ditandai sebagai permintaan latar belakang (X-Live) supaya TIDAK dianggap aktivitas pengguna
 * dan kunci session langsung dilepas (init.php) agar tidak menghambat halaman lain.
 */
$_SERVER['HTTP_X_LIVE'] = '1';
require_once __DIR__ . '/../includes/init.php';

$u = current_user();
if (!$u) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['auth' => false, 'reason' => auth_reason()]);
    exit;
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_flush(); }
set_time_limit(0);
ignore_user_abort(false);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

$last = live_version();
$nid  = latest_notif_id();   // notifikasi terakhir yang sudah diketahui browser
echo "retry: 500\n";
echo "event: hello\ndata: " . json_encode(['v' => $last, 'nid' => $nid]) . "\n\n";
echo str_repeat(' ', 2048) . "\n\n"; // dorong buffer proxy/antivirus
flush();

$umur  = 25;                  // detik; lalu browser menyambung ulang
$mulai = time();
$beat  = time();
while (time() - $mulai < $umur) {
    if (connection_aborted()) { break; }
    $now = live_version();
    if ($now !== $last) {
        $last = $now;
        // Notifikasi baru untuk pengguna ini dikirim LANGSUNG di event (tanpa permintaan tambahan),
        // supaya pop-up muncul seketika.
        $baru = notif_baru($u['id'], $nid);
        if ($baru) {
            $nid = $baru[count($baru) - 1]['id'];
        }
        echo "event: change\ndata: " . json_encode(['v' => $now, 'notif' => $baru, 'unread' => unread_count()]) . "\n\n";
        flush();
        $beat = time();
    } elseif (time() - $beat >= 10) {   // detak jantung: juga cara mendeteksi koneksi yang sudah putus
        echo ": ping\n\n";
        flush();
        $beat = time();
    }
    usleep(250000);           // cek 4x per detik (hanya membaca 1 file kecil, tanpa query database)
}
echo "event: bye\ndata: 1\n\n";
flush();
