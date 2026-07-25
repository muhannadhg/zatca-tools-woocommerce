<?php
/**
 * Settings screen: WooCommerce > Settings > ZATCA Tools.
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit;
}

class ZATCA_Tools_Settings {

    const OPTION_KEY     = 'zatca_tools_api_key';
    const OPTION_ENABLED = 'zatca_tools_enabled';
    const OPTION_STATUS  = 'zatca_tools_key_status'; // cached account name / error

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('woocommerce_settings_tabs_array', array($this, 'add_tab'), 50);
        add_action('woocommerce_settings_tabs_zatca_tools', array($this, 'render'));
        add_action('woocommerce_update_options_zatca_tools', array($this, 'save'));
    }

    public static function api_key() {
        return (string) get_option(self::OPTION_KEY, '');
    }

    public static function is_enabled() {
        return 'yes' === get_option(self::OPTION_ENABLED, 'yes') && self::api_key() !== '';
    }

    public function add_tab($tabs) {
        $tabs['zatca_tools'] = __('ZATCA Tools', 'e-invoicing-saudi-arabia-by-zatca-tools');
        return $tabs;
    }

    public function render() {
        $status = get_option(self::OPTION_STATUS, '');
        if ($status) {
            $is_error = 0 === strpos($status, 'error:');
            printf(
                '<div class="notice %s inline"><p>%s</p></div>',
                $is_error ? 'notice-error' : 'notice-success',
                esc_html($is_error ? substr($status, 6) : sprintf(__('Connected as: %s', 'e-invoicing-saudi-arabia-by-zatca-tools'), $status))
            );
        }
        echo '<p>' . sprintf(
            /* translators: %s: docs URL */
            wp_kses_post(__('Paste your API key from <strong>ZATCA Tools &rarr; Settings &rarr; API</strong>. Need help? See the <a href="%s" target="_blank" rel="noopener">setup guide</a>.', 'e-invoicing-saudi-arabia-by-zatca-tools')),
            'https://zatcatools.com/docs/woocommerce'
        ) . '</p>';

        // External-service disclosure (WordPress.org guideline 6/7).
        echo '<p class="description">' . sprintf(
            /* translators: 1: service URL, 2: terms URL, 3: privacy URL */
            wp_kses_post(__('This plugin sends order data to <a href="%1$s" target="_blank" rel="noopener">ZATCA Tools</a>, an external e-invoicing service, to issue and report your invoices. Data is only sent after you enter an API key. See the <a href="%2$s" target="_blank" rel="noopener">Terms of Use</a> and <a href="%3$s" target="_blank" rel="noopener">Privacy Policy</a>.', 'e-invoicing-saudi-arabia-by-zatca-tools')),
            'https://zatcatools.com',
            'https://zatcatools.com/terms',
            'https://zatcatools.com/privacy'
        ) . '</p>';

        woocommerce_admin_fields($this->fields());
    }

    public function save() {
        woocommerce_update_options($this->fields());
        $this->validate_key();
    }

    private function fields() {
        return array(
            array(
                'title' => __('ZATCA Tools e-invoicing', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                'type'  => 'title',
                'id'    => 'zatca_tools_section',
            ),
            array(
                'title'   => __('Enable automatic invoicing', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                'desc'    => __('Issue a ZATCA invoice for every paid order', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                'id'      => self::OPTION_ENABLED,
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
            array(
                'title'    => __('API key', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                'desc_tip' => __('From ZATCA Tools > Settings > API. Starts with ztk_live_', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                'id'       => self::OPTION_KEY,
                'type'     => 'password',
                'css'      => 'min-width:340px;font-family:monospace;',
            ),
            array('type' => 'sectionend', 'id' => 'zatca_tools_section'),
        );
    }

    /** After save, ping /account so the merchant sees success or the exact error. */
    private function validate_key() {
        $key = self::api_key();
        if ($key === '') {
            update_option(self::OPTION_STATUS, '');
            return;
        }

        $client = new ZATCA_Tools_Client($key);
        $res = $client->account();

        if ($res['ok']) {
            $name = isset($res['body']['name']) ? $res['body']['name'] : __('your establishment', 'e-invoicing-saudi-arabia-by-zatca-tools');
            $connected = ! empty($res['body']['zatca']['connected']);
            update_option(self::OPTION_STATUS, $connected
                ? $name
                : 'error:' . __('Key is valid but your ZATCA connection is not complete. Finish onboarding at zatcatools.com.', 'e-invoicing-saudi-arabia-by-zatca-tools'));
        } else {
            update_option(self::OPTION_STATUS, 'error:' . ($res['error'] ?: __('Could not validate the API key.', 'e-invoicing-saudi-arabia-by-zatca-tools')));
        }
    }
}
