<?php
/*
Plugin Name: TX Smart Lead Forms
Plugin URI: https://github.com/JTechBiz-NaushadA
Description: A smart lead capture plugin for WordPress that enables custom form creation, supports shortcodes, and allows custom HTML email template support directly from the admin for better engagement and conversions, with GDPR-compliant unsubscribe functionality.
Version: 1.0.1
Author: Naushad A.
Author URI: https://github.com/JTechBiz-NaushadA
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: tx-smart-lead-forms
*/

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-tx-smtp.php';

new TX_SMTP();

/* --------------------------
   1. CREATE TABLES
-------------------------- */
register_activation_hook(__FILE__, function () {
    global $wpdb;

    $charset = $wpdb->get_charset_collate();

    $leads = $wpdb->prefix . 'tx_leads';
    $settings = $wpdb->prefix . 'tx_settings';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Leads Table (UPDATED)
    dbDelta("CREATE TABLE $leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(100),
        last_name VARCHAR(100),
        email VARCHAR(150),
        organisation VARCHAR(150),
        role VARCHAR(100),
        country VARCHAR(100),
        interest TEXT,
        unsubscribe TINYINT(1) DEFAULT 0,
        unsubscribe_token VARCHAR(64),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;");

    // Settings Table
	dbDelta("CREATE TABLE $settings (
		id INT PRIMARY KEY,
		sender_name VARCHAR(100),
		sender_email VARCHAR(150),
		subject VARCHAR(255),
		preview_line VARCHAR(255),
		message LONGTEXT,

		smtp_enable TINYINT(1) DEFAULT 0,
		smtp_host VARCHAR(150),
		smtp_port INT,
		smtp_encryption VARCHAR(10),
		smtp_username VARCHAR(150),
		smtp_password VARCHAR(150)

	) $charset;");

    // Insert default settings row
    $wpdb->replace($settings, ['id' => 1]);

});

/* --------------------------
   2. ENQUEUE ASSETS
-------------------------- */
add_action('admin_enqueue_scripts', function ($hook) {

    if ($hook === 'toplevel_page_tx-leads' || $hook === 'tx-leads_page_tx-settings') {

        /* CSS */
        wp_enqueue_style(
            'tx-admin-style',
            plugin_dir_url(__FILE__) . 'assets/admin-style.css',
            [],
            '1.0'
        );

        /* JS */
        wp_enqueue_script(
            'tx-admin-script',
            plugin_dir_url(__FILE__) . 'assets/admin-script.js',
            [],
            '1.0',
            true
        );

        /* Pass AJAX URL */
        wp_localize_script('tx-admin-script', 'txAdmin', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('tx_admin_nonce'),
			'siteName' => get_bloginfo('name')
		]);
    }

});
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('tx-style', plugin_dir_url(__FILE__) . 'assets/style.css');
    wp_enqueue_script('tx-script', plugin_dir_url(__FILE__) . 'assets/script.js', [], false, true);

    wp_localize_script('tx-script', 'tx_ajax', [
        'url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('tx_nonce_action')
    ]);
});

