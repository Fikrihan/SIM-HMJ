<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$q = trim(isset($_GET['q']) ? $_GET['q'] : '');
$urutList = ['aktif' => 'Aktivitas terbaru', 'baru' => 'Utas terbaru', 'populer' => 'Terpopuler', 'sepi' => 'Belum dibalas'];
$urut = isset($_GET['urut']) && isset($urutList[$_GET['urut']]) ? $_GET['urut'] : 'aktif';

$conds = [forum_filter_sql('t')]; // utas kegiatan hanya tampil bagi Admin/Pengurus & peserta yang disetujui
$args  = [];
if ($q !== '') {
    $conds[] = '(t.judul LIKE ? OR t.isi LIKE ?)';
    $like = '%' . $q . '%';
    $args[] = $like;
    $args[] = $like;
}
if ($urut === 'sepi') {
    $conds[] = '(SELECT COUNT(*) FROM forum_posts p0 WHERE p0.thread_id = t.id) = 0';
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$order = 't.last_activity DESC, t.id DESC';
if ($urut === 'baru') { $order = 't.created_at DESC, t.id DESC'; }
if ($urut === 'populer') { $order = 'balasan DESC, t.last_activity DESC'; }

$cnt = db()->prepare("SELECT COUNT(*) FROM forum_threads t $where");
$cnt->execute($args);
$pg = paginate((int)$cnt->fetchColumn(), 12, isset($_GET['page']) ? $_GET['page'] : 1);

$st = db()->prepare(
    "SELECT t.*, u.nama, u.avatar,
            (SELECT COUNT(*) FROM forum_posts p WHERE p.thread_id = t.id) AS balasan,
            (SELECT COUNT(*) FROM forum_reaksi r WHERE r.thread_id = t.id) AS reaksi
     FROM forum_threads t JOIN users u ON u.id = t.user_id
     $where
     ORDER BY $order
     LIMIT " . (int)$pg['per'] . ' OFFSET ' . (int)$pg['offset']
);
$st->execute($args);
$rows = $st->fetchAll();

// Peserta terbaru tiap utas (maks. 4 avatar)
$people = [];
if ($rows) {
    $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
    $in  = implode(',', $ids);
    $ps  = db()->query(
        "SELECT p.thread_id, u.id, u.nama, u.avatar, MAX(p.id) AS last_post
         FROM forum_posts p JOIN users u ON u.id = p.user_id
         WHERE p.thread_id IN ($in)
         GROUP BY p.thread_id, u.id, u.nama, u.avatar
         ORDER BY last_post DESC"
    )->fetchAll();
    foreach ($ps as $p) {
        $tid = (int)$p['thread_id'];
        if (!isset($people[$tid])) { $people[$tid] = []; }
        if (count($people[$tid]) < 4) { $people[$tid][] = $p; }
    }
}

$fz        = forum_filter_sql('t');
$totUtas   = (int)db()->query("SELECT COUNT(*) FROM forum_threads t WHERE $fz")->fetchColumn();
$totBalas  = (int)db()->query("SELECT COUNT(*) FROM forum_posts p JOIN forum_threads t ON t.id = p.thread_id WHERE $fz")->fetchColumn();
$totReaksi = (int)db()->query("SELECT COUNT(*) FROM forum_reaksi r JOIN forum_threads t ON t.id = r.thread_id WHERE $fz")->fetchColumn();

$pageTitle = 'Forum Diskusi';
$active = 'forum';
require __DIR__ . '/../includes/header.php';
?>

<section class="fx-hero">
    <div class="fx-hero-text">
        <span class="fx-eyebrow">Ruang ngobrol HMJ</span>
        <h2>Forum Diskusi</h2>
        <p>Tanya, usul, atau sekadar ngobrol bareng seluruh keluarga Sistem Informasi.</p>
        <a class="btn fx-hero-btn" href="<?= e(url('forum/baru.php')) ?>">+ Mulai Utas Baru</a>
    </div>
    <div class="fx-hero-stats" data-live="forum-stats">
        <div><b><?= $totUtas ?></b><span>Utas</span></div>
        <div><b><?= $totBalas ?></b><span>Balasan</span></div>
        <div><b><?= $totReaksi ?></b><span>Reaksi</span></div>
    </div>
</section>

<div class="card fx-card">
    <form class="fx-search" method="get">
        <div class="fx-search-box">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari judul atau isi utas..." autocomplete="off">
        </div>
        <input type="hidden" name="urut" value="<?= e($urut) ?>">
        <button class="btn btn-light" type="submit">Cari</button>
        <?php if ($q !== ''): ?><a class="btn btn-light" href="<?= e(url('forum/index.php?urut=' . $urut)) ?>">Reset</a><?php endif; ?>
    </form>

    <div class="fx-tabs" role="tablist">
        <?php foreach ($urutList as $k => $label): ?>
            <a class="fx-tab<?= $k === $urut ? ' active' : '' ?>" href="?<?= e(http_build_query(['q' => $q, 'urut' => $k])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="fx-list" data-live="threads">
        <?php if (!$rows): ?>
            <div class="fx-empty">
                <div class="fx-empty-ico">&#128172;</div>
                <strong><?= $q !== '' ? 'Tidak ada utas yang cocok' : 'Belum ada utas' ?></strong>
                <p><?= $q !== '' ? 'Coba kata kunci lain.' : 'Jadilah yang pertama memulai diskusi!' ?></p>
            </div>
        <?php endif; ?>
        <?php foreach ($rows as $t):
            $baru = (time() - strtotime($t['created_at'])) < 86400;
            $ramai = (int)$t['balasan'] >= 5;
            $tp = isset($people[(int)$t['id']]) ? $people[(int)$t['id']] : [];
        ?>
            <a class="fx-thread<?= $t['terkunci'] ? ' locked' : '' ?>" href="<?= e(url('forum/thread.php?id=' . (int)$t['id'])) ?>">
                <?= avatar_html($t['nama'], $t['avatar'], 'md') ?>
                <div class="fx-main">
                    <div class="fx-title">
                        <span><?= e($t['judul']) ?></span>
                        <?php if (!empty($t['program_id'])): ?><em class="fx-badge new">&#127915; Kegiatan</em><?php endif; ?>
                        <?php if ($baru): ?><em class="fx-badge new">Baru</em><?php endif; ?>
                        <?php if ($ramai): ?><em class="fx-badge hot">&#128293; Ramai</em><?php endif; ?>
                        <?php if ($t['terkunci']): ?><em class="fx-badge lock">&#128274; Terkunci</em><?php endif; ?>
                    </div>
                    <div class="fx-excerpt"><?= e(forum_snippet($t['isi'], 140)) ?></div>
                    <div class="fx-meta">oleh <b><?= e($t['nama']) ?></b> &middot; <?= e(waktu_lalu($t['created_at'])) ?></div>
                </div>
                <div class="fx-side">
                    <div class="fx-stats">
                        <span title="Balasan">&#128172; <?= (int)$t['balasan'] ?></span>
                        <span title="Reaksi">&#10084;&#65039; <?= (int)$t['reaksi'] ?></span>
                    </div>
                    <?php if ($tp): ?>
                        <div class="fx-people">
                            <?php foreach (array_reverse($tp) as $pp): ?><?= avatar_html($pp['nama'], $pp['avatar'], 'xs') ?><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="sub">aktif <?= e(waktu_lalu($t['last_activity'])) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
        <?= pagination_html($pg) ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
