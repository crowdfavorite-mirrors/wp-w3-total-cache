<?php

class W3_Setup {
    const FILE_PUT_CONTENTS = 'file_put_contents';
    const FPUTS = 'fputs';
    const WPFS = 'wp filesystem';
    const PHP_CHMOD = 'chmod';
    const WPFS_CHMOD = 'wp filesystem chmod';
    private static $test_dir;
    function __construct() {
        self::$test_dir = W3TC_CACHE_DIR . DIRECTORY_SEPARATOR . '/test_dir';
    }

    function get_setup_message($cache_folders, $addin_files, $ftp_form) {
        global $pagenow;
        $addin_files_messages = $addin_files ? $addin_files['messages'] : array();
        $addin_files = $addin_files ? $addin_files['files'] : array();
        $not_installed = '<ul>';
        if (is_array($cache_folders))
            foreach ($cache_folders as $folder)
                $not_installed .= '<li>' . $folder . '</li>';
        if (is_array($addin_files_messages))
            foreach ($addin_files_messages as $message)
                $not_installed .= '<li>' . $message . '</li>';

        $not_installed .= '</ul>';
        $headline = $pagenow == 'plugins.php' ? '<h2 id="w3tc">W3 Total Cache Error</h2>': '';
        $error = sprintf('%s<p>Unfortunately we\'re not able to create folders and files or write to them to complete
                            the installation for you automatically. Please enter FTP details
                            <a href="#ftp_upload_form">below</a> to complete the setup. %s</p>
                            <div class="w3tc-required-changes" style="display:none">%s</div><p>' .
                            (($cache_folders || $addin_files) ?
                                'Either the <strong>%s</strong> directory is not write-able or another caching plugin
                                 is installed.'
                                : '') .
                            'This error message will automatically disappear once the change is successfully made.</p>'
                            , $headline, $this->button('View required changes', '', 'w3tc-show-required-changes')
                            , $not_installed, WP_CONTENT_DIR);
        if ($pagenow == 'plugins.php') {
            $ftp_form = '<div id="ftp_upload_form" class="updated fade" style="background: none;border: none;">' .
                            str_replace('class="wrap"', '',$ftp_form) . '</div>';
        }
        $result = $this->_test_single_file_creation();
        if (is_array($result))
                $error .= sprintf('<p>Current process is run by %s but owner of created files is %s. They should be the same.
                                    Talk with your host about this issue.</p>
                                    <p>If this is correct behavior then read
                                   <a href="http://codex.wordpress.org/Editing_wp-config.php#WordPress_Upgrade_Constants">
                                   WordPress Update Constants section in WordPress Codex</a>
                                   with regards to FS_METHOD for possible solution. Defining it disables the check.
                                   </p>'
                                , $result['process']
                                , $result['fileowner']);

        return array('message' => $error, 'ftp_form' => $ftp_form);
    }

    /**
     * @param $results
     * @return string
     */
    function format_test_result($results) {
        $list = '<ul>';
        foreach ($results as $result) {
            if (is_array($result))
                $list .= '<li>' . implode(',', $result) . '</li>';
            else
                $list .= '<li>' . $result . '</li>';
        }
        $list .= '</ul>';
        return $list;
    }

