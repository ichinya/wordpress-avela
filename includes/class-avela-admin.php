<?php
defined( 'ABSPATH' ) || exit;

final class Avela_Admin {
    public static function hooks() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
        add_action( 'save_post', array( __CLASS__, 'save' ), 20, 2 );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'settings' ) );
        add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
        add_filter( 'manage_post_posts_columns', array( __CLASS__, 'columns' ) );
        add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
        add_filter( 'plugin_action_links_' . plugin_basename( AVELA_DIR . 'avela.php' ), array( __CLASS__, 'links' ) );
    }

    public static function allowed_html() {
        return array( 'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(),
            'b' => array(), 'i' => array(), 'a' => array( 'href' => true, 'title' => true, 'rel' => true ),
            'ul' => array(), 'ol' => array(), 'li' => array() );
    }

    public static function boxes() {
        foreach ( array( 'post', 'page' ) as $type ) {
            add_meta_box( 'avela-post', 'Avela · Предупреждение перед чтением', array( __CLASS__, 'post_box' ), $type, 'normal', 'high', array( '__block_editor_compatible_meta_box' => true ) );
        }
        add_meta_box( 'avela-template', 'Текст и действия предупреждения', array( __CLASS__, 'template_box' ), 'avela_warning', 'normal', 'high' );
    }

    public static function post_box( $post ) {
        wp_nonce_field( 'avela_save', 'avela_nonce' );
        $selected = get_post_meta( $post->ID, '_avela_warning', true );
        $templates = get_posts( array( 'post_type' => 'avela_warning', 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
        echo '<p><label for="avela-warning"><strong>Предупреждение</strong></label></p><select id="avela-warning" name="avela_warning"><option value="0">Без предупреждения</option>';
        $found = false;
        foreach ( $templates as $template ) {
            $found = $found || (string) $selected === (string) $template->ID;
            echo '<option value="' . absint( $template->ID ) . '" ' . selected( $selected, $template->ID, false ) . '>' . esc_html( $template->post_title ) . '</option>';
        }
        if ( $selected && ! $found ) {
            echo '<option selected value="' . esc_attr( $selected ) . '">Шаблон недоступен — действует стандартное 18+</option>';
        }
        echo '</select><p><label for="avela-teaser"><strong>Публичный анонс</strong></label></p>';
        echo '<textarea class="widefat" rows="5" id="avela-teaser" name="avela_teaser">' . esc_textarea( Avela_Access::teaser( $post->ID ) ) . '</textarea>';
        echo '<p class="description">Обычный текст, который можно показывать всем. Он заменяет основной текст и автоматическую выдержку в публичных представлениях. Заголовок статьи остаётся открытым.</p>';
        if ( $selected && ! Avela_Access::teaser( $post->ID ) ) {
            echo '<p style="color:#b32d2e"><strong>Анонс не заполнен.</strong> До подтверждения будут видны только заголовок и предупреждение.</p>';
        }
        echo '<p><label><input type="checkbox" name="avela_adult" value="1" ' . checked( get_post_meta( $post->ID, '_avela_adult', true ), 1, false ) . '> Метка adult для сексуально откровенного содержимого</label></p>';
        echo '<p class="description">Отдельная поисковая метка. Для грубой лексики и обычного предупреждения 18+ оставьте выключенной.</p>';
        if ( $selected ) {
            foreach ( array( 'before' => 'До подтверждения', 'after' => 'После подтверждения' ) as $mode => $label ) {
                $url = add_query_arg( array( 'avela_preview' => $mode, '_avela_preview_nonce' => wp_create_nonce( 'avela_preview_' . $post->ID ) ), get_preview_post_link( $post ) );
                echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
            }
            echo '<p class="description">Предпросмотр использует сохранённые настройки. Сначала обновите статью.</p>';
            $entry = Avela_Media::index()[ $post->ID ] ?? array( 'ids' => array() );
            echo '<p>В реестре ограниченных медиа: <strong>' . count( $entry['ids'] ) . '</strong>. Ограничения URL действуют во всех местах использования файла, включая открытые статьи.</p>';
            foreach ( $entry['ids'] as $id ) {
                if ( count( Avela_Media::owners( $id ) ) > 1 ) {
                    echo '<p style="color:#996800">Медиа №' . absint( $id ) . ' связано с несколькими закрытыми статьями. Снятие одного предупреждения не снимет остальные ограничения.</p>';
                }
            }
        }
    }

    public static function template_box( $post ) {
        wp_nonce_field( 'avela_save', 'avela_nonce' );
        echo '<p>Название сверху — внутреннее название шаблона. Основной редактор — пояснение читателю; поддерживаются абзацы, выделение и ссылки. Возрастная отметка: <strong>18+</strong>.</p>';
        $fields = array( 'heading' => array( 'Заголовок предупреждения', 'Материал для совершеннолетних' ),
            'confirm' => array( 'Кнопка подтверждения', 'Мне исполнилось 18 лет — читать' ),
            'exit_label' => array( 'Ссылка выхода', 'Вернуться на главную' ),
            'exit_url' => array( 'Адрес выхода на этом сайте', home_url( '/' ) ) );
        foreach ( $fields as $key => $field ) {
            $value = get_post_meta( $post->ID, '_avela_' . $key, true ) ?: $field[1];
            echo '<p><label for="avela-' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label><br>';
            echo '<input class="widefat" type="' . ( 'exit_url' === $key ? 'url' : 'text' ) . '" id="avela-' . esc_attr( $key ) . '" name="avela_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '"></p>';
        }
        echo '<p>Версия: <code>' . esc_html( get_post_meta( $post->ID, '_avela_revision', true ) ?: 'начальная' ) . '</code>. При сохранении версия обновляется. Удаление шаблона сохраняет ограничение статей: используется стандартное предупреждение.</p>';
    }

    public static function save( $id, $post ) {
        if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || ! isset( $_POST['avela_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['avela_nonce'] ) ), 'avela_save' )
            || ! current_user_can( 'edit_post', $id ) ) {
            return;
        }
        if ( 'avela_warning' === $post->post_type && current_user_can( 'manage_options' ) ) {
            foreach ( array( 'heading', 'confirm', 'exit_label', 'exit_url' ) as $field ) {
                $value = sanitize_text_field( wp_unslash( $_POST[ 'avela_' . $field ] ?? '' ) );
                update_post_meta( $id, '_avela_' . $field, 'exit_url' === $field ? Avela_Access::safe_exit( $value ) : $value );
            }
            update_post_meta( $id, '_avela_revision', wp_generate_uuid4() );
            Avela::purge();
        } elseif ( in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
            update_post_meta( $id, '_avela_warning', absint( $_POST['avela_warning'] ?? 0 ) );
            update_post_meta( $id, '_avela_teaser', sanitize_textarea_field( wp_unslash( $_POST['avela_teaser'] ?? '' ) ) );
            update_post_meta( $id, '_avela_adult', empty( $_POST['avela_adult'] ) ? 0 : 1 );
        }
    }

    public static function menu() {
        add_submenu_page( 'edit.php?post_type=avela_warning', 'Настройки Avela', 'Настройки и диагностика', 'manage_options', 'avela', array( __CLASS__, 'page' ) );
    }

    public static function settings() {
        register_setting( 'avela', 'avela_settings', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) ) );
    }

    public static function sanitize_settings( $input ) {
        $input = is_array( $input ) ? $input : array();
        update_option( 'avela_policy_epoch', wp_generate_uuid4(), false );
        Avela::purge();
        return array( 'scope' => 'warning' === ( $input['scope'] ?? '' ) ? 'warning' : 'post',
            'hours' => min( 720, absint( $input['hours'] ?? 0 ) ), 'repeat' => empty( $input['repeat'] ) ? 0 : 1,
            'reset' => empty( $input['reset'] ) ? 0 : 1, 'images' => empty( $input['images'] ) ? 0 : 1 );
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s = Avela::settings();
        echo '<div class="wrap"><h1>Avela · Настройки предупреждений</h1><form method="post" action="options.php">';
        settings_fields( 'avela' );
        echo '<table class="form-table"><tr><th>Область подтверждения</th><td><select name="avela_settings[scope]">';
        foreach ( array( 'post' => 'Только эта статья', 'warning' => 'Все статьи с тем же предупреждением' ) as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $s['scope'], $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr><tr><th>Срок, часы</th><td><input type="number" min="0" max="720" name="avela_settings[hours]" value="' . absint( $s['hours'] ) . '"><p class="description">0 — cookie текущего сеанса с предельным сроком 12 часов; 1–720 — заданный срок. До 12 последних подтверждений.</p></td></tr>';
        foreach ( array( 'repeat' => 'Повторять после изменения предупреждения', 'reset' => 'Показывать кнопку «Скрыть материал»', 'images' => 'Исключать ограниченные изображения из поиска' ) as $key => $label ) {
            echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="checkbox" name="avela_settings[' . esc_attr( $key ) . ']" value="1" ' . checked( $s[ $key ], 1, false ) . '></td></tr>';
        }
        echo '</table><p>Сохранение общих настроек сбрасывает предыдущие подтверждения. Нажатие кнопки — заявление о возрасте, способ <code>self_declared</code>. VK и внешние проверки пока не подключены.</p>';
        submit_button();
        echo '</form><hr><h2>Кеш и поисковые роботы</h2><p>Публичные ответы имеют <code>private, no-store</code> и <code>DONOTCACHEPAGE</code>. Общий HTML-кеш и CDN необходимо отключить или настроить до выпуска. Кеш, отвечающий до загрузки WordPress, не контролируется этим плагином. Очистите уже созданные копии при включении предупреждений.</p>';
        echo '<p>Индексируются заголовок и ручной анонс. Прямые файлы по известному URL доступны. Правила robots ограничивают обход, а не доступ человека.</p>';
        echo '<p>Статей с предупреждением: <strong>' . count( Avela_Media::index() ) . '</strong>. <a href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank" rel="noopener">Проверить robots.txt</a></p>';
        foreach ( Avela_Media::rules() as $host => $rules ) {
            echo '<h3>' . esc_html( $host ) . '</h3><p>' . ( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host ? 'Добавляется в виртуальный robots.txt WordPress.' : 'Внешний хост: перенесите эти правила в его robots.txt или настройте X-Robots-Tag на сервере файлов.' ) . '</p>';
            echo '<textarea class="large-text code" rows="6" readonly>' . esc_textarea( "User-agent: *\n" . implode( "\n", $rules ) ) . '</textarea>';
        }
        echo '<p>Правила по началу имени охватывают размеры и WebP/AVIF-копии; они также могут затронуть другие файлы с тем же началом имени. CDN с другими именами требует адаптера <code>avela_restricted_media_urls</code>.</p></div>';
    }

    public static function notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ( defined( 'WP_CACHE' ) && WP_CACHE ) || file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
            echo '<div class="notice notice-error"><p><strong>Avela:</strong> обнаружен ранний кеш WordPress. Перед использованием предупреждений отключите общий HTML-кеш и очистите старые копии. Поздние фильтры плагина не могут исправить ответ, отданный до его загрузки.</p></div>';
        }
        if ( file_exists( ABSPATH . 'robots.txt' ) ) {
            echo '<div class="notice notice-warning"><p><strong>Avela:</strong> физический robots.txt перекрывает правила WordPress. Перенесите правила из настроек Avela в этот файл.</p></div>';
        }
    }

    public static function columns( $columns ) {
        $columns['avela'] = 'Предупреждение';
        return $columns;
    }

    public static function column( $column, $id ) {
        if ( 'avela' === $column ) {
            echo Avela_Access::restricted( $id ) ? '18+ · ' . esc_html( get_the_title( absint( get_post_meta( $id, '_avela_warning', true ) ) ) ?: 'Стандартное предупреждение' ) : '—';
        }
    }

    public static function links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=avela_warning&page=avela' ) ) . '">Настройки</a>' );
        return $links;
    }
}
