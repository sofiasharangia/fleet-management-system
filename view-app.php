<?php
declare(strict_types=1);
?>
<div class="avtoparki" id="avtoparki-root">

    <!-- Authentication -->
    <div class="av-login" id="av-login" style="display:none;">
        <div class="av-card av-login-card">
            <div class="av-login-mark">Fleet Manager</div><h2>Sign in</h2>
            <p class="av-hint av-login-copy">Enter your username and password.</p>
            <div class="av-field">
                <label for="av-login-user">User</label>
                <input id="av-login-user" autocomplete="username">
            </div>
            <div class="av-field">
                <label for="av-login-pass">Password</label>
                <input id="av-login-pass" type="password" autocomplete="current-password" data-lpignore="true" data-1p-ignore>
            </div>
            <div class="av-error" id="av-login-error"></div>
            <button class="av-btn av-btn-fuel" id="av-login-btn">Sign in</button>
        </div>
    </div>

    <!-- Application -->
    <div class="av-app" id="av-app" style="display:none;">

        <div class="av-masthead av-masthead-main">
            <div class="av-brand-block">
                <div class="av-brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
                <div>
                    <h1>Fleet Manager</h1>
                    <div class="av-sub">Fleet and trip management</div>
                </div>
            </div>
            <div class="av-userbox av-userbox-main">
                <span class="av-who av-user-chip" id="av-who"></span>
                <button class="av-header-action" onclick="AvtoparkiApp.openSettings()" title="Settings" aria-label="Settings">⚙</button>
                <button class="av-header-action av-header-logout" onclick="AvtoparkiApp.logout()" title="Log out" aria-label="Log out">↪</button>
            </div>
        </div>


        <div class="av-tabs">
            <button class="av-tab active" data-tab="trips">Trips</button>
            <button class="av-tab" data-tab="lookup">Search</button>
            <button class="av-tab" id="av-routes-tab" data-tab="routes" hidden>Routes</button>
            <button class="av-tab" id="av-vehicles-tab" data-tab="vehicles" hidden>Vehicles</button>
            <button class="av-tab av-manage-tab" type="button" id="av-manage-tab" data-tab="users" hidden style="display:none;"><span class="av-manage-label">Users</span><span class="av-tab-notification" id="av-tab-request-count" style="display:none;">0</span></button>
        </div>

        <!-- TRIPS -->
        <div class="av-panel active" id="av-panel-trips">
            <div class="av-card av-access-card" id="av-trip-access-card" style="display:none;">
                <h2>Permission to add trips</h2>
                <p class="av-hint" id="av-trip-access-text">Request access from an administrator to add trips.</p>
                <button class="av-btn" id="av-request-trip-access">Request access</button>
            </div>
            <div class="av-card" id="av-trip-form-card">
                <h2 id="av-trip-title">Add a new trip</h2>
                <div class="av-grid">
                    <div class="av-field av-rel">
                        <label>Vehicle <span class="av-required">*</span></label>
                        <input id="t-vehicle-search" required autocomplete="off" placeholder="Plate, make, or model...">
                        <div id="t-vehicle-results" class="av-dropdown"></div>
                    </div>
                    <div class="av-field av-rel">
                        <label>Route <span class="av-required">*</span></label>
                        <input id="t-route-search" required autocomplete="off" placeholder="Type to search routes...">
                        <div id="t-route-results" class="av-dropdown"></div>
                    </div>
                    <div class="av-field"><label>Date <span class="av-required">*</span></label><input id="t-date" type="date" required></div>
                </div>

                <div class="av-compute">
                    <div class="av-compute-item route"><div class="k">Distance traveled</div><div class="v"><span id="calc-km">0</span> km</div></div>
                    <div class="av-compute-item fuel"><div class="k">Calculated fuel</div><div class="v"><span id="calc-fuel">0</span> L</div></div>
                    <div class="av-compute-item"><div class="k">Odometer after trip</div><div class="v"><span id="calc-odo">0</span> km</div></div>
                </div>

                <div class="av-actions">
                    <button class="av-btn av-btn-fuel" id="t-submit" type="button">+ Add trip</button>
                    <button class="av-btn av-btn-secondary" id="t-cancel" style="display:none;">Cancel</button>
                </div>
            </div>

            <div class="av-card">
                <div class="av-section-head"><h2>Recent trips</h2></div>
                <div class="av-table-wrap">
                    <table>
                        <thead><tr><th>Date</th><th>Vehicle</th><th>Route</th><th class="num">km</th><th class="num">Fuel (L)</th><th class="av-trip-actions-head" colspan="2">Actions</th></tr></thead>
                        <tbody id="trips-body"></tbody>
                    </table>
                </div>
                <div class="av-more-trips-wrap"><button class="av-btn av-more-trips" id="trips-more" type="button">View more</button></div>
            </div>
        </div>

        <!-- VEHICLES -->
        <div class="av-panel" id="av-panel-vehicles">
            <div class="av-card">
                <h2 id="av-vehicle-title">Add a new vehicle</h2>
                <div class="av-grid">
                    <div class="av-field"><label>Make <span class="av-required">*</span></label><input id="v-make" required placeholder="e.g. Toyota"></div>
                    <div class="av-field"><label>Model</label><input id="v-model" placeholder="e.g. Land Cruiser"></div>
                    <div class="av-field"><label>License plate (Latin letters) <span class="av-required">*</span></label><input id="v-plate" required placeholder="AA-123-DD" autocomplete="off" maxlength="9" inputmode="latin"></div>
                    <div class="av-field"><label>Category (fuel rate per 100 km) <span class="av-required">*</span></label><select id="v-type" required></select></div>
                    <div class="av-field"><label>Fuel type <span class="av-required">*</span></label><select id="v-fuel-kind" required><option value="">— Not specified —</option><option value="Petrol">Petrol</option><option value="Diesel">Diesel</option></select></div>
                    <div class="av-field" id="v-odo-wrap"><label>Initial odometer (km) <span class="av-required">*</span></label><input id="v-odo" required type="number" min="0" step="0.01" placeholder="0"></div>
                    <div class="av-actions av-vehicle-form-actions">
                        <button class="av-btn" id="v-submit" type="button">+ Add vehicle</button>
                        <button class="av-btn av-btn-secondary" id="v-cancel" style="display:none;">Cancel</button>
                    </div>
                </div>
                <div class="av-hint">The odometer updates automatically from trips. Admins can adjust the initial odometer; the current reading is calculated from trip history.</div>
            </div>
            <div class="av-card">
                <div class="av-section-head"><h2>Fleet Manager</h2><div class="av-list-tools"><input id="vehicle-list-search" class="av-list-search" autocomplete="off" maxlength="9" placeholder="Search by plate — AA-123-DD"><select id="vehicle-status-filter" class="av-status-filter"><option value="active">Active</option><option value="inactive">Inactive</option><option value="all">All</option></select></div></div>
                <div class="av-table-wrap">
                    <table>
                        <thead><tr><th>Make / Model</th><th>Plate</th><th>Category</th><th>Fuel type</th><th class="num">Initial odometer</th><th class="num">Current odometer</th><th>Status</th><th colspan="2"></th></tr></thead>
                        <tbody id="vehicles-body"></tbody>
                    </table>
                </div><div class="av-pager" id="pager-vehicles"></div>
            </div>
        </div>

        <!-- ROUTES -->
        <div class="av-panel" id="av-panel-routes">
            <div class="av-card">
                <h2 id="av-route-title">Add a new route</h2>
                <div class="av-grid av-route-form-grid">
                    <div class="av-field av-rel"><label>Starting location <span class="av-required">*</span></label><input id="r-origin" autocomplete="off" placeholder="Select or add a starting location"><div id="r-origin-results" class="av-dropdown"></div></div>
                    <div class="av-field av-rel"><label>City <span class="av-required">*</span></label><input id="r-city" autocomplete="off" placeholder="Search cities"><div id="r-city-results" class="av-dropdown"></div></div>
                    <div class="av-field av-rel"><label>District</label><input id="r-district" autocomplete="off" placeholder="Select a city first"><div id="r-district-results" class="av-dropdown"></div><p class="av-hint av-location-hint" id="r-district-hint"></p></div>
                    <div class="av-field"><label>Address</label><input id="r-address" autocomplete="off" placeholder="e.g. Kakheti Highway 15"></div>
                    <div class="av-field"><label>Distance (km) <span class="av-required">*</span></label><input id="r-km" required type="number" min="0.01" step="0.01" placeholder="0"></div>
                    <div class="av-actions">
                        <button class="av-btn" id="r-submit">+ Add route</button>
                        <button class="av-btn av-btn-secondary" id="r-cancel" style="display:none;">Cancel</button>
                    </div>
                </div>
            </div>
            <div class="av-card">
                <div class="av-section-head"><h2>Route list</h2><div class="av-list-tools"><span class="av-search-clear-wrap"><input id="route-list-search" class="av-list-search av-search-clear-input" autocomplete="off" placeholder="Search by address"><button type="button" id="route-list-search-clear" class="av-search-clear" aria-label="Clear" title="Clear">×</button></span></div></div>
                <div class="av-table-wrap">
                    <table>
                        <thead><tr><th>Origin / destination</th><th>Destination</th><th class="num">Distance (km)</th><th>Actions</th></tr></thead>
                        <tbody id="routes-body"></tbody>
                    </table>
                </div><div class="av-pager" id="pager-routes"></div>
            </div>
        </div>

        <!-- LOOKUP -->
        <div class="av-panel" id="av-panel-lookup">
            <div class="av-card">
                <h2>Search</h2>
                <div class="av-grid">
                    <div class="av-field av-rel">
                        <label>Vehicle — Plate, make, or model</label>
                        <input id="rep-search" autocomplete="off" placeholder="All vehicles">
                        <div id="rep-results" class="av-dropdown"></div>
                    </div>
                    <div class="av-field">
                        <label>Fuel type</label>
                        <select id="rep-fuel-kind">
                            <option value="">All</option>
                            <option value="Petrol">Petrol</option>
                            <option value="Diesel">Diesel</option>
                        </select>
                    </div>
                    <div class="av-field av-rel">
                        <label>Route</label>
                        <input id="rep-route-search" autocomplete="off" placeholder="All routes">
                        <div id="rep-route-results" class="av-dropdown"></div>
                    </div>
                    <div class="av-field"><label>Period — from</label><input id="rep-from" type="date"></div>
                    <div class="av-field"><label>Period — to</label><input id="rep-to" type="date"></div>
                </div>
                <div class="av-subrow av-period-shortcuts" aria-label="Quick period filters">
                    <div class="av-period-actions">
                        <button class="av-btn av-btn-secondary av-btn-small" id="rep-day" type="button">Day</button>
                        <button class="av-btn av-btn-secondary av-btn-small" id="rep-month" type="button">Month</button>
                        <button class="av-btn av-btn-secondary av-btn-small" id="rep-year" type="button">Year</button>
                        <button class="av-btn av-btn-secondary av-btn-small" id="rep-clear" type="button">All time</button>
                    </div>
                    <button class="av-btn av-search-btn av-search-btn-lower" id="rep-run" type="button"><span aria-hidden="true">⌕</span> Search</button>
                </div>
            </div>

            <div class="av-card" id="rep-card" style="display:none;">
                <div class="av-toolbar">
                    <h2 id="rep-title">Results</h2>
                    <button class="av-btn av-btn-small av-download-btn" id="rep-export" type="button"><span class="av-download-icon" aria-hidden="true">⇩</span><span>Download Excel</span></button>
                </div>
                <div class="av-summary" id="rep-summary"></div>
                <div class="av-table-wrap">
                    <table>
                        <thead><tr><th>Date</th><th>Vehicle</th><th>Fuel type</th><th>Route</th><th class="num">km</th><th class="num">Fuel consumed (L)</th><th id="rep-edit-head">Edit</th><th id="rep-delete-head">Delete</th></tr></thead>
                        <tbody id="rep-body"></tbody>
                    </table>
                    <div id="rep-pagination" class="av-pager av-report-pager" aria-label="Search result pages"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings -->
    <div class="av-modal" id="av-pass-modal" style="display:none;">
        <div class="av-card av-modal-card">
            <div class="av-modal-head"><div><div class="av-eyebrow">Settings</div><h2>Security</h2></div><button class="av-icon-btn" id="p-close" type="button" onclick="AvtoparkiApp.closePassword()" aria-label="Close">×</button></div><p class="av-hint av-settings-copy">Change your account password here.</p>
            <div class="av-field" id="p-username-field" style="display:none;"><label>New admin username</label><input id="p-username" name="av-admin-username" type="text" autocomplete="off" spellcheck="false" placeholder="e.g. admin.nino"></div>
            <div class="av-field"><label>Current password <span class="av-required">*</span></label><div class="av-password-wrap"><input id="p-current" required name="av-current-password" type="password" autocomplete="current-password" data-lpignore="true" data-1p-ignore><button class="av-password-toggle" type="button" data-password-toggle="p-current" aria-label="Show password" title="Show password"><svg class="av-eye-svg av-eye-show" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="av-eye-svg av-eye-hide" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.7a2 2 0 0 0 2.7 2.7"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c5.5 0 9 5.2 9 5.2a15.6 15.6 0 0 1-3.1 3.6"/><path d="M6.2 6.2C4.1 7.6 3 9.2 3 9.2S6.5 14.4 12 14.4c1 0 2-.2 2.8-.5"/></svg></button></div></div>
            <div class="av-field"><label>New password <span class="av-required">*</span></label><div class="av-password-wrap"><input id="p-new" required name="av-new-password" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore><button class="av-password-toggle" type="button" data-password-toggle="p-new" aria-label="Show password" title="Show password"><svg class="av-eye-svg av-eye-show" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="av-eye-svg av-eye-hide" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.7a2 2 0 0 0 2.7 2.7"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c5.5 0 9 5.2 9 5.2a15.6 15.6 0 0 1-3.1 3.6"/><path d="M6.2 6.2C4.1 7.6 3 9.2 3 9.2S6.5 14.4 12 14.4c1 0 2-.2 2.8-.5"/></svg></button></div></div>
            <div class="av-field"><label>Repeat new password <span class="av-required">*</span></label><div class="av-password-wrap"><input id="p-repeat" required name="av-repeat-password" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore><button class="av-password-toggle" type="button" data-password-toggle="p-repeat" aria-label="Show password" title="Show password"><svg class="av-eye-svg av-eye-show" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="av-eye-svg av-eye-hide" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.7a2 2 0 0 0 2.7 2.7"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c5.5 0 9 5.2 9 5.2a15.6 15.6 0 0 1-3.1 3.6"/><path d="M6.2 6.2C4.1 7.6 3 9.2 3 9.2S6.5 14.4 12 14.4c1 0 2-.2 2.8-.5"/></svg></button></div></div>
            <div class="av-error" id="av-pass-error"></div>
            <div class="av-actions">
                <button class="av-btn" id="p-submit">Update password</button>
                <button class="av-btn av-btn-secondary" id="p-cancel">Cancel</button>
                <button class="av-btn av-btn-ghost" id="p-logout" type="button" style="display:none;">Log out</button>
            </div>
        </div>
    </div>


    <!-- USERS / ADMIN -->
    <div class="av-panel" id="av-panel-users">
      <div class="av-card">
        <div class="av-page-head"><div><h2>Users</h2><p class="av-hint">Only administrators can create and manage employee accounts.</p></div></div>
        <div class="av-grid">
          <div class="av-field"><label>Username <span class="av-required">*</span></label><input id="u-name" required autocomplete="off" placeholder="e.g. nino"></div>
          <div class="av-field"><label>Temporary password <span class="av-required">*</span></label><div class="av-password-wrap"><input id="u-pass" required type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="Temporary password"><button class="av-password-toggle" type="button" data-password-toggle="u-pass" aria-label="Show password" title="Show password"><svg class="av-eye-svg av-eye-show" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="av-eye-svg av-eye-hide" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.7a2 2 0 0 0 2.7 2.7"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c5.5 0 9 5.2 9 5.2a15.6 15.6 0 0 1-3.1 3.6"/><path d="M6.2 6.2C4.1 7.6 3 9.2 3 9.2S6.5 14.4 12 14.4c1 0 2-.2 2.8-.5"/></svg></button></div></div>
          <div class="av-field av-user-create-field"><label>&nbsp;</label><button class="av-btn av-field-btn" id="u-create" type="button">+ Add user</button></div>
        </div>
        <div class="av-hint">New users must change their temporary password at first login.</div>
        <div class="av-error" id="av-user-error"></div>
      </div>
      <div class="av-card" id="av-access-requests-card" style="display:none;">
        <h2>Access requests <span class="av-request-badge" id="av-request-count">0</span></h2>
        <div class="av-user-list" id="av-access-request-list"></div>
      </div>
      <div class="av-card">
        <div class="av-section-head"><h2>User list</h2><span class="av-user-limit-note">Maximum 5 users</span></div>
        <div class="av-user-list" id="av-user-list"><div class="av-user-loading">Loading users…</div></div>
      </div>
    </div>

    <div class="av-modal" id="av-user-password-modal" style="display:none;">
      <div class="av-card av-modal-card">
        <div class="av-modal-head"><div><div class="av-eyebrow">User</div><h2>Reset password</h2></div><button class="av-icon-btn" id="upr-close" type="button" aria-label="Close">×</button></div>
        <p class="av-hint" id="upr-user"></p>
        <div class="av-field"><label>New temporary password <span class="av-required">*</span></label><div class="av-password-wrap"><input id="upr-password" type="password" autocomplete="new-password"><button class="av-password-toggle" type="button" data-password-toggle="upr-password" aria-label="Show password" title="Show password"><svg class="av-eye-svg av-eye-show" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="av-eye-svg av-eye-hide" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.7a2 2 0 0 0 2.7 2.7"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c5.5 0 9 5.2 9 5.2a15.6 15.6 0 0 1-3.1 3.6"/><path d="M6.2 6.2C4.1 7.6 3 9.2 3 9.2S6.5 14.4 12 14.4c1 0 2-.2 2.8-.5"/></svg></button></div></div>
        <div class="av-error" id="upr-error"></div>
        <div class="av-modal-actions av-password-reset-actions"><button class="av-btn" id="upr-save" type="button">Save password</button><button class="av-btn av-password-reset-cancel" id="upr-cancel" type="button">Cancel</button></div>
      </div>
    </div>

    <div class="av-modal" id="av-confirm-modal" style="display:none;">
      <div class="av-card av-modal-card av-confirm-card">
        <div class="av-modal-head"><div><div class="av-eyebrow">Confirm</div><h2 id="av-confirm-title">Delete</h2></div><button class="av-icon-btn" type="button" id="av-confirm-close" aria-label="Close">×</button></div>
        <p class="av-confirm-copy" id="av-confirm-message"></p>
        <div class="av-actions av-confirm-actions"><button class="av-btn av-btn-danger-solid" id="av-confirm-yes">Delete</button><button class="av-btn" id="av-confirm-no">Cancel</button></div>
      </div>
    </div>
    <div class="av-toast" id="av-toast"></div>
</div>