    public function try_create_missing_files() {
        $prev_perm = get_transient('w3tc_prev_permission');
        if (!$prev_perm) {
            $prev_perm = w3_get_file_permissions(WP_CONTENT_DIR);
            set_transient('w3tc_prev_permission', $prev_perm, 3600*24);
        }
        /**
         * @var $w3_verify W3_FileVerification
         */
        $w3_verify = w3_instance('W3_FileVerification');

        $result_verify = $w3_verify->verify_addins();
        if (empty($result_verify))
            return true;
        $addin_files = $result_verify['files'];
        $url = w3_is_network() ?
                    network_admin_url('admin.php?page=w3tc_general') : admin_url('admin.php?page=w3tc_general');
        try {
            w3_require_once(W3TC_INC_DIR . '/functions/activation.php');
            w3_create_files($addin_files, '', $url);
        } catch(Exception $ex) {
            if ($ex instanceof FilesystemCredentialException) {
                throw new TryException('Could not create files', $result_verify, $ex->ftp_form());
            } else {
                try {
                    $prev_perm = w3_get_file_permissions(WP_CONTENT_DIR);
                    $this->_try_create_missing_files($addin_files, $url);
                } catch(Exception $ex) {
                    if ($ex instanceof FilesystemCredentialException) {
                        throw new TryException('Could not create files', $result_verify, $ex->ftp_form());
                    } else {
                        if ($ex instanceof FileOperationException && $ex->getOperation() == 'chmod') {
                            $current_perm = w3_get_file_permissions(WP_CONTENT_DIR);
                            throw new W3TCErrorException(sprintf('<strong>W3 Total Cache Error:</strong> Could not
                                            change permissions %d on %s back to original permissions %d.'
                                            , $current_perm, WP_CONTENT_DIR, $prev_perm));
                        }else
                            throw new W3TCErrorException(sprintf('<strong>W3 Total Cache Error:</strong> %s<br />
                                            Verify that correct (server, S/FTP)
                                            <a target="_blank" href="http://codex.wordpress.org/Changing_File_Permissions">
                                                file permissions</a>
                                            are set or set FS_CHMOD_* constants in wp-config.php
                                            <a target="_blank" href="http://codex.wordpress.org/Editing_wp-config.php#Override_of_default_file_permissions">
                                                Learn more</a>.'
                                            , $ex->getMessage()));
                    }
                }
            }
        }
        return true;
    }

    public function try_create_missing_folders() {
        $prev_perm = get_transient('w3tc_prev_permission');
        if (!$prev_perm) {
            $prev_perm = w3_get_file_permissions(WP_CONTENT_DIR);
            set_transient('w3tc_prev_permission', $prev_perm, 3600);
        }

        /**
         * @var $w3_verify W3_FileVerification
         */
        $w3_verify = w3_instance('W3_FileVerification');

        $folders = $w3_verify->check_default_folders();
        if (empty($folders))
            return true;
        $url = w3_is_network() ?
                    network_admin_url('admin.php?page=w3tc_general') : admin_url('admin.php?page=w3tc_general');
        try {
            w3_require_once(W3TC_INC_DIR . '/functions/activation.php');
            w3_create_folders($folders, '', $url);
            $results = $this->test_file_writing();
        } catch(Exception $ex) {
            if ($ex instanceof FilesystemCredentialException) {
                throw new TryException('Could not create folders', $folders, $ex->ftp_form());
            } else {
                try {
                    $this->_try_create_missing_folders($folders, $url);
                    $results = $this->test_file_writing();
                } catch(Exception $ex) {
                    if ($ex instanceof FilesystemCredentialException) {
                        throw new TryException('Could not create folders', $folders, $ex->ftp_form());
                    } else {
                        if ($ex instanceof FileOperationException && $ex->getOperation() == 'chmod') {
                            $current_perm = w3_get_file_permissions(WP_CONTENT_DIR);
                            throw new W3TCErrorException(sprintf('<strong>W3 Total Cache Error:</strong> Could not
                                            change permissions %d on %s back to original permissions %d.'
                                            , $current_perm, WP_CONTENT_DIR, $prev_perm));
                        }else
                            throw new W3TCErrorException(sprintf('<strong>W3 Total Cache Error:</strong> %s<br />Verify
                                            that correct (server, S/FTP)
                                            <a target="_blank" href="http://codex.wordpress.org/Changing_File_Permissions">
                                                file permissions</a>
                                            are set or set FS_CHMOD_* constants in wp-config.php
                                            <a target="_blank" href="http://codex.wordpress.org/Editing_wp-config.php#Override_of_default_file_permissions">
                                                Learn more</a>.'
                                            , $ex->getMessage()));
                    }
                }
                if ($results)
                    throw new W3TCErrorException(sprintf('<strong>W3 Total Cache Error:</strong> %s<br />Verify that
                                    correct (server, S/FTP)
                                    <a target="_blank" href="http://codex.wordpress.org/Changing_File_Permissions">
                                        file permissions</a>
                                    are set or set FS_CHMOD_* constants in wp-config.php
                                    <a target="_blank" href="http://codex.wordpress.org/Editing_wp-config.php#Override_of_default_file_permissions">
                                        Learn more</a>.'
                                    , $ex->getMessage()));
            }
        }

        if ($results)
            throw new TestException('Testing failed', $results);

        $this->add_index_to_folders();

        return true;
    }

