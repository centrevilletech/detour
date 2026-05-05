<?php
/**
 * Plugin Name: Detour
 * Plugin URI:  https://github.com/centrevilletech/detour
 * Description: Catch every 404 and route lost visitors to a destination that converts. Per-URL redirect rules with 301/302/307/308/410 support, sortable hit history, weekly email digests, and an indexed schema engineered for high-traffic sites.
 * Version:     1.1.0
 * Author:      Centreville Tech LLC
 * Author URI:  https://centrevilletech.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: detour
 *
 * Detour is a fork of "All 404 Redirect to Homepage" by wp-buy
 * (https://wordpress.org/plugins/all-404-redirect-to-homepage/), released under
 * GPL-2.0-or-later. See README.md for the full attribution and a list of changes.
 */
register_activation_hook(__FILE__, 'p404_modify_htaccess');
register_activation_hook(__FILE__, 'centct_p404_ensure_schema');
register_deactivation_hook(__FILE__, 'p404_clear_htaccess');

if (! defined('ABSPATH')) exit;
if (! defined('WP_CONTENT_DIR')) exit;

define('OPTIONS404', 'options-404-redirect-group');
add_action('plugins_loaded', 'p404_check_and_upgrade_database');
add_action('plugins_loaded', 'centct_p404_ensure_schema');

require_once('centct-fixes.php');
require_once('centct-rules.php');
require_once('functions.php');
add_action('admin_menu', 'p404_admin_menu');
add_action('admin_head', 'p404_header_code');
add_action('wp', 'p404_redirect');
add_action('admin_enqueue_scripts', 'p404_enqueue_styles_scripts');

add_action('admin_bar_menu', 'p404_free_add_items',  40);
add_action('wp_enqueue_scripts', 'p404_redirect_top_bar_enqueue_style');
add_action('admin_enqueue_scripts', 'p404_redirect_top_bar_enqueue_style');





// Number formatting function
function p404_number_format($number)
{
    if ($number >= 1000000) {
        return round($number / 1000000, 1) . 'M';
    } elseif ($number >= 1000) {
        return round($number / 1000, 1) . 'K';
    }
    return number_format($number);
}

