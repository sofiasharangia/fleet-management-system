<?php
/**
 * db.php — Business logic for vehicles, routes, trips, and reports.
 */

declare(strict_types=1);

class AvtoparkiError extends Exception {
    public int $status;
    public function __construct( string $message, int $status = 400 ) {
        parent::__construct( $message );
        $this->status = $status;
    }
}

class AvtoparkiDB {

    /* ---------------- Fuel categories ---------------- */

    public static function get_fuel_types(): array {
        return avtoparki_db()->query('SELECT id_pk, type_name, litres FROM fuel ORDER BY id_pk ASC')->fetchAll();
    }

    public static function save_fuel_type( array $data, int $id = 0 ) {
        $name   = trim( (string) ( $data['type_name'] ?? '' ) );
        $litres = round( (float) ( $data['litres'] ?? 0 ), 2 );
        if ( '' === $name || $litres < 0 ) {
            throw new AvtoparkiError( 'Enter a category name and a valid fuel rate.' );
        }
        $pdo = avtoparki_db();
        if ( $id ) {
            $stmt = $pdo->prepare( 'UPDATE fuel SET type_name = :n, litres = :l WHERE id_pk = :id' );
            $stmt->execute( array( ':n' => $name, ':l' => $litres, ':id' => $id ) );
            return $id;
        }
        $stmt = $pdo->prepare( 'INSERT INTO fuel (type_name, litres) VALUES (:n, :l)' );
        $stmt->execute( array( ':n' => $name, ':l' => $litres ) );
        return (int) $pdo->lastInsertId();
    }

    /* ---------------- Vehicles ---------------- */

    public static function get_vehicles(): array {
        return avtoparki_db()->query(
            'SELECT v.id_pk, v.make, v.model, v.plate_number, v.type_id, v.initial_odometer, v.odometer, v.is_active, v.fuel_kind, f.type_name, f.litres,
                    v.updated_at, (SELECT COUNT(*) FROM history h WHERE h.vehicle_id = v.id_pk) AS trip_count
             FROM vehicle v LEFT JOIN fuel f ON f.id_pk = v.type_id
             ORDER BY v.updated_at DESC, v.id_pk DESC'
        )->fetchAll();
    }

