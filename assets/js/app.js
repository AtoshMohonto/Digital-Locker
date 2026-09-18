/**
 * Digital Locker - client side behaviour.
 */
(function () {
    'use strict';

    var body = document.body;

    /* ------------------------------------------------------------------
     * Responsive sidebar: smooth open/close states
     *  - Desktop: collapse to icon rail (persisted in localStorage)
     *  - Mobile : off-canvas with backdrop
     * ------------------------------------------------------------------ */
    var sidebarToggle = document.getElementById('sidebar-toggle');
    var backdrop = document.getElementById('sidebar-backdrop');
    var isMobile = function () { return window.matchMedia('(max-width: 767px)').matches; };

    function setExpanded(expanded) {
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    function closeSidebar() {
        body.classList.remove('sidebar-open');
        setExpanded(false);
    }

    /* Icon-rail (collapsed) mode hides group labels, so every group must stay
       expanded -- otherwise a closed <details> would hide its own icons too. */
    function openAllSidebarGroups() {
        document.querySelectorAll('.sidebar__group').forEach(function (d) { d.open = true; });
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            if (isMobile()) {
                var open = body.classList.toggle('sidebar-open');
                setExpanded(open);
            } else {
                var collapsed = body.classList.toggle('sidebar-collapsed');
                if (collapsed) {
                    openAllSidebarGroups();
                }
                try {
                    localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0');
                } catch (e) { /* private mode */ }
            }
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    window.addEventListener('keyup', function (e) {
        if (e.key === 'Escape') {
            closeSidebar();
        }
    });

    /* Restore persisted desktop state. */
    try {
        if (localStorage.getItem('sidebar-collapsed') === '1' && !isMobile()) {
            body.classList.add('sidebar-collapsed');
            setExpanded(false);
            openAllSidebarGroups();
        }
    } catch (e) { /* ignore */ }

    /* Keep desktop state coherent when resizing across breakpoints. */
    window.addEventListener('resize', function () {
        if (!isMobile()) {
            body.classList.remove('sidebar-open');
        }
    });

    /* ------------------------------------------------------------------
     * Topbar user menu (avatar dropdown)
     * ------------------------------------------------------------------ */
    var userMenu = document.getElementById('user-menu');
    var userMenuToggle = document.getElementById('user-menu-toggle');

    function closeUserMenu() {
        if (userMenu) {
            userMenu.classList.remove('is-open');
        }
        if (userMenuToggle) {
            userMenuToggle.setAttribute('aria-expanded', 'false');
        }
    }

    if (userMenuToggle && userMenu) {
        userMenuToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = userMenu.classList.toggle('is-open');
            userMenuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (!userMenu.contains(e.target)) {
                closeUserMenu();
            }
        });
        window.addEventListener('keyup', function (e) {
            if (e.key === 'Escape') {
                closeUserMenu();
            }
        });
    }

    /* ------------------------------------------------------------------
     * Password generator
     * ------------------------------------------------------------------ */
    document.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'generate-btn') {
            e.preventDefault();
            var input = document.getElementById('password');
            if (input) {
                input.value = generatePassword(18);
                input.focus();
            }
        }
    });

    function generatePassword(length) {
        var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var lower = 'abcdefghijkmnopqrstuvwxyz';
        var digits = '23456789';
        var special = '!@#$%^&*()-_=+[]{}';
        var all = upper + lower + digits + special;
        var sets = [upper, lower, digits, special];
        var out = '';
        sets.forEach(function (set) {
            out += set[Math.floor(Math.random() * set.length)];
        });
        while (out.length < length) {
            out += all[Math.floor(Math.random() * all.length)];
        }
        return shuffle(out);
    }

    function shuffle(str) {
        var a = str.split('');
        for (var i = a.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var t = a[i]; a[i] = a[j]; a[j] = t;
        }
        return a.join('');
    }

    /* ------------------------------------------------------------------
     * Reveal secret: single "Reveal" buttons (view page, table rows)
     * and the reveal-recovery button, all backed by the same ajax.php
     * endpoint, which returns { secret, recovery }.
     * ------------------------------------------------------------------ */
    var MASK = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';

    function toggleReveal(btn, targetEl, url, field) {
        // Remember the button's original (masked-state) glyph/label the first time it's used.
        if (!btn.dataset.maskedLabel) {
            btn.dataset.maskedLabel = btn.textContent;
        }
        var isIconButton = btn.dataset.maskedLabel === '\ud83d\udc41';
        var revealedLabel = isIconButton ? '\ud83d\ude48' : 'Hide';

        if (targetEl.dataset.revealed === '1') {
            targetEl.textContent = MASK;
            targetEl.dataset.revealed = '0';
            btn.textContent = btn.dataset.maskedLabel;
            return;
        }

        var askConfirm = body.dataset.confirmReveal === '1';
        if (askConfirm && !window.confirm('Reveal this secret?')) {
            return;
        }

        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var value = data[field];
                if (value) {
                    targetEl.textContent = value;
                    targetEl.dataset.revealed = '1';
                    btn.textContent = revealedLabel;
                } else {
                    window.alert(data.error || 'Unable to reveal secret.');
                }
            })
            .catch(function () {
                window.alert('Request failed.');
            });
    }

    var revealBtn = document.getElementById('reveal-btn');
    var secretEl = document.getElementById('secret-value');
    if (revealBtn && secretEl) {
        revealBtn.addEventListener('click', function () {
            toggleReveal(revealBtn, secretEl, revealBtn.dataset.url, 'secret');
        });
    }

    var revealRecoveryBtn = document.getElementById('reveal-recovery-btn');
    var recoveryEl = document.getElementById('recovery-value');
    if (revealRecoveryBtn && recoveryEl) {
        revealRecoveryBtn.addEventListener('click', function () {
            toggleReveal(revealRecoveryBtn, recoveryEl, revealRecoveryBtn.dataset.url, 'recovery');
        });
    }

    // Vault list: one reveal button per row (event delegation, rows are repeated markup).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.reveal-row-btn') : null;
        if (!btn) { return; }
        var targetEl = document.getElementById(btn.dataset.target);
        if (!targetEl) { return; }
        toggleReveal(btn, targetEl, btn.dataset.url, 'secret');
    });

    /* ------------------------------------------------------------------
     * Copy to clipboard
     * ------------------------------------------------------------------ */
    function copyText(text, btn) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                var original = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = original; }, 1500);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (err) { /* noop */ }
            document.body.removeChild(ta);
        }
    }

    document.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'copy-btn') {
            var target = document.getElementById(e.target.dataset.clipTarget);
            if (!target) { return; }

            // Already revealed on screen: copy what's shown.
            if (target.dataset.revealed === '1') {
                copyText(target.textContent, e.target);
                return;
            }

            // Not revealed yet: fetch the real secret so Copy never sends the masked dots.
            if (!revealBtn || !revealBtn.dataset.url) {
                copyText(target.textContent, e.target);
                return;
            }

            var askConfirm = body.dataset.confirmReveal === '1';
            if (askConfirm && !window.confirm('Copy this secret to the clipboard?')) {
                return;
            }

            fetch(revealBtn.dataset.url, { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.secret) {
                        copyText(data.secret, e.target);
                    } else {
                        window.alert(data.error || 'Unable to copy secret.');
                    }
                })
                .catch(function () {
                    window.alert('Request failed.');
                });
        }
    });

    /* ------------------------------------------------------------------
     * Auto-lock after inactivity (uses auto_lock_minutes setting)
     * ------------------------------------------------------------------ */
    var autoLockMinutes = parseInt(body.dataset.autolock || '0', 10);

    if (autoLockMinutes > 0) {
        var timeout = null;
        var scheduleLock = function () {
            if (timeout) { window.clearTimeout(timeout); }
            timeout = window.setTimeout(function () {
                window.location.href = body.dataset.lockUrl || 'lock.php';
            }, autoLockMinutes * 60 * 1000);
        };

        ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(function (evt) {
            document.addEventListener(evt, scheduleLock, { passive: true });
        });
        scheduleLock();
    }
})();
