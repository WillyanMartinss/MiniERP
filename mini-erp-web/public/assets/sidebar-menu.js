'use strict';
    // Drawer hamburger behavior
    (function () {
        const hamburger = document.getElementById('hamburger');
        const drawer = document.getElementById('sidebar-drawer');
        const backdrop = document.getElementById('drawer-backdrop');
        const closeButton = drawer?.querySelector('[data-drawer-close]');
        // drawerClose button removed; compact mode on desktop will be handled by CSS/JS
        if (!hamburger || !drawer || !backdrop) return;

        function isMobile() { return window.innerWidth < 900; }

        function openDrawer() {
            drawer.classList.add('open');
            backdrop.classList.add('open');
            drawer.setAttribute('aria-hidden', 'false');
            hamburger.setAttribute('aria-expanded', 'true');
            document.body.classList.add('mobile-menu-open');
            resetSubmenus();
            closeButton?.focus({ preventScroll: true });
        }
        function closeDrawer(restoreFocus = true) {
            drawer.classList.remove('open');
            backdrop.classList.remove('open');
            drawer.setAttribute('aria-hidden', 'true');
            hamburger.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('mobile-menu-open');
            if (isMobile() && restoreFocus) hamburger.focus({ preventScroll: true });
        }

        // Toggle only on mobile; on desktop drawer stays fixed open via CSS
        hamburger.addEventListener('click', function () {
            if (!isMobile()) return;
            if (drawer.classList.contains('open')) closeDrawer(); else openDrawer();
        });
        backdrop.addEventListener('click', function () { if (isMobile()) closeDrawer(); });
        closeButton?.addEventListener('click', closeDrawer);
        drawer.querySelectorAll('a').forEach(link => link.addEventListener('click', function () {
            if (isMobile()) closeDrawer(false);
        }));
        window.addEventListener('pageshow', event => {
            if (event.persisted && isMobile()) closeDrawer(false);
        });

        const expandControl = drawer.querySelector('[data-drawer-expand]');
        const drawerSubmenus = Array.from(drawer.querySelectorAll('.drawer-submenu'));
        const mobileQuery = window.matchMedia('(max-width: 899px)');


        const mainMenuButtons = Array.from(drawer.querySelectorAll('.drawer-cats > .drawer-cat > .cat-toggle, .drawer-cats > .drawer-cat > .cat-link'));
        const selectionAnimations = new WeakMap();
        function selectMenuButton(button) {
            mainMenuButtons.forEach(item => {
                const selected = item === button;
                const changed = item.classList.contains('is-selected') !== selected;
                item.classList.toggle('is-selected', selected);
                item.classList.toggle('active', selected);
                if (changed) selectionAnimations.get(item)?.cancel();
                if (changed && selected && !isMobile() && drawer.classList.contains('compact')
                    && !drawer.classList.contains('expanded') && item.animate
                    && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    const animation = item.animate([
                        { opacity: 0.65, transform: 'translateX(-3px)' },
                        { opacity: 1, transform: 'translateX(0)' }
                    ], { duration: 280, easing: 'cubic-bezier(.22,1,.36,1)' });
                    selectionAnimations.set(item, animation);
                    animation.onfinish = () => selectionAnimations.delete(item);
                }
            });
        }
        const currentPage = new URL(window.location.href).searchParams.get('page') || 'dashboard';
        const currentMenuButton = mainMenuButtons.find(button =>
            Array.from(button.parentElement.querySelectorAll('a[href]')).some(link =>
                new URL(link.href, window.location.href).searchParams.get('page') === currentPage
            )
        ) || mainMenuButtons.find(button => button.classList.contains('active'));
        if (currentMenuButton) selectMenuButton(currentMenuButton);
        mainMenuButtons.forEach(button => {
            button.addEventListener('click', () => selectMenuButton(button), { capture: true });
            button.parentElement.querySelectorAll('.drawer-submenu a').forEach(link => {
                link.addEventListener('click', () => selectMenuButton(button), { capture: true });
            });
        });

        const compactDesktop = () => !isMobile() && drawer.classList.contains('compact') && !drawer.classList.contains('expanded');
        const submenuAnimations = new WeakMap();
        function setSubmenuOpen(ul, open) {
            if (ul.classList.contains('open') === open) return;
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const revealDelay = open && compactDesktop() && !reducedMotion ? 120 : 0;
            ul.style.setProperty('--submenu-reveal-delay', revealDelay + 'ms');
            const toggle = ul.previousElementSibling;
            if (revealDelay && toggle?.animate) {
                selectionAnimations.get(toggle)?.cancel();
                const response = toggle.animate([
                    { transform: 'scale(1)', transformOrigin: 'center top' },
                    { transform: 'scale(.97)', transformOrigin: 'center top', offset: .4 },
                    { transform: 'scale(1)', transformOrigin: 'center top' }
                ], { duration: 120, easing: 'ease-out' });
                selectionAnimations.set(toggle, response);
                response.onfinish = () => selectionAnimations.delete(toggle);
            }
            const height = ul.getBoundingClientRect().height;
            submenuAnimations.get(ul)?.cancel();
            submenuAnimations.delete(ul);
            ul.style.height = height + 'px';
            // Commit the current rendered height before changing the transition target.
            void ul.offsetHeight;
            ul.classList.toggle('open', open);
            ul.classList.toggle('closing', !open);
            ul.inert = !open;
            ul.previousElementSibling?.setAttribute('aria-expanded', String(open));
            Array.from(ul.children).forEach((item, index) => {
                item.style.setProperty('--submenu-item-delay', Math.min(20 + index * 25, 80) + 'ms');
            });
            if (reducedMotion) {
                ul.style.height = open ? 'auto' : '0px';
                ul.classList.remove('closing');
                return;
            }
            let frame = null, timer = null;
            const finish = () => {
                if (submenuAnimations.get(ul) !== pending) return;
                pending.cancel();
                ul.style.height = open ? 'auto' : '0px';
                ul.classList.remove('closing');
                submenuAnimations.delete(ul);
            };
            const onEnd = event => {
                if (event.target === ul && event.propertyName === 'height') finish();
            };
            const pending = { cancel: () => {
                window.cancelAnimationFrame(frame);
                clearTimeout(timer);
                ul.removeEventListener('transitionend', onEnd);
            } };
            submenuAnimations.set(ul, pending);
            ul.addEventListener('transitionend', onEnd);
            frame = window.requestAnimationFrame(() => {
                if (submenuAnimations.get(ul) !== pending) return;
                ul.style.height = open ? ul.scrollHeight + 'px' : '0px';
                // Covers equal-height transitions and browsers that omit transitionend.
                timer = setTimeout(finish, 370 + revealDelay);
            });
        }
        function showSubmenu(btn) {
            const ul = btn.nextElementSibling;
            if (!ul) return;
            selectMenuButton(btn);
            drawerSubmenus.forEach(other => {
                const open = other === ul;
                setSubmenuOpen(other, open);
            });
        }
        drawer.querySelectorAll('.cat-toggle').forEach(btn => {
            const ul = btn.nextElementSibling;
            const category = btn.closest('.drawer-cat');
            if (!ul || !category) return;
            const label = btn.textContent.trim();
            btn.setAttribute('aria-label', label);
            ul.dataset.menuLabel = label;
            btn.addEventListener('mouseenter', () => {
                if (compactDesktop()) drawer.classList.add('hover-expanded');
            });
            btn.addEventListener('click', () => {
                if (ul.classList.contains('open')) setSubmenuOpen(ul, false);
                else showSubmenu(btn);
            });
            btn.addEventListener('keydown', event => {
                if (!['ArrowDown', 'ArrowRight'].includes(event.key) || !compactDesktop()) return;
                event.preventDefault();
                showSubmenu(btn);
                ul.querySelector('a[href]')?.focus();
            });
        });
        drawer.addEventListener('keydown', event => {
            if (event.key !== 'Escape' || !compactDesktop()) return;
            const toggle = drawerSubmenus.find(ul => ul.classList.contains('open'))?.previousElementSibling;
            closeAllSubmenus();
            toggle?.focus();
        });

        function resetSubmenus() {
            drawerSubmenus.forEach(ul => {
                submenuAnimations.get(ul)?.cancel();
                submenuAnimations.delete(ul);
                ul.classList.remove('open');
                ul.classList.remove('closing');
                ul.style.height = '0px';
                ul.inert = true;
                ul.previousElementSibling?.setAttribute('aria-expanded', 'false');
            });
        }

        function openAllSubmenus() {
            drawerSubmenus.forEach((ul) => {
                setSubmenuOpen(ul, true);
                const btn = ul.previousElementSibling;
                if (btn?.classList.contains('cat-toggle')) btn.setAttribute('aria-expanded', 'true');
            });
        }

        function closeAllSubmenus() {
            drawerSubmenus.forEach((ul) => {
                setSubmenuOpen(ul, false);
                const btn = ul.previousElementSibling;
                if (btn && btn.classList.contains('cat-toggle')) btn.setAttribute('aria-expanded', 'false');
            });
        }

        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && isMobile()) closeDrawer(); });

        // Desktop click-to-expand and hover-to-expand: manage explicit vs hover expansion
        expandControl?.addEventListener('click', function () {
            if (isMobile()) return;
            const expanding = expandControl.getAttribute('aria-expanded') !== 'true';
            drawer.classList.toggle('expanded', expanding);
            drawer.classList.toggle('compact', !expanding);
            expandControl.setAttribute('aria-expanded', String(expanding));
            expandControl.setAttribute('aria-label', expanding ? 'Recolher opções' : 'Mostrar todas as opções');
            expandControl.title = expanding ? 'Recolher opções' : 'Mostrar todas as opções';
            if (expanding) openAllSubmenus(); else closeAllSubmenus();
        });

        // hover behavior: when user moves mouse over drawer on desktop, expand temporarily

        // Fecha (colapsa) o drawer apenas quando o usuário clicar fora dele.
        document.addEventListener('click', function (e) {
            if (isMobile()) return;
            // Se o clique foi dentro do drawer ou no botão hamburger, ignora.
            if (e.target.closest('#sidebar-drawer') || e.target.closest('#hamburger')) return;

            drawer.classList.remove('expanded');
            drawer.classList.remove('hover-expanded');
            drawer.classList.add('compact');
            expandControl?.setAttribute('aria-expanded', 'false');
            closeAllSubmenus();
        });

        // On load and resize, ensure drawer is compact on desktop and operable on mobile
        function applyResponsive() {
            if (isMobile()) {
                document.body.classList.remove('mobile-menu-open');
                drawer.classList.remove('open');
                drawer.classList.remove('compact');
                drawer.classList.remove('expanded');
                drawer.classList.remove('hover-expanded');
                expandControl?.setAttribute('aria-expanded', 'false');
                backdrop.classList.remove('open');
                drawer.setAttribute('aria-hidden', 'true');
                hamburger.setAttribute('aria-expanded', 'false');
                hamburger.style.display = '';
                resetSubmenus();
            } else {
                document.body.classList.remove('mobile-menu-open');
                // desktop: compact by default, visible, but without forcing the mobile "open" state
                drawer.classList.add('compact');
                drawer.classList.remove('expanded');
                drawer.classList.remove('hover-expanded');
                expandControl?.setAttribute('aria-expanded', 'false');
                drawer.classList.remove('open');
                drawer.setAttribute('aria-hidden', 'false');
                backdrop.classList.remove('open');
                hamburger.style.display = 'none';
                resetSubmenus();
            }
        }
        mobileQuery.addEventListener('change', applyResponsive);
        applyResponsive();
    })();

