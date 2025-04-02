<?php
/**
 * Feature-Flags-Verwaltungsklasse für YPrint Payment
 *
 * Ermöglicht die kontrollierte Einführung neuer Funktionen durch Feature-Flags.
 * Features können wahlweise für alle Benutzer oder für bestimmte Benutzergruppen
 * aktiviert oder deaktiviert werden.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feature-Flags-Verwaltungsklasse
 */
class YPrint_Feature_Flags {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_Feature_Flags
     */
    protected static $_instance = null;

    /**
     * Feature-Flags-Array mit Standardwerten
     *
     * @var array
     */
    private $flags = array();

    /**
     * Option-Name für die Datenbankspeicherung
     *
     * @var string
     */
    private $option_name = 'yprint_feature_flags';

    /**
     * Hauptinstanz der YPrint_Feature_Flags-Klasse
     *
     * Stellt sicher, dass nur eine Instanz der Klasse geladen oder erzeugt werden kann.
     *
     * @return YPrint_Feature_Flags - Hauptinstanz
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
        $this->set_default_flags();
        $this->load_flags();
    }

    /**
     * Setzt die Standard-Feature-Flags
     */
    private function set_default_flags() {
        $this->flags = array(
            // Stripe-bezogene Features
            'stripe_integration' => true,     // Generelle Stripe-Integration
            'stripe_sca_support' => true,     // Strong Customer Authentication (SCA) für EU-Zahlungen
            'stripe_upe_enabled' => false,    // Unified Payment Element (neues Zahlungs-UI)
            'stripe_webhooks' => true,        // Webhook-Unterstützung für Stripe
            
            // PayPal-bezogene Features
            'paypal_integration' => true,     // Generelle PayPal-Integration
            'paypal_smart_buttons' => true,   // Smart Payment Buttons für PayPal
            'paypal_webhooks' => true,        // Webhook-Unterstützung für PayPal
            
            // SEPA-bezogene Features
            'sepa_integration' => true,       // Generelle SEPA-Integration
            'sepa_instant_validation' => false, // Sofortige IBAN-Validierung
            
            // Weitere Zahlungsfeatures
            'bank_transfer' => true,          // Banküberweisung
            'klarna_integration' => false,    // Klarna-Integration (zukünftig)
            'sofort_integration' => false,    // Sofort-Integration (zukünftig)
            
            // UI-Features
            'responsive_checkout' => true,    // Responsive Checkout-Seite
            'enhanced_validation' => true,    // Verbesserte Formularvalidierung
            'address_autofill' => false,      // Adressautovervollständigung
            
            // Admin-Features
            'advanced_reporting' => false,    // Erweiterte Berichterstellung
            'transaction_logs' => true,       // Transaktionsprotokolle
            'debug_mode' => false,            // Debug-Modus
            
            // Sicherheitsfeatures
            'fraud_detection' => false,       // Betrugserkennung
            'captcha_protection' => false,    // CAPTCHA-Schutz für Checkout
            
            // Performance-Features
            'ajax_cart_updates' => true,      // AJAX-Warenkorb-Updates
            'lazy_loading' => false,          // Lazy Loading von Ressourcen
        );
    }

    /**
     * Lädt Feature-Flags aus der Datenbank
     */
    private function load_flags() {
        $saved_flags = get_option($this->option_name, array());
        
        if (!empty($saved_flags) && is_array($saved_flags)) {
            // Zusammenführen mit Standardwerten, sodass neue Flags immer verfügbar sind
            $this->flags = array_merge($this->flags, $saved_flags);
        }

        // Statisches Flag für Entwicklungsumgebung
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // In Entwicklungsumgebungen können wir bestimmte Flags immer aktivieren
            $this->flags['debug_mode'] = true;
        }

