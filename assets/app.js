/* Fleet Management Journal — frontend */
var AvtoparkiApp = (function () {
    'use strict';

    var state = {
        vehicles: [],
        routes: [],
        settings: {},
        locations: {cities:[], districts:{}},
        trips: [],
        fuelTypes: [],
        report: null,
        reportPage: 1,
        reportLoading: false,
        reportHasMore: false,
        reportObserver: null,
        editTripId: 0,
        editVehicleId: 0,
        editRouteId: 0,
        tripVehicleId: 0,
        tripRouteId: 0,
        repVehicleId: 0,
        repRouteId: 0,
        applyingRoute: false,
        freshLogin: false,
        user: '',
        isAdmin: false,
        mustChange: false,
        canAddTrip: false,
        tripRequestStatus: 'none',
        confirmAction: null,
        editFuelTypeId: 0,
        users: [],
        accessPollTimer: null,
        accessPollBusy: false,
        sessionPollTimer: null,
        sessionPollBusy: false,
        flashVehicleId: 0, flashRouteId: 0, flashTripId: 0,
        passwordResetUserId: 0, passwordResetUserName: '',
        activeTab: localStorage.getItem('avtoparki_active_tab') || 'trips',
        pendingActions: {},
        pages: {vehicles:1, routes:1},
        pageSizes: {
            vehicles: parseInt(localStorage.getItem('avtoparki_page_size_vehicles') || '10', 10) || 10,
            routes: parseInt(localStorage.getItem('avtoparki_page_size_routes') || '10', 10) || 10,
            report: parseInt(localStorage.getItem('avtoparki_page_size_report') || '10', 10) || 10
        }
    };

    var $ = function (id) { return document.getElementById(id); };

    /* ---------------- API ---------------- */
    function api(action, method, body) {
        var httpMethod = method || 'GET';
        var opts = {
            method: httpMethod,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
        };
        if (body) { opts.body = JSON.stringify(body); }

        function request(attempt) {
            return fetch(AVTOPARKI.api + '?action=' + action, opts).then(function (res) {
                var contentType = (res.headers.get('content-type') || '').toLowerCase();
                if (contentType.indexOf('application/json') === -1) {
                    var typeErr = new Error('Invalid server response. Please try again.');
                    typeErr.status = res.status;
                    throw typeErr;
                }
                return res.json().then(function (data) {
                    if (!res.ok) {
                        if (res.status === 401 && action !== 'login') { window.setTimeout(function(){ location.reload(); }, 50); }
                        var err = new Error((data && data.message) || 'Unable to complete the request. Please try again.');
                        err.status = res.status;
                        throw err;
                    }
                    return data;
                });
            });
        }
        return request(0);
    }

    function runOnce(key, work) {
        if (state.pendingActions[key]) { return Promise.resolve(null); }
        state.pendingActions[key] = true;
        var result;
        try { result = work(); } catch (e) { delete state.pendingActions[key]; throw e; }
        return Promise.resolve(result).finally(function () { delete state.pendingActions[key]; });
    }
    function setBusy(el, busy) {
        if (!el) { return; }
        el.disabled = !!busy;
        el.classList.toggle('av-busy', !!busy);
    }

    /* ---------------- Shareable URL routing ---------------- */
    var routeTabs = ['trips', 'lookup', 'routes', 'vehicles', 'users'];

    function readUrlRoute() {
        var raw = String(window.location.hash || '').replace(/^#\/?/, '');
        var splitAt = raw.indexOf('?');
        var name = splitAt > -1 ? raw.slice(0, splitAt) : raw;
        var query = splitAt > -1 ? raw.slice(splitAt + 1) : '';
        return {
            tab: routeTabs.indexOf(name) > -1 ? name : '',
            params: new URLSearchParams(query)
        };
    }

    function routeInt(value, fallback) {
        var parsed = parseInt(value, 10);
        return parsed > 0 ? parsed : (fallback || 0);
    }

    function routePageSize(value, fallback) {
        var parsed = parseInt(value, 10);
        return [10, 20, 50].indexOf(parsed) > -1 ? parsed : fallback;
    }

    function routeDate(value, fallback) {
        return /^\d{4}-\d{2}-\d{2}$/.test(String(value || '')) ? String(value) : fallback;
    }

    function applyUrlRoute(route) {
        var params = route.params;
        var today = todayISO();
        if (route.tab === 'vehicles') {
            $('vehicle-list-search').value = params.get('q') || '';
            $('vehicle-status-filter').value = ['active', 'inactive', 'all'].indexOf(params.get('status')) > -1 ? params.get('status') : 'active';
            state.pages.vehicles = routeInt(params.get('p'), 1);
            state.pageSizes.vehicles = routePageSize(params.get('size'), state.pageSizes.vehicles || 10);
        } else if (route.tab === 'routes') {
            $('route-list-search').value = params.get('q') || '';
            state.pages.routes = routeInt(params.get('p'), 1);
            state.pageSizes.routes = routePageSize(params.get('size'), state.pageSizes.routes || 10);
            var routeClear = $('route-list-search-clear');
            if (routeClear) { routeClear.classList.toggle('show', !!$('route-list-search').value); }
        } else if (route.tab === 'lookup') {
            var vehicleId = routeInt(params.get('vehicle'), 0);
            var selectedVehicle = vehicleId ? vehicleById(vehicleId) : null;
            state.repVehicleId = selectedVehicle ? vehicleId : 0;
            $('rep-search').value = selectedVehicle ? selectedVehicle.plate_number : '';

            var routeId = routeInt(params.get('route'), 0);
            var selectedRoute = routeId ? routeById(routeId) : null;
            state.repRouteId = selectedRoute ? routeId : 0;
            $('rep-route-search').value = selectedRoute ? tripRouteLabel(selectedRoute.route_name) : '';

            $('rep-from').value = params.has('from') ? routeDate(params.get('from'), '') : today;
            $('rep-to').value = params.has('to') ? routeDate(params.get('to'), '') : today;
            state.reportPage = routeInt(params.get('p'), 1);
            state.pageSizes.report = routePageSize(params.get('size'), state.pageSizes.report || 10);
        }
    }

    function currentRouteHash() {
        var tab = routeTabs.indexOf(state.activeTab) > -1 ? state.activeTab : 'trips';
        var params = new URLSearchParams();
        if (tab === 'vehicles') {
            var vehicleQuery = $('vehicle-list-search').value.trim();
            if (vehicleQuery) { params.set('q', vehicleQuery); }
            params.set('status', $('vehicle-status-filter').value || 'active');
            if ((state.pages.vehicles || 1) > 1) { params.set('p', String(state.pages.vehicles)); }
            params.set('size', String(state.pageSizes.vehicles || 10));
        } else if (tab === 'routes') {
            var routeQuery = $('route-list-search').value.trim();
            if (routeQuery) { params.set('q', routeQuery); }
            if ((state.pages.routes || 1) > 1) { params.set('p', String(state.pages.routes)); }
            params.set('size', String(state.pageSizes.routes || 10));
        } else if (tab === 'lookup') {
            if (state.repVehicleId) { params.set('vehicle', String(state.repVehicleId)); }
            if (state.repRouteId) { params.set('route', String(state.repRouteId)); }
            params.set('from', $('rep-from').value || '');
            params.set('to', $('rep-to').value || '');
            if ((state.reportPage || 1) > 1) { params.set('p', String(state.reportPage)); }
            params.set('size', String(state.pageSizes.report || 10));
        }
        var query = params.toString();
        return '#' + tab + (query ? '?' + query : '');
    }

    function syncUrlRoute(mode) {
        if (state.applyingRoute) { return; }
        var next = currentRouteHash();
        if (window.location.hash === next) { return; }
        window.history[mode === 'push' ? 'pushState' : 'replaceState'](null, '', next);
    }

    function activateTab(name, remember) {
        var tab = document.querySelector('.av-tab[data-tab="' + name + '"]');
        var panel = $('av-panel-' + name);
        if (!tab || !panel || tab.hidden || tab.style.display === 'none') { return false; }
        Array.prototype.forEach.call(document.querySelectorAll('.av-tab[data-tab]'), function (b) { b.classList.remove('active'); });
        Array.prototype.forEach.call(document.querySelectorAll('.av-panel'), function (p) { p.classList.remove('active'); });
        tab.classList.add('active'); panel.classList.add('active'); state.activeTab = name;
        if (remember !== false) {
            localStorage.setItem('avtoparki_active_tab', name);
            syncUrlRoute('push');
        }
        if (name === 'users') { openManage(); }
        if (name === 'lookup') {
            var reportToday = todayISO();
            if (!state.openLookupMonth && !state.applyingRoute) { $('rep-from').value = reportToday; $('rep-to').value = reportToday; }
            state.openLookupMonth = false;
            var requestedReportPage = state.applyingRoute ? (state.reportPage || 1) : 1;
            if (requestedReportPage > 1) { goReportPage(requestedReportPage); }
            else { runReport(); }
        }
        return true;
    }

    /* ---------------- helpers ---------------- */
    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(n) { return Math.round((parseFloat(n) || 0) * 100) / 100; }
    function fmt(n) { return num(n).toLocaleString('en-US'); }
    function fmtDate(iso) {
        if (!iso) { return ''; }
        var p = String(iso).split('-');
        return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : iso;
    }
    function todayISO() {
        // Business date is always Georgia/Tbilisi, independent of browser/server timezone.
        var parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Tbilisi', year: 'numeric', month: '2-digit', day: '2-digit'
        }).formatToParts(new Date());
        var out = {};
        parts.forEach(function (part) { if (part.type !== 'literal') { out[part.type] = part.value; } });
        return out.year + '-' + out.month + '-' + out.day;
    }
    function toast(msg) {
        var t = $('av-toast');
        t.textContent = msg;
        t.classList.add('show');
        setTimeout(function () { t.classList.remove('show'); }, 2000);
    }
    function vehicleById(id) {
        for (var i = 0; i < state.vehicles.length; i++) {
            if (parseInt(state.vehicles[i].id_pk, 10) === parseInt(id, 10)) { return state.vehicles[i]; }
        }
        return null;
    }
    function routeById(id) {
        for (var i = 0; i < state.routes.length; i++) {
            if (parseInt(state.routes[i].id_pk, 10) === parseInt(id, 10)) { return state.routes[i]; }
        }
        return null;
    }
    function vehicleLabel(v) {
        return (v.plate_number + ' — ' + v.make + ' ' + (v.model || '')).trim();
    }
    function matchVehicle(v, q) {
        return v.plate_number.toLowerCase().indexOf(q) > -1 ||
               v.make.toLowerCase().indexOf(q) > -1 ||
               (v.model || '').toLowerCase().indexOf(q) > -1;
    }

    /* ---------------- Authentication ---------------- */
    function showLogin() {
        $('av-login').style.display = 'flex';
        $('av-app').style.display = 'none';
    }
    function showApp() {
        $('av-login').style.display = 'none';
        $('av-app').style.display = 'block';
    }

    function doLogin() {
        $('av-login-error').textContent = '';
        api('login', 'POST', {
            username: $('av-login-user').value.trim(),
            password: $('av-login-pass').value
        }).then(function (data) {
            $('av-login-pass').value = '';
            state.user = data.user;
            state.isAdmin = !!data.is_admin;
            state.mustChange = !!data.must_change;
            state.canAddTrip = !!(data.permissions && data.permissions.trip_create);
            state.tripRequestStatus = data.trip_request_status || 'none';
            // Require a password change before loading application data.
            if (state.mustChange) {
                $('av-login').style.display = 'none';
                $('av-app').style.display = 'none';
                openPassword(true);
                return;
            }
            // A shared URL takes precedence after login; without one a fresh login opens Trips.
            state.freshLogin = true;
            showApp();
            return bootstrap();
        }).catch(function (e) {
            $('av-login-error').textContent = e.message;
        });
    }

    function logout() {
        localStorage.removeItem('avtoparki_active_tab');
        localStorage.removeItem('avtoparki_report_from');
        localStorage.removeItem('avtoparki_report_to');
        localStorage.removeItem('avtoparki_report_vehicle');
        localStorage.removeItem('avtoparki_report_route');
        api('logout', 'POST').then(function () {
            location.reload();
        });
    }

    function resetPasswordModalState() {
        ['p-current', 'p-new', 'p-repeat'].forEach(function (id) {
            var input = $(id);
            if (input) { input.type = 'password'; input.value = ''; }
        });
        Array.prototype.forEach.call(document.querySelectorAll('#av-pass-modal [data-password-toggle]'), function (btn) {
            btn.classList.remove('is-visible');
            btn.setAttribute('aria-label', 'Show password');
            btn.setAttribute('title', 'Show password');
        });
    }

    function openPassword(forced) {
        forced = forced === true;
        resetPasswordModalState();
        $('av-pass-error').textContent = '';
        var adminSetup = forced && state.isAdmin;
        $('p-username-field').style.display = adminSetup ? 'block' : 'none';
        $('p-username').value = adminSetup ? (state.user || '') : '';
        $('p-current').value = '';
        $('p-new').value = '';
        $('p-repeat').value = '';
        if ($('office-setting-field')) $('office-setting-field').style.display = (state.isAdmin && !forced) ? 'block' : 'none';
        if ($('office-setting-sep')) $('office-setting-sep').style.display = (state.isAdmin && !forced) ? 'block' : 'none';
        if ($('office-city')) $('office-city').value = (state.settings && state.settings.office_city) || '';
        if ($('office-district')) $('office-district').value = (state.settings && state.settings.office_district) || '';
        if ($('office-address')) $('office-address').value = (state.settings && state.settings.office_address) || '';
        renderOfficeLocationLists();
        $('av-pass-modal').style.display = 'flex';
        $('p-cancel').style.display = forced ? 'none' : 'inline-block';
        $('p-logout').style.display = forced ? 'inline-block' : 'none';
        $('p-close').style.display = forced ? 'none' : 'inline-flex';
        $('av-pass-modal').setAttribute('data-forced', forced ? '1' : '0');
    }
    function closePassword() {
        if ($('av-pass-modal').getAttribute('data-forced') === '1') { return; }
        $('av-pass-modal').style.display = 'none';
        resetPasswordModalState();
    }

    function passwordRequirementMessage(value) {
        var missing = [];
        value = String(value || '');
        if (value.length < 8) { missing.push('at least 8 characters'); }
        if (!/[A-Z]/.test(value)) { missing.push('at least 1 uppercase Latin letter'); }
        if (!/[0-9]/.test(value)) { missing.push('at least 1 number'); }
        if (!/[^a-zA-Z0-9]/.test(value)) { missing.push('at least 1 special character'); }
        return missing.length ? 'Password must contain: ' + missing.join(', ') + '.' : '';
    }

    function savePassword() {
        $('av-pass-error').textContent = '';
        var requirementError = passwordRequirementMessage($('p-new').value);
        if (requirementError) { $('av-pass-error').textContent = requirementError; return; }
        api('password', 'POST', {
            current_password: $('p-current').value,
            new_password: $('p-new').value,
            repeat_password: $('p-repeat').value,
            new_username: (state.isAdmin && state.mustChange) ? $('p-username').value.trim() : ''
        }).then(function (data) {
            $('av-pass-modal').setAttribute('data-forced', '0');
            $('av-pass-modal').style.display = 'none';
            $('p-cancel').style.display = 'inline-block';
            $('p-logout').style.display = 'none';
            $('p-close').style.display = 'inline-flex';
            resetPasswordModalState();
            state.mustChange = false;
            if (data && data.user) { state.user = data.user; }
            // Load the current role and permissions after the password is changed.
            showApp();
            bootstrap().then(function(){ toast('Password updated successfully'); });
        }).catch(function (e) {
            $('av-pass-error').textContent = e.message;
        });
    }

    /* ---------------- Loading ---------------- */
    function bootstrap() {
        return api('bootstrap').then(function (data) {
            state.vehicles = data.vehicles || [];
            state.routes = data.routes || [];
            state.trips = data.trips || [];
            state.fuelTypes = data.fuel_types || [];
            state.settings = data.settings || {};
            state.locations = data.locations || {cities:[], districts:{}};
            state.user = data.user || '';
            state.isAdmin = !!data.is_admin;
            state.mustChange = !!data.must_change;
            state.canAddTrip = !!(data.permissions && data.permissions.trip_create);
            state.tripRequestStatus = data.trip_request_status || 'none';
            $('av-who').textContent = displayUsername(state.user);
            // Admin-only navigation. Keep both inline state and hidden attribute in sync so CSS cannot expose it.
            ['av-manage-tab', 'av-routes-tab', 'av-vehicles-tab'].forEach(function (id) {
                var el = $(id);
                if (!el) { return; }
                el.hidden = !state.isAdmin;
                el.style.display = state.isAdmin ? 'inline-flex' : 'none';
            });
            // Defensive reset: a non-admin must never remain on an admin-only panel after role/session changes.
            if (!state.isAdmin) {
                ['users', 'routes', 'vehicles'].forEach(function (name) {
                    var panel = $('av-panel-' + name);
                    if (panel) { panel.classList.remove('active'); }
                });
                var tripsPanel = $('av-panel-trips');
                var tripsTab = document.querySelector('.av-tab[data-tab="trips"]');
                if (tripsPanel && !document.querySelector('.av-panel.active')) { tripsPanel.classList.add('active'); }
                if (tripsTab && !document.querySelector('.av-tab.active:not([hidden])')) { tripsTab.classList.add('active'); }
            }
            var urlRoute = readUrlRoute();
            state.applyingRoute = true;
            if (urlRoute.tab) { applyUrlRoute(urlRoute); }
            renderFuelSelect();
            renderVehicles();
            renderLocationSelects();
            if ($('r-origin') && !$('r-origin').value) $('r-origin').value = defaultOrigin();
            renderRoutes();
            renderTrips();
            recalcTrip();
            applyTripCreatePermission();
            var wantedTab = urlRoute.tab || (state.freshLogin ? 'trips' : (localStorage.getItem('avtoparki_active_tab') || 'trips'));
            if (!activateTab(wantedTab, false)) { activateTab('trips', false); }
            state.applyingRoute = false;
            state.freshLogin = false;
            localStorage.setItem('avtoparki_active_tab', state.activeTab);
            syncUrlRoute('replace');
            startAccessRequestPolling();
            startSessionPolling();
        });
    }

    /* ---------------- Vehicles ---------------- */
    function renderFuelSelect() {
        $('v-type').innerHTML = '<option value="" selected>— Not specified —</option>' + state.fuelTypes.map(function (f) {
            return '<option value="' + f.id_pk + '">' + esc(f.type_name) + ' — ' + fmt(f.litres) + ' L/100km</option>';
        }).join('');
    }

    // Convert Georgian keyboard input to matching Latin plate characters.
    var KA2LAT = {
        'ა': 'A', 'ბ': 'B', 'გ': 'G', 'დ': 'D', 'ე': 'E', 'ვ': 'V', 'ზ': 'Z', 'თ': 'T', 'ი': 'I',
        'კ': 'K', 'ლ': 'L', 'მ': 'M', 'ნ': 'N', 'ო': 'O', 'პ': 'P', 'ჟ': 'J', 'რ': 'R', 'ს': 'S',
        'ტ': 'T', 'უ': 'U', 'ფ': 'F', 'ქ': 'Q', 'ღ': 'R', 'ყ': 'Y', 'შ': 'S', 'ჩ': 'C', 'ც': 'C',
        'ძ': 'Z', 'წ': 'W', 'ჭ': 'W', 'ხ': 'X', 'ჯ': 'J', 'ჰ': 'H'
    };

    // Plate format AA-123-DD: 2 letters, 3 digits, 2 letters.
    function plateClean(raw) {
        var s = '';
        var chars = String(raw).split('');
        for (var k = 0; k < chars.length; k++) {
            var c = (KA2LAT[chars[k]] || chars[k]).toUpperCase();
            var i = s.length;
            if (i >= 7) { break; }
            if (i < 2 || i >= 5) {
                if (/[A-Z]/.test(c)) { s += c; }
            } else if (/[0-9]/.test(c)) {
                s += c;
            }
        }
        return s;
    }
    function plateFormat(raw) {
        var s = plateClean(raw);
        var out = s.slice(0, 2);
        if (s.length > 2) { out += '-' + s.slice(2, 5); }
        if (s.length > 5) { out += '-' + s.slice(5, 7); }
        return out;
    }
    function forceLatinPlate(el) {
        var caret = el.selectionStart;
        var atEnd = caret === el.value.length;
        var kept = plateClean(el.value.slice(0, caret)).length;
        el.value = plateFormat(el.value);
        if (atEnd) {
            el.setSelectionRange(el.value.length, el.value.length);
            return;
        }
        var count = 0, pos = 0;
        while (pos < el.value.length && count < kept) {
            if (el.value[pos] !== '-') { count++; }
            pos++;
        }
        el.setSelectionRange(pos, pos);
    }

    function pageSlice(list, key) {
        var size = state.pageSizes[key] || 10, totalPages = Math.max(1, Math.ceil(list.length / size));
        state.pages[key] = Math.min(Math.max(1, state.pages[key] || 1), totalPages);
        var start = (state.pages[key] - 1) * size;
        return {items:list.slice(start,start+size), totalPages:totalPages, page:state.pages[key], total:list.length, start:start};
    }
    function pagerPages(page,totalPages){
        if(totalPages<=7){return Array.from({length:totalPages},function(_,i){return i+1;});}
        var out=[1], from=Math.max(2,page-1), to=Math.min(totalPages-1,page+1);
        if(from>2)out.push('…');
        for(var i=from;i<=to;i++)out.push(i);
        if(to<totalPages-1)out.push('…');
        out.push(totalPages); return out;
    }
    function renderPager(key, totalPages, totalItems) {
        var box = $('pager-' + key); if (!box) return;
        totalItems=parseInt(totalItems||0,10); if(!totalItems){box.innerHTML='';return;}
        var size=state.pageSizes[key]||10, p=state.pages[key]||1, first=(p-1)*size+1, last=Math.min(p*size,totalItems);
        var pages=pagerPages(p,totalPages), nav='';
        pages.forEach(function(i){ nav += i==='…' ? '<span class="av-page-dots">…</span>' : '<button type="button" data-page-key="'+key+'" data-page="'+i+'" class="'+(i===p?'active':'')+'">'+i+'</button>'; });
        box.innerHTML='<span class="av-page-range">'+first+'–'+last+' / '+totalItems+'</span><div class="av-page-nav"><button type="button" data-page-key="'+key+'" data-page="'+Math.max(1,p-1)+'" '+(p<=1?'disabled':'')+' aria-label="Previous page">‹</button>'+nav+'<button type="button" data-page-key="'+key+'" data-page="'+Math.min(totalPages,p+1)+'" '+(p>=totalPages?'disabled':'')+' aria-label="Next page">›</button></div><label class="av-page-size-label">Rows per page: <select class="av-page-size" data-page-size-key="'+key+'" aria-label="Rows per page"><option>10</option><option>20</option><option>50</option></select></label>';
        syncPageSizeSelects(key);
    }
    function syncPageSizeSelects(key){
        Array.prototype.forEach.call(document.querySelectorAll('.av-page-size[data-page-size-key="'+key+'"]'),function(s){s.value=String(state.pageSizes[key]||10);});
    }
    function setPageSize(key,n){
        if(['vehicles','routes','report'].indexOf(key)<0)return;
        n=parseInt(n,10); if([10,20,50].indexOf(n)<0)n=10;
        state.pageSizes[key]=n; localStorage.setItem('avtoparki_page_size_'+key,String(n));
        if(key==='report') { state.reportPage=1; runReport(); }
        else {
            state.pages[key]=1;
            if(key==='vehicles')renderVehicles(); else if(key==='routes')renderRoutes();
            syncUrlRoute('replace');
        }
    }

    function renderVehicles() {
        var body = $('vehicles-body');
        var filter = $('vehicle-status-filter') ? $('vehicle-status-filter').value : 'active';
        var q = $('vehicle-list-search') ? plateFormat($('vehicle-list-search').value).toLowerCase() : '';
        var list = state.vehicles.filter(function(v){ return (filter === 'all' || (filter === 'active' ? parseInt(v.is_active,10) === 1 : parseInt(v.is_active,10) === 0)) && (!q || String(v.plate_number||'').toLowerCase().indexOf(q)>-1); });
        if (!list.length) { body.innerHTML = '<tr class="av-empty"><td colspan="9">Vehicle not found</td></tr>'; renderPager('vehicles',1,0); return; }
        var pg=pageSlice(list,'vehicles'); renderPager('vehicles',pg.totalPages,pg.total);
        body.innerHTML = pg.items.map(function (v) {
            var active = parseInt(v.is_active,10) === 1, used = parseInt(v.trip_count||0,10) > 0;
            var editButton = active
                ? '<button class="av-btn av-btn-small av-edit-black av-action-fixed" data-edit-vehicle="' + v.id_pk + '" data-action-enabled="1">Edit</button>'
                : '<button class="av-btn av-btn-small av-edit-black av-action-fixed" type="button" disabled>Edit</button>';
            var deleteButton;
            if (!active) {
                deleteButton = '<button class="av-btn av-btn-small av-btn-danger av-action-fixed" type="button" disabled>Delete</button>';
            } else if (used) {
                deleteButton = '<span class="av-disabled-tip" data-tip="This vehicle has trip history and cannot be deleted"><button class="av-btn av-btn-small av-btn-danger av-action-fixed" type="button" disabled>Delete</button></span>';
            } else {
                deleteButton = '<button class="av-btn av-btn-small av-btn-danger av-action-fixed" data-del-vehicle="' + v.id_pk + '" data-action-enabled="1">Delete</button>';
            }
            return '<tr class="' + (active ? '' : 'av-row-inactive') + (parseInt(state.flashVehicleId,10)===parseInt(v.id_pk,10)?' av-row-flash':'') + '">' +
                '<td>' + esc(v.make) + (v.model ? ' <span class="av-brand-model-dot">•</span> ' + esc(v.model) : '') + '</td><td>' + esc(v.plate_number) + '</td>' +
                '<td>' + esc(v.type_name || '—') + ' <span class="av-tag fuel">' + fmt(v.litres) + ' L/100km</span></td>' +
                '<td>' + esc(v.fuel_kind || '—') + '</td>' +
                '<td class="num">' + fmt(v.initial_odometer) + ' km</td><td class="num">' + fmt(v.odometer) + ' km</td>' +
                '<td><span class="av-status-pill' + (active ? '' : ' inactive') + '">' + (active ? 'Active' : 'Inactive') + '</span></td>' +
                '<td colspan="2"><div class="av-row-actions">' + editButton + deleteButton +
                '<button class="av-btn av-btn-small av-btn-status av-action-fixed ' + (active ? 'av-status-deactivate' : 'av-status-restore') + '" data-status-vehicle="' + v.id_pk + '" data-next-active="' + (active ? '0' : '1') + '">' + (active ? 'Deactivate' : 'Restore') + '</button>' +
                '</div></td></tr>';
        }).join('');
        if(state.flashVehicleId){window.setTimeout(function(){state.flashVehicleId=0;renderVehicles();},1400);}
    }

    function setVehicleStatus(id, active) {
        var v=vehicleById(id); var label=v?v.plate_number:'this vehicle';
        askConfirm(active?'Restore vehicle':'Deactivate vehicle', 'Are you sure you want to ' + (active?'restore ':'deactivate ') + label + '?', active?'Restore':'Deactivate', function(){
            return runOnce('vehicle-status-'+id,function(){return api('vehicle_status','POST',{id:parseInt(id,10),is_active:active?1:0});}).then(function(data){
                state.vehicles=data.vehicles||state.vehicles; renderVehicles(); recalcTrip(); refreshReport(); toast(active?'Vehicle restored':'Vehicle deactivated');
            }).catch(function(e){toast(e.message);});
        }, active ? 'restore' : 'status');
    }

    function saveVehicle() {
        var payload = {
            make: $('v-make').value.trim(),
            model: $('v-model').value.trim(),
            plate_number: plateFormat($('v-plate').value),
            type_id: parseInt($('v-type').value, 10) || 0,
            fuel_kind: $('v-fuel-kind').value || ''
        };
        if (!payload.make) { toast('Enter the vehicle make'); return; }
        if (!payload.type_id) { toast('Select a vehicle category'); return; }
        if (!payload.fuel_kind) { toast('Select a fuel type'); return; }
        if ($('v-odo').value.trim()==='' || isNaN(parseFloat($('v-odo').value)) || parseFloat($('v-odo').value)<0) { toast('Enter a valid initial odometer'); return; }
        if (!/^[A-Z]{2}-\d{3}-[A-Z]{2}$/.test(payload.plate_number)) {
            toast('Enter the license plate in AA-123-DD format');
            return;
        }
        var action = 'vehicle_create';
        payload.initial_odometer = parseFloat($('v-odo').value);
        if (state.editVehicleId) {
            action = 'vehicle_update';
            payload.id = state.editVehicleId;
        }
        var btn=$('v-submit'); setBusy(btn,true);
        var editedVehicleId=state.editVehicleId;
        runOnce('vehicle-save',function(){return api(action, 'POST', payload);}).then(function (data) {
            if(!data){return;} state.flashVehicleId=editedVehicleId||parseInt(data.id||0,10); state.vehicles = data.vehicles || state.vehicles;
            cancelVehicleEdit();
            renderVehicles();
            recalcTrip();
            refreshReport();
            toast('Saved');
        }).catch(function (e) { toast(e.message); }).finally(function(){setBusy(btn,false);});
    }

    function setEditFieldMode(card, enabled) {
        if (!card) { return; }
        card.classList.toggle('av-edit-mode', !!enabled);
        if (enabled) {
            card.classList.remove('av-edit-card-flash');
            void card.offsetWidth;
            card.classList.add('av-edit-card-flash');
            window.setTimeout(function(){card.classList.remove('av-edit-card-flash');},900);
        } else {
            card.classList.remove('av-edit-card-flash');
        }
        var fields=card.querySelectorAll('input:not([type="hidden"]),select,textarea');
        Array.prototype.forEach.call(fields,function(el){
            if(!enabled){
                el.classList.remove('av-editable-active');
                el.classList.remove('av-edit-field-flash');
                return;
            }
            if(el.disabled || el.readOnly){
                el.classList.remove('av-editable-active');
                el.classList.remove('av-edit-field-flash');
                return;
            }
            el.classList.add('av-editable-active');
            el.classList.remove('av-edit-field-flash');
            void el.offsetWidth;
            el.classList.add('av-edit-field-flash');
            window.setTimeout(function(){el.classList.remove('av-edit-field-flash');},900);
        });
    }
    function focusEditCard(cardId, focusId) {
        var anchor = $(cardId);
        var card = anchor ? (anchor.closest('.av-card') || anchor) : null;
        if (!card) { return; }
        setEditFieldMode(card,true);
        var targetTop = Math.max(0, card.getBoundingClientRect().top + window.pageYOffset - 18);
        window.scrollTo({ top: targetTop, behavior: 'smooth' });
        window.setTimeout(function(){
            var f=$(focusId);
            if(!f){return;}
            try{f.focus({preventScroll:true});}catch(e){f.focus();}
        }, 650);
    }
    function clearEditMode(cardId){var a=$(cardId),c=a?(a.closest('.av-card')||a):null;setEditFieldMode(c,false);}

    function setVehicleHistoryLocks(v) {
        var used=parseInt((v&&v.trip_count)||0,10)>0;
        ['v-plate','v-type','v-fuel-kind','v-odo'].forEach(function(id){
            var el=$(id); if(!el)return;
            el.disabled=used;
            var field=el.closest('.av-field'); if(!field)return;
            var old=field.querySelector('.av-history-lock-note'); if(old)old.remove();
            if(used){
                var note=document.createElement('div'); note.className='av-history-lock-note';
                note.textContent='This field is locked because the vehicle has trip history';
                field.appendChild(note);
            }
        });
    }

    function editVehicle(id) {
        var v = vehicleById(id);
        if (!v) { return; }
        state.editVehicleId = parseInt(id, 10);
        $('v-make').value = v.make;
        $('v-model').value = v.model || '';
        $('v-plate').value = v.plate_number;
        $('v-type').value = v.type_id;
        $('v-fuel-kind').value = v.fuel_kind || '';
        $('v-odo').value = num(v.initial_odometer);
        setVehicleHistoryLocks(v);
        $('av-vehicle-title').textContent = 'Edit vehicle — ' + v.plate_number;
        $('v-submit').textContent = 'Save changes';
        $('v-cancel').classList.add('av-edit-visible');
        focusEditCard('av-vehicle-title', 'v-make');
    }

    function cancelVehicleEdit() {
        state.editVehicleId = 0;
        setVehicleHistoryLocks(null);
        $('v-make').value = '';
        $('v-model').value = '';
        $('v-plate').value = '';
        $('v-type').value = '';
        $('v-fuel-kind').value = '';
        $('v-odo').value = '';
        $('av-vehicle-title').textContent = 'Add a new vehicle';
        $('v-submit').textContent = '+ Add vehicle';
        $('v-cancel').classList.remove('av-edit-visible');
        clearEditMode('av-vehicle-title');
    }

    function closeConfirm() {
        $('av-confirm-modal').style.display = 'none';
        state.confirmAction = null;
        $('av-confirm-yes').style.display = '';
        $('av-confirm-no').textContent = 'Cancel';
    }
    function askConfirm(title, message, yesLabel, action, kind) {
        $('av-confirm-title').textContent = title;
        $('av-confirm-message').textContent = message;
        $('av-confirm-yes').textContent = yesLabel || 'Confirm';
        $('av-confirm-yes').className = 'av-btn ' + (kind === 'restore' ? 'av-btn-confirm-restore' : (kind === 'status' ? 'av-btn-confirm-status' : 'av-btn-danger-solid'));
        $('av-confirm-yes').style.display = action ? '' : 'none';
        $('av-confirm-no').textContent = action ? 'Cancel' : 'Close';
        state.confirmAction = action || null;
        $('av-confirm-modal').style.display = 'flex';
    }
    function askDelete(title, message, action) { askConfirm(title, message, 'Delete', action); }
    function showInfo(title, message) { askConfirm(title, message, '', null); }

    function deleteVehicle(id) {
        var v = vehicleById(id); if(!v) return;
        if(parseInt(v.is_active,10)!==1) return;
        if(parseInt(v.trip_count||0,10)>0){ showInfo('Vehicle cannot be deleted','This vehicle has trip history and cannot be deleted. Deactivate it to preserve its history.'); return; }
        askDelete('Delete vehicle', 'Are you sure you want to delete vehicle ' + v.plate_number + '?', function () {
            return runOnce('vehicle-delete-'+id,function(){return api('vehicle_delete','POST',{id:parseInt(id,10)});}).then(function(data){ state.vehicles=data.vehicles||[]; renderVehicles(); recalcTrip(); refreshReport(); toast('Vehicle deleted'); }).catch(function(e){toast(e.message);});
        });
    }

    /* ---------------- Routes ---------------- */
    function renderRoutes() {
        renderRouteSuggestions();
        var body=$('routes-body'), q=$('route-list-search') ? $('route-list-search').value.trim().toLowerCase() : '';
        var list=state.routes.filter(function(r){var hay=[r.route_name,r.city,r.district,r.address].join(' ').toLowerCase();return !q || hay.indexOf(q)>-1;});
        if(!list.length){ body.innerHTML='<tr class="av-empty"><td colspan="4">Route not found</td></tr>'; renderPager('routes',1,0); return; }
        var pg=pageSlice(list,'routes'); renderPager('routes',pg.totalPages,pg.total);
        body.innerHTML=pg.items.map(function(r){
            var used=parseInt(r.trip_count||0,10)>0;
            var del=used
                ? '<span class="av-disabled-tip" data-tip="This route is used in trip history and cannot be deleted"><button class="av-btn av-btn-small av-btn-danger av-route-action" type="button" disabled>Delete</button></span>'
                : '<button class="av-btn av-btn-small av-btn-danger av-route-action" data-del-route="'+r.id_pk+'">Delete</button>';
            var parts=String(r.route_name||'').split(/\s+(?:→|↔)\s+/), officeRaw=String(r.origin_label||'').trim() || (parts[0]||'—'), office=String(officeRaw).split(/\s+(?:—|•)\s+/).map(function(x){return x.trim();}).filter(Boolean).join(' • ');
            var dest=[r.city,r.district,r.address].filter(function(v){return String(v||'').trim()!=='';}).join(' • ');
            var edit=used
                ? '<span class="av-disabled-tip" data-tip="This route is used in trip history and cannot be edited"><button class="av-btn av-btn-small av-edit-black av-route-action" type="button" disabled>Edit</button></span>'
                : '<button class="av-btn av-btn-small av-edit-black av-route-action" data-edit-route="'+r.id_pk+'">Edit</button>';
            return '<tr class="'+(parseInt(state.flashRouteId,10)===parseInt(r.id_pk,10)?'av-row-flash':'')+'"><td>'+esc(office)+'</td><td>'+esc(dest||'—')+'</td><td class="num">'+fmt(r.kilometres)+' km</td><td class="av-route-actions-cell"><div class="av-row-actions av-route-actions">'+edit+del+'</div></td></tr>';
        }).join('');
        if(state.flashRouteId){window.setTimeout(function(){state.flashRouteId=0;renderRoutes();},1400);}
    }

    function locationHtml(list, attr, emptyText, addAttr, query){
        var out='';
        list.forEach(function(name){ out += '<div class="av-item" '+attr+'="'+esc(name)+'"><span>'+esc(name)+'</span></div>'; });
        if(query && !list.some(function(name){return name.toLowerCase()===query.toLowerCase();})){
            out += '<div class="av-item av-location-add" '+addAttr+'="'+esc(query)+'"><span>+ Add “'+esc(query)+'”</span></div>';
        }
        if(!out) out='<div class="av-item disabled">'+esc(emptyText)+'</div>';
        return out;
    }
    function originNames(){
        var out=[];
        (state.routes||[]).forEach(function(r){
            var value=String(r.origin_label||'').trim();
            if(!value && r.route_name){ value=String(r.route_name).split(/\s+(?:→|↔)\s+/)[0].trim(); }
            value=value.split(/\s+(?:—|•)\s+/).map(function(x){return x.trim();}).filter(Boolean).join(' • ');
            if(value && out.indexOf(value)<0) out.push(value);
        });
        return out;
    }
    function defaultOrigin(){ var list=originNames(); return list.length ? list[0] : ''; }
    function openRouteOriginDropdown(){
        var input=$('r-origin'), box=$('r-origin-results'); if(!input||!box)return;
        if(input.disabled){ box.classList.remove('show'); return; }
        var raw=input.value.trim(), q=raw.toLowerCase(), all=originNames(), list=all.filter(function(v){return !q||v.toLowerCase().indexOf(q)>-1;});
        var html=list.map(function(v){return '<div class="av-item" data-pick-route-origin="'+esc(v)+'"><span>'+esc(v)+'</span></div>';}).join('');
        if(raw && !all.some(function(v){return v.toLowerCase()===q;})) html += '<div class="av-item av-location-add" data-add-route-origin="'+esc(raw)+'"><span>+ Add “'+esc(raw)+'”</span></div>';
        if(!html) html='<div class="av-item disabled">Enter a starting location</div>';
        box.innerHTML=html; box.classList.add('show');
    }
    function cityNames(){ return (state.locations.cities||[]).map(function(c){return c.name;}); }
    function validCity(value){ return cityNames().indexOf(value)>-1; }
    function districtList(city){ return (state.locations.districts&&state.locations.districts[city])||[]; }

    function openRouteCityDropdown(){
        var input=$('r-city'), box=$('r-city-results'); if(!input||!box)return;
        var q=input.value.trim().toLowerCase();
        var list=cityNames().filter(function(name){return !q || name.toLowerCase().indexOf(q)>-1;});
        box.innerHTML=locationHtml(list,'data-pick-location-city','City not found','data-add-location-city',input.value.trim());
        box.classList.add('show');
    }
    function syncRouteDistrictState(clearValue){
        var city=$('r-city').value.trim(), input=$('r-district'), hint=$('r-district-hint'), box=$('r-district-results');
        if(clearValue)input.value=''; if(box)box.classList.remove('show');
        if(!city){input.disabled=true;input.placeholder='Select a city first';if(hint)hint.textContent='';return;}
        if(!validCity(city)){input.disabled=true;input.value='';input.placeholder='Select a city from the list first';if(hint)hint.textContent='';return;}
        var list=districtList(city);
        if(!list.length){input.disabled=true;input.value='';input.placeholder='No districts listed for this city';if(hint)hint.textContent='No districts are listed for this city. You can enter the address directly.';return;}
        input.disabled=false;input.placeholder='Search districts';if(hint)hint.textContent='';
    }
    function openRouteDistrictDropdown(){
        var city=$('r-city').value.trim(), input=$('r-district'), box=$('r-district-results'); if(!input||!box)return;
        if(!validCity(city)){syncRouteDistrictState(false);return;}
        var all=districtList(city); if(!all.length){syncRouteDistrictState(false);return;}
        var q=input.value.trim().toLowerCase(), list=all.filter(function(name){return !q || name.toLowerCase().indexOf(q)>-1;});
        box.innerHTML=locationHtml(list,'data-pick-location-district','District not found','data-add-location-district',input.value.trim());
        box.classList.add('show');
    }
    function renderLocationSelects(selectedCity, selectedDistrict){
        if(!$('r-city'))return; $('r-city').value=selectedCity||''; syncRouteDistrictState(false);
        if(selectedDistrict && !$('r-district').disabled)$('r-district').value=selectedDistrict;
    }
    function renderDistrictSelect(selected){ syncRouteDistrictState(false); if(selected && !$('r-district').disabled)$('r-district').value=selected; }
    function renderOfficeLocationLists(){ /* office fields are manual by design */ }
    function renderRouteSuggestions(){ /* route city/district use the same searchable dropdown UI as trip selectors */ }

    function saveRoute() {
        var origin = $('r-origin').value.trim(), city = $('r-city').value.trim(), district = $('r-district').value.trim(), address = $('r-address').value.trim();
        var payload = {
            origin_label: origin,
            city: city,
            district: district,
            address: address,
            kilometres: parseFloat($('r-km').value) || 0
        };
        if(!origin || !city || payload.kilometres <= 0){ toast('Select a starting location and city, and enter a positive distance.'); return; }
        if(!validCity(city)){toast('Select a city from the list');return;}
        var dl=districtList(city); if(district && dl.indexOf(district)<0){toast('Select a district from the list');return;}
        var action = state.editRouteId ? 'route_update' : 'route_create';
        var firstRouteCreate = !state.editRouteId && state.routes.length === 0;
        if (state.editRouteId) { payload.id = state.editRouteId; }
        var btn=$('r-submit'); setBusy(btn,true);
        var editedRouteId=state.editRouteId;
        runOnce('route-save',function(){return api(action, 'POST', payload);}).then(function (data) {
            if(!data){return;} state.flashRouteId=editedRouteId||parseInt(data.id||0,10); state.routes = data.routes || state.routes;
            state.routes.sort(function(a,b){ if(parseInt(a.id_pk,10)===parseInt(state.flashRouteId,10))return -1; if(parseInt(b.id_pk,10)===parseInt(state.flashRouteId,10))return 1; return String(b.updated_at||'').localeCompare(String(a.updated_at||'')) || (parseInt(b.id_pk,10)-parseInt(a.id_pk,10)); });
            state.pages.routes=1;
            if(firstRouteCreate && $('route-list-search')) { $('route-list-search').value=''; $('route-list-search').dispatchEvent(new Event('input',{bubbles:true})); }
            cancelRouteEdit();
            renderRoutes();
            recalcTrip();
            toast('Saved');
        }).catch(function (e) { toast(e.message); }).finally(function(){setBusy(btn,false);});
    }

    function editRoute(id) {
        var r = routeById(id);
        if (!r) { return; }
        // A route becomes historical data after its first trip. Keep the same guard here
        // as in the table and backend so edit mode can never be opened indirectly.
        if (parseInt(r.trip_count || 0, 10) > 0) {
            showInfo('Edit route not possible', 'This route is used in trip history and cannot be edited.');
            return;
        }
        state.editRouteId = parseInt(id, 10);
        $('r-origin').disabled = true;
        $('r-origin-results').classList.remove('show');
        $('r-origin').value = String(r.origin_label||'').trim() || String(r.route_name||'').split(/\s+(?:→|↔)\s+/)[0].trim();
        renderLocationSelects(r.city || '', r.district || '');
        var editAddress = String(r.address || '').trim();
        if (!editAddress && r.route_name) {
            var routeParts = String(r.route_name).split(/\s+(?:→|↔)\s+/).map(function(x){return x.trim();}).filter(Boolean);
            var destination = routeParts.length >= 2 ? routeParts[1] : routeParts[0];
            var locationParts = String(destination || '').split(/\s+(?:—|•)\s+/).map(function(x){return x.trim();}).filter(Boolean);
            if (r.city && locationParts[0] === r.city) { locationParts.shift(); }
            if (r.district && locationParts[0] === r.district) { locationParts.shift(); }
            editAddress = locationParts.join(' • ');
        }
        $('r-address').value = editAddress;
        $('r-km').value = num(r.kilometres);
        $('av-route-title').textContent = 'Edit route';
        $('r-submit').textContent = 'Save changes';
        $('r-cancel').classList.add('av-edit-visible');
        focusEditCard('av-route-title', 'r-city');
    }

    function cancelRouteEdit() {
        state.editRouteId = 0;
        $('r-origin').disabled = false;
        $('r-origin').value = defaultOrigin();
        renderLocationSelects('', '');
        $('r-address').value = '';
        $('r-km').value = '';
        $('av-route-title').textContent = 'Add a new route';
        $('r-submit').textContent = '+ Add route';
        $('r-cancel').classList.remove('av-edit-visible');
        clearEditMode('av-route-title');
    }

    function deleteRoute(id) {
        var r=routeById(id); if(!r)return;
        if(parseInt(r.trip_count||0,10)>0){ showInfo('Route cannot be deleted','This route is used in trip history and cannot be deleted.'); return; }
        askDelete('Delete route','Are you sure you want to delete this route?',function(){ return runOnce('route-delete-'+id,function(){return api('route_delete','POST',{id:parseInt(id,10)});}).then(function(data){state.routes=data.routes||[];renderRoutes();recalcTrip();toast('Route deleted');}).catch(function(e){toast(e.message);}); });
    }

    /* ---------------- Trips ---------------- */
    function applyTripCreatePermission() {
        var card = $('av-trip-form-card'), access = $('av-trip-access-card'), btn = $('av-request-trip-access'), txt = $('av-trip-access-text');
        if (!card || !access) { return; }
        card.style.display = (state.isAdmin || state.canAddTrip || state.editTripId) ? 'block' : 'none';
        access.style.display = (!state.isAdmin && !state.canAddTrip) ? 'block' : 'none';
        if (!state.isAdmin && !state.canAddTrip) {
            var pending = state.tripRequestStatus === 'pending';
            txt.textContent = pending ? 'Request sent. Awaiting administrator approval.' : 'Request access from an administrator to add trips.';
            btn.style.display = pending ? 'none' : 'inline-block';
        }
    }

    function requestTripAccess(){
        api('trip_access_request','POST',{}).then(function(d){ state.tripRequestStatus=d.status||'pending'; applyTripCreatePermission(); toast('Access request sent'); }).catch(function(e){toast(e.message);});
    }

    function tripRouteLabel(routeName) {
        var raw = String(routeName || '').trim();
        if (!raw) { return '—'; }
        var parts = raw.split(/\s+(?:→|↔)\s+/).map(function(p){ return p.trim(); }).filter(Boolean);
        var shown = parts.length >= 2 ? parts.slice(0, 2) : parts;
        return shown.map(function(p){
            return p.split(/\s+—\s+|\s+•\s+/).map(function(x){return x.trim();}).filter(Boolean).join(' • ');
        }).join(' ↔ ');
    }

    function renderTrips() {
        var body = $('trips-body');
        if (!state.trips.length) { body.innerHTML = '<tr class="av-empty"><td colspan="7">No trips have been added yet</td></tr>'; return; }
        // Trips tab is intentionally a compact dashboard: only the 10 most recent records.
        // Full history and export remain on the Search page.
        var recent = state.trips.slice(0, 10);
        body.innerHTML = recent.map(function (t) {
            return '<tr class="'+(parseInt(state.flashTripId,10)===parseInt(t.id_pk,10)?'av-row-flash':'')+'">' +
                '<td>' + fmtDate(t.date) + '</td>' +
                '<td>' + esc(t.plate_number) + ' <span class="av-hint">' + esc((t.make || '') + ' ' + (t.model || '')) + '</span></td>' +
                '<td><span class="av-tag route">' + esc(tripRouteLabel(t.route_name)) + '</span></td>' +
                '<td class="num av-km-value">' + fmt(t.kilometres) + '</td>' +
                '<td class="num av-fuel-value">' + fmt(t.fuel_calculated) + '</td>' +
                (state.isAdmin ? '<td><button class="av-btn av-btn-small av-edit-black" data-edit-trip="' + t.id_pk + '">Edit</button></td><td><button class="av-btn av-btn-small av-btn-danger" data-del-trip="' + t.id_pk + '">Delete</button></td>' : '<td colspan="2"></td>') +
                '</tr>';
        }).join('');
        if(state.flashTripId){window.setTimeout(function(){state.flashTripId=0;renderTrips();},1400);}
    }

    function recalcTrip() {
        var r = state.tripRouteId ? routeById(state.tripRouteId) : null;
        var v = state.tripVehicleId ? vehicleById(state.tripVehicleId) : null;
        var km = r ? num(r.kilometres) : 0;
        var fuel = v ? num(km * parseFloat(v.litres) / 100) : 0;

        $('calc-km').textContent = fmt(km);
        $('calc-fuel').textContent = fmt(fuel);

        var odo = 0;
        if (v) {
            odo = num(v.odometer);
            if (state.editTripId) {
                for (var i = 0; i < state.trips.length; i++) {
                    var t = state.trips[i];
                    if (parseInt(t.id_pk, 10) === state.editTripId && parseInt(t.vehicle_id, 10) === parseInt(v.id_pk, 10)) {
                        odo -= num(t.kilometres);
                    }
                }
            }
            odo += km;
        }
        $('calc-odo').textContent = fmt(odo);
    }

    function saveTrip() {
        if (!state.tripVehicleId) { toast('Select a vehicle from the list'); return; }
        if (!state.tripRouteId) { toast('Select a route from the list'); return; }

        var payload = {
            vehicle_id: state.tripVehicleId,
            route_id: state.tripRouteId,
            date: $('t-date').value || todayISO()
        };
        var action = state.editTripId ? 'trip_update' : 'trip_create';
        if (state.editTripId) { payload.id = state.editTripId; }
        var btn=$('t-submit'); setBusy(btn,true);
        var editedTripId=state.editTripId;
        runOnce('trip-save',function(){return api(action, 'POST', payload);}).then(function (data) {
            if(!data){return;} state.flashTripId=editedTripId||parseInt(data.id||0,10); state.trips = data.trips || state.trips;
            state.vehicles = data.vehicles || state.vehicles;
            state.routes = data.routes || state.routes;
            cancelTripEdit();
            renderTrips();
            renderVehicles();
            renderRoutes();
            refreshReport();
            toast('Saved');
        }).catch(function (e) { toast(e.message); }).finally(function(){setBusy(btn,false);});
    }

    function editTrip(id) {
        var trip = null;
        for (var i = 0; i < state.trips.length; i++) {
            if (parseInt(state.trips[i].id_pk, 10) === parseInt(id, 10)) { trip = state.trips[i]; }
        }
        if (!trip) { return; }
        state.editTripId = parseInt(id, 10);
        applyTripCreatePermission();
        state.tripVehicleId = parseInt(trip.vehicle_id, 10);
        state.tripRouteId = parseInt(trip.route_id, 10);

        var v = vehicleById(trip.vehicle_id);
        var r = routeById(trip.route_id);
        $('t-vehicle-search').value = v ? v.plate_number : '';
        $('t-route-search').value = r ? tripRouteLabel(r.route_name) : tripRouteLabel(trip.route_name || '');
        $('t-date').value = trip.date;

        $('av-trip-title').textContent = 'Edit trip';
        $('t-submit').textContent = 'Save changes';
        $('t-cancel').style.display = 'inline-block';
        recalcTrip();
        focusEditCard('av-trip-title', 't-vehicle-search');
    }

    function editReportTrip(id) {
        var rows = state.report && Array.isArray(state.report.rows) ? state.report.rows : [];
        var trip = null;
        for (var i = 0; i < rows.length; i++) {
            if (parseInt(rows[i].id_pk, 10) === parseInt(id, 10)) { trip = rows[i]; break; }
        }
        if (!trip) { toast('Trip not found'); return; }

        var exists = false;
        for (var j = 0; j < state.trips.length; j++) {
            if (parseInt(state.trips[j].id_pk, 10) === parseInt(id, 10)) { exists = true; break; }
        }
        if (!exists) {
            state.trips.unshift({
                id_pk: parseInt(trip.id_pk, 10),
                vehicle_id: parseInt(trip.vehicle_id, 10),
                route_id: parseInt(trip.route_id, 10),
                kilometres: trip.kilometres,
                fuel_calculated: trip.fuel_calculated,
                fuel_kind: trip.fuel_kind || '',
                date: trip.date,
                plate_number: trip.plate_number || '',
                make: trip.make || '',
                model: trip.model || '',
                route_name: trip.route_name || ''
            });
        }

        activateTab('trips', true);
        window.setTimeout(function () { editTrip(id); }, 80);
    }

    function cancelTripEdit() {
        state.editTripId = 0;
        state.tripVehicleId = 0;
        state.tripRouteId = 0;
        $('t-vehicle-search').value = '';
        $('t-route-search').value = '';
        $('t-date').value = todayISO();
        $('t-vehicle-results').classList.remove('show');
        $('t-route-results').classList.remove('show');
        $('av-trip-title').textContent = 'Add a new trip';
        $('t-submit').textContent = '+ Add trip';
        $('t-cancel').style.display = 'none';
        recalcTrip();
        applyTripCreatePermission();
        clearEditMode('av-trip-title');
    }

    function deleteTrip(id) {
        askDelete('Delete trip', 'Are you sure you want to delete this trip? Its distance will be subtracted from the vehicle odometer.', function () {
        return runOnce('trip-delete-'+id,function(){return api('trip_delete', 'POST', { id: parseInt(id, 10) });}).then(function (data) {
            state.trips = data.trips || [];
            state.vehicles = data.vehicles || state.vehicles;
            state.routes = data.routes || state.routes;
            if (state.editTripId === parseInt(id, 10)) { cancelTripEdit(); }
            renderTrips();
            renderVehicles();
            renderRoutes();
            recalcTrip();
            refreshReport();
            toast('Trip deleted');
        }).catch(function (e) { toast(e.message); });
        });
    }

    /* ---------------- Search dropdowns ---------------- */
    function openTripVehicleDropdown() {
        var q = $('t-vehicle-search').value.trim().toLowerCase();
        var box = $('t-vehicle-results');
        if (!state.vehicles.length) {
            box.innerHTML = '<div class="av-item disabled">No vehicles have been added yet</div>';
            box.classList.add('show');
            return;
        }
        var availableVehicles = state.vehicles.filter(function(v){ return parseInt(v.is_active,10) === 1 || (state.editTripId && parseInt(v.id_pk,10) === state.tripVehicleId); });
        var list = q ? availableVehicles.filter(function (v) { return matchVehicle(v, q); }) : availableVehicles;
        box.innerHTML = list.length ? list.map(function (v) {
            return '<div class="av-item' + (parseInt(v.id_pk, 10) === state.tripVehicleId ? ' selected' : '') + '" data-pick-vehicle="' + v.id_pk + '">' +
                '<span class="plate">' + esc(v.plate_number) + '</span>' +
                '<span class="meta">' + esc(v.make) + ' ' + esc(v.model || '') + '</span></div>';
        }).join('') : '<div class="av-item disabled">No matches found</div>';
        box.classList.add('show');
    }

    function openTripRouteDropdown() {
        var q = $('t-route-search').value.trim().toLowerCase();
        var box = $('t-route-results');
        if (!state.routes.length) {
            box.innerHTML = '<div class="av-item disabled">No routes have been added yet</div>';
            box.classList.add('show');
            return;
        }
        var availableRoutes = state.routes.slice();
        var list = q ? availableRoutes.filter(function (r) { return r.route_name.toLowerCase().indexOf(q) > -1; }) : availableRoutes;
        box.innerHTML = list.length ? list.map(function (r) {
            return '<div class="av-item' + (parseInt(r.id_pk, 10) === state.tripRouteId ? ' selected' : '') + '" data-pick-route="' + r.id_pk + '">' +
                '<span>' + esc(tripRouteLabel(r.route_name)) + '</span><span class="meta">' + fmt(r.kilometres) + ' km</span></div>';
        }).join('') : '<div class="av-item disabled">No matches found</div>';
        box.classList.add('show');
    }

    function openRepDropdown() {
        var q = $('rep-search').value.trim().toLowerCase();
        var box = $('rep-results');
        var html = '<div class="av-item' + (state.repVehicleId ? '' : ' selected') + '" data-pick-rep="0">' +
            '<span class="plate">All vehicles</span><span class="meta">' + state.vehicles.length + ' vehicles</span></div>';
        var list = q ? state.vehicles.filter(function (v) { return matchVehicle(v, q); }) : state.vehicles;
        html += list.map(function (v) {
            return '<div class="av-item' + (parseInt(v.id_pk, 10) === state.repVehicleId ? ' selected' : '') + '" data-pick-rep="' + v.id_pk + '">' +
                '<span class="plate">' + esc(v.plate_number) + '</span>' +
                '<span class="meta">' + esc(v.make) + ' ' + esc(v.model || '') + '</span></div>';
        }).join('');
        box.innerHTML = html;
        box.classList.add('show');
    }

    function openRepRouteDropdown() {
        var q = $('rep-route-search').value.trim().toLowerCase();
        var box = $('rep-route-results');
        var html = '<div class="av-item' + (state.repRouteId ? '' : ' selected') + '" data-pick-rep-route="0">' +
            '<span>All routes</span><span class="meta">' + state.routes.length + ' routes</span></div>';
        var list = q ? state.routes.filter(function (r) { return String(r.route_name || '').toLowerCase().indexOf(q) > -1; }) : state.routes;
        html += list.map(function (r) {
            return '<div class="av-item' + (parseInt(r.id_pk, 10) === state.repRouteId ? ' selected' : '') + '" data-pick-rep-route="' + r.id_pk + '">' +
                '<span>' + esc(tripRouteLabel(r.route_name)) + '</span><span class="meta">' + fmt(r.kilometres) + ' km</span></div>';
        }).join('');
        box.innerHTML = html;
        box.classList.add('show');
    }

    /* ---------------- Report ---------------- */
    function reportParams(page, all) {
        var params = [];
        if (state.repVehicleId) params.push('vehicle_id=' + state.repVehicleId);
        if (state.repRouteId) params.push('route_id=' + state.repRouteId);
        if ($('rep-fuel-kind') && $('rep-fuel-kind').value) params.push('fuel_kind=' + encodeURIComponent($('rep-fuel-kind').value));
        if ($('rep-from').value) params.push('from=' + encodeURIComponent($('rep-from').value));
        if ($('rep-to').value) params.push('to=' + encodeURIComponent($('rep-to').value));
        if (all) params.push('all=1'); else { params.push('page=' + (page || 1)); params.push('limit=' + (state.pageSizes.report || 10)); }
        return 'report&' + params.join('&');
    }
    function runReport() {
        localStorage.setItem('avtoparki_report_from', $('rep-from').value || '');
        localStorage.setItem('avtoparki_report_to', $('rep-to').value || '');
        localStorage.setItem('avtoparki_report_vehicle', String(state.repVehicleId || 0));
        localStorage.setItem('avtoparki_report_route', String(state.repRouteId || 0));
        localStorage.setItem('avtoparki_report_fuel_kind', $('rep-fuel-kind') ? $('rep-fuel-kind').value : '');
        state.reportPage=1; state.reportLoading=true;
        syncUrlRoute('replace');
        api(reportParams(1,false)).then(function(data){ state.report=data; state.reportHasMore=!!data.has_more; renderReport(); })
        .catch(function(e){toast(e.message);}).then(function(){state.reportLoading=false;});
    }
    function goReportPage(page) {
        if (state.reportLoading || page < 1) return;
        state.reportLoading = true;
        api(reportParams(page,false)).then(function(data){
            state.report = data;
            state.reportPage = parseInt(data.page || page, 10);
            state.reportHasMore = !!data.has_more;
            syncUrlRoute('replace');
            renderReport();
            if ($('rep-card')) $('rep-card').scrollIntoView({behavior:'smooth', block:'start'});
        }).catch(function(e){toast(e.message);}).then(function(){state.reportLoading=false;});
    }

    function refreshReport() { if (state.report) { runReport(); } }

    function setReportPeriod(kind) {
        var today = todayISO();
        var year = today.slice(0, 4);
        var month = today.slice(5, 7);
        if (kind === 'day') {
            $('rep-from').value = today;
            $('rep-to').value = today;
        } else if (kind === 'month') {
            $('rep-from').value = year + '-' + month + '-01';
            $('rep-to').value = today;
        } else if (kind === 'year') {
            $('rep-from').value = year + '-01-01';
            $('rep-to').value = today;
        } else {
            $('rep-from').value = '';
            $('rep-to').value = '';
        }
        runReport();
    }

    function renderReport() {
        var d = state.report || {};
        var rows = Array.isArray(d.rows) ? d.rows : [];
        var v = d.vehicle || null;
        var selectedRoute = state.repRouteId ? routeById(state.repRouteId) : null;
        var titleParts = [v ? vehicleLabel(v) : 'All vehicles'];
        if (selectedRoute) { titleParts.push(tripRouteLabel(selectedRoute.route_name)); }
        $('rep-title').textContent = 'Results — ' + titleParts.join(' • ');

        var cards = '';
        if (v) {
            cards += card('odo', 'Current odometer', fmt(v.odometer) + ' km');
            cards += card('meta', 'Category / fuel rate', esc(v.type_name || '—') + ' · ' + fmt(v.litres) + ' L');
        }
        cards += card('trips', 'Total trips', (d.count !== undefined ? d.count : rows.length));
        cards += card('route', 'Distance traveled in period', fmt(d.total_km) + ' km');
        cards += card('fuel-petrol', 'Petrol', fmt(d.petrol_fuel || 0) + ' L');
        cards += card('fuel-diesel', 'Diesel', fmt(d.diesel_fuel || 0) + ' L');
        cards += card('fuel-total', 'Total fuel', fmt(d.total_fuel || 0) + ' L');
        $('rep-summary').innerHTML = cards;
        if ($('rep-edit-head')) $('rep-edit-head').style.display = state.isAdmin ? '' : 'none';
        if ($('rep-delete-head')) $('rep-delete-head').style.display = state.isAdmin ? '' : 'none';

        $('rep-body').innerHTML = rows.length ? rows.map(function (r) {
            return '<tr>' +
                '<td>' + fmtDate(r.date) + '</td>' +
                '<td>' + esc(r.plate_number) + ' <span class="av-hint">' + esc((r.make || '') + ' ' + (r.model || '')) + '</span></td>' +
                '<td class="av-fuel-kind">' + esc(r.fuel_kind || '—') + '</td>' +
                '<td><span class="av-tag route">' + esc(tripRouteLabel(r.route_name)) + '</span></td>' +
                '<td class="num av-km-value">' + fmt(r.kilometres) + '</td>' +
                '<td class="num av-fuel-value">' + fmt(r.fuel_calculated) + '</td>' +
                (state.isAdmin ? '<td><button class="av-btn av-btn-small av-edit-black" data-edit-report-trip="' + r.id_pk + '">Edit</button></td><td><button class="av-btn av-btn-small av-btn-danger" data-del-report-trip="' + r.id_pk + '">Delete</button></td>' : '') +
                '</tr>';
        }).join('') : '<tr class="av-empty"><td colspan="6">No trips found for this period</td></tr>';

        $('rep-card').style.display = 'block';
        renderReportPagination(d);
    }

    function renderReportPagination(d){
        var box=$('rep-pagination'); if(!box) return;
        var count=parseInt(d.count||0,10), limit=parseInt(d.limit||state.pageSizes.report||10,10), page=parseInt(d.page||state.reportPage||1,10);
        state.reportPage=page; state.pageSizes.report=limit;
        var pages=Math.max(1,Math.ceil(count/limit));
        if(!count){box.innerHTML='';box.style.display='none';return;}
        box.style.display='flex';
        var first=(page-1)*limit+1, last=Math.min(page*limit,count);
        var items=pagerPages(page,pages), nav='';
        items.forEach(function(i){nav += i==='…' ? '<span class="av-page-dots">…</span>' : '<button type="button" data-report-page="'+i+'" class="'+(i===page?'active':'')+'">'+i+'</button>';});
        box.innerHTML='<span class="av-page-range">'+first+'–'+last+' / '+count+'</span><div class="av-page-nav"><button type="button" data-report-page="'+Math.max(1,page-1)+'" '+(page<=1?'disabled':'')+' aria-label="Previous page">‹</button>'+nav+'<button type="button" data-report-page="'+Math.min(pages,page+1)+'" '+(page>=pages?'disabled':'')+' aria-label="Next page">›</button></div><label class="av-page-size-label">Rows per page: <select class="av-page-size" data-page-size-key="report" aria-label="Rows per page"><option>10</option><option>20</option><option>50</option></select></label>';
        syncPageSizeSelects('report');
        box.querySelectorAll('[data-report-page]').forEach(function(btn){btn.addEventListener('click',function(){goReportPage(parseInt(this.getAttribute('data-report-page'),10));});});
    }

    function card(cls, k, v) {
        return '<div class="av-sum-card ' + cls + '"><div class="k">' + k + '</div><div class="v">' + v + '</div></div>';
    }

    /* ---------------- Excel ---------------- */
    function exportExcel() {
        if (!state.report) { toast('Generate a report first'); return; }
        api(reportParams(1,true)).then(function(data){exportExcelData(data||{});}).catch(function(e){toast(e.message);});
    }

    function exportExcelData(d) {
        var rows = Array.isArray(d.rows) ? d.rows : [];
        var v = d.vehicle || null;
        var selectedRoute = state.repRouteId ? routeById(state.repRouteId) : null;
        var from = $('rep-from').value;
        var to = $('rep-to').value;

        var aoa = [];
        aoa.push(['Fleet fuel report']);
        aoa.push(['Vehicle', v ? vehicleLabel(v) : 'All vehicles']);
        aoa.push(['Route', selectedRoute ? tripRouteLabel(selectedRoute.route_name) : 'All routes']);
        aoa.push(['Period', (from ? fmtDate(from) : 'Beginning') + ' — ' + (to ? fmtDate(to) : 'Today')]);
        aoa.push(['Generated on', fmtDate(todayISO())]);
        if (v) {
            aoa.push(['Category / fuel rate', (v.type_name || '') + ' · ' + num(v.litres) + ' L/100km']);
            aoa.push(['Current odometer (km)', num(v.odometer)]);
        }
        aoa.push(['Total trips', d.count]);
        aoa.push(['Petrol (L)', num(d.petrol_fuel || 0)]);
        aoa.push(['Diesel (L)', num(d.diesel_fuel || 0)]);
        aoa.push(['Total fuel (L)', num(d.total_fuel || 0)]);
        aoa.push([]);
        aoa.push(['Date', 'License plate', 'Vehicle', 'Fuel type', 'Route', 'km', 'Fuel consumed (L)']);
        rows.forEach(function (r) {
            aoa.push([
                fmtDate(r.date),
                r.plate_number,
                ((r.make || '') + ' ' + (r.model || '')).trim(),
                r.fuel_kind || '',
                tripRouteLabel(r.route_name),
                num(r.kilometres),
                num(r.fuel_calculated)
            ]);
        });
        aoa.push([]);
        aoa.push(['', '', '', '', 'Distance traveled', num(d.total_km), '']);
        aoa.push(['', '', '', 'Petrol', '', '', num(d.petrol_fuel || 0)]);
        aoa.push(['', '', '', 'Diesel', '', '', num(d.diesel_fuel || 0)]);
        aoa.push(['', '', '', 'Total fuel', '', '', num(d.total_fuel || 0)]);

        var name = 'fleet_report_' + (v ? v.plate_number.replace(/[^A-Za-z0-9]/g, '') : 'all_vehicles') + '_' + todayISO();

        if (typeof XLSX !== 'undefined') {
            var ws = XLSX.utils.aoa_to_sheet(aoa);
            ws['!cols'] = [{ wch: 12 }, { wch: 14 }, { wch: 22 }, { wch: 16 }, { wch: 34 }, { wch: 10 }, { wch: 20 }];
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Report');
            XLSX.writeFile(wb, name + '.xlsx');
        } else {
            var html = '<html><head><meta charset="utf-8"></head><body><table>' +
                aoa.map(function (row) {
                    return '<tr>' + row.map(function (c) { return '<td>' + esc(c) + '</td>'; }).join('') + '</tr>';
                }).join('') + '</table></body></html>';
            var blob = new Blob(['\ufeff' + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = name + '.xls';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        toast('File downloaded');
    }


    function renderUsers(users) {
        var box = $('av-user-list'); if (!box) { return; }
        state.users = users || state.users || [];
        var ordinaryUsers = state.users.filter(function (u) { return parseInt(u.is_admin, 10) !== 1; });
        if (!ordinaryUsers.length) {
            box.innerHTML = '<div class="av-user-empty av-empty-neutral">No users have been added yet</div>'; return;
        }
        box.innerHTML = ordinaryUsers.map(function (u) {
            var admin = false, active = parseInt(u.is_active, 10) === 1;
            var permission = admin ? '<span class="av-user-admin-perm">All permissions</span>' :
                '<label class="av-switch av-user-perm"><input type="checkbox" data-user-trip-permission="' + u.id_pk + '" ' + (parseInt(u.can_add_trip,10)===1?'checked':'') + '><span class="av-switch-track" aria-hidden="true"></span><span>Add trips</span></label>';
            var actions = admin ? '' : '<button class="av-btn av-btn-small av-edit-black" data-user-password-reset="' + u.id_pk + '" data-user-name="' + esc(u.username) + '">Reset password</button><button class="av-btn av-btn-small av-btn-danger" data-user-delete="' + u.id_pk + '" data-user-name="' + esc(u.username) + '">Delete</button>';
            return '<div class="av-user-row"><div class="av-user-main"><div class="av-user-name">' + esc(u.username) + '</div><div class="av-user-meta">Active account</div></div><div class="av-user-controls">' + permission + actions + '</div></div>';
        }).join('');
    }
    function loadUsers(){ return api('users').then(function(d){ renderUsers(d.users); }); }
    function displayUsername(name){ name=String(name||''); return name ? name.charAt(0).toUpperCase()+name.slice(1).toLowerCase() : ''; }

    function renderAccessRequests(reqs){ var card=$('av-access-requests-card'), box=$('av-access-request-list'), count=$('av-request-count'), tab=$('av-manage-tab'), tabCount=$('av-tab-request-count'); if(!card||!box)return; reqs=reqs||[]; count.textContent=reqs.length; if(tab){ tab.classList.toggle('has-notification', reqs.length>0); } if(tabCount){ tabCount.textContent=String(reqs.length); tabCount.style.display=reqs.length?'inline-flex':'none'; } card.style.display=reqs.length?'block':'none'; box.innerHTML=reqs.map(function(r){return '<div class="av-request-row"><div><strong>'+esc(r.username)+'</strong><div class="av-user-meta">Requests permission to add trips</div></div><div class="av-request-actions"><button class="av-btn av-btn-small av-btn-approve" data-request-approve="'+r.id_pk+'">Approve</button><button class="av-btn av-btn-small av-btn-reject" data-request-reject="'+r.id_pk+'">Reject</button></div></div>';}).join(''); }
    function loadAccessRequests(){return api('access_requests').then(function(d){renderAccessRequests(d.requests);});}
    function pollAccessRequests(){
        if(!state.isAdmin || state.accessPollBusy || document.hidden){return;}
        state.accessPollBusy=true;
        loadAccessRequests().catch(function(){}).finally(function(){state.accessPollBusy=false;});
    }
    function startAccessRequestPolling(){
        if(state.accessPollTimer){clearInterval(state.accessPollTimer);state.accessPollTimer=null;}
        if(!state.isAdmin){return;}
        pollAccessRequests();
        state.accessPollTimer=setInterval(pollAccessRequests,5000);
    }
    function pollSession(){
        if(!state.user || state.sessionPollBusy || document.hidden){return;}
        state.sessionPollBusy=true;
        api('session_check').then(function(d){
            if(!d.authenticated){window.location.reload();}
        }).catch(function(){}).finally(function(){state.sessionPollBusy=false;});
    }
    function startSessionPolling(){
        if(state.sessionPollTimer){clearInterval(state.sessionPollTimer);state.sessionPollTimer=null;}
        if(!state.user){return;}
        state.sessionPollTimer=setInterval(pollSession,5000);
    }
    function resetUserPassword(id,name){
        state.passwordResetUserId=parseInt(id,10)||0; state.passwordResetUserName=String(name||'');
        $('upr-user').textContent='User: '+state.passwordResetUserName;
        $('upr-password').value=''; $('upr-error').textContent=''; $('av-user-password-modal').style.display='flex';
        window.setTimeout(function(){$('upr-password').focus();},50);
    }
    function closeUserPasswordReset(){state.passwordResetUserId=0;state.passwordResetUserName='';$('upr-password').value='';$('upr-error').textContent='';$('av-user-password-modal').style.display='none';}
    function saveUserPasswordReset(){
        var id=state.passwordResetUserId,password=$('upr-password').value;
        if(!id){return;} if(!password){$('upr-error').textContent='Enter a temporary password';return;}
        var btn=$('upr-save');setBusy(btn,true);
        runOnce('user-password-reset-'+id,function(){return api('user_password_reset','POST',{id:id,password:password});}).then(function(d){if(!d)return;renderUsers(d.users);closeUserPasswordReset();toast('Temporary password set');}).catch(function(e){$('upr-error').textContent=e.message;}).finally(function(){setBusy(btn,false);});
    }
    function decideAccessRequest(id,approve){api('access_request_decide','POST',{id:parseInt(id,10),approve:approve}).then(function(d){renderAccessRequests(d.requests);renderUsers(d.users);toast(approve?'Access approved':'Request rejected');}).catch(function(e){toast(e.message);});}
    function openManage(){ if(!state.isAdmin){toast('Only an administrator can manage users');return;} var box=$('av-user-list'); $('av-user-error').textContent=''; if(box){box.innerHTML='<div class="av-user-loading">Loading users…</div>';} Promise.all([loadUsers(),loadAccessRequests()]).catch(function(e){ if(box){box.innerHTML='<div class="av-user-empty">Unable to load users.</div>';} $('av-user-error').textContent=e.message; }); }
    function closeManage(){}
    function createUser(){
        $('av-user-error').textContent='';
        var btn=$('u-create'); setBusy(btn,true);
        runOnce('user-create',function(){ return api('user_create','POST',{username:$('u-name').value.trim(),password:$('u-pass').value,can_add_trip:false}); }).then(function(d){
            if(!d){return;} $('u-name').value=''; $('u-pass').value=''; renderUsers(d.users); toast('User added');
        }).catch(function(e){$('av-user-error').textContent=e.message;}).finally(function(){setBusy(btn,false);});
    }
    function toggleUser(id,active){runOnce('user-toggle-'+id,function(){return api('user_toggle','POST',{id:parseInt(id,10),active:active==='1'});}).then(function(d){if(!d)return;renderUsers(d.users);toast('User status updated');}).catch(function(e){toast(e.message);});}
    function deleteUser(id,name){ askDelete('Delete user','Are you sure you want to delete user “'+name+'”?',function(){ return runOnce('user-delete-'+id,function(){return api('user_delete','POST',{id:parseInt(id,10)});}).then(function(d){if(!d)return;renderUsers(d.users);toast('User deleted');}).catch(function(e){toast(e.message);}); }); }
    function setTripPermission(id, allowed){runOnce('user-perm-'+id,function(){return api('user_permission','POST',{id:parseInt(id,10),can_add_trip:allowed});}).then(function(d){if(!d)return;renderUsers(d.users);toast('Permission updated');}).catch(function(e){toast(e.message);loadUsers();});}

    function resetTabState() {
        cancelVehicleEdit();
        cancelRouteEdit();
        cancelTripEdit();
        state.repVehicleId = 0;
        state.repRouteId = 0;
        if ($('rep-search')) $('rep-search').value = '';
        if ($('rep-route-search')) $('rep-route-search').value = '';
        if ($('rep-fuel-kind')) $('rep-fuel-kind').value = '';
        if ($('rep-from')) $('rep-from').value = '';
        if ($('rep-to')) $('rep-to').value = '';
        if ($('rep-results')) $('rep-results').classList.remove('show');
        if ($('rep-route-results')) $('rep-route-results').classList.remove('show');
        if ($('rep-card')) $('rep-card').style.display = 'none';
        if ($('u-name')) $('u-name').value = '';
        if ($('u-pass')) $('u-pass').value = '';
        if ($('av-user-error')) $('av-user-error').textContent = '';
    }

    function followUrlRoute() {
        if (!state.user || $('av-app').style.display === 'none') { return; }
        var urlRoute = readUrlRoute();
        if (!urlRoute.tab) { return; }
        resetTabState();
        state.applyingRoute = true;
        applyUrlRoute(urlRoute);
        renderVehicles();
        renderRoutes();
        if (!activateTab(urlRoute.tab, false)) { activateTab('trips', false); }
        state.applyingRoute = false;
        localStorage.setItem('avtoparki_active_tab', state.activeTab);
        syncUrlRoute('replace');
    }

    /* ---------------- events ---------------- */
    function bind() {
        window.addEventListener('hashchange', followUrlRoute);
        window.addEventListener('focus', function(){pollAccessRequests();pollSession();});
        window.addEventListener('online', function(){pollAccessRequests();pollSession();});
        document.addEventListener('visibilitychange', function(){ if(!document.hidden){pollAccessRequests();pollSession();} });
        document.addEventListener('click', function(e){
            var btn=e.target.closest ? e.target.closest('[data-password-toggle]') : null;
            if(!btn){ return; }
            var input=$(btn.getAttribute('data-password-toggle'));
            if(!input){ return; }
            var showing=input.type==='text';
            input.type=showing?'password':'text';
            var nowVisible=!showing;
            // Keep both SVGs mounted; only switch state. This avoids DOM replacement/state drift.
            btn.classList.toggle('is-visible', nowVisible);
            btn.setAttribute('aria-label',showing?'Show password':'Hide password');
            btn.setAttribute('title',showing?'Show password':'Hide password');
        });
        // Tabs
        Array.prototype.forEach.call(document.querySelectorAll('.av-tab[data-tab]'), function (btn) {
            btn.addEventListener('click', function () {
                resetTabState();
                activateTab(btn.getAttribute('data-tab'), true);
            });
        });

        $('trips-more').addEventListener('click', function () {
            var lookupTab = document.querySelector('.av-tab[data-tab="lookup"]');
            if (!lookupTab) { return; }
            resetTabState();
            var today = todayISO();
            var d = new Date(today + 'T12:00:00'); d.setMonth(d.getMonth()-1);
            var monthAgo = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
            $('rep-from').value = monthAgo;
            $('rep-to').value = today;
            state.openLookupMonth = true;
            state.repVehicleId = 0;
            state.repRouteId = 0;
            $('rep-search').value = '';
            $('rep-route-search').value = '';
            if ($('rep-fuel-kind')) $('rep-fuel-kind').value = '';
            localStorage.setItem('avtoparki_report_from', monthAgo);
            localStorage.setItem('avtoparki_report_to', today);
            localStorage.setItem('avtoparki_report_vehicle', '0');
            localStorage.setItem('avtoparki_report_route', '0');
            localStorage.setItem('avtoparki_report_fuel_kind', '');
            activateTab('lookup', true);
            runReport();
        });

        $('av-login-btn').addEventListener('click', doLogin);
        $('av-login-pass').addEventListener('keydown', function (e) { if (e.key === 'Enter') { doLogin(); } });

        $('p-submit').addEventListener('click', savePassword);
        $('p-new').addEventListener('input', function () {
            $('av-pass-error').textContent = passwordRequirementMessage(this.value);
        });
        $('p-cancel').addEventListener('click', closePassword);
        $('p-logout').addEventListener('click', logout);
        $('upr-close').addEventListener('click', closeUserPasswordReset);
        $('upr-cancel').addEventListener('click', closeUserPasswordReset);
        $('upr-save').addEventListener('click', saveUserPasswordReset);
        $('upr-password').addEventListener('keydown',function(e){if(e.key==='Enter'){saveUserPasswordReset();}});
        $('u-create').onclick = function (e) { e.preventDefault(); createUser(); };
        $('av-request-trip-access').addEventListener('click', requestTripAccess);

        $('av-confirm-no').addEventListener('click', closeConfirm);
        $('av-confirm-close').addEventListener('click', closeConfirm);
        $('av-confirm-yes').addEventListener('click', function () { var fn = state.confirmAction; if (!fn || this.disabled) { return; } this.disabled=true; closeConfirm(); Promise.resolve(fn()).finally(function(){ $('av-confirm-yes').disabled=false; }); });

        $('v-plate').addEventListener('input', function () { forceLatinPlate(this); });
        $('v-submit').onclick = function (e) { e.preventDefault(); saveVehicle(); };
        if ($('vehicle-status-filter')) $('vehicle-status-filter').addEventListener('change', function(){state.pages.vehicles=1;renderVehicles();syncUrlRoute('replace');});
        $('v-cancel').addEventListener('click', cancelVehicleEdit);

        if ($('r-origin')) { $('r-origin').addEventListener('input', openRouteOriginDropdown); $('r-origin').addEventListener('focus', openRouteOriginDropdown); }
        if ($('r-city')) { $('r-city').addEventListener('input', function(){ syncRouteDistrictState(true); openRouteCityDropdown(); }); $('r-city').addEventListener('focus', openRouteCityDropdown); }
        if ($('r-district')) { $('r-district').addEventListener('input', openRouteDistrictDropdown); $('r-district').addEventListener('focus', openRouteDistrictDropdown); }


        $('r-submit').addEventListener('click', saveRoute);
        $('r-cancel').addEventListener('click', cancelRouteEdit);

        $('t-submit').onclick = function (e) { e.preventDefault(); saveTrip(); };
        $('t-cancel').addEventListener('click', cancelTripEdit);

        // Enter submits add/edit forms just like the visible action button.
        function bindEnterSubmit(containerId, action) {
            var container = $(containerId);
            if (!container) { return; }
            container.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || e.isComposing || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey) { return; }
                var target = e.target;
                if (!target || !/^(INPUT|SELECT)$/.test(target.tagName)) { return; }
                if (target.tagName === 'INPUT' && !/^(text|password|number|date|search|email)$/i.test(target.type || 'text')) { return; }
                e.preventDefault();
                action();
            });
        }
        bindEnterSubmit('av-trip-form-card', saveTrip);
        bindEnterSubmit('av-panel-vehicles', saveVehicle);
        bindEnterSubmit('av-panel-routes', saveRoute);
        bindEnterSubmit('av-panel-users', createUser);

        $('t-vehicle-search').addEventListener('input', function () {
            var v = state.tripVehicleId ? vehicleById(state.tripVehicleId) : null;
            if (!v || v.plate_number !== this.value.trim()) { state.tripVehicleId = 0; }
            openTripVehicleDropdown();
            recalcTrip();
        });
        $('t-vehicle-search').addEventListener('focus', openTripVehicleDropdown);

        $('t-route-search').addEventListener('input', function () {
            var r = state.tripRouteId ? routeById(state.tripRouteId) : null;
            if (!r || tripRouteLabel(r.route_name) !== this.value.trim()) { state.tripRouteId = 0; }
            openTripRouteDropdown();
            recalcTrip();
        });
        $('t-route-search').addEventListener('focus', openTripRouteDropdown);

        $('rep-search').addEventListener('input', function () {
            var v = state.repVehicleId ? vehicleById(state.repVehicleId) : null;
            if (!v || v.plate_number !== this.value.trim()) { state.repVehicleId = 0; }
            openRepDropdown();
            syncUrlRoute('replace');
        });
        $('rep-search').addEventListener('focus', openRepDropdown);

        $('rep-route-search').addEventListener('input', function () {
            var r = state.repRouteId ? routeById(state.repRouteId) : null;
            if (!r || tripRouteLabel(r.route_name) !== this.value.trim()) { state.repRouteId = 0; }
            openRepRouteDropdown();
            syncUrlRoute('replace');
        });
        $('rep-route-search').addEventListener('focus', openRepRouteDropdown);

        $('rep-run').addEventListener('click', runReport);
        $('rep-day').addEventListener('click', function () { setReportPeriod('day'); });
        $('rep-month').addEventListener('click', function () { setReportPeriod('month'); });
        $('rep-year').addEventListener('click', function () { setReportPeriod('year'); });
        $('rep-clear').addEventListener('click', function () { setReportPeriod('all'); });
        $('rep-export').addEventListener('click', exportExcel);
        $('rep-from').addEventListener('change', function(){ syncUrlRoute('replace'); });
        $('rep-to').addEventListener('change', function(){ syncUrlRoute('replace'); });

        document.addEventListener('change', function(e){ if(e.target && e.target.classList && e.target.classList.contains('av-page-size')){ setPageSize(e.target.getAttribute('data-page-size-key'),e.target.value); } });
        if($('vehicle-list-search')) $('vehicle-list-search').addEventListener('input',function(){forceLatinPlate(this);state.pages.vehicles=1;renderVehicles();syncUrlRoute('replace');});
        if($('route-list-search')) $('route-list-search').addEventListener('input',function(){state.pages.routes=1;renderRoutes();var c=$('route-list-search-clear');if(c)c.classList.toggle('show',!!this.value);syncUrlRoute('replace');});
        if($('route-list-search-clear')) $('route-list-search-clear').addEventListener('click',function(){var i=$('route-list-search');if(!i)return;i.value='';i.dispatchEvent(new Event('input',{bubbles:true}));i.focus();});

        // One-click clear button. The dedicated input shell keeps × centered even when an autocomplete dropdown opens.
        ['t-vehicle-search','t-route-search','rep-search','rep-route-search','vehicle-list-search','r-origin','r-city','r-district','u-name','v-make','v-model'].forEach(function(id){
            var input=$(id); if(!input || input.dataset.clearReady==='1') return;
            input.dataset.clearReady='1';
            input.classList.add('av-search-clear-input');
            var shell=document.createElement('span');
            shell.className='av-search-clear-wrap';
            input.parentNode.insertBefore(shell,input);
            shell.appendChild(input);
            var clear=document.createElement('button');
            clear.type='button'; clear.className='av-search-clear'; clear.setAttribute('aria-label','Clear'); clear.setAttribute('title','Clear'); clear.textContent='×';
            function sync(){ clear.classList.toggle('show', !!input.value); }
            clear.addEventListener('click',function(e){
                e.preventDefault(); e.stopPropagation(); input.value='';
                input.dispatchEvent(new Event('input',{bubbles:true})); input.focus(); sync();
            });
            input.addEventListener('input',sync); shell.appendChild(clear); sync();
        });
        document.addEventListener('click',function(e){var p=e.target.closest?e.target.closest('[data-page-key]'):null;if(!p)return;state.pages[p.getAttribute('data-page-key')]=parseInt(p.getAttribute('data-page'),10)||1;var k=p.getAttribute('data-page-key');if(k==='vehicles')renderVehicles();else if(k==='routes')renderRoutes();syncUrlRoute('replace');});

        // Delegated clicks
        document.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest('[data-request-approve],[data-request-reject],[data-user-password-reset],[data-user-delete],[data-user-trip-permission],[data-edit-vehicle],[data-del-vehicle],[data-status-vehicle],[data-edit-route],[data-del-route],[data-edit-trip],[data-del-trip],[data-edit-report-trip],[data-del-report-trip],[data-pick-vehicle],[data-pick-route],[data-pick-rep],[data-pick-rep-route],[data-pick-location-city],[data-pick-location-district],[data-add-location-city],[data-add-location-district],[data-pick-route-origin],[data-add-route-origin]') : null;
            if (el) {
                if (el.hasAttribute('data-request-approve')) { decideAccessRequest(el.getAttribute('data-request-approve'), true); }
                else if (el.hasAttribute('data-request-reject')) { decideAccessRequest(el.getAttribute('data-request-reject'), false); }
                else if (el.hasAttribute('data-user-password-reset')) { resetUserPassword(el.getAttribute('data-user-password-reset'), el.getAttribute('data-user-name')); }
                else if (el.hasAttribute('data-user-delete')) { deleteUser(el.getAttribute('data-user-delete'), el.getAttribute('data-user-name')); }
                else if (el.hasAttribute('data-user-trip-permission')) { setTripPermission(el.getAttribute('data-user-trip-permission'), !!el.checked); }
                else if (el.hasAttribute('data-edit-vehicle')) { if(el.getAttribute('data-action-enabled')==='1') editVehicle(el.getAttribute('data-edit-vehicle')); }
                else if (el.hasAttribute('data-del-vehicle')) { if(el.getAttribute('data-action-enabled')==='1') deleteVehicle(el.getAttribute('data-del-vehicle')); }
                else if (el.hasAttribute('data-status-vehicle')) { setVehicleStatus(el.getAttribute('data-status-vehicle'), el.getAttribute('data-next-active') === '1'); }
                else if (el.hasAttribute('data-pick-location-city')) { $('r-city').value=el.getAttribute('data-pick-location-city'); $('r-city-results').classList.remove('show'); syncRouteDistrictState(true); $('r-district').focus(); }
                else if (el.hasAttribute('data-pick-location-district')) { $('r-district').value=el.getAttribute('data-pick-location-district'); $('r-district-results').classList.remove('show'); }
                else if (el.hasAttribute('data-add-location-city')) { var n=el.getAttribute('data-add-location-city'); api('location_city_add','POST',{name:n}).then(function(data){state.locations=data.locations||state.locations;$('r-city').value=n;$('r-city-results').classList.remove('show');syncRouteDistrictState(true);toast('City or town added');}).catch(function(e){toast(e.message);}); }
                else if (el.hasAttribute('data-add-location-district')) { var d=el.getAttribute('data-add-location-district'), c=$('r-city').value.trim(); api('location_district_add','POST',{city:c,name:d}).then(function(data){state.locations=data.locations||state.locations;$('r-district').value=d;$('r-district-results').classList.remove('show');toast('District added');}).catch(function(e){toast(e.message);}); }
                else if (el.hasAttribute('data-pick-route-origin') || el.hasAttribute('data-add-route-origin')) { $('r-origin').value=el.getAttribute(el.hasAttribute('data-pick-route-origin')?'data-pick-route-origin':'data-add-route-origin')||''; $('r-origin-results').classList.remove('show'); }
                else if (el.hasAttribute('data-edit-route')) { editRoute(el.getAttribute('data-edit-route')); }
                else if (el.hasAttribute('data-del-route')) { deleteRoute(el.getAttribute('data-del-route')); }
                else if (el.hasAttribute('data-edit-trip')) { editTrip(el.getAttribute('data-edit-trip')); }
                else if (el.hasAttribute('data-del-trip')) { deleteTrip(el.getAttribute('data-del-trip')); }
                else if (el.hasAttribute('data-edit-report-trip')) { editReportTrip(el.getAttribute('data-edit-report-trip')); }
                else if (el.hasAttribute('data-del-report-trip')) { deleteTrip(el.getAttribute('data-del-report-trip')); }
                else if (el.hasAttribute('data-pick-vehicle')) {
                    var v = vehicleById(el.getAttribute('data-pick-vehicle'));
                    if (v) {
                        state.tripVehicleId = parseInt(v.id_pk, 10);
                        $('t-vehicle-search').value = v.plate_number;
                        $('t-vehicle-results').classList.remove('show');
                        recalcTrip();
                    }
                } else if (el.hasAttribute('data-pick-route')) {
                    var r = routeById(el.getAttribute('data-pick-route'));
                    if (r) {
                        state.tripRouteId = parseInt(r.id_pk, 10);
                        $('t-route-search').value = tripRouteLabel(r.route_name);
                        $('t-route-results').classList.remove('show');
                        recalcTrip();
                    }
                } else if (el.hasAttribute('data-pick-rep')) {
                    var id = parseInt(el.getAttribute('data-pick-rep'), 10);
                    state.repVehicleId = id;
                    var rv = id ? vehicleById(id) : null;
                    $('rep-search').value = rv ? rv.plate_number : '';
                    $('rep-results').classList.remove('show');
                    runReport();
                } else if (el.hasAttribute('data-pick-rep-route')) {
                    var routeId = parseInt(el.getAttribute('data-pick-rep-route'), 10);
                    state.repRouteId = routeId;
                    var reportRoute = routeId ? routeById(routeId) : null;
                    $('rep-route-search').value = reportRoute ? tripRouteLabel(reportRoute.route_name) : '';
                    $('rep-route-results').classList.remove('show');
                    runReport();
                }
                return;
            }

            // Close dropdowns
            [['t-vehicle-results', 't-vehicle-search'], ['t-route-results', 't-route-search'], ['rep-results', 'rep-search'], ['rep-route-results', 'rep-route-search'], ['r-origin-results','r-origin'], ['r-city-results','r-city'], ['r-district-results','r-district']]
                .forEach(function (pair) {
                    var box = $(pair[0]), inp = $(pair[1]);
                    if (box && inp && !box.contains(e.target) && e.target !== inp) { box.classList.remove('show'); }
                });
        });
    }

    /* ---------------- init ---------------- */
    function init() {
        if (!$('avtoparki-root')) { return; }
        bind();
        $('t-date').value = todayISO();
        if ($('av-today')) $('av-today').textContent = fmtDate(todayISO());

        var today = todayISO();
        $('rep-from').value = today;
        $('rep-to').value = today;
        if ($('rep-fuel-kind')) $('rep-fuel-kind').value = '';

        api('me').then(function (me) {
            if (me.authenticated) {
                state.user = me.user || '';
                state.isAdmin = !!me.is_admin;
                state.mustChange = !!me.must_change;
                state.canAddTrip = !!(me.permissions && me.permissions.trip_create);
                state.tripRequestStatus = me.trip_request_status || 'none';
                // Keep the session on refresh. Require temporary passwords to be changed first.
                if (state.mustChange) {
                    $('av-login').style.display = 'none';
                    $('av-app').style.display = 'none';
                    openPassword(true);
                    return;
                }
                showApp();
                bootstrap();
            } else {
                showLogin();
            }
        }).catch(function () { showLogin(); });
    }

    document.addEventListener('DOMContentLoaded', init);

    return {
        logout: logout,
        openPassword: openPassword,
        openSettings: openPassword,
        closePassword: closePassword,
        openManage: openManage,
        closeManage: closeManage
    };
})();
