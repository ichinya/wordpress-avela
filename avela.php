<?php
/**
 * Plugin Name: Avela — предупреждения перед чтением
 * Description: Публичные анонсы и серверное подтверждение предупреждений перед открытием статей.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Ichi-nya
 * License: GPL-2.0-or-later
 * Text Domain: avela
 */
defined( 'ABSPATH' ) || exit;
define( 'AVELA_VERSION', '0.1.0' );
define( 'AVELA_DIR', plugin_dir_path( __FILE__ ) );
foreach ( array( 'access', 'media', 'public', 'admin' ) as $avela_component ) {
    require_once AVELA_DIR . 'includes/class-avela-' . $avela_component . '.php';
}
unset( $avela_component );

final class Avela {
    public static function settings() {
        return wp_parse_args( get_option( 'avela_settings', array() ), array(
            'scope' => 'post', 'hours' => 0, 'repeat' => 1, 'reset' => 1, 'images' => 1,
        ) );
    }

    public static function register() {
        register_post_type( 'avela_warning', array(
            'labels' => array( 'name' => 'Предупреждения', 'singular_name' => 'Предупреждение',
                'add_new_item' => 'Добавить предупреждение', 'edit_item' => 'Изменить предупреждение' ),
            'public' => false, 'show_ui' => true, 'show_in_rest' => false,
            'menu_icon' => 'dashicons-shield', 'supports' => array( 'title', 'editor' ),
            'capability_type' => 'post', 'capabilities' => array( 'create_posts' => 'manage_options',
                'edit_posts' => 'manage_options', 'edit_others_posts' => 'manage_options',
                'publish_posts' => 'manage_options', 'read_private_posts' => 'manage_options',
                'delete_posts' => 'manage_options', 'delete_others_posts' => 'manage_options',
                'delete_published_posts' => 'manage_options', 'edit_published_posts' => 'manage_options' ),
            'map_meta_cap' => true,
        ) );
    }

    public static function activate() {
        self::register();
        if ( ! get_option( 'avela_installed' ) ) {
            $defaults = array(
                array( 'Стандартное 18+', 'Материал для совершеннолетних', 'Далее размещён материал, предназначенный для совершеннолетних. Подтвердите, что вам исполнилось 18 лет.' ),
                array( 'Ненормативная лексика', 'В тексте встречается грубая лексика', 'Материал содержит ненормативную лексику и предназначен для совершеннолетних читателей.' ),
                array( 'Чувствительные материалы', 'Чувствительный материал', 'Текст и изображения могут вызвать сильные переживания. Продолжайте, только если готовы к такому содержимому и вам исполнилось 18 лет.' ),
            );
            foreach ( $defaults as $row ) {
                $id = wp_insert_post( array( 'post_type' => 'avela_warning', 'post_status' => 'publish',
                    'post_title' => $row[0], 'post_content' => $row[2] ) );
                update_post_meta( $id, '_avela_heading', $row[1] );
            }
            update_option( 'avela_installed', 1, false );
        }
        Avela_Media::rebuild();
        self::purge();
    }

    public static function purge() {
        // Purge shared HTML caches when a previously public post becomes restricted.
        foreach ( array( 'wp_cache_clear_cache', 'w3tc_flush_all', 'rocket_clean_domain' ) as $fn ) {
            if ( function_exists( $fn ) ) {
                call_user_func( $fn );
            }
        }
        do_action( 'litespeed_purge_all' );
        do_action( 'avela_purge_cache' );
    }
}
add_action( 'init', array( 'Avela', 'register' ) );
register_activation_hook( __FILE__, array( 'Avela', 'activate' ) );
Avela_Access::hooks();
Avela_Media::hooks();
Avela_Public::hooks();
Avela_Admin::hooks();