function p404_free_add_items($admin_bar)
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // Check if we have enough redirects to show stats
    $links_count = P404REDIRECT_read_option_value('links', 0);
    if ($links_count < 10) {
        // Keep original simple button
        $args = array(
            'id'    => 'p404_free_top_button',
            'parent' => null,
            'group'  => null,
            'title' => '<span class="ab-icon"></span>404 Stats',
            'href'  => admin_url('admin.php?page=detour.php&mytab=404urls'),
            'meta'  => array(
                'title' => '404 Links',
                'class' => ''
            )
        );
        $admin_bar->add_menu($args);
        return;
    }

    $redirect_stats = p404_get_redirect_statistics();

    // Calculate percentage change from yesterday to today
    $today_count = $redirect_stats['today'];
    $yesterday_count = $redirect_stats['yesterday'];

    $percentage_change = '';
    $change_arrow = '';
    $change_color = '';

    if ($today_count != $yesterday_count) {
        if ($yesterday_count > 0) {
            // Normal percentage calculation
            $change_percent = (($today_count - $yesterday_count) / $yesterday_count) * 100;

            if ($today_count < $yesterday_count) {
                // Decreased
                $percentage_change = abs(round($change_percent, 1)) . '%';
                $change_arrow = '↓';
                $change_color = '#28a745'; // Green for decrease (good thing for 404s)
            } else {
                // Increased
                $percentage_change = abs(round($change_percent, 1)) . '%';
                $change_arrow = '↑';
                $change_color = '#dc3545'; // Red for increase (bad thing for 404s)
            }
        } else if ($yesterday_count == 0 && $today_count > 0) {
            // New redirects when yesterday was 0
            $percentage_change = 'new';
            $change_arrow = '↑';
            $change_color = '#dc3545'; // Red for increase (bad thing for 404s)
        } else if ($yesterday_count > 0 && $today_count == 0) {
            // Went to zero from yesterday
            $percentage_change = '100%';
            $change_arrow = '↓';
            $change_color = '#28a745'; // Green for decrease (good thing for 404s)
        }
    }

    $chart_html = '';

    // Build Today card with percentage change
    $today_card_content = '<div style="color: #aaa; font-size: 12px; font-weight: 500; margin-bottom: 4px;">' . __('Today', 'detour') . '</div>';

    // Apply color to the number if there's a change
    $number_color = '#fff'; // default white
    if ($percentage_change) {
        $number_color = $change_color; // green if decreased, red if increased
    }

    $today_card_content .= '<div style="color: ' . $number_color . '; font-size: 24px; font-weight: 700; line-height: 1;">' . p404_number_format($redirect_stats['today']) . '</div>';

    if ($percentage_change) {
        $change_text = ($today_count < $yesterday_count) ? 'decreased' : 'increased';
        $today_card_content .= '<div style="color: ' . $change_color . '; font-size: 11px; font-weight: 600; margin-top: 4px;">'
            . '<span style="font-size: 12px;">' . $change_arrow . '</span> ' . $change_text . ' ' . $percentage_change . ' from yesterday'
            . '</div>';
    } else {
        $today_card_content .= '<div style="color: #666; font-size: 13px; margin-top: 4px;">' . __('redirects', 'detour') . '</div>';
    }

    // Build dropdown HTML with statistics cards - using unique CSS classes
    $dropdown_html = '<div class="p404-redirect-dropdown-container" style="background: #32373c; border: 1px solid #444; border-radius: 8px; padding: 15px; width: 360px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">'
        . '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">'

        // Today card (enhanced with percentage)
        . '<div class="p404-redirect-card" style="background: #23282d; border-radius: 6px; padding: 15px; border: 1px solid #444;">'
        . $today_card_content
        . '</div>'

        // Yesterday card
        . '<div class="p404-redirect-card" style="background: #23282d; border-radius: 6px; padding: 15px; border: 1px solid #444;">'
        . '<div style="color: #aaa; font-size: 12px; font-weight: 500; margin-bottom: 4px;">' . __('Yesterday', 'detour') . '</div>'
        . '<div style="color: #fff; font-size: 24px; font-weight: 700; line-height: 1;">' . p404_number_format($redirect_stats['yesterday']) . '</div>'
        . '<div style="color: #666; font-size: 13px; margin-top: 4px;">' . __('redirects', 'detour') . '</div>'
        . '</div>'

        // This Month card
        . '<div class="p404-redirect-card" style="background: #23282d; border-radius: 6px; padding: 15px; border: 1px solid #444;">'
        . '<div style="color: #aaa; font-size: 12px; font-weight: 500; margin-bottom: 4px;">' . __('This Month', 'detour') . '</div>'
        . '<div style="color: #fff; font-size: 24px; font-weight: 700; line-height: 1;">' . p404_number_format($redirect_stats['month']) . '</div>'
        . '<div style="color: #666; font-size: 13px; margin-top: 4px;">' . date('F Y') . '</div>'
        . '</div>'

        // Total card
        . '<div class="p404-redirect-card" style="background: #23282d; border-radius: 6px; padding: 15px; border: 1px solid #444;">'
        . '<div style="color: #aaa; font-size: 12px; font-weight: 500; margin-bottom: 4px;">' . __('Total', 'detour') . '</div>'
        . '<div style="color: #fff; font-size: 24px; font-weight: 700; line-height: 1;">' . p404_number_format($redirect_stats['total']) . '</div>'
        . '<div style="color: #666; font-size: 13px; margin-top: 4px;">' . __('All Time', 'detour') . '</div>'
        . '</div>'

        . '</div>' . $chart_html

        // View All button
        . '<div style="text-align: center; margin-top: 10px;">'
        . '<a href="' . esc_url(admin_url('admin.php?page=detour.php&mytab=404urls')) . '" class="p404-redirect-btn" style="background: #dc3545; color: #fff; padding: 8px 18px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-block; transition: background 0.2s;" onmouseover="this.style.background=\'#c82333\'" onmouseout="this.style.background=\'#dc3545\'">'
        . __('View All 404s', 'detour') . '</a>'
        . '</div>'
        . '</div>';

    // Menu title
    $menu_title = '<span class="ab-icon"></span> ' . __('404 Stats', 'detour');

    $admin_bar->add_menu(array(
        'id'    => 'p404_free_top_button',
        'title' => $menu_title,
        'href'  => false,
        'meta'  => array(
            'title' => __('404 Redirect Statistics', 'detour'),
            'class' => 'p404-redirect-admin-bar',
        )
    ));

    $admin_bar->add_menu(array(
        'id'     => 'p404_free_top_button_dropdown',
        'parent' => 'p404_free_top_button',
        'title'  => $dropdown_html,
        'href'   => false,
        'meta'   => array('class' => 'p404-redirect-adminbar-dropdown-wrap')
    ));

}

