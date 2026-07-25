<?php
/**
 * Plugin Name: E-Invoicing for Saudi Arabia by ZATCA Tools
 * Plugin URI:  https://zatcatools.com/docs/woocommerce
 * Description: Automatically issues Saudi Phase 2 compliant e-invoices for paid WooCommerce orders — signed, reported, and delivered. Refunds issue credit notes. Connects to the external ZATCA Tools service. Not affiliated with the Zakat, Tax and Customs Authority.
 * Version:     1.7.0
 * Author:      ZATCA Tools
 * Author URI:  https://zatcatools.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: e-invoicing-saudi-arabia-by-zatca-tools
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit; // No direct access.
}

define('ZATCA_TOOLS_VERSION', '1.7.0');
define('ZATCA_TOOLS_PATH', plugin_dir_path(__FILE__));
define('ZATCA_TOOLS_URL', plugin_dir_url(__FILE__));

require_once ZATCA_TOOLS_PATH . 'includes/class-zatca-client.php';
require_once ZATCA_TOOLS_PATH . 'includes/class-zatca-mapper.php';
require_once ZATCA_TOOLS_PATH . 'includes/class-zatca-settings.php';
require_once ZATCA_TOOLS_PATH . 'includes/class-zatca-orders.php';
require_once ZATCA_TOOLS_PATH . 'includes/class-zatca-invoice-file.php';
require_once ZATCA_TOOLS_PATH . 'includes/class-zatca-setup.php';
require_once ZATCA_TOOLS_PATH . 'includes/class-zatca-buyer.php';
require_once ZATCA_TOOLS_PATH . 'includes/class-zatca-checkout.php';
require_once ZATCA_TOOLS_PATH . 'includes/class-zatca-documents.php';
require_once ZATCA_TOOLS_PATH . 'includes/class-zatca-admin-page.php';

/**
 * Boot the plugin once all plugins are loaded, so we can verify WooCommerce
 * is active before hooking anything.
 */
add_action('plugins_loaded', function () {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('ZATCA Tools requires WooCommerce to be installed and active.', 'e-invoicing-saudi-arabia-by-zatca-tools')
                . '</p></div>';
        });

        return;
    }

    ZATCA_Tools_Settings::instance();
    ZATCA_Tools_Orders::instance();
    ZATCA_Tools_Invoice_File::instance();
    ZATCA_Tools_Buyer::instance();
    ZATCA_Tools_Checkout::instance();
    ZATCA_Tools_Documents::instance();
    ZATCA_Tools_Admin_Page::instance();
});

// Declare HPOS (High-Performance Order Storage) compatibility.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