        // Möglichkeit, Flags über Konstanten zu überschreiben (z.B. in wp-config.php)
        foreach ($this->flags as $flag_name => $enabled) {
            $constant_name = 'YPRINT_FEATURE_' . strtoupper($flag_name);
            if (defined($constant_name)) {
                $this->flags[$flag_name] = (bool) constant($constant_name);
            }
        }

        // Hook nach dem Laden der Flags
        do_action('yprint_feature_flags_loaded', $this->flags);
    }

    /**
     * Speichert die aktuellen Feature-Flags in der Datenbank
     */
    public function save_flags() {
        update_option($this->option_name, $this->flags, true);
        do_action('yprint_feature_flags_saved', $this->flags);
        return true;
    }

    /**
     * Prüft, ob ein bestimmtes Feature aktiviert ist
     *
     * @param string $flag_name Name des Feature-Flags
     * @return bool
     */
    public function is_enabled($flag_name) {
        // Prüfen, ob das Flag existiert
        if (!isset($this->flags[$flag_name])) {
            // Wenn das Flag nicht existiert, standardmäßig deaktiviert
            return false;
        }
        
        // Benutzerspezifische Überprüfung über Filter ermöglichen
        return apply_filters('yprint_is_feature_enabled', $this->flags[$flag_name], $flag_name);
    }

    /**
     * Aktiviert ein Feature-Flag
     *
     * @param string $flag_name Name des Feature-Flags
     * @return bool Erfolg der Operation
     */
    public function enable($flag_name) {
        // Prüfen, ob das Flag existiert
        if (!isset($this->flags[$flag_name])) {
            return false;
        }
        
        // Flag aktivieren
        $this->flags[$flag_name] = true;
        
        // Änderungen speichern
        $this->save_flags();
        
        // Aktion auslösen
        do_action('yprint_feature_enabled', $flag_name);
        
        return true;
    }

    /**
     * Deaktiviert ein Feature-Flag
     *
     * @param string $flag_name Name des Feature-Flags
     * @return bool Erfolg der Operation
     */
    public function disable($flag_name) {
        // Prüfen, ob das Flag existiert
        if (!isset($this->flags[$flag_name])) {
            return false;
        }
        
        // Flag deaktivieren
        $this->flags[$flag_name] = false;
        
        // Änderungen speichern
        $this->save_flags();
        
        // Aktion auslösen
        do_action('yprint_feature_disabled', $flag_name);
        
        return true;
    }

    /**
     * Gibt alle verfügbaren Feature-Flags zurück
     *
     * @return array
     */
    public function get_all_flags() {
        return $this->flags;
    }

    /**
     * Prüft, ob mehrere Features alle aktiviert sind
     *
     * @param array $flag_names Array mit Flag-Namen
     * @return bool
     */
    public function are_all_enabled(array $flag_names) {
        foreach ($flag_names as $flag_name) {
            if (!$this->is_enabled($flag_name)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Prüft, ob irgendein Feature aus einer Liste aktiviert ist
     *
     * @param array $flag_names Array mit Flag-Namen
     * @return bool
     */
    public function is_any_enabled(array $flag_names) {
        foreach ($flag_names as $flag_name) {
            if ($this->is_enabled($flag_name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fügt ein neues Feature-Flag hinzu
     *
     * @param string $flag_name Name des Feature-Flags
     * @param bool $enabled Standard-Status (aktiviert/deaktiviert)
     * @return bool Erfolg der Operation
     */
    public function add_flag($flag_name, $enabled = false) {
        // Prüfen, ob das Flag bereits existiert
        if (isset($this->flags[$flag_name])) {
            return false;
        }
        
        // Neues Flag hinzufügen
        $this->flags[$flag_name] = (bool) $enabled;
        
        // Änderungen speichern
        $this->save_flags();
        
        return true;
    }

    /**
     * Entfernt ein Feature-Flag
     *
     * @param string $flag_name Name des Feature-Flags
     * @return bool Erfolg der Operation
     */
    public function remove_flag($flag_name) {
        // Prüfen, ob das Flag existiert
        if (!isset($this->flags[$flag_name])) {
            return false;
        }
        
        // Flag entfernen
        unset($this->flags[$flag_name]);
        
        // Änderungen speichern
        $this->save_flags();
        
        return true;
    }

    /**
     * Setzt alle Feature-Flags auf ihre Standardwerte zurück
     *
     * @return bool Erfolg der Operation
     */
    public function reset_to_defaults() {
        $this->set_default_flags();
        $this->save_flags();
        
        do_action('yprint_feature_flags_reset');
        
        return true;
    }

    /**
     * Prüft, ob ein Feature für eine bestimmte Benutzerrolle aktiviert ist
     * 
     * @param string $flag_name Name des Feature-Flags
     * @param string $role_name Name der zu prüfenden Benutzerrolle
     * @return bool
     */
    public function is_enabled_for_role($flag_name, $role_name) {
        // Basis Feature-Flag-Prüfung
        if (!$this->is_enabled($flag_name)) {
            return false;
        }
        
        // Rollenspezifische Überschreibungen holen
        $role_overrides = get_option('yprint_feature_flags_roles', array());
        
        // Prüfen, ob Überschreibungen für diese Rolle und dieses Flag existieren
        if (isset($role_overrides[$role_name]) && isset($role_overrides[$role_name][$flag_name])) {
            return (bool) $role_overrides[$role_name][$flag_name];
        }
        
        // Standardmäßig auf globale Einstellung zurückfallen
        return $this->is_enabled($flag_name);
    }

    /**
     * Prüft, ob ein Feature für den aktuellen Benutzer aktiviert ist
     * 
     * @param string $flag_name Name des Feature-Flags
     * @return bool
     */
    public function is_enabled_for_current_user($flag_name) {
        // Basis Feature-Flag-Prüfung
        if (!$this->is_enabled($flag_name)) {
            return false;
        }
        
        // Aktuelle Benutzer-ID holen
        $user_id = get_current_user_id();
        
        // Für nicht eingeloggte Benutzer die globale Einstellung verwenden
        if ($user_id === 0) {
            return $this->is_enabled($flag_name);
        }
        
        // Benutzerspezifische Überschreibungen holen
        $user_overrides = get_user_meta($user_id, 'yprint_feature_flags', true);
        
        // Prüfen, ob Überschreibungen für dieses Flag existieren
        if (is_array($user_overrides) && isset($user_overrides[$flag_name])) {
            return (bool) $user_overrides[$flag_name];
        }
        
        // Wenn keine benutzerspezifische Überschreibung, Rollenprüfung
        $user = get_userdata($user_id);
        if ($user && !empty($user->roles)) {
            foreach ($user->roles as $role) {
                // Wenn das Feature für eine der Rollen des Benutzers aktiviert ist, aktivieren
                if ($this->is_enabled_for_role($flag_name, $role)) {
                    return true;
                }
            }
        }
        
        // Standardmäßig auf globale Einstellung zurückfallen
        return $this->is_enabled($flag_name);
    }

    /**
     * Führt Code aus, wenn ein Feature aktiviert ist
     * 
     * @param string $flag_name Name des Feature-Flags
     * @param callable $callback Auszuführende Callback-Funktion, wenn das Feature aktiviert ist
     * @param callable $fallback Optional: Auszuführende Callback-Funktion, wenn das Feature deaktiviert ist
     * @return mixed Rückgabewert des ausgeführten Callbacks oder null
     */
    public function when_enabled($flag_name, $callback, $fallback = null) {
        if ($this->is_enabled($flag_name)) {
            return call_user_func($callback);
        } elseif ($fallback !== null) {
            return call_user_func($fallback);
        }
        return null;
    }
}

// Hilfsfunktion für globalen Zugriff
function yprint_feature_flags() {
    return YPrint_Feature_Flags::instance();
}