/* --------------------------
   3. SHORTCODE FORM
-------------------------- */
add_shortcode('tx_form', function () {
    ob_start(); ?>

<div class="tx-form-wrapper">
<form id="txForm" class="tx-form-smart-lead-plugin">

<div class="tx-header">
    <h2>Get the Free Whitepaper</h2>
    <p>We'll send it to your inbox immediately. No spam, ever.</p>
</div>

<div class="tx-row">
    <div class="tx-field">
        <label>First Name *</label>
        <input type="text" name="first_name" placeholder="Jane" required>
    </div>

    <div class="tx-field">
        <label>Last Name *</label>
        <input type="text" name="last_name" placeholder="Smith" required>
    </div>
</div>

<div class="tx-field">
    <label>Work Email *</label>
    <input type="email" name="email" placeholder="jane@company.com" required>
</div>

<div class="tx-field">
    <label>Organisation</label>
    <input type="text" name="organisation" placeholder="Your company name" required>
</div>

<div class="tx-row">
    <div class="tx-field">
        <label>Your Role</label>
        <input type="text" name="role" placeholder="e.g. Product Manager" required>
    </div>

    <div class="tx-field">
        <label>Country</label>
        <input type="text" name="country" placeholder="e.g. India" required>
    </div>
</div>

<div class="tx-field chips-parent">
    <label>Areas of Interest</label>
    <div class="chips">
        <span data-value="Business">Business</span>
        <span data-value="Marketing">Marketing</span>
        <span data-value="Extract Leads">Extract Leads</span>
        <span data-value="For Job">For Job</span>
        <span data-value="Paid Advertising">Paid Advertising</span>
    </div>
</div>

<input type="hidden" name="interest" id="interest">

<!-- Privacy -->
<div class="tx-checkbox">
    <input type="checkbox" id="tx-agree" required>
    <label for="tx-agree">
        I agree to receive the whitepaper and relevant content for marketing purpose. 
        You can unsubscribe at any time. 
        <a href="#" target="_blank">View our privacy policy</a>.
    </label>
</div>

<button type="submit" class="tx-btn">
    Download Now!
</button>

<div class="tx-message"></div>

</form>
</div>

<?php return ob_get_clean();
});

/* --------------------------
   4. AJAX SUBMIT
-------------------------- */
add_action('wp_ajax_tx_submit', 'tx_submit');
add_action('wp_ajax_nopriv_tx_submit', 'tx_submit');

function tx_submit() {

    check_ajax_referer('tx_nonce_action','nonce');

    global $wpdb;
    $leads = $wpdb->prefix . 'tx_leads';
    $settings_table = $wpdb->prefix . 'tx_settings';

    $email = sanitize_email($_POST['email']);

    // Check if already unsubscribed
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $leads WHERE email=%s ORDER BY id DESC LIMIT 1",
        $email
    ));

    if ($existing && $existing->unsubscribe == 1) {
        wp_send_json_success("You are unsubscribed.");
    }

    // Generate secure token
    $token = wp_generate_password(32, false);

    $data = [
        'first_name' => sanitize_text_field($_POST['first_name']),
        'last_name' => sanitize_text_field($_POST['last_name']),
        'email' => $email,
        'organisation' => sanitize_text_field($_POST['organisation']),
        'role' => sanitize_text_field($_POST['role']),
        'country' => sanitize_text_field($_POST['country']),
        'interest' => sanitize_text_field($_POST['interest']),
        'unsubscribe_token' => $token,
        'unsubscribe' => 0
    ];

    $wpdb->insert($leads, $data);

    $settings = $wpdb->get_row("SELECT * FROM $settings_table WHERE id=1");

    // Localhost skip
    if ($_SERVER['SERVER_NAME'] == 'localhost') {
        wp_send_json_success("Saved (localhost mode)");
    }

    // Email headers
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: '.$settings->sender_name.' <'.$settings->sender_email.'>',
		'Reply-To: '.$settings->sender_email
    ];

    // Secure unsubscribe link
    $unsubscribe_link = add_query_arg('tx_unsub', $token, site_url());

    // Dynamic variables
    $variables = [
        '{{name}}' => $data['first_name'] . ' ' . $data['last_name'],
        '{{email}}' => $data['email'],
        '{{unsubscribe}}' => $unsubscribe_link
    ];

    $message = str_replace(
        array_keys($variables),
        array_values($variables),
        wp_unslash($settings->message)
    );

    // Send email
    wp_mail($data['email'], $settings->subject, $message, $headers);

    wp_send_json_success("We have sent you an email with downloadable link, please check it!");
}

/* --------------------------
   5. ADMIN MENU
-------------------------- */
add_action('admin_menu', function () {

    add_menu_page('TX Leads','TX Leads','manage_options','tx-leads','tx_leads_page');
    add_submenu_page('tx-leads','Settings','Settings','manage_options','tx-settings','tx_settings_page');

});

