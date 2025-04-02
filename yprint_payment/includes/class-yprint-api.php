<?php
/**
 * API-Kommunikationsschnittstelle für YPrint Payment
 *
 * Diese Klasse dient als zentrale Schnittstelle für alle API-Kommunikationen
 * mit externen Zahlungsanbietern wie Stripe, PayPal, etc. Sie bietet standardisierte
 * Methoden für HTTP-Anfragen, Fehlerbehandlung und Logging.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API-Kommunikationsklasse
 */
class YPrint_API {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_API
     */
    protected static $_instance = null;

    /**
     * Basis-URLs für verschiedene API-Endpunkte
     *
     * @var array
     */
    private $api_base_urls = array(
        'stripe' => array(
            'live' => 'https://api.stripe.com/v1/',
            'test' => 'https://api.stripe.com/v1/'
        ),
        'paypal' => array(
            'live' => 'https://api-m.paypal.com/',
            'test' => 'https://api-m.sandbox.paypal.com/'
        ),
        'sepa' => array(
            'live' => 'https://api.example.com/v1/',
            'test' => 'https://api-test.example.com/v1/'
        )
    );

    /**
     * API-Schlüssel und Zugangsdaten
     *
     * @var array
     */
    private $api_credentials = array();

    /**
     * Letzter Fehler
     *
     * @var array
     */
    private $last_error = array();

    /**
     * Debug-Modus aktiviert/deaktiviert
     *
     * @var bool
     */
    private $debug_mode = false;

    /**
     * Hauptinstanz der YPrint_API-Klasse
     *
     * @return YPrint_API - Hauptinstanz
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
        // Feature Flags laden
        $this->feature_flags = YPrint_Feature_Flags::instance();
        
        // Debug-Modus anhand der Feature-Flags setzen
        $this->debug_mode = $this->feature_flags->is_enabled('debug_mode');
        
        // API-Zugangsdaten laden
        $this->load_api_credentials();
    }

    /**
     * Lädt API-Zugangsdaten aus den WordPress-Optionen
     */
    private function load_api_credentials() {
        // Stripe Zugangsdaten
        $this->api_credentials['stripe'] = array(
            'public_key' => get_option('yprint_stripe_public_key', 'INSERT_API_KEY_HERE'),
            'secret_key' => get_option('yprint_stripe_secret_key', 'INSERT_API_KEY_HERE'),
            'webhook_secret' => get_option('yprint_stripe_webhook_secret', 'INSERT_API_KEY_HERE'),
            'test_mode' => get_option('yprint_stripe_test_mode', 'no') === 'yes'
        );
        
        // PayPal Zugangsdaten
        $this->api_credentials['paypal'] = array(
            'client_id' => get_option('yprint_paypal_client_id', 'INSERT_API_KEY_HERE'),
            'secret_key' => get_option('yprint_paypal_secret_key', 'INSERT_API_KEY_HERE'),
            'webhook_id' => get_option('yprint_paypal_webhook_id', 'INSERT_API_KEY_HERE'),
            'test_mode' => get_option('yprint_paypal_test_mode', 'no') === 'yes'
        );
        
        // SEPA Zugangsdaten
        $this->api_credentials['sepa'] = array(
            'api_key' => get_option('yprint_sepa_api_key', 'INSERT_API_KEY_HERE'),
            'api_secret' => get_option('yprint_sepa_api_secret', 'INSERT_API_KEY_HERE'),
            'test_mode' => get_option('yprint_sepa_test_mode', 'no') === 'yes'
        );
        
        // Hook für benutzerdefinierte Zugangsdaten
        $this->api_credentials = apply_filters('yprint_api_credentials', $this->api_credentials);
    }

    /**
     * Führt einen HTTP-GET-Request durch
     *
     * @param string $gateway Der Zahlungsanbieter (stripe, paypal, etc.)
     * @param string $endpoint Der API-Endpunkt
     * @param array $params Query-Parameter für die Anfrage
     * @param array $headers Zusätzliche Header
     * @return array|WP_Error Antwort oder Fehler
     */
    public function get($gateway, $endpoint, $params = array(), $headers = array()) {
        return $this->request($gateway, $endpoint, 'GET', $params, array(), $headers);
    }

    /**
     * Führt einen HTTP-POST-Request durch
     *
     * @param string $gateway Der Zahlungsanbieter (stripe, paypal, etc.)
     * @param string $endpoint Der API-Endpunkt
     * @param array $data POST-Daten für die Anfrage
     * @param array $headers Zusätzliche Header
     * @return array|WP_Error Antwort oder Fehler
     */
    public function post($gateway, $endpoint, $data = array(), $headers = array()) {
        return $this->request($gateway, $endpoint, 'POST', array(), $data, $headers);
    }

    /**
     * Führt einen HTTP-PUT-Request durch
     *
     * @param string $gateway Der Zahlungsanbieter (stripe, paypal, etc.)
     * @param string $endpoint Der API-Endpunkt
     * @param array $data PUT-Daten für die Anfrage
     * @param array $headers Zusätzliche Header
     * @return array|WP_Error Antwort oder Fehler
     */
    public function put($gateway, $endpoint, $data = array(), $headers = array()) {
        return $this->request($gateway, $endpoint, 'PUT', array(), $data, $headers);
    }

    /**
     * Führt einen HTTP-DELETE-Request durch
     *
     * @param string $gateway Der Zahlungsanbieter (stripe, paypal, etc.)
     * @param string $endpoint Der API-Endpunkt
     * @param array $params Query-Parameter für die Anfrage
     * @param array $headers Zusätzliche Header
     * @return array|WP_Error Antwort oder Fehler
     */
    public function delete($gateway, $endpoint, $params = array(), $headers = array()) {
        return $this->request($gateway, $endpoint, 'DELETE', $params, array(), $headers);
    }

    /**
     * Führt einen HTTP-Request durch
     *
     * @param string $gateway Der Zahlungsanbieter (stripe, paypal, etc.)
     * @param string $endpoint Der API-Endpunkt
     * @param string $method Die HTTP-Methode (GET, POST, etc.)
     * @param array $params Query-Parameter für die Anfrage
     * @param array $data POST/PUT-Daten für die Anfrage
     * @param array $headers Zusätzliche Header
     * @return array|WP_Error Antwort oder Fehler
     */
    public function request($gateway, $endpoint, $method = 'GET', $params = array(), $data = array(), $headers = array()) {
        // Basis-URL basierend auf Gateway und Test-Modus
        $base_url = $this->get_api_base_url($gateway);
        
        if (empty($base_url)) {
            $error = new WP_Error('invalid_gateway', sprintf('Ungültiges Gateway: %s', $gateway));
            $this->set_last_error($error, 'api_request');
            return $error;
        }
        
        // Vollständige URL erstellen
        $url = $base_url . ltrim($endpoint, '/');
        
        // Query-Parameter hinzufügen
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }
        
        // Authentifizierungs-Header hinzufügen
        $headers = array_merge($headers, $this->get_auth_headers($gateway));
        
        // HTTP-Anfrage-Argumente
        $args = array(
            'method' => $method,
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'sslverify' => true,
            'headers' => $headers,
            'body' => $method !== 'GET' ? $this->prepare_request_body($gateway, $data) : null
        );
        
        // Debug-Log: Anfrage
        if ($this->debug_mode) {
            $log_data = array(
                'gateway' => $gateway,
                'url' => $url,
                'method' => $method,
                'headers' => $this->sanitize_log_data($headers),
                'body' => $method !== 'GET' ? $this->sanitize_log_data($data) : null
            );
            $this->log('API-Anfrage: ' . wp_json_encode($log_data));
        }
        
