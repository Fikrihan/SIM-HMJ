<?php
/**
 * Fungsi bantu: session, CSRF, flash message, autentikasi, format, paginasi.
 */

date_default_timezone_set('Asia/Jayapura');

if (session_status() === PHP_SESSION_NONE) {
    session_name('hmjsi_session');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}

/* ---------- Dasar ---------- */

function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function url($path = '')
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect($path)
{
    header('Location: ' . url($path));
    exit;
}

function now()
{
    return date('Y-m-d H:i:s');
}

/* ---------- Flash message ---------- */

function flash($type, $msg)
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function get_flash()
{
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF ---------- */

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify()
{
    $t = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    $s = isset($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
    if (!is_string($t) || $s === '' || !hash_equals($s, $t)) {
        http_response_code(419);
        die('Sesi formulir tidak valid atau sudah kedaluwarsa. Silakan kembali dan muat ulang halaman.');
    }
}

/* ---------- Autentikasi ---------- */

/** Alasan sesi tidak berlaku: '', 'idle', 'dicabut' (dikeluarkan Admin), 'nonaktif'. */
function auth_reason($set = null)
{
    static $r = '';
    if ($set !== null) {
        $r = $set;
    }
    return $r;
}

/** Info tambahan sesi (mis. 'sisa' = detik sebelum logout otomatis). */
function auth_meta($key, $val = null)
{
    static $m = [];
    if ($val !== null) {
        $m[$key] = $val;
        return null;
    }
    return isset($m[$key]) ? $m[$key] : null;
}

function waktu_db($ts = null)
{
    return date('Y-m-d H:i:s', $ts === null ? time() : $ts);
}

/** Buat catatan sesi login baru (dipanggil saat login berhasil). */
function buat_sesi($userId, $token)
{
    $pdo = db();
    // bersihkan sesi lama yang sudah tidak terpakai
    $pdo->prepare('DELETE FROM sesi_login WHERE last_activity < ?')->execute([waktu_db(time() - 86400)]);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 250) : null;
    $ip = isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null;
    $n = now();
    $pdo->prepare('INSERT INTO sesi_login (user_id, token, ip, perangkat, login_at, last_seen, last_activity) VALUES (?,?,?,?,?,?,?)')
        ->execute([(int)$userId, hash('sha256', $token), $ip, $ua, $n, $n, $n]);
}

/** Keluarkan paksa semua sesi milik satu pengguna. Mengembalikan jumlah sesi yang dicabut. */
function cabut_sesi($userId)
{
    $st = db()->prepare('DELETE FROM sesi_login WHERE user_id = ?');
    $st->execute([(int)$userId]);
    return $st->rowCount();
}

function hapus_sesi_ini()
{
    if (!empty($_SESSION['stoken'])) {
        db()->prepare('DELETE FROM sesi_login WHERE token = ?')->execute([hash('sha256', (string)$_SESSION['stoken'])]);
    }
}

function online_cutoff()
{
    return waktu_db(time() - IDLE_TIMEOUT);
}

/** Jumlah pengguna yang sedang login (masih dalam batas waktu diam). */
function online_count()
{
    $st = db()->prepare("SELECT COUNT(DISTINCT s.user_id) FROM sesi_login s JOIN users u ON u.id = s.user_id
                         WHERE u.status = 'aktif' AND s.last_activity >= ?");
    $st->execute([online_cutoff()]);
    return (int)$st->fetchColumn();
}

function current_user()
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    if (empty($_SESSION['uid']) || empty($_SESSION['stoken'])) {
        unset($_SESSION['uid'], $_SESSION['stoken']);
        return $cache;
    }

    $pdo = db();
    $hash = hash('sha256', (string)$_SESSION['stoken']);
    $st = $pdo->prepare(
        'SELECT s.id AS sid, s.last_activity, u.id, u.nim, u.nama, u.role, u.status, u.avatar
         FROM sesi_login s JOIN users u ON u.id = s.user_id
         WHERE s.token = ? AND s.user_id = ? LIMIT 1'
    );
    $st->execute([$hash, (int)$_SESSION['uid']]);
    $row = $st->fetch();

    if (!$row) {
        // sesi dihapus (Admin memaksa logout) atau akun sudah tidak ada
        auth_reason('dicabut');
        unset($_SESSION['uid'], $_SESSION['stoken']);
        return $cache;
    }
    if ($row['status'] !== 'aktif') {
        auth_reason('nonaktif');
        unset($_SESSION['uid'], $_SESSION['stoken']);
        return $cache;
    }

    $now  = time();
    $idle = $now - strtotime($row['last_activity']);
    if ($idle > IDLE_TIMEOUT) {
        auth_reason('idle');
        if (!is_live_request()) {
            $pdo->prepare('DELETE FROM sesi_login WHERE id = ?')->execute([(int)$row['sid']]);
        }
        unset($_SESSION['uid'], $_SESSION['stoken']);
        return $cache;
    }

    // Polling latar belakang (X-Live) bukan aktivitas pengguna, kecuali browser
    // menandainya dengan X-Act (pengguna menggerakkan mouse / mengetik / menyentuh layar).
    $aktivitas = !is_live_request() || isset($_SERVER['HTTP_X_ACT']);
    if ($aktivitas) {
        $pdo->prepare('UPDATE sesi_login SET last_seen = ?, last_activity = ? WHERE id = ?')
            ->execute([waktu_db($now), waktu_db($now), (int)$row['sid']]);
        auth_meta('sisa', IDLE_TIMEOUT);
    } else {
        $pdo->prepare('UPDATE sesi_login SET last_seen = ? WHERE id = ?')
            ->execute([waktu_db($now), (int)$row['sid']]);
        auth_meta('sisa', max(0, IDLE_TIMEOUT - $idle));
    }

    unset($row['sid'], $row['last_activity']);
    $cache = $row;
    return $cache;
}

function require_login()
{
    if (!current_user()) {
        $r = auth_reason();
        redirect('login.php' . ($r !== '' ? '?msg=' . $r : ''));
    }
}

function is_admin()
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

/** Admin & pengurus boleh menambah/ubah/hapus data. Role "anggota" dan "mahasiswa" hanya melihat. */
function can_edit()
{
    $u = current_user();
    return $u && in_array($u['role'], ['admin', 'pengurus'], true);
}

function require_admin()
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        flash('error', 'Halaman tersebut hanya untuk Admin.');
        redirect('dashboard.php');
    }
}

