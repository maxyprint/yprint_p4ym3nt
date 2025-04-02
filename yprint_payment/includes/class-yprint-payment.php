<?php
/**
 * Hauptklasse des YPrint Payment Plugins
 *
 * Diese Klasse dient als Kernkomponente des Plugins und steuert den Ablauf
 * aller Funktionen, initialisiertWooCommerce-Integrationen und lädt alle
 * erforderlichen Abhängigkeiten.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hauptklasse des YPrint Payment Plugins
 */
class YPrint_Payment {
    /**
     * Einzelne Instanz des Plugins
     *
     * @var YPrint_Payment
     */
    protected static $_instance = null;

    /**
     * Verfügbare Zahlungsgateways
     *
     * @var array
     */
    public $gateways = array();

    /**
     * Session-Handler Instanz
     *
     * @var YPrint_Session
     */
    public $session = null;

    /**
     * Feature-Flags-Handler Instanz
     *
     * @var YPrint_Feature_Flags
     */
    public $feature_flags = null;

    /**
     * Gibt die Hauptinstanz des Plugins zurück
     *
     * @return YPrint_Payment
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Konstruktor.
     * Initialisiert das Plugin und lädt alle erforderlichen Komponenten.
     */
    public function __construct() {
        // Prüfen, ob WooCommerce aktiv ist
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        $this->init_hooks();
        $this->load_dependencies();
        $this->init_gateways();

        do_action('yprint_payment_initialized');
    }

    /**
     * Hooks initialisieren
     */
    private function init_hooks() {
        // AJAX-Actions für Frontend-Anfragen
        add_action('wp_ajax_yprint_get_checkout_state', array($this, 'ajax_get_checkout_state'));
        add_action('wp_ajax_nopriv_yprint_get_checkout_state', array($this, 'ajax_get_checkout_state'));

        add_action('wp_ajax_yprint_save_checkout_state', array($this, 'ajax_save_checkout_state'));
        add_action('wp_ajax_nopriv_yprint_save_checkout_state', array($this, 'ajax_save_checkout_state'));

        add_action('wp_ajax_yprint_update_cart_quantity', array($this, 'ajax_update_cart_quantity'));
        add_action('wp_ajax_nopriv_yprint_update_cart_quantity', array($this, 'ajax_update_cart_quantity'));

        add_action('wp_ajax_yprint_remove_from_cart', array($this, 'ajax_remove_from_cart'));
        add_action('wp_ajax_nopriv_yprint_remove_from_cart', array($this, 'ajax_remove_from_cart'));

        add_action('wp_ajax_yprint_apply_coupon', array($this, 'ajax_apply_coupon'));
        add_action('wp_ajax_nopriv_yprint_apply_coupon', array($this, 'ajax_apply_coupon'));

        add_action('wp_ajax_yprint_prepare_order', array($this, 'ajax_prepare_order'));
        add_action('wp_ajax_nopriv_yprint_prepare_order', array($this, 'ajax_prepare_order'));

        add_action('wp_ajax_yprint_finalize_order', array($this, 'ajax_finalize_order'));
        add_action('wp_ajax_nopriv_yprint_finalize_order', array($this, 'ajax_finalize_order'));

        // Assets und Scripts laden
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Shortcodes registrieren
        $this->register_shortcodes();

        // Danke-Seiten-Anpassungen
        add_filter('the_title', array($this, 'customize_order_received_page'), 10, 2);
        add_filter('the_content', array($this, 'replace_thankyou_content'), 1);
        add_action('template_redirect', array($this, 'remove_woocommerce_order_details_table'));
    }

    /**
     * Abhängigkeiten laden
     */
    private function load_dependencies() {
        // Feature-Flags Manager initialisieren
        $this->feature_flags = YPrint_Feature_Flags::instance();
        
        // Session-Handler initialisieren
        $this->session = YPrint_Session::instance();
        
        // API Handler laden
        require_once YPRINT_PAYMENT_ABSPATH . 'includes/class-yprint-api.php';
        YPrint_API::instance();
    }

