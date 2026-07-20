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
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bachs_charge_id` (`bachs_charge_id`),
  ADD KEY `invoice_id` (`invoice_id`);');

    $CI->db->query('ALTER TABLE `' . db_prefix() . 'bachs_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1');
}
