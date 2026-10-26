document.addEventListener('DOMContentLoaded', function () {
    requestAnimationFrame(function () {
        document.body.classList.add('page-ready');
    });

    // Esconde o overlay de carregamento após a página estar pronta.
    (function hideLoader() {
        const loader = document.getElementById('page-loader');
        if (!loader) return;
        // Aguarda o próximo frame para garantir transição suave
        const start = Date.now();
        requestAnimationFrame(() => {
            const minShow = 80; // ms
            const hide = () => {
                loader.classList.add('page-loader--hidden');
                setTimeout(() => { if (loader && loader.parentNode) loader.parentNode.removeChild(loader); }, 120);
            };
            const elapsed = Date.now() - start;
            if (elapsed >= minShow) hide(); else setTimeout(hide, minShow - elapsed);
        });
    })();

    // Intercept internal link clicks and form submits to show loader during navigation
    (function hookNavigationLoader() {
        const loader = document.getElementById('page-loader');
        if (!loader) return;

        function showLoaderBeforeNavigate() {
            loader.classList.remove('page-loader--hidden');
            // ensure it's in DOM
            if (!document.getElementById('page-loader')) document.body.appendChild(loader);
        }

        // Links
        document.addEventListener('click', function (e) {
            const a = e.target.closest('a');
            if (!a) return;
            // Mobile menu links navigate natively, without a delayed loading overlay.
            if (a.closest('#sidebar-drawer') && window.matchMedia('(max-width: 899px)').matches) return;
            const href = a.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
            // only internal navigations
            if (href.startsWith('http') && new URL(href, location.href).origin !== location.origin) return;

            // show loader and allow navigation
            showLoaderBeforeNavigate();
            // small delay to allow animation to show
            // do not prevent default for middle-click/ctrl-click
            if (e.ctrlKey || e.metaKey || e.button === 1) return;
            e.preventDefault();
            setTimeout(() => { location.href = href; }, 30);
        }, {capture: true});

        // Forms (submit)
        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (!form || !(form instanceof HTMLFormElement)) return;
            // Capture happens before AJAX controllers call preventDefault().
            // Wait for propagation and cover only a real page navigation.
            setTimeout(function () {
                if (!e.defaultPrevented && !form.matches('[data-ajax-form], .md-form')) {
                    showLoaderBeforeNavigate();
                }
            }, 0);
        }, {capture: true});
    })();

    const toggle = document.querySelector('.hamburger-toggle');
    const shell = document.querySelector('.app-shell-left');
    if (toggle && shell) {
        toggle.setAttribute('aria-expanded', String(!shell.classList.contains('collapsed')));
        toggle.addEventListener('click', function () {
            shell.classList.toggle('collapsed');
            toggle.setAttribute('aria-expanded', String(!shell.classList.contains('collapsed')));
        });
    }

    // Smooth appearance
    document.body.classList.add('animated-ui');

    // Mantém Financeiro como o penúltimo item, imediatamente antes de Configurações.
    const financialMenuItem = document.querySelector('#sidebar-drawer .cat-toggle [data-feather="dollar-sign"]')?.closest('.drawer-cat');
    const settingsMenuItem = document.querySelector('#sidebar-drawer .cat-toggle [data-feather="settings"]')?.closest('.drawer-cat');
    if (financialMenuItem && settingsMenuItem) settingsMenuItem.parentNode.insertBefore(financialMenuItem, settingsMenuItem);

    // Cada rota interna do menu recebe um ícone próprio antes de inicializar o Feather.
    const drawerIconRoutes = [
        ['page=transporte&tab=cte','file-text'],['page=transporte&tab=mdfe','clipboard'],
        ['page=transporte&tab=veiculos','truck'],['page=transporte&tab=configuracoes','sliders'],
        ['page=dashboard&tab=overview','layout'],['page=dashboard&tab=sales','trending-up'],
        ['page=dashboard&tab=financial','dollar-sign'],
        ['financial_tab=receivable','arrow-down-circle'],['financial_tab=payable','arrow-up-circle'],
        ['tab=entrada','log-in'],['tab=saida','log-out'],['tab=emitidos','check-square'],
        ['page=fiscal_notes','file-text'],['tab=pessoas','user'],['tab=produtos','shopping-bag'],
        ['tab=cfops','hash'],['stock_tab=products','box'],['stock_tab=lots','layers'],
        ['stock_tab=movements','repeat'],['stock_tab=locations','map-pin'],['tab=empresa','briefcase'],
        ['tab=usuarios','users'],['#fiscal','percent'],['#nfce','credit-card'],
        ['#mdfe','truck'],['#contador','clipboard']
    ];
    document.querySelectorAll('#sidebar-drawer .drawer-submenu a').forEach(function (link) {
        if (link.querySelector('[data-feather]')) return;
        const linkLabel = link.textContent?.trim();
        if (linkLabel) {
            link.title = linkLabel;
            link.setAttribute('aria-label', linkLabel);
        }
        const href = link.getAttribute('href') || '';
        const match = drawerIconRoutes.find(([part]) => href.includes(part));
        const icon = document.createElement('i');
        icon.dataset.feather = match ? match[1] : 'chevron-right';
        icon.setAttribute('aria-hidden', 'true');
        link.prepend(icon);
    });
    document.querySelectorAll('#sidebar-drawer > .drawer-inner > .drawer-cats > .drawer-cat > .cat-link, #sidebar-drawer > .drawer-inner > .drawer-cats > .drawer-cat > .cat-toggle').forEach(function (item) {
        const label = item.querySelector('span')?.textContent?.trim();
        if (label) {
            item.title = label;
            item.setAttribute('aria-label', label);
        }
    });

    // initialize feather icons if present
    try { if (window.feather) window.feather.replace(); } catch (e) { /* ignore */ }

    // Submenu behavior for navbar items (hover + keyboard accessible)
    (function () {
        const items = document.querySelectorAll('.menu-item-wrapper');
        items.forEach(item => {
            const submenu = item.querySelector('.submenu');
            if (!submenu) return;

            function adjustPosition() {
                const rect = item.getBoundingClientRect();
                const submenuWidth = submenu.offsetWidth || 200;
                const viewportPadding = 8;
                const fitsRight = rect.right + submenuWidth + viewportPadding <= window.innerWidth;
                const fitsLeft = rect.left - submenuWidth >= viewportPadding;

                if (fitsRight) {
                    submenu.style.left = '0';
                    submenu.style.right = 'auto';
                    submenu.style.transform = 'none';
                } else if (fitsLeft) {
                    submenu.style.left = 'auto';
                    submenu.style.right = '0';
                    submenu.style.transform = 'none';
                } else {
                    submenu.style.left = '50%';
                    submenu.style.right = 'auto';
                    submenu.style.transform = 'translateX(-50%)';
                }
            }

            item.addEventListener('mouseenter', function () {
                requestAnimationFrame(adjustPosition);
            });
            item.addEventListener('focusin', function () {
                requestAnimationFrame(adjustPosition);
            });
            window.addEventListener('resize', adjustPosition);
            window.addEventListener('scroll', adjustPosition, { passive: true });
        });
    })();

    document.querySelectorAll('.register-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            const target = tab.dataset.registerTab;
            if (!target) return;

            document.querySelectorAll('.register-tab').forEach((btn) => btn.classList.toggle('active', btn === tab));
            document.querySelectorAll('.register-panel').forEach((panel) => {
                panel.classList.toggle('active', panel.dataset.registerPanel === target);
            });
        });
    });

    document.querySelectorAll('[data-register-tab-switch]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = btn.dataset.registerTabSwitch;
            if (!target) return;
            const tab = document.querySelector('.register-tab[data-register-tab="' + target + '"]');
            if (tab) tab.click();
        });
    });

    const productListSearch = document.getElementById('product-list-search');
    const productStatusFilter = document.getElementById('product-status-filter');
    if (productListSearch || productStatusFilter) {
        const productRows = Array.from(document.querySelectorAll('[data-product-row]'));
        const emptyState = document.getElementById('product-list-empty');
        const filterProducts = function () {
            const term = (productListSearch ? productListSearch.value : '').trim().toLowerCase();
            const status = productStatusFilter ? productStatusFilter.value : 'all';
            let visible = 0;

            productRows.forEach(function (row) {
                const rowText = (row.dataset.productText || row.textContent || '').toLowerCase();
                const rowStatus = (row.dataset.productStatus || '').toLowerCase();
                const matchesText = !term || rowText.includes(term);
                const matchesStatus = status === 'all' || rowStatus === status;
                const show = matchesText && matchesStatus;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            if (emptyState) emptyState.style.display = visible === 0 ? 'block' : 'none';
        };

        if (productListSearch) productListSearch.addEventListener('input', filterProducts);
        if (productStatusFilter) productStatusFilter.addEventListener('change', filterProducts);
        filterProducts();
    }

    // Hide duplicate fields in empresa form
    const empresaForm = document.querySelector('form input[name="action"][value="save_empresa"]')?.closest('form');
    if (empresaForm) {
        const inputs = Array.from(empresaForm.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"], select'));
        // map name -> [{el,labelText}]
        const map = {};
        inputs.forEach(input => {
            const name = input.name || '';
            const label = input.closest('label');
            const labelText = label ? (label.textContent || '').trim().replace(/:\s*$/, '') : name;
            if (!map[name]) map[name] = [];
            map[name].push({ input, label, labelText });
        });

        // Compare values across different fields (not same name)
        const seen = {};
        inputs.forEach(item => {
            const val = (item.value || '').trim();
            if (!val) return;
            if (!seen[val]) seen[val] = [];
            seen[val].push(item);
        });

        let hiddenCount = 0;
        Object.keys(seen).forEach(val => {
            const group = seen[val];
            if (group.length <= 1) return;
            // keep first, hide the rest
            const keeper = group[0];
            for (let i = 1; i < group.length; i++) {
                const it = group[i];
                if (it.label) {
                    it.label.classList.add('hidden-duplicate');
                    hiddenCount++;
                } else {
                    it.input.style.display = 'none';
                    hiddenCount++;
                }
            }
            // add hint to keeper's label
            if (keeper.label) {
                let hint = keeper.label.querySelector('.duplicate-hint');
                if (!hint) {
                    hint = document.createElement('span');
                    hint.className = 'duplicate-hint';
                    hint.textContent = '(duplicados ocultos)';
                    keeper.label.appendChild(hint);
                }
            }
        });

        if (hiddenCount > 0) {
            const toggle = document.createElement('div');
            toggle.className = 'show-hidden-toggle';
            toggle.textContent = 'Mostrar campos ocultos';
            toggle.addEventListener('click', () => {
                const hidden = empresaForm.querySelectorAll('.hidden-duplicate');
                hidden.forEach(el => el.classList.toggle('hidden-duplicate'));
                toggle.textContent = toggle.textContent === 'Mostrar campos ocultos' ? 'Ocultar campos duplicados' : 'Mostrar campos ocultos';
            });
            empresaForm.appendChild(toggle);
        }
    }
});

// Pedidos: gerenciar itens, busca e totais
(function () {
    const search = document.getElementById('product-search');
    const itemsTable = document.getElementById('items-table');
    const tbody = itemsTable ? itemsTable.querySelector('tbody') : null;
    const btnClear = document.getElementById('btn-clear');
    const totalProdutos = document.getElementById('total-produtos');
    const valorTotal = document.getElementById('valor-total');
    let itemIndex = 0;

    function formatCurrencyBR(v) {
        return 'R$ ' + Number(v).toFixed(2).replace('.', ',');
    }

    function renderNoItems() {
        if (!tbody) return;
        if (tbody.querySelectorAll('tr.item-row').length === 0) {
            tbody.innerHTML = '<tr class="no-items"><td colspan="10">Nenhum registro encontrado</td></tr>';
        }
    }

    function addItem(prod, saved = null) {
        if (!tbody) return;
        // remove placeholder
        const placeholder = tbody.querySelector('tr.no-items');
        if (placeholder) placeholder.remove();

        const row = document.createElement('tr');
        row.className = 'item-row';
        const idx = itemIndex++;
        const subtotal = saved ? Number(saved.net_total) : prod.preco;
        const productTaxes = window.PRODUCT_TAXES || {};
        const defaultTaxes = Array.isArray(productTaxes)
            ? (productTaxes.find(item => String(item.product_id ?? item.id) === String(prod.id)) || {})
            : (productTaxes[String(prod.id)] || {});
        const currentTaxValue = (source, fallback = '') => {
            if (saved && source && Object.prototype.hasOwnProperty.call(saved, source)) return saved[source];
            if (!saved && defaultTaxes && Object.prototype.hasOwnProperty.call(defaultTaxes, source)) return defaultTaxes[source];
            return fallback;
        };
        const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
        const productLots = (window.STOCK_LOTS || []).filter(lot => String(lot.product_id) === String(prod.id));
        const selectedLot = String(saved?.stock_lot_id || '');
        const lotField = prod.stock_control_by_lot ? `<label class="order-lot-field">Lote<select name="itens[${idx}][stock_lot_id]" required><option value="">Selecione o lote</option>${productLots.map(lot=>`<option value="${Number(lot.id)}" ${String(lot.id)===selectedLot?'selected':''}>${escapeHtml(lot.lot_code)} · ${escapeHtml(lot.location_name)} · saldo ${escapeHtml(lot.quantity_available)}${lot.expires_at?' · val. '+escapeHtml(lot.expires_at):''}</option>`).join('')}</select></label>` : '';

        row.innerHTML = `
            <td>${idx+1}</td>
            <td>${prod.codigo}</td>
            <td>${prod.nome}${lotField}</td>
            <td>${prod.un || 'UN'}</td>
            <td><input type="number" name="itens[${idx}][quantidade]" value="${saved ? saved.quantity : 1}" min="0.0001" step="0.0001" class="item-qty"></td>
            <td><input type="number" step="0.0001" name="itens[${idx}][preco_unitario]" value="${saved ? saved.unit_price : prod.preco}" class="item-preco"></td>
            <td><input type="number" step="0.01" min="0" name="itens[${idx}][desconto]" value="${saved ? saved.discount_amount : 0}" class="item-discount"></td>
            <td class="item-subtotal">${formatCurrencyBR(subtotal)}</td>
            <td><span class="status-badge">Não avaliado</span></td>
            <td>
                <button type="button" class="link-button btn-edit">Editar</button>
                <button type="button" class="link-button btn-tax">Impostos</button>
                <button type="button" class="link-button btn-remove">Remover</button>
                <input type="hidden" name="itens[${idx}][produto_id]" value="${prod.id}">
                <input type="hidden" name="itens[${idx}][icms]" value="${String(currentTaxValue('icms', '')).replace(/"/g, '&quot;')}">
                <input type="hidden" name="itens[${idx}][ipi]" value="${String(currentTaxValue('ipi', '')).replace(/"/g, '&quot;')}">
                <input type="hidden" name="itens[${idx}][pis]" value="${String(currentTaxValue('pis', '')).replace(/"/g, '&quot;')}">
                <input type="hidden" name="itens[${idx}][cofins]" value="${String(currentTaxValue('cofins', '')).replace(/"/g, '&quot;')}">
            </td>
        `;

        tbody.appendChild(row);

        // attach events
        row.querySelector('.item-qty').addEventListener('input', computeTotals);
        row.querySelector('.item-preco').addEventListener('input', computeTotals);
        row.querySelector('.item-discount').addEventListener('input', computeTotals);
        row.querySelector('.btn-edit').addEventListener('click', function () {
            const productCell = row.querySelector('td:nth-child(3)');
            if (productCell) productCell.click();
        });
        row.querySelector('.btn-tax').addEventListener('click', function () {
            const modal = document.getElementById('product-taxes-modal');
            const body = document.getElementById('modal-body-content');
            const closeButton = document.getElementById('close-modal');
            if (!modal || !body || !closeButton) return;

            const title = (row.querySelector('td:nth-child(3)')?.textContent || 'Item').trim();
            const itemTaxes = {
                icms: row.querySelector('input[name$="[icms]"]').value || '',
                ipi: row.querySelector('input[name$="[ipi]"]').value || '',
                pis: row.querySelector('input[name$="[pis]"]').value || '',
                cofins: row.querySelector('input[name$="[cofins]"]').value || '',
            };

            body.innerHTML = `
                <div style="display:grid;gap:12px;">
                    <p><strong>Produto:</strong> ${String(title).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</p>
                    <form id="item-tax-form" style="display:grid;gap:12px;">
                        <label>ICMS<input type="text" name="icms" value="${String(itemTaxes.icms).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}" placeholder="Ex.: 18,00"></label>
                        <label>IPI<input type="text" name="ipi" value="${String(itemTaxes.ipi).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}" placeholder="Ex.: 0,00"></label>
                        <label>PIS<input type="text" name="pis" value="${String(itemTaxes.pis).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}" placeholder="Ex.: 0,65"></label>
                        <label>COFINS<input type="text" name="cofins" value="${String(itemTaxes.cofins).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}" placeholder="Ex.: 3,00"></label>
                        <div class="form-actions">
                            <button type="submit" class="btn primary">Salvar imposto</button>
                            <button type="button" class="btn secondary" data-close-item-tax>Fechar</button>
                        </div>
                    </form>
                </div>
            `;

            const form = body.querySelector('#item-tax-form');
            form?.addEventListener('submit', function (event) {
                event.preventDefault();
                const formData = new FormData(form);
                const values = {
                    icms: String(formData.get('icms') || ''),
                    ipi: String(formData.get('ipi') || ''),
                    pis: String(formData.get('pis') || ''),
                    cofins: String(formData.get('cofins') || ''),
                };
                row.querySelector('input[name$="[icms]"]').value = values.icms;
                row.querySelector('input[name$="[ipi]"]').value = values.ipi;
                row.querySelector('input[name$="[pis]"]').value = values.pis;
                row.querySelector('input[name$="[cofins]"]').value = values.cofins;
                row.querySelector('.status-badge').textContent = Object.values(values).some(v => String(v).trim() !== '') ? 'Tributos OK' : 'Não avaliado';
                modal.classList.remove('show');
                modal.setAttribute('aria-hidden', 'true');
            });
            body.querySelector('[data-close-item-tax]')?.addEventListener('click', function () {
                modal.classList.remove('show');
                modal.setAttribute('aria-hidden', 'true');
            });

            closeButton.onclick = function () {
                modal.classList.remove('show');
                modal.setAttribute('aria-hidden', 'true');
            };
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
        });
        row.querySelector('.btn-remove').addEventListener('click', function () { row.remove(); computeTotals(); renderNoItems(); });

        computeTotals();
    }

    function findProduct(term) {
        if (!window.PRODUCTS) return null;
        term = (term || '').toString().toLowerCase().trim();
        if (!term) return null;
        // try exact code
        let p = window.PRODUCTS.find(x => x.codigo && x.codigo.toLowerCase() === term);
        if (p) return p;
        // try id
        p = window.PRODUCTS.find(x => String(x.id) === term);
        if (p) return p;
        // substring name
        p = window.PRODUCTS.find(x => x.nome && x.nome.toLowerCase().includes(term));
        return p || null;
    }

    // Autocomplete: cria dropdown de sugestões sob o campo de busca
    function createAutocomplete() {
        const searchInput = document.getElementById('product-search');
        if (!searchInput) return;
        let box = document.createElement('div');
        box.className = 'autocomplete-box';
        box.style.position = 'absolute';
        box.style.zIndex = 999;
        box.style.background = '#fff';
        box.style.border = '1px solid var(--border)';
        box.style.borderRadius = '6px';
        box.style.boxShadow = '0 6px 18px rgba(21,41,81,0.08)';
        box.style.maxHeight = '300px';
        box.style.overflow = 'auto';
        box.style.display = 'none';
        document.body.appendChild(box);

        let selected = -1;
        let results = [];

        function positionBox() {
            const rect = searchInput.getBoundingClientRect();
            box.style.left = (rect.left + window.scrollX) + 'px';
            box.style.top = (rect.bottom + window.scrollY + 6) + 'px';
            box.style.minWidth = rect.width + 'px';
        }

        function render(items) {
            results = items;
            selected = -1;
            if (!items.length) {
                box.style.display = 'none';
                return;
            }
            box.innerHTML = items.map((p, i) => `<div class="ac-item" data-idx="${i}" style="padding:8px;cursor:pointer;border-bottom:1px solid rgba(0,0,0,0.04);">` +
                `<div style="font-weight:600">${escapeHtml(p.nome)} <small style="color:#666">${escapeHtml(p.codigo || '')}</small></div>` +
                `<div style="font-size:0.9rem;color:#666">R$ ${Number(p.preco).toFixed(2)}</div>` +
                `</div>`).join('');
            box.style.display = 'block';
            positionBox();
        }

        function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

        function filter(term) {
            term = (term || '').toString().toLowerCase().trim();
            if (!term) return [];
            const list = window.PRODUCTS || [];
            const byCode = list.filter(p => p.codigo && p.codigo.toLowerCase().includes(term));
            const byName = list.filter(p => p.nome && p.nome.toLowerCase().includes(term));
            const merged = [];
            const seen = new Set();
            byCode.concat(byName).forEach(p => {
                if (!seen.has(p.id)) { seen.add(p.id); merged.push(p); }
            });
            return merged.slice(0, 12);
        }

        let debounceTimer = null;
        searchInput.addEventListener('input', function (e) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                const items = filter(searchInput.value);
                render(items);
            }, 120);
        });

        document.addEventListener('click', function (ev) {
            if (!box.contains(ev.target) && ev.target !== searchInput) box.style.display = 'none';
        });

        box.addEventListener('click', function (ev) {
            const it = ev.target.closest('.ac-item');
            if (!it) return;
            const idx = Number(it.getAttribute('data-idx'));
            const p = results[idx];
            if (p) { addItem(p); searchInput.value = ''; box.style.display = 'none'; }
        });

        searchInput.addEventListener('keydown', function (ev) {
            if (box.style.display === 'none') return;
            const itemsEls = box.querySelectorAll('.ac-item');
            if (ev.key === 'ArrowDown') { ev.preventDefault(); selected = Math.min(selected + 1, itemsEls.length - 1); highlight(); }
            if (ev.key === 'ArrowUp') { ev.preventDefault(); selected = Math.max(selected - 1, 0); highlight(); }
            if (ev.key === 'Enter') { ev.preventDefault(); if (selected >= 0) { const p = results[selected]; if (p) { addItem(p); searchInput.value = ''; box.style.display = 'none'; } } }
            if (ev.key === 'Escape') { box.style.display = 'none'; }

            function highlight() {
                itemsEls.forEach((el, i) => el.style.background = i === selected ? 'rgba(0,0,0,0.04)' : 'transparent');
                if (selected >= 0 && itemsEls[selected]) itemsEls[selected].scrollIntoView({block:'nearest'});
            }
        });

        window.addEventListener('resize', positionBox);
    }

    // Inicializa autocomplete quando houver campo de busca
    createAutocomplete();
    (window.ORDER_ITEMS || []).forEach(saved => addItem({id:saved.id,codigo:saved.codigo,nome:saved.nome,un:saved.unidade,preco:saved.unit_price,stock_control_by_lot:Boolean(Number(saved.stock_control_by_lot||0))}, saved));
    window.addEventListener('erp:entry-xml-products', event => {
        if (!tbody || !Array.isArray(event.detail)) return;
        tbody.querySelectorAll('tr.item-row').forEach(row => row.remove());
        event.detail.forEach(product => {
            const catalogProduct = {...product}; delete catalogProduct.saved;
            window.PRODUCTS = window.PRODUCTS || [];
            const existingIndex = window.PRODUCTS.findIndex(item => String(item.id) === String(catalogProduct.id));
            if (existingIndex >= 0) window.PRODUCTS[existingIndex] = catalogProduct; else window.PRODUCTS.push(catalogProduct);
            addItem(catalogProduct, product.saved || null);
        });
        computeTotals();
    });

    function computeTotals() {
        if (!tbody) return;
        let total = 0;
        tbody.querySelectorAll('tr.item-row').forEach(row => {
            const qty = Number(row.querySelector('.item-qty').value) || 0;
            const price = Number(row.querySelector('.item-preco').value) || 0;
            const discount = Number(row.querySelector('.item-discount')?.value) || 0;
            const subtotal = Math.max(0, qty * price - discount);
            row.querySelector('.item-subtotal').textContent = formatCurrencyBR(subtotal);
            total += subtotal;
        });
        if (totalProdutos) totalProdutos.textContent = formatCurrencyBR(total);
        const orderForm = document.getElementById('pedido-form');
        const adjustment = name => Number(String(orderForm?.elements.namedItem(name)?.value || '0').replace(',', '.')) || 0;
        const grandTotal = Math.max(0, total + adjustment('frete') + adjustment('seguro') + adjustment('outras_despesas') - adjustment('desconto_valor'));
        if (valorTotal) valorTotal.textContent = formatCurrencyBR(grandTotal);
    }

    if (search) {
        search.addEventListener('keydown', function (e) {
            if (e.key === 'F8') { e.preventDefault(); /* focus already */ }
            if (e.key === 'Enter') {
                e.preventDefault();
                const p = findProduct(search.value);
                if (p) addItem(p);
                search.value = '';
            }
        });
    }

    if (btnClear) btnClear.addEventListener('click', function () {
        if (!tbody) return;
        tbody.querySelectorAll('tr.item-row').forEach(r => r.remove());
        renderNoItems();
        computeTotals();
    });
    document.querySelectorAll('#pedido-form [name="frete"],#pedido-form [name="seguro"],#pedido-form [name="outras_despesas"],#pedido-form [name="desconto_valor"]').forEach(field=>field.addEventListener('input',computeTotals));

    const testFillButton = document.querySelector('[data-order-test-fill]');
    if (testFillButton) testFillButton.addEventListener('click', function () {
        const form = document.getElementById('pedido-form');
        if (!form) return;
        const setValue = (name, value) => {
            const field = form.elements.namedItem(name);
            if (!field) return;
            field.value = value;
            field.dispatchEvent(new Event('change', { bubbles: true }));
            field.dispatchEvent(new Event('input', { bubbles: true }));
        };
        const randomItem = items => items[Math.floor(Math.random() * items.length)];
        const selectRandom = name => {
            const field = form.elements.namedItem(name);
            if (!(field instanceof HTMLSelectElement)) return false;
            const options = [...field.options].filter(item => item.value !== '' && !item.disabled);
            if (!options.length) return false;
            const option = randomItem(options);
            setValue(name, option.value);
            return true;
        };

        btnClear?.click();
        const direction = String(form.elements.namedItem('tipo')?.value || 'saida');
        selectRandom(direction === 'entrada' ? 'fornecedor_id' : 'cliente_id');
        selectRandom('cfop_id');
        setValue('fiscal_model', '55');
        setValue('purpose', 'NORMAL');
        setValue('presence_indicator', '1');
        setValue('codigo_interno', `TESTE-${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}`);
        if (!selectRandom('condicao_pagamento')) setValue('condicao_pagamento', 'avista');
        if (!selectRandom('documento')) setValue('documento', 'PIX');
        setValue('vencimento', new Date().toISOString().slice(0, 10));
        setValue('frete', '0');
        setValue('desconto_percent', '0');
        setValue('desconto_valor', '0');
        setValue('observacoes', 'PREENCHIMENTO DE TESTE — revisar antes de gravar ou preparar nota.');
        setValue('freight_mode', '9');

        const activeProducts = (window.PRODUCTS || []).filter(item => item.status !== 'inativo');
        const pricedProducts = activeProducts.filter(item => Number(item.preco) > 0);
        const productPool = pricedProducts.length ? pricedProducts : activeProducts;
        const shuffledProducts = [...productPool].sort(() => Math.random() - 0.5);
        const itemCount = Math.min(shuffledProducts.length, 1 + Math.floor(Math.random() * 3));
        shuffledProducts.slice(0, itemCount).forEach(product => {
            addItem(product, { quantidade: 1 + Math.floor(Math.random() * 5) });
        });
        testFillButton.blur();
        window.AppToast?.show('Dados aleatórios dos cadastros foram preenchidos. Revise antes de gravar.', 'success', 4500);
    });

    // global keys
    document.addEventListener('keydown', function (e) {
        if (e.key === 'F8') { e.preventDefault(); const s = document.getElementById('product-search'); if (s) s.focus(); }
        if (e.key === 'F3') { e.preventDefault(); const ev = new Event('click'); if (btnClear) btnClear.dispatchEvent(ev); }
    });

    // on submit, ensure there is at least one item-row
    const pedidoForm = document.getElementById('pedido-form');
    if (pedidoForm) {
        pedidoForm.addEventListener('submit', function (e) {
            const rows = tbody ? tbody.querySelectorAll('tr.item-row') : [];
            if (rows.length === 0) {
                e.preventDefault();
                alert('Adicione ao menos um produto ao pedido.');
                return false;
            }
            // ensure item indices are sequential
            let i = 0;
            rows.forEach(row => {
                row.querySelectorAll('input').forEach(inp => {
                    if (inp.name) {
                        const newName = inp.name.replace(/itens\[\d+\]/, 'itens['+i+']');
                        inp.name = newName;
                    }
                });
                i++;
            });
        });
    }

})();

