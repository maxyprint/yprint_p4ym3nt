<?php
/**
 * Stripe-Webhook-Handler für YPrint Payment
 *
 * Diese Klasse implementiert die spezifische Verarbeitung von Stripe-Webhooks.
 * Sie validiert eingehende Webhook-Anfragen von Stripe, verarbeitet die Ereignisse
 * und aktualisiert Bestellungen entsprechend.
 *
 * @package YPrint_Payment
 * @since 1.0.0
 */

// Verhindern direkter Aufrufe
if (!defined('ABSPATH')) {
    exit;
}

// Sicherstellen, dass die Basis-Webhook-Klasse geladen ist
if (!class_exists('YPrint_Webhook_Handler')) {
    require_once YPRINT_PAYMENT_ABSPATH . 'webhooks/class-yprint-webhook-handler.php';
}

/**
 * Stripe-Webhook-Handler Klasse
 */
class YPrint_Stripe_Webhook extends YPrint_Webhook_Handler {
    /**
     * Die Einzelinstanz dieser Klasse
     *
     * @var YPrint_Stripe_Webhook
     */
    protected static $_instance = null;

    /**
     * Stripe API-Schlüssel
     *
     * @var string
     */
    private $secret_key;

    /**
     * Stripe Webhook Secret
     *
     * @var string
     */
    private $webhook_secret;

    /**
     * Testmodus-Flag
     *
     * @var bool
     */
    private $test_mode;

    /**
     * API-Handler
     *
     * @var YPrint_API
     */
    private $api;

    /**
     * Webhook-Endpunkt
     *
     * @var string
     */
    private $webhook_endpoint = 'yprint-stripe-webhook';

    /**
     * Unterstützte Ereignistypen
     *
     * @var array
     */
    private $supported_events = array(
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'charge.refunded',
        'charge.failed',
        'charge.succeeded',
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.payment_succeeded',
        'invoice.payment_failed'
    );

    /**
     * Hauptinstanz der YPrint_Stripe_Webhook-Klasse
     *
     * @return YPrint_Stripe_Webhook - Hauptinstanz
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Konstruktor.
     */
    public function __construct() {
        parent::__construct();
        
        // API-Handler laden
        $this->api = class_exists('YPrint_API') ? YPrint_API::instance() : null;
        
        // API-Schlüssel laden
        $this->load_api_keys();
        
        // Hooks initialisieren
        $this->init_hooks();
    }

    /**
     * Hooks initialisieren
     */
    private function init_hooks() {
        // Webhook-Route registrieren
        add_action('init', array($this, 'register_webhook_endpoint'));
        
        // Webhook-Handler
        add_action('parse_request', array($this, 'process_webhook_request'));
    }

    /**
     * API-Schlüssel laden
     */
    private function load_api_keys() {
        $this->test_mode = get_option('yprint_stripe_test_mode', 'no') === 'yes';
        
        if ($this->test_mode) {
            $this->secret_key = get_option('yprint_stripe_test_secret_key', 'INSERT_API_KEY_HERE');
            $this->webhook_secret = get_option('yprint_stripe_test_webhook_secret', 'INSERT_API_KEY_HERE');
        } else {
            $this->secret_key = get_option('yprint_stripe_secret_key', 'INSERT_API_KEY_HERE');
            $this->webhook_secret = get_option('yprint_stripe_webhook_secret', 'INSERT_API_KEY_HERE');
        }
    }

    /**
     * Webhook-Endpunkt registrieren
     */
    public function register_webhook_endpoint() {
        // Rewrite-Regel für den Webhook-Endpunkt hinzufügen
        add_rewrite_rule(
            '^' . $this->webhook_endpoint . '/?$',
            'index.php?' . $this->webhook_endpoint . '=1',
            'top'
        );
        
        // Query-Var hinzufügen
        add_filter('query_vars', function($vars) {
            $vars[] = $this->webhook_endpoint;
            return $vars;
        });
        
        // Rewrite-Regeln aktualisieren, falls noch nicht geschehen
        if (get_option('yprint_stripe_webhook_flushed') != md5($this->webhook_endpoint)) {
            flush_rewrite_rules();
            update_option('yprint_stripe_webhook_flushed', md5($this->webhook_endpoint));
        }
    }

    /**
     * Webhook-Request verarbeiten
     * 
     * @param WP $wp WordPress-Request-Objekt
     */
    public function process_webhook_request($wp) {
        // Prüfen, ob der Stripe-Webhook aufgerufen wurde
        if (isset($wp->query_vars[$this->webhook_endpoint])) {
            $this->handle_webhook();
            exit; // Weitere Verarbeitung verhindern
        }
    }

