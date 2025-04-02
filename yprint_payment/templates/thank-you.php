<?php
/**
 * Danke-Seite nach erfolgreicher Bestellung
 *
 * Diese Datei zeigt eine Bestätigungsnachricht und Bestelldetails an,
 * nachdem eine Bestellung erfolgreich abgeschlossen wurde.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTML-Template für die Danke-Seite
 *
 * @param int $order_id Optional. Die Bestellungs-ID.
 * @return string Das HTML für die Danke-Seite.
 */
function yprint_thank_you_template($order_id = null) {
    // Die Bestellungs-ID aus dem Query-Parameter oder Session holen
    if (empty($order_id)) {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        
        // Aus der Session holen, falls nicht in der URL
        if ($order_id === 0 && function_exists('WC') && WC()->session) {
            $order_id = WC()->session->get('yprint_last_order_id');
        }
    }
    
    // Bestellung abrufen
    $order = $order_id ? wc_get_order($order_id) : null;
    
    // Standardwerte für Bestelldetails
    $order_number = $order ? $order->get_order_number() : '';
    $customer_name = '';
    $order_total = '';
    $payment_method = '';
    
    if ($order) {
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $order_total = $order->get_formatted_order_total();
        $payment_method = $order->get_payment_method_title();
    }
    
    // Shop-URL für den Zurück-zum-Shop-Button
    $shop_url = get_permalink(wc_get_page_id('shop'));
    if (!$shop_url) {
        $shop_url = home_url();
    }
    
    // Ausgabe beginnen
    ob_start();
    ?>
    <div class="yprint-thank-you-container">
        <div class="yprint-thank-you-card">
            <div class="yprint-thank-you-header">
                <div class="yprint-thank-you-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#4BB543" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </div>
                <h1 class="yprint-thank-you-title">Danke für Ihre Bestellung!</h1>
                <p class="yprint-thank-you-message">Wir haben Ihre Bestellung erhalten und werden sie so schnell wie möglich bearbeiten.</p>
            </div>
            
            <?php if ($order) : ?>
                <div class="yprint-thank-you-details">
                    <h2>Bestellübersicht</h2>
                    <div class="yprint-detail-row">
                        <span class="yprint-detail-label">Bestellnummer:</span>
                        <span class="yprint-detail-value"><?php echo esc_html($order_number); ?></span>
                    </div>
                    <?php if (!empty($customer_name)) : ?>
                        <div class="yprint-detail-row">
                            <span class="yprint-detail-label">Name:</span>
                            <span class="yprint-detail-value"><?php echo esc_html($customer_name); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="yprint-detail-row">
                        <span class="yprint-detail-label">Gesamtbetrag:</span>
                        <span class="yprint-detail-value"><?php echo $order_total; ?></span>
                    </div>
                    <div class="yprint-detail-row">
                        <span class="yprint-detail-label">Zahlungsmethode:</span>
                        <span class="yprint-detail-value"><?php echo esc_html($payment_method); ?></span>
                    </div>
                </div>
                
                <div class="yprint-thank-you-items">
                    <h2>Bestellte Artikel</h2>
                    <table class="yprint-items-table">
                        <thead>
                            <tr>
                                <th>Artikel</th>
                                <th>Menge</th>
                                <th>Preis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($order->get_items() as $item_id => $item) {
                                $product = $item->get_product();
                                $product_name = $item->get_name();
                                $quantity = $item->get_quantity();
                                $price = $order->get_formatted_line_subtotal($item);
                                ?>
                                <tr>
                                    <td><?php echo esc_html($product_name); ?></td>
                                    <td><?php echo esc_html($quantity); ?></td>
                                    <td><?php echo $price; ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <div class="yprint-thank-you-actions">
                <a href="<?php echo esc_url($shop_url); ?>" class="yprint-button yprint-shop-button">Weiter einkaufen</a>
                <?php if ($order_id) : ?>
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="yprint-button yprint-orders-button">Meine Bestellungen</a>
                <?php endif; ?>
            </div>
            
            <div class="yprint-thank-you-redirect">
                <p>Sie werden in <span id="countdown">10</span> Sekunden zur Startseite weitergeleitet.</p>
            </div>
        </div>
    </div>

    <style>
    .yprint-thank-you-container {
        font-family: 'Roboto', -apple-system, sans-serif;
        max-width: 800px;
        margin: 40px auto;
        padding: 20px;
    }

    .yprint-thank-you-card {
        background-color: #fff;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        padding: 40px;
    }

    .yprint-thank-you-header {
        text-align: center;
        margin-bottom: 40px;
    }

    .yprint-thank-you-icon {
        margin-bottom: 20px;
    }

    .yprint-thank-you-title {
        font-size: 28px;
        font-weight: 600;
        color: #1d1d1f;
        margin-bottom: 16px;
    }

    .yprint-thank-you-message {
        font-size: 18px;
        color: #6e6e73;
        line-height: 1.5;
        margin-bottom: 0;
    }

    .yprint-thank-you-details, 
    .yprint-thank-you-items {
        margin-bottom: 30px;
        border-bottom: 1px solid #f5f5f7;
        padding-bottom: 30px;
    }

    .yprint-thank-you-details h2,
    .yprint-thank-you-items h2 {
        font-size: 20px;
        font-weight: 600;
        color: #1d1d1f;
        margin-bottom: 16px;
    }

    .yprint-detail-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
    }

    .yprint-detail-label {
        font-weight: 500;
        color: #6e6e73;
    }

    .yprint-detail-value {
        font-weight: 600;
        color: #1d1d1f;
    }

    .yprint-items-table {
        width: 100%;
        border-collapse: collapse;
    }

    .yprint-items-table th {
        text-align: left;
        font-weight: 500;
        color: #6e6e73;
        padding: 8px 0;
        border-bottom: 1px solid #f5f5f7;
    }

    .yprint-items-table td {
        padding: 12px 0;
        border-bottom: 1px solid #f5f5f7;
    }

    .yprint-thank-you-actions {
        display: flex;
        justify-content: center;
        gap: 20px;
        margin-bottom: 30px;
    }

    .yprint-button {
        display: inline-block;
        padding: 12px 24px;
        border-radius: 5px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .yprint-shop-button {
        background-color: #0079FF;
        color: white;
    }

    .yprint-shop-button:hover {
        background-color: #0068e1;
        color: white;
    }

    .yprint-orders-button {
        background-color: #f5f5f7;
        color: #1d1d1f;
    }

    .yprint-orders-button:hover {
        background-color: #e5e5ea;
        color: #1d1d1f;
    }

    .yprint-thank-you-redirect {
        text-align: center;
        color: #6e6e73;
        font-size: 14px;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .yprint-thank-you-container {
            padding: 10px;
            margin: 20px auto;
        }
        
        .yprint-thank-you-card {
            padding: 20px;
        }
        
        .yprint-thank-you-title {
            font-size: 24px;
        }
        
        .yprint-thank-you-message {
            font-size: 16px;
        }
        
        .yprint-thank-you-actions {
            flex-direction: column;
            gap: 10px;
        }
        
        .yprint-button {
            width: 100%;
            text-align: center;
        }
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Countdown für die Weiterleitung
        let secondsLeft = 10;
        const countdownElement = document.getElementById('countdown');
        
        const countdownInterval = setInterval(function() {
            secondsLeft--;
            if (countdownElement) {
                countdownElement.textContent = secondsLeft;
            }
            
            if (secondsLeft <= 0) {
                clearInterval(countdownInterval);
                window.location.href = '<?php echo esc_js(home_url()); ?>';
            }
        }, 1000);
        
        // Leere den Warenkorb, falls noch nicht geschehen
        if (typeof wc_cart_fragments_params !== 'undefined') {
            $.ajax({
                url: wc_cart_fragments_params.wc_ajax_url.toString().replace('%%endpoint%%', 'empty_cart'),
                type: 'POST',
                success: function() {
                    // Warenkorb wurde geleert
                    $(document.body).trigger('wc_fragments_refreshed');
                }
            });
        }
        
        // Wenn YPrintCheckoutSystem verfügbar ist, den Checkout State zurücksetzen
        if (typeof YPrintCheckoutSystem !== 'undefined' && typeof YPrintCheckoutSystem.resetState === 'function') {
            YPrintCheckoutSystem.resetState();
        }
    });
    </script>
    <?php
    
    return ob_get_clean();
}

