<?php
/**
 * Konfigurasi aplikasi & koneksi database.
 * Default XAMPP: user "root" dengan password kosong.
 */

/**
 * Kredensial database dibaca dari config/config.local.php (file ini TIDAK di-upload ke GitHub).
 * Kalau file itu tidak ada, dipakai default XAMPP di bawah.
 * Di hosting: salin config.local.example.php menjadi config.local.php lalu isi data database hosting.
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
if (!defined('DB_HOST')) { define('DB_HOST', 'localhost'); }
if (!defined('DB_NAME')) { define('DB_NAME', 'hmj_si'); }
if (!defined('DB_USER')) { define('DB_USER', 'root'); }
if (!defined('DB_PASS')) { define('DB_PASS', ''); }

define('APP_NAME', 'SIM-HMJ');

/** Logout otomatis bila tidak ada aktivitas selama ini (detik). 600 = 10 menit. */
define('IDLE_TIMEOUT', 600);
define('APP_FULL_NAME', 'Sistem Informasi Manajemen - Himpunan Mahasiswa Jurusan');

/**
 * BASE_URL dideteksi otomatis dari lokasi folder aplikasi di htdocs.
 * Contoh: folder C:\xampp\htdocs\hmj-si  =>  /hmj-si
 * Jika deteksi gagal, ubah nilainya secara manual (tanpa garis miring di akhir).
 */
$__root = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$__app  = realpath(__DIR__ . '/..');
$__base = '/hmj-si';
if ($__root && $__app) {
    $__root = rtrim(str_replace('\\', '/', $__root), '/');
    $__app  = str_replace('\\', '/', $__app);
    if (stripos($__app, $__root) === 0) {
        $__base = substr($__app, strlen($__root));
    }
}
define('BASE_URL', rtrim($__base, '/'));
unset($__root, $__app, $__base);

/* ---------- Penanda perubahan data (untuk real-time) ----------
 * Setiap INSERT/UPDATE/DELETE yang benar-benar mengubah data memperbarui file kecil config/live.ver.
 * api/stream.php (Server-Sent Events) memantau file ini dan langsung memberi tahu semua browser yang terbuka.
 * Pembaruan "last_seen" sesi login diabaikan karena terjadi terus-menerus. */
function live_ver_file()
{
    return __DIR__ . '/live.ver';
}

function live_version()
{
    clearstatcache(true, live_ver_file());
    $v = @file_get_contents(live_ver_file());
    return $v === false ? '0' : trim($v);
}

function live_bump_query($sql, $rows)
{
    if ($rows < 1) { return; }
    if (!preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)) { return; }
    if (preg_match('/^\s*UPDATE\s+`?sesi_login`?/i', $sql)) { return; }
    @file_put_contents(live_ver_file(), sprintf('%.6f-%d', microtime(true), mt_rand(1000, 9999)), LOCK_EX);
}

class LiveStatement extends PDOStatement
{
    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        $ok = ($params === null) ? parent::execute() : parent::execute($params);
        if ($ok) { live_bump_query($this->queryString, $this->rowCount()); }
        return $ok;
    }
}

class LivePDO extends PDO
{
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        $n = parent::exec($statement);
        if ($n !== false) { live_bump_query($statement, $n); }
        return $n;
    }
}

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new LivePDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STATEMENT_CLASS    => ['LiveStatement'],
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die('<h3>Koneksi database gagal</h3><p>Pastikan <b>MySQL</b> sudah menyala (XAMPP: Start) dan database <b>'
                . htmlspecialchars(DB_NAME) . '</b> sudah di-import dari file <code>database.sql</code>.</p>'
                . '<p>Cek pengaturan di <code>config/config.local.php</code> (atau default di <code>config/database.php</code>).</p>');
        }
    }
    return $pdo;
}
