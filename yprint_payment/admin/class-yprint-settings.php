<?php
/**
 * Einstellungsverwaltung für YPrint Payment
 *
 * Diese Klasse verwaltet alle Einstellungen des YPrint Payment Plugins,
 * einschließlich Gateway-Konfigurationen, Feature-Flags und allgemeinen Optionen.
 * Sie bietet Methoden zum Registrieren, Abrufen und Aktualisieren von Einstellungen.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * YPrint Settings-Klasse
 */
class YPrint_Settings {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_Settings
     */
    protected static $_instance = null;

    /**
     * Default-Einstellungen
     *
     * @var array
     */
    private $defaults = array();

    /**
     * Einstellungs-Bereiche
     *
     * @var array
     */
    private $sections = array();

    /**
     * Feature Flags Manager
     *
     * @var YPrint_Feature_Flags
     */
    private $feature_flags;

    /**
     * Hauptinstanz der YPrint_Settings-Klasse
     *
     * @return YPrint_Settings - Hauptinstanz
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
        
        // Einstellungsbereiche definieren
        $this->define_sections();
        
        // Standardeinstellungen setzen
        $this->define_defaults();
        
        // Hooks initialisieren
        $this->init_hooks();
    }

    /**
     * Initialisierung der Hooks
     */
    private function init_hooks() {
        // Einstellungen registrieren
        add_action('admin_init', array($this, 'register_settings'));
        
        // Filter für den Zugriff auf Einstellungswerte
        add_filter('yprint_get_setting', array($this, 'get_setting'), 10, 3);
    }

    /**
     * Definiert die Einstellungsbereiche
     */
    private function define_sections() {
        $this->sections = array(
            'general' => array(
                'id' => 'general',
                'title' => __('Allgemein', 'yprint-payment'),
                'description' => __('Allgemeine Einstellungen für das YPrint Payment Plugin', 'yprint-payment'),
                'option_group' => 'yprint_general_settings',
                'option_name' => 'yprint_general_settings'
            ),
            'stripe' => array(
                'id' => 'stripe',
                'title' => __('Stripe', 'yprint-payment'),
                'description' => __('Einstellungen für die Stripe-Integration', 'yprint-payment'),
                'option_group' => 'yprint_stripe_settings',
                'option_name' => 'yprint_stripe_settings'
            ),
            'paypal' => array(
                'id' => 'paypal',
                'title' => __('PayPal', 'yprint-payment'),
                'description' => __('Einstellungen für die PayPal-Integration', 'yprint-payment'),
                'option_group' => 'yprint_paypal_settings',
                'option_name' => 'yprint_paypal_settings'
            ),
            'sepa' => array(
                'id' => 'sepa',
                'title' => __('SEPA', 'yprint-payment'),
                'description' => __('Einstellungen für die SEPA-Integration', 'yprint-payment'),
                'option_group' => 'yprint_sepa_settings',
                'option_name' => 'yprint_sepa_settings'
            ),
            'bank_transfer' => array(
                'id' => 'bank_transfer',
                'title' => __('Banküberweisung', 'yprint-payment'),
                'description' => __('Einstellungen für die Banküberweisung', 'yprint-payment'),
                'option_group' => 'yprint_bank_transfer_settings',
                'option_name' => 'yprint_bank_transfer_settings'
            ),
            'logging' => array(
                'id' => 'logging',
                'title' => __('Protokollierung', 'yprint-payment'),
                'description' => __('Einstellungen für die Protokollierung und Fehlerbehandlung', 'yprint-payment'),
                'option_group' => 'yprint_logging_settings',
                'option_name' => 'yprint_logging_settings'
            )
        );
    }

