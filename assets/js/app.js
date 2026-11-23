// Menu samping (HP): buka/tutup, tutup saat menyentuh latar atau memilih menu
(function () {
    var burger = document.getElementById('burger');
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');

    function setOpen(open) {
        if (!sidebar) { return; }
        sidebar.classList.toggle('open', open);
        if (backdrop) { backdrop.classList.toggle('show', open); }
        document.body.classList.toggle('nav-open', open);
        if (burger) { burger.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }
    if (burger && sidebar) {
        burger.addEventListener('click', function () { setOpen(!sidebar.classList.contains('open')); });
        if (backdrop) { backdrop.addEventListener('click', function () { setOpen(false); }); }
        sidebar.addEventListener('click', function (ev) {
            var a = ev.target.closest ? ev.target.closest('a') : null;
            if (a) { setOpen(false); }
        });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { setOpen(false); } });
        window.addEventListener('resize', function () { if (window.innerWidth > 900) { setOpen(false); } });
    }

    // Konfirmasi sebelum aksi berbahaya: <form data-confirm="Yakin?">
    // Memakai delegasi event supaya tetap jalan pada konten yang diperbarui otomatis.
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (form && form.getAttribute && form.getAttribute('data-confirm')) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                ev.preventDefault();
            }
        }
    });

    // Tutup alert otomatis setelah beberapa detik
    document.querySelectorAll('.alert-success').forEach(function (el) {
        setTimeout(function () { el.style.display = 'none'; }, 6000);
    });

    // Tabel -> kartu di layar kecil: beri tiap sel label dari judul kolomnya (dipakai CSS).
    // Aman dipanggil berulang; live.js juga memanggilnya pada konten hasil pembaruan otomatis.
    window.hmjDecorateTables = function (root) {
        root.querySelectorAll('table').forEach(function (t) {
            var ths = t.querySelectorAll('thead th');
            if (!ths.length) { return; }
            var labels = [];
            ths.forEach(function (th) { labels.push(th.textContent.replace(/\s+/g, ' ').trim()); });
            t.querySelectorAll('tbody tr').forEach(function (tr) {
                var i = 0;
                Array.prototype.forEach.call(tr.children, function (td) {
                    if (td.tagName !== 'TD') { return; }
                    if (!td.hasAttribute('data-label')) { td.setAttribute('data-label', labels[i] || ''); }
                    i += td.colSpan || 1;
                });
            });
        });
    };
    window.hmjDecorateTables(document);
})();