    /**
     * Zahlungsgateways initialisieren
     */
    private function init_gateways() {
        $this->gateways = array(
            'stripe'        => YPrint_Stripe::instance(),
            'paypal'        => YPrint_PayPal::instance(),
            'sepa'          => YPrint_SEPA::instance(),
            'bank_transfer' => YPrint_Bank_Transfer::instance()
        );

        // Gateway-Hooks initialisieren
        foreach ($this->gateways as $gateway) {
            if (method_exists($gateway, 'init_hooks')) {
                $gateway->init_hooks();
            }
        }
    }

    /**
     * Benachrichtigung, wenn WooCommerce fehlt
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('YPrint Payment benötigt WooCommerce, um zu funktionieren. Bitte installieren und aktivieren Sie WooCommerce.', 'yprint-payment'); ?></p>
        </div>
        <?php
    }

    /**
     * Frontend-Scripts und -Styles einbinden
     */
    public function enqueue_frontend_scripts() {
        // Nur auf der Checkout-Seite laden
        if (is_checkout() || has_shortcode(get_post()->post_content, 'yprint_checkout')) {
            // Hauptstylesheets
            wp_enqueue_style(
                'yprint-payment',
                YPRINT_PAYMENT_URL . 'assets/css/yprint-payment.css',
                array(),
                YPRINT_PAYMENT_VERSION
            );
            
            wp_enqueue_style(
                'yprint-checkout',
                YPRINT_PAYMENT_URL . 'assets/css/yprint-checkout.css',
                array('yprint-payment'),
                YPRINT_PAYMENT_VERSION
            );
            
            // Haupt-JavaScript
            wp_enqueue_script(
                'yprint-checkout',
                YPRINT_PAYMENT_URL . 'assets/js/yprint-checkout.js',
                array('jquery'),
                YPRINT_PAYMENT_VERSION,
                true
            );
            
            // Validierungs-JavaScript
            wp_enqueue_script(
                'yprint-validation',
                YPRINT_PAYMENT_URL . 'assets/js/yprint-validation.js',
                array('jquery', 'yprint-checkout'),
                YPRINT_PAYMENT_VERSION,
                true
            );
            
            // Gateway-spezifische Scripts
            if ($this->feature_flags->is_enabled('stripe_integration')) {
                wp_enqueue_script(
                    'stripe-js',
                    'https://js.stripe.com/v3/',
                    array(),
                    null,
                    true
                );
                
                wp_enqueue_script(
                    'yprint-stripe',
                    YPRINT_PAYMENT_URL . 'assets/js/yprint-stripe.js',
                    array('jquery', 'yprint-checkout', 'stripe-js'),
                    YPRINT_PAYMENT_VERSION,
                    true
                );
            }
            
            if ($this->feature_flags->is_enabled('paypal_integration')) {
                $paypal_client_id = get_option('yprint_paypal_client_id', 'INSERT_API_KEY_HERE');
                
                wp_enqueue_script(
                    'paypal-js',
                    'https://www.paypal.com/sdk/js?client-id=' . $paypal_client_id . '&currency=EUR&intent=capture',
                    array(),
                    null,
                    true
                );
                
                wp_enqueue_script(
                    'yprint-paypal',
                    YPRINT_PAYMENT_URL . 'assets/js/yprint-paypal.js',
                    array('jquery', 'yprint-checkout', 'paypal-js'),
                    YPRINT_PAYMENT_VERSION,
                    true
                );
            }
            
            // Lokalisierung für JavaScript
            wp_localize_script('yprint-checkout', 'yprint_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'checkout_nonce' => wp_create_nonce('yprint-checkout-nonce'),
                'is_user_logged_in' => is_user_logged_in(),
                'currency_symbol' => get_woocommerce_currency_symbol(),
                'stripe_enabled' => $this->feature_flags->is_enabled('stripe_integration'),
                'paypal_enabled' => $this->feature_flags->is_enabled('paypal_integration'),
                'sepa_enabled' => $this->feature_flags->is_enabled('sepa_integration'),
                'checkout_error' => __('Es ist ein Fehler aufgetreten. Bitte überprüfen Sie Ihre Eingaben.', 'yprint-payment'),
                'empty_cart_message' => __('Ihr Warenkorb ist leer.', 'yprint-payment'),
                'thank_you_url' => home_url('/thank-you/')
            ));
        }
    }

    /**
     * Admin-Scripts und -Styles einbinden
     */
    public function enqueue_admin_scripts($hook) {
        // Nur auf der Plugin-Einstellungsseite laden
        if ($hook != 'settings_page_yprint-payment-settings') {
            return;
        }
        
        wp_enqueue_style(
            'yprint-admin',
            YPRINT_PAYMENT_URL . 'admin/assets/css/yprint-admin.css',
            array(),
            YPRINT_PAYMENT_VERSION
        );
        
        wp_enqueue_script(
            'yprint-admin',
            YPRINT_PAYMENT_URL . 'admin/assets/js/yprint-admin.js',
            array('jquery'),
            YPRINT_PAYMENT_VERSION,
            true
        );
    }

    /**
     * Shortcodes registrieren
     */
    private function register_shortcodes() {
        add_shortcode('yprint_checkout', array($this, 'checkout_shortcode'));
        add_shortcode('yprint_checkout_communication', array($this, 'checkout_communication_shortcode'));
        add_shortcode('yprint_shipping_address', array($this, 'shipping_address_shortcode'));
        add_shortcode('yprint_payment_options', array($this, 'payment_options_shortcode'));
        add_shortcode('yprint_order_summary', array($this, 'order_summary_shortcode'));
        add_shortcode('yprint_coupon_buy', array($this, 'coupon_buy_shortcode'));
        add_shortcode('yprint_different_billing', array($this, 'different_billing_shortcode'));
        add_shortcode('thankyou_redirect', array($this, 'thankyou_redirect_shortcode'));
    }

    /**
     * AJAX-Handler für initiale Checkout-Daten
     */
    public function ajax_get_checkout_state() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (function_exists('WC')) {
            // Kundendaten abrufen
            if ($user_id) {
                $customer = new WC_Customer($user_id);
                
                // Shipping Address
                $shipping = array(
                    'first_name' => $customer->get_shipping_first_name(),
                    'last_name' => $customer->get_shipping_last_name(),
                    'address_1' => $customer->get_shipping_address_1(),
                    'address_2' => $customer->get_shipping_address_2(),
                    'postcode' => $customer->get_shipping_postcode(),
                    'city' => $customer->get_shipping_city(),
                    'country' => $customer->get_shipping_country() ?: 'DE'
                );
                
                // Billing Address
                $billing = array(
                    'first_name' => $customer->get_billing_first_name(),
                    'last_name' => $customer->get_billing_last_name(),
                    'email' => $customer->get_billing_email(),
                    'address_1' => $customer->get_billing_address_1(),
                    'address_2' => $customer->get_billing_address_2(),
                    'postcode' => $customer->get_billing_postcode(),
                    'city' => $customer->get_billing_city(),
                    'country' => $customer->get_billing_country() ?: 'DE'
                );
                
                // State zusammenbauen
                $state = array(
                    'shippingAddress' => $shipping,
                    'billingAddress' => $billing,
                    'differentBilling' => array(
                        'enabled' => get_user_meta($user_id, 'different_billing_enabled', true) ?: false
                    ),
                    'paymentMethod' => array(
                        'method' => WC()->session ? WC()->session->get('chosen_payment_method') : ''
                    ),
                    'couponCode' => array(
                        'code' => ''
                    )
                );
            } else {
                // Fallback für nicht eingeloggte Benutzer
                $state = array(
                    'shippingAddress' => array('country' => 'DE'),
                    'billingAddress' => array('country' => 'DE'),
                    'differentBilling' => array('enabled' => false),
                    'paymentMethod' => array('method' => ''),
                    'couponCode' => array('code' => '')
                );
            }
            
            // Session-Daten überschreiben falls vorhanden
            if (WC()->session) {
                $session_state = WC()->session->get('yprint_checkout_state');
                if ($session_state) {
                    $state = wp_parse_args($session_state, $state);
                }
            }
            
            wp_send_json_success($state);
        } else {
            wp_send_json_error('WooCommerce ist nicht aktiviert');
        }
    }

    /**
     * AJAX-Handler für State-Speicherung
     */
    public function ajax_save_checkout_state() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (isset($_POST['state'])) {
            $state = $_POST['state'];
            $user_id = get_current_user_id();
            
            // State in user_meta speichern falls Benutzer eingeloggt ist
            if ($user_id) {
                update_user_meta($user_id, 'yprint_checkout_state', $state);
            }
            
            // State in WooCommerce Session speichern
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('yprint_checkout_state', $state);
            }
            
            wp_send_json_success();
        } else {
            wp_send_json_error('Keine Daten zum Speichern vorhanden');
        }
    }

    /**
     * AJAX-Handler für Warenkorb-Mengenänderungen
     */
    public function ajax_update_cart_quantity() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field($_POST['cart_item_key']) : '';
        $quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 1;
        
        if (empty($cart_item_key)) {
            wp_send_json_error(array('message' => 'Ungültiger Warenkorbartikel'));
            return;
        }
        
        // Sicherstellen, dass die Menge positiv ist
        if ($quantity < 1) {
            $quantity = 1;
        }
        
        // Warenkorb aktualisieren
        WC()->cart->set_quantity($cart_item_key, $quantity, true);
        
        // Berechne neue Warenkorbwerte
        WC()->cart->calculate_totals();
        
        // Sende aktualisierte Warenkorbinformationen zurück
        wp_send_json_success(array(
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total(),
            'cart_subtotal' => WC()->cart->get_cart_subtotal(),
            'item_subtotal' => wc_price(WC()->cart->get_cart_item_subtotal($cart_item_key)),
            'message' => 'Warenkorb aktualisiert'
        ));
    }

    /**
     * AJAX-Handler für Artikel aus dem Warenkorb entfernen
     */
    public function ajax_remove_from_cart() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field($_POST['cart_item_key']) : '';
        
        if (empty($cart_item_key)) {
            wp_send_json_error(array('message' => 'Ungültiger Warenkorbartikel'));
            return;
        }
        
        // Artikel aus dem Warenkorb entfernen
        WC()->cart->remove_cart_item($cart_item_key);
        
        // Berechne neue Warenkorbwerte
        WC()->cart->calculate_totals();
        
        // Sende aktualisierte Warenkorbinformationen zurück
        wp_send_json_success(array(
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total(),
            'cart_subtotal' => WC()->cart->get_cart_subtotal(),
            'message' => 'Artikel entfernt'
        ));
    }

    /**
     * AJAX-Handler für Gutscheine
     */
    public function ajax_apply_coupon() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (!isset($_POST['coupon_code']) || empty($_POST['coupon_code'])) {
            wp_send_json_error('Kein Gutschein-Code angegeben');
            return;
        }
        
        $coupon_code = sanitize_text_field($_POST['coupon_code']);
        
        if (function_exists('WC') && WC()->cart) {
            // Prüfen ob Gutschein bereits angewendet wurde
            if (WC()->cart->has_discount($coupon_code)) {
                wp_send_json_error('Dieser Gutschein wurde bereits angewendet');
                return;
            }
            
            // Gutschein anwenden
            $result = WC()->cart->apply_coupon($coupon_code);
            
            if ($result) {
                wp_send_json_success('Gutschein erfolgreich angewendet');
            } else {
                wp_send_json_error('Der Gutschein konnte nicht angewendet werden');
            }
        } else {
            wp_send_json_error('WooCommerce ist nicht aktiviert');
        }
    }

    /**
     * AJAX-Handler für die Vorbereitung einer Bestellung
     */
    public function ajax_prepare_order() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (!isset($_POST['checkout_data']) || empty($_POST['checkout_data'])) {
            wp_send_json_error(array(
                'message' => 'Keine Checkout-Daten vorhanden'
            ));
            return;
        }
        
        $checkout_data = $_POST['checkout_data'];
        
        // Ruft die Order-Klasse auf, um eine temporäre Bestellung zu erstellen
        $order_handler = YPrint_Order::instance();
        
        try {
            $temp_order_id = $order_handler->create_temp_order($checkout_data, 'pending');
            
            if ($temp_order_id) {
                // Order ID in Session speichern
                if (function_exists('WC') && WC()->session) {
                    WC()->session->set('yprint_temp_order_id', $temp_order_id);
                }
                
                wp_send_json_success(array(
                    'temp_order_id' => $temp_order_id,
                    'message' => 'Bestellung vorbereitet'
                ));
            } else {
                wp_send_json_error(array(
                    'message' => 'Fehler bei der Vorbereitung der Bestellung'
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Fehler: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX-Handler für die Finalisierung einer Bestellung nach erfolgreicher Zahlung
     */
    public function ajax_finalize_order() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (!isset($_POST['temp_order_id']) || empty($_POST['temp_order_id'])) {
            wp_send_json_error(array(
                'message' => 'Keine Bestellungs-ID angegeben'
            ));
            return;
        }
        
        $temp_order_id = intval($_POST['temp_order_id']);
        $payment_id = isset($_POST['payment_id']) ? sanitize_text_field($_POST['payment_id']) : '';
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : '';
        
        // Ruft die Order-Klasse auf, um die Bestellung zu finalisieren
        $order_handler = YPrint_Order::instance();
        
        try {
            $result = $order_handler->finalize_order($temp_order_id, $payment_id, $payment_method);
            
            if ($result['success']) {
                wp_send_json_success(array(
                    'order_id' => $result['order_id'],
                    'redirect' => $result['redirect']
                ));
            } else {
                wp_send_json_error(array(
                    'message' => $result['message']
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Fehler bei der Finalisierung der Bestellung: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Shortcodes Implementierungen
     */
    
    /**
     * Hauptcheckout-Shortcode
     */
    public function checkout_shortcode() {
        if (!function_exists('WC')) {
            return '<div class="yprint-error-message">WooCommerce ist nicht aktiviert.</div>';
        }

        // Überprüfen, ob Warenkorb leer ist
        $cart_empty = WC()->cart->is_empty();

        ob_start();
        include YPRINT_PAYMENT_TEMPLATE_PATH . 'checkout/main.php';
        return ob_get_clean();
    }

    /**
     * Kommunikationssystem Shortcode
     */
    public function checkout_communication_shortcode() {
        ob_start();
        include YPRINT_PAYMENT_TEMPLATE_PATH . 'checkout/communication.php';
        return ob_get_clean();
    }

    /**
     * Lieferadresse Shortcode
     */
    public function shipping_address_shortcode() {
        ob_start();
        include YPRINT_PAYMENT_TEMPLATE_PATH . 'checkout/shipping-address.php';
        return ob_get_clean();
    }

    /**
     * Zahlungsoptionen Shortcode
     */
    public function payment_options_shortcode() {
        ob_start();
        include YPRINT_PAYMENT_TEMPLATE_PATH . 'checkout/payment-options.php';
        return ob_get_clean();
    }

    /**
     * Bestellübersicht Shortcode
     */
    public function order_summary_shortcode() {
        ob_start();
        include YPRINT_PAYMENT_TEMPLATE_PATH . 'checkout/order-summary.php';
        return ob_get_clean();
    }

    /**
     * Gutschein und Kauf-Button Shortcode
     */
    public function coupon_buy_shortcode() {
        ob_start();
        include YPRINT_PAYMENT_TEMPLATE_PATH . 'checkout/coupon-buy.php';
        return ob_get_clean();
    }

    /**
     * Abweichende Rechnungsadresse Shortcode
     */
    public function different_billing_shortcode() {
        ob_start();
        include YPRINT_PAYMENT_TEMPLATE_PATH . 'checkout/different-billing.php';
        return ob_get_clean();
    }

    /**
     * Danke-Seite-Weiterleitung Shortcode
     */
    public function thankyou_redirect_shortcode() {
        ob_start();
        include YPRINT_PAYMENT_TEMPLATE_PATH . 'thank-you.php';
        return ob_get_clean();
    }

    /**
     * Passt die Bestellbestätigungsseite an und ersetzt sie mit unserem benutzerdefinierten Redirect
     */
    public function customize_order_received_page($title, $id) {
        // Prüfen, ob es sich um die Order-Received-Seite handelt
        if (is_wc_endpoint_url('order-received') && $id == wc_get_page_id('checkout')) {
            // Mache den normalen Titel unsichtbar
            return '';
        }
        return $title;
    }

    /**
     * Ersetzt den Inhalt der Bestellbestätigungsseite
     */
    public function replace_thankyou_content($content) {
        // Nur auf der Order-Received-Seite
        if (is_wc_endpoint_url('order-received')) {
            // Ersetze den Inhalt mit unserem Shortcode
            return do_shortcode('[thankyou_redirect]');
        }
        return $content;
    }

    /**
     * Entferne das standardmäßige WooCommerce Dankeschön-Template
     */
    public function remove_woocommerce_order_details_table() {
        if (is_wc_endpoint_url('order-received')) {
            remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
            
            // Verhindere auch alle anderen WooCommerce-Ausgaben
            remove_all_actions('woocommerce_thankyou');
            remove_all_actions('woocommerce_order_details_before_order_table');
            remove_all_actions('woocommerce_order_details_after_order_table');
            remove_all_actions('woocommerce_order_details_before_customer_details');
            remove_all_actions('woocommerce_order_details_after_customer_details');
        }
    }

    /**
     * Überprüft ein Nonce für sichere AJAX-Anfragen
     *
     * @param string $action
     * @param string $request_field
     */
    public function verify_nonce($action, $request_field) {
        if (!isset($_REQUEST[$request_field]) || !wp_verify_nonce($_REQUEST[$request_field], $action)) {
            wp_send_json_error(array(
                'message' => 'Sicherheitsüberprüfung fehlgeschlagen.'
            ));
            exit;
        }
    }

    /**
     * Gibt den Feature-Flag-Wert zurück
     *
     * @param string $flag_name
     * @return bool
     */
    public function is_feature_enabled($flag_name) {
        return $this->feature_flags->is_enabled($flag_name);
    }

    /**
     * Aktiviert ein Feature-Flag
     *
     * @param string $flag_name
     * @return bool
     */
    public function enable_feature($flag_name) {
        return $this->feature_flags->enable($flag_name);
    }

    /**
     * Deaktiviert ein Feature-Flag
     *
     * @param string $flag_name
     * @return bool
     */
    public function disable_feature($flag_name) {
        return $this->feature_flags->disable($flag_name);
    }

    /**
     * Holt ein Gateway anhand des Namens
     *
     * @param string $gateway_name
     * @return object|null
     */
    public function get_gateway($gateway_name) {
        return isset($this->gateways[$gateway_name]) ? $this->gateways[$gateway_name] : null;
    }

    /**
     * Loggt Nachrichten in die WP-Debug-Protokolle
     *
     * @param string $message
     * @param string $level
     */
    public function log($message, $level = 'info') {
        if (WP_DEBUG) {
            $log_levels = array(
                'emergency' => 0,
                'alert'     => 1,
                'critical'  => 2,
                'error'     => 3,
                'warning'   => 4,
                'notice'    => 5,
                'info'      => 6,
                'debug'     => 7
            );
            
            if (!isset($log_levels[$level])) {
                $level = 'info';
            }
            
            error_log('[YPrint Payment] [' . strtoupper($level) . '] ' . $message);
        }
    }
}