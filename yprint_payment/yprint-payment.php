<?php
/**
 * Plugin Name: YPrint Payment
 * Plugin URI: https://yprint.de
 * Description: Benutzerdefiniertes Zahlungssystem für YPrint mit erweiterter Stripe- und PayPal-Integration
 * Version: 1.0.0
 * Author: YPrint
 * Author URI: https://yprint.de
 * Text Domain: yprint-payment
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 8.3.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

// Plugin-Konstanten definieren
define('YPRINT_PAYMENT_VERSION', '1.0.0');
define('YPRINT_PAYMENT_FILE', __FILE__);
define('YPRINT_PAYMENT_DIR', plugin_dir_path(__FILE__));
define('YPRINT_PAYMENT_URL', plugin_dir_url(__FILE__));
define('YPRINT_PAYMENT_BASENAME', plugin_basename(__FILE__));

/**
 * Hauptklasse des Plugins
 */
final class YPrint_Payment {
    /**
     * Einzelne Instanz des Plugins
     *
     * @var YPrint_Payment
     */
    protected static $_instance = null;

    /**
     * Hauptinstanz des Plugins
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
     * Konstruktor: Plugin-Basis initialisieren
     */
    public function __construct() {
        $this->define_constants();
        $this->init_hooks();
        $this->includes();

        do_action('yprint_payment_loaded');
    }

    /**
     * Konstanten definieren
     */
    private function define_constants() {
        $this->define('YPRINT_PAYMENT_ABSPATH', dirname(YPRINT_PAYMENT_FILE) . '/');
        $this->define('YPRINT_PAYMENT_TEMPLATE_PATH', YPRINT_PAYMENT_ABSPATH . 'templates/');
    }

    /**
     * Konstante definieren, wenn nicht bereits definiert
     *
     * @param string $name
     * @param string|bool $value
     */
    private function define($name, $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }

    /**
     * Hooks initialisieren
     */
    private function init_hooks() {
        register_activation_hook(YPRINT_PAYMENT_FILE, array($this, 'activation_hook'));
        register_deactivation_hook(YPRINT_PAYMENT_FILE, array($this, 'deactivation_hook'));
        
        add_action('plugins_loaded', array($this, 'on_plugins_loaded'), 20);
        add_action('init', array($this, 'init'), 0);
    }

    /**
     * Bei Plugin-Aktivierung ausführen
     */
    public function activation_hook() {
        // Rewrite-Regeln aktualisieren
        flush_rewrite_rules();
        
        // Prüfen, ob WooCommerce aktiviert ist
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('Dieses Plugin benötigt WooCommerce, um zu funktionieren. Bitte installieren und aktivieren Sie WooCommerce zuerst.');
        }
        
        // Standard-Optionen setzen
        $this->set_default_options();
        
        // Webhook-Verzeichnisse und Templates erstellen
        $this->create_template_files();
        
