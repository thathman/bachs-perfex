<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Integration_events_model extends App_Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_PROCESSED = 'processed';
    const STATUS_FAILED = 'failed';
    const STATUS_DEAD_LETTER = 'dead_letter';

    const MAX_ATTEMPTS = 6;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Insert-first idempotent event recording. Returns the existing row if
     * (provider, external_event_id) was already seen -- never records the
     * same external event twice, regardless of retry/redelivery.
     *
     * @return array{id: int, is_new: bool}
     */
    public function record($provider, $event_type, $external_event_id, $payload, $signature_verified = false, $correlation_id = null)
    {
        $existing = $this->db->where('provider', $provider)
            ->where('external_event_id', $external_event_id)
            ->get(db_prefix() . 'integration_events')
            ->row();

        if ($existing) {
            return ['id' => (int) $existing->id, 'is_new' => false];
        }

        $payload_json = is_string($payload) ? $payload : json_encode($payload);

        $this->db->insert(db_prefix() . 'integration_events', [
            'provider'            => $provider,
            'event_type'          => $event_type,
            'external_event_id'   => $external_event_id,
            'correlation_id'      => $correlation_id,
            'payload'             => $payload_json,
            'payload_hash'        => hash('sha256', $payload_json),
            'signature_verified'  => $signature_verified ? 1 : 0,
            'status'              => self::STATUS_PENDING,
            'attempt_count'       => 0,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        return ['id' => (int) $this->db->insert_id(), 'is_new' => true];
    }

    public function mark_processed($id)
    {
        $this->db->where('id', $id)->update(db_prefix() . 'integration_events', [
            'status'       => self::STATUS_PROCESSED,
            'locked_at'    => null,
            'processed_at' => date('Y-m-d H:i:s'),
            'error_message' => null,
        ]);
    }

    /**
     * Records a processing failure, truncates the error message (never store
     * unbounded upstream error text/stack traces), and schedules the next
     * retry with exponential backoff -- or dead-letters once attempts are
     * exhausted.
     */
    public function mark_failed($id, $error_message)
    {
        $event = $this->get($id);
        if (!$event) {
            return;
        }

        $attempt = (int) $event->attempt_count + 1;
        $truncated_error = mb_substr((string) $error_message, 0, 500);

        if ($attempt >= self::MAX_ATTEMPTS) {
            $this->db->where('id', $id)->update(db_prefix() . 'integration_events', [
                'status'        => self::STATUS_DEAD_LETTER,
                'attempt_count' => $attempt,
                'locked_at'     => null,
                'next_retry_at' => null,
                'error_message' => $truncated_error,
            ]);
            return;
        }

        // Exponential backoff: 1, 2, 4, 8, 16 minutes.
        $delay_minutes = 2 ** ($attempt - 1);

        $this->db->where('id', $id)->update(db_prefix() . 'integration_events', [
            'status'        => self::STATUS_FAILED,
            'attempt_count' => $attempt,
            'locked_at'     => null,
            'next_retry_at' => date('Y-m-d H:i:s', strtotime("+{$delay_minutes} minutes")),
            'error_message' => $truncated_error,
        ]);
    }

    /**
     * Claims an event for processing by setting status=processing and
     * locked_at=now, using an UPDATE ... WHERE status<>'processing' guard so
     * a live webhook handler and the cron sweep can never both process the
     * same event at once.
     *
     * @return bool true if this call won the claim
     */
    public function claim($id)
    {
        $this->db->where('id', $id)->where('status !=', self::STATUS_PROCESSING);
        $this->db->update(db_prefix() . 'integration_events', [
            'status'    => self::STATUS_PROCESSING,
            'locked_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->db->affected_rows() > 0;
    }

    public function get($id)
    {
        return $this->db->where('id', $id)->get(db_prefix() . 'integration_events')->row();
    }

    public function get_dead_letters()
    {
        return $this->db->where('status', self::STATUS_DEAD_LETTER)
            ->order_by('created_at', 'DESC')
            ->get(db_prefix() . 'integration_events')
            ->result();
    }

    public function get_failed()
    {
        return $this->db->where('status', self::STATUS_FAILED)
            ->order_by('next_retry_at', 'ASC')
            ->get(db_prefix() . 'integration_events')
            ->result();
    }

    /**
     * Cron entry point (hooked to after_cron_run). Finds every event whose
     * next_retry_at has passed and asks whichever provider module registered
     * a processor to try it again, via a per-provider action hook so this
     * module never needs to know how to process any specific provider's events.
     */
    public function retry_due_events()
    {
        $due = $this->db->where('status', self::STATUS_FAILED)
            ->where('next_retry_at <=', date('Y-m-d H:i:s'))
            ->get(db_prefix() . 'integration_events')
            ->result();

        foreach ($due as $event) {
            $this->process_event($event);
        }
    }

    /**
     * Manual replay from the admin failure screen. Works on failed AND
     * dead-lettered events (a dead letter isn't gone, just stopped retrying
     * automatically -- staff can always force one more attempt).
     */
    public function replay($id)
    {
        $event = $this->get($id);
        if (!$event) {
            return false;
        }

        $this->process_event($event);
        return true;
    }

    private function process_event($event)
    {
        if (!$this->claim($event->id)) {
            return; // another worker already has it
        }

        // Each provider module registers itself here, e.g.:
        //   hooks()->add_action('integration_runtime_process_bachs', 'bachs_process_integration_event');
        hooks()->do_action('integration_runtime_process_' . $event->provider, $event);
    }
}
