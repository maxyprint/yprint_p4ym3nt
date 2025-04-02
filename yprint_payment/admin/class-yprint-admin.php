<?php
/**
 * Admin-Bereich des YPrint Payment Plugins
 *
 * Diese Klasse ist verantwortlich für die Verwaltung des Admin-Bereichs des
 * YPrint Payment Plugins, einschließlich der Einstellungsseiten, Gateway-Konfigurationen,
 * und Status-Informationen.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * YPrint Admin-Klasse
 */
class YPrint_Admin {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_Admin
     */
    protected static $_instance = null;

    /**
     * Feature Flags Manager
     *
     * @var YPrint_Feature_Flags
     */
    private $feature_flags;

    /**
     * API Manager
     *
     * @var YPrint_API
     */
    private $api;

    /**
     * Admin-Menü Slug
     *
     * @var string
     */
    private $menu_slug = 'yprint-payment-settings';

    /**
     * Admin-Tabs
     *
     * @var array
     */
    private $tabs = array();

    /**
     * Plugin-Version
     *
     * @var string
     */
    private $version;

    /**
     * Hauptinstanz der YPrint_Admin-Klasse
     *
     * @return YPrint_Admin - Hauptinstanz
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Konstruktor
     */
    public function __construct() {
        // Nur im Admin-Bereich initialisieren
        if (!is_admin()) {
            return;
        }

        $this->version = YPRINT_PAYMENT_VERSION;

        // Feature Flags Manager laden
        $this->feature_flags = class_exists('YPrint_Feature_Flags') ? YPrint_Feature_Flags::instance() : null;
        
        // API Manager laden
        $this->api = class_exists('YPrint_API') ? YPrint_API::instance() : null;
        
        // Tabs definieren
        $this->define_tabs();
        
        // Hooks initialisieren
        $this->init_hooks();
    }

    /**
     * Hooks initialisieren
     */
    private function init_hooks() {
        // Admin-Menü hinzufügen
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Admin-Scripts laden
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX-Handler registrieren
        add_action('wp_ajax_yprint_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_yprint_test_stripe_connection', array($this, 'ajax_test_stripe_connection'));
        add_action('wp_ajax_yprint_test_paypal_connection', array($this, 'ajax_test_paypal_connection'));
        add_action('wp_ajax_yprint_create_webhook', array($this, 'ajax_create_webhook'));
        add_action('wp_ajax_yprint_toggle_feature_flag', array($this, 'ajax_toggle_feature_flag'));
        
        // Einstellungsfelder registrieren
        add_action('admin_init', array($this, 'register_settings'));
        
        // Plugin-Aktionslinks in der Plugin-Liste hinzufügen
        add_filter('plugin_action_links_' . YPRINT_PAYMENT_BASENAME, array($this, 'add_plugin_action_links'));
        
        // Admin-Benachrichtigungen
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    /**
     * Admin-Menü hinzufügen
     */
    public function add_admin_menu() {
        // Hauptmenüpunkt
        add_menu_page(
            __('YPrint Payment', 'yprint-payment'),
            __('YPrint Payment', 'yprint-payment'),
            'manage_options',
            $this->menu_slug,
            array($this, 'render_settings_page'),
            'dashicons-cart',
            56
        );
        
        // Untermenüpunkte für jeden Tab
        foreach ($this->tabs as $tab_id => $tab) {
            add_submenu_page(
                $this->menu_slug,
                $tab['title'],
                $tab['title'],
                'manage_options',
                $this->menu_slug . '&tab=' . $tab_id,
                array($this, 'render_settings_page')
            );
        }
    }

    /**
     * Admin-Scripts und -Styles laden
     *
     * @param string $hook Admin-Seiten-Hook
     */
    public function enqueue_admin_scripts($hook) {
        // Nur auf Plugin-Seiten laden
        if (strpos($hook, $this->menu_slug) === false) {
            return;
        }
        
        // Styles
        wp_enqueue_style(
            'yprint-admin-styles',
            YPRINT_PAYMENT_URL . 'admin/assets/css/yprint-admin.css',
            array(),
            $this->version
        );
        
        // Scripts
        wp_enqueue_script(
            'yprint-admin-scripts',
            YPRINT_PAYMENT_URL . 'admin/assets/js/yprint-admin.js',
            array('jquery'),
            $this->version,
            true
        );
        
        // Lokalisierung für JavaScript
        wp_localize_script('yprint-admin-scripts', 'yprint_admin_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'admin_nonce' => wp_create_nonce('yprint-admin-nonce'),
            'stripe_enabled' => $this->feature_flags ? $this->feature_flags->is_enabled('stripe_integration') : true,
            'paypal_enabled' => $this->feature_flags ? $this->feature_flags->is_enabled('paypal_integration') : true,
            'sepa_enabled' => $this->feature_flags ? $this->feature_flags->is_enabled('sepa_integration') : true,
            'webhook_urls' => array(
                'stripe' => home_url('/?yprint-stripe-webhook=1'),
                'paypal' => home_url('/?yprint-paypal-webhook=1'),
                'sepa' => home_url('/?yprint-sepa-webhook=1')
            ),
            'text_processing' => __('Verarbeitung...', 'yprint-payment'),
            'text_success' => __('Erfolgreich!', 'yprint-payment'),
            'text_error' => __('Fehler:', 'yprint-payment'),
            'text_confirm_reset' => __('Bist du sicher, dass du alle Einstellungen zurücksetzen möchtest? Dies kann nicht rückgängig gemacht werden.', 'yprint-payment')
        ));
    }

    /**
     * Admin-Tabs definieren
     */
    private function define_tabs() {
        $this->tabs = array(
            'general' => array(
                'title' => __('Allgemein', 'yprint-payment'),
                'callback' => array($this, 'render_general_tab')
            ),
            'stripe' => array(
                'title' => __('Stripe', 'yprint-payment'),
                'callback' => array($this, 'render_stripe_tab')
            ),
            'paypal' => array(
                'title' => __('PayPal', 'yprint-payment'),
                'callback' => array($this, 'render_paypal_tab')
            ),
            'sepa' => array(
                'title' => __('SEPA', 'yprint-payment'),
                'callback' => array($this, 'render_sepa_tab')
            ),
            'bank_transfer' => array(
                'title' => __('Banküberweisung', 'yprint-payment'),
                'callback' => array($this, 'render_bank_transfer_tab')
            ),
            'webhooks' => array(
                'title' => __('Webhooks', 'yprint-payment'),
                'callback' => array($this, 'render_webhooks_tab')
            ),
            'features' => array(
                'title' => __('Features', 'yprint-payment'),
                'callback' => array($this, 'render_features_tab')
            ),
            'status' => array(
                'title' => __('Status', 'yprint-payment'),
                'callback' => array($this, 'render_status_tab')
            ),
            'logs' => array(
                'title' => __('Logs', 'yprint-payment'),
                'callback' => array($this, 'render_logs_tab')
            )
        );
    }

