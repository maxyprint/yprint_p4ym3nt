<?php
/**
 * SEPA-Zahlungsintegration für YPrint Payment
 *
 * Diese Klasse implementiert die Integration mit SEPA-Lastschrift als Zahlungsdienstleister.
 * Sie enthält Methoden für die Zahlungsinitialisierung, Validierung von IBAN/BIC,
 * Mandate-Erstellung und Zahlungsverarbeitung.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEPA Zahlungs-Gateway Klasse
 */
class YPrint_SEPA {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_SEPA
     */
    protected static $_instance = null;

    /**
     * API-Schlüssel (falls erforderlich)
     *
     * @var string
     */
    private $api_key;

    /**
     * API-Secret (falls erforderlich)
     *
     * @var string
     */
    private $api_secret;

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
     * Hauptinstanz der YPrint_SEPA-Klasse
     *
     * @return YPrint_SEPA - Hauptinstanz
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
        // AJAX Hooks für SEPA-Zahlungsprozesse
        add_action('wp_ajax_yprint_process_sepa_payment', array($this, 'ajax_process_sepa_payment'));
        add_action('wp_ajax_nopriv_yprint_process_sepa_payment', array($this, 'ajax_process_sepa_payment'));
        
        // AJAX Hooks für IBAN-Validierung
        add_action('wp_ajax_yprint_validate_iban', array($this, 'ajax_validate_iban'));
        add_action('wp_ajax_nopriv_yprint_validate_iban', array($this, 'ajax_validate_iban'));
        
        // Filter für Gateway-Aktivierung
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_to_woocommerce'));
        
