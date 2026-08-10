/* ============================================================
   MOTASTE Admin — client logic
   Talks exclusively to the consolidated Laravel API.
   ============================================================ */
(function () {
    'use strict';

    const DEVICE_TOKEN_KEY = 'motaste_device_token';
    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function getOrCreateDeviceToken() {
        try {
            let token = localStorage.getItem(DEVICE_TOKEN_KEY);
            if (!token) {
                token = (typeof crypto !== 'undefined' && crypto.randomUUID)
                    ? crypto.randomUUID()
                    : Date.now() + '-' + Math.random().toString(36).slice(2);
                localStorage.setItem(DEVICE_TOKEN_KEY, token);
            }
            return token;
        } catch (e) {
            return Date.now() + '-' + Math.random().toString(36).slice(2);
        }
    }

    async function api(path, options = {}) {
        const opts = Object.assign({ headers: {}, credentials: 'same-origin' }, options);
        opts.headers = Object.assign({}, opts.headers);

        if (opts.json !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.json);
            delete opts.json;
        }
        if (opts.formData !== undefined) {
            opts.body = opts.formData;
            delete opts.formData;
        }

        // CSRF for state-changing requests (Laravel VerifyCsrfToken).
        if (opts.method && opts.method.toUpperCase() !== 'GET') {
            opts.headers['X-CSRF-TOKEN'] = csrfToken();
            opts.headers['X-Requested-With'] = 'XMLHttpRequest';
        }

        const response = await fetch(path, opts);
        let payload = null;
        try { payload = await response.json(); } catch (e) { /* non-JSON */ }

        if (!response.ok) {
            const message = (payload && (payload.error || payload.message)) || 'Request failed (' + response.status + ')';
            const err = new Error(message);
            err.status = response.status;
            err.payload = payload;
            throw err;
        }
        return payload;
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function formatMoney(value) {
        return '₱' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDateTime(value) {
        if (!value) return '—';
        const d = new Date(value);
        if (isNaN(d.getTime())) return value;
        return d.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function toast(message, type) {
        const wrap = $('#toastWrap');
        if (!wrap) { alert(message); return; }
        const el = document.createElement('div');
        el.className = 'toast' + (type ? ' is-' + type : '');
        el.textContent = message;
        wrap.appendChild(el);
        setTimeout(() => el.remove(), 3600);
    }

    function setMessage(el, text, type) {
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('is-error', type === 'error');
        el.classList.toggle('is-ok', type === 'ok');
    }

    /* ========================================================
       LOGIN PAGE
       ======================================================== */
    function initLogin() {
        const loginForm = $('#loginForm');
        if (!loginForm) return;

        const stepLogin = $('#loginStep');
        const stepVerify = $('#verifyStep');
        const loginMessage = $('#loginMessage');
        const verifyMessage = $('#verifyMessage');

        let pendingLogin = null; // { email, password, role, deviceToken }

        // Password visibility toggles.
        $$('[data-toggle]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const input = $('#' + btn.dataset.toggle);
                if (!input) return;
                input.type = input.type === 'password' ? 'text' : 'password';
            });
        });

        loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const email = $('#loginEmail').value.trim();
            const password = $('#loginPassword').value;
            const role = $('#loginRole').value;

            setMessage(loginMessage, '');
            const submitBtn = $('#loginSubmitBtn');
            const spinner = $('.btn-spinner', submitBtn);
            submitBtn.disabled = true;
            if (spinner) spinner.hidden = false;

            try {
                const payload = await api('/api/staff/login', {
                    method: 'POST',
                    json: { email, password, role, deviceToken: getOrCreateDeviceToken() },
                });

                if (payload.success) {
                    window.location.href = '/admin';
                    return;
                }

                if (payload.needsDeviceVerification) {
                    pendingLogin = { email, password, role, deviceToken: getOrCreateDeviceToken() };
                    $('#verifyHint').textContent = payload.message || 'We emailed a 6-digit code to ' + email + '.';
                    stepLogin.hidden = true;
                    stepVerify.hidden = false;
                    $('#verifyCode').value = '';
                    $('#verifyCode').focus();
                    if (payload.warning) setMessage(verifyMessage, payload.warning, 'error');
                    else setMessage(verifyMessage, '', '');
                } else {
                    setMessage(loginMessage, payload.error || 'Login failed', 'error');
                }
            } catch (err) {
                setMessage(loginMessage, err.message, 'error');
            } finally {
                submitBtn.disabled = false;
                if (spinner) spinner.hidden = true;
            }
        });

        $('#verifyBackBtn').addEventListener('click', () => {
            stepVerify.hidden = true;
            stepLogin.hidden = false;
            setMessage(verifyMessage, '');
            setMessage(loginMessage, '');
        });

        $('#verifyForm').addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!pendingLogin) return;

            setMessage(verifyMessage, '');
            const submitBtn = $('#verifySubmitBtn');
            const spinner = $('.btn-spinner', submitBtn);
            submitBtn.disabled = true;
            if (spinner) spinner.hidden = true;

            try {
                const payload = await api('/api/staff/verify-device', {
                    method: 'POST',
                    json: {
                        email: pendingLogin.email,
                        password: pendingLogin.password,
                        code: $('#verifyCode').value.trim(),
                        deviceToken: pendingLogin.deviceToken,
                    },
                });

                if (payload.success) {
                    window.location.href = '/admin';
                } else {
                    setMessage(verifyMessage, payload.error || 'Verification failed', 'error');
                }
            } catch (err) {
                setMessage(verifyMessage, err.message, 'error');
            } finally {
                submitBtn.disabled = false;
                if (spinner) spinner.hidden = true;
            }
        });
    }

    /* ========================================================
       DASHBOARD
       ======================================================== */
    const state = {
        staff: {
            role: document.body.dataset.staffRole || '',
            email: document.body.dataset.staffEmail || '',
            name: document.body.dataset.staffName || '',
        },
        orders: [],
        inventory: [],
        reviews: [],
        logs: [],
        reviewLogs: [],
        devices: [],
        accounts: [],
        menuSnapshot: null,
        highlights: [],
        orderFilter: 'active',
        inventoryCategory: 'all',
        reviewRating: 0,
        logCategory: 'all',
        menuDraft: null,
        specialFoodsDraft: [],
        menuDataDraft: null,
    };

    const isAdmin = () => state.staff.role === 'Admin';

    function initDashboard() {
        if (!$('#sidebarNav')) return;

        // Hide admin-only nav for non-admin roles.
        if (!isAdmin()) {
            $$('[data-admin-only]').forEach((el) => { el.style.display = 'none'; });
        }

        // Tab switching.
        $$('#sidebarNav .nav-item').forEach((item) => {
            item.addEventListener('click', (event) => {
                event.preventDefault();
                const tab = item.dataset.tab;
                activateTab(tab);
            });
        });

        $('#logoutBtn').addEventListener('click', async () => {
            try { await api('/api/staff/logout', { method: 'POST' }); } catch (e) { /* ignore */ }
            window.location.href = '/admin/login';
        });

        // Orders sub-actions.
        $('#ordersList').addEventListener('click', onOrdersListClick);
        $('#overviewOrdersList').addEventListener('click', (e) => {
            const btn = e.target.closest('[data-goto-orders]');
            if (btn) activateTab('orders');
        });

        // Orders status filter.
        $$('.status-filter').forEach((btn) => {
            btn.addEventListener('click', () => {
                $$('.status-filter').forEach((b) => b.classList.toggle('is-active', b === btn));
                state.orderFilter = btn.dataset.status;
                renderOrders();
            });
        });

        // Inventory.
        $('#inventorySearch').addEventListener('input', () => renderInventory());
        $('#inventoryCategoryChips').addEventListener('click', (e) => {
            const chip = e.target.closest('.chip');
            if (!chip) return;
            $$('#inventoryCategoryChips .chip').forEach((c) => c.classList.toggle('is-active', c === chip));
            state.inventoryCategory = chip.dataset.category;
            renderInventory();
        });
        $('#inventoryAddBtn').addEventListener('click', () => openInventoryModal(null));
        $('#inventoryModal').addEventListener('click', (e) => {
            if (e.target.id === 'inventoryModal') closeModal('inventoryModal');
        });
        $('#inventoryForm').addEventListener('submit', onSubmitInventory);
        $('#invCategory').addEventListener('change', () => {
            $('#invImageField').hidden = $('#invCategory').value !== 'specials';
        });

        // Staff.
        $('#staffInviteBtn').addEventListener('click', () => openModal('staffModal'));
        $('#staffModal').addEventListener('click', (e) => {
            if (e.target.id === 'staffModal') closeModal('staffModal');
        });
        $('#staffForm').addEventListener('submit', onSubmitStaffInvite);

        // Reviews filter.
        $('#reviewRatingChips').addEventListener('click', (e) => {
            const chip = e.target.closest('.chip');
            if (!chip) return;
            $$('#reviewRatingChips .chip').forEach((c) => c.classList.toggle('is-active', c === chip));
            state.reviewRating = parseInt(chip.dataset.rating, 10);
            renderReviews();
        });

        // Logs filter.
        $('#logCategoryFilter').addEventListener('change', () => {
            state.logCategory = $('#logCategoryFilter').value;
            renderLogs();
        });

        // Menu.
        $('#specialFoodAddBtn').addEventListener('click', addSpecialFoodDraft);
        $('#menuItemAddBtn').addEventListener('click', addMenuItemDraft);
        $('#menuSaveBtn').addEventListener('click', saveMenuDraft);
        $('#highlightsSaveBtn').addEventListener('click', saveHighlights);
        $('#highlightsInput').addEventListener('change', uploadHighlights);

        // Credentials.
        $('#credRequestBtn').addEventListener('click', requestCredentialChange);
        $('#credentialsForm').addEventListener('submit', confirmCredentialChange);

        // Close buttons.
        $$('[data-close-modal]').forEach((btn) => {
            btn.addEventListener('click', () => closeModal(btn.dataset.closeModal));
        });

        activateTab('overview');
        startPolling();
        connectEvents();
    }

    function activateTab(tab) {
        $$('#sidebarNav .nav-item').forEach((item) => {
            item.classList.toggle('is-active', item.dataset.tab === tab);
        });
        $$('.panel').forEach((panel) => {
            panel.hidden = panel.id !== 'tab-' + tab;
        });

        if (tab === 'overview') loadOverview();
        if (tab === 'orders') loadOrders();
        if (tab === 'inventory') loadInventory();
        if (tab === 'staff') loadStaff();
        if (tab === 'reviews') loadReviews();
        if (tab === 'logs') loadLogs();
        if (tab === 'menu') loadMenu();
        if (tab === 'devices') loadDevices();
        if (tab === 'credentials') loadCredentials();
    }

    function openModal(id) { $('#' + id).hidden = false; }
    function closeModal(id) { $('#' + id).hidden = true; }

    /* --------------------------------------------------------
       OVERVIEW
       -------------------------------------------------------- */
    async function loadOverview() {
        try {
            const payload = await api('/api/staff/orders/pending');
            state.orders = payload.orders || [];

            const invPayload = await api('/api/staff/inventory');
            state.inventory = invPayload.items || [];

            renderOverview();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderOverview() {
        const pending = state.orders.filter((o) => o.status === 'pending');
        const preparing = state.orders.filter((o) => o.status === 'preparing');
        const ready = state.orders.filter((o) => o.status === 'ready');
        const unpaid = state.orders.filter((o) => o.payment_status === 'unpaid');

        $('#metricPending').textContent = pending.length;
        $('#metricPreparing').textContent = preparing.length;
        $('#metricReady').textContent = ready.length;
        $('#metricUnpaid').textContent = unpaid.length;

        const badge = $('#navOrderBadge');
        const activeCount = state.orders.length;
        badge.hidden = activeCount === 0;
        badge.textContent = activeCount;

        const listEl = $('#overviewOrdersList');
        listEl.innerHTML = state.orders.slice(0, 6).map((o) => {
            const items = (o.items || []).map((i) => i.name).join(', ') || 'No items';
            return '<div class="ov-order">' +
                '<div class="ov-order-num">#' + escapeHtml(o.order_number) + '</div>' +
                '<div class="ov-order-meta">' + escapeHtml(items) + '</div>' +
                '<span class="status-chip status-' + escapeHtml(o.status) + '">' + escapeHtml(o.status) + '</span>' +
                '<div class="ov-order-total">' + formatMoney(o.total_amount) + '</div>' +
                '</div>';
        }).join('') || '<p class="empty-state" style="padding:16px 0">No active orders.</p>';

        const lowStock = state.inventory.filter((i) => i.stock <= 5).sort((a, b) => a.stock - b.stock);
        const lowEl = $('#overviewLowStockList');
        lowEl.innerHTML = lowStock.slice(0, 8).map((i) =>
            '<div class="lowstock-item"><span>' + escapeHtml(i.name) + '</span>' +
            '<small>' + i.stock + ' left</small></div>'
        ).join('') || '<p class="empty-state" style="padding:16px 0">All stock levels look good.</p>';
    }

    /* --------------------------------------------------------
       ORDERS
       -------------------------------------------------------- */
    async function loadOrders() {
        try {
            const pendingPayload = await api('/api/staff/orders/pending');
            const completedPayload = await api('/api/staff/orders/completed');
            state.orders = pendingPayload.orders || [];
            state.completedOrders = completedPayload.orders || [];
            renderOrders();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderOrders() {
        const listEl = $('#ordersList');
        const emptyEl = $('#ordersEmpty');
        const source = state.orderFilter === 'completed' ? (state.completedOrders || []) : state.orders;

        if (!source.length) {
            listEl.innerHTML = '';
            emptyEl.hidden = false;
            return;
        }
        emptyEl.hidden = true;

        listEl.innerHTML = source.map(renderOrderCard).join('');
    }

    function renderOrderCard(o) {
        const items = (o.items || []).map((i) => {
            const components = (i.components && i.components.length)
                ? '<div class="order-components">' + escapeHtml(i.components.map((c) => c.name + ' x' + c.quantity).join(', ')) + '</div>'
                : '';
            return '<div class="order-item">' +
                '<span class="order-item-name">' + escapeHtml(i.name) + components + '</span>' +
                '<span class="order-item-qty">×' + i.quantity + '</span>' +
                '<span class="order-item-price">' + formatMoney(i.price) + '</span>' +
                '</div>';
        }).join('');

        const delivery = o.order_type && /delivery/i.test(o.order_type) ? (
            '<div class="order-delivery">Delivery: <strong>' + escapeHtml(o.delivery_address || 'No address') + '</strong>' +
            (o.customer_name ? ' · ' + escapeHtml(o.customer_name) : '') +
            (o.customer_phone ? ' · ' + escapeHtml(o.customer_phone) : '') +
            ' · fee ' + formatMoney(o.delivery_fee || 0) + '</div>'
        ) : '';

        const isActive = ['pending', 'preparing', 'ready'].includes(o.status);
        const buttons = [];
        if (isActive) {
            if (o.status === 'pending') buttons.push(btn('Start Preparing', 'btn-sm btn-blue', 'status', 'preparing'));
            if (o.status === 'preparing') buttons.push(btn('Mark Ready', 'btn-sm btn-amber', 'status', 'ready'));
            if (o.status !== 'completed') buttons.push(btn('Complete', 'btn-sm btn-green', 'status', 'completed'));
            buttons.push(btn('Edit', 'btn-sm btn-ghost', 'edit'));
            buttons.push(btn(o.payment_status === 'paid' ? 'Mark Unpaid' : 'Mark Paid', 'btn-sm ' + (o.payment_status === 'paid' ? 'btn-red' : 'btn-green'), 'payment'));
        }

        return '<div class="order-card" data-order-id="' + o.id + '">' +
            '<div class="order-card-head">' +
            '<span class="ov-order-num">#' + escapeHtml(o.order_number) + '</span>' +
            '<span class="status-chip status-' + escapeHtml(o.status) + '">' + escapeHtml(o.status) + '</span>' +
            '<span class="pay-chip pay-' + escapeHtml(o.payment_status) + '">' + escapeHtml(o.payment_status || 'unpaid') + '</span>' +
            '<span class="ov-order-meta">' + escapeHtml(o.order_type || '') + ' · ' + formatDateTime(o.order_date_iso || o.order_date) + '</span>' +
            '</div>' +
            (delivery) +
            '<div class="order-card-body"><div class="order-items">' + items + '</div></div>' +
            '<div class="order-card-foot">' + buttons.join('') +
            '<span class="order-total">' + formatMoney(o.total_amount) + '</span>' +
            '</div></div>';
    }

    function btn(label, className, act, payload) {
        const attrs = 'data-act="' + escapeHtml(act) + '"' + (payload !== undefined ? ' data-status="' + escapeHtml(payload) + '"' : '');
        return '<button type="button" class="' + className + '" ' + attrs + '>' + label + '</button>';
    }

    function onOrdersListClick(e) {
        const card = e.target.closest('.order-card');
        if (!card) return;
        const orderId = parseInt(card.dataset.orderId, 10);
        const order = [...state.orders, ...(state.completedOrders || [])].find((o) => o.id === orderId);
        if (!order) return;

        const button = e.target.closest('button[data-act]');
        if (!button) return;
        const act = button.dataset.act;
        if (act === 'status') setOrderStatus(order.id, button.dataset.status);
        if (act === 'payment') togglePayment(order);
        if (act === 'edit') openOrderModal(order);
    }

    async function setOrderStatus(orderId, status) {
        try {
            await api('/api/staff/orders/' + orderId + '/status', { method: 'POST', json: { status } });
            toast('Order marked ' + status, 'ok');
            await loadOrders();
            renderOverview();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function togglePayment(order) {
        try {
            const next = order.payment_status === 'paid' ? 'unpaid' : 'paid';
            await api('/api/staff/orders/' + order.id + '/payment', { method: 'POST', json: { payment_status: next } });
            toast('Payment ' + next, 'ok');
            await loadOrders();
            renderOverview();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    // Order edit modal (quantity + components + payment).
    let editingOrder = null;

    function openOrderModal(order) {
        const body = $('#orderModalBody');
        editingOrder = order;
        $('#orderModalTitle').textContent = 'Order #' + order.order_number;

        body.innerHTML = '<div class="order-modal-items">' + (order.items || []).map((item) =>
            '<div class="om-item" data-item-id="' + item.id + '">' +
            '<div class="om-item-name">' + escapeHtml(item.name) +
            (item.components && item.components.length ? '<div class="order-components">' + escapeHtml(item.components.map((c) => c.name + ' x' + c.quantity).join(', ')) + '</div>' : '') +
            '</div>' +
            '<div class="om-qty-controls">' +
            '<button type="button" class="om-qty-btn remove" data-qty="-1">−</button>' +
            '<span class="om-qty-value">' + item.quantity + '</span>' +
            '<button type="button" class="om-qty-btn" data-qty="1">+</button>' +
            '</div>' +
            '<span class="om-item-price">' + formatMoney(item.price) + '</span>' +
            '</div>'
        ).join('') + '</div>';

        openModal('orderModal');
    }

    // Registered once (not per open) so repeated opens never stack handlers.
    if (document.getElementById('sidebarNav')) {
        $('#orderModalBody').addEventListener('click', async (e) => {
            const btn = e.target.closest('.om-qty-btn');
            if (!btn || !editingOrder) return;
            const itemEl = btn.closest('.om-item');
            const itemId = parseInt(itemEl.dataset.itemId, 10);
            const delta = parseInt(btn.dataset.qty, 10);
            const item = (editingOrder.items || []).find((i) => i.id === itemId);
            if (!item) return;
            const nextQty = Math.max(0, item.quantity + delta);

            try {
                await api('/api/staff/orders/items/update', {
                    method: 'POST',
                    json: { orderId: editingOrder.id, itemId, quantity: nextQty },
                });
                toast('Quantity updated', 'ok');
                closeModal('orderModal');
                await loadOrders();
                renderOverview();
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    }

    /* --------------------------------------------------------
       INVENTORY
       -------------------------------------------------------- */
    async function loadInventory() {
        try {
            const payload = await api('/api/staff/inventory');
            state.inventory = payload.items || [];
            renderInventoryChips();
            renderInventory();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderInventoryChips() {
        const categories = ['all'];
        state.inventory.forEach((item) => {
            if (item.category && !categories.includes(item.category)) categories.push(item.category);
        });
        $('#inventoryCategoryChips').innerHTML = categories.map((cat) =>
            '<button type="button" class="chip ' + (cat === state.inventoryCategory ? 'is-active' : '') + '" data-category="' + escapeHtml(cat) + '">' +
            escapeHtml(cat.replace(/([A-Z])/g, ' $1').replace(/^./, (c) => c.toUpperCase())) + '</button>'
        ).join('');
    }

    function renderInventory() {
        const query = ($('#inventorySearch').value || '').trim().toLowerCase();
        const filtered = state.inventory.filter((item) => {
            const matchesQuery = !query || (item.name || '').toLowerCase().includes(query);
            const matchesCat = state.inventoryCategory === 'all' || item.category === state.inventoryCategory;
            return matchesQuery && matchesCat;
        });

        $('#inventoryEmpty').hidden = filtered.length > 0;
        $('#inventoryGrid').innerHTML = filtered.map((item) => {
            const thumb = item.image
                ? '<img class="inv-thumb" src="' + escapeHtml(item.image) + '" alt="">'
                : '<div class="inv-thumb"></div>';
            const stockClass = item.stock <= 0 ? 'pay-unpaid' : (item.stock <= 5 ? 'status-pending' : 'pay-paid');
            return '<div class="inventory-card">' +
                thumb +
                '<div class="inv-cat">' + escapeHtml(item.category || '') + '</div>' +
                '<div class="inv-name">' + escapeHtml(item.name) + '</div>' +
                '<div class="inv-price">' + formatMoney(item.price) + '</div>' +
                '<div class="inv-stock"><span class="status-chip ' + stockClass + '">' + item.stock + ' in stock</span></div>' +
                (item.description ? '<div class="inv-desc">' + escapeHtml(item.description) + '</div>' : '') +
                '<div class="inv-actions">' +
                '<button type="button" class="btn-sm btn-blue" data-inv-edit="' + escapeHtml(item.name) + '">Edit</button>' +
                '<button type="button" class="btn-sm btn-red" data-inv-delete="' + escapeHtml(item.name) + '">Delete</button>' +
                '</div></div>';
        }).join('');
    }

    if (document.getElementById('sidebarNav')) {
        $('#inventoryGrid').addEventListener('click', async (e) => {
            const editBtn = e.target.closest('[data-inv-edit]');
            if (editBtn) {
                const item = state.inventory.find((i) => i.name === editBtn.dataset.invEdit);
                if (item) openInventoryModal(item);
                return;
            }
            const deleteBtn = e.target.closest('[data-inv-delete]');
            if (deleteBtn) {
                const name = deleteBtn.dataset.invDelete;
                if (!confirm('Delete "' + name + '" from inventory?')) return;
                try {
                    await api('/api/staff/inventory/delete', { method: 'POST', json: { name } });
                    toast('Product deleted', 'ok');
                    await loadInventory();
                } catch (err) {
                    toast(err.message, 'error');
                }
            }
        });
    }

    function openInventoryModal(item) {
        $('#inventoryModalTitle').textContent = item ? 'Edit Product' : 'Add Product';
        $('#invPreviousName').value = item ? item.name : '';
        $('#invName').value = item ? item.name : '';
        $('#invCategory').value = item ? (item.category || 'specials') : 'specials';
        $('#invPrice').value = item ? item.price : '';
        $('#invStock').value = item ? item.stock : '';
        $('#invDescription').value = item ? (item.description || '') : '';
        $('#invStatus').value = item ? (item.status || 'In stock') : 'In stock';
        $('#invImageField').hidden = !(item && item.category === 'specials') && $('#invCategory').value !== 'specials';
        openModal('inventoryModal');
    }

    async function onSubmitInventory(e) {
        e.preventDefault();
        const previousName = $('#invPreviousName').value;
        const payload = {
            name: $('#invName').value.trim(),
            previousName,
            price: parseFloat($('#invPrice').value) || 0,
            stock: parseInt($('#invStock').value, 10) || 0,
            category: $('#invCategory').value,
            description: $('#invDescription').value.trim(),
            status: $('#invStatus').value,
        };

        // Specials can carry an image upload.
        const imageInput = $('#invImage');
        if (imageInput && imageInput.files && imageInput.files[0] && payload.category === 'specials') {
            try {
                const formData = new FormData();
                formData.append('image', imageInput.files[0]);
                const upload = await api('/api/staff/inventory/upload-image', { method: 'POST', formData });
                payload.image = upload.relativeUrl || upload.url;
            } catch (err) {
                toast('Image upload failed: ' + err.message, 'error');
                return;
            }
        }

        try {
            await api('/api/staff/inventory/save', { method: 'POST', json: payload });
            toast('Product saved', 'ok');
            closeModal('inventoryModal');
            await loadInventory();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    /* --------------------------------------------------------
       STAFF
       -------------------------------------------------------- */
    async function loadStaff() {
        try {
            const payload = await api('/api/staff/accounts');
            state.accounts = payload.accounts || [];
            renderStaff();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderStaff() {
        const listEl = $('#staffList');
        listEl.innerHTML = state.accounts.map((acc) => {
            const roleClass = acc.role === 'Admin' ? 'admin' : (acc.role === 'Inventory Manager' ? 'inv' : '');
            return '<div class="staff-row">' +
                '<div class="staff-avatar">' + escapeHtml((acc.name || '?').charAt(0).toUpperCase()) + '</div>' +
                '<div class="staff-meta">' +
                '<strong>' + escapeHtml(acc.name) + '</strong>' +
                '<span>' + escapeHtml(acc.email) + (acc.inviteConfirmed ? '' : ' · invite pending') + '</span>' +
                '</div>' +
                '<span class="staff-role-chip ' + roleClass + '">' + escapeHtml(acc.role) + '</span>' +
                '</div>';
        }).join('') || '<p class="empty-state">No staff accounts yet.</p>';
    }

    async function onSubmitStaffInvite(e) {
        e.preventDefault();
        const message = $('#staffFormMessage');
        setMessage(message, '');

        try {
            const payload = await api('/api/staff/invite', {
                method: 'POST',
                json: {
                    name: $('#staffName').value.trim(),
                    role: $('#staffRole').value,
                    email: $('#staffEmail').value.trim(),
                },
            });
            setMessage(message, payload.success ? 'Invite sent! They must confirm with the emailed code on first login.' : (payload.error || 'Invite failed'), payload.success ? 'ok' : 'error');
            if (payload.success) {
                $('#staffName').value = '';
                $('#staffEmail').value = '';
                closeModal('staffModal');
                await loadStaff();
            }
        } catch (err) {
            setMessage(message, err.message, 'error');
        }
    }

    /* --------------------------------------------------------
       REVIEWS
       -------------------------------------------------------- */
    async function loadReviews() {
        try {
            const payload = await api('/api/staff/reviews?scope=staff');
            state.reviews = payload.reviews || [];
            renderReviews();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderReviews() {
        const filtered = state.reviews.filter((r) => !state.reviewRating || r.rating === state.reviewRating);
        $('#reviewsEmpty').hidden = filtered.length > 0;
        $('#reviewsList').innerHTML = filtered.map((r) =>
            '<div class="review-card">' +
            '<div class="review-head">' +
            '<span class="review-stars">' + '★'.repeat(r.rating) + '☆'.repeat(5 - r.rating) + '</span>' +
            '<span class="status-chip status-' + (r.publish_status === 'published' ? 'completed' : 'pending') + '">' + escapeHtml(r.publish_status) + '</span>' +
            '<span class="review-date">' + formatDateTime(r.created_at_iso || r.created_at) + '</span>' +
            '</div>' +
            '<p class="review-text">' + escapeHtml(r.review_text) + '</p>' +
            '<div class="review-actions">' +
            (r.publish_status !== 'published' ? '<button type="button" class="btn-sm btn-green" data-review-publish="' + r.id + '">Publish</button>' : '') +
            '<button type="button" class="btn-sm btn-red" data-review-delete="' + r.id + '">Delete</button>' +
            '</div></div>'
        ).join('');
    }

    if (document.getElementById('sidebarNav')) {
        $('#reviewsList').addEventListener('click', async (e) => {
            const publishBtn = e.target.closest('[data-review-publish]');
            const deleteBtn = e.target.closest('[data-review-delete]');

            if (publishBtn) {
                try {
                    await api('/api/staff/reviews/publish', { method: 'POST', json: { reviewId: parseInt(publishBtn.dataset.reviewPublish, 10) } });
                    toast('Review published', 'ok');
                    await loadReviews();
                } catch (err) { toast(err.message, 'error'); }
            }
            if (deleteBtn) {
                if (!confirm('Delete this review? The reviewer is blocked for today.')) return;
                try {
                    await api('/api/staff/reviews/delete', { method: 'POST', json: { reviewId: parseInt(deleteBtn.dataset.reviewDelete, 10) } });
                    toast('Review deleted', 'ok');
                    await loadReviews();
                } catch (err) { toast(err.message, 'error'); }
            }
        });
    }

    /* --------------------------------------------------------
       LOGS
       -------------------------------------------------------- */
    async function loadLogs() {
        try {
            const [orderPayload, reviewPayload] = await Promise.all([
                api('/api/staff/orders/logs'),
                api('/api/staff/reviews/logs'),
            ]);
            state.logs = orderPayload.logs || [];
            state.reviewLogs = reviewPayload.logs || [];
            renderLogs();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderLogs() {
        const orderLogs = (state.logs || []).map((l) => Object.assign({}, l, { kind: 'orders' }));
        const reviewLogs = (state.reviewLogs || []).map((l) => Object.assign({}, l, { kind: 'reviews' }));
        let all = [...orderLogs, ...reviewLogs].sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));

        const cat = state.logCategory;
        if (cat === 'orders') all = all.filter((l) => l.kind === 'orders');
        if (cat === 'reviews') all = all.filter((l) => l.kind === 'reviews');
        if (cat === 'stock') all = all.filter((l) => /stock|inventory/.test(l.action || ''));
        if (cat === 'accounts') all = all.filter((l) => /invite|staff|credential|account|login|device/.test(l.action || ''));

        $('#logsEmpty').hidden = all.length > 0;
        $('#logsList').innerHTML = all.slice(0, 120).map((l) => {
            const cls = (l.action || '').replace(/^review_/, 'review ');
            return '<div class="log-row">' +
                '<span class="log-action">' + escapeHtml((l.action || 'activity').slice(0, 24)) + '</span>' +
                '<span class="log-summary">' + escapeHtml(l.summary || l.details || '') + '</span>' +
                (l.actor_email ? '<span class="log-meta">' + escapeHtml(l.actor_email) + '</span>' : '') +
                '<span class="log-meta">' + formatDateTime(l.created_at_iso || l.created_at) + '</span>' +
                '</div>';
        }).join('');
    }

    /* --------------------------------------------------------
       MENU (special foods, highlights, categories)
       -------------------------------------------------------- */
    async function loadMenu() {
        try {
            const [menuPayload, highlightsPayload, inventoryPayload] = await Promise.all([
                api('/api/staff/menu'),
                api('/api/staff/highlights'),
                api('/api/staff/inventory'),
            ]);
            state.menuSnapshot = menuPayload.snapshot || {};
            state.highlights = highlightsPayload.slides || [];
            state.inventory = inventoryPayload.items || [];
            initMenuDrafts();
            renderMenu();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function initMenuDrafts() {
        state.specialFoodsDraft = Array.isArray(state.menuSnapshot.specialFoods)
            ? state.menuSnapshot.specialFoods.map((f) => ({
                name: f.name || '', price: f.price || 0, description: f.description || '', image: f.image || '',
            }))
            : [];
        state.menuDataDraft = state.menuSnapshot.menuData || {};
    }

    function renderMenu() {
        // Special foods.
        $('#specialFoodsList').innerHTML = state.specialFoodsDraft.map((food, idx) =>
            '<div class="special-food-card">' +
            (food.image ? '<img src="' + escapeHtml(food.image) + '" alt="">' : '<img alt="">') +
            '<div class="sf-meta">' +
            '<div class="sf-row"><input type="text" data-sf="name" data-idx="' + idx + '" value="' + escapeHtml(food.name) + '" placeholder="Dish name">' +
            '<input type="number" min="0" step="0.01" data-sf="price" data-idx="' + idx + '" value="' + (food.price ?? '') + '" placeholder="Price"></div>' +
            '<textarea rows="2" data-sf="description" data-idx="' + idx + '" placeholder="Description">' + escapeHtml(food.description || '') + '</textarea>' +
            '<div class="inv-actions">' +
            '<button type="button" class="btn-sm btn-blue" data-sf-image="' + idx + '">Upload Image</button>' +
            '<button type="button" class="btn-sm btn-red" data-sf-remove="' + idx + '">Remove</button>' +
            '</div></div></div>'
        ).join('') || '<p class="empty-state" style="padding:10px 0">No special foods yet.</p>';

        // Highlights.
        $('#highlightsList').innerHTML = state.highlights.map((url, idx) =>
            '<div class="highlight-tile"><img src="' + escapeHtml(url) + '" alt=""><button type="button" class="highlight-remove" data-hl-remove="' + idx + '">×</button></div>'
        ).join('');

        // Menu categories.
        $('#menuCategoriesList').innerHTML = Object.entries(state.menuDataDraft).map(([category, catData]) => {
            const items = (catData.items || []).map((item, itemIdx) =>
                '<div class="menu-cat-item">' +
                '<input type="text" class="mi-name" data-mi-cat="' + escapeHtml(category) + '" data-mi-idx="' + itemIdx + '" data-mi-field="name" value="' + escapeHtml(item.name || '') + '">' +
                '<input type="number" class="mi-price" min="0" step="0.01" data-mi-cat="' + escapeHtml(category) + '" data-mi-idx="' + itemIdx + '" data-mi-field="price" value="' + (item.price ?? '') + '">' +
                '<button type="button" class="btn-sm btn-red" data-mi-remove="' + escapeHtml(category) + ':' + itemIdx + '">×</button>' +
                '</div>'
            ).join('');
            return '<div class="menu-cat-block"><div class="menu-cat-head"><strong>' + escapeHtml(category) + '</strong></div>' + items + '</div>';
        }).join('') || '<p class="empty-state" style="padding:10px 0">No menu categories yet.</p>';
    }

    if (document.getElementById('sidebarNav')) {
        $('#specialFoodsList').addEventListener('input', (e) => {
            const input = e.target;
            const idx = parseInt(input.dataset.idx, 10);
            if (idx === undefined || isNaN(idx)) return;
            if (input.dataset.sf === 'name') state.specialFoodsDraft[idx].name = input.value;
            if (input.dataset.sf === 'price') state.specialFoodsDraft[idx].price = parseFloat(input.value) || 0;
            if (input.dataset.sf === 'description') state.specialFoodsDraft[idx].description = input.value;
        });

        $('#specialFoodsList').addEventListener('click', (e) => {
            const imageBtn = e.target.closest('[data-sf-image]');
            const removeBtn = e.target.closest('[data-sf-remove]');
            if (imageBtn) {
                const idx = parseInt(imageBtn.dataset.sfImage, 10);
                const fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.accept = 'image/*';
                fileInput.addEventListener('change', async () => {
                    if (!fileInput.files[0]) return;
                    try {
                        const formData = new FormData();
                        formData.append('image', fileInput.files[0]);
                        const upload = await api('/api/staff/inventory/upload-image', { method: 'POST', formData });
                        state.specialFoodsDraft[idx].image = upload.relativeUrl || upload.url;
                        renderMenu();
                    } catch (err) { toast(err.message, 'error'); }
                });
                fileInput.click();
            }
            if (removeBtn) {
                state.specialFoodsDraft.splice(parseInt(removeBtn.dataset.sfRemove, 10), 1);
                renderMenu();
            }
        });

        $('#highlightsList').addEventListener('click', (e) => {
            const removeBtn = e.target.closest('[data-hl-remove]');
            if (!removeBtn) return;
            state.highlights.splice(parseInt(removeBtn.dataset.hlRemove, 10), 1);
            renderMenu();
        });

        $('#menuCategoriesList').addEventListener('input', (e) => {
            const input = e.target;
            if (!input.dataset.miField) return;
            const category = input.dataset.miCat;
            const idx = parseInt(input.dataset.miIdx, 10);
            const items = (state.menuDataDraft[category] && state.menuDataDraft[category].items) || [];
            if (!items[idx]) return;
            if (input.dataset.miField === 'name') items[idx].name = input.value;
            if (input.dataset.miField === 'price') items[idx].price = parseFloat(input.value) || 0;
        });

        $('#menuCategoriesList').addEventListener('click', (e) => {
            const removeBtn = e.target.closest('[data-mi-remove]');
            if (!removeBtn) return;
            const [category, idx] = removeBtn.dataset.miRemove.split(':');
            if (!state.menuDataDraft[category] || !state.menuDataDraft[category].items) return;
            state.menuDataDraft[category].items.splice(parseInt(idx, 10), 1);
            renderMenu();
        });
    }

    function addSpecialFoodDraft() {
        state.specialFoodsDraft.push({ name: '', price: 0, description: '', image: '' });
        renderMenu();
    }

    function addMenuItemDraft() {
        const categories = Object.keys(state.menuDataDraft);
        const category = categories[0] || 'silog';
        if (!state.menuDataDraft[category]) state.menuDataDraft[category] = { items: [] };
        state.menuDataDraft[category].items.push({ name: '', price: 0 });
        renderMenu();
    }

    async function saveMenuDraft() {
        const message = $('#menuMessage');
        setMessage(message, '');
        try {
            // Preserve every top-level snapshot key; only overwrite the two
            // sections this editor manages so no data is lost.
            const snapshot = Object.assign({}, state.menuSnapshot);
            snapshot.specialFoods = state.specialFoodsDraft.filter((f) => f.name.trim() !== '');
            snapshot.menuData = state.menuDataDraft;

            await api('/api/staff/menu/save', {
                method: 'POST',
                json: snapshot,
            });
            setMessage(message, 'Menu published.', 'ok');
            toast('Menu published', 'ok');
        } catch (err) {
            setMessage(message, err.message, 'error');
        }
    }

    async function uploadHighlights() {
        const input = $('#highlightsInput');
        const files = Array.from(input.files || []);
        if (!files.length) return;

        $('#highlightsMessage').textContent = 'Uploading…';
        try {
            for (const file of files) {
                if (state.highlights.length >= 15) break;
                const formData = new FormData();
                formData.append('image', file);
                const upload = await api('/api/staff/inventory/upload-image', { method: 'POST', formData });
                state.highlights.push(upload.relativeUrl || upload.url);
            }
            input.value = '';
            renderMenu();
            $('#highlightsMessage').textContent = files.length + ' image(s) staged. Click Save Highlights to publish.';
        } catch (err) {
            $('#highlightsMessage').textContent = err.message;
        }
    }

    async function saveHighlights() {
        const message = $('#highlightsMessage');
        setMessage(message, '');
        try {
            const payload = await api('/api/staff/highlights/save', { method: 'POST', json: { slides: state.highlights } });
            setMessage(message, 'Highlights saved.', 'ok');
            toast('Highlights saved', 'ok');
        } catch (err) {
            setMessage(message, err.message, 'error');
        }
    }

    /* --------------------------------------------------------
       DEVICES
       -------------------------------------------------------- */
    async function loadDevices() {
        try {
            const payload = await api('/api/staff/devices?email=' + encodeURIComponent(state.staff.email));
            state.devices = payload.devices || [];
            renderDevices();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderDevices() {
        $('#devicesEmpty').hidden = state.devices.length > 0;
        $('#devicesList').innerHTML = state.devices.map((d) =>
            '<div class="device-row">' +
            '<div class="device-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg></div>' +
            '<div class="device-meta">' +
            '<strong>' + escapeHtml(d.device_label) + (d.is_current ? ' <span class="device-current">This device</span>' : '') + '</strong>' +
            '<span>' + escapeHtml(d.ip_address || '') + ' · first seen ' + escapeHtml(d.first_seen_at || '') + '</span>' +
            '</div>' +
            (d.is_current ? '' : '<button type="button" class="btn-sm btn-red" data-device-revoke="' + d.id + '">Revoke</button>') +
            '</div>'
        ).join('');
    }

    if (document.getElementById('sidebarNav')) {
        $('#devicesList').addEventListener('click', async (e) => {
            const revokeBtn = e.target.closest('[data-device-revoke]');
            if (!revokeBtn) return;
            const device = state.devices.find((d) => d.id === parseInt(revokeBtn.dataset.deviceRevoke, 10));
            if (!device || !confirm('Revoke this device?')) return;
            try {
                await api('/api/staff/devices/revoke', {
                    method: 'POST',
                    json: { email: state.staff.email, fingerprint: device.fingerprint, deviceToken: getOrCreateDeviceToken() },
                });
                toast('Device revoked', 'ok');
                await loadDevices();
            } catch (err) { toast(err.message, 'error'); }
        });
    }

    /* --------------------------------------------------------
       CREDENTIALS
       -------------------------------------------------------- */
    async function loadCredentials() {
        try {
            const payload = await api('/api/staff/credentials');
            $('#credCurrentEmail').value = payload.credentials.email || '';
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function requestCredentialChange() {
        const message = $('#credMessage');
        setMessage(message, '');
        try {
            await api('/api/staff/credentials/change-request', {
                method: 'POST',
                json: {
                    currentEmail: $('#credCurrentEmail').value.trim(),
                    currentPassword: $('#credCurrentPassword').value,
                    newEmail: $('#credNewEmail').value.trim(),
                    newPassword: $('#credNewPassword').value,
                },
            });
            $('#credCodeField').hidden = false;
            $('#credConfirmWrap').hidden = false;
            setMessage(message, 'Verification code sent to your email.', 'ok');
        } catch (err) {
            setMessage(message, err.message, 'error');
        }
    }

    async function confirmCredentialChange(e) {
        e.preventDefault();
        const message = $('#credMessage');
        setMessage(message, '');
        try {
            await api('/api/staff/credentials/change-confirm', {
                method: 'POST',
                json: {
                    currentEmail: $('#credCurrentEmail').value.trim(),
                    currentPassword: $('#credCurrentPassword').value,
                    code: $('#credCode').value.trim(),
                },
            });
            setMessage(message, 'Credentials updated.', 'ok');
            toast('Credentials updated', 'ok');
            $('#credCodeField').hidden = true;
            $('#credConfirmWrap').hidden = true;
            $('#credCode').value = '';
            $('#credNewEmail').value = '';
            $('#credNewPassword').value = '';
            $('#credCurrentPassword').value = '';
            await loadCredentials();
        } catch (err) {
            setMessage(message, err.message, 'error');
        }
    }

    /* --------------------------------------------------------
       Real-time: SSE events + polling fallback
       -------------------------------------------------------- */
    function connectEvents() {
        if (typeof EventSource === 'undefined') return;
        try {
            const source = new EventSource('/api/staff/orders/events');
            source.onmessage = () => { refreshAfterEvent(); };
            source.onerror = () => { /* browser auto-reconnects */ };
        } catch (e) {
            /* polling fallback below */
        }
    }

    let lastRefresh = 0;
    function startPolling() {
        setInterval(() => {
            // Refresh every 12s if the user is on a data-heavy tab.
            if (Date.now() - lastRefresh < 10000) return;
            lastRefresh = Date.now();
            refreshAfterEvent();
        }, 12000);
    }

    function refreshAfterEvent() {
        const visible = $('#dashMain .panel:not([hidden])');
        if (!visible) return;
        const tab = visible.id.replace('tab-', '');
        if (tab === 'overview' || tab === 'orders') {
            loadOrders().then(() => renderOverview());
        }
    }

    /* -------------------------------------------------------- */
    if (document.getElementById('loginForm')) initLogin();
    else initDashboard();
})();
