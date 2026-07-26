const openModalBtn = document.getElementById('openModalBtn');
const closeModalBtn = document.getElementById('closeModalBtn');
const modal = document.getElementById('adminModal');
const roleButtons = document.querySelectorAll('.role-tab');
const loginFields = document.getElementById('loginFields');
const selectedRoleInput = document.getElementById('selectedRole');
const modalTitle = document.getElementById('modalTitle');
const emailInput = document.getElementById('email');
const passwordInput = document.getElementById('password');
const rememberCheckbox = document.getElementById('rememberCredentials');
const logoutBtn = document.getElementById('logoutBtn');
const menuBtn = document.getElementById('menuBtn');
const dashboardPanel = document.getElementById('dashboardPanel');
const closePanelBtn = document.getElementById('closePanelBtn');
const dashboardUserName = document.getElementById('dashboardUserName');
const dashboardUserEmail = document.getElementById('dashboardUserEmail');
const staffForm = document.getElementById('staffLoginForm');
const staffLoginPage = document.querySelector('.staff-login-page');
const allowedRoles = ['Admin', 'Cashier', 'Inventory Manager'];
const staffSessionStorageKey = 'motasteStaffSession';
const staffActiveSectionStorageKey = 'motasteStaffActiveSection';
const staffAccountsStorageKey = 'motasteStaffAccounts';
let inventoryRefreshTimer = null;
let inventoryRefreshVersion = 0;
let inventorySyncInFlight = false;
let lastInventoryUpdateAt = 0;
let staffAccountsSyncInFlight = false;
let staffAccountsRefreshTimer = null;
const isStaffPage = Boolean(document.getElementById('accountList') || document.getElementById('staffLoginForm'));

const defaultStaffAccounts = [
    { name: 'Administrator', role: 'Admin', email: 'admin@motaste.com', password: 'admin123' },
    { name: 'Cashier One', role: 'Cashier', email: 'cashier@motaste.com', password: 'cashier123' },
    { name: 'Inventory One', role: 'Inventory Manager', email: 'inventory@motaste.com', password: 'inventory123' }
];

let accounts = [...defaultStaffAccounts];
window.motasteStaffAccounts = accounts;

function normalizeStaffAccount(account) {
    if (!account || typeof account !== 'object') return null;

    const name = (account.name || '').trim();
    const role = (account.role || '').trim();
    const email = (account.email || '').trim().toLowerCase();
    const password = (account.password || '').toString();

    if (!name || !role || !email || !password) return null;
    if (!allowedRoles.includes(role)) return null;

    return { name, role, email, password };
}

function getCurrentStaffAccounts() {
    return Array.isArray(accounts) && accounts.length ? accounts : [...defaultStaffAccounts];
}

function saveStaffAccountsToStorage() {
    try {
        localStorage.setItem(staffAccountsStorageKey, JSON.stringify(accounts));
    } catch (error) {
        console.error('Unable to persist staff accounts to localStorage', error);
    }
}

function loadStaffAccountsFromStorage() {
    try {
        const raw = localStorage.getItem(staffAccountsStorageKey);
        if (!raw) return false;

        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return false;

        const normalized = parsed.map(normalizeStaffAccount).filter(Boolean);
        if (!normalized.length) return false;

        accounts = normalized;
        window.motasteStaffAccounts = accounts;
        return true;
    } catch (error) {
        console.error('Unable to load staff accounts from localStorage', error);
        return false;
    }
}

function applyStaffAccountsSnapshot(snapshot) {
    if (!Array.isArray(snapshot)) return false;

    const normalized = snapshot.map(normalizeStaffAccount).filter(Boolean);
    if (!normalized.length) return false;

    const currentSignature = JSON.stringify(accounts);
    const nextSignature = JSON.stringify(normalized);
    if (currentSignature === nextSignature) return false;

    accounts = normalized;
    window.motasteStaffAccounts = accounts;
    saveStaffAccountsToStorage();
    return true;
}

