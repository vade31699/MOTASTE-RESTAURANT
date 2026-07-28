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
const lastLoginRoleStorageKey = 'motasteLastLoginRole';
let inventoryRefreshTimer = null;
let inventoryRefreshVersion = 0;
let inventorySyncInFlight = false;
let lastInventoryUpdateAt = 0;
let staffAccountsSyncInFlight = false;
let staffAccountsRefreshTimer = null;
let orderLogsRefreshTimer = null;
let orderLogsSyncInFlight = false;
let reviewRefreshTimer = null;
let orderActivityLogs = [];
let activeOrderLogFilter = 'all';
let pendingOrdersRefreshTimer = null;
const blockedProductNames = new Set(['softdrinks']);
const isStaffPage = Boolean(document.getElementById('accountList') || document.getElementById('staffLoginForm'));

const defaultStaffAccounts = [
    { name: 'Administrator', role: 'Admin', email: 'vadevidad31699@gmail.com', password: 'admin123', inviteConfirmed: true }
];

let accounts = [...defaultStaffAccounts];
window.motasteStaffAccounts = accounts;

function normalizeStaffAccount(account) {
    if (!account || typeof account !== 'object') return null;

    const name = (account.name || '').trim();
    const role = (account.role || '').trim();
    const email = (account.email || '').trim().toLowerCase();
    const password = (account.password || '').toString();
    const inviteConfirmed = account.role === 'Admin' ? true : Boolean(account.inviteConfirmed);

    if (!name || !role || !email || !password) return null;
    if (!allowedRoles.includes(role)) return null;

    return { name, role, email, password, inviteConfirmed };
}

function getCurrentStaffAccounts() {
    return Array.isArray(accounts) ? accounts : [];
}

function ensureAdminAccountInvariant() {
    const adminIndex = accounts.findIndex((account) => account.role === 'Admin');
    if (adminIndex >= 0) {
        accounts[adminIndex].email = (accounts[adminIndex].email || 'vadevidad31699@gmail.com').trim().toLowerCase();
        accounts[adminIndex].inviteConfirmed = true;
        return;
    }

    accounts.unshift({
        name: 'Administrator',
        role: 'Admin',
        email: 'vadevidad31699@gmail.com',
        password: 'admin123',
        inviteConfirmed: true
    });
}

function saveStaffAccountsToStorage() {
    try {
        ensureAdminAccountInvariant();
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
        ensureAdminAccountInvariant();
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

    const currentSignature = JSON.stringify(accounts);
    const nextSignature = JSON.stringify(normalized);
    if (currentSignature === nextSignature) return false;

    accounts = normalized;
    ensureAdminAccountInvariant();
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

        if (Array.isArray(payload.accounts)) {
            const changed = applyStaffAccountsSnapshot(payload.accounts);
            if (changed) {
                renderAccounts();
            }
            enforceActiveSessionValidity();
            return changed;
        }

        enforceActiveSessionValidity();
        return false;
    } catch (error) {
        console.error('Unable to load staff accounts from server', error);
        return false;
    } finally {
        staffAccountsSyncInFlight = false;
    }
}

function forceLogoutCurrentStaffSession() {
    clearStaffSession();
    if (selectedRoleInput) selectedRoleInput.value = '';
    if (emailInput) emailInput.value = '';
    if (passwordInput) passwordInput.value = '';
    if (rememberCheckbox) rememberCheckbox.checked = false;
    if (loginFields) loginFields.hidden = false;
    if (modalTitle) modalTitle.textContent = 'Staff Login';
    document.body.classList.remove('auth');
    resetDashboardProfile();
    setAuthButtonsVisible(false);
    updateAccountManagementAccess();
    setDashboardPanelState(false);

    const staffBox = document.querySelector('.staff-box');
    if (staffBox) {
        staffBox.style.display = '';
        staffBox.hidden = false;
    }
    if (staffLoginPage) {
        staffLoginPage.hidden = false;
    }

    if (overviewSection) overviewSection.hidden = true;
    if (salesSection) salesSection.hidden = true;
    if (inventorySection) inventorySection.hidden = true;
    if (pendingOrdersSection) pendingOrdersSection.hidden = true;
    if (logsSection) logsSection.hidden = true;
    if (accountManagementSection) accountManagementSection.hidden = true;
    if (highlightsSection) highlightsSection.hidden = true;
    if (credentialsSection) credentialsSection.hidden = true;
}

function enforceActiveSessionValidity() {
    if (!document.body.classList.contains('auth')) return;

    const role = selectedRoleInput ? selectedRoleInput.value.trim() : '';
    const email = emailInput ? emailInput.value.trim().toLowerCase() : '';
    const password = passwordInput ? passwordInput.value : '';
    if (!role || !email || !password) return;

    if (!isValidStaffLogin(role, email, password)) {
        forceLogoutCurrentStaffSession();
        if (typeof window !== 'undefined' && window.alert) {
            window.alert('Your account credentials changed or your account was removed. Please log in again.');
        }
    }
}

