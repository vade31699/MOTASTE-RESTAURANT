const openModalBtn = document.getElementById('openModalBtn');
const closeModalBtn = document.getElementById('closeModalBtn');
const modal = document.getElementById('adminModal');
const roleButtons = document.querySelectorAll('.role-tab');
const loginFields = document.getElementById('loginFields');
const selectedRoleInput = document.getElementById('selectedRole');
const modalTitle = document.getElementById('modalTitle');
const emailInput = document.getElementById('email');
const passwordInput = document.getElementById('password');
const passwordToggleBtn = document.querySelector('[data-password-toggle]');
const rememberCheckbox = document.getElementById('rememberCredentials');
const logoutBtn = document.getElementById('logoutBtn');
const menuBtn = document.getElementById('menuBtn');
const dashboardPanel = document.getElementById('dashboardPanel');
const closePanelBtn = document.getElementById('closePanelBtn');
const dashboardUserName = document.getElementById('dashboardUserName');
const dashboardUserEmail = document.getElementById('dashboardUserEmail');
const staffForm = document.getElementById('staffLoginForm');
const staffLoginPage = document.querySelector('.staff-login-page');

if (passwordInput && passwordToggleBtn) {
    passwordToggleBtn.addEventListener('click', () => {
        const isHidden = passwordInput.type === 'password';
        passwordInput.type = isHidden ? 'text' : 'password';
        passwordToggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        passwordToggleBtn.innerHTML = isHidden
            ? '<i class="fa-solid fa-eye-slash" aria-hidden="true"></i>'
            : '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
    });
}
const allowedRoles = ['Admin', 'Cashier', 'Inventory Manager'];
const staffSessionStorageKey = 'motasteStaffSession';
const staffActiveSectionStorageKey = 'motasteStaffActiveSection';
const staffAccountsStorageKey = 'motasteStaffAccounts';
const lastLoginRoleStorageKey = 'motasteLastLoginRole';
let inventorySyncInFlight = false;
let inventoryLoadedFromServer = false;
let lastInventoryUpdateAt = 0;
let staffAccountsSyncInFlight = false;
let staffAccountsRefreshTimer = null;
let skipNextLogoutValidation = false;
let orderLogsRefreshTimer = null;
let orderLogsSyncInFlight = false;
let reviewRefreshTimer = null;
let orderActivityLogs = [];
let reviewActivityLogs = [];
let activeOrderLogFilter = 'all';
let pendingOrdersRefreshTimer = null;
let pendingOrdersCountdownTicker = null;
const staffOrderTimerCacheKey = 'motasteStaffOrderTimerCache';
let staffOrderTimerCache = new Map();
const blockedProductNames = new Set(['softdrinks']);
const isStaffPage = Boolean(document.getElementById('accountList') || document.getElementById('staffLoginForm'));

const adminDefaultEmail = '';
const adminDefaultPassword = '';

const defaultStaffAccounts = [];

let accounts = [...defaultStaffAccounts];
window.motasteStaffAccounts = accounts;

function normalizeStaffAccount(account) {
    if (!account || typeof account !== 'object') return null;

    const name = (account.name || '').trim();
    const role = (account.role || '').trim();
    const email = (account.email || '').trim().toLowerCase();
    const password = (account.password || '').toString();
    const inviteConfirmed = account.role === 'Admin' ? true : Boolean(account.inviteConfirmed);

    if (!name || !role || !email) return null;
    if (!allowedRoles.includes(role)) return null;

    return { name, role, email, password, inviteConfirmed };
}

function getCurrentStaffAccounts() {
    return Array.isArray(accounts) ? accounts : [];
}

function getLoggedInStaffSession() {
    const persistedSession = getPersistedStaffSession();
    if (!persistedSession || !persistedSession.email || !persistedSession.sessionToken || !persistedSession.role) {
        return null;
    }

    return {
        email: persistedSession.email.trim().toLowerCase(),
        role: persistedSession.role.trim(),
        sessionToken: persistedSession.sessionToken
    };
}

function ensureAdminAccountInvariant() {
    const adminIndex = accounts.findIndex((account) => account.role === 'Admin');
    if (adminIndex >= 0) {
        accounts[adminIndex].inviteConfirmed = true;
        if (adminDefaultEmail && !accounts[adminIndex].email) accounts[adminIndex].email = adminDefaultEmail;
        if (adminDefaultPassword && !accounts[adminIndex].password) accounts[adminIndex].password = adminDefaultPassword;
        return;
    }

    if (adminDefaultEmail || adminDefaultPassword) {
        accounts.unshift({
            name: 'Administrator',
            role: 'Admin',
            email: adminDefaultEmail,
            password: adminDefaultPassword,
            inviteConfirmed: true
        });
    }
}

function saveStaffAccountsToStorage() {
    // Staff accounts are persisted by the server. Local storage is disabled.
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
    return true;
}

async function loadStaffAccountsFromServer(forceRefresh = false) {
    if (staffAccountsSyncInFlight && !forceRefresh) return false;

    staffAccountsSyncInFlight = true;
    try {
        // This endpoint is gated by the server session; wait for the page-load
        // session renewal so the first fetch does not 401.
        if (isStaffPage) {
            await ensureStaffServerSession();
        }

        const response = await fetch(getApiUrl(`api/get_staff_accounts.php?_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) return false;

        const payload = await response.json();
        console.debug('submitOrderToServer: response', { status: response.status, payload });
        if (!payload || payload.success !== true) return false;

        if (Array.isArray(payload.accounts)) {
            const changed = applyStaffAccountsSnapshot(payload.accounts);
            if (changed) {
                renderAccounts();
            }
            await enforceActiveSessionValidity();
            return changed;
        }

        await enforceActiveSessionValidity();
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
    if (dashboardPanel) {
        dashboardPanel.style.display = 'none';
    }
    document.body.classList.remove('dashboard-panel-open');

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

function setPublicSectionsVisible(visible) {
    const selectors = ['.front-section', '.about-section', '.menu-section', '#about', '#contact', '.special-foods-section'];
    selectors.forEach((sel) => {
        try {
            const el = document.querySelector(sel);
            if (!el) return;
            el.hidden = !visible;
            el.style.display = visible ? '' : 'none';
        } catch (e) {
            // ignore
        }
    });
}

async function enforceActiveSessionValidity() {
    // Sessions are terminated only by manual logout (or when the staff account
    // no longer exists on the server). This also re-applies the authenticated
    // UI after a refresh once the staff account snapshot has finished loading.
    const session = getPersistedStaffSession();
    if (!session) return;
    if (getCurrentStaffAccounts().length === 0) return;

    const { role, email } = session;
    const accountStillExists = getCurrentStaffAccounts().some((account) =>
        account.email.toLowerCase() === email.toLowerCase() && account.role === role
    );
    if (!accountStillExists) {
        clearStaffSession();
        forceLogoutCurrentStaffSession();
        return;
    }

    if (typeof document !== 'undefined' && !document.body.classList.contains('auth')) {
        restoreStaffSession();
    }
}

async function saveStaffAccountsToServer() {
    saveStaffAccountsToStorage();
    window.motasteStaffAccounts = accounts;

    try {
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });

        const response = await fetch(getApiUrl('api/save_staff_accounts.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify(accounts),
            cache: 'no-store'
        });

        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.success !== true) {
            console.error('Unable to sync staff accounts to server', response.statusText || payload);
            return {
                success: false,
                error: (payload && payload.error) ? payload.error : response.statusText || 'Unknown error',
            };
        }

        return { success: true };
    } catch (error) {
        console.error('Unable to sync staff accounts to server', error);
        return {
            success: false,
            error: error instanceof Error ? error.message : String(error),
        };
    }
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

function normalizeImageUrl(url) {
    if (!url || typeof url !== 'string') return '';
    const trimmed = url.trim();
    if (!trimmed) return '';
    if (/^data:/i.test(trimmed)) {
        return trimmed;
    }
    if (/^https?:\/\//i.test(trimmed)) {
        return trimmed;
    }
    if (trimmed.startsWith('//')) {
        return `${window.location.protocol}${trimmed}`;
    }
    if (trimmed.startsWith('/')) {
        return `${window.location.origin}${trimmed}`;
    }
    try {
        return new URL(trimmed, window.location.href).toString();
    } catch (error) {
        return trimmed;
    }
}

let csrfToken = '';
let csrfTokenFetchedAt = 0;

// Server-side tokens expire after 8 hours; refresh proactively at half that so
// a long-idle tab never hits an expired token (the 419 self-heal is the
// safety net for anything that slips through).
const CSRF_REFRESH_AFTER_MS = 4 * 60 * 60 * 1000;

/**
 * Replace the cached CSRF token. The server session can be regenerated by
 * login / device verification / session renewal; the token must be re-adopted
 * from that new session or every later POST fails with "Invalid CSRF token".
 */
function adoptCsrfToken(token) {
    const next = String(token || '').trim();
    if (next) {
        csrfToken = next;
        csrfTokenFetchedAt = Date.now();
    }
    return csrfToken;
}

async function ensureCsrfToken() {
    // If the staff session is being renewed, wait for it: the token must come
    // from the POST-renewal session, otherwise it is orphaned by the renewal.
    if (isStaffPage && staffServerSessionRenewal) {
        await staffServerSessionRenewal;
    }

    // Refresh a stale token even when we already have one cached.
    if (csrfToken && Date.now() - csrfTokenFetchedAt < CSRF_REFRESH_AFTER_MS) {
        return csrfToken;
    }

    try {
        const response = await fetch(getApiUrl(`api/get_csrf_token.php?_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) return csrfToken;
        const payload = await response.json().catch(() => ({}));
        csrfToken = String(payload.csrfToken || '').trim();
        csrfTokenFetchedAt = Date.now();
        return csrfToken;
    } catch (error) {
        return csrfToken;
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

// ---------------------------------------------------------------------------
// CSRF self-heal: serverless platforms (Laravel Cloud) can route requests to
// different containers, and the CSRF token is now stateless but bound to the
// browser's session ID. If the server ever answers 419 (expired token, or the
// token predates a session regeneration), refresh the token once and retry the
// request automatically — the user never sees "Invalid CSRF token".
// ---------------------------------------------------------------------------
const nativeFetchForCsrf = window.fetch.bind(window);
window.fetch = async function fetchWithCsrfSelfHeal(input, init) {
    const response = await nativeFetchForCsrf(input, init);

    const method = String(((init && init.method) || 'GET')).toUpperCase();
    if (response.status !== 419 || method === 'GET') {
        return response;
    }

    try {
        // Force a fresh token from the current session, then retry exactly once.
        csrfToken = '';
        const fresh = await ensureCsrfToken();
        if (!fresh) {
            return response;
        }
        const headers = new Headers((init && init.headers) || {});
        headers.set('X-CSRF-TOKEN', fresh);
        return await nativeFetchForCsrf(input, { ...init, headers });
    } catch (error) {
        console.debug('CSRF self-heal retry failed', error);
        return response;
    }
};

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

/**
 * Revoke the session token on the server and destroy the PHP session so a
 * logout actually ends the session everywhere.
 */
async function revokeStaffSessionOnServer(sessionToken) {
    if (!sessionToken) return;
    try {
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });
        await fetchWithTimeout(getApiUrl('api/logout_staff.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify({ sessionToken }),
            cache: 'no-store'
        });
    } catch (error) {
        console.debug('Unable to revoke staff session on server', error);
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

function readStaffSessionFrom(storage) {
    if (!storage) return null;
    try {
        const raw = storage.getItem(staffSessionStorageKey);
        if (!raw) return null;
        const session = JSON.parse(raw);
        if (!session || typeof session !== 'object') return null;
        return session;
    } catch (error) {
        return null;
    }
}

function getPersistedStaffSession() {
    if (typeof window === 'undefined') {
        return null;
    }

    // Remembered sessions live in localStorage; per-tab sessions in sessionStorage.
    const session = readStaffSessionFrom(sessionStorage) || readStaffSessionFrom(window.localStorage);
    if (!session) {
        return null;
    }

    // Security: sessions must be token-based. Legacy sessions stored the
    // plaintext password — purge them and require a fresh login.
    if (!session.sessionToken) {
        clearStaffSession();
        return null;
    }

    return {
        role: session.role || '',
        email: session.email || '',
        sessionToken: session.sessionToken || '',
        remember: Boolean(session.remember)
    };
}

function saveStaffSession(role, email, sessionToken, remember) {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        const session = {
            role: role ? role.trim() : '',
            email: email ? email.trim().toLowerCase() : '',
            sessionToken: sessionToken || '',
            remember: Boolean(remember),
            savedAt: new Date().toISOString()
        };

        if (!session.role || !session.email || !session.sessionToken) {
            clearStaffSession();
            return;
        }

        // Remembered sessions persist across browser restarts (localStorage);
        // otherwise the token lives for the tab (sessionStorage). Only the
        // opaque session token is ever stored — never the password.
        const storage = remember
            ? window.localStorage
            : (typeof sessionStorage !== 'undefined' ? sessionStorage : window.localStorage);
        try {
            (storage === window.localStorage ? sessionStorage : window.localStorage).removeItem(staffSessionStorageKey);
        } catch (storageError) {
            // best effort
        }
        storage.setItem(staffSessionStorageKey, JSON.stringify(session));
        window.localStorage.setItem(lastLoginRoleStorageKey, session.role);
    } catch (error) {
        console.warn('Unable to persist staff session', error);
    }
}

function clearStaffSession() {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.removeItem(staffSessionStorageKey);
        window.localStorage.removeItem(lastLoginRoleStorageKey);
        if (typeof sessionStorage !== 'undefined') {
            sessionStorage.removeItem(staffSessionStorageKey);
        }
    } catch (error) {
        console.warn('Unable to clear persisted staff session', error);
    }

    // A cleared session invalidates any cached renewal result — the next
    // ensureStaffServerSession() call must attempt a fresh renewal.
    staffServerSessionRenewal = null;
}

function saveActiveSection(sectionId) {
    // Active section persistence is disabled.
}

function getPersistedActiveSection() {
    return 'overview';
}

function restoreStaffSession() {
    const persistedSession = getPersistedStaffSession();
    if (!persistedSession) {
        forceLogoutCurrentStaffSession();
        return false;
    }

    const { role, email } = persistedSession;
    if (!role || !email || !allowedRoles.includes(role)) {
        clearStaffSession();
        forceLogoutCurrentStaffSession();
        return false;
    }

    // Staff accounts load asynchronously from the server. If they have not
    // arrived yet, restore the authenticated UI immediately and re-validate
    // once the snapshot lands (see enforceActiveSessionValidity).
    const accountsLoaded = getCurrentStaffAccounts().length > 0;
    if (accountsLoaded) {
        const accountStillExists = getCurrentStaffAccounts().some((account) =>
            account.email.toLowerCase() === email.toLowerCase() && account.role === role
        );
        if (!accountStillExists) {
            clearStaffSession();
            forceLogoutCurrentStaffSession();
            return false;
        }
    }

    if (selectedRoleInput) {
        selectedRoleInput.value = role;
    }
    if (emailInput) {
        emailInput.value = email;
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
    // Skip premature render if inventory hasn't loaded yet —
    // initializeInventoryData() will call renderInventoryManagement() when ready.
    if (inventoryData.length > 0) {
        renderInventoryManagement();
    }
    setAuthButtonsVisible(true);
    if (dashboardPanel) {
        dashboardPanel.style.display = '';
    }

    const targetSectionId = resolveAccessibleSection(getPersistedActiveSection());
    const targetSection = document.getElementById(targetSectionId);
    if (targetSection) {
        showDashboardSection(targetSection);
        if (targetSectionId === 'overview') {
            renderOverviewAnalytics();
            renderOrderNotifications();
        } else if (targetSectionId === 'pending-orders') {
            void loadPendingOrdersFromServer();
            setOrdersTab('pending');
            renderWalkInOrderBuilder();
            renderPendingOrders();
        } else if (targetSectionId === 'sales') {
            updateAnalyticsView();
            updateProfitView();
            renderInsights();
        } else if (targetSectionId === 'inventory') {
        } else if (targetSectionId === 'logs') {
            void loadOrderLogsFromServer(true);
        }
    } else {
        showDashboardSection(overviewSection);
        renderOverviewAnalytics();
    }

    setDashboardPanelState(false);

    // Restored sessions land directly on the dashboard — cover the reveal
    // with the loading overlay until the initial data has settled.
    showStaffLoadingOverlay();

    return true;
}

// Full-screen loading overlay shown after login / session restore until the
// initial dashboard fetches settle. Falls back to a short timeout so the
// overlay can never block the dashboard indefinitely.
function showStaffLoadingOverlay() {
    const overlay = document.getElementById('staffLoadingOverlay');
    if (!overlay) return;

    overlay.hidden = false;
    Promise.resolve(staffInitialDataReady).then(() => {
        // Only hide if the overlay is still visible (e.g. a later login did
        // not re-show it while this one was settling).
        if (!overlay.hidden) {
            overlay.hidden = true;
        }
    });
}

function syncSelectedRoleWithTypedEmail(email) {
    if (!selectedRoleInput) {
        return;
    }

    const normalizedEmail = (email || '').trim().toLowerCase();
    if (!normalizedEmail) {
        selectedRoleInput.value = '';
        return;
    }

    const matchingAccount = getCurrentStaffAccounts().find((account) => account.email.toLowerCase() === normalizedEmail);
    if (matchingAccount) {
        selectedRoleInput.value = matchingAccount.role;
        return;
    }

    const currentRole = (selectedRoleInput.value || '').trim();
    if (currentRole && !allowedRoles.includes(currentRole)) {
        selectedRoleInput.value = '';
    }
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
        menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
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

const DEVICE_TOKEN_STORAGE_KEY = 'motaste_device_token';

function getOrCreateDeviceToken() {
    try {
        let token = localStorage.getItem(DEVICE_TOKEN_STORAGE_KEY);
        if (!token) {
            token = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
                ? crypto.randomUUID()
                : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
            localStorage.setItem(DEVICE_TOKEN_STORAGE_KEY, token);
        }
        return token;
    } catch (error) {
        return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    }
}

function fetchWithTimeout(url, options = {}, timeoutMs = 30000) {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    return fetch(url, { ...options, signal: controller.signal }).finally(() => window.clearTimeout(timer));
}

async function authenticateStaffAccount(email, password, role = '', deviceToken = '', silentRefresh = false) {
    try {
        const body = { email, password, role, deviceToken };
        if (silentRefresh) body.silentRefresh = true;
        const response = await fetchWithTimeout(getApiUrl('api/authenticate_staff.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(body),
            cache: 'no-store'
        });

        const payload = await response.json().catch(() => ({}));
        // A 2xx response with needsDeviceVerification=true is a valid state:
        // credentials were correct but the device must be confirmed first.
        if (!response.ok) {
            // Preserve rate-limit, auth-required, and remaining-attempt responses
            // instead of swallowing them into a generic "Invalid credentials"
            // error, so staff can see the lockout countdown and message.
            if (payload && (payload.rateLimited || payload.authRequired || payload.remainingAttempts != null)) {
                return {
                    success: false,
                    error: payload.error || `HTTP ${response.status}`,
                    rateLimited: Boolean(payload.rateLimited),
                    authRequired: Boolean(payload.authRequired),
                    remainingAttempts: payload.remainingAttempts != null ? Number(payload.remainingAttempts) : null
                };
            }
            return null;
        }

        return payload;
    } catch (error) {
        console.error('Staff authentication failed', error);
        return null;
    }
}

async function verifyDeviceLogin(email, password, code, deviceToken) {
    try {
        const response = await fetchWithTimeout(getApiUrl('api/verify_device_login.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ email, password, code, deviceToken }),
            cache: 'no-store'
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            return null;
        }

        return payload;
    } catch (error) {
        console.error('Device verification failed', error);
        return null;
    }
}

let staffServerSessionRenewal = null;

/**
 * Re-establishes the server-side staff session on page load using the persisted
 * opaque session token (no plaintext password is ever stored or re-sent). The
 * staff-only API gate (requireStaffAuth) needs the PHP session cookie; this
 * self-heals it after browser restarts without touching the login history.
 *
 * Returns a promise so staff-scoped fetches (e.g. inventory) can wait for the
 * session cookie to be valid before calling gated endpoints.
 */
function ensureStaffServerSession() {
    if (staffServerSessionRenewal) return staffServerSessionRenewal;

    const session = getPersistedStaffSession();
    if (!session || !session.email || !session.sessionToken) {
        staffServerSessionRenewal = Promise.resolve(false);
        return staffServerSessionRenewal;
    }

    staffServerSessionRenewal = (async () => {
        try {
            const response = await fetchWithTimeout(getApiUrl('api/renew_staff_session.php'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ sessionToken: session.sessionToken }),
                cache: 'no-store'
            });
            const payload = await response.json().catch(() => ({}));
            if (response.ok && payload && payload.success) {
                // The renewal regenerates the session ID; adopt the token issued
                // by the renewed session so later POSTs (Prepare, delete, save,
                // revoke...) pass CSRF validation.
                if (payload.csrfToken) {
                    adoptCsrfToken(payload.csrfToken);
                }
                return true;
            }

            // Token invalid/expired or the account was removed: end the session
            // and return to the login screen.
            clearStaffSession();
            forceLogoutCurrentStaffSession();
            return false;
        } catch (error) {
            console.debug('Unable to renew staff server session', error);
            return false;
        }
    })();

    return staffServerSessionRenewal;
}

/* ---- Device verification modal (replaces native prompt) ---- */
const deviceVerifyModal = document.getElementById('deviceVerifyModal');
const deviceVerifyConfirmStep = document.getElementById('deviceVerifyConfirmStep');
const deviceVerifyCodeStep = document.getElementById('deviceVerifyCodeStep');
const deviceVerifyTitle = document.getElementById('deviceVerifyTitle');
const deviceVerifyText = document.getElementById('deviceVerifyText');
const deviceVerifyCancelBtn = document.getElementById('deviceVerifyCancelBtn');
const deviceVerifySendBtn = document.getElementById('deviceVerifySendBtn');
const deviceVerifyBackBtn = document.getElementById('deviceVerifyBackBtn');
const deviceVerifySubmitBtn = document.getElementById('deviceVerifySubmitBtn');
const deviceVerifyCloseBtn = document.getElementById('deviceVerifyCloseBtn');
const deviceVerifyCodeInput = document.getElementById('deviceVerifyCodeInput');
const deviceVerifyMessage = document.getElementById('deviceVerifyMessage');

let deviceVerifyResolver = null;
let deviceVerifyWarningText = '';

function openDeviceVerifyModal() {
    if (!deviceVerifyModal) return;
    deviceVerifyModal.hidden = false;
    deviceVerifyModal.classList.add('active');
    deviceVerifyModal.setAttribute('aria-hidden', 'false');
}

function closeDeviceVerifyModal() {
    if (!deviceVerifyModal) return;
    deviceVerifyModal.hidden = true;
    deviceVerifyModal.classList.remove('active');
    deviceVerifyModal.setAttribute('aria-hidden', 'true');
}

function resetDeviceVerifyModal() {
    if (deviceVerifyConfirmStep) deviceVerifyConfirmStep.hidden = false;
    if (deviceVerifyCodeStep) deviceVerifyCodeStep.hidden = true;
    if (deviceVerifyCodeInput) deviceVerifyCodeInput.value = '';
    if (deviceVerifyMessage) deviceVerifyMessage.textContent = '';
    deviceVerifyWarningText = '';
    if (deviceVerifyText) {
        deviceVerifyText.textContent = 'Send a confirmation code to your email to continue?';
    }
}

function showDeviceVerifyCodeStep() {
    if (deviceVerifyConfirmStep) deviceVerifyConfirmStep.hidden = true;
    if (deviceVerifyCodeStep) deviceVerifyCodeStep.hidden = false;
    if (deviceVerifyMessage && deviceVerifyWarningText) {
        deviceVerifyMessage.textContent = deviceVerifyWarningText;
    }
    if (deviceVerifyCodeInput) deviceVerifyCodeInput.focus();
}

/**
 * Promise-based replacement for window.prompt() during device verification.
 * Resolves with the entered code string, or null when the user cancels.
 * When the verification email could not be delivered, warningMessage is shown
 * so staff know the code was written to the server log instead.
 */
function requestDeviceVerificationCode(warningMessage) {
    if (!deviceVerifyModal) return Promise.resolve(null);
    return new Promise((resolve) => {
        deviceVerifyResolver = resolve;
        resetDeviceVerifyModal();
        if (warningMessage && deviceVerifyText) {
            deviceVerifyWarningText = warningMessage;
            deviceVerifyText.textContent = warningMessage;
        }
        openDeviceVerifyModal();
    });
}

function resolveDeviceVerify(value) {
    closeDeviceVerifyModal();
    resetDeviceVerifyModal();
    const resolve = deviceVerifyResolver;
    deviceVerifyResolver = null;
    if (resolve) resolve(value);
}

if (deviceVerifyCancelBtn) {
    deviceVerifyCancelBtn.addEventListener('click', () => resolveDeviceVerify(null));
}

if (deviceVerifyCloseBtn) {
    deviceVerifyCloseBtn.addEventListener('click', () => resolveDeviceVerify(null));
}

if (deviceVerifySendBtn) {
    deviceVerifySendBtn.addEventListener('click', () => showDeviceVerifyCodeStep());
}

if (deviceVerifyBackBtn) {
    deviceVerifyBackBtn.addEventListener('click', () => resolveDeviceVerify(null));
}

if (deviceVerifySubmitBtn) {
    deviceVerifySubmitBtn.addEventListener('click', () => {
        const code = deviceVerifyCodeInput ? deviceVerifyCodeInput.value.trim() : '';
        if (!code) {
            if (deviceVerifyMessage) deviceVerifyMessage.textContent = 'Enter the 6-digit code sent to your email.';
            return;
        }
        resolveDeviceVerify(code);
    });
}

if (deviceVerifyCodeInput) {
    deviceVerifyCodeInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (deviceVerifySubmitBtn) deviceVerifySubmitBtn.click();
        }
    });
}

/* ---- Invite verification modal (replaces native prompt) ---- */
const inviteVerifyModal = document.getElementById('inviteVerifyModal');
const inviteVerifyTitle = document.getElementById('inviteVerifyTitle');
const inviteVerifyText = document.getElementById('inviteVerifyText');
const inviteVerifyCancelBtn = document.getElementById('inviteVerifyCancelBtn');
const inviteVerifySubmitBtn = document.getElementById('inviteVerifySubmitBtn');
const inviteVerifyCloseBtn = document.getElementById('inviteVerifyCloseBtn');
const inviteVerifyCodeInput = document.getElementById('inviteVerifyCodeInput');
const inviteVerifyMessage = document.getElementById('inviteVerifyMessage');

let inviteVerifyResolver = null;

function openInviteVerifyModal() {
    if (!inviteVerifyModal) return;
    inviteVerifyModal.hidden = false;
    inviteVerifyModal.classList.add('active');
    inviteVerifyModal.setAttribute('aria-hidden', 'false');
}

function closeInviteVerifyModal() {
    if (!inviteVerifyModal) return;
    inviteVerifyModal.hidden = true;
    inviteVerifyModal.classList.remove('active');
    inviteVerifyModal.setAttribute('aria-hidden', 'true');
}

function resetInviteVerifyModal() {
    if (inviteVerifyCodeInput) inviteVerifyCodeInput.value = '';
    if (inviteVerifyMessage) {
        inviteVerifyMessage.textContent = '';
        inviteVerifyMessage.classList.remove('is-error');
    }
}

/**
 * Promise-based replacement for window.prompt() during staff invite
 * confirmation. Resolves with the entered code string, or null when the user
 * cancels. Pass an errorMessage to surface a previous failed attempt.
 */
function requestInviteVerificationCode(errorMessage) {
    if (!inviteVerifyModal) return Promise.resolve(null);
    return new Promise((resolve) => {
        inviteVerifyResolver = resolve;
        resetInviteVerifyModal();
        if (errorMessage && inviteVerifyMessage) {
            inviteVerifyMessage.textContent = errorMessage;
            inviteVerifyMessage.classList.add('is-error');
        }
        openInviteVerifyModal();
        if (inviteVerifyCodeInput) inviteVerifyCodeInput.focus();
    });
}

function resolveInviteVerify(value) {
    closeInviteVerifyModal();
    resetInviteVerifyModal();
    const resolve = inviteVerifyResolver;
    inviteVerifyResolver = null;
    if (resolve) resolve(value);
}

if (inviteVerifyCancelBtn) {
    inviteVerifyCancelBtn.addEventListener('click', () => resolveInviteVerify(null));
}

if (inviteVerifyCloseBtn) {
    inviteVerifyCloseBtn.addEventListener('click', () => resolveInviteVerify(null));
}

if (inviteVerifySubmitBtn) {
    inviteVerifySubmitBtn.addEventListener('click', () => {
        const code = inviteVerifyCodeInput ? inviteVerifyCodeInput.value.trim() : '';
        if (!code) {
            if (inviteVerifyMessage) {
                inviteVerifyMessage.textContent = 'Enter the code sent to your email.';
                inviteVerifyMessage.classList.add('is-error');
            }
            return;
        }
        resolveInviteVerify(code);
    });
}

if (inviteVerifyCodeInput) {
    inviteVerifyCodeInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (inviteVerifySubmitBtn) inviteVerifySubmitBtn.click();
        }
    });
}

/* ---- Reusable notice modal (replaces native alert) ---- */
const noticeModal = document.getElementById('noticeModal');
const noticeModalIcon = document.getElementById('noticeModalIcon');
const noticeModalTitle = document.getElementById('noticeModalTitle');
const noticeModalText = document.getElementById('noticeModalText');
const noticeModalOkBtn = document.getElementById('noticeModalOkBtn');
const noticeModalCloseBtn = document.getElementById('noticeModalCloseBtn');
const noticeAutoCloseBar = document.getElementById('noticeAutoCloseBar');

let noticeModalResolver = null;
let noticeAutoCloseTimer = null;

// Success notices auto-dismiss after this many ms; errors stay until dismissed.
const NOTICE_AUTO_CLOSE_MS = 4000;

function openNoticeModal() {
    if (!noticeModal) return;
    noticeModal.hidden = false;
    noticeModal.classList.add('active');
    noticeModal.setAttribute('aria-hidden', 'false');
}

function closeNoticeModal() {
    if (noticeAutoCloseTimer) {
        window.clearTimeout(noticeAutoCloseTimer);
        noticeAutoCloseTimer = null;
    }
    if (noticeAutoCloseBar) {
        noticeAutoCloseBar.hidden = true;
        noticeAutoCloseBar.classList.remove('animating');
    }
    if (!noticeModal) return;
    noticeModal.hidden = true;
    noticeModal.classList.remove('active');
    noticeModal.setAttribute('aria-hidden', 'true');
    const resolve = noticeModalResolver;
    noticeModalResolver = null;
    if (resolve) resolve();
}

/**
 * Promise-based replacement for window.alert(). Shows a styled modal with the
 * given message, an icon (check for success / warning for errors), and an OK
 * button. Success notices auto-dismiss after NOTICE_AUTO_CLOSE_MS; errors stay
 * until dismissed. Resolves once the modal is dismissed.
 */
function showStaffNotice(message, isError = false) {
    if (!noticeModal) return Promise.resolve();
    if (noticeModalTitle) {
        noticeModalTitle.textContent = isError ? 'Error' : 'Notice';
    }
    if (noticeModalIcon) {
        noticeModalIcon.classList.toggle('is-error', isError);
        const iconEl = noticeModalIcon.querySelector('i');
        if (iconEl) {
            iconEl.className = isError ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-circle-check';
        }
    }
    if (noticeModalText) {
        noticeModalText.textContent = message;
        noticeModalText.classList.toggle('is-error', isError);
    }
    return new Promise((resolve) => {
        noticeModalResolver = resolve;
        openNoticeModal();
        if (noticeModalOkBtn) noticeModalOkBtn.focus();
        if (!isError) {
            // Animated countdown bar + auto-close for success notices.
            if (noticeAutoCloseBar) {
                noticeAutoCloseBar.hidden = false;
                noticeAutoCloseBar.classList.remove('animating');
                // Restart the animation on every open.
                void noticeAutoCloseBar.offsetWidth;
                noticeAutoCloseBar.classList.add('animating');
            }
            noticeAutoCloseTimer = window.setTimeout(closeNoticeModal, NOTICE_AUTO_CLOSE_MS);
        }
    });
}

if (noticeModalOkBtn) {
    noticeModalOkBtn.addEventListener('click', closeNoticeModal);
}

if (noticeModalCloseBtn) {
    noticeModalCloseBtn.addEventListener('click', closeNoticeModal);
}

if (noticeModal) {
    noticeModal.addEventListener('click', (event) => {
        if (event.target === noticeModal) closeNoticeModal();
    });
}

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && noticeModal && !noticeModal.hidden) {
        closeNoticeModal();
    }
});

/* ---- Reusable confirm modal (replaces native confirm) ---- */
const confirmModal = document.getElementById('confirmModal');
const confirmModalIcon = document.getElementById('confirmModalIcon');
const confirmModalTitle = document.getElementById('confirmModalTitle');
const confirmModalText = document.getElementById('confirmModalText');
const confirmModalCancelBtn = document.getElementById('confirmModalCancelBtn');
const confirmModalConfirmBtn = document.getElementById('confirmModalConfirmBtn');
const confirmModalCloseBtn = document.getElementById('confirmModalCloseBtn');

let confirmModalResolver = null;

function openConfirmModal() {
    if (!confirmModal) return;
    confirmModal.hidden = false;
    confirmModal.classList.add('active');
    confirmModal.setAttribute('aria-hidden', 'false');
}

function closeConfirmModal() {
    if (!confirmModal) return;
    confirmModal.hidden = true;
    confirmModal.classList.remove('active');
    confirmModal.setAttribute('aria-hidden', 'true');
    // NOTE: Do NOT resolve the promise here — resolveConfirm() handles that.
    // Resolving here caused every confirm to resolve as `false` because
    // closeConfirmModal() was called first inside resolveConfirm(), consuming
    // the resolver before the actual value could be passed.
}

/**
 * Promise-based replacement for window.confirm(). Shows a styled modal with a
 * warning icon, a Cancel button and a destructive Confirm button. Resolves
 * true when confirmed, false when cancelled/closed.
 */
function showStaffConfirm(message, options = {}) {
    if (!confirmModal) return Promise.resolve(false);
    const { title = 'Are you sure?', confirmLabel = 'Confirm' } = options;
    if (confirmModalTitle) confirmModalTitle.textContent = title;
    if (confirmModalText) confirmModalText.textContent = message;
    if (confirmModalConfirmBtn) confirmModalConfirmBtn.textContent = confirmLabel;
    if (confirmModalIcon) {
        const iconEl = confirmModalIcon.querySelector('i');
        if (iconEl) iconEl.className = 'fa-solid fa-triangle-exclamation';
    }
    return new Promise((resolve) => {
        confirmModalResolver = resolve;
        openConfirmModal();
        if (confirmModalCancelBtn) confirmModalCancelBtn.focus();
    });
}

function resolveConfirm(value) {
    closeConfirmModal();
    const resolve = confirmModalResolver;
    confirmModalResolver = null;
    if (resolve) resolve(value);
}

if (confirmModalConfirmBtn) {
    confirmModalConfirmBtn.addEventListener('click', () => resolveConfirm(true));
}

if (confirmModalCancelBtn) {
    confirmModalCancelBtn.addEventListener('click', () => resolveConfirm(false));
}

if (confirmModalCloseBtn) {
    confirmModalCloseBtn.addEventListener('click', () => resolveConfirm(false));
}

if (confirmModal) {
    confirmModal.addEventListener('click', (event) => {
        if (event.target === confirmModal) resolveConfirm(false);
    });
}

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && confirmModal && !confirmModal.hidden) {
        resolveConfirm(false);
    }
});

/* ---- Trusted devices management ---- */
const trustedDevicesList = document.getElementById('trustedDevicesList');
const trustedDevicesMessage = document.getElementById('trustedDevicesMessage');

async function loadTrustedDevices() {
    if (!trustedDevicesList) return;
    const actor = getCurrentStaffActor();
    if (!actor.email) return;

    try {
        const query = new URLSearchParams({
            email: actor.email,
            deviceToken: getOrCreateDeviceToken()
        });
        // The Credentials section is admin-only and lists every staff device so
        // Cashier and Inventory Manager devices can be labelled separately.
        if (actor.role === 'Admin') {
            query.set('includeAll', '1');
        }
        const response = await fetch(getApiUrl(`api/get_trusted_devices.php?${query.toString()}&_=${Date.now()}`), { cache: 'no-store' });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Unable to load trusted devices');
        }
        renderTrustedDevices(Array.isArray(payload.devices) ? payload.devices : []);
    } catch (error) {
        console.error('Unable to load trusted devices', error);
        if (trustedDevicesMessage) trustedDevicesMessage.textContent = error.message || 'Unable to load trusted devices.';
    }
}

function getTrustedDeviceRoleLabel(role) {
    const normalized = String(role || '').trim().toLowerCase();
    if (normalized === 'cashier') return 'Cashier device logged in';
    if (normalized === 'inventory manager') return 'Inventory device logged in';
    if (normalized === 'admin') return 'Admin device logged in';
    return 'Other device logged in';
}

function getTrustedDeviceRoleIcon(role) {
    const normalized = String(role || '').trim().toLowerCase();
    if (normalized === 'cashier') return 'fa-cash-register';
    if (normalized === 'inventory manager') return 'fa-boxes-stacked';
    if (normalized === 'admin') return 'fa-user-shield';
    return 'fa-laptop';
}

function renderTrustedDevices(devices) {
    if (!trustedDevicesList) return;

    if (!devices.length) {
        trustedDevicesList.innerHTML = '<p class="trusted-devices-empty">No trusted devices yet. Your current device becomes trusted after you verify your login.</p>';
        return;
    }

    const roleGroups = [];
    const roleOrder = ['Cashier', 'Inventory Manager', 'Admin'];
    const grouped = {};

    devices.forEach((device) => {
        const role = String(device.role || '').trim() || 'Other';
        if (!grouped[role]) grouped[role] = [];
        grouped[role].push(device);
    });

    Object.keys(grouped)
        .sort((a, b) => {
            const indexA = roleOrder.indexOf(a);
            const indexB = roleOrder.indexOf(b);
            if (indexA === -1 && indexB === -1) return String(a).localeCompare(String(b));
            if (indexA === -1) return 1;
            if (indexB === -1) return -1;
            return indexA - indexB;
        })
        .forEach((role) => {
            const groupDevices = grouped[role];
            roleGroups.push(`
                <div class="trusted-devices-group">
                    <div class="trusted-devices-group-title">
                        <i class="fa-solid ${getTrustedDeviceRoleIcon(role)}" aria-hidden="true"></i>
                        <span>${escapeHtml(getTrustedDeviceRoleLabel(role))}</span>
                        <span class="trusted-devices-group-count">${groupDevices.length}</span>
                    </div>
                    ${groupDevices.map((device) => {
                        const label = escapeHtml(device.device_label || 'Unknown device');
                        const fingerprint = escapeHtml(device.fingerprint || '');
                        const email = escapeHtml(device.email || '');
                        const lastSeen = device.last_seen_at ? formatRealtimeDate(device.last_seen_at) : 'Never';
                        const status = device.is_current
                            ? '<span class="trusted-device-status is-current">Current Device</span>'
                            : '<span class="trusted-device-status is-trusted">Trusted</span>';
                        const revokeBtn = device.is_current
                            ? ''
                            : `<button type="button" class="trusted-device-revoke" data-fingerprint="${fingerprint}" data-email="${email}">Revoke Trust</button>`;
                        return `
                            <div class="trusted-device-row">
                                <div class="trusted-device-icon" aria-hidden="true"><i class="fa-solid fa-laptop"></i></div>
                                <div class="trusted-device-meta">
                                    <strong>${label}</strong>
                                    <span>${email ? `${escapeHtml(device.email)} · ` : ''}Last seen ${lastSeen}</span>
                                </div>
                                ${status}
                                ${revokeBtn}
                            </div>
                        `;
                    }).join('')}
                </div>
            `);
        });

    trustedDevicesList.innerHTML = roleGroups.join('');
}

async function revokeTrustedDevice(fingerprint, email = '') {
    const actor = getCurrentStaffActor();
    const targetEmail = (email || '').trim().toLowerCase() || actor.email;
    if (!fingerprint || !targetEmail) return;

    try {
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });

        const response = await fetch(getApiUrl('api/revoke_trusted_device.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify({ email: targetEmail, fingerprint, deviceToken: getOrCreateDeviceToken() }),
            cache: 'no-store'
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Unable to revoke device');
        }

        void loadTrustedDevices();
    } catch (error) {
        console.error('Unable to revoke trusted device', error);
        if (trustedDevicesMessage) trustedDevicesMessage.textContent = error.message || 'Unable to revoke device.';
    }
}

if (trustedDevicesList) {
    trustedDevicesList.addEventListener('click', (event) => {
        const button = event.target.closest('.trusted-device-revoke');
        if (!button) return;
        void revokeTrustedDevice(button.dataset.fingerprint || '', button.dataset.email || '');
    });
}

/* ---- Login history audit trail (Credentials section) ---- */
const loginHistoryList = document.getElementById('loginHistoryList');
const loginOnlineList = document.getElementById('loginOnlineList');
const loginHistoryDateInput = document.getElementById('loginHistoryDateInput');
const loginHistoryClearDateBtn = document.getElementById('loginHistoryClearDateBtn');

// The login history filter defaults to today so the section opens showing the
// current day's logins (mirrors the logs date filter behavior).
function syncLoginHistoryDateToToday() {
    if (!loginHistoryDateInput) return;

    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    const isoDate = `${year}-${month}-${day}`;

    if (!loginHistoryDateInput.value || loginHistoryDateInput.value !== isoDate) {
        loginHistoryDateInput.value = isoDate;
    }
}

async function loadLoginHistory() {
    if (!loginHistoryList) return;

    const dateValue = loginHistoryDateInput ? loginHistoryDateInput.value : '';
    const query = new URLSearchParams();
    if (dateValue) {
        query.set('date', dateValue);
    }
    query.set('_', Date.now());

    try {
        const response = await fetch(getApiUrl(`api/get_login_history.php?${query.toString()}`), { cache: 'no-store' });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Unable to load login history');
        }
        renderLoginOnline(Array.isArray(payload.online) ? payload.online : []);
        renderLoginHistory(Array.isArray(payload.history) ? payload.history : [], dateValue);
    } catch (error) {
        console.error('Unable to load login history', error);
        if (loginHistoryList) {
            loginHistoryList.innerHTML = '<p class="trusted-devices-empty">Unable to load login history.</p>';
        }
    }
}

function renderLoginOnline(online) {
    if (!loginOnlineList) return;

    if (!Array.isArray(online) || !online.length) {
        loginOnlineList.innerHTML = '<p class="login-online-empty">No staff currently online.</p>';
        return;
    }

    loginOnlineList.innerHTML = online.map((account) => {
        const name = String(account.name || '').trim() || 'Staff';
        const role = String(account.role || '').trim() || 'Staff';
        const lastActive = account.last_active_at ? formatRealtimeDate(account.last_active_at) : '';
        return `
            <div class="login-online-item">
                <span class="login-online-dot" aria-hidden="true"></span>
                <strong>${escapeHtml(name)}</strong>
                <span class="login-online-email">${escapeHtml(account.email || '')}</span>
                <span class="login-online-role">${escapeHtml(role)}</span>
                <span class="login-online-time">${lastActive ? `Active ${escapeHtml(lastActive)}` : 'Active now'}</span>
            </div>
        `;
    }).join('');
}

function renderLoginHistory(history, dateValue = '') {
    if (!loginHistoryList) return;

    const filterNote = dateValue
        ? `<p class="login-history-filter-note">Showing logins for ${escapeHtml(formatRealtimeDate(dateValue))}.</p>`
        : '';

    if (!history.length) {
        loginHistoryList.innerHTML = `${filterNote}<p class="trusted-devices-empty">${dateValue ? 'No logins recorded for this date.' : 'No login history recorded yet. Successful staff logins will appear here with their date, time, and role.'}</p>`;
        return;
    }

    loginHistoryList.innerHTML = `
        ${filterNote}
        <div class="login-history-table-wrap">
            <table class="login-history-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Role</th>
                        <th>Email</th>
                        <th>Device</th>
                    </tr>
                </thead>
                <tbody>
                    ${history.map((entry) => `
                        <tr>
                            <td>${entry.logged_in_at ? escapeHtml(formatRealtimeDate(entry.logged_in_at)) : '—'}</td>
                            <td><span class="login-history-role">${escapeHtml(entry.role || '—')}</span></td>
                            <td>${escapeHtml(entry.email || '—')}</td>
                            <td>${escapeHtml(entry.device_label || '—')}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

if (loginHistoryDateInput) {
    loginHistoryDateInput.addEventListener('change', () => void loadLoginHistory());
}
if (loginHistoryClearDateBtn) {
    loginHistoryClearDateBtn.addEventListener('click', () => {
        if (loginHistoryDateInput) loginHistoryDateInput.value = '';
        void loadLoginHistory();
    });
}

// Keep the online status + history fresh while the credentials section is open.
window.setInterval(() => {
    if (typeof credentialsSection !== 'undefined' && credentialsSection && !credentialsSection.hidden && loginHistoryList) {
        void loadLoginHistory();
    }
}, 30000);

function attachStaffLoginHandler() {
    if (!staffForm) return;

    if (emailInput) {
        emailInput.addEventListener('input', () => {
            syncSelectedRoleWithTypedEmail(emailInput.value);
        });
    }

    staffForm.addEventListener('submit', async function (event) {
        if (!staffForm.checkValidity()) {
            staffForm.reportValidity();
            return;
        }

        event.preventDefault();

        const email = emailInput ? emailInput.value.trim() : '';
        syncSelectedRoleWithTypedEmail(email);

        const role = selectedRoleInput && selectedRoleInput.value
            ? selectedRoleInput.value.trim()
            : '';
        const password = passwordInput ? passwordInput.value : '';
        const remember = rememberCheckbox ? rememberCheckbox.checked : false;
        const submitBtn = staffForm.querySelector('button[type="submit"]');

        const setLoading = (loading) => {
            if (!submitBtn) return;
            submitBtn.disabled = loading;
            submitBtn.textContent = loading ? 'Logging in…' : 'Login';
        };

        setLoading(true);
        try {
            await handleStaffLogin(email, password, role, remember);
        } finally {
            setLoading(false);
        }
    });
}

async function handleStaffLogin(email, password, role, remember) {
    const deviceToken = getOrCreateDeviceToken();
    let authResult = await authenticateStaffAccount(email, password, role, deviceToken);
    if (!authResult) {
        setAuthButtonsVisible(false);
        if (modalTitle) {
            modalTitle.textContent = 'Invalid credentials';
        }
        return;
    }

    // Account locked out after too many failed login attempts: show the
    // lockout message from the server so staff know to wait and retry.
    if (authResult.rateLimited) {
        setAuthButtonsVisible(false);
        if (modalTitle) {
            modalTitle.textContent = authResult.error || 'Too many failed login attempts. Please try again later.';
        }
        return;
    }

    // Invalid credentials with a remaining-attempts countdown from the server.
    if (!authResult.success && authResult.remainingAttempts != null) {
        setAuthButtonsVisible(false);
        if (modalTitle) {
            modalTitle.textContent = authResult.error || 'Invalid credentials';
        }
        return;
    }

    // Unrecognized device: the account must confirm the emailed code first.
    if (authResult.needsDeviceVerification) {
        const code = await requestDeviceVerificationCode(authResult.warning || '');
        if (!code) {
            if (modalTitle) {
                modalTitle.textContent = 'Device verification required';
            }
            return;
        }

        authResult = await verifyDeviceLogin(email, password, code, deviceToken);
        if (!authResult) {
            if (modalTitle) {
                modalTitle.textContent = 'Invalid or expired verification code';
            }
            return;
        }
    }

    // Only a genuinely successful auth (or a device-verified one) proceeds.
    if (!authResult.success || !allowedRoles.includes(authResult.role)) {
        setAuthButtonsVisible(false);
        if (modalTitle) {
            modalTitle.textContent = 'Invalid credentials';
        }
        return;
    }

    const detectedRole = authResult.role;
    if (selectedRoleInput) {
        selectedRoleInput.value = detectedRole;
    }

    if ((detectedRole === 'Cashier' || detectedRole === 'Inventory Manager') && !authResult.inviteConfirmed) {
        let inviteError = '';
        // Loop so a wrong code re-opens the modal with the error instead of
        // dumping the user back to a plain login screen.
        for (;;) {
            const inviteCode = await requestInviteVerificationCode(inviteError);
            if (!inviteCode) {
                if (modalTitle) {
                    modalTitle.textContent = 'Invite confirmation required';
                }
                return;
            }

            try {
                await confirmStaffInviteCode(email, detectedRole, inviteCode);
                break;
            } catch (error) {
                inviteError = error.message || 'Invite verification failed';
            }
        }
    }

    // Persist an opaque session token — never the plaintext password.
    if (authResult.sessionToken) {
        saveStaffSession(detectedRole, email, authResult.sessionToken, remember);
        // A fresh token means the server session was (re)established — drop any
        // stale cached renewal so future calls re-check against the server.
        staffServerSessionRenewal = null;
    }

    // The page-load fetch of completed orders ran BEFORE this login (when no
    // server session existed yet), so it skipped the request — without this,
    // the Overview profit/analytics stay empty until the next manual refresh.
    // Re-fetch now that the session is live and re-gate the loading overlay on
    // the refreshed data so the first paint is fully populated.
    staffInitialDataReady = Promise.allSettled([
        loadCompletedOrdersFromServer(true),
        loadPendingOrdersFromServer(),
        loadReviewsFromServer(true)
    ]);

    // Show the loading overlay while the dashboard data settles.
    showStaffLoadingOverlay();

    // The login regenerated the server session; adopt its CSRF token so every
    // later POST validates against the current session.
    if (authResult.csrfToken) {
        adoptCsrfToken(authResult.csrfToken);
    }

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
        if (dashboardPanel) {
            dashboardPanel.style.display = '';
        }
        // After login, show the Overview dashboard as the main page
        if (overviewSection) {
            showDashboardSection(overviewSection);
            renderOverviewAnalytics();
            showLowStockAlertIfNeeded();
        }
        // Ensure dashboard panel is closed (main content visible)
        setDashboardPanelState(false);

        void notifyStaffSessionEvent('login', detectedRole, email.toLowerCase());
}

document.addEventListener('DOMContentLoaded', attachStaffLoginHandler);

document.addEventListener('DOMContentLoaded', () => {
    if (loginFields) {
        loginFields.hidden = false;
    }
    initializeLowStockAlertHandlers();
});

if (logoutBtn) {
    logoutBtn.addEventListener('click', () => {
        const actorRole = selectedRoleInput ? selectedRoleInput.value.trim() : '';
        const actorEmail = emailInput ? emailInput.value.trim().toLowerCase() : '';
        void notifyStaffSessionEvent('logout', actorRole, actorEmail);

        const session = getPersistedStaffSession();
        if (session && session.sessionToken) {
            void revokeStaffSessionOnServer(session.sessionToken);
        }

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

        // Stop background refresh timers and audio when logging out
        try {
            stopStaffAccountsRefresh();
        } catch (e) {
            console.debug('stopStaffAccountsRefresh unavailable', e);
        }
        if (orderLogsRefreshTimer) {
            window.clearInterval(orderLogsRefreshTimer);
            orderLogsRefreshTimer = null;
        }
        if (pendingOrdersRefreshTimer) {
            window.clearInterval(pendingOrdersRefreshTimer);
            pendingOrdersRefreshTimer = null;
        }
        if (pendingOrdersCountdownTicker) {
            window.clearInterval(pendingOrdersCountdownTicker);
            pendingOrdersCountdownTicker = null;
        }
        if (customerOrderStatusPoller) {
            window.clearInterval(customerOrderStatusPoller);
            customerOrderStatusPoller = null;
        }
        if (orderStatusFloatTicker) {
            window.clearInterval(orderStatusFloatTicker);
            orderStatusFloatTicker = null;
        }
        stopOrderCompletedNotificationSound();

        // Hide the dashboard panel when logged out so it does not remain visible.
        if (dashboardPanel) {
            dashboardPanel.style.display = 'none';
        }
        if (menuBtn) {
            menuBtn.style.display = 'none';
            menuBtn.hidden = true;
        }
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
const toggleAccountFormBtn = document.getElementById('toggleAccountFormBtn');
const cancelAccountFormBtn = document.getElementById('cancelAccountFormBtn');
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
const toggleCredentialsFormBtn = document.getElementById('toggleCredentialsFormBtn');
const passwordCredentialsForm = document.getElementById('passwordCredentialsForm');
const togglePasswordFormBtn = document.getElementById('togglePasswordFormBtn');
const adminCurrentEmailInput = document.getElementById('adminCurrentEmail');
const adminCurrentPasswordInput = document.getElementById('adminCurrentPassword');
const adminNewEmailInput = document.getElementById('adminNewEmail');
const adminNewPasswordInput = document.getElementById('adminNewPassword');
const adminChangeCodeInput = document.getElementById('adminChangeCode');
const adminPasswordCurrentEmailInput = document.getElementById('adminPasswordCurrentEmail');
const adminPasswordCurrentPasswordInput = document.getElementById('adminPasswordCurrentPassword');
const adminPasswordNewPasswordInput = document.getElementById('adminPasswordNewPassword');
const adminPasswordChangeCodeInput = document.getElementById('adminPasswordChangeCode');
const requestCredentialsChangeBtn = document.getElementById('requestCredentialsChangeBtn');
const requestPasswordChangeBtn = document.getElementById('requestPasswordChangeBtn');
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

        if (accountEditIndex === account._index) {
            item.innerHTML = `
                <div class="account-inline-editor">
                    <input type="text" value="${escapeHtml(account.name)}" data-field="name" aria-label="Staff name">
                    <select data-field="role" aria-label="Staff role">
                        <option value="Cashier" ${account.role === 'Cashier' ? 'selected' : ''}>Cashier</option>
                        <option value="Inventory Manager" ${account.role === 'Inventory Manager' ? 'selected' : ''}>Inventory Manager</option>
                    </select>
                    <input type="email" value="${escapeHtml(account.email)}" data-field="email" aria-label="Staff email">
                    <input type="password" value="${escapeHtml(account.password)}" data-field="password" aria-label="Staff password">
                    <div class="account-inline-actions">
                        <button type="button" class="save-btn" data-index="${account._index}">Save</button>
                        <button type="button" class="cancel-btn" data-index="${account._index}">Cancel</button>
                    </div>
                </div>
            `;
        } else {
            item.innerHTML = `
                <span>${escapeHtml(account.name)} — ${escapeHtml(account.role)} — ${escapeHtml(account.email)} — ${escapeHtml(inviteLabel)}</span>
                <div>
                    <button type="button" class="edit-btn" data-index="${account._index}">Edit</button>
                    <button type="button" class="delete-btn" data-index="${account._index}">Delete</button>
                </div>
            `;
        }

        accountList.appendChild(item);
    });
}

function resetAccountForm() {
    if (accountForm) {
        accountForm.reset();
    }
    accountEditIndex = null;
}

function toggleAccountForm(showForm) {
    if (!accountForm) return;
    accountForm.hidden = !showForm;
    if (!showForm) {
        resetAccountForm();
    }
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
        if (!payload || payload.success !== true || !payload.credentials) {
            adminCurrentEmailInput.value = adminDefaultEmail;
            if (adminPasswordCurrentEmailInput) adminPasswordCurrentEmailInput.value = adminDefaultEmail;
            return;
        }

        const adminEmail = payload.credentials.email || adminDefaultEmail;
        adminCurrentEmailInput.value = adminEmail;
        if (adminPasswordCurrentEmailInput) adminPasswordCurrentEmailInput.value = adminEmail;
    } catch (error) {
        adminCurrentEmailInput.value = adminDefaultEmail;
        if (adminPasswordCurrentEmailInput) adminPasswordCurrentEmailInput.value = adminDefaultEmail;
        console.error('Unable to load admin credentials', error);
    }
}

async function requestAdminCredentialsChange({
    currentEmailInput = adminCurrentEmailInput,
    currentPasswordInput = adminCurrentPasswordInput,
    newEmailInput = adminNewEmailInput,
    newPasswordInput = adminNewPasswordInput,
    shouldRequireEmail = true
} = {}) {
    if (!currentEmailInput || !currentPasswordInput) return;

    const currentEmail = currentEmailInput.value.trim().toLowerCase();
    const currentPassword = currentPasswordInput.value;
    const nextEmail = newEmailInput ? newEmailInput.value.trim().toLowerCase() : '';
    const nextPassword = newPasswordInput ? newPasswordInput.value : '';

    if (!currentEmail || !currentPassword) {
        setCredentialsMessage('Current email and current password are required.', true);
        return;
    }

    const emailChangeRequested = shouldRequireEmail && nextEmail !== '';
    const passwordChangeRequested = !shouldRequireEmail && nextPassword !== '';
    if (!emailChangeRequested && !passwordChangeRequested) {
        setCredentialsMessage('Please complete the new email or password field before requesting the code.', true);
        return;
    }

    if (emailChangeRequested && !isGmailAddress(nextEmail)) {
        setCredentialsMessage('Admin email must be a Gmail address.', true);
        return;
    }

    // Admin password policy: minimum 8 characters, no upper length limit.
    if (nextPassword !== '' && nextPassword.length < 8) {
        setCredentialsMessage('Admin password must be at least 8 characters.', true);
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
                newEmail: shouldRequireEmail ? nextEmail : currentEmail,
                newPassword: shouldRequireEmail ? nextPassword : nextPassword
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

async function confirmAdminCredentialsChange(event, formType = 'email') {
    if (event && event.preventDefault) event.preventDefault();

    const isEmailForm = formType === 'email';
    const currentEmailInput = isEmailForm ? adminCurrentEmailInput : adminPasswordCurrentEmailInput;
    const currentPasswordInput = isEmailForm ? adminCurrentPasswordInput : adminPasswordCurrentPasswordInput;
    const codeInput = isEmailForm ? adminChangeCodeInput : adminPasswordChangeCodeInput;
    const nextEmailInput = isEmailForm ? adminNewEmailInput : null;
    const nextPasswordInput = isEmailForm ? adminNewPasswordInput : adminPasswordNewPasswordInput;

    if (!currentEmailInput || !currentPasswordInput || !codeInput) return;

    const currentEmail = currentEmailInput.value.trim().toLowerCase();
    const currentPassword = currentPasswordInput.value;
    const code = codeInput.value.trim();
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

        const nextEmail = nextEmailInput ? nextEmailInput.value.trim().toLowerCase() : currentEmail;
        const nextPassword = nextPasswordInput ? nextPasswordInput.value : '';

        // The credentials change revokes every previously issued session token,
        // so re-authenticate silently (this device is already trusted) to obtain
        // a fresh token for the restored session.
        if (nextEmail && nextPassword) {
            const deviceToken = getOrCreateDeviceToken();
            const reAuth = await authenticateStaffAccount(nextEmail, nextPassword, 'Admin', deviceToken, true);
            if (reAuth && reAuth.success && reAuth.sessionToken) {
                if (selectedRoleInput) {
                    selectedRoleInput.value = 'Admin';
                }
                if (emailInput) {
                    emailInput.value = nextEmail;
                }
                if (passwordInput) {
                    passwordInput.value = nextPassword;
                }
                saveStaffSession('Admin', nextEmail, reAuth.sessionToken, false);
                updateDashboardProfile();
            }
        }

        skipNextLogoutValidation = true;
        await loadStaffAccountsFromServer(true);
        restoreStaffSession();
        await loadAdminCredentials();

        if (isEmailForm && credentialsForm) {
            credentialsForm.reset();
        }
        if (!isEmailForm && passwordCredentialsForm) {
            passwordCredentialsForm.reset();
        }
        if (adminCurrentEmailInput) {
            const admin = accounts.find((account) => account.role === 'Admin');
            adminCurrentEmailInput.value = admin ? admin.email : currentEmail;
        }
        if (adminPasswordCurrentEmailInput) {
            const admin = accounts.find((account) => account.role === 'Admin');
            adminPasswordCurrentEmailInput.value = admin ? admin.email : currentEmail;
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
        void loadTrustedDevices();
        void loadLoginHistory();
    });
}

function updateAccountManagementAccess() {
    const setLinkState = (link, isAllowed) => {
        if (!link) return;
        link.hidden = !isAllowed;
        link.classList.toggle('disabled', false);
        link.removeAttribute('aria-disabled');
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
    const year = new Date().getFullYear();
    const monthIndex = new Date().getMonth();
    const days = daysInMonth(year, monthIndex);
    return Array.from({ length: days }, (_, index) => {
        const value = Math.round(base + Math.sin(index / 3) * drift + index * 12);
        return { label: `${index + 1}`, value };
    });
}

const monthKeys = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function daysInMonth(year, monthIndex) {
    // monthIndex: 0 = Jan, 11 = Dec
    return new Date(year, monthIndex + 1, 0).getDate();
}

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
    if (profitMonthSelect) {
        profitMonthSelect.value = monthKey;
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
        // build per-month daily buckets using the actual days in that month
        const year = new Date().getFullYear();
        const monthIndex = monthKeys.indexOf(monthKey);
        const days = Math.max(28, daysInMonth(year, monthIndex));
        monthlySalesByMonth[monthKey] = Array.from({ length: days }, (_, index) => ({
            label: `${index + 1}`,
            value: 0,
            orders: 0
        }));
        // weekly buckets: dynamic number of weeks for the month
        const weeks = Math.ceil(days / 7);
        weeklySalesByMonth[monthKey] = Array.from({ length: weeks }, (_, index) => ({
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
        // Clamp day index to the dynamically-sized month bucket
        const daysInThisMonth = (monthlySalesByMonth[monthKey] || []).length || 30;
        const dayIndex = Math.min(Math.max(0, orderDate.getDate() - 1), daysInThisMonth - 1);
        const weeksForMonth = (weeklySalesByMonth[monthKey] || []).length || 5;
        const weekIndex = Math.min(weeksForMonth - 1, Math.floor(dayIndex / 7));
        const orderTotal = Number(order.total || 0);
        const completedProducts = (Array.isArray(order.items) ? order.items : []).reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);

        if (monthKey && monthlySalesByMonth[monthKey]) {
            if (monthlySalesByMonth[monthKey] && monthlySalesByMonth[monthKey][dayIndex]) {
                monthlySalesByMonth[monthKey][dayIndex].value += orderTotal;
                monthlySalesByMonth[monthKey][dayIndex].orders += completedProducts;
            }
            if (weeklySalesByMonth[monthKey] && weeklySalesByMonth[monthKey][weekIndex]) {
                weeklySalesByMonth[monthKey][weekIndex].value += orderTotal;
                weeklySalesByMonth[monthKey][weekIndex].orders += completedProducts;
            }
            analyticsData.monthly.items[monthIndex].value += orderTotal;
            analyticsData.monthly.items[monthIndex].orders += completedProducts;
        }
    });

    analyticsData.monthly.items = analyticsData.monthly.items.map((item) => ({
        ...item,
        display: `₱${item.value.toLocaleString()}`
    }));
}

function renderDetailChart(container, chartData, title, animate = true) {
    if (!container) return;
    if (!chartData || !chartData.length) {
        container.innerHTML = '<p class="menu-cart-empty">Waiting for live data...</p>';
        return;
    }

    const maxValue = Math.max(...chartData.map((item) => Number(item.value) || 0));
    const paddedMax = Math.max(1000, Math.ceil(maxValue / 1000) * 1000);
    const ticks = 5;
    const pointCount = chartData.length;
    /* Weekly (4-5 pts) and monthly (12 pts) need enough room for labels;
       daily (≈30 pts) scrolls.  Minimum 520 keeps small charts readable;
       per-point spacing grows with fewer points so bars aren't cramped. */
    const perPoint = pointCount <= 6 ? 80 : pointCount <= 14 ? 55 : 30;
    const svgWidth = Math.max(520, Math.min(1400, pointCount * perPoint + 140));
    const svgHeight = 260;
    const margin = { top: 36, right: 28, bottom: 60, left: 62 };
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
            <rect x="0" y="0" width="${svgWidth}" height="${svgHeight}" fill="#ffffff" rx="16" />
            <g>
                <line x1="${margin.left}" y1="${margin.top}" x2="${margin.left}" y2="${margin.top + chartHeight}" stroke="#999" stroke-width="1.5" />
                <line x1="${margin.left}" y1="${margin.top + chartHeight}" x2="${margin.left + chartWidth}" y2="${margin.top + chartHeight}" stroke="#999" stroke-width="1.5" />
            </g>
            <g>
                ${yTicks.map((tick) => `
                    <line x1="${margin.left}" y1="${tick.y}" x2="${margin.left + chartWidth}" y2="${tick.y}" stroke="rgba(150,150,150,0.3)" />
                    <text x="${margin.left - 14}" y="${tick.y + 5}" text-anchor="end" fill="#1e293b" font-size="13" font-weight="700">${tick.value}</text>
                `).join('')}
            </g>
            <path${animate ? ' class="sales-line-path"' : ''} d="${pathD}"${animate ? ' pathLength="1"' : ''} fill="none" stroke="#2BAE66" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
            <g${animate ? ' class="sales-line-dots"' : ''}>
                ${points.map((point, index) => `
                    <circle cx="${point.x}" cy="${point.y}" r="6" fill="#fff" stroke="#2BAE66" stroke-width="2.5"${animate ? ` style="animation-delay: ${(index * 0.04).toFixed(2)}s"` : ''} />
                    <text x="${point.x}" y="${point.y - 14}" text-anchor="middle" fill="#0f172a" font-size="13" font-weight="800">${point.display}</text>
                `).join('')}
            </g>
            <g>
                ${points.map((point) => `
                    <text x="${point.x}" y="${margin.top + chartHeight + 24}" text-anchor="middle" fill="#475569" font-size="12" font-weight="600">${point.label}</text>
                `).join('')}
            </g>
            <text x="${margin.left}" y="22" fill="#1e293b" font-size="15" font-weight="700">${title}</text>
        </svg>
    `;

    container.innerHTML = svg;
}

function updateAnalyticsView(animate = true) {
    if (!analyticsSelect || !analyticsChart || !analyticsMonthWrapper || !analyticsMonthSelect) return;

    const view = analyticsSelect.value;
    analyticsMonthWrapper.style.display = view === 'monthly' ? 'none' : 'inline-flex';

    if (view === 'daily') {
        const month = analyticsMonthSelect.value;
        const monthData = monthlySalesByMonth[month] || monthlySalesByMonth.jan;
        renderDetailChart(analyticsChart, monthData, `Daily Sales — ${analyticsMonthSelect.options[analyticsMonthSelect.selectedIndex].text}`, animate);
        autoScrollChartToCurrentDay(analyticsChart, month, monthData.length);
    } else if (view === 'weekly') {
        const month = analyticsMonthSelect.value;
        const monthData = weeklySalesByMonth[month] || weeklySalesByMonth.jan;
        renderDetailChart(analyticsChart, monthData, `Weekly Sales — ${analyticsMonthSelect.options[analyticsMonthSelect.selectedIndex].text}`, animate);
    } else {
        renderAnalytics('monthly', animate);
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

/* ================= Chart pan buttons =================
   Analysis charts keep their full pixel width so day/month labels stay
   readable; these controls pan the chart wrapper horizontally. */
function setupChartScrollControls() {
    document.querySelectorAll('.chart-scroll-controls[data-scroll-target]').forEach((controls) => {
        const chart = document.getElementById(controls.getAttribute('data-scroll-target'));
        const wrapper = chart ? chart.closest('.sales-analytics-chart-wrapper') : null;
        const isVertical = controls.classList.contains('chart-scroll-vertical');

        if (isVertical) {
            /* Vertical scroll: the parent panel scrolls up/down */
            const panel = controls.closest('.overview-analytics-panel, .sales-analytics-placeholder, .profit-analytics-panel')
                || (chart ? chart.closest('.overview-analytics-panel, .sales-analytics-placeholder, .profit-analytics-panel') : null);
            const scrollTarget = panel || (wrapper ? wrapper.parentElement : null);
            const upBtn = controls.querySelector('[data-chart-scroll="up"]');
            const downBtn = controls.querySelector('[data-chart-scroll="down"]');
            if (!scrollTarget || !upBtn || !downBtn) return;

            const stepAmount = () => Math.max(180, Math.round(scrollTarget.clientHeight * 0.6));

            const updateButtons = () => {
                const maxScroll = Math.max(0, Math.ceil(scrollTarget.scrollHeight - scrollTarget.clientHeight));
                const position = Math.ceil(scrollTarget.scrollTop);
                const scrollable = maxScroll > 4;
                controls.classList.toggle('is-scrollable', scrollable);
                upBtn.disabled = !scrollable || position <= 4;
                downBtn.disabled = !scrollable || position >= maxScroll - 4;
            };

            if (controls.dataset.scrollBound !== 'true') {
                controls.dataset.scrollBound = 'true';
                upBtn.addEventListener('click', () => scrollTarget.scrollBy({ top: -stepAmount(), behavior: 'smooth' }));
                downBtn.addEventListener('click', () => scrollTarget.scrollBy({ top: stepAmount(), behavior: 'smooth' }));
                scrollTarget.addEventListener('scroll', updateButtons, { passive: true });
                window.addEventListener('resize', updateButtons);
                new MutationObserver(updateButtons).observe(chart, { childList: true, subtree: true });
            }

            updateButtons();
            return;
        }

        /* Horizontal scroll (original behavior) */
        const leftBtn = controls.querySelector('[data-chart-scroll="left"]');
        const rightBtn = controls.querySelector('[data-chart-scroll="right"]');
        if (!chart || !wrapper || !leftBtn || !rightBtn) return;

        const updateButtons = () => {
            const maxScroll = Math.max(0, Math.ceil(wrapper.scrollWidth - wrapper.clientWidth));
            const position = Math.ceil(wrapper.scrollLeft);
            const scrollable = maxScroll > 4;
            controls.classList.toggle('is-scrollable', scrollable);
            leftBtn.disabled = !scrollable || position <= 4;
            rightBtn.disabled = !scrollable || position >= maxScroll - 4;
        };

        if (controls.dataset.scrollBound !== 'true') {
            controls.dataset.scrollBound = 'true';
            const panBy = (direction) => {
                const stepAmt = Math.max(160, Math.round(wrapper.clientWidth * 0.8));
                wrapper.scrollBy({ left: direction * stepAmt, behavior: 'smooth' });
            };
            leftBtn.addEventListener('click', () => panBy(-1));
            rightBtn.addEventListener('click', () => panBy(1));
            wrapper.addEventListener('scroll', updateButtons, { passive: true });
            window.addEventListener('resize', updateButtons);
            new MutationObserver(updateButtons).observe(chart, { childList: true, subtree: true });
        }

        updateButtons();
    });
}
setupChartScrollControls();

/* ================= Sales section scroll-to-top button ================= */
(function initSalesScrollTopBtn() {
    const scrollBtn = document.getElementById('salesScrollTopBtn');
    const salesSection = document.getElementById('sales');
    if (!scrollBtn || !salesSection) return;

    function toggleScrollBtn() {
        if (salesSection.hidden) { scrollBtn.classList.remove('is-visible'); return; }
        const scrolled = salesSection.scrollTop > 180;
        scrollBtn.classList.toggle('is-visible', scrolled);
    }

    salesSection.style.overflowY = 'auto';
    salesSection.addEventListener('scroll', toggleScrollBtn, { passive: true });
    scrollBtn.addEventListener('click', () => {
        salesSection.scrollTo({ top: 0, behavior: 'smooth' });
    });
    toggleScrollBtn();
})();

/* ================= Profit analytics (daily / weekly / monthly) =================
   Mirrors the sales analysis charts: same styling, same month selector, and a
   daily view centered on the current day. Profit = revenue - cost of goods
   sold, where COGS uses each item's inventory unit cost (and its customize
   components when present). */
const profitAnalyticsSelect = document.getElementById('profitAnalyticsSelect');
const profitAnalyticsChart = document.getElementById('profitAnalyticsChart');
const profitMonthWrapper = document.getElementById('profitMonthWrapper');
const profitMonthSelect = document.getElementById('profitMonthSelect');
const profitRevenueValue = document.getElementById('profitRevenueValue');
const profitCogsValue = document.getElementById('profitCogsValue');
const profitNetValue = document.getElementById('profitNetValue');

// Buckets mirror the sales buckets: per-month days/weeks plus a 12-month view.
const profitDailyByMonth = {
    jan: [], feb: [], mar: [], apr: [], may: [], jun: [],
    jul: [], aug: [], sep: [], oct: [], nov: [], dec: []
};
const profitWeeklyByMonth = {
    jan: [], feb: [], mar: [], apr: [], may: [], jun: [],
    jul: [], aug: [], sep: [], oct: [], nov: [], dec: []
};
const profitMonthlyItems = [];

function initializeProfitBuckets() {
    monthKeys.forEach((monthKey) => {
        const year = new Date().getFullYear();
        const monthIndex = monthKeys.indexOf(monthKey);
        const days = Math.max(28, daysInMonth(year, monthIndex));
        profitDailyByMonth[monthKey] = Array.from({ length: days }, (_, index) => ({
            label: `${index + 1}`,
            revenue: 0,
            cost: 0,
            profit: 0
        }));
        const weeks = Math.ceil(days / 7);
        profitWeeklyByMonth[monthKey] = Array.from({ length: weeks }, (_, index) => ({
            label: `W${index + 1}`,
            revenue: 0,
            cost: 0,
            profit: 0
        }));
    });

    profitMonthlyItems.length = 0;
    monthKeys.forEach((monthKey, index) => {
        profitMonthlyItems.push({ label: monthLabels[index], revenue: 0, cost: 0, profit: 0 });
    });
}

function getOrderItemCost(item) {
    if (!item) return 0;
    let cost = 0;

    const components = Array.isArray(item.components) ? item.components : [];
    if (components.length) {
        // Special dishes are priced as the sum of their components, so the
        // components ARE the ingredients — use their costs only (the dish's own
        // unit cost would double-count the same items).
        components.forEach((component) => {
            const componentItem = getInventoryItem(component && component.name);
            if (componentItem) {
                cost += (Number(componentItem.unitCost) || 0) * Math.max(0, Number(component && component.quantity) || 0);
            }
        });
        return cost;
    }

    const inventoryItem = getInventoryItem(item.name);
    if (inventoryItem) {
        cost += (Number(inventoryItem.unitCost) || 0) * Math.max(0, Number(item.quantity) || 0);
    }

    return cost;
}

function getOrderProfitBreakdown(order) {
    const revenue = Math.max(0, Number(order.total) || 0);
    let cost = 0;
    (Array.isArray(order.items) ? order.items : []).forEach((item) => {
        cost += getOrderItemCost(item);
    });
    return { revenue, cost, profit: revenue - cost };
}

function roundMoney(value) {
    return Math.round((Number(value) || 0) * 100) / 100;
}

function recalculateProfitAnalytics() {
    initializeProfitBuckets();

    completedOrders.forEach((order) => {
        const orderDate = new Date(order.timestamp);
        const monthIndex = orderDate.getMonth();
        const monthKey = monthKeys[monthIndex];
        const breakdown = getOrderProfitBreakdown(order);

        const daysInThisMonth = (profitDailyByMonth[monthKey] || []).length || 30;
        const dayIndex = Math.min(Math.max(0, orderDate.getDate() - 1), daysInThisMonth - 1);
        const weeksForMonth = (profitWeeklyByMonth[monthKey] || []).length || 5;
        const weekIndex = Math.min(weeksForMonth - 1, Math.floor(dayIndex / 7));

        if (monthKey && profitDailyByMonth[monthKey] && profitDailyByMonth[monthKey][dayIndex]) {
            profitDailyByMonth[monthKey][dayIndex].revenue += breakdown.revenue;
            profitDailyByMonth[monthKey][dayIndex].cost += breakdown.cost;
            profitDailyByMonth[monthKey][dayIndex].profit += breakdown.profit;
        }
        if (monthKey && profitWeeklyByMonth[monthKey] && profitWeeklyByMonth[monthKey][weekIndex]) {
            profitWeeklyByMonth[monthKey][weekIndex].revenue += breakdown.revenue;
            profitWeeklyByMonth[monthKey][weekIndex].cost += breakdown.cost;
            profitWeeklyByMonth[monthKey][weekIndex].profit += breakdown.profit;
        }
        if (profitMonthlyItems[monthIndex]) {
            profitMonthlyItems[monthIndex].revenue += breakdown.revenue;
            profitMonthlyItems[monthIndex].cost += breakdown.cost;
            profitMonthlyItems[monthIndex].profit += breakdown.profit;
        }
    });
}

function profitChartData(buckets) {
    return (Array.isArray(buckets) ? buckets : []).map((bucket) => ({
        label: bucket.label,
        value: roundMoney(bucket.profit)
    }));
}

// Scroll the daily profit chart so today's column sits in the middle of the
// visible area (the default view is centered on the current day).
function centerChartOnCurrentDay(chartContainer, monthKey, pointCount) {
    if (!chartContainer || !monthKey || monthKey !== getCurrentMonthKey()) return;
    const wrapper = chartContainer.closest('.sales-analytics-chart-wrapper');
    if (!wrapper) return;

    const totalPoints = Math.max(1, Number(pointCount) || 30);
    const currentDayIndex = Math.max(0, Math.min(totalPoints - 1, new Date().getDate() - 1));

    const applyScroll = () => {
        const maxScroll = Math.max(0, wrapper.scrollWidth - wrapper.clientWidth);
        if (maxScroll <= 0) return;

        const dayRatio = totalPoints > 1 ? currentDayIndex / (totalPoints - 1) : 0;
        const target = Math.max(0, Math.min(maxScroll, Math.round(wrapper.scrollWidth * dayRatio - wrapper.clientWidth / 2)));
        wrapper.scrollLeft = target;
    };

    applyScroll();
    requestAnimationFrame(applyScroll);
    setTimeout(applyScroll, 0);
}

function updateProfitSummary(view) {
    if (!profitRevenueValue || !profitCogsValue || !profitNetValue) return;

    let revenue = 0;
    let cost = 0;
    let profit = 0;

    if (view === 'daily') {
        const month = profitMonthSelect.value;
        (profitDailyByMonth[month] || []).forEach((bucket) => {
            revenue += bucket.revenue;
            cost += bucket.cost;
            profit += bucket.profit;
        });
    } else if (view === 'weekly') {
        const month = profitMonthSelect.value;
        (profitWeeklyByMonth[month] || []).forEach((bucket) => {
            revenue += bucket.revenue;
            cost += bucket.cost;
            profit += bucket.profit;
        });
    } else {
        profitMonthlyItems.forEach((bucket) => {
            revenue += bucket.revenue;
            cost += bucket.cost;
            profit += bucket.profit;
        });
    }

    profitRevenueValue.textContent = formatCurrency(roundMoney(revenue));
    profitCogsValue.textContent = formatCurrency(roundMoney(cost));
    profitNetValue.textContent = formatCurrency(roundMoney(profit));
    profitNetValue.classList.toggle('is-negative', profit < 0);
}

function updateProfitView(animate = true) {
    if (!profitAnalyticsSelect || !profitAnalyticsChart || !profitMonthWrapper || !profitMonthSelect) return;

    const view = profitAnalyticsSelect.value;
    profitMonthWrapper.style.display = view === 'monthly' ? 'none' : 'inline-flex';

    if (view === 'daily') {
        const month = profitMonthSelect.value;
        const monthData = profitChartData(profitDailyByMonth[month] || profitDailyByMonth.jan);
        renderDetailChart(profitAnalyticsChart, monthData, `Daily Profit — ${profitMonthSelect.options[profitMonthSelect.selectedIndex].text}`, animate);
        centerChartOnCurrentDay(profitAnalyticsChart, month, monthData.length);
    } else if (view === 'weekly') {
        const month = profitMonthSelect.value;
        const monthData = profitChartData(profitWeeklyByMonth[month] || profitWeeklyByMonth.jan);
        renderDetailChart(profitAnalyticsChart, monthData, `Weekly Profit — ${profitMonthSelect.options[profitMonthSelect.selectedIndex].text}`, animate);
    } else {
        renderDetailChart(profitAnalyticsChart, profitChartData(profitMonthlyItems), 'Monthly Profit', animate);
    }

    updateProfitSummary(view);
}

if (analyticsSelect) {
    analyticsSelect.addEventListener('change', updateAnalyticsView);
}
if (analyticsMonthSelect) {
    analyticsMonthSelect.addEventListener('change', updateAnalyticsView);
}
if (profitAnalyticsSelect) {
    profitAnalyticsSelect.addEventListener('change', () => updateProfitView());
}
if (profitMonthSelect) {
    profitMonthSelect.addEventListener('change', () => updateProfitView());
}

/* ================= Insights (hourly / best sellers / period compare / PDF) ================= */
const insightsHourlyChart = document.getElementById('insightsHourlyChart');
const insightsBestSellers = document.getElementById('insightsBestSellers');
const insightsComparePeriodA = document.getElementById('insightsComparePeriodA');
const insightsComparePeriodB = document.getElementById('insightsComparePeriodB');
const insightsCompareBtn = document.getElementById('insightsCompareBtn');
const insightsCompareResult = document.getElementById('insightsCompareResult');
const insightsExportBtn = document.getElementById('insightsExportBtn');
const insightsPeriodFilter = document.getElementById('insightsPeriodFilter');
const insightsCustomRange = document.getElementById('insightsCustomRange');
const insightsCustomFrom = document.getElementById('insightsCustomFrom');
const insightsCustomTo = document.getElementById('insightsCustomTo');

// Server-filtered completed orders for the active Insights period filter.
// 'all' keeps using the client-side latest-500 list; any other period fetches
// the full range from the server so insights can reach orders older than 500.
let insightsOrdersCache = [];
let insightsOrdersCacheKey = 'all';
let insightsOrdersSyncInFlight = false;

function getInsightsFilterKey() {
    return insightsPeriodFilter ? insightsPeriodFilter.value || 'all' : 'all';
}

function parseLocalDateInput(value) {
    const parts = String(value || '').split('-');
    if (parts.length !== 3) return null;
    const year = Number(parts[0]);
    const month = Number(parts[1]);
    const day = Number(parts[2]);
    if (!year || !month || !day) return null;
    const date = new Date(year, month - 1, day);
    return Number.isNaN(date.getTime()) ? null : date;
}

// Returns [from, to] local-midnight Dates for a custom range, or null when
// either end is missing/invalid. 'to' is the midnight AFTER the chosen end day
// so the whole day is included (the filter uses [from, to) comparisons).
function getInsightsCustomRange() {
    if (!insightsCustomFrom || !insightsCustomTo) return null;
    const from = parseLocalDateInput(insightsCustomFrom.value);
    const toRaw = parseLocalDateInput(insightsCustomTo.value);
    if (!from || !toRaw) return null;
    return [from, new Date(toRaw.getTime() + 86400000)];
}

// Cache key that also encodes the exact custom dates, so changing the range
// invalidates the previously fetched server cache.
function getInsightsCacheKey() {
    const key = getInsightsFilterKey();
    if (key !== 'custom') return key;
    const range = getInsightsCustomRange();
    return range && range[0] && range[1]
        ? `custom:${range[0].toISOString()}:${range[1].toISOString()}`
        : 'custom:invalid';
}

function getInsightsFilterLabel() {
    const key = getInsightsFilterKey();
    if (key === 'all') return 'All time';
    if (key === 'custom') {
        const range = getInsightsCustomRange();
        if (range && range[0] && range[1]) {
            const fmt = (d) => d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
            return `${fmt(range[0])} – ${fmt(new Date(range[1].getTime() - 86400000))}`;
        }
        return 'Custom Range';
    }
    return INSIGHT_PERIOD_LABELS[key] || key;
}

function getFilteredOrdersForInsights() {
    const key = getInsightsFilterKey();
    const cacheKey = getInsightsCacheKey();
    if (key === 'all') return getCompletedOrdersForInsights();
    if (insightsOrdersCacheKey === cacheKey && Array.isArray(insightsOrdersCache)) {
        return insightsOrdersCache;
    }
    // Fallback while the server fetch is in flight (or if it failed): filter
    // whatever the client already has loaded.
    const orders = getCompletedOrdersForInsights();
    const now = new Date();
    const [from, to] = getInsightPeriodRange(key, now);
    if (!from || !to) return orders;
    return orders.filter((order) => {
        const ms = parseOrderDateMs(order);
        return !Number.isNaN(ms) && ms >= from.getTime() && ms < to.getTime();
    });
}

function syncInsightsCustomRangeVisibility() {
    if (!insightsCustomRange) return;
    insightsCustomRange.hidden = getInsightsFilterKey() !== 'custom';
}

async function refreshInsightsOrdersFromServer() {
    const key = getInsightsFilterKey();
    const cacheKey = getInsightsCacheKey();
    if (!isStaffPage || key === 'all' || cacheKey === 'custom:invalid') {
        insightsOrdersCache = [];
        insightsOrdersCacheKey = 'all';
        renderInsights();
        return;
    }
    if (insightsOrdersSyncInFlight) return;
    insightsOrdersSyncInFlight = true;
    try {
        await ensureStaffServerSession();
        const now = new Date();
        const [from, to] = getInsightPeriodRange(key, now);
        if (!from || !to) return;
        const url = getApiUrl(`api/get_completed_orders.php?from=${encodeURIComponent(from.toISOString())}&to=${encodeURIComponent(to.toISOString())}&_=${Date.now()}`);
        const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
        if (!response.ok) return;
        const payload = await response.json();
        if (!payload || payload.success !== true || !Array.isArray(payload.orders)) return;
        // Only apply the result if the filter hasn't changed while fetching.
        if (getInsightsCacheKey() !== cacheKey) return;
        const normalized = payload.orders.map(normalizeCompletedOrder);
        normalized.sort((a, b) => b.timestamp - a.timestamp);
        insightsOrdersCache = normalized;
        insightsOrdersCacheKey = cacheKey;
    } catch (error) {
        console.error('Unable to load filtered completed orders', error);
    } finally {
        insightsOrdersSyncInFlight = false;
    }
    renderInsights();
}

function getCompletedOrdersForInsights() {
    return Array.isArray(completedOrders) ? completedOrders : [];
}

function parseOrderDateMs(order) {
    if (order.order_date_iso) {
        const ms = Date.parse(order.order_date_iso);
        if (!Number.isNaN(ms)) return ms;
    }
    if (order.order_date) {
        const ms = Date.parse(order.order_date);
        if (!Number.isNaN(ms)) return ms;
    }
    return Number.isFinite(order.timestamp) ? order.timestamp : NaN;
}

function renderInsights() {
    const orders = getFilteredOrdersForInsights();
    renderInsightsHourlyChart(orders);
    renderInsightsBestSellers(orders);
    renderInsightsComparison();
}

let insightsHourlyChartInstance = null;

function renderInsightsHourlyChart(orders) {
    if (!insightsHourlyChart) return;
    const hourly = new Array(24).fill(0);
    orders.forEach((order) => {
        const ms = parseOrderDateMs(order);
        if (Number.isNaN(ms)) return;
        const hour = new Date(ms).getHours();
        if (hour >= 0 && hour <= 23) hourly[hour] += 1;
    });
    const labels = Array.from({ length: 24 }, (_, hour) => `${String(hour).padStart(2, '0')}:00`);
    const note = `<p class="insights-hourly-note">Orders by hour of day — ${escapeHtml(getInsightsFilterLabel())}. Hover a bar for details.</p>`;

    // Fallback: the old CSS bar chart when the Chart.js CDN didn't load.
    if (typeof Chart === 'undefined') {
        insightsHourlyChartInstance = null;
        const max = Math.max(1, ...hourly);
        insightsHourlyChart.innerHTML = `
            <div class="insights-hourly-bars">
                ${hourly.map((count, hour) => `
                    <div class="insights-hourly-col" title="${hour}:00 — ${count} order(s)">
                        <div class="insights-hourly-bar" style="height:${Math.round((count / max) * 100)}%"></div>
                        <span class="insights-hourly-label">${hour}:00</span>
                    </div>
                `).join('')}
            </div>
            ${note}
        `;
        return;
    }

    // Chart.js rendering. Reuse the canvas/instance across renders so the
    // animation isn't replayed every background refresh.
    let canvas = insightsHourlyChart.querySelector('canvas');
    if (!canvas) {
        insightsHourlyChart.innerHTML = '<div class="insights-hourly-chart-canvas"><canvas></canvas></div>' + note;
        canvas = insightsHourlyChart.querySelector('canvas');
    }
    if (!canvas) return;

    const isDark = document.body.classList.contains('staff-app');
    const gridColor = isDark ? 'rgba(148, 163, 184, 0.18)' : 'rgba(148, 163, 184, 0.25)';
    const tickColor = isDark ? '#94a3b8' : '#64748b';

    const dataset = {
        label: 'Orders',
        data: hourly,
        backgroundColor: 'rgba(99, 102, 241, 0.75)',
        borderColor: '#6366f1',
        borderWidth: 1,
        borderRadius: 4,
        maxBarThickness: 18
    };

    if (insightsHourlyChartInstance) {
        insightsHourlyChartInstance.data.labels = labels;
        insightsHourlyChartInstance.data.datasets[0].data = hourly;
        insightsHourlyChartInstance.options.scales.x.ticks.color = tickColor;
        insightsHourlyChartInstance.options.scales.y.ticks.color = tickColor;
        insightsHourlyChartInstance.options.scales.y.grid.color = gridColor;
        insightsHourlyChartInstance.update();
        const noteEl = insightsHourlyChart.querySelector('.insights-hourly-note');
        if (noteEl) noteEl.outerHTML = note;
        return;
    }

    insightsHourlyChartInstance = new Chart(canvas, {
        type: 'bar',
        data: { labels, datasets: [dataset] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 350 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items) => (items.length ? `${items[0].label} — orders` : ''),
                        label: (ctx) => `${ctx.parsed.y} order(s)`
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: tickColor, font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 }
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: tickColor, precision: 0, font: { size: 10 } },
                    grid: { color: gridColor }
                }
            }
        }
    });
}

function renderInsightsBestSellers(orders) {
    if (!insightsBestSellers) return;
    const units = new Map();
    orders.forEach((order) => {
        (Array.isArray(order.items) ? order.items : []).forEach((item) => {
            const name = String(item.name || item.notes || '').trim();
            if (!name) return;
            units.set(name, (units.get(name) || 0) + (Number(item.quantity) || 0));
        });
    });
    const ranked = [...units.entries()].sort((a, b) => b[1] - a[1]).slice(0, 10);
    if (!ranked.length) {
        insightsBestSellers.innerHTML = `<p class="menu-cart-empty">No completed orders for ${escapeHtml(getInsightsFilterLabel())}.</p>`;
        return;
    }
    const max = ranked[0][1];
    insightsBestSellers.innerHTML = ranked.map(([name, qty], index) => `
        <div class="insights-bestseller-row">
            <span class="insights-bestseller-rank">${index + 1}</span>
            <span class="insights-bestseller-name">${escapeHtml(name)}</span>
            <div class="insights-bestseller-track"><div class="insights-bestseller-fill" style="width:${Math.round((qty / max) * 100)}%"></div></div>
            <strong class="insights-bestseller-qty">${qty}</strong>
        </div>
    `).join('');
}

function getInsightPeriodRange(periodKey, now = new Date()) {
    const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startOfWeek = new Date(startOfDay);
    startOfWeek.setDate(startOfWeek.getDate() - ((startOfWeek.getDay() + 6) % 7));
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    if (periodKey === 'custom') {
        const range = getInsightsCustomRange();
        return range || [null, null];
    }
    switch (periodKey) {
        case 'today': return [startOfDay, new Date(startOfDay.getTime() + 86400000)];
        case 'yesterday': return [new Date(startOfDay.getTime() - 86400000), startOfDay];
        case 'thisweek': return [startOfWeek, new Date(startOfWeek.getTime() + 7 * 86400000)];
        case 'lastweek': return [new Date(startOfWeek.getTime() - 7 * 86400000), startOfWeek];
        case 'thismonth': return [startOfMonth, new Date(now.getFullYear(), now.getMonth() + 1, 1)];
        case 'lastmonth': return [new Date(now.getFullYear(), now.getMonth() - 1, 1), startOfMonth];
        default: return [startOfDay, new Date(startOfDay.getTime() + 86400000)];
    }
}

function summarizeOrdersForPeriod(orders, from, to) {
    const filtered = orders.filter((order) => {
        const ms = parseOrderDateMs(order);
        return !Number.isNaN(ms) && ms >= from.getTime() && ms < to.getTime();
    });
    const revenue = filtered.reduce((sum, order) => sum + (Number(order.total_amount ?? order.total) || 0), 0);
    let items = 0;
    let cost = 0;
    filtered.forEach((order) => {
        (Array.isArray(order.items) ? order.items : []).forEach((item) => {
            items += Number(item.quantity) || 0;
        });
        cost += getOrderProfitBreakdown(order).cost;
    });
    const count = filtered.length;
    return {
        orders: count,
        revenue,
        items,
        cost,
        profit: revenue - cost,
        avgOrderValue: count > 0 ? revenue / count : 0
    };
}

const INSIGHT_PERIOD_LABELS = {
    today: 'Today',
    yesterday: 'Yesterday',
    thisweek: 'This Week',
    lastweek: 'Last Week',
    thismonth: 'This Month',
    lastmonth: 'Last Month'
};

function renderInsightsComparison() {
    if (!insightsCompareResult) return;
    const orders = getCompletedOrdersForInsights();
    const periodA = insightsComparePeriodA ? insightsComparePeriodA.value || 'today' : 'today';
    const periodB = insightsComparePeriodB ? insightsComparePeriodB.value || 'yesterday' : 'yesterday';
    const labelA = INSIGHT_PERIOD_LABELS[periodA] || periodA;
    const labelB = INSIGHT_PERIOD_LABELS[periodB] || periodB;
    const now = new Date();
    const [aFrom, aTo] = getInsightPeriodRange(periodA, now);
    const [bFrom, bTo] = getInsightPeriodRange(periodB, now);
    const a = summarizeOrdersForPeriod(orders, aFrom, aTo);
    const b = summarizeOrdersForPeriod(orders, bFrom, bTo);
    const changePct = (current, previous) => {
        if (!previous) return current > 0 ? 100 : 0;
        return Math.round(((current - previous) / previous) * 100);
    };
    const changeCell = (current, previous) => {
        const pct = changePct(current, previous);
        const cls = pct >= 0 ? 'is-up' : 'is-down';
        const sign = pct >= 0 ? '+' : '';
        return `<td class="${cls}">${sign}${pct}%</td>`;
    };
    const profitLabel = (summary) => {
        if (summary.cost > 0) return `${formatCurrency(summary.profit)} <span class="compare-sub">(COGS ${formatCurrency(summary.cost)})</span>`;
        return formatCurrency(summary.profit);
    };
    insightsCompareResult.innerHTML = `
        <table class="insights-compare-table">
            <thead>
                <tr><th></th><th>${labelA}</th><th>${labelB}</th><th>Change</th></tr>
            </thead>
            <tbody>
                <tr><td>Orders</td><td>${a.orders}</td><td>${b.orders}</td>${changeCell(a.orders, b.orders)}</tr>
                <tr><td>Revenue</td><td>${formatCurrency(a.revenue)}</td><td>${formatCurrency(b.revenue)}</td>${changeCell(a.revenue, b.revenue)}</tr>
                <tr><td>Profit</td><td>${profitLabel(a)}</td><td>${profitLabel(b)}</td>${changeCell(a.profit, b.profit)}</tr>
                <tr><td>Avg order value</td><td>${formatCurrency(a.avgOrderValue)}</td><td>${formatCurrency(b.avgOrderValue)}</td>${changeCell(a.avgOrderValue, b.avgOrderValue)}</tr>
                <tr><td>Items sold</td><td>${a.items}</td><td>${b.items}</td>${changeCell(a.items, b.items)}</tr>
            </tbody>
        </table>
    `;
}

function exportInsightsReport() {
    if (!isStaffPage) return;
    if (typeof XLSX === 'undefined') {
        showStaffNotice('Excel library is not loaded. Check your connection and refresh the page.', true);
        return;
    }

    const allOrders = getCompletedOrdersForInsights();
    const orders = getFilteredOrdersForInsights();
    const now = new Date();

    const hourly = new Array(24).fill(0);
    const units = new Map();
    let revenue = 0;
    orders.forEach((order) => {
        revenue += Number(order.total_amount ?? order.total) || 0;
        const ms = parseOrderDateMs(order);
        if (!Number.isNaN(ms)) hourly[new Date(ms).getHours()] += 1;
        (Array.isArray(order.items) ? order.items : []).forEach((item) => {
            const name = String(item.name || item.notes || '').trim();
            if (name) units.set(name, (units.get(name) || 0) + (Number(item.quantity) || 0));
        });
    });

    const periodA = insightsComparePeriodA ? insightsComparePeriodA.value || 'today' : 'today';
    const periodB = insightsComparePeriodB ? insightsComparePeriodB.value || 'yesterday' : 'yesterday';
    const [aFrom, aTo] = getInsightPeriodRange(periodA, now);
    const [bFrom, bTo] = getInsightPeriodRange(periodB, now);
    const a = summarizeOrdersForPeriod(allOrders, aFrom, aTo);
    const b = summarizeOrdersForPeriod(allOrders, bFrom, bTo);
    const changePct = (current, previous) => {
        if (!previous) return current > 0 ? 100 : 0;
        return Math.round(((current - previous) / previous) * 100);
    };

    const summaryRows = [
        ['MOTASTE Sales Insights'],
        [],
        ['Generated', now.toLocaleString()],
        ['Filter', getInsightsFilterLabel()],
        ['Orders in view', orders.length],
        ['Total revenue (₱)', Number(revenue.toFixed(2))],
        [],
        [INSIGHT_PERIOD_LABELS[periodA] || periodA],
        ['Orders', a.orders],
        ['Revenue (₱)', Number(a.revenue.toFixed(2))],
        ['Profit (₱)', Number(a.profit.toFixed(2))],
        ['COGS (₱)', Number(a.cost.toFixed(2))],
        ['Avg order value (₱)', Number(a.avgOrderValue.toFixed(2))],
        ['Items sold', a.items],
        [],
        [INSIGHT_PERIOD_LABELS[periodB] || periodB],
        ['Orders', b.orders],
        ['Revenue (₱)', Number(b.revenue.toFixed(2))],
        ['Profit (₱)', Number(b.profit.toFixed(2))],
        ['COGS (₱)', Number(b.cost.toFixed(2))],
        ['Avg order value (₱)', Number(b.avgOrderValue.toFixed(2))],
        ['Items sold', b.items],
        [],
        ['Orders change (%)', changePct(a.orders, b.orders)],
        ['Revenue change (%)', changePct(a.revenue, b.revenue)],
        ['Profit change (%)', changePct(a.profit, b.profit)],
        ['Avg order value change (%)', changePct(a.avgOrderValue, b.avgOrderValue)],
        ['Items change (%)', changePct(a.items, b.items)],
    ];

    const hourlyRows = [];
    hourly.forEach((count, hour) => {
        if (count > 0) hourlyRows.push([`${String(hour).padStart(2, '0')}:00`, count]);
    });

    const bestSellerRows = [...units.entries()]
        .sort((x, y) => y[1] - x[1])
        .slice(0, 15)
        .map(([name, qty], index) => [index + 1, excelSafe(name), qty]);

    const workbook = XLSX.utils.book_new();
    const summarySheet = XLSX.utils.aoa_to_sheet(summaryRows);
    summarySheet['!cols'] = [{ wch: 34 }, { wch: 24 }];
    XLSX.utils.book_append_sheet(workbook, summarySheet, 'Summary');
    XLSX.utils.book_append_sheet(workbook, buildExcelSheet(hourlyRows, ['Hour', 'Orders'], [12, 12]), 'Busiest Hours');
    XLSX.utils.book_append_sheet(workbook, buildExcelSheet(bestSellerRows, ['Rank', 'Food Item', 'Qty Sold'], [8, 40, 12]), 'Best Sellers');
    XLSX.writeFile(workbook, `MOTASTE-Insights-${now.toISOString().slice(0, 10)}.xlsx`);
}

if (insightsCompareBtn) {
    insightsCompareBtn.addEventListener('click', renderInsightsComparison);
}
if (insightsExportBtn) {
    insightsExportBtn.addEventListener('click', exportInsightsReport);
}
if (insightsPeriodFilter) {
    insightsPeriodFilter.addEventListener('change', () => {
        syncInsightsCustomRangeVisibility();
        // Render immediately from whatever the client has (fallback filter),
        // then let the server fetch refine it with the full date range.
        renderInsights();
        void refreshInsightsOrdersFromServer();
    });
}
[insightsCustomFrom, insightsCustomTo].forEach((input) => {
    if (input) {
        input.addEventListener('change', () => {
            renderInsights();
            void refreshInsightsOrdersFromServer();
        });
    }
});
syncInsightsCustomRangeVisibility();

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
const inventoryAdminPanel = document.getElementById('inventoryAdminPanel');
const inventoryForm = document.getElementById('inventoryForm');
const inventoryNameInput = document.getElementById('inventoryNameInput');
const inventoryCategoryInput = document.getElementById('inventoryCategoryInput');
const inventoryDescriptionInput = document.getElementById('inventoryDescriptionInput');
const inventoryPriceInput = document.getElementById('inventoryPriceInput');
const inventoryStockInput = document.getElementById('inventoryStockInput');
const inventoryStatusInput = document.getElementById('inventoryStatusInput');
const inventoryUnitCostInput = document.getElementById('inventoryUnitCostInput');
const inventoryAvailabilityInput = document.getElementById('inventoryAvailabilityInput');
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
const productDetailPurchaseBtn = document.getElementById('productDetailPurchaseBtn');
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
const walkInItemInput = document.getElementById('walkInItemInput');
const walkInItemDropdown = document.getElementById('walkInItemDropdown');
let walkInAvailableItems = [];
let walkInDropdownOpen = false;
let walkInDropdownHighlight = -1;
const walkInItemQtyInput = document.getElementById('walkInItemQtyInput');
const walkInAddItemBtn = document.getElementById('walkInAddItemBtn');
const walkInDraftList = document.getElementById('walkInDraftList');
const walkInPaymentMethodSelect = document.getElementById('walkInPaymentMethodSelect');
const walkInOrderTypeSelect = document.getElementById('walkInOrderTypeSelect');
const walkInPlaceOrderBtn = document.getElementById('walkInPlaceOrderBtn');
const walkInOrderMessage = document.getElementById('walkInOrderMessage');
const logsSection = document.getElementById('logs');
const logsFilterBar = document.getElementById('logsFilterBar');
const logsCategoryFilter = document.getElementById('logsCategoryFilter');
const logsDateFilter = document.getElementById('logsDateFilter');
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
let selectedSpecialFoodImageFile = null;
let cachedReviews = [];
let cachedStaffReviews = [];
let activeReviewRatingFilter = 0;
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

function pruneEmptySpecialFoodsFromCart() {
    const initialLength = cartItems.length;
    cartItems = cartItems.filter((item) => {
        const baseComponents = getCartItemBaseComponents(item);
        if (!baseComponents.length) return true;

        const currentComponents = normalizeCartComponents(item.components);
        return currentComponents.some((component) => Math.max(0, Number(component.quantity) || 0) > 0);
    });
    return cartItems.length !== initialLength;
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

function updateCartItemComponentsByRecipe(cartItem, recipeComponents, quantityDelta) {
    if (!Array.isArray(recipeComponents) || !recipeComponents.length) return;
    if (!Array.isArray(cartItem.components)) {
        cartItem.components = [];
    }

    recipeComponents.forEach((recipeComponent) => {
        const normalizedName = normalizeInventoryName(recipeComponent.name);
        if (!normalizedName) return;

        const perDishQty = Math.max(0, Number(recipeComponent.quantity) || 0);
        if (perDishQty <= 0) return;

        const changeBy = perDishQty * quantityDelta;
        let component = cartItem.components.find((entry) => normalizeInventoryName(entry.name) === normalizedName);

        if (!component) {
            component = { name: recipeComponent.name, quantity: 0 };
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
        updateSpecialPriceForModal();
        return;
    }

    specialCustomizeList.innerHTML = selectedSpecialComponents.map((entry, index) => `
        <div class="special-customize-item">
            <span>${escapeHtml(entry.name)} x${entry.quantity}</span>
            <button type="button" class="special-customize-remove-btn" data-index="${index}">Remove</button>
        </div>
    `).join('');
    updateSpecialPriceForModal();
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
            selectedSpecialFoodImageFile = file;
            setSpecialFoodImagePreview(dataUrl);
        } catch (error) {
            selectedSpecialFoodImageData = '';
            selectedSpecialFoodImageFile = null;
            setSpecialFoodImagePreview('');
            await showStaffNotice('Unable to process the selected image. Please choose another image.', true);
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
    // Hide unit cost for specials — cost is derived from component prices.
    const unitCostField = document.getElementById('inventoryUnitCostField');
    if (unitCostField) {
        unitCostField.hidden = isSpecials;
    }

    if (isSpecials) {
        renderSpecialCustomizeControls();
        updateSpecialPriceForModal();
    }

    if (!isSpecials) {
        selectedSpecialFoodImageData = '';
        selectedSpecialFoodImageFile = null;
        selectedSpecialComponents = [];
        if (specialFoodImageInput) {
            specialFoodImageInput.value = '';
            specialFoodImageInput.disabled = true;
        }
        setSpecialFoodImagePreview('');
    }

    if (inventoryPriceInput) {
        inventoryPriceInput.disabled = isSpecials;
        inventoryPriceInput.placeholder = isSpecials ? 'Price auto-calculated from components' : 'Price';
    }

    // Ensure inputs are disabled when not specials to prevent interaction
    if (specialFoodImageInput) specialFoodImageInput.disabled = !isSpecials;
    if (specialCustomizeItemSelect) specialCustomizeItemSelect.disabled = !isSpecials;
    if (specialCustomizeAddBtn) specialCustomizeAddBtn.disabled = !isSpecials;
}

// Force style-level hide/show to override any stylesheet or rendering timing issues
function enforceSpecialFieldsVisibility() {
    if (!specialFoodImageField || !specialCustomizeField || !specialFoodImagePreviewWrap || !inventoryCategoryInput) return;
    const isSpecials = inventoryCategoryInput.value === 'specials';
    specialFoodImageField.style.display = isSpecials ? '' : 'none';
    specialCustomizeField.style.display = isSpecials ? '' : 'none';
    specialFoodImagePreviewWrap.style.display = isSpecials ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    // ensure correct visibility immediately after load
    updateSpecialFoodImageFieldVisibility();
    enforceSpecialFieldsVisibility();
});

// Run enforcement when modal is opened or category changes
if (inventoryCategoryInput) inventoryCategoryInput.addEventListener('change', () => {
    updateSpecialFoodImageFieldVisibility();
    enforceSpecialFieldsVisibility();
});

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
        updateSpecialPriceForModal();
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
        updateSpecialPriceForModal();
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

// The page's strict CSP (script-src without 'unsafe-inline') blocks inline
// onclick attributes, so the inventory FAB and modal close button are wired
// up here with addEventListener instead of inline handlers.
if (inventoryAddFab) {
    inventoryAddFab.addEventListener('click', openInventoryModal);
}
if (inventoryModalCloseBtn) {
    inventoryModalCloseBtn.addEventListener('click', closeInventoryModal);
}

if (overviewAnalyticsSelect) {
    overviewAnalyticsSelect.addEventListener('change', renderOverviewAnalytics);
}
if (overviewMonthSelect) {
    overviewMonthSelect.addEventListener('change', renderOverviewAnalytics);
}

syncAnalyticsMonthSelectorsToCurrentMonth();
updateAnalyticsView();

function renderAnalytics(type, animate = true) {
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
    const pointCount = data.items.length;
    const svgWidth = Math.max(600, Math.min(1200, pointCount * 55 + 140));
    const svgHeight = 280;
    const margin = { top: 36, right: 28, bottom: 64, left: 62 };
    const chartWidth = svgWidth - margin.left - margin.right;
    const chartHeight = svgHeight - margin.top - margin.bottom;
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
        <svg width="${svgWidth}" viewBox="0 0 ${svgWidth} ${svgHeight}" role="img" aria-label="${data.title} line chart">
            <rect x="0" y="0" width="${svgWidth}" height="${svgHeight}" fill="#ffffff" rx="16" />
            <g>
                <line x1="${margin.left}" y1="${margin.top}" x2="${margin.left}" y2="${margin.top + chartHeight}" stroke="#999" stroke-width="1.5" />
                <line x1="${margin.left}" y1="${margin.top + chartHeight}" x2="${margin.left + chartWidth}" y2="${margin.top + chartHeight}" stroke="#999" stroke-width="1.5" />
            </g>
            <g>
                ${yTicks.map((tick) => `
                    <line x1="${margin.left}" y1="${tick.y}" x2="${margin.left + chartWidth}" y2="${tick.y}" stroke="rgba(150,150,150,0.3)" />
                    <text x="${margin.left - 14}" y="${tick.y + 5}" text-anchor="end" fill="#1e293b" font-size="13" font-weight="700">${tick.value}</text>
                `).join('')}
            </g>
            <g${animate ? ' class="sales-line-dots"' : ''}>
                ${points.map((point, index) => `
                    <circle cx="${point.x}" cy="${point.y}" r="6" fill="#fff" stroke="#2BAE66" stroke-width="2.5"${animate ? ` style="animation-delay: ${(index * 0.05).toFixed(2)}s"` : ''} />
                    <text x="${point.x}" y="${point.y - 14}" text-anchor="middle" fill="#0f172a" font-size="14" font-weight="800">${point.display}</text>
                `).join('')}
            </g>
            <path${animate ? ' class="sales-line-path"' : ''} d="${pathD}"${animate ? ' pathLength="1"' : ''} fill="none" stroke="#2BAE66" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
            <g>
                ${points.map((point) => `
                    <text x="${point.x}" y="${margin.top + chartHeight + 28}" text-anchor="middle" fill="#475569" font-size="13" font-weight="700">${point.label}</text>
                `).join('')}
            </g>
            <text x="${margin.left}" y="22" fill="#1e293b" font-size="15" font-weight="700">${data.title}</text>
        </svg>
    `;

    analyticsChart.innerHTML = svg;
}

if (salesLink && salesSection) {
    salesLink.addEventListener('click', (event) => {
        event.preventDefault();
        showDashboardSection(salesSection);
        updateAnalyticsView();
        updateProfitView();
        renderInsights();
    });
}

salesTabBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
        const tabName = btn.dataset.tab;
        setActiveSalesTab(tabName);

        if (tabName === 'insights') {
            renderInsights();
        }
    });
});

if (toggleAccountFormBtn) {
    toggleAccountFormBtn.addEventListener('click', () => {
        if (!document.body.classList.contains('auth') || !(selectedRoleInput && selectedRoleInput.value === 'Admin')) {
            showStaffNotice('Only the admin can manage accounts.', true);
            return;
        }
        accountEditIndex = null;
        renderAccounts();
        toggleAccountForm(true);
        if (accountNameInput) {
            accountNameInput.focus();
        }
    });
}

if (cancelAccountFormBtn) {
    cancelAccountFormBtn.addEventListener('click', () => {
        toggleAccountForm(false);
    });
}

if (accountForm) {
    accountForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!document.body.classList.contains('auth') || !(selectedRoleInput && selectedRoleInput.value === 'Admin')) {
            await showStaffNotice('Only the admin can manage accounts.', true);
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
            await showStaffNotice('Only Cashier and Inventory Manager accounts can be managed here.', true);
            return;
        }

        if (!isGmailAddress(account.email)) {
            await showStaffNotice('Only Gmail addresses are allowed for cashier/inventory accounts.', true);
            return;
        }

        const duplicateIndex = accounts.findIndex((entry, idx) => idx !== accountEditIndex && (entry.email || '').toLowerCase() === account.email);
        if (duplicateIndex >= 0) {
            await showStaffNotice('This email is already registered.', true);
            return;
        }

        const previousAccount = accountEditIndex !== null ? accounts[accountEditIndex] : null;

        if (accountEditIndex !== null && previousAccount) {
            account.inviteConfirmed = previousAccount.inviteConfirmed;
        }

        let invitePayload = null;
        if (accountEditIndex === null) {
            try {
                invitePayload = await sendStaffInviteEmail(account);
            } catch (error) {
                await showStaffNotice(error.message || 'Unable to send invite email.', true);
                return;
            }
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
        const syncResult = await saveStaffAccountsToServer();
        if (!syncResult.success) {
            await showStaffNotice(`Unable to save staff account to the server. ${syncResult.error || 'Please try again or contact support.'}`, true);
            return;
        }

        renderAccounts();
        toggleAccountForm(false);
        if (accountEditIndex !== null) {
            await showStaffNotice('Staff account updated successfully.');
        } else if (invitePayload && invitePayload.delivered === false) {
            await showStaffNotice(invitePayload.error || 'Invite email was not delivered. Check Laravel SMTP settings and try again.', true);
        } else {
            await showStaffNotice('Invite email sent. The staff account can login after confirming the email verification code.');
        }
    });
}

if (accountList) {
    accountList.addEventListener('click', async (event) => {
        const button = event.target.closest('button');
        if (!button) return;

        const index = Number(button.dataset.index);

        if (button.classList.contains('cancel-btn')) {
            accountEditIndex = null;
            renderAccounts();
            return;
        }

        if (button.classList.contains('save-btn')) {
            const row = button.closest('.account-inline-editor');
            if (!row || Number.isNaN(index)) return;

            const updatedAccount = {
                name: (row.querySelector('[data-field="name"]')?.value || '').trim(),
                role: row.querySelector('[data-field="role"]')?.value || '',
                email: (row.querySelector('[data-field="email"]')?.value || '').trim().toLowerCase(),
                password: row.querySelector('[data-field="password"]')?.value || '',
                inviteConfirmed: false
            };

            if (!updatedAccount.name || !updatedAccount.role || !updatedAccount.email || !updatedAccount.password) {
                await showStaffNotice('Please complete all staff account fields.', true);
                return;
            }

            if (updatedAccount.role !== 'Cashier' && updatedAccount.role !== 'Inventory Manager') {
                await showStaffNotice('Only Cashier and Inventory Manager accounts can be managed here.', true);
                return;
            }

            if (!isGmailAddress(updatedAccount.email)) {
                await showStaffNotice('Only Gmail addresses are allowed for cashier/inventory accounts.', true);
                return;
            }

            const duplicateIndex = accounts.findIndex((entry, idx) => idx !== index && (entry.email || '').toLowerCase() === updatedAccount.email);
            if (duplicateIndex >= 0) {
                await showStaffNotice('This email is already registered.', true);
                return;
            }

            const previousAccount = accounts[index];
            updatedAccount.inviteConfirmed = previousAccount ? previousAccount.inviteConfirmed : false;

            accounts[index] = updatedAccount;
            void logStaffActivity('account_updated', `${updatedAccount.name} (${updatedAccount.role})`, {
                previous_name: previousAccount ? previousAccount.name : null,
                previous_role: previousAccount ? previousAccount.role : null,
                previous_email: previousAccount ? previousAccount.email : null,
                password_changed: previousAccount ? previousAccount.password !== updatedAccount.password : false,
                invite_confirmation_reset: true,
                next_name: updatedAccount.name,
                next_role: updatedAccount.role,
                next_email: updatedAccount.email
            });

            window.motasteStaffAccounts = accounts;
            const syncResult = await saveStaffAccountsToServer();
            if (!syncResult.success) {
                await showStaffNotice(`Unable to save staff account to the server. ${syncResult.error || 'Please try again or contact support.'}`, true);
                return;
            }

            accountEditIndex = null;
            renderAccounts();
            await showStaffNotice('Staff account updated successfully.');
            return;
        }

        if (button.classList.contains('delete-btn')) {
            const removedAccount = accounts[index];
            if (removedAccount && removedAccount.role === 'Admin') {
                await showStaffNotice('Admin account is managed through Credentials only.', true);
                return;
            }
            accounts.splice(index, 1);
            window.motasteStaffAccounts = accounts;
            const saveResult = await saveStaffAccountsToServer();
            if (!saveResult.success) {
                await showStaffNotice(`Unable to delete staff account on the server. ${saveResult.error || 'Please try again.'}`, true);
                accounts.splice(index, 0, removedAccount);
                window.motasteStaffAccounts = accounts;
                renderAccounts();
                return;
            }
            if (removedAccount) {
                void logStaffActivity('account_deleted', `${removedAccount.name} (${removedAccount.role})`, {
                    email: removedAccount.email,
                    role: removedAccount.role
                });
            }
            void loadOrderLogsFromServer(true);
            renderAccounts();
            if (removedAccount) {
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
            return;
        }

        if (button.classList.contains('edit-btn')) {
            const selectedAccount = accounts[index];
            if (selectedAccount) {
                if (selectedAccount.role === 'Admin') {
                    await showStaffNotice('Admin account is managed through Credentials only.', true);
                    return;
                }
                accountEditIndex = index;
                if (accountForm) {
                    accountForm.hidden = true;
                }
                renderAccounts();
            }
        }
    });
}

if (toggleCredentialsFormBtn) {
    toggleCredentialsFormBtn.addEventListener('click', () => {
        if (!canAccessCredentials()) {
            setCredentialsMessage('Only admin can change credentials.', true);
            return;
        }
        if (passwordCredentialsForm) {
            passwordCredentialsForm.hidden = true;
        }
        if (credentialsForm) {
            credentialsForm.hidden = !credentialsForm.hidden;
            if (!credentialsForm.hidden) {
                if (adminCurrentPasswordInput) adminCurrentPasswordInput.focus();
            }
        }
    });
}

if (togglePasswordFormBtn) {
    togglePasswordFormBtn.addEventListener('click', () => {
        if (!canAccessCredentials()) {
            setCredentialsMessage('Only admin can change credentials.', true);
            return;
        }
        if (credentialsForm) {
            credentialsForm.hidden = true;
        }
        if (passwordCredentialsForm) {
            passwordCredentialsForm.hidden = !passwordCredentialsForm.hidden;
            if (!passwordCredentialsForm.hidden) {
                if (adminPasswordCurrentPasswordInput) adminPasswordCurrentPasswordInput.focus();
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
        void requestAdminCredentialsChange({
            currentEmailInput: adminCurrentEmailInput,
            currentPasswordInput: adminCurrentPasswordInput,
            newEmailInput: adminNewEmailInput,
            newPasswordInput: adminNewPasswordInput,
            shouldRequireEmail: true
        });
    });
}

if (requestPasswordChangeBtn) {
    requestPasswordChangeBtn.addEventListener('click', () => {
        if (!canAccessCredentials()) {
            setCredentialsMessage('Only admin can change credentials.', true);
            return;
        }
        void requestAdminCredentialsChange({
            currentEmailInput: adminPasswordCurrentEmailInput,
            currentPasswordInput: adminPasswordCurrentPasswordInput,
            newEmailInput: null,
            newPasswordInput: adminPasswordNewPasswordInput,
            shouldRequireEmail: false
        });
    });
}

if (credentialsForm) {
    credentialsForm.addEventListener('submit', (event) => {
        if (!canAccessCredentials()) {
            event.preventDefault();
            setCredentialsMessage('Only admin can change credentials.', true);
            return;
        }
        void confirmAdminCredentialsChange(event, 'email');
    });
}

if (passwordCredentialsForm) {
    passwordCredentialsForm.addEventListener('submit', (event) => {
        if (!canAccessCredentials()) {
            event.preventDefault();
            setCredentialsMessage('Only admin can change credentials.', true);
            return;
        }
        void confirmAdminCredentialsChange(event, 'password');
    });
}

renderAccounts();
toggleAccountForm(false);
if (isStaffPage) {
    void ensureCsrfToken();
    // Ensure dashboard is open by default for staff pages
    setDashboardPanelState(true);
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
    // Highlight data is persisted on the server only.
}

function loadHighlightsFromStorage() {
    highlightsSlides = [];
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
    specials: {
        title: 'SPECIALS',
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
const menuOverlayCategories = document.getElementById('menuOverlayCategories');
const menuOverlayHeader = document.querySelector('.menu-overlay-header');
const menuOverlayActionsPanel = document.querySelector('.menu-overlay-actions-panel');
const menuCategoryScreen = document.getElementById('menuCategoryScreen');
const menuItemsList = document.getElementById('menuItemsList');
const menuCartList = document.getElementById('menuCartList');
const menuCartCount = document.getElementById('menuCartCount');
const menuCartHeader = document.querySelector('.menu-cart-header');
const menuCartPanel = document.getElementById('menuCartPanel');
const menuCartTotal = document.getElementById('menuCartTotal');
const menuCartSummary = document.querySelector('.menu-cart-summary');
const menuPlaceOrderBtn = document.getElementById('menuPlaceOrderBtn');
const cartTitle = document.getElementById('cartTitle');
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
const menuAddToCartBtn = document.getElementById('menuAddToCartBtn');
const menuPurchaseNowBtn = document.getElementById('menuPurchaseNowBtn');
const specialFoodsList = document.getElementById('specialFoodsList');
const cartModal = document.getElementById('cart');
const closeCartButton = document.getElementById('closeCartButton');
const menuNavLink = document.querySelector('a[href="#menu"]');
const orderCheckoutScreen = document.getElementById('orderCheckoutScreen');
const orderCheckoutBackBtn = document.getElementById('orderCheckoutBackBtn');
const orderCheckoutExitBtn = document.getElementById('orderCheckoutExitBtn');
const confirmOrderBtn = document.getElementById('confirmOrderBtn');
const paymentMethodOptions = document.getElementById('paymentMethodOptions');
const codPaymentOption = document.getElementById('codPaymentOption');
const orderTypeOptions = document.getElementById('orderTypeOptions');
const customerNameInput = document.getElementById('customerNameInput');
const customerPhoneInput = document.getElementById('customerPhoneInput');
const customerEmailInput = document.getElementById('customerEmailInput');
const customerPhoneError = document.getElementById('customerPhoneError');
const customerEmailError = document.getElementById('customerEmailError');
const deliveryAddressSection = document.getElementById('deliveryAddressSection');
const deliveryAddressInput = document.getElementById('deliveryAddressInput');
const orderCheckoutItems = document.getElementById('orderCheckoutItems');
const orderCheckoutTotal = document.getElementById('orderCheckoutTotal');
const checkoutMessage = document.getElementById('checkoutMessage');
const orderPaymentScreen = document.getElementById('orderPaymentScreen');
const paymentConfirmationBackBtn = document.getElementById('paymentConfirmationBackBtn');
const orderPaymentNumber = document.getElementById('orderPaymentNumber');
const orderPaymentDatetime = document.getElementById('orderPaymentDatetime');
const orderPaymentMethod = document.getElementById('orderPaymentMethod');
const orderPaymentOrderType = document.getElementById('orderPaymentOrderType');
const orderPaymentCustomer = document.getElementById('orderPaymentCustomer');
const orderPaymentAddressRow = document.getElementById('orderPaymentAddressRow');
const orderPaymentAddress = document.getElementById('orderPaymentAddress');
const orderPaymentMessage = document.getElementById('orderPaymentMessage');
const paymentQrPlaceholder = document.getElementById('paymentQrPlaceholder');
const orderPaymentCloseBtn = document.getElementById('orderPaymentCloseBtn');
const paymentSuccessModal = document.getElementById('paymentSuccessModal');
const paymentSuccessCloseBtn = document.getElementById('paymentSuccessCloseBtn');
const liveClock = document.getElementById('liveClock');
const lowStockAlertStorageKey = 'motasteLowStockRemindLaterUntil';
const lowStockModalOverlay = document.getElementById('lowStockModalOverlay');
const lowStockModalCloseBtn = document.getElementById('lowStockModalCloseBtn');
const lowStockToggleListBtn = document.getElementById('lowStockToggleListBtn');
const lowStockItemDropdown = document.getElementById('lowStockItemDropdown');
const lowStockRemindLaterBtn = document.getElementById('lowStockRemindLaterBtn');

let cartItems = [];
let menuSelectionQuantities = {};
let pendingOrders = [];
let completedOrders = [];
let completedOrdersSyncInFlight = false;
let inventoryData = [];
let currentMenuCategoryId = null;
let showMenuCategoryRecursing = false;
let suppressMenuOverlay = false; // when true, prevent menu overlay from opening
let inventoryEditItemName = null;
let inventoryEditLock = false;
let ignoredPendingOrderNumbers = new Set();
const syncInventoryAcrossTabs = false;
const customerOrderNumbersStorageKey = 'motasteCustomerOrderNumbers';
const seenCompletedOrdersStorageKey = 'motasteSeenCompletedOrders';
const customerOrderTimerCacheKey = 'motasteCustomerOrderTimerCache';
let customerOrderNumbers = new Set();
let seenCompletedOrders = new Set();
let seenCancelledOrders = new Set();
let customerOrderStatusPoller = null;
let customerOrderStatuses = new Map();
let orderStatusFloatTicker = null;
let orderStatusFloatOpen = false;
let orderCompleteScrollLockState = null;
let orderNotificationAudioElement = null;
let orderNotificationAudioListenersBound = false;
let customerInventoryRefreshTimer = null;
let walkInDraftItems = [];
let activeOrdersTab = 'walk-in';
const isCustomerPage = (() => {
    const pathname = window.location.pathname.toLowerCase();
    // The homepage is served at '/' (public/home.html) via the PHP route.
    return pathname === '/' || (!pathname.includes('staff'));
})();

function loadIgnoredPendingOrders() {
    ignoredPendingOrderNumbers = new Set();
}

function saveIgnoredPendingOrders() {
    // Ignored pending orders are not persisted in local storage.
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
    cartItems = [];
}

function saveCart() {
    // Cart state is not persisted in local storage.
}

function loadPendingOrders() {
    pendingOrders = [];
}

function savePendingOrders() {
    // Pending orders are persisted directly on the server. No client-side localStorage persistence is used.
}

function loadCustomerOrderTracking() {
    customerOrderNumbers = new Set();
    seenCompletedOrders = new Set();

    // Persist the tracked order numbers so the floating status icon survives a
    // page refresh while the order is still active (pending/preparing).
    if (typeof window !== 'undefined' && window.localStorage) {
        try {
            const raw = window.localStorage.getItem(customerOrderNumbersStorageKey);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    parsed.forEach((number) => customerOrderNumbers.add(String(number)));
                }
            }
        } catch (error) {
            console.warn('Unable to restore customer order tracking', error);
        }
    }
}

function saveCustomerOrderTracking() {
    if (typeof window !== 'undefined' && window.localStorage) {
        try {
            window.localStorage.setItem(customerOrderNumbersStorageKey, JSON.stringify([...customerOrderNumbers]));
        } catch (error) {
            console.warn('Unable to persist customer order tracking', error);
        }
    }
}

function registerCustomerOrder(orderNumber) {
    if (!orderNumber && orderNumber !== 0) return;
    customerOrderNumbers.add(String(orderNumber));
    saveCustomerOrderTracking();
}

/**
 * Persist the prep-timer state (start timestamp + duration) in localStorage so
 * the countdown stays accurate immediately after a page refresh, even before
 * the first status poll returns. The server remains the source of truth.
 */
function saveCustomerOrderTimerCache() {
    if (typeof window === 'undefined' || !window.localStorage) return;
    try {
        const cache = [...customerOrderStatuses.entries()].map(([orderNumber, state]) => ({
            orderNumber: String(orderNumber),
            status: state.status || 'pending',
            prepMinutes: state.prepMinutes != null ? Number(state.prepMinutes) : null,
            prepStartedAt: state.prepStartedAt || null,
            orderType: state.orderType || ''
        }));
        window.localStorage.setItem(customerOrderTimerCacheKey, JSON.stringify(cache));
    } catch (error) {
        console.warn('Unable to persist customer order timer cache', error);
    }
}

function loadCustomerOrderTimerCache() {
    if (typeof window === 'undefined' || !window.localStorage) return;
    try {
        const raw = window.localStorage.getItem(customerOrderTimerCacheKey);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return;

        parsed.forEach((entry) => {
            if (!entry || !entry.orderNumber) return;
            const orderNumber = String(entry.orderNumber);
            // Only hydrate orders the customer is still actively tracking.
            if (!customerOrderNumbers.has(orderNumber)) return;
            customerOrderStatuses.set(orderNumber, {
                status: entry.status || 'pending',
                prepMinutes: entry.prepMinutes != null ? Number(entry.prepMinutes) : null,
                prepStartedAt: entry.prepStartedAt || null,
                orderType: entry.orderType || ''
            });
        });
    } catch (error) {
        console.warn('Unable to restore customer order timer cache', error);
    }
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

function showCustomerOrderCancelledNotice(orderNumber, status) {
    const label = status === 'refunded' ? 'refunded' : 'cancelled';
    const existing = document.getElementById('order-cancelled-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id = 'order-cancelled-toast';
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'polite');
    toast.style.position = 'fixed';
    toast.style.left = '50%';
    toast.style.top = '24px';
    toast.style.transform = 'translateX(-50%)';
    toast.style.width = 'min(92vw, 460px)';
    toast.style.padding = '14px 18px';
    toast.style.borderRadius = '14px';
    toast.style.background = 'rgba(127, 29, 29, 0.95)';
    toast.style.color = '#fff';
    toast.style.boxShadow = '0 14px 30px rgba(0, 0, 0, 0.3)';
    toast.style.zIndex = '9999';
    toast.style.fontWeight = '700';
    toast.style.fontSize = 'clamp(14px, 2.6vw, 17px)';
    toast.style.textAlign = 'center';
    toast.style.lineHeight = '1.4';
    toast.style.border = '1px solid rgba(255, 255, 255, 0.25)';
    toast.style.pointerEvents = 'auto';
    toast.textContent = `Your order #${orderNumber} was ${label} by the restaurant. You can place a new order anytime.`;

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Dismiss notification');
    closeBtn.textContent = '×';
    closeBtn.style.position = 'absolute';
    closeBtn.style.top = '6px';
    closeBtn.style.right = '10px';
    closeBtn.style.background = 'transparent';
    closeBtn.style.border = 'none';
    closeBtn.style.color = 'rgba(255, 255, 255, 0.8)';
    closeBtn.style.fontSize = '22px';
    closeBtn.style.lineHeight = '1';
    closeBtn.style.cursor = 'pointer';
    closeBtn.style.padding = '0';
    closeBtn.addEventListener('click', () => toast.remove());
    toast.appendChild(closeBtn);

    document.body.appendChild(toast);

    // Auto-dismiss after 8 seconds unless the customer closes it sooner.
    window.setTimeout(() => {
        const stillThere = document.getElementById('order-cancelled-toast');
        if (stillThere) stillThere.remove();
    }, 8000);
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

            if (status === 'completed' || status === 'expired' || status === 'cancelled' || status === 'refunded') {
                // Completed/expired/cancelled/refunded orders are no longer
                // "active": stop tracking them so the floating icon hides once
                // every tracked order is done, and never shows a cancelled
                // order as if it were still being prepared.
                const wasTracked = customerOrderStatuses.has(orderNumber);
                customerOrderStatuses.delete(orderNumber);
                customerOrderNumbers.delete(orderNumber);
                if (wasTracked && (status === 'cancelled' || status === 'refunded') && !seenCancelledOrders.has(orderNumber)) {
                    seenCancelledOrders.add(orderNumber);
                    showCustomerOrderCancelledNotice(orderNumber, status);
                }
            } else {
                // Keep the live status map for the floating status icon.
                customerOrderStatuses.set(orderNumber, {
                    status,
                    prepMinutes: order.prep_minutes != null ? Number(order.prep_minutes) : null,
                    // Prefer the timezone-aware ISO variant; fall back to the raw
                    // server string for older responses or cached payloads.
                    prepStartedAt: order.prep_started_at_iso || order.prep_started_at || null,
                    orderType: order.order_type || ''
                });
            }

            if (status === 'completed' && !seenCompletedOrders.has(orderNumber)) {
                seenCompletedOrders.add(orderNumber);
                showCustomerOrderCompletedPopup(orderNumber);
            }
        });

        saveCustomerOrderTracking();
        saveCustomerOrderTimerCache();
        renderOrderStatusFloat();
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

/* ---- Customer live order status floating icon ---- */
const orderStatusFloat = document.getElementById('orderStatusFloat');
const orderStatusFloatBtn = document.getElementById('orderStatusFloatBtn');
const orderStatusFloatIcon = document.getElementById('orderStatusFloatIcon');
const orderStatusPopover = document.getElementById('orderStatusPopover');
const orderStatusBody = document.getElementById('orderStatusBody');
const orderStatusCloseBtn = document.getElementById('orderStatusCloseBtn');
const orderStatusRingFill = document.querySelector('#orderStatusFloatBtn .order-status-ring-fill');
// Matches the CSS `stroke-dasharray: 154` so the ring is seamless at full progress.
const ORDER_STATUS_RING_CIRCUMFERENCE = 154;
const orderStatusChip = document.getElementById('orderStatusChip');
const orderStatusChipLabel = document.getElementById('orderStatusChipLabel');
const orderStatusChipBarFill = document.getElementById('orderStatusChipBarFill');

function getActiveTrackedOrder() {
    const entries = [...customerOrderStatuses.entries()];
    if (!entries.length) return null;

    // Prefer an accepted/preparing order so the countdown is always visible;
    // fall back to an order still waiting for acceptance. Completed and expired
    // orders are pruned from the map when polled, so only active states remain.
    const preparing = entries.find(([, state]) => state.prepStartedAt != null && state.status !== 'completed' && state.status !== 'expired');
    const waiting = entries.find(([, state]) => state.status !== 'completed' && state.status !== 'expired' && !state.prepStartedAt);
    return preparing || waiting || null;
}

function getOrderStatusFloatIconName(orderType) {
    if (isSakayKoOrderType(orderType)) return 'fa-motorcycle';
    if (String(orderType || '').toLowerCase().includes('take out')) return 'fa-bag-shopping';
    return 'fa-utensils';
}

/**
 * Formats a remaining-seconds countdown as an exact MM:SS clock.
 * Minutes are not capped, so long estimates render like "120:00".
 */
function formatCountdownClock(totalSeconds) {
    const secs = Math.max(0, Math.floor(totalSeconds));
    const mins = Math.floor(secs / 60);
    const rem = secs % 60;
    return `${String(mins).padStart(2, '0')}:${String(rem).padStart(2, '0')}`;
}

/**
 * Parses a prep-timer timestamp for the countdown engine. The Laravel backend
 * is configured with timezone UTC, so naive "YYYY-MM-DD HH:MM:SS" strings are
 * interpreted as UTC. Timezone-aware ISO strings (the preferred format) are
 * parsed by Date directly. Returns null when the value is unusable.
 */
function parsePrepTimestampToMs(value) {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'number') return value;

    const raw = String(value).trim();
    if (!raw) return null;

    const matched = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
    if (matched) {
        // No timezone marker -> the server wrote this in UTC.
        return Date.UTC(
            Number(matched[1]),
            Number(matched[2]) - 1,
            Number(matched[3]),
            Number(matched[4] || 0),
            Number(matched[5] || 0),
            Number(matched[6] || 0)
        );
    }

    const parsed = new Date(raw);
    if (!Number.isNaN(parsed.getTime())) return parsed.getTime();

    return null;
}

/**
 * Shared countdown engine used by both the customer icon/popover and the
 * staff pending-order cards. Computes the live MM:SS clock and the remaining
 * fraction (1 = full time left, 0 = time is up) from the server's prep start
 * timestamp + duration. The countdown only begins once staff accept the
 * order (prep_started_at is set server-side on "Prepare").
 */
function getPreparationCountdownDetails(prepStartedAt, prepMinutes) {
    const minutes = Math.max(0, Number(prepMinutes) || 0);
    const startedMs = parsePrepTimestampToMs(prepStartedAt);
    if (!minutes || startedMs === null) {
        return { clock: '', progress: 1 };
    }
    const durationMs = minutes * 60 * 1000;
    const endMs = startedMs + durationMs;
    const remainingMs = endMs - Date.now();

    if (remainingMs <= 0) {
        return { clock: '00:00', progress: 0 };
    }

    const remainingSecs = Math.max(0, Math.ceil(remainingMs / 1000));
    return {
        clock: formatCountdownClock(remainingSecs),
        progress: Math.min(1, Math.max(0, remainingMs / durationMs))
    };
}

/**
 * Depletes the circular progress ring around the floating icon. progress=1
 * shows a full ring; progress=0 leaves the ring empty (time is up).
 */
function updateOrderStatusRing(progress) {
    if (!orderStatusRingFill) return;
    const clamped = Math.min(1, Math.max(0, Number(progress) || 0));
    orderStatusRingFill.style.strokeDashoffset = String(ORDER_STATUS_RING_CIRCUMFERENCE * (1 - clamped));
}

/**
 * Renders the live countdown chip attached to the floating icon. While the
 * order is still waiting for acceptance the chip shows an "Awaiting
 * acceptance" state; once staff start preparation it shows the exact MM:SS
 * remaining on top of a depleting loading bar (1s ticker keeps it live).
 */
function updateOrderStatusChip(isPreparing, prepStartedAt, prepMinutes) {
    if (!orderStatusChip) return;

    orderStatusChip.hidden = false;
    orderStatusChip.setAttribute('aria-hidden', 'false');
    orderStatusChip.classList.toggle('is-preparing', isPreparing);
    orderStatusChip.classList.toggle('is-waiting', !isPreparing);

    if (!isPreparing) {
        if (orderStatusChipLabel) orderStatusChipLabel.textContent = 'Awaiting acceptance';
        if (orderStatusChipBarFill) {
            orderStatusChipBarFill.style.width = '0%';
            orderStatusChipBarFill.classList.remove('is-done');
        }
        orderStatusChip.classList.remove('is-done');
        return;
    }

    const details = getPreparationCountdownDetails(prepStartedAt, prepMinutes);
    const isDone = details.progress <= 0;
    orderStatusChip.classList.toggle('is-done', isDone);

    if (orderStatusChipLabel) {
        orderStatusChipLabel.textContent = isDone ? 'Almost ready!' : details.clock;
    }
    if (orderStatusChipBarFill) {
        orderStatusChipBarFill.style.width = `${Math.round(details.progress * 100)}%`;
        orderStatusChipBarFill.classList.toggle('is-done', isDone);
    }
}

function renderOrderStatusFloat() {
    if (!orderStatusFloat) return;

    const active = getActiveTrackedOrder();
    if (!active) {
        orderStatusFloat.hidden = true;
        orderStatusFloat.setAttribute('aria-hidden', 'true');
        if (orderStatusChip) orderStatusChip.hidden = true;
        return;
    }

    const [orderNumber, state] = active;
    const isPreparing = state.prepStartedAt != null && state.status !== 'completed';
    orderStatusFloat.hidden = false;
    orderStatusFloat.setAttribute('aria-hidden', 'false');

    if (orderStatusFloatIcon) {
        orderStatusFloatIcon.className = `fa-solid ${getOrderStatusFloatIconName(state.orderType)}`;
    }

    if (orderStatusFloatBtn) {
        orderStatusFloatBtn.classList.toggle('is-preparing', isPreparing);
        orderStatusFloatBtn.classList.toggle('is-waiting', !isPreparing);
        orderStatusFloatBtn.classList.toggle('has-countdown', isPreparing);
    }

    // Deplete the ring as preparation time runs out (1s ticker keeps it live).
    updateOrderStatusRing(isPreparing
        ? getPreparationCountdownDetails(state.prepStartedAt, state.prepMinutes).progress
        : 1);

    // Live MM:SS chip with the integrated depleting loading bar.
    updateOrderStatusChip(isPreparing, state.prepStartedAt, state.prepMinutes);

    if (!orderStatusBody || !orderStatusFloatOpen) return;

    const countdownDetails = isPreparing
        ? getPreparationCountdownDetails(state.prepStartedAt, state.prepMinutes)
        : null;
    const statusLabel = isPreparing ? 'Preparing your order' : 'Waiting for acceptance';
    const statusClass = isPreparing ? 'is-preparing' : 'is-waiting';
    const countdownLabel = countdownDetails && countdownDetails.clock
        ? (countdownDetails.progress > 0 ? `${countdownDetails.clock} remaining` : 'Almost ready!')
        : '';
    const countdownHtml = countdownLabel
        ? `<div class="order-status-countdown"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i> <strong>${escapeHtml(countdownLabel)}</strong></div>`
        : '';
    orderStatusBody.innerHTML = `
        <div class="order-status-line ${statusClass}">
            <i class="fa-solid ${getOrderStatusFloatIconName(state.orderType)}" aria-hidden="true"></i>
            <div>
                <strong>Order #${escapeHtml(String(orderNumber))}</strong>
                <span>${statusLabel}</span>
            </div>
        </div>
        ${countdownHtml}
        <p class="order-status-note">${isPreparing ? 'The kitchen is working on your order.' : 'The kitchen will accept your order shortly.'}</p>
    `;
}

function startOrderStatusFloatTicker() {
    if (orderStatusFloatTicker || !orderStatusFloat) return;
    orderStatusFloatTicker = window.setInterval(() => {
        if (orderStatusFloat.hidden) return;
        renderOrderStatusFloat();
    }, 1000);
}

function toggleOrderStatusPopover(forceOpen) {
    if (!orderStatusPopover) return;
    orderStatusFloatOpen = typeof forceOpen === 'boolean' ? forceOpen : !orderStatusFloatOpen;
    orderStatusPopover.hidden = !orderStatusFloatOpen;
    orderStatusPopover.setAttribute('aria-hidden', String(!orderStatusFloatOpen));
    if (orderStatusFloatBtn) {
        orderStatusFloatBtn.setAttribute('aria-expanded', String(orderStatusFloatOpen));
    }
    if (orderStatusFloatOpen) {
        renderOrderStatusFloat();
    }
}

if (orderStatusFloatBtn) {
    orderStatusFloatBtn.addEventListener('click', () => toggleOrderStatusPopover());
}

if (orderStatusChip) {
    orderStatusChip.addEventListener('click', () => toggleOrderStatusPopover());
}

if (orderStatusCloseBtn) {
    orderStatusCloseBtn.addEventListener('click', () => toggleOrderStatusPopover(false));
}

if (orderStatusPopover) {
    orderStatusPopover.addEventListener('click', (event) => {
        event.stopPropagation();
    });
}

document.addEventListener('click', (event) => {
    if (!orderStatusFloatOpen || !orderStatusFloat) return;
    if (orderStatusFloat.contains(event.target)) return;
    toggleOrderStatusPopover(false);
});

async function loadPendingOrdersFromServer() {
    try {
        // Gated by the server session; wait for the page-load renewal first.
        if (isStaffPage) {
            await ensureStaffServerSession();
        }
        const response = await fetch(getApiUrl('api/get_pending_orders.php'), { cache: 'no-store', credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        const serverOrders = Array.isArray(payload.orders) ? payload.orders : [];

        pendingOrders = serverOrders.map((order) => {
            const items = Array.isArray(order.items) ? order.items : [];
            const orderId = Number(order.id);
            const mapped = {
                id: orderId,
                orderNumber: order.order_number || order.orderNumber || String(order.id),
                timestamp: parseServerDateToMs(order.order_date_iso || order.order_date || Date.now()),
                total: Number(order.total_amount ?? order.total ?? 0),
                paymentMethod: order.payment_method || order.paymentMethod || 'Cash',
                orderType: order.order_type || order.orderType || 'Dine In',
                customerName: order.customer_name || order.customerName || '',
                deliveryAddress: order.delivery_address || order.deliveryAddress || '',
                prepMinutes: order.prep_minutes != null ? Number(order.prep_minutes) : null,
                prepStartedAt: order.prep_started_at_iso || order.prep_started_at || null,
                items: items.map((item) => ({
                    id: Number(item.id ?? 0),
                    name: item.notes || item.name || 'Menu item',
                    notes: item.notes || item.name || 'Menu item',
                    price: Number(item.unit_price ?? item.price ?? 0),
                    quantity: Number(item.quantity ?? 0),
                    components: Array.isArray(item.components) ? item.components : []
                }))
            };

            // Fallback to the locally cached timer so the countdown survives a
            // refresh even if the server payload omits the prep fields.
            if (mapped.prepStartedAt == null && staffOrderTimerCache.has(String(orderId))) {
                const cached = staffOrderTimerCache.get(String(orderId));
                mapped.prepStartedAt = cached.prepStartedAt;
                mapped.prepMinutes = cached.prepMinutes;
            }
            return mapped;
        });
        pendingOrders.sort((a, b) => b.timestamp - a.timestamp);
        pruneOverdueStateToCurrentOrders();
    saveStaffOrderTimerCache();
    savePendingOrders();
    renderPendingOrders();
    renderWalkInOrderBuilder();
    renderOrderNotifications();
    if (!pendingOrders.length) {
        unseenPendingCount = 0;
        updateOverviewBadge();
    }
    } catch (error) {
        console.error('Unable to load pending orders from the server', error);
    }
}

function startPendingOrdersRefresh() {
    if (!isStaffPage || pendingOrdersRefreshTimer) return;

    pendingOrdersRefreshTimer = window.setInterval(() => {
        void loadPendingOrdersFromServer();
    }, 10000);
}

/**
 * Live per-second countdown for preparing pending orders. Re-reads the DOM
 * each tick so it survives list re-renders from the 30s server refresh.
 */
function updatePendingOrdersCountdowns() {
    if (!pendingOrdersList) return;

    const countdownEls = pendingOrdersList.querySelectorAll('.pending-order-countdown');
    countdownEls.forEach((countdownEl) => {
        const startedAt = countdownEl.dataset.prepStartedAt || '';
        const minutes = Number(countdownEl.dataset.prepMinutes || 0);
        const details = getPreparationCountdownDetails(startedAt, minutes);

        const textEl = countdownEl.querySelector('.pending-order-countdown-text');
        if (textEl) {
            textEl.textContent = details.progress > 0 ? details.clock : 'Almost ready!';
        }

        const barFill = countdownEl.querySelector('.pending-order-countdown-bar-fill');
        if (barFill) {
            barFill.style.width = `${Math.round(details.progress * 100)}%`;
            barFill.classList.toggle('is-done', details.progress <= 0);
        }

        countdownEl.classList.toggle('is-done', details.progress <= 0);

        // Overdue detection: when the prep timer reaches 00:00, highlight the
        // order card and raise the visual alert exactly once per prep session
        // (a fresh expiry after staff adds minutes re-triggers it).
        const card = countdownEl.closest('.pending-order-card');
        if (!card) return;
        const orderId = Number(card.dataset.orderId || 0);
        if (!orderId) return;

        if (details.progress <= 0) {
            handleOrderPreparationExpired(orderId);
        } else {
            clearOrderOverdueState(orderId);
        }
    });
}

/* ---- Preparation timer overdue alert ---- */
const overdueOrderIds = new Set();       // order ids rendered with the is-overdue style
const overdueNotifiedOrderIds = new Set(); // prep sessions that already fired the alert
let overdueAlertQueue = [];              // orders waiting to be shown in the alert modal
let overdueAlertActive = false;
let overdueAlertOrderId = null;
let overdueActionInFlight = false;       // guards the modal buttons against double-firing
const overdueAlertModal = document.getElementById('overdueAlertModal');
const overdueAlertCloseBtn = document.getElementById('overdueAlertCloseBtn');
const overdueAlertText = document.getElementById('overdueAlertText');
const overdueMinutesInput = document.getElementById('overdueMinutesInput');
const overdueAddMinutesBtn = document.getElementById('overdueAddMinutesBtn');
const overdueCompleteBtn = document.getElementById('overdueCompleteBtn');
const overdueAlertMessage = document.getElementById('overdueAlertMessage');

function findPendingOrderIndexById(orderId) {
    return pendingOrders.findIndex((order) => Number(order.id) === Number(orderId));
}

/**
 * Resets the overdue flags for an order that is no longer expired (e.g. staff
 * added minutes), so the card un-highlights and a future expiry can alert again.
 */
function clearOrderOverdueState(orderId) {
    const key = String(orderId);
    let changed = false;
    if (overdueOrderIds.delete(key)) changed = true;
    if (overdueNotifiedOrderIds.delete(key)) changed = true;
    if (changed) renderPendingOrders();
}

function pruneOverdueStateToCurrentOrders() {
    if (!pendingOrders.length) {
        overdueOrderIds.clear();
        overdueNotifiedOrderIds.clear();
        overdueAlertQueue = [];
        if (overdueAlertActive) dismissOverdueAlert();
        return;
    }
    const currentIds = new Set(pendingOrders.map((order) => String(order.id)));
    [...overdueOrderIds].forEach((key) => {
        if (!currentIds.has(key)) overdueOrderIds.delete(key);
    });
    [...overdueNotifiedOrderIds].forEach((key) => {
        if (!currentIds.has(key)) overdueNotifiedOrderIds.delete(key);
    });
    overdueAlertQueue = overdueAlertQueue.filter((key) => currentIds.has(key));

    // If the order currently shown in the alert modal left the pending list
    // (e.g. completed by another device), dismiss it and show the next one.
    if (overdueAlertActive && overdueAlertOrderId !== null && !currentIds.has(String(overdueAlertOrderId))) {
        dismissOverdueAlert();
    }
}

function handleOrderPreparationExpired(orderId) {
    const key = String(orderId);
    overdueOrderIds.add(key);

    // Highlight the visible card immediately (survives re-renders via the Set).
    const card = pendingOrdersList.querySelector(`.pending-order-card[data-order-id="${key}"]`);
    if (card) card.classList.add('is-overdue');

    if (overdueNotifiedOrderIds.has(key)) return;

    overdueNotifiedOrderIds.add(key);
    overdueAlertQueue.push(key);

    if (!overdueAlertActive) {
        showNextOverdueAlert();
    }
}

function showNextOverdueAlert() {
    if (!overdueAlertModal) {
        overdueAlertActive = false;
        return;
    }

    while (overdueAlertQueue.length) {
        const orderId = Number(overdueAlertQueue.shift());
        const orderIndex = findPendingOrderIndexById(orderId);
        const order = orderIndex >= 0 ? pendingOrders[orderIndex] : null;
        if (!order) continue; // order was already completed/removed

        overdueAlertActive = true;
        overdueAlertOrderId = orderId;
        if (overdueAlertText) {
            const displayNumber = String(order.orderNumber || order.order_number || order.id || '');
            overdueAlertText.textContent = `Order #${displayNumber}'s preparation timer has expired. Extend the time or mark it complete to keep things moving.`;
        }
        if (overdueMinutesInput) {
            const currentMinutes = Number(order.prepMinutes) || 15;
            overdueMinutesInput.value = String(Math.min(180, Math.max(1, currentMinutes)));
        }
        if (overdueAlertMessage) overdueAlertMessage.textContent = '';
        overdueAlertModal.hidden = false;
        overdueAlertModal.classList.add('active');
        overdueAlertModal.setAttribute('aria-hidden', 'false');
        return;
    }

    overdueAlertActive = false;
    overdueAlertOrderId = null;
    overdueAlertModal.hidden = true;
    overdueAlertModal.classList.remove('active');
    overdueAlertModal.setAttribute('aria-hidden', 'true');
}

function dismissOverdueAlert() {
    overdueAlertActive = false;
    overdueAlertOrderId = null;
    if (overdueAlertModal) {
        overdueAlertModal.hidden = true;
        overdueAlertModal.classList.remove('active');
        overdueAlertModal.setAttribute('aria-hidden', 'true');
    }
    showNextOverdueAlert();
}

async function handleOverdueAddMinutes() {
    if (overdueAlertOrderId === null || overdueActionInFlight) return;
    const orderIndex = findPendingOrderIndexById(overdueAlertOrderId);
    if (orderIndex < 0) {
        dismissOverdueAlert();
        return;
    }

    const minutes = Math.min(180, Math.max(1, Math.round(Number(overdueMinutesInput ? overdueMinutesInput.value : 0) || 15)));
    if (overdueAlertMessage) overdueAlertMessage.textContent = 'Updating preparation time...';

    overdueActionInFlight = true;
    const extended = await startOrderPreparation(orderIndex, minutes);
    overdueActionInFlight = false;
    if (extended) {
        // Deliberately do NOT clear the overdue flags here: the client countdown
        // still shows 00:00 until the server reload lands, and clearing the
        // notified set now would re-trigger the alert on the very next tick.
        // The countdown ticker clears both flags itself once the reloaded timer
        // reports progress > 0 (see updatePendingOrdersCountdowns).
        dismissOverdueAlert();
    } else {
        if (overdueAlertMessage) {
            overdueAlertMessage.textContent = 'Unable to extend the preparation time. Check your connection and try again.';
        }
        void loadPendingOrdersFromServer();
    }
}

async function handleOverdueComplete() {
    if (overdueAlertOrderId === null || overdueActionInFlight) return;
    const orderIndex = findPendingOrderIndexById(overdueAlertOrderId);
    if (orderIndex < 0) {
        dismissOverdueAlert();
        return;
    }

    if (overdueAlertMessage) overdueAlertMessage.textContent = 'Completing order...';
    overdueActionInFlight = true;
    const completed = await markPendingOrderAsComplete(orderIndex, true);
    overdueActionInFlight = false;
    if (completed) {
        // markPendingOrderAsComplete already removed this order from the overdue
        // bookkeeping; only dismiss so the next queued alert (if any) shows.
        dismissOverdueAlert();
    } else {
        if (overdueAlertMessage) {
            overdueAlertMessage.textContent = 'Unable to complete the order. It may already be finished — check the order list.';
        }
        void loadPendingOrdersFromServer();
    }
}

if (overdueAlertCloseBtn) {
    overdueAlertCloseBtn.addEventListener('click', dismissOverdueAlert);
}
if (overdueAddMinutesBtn) {
    overdueAddMinutesBtn.addEventListener('click', () => void handleOverdueAddMinutes());
}
if (overdueCompleteBtn) {
    overdueCompleteBtn.addEventListener('click', () => void handleOverdueComplete());
}
if (overdueAlertModal) {
    overdueAlertModal.addEventListener('click', (event) => {
        if (event.target === overdueAlertModal) dismissOverdueAlert();
    });
}
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (overdueAlertModal && !overdueAlertModal.hidden) {
        dismissOverdueAlert();
    }
});

function startPendingOrdersCountdownTicker() {
    if (!isStaffPage || pendingOrdersCountdownTicker) return;
    pendingOrdersCountdownTicker = window.setInterval(() => {
        updatePendingOrdersCountdowns();
    }, 1000);
}

/**
 * Persist each preparing order's timer (prep start + duration) so the MM:SS
 * countdown is accurate across page refreshes. The backend is the source of
 * truth; this local cache is a fallback while the server response is pending
 * or if the payload ever omits the prep fields.
 */
function saveStaffOrderTimerCache() {
    if (!isStaffPage || typeof window === 'undefined' || !window.localStorage) return;
    try {
        const cache = {};
        pendingOrders.forEach((order) => {
            if (order.prepStartedAt && order.prepMinutes != null) {
                cache[String(order.id)] = {
                    prepMinutes: Number(order.prepMinutes),
                    prepStartedAt: order.prepStartedAt
                };
            }
        });
        window.localStorage.setItem(staffOrderTimerCacheKey, JSON.stringify(cache));
    } catch (error) {
        console.warn('Unable to persist staff order timer cache', error);
    }
}

function loadStaffOrderTimerCache() {
    staffOrderTimerCache = new Map();
    if (!isStaffPage || typeof window === 'undefined' || !window.localStorage) return;
    try {
        const raw = window.localStorage.getItem(staffOrderTimerCacheKey);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return;
        Object.entries(parsed).forEach(([orderId, entry]) => {
            if (entry && entry.prepStartedAt && entry.prepMinutes != null) {
                staffOrderTimerCache.set(String(orderId), {
                    prepMinutes: Number(entry.prepMinutes),
                    prepStartedAt: entry.prepStartedAt
                });
            }
        });
    } catch (error) {
        console.warn('Unable to restore staff order timer cache', error);
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
                orderType: order.orderType,
                customerName: order.customerName || '',
                customerPhone: order.customerPhone || '',
                customerEmail: order.customerEmail || '',
                deliveryAddress: order.deliveryAddress || ''
            })
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            // Surface server messages (e.g. the per-IP order rate limit) to the
            // customer instead of a generic failure.
            throw new Error(payload.error || `Unable to save order (HTTP ${response.status})`);
        }

        return {
            ...order,
            id: Number(payload.orderId || Date.now()),
            orderNumber: order.orderNumber || String(payload.orderId || Date.now())
        };
    } catch (error) {
        console.error('Unable to save order to the server', error);
        return { error: error instanceof Error ? error.message : 'Unable to submit order' };
    }
}

async function startOrderPreparationOnServer(orderId, minutes) {
    const actor = getCurrentStaffActor();
    const headers = await withCsrfHeaders({
        'Content-Type': 'application/json'
    });

    const response = await fetch(getApiUrl('api/start_order_preparation.php'), {
        method: 'POST',
        headers,
        body: JSON.stringify({
            orderId,
            minutes,
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

async function markOrderCompleteOnServer(orderId) {
    const actor = getCurrentStaffActor();
    const headers = await withCsrfHeaders({
        'Content-Type': 'application/json'
    });

    const response = await fetch(getApiUrl('api/mark_order_complete.php'), {
        method: 'POST',
        headers,
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

function getReservedPendingQuantityForComponent(componentName, excludingOrderId = null, excludingItemId = null) {
    const targetName = normalizeInventoryName(componentName);
    if (!targetName) return 0;

    return pendingOrders.reduce((sum, order) => {
        if (excludingOrderId !== null && Number(order.id) === Number(excludingOrderId)) {
            const orderItems = Array.isArray(order.items) ? order.items : [];
            const partial = orderItems.reduce((sub, item) => {
                if (excludingItemId !== null && Number(item.id) === Number(excludingItemId)) {
                    return sub;
                }
                if (!Array.isArray(item.components)) return sub;
                const component = item.components.find((entry) => normalizeInventoryName(entry.name) === targetName);
                return sub + Math.max(0, Number(component ? component.quantity : 0));
            }, 0);
            return sum + partial;
        }

        const orderItems = Array.isArray(order.items) ? order.items : [];
        return sum + orderItems.reduce((sub, item) => {
            if (!Array.isArray(item.components)) return sub;
            const component = item.components.find((entry) => normalizeInventoryName(entry.name) === targetName);
            return sub + Math.max(0, Number(component ? component.quantity : 0));
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

function getMaxEditablePendingComponentQuantity(orderId, item, componentName) {
    const inventoryItem = getInventoryItem(componentName);
    if (!inventoryItem) return Number.MAX_SAFE_INTEGER;

    const stock = Math.max(0, Number(inventoryItem.stock) || 0);
    const reservedByOthers = getReservedPendingQuantityForComponent(componentName, orderId, item.id);
    return Math.max(0, stock - reservedByOthers);
}

async function updatePendingOrderItemQuantity(orderId, itemId, quantity, components = null) {
    const actor = getCurrentStaffActor();

    const payload = {
        orderId,
        itemId,
        quantity,
        actorRole: actor.role,
        actorEmail: actor.email
    };

    if (Array.isArray(components)) {
        payload.components = components.map((component) => ({
            name: String(component.name || '').trim(),
            quantity: Math.max(0, Number(component.quantity) || 0)
        }));
    }

    const headers = await withCsrfHeaders({
        'Content-Type': 'application/json'
    });
    const response = await fetch(getApiUrl('api/update_pending_order_item.php'), {
        method: 'POST',
        headers,
        body: JSON.stringify(payload),
        cache: 'no-store'
    });

    const result = await response.json();
    if (!response.ok || !result.success) {
        const maxAllowed = payload && Number.isFinite(Number(payload.maxAllowed)) ? Number(payload.maxAllowed) : null;
        const message = maxAllowed !== null
            ? `${payload.error || 'Unable to update order item'} (Max allowed: ${maxAllowed})`
            : (payload.error || `HTTP ${response.status}`);
        throw new Error(message);
    }

    return payload;
}

async function updatePendingOrderItemComponentQuantity(orderId, itemId, componentName, componentQuantity) {
    const actor = getCurrentStaffActor();

    const headers = await withCsrfHeaders({
        'Content-Type': 'application/json'
    });
    const response = await fetch(getApiUrl('api/update_pending_order_item.php'), {
        method: 'POST',
        headers,
        body: JSON.stringify({
            orderId,
            itemId,
            componentName,
            componentQuantity,
            actorRole: actor.role,
            actorEmail: actor.email
        }),
        cache: 'no-store'
    });

    const payload = await response.json();
    if (!response.ok || !payload.success) {
        const maxAllowed = payload && Number.isFinite(Number(payload.maxAllowed)) ? Number(payload.maxAllowed) : null;
        const message = maxAllowed !== null
            ? `${payload.error || 'Unable to update component quantity'} (Max allowed: ${maxAllowed})`
            : (payload.error || `HTTP ${response.status}`);
        throw new Error(message);
    }

    return payload;
}

async function changePendingOrderItemComponentQuantity(orderIndex, itemId, componentName, direction) {
    if (!canManageOrders()) return;
    if (orderIndex < 0 || orderIndex >= pendingOrders.length) return;
    const order = pendingOrders[orderIndex];
    const items = Array.isArray(order.items) ? order.items : [];
    const item = items.find((entry) => Number(entry.id) === Number(itemId));
    if (!item) return;

    const currentQuantity = getCartItemComponentQuantity(item, componentName);
    const delta = direction === 'increase' ? 1 : -1;
    const nextQuantity = currentQuantity + delta;
    if (nextQuantity < 0) return;

    const maxAllowed = getMaxEditablePendingComponentQuantity(order.id, item, componentName);
    if (direction === 'increase' && nextQuantity > maxAllowed) {
        return;
    }

    const previousComponents = Array.isArray(item.components) ? JSON.parse(JSON.stringify(item.components)) : [];

    setCartItemComponentQuantity(item, componentName, nextQuantity);
    renderPendingOrders();

    try {
        await updatePendingOrderItemComponentQuantity(order.id, item.id, componentName, nextQuantity);
    } catch (error) {
        console.error('Unable to edit pending order component quantity', error);
        item.components = previousComponents;
        renderPendingOrders();
        await showStaffNotice(error.message || 'Unable to edit component quantity', true);
        return;
    }

    void loadPendingOrdersFromServer();
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

    // Optimistic UI update for faster response
    const previousTotal = Number(order.total) || 0;
    const previousQuantity = currentQuantity;
    const specialRecipe = getSpecialFoodComponentsByName(item.name);
    if (specialRecipe.length) {
        updateCartItemComponentsByRecipe(item, specialRecipe, delta);
    } else {
        applyBaseComponentsDeltaToCartItem(item, delta);
    }

    item.quantity = nextQuantity;
    order.total = previousTotal + (direction === 'increase' ? Number(item.price) || 0 : -(Number(item.price) || 0));
    renderPendingOrders();

    try {
        await updatePendingOrderItemQuantity(order.id, item.id, nextQuantity, item.components);
    } catch (error) {
        console.error('Unable to edit pending order quantity', error);
        item.quantity = previousQuantity;
        order.total = previousTotal;
        renderPendingOrders();
        await showStaffNotice(error.message || 'Unable to edit order quantity', true);
        return;
    }

    void loadPendingOrdersFromServer();
    void initializeInventoryData(true);
    void loadOrderLogsFromServer(true);
}

async function startOrderPreparation(orderIndex, minutes) {
    if (!canManageOrders()) return false;
    if (orderIndex < 0 || orderIndex >= pendingOrders.length) return false;

    const safeMinutes = Math.max(1, Math.min(180, Math.round(Number(minutes) || 0)));
    const targetOrder = pendingOrders[orderIndex];

    try {
        await startOrderPreparationOnServer(targetOrder.id, safeMinutes);
    } catch (error) {
        console.error('Unable to start order preparation', error);
        await showStaffNotice(error.message || 'Unable to start order preparation', true);
        return false;
    }

    // The server preserves the originally chosen prep start time so the
    // customer's countdown is never reset when staff adjust the estimate.
    void loadPendingOrdersFromServer();
    void loadOrderLogsFromServer(true);
    return true;
}

async function markPendingOrderAsComplete(orderIndex, shouldIgnore = false) {
    if (!canManageOrders()) return false;
    if (orderIndex < 0 || orderIndex >= pendingOrders.length) return false;

    const targetOrder = pendingOrders[orderIndex];
    try {
        await markOrderCompleteOnServer(targetOrder.id);
    } catch (error) {
        console.error('Unable to mark order complete on server', error);
        return false;
    }

    const completedOrder = pendingOrders.splice(orderIndex, 1)[0];
    if (shouldIgnore) {
        ignorePendingOrder(completedOrder.orderNumber || completedOrder.id);
    }
    // The completed order is no longer pending — drop any overdue bookkeeping
    // so a stale id can never re-mark a future order as overdue.
    overdueOrderIds.delete(String(completedOrder.id));
    overdueNotifiedOrderIds.delete(String(completedOrder.id));
    overdueAlertQueue = overdueAlertQueue.filter((key) => key !== String(completedOrder.id));
    completedOrders.unshift(completedOrder);
    recalculateSalesAnalytics();
    recalculateProfitAnalytics();
    savePendingOrders();
    renderPendingOrders();
    renderWalkInOrderBuilder();
    renderOrderNotifications();
    updateAnalyticsView();
    updateProfitView(false);
    renderOverviewAnalytics();
    renderInsights();
    void initializeInventoryData(true);
    void loadOrderLogsFromServer(true);
    void loadCompletedOrdersFromServer(true);
    return true;
}

function printOrderReceipt(orderIndex) {
    if (orderIndex < 0 || orderIndex >= pendingOrders.length) return;
    const order = pendingOrders[orderIndex];
    if (!order) return;

    const items = (Array.isArray(order.items) ? order.items : []).map((item) => {
        const components = (Array.isArray(item.components) ? item.components : [])
            .map((component) => `  ↳ ${escapeHtml(component.name)} × ${Number(component.quantity) || 0}`)
            .join('\n');
        return `  ${item.name} × ${item.quantity} — ${formatCurrency(Number(item.price) * Number(item.quantity))}${components ? `\n${components}` : ''}`;
    }).join('\n');

    const customerName = String(order.customerName || order.customer_name || '').trim();
    const address = String(order.deliveryAddress || order.delivery_address || '').trim();
    const printWindow = window.open('', '_blank', 'width=400,height=600');
    if (!printWindow) {
        showStaffNotice('Please allow pop-ups to print the receipt.', true);
        return;
    }

    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Receipt #${escapeHtml(String(order.orderNumber || order.order_number || order.id || ''))}</title>
            <style>
                body { font-family: 'Courier New', monospace; font-size: 12px; margin: 0; padding: 24px; color: #111; }
                h1 { font-size: 16px; text-align: center; margin: 0 0 4px; }
                .sub { text-align: center; color: #555; margin-bottom: 12px; }
                hr { border: 0; border-top: 1px dashed #999; margin: 8px 0; }
                .row { display: flex; justify-content: space-between; }
                .meta { margin: 4px 0; }
                pre { white-space: pre-wrap; font-family: inherit; margin: 0; }
                .total { font-weight: 700; font-size: 14px; }
                .footer { text-align: center; color: #555; margin-top: 12px; }
            </style>
        </head>
        <body>
            <h1>MOTASTE</h1>
            <p class="sub">Crafted Silog • Restaurant</p>
            <hr>
            <p class="meta"><strong>Order #:</strong> ${escapeHtml(String(order.orderNumber || order.order_number || order.id || ''))}</p>
            <p class="meta"><strong>Date:</strong> ${new Date(order.timestamp || Date.now()).toLocaleString()}</p>
            <p class="meta"><strong>Type:</strong> ${escapeHtml(order.orderType || 'Dine In')}</p>
            <p class="meta"><strong>Payment:</strong> ${escapeHtml(order.paymentMethod || '—')}</p>
            ${customerName ? `<p class="meta"><strong>Customer:</strong> ${escapeHtml(customerName)}</p>` : ''}
            ${address ? `<p class="meta"><strong>Address:</strong> ${escapeHtml(address)}</p>` : ''}
            <hr>
            <pre>${items || '  (no items)'}</pre>
            <hr>
            <p class="row total"><span>Total</span><span>${formatCurrency(order.total)}</span></p>
            <p class="footer">Thank you for dining with us! 🍽</p>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    window.setTimeout(() => {
        printWindow.print();
    }, 300);
}

async function cancelPendingOrder(orderIndex) {
    if (!canManageOrders()) return false;
    if (orderIndex < 0 || orderIndex >= pendingOrders.length) return false;

    const targetOrder = pendingOrders[orderIndex];
    const cancelConfirmed = await showStaffConfirm(
        `Cancel order #${targetOrder.orderNumber || targetOrder.id}? Stock will be restored and the order removed from the pending queue.`,
        { title: 'Cancel order?', confirmLabel: 'Cancel Order' }
    );
    if (!cancelConfirmed) return false;

    try {
        const actor = getCurrentStaffActor();
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });
        const response = await fetch(getApiUrl('api/cancel_order.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify({
                orderId: targetOrder.id,
                actorRole: actor.role,
                actorEmail: actor.email
            })
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || `HTTP ${response.status}`);
        }
    } catch (error) {
        console.error('Unable to cancel order on server', error);
        await showStaffNotice(`Order cancel failed. ${error.message || 'Unexpected error'}`, true);
        return false;
    }

    const cancelledOrder = pendingOrders.splice(orderIndex, 1)[0];
    overdueOrderIds.delete(String(cancelledOrder.id));
    overdueNotifiedOrderIds.delete(String(cancelledOrder.id));
    overdueAlertQueue = overdueAlertQueue.filter((key) => key !== String(cancelledOrder.id));
    savePendingOrders();
    renderPendingOrders();
    renderWalkInOrderBuilder();
    renderOrderNotifications();
    void initializeInventoryData(true);
    void loadOrderLogsFromServer(true);
    setOrdersTab('pending');
    await showStaffNotice(`Order #${cancelledOrder.orderNumber || cancelledOrder.id} was cancelled and stock restored.`);
    return true;
}

async function refundCompletedOrder(orderId) {
    if (!canManageOrders()) return false;
    if (!orderId) return false;

    const refundConfirmed = await showStaffConfirm(
        'Refund this completed order? Stock will be restored and the order marked as refunded. This cannot be undone.',
        { title: 'Refund order?', confirmLabel: 'Refund Order' }
    );
    if (!refundConfirmed) return false;

    try {
        const actor = getCurrentStaffActor();
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });
        const response = await fetch(getApiUrl('api/refund_order.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify({
                orderId,
                actorRole: actor.role,
                actorEmail: actor.email
            })
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || `HTTP ${response.status}`);
        }
    } catch (error) {
        console.error('Unable to refund order on server', error);
        await showStaffNotice(`Refund failed. ${error.message || 'Unexpected error'}`, true);
        return false;
    }

    const completedIndex = completedOrders.findIndex((order) => Number(order.id) === Number(orderId));
    if (completedIndex >= 0) {
        completedOrders.splice(completedIndex, 1)[0];
    }
    recalculateSalesAnalytics();
    recalculateProfitAnalytics();
    saveCompletedOrders();
    renderOrderNotifications();
    updateAnalyticsView();
    updateProfitView(false);
    renderOverviewAnalytics();
    renderInsights();
    void initializeInventoryData(true);
    void loadCompletedOrdersFromServer(true);
    void loadOrderLogsFromServer(true);
    await showStaffNotice(`Order #${orderId} was refunded and stock restored.`);
    return true;
}

function formatOrderLogAction(action) {
    const map = {
        order_completed: 'Marked complete',
        order_preparing: 'Order accepted · preparing',
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
        account_login: 'Staff login',
        account_logout: 'Staff logout',
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

function syncLogsDateFilterToToday() {
    if (!logsDateFilter) return;

    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    const isoDate = `${year}-${month}-${day}`;

    if (!logsDateFilter.value || logsDateFilter.value !== isoDate) {
        logsDateFilter.value = isoDate;
    }
}

function getSelectedLogsDateValue() {
    if (!logsDateFilter) return '';
    if (!logsDateFilter.value) {
        syncLogsDateFilterToToday();
    }
    return (logsDateFilter.value || '').trim();
}

function matchesSelectedLogDate(log) {
    const selectedDate = getSelectedLogsDateValue();
    if (!selectedDate) return true;

    const logDate = new Date(parseServerDateToMs(log.created_at_iso || log.created_at));
    if (Number.isNaN(logDate.getTime())) return false;

    const localDate = new Date(logDate.getTime() - (logDate.getTimezoneOffset() * 60000));
    const localIso = localDate.toISOString().slice(0, 10);
    return localIso === selectedDate;
}

function getFilteredOrderLogs() {
    // Review-specific events now live in their own container; the 'reviews'
    // filter reads from that dedicated source while every other filter
    // continues to read from the order activity logs.
    const baseLogs = activeOrderLogFilter === 'reviews'
        ? (Array.isArray(reviewActivityLogs) ? reviewActivityLogs : [])
        : (Array.isArray(orderActivityLogs) ? orderActivityLogs : []);
    let filtered = baseLogs;

    if (activeOrderLogFilter === 'today') {
        filtered = filtered.filter((log) => isLogFromToday(log));
    } else if (activeOrderLogFilter === 'qty') {
        filtered = filtered.filter((log) => isQtyChangeAction(log.action));
    } else if (activeOrderLogFilter === 'completed') {
        filtered = filtered.filter((log) => log.action === 'order_completed');
    } else if (activeOrderLogFilter === 'stock') {
        filtered = filtered.filter((log) => log.action === 'inventory_stock_changed');
    } else if (activeOrderLogFilter === 'inventory') {
        filtered = filtered.filter((log) => String(log.action || '').startsWith('inventory_'));
    } else if (activeOrderLogFilter === 'accounts') {
        filtered = filtered.filter((log) => String(log.action || '').startsWith('account_'));
    }

    if (getSelectedLogsDateValue()) {
        filtered = filtered.filter((log) => matchesSelectedLogDate(log));
    }

    return filtered;
}

function getLogFilterCounts() {
    const allLogs = Array.isArray(orderActivityLogs) ? orderActivityLogs : [];
    const reviewLogs = Array.isArray(reviewActivityLogs) ? reviewActivityLogs : [];
    return {
        all: allLogs.length + reviewLogs.length,
        today: allLogs.filter((log) => isLogFromToday(log)).length + reviewLogs.filter((log) => isLogFromToday(log)).length,
        qty: allLogs.filter((log) => isQtyChangeAction(log.action)).length,
        completed: allLogs.filter((log) => log.action === 'order_completed').length,
        stock: allLogs.filter((log) => log.action === 'inventory_stock_changed').length,
        inventory: allLogs.filter((log) => String(log.action || '').startsWith('inventory_')).length,
        accounts: allLogs.filter((log) => String(log.action || '').startsWith('account_')).length,
        reviews: reviewLogs.length
    };
}

function updateLogsFilterState() {
    if (!logsCategoryFilter) return;
    const selectedValue = logsFilterLabelMap[activeOrderLogFilter] ? activeOrderLogFilter : 'all';
    logsCategoryFilter.value = selectedValue;
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
            ? `Order #${escapeHtml(String(log.order_number))}`
            : '';
        const actorParts = [log.actor_role || 'Staff', log.actor_email || ''];
        const actorText = actorParts.filter(Boolean).map((part) => escapeHtml(String(part))).join(' · ');
        const details = log.details && typeof log.details === 'object' ? log.details : null;
        const qtyText = details && details.previous_quantity !== undefined && details.new_quantity !== undefined
            ? `<p><strong>Qty:</strong> ${escapeHtml(String(details.previous_quantity))} → ${escapeHtml(String(details.new_quantity))}</p>`
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
                    <strong>${escapeHtml(String(formatOrderLogAction(log.action)))}</strong>
                    <span>${escapeHtml(String(formatOrderLogTimestamp(log.created_at_iso || log.created_at)))}</span>
                </div>
                ${showOrderLabel && orderLabel ? `<p><strong>${orderLabel}</strong></p>` : ''}
                <p><strong>By:</strong> ${actorText || 'Staff'}</p>
                ${qtyText}
                ${reviewCommentText ? `<p class="review-log-comment"><strong>Comment:</strong> <span class="review-log-comment-text">${escapeHtml(String(reviewCommentText))}</span></p>` : ''}
                <p><strong>Summary:</strong> ${escapeHtml(String(summaryText))}</p>
            </article>
        `;
    }).join('');
}

async function loadOrderLogsFromServer(forceRefresh = false) {
    if (orderLogsSyncInFlight && !forceRefresh) return false;

    orderLogsSyncInFlight = true;
    try {
        // Gated by the server session; wait for the page-load renewal first.
        if (isStaffPage) {
            await ensureStaffServerSession();
        }
        const [logsResponse, reviewLogsResponse] = await Promise.all([
            fetch(getApiUrl(`api/get_order_logs.php?_=${Date.now()}`), { cache: 'no-store' }),
            fetch(getApiUrl(`api/get_review_logs.php?_=${Date.now()}`), { cache: 'no-store' })
        ]);
        if (!logsResponse.ok || !reviewLogsResponse.ok) return false;

        const [logsPayload, reviewLogsPayload] = await Promise.all([
            logsResponse.json(),
            reviewLogsResponse.json()
        ]);
        if (!logsPayload || logsPayload.success !== true || !reviewLogsPayload || reviewLogsPayload.success !== true) return false;

        orderActivityLogs = Array.isArray(logsPayload.logs) ? logsPayload.logs : [];
        reviewActivityLogs = Array.isArray(reviewLogsPayload.logs) ? reviewLogsPayload.logs : [];
        renderOrderLogs();
        return true;
    } catch (error) {
        console.error('Unable to load activity logs', error);
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

let reviewerToken = null;

function getOrCreateReviewerToken() {
    if (reviewerToken) return reviewerToken;

    reviewerToken = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

    return reviewerToken;
}

function getReviewPublishStatusLabel(status) {
    if ((status || '').toLowerCase() === 'published') {
        return 'Published';
    }
    return 'Pending';
}

function getFilteredReviewsByRating(reviews) {
    const filter = Number(activeReviewRatingFilter) || 0;
    if (filter < 1 || filter > 5) return reviews;
    return reviews.filter((review) => Number(review.rating) === filter);
}

function getReviewFilterEmptyMessage(rating) {
    if (!rating) return 'No reviews yet. Be the first to leave one.';
    return `No ${rating}-star reviews yet.`;
}

function renderCustomerReviews() {
    if (!customerReviewsList) return;

    const filteredReviews = getFilteredReviewsByRating(cachedReviews);
    if (!filteredReviews.length) {
        customerReviewsList.innerHTML = `<p class="menu-cart-empty">${getReviewFilterEmptyMessage(activeReviewRatingFilter)}</p>`;
        return;
    }

    customerReviewsList.innerHTML = filteredReviews.map((review) => {
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

function getReviewerIdentity(reviewerKey) {
    const key = String(reviewerKey || '');
    if (!key) return { label: 'Customer', initials: 'C' };
    const short = key.replace(/[^a-z0-9]/gi, '').slice(0, 4).toUpperCase();
    return {
        label: `Customer ${short}`,
        initials: (short ? short[0] : 'C')
    };
}

function renderStaffReviews() {
    if (!staffReviewList) return;

    const filteredReviews = getFilteredReviewsByRating(cachedStaffReviews);
    if (!filteredReviews.length) {
        staffReviewList.innerHTML = `<p class="menu-cart-empty">${getReviewFilterEmptyMessage(activeReviewRatingFilter)}</p>`;
        return;
    }

    staffReviewList.innerHTML = filteredReviews.map((review) => {
        const status = (review.publish_status || 'pending').toLowerCase();
        const safeReviewText = escapeHtml(review.review_text);
        const identity = getReviewerIdentity(review.reviewer_key || review.id);
        return `
            <article class="staff-review-card">
                <div class="staff-review-head">
                    <span class="staff-review-avatar" aria-hidden="true">${identity.initials}</span>
                    <div class="staff-review-who">
                        <strong>${identity.label}</strong>
                        <span>${formatRealtimeDate(review.created_at_iso || review.created_at)}</span>
                    </div>
                    <span class="review-status-badge ${status === 'published' ? 'is-published' : 'is-pending'}">${getReviewPublishStatusLabel(status)}</span>
                </div>
                <p><strong class="review-stars">${renderStarRating(review.rating)}</strong></p>
                <p class="review-comment">${safeReviewText}</p>
                <div class="staff-review-actions">
                    ${status !== 'published' ? `<button type="button" class="staff-review-publish-btn" data-review-id="${review.id}">Publish</button>` : ''}
                    <button type="button" class="staff-review-delete-btn" data-review-id="${review.id}">Delete Review</button>
                </div>
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
    }, 8000);
}

// Star-rating filter buttons (All / 1-5 stars) shared by public + staff views.
document.querySelectorAll('.review-filter-btn').forEach((button) => {
    button.addEventListener('click', () => {
        activeReviewRatingFilter = Number(button.dataset.reviewRating) || 0;
        document.querySelectorAll('.review-filter-btn').forEach((btn) => {
            btn.classList.toggle('is-active', btn === button);
        });
        renderCustomerReviews();
        renderStaffReviews();
    });
});

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
                reviewSubmitMessage.textContent = payload.message || 'Review submitted. It will appear immediately.';
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

        if (deleteButton) {
            const deleteConfirmed = await showStaffConfirm(
                'Delete this review permanently? This action cannot be undone.',
                { title: 'Delete review?', confirmLabel: 'Delete Review' }
            );
            if (!deleteConfirmed) return;
        }

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

            if (deleteButton) {
                // Optimistic removal so the admin sees the review disappear
                // immediately; the server fetch below then re-syncs the lists.
                cachedStaffReviews = cachedStaffReviews.filter((review) => Number(review.id) !== reviewId);
                cachedReviews = cachedReviews.filter((review) => Number(review.id) !== reviewId);
                renderCustomerReviews();
                renderStaffReviews();
            }

            void loadReviewsFromServer(true);
            void loadOrderLogsFromServer(true);
        } catch (error) {
            console.error('Unable to update review status', error);
            await showStaffNotice(error.message || 'Unable to update review status', true);
        }
    });
}

if (logsCategoryFilter) {
    logsCategoryFilter.addEventListener('change', (event) => {
        const filter = (event.target.value || 'all').trim();
        if (!filter) return;

        activeOrderLogFilter = filter;
        renderOrderLogs();
    });
}

if (logsDateFilter) {
    syncLogsDateFilterToToday();
    logsDateFilter.addEventListener('change', () => {
        renderOrderLogs();
    });
}

function loadCompletedOrders() {
    completedOrders = [];
}

function saveCompletedOrders() {
    // Completed orders are persisted on the server; no client-side storage.
}

function normalizeCompletedOrder(order) {
    return {
        ...order,
        // Normalize the order number so the notifications feed never renders
        // "Order #undefined" for completed orders after a page refresh.
        orderNumber: order.order_number || order.orderNumber || String(order.id),
        total: Number(order.total_amount ?? order.total ?? 0),
        timestamp: order.order_date ? Date.parse(order.order_date) || Date.now() : Date.now(),
        items: Array.isArray(order.items) ? order.items.map((item) => ({
            ...item,
            components: Array.isArray(item.components) ? item.components : []
        })) : []
    };
}

async function loadCompletedOrdersFromServer(forceRefresh = false) {
    if (completedOrdersSyncInFlight && !forceRefresh) return false;

    completedOrdersSyncInFlight = true;

    try {
        // Gated by the server session; wait for the page-load renewal first.
        if (isStaffPage) {
            await ensureStaffServerSession();
        }
        const response = await fetch(getApiUrl(`api/get_completed_orders.php?_=${Date.now()}`), { cache: 'no-store', credentials: 'same-origin' });
        if (!response.ok) return false;

        const payload = await response.json();
        if (!payload || payload.success !== true || !Array.isArray(payload.orders)) return false;

        completedOrders = payload.orders.map(normalizeCompletedOrder);
        completedOrders.sort((a, b) => b.timestamp - a.timestamp);
        saveCompletedOrders();
        recalculateSalesAnalytics();
        recalculateProfitAnalytics();
        // Background refreshes re-render the charts without replaying the
        // left-to-right draw animation; the animation is reserved for when a
        // staff member opens or switches to the Sales/Overview tab.
        updateAnalyticsView(false);
        updateProfitView(false);
        renderOverviewAnalytics(false);
        renderInsights();
        // Keep the server-filtered Insights cache in sync when a period filter
        // is active (e.g. after an order is completed/refunded).
        if (getInsightsFilterKey() !== 'all') {
            void refreshInsightsOrdersFromServer();
        }
        return true;
    } catch (error) {
        console.error('Unable to load completed orders from server', error);
        return false;
    } finally {
        completedOrdersSyncInFlight = false;
    }
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

function normalizeMenuCategoryKey(category) {
    const normalized = String(category || '').trim().toLowerCase().replace(/\s+/g, ' ');
    switch (normalized) {
        case 'batchoy':
            return 'batchoy';
        case 'silog':
            return 'silog';
        case 'friedchicken':
        case 'fried chicken':
            return 'friedChicken';
        case 'breakfast':
            return 'breakfast';
        case 'drinks':
            return 'drinks';
        case 'addons':
        case 'add on':
        case 'addon':
            return 'addons';
        case 'specials':
            return 'specials';
        case 'all':
            return 'all';
        default:
            if (menuData[normalized]) return normalized;
            return null;
    }
}

function resolveInventoryCategory(itemName) {
    const normalizedName = (itemName || '').trim().toLowerCase();
    if (!normalizedName) return 'specials';

    for (const [key, category] of Object.entries(menuData)) {
        const match = category.items.some((menuItem) => (menuItem.name || '').toLowerCase() === normalizedName);
        if (match) return key;
    }

    return 'specials';
}

// Standard restaurant food-cost ratio used to ESTIMATE a default unit cost for
// items that have no recorded cost yet (menu defaults and inventory rows whose
// unit cost was never set). This keeps profit figures meaningful out of the box;
// staff can replace any estimate with the real cost in Inventory Manager.
const DEFAULT_FOOD_COST_RATIO = 0.4;

function estimateDefaultUnitCost(price) {
    const parsed = Math.max(0, Number(price) || 0);
    return Math.round(parsed * DEFAULT_FOOD_COST_RATIO * 100) / 100;
}

function buildDefaultInventoryFromMenu() {
    const items = [];
    const seen = new Set();

    Object.entries(menuData).forEach(([categoryKey, category]) => {
        category.items.forEach((item) => {
            if (blockedProductNames.has(normalizeInventoryName(item.name))) return;
            if (seen.has(item.name)) return;
            seen.add(item.name);
            const price = parsePrice(item.price);
            items.push({
                name: item.name,
                price,
                stock: 0,
                status: 'Out of stock',
                category: categoryKey,
                description: item.description || '',
                unitCost: estimateDefaultUnitCost(price)
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
            components: normalizeSpecialComponents(food.components),
            // Specials are priced as the sum of their components, so their cost
            // is captured through the components — no separate estimate here.
            unitCost: 0
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
        const scopeParam = isStaffPage ? '&scope=staff' : '';
        // The staff scope is gated by the server session. On a page load the
        // session cookie is re-established by ensureStaffServerSession() — wait
        // for it so the first fetch does not 401 (which hid the inventory list).
        if (scopeParam) {
            await ensureStaffServerSession();
        }
        const inventoryUrl = getApiUrl(`api/get_inventory.php?_=${Date.now()}${scopeParam}`);
        const response = await fetch(inventoryUrl, { cache: 'no-store', credentials: 'same-origin' });
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
                description: item.description || localMatch?.description || '',
                image: normalizeImageUrl(item.image || localMatch?.image || ''),
                // Prefer a real recorded cost; fall back to an estimate when
                // the DB row has no cost yet (0/null) so profit is meaningful
                // for every item out of the box.
                unitCost: item.unit_cost != null && Number(item.unit_cost) > 0
                    ? Number(item.unit_cost)
                    : estimateDefaultUnitCost(item.price),
                reorderLevel: item.reorder_level != null ? Number(item.reorder_level) || 0 : (localMatch?.reorderLevel || 0),
                isAvailable: item.is_available !== false && item.is_available !== 'false' && item.is_available !== 0 && item.is_available !== '0'
            };
        }).filter((item) => !blockedProductNames.has(normalizeInventoryName(item.name)));

        // On success, trust the server exclusively — do NOT merge defaults
        // back in. Defaults are only used when the server fetch fails (see
        // catch block below). This prevents deleted items from re-appearing
        // on the customer page.
        const latestInventory = merged.map((item) => ({ ...item }));

        // Preserve locally-added items that the server response hasn't caught
        // up to yet (e.g. the server-side 15-second cache in get_inventory.php
        // still serves stale data, or the initial page-load fetch completes
        // after a save). Merge them into the server response so the UI
        // reflects what the staff member just saved.
        const serverNames = new Set(
            latestInventory.map((item) => normalizeInventoryName(item.name))
        );
        const localOnlyItems = inventoryData.filter(
            (item) => !serverNames.has(normalizeInventoryName(item.name))
        );
        inventoryData = localOnlyItems.length
            ? [...latestInventory, ...localOnlyItems]
            : latestInventory;
        inventoryLoadedFromServer = true;
        debugInventory('Applied server inventory', 'server');
    } catch (error) {
        inventoryData = inventoryData.length ? inventoryData : defaults;
        saveInventoryData();
        debugInventory('initializeInventoryData error — kept local or defaults', 'server-error');
    } finally {
        inventorySyncInFlight = false;
    }

    syncMenuPricesWithInventory();

    // Reconcile menuData and specialFoods against the actual server inventory.
    // If the snapshot still contains items that no longer exist in the DB,
    // remove them so the customer page stays in sync with inventory.
    // Only reconcile after the server inventory has been fetched at least
    // once. On a fresh page load inventoryData is [] which would incorrectly
    // wipe all menu/special items.
    if (inventoryLoadedFromServer) {
        const inventoryNames = new Set(
            inventoryData.map((item) => normalizeInventoryName(item.name))
        );
        let menuChanged = false;
        Object.values(menuData).forEach((category) => {
            if (!category || !Array.isArray(category.items)) return;
            const before = category.items.length;
            category.items = category.items.filter((item) =>
                inventoryNames.has(normalizeInventoryName(item.name))
            );
            if (category.items.length !== before) menuChanged = true;
        });
        const specialBefore = specialFoods.length;
        for (let i = specialFoods.length - 1; i >= 0; i--) {
            if (!inventoryNames.has(normalizeInventoryName(specialFoods[i].name))) {
                specialFoods.splice(i, 1);
            }
        }
        // Also pull any inventory items with category 'specials' into
        // specialFoods so they appear even when the custom-menu snapshot
        // is empty or hasn't been saved yet.
        inventoryData.forEach((invItem) => {
            const cat = normalizeMenuCategoryKey(invItem.category || resolveInventoryCategory(invItem.name));
            if (cat !== 'specials') return;
            const normName = normalizeInventoryName(invItem.name);
            const alreadyPresent = specialFoods.some((sf) => normalizeInventoryName(sf.name) === normName);
            if (!alreadyPresent && invItem.isAvailable !== false) {
                specialFoods.push({
                    name: invItem.name,
                    price: Number(invItem.price) || 0,
                    image: normalizeImageUrl(invItem.image || 'img1.jpg'),
                    description: invItem.description || '',
                    components: normalizeSpecialComponents(invItem.components)
                });
                menuChanged = true;
            }
        });
        if (specialFoods.length !== specialBefore) menuChanged = true;
        if (menuChanged) {
            saveCustomMenuData();
            renderSpecialFoods();
        }
    }

    renderInventoryManagement();
    renderWalkInOrderBuilder();
    if (inventoryModal && !inventoryModal.hidden && inventoryCategoryInput && inventoryCategoryInput.value === 'specials') {
        renderSpecialCustomizeControls();
    }
    if (currentMenuCategoryId) {
        showMenuCategory(currentMenuCategoryId);
    }
    updateCartDisplay();
    // Unit costs just arrived — refresh the profit figures so they reflect the
    // latest cost of goods sold (no-op on the customer page).
    recalculateProfitAnalytics();
    updateProfitView(false);
}

function saveInventoryData() {
    lastInventoryUpdateAt = Date.now();
    // Inventory is persisted directly to the Laravel database. No localStorage persistence is used.
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

if (productDetailPurchaseBtn) {
    productDetailPurchaseBtn.addEventListener('click', () => {
        if (!activeProductDetailItem) return;
        addToCart({
            name: activeProductDetailItem.name,
            price: Number(activeProductDetailItem.price) || 0
        }, productDetailQuantity);
        closeProductDetailModal();
        openCartModal();
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

async function saveCustomMenuData() {
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
            image: normalizeImageUrl(food.image || 'img1.jpg'),
            description: food.description || '',
            components: normalizeSpecialComponents(food.components)
        }))
    };

    try {
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });
        await fetch(getApiUrl('api/save_custom_menu.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify(snapshot),
            cache: 'no-store'
        });
    } catch (error) {
        console.error('Unable to sync custom menu snapshot to server', error);
    }
}

function mergeCustomMenuSnapshots(localSnapshot = {}, remoteSnapshot = {}) {
    const fixedCategories = ['batchoy', 'silog', 'friedChicken', 'breakfast', 'drinks', 'specials', 'addons'];
    const merged = { menuData: {}, specialFoods: [] };

    fixedCategories.forEach((categoryKey) => {
        const remoteItems = Array.isArray(remoteSnapshot.menuData?.[categoryKey]?.items) ? remoteSnapshot.menuData[categoryKey].items : [];
        const localItems = Array.isArray(localSnapshot.menuData?.[categoryKey]?.items) ? localSnapshot.menuData[categoryKey].items : [];
        const seenNames = new Set();

        merged.menuData[categoryKey] = {
            title: remoteSnapshot.menuData?.[categoryKey]?.title || localSnapshot.menuData?.[categoryKey]?.title || categoryKey.toUpperCase(),
            items: []
        };

        [...remoteItems, ...localItems].forEach((item) => {
            const normalizedName = (item.name || '').trim().toLowerCase();
            if (!normalizedName || seenNames.has(normalizedName)) return;
            seenNames.add(normalizedName);
            merged.menuData[categoryKey].items.push({
                name: item.name,
                price: item.price,
                description: item.description || ''
            });
        });
    });

    const seenSpecialSignatures = new Set();
    const remoteSpecials = Array.isArray(remoteSnapshot.specialFoods) ? remoteSnapshot.specialFoods : [];
    const localSpecials = Array.isArray(localSnapshot.specialFoods) ? localSnapshot.specialFoods : [];

    [...remoteSpecials, ...localSpecials].forEach((food) => {
        const name = (food.name || '').trim();
        if (!name) return;
        const image = normalizeImageUrl(food.image || 'img1.jpg');
        const signature = `${name.toLowerCase()}|${image}|${food.description || ''}|${JSON.stringify(normalizeSpecialComponents(food.components))}`;
        if (seenSpecialSignatures.has(signature)) return;
        seenSpecialSignatures.add(signature);

        merged.specialFoods.push({
            name,
            price: Number(food.price) || 0,
            image,
            description: food.description || '',
            components: normalizeSpecialComponents(food.components)
        });
    });

    return merged;
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

    const fixedCategories = ['batchoy', 'silog', 'friedChicken', 'breakfast', 'drinks', 'specials', 'addons'];
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
        snapshot.specialFoods.forEach((food) => {
            const name = (food.name || '').trim();
            if (!name) return;

            specialFoods.push({
                name,
                price: Number(food.price) || 0,
                image: normalizeImageUrl(food.image || 'img1.jpg'),
                description: food.description || '',
                components: normalizeSpecialComponents(food.components)
            });
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
        const response = await fetch(getApiUrl(`api/get_custom_menu.php?_=${Date.now()}`), { cache: 'no-store' });
        if (!response.ok) {
            console.error('Unable to fetch custom menu snapshot from server', response.status);
            return;
        }

        const payload = await response.json();
        if (payload && payload.success && payload.snapshot) {
            const changed = applyCustomMenuSnapshot(payload.snapshot);
            syncMenuPricesWithInventory();
            renderSpecialFoods();
            renderInventoryManagement();
            hydrateWalkInDraftItemsFromSpecialFoods();
            renderWalkInOrderBuilder();
            // Re-render the currently open category.  Skip if we are already
            // inside a showMenuCategory call to avoid an infinite loop.
            if (currentMenuCategoryId && !showMenuCategoryRecursing) {
                showMenuCategory(currentMenuCategoryId);
            }
        }
    } catch (error) {
        console.error('Unable to load custom menu snapshot', error);
    }
}
window.addEventListener('storage', (event) => {
    if (!event.key) return;
    if (inventoryEditLock) return;

});

function updateCartDisplay() {
    if (pruneEmptySpecialFoodsFromCart()) {
        saveCart();
    }
    clampCartToInventory();
    const totalItems = cartItems.reduce((sum, item) => sum + item.quantity, 0);
    if (menuTopCartCount) {
        menuTopCartCount.textContent = totalItems;
        menuTopCartCount.parentElement.classList.toggle('has-items', totalItems > 0);
    }
    updateMenuSalesTotal();
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
        const customizeExpanded = hasCustomizeOptions && item.componentsOpen === true;
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
                            ${hasCustomizeOptions ? `<button type="button" class="menu-cart-components-toggle" data-index="${index}" aria-expanded="${customizeExpanded ? 'true' : 'false'}" aria-label="Toggle customize options">${customizeExpanded ? 'Hide ▾' : 'Customize ▸'}</button>` : ''}
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
                ${hasCustomizeOptions && customizeExpanded ? `<div class="menu-cart-components"><p class="menu-cart-components-title">Customize</p><ul class="menu-cart-component-list">${componentRows}</ul></div>` : ''}
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

function getSpecialPriceFromComponents(components) {
    return normalizeSpecialComponents(components).reduce((sum, component) => {
        const inventoryItem = getInventoryItem(component.name);
        const unitPrice = inventoryItem ? Number(inventoryItem.price) : 0;
        return sum + Math.max(0, unitPrice) * Math.max(0, Number(component.quantity) || 0);
    }, 0);
}

function updateSpecialPriceForModal() {
    if (!inventoryPriceInput || !inventoryCategoryInput) return;
    const isSpecials = inventoryCategoryInput.value === 'specials';
    if (!isSpecials) return;

    const price = getSpecialPriceFromComponents(selectedSpecialComponents);
    inventoryPriceInput.value = price.toFixed(2);
}

function getCartQuantityForItem(name) {
    return cartItems.reduce((total, item) => total + (normalizeInventoryName(item.name) === normalizeInventoryName(name) ? Number(item.quantity) || 0 : 0), 0);
}

function findMenuItemByName(name) {
    const normalizedTarget = normalizeInventoryName(name);
    if (!normalizedTarget) return null;

    for (const category of Object.values(menuData)) {
        if (!category || !Array.isArray(category.items)) continue;
        const found = category.items.find((item) => normalizeInventoryName(item.name) === normalizedTarget);
        if (found) return found;
    }

    return specialFoods.find((item) => normalizeInventoryName(item.name) === normalizedTarget) || null;
}

function commitSelectedMenuQuantitiesToCart() {
    const selectedEntries = Object.entries(menuSelectionQuantities).filter(([, qty]) => Number(qty) > 0);
    if (!selectedEntries.length) {
        if (menuOrderMessage) {
            menuOrderMessage.textContent = 'Select item quantities first before adding to cart.';
        }
        return false;
    }

    selectedEntries.forEach(([name, quantity]) => {
        const item = findMenuItemByName(name);
        if (!item) {
            return;
        }
        addToCart({ name: item.name, price: Number(parsePrice(item.price)) }, Number(quantity));
    });

    menuSelectionQuantities = {};
    if (currentMenuCategoryId) {
        showMenuCategory(currentMenuCategoryId);
    }
    updateCartDisplay();
    return true;
}

function updateMenuSalesTotal() {
    const menuSalesTotal = document.getElementById('menuSalesTotal');
    if (!menuSalesTotal) return;
    const total = cartItems.reduce((sum, item) => sum + getCartItemLineTotal(item), 0);
    menuSalesTotal.textContent = formatCurrency(total);
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
            const selectedQty = menuSelectionQuantities[name] || 0;
            const availableStock = getAvailableStockForItem(name);

            if (quantityElement) {
                quantityElement.textContent = String(selectedQty);
            }
            if (increaseButton) {
                increaseButton.disabled = availableStock <= 0 || selectedQty >= availableStock;
            }
            if (decreaseButton) {
                decreaseButton.disabled = selectedQty <= 0;
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

function isTodayLocalTimestamp(timestamp) {
    const value = Number(timestamp);
    if (!value) return false;
    const date = new Date(value);
    const now = new Date();
    return date.getFullYear() === now.getFullYear()
        && date.getMonth() === now.getMonth()
        && date.getDate() === now.getDate();
}

function renderOrderNotifications() {
    if (!overviewOrderNotificationList || !overviewOrderRevenue) return;

    // The Overview feed only shows orders created on the current calendar day,
    // so historical orders never stack up in this view.
    const sortedPendingOrders = [...pendingOrders]
        .filter((order) => isTodayLocalTimestamp(order.timestamp))
        .sort((a, b) => b.timestamp - a.timestamp);
    const sortedCompletedOrders = [...completedOrders]
        .filter((order) => isTodayLocalTimestamp(order.timestamp))
        .sort((a, b) => b.timestamp - a.timestamp);
    const allOrders = [...sortedPendingOrders, ...sortedCompletedOrders];
    const totalRevenue = allOrders.reduce((sum, order) => sum + (order.total || 0), 0);
    overviewOrderRevenue.textContent = formatCurrency(totalRevenue);

    if (!allOrders.length) {
        overviewOrderNotificationList.innerHTML = '<p class="menu-cart-empty">No orders yet today.</p>';
        return;
    }

    overviewOrderNotificationList.innerHTML = allOrders.map((order) => {
        const isCompleted = completedOrders.some((completed) => completed.id === order.id);
        const items = Array.isArray(order.items) ? order.items : [];
        const customerName = String(order.customerName || order.customer_name || '').trim();
        const deliveryAddress = String(order.deliveryAddress || order.delivery_address || '').trim();
        const isSakayKo = isSakayKoOrderType(order.orderType);
        const displayNumber = String(order.orderNumber || order.order_number || order.id || '');
        const isPreparing = !isCompleted && (order.prepStartedAt != null && order.prepMinutes != null);
        const orderItems = items.map((item) => {
            const componentLines = Array.isArray(item.components) && item.components.length
                ? item.components.map((component) =>
                    `<li class="order-notif-component">↳ ${escapeHtml(component.name)} × ${Number(component.quantity) || 0}</li>`
                ).join('')
                : '';
            return `
                <li class="order-notif-item">
                    <span>${escapeHtml(item.name)} x${item.quantity}</span>
                    <strong>${formatCurrency(item.price * item.quantity)}</strong>
                    ${componentLines ? `<ul class="order-notif-components">${componentLines}</ul>` : ''}
                </li>
            `;
        }).join('');
        const badgeLabel = isCompleted ? 'Completed' : (isPreparing ? 'Preparing' : 'New');
        const badgeClass = isCompleted ? 'is-completed' : (isPreparing ? 'is-preparing' : 'is-new');
        const prepLine = isPreparing
            ? `<p class="order-notif-prep"><strong>Prep:</strong> ~${Number(order.prepMinutes) || 0} min</p>`
            : '';
        return `
            <article class="order-notification-card ${isCompleted ? 'completed' : ''}">
                <div class="order-notif-top">
                    <h4>Order #${escapeHtml(displayNumber)}</h4>
                    <span class="order-notif-badge ${badgeClass}">${badgeLabel}</span>
                </div>
                <div class="order-notif-body">
                    <div class="order-notif-customer">
                        <p><strong>Customer:</strong> ${customerName ? escapeHtml(customerName) : '—'}</p>
                        <p><strong>Order Type:</strong> ${escapeHtml(order.orderType || 'Dine In')}</p>
                        ${isSakayKo ? `<p class="order-notif-address"><strong>Address:</strong> ${deliveryAddress ? escapeHtml(deliveryAddress) : '—'}</p>` : ''}
                        <p><strong>Payment:</strong> ${escapeHtml(order.paymentMethod)}</p>
                        <p><strong>Submitted:</strong> ${formatRealtimeDate(order.timestamp)}</p>
                        ${prepLine}
                    </div>
                    <div class="order-notif-items">
                        <ul>${orderItems}</ul>
                    </div>
                </div>
                <div class="order-notif-footer">
                    <strong>Total: ${formatCurrency(order.total)}</strong>
                    ${isCompleted
                        ? `<button type="button" class="order-refund-btn" data-order-id="${order.id}"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Refund</button>`
                        : `<button type="button" class="order-notif-go-link" data-order-id="${order.id}"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> Orders → Pending Orders</button>`}
                </div>
            </article>
        `;
    }).join('');
}

/* ================= Sales & Receipt Export (Overview dashboard) ================= */
const exportDailyDateInput = document.getElementById('exportDailyDate');
const exportMonthSelect = document.getElementById('exportMonthSelect');
const exportYearSelect = document.getElementById('exportYearSelect');
const exportDailyControls = document.getElementById('exportDailyControls');
const exportMonthlyControls = document.getElementById('exportMonthlyControls');
const exportMessage = document.getElementById('exportMessage');
const exportSummary = document.getElementById('exportSummary');
const exportDailyBtn = document.getElementById('exportDailyBtn');
const exportMonthlyBtn = document.getElementById('exportMonthlyBtn');

const EXPORT_ORDER_COLUMNS = ['Order Number', 'Timestamp', 'Customer Order List', 'Order Total', 'Fulfillment Method', 'Payment Method'];

function toLocalDateInputValue(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function populateExportYearSelect() {
    if (!exportYearSelect) return;
    const currentYear = new Date().getFullYear();
    const fragment = document.createDocumentFragment();
    for (let year = currentYear; year >= currentYear - 6; year -= 1) {
        const option = document.createElement('option');
        option.value = String(year);
        option.textContent = String(year);
        if (year === currentYear) option.selected = true;
        fragment.appendChild(option);
    }
    exportYearSelect.innerHTML = '';
    exportYearSelect.appendChild(fragment);
}

function setExportMessage(text, isError = false) {
    if (!exportMessage) return;
    exportMessage.textContent = text || '';
    exportMessage.classList.toggle('is-error', Boolean(isError));
}

function setExportSummary(summary) {
    if (!exportSummary) return;
    if (!summary) {
        exportSummary.hidden = true;
        exportSummary.innerHTML = '';
        return;
    }
    exportSummary.hidden = false;
    exportSummary.innerHTML = `
        <div class="export-summary-item"><span>Orders</span><strong>${summary.orders}</strong></div>
        <div class="export-summary-item"><span>Total Sales</span><strong>${formatCurrency(summary.total)}</strong></div>
        <div class="export-summary-item"><span>Items Sold</span><strong>${summary.items}</strong></div>
    `;
}

function switchExportMode(mode) {
    const isDaily = mode === 'daily';
    if (exportDailyControls) exportDailyControls.hidden = !isDaily;
    if (exportMonthlyControls) exportMonthlyControls.hidden = isDaily;
    document.querySelectorAll('.export-mode-tab').forEach((tab) => {
        const active = tab.dataset.exportMode === mode;
        tab.classList.toggle('active', active);
        tab.setAttribute('aria-selected', String(active));
    });
    setExportMessage('');
    setExportSummary(null);
}

function getSelectedExportDate() {
    return exportDailyDateInput && exportDailyDateInput.value
        ? exportDailyDateInput.value
        : toLocalDateInputValue(new Date());
}

function getSelectedExportMonth() {
    const monthIndex = exportMonthSelect
        ? Math.max(0, Math.min(11, parseInt(exportMonthSelect.value, 10) || 0))
        : new Date().getMonth();
    const year = exportYearSelect
        ? parseInt(exportYearSelect.value, 10) || new Date().getFullYear()
        : new Date().getFullYear();
    const firstDay = new Date(year, monthIndex, 1);
    const lastDay = new Date(year, monthIndex + 1, 0);
    return {
        label: `${firstDay.toLocaleString('en-US', { month: 'long' })} ${year}`,
        from: toLocalDateInputValue(firstDay),
        to: toLocalDateInputValue(lastDay)
    };
}

function getTimezoneOffsetForLocalDate(dateStr) {
    const parts = String(dateStr || '').split('-').map(Number);
    if (parts.length !== 3 || !parts[0] || !parts[1] || !parts[2]) {
        return -new Date().getTimezoneOffset();
    }
    const localMidnight = new Date(parts[0], parts[1] - 1, parts[2], 0, 0, 0, 0);
    return -localMidnight.getTimezoneOffset();
}

async function fetchSalesReport(fromDate, toDate) {
    const tzOffset = getTimezoneOffsetForLocalDate(fromDate);
    const url = getApiUrl(`api/get_sales_report.php?from=${encodeURIComponent(fromDate)}&to=${encodeURIComponent(toDate)}&tz=${tzOffset}&_=${Date.now()}`);
    const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.success) {
        throw new Error(payload.error || `HTTP ${response.status}`);
    }
    return Array.isArray(payload.orders) ? payload.orders : [];
}

function formatExportTimestamp(order) {
    if (order.order_date_iso) {
        const date = new Date(order.order_date_iso);
        if (!Number.isNaN(date.getTime())) {
            return date.toLocaleString();
        }
    }
    return order.order_date ? String(order.order_date) : '—';
}

function buildCustomerOrderList(order) {
    const items = Array.isArray(order.items) ? order.items : [];
    if (!items.length) return '—';
    return items.map((item) => {
        const name = String(item.name || item.notes || 'Menu item').trim() || 'Menu item';
        const quantity = Math.max(1, Number(item.quantity) || 1);
        return `${quantity} × ${name}`;
    }).join('; ');
}

function excelSafe(value) {
    const str = String(value ?? '');
    return /^[=+\-@]/.test(str) ? `'${str}` : str;
}

function toLocalDateKey(iso) {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function buildOrderRow(order) {
    return [
        excelSafe(String(order.order_number || order.id || '—')),
        excelSafe(formatExportTimestamp(order)),
        excelSafe(buildCustomerOrderList(order)),
        Number(order.total_amount ?? order.total ?? 0),
        excelSafe(String(order.order_type || '—')),
        excelSafe(String(order.payment_method || '—'))
    ];
}

function orderItemCount(order) {
    return (Array.isArray(order.items) ? order.items : [])
        .reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
}

function buildExcelSheet(rows, columns, columnWidths) {
    const sheet = XLSX.utils.aoa_to_sheet([columns].concat(rows));
    sheet['!cols'] = columns.map((_, index) => {
        const width = columnWidths && columnWidths[index] ? columnWidths[index] : 16;
        return { wch: width };
    });
    return sheet;
}

async function exportDailySales() {
    if (!isStaffPage) return;
    if (typeof XLSX === 'undefined') {
        setExportMessage('Excel library is not loaded. Check your connection and refresh the page.', true);
        return;
    }

    const date = getSelectedExportDate();
    if (!date) {
        setExportMessage('Please choose a report date.', true);
        return;
    }

    setExportMessage('Fetching completed orders...');
    if (exportDailyBtn) exportDailyBtn.disabled = true;
    try {
        const orders = await fetchSalesReport(date, date);
        if (!orders.length) {
            setExportSummary(null);
            setExportMessage(`No completed orders found for ${date}.`);
            return;
        }

        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(
            workbook,
            buildExcelSheet(orders.map(buildOrderRow), EXPORT_ORDER_COLUMNS, [16, 22, 60, 14, 20, 16]),
            'Daily Sales'
        );
        XLSX.writeFile(workbook, `MOTASTE-Daily-Sales-${date}.xlsx`);

        const total = orders.reduce((sum, order) => sum + (Number(order.total_amount ?? order.total) || 0), 0);
        setExportSummary({
            orders: orders.length,
            total,
            items: orders.reduce((sum, order) => sum + orderItemCount(order), 0)
        });
        setExportMessage(`Exported ${orders.length} completed order(s) for ${date}.`);
    } catch (error) {
        setExportSummary(null);
        setExportMessage(`Export failed: ${error.message || 'Unexpected error'}`, true);
    } finally {
        if (exportDailyBtn) exportDailyBtn.disabled = false;
    }
}

async function exportMonthlySales() {
    if (!isStaffPage) return;
    if (typeof XLSX === 'undefined') {
        setExportMessage('Excel library is not loaded. Check your connection and refresh the page.', true);
        return;
    }

    const { label, from, to } = getSelectedExportMonth();
    setExportMessage(`Fetching completed orders for ${label}...`);
    if (exportMonthlyBtn) exportMonthlyBtn.disabled = true;
    try {
        const orders = await fetchSalesReport(from, to);
        if (!orders.length) {
            setExportSummary(null);
            setExportMessage(`No completed orders found for ${label}.`);
            return;
        }

        const dayMap = new Map();
        const itemMap = new Map();
        orders.forEach((order) => {
            const day = toLocalDateKey(order.order_date_iso);
            if (!dayMap.has(day)) dayMap.set(day, { orders: 0, total: 0, items: 0 });
            const dayEntry = dayMap.get(day);
            dayEntry.orders += 1;
            dayEntry.total += Number(order.total_amount ?? order.total) || 0;
            dayEntry.items += orderItemCount(order);

            (Array.isArray(order.items) ? order.items : []).forEach((item) => {
                const name = String(item.name || item.notes || 'Menu item').trim() || 'Menu item';
                if (!itemMap.has(name)) itemMap.set(name, { qty: 0, revenue: 0 });
                const itemEntry = itemMap.get(name);
                itemEntry.qty += Number(item.quantity) || 0;
                itemEntry.revenue += Number(item.line_total) || 0;
            });
        });

        const dayRows = [...dayMap.entries()]
            .sort((a, b) => (a[0] < b[0] ? -1 : 1))
            .map(([day, entry]) => [day, entry.orders, Number(entry.total.toFixed(2)), entry.items]);
        const orderRows = orders.map(buildOrderRow);
        const itemRows = [...itemMap.entries()]
            .sort((a, b) => b[1].qty - a[1].qty)
            .map(([name, entry]) => [excelSafe(name), entry.qty, Number(entry.revenue.toFixed(2))]);

        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, buildExcelSheet(dayRows, ['Date', 'Order Count', 'Total Sales', 'Items Sold'], [14, 14, 14, 14]), 'Daily Summary');
        XLSX.utils.book_append_sheet(workbook, buildExcelSheet(orderRows, EXPORT_ORDER_COLUMNS, [16, 22, 60, 14, 20, 16]), 'Orders');
        XLSX.utils.book_append_sheet(workbook, buildExcelSheet(itemRows, ['Food Item', 'Qty Sold', 'Revenue'], [40, 12, 14]), 'Items Summary');
        XLSX.writeFile(workbook, `MOTASTE-Monthly-Sales-${from.slice(0, 7)}.xlsx`);

        const total = orders.reduce((sum, order) => sum + (Number(order.total_amount ?? order.total) || 0), 0);
        setExportSummary({
            orders: orders.length,
            total,
            items: orders.reduce((sum, order) => sum + orderItemCount(order), 0)
        });
        setExportMessage(`Exported ${orders.length} completed order(s) for ${label}.`);
    } catch (error) {
        setExportSummary(null);
        setExportMessage(`Export failed: ${error.message || 'Unexpected error'}`, true);
    } finally {
        if (exportMonthlyBtn) exportMonthlyBtn.disabled = false;
    }
}

function initSalesExportModule() {
    if (!isStaffPage) return;
    if (exportDailyDateInput && !exportDailyDateInput.value) {
        exportDailyDateInput.value = toLocalDateInputValue(new Date());
    }
    populateExportYearSelect();
    if (exportMonthSelect && exportMonthSelect.value === '') {
        exportMonthSelect.value = String(new Date().getMonth());
    }
}

if (exportDailyBtn) {
    exportDailyBtn.addEventListener('click', () => void exportDailySales());
}
if (exportMonthlyBtn) {
    exportMonthlyBtn.addEventListener('click', () => void exportMonthlySales());
}
document.querySelectorAll('.export-mode-tab').forEach((tab) => {
    tab.addEventListener('click', () => switchExportMode(tab.dataset.exportMode || 'daily'));
});

/* ================= Profit report export (Sales page) ================= */
const profitExportDateInput = document.getElementById('profitExportDate');
const profitExportMonthSelect = document.getElementById('profitExportMonth');
const profitExportYearSelect = document.getElementById('profitExportYear');
const profitExportDailyControls = document.getElementById('profitExportDailyControls');
const profitExportMonthlyControls = document.getElementById('profitExportMonthlyControls');
const profitExportDailyBtn = document.getElementById('profitExportDailyBtn');
const profitExportMonthlyBtn = document.getElementById('profitExportMonthlyBtn');
const profitExportMessage = document.getElementById('profitExportMessage');

const PROFIT_EXPORT_COLUMNS = ['Order Number', 'Date & Time', 'Items', 'Revenue (₱)', 'COGS (₱)', 'Profit (₱)'];
const PROFIT_PERIOD_COLUMNS = ['Period', 'Orders', 'Revenue (₱)', 'COGS (₱)', 'Profit (₱)'];

function setProfitExportMessage(text, isError = false) {
    if (!profitExportMessage) return;
    profitExportMessage.textContent = text || '';
    profitExportMessage.classList.toggle('is-error', Boolean(isError));
}

function populateProfitExportYearSelect() {
    if (!profitExportYearSelect) return;
    const currentYear = new Date().getFullYear();
    const fragment = document.createDocumentFragment();
    for (let year = currentYear; year >= currentYear - 6; year -= 1) {
        const option = document.createElement('option');
        option.value = String(year);
        option.textContent = String(year);
        if (year === currentYear) option.selected = true;
        fragment.appendChild(option);
    }
    profitExportYearSelect.innerHTML = '';
    profitExportYearSelect.appendChild(fragment);
}

function getSelectedProfitExportMonth() {
    const monthIndex = profitExportMonthSelect
        ? Math.max(0, Math.min(11, parseInt(profitExportMonthSelect.value, 10) || 0))
        : new Date().getMonth();
    const year = profitExportYearSelect
        ? parseInt(profitExportYearSelect.value, 10) || new Date().getFullYear()
        : new Date().getFullYear();
    const firstDay = new Date(year, monthIndex, 1);
    const lastDay = new Date(year, monthIndex + 1, 0);
    return {
        label: `${firstDay.toLocaleString('en-US', { month: 'long' })} ${year}`,
        from: toLocalDateInputValue(firstDay),
        to: toLocalDateInputValue(lastDay)
    };
}

// Same cost model as the Profit analytics section: revenue from the order
// total, COGS from inventory unit costs (components-only for special dishes).
function computeOrderProfitExport(order) {
    const revenue = Math.max(0, Number(order.total_amount ?? order.total) || 0);
    let cost = 0;
    (Array.isArray(order.items) ? order.items : []).forEach((item) => {
        cost += getOrderItemCost(item);
    });
    return { revenue, cost, profit: revenue - cost };
}

function buildProfitOrderRows(orders) {
    return orders.map((order) => {
        const breakdown = computeOrderProfitExport(order);
        return [
            excelSafe(String(order.order_number || order.id || '—')),
            excelSafe(formatExportTimestamp(order)),
            excelSafe(buildCustomerOrderList(order)),
            Number(breakdown.revenue.toFixed(2)),
            Number(breakdown.cost.toFixed(2)),
            Number(breakdown.profit.toFixed(2))
        ];
    });
}

function appendProfitTotalsRow(rows, orders) {
    let revenue = 0;
    let cost = 0;
    orders.forEach((order) => {
        const breakdown = computeOrderProfitExport(order);
        revenue += breakdown.revenue;
        cost += breakdown.cost;
    });
    rows.push(['TOTAL', '', '', Number(revenue.toFixed(2)), Number(cost.toFixed(2)), Number((revenue - cost).toFixed(2))]);
}

function groupOrdersByLocalDay(orders) {
    const dayMap = new Map();
    orders.forEach((order) => {
        const day = toLocalDateKey(order.order_date_iso);
        if (!dayMap.has(day)) dayMap.set(day, { orders: 0, revenue: 0, cost: 0 });
        const entry = dayMap.get(day);
        const breakdown = computeOrderProfitExport(order);
        entry.orders += 1;
        entry.revenue += breakdown.revenue;
        entry.cost += breakdown.cost;
    });
    return [...dayMap.entries()]
        .sort((a, b) => (a[0] < b[0] ? -1 : 1))
        .map(([day, entry]) => [
            day,
            entry.orders,
            Number(entry.revenue.toFixed(2)),
            Number(entry.cost.toFixed(2)),
            Number((entry.revenue - entry.cost).toFixed(2))
        ]);
}

function appendProfitPeriodTotals(rows, orders) {
    let revenue = 0;
    let cost = 0;
    orders.forEach((order) => {
        const breakdown = computeOrderProfitExport(order);
        revenue += breakdown.revenue;
        cost += breakdown.cost;
    });
    rows.push(['TOTAL', orders.length, Number(revenue.toFixed(2)), Number(cost.toFixed(2)), Number((revenue - cost).toFixed(2))]);
}

async function exportProfitDaily() {
    if (!isStaffPage) return;
    if (typeof XLSX === 'undefined') {
        setProfitExportMessage('Excel library is not loaded. Check your connection and refresh the page.', true);
        return;
    }

    const date = profitExportDateInput && profitExportDateInput.value
        ? profitExportDateInput.value
        : toLocalDateInputValue(new Date());
    setProfitExportMessage('Fetching completed orders...');
    if (profitExportDailyBtn) profitExportDailyBtn.disabled = true;
    try {
        const orders = await fetchSalesReport(date, date);
        if (!orders.length) {
            setProfitExportMessage(`No completed orders found for ${date}.`);
            return;
        }

        const rows = buildProfitOrderRows(orders);
        appendProfitTotalsRow(rows, orders);
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, buildExcelSheet(rows, PROFIT_EXPORT_COLUMNS, [16, 22, 55, 14, 14, 14]), 'Daily Profit');
        XLSX.writeFile(workbook, `MOTASTE-Daily-Profit-${date}.xlsx`);

        let revenue = 0;
        let cost = 0;
        orders.forEach((order) => {
            const breakdown = computeOrderProfitExport(order);
            revenue += breakdown.revenue;
            cost += breakdown.cost;
        });
        setProfitExportMessage(`Exported ${orders.length} completed order(s) for ${date} — net profit ${formatCurrency(roundMoney(revenue - cost))}.`);
    } catch (error) {
        setProfitExportMessage(`Export failed: ${error.message || 'Unexpected error'}`, true);
    } finally {
        if (profitExportDailyBtn) profitExportDailyBtn.disabled = false;
    }
}

// Per-food-item profit rows: revenue from the item line total, COGS from
// getOrderItemCost() so the sums match the Monthly Profit sheet exactly. A
// special dish absorbs its components' costs into its own row.
function buildProfitItemRows(orders) {
    const itemMap = new Map();
    orders.forEach((order) => {
        (Array.isArray(order.items) ? order.items : []).forEach((item) => {
            const name = String(item.name || item.notes || 'Menu item').trim() || 'Menu item';
            if (!itemMap.has(name)) itemMap.set(name, { qty: 0, revenue: 0, cost: 0 });
            const entry = itemMap.get(name);
            entry.qty += Number(item.quantity) || 0;
            entry.revenue += Number(item.line_total) || 0;
            entry.cost += getOrderItemCost(item);
        });
    });

    const rows = [...itemMap.entries()]
        .sort((a, b) => b[1].qty - a[1].qty)
        .map(([name, entry]) => [
            excelSafe(name),
            entry.qty,
            Number(entry.revenue.toFixed(2)),
            Number(entry.cost.toFixed(2)),
            Number((entry.revenue - entry.cost).toFixed(2))
        ]);

    const totals = rows.reduce((acc, row) => {
        acc.qty += row[1];
        acc.revenue += row[2];
        acc.cost += row[3];
        return acc;
    }, { qty: 0, revenue: 0, cost: 0 });
    rows.push(['TOTAL', totals.qty, Number(totals.revenue.toFixed(2)), Number(totals.cost.toFixed(2)), Number((totals.revenue - totals.cost).toFixed(2))]);

    return rows;
}

async function exportProfitMonthly() {
    if (!isStaffPage) return;
    if (typeof XLSX === 'undefined') {
        setProfitExportMessage('Excel library is not loaded. Check your connection and refresh the page.', true);
        return;
    }

    const { label, from, to } = getSelectedProfitExportMonth();
    setProfitExportMessage(`Fetching completed orders for ${label}...`);
    if (profitExportMonthlyBtn) profitExportMonthlyBtn.disabled = true;
    try {
        const orders = await fetchSalesReport(from, to);
        if (!orders.length) {
            setProfitExportMessage(`No completed orders found for ${label}.`);
            return;
        }

        const rows = groupOrdersByLocalDay(orders);
        appendProfitPeriodTotals(rows, orders);
        const itemRows = buildProfitItemRows(orders);
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, buildExcelSheet(rows, PROFIT_PERIOD_COLUMNS, [14, 12, 14, 14, 14]), 'Monthly Profit');
        XLSX.utils.book_append_sheet(workbook, buildExcelSheet(itemRows, ['Food Item', 'Qty Sold', 'Revenue (₱)', 'COGS (₱)', 'Profit (₱)'], [40, 12, 14, 14, 14]), 'Item Profit Breakdown');
        XLSX.writeFile(workbook, `MOTASTE-Monthly-Profit-${from.slice(0, 7)}.xlsx`);

        let revenue = 0;
        let cost = 0;
        orders.forEach((order) => {
            const breakdown = computeOrderProfitExport(order);
            revenue += breakdown.revenue;
            cost += breakdown.cost;
        });
        setProfitExportMessage(`Exported ${orders.length} completed order(s) for ${label} — net profit ${formatCurrency(roundMoney(revenue - cost))}.`);
    } catch (error) {
        setProfitExportMessage(`Export failed: ${error.message || 'Unexpected error'}`, true);
    } finally {
        if (profitExportMonthlyBtn) profitExportMonthlyBtn.disabled = false;
    }
}

function switchProfitExportMode(mode) {
    const isDaily = mode === 'daily';
    if (profitExportDailyControls) profitExportDailyControls.hidden = !isDaily;
    if (profitExportMonthlyControls) profitExportMonthlyControls.hidden = mode !== 'monthly';
    document.querySelectorAll('.profit-export-tab').forEach((tab) => {
        const active = tab.dataset.profitExportMode === mode;
        tab.classList.toggle('active', active);
        tab.setAttribute('aria-selected', String(active));
    });
    setProfitExportMessage('');
}

function initProfitExportModule() {
    if (!isStaffPage) return;
    if (profitExportDateInput && !profitExportDateInput.value) {
        profitExportDateInput.value = toLocalDateInputValue(new Date());
    }
    populateProfitExportYearSelect();
    if (profitExportMonthSelect && profitExportMonthSelect.value === '') {
        profitExportMonthSelect.value = String(new Date().getMonth());
    }
}

if (profitExportDailyBtn) {
    profitExportDailyBtn.addEventListener('click', () => void exportProfitDaily());
}
if (profitExportMonthlyBtn) {
    profitExportMonthlyBtn.addEventListener('click', () => void exportProfitMonthly());
}
document.querySelectorAll('.profit-export-tab').forEach((tab) => {
    tab.addEventListener('click', () => switchProfitExportMode(tab.dataset.profitExportMode || 'daily'));
});

function getLowStockItems() {
    if (!Array.isArray(inventoryData)) return [];
    return inventoryData
        .filter((item) => Number(item.stock) <= 20)
        .sort((a, b) => (Number(a.stock) || 0) - (Number(b.stock) || 0));
}

function getLowStockReminderExpiry() {
    if (typeof window === 'undefined' || !window.localStorage) return 0;
    try {
        return Number(window.localStorage.getItem(lowStockAlertStorageKey) || 0) || 0;
    } catch (error) {
        return 0;
    }
}

function setLowStockReminderExpiry() {
    if (typeof window === 'undefined' || !window.localStorage) return;
    try {
        window.localStorage.setItem(lowStockAlertStorageKey, String(Date.now() + 30 * 60 * 1000));
    } catch (error) {
        // ignore storage failures
    }
}

function hideLowStockAlert() {
    if (!lowStockModalOverlay) return;
    lowStockModalOverlay.classList.remove('active');
    lowStockModalOverlay.hidden = true;
    lowStockModalOverlay.setAttribute('aria-hidden', 'true');
}

function renderLowStockAlert() {
    if (!lowStockModalOverlay || !lowStockItemDropdown) return;

    const lowStockItems = getLowStockItems();
    if (!lowStockItems.length) {
        hideLowStockAlert();
        return;
    }

    lowStockItemDropdown.innerHTML = lowStockItems.map((item) => {
        const stockCount = Number(item.stock) || 0;
        return `
            <div class="low-stock-item">
                <span>${escapeHtml(item.name)}</span>
                <strong>${stockCount} left</strong>
            </div>
        `;
    }).join('');

    lowStockItemDropdown.hidden = true;
    lowStockToggleListBtn.textContent = 'Show low-stock items';
    lowStockModalOverlay.hidden = false;
    lowStockModalOverlay.classList.add('active');
    lowStockModalOverlay.setAttribute('aria-hidden', 'false');
}

function shouldShowLowStockAlert() {
    const reminderExpiry = getLowStockReminderExpiry();
    if (reminderExpiry && Date.now() < reminderExpiry) {
        return false;
    }
    return getLowStockItems().length > 0;
}

function showLowStockAlertIfNeeded() {
    const activeSession = getLoggedInStaffSession();
    if (!activeSession) {
        hideLowStockAlert();
        return;
    }

    if (shouldShowLowStockAlert()) {
        renderLowStockAlert();
    } else {
        hideLowStockAlert();
    }
}

function initializeLowStockAlertHandlers() {
    if (lowStockModalCloseBtn) {
        lowStockModalCloseBtn.addEventListener('click', hideLowStockAlert);
    }

    if (lowStockToggleListBtn && lowStockItemDropdown) {
        lowStockToggleListBtn.addEventListener('click', () => {
            const isVisible = !lowStockItemDropdown.hidden;
            lowStockItemDropdown.hidden = isVisible;
            lowStockToggleListBtn.textContent = isVisible ? 'Show low-stock items' : 'Hide low-stock items';
        });
    }

    if (lowStockRemindLaterBtn) {
        lowStockRemindLaterBtn.addEventListener('click', () => {
            setLowStockReminderExpiry();
            hideLowStockAlert();
        });
    }
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
        const categoryKey = normalizeMenuCategoryKey(inventoryItem.category || resolveInventoryCategory(inventoryItem.name));
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
            food.image = normalizeImageUrl(priceMap[normalizedName].image || food.image || 'img1.jpg');
        }
    });
}

let inventorySelectedCategory = 'all';
let inventorySearchTerm = '';
let walkInSearchTerm = '';

// Helper: detect if user is currently in checkout/payment screens
function isUserInCheckoutOrPayment() {
    try {
        const payment = document.getElementById('orderPaymentScreen');
        const checkout = document.getElementById('orderCheckoutScreen');
        const paymentVisible = payment && !payment.classList.contains('hidden') && payment.getAttribute('aria-hidden') !== 'true';
        const checkoutVisible = checkout && !checkout.classList.contains('hidden') && checkout.getAttribute('aria-hidden') !== 'true';
        return paymentVisible || checkoutVisible || Boolean(suppressMenuOverlay);
    } catch (e) {
        return Boolean(suppressMenuOverlay);
    }
}

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

        const unitCost = Number(item.unitCost) || 0;
        const marginPct = item.price > 0 && unitCost > 0
            ? Math.round(((item.price - unitCost) / item.price) * 100)
            : null;
        const isHidden = item.isAvailable === false;
        const badgeHtml = [
            isHidden ? '<span class="inventory-badge badge-hidden"><i class="fa-solid fa-eye-slash"></i> Hidden</span>' : '',
            unitCost > 0 ? `<span class="inventory-badge badge-cost">Cost ${formatCurrency(unitCost)} · Margin ${marginPct}%</span>` : ''
        ].filter(Boolean).join('');

        if (!editing) {
            return `
                <article class="inventory-item-card${isHidden ? ' is-hidden' : ''}">
                    <div class="inventory-item-main">
                        <strong>${item.name}</strong>
                        <p><span class="inventory-item-category">${categoryLabel}</span></p>
                        <p>Price: ${formatCurrency(item.price)}</p>
                        <p class="inventory-stock-line">
                            <span>Stock: ${item.stock}</span>
                            ${item.stock <= 0 ? `<img src="../../outofstock1.png" alt="Out of stock" class="inventory-out-of-stock-image">` : ''}
                        </p>
                        <p>Status: ${item.status}</p>
                        ${badgeHtml ? `<p class="inventory-badges">${badgeHtml}</p>` : ''}
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
                        Unit Cost (₱)
                        <input type="number" min="0" step="0.01" data-field="unitCost" value="${Number(item.unitCost) || 0}">
                    </label>
                    <label>
                        Availability
                        <select data-field="isAvailable">
                            <option value="1" ${item.isAvailable !== false ? 'selected' : ''}>Available on menu</option>
                            <option value="0" ${item.isAvailable === false ? 'selected' : ''}>Hidden from menu</option>
                        </select>
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
        await ensureStaffServerSession();
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });
        const response = await fetch(getApiUrl('api/delete_inventory_item.php'), {
            method: 'POST',
            headers,
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
        await showStaffNotice(error.message || 'Unable to delete inventory item', true);
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
    renderSpecialFoods();
    if (currentMenuCategoryId) {
        showMenuCategory(currentMenuCategoryId);
    }
}

async function saveInventoryItem(event) {
    if (event && event.preventDefault) {
        event.preventDefault();
    }

    if (!inventoryNameInput || !inventoryPriceInput || !inventoryStockInput || !inventoryStatusInput || !inventoryCategoryInput || !inventoryDescriptionInput) return;

    const name = inventoryNameInput.value.trim();
    const category = inventoryCategoryInput.value || 'specials';
    const stock = Number(inventoryStockInput.value);
    const status = stock <= 0 ? 'Out of stock' : inventoryStatusInput.value;
    const description = inventoryDescriptionInput.value.trim();
    const specialImage = category === 'specials' ? selectedSpecialFoodImageData : '';
    const specialComponents = category === 'specials' ? normalizeSpecialComponents(selectedSpecialComponents) : [];
    const price = category === 'specials'
        ? getSpecialPriceFromComponents(specialComponents)
        : Number(inventoryPriceInput.value);

    if (!name || Number.isNaN(price) || Number.isNaN(stock)) {
        return;
    }

    if (category === 'specials' && !specialComponents.length) {
        await showStaffNotice('Special food price is calculated from added components. Add at least one component to save.', true);
        return;
    }

    const existingItem = inventoryData.find((item) => item.name.toLowerCase() === name.toLowerCase());
    const previousImageUrl = existingItem?.image || '';
    let imageUrl = category === 'specials' ? previousImageUrl : '';

    if (category === 'specials' && selectedSpecialFoodImageData) {
        imageUrl = normalizeImageUrl(selectedSpecialFoodImageData);
        selectedSpecialFoodImageFile = null;
    }

    const unitCost = Math.max(0, Number(inventoryUnitCostInput?.value) || 0);
    const isAvailable = inventoryAvailabilityInput ? inventoryAvailabilityInput.value !== '0' : true;

    if (existingItem) {
        existingItem.price = price;
        existingItem.stock = stock;
        existingItem.status = status;
        existingItem.category = category;
        existingItem.description = description;
        existingItem.components = specialComponents;
        existingItem.image = imageUrl;
        existingItem.unitCost = unitCost;
        existingItem.isAvailable = isAvailable;
        saveMenuCatalogItem({ ...existingItem, description, image: imageUrl, components: specialComponents });
    } else {
        inventoryData.push({
            name,
            price,
            stock,
            status,
            category,
            description,
            components: specialComponents,
            image: imageUrl,
            unitCost,
            isAvailable
        });
        saveMenuCatalogItem({ name, price, stock, status, category, description, image: imageUrl, components: specialComponents });
    }

    inventoryEditItemName = null;
    if (inventorySaveBtn) {
        inventorySaveBtn.textContent = 'Save Inventory Item';
    }

    saveInventoryData();
    let syncSucceeded = false;

    try {
        await ensureStaffServerSession();
        const actor = getCurrentStaffActor();
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });
        const response = await fetch(getApiUrl('api/update_inventory.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify({
                name,
                previousName: existingItem ? existingItem.name : null,
                price,
                stock,
                status,
                category,
                description,
                image: imageUrl,
                unitCost: Number(inventoryUnitCostInput?.value) || 0,
                isAvailable: inventoryAvailabilityInput ? (inventoryAvailabilityInput.value !== '0' ? 1 : 0) : 1,
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
        await showStaffNotice(`Inventory update failed on server. ${error?.message || ''}`, true);
    }

    if (!syncSucceeded) {
        inventoryEditLock = false;
        void initializeInventoryData(true);
        return;
    }
    debugInventory('Saved inventory and sent update to server', 'local-save');

    syncMenuPricesWithInventory();
    renderInventoryManagement();
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
    // NOTE: Do NOT call initializeInventoryData(true) here —
    // renderInventoryManagement() above already shows the correct local
    // inventory data. A background re-fetch could overwrite locally-added
    // items with a stale server response (15-second cache / race with the
    // initial page-load fetch).
    void loadOrderLogsFromServer(true);
    selectedSpecialFoodImageData = '';
}

function editInventoryItem(name) {
    const item = inventoryData.find((inventoryItem) => inventoryItem.name === name);
    if (!item) return;

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
    const unitCostInput = card.querySelector('[data-field="unitCost"]');
    const isAvailableInput = card.querySelector('[data-field="isAvailable"]');
    const descriptionInput = card.querySelector('[data-field="description"]');
    const statusInput = card.querySelector('[data-field="status"]');

    if (!nameInput || !categoryInput || !priceInput || !stockInput || !descriptionInput || !statusInput) return;

    const nextName = nameInput.value.trim();
    const price = Number(priceInput.value);
    const stock = Number(stockInput.value);
    const category = categoryInput.value || 'specials';
    const description = descriptionInput.value.trim();
    const status = stock <= 0 ? 'Out of stock' : statusInput.value;
    const unitCost = unitCostInput ? Math.max(0, Number(unitCostInput.value) || 0) : (previousItem.unitCost || 0);
    const isAvailable = isAvailableInput ? isAvailableInput.value !== '0' : (previousItem.isAvailable !== false);

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
    previousItem.unitCost = unitCost;
    previousItem.isAvailable = isAvailable;

    saveMenuCatalogItem(previousItem, itemName);
    saveInventoryData();
    let syncSucceeded = false;
    try {
        await ensureStaffServerSession();
        const actor = getCurrentStaffActor();
        const headers = await withCsrfHeaders({
            'Content-Type': 'application/json'
        });
        const response = await fetch(getApiUrl('api/update_inventory.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify({
                name: nextName,
                previousName: itemName,
                price,
                stock,
                status,
                category,
                description,
                unitCost: previousItem.unitCost,
                isAvailable: previousItem.isAvailable ? 1 : 0,
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
        renderSpecialFoods();
        await showStaffNotice(`Inventory update failed on server. ${error?.message || ''}`, true);
        return;
    }

    if (!syncSucceeded) {
        return;
    }

    syncMenuPricesWithInventory();
    inventoryEditItemName = null;
    inventoryEditLock = false;
    renderInventoryManagement();
    renderSpecialFoods();

    if (currentMenuCategoryId) {
        showMenuCategory(currentMenuCategoryId);
    }

    // NOTE: Do NOT call initializeInventoryData(true) here —
    // renderInventoryManagement() above already shows the correct local
    // inventory data. A background re-fetch could overwrite locally-added
    // items with a stale server response.
    void loadOrderLogsFromServer(true);
}

function renderOverviewAnalytics(animate = true) {
    if (!overviewAnalyticsSelect || !overviewAnalyticsChart || !overviewMonthSelect || !overviewMonthWrapper) return;

    const view = overviewAnalyticsSelect.value;
    overviewMonthWrapper.style.display = view === 'monthly' ? 'none' : 'inline-flex';

    if (view === 'daily') {
        const month = overviewMonthSelect.value;
        const monthData = monthlySalesByMonth[month] || monthlySalesByMonth.jan;
        renderDetailChart(overviewAnalyticsChart, monthData, `Daily Sales — ${overviewMonthSelect.options[overviewMonthSelect.selectedIndex]?.text || ''}`, animate);
        autoScrollChartToCurrentDay(overviewAnalyticsChart, month, monthData.length);
    } else if (view === 'weekly') {
        const month = overviewMonthSelect.value;
        const monthData = weeklySalesByMonth[month] || weeklySalesByMonth.jan;
        renderDetailChart(overviewAnalyticsChart, monthData, `Weekly Sales — ${overviewMonthSelect.options[overviewMonthSelect.selectedIndex]?.text || ''}`, animate);
    } else {
        const monthly = analyticsData.monthly.items;
        renderDetailChart(overviewAnalyticsChart, monthly, 'Monthly Sales', animate);
    }
}

function showDashboardSection(section) {
    setInventoryModalVisible(false);

    if (section === logsSection && logsDateFilter) {
        syncLogsDateFilterToToday();
    }

    const sections = [overviewSection, salesSection, pendingOrdersSection, inventorySection, logsSection, accountManagementSection, highlightsSection, credentialsSection];
    sections.forEach((el) => {
        if (!el) return;
        el.hidden = el !== section;
    });

    // Chart pan buttons need a refresh once the section is visible again.
    setupChartScrollControls();

    if (section === credentialsSection) {
        syncLoginHistoryDateToToday();
        void loadTrustedDevices();
        void loadLoginHistory();
    }

    if (section && section.id) {
        saveActiveSection(section.id);
    }

    // Update active link highlight in the dashboard panel
    try {
        const linkMap = {
            overview: overviewLink,
            'pending-orders': ordersLink,
            inventory: inventoryLink,
            sales: salesLink,
            logs: logsLink,
            'account-management': accountManagementLink,
            highlights: highlightsLink,
            credentials: credentialsLink,
        };

        Object.values(linkMap).forEach((lnk) => {
            if (!lnk) return;
            lnk.classList.remove('active');
        });

        const activeLink = linkMap[section.id];
        if (activeLink) activeLink.classList.add('active');
    } catch (e) {
        // ignore
    }
}

function isItemOutOfStock(itemName) {
    const inventoryItem = getInventoryItem(itemName);
    if (!inventoryItem) return false;
    // Staff can hide an item entirely (is_available) even when stock remains.
    if (inventoryItem.isAvailable === false) return true;
    return Number(inventoryItem.stock) <= 0;
}

let _lastSpecialFoodsHash = '';

function renderSpecialFoods() {
    if (!specialFoodsList) return;

    // Build a lightweight signature so we skip the DOM wipe when nothing changed
    const signature = specialFoods.map((s) => `${s.name}|${s.price}|${s.image || ''}|${s.description || ''}`).join('\n');
    if (signature === _lastSpecialFoodsHash) return;
    _lastSpecialFoodsHash = signature;

    specialFoodsList.innerHTML = specialFoods.map((item) => {
        const imageSrc = normalizeImageUrl(item.image || 'img1.jpg');
        const description = getInventoryDescription(item.name, item.description || 'Tap the image to view full details.');
        const isOutOfStock = isItemOutOfStock(item.name);
        return `
        <article class="special-food-card${isOutOfStock ? ' is-out-of-stock' : ''}" data-name="${item.name}"${isOutOfStock ? ' aria-disabled="true"' : ''}>
            <button type="button" class="special-food-view-btn" data-name="${item.name}" aria-label="View ${item.name} details"${isOutOfStock ? ' disabled' : ''}>
                <img src="${imageSrc}" alt="${item.name}" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='img1.jpg';">
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
        existing.componentsOpen = existing.componentsOpen === true;
        existing.componentsMode = 'total';
        existing.quantity += quantity;
    } else {
        cartItems.push({
            ...item,
            quantity,
            baseComponents: normalizeSpecialComponents(specialComponents),
            components: buildInitialCartComponents(specialComponents, quantity),
            componentsMode: 'total',
            componentsOpen: false
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
            const componentLines = Array.isArray(item.components) ? item.components : [];
            const componentsHtml = componentLines.length ? `
                <ul class="pending-item-components">
                    ${componentLines.map((component) => {
                        const compQty = Number(component.quantity) || 0;
                        const compName = escapeHtml(component.name);
                        const maxComponentAllowed = getMaxEditablePendingComponentQuantity(order.id, item, component.name);
                        const canCompIncrease = canCompleteOrders && compQty < maxComponentAllowed;
                        const canCompDecrease = canCompleteOrders && compQty > 0;
                        return `
                            <li class="pending-item-component-row">
                                <span>${compName}</span>
                                ${canCompleteOrders ? `
                                    <div class="pending-item-component-controls">
                                        <button type="button" class="pending-item-component-btn" data-action="decrease" data-order-index="${index}" data-item-id="${item.id}" data-component-name="${escapeHtml(component.name)}"${!canCompDecrease ? ' disabled' : ''}>−</button>
                                        <span class="pending-item-component-qty">${compQty}</span>
                                        <button type="button" class="pending-item-component-btn" data-action="increase" data-order-index="${index}" data-item-id="${item.id}" data-component-name="${escapeHtml(component.name)}"${!canCompIncrease ? ' disabled' : ''}>+</button>
                                    </div>
                                ` : `<span>× ${compQty}</span>`}
                            </li>
                        `;
                    }).join('')}
                </ul>
            ` : '';

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
                    ${componentsHtml}
                </li>
            `;
        }).join('');
        const customerName = String(order.customerName || order.customer_name || '').trim();
        const deliveryAddress = String(order.deliveryAddress || order.delivery_address || '').trim();
        const isSakayKo = isSakayKoOrderType(order.orderType);
        const displayNumber = String(order.orderNumber || order.order_number || order.id || '');
        const isPreparing = order.prepStartedAt != null && order.prepMinutes != null;
        const prepMinutesValue = isPreparing ? Number(order.prepMinutes) || 15 : 15;
        const countdownDetails = isPreparing ? getPreparationCountdownDetails(order.prepStartedAt, order.prepMinutes) : null;
        const countdownBlock = countdownDetails
            ? `
                <div class="pending-order-countdown${countdownDetails.progress <= 0 ? ' is-done' : ''}" data-prep-started-at="${escapeHtml(order.prepStartedAt)}" data-prep-minutes="${Number(order.prepMinutes) || 0}">
                    <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                    <span class="pending-order-countdown-text">${escapeHtml(countdownDetails.progress > 0 ? countdownDetails.clock : 'Almost ready!')}</span>
                    <span class="pending-order-countdown-bar" aria-hidden="true"><span class="pending-order-countdown-bar-fill" style="width:${Math.round(countdownDetails.progress * 100)}%"></span></span>
                </div>
            `
            : '';
        const prepBlock = canCompleteOrders
            ? `
                <div class="pending-order-actions">
                    <div class="pending-order-total">
                        <strong>Total: ${formatCurrency(order.total)}</strong>
                        ${isPreparing ? `<span class="pending-order-prep-status"><i class="fa-solid fa-fire-burner" aria-hidden="true"></i> Preparing · ~${prepMinutesValue} min est.</span>` : ''}
                    </div>
                    ${countdownBlock}
                    <div class="pending-order-prep-row">
                        <label class="prep-minutes-field">
                            <span>Est. minutes</span>
                            <input type="number" class="prep-minutes-input" min="1" max="180" step="1" value="${prepMinutesValue}" aria-label="Estimated preparation minutes">
                        </label>
                        <button type="button" class="prepare-order-btn" data-order-index="${index}">${isPreparing ? 'Update' : 'Prepare'}</button>
                        <button type="button" class="order-complete-btn" data-order-index="${index}">Mark Complete</button>
                        <button type="button" class="order-print-btn" data-order-index="${index}"><i class="fa-solid fa-print" aria-hidden="true"></i> Receipt</button>
                        <button type="button" class="order-cancel-btn" data-order-index="${index}"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Cancel</button>
                    </div>
                </div>
            `
            : `<strong>Total: ${formatCurrency(order.total)}</strong>`;
        return `
            <article class="pending-order-card${isPreparing ? ' is-preparing' : ''}${overdueOrderIds.has(String(order.id)) ? ' is-overdue' : ''}" data-order-id="${order.id}">
                <div class="pending-order-top">
                    <h4>Order #${escapeHtml(displayNumber)}</h4>
                    <span class="pending-order-type">${escapeHtml(order.orderType || 'Dine In')}</span>
                </div>
                <p><strong>Submitted:</strong> ${formatRealtimeDate(order.timestamp)}</p>
                <p><strong>Payment:</strong> ${escapeHtml(order.paymentMethod)}</p>
                ${customerName ? `<p><strong>Customer:</strong> ${escapeHtml(customerName)}</p>` : ''}
                ${isSakayKo ? `<p class="order-notif-address"><strong>Address:</strong> ${deliveryAddress ? escapeHtml(deliveryAddress) : '—'}</p>` : ''}
                <ul>${itemsHtml}</ul>
                ${prepBlock}
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

function getReservedPendingDraftComponentQuantity(componentName, excludingDraftIndex = null) {
    const normalizedName = normalizeInventoryName(componentName);
    if (!normalizedName) return 0;

    const pendingReserved = getReservedPendingQuantityForItem(componentName);
    const draftReserved = walkInDraftItems.reduce((sum, item, index) => {
        if (excludingDraftIndex !== null && index === excludingDraftIndex) return sum;
        if (!Array.isArray(item.components)) return sum;
        const component = item.components.find((entry) => normalizeInventoryName(entry.name) === normalizedName);
        return sum + Math.max(0, Number(component ? component.quantity : 0));
    }, 0);

    return pendingReserved + draftReserved;
}

function getAvailablePendingStockForComponent(componentName, excludingDraftIndex = null) {
    const inventoryItem = getInventoryItem(componentName);
    if (!inventoryItem) return 0;

    const stock = Math.max(0, Number(inventoryItem.stock) || 0);
    const reserved = getReservedPendingDraftComponentQuantity(componentName, excludingDraftIndex);
    return Math.max(0, stock - reserved);
}

function canIncreaseWalkInDraftComponentQuantity(index, componentName) {
    return getAvailablePendingStockForComponent(componentName, index) >= 1;
}

function adjustWalkInDraftItemComponentQuantity(index, componentName, change) {
    if (Number.isNaN(index) || index < 0 || index >= walkInDraftItems.length || !change) return;
    const item = walkInDraftItems[index];
    if (!item) return;

    const currentQuantity = getCartItemComponentQuantity(item, componentName);
    const nextQuantity = currentQuantity + change;
    if (change > 0 && !canIncreaseWalkInDraftComponentQuantity(index, componentName)) {
        setWalkInOrderMessage(`Cannot add more ${componentName}. stock limit reached.`, true);
        return;
    }
    if (nextQuantity < 0) return;

    setCartItemComponentQuantity(item, componentName, nextQuantity);
    renderWalkInOrderBuilder();
}

function removeWalkInDraftItemComponent(index, componentName) {
    if (Number.isNaN(index) || index < 0 || index >= walkInDraftItems.length) return;
    const item = walkInDraftItems[index];
    if (!item) return;
    setCartItemComponentQuantity(item, componentName, 0);
    renderWalkInOrderBuilder();
}

function toggleWalkInDraftItemComponents(index) {
    if (Number.isNaN(index) || index < 0 || index >= walkInDraftItems.length) return;
    const item = walkInDraftItems[index];
    if (!item || !getCartItemCustomizeOptions(item).length) return;
    item.componentsOpen = !item.componentsOpen;
    renderWalkInOrderBuilder();
}

function hydrateWalkInDraftItemsFromSpecialFoods() {
    walkInDraftItems.forEach((item) => {
        if (!item || !item.name) return;
        const specialComponents = getSpecialFoodComponentsByName(item.name);
        if (!specialComponents.length) return;

        if (!Array.isArray(item.baseComponents) || !item.baseComponents.length) {
            item.baseComponents = normalizeSpecialComponents(specialComponents);
        }
        if (!Array.isArray(item.components) || !item.components.length) {
            item.components = buildInitialCartComponents(specialComponents, Number(item.quantity) || 0);
            item.componentsMode = 'total';
            item.componentsOpen = false;
        }
    });
}

function getWalkInDraftTotal() {
    return walkInDraftItems.reduce((sum, item) => sum + getCartItemLineTotal(item), 0);
}

function renderWalkInOrderBuilder() {
    hydrateWalkInDraftItemsFromSpecialFoods();
    if (walkInItemInput) {
        const previousSelection = walkInItemInput.value;
        walkInAvailableItems = (inventoryData || [])
            .filter((item) => getAvailablePendingStockForItem(item.name) > 0)
            .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));

        if (!walkInAvailableItems.length) {
            walkInItemInput.value = '';
            walkInItemInput.placeholder = 'No available items';
            walkInItemInput.disabled = true;
        } else {
            walkInItemInput.disabled = false;
            walkInItemInput.placeholder = 'Click to choose a product';

            const stillExists = walkInAvailableItems.some((item) => item.name === previousSelection);
            walkInItemInput.value = stillExists ? previousSelection : walkInAvailableItems[0].name;
        }
        renderWalkInItemDropdown();
    }

    if (!walkInDraftList) return;

    if (!walkInDraftItems.length) {
        walkInDraftList.innerHTML = '<p class="menu-cart-empty">No walk-in items yet.';
        return;
    }

    walkInDraftList.innerHTML = `
        <div class="walkin-draft-items">
            ${walkInDraftItems.map((item, index) => {
                const available = getAvailablePendingStockForItem(item.name);
                const canIncrease = item.quantity < available;
                const canDecrease = item.quantity > 1;
                const customizeOptions = getCartItemCustomizeOptions(item);
                const hasCustomizeOptions = customizeOptions.length > 0;
                const customizeExpanded = hasCustomizeOptions && item.componentsOpen === true;

                const componentRows = hasCustomizeOptions
                    ? `<ul class="walkin-draft-component-list">
                        ${customizeOptions.map((componentName) => {
                            const quantity = getCartItemComponentQuantity(item, componentName);
                            const canIncreaseComp = canIncreaseWalkInDraftComponentQuantity(index, componentName);
                            return `
                                <li class="walkin-draft-component-item">
                                    <span class="walkin-draft-component-name">${escapeHtml(componentName)} x${quantity}</span>
                                    <div class="walkin-draft-component-controls">
                                        <button type="button" class="walkin-draft-component-btn" data-action="decrease" data-index="${index}" data-component-name="${escapeHtml(componentName)}"${quantity <= 0 ? ' disabled' : ''}>−</button>
                                        <span class="walkin-draft-component-qty">${quantity}</span>
                                        <button type="button" class="walkin-draft-component-btn" data-action="increase" data-index="${index}" data-component-name="${escapeHtml(componentName)}"${canIncreaseComp ? '' : ' disabled'}>+</button>
                                        <button type="button" class="walkin-draft-component-remove-btn" data-index="${index}" data-component-name="${escapeHtml(componentName)}"${quantity <= 0 ? ' disabled' : ''}>Remove</button>
                                    </div>
                                </li>
                            `;
                        }).join('')}
                    </ul>`
                    : '';

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
                            ${hasCustomizeOptions ? `<button type="button" class="walkin-draft-customize-toggle-btn" data-index="${index}" aria-expanded="${customizeExpanded ? 'true' : 'false'}">${customizeExpanded ? 'Hide Components' : 'Customize'}</button>` : ''}
                            <button type="button" class="walkin-draft-remove-btn" data-index="${index}">Remove</button>
                        </div>
                        ${hasCustomizeOptions && customizeExpanded ? `<div class="walkin-draft-components">
                            <p class="walkin-draft-components-title">Components</p>
                            ${componentRows}
                        </div>` : ''}
                    </article>
                `;
            }).join('')}
        </div>
        <p class="walkin-draft-total"><strong>Total:</strong> ${formatCurrency(getWalkInDraftTotal())}</p>
    `;
}

function getFilteredWalkInItems(query) {
    const q = String(query || '').trim().toLowerCase();
    if (!q) return walkInAvailableItems;
    return walkInAvailableItems.filter((item) => String(item.name || '').toLowerCase().includes(q));
}

function renderWalkInItemDropdown(query = '') {
    if (!walkInItemDropdown) return;

    const matches = getFilteredWalkInItems(query);
    if (!matches.length || walkInItemInput.disabled) {
        walkInItemDropdown.innerHTML = '';
        walkInItemDropdown.hidden = true;
        walkInDropdownOpen = false;
        if (walkInItemInput) walkInItemInput.setAttribute('aria-expanded', 'false');
        return;
    }

    walkInItemDropdown.innerHTML = matches.map((item, index) => {
        const available = getAvailablePendingStockForItem(item.name);
        const active = index === walkInDropdownHighlight ? ' class="active"' : '';
        return `
            <button type="button" role="option" aria-selected="${index === walkInDropdownHighlight}" class="walkin-item-option${active}" data-name="${escapeHtml(item.name)}" data-index="${index}">
                <span class="walkin-item-option-name">${escapeHtml(item.name)}</span>
                <span class="walkin-item-option-meta">${formatCurrency(item.price)} · stock ${available}</span>
            </button>
        `;
    }).join('');

    // Reveal the list ONLY when the user actually opened it (click/focus/typing).
    // Background refreshes call renderWalkInOrderBuilder() frequently; they must
    // re-populate the contents but never force the panel open on their own.
    if (walkInDropdownOpen) {
        walkInItemDropdown.hidden = false;
        if (walkInItemInput) walkInItemInput.setAttribute('aria-expanded', 'true');
    }
}

function openWalkInItemDropdown() {
    if (!walkInItemDropdown || !walkInAvailableItems.length || walkInItemInput.disabled) return;
    walkInDropdownOpen = true;
    if (walkInDropdownHighlight === -1) {
        walkInDropdownHighlight = 0;
    }
    renderWalkInItemDropdown(walkInItemInput.value);
}

function closeWalkInItemDropdown() {
    walkInDropdownOpen = false;
    walkInDropdownHighlight = -1;
    if (walkInItemDropdown) {
        walkInItemDropdown.hidden = true;
        walkInItemDropdown.innerHTML = '';
    }
    if (walkInItemInput) walkInItemInput.setAttribute('aria-expanded', 'false');
}

function selectWalkInItemFromDropdown(name) {
    const match = walkInAvailableItems.find((item) => item.name === name);
    if (!match) return;
    walkInItemInput.value = match.name;
    closeWalkInItemDropdown();
    if (walkInItemQtyInput) walkInItemQtyInput.focus();
}

function setupWalkInItemPicker() {
    if (!walkInItemInput || !walkInItemDropdown) return;

    // Clicking the search bar drops the full list down (no more native datalist
    // up/down arrows — the whole list is visible at once).
    walkInItemInput.addEventListener('click', () => {
        openWalkInItemDropdown();
    });
    walkInItemInput.addEventListener('focus', () => {
        openWalkInItemDropdown();
    });
    walkInItemInput.addEventListener('input', () => {
        walkInDropdownHighlight = -1;
        walkInDropdownOpen = true;
        if (walkInItemInput.value.trim() !== '') {
            renderWalkInItemDropdown(walkInItemInput.value);
        } else {
            renderWalkInItemDropdown('');
        }
    });
    walkInItemInput.addEventListener('keydown', (event) => {
        const visibleOptions = Array.from(walkInItemDropdown ? walkInItemDropdown.querySelectorAll('.walkin-item-option') : []);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!walkInDropdownOpen) {
                openWalkInItemDropdown();
                return;
            }
            if (!visibleOptions.length) return;
            walkInDropdownHighlight = event.key === 'ArrowDown'
                ? (walkInDropdownHighlight + 1) % visibleOptions.length
                : (walkInDropdownHighlight - 1 + visibleOptions.length) % visibleOptions.length;
            renderWalkInItemDropdown(walkInItemInput.value);
            const active = walkInItemDropdown.querySelector('.walkin-item-option.active');
            if (active) active.scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'Enter' && walkInDropdownOpen && walkInDropdownHighlight >= 0 && visibleOptions.length) {
            event.preventDefault();
            const active = walkInItemDropdown.querySelector('.walkin-item-option.active');
            if (active && active.dataset.name) {
                selectWalkInItemFromDropdown(active.dataset.name);
            }
        } else if (event.key === 'Escape') {
            closeWalkInItemDropdown();
        }
    });

    walkInItemDropdown.addEventListener('mousedown', (event) => {
        // Keep the input's focus when clicking inside the dropdown.
        event.preventDefault();
        const option = event.target.closest('.walkin-item-option');
        if (option && option.dataset.name) {
            selectWalkInItemFromDropdown(option.dataset.name);
        }
    });

    // Close when clicking anywhere outside the picker.
    document.addEventListener('click', (event) => {
        if (walkInDropdownOpen && !event.target.closest('.walkin-item-picker')) {
            closeWalkInItemDropdown();
        }
    });
}

function addWalkInDraftItem() {
    if (!walkInItemInput || walkInItemInput.disabled) {
        setWalkInOrderMessage('No available inventory item for walk-in order.', true);
        return;
    }

    const itemName = (walkInItemInput.value || '').trim();
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
    const specialComponents = getSpecialFoodComponentsByName(itemName);

    if (existingIndex >= 0) {
        const existingDraftItem = walkInDraftItems[existingIndex];
        if (specialComponents.length) {
            if (!Array.isArray(existingDraftItem.baseComponents) || !existingDraftItem.baseComponents.length) {
                existingDraftItem.baseComponents = normalizeSpecialComponents(specialComponents);
            }
            if (!Array.isArray(existingDraftItem.components) || !existingDraftItem.components.length) {
                existingDraftItem.components = buildInitialCartComponents(specialComponents, currentDraftQty);
            }
            applyBaseComponentsDeltaToCartItem(existingDraftItem, qtyToAdd);
            existingDraftItem.componentsOpen = false;
            existingDraftItem.componentsMode = 'total';
        }
        walkInDraftItems[existingIndex].quantity += qtyToAdd;
    } else {
        const draftItem = {
            name: inventoryItem.name,
            price: Number(inventoryItem.price) || 0,
            quantity: qtyToAdd
        };

        if (specialComponents.length) {
            draftItem.baseComponents = normalizeSpecialComponents(specialComponents);
            draftItem.components = buildInitialCartComponents(specialComponents, qtyToAdd);
            draftItem.componentsMode = 'total';
            draftItem.componentsOpen = false;
        }

        walkInDraftItems.push(draftItem);
    }

    if (walkInItemQtyInput) {
        walkInItemQtyInput.value = '1';
    }

    if (qtyToAdd < quantity) {
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
    let qtyDelta = 0;

    if (direction === 'decrease') {
        if (currentQty <= 1) return;
        qtyDelta = -1;
        entry.quantity = currentQty - 1;
    } else {
        const available = getAvailablePendingStockForItem(entry.name);
        if (currentQty >= available) {
            setWalkInOrderMessage(`No more available stock for ${entry.name}.`, true);
            renderWalkInOrderBuilder();
            return;
        }
        qtyDelta = 1;
        entry.quantity = currentQty + 1;
    }

    applyBaseComponentsDeltaToCartItem(entry, qtyDelta);
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
            price: getCartItemUnitPrice(item),
            quantity: Number(item.quantity) || 0,
            components: getOrderComponents(item.components)
        })),
        total: Math.max(0, getWalkInDraftTotal()),
        paymentMethod: walkInPaymentMethodSelect ? walkInPaymentMethodSelect.value || 'Cash' : 'Cash',
        orderType: walkInOrderTypeSelect ? walkInOrderTypeSelect.value || 'Walk-in Dine In' : 'Walk-in Dine In',
        customerPhone: ''
    };

    const syncedOrder = await submitOrderToServer(order);
    if (!syncedOrder || syncedOrder.error) {
        setWalkInOrderMessage((syncedOrder && syncedOrder.error) || 'Unable to submit walk-in order. Please try again.', true);
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
let selectedCustomerName = '';
let selectedCustomerPhone = '';
let selectedCustomerEmail = '';
let selectedDeliveryAddress = '';
let cartAddOnDraftQuantities = {};
let cartAddOnSearchQuery = '';
let cartAddOnDataRefreshInFlight = null;

const SAKAYKO_ORDER_TYPE = 'SakayKo Rider Pick-up';

function isSakayKoOrderType(orderType) {
    return String(orderType || '').trim().toLowerCase() === SAKAYKO_ORDER_TYPE.toLowerCase();
}

function syncDeliveryAddressFieldVisibility() {
    if (!deliveryAddressSection || !deliveryAddressInput) return;
    const isSakayKo = isSakayKoOrderType(selectedOrderType);
    deliveryAddressSection.hidden = !isSakayKo;
    deliveryAddressSection.setAttribute('aria-hidden', isSakayKo ? 'false' : 'true');
    if (isSakayKo) {
        deliveryAddressInput.setAttribute('required', '');
    } else {
        deliveryAddressInput.removeAttribute('required');
        selectedDeliveryAddress = '';
        deliveryAddressInput.value = '';
    }
}

/**
 * Cash On Delivery only exists for SakayKo rider deliveries. When SakayKo is
 * the active order type, COD is revealed, auto-selected, and locked so the
 * customer cannot switch away from it. For any other order type COD is hidden
 * and the payment method falls back to Cash.
 */
function syncPaymentMethodOptions() {
    if (!paymentMethodOptions) return;
    const buttons = Array.from(paymentMethodOptions.querySelectorAll('.checkout-option-btn'));
    const isSakayKo = isSakayKoOrderType(selectedOrderType);

    if (codPaymentOption) {
        codPaymentOption.classList.toggle('hidden', !isSakayKo);
    }

    if (isSakayKo) {
        selectedPaymentMethod = 'Cash On Delivery';
        buttons.forEach((button) => { button.disabled = true; });
    } else {
        if (selectedPaymentMethod === 'Cash On Delivery') {
            selectedPaymentMethod = 'Cash';
        }
        buttons.forEach((button) => { button.disabled = false; });
    }

    selectCheckoutOption(paymentMethodOptions, 'payment', selectedPaymentMethod);
}

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
    // First pass: normal refresh pipeline
    await refreshCartAddOnData();

    if (getAddOnInventoryItems().length > 0) {
        return;
    }

    // Second pass: reverse order to handle slow custom-menu hydration timing.
    try {
        await initializeInventoryData(true);
        await loadCustomMenuData();
        await initializeInventoryData(true);
    } catch (error) {
        console.error('Secondary add on data refresh pass failed', error);
    }

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

    if (getAddOnInventoryItems().length > 0) {
        return;
    }

    // Final pass: one more full refresh after forced fetches.
    try {
        await loadCustomMenuData();
        await initializeInventoryData(true);
    } catch (error) {
        console.error('Final add on data refresh pass failed', error);
    }
}

function closeCartAddOnScreen() {
    if (!cartAddOnScreen) return;
    cartAddOnScreen.classList.add('hidden');
    cartAddOnScreen.setAttribute('aria-hidden', 'true');
    if (menuCartList) {
        menuCartList.classList.remove('hidden');
    }
    if (menuCartHeader) {
        menuCartHeader.classList.remove('hidden');
    }
    if (menuCartSummary) {
        menuCartSummary.classList.remove('hidden');
    }
    if (cartTitle) {
        cartTitle.classList.remove('hidden');
    }
    if (cartAddOnBtn) {
        cartAddOnBtn.classList.remove('hidden');
    }
    if (menuCartPanel) {
        menuCartPanel.classList.remove('cart-addon-active');
    }
    resetCartAddOnDraft();
    cartAddOnSearchQuery = '';
    if (cartAddOnSearchInput) {
        cartAddOnSearchInput.value = '';
    }
    if (cartAddOnMessage) {
        cartAddOnMessage.textContent = '';
    }
    // restore body scrolling
    try { document.body.classList.remove('modal-open'); } catch (e) { }
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
        // nothing additional to update for native scrollbars
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
        // nothing additional to update for native scrollbars
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
    // nothing additional to update for native scrollbars
}

async function openCartAddOnScreen() {
    if (!cartAddOnScreen) return;
    if (menuCartList) {
        menuCartList.classList.add('hidden');
    }
    if (menuCartHeader) {
        menuCartHeader.classList.add('hidden');
    }
    if (menuCartSummary) {
        menuCartSummary.classList.add('hidden');
    }
    if (cartTitle) {
        cartTitle.classList.add('hidden');
    }
    if (cartAddOnBtn) {
        cartAddOnBtn.classList.add('hidden');
    }
    if (menuCartPanel) {
        menuCartPanel.classList.add('cart-addon-active');
    }
    cartAddOnScreen.classList.remove('hidden');
    cartAddOnScreen.setAttribute('aria-hidden', 'false');

    if (cartAddOnList) {
        cartAddOnList.innerHTML = '<p class="menu-cart-empty">Loading add on items...</p>';
    }
    if (cartAddOnApplyBtn) {
        cartAddOnApplyBtn.disabled = true;
    }

    // prevent body scrolling while add-on screen is open
    try { document.body.classList.add('modal-open'); } catch (e) { }

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
    syncDeliveryAddressFieldVisibility();
    syncPaymentMethodOptions();
    renderCheckoutSummary();
    setMenuOverlayMenuVisibility(false);
    // hide the top menu tab while on checkout/payment
    try { if (menuNavLink) menuNavLink.style.display = 'none'; } catch (e) { }
}

function closeCheckoutScreen() {
    if (!orderCheckoutScreen) return;

    orderCheckoutScreen.classList.add('hidden');
    orderCheckoutScreen.setAttribute('aria-hidden', 'true');
    if (menuOverlay) {
        menuOverlay.classList.add('hidden');
        menuOverlay.setAttribute('aria-hidden', 'true');
    }
    if (menuCategoryScreen) {
        menuCategoryScreen.classList.add('hidden');
        menuCategoryScreen.setAttribute('aria-hidden', 'true');
    }
    closeCartAddOnScreen();
    openCartModal();
    suppressMenuOverlay = false;
    try { document.body.classList.remove('suppress-menu'); } catch (e) { }
    try { if (menuNavLink) menuNavLink.style.display = ''; } catch (e) { }
    if (menuOverlayCategories) {
        menuOverlayCategories.hidden = false;
        renderMenuOverlayCategories(currentMenuCategoryId || '');
    }
}

function closeCheckoutScreenCompletely() {
    if (!orderCheckoutScreen) return;

    orderCheckoutScreen.classList.add('hidden');
    orderCheckoutScreen.setAttribute('aria-hidden', 'true');
    if (menuOverlay) {
        menuOverlay.classList.add('hidden');
        menuOverlay.setAttribute('aria-hidden', 'true');
    }
    if (menuCategoryScreen) {
        menuCategoryScreen.classList.add('hidden');
        menuCategoryScreen.setAttribute('aria-hidden', 'true');
    }
    closeCartAddOnScreen();
    suppressMenuOverlay = false;
    try { document.body.classList.remove('suppress-menu'); } catch (e) { }
    try { if (menuNavLink) menuNavLink.style.display = ''; } catch (e) { }
    if (menuOverlayCategories) {
        menuOverlayCategories.hidden = false;
        renderMenuOverlayCategories(currentMenuCategoryId || '');
    }

    if (currentMenuCategoryId) {
        showMenuCategory(currentMenuCategoryId);
    } else if (menuOverlay) {
        openMenuOverlay();
    }
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
    // Collision-resistant order number: last 6 digits of the timestamp plus a
    // 2-digit random suffix, so concurrent orders from different devices never
    // produce the same number (a previous pure-random 6-digit format could).
    const timePart = Date.now().toString().slice(-6);
    const randomPart = String(Math.floor(Math.random() * 100)).padStart(2, '0');
    return timePart + randomPart;
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
    console.debug('openPaymentScreen: start', order);
    if (menuOverlay) {
        menuOverlay.classList.remove('hidden');
        menuOverlay.setAttribute('aria-hidden', 'false');
    }
    if (menuCategoryScreen) {
        menuCategoryScreen.classList.add('hidden');
        menuCategoryScreen.setAttribute('aria-hidden', 'true');
    }
    if (orderCheckoutScreen) {
        orderCheckoutScreen.classList.add('hidden');
        orderCheckoutScreen.setAttribute('aria-hidden', 'true');
    }
    // Ensure cart modal is closed when entering payment screen
    try { closeCartModal(); } catch (e) { }
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
    if (orderPaymentOrderType) {
        orderPaymentOrderType.textContent = order.orderType || 'Dine In';
    }
    if (orderPaymentCustomer) {
        orderPaymentCustomer.textContent = order.customerName || '—';
    }
    if (orderPaymentAddressRow && orderPaymentAddress) {
        const hasAddress = isSakayKoOrderType(order.orderType) && order.deliveryAddress;
        orderPaymentAddressRow.hidden = !hasAddress;
        orderPaymentAddress.textContent = hasAddress ? order.deliveryAddress : '';
    }

    if (selectedPaymentMethod === 'Cash' || selectedPaymentMethod === 'Cash On Delivery') {
        if (orderPaymentMessage) {
            if (selectedPaymentMethod === 'Cash On Delivery') {
                orderPaymentMessage.textContent = 'Cash On Delivery selected. Please prepare the exact amount for the SakayKo rider upon delivery.';
            } else {
                orderPaymentMessage.textContent = 'Cash payment selected. Your dishes will start to cook once they are already paid at the cashier.';
            }
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
    setMenuOverlayMenuVisibility(false);
    // hide the top menu tab while on payment screen and suppress menu overlay
    suppressMenuOverlay = true;
    try { document.body.classList.add('suppress-menu'); } catch (e) { }
    try { if (menuNavLink) menuNavLink.style.display = 'none'; } catch (e) { }
    console.debug('openPaymentScreen: done, suppressed menu overlay');
}

function closePaymentScreen() {
    if (!orderPaymentScreen) return;
    orderPaymentScreen.classList.add('hidden');
    orderPaymentScreen.setAttribute('aria-hidden', 'true');
    if (menuOverlay) {
        menuOverlay.classList.add('hidden');
        menuOverlay.setAttribute('aria-hidden', 'true');
    }
    if (menuCategoryScreen) {
        menuCategoryScreen.classList.add('hidden');
        menuCategoryScreen.setAttribute('aria-hidden', 'true');
    }
    closeCartAddOnScreen();
    // Keep cart closed after closing payment screen
    try { closeCartModal(); } catch (e) { }
    suppressMenuOverlay = false;
    try { document.body.classList.remove('suppress-menu'); } catch (e) { }
    if (menuOverlayCategories) {
        menuOverlayCategories.hidden = false;
        renderMenuOverlayCategories(currentMenuCategoryId || '');
    }
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

function setCheckoutFieldError(input, errorEl, message) {
    if (input) input.classList.toggle('is-invalid', Boolean(message));
    if (errorEl) errorEl.textContent = message || '';
}

function validateCheckoutPhone() {
    if (!customerPhoneInput) return true;
    const value = customerPhoneInput.value.trim();
    if (!value) {
        // Phone is optional — an empty field is always valid (mirrors email).
        setCheckoutFieldError(customerPhoneInput, customerPhoneError, '');
        return true;
    }
    // Strict Philippine mobile format: exactly 12 digits starting with 639.
    if (!/^639\d{9}$/.test(value)) {
        setCheckoutFieldError(customerPhoneInput, customerPhoneError, 'Enter a valid PH mobile number starting with 639 (12 digits, e.g. 639XXXXXXXXX).');
        return false;
    }
    setCheckoutFieldError(customerPhoneInput, customerPhoneError, '');
    return true;
}

function validateCheckoutEmail() {
    if (!customerEmailInput) return true;
    const value = customerEmailInput.value.trim();
    if (!value) {
        // Email is optional — an empty field is always valid.
        setCheckoutFieldError(customerEmailInput, customerEmailError, '');
        return true;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        setCheckoutFieldError(customerEmailInput, customerEmailError, 'Enter a valid email address (e.g. you@example.com).');
        return false;
    }
    setCheckoutFieldError(customerEmailInput, customerEmailError, '');
    return true;
}

function validateCheckoutContactFields() {
    const phoneOk = validateCheckoutPhone();
    const emailOk = validateCheckoutEmail();
    return phoneOk && emailOk;
}

async function confirmOrder() {
    if (!cartItems.length) return;
    // Close the cart modal if it is still open — but do NOT use closeCartModal(),
    // which cascades into closeCheckoutScreenCompletely() whenever the checkout
    // screen is visible, jumping the customer back to the menu tab instead of the
    // payment confirmation page. The checkout screen stays open until the order
    // is submitted and the payment screen takes over.
    closeCartAddOnScreen();
    if (cartModal && !cartModal.classList.contains('hidden')) {
        cartModal.classList.add('hidden');
        cartModal.setAttribute('aria-hidden', 'true');
    }
    console.debug('confirmOrder: started', { cartItemsLength: cartItems.length });
    const payableItems = getPayableCartItems();
    const payableTotal = payableItems.reduce((sum, item) => sum + getCartItemLineTotal(item), 0);

    if (!payableItems.length || payableTotal <= 0) {
        if (checkoutMessage) {
            checkoutMessage.textContent = 'Add at least one payable item before proceeding to payment.';
            checkoutMessage.style.color = '#b00020';
        } else if (menuOrderMessage) {
            menuOrderMessage.textContent = 'Add at least one payable item before proceeding to payment.';
        }
        return;
    }

    const setCheckoutMessage = (message) => {
        if (checkoutMessage) {
            checkoutMessage.textContent = message;
            checkoutMessage.style.color = '#b00020';
        } else if (menuOrderMessage) {
            menuOrderMessage.textContent = message;
        }
    };

    selectedCustomerName = customerNameInput ? customerNameInput.value.trim() : '';
    if (!selectedCustomerName) {
        if (customerNameInput) customerNameInput.focus();
        setCheckoutMessage('Please enter your full name to continue with payment.');
        return;
    }

    selectedCustomerPhone = customerPhoneInput ? customerPhoneInput.value.trim() : '';
    selectedCustomerEmail = customerEmailInput ? customerEmailInput.value.trim() : '';

    // Contact validation: the phone is optional but, if filled in, must be a
    // 12-digit 639 number; the email is optional and must be valid when filled.
    // Blocks submission until the highlighted fields are corrected or cleared.
    if (!validateCheckoutContactFields()) {
        setCheckoutMessage('Please fix the highlighted contact fields before continuing.');
        return;
    }

    selectedDeliveryAddress = isSakayKoOrderType(selectedOrderType) && deliveryAddressInput
        ? deliveryAddressInput.value.trim()
        : '';
    if (isSakayKoOrderType(selectedOrderType) && !selectedDeliveryAddress) {
        if (deliveryAddressInput) deliveryAddressInput.focus();
        setCheckoutMessage('Please enter the delivery address for the SakayKo rider pick-up.');
        return;
    }

    if (checkoutMessage) {
        checkoutMessage.textContent = '';
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
        orderType: selectedOrderType,
        customerName: selectedCustomerName,
        customerPhone: selectedCustomerPhone,
        customerEmail: selectedCustomerEmail,
        deliveryAddress: selectedDeliveryAddress
    };

    if (confirmOrderBtn) {
        confirmOrderBtn.disabled = true;
        confirmOrderBtn.textContent = 'Placing order…';
    }

    const syncedOrder = await submitOrderToServer(order);
    console.debug('confirmOrder: submitOrderToServer returned', syncedOrder);
    if (!syncedOrder || syncedOrder.error) {
        if (confirmOrderBtn) {
            confirmOrderBtn.disabled = false;
            confirmOrderBtn.textContent = 'Confirm Order';
        }
        const failureMessage = (syncedOrder && syncedOrder.error) || 'Unable to submit order to server. Please try again.';
        if (checkoutMessage) {
            checkoutMessage.textContent = failureMessage;
            checkoutMessage.style.color = '#b00020';
        } else if (menuOrderMessage) {
            menuOrderMessage.textContent = failureMessage;
        }
        return;
    }

    pendingOrders.unshift(syncedOrder);
    suppressMenuOverlay = false;
    try { document.body.classList.remove('suppress-menu'); } catch (e) { }
    registerCustomerOrder(syncedOrder.orderNumber);
    // Optimistically surface the floating status icon right after checkout.
    customerOrderStatuses.set(String(syncedOrder.orderNumber), {
        status: 'pending',
        prepMinutes: null,
        prepStartedAt: null,
        orderType: syncedOrder.orderType || ''
    });
    saveCustomerOrderTimerCache();
    renderOrderStatusFloat();
    savePendingOrders();
    renderPendingOrders();
    renderOrderNotifications();
    if (menuOrderMessage) {
        menuOrderMessage.textContent = 'Order received! Proceed with payment to complete transaction.';
    }
    console.debug('confirmOrder: opening payment screen for', syncedOrder.orderNumber);
    openPaymentScreen(syncedOrder);
}

function showMenuCategory(categoryId) {
    // Prevent background refreshes from forcing the menu overlay open
    // (also covers checkout/payment screens, not just suppressMenuOverlay)
    if (isUserInCheckoutOrPayment()) return;
    if (showMenuCategoryRecursing) return;
    showMenuCategoryRecursing = true;
    try {
    syncMenuPricesWithInventory();

    const resolvedCategoryId = normalizeMenuCategoryKey(categoryId) || String(categoryId || '').trim();
    const isSpecialsCategory = resolvedCategoryId === 'specials';
    const category = isSpecialsCategory ? { title: 'SPECIALS', items: specialFoods } : menuData[resolvedCategoryId];
    if (!category || !menuCategoryScreen || !menuItemsList || !menuCategories) return;

    currentMenuCategoryId = resolvedCategoryId;
    if (menuOverlayCategories) {
        menuOverlayCategories.hidden = false;
        renderMenuOverlayCategories(resolvedCategoryId);
    }
    menuItemsList.innerHTML = category.items.map((item) => {
        const isOutOfStock = isItemOutOfStock(item.name);
        const selectedQty = menuSelectionQuantities[item.name] || 0;
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
                    <button type="button" class="menu-item-qty-btn" data-action="decrease" data-name="${item.name}" data-price="${parsePrice(item.price)}" aria-label="Decrease ${item.name} quantity"${selectedQty <= 0 ? ' disabled' : ''}>−</button>
                    <span class="menu-item-qty">${selectedQty}</span>
                    <button type="button" class="menu-item-qty-btn" data-action="increase" data-name="${item.name}" data-price="${parsePrice(item.price)}" aria-label="Increase ${item.name} quantity"${availableStock <= 0 ? ' disabled' : ''}>+</button>
                </div>
                <span class="menu-item-confirmation" aria-live="polite"></span>
            </div>
        </article>
    `;
    }).join('');

    if (!category.items || !category.items.length) {
        menuItemsList.innerHTML = '<div class="menu-empty-message">No products available in this category.</div>';
    }

    menuCategories.hidden = false;
    menuCategoryScreen.classList.remove('hidden');
    menuCategoryScreen.setAttribute('aria-hidden', 'false');
    updateCartDisplay();
    } finally { showMenuCategoryRecursing = false; }
}

function renderMenuOverlayCategories(activeCategoryId = '') {
    if (!menuOverlayCategories) return;
    const categoryKeys = ['batchoy', 'silog', 'friedChicken', 'breakfast', 'drinks', 'specials', 'addons'];
    menuOverlayCategories.innerHTML = categoryKeys.map((categoryKey) => {
        const category = menuData[categoryKey];
        if (!category) return '';
        const isActive = categoryKey === activeCategoryId;
        return `
            <button type="button" class="menu-category-btn${isActive ? ' active' : ''}" data-category="${categoryKey}">
                ${category.title}
            </button>
        `;
    }).join('');
}

function setMenuOverlayMenuVisibility(visible) {
    if (menuOverlayHeader) {
        menuOverlayHeader.style.display = visible ? '' : 'none';
    }
    if (menuOverlayCategories) {
        menuOverlayCategories.style.display = visible ? '' : 'none';
    }
    if (menuOverlayActionsPanel) {
        menuOverlayActionsPanel.style.display = visible ? '' : 'none';
    }
}

function showMenuCategories() {
    if (!menuCategories || !menuCategoryScreen) return;
    menuCategories.hidden = false;
    if (menuOverlayCategories) {
        menuOverlayCategories.hidden = false;
        renderMenuOverlayCategories(currentMenuCategoryId || '');
    }
    menuCategoryScreen.classList.add('hidden');
    menuCategoryScreen.setAttribute('aria-hidden', 'true');
    setMenuOverlayMenuVisibility(true);
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
    try { if (menuNavLink) menuNavLink.style.display = ''; } catch (e) { }
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

    if (orderCheckoutScreen && !orderCheckoutScreen.classList.contains('hidden')) {
        closeCheckoutScreenCompletely();
        return;
    }

    if (orderPaymentScreen && !orderPaymentScreen.classList.contains('hidden')) {
        closePaymentScreen();
        return;
    }
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
        if (isUserInCheckoutOrPayment()) {
            closeCheckoutScreenCompletely();
        }
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

    // Close the full-screen mobile overlay when tapping its background
    topNav.addEventListener('click', (event) => {
        if (event.target === topNav) {
            topNav.classList.remove('open');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        }
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

        // Out-of-stock special foods are non-interactive: the card is disabled
        // visually (CSS) and must never open the product detail modal.
        if (isItemOutOfStock(item.name)) return;

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
        console.debug('menuCategories delegated click', button.dataset.category, event);
        const categoryId = button.dataset.category;
        openMenuOverlay();
        showMenuCategory(categoryId);
    });
}

// Attach direct listeners to each category button as a fallback
document.querySelectorAll('.menu-category-btn').forEach((btn) => {
    if (!btn) return;
    btn.addEventListener('click', (e) => {
        // if the user is in checkout/payment, ignore clicks
        console.debug('menu-category-btn direct click', btn.dataset.category, e);
        if (isUserInCheckoutOrPayment()) return;
        const categoryId = String(btn.dataset.category || '').trim();
        if (!categoryId) return;
        openMenuOverlay();
        showMenuCategory(categoryId);
    });
});

if (menuAddOnQuickBtn) {
    menuAddOnQuickBtn.addEventListener('click', () => {
        openMenuOverlay();
        showMenuCategory('addons');
    });
}

if (menuOverlayCategories) {
    menuOverlayCategories.addEventListener('click', (event) => {
        const button = event.target.closest('.menu-category-btn');
        if (!button) return;
        const categoryId = button.dataset.category;
        showMenuCategory(categoryId);
    });
}

if (menuCategoryScreen) {
    menuCategoryScreen.addEventListener('click', (event) => {
        const qtyButton = event.target.closest('.menu-item-qty-btn');
        if (!qtyButton) return;

        const card = qtyButton.closest('.menu-item-card');
        const name = qtyButton.dataset.name;
        const price = Number(qtyButton.dataset.price);
        const change = qtyButton.dataset.action === 'increase' ? 1 : -1;
        const currentQty = menuSelectionQuantities[name] || 0;
        const availableStock = getAvailableStockForItem(name);
        const nextQty = Math.max(0, currentQty + change);

        if (change > 0 && nextQty > availableStock) return;
        menuSelectionQuantities[name] = nextQty;
        if (menuSelectionQuantities[name] === 0) {
            delete menuSelectionQuantities[name];
        }

        const confirmation = card?.querySelector('.menu-item-confirmation');
        if (confirmation) {
            confirmation.textContent = nextQty > 0 ? `${nextQty} selected` : '';
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

if (menuAddToCartBtn) {
    menuAddToCartBtn.addEventListener('click', () => {
        if (!commitSelectedMenuQuantitiesToCart()) {
            return;
        }

        if (menuOrderMessage) {
            menuOrderMessage.textContent = 'Selected items added to cart.';
        }
        updateCartDisplay();
    });
}

if (menuPurchaseNowBtn) {
    menuPurchaseNowBtn.addEventListener('click', () => {
        const hasNewSelection = Object.values(menuSelectionQuantities).some((qty) => Number(qty) > 0);
        const committed = hasNewSelection ? commitSelectedMenuQuantitiesToCart() : false;

        if (!committed && !cartItems.length) {
            if (menuOrderMessage) {
                menuOrderMessage.textContent = 'Select item quantities first before purchasing.';
            }
            return;
        }

        closeMenuOverlay();
        if (currentMenuCategoryId) {
            showMenuCategory(currentMenuCategoryId);
        }
        openCartModal();
        openCheckoutScreen();
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

if (orderCheckoutExitBtn) {
    orderCheckoutExitBtn.addEventListener('click', closeCheckoutScreenCompletely);
}

if (confirmOrderBtn) {
    confirmOrderBtn.addEventListener('click', confirmOrder);
}

if (paymentConfirmationBackBtn) {
    paymentConfirmationBackBtn.addEventListener('click', closePaymentScreen);
}

if (orderPaymentCloseBtn) {
    orderPaymentCloseBtn.addEventListener('click', () => {
        // Reuse the central close path so UI state (suppressMenuOverlay, menu rendering)
        // is consistently reset when closing the payment screen.
        try { closePaymentScreen(); } catch (e) { /* ignore */ }
        clearCart();
        showPaymentSuccessMessage();
    });
}

if (paymentSuccessCloseBtn) {
    paymentSuccessCloseBtn.addEventListener('click', hidePaymentSuccessMessage);
}

if (customerNameInput) {
    // Only allow letters, spaces, hyphens, periods, and apostrophes — no digits.
    customerNameInput.addEventListener('input', () => {
        const sanitized = customerNameInput.value.replace(/[^A-Za-zÀ-ÿ' \-\.]/g, '');
        if (sanitized !== customerNameInput.value) {
            customerNameInput.value = sanitized;
        }
    });
}

if (customerPhoneInput) {
    // Block letters, symbols, and spaces dynamically — digits only, so the
    // field can only ever hold the numeric 639XXXXXXXXX format.
    customerPhoneInput.addEventListener('input', () => {
        const sanitized = customerPhoneInput.value.replace(/[^0-9]/g, '');
        if (sanitized !== customerPhoneInput.value) {
            customerPhoneInput.value = sanitized;
        }
        if (!sanitized) {
            setCheckoutFieldError(customerPhoneInput, customerPhoneError, '');
        } else if (sanitized.length === 12) {
            validateCheckoutPhone();
        } else {
            setCheckoutFieldError(customerPhoneInput, customerPhoneError, '');
        }
    });
    customerPhoneInput.addEventListener('blur', () => {
        if (customerPhoneInput.value.trim()) validateCheckoutPhone();
    });
}

if (customerEmailInput) {
    customerEmailInput.addEventListener('input', () => {
        if (customerEmailInput.value.trim()) {
            validateCheckoutEmail();
        } else {
            setCheckoutFieldError(customerEmailInput, customerEmailError, '');
        }
    });
}

if (paymentMethodOptions) {
    paymentMethodOptions.addEventListener('click', (event) => {
        const button = event.target.closest('.checkout-option-btn');
        if (!button) return;
        // Locked payment options (e.g. COD while SakayKo delivery is active)
        // cannot be deselected — ignore clicks on disabled buttons.
        if (button.disabled) return;
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
        syncDeliveryAddressFieldVisibility();
        syncPaymentMethodOptions();
    });
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
        } else if (href === '#inventory') {
            if (!canAccessInventory()) return;
            showDashboardSection(inventorySection);
            renderInventoryManagement();
        } else if (href === '#pending-orders') {
            if (!canManageOrders()) return;
            showDashboardSection(pendingOrdersSection);
            // Pending Orders is the primary order workflow; the walk-in builder
            // remains available through the tab switch.
            setOrdersTab('pending');
            renderWalkInOrderBuilder();
            void loadPendingOrdersFromServer();
            renderPendingOrders();
        } else if (href === '#sales') {
            showDashboardSection(salesSection);
            updateAnalyticsView();
        } else if (href === '#logs') {
            if (!canAccessLogs()) return;
            syncLogsDateFilterToToday();
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

const overviewToPendingLink = document.getElementById('overviewToPendingLink');
if (overviewToPendingLink) {
    overviewToPendingLink.addEventListener('click', () => {
        if (!canManageOrders()) return;
        showDashboardSection(pendingOrdersSection);
        setOrdersTab('pending');
        renderWalkInOrderBuilder();
        renderPendingOrders();
        void loadPendingOrdersFromServer();
    });
}

if (overviewOrderNotificationList) {
    overviewOrderNotificationList.addEventListener('click', async (event) => {
        // clicking the notification list marks notifications as seen
        unseenPendingCount = 0;
        updateOverviewBadge();

        const refundBtn = event.target.closest('.order-refund-btn');
        if (refundBtn) {
            if (!canManageOrders()) return;
            const orderId = Number(refundBtn.dataset.orderId || 0);
            if (orderId) {
                await refundCompletedOrder(orderId);
            }
            return;
        }

        // The overview feed is view-only: processing happens under Orders →
        // Pending Orders. Clicking the hint navigates staff there directly.
        const link = event.target.closest('.order-notif-go-link');
        if (!link) return;
        if (!canManageOrders()) return;

        showDashboardSection(pendingOrdersSection);
        setOrdersTab('pending');
        renderWalkInOrderBuilder();
        renderPendingOrders();

        const orderId = Number(link.dataset.orderId || 0);
        // Highlight the target card only after the server reload re-renders the
        // list, so the highlight is never wiped by the async refresh.
        loadPendingOrdersFromServer().then(() => {
            if (!orderId) return;
            const targetCard = Array.from(document.querySelectorAll('#pendingOrdersList .pending-order-card'))
                .find((card) => Number(card.dataset.orderId || card.dataset.orderIndex) === orderId);
            if (targetCard && typeof targetCard.scrollIntoView === 'function') {
                targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                targetCard.classList.add('is-highlighted');
                window.setTimeout(() => targetCard.classList.remove('is-highlighted'), 2200);
            }
        });
    });
}

if (pendingOrdersList) {
    pendingOrdersList.addEventListener('click', async (event) => {
        if (!canManageOrders()) return;
        const componentButton = event.target.closest('.pending-item-component-btn');
        if (componentButton) {
            const action = componentButton.dataset.action;
            const orderIndex = Number(componentButton.dataset.orderIndex);
            const itemId = Number(componentButton.dataset.itemId);
            const componentName = String(componentButton.dataset.componentName || '').trim();
            if (componentName) {
                await changePendingOrderItemComponentQuantity(orderIndex, itemId, componentName, action);
            }
            return;
        }

        const qtyButton = event.target.closest('.pending-item-qty-btn');
        if (qtyButton) {
            const action = qtyButton.dataset.action;
            const orderIndex = Number(qtyButton.dataset.orderIndex);
            const itemId = Number(qtyButton.dataset.itemId);
            await changePendingOrderItemQuantity(orderIndex, itemId, action);
            return;
        }

        const prepareBtn = event.target.closest('.prepare-order-btn');
        if (prepareBtn) {
            const index = Number(prepareBtn.dataset.orderIndex);
            const card = prepareBtn.closest('.pending-order-card');
            const input = card ? card.querySelector('.prep-minutes-input') : null;
            const minutes = input ? Number(input.value) || 15 : 15;
            await startOrderPreparation(index, minutes);
            return;
        }

        const button = event.target.closest('.order-complete-btn');
        if (button) {
            const index = Number(button.dataset.orderIndex);
            await markPendingOrderAsComplete(index, true);
            return;
        }

        const printBtn = event.target.closest('.order-print-btn');
        if (printBtn) {
            const index = Number(printBtn.dataset.orderIndex);
            printOrderReceipt(index);
            return;
        }

        const cancelBtn = event.target.closest('.order-cancel-btn');
        if (cancelBtn) {
            const index = Number(cancelBtn.dataset.orderIndex);
            await cancelPendingOrder(index);
        }
    });
}

if (walkInOrdersTabBtn) {
    walkInOrdersTabBtn.addEventListener('click', () => {
        setOrdersTab('walk-in');
        renderWalkInOrderBuilder();
    });
}

setupWalkInItemPicker();

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
        const componentButton = event.target.closest('.walkin-draft-component-btn');
        if (componentButton) {
            const index = Number(componentButton.dataset.index);
            const componentName = String(componentButton.dataset.componentName || '').trim();
            const change = componentButton.dataset.action === 'increase' ? 1 : -1;
            adjustWalkInDraftItemComponentQuantity(index, componentName, change);
            return;
        }

        const removeComponentButton = event.target.closest('.walkin-draft-component-remove-btn');
        if (removeComponentButton) {
            const index = Number(removeComponentButton.dataset.index);
            const componentName = String(removeComponentButton.dataset.componentName || '').trim();
            removeWalkInDraftItemComponent(index, componentName);
            return;
        }

        const toggleCustomizeButton = event.target.closest('.walkin-draft-customize-toggle-btn');
        if (toggleCustomizeButton) {
            const index = Number(toggleCustomizeButton.dataset.index);
            toggleWalkInDraftItemComponents(index);
            return;
        }

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

// Resolves once the initial dashboard data fetches have settled (loaded or
// failed) so the post-login loading overlay can be dismissed. Reassigned on
// every initOrders() call; never rejects (allSettled + timeout fallback).
let staffInitialDataReady = Promise.resolve();

function buildStaffInitialDataReady(existingPromises = {}) {
    const initialLoads = [
        existingPromises.pendingPromise || loadPendingOrdersFromServer(),
        existingPromises.completedPromise || loadCompletedOrdersFromServer(),
        existingPromises.reviewsPromise || loadReviewsFromServer(),
        existingPromises.inventoryPromise || initializeInventoryData(),
        existingPromises.customMenuPromise || loadCustomMenuData(),
        existingPromises.highlightsPromise || loadHighlightsFromServer(),
        existingPromises.csrfPromise || ensureCsrfToken(),
        ensureStaffServerSession()
    ];
    // A hard cap so the overlay can never hang the dashboard if a fetch stalls.
    return Promise.race([
        Promise.allSettled(initialLoads),
        new Promise((resolve) => setTimeout(resolve, 8000))
    ]);
}

function initOrders() {
    const csrfPromise = ensureCsrfToken();
    const highlightsPromise = loadHighlightsFromServer();
    loadCart();
    loadPendingOrders();
    loadIgnoredPendingOrders();
    loadCompletedOrders();
    initSalesExportModule();
    initProfitExportModule();
    syncAnalyticsMonthSelectorsToCurrentMonth();
    const customMenuPromise = loadCustomMenuData();
    const inventoryPromise = initializeInventoryData();
    recalculateSalesAnalytics();
    recalculateProfitAnalytics();
    renderSpecialFoods();
    updateCartDisplay();
    renderWalkInOrderBuilder();
    setOrdersTab('walk-in');
    renderPendingOrders();
    renderOrderNotifications();
    // NOTE: renderInventoryManagement() is called by initializeInventoryData()
    // when the async fetch completes — do NOT call it here with empty inventoryData.
    updateAnalyticsView();
    updateProfitView();
    renderOverviewAnalytics();
    renderInsights();
    updateLiveClock();
    setInterval(updateLiveClock, 1000);
    const pendingPromise = loadPendingOrdersFromServer();
    const completedPromise = loadCompletedOrdersFromServer();
    const reviewsPromise = loadReviewsFromServer();
    startReviewRefresh();
    loadStaffOrderTimerCache();
    startPendingOrdersCountdownTicker();

    // Reuse the promises already in flight instead of re-fetching.
    staffInitialDataReady = buildStaffInitialDataReady({
        csrfPromise,
        highlightsPromise,
        customMenuPromise,
        inventoryPromise,
        pendingPromise,
        completedPromise,
        reviewsPromise
    });

    if (isCustomerPage) {
        initializeOrderNotificationAudio();
        loadCustomerOrderTracking();
        loadCustomerOrderTimerCache();
        startCustomerInventoryRefresh();
        void initializeInventoryData(true);
        startCustomerOrderStatusPolling();
        void pollCustomerOrderStatus();
        startOrderStatusFloatTicker();
        renderOrderStatusFloat();
    }
}

document.addEventListener('visibilitychange', () => {
    if (isCustomerPage && !document.hidden) {
        void loadCustomMenuData();
        void initializeInventoryData(true);
        void loadHighlightsFromServer();
    }
});

window.addEventListener('focus', () => {
    if (isCustomerPage) {
        void loadCustomMenuData();
        void initializeInventoryData(true);
        void loadHighlightsFromServer();
    }

});

initOrders();
restoreStaffSession();
updateAccountManagementAccess();
// Silently re-establish the server-side staff session (30-day cookie) on page
// load so staff-only APIs keep working after browser restarts. Uses the stored
// credentials exactly like the existing client-side session restore.
if (isStaffPage) {
    void ensureStaffServerSession();
}

// Real-time order events via Server-Sent Events
let orderEventsSource = null;
let unseenPendingCount = 0;
function updateOverviewBadge() {
    const badge = document.getElementById('overviewOrderBadge');
    if (!badge) return;
    if (unseenPendingCount > 0) {
        badge.style.display = 'inline-flex';
        badge.textContent = String(unseenPendingCount > 99 ? '99+' : unseenPendingCount);
        // trigger pulse
        badge.classList.remove('notif-badge-pulse');
        // force reflow to restart animation
        void badge.offsetWidth;
        badge.classList.add('notif-badge-pulse');
    } else {
        badge.style.display = 'none';
        badge.classList.remove('notif-badge-pulse');
    }
}

function initOrderEvents() {
    if (!isStaffPage) {
        return;
    }
    if (typeof EventSource === 'undefined') {
        console.debug('EventSource not supported; falling back to polling');
        return;
    }

    try {
        if (orderEventsSource) {
            try { orderEventsSource.close(); } catch (e) {}
            orderEventsSource = null;
        }

        const url = getApiUrl('api/order_events.php');
        orderEventsSource = new EventSource(url, { withCredentials: true });

        let lastOrderSyncAt = 0;
        const ORDER_SYNC_DEBOUNCE_MS = 800;
        orderEventsSource.addEventListener('order_created', (ev) => {
            try {
                const now = Date.now();
                if (now - lastOrderSyncAt > ORDER_SYNC_DEBOUNCE_MS) {
                    lastOrderSyncAt = now;
                    // reload pending orders to get full order details and update notifications
                    void loadPendingOrdersFromServer();
                    // visual alert for new order
                    unseenPendingCount = (unseenPendingCount || 0) + 1;
                    updateOverviewBadge();
                    void fetchOverviewMetrics();
                } else {
                    // schedule a short delayed sync
                    window.setTimeout(() => void loadPendingOrdersFromServer(), ORDER_SYNC_DEBOUNCE_MS);
                }
            } catch (e) { console.debug('order_created handler error', e); }
        });

        orderEventsSource.addEventListener('order_completed', (ev) => {
            try {
                const now = Date.now();
                if (now - lastOrderSyncAt > ORDER_SYNC_DEBOUNCE_MS) {
                    lastOrderSyncAt = now;
                    void Promise.all([loadPendingOrdersFromServer(), loadCompletedOrdersFromServer()]);
                    void fetchOverviewMetrics();
                } else {
                    window.setTimeout(() => void Promise.all([loadPendingOrdersFromServer(), loadCompletedOrdersFromServer()]), ORDER_SYNC_DEBOUNCE_MS);
                }
            } catch (e) { console.debug('order_completed handler error', e); }
        });

        orderEventsSource.onerror = (err) => {
            console.debug('Order events SSE error', err);
            // EventSource will reconnect automatically; fallback if closed
            if (orderEventsSource && orderEventsSource.readyState === EventSource.CLOSED) {
                orderEventsSource.close();
                orderEventsSource = null;
                // fallback: ensure polling still runs
            }
        };
    } catch (error) {
        console.error('Unable to initialize order events', error);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initOrderEvents();
});

// Overview metrics: fetch counts for staff logins and completed orders
async function fetchOverviewMetrics() {
    try {
        // The count endpoints are gated by the server session; wait for the
        // page-load renewal so the numbers render on the first try.
        if (isStaffPage) {
            await ensureStaffServerSession();
        }

        // Lightweight summary endpoints: the overview KPI cards only need
        // counts and aggregates, so fetch those in parallel instead of
        // shipping the full pending (100 orders + items) and completed (500
        // orders + items) payloads over the wire on every 15s refresh.
        const utcOffset = new Date().getTimezoneOffset() * -1; // minutes east of UTC
        const [pendingRes, summaryRes] = await Promise.all([
            fetch(getApiUrl(`api/get_pending_orders.php?count=1&_=${Date.now()}`), { cache: 'no-store', credentials: 'same-origin' }),
            fetch(getApiUrl(`api/get_completed_orders.php?summary=1&utcOffset=${utcOffset}&_=${Date.now()}`), { cache: 'no-store', credentials: 'same-origin' })
        ]);

        // Pending orders count
        let pendingCount = 0;
        try {
            if (pendingRes.ok) {
                const p = await pendingRes.json().catch(() => ({}));
                pendingCount = Number(p.count ?? 0) || 0;
            }
        } catch (e) {
            console.debug('Unable to load pending orders count', e);
        }

        const pendingEl = document.getElementById('pendingOrdersCount');
        if (pendingEl) pendingEl.textContent = String(pendingCount);

        // Completed orders summary (counts by type, today's revenue, best seller)
        try {
            if (summaryRes.ok) {
                const payload = await summaryRes.json().catch(() => ({}));
                const summary = payload.summary || {};

                const total = Number(summary.total ?? 0) || 0;
                const walkin = Number(summary.walkin ?? 0) || 0;
                const online = Number(summary.online ?? 0) || 0;

                const totalEl = document.getElementById('ordersCompletedCount');
                const walkinEl = document.getElementById('walkinCompletedCount');
                const onlineEl = document.getElementById('onlineCompletedCount');

                if (totalEl) totalEl.textContent = String(total);
                if (walkinEl) walkinEl.textContent = String(walkin);
                if (onlineEl) onlineEl.textContent = String(online);

                renderDashboardKpis(summary);
            }
        } catch (e) {
            console.debug('Unable to load completed orders summary', e);
        }
    } catch (error) {
        console.error('fetchOverviewMetrics error', error);
    }
}

function renderDashboardKpis(summary) {
    const s = summary && typeof summary === 'object' ? summary : {};

    // Today's revenue — computed server-side using this browser's UTC offset
    const revenueEl = document.getElementById('todayRevenueCount');
    if (revenueEl) revenueEl.textContent = formatCurrency(Number(s.todayRevenue ?? 0) || 0);

    // Average prep time from completed orders that carried one
    const avgEl = document.getElementById('avgPrepCount');
    const avgPrep = Number(s.avgPrepMinutes ?? 0) || 0;
    if (avgEl) avgEl.textContent = avgPrep > 0 ? `${avgPrep.toFixed(1)} min` : '—';

    // Low-stock count from current inventory (any item with 20 units or less)
    let lowStockCount = 0;
    if (Array.isArray(inventoryData)) {
        inventoryData.forEach((item) => {
            const stock = Number(item.stock) || 0;
            if (stock <= 20) lowStockCount += 1;
        });
    }
    const lowEl = document.getElementById('lowStockCount');
    if (lowEl) lowEl.textContent = String(lowStockCount);

    // Best seller by total units sold (computed server-side)
    const bestEl = document.getElementById('bestSellerCount');
    if (bestEl) {
        const best = s.bestSeller;
        bestEl.textContent = best && best.name ? `${best.name} (${Number(best.qty) || 0})` : '—';
    }
}

// ============================================================
// Data Retention banner (admin only)
// ============================================================
const retentionBanner = document.getElementById('retentionBanner');
const retentionBannerText = document.getElementById('retentionBannerText');
const retentionExportBtn = document.getElementById('retentionExportBtn');
const retentionClearBtn = document.getElementById('retentionClearBtn');
const retentionBannerMessage = document.getElementById('retentionBannerMessage');
let retentionPendingBatches = [];
let retentionSelectedBatch = null;

function setRetentionMessage(text, isError = false) {
    if (!retentionBannerMessage) return;
    retentionBannerMessage.textContent = text;
    retentionBannerMessage.style.color = isError ? '#dc2626' : '';
}

const RETENTION_TYPE_LABELS = {
    logs: 'System Logs',
    login_history: 'Staff Login History',
    orders: 'Sales & Order History (6-month retention)'
};

function retentionTypeLabel(type) {
    return RETENTION_TYPE_LABELS[type] || String(type || '');
}

function formatRetentionDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

async function loadRetentionBatches() {
    if (!isStaffPage || !canManageAccounts()) {
        if (retentionBanner) retentionBanner.hidden = true;
        return;
    }
    if (!retentionBanner) return;

    try {
        await ensureStaffServerSession();
        const response = await fetch(getApiUrl(`api/get_retention_batches.php?_=${Date.now()}`), {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        if (!response.ok) {
            retentionBanner.hidden = true;
            return;
        }
        const payload = await response.json().catch(() => ({}));
        const batches = Array.isArray(payload.batches) ? payload.batches : [];

        // Only surface batches that still have something to do (pending or
        // exported but not yet cleared).
        retentionPendingBatches = batches.filter((batch) => {
            const status = String(batch.status || 'pending');
            return status !== 'cleared';
        });

        if (!retentionPendingBatches.length) {
            retentionBanner.hidden = true;
            return;
        }

        const latest = retentionPendingBatches[0];
        retentionSelectedBatch = latest;
        retentionBannerText.textContent =
            `${retentionTypeLabel(latest.batch_type)} from ${formatRetentionDate(latest.period_start)} to `
            + `${formatRetentionDate(latest.period_end)} (${latest.record_count} records) is ready to archive. `
            + `Export a copy to Excel, then clear the records from the database to free storage.`;
        retentionBanner.hidden = false;
    } catch (error) {
        console.debug('Unable to load retention batches', error);
        retentionBanner.hidden = true;
    }
}

function downloadRetentionCsv(batch, headers, rows) {
    const csvRows = rows.map((row) => headers.map((header) => {
        const value = row && Object.prototype.hasOwnProperty.call(row, header) ? row[header] : '';
        const text = String(value === null || value === undefined ? '' : value);
        if (/[,"\n]/.test(text)) {
            return `"${text.replace(/"/g, '""')}"`;
        }
        return text;
    }));
    const csv = '\uFEFF' + [headers].concat(csvRows)
        .map((line) => line.join(',')).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `MOTASTE-${batch.batch_type}-${batch.period_label}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

async function exportRetentionBatch() {
    if (!retentionSelectedBatch) return;
    setRetentionMessage('Exporting...');
    if (retentionExportBtn) retentionExportBtn.disabled = true;
    try {
        const response = await fetch(getApiUrl(`api/export_retention_batch.php?id=${retentionSelectedBatch.id}&_=${Date.now()}`), {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const payload = await response.json().catch(() => ({}));
        if (!payload.success) {
            throw new Error(payload.error || 'Export failed');
        }

        downloadRetentionCsv(
            payload.batch || retentionSelectedBatch,
            Array.isArray(payload.headers) ? payload.headers : [],
            Array.isArray(payload.rows) ? payload.rows : []
        );
        setRetentionMessage(`Exported ${payload.rows ? payload.rows.length : 0} record(s). You can now clear them from the database.`);
        void loadRetentionBatches();
    } catch (error) {
        setRetentionMessage(`Export failed: ${error.message || 'Unexpected error'}`, true);
    } finally {
        if (retentionExportBtn) retentionExportBtn.disabled = false;
    }
}

async function clearRetentionBatch() {
    if (!retentionSelectedBatch) return;
    const typeLabel = retentionTypeLabel(retentionSelectedBatch.batch_type);
    const confirmed = await showStaffConfirm(
        `Permanently delete ${retentionSelectedBatch.record_count} archived ${typeLabel} record(s) from the database? `
        + 'This cannot be undone — export first if you need a copy.',
        { title: 'Clear archived records?', confirmLabel: 'Delete Records' }
    );
    if (!confirmed) return;

    setRetentionMessage('Clearing records...');
    if (retentionClearBtn) retentionClearBtn.disabled = true;
    try {
        const headers = await withCsrfHeaders({ 'Content-Type': 'application/json' });
        const response = await fetch(getApiUrl('api/clear_retention_batch.php'), {
            method: 'POST',
            headers,
            body: JSON.stringify({
                batchId: retentionSelectedBatch.id,
                confirmed: true
            })
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || `HTTP ${response.status}`);
        }
        setRetentionMessage(`Cleared ${payload.deleted || 0} record(s) from the database.`);
        void loadRetentionBatches();
    } catch (error) {
        setRetentionMessage(`Clear failed: ${error.message || 'Unexpected error'}`, true);
    } finally {
        if (retentionClearBtn) retentionClearBtn.disabled = false;
    }
}

if (retentionExportBtn) {
    retentionExportBtn.addEventListener('click', () => void exportRetentionBatch());
}
if (retentionClearBtn) {
    retentionClearBtn.addEventListener('click', () => void clearRetentionBatch());
}

// Initial fetch + periodic refresh
document.addEventListener('DOMContentLoaded', () => {
    void fetchOverviewMetrics();
    void loadRetentionBatches();
    setInterval(() => void fetchOverviewMetrics(), 15000);
    setInterval(() => void loadRetentionBatches(), 300000);
});// ============================================================
// PWA service worker registration
// ============================================================
if ('serviceWorker' in navigator && (isCustomerPage || window.location.pathname === '/')) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch((error) => {
            console.warn('Service worker registration failed', error);
        });
    });
}

// ============================================================
// Bilingual toggle (English / Filipino)
// ============================================================
const LANG_STORAGE_KEY = 'motaste_lang';
const langToggleBtn = document.getElementById('langToggleBtn');

const LANGUAGE_STRINGS = {
    en: {
        home: 'Home',
        menu: 'Menu',
        about: 'About Us',
        contact: 'Contact',
        orderNow: 'Order Now',
        addToCart: 'Add to cart',
        purchaseNow: 'Purchase now',
        checkout: 'Checkout',
        confirmOrder: 'Confirm Order',
        fullName: 'Full Name',
        paymentMethod: 'Payment Method',
        orderType: 'Order Type',
        total: 'Total'
    },
    fil: {
        home: 'Tahanan',
        menu: 'Menu',
        about: 'Tungkol Sa Amin',
        contact: 'Kontak',
        orderNow: 'Mag-Order Na',
        addToCart: 'Idagdag sa cart',
        purchaseNow: 'Bilhin na',
        checkout: 'Checkout',
        confirmOrder: 'Kumpirmahin ang Order',
        fullName: 'Buong Pangalan',
        paymentMethod: 'Paraan ng Pagbabayad',
        orderType: 'Uri ng Order',
        total: 'Kabuuan'
    }
};

function applySiteLanguage(lang) {
    const strings = LANGUAGE_STRINGS[lang] || LANGUAGE_STRINGS.en;
    document.documentElement.lang = lang === 'fil' ? 'fil-PH' : 'en';

    const navLinks = document.querySelectorAll('.top-nav a');
    if (navLinks.length >= 3) {
        navLinks[0].textContent = strings.home;
        navLinks[1].textContent = strings.menu;
        navLinks[2].textContent = strings.about;
    }

    const langBtn = document.getElementById('langToggleBtn');
    if (langBtn) langBtn.textContent = lang === 'fil' ? 'FIL' : 'EN';

    try { localStorage.setItem(LANG_STORAGE_KEY, lang); } catch (e) { }
}

if (langToggleBtn) {
    let currentLang = 'en';
    try {
        const saved = localStorage.getItem(LANG_STORAGE_KEY);
        if (saved === 'fil') currentLang = 'fil';
    } catch (e) { }
    applySiteLanguage(currentLang);

    langToggleBtn.addEventListener('click', () => {
        currentLang = currentLang === 'fil' ? 'en' : 'fil';
        applySiteLanguage(currentLang);
    });
}

// ============================================================
// Home page polish — sticky header scrolled state + footer year
// ============================================================
(function homePagePolish() {
    // Header: strengthen background once the page is scrolled
    const siteHeader = document.getElementById('siteHeader');
    if (siteHeader) {
        const updateHeaderState = () => {
            siteHeader.classList.toggle('scrolled', window.scrollY > 24);
        };
        updateHeaderState();
        window.addEventListener('scroll', updateHeaderState, { passive: true });
    }

    // Footer: keep the copyright year current
    const footerYearEl = document.getElementById('footerYear');
    if (footerYearEl) {
        footerYearEl.textContent = String(new Date().getFullYear());
    }

    // Footer social links are placeholders — don't let the '#' jump the page
    document.querySelectorAll('.footer-social a').forEach((link) => {
        link.addEventListener('click', (event) => event.preventDefault());
    });
})();
