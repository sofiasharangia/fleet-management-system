<?php
/**
 * config.php — SQLite connection and automatic schema setup.
 * The database is stored in a single file in data/. No separate database server is needed.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Tbilisi');

/*
 * The database directory defaults to ./data.
 * Override AVTOPARKI_DATA_DIR to store data outside the public directory,
 * for example: AVTOPARKI_DATA_DIR=/path/to/private/fleet-data
 */
$avtoparkiDataDir = getenv('AVTOPARKI_DATA_DIR');
define('AVTOPARKI_DATA_DIR', ($avtoparkiDataDir !== false && $avtoparkiDataDir !== '')
    ? rtrim($avtoparkiDataDir, '/')
    : __DIR__ . '/data');
define('AVTOPARKI_DB_PATH', AVTOPARKI_DATA_DIR . '/avtoparki.sqlite');
define('AVTOPARKI_VERSION', 'production');
define('AVTOPARKI_SESSION_VERSION', 'production-auth');
define('AVTOPARKI_IDLE_TIMEOUT', 3600);

function avtoparki_enforce_idle_timeout(bool $touch = true): void {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['avtoparki_user_id'])) { return; }
    $now = time();
    $last = (int)($_SESSION['avtoparki_last_activity'] ?? $now);
    if (($now - $last) >= AVTOPARKI_IDLE_TIMEOUT) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) { $cp=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$cp['path'],$cp['domain'],$cp['secure'],$cp['httponly']); }
        session_destroy();
        return;
    }
    if ($touch) { $_SESSION['avtoparki_last_activity'] = $now; }
}

function avtoparki_session_migrate(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { return; }
    if (($_SESSION['avtoparki_session_version'] ?? '') !== AVTOPARKI_SESSION_VERSION) {
        // Keep sessions consistent with the current application runtime.
        unset($_SESSION['avtoparki_user_id']);
        $_SESSION['avtoparki_session_version'] = AVTOPARKI_SESSION_VERSION;
    }
}

function avtoparki_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (! is_dir(AVTOPARKI_DATA_DIR)) {
        mkdir(AVTOPARKI_DATA_DIR, 0775, true);
    }

    $pdo = new PDO('sqlite:' . AVTOPARKI_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    avtoparki_install_schema($pdo);

    return $pdo;
}