function require_editor()
{
    require_login();
    if (!can_edit()) {
        http_response_code(403);
        flash('error', 'Akun Anda hanya memiliki akses lihat (read-only).');
        redirect('dashboard.php');
    }
}

/** Hak akses yang boleh diberikan pembuat akun: Pengurus tidak boleh membuat Admin. */
function role_boleh_dibuat()
{
    if (is_admin()) {
        return ['mahasiswa', 'anggota', 'pengurus', 'admin'];
    }
    return ['mahasiswa', 'anggota', 'pengurus'];
}

function pending_count()
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
}

function role_label($r)
{
    $map = ['admin' => 'Admin', 'pengurus' => 'Pengurus', 'anggota' => 'Anggota (lihat saja)', 'mahasiswa' => 'Mahasiswa (lihat saja)'];
    return isset($map[$r]) ? $map[$r] : $r;
}

/** Semua hak akses yang tersedia (kode => label). */
function role_list()
{
    return [
        'admin'     => 'Admin',
        'pengurus'  => 'Pengurus (kelola data)',
        'anggota'   => 'Anggota (lihat saja)',
        'mahasiswa' => 'Mahasiswa (lihat saja)',
    ];
}

/** Hanya Admin & Pengurus yang boleh melihat anggaran program kegiatan. Anggota & Mahasiswa tidak. */
function can_lihat_anggaran()
{
    return can_edit();
}

