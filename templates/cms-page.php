<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$slug = sanitize_title((string) get_query_var('vcp_page'));
$table = $wpdb->prefix . 'vcp_pages';
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug=%s AND is_published=1", $slug), ARRAY_A);
if (!$row) {
    status_header(404);
    include get_404_template();
    return;
}
?>
<main>
    <h1><?php echo esc_html($row['title']); ?></h1>
    <?php echo wp_kses_post(wpautop($row['body'])); ?>
</main>
