<?php
/**
 * Stripe-Zahlungsintegration für YPrint Payment
 *
 * Diese Klasse implementiert die Integration mit Stripe als Zahlungsdienstleister.
 * Sie enthält Methoden für die Zahlungsinitialisierung, Verarbeitung von Webhooks,
 * SCA-Unterstützung und Zahlungsverifizierung.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stripe Zahlungs-Gateway Klasse
 */
class YPrint_Stripe {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_Stripe
     */
    protected static $_instance = null;

    /**
     * Stripe API-Schlüssel
     *
     * @var string
     */
    private $secret_key;

    /**
     * Stripe öffentlicher Schlüssel
     *
     * @var string
     */
    private $public_key;

    /**
     * Stripe Webhook Secret
     *
     * @var string
     */
    private $webhook_secret;

    /**
     * Feature Flags Manager
     *
     * @var YPrint_Feature_Flags
     */
    private $feature_flags;

    /**
     * Testmodus-Flag
     *
     * @var bool
     */
    private $test_mode;

    /**
     * Debug-Modus-Flag
     *
     * @var bool
     */
    private $debug_mode;

    /**
     * Hauptinstanz der YPrint_Stripe-Klasse
     *
     * @return YPrint_Stripe - Hauptinstanz
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
        // API-Schlüssel laden
        $this->load_api_keys();
        
        // Feature Flags laden
        $this->feature_flags = class_exists('YPrint_Feature_Flags') ? YPrint_Feature_Flags::instance() : null;
        
        // Debug-Modus setzen
        $this->debug_mode = $this->is_feature_enabled('debug_mode');
        
        // Hooks initialisieren
        $this->init_hooks();
    }

    /**
     * Hooks initialisieren
     */
    public function init_hooks() {
        // AJAX Hooks für Zahlungsinitialisierung
        add_action('wp_ajax_yprint_init_stripe_payment', array($this, 'ajax_init_stripe_payment'));
        add_action('wp_ajax_nopriv_yprint_init_stripe_payment', array($this, 'ajax_init_stripe_payment'));
        
        // AJAX Hooks für Zahlungsbestätigung
        add_action('wp_ajax_yprint_verify_stripe_return', array($this, 'ajax_verify_stripe_return'));
        add_action('wp_ajax_nopriv_yprint_verify_stripe_return', array($this, 'ajax_verify_stripe_return'));
        
        // AJAX Hooks für Zahlungsbestätigung
        add_action('wp_ajax_yprint_confirm_stripe_payment', array($this, 'ajax_confirm_stripe_payment'));
        add_action('wp_ajax_nopriv_yprint_confirm_stripe_payment', array($this, 'ajax_confirm_stripe_payment'));
        
        // Webhook Handler
        add_action('init', array($this, 'handle_webhook'));
    }

    /**
     * API-Schlüssel laden
     */
    private function load_api_keys() {
        $this->test_mode = get_option('yprint_stripe_test_mode', 'no') === 'yes';
        
        if ($this->test_mode) {
            $this->secret_key = get_option('yprint_stripe_test_secret_key', 'INSERT_API_KEY_HERE');
            $this->public_key = get_option('yprint_stripe_test_public_key', 'INSERT_API_KEY_HERE');
            $this->webhook_secret = get_option('yprint_stripe_test_webhook_secret', 'INSERT_API_KEY_HERE');
        } else {
            $this->secret_key = get_option('yprint_stripe_secret_key', 'INSERT_API_KEY_HERE');
            $this->public_key = get_option('yprint_stripe_public_key', 'INSERT_API_KEY_HERE');
            $this->webhook_secret = get_option('yprint_stripe_webhook_secret', 'INSERT_API_KEY_HERE');
        }
    }

    /**
     * AJAX-Handler für Stripe-Zahlung initialisieren
     */
    public function ajax_init_stripe_payment() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (!isset($_POST['checkout_data']) || empty($_POST['checkout_data'])) {
            wp_send_json_error(array(
                'message' => 'Keine Checkout-Daten vorhanden'
            ));
            return;
        }
        
        $checkout_data = $_POST['checkout_data'];
        $temp_order_id = isset($_POST['temp_order_id']) ? intval($_POST['temp_order_id']) : 0;
        