async function loadStaffAccountsFromServer(forceRefresh = false) {
    if (staffAccountsSyncInFlight && !forceRefresh) return false;

    staffAccountsSyncInFlight = true;
    try {
        const response = await fetch(getApiUrl(`api/get_staff_accounts.php?_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) return false;

        const payload = await response.json();
        if (!payload || payload.success !== true) return false;

        if (Array.isArray(payload.accounts) && payload.accounts.length) {
            const changed = applyStaffAccountsSnapshot(payload.accounts);
            if (changed) {
                renderAccounts();
            }
            return changed;
        }

        return false;
    } catch (error) {
        console.error('Unable to load staff accounts from server', error);
        return false;
    } finally {
        staffAccountsSyncInFlight = false;
    }
}

function saveStaffAccountsToServer() {
    saveStaffAccountsToStorage();
    window.motasteStaffAccounts = accounts;

    void fetch(getApiUrl('api/save_staff_accounts.php'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(accounts),
        cache: 'no-store'
    }).catch((error) => {
        console.error('Unable to sync staff accounts to server', error);
    });
}

function startStaffAccountsRefresh() {
    if (!isStaffPage || staffAccountsRefreshTimer) return;

    staffAccountsRefreshTimer = window.setInterval(() => {
        void loadStaffAccountsFromServer(true);
    }, 10000);
}

function stopStaffAccountsRefresh() {
    if (staffAccountsRefreshTimer) {
        window.clearInterval(staffAccountsRefreshTimer);
        staffAccountsRefreshTimer = null;
    }
}

function getApiUrl(path) {
    try {
        return new URL(path, window.location.href).toString();
    } catch (error) {
        return path;
    }
}

function normalizeInventoryName(name) {
    return (name || '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function getSavedLoginCredentials() {
    try {
        return JSON.parse(localStorage.getItem('motasteSavedCredentials') || '{}');
    } catch (error) {
        return {};
    }
}

function storeSavedLoginCredentials(credentials) {
    localStorage.setItem('motasteSavedCredentials', JSON.stringify(credentials));
}

function saveCredentialsForRole(role, email, password) {
    if (!role) return;
    const saved = getSavedLoginCredentials();
    saved[role] = { email, password };
    storeSavedLoginCredentials(saved);
}

function clearSavedCredentialsForRole(role) {
    if (!role) return;
    const saved = getSavedLoginCredentials();
    delete saved[role];
    storeSavedLoginCredentials(saved);
}

function loadSavedCredentialsForRole(role) {
    const saved = getSavedLoginCredentials()[role];
    if (!saved) {
        if (emailInput) emailInput.value = '';
        if (passwordInput) passwordInput.value = '';
        if (rememberCheckbox) rememberCheckbox.checked = false;
        return;
    }

    if (emailInput) emailInput.value = saved.email || '';
    if (passwordInput) passwordInput.value = saved.password || '';
    if (rememberCheckbox) rememberCheckbox.checked = true;
}

function getPersistedStaffSession() {
    try {
        const raw = localStorage.getItem(staffSessionStorageKey);
        return raw ? JSON.parse(raw) : null;
    } catch (error) {
        return null;
    }
}

function saveStaffSession(role, email, password, remember) {
    if (!role || !email || !password) return;
    const payload = { role, email, password, remember: Boolean(remember) };
    localStorage.setItem(staffSessionStorageKey, JSON.stringify(payload));
}

function clearStaffSession() {
    localStorage.removeItem(staffSessionStorageKey);
    localStorage.removeItem(staffActiveSectionStorageKey);
}

function saveActiveSection(sectionId) {
    if (!sectionId) return;
    localStorage.setItem(staffActiveSectionStorageKey, sectionId);
}

function getPersistedActiveSection() {
    try {
        return localStorage.getItem(staffActiveSectionStorageKey) || 'overview';
    } catch (error) {
        return 'overview';
    }
}

function restoreStaffSession() {
    const persistedSession = getPersistedStaffSession();
    if (!persistedSession) return false;

    const { role, email, password } = persistedSession;
    if (!role || !email || !password || !allowedRoles.includes(role) || !isValidStaffLogin(role, email, password)) {
        clearStaffSession();
        return false;
    }

    if (selectedRoleInput) {
        selectedRoleInput.value = role;
    }
    if (emailInput) {
        emailInput.value = email;
    }
    if (passwordInput) {
        passwordInput.value = password;
    }
    if (rememberCheckbox) {
        rememberCheckbox.checked = Boolean(persistedSession.remember);
    }

    if (modalTitle) {
        modalTitle.textContent = `Logged in as ${role}`;
    }

    updateDashboardProfile();

    if (loginFields) {
        loginFields.hidden = true;
    }

    const staffBox = document.querySelector('.staff-box');
    if (staffBox) {
        staffBox.style.display = 'none';
    }
    if (staffLoginPage) {
        staffLoginPage.hidden = true;
    }

    document.body.classList.add('auth');
    updateAccountManagementAccess();
    renderInventoryManagement();
    setAuthButtonsVisible(true);

    const targetSectionId = getPersistedActiveSection();
    const targetSection = document.getElementById(targetSectionId);
    if (targetSection) {
        showDashboardSection(targetSection);
        if (targetSectionId === 'overview') {
            renderOverviewAnalytics();
            renderOrderNotifications();
            renderOverviewInventory();
        } else if (targetSectionId === 'pending-orders') {
            void loadPendingOrdersFromServer();
            renderPendingOrders();
        } else if (targetSectionId === 'sales') {
            updateAnalyticsView();
        } else if (targetSectionId === 'inventory') {
            renderOverviewInventory();
        }
    } else {
        showDashboardSection(overviewSection);
        renderOverviewAnalytics();
    }

    setDashboardPanelState(false);
    return true;
}

function isValidStaffLogin(role, email, password) {
    const normalizedRole = (role || '').trim();
    const normalizedEmail = (email || '').trim().toLowerCase();
    const staffAccounts = getCurrentStaffAccounts();

    return staffAccounts.some((account) => {
        return account.email.toLowerCase() === normalizedEmail
            && account.password === password
            && account.role === normalizedRole;
    });
}

function updateDashboardProfile() {
    const role = (selectedRoleInput && selectedRoleInput.value) ? selectedRoleInput.value : 'Account';
    const email = (emailInput && emailInput.value) ? emailInput.value.trim() : 'No account selected';

    if (dashboardUserName) {
        dashboardUserName.textContent = role;
    }

    if (dashboardUserEmail) {
        dashboardUserEmail.textContent = email;
    }
}

function resetDashboardProfile() {
    if (dashboardUserName) {
        dashboardUserName.textContent = 'Account';
    }

    if (dashboardUserEmail) {
        dashboardUserEmail.textContent = 'No account selected';
    }
}

function setDashboardPanelState(isOpen) {
    if (dashboardPanel) {
        dashboardPanel.classList.toggle('open', isOpen);
    }
    if (menuBtn) {
        menuBtn.classList.toggle('active', isOpen);
    }
    document.body.classList.toggle('dashboard-panel-open', isOpen);
}

function setAuthButtonsVisible(visible) {
    if (logoutBtn) {
        logoutBtn.hidden = !visible;
    }
    if (menuBtn) {
        menuBtn.hidden = !visible;
        if (!visible) {
            menuBtn.style.display = 'none';
        } else if (!menuBtn.hidden) {
            menuBtn.style.display = 'flex';
        }
    }
}

setAuthButtonsVisible(false);

function openModal() {
    if (!modal) return;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
}

function closeModal() {
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    if (loginFields) {
        loginFields.hidden = true;
    }
    if (selectedRoleInput) {
        selectedRoleInput.value = '';
    }
    roleButtons.forEach((button) => button.classList.remove('active'));
    if (modalTitle) {
        modalTitle.textContent = 'Choose Your Role';
    }
}

if (openModalBtn) {
    openModalBtn.addEventListener('click', openModal);
}
if (closeModalBtn) {
    closeModalBtn.addEventListener('click', closeModal);
}
if (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeModal();
        closeLightbox();
        closeCartModal();
    }
});

function selectRole(role) {
    if (!role) return;

    if (selectedRoleInput) {
        selectedRoleInput.value = role;
    }
    roleButtons.forEach((btn) => btn.classList.toggle('active', btn.dataset.role === role));
    if (loginFields) {
        loginFields.hidden = false;
    }
    loadSavedCredentialsForRole(role);
    if (modalTitle) {
        modalTitle.textContent = `Login as ${role}`;
    }
}

window.selectRole = selectRole;

roleButtons.forEach((button) => {
    button.addEventListener('click', () => selectRole(button.dataset.role));
});

function attachStaffLoginHandler() {
    if (!staffForm) return;

    staffForm.addEventListener('submit', function (event) {
        if (!staffForm.checkValidity()) {
            staffForm.reportValidity();
            return;
        }

        event.preventDefault();

        const role = selectedRoleInput && selectedRoleInput.value
            ? selectedRoleInput.value
            : '';
        const email = emailInput ? emailInput.value.trim() : '';
        const password = passwordInput ? passwordInput.value : '';
        const remember = rememberCheckbox ? rememberCheckbox.checked : false;

        if (!allowedRoles.includes(role) || !isValidStaffLogin(role, email, password)) {
            setAuthButtonsVisible(false);
            if (modalTitle) {
                modalTitle.textContent = 'Invalid credentials';
            }
            return;
        }

        if (remember) {
            saveCredentialsForRole(role, email, password);
        } else {
            clearSavedCredentialsForRole(role);
        }

        saveStaffSession(role, email, password, remember);

        if (modalTitle) {
            modalTitle.textContent = `Logged in as ${role}`;
        }

        updateDashboardProfile();

        if (loginFields) {
            loginFields.hidden = true;
        }

        const staffBox = document.querySelector('.staff-box');
        if (staffBox) {
            staffBox.style.display = 'none';
        }
        if (staffLoginPage) {
            staffLoginPage.hidden = true;
        }

        // mark page authenticated so CSS can reveal auth-only controls
        document.body.classList.add('auth');
        updateAccountManagementAccess();
        renderInventoryManagement();
        setAuthButtonsVisible(true);
        // After login, show the Overview dashboard as the main page
        if (overviewSection) {
            showDashboardSection(overviewSection);
            renderOverviewAnalytics();
        }
        // Ensure dashboard panel is closed (main content visible)
        setDashboardPanelState(false);
    });
}

document.addEventListener('DOMContentLoaded', attachStaffLoginHandler);

if (logoutBtn) {
    logoutBtn.addEventListener('click', () => {
        if (selectedRoleInput) {
            selectedRoleInput.value = '';
        }
        roleButtons.forEach((btn) => btn.classList.remove('active'));
        resetDashboardProfile();
        if (loginFields) {
            loginFields.hidden = true;
        }
        if (modalTitle) {
            modalTitle.textContent = 'Choose Your Role';
        }

        const staffBox = document.querySelector('.staff-box');
        if (staffBox) {
            staffBox.style.display = '';
            staffBox.hidden = false;
        }
        if (staffLoginPage) {
            staffLoginPage.hidden = false;
        }

        if (overviewSection) {
            overviewSection.hidden = true;
        }
        if (salesSection) {
            salesSection.hidden = true;
        }
        if (inventorySection) {
            inventorySection.hidden = true;
        }
        if (pendingOrdersSection) {
            pendingOrdersSection.hidden = true;
        }
        if (accountManagementSection) {
            accountManagementSection.hidden = true;
        }

        updateAccountManagementAccess();
        setAuthButtonsVisible(false);
        renderInventoryManagement();
        document.body.classList.remove('auth');
        clearStaffSession();
        setDashboardPanelState(false);
        document.body.classList.remove('dashboard-panel-open');
    });
}

if (menuBtn) {
    menuBtn.addEventListener('click', () => {
        const isOpen = dashboardPanel && dashboardPanel.classList.contains('open');
        setDashboardPanelState(!isOpen);
    });
}

// closePanelBtn removed from markup — dashboard is toggled via the menu button now
if (closePanelBtn) {
    closePanelBtn.addEventListener('click', () => {
        setDashboardPanelState(false);
    });
}

const accountManagementLink = document.getElementById('accountManagementLink');
const accountManagementSection = document.getElementById('account-management');
const accountForm = document.getElementById('accountForm');
const accountList = document.getElementById('accountList');
const accountNameInput = document.getElementById('accountName');
const accountRoleInput = document.getElementById('accountRole');
const accountEmailInput = document.getElementById('accountEmail');
const accountPasswordInput = document.getElementById('accountPassword');
let accountEditIndex = null;

function renderAccounts() {
    if (!accountList) return;

    accountList.innerHTML = '';

    accounts.forEach((account, index) => {
        const item = document.createElement('li');
        item.innerHTML = `
            <span>${account.name} — ${account.role} — ${account.email}</span>
            <div>
                <button type="button" class="edit-btn" data-index="${index}">Edit</button>
                <button type="button" class="delete-btn" data-index="${index}">Delete</button>
            </div>
        `;
        accountList.appendChild(item);
    });
}

function resetAccountForm() {
    if (accountForm) {
        accountForm.reset();
    }
    accountEditIndex = null;
}

if (accountManagementLink && accountManagementSection) {
    accountManagementLink.addEventListener('click', (event) => {
        event.preventDefault();
        const isAdmin = document.body.classList.contains('auth') && (selectedRoleInput && selectedRoleInput.value === 'Admin');
        if (!isAdmin) {
            return;
        }
        const showAccountManagement = accountManagementSection.hidden;
        accountManagementSection.hidden = !accountManagementSection.hidden;

        if (showAccountManagement && salesSection) {
            salesSection.hidden = true;
        }
    });
}

function updateAccountManagementAccess() {
    const isAdmin = selectedRoleInput && selectedRoleInput.value === 'Admin';
    if (accountManagementLink) {
        if (isAdmin) {
            accountManagementLink.classList.remove('disabled');
        } else {
            accountManagementLink.classList.add('disabled');
        }
    }
}

const salesLink = document.getElementById('salesLink');
const salesSection = document.getElementById('sales');
const salesTabBtns = document.querySelectorAll('.sales-tab-btn');
const salesTabContents = document.querySelectorAll('.sales-tab-content');
const analyticsSelect = document.getElementById('analyticsSelect');
const analyticsChart = document.getElementById('salesAnalyticsChart');
const analyticsMonthWrapper = document.getElementById('analyticsMonthWrapper');
const analyticsMonthSelect = document.getElementById('analyticsMonthSelect');
const dailySalesList = document.getElementById('daily-sales-list');
const weeklySalesList = document.getElementById('weekly-sales-list');

const viewToTabId = {
    daily: 'daily-sales',
    weekly: 'weekly-sales',
    monthly: 'monthly-sales'
};

const tabIdToView = {
    'daily-sales': 'daily',
    'weekly-sales': 'weekly',
    'monthly-sales': 'monthly'
};

function setActiveSalesTab(tabName) {
    salesTabBtns.forEach((btn) => btn.classList.toggle('active', btn.dataset.tab === tabName));
    salesTabContents.forEach((content) => {
        content.hidden = content.id !== tabName;
    });
}

const analyticsData = {
    daily: {
        title: 'Daily Sales',
        items: []
    },
    weekly: {
        title: 'Weekly Sales',
        items: []
    },
    monthly: {
        title: 'Monthly Sales',
        items: []
    }
};

function createMonthlyDailyData(base, drift) {
    return Array.from({ length: 30 }, (_, index) => {
        const value = Math.round(base + Math.sin(index / 3) * drift + index * 12);
        return { label: `${index + 1}`, value };
    });
}

const monthKeys = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const monthlySalesByMonth = {
    jan: [],
    feb: [],
    mar: [],
    apr: [],
    may: [],
    jun: [],
    jul: [],
    aug: [],
    sep: [],
    oct: [],
    nov: [],
    dec: []
};

const weeklySalesByMonth = {
    jan: [],
    feb: [],
    mar: [],
    apr: [],
    may: [],
    jun: [],
    jul: [],
    aug: [],
    sep: [],
    oct: [],
    nov: [],
    dec: []
};

function initializeAnalyticsBuckets() {
    monthKeys.forEach((monthKey) => {
        monthlySalesByMonth[monthKey] = Array.from({ length: 30 }, (_, index) => ({
            label: `${index + 1}`,
            value: 0
        }));
        weeklySalesByMonth[monthKey] = Array.from({ length: 5 }, (_, index) => ({
            label: `W${index + 1}`,
            value: 0
        }));
    });
}

function recalculateSalesAnalytics() {
    initializeAnalyticsBuckets();

    analyticsData.monthly.items = monthKeys.map((monthKey, index) => ({
        label: monthLabels[index],
        value: 0,
        display: `₱0`
    }));

    completedOrders.forEach((order) => {
        const orderDate = new Date(order.timestamp);
        const monthIndex = orderDate.getMonth();
        const monthKey = monthKeys[monthIndex];
        const dayIndex = Math.min(29, Math.max(0, orderDate.getDate() - 1));
        const weekIndex = Math.min(4, Math.floor(dayIndex / 7));
        const orderTotal = Number(order.total || 0);

        if (monthKey && monthlySalesByMonth[monthKey]) {
            monthlySalesByMonth[monthKey][dayIndex].value += orderTotal;
            weeklySalesByMonth[monthKey][weekIndex].value += orderTotal;
            analyticsData.monthly.items[monthIndex].value += orderTotal;
        }
    });

    analyticsData.monthly.items = analyticsData.monthly.items.map((item) => ({
        ...item,
        display: `₱${item.value.toLocaleString()}`
    }));
}

function renderDetailChart(container, chartData, title) {
    if (!container) return;
    if (!chartData || !chartData.length) {
        container.innerHTML = '<p class="menu-cart-empty">Waiting for live data...</p>';
        return;
    }

    const maxValue = Math.max(...chartData.map((item) => item.value));
    const paddedMax = Math.ceil(maxValue / 1000) * 1000;
    const ticks = 5;
    const pointCount = chartData.length;
    const svgWidth = Math.max(720, pointCount * 40 + 140);
    const svgHeight = 300;
    const margin = { top: 32, right: 24, bottom: 56, left: 60 };
    const chartWidth = svgWidth - margin.left - margin.right;
    const chartHeight = svgHeight - margin.top - margin.bottom;
    const xStep = pointCount > 1 ? chartWidth / (pointCount - 1) : chartWidth;

    function formatChartValue(value) {
        if (value >= 1000) {
            const shortVal = value / 1000;
            return `₱${shortVal.toFixed(shortVal % 1 === 0 ? 0 : 1)}k`;
        }
        return `₱${value.toLocaleString()}`;
    }

    const points = chartData.map((item, index) => {
        const x = margin.left + index * xStep;
        const y = margin.top + chartHeight - (item.value / paddedMax) * chartHeight;
        return { x, y, label: item.label, value: item.value, display: item.display || formatChartValue(item.value) };
    });

    const yTicks = Array.from({ length: ticks + 1 }, (_, i) => {
        const value = Math.round((paddedMax / ticks) * i);
        const y = margin.top + chartHeight - (value / paddedMax) * chartHeight;
        return { value, y };
    });

    const pathD = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ');

    const svg = `
        <svg width="${svgWidth}" viewBox="0 0 ${svgWidth} ${svgHeight}" role="img" aria-label="${title} line chart">
            <rect x="0" y="0" width="${svgWidth}" height="${svgHeight}" fill="#f9f9f9" rx="16" />
            <g>
                <line x1="${margin.left}" y1="${margin.top}" x2="${margin.left}" y2="${margin.top + chartHeight}" stroke="#ccc" />
                <line x1="${margin.left}" y1="${margin.top + chartHeight}" x2="${margin.left + chartWidth}" y2="${margin.top + chartHeight}" stroke="#ccc" />
            </g>
            <g>
                ${yTicks.map((tick) => `
                    <line x1="${margin.left}" y1="${tick.y}" x2="${margin.left + chartWidth}" y2="${tick.y}" stroke="rgba(204,204,204,0.45)" />
                    <text x="${margin.left - 12}" y="${tick.y + 4}" text-anchor="end" fill="#333" font-size="12">${tick.value}</text>
                `).join('')}
            </g>
            <path d="${pathD}" fill="none" stroke="#ff9800" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
            <g>
                ${points.map((point) => `
                    <circle cx="${point.x}" cy="${point.y}" r="5" fill="#ff9800" />
                    <text x="${point.x}" y="${point.y - 12}" text-anchor="middle" fill="#111" font-size="12" font-weight="700">${point.display}</text>
                `).join('')}
            </g>
            <g>
                ${points.map((point) => `
                    <text x="${point.x}" y="${margin.top + chartHeight + 22}" text-anchor="middle" fill="#333" font-size="12">${point.label}</text>
                `).join('')}
            </g>
            <text x="${margin.left}" y="20" fill="#333" font-size="14" font-weight="700">${title}</text>
        </svg>
    `;

    container.innerHTML = svg;
}

function renderSalesList(container, items, firstCol = 'Period', secondCol = 'Sales') {
    if (!container) return;
    if (!items || !items.length) {
        container.innerHTML = '<p>No sales data available.</p>';
        return;
    }

    container.innerHTML = `
        <table class="sales-example-table">
            <thead>
                <tr>
                    <th>${firstCol}</th>
                    <th>${secondCol}</th>
                </tr>
            </thead>
            <tbody>
                ${items.map((item) => `
                    <tr>
                        <td>${item.label}</td>
                        <td>${formatCurrency(item.value || 0)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
}

function updateDailySalesList() {
    if (!dailySalesList || !analyticsMonthSelect) return;
    const month = analyticsMonthSelect.value;
    const monthData = monthlySalesByMonth[month] || monthlySalesByMonth.jan;
    renderSalesList(dailySalesList, monthData, 'Day', 'Revenue');
}

function updateWeeklySalesList() {
    if (!weeklySalesList || !analyticsMonthSelect) return;
    const month = analyticsMonthSelect.value;
    const monthData = weeklySalesByMonth[month] || weeklySalesByMonth.jan;
    renderSalesList(weeklySalesList, monthData, 'Week', 'Revenue');
}

function updateMonthlySalesList() {
    const monthlySalesList = document.getElementById('monthly-sales-list');
    if (!monthlySalesList) return;

    const data = analyticsData.monthly.items;
    renderSalesList(monthlySalesList, data, 'Month', 'Revenue');
}

function updateAnalyticsView() {
    if (!analyticsSelect || !analyticsChart || !analyticsMonthWrapper || !analyticsMonthSelect) return;

    const view = analyticsSelect.value;
    const activeTab = viewToTabId[view] || 'daily-sales';
    analyticsMonthWrapper.style.display = view === 'monthly' ? 'none' : 'inline-flex';

    setActiveSalesTab(activeTab);

    if (view === 'daily') {
        const month = analyticsMonthSelect.value;
        const monthData = monthlySalesByMonth[month] || monthlySalesByMonth.jan;
        renderDetailChart(analyticsChart, monthData, `Daily Sales — ${analyticsMonthSelect.options[analyticsMonthSelect.selectedIndex].text}`);
        updateDailySalesList();
    } else if (view === 'weekly') {
        const month = analyticsMonthSelect.value;
        const monthData = weeklySalesByMonth[month] || weeklySalesByMonth.jan;
        renderDetailChart(analyticsChart, monthData, `Weekly Sales — ${analyticsMonthSelect.options[analyticsMonthSelect.selectedIndex].text}`);
        updateWeeklySalesList();
    } else {
        renderAnalytics('monthly');
        updateMonthlySalesList();
    }
}

if (analyticsSelect) {
    analyticsSelect.addEventListener('change', updateAnalyticsView);
}
if (analyticsMonthSelect) {
    analyticsMonthSelect.addEventListener('change', updateAnalyticsView);
}

const overviewLink = document.getElementById('overviewLink');
const inventoryLink = document.getElementById('inventoryLink');
const overviewSection = document.getElementById('overview');
const inventorySection = document.getElementById('inventory');
const overviewAnalyticsSelect = document.getElementById('overviewAnalyticsSelect');
const overviewAnalyticsChart = document.getElementById('overviewAnalyticsChart');
const overviewMonthWrapper = document.getElementById('overviewMonthWrapper');
const overviewMonthSelect = document.getElementById('overviewMonthSelect');
const overviewOrderNotificationList = document.getElementById('overviewOrderNotificationList');
const overviewOrderRevenue = document.getElementById('overviewOrderRevenue');
const overviewInventoryList = document.getElementById('overviewInventoryList');
const inventoryAdminPanel = document.getElementById('inventoryAdminPanel');
const inventoryForm = document.getElementById('inventoryForm');
const inventoryNameInput = document.getElementById('inventoryNameInput');
const inventoryCategoryInput = document.getElementById('inventoryCategoryInput');
const inventoryPriceInput = document.getElementById('inventoryPriceInput');
const inventoryStockInput = document.getElementById('inventoryStockInput');
const inventoryStatusInput = document.getElementById('inventoryStatusInput');
const inventorySaveBtn = document.getElementById('inventorySaveBtn');
const inventoryItemsWrapper = document.getElementById('inventoryItemsWrapper');
const inventorySearchInput = document.getElementById('inventorySearchInput');
const inventoryCategoryTabs = document.querySelectorAll('.inventory-category-tab');
const inventoryAddFab = document.getElementById('inventoryAddFab');
const inventoryModal = document.getElementById('inventoryModal');

function setInventoryCategory(category) {
    if (!category) return;
    inventorySelectedCategory = category;
    inventoryCategoryTabs.forEach((tab) => {
        tab.classList.toggle('active', tab.dataset.category === category);
    });
    renderInventoryManagement();
}

if (inventoryCategoryTabs && inventoryCategoryTabs.length) {
    inventoryCategoryTabs.forEach((button) => {
        button.addEventListener('click', () => {
            setInventoryCategory(button.dataset.category || 'all');
        });
    });
}

if (inventorySearchInput) {
    inventorySearchInput.addEventListener('input', (event) => {
        inventorySearchTerm = (event.target.value || '').trim();
        renderInventoryManagement();
    });
}
const inventoryModalCloseBtn = document.getElementById('inventoryModalCloseBtn');
const inventoryModalTitle = document.getElementById('inventoryModalTitle');
const inventoryAccessNote = document.getElementById('inventoryAccessNote');
const ordersLink = document.getElementById('ordersLink');
const pendingOrdersList = document.getElementById('pendingOrdersList');
const pendingOrdersSection = document.getElementById('pending-orders');

function setInventoryModalVisible(isVisible) {
    if (!inventoryModal) return;
    inventoryModal.hidden = !isVisible;
    inventoryModal.style.display = isVisible ? 'grid' : 'none';
    inventoryModal.setAttribute('aria-hidden', String(!isVisible));
    document.body.style.overflow = isVisible ? 'hidden' : '';

    if (!isVisible && inventoryForm) {
        inventoryForm.reset();
        if (inventoryCategoryInput) {
            inventoryCategoryInput.value = 'batchoy';
        }
    }
}

setInventoryModalVisible(false);

function openInventoryModal() {
    if (!inventoryModal) return;
    setInventoryModalVisible(true);
    if (inventoryModalTitle) {
        inventoryModalTitle.textContent = 'Add New Product';
    }
    if (inventoryForm) {
        inventoryForm.reset();
        if (inventoryCategoryInput) {
            inventoryCategoryInput.value = 'batchoy';
        }
    }
}

function closeInventoryModal(event) {
    if (event && event.preventDefault) {
        event.preventDefault();
    }
    setInventoryModalVisible(false);
}

window.openInventoryModal = openInventoryModal;
window.closeInventoryModal = closeInventoryModal;

if (overviewAnalyticsSelect) {
    overviewAnalyticsSelect.addEventListener('change', renderOverviewAnalytics);
}
if (overviewMonthSelect) {
    overviewMonthSelect.addEventListener('change', renderOverviewAnalytics);
}

updateAnalyticsView();

function renderAnalytics(type) {
    if (!analyticsChart || !analyticsData[type]) return;
    if (!analyticsData[type].items || !analyticsData[type].items.length) {
        analyticsChart.innerHTML = '<p class="menu-cart-empty">Waiting for live data...</p>';
        return;
    }

    const data = analyticsData[type];
    const values = data.items.map((item) => item.value);
    const maxValue = Math.max(...values);
    const paddedMax = Math.ceil(maxValue / 5000) * 5000;
    const ticks = 5;
    const svgWidth = 720;
    const svgHeight = 320;
    const margin = { top: 24, right: 24, bottom: 56, left: 56 };
    const chartWidth = svgWidth - margin.left - margin.right;
    const chartHeight = svgHeight - margin.top - margin.bottom;
    const pointCount = data.items.length;
    const xStep = pointCount > 1 ? chartWidth / (pointCount - 1) : chartWidth;

    const points = data.items.map((item, index) => {
        const x = margin.left + index * xStep;
        const y = margin.top + chartHeight - (item.value / paddedMax) * chartHeight;
        return { x, y, label: item.label, display: item.display, value: item.value };
    });

    const yTicks = Array.from({ length: ticks + 1 }, (_, i) => {
        const value = Math.round((paddedMax / ticks) * i);
        const y = margin.top + chartHeight - (value / paddedMax) * chartHeight;
        return { value, y };
    });

    const pathD = points.map((point, index) => {
        return `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`;
    }).join(' ');

    const svg = `
        <svg width="100%" viewBox="0 0 ${svgWidth} ${svgHeight}" role="img" aria-label="${data.title} line chart">
            <rect x="0" y="0" width="${svgWidth}" height="${svgHeight}" fill="#f9f9f9" rx="16" />
            <g>
                <line x1="${margin.left}" y1="${margin.top}" x2="${margin.left}" y2="${margin.top + chartHeight}" stroke="#ccc" />
                <line x1="${margin.left}" y1="${margin.top + chartHeight}" x2="${margin.left + chartWidth}" y2="${margin.top + chartHeight}" stroke="#ccc" />
            </g>
            <g>
                ${yTicks.map((tick) => `
                    <line x1="${margin.left}" y1="${tick.y}" x2="${margin.left + chartWidth}" y2="${tick.y}" stroke="rgba(204,204,204,0.45)" />
                    <text x="${margin.left - 12}" y="${tick.y + 4}" text-anchor="end" fill="#333" font-size="12">${tick.value}</text>
                `).join('')}
            </g>
            <g>
                ${points.map((point) => `
                    <circle cx="${point.x}" cy="${point.y}" r="5" fill="#ff9800" />
                    <text x="${point.x}" y="${point.y - 12}" text-anchor="middle" fill="#111" font-size="12" font-weight="700">${point.display}</text>
                `).join('')}
            </g>
            <path d="${pathD}" fill="none" stroke="#ff9800" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
            <g>
                ${points.map((point) => `
                    <text x="${point.x}" y="${margin.top + chartHeight + 22}" text-anchor="middle" fill="#333" font-size="12">${point.label}</text>
                `).join('')}
            </g>
            <text x="${margin.left}" y="16" fill="#333" font-size="14" font-weight="700">${data.title}</text>
        </svg>
    `;

    analyticsChart.innerHTML = svg;
}

if (salesLink && salesSection) {
    salesLink.addEventListener('click', (event) => {
        event.preventDefault();
        showDashboardSection(salesSection);
        updateAnalyticsView();
        if (dashboardPanel) {
            setDashboardPanelState(false);
        }
    });
}

salesTabBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
        const tabName = btn.dataset.tab;
        const view = tabIdToView[tabName] || 'daily';

        if (analyticsSelect) {
            analyticsSelect.value = view;
        }

        setActiveSalesTab(tabName);
        updateAnalyticsView();
    });
});

if (accountForm) {
    accountForm.addEventListener('submit', (event) => {
        event.preventDefault();

        if (!document.body.classList.contains('auth') || !(selectedRoleInput && selectedRoleInput.value === 'Admin')) {
            alert('Only the admin can manage accounts.');
            return;
        }

        const account = {
            name: accountNameInput ? accountNameInput.value.trim() : '',
            role: accountRoleInput ? accountRoleInput.value : '',
            email: accountEmailInput ? accountEmailInput.value.trim() : '',
            password: accountPasswordInput ? accountPasswordInput.value : ''
        };

        if (!account.name || !account.role || !account.email || !account.password) {
            return;
        }

        const previousAccount = accountEditIndex !== null ? accounts[accountEditIndex] : null;

        if (accountEditIndex !== null) {
            accounts[accountEditIndex] = account;
        } else {
            accounts.push(account);
        }

        window.motasteStaffAccounts = accounts;
        saveStaffAccountsToServer();

        const currentRole = selectedRoleInput ? selectedRoleInput.value : '';
        const currentEmail = emailInput ? emailInput.value.trim().toLowerCase() : '';
        if (previousAccount && currentRole === previousAccount.role && currentEmail === (previousAccount.email || '').trim().toLowerCase()) {
            clearSavedCredentialsForRole(previousAccount.role);
            if (selectedRoleInput) selectedRoleInput.value = account.role;
            if (emailInput) emailInput.value = account.email;
            if (passwordInput) passwordInput.value = account.password;
            saveStaffSession(account.role, account.email, account.password, rememberCheckbox ? rememberCheckbox.checked : false);
            if (rememberCheckbox && rememberCheckbox.checked) {
                saveCredentialsForRole(account.role, account.email, account.password);
            }
            updateDashboardProfile();
        }

        renderAccounts();
        resetAccountForm();
    });
}

if (accountList) {
    accountList.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;

        const index = Number(button.dataset.index);
        if (button.classList.contains('delete-btn')) {
            const removedAccount = accounts[index];
            accounts.splice(index, 1);
            window.motasteStaffAccounts = accounts;
            saveStaffAccountsToServer();
            if (removedAccount) {
                clearSavedCredentialsForRole(removedAccount.role);
                const currentRole = selectedRoleInput ? selectedRoleInput.value : '';
                const currentEmail = emailInput ? emailInput.value.trim().toLowerCase() : '';
                const removedEmail = (removedAccount.email || '').trim().toLowerCase();
                if (currentRole === removedAccount.role && currentEmail === removedEmail) {
                    clearStaffSession();
                    if (selectedRoleInput) selectedRoleInput.value = '';
                    if (emailInput) emailInput.value = '';
                    if (passwordInput) passwordInput.value = '';
                    if (loginFields) loginFields.hidden = true;
                    if (modalTitle) modalTitle.textContent = 'Choose Your Role';
                    document.body.classList.remove('auth');
                    updateDashboardProfile();
                    setAuthButtonsVisible(false);
                    updateAccountManagementAccess();
                    setDashboardPanelState(false);
                }
            }
            renderAccounts();
            return;
        }

        if (button.classList.contains('edit-btn')) {
            const selectedAccount = accounts[index];
            if (selectedAccount) {
                accountEditIndex = index;
                if (accountNameInput) accountNameInput.value = selectedAccount.name;
                if (accountRoleInput) accountRoleInput.value = selectedAccount.role;
                if (accountEmailInput) accountEmailInput.value = selectedAccount.email;
                if (accountPasswordInput) accountPasswordInput.value = selectedAccount.password;
            }
        }
    });
}

loadStaffAccountsFromStorage();
renderAccounts();
if (isStaffPage) {
    void loadStaffAccountsFromServer();
    startStaffAccountsRefresh();
}

/* Slideshow functionality */
const slideshow = document.querySelector('.slideshow');
if (slideshow) {
    const slides = Array.from(slideshow.querySelectorAll('.slides img'));
    const dotsContainer = document.getElementById('slideDots');
    let current = 0;
    let timer = null;

    const lightbox = document.createElement('div');
    lightbox.className = 'image-lightbox hidden';
    lightbox.innerHTML = '<button type="button" class="close-btn" aria-label="Close image">×</button><img alt="Expanded view">';
    document.body.appendChild(lightbox);

    const lightboxImage = lightbox.querySelector('img');
    const closeButton = lightbox.querySelector('.close-btn');

    function closeLightbox() {
        lightbox.classList.add('hidden');
    }

    function showSlide(index) {
        slides.forEach((s, i) => s.classList.toggle('active', i === index));
        const dots = Array.from(dotsContainer.children);
        dots.forEach((d, i) => d.classList.toggle('active', i === index));
        current = index;
    }

    function nextSlide() { showSlide((current + 1) % slides.length); }
    function prevSlide() { showSlide((current - 1 + slides.length) % slides.length); }

    slides.forEach((slide) => {
        slide.addEventListener('click', () => {
            if (!slide.classList.contains('active')) {
                return;
            }

            lightboxImage.src = slide.src;
            lightboxImage.alt = slide.alt || 'Expanded image';
            lightbox.classList.remove('hidden');
        });
    });

    closeButton.addEventListener('click', closeLightbox);
    lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox) {
            closeLightbox();
        }
    });

    slides.forEach((_, i) => {
        const btn = document.createElement('button');
        btn.addEventListener('click', () => { showSlide(i); resetTimer(); });
        if (i === 0) btn.classList.add('active');
        dotsContainer.appendChild(btn);
    });

    function startTimer() {
        timer = setInterval(nextSlide, 4000);
    }

    function resetTimer() {
        clearInterval(timer);
        startTimer();
    }

    showSlide(0);
    startTimer();
}

const menuData = {
    batchoy: {
        title: 'BATCHOY',
        items: [
            { name: 'Classic Batchoy', price: '₱60', description: 'Savory pork and beef broth with noodles, pork slices, liver, and chicharon.' },
            { name: 'Special Batchoy', price: '₱80', description: 'Classic batchoy with egg and extra meat.' },
            { name: 'Big Bowl Batchoy', price: '₱150', description: 'Larger serving with more noodles, meat, and satisfaction.' }
        ]
    },
    silog: {
        title: 'SILOG',
        items: [
            { name: 'Tapsilog', price: '₱90', description: 'Cured beef tapa served with garlic rice and egg.' },
            { name: 'Tocilog', price: '₱80', description: 'Sweet pork tocino served with garlic rice and egg.' },
            { name: 'Longsilog', price: '₱80', description: 'Homemade pork longganisa with garlic rice and egg.' },
            { name: 'Hotsilog', price: '₱80', description: 'Juicy hotdog served with garlic rice and egg.' },
            { name: 'Bangsilog', price: '₱80', description: 'Marinated bangus belly with garlic rice and egg.' },
            { name: 'Chicksilog', price: '₱80', description: 'Fried chicken fillet served with garlic rice and egg.' }
        ]
    },
    friedChicken: {
        title: 'FRIED CHICKEN',
        items: [
            { name: '1 pc. Fried Chicken', price: '₱30', description: 'Crispy on the outside, juicy on the inside.' },
            { name: 'Combo Meal 1', price: '₱80', description: '1 pc. chicken, 1 rice, and juice drink.' },
            { name: 'Combo Meal 2', price: '₱110', description: '2 pc. chicken, 1 rice, and juice drink.' },
            { name: 'Combo Meal 3', price: '₱130', description: '2 pc. chicken, 2 rice, and juice drink.' }
        ]
    },
    breakfast: {
        title: 'BREAKFAST',
        items: [
            { name: 'Breakfast Plate', price: '₱80', description: 'Egg, toasted bread, choice of meat, garlic rice, and egg.' },
            { name: 'American Breakfast', price: '₱120', description: 'Egg, toasted bread, bacon, ham, and no hashbrown.' },
            { name: 'Overload Breakfast', price: '₱180', description: 'Egg, toasted bread, bacon, ham, and no hashbrown.' }
        ]
    },
    drinks: {
        title: 'DRINKS',
        items: [
            { name: 'Iced Tea', price: '₱55', description: 'Chilled iced tea with refreshing flavor.' },
            { name: 'Lemonade', price: '₱55', description: 'Freshly squeezed lemonade.' },
            { name: 'Bottled Water', price: '₱30', description: 'Pure bottled water.' },
            { name: 'Softdrinks', price: '₱20', description: 'Cold soda to pair with your meal.' }
        ]
    }
};

const specialFoods = [
    { name: 'Special Batchoy', price: 80, image: 'img1.jpg' },
    { name: 'Tapsilog', price: 90, image: 'img2.jpg' },
    { name: 'Combo Meal 2', price: 110, image: 'img3.jpg' },
    { name: 'Overload Breakfast', price: 180, image: 'img4.jpg' },
    { name: 'Ramen batchoy overload', price: 80, image: 'img1.jpg' },
    { name: 'sizzling pork chop', price: 90, image: 'img2.jpg' },
    { name: 'sizzling hungarian', price: 110, image: 'img3.jpg' },
    { name: 'fried siomai', price: 180, image: 'img4.jpg' },
    { name: 'pork chops, egg and rice', price: 80, image: 'img1.jpg' },
    { name: 'tapa, egg and rice', price: 90, image: 'img2.jpg' },
    { name: 'tocino egg and rice', price: 110, image: 'img3.jpg' },
    { name: 'pork fried egg and rice', price: 180, image: 'img4.jpg' }
];

const mobileMenuToggle = document.getElementById('mobileMenuToggle');
const topNav = document.getElementById('topNav');
const menuCategories = document.getElementById('menuCategories');
const menuCategoryScreen = document.getElementById('menuCategoryScreen');
const menuCategoryTitle = document.getElementById('menuCategoryTitle');
const menuItemsList = document.getElementById('menuItemsList');
const menuBackBtn = document.getElementById('menuBackBtn');
const menuCartList = document.getElementById('menuCartList');
const menuCartCount = document.getElementById('menuCartCount');
const menuCartTotal = document.getElementById('menuCartTotal');
const menuPlaceOrderBtn = document.getElementById('menuPlaceOrderBtn');
const menuOrderMessage = document.getElementById('menuOrderMessage');
const menuOverlay = document.getElementById('menuOverlay');
const openMenuBtn = document.getElementById('openMenuBtn');
const closeMenuOverlayBtn = document.getElementById('closeMenuOverlayBtn');
const menuCartButton = document.getElementById('menuCartButton');
const menuTopCartCount = document.getElementById('menuTopCartCount');
const specialFoodsList = document.getElementById('specialFoodsList');
const cartModal = document.getElementById('cart');
const closeCartButton = document.getElementById('closeCartButton');
const menuNavLink = document.querySelector('a[href="#menu"]');
const orderCheckoutScreen = document.getElementById('orderCheckoutScreen');
const orderCheckoutBackBtn = document.getElementById('orderCheckoutBackBtn');
const confirmOrderBtn = document.getElementById('confirmOrderBtn');
const cancelOrderBtn = document.getElementById('cancelOrderBtn');
const paymentMethodOptions = document.getElementById('paymentMethodOptions');
const orderTypeOptions = document.getElementById('orderTypeOptions');
const orderCheckoutItems = document.getElementById('orderCheckoutItems');
const orderCheckoutTotal = document.getElementById('orderCheckoutTotal');
const orderPaymentScreen = document.getElementById('orderPaymentScreen');
const paymentConfirmationBackBtn = document.getElementById('paymentConfirmationBackBtn');
const orderPaymentNumber = document.getElementById('orderPaymentNumber');
const orderPaymentDatetime = document.getElementById('orderPaymentDatetime');
const orderPaymentMethod = document.getElementById('orderPaymentMethod');
const orderPaymentMessage = document.getElementById('orderPaymentMessage');
const paymentQrPlaceholder = document.getElementById('paymentQrPlaceholder');
const orderPaymentCloseBtn = document.getElementById('orderPaymentCloseBtn');
const paymentSuccessModal = document.getElementById('paymentSuccessModal');
const paymentSuccessCloseBtn = document.getElementById('paymentSuccessCloseBtn');
const liveClock = document.getElementById('liveClock');

let cartItems = [];
let pendingOrders = [];
let completedOrders = [];
let inventoryData = [];
let currentMenuCategoryId = null;
let inventoryEditItemName = null;
let inventoryEditLock = false;
let ignoredPendingOrderNumbers = new Set();
const syncInventoryAcrossTabs = false;
const enableInventoryAutoRefresh = false;
const customerOrderNumbersStorageKey = 'motasteCustomerOrderNumbers';
const seenCompletedOrdersStorageKey = 'motasteSeenCompletedOrders';
let customerOrderNumbers = new Set();
let seenCompletedOrders = new Set();
let customerOrderStatusPoller = null;
let orderCompleteScrollLockState = null;
let orderNotificationAudioElement = null;
let orderNotificationAudioListenersBound = false;
let customerInventoryRefreshTimer = null;
const isCustomerPage = (() => {
    const pathname = window.location.pathname.toLowerCase();
    return pathname.endsWith('/index.html') || pathname === '/' || (!pathname.includes('staff'));
})();

function loadIgnoredPendingOrders() {
    try {
        const raw = localStorage.getItem('motasteIgnoredPendingOrders');
        const parsed = raw ? JSON.parse(raw) : [];
        ignoredPendingOrderNumbers = new Set(Array.isArray(parsed) ? parsed.map((value) => String(value)) : []);
    } catch (error) {
        ignoredPendingOrderNumbers = new Set();
    }
}

function saveIgnoredPendingOrders() {
    localStorage.setItem('motasteIgnoredPendingOrders', JSON.stringify([...ignoredPendingOrderNumbers]));
}

function ignorePendingOrder(orderNumber) {
    if (!orderNumber && orderNumber !== 0) return;
    ignoredPendingOrderNumbers.add(String(orderNumber));
    saveIgnoredPendingOrders();
}

function formatCurrency(value) {
    return `₱${value.toLocaleString()}`;
}

function parsePrice(priceText) {
    return Number(priceText.replace(/[₱,\s]/g, '')) || 0;
}

function loadCart() {
    try {
        const raw = localStorage.getItem('motasteCart');
        cartItems = raw ? JSON.parse(raw) : [];
    } catch (error) {
        cartItems = [];
    }
}

function saveCart() {
    localStorage.setItem('motasteCart', JSON.stringify(cartItems));
}

function loadPendingOrders() {
    try {
        const raw = localStorage.getItem('motastePendingOrders');
        pendingOrders = raw ? JSON.parse(raw) : [];
    } catch (error) {
        pendingOrders = [];
    }
    pendingOrders.sort((a, b) => b.timestamp - a.timestamp);
}

function savePendingOrders() {
    localStorage.setItem('motastePendingOrders', JSON.stringify(pendingOrders));
}

function loadCustomerOrderTracking() {
    try {
        const rawOrderNumbers = localStorage.getItem(customerOrderNumbersStorageKey);
        const parsedOrderNumbers = rawOrderNumbers ? JSON.parse(rawOrderNumbers) : [];
        customerOrderNumbers = new Set(Array.isArray(parsedOrderNumbers) ? parsedOrderNumbers.map((value) => String(value)) : []);
    } catch (error) {
        customerOrderNumbers = new Set();
    }

    try {
        const rawSeen = localStorage.getItem(seenCompletedOrdersStorageKey);
        const parsedSeen = rawSeen ? JSON.parse(rawSeen) : [];
        seenCompletedOrders = new Set(Array.isArray(parsedSeen) ? parsedSeen.map((value) => String(value)) : []);
    } catch (error) {
        seenCompletedOrders = new Set();
    }
}

function saveCustomerOrderTracking() {
    localStorage.setItem(customerOrderNumbersStorageKey, JSON.stringify([...customerOrderNumbers]));
    localStorage.setItem(seenCompletedOrdersStorageKey, JSON.stringify([...seenCompletedOrders]));
}

function registerCustomerOrder(orderNumber) {
    if (!orderNumber && orderNumber !== 0) return;
    customerOrderNumbers.add(String(orderNumber));
    saveCustomerOrderTracking();
}

function getOrderSummaryByNumber(orderNumber) {
    const targetOrderNumber = String(orderNumber || '').trim();
    if (!targetOrderNumber) return null;

    const allOrders = [...pendingOrders, ...completedOrders];
    const matchedOrder = allOrders.find((order) => String(order.orderNumber || order.order_number || order.id || '').trim() === targetOrderNumber);
    if (!matchedOrder) return null;

    const items = Array.isArray(matchedOrder.items) ? matchedOrder.items : [];
    const itemsSummary = items.map((item) => `${item.name} x${item.quantity}`).join(', ');

    return {
        orderNumber: targetOrderNumber,
        itemsSummary: itemsSummary || 'No items found',
        total: Number(matchedOrder.total) || 0,
        paymentMethod: matchedOrder.paymentMethod || 'N/A',
        orderType: matchedOrder.orderType || 'N/A'
    };
}

function lockPageScrollForOrderPopup() {
    if (orderCompleteScrollLockState) return;
    orderCompleteScrollLockState = {
        bodyOverflow: document.body.style.overflow,
        htmlOverflow: document.documentElement.style.overflow,
        bodyTouchAction: document.body.style.touchAction
    };

    document.body.style.overflow = 'hidden';
    document.documentElement.style.overflow = 'hidden';
    document.body.style.touchAction = 'none';
}

function unlockPageScrollForOrderPopup() {
    if (!orderCompleteScrollLockState) return;

    document.body.style.overflow = orderCompleteScrollLockState.bodyOverflow;
    document.documentElement.style.overflow = orderCompleteScrollLockState.htmlOverflow;
    document.body.style.touchAction = orderCompleteScrollLockState.bodyTouchAction;
    orderCompleteScrollLockState = null;
}

function initializeOrderNotificationAudio() {
    if (!orderNotificationAudioElement) {
        try {
            orderNotificationAudioElement = new Audio(getApiUrl('order_aud_notif.mp3'));
            orderNotificationAudioElement.preload = 'auto';
            orderNotificationAudioElement.volume = 1;
            orderNotificationAudioElement.loop = true;
        } catch (error) {
            orderNotificationAudioElement = null;
            return;
        }
    }

    if (orderNotificationAudioListenersBound) return;

    const unlockAudio = () => {
        if (!orderNotificationAudioElement) return;

        orderNotificationAudioElement.load();
        document.removeEventListener('pointerdown', unlockAudio);
        document.removeEventListener('keydown', unlockAudio);
        document.removeEventListener('touchstart', unlockAudio);
        orderNotificationAudioListenersBound = false;
    };

    document.addEventListener('pointerdown', unlockAudio, { passive: true });
    document.addEventListener('keydown', unlockAudio, { passive: true });
    document.addEventListener('touchstart', unlockAudio, { passive: true });
    orderNotificationAudioListenersBound = true;
}

function playOrderCompletedNotificationSound() {
    try {
        initializeOrderNotificationAudio();

        if (!orderNotificationAudioElement) return;

        orderNotificationAudioElement.currentTime = 0;
        const playback = orderNotificationAudioElement.play();
        if (playback && typeof playback.catch === 'function') {
            playback.catch((error) => {
                console.debug('Notification sound unavailable', error);
            });
        }
    } catch (error) {
        console.debug('Notification sound unavailable', error);
    }
}

function stopOrderCompletedNotificationSound() {
    if (!orderNotificationAudioElement) return;

    orderNotificationAudioElement.pause();
    orderNotificationAudioElement.currentTime = 0;
}

function dismissCustomerOrderCompletedPopup() {
    const overlay = document.getElementById('order-complete-overlay');
    const popup = document.getElementById('order-complete-popup');

    if (overlay) overlay.remove();
    if (popup) popup.remove();

    stopOrderCompletedNotificationSound();
    unlockPageScrollForOrderPopup();
}

function showCustomerOrderCompletedPopup(orderNumber) {
    const existingOverlay = document.getElementById('order-complete-overlay');
    const existingPopup = document.getElementById('order-complete-popup');
    dismissCustomerOrderCompletedPopup();
    if (existingOverlay) existingOverlay.remove();
    if (existingPopup) existingPopup.remove();

    const overlay = document.createElement('div');
    overlay.id = 'order-complete-overlay';
    overlay.style.position = 'fixed';
    overlay.style.inset = '0';
    overlay.style.background = 'rgba(2, 6, 23, 0.42)';
    overlay.style.backdropFilter = 'blur(2px)';
    overlay.style.zIndex = '9998';
    overlay.style.pointerEvents = 'none';

    const popup = document.createElement('div');
    popup.id = 'order-complete-popup';
    popup.setAttribute('role', 'alertdialog');
    popup.setAttribute('aria-live', 'polite');
    popup.setAttribute('aria-modal', 'true');
    popup.style.position = 'fixed';
    popup.style.left = '50%';
    popup.style.top = '50%';
    popup.style.transform = 'translate(-50%, -50%)';
    popup.style.width = 'min(92vw, 520px)';
    popup.style.maxWidth = '520px';
    popup.style.padding = 'clamp(14px, 3vw, 24px)';
    popup.style.borderRadius = '16px';
    popup.style.background = 'rgba(17, 24, 39, 0.95)';
    popup.style.color = '#ffffff';
    popup.style.boxShadow = '0 20px 40px rgba(0, 0, 0, 0.35)';
    popup.style.zIndex = '9999';
    popup.style.textAlign = 'center';
    popup.style.fontWeight = '700';
    popup.style.fontSize = 'clamp(15px, 2.8vw, 22px)';
    popup.style.lineHeight = '1.35';
    popup.style.letterSpacing = '0.2px';
    popup.style.wordBreak = 'break-word';
    popup.style.border = '1px solid rgba(255, 255, 255, 0.2)';
    popup.style.backdropFilter = 'blur(4px)';
    popup.style.display = 'flex';
    popup.style.flexDirection = 'column';
    popup.style.alignItems = 'center';
    popup.style.justifyContent = 'center';
    popup.style.gap = '12px';
    popup.style.overflow = 'hidden';
    popup.style.paddingTop = '20px';
    popup.style.paddingBottom = '20px';

    const orderSummary = getOrderSummaryByNumber(orderNumber);

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', 'Exit order notification');
    closeButton.textContent = 'X';
    closeButton.style.position = 'absolute';
    closeButton.style.top = 'clamp(8px, 2vw, 12px)';
    closeButton.style.right = 'clamp(8px, 2vw, 12px)';
    closeButton.style.width = 'clamp(34px, 7vw, 44px)';
    closeButton.style.height = 'clamp(34px, 7vw, 44px)';
    closeButton.style.border = 'none';
    closeButton.style.borderRadius = '999px';
    closeButton.style.background = 'rgba(255, 255, 255, 0.16)';
    closeButton.style.color = '#ffffff';
    closeButton.style.fontSize = 'clamp(18px, 3.5vw, 24px)';
    closeButton.style.fontWeight = '700';
    closeButton.style.letterSpacing = '0';
    closeButton.style.lineHeight = '1';
    closeButton.style.cursor = 'pointer';
    closeButton.style.display = 'flex';
    closeButton.style.alignItems = 'center';
    closeButton.style.justifyContent = 'center';
    closeButton.style.padding = '0';
    closeButton.style.zIndex = '1';

    const message = document.createElement('div');
    message.textContent = `Order #${orderNumber} is complete and ready.`;
    message.style.padding = '0 44px 0 12px';
    message.style.maxWidth = '100%';

    const summary = document.createElement('div');
    summary.style.width = '100%';
    summary.style.fontSize = 'clamp(13px, 2.4vw, 16px)';
    summary.style.fontWeight = '600';
    summary.style.lineHeight = '1.5';
    summary.style.opacity = '0.95';
    summary.style.textAlign = 'left';
    summary.style.padding = '0 12px';

    if (orderSummary) {
        summary.innerHTML = `
            <div><strong>Summary:</strong> ${orderSummary.itemsSummary}</div>
            <div><strong>Total:</strong> ${formatCurrency(orderSummary.total)}</div>
            <div><strong>Payment:</strong> ${orderSummary.paymentMethod}</div>
            <div><strong>Order Type:</strong> ${orderSummary.orderType}</div>
        `;
    } else {
        summary.textContent = 'Order summary is not available.';
    }

    popup.appendChild(closeButton);
    popup.appendChild(message);
    popup.appendChild(summary);

    closeButton.addEventListener('click', dismissCustomerOrderCompletedPopup);

    document.body.appendChild(overlay);
    document.body.appendChild(popup);
    lockPageScrollForOrderPopup();
    playOrderCompletedNotificationSound();
}

async function pollCustomerOrderStatus() {
    if (!customerOrderNumbers.size) return;

    try {
        const response = await fetch(getApiUrl(`api/get_order_status.php?_=${Date.now()}`), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ orderNumbers: [...customerOrderNumbers] }),
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        const orders = Array.isArray(payload.orders) ? payload.orders : [];

        orders.forEach((order) => {
            const orderNumber = String(order.order_number || order.orderNumber || '');
            const status = String(order.status || '').toLowerCase();
            if (!orderNumber) return;

            if (status === 'completed' && !seenCompletedOrders.has(orderNumber)) {
                seenCompletedOrders.add(orderNumber);
                showCustomerOrderCompletedPopup(orderNumber);
            }
        });

        saveCustomerOrderTracking();
    } catch (error) {
        console.error('Unable to poll customer order status', error);
    }
}

function startCustomerOrderStatusPolling() {
    if (customerOrderStatusPoller) return;
    customerOrderStatusPoller = window.setInterval(() => {
        void pollCustomerOrderStatus();
    }, 5000);
}

async function loadPendingOrdersFromServer() {
    try {
        const response = await fetch(getApiUrl('api/get_pending_orders.php'), { cache: 'no-store' });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        const serverOrders = Array.isArray(payload.orders) ? payload.orders : [];

        pendingOrders = serverOrders.map((order) => {
            const items = Array.isArray(order.items) ? order.items : [];
            return {
                id: Number(order.id),
                orderNumber: order.order_number || order.orderNumber || String(order.id),
                timestamp: new Date(order.order_date || Date.now()).getTime(),
                total: Number(order.total_amount ?? order.total ?? 0),
                paymentMethod: order.payment_method || order.paymentMethod || 'Cash',
                orderType: order.order_type || order.orderType || 'Dine In',
                items: items.map((item) => ({
                    id: Number(item.id ?? 0),
                    name: item.notes || item.name || 'Menu item',
                    notes: item.notes || item.name || 'Menu item',
                    price: Number(item.unit_price ?? item.price ?? 0),
                    quantity: Number(item.quantity ?? 0)
                }))
            };
        });

        pendingOrders.sort((a, b) => b.timestamp - a.timestamp);
        savePendingOrders();
        renderPendingOrders();
        renderOrderNotifications();
    } catch (error) {
        console.error('Unable to load pending orders from the server', error);
    }
}

async function submitOrderToServer(order) {
    try {
        const response = await fetch(getApiUrl('api/create_order.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                orderNumber: order.orderNumber,
                items: order.items,
                paymentMethod: order.paymentMethod,
                orderType: order.orderType
            })
        });

        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Unable to save order');
        }

        return {
            ...order,
            id: Number(payload.orderId || Date.now()),
            orderNumber: order.orderNumber || String(payload.orderId || Date.now())
        };
    } catch (error) {
        console.error('Unable to save order to the server', error);
        return null;
    }
}

async function markOrderCompleteOnServer(orderId) {
    const response = await fetch(getApiUrl('api/mark_order_complete.php'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ orderId }),
        cache: 'no-store'
    });

    const payload = await response.json();
    if (!response.ok || !payload.success) {
        throw new Error(payload.error || `HTTP ${response.status}`);
    }

    return payload;
}

