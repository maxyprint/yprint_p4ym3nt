<?php
/**
 * Template für die Zahlungsmethoden im Checkout
 *
 * Dieses Template rendert die Zahlungsmethoden-Auswahl im Checkout und
 * bindet die nötigen JavaScript-Funktionen für die Interaktion ein.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

// Verfügbare Zahlungsmethoden aus dem Feature-Flag-System oder den Gateway-Klassen abrufen
$available_gateways = array();
$feature_flags = YPrint_Feature_Flags::instance();

// Stripe hinzufügen, wenn aktiviert
if ($feature_flags->is_enabled('stripe_integration')) {
    $available_gateways['stripe'] = array(
        'id' => 'stripe',
        'title' => __('Kreditkarte', 'yprint-payment'),
        'description' => __('Bezahlen Sie sicher mit Ihrer Kreditkarte.', 'yprint-payment'),
        'icon' => 'credit-card'
    );
}

// PayPal hinzufügen, wenn aktiviert
if ($feature_flags->is_enabled('paypal_integration')) {
    $available_gateways['paypal'] = array(
        'id' => 'paypal',
        'title' => __('PayPal', 'yprint-payment'),
        'description' => __('Bezahlen Sie mit Ihrem PayPal-Konto.', 'yprint-payment'),
        'icon' => 'paypal'
    );
}

// SEPA-Lastschrift hinzufügen, wenn aktiviert
if ($feature_flags->is_enabled('sepa_integration')) {
    $available_gateways['sepa'] = array(
        'id' => 'sepa',
        'title' => __('SEPA-Lastschrift', 'yprint-payment'),
        'description' => __('Bezahlen Sie per SEPA-Lastschrift von Ihrem Bankkonto.', 'yprint-payment'),
        'icon' => 'bank'
    );
}

// Banküberweisung hinzufügen, wenn aktiviert
if ($feature_flags->is_enabled('bank_transfer')) {
    $available_gateways['bank_transfer'] = array(
        'id' => 'bank_transfer',
        'title' => __('Banküberweisung', 'yprint-payment'),
        'description' => __('Bezahlen Sie per Banküberweisung. Ihre Bestellung wird nach Zahlungseingang versendet.', 'yprint-payment'),
        'icon' => 'university'
    );
}

// Filter für Erweiterungen durch andere Plugins
$available_gateways = apply_filters('yprint_payment_available_gateways', $available_gateways);

// Aktuelle ausgewählte Zahlungsmethode abrufen
$chosen_payment_method = '';
if (function_exists('WC') && WC()->session) {
    $chosen_payment_method = WC()->session->get('chosen_payment_method');
}

// Leere Gateway-Liste prüfen
if (empty($available_gateways)) {
    echo '<div class="yprint-no-payment-methods">' . __('Keine Zahlungsmethoden verfügbar.', 'yprint-payment') . '</div>';
    return;
}
?>

<div class="yprint-payment-options" id="yprint-payment-options">
    <div class="yprint-payment-grid">
        <?php foreach ($available_gateways as $gateway_id => $gateway) : ?>
            <div class="yprint-payment-option <?php echo ($chosen_payment_method == $gateway_id) ? 'selected' : ''; ?>" data-payment-id="<?php echo esc_attr($gateway_id); ?>">
                <input 
                    type="radio" 
                    id="payment_method_<?php echo esc_attr($gateway_id); ?>" 
                    name="payment_method" 
                    value="<?php echo esc_attr($gateway_id); ?>"
                    <?php checked($chosen_payment_method, $gateway_id); ?>
                    class="yprint-hidden-radio"
                    required
                >
                <label for="payment_method_<?php echo esc_attr($gateway_id); ?>" class="yprint-payment-label">
                    <div class="yprint-payment-icon yprint-icon-<?php echo esc_attr($gateway['icon']); ?>"></div>
                    <span class="yprint-payment-title"><?php echo esc_html($gateway['title']); ?></span>
                    <div class="yprint-payment-description">
                        <?php echo esc_html($gateway['description']); ?>
                    </div>
                </label>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
// Zahlungsmethoden-Modals für SCA und andere interaktive Zahlungsmethoden
include_once YPRINT_PAYMENT_TEMPLATE_PATH . 'checkout/payment-modals.php';
?>

<style>
.yprint-payment-options {
    width: 100%;
    font-family: 'Roboto', sans-serif;
    margin-bottom: 30px;
}

.yprint-payment-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
}

.yprint-payment-option {
    border: 2px solid #e0e0e0;
    border-radius: 5px;
    overflow: hidden;
    transition: all 0.3s ease;
    background: white;
    position: relative;
}

.yprint-payment-option:hover {
    border-color: #2997FF;
}

.yprint-payment-option.selected {
    border-color: #2997FF;
    background-color: #f0f6ff;
}

.yprint-hidden-radio {
    position: absolute;
    opacity: 0;
    height: 0;
    width: 0;
}

.yprint-payment-label {
    display: block;
    padding: 15px;
    cursor: pointer;
    width: 100%;
    height: 100%;
}

.yprint-payment-icon {
    width: 40px;
    height: 40px;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
    margin-bottom: 10px;
}

.yprint-icon-paypal {
    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="40" height="40"><path fill="%230070E0" d="M19.2,5.4c-0.5-0.5-1.1-1-1.8-1.2C16.5,4,15.6,3.9,14.7,3.9H9.6c-0.5,0-0.9,0.3-1,0.8L6.3,16c-0.1,0.3,0.2,0.6,0.5,0.6h3.5l0.3-1.7v0.1c0.1-0.5,0.5-0.8,1-0.8h2.1c3,0,5.3-1.2,6-4.5c0-0.1,0-0.2,0.1-0.3c0.2-1.3,0-2.2-0.6-3"></path><path fill="%231F264F" d="M9.3,7.9c0.1-0.4,0.3-0.7,0.6-0.9C10.1,6.9,10.4,6.8,10.7,6.8l4.3,0c0.5,0,1,0.1,1.4,0.2c0.1,0,0.2,0.1,0.3,0.1c0.1,0,0.2,0.1,0.3,0.1c0.1,0,0.2,0.1,0.2,0.1c0.1,0,0.2,0.1,0.2,0.1c0.3,0.1,0.5,0.3,0.7,0.5c0.5-2.9-0.02-4.9-1.7-6.7C14.9-0.3,12.2,0,10,0H3.6C3,0,2.5,0.4,2.4,1L0,17.2c-0.1,0.5,0.3,1,0.8,1h6l1.5-9.3L9.3,7.9z"></path></svg>');
}

.yprint-icon-credit-card {
    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="40" height="40"><path fill="%231F264F" d="M20,4H4C2.9,4,2,4.9,2,6v12c0,1.1,0.9,2,2,2h16c1.1,0,2-0.9,2-2V6C22,4.9,21.1,4,20,4z M20,18H4V12h16V18z M20,8H4V6h16V8z"></path><path fill="%231F264F" d="M6,14h4v2H6V14z M12,14h6v2h-6V14z"></path></svg>');
}

.yprint-icon-bank {
    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="40" height="40"><path fill="%231F264F" d="M12,3L2,8v2h20V8L12,3z M4,12h4v6H4V12z M10,12h4v6h-4V12z M16,12h4v6h-4V12z M2,20v2h20v-2H2z"></path></svg>');
}

.yprint-icon-university {
    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="40" height="40"><path fill="%231F264F" d="M12,3L2,8v2h20V8L12,3z M4,12h4v6H4V12z M10,12h4v6h-4V12z M16,12h4v6h-4V12z M2,20v2h20v-2H2z"></path></svg>');
}

.yprint-payment-title {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 1rem;
    color: #1d1d1f;
}

.yprint-payment-description {
    font-size: 0.85rem;
    color: #666;
    margin-top: 8px;
}

.yprint-payment-option.selected .yprint-payment-description {
    display: block;
}

.yprint-payment-option.error {
    animation: yprint-shake 0.5s;
    border-color: #dc3545;
}

@keyframes yprint-shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

.yprint-no-payment-methods {
    text-align: center;
    color: #dc3545;
    padding: 20px;
    border: 1px solid #dc3545;
    border-radius: 4px;
    grid-column: 1 / -1;
}

@media (max-width: 600px) {
    .yprint-payment-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    const $paymentOptions = $('.yprint-payment-option');
    const $paymentInputs = $('input[name="payment_method"]');
    
    // Initial State setzen
    const initialPayment = $('input[name="payment_method"]:checked').val();
    if (initialPayment) {
        // YPrintCheckoutSystem aktualisieren wenn verfügbar
        if (typeof YPrintCheckoutSystem !== 'undefined') {
            YPrintCheckoutSystem.updateState('paymentMethod', {
                method: initialPayment,
                timestamp: Date.now()
            });
        }
    }

    // Zahlungsmethode ändern
    $paymentInputs.on('change', function() {
        const $selectedOption = $(this).closest('.yprint-payment-option');
        
        // UI Update
        $paymentOptions.removeClass('selected');
        $selectedOption.addClass('selected');
        
        // Fehlerklasse entfernen
        $paymentOptions.removeClass('error');
        
        // YPrintCheckoutSystem aktualisieren wenn verfügbar
        if (typeof YPrintCheckoutSystem !== 'undefined') {
            YPrintCheckoutSystem.updateState('paymentMethod', {
                method: this.value,
                timestamp: Date.now()
            });
        }

        // WooCommerce Integration - Standard-Event trigger
        $(document.body).trigger('payment_method_selected');
        
        // AJAX-Update für die Session
        updatePaymentMethodInSession(this.value);
    });
    
    // WooCommerce Payment Update
    function updatePaymentMethodInSession(method) {
        $.ajax({
            type: 'POST',
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            data: {
                action: 'yprint_update_payment_method',
                payment_method: method,
                security: '<?php echo wp_create_nonce('yprint-checkout-nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $(document.body).trigger('payment_method_selected');
                    
                    // Gateway-spezifische Initialisierung
                    initializeSelectedPaymentMethod(method);
                }
            }
        });
    }
    
    // Auf State-Updates reagieren wenn YPrintCheckoutSystem verfügbar
    $(document).on('checkoutStateUpdate', function(e, state) {
        if (state && state.paymentMethod && state.paymentMethod.method) {
            const method = state.paymentMethod.method;
            const $radio = $(`input[value="${method}"]`);
            
            if (!$radio.is(':checked')) {
                $radio.prop('checked', true).trigger('change');
            }
        }
    });

    // Fehlerbehandlung
    $(document).on('checkout_error', function() {
        if (!$paymentInputs.filter(':checked').length) {
            $paymentOptions.addClass('error');
            $('html, body').animate({
                scrollTop: $('.yprint-payment-options').offset().top - 100
            }, 500);
        }
    });

    // Validierung vor Submit
    $(document).on('checkout_place_order', function() {
        if (!$paymentInputs.filter(':checked').length) {
            $paymentOptions.addClass('error');
            return false;
        }
        return true;
    });
    
    // Gateway-spezifische Initialisierung
    function initializeSelectedPaymentMethod(method) {
        // Methode basierend auf ausgewähltem Gateway aufrufen
        switch(method) {
            case 'stripe':
                initStripePaymentForm();
                break;
            case 'paypal':
                initPayPalPaymentButtons();
                break;
            case 'sepa':
                initSepaPaymentForm();
                break;
            case 'bank_transfer':
                // Keine spezielle Initialisierung notwendig
                break;
        }
    }
    
    // Stripe Initialisierung - wird nur geladen, wenn Stripe ausgewählt ist
    function initStripePaymentForm() {
        // Prüfen, ob Stripe-Skript bereits geladen ist
        if (typeof Stripe === 'undefined' && <?php echo $feature_flags->is_enabled('stripe_integration') ? 'true' : 'false'; ?>) {
            // Stripe JS dynamisch laden
            const script = document.createElement('script');
            script.src = 'https://js.stripe.com/v3/';
            script.async = true;
            script.onload = function() {
                // Stripe Elements initialisieren
                if (typeof YPrintCheckoutSystem !== 'undefined' && YPrintCheckoutSystem.initStripeElements) {
                    YPrintCheckoutSystem.initStripeElements();
                }
            };
            document.head.appendChild(script);
        } else if (typeof Stripe !== 'undefined' && typeof YPrintCheckoutSystem !== 'undefined' && YPrintCheckoutSystem.initStripeElements) {
            // Stripe bereits geladen, nur Elements initialisieren
            YPrintCheckoutSystem.initStripeElements();
        }
    }
    
    // PayPal Initialisierung - wird nur geladen, wenn PayPal ausgewählt ist
    function initPayPalPaymentButtons() {
        // Prüfen, ob PayPal-Skript bereits geladen ist
        if (typeof paypal === 'undefined' && <?php echo $feature_flags->is_enabled('paypal_integration') ? 'true' : 'false'; ?>) {
            // PayPal JS dynamisch laden
            const script = document.createElement('script');
            script.src = 'https://www.paypal.com/sdk/js?client-id=<?php echo esc_attr(get_option('yprint_paypal_client_id', 'INSERT_API_KEY_HERE')); ?>&currency=EUR&intent=capture';
            script.async = true;
            script.onload = function() {
                // PayPal Buttons initialisieren
                if (typeof YPrintCheckoutSystem !== 'undefined' && YPrintCheckoutSystem.initPayPalButtons) {
                    YPrintCheckoutSystem.initPayPalButtons();
                }
            };
            document.head.appendChild(script);
        } else if (typeof paypal !== 'undefined' && typeof YPrintCheckoutSystem !== 'undefined' && YPrintCheckoutSystem.initPayPalButtons) {
            // PayPal bereits geladen, nur Buttons initialisieren
            YPrintCheckoutSystem.initPayPalButtons();
        }
    }
    
    // SEPA Initialisierung
    function initSepaPaymentForm() {
        // SEPA-Formular Validierung und UI-Updates
        if (typeof YPrintCheckoutSystem !== 'undefined' && YPrintCheckoutSystem.initSepaForm) {
            YPrintCheckoutSystem.initSepaForm();
        }
    }
    
    // Initial ausgewählte Zahlungsmethode initialisieren
    if (initialPayment) {
        initializeSelectedPaymentMethod(initialPayment);
    }
});
</script>