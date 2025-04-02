<?php
/**
 * PayPal-Webhook-Handler für YPrint Payment
 *
 * Diese Klasse verarbeitet eingehende Webhook-Ereignisse von PayPal,
 * validiert sie und aktualisiert Bestellungen entsprechend.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PayPal-Webhook-Handler Klasse
 */
class YPrint_PayPal_Webhook {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_PayPal_Webhook
     */
    protected static $_instance = null;

    /**
     * API-Handler
     *
     * @var YPrint_API
     */
    private $api;

    /**
     * Feature Flags Manager
     *
     * @var YPrint_Feature_Flags
     */
    private $feature_flags;

    /**
     * Debug-Modus-Flag
     *
     * @var bool
     */
    private $debug_mode;

    /**
     * PayPal API Credentials
     * 
     * @var array
     */
    private $credentials = array();

    /**
     * Hauptinstanz der YPrint_PayPal_Webhook-Klasse
     *
     * @return YPrint_PayPal_Webhook - Hauptinstanz
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Konstruktor.
     */
    public function __construct() {
        // API-Handler laden
        $this->api = class_exists('YPrint_API') ? YPrint_API::instance() : null;
        
        // Feature Flags laden
        $this->feature_flags = class_exists('YPrint_Feature_Flags') ? YPrint_Feature_Flags::instance() : null;
        
        // PayPal und PayPal-Handler Klasse initialisieren, wenn verfügbar
        $this->paypal = class_exists('YPrint_PayPal') ? YPrint_PayPal::instance() : null;
        
        // Debug-Modus setzen
        $this->debug_mode = $this->is_feature_enabled('debug_mode');
        
        // Credentials laden
        $this->load_credentials();
        
        // Hooks initialisieren
        $this->init_hooks();
    }

    /**
     * Hooks initialisieren
     */
    private function init_hooks() {
        // Der Hauptwebhook wird über den allgemeinen Webhook-Handler geroutet
        // Hier könnten zusätzliche spezifische Hooks definiert werden
        add_action('yprint_process_paypal_webhook', array($this, 'process_webhook'), 10, 2);
    }

    /**
     * Credentials laden
     */
    private function load_credentials() {
        $test_mode = get_option('yprint_paypal_test_mode', 'no') === 'yes';
        
        $this->credentials = array(
            'client_id' => $test_mode 
                ? get_option('yprint_paypal_test_client_id', 'INSERT_API_KEY_HERE')
                : get_option('yprint_paypal_client_id', 'INSERT_API_KEY_HERE'),
            'secret_key' => $test_mode
                ? get_option('yprint_paypal_test_secret_key', 'INSERT_API_KEY_HERE')
                : get_option('yprint_paypal_secret_key', 'INSERT_API_KEY_HERE'),
            'webhook_id' => $test_mode
                ? get_option('yprint_paypal_test_webhook_id', 'INSERT_API_KEY_HERE')
                : get_option('yprint_paypal_webhook_id', 'INSERT_API_KEY_HERE'),
            'test_mode' => $test_mode
        );
    }

