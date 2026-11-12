<?php
/**
 * api.php — API entry point for all actions.
 * Usage: api.php?action=<action> with query parameters or a JSON body.
 *
 * URL rewriting is not required.
 */

declare(strict_types=1);

session_start();
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
header( 'Pragma: no-cache' );
header( 'Expires: 0' );
header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet' );

require_once __DIR__ . '/config.php';
avtoparki_session_migrate();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function avtoparki_json_body(): array {
    $raw = file_get_contents( 'php://input' );
    if ( ! $raw ) {
        return array();
    }
    $data = json_decode( $raw, true );
    return is_array( $data ) ? $data : array();
}

function avtoparki_reply( $value ): void {
    if ( is_array( $value ) ) {
        echo json_encode( array_merge( array( 'ok' => true ), $value ), JSON_UNESCAPED_UNICODE );
    } else {
        echo json_encode( array( 'ok' => true, 'result' => $value ), JSON_UNESCAPED_UNICODE );
    }
}

$action = isset( $_GET['action'] ) ? (string) $_GET['action'] : '';
// Background polling must not keep an otherwise idle session alive.
$backgroundActions = array( 'access_requests', 'session_check' );
avtoparki_enforce_idle_timeout( ! in_array( $action, $backgroundActions, true ) );

$publicActions = array( 'login', 'me', 'session_check' );

