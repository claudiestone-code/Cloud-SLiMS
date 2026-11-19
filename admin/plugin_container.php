<?php
/**
 * @Created by          : Waris Agung Widodo (ido.alit@gmail.com)
 * @Date                : 06/11/20 05.31
 * @File name           : plugin_container.php
 */

use \SLiMS\Plugins;

define('INDEX_AUTH', 1);
require_once __DIR__ . '/../sysconfig.inc.php';

// SECURITY: admin plugin pages are only for logged-in staff. Previously each
// plugin had to check this itself; a plugin that forgot was public.
// (Plugins may still include these files again; that is harmless.)
require_once LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';

if (!isset($_GET['mod']) && !isset($_GET['id'])) die('Plugin id not defined!');

$module = utility::filterData('mod', 'get', false, true, true);
$plugin_id = utility::filterData('id', 'get', false, true, true);

// check if plugin is enabled
$all_active_menu_plugin = Plugins::getInstance()->getMenus($module);
if (!isset($all_active_menu_plugin[$plugin_id])) die('Plugin not found / disabled!');
if (!$all_active_menu_plugin[$plugin_id][3]) die('File path is not found!');

// load autoload
Plugins::getInstance()->getAutoload($all_active_menu_plugin[$plugin_id][3]);

// load plugin
require_once $all_active_menu_plugin[$plugin_id][3];