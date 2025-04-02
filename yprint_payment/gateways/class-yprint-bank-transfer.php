<?php
/**
 * Bank-Transfer Zahlungsintegration für YPrint Payment
 *
 * Diese Klasse implementiert die Integration mit Banküberweisung als Zahlungsmethode.
 * Sie enthält Methoden für die Generierung von Überweisungsinformationen,
 * Validierung von Zahlungseingängen und Referenzcodes.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bank Transfer Zahlungs-Gateway Klasse
 */
class YPrint_Bank_Transfer {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_Bank_Transfer
     */
    protected static $_instance = null;

    /**
     * Bankdaten
     *
     * @var array
     */
    private $bank_details = array();

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
     * Hauptinstanz der YPrint_Bank_Transfer-Klasse
     *
     * @return YPrint_Bank_Transfer - Hauptinstanz
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
        // Bank-Details laden
        $this->load_bank_details();
        
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
    private function init_hooks() {
        // AJAX Handler für die Verarbeitung der Banküberweisung
        add_action('wp_ajax_yprint_process_bank_transfer', array($this, 'ajax_process_bank_transfer'));
        add_action('wp_ajax_nopriv_yprint_process_bank_transfer', array($this, 'ajax_process_bank_transfer'));
        
        // E-Mail-Modifikation für Banküberweisung
        add_filter('woocommerce_email_order_meta_fields', array($this, 'add_bank_details_to_emails'), 10, 3);
        
        // Hinzufügen der Bankdaten zur Bestellbestätigungsseite
        add_action('woocommerce_thankyou_bank_transfer', array($this, 'thankyou_page_bank_details'));
        
        // Admin-Schnittstelle für manuelle Zahlungsvalidierung
        add_action('add_meta_boxes', array($this, 'add_bank_transfer_meta_box'));
        add_action('save_post', array($this, 'save_bank_transfer_meta_box'));
        
        // Webhook für importierte Banktransaktionen, falls implementiert
        add_action('init', array($this, 'handle_bank_transaction_webhook'));
        
        // Filter für Gateway-Aktivierung
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_to_woocommerce'));
    }

    /**
     * Lädt die Bankdaten aus den Einstellungen
     */
    private function load_bank_details() {
        $this->bank_details = array(
            'account_name' => get_option('yprint_bank_account_name', ''),
            'account_number' => get_option('yprint_bank_account_number', ''),
            'bank_name' => get_option('yprint_bank_name', ''),
            'iban' => get_option('yprint_bank_iban', ''),
            'bic' => get_option('yprint_bank_bic', ''),
            'description' => get_option('yprint_bank_description', __('Bitte geben Sie Ihre Bestellnummer als Verwendungszweck an.', 'yprint-payment'))
        );
    }

