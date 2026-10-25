<?php
if ( 'cli' !== PHP_SAPI || empty( $argv[1] ) ) {
    exit( "Usage: php tests/regression.php /path/to/local/wordpress [--http]\n" );
}
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( ! preg_match( '/(?:\.local|\.test|localhost)$/', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
    exit( "Refusing to create fixtures outside a .local/.test/localhost site.\n" );
}
if ( ! class_exists( 'Avela' ) ) {
    require dirname( __DIR__ ) . '/avela.php';
    Avela::register();
}
$created = array();
$checks = 0;
$old_settings = get_option( 'avela_settings', null );
$old_cookies = $_COOKIE;
$old_user = get_current_user_id();
$old_epoch = get_option( 'avela_policy_epoch', null );

function check_avela( $condition, $message ) {
    global $checks;
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    ++$checks;
    echo "PASS $message\n";
}

function fixture_avela( $type, $title, $content = '' ) {
    global $created, $wpdb;
    $id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'draft', 'post_title' => $title,
        'post_content' => $content, 'comment_status' => 'open' ), true );
    if ( is_wp_error( $id ) ) {
        throw new RuntimeException( $id->get_error_message() );
    }
    $created[] = $id;
    // Never invoke publishing transitions: active syndication plugins must not send test fixtures.
    $wpdb->update( $wpdb->posts, array( 'post_status' => 'attachment' === $type ? 'inherit' : 'publish',
        'post_name' => 'avela-test-' . $id, 'post_date_gmt' => current_time( 'mysql', true ) ), array( 'ID' => $id ) );
    clean_post_cache( $id );
    return $id;
}

function http_avela( $url, array $cookies = array(), $fields = null, array $headers = array() ) {
    $ch = curl_init( $url );
    $cookie = array_merge( array( 'liliadna_access_test' => 'avela-regression' ), $GLOBALS['avela_http_site_cookies'] ?? array(), $cookies );
    $pairs = array();
    foreach ( $cookie as $name => $value ) {
        $pairs[] = $name . '=' . rawurlencode( $value );
    }
    curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => implode( '; ', $pairs ), CURLOPT_FOLLOWLOCATION => null === $fields,
        CURLOPT_MAXREDIRS => 5, CURLOPT_HTTPHEADER => $headers ) );
    if ( null !== $fields ) {
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $fields ) );
    }
    $raw = curl_exec( $ch );
    if ( false === $raw ) {
        throw new RuntimeException( curl_error( $ch ) );
    }
    $size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
    $status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $header_text = substr( $raw, 0, $size );
    preg_match_all( '/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/mi', $header_text, $matches, PREG_SET_ORDER );
    foreach ( $matches as $match ) {
        $cookie[ $match[1] ] = rawurldecode( $match[2] );
    }
    return array( 'status' => $status, 'headers' => $header_text, 'body' => substr( $raw, $size ), 'cookies' => $cookie );
}

function site_consent_avela( $url ) {
    $before = http_avela( $url );
    if ( false !== strpos( $before['body'], 'name="liliadna_consent_token"' ) ) {
        preg_match( '/name="liliadna_consent_token" value="([^"]+)"/', $before['body'], $token );
        $consent = http_avela( home_url( '/' ), $before['cookies'], array(
            'liliadna_consent_token' => html_entity_decode( $token[1] ),
            'liliadna_consent_return' => $url, 'liliadna_consent_age' => '1', 'liliadna_consent_action' => 'allow',
        ) );
        check_avela( 303 === $consent['status'], 'Existing theme consent completed without changing settings' );
        $GLOBALS['avela_http_site_cookies'] = array_intersect_key( $consent['cookies'], array_flip( array( 'liliadna_access', 'liliadna_consent_csrf' ) ) );
        $before = http_avela( $url );
    }
    return $before;
}

function cleanup_avela() {
    global $created, $old_settings, $old_epoch, $old_cookies, $old_user;
    foreach ( array_reverse( $created ) as $id ) {
        if ( 'attachment' === get_post_type( $id ) ) {
            wp_delete_attachment( $id, true );
        } else {
            wp_delete_post( $id, true );
        }
    }
    null === $old_settings ? delete_option( 'avela_settings' ) : update_option( 'avela_settings', $old_settings );
    null === $old_epoch ? delete_option( 'avela_policy_epoch' ) : update_option( 'avela_policy_epoch', $old_epoch );
    Avela_Media::flush();
    delete_option( 'avela_media_index' );
    $_COOKIE = $old_cookies;
    wp_set_current_user( $old_user );
    wp_reset_postdata();
}
