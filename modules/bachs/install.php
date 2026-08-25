<?php

defined('BASEPATH') or exit('No direct script access allowed');

if (!$CI->db->table_exists(db_prefix() . 'bachs_checkout_sessions')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "bachs_checkout_sessions` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `bachs_checkout_id` varchar(191) NOT NULL,
  `checkout_url` varchar(500) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'created',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_checkout_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bachs_checkout_id` (`bachs_checkout_id`),
  ADD KEY `invoice_status` (`invoice_id`, `status`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_checkout_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1');
}

if (!$CI->db->table_exists(db_prefix() . 'bachs_transactions')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "bachs_transactions` (
  `id` int(11) NOT NULL,
  `bachs_charge_id` varchar(191) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `amount_minor` bigint(20) NOT NULL,
  `currency` varchar(3) NOT NULL,
  `status` varchar(20) NOT NULL,
  `refunded_amount_minor` bigint(20) NOT NULL DEFAULT 0,
  `refund_status` varchar(20) NOT NULL DEFAULT 'none',
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bachs_charge_id` (`bachs_charge_id`),
  ADD KEY `invoice_id` (`invoice_id`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1');
} elseif (!$CI->db->field_exists('refunded_amount_minor', db_prefix() . 'bachs_transactions')) {
    // Existing installs (this one included) had this table before refunds
    // were built -- add the tracking columns without touching existing rows.
    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_transactions`
  ADD `refunded_amount_minor` BIGINT NOT NULL DEFAULT 0,
  ADD `refund_status` VARCHAR(20) NOT NULL DEFAULT \'none\';');
}

if (!$CI->db->table_exists(db_prefix() . 'bachs_refunds')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "bachs_refunds` (
  `id` int(11) NOT NULL,
  `bachs_refund_id` varchar(191) NOT NULL,
  `bachs_charge_id` varchar(191) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `amount_minor` bigint(20) NOT NULL DEFAULT 0,
  `currency` varchar(3) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reason` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_refunds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bachs_refund_id` (`bachs_refund_id`),
  ADD KEY `bachs_charge_id` (`bachs_charge_id`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_refunds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1');
}

if (!$CI->db->table_exists(db_prefix() . 'bachs_customers')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "bachs_customers` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `bachs_customer_id` varchar(191) NOT NULL,
  `mode` varchar(10) NOT NULL DEFAULT 'live',
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `client_mode` (`client_id`, `mode`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1');
}

if (!$CI->db->table_exists(db_prefix() . 'bachs_disputes')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "bachs_disputes` (
  `id` int(11) NOT NULL,
  `bachs_dispute_id` varchar(191) NOT NULL,
  `bachs_charge_id` varchar(191) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `amount_minor` bigint(20) NOT NULL DEFAULT 0,
  `currency` varchar(3) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_disputes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bachs_dispute_id` (`bachs_dispute_id`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_disputes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1');
}

/**
 * Forked, Bachs-specific subscriptions. Mirrors what tblsubscriptions tracks
 * but Bachs-shaped -- no stripe_* columns, and currency is locked to USD
 * because Bachs subscriptions are USD-only today (note: the Bachs API itself
 * does NOT reject a non-USD recurring product, confirmed live in sandbox, so
 * this is the only place that constraint is enforced).
 *
 * A recurring Bachs product IS its price, so the pricing fields are hashed
 * into product_signature: when any of them changes, a new product has to be
 * created rather than the existing one edited.
 */
if (!$CI->db->table_exists(db_prefix() . 'bachs_subscriptions')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "bachs_subscriptions` (
  `id` int(11) NOT NULL,
  `hash` varchar(32) NOT NULL,
  `clientid` int(11) NOT NULL,
  `project_id` int(11) NOT NULL DEFAULT 0,
  `name` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `description_in_item` tinyint(1) NOT NULL DEFAULT 0,
  `terms` text DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `billing_interval` varchar(10) NOT NULL DEFAULT 'month',
  `billing_frequency` int(11) NOT NULL DEFAULT 1,
  `trial_interval` varchar(10) DEFAULT NULL,
  `trial_frequency` int(11) DEFAULT NULL,
  `product_signature` varchar(64) DEFAULT NULL,
  `bachs_product_id` varchar(191) DEFAULT NULL,
  `bachs_subscription_id` varchar(191) DEFAULT NULL,
  `bachs_customer_id` varchar(191) DEFAULT NULL,
  `bachs_checkout_id` varchar(191) DEFAULT NULL,
  `checkout_reference` varchar(191) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'not_subscribed',
  `in_test_environment` tinyint(1) NOT NULL DEFAULT 0,
  `date` date DEFAULT NULL,
  `date_subscribed` datetime DEFAULT NULL,
  `trial_end` datetime DEFAULT NULL,
  `current_period_start` datetime DEFAULT NULL,
  `current_period_end` datetime DEFAULT NULL,
  `next_billed_at` datetime DEFAULT NULL,
  `cancel_at_period_end` tinyint(1) NOT NULL DEFAULT 0,
  `canceled_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `created_from` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `hash` (`hash`),
  ADD UNIQUE KEY `bachs_subscription_id` (`bachs_subscription_id`),
  ADD KEY `clientid` (`clientid`),
  ADD KEY `status` (`status`),
  ADD KEY `bachs_product_id` (`bachs_product_id`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1');

    $CI->db->query('CREATE TABLE `' . db_prefix() . "bachs_events` (
  `id` int(11) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `external_event_id` varchar(191) NOT NULL,
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

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `external_event_id` (`external_event_id`),
  ADD KEY `status_next_retry` (`status`, `next_retry_at`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1');
}
