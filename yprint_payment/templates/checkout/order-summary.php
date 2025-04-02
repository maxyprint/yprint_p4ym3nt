<?php
/**
 * YPrint Payment - Order Summary Template
 * 
 * Displays the order summary section in the checkout with current cart items,
 * quantities, prices and totals. Allows quantity updates and item removal.
 * 
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get cart items
$cart_items = WC()->cart->get_cart();
$cart_empty = WC()->cart->is_empty();
$cart_subtotal = WC()->cart->get_cart_subtotal();
$cart_total = WC()->cart->get_cart_total();
$shipping_total = WC()->cart->get_cart_shipping_total();
$discount_total = WC()->cart->get_discount_total();
$has_discount = (WC()->cart->get_discount_total() > 0);
?>

<div class="yprint-order-summary" id="yprint-order-summary">
    <h3 class="yprint-summary-title"><?php esc_html_e('Your Cart', 'yprint-payment'); ?></h3>
    
    <?php if ($cart_empty) : ?>
        <div class="yprint-empty-cart-message">
            <?php esc_html_e('Your cart is empty.', 'yprint-payment'); ?>
            <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>"><?php esc_html_e('Continue shopping', 'yprint-payment'); ?></a>
        </div>
    <?php else : ?>
        <div class="yprint-loading-overlay">
            <div class="yprint-loader-spinner"></div>
        </div>
        
        <div class="yprint-order-items">
            <?php 
            foreach ($cart_items as $cart_item_key => $cart_item) {
                $_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
                
                if ($_product && $_product->exists() && $cart_item['quantity'] > 0) {
                    $product_name = $_product->get_name();
                    $thumbnail = $_product->get_image('thumbnail', array('class' => 'yprint-item-image'));
                    $product_price = WC()->cart->get_product_price($_product);
                    $line_total = WC()->cart->get_product_subtotal($_product, $cart_item['quantity']);
                    $product_id = $_product->get_id();
                    ?>
                    <div class="yprint-order-item" data-cart-item-key="<?php echo esc_attr($cart_item_key); ?>" data-product-id="<?php echo esc_attr($product_id); ?>">
                        <div class="yprint-item-image-container">
                            <?php echo $thumbnail; ?>
                        </div>
                        <div class="yprint-item-details">
                            <span class="yprint-item-title"><?php echo esc_html($product_name); ?></span>
                            <span class="yprint-item-price"><?php echo $product_price; ?></span>
                            
                            <div class="yprint-item-quantity-wrapper">
                                <button type="button" class="yprint-item-quantity-btn yprint-item-quantity-minus" aria-label="<?php esc_attr_e('Decrease quantity', 'yprint-payment'); ?>">−</button>
                                <span class="yprint-item-quantity-value"><?php echo esc_html($cart_item['quantity']); ?></span>
                                <input type="number" class="yprint-item-quantity-input" value="<?php echo esc_attr($cart_item['quantity']); ?>" min="1" max="99" style="display: none;">
                                <button type="button" class="yprint-item-quantity-btn yprint-item-quantity-plus" aria-label="<?php esc_attr_e('Increase quantity', 'yprint-payment'); ?>">+</button>
                            </div>
                        </div>
                        <button type="button" class="yprint-item-remove" aria-label="<?php esc_attr_e('Remove item', 'yprint-payment'); ?>">&times;</button>
                        <span class="yprint-item-total"><?php echo $line_total; ?></span>
                    </div>
                    <?php 
                }
            } 
            ?>
        </div>

        <div class="yprint-summary-totals">
            <div class="yprint-subtotal">
                <span><?php esc_html_e('Subtotal', 'yprint-payment'); ?></span>
                <span><?php echo $cart_subtotal; ?></span>
            </div>
            
            <?php if (WC()->cart->needs_shipping() && WC()->cart->get_shipping_total() > 0) : ?>
            <div class="yprint-shipping">
                <span><?php esc_html_e('Shipping', 'yprint-payment'); ?></span>
                <span><?php echo $shipping_total; ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($has_discount) : ?>
            <div class="yprint-discount">
                <span><?php esc_html_e('Discount', 'yprint-payment'); ?></span>
                <span>-<?php echo wc_price($discount_total); ?></span>
            </div>
            <?php endif; ?>
            
            <?php 
            // Display any additional fees
            foreach (WC()->cart->get_fees() as $fee) : ?>
            <div class="yprint-fee">
                <span><?php echo esc_html($fee->name); ?></span>
                <span><?php echo wc_price($fee->amount); ?></span>
            </div>
            <?php endforeach; ?>
            
            <?php if (wc_tax_enabled() && !WC()->cart->display_prices_including_tax()) : ?>
                <?php foreach (WC()->cart->get_tax_totals() as $code => $tax) : ?>
                <div class="yprint-tax">
                    <span><?php echo esc_html($tax->label); ?></span>
                    <span><?php echo wp_kses_post($tax->formatted_amount); ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <div class="yprint-total">
                <span><?php esc_html_e('Total', 'yprint-payment'); ?></span>
                <span><?php echo $cart_total; ?></span>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.yprint-order-summary {
    width: 100%;
    font-family: 'Roboto', sans-serif;
    position: relative;
    padding: 0;
    margin-bottom: 30px;
}

.yprint-summary-title {
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 20px;
    color: #1d1d1f;
}

.yprint-empty-cart-message {
    padding: 30px;
    text-align: center;
    background-color: #f8f9fa;
    border-radius: 8px;
    color: #6c757d;
}

.yprint-empty-cart-message a {
    color: #0079FF;
    text-decoration: none;
    font-weight: 500;
    margin-left: 8px;
}

.yprint-empty-cart-message a:hover {
    text-decoration: underline;
}

.yprint-order-items {
    margin-bottom: 20px;
}

.yprint-order-item {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid #f5f5f7;
    position: relative;
}

.yprint-item-image-container {
    flex-shrink: 0;
    width: 64px;
    height: 64px;
}

.yprint-item-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 4px;
}

.yprint-item-details {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
}

.yprint-item-title {
    font-weight: 500;
    margin-bottom: 4px;
    color: #1d1d1f;
}

.yprint-item-price {
    color: #6e6e73;
    font-size: 14px;
    margin-bottom: 8px;
}

.yprint-item-quantity-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
}

.yprint-item-quantity-value {
    min-width: 24px;
    text-align: center;
    color: #0079FF;
    font-weight: 600;
    cursor: pointer;
}

.yprint-item-quantity-input {
    width: 40px;
    padding: 4px;
    text-align: center;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #0079FF;
    font-weight: 600;
}

.yprint-item-quantity-input::-webkit-outer-spin-button,
.yprint-item-quantity-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.yprint-item-quantity-input[type=number] {
    -moz-appearance: textfield;
}

.yprint-item-quantity-btn {
    width: 28px;
    height: 28px;
    border: 1px solid #e0e0e0;
    border-radius: 50%;
    background: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 16px;
    color: #1d1d1f;
    padding: 0;
    transition: all 0.2s ease;
}

.yprint-item-quantity-btn:hover {
    background-color: #f5f5f7;
    border-color: #d0d0d0;
}

.yprint-item-remove {
    background: transparent;
    border: none;
    cursor: pointer;
    color: #999;
    font-size: 18px;
    line-height: 1;
    padding: 5px;
    position: absolute;
    right: 75px;
    top: 0;
    transition: color 0.2s ease;
}

.yprint-item-remove:hover {
    color: #0079FF;
}

.yprint-item-total {
    font-weight: 600;
    color: #0079FF;
    margin-left: auto;
}

.yprint-summary-totals {
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #f5f5f7;
}

.yprint-subtotal, 
.yprint-shipping,
.yprint-discount,
.yprint-tax,
.yprint-fee,
.yprint-total {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    padding: 8px 0;
}

.yprint-subtotal span, 
.yprint-shipping span,
.yprint-discount span,
.yprint-tax span,
.yprint-fee span {
    color: #6e6e73;
}

.yprint-total {
    font-weight: 600;
    border-top: 1px solid #f5f5f7;
    padding-top: 12px;
    margin-top: 12px;
}

.yprint-total span {
    color: #1d1d1f;
    font-size: 18px;
}

.yprint-loading-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(255, 255, 255, 0.7);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 100;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s ease;
}

.yprint-loading-overlay.active {
    opacity: 1;
    visibility: visible;
}

.yprint-loader-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid rgba(0, 121, 255, 0.2);
    border-radius: 50%;
    border-top-color: #0079FF;
    animation: yprint-spin 1s linear infinite;
}

@keyframes yprint-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .yprint-order-item {
        flex-wrap: wrap;
    }
    
    .yprint-item-details {
        width: calc(100% - 80px);
    }
    
    .yprint-item-remove {
        position: relative;
        right: auto;
        top: auto;
        margin-right: 10px;
    }
    
    .yprint-item-total {
        width: 100%;
        text-align: right;
        margin-top: 10px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    const $orderSummary = $('#yprint-order-summary');
    const $loadingOverlay = $orderSummary.find('.yprint-loading-overlay');
    
    // Variable to track if editing is in progress
    let isEditing = false;
    
    // PLUS/MINUS BUTTONS
    $orderSummary.on('click', '.yprint-item-quantity-btn', function(e) {
        e.preventDefault();
        
        if (isEditing) return; // Ignore if editing is active
        
        const $btn = $(this);
        const $item = $btn.closest('.yprint-order-item');
        const $qtyValue = $item.find('.yprint-item-quantity-value');
        const cartItemKey = $item.data('cart-item-key');
        const currentQty = parseInt($qtyValue.text(), 10);
        const newQty = $btn.hasClass('yprint-item-quantity-minus') ? 
                    Math.max(1, currentQty - 1) : 
                    currentQty + 1;
        
        // Update quantity immediately for better UX
        $qtyValue.text(newQty);
        
        // Update quantity via AJAX
        updateCartQuantity(cartItemKey, newQty);
    });
    
    // DIRECT QUANTITY EDITING
    // Click on quantity value (to edit)
    $orderSummary.on('click', '.yprint-item-quantity-value', function() {
        if (isEditing) return;
        
        isEditing = true;
        const $value = $(this);
        const $input = $value.siblings('.yprint-item-quantity-input');
        
        // Prepare and show input field
        $input.val($value.text());
        $value.hide();
        $input.show().focus().select();
    });
    
    // Input field blur (finish editing)
    $orderSummary.on('blur', '.yprint-item-quantity-input', function() {
        finishQuantityEditing($(this));
    });
    
    // Enter key in input field
    $orderSummary.on('keypress', '.yprint-item-quantity-input', function(e) {
        if (e.which === 13) { // Enter
            e.preventDefault();
            finishQuantityEditing($(this));
        }
    });
    
    // Helper function to finish quantity editing
    function finishQuantityEditing($input) {
        const $item = $input.closest('.yprint-order-item');
        const $value = $item.find('.yprint-item-quantity-value');
        const cartItemKey = $item.data('cart-item-key');
        const oldQty = parseInt($value.text(), 10);
        let newQty = parseInt($input.val(), 10);
        
        // Ensure valid value
        if (isNaN(newQty) || newQty < 1) newQty = 1;
        if (newQty > 99) newQty = 99;
        
        // Reset UI
        $input.hide();
        $value.show();
        
        // End editing mode
        isEditing = false;
        
        // Only update if changed
        if (newQty !== oldQty) {
            $value.text(newQty);
            updateCartQuantity(cartItemKey, newQty);
        }
    }
    
    // REMOVE BUTTON
    $orderSummary.on('click', '.yprint-item-remove', function(e) {
        e.preventDefault();
        
        const $item = $(this).closest('.yprint-order-item');
        const cartItemKey = $item.data('cart-item-key');
        
        // Show loading overlay
        $loadingOverlay.addClass('active');
        
        // Send AJAX request to remove item
        $.ajax({
            url: wc_add_to_cart_params.ajax_url,
            type: 'POST',
            data: {
                action: 'yprint_remove_from_cart',
                cart_item_key: cartItemKey,
                security: yprint_params.checkout_nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    $loadingOverlay.removeClass('active');
                    console.error('Error removing item:', response.data?.message || 'Unknown error');
                    alert(response.data?.message || wc_add_to_cart_params.i18n_remove_item_notice);
                }
            },
            error: function(xhr, status, error) {
                $loadingOverlay.removeClass('active');
                console.error('AJAX error:', status, error);
                alert(wc_add_to_cart_params.i18n_remove_item_notice);
            }
        });
    });
    
    // Update cart quantity function
    function updateCartQuantity(cartItemKey, quantity) {
        // Show loading overlay
        $loadingOverlay.addClass('active');
        
        $.ajax({
            url: wc_add_to_cart_params.ajax_url,
            type: 'POST',
            data: {
                action: 'yprint_update_cart_quantity',
                cart_item_key: cartItemKey,
                quantity: quantity,
                security: yprint_params.checkout_nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    $loadingOverlay.removeClass('active');
                    console.error('Error updating quantity:', response.data?.message || 'Unknown error');
                    alert(response.data?.message || wc_add_to_cart_params.i18n_update_cart_notice);
                }
            },
            error: function(xhr, status, error) {
                $loadingOverlay.removeClass('active');
                console.error('AJAX error:', status, error);
                alert(wc_add_to_cart_params.i18n_update_cart_notice);
            }
        });
    }
    
    // Handle clicks outside the quantity input
    $(document).on('mousedown', function(e) {
        if (isEditing && !$(e.target).closest('.yprint-item-quantity-input, .yprint-item-quantity-value').length) {
            $orderSummary.find('.yprint-item-quantity-input:visible').blur();
        }
    });
    
    // Update cart on YPrintCheckoutSystem state changes
    $(document).on('checkoutStateUpdate', function(e, state) {
        if (state && state.cart && state.cart.updated) {
            location.reload();
        }
    });
});
</script>