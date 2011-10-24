<?php
/**
 * phpVirtualBox configuration class. Parses user configuration, applies
 * defaults, and sanitizes user values.
 * 
 * @author Ian Moore (imoore76 at yahoo dot com)
 * @copyright Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 * @version $Id$
 * @package phpVirtualBox
 * @see config.php-example
 * 
*/
class phpVBoxConfigClass {

	/* DEFAULTS */
	
	/**
	 * Default language
	 * @var string
	 */
	var $language = 'en_us';

	/**
	 * Preview screen width
	 * @var integer
	 */	
	var $previewWidth = 180;
	
	/**
	 * Aspect ratio of preview screen
	 * @var float
	 */
	var $previewAspectRatio = 1.6;

	/**
	 * Allow users to delete media when it is removed
	 * @var boolean
	 */
	var $deleteOnRemove = true;

	/**
	 * Restrict file / folder browsers to files ending in extensions found in this array
	 * @var array
	 */
	var $browserRestrictFiles = array('.iso','.vdi','.vmdk','.img','.bin','.vhd','.hdd','.ovf','.ova','.xml','.vbox','.cdr','.dmg','.ima','.dsk','.vfd');

	/**
	 * Force file / folder browser to use local PHP functions rather than VirtualBox's IVFSExplorer
	 * @var boolean
	 */
	var $browserLocal = false;
	
	/**
	 * List of console resolutions available on console tab
	 * @var array
	 */
	var $consoleResolutions = array('640x480','800x600','1024x768','1280x720','1440x900');
	
	/**
	 * Maximum number of NICs displayed per VM
	 * @var integer
	 */
	var $nicMax = 4;

	/**
	 * Refresh VM cache when opening a VM settings dialog
	 * @var boolean
	 */
	var $vmConfigRefresh = true;

	/**
	 * VM list sort order
	 * @var string
	 */
	var $vmListSort = 'name';
	
	/**
	 * Enable custom icon per VM
	 * @var boolean
	 */
	var $enableCustomIcons = false;

	/**
	 * Cache settings
	 * @var array
	 * @see vboxconnector
	 * @see cache
	 */
	var $cacheSettings = array(
		'hostGetDetails' => 86400, // "never" changes. 1 day
		'vboxGetGuestOSTypes' => 86400,
		'vboxSystemPropertiesGet' => 86400,
		'hostGetNetworking' => 86400,
		'vboxGetMedia' => 600, // 10 minutes
		'vboxGetMachines' => 2,
		'_machineGetDetails' => 7200, // 2 hours
		'_machineGetNetworkAdapters' => 7200,
		'_machineGetStorageControllers' => 7200,
		'_machineGetSerialPorts' => 7200,
		'_machineGetParallelPorts' => 7200,
		'_machineGetSharedFolders' => 7200,
		'_machineGetUSBController' => 7200,
		'vboxMachineSortOrderGet' => 300 // 5 minutes
	);

	/**
	 * true if there is no user supplied config.php found.
	 * @var boolean
	 */
	var $warnDefault = false;
	
	/**
	 * Key used to uniquely identify the current server in this
	 * instantiation of phpVBoxConfigClass.
	 * Set in __construct()
	 * @var string
	 */
	var $key = null;
	
	/**
	 * Auth library object instance. See lib/auth for classes.
	 * Set in __construct() based on authLib config setting value.
	 * @var phpvbAuth
	 */
	var $auth = null;
	
	/**
	 * Authentication capabilities provided by authentication module.
	 * Set in __construct
	 * @var phpvbAuthBuiltin::authCapabilities
	 */
	var $authCapabilities = null;
	
	/**
	 * Read user configuration, apply defaults, and do some sanity checking
	 * @see ajax
	 * @see vboxconnector
	 */
	function __construct() {
		
		@include_once(dirname(dirname(__FILE__)).'/config.php');
	
		/* Apply object vars of configuration class to this class */
		if(class_exists('phpVBoxConfig')) {
			$c = new phpVBoxConfig();
			foreach(get_object_vars($c) as $k => $v) {
				// Safety checks
				if($k == 'browserRestrictFiles' && !is_array($v)) continue;
				if($k == 'consoleResolutions' && !is_array($v)) continue;
				if($k == 'browserRestrictFolders' && !is_array($v)) continue;
				if($k == 'cacheSettings' && is_array($v)) {
					$this->cacheSettings = array_merge($this->cacheSettings,$v);
					continue;
				}
				$this->$k = $v;
			}
				
		/* User config.php does not exist. Send warning */
		} else {
			$this->warnDefault = true;
		}
			
		// Ignore any server settings if we have servers
		// in the servers array
		if(@is_array($this->servers) && @is_array($this->servers[0])) {
			unset($this->location);
			unset($this->user);
			unset($this->pass);
		}
		// Set to selected server based on browser cookie
		if(@$_COOKIE['vboxServer'] && @is_array($this->servers) && count($this->servers)) {
			foreach($this->servers as $s) {
				if($s['name'] == $_COOKIE['vboxServer']) {				
					foreach($s as $k=>$v) $this->$k = $v;
					break;
				}
			}
		// If servers is not an array, set to empty array
		} elseif(!@is_array($this->servers)) {
			$this->servers = array();
		}
		// We still have no server set, use the first one from
		// the servers array
		if(!@$this->location && @is_array($this->servers[0])) {
			foreach($this->servers[0] as $k=>$v) $this->$k = $v;
		}
		// Make sure name is set
		if(!isset($this->name) || !$this->name) {
			$this->name = parse_url($this->location);
			$this->name = $this->name['host'];
		}
		
		// Key used to uniquely identify this server in this
		// phpvirtualbox installation
		$this->setKey();
		
		// legacy rdpHost setting
		if(@$this->rdpHost && !@$this->consoleHost)
			$this->consoleHost = $this->rdpHost;
			
		// Ensure authlib is set
		if(!@$this->authLib) $this->authLib = 'Builtin';
		// include interface
		include_once(dirname(__FILE__).'/authinterface.php');
		include_once(dirname(__FILE__).'/auth/'.str_replace(array('.','/','\\'),'',$this->authLib).'.php');
		
		// Check for session functionality
		if(!function_exists('session_start')) $this->noAuth = true;
		
		$alib = "phpvbAuth{$this->authLib}";
		$this->auth = new $alib(@$this->authConfig);
		$this->authCapabilities = $this->auth->capabilities;
	}
	
	/**
	 * Set VirtualBox server to use
	 * @param string $server server from config.php $servers array
	 */
	function setServer($server) {
		foreach($this->servers as $s) {
			if($s['name'] == $server) {				
				foreach($s as $k=>$v) $this->$k = $v;
				$this->setKey();
				break;
			}
		}
	}
	
	/**
	 * Generate a key for current server settings and populate $this->key
	 */
	function setKey() {
		$this->key = md5($this->location.$this->username);
	}
	
	/**
	 * Return the name of the server marked as the authentication master
	 * @return string name of server marked as authMaster
	 */
	function getServerAuthMaster() {
		foreach($this->servers as $s) {
			if($s['authMaster']) {				
				return $s['name'];
			}
		}
		return @$this->servers[0]['name'];
	}

}