function saveStaffAccountsToServer() {
    saveStaffAccountsToStorage();
    window.motasteStaffAccounts = accounts;

    void withCsrfHeaders({
        'Content-Type': 'application/json'
    }).then((headers) => {
        return fetch(getApiUrl('api/save_staff_accounts.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify(accounts),
            cache: 'no-store'
        });
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

let csrfToken = '';

async function ensureCsrfToken() {
    if (csrfToken) return csrfToken;

    try {
        const response = await fetch(getApiUrl(`api/get_csrf_token.php?_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) return '';
        const payload = await response.json().catch(() => ({}));
        csrfToken = String(payload.csrfToken || '').trim();
        return csrfToken;
    } catch (error) {
        return '';
    }
}

async function withCsrfHeaders(headers = {}) {
    const token = await ensureCsrfToken();
    const merged = { ...headers };
    if (token) {
        merged['X-CSRF-TOKEN'] = token;
    }
    return merged;
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function isGmailAddress(email) {
    const normalized = (email || '').trim().toLowerCase();
    return /@gmail\.com$/.test(normalized);
}

async function notifyStaffSessionEvent(eventName, actorRole, actorEmail) {
    if (!eventName || !actorRole || !actorEmail) return;
    if (actorRole !== 'Cashier' && actorRole !== 'Inventory Manager') return;

    try {
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });

        await fetch(getApiUrl('api/notify_staff_session.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify({
                event: eventName,
                role: actorRole,
                email: actorEmail,
                occurredAt: new Date().toISOString(),
                userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : ''
            }),
            cache: 'no-store'
        });
    } catch (error) {
        console.error('Unable to send staff session email notification', error);
    }
}

async function sendStaffInviteEmail(account) {
    const headers = await withCsrfHeaders({
        'Content-Type': 'application/json'
    });

    const response = await fetch(getApiUrl('api/send_staff_invite.php'), {
        method: 'POST',
        headers,
        body: JSON.stringify({
            name: account.name,
            role: account.role,
            email: account.email
        }),
        cache: 'no-store'
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.success) {
        const detail = payload.details ? ` (${payload.details})` : '';
        throw new Error((payload.error || `Unable to send invite email (HTTP ${response.status})`) + detail);
    }

    return payload;
}

async function confirmStaffInviteCode(email, role, code) {
    const headers = await withCsrfHeaders({
        'Content-Type': 'application/json'
    });

    const response = await fetch(getApiUrl('api/confirm_staff_invite.php'), {
        method: 'POST',
        headers,
        body: JSON.stringify({ email, role, code }),
        cache: 'no-store'
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.success) {
        throw new Error(payload.error || `Invite confirmation failed (HTTP ${response.status})`);
    }

    if (Array.isArray(payload.accounts)) {
        applyStaffAccountsSnapshot(payload.accounts);
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
    const normalizedRole = role || localStorage.getItem(lastLoginRoleStorageKey) || 'default';
    if (!normalizedRole) return;
    const saved = getSavedLoginCredentials();
    saved[normalizedRole] = { email, password };
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

function loadSavedCredentialsForLastLogin() {
    const lastRole = localStorage.getItem(lastLoginRoleStorageKey);
    if (!lastRole) {
        if (emailInput) emailInput.value = '';
        if (passwordInput) passwordInput.value = '';
        if (rememberCheckbox) rememberCheckbox.checked = false;
        return;
    }

    loadSavedCredentialsForRole(lastRole);
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

    const targetSectionId = resolveAccessibleSection(getPersistedActiveSection());
    const targetSection = document.getElementById(targetSectionId);
    if (targetSection) {
        showDashboardSection(targetSection);
        if (targetSectionId === 'overview') {
            renderOverviewAnalytics();
            renderOrderNotifications();
            renderOverviewInventory();
        } else if (targetSectionId === 'pending-orders') {
            void loadPendingOrdersFromServer();
            setOrdersTab('walk-in');
            renderWalkInOrderBuilder();
            renderPendingOrders();
        } else if (targetSectionId === 'sales') {
            updateAnalyticsView();
        } else if (targetSectionId === 'inventory') {
            renderOverviewInventory();
        } else if (targetSectionId === 'logs') {
            void loadOrderLogsFromServer(true);
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
        const isConfirmed = account.role === 'Admin' ? true : Boolean(account.inviteConfirmed);
        return account.email.toLowerCase() === normalizedEmail
            && account.password === password
            && (!normalizedRole || account.role === normalizedRole)
            && isConfirmed;
    });
}

function findStaffAccountByCredentials(email, password) {
    const normalizedEmail = (email || '').trim().toLowerCase();
    const staffAccounts = getCurrentStaffAccounts();
    return staffAccounts.find((account) => account.email.toLowerCase() === normalizedEmail && account.password === password) || null;
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

function getCurrentStaffActor() {
    const role = (selectedRoleInput && selectedRoleInput.value) ? selectedRoleInput.value.trim() : 'Staff';
    const email = (emailInput && emailInput.value) ? emailInput.value.trim().toLowerCase() : '';
    return { role: role || 'Staff', email };
}

function getCurrentStaffRole() {
    return selectedRoleInput && selectedRoleInput.value ? selectedRoleInput.value.trim() : '';
}

function canAccessInventory() {
    const role = getCurrentStaffRole();
    return role === 'Admin' || role === 'Inventory Manager';
}

function canAccessLogs() {
    const role = getCurrentStaffRole();
    return role === 'Admin';
}

function canManageOrders() {
    const role = getCurrentStaffRole();
    return role === 'Admin' || role === 'Cashier';
}

function canManageAccounts() {
    return getCurrentStaffRole() === 'Admin';
}

function canManageHighlights() {
    return getCurrentStaffRole() === 'Admin';
}

function canAccessCredentials() {
    return getCurrentStaffRole() === 'Admin';
}

function resolveAccessibleSection(sectionId) {
    const requested = (sectionId || 'overview').trim();
    if (requested === 'inventory' && !canAccessInventory()) return 'overview';
    if (requested === 'logs' && !canAccessLogs()) return 'overview';
    if (requested === 'pending-orders' && !canManageOrders()) return 'overview';
    if (requested === 'account-management' && !canManageAccounts()) return 'overview';
    if (requested === 'highlights' && !canManageHighlights()) return 'overview';
    if (requested === 'credentials' && !canAccessCredentials()) return 'overview';
    return requested || 'overview';
}

async function logStaffActivity(action, summary, details = {}) {
    const actor = getCurrentStaffActor();

    try {
        await fetch(getApiUrl('api/add_activity_log.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action,
                actorRole: actor.role,
                actorEmail: actor.email,
                summary,
                details
            }),
            cache: 'no-store'
        });
    } catch (error) {
        console.error('Unable to log staff activity', error);
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
        loginFields.hidden = false;
    }
    if (selectedRoleInput) {
        selectedRoleInput.value = '';
    }
    roleButtons.forEach((button) => button.classList.remove('active'));
    if (modalTitle) {
        modalTitle.textContent = 'Staff Login';
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
    if (!loginFields) return;
    loginFields.hidden = false;
}

window.selectRole = selectRole;

roleButtons.forEach((button) => {
    button.addEventListener('click', () => selectRole(button.dataset.role));
});

function attachStaffLoginHandler() {
    if (!staffForm) return;

    staffForm.addEventListener('submit', async function (event) {
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
        const matchedAccount = findStaffAccountByCredentials(email, password);
        const detectedRole = matchedAccount ? matchedAccount.role : role;

        if (!matchedAccount || !allowedRoles.includes(detectedRole)) {
            setAuthButtonsVisible(false);
            if (modalTitle) {
                modalTitle.textContent = 'Invalid credentials';
            }
            return;
        }

        if (selectedRoleInput) {
            selectedRoleInput.value = detectedRole;
        }

        if ((detectedRole === 'Cashier' || detectedRole === 'Inventory Manager') && matchedAccount && !matchedAccount.inviteConfirmed) {
            const inviteCode = typeof window !== 'undefined' ? window.prompt('Enter the invite verification code sent to your Gmail:') : '';
            if (!inviteCode) {
                if (modalTitle) {
                    modalTitle.textContent = 'Invite confirmation required';
                }
                return;
            }

            try {
                await confirmStaffInviteCode(email, detectedRole, inviteCode);
            } catch (error) {
                if (modalTitle) {
                    modalTitle.textContent = error.message || 'Invite verification failed';
                }
                return;
            }
        }

        if (!isValidStaffLogin(detectedRole, email, password)) {
            setAuthButtonsVisible(false);
            if (modalTitle) {
                modalTitle.textContent = 'Invalid credentials';
            }
            return;
        }

        if (remember) {
            saveCredentialsForRole(detectedRole, email, password);
            localStorage.setItem(lastLoginRoleStorageKey, detectedRole);
        } else {
            clearSavedCredentialsForRole(detectedRole);
            localStorage.removeItem(lastLoginRoleStorageKey);
        }

        saveStaffSession(detectedRole, email, password, remember);

        if (modalTitle) {
            modalTitle.textContent = `Logged in as ${detectedRole}`;
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

        void notifyStaffSessionEvent('login', detectedRole, email.toLowerCase());
    });
}

document.addEventListener('DOMContentLoaded', attachStaffLoginHandler);

document.addEventListener('DOMContentLoaded', () => {
    if (loginFields) {
        loginFields.hidden = false;
    }
    loadSavedCredentialsForLastLogin();
});

if (logoutBtn) {
    logoutBtn.addEventListener('click', () => {
        const actorRole = selectedRoleInput ? selectedRoleInput.value.trim() : '';
        const actorEmail = emailInput ? emailInput.value.trim().toLowerCase() : '';
        void notifyStaffSessionEvent('logout', actorRole, actorEmail);

        if (selectedRoleInput) {
            selectedRoleInput.value = '';
        }
        roleButtons.forEach((btn) => btn.classList.remove('active'));
        resetDashboardProfile();
        if (loginFields) {
            loginFields.hidden = false;
        }
        if (modalTitle) {
            modalTitle.textContent = 'Staff Login';
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
        if (logsSection) {
            logsSection.hidden = true;
        }
        if (accountManagementSection) {
            accountManagementSection.hidden = true;
        }
        if (highlightsSection) {
            highlightsSection.hidden = true;
        }
        if (credentialsSection) {
            credentialsSection.hidden = true;
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
const highlightsLink = document.getElementById('highlightsLink');
const credentialsLink = document.getElementById('credentialsLink');
const logsLink = document.getElementById('logsLink');
const accountManagementSection = document.getElementById('account-management');
const highlightsSection = document.getElementById('highlights');
const credentialsSection = document.getElementById('credentials');
const accountForm = document.getElementById('accountForm');
const accountList = document.getElementById('accountList');
const accountNameInput = document.getElementById('accountName');
const accountRoleInput = document.getElementById('accountRole');
const accountEmailInput = document.getElementById('accountEmail');
const accountPasswordInput = document.getElementById('accountPassword');
const highlightsForm = document.getElementById('highlightsForm');
const highlightsImagesInput = document.getElementById('highlightsImagesInput');
const highlightsMessage = document.getElementById('highlightsMessage');
const highlightsList = document.getElementById('highlightsList');
const highlightsStorageKey = 'motasteHighlightsSlides';
const highlightsMaxImages = 15;
const credentialsForm = document.getElementById('credentialsForm');
const adminCurrentEmailInput = document.getElementById('adminCurrentEmail');
const adminCurrentPasswordInput = document.getElementById('adminCurrentPassword');
const adminNewEmailInput = document.getElementById('adminNewEmail');
const adminNewPasswordInput = document.getElementById('adminNewPassword');
const adminChangeCodeInput = document.getElementById('adminChangeCode');
const requestCredentialsChangeBtn = document.getElementById('requestCredentialsChangeBtn');
const credentialsMessage = document.getElementById('credentialsMessage');
let accountEditIndex = null;

function renderAccounts() {
    if (!accountList) return;

    accountList.innerHTML = '';

    const managedAccounts = accounts
        .map((account, index) => ({ ...account, _index: index }))
        .filter((account) => account.role !== 'Admin');

    managedAccounts.forEach((account) => {
        const item = document.createElement('li');
        const inviteLabel = account.inviteConfirmed ? 'Confirmed' : 'Pending Email Confirmation';
        item.innerHTML = `
            <span>${account.name} — ${account.role} — ${account.email} — ${inviteLabel}</span>
            <div>
                <button type="button" class="edit-btn" data-index="${account._index}">Edit</button>
                <button type="button" class="delete-btn" data-index="${account._index}">Delete</button>
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

function setCredentialsMessage(message, isError = false) {
    if (!credentialsMessage) return;
    credentialsMessage.textContent = message || '';
    credentialsMessage.style.color = isError ? '#b00020' : '#0b6b2f';
}

async function loadAdminCredentials() {
    if (!adminCurrentEmailInput) return;
    try {
        const response = await fetch(getApiUrl(`api/get_admin_credentials.php?_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) return;
        const payload = await response.json();
        if (!payload || payload.success !== true || !payload.credentials) return;
        adminCurrentEmailInput.value = payload.credentials.email || '';
    } catch (error) {
        console.error('Unable to load admin credentials', error);
    }
}

async function requestAdminCredentialsChange() {
    if (!adminCurrentEmailInput || !adminCurrentPasswordInput || !adminNewEmailInput || !adminNewPasswordInput) return;

    const currentEmail = adminCurrentEmailInput.value.trim().toLowerCase();
    const currentPassword = adminCurrentPasswordInput.value;
    const nextEmail = adminNewEmailInput.value.trim().toLowerCase();
    const nextPassword = adminNewPasswordInput.value;

    if (!currentEmail || !currentPassword || !nextEmail || !nextPassword) {
        setCredentialsMessage('Please complete all credentials fields.', true);
        return;
    }
    if (!isGmailAddress(nextEmail)) {
        setCredentialsMessage('Admin email must be a Gmail address.', true);
        return;
    }

    try {
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });

        const response = await fetch(getApiUrl('api/request_admin_credentials_change.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify({
                currentEmail,
                currentPassword,
                newEmail: nextEmail,
                newPassword: nextPassword
            }),
            cache: 'no-store'
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            const detail = payload.details ? ` (${payload.details})` : '';
            throw new Error((payload.error || `Unable to request verification code (HTTP ${response.status})`) + detail);
        }

        if (payload.delivered === false) {
            throw new Error(payload.error || 'Email was not delivered. Check Laravel SMTP settings and try again.');
        }

        setCredentialsMessage('Verification code sent to current admin email. Enter code to apply changes.');
    } catch (error) {
        setCredentialsMessage(error.message || 'Unable to send verification code.', true);
    }
}

async function confirmAdminCredentialsChange(event) {
    if (event && event.preventDefault) event.preventDefault();
    if (!adminCurrentEmailInput || !adminCurrentPasswordInput || !adminChangeCodeInput) return;

    const currentEmail = adminCurrentEmailInput.value.trim().toLowerCase();
    const currentPassword = adminCurrentPasswordInput.value;
    const code = adminChangeCodeInput.value.trim();
    if (!currentEmail || !currentPassword || !code) {
        setCredentialsMessage('Current email, current password, and verification code are required.', true);
        return;
    }

    try {
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });

        const response = await fetch(getApiUrl('api/confirm_admin_credentials_change.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify({ currentEmail, currentPassword, code }),
            cache: 'no-store'
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || `Unable to apply credentials change (HTTP ${response.status})`);
        }

        if (Array.isArray(payload.accounts)) {
            applyStaffAccountsSnapshot(payload.accounts);
            renderAccounts();
        }

        if (selectedRoleInput && selectedRoleInput.value === 'Admin') {
            const nextAdmin = accounts.find((account) => account.role === 'Admin');
            if (nextAdmin) {
                if (emailInput) emailInput.value = nextAdmin.email;
                if (passwordInput) passwordInput.value = nextAdmin.password;
                saveStaffSession('Admin', nextAdmin.email, nextAdmin.password, rememberCheckbox ? rememberCheckbox.checked : false);
                if (rememberCheckbox && rememberCheckbox.checked) {
                    saveCredentialsForRole('Admin', nextAdmin.email, nextAdmin.password);
                }
                updateDashboardProfile();
            }
        }

        await loadAdminCredentials();

        if (credentialsForm) {
            credentialsForm.reset();
        }
        if (adminCurrentEmailInput) {
            const admin = accounts.find((account) => account.role === 'Admin');
            adminCurrentEmailInput.value = admin ? admin.email : currentEmail;
        }
        setCredentialsMessage('Admin credentials updated successfully.');
    } catch (error) {
        setCredentialsMessage(error.message || 'Unable to verify code.', true);
    }
}

if (accountManagementLink && accountManagementSection) {
    accountManagementLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (!canManageAccounts()) {
            return;
        }
        const showAccountManagement = accountManagementSection.hidden;
        accountManagementSection.hidden = !accountManagementSection.hidden;

        if (showAccountManagement && salesSection) {
            salesSection.hidden = true;
        }
    });
}

if (highlightsLink && highlightsSection) {
    highlightsLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (!canManageHighlights()) {
            return;
        }
        showDashboardSection(highlightsSection);
        renderHighlightsManagement();
    });
}

if (credentialsLink && credentialsSection) {
    credentialsLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (!canAccessCredentials()) {
            return;
        }
        showDashboardSection(credentialsSection);
        void loadAdminCredentials();
    });
}

function updateAccountManagementAccess() {
    const setLinkState = (link, isAllowed) => {
        if (!link) return;
        link.classList.toggle('disabled', !isAllowed);
        link.setAttribute('aria-disabled', String(!isAllowed));
    };

    setLinkState(ordersLink, canManageOrders());
    setLinkState(inventoryLink, canAccessInventory());
    setLinkState(logsLink, canAccessLogs());
    setLinkState(accountManagementLink, canManageAccounts());
    setLinkState(highlightsLink, canManageHighlights());
    setLinkState(credentialsLink, canAccessCredentials());

    if (!document.body.classList.contains('auth')) {
        return;
    }

    const activeSection = [overviewSection, salesSection, pendingOrdersSection, inventorySection, logsSection, accountManagementSection, highlightsSection, credentialsSection]
        .find((section) => section && section.hidden === false);
    if (!activeSection) return;

    const allowedSectionId = resolveAccessibleSection(activeSection.id);
    if (allowedSectionId !== activeSection.id) {
        showDashboardSection(document.getElementById(allowedSectionId) || overviewSection);
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

function getCurrentMonthKey() {
    return monthKeys[new Date().getMonth()] || 'jan';
}

function syncAnalyticsMonthSelectorsToCurrentMonth() {
    const monthKey = getCurrentMonthKey();
    if (analyticsMonthSelect) {
        analyticsMonthSelect.value = monthKey;
    }
    if (overviewMonthSelect) {
        overviewMonthSelect.value = monthKey;
    }
}

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
            value: 0,
            orders: 0
        }));
        weeklySalesByMonth[monthKey] = Array.from({ length: 5 }, (_, index) => ({
            label: `W${index + 1}`,
            value: 0,
            orders: 0
        }));
    });
}

function recalculateSalesAnalytics() {
    initializeAnalyticsBuckets();

    analyticsData.monthly.items = monthKeys.map((monthKey, index) => ({
        label: monthLabels[index],
        value: 0,
        orders: 0,
        display: `₱0`
    }));

    completedOrders.forEach((order) => {
        const orderDate = new Date(order.timestamp);
        const monthIndex = orderDate.getMonth();
        const monthKey = monthKeys[monthIndex];
        const dayIndex = Math.min(29, Math.max(0, orderDate.getDate() - 1));
        const weekIndex = Math.min(4, Math.floor(dayIndex / 7));
        const orderTotal = Number(order.total || 0);
        const completedProducts = (Array.isArray(order.items) ? order.items : []).reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);

        if (monthKey && monthlySalesByMonth[monthKey]) {
            monthlySalesByMonth[monthKey][dayIndex].value += orderTotal;
            monthlySalesByMonth[monthKey][dayIndex].orders += completedProducts;
            weeklySalesByMonth[monthKey][weekIndex].value += orderTotal;
            weeklySalesByMonth[monthKey][weekIndex].orders += completedProducts;
            analyticsData.monthly.items[monthIndex].value += orderTotal;
            analyticsData.monthly.items[monthIndex].orders += completedProducts;
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

    const maxValue = Math.max(...chartData.map((item) => Number(item.value) || 0));
    const paddedMax = Math.max(1000, Math.ceil(maxValue / 1000) * 1000);
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
        const normalizedValue = Number(item.value) || 0;
        const y = margin.top + chartHeight - (normalizedValue / paddedMax) * chartHeight;
        return { x, y, label: item.label, value: normalizedValue, display: item.display || formatChartValue(normalizedValue) };
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
                    <th>Order completes</th>
                </tr>
            </thead>
            <tbody>
                ${items.map((item) => `
                    <tr>
                        <td>${item.label}</td>
                        <td>${formatCurrency(item.value || 0)}</td>
                        <td>${Number(item.orders || 0)}</td>
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
        autoScrollChartToCurrentDay(analyticsChart, month, monthData.length);
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

function autoScrollChartToCurrentDay(chartContainer, monthKey, pointCount) {
    if (!chartContainer || !monthKey || monthKey !== getCurrentMonthKey()) return;
    const wrapper = chartContainer.closest('.sales-analytics-chart-wrapper');
    if (!wrapper) return;

    const totalPoints = Math.max(1, Number(pointCount) || 30);
    const currentDayIndex = Math.max(0, Math.min(totalPoints - 1, new Date().getDate() - 1));

    const applyScroll = () => {
        const maxScroll = Math.max(0, wrapper.scrollWidth - wrapper.clientWidth);
        if (maxScroll <= 0) return;

        const ratio = totalPoints > 1 ? currentDayIndex / (totalPoints - 1) : 0;
        const target = Math.max(0, Math.min(maxScroll, Math.round(maxScroll * ratio)));
        wrapper.scrollLeft = target;
    };

    applyScroll();
    requestAnimationFrame(applyScroll);
    setTimeout(applyScroll, 0);
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
const inventoryDescriptionInput = document.getElementById('inventoryDescriptionInput');
const inventoryPriceInput = document.getElementById('inventoryPriceInput');
const inventoryStockInput = document.getElementById('inventoryStockInput');
const inventoryStatusInput = document.getElementById('inventoryStatusInput');
const specialFoodImageField = document.getElementById('specialFoodImageField');
const specialFoodImageInput = document.getElementById('specialFoodImageInput');
const specialFoodImagePreviewWrap = document.getElementById('specialFoodImagePreviewWrap');
const specialFoodImagePreview = document.getElementById('specialFoodImagePreview');
const specialCustomizeField = document.getElementById('specialCustomizeField');
const specialCustomizeItemSelect = document.getElementById('specialCustomizeItemSelect');
const specialCustomizeQtyInput = document.getElementById('specialCustomizeQtyInput');
const specialCustomizeAddBtn = document.getElementById('specialCustomizeAddBtn');
const specialCustomizeList = document.getElementById('specialCustomizeList');
const productDetailModal = document.getElementById('productDetailModal');
const productDetailCloseBtn = document.getElementById('productDetailCloseBtn');
const productDetailImage = document.getElementById('productDetailImage');
const productDetailName = document.getElementById('productDetailName');
const productDetailDescription = document.getElementById('productDetailDescription');
const productDetailPrice = document.getElementById('productDetailPrice');
const productDetailQtyControls = document.getElementById('productDetailQtyControls');
const productDetailQtyDecrease = document.getElementById('productDetailQtyDecrease');
const productDetailQtyIncrease = document.getElementById('productDetailQtyIncrease');
const productDetailQtyValue = document.getElementById('productDetailQtyValue');
const productDetailAddBtn = document.getElementById('productDetailAddBtn');
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
const walkInOrdersTabBtn = document.getElementById('walkInOrdersTabBtn');
const pendingOrdersTabBtn = document.getElementById('pendingOrdersTabBtn');
const walkInOrderPanel = document.getElementById('walkInOrderPanel');
const pendingOrdersPanel = document.getElementById('pendingOrdersPanel');
const walkInItemSelect = document.getElementById('walkInItemSelect');
const walkInItemQtyInput = document.getElementById('walkInItemQtyInput');
const walkInAddItemBtn = document.getElementById('walkInAddItemBtn');
const walkInDraftList = document.getElementById('walkInDraftList');
const walkInPaymentMethodSelect = document.getElementById('walkInPaymentMethodSelect');
const walkInOrderTypeSelect = document.getElementById('walkInOrderTypeSelect');
const walkInPlaceOrderBtn = document.getElementById('walkInPlaceOrderBtn');
const walkInOrderMessage = document.getElementById('walkInOrderMessage');
const logsSection = document.getElementById('logs');
const logsFilterBar = document.getElementById('logsFilterBar');
const logsList = document.getElementById('logsList');
const staffReviewList = document.getElementById('staffReviewList');
const customerReviewForm = document.getElementById('customerReviewForm');
const reviewRatingInput = document.getElementById('reviewRating');
const reviewMessageInput = document.getElementById('reviewMessage');
const reviewSubmitMessage = document.getElementById('reviewSubmitMessage');
const customerReviewsList = document.getElementById('customerReviewsList');

const logsFilterLabelMap = {
    all: 'All',
    today: 'Today',
    qty: 'Qty Changes',
    completed: 'Completed',
    stock: 'Stock Only',
    inventory: 'Inventory',
    accounts: 'Accounts',
    reviews: 'Reviews'
};

let selectedSpecialFoodImageData = '';
let cachedReviews = [];
let cachedStaffReviews = [];
let activeProductDetailItem = null;
let productDetailQuantity = 1;
let selectedSpecialComponents = [];
const reviewerTokenStorageKey = 'motasteReviewerToken';

function isAddOnCategory(category) {
    const normalized = String(category || '').trim().toLowerCase().replace(/[^a-z]/g, '');
    return normalized === 'addons'
        || normalized === 'addon'
        || normalized === 'addonitem'
        || normalized === 'addonitems'
        || normalized === 'customize'
        || normalized === 'customization'
        || normalized === 'component'
        || normalized === 'components';
}

function normalizeSpecialComponents(components) {
    if (!Array.isArray(components)) return [];

    return components
        .map((entry) => ({
            name: (entry && entry.name ? String(entry.name) : '').trim(),
            quantity: Math.max(1, Number(entry && entry.quantity ? entry.quantity : 1) || 1)
        }))
        .filter((entry) => entry.name !== '');
}

function normalizeCartComponents(components) {
    if (!Array.isArray(components)) return [];

    return components
        .map((entry) => ({
            name: (entry && entry.name ? String(entry.name) : '').trim(),
            quantity: Math.max(0, Number(entry && entry.quantity ? entry.quantity : 0) || 0)
        }))
        .filter((entry) => entry.name !== '');
}

function getSpecialFoodComponentsByName(itemName) {
    const targetName = normalizeInventoryName(itemName);
    if (!targetName) return [];

    const specialItem = specialFoods.find((food) => normalizeInventoryName(food.name) === targetName);
    if (!specialItem) return [];

    return normalizeSpecialComponents(specialItem.components);
}

function getComponentQuantityLookup(components) {
    const lookup = {};
    normalizeCartComponents(components).forEach((component) => {
        const normalizedName = normalizeInventoryName(component.name);
        if (!normalizedName) return;
        lookup[normalizedName] = (lookup[normalizedName] || 0) + Math.max(0, Number(component.quantity) || 0);
    });
    return lookup;
}

function buildInitialCartComponents(baseComponents, dishQuantity) {
    const qty = Math.max(0, Number(dishQuantity) || 0);
    return normalizeSpecialComponents(baseComponents).map((component) => ({
        name: component.name,
        quantity: Math.max(0, Number(component.quantity) || 0) * qty
    }));
}

function applyBaseComponentsDeltaToCartItem(cartItem, dishQuantityDelta) {
    const delta = Number(dishQuantityDelta) || 0;
    if (!delta) return;

    const baseComponents = getCartItemBaseComponents(cartItem);
    if (!baseComponents.length) return;

    if (!Array.isArray(cartItem.components)) {
        cartItem.components = [];
    }

    baseComponents.forEach((baseComponent) => {
        const normalizedName = normalizeInventoryName(baseComponent.name);
        if (!normalizedName) return;

        const perDishQty = Math.max(0, Number(baseComponent.quantity) || 0);
        if (perDishQty <= 0) return;

        const changeBy = perDishQty * delta;
        let component = cartItem.components.find((entry) => normalizeInventoryName(entry.name) === normalizedName);

        if (!component) {
            component = { name: baseComponent.name, quantity: 0 };
            cartItem.components.push(component);
        }

        component.quantity = Math.max(0, (Number(component.quantity) || 0) + changeBy);
    });

    cartItem.components = normalizeCartComponents(cartItem.components);
}

function getCartItemBaseComponents(item) {
    const fromCart = normalizeSpecialComponents(item && item.baseComponents);
    if (fromCart.length) return fromCart;

    const fromSpecialRecipe = getSpecialFoodComponentsByName(item && item.name);
    if (fromSpecialRecipe.length) return fromSpecialRecipe;

    return normalizeSpecialComponents(item && item.components);
}

function getInventoryUnitPrice(itemName) {
    const inventoryItem = getInventoryItem(itemName);
    return Math.max(0, Number(inventoryItem && inventoryItem.price ? inventoryItem.price : 0) || 0);
}

function getCartItemUnitPrice(item) {
    if (!item) return 0;

    const dishQuantity = Math.max(0, Number(item.quantity) || 0);
    if (dishQuantity <= 0) return 0;

    return getCartItemLineTotal(item) / dishQuantity;
}

function getCartItemLineTotal(item) {
    if (!item) return 0;

    const dishQuantity = Math.max(0, Number(item.quantity) || 0);
    const basePrice = Math.max(0, Number(item.price) || 0);
    const baseLineTotal = basePrice * dishQuantity;
    if (dishQuantity <= 0) return 0;

    const baseComponents = getCartItemBaseComponents(item);
    if (baseComponents.length) {
        const currentComponents = normalizeCartComponents(item.components);
        const totalCurrentComponentQty = currentComponents.reduce((sum, component) => sum + Math.max(0, Number(component.quantity) || 0), 0);
        if (totalCurrentComponentQty <= 0) {
            return 0;
        }
    }

    const currentLookup = getComponentQuantityLookup(item.components);
    const baseLookup = getComponentQuantityLookup(baseComponents);
    const allComponentNames = new Set([...Object.keys(baseLookup), ...Object.keys(currentLookup)]);

    let componentLineDelta = 0;
    allComponentNames.forEach((componentName) => {
        const baseQtyPerDish = baseLookup[componentName] || 0;
        const expectedBaseTotalQty = baseQtyPerDish * dishQuantity;
        const currentQty = currentLookup[componentName] || 0;
        const quantityDelta = currentQty - expectedBaseTotalQty;
        if (!quantityDelta) return;

        const unitPrice = getInventoryUnitPrice(componentName);
        if (!unitPrice) return;

        componentLineDelta += quantityDelta * unitPrice;
    });

    return Math.max(0, baseLineTotal + componentLineDelta);
}

function getPayableCartItems() {
    return cartItems.filter((item) => getCartItemLineTotal(item) > 0);
}

function getCartPayableTotal() {
    return getPayableCartItems().reduce((sum, item) => sum + getCartItemLineTotal(item), 0);
}

function getOrderComponents(components) {
    return normalizeCartComponents(components)
        .filter((component) => Math.max(0, Number(component.quantity) || 0) > 0)
        .map((component) => ({
            name: component.name,
            quantity: Math.max(0, Number(component.quantity) || 0)
        }));
}

function getAddOnInventoryItems() {
    const byName = new Map();
    const componentNames = new Set();
    const coreMenuItemNames = new Set();
    const specialFoodNames = new Set();

    ['batchoy', 'silog', 'friedChicken', 'breakfast', 'drinks'].forEach((categoryKey) => {
        const items = Array.isArray(menuData?.[categoryKey]?.items) ? menuData[categoryKey].items : [];
        items.forEach((item) => {
            const normalizedName = normalizeInventoryName(item && item.name ? item.name : '');
            if (normalizedName) {
                coreMenuItemNames.add(normalizedName);
            }
        });
    });

    specialFoods.forEach((food) => {
        const normalizedFoodName = normalizeInventoryName(food && food.name ? food.name : '');
        if (normalizedFoodName) {
            specialFoodNames.add(normalizedFoodName);
        }

        normalizeSpecialComponents(food && food.components).forEach((component) => {
            const normalizedName = normalizeInventoryName(component.name);
            if (normalizedName) {
                componentNames.add(normalizedName);
            }
        });
    });

    (inventoryData || []).forEach((item) => {
        if (!item) return;

        const name = String(item.name || '').trim();
        if (!name) return;

        const normalizedName = normalizeInventoryName(name);
        if (!normalizedName) return;

        const addOnByCategory = isAddOnCategory(item.category);
        const addOnBySpecialComponent = componentNames.has(normalizedName);
        if (!addOnByCategory && !addOnBySpecialComponent) return;

        byName.set(normalizedName, {
            ...item,
            name,
            price: Math.max(0, Number(item.price) || 0),
            stock: Math.max(0, Number(item.stock) || 0),
            category: 'addons'
        });
    });

    const fallbackAddOnMenuItems = Array.isArray(menuData?.addons?.items) ? menuData.addons.items : [];
    fallbackAddOnMenuItems.forEach((item) => {
        const name = String(item && item.name ? item.name : '').trim();
        if (!name) return;

        const normalizedName = normalizeInventoryName(name);
        if (!normalizedName || byName.has(normalizedName)) return;

        byName.set(normalizedName, {
            name,
            price: Math.max(0, Number(item && item.price ? item.price : 0) || 0),
            stock: 0,
            status: 'Out of stock',
            category: 'addons'
        });
    });

    // Fail-safe: if add-on category metadata is missing, infer add-ons from non-main inventory items.
    if (byName.size === 0) {
        (inventoryData || []).forEach((item) => {
            if (!item) return;

            const name = String(item.name || '').trim();
            if (!name) return;

            const normalizedName = normalizeInventoryName(name);
            if (!normalizedName) return;
            if (blockedProductNames.has(normalizedName)) return;
            if (coreMenuItemNames.has(normalizedName)) return;
            if (specialFoodNames.has(normalizedName)) return;

            byName.set(normalizedName, {
                ...item,
                name,
                price: Math.max(0, Number(item.price) || 0),
                stock: Math.max(0, Number(item.stock) || 0),
                category: 'addons'
            });
        });
    }

    return [...byName.values()];
}

function getCartItemComponentQuantity(item, componentName) {
    const normalizedName = normalizeInventoryName(componentName);
    if (!normalizedName || !item || !Array.isArray(item.components)) return 0;

    const component = item.components.find((entry) => normalizeInventoryName(entry.name) === normalizedName);
    return Math.max(0, Number(component && component.quantity ? component.quantity : 0) || 0);
}

function setCartItemComponentQuantity(item, componentName, quantity) {
    if (!item) return;

    const normalizedName = normalizeInventoryName(componentName);
    if (!normalizedName) return;

    if (!Array.isArray(item.components)) {
        item.components = [];
    }

    const nextQuantity = Math.max(0, Number(quantity) || 0);
    const existing = item.components.find((entry) => normalizeInventoryName(entry.name) === normalizedName);
    if (existing) {
        existing.quantity = nextQuantity;
        return;
    }

    item.components.push({
        name: String(componentName).trim(),
        quantity: nextQuantity
    });
}

function getCartItemCustomizeOptions(item) {
    const baseComponents = getCartItemBaseComponents(item);
    const currentComponents = normalizeCartComponents(item && item.components);
    if (!baseComponents.length && !currentComponents.length) return [];

    const optionsByNormalizedName = new Map();

    baseComponents.forEach((component) => {
        const name = String(component.name || '').trim();
        const normalizedName = normalizeInventoryName(name);
        if (!normalizedName) return;
        if (!optionsByNormalizedName.has(normalizedName)) {
            optionsByNormalizedName.set(normalizedName, name);
        }
    });

    currentComponents.forEach((component) => {
        const name = String(component.name || '').trim();
        const normalizedName = normalizeInventoryName(name);
        if (!normalizedName) return;
        if (!optionsByNormalizedName.has(normalizedName)) {
            optionsByNormalizedName.set(normalizedName, name);
        }
    });

    return [...optionsByNormalizedName.values()].sort((a, b) => a.localeCompare(b));
}

function renderSpecialCustomizeControls() {
    if (!specialCustomizeField || !specialCustomizeItemSelect || !specialCustomizeList) return;

    const addOnItems = getAddOnInventoryItems().sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
    const currentValue = specialCustomizeItemSelect.value;

    if (!addOnItems.length) {
        specialCustomizeItemSelect.innerHTML = '<option value="">No ADD ON items in inventory</option>';
        specialCustomizeItemSelect.disabled = true;
    } else {
        specialCustomizeItemSelect.disabled = false;
        specialCustomizeItemSelect.innerHTML = addOnItems.map((item) => {
            const stock = Math.max(0, Number(item.stock) || 0);
            return `<option value="${item.name}">${item.name} (stock ${stock})</option>`;
        }).join('');

        const stillExists = addOnItems.some((item) => item.name === currentValue);
        specialCustomizeItemSelect.value = stillExists ? currentValue : addOnItems[0].name;
    }

    if (!selectedSpecialComponents.length) {
        specialCustomizeList.innerHTML = '<p class="menu-cart-empty">No components added yet.</p>';
        return;
    }

    specialCustomizeList.innerHTML = selectedSpecialComponents.map((entry, index) => `
        <div class="special-customize-item">
            <span>${escapeHtml(entry.name)} x${entry.quantity}</span>
            <button type="button" class="special-customize-remove-btn" data-index="${index}">Remove</button>
        </div>
    `).join('');
}

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
        selectedSpecialFoodImageData = '';
        selectedSpecialComponents = [];
        updateSpecialFoodImageFieldVisibility();
    }
}

setInventoryModalVisible(false);

if (inventoryCategoryInput) {
    inventoryCategoryInput.addEventListener('change', updateSpecialFoodImageFieldVisibility);
}

if (specialFoodImageInput) {
    specialFoodImageInput.addEventListener('change', async (event) => {
        const target = event.target;
        const file = target && target.files && target.files[0] ? target.files[0] : null;
        if (!file) {
            selectedSpecialFoodImageData = '';
            setSpecialFoodImagePreview('');
            return;
        }

        try {
            const dataUrl = await resizeImageToSquareDataUrl(file);
            selectedSpecialFoodImageData = dataUrl;
            setSpecialFoodImagePreview(dataUrl);
        } catch (error) {
            selectedSpecialFoodImageData = '';
            setSpecialFoodImagePreview('');
            if (typeof window !== 'undefined' && window.alert) {
                window.alert('Unable to process the selected image. Please choose another image.');
            }
        }
    });
}

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
    selectedSpecialFoodImageData = '';
    selectedSpecialComponents = [];
    updateSpecialFoodImageFieldVisibility();
}

function setSpecialFoodImagePreview(dataUrl) {
    if (!specialFoodImagePreviewWrap || !specialFoodImagePreview) return;

    if (!dataUrl) {
        specialFoodImagePreviewWrap.hidden = true;
        specialFoodImagePreview.removeAttribute('src');
        return;
    }

    specialFoodImagePreview.src = dataUrl;
    specialFoodImagePreviewWrap.hidden = false;
}

function updateSpecialFoodImageFieldVisibility() {
    if (!specialFoodImageField || !inventoryCategoryInput) return;

    const isSpecials = inventoryCategoryInput.value === 'specials';
    specialFoodImageField.hidden = !isSpecials;
    if (specialCustomizeField) {
        specialCustomizeField.hidden = !isSpecials;
    }

    if (isSpecials) {
        renderSpecialCustomizeControls();
    }

    if (!isSpecials) {
        selectedSpecialFoodImageData = '';
        selectedSpecialComponents = [];
        if (specialFoodImageInput) {
            specialFoodImageInput.value = '';
        }
        setSpecialFoodImagePreview('');
    }
}

if (specialCustomizeAddBtn) {
    specialCustomizeAddBtn.addEventListener('click', () => {
        if (!specialCustomizeItemSelect || specialCustomizeItemSelect.disabled) return;

        const name = String(specialCustomizeItemSelect.value || '').trim();
        const quantity = Math.max(1, Number(specialCustomizeQtyInput ? specialCustomizeQtyInput.value : 1) || 1);
        if (!name) return;

        const existingIndex = selectedSpecialComponents.findIndex((entry) => normalizeInventoryName(entry.name) === normalizeInventoryName(name));
        if (existingIndex >= 0) {
            selectedSpecialComponents[existingIndex].quantity += quantity;
        } else {
            selectedSpecialComponents.push({ name, quantity });
        }

        if (specialCustomizeQtyInput) {
            specialCustomizeQtyInput.value = '1';
        }

        renderSpecialCustomizeControls();
    });
}

if (specialCustomizeList) {
    specialCustomizeList.addEventListener('click', (event) => {
        const removeButton = event.target.closest('.special-customize-remove-btn');
        if (!removeButton) return;

        const index = Number(removeButton.dataset.index);
        if (Number.isNaN(index) || index < 0 || index >= selectedSpecialComponents.length) return;

        selectedSpecialComponents.splice(index, 1);
        renderSpecialCustomizeControls();
    });
}

function resizeImageToSquareDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            const img = new Image();
            img.onload = () => {
                const size = 720;
                const canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;

                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    reject(new Error('Unable to process image'));
                    return;
                }

                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, size, size);

                const scale = Math.min(size / img.width, size / img.height);
                const drawWidth = Math.round(img.width * scale);
                const drawHeight = Math.round(img.height * scale);
                const dx = Math.floor((size - drawWidth) / 2);
                const dy = Math.floor((size - drawHeight) / 2);

                ctx.drawImage(img, dx, dy, drawWidth, drawHeight);

                resolve(canvas.toDataURL('image/jpeg', 0.85));
            };
            img.onerror = () => reject(new Error('Invalid image file'));
            img.src = String(reader.result || '');
        };
        reader.onerror = () => reject(new Error('Unable to read image file'));
        reader.readAsDataURL(file);
    });
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

syncAnalyticsMonthSelectorsToCurrentMonth();
updateAnalyticsView();

function renderAnalytics(type) {
    if (!analyticsChart || !analyticsData[type]) return;
    if (!analyticsData[type].items || !analyticsData[type].items.length) {
        analyticsChart.innerHTML = '<p class="menu-cart-empty">Waiting for live data...</p>';
        return;
    }

    const data = analyticsData[type];
    const values = data.items.map((item) => Number(item.value) || 0);
    const maxValue = Math.max(...values);
    const paddedMax = Math.max(5000, Math.ceil(maxValue / 5000) * 5000);
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
        const normalizedValue = Number(item.value) || 0;
        const y = margin.top + chartHeight - (normalizedValue / paddedMax) * chartHeight;
        return { x, y, label: item.label, display: item.display, value: normalizedValue };
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
    accountForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!document.body.classList.contains('auth') || !(selectedRoleInput && selectedRoleInput.value === 'Admin')) {
            alert('Only the admin can manage accounts.');
            return;
        }

        const account = {
            name: accountNameInput ? accountNameInput.value.trim() : '',
            role: accountRoleInput ? accountRoleInput.value : '',
            email: accountEmailInput ? accountEmailInput.value.trim().toLowerCase() : '',
            password: accountPasswordInput ? accountPasswordInput.value : '',
            inviteConfirmed: false
        };

        if (!account.name || !account.role || !account.email || !account.password) {
            return;
        }

        if (account.role !== 'Cashier' && account.role !== 'Inventory Manager') {
            alert('Only Cashier and Inventory Manager accounts can be managed here.');
            return;
        }

        if (!isGmailAddress(account.email)) {
            alert('Only Gmail addresses are allowed for cashier/inventory accounts.');
            return;
        }

        const duplicateIndex = accounts.findIndex((entry, idx) => idx !== accountEditIndex && (entry.email || '').toLowerCase() === account.email);
        if (duplicateIndex >= 0) {
            alert('This email is already registered.');
            return;
        }

        const previousAccount = accountEditIndex !== null ? accounts[accountEditIndex] : null;

        let invitePayload = null;
        try {
            invitePayload = await sendStaffInviteEmail(account);
        } catch (error) {
            alert(error.message || 'Unable to send invite email.');
            return;
        }

        if (accountEditIndex !== null) {
            accounts[accountEditIndex] = account;
            void logStaffActivity('account_updated', `${account.name} (${account.role})`, {
                previous_name: previousAccount ? previousAccount.name : null,
                previous_role: previousAccount ? previousAccount.role : null,
                previous_email: previousAccount ? previousAccount.email : null,
                password_changed: previousAccount ? previousAccount.password !== account.password : false,
                invite_confirmation_reset: true,
                next_name: account.name,
                next_role: account.role,
                next_email: account.email
            });
        } else {
            accounts.push(account);
            void logStaffActivity('account_created', `${account.name} (${account.role})`, {
                email: account.email,
                role: account.role,
                invite_confirmation_required: true
            });
        }
        void loadOrderLogsFromServer(true);

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
        if (invitePayload && invitePayload.delivered === false) {
            alert(invitePayload.error || 'Invite email was not delivered. Check Laravel SMTP settings and try again.');
        } else {
            alert('Invite email sent. The staff account can login after confirming the email verification code.');
        }
    });
}

if (accountList) {
    accountList.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;

        const index = Number(button.dataset.index);
        if (button.classList.contains('delete-btn')) {
            const removedAccount = accounts[index];
            if (removedAccount && removedAccount.role === 'Admin') {
                alert('Admin account is managed through Credentials only.');
                return;
            }
            accounts.splice(index, 1);
            window.motasteStaffAccounts = accounts;
            saveStaffAccountsToServer();
            if (removedAccount) {
                void logStaffActivity('account_deleted', `${removedAccount.name} (${removedAccount.role})`, {
                    email: removedAccount.email,
                    role: removedAccount.role
                });
            }
            void loadOrderLogsFromServer(true);
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
                    if (loginFields) loginFields.hidden = false;
                    if (modalTitle) modalTitle.textContent = 'Staff Login';
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
                if (selectedAccount.role === 'Admin') {
                    alert('Admin account is managed through Credentials only.');
                    return;
                }
                accountEditIndex = index;
                if (accountNameInput) accountNameInput.value = selectedAccount.name;
                if (accountRoleInput) accountRoleInput.value = selectedAccount.role;
                if (accountEmailInput) accountEmailInput.value = selectedAccount.email;
                if (accountPasswordInput) accountPasswordInput.value = selectedAccount.password;
            }
        }
    });
}