    /**
     * Einstellungsseite rendern
     */
    public function render_settings_page() {
        // Berechtigung prüfen
        if (!current_user_can('manage_options')) {
            wp_die(__('Du hast nicht genügend Berechtigungen, um auf diese Seite zuzugreifen.', 'yprint-payment'));
        }
        
        // Aktiven Tab ermitteln
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        
        if (!isset($this->tabs[$active_tab])) {
            $active_tab = 'general';
        }
        
        ?>
        <div class="wrap yprint-payment-admin">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="yprint-admin-notice hidden"></div>
            
            <h2 class="nav-tab-wrapper">
                <?php foreach ($this->tabs as $tab_id => $tab) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '&tab=' . $tab_id)); ?>" 
                       class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab['title']); ?>
                    </a>
                <?php endforeach; ?>
            </h2>
            
            <div class="yprint-admin-content">
                <form method="post" action="options.php" class="yprint-settings-form">
                    <?php
                    // Tab-Inhalt rendern
                    if (isset($this->tabs[$active_tab]['callback']) && is_callable($this->tabs[$active_tab]['callback'])) {
                        call_user_func($this->tabs[$active_tab]['callback']);
                    }
                    ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Allgemeine Einstellungen rendern
     */
    public function render_general_tab() {
        ?>
        <h2><?php _e('Allgemeine Einstellungen', 'yprint-payment'); ?></h2>
        
        <?php settings_fields('yprint_general_settings'); ?>
        <?php do_settings_sections('yprint_general_settings'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="yprint_shop_name"><?php _e('Shop-Name', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_shop_name" name="yprint_shop_name" 
                           value="<?php echo esc_attr(get_option('yprint_shop_name', get_bloginfo('name'))); ?>" 
                           class="regular-text">
                    <p class="description"><?php _e('Wird in E-Mails und auf Zahlungsseiten angezeigt.', 'yprint-payment'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_thank_you_page"><?php _e('Danke-Seite', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <?php
                    $thank_you_page_id = get_option('yprint_thank_you_page', 0);
                    wp_dropdown_pages(array(
                        'name' => 'yprint_thank_you_page',
                        'id' => 'yprint_thank_you_page',
                        'selected' => $thank_you_page_id,
                        'show_option_none' => __('Standard-Dankeseite verwenden', 'yprint-payment')
                    ));
                    ?>
                    <p class="description">
                        <?php _e('Seite, auf die der Kunde nach erfolgreicher Zahlung weitergeleitet wird.', 'yprint-payment'); ?>
                        <?php if ($thank_you_page_id > 0) : ?>
                            <a href="<?php echo get_permalink($thank_you_page_id); ?>" target="_blank">
                                <?php _e('Seite anzeigen', 'yprint-payment'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_debug_mode"><?php _e('Debug-Modus', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="yprint_debug_mode" name="yprint_debug_mode" value="1" 
                               <?php checked(get_option('yprint_debug_mode', '0'), '1'); ?>>
                        <?php _e('Debug-Modus aktivieren', 'yprint-payment'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Aktiviert detaillierte Protokollierung und Debug-Informationen.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_log_retention"><?php _e('Log-Aufbewahrung', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <select id="yprint_log_retention" name="yprint_log_retention">
                        <option value="7" <?php selected(get_option('yprint_log_retention', '30'), '7'); ?>>
                            <?php _e('7 Tage', 'yprint-payment'); ?>
                        </option>
                        <option value="14" <?php selected(get_option('yprint_log_retention', '30'), '14'); ?>>
                            <?php _e('14 Tage', 'yprint-payment'); ?>
                        </option>
                        <option value="30" <?php selected(get_option('yprint_log_retention', '30'), '30'); ?>>
                            <?php _e('30 Tage', 'yprint-payment'); ?>
                        </option>
                        <option value="60" <?php selected(get_option('yprint_log_retention', '30'), '60'); ?>>
                            <?php _e('60 Tage', 'yprint-payment'); ?>
                        </option>
                        <option value="90" <?php selected(get_option('yprint_log_retention', '30'), '90'); ?>>
                            <?php _e('90 Tage', 'yprint-payment'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php _e('Zeitraum, für den Protokolle aufbewahrt werden sollen.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="button" id="yprint_save_general_settings" class="button button-primary">
                <?php _e('Einstellungen speichern', 'yprint-payment'); ?>
            </button>
        </p>
        <?php
    }

    /**
     * Stripe-Einstellungen rendern
     */
    public function render_stripe_tab() {
        // Prüfen, ob Stripe aktiviert ist
        $stripe_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('stripe_integration') : true;
        
        ?>
        <h2><?php _e('Stripe-Einstellungen', 'yprint-payment'); ?></h2>
        
        <?php settings_fields('yprint_stripe_settings'); ?>
        <?php do_settings_sections('yprint_stripe_settings'); ?>
        
        <div class="notice notice-info inline">
            <p>
                <?php _e('Stripe ist eine sichere Zahlungsplattform, die Kredit- und Debitkartenzahlungen ermöglicht. Du benötigst ein Stripe-Konto, um diese Zahlungsmethode zu aktivieren.', 'yprint-payment'); ?>
                <a href="https://stripe.com" target="_blank">
                    <?php _e('Mehr über Stripe erfahren', 'yprint-payment'); ?>
                </a>
            </p>
        </div>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="yprint_stripe_enabled"><?php _e('Stripe aktivieren', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="yprint_stripe_enabled" name="yprint_stripe_enabled" value="1" 
                               <?php checked($stripe_enabled, true); ?>>
                        <?php _e('Stripe als Zahlungsmethode aktivieren', 'yprint-payment'); ?>
                    </label>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_stripe_test_mode"><?php _e('Testmodus', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="yprint_stripe_test_mode" name="yprint_stripe_test_mode" value="yes" 
                               <?php checked(get_option('yprint_stripe_test_mode', 'no'), 'yes'); ?>>
                        <?php _e('Testmodus aktivieren', 'yprint-payment'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Im Testmodus werden keine echten Zahlungen verarbeitet. Verwende Testeinkaufsdaten zum Testen.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr class="stripe-api-keys">
                <th scope="row" colspan="2">
                    <h3><?php _e('Produktionsschlüssel (Live)', 'yprint-payment'); ?></h3>
                </th>
            </tr>
            
            <tr class="stripe-api-keys">
                <th scope="row">
                    <label for="yprint_stripe_public_key"><?php _e('Öffentlicher Schlüssel', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_stripe_public_key" name="yprint_stripe_public_key" 
                           value="<?php echo esc_attr(get_option('yprint_stripe_public_key', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Beginnt mit "pk_live_".', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr class="stripe-api-keys">
                <th scope="row">
                    <label for="yprint_stripe_secret_key"><?php _e('Geheimer Schlüssel', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="password" id="yprint_stripe_secret_key" name="yprint_stripe_secret_key" 
                           value="<?php echo esc_attr(get_option('yprint_stripe_secret_key', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Beginnt mit "sk_live_".', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr class="stripe-api-keys">
                <th scope="row">
                    <label for="yprint_stripe_webhook_secret"><?php _e('Webhook-Secret', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="password" id="yprint_stripe_webhook_secret" name="yprint_stripe_webhook_secret" 
                           value="<?php echo esc_attr(get_option('yprint_stripe_webhook_secret', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Beginnt mit "whsec_".', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr class="stripe-api-keys">
                <th scope="row" colspan="2">
                    <h3><?php _e('Testschlüssel', 'yprint-payment'); ?></h3>
                </th>
            </tr>
            
            <tr class="stripe-api-keys">
                <th scope="row">
                    <label for="yprint_stripe_test_public_key"><?php _e('Test-Öffentlicher Schlüssel', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_stripe_test_public_key" name="yprint_stripe_test_public_key" 
                           value="<?php echo esc_attr(get_option('yprint_stripe_test_public_key', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Beginnt mit "pk_test_".', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr class="stripe-api-keys">
                <th scope="row">
                    <label for="yprint_stripe_test_secret_key"><?php _e('Test-Geheimer Schlüssel', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="password" id="yprint_stripe_test_secret_key" name="yprint_stripe_test_secret_key" 
                           value="<?php echo esc_attr(get_option('yprint_stripe_test_secret_key', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Beginnt mit "sk_test_".', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr class="stripe-api-keys">
                <th scope="row">
                    <label for="yprint_stripe_test_webhook_secret"><?php _e('Test-Webhook-Secret', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="password" id="yprint_stripe_test_webhook_secret" name="yprint_stripe_test_webhook_secret" 
                           value="<?php echo esc_attr(get_option('yprint_stripe_test_webhook_secret', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Beginnt mit "whsec_".', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label><?php _e('Verbindung testen', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <button type="button" id="yprint_test_stripe_connection" class="button">
                        <?php _e('Verbindung zu Stripe testen', 'yprint-payment'); ?>
                    </button>
                    <span id="yprint_stripe_connection_result"></span>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label><?php _e('Webhook-URL', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <code><?php echo home_url('/?yprint-stripe-webhook=1'); ?></code>
                    <p class="description">
                        <?php _e('Füge diese URL in deinem Stripe-Dashboard unter Entwickler &rarr; Webhooks hinzu.', 'yprint-payment'); ?>
                    </p>
                    <button type="button" id="yprint_create_stripe_webhook" class="button">
                        <?php _e('Webhook automatisch erstellen', 'yprint-payment'); ?>
                    </button>
                    <span id="yprint_stripe_webhook_result"></span>
                </td>
            </tr>
            
            <tr>
                <th scope="row" colspan="2">
                    <h3><?php _e('Stripe Checkout-Einstellungen', 'yprint-payment'); ?></h3>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_stripe_sca_support"><?php _e('SCA-Unterstützung', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="yprint_stripe_sca_support" name="yprint_stripe_sca_support" value="1" 
                               <?php checked($this->feature_flags->is_enabled('stripe_sca_support'), true); ?>>
                        <?php _e('Starke Kundenauthentifizierung (SCA) aktivieren', 'yprint-payment'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Erforderlich für Händler in der EU. Aktiviert 3D Secure und andere Authentifizierungsmethoden.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_stripe_payment_title"><?php _e('Zahlungstitel', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_stripe_payment_title" name="yprint_stripe_payment_title" 
                           value="<?php echo esc_attr(get_option('yprint_stripe_payment_title', __('Kreditkarte (Stripe)', 'yprint-payment'))); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Der Titel, der den Kunden angezeigt wird.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_stripe_payment_description"><?php _e('Zahlungsbeschreibung', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <textarea id="yprint_stripe_payment_description" name="yprint_stripe_payment_description" 
                              class="large-text" rows="3"><?php echo esc_textarea(get_option('yprint_stripe_payment_description', __('Bezahlen Sie sicher mit Ihrer Kredit- oder Debitkarte.', 'yprint-payment'))); ?></textarea>
                    <p class="description">
                        <?php _e('Die Beschreibung, die den Kunden angezeigt wird.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="button" id="yprint_save_stripe_settings" class="button button-primary">
                <?php _e('Einstellungen speichern', 'yprint-payment'); ?>
            </button>
        </p>
        <?php
    }

    /**
     * PayPal-Einstellungen rendern
     */
    public function render_paypal_tab() {
        // Prüfen, ob PayPal aktiviert ist
        $paypal_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('paypal_integration') : true;
        
        ?>
        <h2><?php _e('PayPal-Einstellungen', 'yprint-payment'); ?></h2>
        
        <?php settings_fields('yprint_paypal_settings'); ?>
        <?php settings_fields('yprint_paypal_settings'); ?>
        <?php do_settings_sections('yprint_paypal_settings'); ?>
        
        <div class="notice notice-info inline">
            <p>
                <?php _e('PayPal ermöglicht es deinen Kunden, mit ihrem PayPal-Konto oder per Kreditkarte zu bezahlen. Du benötigst ein PayPal-Geschäftskonto, um diese Zahlungsmethode zu aktivieren.', 'yprint-payment'); ?>
                <a href="https://www.paypal.com/de/business" target="_blank">
                    <?php _e('Mehr über PayPal erfahren', 'yprint-payment'); ?>
                </a>
            </p>
        </div>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="yprint_paypal_enabled"><?php _e('PayPal aktivieren', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="yprint_paypal_enabled" name="yprint_paypal_enabled" value="1" 
                               <?php checked($paypal_enabled, true); ?>>
                        <?php _e('PayPal als Zahlungsmethode aktivieren', 'yprint-payment'); ?>
                    </label>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_paypal_test_mode"><?php _e('Testmodus', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="yprint_paypal_test_mode" name="yprint_paypal_test_mode" value="yes" 
                               <?php checked(get_option('yprint_paypal_test_mode', 'no'), 'yes'); ?>>
                        <?php _e('Testmodus aktivieren (Sandbox)', 'yprint-payment'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Im Testmodus werden keine echten Zahlungen verarbeitet. Verwende PayPal-Sandboxdaten zum Testen.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr class="paypal-api-keys">
                <th scope="row" colspan="2">
                    <h3><?php _e('Produktionsschlüssel (Live)', 'yprint-payment'); ?></h3>
                </th>
            </tr>
            
            <tr class="paypal-api-keys">
                <th scope="row">
                    <label for="yprint_paypal_client_id"><?php _e('Client ID', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_paypal_client_id" name="yprint_paypal_client_id" 
                           value="<?php echo esc_attr(get_option('yprint_paypal_client_id', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr class="paypal-api-keys">
                <th scope="row">
                    <label for="yprint_paypal_secret_key"><?php _e('Secret Key', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="password" id="yprint_paypal_secret_key" name="yprint_paypal_secret_key" 
                           value="<?php echo esc_attr(get_option('yprint_paypal_secret_key', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr class="paypal-api-keys">
                <th scope="row">
                    <label for="yprint_paypal_webhook_id"><?php _e('Webhook ID', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_paypal_webhook_id" name="yprint_paypal_webhook_id" 
                           value="<?php echo esc_attr(get_option('yprint_paypal_webhook_id', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr class="paypal-api-keys">
                <th scope="row" colspan="2">
                    <h3><?php _e('Testschlüssel (Sandbox)', 'yprint-payment'); ?></h3>
                </th>
            </tr>
            
            <tr class="paypal-api-keys">
                <th scope="row">
                    <label for="yprint_paypal_test_client_id"><?php _e('Sandbox Client ID', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_paypal_test_client_id" name="yprint_paypal_test_client_id" 
                           value="<?php echo esc_attr(get_option('yprint_paypal_test_client_id', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr class="paypal-api-keys">
                <th scope="row">
                    <label for="yprint_paypal_test_secret_key"><?php _e('Sandbox Secret Key', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="password" id="yprint_paypal_test_secret_key" name="yprint_paypal_test_secret_key" 
                           value="<?php echo esc_attr(get_option('yprint_paypal_test_secret_key', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr class="paypal-api-keys">
                <th scope="row">
                    <label for="yprint_paypal_test_webhook_id"><?php _e('Sandbox Webhook ID', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_paypal_test_webhook_id" name="yprint_paypal_test_webhook_id" 
                           value="<?php echo esc_attr(get_option('yprint_paypal_test_webhook_id', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label><?php _e('Verbindung testen', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <button type="button" id="yprint_test_paypal_connection" class="button">
                        <?php _e('Verbindung zu PayPal testen', 'yprint-payment'); ?>
                    </button>
                    <span id="yprint_paypal_connection_result"></span>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label><?php _e('Webhook-URL', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <code><?php echo home_url('/?yprint-paypal-webhook=1'); ?></code>
                    <p class="description">
                        <?php _e('Füge diese URL in deinem PayPal-Dashboard unter Einstellungen &rarr; Webhook-Einstellungen hinzu.', 'yprint-payment'); ?>
                    </p>
                    <button type="button" id="yprint_create_paypal_webhook" class="button">
                        <?php _e('Webhook automatisch erstellen', 'yprint-payment'); ?>
                    </button>
                    <span id="yprint_paypal_webhook_result"></span>
                </td>
            </tr>
            
            <tr>
                <th scope="row" colspan="2">
                    <h3><?php _e('PayPal Checkout-Einstellungen', 'yprint-payment'); ?></h3>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_paypal_smart_buttons"><?php _e('Smart Buttons', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="yprint_paypal_smart_buttons" name="yprint_paypal_smart_buttons" value="1" 
                               <?php checked($this->feature_flags->is_enabled('paypal_smart_buttons'), true); ?>>
                        <?php _e('PayPal Smart Payment Buttons aktivieren', 'yprint-payment'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Moderne, anpassbare PayPal-Buttons für ein besseres Checkout-Erlebnis.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_paypal_payment_title"><?php _e('Zahlungstitel', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_paypal_payment_title" name="yprint_paypal_payment_title" 
                           value="<?php echo esc_attr(get_option('yprint_paypal_payment_title', __('PayPal', 'yprint-payment'))); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Der Titel, der den Kunden angezeigt wird.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_paypal_payment_description"><?php _e('Zahlungsbeschreibung', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <textarea id="yprint_paypal_payment_description" name="yprint_paypal_payment_description" 
                              class="large-text" rows="3"><?php echo esc_textarea(get_option('yprint_paypal_payment_description', __('Bezahlen Sie schnell und sicher mit Ihrem PayPal-Konto.', 'yprint-payment'))); ?></textarea>
                    <p class="description">
                        <?php _e('Die Beschreibung, die den Kunden angezeigt wird.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="button" id="yprint_save_paypal_settings" class="button button-primary">
                <?php _e('Einstellungen speichern', 'yprint-payment'); ?>
            </button>
        </p>
        <?php
    }

    /**
     * SEPA-Einstellungen rendern
     */
    public function render_sepa_tab() {
        // Prüfen, ob SEPA aktiviert ist
        $sepa_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('sepa_integration') : true;
        
        ?>
        <h2><?php _e('SEPA-Einstellungen', 'yprint-payment'); ?></h2>
        
        <?php settings_fields('yprint_sepa_settings'); ?>
        <?php do_settings_sections('yprint_sepa_settings'); ?>
        
        <div class="notice notice-info inline">
            <p>
                <?php _e('SEPA (Single Euro Payments Area) ermöglicht es deinen Kunden, per Lastschrift zu bezahlen. Diese Zahlungsmethode ist hauptsächlich in Europa verfügbar.', 'yprint-payment'); ?>
            </p>
        </div>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="yprint_sepa_enabled"><?php _e('SEPA aktivieren', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="yprint_sepa_enabled" name="yprint_sepa_enabled" value="1" 
                               <?php checked($sepa_enabled, true); ?>>
                        <?php _e('SEPA-Lastschrift als Zahlungsmethode aktivieren', 'yprint-payment'); ?>
                    </label>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_sepa_test_mode"><?php _e('Testmodus', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="yprint_sepa_test_mode" name="yprint_sepa_test_mode" value="yes" 
                               <?php checked(get_option('yprint_sepa_test_mode', 'no'), 'yes'); ?>>
                        <?php _e('Testmodus aktivieren', 'yprint-payment'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Im Testmodus werden keine echten Zahlungen verarbeitet.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_sepa_api_key"><?php _e('API-Schlüssel', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_sepa_api_key" name="yprint_sepa_api_key" 
                           value="<?php echo esc_attr(get_option('yprint_sepa_api_key', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_sepa_api_secret"><?php _e('API-Secret', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="password" id="yprint_sepa_api_secret" name="yprint_sepa_api_secret" 
                           value="<?php echo esc_attr(get_option('yprint_sepa_api_secret', 'INSERT_API_KEY_HERE')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_sepa_creditor_id"><?php _e('Gläubiger-ID', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_sepa_creditor_id" name="yprint_sepa_creditor_id" 
                           value="<?php echo esc_attr(get_option('yprint_sepa_creditor_id', '')); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Deine SEPA-Gläubiger-ID (z.B. DE98ZZZ09999999999).', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_sepa_company_name"><?php _e('Firmenname', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_sepa_company_name" name="yprint_sepa_company_name" 
                           value="<?php echo esc_attr(get_option('yprint_sepa_company_name', get_bloginfo('name'))); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Name deines Unternehmens für SEPA-Mandate.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row" colspan="2">
                    <h3><?php _e('SEPA-Checkout-Einstellungen', 'yprint-payment'); ?></h3>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_sepa_mandate_type"><?php _e('Mandatstyp', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <select id="yprint_sepa_mandate_type" name="yprint_sepa_mandate_type">
                        <option value="oneoff" <?php selected(get_option('yprint_sepa_mandate_type', 'oneoff'), 'oneoff'); ?>>
                            <?php _e('Einmalig', 'yprint-payment'); ?>
                        </option>
                        <option value="recurring" <?php selected(get_option('yprint_sepa_mandate_type', 'oneoff'), 'recurring'); ?>>
                            <?php _e('Wiederkehrend', 'yprint-payment'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php _e('Art des SEPA-Mandats.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_sepa_mandate_text"><?php _e('Mandatstext', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <textarea id="yprint_sepa_mandate_text" name="yprint_sepa_mandate_text" 
                              class="large-text" rows="5"><?php echo esc_textarea(get_option('yprint_sepa_mandate_text', __('Ich ermächtige {company}, Zahlungen von meinem Konto mittels SEPA-Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von {company} auf mein Konto gezogenen Lastschriften einzulösen. Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.', 'yprint-payment'))); ?></textarea>
                    <p class="description">
                        <?php _e('Der Text, der dem Kunden angezeigt wird. Verwende {company} als Platzhalter für deinen Firmennamen.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_sepa_payment_title"><?php _e('Zahlungstitel', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_sepa_payment_title" name="yprint_sepa_payment_title" 
                           value="<?php echo esc_attr(get_option('yprint_sepa_payment_title', __('SEPA-Lastschrift', 'yprint-payment'))); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Der Titel, der den Kunden angezeigt wird.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_sepa_payment_description"><?php _e('Zahlungsbeschreibung', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <textarea id="yprint_sepa_payment_description" name="yprint_sepa_payment_description" 
                              class="large-text" rows="3"><?php echo esc_textarea(get_option('yprint_sepa_payment_description', __('Bezahlen Sie bequem per Lastschrift von Ihrem Bankkonto.', 'yprint-payment'))); ?></textarea>
                    <p class="description">
                        <?php _e('Die Beschreibung, die den Kunden angezeigt wird.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="button" id="yprint_save_sepa_settings" class="button button-primary">
                <?php _e('Einstellungen speichern', 'yprint-payment'); ?>
            </button>
        </p>
        <?php
    }

    /**
     * Banküberweisung-Einstellungen rendern
     */
    public function render_bank_transfer_tab() {
        // Prüfen, ob Banküberweisung aktiviert ist
        $bank_transfer_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('bank_transfer') : true;
        
        ?>
        <h2><?php _e('Banküberweisung-Einstellungen', 'yprint-payment'); ?></h2>
        
        <?php settings_fields('yprint_bank_transfer_settings'); ?>
        <?php do_settings_sections('yprint_bank_transfer_settings'); ?>
        
        <div class="notice notice-info inline">
            <p>
                <?php _e('Die Banküberweisung ermöglicht es deinen Kunden, direkt auf dein Bankkonto zu überweisen. Die Bestellung wird erst nach Zahlungseingang bearbeitet.', 'yprint-payment'); ?>
            </p>
        </div>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="yprint_bank_transfer_enabled"><?php _e('Banküberweisung aktivieren', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="yprint_bank_transfer_enabled" name="yprint_bank_transfer_enabled" value="1" 
                               <?php checked($bank_transfer_enabled, true); ?>>
                        <?php _e('Banküberweisung als Zahlungsmethode aktivieren', 'yprint-payment'); ?>
                    </label>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_bank_account_holder"><?php _e('Kontoinhaber', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_bank_account_holder" name="yprint_bank_account_holder" 
                           value="<?php echo esc_attr(get_option('yprint_bank_account_holder', '')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_bank_account_bank"><?php _e('Bank', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_bank_account_bank" name="yprint_bank_account_bank" 
                           value="<?php echo esc_attr(get_option('yprint_bank_account_bank', '')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_bank_account_iban"><?php _e('IBAN', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_bank_account_iban" name="yprint_bank_account_iban" 
                           value="<?php echo esc_attr(get_option('yprint_bank_account_iban', '')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_bank_account_bic"><?php _e('BIC', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_bank_account_bic" name="yprint_bank_account_bic" 
                           value="<?php echo esc_attr(get_option('yprint_bank_account_bic', '')); ?>" 
                           class="regular-text">
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_bank_transfer_instructions"><?php _e('Zahlungsanweisungen', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <textarea id="yprint_bank_transfer_instructions" name="yprint_bank_transfer_instructions" 
                              class="large-text" rows="5"><?php echo esc_textarea(get_option('yprint_bank_transfer_instructions', __('Bitte überweisen Sie den Betrag auf das folgende Konto. Verwenden Sie Ihre Bestellnummer als Verwendungszweck, damit wir Ihre Zahlung zuordnen können.', 'yprint-payment'))); ?></textarea>
                    <p class="description">
                        <?php _e('Anweisungen für die Kunden, wie sie die Überweisung durchführen sollen.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_bank_transfer_payment_title"><?php _e('Zahlungstitel', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="yprint_bank_transfer_payment_title" name="yprint_bank_transfer_payment_title" 
                           value="<?php echo esc_attr(get_option('yprint_bank_transfer_payment_title', __('Banküberweisung', 'yprint-payment'))); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Der Titel, der den Kunden angezeigt wird.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yprint_bank_transfer_payment_description"><?php _e('Zahlungsbeschreibung', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <textarea id="yprint_bank_transfer_payment_description" name="yprint_bank_transfer_payment_description" 
                              class="large-text" rows="3"><?php echo esc_textarea(get_option('yprint_bank_transfer_payment_description', __('Bezahlen Sie per Banküberweisung. Ihre Bestellung wird nach Zahlungseingang bearbeitet.', 'yprint-payment'))); ?></textarea>
                    <p class="description">
                        <?php _e('Die Beschreibung, die den Kunden angezeigt wird.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="button" id="yprint_save_bank_transfer_settings" class="button button-primary">
                <?php _e('Einstellungen speichern', 'yprint-payment'); ?>
            </button>
        </p>
        <?php
    }

    /**
     * Webhooks-Einstellungen rendern
     */
    public function render_webhooks_tab() {
        ?>
        <h2><?php _e('Webhook-Einstellungen', 'yprint-payment'); ?></h2>
        
        <div class="notice notice-info inline">
            <p>
                <?php _e('Webhooks ermöglichen es Zahlungsanbietern, mit deinem Shop zu kommunizieren und Zahlungsstatusaktualisierungen zu senden.', 'yprint-payment'); ?>
            </p>
        </div>
        
        <h3><?php _e('Webhook-URLs', 'yprint-payment'); ?></h3>
        <p><?php _e('Füge diese URLs in den entsprechenden Zahlungsanbieter-Dashboards hinzu:', 'yprint-payment'); ?></p>
        
        <table class="widefat" style="max-width: 800px;">
            <thead>
                <tr>
                    <th><?php _e('Zahlungsanbieter', 'yprint-payment'); ?></th>
                    <th><?php _e('Webhook-URL', 'yprint-payment'); ?></th>
                    <th><?php _e('Status', 'yprint-payment'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Stripe</td>
                    <td><code><?php echo home_url('/?yprint-stripe-webhook=1'); ?></code></td>
                    <td>
                        <?php 
                        $stripe_webhook_id = get_option('yprint_stripe_webhook_secret') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span> ' . __('Konfiguriert', 'yprint-payment') : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span> ' . __('Nicht konfiguriert', 'yprint-payment');
                        echo $stripe_webhook_id;
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>PayPal</td>
                    <td><code><?php echo home_url('/?yprint-paypal-webhook=1'); ?></code></td>
                    <td>
                        <?php 
                        $paypal_webhook_id = get_option('yprint_paypal_webhook_id') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span> ' . __('Konfiguriert', 'yprint-payment') : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span> ' . __('Nicht konfiguriert', 'yprint-payment');
                        echo $paypal_webhook_id;
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>SEPA</td>
                    <td><code><?php echo home_url('/?yprint-sepa-webhook=1'); ?></code></td>
                    <td>
                        <?php 
                        $sepa_webhook_id = get_option('yprint_sepa_webhook_id') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span> ' . __('Konfiguriert', 'yprint-payment') : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span> ' . __('Nicht konfiguriert', 'yprint-payment');
                        echo $sepa_webhook_id;
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <h3><?php _e('Webhook-Ereignisse', 'yprint-payment'); ?></h3>
        <p><?php _e('Das Plugin verarbeitet folgende Webhook-Ereignisse:', 'yprint-payment'); ?></p>
        
        <table class="widefat" style="max-width: 800px;">
            <thead>
                <tr>
                    <th><?php _e('Zahlungsanbieter', 'yprint-payment'); ?></th>
                    <th><?php _e('Ereignistyp', 'yprint-payment'); ?></th>
                    <th><?php _e('Beschreibung', 'yprint-payment'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Stripe</td>
                    <td><code>payment_intent.succeeded</code></td>
                    <td><?php _e('Zahlung erfolgreich', 'yprint-payment'); ?></td>
                </tr>
                <tr>
                    <td>Stripe</td>
                    <td><code>payment_intent.payment_failed</code></td>
                    <td><?php _e('Zahlung fehlgeschlagen', 'yprint-payment'); ?></td>
                </tr>
                <tr>
                    <td>Stripe</td>
                    <td><code>charge.refunded</code></td>
                    <td><?php _e('Zahlung zurückerstattet', 'yprint-payment'); ?></td>
                </tr>
                <tr>
                    <td>PayPal</td>
                    <td><code>PAYMENT.CAPTURE.COMPLETED</code></td>
                    <td><?php _e('Zahlung erfolgreich', 'yprint-payment'); ?></td>
                </tr>
                <tr>
                    <td>PayPal</td>
                    <td><code>PAYMENT.CAPTURE.DENIED</code></td>
                    <td><?php _e('Zahlung abgelehnt', 'yprint-payment'); ?></td>
                </tr>
                <tr>
                    <td>PayPal</td>
                    <td><code>PAYMENT.CAPTURE.REFUNDED</code></td>
                    <td><?php _e('Zahlung zurückerstattet', 'yprint-payment'); ?></td>
                </tr>
                <tr>
                    <td>SEPA</td>
                    <td><code>direct_debit.succeeded</code></td>
                    <td><?php _e('Lastschrift erfolgreich', 'yprint-payment'); ?></td>
                </tr>
                <tr>
                    <td>SEPA</td>
                    <td><code>direct_debit.failed</code></td>
                    <td><?php _e('Lastschrift fehlgeschlagen', 'yprint-payment'); ?></td>
                </tr>
            </tbody>
        </table>
        
        <h3><?php _e('Webhook-Sicherheit', 'yprint-payment'); ?></h3>
        <p><?php _e('Das Plugin verwendet Signaturvalidierung, um die Authentizität von Webhooks zu überprüfen.', 'yprint-payment'); ?></p>
        
        <p class="submit">
            <button type="button" id="yprint_test_webhooks" class="button">
                <?php _e('Webhooks testen', 'yprint-payment'); ?>
            </button>
            <span id="yprint_test_webhooks_result"></span>
        </p>
        <?php
    }

    /**
     * Feature-Flags-Tab rendern
     */
    public function render_features_tab() {
        if (!$this->feature_flags) {
            echo '<div class="notice notice-error"><p>' . __('Feature-Flags-System nicht verfügbar.', 'yprint-payment') . '</p></div>';
            return;
        }
        
        $flags = $this->feature_flags->get_all_flags();
        ?>
        <h2><?php _e('Feature-Flags', 'yprint-payment'); ?></h2>
        
        <div class="notice notice-info inline">
            <p>
                <?php _e('Feature-Flags ermöglichen es, bestimmte Funktionen gezielt zu aktivieren oder zu deaktivieren.', 'yprint-payment'); ?>
            </p>
        </div>
        
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php _e('Feature', 'yprint-payment'); ?></th>
                    <th><?php _e('Beschreibung', 'yprint-payment'); ?></th>
                    <th><?php _e('Status', 'yprint-payment'); ?></th>
                    <th><?php _e('Aktion', 'yprint-payment'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($flags as $flag_name => $enabled) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($flag_name); ?></strong></td>
                        <td><?php echo esc_html($this->get_feature_description($flag_name)); ?></td>
                        <td>
                            <span class="feature-status <?php echo $enabled ? 'enabled' : 'disabled'; ?>">
                                <?php echo $enabled ? __('Aktiviert', 'yprint-payment') : __('Deaktiviert', 'yprint-payment'); ?>
                            </span>
                        </td>
                        <td>
                            <button type="button" class="button toggle-feature" 
                                    data-feature="<?php echo esc_attr($flag_name); ?>" 
                                    data-status="<?php echo $enabled ? '1' : '0'; ?>">
                                <?php echo $enabled ? __('Deaktivieren', 'yprint-payment') : __('Aktivieren', 'yprint-payment'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <p class="submit">
            <button type="button" id="yprint_reset_feature_flags" class="button">
                <?php _e('Zurücksetzen auf Standardwerte', 'yprint-payment'); ?>
            </button>
        </p>
        <?php
    }

    /**
     * Status-Tab rendern
     */
    public function render_status_tab() {
        ?>
        <h2><?php _e('System-Status', 'yprint-payment'); ?></h2>
        
        <h3><?php _e('Plugin-Informationen', 'yprint-payment'); ?></h3>
        <table class="widefat" style="max-width: 600px;">
            <tbody>
                <tr>
                    <td><?php _e('Plugin-Version', 'yprint-payment'); ?></td>
                    <td><?php echo esc_html(YPRINT_PAYMENT_VERSION); ?></td>
                </tr>
                <tr>
                    <td><?php _e('WordPress-Version', 'yprint-payment'); ?></td>
                    <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                </tr>
                <tr>
                    <td><?php _e('WooCommerce-Version', 'yprint-payment'); ?></td>
                    <td><?php echo esc_html(function_exists('WC') ? WC()->version : __('Nicht installiert', 'yprint-payment')); ?></td>
                </tr>
                <tr>
                    <td><?php _e('PHP-Version', 'yprint-payment'); ?></td>
                    <td><?php echo esc_html(phpversion()); ?></td>
                </tr>
                <tr>
                    <td><?php _e('MySQL-Version', 'yprint-payment'); ?></td>
                    <td><?php echo esc_html($this->get_mysql_version()); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Debug-Modus', 'yprint-payment'); ?></td>
                    <td><?php echo defined('WP_DEBUG') && WP_DEBUG ? '<span style="color:green;">' . __('Aktiviert', 'yprint-payment') . '</span>' : '<span style="color:red;">' . __('Deaktiviert', 'yprint-payment') . '</span>'; ?></td>
                </tr>
            </tbody>
        </table>
        
        <h3><?php _e('Zahlungsanbieter-Status', 'yprint-payment'); ?></h3>
        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th><?php _e('Zahlungsanbieter', 'yprint-payment'); ?></th>
                    <th><?php _e('Status', 'yprint-payment'); ?></th>
                    <th><?php _e('Modus', 'yprint-payment'); ?></th>
                    <th><?php _e('Webhook', 'yprint-payment'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Stripe</td>
                    <td>
                        <?php 
                        $stripe_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('stripe_integration') : false;
                        echo $stripe_enabled ? 
                            '<span style="color:green;">' . __('Aktiviert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Deaktiviert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>
                        <?php 
                        $stripe_test_mode = get_option('yprint_stripe_test_mode', 'no') === 'yes';
                        echo $stripe_test_mode ? 
                            '<span style="color:orange;">' . __('Test', 'yprint-payment') . '</span>' : 
                            '<span style="color:green;">' . __('Live', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>
                        <?php 
                        $stripe_webhook = get_option('yprint_stripe_webhook_secret', '');
                        echo !empty($stripe_webhook) ? 
                            '<span style="color:green;">' . __('Konfiguriert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Nicht konfiguriert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>PayPal</td>
                    <td>
                        <?php 
                        $paypal_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('paypal_integration') : false;
                        echo $paypal_enabled ? 
                            '<span style="color:green;">' . __('Aktiviert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Deaktiviert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>
                        <?php 
                        $paypal_test_mode = get_option('yprint_paypal_test_mode', 'no') === 'yes';
                        echo $paypal_test_mode ? 
                            '<span style="color:orange;">' . __('Sandbox', 'yprint-payment') . '</span>' : 
                            '<span style="color:green;">' . __('Live', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>
                        <?php 
                        $paypal_webhook = get_option('yprint_paypal_webhook_id', '');
                        echo !empty($paypal_webhook) ? 
                            '<span style="color:green;">' . __('Konfiguriert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Nicht konfiguriert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>SEPA</td>
                    <td>
                        <?php 
                        $sepa_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('sepa_integration') : false;
                        echo $sepa_enabled ? 
                            '<span style="color:green;">' . __('Aktiviert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Deaktiviert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>
                        <?php 
                        $sepa_test_mode = get_option('yprint_sepa_test_mode', 'no') === 'yes';
                        echo $sepa_test_mode ? 
                            '<span style="color:orange;">' . __('Test', 'yprint-payment') . '</span>' : 
                            '<span style="color:green;">' . __('Live', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>Banküberweisung</td>
                    <td>
                        <?php 
                        $bank_transfer_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('bank_transfer') : false;
                        echo $bank_transfer_enabled ? 
                            '<span style="color:green;">' . __('Aktiviert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Deaktiviert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>-</td>
                    <td>-</td>
                </tr>
            </tbody>
        </table>
        
        <h3><?php _e('Systemanforderungen', 'yprint-payment'); ?></h3>
        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th><?php _e('Anforderung', 'yprint-payment'); ?></th>
                    <th><?php _e('Empfohlen', 'yprint-payment'); ?></th>
                    <th><?php _e('Aktuell', 'yprint-payment'); ?></th>
                    <th><?php _e('Status', 'yprint-payment'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>PHP</td>
                    <td>&ge; 7.4</td>
                    <td><?php echo phpversion(); ?></td>
                    <td>
                        <?php echo version_compare(phpversion(), '7.4', '>=') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td>MySQL</td>
                    <td>&ge; 5.6</td>
                    <td><?php echo $this->get_mysql_version(); ?></td>
                    <td>
                        <?php echo version_compare($this->get_mysql_version(), '5.6', '>=') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td>WordPress</td>
                    <td>&ge; 5.8</td>
                    <td><?php echo get_bloginfo('version'); ?></td>
                    <td>
                        <?php echo version_compare(get_bloginfo('version'), '5.8', '>=') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td>WooCommerce</td>
                    <td>&ge; 6.0</td>
                    <td><?php echo function_exists('WC') ? WC()->version : __('Nicht installiert', 'yprint-payment'); ?></td>
                    <td>
                        <?php 
                        if (!function_exists('WC')) {
                            echo '<span class="dashicons dashicons-no" style="color:red;"></span>';
                        } else {
                            echo version_compare(WC()->version, '6.0', '>=') ? 
                                '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                                '<span class="dashicons dashicons-no" style="color:red;"></span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>cURL</td>
                    <td><?php _e('Aktiviert', 'yprint-payment'); ?></td>
                    <td><?php echo function_exists('curl_version') ? __('Aktiviert', 'yprint-payment') : __('Deaktiviert', 'yprint-payment'); ?></td>
                    <td>
                        <?php echo function_exists('curl_version') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td>JSON</td>
                    <td><?php _e('Aktiviert', 'yprint-payment'); ?></td>
                    <td><?php echo function_exists('json_encode') ? __('Aktiviert', 'yprint-payment') : __('Deaktiviert', 'yprint-payment'); ?></td>
                    <td>
                        <?php echo function_exists('json_encode') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span>'; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <h3><?php _e('Fehlersuche', 'yprint-payment'); ?></h3>
        <p>
            <button type="button" id="yprint_clear_plugin_cache" class="button button-secondary">
                <?php _e('Plugin-Cache leeren', 'yprint-payment'); ?>
            </button>
            <button type="button" id="yprint_run_diagnostics" class="button button-secondary">
                <?php _e('Diagnose ausführen', 'yprint-payment'); ?>
            </button>
        </p>
        
        <div id="yprint_diagnostics_result" style="display: none;">
            <h4><?php _e('Diagnoseergebnisse', 'yprint-payment'); ?></h4>
            <pre></pre>
        </div>
        <?php
    }

    /**
     * Logs-Tab rendern
     */
    public function render_logs_tab() {
        $log_directory = WP_CONTENT_DIR . '/uploads/yprint-payment-logs/';
        $logs = array();
        
        if (is_dir($log_directory)) {
            $files = scandir($log_directory);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'log') {
                    $logs[] = $file;
                }
            }
        }
        
        // Ausgewählte Log-Datei
        $selected_log = isset($_GET['log']) ? sanitize_text_field($_GET['log']) : '';
        $log_content = '';
        
        if ($selected_log && in_array($selected_log, $logs)) {
            $log_path = $log_directory . $selected_log;
            if (file_exists($log_path)) {
                $log_content = file_get_contents($log_path);
            }
        }
        
        ?>
        <h2><?php _e('Log-Dateien', 'yprint-payment'); ?></h2>
        
        <?php if (empty($logs)) : ?>
            <div class="notice notice-info">
                <p><?php _e('Keine Log-Dateien gefunden.', 'yprint-payment'); ?></p>
            </div>
        <?php else : ?>
            <div class="yprint-log-viewer">
                <div class="yprint-log-navigation">
                    <div class="yprint-log-files">
                        <h3><?php _e('Verfügbare Logs', 'yprint-payment'); ?></h3>
                        <ul>
                            <?php foreach ($logs as $log) : ?>
                                <li>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '&tab=logs&log=' . $log)); ?>" 
                                       class="<?php echo $selected_log === $log ? 'active' : ''; ?>">
                                        <?php echo esc_html($log); ?>
                                    </a>
                                    <a href="#" class="yprint-delete-log" data-log="<?php echo esc_attr($log); ?>">[<?php _e('Löschen', 'yprint-payment'); ?>]</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <?php if (!empty($selected_log)) : ?>
                        <div class="yprint-log-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '&tab=logs&log=' . $selected_log . '&download=1')); ?>" class="button">
                                <?php _e('Herunterladen', 'yprint-payment'); ?>
                            </a>
                            <button type="button" id="yprint_refresh_log" class="button" data-log="<?php echo esc_attr($selected_log); ?>">
                                <?php _e('Aktualisieren', 'yprint-payment'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="yprint-log-content">
                    <?php if (!empty($selected_log)) : ?>
                        <h3><?php echo esc_html($selected_log); ?></h3>
                        <div class="yprint-log-viewer-content">
                            <pre><?php echo esc_html($log_content); ?></pre>
                        </div>
                    <?php else : ?>
                        <div class="notice notice-info inline">
                            <p><?php _e('Wähle eine Log-Datei aus der Liste aus, um den Inhalt anzuzeigen.', 'yprint-payment'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <h3><?php _e('Log-Einstellungen', 'yprint-payment'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="yprint_log_level"><?php _e('Log-Level', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <select id="yprint_log_level" name="yprint_log_level">
                        <option value="error" <?php selected(get_option('yprint_log_level', 'error'), 'error'); ?>>
                            <?php _e('Fehler (minimal)', 'yprint-payment'); ?>
                        </option>
                        <option value="warning" <?php selected(get_option('yprint_log_level', 'error'), 'warning'); ?>>
                            <?php _e('Warnungen', 'yprint-payment'); ?>
                        </option>
                        <option value="info" <?php selected(get_option('yprint_log_level', 'error'), 'info'); ?>>
                            <?php _e('Infos', 'yprint-payment'); ?>
                        </option>
                        <option value="debug" <?php selected(get_option('yprint_log_level', 'error'), 'debug'); ?>>
                            <?php _e('Debug (ausführlich)', 'yprint-payment'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php _e('Je höher der Log-Level, desto mehr Informationen werden protokolliert.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="button" id="yprint_save_log_settings" class="button button-primary">
                <?php _e('Log-Einstellungen speichern', 'yprint-payment'); ?>
            </button>
            <button type="button" id="yprint_clear_all_logs" class="button">
                <?php _e('Alle Logs löschen', 'yprint-payment'); ?>
            </button>
        </p>
        <?php
    }

    /**
     * AJAX-Handler zum Speichern der Einstellungen
     */
    public function ajax_save_settings() {
        // Nonce überprüfen
        check_ajax_referer('yprint-admin-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nicht genügend Berechtigungen.', 'yprint-payment')));
            return;
        }
        
        $settings_type = isset($_POST['settings_type']) ? sanitize_text_field($_POST['settings_type']) : '';
        $settings = isset($_POST['settings']) ? $this->sanitize_settings($_POST['settings']) : array();
        
        if (empty($settings_type)) {
            wp_send_json_error(array('message' => __('Ungültiger Einstellungstyp.', 'yprint-payment')));
            return;
        }
        
        // Entsprechende Einstellungen speichern
        switch ($settings_type) {
            case 'general':
                $this->save_general_settings($settings);
                break;
                
            case 'stripe':
                $this->save_stripe_settings($settings);
                break;
                
            case 'paypal':
                $this->save_paypal_settings($settings);
                break;
                
            case 'sepa':
                $this->save_sepa_settings($settings);
                break;
                
            case 'bank_transfer':
                $this->save_bank_transfer_settings($settings);
                break;
                
            case 'logs':
                $this->save_log_settings($settings);
                break;
                
            default:
                wp_send_json_error(array('message' => __('Unbekannter Einstellungstyp.', 'yprint-payment')));
                return;
        }
        
        wp_send_json_success(array('message' => __('Einstellungen erfolgreich gespeichert.', 'yprint-payment')));
    }

    /**
     * Sanitiert die Einstellungen
     *
     * @param array $settings Einstellungen
     * @return array Sanitierte Einstellungen
     */
    private function sanitize_settings($settings) {
        $sanitized = array();
        
        foreach ($settings as $key => $value) {
            // Checkboxen als Boolesche Werte behandeln
            if ($value === 'on' || $value === 'true' || $value === '1') {
                $sanitized[$key] = true;
            } 
            // Arrays rekursiv sanitieren
            elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitize_settings($value);
            } 
            // Verschiedene Sanitierungsfunktionen je nach Feldtyp
            else {
                if (strpos($key, 'description') !== false || strpos($key, 'instructions') !== false || strpos($key, 'text') !== false) {
                    $sanitized[$key] = wp_kses_post($value);
                } elseif (strpos($key, 'email') !== false) {
                    $sanitized[$key] = sanitize_email($value);
                } elseif (strpos($key, 'url') !== false) {
                    $sanitized[$key] = esc_url_raw($value);
                } else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            }
        }
        
        return $sanitized;
    }

    /**
     * Speichert die allgemeinen Einstellungen
     *
     * @param array $settings Einstellungen
     */
    private function save_general_settings($settings) {
        if (isset($settings['yprint_shop_name'])) {
            update_option('yprint_shop_name', $settings['yprint_shop_name']);
        }
        
        if (isset($settings['yprint_thank_you_page'])) {
            update_option('yprint_thank_you_page', intval($settings['yprint_thank_you_page']));
        }
        
        if (isset($settings['yprint_debug_mode'])) {
            update_option('yprint_debug_mode', $settings['yprint_debug_mode'] ? '1' : '0');
            
            // Feature-Flag aktualisieren
            if ($this->feature_flags) {
                if ($settings['yprint_debug_mode']) {
                    $this->feature_flags->enable('debug_mode');
                } else {
                    $this->feature_flags->disable('debug_mode');
                }
            }
        }
        
        if (isset($settings['yprint_log_retention'])) {
            update_option('yprint_log_retention', $settings['yprint_log_retention']);
        }
    }

    /**
     * Speichert die Stripe-Einstellungen
     *
     * @param array $settings Einstellungen
     */
    private function save_stripe_settings($settings) {
        // Aktivierungsstatus
        if (isset($settings['yprint_stripe_enabled']) && $this->feature_flags) {
            if ($settings['yprint_stripe_enabled']) {
                $this->feature_flags->enable('stripe_integration');
            } else {
                $this->feature_flags->disable('stripe_integration');
            }
        }
        
        // Testmodus
        if (isset($settings['yprint_stripe_test_mode'])) {
            update_option('yprint_stripe_test_mode', $settings['yprint_stripe_test_mode'] ? 'yes' : 'no');
        }
        
        // API-Schlüssel
        $api_keys = array(
            'yprint_stripe_public_key',
            'yprint_stripe_secret_key',
            'yprint_stripe_webhook_secret',
            'yprint_stripe_test_public_key',
            'yprint_stripe_test_secret_key',
            'yprint_stripe_test_webhook_secret'
        );
        
        foreach ($api_keys as $key) {
            if (isset($settings[$key])) {
                update_option($key, $settings[$key]);
            }
        }
        
        // SCA-Unterstützung
        if (isset($settings['yprint_stripe_sca_support']) && $this->feature_flags) {
            if ($settings['yprint_stripe_sca_support']) {
                $this->feature_flags->enable('stripe_sca_support');
            } else {
                $this->feature_flags->disable('stripe_sca_support');
            }
        }
        
        // Zahlungstitel und -beschreibung
        if (isset($settings['yprint_stripe_payment_title'])) {
            update_option('yprint_stripe_payment_title', $settings['yprint_stripe_payment_title']);
        }
        
        if (isset($settings['yprint_stripe_payment_description'])) {
            update_option('yprint_stripe_payment_description', $settings['yprint_stripe_payment_description']);
        }
    }

    /**
     * Speichert die PayPal-Einstellungen
     *
     * @param array $settings Einstellungen
     */
    private function save_paypal_settings($settings) {
        // Aktivierungsstatus
        if (isset($settings['yprint_paypal_enabled']) && $this->feature_flags) {
            if ($settings['yprint_paypal_enabled']) {
                $this->feature_flags->enable('paypal_integration');
            } else {
                $this->feature_flags->disable('paypal_integration');
            }
        }
        
        // Testmodus
        if (isset($settings['yprint_paypal_test_mode'])) {
            update_option('yprint_paypal_test_mode', $settings['yprint_paypal_test_mode'] ? 'yes' : 'no');
        }
        
        // API-Schlüssel
        $api_keys = array(
            'yprint_paypal_client_id',
            'yprint_paypal_secret_key',
            'yprint_paypal_webhook_id',
            'yprint_paypal_test_client_id',
            'yprint_paypal_test_secret_key',
            'yprint_paypal_test_webhook_id'
        );
        
        foreach ($api_keys as $key) {
            if (isset($settings[$key])) {
                update_option($key, $settings[$key]);
            }
        }
        
        // Smart Buttons
        if (isset($settings['yprint_paypal_smart_buttons']) && $this->feature_flags) {
            if ($settings['yprint_paypal_smart_buttons']) {
                $this->feature_flags->enable('paypal_smart_buttons');
            } else {
                $this->feature_flags->disable('paypal_smart_buttons');
            }
        }
        
        // Zahlungstitel und -beschreibung
        if (isset($settings['yprint_paypal_payment_title'])) {
            update_option('yprint_paypal_payment_title', $settings['yprint_paypal_payment_title']);
        }
        
        if (isset($settings['yprint_paypal_payment_description'])) {
            update_option('yprint_paypal_payment_description', $settings['yprint_paypal_payment_description']);
        }
    }

    /**
     * Speichert die SEPA-Einstellungen
     *
     * @param array $settings Einstellungen
     */
    private function save_sepa_settings($settings) {
        // Aktivierungsstatus
        if (isset($settings['yprint_sepa_enabled']) && $this->feature_flags) {
            if ($settings['yprint_sepa_enabled']) {
                $this->feature_flags->enable('sepa_integration');
            } else {
                $this->feature_flags->disable('sepa_integration');
            }
        }
        
        // Testmodus
        if (isset($settings['yprint_sepa_test_mode'])) {
            update_option('yprint_sepa_test_mode', $settings['yprint_sepa_test_mode'] ? 'yes' : 'no');
        }
        
        // API-Schlüssel und Gläubiger-ID
        $fields = array(
            'yprint_sepa_api_key',
            'yprint_sepa_api_secret',
            'yprint_sepa_creditor_id',
            'yprint_sepa_company_name',
            'yprint_sepa_mandate_type',
            'yprint_sepa_mandate_text',
            'yprint_sepa_payment_title',
            'yprint_sepa_payment_description'
        );
        
        foreach ($fields as $field) {
            if (isset($settings[$field])) {
                update_option($field, $settings[$field]);
            }
        }
    }

    /**
     * Speichert die Banküberweisung-Einstellungen
     *
     * @param array $settings Einstellungen
     */
    private function save_bank_transfer_settings($settings) {
        // Aktivierungsstatus
        if (isset($settings['yprint_bank_transfer_enabled']) && $this->feature_flags) {
            if ($settings['yprint_bank_transfer_enabled']) {
                $this->feature_flags->enable('bank_transfer');
            } else {
                $this->feature_flags->disable('bank_transfer');
            }
        }
        
        // Bankdaten
        $fields = array(
            'yprint_bank_account_holder',
            'yprint_bank_account_bank',
            'yprint_bank_account_iban',
            'yprint_bank_account_bic',
            'yprint_bank_transfer_instructions',
            'yprint_bank_transfer_payment_title',
            'yprint_bank_transfer_payment_description'
        );
        
        foreach ($fields as $field) {
            if (isset($settings[$field])) {
                update_option($field, $settings[$field]);
            }
        }
    }

    /**
     * Speichert die Log-Einstellungen
     *
     * @param array $settings Einstellungen
     */
    private function save_log_settings($settings) {
        if (isset($settings['yprint_log_level'])) {
            update_option('yprint_log_level', $settings['yprint_log_level']);
        }
    }

    /**
     * AJAX-Handler zum Testen der Stripe-Verbindung
     */
    public function ajax_test_stripe_connection() {
        // Nonce überprüfen
        check_ajax_referer('yprint-admin-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nicht genügend Berechtigungen.', 'yprint-payment')));
            return;
        }
        
        // Prüfen, ob die API-Klasse verfügbar ist
        if (!$this->api) {
            wp_send_json_error(array('message' => __('API-Klasse nicht verfügbar.', 'yprint-payment')));
            return;
        }
        
        // Stripe-Account prüfen
        $result = $this->api->check_stripe_account();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }
        
        wp_send_json_success(array(
            'message' => __('Verbindung zu Stripe erfolgreich hergestellt.', 'yprint-payment')
        ));
    }

    /**
     * AJAX-Handler zum Testen der PayPal-Verbindung
     */
    public function ajax_test_paypal_connection() {
        // Nonce überprüfen
        check_ajax_referer('yprint-admin-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nicht genügend Berechtigungen.', 'yprint-payment')));
            return;
        }
        
        // Prüfen, ob die API-Klasse verfügbar ist
        if (!$this->api) {
            wp_send_json_error(array('message' => __('API-Klasse nicht verfügbar.', 'yprint-payment')));
            return;
        }
        
        // PayPal-Account prüfen
        $result = $this->api->check_paypal_account();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }
        
        wp_send_json_success(array(
            'message' => __('Verbindung zu PayPal erfolgreich hergestellt.', 'yprint-payment')
        ));
    }

    /**
     * AJAX-Handler zum Erstellen eines Webhooks
     */
    public function ajax_create_webhook() {
        // Nonce überprüfen
        check_ajax_referer('yprint-admin-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nicht genügend Berechtigungen.', 'yprint-payment')));
            return;
        }
        
        // Prüfen, ob die API-Klasse verfügbar ist
        if (!$this->api) {
            wp_send_json_error(array('message' => __('API-Klasse nicht verfügbar.', 'yprint-payment')));
            return;
        }
        
        $gateway = isset($_POST['gateway']) ? sanitize_text_field($_POST['gateway']) : '';
        
        if (!in_array($gateway, array('stripe', 'paypal'))) {
            wp_send_json_error(array('message' => __('Ungültiger Gateway-Typ.', 'yprint-payment')));
            return;
        }
        
        // Webhook-URL erstellen
        $webhook_url = home_url('/?yprint-' . $gateway . '-webhook=1');
        
        // Webhook erstellen
        if ($gateway === 'stripe') {
            $result = $this->api->create_stripe_webhook($webhook_url);
        } else {
            $result = $this->api->create_paypal_webhook($webhook_url);
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }
        
        wp_send_json_success(array(
            'message' => sprintf(__('Webhook für %s erfolgreich erstellt.', 'yprint-payment'), ucfirst($gateway))
        ));
    }

    /**
     * AJAX-Handler zum Umschalten von Feature-Flags
     */
    public function ajax_toggle_feature_flag() {
        // Nonce überprüfen
        check_ajax_referer('yprint-admin-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nicht genügend Berechtigungen.', 'yprint-payment')));
            return;
        }
        
        // Prüfen, ob Feature-Flags verfügbar sind
        if (!$this->feature_flags) {
            wp_send_json_error(array('message' => __('Feature-Flag-System nicht verfügbar.', 'yprint-payment')));
            return;
        }
        
        $flag_name = isset($_POST['flag_name']) ? sanitize_text_field($_POST['flag_name']) : '';
        $enable = isset($_POST['enable']) ? (bool) $_POST['enable'] : false;
        
        if (empty($flag_name)) {
            wp_send_json_error(array('message' => __('Kein Feature-Flag angegeben.', 'yprint-payment')));
            return;
        }
        
        // Flag umschalten
        if ($enable) {
            $result = $this->feature_flags->enable($flag_name);
        } else {
            $result = $this->feature_flags->disable($flag_name);
        }
        
        if (!$result) {
            wp_send_json_error(array(
                'message' => sprintf(__('Feature-Flag "%s" konnte nicht umgeschaltet werden.', 'yprint-payment'), $flag_name)
            ));
            return;
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Feature-Flag "%s" erfolgreich %s.', 'yprint-payment'), 
                $flag_name, 
                $enable ? __('aktiviert', 'yprint-payment') : __('deaktiviert', 'yprint-payment')
            )
        ));
    }

    /**
     * Fügt Plugin-Aktionslinks hinzu
     *
     * @param array $links Plugin-Aktionslinks
     * @return array Modifizierte Links
     */
    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=' . $this->menu_slug) . '">' . __('Einstellungen', 'yprint-payment') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Zeigt Admin-Benachrichtigungen an
     */
    public function admin_notices() {
        // Warnung anzeigen, wenn API-Schlüssel fehlen
        $stripe_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('stripe_integration') : false;
        $paypal_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('paypal_integration') : false;
        $sepa_enabled = $this->feature_flags ? $this->feature_flags->is_enabled('sepa_integration') : false;
        
        // Stripe-Schlüssel überprüfen
        if ($stripe_enabled) {
            $test_mode = get_option('yprint_stripe_test_mode', 'no') === 'yes';
            $key_option = $test_mode ? 'yprint_stripe_test_secret_key' : 'yprint_stripe_secret_key';
            $stripe_key = get_option($key_option, '');
            
            if (empty($stripe_key) || $stripe_key === 'INSERT_API_KEY_HERE') {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <?php printf(
                            __('YPrint Payment: Stripe ist aktiviert, aber %s fehlt. <a href="%s">Jetzt konfigurieren</a>', 'yprint-payment'),
                            $test_mode ? __('der Test-Secret-Key', 'yprint-payment') : __('der Secret-Key', 'yprint-payment'),
                            admin_url('admin.php?page=' . $this->menu_slug . '&tab=stripe')
                        ); ?>
                    </p>
                </div>
                <?php
            }
        }
        
        // PayPal-Schlüssel überprüfen
        if ($paypal_enabled) {
            $test_mode = get_option('yprint_paypal_test_mode', 'no') === 'yes';
            $key_option = $test_mode ? 'yprint_paypal_test_client_id' : 'yprint_paypal_client_id';
            $paypal_key = get_option($key_option, '');
            
            if (empty($paypal_key) || $paypal_key === 'INSERT_API_KEY_HERE') {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <?php printf(
                            __('YPrint Payment: PayPal ist aktiviert, aber %s fehlt. <a href="%s">Jetzt konfigurieren</a>', 'yprint-payment'),
                            $test_mode ? __('die Sandbox-Client-ID', 'yprint-payment') : __('die Client-ID', 'yprint-payment'),
                            admin_url('admin.php?page=' . $this->menu_slug . '&tab=paypal')
                        ); ?>
                    </p>
                </div>
                <?php
            }
        }
        
        // SEPA-Schlüssel überprüfen
        if ($sepa_enabled) {
            $sepa_creditor_id = get_option('yprint_sepa_creditor_id', '');
            
            if (empty($sepa_creditor_id)) {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <?php printf(
                            __('YPrint Payment: SEPA ist aktiviert, aber die Gläubiger-ID fehlt. <a href="%s">Jetzt konfigurieren</a>', 'yprint-payment'),
                            admin_url('admin.php?page=' . $this->menu_slug . '&tab=sepa')
                        ); ?>
                    </p>
                </div>
                <?php
            }
        }
    }

    /**
     * Registriert die Einstellungen
     */
    public function register_settings() {
        // Allgemeine Einstellungen
        register_setting('yprint_general_settings', 'yprint_shop_name');
        register_setting('yprint_general_settings', 'yprint_thank_you_page', 'intval');
        register_setting('yprint_general_settings', 'yprint_debug_mode');
        register_setting('yprint_general_settings', 'yprint_log_retention');
        
        // Stripe-Einstellungen
        register_setting('yprint_stripe_settings', 'yprint_stripe_test_mode');
        register_setting('yprint_stripe_settings', 'yprint_stripe_public_key');
        register_setting('yprint_stripe_settings', 'yprint_stripe_secret_key');
        register_setting('yprint_stripe_settings', 'yprint_stripe_webhook_secret');
        register_setting('yprint_stripe_settings', 'yprint_stripe_test_public_key');
        register_setting('yprint_stripe_settings', 'yprint_stripe_test_secret_key');
        register_setting('yprint_stripe_settings', 'yprint_stripe_test_webhook_secret');
        register_setting('yprint_stripe_settings', 'yprint_stripe_payment_title');
        register_setting('yprint_stripe_settings', 'yprint_stripe_payment_description');
        
        // PayPal-Einstellungen
        register_setting('yprint_paypal_settings', 'yprint_paypal_test_mode');
        register_setting('yprint_paypal_settings', 'yprint_paypal_client_id');
        register_setting('yprint_paypal_settings', 'yprint_paypal_secret_key');
        register_setting('yprint_paypal_settings', 'yprint_paypal_webhook_id');
        register_setting('yprint_paypal_settings', 'yprint_paypal_test_client_id');
        register_setting('yprint_paypal_settings', 'yprint_paypal_test_secret_key');
        register_setting('yprint_paypal_settings', 'yprint_paypal_test_webhook_id');
        register_setting('yprint_paypal_settings', 'yprint_paypal_payment_title');
        register_setting('yprint_paypal_settings', 'yprint_paypal_payment_description');
        
        // SEPA-Einstellungen
        register_setting('yprint_sepa_settings', 'yprint_sepa_test_mode');
        register_setting('yprint_sepa_settings', 'yprint_sepa_api_key');
        register_setting('yprint_sepa_settings', 'yprint_sepa_api_secret');
        register_setting('yprint_sepa_settings', 'yprint_sepa_creditor_id');
        register_setting('yprint_sepa_settings', 'yprint_sepa_company_name');
        register_setting('yprint_sepa_settings', 'yprint_sepa_mandate_type');
        register_setting('yprint_sepa_settings', 'yprint_sepa_mandate_text');
        register_setting('yprint_sepa_settings', 'yprint_sepa_payment_title');
        register_setting('yprint_sepa_settings', 'yprint_sepa_payment_description');
        
        // Banküberweisung-Einstellungen
        register_setting('yprint_bank_transfer_settings', 'yprint_bank_account_holder');
        register_setting('yprint_bank_transfer_settings', 'yprint_bank_account_bank');
        register_setting('yprint_bank_transfer_settings', 'yprint_bank_account_iban');
        register_setting('yprint_bank_transfer_settings', 'yprint_bank_account_bic');
        register_setting('yprint_bank_transfer_settings', 'yprint_bank_transfer_instructions');
        register_setting('yprint_bank_transfer_settings', 'yprint_bank_transfer_payment_title');
        register_setting('yprint_bank_transfer_settings', 'yprint_bank_transfer_payment_description');
        
        // Log-Einstellungen
        register_setting('yprint_log_settings', 'yprint_log_level');
    }

    /**
     * Gibt die MySQL-Version zurück
     *
     * @return string MySQL-Version
     */
    private function get_mysql_version() {
        global $wpdb;
        $mysql_version = $wpdb->get_var('SELECT VERSION()');
        return $mysql_version;
    }

    /**
     * Gibt die Feature-Beschreibung zurück
     *
     * @param string $flag_name Feature-Flag-Name
     * @return string Beschreibung
     */
    private function get_feature_description($flag_name) {
        $descriptions = array(
            // Stripe-bezogene Features
            'stripe_integration' => __('Aktiviert die Stripe-Zahlungsmethode', 'yprint-payment'),
            'stripe_sca_support' => __('Aktiviert Strong Customer Authentication (SCA) für EU-Zahlungen', 'yprint-payment'),
            'stripe_upe_enabled' => __('Aktiviert das Unified Payment Element (neue Stripe-UI)', 'yprint-payment'),
            'stripe_webhooks' => __('Aktiviert Webhook-Unterstützung für Stripe', 'yprint-payment'),
            
            // PayPal-bezogene Features
            'paypal_integration' => __('Aktiviert die PayPal-Zahlungsmethode', 'yprint-payment'),
            'paypal_smart_buttons' => __('Aktiviert Smart Payment Buttons für PayPal', 'yprint-payment'),
            'paypal_webhooks' => __('Aktiviert Webhook-Unterstützung für PayPal', 'yprint-payment'),
            
            // SEPA-bezogene Features
            'sepa_integration' => __('Aktiviert die SEPA-Lastschrift-Zahlungsmethode', 'yprint-payment'),
            'sepa_instant_validation' => __('Aktiviert sofortige IBAN-Validierung', 'yprint-payment'),
            
            // Weitere Zahlungsfeatures
            'bank_transfer' => __('Aktiviert die Banküberweisung-Zahlungsmethode', 'yprint-payment'),
            'klarna_integration' => __('Aktiviert die Klarna-Zahlungsmethode (zukünftig)', 'yprint-payment'),
            'sofort_integration' => __('Aktiviert die Sofort-Zahlungsmethode (zukünftig)', 'yprint-payment'),
            
            // UI-Features
            'responsive_checkout' => __('Aktiviert den responsiven Checkout', 'yprint-payment'),
            'enhanced_validation' => __('Aktiviert verbesserte Formularvalidierung', 'yprint-payment'),
            'address_autofill' => __('Aktiviert die automatische Adressvervollständigung', 'yprint-payment'),
            
            // Admin-Features
            'advanced_reporting' => __('Aktiviert erweiterte Berichterstellung', 'yprint-payment'),
            'transaction_logs' => __('Aktiviert Transaktionsprotokolle', 'yprint-payment'),
            'debug_mode' => __('Aktiviert den Debug-Modus mit ausführlicher Protokollierung', 'yprint-payment'),
            
            // Sicherheitsfeatures
            'fraud_detection' => __('Aktiviert Betrugserkennung', 'yprint-payment'),
            'captcha_protection' => __('Aktiviert CAPTCHA-Schutz für Checkout', 'yprint-payment'),
            
            // Performance-Features
            'ajax_cart_updates' => __('Aktiviert AJAX-Warenkorb-Updates', 'yprint-payment'),
            'lazy_loading' => __('Aktiviert Lazy Loading von Ressourcen', 'yprint-payment'),
        );
        
        return isset($descriptions[$flag_name]) ? $descriptions[$flag_name] : __('Keine Beschreibung verfügbar', 'yprint-payment');
    }
}

// Hauptinstanz von YPrint_Admin
function YPrint_Admin() {
    return YPrint_Admin::instance();
}

// Globale für Abwärtskompatibilität
$GLOBALS['yprint_admin'] = YPrint_Admin();