function getReservedPendingQuantityForItem(itemName, excludingOrderId = null, excludingItemId = null) {
    const targetName = normalizeInventoryName(itemName);

    return pendingOrders.reduce((sum, order) => {
        if (excludingOrderId !== null && Number(order.id) === Number(excludingOrderId)) {
            const orderItems = Array.isArray(order.items) ? order.items : [];
            const partial = orderItems.reduce((sub, item) => {
                if (excludingItemId !== null && Number(item.id) === Number(excludingItemId)) {
                    return sub;
                }
                return normalizeInventoryName(item.name) === targetName ? sub + (Number(item.quantity) || 0) : sub;
            }, 0);
            return sum + partial;
        }

        const orderItems = Array.isArray(order.items) ? order.items : [];
        return sum + orderItems.reduce((sub, item) => {
            return normalizeInventoryName(item.name) === targetName ? sub + (Number(item.quantity) || 0) : sub;
        }, 0);
    }, 0);
}

function getMaxEditablePendingQuantity(orderId, item) {
    const inventoryItem = getInventoryItem(item.name);
    if (!inventoryItem) return Number.MAX_SAFE_INTEGER;

    const stock = Math.max(0, Number(inventoryItem.stock) || 0);
    const reservedByOthers = getReservedPendingQuantityForItem(item.name, orderId, item.id);
    return Math.max(0, stock - reservedByOthers);
}

