<?php
defined( 'ABSPATH' ) || exit;

final class Avela_Media {
    private static $pending = array();
    private static $building = false;

    public static function hooks() {
        add_action( 'save_post', array( __CLASS__, 'changed' ), 100, 1 );
        foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'meta_changed' ), 100, 4 );
        }
        add_action( 'deleted_post', array( __CLASS__, 'changed' ) );
        add_action( 'shutdown', array( __CLASS__, 'flush' ) );
        add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 100, 2 );
    }

    public static function index() {
        return (array) get_option( 'avela_media_index', array() );
    }

    public static function building() {
        return self::$building;
    }

    public static function changed( $id ) {
        if ( self::$building || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) {
            return;
        }
        $type = get_post_type( $id );
        if ( in_array( $type, array( 'attachment', 'wp_block' ), true ) ) {
            self::$pending['all'] = true;
        } else {
            self::$pending[ absint( $id ) ] = true;
        }
    }

    public static function meta_changed( $meta_id, $id, $key, $value ) {
        if ( in_array( $key, array( '_avela_warning', '_avela_teaser', '_avela_adult', '_thumbnail_id', '_wp_attachment_metadata', '_wp_attached_file' ), true ) ) {
            self::changed( $id );
        }
    }

    public static function flush() {
        if ( ! self::$pending ) {
            return;
        }
        $all = isset( self::$pending['all'] );
        $ids = array_keys( self::$pending );
        self::$pending = array();
        if ( $all ) {
            self::rebuild();
        } else {
            foreach ( $ids as $id ) {
                self::sync( $id );
            }
        }
        Avela::purge();
    }

    public static function rebuild() {
        global $wpdb;
        // Event-driven registry, including non-public posts: file restrictions remain conservative.
        $ids = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_avela_warning' AND meta_value <> '' AND meta_value <> '0'" );
        update_option( 'avela_media_index', array(), false );
        foreach ( $ids as $id ) {
            self::sync( (int) $id );
        }
    }

    private static function block_media( $blocks, &$ids, &$text, &$seen ) {
        foreach ( $blocks as $block ) {
            $attrs = $block['attrs'] ?? array();
            if ( in_array( $block['blockName'], array( 'core/image', 'core/video', 'core/audio', 'core/file', 'core/cover', 'core/media-text' ), true ) ) {
                foreach ( array( 'id', 'mediaId' ) as $field ) {
                    if ( ! empty( $attrs[ $field ] ) ) {
                        $ids[] = absint( $attrs[ $field ] );
                    }
                }
            }
            if ( 'core/gallery' === $block['blockName'] ) {
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
        $index = self::index();
        $post = get_post( $id );
        unset( $index[ $id ] );
        if ( ! $post || ! Avela_Access::restricted( $id ) ) {
            update_option( 'avela_media_index', $index, false );
            return;
        }
        self::$building = true;
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
        foreach ( $urls as $url ) {
            $attachment = attachment_url_to_postid( $url );
            if ( ! $attachment ) {
                $attachment = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.[^.]+$)/', '', strtok( $url, '?' ) ) );
            }
            if ( $attachment ) {
                $ids[] = $attachment;
            }
        }
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        $uploads = wp_get_upload_dir();
        foreach ( $ids as $attachment ) {
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
        $index[ $id ] = array( 'ids' => $ids, 'urls' => array_values( array_filter( $urls, 'is_string' ) ) );
        update_option( 'avela_media_index', $index, false );
        self::$building = false;
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
        foreach ( self::owners( $attachment ) as $id ) {
            if ( ! Avela_Access::editing( $id ) && ! Avela_Access::allowed( $id ) ) {
                return true;
            }
        }
        return false;
    }

    public static function blocked_ids() {
        $ids = array();
        foreach ( self::index() as $entry ) {
            foreach ( $entry['ids'] as $id ) {
                if ( self::hidden( $id ) ) {
                    $ids[] = $id;
                }
            }
        }
        return array_values( array_unique( $ids ) );
    }

    public static function rules() {
        if ( ! Avela::settings()['images'] ) {
            return array();
        }
        $hosts = array();
        foreach ( self::index() as $entry ) {
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