/* Destaca somente os dados invariavelmente necessários para montar/transmitir a NF-e. */
(()=>{const form=document.querySelector('#pedido-form');if(!form)return;const exact={operation_nature:'ide.natOp / CFOP',fiscal_model:'ide.mod',purpose:'ide.finNFe',presence_indicator:'ide.indPres',cliente_id:'dest',fornecedor_id:'dest',data_venda:'ide.dhEmi',documento:'pag.detPag.tPag',condicao_pagamento:'pag',cfop_id:'det.prod.CFOP'};const mark=field=>{if(!field||field.type==='hidden')return;const name=field.name||'',tag=exact[name]||(name.includes('[produto_id]')?'det.prod':name.includes('[quantidade]')?'det.prod.qCom/qTrib':name.includes('[preco_unitario]')?'det.prod.vUnCom/vUnTrib':'');if(!tag)return;const container=field.closest('.order-field,.field-wrap,.item-cell,label')||field.parentElement;container?.classList.add('sefaz-required');container?.setAttribute('data-sefaz-tag',tag);field.setAttribute('aria-required','true');field.required=true};const scan=()=>form.querySelectorAll('input[name],select[name],textarea[name]').forEach(mark);const legend=document.createElement('p');legend.className='sefaz-required-legend';legend.innerHTML='<strong><span aria-hidden="true">*</span> Obrigatório para SEFAZ</strong> Campos contornados alimentam tags obrigatórias do XML. Campos condicionais são validados conforme a operação.';form.prepend(legend);scan();new MutationObserver(scan).observe(form,{childList:true,subtree:true})})();