    /**
     * Webhook verarbeiten
     */
    public function handle_webhook() {
        // Payload und Header abrufen
        $payload = file_get_contents('php://input');
        $headers = $this->get_request_headers();
        
        // Debug-Logging
        if ($this->debug_mode) {
            $this->log("Stripe Webhook empfangen: " . substr($payload, 0, 500) . '...');
            $this->log("Headers: " . print_r($headers, true));
        }
        
        // Signatur validieren
        if (!$this->validate_webhook_signature($payload, $headers)) {
            $this->log('Stripe Webhook: Ungültige Signatur', 'error');
            $this->send_response(400, 'Invalid webhook signature');
            return;
        }
        
        // Payload parsen
        $event_json = json_decode($payload);
        
        if (!$event_json || !isset($event_json->type)) {
            $this->log('Stripe Webhook: Ungültiger Payload', 'error');
            $this->send_response(400, 'Invalid payload');
            return;
        }
        
        $event_type = $event_json->type;
        
        // Prüfen, ob der Event-Typ unterstützt wird
        if (!in_array($event_type, $this->supported_events)) {
            $this->log("Stripe Webhook: Nicht unterstützter Event-Typ: $event_type", 'warning');
            $this->send_response(200, 'Event type not supported');
            return;
        }
        
        // Event verarbeiten
        try {
            $result = $this->process_event($event_type, $event_json->data->object);
            
            if ($result['success']) {
                $this->log("Stripe Webhook: Event $event_type erfolgreich verarbeitet");
                $this->send_response(200, $result['message'] ?? 'Webhook processed successfully');
            } else {
                $this->log("Stripe Webhook: Fehler bei der Verarbeitung von $event_type: " . ($result['message'] ?? 'Unbekannter Fehler'), 'error');
                $this->send_response(400, $result['message'] ?? 'Error processing webhook');
            }
        } catch (Exception $e) {
            $this->log('Stripe Webhook: Exception bei der Verarbeitung: ' . $e->getMessage(), 'error');
            $this->send_response(500, 'Internal server error');
        }
    }

