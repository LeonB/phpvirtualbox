<?php
/**
 * Simple RDP connection file generator
 *
 * @author Ian Moore (imoore76 at yahoo dot com)
 * @copyright Copyright (C) 2010-2012 Ian Moore (imoore76 at yahoo dot com)
 * @version $Id$
 * @package phpVirtualBox
 *
 */

# Turn off PHP notices
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_WARNING);

require_once(dirname(__FILE__).'/lib/utils.php');
$_GET = clean_request();

foreach(array('port','host','vm') as $g) {
	@$_GET[$g] = str_replace(array("\n","\r","\0"),'',@$_GET[$g]);
}


/*
 * Check for port range or list of ports
 */
if(preg_match('/[^\d]/',@$_GET['port'])) {


	require_once(dirname(__FILE__).'/lib/config.php');
	require_once(dirname(__FILE__).'/lib/vboxconnector.php');

	global $_SESSION;
	session_init();

	$vbox = new vboxconnector();
	$vbox->connect();

	$args = array('vm'=>@$_GET['id']);
	$response = array();
	$vbox->machineGetDetails($args,array(&$response));
	$_GET['port'] = @$response['data']['consoleInfo']['consolePort'];

}

header("Content-type: application/x-rdp",true);
header("Content-disposition: attachment; filename=\"". str_replace(array('"','.'),'_',$_GET['vm']) .".rdp\"",true);


echo('
full address:s:'.@$_GET['host'].(@$_GET['port'] ? ':'.@$_GET['port'] : '').'
compression:i:1
displayconnectionbar:i:1
protocol:i:4
');