    /**
     * AJAX-Handler für die Verarbeitung der Banküberweisung
     */
    public function ajax_process_bank_transfer() {
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
            // Bei einer Banküberweisung benötigen wir keinen externen Zahlungsanbieter
            // Wir generieren lediglich eine Referenznummer und speichern die Bestellung
            
            // Eindeutige Referenz-ID erstellen
            $reference_id = $this->generate_reference_id($temp_order_id);
            
            // Bestellung aktualisieren, falls vorhanden
            if ($temp_order_id > 0) {
                $order = wc_get_order($temp_order_id);
                if ($order) {
                    // Aktualisiere Zahlungsmethode
                    $order->set_payment_method('bank_transfer');
                    $order->set_payment_method_title('Banküberweisung');
                    
                    // Bestellstatus auf "On-Hold" setzen
                    $order->update_status('on-hold', 'Warte auf Zahlungseingang via Banküberweisung');
                    
                    // Referenz speichern
                    update_post_meta($order->get_id(), '_bank_transfer_reference', $reference_id);
                    
                    // Zahlungsinformationen als Bestellnotiz hinzufügen
                    $order->add_order_note(sprintf(
                        __('Banküberweisung initiiert. Referenz: %s. Warte auf Zahlungseingang.', 'yprint-payment'),
                        $reference_id
                    ));
                    
                    // Bestellung speichern
                    $order->save();
                }
            }
            
            // E-Mail mit Überweisungsdaten senden, falls aktiviert
            if ($this->is_feature_enabled('bank_transfer_email')) {
                $this->send_bank_transfer_instructions($checkout_data, $reference_id, $temp_order_id);
            }
            
            // Zahlungsdetails für die Antwort vorbereiten
            $payment_details = $this->get_payment_instructions($reference_id, $temp_order_id);
            
            wp_send_json_success(array(
                'success' => true,
                'payment_id' => $reference_id,
                'payment_details' => $payment_details,
                'message' => 'Zahlungsprozess initiiert. Bitte führen Sie die Überweisung durch.'
            ));
        } catch (Exception $e) {
            $this->log('Fehler bei der Banküberweisung: ' . $e->getMessage(), 'error');
            
            wp_send_json_error(array(
                'message' => 'Fehler bei der Banküberweisung: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Generiert eine Referenz-ID für die Banküberweisung
     * 
     * @param int $order_id Bestellungs-ID (optional)
     * @return string Generierte Referenz-ID
     */
    private function generate_reference_id($order_id = 0) {
        $prefix = 'YP-';
        $timestamp = time();
        $random = rand(1000, 9999);
        
        if ($order_id > 0) {
            return $prefix . $order_id;
        }
        
        return $prefix . $timestamp . '-' . $random;
    }

    /**
     * Bereitet die Zahlungsanweisungen für den Kunden vor
     * 
     * @param string $reference_id Referenz-ID für die Überweisung
     * @param int $order_id Bestellungs-ID (optional)
     * @return array Zahlungsanweisungen
     */
    private function get_payment_instructions($reference_id, $order_id = 0) {
        $order = $order_id > 0 ? wc_get_order($order_id) : null;
        $amount = $order ? $order->get_total() : 0;
        $currency = $order ? $order->get_currency() : get_woocommerce_currency();
        
        return array(
            'bank_name' => $this->bank_details['bank_name'],
            'account_name' => $this->bank_details['account_name'],
            'iban' => $this->bank_details['iban'],
            'bic' => $this->bank_details['bic'],
            'reference' => $reference_id,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $this->bank_details['description']
        );
    }

    /**
     * Sendet eine E-Mail mit Überweisungsinstruktionen
     * 
     * @param array $checkout_data Checkout-Daten
     * @param string $reference_id Referenz-ID für die Überweisung
     * @param int $order_id Bestellungs-ID (optional)
     */
    private function send_bank_transfer_instructions($checkout_data, $reference_id, $order_id = 0) {
        // E-Mail-Adresse aus Checkout-Daten extrahieren
        $email = '';
        
        if (isset($checkout_data['shipping_address']['email'])) {
            $email = sanitize_email($checkout_data['shipping_address']['email']);
        } elseif (isset($checkout_data['different_billing']) && $checkout_data['different_billing'] && 
                  isset($checkout_data['different_billing_address']['different_billing_email'])) {
            $email = sanitize_email($checkout_data['different_billing_address']['different_billing_email']);
        }
        
        // Wenn keine E-Mail gefunden wurde, versuchen wir es mit der Bestellung
        if (empty($email) && $order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order) {
                $email = $order->get_billing_email();
            }
        }
        
        // Wenn immer noch keine E-Mail gefunden wurde, abbrechen
        if (empty($email)) {
            $this->log('Keine E-Mail-Adresse für Überweisungsinstruktionen gefunden', 'warning');
            return;
        }
        
        // Zahlungsanweisungen holen
        $payment_instructions = $this->get_payment_instructions($reference_id, $order_id);
        
        // Betreff erstellen
        $subject = sprintf(
            __('Ihre Zahlungsinformationen für Bestellung bei %s', 'yprint-payment'),
            get_bloginfo('name')
        );
        
        // E-Mail-Inhalt erstellen
        $message = sprintf(
            __('Hallo,<br><br>vielen Dank für Ihre Bestellung bei %s.<br><br>', 'yprint-payment'),
            get_bloginfo('name')
        );
        
        $message .= __('Bitte führen Sie die Überweisung mit folgenden Informationen durch:<br><br>', 'yprint-payment');
        
        $message .= sprintf(
            __('<strong>Empfänger:</strong> %s<br>', 'yprint-payment'),
            esc_html($payment_instructions['account_name'])
        );
        
        $message .= sprintf(
            __('<strong>Bank:</strong> %s<br>', 'yprint-payment'),
            esc_html($payment_instructions['bank_name'])
        );
        
        $message .= sprintf(
            __('<strong>IBAN:</strong> %s<br>', 'yprint-payment'),
            esc_html($payment_instructions['iban'])
        );
        
        $message .= sprintf(
            __('<strong>BIC:</strong> %s<br>', 'yprint-payment'),
            esc_html($payment_instructions['bic'])
        );
        
        $message .= sprintf(
            __('<strong>Betrag:</strong> %s %s<br>', 'yprint-payment'),
            number_format($payment_instructions['amount'], 2, ',', '.'),
            esc_html($payment_instructions['currency'])
        );
        
        $message .= sprintf(
            __('<strong>Verwendungszweck:</strong> %s<br><br>', 'yprint-payment'),
            esc_html($payment_instructions['reference'])
        );
        
        $message .= __('<strong>Wichtig:</strong> Bitte geben Sie unbedingt den angegebenen Verwendungszweck an, damit wir Ihre Zahlung korrekt zuordnen können.<br><br>', 'yprint-payment');
        
        $message .= __('Ihre Bestellung wird bearbeitet, sobald wir den Zahlungseingang bestätigt haben.<br><br>', 'yprint-payment');
        
        $message .= __('Mit freundlichen Grüßen<br>Ihr ' . get_bloginfo('name') . ' Team', 'yprint-payment');
        
        // E-Mail-Header für HTML-E-Mail
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // E-Mail senden
        wp_mail($email, $subject, $message, $headers);
    }

    /**
     * Fügt Bankdaten zu WooCommerce-E-Mails hinzu
     * 
     * @param array $fields E-Mail-Felder
     * @param bool $sent_to_admin Ob E-Mail an Admin gesendet wird
     * @param WC_Order $order Bestellung
     * @return array Aktualisierte E-Mail-Felder
     */
    public function add_bank_details_to_emails($fields, $sent_to_admin, $order) {
        // Nur für Banküberweisung-Bestellungen
        if ($order->get_payment_method() !== 'bank_transfer') {
            return $fields;
        }
        
        // Referenz holen
        $reference = get_post_meta($order->get_id(), '_bank_transfer_reference', true);
        if (empty($reference)) {
            // Fallback: Referenz generieren, falls nicht vorhanden
            $reference = $this->generate_reference_id($order->get_id());
            update_post_meta($order->get_id(), '_bank_transfer_reference', $reference);
        }
        
        // Bankdetails zu den E-Mail-Feldern hinzufügen
        $fields['bank_transfer_account_name'] = array(
            'label' => __('Empfänger', 'yprint-payment'),
            'value' => $this->bank_details['account_name']
        );
        
        $fields['bank_transfer_bank_name'] = array(
            'label' => __('Bank', 'yprint-payment'),
            'value' => $this->bank_details['bank_name']
        );
        
        $fields['bank_transfer_iban'] = array(
            'label' => __('IBAN', 'yprint-payment'),
            'value' => $this->bank_details['iban']
        );
        
        $fields['bank_transfer_bic'] = array(
            'label' => __('BIC', 'yprint-payment'),
            'value' => $this->bank_details['bic']
        );
        
        $fields['bank_transfer_reference'] = array(
            'label' => __('Verwendungszweck', 'yprint-payment'),
            'value' => $reference
        );
        
        return $fields;
    }

    /**
     * Fügt Bankdaten zur Bestellbestätigungsseite hinzu
     * 
     * @param int $order_id Bestellungs-ID
     */
    public function thankyou_page_bank_details($order_id) {
        $order = wc_get_order($order_id);
        
        // Nur für Banküberweisung-Bestellungen
        if (!$order || $order->get_payment_method() !== 'bank_transfer') {
            return;
        }
        
        // Referenz holen
        $reference = get_post_meta($order_id, '_bank_transfer_reference', true);
        if (empty($reference)) {
            // Fallback: Referenz generieren, falls nicht vorhanden
            $reference = $this->generate_reference_id($order_id);
            update_post_meta($order_id, '_bank_transfer_reference', $reference);
        }
        
        // HTML-Ausgabe
        ?>
        <div class="yprint-bank-transfer-details">
            <h2><?php esc_html_e('Banküberweisung Details', 'yprint-payment'); ?></h2>
            <p><?php esc_html_e('Bitte überweisen Sie den Betrag mit den folgenden Angaben:', 'yprint-payment'); ?></p>
            
            <ul class="yprint-bank-details">
                <li class="bank-name">
                    <strong><?php esc_html_e('Bank:', 'yprint-payment'); ?></strong>
                    <span><?php echo esc_html($this->bank_details['bank_name']); ?></span>
                </li>
                <li class="account-name">
                    <strong><?php esc_html_e('Empfänger:', 'yprint-payment'); ?></strong>
                    <span><?php echo esc_html($this->bank_details['account_name']); ?></span>
                </li>
                <li class="iban">
                    <strong><?php esc_html_e('IBAN:', 'yprint-payment'); ?></strong>
                    <span><?php echo esc_html($this->bank_details['iban']); ?></span>
                </li>
                <li class="bic">
                    <strong><?php esc_html_e('BIC:', 'yprint-payment'); ?></strong>
                    <span><?php echo esc_html($this->bank_details['bic']); ?></span>
                </li>
                <li class="reference">
                    <strong><?php esc_html_e('Verwendungszweck:', 'yprint-payment'); ?></strong>
                    <span><?php echo esc_html($reference); ?></span>
                </li>
                <li class="amount">
                    <strong><?php esc_html_e('Betrag:', 'yprint-payment'); ?></strong>
                    <span><?php echo $order->get_formatted_order_total(); ?></span>
                </li>
            </ul>
            
            <div class="yprint-bank-notice">
                <p><strong><?php esc_html_e('Wichtig:', 'yprint-payment'); ?></strong> <?php esc_html_e('Bitte geben Sie unbedingt den angegebenen Verwendungszweck an, damit wir Ihre Zahlung korrekt zuordnen können.', 'yprint-payment'); ?></p>
                <p><?php esc_html_e('Ihre Bestellung wird bearbeitet, sobald wir den Zahlungseingang bestätigt haben.', 'yprint-payment'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Fügt eine Meta-Box im Admin-Bereich hinzu, um Banküberweisung-Zahlungen zu verfolgen
     */
    public function add_bank_transfer_meta_box() {
        add_meta_box(
            'yprint_bank_transfer_status',
            __('Banküberweisung Status', 'yprint-payment'),
            array($this, 'render_bank_transfer_meta_box'),
            'shop_order',
            'side',
            'default'
        );
    }

    /**
     * Rendert die Bank-Transfer Meta-Box
     * 
     * @param WP_Post $post Post-Objekt
     */
    public function render_bank_transfer_meta_box($post) {
        $order = wc_get_order($post->ID);
        
        // Nur für Banküberweisung-Bestellungen
        if (!$order || $order->get_payment_method() !== 'bank_transfer') {
            echo '<p>' . esc_html__('Diese Bestellung verwendet keine Banküberweisung als Zahlungsmethode.', 'yprint-payment') . '</p>';
            return;
        }
        
        // Aktuelle Daten abrufen
        $reference = get_post_meta($post->ID, '_bank_transfer_reference', true);
        $payment_confirmed = get_post_meta($post->ID, '_bank_transfer_confirmed', true);
        $confirmation_date = get_post_meta($post->ID, '_bank_transfer_confirmation_date', true);
        $confirmation_user = get_post_meta($post->ID, '_bank_transfer_confirmation_user', true);
        
        // Nonce für Sicherheit
        wp_nonce_field('yprint_bank_transfer_meta_box', 'yprint_bank_transfer_nonce');
        
        // Referenz anzeigen
        echo '<p><strong>' . esc_html__('Verwendungszweck:', 'yprint-payment') . '</strong> ' . esc_html($reference) . '</p>';
        
        // Status anzeigen
        echo '<p><strong>' . esc_html__('Zahlungsstatus:', 'yprint-payment') . '</strong> ';
        if ($payment_confirmed) {
            echo '<span style="color:green;">' . esc_html__('Bestätigt', 'yprint-payment') . '</span>';
            
            // Zusätzliche Infos anzeigen, wenn vorhanden
            if ($confirmation_date) {
                echo '<br><em>' . esc_html(sprintf(__('Bestätigt am: %s', 'yprint-payment'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($confirmation_date)))) . '</em>';
            }
            
            if ($confirmation_user) {
                $user = get_userdata($confirmation_user);
                if ($user) {
                    echo '<br><em>' . esc_html(sprintf(__('Bestätigt von: %s', 'yprint-payment'), $user->display_name)) . '</em>';
                }
            }
        } else {
            echo '<span style="color:orange;">' . esc_html__('Ausstehend', 'yprint-payment') . '</span>';
        }
        echo '</p>';
        
        // Button zum Bestätigen/Widerrufen der Zahlung
        if ($payment_confirmed) {
            echo '<p>';
            echo '<button type="submit" name="yprint_revoke_bank_transfer" value="1" class="button button-secondary">';
            echo esc_html__('Bestätigung widerrufen', 'yprint-payment');
            echo '</button>';
            echo '</p>';
        } else {
            echo '<p>';
            echo '<button type="submit" name="yprint_confirm_bank_transfer" value="1" class="button button-primary">';
            echo esc_html__('Zahlung bestätigen', 'yprint-payment');
            echo '</button>';
            echo '</p>';
        }
        
        // Hinweis zur automatischen Bestellstatusänderung
        echo '<p><em>' . esc_html__('Hinweis: Bei Bestätigung der Zahlung wird der Bestellstatus automatisch auf "In Bearbeitung" gesetzt.', 'yprint-payment') . '</em></p>';
    }

    /**
     * Speichert die Daten der Bank-Transfer Meta-Box
     * 
     * @param int $post_id Post-ID
     */
    public function save_bank_transfer_meta_box($post_id) {
        // Sicherheitscheck
        if (!isset($_POST['yprint_bank_transfer_nonce']) || !wp_verify_nonce($_POST['yprint_bank_transfer_nonce'], 'yprint_bank_transfer_meta_box')) {
            return;
        }
        
        // Prüfen, ob es sich um einen Autosave handelt
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Berechtigungsprüfung
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Bestellung holen
        $order = wc_get_order($post_id);
        if (!$order || $order->get_payment_method() !== 'bank_transfer') {
            return;
        }
        
        // Zahlung bestätigen
        if (isset($_POST['yprint_confirm_bank_transfer']) && $_POST['yprint_confirm_bank_transfer'] === '1') {
            // Meta-Daten aktualisieren
            update_post_meta($post_id, '_bank_transfer_confirmed', true);
            update_post_meta($post_id, '_bank_transfer_confirmation_date', current_time('mysql'));
            update_post_meta($post_id, '_bank_transfer_confirmation_user', get_current_user_id());
            
            // Bestellstatus aktualisieren
            if ($order->get_status() === 'on-hold') {
                $order->update_status('processing', 'Zahlung per Banküberweisung eingegangen und manuell bestätigt.');
            }
            
            // Aktion auslösen
            do_action('yprint_bank_transfer_payment_confirmed', $order);
            
            // Hinweis hinzufügen
            $this->add_admin_notice(__('Zahlung wurde als bestätigt markiert.', 'yprint-payment'), 'success');
        }
        
        // Bestätigung widerrufen
        elseif (isset($_POST['yprint_revoke_bank_transfer']) && $_POST['yprint_revoke_bank_transfer'] === '1') {
            // Meta-Daten aktualisieren
            update_post_meta($post_id, '_bank_transfer_confirmed', false);
            
            // Bestellstatus aktualisieren
            if ($order->get_status() === 'processing') {
                $order->update_status('on-hold', 'Zahlungsbestätigung für Banküberweisung widerrufen.');
            }
            
            // Aktion auslösen
            do_action('yprint_bank_transfer_payment_revoked', $order);
            
            // Hinweis hinzufügen
            $this->add_admin_notice(__('Zahlungsbestätigung wurde widerrufen.', 'yprint-payment'), 'warning');
        }
    }

    /**
     * Fügt ein Gateway zu den WooCommerce-Zahlungsgateways hinzu
     * 
     * @param array $gateways Bestehende Gateways
     * @return array Aktualisierte Gateways
     */
    public function add_gateway_to_woocommerce($gateways) {
        // Wenn Bank Transfer nicht in den Feature-Flags aktiviert ist, nichts tun
        if (!$this->is_feature_enabled('bank_transfer')) {
            return $gateways;
        }
        
        // Bank Transfer Gateway-Klasse laden, falls nicht manuell registriert
        if (!class_exists('WC_Gateway_Bank_Transfer')) {
            include_once YPRINT_PAYMENT_ABSPATH . 'includes/gateways/wc-gateway-bank-transfer.php';
        }
        
        // Gateway hinzufügen
        $gateways[] = 'WC_Gateway_Bank_Transfer';
        
        return $gateways;
    }

    /**
     * Webhook-Handler für importierte Banktransaktionen
     */
    public function handle_bank_transaction_webhook() {
        // Nur an der Bank-Transaktion-Webhook-URL aktivieren
        if (!isset($_GET['yprint-bank-transaction-webhook'])) {
            return;
        }
        
        // Webhook-Verarbeitung
        $payload = file_get_contents('php://input');
        $headers = $this->get_request_headers();
        
        // Debug-Log für Webhook-Anfragen
        if ($this->debug_mode) {
            $this->log('Bank Transaction Webhook empfangen: ' . substr($payload, 0, 500) . '...');
        }
        
        // API-Key überprüfen
        $api_key = isset($headers['x-api-key']) ? $headers['x-api-key'] : '';
        $expected_api_key = get_option('yprint_bank_webhook_api_key', '');
        
        if (empty($expected_api_key) || $api_key !== $expected_api_key) {
            $this->log('Bank Transaction Webhook: Ungültiger API-Key', 'error');
            status_header(401);
            die('Unauthorized');
        }
        
        try {
            // JSON Payload verarbeiten
            $data = json_decode($payload, true);
            
            if (!$data || !isset($data['transactions']) || !is_array($data['transactions'])) {
                throw new Exception('Ungültiger Payload - fehlerhafte Struktur');
            }
            
            $processed = 0;
            $matched = 0;
            
            // Transaktionen verarbeiten
            foreach ($data['transactions'] as $transaction) {
                $processed++;
                
                // Überprüfen, ob alle erforderlichen Felder vorhanden sind
                if (!isset($transaction['reference']) || !isset($transaction['amount']) || !isset($transaction['date'])) {
                    continue;
                }
                
                // Referenz extrahieren
                $reference = trim($transaction['reference']);
                
                // Nach Bestellungen mit dieser Referenz suchen
                $orders = wc_get_orders(array(
                    'meta_key' => '_bank_transfer_reference',
                    'meta_value' => $reference,
                    'limit' => 1
                ));
                
                if (empty($orders)) {
                    continue;
                }
                
                $order = $orders[0];
                
                // Prüfen, ob die Zahlung bereits bestätigt wurde
            if (get_post_meta($order->get_id(), '_bank_transfer_confirmed', true)) {
                continue;
            }
            
            // Betrag überprüfen
            $order_total = $order->get_total();
            $transaction_amount = floatval($transaction['amount']);
            
            // Betrag muss mit einer Toleranz von 0.01 übereinstimmen
            if (abs($order_total - $transaction_amount) > 0.01) {
                $this->log(sprintf(
                    'Bank Transaction Webhook: Betrag stimmt nicht überein für Bestellung %s. Erwartet: %s, Erhalten: %s',
                    $order->get_id(),
                    $order_total,
                    $transaction_amount
                ), 'warning');
                continue;
            }
            
            // Zahlung bestätigen
            update_post_meta($order->get_id(), '_bank_transfer_confirmed', true);
            update_post_meta($order->get_id(), '_bank_transfer_confirmation_date', current_time('mysql'));
            update_post_meta($order->get_id(), '_bank_transfer_confirmation_source', 'webhook');
            update_post_meta($order->get_id(), '_bank_transfer_transaction_date', $transaction['date']);
            
            // Bestellstatus aktualisieren
            if ($order->get_status() === 'on-hold') {
                $order->update_status('processing', 'Zahlung per Banküberweisung eingegangen (automatisch via Webhook bestätigt).');
            }
            
            // Aktion auslösen
            do_action('yprint_bank_transfer_payment_confirmed', $order);
            
            $matched++;
        }
        
        $this->log(sprintf('Bank Transaction Webhook: %d Transaktionen verarbeitet, %d Übereinstimmungen gefunden', $processed, $matched));
        
        status_header(200);
        echo json_encode(array(
            'success' => true,
            'processed' => $processed,
            'matched' => $matched
        ));
        exit;
    } catch (Exception $e) {
        $this->log('Bank Transaction Webhook Fehler: ' . $e->getMessage(), 'error');
        status_header(400);
        echo json_encode(array(
            'success' => false,
            'error' => $e->getMessage()
        ));
        exit;
    }
}

/**
 * Fügt eine Admin-Benachrichtigung hinzu
 * 
 * @param string $message Die Nachricht
 * @param string $type Der Typ (success, error, warning, info)
 */
private function add_admin_notice($message, $type = 'info') {
    // Notices in der Session speichern
    $notices = get_transient('yprint_admin_notices') ?: array();
    $notices[] = array(
        'message' => $message,
        'type' => $type
    );
    set_transient('yprint_admin_notices', $notices, 60);
    
    // Admin-Notice-Hook hinzufügen, falls noch nicht vorhanden
    if (!has_action('admin_notices', array($this, 'display_admin_notices'))) {
        add_action('admin_notices', array($this, 'display_admin_notices'));
    }
}

/**
 * Zeigt Admin-Benachrichtigungen an
 */
public function display_admin_notices() {
    $notices = get_transient('yprint_admin_notices');
    if (!$notices) {
        return;
    }
    
    foreach ($notices as $notice) {
        $class = 'notice notice-' . $notice['type'];
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($notice['message']));
    }
    
    // Notices löschen
    delete_transient('yprint_admin_notices');
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
        'bank_transfer' => true,
        'bank_transfer_email' => true,
        'bank_transfer_webhook' => false,
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
        error_log('[YPrint Bank Transfer] [' . $level_name . '] ' . $message);
    }
}

/**
 * Gibt die Hauptinstanz von YPrint_Bank_Transfer zurück
 * 
 * @return YPrint_Bank_Transfer
 */
function YPrint_Bank_Transfer() {
    return YPrint_Bank_Transfer::instance();
}

// Globale für Abwärtskompatibilität
$GLOBALS['yprint_bank_transfer'] = YPrint_Bank_Transfer();