    /**
     * Adds index files
     */
    public function add_index_to_folders() {
        $directories = array(
            W3TC_CACHE_DIR,
            W3TC_CONFIG_DIR,
            W3TC_CACHE_CONFIG_DIR);
        $add_files = array();
        foreach ($directories as $dir) {
            if (is_dir($dir) && !file_exists($dir . '/index.html'))
                @file_put_contents($dir . '/index.html', '');
        }
    }

    private function _try_create_missing_folders($folders, $url) {
        $permissions = array(0755, 0775, 0777);
        $prev_perm = w3_get_file_permissions(WP_CONTENT_DIR);
        foreach ($permissions as $permission) {
            $result = true;
            if ($permission == $prev_perm)
                continue;
            if (!($result = @chmod(WP_CONTENT_DIR, $permission)))
                $result = w3_chmod_dir(WP_CONTENT_DIR, $permission);
            if ($result) {
                try {
                    w3_create_folders($folders, '', $url);
                    return true;
                }catch (Exception $ex) {}
            }
            if (!@chmod(WP_CONTENT_DIR, $prev_perm))
                w3_chmod_dir(WP_CONTENT_DIR, $prev_perm);
        }
        return true;
    }

    private function _try_create_missing_files($files, $url) {
        $permissions = array(0755, 0775, 0777);
        $prev_perm = w3_get_file_permissions(WP_CONTENT_DIR);
        foreach ($permissions as $permission) {
            $result = true;
            if ($permission == $prev_perm)
                continue;
            if (!($result = @chmod(WP_CONTENT_DIR, $permission)))
                $result = w3_chmod_dir(WP_CONTENT_DIR, $permission);
            if ($result) {
                try {
                    w3_create_files($files, '', $url);
                    return true;
                }catch (Exception $ex) {}
            }
            if (!@chmod(WP_CONTENT_DIR, $prev_perm))
                w3_chmod_dir(WP_CONTENT_DIR, $prev_perm);
        }
        return true;
    }

    public function test_file_writing() {
        $results = array();
        $permissions = array(0755, 0775, 0777);
        $test_result1 = $this->_test_cache_file_creation();
        if (!$test_result1['success']) {
            w3_require_once(W3TC_INC_DIR . '/functions/activation.php');
            w3_require_once(W3TC_INC_DIR . '/functions/file.php');
            $prev_perm = w3_get_file_permissions(W3TC_CACHE_DIR);
            foreach ($permissions as $permission) {
                if ($permission == $prev_perm)
                    continue;
                $result = w3_chmod_dir(W3TC_CACHE_DIR, $permission, true);
                if ($result) {
                    $test_result1 = $this->_test_cache_file_creation();
                    if ($test_result1['success']) {
                        $c_fileowngrp = w3_get_file_owner(W3TC_CACHE_DIR);
                        $d_fileowngrp = w3_get_file_owner();

                        $results['permissions'][] = sprintf('Plugin changed permissions on: %s to %d from %s. <br />' .
                            'Default file owner is %s, plugin created files is owned by %s.',
                            W3TC_CACHE_DIR, decoct($permission), $prev_perm,
                            $d_fileowngrp, $c_fileowngrp);
                        break;
                    }
                } else {
                    $results[] = 'Folder does not exists: ' . W3TC_CACHE_DIR;
                }
            }
        }
        $test_result2 = $this->_test_w3tc_config_creation();

        if (!$test_result2['success']) {
            w3_require_once(W3TC_INC_DIR . '/functions/activation.php');
            w3_require_once(W3TC_INC_DIR . '/functions/file.php');
            $prev_perm = w3_get_file_permissions(W3TC_CONFIG_DIR);
            foreach ($permissions as $permission) {
                if ($permission == $prev_perm)
                    continue;
                $result = w3_chmod_dir(W3TC_CONFIG_DIR, $permission);
                if ($result) {
                    $test_result2 = $this->_test_w3tc_config_creation();
                    if ($test_result2['success']) {
                        $c_fileowngrp = w3_get_file_owner(W3TC_CONFIG_DIR);
                        $d_fileowngrp = w3_get_file_owner();

                        $results['permissions'][] = sprintf('Plugin changed permissions on: %s to %d from %s. <br />' .
                                'Default file owner is %s, plugin created files is owned by %s.',
                            W3TC_CONFIG_DIR, decoct($permission), $prev_perm,
                            $d_fileowngrp, $c_fileowngrp);
                        break;
                    }
                } else {
                    $results[] = 'Folder does not exists: ' . W3TC_CONFIG_DIR;
                }
            }
        }

        foreach ($test_result1 as $test => $result) {
            if ($test == 'folder' && !$result)
                $results[] = sprintf('Could not mkdir: %s', self::$test_dir);
            elseif (!$result)
                $results[] = sprintf('Could not create file in %s using %s.', self::$test_dir,  $test);
        }

        foreach ($test_result2 as $test => $result) {
            if (!$result)
                $results[] = sprintf('Could not create file in %s using %s.', W3TC_CONFIG_DIR,  $test);
        }

        return $results;
    }