try {
    if ( ! in_array( $action, $publicActions, true ) ) {
        AvtoparkiAuth::require_auth();

        // Users must replace temporary passwords before accessing application features.
        $allowedDuringPasswordChange = array( 'password', 'logout' );
        if ( AvtoparkiAuth::must_change_password() && ! in_array( $action, $allowedDuringPasswordChange, true ) ) {
            throw new AvtoparkiError( 'Change your temporary password before using the application.', 403 );
        }
    }

    switch ( $action ) {

        /* ---------------- Authentication ---------------- */
        case 'login':
            $body = avtoparki_json_body();
            AvtoparkiAuth::login( (string) ( $body['username'] ?? '' ), (string) ( $body['password'] ?? '' ) );
            avtoparki_reply( array(
                'user'        => AvtoparkiAuth::current_username(),
                'must_change' => AvtoparkiAuth::must_change_password(),
                'is_admin'    => AvtoparkiAuth::is_admin(),
                'permissions' => AvtoparkiAuth::permissions(),
                'trip_request_status' => AvtoparkiAuth::trip_access_request_status(),
            ) );
            break;

        case 'logout':
            AvtoparkiAuth::logout();
            avtoparki_reply( array() );
            break;

        case 'me':
            avtoparki_reply( array(
                'authenticated' => AvtoparkiAuth::is_authenticated(),
                'user'          => AvtoparkiAuth::current_username(),
                'must_change'   => AvtoparkiAuth::must_change_password(),
                'is_admin'      => AvtoparkiAuth::is_admin(),
                'permissions'   => AvtoparkiAuth::permissions(),
                'trip_request_status' => AvtoparkiAuth::trip_access_request_status(),
            ) );
            break;

        case 'session_check':
            avtoparki_reply( array( 'authenticated' => AvtoparkiAuth::is_authenticated() ) );
            break;

        case 'password':
            $body = avtoparki_json_body();
            AvtoparkiAuth::change_password(
                (string) ( $body['current_password'] ?? '' ),
                (string) ( $body['new_password'] ?? '' ),
                (string) ( $body['repeat_password'] ?? '' ),
                (string) ( $body['new_username'] ?? '' )
            );
            avtoparki_reply( array( 'user' => AvtoparkiAuth::current_username(), 'is_admin' => AvtoparkiAuth::is_admin(), 'must_change' => AvtoparkiAuth::must_change_password() ) );
            break;

        case 'users':
            AvtoparkiAuth::require_admin();
            avtoparki_reply( array( 'users' => AvtoparkiAuth::users() ) ); break;
        case 'user_create':
            AvtoparkiAuth::require_admin();
            $b=avtoparki_json_body(); AvtoparkiAuth::create_user((string)($b['username']??''),(string)($b['password']??''),(bool)($b['can_add_trip']??false));
            avtoparki_reply(array('users'=>AvtoparkiAuth::users())); break;
        case 'user_toggle':
            AvtoparkiAuth::require_admin();
            $b=avtoparki_json_body(); AvtoparkiAuth::toggle_user((int)($b['id']??0),(bool)($b['active']??false));
            avtoparki_reply(array('users'=>AvtoparkiAuth::users())); break;
        case 'user_password_reset':
            AvtoparkiAuth::require_admin();
            $b=avtoparki_json_body(); AvtoparkiAuth::reset_user_password((int)($b['id']??0),(string)($b['password']??''));
            avtoparki_reply(array('users'=>AvtoparkiAuth::users())); break;
        case 'user_delete':
            AvtoparkiAuth::require_admin();
            $b=avtoparki_json_body(); AvtoparkiAuth::delete_user((int)($b['id']??0));
            avtoparki_reply(array('users'=>AvtoparkiAuth::users())); break;
        case 'user_permission':
            AvtoparkiAuth::require_admin();
            $b=avtoparki_json_body(); AvtoparkiAuth::set_trip_create_permission((int)($b['id']??0),(bool)($b['can_add_trip']??false));
            avtoparki_reply(array('users'=>AvtoparkiAuth::users())); break;

        case 'trip_access_request':
            AvtoparkiAuth::request_trip_access();
            avtoparki_reply(array('status'=>AvtoparkiAuth::trip_access_request_status())); break;
        case 'access_requests':
            AvtoparkiAuth::require_admin();
            avtoparki_reply(array('requests'=>AvtoparkiAuth::access_requests())); break;
        case 'access_request_decide':
            AvtoparkiAuth::require_admin();
            $b=avtoparki_json_body(); AvtoparkiAuth::decide_trip_access_request((int)($b['id']??0),(bool)($b['approve']??false));
            avtoparki_reply(array('requests'=>AvtoparkiAuth::access_requests(),'users'=>AvtoparkiAuth::users())); break;

        /* ---------------- bootstrap ---------------- */
        case 'bootstrap':
            avtoparki_reply( array(
                'vehicles'   => AvtoparkiDB::get_vehicles(),
                'routes'     => AvtoparkiDB::get_routes(),
                'trips'      => AvtoparkiDB::get_trips(),
                'fuel_types' => AvtoparkiDB::get_fuel_types(),
                'settings'   => AvtoparkiDB::get_settings(),
                'locations'  => AvtoparkiDB::get_locations(),
                'user'       => AvtoparkiAuth::current_username(),
                'today'      => date( 'Y-m-d' ),
                'must_change' => AvtoparkiAuth::must_change_password(),
                'is_admin'    => AvtoparkiAuth::is_admin(),
                'permissions' => AvtoparkiAuth::permissions(),
                'trip_request_status' => AvtoparkiAuth::trip_access_request_status(),
            ) );
            break;

        /* ---------------- Vehicles ---------------- */
        case 'vehicles':
            avtoparki_reply( array( 'vehicles' => AvtoparkiDB::get_vehicles() ) );
            break;

        case 'vehicle_create':
            AvtoparkiAuth::require_admin();
            $id = AvtoparkiDB::save_vehicle( avtoparki_json_body() );
            avtoparki_reply( array( 'id' => $id, 'vehicles' => AvtoparkiDB::get_vehicles() ) );
            break;

        case 'vehicle_update':
            AvtoparkiAuth::require_admin();
            $body = avtoparki_json_body();
            $id   = AvtoparkiDB::save_vehicle( $body, (int) ( $body['id'] ?? 0 ) );
            avtoparki_reply( array( 'id' => $id, 'vehicles' => AvtoparkiDB::get_vehicles() ) );
            break;

        case 'vehicle_status':
            AvtoparkiAuth::require_admin();
            $body = avtoparki_json_body();
            AvtoparkiDB::set_vehicle_active((int)($body['id'] ?? 0), !empty($body['is_active']));
            avtoparki_reply(array('vehicles' => AvtoparkiDB::get_vehicles()));
            break;

        case 'vehicle_delete':
            AvtoparkiAuth::require_admin();
            $body = avtoparki_json_body();
            AvtoparkiDB::delete_vehicle( (int) ( $body['id'] ?? 0 ) );
            avtoparki_reply( array( 'vehicles' => AvtoparkiDB::get_vehicles() ) );
            break;

        /* ---------------- Routes ---------------- */
        case 'routes':
            avtoparki_reply( array( 'routes' => AvtoparkiDB::get_routes() ) );
            break;

        case 'location_city_add':
            AvtoparkiAuth::require_admin(); $b=avtoparki_json_body();
            avtoparki_reply(array('locations'=>AvtoparkiDB::add_location_city((string)($b['name']??'')))); break;
        case 'location_district_add':
            AvtoparkiAuth::require_admin(); $b=avtoparki_json_body();
            avtoparki_reply(array('locations'=>AvtoparkiDB::add_location_district((string)($b['city']??''),(string)($b['name']??'')))); break;

        case 'route_create':
            AvtoparkiAuth::require_admin();
            $id = AvtoparkiDB::save_route( avtoparki_json_body() );
            avtoparki_reply( array( 'id' => $id, 'routes' => AvtoparkiDB::get_routes() ) );
            break;

        case 'route_update':
            AvtoparkiAuth::require_admin();
            $body = avtoparki_json_body();
            $id   = AvtoparkiDB::save_route( $body, (int) ( $body['id'] ?? 0 ) );
            avtoparki_reply( array( 'id' => $id, 'routes' => AvtoparkiDB::get_routes() ) );
            break;

        case 'route_delete':
            AvtoparkiAuth::require_admin();
            $body = avtoparki_json_body();
            AvtoparkiDB::delete_route( (int) ( $body['id'] ?? 0 ) );
            avtoparki_reply( array( 'routes' => AvtoparkiDB::get_routes() ) );
            break;

        /* ---------------- Trips ---------------- */
        case 'trips':
            avtoparki_reply( array( 'trips' => AvtoparkiDB::get_trips() ) );
            break;

        case 'trip_create':
            AvtoparkiAuth::require_permission(AvtoparkiAuth::PERM_TRIP_CREATE, 'You do not have permission to add trips.');
            $id = AvtoparkiDB::create_trip( avtoparki_json_body() );
            avtoparki_reply( array( 'id' => $id, 'trips' => AvtoparkiDB::get_trips(), 'vehicles' => AvtoparkiDB::get_vehicles(), 'routes' => AvtoparkiDB::get_routes() ) );
            break;

        case 'trip_update':
            AvtoparkiAuth::require_admin();
            $body = avtoparki_json_body();
            AvtoparkiDB::update_trip( (int) ( $body['id'] ?? 0 ), $body );
            avtoparki_reply( array( 'trips' => AvtoparkiDB::get_trips(), 'vehicles' => AvtoparkiDB::get_vehicles(), 'routes' => AvtoparkiDB::get_routes() ) );
            break;

        case 'trip_delete':
            AvtoparkiAuth::require_admin();
            $body = avtoparki_json_body();
            AvtoparkiDB::delete_trip( (int) ( $body['id'] ?? 0 ) );
            avtoparki_reply( array( 'trips' => AvtoparkiDB::get_trips(), 'vehicles' => AvtoparkiDB::get_vehicles(), 'routes' => AvtoparkiDB::get_routes() ) );
            break;

        /* ---------------- Fuel categories ---------------- */
        case 'fuel_types':
            avtoparki_reply( array( 'fuel_types' => AvtoparkiDB::get_fuel_types() ) );
            break;

        case 'fuel_type_save':
            AvtoparkiAuth::require_admin();
            $body = avtoparki_json_body();
            $id   = AvtoparkiDB::save_fuel_type( $body, (int) ( $body['id_pk'] ?? 0 ) );
            avtoparki_reply( array( 'id' => $id, 'fuel_types' => AvtoparkiDB::get_fuel_types() ) );
            break;

        /* ---------------- Report ---------------- */
        case 'report':
            avtoparki_reply( AvtoparkiDB::report(
                (int) ( $_GET['vehicle_id'] ?? 0 ),
                (int) ( $_GET['route_id'] ?? 0 ),
                (string) ( $_GET['fuel_kind'] ?? '' ),
                (string) ( $_GET['from'] ?? '' ),
                (string) ( $_GET['to'] ?? '' ),
                (int) ( $_GET['page'] ?? 1 ),
                (int) ( $_GET['limit'] ?? 50 ),
                !empty( $_GET['all'] )
            ) );
            break;

        default:
            http_response_code( 404 );
            echo json_encode( array( 'ok' => false, 'message' => 'The requested action was not found.' ), JSON_UNESCAPED_UNICODE );
    }
} catch ( AvtoparkiError $e ) {
    http_response_code( $e->status );
    echo json_encode( array( 'ok' => false, 'message' => $e->getMessage() ), JSON_UNESCAPED_UNICODE );
} catch ( Throwable $e ) {
    http_response_code( 500 );
    echo json_encode( array( 'ok' => false, 'message' => 'A temporary server error occurred. Please try again.' ), JSON_UNESCAPED_UNICODE );
}
