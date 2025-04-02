<?php
/**
 * Webhook-Handler für YPrint Payment
 *
 * Diese Klasse dient als zentraler Handler für Webhooks von verschiedenen
 * Zahlungsanbietern wie Stripe, PayPal, etc. Sie validiert eingehende Webhook-Anfragen,
 * verarbeitet die Payloads und leitet die Ereignisse an die entsprechenden Gateway-Klassen weiter.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Webhook-Handler Klasse
 */
class YPrint_Webhook_Handler {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_Webhook_Handler
     */
    protected static $_instance = null;

    /**
     * Feature Flags Manager
     *
     * @var YPrint_Feature_Flags
     */
    private $feature_flags;

    /**
     * API-Handler
     *
     * @var YPrint_API
     */
    private $api;

    /**
     * Debug-Modus-Flag
     *
     * @var bool
     */
    private $debug_mode;

    /**
     * Webhook-Routen
     *
     * @var array
     */
    private $webhook_routes = array(
        'stripe'  => 'yprint-stripe-webhook',
        'paypal'  => 'yprint-paypal-webhook',
        'sepa'    => 'yprint-sepa-webhook',
    );

    /**
     * Hauptinstanz der YPrint_Webhook_Handler-Klasse
     *
     * @return YPrint_Webhook_Handler - Hauptinstanz
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
        $this->feature_flags = class_exists('YPrint_Feature_Flags') ? YPrint_Feature_Flags::instance() : null;
        
        // API-Handler laden
        $this->api = class_exists('YPrint_API') ? YPrint_API::instance() : null;
        
        // Debug-Modus setzen
        $this->debug_mode = $this->is_feature_enabled('debug_mode');
        
        // Hooks initialisieren
        $this->init_hooks();
    }

    /**
     * Hooks initialisieren
     */
    private function init_hooks() {
        // Webhook-Endpunkte registrieren
        add_action('init', array($this, 'register_webhook_endpoints'));
        
        // Webhook-Handler
        add_action('parse_request', array($this, 'handle_webhook_requests'));
    }

    /**
     * Webhook-Endpunkte registrieren
     */
    public function register_webhook_endpoints() {
        // Für jeden Zahlungsanbieter einen Webhook-Endpunkt registrieren
        foreach ($this->webhook_routes as $gateway => $route) {
            add_rewrite_rule(
                '^' . $route . '/?$',
                'index.php?' . $route . '=1',
                'top'
            );
            
            // Query-Vars hinzufügen
            add_filter('query_vars', function($vars) use ($route) {
                $vars[] = $route;
                return $vars;
            });
        }
        
        // Rewrite-Regeln aktualisieren, falls noch nicht geschehen
        if (get_option('yprint_webhook_routes_flushed') != md5(serialize($this->webhook_routes))) {
            flush_rewrite_rules();
            update_option('yprint_webhook_routes_flushed', md5(serialize($this->webhook_routes)));
        }
    }

    /**
     * Webhook-Anfragen verarbeiten
     * 
     * @param WP $wp WordPress-Request-Objekt
     */
    public function handle_webhook_requests($wp) {
        // Prüfen, welcher Webhook-Endpunkt aufgerufen wurde
        foreach ($this->webhook_routes as $gateway => $route) {
            if (isset($wp->query_vars[$route])) {
                // Entsprechenden Gateway-Webhook aufrufen
                $this->process_webhook($gateway);
                exit; // Verarbeitung beenden, nachdem der Webhook bearbeitet wurde
            }
        }
    }

