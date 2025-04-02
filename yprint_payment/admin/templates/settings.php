<?php
/**
 * Admin settings template for YPrint Payment
 *
 * This template provides the main settings interface for the YPrint Payment plugin.
 * It includes tab navigation and content areas for different settings sections.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get current tab
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';

// Define tabs and their labels
$tabs = array(
    'general'       => __('Allgemein', 'yprint-payment'),
    'stripe'        => __('Stripe', 'yprint-payment'),
    'paypal'        => __('PayPal', 'yprint-payment'),
    'sepa'          => __('SEPA', 'yprint-payment'),
    'bank_transfer' => __('Banküberweisung', 'yprint-payment'),
    'webhooks'      => __('Webhooks', 'yprint-payment'),
    'features'      => __('Features', 'yprint-payment'),
    'logs'          => __('Logs', 'yprint-payment'),
    'status'        => __('Status', 'yprint-payment')
);

// Get feature flags instance
$feature_flags = class_exists('YPrint_Feature_Flags') ? YPrint_Feature_Flags::instance() : null;

// Get API instance
$api = class_exists('YPrint_API') ? YPrint_API::instance() : null;

// Get settings instance
$settings = class_exists('YPrint_Settings') ? yprint_settings() : null;
?>

<div class="wrap yprint-payment-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div id="yprint-admin-notice" class="notice is-dismissible" style="display: none;"></div>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($tabs as $tab_key => $tab_label) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=yprint-payment-settings&tab=' . $tab_key)); ?>" 
               class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tab_label); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <div class="yprint-admin-content">
        <form id="yprint-settings-form" method="post" action="options.php" class="yprint-settings-form">
            <?php
            // Output security fields
            if ($current_tab !== 'status' && $current_tab !== 'logs') {
                settings_fields('yprint_' . $current_tab . '_settings');
            }
            
            // General Settings Tab
            if ($current_tab === 'general') : ?>
                <h2><?php _e('Allgemeine Einstellungen', 'yprint-payment'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="yprint_shop_name"><?php _e('Shop-Name', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_shop_name" name="yprint_general_settings[shop_name]" 
                                   value="<?php echo esc_attr(get_option('yprint_shop_name', get_bloginfo('name'))); ?>" 
                                   class="regular-text">
                            <p class="description"><?php _e('Wird in E-Mails und auf Zahlungsseiten angezeigt.', 'yprint-payment'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_thank_you_page"><?php _e('Danke-Seite', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <?php
                            $thank_you_page_id = get_option('yprint_thank_you_page', 0);
                            wp_dropdown_pages(array(
                                'name' => 'yprint_general_settings[thank_you_page]',
                                'id' => 'yprint_thank_you_page',
                                'selected' => $thank_you_page_id,
                                'show_option_none' => __('Standard-Dankeseite verwenden', 'yprint-payment')
                            ));
                            ?>
                            <p class="description">
                                <?php _e('Seite, auf die der Kunde nach erfolgreicher Zahlung weitergeleitet wird.', 'yprint-payment'); ?>
                                <?php if ($thank_you_page_id > 0) : ?>
                                    <a href="<?php echo get_permalink($thank_you_page_id); ?>" target="_blank">
                                        <?php _e('Seite anzeigen', 'yprint-payment'); ?>
                                    </a>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_debug_mode"><?php _e('Debug-Modus', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="yprint_debug_mode" name="yprint_general_settings[debug_mode]" value="1" 
                                       <?php checked(get_option('yprint_debug_mode', '0'), '1'); ?>>
                                <?php _e('Debug-Modus aktivieren', 'yprint-payment'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Aktiviert detaillierte Protokollierung und Debug-Informationen.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_log_retention"><?php _e('Log-Aufbewahrung', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <select id="yprint_log_retention" name="yprint_general_settings[log_retention]">
                                <option value="7" <?php selected(get_option('yprint_log_retention', '30'), '7'); ?>>
                                    <?php _e('7 Tage', 'yprint-payment'); ?>
                                </option>
                                <option value="14" <?php selected(get_option('yprint_log_retention', '30'), '14'); ?>>
                                    <?php _e('14 Tage', 'yprint-payment'); ?>
                                </option>
                                <option value="30" <?php selected(get_option('yprint_log_retention', '30'), '30'); ?>>
                                    <?php _e('30 Tage', 'yprint-payment'); ?>
                                </option>
                                <option value="60" <?php selected(get_option('yprint_log_retention', '30'), '60'); ?>>
                                    <?php _e('60 Tage', 'yprint-payment'); ?>
                                </option>
                                <option value="90" <?php selected(get_option('yprint_log_retention', '30'), '90'); ?>>
                                    <?php _e('90 Tage', 'yprint-payment'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php _e('Zeitraum, für den Protokolle aufbewahrt werden sollen.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" id="yprint_save_general_settings" class="button button-primary">
                        <?php _e('Einstellungen speichern', 'yprint-payment'); ?>
                    </button>
                </p>
            
            <?php
            // Stripe Settings Tab
            elseif ($current_tab === 'stripe') :
                // Check if Stripe is enabled
                $stripe_enabled = $feature_flags ? $feature_flags->is_enabled('stripe_integration') : true;
            ?>
                <h2><?php _e('Stripe-Einstellungen', 'yprint-payment'); ?></h2>
                
                <div class="notice notice-info inline">
                    <p>
                        <?php _e('Stripe ist eine sichere Zahlungsplattform, die Kredit- und Debitkartenzahlungen ermöglicht. Du benötigst ein Stripe-Konto, um diese Zahlungsmethode zu aktivieren.', 'yprint-payment'); ?>
                        <a href="https://stripe.com" target="_blank">
                            <?php _e('Mehr über Stripe erfahren', 'yprint-payment'); ?>
                        </a>
                    </p>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="yprint_stripe_enabled"><?php _e('Stripe aktivieren', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="yprint_stripe_enabled" name="yprint_stripe_settings[enabled]" value="1" 
                                       <?php checked($stripe_enabled, true); ?>>
                                <?php _e('Stripe als Zahlungsmethode aktivieren', 'yprint-payment'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_stripe_test_mode"><?php _e('Testmodus', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="yprint_stripe_test_mode" name="yprint_stripe_settings[test_mode]" value="yes" 
                                       <?php checked(get_option('yprint_stripe_test_mode', 'no'), 'yes'); ?>>
                                <?php _e('Testmodus aktivieren', 'yprint-payment'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Im Testmodus werden keine echten Zahlungen verarbeitet. Verwende Testeinkaufsdaten zum Testen.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr class="stripe-api-keys">
                        <th scope="row" colspan="2">
                            <h3><?php _e('Produktionsschlüssel (Live)', 'yprint-payment'); ?></h3>
                        </th>
                    </tr>
                    
                    <tr class="stripe-api-keys">
                        <th scope="row">
                            <label for="yprint_stripe_public_key"><?php _e('Öffentlicher Schlüssel', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_stripe_public_key" name="yprint_stripe_settings[public_key]" 
                                   value="<?php echo esc_attr(get_option('yprint_stripe_public_key', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Beginnt mit "pk_live_".', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr class="stripe-api-keys">
                        <th scope="row">
                            <label for="yprint_stripe_secret_key"><?php _e('Geheimer Schlüssel', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="yprint_stripe_secret_key" name="yprint_stripe_settings[secret_key]" 
                                   value="<?php echo esc_attr(get_option('yprint_stripe_secret_key', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Beginnt mit "sk_live_".', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr class="stripe-api-keys">
                        <th scope="row">
                            <label for="yprint_stripe_webhook_secret"><?php _e('Webhook-Secret', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="yprint_stripe_webhook_secret" name="yprint_stripe_settings[webhook_secret]" 
                                   value="<?php echo esc_attr(get_option('yprint_stripe_webhook_secret', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Beginnt mit "whsec_".', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr class="stripe-api-keys">
                        <th scope="row" colspan="2">
                            <h3><?php _e('Testschlüssel', 'yprint-payment'); ?></h3>
                        </th>
                    </tr>
                    
                    <tr class="stripe-api-keys">
                        <th scope="row">
                            <label for="yprint_stripe_test_public_key"><?php _e('Test-Öffentlicher Schlüssel', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_stripe_test_public_key" name="yprint_stripe_settings[test_public_key]" 
                                   value="<?php echo esc_attr(get_option('yprint_stripe_test_public_key', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Beginnt mit "pk_test_".', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr class="stripe-api-keys">
                        <th scope="row">
                            <label for="yprint_stripe_test_secret_key"><?php _e('Test-Geheimer Schlüssel', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="yprint_stripe_test_secret_key" name="yprint_stripe_settings[test_secret_key]" 
                                   value="<?php echo esc_attr(get_option('yprint_stripe_test_secret_key', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Beginnt mit "sk_test_".', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr class="stripe-api-keys">
                        <th scope="row">
                            <label for="yprint_stripe_test_webhook_secret"><?php _e('Test-Webhook-Secret', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="yprint_stripe_test_webhook_secret" name="yprint_stripe_settings[test_webhook_secret]" 
                                   value="<?php echo esc_attr(get_option('yprint_stripe_test_webhook_secret', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Beginnt mit "whsec_".', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label><?php _e('Verbindung testen', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <button type="button" id="yprint_test_stripe_connection" class="button">
                                <?php _e('Verbindung zu Stripe testen', 'yprint-payment'); ?>
                            </button>
                            <span id="yprint_stripe_connection_result"></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label><?php _e('Webhook-URL', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <code><?php echo home_url('/?yprint-stripe-webhook=1'); ?></code>
                            <p class="description">
                                <?php _e('Füge diese URL in deinem Stripe-Dashboard unter Entwickler &rarr; Webhooks hinzu.', 'yprint-payment'); ?>
                            </p>
                            <button type="button" id="yprint_create_stripe_webhook" class="button" data-gateway="stripe">
                                <?php _e('Webhook automatisch erstellen', 'yprint-payment'); ?>
                            </button>
                            <span id="yprint_stripe_webhook_result"></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row" colspan="2">
                            <h3><?php _e('Stripe Checkout-Einstellungen', 'yprint-payment'); ?></h3>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_stripe_sca_support"><?php _e('SCA-Unterstützung', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="yprint_stripe_sca_support" name="yprint_stripe_settings[sca_support]" value="1" 
                                       <?php checked($feature_flags ? $feature_flags->is_enabled('stripe_sca_support') : true, true); ?>>
                                <?php _e('Starke Kundenauthentifizierung (SCA) aktivieren', 'yprint-payment'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Erforderlich für Händler in der EU. Aktiviert 3D Secure und andere Authentifizierungsmethoden.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_stripe_payment_title"><?php _e('Zahlungstitel', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_stripe_payment_title" name="yprint_stripe_settings[payment_title]" 
                                   value="<?php echo esc_attr(get_option('yprint_stripe_payment_title', __('Kreditkarte (Stripe)', 'yprint-payment'))); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Der Titel, der den Kunden angezeigt wird.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_stripe_payment_description"><?php _e('Zahlungsbeschreibung', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="yprint_stripe_payment_description" name="yprint_stripe_settings[payment_description]" 
                                      class="large-text" rows="3"><?php echo esc_textarea(get_option('yprint_stripe_payment_description', __('Bezahlen Sie sicher mit Ihrer Kredit- oder Debitkarte.', 'yprint-payment'))); ?></textarea>
                            <p class="description">
                                <?php _e('Die Beschreibung, die den Kunden angezeigt wird.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" id="yprint_save_stripe_settings" class="button button-primary">
                        <?php _e('Einstellungen speichern', 'yprint-payment'); ?>
                    </button>
                </p>

            <?php
            // PayPal Settings Tab
            elseif ($current_tab === 'paypal') :
                // Check if PayPal is enabled
                $paypal_enabled = $feature_flags ? $feature_flags->is_enabled('paypal_integration') : true;
            ?>
                <h2><?php _e('PayPal-Einstellungen', 'yprint-payment'); ?></h2>
                
                <div class="notice notice-info inline">
                    <p>
                        <?php _e('PayPal ermöglicht es deinen Kunden, mit ihrem PayPal-Konto oder per Kreditkarte zu bezahlen. Du benötigst ein PayPal-Geschäftskonto, um diese Zahlungsmethode zu aktivieren.', 'yprint-payment'); ?>
                        <a href="https://www.paypal.com/de/business" target="_blank">
                            <?php _e('Mehr über PayPal erfahren', 'yprint-payment'); ?>
                        </a>
                    </p>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="yprint_paypal_enabled"><?php _e('PayPal aktivieren', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="yprint_paypal_enabled" name="yprint_paypal_settings[enabled]" value="1" 
                                       <?php checked($paypal_enabled, true); ?>>
                                <?php _e('PayPal als Zahlungsmethode aktivieren', 'yprint-payment'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_paypal_test_mode"><?php _e('Testmodus', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="yprint_paypal_test_mode" name="yprint_paypal_settings[test_mode]" value="yes" 
                                       <?php checked(get_option('yprint_paypal_test_mode', 'no'), 'yes'); ?>>
                                <?php _e('Testmodus aktivieren (Sandbox)', 'yprint-payment'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Im Testmodus werden keine echten Zahlungen verarbeitet. Verwende PayPal-Sandboxdaten zum Testen.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr class="paypal-api-keys">
                        <th scope="row" colspan="2">
                            <h3><?php _e('Produktionsschlüssel (Live)', 'yprint-payment'); ?></h3>
                        </th>
                    </tr>
                    
                    <tr class="paypal-api-keys">
                        <th scope="row">
                            <label for="yprint_paypal_client_id"><?php _e('Client ID', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_paypal_client_id" name="yprint_paypal_settings[client_id]" 
                                   value="<?php echo esc_attr(get_option('yprint_paypal_client_id', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr class="paypal-api-keys">
                        <th scope="row">
                            <label for="yprint_paypal_secret_key"><?php _e('Secret Key', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="yprint_paypal_secret_key" name="yprint_paypal_settings[secret_key]" 
                                   value="<?php echo esc_attr(get_option('yprint_paypal_secret_key', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr class="paypal-api-keys">
                        <th scope="row">
                            <label for="yprint_paypal_webhook_id"><?php _e('Webhook ID', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_paypal_webhook_id" name="yprint_paypal_settings[webhook_id]" 
                                   value="<?php echo esc_attr(get_option('yprint_paypal_webhook_id', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr class="paypal-api-keys">
                        <th scope="row" colspan="2">
                            <h3><?php _e('Testschlüssel (Sandbox)', 'yprint-payment'); ?></h3>
                        </th>
                    </tr>
                    
                    <tr class="paypal-api-keys">
                        <th scope="row">
                            <label for="yprint_paypal_test_client_id"><?php _e('Sandbox Client ID', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_paypal_test_client_id" name="yprint_paypal_settings[test_client_id]" 
                                   value="<?php echo esc_attr(get_option('yprint_paypal_test_client_id', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr class="paypal-api-keys">
                        <th scope="row">
                            <label for="yprint_paypal_test_secret_key"><?php _e('Sandbox Secret Key', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="yprint_paypal_test_secret_key" name="yprint_paypal_settings[test_secret_key]" 
                                   value="<?php echo esc_attr(get_option('yprint_paypal_test_secret_key', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr class="paypal-api-keys">
                        <th scope="row">
                            <label for="yprint_paypal_test_webhook_id"><?php _e('Sandbox Webhook ID', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_paypal_test_webhook_id" name="yprint_paypal_settings[test_webhook_id]" 
                                   value="<?php echo esc_attr(get_option('yprint_paypal_test_webhook_id', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label><?php _e('Verbindung testen', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <button type="button" id="yprint_test_paypal_connection" class="button">
                                <?php _e('Verbindung zu PayPal testen', 'yprint-payment'); ?>
                            </button>
                            <span id="yprint_paypal_connection_result"></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label><?php _e('Webhook-URL', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <code><?php echo home_url('/?yprint-paypal-webhook=1'); ?></code>
                            <p class="description">
                                <?php _e('Füge diese URL in deinem PayPal-Dashboard unter Einstellungen &rarr; Webhook-Einstellungen hinzu.', 'yprint-payment'); ?>
                            </p>
                            <button type="button" id="yprint_create_paypal_webhook" class="button" data-gateway="paypal">
                                <?php _e('Webhook automatisch erstellen', 'yprint-payment'); ?>
                            </button>
                            <span id="yprint_paypal_webhook_result"></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row" colspan="2">
                            <h3><?php _e('PayPal Checkout-Einstellungen', 'yprint-payment'); ?></h3>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_paypal_smart_buttons"><?php _e('Smart Buttons', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="yprint_paypal_smart_buttons" name="yprint_paypal_settings[smart_buttons]" value="1" 
                                       <?php checked($feature_flags ? $feature_flags->is_enabled('paypal_smart_buttons') : true, true); ?>>
                                <?php _e('PayPal Smart Payment Buttons aktivieren', 'yprint-payment'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Moderne, anpassbare PayPal-Buttons für ein besseres Checkout-Erlebnis.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_paypal_payment_title"><?php _e('Zahlungstitel', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_paypal_payment_title" name="yprint_paypal_settings[payment_title]" 
                                   value="<?php echo esc_attr(get_option('yprint_paypal_payment_title', __('PayPal', 'yprint-payment'))); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Der Titel, der den Kunden angezeigt wird.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_paypal_payment_description"><?php _e('Zahlungsbeschreibung', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="yprint_paypal_payment_description" name="yprint_paypal_settings[payment_description]" 
                                      class="large-text" rows="3"><?php echo esc_textarea(get_option('yprint_paypal_payment_description', __('Bezahlen Sie schnell und sicher mit Ihrem PayPal-Konto.', 'yprint-payment'))); ?></textarea>
                            <p class="description">
                                <?php _e('Die Beschreibung, die den Kunden angezeigt wird.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" id="yprint_save_paypal_settings" class="button button-primary">
                        <?php _e('Einstellungen speichern', 'yprint-payment'); ?>
                    </button>
                </p>

            <?php
            // SEPA Settings Tab
            elseif ($current_tab === 'sepa') :
                // Check if SEPA is enabled
                $sepa_enabled = $feature_flags ? $feature_flags->is_enabled('sepa_integration') : true;
            ?>
                <h2><?php _e('SEPA-Einstellungen', 'yprint-payment'); ?></h2>
                
                <div class="notice notice-info inline">
                    <p>
                        <?php _e('SEPA (Single Euro Payments Area) ermöglicht es deinen Kunden, per Lastschrift zu bezahlen. Diese Zahlungsmethode ist hauptsächlich in Europa verfügbar.', 'yprint-payment'); ?>
                    </p>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="yprint_sepa_enabled"><?php _e('SEPA aktivieren', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="yprint_sepa_enabled" name="yprint_sepa_settings[enabled]" value="1" 
                                       <?php checked($sepa_enabled, true); ?>>
                                <?php _e('SEPA-Lastschrift als Zahlungsmethode aktivieren', 'yprint-payment'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_sepa_test_mode"><?php _e('Testmodus', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="yprint_sepa_test_mode" name="yprint_sepa_settings[test_mode]" value="yes" 
                                       <?php checked(get_option('yprint_sepa_test_mode', 'no'), 'yes'); ?>>
                                <?php _e('Testmodus aktivieren', 'yprint-payment'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Im Testmodus werden keine echten Zahlungen verarbeitet.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_sepa_api_key"><?php _e('API-Schlüssel', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_sepa_api_key" name="yprint_sepa_settings[api_key]" 
                                   value="<?php echo esc_attr(get_option('yprint_sepa_api_key', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_sepa_api_secret"><?php _e('API-Secret', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="yprint_sepa_api_secret" name="yprint_sepa_settings[api_secret]" 
                                   value="<?php echo esc_attr(get_option('yprint_sepa_api_secret', 'INSERT_API_KEY_HERE')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_sepa_creditor_id"><?php _e('Gläubiger-ID', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_sepa_creditor_id" name="yprint_sepa_settings[creditor_id]" 
                                   value="<?php echo esc_attr(get_option('yprint_sepa_creditor_id', '')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Deine SEPA-Gläubiger-ID (z.B. DE98ZZZ09999999999).', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_sepa_company_name"><?php _e('Firmenname', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_sepa_company_name" name="yprint_sepa_settings[company_name]" 
                                   value="<?php echo esc_attr(get_option('yprint_sepa_company_name', get_bloginfo('name'))); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Name deines Unternehmens für SEPA-Mandate.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row" colspan="2">
                            <h3><?php _e('SEPA-Checkout-Einstellungen', 'yprint-payment'); ?></h3>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_sepa_mandate_type"><?php _e('Mandatstyp', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <select id="yprint_sepa_mandate_type" name="yprint_sepa_settings[mandate_type]">
                                <option value="oneoff" <?php selected(get_option('yprint_sepa_mandate_type', 'oneoff'), 'oneoff'); ?>>
                                    <?php _e('Einmalig', 'yprint-payment'); ?>
                                </option>
                                <option value="recurring" <?php selected(get_option('yprint_sepa_mandate_type', 'oneoff'), 'recurring'); ?>>
                                    <?php _e('Wiederkehrend', 'yprint-payment'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php _e('Art des SEPA-Mandats.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_sepa_mandate_text"><?php _e('Mandatstext', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="yprint_sepa_mandate_text" name="yprint_sepa_settings[mandate_text]" 
                                      class="large-text" rows="5"><?php echo esc_textarea(get_option('yprint_sepa_mandate_text', __('Ich ermächtige {company}, Zahlungen von meinem Konto mittels SEPA-Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von {company} auf mein Konto gezogenen Lastschriften einzulösen. Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.', 'yprint-payment'))); ?></textarea>
                            <p class="description">
                                <?php _e('Der Text, der dem Kunden angezeigt wird. Verwende {company} als Platzhalter für deinen Firmennamen.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_sepa_payment_title"><?php _e('Zahlungstitel', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_sepa_payment_title" name="yprint_sepa_settings[payment_title]" 
                                   value="<?php echo esc_attr(get_option('yprint_sepa_payment_title', __('SEPA-Lastschrift', 'yprint-payment'))); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Der Titel, der den Kunden angezeigt wird.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_sepa_payment_description"><?php _e('Zahlungsbeschreibung', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="yprint_sepa_payment_description" name="yprint_sepa_settings[payment_description]" 
                                      class="large-text" rows="3"><?php echo esc_textarea(get_option('yprint_sepa_payment_description', __('Bezahlen Sie bequem per Lastschrift von Ihrem Bankkonto.', 'yprint-payment'))); ?></textarea>
                            <p class="description">
                                <?php _e('Die Beschreibung, die den Kunden angezeigt wird.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" id="yprint_save_sepa_settings" class="button button-primary">
                        <?php _e('Einstellungen speichern', 'yprint-payment'); ?>
                    </button>
                </p>

            <?php
            // Bank Transfer Settings Tab
            elseif ($current_tab === 'bank_transfer') :
                // Check if Bank Transfer is enabled
                $bank_transfer_enabled = $feature_flags ? $feature_flags->is_enabled('bank_transfer') : true;
            ?>
                <h2><?php _e('Banküberweisung-Einstellungen', 'yprint-payment'); ?></h2>
                
                <div class="notice notice-info inline">
                    <p>
                        <?php _e('Die Banküberweisung ermöglicht es deinen Kunden, direkt auf dein Bankkonto zu überweisen. Die Bestellung wird erst nach Zahlungseingang bearbeitet.', 'yprint-payment'); ?>
                    </p>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="yprint_bank_transfer_enabled"><?php _e('Banküberweisung aktivieren', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="yprint_bank_transfer_enabled" name="yprint_bank_transfer_settings[enabled]" value="1" 
                                       <?php checked($bank_transfer_enabled, true); ?>>
                                <?php _e('Banküberweisung als Zahlungsmethode aktivieren', 'yprint-payment'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_bank_account_holder"><?php _e('Kontoinhaber', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_bank_account_holder" name="yprint_bank_transfer_settings[account_holder]" 
                                   value="<?php echo esc_attr(get_option('yprint_bank_account_holder', '')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_bank_account_bank"><?php _e('Bank', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_bank_account_bank" name="yprint_bank_transfer_settings[account_bank]" 
                                   value="<?php echo esc_attr(get_option('yprint_bank_account_bank', '')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_bank_account_iban"><?php _e('IBAN', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_bank_account_iban" name="yprint_bank_transfer_settings[account_iban]" 
                                   value="<?php echo esc_attr(get_option('yprint_bank_account_iban', '')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_bank_account_bic"><?php _e('BIC', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_bank_account_bic" name="yprint_bank_transfer_settings[account_bic]" 
                                   value="<?php echo esc_attr(get_option('yprint_bank_account_bic', '')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_bank_transfer_instructions"><?php _e('Zahlungsanweisungen', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="yprint_bank_transfer_instructions" name="yprint_bank_transfer_settings[instructions]" 
                                      class="large-text" rows="5"><?php echo esc_textarea(get_option('yprint_bank_transfer_instructions', __('Bitte überweisen Sie den Betrag auf das folgende Konto. Verwenden Sie Ihre Bestellnummer als Verwendungszweck, damit wir Ihre Zahlung zuordnen können.', 'yprint-payment'))); ?></textarea>
                            <p class="description">
                                <?php _e('Anweisungen für die Kunden, wie sie die Überweisung durchführen sollen.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_bank_transfer_payment_title"><?php _e('Zahlungstitel', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="yprint_bank_transfer_payment_title" name="yprint_bank_transfer_settings[payment_title]" 
                                   value="<?php echo esc_attr(get_option('yprint_bank_transfer_payment_title', __('Banküberweisung', 'yprint-payment'))); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Der Titel, der den Kunden angezeigt wird.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="yprint_bank_transfer_payment_description"><?php _e('Zahlungsbeschreibung', 'yprint-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="yprint_bank_transfer_payment_description" name="yprint_bank_transfer_settings[payment_description]" 
                                      class="large-text" rows="3"><?php echo esc_textarea(get_option('yprint_bank_transfer_payment_description', __('Bezahlen Sie per Banküberweisung. Ihre Bestellung wird nach Zahlungseingang bearbeitet.', 'yprint-payment'))); ?></textarea>
                            <p class="description">
                                <?php _e('Die Beschreibung, die den Kunden angezeigt wird.', 'yprint-payment'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" id="yprint_save_bank_transfer_settings" class="button button-primary">
                        <?php _e('Einstellungen speichern', 'yprint-payment'); ?>
                    </button>
                </p>

            <?php
            // Webhooks Tab
            elseif ($current_tab === 'webhooks') :
            ?>
                <h2><?php _e('Webhook-Einstellungen', 'yprint-payment'); ?></h2>
                
                <div class="notice notice-info inline">
                    <p>
                        <?php _e('Webhooks ermöglichen es Zahlungsanbietern, mit deinem Shop zu kommunizieren und Zahlungsstatusaktualisierungen zu senden.', 'yprint-payment'); ?>
                    </p>
                </div>
                
                <h3><?php _e('Webhook-URLs', 'yprint-payment'); ?></h3>
                <p><?php _e('Füge diese URLs in den entsprechenden Zahlungsanbieter-Dashboards hinzu:', 'yprint-payment'); ?></p>
                
                <table class="widefat" style="max-width: 800px;">
                    <thead>
                        <tr>
                            <th><?php _e('Zahlungsanbieter', 'yprint-payment'); ?></th>
                            <th><?php _e('Webhook-URL', 'yprint-payment'); ?></th>
                            <th><?php _e('Status', 'yprint-payment'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Stripe</td>
                            <td><code><?php echo home_url('/?yprint-stripe-webhook=1'); ?></code></td>
                            <td>
                                <?php 
                                $stripe_webhook_id = get_option('yprint_stripe_webhook_secret') ? 
                                    '<span class="dashicons dashicons-yes" style="color:green;"></span> ' . __('Konfiguriert', 'yprint-payment') : 
                                    '<span class="dashicons dashicons-no" style="color:red;"></span> ' . __('Nicht konfiguriert', 'yprint-payment');
                                echo $stripe_webhook_id;
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>PayPal</td>
                            <td><code><?php echo home_url('/?yprint-paypal-webhook=1'); ?></code></td>
                            <td>
                                <?php 
                                $paypal_webhook_id = get_option('yprint_paypal_webhook_id') ? 
                                    '<span class="dashicons dashicons-yes" style="color:green;"></span> ' . __('Konfiguriert', 'yprint-payment') : 
                                    '<span class="dashicons dashicons-no" style="color:red;"></span> ' . __('Nicht konfiguriert', 'yprint-payment');
                                echo $paypal_webhook_id;
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>SEPA</td>
                            <td><code><?php echo home_url('/?yprint-sepa-webhook=1'); ?></code></td>
                            <td>
                                <?php 
                                $sepa_webhook_id = get_option('yprint_sepa_webhook_id') ? 
                                    '<span class="dashicons dashicons-yes" style="color:green;"></span> ' . __('Konfiguriert', 'yprint-payment') : 
                                    '<span class="dashicons dashicons-no" style="color:red;"></span> ' . __('Nicht konfiguriert', 'yprint-payment');
                                echo $sepa_webhook_id;
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <h3><?php _e('Webhook-Ereignisse', 'yprint-payment'); ?></h3>
                <p><?php _e('Das Plugin verarbeitet folgende Webhook-Ereignisse:', 'yprint-payment'); ?></p>
                
                <table class="widefat" style="max-width: 800px;">
                    <thead>
                        <tr>
                            <th><?php _e('Zahlungsanbieter', 'yprint-payment'); ?></th>
                            <th><?php _e('Ereignistyp', 'yprint-payment'); ?></th>
                            <th><?php _e('Beschreibung', 'yprint-payment'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Stripe</td>
                            <td><code>payment_intent.succeeded</code></td>
                            <td><?php _e('Zahlung erfolgreich', 'yprint-payment'); ?></td>
                        </tr>
                        <tr>
                            <td>Stripe</td>
                            <td><code>payment_intent.payment_failed</code></td>
                            <td><?php _e('Zahlung fehlgeschlagen', 'yprint-payment'); ?></td>
                        </tr>
                        <tr>
                            <td>Stripe</td>
                            <td><code>charge.refunded</code></td>
                            <td><?php _e('Zahlung zurückerstattet', 'yprint-payment'); ?></td>
                        </tr>
                        <tr>
                            <td>PayPal</td>
                            <td><code>PAYMENT.CAPTURE.COMPLETED</code></td>
                            <td><?php _e('Zahlung erfolgreich', 'yprint-payment'); ?></td>
                        </tr>
                        <tr>
                            <td>PayPal</td>
                            <td><code>PAYMENT.CAPTURE.DENIED</code></td>
                            <td><?php _e('Zahlung abgelehnt', 'yprint-payment'); ?></td>
                        </tr>
                        <tr>
                            <td>PayPal</td>
                            <td><code>PAYMENT.CAPTURE.REFUNDED</code></td>
                            <td><?php _e('Zahlung zurückerstattet', 'yprint-payment'); ?></td>
                        </tr>
                        <tr>
                            <td>SEPA</td>
                            <td><code>direct_debit.succeeded</code></td>
                            <td><?php _e('Lastschrift erfolgreich', 'yprint-payment'); ?></td>
                        </tr>
                        <tr>
                            <td>SEPA</td>
                            <td><code>direct_debit.failed</code></td>
                            <td><?php _e('Lastschrift fehlgeschlagen', 'yprint-payment'); ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <h3><?php _e('Webhook-Sicherheit', 'yprint-payment'); ?></h3>
                <p><?php _e('Das Plugin verwendet Signaturvalidierung, um die Authentizität von Webhooks zu überprüfen.', 'yprint-payment'); ?></p>
                
                <p class="submit">
                    <button type="button" id="yprint_test_webhooks" class="button">
                        <?php _e('Webhooks testen', 'yprint-payment'); ?>
                    </button>
                    <span id="yprint_test_webhooks_result"></span>
                </p>

            <?php
            // Features Tab
            elseif ($current_tab === 'features') :
                // Ensure we have feature flags
                if (!$feature_flags) {
                    echo '<div class="notice notice-error"><p>' . __('Feature-Flags-System nicht verfügbar.', 'yprint-payment') . '</p></div>';
                } else {
                    $flags = $feature_flags->get_all_flags();
            ?>
                <h2><?php _e('Feature-Flags', 'yprint-payment'); ?></h2>
                
                <div class="notice notice-info inline">
                    <p>
                        <?php _e('Feature-Flags ermöglichen es, bestimmte Funktionen gezielt zu aktivieren oder zu deaktivieren.', 'yprint-payment'); ?>
                    </p>
                </div>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Feature', 'yprint-payment'); ?></th>
                            <th><?php _e('Beschreibung', 'yprint-payment'); ?></th>
                            <th><?php _e('Status', 'yprint-payment'); ?></th>
                            <th><?php _e('Aktion', 'yprint-payment'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($flags as $flag_name => $enabled) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($flag_name); ?></strong></td>
                                <td><?php echo esc_html($this->get_feature_description($flag_name)); ?></td>
                                <td>
                                    <span class="feature-status <?php echo $enabled ? 'enabled' : 'disabled'; ?>">
                                        <?php echo $enabled ? __('Aktiviert', 'yprint-payment') : __('Deaktiviert', 'yprint-payment'); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="button toggle-feature" 
                                            data-feature="<?php echo esc_attr($flag_name); ?>" 
                                            data-status="<?php echo $enabled ? '1' : '0'; ?>">
                                        <?php echo $enabled ? __('Deaktivieren', 'yprint-payment') : __('Aktivieren', 'yprint-payment'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p class="submit">
                    <button type="button" id="yprint_reset_feature_flags" class="button">
                        <?php _e('Zurücksetzen auf Standardwerte', 'yprint-payment'); ?>
                    </button>
                </p>
            <?php
                }

// Status Tab
elseif ($current_tab === 'status') :
    ?>
        <h2><?php _e('System-Status', 'yprint-payment'); ?></h2>
        
        <h3><?php _e('Plugin-Informationen', 'yprint-payment'); ?></h3>
        <table class="widefat" style="max-width: 600px;">
            <tbody>
                <tr>
                    <td><?php _e('Plugin-Version', 'yprint-payment'); ?></td>
                    <td><?php echo esc_html(YPRINT_PAYMENT_VERSION); ?></td>
                </tr>
                <tr>
                    <td><?php _e('WordPress-Version', 'yprint-payment'); ?></td>
                    <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                </tr>
                <tr>
                    <td><?php _e('WooCommerce-Version', 'yprint-payment'); ?></td>
                    <td><?php echo esc_html(function_exists('WC') ? WC()->version : __('Nicht installiert', 'yprint-payment')); ?></td>
                </tr>
                <tr>
                    <td><?php _e('PHP-Version', 'yprint-payment'); ?></td>
                    <td><?php echo esc_html(phpversion()); ?></td>
                </tr>
                <tr>
                    <td><?php _e('MySQL-Version', 'yprint-payment'); ?></td>
                    <td><?php 
                        global $wpdb;
                        echo esc_html($wpdb->db_version());
                    ?></td>
                </tr>
                <tr>
                    <td><?php _e('Debug-Modus', 'yprint-payment'); ?></td>
                    <td><?php echo defined('WP_DEBUG') && WP_DEBUG ? '<span style="color:green;">' . __('Aktiviert', 'yprint-payment') . '</span>' : '<span style="color:red;">' . __('Deaktiviert', 'yprint-payment') . '</span>'; ?></td>
                </tr>
            </tbody>
        </table>
        
        <h3><?php _e('Zahlungsanbieter-Status', 'yprint-payment'); ?></h3>
        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th><?php _e('Zahlungsanbieter', 'yprint-payment'); ?></th>
                    <th><?php _e('Status', 'yprint-payment'); ?></th>
                    <th><?php _e('Modus', 'yprint-payment'); ?></th>
                    <th><?php _e('Webhook', 'yprint-payment'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Stripe</td>
                    <td>
                        <?php 
                        $stripe_enabled = $feature_flags ? $feature_flags->is_enabled('stripe_integration') : false;
                        echo $stripe_enabled ? 
                            '<span style="color:green;">' . __('Aktiviert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Deaktiviert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>
                        <?php 
                        $stripe_test_mode = get_option('yprint_stripe_test_mode', 'no') === 'yes';
                        echo $stripe_test_mode ? 
                            '<span style="color:orange;">' . __('Test', 'yprint-payment') . '</span>' : 
                            '<span style="color:green;">' . __('Live', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>
                        <?php 
                        $stripe_webhook = get_option('yprint_stripe_webhook_secret', '');
                        echo !empty($stripe_webhook) ? 
                            '<span style="color:green;">' . __('Konfiguriert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Nicht konfiguriert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>PayPal</td>
                    <td>
                        <?php 
                        $paypal_enabled = $feature_flags ? $feature_flags->is_enabled('paypal_integration') : false;
                        echo $paypal_enabled ? 
                            '<span style="color:green;">' . __('Aktiviert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Deaktiviert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>
                        <?php 
                        $paypal_test_mode = get_option('yprint_paypal_test_mode', 'no') === 'yes';
                        echo $paypal_test_mode ? 
                            '<span style="color:orange;">' . __('Sandbox', 'yprint-payment') . '</span>' : 
                            '<span style="color:green;">' . __('Live', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>
                        <?php 
                        $paypal_webhook = get_option('yprint_paypal_webhook_id', '');
                        echo !empty($paypal_webhook) ? 
                            '<span style="color:green;">' . __('Konfiguriert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Nicht konfiguriert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>SEPA</td>
                    <td>
                        <?php 
                        $sepa_enabled = $feature_flags ? $feature_flags->is_enabled('sepa_integration') : false;
                        echo $sepa_enabled ? 
                            '<span style="color:green;">' . __('Aktiviert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Deaktiviert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>
                        <?php 
                        $sepa_test_mode = get_option('yprint_sepa_test_mode', 'no') === 'yes';
                        echo $sepa_test_mode ? 
                            '<span style="color:orange;">' . __('Test', 'yprint-payment') . '</span>' : 
                            '<span style="color:green;">' . __('Live', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>Banküberweisung</td>
                    <td>
                        <?php 
                        $bank_transfer_enabled = $feature_flags ? $feature_flags->is_enabled('bank_transfer') : false;
                        echo $bank_transfer_enabled ? 
                            '<span style="color:green;">' . __('Aktiviert', 'yprint-payment') . '</span>' : 
                            '<span style="color:red;">' . __('Deaktiviert', 'yprint-payment') . '</span>'; 
                        ?>
                    </td>
                    <td>-</td>
                    <td>-</td>
                </tr>
            </tbody>
        </table>
        
        <h3><?php _e('Systemanforderungen', 'yprint-payment'); ?></h3>
        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th><?php _e('Anforderung', 'yprint-payment'); ?></th>
                    <th><?php _e('Empfohlen', 'yprint-payment'); ?></th>
                    <th><?php _e('Aktuell', 'yprint-payment'); ?></th>
                    <th><?php _e('Status', 'yprint-payment'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>PHP</td>
                    <td>&ge; 7.4</td>
                    <td><?php echo phpversion(); ?></td>
                    <td>
                        <?php echo version_compare(phpversion(), '7.4', '>=') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td>MySQL</td>
                    <td>&ge; 5.6</td>
                    <td><?php 
                        global $wpdb;
                        echo $wpdb->db_version();
                    ?></td>
                    <td>
                        <?php echo version_compare($wpdb->db_version(), '5.6', '>=') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td>WordPress</td>
                    <td>&ge; 5.8</td>
                    <td><?php echo get_bloginfo('version'); ?></td>
                    <td>
                        <?php echo version_compare(get_bloginfo('version'), '5.8', '>=') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td>WooCommerce</td>
                    <td>&ge; 6.0</td>
                    <td><?php echo function_exists('WC') ? WC()->version : __('Nicht installiert', 'yprint-payment'); ?></td>
                    <td>
                        <?php 
                        if (!function_exists('WC')) {
                            echo '<span class="dashicons dashicons-no" style="color:red;"></span>';
                        } else {
                            echo version_compare(WC()->version, '6.0', '>=') ? 
                                '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                                '<span class="dashicons dashicons-no" style="color:red;"></span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>cURL</td>
                    <td><?php _e('Aktiviert', 'yprint-payment'); ?></td>
                    <td><?php echo function_exists('curl_version') ? __('Aktiviert', 'yprint-payment') : __('Deaktiviert', 'yprint-payment'); ?></td>
                    <td>
                        <?php echo function_exists('curl_version') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td>JSON</td>
                    <td><?php _e('Aktiviert', 'yprint-payment'); ?></td>
                    <td><?php echo function_exists('json_encode') ? __('Aktiviert', 'yprint-payment') : __('Deaktiviert', 'yprint-payment'); ?></td>
                    <td>
                        <?php echo function_exists('json_encode') ? 
                            '<span class="dashicons dashicons-yes" style="color:green;"></span>' : 
                            '<span class="dashicons dashicons-no" style="color:red;"></span>'; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <h3><?php _e('Fehlersuche', 'yprint-payment'); ?></h3>
        <p>
            <button type="button" id="yprint_clear_plugin_cache" class="button button-secondary">
                <?php _e('Plugin-Cache leeren', 'yprint-payment'); ?>
            </button>
            <button type="button" id="yprint_run_diagnostics" class="button button-secondary">
                <?php _e('Diagnose ausführen', 'yprint-payment'); ?>
            </button>
        </p>
        
        <div id="yprint_diagnostics_result" style="display: none;">
            <h4><?php _e('Diagnoseergebnisse', 'yprint-payment'); ?></h4>
            <pre></pre>
        </div>

    <?php
    // Logs Tab
    elseif ($current_tab === 'logs') :
        $log_directory = WP_CONTENT_DIR . '/uploads/yprint-payment-logs/';
        $logs = array();
        
        if (is_dir($log_directory)) {
            $files = scandir($log_directory);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'log') {
                    $logs[] = $file;
                }
            }
        }
        
        // Selected log file
        $selected_log = isset($_GET['log']) ? sanitize_text_field($_GET['log']) : '';
        $log_content = '';
        
        if ($selected_log && in_array($selected_log, $logs)) {
            $log_path = $log_directory . $selected_log;
            if (file_exists($log_path)) {
                $log_content = file_get_contents($log_path);
            }
        }
    ?>
        <h2><?php _e('Log-Dateien', 'yprint-payment'); ?></h2>
        
        <?php if (empty($logs)) : ?>
            <div class="notice notice-info">
                <p><?php _e('Keine Log-Dateien gefunden.', 'yprint-payment'); ?></p>
            </div>
        <?php else : ?>
            <div class="yprint-log-viewer">
                <div class="yprint-log-navigation">
                    <div class="yprint-log-files">
                        <h3><?php _e('Verfügbare Logs', 'yprint-payment'); ?></h3>
                        <ul>
                            <?php foreach ($logs as $log) : ?>
                                <li>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . sanitize_text_field($_GET['page']) . '&tab=logs&log=' . $log)); ?>" 
                                       class="<?php echo $selected_log === $log ? 'active' : ''; ?>">
                                        <?php echo esc_html($log); ?>
                                    </a>
                                    <a href="#" class="yprint-delete-log" data-log="<?php echo esc_attr($log); ?>">[<?php _e('Löschen', 'yprint-payment'); ?>]</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <?php if (!empty($selected_log)) : ?>
                        <div class="yprint-log-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . sanitize_text_field($_GET['page']) . '&tab=logs&log=' . $selected_log . '&download=1')); ?>" class="button">
                                <?php _e('Herunterladen', 'yprint-payment'); ?>
                            </a>
                            <button type="button" id="yprint_refresh_log" class="button" data-log="<?php echo esc_attr($selected_log); ?>">
                                <?php _e('Aktualisieren', 'yprint-payment'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="yprint-log-content">
                    <?php if (!empty($selected_log)) : ?>
                        <h3><?php echo esc_html($selected_log); ?></h3>
                        <div class="yprint-log-viewer-content">
                            <pre><?php echo esc_html($log_content); ?></pre>
                        </div>
                    <?php else : ?>
                        <div class="notice notice-info inline">
                            <p><?php _e('Wähle eine Log-Datei aus der Liste aus, um den Inhalt anzuzeigen.', 'yprint-payment'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <h3><?php _e('Log-Einstellungen', 'yprint-payment'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="yprint_log_level"><?php _e('Log-Level', 'yprint-payment'); ?></label>
                </th>
                <td>
                    <select id="yprint_log_level" name="yprint_log_settings[log_level]">
                        <option value="error" <?php selected(get_option('yprint_log_level', 'error'), 'error'); ?>>
                            <?php _e('Fehler (minimal)', 'yprint-payment'); ?>
                        </option>
                        <option value="warning" <?php selected(get_option('yprint_log_level', 'error'), 'warning'); ?>>
                            <?php _e('Warnungen', 'yprint-payment'); ?>
                        </option>
                        <option value="info" <?php selected(get_option('yprint_log_level', 'error'), 'info'); ?>>
                            <?php _e('Infos', 'yprint-payment'); ?>
                        </option>
                        <option value="debug" <?php selected(get_option('yprint_log_level', 'error'), 'debug'); ?>>
                            <?php _e('Debug (ausführlich)', 'yprint-payment'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php _e('Je höher der Log-Level, desto mehr Informationen werden protokolliert.', 'yprint-payment'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="button" id="yprint_save_log_settings" class="button button-primary">
                <?php _e('Log-Einstellungen speichern', 'yprint-payment'); ?>
            </button>
            <button type="button" id="yprint_clear_all_logs" class="button">
                <?php _e('Alle Logs löschen', 'yprint-payment'); ?>
            </button>
        </p>
    <?php endif; ?>
</form>
</div>
</div>

<script>
jQuery(document).ready(function($) {
// Toggle for payment gateway settings based on enabled state
$('#yprint_stripe_enabled, #yprint_paypal_enabled, #yprint_sepa_enabled, #yprint_bank_transfer_enabled').on('change', function() {
const gateway = $(this).attr('id').replace('yprint_', '').replace('_enabled', '');
const isEnabled = $(this).prop('checked');

if (gateway === 'stripe') {
    // Toggle Stripe-specific settings
    $('.stripe-api-keys').toggle(isEnabled);
} else if (gateway === 'paypal') {
    // Toggle PayPal-specific settings
    $('.paypal-api-keys').toggle(isEnabled);
} else if (gateway === 'sepa') {
    // Toggle SEPA-specific settings
    $('.sepa-specific-settings').toggle(isEnabled);
} else if (gateway === 'bank_transfer') {
    // Toggle Bank Transfer-specific settings
    $('.bank-transfer-specific-settings').toggle(isEnabled);
}
}).change(); // Trigger on load

// Test connection buttons
$('#yprint_test_stripe_connection').on('click', function() {
const $button = $(this);
const $result = $('#yprint_stripe_connection_result');

$button.prop('disabled', true).text('<?php _e('Verarbeitung...', 'yprint-payment'); ?>');
$result.text('').removeClass('success error');

$.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'yprint_test_stripe_connection',
        security: yprint_admin_params.admin_nonce
    },
    success: function(response) {
        $button.prop('disabled', false).text('<?php _e('Verbindung zu Stripe testen', 'yprint-payment'); ?>');
        
        if (response.success) {
            $result.addClass('success').text('<?php _e('Verbindung erfolgreich!', 'yprint-payment'); ?>');
        } else {
            $result.addClass('error').text('<?php _e('Fehler: ', 'yprint-payment'); ?>' + response.data.message);
        }
    },
    error: function() {
        $button.prop('disabled', false).text('<?php _e('Verbindung zu Stripe testen', 'yprint-payment'); ?>');
        $result.addClass('error').text('<?php _e('Verbindungsfehler!', 'yprint-payment'); ?>');
    }
});
});

$('#yprint_test_paypal_connection').on('click', function() {
const $button = $(this);
const $result = $('#yprint_paypal_connection_result');

$button.prop('disabled', true).text('<?php _e('Verarbeitung...', 'yprint-payment'); ?>');
$result.text('').removeClass('success error');

$.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'yprint_test_paypal_connection',
        security: yprint_admin_params.admin_nonce
    },
    success: function(response) {
        $button.prop('disabled', false).text('<?php _e('Verbindung zu PayPal testen', 'yprint-payment'); ?>');
        
        if (response.success) {
            $result.addClass('success').text('<?php _e('Verbindung erfolgreich!', 'yprint-payment'); ?>');
        } else {
            $result.addClass('error').text('<?php _e('Fehler: ', 'yprint-payment'); ?>' + response.data.message);
        }
    },
    error: function() {
        $button.prop('disabled', false).text('<?php _e('Verbindung zu PayPal testen', 'yprint-payment'); ?>');
        $result.addClass('error').text('<?php _e('Verbindungsfehler!', 'yprint-payment'); ?>');
    }
});
});

// Create webhook buttons
$('.button[id^="yprint_create_"]').on('click', function() {
const $button = $(this);
const gateway = $button.data('gateway');
const $result = $('#yprint_' + gateway + '_webhook_result');

$button.prop('disabled', true).text('<?php _e('Verarbeitung...', 'yprint-payment'); ?>');
$result.text('').removeClass('success error');

$.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'yprint_create_webhook',
        gateway: gateway,
        security: yprint_admin_params.admin_nonce
    },
    success: function(response) {
        $button.prop('disabled', false).text('<?php _e('Webhook automatisch erstellen', 'yprint-payment'); ?>');
        
        if (response.success) {
            $result.addClass('success').text('<?php _e('Webhook erstellt!', 'yprint-payment'); ?>');
        } else {
            $result.addClass('error').text('<?php _e('Fehler: ', 'yprint-payment'); ?>' + response.data.message);
        }
    },
    error: function() {
        $button.prop('disabled', false).text('<?php _e('Webhook automatisch erstellen', 'yprint-payment'); ?>');
        $result.addClass('error').text('<?php _e('Verbindungsfehler!', 'yprint-payment'); ?>');
    }
});
});

// Toggle feature flags
$('.toggle-feature').on('click', function() {
const $button = $(this);
const featureName = $button.data('feature');
const currentStatus = $button.data('status') === 1;
const newStatus = !currentStatus;

$button.prop('disabled', true);

$.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'yprint_toggle_feature_flag',
        flag_name: featureName,
        enable: newStatus ? 1 : 0,
        security: yprint_admin_params.admin_nonce
    },
    success: function(response) {
        $button.prop('disabled', false);
        
        if (response.success) {
            // Update button text and data
            $button.text(newStatus ? '<?php _e('Deaktivieren', 'yprint-payment'); ?>' : '<?php _e('Aktivieren', 'yprint-payment'); ?>');
            $button.data('status', newStatus ? 1 : 0);
            
            // Update status text
            $button.closest('tr').find('.feature-status')
                   .removeClass('enabled disabled')
                   .addClass(newStatus ? 'enabled' : 'disabled')
                   .text(newStatus ? '<?php _e('Aktiviert', 'yprint-payment'); ?>' : '<?php _e('Deaktiviert', 'yprint-payment'); ?>');
            
            // Show success notification
            showAdminNotice('success', response.data.message);
        } else {
            showAdminNotice('error', response.data.message);
        }
    },
    error: function() {
        $button.prop('disabled', false);
        showAdminNotice('error', '<?php _e('Verbindungsfehler beim Aktualisieren des Feature-Flags.', 'yprint-payment'); ?>');
    }
});
});

// Reset feature flags
$('#yprint_reset_feature_flags').on('click', function() {
if (confirm('<?php _e('Bist du sicher, dass du alle Feature-Flags zurücksetzen möchtest? Dies kann nicht rückgängig gemacht werden.', 'yprint-payment'); ?>')) {
    location.reload();
}
});

// Run diagnostics
$('#yprint_run_diagnostics').on('click', function() {
const $button = $(this);
const $result = $('#yprint_diagnostics_result');

$button.prop('disabled', true).text('<?php _e('Diagnose läuft...', 'yprint-payment'); ?>');

$.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'yprint_run_diagnostics',
        security: yprint_admin_params.admin_nonce
    },
    success: function(response) {
        $button.prop('disabled', false).text('<?php _e('Diagnose ausführen', 'yprint-payment'); ?>');
        
        if (response.success) {
            $result.show().find('pre').text(response.data.results);
        } else {
            showAdminNotice('error', response.data.message);
        }
    },
    error: function() {
        $button.prop('disabled', false).text('<?php _e('Diagnose ausführen', 'yprint-payment'); ?>');
        showAdminNotice('error', '<?php _e('Verbindungsfehler bei der Diagnose.', 'yprint-payment'); ?>');
    }
});
});

// Clear plugin cache
$('#yprint_clear_plugin_cache').on('click', function() {
const $button = $(this);

$button.prop('disabled', true).text('<?php _e('Cache wird geleert...', 'yprint-payment'); ?>');

$.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'yprint_clear_plugin_cache',
        security: yprint_admin_params.admin_nonce
    },
    success: function(response) {
        $button.prop('disabled', false).text('<?php _e('Plugin-Cache leeren', 'yprint-payment'); ?>');
        
        if (response.success) {
            showAdminNotice('success', response.data.message);
        } else {
            showAdminNotice('error', response.data.message);
        }
    },
    error: function() {
        $button.prop('disabled', false).text('<?php _e('Plugin-Cache leeren', 'yprint-payment'); ?>');
        showAdminNotice('error', '<?php _e('Verbindungsfehler beim Leeren des Caches.', 'yprint-payment'); ?>');
    }
});
});

// Delete log
$('.yprint-delete-log').on('click', function(e) {
e.preventDefault();

const logFile = $(this).data('log');

if (confirm('<?php _e('Möchtest du diese Log-Datei wirklich löschen?', 'yprint-payment'); ?>')) {
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
                    action: 'yprint_delete_log',
                    log: logFile,
                    security: yprint_admin_params.admin_nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        showAdminNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showAdminNotice('error', '<?php _e('Verbindungsfehler beim Löschen der Log-Datei.', 'yprint-payment'); ?>');
                }
            });
        }
    });
    
    // Clear all logs
    $('#yprint_clear_all_logs').on('click', function() {
        if (confirm('<?php _e('Möchtest du wirklich alle Log-Dateien löschen?', 'yprint-payment'); ?>')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'yprint_clear_all_logs',
                    security: yprint_admin_params.admin_nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        showAdminNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showAdminNotice('error', '<?php _e('Verbindungsfehler beim Löschen der Log-Dateien.', 'yprint-payment'); ?>');
                }
            });
        }
    });
    
    // Refresh log
    $('#yprint_refresh_log').on('click', function() {
        location.reload();
    });
    
    // Save settings forms
    $('button[id^="yprint_save_"]').on('click', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const settingsType = $button.attr('id').replace('yprint_save_', '').replace('_settings', '');
        const $form = $button.closest('form');
        
        $button.prop('disabled', true).text('<?php _e('Speichern...', 'yprint-payment'); ?>');
        
        // Serialize form data
        const formData = $form.serialize();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'yprint_save_settings',
                settings_type: settingsType,
                settings: formData,
                security: yprint_admin_params.admin_nonce
            },
            success: function(response) {
                $button.prop('disabled', false).text('<?php _e('Einstellungen speichern', 'yprint-payment'); ?>');
                
                if (response.success) {
                    showAdminNotice('success', response.data.message);
                } else {
                    showAdminNotice('error', response.data.message);
                }
            },
            error: function() {
                $button.prop('disabled', false).text('<?php _e('Einstellungen speichern', 'yprint-payment'); ?>');
                showAdminNotice('error', '<?php _e('Verbindungsfehler beim Speichern der Einstellungen.', 'yprint-payment'); ?>');
            }
        });
    });
    
    // Helper function to show admin notices
    function showAdminNotice(type, message) {
        const $notice = $('#yprint-admin-notice');
        
        $notice.removeClass('notice-success notice-error')
               .addClass(type === 'success' ? 'notice-success' : 'notice-error')
               .html('<p>' + message + '</p>')
               .show();
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $notice.fadeOut();
        }, 5000);
    }
});
</script>

<?php
/**
 * Returns feature description for a specific flag.
 *
 * @param string $flag_name The name of the feature flag.
 * @return string The description of the feature.
 */