function avtoparki_install_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel (
        id_pk INTEGER PRIMARY KEY AUTOINCREMENT,
        type_name TEXT NOT NULL UNIQUE,
        litres REAL NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle (
        id_pk INTEGER PRIMARY KEY AUTOINCREMENT,
        make TEXT NOT NULL,
        model TEXT NOT NULL DEFAULT '',
        plate_number TEXT NOT NULL UNIQUE,
        type_id INTEGER NOT NULL REFERENCES fuel(id_pk),
        fuel_kind TEXT NOT NULL DEFAULT '',
        initial_odometer REAL NOT NULL DEFAULT 0,
        odometer REAL NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS route (
        id_pk INTEGER PRIMARY KEY AUTOINCREMENT,
        route_name TEXT NOT NULL UNIQUE,
        city TEXT NOT NULL DEFAULT '',
        address TEXT NOT NULL DEFAULT '',
        kilometres REAL NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    // Ensure route location fields are available.
    $routeCols = array_column($pdo->query("PRAGMA table_info(route)")->fetchAll(), 'name');
    if (!in_array('city', $routeCols, true)) { $pdo->exec("ALTER TABLE route ADD COLUMN city TEXT NOT NULL DEFAULT ''"); }
    if (!in_array('address', $routeCols, true)) { $pdo->exec("ALTER TABLE route ADD COLUMN address TEXT NOT NULL DEFAULT ''"); }

    /*
     * Each trip stores its distance at creation time so route changes cannot
     * alter historical trips or odometer contributions.
     */
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_setting (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS location_city (
        id_pk INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS location_district (
        id_pk INTEGER PRIMARY KEY AUTOINCREMENT,
        city_id INTEGER NOT NULL REFERENCES location_city(id_pk) ON DELETE CASCADE,
        name TEXT NOT NULL,
        UNIQUE(city_id, name)
    )");

    // Destination metadata used by routes. Address is intentionally optional.
    $routeCols2 = array_column($pdo->query("PRAGMA table_info(route)")->fetchAll(), 'name');
    if (!in_array('district', $routeCols2, true)) { $pdo->exec("ALTER TABLE route ADD COLUMN district TEXT NOT NULL DEFAULT ''"); }
    if (!in_array('origin_label', $routeCols2, true)) { $pdo->exec("ALTER TABLE route ADD COLUMN origin_label TEXT NOT NULL DEFAULT ''"); }
    // Preserve each existing route's historical origin before Settings is retired.
    $legacySettings = array();
    foreach ($pdo->query("SELECT setting_key, setting_value FROM app_setting WHERE setting_key IN ('office_city','office_district','office_address')")->fetchAll() as $row) { $legacySettings[$row['setting_key']] = trim((string)$row['setting_value']); }
    $legacyOrigin = implode(' • ', array_values(array_filter(array($legacySettings['office_city'] ?? '', $legacySettings['office_district'] ?? '', $legacySettings['office_address'] ?? ''), fn($v) => $v !== '')));
    $routeRows = $pdo->query("SELECT id_pk, route_name, origin_label FROM route")->fetchAll();
    $originUpdate = $pdo->prepare("UPDATE route SET origin_label=:o WHERE id_pk=:id");
    foreach ($routeRows as $rr) {
        if (trim((string)$rr['origin_label']) !== '') { continue; }
        $parts = preg_split('/\s+(?:→|↔)\s+/u', (string)$rr['route_name']);
        $origin = trim((string)($parts[0] ?? ''));
        $origin = preg_replace('/\s+(?:—|•)\s+/u', ' • ', $origin);
        if ($origin === '') { $origin = $legacyOrigin; }
        if ($origin !== '') { $originUpdate->execute(array(':o'=>$origin, ':id'=>(int)$rr['id_pk'])); }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS history (
        id_pk INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL REFERENCES vehicle(id_pk),
        route_id INTEGER NOT NULL REFERENCES route(id_pk),
        kilometres REAL NOT NULL DEFAULT 0,
        fuel_calculated REAL NOT NULL DEFAULT 0,
        date TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS account (
        id_pk INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        must_change INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permission (
        user_id INTEGER NOT NULL REFERENCES account(id_pk) ON DELETE CASCADE,
        permission TEXT NOT NULL,
        allowed INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, permission)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS access_request (
        id_pk INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES account(id_pk) ON DELETE CASCADE,
        permission TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at DATETIME,
        UNIQUE(user_id, permission)
    )");

    // Store the initial odometer separately.
    $vehicleCols = $pdo->query('PRAGMA table_info(vehicle)')->fetchAll();
    $vehicleColNames = array_column($vehicleCols, 'name');
    if (! in_array('initial_odometer', $vehicleColNames, true)) {
        $pdo->exec('ALTER TABLE vehicle ADD COLUMN initial_odometer REAL NOT NULL DEFAULT 0');
        // Use the current odometer as the initial value when upgrading an old database.
        $pdo->exec('UPDATE vehicle SET initial_odometer = odometer');
    }
    // Vehicle active/inactive status.
    $vehicleCols = $pdo->query('PRAGMA table_info(vehicle)')->fetchAll();
    $vehicleColNames = array_column($vehicleCols, 'name');
    if (! in_array('is_active', $vehicleColNames, true)) { $pdo->exec('ALTER TABLE vehicle ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1'); }
    if (! in_array('fuel_kind', $vehicleColNames, true)) { $pdo->exec("ALTER TABLE vehicle ADD COLUMN fuel_kind TEXT NOT NULL DEFAULT ''"); }

    // Snapshot the vehicle fuel kind on each trip so historical reports remain correct.
    $historyCols = $pdo->query('PRAGMA table_info(history)')->fetchAll();
    $historyColNames = array_column($historyCols, 'name');
    if (! in_array('fuel_kind', $historyColNames, true)) {
        $pdo->exec("ALTER TABLE history ADD COLUMN fuel_kind TEXT NOT NULL DEFAULT ''");
    }
    // Backfill existing trips from their vehicle. This preserves already-entered production data.
    $pdo->exec("UPDATE history SET fuel_kind = COALESCE((SELECT v.fuel_kind FROM vehicle v WHERE v.id_pk = history.vehicle_id), '') WHERE fuel_kind = ''");
    $routeCols = $pdo->query('PRAGMA table_info(route)')->fetchAll();
    $routeColNames = array_column($routeCols, 'name');
    if (! in_array('is_active', $routeColNames, true)) { $pdo->exec('ALTER TABLE route ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1'); }
    // routes are no longer archived/deactivated; keep every existing route available.
    $pdo->exec('UPDATE route SET is_active = 1');

    // Admin and status columns.
    $accountCols = $pdo->query('PRAGMA table_info(account)')->fetchAll();
    $accountColNames = array_column($accountCols, 'name');
    if (! in_array('is_admin', $accountColNames, true)) { $pdo->exec('ALTER TABLE account ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0'); }
    if (! in_array('is_active', $accountColNames, true)) { $pdo->exec('ALTER TABLE account ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1'); }
    $pdo->exec('UPDATE account SET is_admin = 1 WHERE id_pk = (SELECT MIN(id_pk) FROM account)');
    // User deactivation is no longer part of the product; keep existing user accounts usable.
    $pdo->exec('UPDATE account SET is_active = 1');

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempt (
        ip TEXT PRIMARY KEY,
        fails INTEGER NOT NULL DEFAULT 0,
        locked_until DATETIME
    )");

    // Georgia locations based on MyHome's public location selector (cities / towns / municipalities).
    // Destination districts are city-scoped. Cities without a published district list intentionally have none.
    $cities = array(
      'Tbilisi','Batumi','Kutaisi','Rustavi','Gori','Telavi','Zugdidi','Poti','Mtskheta','Borjomi',
      'Akhaltsikhe','Khashuri','Samtredia','Zestafoni','Kobuleti','Senaki','Ozurgeti','Marneuli','Gurjaani','Sagarejo',
      'Tskaltubo','Chiatura','Sachkhere','Tkibuli','Khoni','Vani','Baghdati','Lanchkhuti','Abasha','Martvili','Khobi','Tsalenjikha',
      'Tsnori','Sighnaghi','Kvareli','Lagodekhi','Akhmeta','Dedoplistskaro','Dusheti','Kaspi','Kareli','Bolnisi','Gardabani',
      'Dmanisi','Tetritskaro','Tsalka','Akhalkalaki','Ninotsminda','Ambrolauri','Oni','Jvari','Vale','Tskhinvali','Sokhumi'
    );
    $seedVersion='2';
    $seedCheck=$pdo->prepare("SELECT setting_value FROM app_setting WHERE setting_key='location_seed_version'"); $seedCheck->execute();
    $needsLocationSeed=((string)$seedCheck->fetchColumn()!==$seedVersion);
    $insCity=$pdo->prepare('INSERT OR IGNORE INTO location_city(name) VALUES(:n)');
    if($needsLocationSeed){ foreach($cities as $c){$insCity->execute(array(':n'=>$c));} }
    $districts = array(
      'Tbilisi'=>array('Vake','Saburtalo','Mtatsminda','Sololaki','Avlabari','Ortachala','Didube','Chughureti','Gldani','Nadzaladevi','Isani','Samgori','Lilo','Varketili','Didi Dighomi','Dighomi Massif','Vedzisi','Lisi','Temka','Afrika','Ponichala'),
      'Batumi'=>array('Old Batumi','Khimshiashvili','Airport District','Kakhaberi','New Boulevard','Bagrationi','Boni-Gorodok','Makhinjauri'),
      'Kutaisi'=>array('Bzholebi','Auto Factory','Nikea','Gora','Choma','Mtsvanekvavila','Shaomiani','Mefetubani','Sapichkhia','Zastava','Balakhvani','Steklotara')
    );
    $cityId=$pdo->prepare('SELECT id_pk FROM location_city WHERE name=:n');
    $insDist=$pdo->prepare('INSERT OR IGNORE INTO location_district(city_id,name) VALUES(:c,:n)');
    // Remove the old synthetic fallback: a city with no known districts should show the proper empty-state message.
    $pdo->exec("DELETE FROM location_district WHERE name='Other'");
    if($needsLocationSeed){ foreach($districts as $city=>$items){$cityId->execute(array(':n'=>$city));$cid=(int)$cityId->fetchColumn();foreach($items as $d){$insDist->execute(array(':c'=>$cid,':n'=>$d));}}
      $sv=$pdo->prepare("INSERT INTO app_setting(setting_key,setting_value) VALUES('location_seed_version',:v) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value");$sv->execute(array(':v'=>$seedVersion));
    }

    $pdo->exec("INSERT OR IGNORE INTO app_setting(setting_key,setting_value) VALUES('office_address','')");

    // Indexes for searches and history filters.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vehicle_plate ON vehicle(plate_number)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vehicle_make_model ON vehicle(make, model)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_location_city_name ON location_city(name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_location_district_city_name ON location_district(city_id,name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_route_name ON route(route_name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_history_date ON history(date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_history_vehicle_date ON history(vehicle_id, date)');

    foreach (array( 'fuel', 'vehicle', 'route', 'history', 'account' ) as $t) {
        $pdo->exec("DROP TRIGGER IF EXISTS trg_{$t}_updated");
        $pdo->exec("CREATE TRIGGER trg_{$t}_updated
            AFTER UPDATE ON {$t}
            BEGIN
                UPDATE {$t} SET updated_at = strftime('%Y-%m-%d %H:%M:%f','now') WHERE id_pk = NEW.id_pk;
            END");
    }

    // Default vehicle categories: Sedan and SUV.
    $pdo->exec("INSERT OR IGNORE INTO fuel (type_name, litres) VALUES ('Sedan', 11)");
    $pdo->exec("INSERT OR IGNORE INTO fuel (type_name, litres) VALUES ('SUV', 15)");

    $accCount = (int) $pdo->query('SELECT COUNT(*) FROM account')->fetchColumn();
    if ($accCount === 0) {
        // Initialize only an empty installation; never reset existing credentials.
        $username = getenv('FLEET_INITIAL_ADMIN_USERNAME') ?: 'admin';
        $password = getenv('FLEET_INITIAL_ADMIN_PASSWORD') ?: 'demoadmin';
        $stmt = $pdo->prepare('INSERT INTO account (username, password_hash, must_change, is_admin, is_active) VALUES (:u, :h, 0, 1, 1)');
        $stmt->execute(array(
            ':u' => $username,
            ':h' => password_hash($password, PASSWORD_DEFAULT),
        ));
    }
}
