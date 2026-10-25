<?php
defined( 'ABSPATH' ) || exit;

final class Avela_Media {
    private static $pending = array();
    private static $building = false;
    private static $index = null;

    public static function hooks() {
        add_action( 'save_post', array( __CLASS__, 'changed' ), 100, 1 );
        foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'meta_changed' ), 100, 4 );
        }
        add_action( 'before_delete_post', array( __CLASS__, 'changed' ) );
        add_action( 'deleted_post', array( __CLASS__, 'invalidate' ) );
        add_action( 'shutdown', array( __CLASS__, 'flush' ) );
        add_action( 'avela_rebuild_media', array( __CLASS__, 'sync' ) );
        add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 100, 2 );
    }

    public static function index() {
        if ( null !== self::$index ) {
            return self::$index;
        }
        global $wpdb;
        // The authoritative list comes from warning assignments, never a disposable derived index.
        $ids = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_avela_warning' AND m.meta_value <> '' AND m.meta_value <> '0'" ) );
        update_meta_cache( 'post', $ids );
        self::$index = array();
        foreach ( $ids as $id ) {
            $entry = get_post_meta( $id, '_avela_media_index', true );
            if ( ! is_array( $entry ) || ! isset( $entry['ids'], $entry['urls'] )
                || ! is_array( $entry['ids'] ) || ! is_array( $entry['urls'] ) ) {
                $entry = array( 'ids' => array(), 'urls' => array(), 'refs' => array(), 'ready' => false );
            }
            if ( get_post_meta( $id, '_avela_media_dirty', true ) || ( $entry['revision'] ?? '' ) !== (string) get_post_meta( $id, '_avela_media_revision', true ) ) {
                $entry['ready'] = false;
            }
            self::$index[ $id ] = $entry;
        }
        return self::$index;
    }

    public static function invalidate() {
        self::$index = null;
    }

    public static function building() {
        return self::$building;
    }

    public static function changed( $id ) {
        if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) {
            return;
        }
        $type = get_post_type( $id );
        if ( 'avela_warning' === $type ) {
            self::$pending['purge'] = true;
            return;
        }
        if ( 'attachment' === $type ) {
            foreach ( self::owners( $id ) as $owner ) {
                self::changed( $owner );
            }
            return;
        }
        if ( 'wp_block' === $type ) {
            foreach ( self::index() as $owner => $entry ) {
                if ( $owner !== (int) $id && ( in_array( (int) $id, $entry['refs'] ?? array(), true ) || empty( $entry['ready'] ) ) ) {
                    self::changed( $owner );
                }
            }
            return;
        }
        if ( Avela_Access::restricted( $id ) || get_post_meta( $id, '_avela_media_index', true ) ) {
            self::$pending[ absint( $id ) ] = true;
            // Readers seeing an unfinished update must keep media closed until its index is ready.
            update_post_meta( $id, '_avela_media_revision', wp_generate_uuid4() );
            update_post_meta( $id, '_avela_media_dirty', 1 );
            self::invalidate();
        }
    }

    public static function meta_changed( $meta_id, $id, $key, $value ) {
        if ( in_array( $key, array( '_avela_media_index', '_avela_media_dirty', '_avela_media_revision', '_avela_warning' ), true ) ) {
            self::invalidate();
        }
        if ( in_array( $key, array( '_avela_warning', '_avela_teaser', '_avela_adult', '_thumbnail_id', '_wp_attachment_metadata', '_wp_attached_file' ), true ) ) {
            self::changed( $id );
        }
    }

    public static function flush() {
        if ( ! self::$pending ) {
            return;
        }
        $ids = array_keys( self::$pending );
        self::$pending = array();
        foreach ( $ids as $id ) {
            if ( 'purge' !== $id ) {
                self::sync( $id );
            }
        }
        Avela::purge();
    }

    public static function rebuild() {
        self::invalidate();
        $ids = array_keys( self::index() );
        // Each owner has an independent atomic meta row. Never publish an empty global snapshot.
        foreach ( $ids as $id ) {
            self::sync( (int) $id );
        }
    }

    private static function block_media( $blocks, &$ids, &$text, &$seen ) {
        foreach ( $blocks as $block ) {
            $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
            if ( in_array( $block['blockName'], array( 'core/image', 'core/video', 'core/audio', 'core/file', 'core/cover', 'core/media-text' ), true ) ) {
                foreach ( array( 'id', 'mediaId' ) as $field ) {
                    if ( ! empty( $attrs[ $field ] ) ) {
                        $ids[] = absint( $attrs[ $field ] );
                    }
                }
            }
            if ( 'core/gallery' === $block['blockName'] && is_array( $attrs['ids'] ?? null ) ) {
                $ids = array_merge( $ids, array_map( 'absint', $attrs['ids'] ?? array() ) );
            }
            if ( 'core/block' === $block['blockName'] && ! empty( $attrs['ref'] ) && ! isset( $seen[ $attrs['ref'] ] ) ) {
                $seen[ $attrs['ref'] ] = true;
                $ref = get_post( $attrs['ref'] );
                if ( $ref ) {
                    $text .= '\n' . $ref->post_content;
                    self::block_media( parse_blocks( $ref->post_content ), $ids, $text, $seen );
                }
            }
            self::block_media( $block['innerBlocks'] ?? array(), $ids, $text, $seen );
        }
    }

    public static function sync( $id ) {
        global $wpdb;
        $was_building = self::$building;
        self::$building = true;
        try {
            for ( $attempt = 0; $attempt < 3; ++$attempt ) {
                clean_post_cache( $id );
                $post = get_post( $id );
                if ( ! $post || ! Avela_Access::restricted( $id ) ) {
                    delete_post_meta( $id, '_avela_media_index' );
                    delete_post_meta( $id, '_avela_media_dirty' );
                    delete_post_meta( $id, '_avela_media_revision' );
                    wp_clear_scheduled_hook( 'avela_rebuild_media', array( $id ) );
                    return;
                }
                update_post_meta( $id, '_avela_media_dirty', 1 );
                $revision = (string) get_post_meta( $id, '_avela_media_revision', true );
                $entry = self::collect( $post );
                $entry['revision'] = $revision;
                $latest = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_avela_media_revision' LIMIT 1", $id ) );
                if ( $latest !== $revision ) {
                    continue;
                }
                update_post_meta( $id, '_avela_media_index', $entry );
                // A later writer is still detected by index(), even if it races this final check.
                $latest = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_avela_media_revision' LIMIT 1", $id ) );
                if ( $latest === $revision && get_post_meta( $id, '_avela_media_index', true ) === $entry ) {
                    delete_post_meta( $id, '_avela_media_dirty' );
                    return;
                }
            }
            if ( ! doing_action( 'avela_rebuild_media' ) && ! wp_next_scheduled( 'avela_rebuild_media', array( $id ) ) ) {
                wp_schedule_single_event( time() + 5, 'avela_rebuild_media', array( $id ) );
            }
        } finally {
            self::$building = $was_building;
            self::invalidate();
        }
    }

    private static function collect( $post ) {
        $id = $post->ID;
        $text = $post->post_content;
        $ids = array( absint( get_post_meta( $id, '_thumbnail_id', true ) ) );
        $seen = array();
        self::block_media( parse_blocks( $text ), $ids, $text, $seen );
        preg_match_all( '/(?:wp-image-|attachment_)(\d+)/', $text, $matches );
        $ids = array_merge( $ids, array_map( 'absint', $matches[1] ), array_keys( get_attached_media( '', $id ) ) );
        preg_match_all( '/\[gallery[^\]]*ids=[\x22\x27]([\d,\s]+)[\x22\x27]/', $text, $galleries );
        foreach ( $galleries[1] as $gallery ) {
            $ids = array_merge( $ids, array_map( 'absint', explode( ',', $gallery ) ) );
        }
        preg_match_all( '~(?:https?:)?//[^\s<>\x22\x27]+\.(?:jpe?g|png|gif|webp|avif|svg|mp4|webm|mp3|pdf)(?:\?[^\s<>\x22\x27]*)?~i', $text, $matches );
        $urls = array_map( 'html_entity_decode', $matches[0] );
        $processor = new WP_HTML_Tag_Processor( $text );
        while ( $processor->next_tag() ) {
            $tag = $processor->get_tag();
            if ( ! in_array( $tag, array( 'IMG', 'VIDEO', 'AUDIO', 'SOURCE', 'IFRAME', 'EMBED', 'OBJECT', 'A' ), true ) ) {
                continue;
            }
            foreach ( array( 'src', 'poster', 'data-src', 'data', 'href' ) as $attr ) {
                $url = $processor->get_attribute( $attr );
                if ( is_string( $url ) && ( 'A' !== $tag || preg_match( '/\.(?:jpe?g|png|gif|webp|avif|svg|mp4|webm|mp3|pdf)(?:[?#]|$)/i', $url ) ) ) {
                    $urls[] = $url;
                }
            }
            foreach ( array( 'srcset', 'data-srcset' ) as $attr ) {
                $srcset = $processor->get_attribute( $attr );
                if ( is_string( $srcset ) ) {
                    foreach ( explode( ',', $srcset ) as $candidate ) {
                        $urls[] = preg_split( '/\s+/', trim( $candidate ) )[0];
                    }
                }
            }
        }
        $urls = array_values( array_filter( array_map( function ( $url ) use ( $id ) {
            return self::normalize_url( $url, get_permalink( $id ) );
        }, $urls ) ) );
        foreach ( $urls as $url ) {
            $bare = strtok( $url, '?' );
            $attachment = attachment_url_to_postid( $bare );
            if ( ! $attachment ) {
                $attachment = attachment_url_to_postid( rawurldecode( preg_replace( '/-\d+x\d+(?=\.[^.]+$)/', '', $bare ) ) );
            }
            if ( $attachment ) {
                $ids[] = $attachment;
            }
        }
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        $uploads = wp_get_upload_dir();
        foreach ( $ids as $attachment ) {
            $actual_url = wp_get_attachment_url( $attachment );
            if ( $actual_url ) {
                $urls[] = $actual_url;
            }
            $srcset = wp_get_attachment_image_srcset( $attachment, 'full' );
            if ( is_string( $srcset ) ) {
                foreach ( explode( ',', $srcset ) as $candidate ) {
                    $urls[] = preg_split( '/\s+/', trim( $candidate ) )[0];
                }
            }
            $file = get_post_meta( $attachment, '_wp_attached_file', true );
            $meta = get_post_meta( $attachment, '_wp_attachment_metadata', true );
            if ( $file ) {
                $urls[] = $uploads['baseurl'] . '/' . $file;
                $dir = dirname( $file );
                $dir = '.' === $dir ? '' : $dir . '/';
                foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
                    if ( isset( $size['file'] ) ) {
                        $urls[] = $uploads['baseurl'] . '/' . $dir . $size['file'];
                    }
                }
                if ( ! empty( $meta['original_image'] ) ) {
                    $urls[] = $uploads['baseurl'] . '/' . $dir . $meta['original_image'];
                }
            }
        }
        // CDN/optimizer adapters can enumerate transformed URLs without fetching any files.
        $urls = apply_filters( 'avela_restricted_media_urls', array_values( array_unique( $urls ) ), $id, $ids );
        return array( 'ids' => $ids, 'urls' => array_values( array_unique( array_filter( $urls, 'is_string' ) ) ),
            'refs' => array_map( 'intval', array_keys( $seen ) ), 'ready' => true );
    }

    public static function normalize_url( $url, $base ) {
        if ( ! is_string( $url ) || '' === trim( $url ) ) {
            return '';
        }
        $url = WP_Http::make_absolute_url( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base );
        $parts = wp_parse_url( $url );
        return $parts && ! empty( $parts['host'] ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
            ? preg_replace( '/#.*$/', '', $url ) : '';
    }

    public static function unresolved() {
        foreach ( self::index() as $id => $entry ) {
            if ( empty( $entry['ready'] ) && ! Avela_Access::editing( $id ) && ! Avela_Access::allowed( $id ) ) {
                return true;
            }
        }
        return false;
    }

    public static function owners( $attachment ) {
        $owners = array();
        foreach ( self::index() as $id => $entry ) {
            if ( in_array( (int) $attachment, $entry['ids'], true ) ) {
                $owners[] = (int) $id;
            }
        }
        $parent = (int) wp_get_post_parent_id( $attachment );
        if ( $parent && Avela_Access::restricted( $parent ) ) {
            $owners[] = $parent;
        }
        return array_unique( $owners );
    }

    public static function hidden( $attachment ) {
        if ( self::unresolved() ) {
            return true;
        }
        foreach ( self::owners( $attachment ) as $id ) {
            if ( ! Avela_Access::editing( $id ) && ! Avela_Access::allowed( $id ) ) {
                return true;
            }
        }
        return false;
    }

    public static function blocked_ids() {
        $ids = array();
        foreach ( self::index() as $owner => $entry ) {
            if ( ! Avela_Access::editing( $owner ) && ! Avela_Access::allowed( $owner ) ) {
                $ids = array_merge( $ids, $entry['ids'] );
            }
        }
        return array_values( array_unique( $ids ) );
    }

    public static function restricted_url( $url, $public_metadata = false ) {
        $url = self::normalize_url( $url, home_url( '/' ) );
        if ( ! $url ) {
            return false;
        }
        $target = wp_parse_url( $url );
        foreach ( self::index() as $owner => $entry ) {
            if ( ! $public_metadata && ( Avela_Access::editing( $owner ) || Avela_Access::allowed( $owner ) ) ) {
                continue;
            }
            if ( empty( $entry['ready'] ) ) {
                return true;
            }
            foreach ( $entry['urls'] as $source_url ) {
                $source = wp_parse_url( $source_url );
                if ( empty( $source['path'] ) || empty( $source['host'] ) || strtolower( $source['host'] ) !== strtolower( $target['host'] ) ) {
                    continue;
                }
                $stem = preg_replace( '/(?:-\d+x\d+|-scaled|-rotated)?\.[^.\/]+$/', '', rawurldecode( $source['path'] ) );
                if ( 0 === strpos( rawurldecode( $target['path'] ?? '' ), $stem ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function public_uses( $attachment, $exclude ) {
        global $wpdb;
        $file = get_post_meta( $attachment, '_wp_attached_file', true );
        $path = is_string( $file ) && '' !== $file ? '%' . $wpdb->esc_like( $file ) . '%' : '';
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} t ON t.post_id=p.ID AND t.meta_key='_thumbnail_id' AND t.meta_value=%s WHERE p.ID<>%d AND p.post_status='publish' AND p.post_type IN ('post','page') AND (t.meta_id IS NOT NULL OR p.post_content LIKE %s OR p.post_content LIKE %s) AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} g WHERE g.post_id=p.ID AND g.meta_key='_avela_warning' AND g.meta_value<>'' AND g.meta_value<>'0') LIMIT 5",
            (string) $attachment, $exclude, $path, '%wp-image-' . absint( $attachment ) . '%'
        ) ) );
    }

    public static function rules() {
        if ( ! Avela::settings()['images'] ) {
            return array();
        }
        $hosts = array();
        foreach ( self::index() as $entry ) {
            if ( empty( $entry['ready'] ) ) {
                $uploads = wp_parse_url( wp_get_upload_dir()['baseurl'] );
                if ( ! empty( $uploads['host'] ) && ! empty( $uploads['path'] ) ) {
                    $hosts[ strtolower( $uploads['host'] ) ][] = 'Disallow: ' . trailingslashit( $uploads['path'] );
                }
            }
            foreach ( $entry['urls'] as $url ) {
                $parts = wp_parse_url( $url );
                if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
                    continue;
                }
                $path = str_replace( array( "\r", "\n", '*', '$' ), array( '', '', '%2A', '%24' ), $parts['path'] );
                // Cover the original, resized and optimizer-generated WebP/AVIF siblings.
                $stem = preg_replace( '/(?:-\d+x\d+|-scaled|-rotated)?\.[^.\/]+$/', '', $path );
                $hosts[ strtolower( $parts['host'] ) ][] = 'Disallow: ' . $stem . '*';
            }
        }
        foreach ( $hosts as &$rules ) {
            $rules = array_values( array_unique( $rules ) );
            sort( $rules );
        }
        return $hosts;
    }

    public static function robots( $output, $public ) {
        $host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        $rules = self::rules()[ $host ] ?? array();
        if ( ! $rules ) {
            return $output;
        }
        // Insert into EVERY existing group: a more specific crawler group overrides '*'.
        $lines = preg_split( '/\r?\n/', $output );
        $result = array();
        $in_agents = false;
        $found = false;
        foreach ( $lines as $line ) {
            if ( preg_match( '/^\s*User-agent\s*:/i', $line ) ) {
                if ( ! $in_agents ) {
                    $in_agents = true;
                    $found = true;
                }
            } elseif ( $in_agents ) {
                $result = array_merge( $result, $rules );
                $in_agents = false;
            }
            $result[] = $line;
        }
        if ( $in_agents ) {
            $result = array_merge( $result, $rules );
        }
        if ( ! $found ) {
            $result = array_merge( $result, array( '', 'User-agent: *' ), $rules );
        }
        return implode( "\n", $result ) . "\n";
    }
}
