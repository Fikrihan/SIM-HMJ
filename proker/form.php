<?php
require_once __DIR__ . '/../includes/init.php';
redirect('kegiatan/form.php' . (isset($_GET['id']) ? '?id=' . (int)$_GET['id'] : ''));
