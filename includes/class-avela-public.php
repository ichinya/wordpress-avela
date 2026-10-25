<?php
defined( 'ABSPATH' ) || exit;

final class Avela_Public {
    public static function hooks() {
        // Also covers archives personalized by a consent cookie. Earlier/CDN caches need deployment configuration.
        if ( ! is_admin() && ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        add_action( 'send_headers', array( __CLASS__, 'no_cache' ) );
        add_filter( 'rest_post_dispatch', array( __CLASS__, 'rest_headers' ), PHP_INT_MAX, 3 );
        add_filter( 'the_posts', array( __CLASS__, 'posts' ), PHP_INT_MAX, 2 );
        add_filter( 'the_content', array( __CLASS__, 'content' ), -999 );
        add_filter( 'the_content', array( __CLASS__, 'content' ), PHP_INT_MAX );
        add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt' ), -999, 2 );
        add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt' ), PHP_INT_MAX, 2 );
        add_filter( 'the_content_feed', array( __CLASS__, 'feed' ), PHP_INT_MAX );
        add_filter( 'the_excerpt_rss', array( __CLASS__, 'feed' ), PHP_INT_MAX );
        add_filter( 'get_post_metadata', array( __CLASS__, 'metadata' ), 10, 4 );
        add_filter( 'post_thumbnail_html', array( __CLASS__, 'thumbnail' ), PHP_INT_MAX, 2 );
        add_filter( 'wp_get_attachment_image', array( __CLASS__, 'image' ), PHP_INT_MAX, 2 );
        add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'image_src' ), PHP_INT_MAX, 2 );
        add_filter( 'pre_get_posts', array( __CLASS__, 'query' ) );
        add_filter( 'posts_search', array( __CLASS__, 'search' ), PHP_INT_MAX, 2 );
        add_filter( 'comments_clauses', array( __CLASS__, 'comments_sql' ), PHP_INT_MAX, 2 );
        add_filter( 'comment_feed_where', array( __CLASS__, 'comment_feed_sql' ) );
        add_filter( 'comments_array', array( __CLASS__, 'comments' ), PHP_INT_MAX, 2 );
        add_filter( 'comments_open', array( __CLASS__, 'comments_open' ), PHP_INT_MAX, 2 );
        add_filter( 'get_comments_number', array( __CLASS__, 'comments_number' ), PHP_INT_MAX, 2 );
        add_filter( 'comment_text', array( __CLASS__, 'comment_text' ), PHP_INT_MAX, 2 );
        add_filter( 'preprocess_comment', array( __CLASS__, 'new_comment' ) );
        add_action( 'template_redirect', array( __CLASS__, 'redirect' ), -100 );
        add_action( 'template_redirect', array( __CLASS__, 'buffer' ), -95 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
        add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_guard' ), 10, 3 );
        add_filter( 'rest_prepare_post', array( __CLASS__, 'rest_post' ), PHP_INT_MAX, 3 );
        add_filter( 'rest_prepare_page', array( __CLASS__, 'rest_post' ), PHP_INT_MAX, 3 );
        add_filter( 'rest_prepare_attachment', array( __CLASS__, 'rest_attachment' ), PHP_INT_MAX, 3 );
        add_filter( 'rest_prepare_comment', array( __CLASS__, 'rest_comment' ), PHP_INT_MAX, 3 );
        add_filter( 'oembed_response_data', array( __CLASS__, 'oembed' ), PHP_INT_MAX, 2 );
        add_filter( 'embed_thumbnail_id', array( __CLASS__, 'embed_thumbnail' ) );
        foreach ( array( 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description', 'rank_math/frontend/description', 'rank_math/opengraph/facebook/description', 'rank_math/opengraph/twitter/description' ) as $hook ) {
            add_filter( $hook, array( __CLASS__, 'description' ), PHP_INT_MAX );
        }
        foreach ( array( 'wpseo_opengraph_image', 'wpseo_twitter_image', 'rank_math/opengraph/facebook/image', 'rank_math/opengraph/twitter/image' ) as $hook ) {
            add_filter( $hook, array( __CLASS__, 'seo_image' ), PHP_INT_MAX );
        }
        add_filter( 'wpseo_schema_graph', array( __CLASS__, 'schema' ), PHP_INT_MAX );
        add_filter( 'rank_math/json_ld', array( __CLASS__, 'schema' ), PHP_INT_MAX );
        add_filter( 'wpseo_sitemap_urlimages', array( __CLASS__, 'sitemap_images' ), PHP_INT_MAX, 2 );
        add_filter( 'rank_math/sitemap/urlimages', array( __CLASS__, 'sitemap_images' ), PHP_INT_MAX, 2 );
    }

    public static function no_cache() {
        if ( ! is_admin() && ! headers_sent() ) {
            nocache_headers();
            header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
            header( 'Vary: Cookie', false );
        }
    }

    public static function rest_headers( $response, $server, $request ) {
        $response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Vary', 'Cookie' );
        return $response;
    }

    public static function locked( $id ) {
        return Avela_Access::restricted( $id ) && ! Avela_Access::editing( $id ) && ! Avela_Access::allowed( $id );
    }

    public static function posts( $posts, $query ) {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return $posts;
        }
        foreach ( $posts as &$post ) {
            if ( self::locked( $post->ID ) ) {
                // Clone: never poison the persistent object cache or the editor's raw post.
                $post = clone $post;
                $post->post_content = Avela_Access::teaser( $post->ID );
                $post->post_excerpt = Avela_Access::teaser( $post->ID );
                $post->post_content_filtered = '';
            }
        }
        return $posts;
    }

