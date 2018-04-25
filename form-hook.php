<?php
/*
Plugin Name: Form hook
Plugin URI: http://zomertoernooi.sbctoernooien.nl
Description: Post form 7 data naar webhook
Author: Vincent van der Weele
Version: 1.0
Author URI: http://zomertoernooi.sbctoernooien.nl
*/

defined( 'ABSPATH' ) or die( 'No direct access' );

// install / uninstall
register_activation_hook(__FILE__,'install');
register_deactivation_hook( __FILE__, 'uninstall' );

// register admin menu
add_action('admin_menu', 'addPluginMenu' );

// add hook for form submitting
add_action('wpcf7_mail_sent', 'handleFormData');

// handle post requests
add_action('wp_ajax_form_hook_test', 'testHook');
add_action('wp_ajax_form_hook_save', 'saveData');

const WEBHOOK_NAME = 'form_hook_name';
const WEBHOOK_SECRET = 'form_hook_secret';

function install() {
  add_option(WEBHOOK_NAME, '');
	add_option(WEBHOOK_SECRET, '');
}

function uninstall() {
  delete_option(WEBHOOK_NAME);
  delete_option(WEBHOOK_SECRET);
}

function addPluginMenu() {
  add_options_page('Form hook options', 'Form hook', 'manage_options', 'form-hook','addPluginPage');
}

function addPluginPage() {
  if ( !current_user_can( 'manage_options' ) )  {
    wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
  }

  $webhook = get_option(WEBHOOK_NAME);
  $secret = get_option(WEBHOOK_SECRET);

  ?>
  <script>
    function wpAjax(action, data, onResponse) {
      jQuery.post(ajaxurl, {
        action,
        data,
        nonce: '<?php print(wp_create_nonce("form_hook_nonce")) ?>',
      }, onResponse);
    };

    function test() {
      const webhook = document.getElementById('webhook').value;
      const secret = document.getElementById('secret').value;

      wpAjax('form_hook_test', { webhook, secret }, ({ data }) => {
        console.log(data);
        document.getElementById('test-output').innerHTML = data;
      });
    }

    function save() {
      const webhook = document.getElementById('webhook').value;
      const secret = document.getElementById('secret').value;

      wpAjax('form_hook_save', { webhook, secret }, (response) => {
        console.log(response);
      });
    }
  </script>
  <h1>Form hook</h1>
  <form >
    <label>
      Webhook:
      <input
        type="text"
        id="webhook"
        value="<?php print($webhook) ?>"/>
    </label>
    <label>
      Secret:
      <input
        type="password"
        id="secret"
        value="<?php print($secret) ?>"/>
    </label>
    <button type="button" onClick="test()">Test</button>
    <button type="button" onClick="save()">Save</button>
  </form>
  <div id='test-output'></div>
  <?php
}

function handleFormData($cf7) {
  $webhook = get_option(WEBHOOK_NAME);
  $secret = get_option(WEBHOOK_SECRET);

  try {
    sendHook(
      $webhook,
      $secret,
      convertData($cf7),
      FALSE
    );
  } catch (Exception $e) {
    // swallow the exception
  }
}

function testHook() {
  check_ajax_referer('form_hook_nonce', 'nonce');

  $webhook = $_POST['data']['webhook'];
  $secret = $_POST['data']['secret'];

  try {
    sendHook(
      $webhook,
      $secret,
      array('test' => 123),
      TRUE
    );
    wp_send_json_success('OK');
  } catch (Exception $e) {
    wp_send_json_error($e->getMessage());
  }
}

function sendHook($webhook, $secret, $data, $test) {
  if ($webhook) {
    $headers = array('Content-Type' => 'application/json; charset=utf-8');
    if ($secret) {
      $headers['X-hook-secret'] = $secret;
    }
    if ($test) {
      $headers['X-test'] = TRUE;
    }

    $response = wp_remote_post($webhook, array(
      'headers'   => $headers,
      'body'      => json_encode($data),
      'method'    => 'POST'
    ));

    if (is_wp_error($response)) {
      $error_message = $response->get_error_message();
      throw new Exception($error_message);
   }
  }
}

function convertData($cf7) {
  if (!isset($cf7->posted_data) && class_exists('WPCF7_Submission')) {
    // Contact Form 7 version 3.9 removed $cf7->posted_data and now
    // we have to retrieve it from an API
    $submission = WPCF7_Submission::get_instance();
    if ($submission) {
        $data = array();
        $data['title'] = $cf7->title();
        $data['data'] = $submission->get_posted_data();
        return (object) $data;
    }
  }
  return $cf7;
}

function saveData() {
  check_ajax_referer('form_hook_nonce', 'nonce');

  $webhook = $_POST['data']['webhook'];
  $secret = $_POST['data']['secret'];

  update_option(WEBHOOK_NAME, $webhook);
  update_option(WEBHOOK_SECRET, $secret);

  wp_send_json_success('OK');
}

?>
