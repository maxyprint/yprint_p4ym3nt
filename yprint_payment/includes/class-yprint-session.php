<?php
/**
 * Session-Manager für YPrint Payment
 *
 * Diese Klasse verwaltet Session-Daten für das YPrint Payment Plugin und dient
 * als zentrale Schnittstelle für die Speicherung und den Abruf von Checkout-Daten,
 * Zahlungsinformationen und temporären Bestelldaten.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Session-Manager Klasse
 */
class YPrint_Session {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_Session
     */
    protected static $_instance = null;

    /**
     * Session-Prefix für alle gespeicherten Daten
     *
     * @var string
     */
    private $prefix = 'yprint_';

    /**
     * Sitzungsdauer in Sekunden
     *
     * @var int
     */
    private $session_expiry = 43200; // 12 Stunden

    /**
     * Lokaler Cache für Session-Daten
     *
     * @var array
     */
    private $data = array();

    /**
     * Flag, ob eine Session bereits initialisiert wurde
     *
     * @var bool
     */
    private $initialized = false;

    /**
     * Hauptinstanz der YPrint_Session-Klasse
     *
     * Stellt sicher, dass nur eine Instanz der Klasse geladen wird.
     *
     * @return YPrint_Session - Hauptinstanz
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
        // Initialisierung der Session
        add_action('wp_loaded', array($this, 'init'), 20);
        
        // Session-Cleanup, wenn der Benutzer sich abmeldet
        add_action('wp_logout', array($this, 'destroy_session'));
        
        // Automatisches Speichern bei Seitenende
        add_action('shutdown', array($this, 'save_data'), 20);
        
        // Bereinigung alter Sessions
        add_action('wp_scheduled_auto_clean', array($this, 'cleanup_expired_sessions'));
        
        // Registrieren des Cronjobs, falls noch nicht vorhanden
        if (!wp_next_scheduled('wp_scheduled_auto_clean')) {
            wp_schedule_event(time(), 'daily', 'wp_scheduled_auto_clean');
        }
    }

    /**
     * Initialisiert die Session
     */
    public function init() {
        if ($this->initialized) {
            return;
        }
        
        $this->initialized = true;
        
        // Session-Daten laden
        $this->load_session();
    }

    /**
     * Lädt die Session-Daten
     */
    private function load_session() {
        // Wenn WooCommerce aktiv ist, nutze deren Session-System
        if (function_exists('WC') && WC()->session) {
            $this->data = WC()->session->get($this->prefix . 'data', array());
            return;
        }
        
        // Fallback: Laden der Daten aus der Datenbank für eingeloggte Benutzer
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $this->data = get_user_meta($user_id, $this->prefix . 'session_data', true);
            if (!is_array($this->data)) {
                $this->data = array();
            }
            return;
        }
        
        // Fallback: Cookies für nicht eingeloggte Benutzer
        $cookie_name = $this->prefix . 'session';
        
        if (isset($_COOKIE[$cookie_name])) {
            $session_id = sanitize_text_field($_COOKIE[$cookie_name]);
            $transient_name = $this->prefix . 'session_' . $session_id;
            $data = get_transient($transient_name);
            
            if (is_array($data)) {
                $this->data = $data;
            }
        } 
        
        // Wenn keine Session gefunden oder abgelaufen, neue Session erstellen
        if (empty($this->data)) {
            $this->data = array();
            $session_id = $this->generate_session_id();
            $transient_name = $this->prefix . 'session_' . $session_id;
            
            // Cookie für 12 Stunden setzen (gleiche Zeit wie Session-Dauer)
            setcookie($cookie_name, $session_id, time() + $this->session_expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            
            // Leere Session in Transient speichern
            set_transient($transient_name, $this->data, $this->session_expiry);
        }
    }

    /**
     * Generiert eine eindeutige Session-ID
     *
     * @return string
     */
    private function generate_session_id() {
        return md5(uniqid() . time() . rand(1000, 9999));
    }

    /**
     * Speichert die Session-Daten
     */
    public function save_data() {
        if (!$this->initialized) {
            return;
        }
        
        // Wenn WooCommerce aktiv ist, nutze deren Session-System
        if (function_exists('WC') && WC()->session) {
            WC()->session->set($this->prefix . 'data', $this->data);
            return;
        }
        
        // Speichern für eingeloggte Benutzer
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            update_user_meta($user_id, $this->prefix . 'session_data', $this->data);
            return;
        }
        
        // Fallback: Cookies für nicht eingeloggte Benutzer
        $cookie_name = $this->prefix . 'session';
        
