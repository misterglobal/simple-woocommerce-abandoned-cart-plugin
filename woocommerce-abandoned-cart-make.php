<?php
/*
Plugin Name: WooCommerce Abandoned Cart to Make
Description: Detects and records abandoned carts, sends data to Make (Integromat), and provides admin management for WooCommerce stores.
Version: 1.0.0
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

class WC_Abandoned_Cart_Make {
    public function __construct() {
        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Admin menu
        add_action('admin_menu', array($this, 'admin_menu'));

        // Settings
        add_action('admin_init', array($this, 'register_settings'));

        // WooCommerce hooks
        add_action('woocommerce_cart_updated', array($this, 'track_cart'));
        add_action('woocommerce_checkout_order_processed', array($this, 'mark_cart_recovered'), 10, 1);

        // Cron for abandoned carts
        add_action('wc_abandoned_cart_cron', array($this, 'check_abandoned_carts'));

        // GDPR notice
        add_action('woocommerce_before_cart', array($this, 'gdpr_notice'));

        // Init hooks
        add_action('init', array($this, 'init_hooks'));
    }

    public function activate() {
        global $wpdb;
        $table = $wpdb->prefix . 'abandoned_carts';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50),
            name VARCHAR(255),
            cart_json TEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sent_to_make TINYINT(1) DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        if (!wp_next_scheduled('wc_abandoned_cart_cron')) {
            wp_schedule_event(time(), 'minute', 'wc_abandoned_cart_cron');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('wc_abandoned_cart_cron');
    }

    // Register plugin settings
    public function register_settings() {
        register_setting('wc_abandoned_cart_settings', 'wc_abandoned_cart_webhook_url');
        register_setting('wc_abandoned_cart_settings', 'wc_abandoned_cart_timeout');
        register_setting('wc_abandoned_cart_settings', 'wc_abandoned_cart_logging');
        register_setting('wc_abandoned_cart_settings', 'wc_abandoned_cart_expiry_days');
    }

    // Add admin menu
    public function admin_menu() {
        add_menu_page(
            'Abandoned Carts',
            'Abandoned Carts',
            'manage_woocommerce',
            'wc-abandoned-carts',
            array($this, 'admin_page'),
            'dashicons-cart',
            56
        );
        add_submenu_page(
            'wc-abandoned-carts',
            'Settings',
            'Settings',
            'manage_woocommerce',
            'wc-abandoned-carts-settings',
            array($this, 'settings_page')
        );
    }

    // Admin page: Table of abandoned carts
    public function admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'abandoned_carts';
        // Handle actions
        if (isset($_GET['resend']) && is_numeric($_GET['resend'])) {
            $id = intval($_GET['resend']);
            $cart = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
            if ($cart) {
                $this->send_to_make($cart);
                $wpdb->update($table, ['sent_to_make' => 1], ['id' => $id]);
                echo '<div class="updated"><p>Webhook resent to Make for cart ID ' . esc_html($id) . '.</p></div>';
            }
        }
        if (isset($_GET['recover']) && is_numeric($_GET['recover'])) {
            $id = intval($_GET['recover']);
            $wpdb->update($table, ['status' => 'recovered'], ['id' => $id]);
            echo '<div class="updated"><p>Cart ID ' . esc_html($id) . ' marked as recovered.</p></div>';
        }
        $carts = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 100");
        echo '<div class="wrap"><h1>Abandoned Carts</h1>';
        echo '<table class="widefat"><thead><tr><th>Email</th><th>Name</th><th>Phone</th><th>Status</th><th>Created</th><th>Sent to Make</th><th>Actions</th></tr></thead><tbody>';
        foreach ($carts as $cart) {
            echo '<tr>';
            echo '<td>' . esc_html($cart->email) . '</td>';
            echo '<td>' . esc_html($cart->name) . '</td>';
            echo '<td>' . esc_html($cart->phone) . '</td>';
            echo '<td>' . esc_html($cart->status) . '</td>';
            echo '<td>' . esc_html($cart->created_at) . '</td>';
            echo '<td>' . ($cart->sent_to_make ? 'Yes' : 'No') . '</td>';
            echo '<td>';
            echo '<a href="?page=wc-abandoned-carts&resend=' . intval($cart->id) . '" class="button">Resend to Make</a> ';
            echo '<a href="?page=wc-abandoned-carts&recover=' . intval($cart->id) . '" class="button">Mark as Recovered</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    // Helper: Send webhook to Make
    private function send_to_make($cart) {
        $webhook_url = get_option('wc_abandoned_cart_webhook_url', '');
        if (!$webhook_url) return;
        $payload = array(
            'email' => $cart->email,
            'name' => $cart->name,
            'phone' => $cart->phone,
            'cart' => json_decode($cart->cart_json, true),
            'cart_total' => '',
            'abandonment_timestamp' => $cart->created_at,
            'cart_id' => $cart->id
        );
        $args = array(
            'body' => json_encode($payload),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 10
        );
        wp_remote_post($webhook_url, $args);
    }

    // Settings page
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Abandoned Cart Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('wc_abandoned_cart_settings'); ?>
                <?php do_settings_sections('wc_abandoned_cart_settings'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Make Webhook URL</th>
                        <td><input type="text" name="wc_abandoned_cart_webhook_url" value="<?php echo esc_attr(get_option('wc_abandoned_cart_webhook_url')); ?>" size="60" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Abandonment Timeout (minutes)</th>
                        <td><input type="number" name="wc_abandoned_cart_timeout" value="<?php echo esc_attr(get_option('wc_abandoned_cart_timeout', 60)); ?>" min="1" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Enable Logging</th>
                        <td><input type="checkbox" name="wc_abandoned_cart_logging" value="1" <?php checked(1, get_option('wc_abandoned_cart_logging', 0)); ?> /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Auto-expire Records After (days)</th>
                        <td><input type="number" name="wc_abandoned_cart_expiry_days" value="<?php echo esc_attr(get_option('wc_abandoned_cart_expiry_days', 30)); ?>" min="1" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // Track cart changes and store/update session data
    public function track_cart() {
        if (!is_user_logged_in() && empty(WC()->session)) return;
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) return;
        $session_id = WC()->session->get_customer_id();
        $email = WC()->session->get('abandoned_cart_email');
        if (!$email) return; // Only track if email is available
        $phone = WC()->session->get('abandoned_cart_phone');
        $name = WC()->session->get('abandoned_cart_name');
        $cart_items = array();
        foreach ($cart->get_cart() as $item) {
            $cart_items[] = array(
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity']
            );
        }
        $cart_json = wp_json_encode($cart_items);
        $cart_total = $cart->get_total('edit');
        $currency = get_woocommerce_currency();
        $now = current_time('mysql');
        global $wpdb;
        $table = $wpdb->prefix . 'abandoned_carts';
        // Upsert abandoned cart record
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE email = %s AND status = 'pending'", $email));
        if ($existing) {
            $wpdb->update($table, [
                'cart_json' => $cart_json,
                'created_at' => $now
            ], ['id' => $existing]);
        } else {
            $wpdb->insert($table, [
                'email' => $email,
                'phone' => $phone,
                'name' => $name,
                'cart_json' => $cart_json,
                'status' => 'pending',
                'created_at' => $now,
                'sent_to_make' => 0
            ]);
        }
    }

    // Mark cart as recovered on checkout
    public function mark_cart_recovered($order_id) {
        $order = wc_get_order($order_id);
        $email = $order->get_billing_email();
        global $wpdb;
        $table = $wpdb->prefix . 'abandoned_carts';
        $wpdb->update($table, [
            'status' => 'recovered'
        ], [
            'email' => $email,
            'status' => 'pending'
        ]);
    }

    // Cron: Check for abandoned carts and fire webhook
    public function check_abandoned_carts() {
        $timeout = get_option('wc_abandoned_cart_timeout', 60); // minutes
        $webhook_url = get_option('wc_abandoned_cart_webhook_url', '');
        if (!$webhook_url) return;
        global $wpdb;
        $table = $wpdb->prefix . 'abandoned_carts';
        $threshold = date('Y-m-d H:i:s', strtotime('-' . intval($timeout) . ' minutes'));
        $carts = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status = 'pending' AND created_at < %s AND sent_to_make = 0", $threshold));
        foreach ($carts as $cart) {
            $payload = array(
                'email' => $cart->email,
                'name' => $cart->name,
                'phone' => $cart->phone,
                'cart' => json_decode($cart->cart_json, true),
                'cart_total' => '', // Optionally add cart total if stored
                'abandonment_timestamp' => $cart->created_at,
                'cart_id' => $cart->id
            );
            $args = array(
                'body' => json_encode($payload),
                'headers' => array('Content-Type' => 'application/json'),
                'timeout' => 10
            );
            $response = wp_remote_post($webhook_url, $args);
            if (!is_wp_error($response)) {
                $wpdb->update($table, ['sent_to_make' => 1], ['id' => $cart->id]);
            }
        }
        $expire_days = get_option('wc_abandoned_cart_expiry_days', 30);
        if ($expire_days > 0) {
            $expire_threshold = date('Y-m-d H:i:s', strtotime('-' . intval($expire_days) . ' days'));
            $wpdb->update($table, ['status' => 'expired'], [ 'status' => 'pending', 'created_at <' => $expire_threshold ]);
        }
    }

    // GDPR notice on cart page
    public function gdpr_notice() {
        echo '<div class="woocommerce-info">If you don’t complete your purchase, we may contact you about your cart.</div>';
    }

    // Allow admin to delete abandoned cart records
    public function delete_cart($cart_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'abandoned_carts';
        $wpdb->delete($table, ['id' => intval($cart_id)]);
    }

    // AJAX handler to save abandoned cart email to WooCommerce session
    public function ajax_save_abandoned_cart_email() {
        if (!empty($_POST['email']) && is_email($_POST['email'])) {
            if (function_exists('WC')) {
                WC()->session->set('abandoned_cart_email', sanitize_email($_POST['email']));
            }
            wp_send_json_success();
        }
        wp_send_json_error();
    }

    // Update exit-intent popup JS to use AJAX
    public function exit_intent_popup() {
        ?>
        <script>
        let shown = false;
        document.addEventListener('mouseout', function(e) {
            if (!shown && e.clientY < 50) {
                shown = true;
                let email = prompt('Enter your email to save your cart:');
                if (email) {
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=save_abandoned_cart_email&email=' + encodeURIComponent(email)
                    });
                }
            }
        });
        </script>
        <?php
    }

    // Add AJAX action hooks
    public function init_hooks() {
        add_action('wp_footer', array($this, 'exit_intent_popup'));
        add_action('wp_ajax_save_abandoned_cart_email', array($this, 'ajax_save_abandoned_cart_email'));
        add_action('wp_ajax_nopriv_save_abandoned_cart_email', array($this, 'ajax_save_abandoned_cart_email'));
    }
}

new WC_Abandoned_Cart_Make();
