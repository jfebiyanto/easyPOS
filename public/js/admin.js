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

    // Sidebar accordion: expand/collapse sections with state persistence
    var STORAGE_KEY = 'easypos.nav-sections';

    function readState() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {}; }
        catch (e) { return {}; }
    }

    function writeState(state) {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
    }

    var state = readState();
    var groups = document.querySelectorAll('.nav-group');

    groups.forEach(function (group) {
        var section = group.getAttribute('data-section');
        var hasActive = !!group.querySelector('a.active');

        // Item aktif selalu membuka section-nya (menimpa preferensi tersimpan)
        if (hasActive) {
            state[section] = true;
        }

        if (state[section] === false) {
            group.classList.add('collapsed');
            group.querySelector('.nav-group-header').setAttribute('aria-expanded', 'false');
        }
    });
    writeState(state);

    groups.forEach(function (group) {
        group.querySelector('.nav-group-header').addEventListener('click', function () {
            var section = group.getAttribute('data-section');
            var collapsed = group.classList.toggle('collapsed');
            this.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            state[section] = !collapsed;
            writeState(state);
        });
    });
});