    public static function form( $id, $action, $label ) {
        return '<form class="avela-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
            . '<input type="hidden" name="action" value="avela_' . esc_attr( $action ) . '">'
            . '<input type="hidden" name="post_id" value="' . absint( $id ) . '">'
            . '<input type="hidden" name="avela_token" value="' . esc_attr( Avela_Access::token( $id, $action ) ) . '">'
            . '<button type="submit" class="avela-button">' . esc_html( $label ) . '</button></form>';
    }

    public static function gate( $id ) {
        $w = Avela_Access::warning( $id );
        return '<section class="avela-gate" aria-labelledby="avela-heading-' . absint( $id ) . '">'
            . '<span class="avela-badge">18+ · Запрещено для детей</span>'
            . '<h2 id="avela-heading-' . absint( $id ) . '">' . esc_html( $w['heading'] ) . '</h2>'
            . '<div class="avela-message">' . wpautop( wp_kses( $w['body'], Avela_Admin::allowed_html() ) ) . '</div>'
            . '<div class="avela-actions">' . self::form( $id, 'confirm', $w['confirm'] )
            . '<a class="avela-exit" rel="nofollow" href="' . esc_url( $w['exit_url'] ) . '">' . esc_html( $w['exit_label'] ) . '</a></div>'
            . '<p class="avela-note">Кнопка фиксирует ваше заявление о возрасте. Для открытия нужны cookie.</p></section>';
    }

    public static function public_html( $id, $gate = true ) {
        $teaser = wpautop( esc_html( Avela_Access::teaser( $id ) ) );
        if ( $gate ) {
            return $teaser . self::gate( $id );
        }
        return $teaser . '<p><span class="avela-badge">18+</span> <a href="' . esc_url( get_permalink( $id ) ) . '">Прочитать материал с предупреждением</a></p>';
    }

    public static function content( $content ) {
        $id = get_the_ID();
        if ( ! $id || ! Avela_Access::restricted( $id ) || Avela_Access::editing( $id ) ) {
            return $content;
        }
        if ( self::locked( $id ) ) {
            return self::public_html( $id, is_singular() && ! is_feed() && ! is_embed() );
        }
        if ( PHP_INT_MAX === avela_current_filter_priority() ) {
            return $content . ( Avela::settings()['reset'] ? self::form( $id, 'reset', 'Скрыть материал' ) : '' );
        }
        return $content;
    }

    public static function excerpt( $excerpt, $post ) {
        if ( $post && Avela_Access::restricted( $post->ID ) && ! Avela_Access::editing( $post->ID ) ) {
            return esc_html( Avela_Access::teaser( $post->ID ) ) . ' · 18+';
        }
        return $excerpt;
    }

    public static function feed( $content ) {
        return Avela_Access::restricted( get_the_ID() ) ? self::public_html( get_the_ID(), false ) : $content;
    }

    public static function metadata( $value, $id, $key, $single ) {
        if ( ! Avela_Media::building() && '_thumbnail_id' === $key && self::locked( $id ) ) {
            return $single ? 0 : array();
        }
        return $value;
    }

    public static function thumbnail( $html, $id ) {
        return self::locked( $id ) ? '' : $html;
    }

    public static function image( $html, $id ) {
        return Avela_Media::hidden( $id ) ? '' : $html;
    }

    public static function image_src( $src, $id ) {
        return Avela_Media::hidden( $id ) ? false : $src;
    }

    public static function query( $query ) {
        if ( Avela_Media::building() || is_admin() ) {
            return;
        }
        $blocked = Avela_Media::blocked_ids();
        if ( $blocked ) {
            $query->set( 'post__not_in', array_unique( array_merge( (array) $query->get( 'post__not_in' ), $blocked ) ) );
        }
    }

    public static function hidden_post_ids() {
        $ids = array();
        foreach ( array_keys( Avela_Media::index() ) as $id ) {
            if ( self::locked( $id ) ) {
                $ids[] = absint( $id );
            }
        }
        return $ids;
    }

