<?php
/**
 * Stripe-Rückgabeseite für YPrint Payment
 *
 * Diese Datei verarbeitet die Rückgaben von Stripe nach einem Zahlungsprozess,
 * prüft den Status der Zahlung und leitet den Benutzer entsprechend weiter.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindere direkten Zugriff
if (!defined("ABSPATH")) exit;

// Stripe-Parameter aus URL abrufen
$payment_intent = isset($_GET["payment_intent"]) ? sanitize_text_field($_GET["payment_intent"]) : "";
$payment_intent_client_secret = isset($_GET["payment_intent_client_secret"]) ? sanitize_text_field($_GET["payment_intent_client_secret"]) : "";
$redirect_status = isset($_GET["redirect_status"]) ? sanitize_text_field($_GET["redirect_status"]) : "";

// Debug-Logging, wenn aktiviert
if (apply_filters('yprint_debug_mode', false)) {
    error_log('Stripe Return Page accessed - Payment Intent: ' . $payment_intent . ', Status: ' . $redirect_status);
}

// Meta-Titel und Beschreibung für SEO
$page_title = __('Zahlung wird verarbeitet', 'yprint-payment');
$page_description = __('Ihre Zahlung wird verarbeitet. Bitte warten Sie einen Moment.', 'yprint-payment');

// Wenn die Zahlung abgebrochen oder fehlgeschlagen ist
if ($redirect_status !== "succeeded" && $redirect_status !== "processing" && $redirect_status !== "requires_capture") {
    $page_title = __('Zahlung nicht abgeschlossen', 'yprint-payment');
    $page_description = __('Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten.', 'yprint-payment');
}

// Header laden mit angepasstem Titel
add_filter('pre_get_document_title', function() use ($page_title) {
    return $page_title;
});

// Meta-Beschreibung einfügen
add_action('wp_head', function() use ($page_description) {
    echo '<meta name="description" content="' . esc_attr($page_description) . '" />';
});

get_header();
?>

<div class="yprint-payment-return-container">
    <div class="yprint-payment-message <?php echo in_array($redirect_status, array('succeeded', 'processing', 'requires_capture')) ? "yprint-payment-success" : "yprint-payment-error"; ?>">
        <?php if (in_array($redirect_status, array('succeeded', 'processing', 'requires_capture'))): ?>
            <div class="yprint-success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <h2><?php _e('Zahlung wird verarbeitet', 'yprint-payment'); ?></h2>
            <p><?php _e('Ihre Zahlung wird verarbeitet. Sie werden in Kürze weitergeleitet...', 'yprint-payment'); ?></p>
            <div class="yprint-loader"></div>
        <?php else: ?>
            <div class="yprint-error-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <h2><?php _e('Zahlung nicht abgeschlossen', 'yprint-payment'); ?></h2>
            <p><?php _e('Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten.', 'yprint-payment'); ?></p>
            <p class="yprint-error-details"><?php 
                switch ($redirect_status) {
                    case 'failed':
                        _e('Die Zahlung wurde abgelehnt. Bitte überprüfen Sie Ihre Zahlungsinformationen.', 'yprint-payment');
                        break;
                    case 'canceled':
                        _e('Die Zahlung wurde abgebrochen.', 'yprint-payment');
                        break;
                    default:
                        _e('Es ist ein unerwarteter Fehler aufgetreten.', 'yprint-payment');
                }
            ?></p>
            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button"><?php _e('Zurück zum Checkout', 'yprint-payment'); ?></a>
            
            <?php if (apply_filters('yprint_show_support_info', true)): ?>
                <div class="yprint-support-info">
                    <p><?php _e('Wenn das Problem weiterhin besteht, kontaktieren Sie bitte unseren Support.', 'yprint-payment'); ?></p>
                    <p><?php _e('Fehlerreferenz:', 'yprint-payment'); ?> <?php echo esc_html(substr($payment_intent, 0, 8) . '...'); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.yprint-payment-return-container {
    max-width: 800px;
    margin: 50px auto;
    padding: 30px;
    text-align: center;
    font-family: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

.yprint-payment-message {
    padding: 40px 30px;
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
}

.yprint-payment-success {
    background-color: #f0f9ff;
    border: 1px solid #d1e7f7;
    color: #0c5460;
}

.yprint-payment-error {
    background-color: #fff2f2;
    border: 1px solid #ffcdd2;
    color: #721c24;
}

.yprint-success-icon, .yprint-error-icon {
    margin-bottom: 20px;
}

.yprint-success-icon svg {
    color: #28a745;
}

.yprint-error-icon svg {
    color: #dc3545;
}

.yprint-payment-message h2 {
    margin-bottom: 20px;
    font-size: 24px;
    font-weight: 600;
}

.yprint-payment-message p {
    margin-bottom: 20px;
    font-size: 16px;
    line-height: 1.6;
}

.yprint-loader {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid rgba(0, 121, 255, 0.2);
    border-top: 4px solid #0079FF;
    border-radius: 50%;
    animation: yprint-spin 1s linear infinite;
    margin-top: 20px;
}

@keyframes yprint-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.yprint-return-button {
    display: inline-block;
    margin-top: 20px;
    padding: 12px 28px;
    background-color: #0079FF;
    color: white;
    text-decoration: none;
    border-radius: 5px;
    font-weight: 500;
    transition: background-color 0.2s ease;
}

.yprint-return-button:hover {
    background-color: #0068e1;
    color: white;
    text-decoration: none;
}

.yprint-error-details {
    background-color: rgba(220, 53, 69, 0.1);
    padding: 15px;
    border-radius: 5px;
    margin: 20px 0;
    font-size: 14px;
}

.yprint-support-info {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid rgba(0,0,0,0.1);
    font-size: 14px;
    color: #6c757d;
}

/* Responsives Design */
@media (max-width: 768px) {
    .yprint-payment-return-container {
        padding: 15px;
        margin: 30px auto;
    }
    
    .yprint-payment-message {
        padding: 30px 20px;
    }
    
    .yprint-payment-message h2 {
        font-size: 20px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    <?php if (in_array($redirect_status, array('succeeded', 'processing', 'requires_capture')) && !empty($payment_intent)): ?>
    // Stripe-Zahlung bestätigen
    $.ajax({
        type: "POST",
        url: "<?php echo admin_url("admin-ajax.php"); ?>",
        data: {
            action: "yprint_verify_stripe_return",
            payment_intent: "<?php echo esc_js($payment_intent); ?>",
            client_secret: "<?php echo esc_js($payment_intent_client_secret); ?>",
            security: "<?php echo wp_create_nonce("yprint-checkout-nonce"); ?>"
        },
        success: function(response) {
            if (response.success && response.data.redirect) {
                // Kundenspezifische Analytics-Events auslösen
                if (typeof gtag === 'function') {
                    gtag('event', 'purchase', {
                        'transaction_id': '<?php echo esc_js($payment_intent); ?>',
                        'affiliation': 'Stripe',
                        'value': response.data.amount || 0,
                        'currency': response.data.currency || 'EUR'
                    });
                }
                
                // Zur Thank-you-Seite weiterleiten
                window.location.href = response.data.redirect;
            } else {
                // Fehler anzeigen
                $(".yprint-payment-message")
                    .removeClass("yprint-payment-success")
                    .addClass("yprint-payment-error")
                    .html(`
                        <div class="yprint-error-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        </div>
                        <h2>${"<?php _e('Fehler bei der Zahlung', 'yprint-payment'); ?>"}</h2>
                        <p>${response.data.message || "<?php _e('Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten.', 'yprint-payment'); ?>"}</p>
                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button"><?php _e('Zurück zum Checkout', 'yprint-payment'); ?></a>
                    `);
                
                // Fehler in die Konsole loggen
                console.error('Fehler bei der Stripe-Zahlungsbestätigung:', response);
                
                // Fehler in Analytics tracken, wenn vorhanden
                if (typeof gtag === 'function') {
                    gtag('event', 'payment_error', {
                        'transaction_id': '<?php echo esc_js($payment_intent); ?>',
                        'error_type': 'stripe_verification_failed',
                        'error_message': response.data.message || 'Unbekannter Fehler'
                    });
                }
            }
        },
        error: function(xhr, status, error) {
            // Allgemeiner Fehler
            $(".yprint-payment-message")
                .removeClass("yprint-payment-success")
                .addClass("yprint-payment-error")
                .html(`
                    <div class="yprint-error-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                    </div>
                    <h2>${"<?php _e('Fehler bei der Zahlung', 'yprint-payment'); ?>"}</h2>
                    <p>${"<?php _e('Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten.', 'yprint-payment'); ?>"}</p>
                    <p class="yprint-error-details">${"<?php _e('Fehlerdetails', 'yprint-payment'); ?>:"} ${error || 'Unbekannter Fehler'}</p>
                    <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">${"<?php _e('Zurück zum Checkout', 'yprint-payment'); ?>"}</a>
                    <div class="yprint-support-info">
                        <p>${"<?php _e('Wenn das Problem weiterhin besteht, kontaktieren Sie bitte unseren Support.', 'yprint-payment'); ?>"}</p>
                        <p>${"<?php _e('Fehlerreferenz:', 'yprint-payment'); ?>"} <?php echo esc_html(substr($payment_intent, 0, 8) . '...'); ?></p>
                    </div>
                `);
            
            // Fehler in die Konsole loggen
            console.error('AJAX-Fehler bei der Stripe-Zahlungsbestätigung:', xhr, status, error);
            
            // Fehler in Analytics tracken, wenn vorhanden
            if (typeof gtag === 'function') {
                gtag('event', 'payment_error', {
                    'transaction_id': '<?php echo esc_js($payment_intent); ?>',
                    'error_type': 'ajax_failure',
                    'error_message': error || 'Unbekannter Fehler'
                });
            }
        }
    });
    
    // Timeout-Handler für den Fall, dass die AJAX-Anfrage zu lange dauert
    setTimeout(function() {
        if ($('.yprint-loader').is(':visible')) {
            console.log('Timeout erreicht, Weiterleitung zur Thank-you-Seite');
            window.location.href = "<?php echo esc_url(home_url('/thank-you/?pi=' . urlencode($payment_intent))); ?>";
        }
    }, 10000); // 10 Sekunden Timeout
    
    <?php elseif ($redirect_status === 'requires_action'): ?>
    // Wenn weitere Authentifizierung erforderlich ist (z.B. 3D Secure)
    // Diese Logik wird client-seitig durch den Stripe.js-Handler behandelt
    $(".yprint-payment-message").html(`
        <div class="yprint-info-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
        </div>
        <h2>${"<?php _e('Authentifizierung erforderlich', 'yprint-payment'); ?>"}</h2>
        <p>${"<?php _e('Ihre Bank benötigt eine zusätzliche Authentifizierung. Bitte folgen Sie den Anweisungen.', 'yprint-payment'); ?>"}</p>
        <div class="yprint-loader"></div>
    `);
    
    // Stripe.js laden, falls noch nicht vorhanden
    if (typeof Stripe === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.onload = function() {
            handleStripeAuthentication();
        };
        document.head.appendChild(script);
    } else {
        handleStripeAuthentication();
    }
    
    function handleStripeAuthentication() {
        const stripe = Stripe("<?php echo esc_js(get_option('yprint_stripe_public_key', 'INSERT_API_KEY_HERE')); ?>");
        const clientSecret = "<?php echo esc_js($payment_intent_client_secret); ?>";
        
        stripe.retrievePaymentIntent(clientSecret).then(function(result) {
            if (result.error) {
                // Fehler beim Abrufen des Payment Intent
                $(".yprint-payment-message")
                    .removeClass("yprint-payment-success")
                    .addClass("yprint-payment-error")
                    .html(`
                        <div class="yprint-error-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        </div>
                        <h2>${"<?php _e('Fehler bei der Authentifizierung', 'yprint-payment'); ?>"}</h2>
                        <p>${result.error.message || "<?php _e('Bei der Authentifizierung Ihrer Zahlung ist ein Fehler aufgetreten.', 'yprint-payment'); ?>"}</p>
                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">${"<?php _e('Zurück zum Checkout', 'yprint-payment'); ?>"}</a>
                    `);
            } else if (result.paymentIntent && result.paymentIntent.status === 'requires_action') {
                // Authentifizierung erforderlich, Stripe-Formular anzeigen
                stripe.handleNextAction({
                    clientSecret: clientSecret
                }).then(function(result) {
                    if (result.error) {
                        // Fehler bei der Authentifizierung
                        $(".yprint-payment-message")
                            .removeClass("yprint-payment-success")
                            .addClass("yprint-payment-error")
                            .html(`
                                <div class="yprint-error-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <line x1="12" y1="8" x2="12" y2="12"></line>
                                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                    </svg>
                                </div>
                                <h2>${"<?php _e('Fehler bei der Authentifizierung', 'yprint-payment'); ?>"}</h2>
                                <p>${result.error.message || "<?php _e('Bei der Authentifizierung Ihrer Zahlung ist ein Fehler aufgetreten.', 'yprint-payment'); ?>"}</p>
                                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">${"<?php _e('Zurück zum Checkout', 'yprint-payment'); ?>"}</a>
                            `);
                    } else {
                        // Authentifizierung erfolgreich, zur Thank-you-Seite weiterleiten
                        window.location.href = "<?php echo esc_url(home_url('/thank-you/?pi=' . urlencode($payment_intent))); ?>";
                    }
                });
            } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                // Zahlung bereits erfolgreich, zur Thank-you-Seite weiterleiten
                window.location.href = "<?php echo esc_url(home_url('/thank-you/?pi=' . urlencode($payment_intent))); ?>";
            } else {
                // Unerwarteter Status
                $(".yprint-payment-message")
                    .removeClass("yprint-payment-success")
                    .addClass("yprint-payment-error")
                    .html(`
                        <div class="yprint-error-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        </div>
                        <h2>${"<?php _e('Unerwarteter Zahlungsstatus', 'yprint-payment'); ?>"}</h2>
                        <p>${"<?php _e('Der Zahlungsstatus konnte nicht eindeutig ermittelt werden.', 'yprint-payment'); ?>"}</p>
                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">${"<?php _e('Zurück zum Checkout', 'yprint-payment'); ?>"}</a>
                    `);
            }
        });
    }
    <?php endif; ?>
});
</script>

<?php 
// Tracking-Pixel für Conversion-Tracking, wenn Zahlung erfolgreich ist
if (in_array($redirect_status, array('succeeded', 'processing')) && 
    apply_filters('yprint_enable_conversion_tracking', true)): 
?>
<!-- Google Conversion Tracking -->
<script>
window.dataLayer = window.dataLayer || [];
dataLayer.push({
    'event': 'payment_return',
    'payment_type': 'stripe',
    'payment_status': '<?php echo esc_js($redirect_status); ?>',
    'payment_id': '<?php echo esc_js($payment_intent); ?>'
});
</script>
<?php endif; ?>

<?php get_footer(); ?>