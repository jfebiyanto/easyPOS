document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('menu-toggle');
    var drawer = document.getElementById('nav-drawer');
    var scrim = document.getElementById('scrim');

    if (toggle && drawer) {
        toggle.addEventListener('click', function () {
            drawer.classList.toggle('open');
            scrim.classList.toggle('open');
        });
        if (scrim) {
            scrim.addEventListener('click', function () {
                drawer.classList.remove('open');
                scrim.classList.remove('open');
            });
        }
    }

    // Auto-dismiss snackbars
    document.querySelectorAll('.md-snackbar').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .4s ease';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 450);
        }, 4000);
    });

    // Sidebar accordion: status buka/tutup awal ditentukan server (hanya grup halaman aktif yang terbuka).
    document.querySelectorAll('.nav-group').forEach(function (group) {
        group.querySelector('.nav-group-header').addEventListener('click', function () {
            var collapsed = group.classList.toggle('collapsed');
            this.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
    });

    // Simpan posisi scroll sidebar agar tidak kembali ke atas saat pindah halaman.
    if (drawer) {
        window.addEventListener('pagehide', function () {
            try { sessionStorage.setItem('easypos.nav-scroll', String(drawer.scrollTop)); } catch (e) {}
        });
    }

    // Form promosi: tampilkan field & label sesuai tipe. Field tersembunyi di-disable agar tidak wajib/terkirim.
    var promoType = document.querySelector('[data-promo-type]');
    if (promoType) {
        var applyPromoType = function () {
            var type = promoType.value;
            document.querySelectorAll('[data-promo-types]').forEach(function (field) {
                var visible = field.getAttribute('data-promo-types').split(' ').indexOf(type) !== -1;
                field.hidden = !visible;
                field.querySelectorAll('input, select, textarea').forEach(function (input) { input.disabled = !visible; });
            });
            document.querySelectorAll('[data-promo-labels]').forEach(function (label) {
                var labels = JSON.parse(label.getAttribute('data-promo-labels'));
                if (labels[type]) { label.textContent = labels[type]; }
            });
        };
        promoType.addEventListener('change', applyPromoType);
        applyPromoType();
    }

    // Dropdown sederhana (mis. store aktif di top bar): klik tombol untuk buka/tutup, klik di luar atau Esc untuk menutup.
    var closeDropdowns = function (except) {
        document.querySelectorAll('[data-dropdown]').forEach(function (dropdown) {
            if (dropdown === except) { return; }
            dropdown.querySelector('[data-dropdown-panel]').hidden = true;
            dropdown.querySelector('[data-dropdown-toggle]').setAttribute('aria-expanded', 'false');
        });
    };
    document.querySelectorAll('[data-dropdown]').forEach(function (dropdown) {
        var toggleBtn = dropdown.querySelector('[data-dropdown-toggle]');
        var panel = dropdown.querySelector('[data-dropdown-panel]');
        toggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeDropdowns(dropdown);
            panel.hidden = !panel.hidden;
            toggleBtn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        });
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
    });
    document.addEventListener('click', function () { closeDropdowns(null); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeDropdowns(null); } });

    // Status bar bawah: tanggal bisnis & jam berjalan dalam zona waktu perusahaan, disinkronkan ke jam server.
    var businessClock = document.querySelector('[data-business-clock]');
    if (businessClock && window.Intl) {
        var timeZone = businessClock.getAttribute('data-timezone');
        var offset = new Date(businessClock.getAttribute('data-server-time')).getTime() - Date.now() || 0;
        var dateEl = businessClock.querySelector('[data-business-date]');
        var timeEl = businessClock.querySelector('[data-business-time]');
        var dateFormat = new Intl.DateTimeFormat('id-ID', { timeZone: timeZone, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        var timeFormat = new Intl.DateTimeFormat('id-ID', { timeZone: timeZone, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZoneName: 'short' });
        var tick = function () {
            var now = new Date(Date.now() + offset);
            dateEl.textContent = dateFormat.format(now);
            timeEl.textContent = timeFormat.format(now).replace(/\./g, ':');
        };
        tick();
        setInterval(tick, 1000);
    }

    try { localStorage.removeItem('easypos.nav-sections'); } catch (e) {}
});