    /**
     * Validiert die Webhook-Signatur
     * 
     * @param string $payload Webhook-Payload
     * @param array $headers HTTP-Header
     * @return bool True wenn gültig, sonst false
     */
    private function validate_webhook_signature($payload, $headers) {
        // API-Handler für Webhook-Validierung verwenden, falls verfügbar
        if ($this->api && method_exists($this->api, 'verify_webhook_signature')) {
            return $this->api->verify_webhook_signature('stripe', $payload, $headers);
        }
        
        // Fallback zur manuellen Validierung
        if (empty($this->webhook_secret)) {
            $this->log('Stripe Webhook: Kein Webhook-Secret konfiguriert', 'error');
            return false;
        }
        
        $signature = isset($headers['stripe-signature']) ? $headers['stripe-signature'] : '';
        
        if (empty($signature)) {
            $this->log('Keine Stripe-Signatur im Header gefunden', 'error');
            return false;
        }
        
        try {
            // Stripe-Bibliothek laden
            $this->load_stripe_api();
            
            // Event verifizieren
            if (class_exists('\\Stripe\\Webhook')) {
                \Stripe\Webhook::constructEvent(
                    $payload, $signature, $this->webhook_secret
                );
                return true;
            } else if (class_exists('Stripe\\Webhook')) {
                Stripe\Webhook::constructEvent(
                    $payload, $signature, $this->webhook_secret
                );
                return true;
            } else {
                $this->log('Stripe Webhook-Klasse nicht gefunden', 'error');
                return false;
            }
        } catch (\UnexpectedValueException $e) {
            // Ungültiger Payload
            $this->log('Stripe Webhook: Ungültiger Payload: ' . $e->getMessage(), 'error');
            return false;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Ungültige Signatur
            $this->log('Stripe Webhook: Ungültige Signatur: ' . $e->getMessage(), 'error');
            return false;
        } catch (Exception $e) {
            // Sonstiger Fehler
            $this->log('Stripe Webhook: Validierungsfehler: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Verarbeitet ein Stripe-Ereignis
     * 
     * @param string $event_type Ereignistyp
     * @param object $event_object Ereignisobjekt
     * @return array Verarbeitungsergebnis
     */
    private function process_event($event_type, $event_object) {
        switch ($event_type) {
            case 'payment_intent.succeeded':
                return $this->process_payment_intent_succeeded($event_object);
                
            case 'payment_intent.payment_failed':
                return $this->process_payment_intent_failed($event_object);
                
            case 'payment_intent.canceled':
                return $this->process_payment_intent_canceled($event_object);
                
            case 'charge.refunded':
                return $this->process_charge_refunded($event_object);
                
            case 'charge.failed':
                return $this->process_charge_failed($event_object);
                
            case 'charge.succeeded':
                return $this->process_charge_succeeded($event_object);
                
            case 'checkout.session.completed':
                return $this->process_checkout_session_completed($event_object);
                
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                return $this->process_subscription_event($event_type, $event_object);
                
            case 'invoice.payment_succeeded':
            case 'invoice.payment_failed':
                return $this->process_invoice_event($event_type, $event_object);
                
            default:
                return [
                    'success' => false,
                    'message' => "Unhandled event type: $event_type"
                ];
        }
    }

    /**
     * Verarbeitet ein erfolgreiches Payment Intent Ereignis
     * 
     * @param object $payment_intent Payment Intent Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_payment_intent_succeeded($payment_intent) {
        $this->log('Verarbeite erfolgreiches Payment Intent: ' . $payment_intent->id);
        
        if (!isset($payment_intent->id)) {
            return [
                'success' => false,
                'message' => 'Payment Intent ID fehlt'
            ];
        }
        
        // Order aus Payment Intent ID suchen
        $order = $this->find_order_by_payment_intent($payment_intent->id);
        
        if (!$order) {
            $this->log('Keine Bestellung für Payment Intent ID gefunden: ' . $payment_intent->id, 'error');
            return [
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            ];
        }
        
        // Prüfen, ob die Bestellung bereits bezahlt wurde
        if ($order->is_paid()) {
            $this->log('Bestellung bereits bezahlt: ' . $order->get_id());
            return [
                'success' => true,
                'message' => 'Order already paid',
                'order_id' => $order->get_id()
            ];
        }
        
        // Bestellung als bezahlt markieren
        $charge_id = isset($payment_intent->latest_charge) ? $payment_intent->latest_charge : $payment_intent->id;
        
        try {
            $order->payment_complete($charge_id);
            $order->add_order_note('Zahlung via Stripe Webhook bestätigt (Payment Intent ID: ' . $payment_intent->id . ', Charge ID: ' . $charge_id . ')');
            
            // SCA-Status speichern
            $sca_required = $this->is_sca_required($payment_intent);
            update_post_meta($order->get_id(), '_stripe_sca_required', $sca_required);
            
            $order->save();
            
            $this->log('Bestellung als bezahlt markiert: ' . $order->get_id());
            
            // Hook für weitere Verarbeitung
            do_action('yprint_payment_stripe_payment_complete', $order, $payment_intent);
            
            return [
                'success' => true,
                'message' => 'Payment processed successfully',
                'order_id' => $order->get_id()
            ];
        } catch (Exception $e) {
            $this->log('Fehler beim Markieren der Bestellung als bezahlt: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Error completing payment: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verarbeitet ein fehlgeschlagenes Payment Intent Ereignis
     * 
     * @param object $payment_intent Payment Intent Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_payment_intent_failed($payment_intent) {
        $this->log('Verarbeite fehlgeschlagenes Payment Intent: ' . $payment_intent->id);
        
        if (!isset($payment_intent->id)) {
            return [
                'success' => false,
                'message' => 'Payment Intent ID fehlt'
            ];
        }
        
        // Order aus Payment Intent ID suchen
        $order = $this->find_order_by_payment_intent($payment_intent->id);
        
        if (!$order) {
            $this->log('Keine Bestellung für Payment Intent ID gefunden: ' . $payment_intent->id, 'error');
            return [
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            ];
        }
        
        // Fehlermeldung aus dem Payment Intent extrahieren
        $error_message = 'Zahlung fehlgeschlagen';
        if (isset($payment_intent->last_payment_error) && isset($payment_intent->last_payment_error->message)) {
            $error_message .= ': ' . $payment_intent->last_payment_error->message;
        }
        
        try {
            // Bestellung als fehlgeschlagen markieren
            $order->update_status('failed', 'Stripe-Zahlung fehlgeschlagen: ' . $error_message);
            $order->save();
            
            $this->log('Bestellung als fehlgeschlagen markiert: ' . $order->get_id());
            
            // Hook für weitere Verarbeitung
            do_action('yprint_payment_stripe_payment_failed', $order, $payment_intent);
            
            return [
                'success' => true,
                'message' => 'Payment failure recorded',
                'order_id' => $order->get_id()
            ];
        } catch (Exception $e) {
            $this->log('Fehler beim Markieren der Bestellung als fehlgeschlagen: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Error updating order status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verarbeitet ein abgebrochenes Payment Intent Ereignis
     * 
     * @param object $payment_intent Payment Intent Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_payment_intent_canceled($payment_intent) {
        $this->log('Verarbeite abgebrochenes Payment Intent: ' . $payment_intent->id);
        
        if (!isset($payment_intent->id)) {
            return [
                'success' => false,
                'message' => 'Payment Intent ID fehlt'
            ];
        }
        
        // Order aus Payment Intent ID suchen
        $order = $this->find_order_by_payment_intent($payment_intent->id);
        
        if (!$order) {
            $this->log('Keine Bestellung für Payment Intent ID gefunden: ' . $payment_intent->id, 'error');
            return [
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            ];
        }
        
        try {
            // Bestellung als abgebrochen markieren
            $order->update_status('cancelled', 'Stripe-Zahlung abgebrochen');
            $order->save();
            
            $this->log('Bestellung als abgebrochen markiert: ' . $order->get_id());
            
            // Hook für weitere Verarbeitung
            do_action('yprint_payment_stripe_payment_canceled', $order, $payment_intent);
            
            return [
                'success' => true,
                'message' => 'Payment cancellation recorded',
                'order_id' => $order->get_id()
            ];
        } catch (Exception $e) {
            $this->log('Fehler beim Markieren der Bestellung als abgebrochen: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Error updating order status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verarbeitet ein Rückerstattungs-Ereignis
     * 
     * @param object $charge Charge Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_charge_refunded($charge) {
        $this->log('Verarbeite Rückerstattung für Charge: ' . $charge->id);
        
        if (!isset($charge->id)) {
            return [
                'success' => false,
                'message' => 'Charge ID fehlt'
            ];
        }
        
        // Order aus Charge ID oder Payment Intent ID suchen
        $order = $this->find_order_by_charge($charge);
        
        if (!$order) {
            $this->log('Keine Bestellung für Charge ID gefunden: ' . $charge->id, 'error');
            return [
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            ];
        }
        
        // Prüfen, ob volle oder teilweise Rückerstattung
        $refunded_amount = $charge->amount_refunded / 100; // In Euro konvertieren
        $total_amount = $charge->amount / 100; // In Euro konvertieren
        $is_partial = $refunded_amount < $total_amount;
        
        try {
            if ($is_partial) {
                // Teilweise Rückerstattung
                $refund_note = sprintf(
                    'Stripe Zahlung teilweise zurückerstattet: %s von %s',
                    wc_price($refunded_amount),
                    wc_price($total_amount)
                );
                $order->add_order_note($refund_note);
                
                // WooCommerce Refund erstellen
                $refund = wc_create_refund(array(
                    'order_id' => $order->get_id(),
                    'amount' => $refunded_amount,
                    'reason' => 'Stripe Teilrückerstattung (Webhook)'
                ));
                
                if (is_wp_error($refund)) {
                    throw new Exception('Fehler beim Erstellen der Rückerstattung: ' . $refund->get_error_message());
                }
                
                $this->log('Teilweise Rückerstattung für Bestellung erstellt: ' . $order->get_id() . ', Betrag: ' . $refunded_amount);
            } else {
                // Vollständige Rückerstattung
                $order->update_status('refunded', 'Stripe Zahlung vollständig zurückerstattet');
                $this->log('Bestellung als vollständig zurückerstattet markiert: ' . $order->get_id());
            }
            
            // Rückerstattungs-Metadaten speichern
            update_post_meta($order->get_id(), '_stripe_refund_id', $charge->id);
            update_post_meta($order->get_id(), '_stripe_refund_amount', $refunded_amount);
            
            $order->save();
            
            // Hook für weitere Verarbeitung
            do_action('yprint_payment_stripe_refund', $order, $charge, $refunded_amount, $is_partial);
            
            return [
                'success' => true,
                'message' => $is_partial ? 'Partial refund processed' : 'Full refund processed',
                'order_id' => $order->get_id(),
                'refund_amount' => $refunded_amount
            ];
        } catch (Exception $e) {
            $this->log('Fehler bei der Verarbeitung der Rückerstattung: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Error processing refund: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verarbeitet ein fehlgeschlagenes Charge-Ereignis
     * 
     * @param object $charge Charge Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_charge_failed($charge) {
        $this->log('Verarbeite fehlgeschlagene Charge: ' . $charge->id);
        
        if (!isset($charge->id)) {
            return [
                'success' => false,
                'message' => 'Charge ID fehlt'
            ];
        }
        
        // Order aus Charge ID oder Payment Intent ID suchen
        $order = $this->find_order_by_charge($charge);
        
        if (!$order) {
            $this->log('Keine Bestellung für Charge ID gefunden: ' . $charge->id, 'error');
            return [
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            ];
        }
        
        // Fehlermeldung extrahieren
        $failure_message = isset($charge->failure_message) ? $charge->failure_message : 'Charge fehlgeschlagen';
        $failure_code = isset($charge->failure_code) ? $charge->failure_code : '';
        
        try {
            // Bestellung als fehlgeschlagen markieren
            $order->update_status('failed', 'Stripe-Zahlung fehlgeschlagen: ' . $failure_message . ($failure_code ? ' (Code: ' . $failure_code . ')' : ''));
            $order->save();
            
            $this->log('Bestellung als fehlgeschlagen markiert: ' . $order->get_id());
            
            // Hook für weitere Verarbeitung
            do_action('yprint_payment_stripe_charge_failed', $order, $charge);
            
            return [
                'success' => true,
                'message' => 'Charge failure recorded',
                'order_id' => $order->get_id()
            ];
        } catch (Exception $e) {
            $this->log('Fehler beim Markieren der Bestellung als fehlgeschlagen: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Error updating order status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verarbeitet ein erfolgreiches Charge-Ereignis
     * 
     * @param object $charge Charge Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_charge_succeeded($charge) {
        $this->log('Verarbeite erfolgreiche Charge: ' . $charge->id);
        
        // In der Regel wird die Bestellung bereits durch payment_intent.succeeded aktualisiert,
        // aber wir behandeln dieses Ereignis trotzdem als Fallback
        
        if (!isset($charge->id)) {
            return [
                'success' => false,
                'message' => 'Charge ID fehlt'
            ];
        }
        
        // Order aus Charge ID oder Payment Intent ID suchen
        $order = $this->find_order_by_charge($charge);
        
        if (!$order) {
            $this->log('Keine Bestellung für Charge ID gefunden: ' . $charge->id, 'error');
            return [
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            ];
        }
        
        // Prüfen, ob die Bestellung bereits bezahlt wurde
        if ($order->is_paid()) {
            $this->log('Bestellung bereits bezahlt: ' . $order->get_id());
            return [
                'success' => true,
                'message' => 'Order already paid',
                'order_id' => $order->get_id()
            ];
        }
        
        try {
            // Bestellung als bezahlt markieren
            $order->payment_complete($charge->id);
            $order->add_order_note('Zahlung via Stripe Webhook bestätigt (Charge ID: ' . $charge->id . ')');
            $order->save();
            
            $this->log('Bestellung als bezahlt markiert: ' . $order->get_id());
            
            // Metadaten speichern
            update_post_meta($order->get_id(), '_transaction_id', $charge->id);
            
            if (isset($charge->payment_intent)) {
                update_post_meta($order->get_id(), '_stripe_payment_intent', $charge->payment_intent);
            }
            
            // Hook für weitere Verarbeitung
            do_action('yprint_payment_stripe_charge_succeeded', $order, $charge);
            
            return [
                'success' => true,
                'message' => 'Charge processed successfully',
                'order_id' => $order->get_id()
            ];
        } catch (Exception $e) {
            $this->log('Fehler beim Markieren der Bestellung als bezahlt: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Error completing payment: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verarbeitet ein abgeschlossenes Checkout-Session-Ereignis
     * 
     * @param object $session Checkout Session Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_checkout_session_completed($session) {
        $this->log('Verarbeite abgeschlossene Checkout-Session: ' . $session->id);
        
        if (!isset($session->id)) {
            return [
                'success' => false,
                'message' => 'Session ID fehlt'
            ];
        }
        
        // Order anhand der client_reference_id suchen
        if (!isset($session->client_reference_id) || empty($session->client_reference_id)) {
            $this->log('Keine client_reference_id in der Checkout-Session gefunden', 'error');
            return [
                'success' => false,
                'message' => 'Keine client_reference_id gefunden'
            ];
        }
        
        $order_id = intval($session->client_reference_id);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $this->log('Keine Bestellung mit ID gefunden: ' . $order_id, 'error');
            return [
                'success' => false,
                'message' => 'Keine zugehörige Bestellung gefunden'
            ];
        }
        
        // Prüfen, ob die Bestellung bereits bezahlt wurde
        if ($order->is_paid()) {
            $this->log('Bestellung bereits bezahlt: ' . $order->get_id());
            return [
                'success' => true,
                'message' => 'Order already paid',
                'order_id' => $order->get_id()
            ];
        }
        
        try {
            // Zahlungsinformationen aus der Session holen
            $payment_intent_id = isset($session->payment_intent) ? $session->payment_intent : '';
            $payment_method = 'stripe';
            
            if (empty($payment_intent_id)) {
                $this->log('Kein Payment Intent in der Checkout-Session gefunden', 'warning');
            }
            
            // Session-ID als Transaktions-ID verwenden, falls kein Payment Intent verfügbar
            $transaction_id = $payment_intent_id ?: $session->id;
            
            // Bestellung als bezahlt markieren
            $order->payment_complete($transaction_id);
            $order->set_payment_method('stripe');
            $order->set_payment_method_title('Kreditkarte (Stripe)');
            $order->add_order_note('Zahlung via Stripe Checkout Session bestätigt (Session ID: ' . $session->id . ')');
            $order->save();
            
            $this->log('Bestellung als bezahlt markiert: ' . $order->get_id());
            
            // Metadaten speichern
            update_post_meta($order->get_id(), '_stripe_checkout_session', $session->id);
            
            if (!empty($payment_intent_id)) {
                update_post_meta($order->get_id(), '_stripe_payment_intent', $payment_intent_id);
            }
            
            // Hook für weitere Verarbeitung
            do_action('yprint_payment_stripe_checkout_completed', $order, $session);
            
            return [
                'success' => true,
                'message' => 'Checkout session processed successfully',
                'order_id' => $order->get_id()
            ];
        } catch (Exception $e) {
            $this->log('Fehler beim Markieren der Bestellung als bezahlt: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Error completing payment: ' . $e->getMessage()
            ];
        }
    }
 
    /**
     * Verarbeitet Abonnement-Ereignisse
     * 
     * @param string $event_type Ereignistyp
     * @param object $subscription Abonnement-Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_subscription_event($event_type, $subscription) {
        $this->log('Verarbeite Abonnement-Ereignis ' . $event_type . ' für Abonnement: ' . $subscription->id);
        
        // Wird nur verarbeitet, wenn WooCommerce Subscriptions aktiv ist
        if (!class_exists('WC_Subscriptions')) {
            $this->log('WooCommerce Subscriptions nicht aktiv. Abonnement-Ereignis wird ignoriert.', 'info');
            return [
                'success' => true,
                'message' => 'WooCommerce Subscriptions not active'
            ];
        }
        
        // Abonnement anhand der Metadaten suchen
        if (!isset($subscription->metadata->order_id) || empty($subscription->metadata->order_id)) {
            $this->log('Keine order_id in den Abonnement-Metadaten gefunden', 'warning');
            return [
                'success' => false,
                'message' => 'No order ID in subscription metadata'
            ];
        }
        
        $order_id = intval($subscription->metadata->order_id);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $this->log('Keine Bestellung mit ID gefunden: ' . $order_id, 'error');
            return [
                'success' => false,
                'message' => 'No associated order found'
            ];
        }
        
        // Je nach Ereignistyp verarbeiten
        try {
            switch ($event_type) {
                case 'customer.subscription.created':
                    // Neues Abonnement
                    $order->add_order_note('Stripe-Abonnement erstellt (Abonnement-ID: ' . $subscription->id . ')');
                    
                    // Abonnement-Metadaten speichern
                    update_post_meta($order->get_id(), '_stripe_subscription_id', $subscription->id);
                    
                    // Startdatum speichern
                    if (isset($subscription->start_date)) {
                        update_post_meta($order->get_id(), '_stripe_subscription_start_date', $subscription->start_date);
                    }
                    
                    $this->log('Neues Abonnement für Bestellung gespeichert: ' . $order->get_id());
                    break;
                    
                case 'customer.subscription.updated':
                    // Abonnement aktualisiert
                    $status_note = '';
                    
                    if (isset($subscription->status)) {
                        $status_note = ' (Status: ' . $subscription->status . ')';
                        update_post_meta($order->get_id(), '_stripe_subscription_status', $subscription->status);
                    }
                    
                    $order->add_order_note('Stripe-Abonnement aktualisiert' . $status_note);
                    $this->log('Abonnement für Bestellung aktualisiert: ' . $order->get_id());
                    break;
                    
                case 'customer.subscription.deleted':
                    // Abonnement gelöscht
                    $order->add_order_note('Stripe-Abonnement gekündigt (Abonnement-ID: ' . $subscription->id . ')');
                    update_post_meta($order->get_id(), '_stripe_subscription_status', 'canceled');
                    $this->log('Abonnement für Bestellung gekündigt: ' . $order->get_id());
                    break;
            }
            
            $order->save();
            
            // Hook für weitere Verarbeitung
            do_action('yprint_payment_stripe_subscription_' . str_replace('customer.subscription.', '', $event_type), $order, $subscription);
            
            return [
                'success' => true,
                'message' => 'Subscription event processed',
                'order_id' => $order->get_id()
            ];
        } catch (Exception $e) {
            $this->log('Fehler bei der Verarbeitung des Abonnement-Ereignisses: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Error processing subscription event: ' . $e->getMessage()
            ];
        }
    }
 
    /**
     * Verarbeitet Rechnungs-Ereignisse
     * 
     * @param string $event_type Ereignistyp
     * @param object $invoice Rechnungs-Objekt
     * @return array Verarbeitungsergebnis
     */
    private function process_invoice_event($event_type, $invoice) {
        $this->log('Verarbeite Rechnungs-Ereignis ' . $event_type . ' für Rechnung: ' . $invoice->id);
        
        // Wird nur verarbeitet, wenn WooCommerce Subscriptions aktiv ist
        if (!class_exists('WC_Subscriptions')) {
            $this->log('WooCommerce Subscriptions nicht aktiv. Rechnungs-Ereignis wird ignoriert.', 'info');
            return [
                'success' => true,
                'message' => 'WooCommerce Subscriptions not active'
            ];
        }
        
        // Abonnement-ID aus der Rechnung holen
        if (!isset($invoice->subscription) || empty($invoice->subscription)) {
            $this->log('Keine subscription in der Rechnung gefunden', 'warning');
            return [
                'success' => false,
                'message' => 'No subscription in invoice'
            ];
        }
        
        $subscription_id = $invoice->subscription;
        
        // Abonnement-Bestellung suchen
        $subscription_orders = wc_get_orders(array(
            'meta_key' => '_stripe_subscription_id',
            'meta_value' => $subscription_id,
            'limit' => 1
        ));
        
        if (empty($subscription_orders)) {
            $this->log('Keine Bestellung für Abonnement-ID gefunden: ' . $subscription_id, 'error');
            return [
                'success' => false,
                'message' => 'No subscription order found'
            ];
        }
        
        $subscription_order = $subscription_orders[0];
        
        // Je nach Ereignistyp verarbeiten
        try {
            switch ($event_type) {
                case 'invoice.payment_succeeded':
                    // Erfolgreiche Zahlung für wiederkehrende Rechnung
                    $amount = isset($invoice->amount_paid) ? ($invoice->amount_paid / 100) : 0;
                    $invoice_number = isset($invoice->number) ? $invoice->number : $invoice->id;
                    
                    $subscription_order->add_order_note(sprintf(
                        'Stripe-Abonnement-Zahlung erfolgreich (%s): %s',
                        $invoice_number,
                        wc_price($amount)
                    ));
                    
                    // Wenn WooCommerce Subscriptions vorhanden ist, Erneuerung markieren
                    if (function_exists('wcs_get_subscriptions_for_order')) {
                        $subscriptions = wcs_get_subscriptions_for_order($subscription_order->get_id());
                        foreach ($subscriptions as $subscription) {
                            // Letzte Zahlung aktualisieren
                            $subscription->update_dates(array(
                                'last_payment' => current_time('mysql')
                            ));
                            
                            // Rechnungsmetadaten speichern
                            update_post_meta($subscription->get_id(), '_stripe_invoice_' . $invoice->id, current_time('mysql'));
                        }
                    }
                    
                    $this->log('Erfolgreiche Abonnement-Zahlung für Bestellung verarbeitet: ' . $subscription_order->get_id());
                    break;
                    
                case 'invoice.payment_failed':
                    // Fehlgeschlagene Zahlung für wiederkehrende Rechnung
                    $invoice_number = isset($invoice->number) ? $invoice->number : $invoice->id;
                    $failure_message = isset($invoice->last_finalization_error->message) ? 
                                      $invoice->last_finalization_error->message : 
                                      'Unbekannter Fehler';
                    
                    $subscription_order->add_order_note(sprintf(
                        'Stripe-Abonnement-Zahlung fehlgeschlagen (%s): %s',
                        $invoice_number,
                        $failure_message
                    ));
                    
                    // Wenn WooCommerce Subscriptions vorhanden ist, Abonnement als ausstehend markieren
                    if (function_exists('wcs_get_subscriptions_for_order')) {
                        $subscriptions = wcs_get_subscriptions_for_order($subscription_order->get_id());
                        foreach ($subscriptions as $subscription) {
                            $subscription->update_status('on-hold', 'Zahlung für Rechnung ' . $invoice_number . ' fehlgeschlagen');
                        }
                    }
                    
                    $this->log('Fehlgeschlagene Abonnement-Zahlung für Bestellung verarbeitet: ' . $subscription_order->get_id());
                    break;
            }
            
            $subscription_order->save();
            
            // Hook für weitere Verarbeitung
            do_action('yprint_payment_stripe_invoice_' . str_replace('invoice.', '', $event_type), $subscription_order, $invoice);
            
            return [
                'success' => true,
                'message' => 'Invoice event processed',
                'order_id' => $subscription_order->get_id()
            ];
        } catch (Exception $e) {
            $this->log('Fehler bei der Verarbeitung des Rechnungs-Ereignisses: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => 'Error processing invoice event: ' . $e->getMessage()
            ];
        }
    }
 
    /**
     * Findet eine Bestellung anhand der Payment Intent ID
     * 
     * @param string $payment_intent_id Payment Intent ID
     * @return WC_Order|false Bestellung oder false wenn nicht gefunden
     */
    private function find_order_by_payment_intent($payment_intent_id) {
        // Zuerst in den Bestellungs-Metadaten suchen
        $orders = wc_get_orders(array(
            'meta_key' => '_stripe_payment_intent',
            'meta_value' => $payment_intent_id,
            'limit' => 1
        ));
        
        if (!empty($orders)) {
            return $orders[0];
        }
        
        return false;
    }
 
    /**
     * Findet eine Bestellung anhand der Charge-Informationen
     * 
     * @param object $charge Charge Objekt
     * @return WC_Order|false Bestellung oder false wenn nicht gefunden
     */
    private function find_order_by_charge($charge) {
        // Zuerst anhand der Charge ID suchen
        $orders = wc_get_orders(array(
            'meta_key' => '_transaction_id',
            'meta_value' => $charge->id,
            'limit' => 1
        ));
        
        if (!empty($orders)) {
            return $orders[0];
        }
        
        // Alternativ nach Payment Intent ID suchen, falls vorhanden
        if (isset($charge->payment_intent) && !empty($charge->payment_intent)) {
            return $this->find_order_by_payment_intent($charge->payment_intent);
        }
        
        // Fallback: Wenn in Metadaten eine order_id vorhanden ist
        if (isset($charge->metadata->order_id) && !empty($charge->metadata->order_id)) {
            $order_id = intval($charge->metadata->order_id);
            $order = wc_get_order($order_id);
            
            if ($order) {
                return $order;
            }
        }
        
        return false;
    }
 
    /**
     * Lädt die Stripe-API-Bibliothek
     */
    private function load_stripe_api() {
        // Stripe API-Key setzen
        $this->set_api_key();
        
        // Prüfen, ob die WooCommerce Stripe Gateway-Klasse verfügbar ist
        if (class_exists('WC_Stripe_API')) {
            return;
        }
        
        // Prüfen, ob die Stripe-Bibliothek direkt verfügbar ist
        if (class_exists('\\Stripe\\Stripe') || class_exists('Stripe\\Stripe')) {
            return;
        }
        
        // Versuchen, die WooCommerce Stripe Gateway-Bibliothek zu laden
        if (file_exists(WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/includes/class-wc-stripe-api.php')) {
            include_once WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/includes/class-wc-stripe-api.php';
            
            // Auch die Stripe PHP Library laden, falls nötig
            if (!class_exists('\\Stripe\\Stripe') && !class_exists('Stripe\\Stripe')) {
                if (file_exists(WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/vendor/autoload.php')) {
                    include_once WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/vendor/autoload.php';
                }
            }
            
            return;
        }
        
        // Versuchen, die Stripe-PHP-Bibliothek aus dem Plugin zu laden
        if (file_exists(YPRINT_PAYMENT_ABSPATH . 'vendor/autoload.php')) {
            require_once YPRINT_PAYMENT_ABSPATH . 'vendor/autoload.php';
            return;
        }
        
        $this->log('Stripe-API-Bibliothek konnte nicht geladen werden.', 'error');
        throw new Exception('Stripe-API-Bibliothek konnte nicht geladen werden.');
    }
 
    /**
     * Setzt den Stripe API-Key
     */
    private function set_api_key() {
        if (class_exists('WC_Stripe_API')) {
            // WooCommerce Stripe API
            \WC_Stripe_API::set_secret_key($this->secret_key);
        } elseif (class_exists('\\Stripe\\Stripe')) {
            // Stripe PHP SDK (Namespace Version)
            \Stripe\Stripe::setApiKey($this->secret_key);
        } elseif (class_exists('Stripe\\Stripe')) {
            // Stripe PHP SDK (Non-Namespace Version)
            Stripe\Stripe::setApiKey($this->secret_key);
        }
    }
 
    /**
     * Prüft, ob Strong Customer Authentication (SCA) erforderlich ist
     * 
     * @param object $payment_intent Payment Intent Objekt
     * @return bool True, wenn SCA erforderlich ist
     */
    private function is_sca_required($payment_intent) {
        // Prüfen auf next_action
        if (!empty($payment_intent->next_action)) {
            return true;
        }
        
        // Prüfen auf Status für SCA
        if (isset($payment_intent->status) && in_array($payment_intent->status, array('requires_action', 'requires_confirmation', 'requires_payment_method'))) {
            return true;
        }
        
        return false;
    }
 }
 
 /**
 * Gibt die Hauptinstanz von YPrint_Stripe_Webhook zurück
 * 
 * @return YPrint_Stripe_Webhook
 */
 function YPrint_Stripe_Webhook() {
    return YPrint_Stripe_Webhook::instance();
 }
 
 // Instanz initialisieren
 YPrint_Stripe_Webhook();