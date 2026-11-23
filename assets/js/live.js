/**
 * Pembaruan real-time tanpa reload (polling).
 *  1. Bagian halaman bertanda  data-live="nama"  disegarkan otomatis bila isinya berubah.
 *  2. Lencana notifikasi / antrean diperbarui, dan notifikasi baru muncul sebagai toast.
 * Real-time: koneksi SSE (api/stream.php) memberi tahu seketika saat ada data berubah, lalu bagian
 *    halaman disegarkan. Bila SSE tidak tersedia, otomatis kembali ke polling tiap 5 detik.
 * Pembaruan ditunda saat tab tersembunyi, atau saat pengguna sedang mengisi bagian yang sama.
 *  3. Logout otomatis (batas diam ditentukan server): aktivitas pengguna (mouse, ketik, sentuh, gulir)
 *     dilaporkan lewat header X-Act. Polling biasa BUKAN aktivitas. Sebelum habis, muncul peringatan hitung mundur.
 */
(function () {
    var INTERVAL = 5000;
    var base = document.body.getAttribute('data-base') || '';
    var nid0 = parseInt(document.body.getAttribute('data-nid'), 10);
    var lastId = isNaN(nid0) ? -1 : nid0; // id notifikasi terakhir yang sudah diketahui halaman ini
    var shown = {};             // id notifikasi yang sudah tampil sebagai pop-up (cegah dobel)
    var lastVer = null;         // versi data terakhir dari SSE (untuk menyusul perubahan saat sambung ulang)
    var busy = false;
    var acted = false;          // pengguna berinteraksi sejak polling terakhir
    var WARN = 60;              // detik: tampilkan peringatan sebelum logout otomatis
    var sisa = null, sisaAt = 0; // sisa waktu dari server + waktu diterimanya
    var warnEl = document.getElementById('idleWarn');
    var secEl = document.getElementById('idleSec');
    var warnShown = false, pingTimer = null;
    var es = null, sseOK = false, lastRun = 0, again = false, esRetry = null;
    var BACKUP = 15000;         // saat SSE aktif, tetap cek berkala sebagai jaring pengaman

    function goLogin(reason) {
        window.location.href = base + '/login.php' + (reason ? '?msg=' + encodeURIComponent(reason) : '');
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text) { n.textContent = text; }
        return n;
    }

    function setBadge(node, n) {
        node.textContent = n;
        if (n > 0) { node.removeAttribute('hidden'); } else { node.setAttribute('hidden', ''); }
    }

    /* ---------- Suara notifikasi ---------- */
    var SOUND_URL = document.body.getAttribute('data-sound');
    var audio = null, unlocked = false, lastPlay = 0, soundOn = true;
    try { soundOn = window.localStorage.getItem('hmj-sound') !== 'off'; } catch (x) {}

    function initAudio() {
        if (!audio && SOUND_URL && window.Audio) {
            audio = new Audio(SOUND_URL);
            audio.preload = 'auto';
        }
        return audio;
    }

    /* Browser (terutama iPhone & Chrome) hanya mengizinkan suara setelah pengguna menyentuh halaman. */
    function unlockAudio() {
        if (unlocked || !initAudio()) { return; }
        audio.muted = true;
        var p = audio.play();
        var done = function () {
            audio.pause();
            audio.currentTime = 0;
            audio.muted = false;
            unlocked = true;
        };
        if (p && p.then) { p.then(done, function () { audio.muted = false; }); } else { done(); }
    }

    function playSound(force) {
        if (!soundOn && !force) { return; }
        var now = Date.now();
        if (!force && now - lastPlay < 1200) { return; } // beberapa notifikasi sekaligus: cukup satu bunyi
        lastPlay = now;
        if (!initAudio()) { return; }
        try {
            audio.muted = false;
            audio.currentTime = 0;
            var p = audio.play();
            if (p && p.catch) { p.catch(function () {}); }
        } catch (x) {}
    }

    function setSoundUI() {
        var b = document.getElementById('soundToggle');
        if (!b) { return; }
        b.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
        var t = 'Suara notifikasi: ' + (soundOn ? 'aktif' : 'mati');
        b.setAttribute('title', t);
        b.setAttribute('aria-label', t);
    }

    ['pointerdown', 'touchend', 'keydown'].forEach(function (ev) {
        window.addEventListener(ev, unlockAudio, { passive: true, capture: true });
    });
    var soundBtn = document.getElementById('soundToggle');
    if (soundBtn) {
        soundBtn.addEventListener('click', function () {
            soundOn = !soundOn;
            try { window.localStorage.setItem('hmj-sound', soundOn ? 'on' : 'off'); } catch (x) {}
            setSoundUI();
            if (soundOn) { playSound(true); } // contoh bunyi saat diaktifkan
        });
    }
    setSoundUI();
    window.hmjPlayNotifSound = function () { playSound(true); };

    function toast(item) {
        var box = document.getElementById('toasts');
        if (!box || shown[item.id]) { return; }
        shown[item.id] = true;
        playSound(false);
        var t = el(item.link ? 'a' : 'div', 'toast');
        if (item.link) { t.href = base + '/notifikasi.php?go=' + item.id; }
        t.appendChild(el('strong', '', item.judul));
        if (item.pesan) { t.appendChild(el('span', '', item.pesan)); }
        box.insertBefore(t, box.firstChild);          // terbaru selalu paling atas
        while (box.children.length > 4) { box.removeChild(box.lastChild); }
        setTimeout(function () { t.classList.add('out'); }, 7000);
        setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 7600);
    }

    function pollStatus() {
        var headers = { 'X-Live': '1' }, sent = false;
        if (acted) { headers['X-Act'] = '1'; acted = false; sent = true; }
        return fetch(base + '/api/live.php?after=' + lastId, {
            headers: headers, credentials: 'same-origin', cache: 'no-store'
        }).then(function (r) {
            if (r.status === 401) {
                return r.json().then(function (d) { goLogin(d && d.reason); return null; },
                                     function () { goLogin(); return null; });
            }
            return r.json();
        }).then(function (d) {
            if (!d || !d.auth) { return; }
            updateBadges(d);
            if (lastId >= 0) { (d['new'] || []).forEach(toast); }
            if (d.latest > lastId) { lastId = d.latest; }
            if (typeof d.sisa === 'number') { sisa = d.sisa; sisaAt = Date.now(); renderWarn(); }
        }).catch(function () { if (sent) { acted = true; } });
    }

    function updateBadges(d) {
        var bell = document.getElementById('bell-badge');
        if (bell && d.notifikasi !== undefined) { setBadge(bell, d.notifikasi); }
        document.querySelectorAll('.nav-badge[data-badge]').forEach(function (b) {
            var k = b.getAttribute('data-badge');
            if (d[k] !== undefined) { setBadge(b, d[k]); }
        });
    }

    /* Event SSE 'change' membawa notifikasi baru: tampilkan seketika, lalu segarkan halaman di latar. */
    function onChange(d) {
        if (d && d.notif && d.notif.length) {
            d.notif.forEach(function (n) {
                toast(n);
                if (n.id > lastId) { lastId = n.id; }
            });
        }
        if (d && d.unread !== undefined) { updateBadges({ notifikasi: d.unread }); }
        run();
    }

    function remaining() {
        if (sisa === null) { return null; }
        return sisa - Math.floor((Date.now() - sisaAt) / 1000);
    }

    function renderWarn() {
        var r = remaining();
        if (!warnEl || r === null) { return; }
        if (r > 0 && r <= WARN) {
            if (secEl) { secEl.textContent = r; }
            warnEl.removeAttribute('hidden');
            warnShown = true;
        } else {
            warnEl.setAttribute('hidden', '');
            warnShown = false;
        }
        if (r <= 0 && !busy) { // waktu habis: biarkan server yang memutuskan
            busy = true;
            pollStatus().then(function () { busy = false; }, function () { busy = false; });
        }
    }

    function onActivity() {
        acted = true;
        if (warnShown && !pingTimer) { // sedang ada peringatan: laporkan segera agar waktu diperpanjang
            pingTimer = setTimeout(function () { pingTimer = null; pollStatus(); }, 400);
        }
    }

    function userIsBusyIn(region) {
        if (region.querySelector('.react-pop.open')) { return true; } // pemilih emoji sedang dibuka
        var a = document.activeElement;
        return a && region.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName);
    }

    function refreshRegions() {
        var regions = document.querySelectorAll('[data-live]');
        if (!regions.length) { return Promise.resolve(); }
        return fetch(window.location.href, {
            headers: { 'X-Live': '1' }, credentials: 'same-origin', cache: 'no-store'
        }).then(function (r) {
            if (r.status === 401 || (r.redirected && r.url.indexOf('login.php') !== -1)) {
                if (r.redirected) { window.location.href = r.url; } else { goLogin(); }
                return null;
            }
            if (r.redirected) { return null; } // halaman sudah berpindah (mis. utas dihapus)
            return r.text();
        }).then(function (html) {
            if (!html) { return; }
            var doc = new DOMParser().parseFromString(html, 'text/html');
            if (window.hmjDecorateTables) { window.hmjDecorateTables(doc); }
            regions.forEach(function (cur) {
                var fresh = doc.querySelector('[data-live="' + cur.getAttribute('data-live') + '"]');
                if (!fresh || userIsBusyIn(cur)) { return; }
                if (fresh.innerHTML !== cur.innerHTML) {
                    var y = window.scrollY;
                    cur.innerHTML = fresh.innerHTML;
                    window.scrollTo(0, y);
                    if (cur.hasAttribute('data-live-scroll')) { cur.scrollTop = cur.scrollHeight; }
                }
            });
        }).catch(function () {});
    }

    function run() {
        if (document.hidden) { return Promise.resolve(); }
        if (busy) { again = true; return Promise.resolve(); }
        busy = true;
        lastRun = Date.now();
        return Promise.all([pollStatus(), refreshRegions()]).then(function () { busy = false; }, function () { busy = false; })
            .then(function () { if (again) { again = false; run(); } });
    }

    function tick() {
        if (document.hidden) { return; }
        if (sseOK && Date.now() - lastRun < BACKUP) { return; } // SSE aktif: cukup jaring pengaman
        run();
    }

    /* ---------- Real-time (SSE) ---------- */
    function stopStream() {
        if (es) { es.close(); es = null; }
        sseOK = false;
        if (esRetry) { clearTimeout(esRetry); esRetry = null; }
    }

    function startStream() {
        if (!window.EventSource || es || document.hidden) { return; }
        es = new EventSource(base + '/api/stream.php');
        es.addEventListener('hello', function (ev) {
            sseOK = true;
            var d = null;
            try { d = JSON.parse(ev.data); } catch (x) {}
            // Ada perubahan selama koneksi terputus / menyambung ulang -> segarkan sekarang juga
            if (d && (d.nid > lastId || (lastVer !== null && d.v !== lastVer))) { run(); }
            if (d) { lastVer = d.v; }
        });
        es.addEventListener('change', function (ev) {
            var d = null;
            try { d = JSON.parse(ev.data); } catch (x) {}
            if (d && d.v) { lastVer = d.v; }
            onChange(d);
        });
        es.onerror = function () {
            sseOK = false;
            if (es && es.readyState === 2) { // ditutup permanen (mis. 401 / server mati)
                es = null;
                pollStatus();                 // 401 -> diarahkan ke halaman login
                esRetry = setTimeout(function () { esRetry = null; startStream(); }, 5000);
            }                                  // selain itu browser menyambung ulang sendiri
        };
    }

    ['mousedown', 'mousemove', 'keydown', 'touchstart', 'wheel', 'scroll', 'click'].forEach(function (ev) {
        window.addEventListener(ev, onActivity, { passive: true, capture: true });
    });

    pollStatus();
    startStream();
    setInterval(tick, INTERVAL);
    setInterval(renderWarn, 1000);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopStream();                    // tab tidak terlihat: hemat koneksi server
        } else {
            acted = true;
            startStream();
            run();
        }
    });
    window.addEventListener('beforeunload', stopStream);
})();