// Updated function to get correct redirect statistics
function p404_get_redirect_statistics()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'redirects_404';

    // Get total redirects by summing all count values
    $total_redirects = $wpdb->get_var("SELECT SUM(count) FROM {$table_name}");
    $total_redirects = $total_redirects ? intval($total_redirects) : 0;

    // Get today's redirects (sum of counts for URLs redirected today)
    $today_redirects = $wpdb->get_var($wpdb->prepare("
        SELECT SUM(count) 
        FROM {$table_name} 
        WHERE DATE(last_redirected) = %s
    ", date('Y-m-d')));
    $today_redirects = $today_redirects ? intval($today_redirects) : 0;

    // Get yesterday's redirects
    $yesterday_redirects = $wpdb->get_var($wpdb->prepare("
        SELECT SUM(count) 
        FROM {$table_name} 
        WHERE DATE(last_redirected) = %s
    ", date('Y-m-d', strtotime('-1 day'))));
    $yesterday_redirects = $yesterday_redirects ? intval($yesterday_redirects) : 0;

    // Get this month's redirects
    $month_redirects = $wpdb->get_var($wpdb->prepare("
        SELECT SUM(count) 
        FROM {$table_name} 
        WHERE YEAR(last_redirected) = %d AND MONTH(last_redirected) = %d
    ", date('Y'), date('n')));
    $month_redirects = $month_redirects ? intval($month_redirects) : 0;

    return array(
        'total' => $total_redirects,
        'today' => $today_redirects,
        'yesterday' => $yesterday_redirects,
        'month' => $month_redirects
    );
}




// Updated CSS function with unique class names to prevent conflicts
function p404_redirect_top_bar_enqueue_style()
{
    wp_enqueue_style('admin-bar');
    $custom_css = '
    .p404-redirect-adminbar-weekly-title {
        font-weight: bold;
        font-size: 14px;
        color: #fff;
        margin-bottom: 6px;
    }

    #wpadminbar #wp-admin-bar-p404_free_top_button .ab-icon:before {
        content: "\f103";
        color: #dc3545;
        top: 3px;
    }
    
    #wp-admin-bar-p404_free_top_button .ab-item {
        min-width: 80px !important;
        padding: 0px !important;
    }
    
    /* Ensure proper positioning and z-index for P404 dropdown */
    .p404-redirect-adminbar-dropdown-wrap { 
        min-width: 0; 
        padding: 0;
        position: static !important;
    }
    
    #wpadminbar #wp-admin-bar-p404_free_top_button_dropdown {
        position: static !important;
    }
    
    #wpadminbar #wp-admin-bar-p404_free_top_button_dropdown .ab-item {
        padding: 0 !important;
        margin: 0 !important;
    }
    
    .p404-redirect-dropdown-container {
        min-width: 340px;
        padding: 18px 18px 12px 18px;
        background: #23282d !important;
        color: #fff;
        border-radius: 12px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.25);
        margin-top: 10px;
        position: relative !important;
        z-index: 999999 !important;
        display: block !important;
        border: 1px solid #444;
    }
    
    /* Ensure P404 dropdown appears on hover */
    #wpadminbar #wp-admin-bar-p404_free_top_button .p404-redirect-dropdown-container { 
        display: none !important;
    }
    
    #wpadminbar #wp-admin-bar-p404_free_top_button:hover .p404-redirect-dropdown-container { 
        display: block !important;
    }
    
    #wpadminbar #wp-admin-bar-p404_free_top_button:hover #wp-admin-bar-p404_free_top_button_dropdown .p404-redirect-dropdown-container {
        display: block !important;
    }
    
    .p404-redirect-card {
        background: #2c3338;
        border-radius: 8px;
        padding: 18px 18px 12px 18px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        border: 1px solid #444;
    }
    
    .p404-redirect-btn {
        display: inline-block;
        background: #dc3545;
        color: #fff !important;
        font-weight: bold;
        padding: 5px 22px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 17px;
        transition: background 0.2s, box-shadow 0.2s;
        margin-top: 8px;
        box-shadow: 0 2px 8px rgba(220,53,69,0.15);
        text-align: center;
        line-height: 1.6;
    }
    
    .p404-redirect-btn:hover {
        background: #c82333;
        color: #fff !important;
        box-shadow: 0 4px 16px rgba(220,53,69,0.25);
    }
    
    /* Prevent conflicts with other admin bar dropdowns */
    #wpadminbar .ab-top-menu > li:hover > .ab-item,
    #wpadminbar .ab-top-menu > li.hover > .ab-item {
        z-index: auto;
    }
    
    #wpadminbar #wp-admin-bar-p404_free_top_button:hover > .ab-item {
        z-index: 999998 !important;
    }
    ';
    wp_add_inline_style('admin-bar', $custom_css);
}

