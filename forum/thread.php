<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$me = current_user();
$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);

$st = db()->prepare('SELECT t.*, u.nama, u.avatar FROM forum_threads t JOIN users u ON u.id = t.user_id WHERE t.id = ?');
$st->execute([$id]);
$thread = $st->fetch();
if (!$thread) {
    http_response_code(404);
    flash('error', 'Utas tidak ditemukan atau sudah dihapus.');
    redirect('forum/index.php');
}

// Forum kegiatan: hanya Admin, Pengurus, dan peserta yang sudah disetujui (berlaku juga untuk semua aksi POST)
if (!forum_boleh_akses($thread)) {
    http_response_code(403);
    flash('error', 'Forum ini khusus peserta kegiatan yang pendaftarannya sudah disetujui.');
    redirect(!empty($thread['program_id']) ? 'kegiatan/index.php' : 'forum/index.php');
}

$isKegiatan = !empty($thread['program_id']);
$isOwner = (int)$thread['user_id'] === (int)$me['id'];

/** Ambil semua reaksi utas ini: [post_id][emoji] => ['n' => jumlah, 'me' => bool, 'names' => []]. */
function forum_reaksi_map($threadId, $meId)
{
    $st = db()->prepare('SELECT r.post_id, r.emoji, r.user_id, u.nama FROM forum_reaksi r JOIN users u ON u.id = r.user_id WHERE r.thread_id = ? ORDER BY r.id ASC');
    $st->execute([$threadId]);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $pid = (int)$r['post_id'];
        $emo = $r['emoji'];
        if (!isset($map[$pid][$emo])) {
            $map[$pid][$emo] = ['n' => 0, 'me' => false, 'names' => []];
        }
        $map[$pid][$emo]['n']++;
        $map[$pid][$emo]['names'][] = $r['nama'];
        if ((int)$r['user_id'] === (int)$meId) {
            $map[$pid][$emo]['me'] = true;
        }
    }
    return $map;
}