    /**
     * Definiert die Standardeinstellungen
     */
    private function define_defaults() {
        // Allgemeine Einstellungen
        $this->defaults['general'] = array(
            'enabled' => 'yes',
            'shop_name' => get_bloginfo('name'),
            'thank_you_page' => 0,
            'debug_mode' => 'no',
            'country' => 'DE',
            'currency' => 'EUR',
            'default_language' => 'de'
        );
        
        // Stripe-Einstellungen
        $this->defaults['stripe'] = array(
            'enabled' => 'yes',
            'test_mode' => 'yes',
            'test_publishable_key' => 'INSERT_API_KEY_HERE',
            'test_secret_key' => 'INSERT_API_KEY_HERE',
            'test_webhook_secret' => 'INSERT_API_KEY_HERE',
            'live_publishable_key' => 'INSERT_API_KEY_HERE',
            'live_secret_key' => 'INSERT_API_KEY_HERE',
            'live_webhook_secret' => 'INSERT_API_KEY_HERE',
            'payment_title' => __('Kreditkarte (Stripe)', 'yprint-payment'),
            'payment_description' => __('Bezahlen Sie sicher mit Ihrer Kredit- oder Debitkarte.', 'yprint-payment'),
            'sca_enabled' => 'yes',
            'statement_descriptor' => 'YPrint.de'
        );
        
        // PayPal-Einstellungen
        $this->defaults['paypal'] = array(
            'enabled' => 'yes',
            'test_mode' => 'yes',
            'test_client_id' => 'INSERT_API_KEY_HERE',
            'test_secret_key' => 'INSERT_API_KEY_HERE',
            'test_webhook_id' => 'INSERT_API_KEY_HERE',
            'live_client_id' => 'INSERT_API_KEY_HERE',
            'live_secret_key' => 'INSERT_API_KEY_HERE',
            'live_webhook_id' => 'INSERT_API_KEY_HERE',
            'payment_title' => __('PayPal', 'yprint-payment'),
            'payment_description' => __('Bezahlen Sie mit Ihrem PayPal-Konto.', 'yprint-payment'),
            'smart_buttons' => 'yes'
        );
        
        // SEPA-Einstellungen
        $this->defaults['sepa'] = array(
            'enabled' => 'yes',
            'test_mode' => 'yes',
            'api_key' => 'INSERT_API_KEY_HERE',
            'api_secret' => 'INSERT_API_KEY_HERE',
            'creditor_id' => '',
            'company_name' => get_bloginfo('name'),
            'mandate_type' => 'oneoff',
            'payment_title' => __('SEPA-Lastschrift', 'yprint-payment'),
            'payment_description' => __('Bezahlen Sie bequem per Lastschrift von Ihrem Bankkonto.', 'yprint-payment'),
            'mandate_text' => __(
                'Ich ermächtige {company}, Zahlungen von meinem Konto mittels SEPA-Lastschrift einzuziehen. '.
                'Zugleich weise ich mein Kreditinstitut an, die von {company} auf mein Konto gezogenen Lastschriften einzulösen. '.
                'Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. '.
                'Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.',
                'yprint-payment'
            )
        );
        
        // Banküberweisung-Einstellungen
        $this->defaults['bank_transfer'] = array(
            'enabled' => 'yes',
            'payment_title' => __('Banküberweisung', 'yprint-payment'),
            'payment_description' => __('Bezahlen Sie per Banküberweisung. Ihre Bestellung wird nach Zahlungseingang bearbeitet.', 'yprint-payment'),
            'account_holder' => '',
            'bank_name' => '',
            'iban' => '',
            'bic' => '',
            'instructions' => __(
                'Bitte überweisen Sie den Betrag auf das folgende Konto. Verwenden Sie Ihre Bestellnummer als Verwendungszweck, '.
                'damit wir Ihre Zahlung zuordnen können.',
                'yprint-payment'
            )
        );
        
        // Logging-Einstellungen
        $this->defaults['logging'] = array(
            'enabled' => 'yes',
            'log_level' => 'error',
            'retention_days' => 30,
            'detailed_errors' => 'no',
            'email_notifications' => 'no',
            'email_recipient' => get_option('admin_email')
        );
    }

    /**
     * Einstellungen registrieren
     */
    public function register_settings() {
        // Alle Einstellungsbereiche registrieren
        foreach ($this->sections as $section) {
            // Einstellungsgruppe registrieren
            register_setting(
                $section['option_group'],
                $section['option_name'],
                array(
                    'sanitize_callback' => array($this, 'sanitize_settings'),
                    'default' => $this->defaults[$section['id']]
                )
            );
            
            // Einstellungssektionen registrieren
            add_settings_section(
                'yprint_' . $section['id'] . '_section',
                $section['title'],
                array($this, 'render_section_description'),
                'yprint_' . $section['id'] . '_settings'
            );
            
            // Felder für jeden Bereich basierend auf den Defaults registrieren
            if (isset($this->defaults[$section['id']])) {
                $this->register_section_fields($section['id'], $this->defaults[$section['id']]);
            }
        }
    }

    /**
     * Rendert die Sektionsbeschreibung
     * 
     * @param array $args Argumente der Sektion
     */
    public function render_section_description($args) {
        $section_id = str_replace('yprint_', '', str_replace('_section', '', $args['id']));
        
        if (isset($this->sections[$section_id]['description'])) {
            echo '<p>' . esc_html($this->sections[$section_id]['description']) . '</p>';
        }
    }

    /**
     * Registriert Einstellungsfelder für eine Sektion
     * 
     * @param string $section_id ID der Sektion
     * @param array $fields Felder der Sektion
     */
    private function register_section_fields($section_id, $fields) {
        foreach ($fields as $field_id => $default_value) {
            // Feldtyp bestimmen
            $type = $this->determine_field_type($field_id, $default_value);
            
            // Feldtitel generieren
            $title = $this->get_field_title($field_id);
            
            // Feld registrieren
            add_settings_field(
                'yprint_' . $section_id . '_' . $field_id,
                $title,
                array($this, 'render_field'),
                'yprint_' . $section_id . '_settings',
                'yprint_' . $section_id . '_section',
                array(
                    'section' => $section_id,
                    'field' => $field_id,
                    'type' => $type,
                    'default' => $default_value,
                    'label_for' => 'yprint_' . $section_id . '_' . $field_id
                )
            );
        }
    }

