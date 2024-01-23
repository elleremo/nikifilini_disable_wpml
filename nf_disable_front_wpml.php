<?php

/*
Plugin Name: Disable WPML
Description: Отключает WPML и пытается ничего сломать
Version: 1.0.0
Author: elleremo
*/

if (!defined('ABSPATH')) {
    exit;
}

// Проверяем, является ли запрос одним из следующих типов
if (
    wp_doing_ajax() ||
    isset($_GET['doing_wp_cron']) ||
    defined('REST_REQUEST') ||
    is_admin() ||
    wp_doing_cron() ||
    php_sapi_name() === 'cli' ||
    (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
    (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ||
    (isset($_POST['action']) && $_POST['action'] === 'heartbeat') ||
    strpos($_SERVER['REQUEST_URI'], '/wp-admin/async-upload.php') !== false
) {
    return;
}

// return;
add_filter('wpml_current_language', 'default_to_russian_language_wpml', 1000, 2);

function default_to_russian_language_wpml($language) {
    // Если WPML отключен, устанавливаем русский язык по умолчанию
    if (is_admin()) {
        return $language;
    }

    $language = 'ru';

    return $language;
}

add_filter('option_active_plugins', 'disable_specific_plugin');
function disable_specific_plugin($plugins) {

    if (is_admin()) {
        return $plugins;
    }

    $plugin_list = [
        // 'nikifilini-head-banner/nikifilini-head-banner.php',
        'sitepress-multilingual-cms/sitepress.php',
        'woocommerce-multilingual/wpml-woocommerce.php',
        'wp-seo-multilingual/plugin.php',
        'wpml-string-translation/plugin.php',

        // 'woocommerce-multilingual/wpml-woocommerce.php'
    ];

    foreach ( $plugin_list as $plugin ) {

        $key = array_search($plugin, $plugins);
        if (false !== $key)
            unset($plugins[$key]);

    }

    return $plugins;
}

add_filter('wpseo_canonical', 'remove_lang_parameter_from_canonical');

function remove_lang_parameter_from_canonical($canonical) {
    // Удаляем параметр ?lang=en
    $canonical = remove_query_arg('lang', $canonical);

    return $canonical;
}
// ============ PRODUCTS ======================

add_action('pre_get_posts', 'filter_posts_to_russian_language', 100, 1);

function filter_posts_to_russian_language($query) {

    // Убедимся, что мы находимся на фронтенде и в основном запросе
    if (!is_admin() && $query->is_main_query()) {
        // Проверяем, является ли запрос архивом продуктов, страницей продукта, категорией или меткой продукта
        if ($query->is_post_type_archive('product') || $query->is_tax('product_cat') || $query->is_tax('product_tag') || ($query->is_singular() && $query->get('post_type') === 'product')) {
            // Добавляем фильтр к запросу
            add_filter('posts_join', 'join_wpml_translations_table');
            add_filter('posts_where', 'filter_to_russian_language');
        }
    }
}

function join_wpml_translations_table($join) {
    global $wpdb;
    $join .= " LEFT JOIN {$wpdb->prefix}icl_translations AS t ON {$wpdb->posts}.ID = t.element_id";
    return $join;
}

function filter_to_russian_language($where) {
    global $wpdb;
    $where .= " AND t.element_type = 'post_product' AND t.language_code = 'ru'";
    return $where;
}



// ONLY PAGES =================================================

add_action('pre_get_posts', 'filter_pages_to_russian_language', 1000, 1);

function filter_pages_to_russian_language($query) {
    global $wpdb, $wp_query;

    if (!is_admin() && $query->is_main_query() && is_page() && !is_post_type_archive()) {


        // Получаем ID текущей страницы
        $current_page_id = $wp_query->get_queried_object_id();

        // Получаем ID русской версии страницы
        $russian_page_id = $wpdb->get_var($wpdb->prepare("
            SELECT t_rus.element_id
            FROM {$wpdb->prefix}icl_translations AS t_orig
            JOIN {$wpdb->prefix}icl_translations AS t_rus ON t_orig.trid = t_rus.trid
            WHERE t_orig.element_id = %d
            AND t_rus.language_code = 'ru'
            AND t_orig.element_type = 'post_page'
        ", $current_page_id));

        // Если найдена русская версия и это не текущая страница
        if ($russian_page_id && $russian_page_id != $current_page_id) {

            // Изменяем запрос, чтобы он загружал русскую версию страницы
            $query->set('page_id', $russian_page_id);
        }
    }
}


// CHANGE LANG TEXT
add_filter('locale', 'set_russian_locale', 1, 10000);
function set_russian_locale($locale) {
    if (!is_admin()) {
        return 'ru_RU';
    }
    return $locale;
}


//AWS
// add_filter('aws_search_page_results', function($new_posts, $query, $data) {
//     global $wpdb;

//     // Создаем новый массив для отфильтрованных постов
//     $filtered_posts = [];

//     // Получаем ID всех постов
//     $post_ids = wp_list_pluck($new_posts, 'ID');

//     if (!empty($post_ids)) {
//         // Создаем строку с перечислением ID для SQL-запроса
//         $post_ids_string = implode(',', $post_ids);

//         // Выполняем SQL-запрос для получения ID постов на русском языке
//         $russian_post_ids = $wpdb->get_col("
//             SELECT t.element_id
//             FROM {$wpdb->prefix}icl_translations AS t
//             WHERE t.element_id IN ($post_ids_string)
//             AND t.element_type = 'post_product'
//             AND t.language_code = 'ru'
//         ");

//         // Фильтруем посты, оставляя только те, которые на русском языке
//         foreach ($new_posts as $post) {
//             if (in_array($post->ID, $russian_post_ids)) {
//                 $filtered_posts[] = $post;
//             }
//         }
//     }

//     // Возвращаем отфильтрованные посты
//     return $filtered_posts;
// }, 100, 3);



add_filter('aws_search_results_products', function ($new_posts, $s) {
    global $wpdb;

    // Если массив пуст, возвращаем его как есть
    if (empty($new_posts)) {
        return $new_posts;
    }

    // Получаем ID всех продуктов из массива
    $product_ids = array_column($new_posts, 'parent_id');

    // Создаем строку с перечислением ID для SQL-запроса
    $product_ids_string = implode(',', $product_ids);

    // Выполняем SQL-запрос для получения ID продуктов на русском языке
    $russian_product_ids = $wpdb->get_col("
        SELECT t.element_id
        FROM {$wpdb->prefix}icl_translations AS t
        WHERE t.element_id IN ($product_ids_string)
        AND t.element_type = 'post_product'
        AND t.language_code = 'ru'
    ");


    // Фильтруем продукты, оставляя только те, которые на русском языке
    $filtered_products = array_filter($new_posts, function ($product) use ($russian_product_ids) {
        return in_array($product['parent_id'], $russian_product_ids);
    });

    // Возвращаем отфильтрованные продукты
    return $filtered_products;
}, 100, 2);