if (requestCredentialsChangeBtn) {
    requestCredentialsChangeBtn.addEventListener('click', () => {
        if (!canAccessCredentials()) {
            setCredentialsMessage('Only admin can change credentials.', true);
            return;
        }
        void requestAdminCredentialsChange();
    });
}

if (credentialsForm) {
    credentialsForm.addEventListener('submit', (event) => {
        if (!canAccessCredentials()) {
            event.preventDefault();
            setCredentialsMessage('Only admin can change credentials.', true);
            return;
        }
        void confirmAdminCredentialsChange(event);
    });
}

const socialLoginButtons = document.querySelectorAll('.social-login-btn');
if (socialLoginButtons && socialLoginButtons.length) {
    socialLoginButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const provider = (button.dataset.provider || '').trim();
            const label = provider ? provider.charAt(0).toUpperCase() + provider.slice(1) : 'Social';
            if (typeof window !== 'undefined' && window.alert) {
                window.alert(`${label} login option is shown. Complete OAuth app keys/server callback setup before enabling live sign-in.`);
            }
        });
    });
}

loadStaffAccountsFromStorage();
renderAccounts();
if (isStaffPage) {
    void ensureCsrfToken();
    void loadStaffAccountsFromServer();
    void loadHighlightsFromServer();
    void loadAdminCredentials();
    startStaffAccountsRefresh();
    void loadOrderLogsFromServer();
    startOrderLogsRefresh();
    startPendingOrdersRefresh();
}