/** Mahasiswa umum bukan bagian pengurus HMJ, jadi tidak otomatis masuk Daftar Anggota. */
function role_masuk_anggota($role)
{
    return $role !== 'mahasiswa';
}

/* ---------- Format ---------- */

function tgl($date)
{
    if (!$date || $date === '0000-00-00') {
        return '-';
    }
    $bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($date);
    if (!$t) {
        return '-';
    }
    return date('j', $t) . ' ' . $bulan[(int)date('n', $t)] . ' ' . date('Y', $t);
}

function tgl_waktu($date)
{
    if (!$date) {
        return '-';
    }
    return tgl($date) . ' ' . date('H:i', strtotime($date));
}

function rupiah($n)
{
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

function badge($status)
{
    $map = [
        'aktif'    => 'ok',
        'nonaktif' => 'muted',
        'pending'  => 'warn',
        'ditolak'  => 'danger',
        'rencana'  => 'info',
        'berjalan' => 'warn',
        'selesai'  => 'ok',
        'batal'    => 'danger',
        'menunggu'     => 'warn',
        'disetujui'    => 'ok',
        'baik'         => 'ok',
        'rusak ringan' => 'warn',
        'rusak berat'  => 'danger',
        'hilang'       => 'muted',
    ];
    $cls = isset($map[$status]) ? $map[$status] : 'muted';
    return '<span class="badge badge-' . $cls . '">' . e(ucfirst($status)) . '</span>';
}

/* ---------- Opsi isian ---------- */

function jabatan_list()
{
    return ['Ketua Umum', 'Wakil Ketua', 'Sekretaris', 'Bendahara', 'Koordinator Divisi', 'Staf Divisi', 'Anggota'];
}

function divisi_list()
{
    return ['Badan Pengurus Harian', 'Pendidikan & Keilmuan', 'Hubungan Masyarakat', 'Minat & Bakat',
            'Kewirausahaan', 'Media & Kreatif', 'Kaderisasi'];
}

function status_proker_list()
{
    return ['rencana' => 'Rencana', 'berjalan' => 'Berjalan', 'selesai' => 'Selesai', 'batal' => 'Batal'];
}

/* ---------- Paginasi ---------- */

function paginate($total, $perPage, $page)
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, (int)$page), $pages);
    return ['page' => $page, 'pages' => $pages, 'per' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

function pagination_html($p)
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $q = $_GET;
    $html = '<nav class="pagination">';
    for ($i = 1; $i <= $p['pages']; $i++) {
        $q['page'] = $i;
        $cls = $i === $p['page'] ? ' class="active"' : '';
        $html .= '<a' . $cls . ' href="?' . e(http_build_query($q)) . '">' . $i . '</a>';
    }
    return $html . '</nav>';
}


/* ---------- Real-time (polling) ---------- */

/** Permintaan latar belakang dari assets/js/live.js (header X-Live). */
function is_live_request()
{
    return isset($_SERVER['HTTP_X_LIVE']);
}

/** URL aset + versi dari waktu ubah file, supaya perubahan CSS/JS langsung terbaca tanpa Ctrl+F5. */
function asset($path)
{
    $f = __DIR__ . '/../' . ltrim($path, '/');
    $v = is_file($f) ? filemtime($f) : time();
    return url($path) . '?v=' . $v;
}

/* ---------- Sapaan ---------- */

function greeting()
{
    $h = (int)date('G');
    if ($h < 11) {
        return 'Selamat pagi';
    }
    if ($h < 15) {
        return 'Selamat siang';
    }
    if ($h < 18) {
        return 'Selamat sore';
    }
    return 'Selamat malam';
}

function hari_tanggal()
{
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return $hari[(int)date('w')] . ', ' . tgl(date('Y-m-d'));
}

