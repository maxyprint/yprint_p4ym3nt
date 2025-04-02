<?php
/**
 * Bestellungsverarbeitungsklasse für YPrint Payment
 *
 * Diese Klasse ist verantwortlich für die Erstellung, Verarbeitung und Verwaltung
 * von Bestellungen im YPrint Payment System. Sie bietet Methoden für die Erstellung
 * temporärer Bestellungen, die Finalisierung von Bestellungen nach erfolgreicher
 * Zahlung und die Bearbeitung von Bestellungsstatus-Aktualisierungen.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bestellungs-Manager Klasse
 */
class YPrint_Order {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_Order
     */
    protected static $_instance = null;

    /**
     * Zahlungsgateway-Handler
     *
     * @var array
     */
    protected $gateways = array();
    
    /**
     * Feature Flags Handler
     *
     * @var YPrint_Feature_Flags
     */
    protected $feature_flags;
    
    /**
     * Session Handler
     *
     * @var YPrint_Session
     */
    protected $session;

    /**
     * Hauptinstanz der YPrint_Order-Klasse
     *
     * @return YPrint_Order - Hauptinstanz
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
        // Feature Flags laden
        $this->feature_flags = class_exists('YPrint_Feature_Flags') ? YPrint_Feature_Flags::instance() : null;
        
        // Session-Handler initialisieren
        $this->session = class_exists('YPrint_Session') ? YPrint_Session::instance() : null;
        
        // Hooks initialisieren
        $this->init_hooks();
    }

    /**
     * Hooks initialisieren
     */
    private function init_hooks() {
        // Order Status Updates
        add_action('woocommerce_order_status_changed', array($this, 'order_status_changed'), 10, 4);
        
        // Order Meta
        add_action('woocommerce_checkout_update_order_meta', array($this, 'update_order_meta'), 10, 2);
        
        // Gateway Register
        add_action('init', array($this, 'register_gateways'), 5);
    }

    /**
     * Zahlungs-Gateways registrieren
     */
    public function register_gateways() {
        if (class_exists('YPrint_Stripe')) {
            $this->gateways['stripe'] = YPrint_Stripe::instance();
        }
        
        if (class_exists('YPrint_PayPal')) {
            $this->gateways['paypal'] = YPrint_PayPal::instance();
        }
        
        if (class_exists('YPrint_SEPA')) {
            $this->gateways['sepa'] = YPrint_SEPA::instance();
        }
        
        if (class_exists('YPrint_Bank_Transfer')) {
            $this->gateways['bank_transfer'] = YPrint_Bank_Transfer::instance();
        }
        
        // Hook für externe Gateway-Registrierung
        $this->gateways = apply_filters('yprint_payment_gateways', $this->gateways);
    }