    /**
     * Feld-Typ basierend auf ID und Wert bestimmen
     * 
     * @param string $field_id ID des Feldes
     * @param mixed $value Wert des Feldes
     * @return string Feldtyp
     */
    private function determine_field_type($field_id, $value) {
        // Spezielle Feldtypen basierend auf ID
        if (strpos($field_id, 'enabled') !== false || 
            strpos($field_id, 'mode') !== false || 
            strpos($field_id, 'debug') !== false ||
            strpos($field_id, 'notifications') !== false ||
            strpos($field_id, 'detailed_errors') !== false ||
            strpos($field_id, 'smart_buttons') !== false ||
            strpos($field_id, 'sca_enabled') !== false) {
            return 'checkbox';
        }
        
        if (strpos($field_id, 'key') !== false || 
            strpos($field_id, 'secret') !== false ||
            strpos($field_id, 'password') !== false) {
            return 'password';
        }
        
        if (strpos($field_id, 'page') !== false) {
            return 'page';
        }
        
        if (strpos($field_id, 'description') !== false || 
            strpos($field_id, 'instructions') !== false ||
            strpos($field_id, 'text') !== false) {
            return 'textarea';
        }
        
        if (strpos($field_id, 'email') !== false) {
            return 'email';
        }
        
        if (strpos($field_id, 'country') !== false) {
            return 'country';
        }
        
        if (strpos($field_id, 'currency') !== false) {
            return 'currency';
        }
        
        if (strpos($field_id, 'language') !== false) {
            return 'language';
        }
        
        if (strpos($field_id, 'level') !== false) {
            return 'select';
        }
        
        if (strpos($field_id, 'days') !== false) {
            return 'number';
        }
        
        // Standard-Feldtyp
        return 'text';
    }

    /**
     * Generiert einen benutzerfreundlichen Feldtitel aus der Feld-ID
     * 
     * @param string $field_id ID des Feldes
     * @return string Feldtitel
     */
    private function get_field_title($field_id) {
        // Unterstriche durch Leerzeichen ersetzen und ersten Buchstaben groß schreiben
        $title = str_replace('_', ' ', $field_id);
        $title = ucfirst($title);
        
        // Spezielle Fälle behandeln
        $special_cases = array(
            'Iban' => 'IBAN',
            'Bic' => 'BIC',
            'Api' => 'API',
            'Sca' => 'SCA',
            'Id' => 'ID',
            'Url' => 'URL',
            'Webhook' => 'Webhook',
            'Sepa' => 'SEPA'
        );
        
        foreach ($special_cases as $search => $replace) {
            $title = str_replace($search, $replace, $title);
        }
        
        return $title;
    }

    /**
     * Rendert ein Einstellungsfeld
     * 
     * @param array $args Feld-Argumente
     */
    public function render_field($args) {
        $section = $args['section'];
        $field = $args['field'];
        $type = $args['type'];
        $default = $args['default'];
        $option_name = $this->sections[$section]['option_name'];
        
        // Aktuellen Wert abrufen
        $options = get_option($option_name, array());
        $value = isset($options[$field]) ? $options[$field] : $default;
        
        // Field ID und Name
        $field_id = 'yprint_' . $section . '_' . $field;
        $field_name = $option_name . '[' . $field . ']';
        
        // Feld basierend auf Typ rendern
        switch ($type) {
            case 'checkbox':
                $this->render_checkbox_field($field_id, $field_name, $value);
                break;
                
            case 'password':
                $this->render_password_field($field_id, $field_name, $value);
                break;
                
            case 'textarea':
                $this->render_textarea_field($field_id, $field_name, $value);
                break;
                
            case 'page':
                $this->render_page_field($field_id, $field_name, $value);
                break;
                
            case 'email':
                $this->render_email_field($field_id, $field_name, $value);
                break;
                
            case 'country':
                $this->render_country_field($field_id, $field_name, $value);
                break;
                
            case 'currency':
                $this->render_currency_field($field_id, $field_name, $value);
                break;
                
            case 'language':
                $this->render_language_field($field_id, $field_name, $value);
                break;
                
            case 'select':
                $this->render_select_field($field_id, $field_name, $value, $field);
                break;
                
            case 'number':
                $this->render_number_field($field_id, $field_name, $value);
                break;
                
            default:
                $this->render_text_field($field_id, $field_name, $value);
                break;
        }
        
        // Feldbeschreibung hinzufügen, wenn verfügbar
        if ($desc = $this->get_field_description($section, $field)) {
            echo '<p class="description">' . esc_html($desc) . '</p>';
        }
    }

    /**
     * Rendert ein Textfeld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param string $value Feld-Wert
     */
    private function render_text_field($id, $name, $value) {
        ?>
        <input type="text" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" 
               value="<?php echo esc_attr($value); ?>" class="regular-text">
        <?php
    }