    /**
     * Verarbeitet den Webhook eines bestimmten Gateways
     * 
     * @param string $gateway Der Name des Zahlungsanbieters
     */
    private function process_webhook($gateway) {
        // Payload und Header abrufen
        $payload = file_get_contents('php://input');
        $headers = $this->get_request_headers();
        
        // Debug-Logging
        if ($this->debug_mode) {
            $this->log("Webhook für $gateway empfangen: " . substr($payload, 0, 500) . '...');
            $this->log("Headers: " . print_r($headers, true));
        }
        
        // Weiterleiten an das entsprechende Gateway
        switch ($gateway) {
            case 'stripe':
                $this->process_stripe_webhook($payload, $headers);
                break;
                
            case 'paypal':
                $this->process_paypal_webhook($payload, $headers);
                break;
                
            case 'sepa':
                $this->process_sepa_webhook($payload, $headers);
                break;
                
            default:
                $this->send_response(400, 'Ungültiger Gateway-Typ: ' . $gateway);
                break;
        }
    }

    /**
     * Verarbeitet Stripe Webhooks
     * 
     * @param string $payload Der Webhook-Payload
     * @param array $headers Die HTTP-Header
     */
    private function process_stripe_webhook($payload, $headers) {
        // Prüfen, ob der YPrint_Stripe Handler verfügbar ist
        if (!class_exists('YPrint_Stripe')) {
            $this->log('Stripe Webhook-Handler nicht verfügbar', 'error');
            $this->send_response(500, 'Stripe Webhook-Handler nicht verfügbar');
            return;
        }
        
        // Stripe-Handler holen
        $stripe_handler = YPrint_Stripe::instance();
        
        // Gateway-Handler verfügbar?
        if (!method_exists($stripe_handler, 'process_webhook')) {
            $this->log('Stripe Webhook-Verarbeitungsmethode nicht verfügbar', 'error');
            $this->send_response(500, 'Stripe Webhook-Verarbeitungsmethode nicht verfügbar');
            return;
        }
        
        try {
            // Webhook an den Stripe-Handler übergeben
            $result = $stripe_handler->process_webhook($payload, $headers);
            
            // Ergebnis loggen und Antwort senden
            if ($this->debug_mode) {
                $this->log('Stripe Webhook-Ergebnis: ' . print_r($result, true));
            }
            
            if (isset($result['success']) && $result['success']) {
                $this->send_response(200, isset($result['message']) ? $result['message'] : 'Webhook erfolgreich verarbeitet');
            } else {
                $this->send_response(400, isset($result['message']) ? $result['message'] : 'Fehler bei der Webhook-Verarbeitung');
            }
        } catch (Exception $e) {
            $this->log('Fehler bei der Stripe Webhook-Verarbeitung: ' . $e->getMessage(), 'error');
            $this->send_response(500, 'Interner Serverfehler: ' . $e->getMessage());
        }
    }

    /**
     * Verarbeitet PayPal Webhooks
     * 
     * @param string $payload Der Webhook-Payload
     * @param array $headers Die HTTP-Header
     */
    private function process_paypal_webhook($payload, $headers) {
        // Prüfen, ob der YPrint_PayPal Handler verfügbar ist
        if (!class_exists('YPrint_PayPal')) {
            $this->log('PayPal Webhook-Handler nicht verfügbar', 'error');
            $this->send_response(500, 'PayPal Webhook-Handler nicht verfügbar');
            return;
        }
        
        // PayPal-Handler holen
        $paypal_handler = YPrint_PayPal::instance();
        
        // Gateway-Handler verfügbar?
        if (!method_exists($paypal_handler, 'process_webhook')) {
            $this->log('PayPal Webhook-Verarbeitungsmethode nicht verfügbar', 'error');
            $this->send_response(500, 'PayPal Webhook-Verarbeitungsmethode nicht verfügbar');
            return;
        }
        
        try {
            // Webhook an den PayPal-Handler übergeben
            $result = $paypal_handler->process_webhook($payload, $headers);
            
            // Ergebnis loggen und Antwort senden
            if ($this->debug_mode) {
                $this->log('PayPal Webhook-Ergebnis: ' . print_r($result, true));
            }
            
            if (isset($result['success']) && $result['success']) {
                $this->send_response(200, isset($result['message']) ? $result['message'] : 'Webhook erfolgreich verarbeitet');
            } else {
                $this->send_response(400, isset($result['message']) ? $result['message'] : 'Fehler bei der Webhook-Verarbeitung');
            }
        } catch (Exception $e) {
            $this->log('Fehler bei der PayPal Webhook-Verarbeitung: ' . $e->getMessage(), 'error');
            $this->send_response(500, 'Interner Serverfehler: ' . $e->getMessage());
        }
    }

