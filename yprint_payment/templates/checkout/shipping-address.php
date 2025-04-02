<?php
/**
 * YPrint Payment - Shipping Address Template
 *
 * This template displays the shipping address form in the checkout process.
 * It allows customers to select, create, and edit shipping addresses.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!function_exists('WC')) {
    return;
}

// Get current user
$user_id = get_current_user_id();

// Load customer data if logged in
if ($user_id > 0) {
    $customer = new WC_Customer($user_id);
    
    // Primary address
    $primary_address = [
        'first_name' => $customer->get_shipping_first_name(),
        'last_name' => $customer->get_shipping_last_name(),
        'address_1' => $customer->get_shipping_address_1(),
        'address_2' => $customer->get_shipping_address_2(),
        'postcode' => $customer->get_shipping_postcode(),
        'city' => $customer->get_shipping_city(),
        'country' => $customer->get_shipping_country() ?: 'DE',
        'email' => $customer->get_billing_email(),
        'phone' => $customer->get_billing_phone(),
    ];
    
    // Load additional addresses if feature is enabled
    $additional_addresses_enabled = apply_filters('yprint_additional_addresses_enabled', true);
    if ($additional_addresses_enabled) {
        $additional_addresses = get_user_meta($user_id, 'yprint_additional_shipping_addresses', true) ?: [];
    } else {
        $additional_addresses = [];
    }
} else {
    // Default empty address for guests
    $primary_address = [
        'first_name' => '',
        'last_name' => '',
        'address_1' => '',
        'address_2' => '',
        'postcode' => '',
        'city' => '',
        'country' => 'DE',
        'email' => '',
        'phone' => '',
    ];
    $additional_addresses = [];
}

// Get current selected address slot from session
$current_slot = WC()->session ? WC()->session->get('yprint_selected_shipping_slot') : 'primary';
if (empty($current_slot)) {
    $current_slot = 'primary';
}

// Max number of additional addresses
$max_additional_addresses = apply_filters('yprint_max_additional_addresses', 2);
?>

<div class="yprint-shipping-address" id="yprint-shipping-address">
    <?php if ($user_id > 0 && !empty($additional_addresses_enabled)) : ?>
    <div class="yprint-address-selector">
        <h3 class="yprint-section-subtitle"><?php esc_html_e('Lieferadresse auswählen', 'yprint-payment'); ?></h3>
        <div class="yprint-address-slots">
            <!-- Primary address -->
            <div class="yprint-address-slot <?php echo ($current_slot === 'primary') ? 'active' : ''; ?>" 
                 data-slot="primary">
                <span class="yprint-slot-title"><?php esc_html_e('Standardadresse', 'yprint-payment'); ?></span>
                <?php if (!empty($primary_address['address_1'])) : ?>
                <span class="yprint-slot-preview">
                    <?php echo esc_html($primary_address['first_name'] . ' ' . $primary_address['last_name']); ?>, 
                    <?php echo esc_html($primary_address['address_1'] . ' ' . $primary_address['address_2']); ?>, 
                    <?php echo esc_html($primary_address['postcode'] . ' ' . $primary_address['city']); ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Additional addresses -->
            <?php foreach ($additional_addresses as $index => $address) : ?>
                <div class="yprint-address-slot <?php echo ($current_slot === 'secondary_' . $index) ? 'active' : ''; ?>" 
                     data-slot="secondary_<?php echo esc_attr($index); ?>">
                    <span class="yprint-slot-title"><?php echo esc_html(sprintf(__('Adresse %d', 'yprint-payment'), $index + 2)); ?></span>
                    <?php if (!empty($address['address_1'])) : ?>
                    <span class="yprint-slot-preview">
                        <?php echo esc_html($address['first_name'] . ' ' . $address['last_name']); ?>, 
                        <?php echo esc_html($address['address_1'] . ' ' . $address['address_2']); ?>, 
                        <?php echo esc_html($address['postcode'] . ' ' . $address['city']); ?>
                    </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Add new address button -->
            <?php if (count($additional_addresses) < $max_additional_addresses) : ?>
                <div class="yprint-address-slot yprint-new-address" data-slot="new">
                    <span class="yprint-slot-title">
                        <i class="yprint-icon-plus"></i> <?php esc_html_e('Neue Adresse hinzufügen', 'yprint-payment'); ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="yprint-form-section">
        <input type="hidden" name="current_address_slot" id="current_address_slot" value="<?php echo esc_attr($current_slot); ?>">
        
        <!-- Name fields -->
        <div class="yprint-form-row">
            <div class="yprint-form-field">
                <label for="shipping_first_name"><?php esc_html_e('Vorname', 'yprint-payment'); ?> <span class="required">*</span></label>
                <input type="text" 
                       id="shipping_first_name"
                       name="shipping_first_name" 
                       class="yprint-shipping-field"
                       value=""
                       required>
                <div class="yprint-field-error"></div>
            </div>
            
            <div class="yprint-form-field">
                <label for="shipping_last_name"><?php esc_html_e('Nachname', 'yprint-payment'); ?> <span class="required">*</span></label>
                <input type="text" 
                       id="shipping_last_name"
                       name="shipping_last_name" 
                       class="yprint-shipping-field"
                       value=""
                       required>
                <div class="yprint-field-error"></div>
            </div>
        </div>

        <!-- Address fields -->
        <div class="yprint-form-row">
            <div class="yprint-form-field yprint-form-field-large">
                <label for="shipping_address_1"><?php esc_html_e('Straße', 'yprint-payment'); ?> <span class="required">*</span></label>
                <input type="text" 
                       id="shipping_address_1"
                       name="shipping_address_1" 
                       class="yprint-shipping-field"
                       value=""
                       required>
                <div class="yprint-field-error"></div>
            </div>
            
            <div class="yprint-form-field yprint-form-field-small">
                <label for="shipping_address_2"><?php esc_html_e('Hausnummer', 'yprint-payment'); ?> <span class="required">*</span></label>
                <input type="text" 
                       id="shipping_address_2"
                       name="shipping_address_2" 
                       class="yprint-shipping-field"
                       value=""
                       required>
                <div class="yprint-field-error"></div>
            </div>
        </div>

        <!-- City and Postcode fields -->
        <div class="yprint-form-row">
            <div class="yprint-form-field yprint-form-field-small">
                <label for="shipping_postcode"><?php esc_html_e('PLZ', 'yprint-payment'); ?> <span class="required">*</span></label>
                <input type="text" 
                       id="shipping_postcode"
                       name="shipping_postcode" 
                       class="yprint-shipping-field"
                       value=""
                       required>
                <div class="yprint-field-error"></div>
            </div>
            
            <div class="yprint-form-field yprint-form-field-large">
                <label for="shipping_city"><?php esc_html_e('Ort', 'yprint-payment'); ?> <span class="required">*</span></label>
                <input type="text" 
                       id="shipping_city"
                       name="shipping_city" 
                       class="yprint-shipping-field"
                       value=""
                       required>
                <div class="yprint-field-error"></div>
            </div>
        </div>

        <!-- Country field -->
        <div class="yprint-form-row">
            <div class="yprint-form-field">
                <label for="shipping_country"><?php esc_html_e('Land', 'yprint-payment'); ?> <span class="required">*</span></label>
                <select id="shipping_country" name="shipping_country" class="yprint-shipping-field" required>
                    <?php
                    // Get countries list
                    $countries_obj = new WC_Countries();
                    $countries = $countries_obj->get_shipping_countries();
                    $default_country = !empty($primary_address['country']) ? $primary_address['country'] : $countries_obj->get_base_country();
                    
                    foreach ($countries as $code => $name) {
                        echo '<option value="' . esc_attr($code) . '" ' . 
                             selected($default_country, $code, false) . '>' . 
                             esc_html($name) . '</option>';
                    }
                    ?>
                </select>
                <div class="yprint-field-error"></div>
            </div>
        </div>

        <!-- Contact fields -->
        <div class="yprint-form-row">
            <div class="yprint-form-field">
                <label for="shipping_email"><?php esc_html_e('E-Mail', 'yprint-payment'); ?> <span class="required">*</span></label>
                <input type="email" 
                       id="shipping_email"
                       name="shipping_email" 
                       class="yprint-shipping-field"
                       value=""
                       required>
                <div class="yprint-field-error"></div>
            </div>
            
            <div class="yprint-form-field">
                <label for="shipping_phone"><?php esc_html_e('Telefon', 'yprint-payment'); ?></label>
                <input type="tel" 
                       id="shipping_phone"
                       name="shipping_phone" 
                       class="yprint-shipping-field"
                       value="">
                <div class="yprint-field-error"></div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Variables
    const primaryAddress = <?php echo json_encode($primary_address); ?>;
    const additionalAddresses = <?php echo json_encode($additional_addresses); ?>;
    let currentSlot = '<?php echo esc_js($current_slot); ?>';
    const shippingForm = $('#yprint-shipping-address');
    
    // Required fields for validation
    const requiredFields = [
        'shipping_first_name',
        'shipping_last_name',
        'shipping_address_1',
        'shipping_address_2',
        'shipping_postcode',
        'shipping_city',
        'shipping_country',
        'shipping_email'
    ];
    
    /**
     * Load address data to form fields
     */
    function loadAddressData(slot) {
        // Reset validation errors
        $('.yprint-field-error').empty().hide();
        
        let addressData = {};
        
        if (slot === 'primary') {
            addressData = primaryAddress;
        } else if (slot === 'new') {
            // Clear all fields for new address
            clearFormFields();
            return;
        } else if (slot.startsWith('secondary_')) {
            const index = parseInt(slot.replace('secondary_', ''), 10);
            if (additionalAddresses[index]) {
                addressData = additionalAddresses[index];
            } else {
                console.error('Address not found for slot:', slot);
                return;
            }
        } else {
            console.error('Invalid address slot:', slot);
            return;
        }
        
        // Fill form fields with address data
        Object.keys(addressData).forEach(key => {
            const fieldName = `shipping_${key}`;
            const $field = $(`[name="${fieldName}"]`);
            
            if ($field.length) {
                $field.val(addressData[key] || '');
            }
        });
        
        // Update YPrintCheckoutSystem state if available
        if (typeof YPrintCheckoutSystem !== 'undefined') {
            YPrintCheckoutSystem.updateState('shippingAddress', {
                ...addressData,
                slot: slot
            });
        }
    }
    
    /**
     * Clear all form fields
     */
    function clearFormFields() {
        $('.yprint-shipping-field').val('');
    }
    
    /**
     * Validate form fields
     */
    function validateFormFields() {
        let isValid = true;
        
        // Check required fields
        requiredFields.forEach(fieldName => {
            const $field = $(`[name="${fieldName}"]`);
            const $errorContainer = $field.siblings('.yprint-field-error');
            
            if (!$field.val().trim()) {
                isValid = false;
                $errorContainer.text('<?php esc_html_e('Dieses Feld ist erforderlich.', 'yprint-payment'); ?>').show();
                $field.addClass('error');
            } else {
                $errorContainer.empty().hide();
                $field.removeClass('error');
            }
        });
        
        // Validate email field
        const $emailField = $('[name="shipping_email"]');
        if ($emailField.val().trim() && !isValidEmail($emailField.val())) {
            isValid = false;
            $emailField.siblings('.yprint-field-error').text('<?php esc_html_e('Bitte gib eine gültige E-Mail-Adresse ein.', 'yprint-payment'); ?>').show();
            $emailField.addClass('error');
        }
        
        return isValid;
    }
    
    /**
     * Validate email format
     */
    function isValidEmail(email) {
        const regex = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*$/;
        return regex.test(email);
    }
    
    /**
     * Save current address
     */
    function saveCurrentAddress() {
        // Validate before saving
        if (!validateFormFields()) {
            return false;
        }
        
        const addressData = {};
        
        // Collect form field values
        $('.yprint-shipping-field').each(function() {
            const fieldName = $(this).attr('name');
            const key = fieldName.replace('shipping_', '');
            addressData[key] = $(this).val();
        });
        
        // Add slot information
        addressData.slot = currentSlot;
        
        // Update state in YPrintCheckoutSystem
        if (typeof YPrintCheckoutSystem !== 'undefined') {
            YPrintCheckoutSystem.updateState('shippingAddress', addressData);
        }
        
        // If user is logged in, save address to user meta via AJAX
        if (<?php echo $user_id > 0 ? 'true' : 'false'; ?>) {
            $.ajax({
                url: yprint_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'yprint_save_shipping_address',
                    address: addressData,
                    slot: currentSlot,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update address preview if successful
                        updateAddressPreview(currentSlot, addressData);
                    } else {
                        console.error('Error saving address:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error saving address:', error);
                }
            });
        }
        
        return true;
    }
    
    /**
     * Update address preview in slot selector
     */
    function updateAddressPreview(slot, address) {
        if (!address) return;
        
        const $slot = $(`.yprint-address-slot[data-slot="${slot}"]`);
        if (!$slot.length) return;
        
        const previewText = `${address.first_name} ${address.last_name}, ${address.address_1} ${address.address_2}, ${address.postcode} ${address.city}`;
        
        let $preview = $slot.find('.yprint-slot-preview');
        if (!$preview.length) {
            $preview = $('<span class="yprint-slot-preview"></span>').appendTo($slot);
        }
        
        $preview.text(previewText);
    }
    
    // Address slot selection
    $('.yprint-address-slot').on('click', function() {
        const newSlot = $(this).data('slot');
        
        // Save current address before switching
        if (currentSlot !== newSlot) {
            saveCurrentAddress();
        }
        
        // Update UI
        $('.yprint-address-slot').removeClass('active');
        $(this).addClass('active');
        
        // Update current slot
        currentSlot = newSlot;
        $('#current_address_slot').val(newSlot);
        
        // Load address data
        loadAddressData(newSlot);
        
        // Update session via AJAX
        $.ajax({
            url: yprint_params.ajax_url,
            type: 'POST',
            data: {
                action: 'yprint_update_shipping_slot',
                slot: newSlot,
                security: yprint_params.checkout_nonce
            }
        });
    });
    
    // Form field change event
    $('.yprint-shipping-field').on('change input blur', function() {
        const fieldName = $(this).attr('name');
        const key = fieldName.replace('shipping_', '');
        const value = $(this).val();
        
        // Real-time validation
        if ($(this).prop('required') && !value.trim()) {
            $(this).addClass('error');
            $(this).siblings('.yprint-field-error').text('<?php esc_html_e('Dieses Feld ist erforderlich.', 'yprint-payment'); ?>').show();
        } else if (fieldName === 'shipping_email' && value.trim() && !isValidEmail(value)) {
            $(this).addClass('error');
            $(this).siblings('.yprint-field-error').text('<?php esc_html_e('Bitte gib eine gültige E-Mail-Adresse ein.', 'yprint-payment'); ?>').show();
        } else {
            $(this).removeClass('error');
            $(this).siblings('.yprint-field-error').empty().hide();
        }
        
        // Update YPrintCheckoutSystem state if available
        if (typeof YPrintCheckoutSystem !== 'undefined') {
            YPrintCheckoutSystem.updateState('shippingAddress', {
                [key]: value
            });
        }
    });
    
    // Listen for checkout state updates
    $(document).on('checkoutStateUpdate', function(e, state) {
        if (state && state.shippingAddress) {
            // Only update if same slot or no slot defined
            if (!state.shippingAddress.slot || 
                state.shippingAddress.slot === currentSlot) {
                
                Object.keys(state.shippingAddress).forEach(key => {
                    if (key !== 'slot') {
                        const $field = $(`[name="shipping_${key}"]`);
                        if ($field.length && !$field.is(':focus')) {
                            $field.val(state.shippingAddress[key] || '');
                        }
                    }
                });
            }
        }
    });
    
    // Form submission validation
    $(document).on('checkout_place_order', function() {
        return saveCurrentAddress();
    });
    
    // Load initial address
    loadAddressData(currentSlot);
});
</script>

