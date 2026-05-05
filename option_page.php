<?php

if (! defined('ABSPATH')) exit; // Exit if accessed directly

include_once "cf_dropdown.php";

global $wpdb, $table_prefix;

$redirect_to = (isset($_POST['redirect_to'])) ? sanitize_text_field($_POST['redirect_to']) : '';
$nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field($_REQUEST['_wpnonce']) : '';
if (isset($_GET['page']) && $_GET['page'] == 'detour.php') {
    detour_cleanup_image_options();
}
if ($redirect_to !== '') {
    if (wp_verify_nonce($nonce, 'p404home_nounce')) {
        Detour_save_option_value('p404_execlude_media', sanitize_text_field($_POST['p404_execlude_media']));
        Detour_save_option_value('p404_redirect_to', $redirect_to);
        Detour_save_option_value('p404_status', sanitize_text_field($_POST['p404_status']));
        Detour_save_option_value('img_p404_status', sanitize_text_field($_POST['img_p404_status']));
        Detour_save_option_value('image_id_p404_redirect_to', sanitize_text_field($_POST['misha-img']));
        Detour_save_option_value('p404_redirect_type', sanitize_text_field($_POST['p404_redirect_type']));
        Detour_save_option_value('email_notifications_enabled', sanitize_text_field($_POST['email_notifications_enabled']));
        Detour_save_option_value('email_frequency', sanitize_text_field($_POST['email_frequency']));
        Detour_save_option_value('notification_email', sanitize_email($_POST['notification_email']));
        detour_update_email_schedules();
        if (isset($_POST['img_p404_status']) && $_POST['img_p404_status'] == 1) {
            if (isset($_POST['misha-img']) && $_POST['misha-img'] != '') {
                // Validate that the uploaded image exists
                $image_id = absint($_POST['misha-img']);
                $image_data = wp_get_attachment_image_src($image_id);

                if ($image_data && !empty($image_data[0])) {
                    $mod_file = detour_modify_htaccess();
                    if ($mod_file['status']) {
                        // Show success modal
?>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                showSuccessModal();
                            });
                        </script>
                    <?php
                    } else {
                        // Show htaccess modal
                    ?>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                showHtaccessModal();
                            });
                        </script>
                    <?php
                    }
                } else {
                    // Image doesn't exist, show error
                    ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            showErrorModal();
                        });
                    </script>
                <?php
                }
            } else {
                // Show warning modal
                ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        showWarningModal();
                    });
                </script>
            <?php
            }
        } else {
            detour_clear_htaccess();
            // Show success modal
            ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showSuccessModal();
                });
            </script>
        <?php
        }
    } else {
        // Show error modal instead of inline notice
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showErrorModal();
            });
        </script>
<?php
    }
}

// Function to get redirects data with pagination, sorting, and search - WordPress style
function get_redirects_data($page = 1, $per_page = 10, $order_by = 'last_redirected', $order_dir = 'DESC', $search = '')
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'redirects_404';
    $offset = ($page - 1) * $per_page;

    // Base query
    $where_clause = "WHERE 1=1";
    $params = array();

    // Add search filter
    if (!empty($search)) {
        $where_clause .= " AND url LIKE %s";
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }

    // Validate order by column - WordPress style column names
    $allowed_columns = ['last_redirected', 'url', 'count'];
    if (!in_array($order_by, $allowed_columns)) {
        $order_by = 'last_redirected';
    }

    // Validate order direction
    $order_dir = strtoupper($order_dir);
    if (!in_array($order_dir, ['ASC', 'DESC'])) {
        $order_dir = 'DESC';
    }

    // Get total count for pagination
    $total_query = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";
    if (!empty($params)) {
        $total_items = $wpdb->get_var($wpdb->prepare($total_query, $params));
    } else {
        $total_items = $wpdb->get_var($total_query);
    }

    // Get paginated results
    $query = "SELECT * FROM {$table_name} {$where_clause} ORDER BY {$order_by} {$order_dir} LIMIT %d OFFSET %d";
    $query_params = array_merge($params, [$per_page, $offset]);

    $results = $wpdb->get_results($wpdb->prepare($query, $query_params), ARRAY_A);

    return array(
        'data' => $results,
        'total' => (int)$total_items,
        'pages' => ceil($total_items / $per_page),
        'current_page' => $page
    );
}

// Function to build WordPress-style column sort URL
function get_column_sort_url($column, $current_order_by, $current_order_dir)
{
    $new_order_dir = 'ASC';

    // If clicking the same column, toggle direction
    if ($current_order_by === $column) {
        $new_order_dir = ($current_order_dir === 'ASC') ? 'DESC' : 'ASC';
    }

    $current_url = remove_query_arg(['orderby', 'order'], $_SERVER['REQUEST_URI']);
    $sort_url = add_query_arg([
        'orderby' => $column,
        'order' => strtolower($new_order_dir),
        'paged' => 1 // Reset to first page when sorting
    ], $current_url);

    return $sort_url;
}

$options = Detour_get_my_options();
function detour_cleanup_image_options()
{
    $options = Detour_get_my_options();
    $image_id = isset($options['image_id_p404_redirect_to']) ? absint($options['image_id_p404_redirect_to']) : '';

    if ($image_id != '') {
        $image_data = wp_get_attachment_image_src($image_id);
        if (!$image_data || empty($image_data[0])) {
            // Image doesn't exist, clear the option
            $options['image_id_p404_redirect_to'] = '';
            update_option('p404_redirect_options', $options);
            return false;
        }
    }

    return true;
}
?>


<?php
if (Detour_there_is_cache() != '') {
    echo '<div class="notice notice-info">';
    echo '<p><strong>Cache Plugin Detected:</strong> You have a cache plugin installed (<strong>' . Detour_there_is_cache() . '</strong>). Please clear your cache after making changes for them to take effect immediately.</p>';
    echo '</div>';
}

// Get the current email options (add defaults if they don't exist)
$email_enabled = Detour_read_option_value('email_notifications_enabled', '1'); // Default: enabled
$email_frequency = Detour_read_option_value('email_frequency', 'weekly'); // Default: Weekly
$notification_email = Detour_read_option_value('notification_email', get_option('admin_email')); // Default: Admin email

?>