        // Anfrage ausführen
        $response = wp_remote_request($url, $args);
        
        // Fehler prüfen
        if (is_wp_error($response)) {
            $this->set_last_error($response, 'http_request');
            
            if ($this->debug_mode) {
                $this->log('API-Fehler: ' . $response->get_error_message());
            }
            
            return $response;
        }
        
        // Statuscode und Body extrahieren
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Debug-Log: Antwort
        if ($this->debug_mode) {
            $log_data = array(
                'status_code' => $status_code,
                'body' => $this->parse_response_body($gateway, $body)
            );
            $this->log('API-Antwort: ' . wp_json_encode($log_data));
        }
        
        // Erfolgreiche Antwort verarbeiten
        if ($status_code >= 200 && $status_code < 300) {
            return array(
                'status_code' => $status_code,
                'body' => $this->parse_response_body($gateway, $body),
                'headers' => wp_remote_retrieve_headers($response)
            );
        }
        
        // Fehlerhafte Antwort verarbeiten
        $error_data = $this->parse_response_body($gateway, $body);
        $error = new WP_Error(
            'api_error',
            sprintf('API-Fehler: %s - %s', $status_code, $this->get_error_message($gateway, $error_data)),
            array(
                'status_code' => $status_code,
                'error_data' => $error_data
            )
        );
        $this->set_last_error($error, 'api_response');
        
