<?php
/**
 * Plugin Name: Newsroom Web API
 * Description: Adds ACF fields and related posts data to WP REST API page responses.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Filter REST API response for pages and posts to enhance/override ACF data.
 */
add_filter('rest_prepare_page', 'newsroom_enhance_rest_api', 20, 3);
add_filter('rest_prepare_post', 'newsroom_enhance_rest_api', 20, 3);
add_filter('rest_prepare_category', 'newsroom_enhance_category_api', 20, 3);
add_filter('rest_prepare_post_tag', 'newsroom_enhance_tag_api', 20, 3);

/**
 * Register Custom Routes
 */
add_action('rest_api_init', function () {
    register_rest_route('newsroom/v1', '/theme-settings', [
        'methods' => 'GET',
        'callback' => 'newsroom_get_theme_settings_api',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('newsroom/v1', '/header', [
        'methods' => 'GET',
        'callback' => 'newsroom_get_header_api',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('newsroom/v1', '/trending', [
        'methods' => 'GET',
        'callback' => 'newsroom_get_trending_api',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('newsroom/v1', '/footer', [
        'methods' => 'GET',
        'callback' => 'newsroom_get_footer_api',
        'permission_callback' => '__return_true'
    ]);


    register_rest_route('newsroom/v1', '/search', [
        'methods' => 'GET',
        'callback' => 'newsroom_get_search_api',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('newsroom/v1', '/search-suggestions', [
        'methods' => 'GET',
        'callback' => 'newsroom_get_search_suggestions_api',
        'permission_callback' => '__return_true'
    ]);

    // Date Archive: /date-archive/2026 | /date-archive/2026/02 | /date-archive/2026/02/11
    register_rest_route('newsroom/v1', '/date-archive/(?P<year>\d{4})', [
        'methods' => 'GET',
        'callback' => 'newsroom_get_date_archive_api',
        'permission_callback' => '__return_true'
    ]);
    register_rest_route('newsroom/v1', '/date-archive/(?P<year>\d{4})/(?P<month>\d{1,2})', [
        'methods' => 'GET',
        'callback' => 'newsroom_get_date_archive_api',
        'permission_callback' => '__return_true'
    ]);
    register_rest_route('newsroom/v1', '/date-archive/(?P<year>\d{4})/(?P<month>\d{1,2})/(?P<day>\d{1,2})', [
        'methods' => 'GET',
        'callback' => 'newsroom_get_date_archive_api',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('headless/v1', '/subscribe', [
        'methods' => 'POST',
        'callback' => 'hjs_handle_subscription',
        'permission_callback' => '__return_true',
    ]);

    // Category Posts: Paginate posts within a category (page = page of posts)
    register_rest_route('newsroom/v1', '/category-posts', [
        'methods' => 'GET',
        'callback' => 'newsroom_get_category_posts_api',
        'permission_callback' => '__return_true'
    ]);
});

/**
 * FIX: Intercept Category REST requests to handle the 'page' parameter conflict.
 * When using /wp/v2/categories/?slug=crime&page=2, WP core tries to find the 2nd page of categories.
 * Since only 1 'crime' category exists, it returns []. 
 * We rename 'page' to 'paged' internally so WP finds the category, and we handle post pagination.
 */
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if (strpos($request->get_route(), '/wp/v2/categories') !== false) {
        $params = $request->get_query_params();
        if (isset($params['page']) && !isset($params['paged'])) {
            $request->set_param('paged', $params['page']);
            // We don't unset 'page' to avoid breaking core expectations, 
            // but we'll use 'paged' in our custom logic.
        }
    }
    return $result;
}, 10, 3);

//Get all THE Global Settings
function newsroom_get_theme_settings_api() {
    $options = function_exists('get_fields') ? get_fields('option') : [];
    if (empty($options)) return new WP_Error('no_options', 'No theme settings found', ['status' => 404]);

    // Apply recursive resolution (deals with ads, posts, etc. inside settings)
    $resolved_options = newsroom_resolve_fields_recursive($options);

    // Add all menus to theme settings
    $locations = get_nav_menu_locations();

    $header_menu_id = $locations['header-menu'] ?? $locations['primary'] ?? 0;
    $footer_menu_id = $locations['footer-menu'] ?? 0;
    $mobile_menu_id = $locations['mobile-menu'] ?? 0;
    $mobile_header_menu_id = $locations['mobile-menu-header'] ?? $locations['mobile-header-menu'] ?? 0;
    $categories_menu_id = $locations['categories-menu'] ?? 0;

    $resolved_options['menus'] = [
        'header'         => $header_menu_id ? newsroom_structure_menu(wp_get_nav_menu_items($header_menu_id)) : [],
        'footer'         => $footer_menu_id ? newsroom_structure_menu(wp_get_nav_menu_items($footer_menu_id)) : [],
        'mobile'         => $mobile_menu_id ? newsroom_structure_menu(wp_get_nav_menu_items($mobile_menu_id)) : [],
        'mobile_header'  => $mobile_header_menu_id ? newsroom_structure_menu(wp_get_nav_menu_items($mobile_header_menu_id)) : [],
        'categories'     => $categories_menu_id ? newsroom_structure_menu(wp_get_nav_menu_items($categories_menu_id)) : [],
    ];

    // Add WhatsApp subscription settings (only if WhatsApp Twilio plugin is active)
    if (!function_exists('is_plugin_active')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (is_plugin_active('newsroom_whatsapp_post_notification_twilio/whatsapp_post_notification_twilio.php')) {
        $resolved_options['whatsapp'] = [
            'notification_image'    => get_option('cuf_wa_notification_image', ''),
            'subscribe_button_text' => get_option('cuf_wa_subscribe_button_text', ''),
            'subscribe_button_url'  => get_option('cuf_wa_subscribe_button_url', ''),
        ];
    }

    return new WP_REST_Response($resolved_options, 200);
}

/**
 * Enhanced Category API Response
 */
function newsroom_enhance_category_api($response, $category, $request) {
    $acf = function_exists('get_fields') ? get_fields($category) : [];
    if (!is_array($acf)) $acf = [];

    $options = function_exists('get_fields') ? get_fields('option') : [];

    // 1. Resolve Global Ads from Theme Options
    $acf['advert_top'] = newsroom_get_full_ad_data($options['adrotate_ad_select'] ?? 0);
    $acf['advert_bottom'] = newsroom_get_full_ad_data($options['adrotate_ad_select_bottom'] ?? 0);

    // 2. Resolve Sidebar Data
    $acf['sidebar_data'] = newsroom_get_sidebar_data('sidebar-1');

    // 3. Resolve Trending Posts
    $trend_limit = (int)($options['trending_posts_per_page'] ?? 10);
    $acf['trending_posts'] = newsroom_get_trending_data($trend_limit, 0);

    // 4. Resolve Breadcrumb
    $acf['breadcrumb'] = newsroom_get_breadcrumb_data($category);

    // 5. Standard Pagination Logic
    $cat_id = (int)$category->term_id;
    $paged = max(1, (int)$request->get_param('page'));
    $posts_per_page = (int)($request->get_param('per_page') ?: 18);
    $manual_offset = $request->get_param('offset');

    // Default theme behavior: Top 3 Posts (Fixed)
    $acf['top_posts'] = newsroom_get_api_posts(3, $cat_id, 0);

    if ($manual_offset !== null) {
        $offset = (int)$manual_offset;
    } else {
        // Offset by 3 to skip Top Posts
        $offset = 3 + ($paged - 1) * $posts_per_page;
    }

    $acf['category_posts'] = [
        'results' => newsroom_get_api_posts($posts_per_page, $cat_id, $offset),
        'pagination' => [
            'current_page' => $paged,
            'per_page'     => $posts_per_page
        ]
    ];

    // 6. Resolve everything else recursively
    $response->data['acf'] = newsroom_resolve_fields_recursive($acf);

    return $response;
}


/**
 * Category Posts API: Paginate posts within a category
 * Usage: /newsroom/v1/category-posts?slug=crime&paged=2&per_page=18
 * Using 'paged' instead of 'page' because WP REST reserves 'page' internally.
 */
function newsroom_get_category_posts_api($request) {
    $slug = $request->get_param('slug');
    if (empty($slug)) {
        return new WP_Error('missing_slug', 'Please provide a category slug', ['status' => 400]);
    }

    $category = get_category_by_slug($slug);
    if (!$category) {
        return new WP_Error('not_found', 'Category not found', ['status' => 404]);
    }

    $cat_id = (int)$category->term_id;
    $paged = max(1, (int)($request->get_param('paged') ?: 1));
    $posts_per_page = (int)(get_field('category_post_per_page', $category) ?: 18);
    $manual_offset = $request->get_param('offset');

    $options = function_exists('get_fields') ? get_fields('option') : [];

    // Top 3 Posts (Fixed — always the same regardless of page)
    $top_posts = newsroom_get_api_posts(3, $cat_id, 0);

    // Category Posts (Paginated)
    if ($manual_offset !== null) {
        $offset = (int)$manual_offset;
    } else {
        $offset = 3 + ($paged - 1) * $posts_per_page;
    }
    $results = newsroom_get_api_posts($posts_per_page, $cat_id, $offset);

    // Total count for pagination
    $total_q = new WP_Query([
        'post_type'      => 'post',
        'cat'            => $cat_id,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_status'    => 'publish',
        'ignore_sticky_posts' => true
    ]);
    $total_posts = (int)$total_q->found_posts;
    $paginated_pool = max(0, $total_posts - 3);
    $max_pages = $posts_per_page > 0 ? ceil($paginated_pool / $posts_per_page) : 1;

    $data = [
        'category'       => ['id' => $cat_id, 'name' => $category->name, 'slug' => $category->slug],
        'breadcrumb'     => newsroom_get_breadcrumb_data($category),
        'trending_posts' => newsroom_get_trending_data((int)($options['trending_posts_per_page'] ?? 10), 0),
        'advert_top'     => newsroom_get_full_ad_data($options['adrotate_ad_select'] ?? 0),
        'advert_bottom'  => newsroom_get_full_ad_data($options['adrotate_ad_select_bottom'] ?? 0),
        'sidebar_data'   => newsroom_get_sidebar_data('sidebar-1'),
        'top_posts'      => $top_posts,
        'category_posts' => [
            'results'    => $results,
            'pagination' => [
                'current_page'  => $paged,
                'category_post_per_page' => get_field('category_post_per_page', $category),
                'total_pages'   => (int)$max_pages,
                'total_results' => $total_posts,
                'offset_used'   => $offset
            ]
        ]
    ];

    return new WP_REST_Response(newsroom_resolve_fields_recursive($data), 200);
}


/**
 * Header API: Returns all settings and menus for the site header
 */
function newsroom_get_header_api() {
    $options = function_exists('get_fields') ? get_fields('option') : [];
    $locations = get_nav_menu_locations();
    
    $header_menu_id = $locations['header-menu'] ?? 0;
    $mobile_menu_id = $locations['mobile-menu'] ?? 0;
    $mobile_header_menu_id = $locations['mobile-menu-header'] ?? $locations['mobile-header-menu'] ?? 0;
    $categories_menu_id = $locations['categories-menu'] ?? 0;

    $header_items = $header_menu_id ? wp_get_nav_menu_items($header_menu_id) : [];
    $mobile_items = $mobile_menu_id ? wp_get_nav_menu_items($mobile_menu_id) : [];
    $mobile_header_items = $mobile_header_menu_id ? wp_get_nav_menu_items($mobile_header_menu_id) : [];
    $categories_items = $categories_menu_id ? wp_get_nav_menu_items($categories_menu_id) : [];

    $data = [
        'header_logo' => $options['header_logo'] ?? null,
        'social_items' => $options['social_items'] ?? [],
        'current_date' => date('l, F j, Y'),
        'menus' => [
            'header' => newsroom_structure_menu($header_items),
            'mobile' => newsroom_structure_menu($mobile_items),
            'mobile_header' => newsroom_structure_menu($mobile_header_items),
            'categories' => newsroom_structure_menu($categories_items),
        ],
        'search_settings' => [
            'popular_categories' => $options['select_popular_categories'] ?? [],
            'popular_search_title' => $options['popular_search_title'] ?? 'Popular Searches',
            'trending_posts' => newsroom_get_trending_data(4, 0), // Mirroring header.php line 272/286
        ],
        'mobile_sticky_menu' => $options['mobile_header_list'] ?? []
    ];

    return new WP_REST_Response(newsroom_resolve_fields_recursive($data), 200);
}

/**
 * Standalone Trending API
 */
function newsroom_get_trending_api($request) {
    $limit = (int)($request->get_param('limit') ?: 10);
    $offset = (int)($request->get_param('offset') ?: 0);
    $data = newsroom_get_trending_data($limit, $offset);
    return new WP_REST_Response($data, 200);
}

/**
 * Footer API: Returns all settings and menus for the site footer
 */
function newsroom_get_footer_api() {
    $options = function_exists('get_fields') ? get_fields('option') : [];
    $locations = get_nav_menu_locations();
    
    $footer_menu_id = $locations['footer-menu'] ?? 0;
    $footer_items = $footer_menu_id ? wp_get_nav_menu_items($footer_menu_id) : [];

    $data = [
        'footer_logo' => $options['footer_logo'] ?? null,
        'footer_description' => $options['footer_description'] ?? '',
        'footer_copyright' => $options['footer_copyright'] ?? '',
        'menu' => newsroom_structure_menu($footer_items),
        'sidebar' => newsroom_get_sidebar_data('custom-footer-sidebar'),
        'social_items' => $options['social_items'] ?? [],
        'site_credit' => [
            'enabled' => $options['site_credit'] ?? false,
            'text' => $options['site_credit_text'] ?? ''
        ],
        'page_links' => $options['footer_page_listing'] ?? [],
        'whatsapp' => [
            'enabled' => $options['whatsapp_button'] ?? false,
            'url' => $options['footer_whatsapp_url'] ?? ''
        ],
        'newsletter' => [
            'title' => $options['footer_newsletter_title'] ?? '',
            'enabled' => !empty($options['footer_newsletter'])
        ]
    ];

    return new WP_REST_Response(newsroom_resolve_fields_recursive($data), 200);
}

/**
 * Helper to structure menu items into a tree
 */
function newsroom_structure_menu($items) {
    if (empty($items)) return [];
    $structured_menu = [];
    foreach ($items as $item) {
        if ($item->menu_item_parent == 0) {
            $structured_menu[] = newsroom_format_menu_item($item, $items);
        }
    }
    return $structured_menu;
}

function newsroom_format_menu_item($item, $all_items) {
    if (empty($item)) return null;

    $child_items = [];
    foreach ($all_items as $ci) {
        if ($ci->menu_item_parent == $item->ID) {
            $child_items[] = newsroom_format_menu_item($ci, $all_items);
        }
    }

    $id = (int)$item->object_id;
    $post_type = $item->object; // e.g. 'page', 'post', 'category', 'custom'
    $slug = '';

    if ($item->type === 'post_type') {
        $slug = get_post_field('post_name', $id);
    } elseif ($item->type === 'taxonomy') {
        $term = get_term($id);
        $slug = $term ? $term->slug : '';
    }

    return [
        'id'           => $id,
        'menu_item_id' => (int)$item->ID,
        'title'        => html_entity_decode($item->title, ENT_QUOTES, 'UTF-8'),
        'url'          => $item->url,
        'post_type'    => $post_type,
        'slug'         => $slug,
        'children'     => $child_items
    ];
}

//Get the Trending Posts from Jetpack Stats
function newsroom_get_trending_data($limit = 10, $offset = 0) {
    $post_ids = [];
    $stats_cache = get_option('stats_cache');
    
    if (!empty($stats_cache) && is_array($stats_cache)) {
        $first_key = array_key_first($stats_cache);
        $level1 = $stats_cache[$first_key];
        
        if (is_array($level1)) {
            $seen_ids = [];
            $two_months_ago = strtotime('-2 months');
            
            foreach ($level1 as $daily_posts) {
                if (!is_array($daily_posts)) continue;
                foreach ($daily_posts as $p) {
                    $pid = $p['post_id'] ?? null;
                    if (!$pid || isset($seen_ids[$pid])) continue;
                    
                    // Filter by post type and date
                    if (get_post_type($pid) === 'post') {
                        $p_date = get_post_field('post_date', $pid);
                        if (strtotime($p_date) >= $two_months_ago) {
                            $seen_ids[$pid] = true;
                            // Store as object for sorting like the theme
                            $post_ids[] = [
                                'post_id' => $pid,
                                'post_date' => strtotime($p_date)
                            ];
                        }
                    }
                }
            }
        }
    }

    // Sort by Date Descending (Matches theme logic: usort date_b - date_a)
    usort($post_ids, function($a, $b) {
        return $b['post_date'] - $a['post_date'];
    });

    // fallback to Jetpack CSV API if cache is empty
    if (empty($post_ids) && function_exists('stats_get_csv')) {
        $top_posts = stats_get_csv('postviews', ['days' => 7, 'limit' => $limit + $offset]);
        if (!empty($top_posts) && is_array($top_posts)) {
            foreach ($top_posts as $p) {
                $pid = url_to_postid($p['post_permalink']);
                if ($pid && get_post_type($pid) === 'post') {
                    $post_ids[] = [
                        'post_id' => $pid,
                        'post_date' => strtotime(get_post_field('post_date', $pid))
                    ];
                }
            }
            // Sort fallback results too
            usort($post_ids, function($a, $b) {
                return $b['post_date'] - $a['post_date'];
            });
        }
    }

    if (empty($post_ids)) return [];

    // Slice results
    $post_ids = array_slice($post_ids, $offset, $limit);

    $results = [];
    foreach ($post_ids as $p_obj) {
        $id = $p_obj['post_id'];
        if ($id) {
            $p = get_post($id);
            if ($p) $results[] = newsroom_format_api_post($p);
        }
    }
    return $results;
}

/**
 * Custom callback for Search API
 */
function newsroom_get_search_api($request) {
    $keyword = sanitize_text_field($request->get_param('s'));
    $paged = max(1, $request->get_param('page')) ?: 1;
    $posts_per_page = 12; // Standard for archive/search

    if (empty($keyword)) {
        return new WP_REST_Response([
            'results' => [],
            'pagination' => ['total_pages' => 0, 'current_page' => 1],
            'sidebar_data' => newsroom_get_sidebar_data('sidebar-1')
        ], 200);
    }

    $args = array(
        's'              => $keyword,
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged
    );

    $query = new WP_Query($args);
    $results = [];

    if ($query->have_posts()) {
        foreach ($query->posts as $p) {
            $results[] = newsroom_format_api_post($p);
        }
    }

    // Add "no results" messaging inside results matching the theme
    if (empty($results)) {
        $results = [
            'message' => 'No results found for ' . $keyword,
            'description' => 'No posts found. Try another search.'
        ];
    }

    $response = [
        'results' => $results,
        'pagination' => [
            'total_pages' => $query->max_num_pages,
            'current_page' => (int)$paged,
            'total_results' => (int)$query->found_posts
        ],
        'sidebar_data' => newsroom_get_sidebar_data('sidebar-1')
    ];

    wp_reset_postdata();

    return new WP_REST_Response($response, 200);
}

/**
 * Search Suggestions API (Lightweight - for keyup autocomplete)
 * Mirrors theme's newsroom_ajax_search_callback()
 */
function newsroom_get_search_suggestions_api($request) {
    $keyword = sanitize_text_field($request->get_param('s'));

    if (empty($keyword) || strlen($keyword) < 1) {
        return new WP_REST_Response(['results' => []], 200);
    }

    $args = [
        's'              => $keyword,
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 6, // Same as theme's AJAX search
    ];

    $query = new WP_Query($args);
    $results = [];

    if ($query->have_posts()) {
        foreach ($query->posts as $p) {
            $categories = get_the_category($p->ID);
            $results[] = [
                'id'        => $p->ID,
                'title'     => html_entity_decode(get_the_title($p), ENT_QUOTES, 'UTF-8'),
                'slug'      => $p->post_name,
                'link'      => get_permalink($p),
                'thumbnail' => get_the_post_thumbnail_url($p->ID, 'medium') ?: null,
                'category'  => !empty($categories) ? [
                    'id'   => $categories[0]->term_id,
                    'name' => html_entity_decode($categories[0]->name, ENT_QUOTES, 'UTF-8')
                ] : null,
            ];
        }
    }

    wp_reset_postdata();

    // Add "no results" messaging inside results matching the theme
    if (empty($results)) {
        $results = [
            'message' => 'No result found.'
        ];
    }

    $response_data = ['results' => $results];

    return new WP_REST_Response($response_data, 200);
}

/**
 * Date Archive API: Mirrors date.php template
 * Usage:
 *   /newsroom/v1/date-archive/2026          (Yearly)
 *   /newsroom/v1/date-archive/2026/02       (Monthly)
 *   /newsroom/v1/date-archive/2026/02/11    (Daily)
 *   Add ?page=2 for pagination
 */
function newsroom_get_date_archive_api($request) {
    $year  = (int) $request->get_param('year');
    $month = (int) $request->get_param('month');
    $day   = (int) $request->get_param('day');
    $paged = max(1, (int) $request->get_param('page'));
    $posts_per_page = 12; // Matches theme default for archive grids

    if (empty($year)) {
        return new WP_Error('missing_year', 'The year parameter is required.', ['status' => 400]);
    }

    // --- Build Archive Title & Date Label (mirroring date.php lines 46-84 / 93-131) ---
    $archive_title = '';
    $date_label    = '';

    if ($day && $month) {
        // Daily archive
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $formatted = date_i18n('F j, Y', strtotime($date_str));
        $archive_title = 'Daily Archives: ' . $formatted;
        $date_label    = $formatted;
    } elseif ($month) {
        // Monthly archive
        $date_str = sprintf('%04d-%02d-01', $year, $month);
        $formatted = date_i18n('F Y', strtotime($date_str));
        $archive_title = 'Monthly Archives: ' . $formatted;
        $date_label    = $formatted;
    } else {
        // Yearly archive
        $archive_title = 'Yearly Archives: ' . $year;
        $date_label    = (string) $year;
    }

    // --- Build WP_Query for date-based posts (mirroring the main loop in date.php) ---
    $date_query = ['year' => $year];
    if ($month) $date_query['month'] = $month;
    if ($day)   $date_query['day']   = $day;

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'date_query'     => [$date_query],
        'ignore_sticky_posts' => true
    ];

    $query = new WP_Query($args);
    $results = [];

    if ($query->have_posts()) {
        foreach ($query->posts as $p) {
            $results[] = newsroom_format_api_post($p);
        }
    }

    // No results messaging
    if (empty($results)) {
        $results = [
            'message' => 'No results found for ' . $date_label,
            'description' => 'No posts found. Try another date.'
        ];
    }

    // --- Build Date Breadcrumb: Home > 2026 > February > 11 ---
    $base_url = home_url('/');
    $breadcrumb = [
        ['title' => 'Home', 'url' => $base_url]
    ];
    // Year level
    $breadcrumb[] = ['title' => (string) $year, 'url' => $base_url . $year . '/'];
    // Month level
    if ($month) {
        $month_name = date_i18n('F', mktime(0, 0, 0, $month, 1));
        $breadcrumb[] = ['title' => $month_name, 'url' => $base_url . $year . '/' . sprintf('%02d', $month) . '/'];
    }
    // Day level
    if ($day && $month) {
        $breadcrumb[] = ['title' => (string) $day, 'url' => $base_url . $year . '/' . sprintf('%02d', $month) . '/' . sprintf('%02d', $day) . '/'];
    }

    // --- Global data (mirroring date.php: breadcrumb, trending, sidebar) ---
    $options = function_exists('get_fields') ? get_fields('option') : [];
    $trend_limit = (int)($options['trending_posts_per_page'] ?? 10);

    $response = [
        'archive_title'  => $archive_title,
        'date_label'     => $date_label,
        'results'        => $results,
        'pagination'     => [
            'total_pages'   => (int) $query->max_num_pages,
            'current_page'  => (int) $paged,
            'total_results' => (int) $query->found_posts
        ],
        'breadcrumb'     => $breadcrumb,
        'trending_posts' => newsroom_get_trending_data($trend_limit, 0),
        'sidebar_data'   => newsroom_get_sidebar_data('sidebar-1'),
        'date_sidebar'   => newsroom_get_sidebar_data('date')
    ];

    wp_reset_postdata();

    return new WP_REST_Response($response, 200);
}

function newsroom_enhance_rest_api($response, $post, $request) {
    // Only enhance for published pages and posts to avoid data leakage on drafts or 404 targets
    if ($post->post_status !== 'publish') {
        return $response;
    }

    $acf = &$response->data['acf'];
    if (!is_array($acf)) $acf = [];

    // 1. Add API Status Flag
    $response->data['newsroom_api'] = 'active';

    // 2. Add Global Data (Breadcrumb & Trending) - Always present in theme
    $options = function_exists('get_fields') ? get_fields('option') : [];
    $acf['breadcrumb'] = newsroom_get_breadcrumb_data($post->ID);
    
    $trend_limit = (int)($options['trending_posts_per_page'] ?? 10);
    $acf['trending_posts'] = newsroom_get_trending_data($trend_limit, 0);

    // 2. Keep HTML for Post Content to support images and embeds (like Canva)
    // We only decode entities to ensure clean HTML for dangerouslySetInnerHTML in React
    if (isset($response->data['content']['rendered'])) {
        $response->data['content']['rendered'] = html_entity_decode($response->data['content']['rendered'], ENT_QUOTES, 'UTF-8');
    }
    
    // For excerpts, we'll keep basic formatting but remove aggressive stripping
    if (isset($response->data['excerpt']['rendered'])) {
        $allowed_ex_tags = '<a><strong><em><b><i><br>';
        $response->data['excerpt']['rendered'] = trim(html_entity_decode(strip_tags($response->data['excerpt']['rendered'], $allowed_ex_tags), ENT_QUOTES, 'UTF-8'));
    }

    // 3. Global Options: Pulled selectively for non-standard pages
    $slug = strtolower($post->post_name);
    $options = function_exists('get_fields') ? get_fields('option') : [];
    
    $is_404 = ($slug === '404' || strpos($slug, 'not-found') !== false);
    $is_ty = (strpos($slug, 'thank-you') !== false);
    $is_trending = (strpos($slug, 'trending') !== false);
    $is_all_news = (get_page_template_slug($post->ID) === 'page-all-news.php' || strpos($slug, 'all-news') !== false);

    if ($is_404 || $is_ty || $is_trending || $is_all_news) {
        
        foreach ($options as $key => $val) {
            // Pull 404 fields
            if ($is_404 && strpos($key, 'not_found') !== false) {
                $acf[$key] = $val;
            }
            // Pull Thank You fields (matching 'thank_you' and 'ty')
            if ($is_ty && (strpos($key, 'thank_you') !== false || strpos($key, '_ty_') !== false)) {
                $acf[$key] = $val;
            }
        }
        // Force sidebar for these pages as they are often layout-driven
        $acf['show_sidebar'] = true;

        // Specialized logic for All News Template
        if ($is_all_news) {
            // A. Ads
            $acf['advert_top'] = newsroom_get_full_ad_data($options['adrotate_ad_select'] ?? 0);
            $acf['advert_bottom'] = newsroom_get_full_ad_data($options['adrotate_ad_select_bottom'] ?? 0);

            // B. Breadcrumb
            $acf['breadcrumb'] = newsroom_get_breadcrumb_data($post->ID);

            // C. Trending Section (default 10 posts)
            $trend_limit = (int)($options['trending_posts_per_page'] ?? 10);
            $acf['trending_posts'] = newsroom_get_trending_data($trend_limit, 0);

            // D. All Posts (Archives)
            $paged = max(1, $request->get_param('page')) ?: 1;
            $posts_per_page = 12; // Matches page-all-news.php line 61
            
            $args = [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => $posts_per_page,
                'paged'          => $paged,
                'orderby'        => 'date',
                'order'          => 'DESC'
            ];
            
            $q = new WP_Query($args);
            $all_posts = [];
            foreach ($q->posts as $p) {
                $all_posts[] = newsroom_format_api_post($p);
            }
            
            $acf['all_news_posts'] = [
                'results' => $all_posts,
                'pagination' => [
                    'total_pages' => $q->max_num_pages,
                    'current_page' => (int)$paged,
                    'total_results' => (int)$q->found_posts
                ]
            ];
            wp_reset_postdata();
        }
    }

    // 3. Resolve Single Post specific data (Mirroring single.php)
    if ($post->post_type === 'post') {
        // A. Subtitle
        $acf['subtitle'] = get_field('subtitle', $post->ID);

        // B. Audio Player / AtlasVoice Logic (Mirroring single.php line 63-67)
        $post_timestamp = get_post_time('U', true, $post->ID);
        $nov2025_timestamp = strtotime('2025-11-01');
        $acf['audio_player_html'] = ($post_timestamp >= $nov2025_timestamp) ? do_shortcode("[atlasvoice listen_text='Listen this article' loop='false']") : '';

        // C. Featured Image Caption (Mirroring single.php line 154-158)
        $thumb_id = get_post_thumbnail_id($post->ID);
        $thumb_post = get_post($thumb_id);
        $acf['featured_image_caption'] = $thumb_post ? $thumb_post->post_excerpt : '';

        // D. Category Logic (Primary Category Detection mirroring single.php line 71-90)
        $categories = get_the_category($post->ID);
        $primary_cat_id = get_post_meta($post->ID, '_yoast_wpseo_primary_category', true);
        $primary_cat = null;
        if ($primary_cat_id) {
            foreach ($categories as $cat) {
                if ($cat->term_id == $primary_cat_id) {
                    $primary_cat = ['id' => $cat->term_id, 'name' => html_entity_decode($cat->name, ENT_QUOTES, 'UTF-8')];
                    break;
                }
            }
        }
        $acf['primary_category'] = $primary_cat ?: (!empty($categories) ? ['id' => $categories[0]->term_id, 'name' => html_entity_decode($categories[0]->name, ENT_QUOTES, 'UTF-8')] : null);

        // E. Next/Prev Navigation (Mirroring single.php line 169-202)
        $prev = get_adjacent_post(false, '', true);
        $next = get_adjacent_post(false, '', false);
        
        $acf['post_navigation'] = [
            'previous' => $prev ? [
                'id' => $prev->ID,
                'title' => get_the_title($prev->ID),
                'link' => get_permalink($prev->ID),
                'thumbnail' => get_the_post_thumbnail_url($prev->ID, 'full')
            ] : null,
            'next' => $next ? [
                'id' => $next->ID,
                'title' => get_the_title($next->ID),
                'link' => get_permalink($next->ID),
                'thumbnail' => get_the_post_thumbnail_url($next->ID, 'full')
            ] : null,
        ];

        // F. Related Articles (Same category, last 3 - mirroring single.php line 211-224)
        $cat_ids = wp_get_post_categories($post->ID);
        $acf['related_articles'] = newsroom_get_api_posts(3, $cat_ids, 0);

        // G. Ensure Sidebar is ALWAYS included (Mirroring single.php line 331)
        $acf['sidebar_data'] = newsroom_get_sidebar_data('sidebar-1');

        // H. Better Ads Manager - Post Content Ads (Above/Below/Middle/Inside)
        $acf['post_ads'] = newsroom_get_better_ads_post_data();

        // I. TTS MP3 Audio URL (Text-to-Speech generated audio)
        $tts_mp3 = get_post_meta($post->ID, 'tts_mp3_file_urls', false);
        if (!empty($tts_mp3)) {
            $acf['tts_mp3_url'] = $tts_mp3;
        }
    }

    // 4. Resolve Top-Level Sidebar if enabled or if it's a layout-driven page (Fallthrough for pages)
    if (empty($acf['sidebar_data']) && (!empty($acf['show_sidebar']) || !empty($acf['show_sider']))) {
        $acf['sidebar_data'] = newsroom_get_sidebar_data('sidebar-1');
    }

    // 5. Recursively resolve all fields (Dynamic detection for layouts)
    $acf = newsroom_resolve_fields_recursive($acf);

    return $response;
}

/**
 * Recursively traverses the ACF array to find and resolve posts and ads.
 * Also automatically strips HTML and decodes entities for plain-text strings.
 */
function newsroom_resolve_fields_recursive($data) {
    if (is_string($data)) {
        // Allow common HTML tags for Headless/React rendering (Images, Iframes, Formatting)
        $allowed_tags = '<img><iframe><div><p><a><span><strong><em><b><i><ul><li><ol><br><h1><h2><h3><h4><h5><h6>';
        return trim(html_entity_decode(strip_tags($data, $allowed_tags), ENT_QUOTES, 'UTF-8'));
    }
    if (!is_array($data)) return $data;

    // A. Check if this is a layout/object that needs post fetching
    $layout_name = $data['acf_fc_layout'] ?? $data['type'] ?? '';
    if (!empty($layout_name)) {
        $data = newsroom_resolve_layout_posts($data);
    }

    // B. Pass through and resolve Ads and Images
    foreach ($data as $key => $val) {
        // 1. Resolve Ads (Exclude position numbers)
        if ($key !== 'select_advert_position' && (strpos($key, 'advert_select') !== false || strpos($key, 'adrotate_ad') !== false || strpos($key, '_advert') !== false)) {
            if (!empty($val) && (is_string($val) || is_numeric($val))) {
                // Determine suffix for the key to avoid overwriting multiple ads at same level
                $suffix = (strpos($key, 'top') !== false) ? '_top' : ((strpos($key, 'bottom') !== false) ? '_bottom' : '');
                $data['advert_code' . $suffix] = newsroom_get_full_ad_data($val);
            }
        }

        // 2. Resolve Image IDs automatically for keys containing 'image', 'logo', 'icon', 'thumb'
        if (preg_match('/(image|logo|icon|thumb)/i', $key) && !is_array($val) && !empty($val) && is_numeric($val)) {
            $img_url = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($val) : '';
            if ($img_url) {
                $data[$key] = [
                    'id' => (int)$val,
                    'url' => $img_url,
                    'alt' => function_exists('get_post_meta') ? get_post_meta($val, '_wp_attachment_image_alt', true) : ''
                ];
            }
        }



        // 5. Resolve Social Icons from Theme Settings when enabled
        if ($key === 'social_icons' && $val === true) {
            $theme_options = function_exists('get_fields') ? get_fields('option') : [];
            $data['social_icons'] = $theme_options['social_items'] ?? [];
        }
    }

    // D. Interleave Posts and Ads into section_items (for frontend rendering)
    if (isset($data['section_posts']) && is_array($data['section_posts'])) {
        $items = [];
        $posts = $data['section_posts'];
        $ad = $data['advert_code'] ?? $data['advert_code_top'] ?? $data['advert_code_bottom'] ?? null;
        
        // Use select_advert_position (ACF Name) or fallback to advert_position
        $pos = 0;
        if (isset($data['select_advert_position']) && $data['select_advert_position'] !== '') {
            $pos = (int)$data['select_advert_position'];
        } elseif (isset($data['advert_position']) && $data['advert_position'] !== '') {
            $pos = (int)$data['advert_position'];
        }

        $ad_inserted = false;   
        $idx = 0;

        foreach ($posts as $p) {
            $idx++;
            
            // "At Position" logic: If position is N, insert ad BEFORE the Nth post
            if ($ad && !$ad_inserted && $pos > 0 && $idx == $pos) {
                $items[] = ['type' => 'ad', 'data' => $ad];
                $ad_inserted = true;
            }

            $items[] = ['type' => 'post', 'data' => $p];
        }

        // Fallback: If ad was not inserted (pos=0, pos > total, or no posts), add at the end
        if ($ad && !$ad_inserted) {
            $items[] = ['type' => 'ad', 'data' => $ad];
        }

        $data['section_items'] = $items;

        // Clean up redundant arrays to avoid duplication, 
        // but KEEP configuration fields like select_advert_position and adrotate_ad_select.
        unset($data['section_posts']);
        unset($data['advert_code']);
        unset($data['advert_code_top']);
        unset($data['advert_code_bottom']);
    }

    // C. Continue recursion for nested arrays and clean up strings
    $html_keep_keys = ['bannercode', 'advert_code', 'audio_player_html', 'advert_code_top', 'advert_code_bottom'];
    $block_recursion = ['section_posts', 'trending_posts', 'global_options', 'sidebar_data', 'section_items'];

    foreach ($data as $key => &$value) {
        // Skip keys we just transformed into arrays (like images) to avoid redundant recursion or stripping
        if (is_array($value) && isset($value['url']) && isset($value['id'])) continue;

        if (is_string($value) && !in_array($key, $html_keep_keys)) {
            $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'));
        } elseif (is_array($value) && !in_array($key, array_merge($html_keep_keys, $block_recursion))) {
            $value = newsroom_resolve_fields_recursive($value);
        }
    }

    return $data;
}

/**
 * Dynamically fetches posts for any layout that looks like a news section.
 */
function newsroom_resolve_layout_posts($layout) {
  $layout_name = $layout['acf_fc_layout'] ?? $layout['type'] ?? '';
  
  // 0. Context switch for Grouped Sections (Category News Section wrapper)
  if ($layout_name === 'category_news_section') {
      $sub_layout = $layout['news_layout'] ?? '';
      if ($sub_layout) {
          $layout_name = $sub_layout; // Switch context to the specific layout (e.g. politics_news_section)
      }
  }
  
  $has_category = !empty($layout['select_category']);
  $is_recent = (strpos($layout_name, 'recent') !== false);

  if ($has_category || $is_recent) {
    $cat_ids = [];
    if ($has_category) {
      $raw_cats = is_array($layout['select_category']) ? $layout['select_category'] : explode(',', $layout['select_category']);
      $cat_ids = array_map('intval', array_filter($raw_cats));
    }

    $count = 5;
    $offset = 0;

    foreach ($layout as $key => $val) {
        if (strpos($key, 'post_per_page') !== false || strpos($key, 'limit') !== false) {
            if (!empty($val)) {
                $count = intval($val);
            } elseif (function_exists('acf_get_field')) {
                // Field is empty — get ACF default value
                $field_obj = acf_get_field($key);
                if ($field_obj && !empty($field_obj['default_value'])) {
                    $count = intval($field_obj['default_value']);
                }
            }
        }
        if (strpos($key, 'offset') !== false) {
            if (!empty($val)) {
                $offset = intval($val);
            } elseif (function_exists('acf_get_field')) {
                $field_obj = acf_get_field($key);
                if ($field_obj && !empty($field_obj['default_value'])) {
                    $offset = intval($field_obj['default_value']);
                }
            }
        }
    }

    if ($count <= 0) $count = 5;

    // A. Extra post logic: If no ad is selected in Politics, Sports, or Features, fetch +1 post to fill the gap
    $ad_field_keys = ['adrotate_ad_select', 'advert_select', 'select_advert'];
    $has_ad = false;
    foreach ($ad_field_keys as $ad_key) {
        if (!empty($layout[$ad_key])) {
            $has_ad = true;
            break;
        }
    }
    
    $is_premium_layout = (strpos($layout_name, 'politics') !== false || strpos($layout_name, 'sports') !== false || strpos($layout_name, 'feature') !== false);
    if ($is_premium_layout && !$has_ad) {
        $count++;
    }

    $layout['section_posts'] = newsroom_get_api_posts($count, $cat_ids, $offset);
  }

  // 4. Check for Trending Sections (Dedicated layout or Hero Banner which includes it in theme)
  if (strpos($layout_name, 'trending') !== false || $layout_name === 'hero_banner_section') {
      // Default from options if not specified in layout
      $options = function_exists('get_fields') ? get_fields('option') : [];
      $trend_limit = (int)($options['post_per_page'] ?? $options['trending_posts_per_page'] ?? 10);

      foreach ($layout as $key => $val) {
          if (strpos($key, 'post_per_page') !== false || strpos($key, 'limit') !== false) {
              if (!empty($val)) $trend_limit = intval($val);
          }
      }
      $layout['trending_posts'] = newsroom_get_trending_data($trend_limit, 0);
  }

  // 4. Capture Sidebar Data (Conditional or YouTube specific)
  $show_sidebar = !empty($layout['show_sidebar']);
  
  if ($layout_name === 'youtube_section') {
      $layout['youtube_data'] = newsroom_get_sidebar_data('custom-youtube-sidebar');
  } elseif ($show_sidebar) {
      // Fetch the default generic sidebar for other sections if show_sidebar is enabled
      $layout['sidebar_data'] = newsroom_get_sidebar_data('sidebar-1');
  }

  return $layout;
}

/**
 * Attempts to extract raw data (YouTube, etc.) and HTML from widgets in a sidebar.
 */
function newsroom_get_sidebar_data($sidebar_id) {
    $sidebars_widgets = wp_get_sidebars_widgets();
    if (!isset($sidebars_widgets[$sidebar_id])) return null;

    $results = [];
    foreach ($sidebars_widgets[$sidebar_id] as $widget_id) {
        // Extract widget type and instance ID
        preg_match('/^(.+)-(\d+)$/', $widget_id, $matches);
        if (!$matches) continue;

        $widget_base = $matches[1];
        $instance_id = $matches[2];
        $options = get_option('widget_' . $widget_base);

        if (isset($options[$instance_id])) {
            $instance = $options[$instance_id];
            
            // 1. Check for Custom HTML or Text widgets (Parse for YouTube links)
            $content = $instance['text'] ?? $instance['content'] ?? '';
            if (!empty($content)) {
                // Regex for YouTube URLs or IDs
                $youtube_pattern = '/(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/(watch\?v=|embed\/|v\/|shorts\/)?([a-zA-Z0-9_-]{11})/i';
                if (preg_match_all($youtube_pattern, $content, $url_matches)) {
                    foreach ($url_matches[0] as $url) {
                         $results[] = [
                             'type' => 'link',
                             'url' => $url,
                             'id' => end(explode('/', rtrim($url, '/'))) // Basic ID extraction
                         ];
                    }
                }
            }

            // 2. Check for BetterStudio YouTube Playlist widget
            // We look for common keys used by this widget type
            if ($widget_base === 'bs-youtube-playlist' || strpos($widget_base, 'youtube') !== false) {
                if (isset($instance['playlist_url'])) {
                    $results[] = [
                        'type' => 'playlist',
                        'url' => html_entity_decode($instance['playlist_url']),
                        'title' => $instance['title'] ?? ''
                    ];
                }
                // Try other potential keys if 'playlist_url' is missing
                elseif (isset($instance['url'])) {
                    $results[] = [
                        'type' => 'youtube_url',
                        'url' => html_entity_decode($instance['url']),
                        'title' => $instance['title'] ?? ''
                    ];
                }
            }

            // 3. AdRotate / Generic Ad Support
            // We check for any numeric ID in common keys and verify if it's a valid AdRotate group/ad.
            // This handles randomized widget names (e.g., 'ckajepebyf') used by AdRotate Pro.
            $potential_id = $instance['adrotate_id'] ?? $instance['adrotate_group'] ?? $instance['groupid'] ?? $instance['group'] ?? $instance['id'] ?? $instance['ad'] ?? 0;
            if ($potential_id && is_numeric($potential_id)) {
                $ad_data = newsroom_get_full_ad_data($potential_id);
                if ($ad_data) {
                    $results[] = [
                        'type' => 'adrotate',
                        'advert_code' => $ad_data
                    ];
                }
            }

            // 4. Custom Recent Posts Widget Support
            if ($widget_base === 'my_recent_posts_widget') {
                $count = !empty($instance['number']) ? absint($instance['number']) : 3;
                $results[] = [
                    'type' => 'recent_posts',
                    'title' => $instance['title'] ?? '',
                    'section_posts' => newsroom_get_api_posts($count, [], 0)
                ];
            }

            // 5. Check for common YouTube plugins
            if (isset($instance['channel_url']) || isset($instance['channel_id'])) {
                $results[] = [
                    'type' => 'channel',
                    'url' => $instance['channel_url'] ?? '',
                    'id' => $instance['channel_id'] ?? ''
                ];
            }
        }
    }

    return [
        'data' => $results
    ];
}

/**
 * Helper to get posts for the API
 */
function newsroom_get_api_posts($count, $cat_ids, $offset) {
  $args = [
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => $count,
    'offset'         => intval($offset),
    'orderby'        => 'date',
    'order'          => 'DESC',
    'ignore_sticky_posts' => true
  ];

  if (!empty($cat_ids)) {
    if (is_numeric($cat_ids)) {
        // Use 'cat' to match theme behavior or 'category__in' for strict exclusion of children
        // Given "only for given category id", using cat is usually safer for category pages
        $args['cat'] = (int)$cat_ids;
    } else {
        $args['category__in'] = (array)$cat_ids;
    }
  }

  $q = new WP_Query($args);
  $posts = [];

  if ($q->have_posts()) {
    foreach ($q->posts as $p) {
      $posts[] = newsroom_format_api_post($p);
    }
  }
  wp_reset_postdata();
  return $posts;
}

/**
 * Standard formatter for Post objects in API response
 */
function newsroom_format_api_post($p) {
  if (!$p) return null;
  $cat = get_the_category($p->ID);
  return [
    'id'        => $p->ID,
    'title'     => html_entity_decode(get_the_title($p), ENT_QUOTES, 'UTF-8'),
    'link'      => get_permalink($p),
    'slug'      => $p->post_name,
    'date'      => html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags(get_the_date('M j, Y', $p->ID)))), ENT_QUOTES, 'UTF-8'),
    'thumbnail' => get_the_post_thumbnail_url($p->ID, 'full'),
    'excerpt'   => trim(html_entity_decode(strip_tags(wp_trim_words($p->post_content, 30)), ENT_QUOTES, 'UTF-8')),
    'categories' => array_map(function($c) { return ['id' => $c->term_id, 'name' => html_entity_decode($c->name, ENT_QUOTES, 'UTF-8')]; }, $cat),
  ];
}

/**
 * Helper to get breadcrumb data for the API
 * Supports Post ID, Page ID, or WP_Term object (for categories)
 */
function newsroom_get_breadcrumb_data($obj) {
    if (!$obj) return [];
    
    $breadcrumb = [
        ['title' => 'Home', 'url' => home_url('/')]
    ];

    if (is_numeric($obj)) {
        $post = get_post($obj);
        if (!$post) return $breadcrumb;

        if ($post->post_type === 'page') {
            if ($post->post_parent) {
                $ancestors = array_reverse(get_post_ancestors($post->ID));
                foreach ($ancestors as $ancestor_id) {
                    $breadcrumb[] = [
                        'title' => get_the_title($ancestor_id),
                        'url' => get_permalink($ancestor_id)
                    ];
                }
            }
            $breadcrumb[] = ['title' => get_the_title($post->ID), 'url' => get_permalink($post->ID)];
        } elseif ($post->post_type === 'post') {
            $categories = get_the_category($post->ID);
            if (!empty($categories)) {
                $primary_cat = $categories[0];
                $ancestors = array_reverse(get_ancestors($primary_cat->term_id, 'category'));
                foreach ($ancestors as $ancestor_id) {
                    $ancestor = get_category($ancestor_id);
                    $breadcrumb[] = [
                        'title' => $ancestor->name,
                        'url' => get_category_link($ancestor->term_id)
                    ];
                }
                $breadcrumb[] = [
                    'title' => $primary_cat->name,
                    'url' => get_category_link($primary_cat->term_id)
                ];
            }
            $breadcrumb[] = ['title' => get_the_title($post->ID), 'url' => get_permalink($post->ID)];
        }
    } elseif ($obj instanceof WP_Term) {
        if ($obj->parent != 0) {
            $ancestors = array_reverse(get_ancestors($obj->term_id, 'category'));
            foreach ($ancestors as $ancestor_id) {
                $ancestor = get_category($ancestor_id);
                $breadcrumb[] = [
                    'title' => $ancestor->name,
                    'url' => get_category_link($ancestor->term_id)
                ];
            }
        }
        $breadcrumb[] = ['title' => $obj->name, 'url' => get_category_link($obj->term_id)];
    }
    
    return $breadcrumb;
}

/**
 * Fetches full AdRotate Group and Ads data.
 * Handles both Group IDs and Single Ad IDs.
 */
function newsroom_get_full_ad_data($id) {
    global $wpdb;
    $id = (int)$id;
    if ($id <= 0) return null;

    $table_ads = $wpdb->prefix . 'adrotate';
    $table_groups = $wpdb->prefix . 'adrotate_groups';
    $table_linkmeta = $wpdb->prefix . 'adrotate_linkmeta';

    // 1. Try to find as a GROUP first
    $group = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, modus, adspeed, repeat_impressions, gridrows, gridcolumns FROM {$table_groups} WHERE id = %d",
        $id
    ));

    if ($group) {
        $ads = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.title, a.bannercode, a.image, a.tracker 
             FROM {$table_ads} a JOIN {$table_linkmeta} m ON a.id = m.ad 
             WHERE m.group = %d AND a.type = 'active'
             GROUP BY a.id",
            $id
        ));
    } else {
        // 2. If not a group, try to find as a SINGLE ad
        $single_ad = $wpdb->get_row($wpdb->prepare(
            "SELECT id, title, bannercode, image, tracker FROM {$table_ads} WHERE id = %d AND type = 'active'",
            $id
        ));
        
        if (!$single_ad) return null;

        // Create a dummy group wrapper for consistency
        $group = (object)[
            'id' => 0,
            'name' => 'Single Advertisement',
            'modus' => 0,
            'adspeed' => 0,
            'repeat_impressions' => 'N',
            'gridrows' => 1,
            'gridcolumns' => 1
        ];
        $ads = [$single_ad];
    }

    $processed_ads = [];
    $site_url = get_site_url();
    $config = get_option('adrotate_config');
    $banner_folder = isset($config['banner_folder']) ? $config['banner_folder'] : 'banners';

    foreach ($ads as $ad) {
        $html = stripslashes(html_entity_decode($ad->bannercode));
        $ad_image = '';
        if (!empty($ad->image)) {
            $ad_image = str_replace('%folder%', $site_url . '/wp-content/' . $banner_folder, $ad->image);
        }
        if (empty($ad_image) && preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $html, $matches)) {
            $ad_image = $matches[1];
            if (strpos($ad_image, 'http') === false) $ad_image = $site_url . '/' . ltrim($ad_image, '/');
        }

        $ad_url = '';
        if (preg_match('/<a[^>]+href=[\'"]([^\'"]+)[\'"][^>]*>/i', $html, $matches)) {
            $ad_url = $matches[1];
        }

        $processed_ads[] = [
            'id' => (int)$ad->id,
            'title' => html_entity_decode($ad->title, ENT_QUOTES, 'UTF-8'),
            'image_url' => $ad_image,
            'click_url' => $ad_url,
            'tracker_status' => $ad->tracker,
            'tracking_data' => base64_encode($ad->id . ',' . $id . ',0'),
            'bannercode' => stripslashes($ad->bannercode)
        ];
    }

    return [
        'group' => [
            'id' => (int)$group->id,
            'name' => $group->name,
            'modus' => (int)$group->modus,
            'adspeed' => (int)$group->adspeed,
            'repeat_impressions' => $group->repeat_impressions,
            'gridrows' => (int)$group->gridrows,
            'gridcolumns' => (int)$group->gridcolumns
        ],
        'ads' => $processed_ads
    ];
}

