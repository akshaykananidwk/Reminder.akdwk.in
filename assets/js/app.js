/* ==========================================================================
   Krishna Reminder — front-end behaviour (vanilla JS, no build step)
   Every network call has an explicit loading, success and error state.
   ========================================================================== */

(function () {
    'use strict';

    const KR = window.KR || {};
    window.KR = KR;

    KR.csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    KR.base = document.querySelector('meta[name="base-url"]')?.content || '';

    /* --------------------------------------------------------------- Toast */

    KR.toast = function (message, type) {
        let host = document.querySelector('.toasts');

        if (!host) {
            host = document.createElement('div');
            host.className = 'toasts';
            document.body.appendChild(host);
        }

        const el = document.createElement('div');
        el.className = 'toast ' + (type || '');
        el.textContent = message;
        el.setAttribute('role', 'status');
        host.appendChild(el);

        setTimeout(function () {
            el.style.opacity = '0';
            el.style.transition = 'opacity .3s';
            setTimeout(() => el.remove(), 320);
        }, 3600);
    };

    /* ---------------------------------------------------------------- HTTP */

    KR.request = async function (url, options) {
        options = options || {};

        const headers = Object.assign({
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }, options.headers || {});

        if (options.json) {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = KR.csrf;
            options.body = JSON.stringify(options.json);
            options.method = options.method || 'POST';
        }

        if (options.form) {
            headers['X-CSRF-Token'] = KR.csrf;
            options.body = options.form;
            options.method = options.method || 'POST';
        }

        try {
            const response = await fetch(url, {
                method: options.method || 'GET',
                headers: headers,
                body: options.body,
                credentials: 'same-origin'
            });

            const text = await response.text();
            let data = null;

            try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }

            if (!response.ok) {
                const message = (data && data.message) || ('Request failed (' + response.status + ')');
                throw new Error(message);
            }

            return data;
        } catch (error) {
            throw error instanceof Error ? error : new Error('Network error');
        }
    };

    /* ------------------------------------------------------- Button states */

    KR.busy = function (button, isBusy) {
        if (!button) return;

        if (isBusy) {
            button.dataset.label = button.innerHTML;
            button.classList.add('is-loading');
            button.disabled = true;
        } else {
            button.classList.remove('is-loading');
            button.disabled = false;
            if (button.dataset.label) button.innerHTML = button.dataset.label;
        }
    };

    /* --------------------------------------------------------------- Theme */

    KR.setTheme = function (theme) {
        const resolved = theme === 'auto'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : theme;

        document.documentElement.setAttribute('data-theme', resolved);
        try { localStorage.setItem('kr-theme', theme); } catch (e) { /* private mode */ }
    };

    (function initTheme() {
        let stored = 'auto';
        try { stored = localStorage.getItem('kr-theme') || 'auto'; } catch (e) { /* ignore */ }
        KR.setTheme(stored);
    })();

    /* ------------------------------------------------------------- Countdown */

    function tickCountdowns() {
        document.querySelectorAll('[data-countdown]').forEach(function (el) {
            const target = new Date(el.dataset.countdown).getTime();

            if (isNaN(target)) return;

            let diff = Math.floor((target - Date.now()) / 1000);

            if (diff <= 0) {
                el.textContent = el.dataset.dueLabel || '00:00:00';
                return;
            }

            const d = Math.floor(diff / 86400);
            diff -= d * 86400;
            const h = Math.floor(diff / 3600);
            diff -= h * 3600;
            const m = Math.floor(diff / 60);
            const s = diff - m * 60;

            const pad = (n) => String(n).padStart(2, '0');
            el.textContent = (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s);
        });
    }

    setInterval(tickCountdowns, 1000);
    tickCountdowns();

    /* ------------------------------------------------------ Occurrence actions */

    document.addEventListener('click', async function (event) {
        const trigger = event.target.closest('[data-action]');

        if (!trigger) return;

        const action = trigger.dataset.action;

        /* ---- Theme toggle ---- */
        if (action === 'toggle-theme') {
            event.preventDefault();
            const current = document.documentElement.getAttribute('data-theme');
            KR.setTheme(current === 'dark' ? 'light' : 'dark');
            return;
        }

        /* ---- Copy to clipboard ---- */
        if (action === 'copy') {
            event.preventDefault();
            const value = trigger.dataset.value || '';

            try {
                await navigator.clipboard.writeText(value);
                KR.toast(trigger.dataset.copied || 'Copied!', 'success');
            } catch (e) {
                KR.toast('Could not copy — please select and copy manually.', 'error');
            }
            return;
        }

        /* ---- Modal open/close ---- */
        if (action === 'open-modal') {
            event.preventDefault();
            const modal = document.getElementById(trigger.dataset.target);
            if (modal) modal.classList.remove('hide');
            return;
        }

        if (action === 'close-modal') {
            event.preventDefault();
            const backdrop = trigger.closest('.modal-backdrop');
            if (backdrop) backdrop.classList.add('hide');
            return;
        }

        /* ---- Confirm-then-submit ---- */
        if (action === 'confirm') {
            if (!window.confirm(trigger.dataset.message || 'Are you sure?')) {
                event.preventDefault();
            }
            return;
        }

        /* ---- Reminder occurrence: done / snooze ---- */
        if (action === 'done' || action === 'snooze') {
            event.preventDefault();

            const id = trigger.dataset.occurrence;
            if (!id) return;

            KR.busy(trigger, true);

            try {
                const payload = { action: action };

                if (action === 'snooze') {
                    payload.minutes = parseInt(trigger.dataset.minutes || '5', 10);
                }

                const result = await KR.request(KR.base + '/client/occurrences/' + id + '/action', { json: payload });
                KR.toast(result.message || 'Done', 'success');

                const row = trigger.closest('[data-row]');

                if (row && action === 'done') {
                    row.classList.add('done');
                    trigger.remove();
                } else {
                    setTimeout(() => window.location.reload(), 700);
                }
            } catch (error) {
                KR.toast(error.message, 'error');
            } finally {
                KR.busy(trigger, false);
            }
            return;
        }
    });

    /* -------------------------------------------------- Natural-language add */

    const quickForm = document.getElementById('quick-add-form');

    if (quickForm) {
        quickForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            const input = quickForm.querySelector('[name="text"]');
            const button = quickForm.querySelector('button[type="submit"]');
            const preview = document.getElementById('quick-add-preview');
            const text = (input.value || '').trim();

            if (!text) return;

            KR.busy(button, true);

            if (preview) {
                preview.innerHTML = '<div class="skeleton skeleton-line" style="width:70%"></div><div class="skeleton skeleton-line" style="width:45%"></div>';
                preview.classList.remove('hide');
            }

            try {
                const result = await KR.request(KR.base + '/client/reminders/parse', { json: { text: text, create: true } });

                KR.toast(result.message || 'Reminder created', 'success');
                input.value = '';

                if (preview) preview.classList.add('hide');

                setTimeout(() => window.location.reload(), 600);
            } catch (error) {
                if (preview) preview.classList.add('hide');
                KR.toast(error.message, 'error');
            } finally {
                KR.busy(button, false);
            }
        });
    }

    /* ------------------------------------------------------------ OTP timer */

    document.querySelectorAll('[data-otp-timer]').forEach(function (el) {
        let seconds = parseInt(el.dataset.otpTimer, 10) || 60;
        const button = document.querySelector('[data-otp-resend]');

        if (button) button.disabled = true;

        const timer = setInterval(function () {
            seconds--;
            el.textContent = seconds + 's';

            if (seconds <= 0) {
                clearInterval(timer);
                el.textContent = '';
                if (button) button.disabled = false;
            }
        }, 1000);
    });

    /* -------------------------------------------------------- OTP auto-jump */

    const otpInput = document.querySelector('.otp-input');

    if (otpInput) {
        otpInput.addEventListener('input', function () {
            otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 6);

            if (otpInput.value.length === 6) {
                otpInput.form?.requestSubmit();
            }
        });
    }

    /* ------------------------------------------------------- Cookie consent */

    (function cookieConsent() {
        const banner = document.querySelector('.cookie-banner');

        if (!banner) return;

        let accepted = false;
        try { accepted = localStorage.getItem('kr-cookies') === '1'; } catch (e) { /* ignore */ }

        if (accepted) {
            banner.remove();
            return;
        }

        banner.classList.remove('hide');
        banner.querySelector('[data-accept-cookies]')?.addEventListener('click', function () {
            try { localStorage.setItem('kr-cookies', '1'); } catch (e) { /* ignore */ }
            banner.remove();
        });
    })();

    /* ------------------------------------------------------- Bulk selection */

    const bulkForm = document.getElementById('bulk-form');

    if (bulkForm) {
        const counter = document.getElementById('bulk-count');
        const bar = document.getElementById('bulk-bar');

        bulkForm.addEventListener('change', function () {
            const checked = bulkForm.querySelectorAll('input[name="ids[]"]:checked').length;

            if (counter) counter.textContent = String(checked);
            if (bar) bar.classList.toggle('hide', checked === 0);
        });
    }

    /* ------------------------------------------------ Service worker (PWA) */

    if ('serviceWorker' in navigator && location.protocol === 'https:') {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(KR.base + '/sw.js').catch(function () {
                /* Offline support is a progressive enhancement. */
            });
        });
    }
})();
