<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Admin Dashboard' }} · MOTASTE</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('admin.css') }}">
</head>
<body class="admin-dash-body" data-staff-role="{{ $staff['role'] ?? '' }}" data-staff-email="{{ $staff['email'] ?? '' }}" data-staff-name="{{ $staff['name'] ?? '' }}">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="login-monogram">MT<span class="login-dot"></span></span>
            <span class="sidebar-wordmark">Motaste Admin</span>
        </div>

        <nav class="sidebar-nav" id="sidebarNav">
            <a href="#overview" class="nav-item is-active" data-tab="overview">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
                Overview
            </a>
            <a href="#orders" class="nav-item" data-tab="orders">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2l-2 4v16h16V6l-2-4H6zM6 6h12M9 10h6v10H9z"/></svg>
                Orders
                <span class="nav-badge" id="navOrderBadge" hidden>0</span>
            </a>
            <a href="#inventory" class="nav-item" data-tab="inventory">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8zM3 8l9 5 9-5M12 13v8"/></svg>
                Inventory
            </a>
            <a href="#staff" class="nav-item" data-tab="staff" data-admin-only>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Staff
            </a>
            <a href="#reviews" class="nav-item" data-tab="reviews">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                Reviews
            </a>
            <a href="#logs" class="nav-item" data-tab="logs">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zM14 2v6h6M9 13h6M9 17h6"/></svg>
                Logs
            </a>
            <a href="#menu" class="nav-item" data-tab="menu" data-admin-only>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                Menu
            </a>
            <a href="#devices" class="nav-item" data-tab="devices" data-admin-only>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
                Devices
            </a>
            <a href="#credentials" class="nav-item" data-tab="credentials" data-admin-only>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Credentials
            </a>
        </nav>

        <div class="sidebar-user">
            <div class="sidebar-user-avatar">{{ strtoupper(substr($staff['name'] ?? 'S', 0, 1)) }}</div>
            <div class="sidebar-user-meta">
                <strong id="sideUserName">{{ $staff['name'] ?? 'Staff' }}</strong>
                <span id="sideUserRole">{{ $staff['role'] ?? '' }}</span>
            </div>
            <button type="button" class="logout-icon" id="logoutBtn" title="Logout" aria-label="Logout">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            </button>
        </div>
    </aside>

    <main class="dash-main" id="dashMain">
        <!-- OVERVIEW -->
        <section class="panel" id="tab-overview">
            <header class="panel-head">
                <div>
                    <h1>Overview</h1>
                    <p>Live snapshot of the kitchen and floor.</p>
                </div>
                <span class="live-pill"><span class="live-dot"></span> Live</span>
            </header>

            <div class="metric-row">
                <div class="metric-card metric-red">
                    <span class="metric-label">Pending</span>
                    <strong id="metricPending">0</strong>
                    <small>awaiting kitchen</small>
                </div>
                <div class="metric-card metric-amber">
                    <span class="metric-label">Preparing</span>
                    <strong id="metricPreparing">0</strong>
                    <small>in the kitchen</small>
                </div>
                <div class="metric-card metric-blue">
                    <span class="metric-label">Ready</span>
                    <strong id="metricReady">0</strong>
                    <small>awaiting pickup</small>
                </div>
                <div class="metric-card metric-green">
                    <span class="metric-label">Unpaid</span>
                    <strong id="metricUnpaid">0</strong>
                    <small>needs payment</small>
                </div>
            </div>

            <div class="overview-grid">
                <div class="card overview-orders-card">
                    <h3>Latest Orders</h3>
                    <div id="overviewOrdersList" class="overview-orders-list"></div>
                </div>
                <div class="card overview-lowstock-card">
                    <h3>Low Stock</h3>
                    <div id="overviewLowStockList" class="overview-lowstock-list"></div>
                </div>
            </div>
        </section>

        <!-- ORDERS -->
        <section class="panel" id="tab-orders" hidden>
            <header class="panel-head">
                <div>
                    <h1>Orders</h1>
                    <p>Drive the order lifecycle: prepare, mark ready, complete, collect payment.</p>
                </div>
            </header>

            <div class="orders-status-bar">
                <button type="button" class="status-filter is-active" data-status="active">Active</button>
                <button type="button" class="status-filter" data-status="completed">Completed</button>
            </div>

            <div id="ordersList" class="orders-list"></div>
            <p class="empty-state" id="ordersEmpty" hidden>No orders to show.</p>

            <!-- order item edit modal -->
            <div class="modal-overlay" id="orderModal" hidden>
                <div class="modal-card order-modal-card">
                    <header class="modal-head">
                        <h3 id="orderModalTitle">Edit Order</h3>
                        <button type="button" class="modal-close" data-close-modal="orderModal">×</button>
                    </header>
                    <div class="order-modal-body" id="orderModalBody"></div>
                </div>
            </div>
        </section>

        <!-- INVENTORY -->
        <section class="panel" id="tab-inventory" hidden>
            <header class="panel-head">
                <div>
                    <h1>Inventory</h1>
                    <p>Manage products, stock levels and special food images.</p>
                </div>
                <button type="button" class="btn-primary" id="inventoryAddBtn">+ Add Product</button>
            </header>

            <div class="toolbar-row">
                <input type="search" id="inventorySearch" class="search-input" placeholder="Search products…">
                <div class="chip-row" id="inventoryCategoryChips"></div>
            </div>

            <div id="inventoryGrid" class="inventory-grid"></div>
            <p class="empty-state" id="inventoryEmpty" hidden>No inventory items found.</p>

            <!-- inventory edit modal -->
            <div class="modal-overlay" id="inventoryModal" hidden>
                <div class="modal-card inventory-modal-card">
                    <header class="modal-head">
                        <h3 id="inventoryModalTitle">Add Product</h3>
                        <button type="button" class="modal-close" data-close-modal="inventoryModal">×</button>
                    </header>
                    <form id="inventoryForm" class="form-grid">
                        <input type="hidden" id="invPreviousName">
                        <div class="field">
                            <label for="invName">Name</label>
                            <input type="text" id="invName" required>
                        </div>
                        <div class="field">
                            <label for="invCategory">Category</label>
                            <select id="invCategory">
                                <option value="specials">Specials</option>
                                <option value="batchoy">Batchoy</option>
                                <option value="silog">Silog</option>
                                <option value="friedChicken">Fried Chicken</option>
                                <option value="breakfast">Breakfast</option>
                                <option value="drinks">Drinks</option>
                                <option value="addons">Add On</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="invPrice">Price (₱)</label>
                            <input type="number" id="invPrice" min="0" step="0.01" required>
                        </div>
                        <div class="field">
                            <label for="invStock">Stock</label>
                            <input type="number" id="invStock" min="0" step="1" required>
                        </div>
                        <div class="field field-full">
                            <label for="invDescription">Description</label>
                            <textarea id="invDescription" rows="3"></textarea>
                        </div>
                        <div class="field">
                            <label for="invStatus">Status</label>
                            <select id="invStatus">
                                <option value="In stock">In stock</option>
                                <option value="Low stock">Low stock</option>
                                <option value="Out of stock">Out of stock</option>
                            </select>
                        </div>
                        <div class="field" id="invImageField" hidden>
                            <label for="invImage">Special Food Image</label>
                            <input type="file" id="invImage" accept="image/*">
                        </div>
                        <div class="form-actions field-full">
                            <button type="submit" class="btn-primary" id="invSaveBtn">Save Product</button>
                            <button type="button" class="btn-ghost" data-close-modal="inventoryModal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- STAFF -->
        <section class="panel" id="tab-staff" hidden>
            <header class="panel-head">
                <div>
                    <h1>Staff</h1>
                    <p>Accounts and invite flow for cashiers and inventory managers.</p>
                </div>
                <button type="button" class="btn-primary" id="staffInviteBtn">+ Invite Staff</button>
            </header>

            <div id="staffList" class="staff-list"></div>

            <div class="modal-overlay" id="staffModal" hidden>
                <div class="modal-card">
                    <header class="modal-head">
                        <h3>Invite Staff</h3>
                        <button type="button" class="modal-close" data-close-modal="staffModal">×</button>
                    </header>
                    <form id="staffForm" class="form-grid">
                        <div class="field field-full">
                            <label for="staffName">Full name</label>
                            <input type="text" id="staffName" required>
                        </div>
                        <div class="field">
                            <label for="staffRole">Role</label>
                            <select id="staffRole">
                                <option value="Cashier">Cashier</option>
                                <option value="Inventory Manager">Inventory Manager</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="staffEmail">Gmail address</label>
                            <input type="email" id="staffEmail" placeholder="name@gmail.com" required>
                        </div>
                        <div class="form-actions field-full">
                            <button type="submit" class="btn-primary" id="staffInviteSaveBtn">Send Invite</button>
                            <button type="button" class="btn-ghost" data-close-modal="staffModal">Cancel</button>
                        </div>
                        <p class="form-message field-full" id="staffFormMessage"></p>
                    </form>
                </div>
            </div>
        </section>

        <!-- REVIEWS -->
        <section class="panel" id="tab-reviews" hidden>
            <header class="panel-head">
                <div>
                    <h1>Reviews</h1>
                    <p>Moderate customer feedback. Deleting blocks that reviewer for the day.</p>
                </div>
            </header>
            <div class="toolbar-row">
                <div class="chip-row" id="reviewRatingChips">
                    <button type="button" class="chip is-active" data-rating="0">All</button>
                    <button type="button" class="chip" data-rating="5">5 ★</button>
                    <button type="button" class="chip" data-rating="4">4 ★</button>
                    <button type="button" class="chip" data-rating="3">3 ★</button>
                    <button type="button" class="chip" data-rating="2">2 ★</button>
                    <button type="button" class="chip" data-rating="1">1 ★</button>
                </div>
            </div>
            <div id="reviewsList" class="reviews-list"></div>
            <p class="empty-state" id="reviewsEmpty" hidden>No reviews to show.</p>
        </section>

        <!-- LOGS -->
        <section class="panel" id="tab-logs" hidden>
            <header class="panel-head">
                <div>
                    <h1>Logs</h1>
                    <p>Activity across orders and reviews.</p>
                </div>
            </header>
            <div class="toolbar-row">
                <select id="logCategoryFilter" class="search-input log-filter-select">
                    <option value="all">All activity</option>
                    <option value="orders">Orders</option>
                    <option value="stock">Stock</option>
                    <option value="accounts">Accounts</option>
                    <option value="reviews">Reviews</option>
                </select>
            </div>
            <div id="logsList" class="logs-list"></div>
            <p class="empty-state" id="logsEmpty" hidden>No activity logged yet.</p>
        </section>

        <!-- MENU -->
        <section class="panel" id="tab-menu" hidden>
            <header class="panel-head">
                <div>
                    <h1>Menu</h1>
                    <p>Special foods, highlights and menu categories. Changes publish instantly.</p>
                </div>
            </header>

            <div class="card">
                <h3>Special Foods</h3>
                <div id="specialFoodsList" class="special-foods-list"></div>
                <button type="button" class="btn-ghost" id="specialFoodAddBtn">+ Add Special Food</button>
            </div>

            <div class="card">
                <h3>Highlights Slideshow</h3>
                <p class="card-hint">Maximum 15 images. Upload then save.</p>
                <div id="highlightsList" class="highlights-grid"></div>
                <div class="toolbar-row">
                    <input type="file" id="highlightsInput" accept="image/*" multiple>
                    <button type="button" class="btn-primary" id="highlightsSaveBtn">Save Highlights</button>
                </div>
                <p class="form-message" id="highlightsMessage"></p>
            </div>

            <div class="card">
                <h3>Menu Categories</h3>
                <div id="menuCategoriesList" class="menu-categories-list"></div>
                <button type="button" class="btn-ghost" id="menuItemAddBtn">+ Add Menu Item</button>
                <div class="toolbar-row">
                    <button type="button" class="btn-primary" id="menuSaveBtn">Save Menu</button>
                    <p class="form-message" id="menuMessage"></p>
                </div>
            </div>
        </section>

        <!-- DEVICES -->
        <section class="panel" id="tab-devices" hidden>
            <header class="panel-head">
                <div>
                    <h1>Trusted Devices</h1>
                    <p>Devices that can sign in without a verification code.</p>
                </div>
            </header>
            <div id="devicesList" class="devices-list"></div>
            <p class="empty-state" id="devicesEmpty" hidden>No trusted devices.</p>
        </section>

        <!-- CREDENTIALS -->
        <section class="panel" id="tab-credentials" hidden>
            <header class="panel-head">
                <div>
                    <h1>Credentials</h1>
                    <p>Change the admin email or password. Changes require an emailed verification code.</p>
                </div>
            </header>

            <div class="card">
                <h3>Change admin email or password</h3>
                <form id="credentialsForm" class="form-grid">
                    <div class="field">
                        <label for="credCurrentEmail">Current admin email</label>
                        <input type="email" id="credCurrentEmail" readonly>
                    </div>
                    <div class="field">
                        <label for="credCurrentPassword">Current password</label>
                        <input type="password" id="credCurrentPassword" autocomplete="current-password" required>
                    </div>
                    <div class="field">
                        <label for="credNewEmail">New email (optional)</label>
                        <input type="email" id="credNewEmail" placeholder="new@gmail.com">
                    </div>
                    <div class="field">
                        <label for="credNewPassword">New password (min 8 chars)</label>
                        <input type="password" id="credNewPassword" minlength="8" autocomplete="new-password">
                    </div>
                    <div class="form-actions field-full">
                        <button type="button" class="btn-primary" id="credRequestBtn">Send Verification Code</button>
                    </div>
                    <div class="field field-full" id="credCodeField" hidden>
                        <label for="credCode">Verification code</label>
                        <input type="text" id="credCode" inputmode="numeric" maxlength="8" autocomplete="one-time-code">
                    </div>
                    <div class="form-actions field-full" id="credConfirmWrap" hidden>
                        <button type="submit" class="btn-primary">Verify &amp; Apply</button>
                    </div>
                    <p class="form-message field-full" id="credMessage"></p>
                </form>
            </div>
        </section>
    </main>

    <!-- toast container -->
    <div class="toast-wrap" id="toastWrap" aria-live="polite"></div>

    <script src="{{ asset('admin.js') }}"></script>
</body>
</html>
