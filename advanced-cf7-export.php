<?php
/*
Plugin Name: Automated CF7 Export
Description: Automates the export of Contact Form 7 submissions to CSV and emails them on a scheduled basis.
Version: 1.0.4
Author: LFMC
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Plugin Update Checker
require 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/websupport-lfmc/automated-cf7-export',
    __FILE__,
    'automated-cf7-export'
);

$myUpdateChecker->setBranch('main');

// Function to fetch form titles
function get_form_title($cf7_id)
{
    global $wpdb;
    $form_title = $wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->prefix}posts WHERE ID = %d", $cf7_id));
    return $form_title ? sanitize_file_name($form_title) : "form_$cf7_id";
}

// Function to fetch and link data from the database
function fetch_cf7_data($limit = false)
{
    global $wpdb;

    $vdata_table = $wpdb->prefix . 'cf7_vdata';
    $entry_table = $wpdb->prefix . 'cf7_vdata_entry';

    // Fetching all distinct form IDs
    $form_ids = $wpdb->get_col("SELECT DISTINCT cf7_id FROM $entry_table");

    $forms_data = [];

    foreach ($form_ids as $form_id) {
        $interval = '1 MONTH'; // default to monthly
        $options = get_option('acf7_export_options');
        if ($options['schedule_frequency'] === 'weekly') {
            $interval = '1 WEEK';
        } elseif ($options['schedule_frequency'] === 'daily') {
            $interval = '1 DAY';
        }

        $query = "
            SELECT 
                vdata.id AS entry_id, 
                vdata.created AS submission_date, 
                entry.name AS field_name, 
                entry.value AS field_value 
            FROM $vdata_table AS vdata
            JOIN $entry_table AS entry 
            ON vdata.id = entry.data_id
            WHERE entry.cf7_id = %d
            AND vdata.created >= DATE_SUB(NOW(), INTERVAL $interval)
            ORDER BY vdata.created DESC
        ";

        $results = $wpdb->get_results($wpdb->prepare($query, $form_id), OBJECT);

        if ($limit && !empty($results)) {
            $entry_ids = array_unique(array_column($results, 'entry_id'));
            if (count($entry_ids) > $limit) {
                $entry_ids = array_slice($entry_ids, 0, $limit);
            }
            $results = array_filter($results, function ($entry) use ($entry_ids) {
                return in_array($entry->entry_id, $entry_ids);
            });
        }

        if (!empty($results)) {
            $forms_data[$form_id] = $results;
        }
    }

    return $forms_data;
}

// Function to export data to CSV
function export_cf7_data_to_csv($limit = false)
{
    $forms_data = fetch_cf7_data($limit);
    $csv_files = [];

    foreach ($forms_data as $form_id => $entries) {
        $form_title = get_form_title($form_id);
        $csv_file = plugin_dir_path(__FILE__) . "cf7_submissions_{$form_title}.csv";
        $file_handle = fopen($csv_file, 'w');

        // Collect all unique field names
        $unique_field_names = [];
        foreach ($entries as $entry) {
            if (!in_array($entry->field_name, $unique_field_names)) {
                $unique_field_names[] = $entry->field_name;
            }
        }
        sort($unique_field_names);

        // Add CSV header
        $headers = array_merge(['Entry ID', 'Submission Date'], $unique_field_names);
        fputcsv($file_handle, $headers);

        // Add data rows
        $entry_rows = [];
        foreach ($entries as $entry) {
            if (!isset($entry_rows[$entry->entry_id])) {
                $entry_rows[$entry->entry_id] = array_fill_keys($headers, '');
                $entry_rows[$entry->entry_id]['Entry ID'] = $entry->entry_id;
                $entry_rows[$entry->entry_id]['Submission Date'] = $entry->submission_date;
            }
            $entry_rows[$entry->entry_id][$entry->field_name] = $entry->field_value;
        }

        foreach ($entry_rows as $row) {
            fputcsv($file_handle, $row);
        }

        fclose($file_handle);
        $csv_files[] = $csv_file;
    }

    return $csv_files;
}

// Function to send email with CSV attachment
function send_cf7_email($limit = false)
{
    $options = get_option('acf7_export_options');
    $to = isset($options['export_emails']) ? $options['export_emails'] : '';
    if (empty($to)) {
        return;
    }
    $frequency = isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly';
    $site_name = get_bloginfo('name');
    $subject = ucfirst($frequency) . " Form Submissions for $site_name";
    $body = "Here's a $frequency update on the form submissions for $site_name:<br><br>";

    // Fetch form data and generate table
    $forms_data = fetch_cf7_data($limit);
    $body .= "<table border='1' cellpadding='5' cellspacing='0' style='text-align: left;'>";
    $body .= "<tr><th style='text-align: left;'>Form Name</th><th style='text-align: left;'>Total " . ucfirst($frequency) . " Submissions</th></tr>";
    foreach ($forms_data as $form_id => $entries) {
        $form_title = get_form_title($form_id);
        $unique_entry_ids = array_unique(array_column($entries, 'entry_id'));
        $total_submissions = count($unique_entry_ids);
        $body .= "<tr><td style='text-align: left;'>$form_title</td><td style='text-align: left;'>$total_submissions</td></tr>";
    }
    $body .= "</table><br><br>";

    $test_email = isset($options['test_email']) ? $options['test_email'] : '';
    $body .= "For further information about the export, please reach out to $test_email.";
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $attachments = export_cf7_data_to_csv($limit);

    wp_mail($to, $subject, $body, $headers, $attachments);
}

// Schedule the email function
function schedule_cf7_email_event()
{
    $options = get_option('acf7_export_options');
    $frequency = isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly';
    $emails = isset($options['export_emails']) ? $options['export_emails'] : '';

    if (empty($emails)) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>Error: No email address provided for CF7 export.</p></div>';
        });
        return;
    }

    if (!wp_next_scheduled('send_cf7_email_event')) {
        if ($frequency === 'weekly') {
            wp_schedule_event(strtotime('next Monday'), 'weekly', 'send_cf7_email_event');
        } elseif ($frequency === 'daily') {
            wp_schedule_event(time(), 'daily', 'send_cf7_email_event');
        } else {
            wp_schedule_event(strtotime('first day of next month midnight'), 'monthly', 'send_cf7_email_event');
        }
    }
}

// Clear scheduled event
function clear_cf7_email_schedule()
{
    $timestamp = wp_next_scheduled('send_cf7_email_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'send_cf7_email_event');
    }
}

add_action('wp', 'schedule_cf7_email_event');

// Register settings
function acf7_export_register_settings()
{
    register_setting('acf7_export_options_group', 'acf7_export_options', 'acf7_export_options_validate');
}
add_action('admin_init', 'acf7_export_register_settings');

// Validate and sanitize settings
function acf7_export_options_validate($input)
{
    $output = [];

    $output['export_emails'] = sanitize_text_field($input['export_emails']);
    if (empty($output['export_emails'])) {
        $output['export_emails'] = '';
    }

    $output['test_email'] = sanitize_text_field($input['test_email']);
    if (empty($output['test_email'])) {
        $output['test_email'] = '';
    }

    $output['test_limit'] = intval($input['test_limit']);
    if ($output['test_limit'] <= 0) {
        $output['test_limit'] = 1;
    }

    $output['schedule_frequency'] = sanitize_text_field($input['schedule_frequency']);
    if (!in_array($output['schedule_frequency'], ['daily', 'weekly', 'monthly'])) {
        $output['schedule_frequency'] = 'monthly';
    }

    return $output;
}

// Add options page
function acf7_export_options_page()
{
    add_menu_page(
        'Automated CF7 Export',
        'CF7 Export',
        'manage_options',
        'acf7_export',
        'acf7_export_options_page_html',
        'dashicons-email-alt',
        30
    );

    add_submenu_page(
        'acf7_export',
        'CF7 Export Settings',
        'CF7 Export Settings',
        'manage_options',
        'acf7_export',
        'acf7_export_options_page_html'
    );

    add_submenu_page(
        'acf7_export',
        'CF7 Export Test Email',
        'CF7 Export Test Email',
        'manage_options',
        'test-cf7-export-email',
        'test_email_button_callback'
    );
}
add_action('admin_menu', 'acf7_export_options_page');

function acf7_export_options_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $message = '';
    if (isset($_POST['acf7_toggle_schedule'])) {
        if (wp_next_scheduled('send_cf7_email_event')) {
            clear_cf7_email_schedule();
            $message = '<div class="updated"><p>Scheduled sending has been canceled.</p></div>';
        } else {
            schedule_cf7_email_event();
            $message = '<div class="updated"><p>Scheduled sending has been started.</p></div>';
        }
    }

    if (isset($_POST['acf7_clear_options'])) {
        delete_option('acf7_export_options');
        $message = '<div class="updated"><p>All options have been cleared.</p></div>';
    }

?>
    <div class="wrap">
        <h1>Automated CF7 Export Settings</h1>
        <?php if ($message) echo $message; ?>

        <!-- Form for updating settings -->
        <form method="post" action="options.php" id="acf7_export_settings_form">
            <?php
            settings_fields('acf7_export_options_group');
            $options = get_option('acf7_export_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Receiving Email Addresses</th>
                    <td><input type="text" name="acf7_export_options[export_emails]" value="<?php echo isset($options['export_emails']) ? esc_attr($options['export_emails']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Admin &amp; Test Email Address</th>
                    <td><input type="text" name="acf7_export_options[test_email]" value="<?php echo isset($options['test_email']) ? esc_attr($options['test_email']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Test Export Limit</th>
                    <td><input type="number" name="acf7_export_options[test_limit]" value="<?php echo isset($options['test_limit']) ? esc_attr($options['test_limit']) : 1; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Schedule Frequency</th>
                    <td>
                        <select name="acf7_export_options[schedule_frequency]">
                            <option value="daily" <?php selected(isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly', 'daily'); ?>>Daily</option>
                            <option value="weekly" <?php selected(isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly', 'weekly'); ?>>Weekly (Mondays)</option>
                            <option value="monthly" <?php selected(isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly', 'monthly'); ?>>Monthly (First of the month)</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <?php submit_button('Save Changes', 'primary', 'submit', false); ?>
            </p>
        </form>

        <!-- Form to start/stop schedule sending -->
        <form method="post" action="">
            <h2>Schedule Sending</h2>
            <p>Use the button below to start or stop scheduled sending of CF7 submissions.</p>
            <p class="submit">
                <button type="submit" name="acf7_toggle_schedule" class="button button-secondary"><?php echo wp_next_scheduled('send_cf7_email_event') ? 'Cancel Scheduled Sending' : 'Start Scheduled Sending'; ?></button>
            </p>
        </form>

        <!-- Form to clear all options -->
        <form method="post" action="" onsubmit="return confirm('Are you sure you want to clear all options?');">
            <h2>Clear All Options</h2>
            <p>Use the button below to clear all options. This action cannot be undone.</p>
            <p class="submit">
                <button type="submit" name="acf7_clear_options" class="button button-secondary" style="background-color: red; color: white; border-color: red;">Clear All Options</button>
            </p>
        </form>
    </div>

    <script type="text/javascript">
        document.getElementById('acf7_export_settings_form').onsubmit = function() {
            var exportEmails = document.querySelector('[name="acf7_export_options[export_emails]"]').value;
            var testEmail = document.querySelector('[name="acf7_export_options[test_email]"]').value;
            var testLimit = document.querySelector('[name="acf7_export_options[test_limit]"]').value;

            if (!exportEmails || !testEmail || !testLimit) {
                alert('Please fill out all fields before saving.');
                return false;
            }

            return true;
        };
    </script>
<?php
}

// Add test email button in admin interface
function test_email_button_callback()
{
    $options = get_option('acf7_export_options');
    $test_email = isset($options['test_email']) ? $options['test_email'] : '';
    $test_limit = isset($options['test_limit']) ? $options['test_limit'] : 1;

    if (empty($test_email)) {
        echo '<div class="notice notice-error"><p>Error: No test email address provided.</p></div>';
        return;
    }

    if (isset($_POST['send_test_email'])) {
        send_cf7_email($test_limit);
        echo '<div class="updated"><p>Test email with the most recent submission(s) sent successfully to ' . esc_html($test_email) . '!</p></div>';
    }

    echo '<div class="wrap">';
    echo '<h2>Send Test CF7 Export Email</h2>';
    echo '<p>The button below will send a test email to ' . esc_html($test_email) . ' with the most recent submission(s).</p>';
    echo '<form method="post">';
    echo '<input type="hidden" name="send_test_email" value="true">';
    submit_button('Send Test Email');
    echo '</form>';
    echo '</div>';
}
?>