/**
 * Shortcode für die Danke-Seite
 *
 * @param array $atts Shortcode-Attribute
 * @return string Das HTML für die Danke-Seite
 */
function yprint_thank_you_shortcode($atts) {
    $atts = shortcode_atts(array(
        'order_id' => 0,
    ), $atts, 'thankyou_redirect');
    
    $order_id = absint($atts['order_id']);
    
    return yprint_thank_you_template($order_id);
}
add_shortcode('thankyou_redirect', 'yprint_thank_you_shortcode');

/**
 * Danke-Seite Template
 * Diese Funktion wird direkt aufgerufen, wenn die Danke-Seite
 * als separates Template angezeigt wird.
 */
function yprint_thank_you_page_template() {
    // Seiten-Header hinzufügen, wenn nicht Teil eines Shortcodes
    get_header();
    
    echo yprint_thank_you_template();
    
    // Seiten-Footer hinzufügen
    get_footer();
}

/**
 * WooCommerce Thank You Hook
 * Diese Funktion ersetzt die Standard-Dankeseite von WooCommerce
 */
function yprint_replace_woocommerce_thankyou($order_id) {
    // Bestellung abrufen
    $order = wc_get_order($order_id);
    
    if ($order) {
        // Speichere Bestellungs-ID in der Session für späteren Zugriff
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('yprint_last_order_id', $order_id);
        }
    }
    
    // Standard WooCommerce Dankesnachricht überschreiben
    remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
    
    // YPrint Dankesnachricht anzeigen
    echo yprint_thank_you_template($order_id);
    
    return true;
}
// Auf niedriger Priorität hinzufügen, um sicherzustellen, dass es nach den Standard-Hooks ausgeführt wird
add_action('woocommerce_thankyou', 'yprint_replace_woocommerce_thankyou', 1);