<div class="wrap p404-admin-wrap">
    <div class="p404-header">
        <h1>Detour</h1>
        <p>Manage your 404 error redirections and broken image handling with professional-grade controls</p>
    </div>

    <?php
    $mytab = isset($_REQUEST['mytab']) ? sanitize_text_field($_REQUEST['mytab']) : "options";
    ?>

    <nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
        <a href="?page=detour.php&mytab=options" class="nav-tab <?php if ($mytab == 'options') echo 'nav-tab-active'; ?>">
            <span class="dashicons dashicons-admin-settings"></span> Options
        </a>
        <a href="?page=detour.php&mytab=rules" class="nav-tab <?php if ($mytab == 'rules') echo 'nav-tab-active'; ?>">
            <span class="dashicons dashicons-randomize"></span> Rules
        </a>
        <a href="?page=detour.php&mytab=404urls" class="nav-tab <?php if ($mytab == '404urls') echo 'nav-tab-active'; ?>">
            <span class="dashicons dashicons-list-view"></span> 404 URLs
        </a>
    </nav>

    <div id="tabs_content">
        <?php if ($mytab == "options") { ?>
            <form method="POST" class="p404-options-form">
                <div class="form-section">
                    <h3><span class="dashicons dashicons-admin-page"></span> 404 Pages Settings</h3>
                    <div class="form-content">
                        <div class="form-group">
                            <label class="form-label">404 Redirection Status:</label>
                            <div class="form-control">
                                <?php
                                $drop = new Detour_Dropdown('p404_status');
                                $drop->add('Enabled', '1');
                                $drop->add('Disabled', '2');
                                $drop->dropdown_print();
                                $drop->select($options['p404_status']);
                                ?>
                                <div class="help-text">Enable or disable 404 page redirections to improve user experience</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Redirect all 404 pages to:</label>
                            <div class="form-control">
                                <input type="text" name="redirect_to" id="redirect_to"
                                    value="<?php echo esc_attr($options['p404_redirect_to']); ?>"
                                    placeholder="https://example.com">
                                <div class="help-text">Enter the destination URL where visitors should be redirected when they encounter 404 errors</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Select Redirect Type:</label>
                            <div class="form-control">
                                <?php
                                $drop = new Detour_Dropdown('p404_redirect_type');
                                $drop->add('301 Permanent Redirect', '301');
                                $drop->add('302 Temporary Redirect', '302');
                                $drop->dropdown_print();
                                $redirect_type = isset($options['p404_redirect_type']) ? $options['p404_redirect_type'] : '301';
                                $drop->select($redirect_type);
                                ?>
                                <div class="help-text">301 redirects are recommended for SEO as they transfer link equity to the destination page</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Prevent logging media links:</label>
                            <div class="form-control">
                                <?php
                                $drop = new Detour_Dropdown('p404_execlude_media');
                                $drop->add('Yes', '1');
                                $drop->add('No', '2');
                                $drop->dropdown_print();
                                $detour_execlude_media_val = isset($options['p404_execlude_media']) ? $options['p404_execlude_media'] : '1';
                                $drop->select($detour_execlude_media_val);
                                ?>
                                <div class="help-text">Exclude media files (images, videos, etc.) from 404 logging to keep your database clean and efficient</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><span class="dashicons dashicons-format-image"></span> 404 Images Settings</h3>
                    <div class="form-content">
                        <div class="form-group">
                            <label class="form-label">Image 404 Redirection Status:</label>
                            <div class="form-control">
                                <?php
                                $drop = new Detour_Dropdown('img_p404_status');
                                $drop->add('Enabled', '1');
                                $drop->add('Disabled', '2');
                                $drop->dropdown_print();
                                $image_status_val = isset($options['img_p404_status']) ? $options['img_p404_status'] : '2';
                                $drop->select($image_status_val);
                                ?>
                                <div class="help-text">
                                    Enable to show a default image for broken images instead of broken image icons.
                                    <strong style="color: #dc2626;">For advanced features, try our
                                        <a href="https://wordpress.org/plugins/broken-images-redirection" target="_blank" style="color: #dc2626;">Broken Images Redirection</a>
                                        Plugin</strong>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Default Image for 404 errors:</label>
                            <div class="form-control">
                                <div class="image-upload-section">
                                    <?php
                                    $image_id = isset($options['image_id_p404_redirect_to']) ? absint($options['image_id_p404_redirect_to']) : '';
                                    if (wp_get_attachment_image_src($image_id) && $image_id != '') {
                                        $image = wp_get_attachment_image_src($image_id);
                                    ?>
                                        <div class="image-preview">
                                            <img src="<?php echo esc_url($image[0]); ?>" alt="404 Default Image" />
                                        </div>
                                        <a href="#" class="btn btn-primary misha-upl">
                                            <span class="dashicons dashicons-edit"></span> Change Image
                                        </a>
                                        <a href="#" class="btn btn-secondary misha-rmv">
                                            <span class="dashicons dashicons-no-alt"></span> Remove Image
                                        </a>
                                        <input type="hidden" class="misha-img" name="misha-img" value="<?php echo esc_attr($image_id); ?>">
                                    <?php } else { ?>
                                        <div class="upload-section-container">
                                            <div class="dashicons dashicons-format-image upload-icon"></div>
                                            <p style="margin: 0 0 15px 0; color: #6b7280;">No image selected</p>
                                            <a href="#" class="btn btn-primary misha-upl">
                                                <span class="dashicons dashicons-upload"></span> Upload Image
                                            </a>
                                            <input type="hidden" class="misha-img" name="misha-img" value="">
                                        </div>
                                    <?php } ?>
                                </div>
                                <div class="help-text">This image will be displayed whenever a requested image is not found on your website</div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
                // Get total redirects count for test mail condition
                $total_redirects = Detour_read_option_value('links', 0);
                ?>

                <div class="form-section">
                    <h3><span class="dashicons dashicons-email-alt"></span> Email Notifications Settings</h3>
                    <div class="form-content">
                        <div class="form-group">
                            <label class="form-label">Email Notifications:</label>
                            <div class="form-control">
                                <?php
                                $drop = new Detour_Dropdown('email_notifications_enabled');
                                $drop->add('Enabled', '1');
                                $drop->add('Disabled', '2');
                                $drop->dropdown_print();
                                $drop->select($email_enabled);
                                ?>
                                <div class="help-text">Enable to receive email summaries of 404 activity on your website</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email Frequency:</label>
                            <div class="form-control">
                                <?php
                                $drop = new Detour_Dropdown('email_frequency');
                                $drop->add('Daily', 'daily');
                                $drop->add('Weekly', 'weekly');
                                $drop->add('Monthly', 'monthly');
                                $drop->dropdown_print();
                                $drop->select($email_frequency);
                                ?>
                                <div class="help-text">How often you want to receive email summaries (weekly is recommended)</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Notification Email:</label>
                            <div class="form-control">
                                <div style="display: flex; gap: 10px; align-items: flex-start;">
                                    <input type="email" name="notification_email" id="notification_email"
                                        value="<?php echo esc_attr($notification_email); ?>"
                                        placeholder="admin@yoursite.com"
                                        style="flex: 1;">

                                    <?php if ($total_redirects >= 5): ?>
                                        <button type="button" id="sendTestEmail" class="btn btn-secondary" style="white-space: nowrap; margin-left: 10px;">
                                            <span class="dashicons dashicons-email"></span> Send Test Email
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="help-text">
                                    Email address where 404 summaries will be sent (defaults to admin email)
                                    <?php if ($total_redirects < 5): ?>
                                        <br><span style="color: #f59e0b;">⚠️ Test email available after recording 5+ redirects (currently: <?php echo $total_redirects; ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($total_redirects >= 5): ?>
                            <!-- Test Email Status -->
                            <div class="form-group">
                                <label class="form-label">Test Email Status:</label>
                                <div class="form-control">
                                    <div id="testEmailStatus" style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 15px; display: none;">
                                        <div id="testEmailMessage"></div>
                                    </div>
                                    <div class="help-text">Click "Send Test Email" to preview how your 404 analytics report will look</div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">What you'll receive:</label>
                            <div class="form-control">
                                <div style="background: 
#f8fafc; border: 1px solid 
#e2e8f0; border-radius: 8px; padding: 15px;">
                                    <ul style="margin: 0; padding-left: 20px; color: 
#4b5563; line-height: 1.6;">
                                        <li>📊 Total 404 errors count</li>
                                        <li>🔗 Most frequent broken URLs</li>
                                        <li>📈 Trend compared to previous period</li>
                                        <li>🌐 Top referrers causing 404s</li>
                                        <li>💡 Recommendations to fix issues</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Enhanced JavaScript for Test Email -->
                <script>
                    jQuery(document).ready(function($) {
                        $('#sendTestEmail').click(function(e) {
                            e.preventDefault();

                            var button = $(this);
                            var originalText = button.html();
                            var emailAddress = $('#notification_email').val().trim();
                            var statusDiv = $('#testEmailStatus');
                            var messageDiv = $('#testEmailMessage');

                            // Validate email
                            if (!emailAddress) {
                                alert('Please enter an email address first.');
                                $('#notification_email').focus();
                                return;
                            }

                            if (!emailAddress.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                                alert('Please enter a valid email address.');
                                $('#notification_email').focus();
                                return;
                            }

                            // Show loading state
                            button.html('<span class="dashicons dashicons-update-alt" style="animation: rotation 1s infinite linear;"></span> Sending...').prop('disabled', true);
                            statusDiv.show();
                            messageDiv.html('<div style="color: #2563eb;"><span class="dashicons dashicons-email"></span> Preparing test email...</div>');

                            // Send AJAX request
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'send_test_404_email',
                                    email: emailAddress,
                                    nonce: '<?php echo wp_create_nonce("test_404_email_nonce"); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        messageDiv.html(
                                            '<div style="color: #059669;">' +
                                            '<span class="dashicons dashicons-yes-alt"></span> ' +
                                            '<strong>Test email sent successfully!</strong><br>' +
                                            '<span style="font-size: 12px; opacity: 0.8;">Check your inbox at ' + emailAddress + '</span>' +
                                            '</div>'
                                        );
                                        statusDiv.css('background', '#f0fdf4').css('border-color', '#bbf7d0');
                                    } else {
                                        messageDiv.html(
                                            '<div style="color: #dc2626;">' +
                                            '<span class="dashicons dashicons-dismiss"></span> ' +
                                            '<strong>Failed to send test email:</strong><br>' +
                                            '<span style="font-size: 12px;">' + (response.data || 'Unknown error occurred') + '</span>' +
                                            '</div>'
                                        );
                                        statusDiv.css('background', '#fef2f2').css('border-color', '#fecaca');
                                    }
                                },
                                error: function(xhr, status, error) {
                                    messageDiv.html(
                                        '<div style="color: #dc2626;">' +
                                        '<span class="dashicons dashicons-dismiss"></span> ' +
                                        '<strong>Connection error:</strong><br>' +
                                        '<span style="font-size: 12px;">Please check your internet connection and try again</span>' +
                                        '</div>'
                                    );
                                    statusDiv.css('background', '#fef2f2').css('border-color', '#fecaca');
                                },
                                complete: function() {
                                    // Restore button
                                    button.html(originalText).prop('disabled', false);

                                    // Auto-hide status after 5 seconds if successful
                                    setTimeout(function() {
                                        if (messageDiv.find('.dashicons-yes-alt').length > 0) {
                                            statusDiv.fadeOut();
                                        }
                                    }, 5000);
                                }
                            });
                        });
                    });
                </script>
                <div style="text-align: center; margin-top: 40px; padding-top: 30px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" id="_wpnonce" name="_wpnonce" value="<?php echo wp_create_nonce('p404home_nounce'); ?>" />
                    <button type="submit" class="btn btn-primary" name="Save_Options" style="font-size: 16px; padding: 16px 40px;">
                        <span class="dashicons dashicons-saved"></span> Update Options
                    </button>
                </div>
            </form>

        <?php } else if ($mytab == "404urls") {
            // Get parameters from URL for pagination, sorting, and search - WordPress style
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $per_page = 10; // WordPress standard: 10 items per page

            // WordPress-style parameters
            $order_by = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'last_redirected';
            $order_dir = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';
            $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

            // Get the paginated data
            $pagination_data = get_redirects_data($current_page, $per_page, $order_by, $order_dir, $search);
            $latest_redirects = $pagination_data['data'];

            global $wpdb;
            $table_name = $wpdb->prefix . 'redirects_404';
            $total_redirects = Detour_read_option_value('links', 0);

            // Get unique IPs count (last 30 days)
            $unique_ips = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT ip_address) 
        FROM {$table_name} 
        WHERE last_redirected >= %s
        AND ip_address IS NOT NULL 
        AND ip_address != 'unknown'
    ", date('Y-m-d H:i:s', strtotime('-30 days'))));

            // Get unique URLs count (last 30 days)
            $unique_urls = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT url) 
        FROM {$table_name} 
        WHERE last_redirected >= %s
    ", date('Y-m-d H:i:s', strtotime('-30 days'))));
        ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-number"><?php echo esc_html(number_format($total_redirects)); ?></span>
                    <div class="stat-label">Total Redirects Handled</div>
                    <div class="stat-sublabel">All time</div>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo esc_html(number_format($unique_urls)); ?></span>
                    <div class="stat-label">Unique Broken URLs</div>
                    <div class="stat-sublabel">Last 30 days</div>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo esc_html(number_format($unique_ips)); ?></span>
                    <div class="stat-label">Unique Visitors</div>
                    <div class="stat-sublabel">Last 30 days</div>
                </div>
            </div>

            <div style="margin-bottom: 25px; padding: 20px; background: #f8fafc; border-radius: 8px; border-left: 4px solid #2563eb;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <p style="margin: 0; color: #4b5563; font-size: 14px;">
                            Plugin installed on: <strong style="color: #1f2937;"><?php echo esc_attr(Detour_read_option_value('install_date', date("Y-m-d h:i a"))); ?></strong>
                        </p>
                    </div>
                    <button id="openModal" class="btn btn-danger">
                        <span class="dashicons dashicons-trash"></span> Clear All Data
                    </button>
                </div>
            </div>

            <!-- Search form with original styling -->
            <div class="search-controls" style="margin-bottom: 20px; background: white; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <form method="get" style="display: flex; align-items: center; gap: 10px;">
                    <input type="hidden" name="page" value="detour.php" />
                    <input type="hidden" name="mytab" value="404urls" />
                    <?php if (isset($_GET['orderby'])) : ?>
                        <input type="hidden" name="orderby" value="<?php echo esc_attr($_GET['orderby']); ?>" />
                    <?php endif; ?>
                    <?php if (isset($_GET['order'])) : ?>
                        <input type="hidden" name="order" value="<?php echo esc_attr($_GET['order']); ?>" />
                    <?php endif; ?>

                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search URLs..." style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; width: 280px;" />
                    <button type="submit" style="padding: 8px 16px; background: #6b7280; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        <span class="dashicons dashicons-search" style="margin-right: 4px;"></span>Search
                    </button>
                    <?php if (!empty($search)) : ?>
                        <a href="<?php echo esc_url(remove_query_arg('s')); ?>" style="padding: 8px 12px; background: #9ca3af; color: white; border: none; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center;">
                            <span class="dashicons dashicons-no-alt" style="margin-right: 4px;"></span>Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Original styled table with sorting -->
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th width="60">#</th>
                            <th width="180" class="sortable-column" data-column="last_redirected">
                                <a href="<?php echo esc_url(get_column_sort_url('last_redirected', $order_by, $order_dir)); ?>" style="color: #374151; text-decoration: none; display: flex; align-items: center; justify-content: space-between;">
                                    <span>Last Redirected</span>
                                    <span class="sort-icon">
                                        <?php if ($order_by === 'last_redirected') : ?>
                                            <?php if ($order_dir === 'ASC') : ?>
                                                <span class="dashicons dashicons-arrow-up-alt2" style="color: #6b7280; font-size: 16px;"></span>
                                            <?php else : ?>
                                                <span class="dashicons dashicons-arrow-down-alt2" style="color: #6b7280; font-size: 16px;"></span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-sort" style="color: #9ca3af; font-size: 16px;"></span>
                                        <?php endif; ?>
                                    </span>
                                </a>
                            </th>
                            <th class="sortable-column" data-column="url">
                                <a href="<?php echo esc_url(get_column_sort_url('url', $order_by, $order_dir)); ?>" style="color: #374151; text-decoration: none; display: flex; align-items: center; justify-content: space-between;">
                                    <span>URL</span>
                                    <span class="sort-icon">
                                        <?php if ($order_by === 'url') : ?>
                                            <?php if ($order_dir === 'ASC') : ?>
                                                <span class="dashicons dashicons-arrow-up-alt2" style="color: #6b7280; font-size: 16px;"></span>
                                            <?php else : ?>
                                                <span class="dashicons dashicons-arrow-down-alt2" style="color: #6b7280; font-size: 16px;"></span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-sort" style="color: #9ca3af; font-size: 16px;"></span>
                                        <?php endif; ?>
                                    </span>
                                </a>
                            </th>
                            <th width="100" class="sortable-column" data-column="count">
                                <a href="<?php echo esc_url(get_column_sort_url('count', $order_by, $order_dir)); ?>" style="color: #374151; text-decoration: none; display: flex; align-items: center; justify-content: space-between;">
                                    <span>Redirect Count</span>
                                    <span class="sort-icon">
                                        <?php if ($order_by === 'count') : ?>
                                            <?php if ($order_dir === 'ASC') : ?>
                                                <span class="dashicons dashicons-arrow-up-alt2" style="color: #6b7280; font-size: 16px;"></span>
                                            <?php else : ?>
                                                <span class="dashicons dashicons-arrow-down-alt2" style="color: #6b7280; font-size: 16px;"></span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-sort" style="color: #9ca3af; font-size: 16px;"></span>
                                        <?php endif; ?>
                                    </span>
                                </a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($latest_redirects)) { ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 60px 20px; color: #6b7280;">
                                    <div class="empty-state-container">
                                        <div class="dashicons dashicons-info empty-state-icon"></div>
                                        <?php if (!empty($search)) { ?>
                                            <h4 style="margin: 0 0 8px 0; color: #374151;">No Results Found</h4>
                                            <p style="margin: 0;">No URLs match your search for "<?php echo esc_html($search); ?>"</p>
                                            <p style="margin: 8px 0 0 0;"><a href="<?php echo esc_url(remove_query_arg('s')); ?>" style="color: #2563eb;">Clear search</a> to see all results.</p>
                                        <?php } else { ?>
                                            <h4 style="margin: 0 0 8px 0; color: #374151;">No 404 Redirects Yet</h4>
                                            <p style="margin: 0;">When visitors encounter 404 errors, they'll appear here.</p>
                                        <?php } ?>
                                    </div>
                                </td>
                            </tr>
                            <?php } else {
                            $start_number = ($current_page - 1) * $per_page;
                            foreach ($latest_redirects as $index => $redirect) {
                                $promote_href = esc_url(add_query_arg(
                                    array('mytab' => 'rules', 'promote' => rawurlencode($redirect['url'])),
                                    admin_url('admin.php?page=detour.php')
                                ));
                            ?>
                                <tr>
                                    <td style="font-weight: 500; color: #6b7280;"><?php echo (int) $start_number + $index + 1; ?></td>
                                    <td style="font-size: 13px; color: #4b5563;"><?php echo esc_html($redirect['last_redirected']); ?></td>
                                    <td>
                                        <a target="_blank" href="<?php echo esc_url($redirect['url']); ?>" style="color: #2563eb; text-decoration: none; word-break: break-all;">
                                            <?php echo esc_url($redirect['url']); ?>
                                        </a>
                                        <a href="<?php echo $promote_href; ?>" class="button button-small" style="margin-left:8px;font-size:11px;">+ Make rule</a>
                                    </td>
                                    <td class="Number">
                                        <span class="badge">
                                            <?php echo esc_html(number_format($redirect['count'])); ?>
                                        </span>
                                    </td>
                                </tr>
                        <?php }
                        } ?>
                    </tbody>
                </table>
            </div>

            <!-- Original styled pagination -->
            <?php if ($pagination_data['pages'] > 1) { ?>
                <div class="pagination-wrapper" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; background: white; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <div class="pagination-info" style="color: #6b7280; font-size: 14px;">
                        Showing <?php echo (($current_page - 1) * $per_page) + 1; ?> to
                        <?php echo min($current_page * $per_page, $pagination_data['total']); ?>
                        of <?php echo $pagination_data['total']; ?> entries
                        <?php if (!empty($search)) { ?>
                            (filtered from total)
                        <?php } ?>
                    </div>

                    <div class="pagination-controls">
                        <?php
                        $prev_page = $current_page - 1;
                        $next_page = $current_page + 1;
                        ?>

                        <?php if ($current_page > 1) { ?>
                            <button onclick="goToPage(1)" class="page-btn">First</button>
                            <button onclick="goToPage(<?php echo $prev_page; ?>)" class="page-btn">Previous</button>
                        <?php } ?>

                        <?php
                        // Show page numbers
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($pagination_data['pages'], $current_page + 2);

                        for ($i = $start_page; $i <= $end_page; $i++) {
                            $class = ($i == $current_page) ? 'page-btn active' : 'page-btn';
                            echo "<button onclick='goToPage($i)' class='$class'>$i</button>";
                        }
                        ?>

                        <?php if ($current_page < $pagination_data['pages']) { ?>
                            <button onclick="goToPage(<?php echo $next_page; ?>)" class="page-btn">Next</button>
                            <button onclick="goToPage(<?php echo $pagination_data['pages']; ?>)" class="page-btn">Last</button>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>

            <!-- Enhanced Modal for Clear Confirmation -->
            <div id="confirmationModal" class="p404-modal">
                <div class="p404-modal-content">
                    <div class="p404-modal-header">
                        <div class="dashicons dashicons-warning"></div>
                        <h2>Confirm Data Deletion</h2>
                    </div>
                    <div class="p404-modal-body">
                        <p>You are about to permanently delete all 404 redirect logs from the database.</p>
                        <p><strong>This action cannot be undone and will remove:</strong></p>
                        <ul style="margin: 15px 0; padding-left: 20px; color: #4b5563;">
                            <li>All recorded 404 URLs</li>
                            <li>Redirect counts and timestamps</li>
                            <li>Historical tracking data</li>
                        </ul>
                        <p style="color: #dc2626; font-weight: 500;">Are you sure you want to proceed?</p>
                    </div>
                    <div class="p404-modal-footer">
                        <button type="button" id="closeModal" class="btn btn-secondary">
                            <span class="dashicons dashicons-no-alt"></span> Cancel
                        </button>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                            <?php wp_nonce_field('clear_redirects_log', 'clear_redirects_nonce'); ?>
                            <input type="hidden" name="action" value="clear_redirects_log">
                            <button type="submit" class="btn btn-danger">
                                <span class="dashicons dashicons-trash"></span> Yes, Delete All Data
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        <?php } ?>

        <?php if ($mytab == "rules") {
            detour_render_rules_tab();
        } ?>
    </div>
</div>

<!-- Htaccess Error Modal -->
<div id="htaccessModal" class="p404-modal">
    <div class="p404-modal-content">
        <div class="p404-modal-header">
            <div class="dashicons dashicons-warning"></div>
            <h2>Manual .htaccess Configuration Required</h2>
        </div>
        <div class="p404-modal-body">
            <p><strong>Warning:</strong> Your .htaccess file is not writable by WordPress. You need to add the following code manually.</p>
            <p>Please copy and paste the following code into the <strong>first line</strong> of your .htaccess file:</p>
            <div class="htaccess-instructions">
                <code>
                    <?php
                    if (isset($_POST['misha-img']) && $_POST['misha-img'] != '') {
                        $image = wp_get_attachment_image_src(absint($_POST['misha-img']));
                        $image_url = $image[0];
                    ?>
                        RewriteOptions inherit<br>
                        &lt;IfModule mod_rewrite.c&gt;<br>
                        RewriteEngine on<br>
                        RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI} !-f<br>
                        RewriteRule \.(gif|jpe?g|png|bmp) <?php echo esc_url($image_url); ?> [NC,L]<br>
                        &lt;/IfModule&gt;
                    <?php } ?>
                </code>
            </div>
            <p style="margin-top: 15px; color: #4b5563; font-size: 13px;">
                <strong>Note:</strong> Make sure to backup your .htaccess file before making any changes.
            </p>
        </div>
        <div class="p404-modal-footer">
            <button type="button" id="closeHtaccessModal" class="btn btn-primary">
                <span class="dashicons dashicons-yes-alt"></span> I Understand
            </button>
        </div>
    </div>