        try {
            // Stripe-API einbinden
            $this->load_stripe_api();
            
            // Kundenadresse aus Checkout-Daten vorbereiten
            $customer_info = $this->prepare_customer_info($checkout_data);
            
            // Währung abrufen
            $currency = strtolower(get_woocommerce_currency());
            
            // Betrag berechnen
            $amount = $this->calculate_amount();
            
            // Eindeutige Referenz-ID erstellen
            $reference_id = 'yp_' . time() . '_' . rand(1000, 9999);
            
            // Payment Intent Daten erstellen
            $payment_intent_args = $this->create_payment_intent_args($amount, $currency, $customer_info, $reference_id, $temp_order_id);
            
            // Stripe API aufrufen
            $response = $this->request_stripe_api('payment_intents', $payment_intent_args, 'POST');
            
            if (!empty($response->error)) {
                throw new Exception($response->error->message);
            }
            
            if (empty($response->id) || empty($response->client_secret)) {
                throw new Exception('Ungültige Antwort von Stripe');
            }
            
            // Payment Intent ID in Session speichern
            WC()->session->set('yprint_stripe_payment_intent', $response->id);
            WC()->session->set('yprint_stripe_reference_id', $reference_id);
            
            // Optional: Payment Intent mit einer temporären Bestellung verknüpfen
            if ($temp_order_id > 0) {
                update_post_meta($temp_order_id, '_stripe_payment_intent', $response->id);
            }
            
            // SCA-Status für die Antwort bestimmen
            $is_sca_required = $this->is_sca_required($response);
            
            // Debug-Logging
            if ($this->debug_mode) {
                $this->log('Payment Intent erstellt: ' . $response->id . ', SCA erforderlich: ' . ($is_sca_required ? 'Ja' : 'Nein'));
            }
            
            wp_send_json_success(array(
                'client_secret' => $response->client_secret,
                'payment_intent_id' => $response->id,
                'is_sca_required' => $is_sca_required,
                'public_key' => $this->public_key
            ));
        } catch (Exception $e) {
            $this->log('Fehler bei der Stripe-Initialisierung: ' . $e->getMessage(), 'error');
            
            wp_send_json_error(array(
                'message' => 'Fehler bei der Stripe-Initialisierung: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Berechnet den Gesamtbetrag aus dem Warenkorb
     * 
     * @return int Betrag in der kleinsten Währungseinheit (z.B. Cent)
     */
    private function calculate_amount() {
        if (!function_exists('WC') || !isset(WC()->cart)) {
            throw new Exception('WooCommerce ist nicht verfügbar');
        }
        
        // Gesamtbetrag aus dem Warenkorb holen
        $total = WC()->cart->get_total('numeric');
        
        // In Cent umrechnen
        return $this->format_amount($total, get_woocommerce_currency());
    }

    /**
     * Bereitet Kundeninformationen für Stripe vor
     * 
     * @param array $checkout_data Checkout-Daten
     * @return array Vorbereitete Kundeninformationen
     */
    private function prepare_customer_info($checkout_data) {
        // Shipping und Billing-Daten abrufen
        $shipping_address = isset($checkout_data['shipping_address']) ? $checkout_data['shipping_address'] : array();
        $billing_enabled = isset($checkout_data['different_billing']) && 
                          ($checkout_data['different_billing'] === true || 
                           $checkout_data['different_billing'] === 'true' || 
                           $checkout_data['different_billing'] === 1 || 
                           $checkout_data['different_billing'] === '1');
        
        $billing_address = $billing_enabled && isset($checkout_data['different_billing_address']) 
                          ? $checkout_data['different_billing_address'] 
                          : $shipping_address;
        
        // Adressinformationen für Stripe vorbereiten
        $customer_info = array(
            'address' => array(
                'line1' => isset($billing_address['address_1']) ? sanitize_text_field($billing_address['address_1']) : '',
                'line2' => isset($billing_address['address_2']) ? sanitize_text_field($billing_address['address_2']) : '',
                'city' => isset($billing_address['city']) ? sanitize_text_field($billing_address['city']) : '',
                'postal_code' => isset($billing_address['postcode']) ? sanitize_text_field($billing_address['postcode']) : '',
                'country' => isset($billing_address['country']) ? sanitize_text_field($billing_address['country']) : 'DE'
            ),
            'email' => isset($billing_address['email']) ? sanitize_email($billing_address['email']) : '',
            'name' => trim((isset($billing_address['first_name']) ? sanitize_text_field($billing_address['first_name']) : '') . ' ' . 
                   (isset($billing_address['last_name']) ? sanitize_text_field($billing_address['last_name']) : '')),
            'phone' => isset($billing_address['phone']) ? sanitize_text_field($billing_address['phone']) : ''
        );
        
        // E-Mail-Adresse aus Benutzeraccount nehmen, wenn nicht vorhanden
        if (empty($customer_info['email'])) {
            $user_id = get_current_user_id();
            if ($user_id > 0) {
                $user = get_userdata($user_id);
                $customer_info['email'] = $user->user_email;
            } else if (isset($shipping_address['email'])) {
                $customer_info['email'] = sanitize_email($shipping_address['email']);
            }
        }
        
        // Alternativ abweichende Rechnungsadresse prüfen
        if ($billing_enabled && isset($checkout_data['different_billing_address']['different_billing_email']) && 
            !empty($checkout_data['different_billing_address']['different_billing_email'])) {
            $customer_info['email'] = sanitize_email($checkout_data['different_billing_address']['different_billing_email']);
        }
        
        // Leere Werte entfernen
        foreach ($customer_info['address'] as $key => $value) {
            if (empty($value)) {
                unset($customer_info['address'][$key]);
            }
        }
        
        if (empty($customer_info['name'])) {
            unset($customer_info['name']);
        }
        
        if (empty($customer_info['email'])) {
            unset($customer_info['email']);
        }
        
        if (empty($customer_info['phone'])) {
            unset($customer_info['phone']);
        }
        
        return $customer_info;
    }

    /**
     * Erstellt die Parameter für einen Payment Intent
     * 
     * @param int $amount Betrag in Cent
     * @param string $currency Währung
     * @param array $customer_info Kundeninformationen
     * @param string $reference_id Referenz-ID
     * @param int $order_id Bestell-ID (optional)
     * @return array Payment Intent Parameter
     */
    private function create_payment_intent_args($amount, $currency, $customer_info, $reference_id, $order_id = 0) {
        // Metadaten erstellen
        $metadata = array(
            'reference_id' => $reference_id,
            'site_url' => get_site_url()
        );
        
        // Bestell-ID hinzufügen, falls vorhanden
        if ($order_id > 0) {
            $metadata['order_id'] = $order_id;
        }
        
        // Beschreibung erstellen
        $description = $order_id > 0 
                     ? 'Bestellung #' . $order_id . ' bei ' . get_bloginfo('name')
                     : 'Bestellung bei ' . get_bloginfo('name');
        
        // Payment Intent Parameter
        $payment_intent_args = array(
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'metadata' => $metadata,
            'receipt_email' => isset($customer_info['email']) ? $customer_info['email'] : null,
        );
        
        // Automatische Zahlungsmethoden aktivieren, wenn Feature aktiviert
        if ($this->is_feature_enabled('stripe_automatic_payment_methods')) {
            $payment_intent_args['automatic_payment_methods'] = array('enabled' => true);
        } else {
            $payment_intent_args['payment_method_types'] = array('card');
        }
        
        // SCA-Unterstützung, wenn das Feature aktiviert ist
        if ($this->is_feature_enabled('stripe_sca_support')) {
            $payment_intent_args['payment_method_options'] = array(
                'card' => array(
                    'request_three_d_secure' => 'automatic'
                )
            );
        }
        
        // Kundeninformationen hinzufügen, falls vorhanden
        if (!empty($customer_info['email']) || !empty($customer_info['name'])) {
            // Existierenden Kunden suchen oder neuen erstellen
            $customer_id = $this->find_or_create_customer($customer_info);
            
            if ($customer_id) {
                $payment_intent_args['customer'] = $customer_id;
            }
        }
        
        // Hook für zusätzliche Payment Intent Parameter
        return apply_filters('yprint_stripe_payment_intent_args', $payment_intent_args, $amount, $currency, $customer_info, $order_id);
    }

    /**
     * Findet einen bestehenden Kunden oder erstellt einen neuen
     * 
     * @param array $customer_info Kundeninformationen
     * @return string|null Kunden-ID oder null bei Fehler
     */
    private function find_or_create_customer($customer_info) {
        try {
            // Nur fortfahren, wenn eine E-Mail-Adresse vorhanden ist
            if (empty($customer_info['email'])) {
                return null;
            }
            
            // Prüfen, ob der Kunde bereits existiert
            $user_id = get_current_user_id();
            if ($user_id > 0) {
                $stripe_customer_id = get_user_meta($user_id, '_stripe_customer_id', true);
                
                if (!empty($stripe_customer_id)) {
                    // Bestehenden Kunden aktualisieren
                    $customer_args = array();
                    
                    if (!empty($customer_info['name'])) {
                        $customer_args['name'] = $customer_info['name'];
                    }
                    
                    if (!empty($customer_info['phone'])) {
                        $customer_args['phone'] = $customer_info['phone'];
                    }
                    
                    if (!empty($customer_info['address'])) {
                        $customer_args['address'] = $customer_info['address'];
                    }
                    
                    if (!empty($customer_args)) {
                        $this->request_stripe_api('customers/' . $stripe_customer_id, $customer_args, 'POST');
                    }
                    
                    return $stripe_customer_id;
                }
            }
            
            // Customer erstellen
            $customer_args = array(
                'email' => $customer_info['email']
            );
            
            if (!empty($customer_info['name'])) {
                $customer_args['name'] = $customer_info['name'];
            }
            
            if (!empty($customer_info['phone'])) {
                $customer_args['phone'] = $customer_info['phone'];
            }
            
            if (!empty($customer_info['address'])) {
                $customer_args['address'] = $customer_info['address'];
            }
            
            if ($user_id > 0) {
                $customer_args['metadata'] = array(
                    'user_id' => $user_id,
                    'site_url' => get_site_url()
                );
            }
            
            $response = $this->request_stripe_api('customers', $customer_args, 'POST');
            
            if (!empty($response->error)) {
                $this->log('Fehler beim Erstellen des Stripe Customers: ' . $response->error->message, 'error');
                return null;
            }
            
            $customer_id = $response->id;
            
            // Customer-ID speichern, wenn der Benutzer angemeldet ist
            if ($user_id > 0) {
                update_user_meta($user_id, '_stripe_customer_id', $customer_id);
            }
            
            return $customer_id;
        } catch (Exception $e) {
            $this->log('Fehler beim Erstellen des Stripe Customers: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Prüft, ob Strong Customer Authentication (SCA) erforderlich ist
     * 
     * @param object $payment_intent Payment Intent Objekt
     * @return bool True, wenn SCA erforderlich ist
     */
    public function is_sca_required($payment_intent) {
        // Prüfen auf next_action
        if (!empty($payment_intent->next_action)) {
            return true;
        }
        
        // Prüfen auf Status für SCA
        if (isset($payment_intent->status) && in_array($payment_intent->status, array('requires_action', 'requires_confirmation', 'requires_payment_method'))) {
            return true;
        }
        
        return false;
    }

    /**
     * AJAX-Handler zur Bestätigung einer Stripe-Zahlung
     */
    public function ajax_confirm_stripe_payment() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (!isset($_POST['payment_intent']) || empty($_POST['payment_intent'])) {
            wp_send_json_error(array(
                'message' => 'Fehlende Zahlungsinformationen'
            ));
            return;
        }
        
        $payment_intent_id = sanitize_text_field($_POST['payment_intent']);
        
        try {
            // Stripe-API einbinden
            $this->load_stripe_api();
            
            // Payment Intent Status prüfen
            $response = $this->request_stripe_api('payment_intents/' . $payment_intent_id, array());
            
            if (!empty($response->error)) {
                throw new Exception($response->error->message);
            }
            
            // Prüfen, ob der Payment Intent mit dem in der Session gespeicherten übereinstimmt
            $stored_payment_intent = WC()->session ? WC()->session->get('yprint_stripe_payment_intent') : '';
            
            if ($stored_payment_intent !== $payment_intent_id) {
                throw new Exception('Payment Intent stimmt nicht mit Session-Daten überein');
            }
            
            // Prüfen, ob Zahlung erfolgreich war
            if ($response->status !== 'succeeded') {
                throw new Exception('Zahlung wurde nicht erfolgreich abgeschlossen (Status: ' . $response->status . ')');
            }
            
            // Charge ID extrahieren
            $charge_id = isset($response->charges->data[0]->id) ? $response->charges->data[0]->id : $payment_intent_id;
            
            // Zahlungsinformationen in Session speichern
            WC()->session->set('yprint_stripe_payment_status', 'COMPLETED');
            WC()->session->set('yprint_stripe_charge_id', $charge_id);
            
            wp_send_json_success(array(
                'message' => 'Zahlung erfolgreich bestätigt',
                'charge_id' => $charge_id
            ));
        } catch (Exception $e) {
            $this->log('Fehler bei der Zahlungsbestätigung: ' . $e->getMessage(), 'error');
            
            wp_send_json_error(array(
                'message' => 'Fehler bei der Zahlungsbestätigung: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX-Handler zur Bestätigung einer Stripe-Rückgabe
     */
    public function ajax_verify_stripe_return() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (!isset($_POST['payment_intent']) || empty($_POST['payment_intent'])) {
            wp_send_json_error(array(
                'message' => 'Payment Intent ID fehlt'
            ));
            return;
        }
        
        $payment_intent_id = sanitize_text_field($_POST['payment_intent']);
        
        try {
            // Stripe-API einbinden
            $this->load_stripe_api();
            
            // Payment Intent Status prüfen
            $response = $this->request_stripe_api('payment_intents/' . $payment_intent_id, array());
            
            if (!empty($response->error)) {
                throw new Exception($response->error->message);
            }
            
            // Prüfen, ob der Payment Intent mit dem in der Session gespeicherten übereinstimmt
            $stored_payment_intent = WC()->session ? WC()->session->get('yprint_stripe_payment_intent') : '';
            
            if (!empty($stored_payment_intent) && $stored_payment_intent !== $payment_intent_id) {
                // Debug-Log: Nicht als Fehler behandeln, wenn wir von einer externen Umleitung kommen
                $this->log('Payment Intent ' . $payment_intent_id . ' stimmt nicht mit gespeichertem Intent ' . $stored_payment_intent . ' überein', 'notice');
                // Wir aktualisieren die Session mit dem neuen Payment Intent
                WC()->session->set('yprint_stripe_payment_intent', $payment_intent_id);
            }
            
            // Prüfen, ob Zahlung erfolgreich war
            if ($response->status !== 'succeeded') {
                // SCA-Erweiterung: Wenn SCA erforderlich ist, aber noch nicht abgeschlossen
                if ($response->status === 'requires_action' || $response->status === 'requires_confirmation') {
                    wp_send_json_error(array(
                        'message' => 'Zahlung erfordert weitere Authentifizierung',
                        'requires_action' => true,
                        'payment_intent_client_secret' => $response->client_secret
                    ));
                    return;
                }
                
                throw new Exception('Zahlung wurde nicht erfolgreich abgeschlossen (Status: ' . $response->status . ')');
            }
            
            // Order ID aus Stripe Payment Intent ID finden
            $orders = wc_get_orders(array(
                'meta_key' => '_stripe_payment_intent',
                'meta_value' => $payment_intent_id,
                'limit' => 1
            ));
            
            if (empty($orders)) {
                // Versuche eine neue Bestellung zu erstellen
                $order_id = $this->create_order_from_session($payment_intent_id);
                if (!$order_id) {
                    throw new Exception('Keine zugehörige Bestellung gefunden und konnte keine neue erstellen');
                }
                $order = wc_get_order($order_id);
            } else {
                $order = $orders[0];
            }
            
            // Bestellung aktualisieren
            if (!$order->is_paid()) {
                // Charge ID extrahieren
                $charge_id = isset($response->charges->data[0]->id) ? $response->charges->data[0]->id : $payment_intent_id;
                
                $order->payment_complete($charge_id);
                $order->add_order_note('Stripe-Zahlung abgeschlossen (Charge ID: ' . $charge_id . ')');
                
                // SCA-Status speichern
                update_post_meta($order->get_id(), '_stripe_sca_required', !empty($response->next_action));
                
                $order->save();
            }
            
            // Warenkorb leeren
            WC()->cart->empty_cart();
            
            // Thank-You-URL
            $redirect_url = $this->get_thank_you_url($order);
            
            wp_send_json_success(array(
                'redirect' => $redirect_url
            ));
        } catch (Exception $e) {
            $this->log('Fehler bei der Stripe-Zahlungsverifizierung: ' . $e->getMessage(), 'error');
            
            wp_send_json_error(array(
                'message' => 'Fehler bei der Verarbeitung der Stripe-Zahlung: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Erstellt eine Bestellung aus den Session-Daten
     * 
     * @param string $payment_intent_id Die Payment Intent ID
     * @return int|bool Bestell-ID oder false bei Fehler
     */
    private function create_order_from_session($payment_intent_id) {
        try {
            if (!WC()->session) {
                return false;
            }
            
            // Temporäre Bestellung erstellen
            $order = wc_create_order();
            
            // Benutzer zuweisen, falls angemeldet
            $user_id = get_current_user_id();
            if ($user_id > 0) {
                $order->set_customer_id($user_id);
            }
            
            // Warenkorb-Produkte zur Bestellung hinzufügen
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                $quantity = $cart_item['quantity'];
                
                // Produkt zur Bestellung hinzufügen
                $order->add_product($product, $quantity);
            }
            
            // Versandkosten hinzufügen
            if (WC()->cart->needs_shipping()) {
                $shipping_rate = new WC_Shipping_Rate(
                    'flat_rate', 
                    'Versand', 
                    WC()->cart->get_shipping_total(), 
                    WC()->cart->get_shipping_tax(), 
                    'flat_rate'
                );
                $order->add_shipping($shipping_rate);
            }
            
            // Shipping und Billing aus der Session holen
            $checkout_state = WC()->session->get('yprint_checkout_state');
            
            if ($checkout_state && isset($checkout_state['shippingAddress'])) {
                $shipping = $checkout_state['shippingAddress'];
                
                // Shipping-Adresse setzen
                $order->set_shipping_first_name($shipping['first_name'] ?? '');
                $order->set_shipping_last_name($shipping['last_name'] ?? '');
                $order->set_shipping_address_1($shipping['address_1'] ?? '');
                $order->set_shipping_address_2($shipping['address_2'] ?? '');
                $order->set_shipping_postcode($shipping['postcode'] ?? '');
                $order->set_shipping_city($shipping['city'] ?? '');
                $order->set_shipping_country($shipping['country'] ?? 'DE');
                
                // Billing gleich Shipping, wenn nichts anderes definiert
                $order->set_billing_first_name($shipping['first_name'] ?? '');
                $order->set_billing_last_name($shipping['last_name'] ?? '');
                $order->set_billing_address_1($shipping['address_1'] ?? '');
                $order->set_billing_address_2($shipping['address_2'] ?? '');
                $order->set_billing_postcode($shipping['postcode'] ?? '');
                $order->set_billing_city($shipping['city'] ?? '');
                $order->set_billing_country($shipping['country'] ?? 'DE');
                
                // E-Mail-Adresse aus Benutzeraccount
                if ($user_id > 0) {
                    $user = get_userdata($user_id);
                    $order->set_billing_email($user->user_email);
                }
            }
            
            // Abweichende Rechnungsadresse
            if ($checkout_state && isset($checkout_state['differentBilling']) && 
                isset($checkout_state['differentBilling']['enabled']) && 
                $checkout_state['differentBilling']['enabled'] && 
                isset($checkout_state['differentBillingAddress'])) {
                
                $billing = $checkout_state['differentBillingAddress'];
                $order->set_billing_first_name($billing['different_billing_first_name'] ?? '');
                $order->set_billing_last_name($billing['different_billing_last_name'] ?? '');
                $order->set_billing_address_1($billing['different_billing_address_1'] ?? '');
                $order->set_billing_address_2($billing['different_billing_address_2'] ?? '');
                $order->set_billing_postcode($billing['different_billing_postcode'] ?? '');
                $order->set_billing_city($billing['different_billing_city'] ?? '');
                $order->set_billing_country($billing['different_billing_country'] ?? 'DE');
                $order->set_billing_email($billing['different_billing_email'] ?? '');
            }
            
            // Stripe als Zahlungsmethode setzen
            $order->set_payment_method('stripe');
            $order->set_payment_method_title('Kreditkarte (Stripe)');
            
            // Payment Intent als Metadaten speichern
            update_post_meta($order->get_id(), '_stripe_payment_intent', $payment_intent_id);
            
            // Bestellung berechnen und speichern
            $order->calculate_totals();
            $order->update_status('processing', 'Bestellung aus Stripe Payment Intent erstellt');
            $order->save();
            
            return $order->get_id();
        } catch (Exception $e) {
            $this->log('Fehler beim Erstellen der Bestellung: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Webhook Handler für Stripe
     */
    public function handle_webhook() {
        // Nur an der Stripe Webhook-URL aktivieren
        if (!isset($_GET['yprint-stripe-webhook'])) {
            return;
        }
        
        // Webhook-Verarbeitung
        $payload = file_get_contents('php://input');
        $headers = $this->get_request_headers();
        
        // Debug-Log für Webhook-Anfragen
        if ($this->debug_mode) {
            $this->log('Stripe Webhook empfangen: ' . substr($payload, 0, 500) . '...');
        }
        
        // Webhook-Signatur prüfen
        $signature = isset($headers['stripe-signature']) ? $headers['stripe-signature'] : '';
        
        if (empty($this->webhook_secret) || empty($signature)) {
            $this->log('Stripe Webhook Fehler: Ungültige Webhook-Konfiguration - Secret oder Signatur fehlt', 'error');
            status_header(400);
            die('Invalid webhook configuration');
        }
        
        try {
            // Stripe-Bibliothek laden
            $this->load_stripe_api();
            
            // Event verifizieren
            if (class_exists('\\Stripe\\Webhook')) {
                $event = \Stripe\Webhook::constructEvent(
                    $payload, $signature, $this->webhook_secret
                );
            } else {
                throw new Exception('Stripe Webhook Klasse nicht gefunden');
            }
            
            if ($this->debug_mode) {
                $this->log('Stripe Webhook Event verifiziert: ' . $event->type);
            }
            
            // Event-Typ verarbeiten
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    // Zahlung erfolgreich
                    $result = $this->process_payment_succeeded($event->data->object);
                    break;
                    
                case 'payment_intent.payment_failed':
                    // Zahlung fehlgeschlagen
                    $result = $this->process_payment_failed($event->data->object);
                    break;
                    
                case 'charge.refunded':
                    // Zahlung zurückerstattet
                    $result = $this->process_refund($event->data->object);
                    break;
                
                default:
                    $this->log('Stripe Webhook: Unbehandelter Event-Typ: ' . $event->type);
                    $result = array(
                        'success' => true,
                        'message' => 'Unbehandelter Event-Typ: ' . $event->type
                    );
                    break;
            }
            
            status_header(200);
            if ($this->debug_mode) {
                $this->log('Stripe Webhook verarbeitet: ' . json_encode($result));
            }
            die('Webhook processed');
        } catch (\UnexpectedValueException $e) {
            // Ungültiger Payload
            $this->log('Stripe Webhook Fehler (Payload): ' . $e->getMessage(), 'error');
            status_header(400);
            die('Invalid payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Ungültige Signatur
            $this->log('Stripe Webhook Fehler (Signatur): ' . $e->getMessage(), 'error');
            status_header(400);
            die('Invalid signature');
        } catch (Exception $e) {
            // Sonstiger Fehler
            $this->log('Stripe Webhook Fehler: ' . $e->getMessage(), 'error');
            status_header(400);
            die('Webhook Error: ' . $e->getMessage());
        }
    }

    /**
     * Verarbeitet erfolgreiche Zahlungen von Stripe
     * 
     * @param object $payment_intent Payment Intent Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_payment_succeeded($payment_intent) {
        if ($this->debug_mode) {
            $this->log('Verarbeite erfolgreiche Stripe-Zahlung: ' . $payment_intent->id);
        }
        
        if (!isset($payment_intent->id)) {
            return array(
                'success' => false,
                'message' => 'Payment Intent ID fehlt'
            );
        }
        
        $payment_intent_id = $payment_intent->id;
        
        // Order ID aus Stripe Payment Intent ID finden
        $orders = wc_get_orders(array(
            'meta_key' => '_stripe_payment_intent',
            'meta_value' => $payment_intent_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            // Versuche über Metadaten zu finden, wenn vorhanden
            if (isset($payment_intent->metadata->order_id)) {
                $order_id = $payment_intent->metadata->order_id;
                if ($this->debug_mode) {
                    $this->log('Suche Bestellung anhand der ID aus Metadaten: ' . $order_id);
                }
                $order = wc_get_order($order_id);
                
                if ($order) {
                    $orders = array($order);
                    if ($this->debug_mode) {
                        $this->log('Bestellung per Metadaten gefunden: ' . $order_id);
                    }
                } else {
                    return array(
                        'success' => false,
                        'message' => 'Bestellung wurde nicht gefunden'
                    );
                }
            } else {
                return array(
                    'success' => false,
                    'message' => 'Keine zugehörige Bestellung gefunden'
                );
            }
        }
        
        $order = $orders[0];
        
        // Prüfen, ob Bestellung bereits bezahlt wurde
        if ($order->is_paid()) {
            return array(
                'success' => true,
                'message' => 'Bestellung wurde bereits bezahlt',
                'order_id' => $order->get_id()
            );
        }
        
        // Bestellung als bezahlt markieren
        $charge_id = isset($payment_intent->latest_charge) ? $payment_intent->latest_charge : $payment_intent_id;
        $order->payment_complete($charge_id);
        $order->add_order_note('Zahlung via Stripe Webhook bestätigt (Charge ID: ' . $charge_id . ')');
        
        // Speichere Payment Intent ID, falls noch nicht gespeichert
        if (!get_post_meta($order->get_id(), '_stripe_payment_intent', true)) {
            update_post_meta($order->get_id(), '_stripe_payment_intent', $payment_intent_id);
        }
        
        // Speichere auch SCA Status
        update_post_meta($order->get_id(), '_stripe_sca_required', $this->is_sca_required($payment_intent));
        
        $order->save();
        
        return array(
            'success' => true,
            'message' => 'Zahlung erfolgreich verarbeitet',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet fehlgeschlagene Zahlungen von Stripe
     * 
     * @param object $payment_intent Payment Intent Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_payment_failed($payment_intent) {
        if ($this->debug_mode) {
            $this->log('Verarbeite fehlgeschlagene Stripe-Zahlung: ' . $payment_intent->id);
        }
        
        if (!isset($payment_intent->id)) {
            return array(
                'success' => false,
                'message' => 'Payment Intent ID fehlt'
            );
        }
        
        $payment_intent_id = $payment_intent->id;
        
        // Order ID aus Stripe Payment Intent ID finden
        $orders = wc_get_orders(array(
            'meta_key' => '_stripe_payment_intent',
            'meta_value' => $payment_intent_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            // Versuche über Metadaten zu finden, wenn vorhanden
            if (isset($payment_intent->metadata->order_id)) {
                $order_id = $payment_intent->metadata->order_id;
                $order = wc_get_order($order_id);
                
                if ($order) {
                    $orders = array($order);
                } else {
                    return array(
                        'success' => false,
                        'message' => 'Bestellung wurde nicht gefunden'
                    );
                }
            } else {
                return array(
                    'success' => false,
                    'message' => 'Keine zugehörige Bestellung gefunden'
                );
            }
        }
        
        $order = $orders[0];
        
        // Fehlermeldung extrahieren
        $error_message = 'Zahlung fehlgeschlagen';
        if (isset($payment_intent->last_payment_error->message)) {
            $error_message = $payment_intent->last_payment_error->message;
        }
        
        // Bestellung als fehlgeschlagen markieren
        $order->update_status('failed', 'Stripe-Zahlung fehlgeschlagen: ' . $error_message);
        $order->save();
        
        return array(
            'success' => true,
            'message' => 'Fehlgeschlagene Zahlung verarbeitet',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet Rückerstattungen von Stripe
     * 
     * @param object $charge Charge Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_refund($charge) {
        if ($this->debug_mode) {
            $this->log('Verarbeite Stripe-Rückerstattung: ' . $charge->id);
        }
        
        if (!isset($charge->id)) {
            return array(
                'success' => false,
                'message' => 'Charge ID fehlt'
            );
        }
        
        $charge_id = $charge->id;
        
        // Order ID aus Stripe Charge ID finden
        $orders = wc_get_orders(array(
            'meta_key' => '_transaction_id',
            'meta_value' => $charge_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            // Alternative Suche nach Payment Intent ID
            if (isset($charge->payment_intent)) {
                $orders = wc_get_orders(array(
                    'meta_key' => '_stripe_payment_intent',
                    'meta_value' => $charge->payment_intent,
                    'limit' => 1
                ));
                
                if (empty($orders)) {
                    return array(
                        'success' => false,
                        'message' => 'Keine zugehörige Bestellung gefunden'
                    );
                }
            } else {
                return array(
                    'success' => false,
                    'message' => 'Keine zugehörige Bestellung gefunden'
                );
            }
        }
        
        $order = $orders[0];
        
        // Prüfen ob vollständige oder teilweise Rückerstattung
        $refunded_amount = $charge->amount_refunded;
        $total_amount = $charge->amount;
        
        if ($this->debug_mode) {
            $this->log('Rückerstattungsbetrag: ' . $refunded_amount . ' von ' . $total_amount);
        }
        
        // Währung der Bestellung abrufen und Beträge formatieren
        $currency = $order->get_currency();
        $formatted_refunded = $this->format_currency_amount($refunded_amount, $currency);
        $formatted_total = $this->format_currency_amount($total_amount, $currency);
        
        if ($refunded_amount === $total_amount) {
            // Vollständige Rückerstattung
            $order->update_status('refunded', 'Stripe Zahlung vollständig zurückerstattet.');
            if ($this->debug_mode) {
                $this->log('Bestellung vollständig zurückerstattet');
            }
        } else {
            // Teilweise Rückerstattung
            $refund_note = sprintf(
                'Stripe Zahlung teilweise zurückerstattet: %s von %s',
                wc_price($formatted_refunded, array('currency' => $currency)),
                wc_price($formatted_total, array('currency' => $currency))
            );
            $order->add_order_note($refund_note);
            
            // WooCommerce Refund erstellen
            $refund = wc_create_refund(array(
                'order_id' => $order->get_id(),
                'amount' => $formatted_refunded,
                'reason' => 'Stripe Teilrückerstattung (Webhook)'
            ));
            
            if (is_wp_error($refund)) {
                $this->log('Fehler beim Erstellen der WooCommerce-Rückerstattung: ' . $refund->get_error_message(), 'error');
            }
        }
        
        $order->save();
        
        return array(
            'success' => true,
            'message' => 'Rückerstattung erfolgreich verarbeitet',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Formatiert den Betrag von der kleinsten Einheit in die normale Währung
     * 
     * @param int $amount Betrag in kleinster Einheit (z.B. Cent)
     * @param string $currency Währung
     * @return float Formatierter Betrag
     */
    private function format_currency_amount($amount, $currency) {
        // Bestimme die kleinste Einheit der Währung
        $zero_decimal_currencies = array(
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
        );
        
        if (in_array(strtoupper($currency), $zero_decimal_currencies)) {
            // Bei Währungen ohne Dezimalstellen
            return $amount;
        }
        
        // Standard: Dividieren durch 100 für Euro-Beträge
        return $amount / 100;
    }

    /**
     * Formatiert einen Betrag in die kleinste Währungseinheit
     * 
     * @param float $amount Betrag
     * @param string $currency Währung
     * @return int Formatierter Betrag
     */
    public function format_amount($amount, $currency = '') {
        if (empty($currency)) {
            $currency = get_woocommerce_currency();
        }
        
        // Bestimme die kleinste Einheit der Währung
        $zero_decimal_currencies = array(
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
        );
        
        if (in_array(strtoupper($currency), $zero_decimal_currencies)) {
            // Bei Währungen ohne Dezimalstellen, kein Multiplizieren
            return absint($amount);
        }
        
        // Standard: Multipliziere mit 100 für Cent-Beträge
        return absint(round($amount * 100));
    }

    /**
     * Überprüft ein Nonce für sichere Anfragen
     *
     * @param string $action Name der Aktion
     * @param string $request_field Name des Request-Feldes
     */
    private function verify_nonce($action, $request_field) {
        if (!isset($_REQUEST[$request_field]) || !wp_verify_nonce($_REQUEST[$request_field], $action)) {
            wp_send_json_error(array(
                'message' => 'Sicherheitsüberprüfung fehlgeschlagen.'
            ));
            exit;
        }
    }

    /**
     * Führt eine API-Anfrage an Stripe durch
     * 
     * @param string $endpoint API-Endpunkt
     * @param array $args Anfrageparameter
     * @param string $method HTTP-Methode (GET, POST, DELETE)
     * @return object Antwort von Stripe
     */
    private function request_stripe_api($endpoint, $args = array(), $method = 'GET') {
        // Stripe-API einbinden
        $this->load_stripe_api();
        
        try {
            // WC_Stripe_API verwenden, wenn verfügbar
            if (class_exists('WC_Stripe_API')) {
                \WC_Stripe_API::set_secret_key($this->secret_key);
                
                if ($method === 'GET') {
                    return \WC_Stripe_API::retrieve($endpoint, $args);
                } else if ($method === 'POST') {
                    return \WC_Stripe_API::request($args, $endpoint);
                } else if ($method === 'DELETE') {
                    return \WC_Stripe_API::request(array(), $endpoint, 'DELETE');
                }
            }
            
            // Fallback-Implementierung (ohne WC_Stripe_API)
            if (!class_exists('\\Stripe\\Stripe')) {
                throw new Exception('Stripe-API nicht verfügbar');
            }
            
            \Stripe\Stripe::setApiKey($this->secret_key);
            
            if ($method === 'GET') {
                if (strpos($endpoint, 'payment_intents') === 0) {
                    return \Stripe\PaymentIntent::retrieve($endpoint);
                } else if (strpos($endpoint, 'customers') === 0) {
                    return \Stripe\Customer::retrieve($endpoint);
                } else if (strpos($endpoint, 'charges') === 0) {
                    return \Stripe\Charge::retrieve($endpoint);
                }
            } else if ($method === 'POST') {
                if (strpos($endpoint, 'payment_intents') === 0) {
                    return \Stripe\PaymentIntent::create($args);
                } else if (strpos($endpoint, 'customers') === 0) {
                    return \Stripe\Customer::create($args);
                }
            }
            
            throw new Exception('Ungültige API-Anfrage');
        } catch (Exception $e) {
            $this->log('Stripe API Fehler: ' . $e->getMessage(), 'error');
            $error = new stdClass();
            $error->error = new stdClass();
            $error->error->message = $e->getMessage();
            return $error;
        }
    }

    /**
     * Lädt die Stripe-API-Bibliothek
     */
    private function load_stripe_api() {
        // Prüfen, ob die WooCommerce Stripe Gateway-Klasse verfügbar ist
        if (class_exists('WC_Stripe_API')) {
            return;
        }
        
        // Prüfen, ob die Stripe-Bibliothek direkt verfügbar ist
        if (class_exists('\\Stripe\\Stripe')) {
            return;
        }
        
        // Versuchen, die WooCommerce Stripe Gateway-Bibliothek zu laden
        if (file_exists(WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/includes/class-wc-stripe-api.php')) {
            include_once WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/includes/class-wc-stripe-api.php';
            return;
        }
        
        // Versuchen, die Stripe-PHP-Bibliothek zu laden
        if (file_exists(YPRINT_PAYMENT_DIR . 'vendor/autoload.php')) {
            require_once YPRINT_PAYMENT_DIR . 'vendor/autoload.php';
            return;
        }
        
        throw new Exception('Stripe-API-Bibliothek konnte nicht geladen werden.');
    }

    /**
     * Holt die HTTP-Request-Header
     * 
     * @return array Die HTTP-Header
     */
    private function get_request_headers() {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            
            // Konvertiere Header-Namen zu Kleinbuchstaben, da einige Server
            // unterschiedliche Groß-/Kleinschreibung verwenden
            $normalized = array();
            foreach ($headers as $key => $value) {
                $normalized[strtolower($key)] = $value;
            }
            
            return $normalized;
        }
        
        // Fallback für Server ohne getallheaders()
        $headers = array();
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[strtolower($name)] = $value;
            }
        }
        
        return $headers;
    }

    /**
     * Holt die Thank-You-URL für eine Bestellung
     * 
     * @param WC_Order $order Die Bestellung
     * @return string Die Thank-You-URL
     */
    private function get_thank_you_url($order) {
        // Definierte Danke-Seite verwenden, falls vorhanden
        $thank_you_page = get_option('yprint_thank_you_page');
        
        if (!empty($thank_you_page)) {
            return get_permalink($thank_you_page);
        }
        
        // Fallback auf Standard-Dankeseite
        $thank_you_url = get_option('yprint_thank_you_url', 'https://yprint.de/thank-you/');
        
        if (!empty($thank_you_url)) {
            return $thank_you_url;
        }
        
        // Standard WooCommerce Danke-Seite
        return $order->get_checkout_order_received_url();
    }

    /**
     * Prüft, ob ein Feature aktiviert ist
     * 
     * @param string $feature_name Feature-Name
     * @return bool True, wenn das Feature aktiviert ist
     */
    private function is_feature_enabled($feature_name) {
        if ($this->feature_flags && method_exists($this->feature_flags, 'is_enabled')) {
            return $this->feature_flags->is_enabled($feature_name);
        }
        
        // Standardwerte für verschiedene Features
        $defaults = array(
            'stripe_automatic_payment_methods' => false,
            'stripe_sca_support' => true,
            'stripe_refunds' => true,
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG
        );
        
        return isset($defaults[$feature_name]) ? $defaults[$feature_name] : false;
    }

    /**
     * Loggt eine Nachricht
     * 
     * @param string $message Die Nachricht
     * @param string $level Log-Level (info, error, warning)
     */
    private function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[YPrint Stripe] [' . strtoupper($level) . '] ' . $message);
        }
    }
}

/**
 * Gibt die Hauptinstanz von YPrint_Stripe zurück
 * 
 * @return YPrint_Stripe
 */
function YPrint_Stripe() {
    return YPrint_Stripe::instance();
}

// Globale für Abwärtskompatibilität
$GLOBALS['yprint_stripe'] = YPrint_Stripe();