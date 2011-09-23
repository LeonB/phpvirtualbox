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
	 * Cache tweaking settings
	 * @var array
	 * @see vboxconnector
	 * @see cache
	 */
	var $cacheSettings = array(
		'getHostDetails' => 86400, // "never" changes. 1 day
		'getGuestOSTypes' => 86400,
		'getSystemProperties' => 86400,
		'getHostNetworking' => 86400,
		'getMedia' => 600, // 10 minutes
		'getVMs' => 2,
		'__getMachine' => 7200, // 2 hours
		'__getNetworkAdapters' => 7200,
		'__getStorageControllers' => 7200,
		'__getSerialPorts' => 7200,
		'__getSharedFolders' => 7200,
		'__getUSBController' => 7200,
		'getVMSortOrder' => 300 // 5 minutes
	);

	/**
	 * true if there is no user supplied config.php found.
	 * @var boolean
	 */
	var $warnDefault = false;
	
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
		$this->key = md5($this->location.$this->username);
		
		// legacy rdpHost setting
		if(@$this->rdpHost && !@$this->consoleHost)
			$this->consoleHost = $this->rdpHost;
			
		// Ensure authlib is set
		if(!@$this->authLib) $this->authLib = 'Builtin';
		
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
				break;
			}
		}
	}
	
	/**
	 * Return the server configuration array marked as the authentication master
	 * @return array server configuration of server marked as authMaster
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



