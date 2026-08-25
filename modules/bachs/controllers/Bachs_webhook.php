<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Public receiver for Bachs webhooks. HMAC-SHA256 scheme, header names, and
 * 300s tolerance window ported directly from the proven, sandbox-verified
 * services/gateway/src/webhooks/bachs-webhook.service.ts (confirmed against
 * docs.bachs.io/guides/webhooks/overview, 2026-07-11) -- same headers
 * (X-Bachs-Timestamp / X-Bachs-Signature), same message construction
 * ("{timestamp}.{rawBody}"), same constant-time comparison.
 *
 * Hard rule (per the master plan): a browser landing on a success URL is
 * NEVER payment confirmation. Only this verified webhook ever records a
 * payment.
 */
class Bachs_webhook extends App_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('bachs/Bachs_events_model');
        $this->load->library('bachs_gateway');
    }

    /**
     * Bachs webhook envelopes are small JSON documents -- a generous cap
     * well above any real payload, kept only to reject a trivially
     * oversized request body before it reaches json_decode().
     */
    private const MAX_BODY_BYTES = 65536;

    public function receive()
    {
        $raw = $this->input->raw_input_stream ?: file_get_contents('php://input');

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            http_response_code(413);
            echo json_encode(['error' => 'payload too large']);
            return;
        }

        if (!$this->verify_signature($raw)) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid signature']);
            return;
        }

        $envelope = json_decode($raw, true);

        if (!is_array($envelope) || !isset($envelope['id'], $envelope['type'])) {
            http_response_code(400);
            echo json_encode(['error' => 'malformed payload']);
            return;
        }

        $event = $this->bachs_events_model->record($envelope['type'], $envelope['id'], $raw, true);

        if (!$event['is_new']) {
            echo json_encode(['ok' => true, 'duplicate' => true]);
            return;
        }

        if (!$this->bachs_events_model->claim($event['id'])) {
            echo json_encode(['ok' => true]);
            return;
        }

        try {
            $this->bachs_gateway->process_webhook_event($envelope);
            $this->bachs_events_model->mark_processed($event['id']);
        } catch (\Throwable $e) {
            $this->bachs_events_model->mark_failed($event['id'], $e->getMessage());
        }

        echo json_encode(['ok' => true]);
    }

    private function verify_signature($rawBody)
    {
        // Sandbox and production have entirely separate webhook endpoints
        // and signing secrets (confirmed docs.bachs.io/integrate/sandbox) --
        // resolve whichever mode the gateway is currently set to, exactly
        // like make_client() does for the API base URL/key pair.
        $secret = $this->bachs_gateway->webhook_secret();

        if (empty($secret)) {
            return false;
        }

        $timestampHeader = $this->input->get_request_header('X-Bachs-Timestamp', true);
        $signatureHeader = $this->input->get_request_header('X-Bachs-Signature', true);

        if (empty($timestampHeader) || empty($signatureHeader)) {
            return false;
        }

        $timestamp = (int) $timestampHeader;
        if ($timestamp <= 0 || abs(time() - $timestamp) > 300) {
            return false;
        }

        $message  = $timestamp . '.' . $rawBody;
        $expected = hash_hmac('sha256', $message, $secret);

        return hash_equals($expected, (string) $signatureHeader);
    }
}
