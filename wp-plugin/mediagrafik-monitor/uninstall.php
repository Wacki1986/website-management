<?php

// Úklid po odinstalaci: options a transienty pluginu. Nic jiného plugin neukládá.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('mg_monitor_key_hash');
delete_option('mg_monitor_key_hint');
delete_option('mg_monitor_last_seen');
delete_option('mg_monitor_hub_url');
delete_transient('mg_monitor_updates_checked');

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mg_monitor_%' OR option_name LIKE '_transient_timeout_mg_monitor_%'");
