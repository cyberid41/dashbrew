<?php

namespace Dashbrew\Tasks;

use Dashbrew\Commands\ProvisionCommand;
use Dashbrew\Task\Task;
use Dashbrew\Util\Config;
use Dashbrew\Util\Util;
use Dashbrew\Util\Registry;

/**
 * ProjectsProcess Task Class.
 *
 * @package Dashbrew\Tasks
 */
class ProjectsProcessTask extends Task {

    /**
     * @throws \Exception
     */
    public function run() {

        if(!$this->command instanceof ProvisionCommand){
            throw new \Exception("The Config task can only be run by the Provision command.");
        }

        $projects = Registry::get('projects');

        foreach(['check', 'modify', 'create', 'delete'] as $action){
            foreach($projects[$action] as $id => $project){
                if(!empty($project['vhost'])){
                    $this->processVhost($action, $id, $project);
                }
            }
        }
    }

    protected function processVhost($action, $id, $project) {

        static $verbs;

        if(null === $verbs){
            $verbs = [
                'delete' => ['Removing', 'remove'],
                'modify' => ['Updating', 'update'],
                'create' => ['Writing', 'write'],
            ];

            $verbs['check'] = $verbs['modify'];
        }

        $fs = Util::getFilesystem();
        $vhost = $project['vhost'];
        $vhost = array_merge([
            'docroot'         => '${dir}',
            'servername'      => $id,
            'options'         => ['Indexes','FollowSymLinks','MultiViews'],
            'override'        => ['None'],
            'directoryindex'  => '',
            'ssl'             => false,
            'ssl_cert'        => '/etc/ssl/certs/ssl-cert-snakeoil.pem',
            'ssl_key'         => '/etc/ssl/private/ssl-cert-snakeoil.key',
            'ssl_certs_dir'   => '/etc/ssl/certs',
            'php-fpm'         => '',
        ], $vhost);

        $vhost_file = "/etc/apache2/sites-enabled/{$id}.conf";
        $vhost_ssl_file = "/etc/apache2/sites-enabled/{$id}-ssl.conf";

        if($action == 'delete'){
            if(file_exists($vhost_file)){
                $this->output->writeInfo("{$verbs[$action][0]} apache vhost for '$id'");
                $fs->remove($vhost_file);
            }

            if(file_exists($vhost_ssl_file)){
                $this->output->writeInfo("{$verbs[$action][0]} apache ssl vhost for '$id'");
                $fs->remove($vhost_ssl_file);
            }

            return;
        }

        if(!$vhost['ssl'] && file_exists($vhost_ssl_file)){
            $this->output->writeInfo("{$verbs['delete'][0]} apache ssl vhost for '$id'");
            $fs->remove($vhost_ssl_file);
        }

        // Defauly vhost directory
        if(empty($vhost['directories'])){
            $vhost['directories'] = [[
                'provider'       => 'directory',
                'path'           => $vhost['docroot'],
                'options'        => $vhost['options'],
                'allow_override' => $vhost['override'],
                'directoryindex' => $vhost['directoryindex'],
                'require'        => 'all granted',
            ]];
        }

        if(!empty($project['php'])){
            $vhost = $this->__set_vhost_fpm_include($id, $project, $vhost);
        }

        foreach($vhost['directories'] as $key => $dir){
            if(empty($dir['path']) || empty($dir['provider'])){
                unset($vhost['directories'][$key]);
                continue;
            }

            if(!preg_match('(directory|location|files)', $dir['provider']))
                $dir['provider'] = 'directory';

            $vhost['directories'][$key]['provider'] = ucfirst(str_replace('match', 'Match', $dir['provider']));
        }

        $vhost['serveradmin'] = "admin@$vhost[servername]";
        $vhost['port'] = '80';
        $vhost['access_log'] = "/var/log/apache2/vhost-{$id}.access.log";
        $vhost['error_log'] = "/var/log/apache2/vhost-{$id}.error.log";

        $vhost = $this->__replace_vhost_vars($vhost, $project['_path']);
        $vhost_file_content = Util::renderTemplate('apache/vhost.php', [
            'vhost'              => $vhost,
            '_project_id'        => $id,
            '_project_file_path' => $project['_path'],
        ], false);

        $vhost_file_save = false;
        if(!file_exists($vhost_file) || md5($vhost_file_content) !== md5_file($vhost_file)){
            $vhost_file_save = true;
        }

        if($vhost_file_save){
            $this->output->writeInfo("{$verbs[$action][0]} apache vhost file for '$id'");
            if(!file_put_contents($vhost_file, $vhost_file_content)){
                $this->output->writeError("Unable to {$verbs[$action][1]} apache vhost file '$vhost_file'");
            }
        }

        if($vhost['ssl']){
            $vhost_ssl = $vhost;

            $vhost_ssl['port'] = '443';
            $vhost_ssl['access_log'] = "/var/log/apache2/vhost-{$id}-ssl.access.log";
            $vhost_ssl['error_log'] = "/var/log/apache2/vhost-{$id}-ssl.error.log";

            $vhost_ssl_file_content = Util::renderTemplate('apache/vhost.php', [
                'vhost'              => $vhost_ssl,
                '_project_id'        => $id,
                '_project_file_path' => $project['_path'],
            ], false);

            $vhost_ssl_file_save = false;
            if(!file_exists($vhost_ssl_file) || md5($vhost_ssl_file_content) !== md5_file($vhost_ssl_file)){
                $vhost_ssl_file_save = true;
            }

            if($vhost_ssl_file_save){
                $this->output->writeInfo("{$verbs[$action][0]} apache ssl vhost file for '$id'");
                if(!file_put_contents($vhost_ssl_file, $vhost_ssl_file_content)){
                    $this->output->writeError("Unable to {$verbs[$action][1]} apache ssl vhost file '$vhost_ssl_file'");
                }
            }
        }
    }