<style>
.yprint-shipping-address {
    width: 100%;
    font-family: 'Roboto', -apple-system, BlinkMacSystemFont, sans-serif;
    margin-bottom: 30px;
}

.yprint-address-selector {
    margin-bottom: 20px;
}

.yprint-section-subtitle {
    font-size: 16px;
    font-weight: 500;
    margin-bottom: 15px;
    color: #555;
}

.yprint-address-slots {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.yprint-address-slot {
    flex: 1;
    min-width: 200px;
    padding: 15px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    background-color: #f9f9f9;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
}

.yprint-address-slot:hover {
    border-color: #0079FF;
    background-color: #f5f9ff;
}

.yprint-address-slot.active {
    border-color: #0079FF;
    background-color: #f0f6ff;
    box-shadow: 0 0 0 2px rgba(0, 121, 255, 0.1);
}

.yprint-slot-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 5px;
    color: #333;
}

.yprint-slot-preview {
    font-size: 13px;
    color: #666;
    line-height: 1.3;
}

.yprint-new-address {
    border-style: dashed;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: #0079FF;
}

.yprint-icon-plus:before {
    content: "+";
    font-size: 16px;
    margin-right: 5px;
    font-weight: bold;
}

.yprint-form-section {
    width: 100%;
}

.yprint-form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 15px;
}

.yprint-form-field {
    flex: 1;
    min-width: 200px;
    position: relative;
}

.yprint-form-field-large {
    flex: 3;
}

.yprint-form-field-small {
    flex: 1;
}

.yprint-form-field label {
    display: block;
    font-size: 14px;
    margin-bottom: 5px;
    color: #555;
}

.yprint-form-field label .required {
    color: #e02b27;
}

.yprint-shipping-field {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    font-size: 14px;
    line-height: 1.5;
    color: #333;
    transition: border-color 0.2s ease;
}

.yprint-shipping-field:focus {
    border-color: #0079FF;
    box-shadow: 0 0 0 2px rgba(0, 121, 255, 0.1);
    outline: none;
}

.yprint-shipping-field.error {
    border-color: #e02b27;
}

.yprint-field-error {
    color: #e02b27;
    font-size: 12px;
    margin-top: 4px;
    display: none;
}

/* Responsive styles */
@media (max-width: 768px) {
    .yprint-form-row {
        flex-direction: column;
        gap: 10px;
    }
    
    .yprint-form-field {
        min-width: 100%;
    }
    
    .yprint-address-slot {
        min-width: 100%;
    }
}
</style>