        if (isset($_COOKIE[$cookie_name])) {
            $session_id = sanitize_text_field($_COOKIE[$cookie_name]);
            $transient_name = $this->prefix . 'session_' . $session_id;
            
            // Aktualisiere die Daten im Transient
            set_transient($transient_name, $this->data, $this->session_expiry);
        }
    }

    /**
     * Holt einen Wert aus der Session
     *
     * @param string $key Der Schlüssel des Werts
     * @param mixed $default Standardwert, falls kein Wert gefunden wird
     * @return mixed
     */
    public function get($key, $default = null) {
        // Falls WooCommerce-Session spezifischer Schlüssel
        if (function_exists('WC') && WC()->session && method_exists(WC()->session, 'get')) {
            $wc_value = WC()->session->get($key);
            if ($wc_value !== null) {
                return $wc_value;
            }
        }
        
        // Eigenen Datenspeicher durchsuchen
        if (isset($this->data[$key])) {
            return $this->data[$key];
        }
        
        return $default;
    }

    /**
     * Speichert einen Wert in der Session
     *
     * @param string $key Der Schlüssel, unter dem der Wert gespeichert wird
     * @param mixed $value Der zu speichernde Wert
     * @return YPrint_Session Zur Verkettung
     */
    public function set($key, $value) {
        $this->data[$key] = $value;
        
        // Für wichtige Daten sofort speichern
        if (in_array($key, array('checkout_state', 'temp_order_id', 'payment_intent'))) {
            $this->save_data();
        }
        
        // Zusätzlich in WooCommerce-Session speichern für bessere Interoperabilität
        if (function_exists('WC') && WC()->session && method_exists(WC()->session, 'set')) {
            WC()->session->set($key, $value);
        }
        
        return $this;
    }

    /**
     * Entfernt einen Wert aus der Session
     *
     * @param string $key Der Schlüssel des zu entfernenden Werts
     * @return YPrint_Session Zur Verkettung
     */
    public function remove($key) {
        if (isset($this->data[$key])) {
            unset($this->data[$key]);
        }
        
        // Auch aus WooCommerce-Session entfernen
        if (function_exists('WC') && WC()->session && method_exists(WC()->session, 'set')) {
            WC()->session->set($key, null);
        }
        
        return $this;
    }

    /**
     * Gibt alle Session-Daten zurück
     *
     * @return array
     */
    public function get_all() {
        return $this->data;
    }

    /**
     * Löscht alle Session-Daten
     *
     * @return YPrint_Session Zur Verkettung
     */
    public function clear() {
        $this->data = array();
        
        // Auch WooCommerce-Session leeren
        if (function_exists('WC') && WC()->session && method_exists(WC()->session, 'set')) {
            WC()->session->set($this->prefix . 'data', array());
        }
        
        return $this;
    }

    /**
     * Zerstört die aktuelle Session
     */
    public function destroy_session() {
        // Daten löschen
        $this->clear();
        
        // Cookie löschen
        $cookie_name = $this->prefix . 'session';
        if (isset($_COOKIE[$cookie_name])) {
            $session_id = sanitize_text_field($_COOKIE[$cookie_name]);
            $transient_name = $this->prefix . 'session_' . $session_id;
            
            // Transient löschen
            delete_transient($transient_name);
            
            // Cookie löschen
            setcookie($cookie_name, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }
        
        // Benutzer-Meta löschen, wenn eingeloggt
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            delete_user_meta($user_id, $this->prefix . 'session_data');
        }
    }

    /**
     * Bereinigt abgelaufene Sessions
     */
    public function cleanup_expired_sessions() {
        global $wpdb;
        
        // Lösche abgelaufene Transients
        $time = time();
        $expired = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM $wpdb->options 
                WHERE option_name LIKE %s 
                AND option_value < %d",
                '_transient_timeout_' . $this->prefix . 'session_%',
                $time
            )
        );
        
        if (!empty($expired)) {
            foreach ($expired as $transient) {
                $session_id = str_replace('_transient_timeout_' . $this->prefix . 'session_', '', $transient);
                delete_transient($this->prefix . 'session_' . $session_id);
            }
        }
        
        // Optional: Lösche alte Benutzerdaten, die älter als X Tage sind
        // Implementierung je nach Anforderungen
    }

    /**
     * Holt einen Wert aus dem Checkout-Status
     *
     * @param string $section Abschnitt (shippingAddress, paymentMethod, etc.)
     * @param string $key Schlüssel im Abschnitt (optional)
     * @param mixed $default Standardwert
     * @return mixed
     */
    public function get_checkout_state($section = null, $key = null, $default = null) {
        $checkout_state = $this->get('checkout_state', array());
        
        if ($section === null) {
            return $checkout_state;
        }
        
        if (!isset($checkout_state[$section])) {
            return $default;
        }
        
        if ($key === null) {
            return $checkout_state[$section];
        }
        
        return isset($checkout_state[$section][$key]) ? $checkout_state[$section][$key] : $default;
    }

    /**
     * Aktualisiert einen Wert im Checkout-Status
     *
     * @param string $section Abschnitt (shippingAddress, paymentMethod, etc.)
     * @param array $data Zu aktualisierende Daten
     * @return YPrint_Session Zur Verkettung
     */
    public function update_checkout_state($section, $data) {
        $checkout_state = $this->get('checkout_state', array());
        
        if (!isset($checkout_state[$section])) {
            $checkout_state[$section] = array();
        }
        
        $checkout_state[$section] = array_merge($checkout_state[$section], $data);
        
        $this->set('checkout_state', $checkout_state);
        
        return $this;
    }

    /**
     * Speichert die Zahlungsmethode in der Session
     *
     * @param string $method Zahlungsmethode
     * @return YPrint_Session Zur Verkettung
     */
    public function set_payment_method($method) {
        return $this->update_checkout_state('paymentMethod', array(
            'method' => $method,
            'timestamp' => time()
        ));
    }

    /**
     * Holt die aktuell gewählte Zahlungsmethode
     *
     * @return string|null
     */
    public function get_payment_method() {
        return $this->get_checkout_state('paymentMethod', 'method');
    }

    /**
     * Speichert eine temporäre Bestellungs-ID
     *
     * @param int $order_id Bestellungs-ID
     * @return YPrint_Session Zur Verkettung
     */
    public function set_temp_order_id($order_id) {
        return $this->set('temp_order_id', $order_id);
    }

    /**
     * Holt die temporäre Bestellungs-ID
     *
     * @return int|null
     */
    public function get_temp_order_id() {
        return $this->get('temp_order_id');
    }

    /**
     * Speichert Zahlungsinformationen für einen bestimmten Gateway
     *
     * @param string $gateway Gateway-Name (stripe, paypal, etc.)
     * @param array $data Zahlungsdaten
     * @return YPrint_Session Zur Verkettung
     */
    public function set_payment_data($gateway, $data) {
        return $this->set('payment_' . $gateway, $data);
    }

    /**
     * Holt Zahlungsinformationen für einen bestimmten Gateway
     *
     * @param string $gateway Gateway-Name (stripe, paypal, etc.)
     * @return array|null
     */
    public function get_payment_data($gateway) {
        return $this->get('payment_' . $gateway, array());
    }

    /**
     * Speichert den ausgewählten Versandslot
     *
     * @param string $slot Slot-Name
     * @return YPrint_Session Zur Verkettung
     */
    public function set_shipping_slot($slot) {
        return $this->set('shipping_slot', $slot);
    }

    /**
     * Holt den ausgewählten Versandslot
     *
     * @return string|null
     */
    public function get_shipping_slot() {
        return $this->get('shipping_slot');
    }

    /**
     * Speichert einen erfolgreichen Zahlungsstatus
     *
     * @param string $gateway Gateway-Name
     * @param string $transaction_id Transaktions-ID
     * @param string $status Status der Zahlung
     * @return YPrint_Session Zur Verkettung
     */
    public function set_payment_complete($gateway, $transaction_id, $status = 'completed') {
        return $this->set('payment_status', array(
            'gateway' => $gateway,
            'transaction_id' => $transaction_id,
            'status' => $status,
            'timestamp' => time()
        ));
    }

    /**
     * Gibt zurück, ob eine Zahlung erfolgreich war
     *
     * @return bool
     */
    public function is_payment_complete() {
        $payment_status = $this->get('payment_status', array());
        return !empty($payment_status) && isset($payment_status['status']) && $payment_status['status'] === 'completed';
    }

    /**
     * Speichert eine Fehlermeldung
     *
     * @param string $code Fehlercode
     * @param string $message Fehlermeldung
     * @param string $context Kontext des Fehlers
     * @return YPrint_Session Zur Verkettung
     */
    public function set_error($code, $message, $context = '') {
        return $this->set('last_error', array(
            'code' => $code,
            'message' => $message,
            'context' => $context,
            'timestamp' => time()
        ));
    }

    /**
     * Holt die letzte Fehlermeldung
     *
     * @return array|null
     */
    public function get_last_error() {
        return $this->get('last_error');
    }

    /**
     * Löscht die letzte Fehlermeldung
     *
     * @return YPrint_Session Zur Verkettung
     */
    public function clear_last_error() {
        return $this->remove('last_error');
    }
}

// Hilfsfunktion für globalen Zugriff
function yprint_session() {
    return YPrint_Session::instance();
}