        // Hooks für Bestellverwaltung
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 3);
    }

    /**
     * API-Schlüssel laden
     */
    private function load_api_keys() {
        $this->test_mode = get_option('yprint_sepa_test_mode', 'no') === 'yes';
        
        if ($this->test_mode) {
            $this->api_key = get_option('yprint_sepa_test_api_key', 'INSERT_API_KEY_HERE');
            $this->api_secret = get_option('yprint_sepa_test_api_secret', 'INSERT_API_KEY_HERE');
        } else {
            $this->api_key = get_option('yprint_sepa_api_key', 'INSERT_API_KEY_HERE');
            $this->api_secret = get_option('yprint_sepa_api_secret', 'INSERT_API_KEY_HERE');
        }
    }

    /**
     * AJAX-Handler für SEPA-Zahlungsverarbeitung
     */
    public function ajax_process_sepa_payment() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (!isset($_POST['checkout_data']) || empty($_POST['checkout_data']) || !isset($_POST['sepa_data']) || empty($_POST['sepa_data'])) {
            wp_send_json_error(array(
                'message' => 'Unvollständige Zahlungsdaten'
            ));
            return;
        }
        
        $checkout_data = $_POST['checkout_data'];
        $sepa_data = $_POST['sepa_data'];
        $temp_order_id = isset($_POST['temp_order_id']) ? intval($_POST['temp_order_id']) : 0;
        
        try {
            // SEPA-Daten validieren
            $validation_result = $this->validate_sepa_data($sepa_data);
            if (!$validation_result['is_valid']) {
                throw new Exception($validation_result['message']);
            }
            
            // Mandatsreferenz generieren
            $mandate_reference = $this->generate_mandate_reference($temp_order_id);
            
            // Zahlungsprozess starten
            $payment_result = $this->process_sepa_payment($sepa_data, $mandate_reference, $temp_order_id);
            
            // In der Session speichern
            if (class_exists('WC_Session') && WC()->session) {
                WC()->session->set('yprint_sepa_mandate_reference', $mandate_reference);
                WC()->session->set('yprint_sepa_payment_id', $payment_result['payment_id']);
            }
            
            // Mit temporärer Bestellung verknüpfen, falls vorhanden
            if ($temp_order_id > 0) {
                update_post_meta($temp_order_id, '_sepa_mandate_reference', $mandate_reference);
                update_post_meta($temp_order_id, '_sepa_payment_id', $payment_result['payment_id']);
                
                // Bestellung auf "on-hold" setzen, da SEPA-Zahlungen eine Wartezeit haben
                $order = wc_get_order($temp_order_id);
                if ($order) {
                    $order->update_status('on-hold', __('SEPA-Lastschriftmandat erteilt: ' . $mandate_reference, 'yprint-payment'));
                    
                    // Maskierte IBAN als Notiz hinzufügen
                    $masked_iban = $this->mask_iban($sepa_data['iban']);
                    $order->add_order_note(sprintf(
                        __('SEPA-Lastschriftmandat erstellt:<br>Referenz: %s<br>IBAN: %s<br>Kontoinhaber: %s', 'yprint-payment'),
                        $mandate_reference,
                        $masked_iban,
                        $sepa_data['account_holder']
                    ));
                    
                    $order->save();
                }
            }
            
            // Bestätigungsmail senden, falls aktiviert
            if ($this->is_feature_enabled('sepa_mandate_email') && !empty($checkout_data['shipping_address']['email'])) {
                $this->send_mandate_confirmation_email(
                    $checkout_data['shipping_address']['email'],
                    $sepa_data, 
                    $mandate_reference,
                    $temp_order_id
                );
            }
            
            wp_send_json_success(array(
                'success' => true,
                'payment_id' => $payment_result['payment_id'],
                'mandate_reference' => $mandate_reference,
                'message' => 'SEPA-Lastschriftmandat erfolgreich erstellt'
            ));
        } catch (Exception $e) {
            $this->log('Fehler bei der SEPA-Zahlungsverarbeitung: ' . $e->getMessage(), 'error');
            
            wp_send_json_error(array(
                'message' => 'Fehler bei der SEPA-Zahlungsverarbeitung: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX-Handler für IBAN-Validierung
     */
    public function ajax_validate_iban() {
        $this->verify_nonce('yprint-checkout-nonce', 'security');
        
        if (!isset($_POST['iban']) || empty($_POST['iban'])) {
            wp_send_json_error(array(
                'message' => 'Keine IBAN angegeben'
            ));
            return;
        }
        
        $iban = sanitize_text_field($_POST['iban']);
        $bic = isset($_POST['bic']) ? sanitize_text_field($_POST['bic']) : '';
        
        // IBAN validieren
        $iban_valid = $this->validate_iban($iban);
        
        // BIC validieren, falls angegeben
        $bic_valid = empty($bic) ? true : $this->validate_bic($bic);
        
        if (!$iban_valid) {
            wp_send_json_error(array(
                'message' => 'Ungültige IBAN. Bitte überprüfen Sie Ihre Eingabe.'
            ));
            return;
        }
        
        if (!$bic_valid) {
            wp_send_json_error(array(
                'message' => 'Ungültiger BIC. Bitte überprüfen Sie Ihre Eingabe.'
            ));
            return;
        }
        
        // Bank-Informationen abrufen, wenn Feature aktiviert
        $bank_info = array();
        if ($this->is_feature_enabled('sepa_bank_lookup') && $iban_valid) {
            $bank_info = $this->get_bank_info_from_iban($iban);
        }
        
        wp_send_json_success(array(
            'is_valid' => true,
            'bank_info' => $bank_info
        ));
    }

    /**
     * Validiert SEPA-Zahlungsdaten
     * 
     * @param array $sepa_data Die zu validierenden SEPA-Daten
     * @return array Validierungsergebnis mit 'is_valid' und 'message'
     */
    private function validate_sepa_data($sepa_data) {
        // Prüfen, ob alle erforderlichen Felder vorhanden sind
        $required_fields = array('iban', 'account_holder');
        foreach ($required_fields as $field) {
            if (!isset($sepa_data[$field]) || empty($sepa_data[$field])) {
                return array(
                    'is_valid' => false,
                    'message' => 'Feld fehlt: ' . $field
                );
            }
        }
        
        // IBAN validieren
        $iban = $sepa_data['iban'];
        if (!$this->validate_iban($iban)) {
            return array(
                'is_valid' => false,
                'message' => 'Ungültige IBAN. Bitte überprüfen Sie Ihre Eingabe.'
            );
        }
        
        // BIC validieren, falls angegeben
        if (isset($sepa_data['bic']) && !empty($sepa_data['bic']) && !$this->validate_bic($sepa_data['bic'])) {
            return array(
                'is_valid' => false,
                'message' => 'Ungültiger BIC. Bitte überprüfen Sie Ihre Eingabe.'
            );
        }
        
        // Kontoinhaber validieren
        if (isset($sepa_data['account_holder']) && !$this->validate_account_holder($sepa_data['account_holder'])) {
            return array(
                'is_valid' => false,
                'message' => 'Ungültiger Kontoinhaber. Bitte überprüfen Sie Ihre Eingabe.'
            );
        }
        
        // Validierung erfolgreich
        return array(
            'is_valid' => true,
            'message' => 'Validierung erfolgreich'
        );
    }

    /**
     * Validiert eine IBAN
     * 
     * @param string $iban Die zu validierende IBAN
     * @return bool True, wenn gültig, sonst false
     */
    public function validate_iban($iban) {
        // Leerzeichen und Sonderzeichen entfernen
        $iban = preg_replace('/\s+/', '', $iban);
        $iban = strtoupper($iban);
        
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
        if ($this->is_feature_enabled('sepa_iban_checksum')) {
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
        // Da PHP Ganzzahlen bei großen Zahlen begrenzt, verwenden wir hier bcmod
        if (function_exists('bcmod')) {
            $modulo = bcmod($digits, '97');
            
            // Die Prüfsumme muss 1 ergeben
            return $modulo === '1';
        }
        
        // Wenn bcmod nicht verfügbar ist, manuelle Berechnung
        // Teile die Zahl in Blöcke und berechne den Modulo schrittweise
        $modulo = 0;
        for ($i = 0; $i < strlen($digits); $i++) {
            $modulo = ($modulo * 10 + (int)$digits[$i]) % 97;
        }
        
        return $modulo === 1;
    }
    
    /**
     * Validiert einen BIC
     * 
     * @param string $bic Der zu validierende BIC
     * @return bool True, wenn gültig, sonst false
     */
    public function validate_bic($bic) {
        // Leerzeichen und Sonderzeichen entfernen
        $bic = preg_replace('/\s+/', '', $bic);
        $bic = strtoupper($bic);
        
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
     * Validiert einen Kontoinhaber
     * 
     * @param string $account_holder Der zu validierende Kontoinhaber-Name
     * @return bool True, wenn gültig, sonst false
     */
    private function validate_account_holder($account_holder) {
        // Mindestlänge prüfen
        if (strlen($account_holder) < 3) {
            return false;
        }
        
        // Auf unerlaubte Zeichen prüfen
        $pattern = '/^[a-zA-ZäöüÄÖÜß\s\-&\.\',]+$/';
        if (!preg_match($pattern, $account_holder)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Generiert eine Mandatsreferenz
     * 
     * @param int $order_id Bestellungs-ID (optional)
     * @return string Generierte Mandatsreferenz
     */
    private function generate_mandate_reference($order_id = 0) {
        $prefix = 'YP-SEPA-';
        $timestamp = time();
        $random = rand(1000, 9999);
        
        if ($order_id > 0) {
            return $prefix . $order_id . '-' . $timestamp . '-' . $random;
        }
        
        return $prefix . $timestamp . '-' . $random;
    }
    
    /**
     * Verarbeitet eine SEPA-Zahlung
     * 
     * @param array $sepa_data SEPA-Zahlungsdaten
     * @param string $mandate_reference Mandatsreferenz
     * @param int $order_id Bestellungs-ID (optional)
     * @return array Ergebnis mit payment_id
     */
    private function process_sepa_payment($sepa_data, $mandate_reference, $order_id = 0) {
        // In der Praxis würde hier die Kommunikation mit dem Zahlungsdienstleister stattfinden
        // oder ein Zahlungsdatensatz für spätere Einzüge erstellt werden
        
        // Hier implementieren wir eine lokale Zahlungsverarbeitung
        // IBAN maskieren aus Sicherheitsgründen
        $masked_iban = $this->mask_iban($sepa_data['iban']);
        
        // Eindeutige Zahlungs-ID generieren
        $payment_id = 'sepa_' . time() . '_' . rand(1000, 9999);
        
        // Speichere die Zahlungsdaten in der Datenbank
        $payment_data = array(
            'payment_id' => $payment_id,
            'mandate_reference' => $mandate_reference,
            'account_holder' => sanitize_text_field($sepa_data['account_holder']),
            'masked_iban' => $masked_iban,
            'bic' => isset($sepa_data['bic']) ? sanitize_text_field($sepa_data['bic']) : '',
            'order_id' => $order_id,
            'created_at' => current_time('mysql'),
            'status' => 'pending',
            'test_mode' => $this->test_mode
        );
        
        // Speichere SEPA-Zahlungsdaten in einer separaten Tabelle, falls aktiviert
        if ($this->is_feature_enabled('sepa_payment_storage')) {
            $this->store_sepa_payment($payment_data);
        }
        
        return array(
            'payment_id' => $payment_id,
            'mandate_reference' => $mandate_reference,
            'status' => 'pending'
        );
    }
    
    /**
     * Speichert SEPA-Zahlungsdaten in einer separaten Tabelle
     * 
     * @param array $payment_data Die zu speichernden Zahlungsdaten
     * @return bool True bei Erfolg, sonst false
     */
    private function store_sepa_payment($payment_data) {
        global $wpdb;
        
        // Tabellennamen generieren
        $table_name = $wpdb->prefix . 'yprint_sepa_payments';
        
        // Prüfen, ob die Tabelle existiert, andernfalls erstellen
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $this->create_sepa_payments_table();
        }
        
        // Daten in die Tabelle einfügen
        $result = $wpdb->insert(
            $table_name,
            $payment_data,
            array(
                '%s', // payment_id
                '%s', // mandate_reference
                '%s', // account_holder
                '%s', // masked_iban
                '%s', // bic
                '%d', // order_id
                '%s', // created_at
                '%s', // status
                '%d'  // test_mode
            )
        );
        
        return $result !== false;
    }
    
    /**
     * Erstellt die SEPA-Zahlungstabelle
     * 
     * @return bool True bei Erfolg, sonst false
     */
    private function create_sepa_payments_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'yprint_sepa_payments';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            payment_id varchar(50) NOT NULL,
            mandate_reference varchar(100) NOT NULL,
            account_holder varchar(100) NOT NULL,
            masked_iban varchar(50) NOT NULL,
            bic varchar(20),
            order_id bigint(20),
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            status varchar(20) NOT NULL,
            test_mode tinyint(1) DEFAULT 0 NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        return !empty($result);
    }
    
    /**
     * Handler für WooCommerce-Bestellstatusänderungen
     * 
     * @param int $order_id Bestellungs-ID
     * @param string $old_status Alter Status
     * @param string $new_status Neuer Status
     */
    public function handle_order_status_change($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);
        
        // Nur SEPA-Bestellungen verarbeiten
        if ($order->get_payment_method() !== 'sepa') {
            return;
        }
        
        // Mandate-Referenz abrufen
        $mandate_reference = get_post_meta($order_id, '_sepa_mandate_reference', true);
        
        // Payment-ID abrufen
        $payment_id = get_post_meta($order_id, '_sepa_payment_id', true);
        
        // Wenn Status auf "completed" geändert wird
        if ($new_status === 'completed' && $old_status !== 'completed') {
            // SEPA-Zahlungsstatus aktualisieren
            $this->update_sepa_payment_status($payment_id, 'completed');
            
            // Benachrichtigung senden, falls aktiviert
            if ($this->is_feature_enabled('sepa_completion_notification')) {
                $this->send_completion_notification($order, $mandate_reference);
            }
        }
        
        // Wenn Status auf "refunded" geändert wird
        elseif ($new_status === 'refunded' && $old_status !== 'refunded') {
            // SEPA-Zahlungsstatus aktualisieren
            $this->update_sepa_payment_status($payment_id, 'refunded');
            
            // Rückerstattungsprotokoll erstellen
            $this->log_sepa_refund($order_id, $payment_id, $mandate_reference);
        }
        
        // Wenn Status auf "failed" geändert wird
        elseif ($new_status === 'failed' && $old_status !== 'failed') {
            // SEPA-Zahlungsstatus aktualisieren
            $this->update_sepa_payment_status($payment_id, 'failed');
        }
    }
    
    /**
     * Aktualisiert den Status einer SEPA-Zahlung
     * 
     * @param string $payment_id Zahlungs-ID
     * @param string $status Neuer Status
     * @return bool True bei Erfolg, sonst false
     */
    private function update_sepa_payment_status($payment_id, $status) {
        global $wpdb;
        
        // Tabellennamen generieren
        $table_name = $wpdb->prefix . 'yprint_sepa_payments';
        
        // Prüfen, ob die Tabelle existiert
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return false;
        }
        
        // Status aktualisieren
        $result = $wpdb->update(
            $table_name,
            array('status' => $status),
            array('payment_id' => $payment_id),
            array('%s'),
            array('%s')
        );
        
        return $result !== false;
    }
    
    /**
     * Sendet eine SEPA-Mandatsbestätigung per E-Mail
     * 
     * @param string $email Empfänger-E-Mail
     * @param array $sepa_data SEPA-Daten
     * @param string $mandate_reference Mandatsreferenz
     * @param int $order_id Bestellungs-ID (optional)
     */
    private function send_mandate_confirmation_email($email, $sepa_data, $mandate_reference, $order_id = 0) {
        // Betreff erstellen
        $subject = get_bloginfo('name') . ' - ' . __('SEPA-Lastschriftmandat', 'yprint-payment');
        
        // E-Mail-Inhalt erstellen
        $order = $order_id ? wc_get_order($order_id) : null;
        $amount = $order ? $order->get_total() : '';
        $currency = $order ? $order->get_currency() : get_woocommerce_currency();
        
        $message = sprintf(
            __('Hallo %s,<br><br>vielen Dank für Ihre Bestellung bei %s.<br><br>', 'yprint-payment'),
            sanitize_text_field($sepa_data['account_holder']),
            get_bloginfo('name')
        );
        
        $message .= sprintf(
            __('Sie haben uns ein SEPA-Lastschriftmandat erteilt. Hier sind die Details:<br><br>
            <strong>Mandatsreferenz:</strong> %s<br>
            <strong>IBAN:</strong> %s<br>
            <strong>Kontoinhaber:</strong> %s<br>
            <strong>Betrag:</strong> %s %s<br>
            <strong>Datum:</strong> %s<br><br>', 'yprint-payment'),
            $mandate_reference,
            $this->mask_iban($sepa_data['iban']),
            sanitize_text_field($sepa_data['account_holder']),
            $amount,
            $currency,
            date_i18n(get_option('date_format'), current_time('timestamp'))
        );
        
        $message .= __('Der Betrag wird in den nächsten Tagen von Ihrem Konto abgebucht.<br><br>', 'yprint-payment');
        
        // Widerrufsbelehrung hinzufügen
        $company_name = get_option('yprint_company_name', get_bloginfo('name'));
        $company_address = get_option('yprint_company_address', '');
        
        $message .= sprintf(
            __('<strong>SEPA-Lastschrift-Mandat Informationen:</strong><br>
            Ich ermächtige %1$s, Zahlungen von meinem Konto mittels Lastschrift einzuziehen. 
            Zugleich weise ich mein Kreditinstitut an, die von %1$s auf mein Konto gezogenen Lastschriften einzulösen.<br><br>
            Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. 
            Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.<br><br>', 'yprint-payment'),
            $company_name
        );
        
        $message .= __('Mit freundlichen Grüßen<br>Ihr ' . get_bloginfo('name') . ' Team', 'yprint-payment');
        
        // E-Mail-Header für HTML-E-Mail
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // E-Mail senden
        wp_mail($email, $subject, $message, $headers);
    }
    
    * Sendet eine Benachrichtigung über den Abschluss der Zahlung
     * 
     * @param WC_Order $order Bestellungsobjekt
     * @param string $mandate_reference Mandatsreferenz
     */
    private function send_completion_notification($order, $mandate_reference) {
        // Kunden-E-Mail
        $email = $order->get_billing_email();
        
        // Bestellnummer
        $order_number = $order->get_order_number();
        
        // Betreff
        $subject = sprintf(
            __('Ihre Zahlung für Bestellung #%s wurde verarbeitet', 'yprint-payment'),
            $order_number
        );
        
        // Nachricht
        $message = sprintf(
            __('Hallo %s %s,<br><br>', 'yprint-payment'),
            $order->get_billing_first_name(),
            $order->get_billing_last_name()
        );
        
        $message .= sprintf(
            __('Wir möchten Sie informieren, dass Ihre SEPA-Lastschriftzahlung für die Bestellung #%s erfolgreich verarbeitet wurde.<br><br>', 'yprint-payment'),
            $order_number
        );
        
        $message .= sprintf(
            __('Mandatsreferenz: %s<br>', 'yprint-payment'),
            $mandate_reference
        );
        
        $message .= sprintf(
            __('Betrag: %s<br><br>', 'yprint-payment'),
            $order->get_formatted_order_total()
        );
        
        $message .= __('Vielen Dank für Ihren Einkauf.<br><br>', 'yprint-payment');
        $message .= __('Mit freundlichen Grüßen<br>Ihr ' . get_bloginfo('name') . ' Team', 'yprint-payment');
        
        // E-Mail-Header für HTML-E-Mail
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // E-Mail senden
        wp_mail($email, $subject, $message, $headers);
    }
    
    /**
     * Protokolliert eine SEPA-Rückerstattung
     * 
     * @param int $order_id Bestellungs-ID
     * @param string $payment_id Zahlungs-ID
     * @param string $mandate_reference Mandatsreferenz
     */
    private function log_sepa_refund($order_id, $payment_id, $mandate_reference) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Rückerstattungsdatum
        $refund_date = current_time('mysql');
        
        // Rückerstattungsbetrag
        $refund_amount = $order->get_total();
        
        // Rückerstattungsgrund
        $refund_reason = __('Bestellung zurückerstattet', 'yprint-payment');
        
        // Benutzer-ID (falls verfügbar)
        $user_id = get_current_user_id();
        
        // Rückerstattungsdaten
        $refund_data = array(
            'order_id' => $order_id,
            'payment_id' => $payment_id,
            'mandate_reference' => $mandate_reference,
            'refund_date' => $refund_date,
            'refund_amount' => $refund_amount,
            'refund_reason' => $refund_reason,
            'user_id' => $user_id
        );
        
        // Speichere Rückerstattungsdaten
        if ($this->is_feature_enabled('sepa_refund_logging')) {
            $this->store_sepa_refund($refund_data);
        }
        
        // Log-Nachricht
        $this->log(sprintf(
            'SEPA-Rückerstattung für Bestellung #%s: %s %s, Mandatsreferenz: %s',
            $order_id,
            $refund_amount,
            $order->get_currency(),
            $mandate_reference
        ));
    }
    
    /**
     * Speichert SEPA-Rückerstattungsdaten
     * 
     * @param array $refund_data Rückerstattungsdaten
     * @return bool True bei Erfolg, sonst false
     */
    private function store_sepa_refund($refund_data) {
        global $wpdb;
        
        // Tabellennamen generieren
        $table_name = $wpdb->prefix . 'yprint_sepa_refunds';
        
        // Prüfen, ob die Tabelle existiert, andernfalls erstellen
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $this->create_sepa_refunds_table();
        }
        
        // Daten in die Tabelle einfügen
        $result = $wpdb->insert(
            $table_name,
            $refund_data,
            array(
                '%d', // order_id
                '%s', // payment_id
                '%s', // mandate_reference
                '%s', // refund_date
                '%f', // refund_amount
                '%s', // refund_reason
                '%d'  // user_id
            )
        );
        
        return $result !== false;
    }
    
    /**
     * Erstellt die SEPA-Rückerstattungstabelle
     * 
     * @return bool True bei Erfolg, sonst false
     */
    private function create_sepa_refunds_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'yprint_sepa_refunds';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            payment_id varchar(50) NOT NULL,
            mandate_reference varchar(100) NOT NULL,
            refund_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            refund_amount decimal(10,2) NOT NULL,
            refund_reason text NOT NULL,
            user_id bigint(20),
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        return !empty($result);
    }
    
    /**
     * Ruft Bank-Informationen anhand einer IBAN ab
     * 
     * @param string $iban Die IBAN
     * @return array Bank-Informationen
     */
    private function get_bank_info_from_iban($iban) {
        // Für deutsche IBANs: Bankleitzahl extrahieren
        if (substr($iban, 0, 2) === 'DE') {
            $blz = substr($iban, 4, 8);
            return $this->get_bank_info_from_blz($blz);
        }
        
        // Für andere Länder: Leeres Array zurückgeben
        return array();
    }
    
    /**
     * Ruft Bank-Informationen anhand einer Bankleitzahl ab
     * 
     * @param string $blz Die Bankleitzahl
     * @return array Bank-Informationen
     */
    private function get_bank_info_from_blz($blz) {
        // Hier könnte eine API für Bankdaten oder eine lokale Datenbank abgefragt werden
        // In dieser Demo-Implementierung geben wir einige statische Daten zurück
        
        // Bekannte deutsche Bankleitzahlen für Demo-Zwecke
        $banks = array(
            '10010010' => array(
                'name' => 'Postbank',
                'city' => 'Berlin',
                'bic' => 'PBNKDEFF'
            ),
            '10050000' => array(
                'name' => 'Landesbank Berlin',
                'city' => 'Berlin',
                'bic' => 'BELADEBEXXX'
            ),
            '20010020' => array(
                'name' => 'Postbank',
                'city' => 'Hamburg',
                'bic' => 'PBNKDEFF200'
            ),
            '20050550' => array(
                'name' => 'Hamburger Sparkasse',
                'city' => 'Hamburg',
                'bic' => 'HASPDEHHXXX'
            ),
            '30010400' => array(
                'name' => 'IKB Deutsche Industriebank',
                'city' => 'Düsseldorf',
                'bic' => 'IKBDDEDDXXX'
            ),
            '30050000' => array(
                'name' => 'Landesbank Hessen-Thüringen Girozentrale NL Düsseldorf',
                'city' => 'Düsseldorf',
                'bic' => 'WELADEDDXXX'
            ),
            '37010050' => array(
                'name' => 'Commerzbank',
                'city' => 'Köln',
                'bic' => 'COBADEFFXXX'
            ),
            '37040044' => array(
                'name' => 'Commerzbank',
                'city' => 'Köln',
                'bic' => 'COBADEFFXXX'
            ),
            '50010517' => array(
                'name' => 'ING-DiBa',
                'city' => 'Frankfurt am Main',
                'bic' => 'INGDDEFFXXX'
            ),
            '50050201' => array(
                'name' => 'Frankfurter Sparkasse',
                'city' => 'Frankfurt am Main',
                'bic' => 'HELADEF1822'
            ),
            '70010080' => array(
                'name' => 'Postbank',
                'city' => 'München',
                'bic' => 'PBNKDEFF700'
            ),
            '70050000' => array(
                'name' => 'Bayerische Landesbank',
                'city' => 'München',
                'bic' => 'BYLADEMMXXX'
            )
        );
        
        return isset($banks[$blz]) ? $banks[$blz] : array();
    }
    
    /**
     * Maskiert eine IBAN für die Anzeige
     * 
     * @param string $iban Die zu maskierende IBAN
     * @return string Die maskierte IBAN
     */
    public function mask_iban($iban) {
        // Leerzeichen entfernen
        $iban = str_replace(' ', '', $iban);
        
        // Länge der IBAN
        $length = strlen($iban);
        
        // Falls die IBAN sehr kurz ist, nicht maskieren
        if ($length <= 8) {
            return $iban;
        }
        
        // Die ersten 4 Zeichen (Ländercode und Prüfziffern) und die letzten 4 Zeichen anzeigen
        $visible_start = substr($iban, 0, 4);
        $visible_end = substr($iban, -4);
        
        // Mittleren Teil durch Sternchen ersetzen
        $masked = $visible_start . str_repeat('*', $length - 8) . $visible_end;
        
        // Mit Leerzeichen formatieren für bessere Lesbarkeit
        $formatted = '';
        for ($i = 0; $i < strlen($masked); $i++) {
            $formatted .= $masked[$i];
            if (($i + 1) % 4 === 0 && $i < strlen($masked) - 1) {
                $formatted .= ' ';
            }
        }
        
        return $formatted;
    }
    
    /**
     * Fügt das SEPA-Gateway zu den WooCommerce-Zahlungsgateways hinzu
     * 
     * @param array $gateways Bestehende Gateways
     * @return array Aktualisierte Gateways
     */
    public function add_gateway_to_woocommerce($gateways) {
        // Wenn SEPA nicht in den Feature-Flags aktiviert ist, nichts tun
        if (!$this->is_feature_enabled('sepa_integration')) {
            return $gateways;
        }
        
        // SEPA-Gateway-Klasse laden, falls nicht manuell registriert
        if (!class_exists('WC_Gateway_SEPA')) {
            include_once YPRINT_PAYMENT_ABSPATH . 'includes/gateways/wc-gateway-sepa.php';
        }
        
        // Gateway hinzufügen
        $gateways[] = 'WC_Gateway_SEPA';
        
        return $gateways;
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
            'sepa_integration' => true,
            'sepa_iban_checksum' => true,
            'sepa_bank_lookup' => false,
            'sepa_mandate_email' => true,
            'sepa_payment_storage' => true,
            'sepa_refund_logging' => true,
            'sepa_completion_notification' => true,
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
            error_log('[YPrint SEPA] [' . $level_name . '] ' . $message);
        }
    }
}

/**
 * Gibt die Hauptinstanz von YPrint_SEPA zurück
 * 
 * @return YPrint_SEPA
 */
function YPrint_SEPA() {
    return YPrint_SEPA::instance();
}

// Globale für Abwärtskompatibilität
$GLOBALS['yprint_sepa'] = YPrint_SEPA();