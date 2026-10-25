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
    const AGE_COOKIE = 'avela_age';
    const CSRF = 'avela_browser';

    public static function hooks() {
        add_action( 'init', array( __CLASS__, 'browser_cookie' ), 1 );
        foreach ( array( 'admin_post_avela_confirm', 'admin_post_nopriv_avela_confirm',
            'admin_post_avela_reset', 'admin_post_nopriv_avela_reset' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'submit' ) );
        }
    }

    public static function restricted( $id ) {
        foreach ( (array) get_post_meta( $id, '_avela_warning', false ) as $value ) {
            if ( ! is_scalar( $value ) || ( '' !== (string) $value && '0' !== (string) $value ) ) {
                return true;
            }
        }
        return false;
    }

    public static function warning( $id ) {
        $template_id = absint( get_post_meta( $id, '_avela_warning', true ) );
        $post = $template_id > 0 ? get_post( $template_id ) : null;
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
        return self::same_origin( $url ) ? esc_url_raw( $url ) : home_url( '/' );
    }

    public static function same_origin( $url ) {
        if ( ! is_string( $url ) ) {
            return false;
        }
        $home = wp_parse_url( home_url( '/' ) );
        $parts = wp_parse_url( $url );
        return is_array( $parts ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
            && strtolower( $parts['host'] ?? '' ) === strtolower( $home['host'] )
            && ( $parts['scheme'] ?? '' ) === $home['scheme']
            && ( $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 ) ) === ( $home['port'] ?? ( 'https' === $home['scheme'] ? 443 : 80 ) );
    }

    public static function request_origin_allowed() {
        foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) && ! self::same_origin( wp_unslash( $_SERVER[ $header ] ) ) ) {
                return false;
            }
        }
        return true;
    }

    public static function teaser( $id ) {
        // Plain text is deliberate: no shortcode, embed, image or unsafe automatic excerpt.
        $value = get_post_meta( $id, '_avela_teaser', true );
        return is_string( $value ) ? $value : '';
    }

    public static function lifetime() {
        $hours = (int) Avela::settings()['hours'];
        return $hours > 0 ? min( 720, $hours ) * HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS;
    }

    public static function key( $id ) {
        return 'warning' === Avela::settings()['scope']
            ? 'w' . absint( get_post_meta( $id, '_avela_warning', true ) ) : 'p' . absint( $id );
    }

    public static function fingerprint( $id, $current_warning = false ) {
        $warning = self::warning( $id );
        if ( ! $current_warning && ! Avela::settings()['repeat'] ) {
            $warning = array( 'id' => $warning['id'], 'provider' => $warning['provider'] );
        }
        return hash( 'sha256', wp_json_encode( array( $warning, Avela::settings(), get_option( 'avela_policy_epoch', '' ) ) ) );
    }

    public static function sign( array $payload, $purpose = 'grants' ) {
        $body = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
        return $body . '.' . hash_hmac( 'sha256', $body, self::secret( $purpose ) );
    }

    private static function secret( $purpose ) {
        return wp_salt( 'auth' ) . '|avela-v2|' . get_current_blog_id() . '|' . home_url( '/' ) . '|' . $purpose;
    }

    public static function decode( $cookie, $purpose = 'grants' ) {
        if ( ! is_string( $cookie ) || strlen( $cookie ) > 3800 ) {
            return array();
        }
        $parts = explode( '.', $cookie );
        if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], self::secret( $purpose ) ), $parts[1] ) ) {
            return array();
        }
        $json = base64_decode( strtr( $parts[0], '-_', '+/' ), true );
        $data = false === $json ? null : json_decode( $json, true );
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
        $provider = self::warning( $id )['provider'];
        return is_array( $grant ) && isset( $grant['v'], $grant['exp'], $grant['method'], $grant['provider'] )
            && is_string( $grant['v'] ) && hash_equals( self::fingerprint( $id ), $grant['v'] )
            && is_int( $grant['exp'] ) && $grant['exp'] > time() && is_string( $grant['method'] )
            && $grant['provider'] === $provider && null !== self::age_result( $provider );
    }

    public static function cookie( $name, $value, $expires = 0 ) {
        if ( headers_sent() ) {
            return false;
        }
        $result = setcookie( $name, $value, array( 'expires' => $expires, 'path' => '/',
            'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
        if ( $result ) {
            if ( $expires > 0 && $expires < time() ) {
                unset( $_COOKIE[ $name ] );
            } else {
                $_COOKIE[ $name ] = $value;
            }
        }
        return $result;
    }

    public static function browser_cookie() {
        if ( ! is_admin() && ( ! isset( $_COOKIE[ self::CSRF ] ) || ! is_string( $_COOKIE[ self::CSRF ] ) || ! preg_match( '/^[a-f0-9]{48}$/D', $_COOKIE[ self::CSRF ] ) ) ) {
            self::cookie( self::CSRF, bin2hex( random_bytes( 24 ) ) );
        }
    }

    public static function token( $id, $action, $expires = null ) {
        $expires = $expires ?? time() + 2 * HOUR_IN_SECONDS;
        $browser = $_COOKIE[ self::CSRF ] ?? '';
        if ( ! is_string( $browser ) || ! preg_match( '/^[a-f0-9]{48}$/D', $browser ) ) {
            return '';
        }
        return $expires . ':' . hash_hmac( 'sha256', $id . '|' . $action . '|' . $expires . '|'
            . $browser . '|' . self::fingerprint( $id, true ), self::secret( 'form' ) );
    }

    public static function valid_token( $id, $action, $token ) {
        if ( ! is_string( $token ) || strlen( $token ) > 100 || empty( $_COOKIE[ self::CSRF ] ) || ! is_string( $_COOKIE[ self::CSRF ] ) ) {
            return false;
        }
        $parts = explode( ':', $token );
        return 2 === count( $parts ) && ctype_digit( $parts[0] )
            && (int) $parts[0] > time() && (int) $parts[0] <= time() + 2 * HOUR_IN_SECONDS
            && hash_equals( self::token( $id, $action, (int) $parts[0] ), $token );
    }

    public static function grant( $id, array $result ) {
        $now = time();
        if ( ! self::valid_age_result( $result, self::warning( $id )['provider'] ) ) {
            return new WP_Error( 'avela_age_unknown', 'Подтверждение не получено. Материал остаётся закрытым.' );
        }
        // A provider may return a full profile. Persist only the age decision, never its extra data.
        $result = array_intersect_key( $result, array_flip( array( 'status', 'threshold', 'method', 'provider', 'checked_at', 'expires_at' ) ) );
        $result['expires_at'] = min( $result['expires_at'], $now + self::lifetime() );
        $ages = self::decode( $_COOKIE[ self::AGE_COOKIE ] ?? '', 'age' );
        $ages[ $result['provider'] ] = $result;
        $ages = array_slice( $ages, -4, null, true );
        $age_cookie = self::sign( $ages, 'age' );
        if ( strlen( $age_cookie ) > 3800 || ! self::cookie( self::AGE_COOKIE, $age_cookie, Avela::settings()['hours'] ? $now + self::lifetime() : 0 ) ) {
            return false;
        }
        $grants = array_filter( self::decode( $_COOKIE[ self::COOKIE ] ?? '' ), function ( $g ) use ( $now ) {
            return is_array( $g ) && ( $g['exp'] ?? 0 ) > $now;
        } );
        unset( $grants[ self::key( $id ) ] );
        $grants = array_slice( $grants, -11, null, true );
        $grants[ self::key( $id ) ] = array( 'v' => self::fingerprint( $id ),
            'exp' => min( $result['expires_at'], $now + self::lifetime() ),
            'method' => $result['method'], 'provider' => $result['provider'], 'checked_at' => $result['checked_at'] );
        return self::write_grants( $grants );
    }

    private static function write_grants( array $grants ) {
        while ( count( $grants ) > 1 && strlen( self::sign( $grants ) ) > 3800 ) {
            array_shift( $grants );
        }
        $cookie = self::sign( $grants );
        if ( strlen( $cookie ) > 3800 ) {
            return false;
        }
        return self::cookie( self::COOKIE, $grants ? $cookie : '', $grants
            ? ( Avela::settings()['hours'] ? time() + self::lifetime() : 0 ) : time() - HOUR_IN_SECONDS );
    }

    public static function valid_age_result( array $result, $provider ) {
        $now = time();
        return 'passed' === ( $result['status'] ?? '' ) && isset( $result['threshold'], $result['method'], $result['provider'], $result['checked_at'], $result['expires_at'] )
            && is_int( $result['threshold'] ) && $result['threshold'] >= 18
            && is_string( $result['method'] ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $result['method'] )
            && is_string( $result['provider'] ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $result['provider'] ) && $result['provider'] === $provider
            && is_int( $result['checked_at'] ) && $result['checked_at'] > 0 && $result['checked_at'] <= $now
            && is_int( $result['expires_at'] ) && $result['expires_at'] > $now;
    }

    public static function age_result( $provider ) {
        $ages = self::decode( $_COOKIE[ self::AGE_COOKIE ] ?? '', 'age' );
        $result = $ages[ $provider ] ?? null;
        return is_array( $result ) && self::valid_age_result( $result, $provider ) ? $result : null;
    }

    public static function submit() {
        Avela_Public::private_headers();
        $id = isset( $_POST['post_id'] ) && is_string( $_POST['post_id'] ) && preg_match( '/^[1-9][0-9]*$/D', $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $action = 'avela_reset' === ( $_POST['action'] ?? '' ) ? 'reset' : 'confirm';
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::request_origin_allowed() || ! self::restricted( $id )
            || ! is_post_publicly_viewable( $id ) || ! self::valid_token( $id, $action, wp_unslash( $_POST['avela_token'] ?? '' ) ) ) {
            wp_die( 'Форма устарела. Вернитесь к статье и обновите страницу.', 'Avela', array( 'response' => 403 ) );
        }
        if ( 'reset' === $action ) {
            $grants = self::decode( $_COOKIE[ self::COOKIE ] ?? '' );
            unset( $grants[ self::key( $id ) ] );
            if ( ! self::write_grants( $grants ) ) {
                wp_die( 'Не удалось сбросить подтверждение. Повторите попытку.', 'Avela', array( 'response' => 503 ) );
            }
        } else {
            $providers = apply_filters( 'avela_providers', array( 'local' => new Avela_Local_Provider() ) );
            $required = self::warning( $id )['provider'];
            $provider = is_array( $providers ) ? ( $providers[ $required ] ?? null ) : null;
            $result = self::age_result( $required );
            if ( null === $result && ! $provider instanceof Avela_Age_Provider ) {
                wp_die( 'Способ подтверждения сейчас недоступен. Материал остаётся закрытым.', 'Avela', array( 'response' => 503 ) );
            }
            if ( null === $result ) {
                try {
                    $result = $provider->verify( $id, wp_unslash( $_POST ) );
                } catch ( Throwable $error ) {
                    wp_die( 'Сервис подтверждения сейчас недоступен. Материал остаётся закрытым.', 'Avela', array( 'response' => 503 ) );
                }
            }
            $saved = is_array( $result ) ? self::grant( $id, $result )
                : new WP_Error( 'avela_age_unknown', 'Сервис не вернул подтверждение. Материал остаётся закрытым.' );
            if ( is_wp_error( $saved ) ) {
                wp_die( esc_html( $saved->get_error_message() ), 'Avela', array( 'response' => 403 ) );
            }
            if ( ! $saved ) {
                wp_die( 'Подтверждение не сохранено. Разрешите cookie и повторите попытку.', 'Avela', array( 'response' => 403 ) );
            }
        }
        wp_safe_redirect( get_permalink( $id ), 303 );
        exit;
    }
}