async function updatePendingOrderItemQuantity(orderId, itemId, quantity) {
    const response = await fetch(getApiUrl('api/update_pending_order_item.php'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ orderId, itemId, quantity }),
        cache: 'no-store'
    });

    const payload = await response.json();
    if (!response.ok || !payload.success) {
        const maxAllowed = payload && Number.isFinite(Number(payload.maxAllowed)) ? Number(payload.maxAllowed) : null;
        const message = maxAllowed !== null
            ? `${payload.error || 'Unable to update order item'} (Max allowed: ${maxAllowed})`
            : (payload.error || `HTTP ${response.status}`);
        throw new Error(message);
    }

    return payload;
}

async function changePendingOrderItemQuantity(orderIndex, itemId, direction) {
    if (orderIndex < 0 || orderIndex >= pendingOrders.length) return;
    const order = pendingOrders[orderIndex];
    const items = Array.isArray(order.items) ? order.items : [];
    const item = items.find((entry) => Number(entry.id) === Number(itemId));
    if (!item) return;

    const currentQuantity = Number(item.quantity) || 0;
    const delta = direction === 'increase' ? 1 : -1;
    const nextQuantity = currentQuantity + delta;
    if (nextQuantity < 1) return;

    const maxAllowed = getMaxEditablePendingQuantity(order.id, item);
    if (direction === 'increase' && nextQuantity > maxAllowed) {
        return;
    }

    try {
        await updatePendingOrderItemQuantity(order.id, item.id, nextQuantity);
    } catch (error) {
        console.error('Unable to edit pending order quantity', error);
        if (typeof window !== 'undefined' && window.alert) {
            window.alert(error.message || 'Unable to edit order quantity');
        }
        return;
    }

    void loadPendingOrdersFromServer();
    void initializeInventoryData(true);
}

