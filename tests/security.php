<?php
/** Adversarial integration cases. Fixtures are always removed in finally. */
require __DIR__ . '/bootstrap.php';
// Keep CLI headers available while testing actual signed-cookie writes.
ob_start();
$issues = array();
function audit_avela( $condition, $message ) {
    global $issues, $checks;
    ++$checks;
    if ( ! $condition ) {
        $issues[] = $message;
    }
    echo ( $condition ? 'PASS ' : 'FAIL ' ) . $message . "\n";
}

try {
    wp_set_current_user( 0 );
    update_option( 'avela_settings', array( 'scope' => 'post', 'hours' => 0, 'repeat' => 1, 'reset' => 1, 'images' => 1 ) );
    $_COOKIE = array( Avela_Access::CSRF => str_repeat( 'a', 48 ) );
    $warning = fixture_avela( 'avela_warning', 'Avela security warning', 'Чувствительный материал.' );
    $post_id = fixture_avela( 'post', 'Avela security fixture', 'AVELA_SECURITY_BODY' );
    update_post_meta( $post_id, '_avela_warning', $warning );
    update_post_meta( $post_id, '_avela_teaser', 'AVELA_SECURITY_PUBLIC' );
    $media = fixture_avela( 'attachment', 'AVELA_SECURITY_MEDIA' );
    update_post_meta( $media, '_wp_attached_file', '2099/02/avela-security.png' );
    update_post_meta( $post_id, '_thumbnail_id', $media );
    $comment_id = wp_insert_comment( array( 'comment_post_ID' => $post_id,
        'comment_content' => 'AVELA_SECURITY_COMMENT', 'comment_author' => 'Avela security', 'comment_approved' => 1 ) );
    Avela_Media::flush();

    $query = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => array( $media ), 'fields' => 'ids' ) );
    audit_avela( ! in_array( $media, $query->posts, true ), 'Explicit include cannot override attachment restrictions' );

    delete_option( 'avela_media_index' );
    delete_post_meta( $post_id, '_avela_media_index' );
    audit_avela( ! get_comments( array( 'post_id' => $post_id, 'status' => 'approve' ) ), 'Missing derived media index cannot expose comments' );
    audit_avela( Avela_Media::hidden( $media ), 'Missing derived media index keeps attachments closed' );
    Avela_Media::sync( $post_id );

    $during_rebuild = null;
    $observe = function ( $urls, $owner ) use ( $post_id, &$during_rebuild ) {
        if ( $owner === $post_id ) {
            $during_rebuild = ! get_comments( array( 'post_id' => $post_id, 'status' => 'approve' ) );
        }
        return $urls;
    };
    add_filter( 'avela_restricted_media_urls', $observe, 10, 2 );
    Avela_Media::rebuild();
    remove_filter( 'avela_restricted_media_urls', $observe );
    audit_avela( true === $during_rebuild, 'Rebuild never publishes an empty access registry' );

    $relative_media = fixture_avela( 'attachment', 'AVELA_RELATIVE_MEDIA' );
    update_post_meta( $relative_media, '_wp_attached_file', '2099/02/avela-relative.png' );
    $relative_url = wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH ) . '/2099/02/avela-relative.png';
    $relative_post = fixture_avela( 'post', 'Avela relative fixture', '<img src="' . $relative_url . '" alt="">' );
    update_post_meta( $relative_post, '_avela_warning', $warning );
    Avela_Media::sync( $relative_post );
    audit_avela( Avela_Media::hidden( $relative_media ), 'Relative image URLs without a class retain attachment ownership' );

    $other = fixture_avela( 'post', 'Avela independent owner', 'AVELA_OTHER_BODY' );
    $interleaved = false;
    $nested = function ( $urls, $owner ) use ( $post_id, $other, $warning, &$interleaved ) {
        if ( $owner === $post_id && ! $interleaved ) {
            $interleaved = true;
            update_post_meta( $other, '_avela_warning', $warning );
            Avela_Media::sync( $other );
        }
        return $urls;
    };
    add_filter( 'avela_restricted_media_urls', $nested, 10, 2 );
    Avela_Media::sync( $post_id );
    remove_filter( 'avela_restricted_media_urls', $nested );
    audit_avela( isset( Avela_Media::index()[ $other ] ) && ! empty( Avela_Media::index()[ $other ]['ready'] ), 'Interleaved owner saves do not overwrite each other' );
    $same_owner = false;
    $nested_same = function ( $urls, $owner ) use ( $post_id, $relative_media, &$same_owner ) {
        if ( $owner === $post_id && ! $same_owner ) {
            $same_owner = true;
            update_post_meta( $post_id, '_thumbnail_id', $relative_media );
            Avela_Media::sync( $post_id );
        }
        return $urls;
    };
    add_filter( 'avela_restricted_media_urls', $nested_same, 10, 2 );
    Avela_Media::sync( $post_id );
    remove_filter( 'avela_restricted_media_urls', $nested_same );
    audit_avela( in_array( $relative_media, Avela_Media::index()[ $post_id ]['ids'], true ), 'An older build cannot overwrite a newer revision of the same owner' );
    update_post_meta( $post_id, '_thumbnail_id', $media );
    Avela_Media::sync( $post_id );

    $GLOBALS['post'] = get_post( $post_id );
    setup_postdata( $GLOBALS['post'] );
    $shortcode_called = false;
    add_shortcode( 'avela_probe', function () use ( &$shortcode_called ) { $shortcode_called = true; return 'INTERNAL_SHORTCODE_OUTPUT'; } );
    update_post_meta( $post_id, '_avela_teaser', '[avela_probe] AVELA_SECURITY_PUBLIC' );
    $teaser_html = apply_filters( 'the_content', 'AVELA_SECURITY_BODY' );
    audit_avela( ! $shortcode_called && false === strpos( $teaser_html, 'INTERNAL_SHORTCODE_OUTPUT' ), 'Public teaser does not execute shortcodes' );
    remove_shortcode( 'avela_probe' );
    update_post_meta( $post_id, '_avela_teaser', 'AVELA_SECURITY_PUBLIC' );

    $age = ( new Avela_Local_Provider() )->verify( $post_id, array() );
    $grant = array( 'v' => Avela_Access::fingerprint( $post_id ), 'exp' => time() + 3600, 'method' => 'self_declared', 'provider' => 'local' );
    $_COOKIE[ Avela_Access::AGE_COOKIE ] = Avela_Access::sign( array( 'local' => $age ), 'age' );
    audit_avela( ! Avela_Access::allowed( $post_id ), 'Age evidence alone does not accept a warning' );
    $_COOKIE[ Avela_Access::COOKIE ] = Avela_Access::sign( array( Avela_Access::key( $post_id ) => $grant ) );
    audit_avela( Avela_Access::allowed( $post_id ), 'Independent age evidence and accepted warning together grant access' );
    update_post_meta( $warning, '_avela_revision', 'new_conditions' );
    audit_avela( ! Avela_Access::allowed( $post_id ) && null !== Avela_Access::age_result( 'local' ), 'New warning invalidates its acceptance while retaining valid age evidence' );
    $grant['v'] = Avela_Access::fingerprint( $post_id );
    $_COOKIE[ Avela_Access::COOKIE ] = Avela_Access::sign( array( Avela_Access::key( $post_id ) => $grant ) );
    $age['expires_at'] = time() - 1;
    $_COOKIE[ Avela_Access::AGE_COOKIE ] = Avela_Access::sign( array( 'local' => $age ), 'age' );
    audit_avela( ! Avela_Access::allowed( $post_id ), 'Expired age evidence closes even an accepted warning' );
    $_COOKIE = array( Avela_Access::CSRF => str_repeat( 'a', 48 ) );
    $policy_settings = Avela::settings();
    $no_repeat = $policy_settings;
    $no_repeat['repeat'] = 0;
    update_option( 'avela_settings', $no_repeat );
    $age = ( new Avela_Local_Provider() )->verify( $post_id, array() );
    $_COOKIE[ Avela_Access::AGE_COOKIE ] = Avela_Access::sign( array( 'local' => $age ), 'age' );
    $grant['v'] = Avela_Access::fingerprint( $post_id );
    $_COOKIE[ Avela_Access::COOKIE ] = Avela_Access::sign( array( Avela_Access::key( $post_id ) => $grant ) );
    $old_form_token = Avela_Access::token( $post_id, 'confirm' );
    update_post_meta( $warning, '_avela_revision', 'changed_with_no_repeat' );
    audit_avela( Avela_Access::allowed( $post_id ) && ! Avela_Access::valid_token( $post_id, 'confirm', $old_form_token ), 'Disabling repeat preserves past consent but never accepts a stale form' );
    update_option( 'avela_settings', $policy_settings );
    $_COOKIE = array( Avela_Access::CSRF => str_repeat( 'a', 48 ) );
    $extra_profile = ( new Avela_Local_Provider() )->verify( $post_id, array() );
    $extra_profile['birth_date'] = '1900-01-01';
    $extra_profile['document_photo'] = 'PRIVATE_PROFILE_DATA';
    $written = Avela_Access::grant( $post_id, $extra_profile );
    $stored_age = Avela_Access::decode( $_COOKIE[ Avela_Access::AGE_COOKIE ] ?? '', 'age' )['local'] ?? array();
    audit_avela( true === $written && ! isset( $stored_age['birth_date'], $stored_age['document_photo'] ) && 6 === count( $stored_age ), 'Provider profile extras are never persisted in age evidence' );
    $_COOKIE = array( Avela_Access::CSRF => str_repeat( 'a', 48 ) );

    $long_provider = 'p' . str_repeat( 'x', 63 );
    $long_required = function () use ( $long_provider ) { return $long_provider; };
    add_filter( 'avela_required_provider', $long_required );
    $large_grant = array( 'v' => str_repeat( 'a', 64 ), 'exp' => time() + 3600,
        'method' => 'm' . str_repeat( 'x', 63 ), 'provider' => $long_provider, 'checked_at' => time() );
    $old_grants = array();
    for ( $i = 0; $i < 11; ++$i ) {
        $candidate = $old_grants + array( 'p' . ( 999990 + $i ) => $large_grant );
        if ( strlen( Avela_Access::sign( $candidate ) ) > 3750 ) {
            break;
        }
        $old_grants = $candidate;
    }
    $_COOKIE[ Avela_Access::COOKIE ] = Avela_Access::sign( $old_grants );
    $long_result = ( new Avela_Local_Provider() )->verify( $post_id, array() );
    $long_result['provider'] = $long_provider;
    $long_result['method'] = $large_grant['method'];
    $written = Avela_Access::grant( $post_id, $long_result );
    audit_avela( true === $written && strlen( $_COOKIE[ Avela_Access::COOKIE ] ) <= 3800 && Avela_Access::allowed( $post_id ), 'A full consent cookie evicts older entries and retains the newly confirmed post' );
    remove_filter( 'avela_required_provider', $long_required );
    $_COOKIE = array( Avela_Access::CSRF => str_repeat( 'a', 48 ) );

    $broken_required = function () { return 'broken'; };
    $broken_providers = function () {
        return array( 'broken' => new class implements Avela_Age_Provider {
            public function verify( $post_id, array $input ) { throw new RuntimeException( 'PRIVATE_PROVIDER_EXCEPTION' ); }
        } );
    };
    $die_handler = function () {
        return function ( $message, $title, $args ) { throw new RuntimeException( (string) $message, $args['response'] ); };
    };
    add_filter( 'avela_required_provider', $broken_required );
    add_filter( 'avela_providers', $broken_providers );
    add_filter( 'wp_die_handler', $die_handler );
    $old_method = $_SERVER['REQUEST_METHOD'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array( 'action' => 'avela_confirm', 'post_id' => (string) $post_id, 'avela_token' => Avela_Access::token( $post_id, 'confirm' ) );
    try {
        Avela_Access::submit();
        $closed_error = false;
    } catch ( RuntimeException $e ) {
        $closed_error = 503 === $e->getCode() && false === strpos( $e->getMessage(), 'PRIVATE_PROVIDER_EXCEPTION' ) && ! isset( $_COOKIE[ Avela_Access::COOKIE ] );
    } finally {
        remove_filter( 'avela_required_provider', $broken_required );
        remove_filter( 'avela_providers', $broken_providers );
        remove_filter( 'wp_die_handler', $die_handler );
        $_POST = array();
        if ( null === $old_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $old_method; }
    }
    audit_avela( $closed_error, 'Provider exception yields a closed generic 503 without disclosing details' );

    $public_post = fixture_avela( 'post', 'Avela public shared-media fixture', 'Public text' );
    update_post_meta( $public_post, '_thumbnail_id', $media );
    $media_url = wp_get_upload_dir()['baseurl'] . '/2099/02/avela-security.png';
    audit_avela( in_array( $public_post, Avela_Media::public_uses( $media, $post_id ), true ), 'Editor can detect a restricted file reused by a public post' );
    audit_avela( array() === Avela_Public::sitemap_images( array( array( 'src' => $media_url ) ), $public_post ), 'Public image sitemap omits restricted shared files too' );
    audit_avela( '' === Avela_Public::seo_image( $media_url ), 'Open Graph adapters omit restricted files on public posts too' );
    audit_avela( false === strpos( Avela_Public::strip_media_html( '<meta property="og:image" content="' . $media_url . '">' ), 'avela-security.png' ), 'Raw social-image tags cannot expose a restricted shared file' );
    $wpdb->update( $wpdb->posts, array( 'post_excerpt' => 'AVELA_CAPTION_SECRET', 'post_mime_type' => 'image/png' ), array( 'ID' => $media ) );
    clean_post_cache( $media );
    audit_avela( null === wp_prepare_attachment_for_js( $media ), 'Media-library JSON is hidden from a viewer without edit permission' );
    $GLOBALS['post'] = get_post( $public_post );
    $gallery = do_shortcode( '[gallery ids="' . $media . '"]' );
    audit_avela( false === strpos( $gallery, 'AVELA_CAPTION_SECRET' ), 'Gallery include cannot reveal the caption of a restricted file' );

    set_error_handler( function ( $severity, $message ) { throw new RuntimeException( $message ); } );
    try {
        $malformed = ! Avela_Access::valid_token( $post_id, 'confirm', array( 'bad' ) );
    } catch ( Throwable $e ) {
        $malformed = false;
    } finally {
        restore_error_handler();
    }
    audit_avela( $malformed, 'Array-shaped form token is rejected without a PHP warning' );
    $result = ( new Avela_Local_Provider() )->verify( $post_id, array() );
    $bad_result = $result;
    $bad_result['expires_at'] = array( time() + 3600 );
    audit_avela( is_wp_error( Avela_Access::grant( $post_id, $bad_result ) ), 'Malformed provider expiry cannot issue consent' );
    $bad_result = $result;
    $bad_result['method'] = array( 'document_verified' );
    audit_avela( is_wp_error( Avela_Access::grant( $post_id, $bad_result ) ), 'Malformed provider method cannot issue consent' );

    $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
    if ( $admins ) {
        wp_set_current_user( $admins[0]->ID );
        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        $previous_screen = $GLOBALS['current_screen'] ?? null;
        $GLOBALS['current_screen'] = WP_Screen::get( 'upload' );
        audit_avela( is_array( wp_prepare_attachment_for_js( $media ) ), 'Authorized editor retains complete media-library JSON' );
        $GLOBALS['current_screen'] = $previous_screen;
        $_POST = array( 'avela_nonce' => wp_create_nonce( 'avela_save' ), 'avela_warning' => 'invalid' );
        Avela_Admin::save( $post_id, get_post( $post_id ) );
        audit_avela( Avela_Access::restricted( $post_id ), 'Malformed editor selection cannot silently disable a warning' );
        Avela_Admin::save( $public_post, get_post( $public_post ) );
        audit_avela( Avela_Access::restricted( $public_post ), 'Malformed non-empty selection on an unguarded post enables the safe fallback' );
        update_post_meta( $public_post, '_avela_warning', 0 );
        update_post_meta( $post_id, '_avela_warning', $warning );
        update_post_meta( $post_id, '_avela_teaser', 'AVELA_SECURITY_PUBLIC' );
        $_POST = array();
        wp_set_current_user( 0 );
    }
    Avela_Media::flush();

    if ( in_array( '--http', $argv, true ) ) {
        $before = site_consent_avela( get_permalink( $post_id ) );
        audit_avela( 200 === $before['status'] && false !== strpos( $before['body'], 'avela_token' ), 'Security fixture is reachable behind existing site consent' );
        $media_list = http_avela( add_query_arg( array( 'rest_route' => '/wp/v2/media', 'include' => $media ), home_url( '/' ) ) );
        audit_avela( 200 === $media_list['status'] && array() === json_decode( $media_list['body'], true ), 'REST media include returns an empty collection, not an error object' );
        $feed = http_avela( get_feed_link( 'rss2' ) );
        preg_match( '/^ETag:\s*(.+)\r?$/mi', $feed['headers'], $etag );
        $conditional = http_avela( get_feed_link( 'rss2' ), array(), null, array(
            'If-None-Match: ' . trim( $etag[1] ?? '"previous-public-copy"' ),
            'If-Modified-Since: Wed, 01 Jan 2031 00:00:00 GMT',
        ) );
        audit_avela( 200 === $conditional['status'] && false !== strpos( $conditional['body'], 'AVELA_SECURITY_PUBLIC' ), 'Conditional feed request cannot reuse a pre-restriction body through HTTP 304' );
        preg_match( '/name="avela_token" value="([^"]+)"/', $before['body'], $token );
        $cross_origin = http_avela( admin_url( 'admin-post.php' ), $before['cookies'], array(
            'action' => 'avela_confirm', 'post_id' => $post_id, 'avela_token' => html_entity_decode( $token[1] ?? '' ),
        ), array( 'Origin: https://untrusted.example' ) );
        audit_avela( 403 === $cross_origin['status'], 'Cross-origin confirmation is denied even with a captured form token' );
        $bad_form = http_avela( admin_url( 'admin-post.php' ), $before['cookies'], array(
            'action' => 'avela_confirm', 'post_id' => $post_id, 'avela_token' => array( 'bad' ),
        ) );
        audit_avela( 403 === $bad_form['status'] && false === stripos( $bad_form['body'], 'warning:' ) && false === strpos( $bad_form['body'], 'class-avela-access.php' ), 'Malformed HTTP form does not disclose PHP diagnostics' );
        $atom = http_avela( get_feed_link( 'atom' ) );
        audit_avela( 200 === $atom['status'] && false !== strpos( $atom['body'], 'AVELA_SECURITY_PUBLIC' ) && false === strpos( $atom['body'], 'AVELA_SECURITY_BODY' ), 'Atom exposes only the public teaser' );
        $comments_feed = http_avela( get_post_comments_feed_link( $post_id, 'rss2' ) );
        audit_avela( 200 === $comments_feed['status'] && false === strpos( $comments_feed['body'], 'AVELA_SECURITY_COMMENT' ), 'Post comment feed does not expose protected comments' );
        $embed = http_avela( add_query_arg( array( 'rest_route' => '/oembed/1.0/embed', 'url' => get_permalink( $post_id ) ), home_url( '/' ) ) );
        $embed_data = json_decode( $embed['body'], true );
        audit_avela( 200 === $embed['status'] && ! isset( $embed_data['thumbnail_url'] ) && false === strpos( $embed['body'], 'AVELA_SECURITY_BODY' ), 'Native oEmbed omits protected body and thumbnails' );
        update_post_meta( $post_id, '_avela_adult', 1 );
        $adult_page = http_avela( get_permalink( $post_id ) );
        audit_avela( false !== strpos( $adult_page['body'], 'name="rating" content="adult"' ), 'Explicit adult classification can be enabled independently' );
        if ( $admins ) {
            $session_manager = WP_Session_Tokens::get_instance( $admins[0]->ID );
            $session_token = $session_manager->create( time() + 300 );
            $saved_browser_cookies = $_COOKIE;
            try {
                $login = wp_generate_auth_cookie( $admins[0]->ID, time() + 300, 'logged_in', $session_token );
                $_COOKIE[ LOGGED_IN_COOKIE ] = $login;
                wp_set_current_user( $admins[0]->ID );
                $auth = array( LOGGED_IN_COOKIE => $login, AUTH_COOKIE => wp_generate_auth_cookie( $admins[0]->ID, time() + 300, 'auth', $session_token ) );
                $editor_rest = http_avela( add_query_arg( array( 'rest_route' => '/wp/v2/posts/' . $post_id, 'context' => 'edit' ), home_url( '/' ) ), $auth, null, array( 'X-WP-Nonce: ' . wp_create_nonce( 'wp_rest' ) ) );
                $editor_data = json_decode( $editor_rest['body'], true );
                audit_avela( 200 === $editor_rest['status'] && 'AVELA_SECURITY_BODY' === ( $editor_data['content']['raw'] ?? null ), 'Authenticated editor receives raw content for Gutenberg through real REST' );
            } finally {
                $session_manager->destroy( $session_token );
                $_COOKIE = $saved_browser_cookies;
                wp_set_current_user( 0 );
            }
        }
    }
    echo count( $issues ) ? 'AUDIT FAILED: ' . count( $issues ) . " cases\n" : "SUCCESS: $checks security checks\n";
} catch ( Throwable $e ) {
    $issues[] = $e->getMessage();
    fwrite( STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
} finally {
    cleanup_avela();
    ob_end_flush();
}
exit( $issues ? 1 : 0 );