/* Highlights slideshow functionality (cross-device via API) */
const slideshow = document.querySelector('.slideshow');
const slideDots = document.getElementById('slideDots');
let highlightsSlides = [];
let highlightsCurrentIndex = 0;
let highlightsTimer = null;

const highlightsLightbox = document.createElement('div');
highlightsLightbox.className = 'image-lightbox hidden';
highlightsLightbox.innerHTML = '<button type="button" class="close-btn" aria-label="Close image">×</button><img alt="Expanded view">';
document.body.appendChild(highlightsLightbox);

const highlightsLightboxImage = highlightsLightbox.querySelector('img');
const highlightsCloseButton = highlightsLightbox.querySelector('.close-btn');

function closeLightbox() {
    highlightsLightbox.classList.add('hidden');
}

if (highlightsCloseButton) {
    highlightsCloseButton.addEventListener('click', closeLightbox);
}

highlightsLightbox.addEventListener('click', (event) => {
    if (event.target === highlightsLightbox) {
        closeLightbox();
    }
});

function setHighlightsMessage(message, isError = false) {
    if (!highlightsMessage) return;
    highlightsMessage.textContent = message || '';
    highlightsMessage.style.color = isError ? '#b00020' : '#0b6b2f';
}

function persistHighlightsToStorage() {
    localStorage.setItem(highlightsStorageKey, JSON.stringify(highlightsSlides));
}

function loadHighlightsFromStorage() {
    try {
        const raw = localStorage.getItem(highlightsStorageKey);
        const parsed = raw ? JSON.parse(raw) : [];
        highlightsSlides = Array.isArray(parsed)
            ? parsed.filter((item) => typeof item === 'string' && item.trim() !== '')
            : [];
    } catch (error) {
        highlightsSlides = [];
    }
}

async function loadHighlightsFromServer() {
    try {
        const response = await fetch(getApiUrl(`api/get_highlights.php?_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) return false;

        const payload = await response.json().catch(() => ({}));
        if (!payload || payload.success !== true || !Array.isArray(payload.slides)) return false;

        highlightsSlides = payload.slides
            .filter((item) => typeof item === 'string' && item.trim() !== '')
            .slice(0, highlightsMaxImages);
        persistHighlightsToStorage();
        renderHighlightsSlideshow();
        renderHighlightsManagement();
        return true;
    } catch (error) {
        return false;
    }
}

async function saveHighlightsToServer() {
    const headers = await withCsrfHeaders({
        'Content-Type': 'application/json'
    });

    const response = await fetch(getApiUrl('api/save_highlights.php'), {
        method: 'POST',
        headers,
        body: JSON.stringify({ slides: highlightsSlides.slice(0, highlightsMaxImages) }),
        cache: 'no-store'
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.success) {
        throw new Error(payload.error || `Unable to save highlights (HTTP ${response.status})`);
    }
}

function renderHighlightsSlideshow() {
    if (!slideshow) return;
    const slidesContainer = slideshow.querySelector('.slides');
    if (!slidesContainer || !slideDots) return;

    if (highlightsTimer) {
        clearInterval(highlightsTimer);
        highlightsTimer = null;
    }

    if (!highlightsSlides.length) {
        slidesContainer.innerHTML = '<div class="slideshow-empty">No highlights yet. Admin can upload images in Highlights tab.</div>';
        slideDots.innerHTML = '';
        return;
    }

    slidesContainer.innerHTML = highlightsSlides.map((src, index) => `
        <img src="${src}" alt="Highlight ${index + 1}"${index === 0 ? ' class="active"' : ''}>
    `).join('');
    slideDots.innerHTML = '';

    const slides = Array.from(slidesContainer.querySelectorAll('img'));
    highlightsCurrentIndex = Math.min(highlightsCurrentIndex, Math.max(0, slides.length - 1));

    const showSlide = (index) => {
        slides.forEach((slide, idx) => {
            slide.classList.toggle('active', idx === index);
        });

        Array.from(slideDots.children).forEach((dot, idx) => {
            dot.classList.toggle('active', idx === index);
        });

        highlightsCurrentIndex = index;
    };

    slides.forEach((slide) => {
        slide.addEventListener('click', () => {
            if (!slide.classList.contains('active')) return;
            highlightsLightboxImage.src = slide.src;
            highlightsLightboxImage.alt = slide.alt || 'Expanded image';
            highlightsLightbox.classList.remove('hidden');
        });
    });

    slides.forEach((_, index) => {
        const dot = document.createElement('button');
        if (index === highlightsCurrentIndex) {
            dot.classList.add('active');
        }
        dot.addEventListener('click', () => {
            showSlide(index);
        });
        slideDots.appendChild(dot);
    });

    showSlide(highlightsCurrentIndex);
    highlightsTimer = setInterval(() => {
        const next = (highlightsCurrentIndex + 1) % slides.length;
        showSlide(next);
    }, 4000);
}

function renderHighlightsManagement() {
    if (!highlightsList) return;

    if (!highlightsSlides.length) {
        highlightsList.innerHTML = '<p class="menu-cart-empty">No highlight images uploaded yet.</p>';
    } else {
        highlightsList.innerHTML = highlightsSlides.map((src, index) => `
            <article class="highlight-item-card">
                <img src="${src}" alt="Highlight ${index + 1}">
                <button type="button" class="highlight-remove-btn" data-index="${index}">Remove</button>
            </article>
        `).join('');
    }

    setHighlightsMessage(`${highlightsSlides.length}/${highlightsMaxImages} images in slideshow.`);
}

function readImageAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result || ''));
        reader.onerror = () => reject(new Error('Unable to read image file.'));
        reader.readAsDataURL(file);
    });
}

if (highlightsForm) {
    highlightsForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!canManageHighlights()) {
            setHighlightsMessage('Only admin can manage highlights.', true);
            return;
        }

        const files = highlightsImagesInput && highlightsImagesInput.files
            ? Array.from(highlightsImagesInput.files)
            : [];

        if (!files.length) {
            setHighlightsMessage('Select at least one image.', true);
            return;
        }

        const slotsLeft = highlightsMaxImages - highlightsSlides.length;
        if (slotsLeft <= 0) {
            setHighlightsMessage(`Maximum of ${highlightsMaxImages} images reached.`, true);
            return;
        }

        const filesToUpload = files.filter((file) => file.type.startsWith('image/')).slice(0, slotsLeft);
        if (!filesToUpload.length) {
            setHighlightsMessage('Only image files are allowed.', true);
            return;
        }

        try {
            const newSlides = await Promise.all(filesToUpload.map((file) => readImageAsDataUrl(file)));
            highlightsSlides = [...highlightsSlides, ...newSlides].slice(0, highlightsMaxImages);
            persistHighlightsToStorage();
            await saveHighlightsToServer();

            renderHighlightsSlideshow();
            renderHighlightsManagement();
            if (highlightsImagesInput) {
                highlightsImagesInput.value = '';
            }

            if (files.length > filesToUpload.length) {
                setHighlightsMessage(`Uploaded ${filesToUpload.length} image(s). Extra files were skipped by the ${highlightsMaxImages}-image limit.`);
            } else {
                setHighlightsMessage(`Uploaded ${filesToUpload.length} image(s).`);
            }
        } catch (error) {
            setHighlightsMessage(error.message || 'Unable to upload highlights.', true);
        }
    });
}

if (highlightsList) {
    highlightsList.addEventListener('click', async (event) => {
        const removeButton = event.target.closest('.highlight-remove-btn');
        if (!removeButton) return;
        if (!canManageHighlights()) {
            setHighlightsMessage('Only admin can manage highlights.', true);
            return;
        }

        const index = Number(removeButton.dataset.index);
        if (Number.isNaN(index) || index < 0 || index >= highlightsSlides.length) return;

        highlightsSlides.splice(index, 1);
        if (highlightsCurrentIndex >= highlightsSlides.length) {
            highlightsCurrentIndex = Math.max(0, highlightsSlides.length - 1);
        }

        try {
            persistHighlightsToStorage();
            await saveHighlightsToServer();
            renderHighlightsSlideshow();
            renderHighlightsManagement();
        } catch (error) {
            setHighlightsMessage(error.message || 'Unable to remove highlight image.', true);
        }
    });
}

loadHighlightsFromStorage();
renderHighlightsSlideshow();
renderHighlightsManagement();

const menuData = {
    batchoy: {
        title: 'BATCHOY',
        items: []
    },
    silog: {
        title: 'SILOG',
        items: []
    },
    friedChicken: {
        title: 'FRIED CHICKEN',
        items: []
    },
    breakfast: {
        title: 'BREAKFAST',
        items: []
    },
    drinks: {
        title: 'DRINKS',
        items: []
    },
    addons: {
        title: 'ADD ON',
        items: []
    }
};

const specialFoods = [];

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
const cartAddOnBtn = document.getElementById('cartAddOnBtn');
const cartAddOnScreen = document.getElementById('cartAddOnScreen');
const cartAddOnCloseBtn = document.getElementById('cartAddOnCloseBtn');
const cartAddOnList = document.getElementById('cartAddOnList');
const cartAddOnTotal = document.getElementById('cartAddOnTotal');
const cartAddOnMessage = document.getElementById('cartAddOnMessage');
const cartAddOnApplyBtn = document.getElementById('cartAddOnApplyBtn');
const cartAddOnSearchInput = document.getElementById('cartAddOnSearchInput');
const menuOrderMessage = document.getElementById('menuOrderMessage');
const menuOverlay = document.getElementById('menuOverlay');
const openMenuBtn = document.getElementById('openMenuBtn');
const closeMenuOverlayBtn = document.getElementById('closeMenuOverlayBtn');
const menuAddOnQuickBtn = document.getElementById('menuAddOnQuickBtn');
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
let walkInDraftItems = [];
let activeOrdersTab = 'walk-in';
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

function parseServerDateToMs(value) {
    if (value === null || value === undefined || value === '') return Date.now();
    if (typeof value === 'number') return value;

    const raw = String(value).trim();
    if (!raw) return Date.now();

    const matched = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
    if (matched) {
        const year = Number(matched[1]);
        const month = Number(matched[2]) - 1;
        const day = Number(matched[3]);
        const hour = Number(matched[4] || 0);
        const minute = Number(matched[5] || 0);
        const second = Number(matched[6] || 0);
        return new Date(year, month, day, hour, minute, second).getTime();
    }

    const parsed = new Date(raw);
    if (!Number.isNaN(parsed.getTime())) return parsed.getTime();

    return Date.now();
}

function formatRealtimeDate(value) {
    const timestamp = parseServerDateToMs(value);
    return new Date(timestamp).toLocaleString();
}

function parsePrice(priceText) {
    if (typeof priceText === 'number') {
        return Number.isFinite(priceText) ? priceText : 0;
    }

    const raw = String(priceText || '').trim();
    if (!raw) return 0;
    return Number(raw.replace(/[₱,\s]/g, '')) || 0;
}

function getResolvedAddOnPrice(itemName, fallbackPrice = 0) {
    const inventoryItem = getInventoryItem(itemName);
    if (inventoryItem) {
        const inventoryPrice = Number(inventoryItem.price);
        if (Number.isFinite(inventoryPrice) && inventoryPrice >= 0) {
            return inventoryPrice;
        }
    }

    return Math.max(0, parsePrice(fallbackPrice));
}

function loadCart() {
    try {
        const raw = localStorage.getItem('motasteCart');
        cartItems = raw ? JSON.parse(raw) : [];
    } catch (error) {
        cartItems = [];
    }

    if (!Array.isArray(cartItems)) {
        cartItems = [];
        return;
    }

    cartItems = cartItems.map((item) => {
        const quantity = Math.max(1, Number(item && item.quantity ? item.quantity : 1) || 1);
        const fallbackBase = getSpecialFoodComponentsByName(item.name);
        const baseComponents = normalizeSpecialComponents(
            Array.isArray(item.baseComponents) && item.baseComponents.length
                ? item.baseComponents
                : (fallbackBase.length ? fallbackBase : item.components)
        );

        let loadedComponents = normalizeCartComponents(item.components);
        if (loadedComponents.length && item.componentsMode !== 'total') {
            loadedComponents = loadedComponents.map((component) => ({
                ...component,
                quantity: component.quantity * quantity
            }));
        }

        const components = loadedComponents.length
            ? loadedComponents
            : buildInitialCartComponents(baseComponents, quantity);

        return {
            ...item,
            quantity,
            baseComponents,
            components,
            componentsMode: 'total',
            componentsOpen: components.length > 0
        };
    });
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
                timestamp: parseServerDateToMs(order.order_date_iso || order.order_date || Date.now()),
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
        renderWalkInOrderBuilder();
        renderOrderNotifications();
    } catch (error) {
        console.error('Unable to load pending orders from the server', error);
    }
}

function startPendingOrdersRefresh() {
    if (!isStaffPage || pendingOrdersRefreshTimer) return;

    pendingOrdersRefreshTimer = window.setInterval(() => {
        void loadPendingOrdersFromServer();
    }, 30000);
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
    const actor = getCurrentStaffActor();

    const response = await fetch(getApiUrl('api/mark_order_complete.php'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            orderId,
            actorRole: actor.role,
            actorEmail: actor.email
        }),
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
    const actor = getCurrentStaffActor();

    const response = await fetch(getApiUrl('api/update_pending_order_item.php'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            orderId,
            itemId,
            quantity,
            actorRole: actor.role,
            actorEmail: actor.email
        }),
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
    if (!canManageOrders()) return;
    if (orderIndex < 0 || orderIndex >= pendingOrders.length) return;
    const order = pendingOrders[orderIndex];
    const items = Array.isArray(order.items) ? order.items : [];
    const item = items.find((entry) => Number(entry.id) === Number(itemId));
    if (!item) return;

    const currentQuantity = Number(item.quantity) || 0;
    const delta = direction === 'increase' ? 1 : -1;
    const nextQuantity = currentQuantity + delta;
    if (nextQuantity < 0) return;

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
    void loadOrderLogsFromServer(true);
}

async function markPendingOrderAsComplete(orderIndex, shouldIgnore = false) {
    if (!canManageOrders()) return;
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
    renderWalkInOrderBuilder();
    renderOrderNotifications();
    updateAnalyticsView();
    renderOverviewAnalytics();
    void initializeInventoryData(true);
    void loadOrderLogsFromServer(true);
}

function formatOrderLogAction(action) {
    const map = {
        order_completed: 'Marked complete',
        quantity_increased: 'Quantity increased',
        quantity_decreased: 'Quantity decreased',
        quantity_updated: 'Quantity updated',
        item_removed: 'Item removed',
        order_removed: 'Order removed',
        inventory_item_added: 'Inventory item added',
        inventory_item_updated: 'Inventory item updated',
        inventory_item_removed: 'Inventory item removed',
        inventory_stock_changed: 'Inventory stock changed',
        account_created: 'Account created',
        account_updated: 'Account updated',
        account_deleted: 'Account deleted',
        review_submitted: 'Review submitted',
        review_submitted_pending: 'Review submitted (pending)',
        review_published: 'Review published',
        review_deleted: 'Review deleted'
    };

    return map[action] || 'Activity updated';
}

function formatOrderLogTimestamp(value) {
    return formatRealtimeDate(value);
}

function isLogFromToday(log) {
    const parsed = new Date(parseServerDateToMs(log.created_at_iso || log.created_at));
    if (Number.isNaN(parsed.getTime())) return false;

    const now = new Date();
    return parsed.getFullYear() === now.getFullYear()
        && parsed.getMonth() === now.getMonth()
        && parsed.getDate() === now.getDate();
}

function isQtyChangeAction(action) {
    return ['quantity_increased', 'quantity_decreased', 'quantity_updated', 'item_removed', 'order_removed'].includes(action);
}

function getFilteredOrderLogs() {
    if (activeOrderLogFilter === 'today') {
        return orderActivityLogs.filter((log) => isLogFromToday(log));
    }

    if (activeOrderLogFilter === 'qty') {
        return orderActivityLogs.filter((log) => isQtyChangeAction(log.action));
    }

    if (activeOrderLogFilter === 'completed') {
        return orderActivityLogs.filter((log) => log.action === 'order_completed');
    }

    if (activeOrderLogFilter === 'stock') {
        return orderActivityLogs.filter((log) => log.action === 'inventory_stock_changed');
    }

    if (activeOrderLogFilter === 'inventory') {
        return orderActivityLogs.filter((log) => String(log.action || '').startsWith('inventory_'));
    }

    if (activeOrderLogFilter === 'accounts') {
        return orderActivityLogs.filter((log) => String(log.action || '').startsWith('account_'));
    }

    if (activeOrderLogFilter === 'reviews') {
        return orderActivityLogs.filter((log) => String(log.action || '').startsWith('review_'));
    }

    return orderActivityLogs;
}

function getLogFilterCounts() {
    const allLogs = Array.isArray(orderActivityLogs) ? orderActivityLogs : [];
    return {
        all: allLogs.length,
        today: allLogs.filter((log) => isLogFromToday(log)).length,
        qty: allLogs.filter((log) => isQtyChangeAction(log.action)).length,
        completed: allLogs.filter((log) => log.action === 'order_completed').length,
        stock: allLogs.filter((log) => log.action === 'inventory_stock_changed').length,
        inventory: allLogs.filter((log) => String(log.action || '').startsWith('inventory_')).length,
        accounts: allLogs.filter((log) => String(log.action || '').startsWith('account_')).length,
        reviews: allLogs.filter((log) => String(log.action || '').startsWith('review_')).length
    };
}

function updateLogsFilterState() {
    if (!logsFilterBar) return;

    const counts = getLogFilterCounts();
    const buttons = Array.from(logsFilterBar.querySelectorAll('.logs-filter-btn'));
    buttons.forEach((button) => {
        const filterKey = (button.dataset.logFilter || 'all').trim();
        const baseLabel = logsFilterLabelMap[filterKey] || (button.textContent || '').replace(/\s*\(\d+\)\s*$/, '').trim();
        const countValue = Number(counts[filterKey] || 0);

        button.classList.toggle('active', filterKey === activeOrderLogFilter);
        button.textContent = `${baseLabel} (${countValue})`;
    });
}

function renderOrderLogs() {
    if (!logsList) return;

    updateLogsFilterState();
    const filteredLogs = getFilteredOrderLogs();

    if (!filteredLogs.length) {
        logsList.innerHTML = '<p class="menu-cart-empty">No recent activity yet.</p>';
        return;
    }

    logsList.innerHTML = filteredLogs.map((log) => {
        const showOrderLabel = String(log.action || '') === 'order_completed';
        const orderLabel = log.order_number
            ? `Order #${log.order_number}`
            : '';
        const actorParts = [log.actor_role || 'Staff', log.actor_email || ''];
        const actorText = actorParts.filter(Boolean).join(' · ');
        const details = log.details && typeof log.details === 'object' ? log.details : null;
        const qtyText = details && details.previous_quantity !== undefined && details.new_quantity !== undefined
            ? `<p><strong>Qty:</strong> ${details.previous_quantity} → ${details.new_quantity}</p>`
            : '';

        let summaryText = log.summary || 'No summary.';
        if (String(log.action || '').startsWith('account_') && details) {
            const changes = [];
            if (details.previous_email !== undefined && details.next_email !== undefined && details.previous_email !== details.next_email) changes.push(`email: ${details.previous_email || '-'} -> ${details.next_email || '-'}`);
            if (details.previous_role !== undefined && details.next_role !== undefined && details.previous_role !== details.next_role) changes.push(`role: ${details.previous_role || '-'} -> ${details.next_role || '-'}`);
            if (details.password_changed === true) changes.push('password: changed');
            if (changes.length) {
                summaryText = changes.join('; ');
            } else if (log.action === 'account_created') {
                summaryText = `${details.role || '-'} account added (${details.email || '-'})`;
            } else if (log.action === 'account_deleted') {
                summaryText = `${details.role || '-'} account deleted (${details.email || '-'})`;
            }
        }
        if (String(log.action || '').startsWith('inventory_') && details) {
            const stockValue = details.stock !== undefined ? details.stock : details.new_stock;
            const previousStock = details.previous_stock;
            const name = details.name || details.item || summaryText;
            if (log.action === 'inventory_stock_changed') {
                if (previousStock !== undefined && previousStock !== null) {
                    summaryText = `${name} stock: ${previousStock} -> ${stockValue}`;
                } else {
                    summaryText = `${name} stock updated to ${stockValue}`;
                }
            } else if (log.action === 'inventory_item_added') {
                summaryText = `${name} added`;
            } else if (log.action === 'inventory_item_removed') {
                summaryText = `${name} removed`;
            } else {
                summaryText = `${name}`;
            }
        }
        if (isQtyChangeAction(log.action) && details) {
            const itemName = details.item || summaryText;
            summaryText = `${itemName}: ${details.previous_quantity ?? 0} -> ${details.new_quantity ?? 0}`;
        }

        const isReviewAction = String(log.action || '').startsWith('review_');
        let reviewCommentText = '';
        if (isReviewAction && details) {
            const extractedComment = (details.review_text || details.comment || '').toString().trim();
            if (extractedComment) {
                reviewCommentText = extractedComment;
                if (log.action === 'review_submitted' || log.action === 'review_submitted_pending') {
                    summaryText = extractedComment;
                }
            }
        }

        return `
            <article class="order-log-card">
                <div class="order-log-top-row">
                    <strong>${formatOrderLogAction(log.action)}</strong>
                    <span>${formatOrderLogTimestamp(log.created_at_iso || log.created_at)}</span>
                </div>
                ${showOrderLabel && orderLabel ? `<p><strong>${orderLabel}</strong></p>` : ''}
                <p><strong>By:</strong> ${actorText || 'Staff'}</p>
                ${qtyText}
                ${reviewCommentText ? `<p class="review-log-comment"><strong>Comment:</strong> <span class="review-log-comment-text">${reviewCommentText}</span></p>` : ''}
                <p><strong>Summary:</strong> ${summaryText}</p>
            </article>
        `;
    }).join('');
}

async function loadOrderLogsFromServer(forceRefresh = false) {
    if (orderLogsSyncInFlight && !forceRefresh) return false;

    orderLogsSyncInFlight = true;
    try {
        const response = await fetch(getApiUrl(`api/get_order_logs.php?_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) return false;

        const payload = await response.json();
        if (!payload || payload.success !== true) return false;

        orderActivityLogs = Array.isArray(payload.logs) ? payload.logs : [];
        renderOrderLogs();
        return true;
    } catch (error) {
        console.error('Unable to load order activity logs', error);
        return false;
    } finally {
        orderLogsSyncInFlight = false;
    }
}

function startOrderLogsRefresh() {
    if (!isStaffPage || orderLogsRefreshTimer) return;

    orderLogsRefreshTimer = window.setInterval(() => {
        void loadOrderLogsFromServer(true);
    }, 5000);
}

function renderStarRating(value) {
    const rating = Math.max(1, Math.min(5, Number(value) || 0));
    return `${'★'.repeat(rating)}${'☆'.repeat(5 - rating)}`;
}

function getOrCreateReviewerToken() {
    try {
        const existing = localStorage.getItem(reviewerTokenStorageKey);
        if (existing) return existing;

        const token = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
            ? crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

        localStorage.setItem(reviewerTokenStorageKey, token);
        return token;
    } catch (error) {
        return `fallback-${Date.now()}`;
    }
}

function getReviewPublishStatusLabel(status) {
    if ((status || '').toLowerCase() === 'published') {
        return 'Published';
    }
    return 'Pending Approval';
}

function renderCustomerReviews() {
    if (!customerReviewsList) return;

    if (!cachedReviews.length) {
        customerReviewsList.innerHTML = '<p class="menu-cart-empty">No reviews yet. Be the first to leave one.</p>';
        return;
    }

    customerReviewsList.innerHTML = cachedReviews.map((review) => {
        const safeReviewText = escapeHtml(review.review_text);
        return `
            <article class="customer-review-card">
                <p><strong class="review-stars">${renderStarRating(review.rating)}</strong></p>
                <p class="review-comment">${safeReviewText}</p>
                <p><span>${formatRealtimeDate(review.created_at_iso || review.created_at)}</span></p>
            </article>
        `;
    }).join('');
}

function renderStaffReviews() {
    if (!staffReviewList) return;

    if (!cachedStaffReviews.length) {
        staffReviewList.innerHTML = '<p class="menu-cart-empty">No reviews yet.</p>';
        return;
    }

    staffReviewList.innerHTML = cachedStaffReviews.map((review) => {
        const status = (review.publish_status || 'pending').toLowerCase();
        const showPublishButton = status !== 'published';
        const safeReviewText = escapeHtml(review.review_text);
        return `
            <article class="staff-review-card">
                <p><span class="review-status-badge ${status === 'published' ? 'is-published' : 'is-pending'}">${getReviewPublishStatusLabel(status)}</span></p>
                <p><strong class="review-stars">${renderStarRating(review.rating)}</strong></p>
                <p class="review-comment">${safeReviewText}</p>
                <p><span>${formatRealtimeDate(review.created_at_iso || review.created_at)}</span></p>
                ${showPublishButton ? `<button type="button" class="staff-review-publish-btn" data-review-id="${review.id}">Publish Review</button>` : ''}
                <button type="button" class="staff-review-delete-btn" data-review-id="${review.id}">Delete Review</button>
            </article>
        `;
    }).join('');
}

async function loadReviewsFromServer(forceRefresh = false) {
    if (reviewRefreshTimer && !forceRefresh && (cachedReviews.length || cachedStaffReviews.length)) {
        renderCustomerReviews();
        renderStaffReviews();
        return true;
    }

    try {
        const scope = staffReviewList ? 'staff' : 'public';
        const response = await fetch(getApiUrl(`api/get_reviews.php?scope=${scope}&_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) return false;
        const payload = await response.json();
        if (!payload || payload.success !== true) return false;

        const incomingReviews = Array.isArray(payload.reviews) ? payload.reviews : [];
        if (scope === 'staff') {
            cachedStaffReviews = incomingReviews;
            cachedReviews = incomingReviews.filter((review) => (review.publish_status || '').toLowerCase() === 'published');
        } else {
            cachedReviews = incomingReviews;
            cachedStaffReviews = [];
        }

        renderCustomerReviews();
        renderStaffReviews();
        return true;
    } catch (error) {
        console.error('Unable to load reviews', error);
        return false;
    }
}

function startReviewRefresh() {
    if (reviewRefreshTimer) return;
    reviewRefreshTimer = window.setInterval(() => {
        void loadReviewsFromServer(true);
    }, 10000);
}

if (customerReviewForm) {
    customerReviewForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const rating = Number(reviewRatingInput ? reviewRatingInput.value : 0);
        const reviewText = reviewMessageInput ? reviewMessageInput.value.trim() : '';
        if (rating < 1 || rating > 5 || !reviewText) {
            if (reviewSubmitMessage) reviewSubmitMessage.textContent = 'Please provide a star rating and your review.';
            return;
        }

        try {
            const reviewerToken = getOrCreateReviewerToken();
            const headers = await withCsrfHeaders({
                'Content-Type': 'application/json'
            });

            const response = await fetch(getApiUrl('api/save_review.php'), {
                method: 'POST',
                headers,
                body: JSON.stringify({ rating, reviewText, reviewerToken }),
                cache: 'no-store'
            });

            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.error || `HTTP ${response.status}`);
            }

            if (customerReviewForm) customerReviewForm.reset();
            if (reviewSubmitMessage) {
                reviewSubmitMessage.textContent = payload.message || 'Review submitted. It will appear after staff approval.';
            }
            void loadReviewsFromServer(true);
            void loadOrderLogsFromServer(true);
        } catch (error) {
            if (reviewSubmitMessage) reviewSubmitMessage.textContent = error.message || 'Unable to submit review right now.';
        }
    });
}

