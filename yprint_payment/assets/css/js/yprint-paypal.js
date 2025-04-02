/**
 * YPrint PayPal Integration
 * 
 * This script handles the PayPal payment flow including:
 * - PayPal Smart Payment Buttons
 * - Payment authorization and capture
 * - Error handling and user experience
 * 
 * @package YPrint_Payment
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * YPrint PayPal Handler
     * Manages the integration with PayPal API
     */
    window.YPrintPayPal = {
        /**
         * PayPal instance
         */
        paypal: null,

        /**
         * PayPal options
         */
        options: {
            currency: 'EUR',
            intent: 'capture',
            buttonStyles: {
                layout: 'vertical',
                color: 'blue',
                shape: 'rect',
                label: 'pay'
            }
        },
        
        /**
         * Current order data
         */
        orderData: {},
        
        /**
         * The PayPal modal element
         */
        $modal: null,
        
        /**
         * Button container element
         */
        $buttonContainer: null,
        
        /**
         * Callback function after processing
         */
        callback: null,
        
        /**
         * Initialize PayPal
         * 
         * @param {Object} options Optional options to override defaults
         */
        init: function(options) {
            if (typeof paypal === 'undefined') {
                console.error('PayPal SDK not loaded');
                return;
            }
            
            this.paypal = paypal;
            
            // Merge custom options with defaults
            if (options) {
                this.options = $.extend(true, this.options, options);
            }
            
            // Store DOM references
            this.$modal = $('#yprint-paypal-modal');
            this.$buttonContainer = $('#paypal-button-container');
            
            // Set up modal close button
            $('#yprint-paypal-modal-close').on('click', this.closeModal.bind(this));
            
            // Close modal when clicking outside
            $(window).on('click', function(event) {
                if ($(event.target).hasClass('yprint-payment-modal')) {
                    this.closeModal();
                }
            }.bind(this));
            
            console.log('YPrint PayPal initialized');
        },
        
        /**
         * Process a PayPal payment
         * 
         * @param {Object} checkoutData The checkout data
         * @param {number} tempOrderId The temporary order ID
         * @param {Function} callback The callback function after processing
         */
        processPayment: function(checkoutData, tempOrderId, callback) {
            this.orderData = checkoutData;
            this.callback = callback;
            
            // Show loading indicator 
            $('.yprint-validation-feedback')
                .empty()
                .removeClass('error')
                .addClass('success')
                .append($('<div class="yprint-validation-item"></div>')
                .text('PayPal wird initialisiert...'))
                .show();
            
            // Initialize PayPal payment
            $.ajax({
                type: 'POST',
                url: yprint_params.ajax_url,
                data: {
                    action: 'yprint_init_paypal_payment',
                    checkout_data: checkoutData,
                    temp_order_id: tempOrderId,
                    security: yprint_params.checkout_nonce
                },
                success: this.handleInitSuccess.bind(this),
                error: this.handleInitError.bind(this)
            });
        },
        
        /**
         * Handle successful PayPal initialization
         * 
         * @param {Object} response The AJAX response
         */
        handleInitSuccess: function(response) {
            if (response.success && response.data.paypal_order_id) {
                // PayPal payment initiated, now open PayPal modal
                const paypalOrderId = response.data.paypal_order_id;
                
                // Clear existing buttons and message
                this.$buttonContainer.empty();
                $('.yprint-validation-feedback').hide();
                
                // Show PayPal modal
                this.$modal.show();
                
                // Render PayPal buttons
                this.renderButtons(paypalOrderId);
            } else {
                this.handleInitError(null, null, response.data ? response.data.message : 'PayPal initialization failed');
            }
        },
        
        /**
         * Handle PayPal initialization error
         * 
         * @param {Object} xhr The XMLHttpRequest object
         * @param {string} status The status text
         * @param {string} error The error message
         */
        handleInitError: function(xhr, status, error) {
            console.error('AJAX error initializing PayPal:', xhr, status, error);
            
            // Hide modal if shown
            this.$modal.hide();
            
            // Show error message
            $('.yprint-validation-feedback')
                .empty()
                .removeClass('success')
                .addClass('error')
                .append($('<div class="yprint-validation-item"></div>')
                .text('Fehler beim Initialisieren von PayPal: ' + (error || 'Verbindungsfehler')))
                .show();
            
            // Call callback with error
            if (this.callback) {
                this.callback({
                    success: false,
                    message: 'Fehler beim Initialisieren von PayPal: ' + (error || 'Verbindungsfehler')
                });
            }
        },
        
        /**
         * Render the PayPal buttons
         * 
         * @param {string} paypalOrderId The PayPal order ID
         */
        renderButtons: function(paypalOrderId) {
            try {
                // Check if PayPal object is available
                if (!this.paypal || !this.paypal.Buttons) {
                    throw new Error('PayPal SDK not properly loaded');
                }
                
                // Create PayPal buttons with options
                this.paypal.Buttons({
                    fundingSource: this.paypal.FUNDING.PAYPAL,
                    style: this.options.buttonStyles,
                    
                    // Called when the button is created
                    createOrder: function() {
                        return paypalOrderId; // Return the already created order ID
                    },
                    
                    // Called when the buyer approves the transaction
                    onApprove: this.handleApproval.bind(this),
                    
                    // Called when the buyer cancels the transaction
                    onCancel: this.handleCancel.bind(this),
                    
                    // Called when an error occurs during the transaction
                    onError: this.handleError.bind(this)
                }).render(this.$buttonContainer[0]);
                
                // Add alternative payment methods if enabled
                if (yprint_params.paypal_smart_buttons) {
                    // Create Credit Card button (PayPal Checkout)
                    this.paypal.Buttons({
                        fundingSource: this.paypal.FUNDING.CARD,
                        style: {
                            ...this.options.buttonStyles,
                            color: 'black'
                        },
                        createOrder: function() {
                            return paypalOrderId;
                        },
                        onApprove: this.handleApproval.bind(this),
                        onCancel: this.handleCancel.bind(this),
                        onError: this.handleError.bind(this)
                    }).render(this.$buttonContainer[0]);
                }
            } catch (error) {
                console.error('Error rendering PayPal buttons:', error);
                this.handleError(error);
            }
        },
        
        /**
         * Handle payment approval
         * 
         * @param {Object} data The data returned from PayPal
         * @param {Object} actions The PayPal actions object
         */
        handleApproval: function(data, actions) {
            // Hide PayPal modal
            this.$modal.hide();
            
            // Show processing message
            $('.yprint-validation-feedback')
                .empty()
                .removeClass('error')
                .addClass('success')
                .append($('<div class="yprint-validation-item"></div>')
                .text('PayPal-Zahlung wird verarbeitet...'))
                .show();
                
            // Capture payment via AJAX
            $.ajax({
                type: 'POST',
                url: yprint_params.ajax_url,
                data: {
                    action: 'yprint_capture_checkout_paypal_payment',
                    paypal_order_id: data.orderID,
                    security: yprint_params.checkout_nonce
                },
                success: this.handleCaptureSuccess.bind(this),
                error: this.handleCaptureError.bind(this)
            });
        },
        
        /**
         * Handle successful payment capture
         * 
         * @param {Object} response The AJAX response
         */
        handleCaptureSuccess: function(response) {
            if (response.success) {
                // Call callback with success
                if (this.callback) {
                    this.callback({
                        success: true,
                        payment_id: response.data.transaction_id || response.data.paypal_order_id
                    });
                }
            } else {
                this.handleCaptureError(null, null, response.data ? response.data.message : 'Fehler bei der PayPal-Zahlung');
            }
        },
        
        /**
         * Handle payment capture error
         * 
         * @param {Object} xhr The XMLHttpRequest object
         * @param {string} status The status text
         * @param {string} error The error message
         */
        handleCaptureError: function(xhr, status, error) {
            console.error('AJAX error capturing PayPal payment:', xhr, status, error);
            
            // Show error message
            $('.yprint-validation-feedback')
                .empty()
                .removeClass('success')
                .addClass('error')
                .append($('<div class="yprint-validation-item"></div>')
                .text('Fehler bei der PayPal-Zahlungsbestätigung: ' + (error || 'Verbindungsfehler')))
                .show();
            
            // Call callback with error
            if (this.callback) {
                this.callback({
                    success: false,
                    message: 'Fehler bei der PayPal-Zahlungsbestätigung: ' + (error || 'Verbindungsfehler')
                });
            }
        },
        
        /**
         * Handle payment cancellation
         */
        handleCancel: function() {
            // Hide PayPal modal
            this.$modal.hide();
            
            // Show cancellation message
            $('.yprint-validation-feedback')
                .empty()
                .removeClass('success')
                .addClass('error')
                .append($('<div class="yprint-validation-item"></div>')
                .text('PayPal-Zahlung wurde abgebrochen'))
                .show();
                
            // Call callback with cancellation
            if (this.callback) {
                this.callback({
                    success: false,
                    message: 'PayPal-Zahlung wurde abgebrochen'
                });
            }
        },
        
        /**
         * Handle payment error
         * 
         * @param {Error} err The error object
         */
        handleError: function(err) {
            // Hide PayPal modal
            this.$modal.hide();
            
            // Show error message
            $('.yprint-validation-feedback')
                .empty()
                .removeClass('success')
                .addClass('error')
                .append($('<div class="yprint-validation-item"></div>')
                .text('Fehler bei der PayPal-Zahlung: ' + (err.message || err)))
                .show();
                
            // Call callback with error
            if (this.callback) {
                this.callback({
                    success: false,
                    message: 'Fehler bei der PayPal-Zahlung: ' + (err.message || err)
                });
            }
        },
        
        /**
         * Close the PayPal modal
         */
        closeModal: function() {
            this.$modal.hide();
            
            // Call callback with cancellation if set
            if (this.callback) {
                this.callback({
                    success: false,
                    message: 'PayPal-Zahlung wurde abgebrochen'
                });
            }
        }
    };

    /**
     * Initialize PayPal integration when document is ready
     */
    $(document).ready(function() {
        // Only initialize on checkout pages
        if ($('.yprint-checkout-container').length > 0) {
            // Initialize PayPal with options from global params
            YPrintPayPal.init({
                currency: yprint_params.currency || 'EUR',
                buttonStyles: yprint_params.paypal_button_styles || {},
                intent: yprint_params.paypal_intent || 'capture'
            });
            
            // Register PayPal processor in checkout system
            if (window.YPrintCheckoutSystem) {
                YPrintCheckoutSystem.gateways = YPrintCheckoutSystem.gateways || {};
                YPrintCheckoutSystem.gateways.paypal = {
                    processPayment: YPrintPayPal.processPayment.bind(YPrintPayPal)
                };
                
                console.log('PayPal gateway registered with YPrintCheckoutSystem');
            }
        }
    });

})(jQuery);