</div>

<!-- Error Modal -->
<div id="errorModal" class="p404-modal">
    <div class="p404-modal-content simple-modal">
        <div class="simple-modal-body">
            <div class="modal-icon error-icon">
                <div class="dashicons dashicons-dismiss"></div>
            </div>
            <h2>Unable to Save Settings</h2>
            <button type="button" id="closeErrorModal" class="btn btn-primary modal-btn" onclick="location.reload();">
                Refresh Page
            </button>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div id="successModal" class="p404-modal">
    <div class="p404-modal-content simple-modal">
        <div class="simple-modal-body">
            <div class="modal-icon success-icon">
                <div class="dashicons dashicons-yes-alt"></div>
            </div>
            <h2>Settings Saved Successfully</h2>
            <button type="button" id="closeSuccessModal" class="btn btn-primary modal-btn">
                Perfect!
            </button>
        </div>
    </div>
</div>

<!-- Image Warning Modal -->
<div id="warningModal" class="p404-modal">
    <div class="p404-modal-content simple-modal">
        <div class="simple-modal-body">
            <div class="modal-icon warning-icon">
                <div class="dashicons dashicons-warning"></div>
            </div>
            <h2>Image Upload Required</h2>
            <button type="button" id="closeWarningModal" class="btn btn-primary modal-btn">
                I Understand
            </button>
        </div>
    </div>
