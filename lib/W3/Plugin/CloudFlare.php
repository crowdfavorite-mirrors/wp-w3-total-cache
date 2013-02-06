<?php

/**
 * W3 ObjectCache plugin
 */
if (!defined('W3TC')) {
    die();
}

w3_require_once(W3TC_LIB_W3_DIR . '/Plugin.php');

/**
 * Class W3_Plugin_CloudFlare
 */
class W3_Plugin_CloudFlare extends W3_Plugin{

    /**
     * Runs plugin
     */
    function run() {
        if (is_admin()) {
            $this->check_ip_versions();
            $this->get_admin()->run();
        }
        add_action('wp_set_comment_status', array($this, 'set_comment_status'), 1, 2);
    }

    /**
     * @return array
     */
    function deactivate() {
        return $this->get_admin()->deactivate();
    }

    /**
     * Get the corresponding Admin plugin for the module
     *
     * @return W3_Plugin_CloudFlareAdmin
     */
    function get_admin() {
        return w3_instance('W3_Plugin_CloudFlareAdmin');
    }

    /**
     * Check if last check has expired. If so update CloudFlare ips
     */
    function check_ip_versions() {
        $checked = get_transient('w3tc_cloudflare_ip_check');

        if (false === $checked) {
            $cf = w3_instance('W3_CloudFlare');
            $cf->update_ip_ranges();
            set_transient('w3tc_cloudflare_ip_check', time(), 3600*24);
        }
    }

    /**
     * @param $id
     * @param $status
     */
    function set_comment_status($id, $status) {
        $cf = w3_instance('W3_CloudFlare');
        $cf->report_if_spam($id, $status);
    }
}
