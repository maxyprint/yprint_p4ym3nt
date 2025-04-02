<?php
/**
 * PayPal-Zahlungsintegration für YPrint Payment
 *
 * Diese Klasse implementiert die Integration mit PayPal als Zahlungsdienstleister.
 * Sie enthält Methoden für die Zahlungsinitialisierung, Verarbeitung von Webhooks,
 * und Zahlungsverifizierung.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PayPal Zahlungs-Gateway Klasse
 */
class YPrint_PayPal {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_PayPal
     */
    protected static $_instance = null;

    /**
     * PayPal API-Schlüssel
     *
     * @var string
     */
    private $client_id;

    /**
     * PayPal API Secret
     *
     * @var string
     */
    private $client_secret;

    /**
     * PayPal Webhook ID
     *
     * @var string
     */
    private $webhook_id;

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
     * API-Basis-URL
     * 
     * @var string
     */
    private $api_url;

    /**
     * Hauptinstanz der YPrint_PayPal-Klasse
     *
     * @return YPrint_PayPal - Hauptinstanz
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
        add_action('wp_ajax_yprint_init_paypal_payment', array($this, 'ajax_init_paypal_payment'));
        add_action('wp_ajax_nopriv_yprint_init_paypal_payment', array($this, 'ajax_init_paypal_payment'));
        
        // AJAX Hooks für Zahlungserfassung (Capture)
        add_action('wp_ajax_yprint_capture_checkout_paypal_payment', array($this, 'ajax_capture_paypal_payment'));
        add_action('wp_ajax_nopriv_yprint_capture_checkout_paypal_payment', array($this, 'ajax_capture_paypal_payment'));
        
        // AJAX Hooks für Zahlungsbestätigung
        add_action('wp_ajax_yprint_verify_paypal_return', array($this, 'ajax_verify_paypal_return'));
        add_action('wp_ajax_nopriv_yprint_verify_paypal_return', array($this, 'ajax_verify_paypal_return'));
        
        // Webhook Handler
        add_action('init', array($this, 'handle_webhook'));
    }

    /**
     * API-Schlüssel laden
     */
    private function load_api_keys() {
        $this->test_mode = get_option('yprint_paypal_test_mode', 'no') === 'yes';
        
        if ($this->test_mode) {
            $this->client_id = get_option('yprint_paypal_test_client_id', 'INSERT_API_KEY_HERE');
            $this->client_secret = get_option('yprint_paypal_test_secret_key', 'INSERT_API_KEY_HERE');
            $this->webhook_id = get_option('yprint_paypal_test_webhook_id', 'INSERT_API_KEY_HERE');
            $this->api_url = 'https://api-m.sandbox.paypal.com/';
        } else {
            $this->client_id = get_option('yprint_paypal_client_id', 'INSERT_API_KEY_HERE');
            $this->client_secret = get_option('yprint_paypal_secret_key', 'INSERT_API_KEY_HERE');
            $this->webhook_id = get_option('yprint_paypal_webhook_id', 'INSERT_API_KEY_HERE');
            $this->api_url = 'https://api-m.paypal.com/';
        }
    }

    /**
     * AJAX-Handler für PayPal-Zahlung initialisieren
     */
    public function ajax_init_paypal_payment() {
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
            // OAuth Token abrufen
            $token = $this->get_access_token();
            
            if (empty($token)) {
                throw new Exception('OAuth-Token konnte nicht abgerufen werden');
            }
            
            // Kundenadresse aus Checkout-Daten vorbereiten
            $customer_info = $this->prepare_customer_info($checkout_data);
            
            // Warenkorb-Daten für PayPal aufbereiten
            $total = WC()->cart->get_total('numeric');
            $subtotal = WC()->cart->get_subtotal();
            $shipping = WC()->cart->get_shipping_total();
            $tax = WC()->cart->get_total_tax();
            $discount = WC()->cart->get_discount_total();
            
            // Währung abrufen
            $currency = get_woocommerce_currency();
            
            // Eindeutige Referenz-ID erstellen
            $reference_id = 'yp_' . time() . '_' . rand(1000, 9999);
            
            // PayPal Order-Daten erstellen
            $order_data = $this->create_order_data($currency, $total, $subtotal, $shipping, $tax, $discount, $reference_id, $customer_info, $temp_order_id);
            
            // API-Header für PayPal
            $headers = array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            );
            
            // PayPal Order erstellen
            $response = $this->request_paypal_api('v2/checkout/orders', $order_data, 'POST', $headers);
            
            if (isset($response['error'])) {
                throw new Exception($response['error_description'] ?? 'Fehler bei der Kommunikation mit PayPal');
            }
            