    private function _test_single_file_creation() {
        $result = false;
        if (defined('FS_METHOD') && in_array(FS_METHOD, array('direct', 'ssh', 'ftpext', 'ftpsockets')))
            return true;

        if (function_exists('getmyuid') && function_exists('fileowner') ){
            $context = WP_CONTENT_DIR;
            $context = trailingslashit($context);
            $temp_file_name = $context . 'temp-write-test-' . time();
            $temp_handle = @fopen($temp_file_name, 'w');
            if ( $temp_handle ) {
                if ( ($uid = getmyuid()) == ($fo = @fileowner($temp_file_name)) )
                    $result = true;
                $guid = getmygid();
                $fogui = filegroup($temp_file_name);
                @fclose($temp_handle);
                @unlink($temp_file_name);
            }
        } else {
            return false;
        }
        if (!$result && isset($uid) && isset($fo) && isset($guid) && isset($fogui)) {
            $pugid = $fugid = '';
            if (function_exists('posix_getpwuid')) {
                $pwuid = posix_getpwuid($uid);
                $pwgid = posix_getgrgid($guid);
                $pugid = $pwuid['name'] . ':' . $pwgid['name'] . ' ';
                $pwfo = posix_getpwuid($fo);
                $pwfog = posix_getgrgid($fogui);
                $fugid = $pwfo['name'] . ':' . $pwfog['name'] . ' ';
            }
            $pugid .= '(' . $uid . ':' . $guid . ')';
            $fugid .= '(' . $fo . ':' . $fogui . ')';
            return array('process' => $pugid, 'fileowner' => $fugid);
        }
        return $result;
    }

    private function _test_cache_file_creation() {
        $result = array();
        $result['folder'] = $this->_test_cache_folder();
        if ($result['folder']) {
            $result['success'] = true;
            $methods = array(self::FILE_PUT_CONTENTS, self::FPUTS);
            foreach ($methods as $method) {
                $result[$method] = $this->_test_page_enhanced_file($method);
                $result['success'] = $result['success'] && $result[$method];
            }
        } else {
            $result['success'] = false;
        }
        $this->_test_cleanup();
        return $result;
    }

    private function _test_cache_folder() {
        w3_require_once(W3TC_INC_DIR . '/functions/file.php');
        $test_file = self::$test_dir . '/test.php';
        return w3_mkdir_from(dirname($test_file), W3TC_CACHE_DIR);
    }

    private function _test_page_enhanced_file($method) {
        w3_require_once(W3TC_INC_DIR . '/functions/file.php');
        return $this->_test_create_file(self::$test_dir, $method);
    }

