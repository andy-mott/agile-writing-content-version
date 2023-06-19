<?php
/**
 * Plugin Name: Agile Writing - Content Version
 * Description: A plugin to display changes to posts using jsdiff
 * Version: 1.03
 * Author: Andy Mott
 */

// Activation hook for creating table
register_activation_hook(__FILE__, 'awcv_create_version_table');
function awcv_create_version_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'content_version';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        version int(11) NOT NULL,
        content longtext NOT NULL,
        author bigint(20) NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Register the styles and scripts
add_action('wp_enqueue_scripts', 'awcv_enqueue_scripts');
function awcv_enqueue_scripts() {
    wp_enqueue_style('awcv-styles', plugin_dir_url(__FILE__) . 'css/style.css');
    
    // Enqueue the diff library
    wp_enqueue_script('diff-lib', 'https://cdn.jsdelivr.net/npm/diff@5.0.0/dist/diff.min.js');
    
    // Enqueue custom scripts with dependencies
    wp_enqueue_script('awcv-scripts', plugin_dir_url(__FILE__) . 'js/script.js', array('jquery', 'diff-lib'), '1.3', true); // Notice the version change to 1.3

    // Pass variables to JavaScript
    wp_localize_script('awcv-scripts', 'awcv_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'post_id' => get_the_ID()
    ));
}

// Filter the content of the post
add_filter('the_content', 'awcv_append_version_to_content');
function awcv_append_version_to_content($original_content) {
    global $post, $wpdb;

    // Only add version info to single posts/pages
    if (is_singular()) {
        $table_name = $wpdb->prefix . 'content_version';

        // Retrieve the current version number from the database
        $current_version = $wpdb->get_var($wpdb->prepare("SELECT MAX(version) FROM {$table_name} WHERE post_id = %d", $post->ID));

        // Build the version info
        $additional_content = '<div class="awcv-version-info">';
        $additional_content .= '<a href="' . add_query_arg('awcv_version_compare', '1', get_permalink($post->ID)) . '">Version ' . esc_html($current_version) . '</a>';
        $additional_content .= '</div>';

        // Check if we are in version compare mode
        if (isset($_GET['awcv_version_compare'])) {
            // Fetch all versions for this post
            $versions = $wpdb->get_results($wpdb->prepare(
                "SELECT cv.*, p.post_modified
                 FROM {$table_name} AS cv
                 INNER JOIN {$wpdb->prefix}posts AS p ON cv.post_id = p.ID
                 WHERE cv.post_id = %d
                 ORDER BY cv.version ASC", $post->ID
            ));

            // Build the version selection interface
            $comparison_content = '<div class="awcv-version-selector">';
            $comparison_content .= '<div class="awcv-description">Comparison view - new text is <span class="awcv-color-green">green</span>, removed text is <span class="awcv-color-red">red</span>, and common text is <span class="awcv-color-grey">grey</span>.</div>';
            $comparison_content .= '<div id="awcv-comparison-result"></div>';
            $comparison_content .= '<select id="awcv-version-1">';
            foreach ($versions as $version) {
                // Include the date next to the version number
                $date = new DateTime($version->post_modified);
                $formatted_date = $date->format('Y-m-d');
                $comparison_content .= '<option value="' . esc_attr($version->version) . '">Version ' . esc_html($version->version) . ' (' . esc_html($formatted_date) . ')</option>';
            }
            $comparison_content .= '</select>';

            $comparison_content .= '<select id="awcv-version-2">';
            foreach ($versions as $version) {
                $date = new DateTime($version->post_modified);
                $formatted_date = $date->format('Y-m-d');
                $comparison_content .= '<option value="' . esc_attr($version->version) . '">Version ' . esc_html($version->version) . ' (' . esc_html($formatted_date) . ')</option>';
            }
            $comparison_content .= '</select>';

            $comparison_content .= '<button id="awcv-compare-button">Compare</button>';
            $comparison_content .= '</div>';

            // Append the comparison content before the version info
            $additional_content = $comparison_content . $additional_content;
        }

        // Append additional content before the original content
        return $additional_content . $original_content;
    }

    return $original_content;
}

// Ajax action for version comparison
add_action('wp_ajax_awcv_compare_versions', 'awcv_compare_versions');
add_action('wp_ajax_nopriv_awcv_compare_versions', 'awcv_compare_versions');
function awcv_compare_versions() {
    global $wpdb;
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $version_1 = isset($_POST['version_1']) ? intval($_POST['version_1']) : 0;
    $version_2 = isset($_POST['version_2']) ? intval($_POST['version_2']) : 0;

    $table_name = $wpdb->prefix . 'content_version';

    $content_1 = $wpdb->get_var($wpdb->prepare("SELECT content FROM {$table_name} WHERE post_id = %d AND version = %d", $post_id, $version_1));
    $content_2 = $wpdb->get_var($wpdb->prepare("SELECT content FROM {$table_name} WHERE post_id = %d AND version = %d", $post_id, $version_2));

    wp_send_json([
        'content_1' => $content_1,
        'content_2' => $content_2
    ]);
}

// Save the version of the post
add_action('save_post', 'awcv_save_post_version', 10, 3);
function awcv_save_post_version($post_id, $post, $update) {
    global $wpdb;

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!$update) {
        return;
    }

    $table_name = $wpdb->prefix . 'content_version';

    $current_version = $wpdb->get_var($wpdb->prepare("SELECT MAX(version) FROM {$table_name} WHERE post_id = %d", $post_id));

    $new_version = $current_version ? intval($current_version) + 1 : 1;

    $wpdb->insert(
        $table_name,
        array(
            'post_id' => $post_id,
            'version' => $new_version,
            'content' => $post->post_content,
            'author' => $post->post_author
        ),
        array('%d', '%d', '%s', '%d')
    );
}
?>
