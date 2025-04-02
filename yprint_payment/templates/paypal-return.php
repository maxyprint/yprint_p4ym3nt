<?php
/**
 * PayPal-Rückgabeseite für YPrint Payment
 *
 * Verarbeitet die Rückkehr des Kunden von der PayPal-Zahlungsseite und 
 * verifiziert die Zahlung über einen AJAX-Call zum Backend.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindere direkten Zugriff
if (!defined("ABSPATH")) exit;

// Order ID aus der Session abrufen
$temp_order_id = WC()->session ? WC()->session->get("yprint_temp_order_id") : 0;

// PayPal-Parameter aus URL abrufen
$paypal_order_id = isset($_GET["token"]) ? sanitize_text_field($_GET["token"]) : "";
$payer_id = isset($_GET["PayerID"]) ? sanitize_text_field($_GET["PayerID"]) : "";

// Prüfen, ob Benutzer abgebrochen hat
$canceled = isset($_GET["cancel"]) && $_GET["cancel"] === "true";

// Debug-Logging wenn aktiviert
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('PayPal Return Page - Token: ' . $paypal_order_id . ', PayerID: ' . $payer_id . ', Canceled: ' . ($canceled ? 'yes' : 'no'));
}

// Sicherstellen, dass wir ein gültiges WooCommerce haben
if (!function_exists('WC')) {
    wp_redirect(site_url());
    exit;
}

// Seiten-Header einbinden
get_header();
?>

<div class="yprint-payment-return-container">
    <?php if ($canceled): ?>
        <div class="yprint-payment-message yprint-payment-error">
            <h2><?php _e('Zahlung abgebrochen', 'yprint-payment'); ?></h2>
            <p><?php _e('Die PayPal-Zahlung wurde abgebrochen. Bitte versuchen Sie es erneut oder wählen Sie eine andere Zahlungsmethode.', 'yprint-payment'); ?></p>
            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">
                <?php _e('Zurück zum Checkout', 'yprint-payment'); ?>
            </a>
        </div>
    <?php else: ?>
        <div class="yprint-payment-message yprint-payment-pending" id="paypal-processing">
            <h2><?php _e('Zahlung wird verarbeitet', 'yprint-payment'); ?></h2>
            <p><?php _e('Ihre PayPal-Zahlung wird verarbeitet. Bitte warten Sie einen Moment...', 'yprint-payment'); ?></p>
            <div class="yprint-loader"></div>
        </div>
        
        <div class="yprint-payment-message yprint-payment-success" id="paypal-success" style="display: none;">
            <h2><?php _e('Zahlung erfolgreich', 'yprint-payment'); ?></h2>
            <p><?php _e('Ihre Zahlung wurde erfolgreich bearbeitet. Sie werden in Kürze weitergeleitet...', 'yprint-payment'); ?></p>
            <div class="yprint-loader"></div>
        </div>
        
        <div class="yprint-payment-message yprint-payment-error" id="paypal-error" style="display: none;">
            <h2><?php _e('Fehler bei der Zahlung', 'yprint-payment'); ?></h2>
            <p id="paypal-error-message"><?php _e('Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten.', 'yprint-payment'); ?></p>
            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="yprint-return-button">
                <?php _e('Zurück zum Checkout', 'yprint-payment'); ?>
            </a>
        </div>
    <?php endif; ?>
</div>

<style>
.yprint-payment-return-container {
    max-width: 800px;
    margin: 50px auto;
    padding: 30px;
    text-align: center;
    font-family: 'Roboto', -apple-system, sans-serif;
}

.yprint-payment-message {
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}

.yprint-payment-pending {
    background-color: #f0f9ff;
    border: 1px solid #d1e7f7;
}

.yprint-payment-success {
    background-color: #f0fff4;
    border: 1px solid #c6f6d5;
}

.yprint-payment-error {
    background-color: #fff2f2;
    border: 1px solid #ffcdd2;
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
    padding: 12px 24px;
    background-color: #0079FF;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-weight: 500;
    transition: background-color 0.2s ease;
}

.yprint-return-button:hover {
    background-color: #0068e1;
    color: white;
    text-decoration: none;
}

h2 {
    margin-top: 0;
    font-size: 24px;
    font-weight: 600;
    color: #333;
}

p {
    font-size: 16px;
    line-height: 1.5;
    color: #666;
    margin-bottom: 20px;
}
</style>

<?php if (!$canceled && !empty($paypal_order_id)): ?>
<script>
jQuery(document).ready(function($) {
    // PayPal-Zahlung bestätigen
    $.ajax({
        type: "POST",
        url: "<?php echo admin_url("admin-ajax.php"); ?>",
        data: {
            action: "yprint_verify_paypal_return",
            paypal_order_id: "<?php echo esc_js($paypal_order_id); ?>",
            payer_id: "<?php echo esc_js($payer_id); ?>",
            temp_order_id: "<?php echo esc_js($temp_order_id); ?>",
            security: "<?php echo wp_create_nonce("yprint-checkout-nonce"); ?>"
        },
        success: function(response) {
            if (response.success && response.data.redirect) {
                // Erfolgsmeldung anzeigen
                $("#paypal-processing").hide();
                $("#paypal-success").show();
                
                // Kurze Verzögerung für die Anzeige der Erfolgsmeldung
                setTimeout(function() {
                    window.location.href = response.data.redirect;
                }, 2000);
            } else {
                // Fehlermeldung anzeigen
                $("#paypal-processing").hide();
                
                if (response.data && response.data.message) {
                    $("#paypal-error-message").text(response.data.message);
                }
                
                $("#paypal-error").show();
                
                // Debug-Logging
                if (window.console && window.console.error) {
                    console.error('PayPal Verification Error:', response);
                }
            }
        },
        error: function(xhr, status, error) {
            // Allgemeiner Fehler
            $("#paypal-processing").hide();
            $("#paypal-error-message").text("Bei der Verarbeitung ist ein Serverfehler aufgetreten. Bitte kontaktieren Sie den Support.");
            $("#paypal-error").show();
            
            // Debug-Logging
            if (window.console && window.console.error) {
                console.error('AJAX Error:', status, error, xhr.responseText);
            }
        }
    });
});
</script>
<?php endif; ?>

<?php get_footer(); ?>