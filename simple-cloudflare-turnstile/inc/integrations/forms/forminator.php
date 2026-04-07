<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if(get_option('cfturnstile_forminator')) {

	// Get turnstile field: Forminator Forms
	add_filter( 'forminator_render_form_submit_markup', 'cfturnstile_field_forminator_form', 10, 4 );
	function cfturnstile_field_forminator_form( $html, $form_id, $post_id, $nonce ) {

        if(!cfturnstile_form_disable($form_id, 'cfturnstile_forminator_disable')) {

            ob_start();

            // Determine failsafe UI mode (keeps UI behavior consistent with backend validation)
            $failsafe_mode = '';
            if ( get_option('cfturnstile_failover') && function_exists('cfturnstile_is_cloudflare_down') && cfturnstile_is_cloudflare_down() ) {
                $failsafe_mode = get_option('cfturnstile_failsafe_type', 'allow');
                if ( $failsafe_mode !== 'recaptcha' && $failsafe_mode !== 'allow' ) {
                    $failsafe_mode = 'allow';
                }
            }

            // Only load Turnstile API in normal mode. In failsafe mode, cfturnstile_field_show()
            // renders a marker or reCAPTCHA instead, so Turnstile JS would be unnecessary (and can error).
            if ( $failsafe_mode === '' ) {
                // if cfturnstile script doesnt exist, enqueue it
                if(!wp_script_is('cfturnstile', 'enqueued')) {
                    wp_register_script("cfturnstile", "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit", array(), '', 'true');
                    wp_print_scripts('cfturnstile');
                }
            }
            echo "<style>#cf-turnstile-fmntr-".esc_html($form_id)." { margin-left: 0px !important; }</style>";

            cfturnstile_field_show('.forminator-button-submit', 'turnstileForminatorCallback', 'forminator-form-' . esc_html($form_id), '-fmntr-' . esc_html($form_id));

            // If failsafe reCAPTCHA is used, ensure the script tag is printed even when the form is
            // loaded via AJAX (wp_enqueue_script alone may not output in the AJAX response).
            if ( $failsafe_mode === 'recaptcha' && wp_script_is('cfturnstile-recaptcha', 'enqueued') && !wp_script_is('cfturnstile-recaptcha', 'done') ) {
                wp_print_scripts('cfturnstile-recaptcha');
            }
            ?>
            <?php if ( $failsafe_mode === '' ) { ?>
            <script>
            // On ajax.complete run turnstile.render if element is empty
            jQuery(document).ajaxComplete(function() {
                setTimeout(function() {
                    if (document.getElementById('cf-turnstile-fmntr-<?php echo esc_html($form_id); ?>')) {
                        if(!document.getElementById('cf-turnstile-fmntr-<?php echo esc_html($form_id); ?>').innerHTML.trim()) {
                                turnstile.remove('#cf-turnstile-fmntr-<?php echo esc_html($form_id); ?>');
                                turnstile.render('#cf-turnstile-fmntr-<?php echo esc_html($form_id); ?>');
                        }
                    }
                }, 1000);
            });
            // Enable Submit Button Function
            function turnstileForminatorCallback() {
                document.querySelectorAll('.forminator-button, .forminator-button-submit').forEach(function(el) {
                    el.style.pointerEvents = 'auto';
                    el.style.opacity = '1';
                });
            }
            // On submit re-render
            jQuery(document).ready(function() {
                jQuery('.forminator-custom-form').on('submit', function() {
                    if(document.getElementById('cf-turnstile-fmntr-<?php echo esc_html($form_id); ?>')) {
                        setTimeout(function() {
                            turnstile.remove('#cf-turnstile-fmntr-<?php echo esc_html($form_id); ?>');
                            turnstile.render('#cf-turnstile-fmntr-<?php echo esc_html($form_id); ?>');
                        }, 1000);
                    }
                });
            });
            </script>
            <?php } ?>
            <?php
            $cfturnstile = ob_get_contents();
            ob_end_clean();
            wp_reset_postdata();

            if(!empty(get_option('cfturnstile_forminator_pos')) && get_option('cfturnstile_forminator_pos') == "after") {
                return $html . $cfturnstile;
            } else {
                return $cfturnstile . $html;
            }

        } else {
            return $html;
        }

	}

	// Forminator Forms Check
	add_action('forminator_custom_form_submit_errors', 'cfturnstile_forminator_check', 10, 3);
	function cfturnstile_forminator_check($submit_errors, $form_id, $field_data_array){
        if(!cfturnstile_form_disable($form_id, 'cfturnstile_forminator_disable')) {

            // Forminator may call this hook multiple times for the same logical submission,
            // so we use a transient to cache successful validations for a short time.
            $form_uid  = isset($_POST['form_uid']) ? sanitize_text_field(wp_unslash($_POST['form_uid'])) : '';
            $cache_key = '';
            if ($form_uid !== '') {
                $cache_key = 'cfturnstile_forminator_' . md5($form_id . '|' . $form_uid);
                $cached    = get_transient($cache_key);
                if (is_array($cached) && isset($cached['success']) && $cached['success'] === true) {
                    return $submit_errors;
                }
            }

            $posted_data = array();
            if (is_array($field_data_array)) {
                foreach ($field_data_array as $key => $val) {
                    // Sometimes Forminator provides an associative array of name => value
                    if (is_string($key) && !is_array($val) && !is_object($val)) {
                        $posted_data[$key] = $val;
                        continue;
                    }
                    // Sometimes it provides an array of arrays with keys like ['name' => ..., 'value' => ...]
                    if (is_array($val) && isset($val['name'])) {
                        $name = $val['name'];
                        $value = array_key_exists('value', $val) ? $val['value'] : '';
                        if (is_string($name)) {
                            $posted_data[$name] = $value;
                        }
                        continue;
                    }
                    // Or an array of objects with ->name and ->value
                    if (is_object($val) && isset($val->name)) {
                        $name = $val->name;
                        $value = isset($val->value) ? $val->value : '';
                        if (is_string($name)) {
                            $posted_data[$name] = $value;
                        }
                    }
                }
            }

            $token = '';
            if (isset($posted_data['cf-turnstile-response']) && !is_array($posted_data['cf-turnstile-response'])) {
                $token = sanitize_text_field($posted_data['cf-turnstile-response']);
            }

            // Fallback: if the token was not present in the structured field data
            if ($token === '' && isset($_POST['cf-turnstile-response']) && !is_array($_POST['cf-turnstile-response'])) {
                $token = sanitize_text_field($_POST['cf-turnstile-response']);
            }

            $_post_backup = array();
            $sync_keys = array(
                'cf-turnstile-response',
                'cfturnstile_failsafe',
                'g-recaptcha-response',
            );
            foreach ($sync_keys as $sync_key) {
                $_post_backup[$sync_key] = array_key_exists($sync_key, $_POST) ? $_POST[$sync_key] : null;
                if (isset($posted_data[$sync_key]) && !is_array($posted_data[$sync_key])) {
                    $_POST[$sync_key] = sanitize_text_field($posted_data[$sync_key]);
                }
            }

            // Ensure the resolved token is available in $_POST for cfturnstile_check()
            // and any failover logic that reads from $_POST.
            if ($token !== '') {
                $_POST['cf-turnstile-response'] = $token;
            }

            $check = cfturnstile_check($token);
            foreach ($_post_backup as $sync_key => $old_val) {
                if ($old_val === null) {
                    unset($_POST[$sync_key]);
                } else {
                    $_POST[$sync_key] = $old_val;
                }
            }

            $success = (is_array($check) && isset($check['success'])) ? $check['success'] : false;
            if($success != true) {
                $submit_errors[]['submit'] = cfturnstile_failed_message();
            } elseif ($cache_key !== '') {
                set_transient($cache_key, array('success' => true), 5 * MINUTE_IN_SECONDS);
            }
        }
        return $submit_errors;
	}

}