    public static function get_vehicle( int $id ): ?array {
        $stmt = avtoparki_db()->prepare(
            'SELECT v.*, f.type_name, f.litres FROM vehicle v LEFT JOIN fuel f ON f.id_pk = v.type_id WHERE v.id_pk = :id'
        );
        $stmt->execute( array( ':id' => $id ) );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function save_vehicle( array $data, int $id = 0 ) {
        $pdo   = avtoparki_db();
        $make  = trim( (string) ( $data['make'] ?? '' ) );
        $model = trim( (string) ( $data['model'] ?? '' ) );
        $plate = strtoupper( trim( (string) ( $data['plate_number'] ?? '' ) ) );
        $ka2lat = array(
            'ა'=>'A','ბ'=>'B','გ'=>'G','დ'=>'D','ე'=>'E','ვ'=>'V','ზ'=>'Z','თ'=>'T','ი'=>'I','კ'=>'K','ლ'=>'L','მ'=>'M','ნ'=>'N','ო'=>'O','პ'=>'P','ჟ'=>'J','რ'=>'R','ს'=>'S','ტ'=>'T','უ'=>'U','ფ'=>'F','ქ'=>'Q','ღ'=>'R','ყ'=>'Y','შ'=>'S','ჩ'=>'C','ც'=>'C','ძ'=>'Z','წ'=>'W','ჭ'=>'W','ხ'=>'X','ჯ'=>'J','ჰ'=>'H'
        );
        $plate = strtr( $plate, $ka2lat );
        $type  = (int) ( $data['type_id'] ?? 0 );
        $fuelKind = trim((string)($data['fuel_kind'] ?? ''));
        if (!in_array($fuelKind, array('Petrol', 'Diesel'), true)) { throw new AvtoparkiError('Select a fuel type.'); }

        if ( '' === $make || '' === $plate ) {
            throw new AvtoparkiError( 'Enter the vehicle make and license plate.' );
        }
        if ( ! preg_match( '/^[A-Z]{2}-[0-9]{3}-[A-Z]{2}$/', $plate ) ) {
            throw new AvtoparkiError( 'Enter the license plate in AA-123-DD format.' );
        }
        $typeExists = $pdo->prepare( 'SELECT id_pk FROM fuel WHERE id_pk = :id' );
        $typeExists->execute( array( ':id' => $type ) );
        if ( ! $type || ! $typeExists->fetchColumn() ) {
            throw new AvtoparkiError( 'Select a vehicle category.' );
        }

        $dupe = $pdo->prepare( 'SELECT id_pk FROM vehicle WHERE plate_number = :p AND id_pk != :id' );
        $dupe->execute( array( ':p' => $plate, ':id' => $id ) );
        if ( $dupe->fetchColumn() ) {
            throw new AvtoparkiError( 'This license plate is already registered.', 409 );
        }

        if (!array_key_exists('initial_odometer', $data) || $data['initial_odometer'] === '' || !is_numeric($data['initial_odometer']) || (float)$data['initial_odometer'] < 0) { throw new AvtoparkiError('Enter a valid initial odometer.'); }
        $initial = round((float)$data['initial_odometer'], 2);
        if ( $id ) {
            $oldStmt = $pdo->prepare('SELECT make, model, plate_number, type_id, fuel_kind, initial_odometer, odometer, is_active FROM vehicle WHERE id_pk = :id');
            $oldStmt->execute(array(':id' => $id));
            $old = $oldStmt->fetch();
            if (!$old) { throw new AvtoparkiError('Vehicle not found.', 404); }
            if ((int)$old['is_active'] !== 1) { throw new AvtoparkiError('Restore the inactive vehicle before editing it.', 409); }

            $tripStmt = $pdo->prepare('SELECT COUNT(*) FROM history WHERE vehicle_id = :id');
            $tripStmt->execute(array(':id' => $id));
            $hasTrips = (int)$tripStmt->fetchColumn() > 0;
            if ($hasTrips) {
                // Once a vehicle has trip history, only typo-safe identity labels may change.
                $immutableChanged = $plate !== (string)$old['plate_number']
                    || $type !== (int)$old['type_id']
                    || $fuelKind !== (string)$old['fuel_kind']
                    || abs($initial - (float)$old['initial_odometer']) > 0.0001;
                if ($immutableChanged) {
                    throw new AvtoparkiError('This vehicle has trip history. Only its make and model can be changed.', 409);
                }
                $stmt = $pdo->prepare('UPDATE vehicle SET make = :m, model = :mo WHERE id_pk = :id');
                $stmt->execute(array(':m'=>$make, ':mo'=>$model, ':id'=>$id));
                return $id;
            }

            $delta = $initial - (float) $old['initial_odometer'];
            $current = max(0, round((float) $old['odometer'] + $delta, 2));
            $stmt = $pdo->prepare(
                'UPDATE vehicle SET make = :m, model = :mo, plate_number = :p, type_id = :t, fuel_kind = :fk, initial_odometer = :i, odometer = :o WHERE id_pk = :id'
            );
            $stmt->execute(array(':m'=>$make, ':mo'=>$model, ':p'=>$plate, ':t'=>$type, ':fk'=>$fuelKind, ':i'=>$initial, ':o'=>$current, ':id'=>$id));
            return $id;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO vehicle (make, model, plate_number, type_id, fuel_kind, initial_odometer, odometer) VALUES (:m, :mo, :p, :t, :fk, :i, :o)'
        );
        $stmt->execute(array(':m'=>$make, ':mo'=>$model, ':p'=>$plate, ':t'=>$type, ':fk'=>$fuelKind, ':i'=>$initial, ':o'=>$initial));
        return (int) $pdo->lastInsertId();
    }

    public static function set_vehicle_active( int $id, bool $active ) {
        $stmt = avtoparki_db()->prepare('UPDATE vehicle SET is_active = :a WHERE id_pk = :id');
        $stmt->execute(array(':a' => $active ? 1 : 0, ':id' => $id));
        if (!$stmt->rowCount() && !self::get_vehicle($id)) { throw new AvtoparkiError('Vehicle not found.', 404); }
        return true;
    }

    public static function delete_vehicle( int $id ) {
        $pdo  = avtoparki_db();
        $vehicle = self::get_vehicle($id);
        if (!$vehicle) { throw new AvtoparkiError('Vehicle not found.', 404); }
        if ((int)$vehicle['is_active'] !== 1) { throw new AvtoparkiError('Restore the inactive vehicle before deleting it.', 409); }
        $stmt = $pdo->prepare( 'SELECT COUNT(*) FROM history WHERE vehicle_id = :id' );
        $stmt->execute( array( ':id' => $id ) );
        $used = (int) $stmt->fetchColumn();
        if ( $used > 0 ) {
            throw new AvtoparkiError( "This vehicle has trip history and cannot be deleted. Deactivate it to preserve its history.", 409 );
        }
        $pdo->prepare( 'DELETE FROM vehicle WHERE id_pk = :id' )->execute( array( ':id' => $id ) );
        return true;
    }

    /* ---------------- Routes ---------------- */

    public static function get_routes(): array {
        return avtoparki_db()->query( "SELECT r.id_pk, r.route_name, r.origin_label, r.city, r.district, r.address, r.kilometres, r.updated_at, (SELECT COUNT(*) FROM history h WHERE h.route_id = r.id_pk) AS trip_count FROM route r ORDER BY r.updated_at DESC, r.id_pk DESC" )->fetchAll();
    }

    public static function get_settings(): array {
        $rows=avtoparki_db()->query('SELECT setting_key, setting_value FROM app_setting')->fetchAll(); $out=array();
        foreach($rows as $r){$out[$r['setting_key']]=$r['setting_value'];} return $out;
    }
    public static function save_office_address(array $data): void {
        $pdo=avtoparki_db();
        $city=trim((string)($data['office_city']??''));
        $district=trim((string)($data['office_district']??''));
        $address=trim((string)($data['office_address']??''));
        if($city==='' || $address===''){throw new AvtoparkiError('Enter the office city and address.');}
        $vals=array('office_city'=>$city,'office_district'=>$district,'office_address'=>$address);
        $st=$pdo->prepare('INSERT INTO app_setting(setting_key,setting_value) VALUES(:k,:v) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value');
        foreach($vals as $k=>$v){$st->execute(array(':k'=>$k,':v'=>$v));}
    }
    public static function get_locations(): array {
        $pdo=avtoparki_db();
        $priority=array('Tbilisi','Batumi','Kutaisi','Rustavi','Gori','Telavi','Zugdidi','Poti','Mtskheta','Borjomi','Akhaltsikhe','Khashuri','Samtredia','Zestafoni','Kobuleti','Senaki','Ozurgeti','Marneuli','Gurjaani','Sagarejo','Tskaltubo','Chiatura','Sachkhere','Tkibuli','Khoni','Vani','Baghdati','Lanchkhuti','Abasha','Martvili','Khobi','Tsalenjikha','Tsnori','Sighnaghi','Kvareli','Lagodekhi','Akhmeta','Dedoplistskaro','Dusheti','Kaspi','Kareli','Bolnisi','Gardabani','Dmanisi','Tetritskaro','Tsalka','Akhalkalaki','Ninotsminda','Ambrolauri','Oni','Jvari','Vale','Tskhinvali','Sokhumi');
        $all=$pdo->query('SELECT id_pk,name FROM location_city')->fetchAll();
        usort($all,function($a,$b)use($priority){$ia=array_search($a['name'],$priority,true);$ib=array_search($b['name'],$priority,true);$ia=$ia===false?9999:$ia;$ib=$ib===false?9999:$ib;return $ia===$ib?strcmp($a['name'],$b['name']):($ia<=>$ib);}); $cities=$all;
        $ds=$pdo->query('SELECT c.name city,d.name FROM location_district d JOIN location_city c ON c.id_pk=d.city_id ORDER BY d.id_pk')->fetchAll();
        $districts=array(); foreach($ds as $d){$districts[$d['city']][]=$d['name'];} return array('cities'=>$cities,'districts'=>$districts);
    }
    public static function add_location_city(string $name): array {
        $name=trim($name); if($name===''){throw new AvtoparkiError('Enter a city or town.');}
        if(strlen($name)>300){throw new AvtoparkiError('The city or town name is too long.');}
        $pdo=avtoparki_db(); $st=$pdo->prepare('INSERT OR IGNORE INTO location_city(name) VALUES(:n)'); $st->execute(array(':n'=>$name));
        return self::get_locations();
    }
    public static function add_location_district(string $city,string $name): array {
        $city=trim($city); $name=trim($name); if($city===''||$name===''){throw new AvtoparkiError('Select a city and enter a district.');}
        if(strlen($name)>300){throw new AvtoparkiError('The district name is too long.');}
        $pdo=avtoparki_db(); $st=$pdo->prepare('SELECT id_pk FROM location_city WHERE name=:n');$st->execute(array(':n'=>$city));$cid=(int)$st->fetchColumn();
        if(!$cid){throw new AvtoparkiError('City not found.');}
        $ins=$pdo->prepare('INSERT OR IGNORE INTO location_district(city_id,name) VALUES(:c,:n)');$ins->execute(array(':c'=>$cid,':n'=>$name));
        return self::get_locations();
    }

    private static function office_label(array $settings): string {
        $parts=array_filter(array(trim((string)($settings['office_city']??'')),trim((string)($settings['office_district']??'')),trim((string)($settings['office_address']??''))),fn($v)=>$v!=='');
        return implode(' — ',$parts);
    }
    public static function save_route( array $data, int $id = 0 ) {
        $pdo=avtoparki_db(); $origin=trim((string)($data['origin_label']??'')); $city=trim((string)($data['city']??'')); $district=trim((string)($data['district']??'')); $address=trim((string)($data['address']??'')); $km=round((float)($data['kilometres']??0),2);
        if($origin==='' || $city==='' || $km<=0){throw new AvtoparkiError('Select a starting location and city, and enter a positive distance.');}
        $c=$pdo->prepare('SELECT COUNT(*) FROM location_city WHERE name=:c'); $c->execute(array(':c'=>$city)); if(!(int)$c->fetchColumn()){throw new AvtoparkiError('The selected city was not found.');}
        if($district!==''){$check=$pdo->prepare('SELECT COUNT(*) FROM location_district d JOIN location_city c ON c.id_pk=d.city_id WHERE c.name=:c AND d.name=:d');$check->execute(array(':c'=>$city,':d'=>$district));if(!(int)$check->fetchColumn()){throw new AvtoparkiError('The selected district does not belong to this city.');}}
        $origin=preg_replace('/\s+(?:—|•)\s+/u',' • ',$origin);
        $dest=implode(' • ',array_filter(array($city,$district,$address),fn($v)=>$v!=='')); $name=$origin.' ↔ '.$dest;
        $dupe=$pdo->prepare('SELECT id_pk FROM route WHERE route_name=:n AND id_pk!=:id'); $dupe->execute(array(':n'=>$name,':id'=>$id)); if($dupe->fetchColumn()){throw new AvtoparkiError('This route already exists.',409);}
        if($id){
            $usedStmt=$pdo->prepare('SELECT COUNT(*) FROM history WHERE route_id=:id'); $usedStmt->execute(array(':id'=>$id));
            if((int)$usedStmt->fetchColumn()>0){throw new AvtoparkiError('This route is used in trip history and cannot be edited.',409);}
            $pdo->prepare('UPDATE route SET route_name=:n,origin_label=:o,city=:c,district=:d,address=:a,kilometres=:k WHERE id_pk=:id')->execute(array(':n'=>$name,':o'=>$origin,':c'=>$city,':d'=>$district,':a'=>$address,':k'=>$km,':id'=>$id)); return $id;
        }
        $st=$pdo->prepare('INSERT INTO route(route_name,origin_label,city,district,address,kilometres) VALUES(:n,:o,:c,:d,:a,:k)'); $st->execute(array(':n'=>$name,':o'=>$origin,':c'=>$city,':d'=>$district,':a'=>$address,':k'=>$km)); return (int)$pdo->lastInsertId();
    }

    public static function delete_route( int $id ) {
        $pdo  = avtoparki_db();
        $stmt = $pdo->prepare( 'SELECT COUNT(*) FROM history WHERE route_id = :id' );
        $stmt->execute( array( ':id' => $id ) );
        $used = (int) $stmt->fetchColumn();
        if ( $used > 0 ) {
            throw new AvtoparkiError( "This route is used in trip history and cannot be deleted.", 409 );
        }
        $pdo->prepare( 'DELETE FROM route WHERE id_pk = :id' )->execute( array( ':id' => $id ) );
        return true;
    }

    /* ---------------- Trips ---------------- */

    public static function calc_fuel( float $km, float $litres_per_100 ): float {
        return round( $km * $litres_per_100 / 100, 2 );
    }

    protected static function shift_odometer( int $vehicle_id, float $delta ): void {
        avtoparki_db()->prepare(
            'UPDATE vehicle SET odometer = MAX(0, odometer + :d) WHERE id_pk = :id'
        )->execute( array( ':d' => $delta, ':id' => $vehicle_id ) );
    }

    public static function get_trips( int $limit = 100 ): array {
        $stmt = avtoparki_db()->prepare(
            'SELECT h.id_pk, h.vehicle_id, h.route_id, h.kilometres, h.fuel_calculated, h.date, h.updated_at,
                    v.plate_number, v.make, v.model, r.route_name
             FROM history h
             LEFT JOIN vehicle v ON v.id_pk = h.vehicle_id
             LEFT JOIN route r ON r.id_pk = h.route_id
             ORDER BY h.updated_at DESC, h.id_pk DESC
             LIMIT :lim'
        );
        $stmt->bindValue( ':lim', $limit, PDO::PARAM_INT );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function get_trip( int $id ): ?array {
        $stmt = avtoparki_db()->prepare( 'SELECT * FROM history WHERE id_pk = :id' );
        $stmt->execute( array( ':id' => $id ) );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create_trip( array $data ) {
        $pdo        = avtoparki_db();
        $vehicle_id = (int) ( $data['vehicle_id'] ?? 0 );
        $route_id   = (int) ( $data['route_id'] ?? 0 );
        $date       = self::sanitize_date( $data['date'] ?? '' );

        $vehicle = self::get_vehicle( $vehicle_id );
        if ( ! $vehicle ) {
            throw new AvtoparkiError( 'The selected vehicle was not found.' );
        }
        if (empty($vehicle['is_active'])) { throw new AvtoparkiError('Trips cannot be added for an inactive vehicle.'); }
        $stmt = $pdo->prepare( 'SELECT * FROM route WHERE id_pk = :id' );
        $stmt->execute( array( ':id' => $route_id ) );
        $route = $stmt->fetch();
        if ( ! $route ) {
            throw new AvtoparkiError( 'The selected route was not found.' );
        }

        $km   = round( (float) $route['kilometres'], 2 );
        $fuel = self::calc_fuel( $km, (float) $vehicle['litres'] );

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO history (vehicle_id, route_id, kilometres, fuel_calculated, fuel_kind, date)
                 VALUES (:v, :r, :k, :f, :fk, :d)'
            );
            $ins->execute( array( ':v' => $vehicle_id, ':r' => $route_id, ':k' => $km, ':f' => $fuel, ':fk' => (string)$vehicle['fuel_kind'], ':d' => $date ) );
            $id = (int) $pdo->lastInsertId();
            self::shift_odometer( $vehicle_id, $km );
            $pdo->commit();
            return $id;
        } catch ( Throwable $e ) {
            $pdo->rollBack();
            throw new AvtoparkiError( 'Unable to save the trip. Please try again.', 500 );
        }
    }

    public static function update_trip( int $id, array $data ) {
        $pdo = avtoparki_db();
        $old = self::get_trip( $id );
        if ( ! $old ) {
            throw new AvtoparkiError( 'The requested trip was not found.', 404 );
        }
        $vehicle_id = (int) ( $data['vehicle_id'] ?? $old['vehicle_id'] );
        $route_id   = (int) ( $data['route_id'] ?? $old['route_id'] );
        $date       = self::sanitize_date( $data['date'] ?? $old['date'] );

        $vehicle = self::get_vehicle( $vehicle_id );
        if ( ! $vehicle ) {
            throw new AvtoparkiError( 'The selected vehicle was not found.' );
        }
        if ((int)$vehicle['is_active'] !== 1 && $vehicle_id !== (int)$old['vehicle_id']) {
            throw new AvtoparkiError('A trip cannot be moved to an inactive vehicle.');
        }
        $stmt = $pdo->prepare( 'SELECT * FROM route WHERE id_pk = :id' );
        $stmt->execute( array( ':id' => $route_id ) );
        $route = $stmt->fetch();
        if ( ! $route ) {
            throw new AvtoparkiError( 'The selected route was not found.' );
        }

        $km   = round( (float) $route['kilometres'], 2 );
        $fuel = self::calc_fuel( $km, (float) $vehicle['litres'] );

        $pdo->beginTransaction();
        try {
            self::shift_odometer( (int) $old['vehicle_id'], - (float) $old['kilometres'] );

            $upd = $pdo->prepare(
                'UPDATE history SET vehicle_id = :v, route_id = :r, kilometres = :k, fuel_calculated = :f, fuel_kind = :fk, date = :d
                 WHERE id_pk = :id'
            );
            $upd->execute( array( ':v' => $vehicle_id, ':r' => $route_id, ':k' => $km, ':f' => $fuel, ':fk' => (string)$vehicle['fuel_kind'], ':d' => $date, ':id' => $id ) );

            self::shift_odometer( $vehicle_id, $km );
            $pdo->commit();
            return $id;
        } catch ( Throwable $e ) {
            $pdo->rollBack();
            throw new AvtoparkiError( 'Unable to save changes. Please try again.', 500 );
        }
    }

    public static function delete_trip( int $id ) {
        $pdo  = avtoparki_db();
        $trip = self::get_trip( $id );
        if ( ! $trip ) {
            throw new AvtoparkiError( 'The requested trip was not found.', 404 );
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare( 'DELETE FROM history WHERE id_pk = :id' )->execute( array( ':id' => $id ) );
            self::shift_odometer( (int) $trip['vehicle_id'], - (float) $trip['kilometres'] );
            $pdo->commit();
            return true;
        } catch ( Throwable $e ) {
            $pdo->rollBack();
            throw new AvtoparkiError( 'Unable to delete the trip. Please try again.', 500 );
        }
    }

    /* ---------------- Report ---------------- */

    public static function report( int $vehicle_id = 0, int $route_id = 0, string $fuel_kind = '', string $from = '', string $to = '', int $page = 1, int $limit = 50, bool $all = false ): array {
        $pdo    = avtoparki_db();
        $where  = array( '1=1' );
        $params = array();

        if ( $vehicle_id ) {
            $where[]        = 'h.vehicle_id = :vid';
            $params[':vid'] = $vehicle_id;
        }
        if ( $route_id ) {
            $where[]        = 'h.route_id = :rid';
            $params[':rid'] = $route_id;
        }
        if ( $fuel_kind !== '' ) {
            if ( !in_array( $fuel_kind, array( 'Petrol', 'Diesel' ), true ) ) {
                throw new AvtoparkiError( 'Fuel type is invalid.' );
            }
            $where[] = "COALESCE(NULLIF(h.fuel_kind,''), v.fuel_kind) = :fuel_kind";
            $params[':fuel_kind'] = $fuel_kind;
        }
        if ( $from ) {
            $where[]         = 'h.date >= :from';
            $params[':from'] = self::sanitize_date( $from );
        }
        if ( $to ) {
            $where[]       = 'h.date <= :to';
            $params[':to'] = self::sanitize_date( $to );
        }

        $whereSql = implode( ' AND ', $where );
        $sumSql = "SELECT COUNT(*) AS cnt,
                          COALESCE(SUM(h.kilometres),0) AS total_km,
                          COALESCE(SUM(h.fuel_calculated),0) AS total_fuel,
                          COALESCE(SUM(CASE WHEN COALESCE(NULLIF(h.fuel_kind,''), v.fuel_kind) = 'Petrol' THEN h.fuel_calculated ELSE 0 END),0) AS petrol_fuel,
                          COALESCE(SUM(CASE WHEN COALESCE(NULLIF(h.fuel_kind,''), v.fuel_kind) = 'Diesel' THEN h.fuel_calculated ELSE 0 END),0) AS diesel_fuel
                   FROM history h
                   LEFT JOIN vehicle v ON v.id_pk = h.vehicle_id
                   WHERE " . $whereSql;
        $sumStmt = $pdo->prepare( $sumSql );
        $sumStmt->execute( $params );
        $summary = $sumStmt->fetch();

        $page  = max( 1, $page );
        $limit = max( 1, min( 200, $limit ) );
        $offset = ( $page - 1 ) * $limit;

        $sql = 'SELECT h.id_pk, h.date, h.kilometres, h.fuel_calculated,
                       v.id_pk AS vehicle_id, v.plate_number, v.make, v.model, COALESCE(h.fuel_kind, v.fuel_kind) AS fuel_kind,
                       r.id_pk AS route_id, r.route_name
                FROM history h
                LEFT JOIN vehicle v ON v.id_pk = h.vehicle_id
                LEFT JOIN route r ON r.id_pk = h.route_id
                WHERE ' . $whereSql . '
                ORDER BY h.date ASC, h.id_pk ASC';
        if ( !$all ) { $sql .= ' LIMIT :lim OFFSET :off'; }

        $stmt = $pdo->prepare( $sql );
        foreach ( $params as $key => $value ) { $stmt->bindValue( $key, $value ); }
        if ( !$all ) {
            $stmt->bindValue( ':lim', $limit, PDO::PARAM_INT );
            $stmt->bindValue( ':off', $offset, PDO::PARAM_INT );
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $count = (int) ( $summary['cnt'] ?? 0 );

        $result = array(
            'rows'       => $rows,
            'total_km'   => round( (float) ( $summary['total_km'] ?? 0 ), 2 ),
            'total_fuel' => round( (float) ( $summary['total_fuel'] ?? 0 ), 2 ),
            'petrol_fuel' => round( (float) ( $summary['petrol_fuel'] ?? 0 ), 2 ),
            'diesel_fuel' => round( (float) ( $summary['diesel_fuel'] ?? 0 ), 2 ),
            'count'      => $count,
            'page'       => $page,
            'limit'      => $limit,
            'has_more'   => !$all && ( $offset + count( $rows ) < $count ),
        );

        if ( $vehicle_id ) { $result['vehicle'] = self::get_vehicle( $vehicle_id ); }
        return $result;
    }

    public static function sanitize_date( $date ): string {
        $date = trim( (string) $date );
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts )
            && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) ) {
            return $date;
        }
        throw new AvtoparkiError( 'Enter a valid date in YYYY-MM-DD format.' );
    }
}