/** Baris reaksi untuk satu target (0 = isi utas, selain itu id balasan). */
function forum_reaksi_html($targetId, $map)
{
    $mine = isset($map[$targetId]) ? $map[$targetId] : [];
    $h = '<div class="react-bar" data-target="' . (int)$targetId . '">';
    foreach (forum_emoji_list() as $emo) {
        if (empty($mine[$emo])) { continue; }
        $r = $mine[$emo];
        $h .= '<button type="button" class="react-chip' . ($r['me'] ? ' mine' : '') . '" data-emoji="' . e($emo) . '" title="' . e(implode(', ', $r['names'])) . '">'
            . '<span class="emo">' . e($emo) . '</span><span class="cnt">' . (int)$r['n'] . '</span></button>';
    }
    $h .= '<span class="react-add"><button type="button" class="react-toggle" aria-label="Beri reaksi" title="Beri reaksi">&#9786;<b>+</b></button>'
        . '<span class="react-pop" role="menu">';
    foreach (forum_emoji_list() as $emo) {
        $h .= '<button type="button" data-emoji="' . e($emo) . '" aria-label="Reaksi ' . e($emo) . '">' . e($emo) . '</button>';
    }
    $h .= '</span></span></div>';
    return $h;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'balas') {
        $isi = trim(isset($_POST['isi']) ? $_POST['isi'] : '');
        if ($thread['terkunci']) {
            flash('error', 'Utas ini terkunci, balasan tidak dapat ditambahkan.');
        } elseif (mb_strlen($isi) < 1 || mb_strlen($isi) > 3000) {
            flash('error', 'Balasan harus 1-3000 karakter.');
        } else {
            // Balasan ke komentar tertentu (harus komentar di utas yang sama)
            $parentId = (int)(isset($_POST['parent_id']) ? $_POST['parent_id'] : 0);
            $parentOwner = 0;
            if ($parentId > 0) {
                $pc = db()->prepare('SELECT user_id FROM forum_posts WHERE id = ? AND thread_id = ?');
                $pc->execute([$parentId, $id]);
                $po = $pc->fetchColumn();
                if ($po === false) { $parentId = 0; } else { $parentOwner = (int)$po; }
            }

            db()->prepare('INSERT INTO forum_posts (thread_id, user_id, parent_id, isi, created_at) VALUES (?,?,?,?,?)')
                ->execute([$id, (int)$me['id'], $parentId > 0 ? $parentId : null, $isi, now()]);
            $newId = (int)db()->lastInsertId();
            db()->prepare('UPDATE forum_threads SET last_activity = ? WHERE id = ?')->execute([now(), $id]);

            // Beri tahu pembuat utas dan peserta lain yang pernah membalas
            $ids = db()->prepare('SELECT DISTINCT user_id FROM forum_posts WHERE thread_id = ?');
            $ids->execute([$id]);
            $target = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
            $target[] = (int)$thread['user_id'];
            $link = 'forum/thread.php?id=' . $id . '#post-' . $newId;
            foreach (array_unique($target) as $uid) {
                if ($uid === (int)$me['id']) { continue; }
                if (!forum_boleh_akses($thread, $uid)) { continue; } // jangan beri tahu yang tak punya akses
                if ($parentOwner > 0 && $uid === $parentOwner) {
                    notify($uid, $me['nama'] . ' membalas komentar Anda', mb_substr($isi, 0, 100), $link);
                } else {
                    notify($uid, 'Balasan baru di: ' . $thread['judul'], $me['nama'] . ': ' . mb_substr($isi, 0, 100), $link);
                }
            }
        }
        redirect('forum/thread.php?id=' . $id . (isset($newId) ? '#post-' . $newId : '#balas'));
    }

    if ($action === 'reaksi') {
        $pid = (int)(isset($_POST['post_id']) ? $_POST['post_id'] : 0);
        $emo = isset($_POST['emoji']) ? (string)$_POST['emoji'] : '';
        $valid = in_array($emo, forum_emoji_list(), true);
        if ($valid && $pid > 0) {
            $pc = db()->prepare('SELECT COUNT(*) FROM forum_posts WHERE id = ? AND thread_id = ?');
            $pc->execute([$pid, $id]);
            $valid = (int)$pc->fetchColumn() > 0;
        }
        if ($valid) {
            $ex = db()->prepare('SELECT id FROM forum_reaksi WHERE thread_id = ? AND post_id = ? AND user_id = ? AND emoji = ?');
            $ex->execute([$id, $pid, (int)$me['id'], $emo]);
            $rid = $ex->fetchColumn();
            if ($rid !== false) { // klik lagi = batalkan reaksi
                db()->prepare('DELETE FROM forum_reaksi WHERE id = ?')->execute([$rid]);
            } else {
                db()->prepare('INSERT IGNORE INTO forum_reaksi (thread_id, post_id, user_id, emoji, created_at) VALUES (?,?,?,?,?)')
                    ->execute([$id, $pid, (int)$me['id'], $emo, now()]);
            }
        }
        if (isset($_SERVER['HTTP_X_AJAX'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => $valid, 'target' => $pid, 'html' => forum_reaksi_html($pid, forum_reaksi_map($id, (int)$me['id']))]);
            exit;
        }
        redirect('forum/thread.php?id=' . $id . '#' . ($pid > 0 ? 'post-' . $pid : 'top'));
    }

    if ($action === 'hapus_post') {
        $pid = (int)(isset($_POST['post_id']) ? $_POST['post_id'] : 0);
        $ps = db()->prepare('SELECT user_id FROM forum_posts WHERE id = ? AND thread_id = ?');
        $ps->execute([$pid, $id]);
        $owner = $ps->fetchColumn();
        if ($owner !== false && ((int)$owner === (int)$me['id'] || can_edit())) {
            db()->prepare('DELETE FROM forum_reaksi WHERE thread_id = ? AND post_id = ?')->execute([$id, $pid]);
            db()->prepare('DELETE FROM forum_posts WHERE id = ?')->execute([$pid]);
            flash('success', 'Balasan dihapus.');
        }
        redirect('forum/thread.php?id=' . $id);
    }

    if ($action === 'hapus_thread' && (($isOwner && !$isKegiatan) || can_edit())) {
        db()->prepare('DELETE FROM forum_threads WHERE id = ?')->execute([$id]);
        flash('success', 'Utas dihapus.');
        redirect('forum/index.php');
    }

    if ($action === 'kunci' && can_edit()) {
        db()->prepare('UPDATE forum_threads SET terkunci = ? WHERE id = ?')->execute([$thread['terkunci'] ? 0 : 1, $id]);
        flash('success', $thread['terkunci'] ? 'Utas dibuka kembali.' : 'Utas dikunci.');
        redirect('forum/thread.php?id=' . $id);
    }

    redirect('forum/thread.php?id=' . $id);
}