/**
 * Extracts fields and metadata from a Contact Form 7 form.
 * Optimized for React/Headless consumption.
 */
function newsroom_get_cf7_form_data($id) {
    $id = (int)$id;
    if ($id <= 0) return null;

    $fields = [];
    $title = get_the_title($id);
    
    // Attempt to use WPCF7 internal scanner if plugin is active
    if (class_exists('WPCF7_ContactForm')) {
        $form = WPCF7_ContactForm::get_instance($id);
        if ($form) {
            $tags = $form->scan_form_tags();
            foreach ($tags as $tag) {
                if (empty($tag->name)) continue;
                $fields[] = [
                    'type' => $tag->type,
                    'name' => $tag->name,
                    'raw_type' => $tag->basetype,
                    'required' => $tag->is_required(),
                    'values' => $tag->values,
                    'labels' => $tag->labels,
                    'placeholder' => $tag->get_option('placeholder', '', true),
                    'default' => $tag->get_default_option(),
                    'className' => $tag->get_class_option(),
                ];
            }
            return [
                'id' => $id,
                'title' => $title,
                'fields' => $fields,
                'submit_text' => 'Submit'
            ];
        }
    }

    // Fallback: Parse from post_content if CF7 class is not accessible or failed
    $content = get_post_field('post_content', $id);
    if (empty($content)) return null;

    // Very basic regex to pick up [type name "placeholder"] or [type* name]
    // Note: This is a fallback and less accurate than the plugin itself
    preg_match_all('/\[([a-zA-Z\*\-]+)\s+([a-zA-Z0-9\-_]+)(\s+.*?)?\]/', $content, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $type = $match[1];
        $name = $match[2];
        if ($type === 'submit') continue;
        
        $fields[] = [
            'type' => $type,
            'name' => $name,
            'required' => (strpos($type, '*') !== false),
            'placeholder' => preg_match('/placeholder\s+"([^"]+)"/', $match[3] ?? '', $p) ? $p[1] : ''
        ];
    }

    return [
        'id' => $id,
        'title' => $title,
        'fields' => $fields,
        'submit_text' => 'Submit',
        'is_fallback' => true
    ];
}