/* --------------------------
   6. LEADS PAGE
-------------------------- */
function tx_leads_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'tx_leads';

    $data = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");

    echo '<div class="wrap">';
    echo '<h1>Leads</h1>';
	echo '<a href="?page=tx-leads&export=1" class="button button-primary" id="tx-export-btn" ' . (empty($data) ? 'disabled style="opacity:0.5;pointer-events:none;"' : '') . '>Export CSV</a><br><br>';
    echo '<table class="widefat fixed striped">';
    echo '<thead>
        <tr>
            <th>Full Name</th>
            <th>Email</th>
            <th>Organisation</th>
            <th>Role</th>
            <th>Country</th>
            <th>Interest</th>
            <th>Date</th>
            <th>Unsubscribed</th>
            <th>Action</th>
        </tr>
    </thead>';

    echo '<tbody>';

    foreach ($data as $d) {
        $full_name = esc_html($d->first_name . ' ' . $d->last_name);
        $unsub = $d->unsubscribe ? 'Yes' : 'No';

        echo "<tr>
            <td>{$full_name}</td>
            <td>" . esc_html($d->email) . "</td>
            <td>" . esc_html($d->organisation) . "</td>
            <td>" . esc_html($d->role) . "</td>
            <td>" . esc_html($d->country) . "</td>
            <td>" . esc_html($d->interest) . "</td>
            <td>" . esc_html($d->created_at) . "</td>
            <td>{$unsub}</td>
            <td>
                <button class='button button-small tx-delete-lead' data-id='{$d->id}'>Delete</button>
            </td>
        </tr>";
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}

add_action('wp_ajax_tx_delete_lead', function() {
    check_ajax_referer('tx_admin_nonce', 'nonce');

    if (!isset($_POST['id'])) {
        wp_send_json_error('Invalid ID');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'tx_leads';
    $id = intval($_POST['id']);

    $deleted = $wpdb->delete($table, ['id' => $id]);

    if ($deleted) {
        wp_send_json_success('Lead deleted successfully');
    } else {
        wp_send_json_error('Failed to delete lead');
    }
});

/* --------------------------
   7. CSV EXPORT
-------------------------- */
add_action('init', function () {

    if (!isset($_GET['export'])) return;

    global $wpdb;
    $table = $wpdb->prefix . 'tx_leads';

    $rows = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);

    header('Content-Type:text/csv');
    header('Content-Disposition:attachment;filename=leads.csv');

    $out = fopen('php://output','w');

    if ($rows) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($out,$r);
    }

    fclose($out);
    exit;
});