        return $error;
    }

    /**
     * Erstellt die notwendigen Authentifizierungs-Header für ein Gateway
     *
     * @param string $gateway Der Zahlungsanbieter
     * @return array Header für die Authentifizierung
     */
    private function get_auth_headers($gateway) {
        $headers = array();
        
        switch ($gateway) {
            case 'stripe':
                $secret_key = $this->api_credentials['stripe']['secret_key'];
                $headers['Authorization'] = 'Bearer ' . $secret_key;
                $headers['Stripe-Version'] = '2022-11-15'; // Aktuelle API-Version
                break;
                
            case 'paypal':
                // Für PayPal implementieren wir die OAuth-Authentifizierung separat
                // da dies ein komplexerer Prozess mit Token-Generierung ist
                $headers['Content-Type'] = 'application/json';
                break;
                
            case 'sepa':
                $api_key = $this->api_credentials['sepa']['api_key'];
                $headers['Authorization'] = 'Bearer ' . $api_key;
                break;
        }
        
        return $headers;
    }

    /**
     * Bereitet den Request-Body für einen API-Request vor
     *
     * @param string $gateway Der Zahlungsanbieter
     * @param array $data Die zu sendenden Daten
     * @return string|array Vorbereiteter Request-Body
     */
    private function prepare_request_body($gateway, $data) {
        switch ($gateway) {
            case 'stripe':
                // Stripe erwartet Daten als application/x-www-form-urlencoded
                return $data;
                
            case 'paypal':
            case 'sepa':
                // PayPal und SEPA erwarten Daten als JSON
                return wp_json_encode($data);
                
            default:
                return $data;
        }
    }

    /**
     * Parst den Response-Body einer API-Antwort
     *
     * @param string $gateway Der Zahlungsanbieter
     * @param string $body Der Response-Body
     * @return array|object Geparstes Objekt oder Array
     */
    private function parse_response_body($gateway, $body) {
        switch ($gateway) {
            case 'stripe':
            case 'paypal':
            case 'sepa':
                // Versuchen, JSON zu dekodieren
                $decoded = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
                break;
        }
        
        // Fallback: Originaltext zurückgeben
        return $body;
    }

    /**
     * Extrahiert die Fehlermeldung aus einer API-Fehlerantwort
     *
     * @param string $gateway Der Zahlungsanbieter
     * @param mixed $error_data Die Fehlerdaten
     * @return string Die Fehlermeldung
     */
    private function get_error_message($gateway, $error_data) {
        if (empty($error_data)) {
            return 'Unbekannter Fehler';
        }
        
        switch ($gateway) {
            case 'stripe':
                if (isset($error_data['error']['message'])) {
                    return $error_data['error']['message'];
                }
                break;
                
            case 'paypal':
                if (isset($error_data['message'])) {
                    return $error_data['message'];
                } elseif (isset($error_data['error_description'])) {
                    return $error_data['error_description'];
                }
                break;
                
            case 'sepa':
                if (isset($error_data['message'])) {
                    return $error_data['message'];
                }
                break;
        }
        
        // Fallback: Standard-Fehlermeldung
        return 'Ein Fehler ist bei der Kommunikation mit dem Zahlungsdienstleister aufgetreten.';
    }

    /**
     * Speichert den letzten Fehler
     *
     * @param WP_Error $error Der Fehler
     * @param string $context Der Kontext des Fehlers
     */
    private function set_last_error($error, $context = '') {
        $this->last_error = array(
            'error' => $error,
            'context' => $context,
            'timestamp' => time()
        );
    }

    /**
     * Gibt den letzten Fehler zurück
     *
     * @return array Der letzte Fehler
     */
    public function get_last_error() {
        return $this->last_error;
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
            'password', 'pwd', 'pass', 'secret', 'key', 'api_key', 'access_token', 
            'auth', 'credentials', 'token', 'authorization', 'card', 'cc', 'cvv', 
            'cvc', 'number', 'expiry', 'exp', 'expiration'
        );
        
        foreach ($data as $key => $value) {
            // Prüfen auf sensible Felder
            foreach ($sensitive_fields as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $data[$key] = '***MASKED***';
                    break;
                }
            }
            
            // Rekursiv für Arrays
            if (is_array($value)) {
                $data[$key] = $this->sanitize_log_data($value);
            }
        }
        
        return $data;
    }

    /**
     * Protokolliert eine Nachricht im Debug-Modus
     *
     * @param string $message Die zu protokollierende Nachricht
     * @param string $level Log-Level (debug, info, warning, error)
     */
    private function log($message, $level = 'info') {
        if (!$this->debug_mode) {
            return;
        }
        
        $log_levels = array(
            'debug' => 7,
            'info' => 6,
            'warning' => 4,
            'error' => 3
        );
        
        // Standardlevel, wenn ungültiges Level angegeben wurde
        if (!isset($log_levels[$level])) {
            $level = 'info';
        }
        
        error_log('[YPrint API] [' . strtoupper($level) . '] ' . $message);
    }

    /**
     * Generiert ein OAuth-Token für PayPal
     *
     * @return string|WP_Error Das generierte Token oder ein Fehler
     */
    public function get_paypal_oauth_token() {
        $client_id = $this->api_credentials['paypal']['client_id'];
        $secret_key = $this->api_credentials['paypal']['secret_key'];
        
        // Token aus Cache holen, falls vorhanden und gültig
        $cached_token = get_transient('yprint_paypal_oauth_token');
        if ($cached_token) {
            return $cached_token;
        }
        
        // Token-Basis-URL
        $base_url = $this->get_api_base_url('paypal');
        $url = $base_url . 'v1/oauth2/token';
        
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
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret_key)
            ),
            'body' => 'grant_type=client_credentials'
        );
        
        // Anfrage ausführen
        $response = wp_remote_request($url, $args);
        
        // Fehler prüfen
        if (is_wp_error($response)) {
            $this->set_last_error($response, 'paypal_oauth');
            return $response;
        }
        
        // Antwort parsen
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200 || !isset($body['access_token'])) {
            $error = new WP_Error(
                'paypal_oauth_error',
                'Fehler bei der PayPal-OAuth-Authentifizierung',
                array(
                    'status_code' => $status_code,
                    'response' => $body
                )
            );
            $this->set_last_error($error, 'paypal_oauth');
            return $error;
        }
        
        // Token im Cache speichern (etwas kürzer als die Ablaufzeit)
        $expires_in = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;
        set_transient('yprint_paypal_oauth_token', $body['access_token'], $expires_in - 60);
        
        return $body['access_token'];
    }

    /**
     * Gibt die API-Basis-URL für ein Gateway zurück
     *
     * @param string $gateway Der Zahlungsanbieter
     * @return string Die API-Basis-URL
     */
    private function get_api_base_url($gateway) {
        if (!isset($this->api_base_urls[$gateway])) {
            return '';
        }
        
        $is_test_mode = $this->api_credentials[$gateway]['test_mode'] ?? false;
        $mode = $is_test_mode ? 'test' : 'live';
        
        return $this->api_base_urls[$gateway][$mode];
    }

    /**
     * Überprüft die Webhook-Signatur für verschiedene Gateways
     *
     * @param string $gateway Der Zahlungsanbieter
     * @param string $payload Der Webhook-Payload
     * @param array $headers Die HTTP-Header
     * @return bool True, wenn die Signatur gültig ist, sonst false
     */
    public function verify_webhook_signature($gateway, $payload, $headers) {
        switch ($gateway) {
            case 'stripe':
                return $this->verify_stripe_webhook_signature($payload, $headers);
                
            case 'paypal':
                return $this->verify_paypal_webhook_signature($payload, $headers);
                
            default:
                return false;
        }
    }
    
    /**
     * Überprüft die Webhook-Signatur für Stripe
     *
     * @param string $payload Der Webhook-Payload
     * @param array $headers Die HTTP-Header
     * @return bool True, wenn die Signatur gültig ist, sonst false
     */
    private function verify_stripe_webhook_signature($payload, $headers) {
        if (!class_exists('Stripe\Webhook')) {
            // Stripe-Bibliothek laden
            if (file_exists(ABSPATH . 'wp-content/plugins/woocommerce-gateway-stripe/includes/compat/class-wc-stripe-webhook.php')) {
                require_once ABSPATH . 'wp-content/plugins/woocommerce-gateway-stripe/includes/compat/class-wc-stripe-webhook.php';
            } else {
                $this->log('Stripe Webhook Klasse konnte nicht geladen werden', 'error');
                return false;
            }
        }
        
        $webhook_secret = $this->api_credentials['stripe']['webhook_secret'];
        $signature = isset($headers['Stripe-Signature']) ? $headers['Stripe-Signature'] : '';
        
        if (empty($webhook_secret) || empty($signature)) {
            $this->log('Stripe Webhook: Fehlendes Secret oder Signatur', 'error');
            return false;
        }
        
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $webhook_secret);
            return true;
        } catch (\Exception $e) {
            $this->log('Stripe Webhook Signaturvalidierung fehlgeschlagen: ' . $e->getMessage(), 'error');
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
    private function verify_paypal_webhook_signature($payload, $headers) {
        $webhook_id = $this->api_credentials['paypal']['webhook_id'];
        
        if (empty($webhook_id)) {
            $this->log('PayPal Webhook: Fehlende Webhook-ID', 'error');
            return false;
        }
        
        // Token abrufen
        $token = $this->get_paypal_oauth_token();
        if (is_wp_error($token)) {
            $this->log('PayPal Webhook: OAuth-Token konnte nicht abgerufen werden', 'error');
            return false;
        }
        
        // Basis-URL
        $base_url = $this->get_api_base_url('paypal');
        $url = $base_url . 'v1/notifications/verify-webhook-signature';
        
        // Daten für die Signaturprüfung
        $data = array(
            'webhook_id' => $webhook_id,
            'transmission_id' => $headers['Paypal-Transmission-Id'] ?? '',
            'transmission_time' => $headers['Paypal-Transmission-Time'] ?? '',
            'cert_url' => $headers['Paypal-Cert-Url'] ?? '',
            'auth_algo' => $headers['Paypal-Auth-Algo'] ?? '',
            'transmission_sig' => $headers['Paypal-Transmission-Sig'] ?? '',
            'webhook_event' => json_decode($payload, true)
        );
        
        // HTTP-Anfrage-Argumente
        $args = array(
            'method' => 'POST',
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'sslverify' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'body' => wp_json_encode($data)
        );
        
        // Anfrage ausführen
        $response = wp_remote_request($url, $args);
        
        // Fehler prüfen
        if (is_wp_error($response)) {
            $this->log('PayPal Webhook: Fehler bei der Signaturüberprüfung', 'error');
            return false;
        }
        
        // Antwort parsen
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200 || !isset($body['verification_status'])) {
            $this->log('PayPal Webhook: Ungültige Antwort bei der Signaturüberprüfung', 'error');
            return false;
        }
        
        // Signatur prüfen
        return $body['verification_status'] === 'SUCCESS';
    }

    /**
     * Verarbeitet einen Stripe-Webhook
     *
     * @param string $payload Der Webhook-Payload
     * @param array $headers Die HTTP-Header
     * @return array Verarbeitungsergebnis
     */
    public function process_stripe_webhook($payload, $headers) {
        // Signatur überprüfen
        if (!$this->verify_webhook_signature('stripe', $payload, $headers)) {
            return array(
                'success' => false,
                'message' => 'Ungültige Webhook-Signatur'
            );
        }
        
        // Event aus Payload extrahieren
        $event_data = json_decode($payload, true);
        if (!$event_data || !isset($event_data['type'])) {
            return array(
                'success' => false,
                'message' => 'Ungültiger Webhook-Payload'
            );
        }
        
        $event_type = $event_data['type'];
        $object_data = $event_data['data']['object'] ?? array();
        
        // Eventkategorien
        switch ($event_type) {
            case 'payment_intent.succeeded':
                return $this->handle_stripe_payment_succeeded($object_data);
                
            case 'payment_intent.payment_failed':
                return $this->handle_stripe_payment_failed($object_data);
                
            case 'charge.refunded':
                return $this->handle_stripe_refund($object_data);
                
            default:
                // Unbekanntes Event-Logging
                $this->log('Stripe Webhook: Unbekannter Event-Typ: ' . $event_type, 'warning');
                return array(
                    'success' => true,
                    'message' => 'Unbekannter Event-Typ, aber erfolgreich empfangen'
                );
        }
    }
    
    /**
     * Verarbeitet einen PayPal-Webhook
     *
     * @param string $payload Der Webhook-Payload
     * @param array $headers Die HTTP-Header
     * @return array Verarbeitungsergebnis
     */
    public function process_paypal_webhook($payload, $headers) {
        // Signatur überprüfen
        if (!$this->verify_webhook_signature('paypal', $payload, $headers)) {
            return array(
                'success' => false,
                'message' => 'Ungültige Webhook-Signatur'
            );
        }
        
        // Event aus Payload extrahieren
        $event_data = json_decode($payload, true);
        if (!$event_data || !isset($event_data['event_type'])) {
            return array(
                'success' => false,
                'message' => 'Ungültiger Webhook-Payload'
            );
        }
        
        $event_type = $event_data['event_type'];
        $resource = $event_data['resource'] ?? array();
        
        // Eventkategorien
        switch ($event_type) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                return $this->handle_paypal_payment_completed($resource);
                
            case 'PAYMENT.CAPTURE.DENIED':
            case 'PAYMENT.CAPTURE.REFUNDED':
            case 'PAYMENT.CAPTURE.REVERSED':
                return $this->handle_paypal_payment_failed($resource, $event_type);
                
            case 'CHECKOUT.ORDER.APPROVED':
                return $this->handle_paypal_order_approved($resource);
                
            case 'CHECKOUT.ORDER.COMPLETED':
                return $this->handle_paypal_order_completed($resource);
                
            default:
                // Unbekanntes Event-Logging
                $this->log('PayPal Webhook: Unbekannter Event-Typ: ' . $event_type, 'warning');
                return array(
                    'success' => true,
                    'message' => 'Unbekannter Event-Typ, aber erfolgreich empfangen'
                );
        }
    }

    /**
     * Verarbeitet erfolgreiche Stripe-Zahlungen
     *
     * @param array $payment_intent Die Payment Intent Daten
     * @return array Verarbeitungsergebnis
     */
    private function handle_stripe_payment_succeeded($payment_intent) {
        $this->log('Verarbeite erfolgreiche Stripe-Zahlung: ' . json_encode($payment_intent));
        
        if (!isset($payment_intent['id'])) {
            return array(
                'success' => false,
                'message' => 'Payment Intent ID fehlt'
            );
        }
        
        $payment_intent_id = $payment_intent['id'];
        
        // Order ID aus Stripe Payment Intent ID finden
        $orders = wc_get_orders(array(
            'meta_key' => '_stripe_payment_intent',
            'meta_value' => $payment_intent_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            // Versuche über Metadaten zu finden, wenn vorhanden
            if (isset($payment_intent['metadata']['order_id'])) {
                $order_id = $payment_intent['metadata']['order_id'];
                $this->log('Suche Bestellung anhand der ID aus Metadaten: ' . $order_id);
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
        
        // Prüfen, ob Bestellung bereits bezahlt wurde
        if ($order->is_paid()) {
            return array(
                'success' => true,
                'message' => 'Bestellung wurde bereits bezahlt'
            );
        }
        
        // Bestellung als bezahlt markieren
        $charge_id = isset($payment_intent['latest_charge']) ? $payment_intent['latest_charge'] : $payment_intent_id;
        $order->payment_complete($charge_id);
        $order->add_order_note('Zahlung via Stripe Webhook bestätigt (Charge ID: ' . $charge_id . ')');
        
        // Speichere Payment Intent ID, falls noch nicht gespeichert
        if (!get_post_meta($order->get_id(), '_stripe_payment_intent', true)) {
            update_post_meta($order->get_id(), '_stripe_payment_intent', $payment_intent_id);
        }
        
        // Speichere auch SCA Status
        update_post_meta($order->get_id(), '_stripe_sca_required', !empty($payment_intent['next_action']));
        
        $order->save();
        
        return array(
            'success' => true,
            'message' => 'Zahlung erfolgreich verarbeitet',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet fehlgeschlagene Stripe-Zahlungen
     *
     * @param array $payment_intent Die Payment Intent Daten
     * @return array Verarbeitungsergebnis
     */
    private function handle_stripe_payment_failed($payment_intent) {
        $this->log('Verarbeite fehlgeschlagene Stripe-Zahlung: ' . json_encode($payment_intent));
        
        if (!isset($payment_intent['id'])) {
            return array(
                'success' => false,
                'message' => 'Payment Intent ID fehlt'
            );
        }
        
        $payment_intent_id = $payment_intent['id'];
        
        // Order ID aus Stripe Payment Intent ID finden
        $orders = wc_get_orders(array(
            'meta_key' => '_stripe_payment_intent',
            'meta_value' => $payment_intent_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            // Versuche über Metadaten zu finden, wenn vorhanden
            if (isset($payment_intent['metadata']['order_id'])) {
                $order_id = $payment_intent['metadata']['order_id'];
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
        
        // Fehlernachricht extrahieren
        $error_message = 'Zahlung fehlgeschlagen';
        if (isset($payment_intent['last_payment_error']['message'])) {
            $error_message = $payment_intent['last_payment_error']['message'];
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
     * Verarbeitet Stripe-Rückerstattungen
     *
     * @param array $charge Die Charge-Daten
     * @return array Verarbeitungsergebnis
     */
    private function handle_stripe_refund($charge) {
        $this->log('Verarbeite Stripe-Rückerstattung: ' . json_encode($charge));
        
        if (!isset($charge['id'])) {
            return array(
                'success' => false,
                'message' => 'Charge ID fehlt'
            );
        }
        
        $charge_id = $charge['id'];
        
        // Order ID aus Stripe Charge ID finden
        $orders = wc_get_orders(array(
            'meta_key' => '_transaction_id',
            'meta_value' => $charge_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            // Alternative Suche nach Payment Intent ID
            if (isset($charge['payment_intent'])) {
                $orders = wc_get_orders(array(
                    'meta_key' => '_stripe_payment_intent',
                    'meta_value' => $charge['payment_intent'],
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
        $refunded_amount = $charge['amount_refunded'];
        $total_amount = $charge['amount'];
        
        if ($refunded_amount === $total_amount) {
            // Vollständige Rückerstattung
            $order->update_status('refunded', 'Stripe Zahlung vollständig zurückerstattet.');
        } else {
            // Teilweise Rückerstattung
            $refund_note = sprintf(
                'Stripe Zahlung teilweise zurückerstattet: %s von %s',
                wc_price($refunded_amount / 100),
                wc_price($total_amount / 100)
            );
            $order->add_order_note($refund_note);
            
            // WooCommerce Refund erstellen
            $refund_amount = wc_format_decimal($refunded_amount / 100);
            wc_create_refund(array(
                'order_id' => $order->get_id(),
                'amount' => $refund_amount,
                'reason' => 'Stripe Teilrückerstattung (Webhook)'
            ));
        }
        
        $order->save();
        
        return array(
            'success' => true,
            'message' => 'Rückerstattung erfolgreich verarbeitet',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet abgeschlossene PayPal-Zahlungen
     *
     * @param array $resource Die Resource-Daten
     * @return array Verarbeitungsergebnis
     */
    private function handle_paypal_payment_completed($resource) {
        $this->log('Verarbeite abgeschlossene PayPal-Zahlung: ' . json_encode($resource));
        
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
                'message' => 'Bestellung wurde bereits bezahlt'
            );
        }
        
        // Bestellung als bezahlt markieren
        $order->payment_complete($transaction_id);
        $order->add_order_note('Zahlung via PayPal Webhook bestätigt (Transaktion ID: ' . $transaction_id . ')');
        
        // Transaction ID in Meta speichern
        update_post_meta($order->get_id(), '_paypal_transaction_id', $transaction_id);
        
        $order->save();
        
        return array(
            'success' => true,
            'message' => 'Zahlung erfolgreich verarbeitet',
            'order_id' => $order->get_id()
        );
    }

    /**
     * Verarbeitet fehlgeschlagene PayPal-Zahlungen
     *
     * @param array $resource Die Resource-Daten
     * @param string $event_type Der Event-Typ
     * @return array Verarbeitungsergebnis
     */
    private function handle_paypal_payment_failed($resource, $event_type) {
        $this->log('Verarbeite fehlgeschlagene PayPal-Zahlung: ' . json_encode($resource) . ', Event: ' . $event_type);
        
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
     * @param array $resource Die Resource-Daten
     * @return array Verarbeitungsergebnis
     */
    private function handle_paypal_order_approved($resource) {
        $this->log('Verarbeite genehmigte PayPal-Bestellung: ' . json_encode($resource));
        
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
     * @param array $resource Die Resource-Daten
     * @return array Verarbeitungsergebnis
     */
    private function handle_paypal_order_completed($resource) {
        $this->log('Verarbeite abgeschlossene PayPal-Bestellung: ' . json_encode($resource));
        
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
     * Erstellt einen Stripe-Checkout-Sitzung für die Zahlung
     *
     * @param int $order_id Bestellungs-ID
     * @param string $success_url URL für erfolgreiche Zahlungen
     * @param string $cancel_url URL für abgebrochene Zahlungen
     * @return array|WP_Error Checkout-Session oder Fehler
     */
    public function create_stripe_checkout_session($order_id, $success_url, $cancel_url) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', 'Ungültige Bestellung');
        }
        
        $line_items = array();
        
        // Artikel hinzufügen
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $price_in_cents = round($item->get_total() * 100);
            
            $line_items[] = array(
                'price_data' => array(
                    'currency' => $order->get_currency(),
                    'unit_amount' => $price_in_cents / $item->get_quantity(),
                    'product_data' => array(
                        'name' => $item->get_name(),
                        'description' => $product ? $product->get_description() : '',
                        'images' => $product ? array($product->get_image_id() ? wp_get_attachment_url($product->get_image_id()) : '') : array(),
                    ),
                ),
                'quantity' => $item->get_quantity(),
            );
        }
        
        // Versandkosten hinzufügen, falls vorhanden
        $shipping_total = $order->get_shipping_total();
        if ($shipping_total > 0) {
            $line_items[] = array(
                'price_data' => array(
                    'currency' => $order->get_currency(),
                    'unit_amount' => round($shipping_total * 100),
                    'product_data' => array(
                        'name' => 'Versand',
                        'description' => 'Versandkosten',
                    ),
                ),
                'quantity' => 1,
            );
        }
        
        // Steuer hinzufügen, falls separat
        $tax_total = $order->get_total_tax();
        if ($tax_total > 0) {
            $line_items[] = array(
                'price_data' => array(
                    'currency' => $order->get_currency(),
                    'unit_amount' => round($tax_total * 100),
                    'product_data' => array(
                        'name' => 'Steuern',
                        'description' => 'Steuerbetrag',
                    ),
                ),
                'quantity' => 1,
            );
        }
        
        // Stripe API-Aufruf
        $session_data = array(
            'payment_method_types' => array('card'),
            'line_items' => $line_items,
            'mode' => 'payment',
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'client_reference_id' => $order_id,
            'customer_email' => $order->get_billing_email(),
            'payment_intent_data' => array(
                'description' => 'Bestellung #' . $order->get_id(),
                'metadata' => array(
                    'order_id' => $order_id,
                    'website' => get_bloginfo('url')
                )
            )
        );
        
        // Optional: Versandadressen-Sammlung
        if ($this->feature_flags->is_enabled('stripe_collect_shipping')) {
            $session_data['shipping_address_collection'] = array(
                'allowed_countries' => array('DE', 'AT', 'CH', 'LU')
            );
        }
        
        // Session erstellen
        $response = $this->post('stripe', 'checkout/sessions', $session_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $session = $response['body'];
        
        // Session-ID in Bestellung speichern
        update_post_meta($order_id, '_stripe_checkout_session', $session['id']);
        
        return $session;
    }

    /**
     * Erstellt einen PayPal-Order für die Zahlung
     *
     * @param int $order_id Bestellungs-ID
     * @param string $return_url URL für Rückleitung nach erfolgreicher Zahlung
     * @param string $cancel_url URL für abgebrochene Zahlungen
     * @return array|WP_Error PayPal-Order oder Fehler
     */
    public function create_paypal_order($order_id, $return_url, $cancel_url) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', 'Ungültige Bestellung');
        }
        
        // Token abrufen
        $token = $this->get_paypal_oauth_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        $items = array();
        $subtotal = 0;
        
        // Artikel hinzufügen
        foreach ($order->get_items() as $item) {
            $price = round($item->get_total() / $item->get_quantity(), 2);
            $subtotal += $item->get_total();
            
            $items[] = array(
                'name' => $item->get_name(),
                'unit_amount' => array(
                    'currency_code' => $order->get_currency(),
                    'value' => number_format($price, 2, '.', '')
                ),
                'quantity' => $item->get_quantity(),
                'description' => substr(strip_tags($item->get_product() ? $item->get_product()->get_description() : ''), 0, 127)
            );
        }
        
        // Versandkosten und Steuern
        $shipping_total = $order->get_shipping_total();
        $tax_total = $order->get_total_tax();
        
        // Eindeutige Referenz
        $reference_id = 'order_' . $order_id . '_' . time();
        
        // Order-Daten
        $order_data = array(
            'intent' => 'CAPTURE',
            'purchase_units' => array(
                array(
                    'reference_id' => $reference_id,
                    'description' => 'Bestellung #' . $order->get_id() . ' bei ' . get_bloginfo('name'),
                    'amount' => array(
                        'currency_code' => $order->get_currency(),
                        'value' => number_format($order->get_total(), 2, '.', ''),
                        'breakdown' => array(
                            'item_total' => array(
                                'currency_code' => $order->get_currency(),
                                'value' => number_format($subtotal, 2, '.', '')
                            ),
                            'shipping' => array(
                                'currency_code' => $order->get_currency(),
                                'value' => number_format($shipping_total, 2, '.', '')
                            ),
                            'tax_total' => array(
                                'currency_code' => $order->get_currency(),
                                'value' => number_format($tax_total, 2, '.', '')
                            )
                        )
                    ),
                    'items' => $items,
                    'custom_id' => 'WC-' . $order->get_id()
                )
            ),
            'application_context' => array(
                'brand_name' => get_bloginfo('name'),
                'landing_page' => 'BILLING',
                'shipping_preference' => 'SET_PROVIDED_ADDRESS',
                'user_action' => 'PAY_NOW',
                'return_url' => $return_url,
                'cancel_url' => $cancel_url
            )
        );
        
        // Lieferadresse hinzufügen
        if ($order->get_shipping_address_1()) {
            $order_data['purchase_units'][0]['shipping'] = array(
                'name' => array(
                    'full_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()
                ),
                'address' => array(
                    'address_line_1' => $order->get_shipping_address_1(),
                    'address_line_2' => $order->get_shipping_address_2(),
                    'admin_area_2' => $order->get_shipping_city(),
                    'postal_code' => $order->get_shipping_postcode(),
                    'country_code' => $order->get_shipping_country()
                )
            );
        }
        
        // API-Header mit Token
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        );
        
        // PayPal-Order erstellen
        $response = $this->post('paypal', 'v2/checkout/orders', $order_data, $headers);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $paypal_order = $response['body'];
        
        // Order-ID in Bestellung speichern
        update_post_meta($order_id, '_paypal_order_id', $paypal_order['id']);
        
        return $paypal_order;
    }

    /**
     * Holt die Details eines Stripe Payment Intent
     *
     * @param string $payment_intent_id Die Payment Intent ID
     * @return array|WP_Error Payment Intent Details oder Fehler
     */
    public function get_stripe_payment_intent($payment_intent_id) {
        return $this->get('stripe', 'payment_intents/' . $payment_intent_id);
    }

    /**
     * Holt die Details einer PayPal-Order
     *
     * @param string $order_id Die PayPal-Order ID
     * @return array|WP_Error Order Details oder Fehler
     */
    public function get_paypal_order($order_id) {
        // Token abrufen
        $token = $this->get_paypal_oauth_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        // API-Header mit Token
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        );
        
        return $this->get('paypal', 'v2/checkout/orders/' . $order_id, array(), $headers);
    }

    /**
     * Erfasst eine PayPal-Zahlung (Capture)
     *
     * @param string $order_id Die PayPal-Order ID
     * @return array|WP_Error Capture-Ergebnis oder Fehler
     */
    public function capture_paypal_payment($order_id) {
        // Token abrufen
        $token = $this->get_paypal_oauth_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        // API-Header mit Token
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Prefer' => 'return=representation'
        );
        
        // Leerer Body für Capture
        return $this->post('paypal', 'v2/checkout/orders/' . $order_id . '/capture', array(), $headers);
    }

    //Sammelt SEPA-Zahlungsinformationen
     * @param array $sepa_data SEPA-Zahlungsdaten (IBAN, BIC, Kontoinhaber, Mandatsreferenz)
     * @param int $order_id Bestellungs-ID
     * @return array|WP_Error SEPA-Zahlungsbestätigung oder Fehler
     */
    public function process_sepa_payment($sepa_data, $order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', 'Ungültige Bestellung');
        }
        
        // SEPA-Validierung
        if (empty($sepa_data['iban'])) {
            return new WP_Error('invalid_iban', 'Ungültige IBAN');
        }
        
        if (empty($sepa_data['account_holder'])) {
            return new WP_Error('invalid_account_holder', 'Ungültiger Kontoinhaber');
        }
        
        // IBAN validieren (einfacher Check)
        $iban = str_replace(' ', '', strtoupper($sepa_data['iban']));
        if (!$this->validate_iban($iban)) {
            return new WP_Error('invalid_iban_format', 'Das IBAN-Format ist ungültig');
        }
        
        // BIC validieren, falls vorhanden
        if (!empty($sepa_data['bic'])) {
            $bic = str_replace(' ', '', strtoupper($sepa_data['bic']));
            if (!$this->validate_bic($bic)) {
                return new WP_Error('invalid_bic_format', 'Das BIC-Format ist ungültig');
            }
        }
        
        // Eindeutige Mandatsreferenz generieren
        $mandate_reference = 'YP-SEPA-' . $order_id . '-' . time();
        
        // SEPA-Daten für die Bestellung speichern
        update_post_meta($order_id, '_sepa_payment_data', array(
            'iban' => $this->mask_iban($iban), // IBAN maskieren aus Sicherheitsgründen
            'bic' => $bic ?? '',
            'account_holder' => sanitize_text_field($sepa_data['account_holder']),
            'mandate_reference' => $mandate_reference,
            'mandate_date' => current_time('mysql')
        ));
        
        // Bestellung für SEPA-Zahlung aktualisieren
        $order->update_status('on-hold', 'SEPA-Lastschriftmandat erteilt: ' . $mandate_reference);
        $order->set_payment_method('sepa');
        $order->set_payment_method_title('SEPA-Lastschrift');
        $order->save();
        
        // Bestätigungsmail für das SEPA-Mandat senden, falls Feature aktiviert
        if ($this->feature_flags->is_enabled('sepa_mandate_email')) {
            $this->send_sepa_mandate_confirmation($order, $iban, $mandate_reference);
        }
        
        return array(
            'success' => true,
            'mandate_reference' => $mandate_reference,
            'order_id' => $order_id,
            'message' => 'SEPA-Lastschriftmandat erfolgreich erstellt'
        );
    }
    
    /**
     * Validiert eine IBAN
     *
     * @param string $iban Die zu validierende IBAN
     * @return bool True, wenn gültig, sonst false
     */
    private function validate_iban($iban) {
        // Grundlegende Prüfung (Länge, Format)
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }
        
        // Ländercode prüfen (erste 2 Zeichen müssen Buchstaben sein)
        if (!ctype_alpha(substr($iban, 0, 2))) {
            return false;
        }
        
        // Prüfziffern (Zeichen 3 und 4 müssen Ziffern sein)
        if (!ctype_digit(substr($iban, 2, 2))) {
            return false;
        }
        
        // Rest sollte alphanumerisch sein
        if (!ctype_alnum(substr($iban, 4))) {
            return false;
        }
        
        // Für deutsche IBANs: zusätzliche Prüfung der Länge (22 Zeichen)
        if (substr($iban, 0, 2) === 'DE' && strlen($iban) !== 22) {
            return false;
        }
        
        // Wenn Feature-Flag aktiviert, auch IBAN-Prüfsumme validieren
        if ($this->feature_flags->is_enabled('sepa_iban_checksum')) {
            return $this->validate_iban_checksum($iban);
        }
        
        return true;
    }
    
    /**
     * Validiert die Prüfsumme einer IBAN
     *
     * @param string $iban Die zu validierende IBAN
     * @return bool True, wenn gültig, sonst false
     */
    private function validate_iban_checksum($iban) {
        // IBAN umformatieren: Ländercode und Prüfziffern ans Ende verschieben
        $rearranged_iban = substr($iban, 4) . substr($iban, 0, 4);
        
        // Buchstaben durch Zahlen ersetzen (A=10, B=11, ...)
        $characters = str_split($rearranged_iban);
        $digits = '';
        
        foreach ($characters as $char) {
            if (ctype_alpha($char)) {
                $digits .= (ord(strtoupper($char)) - 55);
            } else {
                $digits .= $char;
            }
        }
        
        // Prüfsumme berechnen (Modulo 97)
        $modulo = bcmod($digits, '97');
        
        // Die Prüfsumme muss 1 ergeben
        return $modulo === '1';
    }
    
    /**
     * Validiert eine BIC
     *
     * @param string $bic Die zu validierende BIC
     * @return bool True, wenn gültig, sonst false
     */
    private function validate_bic($bic) {
        // BIC-Format prüfen: 8 oder 11 Zeichen
        if (strlen($bic) !== 8 && strlen($bic) !== 11) {
            return false;
        }
        
        // Bankcode (erste 4 Zeichen müssen Buchstaben sein)
        if (!ctype_alpha(substr($bic, 0, 4))) {
            return false;
        }
        
        // Ländercode (Zeichen 5 und 6 müssen Buchstaben sein)
        if (!ctype_alpha(substr($bic, 4, 2))) {
            return false;
        }
        
        // Orts-/Filialcode (Rest sollte alphanumerisch sein)
        if (!ctype_alnum(substr($bic, 6))) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Maskiert eine IBAN für die Speicherung
     *
     * @param string $iban Die zu maskierende IBAN
     * @return string Die maskierte IBAN
     */
    private function mask_iban($iban) {
        // Nur die letzten 4 Zeichen anzeigen
        $length = strlen($iban);
        if ($length <= 8) {
            return $iban; // Zu kurz zum maskieren
        }
        
        $visible_part = substr($iban, $length - 4, 4);
        $masked_part = str_repeat('*', $length - 8);
        $country_code = substr($iban, 0, 2);
        $check_digits = substr($iban, 2, 2);
        
        return $country_code . $check_digits . $masked_part . $visible_part;
    }
    
    /**
     * Sendet eine SEPA-Mandatsbestätigung per E-Mail
     *
     * @param WC_Order $order Die Bestellung
     * @param string $iban Die IBAN
     * @param string $mandate_reference Die Mandatsreferenz
     */
    private function send_sepa_mandate_confirmation($order, $iban, $mandate_reference) {
        $to = $order->get_billing_email();
        $subject = get_bloginfo('name') . ' - SEPA-Lastschriftmandat';
        
        // E-Mail-Inhalt erstellen
        $message = sprintf(
            'Hallo %s %s,<br><br>vielen Dank für Ihre Bestellung bei %s.<br><br>',
            $order->get_billing_first_name(),
            $order->get_billing_last_name(),
            get_bloginfo('name')
        );
        
        $message .= sprintf(
            'Sie haben uns ein SEPA-Lastschriftmandat erteilt. Hier sind die Details:<br><br>
            <strong>Mandatsreferenz:</strong> %s<br>
            <strong>IBAN:</strong> %s<br>
            <strong>Kontoinhaber:</strong> %s<br>
            <strong>Betrag:</strong> %s<br>
            <strong>Datum:</strong> %s<br><br>',
            $mandate_reference,
            $this->mask_iban($iban),
            get_post_meta($order->get_id(), '_sepa_payment_data', true)['account_holder'],
            $order->get_formatted_order_total(),
            date_i18n(get_option('date_format'), current_time('timestamp'))
        );
        
        $message .= 'Der Betrag wird in den nächsten Tagen von Ihrem Konto abgebucht.<br><br>';
        $message .= 'Mit freundlichen Grüßen<br>Ihr ' . get_bloginfo('name') . ' Team';
        
        // E-Mail-Header für HTML-E-Mail
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // E-Mail senden
        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Initiiert einen Kreditkartenzahlungsvorgang über Stripe
     *
     * @param float $amount Betrag
     * @param string $currency Währung
     * @param array $customer_info Kundeninformationen
     * @param int $order_id Bestellungs-ID
     * @return array|WP_Error Payment Intent oder Fehler
     */
    public function create_stripe_payment_intent($amount, $currency, $customer_info, $order_id = null) {
        // Prüfen, ob Währung in ISO-Format ist
        $currency = strtolower($currency);
        
        // Betrag in Cent konvertieren (für Stripe)
        $amount_in_cents = $this->format_stripe_amount($amount, $currency);
        
        // Metadaten für die Zahlung
        $metadata = array(
            'order_id' => $order_id,
            'website' => get_bloginfo('url')
        );
        
        // Kundeninformationen vorbereiten
        $customer_data = array(
            'name' => $customer_info['name'] ?? '',
            'email' => $customer_info['email'] ?? '',
            'phone' => $customer_info['phone'] ?? '',
            'address' => $customer_info['address'] ?? array()
        );
        
        // Payment Intent Daten
        $payment_intent_data = array(
            'amount' => $amount_in_cents,
            'currency' => $currency,
            'metadata' => $metadata,
            'capture_method' => 'automatic',
            'description' => $order_id ? 'Bestellung #' . $order_id : 'Zahlung bei ' . get_bloginfo('name')
        );
        
        // Aktivieren der automatischen Zahlungsmethoden, wenn Feature-Flag gesetzt
        if ($this->feature_flags->is_enabled('stripe_automatic_payment_methods')) {
            $payment_intent_data['automatic_payment_methods'] = array('enabled' => true);
        } else {
            $payment_intent_data['payment_method_types'] = array('card');
        }
        
        // SCA-Unterstützung, wenn Feature-Flag gesetzt
        if ($this->feature_flags->is_enabled('stripe_sca_support')) {
            $payment_intent_data['setup_future_usage'] = 'off_session';
            $payment_intent_data['payment_method_options'] = array(
                'card' => array(
                    'request_three_d_secure' => 'automatic'
                )
            );
        }
        
        // Stripe Zahlungsabsicht erstellen
        $response = $this->post('stripe', 'payment_intents', $payment_intent_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $payment_intent = $response['body'];
        
        // Falls es eine Bestellung gibt, speichere die Payment Intent ID
        if ($order_id) {
            update_post_meta($order_id, '_stripe_payment_intent', $payment_intent['id']);
        }
        
        return $payment_intent;
    }
    
    /**
     * Formatiert einen Betrag für Stripe (in kleinster Währungseinheit)
     *
     * @param float $amount Der Betrag
     * @param string $currency Die Währung
     * @return int Der formatierte Betrag
     */
    private function format_stripe_amount($amount, $currency) {
        // Liste der Währungen ohne Dezimalstellen
        $zero_decimal_currencies = array(
            'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 
            'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'
        );
        
        if (in_array(strtolower($currency), $zero_decimal_currencies)) {
            // Bei Währungen ohne Dezimalstellen, kein Multiplizieren
            return absint($amount);
        }
        
        // Standard: Multipliziere mit 100 für Cent-Beträge
        return absint(round($amount * 100));
    }

    /**
     * Überprüft, ob der Stripe Account gültig ist
     *
     * @return bool|WP_Error True bei gültigem Account, WP_Error bei ungültigem Account
     */
    public function check_stripe_account() {
        $response = $this->get('stripe', 'account');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return true;
    }

    /**
     * Überprüft, ob der PayPal Account gültig ist
     *
     * @return bool|WP_Error True bei gültigem Account, WP_Error bei ungültigem Account
     */
    public function check_paypal_account() {
        // Token abrufen
        $token = $this->get_paypal_oauth_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        // API-Header mit Token
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        );
        
        $response = $this->get('paypal', 'v1/identity/oauth2/userinfo', array('schema' => 'paypalv1.1'), $headers);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return true;
    }

    /**
     * Gibt die verfügbaren Zahlungsmethoden zurück
     *
     * @return array Liste der verfügbaren Zahlungsmethoden
     */
    public function get_available_payment_methods() {
        $methods = array();
        
        // Stripe
        if ($this->feature_flags->is_enabled('stripe_integration')) {
            $methods['stripe'] = array(
                'title' => 'Kreditkarte',
                'description' => 'Bezahlen Sie sicher mit Ihrer Kreditkarte.',
                'icon' => 'credit-card',
                'gateway' => 'stripe'
            );
        }
        
        // PayPal
        if ($this->feature_flags->is_enabled('paypal_integration')) {
            $methods['paypal'] = array(
                'title' => 'PayPal',
                'description' => 'Bezahlen Sie mit Ihrem PayPal-Konto.',
                'icon' => 'paypal',
                'gateway' => 'paypal'
            );
        }
        
        // SEPA
        if ($this->feature_flags->is_enabled('sepa_integration')) {
            $methods['sepa'] = array(
                'title' => 'SEPA-Lastschrift',
                'description' => 'Bezahlen Sie per SEPA-Lastschrift von Ihrem Bankkonto.',
                'icon' => 'bank',
                'gateway' => 'sepa'
            );
        }
        
        // Banküberweisung
        if ($this->feature_flags->is_enabled('bank_transfer')) {
            $methods['bank_transfer'] = array(
                'title' => 'Banküberweisung',
                'description' => 'Bezahlen Sie per Banküberweisung. Ihre Bestellung wird nach Zahlungseingang versendet.',
                'icon' => 'university',
                'gateway' => 'bank_transfer'
            );
        }
        
        return apply_filters('yprint_available_payment_methods', $methods);
    }

    /**
     * Erstellt einen Webhook für Stripe
     *
     * @param string $url Die Webhook-URL
     * @param array $events Die zu abonnierenden Ereignisse
     * @return array|WP_Error Webhook-Informationen oder Fehler
     */
    public function create_stripe_webhook($url, $events = array()) {
        // Standard-Events, falls keine angegeben
        if (empty($events)) {
            $events = array(
                'payment_intent.succeeded',
                'payment_intent.payment_failed',
                'charge.refunded'
            );
        }
        
        // Webhook erstellen
        $webhook_data = array(
            'url' => $url,
            'enabled_events' => $events,
            'description' => 'YPrint Payment Webhook (' . get_bloginfo('name') . ')'
        );
        
        $response = $this->post('stripe', 'webhook_endpoints', $webhook_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $webhook = $response['body'];
        
        // Webhook-Secret in den Optionen speichern
        if (isset($webhook['secret'])) {
            update_option('yprint_stripe_webhook_secret', $webhook['secret']);
        }
        
        return $webhook;
    }

    /**
     * Erstellt einen Webhook für PayPal
     *
     * @param string $url Die Webhook-URL
     * @param array $events Die zu abonnierenden Ereignisse
     * @return array|WP_Error Webhook-Informationen oder Fehler
     */
    public function create_paypal_webhook($url, $events = array()) {
        // Token abrufen
        $token = $this->get_paypal_oauth_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        // Standard-Events, falls keine angegeben
        if (empty($events)) {
            $events = array(
                'PAYMENT.CAPTURE.COMPLETED',
                'PAYMENT.CAPTURE.DENIED',
                'PAYMENT.CAPTURE.REFUNDED',
                'CHECKOUT.ORDER.APPROVED',
                'CHECKOUT.ORDER.COMPLETED'
            );
        }
        
        // Event-Typen für den Webhook vorbereiten
        $event_types = array();
        foreach ($events as $event) {
            $event_types[] = array('name' => $event);
        }
        
        // Webhook erstellen
        $webhook_data = array(
            'url' => $url,
            'event_types' => $event_types
        );
        
        // API-Header mit Token
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        );
        
        $response = $this->post('paypal', 'v1/notifications/webhooks', $webhook_data, $headers);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $webhook = $response['body'];
        
        // Webhook-ID in den Optionen speichern
        if (isset($webhook['id'])) {
            update_option('yprint_paypal_webhook_id', $webhook['id']);
        }
        
        return $webhook;
    }
    
    /**
     * Führt eine Rückerstattung für eine Stripe-Zahlung durch
     *
     * @param int $order_id Bestellungs-ID
     * @param float $amount Rückerstattungsbetrag (optional, falls leer wird voller Betrag erstattet)
     * @param string $reason Grund für die Rückerstattung
     * @return array|WP_Error Rückerstattungsdetails oder Fehler
     */
    public function refund_stripe_payment($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', 'Ungültige Bestellung');
        }
        
        // Charge ID finden
        $transaction_id = $order->get_transaction_id();
        if (empty($transaction_id)) {
            $payment_intent_id = get_post_meta($order->get_id(), '_stripe_payment_intent', true);
            
            if (empty($payment_intent_id)) {
                return new WP_Error('missing_transaction', 'Keine Transaktions-ID gefunden');
            }
            
            // Payment Intent abrufen, um Charge ID zu bekommen
            $response = $this->get('stripe', 'payment_intents/' . $payment_intent_id);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $payment_intent = $response['body'];
            
            if (empty($payment_intent['charges']['data'][0]['id'])) {
                return new WP_Error('missing_charge', 'Keine Charge ID gefunden');
            }
            
            $transaction_id = $payment_intent['charges']['data'][0]['id'];
        }
        
        // Rückerstattungsdaten vorbereiten
        $refund_data = array(
            'charge' => $transaction_id,
            'metadata' => array(
                'order_id' => $order_id,
                'refund_reason' => $reason
            )
        );
        
        // Betrag hinzufügen, falls angegeben
        if ($amount !== null) {
            $refund_data['amount'] = $this->format_stripe_amount($amount, $order->get_currency());
        }
        
        // Grund hinzufügen, falls angegeben
        if (!empty($reason)) {
            $refund_data['reason'] = 'requested_by_customer';
        }
        
        // Rückerstattung durchführen
        $response = $this->post('stripe', 'refunds', $refund_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $refund = $response['body'];
        
        // WooCommerce Refund erstellen
        $refund_amount = isset($refund['amount']) ? $refund['amount'] / 100 : $order->get_total();
        $refund_reason = !empty($reason) ? $reason : 'Stripe Rückerstattung';
        
        // WooCommerce-Rückerstattung erstellen
        $wc_refund = wc_create_refund(array(
            'order_id' => $order_id,
            'amount' => $refund_amount,
            'reason' => $refund_reason
        ));
        
        if (is_wp_error($wc_refund)) {
            // Fehler protokollieren, aber dennoch mit Stripe-Rückerstattung fortfahren
            $this->log('Fehler bei der WooCommerce-Rückerstattung: ' . $wc_refund->get_error_message(), 'error');
        }
        
        // Bestellung aktualisieren
        if ($refund_amount >= $order->get_total()) {
            $order->update_status('refunded', 'Vollständige Rückerstattung über Stripe: ' . $refund['id']);
        } else {
            $order->add_order_note('Teilweise Rückerstattung über Stripe: ' . $refund['id'] . ' - Betrag: ' . wc_price($refund_amount));
        }
        
        // Rückerstattungs-ID speichern
        update_post_meta($order_id, '_stripe_refund_id', $refund['id']);
        
        return array(
            'refund_id' => $refund['id'],
            'amount' => $refund_amount,
            'currency' => $order->get_currency()
        );
    }
    
    /**
     * Führt eine Rückerstattung für eine PayPal-Zahlung durch
     *
     * @param int $order_id Bestellungs-ID
     * @param float $amount Rückerstattungsbetrag (optional, falls leer wird voller Betrag erstattet)
     * @param string $reason Grund für die Rückerstattung
     * @return array|WP_Error Rückerstattungsdetails oder Fehler
     */
    public function refund_paypal_payment($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', 'Ungültige Bestellung');
        }
        
        // PayPal-Transaktions-ID finden
        $transaction_id = $order->get_transaction_id();
        if (empty($transaction_id)) {
            $transaction_id = get_post_meta($order->get_id(), '_paypal_transaction_id', true);
            
            if (empty($transaction_id)) {
                return new WP_Error('missing_transaction', 'Keine Transaktions-ID gefunden');
            }
        }
        
        // Token abrufen
        $token = $this->get_paypal_oauth_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        // API-Header mit Token
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        );
        
        // Rückerstattungsdaten vorbereiten
        $refund_data = array(
            'note_to_payer' => !empty($reason) ? $reason : 'Rückerstattung für Bestellung #' . $order_id
        );
        
        // Betrag hinzufügen, falls angegeben
        if ($amount !== null) {
            $refund_data['amount'] = array(
                'value' => number_format($amount, 2, '.', ''),
                'currency_code' => $order->get_currency()
            );
        }
        
        // Rückerstattung durchführen
        $response = $this->post('paypal', 'v2/payments/captures/' . $transaction_id . '/refund', $refund_data, $headers);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $refund = $response['body'];
        
        // WooCommerce-Rückerstattung erstellen
        $refund_amount = isset($refund['amount']['value']) ? $refund['amount']['value'] : $order->get_total();
        $refund_reason = !empty($reason) ? $reason : 'PayPal Rückerstattung';
        
        $wc_refund = wc_create_refund(array(
            'order_id' => $order_id,
            'amount' => $refund_amount,
            'reason' => $refund_reason
        ));
        
        if (is_wp_error($wc_refund)) {
            // Fehler protokollieren, aber dennoch mit PayPal-Rückerstattung fortfahren
            $this->log('Fehler bei der WooCommerce-Rückerstattung: ' . $wc_refund->get_error_message(), 'error');
        }
        
        // Bestellung aktualisieren
        if ($refund_amount >= $order->get_total()) {
            $order->update_status('refunded', 'Vollständige Rückerstattung über PayPal: ' . $refund['id']);
        } else {
            $order->add_order_note('Teilweise Rückerstattung über PayPal: ' . $refund['id'] . ' - Betrag: ' . wc_price($refund_amount));
        }
        
        // Rückerstattungs-ID speichern
        update_post_meta($order_id, '_paypal_refund_id', $refund['id']);
        
        return array(
            'refund_id' => $refund['id'],
            'amount' => $refund_amount,
            'currency' => $order->get_currency()
        );
    }
}

// Hilfsfunktion für globalen Zugriff
function yprint_api() {
    return YPrint_API::instance();
}