    private function _test_w3tc_config_creation() {
        $result = array();
        $methods = array(self::FILE_PUT_CONTENTS, self::FPUTS);
        $result['success'] = true;
        foreach ($methods as $method) {
            $result[$method] = $this->_test_create_file(W3TC_CONFIG_DIR, $method);
            $result['success'] = $result['success'] && $result[$method];
        }
        return $result;
    }

    private function _test_cleanup() {
        w3_rmdir(self::$test_dir);
    }

    private function _test_create_file($folder, $method) {
        $test_file = $folder . DIRECTORY_SEPARATOR . 'test_file';
        switch ($method) {
            case W3_Setup::FPUTS:
                return $this->_test_create_file_fputs($test_file);
            case W3_Setup::FILE_PUT_CONTENTS:
                return $this->_test_create_file_file_put($test_file);
            case W3_Setup::WPFS:
                return $this->_test_create_file_wpfs($test_file);
        }
        return false;
    }

    /**
     * @param $test_file
     * @return bool true on success
     */
    private function _test_create_file_file_put($test_file) {
        $result = @file_put_contents($test_file, 'test content') !== false;
        @unlink($test_file);
        return $result;
    }

    private function _test_create_file_fputs($test_file) {
        $fp = @fopen($test_file, 'w');

        if (!$fp)
            return false;
        if (@flock($fp, LOCK_EX)) {
            @fputs($fp, $test_file);
            @fclose($fp);
            @flock($fp, LOCK_UN);
            @unlink($test_file);
            return true;
        } else {
            return false;
        }
    }

    private function _test_create_file_wpfs($test_file) {
        return false;
    }

    function button($text, $onclick = '', $class = '') {
        return sprintf('<input type="button" class="button %s" value="%s" onclick="%s" />'
                        , htmlspecialchars($class), htmlspecialchars($text), htmlspecialchars($onclick));
    }

    /**
     * @throws TryException
     * @throws W3TCErrorException
     * @return bool
     */
    public function try_create_wp_loader() {
        $file_data = $this->w3tc_loader_file_data();
        $filename = $file_data['filename'];
        $data = $file_data['data'];
        w3_require_once(W3TC_INC_DIR . '/functions/rule.php');
        if (($current_data = @file_get_contents($filename)) && strstr(w3_clean_rules($current_data), w3_clean_rules($data)) !== false)
            return true;

        $url = w3_is_network() ?
            network_admin_url('admin.php?page=w3tc_general') : admin_url('admin.php?page=w3tc_general');
        try {
            w3_require_once(W3TC_INC_DIR . '/functions/activation.php');
            $result = w3_write_to_file($filename, $data, $url);
        } catch(Exception $ex) {
            if ($ex instanceof FilesystemCredentialException) {
                throw new TryException('Could not create file', array($filename), $ex->ftp_form());
            } else {
                throw new W3TCErrorException(sprintf('<strong>W3 Total Cache Error:</strong>Could not create file <strong>%s</strong>
                    with content: <pre>%s</pre><br />You need to do this manually.'
                    , $filename, esc_textarea($data)));
            }
        }
        return true;
    }

    public function w3tc_loader_file_data() {
        $filename= W3TC_WP_LOADER;
        $data = "
<?php
    if (W3TC_WP_LOADING)
        require_once '" . w3_get_document_root() . '/' . trim(w3_get_site_path() ,"/") . "/wp-load.php';
";
        return array('filename' => $filename, 'data' => $data);
    }
}

class TryException extends Exception {
    private $files = array();
    private $ftp_form = '';

    function __construct($message = '', $files = array(), $ftp_form = '') {
        parent::__construct($message);
        $this->files = $files;
        $this->ftp_form = $ftp_form;
    }

    function getFiles() {
        return $this->files;
    }

    function getFtpForm() {
        return $this->ftp_form;
    }
}
class TestException extends Exception {
    private $tests = array();

    function __construct($message, $test_results = array()) {
        parent::__construct($message);
        $this->tests = $test_results;
    }

    function getTestResults() {
        return $this->tests;
    }
}
class W3TCErrorException extends Exception {

}