function waktu_lalu($datetime)
{
    $d = time() - strtotime($datetime);
    if ($d < 60) {
        return 'baru saja';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' menit lalu';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' jam lalu';
    }
    if ($d < 604800) {
        return floor($d / 86400) . ' hari lalu';
    }
    return tgl($datetime);
}

/* ---------- Notifikasi ---------- */

/** Kirim notifikasi ke satu pengguna. */
function notify($userId, $judul, $pesan = null, $link = null)
{
    db()->prepare('INSERT INTO notifikasi (user_id, judul, pesan, link, created_at) VALUES (?,?,?,?,?)')
        ->execute([(int)$userId, mb_substr($judul, 0, 150), $pesan === null ? null : mb_substr($pesan, 0, 255), $link, now()]);
}

/** Kirim notifikasi ke semua pengguna aktif dengan role tertentu (null = semua role). */
function notify_roles($roles, $judul, $pesan = null, $link = null, $exceptId = 0)
{
    if ($roles === null) {
        $st = db()->prepare("SELECT id FROM users WHERE status = 'aktif' AND id <> ?");
        $st->execute([(int)$exceptId]);
    } else {
        $in = implode(',', array_fill(0, count($roles), '?'));
        $st = db()->prepare("SELECT id FROM users WHERE status = 'aktif' AND id <> ? AND role IN ($in)");
        $st->execute(array_merge([(int)$exceptId], array_values($roles)));
    }
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        notify($uid, $judul, $pesan, $link);
    }
}

function unread_count()
{
    $u = current_user();
    if (!$u) {
        return 0;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM notifikasi WHERE user_id = ? AND dibaca = 0');
    $st->execute([(int)$u['id']]);
    return (int)$st->fetchColumn();
}

/** ID notifikasi terbaru milik pengguna saat ini (0 bila belum ada). */
function latest_notif_id()
{
    $u = current_user();
    if (!$u) {
        return 0;
    }
    $st = db()->prepare('SELECT COALESCE(MAX(id),0) FROM notifikasi WHERE user_id = ?');
    $st->execute([(int)$u['id']]);
    return (int)$st->fetchColumn();
}

/** Notifikasi belum dibaca yang lebih baru dari $after, siap dikirim sebagai toast. */
function notif_baru($userId, $after, $limit = 5)
{
    $st = db()->prepare('SELECT id, judul, pesan, link FROM notifikasi WHERE user_id = ? AND id > ? AND dibaca = 0 ORDER BY id ASC LIMIT ' . (int)$limit);
    $st->execute([(int)$userId, (int)$after]);
    $out = [];
    foreach ($st->fetchAll() as $n) {
        $out[] = [
            'id'    => (int)$n['id'],
            'judul' => $n['judul'],
            'pesan' => $n['pesan'],
            'link'  => $n['link'] ? url($n['link']) : null,
        ];
    }
    return $out;
}

/* ---------- Akun disetujui -> masuk daftar anggota ---------- */

/**
 * Tahun angkatan dari NIM.
 * Memakai 4 digit awal bila berupa tahun (mis. 2022xxxx -> 2022).
 * Jika tidak (mis. 2223101001), dipakai 2 digit awal (22 -> 2022).
 */
function angkatan_dari_nim($nim)
{
    $nim = preg_replace('/\D/', '', (string)$nim);
    $max = (int)date('Y') + 1;
    if (strlen($nim) >= 4) {
        $y = (int)substr($nim, 0, 4);
        if ($y >= 2000 && $y <= $max) {
            return $y;
        }
    }
    if (strlen($nim) >= 2) {
        $y = 2000 + (int)substr($nim, 0, 2);
        if ($y >= 2000 && $y <= $max) {
            return $y;
        }
    }
    return null;
}

function tambah_ke_anggota($nim, $nama)
{
    $angkatan = angkatan_dari_nim($nim);
    $st = db()->prepare('SELECT id, angkatan FROM anggota WHERE nim = ?');
    $st->execute([$nim]);
    $ada = $st->fetch();
    if ($ada) {
        db()->prepare("UPDATE anggota SET status = 'aktif', angkatan = COALESCE(angkatan, ?), updated_at = ? WHERE nim = ?")
            ->execute([$angkatan, now(), $nim]);
        return false;
    }
    db()->prepare("INSERT INTO anggota (nim, nama, angkatan, jabatan, status, created_at) VALUES (?, ?, ?, 'Anggota', 'aktif', ?)")
        ->execute([$nim, $nama, $angkatan, now()]);
    return true;
}

/* ---------- Foto kegiatan (unggahan) ---------- */

/**
 * Simpan foto dari <input type="file" name="$field">.
 * Mengembalikan path relatif (mis. "kegiatan/abc.jpg"), atau null bila tidak ada file / gagal ($error terisi).
 */
function simpan_foto($field, &$error)
{
    $error = '';
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $error = 'Foto gagal diunggah (ukuran file mungkin terlalu besar).';
        return null;
    }
    if ($f['size'] > 3 * 1024 * 1024) {
        $error = 'Ukuran foto maksimal 3 MB.';
        return null;
    }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) {
        $types[IMAGETYPE_WEBP] = 'webp';
    }
    if (!$info || !isset($types[$info[2]])) {
        $error = 'File foto harus berupa gambar JPG, PNG, GIF, atau WEBP.';
        return null;
    }
    $dir = __DIR__ . '/../uploads/kegiatan';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        $error = 'Folder unggahan tidak bisa dibuat. Periksa izin folder uploads.';
        return null;
    }
    $nama = bin2hex(random_bytes(12)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nama)) {
        $error = 'Foto gagal disimpan di server.';
        return null;
    }
    return 'kegiatan/' . $nama;
}