/**
 * Reads Better Ads Manager plugin settings for Post Content Ads.
 * Positions: above_post_content, inside_post_content, middle_post_content, below_post_content
 * Resolves any AdRotate group/ad IDs referenced in those positions.
 */
function newsroom_get_better_ads_post_data() {
    // Better Ads Manager stores settings under these possible option keys
    $possible_keys = [
        'jesuspended_post_ads',
        'jesuspended_ads_options',
        'jeuspended_post_ads',
        'jeuspended_options',
        'better_ads_post',
        'jeuspended_settings',
    ];

    $settings = null;
    foreach ($possible_keys as $key) {
        $opt = get_option($key);
        if (!empty($opt)) {
            $settings = $opt;
            break;
        }
    }

    // Fallback: scan all options matching the plugin prefix
    if (empty($settings)) {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
             WHERE option_name LIKE '%jeuspended%' 
             OR option_name LIKE '%jesspended%'
             OR option_name LIKE '%better_ads%'
             OR option_name LIKE '%jeuspended%'
             LIMIT 20"
        );
        
        foreach ($rows as $row) {
            $val = maybe_unserialize($row->option_value);
            if (is_array($val) && (
                isset($val['above_post_content']) || 
                isset($val['below_post_content']) ||
                isset($val['post_ads'])
            )) {
                $settings = $val;
                break;
            }
        }
    }

    // Define the ad positions we want to resolve
    $positions = ['above_post_content', 'inside_post_content', 'middle_post_content', 'below_post_content'];
    $result = [];

    if (!empty($settings) && is_array($settings)) {
        // Handle nested structure: settings might have ads under a 'post_ads' sub-key
        $ad_settings = $settings['post_ads'] ?? $settings;

        foreach ($positions as $position) {
            $ad_config = $ad_settings[$position] ?? null;
            
            if (!empty($ad_config)) {
                // Extract the AdRotate group/ad ID
                $ad_id = 0;
                if (is_array($ad_config)) {
                    $ad_id = (int)($ad_config['ad_id'] ?? $ad_config['group_id'] ?? $ad_config['id'] ?? 0);
                    
                    // Check for 'adrotate' type entries
                    if (empty($ad_id) && isset($ad_config['type']) && $ad_config['type'] === 'banner') {
                        $ad_id = (int)($ad_config['data'] ?? $ad_config['banner_id'] ?? 0);
                    }
                } elseif (is_numeric($ad_config)) {
                    $ad_id = (int)$ad_config;
                }

                if ($ad_id > 0) {
                    $result[$position] = newsroom_get_full_ad_data($ad_id);
                } else {
                    $result[$position] = null;
                }
            } else {
                $result[$position] = null;
            }
        }
    }

    // If plugin settings weren't found, try reading AdRotate groups by name matching
    if (empty($result)) {
        global $wpdb;
        $table_groups = $wpdb->prefix . 'adrotate_groups';
        
        $name_map = [
            'above_post_content' => ['Above Article', 'Above Post', 'above_post'],
            'below_post_content' => ['Below Article', 'Below Post', 'below_post'],
            'middle_post_content' => ['Middle Post', 'Middle Article', 'middle_post'],
        ];

        foreach ($name_map as $position => $search_names) {
            foreach ($search_names as $name) {
                $group = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$table_groups} WHERE name LIKE %s LIMIT 1",
                    '%' . $wpdb->esc_like($name) . '%'
                ));
                if ($group) {
                    $result[$position] = newsroom_get_full_ad_data((int)$group->id);
                    break;
                }
            }
            if (!isset($result[$position])) {
                $result[$position] = null;
            }
        }
    }

    return $result;
}


/**
 * Handle subscription request
 */
function hjs_handle_subscription( WP_REST_Request $request ) {
  
    // Get email
    $email = sanitize_email( $request->get_param( 'email' ) );

    if ( empty( $email ) || ! is_email( $email ) ) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Invalid email address.',
            ),
            400
        );
    }
  

    // Ensure Jetpack is active
    if ( ! class_exists( 'Jetpack_Subscriptions' ) ) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Jetpack Subscriptions not available.',
            ),
            500
        );
    }
   

    // Basic rate limiting (per IP, 1 request per 30 seconds)
    $ip = $_SERVER['REMOTE_ADDR'];
    $transient_key = 'hjs_rate_' . md5( $ip );

    if ( get_transient( $transient_key ) ) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Too many requests. Please wait.',
            ),
            429
        );
    }

    set_transient( $transient_key, true, 30 );

    try {

        // Subscribe user (non-static — must use instance)
        $subs = Jetpack_Subscriptions::init();
        $result = $subs->subscribe( $email, 0, false );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                ),
                400
            );
        }

        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => 'Please check your email to confirm subscription.',
            ),
            200
        );

    } catch ( Exception $e ) {

        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Subscription failed.',
            ),
            500
        );
    }
}