if (staffReviewList) {
    staffReviewList.addEventListener('click', async (event) => {
        const publishButton = event.target.closest('.staff-review-publish-btn');
        const deleteButton = event.target.closest('.staff-review-delete-btn');
        const actionButton = publishButton || deleteButton;
        if (!actionButton) return;

        const reviewId = Number(actionButton.dataset.reviewId || 0);
        if (!reviewId) return;

        const actor = getCurrentStaffActor();
        try {
            const endpoint = publishButton ? 'api/publish_review.php' : 'api/delete_review.php';
            const headers = await withCsrfHeaders({
                'Content-Type': 'application/json'
            });

            const response = await fetch(getApiUrl(endpoint), {
                method: 'POST',
                headers,
                body: JSON.stringify({
                    reviewId,
                    actorRole: actor.role,
                    actorEmail: actor.email
                }),
                cache: 'no-store'
            });

            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.error || `HTTP ${response.status}`);
            }

            void loadReviewsFromServer(true);
            void loadOrderLogsFromServer(true);
        } catch (error) {
            if (typeof window !== 'undefined' && window.alert) {
                window.alert(error.message || 'Unable to update review status');
            }
        }
    });
}

if (logsFilterBar) {
    logsFilterBar.addEventListener('click', (event) => {
        const button = event.target.closest('.logs-filter-btn');
        if (!button) return;

        const filter = (button.dataset.logFilter || 'all').trim();
        if (!filter || filter === activeOrderLogFilter) return;

        activeOrderLogFilter = filter;
        renderOrderLogs();
    });
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
    addons: 'Add On',
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
            if (blockedProductNames.has(normalizeInventoryName(item.name))) return;
            if (seen.has(item.name)) return;
            seen.add(item.name);
            items.push({
                name: item.name,
                price: parsePrice(item.price),
                stock: 0,
                status: 'Out of stock',
                category: categoryKey,
                description: item.description || ''
            });
        });
    });

    specialFoods.forEach((food) => {
        if (blockedProductNames.has(normalizeInventoryName(food.name))) return;
        if (seen.has(food.name)) return;
        seen.add(food.name);
        items.push({
            name: food.name,
            price: Number(food.price) || 0,
            stock: 0,
            status: 'Out of stock',
            category: 'specials',
            description: food.description || '',
            components: normalizeSpecialComponents(food.components)
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
        const merged = serverItems.map((item) => {
            const normalizedServerName = normalizeInventoryName(item.name);
            const localMatch = inventoryData.find((entry) => normalizeInventoryName(entry.name) === normalizedServerName);
            return {
                name: item.name,
                price: Number(item.price) || 0,
                stock: Number(item.stock) || 0,
                status: item.status || (Number(item.stock) > 0 ? 'In stock' : 'Out of stock'),
                category: item.category || localMatch?.category || resolveInventoryCategory(item.name),
                description: item.description || localMatch?.description || ''
            };
        }).filter((item) => !blockedProductNames.has(normalizeInventoryName(item.name)));

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
    renderWalkInOrderBuilder();
    renderOverviewInventory();
    if (inventoryModal && !inventoryModal.hidden && inventoryCategoryInput && inventoryCategoryInput.value === 'specials') {
        renderSpecialCustomizeControls();
    }
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

function getInventoryDescription(name, fallback = '') {
    const inventoryItem = getInventoryItem(name);
    if (inventoryItem && inventoryItem.description) {
        return String(inventoryItem.description).trim();
    }

    return fallback || '';
}

function openProductDetailModal(item) {
    if (!productDetailModal || !item) return;

    activeProductDetailItem = {
        name: item.name || '',
        price: Number(item.price) || 0,
        image: item.image || 'img1.jpg',
        description: getInventoryDescription(item.name, item.description || 'Description coming soon.')
    };

    if (productDetailImage) {
        productDetailImage.src = activeProductDetailItem.image;
        productDetailImage.alt = activeProductDetailItem.name || 'Product image';
    }
    if (productDetailName) {
        productDetailName.textContent = activeProductDetailItem.name || 'Product details';
    }
    if (productDetailDescription) {
        productDetailDescription.textContent = activeProductDetailItem.description || 'Description coming soon.';
    }
    if (productDetailPrice) {
        productDetailPrice.textContent = formatCurrency(activeProductDetailItem.price);
    }
    productDetailQuantity = 1;
    syncProductDetailQuantityControls();

    productDetailModal.classList.remove('hidden');
    productDetailModal.hidden = false;
    productDetailModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function syncProductDetailQuantityControls() {
    if (!productDetailQtyValue || !productDetailAddBtn) return;

    const availableStock = activeProductDetailItem
        ? getAvailableStockForItem(activeProductDetailItem.name)
        : 0;

    if (availableStock <= 0) {
        productDetailQuantity = 0;
    } else {
        if (productDetailQuantity < 1) {
            productDetailQuantity = 1;
        }
        if (productDetailQuantity > availableStock) {
            productDetailQuantity = availableStock;
        }
    }

    productDetailQtyValue.textContent = String(productDetailQuantity);

    if (productDetailQtyDecrease) {
        productDetailQtyDecrease.disabled = availableStock <= 0 || productDetailQuantity <= 1;
    }
    if (productDetailQtyIncrease) {
        productDetailQtyIncrease.disabled = availableStock <= 0 || productDetailQuantity >= availableStock;
    }

    if (!activeProductDetailItem) {
        productDetailAddBtn.textContent = 'Add to cart';
        productDetailAddBtn.disabled = true;
        return;
    }

    const qtyLabel = productDetailQuantity === 1 ? '1 item' : `${productDetailQuantity} items`;
    productDetailAddBtn.textContent = `Add ${qtyLabel} to cart`;
    productDetailAddBtn.disabled = availableStock <= 0 || productDetailQuantity <= 0;
}

function closeProductDetailModal() {
    if (!productDetailModal) return;

    productDetailModal.classList.add('hidden');
    productDetailModal.hidden = true;
    productDetailModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    activeProductDetailItem = null;
    productDetailQuantity = 1;
}

if (productDetailCloseBtn) {
    productDetailCloseBtn.addEventListener('click', closeProductDetailModal);
}

if (productDetailModal) {
    productDetailModal.addEventListener('click', (event) => {
        if (event.target === productDetailModal) {
            closeProductDetailModal();
        }
    });
}

if (productDetailAddBtn) {
    productDetailAddBtn.addEventListener('click', () => {
        if (!activeProductDetailItem) return;
        addToCart({
            name: activeProductDetailItem.name,
            price: Number(activeProductDetailItem.price) || 0
        }, productDetailQuantity);
        closeProductDetailModal();
    });
}

if (productDetailQtyDecrease) {
    productDetailQtyDecrease.addEventListener('click', () => {
        if (!activeProductDetailItem) return;
        productDetailQuantity = Math.max(1, productDetailQuantity - 1);
        syncProductDetailQuantityControls();
    });
}

if (productDetailQtyIncrease) {
    productDetailQtyIncrease.addEventListener('click', () => {
        if (!activeProductDetailItem) return;
        const availableStock = getAvailableStockForItem(activeProductDetailItem.name);
        productDetailQuantity = Math.min(availableStock, productDetailQuantity + 1);
        syncProductDetailQuantityControls();
    });
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
            image: food.image,
            description: food.description || '',
            components: normalizeSpecialComponents(food.components)
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

    const beforeSignature = JSON.stringify({
        menuData: Object.fromEntries(Object.entries(menuData).map(([key, category]) => [
            key,
            {
                title: category.title,
                items: (category.items || []).map((item) => ({
                    name: item.name,
                    price: item.price,
                    description: item.description || ''
                }))
            }
        ])),
        specialFoods: specialFoods.map((food) => ({
            name: food.name,
            price: Number(food.price) || 0,
            image: food.image || 'img1.jpg',
            description: food.description || '',
            components: normalizeSpecialComponents(food.components)
        }))
    });

    const fixedCategories = ['batchoy', 'silog', 'friedChicken', 'breakfast', 'drinks', 'addons'];
    fixedCategories.forEach((categoryKey) => {
        if (!menuData[categoryKey]) {
            menuData[categoryKey] = { title: categoryKey.toUpperCase(), items: [] };
        }
        menuData[categoryKey].items = [];
    });

    specialFoods.length = 0;

    const menuDataSnapshot = snapshot.menuData || {};
    fixedCategories.forEach((categoryKey) => {
        const category = menuDataSnapshot[categoryKey];
        if (!category || !Array.isArray(category.items)) return;

        const seenNames = new Set();
        category.items.forEach((item) => {
            const normalizedName = (item.name || '').trim().toLowerCase();
            if (!normalizedName || seenNames.has(normalizedName)) return;

            menuData[categoryKey].items.push({
                name: item.name,
                price: item.price,
                description: item.description || `${item.name} has been added by staff.`
            });
            seenNames.add(normalizedName);
        });
    });

    if (Array.isArray(snapshot.specialFoods)) {
        const seenSpecialFoods = new Set();
        snapshot.specialFoods.forEach((food) => {
            const normalizedName = (food.name || '').trim().toLowerCase();
            if (!normalizedName || seenSpecialFoods.has(normalizedName)) return;

            specialFoods.push({
                name: food.name,
                price: Number(food.price) || 0,
                image: food.image || 'img1.jpg',
                description: food.description || '',
                components: normalizeSpecialComponents(food.components)
            });
            seenSpecialFoods.add(normalizedName);
        });
    }

    const afterSignature = JSON.stringify({
        menuData: Object.fromEntries(Object.entries(menuData).map(([key, category]) => [
            key,
            {
                title: category.title,
                items: (category.items || []).map((item) => ({
                    name: item.name,
                    price: item.price,
                    description: item.description || ''
                }))
            }
        ])),
        specialFoods: specialFoods.map((food) => ({
            name: food.name,
            price: Number(food.price) || 0,
            image: food.image || 'img1.jpg',
            description: food.description || '',
            components: normalizeSpecialComponents(food.components)
        }))
    });

    return beforeSignature !== afterSignature;
}

async function loadCustomMenuData() {
    try {
        const raw = localStorage.getItem('motasteCustomMenuData');
        let parsed = null;
        if (raw) {
            parsed = JSON.parse(raw);
        }

        const response = await fetch(getApiUrl(`api/get_custom_menu.php?_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) {
            if (parsed) {
                const changed = applyCustomMenuSnapshot(parsed);
                if (changed) {
                    syncMenuPricesWithInventory();
                    renderSpecialFoods();
                    renderInventoryManagement();
                    if (currentMenuCategoryId) {
                        showMenuCategory(currentMenuCategoryId);
                    }
                }
            }
            return;
        }

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
            return;
        }

        if (parsed) {
            const changed = applyCustomMenuSnapshot(parsed);
            if (changed) {
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
        const itemTotal = getCartItemLineTotal(item);
        total += itemTotal;
        const customizeOptions = getCartItemCustomizeOptions(item);
        const hasCustomizeOptions = customizeOptions.length > 0;
        const componentRows = hasCustomizeOptions
            ? customizeOptions.map((componentName) => {
                const quantity = getCartItemComponentQuantity(item, componentName);
                const canIncrease = canIncreaseCartComponentQuantity(index, componentName);
                return `
                    <li class="menu-cart-component-item">
                        <span class="menu-cart-component-name">${escapeHtml(componentName)}</span>
                        <div class="menu-cart-component-controls">
                            <button type="button" class="menu-cart-component-btn" data-action="component-decrease" data-index="${index}" data-component-name="${escapeHtml(componentName)}" aria-label="Decrease ${escapeHtml(componentName)} quantity"${quantity <= 0 ? ' disabled' : ''}>−</button>
                            <span class="menu-cart-component-qty">${quantity}</span>
                            <button type="button" class="menu-cart-component-btn" data-action="component-increase" data-index="${index}" data-component-name="${escapeHtml(componentName)}" aria-label="Increase ${escapeHtml(componentName)} quantity"${canIncrease ? '' : ' disabled'}>+</button>
                            <button type="button" class="menu-cart-component-remove" data-index="${index}" data-component-name="${escapeHtml(componentName)}" aria-label="Remove ${escapeHtml(componentName)}"${quantity <= 0 ? ' disabled' : ''}>Remove</button>
                        </div>
                    </li>
                `;
            }).join('')
            : '';
        return `
            <div class="menu-cart-item">
                <div class="menu-cart-item-details">
                    <div>
                        <div class="menu-cart-item-title-row">
                            <strong>${item.name}</strong>
                            ${hasCustomizeOptions ? `<button type="button" class="menu-cart-components-toggle" data-index="${index}" aria-expanded="${item.componentsOpen ? 'true' : 'false'}" aria-label="Toggle customize options">${item.componentsOpen ? '▾' : '▸'}</button>` : ''}
                        </div>
                        <div class="menu-cart-item-qty-controls">
                            <button type="button" class="menu-cart-item-quantity-btn" data-action="decrease" data-index="${index}" aria-label="Decrease ${item.name} quantity"${item.quantity === 1 ? ' disabled' : ''}>
                                <i class="fa-solid fa-minus" aria-hidden="true"></i>
                            </button>
                            <span class="menu-cart-item-qty">${item.quantity}</span>
                            <button type="button" class="menu-cart-item-quantity-btn" data-action="increase" data-index="${index}" aria-label="Increase ${item.name} quantity"${canIncreaseCartItemQuantity(index) ? '' : ' disabled'}>
                                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="menu-cart-item-remove" data-index="${index}">Remove</button>
                </div>
                ${hasCustomizeOptions && item.componentsOpen ? `<div class="menu-cart-components"><p class="menu-cart-components-title">Customize</p><ul class="menu-cart-component-list">${componentRows}</ul></div>` : ''}
                <div>${formatCurrency(itemTotal)}</div>
            </div>
        `;
    }).join('');

    menuCartCount.textContent = `${totalItems} items`;
    menuCartTotal.textContent = formatCurrency(total);
    menuPlaceOrderBtn.disabled = total <= 0;
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

function getReservedComponentQuantityInCart(componentName) {
    const normalizedComponentName = normalizeInventoryName(componentName);
    if (!normalizedComponentName) return 0;

    return cartItems.reduce((sum, cartItem) => {
        if (!Array.isArray(cartItem.components)) return sum;

        const component = cartItem.components.find((entry) => normalizeInventoryName(entry.name) === normalizedComponentName);
        if (!component) return sum;

        const componentQuantity = Math.max(0, Number(component.quantity) || 0);
        return sum + componentQuantity;
    }, 0);
}

function getAvailableStockForCartComponent(componentName) {
    const inventoryItem = getInventoryItem(componentName);
    if (!inventoryItem) return 0;

    const stock = Math.max(0, Number(inventoryItem.stock) || 0);
    const reserved = getReservedComponentQuantityInCart(componentName);
    return Math.max(0, stock - reserved);
}

function canIncreaseCartItemQuantity(index) {
    if (index < 0 || index >= cartItems.length) return false;

    const cartItem = cartItems[index];
    if (getAvailableStockForItem(cartItem.name) <= 0) {
        return false;
    }

    const baseComponents = getCartItemBaseComponents(cartItem);
    if (!baseComponents.length) {
        return true;
    }

    return baseComponents.every((component) => {
        const componentPerDishQty = Math.max(0, Number(component.quantity) || 0);
        if (componentPerDishQty <= 0) return true;
        return getAvailableStockForCartComponent(component.name) >= componentPerDishQty;
    });
}

function canIncreaseCartComponentQuantity(index, componentName) {
    if (index < 0 || index >= cartItems.length) return false;

    return getAvailableStockForCartComponent(componentName) >= 1;
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

    const reservedUsage = {};
    cartItems.forEach((item) => {
        if (!Array.isArray(item.components)) {
            item.components = [];
            return;
        }

        const nextComponents = [];

        item.components.forEach((component) => {
            const componentName = (component && component.name ? String(component.name) : '').trim();
            if (!componentName) {
                changed = true;
                return;
            }

            const inventoryComponent = getInventoryItem(componentName);
            if (!inventoryComponent) {
                // Keep component entries even when inventory metadata is temporarily unavailable.
                nextComponents.push({
                    name: componentName,
                    quantity: Math.max(0, Number(component.quantity) || 0)
                });
                return;
            }

            const normalized = normalizeInventoryName(componentName);
            const stock = Math.max(0, Number(inventoryComponent.stock) || 0);
            const alreadyReserved = reservedUsage[normalized] || 0;
            const remainingStock = Math.max(0, stock - alreadyReserved);
            const requestedQty = Math.max(0, Number(component.quantity) || 0);
            const clampedQty = Math.min(requestedQty, remainingStock);

            if (clampedQty !== requestedQty) {
                changed = true;
            }

            nextComponents.push({
                name: componentName,
                quantity: clampedQty
            });
            reservedUsage[normalized] = alreadyReserved + clampedQty;
        });

        if (nextComponents.length !== item.components.length) {
            changed = true;
        }

        item.components = nextComponents;
    });

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
    const applyStockReduction = (itemName, quantityToReduce) => {
        const inventoryItem = getInventoryItem(itemName);
        if (!inventoryItem) return;

        inventoryItem.stock = Math.max(0, Number(inventoryItem.stock || 0) - quantityToReduce);
        if (inventoryItem.stock <= 0) {
            inventoryItem.status = 'Out of stock';
        } else if (inventoryItem.stock <= 5) {
            inventoryItem.status = 'Low stock';
        } else {
            inventoryItem.status = 'In stock';
        }
    };

    items.forEach((item) => {
        const orderedQuantity = Math.max(0, Number(item.quantity) || 0);
        if (orderedQuantity <= 0) return;

        applyStockReduction(item.name, orderedQuantity);

        const components = Array.isArray(item.components) && item.components.length
            ? normalizeCartComponents(item.components)
            : buildInitialCartComponents(getSpecialFoodComponentsByName(item.name), orderedQuantity);
        components.forEach((component) => {
            const needed = Math.max(0, Number(component.quantity) || 0);
            if (needed <= 0) return;
            applyStockReduction(component.name, needed);
        });
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

    const canCompleteOrders = canManageOrders();

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
                <p><strong>Submitted:</strong> ${formatRealtimeDate(order.timestamp)}</p>
                <p><strong>Payment:</strong> ${order.paymentMethod}</p>
                <ul>${orderItems}</ul>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                    <strong>Total: ${formatCurrency(order.total)}</strong>
                    ${isCompleted || !canCompleteOrders ? '' : `<button type="button" class="order-complete-btn" data-order-id="${order.id}">Mark Complete</button>`}
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
        const normalizedName = normalizeInventoryName(item.name);
        if (!normalizedName) return map;
        map[normalizedName] = item;
        return map;
    }, {});

    (inventoryData || []).forEach((inventoryItem) => {
        const categoryKey = inventoryItem.category || resolveInventoryCategory(inventoryItem.name);
        if (!categoryKey || categoryKey === 'specials') return;
        if (blockedProductNames.has(normalizeInventoryName(inventoryItem.name))) return;

        if (!menuData[categoryKey]) {
            menuData[categoryKey] = {
                title: (inventoryCategoryLabels[categoryKey] || String(categoryKey)).toUpperCase(),
                items: []
            };
        }

        const existsInCategory = (menuData[categoryKey].items || []).some((menuItem) => normalizeInventoryName(menuItem.name) === normalizeInventoryName(inventoryItem.name));
        if (!existsInCategory) {
            menuData[categoryKey].items.push({
                name: inventoryItem.name,
                price: `₱${Number(inventoryItem.price || 0).toLocaleString()}`,
                description: inventoryItem.description || `${inventoryItem.name} has been added by staff.`
            });
        }
    });

    Object.values(menuData).forEach((category) => {
        category.items.forEach((item) => {
            const normalizedName = normalizeInventoryName(item.name);
            if (priceMap[normalizedName] !== undefined) {
                item.price = `₱${Number(priceMap[normalizedName].price || 0).toLocaleString()}`;
                item.description = priceMap[normalizedName].description || item.description || '';
            }
        });
    });

    specialFoods.forEach((food) => {
        const normalizedName = normalizeInventoryName(food.name);
        if (priceMap[normalizedName] !== undefined) {
            food.price = Number(priceMap[normalizedName].price) || 0;
            food.description = priceMap[normalizedName].description || food.description || '';
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
        const description = item.description || '';

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
                        <p class="inventory-item-description">${escapeHtml(description || 'No description yet.')}</p>
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
                            <option value="addons" ${category === 'addons' ? 'selected' : ''}>Add On</option>
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
                        Description
                        <textarea data-field="description" rows="4">${escapeHtml(description)}</textarea>
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
    const description = item.description || '';
    const components = normalizeSpecialComponents(item.components);

    if (category === 'specials') {
        const existingSpecialIndex = specialFoods.findIndex((food) => (food.name || '').trim().toLowerCase() === normalizedPreviousName || (food.name || '').trim().toLowerCase() === (item.name || '').trim().toLowerCase());
        const preferredImage = item.image || selectedSpecialFoodImageData || '';
        if (existingSpecialIndex >= 0) {
            specialFoods[existingSpecialIndex] = {
                ...specialFoods[existingSpecialIndex],
                name: item.name,
                price: priceNumber,
                description: description || specialFoods[existingSpecialIndex].description || '',
                image: preferredImage || specialFoods[existingSpecialIndex].image || 'img1.jpg',
                components: components.length ? components : normalizeSpecialComponents(specialFoods[existingSpecialIndex].components)
            };
        } else {
            specialFoods.push({
                name: item.name,
                price: priceNumber,
                description,
                image: preferredImage || 'img1.jpg',
                components
            });
        }
    } else if (menuData[category]) {
        const existingMenuIndex = menuData[category].items.findIndex((menuItem) => (menuItem.name || '').trim().toLowerCase() === normalizedPreviousName || (menuItem.name || '').trim().toLowerCase() === (item.name || '').trim().toLowerCase());
        if (existingMenuIndex >= 0) {
            menuData[category].items[existingMenuIndex] = {
                ...menuData[category].items[existingMenuIndex],
                name: item.name,
                price: `₱${priceNumber.toLocaleString()}`,
                description: description || menuData[category].items[existingMenuIndex].description || `${item.name} has been updated by staff.`
            };
        } else {
            menuData[category].items.push({
                name: item.name,
                price: `₱${priceNumber.toLocaleString()}`,
                description: description || `${item.name} has been added by staff.`
            });
        }
    } else {
        menuData[category] = {
            title: category === 'specials' ? 'SPECIALS' : category.toUpperCase(),
            items: [
                {
                    name: item.name,
                    price: `₱${priceNumber.toLocaleString()}`,
                    description: description || `${item.name} has been added by staff.`
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

async function deleteInventoryItem(name) {
    const normalizedTargetName = normalizeInventoryName(name);
    const index = inventoryData.findIndex((item) => normalizeInventoryName(item.name) === normalizedTargetName);
    if (index < 0) return;

    const actor = getCurrentStaffActor();
    let shouldContinueDelete = false;
    try {
        const response = await fetch(getApiUrl('api/delete_inventory_item.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                name,
                actorRole: actor.role,
                actorEmail: actor.email
            }),
            cache: 'no-store'
        });

        const payload = await response.json().catch(() => ({}));
        const errorMessage = String(payload?.error || '').toLowerCase();

        if ((!response.ok || !payload.success) && !errorMessage.includes('not found')) {
            throw new Error(payload.error || `HTTP ${response.status}`);
        }

        shouldContinueDelete = true;
    } catch (error) {
        if (typeof window !== 'undefined' && window.alert) {
            window.alert(error.message || 'Unable to delete inventory item');
        }
        return;
    }

    if (!shouldContinueDelete) {
        return;
    }

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
    void initializeInventoryData(true);
    void loadOrderLogsFromServer(true);
}

async function saveInventoryItem(event) {
    if (event && event.preventDefault) {
        event.preventDefault();
    }

    if (!inventoryNameInput || !inventoryPriceInput || !inventoryStockInput || !inventoryStatusInput || !inventoryCategoryInput || !inventoryDescriptionInput) return;

    const name = inventoryNameInput.value.trim();
    const price = Number(inventoryPriceInput.value);
    const stock = Number(inventoryStockInput.value);
    const category = inventoryCategoryInput.value || 'specials';
    const status = stock <= 0 ? 'Out of stock' : inventoryStatusInput.value;
    const description = inventoryDescriptionInput.value.trim();
    const specialImage = category === 'specials' ? selectedSpecialFoodImageData : '';
    const specialComponents = category === 'specials' ? normalizeSpecialComponents(selectedSpecialComponents) : [];

    if (!name || Number.isNaN(price) || Number.isNaN(stock)) {
        return;
    }

    const existingItem = inventoryData.find((item) => item.name.toLowerCase() === name.toLowerCase());

    if (existingItem) {
        existingItem.price = price;
        existingItem.stock = stock;
        existingItem.status = status;
        existingItem.category = category;
        existingItem.description = description;
        existingItem.components = specialComponents;
        saveMenuCatalogItem({ ...existingItem, description, image: specialImage || existingItem.image || '', components: specialComponents });
    } else {
        inventoryData.push({
            name,
            price,
            stock,
            status,
            category,
            description,
            components: specialComponents
        });
        saveMenuCatalogItem({ name, price, stock, status, category, description, image: specialImage || '', components: specialComponents });
    }

    inventoryEditItemName = null;
    if (inventorySaveBtn) {
        inventorySaveBtn.textContent = 'Save Inventory Item';
    }

    saveInventoryData();
    let syncSucceeded = false;
    try {
        const actor = getCurrentStaffActor();
        const response = await fetch(getApiUrl('api/update_inventory.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                name,
                previousName: existingItem ? existingItem.name : null,
                price,
                stock,
                status,
                category,
                description,
                actorRole: actor.role,
                actorEmail: actor.email
            })
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
    void loadOrderLogsFromServer(true);
    selectedSpecialFoodImageData = '';
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
    const descriptionInput = card.querySelector('[data-field="description"]');
    const statusInput = card.querySelector('[data-field="status"]');

    if (!nameInput || !categoryInput || !priceInput || !stockInput || !descriptionInput || !statusInput) return;

    const nextName = nameInput.value.trim();
    const price = Number(priceInput.value);
    const stock = Number(stockInput.value);
    const category = categoryInput.value || 'specials';
    const description = descriptionInput.value.trim();
    const status = stock <= 0 ? 'Out of stock' : statusInput.value;

    if (!nextName || Number.isNaN(price) || Number.isNaN(stock)) return;

    const previousSnapshot = {
        name: previousItem.name,
        price: previousItem.price,
        stock: previousItem.stock,
        status: previousItem.status,
        category: previousItem.category,
        description: previousItem.description,
    };

    previousItem.name = nextName;
    previousItem.price = price;
    previousItem.stock = stock;
    previousItem.status = status;
    previousItem.category = category;
    previousItem.description = description;

    saveMenuCatalogItem(previousItem, itemName);
    saveInventoryData();
    inventoryRefreshVersion += 1;
    let syncSucceeded = false;
    try {
        const actor = getCurrentStaffActor();
        const response = await fetch(getApiUrl('api/update_inventory.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                name: nextName,
                previousName: itemName,
                price,
                stock,
                status,
                category,
                description,
                actorRole: actor.role,
                actorEmail: actor.email
            })
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
        previousItem.description = previousSnapshot.description;
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
    void loadOrderLogsFromServer(true);
}

function renderOverviewAnalytics() {
    if (!overviewAnalyticsSelect || !overviewAnalyticsChart || !overviewMonthSelect || !overviewMonthWrapper) return;

    const view = overviewAnalyticsSelect.value;
    overviewMonthWrapper.style.display = view === 'monthly' ? 'none' : 'inline-flex';

    if (view === 'daily') {
        const month = overviewMonthSelect.value;
        const monthData = monthlySalesByMonth[month] || monthlySalesByMonth.jan;
        renderDetailChart(overviewAnalyticsChart, monthData, `Daily Sales — ${overviewMonthSelect.options[overviewMonthSelect.selectedIndex]?.text || ''}`);
        autoScrollChartToCurrentDay(overviewAnalyticsChart, month, monthData.length);
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

    const sections = [overviewSection, salesSection, pendingOrdersSection, inventorySection, logsSection, accountManagementSection, highlightsSection, credentialsSection];
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
        const description = getInventoryDescription(item.name, item.description || 'Tap the image to view full details.');
        const isOutOfStock = isItemOutOfStock(item.name);
        return `
        <article class="special-food-card${isOutOfStock ? ' is-out-of-stock' : ''}" data-name="${item.name}">
            <button type="button" class="special-food-view-btn" data-name="${item.name}" aria-label="View ${item.name} details">
                <img src="${imageSrc}" alt="${item.name}">
                <div class="special-food-image-meta">
                    <span class="special-food-image-name">${item.name}</span>
                </div>
            </button>
            ${isOutOfStock ? `<div class="stock-status-overlay"><img src="outofstock1.png" alt="Out of stock"><span>Out of stock</span></div>` : ''}
            <div class="special-food-details">
                <p class="special-food-description">${escapeHtml(description)}</p>
                <strong class="special-food-details-price">${formatCurrency(item.price)}</strong>
            </div>
        </article>
    `;
    }).join('');

    syncVisibleMenuItemQuantities();
}

function addToCart(item, quantityToAdd = 1) {
    if (!item) return;
    const requestedQuantity = Math.max(1, Number(quantityToAdd) || 1);
    const availableStock = getAvailableStockForItem(item.name);
    if (availableStock <= 0) {
        if (menuOrderMessage) {
            menuOrderMessage.textContent = `${item.name} has reached the available stock limit.`;
        }
        return;
    }

    const specialComponents = getSpecialFoodComponentsByName(item.name);
    const maxAdditionalByComponents = specialComponents.length
        ? specialComponents.reduce((minAllowed, component) => {
            const perDishQty = Math.max(0, Number(component.quantity) || 0);
            if (perDishQty <= 0) return minAllowed;

            const availableComponentStock = getAvailableStockForCartComponent(component.name);
            const maxByThisComponent = Math.floor(availableComponentStock / perDishQty);
            return Math.min(minAllowed, maxByThisComponent);
        }, Number.POSITIVE_INFINITY)
        : Number.POSITIVE_INFINITY;

    const quantity = Math.min(requestedQuantity, availableStock, maxAdditionalByComponents);
    if (quantity <= 0) {
        if (menuOrderMessage) {
            menuOrderMessage.textContent = `${item.name} cannot be added. ADD ON stock limit reached.`;
        }
        return;
    }

    const existing = cartItems.find((cartItem) => cartItem.name === item.name);

    if (existing) {
        if (!Array.isArray(existing.components) || !existing.components.length) {
            existing.components = buildInitialCartComponents(specialComponents, existing.quantity);
        }
        if (!Array.isArray(existing.baseComponents) || !existing.baseComponents.length) {
            existing.baseComponents = normalizeSpecialComponents(specialComponents);
        }
        applyBaseComponentsDeltaToCartItem(existing, quantity);
        existing.componentsOpen = Array.isArray(existing.components) && existing.components.length > 0;
        existing.componentsMode = 'total';
        existing.quantity += quantity;
    } else {
        cartItems.push({
            ...item,
            quantity,
            baseComponents: normalizeSpecialComponents(specialComponents),
            components: buildInitialCartComponents(specialComponents, quantity),
            componentsMode: 'total',
            componentsOpen: specialComponents.length > 0
        });
    }
    saveCart();
    updateCartDisplay();
    if (menuOrderMessage) {
        if (quantity < requestedQuantity || quantity < availableStock) {
            menuOrderMessage.textContent = `${item.name}: only ${quantity} item(s) were added due to stock limit.`;
        } else {
            menuOrderMessage.textContent = `${quantity} ${item.name} added to cart.`;
        }
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

    if (change > 0 && !canIncreaseCartItemQuantity(index)) {
        if (menuOrderMessage) {
            menuOrderMessage.textContent = `Cannot increase ${item.name}. ADD ON stock limit reached.`;
        }
        return;
    }

    const nextQuantity = item.quantity + change;
    if (nextQuantity < 1) return;

    applyBaseComponentsDeltaToCartItem(item, change);
    item.quantity = nextQuantity;
    saveCart();
    updateCartDisplay();
}

function toggleCartItemComponents(index) {
    if (Number.isNaN(index) || index < 0 || index >= cartItems.length) return;
    const item = cartItems[index];
    if (!getCartItemCustomizeOptions(item).length) return;

    item.componentsOpen = !item.componentsOpen;
    saveCart();
    updateCartDisplay();
}

function adjustCartItemComponentQuantity(index, componentName, change) {
    if (Number.isNaN(index) || index < 0 || index >= cartItems.length || !change) return;

    const item = cartItems[index];
    const normalizedName = normalizeInventoryName(componentName);
    if (!normalizedName) return;

    const currentQuantity = getCartItemComponentQuantity(item, componentName);
    const nextQuantity = currentQuantity + change;

    if (change > 0 && !canIncreaseCartComponentQuantity(index, componentName)) {
        if (menuOrderMessage) {
            menuOrderMessage.textContent = `Cannot add more ${componentName}. ADD ON stock limit reached.`;
        }
        return;
    }

    if (nextQuantity < 0) {
        return;
    }

    setCartItemComponentQuantity(item, componentName, nextQuantity);
    saveCart();
    updateCartDisplay();
}

function removeCartItemComponent(index, componentName) {
    if (Number.isNaN(index) || index < 0 || index >= cartItems.length) return;

    const item = cartItems[index];
    const normalizedName = normalizeInventoryName(componentName);
    if (!normalizedName) return;

    setCartItemComponentQuantity(item, componentName, 0);

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

    const canCompleteOrders = canManageOrders();

    pendingOrdersList.innerHTML = pendingOrders.map((order, index) => {
        const items = Array.isArray(order.items) ? order.items : [];
        const itemsHtml = items.map((item) => {
            const maxAllowed = getMaxEditablePendingQuantity(order.id, item);
            const canIncrease = canCompleteOrders && (Number(item.quantity) || 0) < maxAllowed;
            const canDecrease = canCompleteOrders && (Number(item.quantity) || 0) > 0;

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
                <p><strong>Submitted:</strong> ${formatRealtimeDate(order.timestamp)}</p>
                <p><strong>Payment:</strong> ${order.paymentMethod}</p>
                <ul>${itemsHtml}</ul>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                    <strong>Total: ${formatCurrency(order.total)}</strong>
                    ${canCompleteOrders ? `<button type="button" class="order-complete-btn" data-order-index="${index}">Mark Complete</button>` : ''}
                </div>
            </article>
        `;
    }).join('');
}

function setOrdersTab(tabName) {
    activeOrdersTab = tabName === 'pending' ? 'pending' : 'walk-in';

    if (walkInOrdersTabBtn) {
        const isActive = activeOrdersTab === 'walk-in';
        walkInOrdersTabBtn.classList.toggle('active', isActive);
        walkInOrdersTabBtn.setAttribute('aria-selected', String(isActive));
    }

    if (pendingOrdersTabBtn) {
        const isActive = activeOrdersTab === 'pending';
        pendingOrdersTabBtn.classList.toggle('active', isActive);
        pendingOrdersTabBtn.setAttribute('aria-selected', String(isActive));
    }

    if (walkInOrderPanel) {
        walkInOrderPanel.hidden = activeOrdersTab !== 'walk-in';
    }

    if (pendingOrdersPanel) {
        pendingOrdersPanel.hidden = activeOrdersTab !== 'pending';
    }
}

function setWalkInOrderMessage(message, isError = false) {
    if (!walkInOrderMessage) return;
    walkInOrderMessage.textContent = message || '';
    walkInOrderMessage.style.color = isError ? '#b00020' : '#0b6b2f';
}

function getAvailablePendingStockForItem(itemName) {
    const inventoryItem = getInventoryItem(itemName);
    if (!inventoryItem) return 0;

    const stock = Math.max(0, Number(inventoryItem.stock) || 0);
    const reserved = getReservedPendingQuantityForItem(itemName);
    return Math.max(0, stock - reserved);
}

function getWalkInDraftTotal() {
    return walkInDraftItems.reduce((sum, item) => sum + ((Number(item.price) || 0) * (Number(item.quantity) || 0)), 0);
}

function renderWalkInOrderBuilder() {
    if (walkInItemSelect) {
        const previousSelection = walkInItemSelect.value;
        const availableItems = (inventoryData || [])
            .filter((item) => getAvailablePendingStockForItem(item.name) > 0)
            .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));

        if (!availableItems.length) {
            walkInItemSelect.innerHTML = '<option value="">No available items</option>';
            walkInItemSelect.disabled = true;
        } else {
            walkInItemSelect.disabled = false;
            walkInItemSelect.innerHTML = availableItems.map((item) => {
                const available = getAvailablePendingStockForItem(item.name);
                return `<option value="${item.name}">${item.name} (${formatCurrency(item.price)} · stock ${available})</option>`;
            }).join('');

            const stillExists = availableItems.some((item) => item.name === previousSelection);
            walkInItemSelect.value = stillExists ? previousSelection : availableItems[0].name;
        }
    }

    if (!walkInDraftList) return;

    if (!walkInDraftItems.length) {
        walkInDraftList.innerHTML = '<p class="menu-cart-empty">No walk-in items yet. Add products to build the order.</p>';
        return;
    }

    walkInDraftList.innerHTML = `
        <div class="walkin-draft-items">
            ${walkInDraftItems.map((item, index) => {
                const available = getAvailablePendingStockForItem(item.name);
                const canIncrease = item.quantity < available;
                const canDecrease = item.quantity > 1;

                return `
                    <article class="walkin-draft-item-card">
                        <div>
                            <strong>${item.name}</strong>
                            <p>${formatCurrency(item.price)} each</p>
                        </div>
                        <div class="walkin-draft-actions">
                            <button type="button" class="walkin-draft-qty-btn" data-action="decrease" data-index="${index}"${canDecrease ? '' : ' disabled'}>−</button>
                            <span>${item.quantity}</span>
                            <button type="button" class="walkin-draft-qty-btn" data-action="increase" data-index="${index}"${canIncrease ? '' : ' disabled'}>+</button>
                            <button type="button" class="walkin-draft-remove-btn" data-index="${index}">Remove</button>
                        </div>
                    </article>
                `;
            }).join('')}
        </div>
        <p class="walkin-draft-total"><strong>Total:</strong> ${formatCurrency(getWalkInDraftTotal())}</p>
    `;
}

function addWalkInDraftItem() {
    if (!walkInItemSelect || walkInItemSelect.disabled) {
        setWalkInOrderMessage('No available inventory item for walk-in order.', true);
        return;
    }

    const itemName = (walkInItemSelect.value || '').trim();
    const quantity = Math.max(1, Number(walkInItemQtyInput ? walkInItemQtyInput.value : 1) || 1);
    const inventoryItem = getInventoryItem(itemName);

    if (!inventoryItem) {
        setWalkInOrderMessage('Selected inventory item was not found.', true);
        return;
    }

    const availableBeforeDraft = getAvailablePendingStockForItem(itemName);
    const existingIndex = walkInDraftItems.findIndex((item) => normalizeInventoryName(item.name) === normalizeInventoryName(itemName));
    const currentDraftQty = existingIndex >= 0 ? Number(walkInDraftItems[existingIndex].quantity) || 0 : 0;
    const maxAddable = Math.max(0, availableBeforeDraft - currentDraftQty);

    if (maxAddable <= 0) {
        setWalkInOrderMessage(`${itemName} has no remaining stock for new pending orders.`, true);
        return;
    }

    const qtyToAdd = Math.min(quantity, maxAddable);
    if (existingIndex >= 0) {
        walkInDraftItems[existingIndex].quantity += qtyToAdd;
    } else {
        walkInDraftItems.push({
            name: inventoryItem.name,
            price: Number(inventoryItem.price) || 0,
            quantity: qtyToAdd
        });
    }

    if (walkInItemQtyInput) {
        walkInItemQtyInput.value = '1';
    }

    if (quantity > qtyToAdd) {
        setWalkInOrderMessage(`Only ${qtyToAdd} item(s) were added due to stock limits.`, true);
    } else {
        setWalkInOrderMessage(`${inventoryItem.name} added to walk-in order.`);
    }

    renderWalkInOrderBuilder();
}

function adjustWalkInDraftItem(index, direction) {
    if (index < 0 || index >= walkInDraftItems.length) return;
    const entry = walkInDraftItems[index];
    const currentQty = Number(entry.quantity) || 0;

    if (direction === 'decrease') {
        entry.quantity = Math.max(1, currentQty - 1);
    } else {
        const available = getAvailablePendingStockForItem(entry.name);
        if (currentQty >= available) {
            setWalkInOrderMessage(`No more available stock for ${entry.name}.`, true);
            renderWalkInOrderBuilder();
            return;
        }
        entry.quantity = currentQty + 1;
    }

    renderWalkInOrderBuilder();
}

function removeWalkInDraftItem(index) {
    if (index < 0 || index >= walkInDraftItems.length) return;
    walkInDraftItems.splice(index, 1);
    renderWalkInOrderBuilder();
}

async function placeWalkInOrder() {
    if (!canManageOrders()) {
        setWalkInOrderMessage('Only cashier/admin can place walk-in orders.', true);
        return;
    }

    if (!walkInDraftItems.length) {
        setWalkInOrderMessage('Add at least one item to create a walk-in order.', true);
        return;
    }

    const hasInvalidQty = walkInDraftItems.some((item) => {
        const available = getAvailablePendingStockForItem(item.name);
        return (Number(item.quantity) || 0) > available;
    });

    if (hasInvalidQty) {
        setWalkInOrderMessage('One or more items exceed available stock. Adjust quantities and try again.', true);
        renderWalkInOrderBuilder();
        return;
    }

    const order = {
        orderNumber: generateOrderNumber(),
        id: Date.now(),
        timestamp: Date.now(),
        items: walkInDraftItems.map((item) => ({
            name: item.name,
            price: Number(item.price) || 0,
            quantity: Number(item.quantity) || 0
        })),
        total: getWalkInDraftTotal(),
        paymentMethod: walkInPaymentMethodSelect ? walkInPaymentMethodSelect.value || 'Cash' : 'Cash',
        orderType: walkInOrderTypeSelect ? walkInOrderTypeSelect.value || 'Walk-in Dine In' : 'Walk-in Dine In'
    };

    const syncedOrder = await submitOrderToServer(order);
    if (!syncedOrder) {
        setWalkInOrderMessage('Unable to submit walk-in order. Please try again.', true);
        return;
    }

    pendingOrders.unshift(syncedOrder);
    savePendingOrders();
    renderPendingOrders();
    renderOrderNotifications();
    walkInDraftItems = [];
    renderWalkInOrderBuilder();
    setOrdersTab('pending');
    setWalkInOrderMessage(`Walk-in order #${syncedOrder.orderNumber} created and moved to pending.`);
    void loadPendingOrdersFromServer();
}

let selectedPaymentMethod = 'Cash';
let selectedOrderType = 'Dine In';
let cartAddOnDraftQuantities = {};
let cartAddOnSearchQuery = '';
let cartAddOnDataRefreshInFlight = null;

function resetCartAddOnDraft() {
    cartAddOnDraftQuantities = {};
}

function getCurrentCartTotalAmount() {
    return cartItems.reduce((sum, item) => sum + getCartItemLineTotal(item), 0);
}

function getCartAddOnDraftTotal(addOnItems) {
    if (!Array.isArray(addOnItems) || !addOnItems.length) return 0;

    const priceByName = new Map(
        addOnItems.map((item) => [normalizeInventoryName(item.name), Math.max(0, Number(item.price) || 0)])
    );

    return Object.entries(cartAddOnDraftQuantities).reduce((sum, [normalizedName, rawQty]) => {
        const quantity = Math.max(0, Number(rawQty) || 0);
        if (quantity <= 0) return sum;

        const unitPrice = Math.max(0, Number(priceByName.get(normalizedName)) || 0);
        return sum + (quantity * unitPrice);
    }, 0);
}

function updateCartAddOnTotalDisplay(allAddOnItems) {
    if (!cartAddOnTotal) return;

    const combinedTotal = getCurrentCartTotalAmount() + getCartAddOnDraftTotal(allAddOnItems);
    cartAddOnTotal.innerHTML = `<span>Total if added</span><strong>${formatCurrency(combinedTotal)}</strong>`;
}

async function refreshCartAddOnData() {
    if (cartAddOnDataRefreshInFlight) {
        return cartAddOnDataRefreshInFlight;
    }

    cartAddOnDataRefreshInFlight = (async () => {
        try {
            await loadCustomMenuData();
        } catch (error) {
            console.error('Unable to refresh custom menu data for add on screen', error);
        }

        try {
            await initializeInventoryData(true);
        } catch (error) {
            console.error('Unable to refresh inventory data for add on screen', error);
        }
    })();

    try {
        await cartAddOnDataRefreshInFlight;
    } finally {
        cartAddOnDataRefreshInFlight = null;
    }
}

async function ensureCartAddOnItemsReady() {
    await refreshCartAddOnData();

    if (getAddOnInventoryItems().length > 0) {
        return;
    }

    // Hard fallback for first-open empty-cart scenarios where menu/inventory data has not settled yet.
    try {
        const inventoryUrl = getApiUrl(`api/get_inventory.php?_=${Date.now()}`);
        const response = await fetch(inventoryUrl, { cache: 'no-store' });
        if (response.ok) {
            const payload = await response.json();
            const items = Array.isArray(payload?.items) ? payload.items : [];
            if (items.length) {
                inventoryData = items.map((item) => ({
                    name: String(item?.name || '').trim(),
                    price: Number(item?.price) || 0,
                    stock: Number(item?.stock) || 0,
                    status: item?.status || (Number(item?.stock) > 0 ? 'In stock' : 'Out of stock'),
                    category: item?.category || resolveInventoryCategory(item?.name),
                    description: item?.description || ''
                })).filter((item) => item.name !== '');
                saveInventoryData();
            }
        }
    } catch (error) {
        console.error('Fallback inventory fetch for add on screen failed', error);
    }

    if (getAddOnInventoryItems().length > 0) {
        return;
    }

    try {
        const menuUrl = getApiUrl(`api/get_custom_menu.php?_=${Date.now()}`);
        const response = await fetch(menuUrl, { cache: 'no-store' });
        if (response.ok) {
            const payload = await response.json();
            if (payload?.success && payload?.snapshot) {
                applyCustomMenuSnapshot(payload.snapshot);
            }
        }
    } catch (error) {
        console.error('Fallback custom menu fetch for add on screen failed', error);
    }
}

function closeCartAddOnScreen() {
    if (!cartAddOnScreen) return;
    cartAddOnScreen.classList.add('hidden');
    cartAddOnScreen.setAttribute('aria-hidden', 'true');
    if (menuPlaceOrderBtn) {
        menuPlaceOrderBtn.classList.remove('hidden');
    }
    resetCartAddOnDraft();
    cartAddOnSearchQuery = '';
    if (cartAddOnSearchInput) {
        cartAddOnSearchInput.value = '';
    }
    if (cartAddOnMessage) {
        cartAddOnMessage.textContent = '';
    }
}

function renderCartAddOnScreen() {
    if (!cartAddOnList) return;

    const query = String(cartAddOnSearchQuery || '').trim().toLowerCase();

    const allAddOnItems = getAddOnInventoryItems()
        .map((item) => ({
            ...item,
            price: getResolvedAddOnPrice(item.name, item.price),
            stock: getAvailableStockForItem(item.name)
        }))
        .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));

    updateCartAddOnTotalDisplay(allAddOnItems);

    if (!allAddOnItems.length) {
        cartAddOnList.innerHTML = '<p class="menu-cart-empty">No add on items available.</p>';
        if (cartAddOnApplyBtn) {
            cartAddOnApplyBtn.disabled = true;
        }
        return;
    }

    const addOnItems = query
        ? allAddOnItems.filter((item) => String(item.name || '').toLowerCase().includes(query))
        : allAddOnItems;

    if (!addOnItems.length) {
        cartAddOnList.innerHTML = '<p class="menu-cart-empty">No add on items found.</p>';
        if (cartAddOnApplyBtn) {
            const hasSelected = Object.values(cartAddOnDraftQuantities).some((qty) => (Number(qty) || 0) > 0);
            cartAddOnApplyBtn.disabled = !hasSelected;
        }
        return;
    }

    cartAddOnList.innerHTML = addOnItems.map((item) => {
        const normalizedName = normalizeInventoryName(item.name);
        const selectedQty = Math.max(0, Number(cartAddOnDraftQuantities[normalizedName] || 0));
        const availableStock = Math.max(0, Number(item.stock) || 0);
        const maxAddable = Math.max(0, availableStock);
        return `
            <article class="cart-addon-item">
                <div class="cart-addon-item-head">
                    <strong>${escapeHtml(item.name)}</strong>
                    <span>${formatCurrency(item.price)}</span>
                </div>
                <div class="cart-addon-item-controls" data-name="${escapeHtml(item.name)}" data-max="${maxAddable}">
                    <button type="button" class="menu-item-qty-btn" data-action="decrease" ${selectedQty <= 0 ? 'disabled' : ''}>−</button>
                    <span class="menu-item-qty">${selectedQty}</span>
                    <button type="button" class="menu-item-qty-btn" data-action="increase" ${selectedQty >= maxAddable ? 'disabled' : ''}>+</button>
                </div>
                <div class="cart-addon-item-stock">Available: ${availableStock}</div>
            </article>
        `;
    }).join('');

    if (cartAddOnApplyBtn) {
        const hasSelected = Object.values(cartAddOnDraftQuantities).some((qty) => (Number(qty) || 0) > 0);
        cartAddOnApplyBtn.disabled = !hasSelected;
    }
}

async function openCartAddOnScreen() {
    if (!cartAddOnScreen) return;
    if (menuPlaceOrderBtn) {
        menuPlaceOrderBtn.classList.add('hidden');
    }
    if (cartAddOnSearchInput) {
        cartAddOnSearchInput.value = '';
    }
    cartAddOnSearchQuery = '';
    cartAddOnScreen.classList.remove('hidden');
    cartAddOnScreen.setAttribute('aria-hidden', 'false');

    if (cartAddOnList) {
        cartAddOnList.innerHTML = '<p class="menu-cart-empty">Loading add on items...</p>';
    }
    if (cartAddOnApplyBtn) {
        cartAddOnApplyBtn.disabled = true;
    }

    await ensureCartAddOnItemsReady();
    renderCartAddOnScreen();
}

function applySelectedCartAddOns() {
    const addOnItems = getAddOnInventoryItems();
    let addedGroups = 0;
    let addedUnits = 0;

    Object.entries(cartAddOnDraftQuantities).forEach(([normalizedName, rawQty]) => {
        const quantity = Math.max(0, Number(rawQty) || 0);
        if (quantity <= 0) return;

        const item = addOnItems.find((candidate) => normalizeInventoryName(candidate.name) === normalizedName);
        if (!item) return;

        addToCart({
            name: item.name,
            price: getResolvedAddOnPrice(item.name, item.price)
        }, quantity);
        addedGroups += 1;
        addedUnits += quantity;
    });

    if (cartAddOnMessage) {
        cartAddOnMessage.textContent = addedGroups > 0
            ? `${addedUnits} add on item(s) added to cart.`
            : 'Select add on quantity first.';
    }

    if (addedGroups > 0) {
        resetCartAddOnDraft();
        renderCartAddOnScreen();
    }
}

function openCheckoutScreen() {
    if (!cartItems.length || getCartPayableTotal() <= 0) return;
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

    const payableItems = getPayableCartItems();

    if (!payableItems.length) {
        orderCheckoutItems.innerHTML = '<p class="menu-cart-empty">No payable items in cart.</p>';
        orderCheckoutTotal.textContent = formatCurrency(0);
        if (confirmOrderBtn) {
            confirmOrderBtn.disabled = true;
        }
        return;
    }

    orderCheckoutItems.innerHTML = payableItems.map((item) => `
        <div class="order-checkout-item">
            <div>
                <strong>${item.name}</strong>
                <span>Qty: ${item.quantity}</span>
            </div>
            <div>${formatCurrency(getCartItemLineTotal(item))}</div>
        </div>
    `).join('');

    orderCheckoutTotal.textContent = formatCurrency(payableItems.reduce((sum, item) => sum + getCartItemLineTotal(item), 0));
    if (confirmOrderBtn) {
        confirmOrderBtn.disabled = false;
    }
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
        orderPaymentDatetime.textContent = formatRealtimeDate(order.timestamp);
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
    const payableItems = getPayableCartItems();
    const payableTotal = payableItems.reduce((sum, item) => sum + getCartItemLineTotal(item), 0);

    if (!payableItems.length || payableTotal <= 0) {
        if (menuOrderMessage) {
            menuOrderMessage.textContent = 'Add at least one payable item before proceeding to payment.';
        }
        return;
    }

    const order = {
        orderNumber: generateOrderNumber(),
        id: Date.now(),
        timestamp: Date.now(),
        items: payableItems.map((item) => ({
            name: item.name,
            price: getCartItemUnitPrice(item),
            quantity: item.quantity,
            components: getOrderComponents(item.components)
        })),
        total: payableTotal,
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
        const description = getInventoryDescription(item.name, item.description || 'Tap the image to view full details.');
        return `
        <article class="menu-item-card${isOutOfStock ? ' is-out-of-stock' : ''}">
            <div class="menu-item-main">
                <h4>${item.name}</h4>
                <p>${escapeHtml(description)}</p>
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
    closeCartAddOnScreen();
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
        const card = event.target.closest('.special-food-card');
        if (!card) return;

        const item = specialFoods.find((food) => food.name === card.dataset.name);
        if (!item) return;

        openProductDetailModal({
            name: item.name,
            price: Number(item.price) || 0,
            image: item.image || 'img1.jpg',
            description: item.description || ''
        });
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

if (menuAddOnQuickBtn) {
    menuAddOnQuickBtn.addEventListener('click', () => {
        openMenuOverlay();
        showMenuCategory('addons');
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
        const toggleButton = event.target.closest('.menu-cart-components-toggle');
        if (toggleButton) {
            const index = Number(toggleButton.dataset.index);
            toggleCartItemComponents(index);
            return;
        }

        const componentButton = event.target.closest('.menu-cart-component-btn');
        if (componentButton) {
            const index = Number(componentButton.dataset.index);
            const componentName = String(componentButton.dataset.componentName || '').trim();
            const change = componentButton.dataset.action === 'component-increase' ? 1 : -1;
            adjustCartItemComponentQuantity(index, componentName, change);
            return;
        }

        const removeComponentButton = event.target.closest('.menu-cart-component-remove');
        if (removeComponentButton) {
            const index = Number(removeComponentButton.dataset.index);
            const componentName = String(removeComponentButton.dataset.componentName || '').trim();
            removeCartItemComponent(index, componentName);
            return;
        }

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

if (cartAddOnBtn) {
    cartAddOnBtn.addEventListener('click', openCartAddOnScreen);
}

if (cartAddOnCloseBtn) {
    cartAddOnCloseBtn.addEventListener('click', closeCartAddOnScreen);
}

if (cartAddOnList) {
    cartAddOnList.addEventListener('click', (event) => {
        const controls = event.target.closest('.cart-addon-item-controls');
        if (!controls) return;

        const name = String(controls.dataset.name || '').trim();
        const max = Math.max(0, Number(controls.dataset.max || 0));
        const normalizedName = normalizeInventoryName(name);
        if (!name || !normalizedName) return;

        const actionBtn = event.target.closest('button[data-action]');
        if (!actionBtn) return;

        const action = actionBtn.dataset.action;
        const current = Math.max(0, Number(cartAddOnDraftQuantities[normalizedName] || 0));

        if (action === 'increase') {
            cartAddOnDraftQuantities[normalizedName] = Math.min(max, current + 1);
            renderCartAddOnScreen();
            return;
        }

        if (action === 'decrease') {
            cartAddOnDraftQuantities[normalizedName] = Math.max(0, current - 1);
            renderCartAddOnScreen();
            return;
        }
    });
}

if (cartAddOnApplyBtn) {
    cartAddOnApplyBtn.addEventListener('click', applySelectedCartAddOns);
}

if (cartAddOnSearchInput) {
    cartAddOnSearchInput.addEventListener('input', () => {
        cartAddOnSearchQuery = cartAddOnSearchInput.value || '';
        renderCartAddOnScreen();
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
            if (!canAccessInventory()) return;
            showDashboardSection(inventorySection);
            renderOverviewInventory();
        } else if (href === '#pending-orders') {
            if (!canManageOrders()) return;
            showDashboardSection(pendingOrdersSection);
            setOrdersTab('walk-in');
            renderWalkInOrderBuilder();
            void loadPendingOrdersFromServer();
            renderPendingOrders();
        } else if (href === '#sales') {
            showDashboardSection(salesSection);
            updateAnalyticsView();
        } else if (href === '#logs') {
            if (!canAccessLogs()) return;
            showDashboardSection(logsSection);
            void loadOrderLogsFromServer(true);
        } else if (href === '#account-management') {
            if (!canManageAccounts()) {
                return;
            }
            showDashboardSection(accountManagementSection);
        } else if (href === '#highlights') {
            if (!canManageHighlights()) {
                return;
            }
            showDashboardSection(highlightsSection);
            renderHighlightsManagement();
        } else if (href === '#credentials') {
            if (!canAccessCredentials()) {
                return;
            }
            showDashboardSection(credentialsSection);
            void loadAdminCredentials();
        }

        setDashboardPanelState(false);
    });
}

if (overviewOrderNotificationList) {
    overviewOrderNotificationList.addEventListener('click', async (event) => {
        if (!canManageOrders()) return;
        const button = event.target.closest('.order-complete-btn');
        if (!button) return;
        const orderId = button.dataset.orderId;
        const orderIndex = pendingOrders.findIndex((order) => order.id === Number(orderId));
        await markPendingOrderAsComplete(orderIndex, false);
    });
}

if (pendingOrdersList) {
    pendingOrdersList.addEventListener('click', async (event) => {
        if (!canManageOrders()) return;
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

if (walkInOrdersTabBtn) {
    walkInOrdersTabBtn.addEventListener('click', () => {
        setOrdersTab('walk-in');
        renderWalkInOrderBuilder();
    });
}

if (pendingOrdersTabBtn) {
    pendingOrdersTabBtn.addEventListener('click', () => {
        setOrdersTab('pending');
        renderPendingOrders();
    });
}

if (walkInAddItemBtn) {
    walkInAddItemBtn.addEventListener('click', addWalkInDraftItem);
}

if (walkInDraftList) {
    walkInDraftList.addEventListener('click', (event) => {
        const qtyButton = event.target.closest('.walkin-draft-qty-btn');
        if (qtyButton) {
            const index = Number(qtyButton.dataset.index);
            const action = qtyButton.dataset.action;
            adjustWalkInDraftItem(index, action);
            return;
        }

        const removeButton = event.target.closest('.walkin-draft-remove-btn');
        if (!removeButton) return;
        const index = Number(removeButton.dataset.index);
        removeWalkInDraftItem(index);
    });
}

if (walkInPlaceOrderBtn) {
    walkInPlaceOrderBtn.addEventListener('click', () => {
        void placeWalkInOrder();
    });
}

function initOrders() {
    void ensureCsrfToken();
    void loadHighlightsFromServer();
    loadCart();
    loadPendingOrders();
    loadIgnoredPendingOrders();
    loadCompletedOrders();
    syncAnalyticsMonthSelectorsToCurrentMonth();
    loadCustomMenuData();
    startInventoryAutoRefresh();
    void initializeInventoryData();
    recalculateSalesAnalytics();
    renderSpecialFoods();
    updateCartDisplay();
    renderWalkInOrderBuilder();
    setOrdersTab('walk-in');
    renderPendingOrders();
    renderOrderNotifications();
    renderOverviewInventory();
    renderInventoryManagement();
    updateAnalyticsView();
    renderOverviewAnalytics();
    updateLiveClock();
    setInterval(updateLiveClock, 1000);
    void loadPendingOrdersFromServer();
    void loadReviewsFromServer();
    startReviewRefresh();

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
        void loadHighlightsFromServer();
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
        void loadHighlightsFromServer();
    }

    if (!enableInventoryAutoRefresh) return;
    if (!inventoryEditLock) {
        void initializeInventoryData();
    }
});

initOrders();
restoreStaffSession();
updateAccountManagementAccess();