function foto_valid($path)
{
    return is_string($path) && preg_match('~^kegiatan/[a-f0-9]{24}\.(jpg|png|gif|webp)$~', $path);
}

function hapus_foto($path)
{
    if (foto_valid($path)) {
        $f = __DIR__ . '/../uploads/' . $path;
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/** Salin file foto (dipakai saat pengajuan disetujui, supaya program punya foto sendiri). */
function salin_foto($path)
{
    if (!foto_valid($path)) {
        return null;
    }
    $src = __DIR__ . '/../uploads/' . $path;
    if (!is_file($src)) {
        return null;
    }
    $baru = 'kegiatan/' . bin2hex(random_bytes(12)) . '.' . pathinfo($path, PATHINFO_EXTENSION);
    return @copy($src, __DIR__ . '/../uploads/' . $baru) ? $baru : null;
}

function foto_url($path)
{
    return foto_valid($path) ? url('uploads/' . $path) : '';
}

/* ---------- Pengajuan kegiatan ---------- */

function pengajuan_pending_count()
{
    return (int)db()->query("SELECT COUNT(*) FROM pengajuan_kegiatan WHERE status = 'menunggu'")->fetchColumn();
}

function kondisi_list()
{
    return ['baik' => 'Baik', 'rusak ringan' => 'Rusak ringan', 'rusak berat' => 'Rusak berat', 'hilang' => 'Hilang'];
}

/* ---------- Ikon ---------- */

function icon_svg($key, $size = 18)
{
    static $icons = [
    'dashboard'  => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
    'anggota'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'kegiatan'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'pengajuan'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>',
    'forum'      => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'inventaris' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05M12 22.08V12"/>',
    'notifikasi' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    'volume'     => '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/>',
    'volumeoff'  => '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/>',
    'persetujuan'=> '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M17 11l2 2 4-4"/>',
    'pengguna'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'akun'       => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'keluar'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'online'     => '<circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49m11.31-2.82a10 10 0 0 1 0 14.14m-14.14 0a10 10 0 0 1 0-14.14"/>',
    'userok'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M17 11l2 2 4-4"/>',
    'activity'   => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
    'checkc'     => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>',
    'clock'      => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'bolt'       => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>',
    'plus'       => '<path d="M12 5v14M5 12h14"/>',
    'arrow'      => '<path d="M5 12h14M12 5l7 7-7 7"/>',
    'pin'        => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" width="' . (int)$size . '" height="' . (int)$size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . (isset($icons[$key]) ? $icons[$key] : '') . '</svg>';
}

/* ---------- Forum: reaksi emoji ---------- */

/** Daftar emoji yang boleh dipakai sebagai reaksi (urutan = urutan tampil). */
function forum_emoji_list()
{
    return ['👍', '❤️', '😂', '😮', '😢', '🙏', '🎉', '🔥'];
}

/* ---------- Foto profil kartun ---------- */

/** Pilihan foto profil: kode => nama. File: assets/img/avatars/<kode>.svg */
function avatar_list()
{
    return [
        'a1' => 'Kucing Oranye',
        'a2' => 'Rubah Cerdik',
        'a3' => 'Panda Santai',
        'a4' => 'Beruang Hangat',
        'a5' => 'Kelinci Manis',
        'a6' => 'Robot Ceria',
        'a7' => 'Astronot Kecil',
        'a8' => 'Burung Hantu',
        'a9' => 'Penguin Ramah',
    ];
}

function avatar_valid($key)
{
    $l = avatar_list();
    return is_string($key) && isset($l[$key]);
}

/** HTML avatar: foto kartun bila sudah dipilih, kalau belum pakai huruf awal nama. $cls mis. "sm" / "lg". */
function avatar_html($nama, $key = null, $cls = '')
{
    $cls = trim('avatar ' . $cls);
    if (avatar_valid($key)) {
        $l = avatar_list();
        return '<span class="' . e($cls) . ' av-img"><img src="' . e(asset('assets/img/avatars/' . $key . '.svg')) . '" alt="' . e($l[$key]) . '" loading="lazy"></span>';
    }
    return '<span class="' . e($cls) . '">' . e(mb_strtoupper(mb_substr(trim((string)$nama), 0, 1))) . '</span>';
}

/** Ringkasan teks satu baris (untuk kutipan balasan & pratinjau utas). */
function forum_snippet($text, $max = 90)
{
    $t = trim(preg_replace('/\s+/u', ' ', (string)$text));
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max) . '…' : $t;
}

/* ---------- Pendaftaran kegiatan ---------- */

/** Hanya Anggota & Mahasiswa yang mendaftar. Admin & Pengurus adalah pengelola kegiatan. */
function bisa_daftar_kegiatan()
{
    $u = current_user();
    return $u && in_array($u['role'], ['anggota', 'mahasiswa'], true);
}

/** Pendaftaran hanya dibuka selama kegiatan berstatus Rencana atau Berjalan. */
function kegiatan_terbuka($status)
{
    return in_array($status, ['rencana', 'berjalan'], true);
}

/** Jumlah peserta yang sudah disetujui. */
function kegiatan_terisi($programId)
{
    $st = db()->prepare("SELECT COUNT(*) FROM pendaftaran_kegiatan WHERE program_id = ? AND status = 'disetujui'");
    $st->execute([(int)$programId]);
    return (int)$st->fetchColumn();
}

/** Kuota penuh? $kuota NULL / 0 = tanpa batas. */
function kuota_penuh($kuota, $terisi)
{
    return $kuota !== null && (int)$kuota > 0 && (int)$terisi >= (int)$kuota;
}

function kuota_label($kuota, $terisi)
{
    return ($kuota !== null && (int)$kuota > 0) ? ((int)$terisi . ' / ' . (int)$kuota . ' peserta') : ((int)$terisi . ' peserta (tanpa batas)');
}

/** Jumlah pendaftar yang menunggu persetujuan (untuk lencana menu Admin/Pengurus). */
function pendaftaran_pending_count()
{
    return (int)db()->query("SELECT COUNT(*) FROM pendaftaran_kegiatan WHERE status = 'menunggu'")->fetchColumn();
}

/* ---------- Forum kegiatan (khusus peserta yang disetujui) ---------- */

/**
 * Boleh membuka utas ini?
 * Utas biasa (tanpa program_id): semua pengguna login.
 * Utas kegiatan: Admin, Pengurus, dan peserta kegiatan itu yang berstatus "disetujui".
 * Ubah di sini bila Pengurus tidak boleh ikut masuk (hapus 'pengurus' dari daftar).
 */
function forum_boleh_akses($thread, $uid = null, $role = null)
{
    if (empty($thread['program_id'])) {
        return true;
    }
    if ($uid === null) {
        $u = current_user();
        if (!$u) {
            return false;
        }
        $uid = (int)$u['id'];
        $role = $u['role'];
    } elseif ($role === null) {
        $r = db()->prepare("SELECT role FROM users WHERE id = ? AND status = 'aktif'");
        $r->execute([(int)$uid]);
        $role = $r->fetchColumn();
        if ($role === false) {
            return false;
        }
    }
    if (in_array($role, ['admin', 'pengurus'], true)) {
        return true;
    }
    $st = db()->prepare("SELECT COUNT(*) FROM pendaftaran_kegiatan WHERE program_id = ? AND user_id = ? AND status = 'disetujui'");
    $st->execute([(int)$thread['program_id'], (int)$uid]);
    return (int)$st->fetchColumn() > 0;
}

/** Potongan SQL (untuk WHERE) yang menyaring utas kegiatan sesuai hak akses pengguna saat ini. */
function forum_filter_sql($alias = 't')
{
    $u = current_user();
    if (!$u) {
        return $alias . '.program_id IS NULL';
    }
    if (in_array($u['role'], ['admin', 'pengurus'], true)) {
        return '1=1';
    }
    return '(' . $alias . '.program_id IS NULL OR EXISTS (SELECT 1 FROM pendaftaran_kegiatan dk WHERE dk.program_id = '
        . $alias . '.program_id AND dk.user_id = ' . (int)$u['id'] . " AND dk.status = 'disetujui'))";
}

/** Ambil id utas forum milik kegiatan; dibuat otomatis (judul = nama kegiatan) bila belum ada. */
function forum_kegiatan_id($programId, $creatorId = null)
{
    $programId = (int)$programId;
    $st = db()->prepare('SELECT id FROM forum_threads WHERE program_id = ?');
    $st->execute([$programId]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }

    $p = db()->prepare('SELECT nama_proker FROM program_kerja WHERE id = ?');
    $p->execute([$programId]);
    $nama = $p->fetchColumn();
    if ($nama === false) {
        return 0;
    }
    if (!$creatorId) {
        $a = db()->query("SELECT id FROM users WHERE role = 'admin' AND status = 'aktif' ORDER BY id LIMIT 1")->fetchColumn();
        $u = current_user();
        $creatorId = $a !== false ? (int)$a : ($u ? (int)$u['id'] : 0);
    }
    try {
        db()->prepare('INSERT INTO forum_threads (user_id, program_id, judul, isi, created_at, last_activity) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$creatorId, $programId, mb_substr($nama, 0, 150),
                'Forum diskusi resmi untuk peserta kegiatan ini. Hanya Admin, Pengurus, dan peserta yang sudah disetujui yang dapat melihat dan membalas di sini.',
                now(), now()]);
        return (int)db()->lastInsertId();
    } catch (PDOException $e) {
        // utas sudah dibuat oleh permintaan lain di saat yang sama
        $st->execute([$programId]);
        $id = $st->fetchColumn();
        return $id === false ? 0 : (int)$id;
    }
}
