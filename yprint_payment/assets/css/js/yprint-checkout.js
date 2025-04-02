/**
 * YPrint Checkout System
 * 
 * Manages the checkout process including:
 * - State management
 * - Form validation
 * - Payment processing
 * - AJAX communication with the server
 * 
 * @package YPrint_Payment
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * YPrint Checkout System
     * Central object that manages the entire checkout process
     */
    window.YPrintCheckoutSystem = {
        /**
         * Checkout state
         * Stores the current state of the checkout form
         */
        state: {
            shippingAddress: {},
            billingAddress: {},
            differentBilling: { enabled: false },
            differentBillingAddress: {},
            paymentMethod: {},
            couponCode: {},
            validationErrors: []
        },

        /**
         * Gateway handlers
         * References to specific payment gateway processors
         */
        gateways: {
            stripe: null,
            paypal: null,
            sepa: null,
            bank_transfer: null
        },

        /**
         * Initialization
         * Sets up the checkout system and loads initial state
         */
        init: function() {
            this.initializeState();
            this.setupEventListeners();
            this.initializeGateways();
            
            // Trigger ready event
            $(document).trigger('yprint_checkout_ready', [this.state]);
            
            console.log('YPrint Checkout System initialized');
        },

        /**
         * Initialize state from server
         * Loads the initial checkout state via AJAX
         */
        initializeState: function() {
            const self = this;
            const user_id = yprint_params.user_id || '0';
            
            // AJAX call to load initial data
            $.ajax({
                url: yprint_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'yprint_get_checkout_state',
                    user_id: user_id,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Ensure differentBilling is always initially disabled
                        const data = {...response.data};
                        if (data.differentBilling) {
                            data.differentBilling.enabled = false;
                        }
                        self.state = {...self.state, ...data};
                        self.updateAllFields();
                        self.notifyStateChange();
                    } else {
                        self.showError('Error loading checkout state: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    self.showError('Error connecting to server: ' + error);
                    console.error('YPrint Checkout System init error:', xhr, status, error);
                }
            });
        },

        /**
         * Setup event listeners
         * Attaches event handlers to form elements
         */
        setupEventListeners: function() {
            const self = this;
            
            // Shipping Address Events
            $(document).on('change', '.yprint-shipping-field', function(e) {
                const field = e.target.name.replace('shipping_', '');
                const value = e.target.value;
                self.updateState('shippingAddress', {
                    [field]: value
                });
            });

            // Different Billing Events
            $(document).on('change', '#different_billing', function(e) {
                self.updateState('differentBilling', {
                    enabled: e.target.checked
                });
                
                // Toggle the visibility of billing fields
                if (e.target.checked) {
                    $('#different_billing_fields').slideDown(300);
                } else {
                    $('#different_billing_fields').slideUp(300);
                }
            });

            // Different Billing Address Events
            $(document).on('change', '.yprint-billing-field', function(e) {
                const field = e.target.name;
                const value = e.target.value;
                self.updateState('differentBillingAddress', {
                    [field]: value
                });
            });

            // Payment Method Events
            $(document).on('change', '[name="payment_method"]', function(e) {
                self.updateState('paymentMethod', {
                    method: e.target.value,
                    timestamp: Date.now()
                });
                
                // Update payment method visuals
                $('.yprint-payment-option').removeClass('selected');
                $(e.target).closest('.yprint-payment-option').addClass('selected');
                
                // Show specific payment fields if applicable
                self.togglePaymentFields(e.target.value);
            });

            // Coupon Events
            $(document).on('change', '#coupon_code', function(e) {
                self.updateState('couponCode', {
                    code: e.target.value
                });
            });
            
            // Apply coupon button
            $(document).on('click', '#apply_coupon', function(e) {
                e.preventDefault();
                self.applyCoupon();
            });
            
            // Checkout submit button
            $(document).on('click', '#yprint_checkout_submit', function(e) {
                e.preventDefault();
                self.processCheckout();
            });
            
            // Order item quantity adjustment
            $(document).on('click', '.yprint-item-quantity-btn', function(e) {
                const $btn = $(this);
                const $item = $btn.closest('.yprint-order-item');
                const $quantity = $item.find('.yprint-item-quantity-value');
                const cartItemKey = $btn.data('cart-item-key');
                const currentQty = parseInt($quantity.text());
                let newQty = currentQty;
                
                if ($btn.hasClass('yprint-item-quantity-plus')) {
                    newQty = currentQty + 1;
                } else if ($btn.hasClass('yprint-item-quantity-minus')) {
                    newQty = Math.max(1, currentQty - 1);
                }
                
                if (newQty !== currentQty) {
                    self.updateCartQuantity(cartItemKey, newQty);
                }
            });
            
            // Remove item from cart
            $(document).on('click', '.yprint-item-remove', function(e) {
                const cartItemKey = $(this).data('cart-item-key');
                self.removeCartItem(cartItemKey);
            });
            
            // Form validation
            $(document).on('blur', '.yprint-required-field', function(e) {
                self.validateField(e.target);
            });
            
            // Handle address slot selection
            $(document).on('click', '.yprint-address-slot', function(e) {
                const slot = $(this).data('slot');
                $('.yprint-address-slot').removeClass('active');
                $(this).addClass('active');
                $('#current_address_slot').val(slot);
                
                self.loadAddressData(slot);
            });
        },
        
        /**
         * Initialize payment gateways
         * Sets up payment gateway handlers
         */
        initializeGateways: function() {
            // Only initialize enabled gateways
            if (yprint_params.stripe_enabled) {
                this.initStripeGateway();
            }
            
            if (yprint_params.paypal_enabled) {
                this.initPayPalGateway();
            }
            
            if (yprint_params.sepa_enabled) {
                this.initSepaGateway();
            }
            
            // Bank transfer doesn't need special initialization
            this.gateways.bank_transfer = {
                processPayment: this.processBankTransferPayment.bind(this)
            };
        },
        
        /**
         * Initialize Stripe gateway
         * Sets up Stripe JS integration
         */
        initStripeGateway: function() {
            const self = this;
            
            // Check if Stripe JS is available
            if (typeof Stripe === 'undefined') {
                console.error('Stripe JS not loaded');
                return;
            }
            
            try {
                const stripe = Stripe(yprint_params.stripe_public_key);
                
                this.gateways.stripe = {
                    stripe: stripe,
                    elements: null,
                    paymentElement: null,
                    form: null,
                    clientSecret: null,
                    processPayment: this.processStripePayment.bind(this)
                };
                
                console.log('Stripe gateway initialized');
            } catch (error) {
                console.error('Error initializing Stripe:', error);
            }
        },
        
        /**
         * Initialize PayPal gateway
         * Sets up PayPal SDK integration
         */
        initPayPalGateway: function() {
            const self = this;
            
            // Check if PayPal SDK is available
            if (typeof paypal === 'undefined') {
                console.error('PayPal SDK not loaded');
                return;
            }
            
            try {
                this.gateways.paypal = {
                    processPayment: this.processPayPalPayment.bind(this)
                };
                
                console.log('PayPal gateway initialized');
            } catch (error) {
                console.error('Error initializing PayPal:', error);
            }
        },
        
        /**
         * Initialize SEPA gateway
         * Sets up SEPA integration
         */
        initSepaGateway: function() {
            const self = this;
            
            this.gateways.sepa = {
                processPayment: this.processSepaPayment.bind(this)
            };
            
            console.log('SEPA gateway initialized');
        },

        /**
         * Update checkout state
         * Updates a specific section of the checkout state
         * 
         * @param {string} section - The state section to update
         * @param {object} data - The data to update
         */
        updateState: function(section, data) {
            if (!this.state[section]) {
                this.state[section] = {};
            }
            
            // Update the section in the state
            this.state[section] = {
                ...this.state[section],
                ...data
            };
            
            this.saveState();
            this.notifyStateChange();
        },

        /**
         * Save state to server
         * Persists the current checkout state via AJAX
         */
        saveState: function() {
            $.ajax({
                url: yprint_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'yprint_save_checkout_state',
                    state: this.state,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    if (!response.success) {
                        console.error('Error saving checkout state:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error saving state:', xhr, status, error);
                }
            });
        },

        /**
         * Notify state change
         * Triggers an event to notify state changes
         */
        notifyStateChange: function() {
            $(document).trigger('checkoutStateUpdate', [this.state]);
        },
        
        /**
         * Update all form fields
         * Updates all form fields with the current state values
         */
        updateAllFields: function() {
            const $ = jQuery;
            
            // Shipping Address Fields
            if (this.state.shippingAddress) {
                Object.entries(this.state.shippingAddress).forEach(([field, value]) => {
                    if (field !== 'slot' && field !== 'address' && field !== 'field') {
                        $(`[name="shipping_${field}"]`).val(value);
                    }
                });
            }

            // Different Billing Checkbox
            if (this.state.differentBilling && this.state.differentBilling.enabled !== undefined) {
                $('#different_billing').prop('checked', this.state.differentBilling.enabled);
                
                // Show/hide different address fields
                if (this.state.differentBilling.enabled) {
                    $('#different_billing_fields').show();
                } else {
                    $('#different_billing_fields').hide();
                }
            }

            // Different Billing Address Fields
            if (this.state.differentBillingAddress) {
                Object.entries(this.state.differentBillingAddress).forEach(([field, value]) => {
                    $(`[name="${field}"]`).val(value);
                });
            }

            // Payment Method
            if (this.state.paymentMethod && this.state.paymentMethod.method) {
                const paymentMethod = this.state.paymentMethod.method;
                $(`[name="payment_method"][value="${paymentMethod}"]`)
                    .prop('checked', true)
                    .closest('.yprint-payment-option')
                    .addClass('selected');
                    
                // Show specific payment fields
                this.togglePaymentFields(paymentMethod);
            }

            // Coupon Code
            if (this.state.couponCode && this.state.couponCode.code) {
                $('#coupon_code').val(this.state.couponCode.code);
            }
        },
        
        /**
         * Toggle payment specific fields
         * Shows/hides fields specific to a payment method
         * 
         * @param {string} method - The payment method
         */
        togglePaymentFields: function(method) {
            // Hide all payment specific fields
            $('.yprint-payment-specific-fields').hide();
            
            // Show fields for the selected method
            $(`.yprint-${method}-fields`).show();
        },
        
        /**
         * Load address data
         * Loads address data for a specific slot
         * 
         * @param {string} slot - The address slot to load
         */
        loadAddressData: function(slot) {
            let addressData;
            
            if (slot === 'primary') {
                addressData = this.state.shippingAddress || {};
            } else if (slot === 'new') {
                $('.yprint-shipping-field').val('');
                return;
            } else {
                const index = slot.replace('secondary_', '');
                const secondaryAddresses = yprint_params.secondary_addresses || [];
                addressData = secondaryAddresses[index] || {};
            }

            // Fill fields
            Object.entries(addressData).forEach(([key, value]) => {
                if (key !== 'slot') {
                    $(`[name="shipping_${key}"]`).val(value);
                }
            });

            // Update state with slot information
            this.updateState('shippingAddress', {
                slot: slot,
                ...addressData
            });
            
            // Update server with selected slot
            $.ajax({
                url: yprint_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'yprint_update_shipping_slot',
                    slot: slot,
                    security: yprint_params.checkout_nonce
                }
            });
        },

        /**
         * Validate the checkout form
         * Checks if all required fields are filled
         * 
         * @returns {object} Validation result with status and errors
         */
        validateForm: function() {
            let isValid = true;
            const errors = [];
            this.state.validationErrors = [];
            
            // Validate Shipping Address
            if (!this.validateShippingAddress()) {
                isValid = false;
                errors.push('Bitte gib eine vollständige Lieferadresse an');
            }
            
            // Validate Payment Method
            if (!this.state.paymentMethod || !this.state.paymentMethod.method) {
                isValid = false;
                errors.push('Bitte wähle eine Zahlungsmethode aus');
                $('.yprint-payment-options').addClass('yprint-error');
            } else {
                $('.yprint-payment-options').removeClass('yprint-error');
            }

            // Validate Different Billing Address if enabled
            if (this.state.differentBilling && this.state.differentBilling.enabled) {
                if (!this.validateBillingAddress()) {
                    isValid = false;
                    errors.push('Bitte gib eine vollständige abweichende Rechnungsadresse an');
                }
            }
            
            // Validate Privacy checkbox
            if (!$('#privacy_checkbox').is(':checked')) {
                isValid = false;
                errors.push('Bitte akzeptiere die Datenschutzerklärung');
                $('#privacy_error').text('Bitte akzeptiere die Datenschutzerklärung').show();
            } else {
                $('#privacy_error').hide();
            }
            
            // Store validation errors in state
            this.state.validationErrors = errors;
            
            return {
                isValid: isValid,
                errors: errors
            };
        },
        
        /**
         * Validate shipping address
         * Ensures all required shipping address fields are filled
         * 
         * @returns {boolean} Whether the shipping address is valid
         */
        validateShippingAddress: function() {
            const requiredFields = [
                'first_name', 'last_name', 'address_1', 'postcode', 'city', 'country'
            ];
            
            let isValid = true;
            
            requiredFields.forEach(field => {
                const $field = $(`[name="shipping_${field}"]`);
                
                if (!$field.val()) {
                    isValid = false;
                    $field.addClass('yprint-error');
                } else {
                    $field.removeClass('yprint-error');
                }
            });
            
            return isValid;
        },
        
        /**
         * Validate billing address
         * Ensures all required billing address fields are filled
         * 
         * @returns {boolean} Whether the billing address is valid
         */
        validateBillingAddress: function() {
            const requiredFields = [
                'different_billing_first_name', 
                'different_billing_last_name', 
                'different_billing_address_1', 
                'different_billing_postcode', 
                'different_billing_city', 
                'different_billing_country',
                'different_billing_email'
            ];
            
            let isValid = true;
            
            requiredFields.forEach(field => {
                const $field = $(`[name="${field}"]`);
                
                if (!$field.val()) {
                    isValid = false;
                    $field.addClass('yprint-error');
                } else {
                    $field.removeClass('yprint-error');
                }
            });
            
            return isValid;
        },
        
        /**
         * Validate a specific field
         * Checks if a field is valid and shows error state if not
         * 
         * @param {HTMLElement} field - The field to validate
         * @returns {boolean} Whether the field is valid
         */
        validateField: function(field) {
            const $field = $(field);
            const value = $field.val();
            let isValid = true;
            
            // Check if required field is empty
            if ($field.hasClass('yprint-required-field') && !value) {
                isValid = false;
            }
            
            // Email validation
            if ($field.attr('type') === 'email' && value) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                isValid = emailRegex.test(value);
            }
            
            // Postcode validation for Germany
            if ($field.attr('name') && $field.attr('name').indexOf('postcode') !== -1 && value) {
                const country = this.state.shippingAddress.country || 'DE';
                
                if (country === 'DE') {
                    // German postcodes are 5 digits
                    isValid = /^\d{5}$/.test(value);
                }
            }
            
            // Show/hide error state
            if (isValid) {
                $field.removeClass('yprint-error');
            } else {
                $field.addClass('yprint-error');
            }
            
            return isValid;
        },

        /**
         * Get checkout form data
         * Collects all checkout form data
         * 
         * @returns {object} Checkout form data
         */
        getFormData: function() {
            return {
                shipping_address: this.state.shippingAddress,
                billing_address: this.state.billingAddress,
                different_billing: this.state.differentBilling ? this.state.differentBilling.enabled : false,
                different_billing_address: this.state.differentBillingAddress,
                payment_method: this.state.paymentMethod ? this.state.paymentMethod.method : '',
                coupon_code: this.state.couponCode ? this.state.couponCode.code : ''
            };
        },
        
        /**
         * Apply coupon
         * Applies a coupon code to the order
         */
        applyCoupon: function() {
            const self = this;
            const couponCode = this.state.couponCode ? this.state.couponCode.code : '';
            const $couponMessage = $('.yprint-coupon-message');
            const $couponButton = $('#apply_coupon');
            
            if (!couponCode) {
                this.showCouponMessage('Bitte gib einen Gutschein-Code ein.', 'error');
                return;
            }
            
            // Disable button during request
            $couponButton.prop('disabled', true);
            
            $.ajax({
                type: 'POST',
                url: yprint_params.ajax_url,
                data: {
                    action: 'yprint_apply_coupon',
                    coupon_code: couponCode,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    $couponButton.prop('disabled', false);
                    
                    if (response.success) {
                        self.showCouponMessage('Gutschein wurde erfolgreich angewendet.', 'success');
                        
                        // Reload to update prices
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        self.showCouponMessage(response.data || 'Gutschein konnte nicht angewendet werden.', 'error');
                    }
                },
                error: function() {
                    $couponButton.prop('disabled', false);
                    self.showCouponMessage('Ein Fehler ist aufgetreten. Bitte versuche es erneut.', 'error');
                }
            });
        },
        
        /**
         * Show coupon message
         * Displays a message about coupon status
         * 
         * @param {string} text - The message text
         * @param {string} type - The message type (success/error)
         */
        showCouponMessage: function(text, type) {
            const $couponMessage = $('.yprint-coupon-message');
            
            $couponMessage
                .text(text)
                .removeClass('success error')
                .addClass(type)
                .fadeIn();
            
            // Hide after 3 seconds
            setTimeout(function() {
                $couponMessage.fadeOut();
            }, 3000);
        },
        
        /**
         * Update cart quantity
         * Changes the quantity of a cart item
         * 
         * @param {string} cartItemKey - The cart item key
         * @param {number} quantity - The new quantity
         */
        updateCartQuantity: function(cartItemKey, quantity) {
            const self = this;
            const $orderSummary = $('#yprint-order-summary');
            
            // Show loading overlay
            $orderSummary.find('.yprint-loading-overlay').addClass('active');
            
            $.ajax({
                url: yprint_params.ajax_url,
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
                        $orderSummary.find('.yprint-loading-overlay').removeClass('active');
                        self.showError(response.data || 'Ein Fehler ist aufgetreten.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error updating quantity:', status, error);
                    $orderSummary.find('.yprint-loading-overlay').removeClass('active');
                    self.showError('Ein Fehler ist aufgetreten.');
                }
            });
        },
        
        /**
         * Remove cart item
         * Removes an item from the cart
         * 
         * @param {string} cartItemKey - The cart item key
         */
        removeCartItem: function(cartItemKey) {
            const $orderSummary = $('#yprint-order-summary');
            
            // Show loading overlay
            $orderSummary.find('.yprint-loading-overlay').addClass('active');
            
            $.ajax({
                url: yprint_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'yprint_remove_from_cart',
                    cart_item_key: cartItemKey,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    // Reload after success
                    if (response.cart_count !== undefined) {
                        location.reload();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error removing item:', status, error);
                    $orderSummary.find('.yprint-loading-overlay').removeClass('active');
                    self.showError('Ein Fehler ist aufgetreten.');
                }
            });
        },

        /**
         * Process checkout
         * Starts the checkout process
         */
        processCheckout: function() {
            const self = this;
            const $buyButton = $('#yprint_checkout_submit');
            const $validationFeedback = $('.yprint-validation-feedback');
            
            // Check if cart is empty
            if (yprint_params.cart_is_empty) {
                this.showValidationErrors(['Dein Warenkorb ist leer.']);
                return;
            }
            
            // Button loading state
            $buyButton.addClass('loading');
            $validationFeedback.hide();
    
            // Form validation
            const validation = this.validateForm();
            
            if (!validation.isValid) {
                // Show errors
                this.showValidationErrors(validation.errors);
                $buyButton.removeClass('loading');
                return;
            }
    
            // Get checkout data
            const checkoutData = this.getFormData();
            const paymentMethod = checkoutData.payment_method;
            
            console.log('Starting checkout with payment method:', paymentMethod);
            
            // First prepare the order
            this.prepareOrder(checkoutData, function(prepareResult) {
                if (!prepareResult.success) {
                    self.showValidationErrors([prepareResult.message || 'Error preparing order']);
                    $buyButton.removeClass('loading');
                    return;
                }
                
                const tempOrderId = prepareResult.temp_order_id;
                
                // Process payment based on selected method
                self.processPayment(paymentMethod, checkoutData, tempOrderId, function(paymentResult) {
                    if (!paymentResult.success) {
                        self.showValidationErrors([paymentResult.message || 'Payment processing failed']);
                        $buyButton.removeClass('loading');
                        return;
                    }
                    
                    // Finalize order after successful payment
                    self.finalizeOrder(tempOrderId, paymentResult.payment_id, paymentMethod, function(finalizeResult) {
                        $buyButton.removeClass('loading');
                        
                        if (finalizeResult.success) {
                            // Redirect to thank you page
                            window.location.href = finalizeResult.redirect;
                        } else {
                            self.showValidationErrors([finalizeResult.message || 'Error finalizing order']);
                        }
                    });
                });
            });
        },
        
        /**
         * Prepare order
         * Creates a temporary order
         * 
         * @param {object} checkoutData - The checkout data
         * @param {function} callback - The callback function
         */
        prepareOrder: function(checkoutData, callback) {
            $.ajax({
                type: 'POST',
                url: yprint_params.ajax_url,
                data: {
                    action: 'yprint_prepare_order',
                    checkout_data: checkoutData,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    if (response.success && response.data.temp_order_id) {
                        callback({
                            success: true,
                            temp_order_id: response.data.temp_order_id
                        });
                    } else {
                        callback({
                            success: false,
                            message: response.data ? response.data.message : 'Error preparing order'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error preparing order:', xhr, status, error);
                    
                    let errorMessage = 'Connection error while preparing order';
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response && response.data && response.data.message) {
                            errorMessage = response.data.message;
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e);
                    }
                    
                    callback({
                        success: false,
                        message: errorMessage
                    });
                }
            });
        },
        
        /**
         * Process payment
         * Processes the payment using the selected gateway
         * 
         * @param {string} paymentMethod - The payment method
         * @param {object} checkoutData - The checkout data
         * @param {number} tempOrderId - The temporary order ID
         * @param {function} callback - The callback function
         */
        processPayment: function(paymentMethod, checkoutData, tempOrderId, callback) {
            // Determine which gateway to use
            let gateway = null;
            
            if (paymentMethod.includes('stripe')) {
                gateway = this.gateways.stripe;
            } else if (paymentMethod.includes('paypal')) {
                gateway = this.gateways.paypal;
            } else if (paymentMethod.includes('sepa')) {
                gateway = this.gateways.sepa;
            } else if (paymentMethod.includes('bacs') || paymentMethod.includes('bank')) {
                gateway = this.gateways.bank_transfer;
            } else {
                // Default payment processor for unknown methods
                callback({
                    success: false,
                    message: 'Unbekannte Zahlungsmethode: ' + paymentMethod
                });
                return;
            }
            
            if (!gateway) {
                callback({
                    success: false,
                    message: 'Payment gateway not initialized: ' + paymentMethod
                });
                return;
            }
            
            // Process payment with the selected gateway
            gateway.processPayment(checkoutData, tempOrderId, callback);
        },
        
        /**
         * Finalize order
         * Finalizes the order after successful payment
         * 
         * @param {number} tempOrderId - The temporary order ID
         * @param {string} paymentId - The payment ID
         * @param {string} paymentMethod - The payment method
         * @param {function} callback - The callback function
         */
        finalizeOrder: function(tempOrderId, paymentId, paymentMethod, callback) {
            $.ajax({
                type: 'POST',
                url: yprint_params.ajax_url,
                data: {
                    action: 'yprint_finalize_order',
                    temp_order_id: tempOrderId,
                    payment_id: paymentId,
                    payment_method: paymentMethod,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    if (response.success) {
                        callback({
                            success: true,
                            order_id: response.data.order_id,
                            redirect: response.data.redirect
                        });
                    } else {
                        callback({
                            success: false,
                            message: response.data ? response.data.message : 'Error finalizing order'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error finalizing order:', xhr, status, error);
                    
                    let errorMessage = 'Connection error while finalizing order';
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response && response.data && response.data.message) {
                            errorMessage = response.data.message;
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e);
                    }
                    
                    callback({
                        success: false,
                        message: errorMessage
                    });
                }
            });
        },
        
        /**
         * Process Stripe payment
         * Handles Stripe payment processing
         * 
         * @param {object} checkoutData - The checkout data
         * @param {number} tempOrderId - The temporary order ID
         * @param {function} callback - The callback function
         */
        processStripePayment: function(checkoutData, tempOrderId, callback) {
            const self = this;
            const $ = jQuery;
            
            if (!this.gateways.stripe || !this.gateways.stripe.stripe) {
                callback({
                    success: false,
                    message: 'Stripe not initialized'
                });
                return;
            }
            
            const stripe = this.gateways.stripe.stripe;
            
            // Initialize Stripe session
            $.ajax({
                type: 'POST',
                url: yprint_params.ajax_url,
                data: {
                    action: 'yprint_init_stripe_payment',
                    checkout_data: checkoutData,
                    temp_order_id: tempOrderId,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    if (response.success && response.data.client_secret) {
                        const clientSecret = response.data.client_secret;
                        const paymentIntentId = response.data.payment_intent_id;
                        
                        // Store client secret for later use
                        self.gateways.stripe.clientSecret = clientSecret;
                        
                        // SCA check - show Stripe Elements UI if required
                        if (response.data.is_sca_required) {
                            self.handleStripeWithSCA(clientSecret, paymentIntentId, callback);
                        } else {
                            // No SCA required, confirm payment directly
                            stripe.confirmCardPayment(clientSecret)
                                .then(function(result) {
                                    if (result.error) {
                                        callback({
                                            success: false,
                                            message: result.error.message
                                        });
                                    } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                                        callback({
                                            success: true,
                                            payment_id: paymentIntentId
                                        });
                                    } else {
                                        callback({
                                            success: false,
                                            message: 'Unbekannter Stripe-Fehler'
                                        });
                                    }
                                })
                                .catch(function(error) {
                                    console.error('Stripe payment error:', error);
                                    callback({
                                        success: false,
                                        message: error.message || 'Stripe error'
                                    });
                                });
                        }
                    } else {
                        callback({
                            success: false,
                            message: response.data ? response.data.message : 'Stripe initialization failed'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error initializing Stripe:', xhr, status, error);
                    callback({
                        success: false,
                        message: 'Connection error initializing payment'
                    });
                }
            });
        },
        
        /**
         * Handle Stripe with SCA
         * Manages Stripe payment that requires Strong Customer Authentication
         * 
         * @param {string} clientSecret - The client secret
         * @param {string} paymentIntentId - The payment intent ID
         * @param {function} callback - The callback function
         */
        handleStripeWithSCA: function(clientSecret, paymentIntentId, callback) {
            const self = this;
            const stripe = this.gateways.stripe.stripe;
            
            // Display Stripe modal
            $('#yprint-stripe-modal').show();
            
            // Create Stripe Elements if not already created
            if (!this.gateways.stripe.elements) {
                this.gateways.stripe.elements = stripe.elements({
                    clientSecret: clientSecret
                });
                
                // Create and mount the Payment Element
                const paymentElement = this.gateways.stripe.elements.create('payment', {
                    layout: { type: 'tabs' }
                });
                
                paymentElement.mount('#yprint-stripe-payment-element');
                this.gateways.stripe.paymentElement = paymentElement;
                
                // Set up form
                const form = document.getElementById('yprint-stripe-payment-form');
                this.gateways.stripe.form = form;
                
                // Handle form submission
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    self.submitStripeForm(paymentIntentId, callback);
                });
                
                // Set up modal close button
                $('#yprint-stripe-modal-close').on('click', function() {
                    $('#yprint-stripe-modal').hide();
                    callback({
                        success: false,
                        message: 'Zahlung abgebrochen'
                    });
                });
            }
        },
        
        /**
         * Submit Stripe form
         * Handles Stripe form submission for SCA
         * 
         * @param {string} paymentIntentId - The payment intent ID
         * @param {function} callback - The callback function
         */
        submitStripeForm: function(paymentIntentId, callback) {
            const self = this;
            const stripe = this.gateways.stripe.stripe;
            const elements = this.gateways.stripe.elements;
            
            // Show loading state
            $('#yprint-stripe-submit').addClass('loading');
            $('.yprint-stripe-error-message').hide();
            
            // Confirm payment with SCA
            stripe.confirmPayment({
                elements,
                confirmParams: {
                    return_url: yprint_params.stripe_return_url,
                },
                redirect: 'if_required'
            }).then(function(result) {
                if (result.error) {
                    // Show error message
                    $('.yprint-stripe-error-message')
                        .text(result.error.message)
                        .show();
                    $('#yprint-stripe-submit').removeClass('loading');
                    
                    callback({
                        success: false,
                        message: result.error.message
                    });
                } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                    // Payment succeeded without redirect
                    $('#yprint-stripe-modal').hide();
                    
                    callback({
                        success: true,
                        payment_id: paymentIntentId
                    });
                } else {
                    // Payment requires redirect or other actions
                    // The callback will be handled on return via the return_url
                    $('#yprint-stripe-modal').hide();
                    
                    // For cases without redirect but requiring further action
                    if (!result.paymentIntent) {
                        callback({
                            success: false,
                            message: 'Weitere Authentifizierung erforderlich'
                        });
                    }
                }
            }).catch(function(error) {
                console.error('Stripe error:', error);
                
                $('.yprint-stripe-error-message')
                    .text(error.message || 'Ein Fehler ist aufgetreten')
                    .show();
                $('#yprint-stripe-submit').removeClass('loading');
                
                callback({
                    success: false,
                    message: error.message || 'Stripe error'
                });
            });
        },
        
        /**
         * Process PayPal payment
         * Handles PayPal payment processing
         * 
         * @param {object} checkoutData - The checkout data
         * @param {number} tempOrderId - The temporary order ID
         * @param {function} callback - The callback function
         */
        processPayPalPayment: function(checkoutData, tempOrderId, callback) {
            const self = this;
            const $ = jQuery;
            
            // PayPal session initialization
            $.ajax({
                type: 'POST',
                url: yprint_params.ajax_url,
                data: {
                    action: 'yprint_init_paypal_payment',
                    checkout_data: checkoutData,
                    temp_order_id: tempOrderId,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    if (response.success && response.data.paypal_order_id) {
                        // PayPal payment initiated, now open PayPal modal
                        const paypalOrderId = response.data.paypal_order_id;
                        
                        // Show PayPal modal
                        $('#yprint-paypal-modal').show();
                        
                        // Initialize PayPal buttons
                        self.initPayPalButtons(paypalOrderId, tempOrderId, callback);
                    } else {
                        callback({
                            success: false,
                            message: response.data ? response.data.message : 'PayPal initialization failed'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error initializing PayPal:', xhr, status, error);
                    callback({
                        success: false,
                        message: 'Connection error initializing payment'
                    });
                }
            });
        },
        
        /**
         * Initialize PayPal buttons
         * Sets up PayPal Smart Payment Buttons
         * 
         * @param {string} paypalOrderId - The PayPal order ID
         * @param {number} tempOrderId - The temporary order ID
         * @param {function} callback - The callback function
         */
        initPayPalButtons: function(paypalOrderId, tempOrderId, callback) {
            const self = this;
            const $ = jQuery;
            
            // Clear existing buttons
            $('#paypal-button-container').empty();
            
            // Modal close button
            $('#yprint-paypal-modal-close').off('click').on('click', function() {
                $('#yprint-paypal-modal').hide();
                callback({
                    success: false,
                    message: 'PayPal payment cancelled'
                });
            });
            
            // Create PayPal buttons
            try {
                paypal.Buttons({
                    fundingSource: paypal.FUNDING.PAYPAL,
                    createOrder: function() {
                        return paypalOrderId; // Return the already created order ID
                    },
                    onApprove: function(data, actions) {
                        // Payment approved by user, now capture it
                        $('.yprint-validation-feedback')
                            .empty()
                            .addClass('success')
                            .append($('<div class="yprint-validation-item"></div>')
                            .text('PayPal-Zahlung wird verarbeitet...'))
                            .show();
                            
                        // Capture payment
                        $.ajax({
                            type: 'POST',
                            url: yprint_params.ajax_url,
                            data: {
                                action: 'yprint_capture_checkout_paypal_payment',
                                paypal_order_id: paypalOrderId,
                                temp_order_id: tempOrderId,
                                security: yprint_params.checkout_nonce
                            },
                            success: function(captureResponse) {
                                if (captureResponse.success) {
                                    // Close PayPal modal
                                    $('#yprint-paypal-modal').hide();
                                    
                                    callback({
                                        success: true,
                                        payment_id: captureResponse.data.transaction_id || paypalOrderId
                                    });
                                } else {
                                    $('.yprint-validation-feedback')
                                        .empty()
                                        .addClass('error')
                                        .append($('<div class="yprint-validation-item"></div>')
                                        .text(captureResponse.data.message || 'Fehler bei der PayPal-Zahlung'))
                                        .show();
                                        
                                    callback({
                                        success: false,
                                        message: captureResponse.data.message || 'Fehler bei der PayPal-Zahlung'
                                    });
                                }
                            },
                            error: function() {
                                $('.yprint-validation-feedback')
                                    .empty()
                                    .addClass('error')
                                    .append($('<div class="yprint-validation-item"></div>')
                                    .text('Fehler bei der PayPal-Zahlungsverarbeitung'))
                                    .show();
                                    
                                callback({
                                    success: false,
                                    message: 'Fehler bei der PayPal-Zahlungsverarbeitung'
                                });
                            }
                        });
                    },
                    onCancel: function() {
                        $('#yprint-paypal-modal').hide();
                        
                        $('.yprint-validation-feedback')
                            .empty()
                            .addClass('error')
                            .append($('<div class="yprint-validation-item"></div>')
                            .text('PayPal-Zahlung wurde abgebrochen'))
                            .show();
                            
                        callback({
                            success: false,
                            message: 'PayPal-Zahlung wurde abgebrochen'
                        });
                    },
                    onError: function(err) {
                        $('#yprint-paypal-modal').hide();
                        
                        $('.yprint-validation-feedback')
                            .empty()
                            .addClass('error')
                            .append($('<div class="yprint-validation-item"></div>')
                            .text('Fehler bei der PayPal-Zahlung: ' + err))
                            .show();
                            
                        callback({
                            success: false,
                            message: 'Fehler bei der PayPal-Zahlung: ' + err
                        });
                    }
                }).render('#paypal-button-container');
            } catch (error) {
                console.error('Error rendering PayPal buttons:', error);
                $('#yprint-paypal-modal').hide();
                
                callback({
                    success: false,
                    message: 'Fehler beim Laden von PayPal: ' + error.message
                });
            }
        },
        
        /**
         * Process SEPA payment
         * Handles SEPA direct debit payment processing
         * 
         * @param {object} checkoutData - The checkout data
         * @param {number} tempOrderId - The temporary order ID
         * @param {function} callback - The callback function
         */
        processSepaPayment: function(checkoutData, tempOrderId, callback) {
            const $ = jQuery;
            
            // Collect SEPA data from form
            const sepaData = {
                iban: $('#sepa_iban').val(),
                bic: $('#sepa_bic').val(),
                account_holder: $('#sepa_account_holder').val()
            };
            
            // Validate IBAN (basic check)
            if (!sepaData.iban || sepaData.iban.length < 15) {
                callback({
                    success: false,
                    message: 'Bitte gib eine gültige IBAN ein'
                });
                return;
            }
            
            // Validate account holder
            if (!sepaData.account_holder) {
                callback({
                    success: false,
                    message: 'Bitte gib den Namen des Kontoinhabers an'
                });
                return;
            }
            
            // Process SEPA payment
            $.ajax({
                type: 'POST',
                url: yprint_params.ajax_url,
                data: {
                    action: 'yprint_process_sepa_payment',
                    payment_method: 'sepa',
                    checkout_data: checkoutData,
                    temp_order_id: tempOrderId,
                    sepa_data: sepaData,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    if (response.success) {
                        callback({
                            success: true,
                            payment_id: response.data.payment_id || 'sepa_direct'
                        });
                    } else {
                        callback({
                            success: false,
                            message: response.data.message || 'SEPA-Zahlung konnte nicht verarbeitet werden.'
                        });
                    }
                },
                error: function() {
                    callback({
                        success: false,
                        message: 'Fehler bei der SEPA-Zahlungsverarbeitung.'
                    });
                }
            });
        },
        
        /**
         * Process bank transfer payment
         * Handles bank transfer payment processing
         * 
         * @param {object} checkoutData - The checkout data
         * @param {number} tempOrderId - The temporary order ID
         * @param {function} callback - The callback function
         */
        processBankTransferPayment: function(checkoutData, tempOrderId, callback) {
            // Bank transfer doesn't need real-time processing
            // We just generate a reference and proceed
            const referenceId = 'bank_transfer_' + Date.now();
            
            callback({
                success: true,
                payment_id: referenceId
            });
        },
        
        /**
         * Show validation errors
         * Displays validation errors to the user
         * 
         * @param {array} errors - The error messages
         */
        showValidationErrors: function(errors) {
            const $validationFeedback = $('.yprint-validation-feedback');
            
            $validationFeedback.empty().addClass('error');
            
            errors.forEach(error => {
                $validationFeedback.append(
                    $('<div class="yprint-validation-item"></div>').text(error)
                );
            });
            
            $validationFeedback.show();
            
            // Scroll to errors
            $('html, body').animate({
                scrollTop: $validationFeedback.offset().top - 100
            }, 500);
        },
        
        /**
         * Show error
         * Displays a general error message
         * 
         * @param {string} message - The error message
         */
        showError: function(message) {
            const $validationFeedback = $('.yprint-validation-feedback');
            
            $validationFeedback
                .empty()
                .addClass('error')
                .append($('<div class="yprint-validation-item"></div>').text(message))
                .show();
            
            // Scroll to error
            $('html, body').animate({
                scrollTop: $validationFeedback.offset().top - 100
            }, 500);
        }
    };

    // Initialize the checkout system when the document is ready
    $(document).ready(function() {
        // Only initialize on checkout pages
        if ($('.yprint-checkout-container').length > 0) {
            YPrintCheckoutSystem.init();
        }
    });

})(jQuery);