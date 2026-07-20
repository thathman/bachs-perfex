<?php

defined('BASEPATH') or exit('No direct script access allowed');

if (!$CI->db->table_exists(db_prefix() . 'integration_events')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "integration_events` (
  `id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `external_event_id` varchar(191) NOT NULL,
  `correlation_id` varchar(191) DEFAULT NULL,
  `payload` longtext NOT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `signature_verified` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `attempt_count` int(11) NOT NULL DEFAULT '0',
  `next_retry_at` datetime DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'integration_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `provider_external_event` (`provider`, `external_event_id`),
  ADD KEY `status_next_retry` (`status`, `next_retry_at`),
  ADD KEY `correlation_id` (`correlation_id`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'integration_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1');
}
