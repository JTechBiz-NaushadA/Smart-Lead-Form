<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$leads = $wpdb->prefix . 'tx_leads';
$settings = $wpdb->prefix . 'tx_settings';

// Delete tables
$wpdb->query("DROP TABLE IF EXISTS $leads");
$wpdb->query("DROP TABLE IF EXISTS $settings");