<?php
/**
 * Layout atas (sidebar + topbar).
 * Variabel yang diharapkan dari halaman: $pageTitle, $active
 */
$me = current_user();
$unread = unread_count();
$latestNotif = latest_notif_id();
$pageTitle = isset($pageTitle) ? $pageTitle : 'Dashboard';
$active = isset($active) ? $active : '';
$svg = function ($key) {
    return icon_svg($key, 18);
};
$nav = function ($key, $href, $label, $badge = null) use ($active, $svg) {
    $cls = $active === $key ? ' class="active"' : '';
    $b = $badge === null ? '' : '<span class="nav-badge" data-badge="' . e($key) . '"' . ($badge > 0 ? '' : ' hidden') . '>' . (int)$badge . '</span>';
    return '<a' . $cls . ' href="' . e(url($href)) . '">' . $svg($key) . '<span class="lbl">' . e($label) . '</span>' . $b . '</a>';
};
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#101733">
    <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(asset('assets/img/favicon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
</head>
<body data-base="<?= e(BASE_URL) ?>" data-idle="<?= (int)IDLE_TIMEOUT ?>" data-nid="<?= (int)$latestNotif ?>" data-sound="<?= e(asset('assets/sounds/notifikasi.mp3')) ?>">
<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img class="brand-logo" src="<?= e(asset('assets/img/logo.png')) ?>" alt="Logo SIM-HMJ">
            <div>
                <strong>SIM-HMJ</strong>
                <small>Sistem Informasi Manajemen</small>
            </div>
        </div>
        <nav class="menu">
            <div class="menu-label">Menu Utama</div>
            <?= $nav('dashboard', 'dashboard.php', 'Dashboard') ?>
            <?= $nav('anggota', 'anggota/index.php', 'Data Anggota') ?>
            <?= $nav('kegiatan', 'kegiatan/index.php', 'Program Kegiatan', can_edit() ? pendaftaran_pending_count() : null) ?>
            <?= $nav('pengajuan', 'pengajuan/index.php', 'Pengajuan Kegiatan', can_edit() ? pengajuan_pending_count() : null) ?>
            <?= $nav('forum', 'forum/index.php', 'Forum Diskusi') ?>
            <?= $nav('inventaris', 'inventaris/index.php', 'Inventaris') ?>
            <?= $nav('notifikasi', 'notifikasi.php', 'Notifikasi', $unread) ?>
            <?php if (can_edit()): ?><?= $nav('online', 'online.php', 'Sedang Online') ?><?php endif; ?>
            <?php if (is_admin()): ?>
                <div class="menu-label">Administrasi</div>
                <?= $nav('persetujuan', 'admin/persetujuan.php', 'Persetujuan Akun', pending_count()) ?>
                <?= $nav('pengguna', 'admin/pengguna.php', 'Kelola Pengguna') ?>
            <?php endif; ?>
            <div class="menu-label">Akun</div>
            <?= $nav('akun', 'akun.php', 'Akun Saya') ?>
            <a href="<?= e(url('logout.php')) ?>"><?= $svg('keluar') ?><span class="lbl">Keluar</span></a>
        </nav>
        <div class="side-user">
            <?= avatar_html($me['nama'], $me['avatar']) ?>
            <div><strong><?= e($me['nama']) ?></strong><small><?= e(role_label($me['role'])) ?></small></div>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="main">
        <header class="topbar">
            <button class="burger" type="button" id="burger" aria-label="Buka menu" aria-expanded="false">&#9776;</button>
            <div class="top-title"><h1><?= e($pageTitle) ?></h1><small><?= e(hari_tanggal()) ?></small></div>
            <button class="bell sound-toggle" type="button" id="soundToggle" aria-pressed="true" title="Suara notifikasi: aktif" aria-label="Suara notifikasi">
                <span class="s-on"><?= $svg('volume') ?></span><span class="s-off"><?= $svg('volumeoff') ?></span>
            </button>
            <a class="bell" href="<?= e(url('notifikasi.php')) ?>" title="Notifikasi" aria-label="Notifikasi">
                <?= $svg('notifikasi') ?><span class="bell-badge" id="bell-badge" <?= $unread > 0 ? '' : 'hidden' ?>><?= (int)$unread ?></span>
            </a>
            <div class="user-chip">
                <?= avatar_html($me['nama'], $me['avatar']) ?>
                <div class="user-meta">
                    <strong><?= e($me['nama']) ?></strong>
                    <small><?= e($me['nim']) ?> &middot; <?= e(role_label($me['role'])) ?></small>
                </div>
            </div>
        </header>
        <main class="content">
            <?php foreach (is_live_request() ? [] : get_flash() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
            <?php endforeach; ?>
