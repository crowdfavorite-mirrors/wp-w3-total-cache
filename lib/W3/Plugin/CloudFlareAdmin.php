<?php

/**
 * W3 CloudFlareAdmin plugin
 */
if (!defined('W3TC')) {
    die();
}

w3_require_once(W3TC_LIB_W3_DIR . '/Plugin.php');

/**
 * Class W3_Plugin_CloudFlareAdmin
 */
class W3_Plugin_CloudFlareAdmin extends W3_Plugin{

    function run() {

        /**
         * Only admin can see W3TC notices and errors
         */
        add_action('admin_notices', array(
            &$this,
            'admin_notices'
        ));
        add_action('network_admin_notices', array(
            &$this,
            'admin_notices'
        ));
    }

    /**
     * @return array|void
     */
    function deactivate() {
        /**
         * @var $dispatcher W3_Dispatcher
         */
        $dispatcher = w3_instance('W3_Dispatcher');
        return $dispatcher->remove_cloudflare_rules_with_message();
    }

    /**
     * Returns required rules for module
     * @return array
     */
    function get_required_rules() {
        /**
         * @var $dispatcher W3_Dispatcher
         */
        $dispatcher = w3_instance('W3_Dispatcher');
        return $dispatcher->get_required_rules_for_cloudflare();
    }

    function admin_notices() {
        $plugins = get_plugins();
        if (array_key_exists('cloudflare/cloudflare.php', $plugins))
            echo sprintf('<div class="error"><p>%s</p></div>', __('CloudFlare plugin detected. We recommend removing the
            plugin as it offers no additional capabilities when W3 Total Cache is installed. This message will disappear
            when CloudFlare is removed.', 'w3-total-cache'));
    }
}
