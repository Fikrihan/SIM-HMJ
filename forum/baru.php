<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$me = current_user();
$errors = [];
$judul = '';
$isi = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $judul = trim(isset($_POST['judul']) ? $_POST['judul'] : '');
    $isi   = trim(isset($_POST['isi']) ? $_POST['isi'] : '');

    if (mb_strlen($judul) < 5 || mb_strlen($judul) > 150) {
        $errors[] = 'Judul utas harus 5-150 karakter.';
    }
    if (mb_strlen($isi) < 5 || mb_strlen($isi) > 5000) {
        $errors[] = 'Isi utas harus 5-5000 karakter.';
    }

    if (!$errors) {
        db()->prepare('INSERT INTO forum_threads (user_id, judul, isi, created_at, last_activity) VALUES (?,?,?,?,?)')
            ->execute([(int)$me['id'], $judul, $isi, now(), now()]);
        $id = (int)db()->lastInsertId();
        notify_roles(null, 'Utas baru: ' . $judul, 'Dibuat oleh ' . $me['nama'], 'forum/thread.php?id=' . $id, (int)$me['id']);
        flash('success', 'Utas berhasil dibuat.');
        redirect('forum/thread.php?id=' . $id);
    }
}

$pageTitle = 'Utas Baru';
$active = 'forum';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:820px">
    <div class="card-head"><h2>Buat Utas Baru</h2><span class="sub">Tulis judul yang jelas agar mudah dicari &amp; dijawab.</span></div>

    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="full">
                <label for="judul">Judul <span class="req">*</span></label>
                <input type="text" id="judul" name="judul" value="<?= e($judul) ?>" maxlength="150" required>
            </div>
            <div class="full">
                <label for="isi">Isi / Pertanyaan <span class="req">*</span></label>
                <textarea id="isi" name="isi" rows="8" placeholder="Ceritakan pertanyaan, usul, atau topik yang ingin dibahas..." maxlength="5000" required><?= e($isi) ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Kirim Utas</button>
            <a class="btn btn-light" href="<?= e(url('forum/index.php')) ?>">Batal</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