function P404REDIRECT__filter_action_links($links)
{
    $links['settings'] = sprintf('<a href="%s">Settings</a>', admin_url('admin.php?page=detour.php'));
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'P404REDIRECT__filter_action_links', 10, 1);



function p404_redirect()
{
    if (!is_404()) {
        return;
    }

    $options = P404REDIRECT_get_my_options();
    $link    = P404REDIRECT_get_current_URL();

    // Per-URL rules win over the global default. A rule may apply 410 Gone
    // (no Location header) or any of 301/302/307/308.
    if (function_exists('centct_p404_match_rule')) {
        $rule = centct_p404_match_rule($link);
        if ($rule) {
            P404REDIRECT_add_redirected_link($link);
            centct_p404_apply_rule($rule, $link);
            // apply_rule exits on success; if it returned, the rule was
            // misconfigured (e.g. empty destination) and we fall through.
        }
    }

    // Prevent infinite redirection loops on the global default.
    if ($link == $options['p404_redirect_to']) {
        echo "<b>Detour</b> detected that the redirect target equals the requested URL. This would create an infinite loop. Please update the target URL in the plugin settings.";
        exit();
    }

    if ($options['p404_status'] != '1' || $options['p404_redirect_to'] == '') {
        return;
    }

    $redirect_type = isset($options['p404_redirect_type']) ? intval($options['p404_redirect_type']) : 301;
    $is_media_link = (strpos($link, '/wp-content') !== false);
    $exclude_media = (isset($options['p404_execlude_media']) && $options['p404_execlude_media'] == '1');

    if (!$exclude_media || !$is_media_link) {
        P404REDIRECT_add_redirected_link($link);
    }

    wp_redirect($options['p404_redirect_to'], $redirect_type);
    exit();
}

//---------------------------------------------------------------

function p404_get_site_404_page_path()
{
    $url = str_ireplace("://", "", site_url());
    $site_404_page = substr($url, stripos($url, "/"));
    if (stripos($url, "/") === FALSE || $site_404_page == "/")
        $site_404_page = "/index.php?error=404";
    else
        $site_404_page = $site_404_page . "/index.php?error=404";
    return $site_404_page;
}
//---------------------------------------------------------------

function p404_check_default_permalink()
{
    // Only verify once per day - this previously ran on every admin pageview,
    // re-reading .htaccess each time. The transient bust below cheaply caps it.
    if (get_transient('centct_p404_htaccess_verified')) {
        return;
    }
    set_transient('centct_p404_htaccess_verified', 1, DAY_IN_SECONDS);

    $file = get_home_path() . "/.htaccess";
    if (!file_exists($file)) {
        insert_with_markers($file, 'FRedirect_ErrorDocument', 'ErrorDocument 404 ' . p404_get_site_404_page_path());
        return;
    }
    $contents = @file_get_contents($file);
    if ($contents !== false && strpos($contents, 'FRedirect_ErrorDocument') === false) {
        insert_with_markers($file, 'FRedirect_ErrorDocument', 'ErrorDocument 404 ' . p404_get_site_404_page_path());
    }
}


//---------------------------------------------------------------

function p404_header_code()
{
    p404_check_default_permalink();
}


function p404_enqueue_styles_scripts()
{
    // Check if we are in the admin area and on the plugin's page
    if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'detour.php') {
        $css = plugins_url() . '/' . basename(dirname(__FILE__)) . "/stylesheet.css";
        wp_enqueue_style('main-404-css', $css);
    }
}

