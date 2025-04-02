/**
 * YPrint Stripe Integration
 * 
 * Handles client-side Stripe integration for the YPrint Payment Plugin.
 * - Initializes Stripe Elements
 * - Handles payment processing
 * - Supports Strong Customer Authentication (SCA)
 * - Manages the payment submission flow
 * 
 * @package YPrint_Payment
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * YPrint Stripe Handler
     * Object responsible for all Stripe-related operations
     */
    window.YPrintStripe = {
        /**
         * Stripe instance
         */
        stripe: null,

        /**
         * Stripe Elements instance
         */
        elements: null,

        /**
         * Payment Element instance
         */
        paymentElement: null,

        /**
         * Client secret for Payment Intent
         */
        clientSecret: null,

        /**
         * Payment Intent ID
         */
        paymentIntentId: null,

        /**
         * Check if SCA is required for the payment
         */
        scaRequired: false,

        /**
         * Modal element for Stripe payment form
         */
        modal: null,

        /**
         * Payment form element
         */
        form: null,

        /**
         * Error message element
         */
        errorElement: null,

        /**
         * Submit button element
         */
        submitButton: null,

        /**
         * Current checkout data
         */
        checkoutData: null,

        /**
         * Temporary order ID
         */
        tempOrderId: null,

        /**
         * Callback function after payment processing
         */
        callback: null,

        /**
         * Flags if payment process is currently active
         */
        isProcessing: false,

        /**
         * Initialize Stripe integration
         * 
         * @param {string} publishableKey Stripe publishable key
         */
        init: function(publishableKey) {
            if (typeof Stripe === 'undefined') {
                console.error('Stripe.js not loaded');
                return false;
            }

            try {
                // Initialize Stripe with the publishable key
                this.stripe = Stripe(publishableKey);
                
                // Set up DOM elements
                this.modal = $('#yprint-stripe-modal');
                this.form = $('#yprint-stripe-payment-form');
                this.errorElement = $('.yprint-stripe-error-message');
                this.submitButton = $('#yprint-stripe-submit');
                
                // Set up event handlers
                this.setupEventHandlers();
                
                console.log('Stripe integration initialized');
                return true;
            } catch (error) {
                console.error('Failed to initialize Stripe:', error);
                return false;
            }
        },

        /**
         * Set up event handlers for Stripe elements
         */
        setupEventHandlers: function() {
            const self = this;
            
            // Close modal handler
            $('#yprint-stripe-modal-close').on('click', function() {
                self.closeModal();
            });
            
            // Form submission handler
            this.form.on('submit', function(event) {
                event.preventDefault();
                self.submitPayment();
            });
            
            // Close modal when clicking outside
            $(window).on('click', function(event) {
                if ($(event.target).is(self.modal)) {
                    self.closeModal();
                }
            });
            
            // ESC key to close modal
            $(document).on('keydown', function(event) {
                if (event.key === 'Escape' && self.modal.is(':visible')) {
                    self.closeModal();
                }
            });
        },

        /**
         * Process payment via Stripe
         * Entry point from YPrintCheckoutSystem
         * 
         * @param {object} checkoutData Checkout form data
         * @param {number} tempOrderId Temporary order ID
         * @param {function} callback Callback function after processing
         */
        processPayment: function(checkoutData, tempOrderId, callback) {
            // Store data for later use
            this.checkoutData = checkoutData;
            this.tempOrderId = tempOrderId;
            this.callback = callback;
            
            if (this.isProcessing) {
                console.warn('Payment process already active');
                return;
            }
            
            this.isProcessing = true;
            
            // Start the Stripe payment process
            this.initStripePayment();
        },

        /**
         * Initialize Stripe payment process
         * Makes AJAX call to create Payment Intent
         */
        initStripePayment: function() {
            const self = this;
            
            // Reset state
            this.resetPaymentState();
            
            // Show validation feedback while processing
            $('.yprint-validation-feedback')
                .empty()
                .removeClass('error')
                .addClass('info')
                .append($('<div class="yprint-validation-item"></div>')
                .text('Zahlungsprozess wird initialisiert...'))
                .show();
            
            // Create Payment Intent on the server
            $.ajax({
                type: 'POST',
                url: yprint_params.ajax_url,
                data: {
                    action: 'yprint_init_stripe_payment',
                    checkout_data: this.checkoutData,
                    temp_order_id: this.tempOrderId,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    if (response.success && response.data.client_secret) {
                        self.clientSecret = response.data.client_secret;
                        self.paymentIntentId = response.data.payment_intent_id;
                        self.scaRequired = response.data.is_sca_required || false;
                        
                        // Clear validation feedback
                        $('.yprint-validation-feedback').hide();
                        
                        // Process based on whether SCA is required
                        if (self.scaRequired) {
                            self.handleScaPayment();
                        } else {
                            self.handleNonScaPayment();
                        }
                    } else {
                        // Show error
                        self.handleError(response.data ? response.data.message : 'Fehler bei der Initialisierung der Zahlung');
                    }
                    
                    self.isProcessing = false;
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', xhr, status, error);
                    
                    // Show error
                    self.handleError('Fehler bei der Verbindung zum Server. Bitte versuchen Sie es später erneut.');
                    self.isProcessing = false;
                }
            });
        },

        /**
         * Handle payment that requires Strong Customer Authentication (SCA)
         * Shows Stripe Elements UI for authentication
         */
        handleScaPayment: function() {
            // Create and mount Stripe Elements
            this.createStripeElements();
            
            // Show the payment modal
            this.openModal();
        },

        /**
         * Handle payment that doesn't require SCA
         * Process directly without UI
         */
        handleNonScaPayment: function() {
            const self = this;
            
            // Show processing state
            $('#yprint_checkout_submit').addClass('loading');
            
            // Confirm payment without additional UI
            this.stripe.confirmCardPayment(this.clientSecret)
                .then(function(result) {
                    $('#yprint_checkout_submit').removeClass('loading');
                    
                    if (result.error) {
                        self.handleError(result.error.message);
                    } else if (result.paymentIntent && 
                              result.paymentIntent.status === 'succeeded') {
                        // Payment succeeded
                        self.handlePaymentSuccess();
                    } else {
                        // Unexpected status
                        self.handleError('Unerwarteter Zahlungsstatus: ' + 
                                        (result.paymentIntent ? result.paymentIntent.status : 'unbekannt'));
                    }
                })
                .catch(function(error) {
                    $('#yprint_checkout_submit').removeClass('loading');
                    console.error('Stripe payment error:', error);
                    self.handleError(error.message || 'Fehler bei der Zahlungsverarbeitung');
                });
        },

        /**
         * Create and mount Stripe Elements for the payment form
         */
        createStripeElements: function() {
            // Clear previous instances
            if (this.paymentElement) {
                this.paymentElement.destroy();
            }
            
            // Create Elements instance
            this.elements = this.stripe.elements({
                clientSecret: this.clientSecret,
                appearance: {
                    theme: 'stripe',
                    variables: {
                        colorPrimary: '#0079FF',
                        colorBackground: '#ffffff',
                        colorText: '#30313d',
                        colorDanger: '#df1b41',
                        fontFamily: 'Roboto, -apple-system, sans-serif',
                        spacingUnit: '4px',
                        borderRadius: '4px'
                    }
                }
            });
            
            // Create and mount the Payment Element
            this.paymentElement = this.elements.create('payment', {
                layout: {
                    type: 'tabs',
                    defaultCollapsed: false
                }
            });
            
            // Mount to the DOM
            this.paymentElement.mount('#yprint-stripe-payment-element');
            
            // Reset error displays
            this.errorElement.hide();
        },

        /**
         * Submit the payment form
         * Triggered when user submits the Stripe Elements form
         */
        submitPayment: function() {
            const self = this;
            
            // Check if already processing
            if (this.isProcessing) {
                return;
            }
            
            this.isProcessing = true;
            
            // Show loading state
            this.submitButton.addClass('loading');
            this.errorElement.hide();
            
            // Submit payment through Stripe
            this.stripe.confirmPayment({
                elements: this.elements,
                confirmParams: {
                    return_url: yprint_params.stripe_return_url,
                },
                redirect: 'if_required'
            }).then(function(result) {
                self.isProcessing = false;
                
                if (result.error) {
                    // Show error in the form
                    self.submitButton.removeClass('loading');
                    self.showElementError(result.error.message);
                } else if (result.paymentIntent && 
                          result.paymentIntent.status === 'succeeded') {
                    // Payment succeeded immediately without redirect
                    self.closeModal();
                    self.handlePaymentSuccess();
                } else if (result.paymentIntent && 
                          result.paymentIntent.next_action) {
                    // Payment requires additional action but browser redirect handled by Stripe
                    // We don't need to do anything here as browser will redirect
                    console.log('Payment requires redirect, handled by Stripe');
                } else {
                    // Unexpected status
                    self.submitButton.removeClass('loading');
                    self.showElementError('Unerwarteter Zahlungsstatus');
                }
            }).catch(function(error) {
                self.isProcessing = false;
                self.submitButton.removeClass('loading');
                console.error('Stripe payment error:', error);
                self.showElementError(error.message || 'Fehler bei der Zahlungsverarbeitung');
            });
        },

        /**
         * Handle successful payment completion
         */
        handlePaymentSuccess: function() {
            if (typeof this.callback === 'function') {
                this.callback({
                    success: true,
                    payment_id: this.paymentIntentId
                });
            } else {
                console.error('No callback function provided');
                
                // Fallback: Redirect to thank you page
                window.location.href = yprint_params.thank_you_url;
            }
        },

        /**
         * Handle payment error
         * 
         * @param {string} message Error message
         */
        handleError: function(message) {
            // Reset processing state
            $('#yprint_checkout_submit').removeClass('loading');
            this.isProcessing = false;
            
            // Show error to user
            $('.yprint-validation-feedback')
                .empty()
                .removeClass('info')
                .addClass('error')
                .append($('<div class="yprint-validation-item"></div>')
                .text(message))
                .show();
            
            // Scroll to error
            $('html, body').animate({
                scrollTop: $('.yprint-validation-feedback').offset().top - 100
            }, 500);
            
            // Execute callback with error
            if (typeof this.callback === 'function') {
                this.callback({
                    success: false,
                    message: message
                });
            }
        },

        /**
         * Show error in the Stripe Elements form
         * 
         * @param {string} message Error message
         */
        showElementError: function(message) {
            this.errorElement
                .text(message)
                .show();
        },

        /**
         * Open the Stripe payment modal
         */
        openModal: function() {
            this.modal.show();
            
            // Focus the first input field for better UX
            setTimeout(() => {
                const firstInput = this.modal.find('input:visible').first();
                if (firstInput.length) {
                    firstInput.focus();
                }
            }, 100);
        },

        /**
         * Close the Stripe payment modal
         */
        closeModal: function() {
            this.modal.hide();
            
            // Cancel the payment process
            if (this.isProcessing) {
                this.isProcessing = false;
                
                if (typeof this.callback === 'function') {
                    this.callback({
                        success: false,
                        message: 'Zahlungsvorgang abgebrochen'
                    });
                }
            }
        },

        /**
         * Reset the payment state
         */
        resetPaymentState: function() {
            this.errorElement.hide();
            this.isProcessing = false;
            
            // Reset form if it exists
            if (this.form.length) {
                this.form[0].reset();
            }
        },

        /**
         * Verify Stripe payment return
         * Used after redirect from Stripe
         * 
         * @param {string} paymentIntentId Payment Intent ID
         * @param {function} callback Callback function
         */
        verifyPaymentReturn: function(paymentIntentId, callback) {
            const self = this;
            
            // Show processing message
            $('.yprint-validation-feedback')
                .empty()
                .removeClass('error')
                .addClass('info')
                .append($('<div class="yprint-validation-item"></div>')
                .text('Zahlungsstatus wird überprüft...'))
                .show();
            
            // Verify payment status with server
            $.ajax({
                type: 'POST',
                url: yprint_params.ajax_url,
                data: {
                    action: 'yprint_verify_stripe_return',
                    payment_intent: paymentIntentId,
                    security: yprint_params.checkout_nonce
                },
                success: function(response) {
                    if (response.success && response.data.redirect) {
                        // Payment successfully verified, redirect to thank you page
                        window.location.href = response.data.redirect;
                    } else if (response.data && response.data.requires_action) {
                        // Payment requires additional authentication
                        self.handleAdditionalAction(response.data.payment_intent_client_secret, callback);
                    } else {
                        // Error occurred
                        const errorMessage = response.data ? response.data.message : 'Fehler bei der Zahlungsüberprüfung';
                        
                        $('.yprint-validation-feedback')
                            .empty()
                            .removeClass('info')
                            .addClass('error')
                            .append($('<div class="yprint-validation-item"></div>')
                            .text(errorMessage))
                            .show();
                        
                        if (typeof callback === 'function') {
                            callback(false, errorMessage);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', xhr, status, error);
                    
                    const errorMessage = 'Fehler bei der Verbindung zum Server. Bitte versuchen Sie es später erneut.';
                    
                    $('.yprint-validation-feedback')
                        .empty()
                        .removeClass('info')
                        .addClass('error')
                        .append($('<div class="yprint-validation-item"></div>')
                        .text(errorMessage))
                        .show();
                    
                    if (typeof callback === 'function') {
                        callback(false, errorMessage);
                    }
                }
            });
        },

        /**
         * Handle additional authentication actions
         * 
         * @param {string} clientSecret Client secret
         * @param {function} callback Callback function
         */
        handleAdditionalAction: function(clientSecret, callback) {
            const self = this;
            
            this.stripe.handleNextAction({
                clientSecret: clientSecret
            }).then(function(result) {
                if (result.error) {
                    // Handle error
                    const errorMessage = result.error.message || 'Fehler bei der Zahlungsauthentifizierung';
                    
                    $('.yprint-validation-feedback')
                        .empty()
                        .removeClass('info')
                        .addClass('error')
                        .append($('<div class="yprint-validation-item"></div>')
                        .text(errorMessage))
                        .show();
                    
                    if (typeof callback === 'function') {
                        callback(false, errorMessage);
                    }
                } else if (result.paymentIntent && 
                          result.paymentIntent.status === 'succeeded') {
                    // Payment succeeded
                    if (typeof callback === 'function') {
                        callback(true, null);
                    }
                    
                    // Redirect to thank you page
                    window.location.href = yprint_params.thank_you_url;
                } else {
                    // Unexpected status
                    const errorMessage = 'Unerwarteter Zahlungsstatus: ' + 
                                       (result.paymentIntent ? result.paymentIntent.status : 'unbekannt');
                    
                    $('.yprint-validation-feedback')
                        .empty()
                        .removeClass('info')
                        .addClass('error')
                        .append($('<div class="yprint-validation-item"></div>')
                        .text(errorMessage))
                        .show();
                    
                    if (typeof callback === 'function') {
                        callback(false, errorMessage);
                    }
                }
            });
        }
    };

    // Add Stripe processing method to YPrintCheckoutSystem
    if (window.YPrintCheckoutSystem) {
        /**
         * Process Stripe payment
         * Implementation for YPrintCheckoutSystem.processPayment
         * 
         * @param {object} checkoutData Checkout form data
         * @param {number} tempOrderId Temporary order ID
         * @param {function} callback Callback function
         */
        window.YPrintCheckoutSystem.processStripePayment = function(checkoutData, tempOrderId, callback) {
            // Check if Stripe is initialized
            if (!YPrintStripe.stripe) {
                const stripePublicKey = yprint_params.stripe_public_key || '';
                
                // Initialize Stripe if not already done
                if (!YPrintStripe.init(stripePublicKey)) {
                    callback({
                        success: false,
                        message: 'Stripe konnte nicht initialisiert werden. Bitte versuchen Sie es später erneut.'
                    });
                    return;
                }
            }
            
            // Process payment via YPrintStripe
            YPrintStripe.processPayment(checkoutData, tempOrderId, callback);
        };
    } else {
        console.error('YPrintCheckoutSystem not found. Stripe integration cannot be initialized.');
    }

    // Initialize on document ready
    $(document).ready(function() {
        // Initialize Stripe if we're on the checkout page and Stripe is enabled
        if ($('.yprint-checkout-container').length > 0 && 
            yprint_params && 
            yprint_params.stripe_enabled) {
            
            const stripePublicKey = yprint_params.stripe_public_key || '';
            
            if (stripePublicKey) {
                YPrintStripe.init(stripePublicKey);
            }
        }
        
        // Handle return from Stripe redirect (payment confirmation)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('payment_intent') && 
            (urlParams.has('payment_intent_client_secret') || 
             urlParams.has('redirect_status'))) {
            
            const paymentIntentId = urlParams.get('payment_intent');
            const redirectStatus = urlParams.get('redirect_status');
            
            // Show processing message
            $('.yprint-payment-message')
                .addClass('yprint-payment-processing')
                .html(`
                    <h2>Zahlung wird verarbeitet</h2>
                    <p>Bitte warten Sie einen Moment, während wir Ihre Zahlung verarbeiten...</p>
                    <div class="yprint-loader"></div>
                `);
            
            // Initialize Stripe if needed
            if (!YPrintStripe.stripe) {
                const stripePublicKey = yprint_params.stripe_public_key || '';
                YPrintStripe.init(stripePublicKey);
            }
            
            // Verify the payment status
            YPrintStripe.verifyPaymentReturn(paymentIntentId, function(success, message) {
                if (!success) {
                    $('.yprint-payment-message')
                        .removeClass('yprint-payment-processing')
                        .addClass('yprint-payment-error')
                        .html(`
                            <h2>Fehler bei der Zahlung</h2>
                            <p>${message || 'Bei der Verarbeitung Ihrer Zahlung ist ein Fehler aufgetreten.'}</p>
                            <a href="${yprint_params.checkout_url || '/'}" class="yprint-return-button">Zurück zum Checkout</a>
                        `);
                }
            });
        }
    });

})(jQuery);