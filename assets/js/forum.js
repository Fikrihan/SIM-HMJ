/**
 * Forum diskusi: balas komentar tertentu + reaksi emoji.
 * Memakai delegasi event pada document supaya tetap jalan setelah isi utas disegarkan otomatis (live.js).
 */
(function () {
    var app = document.getElementById('forumApp');
    if (!app) { return; }
    var csrf = app.getAttribute('data-csrf') || '';
    var pending = false;

    function closePops(except) {
        document.querySelectorAll('.react-pop.open').forEach(function (p) {
            if (p !== except) { p.classList.remove('open'); }
        });
    }

    /* ---------- Reaksi emoji ---------- */
    function sendReaction(bar, emoji) {
        if (pending) { return; }
        pending = true;
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'reaksi');
        fd.append('post_id', bar.getAttribute('data-target') || '0');
        fd.append('emoji', emoji);
        fetch(window.location.pathname + window.location.search, {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Ajax': '1' }, cache: 'no-store'
        }).then(function (r) { return r.ok ? r.json() : null; })
          .then(function (d) {
              if (!d || !d.ok) { return; }
              var cur = document.querySelector('.react-bar[data-target="' + d.target + '"]');
              if (cur) {
                  var tmp = document.createElement('div');
                  tmp.innerHTML = d.html;
                  cur.parentNode.replaceChild(tmp.firstChild, cur);
              }
          })
          .catch(function () {})
          .then(function () { pending = false; });
    }

    /* ---------- Balas komentar ---------- */
    var form = document.getElementById('replyCtx') ? document.getElementById('replyCtx').closest('form') : null;
    var ctx = document.getElementById('replyCtx');
    var pid = document.getElementById('parentId');
    var nameEl = document.getElementById('replyNama');
    var textEl = document.getElementById('replyText');

    function setReply(id, nama, text) {
        if (!ctx || !pid) { return; }
        pid.value = id || '';
        if (id) {
            nameEl.textContent = nama;
            textEl.textContent = text ? ': ' + text : '';
            ctx.removeAttribute('hidden');
        } else {
            ctx.setAttribute('hidden', '');
            nameEl.textContent = '';
            textEl.textContent = '';
        }
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (!t || !t.closest) { return; }

        var toggle = t.closest('.react-toggle');
        if (toggle) {
            var pop = toggle.parentNode.querySelector('.react-pop');
            var willOpen = !pop.classList.contains('open');
            closePops(pop);
            pop.classList.toggle('open', willOpen);
            return;
        }

        var emo = t.closest('.react-chip, .react-pop button');
        if (emo) {
            var bar = emo.closest('.react-bar');
            closePops(null);
            if (bar) { sendReaction(bar, emo.getAttribute('data-emoji')); }
            return;
        }

        var rb = t.closest('.reply-btn');
        if (rb) {
            setReply(rb.getAttribute('data-id'), rb.getAttribute('data-nama'), rb.getAttribute('data-text'));
            var box = document.getElementById('isi');
            if (box) {
                box.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(function () { box.focus({ preventScroll: true }); }, 250);
            }
            return;
        }

        if (t.closest('#replyCancel')) { setReply('', '', ''); return; }

        if (!t.closest('.react-add')) { closePops(null); }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { closePops(null); }
    });

    /* ---------- Kolom tulis: tinggi otomatis, hitung karakter, Ctrl+Enter kirim ---------- */
    var ta = document.getElementById('isi');
    var cnt = document.getElementById('isiCount');
    if (ta) {
        var fit = function () {
            ta.style.height = 'auto';
            ta.style.height = Math.min(ta.scrollHeight, 260) + 'px';
            if (cnt) { cnt.textContent = ta.value.length; }
        };
        ta.addEventListener('input', fit);
        ta.addEventListener('keydown', function (ev) {
            if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter' && ta.form && ta.value.trim() !== '') {
                ev.preventDefault();
                if (ta.form.requestSubmit) { ta.form.requestSubmit(); } else { ta.form.submit(); }
            }
        });
        fit();
    }

    // Setelah kirim, form dimuat ulang oleh server; pastikan konteks balasan bersih bila halaman dari cache
    if (form) { setReply('', '', ''); }
})();