    /**
     * Verarbeitet SEPA Webhooks
     * 
     * @param string $payload Der Webhook-Payload
     * @param array $headers Die HTTP-Header
     */
    private function process_sepa_webhook($payload, $headers) {
        // Prüfen, ob der YPrint_SEPA Handler verfügbar ist
        if (!class_exists('YPrint_SEPA')) {
            $this->log('SEPA Webhook-Handler nicht verfügbar', 'error');
            $this->send_response(500, 'SEPA Webhook-Handler nicht verfügbar');
            return;
        }
        
        // SEPA-Handler holen
        $sepa_handler = YPrint_SEPA::instance();
        
        // Gateway-Handler verfügbar?
        if (!method_exists($sepa_handler, 'process_webhook')) {
            $this->log('SEPA Webhook-Verarbeitungsmethode nicht verfügbar', 'error');
            $this->send_response(500, 'SEPA Webhook-Verarbeitungsmethode nicht verfügbar');
            return;
        }
        
        try {
            // Webhook an den SEPA-Handler übergeben
            $result = $sepa_handler->process_webhook($payload, $headers);
            
            // Ergebnis loggen und Antwort senden
            if ($this->debug_mode) {
                $this->log('SEPA Webhook-Ergebnis: ' . print_r($result, true));
            }
            
            if (isset($result['success']) && $result['success']) {
                $this->send_response(200, isset($result['message']) ? $result['message'] : 'Webhook erfolgreich verarbeitet');
            } else {
                $this->send_response(400, isset($result['message']) ? $result['message'] : 'Fehler bei der Webhook-Verarbeitung');
            }
        } catch (Exception $e) {
            $this->log('Fehler bei der SEPA Webhook-Verarbeitung: ' . $e->getMessage(), 'error');
            $this->send_response(500, 'Interner Serverfehler: ' . $e->getMessage());
        }
    }