// Gráficos responsivos do Dashboard com escala linear real e tooltips em pt-BR.
(() => {
    if (typeof window.Chart === 'undefined') return;
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const rootStyle = getComputedStyle(document.documentElement);
    const textColor = rootStyle.getPropertyValue('--text').trim() || '#172033';
    const mutedColor = rootStyle.getPropertyValue('--muted').trim() || '#667085';
    const borderColor = rootStyle.getPropertyValue('--border').trim() || '#dce3ef';

    document.querySelectorAll('canvas.dashboard-chart[data-chart]').forEach(canvas => {
        let payload;
        try { payload = JSON.parse(canvas.dataset.chart || '{}'); } catch (_) { return; }
        const isMoney = payload.type === 'money';
        const isDonut = payload.type === 'donut';
        const context = canvas.getContext('2d');
        const gradient = context.createLinearGradient(0, 0, 0, 360);
        gradient.addColorStop(0, isMoney ? '#3b82f6' : '#22c98a');
        gradient.addColorStop(1, isMoney ? '#2457d6' : '#12845d');

        const palette = ['#cf4967','#2f83bd','#d1a53a','#22a06b','#775dd0','#e07a3f'];
        new window.Chart(context, {
            type: isDonut ? 'doughnut' : 'bar',
            data: {
                labels: payload.labels || [],
                datasets: [{
                    label: payload.label || '',
                    data: payload.values || [],
                    backgroundColor: isDonut ? palette : gradient,
                    borderColor: isDonut ? rootStyle.getPropertyValue('--panel').trim() || '#fff' : (isMoney ? '#2457d6' : '#12845d'),
                    borderWidth: isDonut ? 3 : 1,
                    borderRadius: 7,
                    borderSkipped: false,
                    maxBarThickness: 42,
                    hoverOffset: isDonut ? 6 : 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reduced ? false : { duration: 380, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: isDonut, position: 'top', labels: { color: textColor, usePointStyle: true, boxWidth: 8, padding: 14 } },
                    tooltip: {
                        displayColors: false,
                        padding: 12,
                        callbacks: {
                            title: items => items[0]?.label || '',
                            label: item => isMoney ? `Faturamento: ${money.format(item.raw || 0)}` : `${item.label || 'Quantidade'}: ${item.raw || 0}`,
                            afterLabel: item => isMoney && Array.isArray(payload.counts) ? `Vendas: ${payload.counts[item.dataIndex] || 0}` : ''
                        }
                    }
                },
                cutout: isDonut ? '58%' : undefined,
                scales: isDonut ? {} : {
                    x: { grid: { display: false }, ticks: { color: mutedColor, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
                    y: {
                        beginAtZero: true,
                        grace: '12%',
                        grid: { color: borderColor },
                        ticks: { color: mutedColor, precision: 0, callback: value => isMoney ? money.format(value) : value }
                    }
                }
            }
        });
        canvas.style.color = textColor;
    });
})();

// A Central de Notas reutiliza o preview seguro do pedido e mantém a Central aberta.
document.querySelectorAll('.notes-table tbody tr').forEach(function (row) {
    const orderText = row.querySelector('td small')?.textContent || '';
    const orderMatch = orderText.match(/#(\d+)/);
    const actions = row.querySelector('.row-actions');
    if (!orderMatch || !actions || actions.querySelector('[data-danfe-preview]')) return;
    const model = row.querySelector('[data-label="Modelo"]')?.textContent.trim();
    if (model !== '55' && model !== '65') return;
    const link = document.createElement('a');
    link.className = 'btn small';
    link.href = '/fiscal_danfe_preview.php?order_id=' + encodeURIComponent(orderMatch[1]);
    link.dataset.danfePreview = '';
    link.textContent = model === '65' ? 'Prévia DANFC-e' : 'Prévia DANFE';
    actions.querySelector('details')?.before(link);
});

(function () {
    document.querySelectorAll('.switch-control').forEach(function (control) {
        const input = control.querySelector('.switch-input');
        const state = control.querySelector('.switch-state');
        if (!input || !state) return;
        const sync = function () {
            const checked = input.checked;
            control.setAttribute('aria-checked', checked ? 'true' : 'false');
            state.textContent = checked ? 'Sim' : 'Não';
        };
        input.addEventListener('change', sync);
        control.addEventListener('keydown', function (event) {
            if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault();
                input.checked = !input.checked;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        sync();
    });

    document.querySelectorAll('.order-collapse-toggle').forEach(function (button) {
        const card = button.closest('.logistics-card');
        if (!card) return;
        const label = button.firstElementChild;
        const icon = button.lastElementChild;
        const sync = function () {
            const expanded = button.getAttribute('aria-expanded') === 'true';
            card.classList.toggle('is-collapsed', !expanded);
            if (label) label.textContent = expanded ? 'Recolher' : 'Expandir';
            if (icon) icon.textContent = expanded ? '↑' : '↓';
        };
        button.addEventListener('click', function () {
            const next = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', String(next));
            sync();
        });
        sync();
    });
})();

// Mostrar modal de impostos quando clicar no nome do produto na tabela de itens
document.addEventListener('click', function (e) {
    const previewLink = e.target.closest && e.target.closest('[data-danfe-preview]');
    if (previewLink) {
        e.preventDefault();
        const previewWindow = window.open('', '_blank');
        if (!previewWindow) {
            window.location.href = previewLink.href;
            return;
        }
        previewWindow.document.write('<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Gerando prévia DANFE</title><body style="font:16px system-ui;padding:32px"><p>Gerando prévia DANFE...</p></body></html>');
        previewWindow.location.href = previewLink.href;
        return;
    }

    const descriptionCell = e.target && e.target.closest ? e.target.closest('td') : null;
    if (!descriptionCell) return;

    const itemRow = descriptionCell.closest && descriptionCell.closest('tr.item-row');
    if (!itemRow) return;

    if (e.target && e.target.closest && e.target.closest('.btn-tax')) return;

    const productCell = itemRow.querySelector('td:nth-child(3)');
    if (!productCell || (descriptionCell !== productCell && !productCell.contains(descriptionCell))) return;

    const prodIdInput = itemRow.querySelector('input[type="hidden"][name$="[produto_id]"]');
    if (!prodIdInput) return;

    const pid = prodIdInput.value;
    const taxesMap = window.PRODUCT_TAXES || {};
    const taxes = Array.isArray(taxesMap)
        ? taxesMap.find(item => String(item.product_id ?? item.id) === String(pid)) || null
        : (taxesMap[pid] ?? null);

    const modal = document.getElementById('product-taxes-modal');
    const body = document.getElementById('modal-body-content');
    const closeButton = document.getElementById('close-modal');
    if (!modal || !body || !closeButton) return;

    if (!taxes) {
        body.innerHTML = '<p>Sem informações de impostos para este produto.</p>';
    } else {
        body.innerHTML = '<ul>' +
            '<li><strong>IPI:</strong> ' + (taxes.ipi || '-') + '</li>' +
            '<li><strong>ICMS:</strong> ' + (taxes.icms || '-') + '</li>' +
            '<li><strong>PIS:</strong> ' + (taxes.pis || '-') + '</li>' +
            '<li><strong>COFINS:</strong> ' + (taxes.cofins || '-') + '</li>' +
            '</ul>';
    }

    closeButton.onclick = function () {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    };
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
});

// Gestão de usuários da empresa em modal com guias.
(() => {
    const modal = document.querySelector('[data-user-modal]');
    const form = modal?.querySelector('[data-user-form]');
    if (!modal || !form) return;
    const users = Array.isArray(window.ERP_COMPANY_USERS) ? window.ERP_COMPANY_USERS : [];
    const title = modal.querySelector('#user-modal-title');
    const tabs = [...modal.querySelectorAll('[data-user-tab]')];
    const panels = [...modal.querySelectorAll('[data-user-panel]')];
    const showTab = name => {
        tabs.forEach(tab => { const active = tab.dataset.userTab === name; tab.classList.toggle('active', active); tab.setAttribute('aria-selected', String(active)); });
        panels.forEach(panel => panel.classList.toggle('active', panel.dataset.userPanel === name));
    };
    const setField = (name, value) => { const field = form.elements.namedItem(name); if (field) field.value = value == null ? '' : String(value); };
    const open = (user = null) => {
        form.reset();
        setField('id', user?.id || ''); setField('nome', user?.nome || ''); setField('email', user?.email || '');
        setField('cargo', user?.cargo || 'funcionario'); setField('role', user?.role || 'user'); setField('status', user?.status || 'ativo'); setField('pessoa_id', user?.pessoa_id || '');
        const permissions = new Set(String(user?.permissions || '').split(',').filter(Boolean));
        form.querySelectorAll('[name="permissions[]"]').forEach(box => { box.checked = permissions.has(box.value); });
        const password = form.elements.namedItem('senha'); if (password) { password.required = !user; password.placeholder = user ? 'Deixe em branco para manter' : 'Crie uma senha de acesso'; }
        if (title) title.textContent = user ? 'Editar usuário' : 'Novo usuário';
        showTab('access'); modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('user-modal-open');
        setTimeout(() => form.elements.namedItem('nome')?.focus(), 30);
    };
    const close = () => { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('user-modal-open'); };
    document.querySelector('[data-user-modal-open]')?.addEventListener('click', () => open());
    document.querySelectorAll('[data-user-edit]').forEach(button => button.addEventListener('click', () => open(users.find(user => Number(user.id) === Number(button.dataset.userEdit)) || null)));
    modal.querySelectorAll('[data-user-modal-close]').forEach(button => button.addEventListener('click', close));
    tabs.forEach(tab => tab.addEventListener('click', () => showTab(tab.dataset.userTab)));
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) close(); });
})();
