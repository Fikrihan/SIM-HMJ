<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

// Permintaan polling latar belakang: simpan token CSRF lalu lepas kunci session
// supaya tidak menghambat halaman lain dan tidak memakai pesan flash.
if (is_live_request()) {
    csrf_token();
    session_write_close();
}