async function markPendingOrderAsComplete(orderIndex, shouldIgnore = false) {
    if (orderIndex < 0 || orderIndex >= pendingOrders.length) return;

    const targetOrder = pendingOrders[orderIndex];
    try {
        await markOrderCompleteOnServer(targetOrder.id);
    } catch (error) {
        console.error('Unable to mark order complete on server', error);
        return;
    }

    const completedOrder = pendingOrders.splice(orderIndex, 1)[0];
    if (shouldIgnore) {
        ignorePendingOrder(completedOrder.orderNumber || completedOrder.id);
    }
    completedOrders.unshift(completedOrder);
    recalculateSalesAnalytics();
    savePendingOrders();
    saveCompletedOrders();
    renderPendingOrders();
    renderOrderNotifications();
    updateAnalyticsView();
    renderOverviewAnalytics();
    void initializeInventoryData(true);
}

function loadCompletedOrders() {
    try {
        const raw = localStorage.getItem('motasteCompletedOrders');
        completedOrders = raw ? JSON.parse(raw) : [];
    } catch (error) {
        completedOrders = [];
    }
    completedOrders.sort((a, b) => b.timestamp - a.timestamp);
}

function saveCompletedOrders() {
    localStorage.setItem('motasteCompletedOrders', JSON.stringify(completedOrders));
}

const inventoryCategoryLabels = {
    all: 'All',
    batchoy: 'Batchoy',
    silog: 'Silog',
    friedChicken: 'Fried Chicken',
    breakfast: 'Breakfast',
    drinks: 'Drinks',
    specials: 'Specials'
};

function resolveInventoryCategory(itemName) {
    const normalizedName = (itemName || '').trim().toLowerCase();
    if (!normalizedName) return 'specials';

    for (const [key, category] of Object.entries(menuData)) {
        const match = category.items.some((menuItem) => (menuItem.name || '').toLowerCase() === normalizedName);
        if (match) return key;
    }

    return 'specials';
}

function buildDefaultInventoryFromMenu() {
    const items = [];
    const seen = new Set();

    Object.entries(menuData).forEach(([categoryKey, category]) => {
        category.items.forEach((item) => {
            if (seen.has(item.name)) return;
            seen.add(item.name);
            items.push({
                name: item.name,
                price: parsePrice(item.price),
                stock: 0,
                status: 'Out of stock',
                category: categoryKey
            });
        });
    });

    specialFoods.forEach((food) => {
        if (seen.has(food.name)) return;
        seen.add(food.name);
        items.push({
            name: food.name,
            price: Number(food.price) || 0,
            stock: 0,
            status: 'Out of stock',
            category: 'specials'
        });
    });

    return items;
}