    /**
     * Erstellt eine temporäre WooCommerce-Bestellung
     *
     * @param array $checkout_data Checkout-Formulardaten
     * @param string $status Initialer Bestellstatus
     * @return int|bool Bestellungs-ID oder false bei Fehler
     */
    public function create_temp_order($checkout_data, $status = 'pending') {
        try {
            // Sicherstellen dass WooCommerce aktiv ist
            if (!function_exists('WC') || !class_exists('WC_Order')) {
                throw new Exception('WooCommerce ist nicht aktiv oder fehlerhaft initialisiert.');
            }
                
            // Warenkorb prüfen
            if (WC()->cart->is_empty()) {
                throw new Exception('Der Warenkorb ist leer. Es kann keine Bestellung erstellt werden.');
            }
            
            // Neues WooCommerce Order-Objekt erstellen
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
                
                // Prüfen ob Produkt noch verfügbar ist
                if (!$product) {
                    continue;
                }
                
                try {
                    // Produkt zur Bestellung hinzufügen mit allen Metadaten
                    $item_id = $order->add_product($product, $quantity, array(
                        'variation' => isset($cart_item['variation']) ? $cart_item['variation'] : array(),
                        'totals' => array(
                            'subtotal' => $cart_item['line_subtotal'],
                            'subtotal_tax' => $cart_item['line_subtotal_tax'],
                            'total' => $cart_item['line_total'],
                            'tax' => $cart_item['line_tax'],
                            'tax_data' => $cart_item['line_tax_data']
                        )
                    ));
                    
                    // Zusätzliche Metadaten übertragen
                    if (isset($cart_item['custom_price'])) {
                        wc_add_order_item_meta($item_id, '_custom_price', $cart_item['custom_price']);
                    }
                    
                    // Zusätzliche Artikeldaten übertragen
                    if (!empty($cart_item['custom_data'])) {
                        foreach ($cart_item['custom_data'] as $key => $value) {
                            if (!empty($value)) {
                                wc_add_order_item_meta($item_id, $key, $value);
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->log('Fehler beim Hinzufügen von Produkt ' . $product->get_id() . ' zur Bestellung: ' . $e->getMessage(), 'error');
                }
            }
            
            // Shipping-Methode hinzufügen
            if (WC()->session && WC()->session->get('chosen_shipping_methods')) {
                $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
                
                if (!empty($chosen_shipping_methods)) {
                    // Standard-Versandmethode
                    $method_id = $chosen_shipping_methods[0];
                    $shipping_rate = new WC_Shipping_Rate(
                        $method_id,
                        'Versand',
                        WC()->cart->get_shipping_total(),
                        WC()->cart->get_shipping_tax(),
                        $method_id
                    );
                    
                    $order->add_shipping($shipping_rate);
                } else {
                    // Fallback auf Flatrate
                    $shipping_rate = new WC_Shipping_Rate(
                        'flat_rate',
                        'Versand',
                        WC()->cart->get_shipping_total(),
                        WC()->cart->get_shipping_tax(),
                        'flat_rate'
                    );
                    $order->add_shipping($shipping_rate);
                }
            }
            
            // Adressen setzen
            if (isset($checkout_data['shipping_address'])) {
                $shipping_address = $checkout_data['shipping_address'];
                
                $order->set_shipping_first_name(isset($shipping_address['first_name']) ? sanitize_text_field($shipping_address['first_name']) : '');
                $order->set_shipping_last_name(isset($shipping_address['last_name']) ? sanitize_text_field($shipping_address['last_name']) : '');
                $order->set_shipping_address_1(isset($shipping_address['address_1']) ? sanitize_text_field($shipping_address['address_1']) : '');
                $order->set_shipping_address_2(isset($shipping_address['address_2']) ? sanitize_text_field($shipping_address['address_2']) : '');
                $order->set_shipping_city(isset($shipping_address['city']) ? sanitize_text_field($shipping_address['city']) : '');
                $order->set_shipping_postcode(isset($shipping_address['postcode']) ? sanitize_text_field($shipping_address['postcode']) : '');
                $order->set_shipping_country(isset($shipping_address['country']) ? sanitize_text_field($shipping_address['country']) : 'DE');
            } else {
                throw new Exception('Keine Lieferadresse angegeben.');
            }
            
            // Billing-Adresse setzen (entweder von different_billing oder von shipping kopieren)
            if (isset($checkout_data['different_billing']) && 
                ($checkout_data['different_billing'] === true || 
                 $checkout_data['different_billing'] === 'true' || 
                 $checkout_data['different_billing'] === 1 || 
                 $checkout_data['different_billing'] === '1') && 
                isset($checkout_data['different_billing_address'])) {
                
                $different_billing = $checkout_data['different_billing_address'];
                
                $order->set_billing_first_name(isset($different_billing['different_billing_first_name']) ? sanitize_text_field($different_billing['different_billing_first_name']) : '');
                $order->set_billing_last_name(isset($different_billing['different_billing_last_name']) ? sanitize_text_field($different_billing['different_billing_last_name']) : '');
                $order->set_billing_address_1(isset($different_billing['different_billing_address_1']) ? sanitize_text_field($different_billing['different_billing_address_1']) : '');
                $order->set_billing_address_2(isset($different_billing['different_billing_address_2']) ? sanitize_text_field($different_billing['different_billing_address_2']) : '');
                $order->set_billing_city(isset($different_billing['different_billing_city']) ? sanitize_text_field($different_billing['different_billing_city']) : '');
                $order->set_billing_postcode(isset($different_billing['different_billing_postcode']) ? sanitize_text_field($different_billing['different_billing_postcode']) : '');
                $order->set_billing_country(isset($different_billing['different_billing_country']) ? sanitize_text_field($different_billing['different_billing_country']) : 'DE');
                $order->set_billing_email(isset($different_billing['different_billing_email']) ? sanitize_email($different_billing['different_billing_email']) : '');
            } else if (isset($checkout_data['shipping_address'])) {
                // Kopiere Shipping Address nach Billing Address
                $shipping_address = $checkout_data['shipping_address'];
                
                $order->set_billing_first_name(isset($shipping_address['first_name']) ? sanitize_text_field($shipping_address['first_name']) : '');
                $order->set_billing_last_name(isset($shipping_address['last_name']) ? sanitize_text_field($shipping_address['last_name']) : '');
                $order->set_billing_address_1(isset($shipping_address['address_1']) ? sanitize_text_field($shipping_address['address_1']) : '');
                $order->set_billing_address_2(isset($shipping_address['address_2']) ? sanitize_text_field($shipping_address['address_2']) : '');
                $order->set_billing_city(isset($shipping_address['city']) ? sanitize_text_field($shipping_address['city']) : '');
                $order->set_billing_postcode(isset($shipping_address['postcode']) ? sanitize_text_field($shipping_address['postcode']) : '');
                $order->set_billing_country(isset($shipping_address['country']) ? sanitize_text_field($shipping_address['country']) : 'DE');
                
                // E-Mail-Adresse aus Benutzeraccount oder Session
                if ($user_id > 0) {
                    $user = get_userdata($user_id);
                    $order->set_billing_email($user->user_email);
                } else if (WC()->session && WC()->session->get('customer') && isset(WC()->session->get('customer')['email'])) {
                    $order->set_billing_email(sanitize_email(WC()->session->get('customer')['email']));
                }
            }
            
            // Zahlungsmethode setzen
            if (isset($checkout_data['payment_method'])) {
                $order->set_payment_method(sanitize_text_field($checkout_data['payment_method']));
                $order->set_payment_method_title($this->get_payment_method_title($checkout_data['payment_method']));
            }
            
            // Gutschein anwenden, falls vorhanden
            if (isset($checkout_data['coupon_code']) && !empty($checkout_data['coupon_code'])) {
                $coupon_code = sanitize_text_field($checkout_data['coupon_code']);
                $coupon = new WC_Coupon($coupon_code);
                
                if ($coupon->is_valid()) {
                    $order->apply_coupon($coupon_code);
                }
            }
            
            // Meta-Tag hinzufügen, um temporäre Bestellungen zu kennzeichnen
            update_post_meta($order->get_id(), '_yprint_temp_order', 'yes');
            update_post_meta($order->get_id(), '_yprint_checkout_data', $checkout_data);
            
            // Bestellung speichern mit dem gewünschten Status
            $order->calculate_totals();
            
            // Status festlegen
            if (!empty($status)) {
                $order->update_status($status, __('Temporäre Bestellung erstellt für Zahlungsverarbeitung.', 'yprint-payment'));
            }
            
            $order->save();
            
            // Ereignis auslösen
            do_action('yprint_temp_order_created', $order->get_id(), $checkout_data);
            
            // Bestellungs-ID zurückgeben
            return $order->get_id();
        } catch (Exception $e) {
            $this->log('Fehler bei temporärer Bestellerstellung: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Finalisiert eine Bestellung nach erfolgreicher Zahlung
     *
     * @param int $order_id Bestellungs-ID
     * @param string $payment_id Zahlungs-ID vom Gateway
     * @param string $payment_method Zahlungsmethode
     * @return array Ergebnis der Finalisierung
     */
    public function finalize_order($order_id, $payment_id, $payment_method) {
        try {
            // Bestellung abrufen
            $order = wc_get_order($order_id);
            
            if (!$order) {
                throw new Exception('Bestellung konnte nicht gefunden werden.');
            }
            
            // Zahlungsinformationen speichern
            if (!empty($payment_id)) {
                $order->set_transaction_id($payment_id);
                update_post_meta($order_id, '_payment_transaction_id', $payment_id);
            }
            
            if (!empty($payment_method)) {
                update_post_meta($order_id, '_payment_method', $payment_method);
                $order->set_payment_method($payment_method);
                $order->set_payment_method_title($this->get_payment_method_title($payment_method));
            }
            
            // Gateway-spezifische Metadaten
            $this->set_payment_gateway_meta($order, $payment_method, $payment_id);
            
            // Bestellung als bezahlt markieren, außer bei Überweisung
            if (strpos($payment_method, 'bacs') !== false || strpos($payment_method, 'bank') !== false) {
                // Bei Überweisung: Status auf "on-hold" setzen
                $order->update_status('on-hold', __('Warte auf Zahlungseingang via Überweisung.', 'yprint-payment'));
            } else {
                // Bei anderen Zahlungsmethoden: als bezahlt markieren
                $order->payment_complete($payment_id);
            }
            
            // Notiz hinzufügen
            $order->add_order_note(__('Zahlung erfolgreich verarbeitet.', 'yprint-payment'));
            
            // Kundendaten speichern, wenn angemeldet
            $user_id = get_current_user_id();
            if ($user_id > 0) {
                $order->set_customer_id($user_id);
            }
            
            // Entferne temporäre Markierung
            delete_post_meta($order_id, '_yprint_temp_order');
            
            // Bestellung speichern
            $order->save();
            
            // Warenkorb leeren
            WC()->cart->empty_cart();
            
            // Session-Daten löschen
            if (WC()->session) {
                WC()->session->set('yprint_temp_order_id', '');
                WC()->session->set('yprint_checkout_state', array());
            }
            
            // Ereignis auslösen
            do_action('yprint_order_finalized', $order_id, $payment_id, $payment_method);
            
            // Erfolg melden
            return array(
                'success' => true,
                'order_id' => $order_id,
                'redirect' => $this->get_thank_you_url($order)
            );
        } catch (Exception $e) {
            $this->log('Fehler bei der Finalisierung der Bestellung: ' . $e->getMessage(), 'error');
            
            return array(
                'success' => false,
                'message' => 'Fehler bei der Finalisierung der Bestellung: ' . $e->getMessage()
            );
        }
    }

    /**
     * Speichert Gateway-spezifische Metadaten für die Bestellung
     *
     * @param WC_Order $order Bestellung
     * @param string $payment_method Zahlungsmethode
     * @param string $payment_id Zahlungs-ID
     */
    private function set_payment_gateway_meta($order, $payment_method, $payment_id) {
        $order_id = $order->get_id();
        
        // Stripe
        if (strpos($payment_method, 'stripe') !== false) {
            update_post_meta($order_id, '_stripe_payment_intent', $payment_id);
            
            // SCA-Status, falls verfügbar
            if (WC()->session && WC()->session->get('yprint_stripe_sca_required')) {
                update_post_meta($order_id, '_stripe_sca_required', WC()->session->get('yprint_stripe_sca_required'));
            }
        }
        
        // PayPal
        else if (strpos($payment_method, 'paypal') !== false) {
            update_post_meta($order_id, '_paypal_order_id', $payment_id);
            
            // PayPal Transaktions-ID, falls verfügbar
            if (WC()->session && WC()->session->get('yprint_paypal_transaction_id')) {
                update_post_meta($order_id, '_paypal_transaction_id', WC()->session->get('yprint_paypal_transaction_id'));
            }
        }
        
        // SEPA
        else if (strpos($payment_method, 'sepa') !== false || strpos($payment_method, 'lastschrift') !== false) {
            update_post_meta($order_id, '_sepa_payment_id', $payment_id);
            
            // SEPA Mandatsreferenz, falls verfügbar
            if (WC()->session && WC()->session->get('yprint_sepa_mandate_reference')) {
                update_post_meta($order_id, '_sepa_mandate_reference', WC()->session->get('yprint_sepa_mandate_reference'));
            }
        }
        
        // Banküberweisung
        else if (strpos($payment_method, 'bacs') !== false || strpos($payment_method, 'bank') !== false) {
            update_post_meta($order_id, '_bank_transfer_reference', 'YP-' . $order_id . '-' . substr(md5(time()), 0, 6));
        }
        
        // Gateway-spezifischer Hook für benutzerdefinierte Gateways
        do_action('yprint_set_payment_gateway_meta', $order, $payment_method, $payment_id);
    }

    /**
     * Handler für WooCommerce Bestellstatus-Änderungen
     *
     * @param int $order_id Bestellungs-ID
     * @param string $old_status Alter Status
     * @param string $new_status Neuer Status
     * @param WC_Order $order Bestellungsobjekt
     */
    public function order_status_changed($order_id, $old_status, $new_status, $order) {
        // Prüfen, ob es eine YPrint-Bestellung ist
        $is_yprint_order = get_post_meta($order_id, '_yprint_order', true) || get_post_meta($order_id, '_yprint_temp_order', true);
        
        if (!$is_yprint_order) {
            return;
        }
        
        // Bei Stornierung und Rückerstattung
        if ($new_status === 'cancelled' || $new_status === 'refunded') {
            $this->handle_order_cancellation($order, $old_status, $new_status);
        }
        
        // Bei Abschluss
        else if ($new_status === 'completed') {
            $this->handle_order_completion($order, $old_status, $new_status);
        }
        
        // Bei Zahlung erhalten
        else if ($new_status === 'processing' && $old_status === 'pending') {
            $this->handle_payment_received($order, $old_status, $new_status);
        }
        
        // Hook für benutzerdefinierte Statusänderungen
        do_action('yprint_order_status_changed', $order_id, $old_status, $new_status, $order);
    }

    /**
     * Behandelt Bestellabbruch und Rückerstattungen
     *
     * @param WC_Order $order
     * @param string $old_status
     * @param string $new_status
     */
    private function handle_order_cancellation($order, $old_status, $new_status) {
        $order_id = $order->get_id();
        $payment_method = $order->get_payment_method();
        $transaction_id = $order->get_transaction_id();
        
        // Nur verarbeiten, wenn eine Transaktion vorhanden ist
        if (empty($transaction_id)) {
            return;
        }
        
        // Wende Gateway-spezifische Stornierungslogik an
        if (strpos($payment_method, 'stripe') !== false) {
            $this->process_stripe_cancellation($order, $transaction_id);
        } 
        else if (strpos($payment_method, 'paypal') !== false) {
            $this->process_paypal_cancellation($order, $transaction_id);
        }
        
        // Hook für benutzerdefinierte Gateways
        do_action('yprint_process_order_cancellation', $order, $payment_method, $transaction_id);
    }

    /**
     * Behandelt die Stornierung einer Stripe-Zahlung
     *
     * @param WC_Order $order
     * @param string $transaction_id
     */
    private function process_stripe_cancellation($order, $transaction_id) {
        // Prüfen, ob wir tatsächlich eine Rückerstattung vornehmen müssen
        if ($order->get_status() !== 'refunded' || !$this->is_feature_enabled('stripe_refunds')) {
            return;
        }
        
        try {
            if (!class_exists('WC_Stripe_API')) {
                require_once WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/includes/class-wc-stripe-api.php';
            }
            
            $stripe_secret_key = get_option('yprint_stripe_secret_key', 'INSERT_API_KEY_HERE');
            \WC_Stripe_API::set_secret_key($stripe_secret_key);
            
            // Bestimme, ob wir einen PaymentIntent oder Charge haben
            $payment_intent_id = get_post_meta($order->get_id(), '_stripe_payment_intent', true);
            
            if (!empty($payment_intent_id)) {
                // Hole den PaymentIntent, um die zugehörige Charge zu finden
                $payment_intent = \WC_Stripe_API::retrieve('payment_intents/' . $payment_intent_id);
                
                if (empty($payment_intent->charges->data[0]->id)) {
                    throw new Exception('Keine Charge ID für die Rückerstattung gefunden.');
                }
                
                $charge_id = $payment_intent->charges->data[0]->id;
            } else {
                // Fallback auf Transaction ID als Charge ID
                $charge_id = $transaction_id;
            }
            
            // Führe die Rückerstattung durch
            $refund_response = \WC_Stripe_API::request(array(
                'amount' => $this->get_stripe_amount($order->get_total(), $order->get_currency()),
                'charge' => $charge_id,
                'reason' => 'requested_by_customer',
                'metadata' => array(
                    'order_id' => $order->get_id(),
                    'refund_reason' => 'Customer requested refund'
                )
            ), 'refunds');
            
            if (!empty($refund_response->error)) {
                throw new Exception($refund_response->error->message);
            }
            
            $order->add_order_note(sprintf(
                __('Rückerstattung über Stripe erfolgreich durchgeführt (Rückerstattungs-ID: %s)', 'yprint-payment'),
                $refund_response->id
            ));
            
            // Speichere Rückerstattungs-ID
            update_post_meta($order->get_id(), '_stripe_refund_id', $refund_response->id);
            
        } catch (Exception $e) {
            $this->log('Fehler bei der Stripe-Rückerstattung: ' . $e->getMessage(), 'error');
            
            $order->add_order_note(sprintf(
                __('Fehler bei der Stripe-Rückerstattung: %s', 'yprint-payment'),
                $e->getMessage()
            ));
        }
    }

    /**
     * Behandelt die Stornierung einer PayPal-Zahlung
     *
     * @param WC_Order $order
     * @param string $transaction_id
     */
    private function process_paypal_cancellation($order, $transaction_id) {
        // Prüfen, ob wir tatsächlich eine Rückerstattung vornehmen müssen
        if ($order->get_status() !== 'refunded' || !$this->is_feature_enabled('paypal_refunds')) {
            return;
        }
        
        try {
            // PayPal SDK laden
            if (!class_exists('PayPalCheckoutSdk\Core\PayPalHttpClient')) {
                // Hier SDK laden oder Fehler ausgeben
                throw new Exception('PayPal SDK nicht verfügbar.');
            }
            
            // API-Zugangsdaten
            $client_id = get_option('yprint_paypal_client_id', 'INSERT_API_KEY_HERE');
            $client_secret = get_option('yprint_paypal_secret_key', 'INSERT_API_KEY_HERE');
            
            // Produktions- oder Sandbox-Umgebung
            $environment = get_option('yprint_paypal_sandbox', 'no') === 'yes' 
                ? new \PayPalCheckoutSdk\Core\SandboxEnvironment($client_id, $client_secret)
                : new \PayPalCheckoutSdk\Core\ProductionEnvironment($client_id, $client_secret);
            
            $client = new \PayPalCheckoutSdk\Core\PayPalHttpClient($environment);
            
            // PayPal Capture ID oder Order ID
            $paypal_transaction_id = get_post_meta($order->get_id(), '_paypal_transaction_id', true);
            
            if (empty($paypal_transaction_id)) {
                // Fallback auf stored Order ID
                $paypal_transaction_id = get_post_meta($order->get_id(), '_paypal_order_id', true);
                
                if (empty($paypal_transaction_id)) {
                    // Letzter Fallback auf Transaction ID
                    $paypal_transaction_id = $transaction_id;
                }
            }
            
            // Refund Request erstellen
            $request = new \PayPalCheckoutSdk\Payments\CapturesRefundRequest($paypal_transaction_id);
            $request->body = array(
                'amount' => array(
                    'value' => $order->get_total(),
                    'currency_code' => $order->get_currency()
                ),
                'note_to_payer' => 'Refund for order #' . $order->get_id()
            );
            
            // Refund durchführen
            $response = $client->execute($request);
            
            if ($response->statusCode === 201 || $response->statusCode === 200) {
                $refund_id = $response->result->id;
                
                $order->add_order_note(sprintf(
                    __('Rückerstattung über PayPal erfolgreich durchgeführt (Rückerstattungs-ID: %s)', 'yprint-payment'),
                    $refund_id
                ));
                
                // Speichere Rückerstattungs-ID
                update_post_meta($order->get_id(), '_paypal_refund_id', $refund_id);
                
            } else {
                throw new Exception('Unerwarteter Antwortcode: ' . $response->statusCode);
            }
            
        } catch (Exception $e) {
            $this->log('Fehler bei der PayPal-Rückerstattung: ' . $e->getMessage(), 'error');
            
            $order->add_order_note(sprintf(
                __('Fehler bei der PayPal-Rückerstattung: %s', 'yprint-payment'),
                $e->getMessage()
            ));
        }
    }

    /**
     * Behandelt den Abschluss einer Bestellung
     *
     * @param WC_Order $order
     * @param string $old_status
     * @param string $new_status
     */
    private function handle_order_completion($order, $old_status, $new_status) {
        // Markiere als abgeschlossen in YPrint-Meta
        update_post_meta($order->get_id(), '_yprint_order_completed', 'yes');
        update_post_meta($order->get_id(), '_yprint_completion_date', current_time('mysql'));
        
        // Hook für benutzerdefinierte Aktionen
        do_action('yprint_order_completed', $order, $old_status, $new_status);
        
        // Benachrichtigung senden, falls aktiviert
        if ($this->is_feature_enabled('completion_notifications')) {
            $this->send_completion_notification($order);
        }
    }

    /**
     * Behandelt den Zahlungseingang
     *
     * @param WC_Order $order
     * @param string $old_status
     * @param string $new_status
     */
    private function handle_payment_received($order, $old_status, $new_status) {
        // Markiere als bezahlt in YPrint-Meta
        update_post_meta($order->get_id(), '_yprint_payment_received', 'yes');
        update_post_meta($order->get_id(), '_yprint_payment_date', current_time('mysql'));
        
        // Hook für benutzerdefinierte Aktionen
        do_action('yprint_payment_received', $order, $old_status, $new_status);
        
        // Benachrichtigung senden, falls aktiviert
        if ($this->is_feature_enabled('payment_notifications')) {
            $this->send_payment_notification($order);
        }
    }

    /**
     * Sendet eine Benachrichtigung über den Zahlungseingang
     *
     * @param WC_Order $order
     */
    private function send_payment_notification($order) {
        // Admin-Benachrichtigung
        if (get_option('yprint_notify_admin_payment', 'yes') === 'yes') {
            $admin_email = get_option('admin_email');
            $subject = sprintf(__('Neue Zahlung für Bestellung #%s eingegangen', 'yprint-payment'), $order->get_id());
            
            $message = sprintf(
                __('Eine neue Zahlung für Bestellung #%1$s von %2$s %3$s ist eingegangen.', 'yprint-payment'),
                $order->get_id(),
                $order->get_billing_first_name(),
                $order->get_billing_last_name()
            );
            
            $message .= "\n\n";
            $message .= sprintf(__('Bestellsumme: %s', 'yprint-payment'), $order->get_formatted_order_total());
            $message .= "\n\n";
            $message .= sprintf(__('Zahlungsmethode: %s', 'yprint-payment'), $order->get_payment_method_title());
            $message .= "\n\n";
            $message .= sprintf(__('Bestellung anzeigen: %s', 'yprint-payment'), admin_url('post.php?post=' . $order->get_id() . '&action=edit'));
            
            wp_mail($admin_email, $subject, $message);
        }
    }

    /**
     * Sendet eine Benachrichtigung über den Bestellabschluss
     *
     * @param WC_Order $order
     */
    private function send_completion_notification($order) {
        // Kundenbenachrichtigung
        if (get_option('yprint_notify_customer_completion', 'yes') === 'yes') {
            $customer_email = $order->get_billing_email();
            $subject = sprintf(__('Deine Bestellung #%s wurde abgeschlossen', 'yprint-payment'), $order->get_id());
            
            $message = sprintf(
                __('Hallo %1$s %2$s,', 'yprint-payment'),
                $order->get_billing_first_name(),
                $order->get_billing_last_name()
            );
            
            $message .= "\n\n";
            $message .= sprintf(__('Deine Bestellung #%s wurde erfolgreich abgeschlossen.', 'yprint-payment'), $order->get_id());
            $message .= "\n\n";
            $message .= __('Vielen Dank für deinen Einkauf bei YPrint!', 'yprint-payment');
            $message .= "\n\n";
            $message .= sprintf(__('Bestelldetails anzeigen: %s', 'yprint-payment'), $order->get_view_order_url());
            
            // HTML-E-Mail, falls aktiviert
            if (get_option('yprint_html_emails', 'yes') === 'yes') {
                add_filter('wp_mail_content_type', function() { return 'text/html'; });
                
                $message = nl2br($message);
                
                // Hinzufügen von HTML-Styling
                $message = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 5px;">' . 
                           '<h2 style="color: #0079FF;">Deine Bestellung wurde abgeschlossen</h2>' .
                           '<p>' . $message . '</p>' .
                           '<p style="text-align: center; margin-top: 30px;"><a href="' . $order->get_view_order_url() . '" style="display: inline-block; padding: 10px 20px; background-color: #0079FF; color: white; text-decoration: none; border-radius: 4px;">Bestellung anzeigen</a></p>' .
                           '</div>';
            }
            
            wp_mail($customer_email, $subject, $message);
            
            // Filter zurücksetzen
            if (get_option('yprint_html_emails', 'yes') === 'yes') {
                remove_filter('wp_mail_content_type', function() { return 'text/html'; });
            }
        }
    }

    /**
     * Prüft, ob ein Feature aktiviert ist
     *
     * @param string $feature_name
     * @return bool
     */
    private function is_feature_enabled($feature_name) {
        if ($this->feature_flags && method_exists($this->feature_flags, 'is_enabled')) {
            return $this->feature_flags->is_enabled($feature_name);
        }
        
        // Default-Werte für verschiedene Features
        $defaults = array(
            'payment_notifications' => true,
            'completion_notifications' => true,
            'stripe_refunds' => true,
            'paypal_refunds' => true
        );
        
        return isset($defaults[$feature_name]) ? $defaults[$feature_name] : false;
    }

    /**
     * Aktualisiert Bestellungs-Metadaten bei Checkout
     *
     * @param int $order_id Bestellungs-ID
     * @param array $posted_data Formular-Daten
     */
    public function update_order_meta($order_id, $posted_data) {
        // Speichere YPrint-Meta für neuere Bestellungen
        update_post_meta($order_id, '_yprint_order', 'yes');
        update_post_meta($order_id, '_yprint_order_version', YPRINT_PAYMENT_VERSION);
        
        // Zusätzliche Metadaten speichern
        if (isset($posted_data['billing_phone'])) {
            update_post_meta($order_id, '_billing_phone', sanitize_text_field($posted_data['billing_phone']));
        }
        
        // Checkout-Referenzen speichern, falls vorhanden
        $checkout_reference = filter_input(INPUT_COOKIE, 'yprint_checkout_reference');
        if (!empty($checkout_reference)) {
            update_post_meta($order_id, '_yprint_checkout_reference', sanitize_text_field($checkout_reference));
        }
    }

    /**
     * Konvertiert einen Betrag in das Stripe-Format
     *
     * @param float $amount Betrag
     * @param string $currency Währung
     * @return int Formatierter Betrag für Stripe
     */
    public function get_stripe_amount($amount, $currency = '') {
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
     * Ruft den Titel einer Zahlungsmethode ab
     *
     * @param string $payment_method Zahlungsmethode-ID
     * @return string Zahlungsmethode-Titel
     */
    public function get_payment_method_title($payment_method) {
        $payment_gateways = WC()->payment_gateways()->payment_gateways();
        
        if (isset($payment_gateways[$payment_method])) {
            return $payment_gateways[$payment_method]->get_title();
        }
        
        // Fallback-Titel für bekannte Methoden
        $fallback_titles = array(
            'paypal' => 'PayPal',
            'stripe' => 'Kreditkarte (Stripe)',
            'stripe_cc' => 'Kreditkarte',
            'stripe_sepa' => 'SEPA-Lastschrift',
            'stripe_sofort' => 'Sofortüberweisung',
            'stripe_giropay' => 'giropay',
            'stripe_ideal' => 'iDEAL',
            'stripe_bancontact' => 'Bancontact',
            'stripe_eps' => 'EPS',
            'stripe_p24' => 'Przelewy24',
            'bacs' => 'Überweisung',
            'cod' => 'Nachnahme',
            'sepa' => 'SEPA-Lastschrift',
            'klarna' => 'Klarna',
            'sofort' => 'Sofortüberweisung'
        );
        
        // Prüfen auf partielle Übereinstimmungen
        foreach ($fallback_titles as $key => $title) {
            if (strpos($payment_method, $key) !== false) {
                return $title;
            }
        }
        
        return __('Unbekannte Zahlungsmethode', 'yprint-payment');
    }

    /**
     * Gibt die Thank-You-URL für eine Bestellung zurück
     *
     * @param WC_Order $order Bestellung
     * @return string Danke-Seiten-URL
     */
    public function get_thank_you_url($order) {
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
     * Loggt Nachrichten in die Debug-Logs
     *
     * @param string $message Nachricht
     * @param string $level Log-Level (info, warning, error)
     */
    private function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
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
            
            error_log('[YPrint Order] [' . strtoupper($level) . '] ' . $message);
        }
    }
}

// Hilfsfunktion für globalen Zugriff
function YPrint_Order() {
    return YPrint_Order::instance();
}

// Globale für Abwärtskompatibilität
$GLOBALS['yprint_order'] = YPrint_Order();