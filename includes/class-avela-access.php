<?php
defined( 'ABSPATH' ) || exit;

/** Providers must return passed/failed/unknown, threshold, method, provider, checked_at, expires_at. */
interface Avela_Age_Provider {
    public function verify( $post_id, array $input );
}

final class Avela_Local_Provider implements Avela_Age_Provider {
    public function verify( $post_id, array $input ) {
        return array( 'status' => 'passed', 'threshold' => 18, 'method' => 'self_declared',
            'provider' => 'local', 'checked_at' => time(), 'expires_at' => time() + Avela_Access::lifetime() );
    }
}

final class Avela_Access {
    const COOKIE = 'avela_grants';
    const CSRF = 'avela_browser';

    public static function hooks() {
        add_action( 'init', array( __CLASS__, 'browser_cookie' ), 1 );
        foreach ( array( 'admin_post_avela_confirm', 'admin_post_nopriv_avela_confirm',
            'admin_post_avela_reset', 'admin_post_nopriv_avela_reset' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'submit' ) );
        }
    }

    public static function restricted( $id ) {
        $value = get_post_meta( $id, '_avela_warning', true );
        return '' !== (string) $value && '0' !== (string) $value;
    }

    public static function warning( $id ) {
        $template_id = absint( get_post_meta( $id, '_avela_warning', true ) );
        $post = get_post( $template_id );
        $valid = $post && 'avela_warning' === $post->post_type;
        // Archived/deleted templates always keep their posts gated.
        return array(
            'id' => $template_id,
            'heading' => $valid ? ( get_post_meta( $template_id, '_avela_heading', true ) ?: 'Материал для совершеннолетних' ) : 'Материал для совершеннолетних',
            'body' => $valid ? $post->post_content : 'Материал предназначен для совершеннолетних. Подтвердите, что вам исполнилось 18 лет.',
            'confirm' => $valid ? ( get_post_meta( $template_id, '_avela_confirm', true ) ?: 'Мне исполнилось 18 лет — читать' ) : 'Мне исполнилось 18 лет — читать',
            'exit_label' => $valid ? ( get_post_meta( $template_id, '_avela_exit_label', true ) ?: 'Вернуться на главную' ) : 'Вернуться на главную',
            'exit_url' => self::safe_exit( $valid ? get_post_meta( $template_id, '_avela_exit_url', true ) : '' ),
            'revision' => $valid ? (string) get_post_meta( $template_id, '_avela_revision', true ) : 'missing',
            'provider' => (string) apply_filters( 'avela_required_provider', 'local', $id ),
        );
    }

    public static function safe_exit( $url ) {
        $home = wp_parse_url( home_url( '/' ) );
        $parts = wp_parse_url( $url );
        if ( ! $parts || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] )
            || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
            || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
            return home_url( '/' );
        }
        return esc_url_raw( $url );
    }

    public static function teaser( $id ) {
        // Plain text is deliberate: no shortcode, embed, image or unsafe automatic excerpt.
        return (string) get_post_meta( $id, '_avela_teaser', true );
    }

    public static function lifetime() {
        $hours = (int) Avela::settings()['hours'];
        return $hours > 0 ? min( 720, $hours ) * HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS;
    }

    public static function key( $id ) {
        return 'warning' === Avela::settings()['scope']
            ? 'w' . absint( get_post_meta( $id, '_avela_warning', true ) ) : 'p' . absint( $id );
    }

    public static function fingerprint( $id ) {
        $warning = self::warning( $id );
        if ( ! Avela::settings()['repeat'] ) {
            $warning = array( 'id' => $warning['id'], 'provider' => $warning['provider'] );
        }
        return hash( 'sha256', wp_json_encode( array( $warning, Avela::settings(), get_option( 'avela_policy_epoch', '' ) ) ) );
    }

    public static function sign( array $payload ) {
        $body = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
        return $body . '.' . hash_hmac( 'sha256', $body, wp_salt( 'auth' ) . '|avela|' . get_current_blog_id() );
    }

    public static function decode( $cookie ) {
        if ( ! is_string( $cookie ) || strlen( $cookie ) > 3800 ) {
            return array();
        }
        $parts = explode( '.', $cookie );
        if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) . '|avela|' . get_current_blog_id() ), $parts[1] ) ) {
            return array();
        }
        $data = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
        return is_array( $data ) ? $data : array();
    }

    public static function editing( $id ) {
        return current_user_can( 'edit_post', $id ) && ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) );
    }

    public static function allowed( $id ) {
        if ( ! self::restricted( $id ) ) {
            return true;
        }
        // Feeds and embeds are always public, even for an accepted visitor.
        if ( is_feed() || is_embed() ) {
            return false;
        }
        if ( isset( $_GET['avela_preview'] ) && current_user_can( 'edit_post', $id )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_avela_preview_nonce'] ?? '' ) ), 'avela_preview_' . $id ) ) {
            return 'after' === $_GET['avela_preview'];
        }
        $grants = self::decode( $_COOKIE[ self::COOKIE ] ?? '' );
        $grant = $grants[ self::key( $id ) ] ?? array();
        return isset( $grant['v'], $grant['exp'], $grant['method'], $grant['provider'] )
            && is_string( $grant['v'] ) && hash_equals( self::fingerprint( $id ), $grant['v'] )
            && $grant['exp'] > time() && $grant['provider'] === self::warning( $id )['provider'];
    }

    public static function cookie( $name, $value, $expires = 0 ) {
        if ( headers_sent() ) {
            return false;
        }
        $result = setcookie( $name, $value, array( 'expires' => $expires, 'path' => '/',
            'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
        $_COOKIE[ $name ] = $value;
        return $result;
    }

    public static function browser_cookie() {
        if ( ! is_admin() && ( ! isset( $_COOKIE[ self::CSRF ] ) || ! is_string( $_COOKIE[ self::CSRF ] ) || ! preg_match( '/^[a-f0-9]{48}$/D', $_COOKIE[ self::CSRF ] ) ) ) {
            self::cookie( self::CSRF, bin2hex( random_bytes( 24 ) ) );
        }
    }

    public static function token( $id, $action, $expires = null ) {
        $expires = $expires ?? time() + 2 * HOUR_IN_SECONDS;
        return $expires . ':' . hash_hmac( 'sha256', $id . '|' . $action . '|' . $expires . '|'
            . ( $_COOKIE[ self::CSRF ] ?? '' ) . '|' . self::fingerprint( $id ), wp_salt( 'nonce' ) );
    }

    public static function valid_token( $id, $action, $token ) {
        $parts = explode( ':', (string) $token );
        return is_string( $token ) && ! empty( $_COOKIE[ self::CSRF ] ) && is_string( $_COOKIE[ self::CSRF ] ) && 2 === count( $parts ) && ctype_digit( $parts[0] )
            && (int) $parts[0] > time() && (int) $parts[0] <= time() + 2 * HOUR_IN_SECONDS
            && hash_equals( self::token( $id, $action, (int) $parts[0] ), $token );
    }

    public static function grant( $id, array $result ) {
        $now = time();
        if ( 'passed' !== ( $result['status'] ?? '' ) || (int) ( $result['threshold'] ?? 0 ) < 18
            || empty( $result['method'] ) || ( $result['provider'] ?? '' ) !== self::warning( $id )['provider']
            || empty( $result['checked_at'] ) || $result['checked_at'] > $now || ( $result['expires_at'] ?? 0 ) <= $now ) {
            return new WP_Error( 'avela_age_unknown', 'Подтверждение не получено. Материал остаётся закрытым.' );
        }
        $grants = array_filter( self::decode( $_COOKIE[ self::COOKIE ] ?? '' ), function ( $g ) use ( $now ) {
            return is_array( $g ) && ( $g['exp'] ?? 0 ) > $now;
        } );
        unset( $grants[ self::key( $id ) ] );
        $grants = array_slice( $grants, -11, null, true );
        $grants[ self::key( $id ) ] = array( 'v' => self::fingerprint( $id ),
            'exp' => min( $result['expires_at'], $now + self::lifetime() ),
            'method' => $result['method'], 'provider' => $result['provider'], 'checked_at' => $result['checked_at'] );
        return self::cookie( self::COOKIE, self::sign( $grants ), Avela::settings()['hours'] ? $now + self::lifetime() : 0 );
    }

    public static function submit() {
        $id = absint( $_POST['post_id'] ?? 0 );
        $action = 'avela_reset' === ( $_POST['action'] ?? '' ) ? 'reset' : 'confirm';
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::restricted( $id )
            || ! is_post_publicly_viewable( $id ) || ! self::valid_token( $id, $action, wp_unslash( $_POST['avela_token'] ?? '' ) ) ) {
            wp_die( 'Форма устарела. Вернитесь к статье и обновите страницу.', 'Avela', array( 'response' => 403 ) );
        }
        nocache_headers();
        if ( 'reset' === $action ) {
            $grants = self::decode( $_COOKIE[ self::COOKIE ] ?? '' );
            unset( $grants[ self::key( $id ) ] );
            self::cookie( self::COOKIE, self::sign( $grants ), Avela::settings()['hours'] ? time() + self::lifetime() : 0 );
        } else {
            $providers = apply_filters( 'avela_providers', array( 'local' => new Avela_Local_Provider() ) );
            $provider = $providers[ self::warning( $id )['provider'] ] ?? null;
            if ( ! $provider instanceof Avela_Age_Provider ) {
                wp_die( 'Способ подтверждения сейчас недоступен. Материал остаётся закрытым.', 'Avela', array( 'response' => 503 ) );
            }
            $result = $provider->verify( $id, wp_unslash( $_POST ) );
            $saved = is_array( $result ) ? self::grant( $id, $result ) : false;
            if ( is_wp_error( $saved ) || ! $saved ) {
                wp_die( 'Подтверждение не сохранено. Разрешите cookie и повторите попытку.', 'Avela', array( 'response' => 403 ) );
            }
        }
        wp_safe_redirect( get_permalink( $id ), 303 );
        exit;
    }
}