    /**
     * Verarbeitet den PayPal Webhook
     * 
     * @param string $payload Der Webhook-Payload
     * @param array $headers Die HTTP-Header
     * @return array Ergebnis der Verarbeitung
     */
    public function process_webhook($payload, $headers) {
        try {
            // Payload dekodieren
            $event_json = json_decode($payload, true);
            if (empty($event_json) || json_last_error() !== JSON_ERROR_NONE) {
                $this->log('Ungültiger PayPal Webhook Payload: ' . json_last_error_msg(), 'error');
                return array(
                    'success' => false,
                    'message' => 'Ungültiger Payload: ' . json_last_error_msg()
                );
            }
            
            // Debug-Logging
            if ($this->debug_mode) {
                $this->log('PayPal Webhook empfangen: ' . print_r($event_json, true));
            }
            
            // Event-Typ extrahieren
            if (!isset($event_json['event_type'])) {
                $this->log('PayPal Webhook: Kein Event-Typ gefunden', 'error');
                return array(
                    'success' => false,
                    'message' => 'Kein Event-Typ im Payload gefunden'
                );
            }
            
            $event_type = $event_json['event_type'];
            
            // Webhook verifizieren
            if (!$this->verify_webhook_signature($payload, $headers)) {
                $this->log('PayPal Webhook: Signaturverifizierung fehlgeschlagen', 'error');
                return array(
                    'success' => false,
                    'message' => 'Signaturverifizierung fehlgeschlagen'
                );
            }
            
            // Event-Typ verarbeiten
            switch ($event_type) {
                case 'PAYMENT.CAPTURE.COMPLETED':
                    return $this->process_payment_completed($event_json);
                    
                case 'PAYMENT.CAPTURE.DENIED':
                case 'PAYMENT.CAPTURE.REFUNDED':
                case 'PAYMENT.CAPTURE.REVERSED':
                    return $this->process_payment_failed($event_json, $event_type);
                    
                case 'CHECKOUT.ORDER.APPROVED':
                    return $this->process_order_approved($event_json);
                    
                case 'CHECKOUT.ORDER.COMPLETED':
                    return $this->process_order_completed($event_json);
                    
                case 'CHECKOUT.ORDER.PROCESSED':
                    return $this->process_order_processed($event_json);

                default:
                    $this->log('PayPal Webhook: Unbekannter Event-Typ: ' . $event_type, 'notice');
                    return array(
                        'success' => true,
                        'message' => 'Unbehandelter Event-Typ: ' . $event_type
                    );
            }
        } catch (Exception $e) {
            $this->log('PayPal Webhook Fehler: ' . $e->getMessage(), 'error');
            return array(
                'success' => false,
                'message' => 'Fehler bei der Webhook-Verarbeitung: ' . $e->getMessage()
            );
        }
    }