            // Debug-Logging
            if ($this->debug_mode) {
                $this->log('PayPal Order erstellt: ' . ($response['id'] ?? 'Keine ID'));
            }
            
            // PayPal Order ID in Session speichern
            if (class_exists('WC_Session') && WC()->session) {
                WC()->session->set('yprint_paypal_order_id', $response['id']);
                WC()->session->set('yprint_paypal_reference_id', $reference_id);
            }
            
            // Mit temporärer Bestellung verknüpfen, falls vorhanden
            if ($temp_order_id > 0) {
                update_post_meta($temp_order_id, '_paypal_order_id', $response['id']);
            }
            
            wp_send_json_success(array(
                'paypal_order_id' => $response['id'],
                'client_id' => $this->client_id
            ));
        } catch (Exception $e) {
            $this->log('Fehler bei der PayPal-Initialisierung: ' . $e->getMessage(), 'error');
            
            wp_send_json_error(array(
                'message' => 'Fehler bei der PayPal-Initialisierung: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX-Handler für die PayPal-Zahlung erfassen (Capture)
     */
    public function ajax_capture_paypal_payment() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (!isset($_POST['paypal_order_id']) || empty($_POST['paypal_order_id'])) {
            wp_send_json_error(array(
                'message' => 'Keine PayPal Order ID vorhanden'
            ));
            return;
        }
        
        $paypal_order_id = sanitize_text_field($_POST['paypal_order_id']);
        $temp_order_id = isset($_POST['temp_order_id']) ? intval($_POST['temp_order_id']) : 0;
        
        try {
            // OAuth Token abrufen
            $token = $this->get_access_token();
            
            if (empty($token)) {
                throw new Exception('OAuth-Token konnte nicht abgerufen werden');
            }
            
            // API-Header für PayPal
            $headers = array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Prefer' => 'return=representation'
            );
            
            // PayPal Zahlung erfassen (Capture)
            $response = $this->request_paypal_api('v2/checkout/orders/' . $paypal_order_id . '/capture', array(), 'POST', $headers);
            
            if (isset($response['error'])) {
                throw new Exception($response['error_description'] ?? 'Fehler bei der Zahlungserfassung');
            }
            
            // Debug-Logging
            if ($this->debug_mode) {
                $this->log('PayPal Zahlung erfasst: ' . ($response['id'] ?? 'Keine ID'));
            }
            
            // Transaktions-ID extrahieren
            $transaction_id = null;
            if (isset($response['purchase_units']) && is_array($response['purchase_units']) && !empty($response['purchase_units'][0]['payments']['captures'])) {
                $transaction_id = $response['purchase_units'][0]['payments']['captures'][0]['id'];
            }
            
            // Wenn keine Transaktions-ID gefunden wurde, Fallback auf Order-ID
            if (empty($transaction_id)) {
                $transaction_id = $paypal_order_id;
            }
            
            // Zahlungsdetails in der Session speichern
            if (class_exists('WC_Session') && WC()->session) {
                WC()->session->set('yprint_paypal_transaction_id', $transaction_id);
                WC()->session->set('yprint_paypal_payment_status', 'COMPLETED');
            }
            
            // Mit temporärer Bestellung verknüpfen, falls vorhanden
            if ($temp_order_id > 0) {
                update_post_meta($temp_order_id, '_paypal_transaction_id', $transaction_id);
                update_post_meta($temp_order_id, '_transaction_id', $transaction_id);
            }
            
            wp_send_json_success(array(
                'message' => 'Zahlung erfolgreich erfasst',
                'transaction_id' => $transaction_id
            ));
        } catch (Exception $e) {
            $this->log('Fehler bei der PayPal-Zahlungserfassung: ' . $e->getMessage(), 'error');
            
            wp_send_json_error(array(
                'message' => 'Fehler bei der Zahlungserfassung: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX-Handler zur Bestätigung einer PayPal-Rückgabe
     */
    public function ajax_verify_paypal_return() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (!isset($_POST['paypal_order_id']) || empty($_POST['paypal_order_id'])) {
            wp_send_json_error(array(
                'message' => 'PayPal Order ID fehlt'
            ));
            return;
        }
        
        $paypal_order_id = sanitize_text_field($_POST['paypal_order_id']);
        
        try {
            // OAuth Token abrufen
            $token = $this->get_access_token();
            
            if (empty($token)) {
                throw new Exception('OAuth-Token konnte nicht abgerufen werden');
            }
            
            // API-Header für PayPal
            $headers = array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            );
            
            // PayPal Order-Status abrufen
            $response = $this->request_paypal_api('v2/checkout/orders/' . $paypal_order_id, array(), 'GET', $headers);
            
            if (isset($response['error'])) {
                throw new Exception($response['error_description'] ?? 'Fehler beim Abrufen des Order-Status');
            }
            
            $paypal_status = $response['status'] ?? '';
            
            if ($paypal_status === 'COMPLETED' || $paypal_status === 'APPROVED') {
                // Bei APPROVED versuchen, die Zahlung zu erfassen
                if ($paypal_status === 'APPROVED') {
                    // Zahlung erfassen
                    $capture_response = $this->request_paypal_api('v2/checkout/orders/' . $paypal_order_id . '/capture', array(), 'POST', array_merge($headers, array('Prefer' => 'return=representation')));
                    
                    if (isset($capture_response['error'])) {
                        throw new Exception($capture_response['error_description'] ?? 'Fehler bei der Zahlungserfassung');
                    }
                    
                    // Transaktions-ID extrahieren
                    $transaction_id = null;
                    if (isset($capture_response['purchase_units']) && is_array($capture_response['purchase_units']) && !empty($capture_response['purchase_units'][0]['payments']['captures'])) {
                        $transaction_id = $capture_response['purchase_units'][0]['payments']['captures'][0]['id'];
                    } else {
                        $transaction_id = $paypal_order_id;
                    }
                } else {
                    // Bei COMPLETED die Transaktions-ID aus der Antwort extrahieren
                    $transaction_id = null;
                    if (isset($response['purchase_units']) && is_array($response['purchase_units']) && !empty($response['purchase_units'][0]['payments']['captures'])) {
                        $transaction_id = $response['purchase_units'][0]['payments']['captures'][0]['id'];
                    } else {
                        $transaction_id = $paypal_order_id;
                    }
                }
                
                // Order ID aus PayPal-Order ID finden
                $orders = wc_get_orders(array(
                    'meta_key' => '_paypal_order_id',
                    'meta_value' => $paypal_order_id,
                    'limit' => 1
                ));
                
                if (empty($orders)) {
                    // Erstelle neue Bestellung, wenn keine gefunden wurde
                    $order_id = $this->create_order_from_session($paypal_order_id, $transaction_id);
                    if (!$order_id) {
                        throw new Exception('Keine zugehörige Bestellung gefunden und Erstellung fehlgeschlagen');
                    }
                    $order = wc_get_order($order_id);
                } else {
                    $order = $orders[0];
                }
                
                // Bestellung aktualisieren
                if (!$order->is_paid()) {
                    $order->payment_complete($transaction_id);
                    $order->add_order_note('PayPal-Zahlung abgeschlossen (Transaktion ID: ' . $transaction_id . ')');
                    
                    // Zahlungsmethode setzen
                    $order->set_payment_method('paypal');
                    $order->set_payment_method_title('PayPal');
                    
                    $order->save();
                }
                
                // Warenkorb leeren
                WC()->cart->empty_cart();
                
                // Thank-You-URL
                $redirect_url = $this->get_thank_you_url($order);
                
                wp_send_json_success(array(
                    'redirect' => $redirect_url
                ));
            } else {
                throw new Exception('PayPal-Status ist nicht COMPLETED oder APPROVED: ' . $paypal_status);
            }
        } catch (Exception $e) {
            $this->log('Fehler bei der PayPal-Zahlungsverifizierung: ' . $e->getMessage(), 'error');
            
            wp_send_json_error(array(
                'message' => 'Fehler bei der Verarbeitung der PayPal-Zahlung: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Webhook Handler für PayPal
     */
    public function handle_webhook() {
        // Nur an der PayPal Webhook-URL aktivieren
        if (!isset($_GET['yprint-paypal-webhook'])) {
            return;
        }
        
        // Webhook-Verarbeitung
        $payload = file_get_contents('php://input');
        $headers = $this->get_request_headers();
        
        // Debug-Log für Webhook-Anfragen
        if ($this->debug_mode) {
            $this->log('PayPal Webhook empfangen: ' . substr($payload, 0, 500) . '...');
        }
        
        // Webhook-Verifizierung
        if (!$this->verify_webhook_signature($payload, $headers)) {
            $this->log('PayPal Webhook: Signaturüberprüfung fehlgeschlagen', 'error');
            status_header(400);
            die('Invalid webhook signature');
        }
        
        // JSON Payload verarbeiten
        $event_json = json_decode($payload, true);
        
        if (!$event_json || !isset($event_json['event_type'])) {
            $this->log('PayPal Webhook: Ungültiger Payload', 'error');
            status_header(400);
            die('Invalid payload');
        }
        
        $event_type = $event_json['event_type'];
        $resource = $event_json['resource'] ?? array();
        
        if ($this->debug_mode) {
            $this->log('PayPal Webhook Event Typ: ' . $event_type);
        }
        
        // Event-Typ verarbeiten
        switch ($event_type) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                // Zahlung abgeschlossen
                $result = $this->process_payment_completed($resource);
                break;
                
            case 'PAYMENT.CAPTURE.DENIED':
            case 'PAYMENT.CAPTURE.REFUNDED':
            case 'PAYMENT.CAPTURE.REVERSED':
                // Zahlung abgelehnt/zurückerstattet/storniert
                $result = $this->process_payment_failed($resource, $event_type);
                break;
                
            case 'CHECKOUT.ORDER.APPROVED':
                // Bestellung genehmigt
                $result = $this->process_order_approved($resource);
                break;
                
            case 'CHECKOUT.ORDER.COMPLETED':
                // Bestellung abgeschlossen
                $result = $this->process_order_completed($resource);
                break;
                
            default:
                // Unbekanntes Event
                $this->log('PayPal Webhook: Unbekannter Event-Typ: ' . $event_type, 'warning');
                $result = array(
                    'success' => true,
                    'message' => 'Unbekannter Event-Typ: ' . $event_type
                );
                break;
        }
        
        status_header(200);
        if ($this->debug_mode && isset($result)) {
            $this->log('PayPal Webhook verarbeitet: ' . json_encode($result));
        }
        die('Webhook processed');
    }

    /**
     * Verarbeitet erfolgreiche Zahlungen von PayPal
     * 
     * @param array $resource PayPal Resource Daten
     * @return array Verarbeitungsergebnis
     */
    private function process_payment_completed($resource) {
        if ($this->debug_mode) {
            $this->log('Verarbeite erfolgreiche PayPal-Zahlung: ' . json_encode($resource));
        }
        
        if (!isset($resource['id']) || !isset($resource['supplementary_data']['related_ids']['order_id'])) {
            return array(
                'success' => false,
                'message' => 'Fehlende Zahlungsinformationen'
            );
        }
        
        $transaction_id = $resource['id'];
        $paypal_order_id = $resource['supplementary_data']['related_ids']['order_id'];
        
        // Order ID aus PayPal-Order ID finden
        $orders = wc_get_orders(array(
            'meta_key' => '_paypal_order_id',
            'meta_value' => $paypal_order_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            return array(
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            );
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
        $order->payment_complete($transaction_id);
        $order->add_order_note('Zahlung via PayPal Webhook bestätigt (Transaktion ID: ' . $transaction_id . ')');
        
        // Transaction ID in Meta speichern
        update_post_meta($order->get_id(), '_paypal_transaction_id', $transaction_id);
        
        // PayPal-spezifische Metadaten speichern
        $this->save_payment_data($order, $resource);
        
        $order->save();
        
        return array(
            'success' => true,
            'message' => 'Zahlung erfolgreich verarbeitet',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet fehlgeschlagene Zahlungen von PayPal
     * 
     * @param array $resource PayPal Resource Daten
     * @param string $event_type Event-Typ
     * @return array Verarbeitungsergebnis
     */
    private function process_payment_failed($resource, $event_type) {
        if ($this->debug_mode) {
            $this->log('Verarbeite fehlgeschlagene PayPal-Zahlung: ' . json_encode($resource) . ', Event: ' . $event_type);
        }
        
        if (!isset($resource['supplementary_data']['related_ids']['order_id'])) {
            return array(
                'success' => false,
                'message' => 'Fehlende Informationen zur Bestellung'
            );
        }
        
        $paypal_order_id = $resource['supplementary_data']['related_ids']['order_id'];
        
        // Order ID aus PayPal-Order ID finden
        $orders = wc_get_orders(array(
            'meta_key' => '_paypal_order_id',
            'meta_value' => $paypal_order_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            return array(
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            );
        }
        
        $order = $orders[0];
        
        // Bestellung als fehlgeschlagen oder zurückerstattet markieren
        if ($event_type === 'PAYMENT.CAPTURE.REFUNDED') {
            $order->update_status('refunded', 'PayPal-Zahlung wurde zurückerstattet.');
            
            // Wenn Rückerstattungsdetails vorhanden sind, WooCommerce-Rückerstattung erstellen
            if (isset($resource['amount']['value'])) {
                $refund_amount = floatval($resource['amount']['value']);
                
                // WooCommerce-Rückerstattung erstellen
                wc_create_refund(array(
                    'order_id' => $order->get_id(),
                    'amount' => $refund_amount,
                    'reason' => 'PayPal-Rückerstattung (Webhook)'
                ));
            }
        } else {
            $order->update_status('failed', 'PayPal-Zahlung fehlgeschlagen: ' . $event_type);
        }
        
        $order->save();
        
        return array(
            'success' => true,
            'message' => 'Fehlgeschlagene Zahlung verarbeitet',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet genehmigte PayPal-Bestellungen
     * 
     * @param array $resource PayPal Resource Daten
     * @return array Verarbeitungsergebnis
     */
    private function process_order_approved($resource) {
        if ($this->debug_mode) {
            $this->log('Verarbeite genehmigte PayPal-Bestellung: ' . json_encode($resource));
        }
        
        if (!isset($resource['id'])) {
            return array(
                'success' => false,
                'message' => 'PayPal Order ID fehlt'
            );
        }
        
        $paypal_order_id = $resource['id'];
        
        // Order ID aus PayPal-Order ID finden
        $orders = wc_get_orders(array(
            'meta_key' => '_paypal_order_id',
            'meta_value' => $paypal_order_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            return array(
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            );
        }
        
        $order = $orders[0];
        
        // Status auf "on-hold" setzen, da die Zahlung zwar genehmigt, aber noch nicht abgeschlossen ist
        if ($order->get_status() === 'pending') {
            $order->update_status('on-hold', 'PayPal-Bestellung genehmigt, warte auf Zahlungsabschluss');
            $order->save();
        }
        
        return array(
            'success' => true,
            'message' => 'Bestellung als genehmigt markiert',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet abgeschlossene PayPal-Bestellungen
     * 
     * @param array $resource PayPal Resource Daten
     * @return array Verarbeitungsergebnis
     */
    private function process_order_completed($resource) {
        if ($this->debug_mode) {
            $this->log('Verarbeite abgeschlossene PayPal-Bestellung: ' . json_encode($resource));
        }
        
        if (!isset($resource['id'])) {
            return array(
                'success' => false,
                'message' => 'PayPal Order ID fehlt'
            );
        }
        
        $paypal_order_id = $resource['id'];
        
        // Order ID aus PayPal-Order ID finden
        $orders = wc_get_orders(array(
            'meta_key' => '_paypal_order_id',
            'meta_value' => $paypal_order_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            return array(
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            );
        }
        
        $order = $orders[0];
        
        // Prüfen, ob Bestellung bereits bezahlt wurde
        if (!$order->is_paid()) {
            // Bestellung als bezahlt markieren, falls noch keine Zahlung eingegangen ist
            $order->payment_complete($paypal_order_id);
            $order->add_order_note('Zahlung via PayPal Webhook bestätigt (Order ID: ' . $paypal_order_id . ')');
            $order->save();
        }
        
        return array(
            'success' => true,
            'message' => 'Bestellung als abgeschlossen markiert',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Erstellt eine Bestellung aus den Session-Daten
     * 
     * @param string $paypal_order_id Die PayPal Order ID
     * @param string $transaction_id Die Transaktions-ID (optional)
     * @return int|bool Bestell-ID oder false bei Fehler
     */
    private function create_order_from_session($paypal_order_id, $transaction_id = null) {
        try {
            if (!class_exists('WC_Session') || !WC()->session) {
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
            
            // PayPal als Zahlungsmethode setzen
            $order->set_payment_method('paypal');
            $order->set_payment_method_title('PayPal');
            
            // PayPal Order ID und Transaction ID als Metadaten speichern
            update_post_meta($order->get_id(), '_paypal_order_id', $paypal_order_id);
            
            if (!empty($transaction_id)) {
                update_post_meta($order->get_id(), '_paypal_transaction_id', $transaction_id);
                update_post_meta($order->get_id(), '_transaction_id', $transaction_id);
            }
            
            // Bestellung berechnen und speichern
            $order->calculate_totals();
            $order->update_status('processing', 'Bestellung aus PayPal Order erstellt');
            $order->save();
            
            return $order->get_id();
        } catch (Exception $e) {
            $this->log('Fehler beim Erstellen der Bestellung: ' . $e->getMessage(), 'error');
            return false;
        }
    }
 
    /**
     * Überprüft die Webhook-Signatur für PayPal
     *
     * @param string $payload Der Webhook-Payload
     * @param array $headers Die HTTP-Header
     * @return bool True, wenn die Signatur gültig ist, sonst false
     */
    private function verify_webhook_signature($payload, $headers) {
        // Wenn Webhook-Verifizierung deaktiviert ist (z.B. für Testzwecke)
        if (!$this->is_feature_enabled('paypal_webhook_verification')) {
            $this->log('PayPal Webhook-Verifizierung übersprungen (Feature deaktiviert)', 'warning');
            return true;
        }
        
        if (empty($this->webhook_id)) {
            $this->log('PayPal Webhook: Fehlende Webhook-ID', 'error');
            return false;
        }
        
        // Erforderliche Header für die Signaturvalidierung
        $transmission_id = isset($headers['paypal-transmission-id']) ? $headers['paypal-transmission-id'] : '';
        $transmission_time = isset($headers['paypal-transmission-time']) ? $headers['paypal-transmission-time'] : '';
        $cert_url = isset($headers['paypal-cert-url']) ? $headers['paypal-cert-url'] : '';
        $auth_algo = isset($headers['paypal-auth-algo']) ? $headers['paypal-auth-algo'] : '';
        $transmission_sig = isset($headers['paypal-transmission-sig']) ? $headers['paypal-transmission-sig'] : '';
        
        if (empty($transmission_id) || empty($transmission_time) || empty($cert_url) || 
            empty($auth_algo) || empty($transmission_sig)) {
            $this->log('PayPal Webhook: Fehlende Header für die Signaturvalidierung', 'error');
            return false;
        }
        
        // Token abrufen
        $token = $this->get_access_token();
        
        if (empty($token)) {
            $this->log('PayPal Webhook: OAuth-Token konnte nicht abgerufen werden', 'error');
            return false;
        }
        
        // Daten für die Signaturprüfung
        $verify_data = array(
            'webhook_id' => $this->webhook_id,
            'transmission_id' => $transmission_id,
            'transmission_time' => $transmission_time,
            'cert_url' => $cert_url,
            'auth_algo' => $auth_algo,
            'transmission_sig' => $transmission_sig,
            'webhook_event' => json_decode($payload, true)
        );
        
        // API-Header mit Token
        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        );
        
        // Signatur validieren
        $response = $this->request_paypal_api('v1/notifications/verify-webhook-signature', $verify_data, 'POST', $headers);
        
        if (isset($response['error'])) {
            $this->log('PayPal Webhook: Fehler bei der Signaturüberprüfung: ' . ($response['error_description'] ?? 'Unbekannter Fehler'), 'error');
            return false;
        }
        
        if (!isset($response['verification_status'])) {
            $this->log('PayPal Webhook: Ungültige Antwort bei der Signaturüberprüfung', 'error');
            return false;
        }
        
        // Signatur prüfen
        return $response['verification_status'] === 'SUCCESS';
    }
 
    /**
     * Speichert Zahlungsdaten für eine Bestellung
     * 
     * @param WC_Order $order Bestellung
     * @param array $resource PayPal-Resource-Daten
     */
    private function save_payment_data($order, $resource) {
        // Grundlegende Zahlungsinformationen
        if (isset($resource['id'])) {
            update_post_meta($order->get_id(), '_paypal_transaction_id', $resource['id']);
        }
        
        // Zahlungsstatus
        if (isset($resource['status'])) {
            update_post_meta($order->get_id(), '_paypal_payment_status', $resource['status']);
        }
        
        // Zahlungsbetrag und Währung
        if (isset($resource['amount']['value'])) {
            update_post_meta($order->get_id(), '_paypal_payment_amount', $resource['amount']['value']);
        }
        
        if (isset($resource['amount']['currency_code'])) {
            update_post_meta($order->get_id(), '_paypal_payment_currency', $resource['amount']['currency_code']);
        }
        
        // Zahlungszeitpunkt
        if (isset($resource['create_time'])) {
            update_post_meta($order->get_id(), '_paypal_payment_date', $resource['create_time']);
        }
        
        // Zahlungsdetails
        if (isset($resource['supplementary_data']['related_ids'])) {
            $related_ids = $resource['supplementary_data']['related_ids'];
            
            if (isset($related_ids['order_id'])) {
                update_post_meta($order->get_id(), '_paypal_order_id', $related_ids['order_id']);
            }
            
            if (isset($related_ids['authorization_id'])) {
                update_post_meta($order->get_id(), '_paypal_authorization_id', $related_ids['authorization_id']);
            }
        }
    }
 
    /**
     * Erstellt die PayPal Order-Daten
     * 
     * @param string $currency Währung
     * @param float $total Gesamtbetrag
     * @param float $subtotal Zwischensumme
     * @param float $shipping Versandkosten
     * @param float $tax Steuern
     * @param float $discount Rabatt
     * @param string $reference_id Referenz-ID
     * @param array $customer_info Kundeninformationen
     * @param int $order_id Bestellungs-ID (optional)
     * @return array Order-Daten für PayPal API
     */
    private function create_order_data($currency, $total, $subtotal, $shipping, $tax, $discount, $reference_id, $customer_info, $order_id = 0) {
        // Produkt-Items für PayPal
        $items = array();
        
        // Artikel aus Warenkorb hinzufügen
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $quantity = $cart_item['quantity'];
            $price = round($cart_item['line_total'] / $quantity, 2);
            
            $items[] = array(
                'name' => $product->get_name(),
                'unit_amount' => array(
                    'currency_code' => $currency,
                    'value' => number_format($price, 2, '.', '')
                ),
                'quantity' => $quantity,
                'description' => $this->get_product_description($product)
            );
        }
        
        // Order-Metadaten
        $order_metadata = array(
            'site_url' => get_site_url(),
            'reference_id' => $reference_id
        );
        
        // Bestellungs-ID hinzufügen, falls vorhanden
        if ($order_id > 0) {
            $order_metadata['order_id'] = $order_id;
        }
        
        // Beschreibung erstellen
        $description = $order_id > 0 
                     ? 'Bestellung #' . $order_id . ' bei ' . get_bloginfo('name')
                     : 'Bestellung bei ' . get_bloginfo('name');
        
        // Order-Daten
        $order_data = array(
            'intent' => 'CAPTURE',
            'purchase_units' => array(
                array(
                    'reference_id' => $reference_id,
                    'description' => $description,
                    'amount' => array(
                        'currency_code' => $currency,
                        'value' => number_format($total, 2, '.', ''),
                        'breakdown' => array(
                            'item_total' => array(
                                'currency_code' => $currency,
                                'value' => number_format($subtotal, 2, '.', '')
                            ),
                            'shipping' => array(
                                'currency_code' => $currency,
                                'value' => number_format($shipping, 2, '.', '')
                            ),
                            'tax_total' => array(
                                'currency_code' => $currency,
                                'value' => number_format($tax, 2, '.', '')
                            ),
                            'discount' => array(
                                'currency_code' => $currency,
                                'value' => number_format($discount, 2, '.', '')
                            )
                        )
                    ),
                    'items' => $items,
                    'custom_id' => 'WC-' . ($order_id > 0 ? $order_id : 'cart-' . time()),
                    'invoice_id' => 'INV-YP-' . time() . '-' . rand(1000, 9999)
                )
            ),
            'application_context' => array(
                'brand_name' => get_bloginfo('name'),
                'landing_page' => 'BILLING',
                'shipping_preference' => 'SET_PROVIDED_ADDRESS',
                'user_action' => 'PAY_NOW',
                'return_url' => home_url('/paypal-return'),
                'cancel_url' => home_url('/checkout')
            )
        );
        
        // Lieferadresse hinzufügen, falls vorhanden
        if (!empty($customer_info['address']['line1'])) {
            $order_data['purchase_units'][0]['shipping'] = array(
                'name' => array(
                    'full_name' => $customer_info['name']
                ),
                'address' => array(
                    'address_line_1' => $customer_info['address']['line1'],
                    'address_line_2' => $customer_info['address']['line2'] ?? '',
                    'admin_area_2' => $customer_info['address']['city'] ?? '',
                    'postal_code' => $customer_info['address']['postal_code'] ?? '',
                    'country_code' => $customer_info['address']['country'] ?? 'DE'
                )
            );
        }
        
        return $order_data;
    }
 
    /**
     * Erstellt eine Produktbeschreibung für PayPal
     * 
     * @param WC_Product $product Produkt
     * @return string Beschreibung (maximal 127 Zeichen)
     */
    private function get_product_description($product) {
        $description = '';
        
        if ($product) {
            // Kurzbeschreibung oder normale Beschreibung verwenden
            $description = $product->get_short_description();
            
            if (empty($description)) {
                $description = $product->get_description();
            }
            
            // HTML-Tags entfernen
            $description = strip_tags($description);
            
            // Auf 127 Zeichen begrenzen (PayPal-Anforderung)
            if (strlen($description) > 127) {
                $description = substr($description, 0, 124) . '...';
            }
        }
        
        return $description;
    }
 
    /**
     * Bereitet Kundeninformationen für PayPal vor
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
        
        // Adressinformationen für PayPal vorbereiten
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
     * Führt eine Anfrage an die PayPal-API durch
     * 
     * @param string $endpoint API-Endpunkt
     * @param array $data Anfrage-Daten
     * @param string $method HTTP-Methode (GET, POST)
     * @param array $headers Zusätzliche Header
     * @return array Antwort von PayPal
     */
    private function request_paypal_api($endpoint, $data = array(), $method = 'GET', $headers = array()) {
        // Basis-URL
        $url = $this->api_url . $endpoint;
        
        // Standard-Header
        $default_headers = array(
            'Content-Type' => 'application/json'
        );
        
        // Header zusammenführen
        $headers = array_merge($default_headers, $headers);
        
        // Anfrage-Parameter
        $args = array(
            'method' => $method,
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'sslverify' => true,
            'headers' => $headers
        );
        
        // Body hinzufügen, falls Daten vorhanden
        if (!empty($data) && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }
        
        // Debug-Logging
        if ($this->debug_mode) {
            $log_data = array(
                'url' => $url,
                'method' => $method,
                'headers' => $this->sanitize_log_data($headers),
                'body' => $method !== 'GET' ? $this->sanitize_log_data($data) : null
            );
            $this->log('PayPal API-Anfrage: ' . json_encode($log_data));
        }
        
        // Anfrage durchführen
        $response = wp_remote_request($url, $args);
        
        // Fehler prüfen
        if (is_wp_error($response)) {
            $this->log('PayPal API-Fehler: ' . $response->get_error_message(), 'error');
            return array(
                'error' => 'connection_error',
                'error_description' => $response->get_error_message()
            );
        }
        
        // Statuscode und Body extrahieren
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Antwort parsen
        $parsed_response = json_decode($body, true);
        
        // Debug-Logging
        if ($this->debug_mode) {
            $this->log('PayPal API-Antwort: Status ' . $status_code . ', Antwort: ' . substr($body, 0, 500) . (strlen($body) > 500 ? '...' : ''));
        }
        
        // Fehler prüfen
        if ($status_code >= 400) {
            $error_message = isset($parsed_response['error_description']) ? $parsed_response['error_description'] : 'Unbekannter Fehler';
            $this->log('PayPal API-Fehler: ' . $error_message . ' (Status: ' . $status_code . ')', 'error');
            
            return array(
                'error' => isset($parsed_response['error']) ? $parsed_response['error'] : 'api_error',
                'error_description' => $error_message,
                'status_code' => $status_code
            );
        }
        
        // Erfolgreiche Antwort zurückgeben
        return $parsed_response ?? array();
    }
 
    /**
     * Generiert ein OAuth-Token für PayPal
     *
     * @return string|null Das generierte Token oder null bei Fehler
     */
    private function get_access_token() {
        // Token aus Cache holen, falls vorhanden und gültig
        $cached_token = get_transient('yprint_paypal_oauth_token');
        if ($cached_token) {
            return $cached_token;
        }
        
        // Basic Auth Header erstellen
        $auth = base64_encode($this->client_id . ':' . $this->client_secret);
        
        // Token-URL
        $url = $this->api_url . 'v1/oauth2/token';
        
        // HTTP-Anfrage-Argumente
        $args = array(
            'method' => 'POST',
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'sslverify' => true,
            'headers' => array(
                'Accept' => 'application/json',
                'Accept-Language' => 'en_US',
                'Authorization' => 'Basic ' . $auth
            ),
            'body' => 'grant_type=client_credentials'
        );
        
        // Anfrage durchführen
        $response = wp_remote_request($url, $args);
        
        // Fehler prüfen
        if (is_wp_error($response)) {
            $this->log('PayPal OAuth-Fehler: ' . $response->get_error_message(), 'error');
            return null;
        }
        
        // Antwort parsen
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200 || !isset($body['access_token'])) {
            $this->log('PayPal OAuth-Fehler: Ungültige Antwort (Status: ' . $status_code . ')', 'error');
            return null;
        }
        
        // Token im Cache speichern (etwas kürzer als die Ablaufzeit)
        $expires_in = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;
        set_transient('yprint_paypal_oauth_token', $body['access_token'], $expires_in - 60);
        
        return $body['access_token'];
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
     * Bereinigt sensible Daten für Logging
     *
     * @param array $data Die zu bereinigenden Daten
     * @return array Bereinigte Daten
     */
    private function sanitize_log_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $sensitive_fields = array(
            'password', 'pwd', 'pass', 'secret', 'key', 'client_secret', 'access_token', 
            'auth', 'credentials', 'token', 'authorization', 'card', 'cc', 'cvv', 
            'cvc', 'number', 'expiry', 'exp', 'expiration'
        );
        
        $result = array();
        foreach ($data as $key => $value) {
            // Prüfen auf sensible Felder
            $is_sensitive = false;
            foreach ($sensitive_fields as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $is_sensitive = true;
                    break;
                }
            }
            
            if ($is_sensitive) {
                $result[$key] = '***MASKED***';
            } else if (is_array($value)) {
                $result[$key] = $this->sanitize_log_data($value);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
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
            'paypal_smart_buttons' => true,
            'paypal_webhook_verification' => true,
            'paypal_refunds' => true,
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
    if ($this->debug_mode || $level === 'error') {
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
        
        $level_name = isset($log_levels[$level]) ? strtoupper($level) : 'INFO';
        error_log('[YPrint PayPal] [' . $level_name . '] ' . $message);
    }
}
}

/**
* Gibt die Hauptinstanz von YPrint_PayPal zurück
* 
* @return YPrint_PayPal
*/
function YPrint_PayPal() {
return YPrint_PayPal::instance();
}

// Globale für Abwärtskompatibilität
$GLOBALS['yprint_paypal'] = YPrint_PayPal();