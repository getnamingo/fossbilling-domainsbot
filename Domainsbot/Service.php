<?php

/**
 * Domainsbot Name Suggestion Module
 *
 * Provides domain name suggestions based on user queries using the DomainsBot API.
 *
 * Some functions in this module are adapted from the main FOSSBilling codebase.
 *
 * @package   DomainsbotModule
 * @author    Namingo Team <help@namingo.org>
 * @license   Apache-2.0
 * @link      https://namingo.org
 *
 * FOSSBilling.
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 *
 * Copyright FOSSBilling 2022
 * This software may contain code previously used in the BoxBilling project.
 * Copyright BoxBilling, Inc 2011-2021
 *
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE.
 */

namespace Box\Mod\Domainsbot;

use FOSSBilling\Config;
use FOSSBilling\InjectionAwareInterface;
use Symfony\Contracts\Cache\ItemInterface;
use FOSSBilling\InformationException;

class Service
{
    protected $di;

    public function setDi(\Pimple\Container|null $di): void
    {
        $this->di = $di;
    }

    /**
     * Method to install the module. In most cases you will use this
     * to create database tables for your module.
     *
     * If your module isn't very complicated then the extension_meta
     * database table might be enough.
     *
     * @return bool
     *
     * @throws InformationException
     */
    public function install(): bool
    {
        // Execute SQL script if needed
        $db = $this->di['db'];
        $db->exec('SELECT NOW()');

        // throw new InformationException("Throw exception to terminate module installation process with a message", array(), 123);
        return true;
    }

    /**
     * Method to uninstall module. In most cases you will use this
     * to remove database tables for your module.
     *
     * You also can opt to keep the data in the database if you want
     * to keep the data for future use.
     *
     * @return bool
     *
     * @throws InformationException
     */
    public function uninstall(): bool
    {
        // throw new InformationException("Throw exception to terminate module uninstallation process with a message", array(), 124);
        return true;
    }

    /**
     * Method to update module. When you release new version to
     * extensions.fossbilling.org then this method will be called
     * after the new files are placed.
     *
     * @param array $manifest - information about the new module version
     *
     * @return bool
     *
     * @throws InformationException
     */
    public function update(array $manifest): bool
    {
        // throw new InformationException("Throw exception to terminate module update process with a message", array(), 125);
        return true;
    }

    /**
     * Methods is a delegate for one database row.
     *
     * @param array $row - array representing one database row
     * @param string $role - guest|client|admin who is calling this method
     * @param bool $deep - true|false deep or light version of result to return to API
     *
     * @return array
     */
    public function toApiArray(array $row, string $role = 'guest', bool $deep = true): array
    {
        return $row;
    }
    
    public function getModulePermissions(): array
    {
        return [
            'can_always_access' => true,
            'manage_settings' => [],
        ];
    }
    
    public function setConfig($data)
    {
        $this->hasManagePermission($data['ext']);
        $ext = $data['ext'];
        $this->getConfig($ext); // Creates new config if it does not exist in DB

        $this->di['events_manager']->fire(['event' => 'onBeforeAdminExtensionConfigSave', 'params' => $data]);
        $sql = "
            UPDATE extension_meta
            SET meta_value = :config
            WHERE extension = :ext
            AND meta_key = 'config'
            LIMIT 1;
        ";

        $config = json_encode($data);
        //$config = $this->di['crypt']->encrypt($config, $this->_getSalt());

        $params = [
            'ext' => $ext,
            'config' => $config,
        ];
        $this->di['db']->exec($sql, $params);
        $this->di['events_manager']->fire(['event' => 'onAfterAdminExtensionConfigSave', 'params' => $data]);
        $this->di['logger']->info('Updated extension "%s" configuration', $ext);
        $this->di['cache']->delete("config_$ext");

        return true;
    }
    
    // Checks if the current user has permission to edit a module's settings
    public function hasManagePermission(string $module, ?\Box_App $app = null): void
    {
        $staff_service = $this->di['mod_service']('Staff');

        // The module isn't active or has no permissions if this is the case, so continue as normal
        if (!$this->isExtensionActive('mod', $module)) {
            return;
        }

        // First check if any access is allowed to the module for this person
        if (!$staff_service->hasPermission(null, $module)) {
            http_response_code(403);
            $e = new \FOSSBilling\InformationException('You do not have permission to access the :mod: module', [':mod:' => $module], 403);
            if (!is_null($app)) {
                echo $app->render('error', ['exception' => $e]);
                exit;
            } else {
                throw $e;
            }
        }

        $module_permissions = $this->getSpecificModulePermissions($module);

        // If they have access, let's see if that module has a permission specifically for managing settings and check if they have that permission.
        if (array_key_exists('manage_settings', $module_permissions) && !$staff_service->hasPermission(null, $module, 'manage_settings')) {
            http_response_code(403);
            $e = new \FOSSBilling\InformationException('You do not have permission to perform this action', [], 403);
            if (!is_null($app)) {
                echo $app->render('error', ['exception' => $e]);
                exit;
            } else {
                throw $e;
            }
        }
    }
    
    public function isExtensionActive($type, $id)
    {
        if ($type == 'mod' && $this->isCoreModule($id)) {
            return true;
        }

        $query = "SELECT id
                FROM extension
                WHERE type = :type
                AND status = 'installed'
                AND name = :id
                LIMIT 1
               ";

        $id_or_null = $this->di['db']->getCell($query, ['type' => $type, 'id' => $id]);

        return (bool) $id_or_null;
    }
    
    public function isCoreModule($mod)
    {
        $core = $this->di['mod']('extension')->getCoreModules();

        return in_array($mod, $core);
    }
    
    public function getConfig($ext): array
    {
        return $this->di['cache']->get("config_$ext", function (ItemInterface $item) use ($ext) {
            $item->expiresAfter(60 * 60);

            $c = $this->di['db']->findOne('ExtensionMeta', 'extension = :ext AND meta_key = :key', [':ext' => $ext, ':key' => 'config']);
            if (is_null($c)) {
                $c = $this->di['db']->dispense('ExtensionMeta');
                $c->extension = $ext;
                $c->meta_key = 'config';
                $c->meta_value = null;
                $c->created_at = date('Y-m-d H:i:s');
                $c->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($c);
                $config = [];
            } else {
                //$config = $this->di['crypt']->decrypt($c->meta_value, $this->_getSalt());

                if (is_string($config) && json_validate($config)) {
                    $config = json_decode($config, true);
                } else {
                    $config = [];
                }
            }

            $config['ext'] = $ext;

            return $config;
        });
    }
    
    private function _getSalt()
    {
        return Config::getProperty('info.salt');
    }
    
    public function getSpecificModulePermissions(string $module, bool $buildingCompleteList = false): array|false
    {
        $class = 'Box\Mod\\' . ucfirst($module) . '\Service';
        if (class_exists($class) && method_exists($class, 'getModulePermissions')) {
            $moduleService = new $class();
            if (method_exists($moduleService, 'setDi')) {
                $moduleService->setDi($this->di);
            }
            $permissions = $moduleService->getModulePermissions();

            if (isset($permissions['hide_permissions']) && $permissions['hide_permissions']) {
                return $buildingCompleteList ? false : [];
            } else {
                unset($permissions['hide_permissions']);

                // Fill in the manage_settings permission as it will always be the same
                if (isset($permissions['manage_settings'])) {
                    $permissions['manage_settings'] = [
                        'type' => 'bool',
                        'display_name' => __trans('Manage settings'),
                        'description' => __trans('Allows the staff member to edit settings for this module.'),
                    ];
                }

                return $permissions;
            }
        }

        return [];
    }
    
}