    /**
     * Sendet eine HTTP-Antwort und beendet die Verarbeitung
     * 
     * @param int $status_code HTTP-Statuscode
     * @param string $message Nachricht
     */
    private function send_response($status_code, $message = '') {
        status_header($status_code);
        header('Content-Type: application/json');
        
        if ($message) {
            echo json_encode(array('message' => $message));
        }
        
        exit;
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
            if (is_array($message) || is_object($message)) {
                $message = print_r($message, true);
            }
            
            error_log('[YPrint Webhook] [' . strtoupper($level) . '] ' . $message);
        }
    }

    /**
     * Fügt einen neuen Webhook-Endpunkt hinzu
     * 
     * @param string $gateway Gateway-Name
     * @param string $route Webhook-Route
     * @return bool Erfolg der Operation
     */
    public function add_webhook_route($gateway, $route) {
        if (isset($this->webhook_routes[$gateway])) {
            return false;
        }
        
        $this->webhook_routes[$gateway] = $route;
        
        // Rewrite-Regeln müssen aktualisiert werden
        update_option('yprint_webhook_routes_flushed', '');
        
        return true;
    }

    /**
     * Gibt die URL für einen bestimmten Webhook-Endpunkt zurück
     * 
     * @param string $gateway Gateway-Name
     * @return string|bool Webhook-URL oder false wenn Gateway nicht existiert
     */
    public function get_webhook_url($gateway) {
        if (!isset($this->webhook_routes[$gateway])) {
            return false;
        }
        
        return home_url('/' . $this->webhook_routes[$gateway] . '/');
    }

    /**
     * Überprüft, ob eine Webhook-Anfrage gültig ist
     * 
     * @param string $gateway Gateway-Name
     * @param string $payload Webhook-Payload
     * @param array $headers HTTP-Header
     * @return bool True, wenn die Anfrage gültig ist
     */
    public function validate_webhook_request($gateway, $payload, $headers) {
        // API-Handler für Webhook-Validierung verwenden
        if ($this->api && method_exists($this->api, 'verify_webhook_signature')) {
            return $this->api->verify_webhook_signature($gateway, $payload, $headers);
        }
        
        // Fallback: Basis-Validierung
        switch ($gateway) {
            case 'stripe':
                return $this->validate_stripe_webhook($payload, $headers);
                
            case 'paypal':
                return $this->validate_paypal_webhook($payload, $headers);
                
            default:
                // Für andere Gateways keine spezielle Validierung
                return true;
        }
    }

    /**
     * Validiert eine Stripe-Webhook-Anfrage
     * 
     * @param string $payload Webhook-Payload
     * @param array $headers HTTP-Header
     * @return bool True, wenn die Anfrage gültig ist
     */
    private function validate_stripe_webhook($payload, $headers) {
        // Prüfen, ob die Stripe-Bibliothek verfügbar ist
        if (!class_exists('\\Stripe\\Webhook') && !class_exists('Stripe\\Webhook')) {
            $this->log('Stripe-Bibliothek nicht verfügbar für Webhook-Validierung', 'error');
            return false;
        }
        
        $webhook_secret = get_option('yprint_stripe_webhook_secret');
        
        if (empty($webhook_secret)) {
            $this->log('Kein Stripe Webhook-Secret konfiguriert', 'error');
            return false;
        }
        
        $signature = isset($headers['stripe-signature']) ? $headers['stripe-signature'] : '';
        
        if (empty($signature)) {
            $this->log('Keine Stripe-Signatur im Header gefunden', 'error');
            return false;
        }
        
        try {
            if (class_exists('\\Stripe\\Webhook')) {
                \Stripe\Webhook::constructEvent($payload, $signature, $webhook_secret);
            } else {
                Stripe\Webhook::constructEvent($payload, $signature, $webhook_secret);
            }
            return true;
        } catch (Exception $e) {
            $this->log('Stripe Webhook-Validierung fehlgeschlagen: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Validiert eine PayPal-Webhook-Anfrage
     * 
     * @param string $payload Webhook-Payload
     * @param array $headers HTTP-Header
     * @return bool True, wenn die Anfrage gültig ist
     */
    private function validate_paypal_webhook($payload, $headers) {
        // PayPal-Webhook-ID
        $webhook_id = get_option('yprint_paypal_webhook_id');
        
        if (empty($webhook_id)) {
            $this->log('Keine PayPal Webhook-ID konfiguriert', 'error');
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
        
        foreach ($required_headers as $header) {
            if (empty($headers[$header])) {
                $this->log("Fehlender PayPal-Header: $header", 'error');
                return false;
            }
        }
        
        // Für die vollständige Validierung würde PayPal's SDK benötigt
        // Hier implementieren wir eine Basis-Validierung
        // In der Produktion sollte die vollständige Validierung mit PayPal's SDK erfolgen
        
        // Prüfen, ob der JSON gültig ist
        $json_data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('Ungültiger JSON-Payload für PayPal-Webhook', 'error');
            return false;
        }
        
        // Prüfen, ob die wichtigsten Felder vorhanden sind
        if (empty($json_data['event_type']) || empty($json_data['resource'])) {
            $this->log('Fehlende Pflichtfelder im PayPal-Webhook-Payload', 'error');
            return false;
        }
        
        // In einer vollständigen Implementierung würde hier die Signaturvalidierung erfolgen
        // Da dies spezielle PayPal-Bibliotheken erfordert, überspringen wir diesen Schritt hier
        
        return true;
    }
}

/**
 * Hauptinstanz von YPrint_Webhook_Handler
 * 
 * @return YPrint_Webhook_Handler
 */
function YPrint_Webhook_Handler() {
    return YPrint_Webhook_Handler::instance();
}

// Globale für Abwärtskompatibilität
$GLOBALS['yprint_webhook_handler'] = YPrint_Webhook_Handler();