</div>

<script>
    jQuery(document).ready(function($) {
        var deleteImageNonce = '<?php echo wp_create_nonce("delete_404_image_nonce"); ?>';
        var custom_uploader;
        var previousImageId = null;

        // Handle upload button click
        $('body').on('click', '.misha-upl', function(e) {
            e.preventDefault();
            var button = $(this);
            var container = button.closest('.image-upload-section');

            // Store the current image ID before opening uploader (for replacement)
            var currentImageId = container.find('.misha-img').val();
            if (currentImageId) {
                previousImageId = currentImageId;
            }

            // Create new uploader instance each time
            custom_uploader = wp.media({
                title: 'Choose Default 404 Image',
                library: {
                    type: 'image'
                },
                button: {
                    text: 'Use this image'
                },
                multiple: false
            });

            custom_uploader.on('select', function() {
                var attachment = custom_uploader.state().get('selection').first().toJSON();

                // Delete previous image if replacing
                if (previousImageId && previousImageId !== attachment.id) {
                    deletePreviousImage(previousImageId);
                }

                // Update the entire container with new image
                container.html(
                    '<div class="image-preview">' +
                    '<img src="' + attachment.url + '" alt="404 Default Image" />' +
                    '</div>' +
                    '<a href="#" class="btn btn-primary misha-upl">' +
                    '<span class="dashicons dashicons-edit"></span> Change Image' +
                    '</a>' +
                    '<a href="#" class="btn btn-secondary misha-rmv">' +
                    '<span class="dashicons dashicons-no-alt"></span> Remove Image' +
                    '</a>' +
                    '<input type="hidden" class="misha-img" name="misha-img" value="' + attachment.id + '">'
                );

                // Reset previous image ID
                previousImageId = null;

                // Force close the modal by removing it from DOM
                $('.media-modal').remove();
                $('.media-modal-backdrop').remove();
                $('body').removeClass('modal-open');

                // Set uploader to null
                custom_uploader = null;
            });

            // Handle uploader close event
            custom_uploader.on('close', function() {
                previousImageId = null;
            });

            custom_uploader.open();
        });

        // Function to delete previous image via AJAX
        function deletePreviousImage(imageId) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'delete_previous_404_image',
                    image_id: imageId,
                    option_name: 'image_id_p404_redirect_to', // Add this line
                    nonce: deleteImageNonce
                },
                success: function(response) {
                    if (response.success) {
                        console.log('Previous image deleted successfully');
                    } else {
                        console.log('Failed to delete previous image: ' + response.data);
                    }
                },
                error: function() {
                    console.log('Error occurred while deleting previous image');
                }
            });
        }

        // Remove image functionality
        $('body').on('click', '.misha-rmv', function(e) {
            e.preventDefault();
            var container = $(this).closest('.image-upload-section');
            var imageId = container.find('.misha-img').val();

            // Delete the image from media library
            if (imageId) {
                deletePreviousImage(imageId);
            }

            // Reset to default state
            container.html(
                '<div class="upload-section-container">' +
                '<div class="dashicons dashicons-format-image upload-icon"></div>' +
                '<p style="margin: 0 0 15px 0; color: #6b7280;">No image selected</p>' +
                '<a href="#" class="btn btn-primary misha-upl">' +
                '<span class="dashicons dashicons-upload"></span> Upload Image' +
                '</a>' +
                '<input type="hidden" class="misha-img" name="misha-img" value="">' +
                '</div>'
            );
        });

        // Modal functionality for clear confirmation
        $('#openModal').click(function(e) {
            e.preventDefault();
            $('#confirmationModal').css('display', 'flex');
        });

        $('#closeModal').click(function() {
            $('#confirmationModal').hide();
        });

        // Close modal when clicking outside
        $('#confirmationModal').click(function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Htaccess modal functionality
        $('#closeHtaccessModal').click(function() {
            $('#htaccessModal').hide();
        });

        $('#htaccessModal').click(function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Error modal functionality
        $('#closeErrorModal').click(function() {
            $('#errorModal').hide();
        });

        $('#errorModal').click(function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Success modal functionality
        $('#closeSuccessModal').click(function() {
            $('#successModal').hide();
        });

        $('#successModal').click(function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Warning modal functionality
        $('#closeWarningModal').click(function() {
            $('#warningModal').hide();
        });

        $('#warningModal').click(function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Form validation
        $('.p404-options-form').on('submit', function(e) {
            var redirectTo = $('#redirect_to').val().trim();
            if (!redirectTo) {
                e.preventDefault();
                alert('Please enter a redirect URL before saving.');
                $('#redirect_to').focus();
                return false;
            }

            // Basic URL validation
            if (!redirectTo.match(/^https?:\/\/.+/)) {
                e.preventDefault();
                alert('Please enter a valid URL starting with http:// or https://');
                $('#redirect_to').focus();
                return false;
            }
        });

        // Enhanced keyboard navigation for modals
        $(document).keydown(function(e) {
            if (e.keyCode === 27) { // ESC key
                $('.p404-modal:visible').hide();
            }
        });

        // Auto-focus on modal inputs when opened
        $('.p404-modal').on('show', function() {
            $(this).find('input, button').first().focus();
        });
    });

    // JavaScript functions for pagination (original style)
    function goToPage(page) {
        var url = new URL(window.location);
        url.searchParams.set('paged', page);
        window.location.href = url.toString();
    }

    // Function to show error modal (called from PHP)
    function showErrorModal() {
        jQuery('#errorModal').css('display', 'flex');
    }

    // Function to show success modal (called from PHP)
    function showSuccessModal() {
        jQuery('#successModal').css('display', 'flex');
    }

    // Function to show htaccess modal (called from PHP)
    function showHtaccessModal() {
        jQuery('#htaccessModal').css('display', 'flex');
    }

    // Function to show warning modal (called from PHP)
    function showWarningModal() {
        jQuery('#warningModal').css('display', 'flex');
    }

    // Add loading states to buttons
    jQuery(document).ready(function($) {
        $('.p404-options-form').on('submit', function() {
            var submitBtn = $(this).find('button[type="submit"]');
            var originalText = submitBtn.html();
            submitBtn.html('<span class="dashicons dashicons-update-alt" style="animation: rotation 1s infinite linear;"></span> Saving...').prop('disabled', true);

            // Re-enable after a timeout as fallback
            setTimeout(function() {
                submitBtn.html(originalText).prop('disabled', false);
            }, 5000);
        });
    });
</script>
<style>
    #sendTestEmail {
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        color: white;
        border: none;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 2px 4px rgba(99, 102, 241, 0.2);
    }

    #sendTestEmail:hover {
        background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
    }

    #sendTestEmail:disabled {
        background: #9ca3af;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    #sendTestEmail .dashicons {
        font-size: 16px;
        width: 16px;
        height: 16px;
        line-height: 16px;
    }

    /* Test Email Status Styling */
    #testEmailStatus {
        border-radius: 8px;
        padding: 15px;
        margin-top: 10px;
        transition: all 0.3s ease;
        border: 1px solid;
    }

    #testEmailStatus .dashicons {
        margin-right: 8px;
        font-size: 16px;
        width: 16px;
        height: 16px;
        line-height: 16px;
    }

    /* Enhanced form group for email input */
    .form-group input[type="email"] {
        min-width: 300px;
    }

    /* Statistics Grid Styling */
    .current-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 15px;
        text-align: center;
    }

    .stat-item {
        background: white;
        padding: 15px;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .stat-number {
        font-size: 24px;
        font-weight: bold;
        margin-bottom: 5px;
    }

    .stat-label {
        color: #6b7280;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Base WordPress Admin Styles */
    .p404-admin-wrap {
        max-width: 1200px;
        margin: 20px 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }

    .p404-header {
        padding: 25px 30px;
        border-radius: 12px 12px 0 0;
        margin-bottom: 0;
    }

    .p404-header h1 {
        margin: 0;
        font-size: 20px;
        font-weight: 500;
        letter-spacing: -0.025em;
    }

    .p404-header p {
        margin: 8px 0 0 0;
        opacity: 0.9;
        font-size: 16px;
        font-weight: 400;
    }

    .nav-tab-wrapper {
        border-bottom: 2px solid #e5e7eb;
        background: #f8fafc;
        margin: 0;
        padding-top: 0px;
        display: flex;
        gap: 0;
    }

    .nav-tab {
        position: relative;
        padding: 16px 24px;
        font-weight: 500;
        font-size: 15px;
        transition: all 0.2s ease;
        border: none;
        background: transparent;
        color: #6b7280;
        text-decoration: none;
        border-radius: 0;
        margin: 0;
        border-bottom: 3px solid transparent;
    }

    .nav-tab:first-child {
        margin-left: 0;
    }

    .nav-tab:not(:first-child) {
        margin-left: 8px;
    }

    .nav-tab-active,
    .nav-tab-active:focus,
    .nav-tab-active:focus:active,
    .nav-tab-active:hover {
        border-bottom: 3px solid #6b7280;
        background: #ffffff;
        color: #374151;
        font-weight: 600;
        box-shadow: none;
    }

    .nav-tab:hover:not(.nav-tab-active) {
        color: #374151;
        background: #f1f5f9;
    }

    #tabs_content {
        padding: 35px;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-top: 0;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        min-height: 500px;
    }

    /* Original Data Table Styles */
    .data-table {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        border: 1px solid #e5e7eb;
    }

    .data-table table {
        width: 100%;
        border-collapse: collapse;
    }

    .data-table th {
        background: #f8fafc;
        padding: 18px 20px;
        text-align: left;
        font-weight: 600;
        color: #374151;
        border-bottom: 2px solid #e5e7eb;
        font-size: 14px;
    }

    .data-table td {
        padding: 16px 20px;
        border-bottom: 1px solid #f3f4f6;
        font-size: 14px;
    }

    .data-table tr:hover {
        background: #f8fafc;
    }

    /* Sortable Column Styles */
    .sortable-column {
        cursor: pointer;
        user-select: none;
        transition: background-color 0.2s ease;
    }

    .sortable-column:hover {
        background: #f1f5f9 !important;
    }

    .sortable-column a {
        color: #374151;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        padding: 0;
    }

    .sortable-column a:hover {
        color: #1f2937;
    }

    .sort-icon {
        margin-left: 8px;
        display: flex;
        align-items: center;
    }

    .sort-icon .dashicons {
        font-size: 16px;
        width: 16px;
        height: 16px;
        line-height: 16px;
    }

    /* Pagination Styles - Original */
    .page-btn {
        padding: 8px 12px;
        margin: 0 2px;
        border: 1px solid #d1d5db;
        background: white;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.2s ease;
    }

    .page-btn:hover {
        background: #f3f4f6;
    }

    .page-btn.active {
        background: #6b7280;
        color: white;
        border-color: #6b7280;
    }

    .page-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Search Controls */
    .search-controls {
        background: white;
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
    }

    .search-controls input[type="search"]:focus {
        outline: none;
        border-color: #6b7280;
        box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.1);
    }

    /* Form Styles */
    .form-section {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
        border-left: 4px solid #6b7280;
    }

    .form-section h3 {
        margin: 0;
        padding: 20px 25px;
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
        border-bottom: 1px solid #cbd5e1;
        color: #1e293b;
        font-size: 18px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .form-section .form-content {
        padding: 25px;
    }

    .form-group {
        display: flex;
        align-items: flex-start;
        margin-bottom: 24px;
        gap: 20px;
    }

    .form-group:last-child {
        margin-bottom: 0;
    }

    .form-label {
        min-width: 220px;
        font-weight: 500;
        color: #374151;
        font-size: 14px;
        padding-top: 8px;
        line-height: 1.5;
    }

    .form-control {
        flex: 1;
        max-width: 450px;
    }

    .form-control input[type="text"],
    .form-control input[type="email"] {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s ease;
        background: white;
    }

    .form-control input[type="text"]:focus,
    .form-control input[type="email"]:focus {
        border-color: #6b7280;
        outline: none;
        box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.1);
    }

    .form-control select {
        padding: 10px 16px;
        padding-right: 40px;
        /* Make room for custom arrow */
        border: 2px solid #d1d5db;
        border-radius: 8px;
        background: white;
        font-size: 14px;
        min-width: 220px;
        cursor: pointer;

        /* Hide default arrow */
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;

        /* Add custom arrow */
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 16px;
    }

    .form-control select:focus {
        border-color: #6b7280;
        outline: none;
        box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.1);
    }

    /* Ensure the arrow stays visible on focus */
    .form-control select:focus {
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
    }

    .help-text {
        font-size: 13px;
        color: #6b7280;
        margin-top: 6px;
        line-height: 1.4;
    }

    .image-upload-section {
        border: 2px dashed #cbd5e1;
        border-radius: 12px;
        padding: 30px;
        text-align: center;
        background: #ffffff;
        transition: all 0.3s ease;
    }

    .image-upload-section:hover {
        border-color: #6b7280;
        background: #f9fafb;
    }

    .image-preview {
        margin: 20px 0;
    }

    .image-preview img {
        max-width: 150px;
        border-radius: 12px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s ease;
        line-height: 1;
    }

    .btn-primary {
        background: #6b7280;
        color: white;
    }

    .btn-primary:hover {
        background: #4b5563;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(107, 114, 128, 0.4);
    }

    .btn-secondary {
        background: #9ca3af;
        color: white;
    }

    .btn-secondary:hover {
        background: #6b7280;
    }

    .btn-danger {
        background: #dc2626;
        color: white;
    }

    .btn-danger:hover {
        background: #b91c1c;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 24px;
        margin-bottom: 35px;
    }

    .stat-card {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border: 1px solid #e2e8f0;
        color: #1f2937;
        padding: 25px;
        border-radius: 12px;
        text-align: center;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .stat-number {
        font-size: 36px;
        font-weight: bold;
        display: block;
        margin-bottom: 8px;
        color: #374151;
    }

    .stat-label {
        font-size: 15px;
        font-weight: 600;
        color: #4b5563;
        margin-bottom: 4px;
    }

    .stat-sublabel {
        font-size: 12px;
        color: #6b7280;
        font-weight: 400;
    }

    .notice {
        padding: 16px 20px;
        margin: 20px 0;
        border-radius: 8px;
        border-left: 4px solid;
        font-size: 14px;
    }

    .notice-info {
        background: #f0f9ff;
        border-color: #6b7280;
        color: #374151;
    }

    .notice-error {
        background: #fef2f2;
        border-color: #dc2626;
        color: #b91c1c;
    }

    .notice-success {
        background: #f0fdf4;
        border-color: #16a34a;
        color: #15803d;
    }

    .promotional-section {
        padding: 30px;
        border-radius: 12px;
        margin-top: 35px;
        text-align: center;
    }

    .promotional-section h4 {
        margin: 0 0 12px 0;
        font-size: 20px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .promotional-section p {
        margin: 0 0 20px 0;
        opacity: 0.9;
        font-size: 16px;
        line-height: 1.5;
    }

    .promotional-section img {
        max-width: 100%;
        border-radius: 12px;
    }

    .Number {
        text-align: center;
    }

    .badge {
        color: #6b7280;
        border-radius: 20px;
        font-size: 16px;
        font-weight: 500;
    }

    /* Enhanced focus states for grey theme */
    .btn:focus,
    input:focus,
    select:focus {
        outline: 2px solid #6b7280;
        outline-offset: 2px;
    }

    /* Update form submit button styling */
    .p404-options-form button[type="submit"] {
        background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
        color: white;
        font-size: 16px;
        padding: 16px 40px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .p404-options-form button[type="submit"]:hover {
        background: linear-gradient(135deg, #374151 0%, #1f2937 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(75, 85, 99, 0.4);
    }

    @media (max-width: 768px) {
        .p404-admin-wrap {
            margin: 10px;
        }

        .p404-header {
            padding: 20px;
        }

        .nav-tab-wrapper {
            padding: 0 20px;
            flex-wrap: wrap;
        }

        .nav-tab {
            padding: 12px 16px;
            font-size: 14px;
        }

        #tabs_content {
            padding: 20px;
        }

        .form-group {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .form-label {
            min-width: auto;
            padding-top: 0;
        }

        .form-control {
            max-width: 100%;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .p404-modal-content {
            padding: 25px;
            margin: 10px;
        }

        .btn {
            width: 100%;
            justify-content: center;
            margin-bottom: 8px;
        }

        .search-controls form {
            flex-direction: column;
            align-items: stretch;
        }

        .search-controls input[type="search"] {
            width: 100%;
            margin-bottom: 10px;
        }

        .pagination-wrapper {
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .pagination-info {
            margin-bottom: 15px;
        }

        .pagination-controls {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 4px;
        }

        .page-btn {
            min-width: 40px;
        }

        .data-table {
            font-size: 12px;
        }

        .data-table th,
        .data-table td {
            padding: 8px 10px;
        }

        .sortable-column a {
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
        }

        .sort-icon {
            margin-left: 0;
        }
    }

    /* Enhanced Modal Styles - Professional Grey Theme */
    .p404-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 99999;
        justify-content: center;
        align-items: center;
        padding: 20px;
        box-sizing: border-box;
    }

    .p404-modal-content {
        background: white;
        padding: 35px;
        width: 100%;
        max-width: 600px;
        border-radius: 16px;
        text-align: left;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        animation: modalSlideIn 0.3s ease;
        max-height: 90vh;
        overflow-y: auto;
    }

    /* Simple Modal Styles - For Success/Warning/Error */
    .simple-modal {
        max-width: 460px !important;
        padding: 0 !important;
        text-align: center !important;
        border-radius: 20px !important;
        overflow: hidden;
    }

    .simple-modal-body {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 50px 40px 40px 40px;
    }

    /* Modal Icon Styling */
    .modal-icon {
        width: 90px;
        height: 90px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 25px;
        position: relative;
        animation: modalIconPulse 0.6s ease-out;
    }

    .modal-icon::before {
        content: '';
        position: absolute;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: inherit;
        opacity: 0.2;
        transform: scale(1.3);
        animation: iconRipple 2s infinite;
    }

    .modal-icon .dashicons {
        font-size: 48px !important;
        width: 48px !important;
        height: 48px !important;
        line-height: 48px !important;
        position: relative;
        z-index: 2;
    }

    /* Icon Color Themes */
    .success-icon {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
        box-shadow: 0 8px 30px rgba(16, 185, 129, 0.4);
    }

    .error-icon {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
        box-shadow: 0 8px 30px rgba(239, 68, 68, 0.4);
    }

    .warning-icon {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: white;
        box-shadow: 0 8px 30px rgba(245, 158, 11, 0.4);
    }

    /* Modal Text Styling */
    .simple-modal h2 {
        margin: 0 0 15px 0 !important;
        font-size: 26px !important;
        font-weight: 700 !important;
        color: #1f2937 !important;
        line-height: 1.3 !important;
        letter-spacing: -0.025em;
    }

    .simple-modal p {
        margin: 0 0 20px 0 !important;
        color: #6b7280 !important;
        font-size: 16px !important;
        line-height: 1.5 !important;
        max-width: 320px;
    }

    /* Modal Button Styling */
    .modal-btn {
        margin-top: 15px !important;
        padding: 14px 32px !important;
        font-size: 16px !important;
        font-weight: 600 !important;
        min-width: 140px !important;
        border-radius: 12px !important;
        border: none !important;
        cursor: pointer;
        transition: all 0.2s ease;
        text-transform: none !important;
        letter-spacing: 0.025em;
    }

    .modal-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(107, 114, 128, 0.3) !important;
    }

    .modal-btn:active {
        transform: translateY(0);
    }

    /* Specific Modal Button Colors */
    .success-icon+h2+.modal-btn,
    .simple-modal .success-icon~* .modal-btn {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
        color: white !important;
    }

    .warning-icon+h2+.modal-btn,
    .simple-modal .warning-icon~* .modal-btn {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
        color: white !important;
    }

    .error-icon+h2+.modal-btn,
    .simple-modal .error-icon~* .modal-btn {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
        color: white !important;
    }

    /* Default button styling for simple modals */
    .simple-modal .btn-primary {
        background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%) !important;
        color: white !important;
    }

    /* Modal Header for Complex Modals */
    .p404-modal-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 25px;
        padding-bottom: 25px;
        border-bottom: 1px solid #e5e7eb;
    }

    .p404-modal-header .dashicons {
        font-size: 32px;
        color: #f59e0b;
        margin-bottom: 10px;
    }

    .p404-modal-header h2 {
        margin: 0;
        font-size: 24px;
        color: #1f2937;
        font-weight: 600;
    }

    /* Modal Body */
    .p404-modal-body {
        margin-bottom: 30px;
        line-height: 1.6;
        color: #4b5563;
    }

    .p404-modal-body strong {
        color: #1f2937;
    }

    /* Modal Footer */
    .p404-modal-footer {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        padding-top: 25px;
        border-top: 1px solid #e5e7eb;
    }

    /* Htaccess Instructions */
    .htaccess-instructions {
        background: #1f2937;
        padding: 20px;
        border-radius: 8px;
        margin-top: 15px;
    }

    .htaccess-instructions code {
        color: #e5e7eb;
        font-family: 'Monaco', 'Consolas', 'Courier New', monospace;
        line-height: 1.6;
        display: block;
        font-size: 13px;
    }

    /* Animations */
    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-50px) scale(0.95);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    @keyframes modalIconPulse {
        0% {
            transform: scale(0.8);
            opacity: 0;
        }

        50% {
            transform: scale(1.05);
        }

        100% {
            transform: scale(1);
            opacity: 1;
        }
    }

    @keyframes iconRipple {
        0% {
            transform: scale(1.3);
            opacity: 0.2;
        }

        50% {
            transform: scale(1.5);
            opacity: 0.1;
        }

        100% {
            transform: scale(1.3);
            opacity: 0.2;
        }
    }

    @keyframes rotation {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    /* Mobile Responsiveness for Modals */
    @media (max-width: 768px) {
        .p404-modal-content {
            margin: 15px;
            padding: 25px;
            border-radius: 16px;
        }

        .simple-modal {
            max-width: 90% !important;
            margin: 20px;
        }

        .simple-modal-body {
            padding: 40px 30px 30px 30px;
        }

        .modal-icon {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
        }

        .modal-icon .dashicons {
            font-size: 40px !important;
            width: 40px !important;
            height: 40px !important;
            line-height: 40px !important;
        }

        .simple-modal h2 {
            font-size: 22px !important;
        }

        .simple-modal p {
            font-size: 15px !important;
        }

        .modal-btn {
            width: 100%;
            margin-top: 20px !important;
        }

        .p404-modal-footer {
            flex-direction: column;
        }

        .p404-modal-footer .btn {
            width: 100%;
            margin-bottom: 8px;
        }
    }
</style>