    /**
     * Verifiziert die PayPal Webhook-Signatur
     * 
     * @param string $payload Der Webhook-Payload
     * @param array $headers Die HTTP-Header
     * @return bool True wenn die Signatur gültig ist, sonst false
     */
    private function verify_webhook_signature($payload, $headers) {
        // Wenn im Debug-Modus und explizit konfiguriert, Signaturprüfung überspringen
        if ($this->debug_mode && $this->is_feature_enabled('skip_webhook_signature_verification')) {
            $this->log('PayPal Webhook: Signaturprüfung übersprungen (Debug-Modus)');
            return true;
        }
        
        // Webhook-ID prüfen
        if (empty($this->credentials['webhook_id'])) {
            $this->log('PayPal Webhook: Keine Webhook-ID konfiguriert', 'error');
            return false;
        }
        
        // Benötigte Header prüfen
        $required_headers = array(
            'paypal-transmission-id',
            'paypal-transmission-time',
            'paypal-transmission-sig',
            'paypal-cert-url',
            'paypal-auth-algo'
        );
        
        $normalized_headers = array();
        foreach ($headers as $key => $value) {
            $normalized_headers[strtolower($key)] = $value;
        }
        
        foreach ($required_headers as $header) {
            if (empty($normalized_headers[$header])) {
                $this->log("PayPal Webhook: Fehlender Header: $header", 'error');
                return false;
            }
        }
        
        // API verwenden, falls verfügbar
        if ($this->api && method_exists($this->api, 'verify_webhook_signature')) {
            return $this->api->verify_webhook_signature('paypal', $payload, $normalized_headers);
        }
        
        // Falls API nicht verfügbar, eigene Implementierung
        try {
            // PayPal OAuth Token abrufen
            $token = $this->get_auth_token();
            if (empty($token)) {
                $this->log('PayPal Webhook: Konnte kein Auth-Token abrufen', 'error');
                return false;
            }
            
            // Daten für die Signaturprüfung
            $verify_data = array(
                'webhook_id' => $this->credentials['webhook_id'],
                'transmission_id' => $normalized_headers['paypal-transmission-id'],
                'transmission_time' => $normalized_headers['paypal-transmission-time'],
                'cert_url' => $normalized_headers['paypal-cert-url'],
                'auth_algo' => $normalized_headers['paypal-auth-algo'],
                'transmission_sig' => $normalized_headers['paypal-transmission-sig'],
                'webhook_event' => json_decode($payload, true)
            );
            
            // API-URL bestimmen
            $api_url = $this->credentials['test_mode'] 
                ? 'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature'
                : 'https://api-m.paypal.com/v1/notifications/verify-webhook-signature';
            
            // Anfrage an PayPal senden
            $response = wp_remote_post($api_url, array(
                'method' => 'POST',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.1',
                'blocking' => true,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ),
                'body' => json_encode($verify_data),
                'cookies' => array(),
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                $this->log('PayPal Webhook: Fehler bei der Signaturprüfung: ' . $response->get_error_message(), 'error');
                return false;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (empty($body) || !isset($body['verification_status'])) {
                $this->log('PayPal Webhook: Ungültige Antwort bei der Signaturprüfung', 'error');
                return false;
            }
            
            $is_valid = $body['verification_status'] === 'SUCCESS';
            
            if (!$is_valid) {
                $this->log('PayPal Webhook: Signaturprüfung fehlgeschlagen: ' . $body['verification_status'], 'error');
            }
            
            return $is_valid;
            
        } catch (Exception $e) {
            $this->log('PayPal Webhook: Fehler bei der Signaturprüfung: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Authentifizierungstoken von PayPal abrufen
     * 
     * @return string|bool Das Token oder false bei Fehler
     */
    private function get_auth_token() {
        // Cached Token prüfen
        $token = get_transient('yprint_paypal_auth_token');
        if (!empty($token)) {
            return $token;
        }
        
        try {
            // Credentials prüfen
            if (empty($this->credentials['client_id']) || empty($this->credentials['secret_key'])) {
                $this->log('PayPal Auth: Fehlende API-Credentials', 'error');
                return false;
            }
            
            // API-URL bestimmen
            $api_url = $this->credentials['test_mode'] 
                ? 'https://api-m.sandbox.paypal.com/v1/oauth2/token'
                : 'https://api-m.paypal.com/v1/oauth2/token';
            
            // Basic Auth für PayPal
            $auth = base64_encode($this->credentials['client_id'] . ':' . $this->credentials['secret_key']);
            
            // Anfrage an PayPal senden
            $response = wp_remote_post($api_url, array(
                'method' => 'POST',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.1',
                'blocking' => true,
                'headers' => array(
                    'Accept' => 'application/json',
                    'Accept-Language' => 'en_US',
                    'Authorization' => 'Basic ' . $auth
                ),
                'body' => 'grant_type=client_credentials',
                'cookies' => array(),
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                $this->log('PayPal Auth: Fehler beim Token-Abruf: ' . $response->get_error_message(), 'error');
                return false;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (empty($body) || !isset($body['access_token'])) {
                $this->log('PayPal Auth: Ungültige Antwort beim Token-Abruf', 'error');
                return false;
            }
            
            $token = $body['access_token'];
            $expires_in = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;
            
            // Token im Cache speichern (etwas kürzer als die Ablaufzeit)
            set_transient('yprint_paypal_auth_token', $token, $expires_in - 60);
            
            return $token;
            
        } catch (Exception $e) {
            $this->log('PayPal Auth: Fehler beim Token-Abruf: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Verarbeitet das Ereignis PAYMENT.CAPTURE.COMPLETED
     * 
     * @param array $event_json Die Ereignisdaten
     * @return array Ergebnis der Verarbeitung
     */
    private function process_payment_completed($event_json) {
        if (!isset($event_json['resource'])) {
            $this->log('PayPal Webhook: Fehlende Ressource in PAYMENT.CAPTURE.COMPLETED', 'error');
            return array(
                'success' => false,
                'message' => 'Fehlende Ressource in Ereignisdaten'
            );
        }
        
        $resource = $event_json['resource'];
        
        // Transaktions-ID und Order-ID extrahieren
        $transaction_id = isset($resource['id']) ? $resource['id'] : '';
        $paypal_order_id = isset($resource['supplementary_data']['related_ids']['order_id']) 
            ? $resource['supplementary_data']['related_ids']['order_id'] 
            : '';
            
        if (empty($transaction_id)) {
            $this->log('PayPal Webhook: Keine Transaktions-ID in PAYMENT.CAPTURE.COMPLETED', 'error');
            return array(
                'success' => false,
                'message' => 'Keine Transaktions-ID gefunden'
            );
        }
        
        // Bestellung finden
        $order = $this->find_order_by_paypal_data($transaction_id, $paypal_order_id);
        
        if (!$order) {
            $this->log('PayPal Webhook: Keine Bestellung für PAYMENT.CAPTURE.COMPLETED gefunden', 'error');
            return array(
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            );
        }
        
        // Prüfen, ob Bestellung bereits bezahlt wurde
        if ($order->is_paid()) {
            $this->log('PayPal Webhook: Bestellung #' . $order->get_id() . ' wurde bereits bezahlt', 'notice');
            return array(
                'success' => true,
                'message' => 'Bestellung wurde bereits bezahlt',
                'order_id' => $order->get_id()
            );
        }
        
        // Capture-Status prüfen
        $payment_status = strtolower(isset($resource['status']) ? $resource['status'] : '');
        
        if ($payment_status !== 'completed') {
            $this->log('PayPal Webhook: Unerwarteter Status in PAYMENT.CAPTURE.COMPLETED: ' . $payment_status, 'error');
            return array(
                'success' => false,
                'message' => 'Unerwarteter Zahlungsstatus: ' . $payment_status
            );
        }
        
        // Zahlungsbetrag prüfen, wenn vorhanden
        if (isset($resource['amount']['value'])) {
            $payment_amount = floatval($resource['amount']['value']);
            $order_total = floatval($order->get_total());
            
            // Toleranz für Rundungsfehler (0.01 Einheiten oder 1%)
            $tolerance = max(0.01, $order_total * 0.01);
            
            if (abs($payment_amount - $order_total) > $tolerance) {
                $this->log('PayPal Webhook: Zahlungsbetrag (' . $payment_amount . ') weicht von Bestellsumme (' . $order_total . ') ab', 'warning');
                // Wir setzen trotzdem fort, da PayPal die Zahlung akzeptiert hat
            }
        }
        
        // Bestellung als bezahlt markieren
        $order->payment_complete($transaction_id);
        $order->add_order_note('Zahlung via PayPal Webhook bestätigt (Transaktion ID: ' . $transaction_id . ')');
        
        // PayPal Metadaten speichern
        update_post_meta($order->get_id(), '_paypal_transaction_id', $transaction_id);
        if (!empty($paypal_order_id)) {
            update_post_meta($order->get_id(), '_paypal_order_id', $paypal_order_id);
        }
        
        // Zahler-Information speichern, wenn vorhanden
        if (isset($resource['payer'])) {
            $payer_info = $resource['payer'];
            update_post_meta($order->get_id(), '_paypal_payer_info', $payer_info);
            
            // E-Mail-Adresse aktualisieren, wenn vorhanden
            if (isset($payer_info['email_address']) && !empty($payer_info['email_address'])) {
                $order->set_billing_email($payer_info['email_address']);
            }
        }
        
        $order->save();
        
        $this->log('PayPal Webhook: Bestellung #' . $order->get_id() . ' als bezahlt markiert');
        
        // Möglicherweise weitere Aktionen ausführen
        do_action('yprint_paypal_payment_completed', $order, $resource);
        
        return array(
            'success' => true,
            'message' => 'Zahlung erfolgreich verarbeitet',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet ein fehlgeschlagenes Zahlungsereignis
     * 
     * @param array $event_json Die Ereignisdaten
     * @param string $event_type Der Ereignistyp
     * @return array Ergebnis der Verarbeitung
     */
    private function process_payment_failed($event_json, $event_type) {
        if (!isset($event_json['resource'])) {
            $this->log('PayPal Webhook: Fehlende Ressource in ' . $event_type, 'error');
            return array(
                'success' => false,
                'message' => 'Fehlende Ressource in Ereignisdaten'
            );
        }
        
        $resource = $event_json['resource'];
        
        // Transaktions-ID und Order-ID extrahieren
        $transaction_id = isset($resource['id']) ? $resource['id'] : '';
        $paypal_order_id = isset($resource['supplementary_data']['related_ids']['order_id']) 
            ? $resource['supplementary_data']['related_ids']['order_id'] 
            : '';
            
        if (empty($transaction_id) && empty($paypal_order_id)) {
            $this->log('PayPal Webhook: Keine Identifikation in ' . $event_type, 'error');
            return array(
                'success' => false,
                'message' => 'Keine Transaktions-ID oder Order-ID gefunden'
            );
        }
        
        // Bestellung finden
        $order = $this->find_order_by_paypal_data($transaction_id, $paypal_order_id);
        
        if (!$order) {
            $this->log('PayPal Webhook: Keine Bestellung für ' . $event_type . ' gefunden', 'error');
            return array(
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            );
        }
        
        // Je nach Ereignistyp den Status setzen
        switch($event_type) {
            case 'PAYMENT.CAPTURE.REFUNDED':
                // Prüfen, ob Vollrückerstattung oder Teilrückerstattung
                $refund_amount = isset($resource['amount']['value']) ? floatval($resource['amount']['value']) : 0;
                $order_total = floatval($order->get_total());
                
                // Toleranz für Rundungsfehler (0.01 Einheiten oder 1%)
                $tolerance = max(0.01, $order_total * 0.01);
                
                if (abs($refund_amount - $order_total) <= $tolerance) {
                    // Vollrückerstattung
                    $order->update_status('refunded', 'PayPal-Zahlung wurde vollständig zurückerstattet.');
                    $this->log('PayPal Webhook: Bestellung #' . $order->get_id() . ' als zurückerstattet markiert');
                } else {
                    // Teilrückerstattung
                    $refund_note = sprintf(
                        'PayPal-Zahlung teilweise zurückerstattet: %s %s von %s %s.',
                        number_format($refund_amount, 2),
                        $resource['amount']['currency_code'],
                        number_format($order_total, 2),
                        $order->get_currency()
                    );
                    $order->add_order_note($refund_note);
                    
                    // WooCommerce-Rückerstattung erstellen
                    try {
                        wc_create_refund(array(
                            'order_id' => $order->get_id(),
                            'amount' => $refund_amount,
                            'reason' => 'PayPal-Rückerstattung (Webhook)'
                        ));
                    } catch (Exception $e) {
                        $this->log('PayPal Webhook: Fehler bei der Erstellung der WooCommerce-Rückerstattung: ' . $e->getMessage(), 'error');
                    }
                    
                    $this->log('PayPal Webhook: Teilrückerstattung für Bestellung #' . $order->get_id() . ' verarbeitet');
                }
                break;
                
            case 'PAYMENT.CAPTURE.DENIED':
            case 'PAYMENT.CAPTURE.REVERSED':
            default:
                // Bestellung als fehlgeschlagen markieren
                $order->update_status('failed', 'PayPal-Zahlung fehlgeschlagen: ' . $event_type);
                $this->log('PayPal Webhook: Bestellung #' . $order->get_id() . ' als fehlgeschlagen markiert (' . $event_type . ')');
                break;
        }
        
        $order->save();
        
        // Möglicherweise weitere Aktionen ausführen
        do_action('yprint_paypal_payment_failed', $order, $resource, $event_type);
        
        return array(
            'success' => true,
            'message' => 'Fehlgeschlagene/zurückerstattete Zahlung verarbeitet',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet das Ereignis CHECKOUT.ORDER.APPROVED
     * 
     * @param array $event_json Die Ereignisdaten
     * @return array Ergebnis der Verarbeitung
     */
    private function process_order_approved($event_json) {
        if (!isset($event_json['resource'])) {
            $this->log('PayPal Webhook: Fehlende Ressource in CHECKOUT.ORDER.APPROVED', 'error');
            return array(
                'success' => false,
                'message' => 'Fehlende Ressource in Ereignisdaten'
            );
        }
        
        $resource = $event_json['resource'];
        
        // PayPal Order-ID extrahieren
        $paypal_order_id = isset($resource['id']) ? $resource['id'] : '';
            
        if (empty($paypal_order_id)) {
            $this->log('PayPal Webhook: Keine Order-ID in CHECKOUT.ORDER.APPROVED', 'error');
            return array(
                'success' => false,
                'message' => 'Keine PayPal Order-ID gefunden'
            );
        }
        
        // Bestellung finden
        $order = $this->find_order_by_paypal_data('', $paypal_order_id);
        
        if (!$order) {
            $this->log('PayPal Webhook: Keine Bestellung für CHECKOUT.ORDER.APPROVED gefunden', 'error');
            return array(
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            );
        }
        
        // Bestellung als "on-hold" markieren, bis die Zahlung abgeschlossen ist
        if ($order->get_status() === 'pending') {
            $order->update_status('on-hold', 'PayPal-Bestellung genehmigt, warte auf Zahlungsabschluss');
            $order->save();
            $this->log('PayPal Webhook: Bestellung #' . $order->get_id() . ' als on-hold markiert (CHECKOUT.ORDER.APPROVED)');
        }
        
        // Möglicherweise weitere Aktionen ausführen
        do_action('yprint_paypal_order_approved', $order, $resource);
        
        return array(
            'success' => true,
            'message' => 'Bestellung als genehmigt markiert',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet das Ereignis CHECKOUT.ORDER.COMPLETED
     * 
     * @param array $event_json Die Ereignisdaten
     * @return array Ergebnis der Verarbeitung
     */
    private function process_order_completed($event_json) {
        if (!isset($event_json['resource'])) {
            $this->log('PayPal Webhook: Fehlende Ressource in CHECKOUT.ORDER.COMPLETED', 'error');
            return array(
                'success' => false,
                'message' => 'Fehlende Ressource in Ereignisdaten'
            );
        }
        
        $resource = $event_json['resource'];
        
        // PayPal Order-ID extrahieren
        $paypal_order_id = isset($resource['id']) ? $resource['id'] : '';
            
        if (empty($paypal_order_id)) {
            $this->log('PayPal Webhook: Keine Order-ID in CHECKOUT.ORDER.COMPLETED', 'error');
            return array(
                'success' => false,
                'message' => 'Keine PayPal Order-ID gefunden'
            );
        }
        
        // Bestellung finden
        $order = $this->find_order_by_paypal_data('', $paypal_order_id);
        
        if (!$order) {
            $this->log('PayPal Webhook: Keine Bestellung für CHECKOUT.ORDER.COMPLETED gefunden', 'error');
            return array(
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            );
        }
        
        // Überprüfen, ob die Bestellung bereits bezahlt ist
        if (!$order->is_paid()) {
            // Manchmal kommt das CHECKOUT.ORDER.COMPLETED Event, aber kein PAYMENT.CAPTURE.COMPLETED
            // In diesem Fall können wir die Bestellung direkt als bezahlt markieren
            
            // TransaktionsID extrahieren, falls verfügbar
            $transaction_id = '';
            if (isset($resource['purchase_units']) && is_array($resource['purchase_units'])) {
                foreach ($resource['purchase_units'] as $unit) {
                    if (isset($unit['payments']['captures']) && is_array($unit['payments']['captures'])) {
                        foreach ($unit['payments']['captures'] as $capture) {
                            if (isset($capture['id'])) {
                                $transaction_id = $capture['id'];
                                break 2; // Exit both loops
                            }
                        }
                    }
                }
            }
            
            // Fallback, wenn keine TransaktionsID gefunden wurde
            if (empty($transaction_id)) {
                $transaction_id = $paypal_order_id;
            }
            
            // Bestellung als bezahlt markieren
            $order->payment_complete($transaction_id);
            $order->add_order_note('Zahlung via PayPal Webhook bestätigt (Order ID: ' . $paypal_order_id . ')');
            
            // TransaktionsID speichern
            update_post_meta($order->get_id(), '_paypal_transaction_id', $transaction_id);
            
            $this->log('PayPal Webhook: Bestellung #' . $order->get_id() . ' als bezahlt markiert (CHECKOUT.ORDER.COMPLETED)');
        }
        
        $order->save();
        
        // Möglicherweise weitere Aktionen ausführen
        do_action('yprint_paypal_order_completed', $order, $resource);
        
        return array(
            'success' => true,
            'message' => 'Bestellung als abgeschlossen markiert',
            'order_id' => $order->get_id()
        );
    }
 
    /**
     * Verarbeitet das Ereignis CHECKOUT.ORDER.PROCESSED
     * 
     * @param array $event_json Die Ereignisdaten
     * @return array Ergebnis der Verarbeitung
     */
    private function process_order_processed($event_json) {
        if (!isset($event_json['resource'])) {
            $this->log('PayPal Webhook: Fehlende Ressource in CHECKOUT.ORDER.PROCESSED', 'error');
            return array(
                'success' => false,
                'message' => 'Fehlende Ressource in Ereignisdaten'
            );
        }
        
        $resource = $event_json['resource'];
        
        // PayPal Order-ID extrahieren
        $paypal_order_id = isset($resource['id']) ? $resource['id'] : '';
            
        if (empty($paypal_order_id)) {
            $this->log('PayPal Webhook: Keine Order-ID in CHECKOUT.ORDER.PROCESSED', 'error');
            return array(
                'success' => false,
                'message' => 'Keine PayPal Order-ID gefunden'
            );
        }
        
        // Bestellung finden
        $order = $this->find_order_by_paypal_data('', $paypal_order_id);
        
        if (!$order) {
            $this->log('PayPal Webhook: Keine Bestellung für CHECKOUT.ORDER.PROCESSED gefunden', 'error');
            return array(
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            );
        }
        
        // Updates auf die Bestellung anwenden, falls erforderlich
        // CHECKOUT.ORDER.PROCESSED ist oft ein Zwischenschritt und erfordert keine direkte Aktion
        $order->add_order_note('PayPal Order wurde verarbeitet (ID: ' . $paypal_order_id . ')');
        $order->save();
        
        $this->log('PayPal Webhook: Bestellung #' . $order->get_id() . ' aktualisiert (CHECKOUT.ORDER.PROCESSED)');
        
        // Möglicherweise weitere Aktionen ausführen
        do_action('yprint_paypal_order_processed', $order, $resource);
        
        return array(
            'success' => true,
            'message' => 'Bestellung verarbeitet',
            'order_id' => $order->get_id()
        );
    }
 
    /**
     * Findet eine Bestellung anhand von PayPal-Daten
     * 
     * @param string $transaction_id Die PayPal-Transaktions-ID
     * @param string $order_id Die PayPal-Order-ID
     * @return WC_Order|false Die gefundene Bestellung oder false
     */
    private function find_order_by_paypal_data($transaction_id, $order_id) {
        // Zuerst nach Transaktions-ID suchen, wenn vorhanden
        if (!empty($transaction_id)) {
            $orders = wc_get_orders(array(
                'meta_key' => '_paypal_transaction_id',
                'meta_value' => $transaction_id,
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        // Nach Order-ID suchen, wenn vorhanden
        if (!empty($order_id)) {
            $orders = wc_get_orders(array(
                'meta_key' => '_paypal_order_id',
                'meta_value' => $order_id,
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        // Nach custom_id suchen, falls in der Ressource vorhanden
        if (isset($event_json['resource']['purchase_units']) && is_array($event_json['resource']['purchase_units'])) {
            foreach ($event_json['resource']['purchase_units'] as $unit) {
                if (isset($unit['custom_id']) && strpos($unit['custom_id'], 'WC-') === 0) {
                    $order_id = substr($unit['custom_id'], 3);
                    $order = wc_get_order($order_id);
                    if ($order) {
                        return $order;
                    }
                }
            }
        }
        
        // Wenn weder Transaktions-ID noch Order-ID übereinstimmen, temporäre Bestellung suchen
        $temp_order_id = WC()->session ? WC()->session->get('yprint_temp_order_id') : 0;
        if ($temp_order_id) {
            $order = wc_get_order($temp_order_id);
            if ($order && $order->get_payment_method() === 'paypal') {
                return $order;
            }
        }
        
        return false;
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
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
            'skip_webhook_signature_verification' => false
        );
        
        return isset($defaults[$feature_name]) ? $defaults[$feature_name] : false;
    }
 
    /**
     * Loggt eine Nachricht
     * 
     * @param string $message Die Nachricht
     * @param string $level Log-Level (info, error, warning, notice)
     */
    private function log($message, $level = 'info') {
        // Debug-Logging nur im Debug-Modus, es sei denn, es ist ein Fehler
        if (!$this->debug_mode && $level !== 'error') {
            return;
        }
        
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }
        
        error_log('[YPrint PayPal Webhook] [' . strtoupper($level) . '] ' . $message);
    }
 }
 
 /**
 * Hauptinstanz von YPrint_PayPal_Webhook
 * 
 * @return YPrint_PayPal_Webhook
 */
 function YPrint_PayPal_Webhook() {
    return YPrint_PayPal_Webhook::instance();
 }
 
 // Globale für Abwärtskompatibilität
 $GLOBALS['yprint_paypal_webhook'] = YPrint_PayPal_Webhook();