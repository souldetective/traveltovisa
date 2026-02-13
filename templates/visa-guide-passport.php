<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$slug = strtolower((string) get_query_var('vcp_passport'));
if (!preg_match('/^[a-z]{2}$/', $slug)) {
    status_header(404);
    include get_404_template();
    return;
}

$table = $wpdb->prefix . 'vcp_passport_data';
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug=%s", $slug), ARRAY_A);
if (!$row) {
    status_header(404);
    include get_404_template();
    return;
}
?>
<main>
    <h1><?php echo esc_html($row['country_name']); ?> Visa Requirements</h1>
</main>