$ps = db()->prepare('SELECT p.*, u.nama, u.role, u.avatar, pp.isi AS parent_isi, pu.nama AS parent_nama
    FROM forum_posts p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN forum_posts pp ON pp.id = p.parent_id
    LEFT JOIN users pu ON pu.id = pp.user_id
    WHERE p.thread_id = ? ORDER BY p.id ASC');
$ps->execute([$id]);
$posts = $ps->fetchAll();
$rxMap = forum_reaksi_map($id, (int)$me['id']);
$totReaksi = 0;
foreach ($rxMap as $byEmoji) { foreach ($byEmoji as $r) { $totReaksi += $r['n']; } }
$peserta = [(int)$thread['user_id'] => 1];
foreach ($posts as $p0) { $peserta[(int)$p0['user_id']] = 1; }

$pageTitle = 'Forum Diskusi';
$active = 'forum';
$extraJs = ['assets/js/forum.js'];
require __DIR__ . '/../includes/header.php';
?>

<p class="fx-back"><?php if ($isKegiatan): ?><a href="<?= e(url('kegiatan/index.php')) ?>">&larr; Kembali ke Program Kegiatan</a><?php else: ?><a href="<?= e(url('forum/index.php')) ?>">&larr; Kembali ke daftar utas</a><?php endif; ?></p>

<div class="fx-wrap" id="forumApp" data-csrf="<?= e(csrf_token()) ?>" data-locked="<?= $thread['terkunci'] ? '1' : '0' ?>">
    <div data-live="thread-body" id="top">
        <section class="fx-op">
            <div class="fx-op-top">
                <div class="fx-op-tags">
                    <?php if ($isKegiatan): ?><span class="fx-badge new">&#127915; Forum Kegiatan</span><?php endif; ?>
                    <span class="fx-badge"><?= count($peserta) ?> peserta</span>
                    <?php if ($thread['terkunci']): ?><span class="fx-badge lock">&#128274; Terkunci</span><?php endif; ?>
                </div>
                <div class="actions">
                    <?php if (can_edit()): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="kunci">
                            <button class="btn btn-light btn-sm" type="submit"><?= $thread['terkunci'] ? 'Buka kunci' : 'Kunci utas' ?></button></form>
                    <?php endif; ?>
                    <?php if (($isOwner && !$isKegiatan) || can_edit()): ?>
                        <form method="post" data-confirm="Hapus utas beserta semua balasannya?"><?= csrf_field() ?><input type="hidden" name="action" value="hapus_thread">
                            <button class="btn btn-danger btn-sm" type="submit">Hapus utas</button></form>
                    <?php endif; ?>
                </div>
            </div>
            <h2 class="fx-op-title"><?= e($thread['judul']) ?></h2>
            <div class="fx-op-author">
                <?= avatar_html($thread['nama'], $thread['avatar'], 'md') ?>
                <div><strong><?= e($thread['nama']) ?></strong> <span class="tag">Penulis utas</span>
                    <div class="sub"><?= e(tgl_waktu($thread['created_at'])) ?></div></div>
            </div>
            <div class="fx-op-text"><?= e($thread['isi']) ?></div>
            <div class="post-actions"><?= forum_reaksi_html(0, $rxMap) ?></div>
        </section>

        <div class="fx-divider"><span><?= count($posts) ?> Balasan<?= $totReaksi > 0 ? ' &middot; ' . $totReaksi . ' reaksi' : '' ?></span></div>

        <?php if (!$posts): ?>
            <div class="fx-empty small"><div class="fx-empty-ico">&#128172;</div><strong>Belum ada balasan</strong><p>Kirim balasan pertama di bawah.</p></div>
        <?php endif; ?>

        <?php foreach ($posts as $p): $own = (int)$p['user_id'] === (int)$me['id']; ?>
            <article class="fx-msg<?= $own ? ' own' : '' ?>" id="post-<?= (int)$p['id'] ?>">
                <?= avatar_html($p['nama'], $p['avatar'], 'md') ?>
                <div class="fx-msg-main">
                    <div class="fx-msg-head">
                        <strong><?= e($p['nama']) ?></strong>
                        <?php if (in_array($p['role'], ['admin', 'pengurus'], true)): ?><span class="tag"><?= e(role_label($p['role'])) ?></span><?php endif; ?>
                        <?php if ((int)$p['user_id'] === (int)$thread['user_id']): ?><span class="tag gold">Penulis</span><?php endif; ?>
                        <span class="sub"><?= e(tgl_waktu($p['created_at'])) ?></span>
                        <?php if ($own || can_edit()): ?>
                            <form method="post" data-confirm="Hapus balasan ini?" class="fx-del">
                                <?= csrf_field() ?><input type="hidden" name="action" value="hapus_post"><input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                                <button class="btn-link small" type="submit">Hapus</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="fx-bubble">
                        <?php if ($p['parent_id'] && $p['parent_nama'] !== null): ?>
                            <a class="quote" href="#post-<?= (int)$p['parent_id'] ?>"><strong>&#8617; <?= e($p['parent_nama']) ?></strong><span><?= e(forum_snippet($p['parent_isi'])) ?></span></a>
                        <?php endif; ?>
                        <div class="post-text"><?= e($p['isi']) ?></div>
                    </div>
                    <div class="post-actions">
                        <?= forum_reaksi_html((int)$p['id'], $rxMap) ?>
                        <?php if (!$thread['terkunci']): ?>
                            <button type="button" class="btn-link small reply-btn" data-id="<?= (int)$p['id'] ?>" data-nama="<?= e($p['nama']) ?>" data-text="<?= e(forum_snippet($p['isi'], 120)) ?>">&#8617; Balas</button>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="fx-composer" id="balas">
        <?php if ($thread['terkunci']): ?>
            <div class="muted">&#128274; Utas ini terkunci. Balasan baru tidak dapat ditambahkan.</div>
        <?php else: ?>
            <form method="post" autocomplete="off">
                <?= csrf_field() ?><input type="hidden" name="action" value="balas">
                <input type="hidden" name="parent_id" id="parentId" value="">
                <div class="reply-ctx" id="replyCtx" hidden>
                    <div class="rc-text">Membalas <strong id="replyNama"></strong><span id="replyText"></span></div>
                    <button type="button" class="btn-link small" id="replyCancel" aria-label="Batalkan balasan ke komentar">&#10005; Batal</button>
                </div>
                <div class="fx-composer-row">
                    <?= avatar_html($me['nama'], $me['avatar'], 'md') ?>
                    <textarea id="isi" name="isi" rows="2" maxlength="3000" required placeholder="Tulis balasan... (Ctrl + Enter untuk kirim)"></textarea>
                </div>
                <div class="fx-composer-foot">
                    <span class="sub"><span id="isiCount">0</span>/3000</span>
                    <button class="btn" type="submit">Kirim Balasan &#10148;</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
