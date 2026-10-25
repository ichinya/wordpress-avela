<?php
/** Integration checks against a local WordPress instance. */
require __DIR__ . '/bootstrap.php';

try {
    wp_set_current_user( 0 );
    update_option( 'avela_settings', array( 'scope' => 'post', 'hours' => 0, 'repeat' => 1, 'reset' => 1, 'images' => 1 ) );
    $_COOKIE = array( Avela_Access::CSRF => str_repeat( 'a', 48 ) );
    $secret = 'AVELA_SECRET_' . bin2hex( random_bytes( 8 ) );
    $comment_secret = 'AVELA_COMMENT_' . bin2hex( random_bytes( 8 ) );
    $warning = fixture_avela( 'avela_warning', 'Avela temporary warning', 'Предупреждение о чувствительном материале.' );
    $post_id = fixture_avela( 'post', 'Avela · временная проверка', $secret . '<p>Полный материал после подтверждения.</p>' );
    $other = fixture_avela( 'post', 'Avela · другое предупреждение', $secret );
    update_post_meta( $post_id, '_avela_warning', $warning );
    update_post_meta( $post_id, '_avela_teaser', 'Безопасный публичный анонс.' );
    update_post_meta( $other, '_avela_warning', $warning );
    $media = fixture_avela( 'attachment', 'AVELA_PRIVATE_MEDIA', '' );
    update_post_meta( $media, '_wp_attached_file', '2099/01/avela-secret-fixture.png' );
    update_post_meta( $media, '_wp_attachment_metadata', array( 'file' => '2099/01/avela-secret-fixture.png',
        'width' => 20, 'height' => 20, 'sizes' => array( 'thumbnail' => array( 'file' => 'avela-secret-fixture-10x10.png', 'width' => 10, 'height' => 10 ) ) ) );
    update_post_meta( $post_id, '_thumbnail_id', $media );
    $inline = wp_get_upload_dir()['baseurl'] . '/2099/01/avela-secret-fixture.png';
    $wpdb->update( $wpdb->posts, array( 'post_content' => $secret . '<p>Полный материал после подтверждения.</p><img src="' . esc_url( $inline ) . '" class="wp-image-' . $media . '" alt="">' ), array( 'ID' => $post_id ) );
    clean_post_cache( $post_id );
    Avela_Media::sync( $post_id );
    Avela_Media::sync( $other );
    $comment = wp_insert_comment( array( 'comment_post_ID' => $post_id, 'comment_content' => $comment_secret,
        'comment_author' => 'Avela test', 'comment_approved' => 1 ) );
    $GLOBALS['post'] = get_post( $post_id );
    setup_postdata( $GLOBALS['post'] );
    check_avela( ! Avela_Access::allowed( $post_id ), 'Fresh visitor is locked' );
    $html = apply_filters( 'the_content', $secret );
    check_avela( false === strpos( $html, $secret ) && false !== strpos( $html, 'Безопасный' ), 'Content filter exposes only manual teaser' );
    check_avela( false === strpos( get_the_excerpt( $post_id ), $secret ), 'Automatic excerpt never exposes body' );
    check_avela( 0 === get_post_thumbnail_id( $post_id ), 'Featured image is hidden' );
    check_avela( Avela_Media::hidden( $media ), 'Restricted media tracked through featured-image ownership' );
    check_avela( in_array( $media, Avela_Media::index()[ $post_id ]['ids'], true ), 'Indexer reads original metadata without public redaction' );
    check_avela( false !== strpos( Avela_Media::robots( "User-agent: *\nDisallow: /wp-admin/\n\nUser-agent: Googlebot\nAllow: /\n", true ), 'Disallow: /wp-content/uploads/2099/01/avela-secret-fixture*' ), 'Robots rules cover image variants' );
    check_avela( 2 === substr_count( Avela_Media::robots( "User-agent: *\n\nUser-agent: Googlebot\n", true ), 'avela-secret-fixture*' ), 'Rules apply to specific crawler groups too' );
    check_avela( ! get_comments( array( 'post_id' => $post_id, 'status' => 'approve' ) ), 'Comment queries hide protected comments' );
    check_avela( ! comments_open( $post_id ) && 0 === (int) get_comments_number( $post_id ), 'Comment form and counts are hidden' );
    $token = Avela_Access::token( $post_id, 'confirm' );
    check_avela( Avela_Access::valid_token( $post_id, 'confirm', $token ), 'Browser-bound confirmation token works' );
    $_COOKIE[ Avela_Access::CSRF ] = str_repeat( 'b', 48 );
    check_avela( ! Avela_Access::valid_token( $post_id, 'confirm', $token ), 'Token cannot be used by a different browser' );
    $_COOKIE[ Avela_Access::CSRF ] = str_repeat( 'a', 48 );
    check_avela( ! Avela_Access::valid_token( $post_id, 'reset', $token ), 'Confirm token cannot reset consent' );
    check_avela( ! Avela_Access::valid_token( $post_id, 'confirm', Avela_Access::token( $post_id, 'confirm', time() - 1 ) ), 'Expired forms fail closed' );
    $result = ( new Avela_Local_Provider() )->verify( $post_id, array() );
    $_COOKIE[ Avela_Access::AGE_COOKIE ] = Avela_Access::sign( array( 'local' => $result ), 'age' );
    // Set signed state without emitting headers, since the CLI has printed check output.
    $grant = array( 'v' => Avela_Access::fingerprint( $post_id ), 'exp' => time() + 3600,
        'method' => 'self_declared', 'provider' => 'local', 'checked_at' => time() );
    $_COOKIE[ Avela_Access::COOKIE ] = Avela_Access::sign( array( Avela_Access::key( $post_id ) => $grant ) );
    check_avela( Avela_Access::allowed( $post_id ), 'Valid signed confirmation opens the post' );
    check_avela( ! Avela_Access::allowed( $other ), 'Default consent does not open another post' );
    $saved = $_COOKIE[ Avela_Access::COOKIE ];
    $_COOKIE[ Avela_Access::COOKIE ] .= 'x';
    check_avela( ! Avela_Access::allowed( $post_id ), 'Tampered cookie is rejected' );
    $_COOKIE[ Avela_Access::COOKIE ] = $saved;
    $expired = $grant;
    $expired['exp'] = time() - 1;
    $_COOKIE[ Avela_Access::COOKIE ] = Avela_Access::sign( array( Avela_Access::key( $post_id ) => $expired ) );
    check_avela( ! Avela_Access::allowed( $post_id ), 'Expired signed consent cannot reopen material' );
    $_COOKIE[ Avela_Access::COOKIE ] = $saved;
    update_post_meta( $warning, '_avela_revision', 'changed' );
    check_avela( ! Avela_Access::allowed( $post_id ), 'Changed warning invalidates prior consent' );
    update_post_meta( $post_id, '_avela_warning', 999999999 );
    check_avela( ! Avela_Access::allowed( $post_id ) && Avela_Access::restricted( $post_id ), 'Missing template fails closed' );
    update_post_meta( $post_id, '_avela_warning', $warning );
    check_avela( is_wp_error( Avela_Access::grant( $post_id, array( 'status' => 'unknown' ) ) ), 'Unknown age result cannot issue consent' );
    $required = function () { return 'external'; };
    add_filter( 'avela_required_provider', $required );
    check_avela( is_wp_error( Avela_Access::grant( $post_id, $result ) ), 'Required external provider never falls back to local declaration' );
    remove_filter( 'avela_required_provider', $required );
    check_avela( home_url( '/' ) === Avela_Access::safe_exit( 'https://example.org/exit' ), 'External exit redirect is rejected' );
    $settings = Avela::settings();
    $settings['scope'] = 'warning';
    update_option( 'avela_settings', $settings );
    $grant['v'] = Avela_Access::fingerprint( $post_id );
    $_COOKIE[ Avela_Access::COOKIE ] = Avela_Access::sign( array( Avela_Access::key( $post_id ) => $grant ) );
    check_avela( Avela_Access::allowed( $other ), 'Warning-scoped consent applies to matching template only' );
    $settings['scope'] = 'post';
    update_option( 'avela_settings', $settings );
    $_COOKIE = array( Avela_Access::CSRF => str_repeat( 'a', 48 ) );
    update_post_meta( $other, '_thumbnail_id', $media );
    Avela_Media::sync( $other );
    update_post_meta( $post_id, '_avela_warning', 0 );
    Avela_Media::sync( $post_id );
    check_avela( Avela_Media::hidden( $media ), 'Removing one restriction keeps shared media protected' );
    update_post_meta( $post_id, '_avela_warning', $warning );
    Avela_Media::sync( $post_id );
    $GLOBALS['post'] = get_post( $post_id );
    $raw = get_post( $post_id )->post_content;
    $query = new WP_Query( array( 'p' => $post_id ) );
    check_avela( false === strpos( $query->posts[0]->post_content, $secret ), 'Public query object is redacted before head/template output' );
    check_avela( $raw === get_post( $post_id )->post_content, 'Redaction never corrupts raw object cache' );
    $hidden_search = new WP_Query( array( 's' => $secret, 'fields' => 'ids' ) );
    check_avela( ! in_array( $post_id, $hidden_search->posts, true ), 'Public search cannot match closed body text' );
    $teaser_search = new WP_Query( array( 's' => 'Безопасный публичный анонс', 'fields' => 'ids' ) );
    check_avela( in_array( $post_id, $teaser_search->posts, true ), 'Public search matches the manual teaser' );

    if ( in_array( '--http', $argv, true ) ) {
        check_avela( in_array( 'avela/avela.php', get_option( 'active_plugins', array() ), true ), 'Plugin active for real HTTP checks' );
        // Persist current registry before separate HTTP processes read it.
        Avela_Media::flush();
        $url = get_permalink( $post_id );
        $before = http_avela( $url );
        if ( false !== strpos( $before['body'], 'name="liliadna_consent_token"' ) ) {
            preg_match( '/name="liliadna_consent_token" value="([^"]+)"/', $before['body'], $theme_token );
            $site_consent = http_avela( home_url( '/' ), $before['cookies'], array(
                'liliadna_consent_token' => html_entity_decode( $theme_token[1] ),
                'liliadna_consent_return' => $url, 'liliadna_consent_age' => '1', 'liliadna_consent_action' => 'allow',
            ) );
            check_avela( 303 === $site_consent['status'], 'Existing theme consent completed without changing theme settings' );
            $GLOBALS['avela_http_site_cookies'] = array_intersect_key( $site_consent['cookies'], array_flip( array( 'liliadna_access', 'liliadna_consent_csrf' ) ) );
            $before = http_avela( $url );
        }
        if ( ! is_dir( dirname( __DIR__ ) . '/artifacts' ) ) {
            mkdir( dirname( __DIR__ ) . '/artifacts' );
        }
        file_put_contents( dirname( __DIR__ ) . '/artifacts/before.html', $before['body'] );
        check_avela( 200 === $before['status'], 'Article retains URL and HTTP 200' );
        check_avela( false === strpos( $before['body'], $secret ) && false === strpos( $before['body'], $comment_secret ), 'HTTP source contains neither body nor comment secrets' );
        check_avela( false === strpos( $before['body'], 'avela-secret-fixture' ), 'HTTP source contains no protected image URLs or srcset' );
        $home = http_avela( home_url( '/' ) );
        check_avela( false === strpos( $home['body'], $secret ) && false === strpos( $home['body'], 'avela-secret-fixture' ), 'Home cards do not leak inline cover fallbacks or body text' );
        check_avela( false !== strpos( $before['body'], 'name="avela_token"' ), 'Server renders usable plain HTML form' );
        check_avela( false !== stripos( $before['headers'], 'private, no-store' ), 'Public HTML has private no-store headers' );
        check_avela( false !== strpos( $before['body'], 'name="description" content="Безопасный публичный анонс.' ), 'Metadata uses the manual public teaser' );
        check_avela( false === strpos( $before['body'], 'name="rating" content="adult"' ), 'Adult rating is off by default' );
        $rest = http_avela( add_query_arg( array( 'rest_route' => '/wp/v2/posts/' . $post_id, '_embed' => 1 ), home_url( '/' ) ) );
        check_avela( 200 === $rest['status'] && false === strpos( $rest['body'], $secret ) && false === strpos( $rest['body'], 'avela-secret-fixture' ), 'REST with embeds does not leak body/media' );
        $rest_media = http_avela( add_query_arg( 'rest_route', '/wp/v2/media/' . $media, home_url( '/' ) ) );
        check_avela( 403 === $rest_media['status'] && false === strpos( $rest_media['body'], 'avela-secret-fixture' ), 'Direct REST media request is denied' );
        $rest_comment = http_avela( add_query_arg( 'rest_route', '/wp/v2/comments/' . $comment, home_url( '/' ) ) );
        check_avela( 403 === $rest_comment['status'] && false === strpos( $rest_comment['body'], $comment_secret ), 'Direct REST comment request is denied' );
        $feed = http_avela( add_query_arg( 'feed', 'rss2', home_url( '/' ) ) );
        file_put_contents( dirname( __DIR__ ) . '/artifacts/feed.txt', $feed['headers'] . $feed['body'] );
        check_avela( false === strpos( $feed['body'], $secret ) && false !== strpos( $feed['body'], 'Безопасный' ), 'RSS contains only the teaser' );
        $robots = http_avela( home_url( '/robots.txt' ) );
        check_avela( false !== strpos( $robots['body'], 'avela-secret-fixture*' ), 'Actual robots.txt contains file exclusion rules' );
        preg_match( '/name="avela_token" value="([^"]+)"/', $before['body'], $match );
        $fields = array( 'action' => 'avela_confirm', 'post_id' => $post_id, 'avela_token' => html_entity_decode( $match[1] ?? '' ) );
        $bad = http_avela( admin_url( 'admin-post.php' ), array(), $fields );
        check_avela( 403 === $bad['status'], 'Cross-browser HTTP form submission is denied' );
        $accepted = http_avela( admin_url( 'admin-post.php' ), $before['cookies'], $fields );
        check_avela( 303 === $accepted['status'] && isset( $accepted['cookies'][ Avela_Access::COOKIE ] ), 'No-JavaScript POST issues signed cookie and returns 303' );
        $after = http_avela( $url, $accepted['cookies'] );
        check_avela( 200 === $after['status'] && false !== strpos( $after['body'], $secret ), 'Accepted browser receives complete body' );
        check_avela( false !== strpos( $after['body'], $comment_secret ), 'Accepted browser receives comments' );
        check_avela( false !== stripos( $after['headers'], 'private, no-store' ), 'Accepted response remains private no-store' );
        $fresh = http_avela( $url );
        check_avela( false === strpos( $fresh['body'], $secret ), 'Fresh visitor after accepted visit still gets warning' );
        $accepted_feed = http_avela( add_query_arg( 'feed', 'rss2', home_url( '/' ) ), $accepted['cookies'] );
        check_avela( false === strpos( $accepted_feed['body'], $secret ), 'Feed stays public even with accepted cookie' );
        preg_match( '/name="avela_token" value="([^"]+)"/', $after['body'], $reset_match );
        $reset = http_avela( admin_url( 'admin-post.php' ), $accepted['cookies'], array( 'action' => 'avela_reset', 'post_id' => $post_id, 'avela_token' => html_entity_decode( $reset_match[1] ?? '' ) ) );
        check_avela( 303 === $reset['status'], 'Reset uses the same no-JavaScript form flow' );
        $hidden = http_avela( $url, $reset['cookies'] );
        check_avela( false === strpos( $hidden['body'], $secret ), 'Reset hides the material again' );
    }
    echo "SUCCESS: $checks checks\n";
} catch ( Throwable $e ) {
    fwrite( STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
    $failed = true;
} finally {
    cleanup_avela();
}

exit( empty( $failed ) ? 0 : 1 );