    protected function __set_vhost_fpm_include($id, $project, $vhost) {

        static $default_php_version;

        $phps_config = Config::get('php::builds');
        // set default php version if not set
        if(null === $default_php_version){
            foreach($phps_config as $version => $meta){
                if(!empty($meta['default'])){
                    $default_php_version = $version;
                }
            }

            if(null === $default_php_version){
                $default_php_version = 0;
            }
        }

        $php_version = $project['php'];
        if($php_version == 'default' && 0 === $default_php_version){
            $this->output->writeError("Unable to use default php version for project '$id', no default php version found");
            return $vhost;
        }

        if($php_version == 'default'){
            $php_version = $default_php_version;
        }

        $phps_installed = Util::getInstalledPhps();
        if(!in_array('php-' . $php_version, $phps_installed) || !isset($phps_config[$php_version])){
            $this->output->writeError("Unable to use php version '$php_version' for project '$id', php version isn't installed");
            return $vhost;
        }

        if(empty($phps_config[$php_version]['fpm']['port'])){
            $this->output->writeError("Unable to use php version '$php_version' for project '$id', php fpm port isn't configured");
            return $vhost;
        }

        $php_version_fpm_conf = '/etc/apache2/php/php-' . $php_version . '-fpm.conf';
        if(!file_exists($php_version_fpm_conf)){
            $this->output->writeError("Unable to use php version '$php_version' for project '$id', apache php-fpm config file '$php_version_fpm_conf' doesn't exist");
            return $vhost;
        }

        $vhost['includes'] = [
            $php_version_fpm_conf
        ];

        return $vhost;
    }

    protected function __replace_vhost_vars($vhost, $project_file_path) {

        $vars = [
            'dir' => str_replace('/vagrant/public', '/var/www', dirname($project_file_path)),
            'root' => '/var/www'
        ];

        $vars['dir_esc'] = preg_quote($vars['dir']);
        $vars['root_esc'] = preg_quote($vars['root']);

        $s = [];
        $r = [];
        foreach($vars as $varname => $varvalue){
            $s[] = '${' . $varname . '}';
            $r[] = strval($varvalue);
        }

        foreach(['docroot'] as $key){
            $vhost[$key] = str_replace($s, $r, $vhost[$key]);
        }

        foreach($vhost['directories'] as $key => $dir){
            if(isset($vhost['directories'][$key]['path'])){
                $vhost['directories'][$key]['path'] = str_replace($s, $r, $vhost['directories'][$key]['path']);
            }
        }

        return $vhost;
    }

}