/* --------------------------
   8. SETTINGS PAGE
-------------------------- */
function tx_settings_page() {

    global $wpdb;
    $table = $wpdb->prefix . 'tx_settings';

    if (isset($_POST['save'])) {

		$data = [
			'sender_name'   => sanitize_text_field($_POST['sender_name']),
			'sender_email'  => sanitize_email($_POST['sender_email']),
			'subject'       => sanitize_text_field($_POST['subject']),
			'preview_line'  => sanitize_text_field($_POST['preview_line']),
			'message' 		=> wp_unslash($_POST['message']),

			'smtp_enable'   => isset($_POST['smtp_enable']) ? 1 : 0,
			'smtp_host'     => sanitize_text_field($_POST['smtp_host']),
			'smtp_port'     => intval($_POST['smtp_port']),
			'smtp_encryption'=> sanitize_text_field($_POST['smtp_encryption']),
			'smtp_username' => sanitize_text_field($_POST['smtp_username'])
		];

		// Only update password if user entered new one
		if (!empty($_POST['smtp_password'])) {
			$data['smtp_password'] = sanitize_text_field($_POST['smtp_password']);
		}

		// Proper update (no need to pass id inside data)
		$updated = $wpdb->update($table, $data, ['id' => 1]);

		// If no row updated, insert it
		if ($updated === false || $updated === 0) {
			$data['id'] = 1;
			$wpdb->insert($table, $data);
		}
	}

    $s = $wpdb->get_row("SELECT * FROM $table WHERE id=1");

    ?>

<div class="wrap tx-settings-wrap">

    <h1>Email Settings</h1>
	<span><em>Use shortcodes <strong>[tx_form]</strong> into widget, text editor or any place you want to show the form.</em></span>

    <p class="tx-settings-desc">
        Configure how emails are sent after a user submits the form. 
        You can customize sender details, subject line, preview text, and the email body.
        Use HTML in the message field and dynamic variables like <code>{{name}}</code>.
    </p>

    <form method="post" class="tx-settings-form">
	
		<h2>SMTP Settings</h2>

		<div class="tx-field">
			<label>
				<input type="checkbox" name="smtp_enable" value="1" <?= checked($s->smtp_enable, 1, false); ?>>
				Enable SMTP
			</label>
		</div>

		<div class="tx-field">
			<label>SMTP Host</label>
			<input type="text" name="smtp_host" value="<?= esc_attr($s->smtp_host) ?>" placeholder="e.g. smtp.hostinger.com">
		</div>

		<div class="tx-field">
			<label>SMTP Port</label>
			<input type="number" name="smtp_port" value="<?= esc_attr($s->smtp_port) ?>" placeholder="e.g. 587">
		</div>

		<div class="tx-field">
			<label>Encryption</label>
			<select name="smtp_encryption">
				<option value="tls" <?= selected($s->smtp_encryption, 'tls', false); ?>>TLS</option>
				<option value="ssl" <?= selected($s->smtp_encryption, 'ssl', false); ?>>SSL</option>
			</select>
		</div>

		<div class="tx-field">
			<label>SMTP Username</label>
			<input type="text" name="smtp_username" value="<?= esc_attr($s->smtp_username) ?>">
		</div>

		<div class="tx-field">
			<label>SMTP Password</label>
			<input type="password" name="smtp_password" value="<?= esc_attr($s->smtp_password) ?> "placeholder="Leave blank to keep existing">
		</div>

        <div class="tx-field">
            <label>Sender Name</label>
            <input type="text" name="sender_name" value="<?= esc_attr($s->sender_name) ?>" placeholder="e.g. Your Company">
        </div>

        <div class="tx-field">
            <label>Sender Email</label>
            <input type="email" name="sender_email" value="<?= esc_attr($s->sender_email) ?>" placeholder="e.g. hello@yourdomain.com">
        </div>

        <div class="tx-field">
            <label>Subject Line</label>
            <input type="text" name="subject" value="<?= esc_attr($s->subject) ?>" placeholder="e.g. Your Free Whitepaper is Inside">
        </div>

        <div class="tx-field">
            <label>Preview Line</label>
            <input type="text" name="preview_line" value="<?= esc_attr($s->preview_line) ?>" placeholder="Short text shown in inbox preview">
        </div>

        <div class="tx-field">
            <label>Email Message (HTML Supported)</label>
            <small>Please, use <strong>{{unsubscribe}}</strong> in your email template with html anchor tag for GDPR Compliant.</small>
            <textarea name="message" rows="10"><?= esc_textarea($s->message) ?></textarea>
        </div>
		
		<h2>Email Preview</h2>
		<small>First you need to save the template with below save button, after that you can get a preview by click link below.</small>
		<div style="border:1px solid #ddd; padding:15px; background:#fff; border-radius:8px; font-size:16px; font-weight:bold; margin-top:10px;">
			<a href="<?= admin_url('?tx_preview=1&_wpnonce=' . wp_create_nonce('tx_preview_nonce')) ?>" target="_blank">Preview Email </a>
		</div>

		<br>

		<h2>Send Test Email</h2>

		<input type="email" id="tx-test-email" placeholder="Enter test email">
		<button type="button" class="button" id="tx-send-test">Send Test</button>

		<div id="tx-test-msg"></div>

        <div class="tx-actions">
            <button name="save" class="button button-primary">Save Settings</button>
        </div>

    </form>
</div>
<!-- Footer -->
<div style="margin-top: 30px; padding: 15px; text-align: center; border-top: 1px solid #ddd;">
    <p style="margin: 0; font-size: 13px; color: #666;">
        Powered by <a href="mailto:naushadali.rj@gmail.com" style="text-decoration:none; color:#0073aa;">
            Naushad A.
        </a>
    </p>
</div>

    <?php
}
/* --------------------------
   8. UNSUBSCRIBE LINK
-------------------------- */