function p404_admin_menu()
{
    add_options_page('Detour', 'Detour', 'manage_options', basename(__FILE__), 'p404_options_menu');
}

function p404_options_menu()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    include "option_page.php";
}

add_action('admin_enqueue_scripts', 'p404_include_js');

function p404_include_js()
{

    $mypage = isset($_GET['page']) ? $_GET['page'] : '';

    if ($mypage == 'detour.php') {
        if (!did_action('wp_enqueue_media')) {
            wp_enqueue_media();
        }


        wp_enqueue_script('myuploadscript', plugin_dir_url(__FILE__) . '/js/custom.js', array('jquery'));
    }
}



function p404_modify_htaccess()
{
    $options = P404REDIRECT_get_my_options();
    if (isset($options['img_p404_status']) && $options['img_p404_status'] == 1) {
        $image_id = isset($options['image_id_p404_redirect_to']) ? absint($options['image_id_p404_redirect_to']) : '';
        if ($image_id != '') {
            // Check if the image actually exists in the media library
            $image_data = wp_get_attachment_image_src($image_id);

            if ($image_data && !empty($image_data[0])) {
                $image = $image_data[0];
                $ruls[] = <<<EOT
    RewriteOptions inherit
    <IfModule mod_rewrite.c>
    RewriteEngine on
    RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI} !-f
    RewriteRule \.(gif|jpe?g|png|bmp) $image [NC,L]
    </IfModule>
    EOT;
                //NC (no case, case insensitive, useless in this context) and L (last rule if applied)
                return p404_add_htaccess($ruls);
            } else {
                // Image doesn't exist, clear the option
                $options['image_id_p404_redirect_to'] = '';
                update_option('p404_redirect_options', $options);

                return array(
                    'status' => false,
                    'message' => 'Selected image no longer exists. Please choose a new image.'
                );
            }
        }
    }

    return array('status' => false, 'message' => 'No valid image selected');
}

//echo WP_CONTENT_DIR;echo"<br>";
//echo ABSPATH;exit;
function p404_add_htaccess($insertion)
{
    //Clear the old htaccess file located inside the main website directory
    $htaccess_file = WP_CONTENT_DIR . '/.htaccess';
    $filename = $htaccess_file;
    if (!file_exists($filename)) {
        touch($filename);
    }
    if (is_writable($filename)) {
        return array('status' => true, 'massage' => p404_insert_with_markers_htaccess($htaccess_file, 'All_404_marker_comment_image', (array) $insertion));
    } else {
        return array('status' => false, 'massage' => $insertion);
    }
}

function p404_clear_htaccess()
{
    $htaccess_file = WP_CONTENT_DIR . '/.htaccess';

    p404_insert_with_markers_htaccess($htaccess_file, 'All_404_marker_comment_image', "");
}

function p404_insert_with_markers_htaccess($filename, $marker, $insertion)
{
    if (!file_exists($filename) || is_writeable($filename)) {
        if (!file_exists($filename)) {
            $markerdata = '';
        } else {
            $markerdata = explode("\n", implode('', file($filename)));
        }

        if (!$f = @fopen($filename, 'w'))
            return false;

        $foundit = false;
        if ($markerdata) {
            $state = true;
            foreach ($markerdata as $n => $markerline) {
                if (strpos($markerline, '# BEGIN ' . $marker) !== false)
                    $state = false;
                if ($state) {
                    if ($n + 1 < count($markerdata))
                        fwrite($f, "{$markerline}\n");
                    else
                        fwrite($f, "{$markerline}");
                }
                if (strpos($markerline, '# END ' . $marker) !== false) {
                    fwrite($f, "# BEGIN {$marker}\n");
                    if (is_array($insertion))
                        foreach ($insertion as $insertline)
                            fwrite($f, "{$insertline}\n");
                    fwrite($f, "# END {$marker}\n");
                    $state = true;
                    $foundit = true;
                }
            }
        }
        if (!$foundit) {
            fwrite($f, "\n# BEGIN {$marker}\n");
            if (is_array($insertion))
                foreach ($insertion as $insertline)
                    fwrite($f, "{$insertline}\n");
            fwrite($f, "# END {$marker}\n");
        }
        fclose($f);
        return true;
    } else {
        return false;
    }
}