    public static function search( $search, $query ) {
        global $wpdb;
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) ) || empty( $query->query_vars['search_terms'] ) ) {
            return $search;
        }
        $gated = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} avela_gate WHERE avela_gate.post_id = {$wpdb->posts}.ID AND avela_gate.meta_key = '_avela_warning' AND avela_gate.meta_value <> '' AND avela_gate.meta_value <> '0')";
        $parts = array();
        $wildcard = $query->get( 'exact' ) ? '' : '%';
        foreach ( $query->query_vars['search_terms'] as $term ) {
            $exclude = '-' === substr( $term, 0, 1 );
            $term = $exclude ? substr( $term, 1 ) : $term;
            $like = $wildcard . $wpdb->esc_like( $term ) . $wildcard;
            $op = $exclude ? 'NOT LIKE' : 'LIKE';
            $join = $exclude ? ' AND ' : ' OR ';
            $title = $wpdb->prepare( "{$wpdb->posts}.post_title $op %s", $like );
            $raw = $wpdb->prepare( "{$wpdb->posts}.post_excerpt $op %s $join {$wpdb->posts}.post_content $op %s", $like, $like );
            $teaser = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} avela_teaser WHERE avela_teaser.post_id = {$wpdb->posts}.ID AND avela_teaser.meta_key = '_avela_teaser' AND avela_teaser.meta_value LIKE %s)", $like );
            if ( $exclude ) {
                $teaser = 'NOT ' . $teaser;
            }
            $parts[] = "(($gated AND ($title $join $teaser)) OR (NOT $gated AND ($title $join ($raw))))";
        }
        $search = ' AND (' . implode( ' AND ', $parts ) . ')';
        if ( ! is_user_logged_in() ) {
            $search .= " AND ({$wpdb->posts}.post_password = '')";
        }
        return $search;
    }

    public static function comments_sql( $clauses, $query ) {
        global $wpdb;
        $ids = self::hidden_post_ids();
        if ( $ids ) {
            $clauses['where'] .= ' AND ' . $wpdb->comments . '.comment_post_ID NOT IN (' . implode( ',', $ids ) . ')';
        }
        return $clauses;
    }

    public static function comment_feed_sql( $where ) {
        global $wpdb;
        $ids = array_map( 'absint', array_keys( Avela_Media::index() ) );
        return $ids ? $where . ' AND ' . $wpdb->comments . '.comment_post_ID NOT IN (' . implode( ',', $ids ) . ')' : $where;
    }

    public static function comments( $comments, $id ) {
        return self::locked( $id ) ? array() : $comments;
    }

    public static function comments_open( $open, $id ) {
        return self::locked( $id ) ? false : $open;
    }

    public static function comments_number( $number, $id ) {
        return self::locked( $id ) ? 0 : $number;
    }

    public static function comment_text( $text, $comment ) {
        return $comment && self::locked( $comment->comment_post_ID ) ? '' : $text;
    }

    public static function new_comment( $data ) {
        if ( self::locked( absint( $data['comment_post_ID'] ?? 0 ) ) ) {
            wp_die( 'Сначала подтвердите предупреждение в статье.', 'Avela', array( 'response' => 403 ) );
        }
        return $data;
    }

    public static function redirect() {
        self::no_cache();
        if ( is_attachment() && Avela_Media::hidden( get_queried_object_id() ) ) {
            status_header( 404 );
            nocache_headers();
            wp_die( 'Материал недоступен без подтверждения.', 'Avela', array( 'response' => 404 ) );
        }
    }

    public static function assets() {
        wp_enqueue_style( 'avela', plugins_url( 'assets/avela.css', AVELA_DIR . 'avela.php' ), array(), AVELA_VERSION );
    }

    public static function buffer() {
        if ( ! is_feed() && ! is_robots() && ! is_trackback() ) {
            ob_start( array( __CLASS__, 'strip_media_html' ) );
        }
    }

    public static function strip_media_html( $html ) {
        // Themes can use raw first-image fallbacks instead of WordPress thumbnail APIs.
        // Remove known closed media from those tags too, before the response is sent.
        $stems = array();
        foreach ( Avela_Media::index() as $id => $entry ) {
            if ( ! self::locked( $id ) ) {
                continue;
            }
            foreach ( $entry['urls'] as $url ) {
                $path = wp_parse_url( $url, PHP_URL_PATH );
                if ( $path ) {
                    $stems[] = preg_replace( '/(?:-\d+x\d+|-scaled|-rotated)?\.[^.\/]+$/', '', $path );
                }
            }
        }
        if ( ! $stems ) {
            return $html;
        }
        $stems = array_unique( $stems );
        return preg_replace_callback( '~<(?:img|source|link)\b(?:"[^"]*"|\x27[^\x27]*\x27|[^\x27">])*>~i', function ( $match ) use ( $stems ) {
            $tag = html_entity_decode( $match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            foreach ( $stems as $stem ) {
                if ( false !== strpos( $tag, $stem ) ) {
                    return '';
                }
            }
            return $match[0];
        }, $html );
    }

    public static function head() {
        $id = get_queried_object_id();
        if ( ! is_singular() || ! Avela_Access::restricted( $id ) ) {
            return;
        }
        if ( ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) ) {
            echo '<meta name="description" content="' . esc_attr( Avela_Access::teaser( $id ) ) . '">' . "\n";
        }
        if ( get_post_meta( $id, '_avela_adult', true ) ) {
            echo '<meta name="rating" content="adult">' . "\n";
        }
    }

    public static function robots( $robots ) {
        if ( is_singular() && Avela_Access::restricted( get_queried_object_id() ) && Avela::settings()['images'] ) {
            $robots['noimageindex'] = true;
            $robots['max-image-preview'] = 'none';
        }
        return $robots;
    }

    public static function rest_guard( $result, $server, $request ) {
        $route = $request->get_route();
        if ( preg_match( '~^/wp/v2/media/(\d+)(?:/|$)~', $route, $m ) && Avela_Media::hidden( (int) $m[1] ) ) {
            return new WP_Error( 'avela_media_locked', 'Медиа недоступно без подтверждения.', array( 'status' => 403 ) );
        }
        if ( preg_match( '~^/wp/v2/comments/(\d+)(?:/|$)~', $route, $m ) ) {
            $comment = get_comment( (int) $m[1] );
            if ( $comment && self::locked( $comment->comment_post_ID ) ) {
                return new WP_Error( 'avela_comment_locked', 'Комментарии недоступны без подтверждения.', array( 'status' => 403 ) );
            }
        }
        return $result;
    }

    public static function rest_post( $response, $post, $request ) {
        if ( ! self::locked( $post->ID ) ) {
            return $response;
        }
        $data = $response->get_data();
        // Whitelist: registered custom REST fields can otherwise expose original body/media.
        $data = array_intersect_key( $data, array_flip( array( 'id', 'date', 'date_gmt', 'modified', 'modified_gmt', 'slug', 'status', 'type', 'link', 'title', 'author', 'categories', 'tags' ) ) );
        $data['content'] = array( 'rendered' => self::public_html( $post->ID, false ), 'protected' => true );
        $data['excerpt'] = array( 'rendered' => wpautop( esc_html( Avela_Access::teaser( $post->ID ) ) ), 'protected' => true );
        $data['featured_media'] = 0;
        $data['comment_status'] = 'closed';
        $data['avela'] = array( 'restricted' => true, 'age' => '18+', 'teaser' => Avela_Access::teaser( $post->ID ) );
        $response->set_data( $data );
        foreach ( array( 'wp:featuredmedia', 'wp:attachment', 'replies', 'version-history', 'predecessor-version' ) as $rel ) {
            $response->remove_link( $rel );
        }
        return $response;
    }

    public static function rest_attachment( $response, $post, $request ) {
        return Avela_Media::hidden( $post->ID ) ? new WP_Error( 'avela_media_locked', 'Медиа недоступно без подтверждения.', array( 'status' => 403 ) ) : $response;
    }

    public static function rest_comment( $response, $comment, $request ) {
        return self::locked( $comment->comment_post_ID ) ? new WP_Error( 'avela_comment_locked', 'Комментарии недоступны без подтверждения.', array( 'status' => 403 ) ) : $response;
    }

    public static function oembed( $data, $post ) {
        if ( Avela_Access::restricted( $post->ID ) ) {
            unset( $data['thumbnail_url'], $data['thumbnail_width'], $data['thumbnail_height'] );
        }
        return $data;
    }

    public static function embed_thumbnail( $id ) {
        return Avela_Access::restricted( get_the_ID() ) ? 0 : $id;
    }

    public static function description( $description ) {
        $id = get_queried_object_id();
        return is_singular() && Avela_Access::restricted( $id ) ? Avela_Access::teaser( $id ) : $description;
    }

    public static function seo_image( $image ) {
        return is_singular() && Avela_Access::restricted( get_queried_object_id() ) ? '' : $image;
    }

    public static function schema( $data ) {
        return is_singular() && Avela_Access::restricted( get_queried_object_id() ) ? array() : $data;
    }

    public static function sitemap_images( $images, $id ) {
        return Avela_Access::restricted( is_object( $id ) ? $id->ID : $id ) ? array() : $images;
    }
}

/** WP_Hook exposes the current priority but there is no core global convenience function. */
function avela_current_filter_priority() {
    global $wp_filter;
    return isset( $wp_filter[ current_filter() ] ) ? $wp_filter[ current_filter() ]->current_priority() : false;
}
