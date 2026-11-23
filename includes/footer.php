        </main>
        <footer class="footer">
            &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &middot; <?= e(APP_FULL_NAME) ?>
        </footer>
    </div>
</div>
<div class="toasts" id="toasts"></div>
<div class="idle-warn" id="idleWarn" role="alert" hidden>
    <span>Tidak ada aktivitas. Anda akan keluar otomatis dalam <b id="idleSec">60</b> detik.</span>
    <button type="button" class="btn btn-sm" id="idleStay">Tetap masuk</button>
</div>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<script src="<?= e(asset('assets/js/live.js')) ?>"></script>
<?php foreach ((isset($extraJs) ? (array)$extraJs : []) as $__js): ?>
<script src="<?= e(asset($__js)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