    /**
     * Rendert ein Passwortfeld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param string $value Feld-Wert
     */
    private function render_password_field($id, $name, $value) {
        ?>
        <input type="password" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" 
               value="<?php echo esc_attr($value); ?>" class="regular-text">
        <?php
    }

    /**
     * Rendert ein Textarea-Feld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param string $value Feld-Wert
     */
    private function render_textarea_field($id, $name, $value) {
        ?>
        <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" 
                  class="large-text" rows="5"><?php echo esc_textarea($value); ?></textarea>
        <?php
    }

    /**
     * Rendert ein Checkbox-Feld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param string $value Feld-Wert
     */
    private function render_checkbox_field($id, $name, $value) {
        ?>
        <label for="<?php echo esc_attr($id); ?>">
            <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" 
                   value="yes" <?php checked($value, 'yes'); ?>>
            <?php _e('Aktivieren', 'yprint-payment'); ?>
        </label>
        <?php
    }

    /**
     * Rendert ein Seitenauswahl-Feld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param int $value Feld-Wert
     */
    private function render_page_field($id, $name, $value) {
        wp_dropdown_pages(array(
            'name' => $name,
            'id' => $id,
            'selected' => $value,
            'show_option_none' => __('Standard-Seite auswählen', 'yprint-payment')
        ));
    }

    /**
     * Rendert ein E-Mail-Feld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param string $value Feld-Wert
     */
    private function render_email_field($id, $name, $value) {
        ?>
        <input type="email" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" 
               value="<?php echo esc_attr($value); ?>" class="regular-text">
        <?php
    }