async function initializeInventoryData(forceRefresh = false) {
    if (inventorySyncInFlight && !forceRefresh) return;
    if (inventoryEditLock && !forceRefresh) return;

    inventorySyncInFlight = true;
    const defaults = buildDefaultInventoryFromMenu();
    try {
        const inventoryUrl = getApiUrl(`api/get_inventory.php?_=${Date.now()}`);
        const response = await fetch(inventoryUrl, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        const serverItems = Array.isArray(payload.items) ? payload.items : [];
        const merged = serverItems.map((item) => ({
            name: item.name,
            price: Number(item.price) || 0,
            stock: Number(item.stock) || 0,
            status: item.status || (Number(item.stock) > 0 ? 'In stock' : 'Out of stock'),
            category: item.category || resolveInventoryCategory(item.name)
        }));

        const mergedNames = new Set(merged.map((item) => normalizeInventoryName(item.name)));
        defaults.forEach((item) => {
            if (!mergedNames.has(normalizeInventoryName(item.name))) {
                merged.push(item);
                mergedNames.add(normalizeInventoryName(item.name));
            }
        });

        const latestInventory = merged.map((item) => ({ ...item }));
        const hasLocalChanges = inventoryData.some((item) => {
            const serverItem = latestInventory.find((candidate) => normalizeInventoryName(candidate.name) === normalizeInventoryName(item.name));
            return serverItem && (serverItem.stock !== item.stock || serverItem.status !== item.status || serverItem.price !== item.price);
        });

        if (!hasLocalChanges || forceRefresh) {
            inventoryData = latestInventory;
            saveInventoryData();
            debugInventory('Applied server inventory', 'server');
        } else {
            debugInventory('Skipped applying server inventory due to local changes', 'server');
        }
    } catch (error) {
        inventoryData = inventoryData.length ? inventoryData : defaults;
        saveInventoryData();
        debugInventory('initializeInventoryData error — kept local or defaults', 'server-error');
    } finally {
        inventorySyncInFlight = false;
    }

    syncMenuPricesWithInventory();
    renderSpecialFoods();
    renderInventoryManagement();
    renderOverviewInventory();
    if (currentMenuCategoryId) {
        showMenuCategory(currentMenuCategoryId);
    }
    updateCartDisplay();
}

function saveInventoryData() {
    try {
        lastInventoryUpdateAt = Date.now();
        localStorage.setItem('motasteInventoryData', JSON.stringify(inventoryData));
        localStorage.setItem('motasteInventoryDataUpdatedAt', String(lastInventoryUpdateAt));
    } catch (error) {
        console.error('Unable to persist inventory data to localStorage', error);
    }
}

// Debug helper: log inventory summary
function debugInventory(msg, source) {
    try {
        const summary = (inventoryData || []).map(i => ({ name: i.name, stock: i.stock }));
        console.debug(`[INV] ${msg}`, { source: source || 'unknown', at: Date.now(), summary, lastInventoryUpdateAt });
    } catch (e) {
        console.debug('[INV] debugInventory error', e);
    }
}

function startInventoryAutoRefresh() {
    if (!enableInventoryAutoRefresh) return;
    if (inventoryRefreshTimer) return;

    inventoryRefreshTimer = window.setInterval(() => {
        void initializeInventoryData();
    }, 5000);
}

function stopInventoryAutoRefresh() {
    if (inventoryRefreshTimer) {
        window.clearInterval(inventoryRefreshTimer);
        inventoryRefreshTimer = null;
    }
}

function startCustomerInventoryRefresh() {
    if (!isCustomerPage || customerInventoryRefreshTimer) return;

    customerInventoryRefreshTimer = window.setInterval(() => {
        void loadCustomMenuData();
        void initializeInventoryData(true);
    }, 10000);
}

function stopCustomerInventoryRefresh() {
    if (customerInventoryRefreshTimer) {
        window.clearInterval(customerInventoryRefreshTimer);
        customerInventoryRefreshTimer = null;
    }
}

function saveCustomMenuData() {
    const snapshot = {
        menuData: Object.fromEntries(Object.entries(menuData).map(([categoryKey, category]) => [
            categoryKey,
            {
                title: category.title,
                items: category.items.map((item) => ({
                    name: item.name,
                    price: item.price,
                    description: item.description || ''
                }))
            }
        ])),
        specialFoods: specialFoods.map((food) => ({
            name: food.name,
            price: food.price,
            image: food.image
        }))
    };
    localStorage.setItem('motasteCustomMenuData', JSON.stringify(snapshot));

    void fetch(getApiUrl('api/save_custom_menu.php'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(snapshot),
        cache: 'no-store'
    }).catch((error) => {
        console.error('Unable to sync custom menu snapshot to server', error);
    });
}

function applyCustomMenuSnapshot(snapshot) {
    if (!snapshot || typeof snapshot !== 'object') return false;

    let changed = false;

    const menuDataSnapshot = snapshot.menuData || {};
    Object.entries(menuDataSnapshot).forEach(([categoryKey, category]) => {
        if (!menuData[categoryKey]) {
            menuData[categoryKey] = { title: category.title || categoryKey.toUpperCase(), items: [] };
        }
        const seenNames = new Set((menuData[categoryKey].items || []).map((item) => (item.name || '').trim().toLowerCase()));
        (category.items || []).forEach((item) => {
            const normalizedName = (item.name || '').trim().toLowerCase();
            if (!normalizedName || seenNames.has(normalizedName)) return;
            menuData[categoryKey].items.push({
                name: item.name,
                price: item.price,
                description: item.description || `${item.name} has been added by staff.`
            });
            seenNames.add(normalizedName);
            changed = true;
        });
    });

    if (Array.isArray(snapshot.specialFoods)) {
        const seenSpecialFoods = new Set(specialFoods.map((food) => (food.name || '').trim().toLowerCase()));
        snapshot.specialFoods.forEach((food) => {
            const normalizedName = (food.name || '').trim().toLowerCase();
            if (!normalizedName || seenSpecialFoods.has(normalizedName)) return;
            specialFoods.push({
                name: food.name,
                price: Number(food.price) || 0,
                image: food.image || 'img1.jpg'
            });
            seenSpecialFoods.add(normalizedName);
            changed = true;
        });
    }

    return changed;
}

async function loadCustomMenuData() {
    try {
        const raw = localStorage.getItem('motasteCustomMenuData');
        if (!raw) return;

        const parsed = JSON.parse(raw);
        applyCustomMenuSnapshot(parsed);

        const response = await fetch(getApiUrl(`api/get_custom_menu.php?_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) return;

        const payload = await response.json();
        if (payload && payload.success && payload.snapshot) {
            const changed = applyCustomMenuSnapshot(payload.snapshot);
            if (changed) {
                saveCustomMenuData();
                syncMenuPricesWithInventory();
                renderSpecialFoods();
                renderInventoryManagement();
                if (currentMenuCategoryId) {
                    showMenuCategory(currentMenuCategoryId);
                }
            }
        }
    } catch (error) {
        console.error('Unable to load custom menu snapshot', error);
    }
}
window.addEventListener('storage', (event) => {
    if (!event.key) return;
    if (inventoryEditLock) return;

    if (event.key === 'motasteCustomMenuData') {
        loadCustomMenuData();
        if (currentMenuCategoryId) {
            showMenuCategory(currentMenuCategoryId);
        }
    }

    if (syncInventoryAcrossTabs && (event.key === 'motasteInventoryData' || event.key === 'motasteInventoryDataUpdatedAt')) {
        try {
            const remoteUpdatedAt = Number(localStorage.getItem('motasteInventoryDataUpdatedAt') || '0');
            if (remoteUpdatedAt && remoteUpdatedAt <= (lastInventoryUpdateAt || 0)) {
                // Ignore older updates
                debugInventory('Ignored storage event: older remoteUpdatedAt', 'storage');
                return;
            }

            const raw = localStorage.getItem('motasteInventoryData');
            inventoryData = raw ? JSON.parse(raw) : [];
        } catch (error) {
            inventoryData = [];
        }
        debugInventory('Applied storage event inventory', 'storage');
        syncMenuPricesWithInventory();
        if (currentMenuCategoryId) {
            showMenuCategory(currentMenuCategoryId);
        }
    }
});

function updateCartDisplay() {
    clampCartToInventory();
    const totalItems = cartItems.reduce((sum, item) => sum + item.quantity, 0);
    if (menuTopCartCount) {
        menuTopCartCount.textContent = totalItems;
        menuTopCartCount.parentElement.classList.toggle('has-items', totalItems > 0);
    }
    syncVisibleMenuItemQuantities();
    if (!menuCartList || !menuCartCount || !menuCartTotal || !menuPlaceOrderBtn) return;

    if (!cartItems.length) {
        menuCartList.innerHTML = '<p class="menu-cart-empty">Your cart is empty.</p>';
        menuCartCount.textContent = '0 items';
        menuCartTotal.textContent = formatCurrency(0);
        menuPlaceOrderBtn.disabled = true;
        return;
    }

    let total = 0;
    menuCartList.innerHTML = cartItems.map((item, index) => {
        const itemTotal = item.quantity * item.price;
        total += itemTotal;
        return `
            <div class="menu-cart-item">
                <div class="menu-cart-item-details">
                    <div>
                        <strong>${item.name}</strong>
                        <div class="menu-cart-item-qty-controls">
                            <button type="button" class="menu-cart-item-quantity-btn" data-action="decrease" data-index="${index}" aria-label="Decrease ${item.name} quantity"${item.quantity === 1 ? ' disabled' : ''}>
                                <i class="fa-solid fa-minus" aria-hidden="true"></i>
                            </button>
                            <span class="menu-cart-item-qty">${item.quantity}</span>
                            <button type="button" class="menu-cart-item-quantity-btn" data-action="increase" data-index="${index}" aria-label="Increase ${item.name} quantity">
                                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="menu-cart-item-remove" data-index="${index}">Remove</button>
                </div>
                <div>${formatCurrency(itemTotal)}</div>
            </div>
        `;
    }).join('');

    menuCartCount.textContent = `${totalItems} items`;
    menuCartTotal.textContent = formatCurrency(total);
    menuPlaceOrderBtn.disabled = false;
}

function getInventoryItem(name) {
    const targetName = normalizeInventoryName(name);
    return inventoryData.find((item) => normalizeInventoryName(item.name) === targetName);
}

function getCartQuantityForItem(name) {
    return cartItems.reduce((total, item) => total + (normalizeInventoryName(item.name) === normalizeInventoryName(name) ? Number(item.quantity) || 0 : 0), 0);
}

function getAvailableStockForItem(name) {
    const inventoryItem = getInventoryItem(name);
    if (!inventoryItem) return Infinity;

    const stock = Math.max(0, Number(inventoryItem.stock) || 0);
    return Math.max(0, stock - getCartQuantityForItem(name));
}

function clampCartToInventory() {
    let changed = false;

    cartItems = cartItems.reduce((items, item) => {
        const inventoryItem = getInventoryItem(item.name);
        if (!inventoryItem) {
            items.push(item);
            return items;
        }

        const stock = Math.max(0, Number(inventoryItem.stock) || 0);
        const nextQuantity = Math.min(Math.max(0, Number(item.quantity) || 0), stock);
        if (nextQuantity <= 0) {
            changed = true;
            return items;
        }

        if (nextQuantity !== item.quantity) {
            changed = true;
        }

        items.push({ ...item, quantity: nextQuantity });
        return items;
    }, []);

    if (changed) {
        saveCart();
    }

    return changed;
}

function syncVisibleMenuItemQuantities() {
    if (menuCategoryScreen) {
        menuCategoryScreen.querySelectorAll('.menu-item-card').forEach((card) => {
            const name = card.querySelector('.menu-item-qty-btn[data-action="increase"]')?.dataset.name;
            if (!name) return;

            const quantityElement = card.querySelector('.menu-item-qty');
            const increaseButton = card.querySelector('.menu-item-qty-btn[data-action="increase"]');
            const decreaseButton = card.querySelector('.menu-item-qty-btn[data-action="decrease"]');
            const currentQty = getCartQuantityForItem(name);
            const availableStock = getAvailableStockForItem(name);

            if (quantityElement) {
                quantityElement.textContent = String(currentQty);
            }
            if (increaseButton) {
                increaseButton.disabled = availableStock <= 0;
            }
            if (decreaseButton) {
                decreaseButton.disabled = currentQty <= 0;
            }
        });
    }

    if (specialFoodsList) {
        specialFoodsList.querySelectorAll('.special-food-add').forEach((button) => {
            const name = button.dataset.name;
            if (!name) return;
            button.disabled = getAvailableStockForItem(name) <= 0;
        });
    }
}

function decrementInventory(items) {
    items.forEach((item) => {
        const inventoryItem = getInventoryItem(item.name);
        if (!inventoryItem) return;
        inventoryItem.stock = Math.max(0, inventoryItem.stock - item.quantity);
        if (inventoryItem.stock <= 0) {
            inventoryItem.status = 'Out of stock';
        } else if (inventoryItem.stock <= 5) {
            inventoryItem.status = 'Low stock';
        } else {
            inventoryItem.status = 'In stock';
        }
    });
    saveInventoryData();
}

function renderOrderNotifications() {
    if (!overviewOrderNotificationList || !overviewOrderRevenue) return;

    const sortedPendingOrders = [...pendingOrders].sort((a, b) => b.timestamp - a.timestamp);
    const sortedCompletedOrders = [...completedOrders].sort((a, b) => b.timestamp - a.timestamp);
    const allOrders = [...sortedPendingOrders, ...sortedCompletedOrders];
    const totalRevenue = allOrders.reduce((sum, order) => sum + (order.total || 0), 0);
    overviewOrderRevenue.textContent = formatCurrency(totalRevenue);

    if (!allOrders.length) {
        overviewOrderNotificationList.innerHTML = '<p class="menu-cart-empty">There are no order notifications yet.</p>';
        return;
    }

    overviewOrderNotificationList.innerHTML = allOrders.map((order) => {
        const isCompleted = completedOrders.some((completed) => completed.id === order.id);
        const items = Array.isArray(order.items) ? order.items : [];
        const orderItems = items.map((item) => `
                <li>${item.name} x${item.quantity} — ${formatCurrency(item.price * item.quantity)}</li>
            `).join('');
        return `
            <article class="order-notification-card ${isCompleted ? 'completed' : ''}">
                <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                    <h4>Order #${order.orderNumber}</h4>
                    <span>${isCompleted ? 'Completed' : 'New'}</span>
                </div>
                <p><strong>Submitted:</strong> ${new Date(order.timestamp).toLocaleString()}</p>
                <p><strong>Payment:</strong> ${order.paymentMethod}</p>
                <ul>${orderItems}</ul>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                    <strong>Total: ${formatCurrency(order.total)}</strong>
                    ${isCompleted ? '' : `<button type="button" class="order-complete-btn" data-order-id="${order.id}">Mark Complete</button>`}
                </div>
            </article>
        `;
    }).join('');
}

function renderInventoryList(container, items) {
    if (!container) return;
    if (!items || !items.length) {
        // hide the inventory list entirely when there are no items
        container.innerHTML = '';
        return;
    }

    container.innerHTML = `
        <div class="inventory-list-panel">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr>
                            <td>${item.name}</td>
                            <td>${item.stock}</td>
                            <td class="${item.stock <= 5 ? 'inventory-stock-low' : ''}">${item.status}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

function renderOverviewInventory() {
    // hide the entire overview inventory box when there are no inventory items
    const overviewBox = document.querySelector('.overview-inventory-box');
    if (!inventoryData || !inventoryData.length) {
        if (overviewBox) overviewBox.hidden = true;
        if (overviewInventoryList) overviewInventoryList.innerHTML = '';
        return;
    }

    if (overviewBox) overviewBox.hidden = false;
    renderInventoryList(overviewInventoryList, inventoryData);
}

function syncMenuPricesWithInventory() {
    if (!inventoryData || !inventoryData.length) return;

    const priceMap = inventoryData.reduce((map, item) => {
        map[item.name] = item.price;
        return map;
    }, {});

    Object.values(menuData).forEach((category) => {
        category.items.forEach((item) => {
            if (priceMap[item.name] !== undefined) {
                item.price = `₱${priceMap[item.name].toLocaleString()}`;
            }
        });
    });

    specialFoods.forEach((food) => {
        if (priceMap[food.name] !== undefined) {
            food.price = priceMap[food.name];
        }
    });
}

let inventorySelectedCategory = 'all';
let inventorySearchTerm = '';

function getFilteredInventoryItems() {
    const term = (inventorySearchTerm || '').trim().toLowerCase();
    return (inventoryData || []).filter((item) => {
        const category = item.category || resolveInventoryCategory(item.name);
        const matchesCategory = inventorySelectedCategory === 'all' || category === inventorySelectedCategory;
        const matchesSearch = !term || (item.name || '').toLowerCase().includes(term);
        return matchesCategory && matchesSearch;
    });
}

function renderInventoryManagement() {
    const isAdmin = selectedRoleInput && selectedRoleInput.value === 'Admin';

    if (!isAdmin) {
        setInventoryModalVisible(false);
    }

    if (inventoryAddFab) {
        inventoryAddFab.hidden = !isAdmin;
    }
    if (inventoryAccessNote) {
        inventoryAccessNote.textContent = isAdmin ? 'Admin mode: use the floating + button to add a new product, or edit any item inline.' : 'View-only: admin must sign in to add or edit inventory.';
    }

    if (!inventoryItemsWrapper) return;

    const filteredItems = getFilteredInventoryItems();

    if (!filteredItems.length) {
        inventoryItemsWrapper.innerHTML = '<p class="menu-cart-empty">No matching inventory items found.</p>';
        inventoryItemsWrapper.hidden = false;
        return;
    }

    inventoryItemsWrapper.hidden = false;
    inventoryItemsWrapper.innerHTML = filteredItems.map((item) => {
        const editing = inventoryEditItemName === item.name;
        const category = item.category || resolveInventoryCategory(item.name);
        const categoryLabel = inventoryCategoryLabels[category] || 'Specials';

        if (!editing) {
            return `
                <article class="inventory-item-card">
                    <div class="inventory-item-main">
                        <strong>${item.name}</strong>
                        <p><span class="inventory-item-category">${categoryLabel}</span></p>
                        <p>Price: ${formatCurrency(item.price)}</p>
                        <p class="inventory-stock-line">
                            <span>Stock: ${item.stock}</span>
                            ${item.stock <= 0 ? `<img src="../../outofstock1.png" alt="Out of stock" class="inventory-out-of-stock-image">` : ''}
                        </p>
                        <p>Status: ${item.status}</p>
                    </div>
                    <div class="inventory-item-actions">
                        <button type="button" class="inventory-edit-btn" data-item-name="${item.name}">Edit</button>
                        <button type="button" class="inventory-inline-delete inventory-card-delete-btn" data-item-name="${item.name}">Delete</button>
                    </div>
                </article>
            `;
        }

        return `
            <article class="inventory-item-card is-editing" data-item-name="${item.name}">
                <div class="inventory-inline-editor">
                    <label>
                        Item Name
                        <input type="text" data-field="name" value="${item.name}">
                    </label>
                    <label>
                        Category
                        <select data-field="category">
                            <option value="batchoy" ${category === 'batchoy' ? 'selected' : ''}>Batchoy</option>
                            <option value="silog" ${category === 'silog' ? 'selected' : ''}>Silog</option>
                            <option value="friedChicken" ${category === 'friedChicken' ? 'selected' : ''}>Fried Chicken</option>
                            <option value="breakfast" ${category === 'breakfast' ? 'selected' : ''}>Breakfast</option>
                            <option value="drinks" ${category === 'drinks' ? 'selected' : ''}>Drinks</option>
                            <option value="specials" ${category === 'specials' ? 'selected' : ''}>Specials</option>
                        </select>
                    </label>
                    <label>
                        Price
                        <input type="number" min="0" step="0.01" data-field="price" value="${item.price}">
                    </label>
                    <label>
                        Stock
                        <input type="number" min="0" step="1" data-field="stock" value="${item.stock}">
                    </label>
                    <label>
                        Status
                        <select data-field="status">
                            <option value="In stock" ${item.status === 'In stock' ? 'selected' : ''}>In stock</option>
                            <option value="Low stock" ${item.status === 'Low stock' ? 'selected' : ''}>Low stock</option>
                            <option value="Out of stock" ${item.status === 'Out of stock' ? 'selected' : ''}>Out of stock</option>
                        </select>
                    </label>
                </div>
                <div class="inventory-item-actions inline-actions">
                    <button type="button" class="inventory-inline-save" data-item-name="${item.name}">Save</button>
                    <button type="button" class="inventory-inline-cancel" data-item-name="${item.name}">Cancel</button>
                </div>
            </article>
        `;
    }).join('');
}

function saveMenuCatalogItem(item, previousName = null) {
    const category = item.category || resolveInventoryCategory(item.name);
    const priceNumber = Number(item.price) || 0;
    const normalizedPreviousName = (previousName || item.name || '').trim().toLowerCase();

    if (category === 'specials') {
        const existingSpecialIndex = specialFoods.findIndex((food) => (food.name || '').trim().toLowerCase() === normalizedPreviousName || (food.name || '').trim().toLowerCase() === (item.name || '').trim().toLowerCase());
        if (existingSpecialIndex >= 0) {
            specialFoods[existingSpecialIndex] = {
                ...specialFoods[existingSpecialIndex],
                name: item.name,
                price: priceNumber,
                image: specialFoods[existingSpecialIndex].image || 'img1.jpg'
            };
        } else {
            specialFoods.push({
                name: item.name,
                price: priceNumber,
                image: 'img1.jpg'
            });
        }
    } else if (menuData[category]) {
        const existingMenuIndex = menuData[category].items.findIndex((menuItem) => (menuItem.name || '').trim().toLowerCase() === normalizedPreviousName || (menuItem.name || '').trim().toLowerCase() === (item.name || '').trim().toLowerCase());
        if (existingMenuIndex >= 0) {
            menuData[category].items[existingMenuIndex] = {
                ...menuData[category].items[existingMenuIndex],
                name: item.name,
                price: `₱${priceNumber.toLocaleString()}`,
                description: menuData[category].items[existingMenuIndex].description || `${item.name} has been updated by staff.`
            };
        } else {
            menuData[category].items.push({
                name: item.name,
                price: `₱${priceNumber.toLocaleString()}`,
                description: `${item.name} has been added by staff.`
            });
        }
    } else {
        menuData[category] = {
            title: category === 'specials' ? 'SPECIALS' : category.toUpperCase(),
            items: [
                {
                    name: item.name,
                    price: `₱${priceNumber.toLocaleString()}`,
                    description: `${item.name} has been added by staff.`
                }
            ]
        };
    }

    saveCustomMenuData();
}

function removeMenuItemByName(itemName) {
    const normalizedName = (itemName || '').trim().toLowerCase();
    if (!normalizedName) return false;

    let removed = false;

    Object.values(menuData).forEach((category) => {
        const beforeCount = category.items.length;
        category.items = category.items.filter((menuItem) => (menuItem.name || '').trim().toLowerCase() !== normalizedName);
        if (category.items.length !== beforeCount) {
            removed = true;
        }
    });

    const specialIndex = specialFoods.findIndex((food) => (food.name || '').trim().toLowerCase() === normalizedName);
    if (specialIndex >= 0) {
        specialFoods.splice(specialIndex, 1);
        removed = true;
    }

    if (removed) {
        saveCustomMenuData();
    }

    return removed;
}

function deleteInventoryItem(name) {
    const index = inventoryData.findIndex((item) => item.name === name);
    if (index < 0) return;

    inventoryData.splice(index, 1);
    saveInventoryData();
    removeMenuItemByName(name);
    syncMenuPricesWithInventory();
    inventoryEditItemName = null;
    renderInventoryManagement();
    renderOverviewInventory();
    renderSpecialFoods();
    if (currentMenuCategoryId) {
        showMenuCategory(currentMenuCategoryId);
    }
}

async function saveInventoryItem(event) {
    if (event && event.preventDefault) {
        event.preventDefault();
    }

    if (!inventoryNameInput || !inventoryPriceInput || !inventoryStockInput || !inventoryStatusInput || !inventoryCategoryInput) return;

    const name = inventoryNameInput.value.trim();
    const price = Number(inventoryPriceInput.value);
    const stock = Number(inventoryStockInput.value);
    const category = inventoryCategoryInput.value || 'specials';
    const status = stock <= 0 ? 'Out of stock' : inventoryStatusInput.value;

    if (!name || Number.isNaN(price) || Number.isNaN(stock)) {
        return;
    }

    const existingItem = inventoryData.find((item) => item.name.toLowerCase() === name.toLowerCase());

    if (existingItem) {
        existingItem.price = price;
        existingItem.stock = stock;
        existingItem.status = status;
        existingItem.category = category;
        saveMenuCatalogItem(existingItem);
    } else {
        inventoryData.push({
            name,
            price,
            stock,
            status,
            category
        });
        saveMenuCatalogItem({ name, price, stock, status, category });
    }

    inventoryEditItemName = null;
    if (inventorySaveBtn) {
        inventorySaveBtn.textContent = 'Save Inventory Item';
    }

    saveInventoryData();
    let syncSucceeded = false;
    try {
        const response = await fetch(getApiUrl('api/update_inventory.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ name, price, stock, status, category })
        });

        if (!response.ok) {
            throw new Error(`Inventory sync failed: HTTP ${response.status}`);
        }

        const payload = await response.json();
        if (!payload || payload.success !== true) {
            const details = payload?.details ? ` (${payload.details})` : '';
            throw new Error(`Inventory sync failed: ${payload?.error || 'Unknown server response'}${details}`);
        }
        syncSucceeded = true;
    } catch (error) {
        console.error('Unable to sync inventory with server', error);
        window.alert(`Inventory update failed on server. ${error?.message || ''}`);
    }

    if (!syncSucceeded) {
        inventoryEditLock = false;
        startInventoryAutoRefresh();
        void initializeInventoryData(true);
        return;
    }
    debugInventory('Saved inventory and sent update to server', 'local-save');

    syncMenuPricesWithInventory();
    renderInventoryManagement();
    renderOverviewInventory();
    renderSpecialFoods();

    if (currentMenuCategoryId) {
        showMenuCategory(currentMenuCategoryId);
    }

    if (inventoryForm) {
        inventoryForm.reset();
        inventoryCategoryInput.value = 'batchoy';
    }

    setInventoryModalVisible(false);
    inventoryEditLock = false;
    startInventoryAutoRefresh();
    void initializeInventoryData(true);
}

function editInventoryItem(name) {
    const item = inventoryData.find((inventoryItem) => inventoryItem.name === name);
    if (!item) return;

    stopInventoryAutoRefresh();
    inventoryEditLock = true;
    inventoryEditItemName = item.name;
    renderInventoryManagement();
}

async function commitInlineInventoryEdit(card) {
    const itemName = card.dataset.itemName;
    const previousItem = inventoryData.find((item) => item.name === itemName);
    if (!previousItem) return;

    const nameInput = card.querySelector('[data-field="name"]');
    const categoryInput = card.querySelector('[data-field="category"]');
    const priceInput = card.querySelector('[data-field="price"]');
    const stockInput = card.querySelector('[data-field="stock"]');
    const statusInput = card.querySelector('[data-field="status"]');

    if (!nameInput || !categoryInput || !priceInput || !stockInput || !statusInput) return;

    const nextName = nameInput.value.trim();
    const price = Number(priceInput.value);
    const stock = Number(stockInput.value);
    const category = categoryInput.value || 'specials';
    const status = stock <= 0 ? 'Out of stock' : statusInput.value;

    if (!nextName || Number.isNaN(price) || Number.isNaN(stock)) return;

    const previousSnapshot = {
        name: previousItem.name,
        price: previousItem.price,
        stock: previousItem.stock,
        status: previousItem.status,
        category: previousItem.category,
    };

    previousItem.name = nextName;
    previousItem.price = price;
    previousItem.stock = stock;
    previousItem.status = status;
    previousItem.category = category;

    saveMenuCatalogItem(previousItem, itemName);
    saveInventoryData();
    inventoryRefreshVersion += 1;
    let syncSucceeded = false;
    try {
        const response = await fetch(getApiUrl('api/update_inventory.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ name: nextName, price, stock, status, category })
        });

        if (!response.ok) {
            throw new Error(`Inventory sync failed: HTTP ${response.status}`);
        }

        const payload = await response.json();
        if (!payload || payload.success !== true) {
            const details = payload?.details ? ` (${payload.details})` : '';
            throw new Error(`Inventory sync failed: ${payload?.error || 'Unknown server response'}${details}`);
        }
        syncSucceeded = true;
    } catch (error) {
        console.error('Unable to sync inventory with server', error);
        previousItem.name = previousSnapshot.name;
        previousItem.price = previousSnapshot.price;
        previousItem.stock = previousSnapshot.stock;
        previousItem.status = previousSnapshot.status;
        previousItem.category = previousSnapshot.category;
        saveInventoryData();
        inventoryEditItemName = null;
        inventoryEditLock = false;
        renderInventoryManagement();
        renderOverviewInventory();
        renderSpecialFoods();
        window.alert(`Inventory update failed on server. ${error?.message || ''}`);
        startInventoryAutoRefresh();
        return;
    }

    if (!syncSucceeded) {
        startInventoryAutoRefresh();
        return;
    }

    syncMenuPricesWithInventory();
    inventoryEditItemName = null;
    inventoryEditLock = false;
    renderInventoryManagement();
    renderOverviewInventory();
    renderSpecialFoods();

    if (currentMenuCategoryId) {
        showMenuCategory(currentMenuCategoryId);
    }

    startInventoryAutoRefresh();
    void initializeInventoryData(true);
}

function renderOverviewAnalytics() {
    if (!overviewAnalyticsSelect || !overviewAnalyticsChart || !overviewMonthSelect || !overviewMonthWrapper) return;

    const view = overviewAnalyticsSelect.value;
    overviewMonthWrapper.style.display = view === 'monthly' ? 'none' : 'inline-flex';

    if (view === 'daily') {
        const month = overviewMonthSelect.value;
        const monthData = monthlySalesByMonth[month] || monthlySalesByMonth.jan;
        renderDetailChart(overviewAnalyticsChart, monthData, `Daily Sales — ${overviewMonthSelect.options[overviewMonthSelect.selectedIndex]?.text || ''}`);
    } else if (view === 'weekly') {
        const month = overviewMonthSelect.value;
        const monthData = weeklySalesByMonth[month] || weeklySalesByMonth.jan;
        renderDetailChart(overviewAnalyticsChart, monthData, `Weekly Sales — ${overviewMonthSelect.options[overviewMonthSelect.selectedIndex]?.text || ''}`);
    } else {
        const monthly = analyticsData.monthly.items;
        renderDetailChart(overviewAnalyticsChart, monthly, 'Monthly Sales');
    }
}

function showDashboardSection(section) {
    setInventoryModalVisible(false);

    const sections = [overviewSection, salesSection, pendingOrdersSection, inventorySection, accountManagementSection];
    sections.forEach((el) => {
        if (!el) return;
        el.hidden = el !== section;
    });

    if (section && section.id) {
        saveActiveSection(section.id);
    }
}

function isItemOutOfStock(itemName) {
    const inventoryItem = getInventoryItem(itemName);
    return Boolean(inventoryItem && inventoryItem.stock <= 0);
}

function renderSpecialFoods() {
    if (!specialFoodsList) return;

    specialFoodsList.innerHTML = specialFoods.map((item) => {
        const imageSrc = item.image || 'img1.jpg';
        const isOutOfStock = isItemOutOfStock(item.name);
        const canAddMore = getAvailableStockForItem(item.name) > 0;
        return `
        <article class="special-food-card${isOutOfStock ? ' is-out-of-stock' : ''}">
            <img src="${imageSrc}" alt="${item.name}">
            ${isOutOfStock ? `<div class="stock-status-overlay"><img src="outofstock1.png" alt="Out of stock"><span>Out of stock</span></div>` : ''}
            <div class="special-food-details">
                <h4>${item.name}</h4>
                <strong>${formatCurrency(item.price)}</strong>
            </div>
            <div class="special-food-cart-action">
                <button type="button" class="special-food-add" data-name="${item.name}" data-price="${item.price}" aria-label="Add ${item.name} to cart"${canAddMore ? '' : ' disabled'}>
                    <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                </button>
                <span class="special-food-added-message" aria-live="polite"></span>
            </div>
        </article>
    `;
    }).join('');

    syncVisibleMenuItemQuantities();
}

function addToCart(item) {
    if (!item) return;
    const availableStock = getAvailableStockForItem(item.name);
    if (availableStock <= 0) {
        if (menuOrderMessage) {
            menuOrderMessage.textContent = `${item.name} has reached the available stock limit.`;
        }
        return;
    }
    const existing = cartItems.find((cartItem) => cartItem.name === item.name);
    if (existing) {
        existing.quantity += 1;
    } else {
        cartItems.push({ ...item, quantity: 1 });
    }
    saveCart();
    updateCartDisplay();
    if (menuOrderMessage) {
        menuOrderMessage.textContent = `${item.name} added to cart.`;
    }
}

function clearSpecialFoodConfirmation(itemName) {
    if (!specialFoodsList) return;
    const specialFoodButton = Array.from(specialFoodsList.querySelectorAll('.special-food-add'))
        .find((button) => button.dataset.name === itemName);
    const message = specialFoodButton && specialFoodButton.parentElement
        ? specialFoodButton.parentElement.querySelector('.special-food-added-message')
        : null;
    if (message) {
        message.textContent = '';
    }
}

function removeCartItem(index) {
    if (index < 0 || index >= cartItems.length) return;
    const removedItem = cartItems[index];
    cartItems.splice(index, 1);
    clearSpecialFoodConfirmation(removedItem.name);
    saveCart();
    updateCartDisplay();
}

function adjustCartItemQuantity(index, change) {
    if (index < 0 || index >= cartItems.length || !change) return;
    const item = cartItems[index];
    const nextQuantity = item.quantity + change;
    if (nextQuantity < 1) return;
    item.quantity = nextQuantity;
    saveCart();
    updateCartDisplay();
}

function clearCart() {
    cartItems = [];
    saveCart();
    updateCartDisplay();
}

function renderPendingOrders() {
    if (!pendingOrdersList) return;
    if (!pendingOrders.length) {
        pendingOrdersList.innerHTML = '<p class="menu-cart-empty">There are no pending orders at the moment.</p>';
        return;
    }

    pendingOrdersList.innerHTML = pendingOrders.map((order, index) => {
        const items = Array.isArray(order.items) ? order.items : [];
        const itemsHtml = items.map((item) => {
            const maxAllowed = getMaxEditablePendingQuantity(order.id, item);
            const canIncrease = (Number(item.quantity) || 0) < maxAllowed;
            const canDecrease = (Number(item.quantity) || 0) > 1;

            return `
                <li>
                    <div class="pending-item-row">
                        <span>${item.name} — ${formatCurrency(item.price * item.quantity)}</span>
                        <div class="pending-item-qty-controls">
                            <button type="button" class="pending-item-qty-btn" data-action="decrease" data-order-index="${index}" data-item-id="${item.id}"${canDecrease ? '' : ' disabled'}>−</button>
                            <span>${item.quantity}</span>
                            <button type="button" class="pending-item-qty-btn" data-action="increase" data-order-index="${index}" data-item-id="${item.id}"${canIncrease ? '' : ' disabled'}>+</button>
                        </div>
                    </div>
                </li>
            `;
        }).join('');
        return `
            <article class="pending-order-card">
                <h4>Order #${order.orderNumber}</h4>
                <p><strong>Submitted:</strong> ${new Date(order.timestamp).toLocaleString()}</p>
                <p><strong>Payment:</strong> ${order.paymentMethod}</p>
                <ul>${itemsHtml}</ul>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                    <strong>Total: ${formatCurrency(order.total)}</strong>
                    <button type="button" class="order-complete-btn" data-order-index="${index}">Mark Complete</button>
                </div>
            </article>
        `;
    }).join('');
}

let selectedPaymentMethod = 'Cash';
let selectedOrderType = 'Dine In';

function openCheckoutScreen() {
    if (!cartItems.length) return;
    if (!orderCheckoutScreen || !menuCategoryScreen) return;

    menuCategoryScreen.classList.add('hidden');
    orderCheckoutScreen.classList.remove('hidden');
    orderCheckoutScreen.setAttribute('aria-hidden', 'false');
    renderCheckoutSummary();
}

function closeCheckoutScreen() {
    if (!orderCheckoutScreen || !menuCategoryScreen) return;

    orderCheckoutScreen.classList.add('hidden');
    orderCheckoutScreen.setAttribute('aria-hidden', 'true');
    menuCategoryScreen.classList.remove('hidden');
}

function renderCheckoutSummary() {
    if (!orderCheckoutItems || !orderCheckoutTotal) return;

    orderCheckoutItems.innerHTML = cartItems.map((item) => `
        <div class="order-checkout-item">
            <div>
                <strong>${item.name}</strong>
                <span>Qty: ${item.quantity}</span>
            </div>
            <div>${formatCurrency(item.price * item.quantity)}</div>
        </div>
    `).join('');

    orderCheckoutTotal.textContent = formatCurrency(cartItems.reduce((sum, item) => sum + item.price * item.quantity, 0));
}

function selectCheckoutOption(container, type, selectedValue) {
    if (!container) return;
    const buttons = Array.from(container.querySelectorAll('.checkout-option-btn'));
    buttons.forEach((button) => {
        const value = button.dataset[type];
        button.classList.toggle('active', value === selectedValue);
    });
}

function generateOrderNumber() {
    return Math.floor(Math.random() * 1000000).toString().padStart(6, '0');
}

function updateLiveClock() {
    if (!liveClock) return;
    const now = new Date();
    liveClock.textContent = now.toLocaleString([], {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
}

function openPaymentScreen(order) {
    if (!orderPaymentScreen) return;
    if (menuCategoryScreen) {
        menuCategoryScreen.classList.add('hidden');
    }
    orderCheckoutScreen.classList.add('hidden');
    orderPaymentScreen.classList.remove('hidden');
    orderPaymentScreen.setAttribute('aria-hidden', 'false');

    if (orderPaymentNumber) {
        orderPaymentNumber.textContent = order.orderNumber;
    }
    if (orderPaymentDatetime) {
        orderPaymentDatetime.textContent = new Date(order.timestamp).toLocaleString();
    }
    if (orderPaymentMethod) {
        orderPaymentMethod.textContent = order.paymentMethod;
    }

    if (selectedPaymentMethod === 'Cash') {
        if (orderPaymentMessage) {
            orderPaymentMessage.textContent = 'Cash payment selected. Your dishes will start to cook once they are already paid at the cashier.';
        }
        if (paymentQrPlaceholder) {
            paymentQrPlaceholder.classList.add('hidden');
        }
    } else {
        if (orderPaymentMessage) {
            orderPaymentMessage.textContent = 'Please pay via QR code to complete your order.';
        }
        if (paymentQrPlaceholder) {
            paymentQrPlaceholder.classList.remove('hidden');
        }
    }
}

function closePaymentScreen() {
    if (!orderPaymentScreen) return;
    orderPaymentScreen.classList.add('hidden');
    orderPaymentScreen.setAttribute('aria-hidden', 'true');
    orderCheckoutScreen.classList.remove('hidden');
    orderCheckoutScreen.setAttribute('aria-hidden', 'false');
}

function showPaymentSuccessMessage() {
    if (!paymentSuccessModal) return;
    paymentSuccessModal.classList.remove('hidden');
    paymentSuccessModal.setAttribute('aria-hidden', 'false');
}

function hidePaymentSuccessMessage() {
    if (!paymentSuccessModal) return;
    paymentSuccessModal.classList.add('hidden');
    paymentSuccessModal.setAttribute('aria-hidden', 'true');
}

async function confirmOrder() {
    if (!cartItems.length) return;
    const order = {
        orderNumber: generateOrderNumber(),
        id: Date.now(),
        timestamp: Date.now(),
        items: cartItems.map((item) => ({ name: item.name, price: item.price, quantity: item.quantity })),
        total: cartItems.reduce((sum, item) => sum + item.price * item.quantity, 0),
        paymentMethod: selectedPaymentMethod,
        orderType: selectedOrderType
    };

    const syncedOrder = await submitOrderToServer(order);
    if (!syncedOrder) {
        if (menuOrderMessage) {
            menuOrderMessage.textContent = 'Unable to submit order to server. Please try again.';
        }
        return;
    }

    pendingOrders.unshift(syncedOrder);
    registerCustomerOrder(syncedOrder.orderNumber);
    savePendingOrders();
    renderPendingOrders();
    renderOrderNotifications();
    renderOverviewInventory();
    if (menuOrderMessage) {
        menuOrderMessage.textContent = 'Order received! Proceed with payment to complete transaction.';
    }
    openPaymentScreen(syncedOrder);
}

function showMenuCategory(categoryId) {
    loadCustomMenuData();
    syncMenuPricesWithInventory();

    const category = menuData[categoryId];
    if (!category || !menuCategoryScreen || !menuItemsList || !menuCategoryTitle || !menuCategories) return;

    currentMenuCategoryId = categoryId;
    menuCategoryTitle.textContent = category.title;
    menuItemsList.innerHTML = category.items.map((item) => {
        const isOutOfStock = isItemOutOfStock(item.name);
        const currentQty = getCartQuantityForItem(item.name);
        const availableStock = getAvailableStockForItem(item.name);
        return `
        <article class="menu-item-card${isOutOfStock ? ' is-out-of-stock' : ''}">
            <div class="menu-item-main">
                <h4>${item.name}</h4>
                <p>${item.description}</p>
                <p class="menu-item-price">${item.price}</p>
            </div>
            ${isOutOfStock ? `<div class="stock-status-overlay"><img src="outofstock1.png" alt="Out of stock"><span>Out of stock</span></div>` : ''}
            <div class="menu-item-controls">
                <div class="menu-item-qty-controls">
                    <button type="button" class="menu-item-qty-btn" data-action="decrease" data-name="${item.name}" data-price="${parsePrice(item.price)}" aria-label="Decrease ${item.name} quantity"${currentQty <= 0 ? ' disabled' : ''}>−</button>
                    <span class="menu-item-qty">${currentQty}</span>
                    <button type="button" class="menu-item-qty-btn" data-action="increase" data-name="${item.name}" data-price="${parsePrice(item.price)}" aria-label="Increase ${item.name} quantity"${availableStock <= 0 ? ' disabled' : ''}>+</button>
                </div>
                <span class="menu-item-confirmation" aria-live="polite"></span>
            </div>
        </article>
    `;
    }).join('');

    menuCategories.hidden = true;
    menuCategoryScreen.classList.remove('hidden');
    menuCategoryScreen.setAttribute('aria-hidden', 'false');
    updateCartDisplay();
}

function showMenuCategories() {
    if (!menuCategories || !menuCategoryScreen) return;
    menuCategories.hidden = false;
    menuCategoryScreen.classList.add('hidden');
    menuCategoryScreen.setAttribute('aria-hidden', 'true');
}

function openMenuOverlay() {
    if (!menuOverlay) return;
    menuOverlay.classList.remove('hidden');
    menuOverlay.setAttribute('aria-hidden', 'false');
    showMenuCategories();
    updateCartDisplay();
}

function closeMenuOverlay() {
    if (!menuOverlay) return;
    showMenuCategories();
    menuOverlay.classList.add('hidden');
    menuOverlay.setAttribute('aria-hidden', 'true');
}

function openCartModal() {
    if (!cartModal) return;
    cartModal.classList.remove('hidden');
    cartModal.setAttribute('aria-hidden', 'false');
}

function closeCartModal() {
    if (!cartModal) return;
    cartModal.classList.add('hidden');
    cartModal.setAttribute('aria-hidden', 'true');
}

if (openMenuBtn) {
    openMenuBtn.addEventListener('click', () => {
        if (!menuCategories) return;
        menuCategories.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
}

if (menuNavLink) {
    menuNavLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (menuCategories) {
            menuCategories.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
}

if (closeMenuOverlayBtn) {
    closeMenuOverlayBtn.addEventListener('click', closeMenuOverlay);
}

let floatedCartButtonDragMoved = false;

if (menuCartButton) {
    menuCartButton.style.position = 'fixed';
    menuCartButton.style.right = '20px';
    menuCartButton.style.bottom = '20px';
    menuCartButton.style.left = 'auto';
    menuCartButton.style.top = 'auto';
    menuCartButton.style.width = '58px';
    menuCartButton.style.height = '58px';
    menuCartButton.style.borderRadius = '50%';
    menuCartButton.style.zIndex = '1600';
    menuCartButton.style.display = 'grid';
    menuCartButton.style.placeItems = 'center';
    menuCartButton.style.padding = '0';
    menuCartButton.style.cursor = 'grab';

    let dragOffsetX = 0;
    let dragOffsetY = 0;
    let isDragging = false;

    menuCartButton.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        const rect = menuCartButton.getBoundingClientRect();
        dragOffsetX = event.clientX - rect.left;
        dragOffsetY = event.clientY - rect.top;
        isDragging = true;
        floatedCartButtonDragMoved = false;
        menuCartButton.classList.add('is-dragging');
        menuCartButton.setPointerCapture(event.pointerId);
    });

    menuCartButton.addEventListener('pointermove', (event) => {
        if (!isDragging) return;

        const nextLeft = event.clientX - dragOffsetX;
        const nextTop = event.clientY - dragOffsetY;
        const maxLeft = Math.max(0, window.innerWidth - menuCartButton.offsetWidth - 8);
        const maxTop = Math.max(0, window.innerHeight - menuCartButton.offsetHeight - 8);
        const movedX = Math.abs(event.clientX - (menuCartButton.getBoundingClientRect().left + dragOffsetX));
        const movedY = Math.abs(event.clientY - (menuCartButton.getBoundingClientRect().top + dragOffsetY));

        if (movedX > 3 || movedY > 3) {
            floatedCartButtonDragMoved = true;
        }

        menuCartButton.style.left = `${Math.min(Math.max(0, nextLeft), maxLeft)}px`;
        menuCartButton.style.top = `${Math.min(Math.max(0, nextTop), maxTop)}px`;
        menuCartButton.style.right = 'auto';
        menuCartButton.style.bottom = 'auto';
    });

    const stopDragging = (event) => {
        if (!isDragging) return;
        isDragging = false;
        menuCartButton.classList.remove('is-dragging');
        if (event?.pointerId !== undefined) {
            menuCartButton.releasePointerCapture(event.pointerId);
        }
    };

    menuCartButton.addEventListener('pointerup', stopDragging);
    menuCartButton.addEventListener('pointercancel', stopDragging);

    menuCartButton.addEventListener('click', (event) => {
        if (floatedCartButtonDragMoved) {
            event.preventDefault();
            floatedCartButtonDragMoved = false;
            return;
        }
        openCartModal();
    });
}

if (closeCartButton) {
    closeCartButton.addEventListener('click', closeCartModal);
}

if (mobileMenuToggle && topNav) {
    mobileMenuToggle.addEventListener('click', () => {
        const isOpen = topNav.classList.toggle('open');
        mobileMenuToggle.setAttribute('aria-expanded', String(isOpen));
    });

    topNav.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            topNav.classList.remove('open');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        });
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 760) {
            topNav.classList.remove('open');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        }
    });
}

if (specialFoodsList) {
    specialFoodsList.addEventListener('click', (event) => {
        const button = event.target.closest('.special-food-add');
        if (!button) return;

        const card = button.closest('.special-food-card');
        if (card) {
            specialFoodsList.querySelectorAll('.special-food-card').forEach((foodCard) => {
                foodCard.classList.remove('is-active');
            });
            card.classList.add('is-active');
        }

        addToCart({
            name: button.dataset.name,
            price: Number(button.dataset.price)
        });
        const message = button.parentElement.querySelector('.special-food-added-message');
        if (message) {
            message.textContent = 'Added to cart';
        }
    });
}

if (inventoryForm) {
    inventoryForm.addEventListener('submit', saveInventoryItem);
}

if (inventoryItemsWrapper) {
    inventoryItemsWrapper.addEventListener('click', (event) => {
        const editButton = event.target.closest('.inventory-edit-btn');
        const saveButton = event.target.closest('.inventory-inline-save');
        const cancelButton = event.target.closest('.inventory-inline-cancel');
        const deleteButton = event.target.closest('.inventory-inline-delete');

        if (editButton) {
            const itemName = editButton.dataset.itemName;
            editInventoryItem(itemName);
            return;
        }

        if (saveButton) {
            const card = saveButton.closest('.inventory-item-card');
            if (card) {
                commitInlineInventoryEdit(card);
            }
            return;
        }

        if (cancelButton) {
            inventoryEditItemName = null;
            inventoryEditLock = false;
            renderInventoryManagement();
            startInventoryAutoRefresh();
            return;
        }

        if (deleteButton) {
            const itemName = deleteButton.dataset.itemName;
            deleteInventoryItem(itemName);
            return;
        }
    });
}

if (menuCategories) {
    menuCategories.addEventListener('click', (event) => {
        const button = event.target.closest('.menu-category-btn');
        if (!button) return;
        const categoryId = button.dataset.category;
        openMenuOverlay();
        showMenuCategory(categoryId);
    });
}

if (menuCategoryScreen) {
    menuCategoryScreen.addEventListener('click', (event) => {
        const qtyButton = event.target.closest('.menu-item-qty-btn');
        if (!qtyButton) return;

        const card = qtyButton.closest('.menu-item-card');
        const qtyElement = card?.querySelector('.menu-item-qty');
        const confirmation = card?.querySelector('.menu-item-confirmation');
        const name = qtyButton.dataset.name;
        const price = Number(qtyButton.dataset.price);
        const currentQty = Number(qtyElement?.textContent || 0);
        const change = qtyButton.dataset.action === 'increase' ? 1 : -1;

        if (change > 0) {
            addToCart({ name, price });
        } else if (currentQty > 0) {
            const cartIndex = cartItems.findIndex((item) => item.name === name);
            if (cartIndex >= 0) {
                const existing = cartItems[cartIndex];
                existing.quantity = Math.max(0, existing.quantity - 1);
                if (existing.quantity === 0) {
                    cartItems.splice(cartIndex, 1);
                }
                saveCart();
                updateCartDisplay();
            }
        }

        if (confirmation) {
            const updatedQty = getCartQuantityForItem(name);
            confirmation.textContent = updatedQty > 0 ? `${updatedQty} ${updatedQty === 1 ? 'order' : 'orders'} added` : '';
        }
        syncVisibleMenuItemQuantities();
    });
}

if (menuCartList) {
    menuCartList.addEventListener('click', (event) => {
        const quantityButton = event.target.closest('.menu-cart-item-quantity-btn');
        if (quantityButton) {
            const index = Number(quantityButton.dataset.index);
            const change = quantityButton.dataset.action === 'increase' ? 1 : -1;
            adjustCartItemQuantity(index, change);
            return;
        }
        const button = event.target.closest('.menu-cart-item-remove');
        if (!button) return;
        const index = Number(button.dataset.index);
        removeCartItem(index);
    });
}

if (menuPlaceOrderBtn) {
    menuPlaceOrderBtn.addEventListener('click', () => {
        closeCartModal();
        openMenuOverlay();
        openCheckoutScreen();
    });
}

if (orderCheckoutBackBtn) {
    orderCheckoutBackBtn.addEventListener('click', closeCheckoutScreen);
}

if (cancelOrderBtn) {
    cancelOrderBtn.addEventListener('click', closeCheckoutScreen);
}

if (confirmOrderBtn) {
    confirmOrderBtn.addEventListener('click', confirmOrder);
}

if (paymentConfirmationBackBtn) {
    paymentConfirmationBackBtn.addEventListener('click', closePaymentScreen);
}

if (orderPaymentCloseBtn) {
    orderPaymentCloseBtn.addEventListener('click', () => {
        if (orderPaymentScreen) {
            orderPaymentScreen.classList.add('hidden');
            orderPaymentScreen.setAttribute('aria-hidden', 'true');
        }
        if (menuOverlay) {
            menuOverlay.classList.add('hidden');
            menuOverlay.setAttribute('aria-hidden', 'true');
        }
        if (menuCategoryScreen) {
            menuCategoryScreen.classList.add('hidden');
        }
        if (orderCheckoutScreen) {
            orderCheckoutScreen.classList.add('hidden');
            orderCheckoutScreen.setAttribute('aria-hidden', 'true');
        }
        clearCart();
        showPaymentSuccessMessage();
    });
}

if (paymentSuccessCloseBtn) {
    paymentSuccessCloseBtn.addEventListener('click', hidePaymentSuccessMessage);
}

if (paymentMethodOptions) {
    paymentMethodOptions.addEventListener('click', (event) => {
        const button = event.target.closest('.checkout-option-btn');
        if (!button) return;
        selectedPaymentMethod = button.dataset.payment || 'Cash';
        selectCheckoutOption(paymentMethodOptions, 'payment', selectedPaymentMethod);
    });
}

if (orderTypeOptions) {
    orderTypeOptions.addEventListener('click', (event) => {
        const button = event.target.closest('.checkout-option-btn');
        if (!button) return;
        selectedOrderType = button.dataset.order || 'Dine In';
        selectCheckoutOption(orderTypeOptions, 'order', selectedOrderType);
    });
}

if (menuBackBtn) {
    menuBackBtn.addEventListener('click', closeMenuOverlay);
}

if (dashboardPanel) {
    dashboardPanel.addEventListener('click', (event) => {
        const link = event.target.closest('a');
        if (!link) return;

        const href = (link.getAttribute('href') || '').trim();

        event.preventDefault();

        if (href === '#overview') {
            showDashboardSection(overviewSection);
            renderOrderNotifications();
            renderOverviewAnalytics();
            renderOverviewInventory();
        } else if (href === '#inventory') {
            showDashboardSection(inventorySection);
            renderOverviewInventory();
        } else if (href === '#pending-orders') {
            showDashboardSection(pendingOrdersSection);
            void loadPendingOrdersFromServer();
            renderPendingOrders();
        } else if (href === '#sales') {
            showDashboardSection(salesSection);
            updateAnalyticsView();
        } else if (href === '#account-management') {
            const isAdmin = document.body.classList.contains('auth') && (selectedRoleInput && selectedRoleInput.value === 'Admin');
            if (!isAdmin) {
                return;
            }
            showDashboardSection(accountManagementSection);
        }

        setDashboardPanelState(false);
    });
}

if (overviewOrderNotificationList) {
    overviewOrderNotificationList.addEventListener('click', async (event) => {
        const button = event.target.closest('.order-complete-btn');
        if (!button) return;
        const orderId = button.dataset.orderId;
        const orderIndex = pendingOrders.findIndex((order) => order.id === Number(orderId));
        await markPendingOrderAsComplete(orderIndex, false);
    });
}

if (pendingOrdersList) {
    pendingOrdersList.addEventListener('click', async (event) => {
        const qtyButton = event.target.closest('.pending-item-qty-btn');
        if (qtyButton) {
            const action = qtyButton.dataset.action;
            const orderIndex = Number(qtyButton.dataset.orderIndex);
            const itemId = Number(qtyButton.dataset.itemId);
            await changePendingOrderItemQuantity(orderIndex, itemId, action);
            return;
        }

        const button = event.target.closest('.order-complete-btn');
        if (!button) return;
        const index = Number(button.dataset.orderIndex);
        await markPendingOrderAsComplete(index, true);
    });
}

function initOrders() {
    loadCart();
    loadPendingOrders();
    loadIgnoredPendingOrders();
    loadCompletedOrders();
    loadCustomMenuData();
    startInventoryAutoRefresh();
    void initializeInventoryData();
    recalculateSalesAnalytics();
    renderSpecialFoods();
    updateCartDisplay();
    renderPendingOrders();
    renderOrderNotifications();
    renderOverviewInventory();
    renderInventoryManagement();
    updateAnalyticsView();
    renderOverviewAnalytics();
    updateLiveClock();
    setInterval(updateLiveClock, 1000);
    void loadPendingOrdersFromServer();

    if (isCustomerPage) {
        initializeOrderNotificationAudio();
        loadCustomerOrderTracking();
        startCustomerInventoryRefresh();
        void initializeInventoryData(true);
        startCustomerOrderStatusPolling();
        void pollCustomerOrderStatus();
    }
}

document.addEventListener('visibilitychange', () => {
    if (isCustomerPage && !document.hidden) {
        void loadCustomMenuData();
        void initializeInventoryData(true);
    }

    if (!enableInventoryAutoRefresh) return;
    if (!document.hidden && !inventoryEditLock) {
        void initializeInventoryData();
    }
});

window.addEventListener('focus', () => {
    if (isCustomerPage) {
        void loadCustomMenuData();
        void initializeInventoryData(true);
    }

    if (!enableInventoryAutoRefresh) return;
    if (!inventoryEditLock) {
        void initializeInventoryData();
    }
});

initOrders();
restoreStaffSession();
