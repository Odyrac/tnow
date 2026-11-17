// Transfer Now — composants d'interface custom (onglets, select, scrollbar, modale)
(function () {
    'use strict';

    // --- Pas de glisser des images / liens --------------------------------
    document.addEventListener('dragstart', (e) => {
        if (e.target.closest && e.target.closest('img, a, [draggable="false"]')) {
            e.preventDefault();
        }
    });

    // --- Scrollbar custom ---------------------------------------------------
    // scroller : élément qui défile (document.scrollingElement pour la page)
    // host : élément dans lequel la barre est insérée (body pour la page)
    function scrollbar(scroller, host) {
        const isPage = scroller === document.scrollingElement;
        const track = document.createElement('div');
        track.className = 'tn-scrollbar is-hidden' + (isPage ? '' : ' tn-scrollbar--inner');
        track.setAttribute('aria-hidden', 'true');
        const thumb = document.createElement('div');
        thumb.className = 'tn-scrollbar-thumb';
        track.appendChild(thumb);
        host.appendChild(track);

        let scrollingTimer = null;

        function viewport() {
            return isPage ? window.innerHeight : scroller.clientHeight;
        }

        function metrics() {
            const scrollHeight = scroller.scrollHeight;
            const view = viewport();
            const trackHeight = track.clientHeight;
            const thumbHeight = Math.max(32, trackHeight * view / scrollHeight);
            return {
                maxScroll: scrollHeight - view,
                maxTop: trackHeight - thumbHeight,
                thumbHeight: thumbHeight,
            };
        }

        function update() {
            if (scroller.scrollHeight <= viewport() + 1) {
                track.classList.add('is-hidden');
                return;
            }
            track.classList.remove('is-hidden');
            const m = metrics();
            const top = m.maxScroll > 0 ? (scroller.scrollTop / m.maxScroll) * m.maxTop : 0;
            thumb.style.height = m.thumbHeight + 'px';
            thumb.style.transform = 'translateY(' + top + 'px)';
        }

        function onScroll() {
            update();
            track.classList.add('is-scrolling');
            clearTimeout(scrollingTimer);
            scrollingTimer = setTimeout(() => track.classList.remove('is-scrolling'), 700);
        }

        // Glisser le curseur
        thumb.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const startY = e.clientY;
            const startScroll = scroller.scrollTop;
            const m = metrics();
            thumb.setPointerCapture(e.pointerId);
            track.classList.add('is-dragging');
            document.documentElement.classList.add('is-dragging-scrollbar');

            function move(ev) {
                const ratio = m.maxTop > 0 ? m.maxScroll / m.maxTop : 0;
                scroller.scrollTop = startScroll + (ev.clientY - startY) * ratio;
            }

            function up() {
                thumb.removeEventListener('pointermove', move);
                thumb.removeEventListener('pointerup', up);
                thumb.removeEventListener('pointercancel', up);
                track.classList.remove('is-dragging');
                document.documentElement.classList.remove('is-dragging-scrollbar');
            }

            thumb.addEventListener('pointermove', move);
            thumb.addEventListener('pointerup', up);
            thumb.addEventListener('pointercancel', up);
        });

        // Clic sur le rail : centre le curseur à cet endroit
        track.addEventListener('pointerdown', (e) => {
            if (e.target === thumb) return;
            e.preventDefault();
            const m = metrics();
            const rect = track.getBoundingClientRect();
            const top = Math.min(Math.max(e.clientY - rect.top - m.thumbHeight / 2, 0), m.maxTop);
            scroller.scrollTo({ top: m.maxTop > 0 ? (top / m.maxTop) * m.maxScroll : 0, behavior: 'smooth' });
        });

        (isPage ? window : scroller).addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', update);
        if ('ResizeObserver' in window) {
            const ro = new ResizeObserver(update);
            ro.observe(isPage ? document.body : scroller);
        }
        if (!isPage) {
            scroller.addEventListener('input', update);
        }
        update();

        return { update: update };
    }

    // --- Onglets ----------------------------------------------------------
    function tabs(root) {
        const buttons = Array.from(root.querySelectorAll('.tn-tab'));

        function activate(index, focus) {
            root.dataset.active = String(index);
            buttons.forEach((btn, i) => {
                const panel = document.getElementById(btn.getAttribute('aria-controls'));
                const active = i === index;
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
                btn.tabIndex = active ? 0 : -1;
                if (active && panel.hidden) {
                    panel.hidden = false;
                    panel.classList.remove('is-entering');
                    void panel.offsetWidth;
                    panel.classList.add('is-entering');
                } else if (!active) {
                    panel.hidden = true;
                }
            });
            if (focus) buttons[index].focus();
        }

        buttons.forEach((btn, i) => {
            btn.addEventListener('click', () => activate(i, false));
            btn.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                    e.preventDefault();
                    const dir = e.key === 'ArrowRight' ? 1 : -1;
                    activate((i + dir + buttons.length) % buttons.length, true);
                }
            });
        });
    }

    // --- Select custom ----------------------------------------------------
    const selects = [];

    function select(root) {
        const input = root.querySelector('input[type="hidden"]');
        const trigger = root.querySelector('.tn-select-trigger');
        const valueEl = root.querySelector('.tn-select-value');
        const menu = root.querySelector('.tn-select-menu');
        const options = Array.from(root.querySelectorAll('.tn-select-option'));
        let activeIndex = Math.max(0, options.findIndex(o => o.dataset.value === input.value));

        function setActive(index) {
            activeIndex = index;
            options.forEach((o, i) => o.classList.toggle('is-active', i === index));
        }

        function setValue(value, propagate) {
            const option = options.find(o => o.dataset.value === value);
            if (!option) return;
            input.value = value;
            valueEl.textContent = option.textContent.trim();
            options.forEach(o => o.setAttribute('aria-selected', o === option ? 'true' : 'false'));
            if (propagate && root.dataset.sync) {
                selects.forEach(s => {
                    if (s.root !== root && s.root.dataset.sync === root.dataset.sync) {
                        s.setValue(value, false);
                    }
                });
            }
        }

        function open() {
            selects.forEach(s => s !== api && s.close());
            // Ouvre vers le haut s'il n'y a pas la place en dessous
            const rect = trigger.getBoundingClientRect();
            root.classList.toggle('is-up', window.innerHeight - rect.bottom < menu.offsetHeight + 16 && rect.top > menu.offsetHeight + 16);
            root.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            setActive(Math.max(0, options.findIndex(o => o.dataset.value === input.value)));
        }

        function close() {
            root.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
            options.forEach(o => o.classList.remove('is-active'));
        }

        function isOpen() {
            return root.classList.contains('is-open');
        }

        trigger.addEventListener('click', () => (isOpen() ? close() : open()));

        trigger.addEventListener('keydown', (e) => {
            switch (e.key) {
                case 'ArrowDown':
                case 'ArrowUp':
                    e.preventDefault();
                    if (!isOpen()) {
                        open();
                    } else {
                        const dir = e.key === 'ArrowDown' ? 1 : -1;
                        setActive((activeIndex + dir + options.length) % options.length);
                    }
                    break;
                case 'Home':
                    if (isOpen()) {
                        e.preventDefault();
                        setActive(0);
                    }
                    break;
                case 'End':
                    if (isOpen()) {
                        e.preventDefault();
                        setActive(options.length - 1);
                    }
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    if (isOpen()) {
                        setValue(options[activeIndex].dataset.value, true);
                        close();
                    } else {
                        open();
                    }
                    break;
                case 'Escape':
                    if (isOpen()) {
                        e.preventDefault();
                        close();
                    }
                    break;
                case 'Tab':
                    close();
                    break;
            }
        });

        options.forEach((option, i) => {
            option.addEventListener('mouseenter', () => setActive(i));
            option.addEventListener('click', () => {
                setValue(option.dataset.value, true);
                close();
                trigger.focus();
            });
        });

        const api = { root: root, setValue: setValue, close: close };
        selects.push(api);
        return api;
    }

    document.addEventListener('pointerdown', (e) => {
        selects.forEach(s => {
            if (!s.root.contains(e.target)) s.close();
        });
    });

    // --- Modale -----------------------------------------------------------
    function modal(root) {
        let lastFocus = null;
        const focusables = () => Array.from(root.querySelectorAll('button'));

        function open() {
            lastFocus = document.activeElement;
            root.classList.add('is-open');
            root.setAttribute('aria-hidden', 'false');
            const buttons = focusables();
            if (buttons.length) setTimeout(() => buttons[0].focus(), 50);
        }

        function close() {
            root.classList.remove('is-open');
            root.setAttribute('aria-hidden', 'true');
            if (lastFocus) lastFocus.focus();
        }

        root.querySelectorAll('[data-modal-close]').forEach(el => el.addEventListener('click', close));

        root.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                close();
            } else if (e.key === 'Tab') {
                // Garde le focus dans la modale
                const buttons = focusables();
                const first = buttons[0];
                const last = buttons[buttons.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        return { open: open, close: close };
    }

    // Scrollbar de la page, partout
    scrollbar(document.scrollingElement || document.documentElement, document.body);

    // --- Loader : on révèle la page une fois CSS, images et polices chargées
    let revealed = false;
    function reveal() {
        if (revealed) return;
        revealed = true;
        // Force le calcul des styles finaux (sans transition) avant de réactiver les animations
        void document.body.offsetHeight;
        document.documentElement.classList.remove('tn-preload');
        const loader = document.getElementById('tn-loader');
        if (loader) loader.classList.add('is-done');
        // Les scrollbars custom étaient masquées : on recalcule leurs dimensions
        window.dispatchEvent(new Event('resize'));
    }

    const pageLoaded = new Promise((resolve) => {
        if (document.readyState === 'complete') resolve();
        else window.addEventListener('load', resolve, { once: true });
    });
    Promise.all([pageLoaded, document.fonts ? document.fonts.ready : null]).then(reveal, reveal);
    setTimeout(reveal, 5000); // Filet de sécurité

    // Retour arrière (cache du navigateur) : la page ne doit pas rester masquée
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) reveal();
    });

    window.TN = { scrollbar: scrollbar, tabs: tabs, select: select, modal: modal };
})();