add_action('init', function () {

    if (!isset($_GET['tx_unsub'])) return;

    global $wpdb;
    $table = $wpdb->prefix . 'tx_leads';

    $token = sanitize_text_field($_GET['tx_unsub']);

    // Find user by token
    $user = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table WHERE unsubscribe_token = %s", $token)
    );

    $status = 'invalid';

    if ($user) {

        if ($user->unsubscribe == 1) {
            $status = 'already';
        } else {
            $wpdb->update($table, ['unsubscribe' => 1], ['id' => $user->id]);
            $status = 'success';
        }
    }

    // Show custom UI instead of wp_die
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Unsubscribe</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #f4f6f9;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                margin: 0;
            }
            .box {
                background: #fff;
                padding: 30px;
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                text-align: center;
                max-width: 400px;
            }
            .box h2 {
                margin-bottom: 10px;
                color: #1c3d7a;
            }
            .box p {
                color: #555;
                font-size: 14px;
            }
            .success { color: #2e7d32; }
            .error { color: #c62828; }
        </style>
    </head>
    <body>
        <div class="box">';

    if ($status === 'success') {
        echo '<h2 class="success">You have been unsubscribed</h2>
              <p>You will no longer receive emails from us.</p>';
    } elseif ($status === 'already') {
        echo '<h2>Already Unsubscribed</h2>
              <p>You are already removed from our mailing list.</p>';
    } else {
        echo '<h2 class="error">Invalid Link</h2>
              <p>This unsubscribe link is invalid or expired.</p>';
    }

    echo '</div>
    </body>
    </html>';

    exit;
});

/* --------------------------
   9. Test Email Handler
-------------------------- */

add_action('wp_ajax_tx_send_test', function () {

    // Security check
    check_ajax_referer('tx_admin_nonce', 'nonce');

    // Validate input exists
    if (empty($_POST['email'])) {
        wp_send_json_error("Please enter an email address.");
    }

    $email = sanitize_email($_POST['email']);

    // Validate email format
    if (!is_email($email)) {
        wp_send_json_error("Please enter a valid email address.");
    }

    global $wpdb;
    $settings = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}tx_settings WHERE id=1");

    if (!$settings) {
        wp_send_json_error("Settings not found.");
    }

    // Prepare message safely
    $message = str_replace(
        '{{name}}',
        'Test User',
        wp_unslash($settings->message)
    );

    $headers = [
        'Content-Type: text/html; charset=UTF-8'
    ];

    // Send email and check result
    $sent = wp_mail($email, $settings->subject, $message, $headers);

    if ($sent) {
        wp_send_json_success("Test email sent successfully!");
    } else {
        wp_send_json_error("Failed to send email. Check SMTP settings OR email address if it exists or not.");
    }
});

/* --------------------------
   10. Preview Handler
-------------------------- */
add_action('init', function () {

    if (!isset($_GET['tx_preview'])) return;

    // Admin check
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    // Nonce check
    if (
        !isset($_GET['_wpnonce']) ||
        !wp_verify_nonce($_GET['_wpnonce'], 'tx_preview_nonce')
    ) {
        wp_die('Invalid request');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'tx_settings';

    $s = $wpdb->get_row("SELECT message FROM $table WHERE id=1");

    if (!$s || empty($s->message)) {
        wp_die('No email template found.');
    }

	$dummy_token = 'preview_j54fd6fe6ewe12d6ere32ff13df2e6efdff3f2d';

	$unsubscribe_link = add_query_arg('tx_unsub', $dummy_token, site_url());

	$variables = [
		'{{unsubscribe}}' => $unsubscribe_link
	];

	$message = str_replace(
		array_keys($variables),
		array_values($variables),
		wp_unslash($s->message)
	);

	echo $message;

    exit;
});