        do_action('yprint_payment_activated');
    }

    /**
     * Standard-Optionen setzen
     */
    private function set_default_options() {
        $default_options = array(
            'yprint_paypal_client_id' => 'AWKnm3nbPgIo3KXZbs-vjQPjSWOYy6LiFGQ6zn_nJ7XlW9LldJ8OjKuxbbLzK7UTE2xETV9sz43qzhpB',
            'yprint_paypal_secret_key' => 'EDZRCOs170tjF5v2CFfw7giWsnTvjgLSbodtsgbAXee13AdigXiZPqF_0HU8BqlRnJVAigVtgKke8mY9',
            'yprint_paypal_webhook_id' => '1AP621966T021942C',
            'yprint_stripe_public_key' => 'pk_live_51QomI4Efl8wKMoZvo59c44NsSuDkXqUtgvqg5qpTU5D7JmJf5XGfM8wNli25KpAAhrbPf2O0gjXNg3hWCtxpyG9G00Dxkv5oxL',
            'yprint_stripe_secret_key' => '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',
            'yprint_stripe_webhook_secret' => 'whsec_vkmfKrTYzIgzgdxDLDRlp3qAryBSowFw',
            'yprint_feature_flags' => array(
                'stripe_sca_support' => true,
                'paypal_smart_buttons' => true
            )
        );
        
        foreach ($default_options as $option_name => $default_value) {
            if (is_array($default_value)) {
                add_option($option_name, $default_value);
            } else {
                if (!get_option($option_name)) {
                    add_option($option_name, $default_value);
                }
            }
        }
    }

    /**
     * Webhook-Templates erstellen
     */
    private function create_template_files() {
        // Templates-Verzeichnis erstellen, falls nicht vorhanden
        $template_dir = YPRINT_PAYMENT_ABSPATH . 'templates';
        if (!file_exists($template_dir)) {
            mkdir($template_dir, 0755, true);
        }
        
        // PayPal-Rückgabeseite Template
        $paypal_template_path = $template_dir . '/paypal-return.php';
        if (!file_exists($paypal_template_path)) {
            $this->create_paypal_return_template($paypal_template_path);
        }
        
        // Stripe-Rückgabeseite Template
        $stripe_template_path = $template_dir . '/stripe-return.php';
        if (!file_exists($stripe_template_path)) {
            $this->create_stripe_return_template($stripe_template_path);
        }
    }
    
    /**
     * PayPal Return Template erstellen
     */
    private function create_paypal_return_template($path) {
        $content = '<?php
// Verhindere direkten Zugriff
if (!defined("ABSPATH")) exit;

// Order ID aus der Session abrufen
$order_id = WC()->session ? WC()->session->get("yprint_last_order_id") : 0;

// PayPal-Order ID aus URL abrufen
$paypal_order_id = isset($_GET["token"]) ? sanitize_text_field($_GET["token"]) : "";

// Prüfen, ob Benutzer abgebrochen hat
$canceled = isset($_GET["cancel"]) && $_GET["cancel"] === "true";

get_header();
?>

<div class="yprint-payment-return-container">
    <?php if ($canceled): ?>
        <div class="yprint-payment-message yprint-payment-error">
            <h2>Zahlung abgebrochen</h2>
            <p>Die PayPal-Zahlung wurde abgebrochen. Bitte versuchen Sie es erneut oder wählen Sie eine andere Zahlungsmethode.</p>
            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">Zurück zum Checkout</a>
        </div>
    <?php else: ?>
        <div class="yprint-payment-message yprint-payment-success">
            <h2>Zahlung wird verarbeitet</h2>
            <p>Ihre PayPal-Zahlung wird verarbeitet. Bitte warten Sie einen Moment...</p>
            <div class="yprint-loader"></div>
        </div>
    <?php endif; ?>
</div>

<style>
.yprint-payment-return-container {
    max-width: 800px;
    margin: 50px auto;
    padding: 30px;
    text-align: center;
}

.yprint-payment-message {
    padding: 30px;
    border-radius: 8px;
}

.yprint-payment-success {
    background-color: #f0f9ff;
    border: 1px solid #d1e7f7;
}

.yprint-payment-error {
    background-color: #fff2f2;
    border: 1px solid #ffcdd2;
}

.yprint-loader {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #0079FF;
    border-radius: 50%;
    animation: yprint-spin 1s linear infinite;
    margin-top: 20px;
}

@keyframes yprint-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.yprint-return-button {
    display: inline-block;
    margin-top: 20px;
    padding: 10px 20px;
    background-color: #0079FF;
    color: white;
    text-decoration: none;
    border-radius: 4px;
}

.yprint-return-button:hover {
    background-color: #0068e1;
    color: white;
}
</style>

<script>
jQuery(document).ready(function($) {
    <?php if (!$canceled && !empty($paypal_order_id)): ?>
    // PayPal-Zahlung bestätigen
    $.ajax({
        type: "POST",
        url: "<?php echo admin_url("admin-ajax.php"); ?>",
        data: {
            action: "yprint_verify_paypal_return",
            paypal_order_id: "<?php echo esc_js($paypal_order_id); ?>",
            security: "<?php echo wp_create_nonce("yprint-checkout-nonce"); ?>"
        },
        success: function(response) {
            if (response.success && response.data.redirect) {
                window.location.href = response.data.redirect;
            } else {
                // Fehler anzeigen
                $(".yprint-payment-message")
                    .removeClass("yprint-payment-success")
                    .addClass("yprint-payment-error")
                    .html(`
                        <h2>Fehler bei der Zahlung</h2>
                        <p>${response.data.message || "Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten."}</p>
                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">Zurück zum Checkout</a>
                    `);
            }
        },
        error: function() {
            // Allgemeiner Fehler
            $(".yprint-payment-message")
                .removeClass("yprint-payment-success")
                .addClass("yprint-payment-error")
                .html(`
                    <h2>Fehler bei der Zahlung</h2>
                    <p>Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten.</p>
                    <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">Zurück zum Checkout</a>
                `);
        }
    });
    <?php endif; ?>
});
</script>

<?php get_footer(); ?>';
        
        file_put_contents($path, $content);
    }
    
    /**
     * Stripe Return Template erstellen
     */
    private function create_stripe_return_template($path) {
        $content = '<?php
// Verhindere direkten Zugriff
if (!defined("ABSPATH")) exit;

// Stripe-Parameter aus URL abrufen
$payment_intent = isset($_GET["payment_intent"]) ? sanitize_text_field($_GET["payment_intent"]) : "";
$payment_intent_client_secret = isset($_GET["payment_intent_client_secret"]) ? sanitize_text_field($_GET["payment_intent_client_secret"]) : "";
$redirect_status = isset($_GET["redirect_status"]) ? sanitize_text_field($_GET["redirect_status"]) : "";

get_header();
?>

<div class="yprint-payment-return-container">
    <div class="yprint-payment-message <?php echo $redirect_status === "succeeded" ? "yprint-payment-success" : "yprint-payment-error"; ?>">
        <?php if ($redirect_status === "succeeded"): ?>
            <h2>Zahlung erfolgreich</h2>
            <p>Ihre Zahlung wurde erfolgreich verarbeitet. Sie werden in Kürze weitergeleitet...</p>
            <div class="yprint-loader"></div>
        <?php else: ?>
            <h2>Zahlung nicht abgeschlossen</h2>
            <p>Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten.</p>
            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">Zurück zum Checkout</a>
        <?php endif; ?>
    </div>
</div>

<style>
.yprint-payment-return-container {
    max-width: 800px;
    margin: 50px auto;
    padding: 30px;
    text-align: center;
}

.yprint-payment-message {
    padding: 30px;
    border-radius: 8px;
}

.yprint-payment-success {
    background-color: #f0f9ff;
    border: 1px solid #d1e7f7;
}

.yprint-payment-error {
    background-color: #fff2f2;
    border: 1px solid #ffcdd2;
}

.yprint-loader {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #0079FF;
    border-radius: 50%;
    animation: yprint-spin 1s linear infinite;
    margin-top: 20px;
}

@keyframes yprint-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.yprint-return-button {
    display: inline-block;
    margin-top: 20px;
    padding: 10px 20px;
    background-color: #0079FF;
    color: white;
    text-decoration: none;
    border-radius: 4px;
}

.yprint-return-button:hover {
    background-color: #0068e1;
    color: white;
}
</style>

<script>
jQuery(document).ready(function($) {
    <?php if ($redirect_status === "succeeded" && !empty($payment_intent)): ?>
    // Stripe-Zahlung bestätigen
    $.ajax({
        type: "POST",
        url: "<?php echo admin_url("admin-ajax.php"); ?>",
        data: {
            action: "yprint_verify_stripe_return",
            payment_intent: "<?php echo esc_js($payment_intent); ?>",
            client_secret: "<?php echo esc_js($payment_intent_client_secret); ?>",
            security: "<?php echo wp_create_nonce("yprint-checkout-nonce"); ?>"
        },
        success: function(response) {
            if (response.success && response.data.redirect) {
                window.location.href = response.data.redirect;
            } else {
                // Fehler anzeigen
                $(".yprint-payment-message")
                    .removeClass("yprint-payment-success")
                    .addClass("yprint-payment-error")
                    .html(`
                        <h2>Fehler bei der Zahlung</h2>
                        <p>${response.data.message || "Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten."}</p>
                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">Zurück zum Checkout</a>
                    `);
            }
        },
        error: function() {
            // Allgemeiner Fehler
            $(".yprint-payment-message")
                .removeClass("yprint-payment-success")
                .addClass("yprint-payment-error")
                .html(`
                    <h2>Fehler bei der Zahlung</h2>
                    <p>Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten.</p>
                    <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">Zurück zum Checkout</a>
                `);
        }
    });
    <?php endif; ?>
});
</script>

<?php get_footer(); ?>';
        
        file_put_contents($path, $content);
    }

    /**
     * Bei Plugin-Deaktivierung ausführen
     */
    public function deactivation_hook() {
        // Rewrite-Regeln zurücksetzen
        flush_rewrite_rules();
        
        do_action('yprint_payment_deactivated');
    }

    /**
     * Wenn Plugins geladen sind
     */
    public function on_plugins_loaded() {
        // Übersetzungen laden
        load_plugin_textdomain('yprint-payment', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        
        // Prüfen, ob WooCommerce aktiviert ist
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Weitere Initialisierungen, die WooCommerce benötigen
        $this->includes_dependency();
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
     * Initialisierung
     */
    public function init() {
        // Shortcodes registrieren
        $this->register_shortcodes();
        
        // Benutzerdefinierte Endpunkte registrieren
        $this->register_endpoints();
    }

    /**
     * Dateien einbinden
     */
    private function includes() {
        // Klassen-Dateien einbinden
        include_once YPRINT_PAYMENT_ABSPATH . 'includes/class-yprint-feature-flags.php';
        include_once YPRINT_PAYMENT_ABSPATH . 'includes/class-yprint-session.php';
        include_once YPRINT_PAYMENT_ABSPATH . 'includes/class-yprint-api.php';
        
        // Admin-Bereich Klassen einbinden
        if (is_admin()) {
            include_once YPRINT_PAYMENT_ABSPATH . 'admin/class-yprint-admin.php';
        }
    }

    /**
     * Abhängige Dateien einbinden (wenn WooCommerce aktiv ist)
     */
    private function includes_dependency() {
        include_once YPRINT_PAYMENT_ABSPATH . 'includes/class-yprint-order.php';
        include_once YPRINT_PAYMENT_ABSPATH . 'gateways/class-yprint-stripe.php';
        include_once YPRINT_PAYMENT_ABSPATH . 'gateways/class-yprint-paypal.php';
        include_once YPRINT_PAYMENT_ABSPATH . 'gateways/class-yprint-sepa.php';
        include_once YPRINT_PAYMENT_ABSPATH . 'gateways/class-yprint-bank-transfer.php';
        include_once YPRINT_PAYMENT_ABSPATH . 'webhooks/class-yprint-webhook-handler.php';
    }

    /**
     * Shortcodes registrieren
     */
    private function register_shortcodes() {
        add_shortcode('yprint_checkout', array($this, 'checkout_shortcode'));
        add_shortcode('yprint_checkout_communication', array($this, 'checkout_communication_shortcode'));
        add_shortcode('yprint_payment_options', array($this, 'payment_options_shortcode'));
        add_shortcode('yprint_shipping_address', array($this, 'shipping_address_shortcode'));
        add_shortcode('yprint_order_summary', array($this, 'order_summary_shortcode'));
        add_shortcode('yprint_coupon_buy', array($this, 'coupon_buy_shortcode'));
        add_shortcode('yprint_different_billing', array($this, 'different_billing_shortcode'));
        add_shortcode('thankyou_redirect', array($this, 'thankyou_redirect_shortcode'));
    }

    /**
     * Endpoints registrieren
     */
    private function register_endpoints() {
        // PayPal Rückgabeseite
        add_rewrite_rule(
            '^paypal-return/?$',
            'index.php?paypal_return=1',
            'top'
        );
        
        // Stripe Rückgabeseite
        add_rewrite_rule(
            '^stripe-return/?$',
            'index.php?stripe_return=1',
            'top'
        );
        
        // Query-Vars hinzufügen
        add_filter('query_vars', function($vars) {
            $vars[] = 'paypal_return';
            $vars[] = 'stripe_return';
            return $vars;
        });
        
        // Template für Rückgabeseiten laden
        add_action('template_redirect', function() {
            global $wp_query;
            
            if (isset($wp_query->query_vars['paypal_return']) && $wp_query->query_vars['paypal_return']) {
                include(YPRINT_PAYMENT_TEMPLATE_PATH . 'paypal-return.php');
                exit;
            }
            
            if (isset($wp_query->query_vars['stripe_return']) && $wp_query->query_vars['stripe_return']) {
                include(YPRINT_PAYMENT_TEMPLATE_PATH . 'stripe-return.php');
                exit;
            }
        });
    }

    /**
     * Shortcodes Implementierungen
     */
    
    /**
     * Hauptcheckout-Shortcode
     */
    public function checkout_shortcode() {
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
     * Zahlungsoptionen Shortcode
     */
    public function payment_options_shortcode() {
        ob_start();
        include YPRINT_PAYMENT_TEMPLATE_PATH . 'checkout/payment-options.php';
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
}

/**
 * Gibt die Hauptinstanz des Plugins zurück
 *
 * @return YPrint_Payment
 */
function YPrint_Payment() {
    return YPrint_Payment::instance();
}

// Plugin starten
$GLOBALS['yprint_payment'] = YPrint_Payment();