    /**
     * Rendert ein Länderauswahl-Feld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param string $value Feld-Wert
     */
    private function render_country_field($id, $name, $value) {
        $countries = $this->get_countries();
        ?>
        <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>">
            <?php foreach ($countries as $code => $country) : ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($value, $code); ?>>
                    <?php echo esc_html($country); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Rendert ein Währungsauswahl-Feld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param string $value Feld-Wert
     */
    private function render_currency_field($id, $name, $value) {
        $currencies = $this->get_currencies();
        ?>
        <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>">
            <?php foreach ($currencies as $code => $currency) : ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($value, $code); ?>>
                    <?php echo esc_html($currency . ' (' . $code . ')'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Rendert ein Sprachauswahl-Feld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param string $value Feld-Wert
     */
    private function render_language_field($id, $name, $value) {
        $languages = $this->get_languages();
        ?>
        <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>">
            <?php foreach ($languages as $code => $language) : ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($value, $code); ?>>
                    <?php echo esc_html($language); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Rendert ein Auswahlfeld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param string $value Feld-Wert
     * @param string $field_id Feld-ID für spezifische Optionen
     */
    private function render_select_field($id, $name, $value, $field_id) {
        $options = $this->get_select_options($field_id);
        ?>
        <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>">
            <?php foreach ($options as $option_value => $option_label) : ?>
                <option value="<?php echo esc_attr($option_value); ?>" <?php selected($value, $option_value); ?>>
                    <?php echo esc_html($option_label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Rendert ein Zahlenfeld
     * 
     * @param string $id Feld-ID
     * @param string $name Feld-Name
     * @param int $value Feld-Wert
     */
    private function render_number_field($id, $name, $value) {
        ?>
        <input type="number" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" 
               value="<?php echo esc_attr($value); ?>" class="small-text" min="1" step="1">
        <?php
    }

    /**
     * Holt Beschreibung für ein Feld
     *
     * @param string $section_id Abschnitts-ID
     * @param string $field_id Feld-ID
     * @return string Beschreibung oder leerer String
     */
    private function get_field_description($section_id, $field_id) {
        $descriptions = array(
            // Allgemeine Einstellungen
            'general' => array(
                'enabled' => __('Aktivieren Sie das Plugin global.', 'yprint-payment'),
                'shop_name' => __('Name Ihres Shops für Rechnungen und E-Mails.', 'yprint-payment'),
                'thank_you_page' => __('Seite, auf die Kunden nach erfolgreicher Zahlung weitergeleitet werden.', 'yprint-payment'),
                'debug_mode' => __('Aktiviert ausführliche Protokollierung. Nur für Entwicklungs- und Fehlerbehebungszwecke verwenden.', 'yprint-payment'),
                'country' => __('Standardland für Rechnungen und Steuerberechnungen.', 'yprint-payment'),
                'currency' => __('Standardwährung für alle Transaktionen.', 'yprint-payment'),
                'default_language' => __('Standardsprache für Texte und E-Mails.', 'yprint-payment'),
            ),
            
            // Stripe-Einstellungen
            'stripe' => array(
                'enabled' => __('Aktivieren Sie Stripe als Zahlungsmethode.', 'yprint-payment'),
                'test_mode' => __('Aktivieren Sie den Testmodus, um Zahlungen zu simulieren, ohne echte Transaktionen durchzuführen.', 'yprint-payment'),
                'test_publishable_key' => __('Der Stripe Test Publishable Key. Beginnt mit "pk_test_".', 'yprint-payment'),
                'test_secret_key' => __('Der Stripe Test Secret Key. Beginnt mit "sk_test_".', 'yprint-payment'),
                'test_webhook_secret' => __('Der Stripe Test Webhook Secret. Beginnt mit "whsec_".', 'yprint-payment'),
                'live_publishable_key' => __('Der Stripe Live Publishable Key. Beginnt mit "pk_live_".', 'yprint-payment'),
'live_secret_key' => __('Der Stripe Live Secret Key. Beginnt mit "sk_live_".', 'yprint-payment'),
                'live_webhook_secret' => __('Der Stripe Live Webhook Secret. Beginnt mit "whsec_".', 'yprint-payment'),
                'payment_title' => __('Titel für die Stripe-Zahlungsmethode, wie er den Kunden angezeigt wird.', 'yprint-payment'),
                'payment_description' => __('Beschreibung für die Stripe-Zahlungsmethode, wie sie den Kunden angezeigt wird.', 'yprint-payment'),
                'sca_enabled' => __('Aktiviert die Strong Customer Authentication (SCA) für EU-Zahlungen.', 'yprint-payment'),
                'statement_descriptor' => __('Beschreibung, die auf der Kreditkartenabrechnung des Kunden erscheint.', 'yprint-payment'),
            ),
            
            // PayPal-Einstellungen
            'paypal' => array(
                'enabled' => __('Aktivieren Sie PayPal als Zahlungsmethode.', 'yprint-payment'),
                'test_mode' => __('Aktivieren Sie den Testmodus, um Zahlungen zu simulieren, ohne echte Transaktionen durchzuführen.', 'yprint-payment'),
                'test_client_id' => __('Die PayPal Sandbox Client-ID.', 'yprint-payment'),
                'test_secret_key' => __('Der PayPal Sandbox Secret-Key.', 'yprint-payment'),
                'test_webhook_id' => __('Die PayPal Sandbox Webhook-ID.', 'yprint-payment'),
                'live_client_id' => __('Die PayPal Live Client-ID.', 'yprint-payment'),
                'live_secret_key' => __('Der PayPal Live Secret-Key.', 'yprint-payment'),
                'live_webhook_id' => __('Die PayPal Live Webhook-ID.', 'yprint-payment'),
                'payment_title' => __('Titel für die PayPal-Zahlungsmethode, wie er den Kunden angezeigt wird.', 'yprint-payment'),
                'payment_description' => __('Beschreibung für die PayPal-Zahlungsmethode, wie sie den Kunden angezeigt wird.', 'yprint-payment'),
                'smart_buttons' => __('Aktiviert die PayPal Smart Buttons für ein moderneres Checkout-Erlebnis.', 'yprint-payment'),
            ),
            
            // SEPA-Einstellungen
            'sepa' => array(
                'enabled' => __('Aktivieren Sie SEPA-Lastschrift als Zahlungsmethode.', 'yprint-payment'),
                'test_mode' => __('Aktivieren Sie den Testmodus, um Zahlungen zu simulieren, ohne echte Transaktionen durchzuführen.', 'yprint-payment'),
                'api_key' => __('Der API-Schlüssel für den SEPA-Dienstleister.', 'yprint-payment'),
                'api_secret' => __('Der API-Secret für den SEPA-Dienstleister.', 'yprint-payment'),
                'creditor_id' => __('Ihre SEPA-Gläubiger-ID (z.B. DE98ZZZ09999999999).', 'yprint-payment'),
                'company_name' => __('Ihr Firmenname für SEPA-Mandate.', 'yprint-payment'),
                'mandate_type' => __('Art des SEPA-Mandats (einmalig oder wiederkehrend).', 'yprint-payment'),
                'payment_title' => __('Titel für die SEPA-Zahlungsmethode, wie er den Kunden angezeigt wird.', 'yprint-payment'),
                'payment_description' => __('Beschreibung für die SEPA-Zahlungsmethode, wie sie den Kunden angezeigt wird.', 'yprint-payment'),
                'mandate_text' => __('Text des SEPA-Mandats, das Kunden akzeptieren müssen. Verwenden Sie {company} als Platzhalter für Ihren Firmennamen.', 'yprint-payment'),
            ),
            
            // Banküberweisung-Einstellungen
            'bank_transfer' => array(
                'enabled' => __('Aktivieren Sie Banküberweisung als Zahlungsmethode.', 'yprint-payment'),
                'payment_title' => __('Titel für die Banküberweisung, wie er den Kunden angezeigt wird.', 'yprint-payment'),
                'payment_description' => __('Beschreibung für die Banküberweisung, wie sie den Kunden angezeigt wird.', 'yprint-payment'),
                'account_holder' => __('Der Name des Kontoinhabers.', 'yprint-payment'),
                'bank_name' => __('Der Name der Bank.', 'yprint-payment'),
                'iban' => __('Die internationale Bankkontonummer (IBAN).', 'yprint-payment'),
                'bic' => __('Die Bankleitzahl (BIC/SWIFT).', 'yprint-payment'),
                'instructions' => __('Zusätzliche Anweisungen für die Kunden zur Durchführung der Überweisung.', 'yprint-payment'),
            ),
            
            // Logging-Einstellungen
            'logging' => array(
                'enabled' => __('Aktiviert die Protokollierung von Ereignissen und Fehlern.', 'yprint-payment'),
                'log_level' => __('Legt fest, wie detailliert die Protokollierung sein soll.', 'yprint-payment'),
                'retention_days' => __('Anzahl der Tage, die Protokolle aufbewahrt werden sollen.', 'yprint-payment'),
                'detailed_errors' => __('Aktiviert ausführliche Fehlerberichte für Entwickler.', 'yprint-payment'),
                'email_notifications' => __('Sendet E-Mail-Benachrichtigungen bei kritischen Fehlern.', 'yprint-payment'),
                'email_recipient' => __('E-Mail-Adresse, an die Fehlerbenachrichtigungen gesendet werden sollen.', 'yprint-payment'),
            ),
        );
        
        return isset($descriptions[$section_id][$field_id]) ? $descriptions[$section_id][$field_id] : '';
    }

    /**
     * Holt Optionen für ein Select-Feld
     *
     * @param string $field_id Feld-ID
     * @return array Array von Optionen
     */
    private function get_select_options($field_id) {
        $options = array();
        
        // Log-Level-Optionen
        if ($field_id === 'log_level') {
            $options = array(
                'error' => __('Nur Fehler', 'yprint-payment'),
                'warning' => __('Warnungen und Fehler', 'yprint-payment'),
                'info' => __('Info, Warnungen und Fehler', 'yprint-payment'),
                'debug' => __('Debug (ausführlich)', 'yprint-payment')
            );
        }
        
        // Mandate-Type-Optionen
        if ($field_id === 'mandate_type') {
            $options = array(
                'oneoff' => __('Einmalig', 'yprint-payment'),
                'recurring' => __('Wiederkehrend', 'yprint-payment')
            );
        }
        
        return $options;
    }

    /**
     * Holt Liste aller Länder
     *
     * @return array Array von Ländern
     */
    private function get_countries() {
        return array(
            'DE' => __('Deutschland', 'yprint-payment'),
            'AT' => __('Österreich', 'yprint-payment'),
            'CH' => __('Schweiz', 'yprint-payment'),
            'BE' => __('Belgien', 'yprint-payment'),
            'NL' => __('Niederlande', 'yprint-payment'),
            'FR' => __('Frankreich', 'yprint-payment'),
            'IT' => __('Italien', 'yprint-payment'),
            'GB' => __('Großbritannien', 'yprint-payment'),
            'US' => __('Vereinigte Staaten', 'yprint-payment'),
            // Weitere Länder je nach Bedarf...
        );
    }

    /**
     * Holt Liste aller Währungen
     *
     * @return array Array von Währungen
     */
    private function get_currencies() {
        return array(
            'EUR' => __('Euro', 'yprint-payment'),
            'USD' => __('US-Dollar', 'yprint-payment'),
            'GBP' => __('Britisches Pfund', 'yprint-payment'),
            'CHF' => __('Schweizer Franken', 'yprint-payment'),
            // Weitere Währungen je nach Bedarf...
        );
    }

    /**
     * Holt Liste aller Sprachen
     *
     * @return array Array von Sprachen
     */
    private function get_languages() {
        return array(
            'de' => __('Deutsch', 'yprint-payment'),
            'en' => __('Englisch', 'yprint-payment'),
            'fr' => __('Französisch', 'yprint-payment'),
            'it' => __('Italienisch', 'yprint-payment'),
            'es' => __('Spanisch', 'yprint-payment'),
            // Weitere Sprachen je nach Bedarf...
        );
    }

    /**
     * Sanitiert Einstellungswerte vor dem Speichern
     *
     * @param array $input Eingegebene Werte
     * @return array Sanitierte Werte
     */
    public function sanitize_settings($input) {
        if (!is_array($input)) {
            return array();
        }
        
        $sanitized_input = array();
        
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $sanitized_input[$key] = $this->sanitize_settings($value);
            } else {
                switch ($key) {
                    case 'email_recipient':
                        $sanitized_input[$key] = sanitize_email($value);
                        break;
                        
                    case 'iban':
                    case 'bic':
                        $sanitized_input[$key] = strtoupper(preg_replace('/\s+/', '', $value));
                        break;
                        
                    case 'instructions':
                    case 'mandate_text':
                    case 'payment_description':
                        $sanitized_input[$key] = wp_kses_post($value);
                        break;
                        
                    case 'enabled':
                    case 'test_mode':
                    case 'debug_mode':
                    case 'detailed_errors':
                    case 'email_notifications':
                    case 'smart_buttons':
                    case 'sca_enabled':
                        $sanitized_input[$key] = ($value === 'yes') ? 'yes' : 'no';
                        break;
                        
                    case 'retention_days':
                        $sanitized_input[$key] = absint($value);
                        break;
                        
                    default:
                        $sanitized_input[$key] = sanitize_text_field($value);
                        break;
                }
            }
        }
        
        return $sanitized_input;
    }

    /**
     * Abrufen einer Einstellung
     *
     * @param mixed $default Standardwert, wenn Einstellung nicht existiert
     * @param string $section_id Abschnitts-ID
     * @param string $field_id Feld-ID
     * @return mixed Wert der Einstellung
     */
    public function get_setting($default, $section_id, $field_id) {
        // Prüfen, ob Abschnitt existiert
        if (!isset($this->sections[$section_id])) {
            return $default;
        }
        
        // Optionen abrufen
        $option_name = $this->sections[$section_id]['option_name'];
        $options = get_option($option_name, array());
        
        // Wenn Feld existiert, Wert zurückgeben, sonst Standardwert
        if (isset($options[$field_id])) {
            $value = $options[$field_id];
            
            // Bestimmte Feldtypen behandeln
            if (in_array($field_id, array('enabled', 'test_mode', 'debug_mode', 'detailed_errors', 'email_notifications', 'smart_buttons', 'sca_enabled'))) {
                return $value === 'yes';
            }
            
            return $value;
        }
        
        // Standardwert aus defaults holen, falls verfügbar
        if (isset($this->defaults[$section_id][$field_id])) {
            $default_value = $this->defaults[$section_id][$field_id];
            
            // Bestimmte Feldtypen behandeln
            if (in_array($field_id, array('enabled', 'test_mode', 'debug_mode', 'detailed_errors', 'email_notifications', 'smart_buttons', 'sca_enabled'))) {
                return $default_value === 'yes';
            }
            
            return $default_value;
        }
        
        return $default;
    }

    /**
     * Prüft, ob ein Gateway aktiviert ist
     *
     * @param string $gateway Gateway-Name
     * @return bool True, wenn aktiviert
     */
    public function is_gateway_enabled($gateway) {
        return $this->get_setting(false, $gateway, 'enabled');
    }

    /**
     * Gibt die API-Schlüssel für ein Gateway zurück
     *
     * @param string $gateway Gateway-Name
     * @return array Array von API-Schlüsseln
     */
    public function get_gateway_keys($gateway) {
        $is_test_mode = $this->get_setting(true, $gateway, 'test_mode');
        $keys = array();
        
        switch ($gateway) {
            case 'stripe':
                if ($is_test_mode) {
                    $keys['publishable_key'] = $this->get_setting('', $gateway, 'test_publishable_key');
                    $keys['secret_key'] = $this->get_setting('', $gateway, 'test_secret_key');
                    $keys['webhook_secret'] = $this->get_setting('', $gateway, 'test_webhook_secret');
                } else {
                    $keys['publishable_key'] = $this->get_setting('', $gateway, 'live_publishable_key');
                    $keys['secret_key'] = $this->get_setting('', $gateway, 'live_secret_key');
                    $keys['webhook_secret'] = $this->get_setting('', $gateway, 'live_webhook_secret');
                }
                break;
                
            case 'paypal':
                if ($is_test_mode) {
                    $keys['client_id'] = $this->get_setting('', $gateway, 'test_client_id');
                    $keys['secret_key'] = $this->get_setting('', $gateway, 'test_secret_key');
                    $keys['webhook_id'] = $this->get_setting('', $gateway, 'test_webhook_id');
                } else {
                    $keys['client_id'] = $this->get_setting('', $gateway, 'live_client_id');
                    $keys['secret_key'] = $this->get_setting('', $gateway, 'live_secret_key');
                    $keys['webhook_id'] = $this->get_setting('', $gateway, 'live_webhook_id');
                }
                break;
                
            case 'sepa':
                $keys['api_key'] = $this->get_setting('', $gateway, 'api_key');
                $keys['api_secret'] = $this->get_setting('', $gateway, 'api_secret');
                $keys['creditor_id'] = $this->get_setting('', $gateway, 'creditor_id');
                break;
                
            case 'bank_transfer':
                $keys['account_holder'] = $this->get_setting('', $gateway, 'account_holder');
                $keys['bank_name'] = $this->get_setting('', $gateway, 'bank_name');
                $keys['iban'] = $this->get_setting('', $gateway, 'iban');
                $keys['bic'] = $this->get_setting('', $gateway, 'bic');
                break;
        }
        
        return $keys;
    }

    /**
     * Gibt den Zahlungstitel für ein Gateway zurück
     *
     * @param string $gateway Gateway-Name
     * @return string Zahlungstitel
     */
    public function get_payment_title($gateway) {
        return $this->get_setting('', $gateway, 'payment_title');
    }

    /**
     * Gibt die Zahlungsbeschreibung für ein Gateway zurück
     *
     * @param string $gateway Gateway-Name
     * @return string Zahlungsbeschreibung
     */
    public function get_payment_description($gateway) {
        return $this->get_setting('', $gateway, 'payment_description');
    }

    /**
     * Prüft, ob der Debug-Modus aktiviert ist
     *
     * @return bool True, wenn Debug-Modus aktiviert
     */
    public function is_debug_mode() {
        return $this->get_setting(false, 'general', 'debug_mode');
    }

    /**
     * Gibt alle aktiven Zahlungsgateways zurück
     *
     * @return array Array aktiver Gateways
     */
    public function get_active_gateways() {
        $active_gateways = array();
        $all_gateways = array('stripe', 'paypal', 'sepa', 'bank_transfer');
        
        foreach ($all_gateways as $gateway) {
            if ($this->is_gateway_enabled($gateway)) {
                $active_gateways[] = $gateway;
            }
        }
        
        return $active_gateways;
    }

    /**
     * Gibt den Danke-Seiten-Link zurück
     *
     * @return string URL der Danke-Seite
     */
    public function get_thank_you_page_url() {
        $page_id = $this->get_setting(0, 'general', 'thank_you_page');
        
        if ($page_id > 0) {
            return get_permalink($page_id);
        }
        
        return home_url('/thank-you/');
    }

    /**
     * Aktualisiert eine Einstellung
     *
     * @param string $section_id Abschnitts-ID
     * @param string $field_id Feld-ID
     * @param mixed $value Neuer Wert
     * @return bool True bei Erfolg
     */
    public function update_setting($section_id, $field_id, $value) {
        // Prüfen, ob Abschnitt existiert
        if (!isset($this->sections[$section_id])) {
            return false;
        }
        
        // Wert sanitieren
        $value = $this->sanitize_field_value($field_id, $value);
        
        // Optionen abrufen und aktualisieren
        $option_name = $this->sections[$section_id]['option_name'];
        $options = get_option($option_name, array());
        $options[$field_id] = $value;
        
        // Aktualisierte Optionen speichern
        $result = update_option($option_name, $options);
        
        // Feature-Flag aktualisieren, falls zutreffend
        if ($result && $field_id === 'enabled' && $this->feature_flags !== null) {
            $flag_name = $section_id . '_integration';
            
            if ($value === 'yes') {
                $this->feature_flags->enable($flag_name);
            } else {
                $this->feature_flags->disable($flag_name);
            }
        }
        
        return $result;
    }

    /**
     * Sanitiert einen einzelnen Feldwert basierend auf dem Feldtyp
     *
     * @param string $field_id Feld-ID
     * @param mixed $value Wert
     * @return mixed Sanitierter Wert
     */
    private function sanitize_field_value($field_id, $value) {
        switch ($field_id) {
            case 'email_recipient':
                return sanitize_email($value);
                
            case 'iban':
            case 'bic':
                return strtoupper(preg_replace('/\s+/', '', $value));
                
            case 'instructions':
            case 'mandate_text':
            case 'payment_description':
                return wp_kses_post($value);
                
            case 'enabled':
            case 'test_mode':
            case 'debug_mode':
            case 'detailed_errors':
            case 'email_notifications':
            case 'smart_buttons':
            case 'sca_enabled':
                return ($value === true || $value === 'yes') ? 'yes' : 'no';
                
            case 'retention_days':
                return absint($value);
                
            default:
                return is_array($value) ? $value : sanitize_text_field($value);
        }
    }

    /**
     * Setzt alle Einstellungen auf die Standardwerte zurück
     *
     * @return bool True bei Erfolg
     */
    public function reset_settings() {
        $success = true;
        
        foreach ($this->sections as $section_id => $section) {
            if (isset($this->defaults[$section_id])) {
                $option_name = $section['option_name'];
                $result = update_option($option_name, $this->defaults[$section_id]);
                $success = $success && $result;
            }
        }
        
        return $success;
    }

    /**
     * Exportiert alle Einstellungen als JSON
     *
     * @return string JSON-String mit allen Einstellungen
     */
    public function export_settings() {
        $settings = array();
        
        foreach ($this->sections as $section_id => $section) {
            $option_name = $section['option_name'];
            $settings[$section_id] = get_option($option_name, $this->defaults[$section_id] ?? array());
        }
        
        return json_encode($settings);
    }

    /**
     * Importiert Einstellungen aus einem JSON-String
     *
     * @param string $json JSON-String mit Einstellungen
     * @return bool True bei Erfolg
     */
    public function import_settings($json) {
        $settings = json_decode($json, true);
        
        if (!is_array($settings)) {
            return false;
        }
        
        $success = true;
        
        foreach ($settings as $section_id => $section_settings) {
            if (isset($this->sections[$section_id]) && is_array($section_settings)) {
                $option_name = $this->sections[$section_id]['option_name'];
                $sanitized_settings = $this->sanitize_settings($section_settings);
                $result = update_option($option_name, $sanitized_settings);
                $success = $success && $result;
            }
        }
        
        return $success;
    }
}

// Hilfsfunktion für globalen Zugriff
function yprint_settings() {
    return YPrint_Settings::instance();
}