function get_feature_description($flag_name) {
    $descriptions = array(
        // Stripe-bezogene Features
        'stripe_integration' => __('Aktiviert die Stripe-Zahlungsmethode', 'yprint-payment'),
        'stripe_sca_support' => __('Aktiviert Strong Customer Authentication (SCA) für EU-Zahlungen', 'yprint-payment'),
        'stripe_upe_enabled' => __('Aktiviert das Unified Payment Element (neue Stripe-UI)', 'yprint-payment'),
        'stripe_webhooks' => __('Aktiviert Webhook-Unterstützung für Stripe', 'yprint-payment'),
        
        // PayPal-bezogene Features
        'paypal_integration' => __('Aktiviert die PayPal-Zahlungsmethode', 'yprint-payment'),
        'paypal_smart_buttons' => __('Aktiviert Smart Payment Buttons für PayPal', 'yprint-payment'),
        'paypal_webhooks' => __('Aktiviert Webhook-Unterstützung für PayPal', 'yprint-payment'),
        
        // SEPA-bezogene Features
        'sepa_integration' => __('Aktiviert die SEPA-Lastschrift-Zahlungsmethode', 'yprint-payment'),
        'sepa_instant_validation' => __('Aktiviert sofortige IBAN-Validierung', 'yprint-payment'),
        
        // Weitere Zahlungsfeatures
        'bank_transfer' => __('Aktiviert die Banküberweisung-Zahlungsmethode', 'yprint-payment'),
        'klarna_integration' => __('Aktiviert die Klarna-Zahlungsmethode (zukünftig)', 'yprint-payment'),
        'sofort_integration' => __('Aktiviert die Sofort-Zahlungsmethode (zukünftig)', 'yprint-payment'),
        
        // UI-Features
        'responsive_checkout' => __('Aktiviert den responsiven Checkout', 'yprint-payment'),
        'enhanced_validation' => __('Aktiviert verbesserte Formularvalidierung', 'yprint-payment'),
        'address_autofill' => __('Aktiviert die automatische Adressvervollständigung', 'yprint-payment'),
        
        // Admin-Features
        'advanced_reporting' => __('Aktiviert erweiterte Berichterstellung', 'yprint-payment'),
        'transaction_logs' => __('Aktiviert Transaktionsprotokolle', 'yprint-payment'),
        'debug_mode' => __('Aktiviert den Debug-Modus mit ausführlicher Protokollierung', 'yprint-payment'),
        
        // Sicherheitsfeatures
        'fraud_detection' => __('Aktiviert Betrugserkennung', 'yprint-payment'),
        'captcha_protection' => __('Aktiviert CAPTCHA-Schutz für Checkout', 'yprint-payment'),
        
        // Performance-Features
        'ajax_cart_updates' => __('Aktiviert AJAX-Warenkorb-Updates', 'yprint-payment'),
        'lazy_loading' => __('Aktiviert Lazy Loading von Ressourcen', 'yprint-payment'),
    );
    
    return isset($descriptions[$flag_name]) ? $descriptions[$flag_name] : __('Keine Beschreibung verfügbar', 'yprint-payment');
}