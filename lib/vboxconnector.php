<?php
/**
 *
 * Connects to vboxwebsrv, calls SOAP methods, and returns data.
 *
 * @author Ian Moore (imoore76 at yahoo dot com)
 * @copyright Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 * @version $Id$
 * @package phpVirtualBox
 *
 */

class vboxconnector {

	/**
	 * Error number describing a fatal error
	 * @var integer
	 */
	const PHPVB_ERRNO_FATAL = 32;

	/**
	 * Error number describing a connection error
	 * @var integer
	 */
	const PHPVB_ERRNO_CONNECT = 64;

	/**
	 * Static VirtualBox result codes.
	 * @var array
	 */
	static $resultcodes = array(
		'0x80BB0001' => 'VBOX_E_OBJECT_NOT_FOUND',
		'0x80BB0002' => 'VBOX_E_INVALID_VM_STATE',
		'0x80BB0003' => 'VBOX_E_VM_ERROR',
		'0x80BB0004' => 'VBOX_E_FILE_ERROR',
		'0x80BB0005' => 'VBOX_E_IPRT_ERROR',
		'0x80BB0006' => 'VBOX_E_PDM_ERROR',
		'0x80BB0007' => 'VBOX_E_INVALID_OBJECT_STATE',
		'0x80BB0008' => 'VBOX_E_HOST_ERROR',
		'0x80BB0009' => 'VBOX_E_NOT_SUPPORTED',
		'0x80BB000A' => 'VBOX_E_XML_ERROR',
		'0x80BB000B' => 'VBOX_E_INVALID_SESSION_STATE',
		'0x80BB000C' => 'VBOX_E_OBJECT_IN_USE',
	    '0x80004004' => 'NS_ERROR_ABORT'
	);


	/**
	 * Holds any errors that occur during processing. Errors are placed in here
	 * when we want calling functions to be aware of the error, but do not want to
	 * halt processing
	 *
	 * @var array
	 */
	var $errors = array();


	/**
	 * true if a progress operation was creating during processing
	 *
	 * @var boolean
	 * @access private
	 * @see __destruct()
	 */
	var $progressCreated = false;

	/**
	 * Settings object
	 * @var phpVBoxConfigClass
	 * @see phpVBoxConfigClass
	 */
	var $settings = null;

	/**
	 * true if connected to vboxwebsrv
	 * @var boolean
	 */
	var $connected = false;

	/**
	 * Instance of cache object
	 * @see cache
	 * @var cache
	 */
	var $cache;

	/**
	 * IVirtualBox instance
	 * @var IVirtualBox
	 */
	var $vbox = null;

	/**
	 * VirtualBox web session manager
	 * @var IWebsessionManager
	 */
	var $websessionManager = null;

	/**
	 * Holds IWebsessionManager session object if created
	 * during processing so that it can be properly shutdown
	 * in __destruct
	 * @var ISession
	 * @see __destruct()
	 */
	var $session = null;

	/**
	 * Holds VirtualBox version information
	 * @var array
	 */
	var $version = null;

	/**
	 * If true, vboxconnector will not verify that there is a valid
	 * (PHP) session before connecting.
	 * @var boolean
	 */
	var $skipSessionCheck = false;

	/**
	 * Obtain configuration settings and set object vars
	 * @param boolean $useAuthMaster use the authentication master obtained from configuration class
	 * @see phpVBoxConfigClass
	 */
	public function __construct($useAuthMaster = false) {

		require_once(dirname(__FILE__).'/cache.php');
		require_once(dirname(__FILE__).'/language.php');
		require_once(dirname(__FILE__).'/vboxServiceWrappers.php');

		/* Set up.. .. settings */
		
		/** @var phpVBoxConfigClass */
		$this->settings = new phpVBoxConfigClass();

		// Are default settings being used?
		if(@$this->settings->warnDefault) {
			throw new Exception("No configuration found. Rename the file <b>config.php-example</b> in phpVirtualBox's folder to <b>config.php</b> and edit as needed.<p>For more detailed instructions, please see the installation wiki on phpVirtualBox's web site. <p><a href='http://code.google.com/p/phpvirtualbox/w/list' target=_blank>http://code.google.com/p/phpvirtualbox/w/list</a>.</p>",vboxconnector::PHPVB_ERRNO_FATAL);
		}

		// Check for SoapClient class
		if(!class_exists('SoapClient')) {
			throw new Exception('PHP does not have the SOAP extension enabled.',vboxconnector::PHPVB_ERRNO_FATAL);
		}

		// use authentication master server?
		if(@$useAuthMaster) {
			$this->settings->setServer($this->settings->getServerAuthMaster());
		}

		// Cache handler object.
		$this->cache = new cache();

		if(@$this->settings->cachePath) $this->cache->path = $this->settings->cachePath;

		# Using $Revision$ in the cache file prefix invalidates cache
		# files generated from previous versions of this file
		$this->cache->prefix = 'pvbx-'.md5($this->settings->key.'$Revision$').'-';

	}

	/**
	 * Connect to vboxwebsrv
	 * @see SoapClient
	 * @see phpVBoxConfigClass
	 * @return boolean true on success
	 */
	public function connect() {

		// Already connected?
		if(@$this->connected) return true;

		// Valid session?
		global $_SESSION;
		if(!@$this->skipSessionCheck && !$_SESSION['valid']) {
			throw new Exception(trans('Not logged in.','UIUsers'),vboxconnector::PHPVB_ERRNO_FATAL);
		}

		//Connect to webservice
		$this->client = new SoapClient(dirname(__FILE__)."/vboxwebService-4.1.wsdl",
		    array(
		    	'features' => (SOAP_USE_XSI_ARRAY_TYPE + SOAP_SINGLE_ELEMENT_ARRAYS),
		        'cache_wsdl'=>WSDL_CACHE_MEMORY,
		        'trace'=>(@$this->settings->debugSoap),
				'connection_timeout' => (@$this->settings->connectionTimeout ? $this->settings->connectionTimeout : 20),
		        'location'=>@$this->settings->location
		    ));


		/* Try / catch / throw here hides login credentials from exception if one is thrown */
		try {
			$this->websessionManager = new IWebsessionManager($this->client);
			$this->vbox = $this->websessionManager->logon($this->settings->username,$this->settings->password);
		} catch (Exception $e) {
			
			if(!($msg = $e->getMessage()))
				$msg = 'Error logging in to vboxwebsrv.';
			
			throw new Exception($msg,vboxconnector::PHPVB_ERRNO_CONNECT);
		}


		// Error logging in
		if(!$this->vbox->handle) {
			throw new Exception('Error logging in or connecting to vboxwebsrv.',vboxconnector::PHPVB_ERRNO_CONNECT);
		}

		return ($this->connected = true);

	}


	/**
	 * Get VirtualBox version
	 * @return array version information
	 */
	public function getVersion() {

		if(!@$this->version) {

			$this->connect();

			$this->version = explode('.',$this->vbox->version);
			$this->version = array(
				'ose'=>(stripos($this->version[2],'ose') > 0),
				'string'=>join('.',$this->version),
				'major'=>intval(array_shift($this->version)),
				'minor'=>intval(array_shift($this->version)),
				'sub'=>intval(array_shift($this->version)),
				'revision'=>(string)$this->vbox->revision,
				'settingsFilePath' => $this->vbox->settingsFilePath
			);
		}

		return $this->version;

	}

	/**
	 *
	 * Log out of vboxwebsrv unless a progress operation was created
	 * @see $progressCreated
	 */
	public function __destruct() {

		// Do not logout if there is a progress operation associated
		// with this vboxweb session
		if(@$this->connected && !$this->progressCreated && @$this->vbox->handle) {

			if(@$this->session && @$this->session->handle) {
				try {$this->session->unlockMachine();}
				catch (Exception $e) { }
			}

			$this->websessionManager->logoff($this->vbox->handle);
		}

		unset($this->client);
	}


	/**
	 * Call overloader. Handles caching of vboxwebsrv data for some incoming requests.
	 * Returns cached data or result of method call.
	 *
	 * @param string $fn method to call
	 * @param array $args arguments for method
	 * @throws Exception
	 * @return array
	 */
	function __call($fn,$args) {

		// Valid session?
		global $_SESSION;
		if(!@$this->skipSessionCheck && !$_SESSION['valid']) {
			throw new Exception(trans('Not logged in.','UIUsers'),vboxconnector::PHPVB_ERRNO_FATAL);
		}

		$req = &$args[0];
		$response = &$args[1][0]; # fix for allow_call_time_pass_reference = Off setting


		/*
		 * Special Cases First
		 *
		 */

		# Setting VM states
		if(strpos($fn,'setStateVM') === 0) {

			$this->__setVMState($req['vm'],substr($fn,10),$response);

		# Getting enumeration values
		} else if(strpos($fn,'getEnum') === 0) {

			$this->__getEnumerationMap(substr($fn,7),$response);

		# Access to other methods goes through caching
		# if the method methodName+'Cached' exists
		} elseif(method_exists($this,$fn.'Cached')) {

			// do not cache
			if(!@$this->settings->cacheSettings[$fn]) {

				$this->{$fn.'Cached'}($req,$response);

			// cached data exists ? return it : get data, cache data, return data
			} else if(@$req['force_refresh'] || (($response['data'] = $this->cache->get($fn,@$this->settings->cacheSettings[$fn])) === false)) {

				$lock = $this->cache->lock($fn);

				// file was modified while attempting to lock.
				// file data is returned
				if($lock === null) {
					$response['data'] = $this->cache->get($fn,@$this->settings->cacheSettings[$fn]);

				// lock obtained (hopefully)
				} else {
					$this->{$fn.'Cached'}($req,$response);
					if($this->cache->store($fn,$response['data']) === false && $response['data'] !== false) {
						throw new Exception("Error storing cache.");
					}
				}

			}

		// Not found
		} else {

			throw new Exception('Undefined method: ' . $fn ." - Clear your web browser's cache.");

		}

		return $response;
	}

	/**
	 *
	 * Enumerate guest properties of a vm
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean
	 */
	public function enumerateGuestProperties($args,&$response) {

		$this->connect();

		/* @var $m IMachine */
		$m = $this->vbox->findMachine($args['vm']);

		$response['data'] = $m->enumerateGuestProperties($args['pattern']);
		$m->releaseRemote();

		return true;

	}

	/**
	 * Uses VirtualBox's vfsexplorer to check if a file exists
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean
	 */
	public function fileExists($args,&$response) {

		$this->connect();

		$dsep = $this->getDsep();

		$path = str_replace($dsep.$dsep,$dsep,$args['file']);
		$dir = dirname($path);
		$file = basename($path);

		if(substr($dir,-1) != $dsep) $dir .= $dsep;

		/* @var $appl IAppliance */
		$appl = $this->vbox->createAppliance();


		/* @var $vfs IVFSExplorer */
		$vfs = $appl->createVFSExplorer('file://'.$dir);

		/* @var $progress IProgress */
		$progress = $vfs->update();
		$progress->waitForCompletion(-1);
		$progress->releaseRemote();

		$exists = $vfs->exists(array($file));

		$vfs->releaseRemote();
		$appl->releaseRemote();


		$response['data']['exists'] = count($exists);
		return true;
	}

	/**
	 * Save a vm's shared folder settings
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @param boolean $fromSaveVM set to true if called from saveVM method
	 */
	public function saveVMSharedFolders($args,&$response,$fromSaveVM=false) {

		if(!$fromSaveVM) {
			$this->connect();

			/* @var $machine IMachine */
			$machine = $this->vbox->findMachine($args['id']);
			$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
			$machine->lockMachine($this->session->handle, 'Shared');
		}

		// Compose incoming list
		$sf_inc = array();
		foreach($args['sharedFolders'] as $s) {
			$sf_inc[$s['name']] = $s;
		}


		// Get list of perm shared folders
		$psf_tmp = $this->session->machine->sharedFolders;
		$psf = array();
		foreach($psf_tmp as $sf) {
			$psf[$sf->name] = $sf;
		}

		// Get a list of temp shared folders
		$tsf_tmp = $this->session->console->sharedFolders;
		$tsf = array();
		foreach($tsf_tmp as $sf) {
			$tsf[$sf->name] = $sf;
		}

		/*
		 *  Step through list and remove non-matching folders
		 */
		foreach($sf_inc as $sf) {

			// Already exists in perm list. Check Settings.
			if($sf['type'] == 'machine' && $psf[$sf['name']]) {

				/* Remove if it doesn't match */
				if($sf['hostPath'] != $psf[$sf['name']]->hostPath || (bool)$sf['autoMount'] != (bool)$psf[$sf['name']]->autoMount || (bool)$sf['writable'] != (bool)$psf[$sf['name']]->writable) {

					$this->session->machine->removeSharedFolder($sf['name']);
					$this->session->machine->createSharedFolder($sf['name'],$sf['hostPath'],(bool)$sf['writable'],(bool)$sf['autoMount']);
				}

				unset($psf[$sf['name']]);

			// Already exists in perm list. Check Settings.
			} else if($sf['type'] != 'machine' && $tsf[$sf['name']]) {

				/* Remove if it doesn't match */
				if($sf['hostPath'] != $tsf[$sf['name']]->hostPath || (bool)$sf['autoMount'] != (bool)$tsf[$sf['name']]->autoMount || (bool)$sf['writable'] != (bool)$tsf[$sf['name']]->writable) {

					$this->session->console->removeSharedFolder($sf['name']);
					$this->session->console->createSharedFolder($sf['name'],$sf['hostPath'],(bool)$sf['writable'],(bool)$sf['autoMount']);

				}

				unset($tsf[$sf['name']]);

			} else {

				// Does not exist or was removed. Add it.
				if($sf['type'] != 'machine') $this->session->console->createSharedFolder($sf['name'],$sf['hostPath'],(bool)$sf['writable'],(bool)$sf['autoMount']);
				else $this->session->machine->createSharedFolder($sf['name'],$sf['hostPath'],(bool)$sf['writable'],(bool)$sf['autoMount']);
			}

		}

		/*
		 * Remove remaining
		 */
		foreach($psf as $sf) $this->session->machine->removeSharedFolder($sf->name);
		foreach($tsf as $sf) $this->session->console->removeSharedFolder($sf->name);

		// Expire shared folder info
		$this->cache->expire('__getSharedFolders'.$args['id']);

		if($fromSaveVM) return;

		$this->session->machine->saveSettings();
		$this->session->unlockMachine();
		$machine->releaseRemote();
		$this->session->releaseRemote();
		unset($this->session);

	}

	/**
	 * Install guest additions
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 */
	public function installGuestAdditions($args,&$response) {

		$this->connect();

		$response['data'] = array();
		$response['data']['errored'] = 0;

		/* @var $gem IMedium|null */
		$gem = null;
		foreach($this->vbox->DVDImages as $m) { /* @var $m IMedium */
			if(strtolower($m->name) == 'vboxguestadditions.iso') {
				$gem = $m;
				break;
			}
			$m->releaseRemote();
		}

		// Not in media registry. Try to register it.
		if(!$gem) {
			$checks = array(
				'linux' => '/usr/share/virtualbox/VBoxGuestAdditions.iso',
				'osx' => '/Applications/VirtualBox.app/Contents/MacOS/VBoxGuestAdditions.iso',
				'sunos' => '/opt/VirtualBox/additions/VBoxGuestAdditions.iso',
				'windows' => 'C:\\Program Files\\Oracle\\VirtualBox\\VBoxGuestAdditions.iso',
				'windowsx86' => 'C:\\Program Files (x86)\\Oracle\\VirtualBox\\VBoxGuestAdditions.iso' // Does this exist?
			);
			$hostos = $this->vbox->host->operatingSystem;
			if(stripos($hostos,'windows') !== false) {
				$checks = array($checks['windows'],$checks['windowsx86']);
			} elseif(stripos($hostos,'solaris') !== false || stripos($hostos,'sunos') !== false) {
				$checks = array($checks['sunos']);
			// not sure of uname returned on Mac. This should cover all of them 
			} elseif(stripos($hostos,'mac') !== false || stripos($hostos,'apple') !== false || stripos($hostos,'osx') !== false || stripos($hostos,'os x') !== false || stripos($hostos,'darwin') !== false) {
				$checks = array($checks['osx']);
			} elseif(stripos($hostos,'linux') !== false) {
				$checks = array($checks['linux']);
			}

			// Check for config setting
			if(@$this->settings->vboxGuestAdditionsISO)
				$checks = array($this->settings->vboxGuestAdditionsISO);

			// Unknown os and no config setting leaves all checks in place.
			// Try to register medium.
			foreach($checks as $iso) {
				try {
					$gem = $this->vbox->openMedium($iso,'DVD','ReadOnly');
					$this->cache->expire('getMedia');
					break;
				} catch (Exception $e) {
					// Ignore
				}
			}
			$response['data']['sources'] = $checks;
		}

		// No guest additions found
		if(!$gem) {
			$response['data']['result'] = 'noadditions';
			return;
		}

		// create session and lock machine
		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($args['vm']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');

		// Try update from guest if it is supported
		if(!@$args['mount_only']) {
			try {

				/* @var $progress IProgress */
				$progress = $this->session->console->guest->updateGuestAdditions($gem->location,'WaitForUpdateStartOnly');

				// No error info. Save progress.
				$gem->releaseRemote();
				$this->__storeProgress($progress);
				$response['data']['progress'] = $progress->handle;
				$this->cache->expire('__getStorageControllers'.$args['vm']);
				$this->cache->expire('getMedia');
				return true;

			} catch (Exception $e) {
				// Try to mount medium
				$response['data']['errored'] = 1;
			}
		}

		// updateGuestAdditions is not supported. Just try to mount image.
		$response['data']['result'] = 'nocdrom';
		$mounted = false;
		foreach($machine->storageControllers as $sc) { /* @var $sc IStorageController */
			foreach($machine->getMediumAttachmentsOfController($sc->name) as $ma) { /* @var $ma IMediumAttachment */
				if($ma->type->__toString() == 'DVD') {
					$this->session->machine->mountMedium($sc->name, $ma->port, $ma->device, $gem->handle, true);
					$response['data']['result'] = 'mounted';
					$this->cache->expire('__getStorageControllers'.$args['vm']);
					$this->cache->expire('getMedia');
					$mounted = true;
					break;
				}
			}
			$sc->releaseRemote();
			if($mounted) break;
		}


		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();
		$gem->releaseRemote();

	}

	/**
	 * Attach USB device identified by $args['id'] to a running VM
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 */
	public function usbAttachDevice($args,&$response) {

		$this->connect();

		// create session and lock machine
		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($args['vm']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');

		$this->session->console->attachUSBDevice($args['id']);

		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();

		$response['data']['result'] = 1;
	}

	/**
	 * Detach USB device identified by $args['id'] from a running VM
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 */
	public function usbDetachDevice($args,&$response) {

		$this->connect();

		// create session and lock machine
		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($args['vm']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');

		$this->session->console->detachUSBDevice($args['id']);

		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();

		$response['data']['result'] = 1;
	}


	/**
	 * Save a vm's network adapter settings
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @param boolean $fromSaveVM set to true if called from saveVM method
	 */
	public function saveVMNetwork($args,&$response,$fromSaveVMRunning = false) {

		if(!$fromSaveVMRunning) {

			$this->connect();

			// create session and lock machine
			/* @var $machine IMachine */
			$machine = $this->vbox->findMachine($args['id']);
			$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
			$machine->lockMachine($this->session->handle, 'Shared');
			$this->settings->enableAdvancedConfig = (@$this->settings->enableAdvancedConfig && @$args['clientConfig']['enableAdvancedConfig']);

		}

		// Network Adapters
		$netprops = array('enabled','attachmentType','bridgedInterface','hostOnlyInterface','internalNetwork','NATNetwork','cableConnected','promiscModePolicy','genericDriver');
		if(@$this->settings->enableVDE) $netprops[] = 'VDENetwork';

		for($i = 0; $i < count($args['networkAdapters']); $i++) {

			/* @var $n INetworkAdapter */
			$n = $this->session->machine->getNetworkAdapter($i);

			// Skip disabled adapters
			if(!$n->enabled) {
				$n->releaseRemote();
				continue;
			}

			for($p = 0; $p < count($netprops); $p++) {
				switch($netprops[$p]) {
					case 'enabled':
					case 'cableConnected':
						break;
					default:
						if((string)$n->{$netprops[$p]} != (string)$args['networkAdapters'][$i][$netprops[$p]])
							$n->{$netprops[$p]} = $args['networkAdapters'][$i][$netprops[$p]];
				}
			}

			// Network properties
			$eprops = $n->getProperties();
			$eprops = array_combine($eprops[1],$eprops[0]);
			$iprops = array_map(create_function('$a','$b=explode("=",$a); return array($b[0]=>$b[1]);'),preg_split('/[\r|\n]+/',$args['networkAdapters'][$i]['properties']));
			$inprops = array();
			foreach($iprops as $a) {
				foreach($a as $k=>$v)
				$inprops[$k] = $v;
			}
			
			// Remove any props that are in the existing properties array
			// but not in the incoming properties array
			foreach(array_diff(array_keys($eprops),array_keys($inprops)) as $dk)
				$n->setProperty($dk, '');
				
			// Set remaining properties
			foreach($inprops as $k => $v)
				$n->setProperty($k, $v);
				
			if(intval($n->cableConnected) != intval($args['networkAdapters'][$i]['cableConnected']))
				$n->cableConnected = intval($args['networkAdapters'][$i]['cableConnected']);

			if($args['networkAdapters'][$i]['attachmentType'] == 'NAT') {

				// Remove existing redirects
				foreach($n->natDriver->getRedirects() as $r) {
					$n->natDriver->removeRedirect(array_shift(explode(',',$r)));
				}
				// Add redirects
				foreach($args['networkAdapters'][$i]['redirects'] as $r) {
					$r = explode(',',$r);
					$n->natDriver->addRedirect($r[0],$r[1],$r[2],$r[3],$r[4],$r[5]);
				}

				// Advanced NAT settings
				if(@$this->settings->enableAdvancedConfig) {
					$aliasMode = $n->natDriver->aliasMode & 1;
					if(intval($args['networkAdapters'][$i]['natDriver']['aliasMode'] & 2)) $aliasMode |= 2;
					if(intval($args['networkAdapters'][$i]['natDriver']['aliasMode'] & 4)) $aliasMode |= 4;
					$n->natDriver->aliasMode = $aliasMode;
					$n->natDriver->dnsProxy = intval($args['networkAdapters'][$i]['natDriver']['dnsProxy']);
					$n->natDriver->dnsPassDomain = intval($args['networkAdapters'][$i]['natDriver']['dnsPassDomain']);
					$n->natDriver->dnsUseHostResolver = intval($args['networkAdapters'][$i]['natDriver']['dnsUseHostResolver']);
					$n->natDriver->hostIP = $args['networkAdapters'][$i]['natDriver']['hostIP'];
				}

			}
			$n->releaseRemote();
		}

		// Expire network info
		$this->cache->expire('__getNetworkAdapters'.$args['id']);
		$this->cache->expire('getHostNetworking');

		if($fromSaveVMRunning) return;

		$this->session->machine->saveSettings();
		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();
	}

	/**
	 * Clone a virtual machine
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 */
	public function cloneVM($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $src IMachine */
		$src = $this->vbox->findMachine($args['src']);

		if($args['snapshot'] && $args['snapshot']['id']) {
			/* @var $nsrc ISnapshot */
			$nsrc = $src->findSnapshot($args['snapshot']['id']);
			$src->releaseRemote();
			$src = null;
			$src = $nsrc->machine;
		}
		/* @var $m IMachine */
		$m = $this->vbox->createMachine($this->vbox->composeMachineFilename($args['name'],$this->vbox->systemProperties->defaultMachineFolder),$args['name'],null,null,false);
		$sfpath = $m->settingsFilePath;

		/* @var $cm CloneMode */
		$cm = new CloneMode(null,$args['vmState']);
		$state = $cm->ValueMap[$args['vmState']];

		$opts = array();
		if(!$args['reinitNetwork']) $opts[] = 'KeepAllMACs';
		if($args['link']) $opts[] = 'Link';

		/* @var $progress IProgress */
		$progress = $src->cloneTo($m->handle,$args['vmState'],$opts);

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$this->__storeProgress($progress,array('getMedia'));

		$response['data'] = array('progress' => $progress->handle, 'settingsFilePath' => $sfpath);


		return true;
	}


	/**
	 * Turn VRDE on / off on a running VM
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 */
	public function updateVRDE($args, &$response) {

		$this->connect();

		// create session and lock machine
		/* @var $m IMachine */
		$m = $this->vbox->findMachine($args['vm']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$m->lockMachine($this->session->handle, 'Shared');
		$this->session->machine->VRDEServer->enabled = intval($args['enabled']);

		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);

		$m->releaseRemote();

		$this->cache->expire('__consoleInfo'.$args['vm']);

	}

	/**
	 * Save running VM settings. Called from saveVM method if the requested VM is running.
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function saveVMRunning($args,&$response) {

		// Client and server must agree on advanced config setting
		$this->settings->enableAdvancedConfig = (@$this->settings->enableAdvancedConfig && @$args['clientConfig']['enableAdvancedConfig']);
		$this->settings->enableHDFlushConfig = (@$this->settings->enableHDFlushConfig && @$args['clientConfig']['enableHDFlushConfig']);

		// Shorthand
		/* @var $m IMachine */
		$m = &$this->session->machine;

		$m->CPUExecutionCap = intval($args['CPUExecutionCap']);
		$m->description = $args['description'];

		$m->setExtraData('GUI/SaveMountedAtRuntime', ($args['GUI']['SaveMountedAtRuntime'] == 'no' ? 'no' : 'yes'));

		// VRDE settings
		try {
			if($m->VRDEServer && $this->vbox->systemProperties->defaultVRDEExtPack) {
				$m->VRDEServer->enabled = intval($args['VRDEServer']['enabled']);
				$m->VRDEServer->setVRDEProperty('TCP/Ports',$args['VRDEServer']['ports']);
				$m->VRDEServer->authType = ($args['VRDEServer']['authType'] ? $args['VRDEServer']['authType'] : null);
				$m->VRDEServer->authTimeout = intval($args['VRDEServer']['authTimeout']);
			}
		} catch (Exception $e) {
		}

		$this->cache->expire('__getMachine'.$args['id']);

		// Storage Controllers
		$scs = $m->storageControllers;
		$attachedEx = $attachedNew = array();
		foreach($scs as $sc) { /* @var $sc IStorageController */
			$mas = $m->getMediumAttachmentsOfController($sc->name);
			foreach($mas as $ma) { /* @var $ma IMediumAttachment */
				$attachedEx[$sc->name.$ma->port.$ma->device] = (($ma->medium->handle && $ma->medium->id) ? $ma->medium->id : null);
			}
		}

		// Incoming list
		foreach($args['storageControllers'] as $sc) {

			$sc['name'] = trim($sc['name']);
			$name = ($sc['name'] ? $sc['name'] : $sc['bus'].' Controller');

			// Medium attachments
			foreach($sc['mediumAttachments'] as $ma) {

				if($ma['medium'] == 'null') $ma['medium'] = null;

				$attachedNew[$name.$ma['port'].$ma['device']] = $ma['medium']['id'];

				// Compare incoming list with existing
				if($ma['type'] != 'HardDisk' && $attachedNew[$name.$ma['port'].$ma['device']] != $attachedEx[$name.$ma['port'].$ma['device']]) {

					if(is_array($ma['medium']) && $ma['medium']['id'] && $ma['type']) {

						// Host drive
						if(strtolower($ma['medium']['hostDrive']) == 'true' || $ma['medium']['hostDrive'] === true) {
							// CD / DVD Drive
							if($ma['type'] == 'DVD') {
								$drives = $this->vbox->host->DVDDrives;
							// floppy drives
							} else {
								$drives = $this->vbox->host->floppyDrives;
							}
							foreach($drives as $md) {
								if($md->id == $ma['medium']['id']) {
									$med = &$md;
									break;
								}
								$md->releaseRemote();
							}
						} else {
							$med = $this->vbox->findMedium($ma['medium']['id'],$ma['type']);
						}
					} else {
						$med = null;
					}
					$m->mountMedium($name,$ma['port'],$ma['device'],(is_object($med) ? $med->handle : null),true);
					if(is_object($med)) $med->releaseRemote();
				}

				// Set Live CD/DVD
				if($ma['type'] == 'DVD') {
					if((strtolower($ma['medium']['hostDrive']) != 'true' && $ma['medium']['hostDrive'] !== true))
						$m->temporaryEjectDevice($name,$ma['port'],$ma['device'],(intval($ma['temporaryEject']) ? true : false));

				// Set IgnoreFlush
				} elseif($ma['type'] == 'HardDisk') {

					// Remove IgnoreFlush key?
					if($this->settings->enableHDFlushConfig) {

						$xtra = $this->__getIgnoreFlushKey($ma['port'], $ma['device'], $sc['controllerType']);

						if($xtra) {
							if(intval($ma['ignoreFlush']) == 0) {
								$m->setExtraData($xtra, '0');
							} else {
								$m->setExtraData($xtra, '');
							}
						}
					}


				}
			}

		}
		// Expire storage
		$this->cache->expire('__getStorageControllers'.$args['id']);

		// Expire media?
		ksort($attachedEx);
		ksort($attachedNew);
		if(serialize($attachedEx) != serialize($attachedNew))
			$this->cache->expire('getMedia');


		/* Networking */
		$this->saveVMNetwork($args,$null,true);

		/* Shared Folders */
		$this->saveVMSharedFolders($args,$null,true);

		/*
		 * USB Filters
		 */

		$usbchanged = false;
		$usbEx = array();
		$usbNew = array();

		$usbc = $this->__getCachedMachineData('__getUSBController',$args['id'],$this->session->machine);

		if($usbc['enabled']) {

			// filters
			if(!is_array($args['USBController']['deviceFilters'])) $args['USBController']['deviceFilters'] = array();
			if(count($usbc['deviceFilters']) != count($args['USBController']['deviceFilters']) || @serialize($usbc['deviceFilters']) != @serialize($args['USBController']['deviceFilters'])) {

				$usbchanged = true;

				// usb filter properties to change
				$usbProps = array('vendorId','productId','revision','manufacturer','product','serialNumber','port','remote');

				// Remove and Add filters
				try {


					$max = max(count($usbc['deviceFilters']),count($args['USBController']['deviceFilters']));
					$offset = 0;

					// Remove existing
					for($i = 0; $i < $max; $i++) {

						// Only if filter differs
						if(@serialize($usbc['deviceFilters'][$i]) != @serialize($args['USBController']['deviceFilters'][$i])) {

							// Remove existing?
							if($i < count($usbc['deviceFilters'])) {
								$m->USBController->removeDeviceFilter(($i-$offset));
								$offset++;
							}

							// Exists in new?
							if(count($args['USBController']['deviceFilters'][$i])) {

								// Create filter
								$f = $m->USBController->createDeviceFilter($args['USBController']['deviceFilters'][$i]['name']);
								$f->active = (bool)$args['USBController']['deviceFilters'][$i]['active'];

								foreach($usbProps as $p) {
									$f->$p = $args['USBController']['deviceFilters'][$i][$p];
								}

								$m->USBController->insertDeviceFilter($i,$f->handle);
								$f->releaseRemote();
								$offset--;
							}
						}

					}

				} catch (Exception $e) { $this->errors[] = $e; }

			}

			// Expire USB info?
			if($usbchanged) $this->cache->expire('__getUSBController'.$args['id']);
		}


		$this->session->machine->saveSettings();
		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$m->releaseRemote();

		$response['data']['result'] = 1;
		return true;

	}

	/**
	 * Save virtual machine settings.
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 */
	public function saveVM($args,&$response) {

		$this->connect();

		// create session and lock machine
		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($args['id']);
		$vmRunning = ($machine->state->__toString() == 'Running');
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, ($vmRunning ? 'Shared' : 'Write'));

		// Switch to saveVMRunning()?
		if($vmRunning) return $this->saveVMRunning($args,$response);



		// Client and server must agree on advanced config setting
		$this->settings->enableAdvancedConfig = (@$this->settings->enableAdvancedConfig && @$args['clientConfig']['enableAdvancedConfig']);
		$this->settings->enableHDFlushConfig = (@$this->settings->enableHDFlushConfig && @$args['clientConfig']['enableHDFlushConfig']);

		/* @var $expire array Cache items to expire after saving VM settings */
		$expire = array();

		// Shorthand
		/* @var $m IMachine */
		$m = &$this->session->machine;

		// General machine settings
		if (@$this->settings->enforceVMOwnership )
		{
			$args['name'] = "{$_SESSION['user']}_" . preg_replace('/^' . preg_quote($_SESSION['user']) . '_/', '', $args['name']);
		}

		if ( ($owner = $machine->getExtraData("phpvb/sso/owner")) && $owner !== $_SESSION['user'] && !$_SESSION['admin'] )
		{
			// skip this VM as it is not owned by the user we're logged in as
			throw new Exception("Not authorized to modify this VM");
		}

		$m->name = $args['name'];
		$m->OSTypeId = $args['OSTypeId'];
		$m->CPUCount = $args['CPUCount'];
		$m->memorySize = $args['memorySize'];
		$m->firmwareType = $args['firmwareType'];
		if($args['chipsetType']) $m->chipsetType = $args['chipsetType'];
		if($m->snapshotFolder != $args['snapshotFolder']) $m->snapshotFolder = $args['snapshotFolder'];
		$m->RTCUseUTC = ($args['RTCUseUTC'] ? 1 : 0);
		$m->setCpuProperty('PAE', ($args['CpuProperties']['PAE'] ? 1 : 0));
		// IOAPIC
		$m->BIOSSettings->IOAPICEnabled = ($args['BIOSSettings']['IOAPICEnabled'] ? 1 : 0);
		$m->CPUExecutionCap = intval($args['CPUExecutionCap']);
		$m->description = $args['description'];


		/* Only if advanced configuration is enabled */
		if(@$this->settings->enableAdvancedConfig) {

			/** @def VBOX_WITH_PAGE_SHARING
 			* Enables the page sharing code.
			* @remarks This must match GMMR0Init; currently we only support page fusion on
			 *          all 64-bit hosts except Mac OS X */
			$hostData = array();
			$this->getHostDetailsCached(array(),$hostData);
			if($hostData['data']['cpuFeatures']['Long Mode (64-bit)'] && stripos($hostData['data']['operatingSystem'],"darwin")===false) {
				try {
					$m->pageFusionEnabled = intval($args['pageFusionEnabled']);
				} catch (Exception $null) {
				}
			}

			$m->hpetEnabled = intval($args['hpetEnabled']);
			$m->setExtraData("VBoxInternal/Devices/VMMDev/0/Config/GetHostTimeDisabled", $args['disableHostTimeSync']);
			$m->keyboardHidType = $args['keyboardHidType'];
			$m->pointingHidType = $args['pointingHidType'];
			$m->setHWVirtExProperty('Enabled',(intval($args['HWVirtExProperties']['Enabled']) ? 1 : 0));
			$m->setHWVirtExProperty('NestedPaging', (intval($args['HWVirtExProperties']['NestedPaging']) ? 1 : 0));
			$m->setHWVirtExProperty('LargePages', (intval($args['HWVirtExProperties']['LargePages']) ? 1 : 0));
			$m->setHWVirtExProperty('Exclusive', (intval($args['HWVirtExProperties']['Exclusive']) ? 1 : 0));
			$m->setHWVirtExProperty('VPID', (intval($args['HWVirtExProperties']['VPID']) ? 1 : 0));

		}

		/* Custom Icon */
		if(@$this->settings->enableCustomIcons)
			$m->setExtraData('phpvb/icon', $args['customIcon']);

		$m->VRAMSize = $args['VRAMSize'];
		
		/* Unsupported at this time
		$m->monitorCount = max(1,intval($args['monitorCount']));
		$m->accelerate3DEnabled = $args['accelerate3DEnabled'];
		$m->accelerate2DVideoEnabled = $args['accelerate2DVideoEnabled'];
		*/


		$m->setExtraData('GUI/SaveMountedAtRuntime', ($args['GUI']['SaveMountedAtRuntime'] == 'no' ? 'no' : 'yes'));

		// VRDE settings
		try {
			if($m->VRDEServer && $this->vbox->systemProperties->defaultVRDEExtPack) {
				$m->VRDEServer->enabled = intval($args['VRDEServer']['enabled']);
				$m->VRDEServer->setVRDEProperty('TCP/Ports',$args['VRDEServer']['ports']);
				if(@$this->settings->enableAdvancedConfig)
					$m->VRDEServer->setVRDEProperty('TCP/Address',$args['VRDEServer']['netAddress']);
				$m->VRDEServer->authType = ($args['VRDEServer']['authType'] ? $args['VRDEServer']['authType'] : null);
				$m->VRDEServer->authTimeout = intval($args['VRDEServer']['authTimeout']);
				$m->VRDEServer->allowMultiConnection = intval($args['VRDEServer']['allowMultiConnection']);
			}
		} catch (Exception $e) {
		}

		// Audio controller settings
		$m->audioAdapter->enabled = ($args['audioAdapter']['enabled'] ? 1 : 0);
		$m->audioAdapter->audioController = $args['audioAdapter']['audioController'];
		$m->audioAdapter->audioDriver = $args['audioAdapter']['audioDriver'];

		// Boot order
		$mbp = $this->vbox->systemProperties->maxBootPosition;
		for($i = 0; $i < $mbp; $i ++) {
			if($args['bootOrder'][$i]) {
				$m->setBootOrder(($i + 1),$args['bootOrder'][$i]);
			} else {
				$m->setBootOrder(($i + 1),null);
			}
		}

		// Expire machine cache
		$expire[] = '__getMachine'.$args['id'];

		// Storage Controllers
		$scs = $m->storageControllers;
		$attachedEx = $attachedNew = array();
		foreach($scs as $sc) { /* @var $sc IStorageController */

			$mas = $m->getMediumAttachmentsOfController($sc->name);

			$cType = $sc->controllerType->__toString();

			foreach($mas as $ma) { /* @var $ma IMediumAttachment */

				$attachedEx[$sc->name.$ma->port.$ma->device] = (($ma->medium->handle && $ma->medium->id) ? $ma->medium->id : null);

				// Remove IgnoreFlush key?
				if($this->settings->enableHDFlushConfig && $ma->type->__toString() == 'HardDisk') {
					$xtra = $this->__getIgnoreFlushKey($ma->port, $ma->device, $cType);
					if($xtra) {
						$m->setExtraData($xtra,'');	
					}
				}

				if($ma->controller) {
					$m->detachDevice($ma->controller,$ma->port,$ma->device);
				}

			}
			$scname = $sc->name;
			$sc->releaseRemote();
			$m->removeStorageController($scname);
		}

		// Add New
		foreach($args['storageControllers'] as $sc) {

			$sc['name'] = trim($sc['name']);
			$name = ($sc['name'] ? $sc['name'] : $sc['bus'].' Controller');


			$bust = new StorageBus(null,$sc['bus']);
			$c = $m->addStorageController($name,$bust->__toString());
			$c->controllerType = $sc['controllerType'];
			$c->useHostIOCache = ($sc['useHostIOCache'] ? 1 : 0);

			// Set sata port count
			if($sc['bus'] == 'SATA') {
				$max = max(1,intval(@$sc['portCount']));
				foreach($sc['mediumAttachments'] as $ma) {
					$max = max($max,(intval($ma['port'])+1));
				}
				$c->portCount = min(intval($c->maxPortCount),max(count($sc['mediumAttachments']),$max));

				// Check for "automatic" setting
				/*
				 Disabled for now
				if(intval(@$sc['portCount']) == 0) $m->setExtraData('phpvb/AutoSATAPortCount','yes');
				else $m->setExtraData('phpvb/AutoSATAPortCount','no');
				*/
			}
			$c->releaseRemote();


			// Medium attachments
			foreach($sc['mediumAttachments'] as $ma) {

				if($ma['medium'] == 'null') $ma['medium'] = null;

				$attachedNew[$name.$ma['port'].$ma['device']] = $ma['medium']['id'];

				if(is_array($ma['medium']) && $ma['medium']['id'] && $ma['type']) {

					// Host drive
					if(strtolower($ma['medium']['hostDrive']) == 'true' || $ma['medium']['hostDrive'] === true) {
						// CD / DVD Drive
						if($ma['type'] == 'DVD') {
							$drives = $this->vbox->host->DVDDrives;
						// floppy drives
						} else {
							$drives = $this->vbox->host->floppyDrives;
						}
						foreach($drives as $md) { /* @var $md IMedium */
							if($md->id == $ma['medium']['id']) {
								$med = &$md;
								break;
							}
							$md->releaseRemote();
						}
					} else {
						/* @var $med IMedium */
						$med = $this->vbox->findMedium($ma['medium']['id'],$ma['type']);
					}
				} else {
					$med = null;
				}
				$m->attachDevice($name,$ma['port'],$ma['device'],$ma['type'],(is_object($med) ? $med->handle : null));

				// CD / DVD medium attachment type
				if($ma['type'] == 'DVD') {

					if((strtolower($ma['medium']['hostDrive']) == 'true' || $ma['medium']['hostDrive'] === true))
						$m->passthroughDevice($name,$ma['port'],$ma['device'],(intval($ma['passthrough']) ? true : false));
					else
						$m->temporaryEjectDevice($name,$ma['port'],$ma['device'],(intval($ma['temporaryEject']) ? true : false));

				// HardDisk medium attachment type
				} else if($ma['type'] == 'HardDisk') {

					$m->nonRotationalDevice($name,$ma['port'],$ma['device'],(intval($ma['nonRotational']) ? true : false));

					// Remove IgnoreFlush key?
					if($this->settings->enableHDFlushConfig) {

						$xtra = $this->__getIgnoreFlushKey($ma['port'], $ma['device'], $sc['controllerType']);

						if($xtra) {
							if(intval($ma['ignoreFlush']) == 0) {
								$m->setExtraData($xtra, 0);
							} else {
								$m->setExtraData($xtra, '');
							}
						}
					}


				}
				if(is_object($med)) $med->releaseRemote();
			}

		}
		// Expire storage
		$expire[] = '__getStorageControllers'.$args['id'];
		// Expire media?
		ksort($attachedEx);
		ksort($attachedNew);
		if(serialize($attachedEx) != serialize($attachedNew))
			$expire[] = 'getMedia';


		/*
		 *
		 * Network Adapters
		 *
		 */

		$netprops = array('enabled','attachmentType','adapterType','MACAddress','bridgedInterface','hostOnlyInterface','internalNetwork','NATNetwork','cableConnected','promiscModePolicy','genericDriver');
		if(@$this->settings->enableVDE) $netprops[] = 'VDENetwork';
		$adapters = $this->__getCachedMachineData('__getNetworkAdapters',$args['id'],$this->session->machine);

		for($i = 0; $i < count($args['networkAdapters']); $i++) {

			$n = $m->getNetworkAdapter($i);

			// Skip disabled adapters
			if(intval($n->enabled) + intval($args['networkAdapters'][$i]['enabled']) == 0) continue;

			for($p = 0; $p < count($netprops); $p++) {
				switch($netprops[$p]) {
					case 'enabled':
					case 'cableConnected':
						continue;
				}
				$n->{$netprops[$p]} = @$args['networkAdapters'][$i][$netprops[$p]];
			}

			// Special case for boolean values
			$n->enabled = intval($args['networkAdapters'][$i]['enabled']);
			$n->cableConnected = intval($args['networkAdapters'][$i]['cableConnected']);
			
			// Network properties
			$eprops = $n->getProperties();
			$eprops = array_combine($eprops[1],$eprops[0]);
			$iprops = array_map(create_function('$a','$b=explode("=",$a); return array($b[0]=>$b[1]);'),preg_split('/[\r|\n]+/',$args['networkAdapters'][$i]['properties']));
			$inprops = array();
			foreach($iprops as $a) {
				foreach($a as $k=>$v)
					$inprops[$k] = $v;
			}
			// Remove any props that are in the existing properties array
			// but not in the incoming properties array
			foreach(array_diff(array_keys($eprops),array_keys($inprops)) as $dk)
				$n->setProperty($dk, '');
			
			// Set remaining properties
			foreach($inprops as $k => $v)
				$n->setProperty($k, $v);
			
			if($args['networkAdapters'][$i]['attachmentType'] == 'NAT') {

				// Remove existing redirects
				foreach($n->natDriver->getRedirects() as $r) {
					$n->natDriver->removeRedirect(array_shift(explode(',',$r)));
				}
				// Add redirects
				foreach($args['networkAdapters'][$i]['redirects'] as $r) {
					$r = explode(',',$r);
					$n->natDriver->addRedirect($r[0],$r[1],$r[2],$r[3],$r[4],$r[5]);
				}

				// Advanced NAT settings
				if(@$this->settings->enableAdvancedConfig) {
					$aliasMode = $n->natDriver->aliasMode & 1;
					if(intval($args['networkAdapters'][$i]['natDriver']['aliasMode'] & 2)) $aliasMode |= 2;
					if(intval($args['networkAdapters'][$i]['natDriver']['aliasMode'] & 4)) $aliasMode |= 4;
					$n->natDriver->aliasMode = $aliasMode;
					$n->natDriver->dnsProxy = intval($args['networkAdapters'][$i]['natDriver']['dnsProxy']);
					$n->natDriver->dnsPassDomain = intval($args['networkAdapters'][$i]['natDriver']['dnsPassDomain']);
					$n->natDriver->dnsUseHostResolver = intval($args['networkAdapters'][$i]['natDriver']['dnsUseHostResolver']);
					$n->natDriver->hostIP = $args['networkAdapters'][$i]['natDriver']['hostIP'];
				}

			}
			$n->releaseRemote();
		}

		// Expire network info?
		$expire[] = '__getNetworkAdapters'.$args['id'];
		$expire[] = 'getHostNetworking';

		// Serial Ports
		$spChanged = false;
		for($i = 0; $i < count($args['serialPorts']); $i++) {

			/* @var $p ISerialPort */
			$p = $m->getSerialPort($i);

			if(!($p->enabled || intval($args['serialPorts'][$i]['enabled']))) continue;
			$spChanged = true;
			try {
				$p->enabled = intval($args['serialPorts'][$i]['enabled']);
				$p->IOBase = @hexdec($args['serialPorts'][$i]['IOBase']);
				$p->IRQ = intval($args['serialPorts'][$i]['IRQ']);
				if($args['serialPorts'][$i]['path']) {
					$p->path = $args['serialPorts'][$i]['path'];
					$p->hostMode = $args['serialPorts'][$i]['hostMode'];
				} else {
					$p->hostMode = $args['serialPorts'][$i]['hostMode'];
					$p->path = $args['serialPorts'][$i]['path'];
				}
				$p->server = intval($args['serialPorts'][$i]['server']);
				$p->releaseRemote();
			} catch (Exception $e) {
				$this->errors[] = $e;
			}
		}
		if($spChanged) $expire[] = '__getSerialPorts'.$args['id'];

		// LPT Ports
		if(@$this->settings->enableLPTConfig) {
			$lptChanged = false;

			for($i = 0; $i < count($args['parallelPorts']); $i++) {

				/* @var $p IParallelPort */
				$p = $m->getParallelPort($i);

				if(!($p->enabled || intval($args['parallelPorts'][$i]['enabled']))) continue;
				$lptChanged = true;
				try {
					$p->IOBase = @hexdec($args['parallelPorts'][$i]['IOBase']);
					$p->IRQ = intval($args['parallelPorts'][$i]['IRQ']);
					$p->path = $args['parallelPorts'][$i]['path'];
					$p->enabled = intval($args['parallelPorts'][$i]['enabled']);
					$p->releaseRemote();
				} catch (Exception $e) {
					$this->errors[] = $e;
				}
			}
			if($lptChanged) $expire[] = '__getParallelPorts'.$args['id'];
		}


		$sharedchanged = false;
		$sharedEx = array();
		$sharedNew = array();
		foreach($this->__getCachedMachineData('__getSharedFolders',$args['id'],$m) as $s) {
			$sharedEx[$s['name']] = array('name'=>$s['name'],'hostPath'=>$s['hostPath'],'autoMount'=>(bool)$s['autoMount'],'writable'=>(bool)$s['writable']);
		}
		foreach($args['sharedFolders'] as $s) {
			$sharedNew[$s['name']] = array('name'=>$s['name'],'hostPath'=>$s['hostPath'],'autoMount'=>(bool)$s['autoMount'],'writable'=>(bool)$s['writable']);
		}
		// Compare
		if(count($sharedEx) != count($sharedNew) || (@serialize($sharedEx) != @serialize($sharedNew))) {
			$sharedchanged = true;
			foreach($sharedEx as $s) { $m->removeSharedFolder($s['name']);}
			try {
				foreach($sharedNew as $s) {
					$m->createSharedFolder($s['name'],$s['hostPath'],(bool)$s['writable'],(bool)$s['autoMount']);
				}
			} catch (Exception $e) { $this->errors[] = $e; }
		}
		// Expire shared folders?
		if($sharedchanged) $expire[] = '__getSharedFolders'.$args['id'];

		// USB Filters

		$usbchanged = false;
		$usbEx = array();
		$usbNew = array();

		$usbc = $this->__getCachedMachineData('__getUSBController',$args['id'],$this->session->machine);

		// controller properties
		if((bool)$usbc['enabled'] != (bool)$args['USBController']['enabled'] || (bool)$usbc['enabledEhci'] != (bool)$args['USBController']['enabledEhci']) {
			$usbchanged = true;
			$m->USBController->enabled = (bool)$args['USBController']['enabled'];
			$m->USBController->enabledEhci = (bool)$args['USBController']['enabledEhci'];
		}

		// filters
		if(!is_array($args['USBController']['deviceFilters'])) $args['USBController']['deviceFilters'] = array();
		if(count($usbc['deviceFilters']) != count($args['USBController']['deviceFilters']) || @serialize($usbc['deviceFilters']) != @serialize($args['USBController']['deviceFilters'])) {

			$usbchanged = true;

			// usb filter properties to change
			$usbProps = array('vendorId','productId','revision','manufacturer','product','serialNumber','port','remote');

			// Remove and Add filters
			try {


				$max = max(count($usbc['deviceFilters']),count($args['USBController']['deviceFilters']));
				$offset = 0;

				// Remove existing
				for($i = 0; $i < $max; $i++) {

					// Only if filter differs
					if(@serialize($usbc['deviceFilters'][$i]) != @serialize($args['USBController']['deviceFilters'][$i])) {

						// Remove existing?
						if($i < count($usbc['deviceFilters'])) {
							$m->USBController->removeDeviceFilter(($i-$offset));
							$offset++;
						}

						// Exists in new?
						if(count($args['USBController']['deviceFilters'][$i])) {

							// Create filter
							$f = $m->USBController->createDeviceFilter($args['USBController']['deviceFilters'][$i]['name']);
							$f->active = (bool)$args['USBController']['deviceFilters'][$i]['active'];

							foreach($usbProps as $p) {
								$f->$p = $args['USBController']['deviceFilters'][$i][$p];
							}

							$m->USBController->insertDeviceFilter($i,$f->handle);
							$f->releaseRemote();
							$offset--;
						}
					}

				}

			} catch (Exception $e) { $this->errors[] = $e; }

		}

		// Expire USB info?
		if($usbchanged) $expire[] = '__getUSBController'.$args['id'];


		$this->session->machine->saveSettings();
		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();

		// Expire cache
		foreach(array_unique($expire) as $ex)
			$this->cache->expire($ex);

		$response['data']['result'] = 1;

		return true;
	}

	/**
	 * Add a virtual machine via its settings file.
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function addVM($args,&$response) {

		$this->connect();

		/* @var $m IMachine */
		$m = $this->vbox->openMachine($args['file']);
		$this->vbox->registerMachine($m->handle);

		$m->releaseRemote();

		$this->cache->expire(array('getVMs','getMedia','getHostNetworking'));

		return ($response['data']['result'] = 1);

	}

	/**
	 * Return cached VM configuration data. These are split into multiple cache files.
	 * E.g. network adapters, storage controllers, etc..
	 *
	 * @param string $fn function to call
	 * @param string $key key for cache item
	 * @param object $item item to obtain data from if data is not cached
	 * @param boolean $force_refresh force the refresh of cached data
	 * @return array data returned from call
	 */
	private function __getCachedMachineData($fn,$key,&$item,$force_refresh=false) {

		// do not cache
		if(!@$this->settings->cacheSettings[$fn] || !$key) {

			return $this->$fn($item);

		// Cached data exists?
		} else if(!$force_refresh && ($result = $this->cache->get($fn.$key,@$this->settings->cacheSettings[$fn])) !== false) {

			return $result;

		} else {

			$lock = $this->cache->lock($fn.$key);

			// file was modified while attempting to lock.
			// file data is returned
			if($lock === null) {

				return $this->cache->get($fn.$key,@$this->settings->cacheSettings[$fn]);

			// lock obtained
			} else {

				$result = $this->$fn($item);

				if($this->cache->store($fn.$key,$result) === false && $result !== false) {
					throw new Exception("Error storing cache.");
					return false;
				}

				return $result;

			}

		}


	}

	/**
	 * Get progress operation status. On completion, destory progress operation.
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 *
	 */
	public function getProgress($args,&$response) {

		$pop = $this->cache->get('ProgressOperations',false);

		if(!($pop = @$pop[$args['progress']])) {
			throw new Exception('Could not find progress operation: '.$args['progress']);
		}

		// progress operation result
		$result = 1;
		$error = 0;

		// Connect to vboxwebsrv
		$this->connect();

		try {

			try {

				// Keep session from timing out
				$vbox = new IVirtualBox($this->client, $pop['session']);
				$session = $this->websessionManager->getSessionObject($vbox->handle);

				// Force web call
				if($session->state->__toString()) {}

				/* @var $progress IProgress */
				$progress = new IProgress($this->client,$args['progress']);

			} catch (Exception $e) {
				$this->errors[] = $e;
				throw new Exception('Could not obtain progress operation: '.$args['progress']);
				$result = 0;
			}


			$response['data']['progress'] = $args['progress'];

			$response['data']['info'] = array(
				'completed' => $progress->completed,
				'canceled' => $progress->canceled,
				'description' => $progress->description,
				'operationDescription' => $progress->operationDescription,
				'timeRemaining' => $this->__splitTime($progress->timeRemaining),
				'timeElapsed' => $this->__splitTime((time() - $pop['started'])),
				'percent' => $progress->percent
				);


			// Completed? Do not return. Fall to __destroyProgress() called later
			if($response['data']['info']['completed'] || $response['data']['info']['canceled']) {

				try {
					if(!$response['data']['info']['canceled'] && $progress->errorInfo->handle) {
						$error = array('message'=>$progress->errorInfo->text,'err'=>$this->resultcodes['0x'.strtoupper(dechex($progress->resultCode))]);
					}
				} catch (Exception $null) {}


			} else {

				$response['data']['info']['cancelable'] = $progress->cancelable;

				return true;
			}


		} catch (Exception $e) {

			// Force progress dialog closure
			$response['data']['info'] = array('completed'=>1);

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$error = array('message'=>$progress->errorInfo->text,'err'=>$this->resultcodes['0x'.strtoupper(dechex($progress->resultCode))]);
				}
			} catch (Exception $null) {}

			// Some progress operations seem to go away after completion
			// probably the result of automatic session closure
			if(!($session->handle && $session->state->__toString() == 'Unlocked')) {
				$this->errors[] = $e;
				$result = 0;
			}

		}

		if($error) {
			$result = 0;
			if(@$args['catcherrs']) $response['data']['error'] = $error;
			else $this->errors[] = new Exception($error['message']);

		}

		$response['data']['result'] = $result;
		$this->__destroyProgress($pop);


	}

	/**
	 * Cancel a running progress operation
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function cancelProgress($args,&$response) {

		$pop = $this->cache->get('ProgressOperations',false);

		if(!($pop = @$pop[$args['progress']])) {
			throw new Exception('Could not obtain progress operation: '.$args['progress']);
		}

		// Connect to vboxwebsrv
		$this->connect();

		try {
			/* @var $progress IProgress */
			$progress = new IProgress($this->client,$args['progress']);
			if(!($progress->completed || $progress->canceled))
				$progress->cancel();
		} catch (Exception $e) {
			$this->errors[] = $e;
		}

		return ($response['data']['result'] = 1);
	}

	/**
	 * Destory a progress operation. Removing its cache and expiring any after-action
	 * cache items that should be expired.
	 *
	 * @param array $pop progress operation details
	 * @return boolean true on success
	 */
	private function __destroyProgress($pop) {

		// Expire cache item(s)?
		$this->cache->expire($pop['expire']);

		// Connect to vboxwebsrv
		$this->connect();

		try {
			/* @var $progress IProgress */
			$progress = new IProgress($this->client,$pop['progress']); $progress->releaseRemote();
		} catch (Exception $e) {}
		try {

			// Recreate vbox interface and close session
			$vbox = new IVirtualBox(null, $pop['session']);

			try {

				$session = $this->websessionManager->getSessionObject($vbox->handle);

				if($session->handle && $session->state->__toString() != 'Unlocked')
					$session->unlockMachine();

			} catch (Exception $null) { }


			// Logoff
			$this->websessionManager->logoff($vbox->handle);

		} catch (Exception $e) {
			$this->errors[] = $e;
		}

		// Remove progress reference from cache
		$this->cache->lock('ProgressOperations');
		$inprogress = $this->cache->get('ProgressOperations');
		if(!is_array($inprogress)) $inprogress = array();
		unset($inprogress[$pop['progress']]);
		$this->cache->store('ProgressOperations',$inprogress);

		return true;
	}

	/**
	 * Returns a key => value mapping of an enumeration class contained
	 * in vboxServiceWrappers.php (classes that extend VBox_Enum).
	 *
	 * @param string $class name of enumeration class
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 * @see vboxServiceWrappers.php
	 */
	private function __getEnumerationMap($class, &$response) {
		if(class_exists($class)) {
			$c = new $class;
			$response['data'] = $c->NameMap;
		}
	}

	/**
	 * Save VirtualBox system properties
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function saveSystemProperties($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$this->vbox->systemProperties->defaultMachineFolder = $args['SystemProperties']['defaultMachineFolder'];
		$this->vbox->systemProperties->VRDEAuthLibrary = $args['SystemProperties']['VRDEAuthLibrary'];

		$this->cache->expire('getSystemProperties');

		return ($response['data']['result'] = 1);

	}

	/**
	 * Import a virtual appliance
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function applianceImport($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $app IAppliance */
		$app = $this->vbox->createAppliance();

		/* @var $progress IProgress */
		$progress = $app->read($args['file']);

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$app->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$progress->waitForCompletion(-1);

		$app->interpret();

		$a = 0;
		foreach($app->virtualSystemDescriptions as $d) { /* @var $d IVirtualSystemDescription */
			// Replace with passed values
			$args['descriptions'][$a][5] = array_pad($args['descriptions'][$a][5], count($args['descriptions'][$a][3]),true);
			foreach(array_keys($args['descriptions'][$a][5]) as $k) $args['descriptions'][$a][5][$k] = (bool)$args['descriptions'][$a][5][$k];
			$d->setFinalValues($args['descriptions'][$a][5],$args['descriptions'][$a][3],$args['descriptions'][$a][4]);
			$a++;
		}

		/* @var $progress IProgress */
		$progress = $app->importMachines(array($args['reinitNetwork'] ? 'KeepNATMACs' : 'KeepAllMACs'));

		$app->releaseRemote();

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress,array('getMedia','getVMs','getHostNetworking'));

		$response['data']['progress'] = $progress->handle;

		return true;

	}

	/**
	 * Get a list of VMs that are available for export.
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getVMsExportable($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		//Get a list of registered machines
		$machines = $this->vbox->machines;

		foreach ($machines as $machine) { /* @var $machine IMachine */

			if ( @$this->settings->enforceVMOwnership && ($owner = $machine->getExtraData("phpvb/sso/owner")) && $owner !== $_SESSION['user'] && !$_SESSION['admin'] )
			{
				// skip this VM as it is not owned by the user we're logged in as
				continue;
			}

			try {
				$response['data'][] = array(
					'name' => @$this->settings->enforceVMOwnership ? preg_replace('/^' . preg_quote($_SESSION['user']) . '_/', '', $machine->name) : $machine->name,
					'state' => $machine->state->__toString(),
					'OSTypeId' => $machine->getOSTypeId(),
					'id' => $machine->id,
					'description' => $machine->description
				);
				$machine->releaseRemote();

			} catch (Exception $e) {
				// Ignore. Probably inaccessible machine.
			}
		}
		return true;
	}


	/**
	 * Read and interpret virtual appliance file
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function applianceReadInterpret($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $app IAppliance */
		$app = $this->vbox->createAppliance();

		/* @var $progress IProgress */
		$progress = $app->read($args['file']);

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$app->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$progress->waitForCompletion(-1);

		$app->interpret();

		$response['data']['warnings'] = $app->getWarnings();
		$response['data']['descriptions'] = array();
		$i = 0;
		foreach($app->virtualSystemDescriptions as $d) { /* @var $d IVirtualSystemDescription */
			$desc = array();
			$response['data']['descriptions'][$i] = $d->getDescription();
			foreach($response['data']['descriptions'][$i][0] as $ddesc) {
				$desc[] = $ddesc->__toString();
			}
			$response['data']['descriptions'][$i][0] = $desc;
			$i++;
			$d->releaseRemote();
		}
		$app->releaseRemote();
		$app=null;

		return ($response['data']['result'] = 1);

	}


	/**
	 * Export VMs to a virtual appliance file
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function applianceExport($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $app IAppliance */
		$app = $this->vbox->createAppliance();

		// Overwrite existing file?
		if($args['overwrite']) {

			$dsep = $this->getDsep();

			$path = str_replace($dsep.$dsep,$dsep,$args['file']);
			$dir = dirname($path);
			$file = basename($path);

			if(substr($dir,-1) != $dsep) $dir .= $dsep;

			/* @var $vfs IVFSExplorer */
			$vfs = $app->createVFSExplorer('file://'.$dir);

			/* @var $progress IProgress */
			$progress = $vfs->remove(array($file));
			$progress->waitForCompletion(-1);
			$progress->releaseRemote();

			$vfs->releaseRemote();
		}

		$appProps = array(
			'name' => 'Name',
			'description' => 'Description',
			'product' => 'Product',
			'vendor' => 'Vendor',
			'version' => 'Version',
			'product-url' => 'ProductUrl',
			'vendor-url' => 'VendorUrl',
			'license' => 'License');


		foreach($args['vms'] as $vm) {

			/* @var $m IMachine */
			$m = $this->vbox->findMachine($vm['id']);
			if (@$this->settings->enforceVMOwnership && ($owner = $m->getExtraData("phpvb/sso/owner")) && $owner !== $_SESSION['user'] && !$_SESSION['admin'] )
			{
				// skip this VM as it is not owned by the user we're logged in as
				continue;
			}
			$desc = $m->export($app->handle, $args['file']);
			$props = $desc->getDescription();
			$ptypes = array();
			foreach($props[0] as $p) {$ptypes[] = $p->__toString();}
			$typecount = 0;
			foreach($appProps as $k=>$v) {
				// Check for existing property
				if(($i=array_search($v,$ptypes)) !== false) {
					$props[3][$i] = $vm[$k];
				} else {
					$desc->addDescription($v,$vm[$k],null);
					$props[3][] = $vm[$k];
					$props[4][] = null;
				}
				$typecount++;
			}
			$enabled = array_pad(array(),count($props[3]),true);
			foreach(array_keys($enabled) as $k) $enabled[$k] = (bool)$enabled[$k];
			$desc->setFinalValues($enabled,$props[3],$props[4]);
			$desc->releaseRemote();
			$m->releaseRemote();
		}

		/* @var $progress IProgress */
		$progress = $app->write(($args['format'] ? $args['format'] : 'ovf-1.0'),($args['manifest'] ? true : false),$args['file']);
		$app->releaseRemote();

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress);

		$response['data']['progress'] = $progress->handle;

		return true;
	}

	/**
	 * Get host networking info
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	private function getHostNetworkingCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/*
		 * Existing Networks
		 */
		$networks = array();
		$vdenetworks = array();
		$nics = array();
		$genericDrivers = array();
		foreach($this->vbox->machines as $machine) { /* @var $machine IMachine */

			$mname = $machine->name;

			for($i = 0; $i < $this->settings->nicMax; $i++) {

				try {
					/* @var $h INetworkAdapter */
					$h = $machine->getNetworkAdapter($i);
				} catch (Exception $e) {
					break;
				}

				try {
					$at = $h->attachmentType->__toString();
					if($at == 'Bridged' || $at == 'HostOnly') {
						if($at == 'Bridged') $nic = $h->bridgedInterface;
						else $nic = $h->hostOnlyInterface;
						if(!is_array($nics[$nic])) $nics[$nic] = array();
						if(!is_array($nics[$nic][$mname])) $nics[$nic][$mname] = array();
						$nics[$nic][$mname][] = ($i+1);
					} else if ($at == 'Generic') {
						$genericDrivers[$h->genericDriver] = 1;
					} else {
						if($h->internalNetwork) {
							$networks[$h->internalNetwork] = 1;
						} else if(@$this->settings->enableVDE && @$h->VDENetwork) {
							$vdenetworks[$h->VDENetwork] = 1;
						}
					}
					$h->releaseRemote();
				} catch (Exception $e) {
					// Ignore
				}

			}
			$machine->releaseRemote();
		}
		$response['data']['nics'] = $nics;
		$response['data']['networks'] = array_keys($networks);
		$response['data']['vdenetworks'] = array_keys($vdenetworks);
		$response['data']['genericDrivers'] = array_keys($genericDrivers);

		return true;
	}

	/**
	 * Get host-only interface information
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	private function getHostOnlyNetworkingCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/*
		 * NICs
		 */
		$response['data']['networkInterfaces'] = array();
		foreach($this->vbox->host->networkInterfaces as $d) { /* @var $d IHostNetworkInterface */

			if($d->interfaceType->__toString() != 'HostOnly') {
				$d->releaseRemote();
				continue;
			}


			// Get DHCP Info
			try {
				/* @var $dhcp IDHCPServer */
				$dhcp = $this->vbox->findDHCPServerByNetworkName($d->networkName);
				if($dhcp->handle) {
					$dhcpserver = array(
						'enabled' => $dhcp->enabled,
						'IPAddress' => $dhcp->IPAddress,
						'networkMask' => $dhcp->networkMask,
						'networkName' => $dhcp->networkName,
						'lowerIP' => $dhcp->lowerIP,
						'upperIP' => $dhcp->upperIP
					);
					$dhcp->releaseRemote();
				} else {
					$dhcpserver = array();
				}
			} catch (Exception $e) {
				$dhcpserver = array();
			}

			$response['data']['networkInterfaces'][] = array(
				'id' => $d->id,
				'IPV6Supported' => $d->IPV6Supported,
				'name' => $d->name,
				'IPAddress' => $d->IPAddress,
				'networkMask' => $d->networkMask,
				'IPV6Address' => $d->IPV6Address,
				'IPV6NetworkMaskPrefixLength' => $d->IPV6NetworkMaskPrefixLength,
				'dhcpEnabled' => $d->dhcpEnabled,
				'networkName' => $d->networkName,
				'dhcpServer' => $dhcpserver
			);
			$d->releaseRemote();
		}

		return true;
	}


	/**
	 * Save host-only interface information
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function saveHostOnlyInterfaces($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$nics = $args['networkInterfaces'];

		for($i = 0; $i < count($nics); $i++) {

			/* @var $nic IHostNetworkInterface */
			$nic = $this->vbox->host->findHostNetworkInterfaceById($nics[$i]['id']);

			// Common settings
			if($nic->IPAddress != $nics[$i]['IPAddress'] || $nic->networkMask != $nics[$i]['networkMask']) {
				$nic->enableStaticIpConfig($nics[$i]['IPAddress'],$nics[$i]['networkMask']);
			}
			if($nics[$i]['IPV6Supported'] &&
				($nic->IPV6Address != $nics[$i]['IPV6Address'] || $nic->IPV6NetworkMaskPrefixLength != $nics[$i]['IPV6NetworkMaskPrefixLength'])) {
				$nic->enableStaticIpConfigV6($nics[$i]['IPV6Address'],intval($nics[$i]['IPV6NetworkMaskPrefixLength']));
			}

			// Get DHCP Info
			try {
				$dhcp = $this->vbox->findDHCPServerByNetworkName($nic->networkName);
			} catch (Exception $e) {$dhcp = null;};

			// Create DHCP server?
			if((bool)@$nics[$i]['dhcpServer']['enabled'] && !$dhcp) {
				$dhcp = $this->vbox->createDHCPServer($nic->networkName);
			}
			if($dhcp->handle) {
				$dhcp->enabled = (bool)@$nics[$i]['dhcpServer']['enabled'];
				$dhcp->setConfiguration($nics[$i]['dhcpServer']['IPAddress'],$nics[$i]['dhcpServer']['networkMask'],$nics[$i]['dhcpServer']['lowerIP'],$nics[$i]['dhcpServer']['upperIP']);
				$dhcp->releaseRemote();
			}
			$nic->releaseRemote();

		}

		return ($response['data']['result'] = 1);

	}

	/*
	 * Add Host-only interface
	 */
	public function createHostOnlyInterface($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $progress IProgress */
		list($int,$progress) = $this->vbox->host->createHostOnlyNetworkInterface();
		$int->releaseRemote();

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress,array('getHostDetails'));

		$response['data']['progress'] = $progress->handle;

		return true;

	}


	/**
	 * Remove a host-only interface
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function removeHostOnlyInterface($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $progress IProgress */
		$progress = $this->vbox->host->removeHostOnlyNetworkInterface($args['id']);

		if(!$progress->handle) return false;

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress,array('getHostDetails'));

		$response['data']['result'] = 1;
		$response['data']['progress'] = $progress->handle;

		return true;
	}

	/**
	 * Get a list of Guest OS Types supported by this VirtualBox installation
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	private function getGuestOSTypesCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$ts = $this->vbox->getGuestOSTypes();

		$supp64 = ($this->vbox->host->getProcessorFeature('LongMode') && $this->vbox->host->getProcessorFeature('HWVirtEx'));

		foreach($ts as $g) { /* @var $g IGuestOSType */

			// Avoid multiple calls
			$bit64 = $g->is64Bit;
			$response['data'][] = array(
				'familyId' => $g->familyId,
				'familyDescription' => $g->familyDescription,
				'id' => $g->id,
				'description' => $g->description,
				'is64Bit' => $bit64,
				'recommendedRAM' => $g->recommendedRAM,
				'recommendedHDD' => ($g->recommendedHDD/1024)/1024,
				'supported' => intval(!$bit64 || $supp64)
			);
		}

		return true;
	}

	/**
	 * Set virtual machine state. Running, power off, save state, pause, etc..
	 *
	 * @param string $vm virtual machine name or UUID
	 * $param string $state state to set the virtual machine to
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	private function __setVMState($vm, $state, &$response) {


		$states = array(
			'powerDown' => array('result'=>'PoweredOff','progress'=>2),
			'reset' => array(),
			'saveState' => array('result'=>'Saved','progress'=>2),
			'powerButton' => array('acpi'=>true),
			'sleepButton' => array('acpi'=>true),
			'pause' => array('result'=>'Paused','progress'=>false),
			'resume' => array('result'=>'Running','progress'=>false),
			'powerUp' => array('result'=>'Running'),
			'discardSavedState' => array('result'=>'poweredOff','lock'=>'shared','force'=>true)
		);

		// Check for valid state
		if(!is_array($states[$state])) {
			$response['data']['result'] = 0;
			throw new Exception('Invalid state: ' . $state);
		}

		// Connect to vboxwebsrv
		$this->connect();

		// Machine state
		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($vm);
		$mstate = $machine->state->__toString();

		if ( ($owner = $machine->getExtraData("phpvb/sso/owner")) && $owner !== $_SESSION['user'] && !$_SESSION['admin'] )
		{
			// skip this VM as it is not owned by the user we're logged in as
			throw new Exception("Not authorized to change state of this VM");
		}

		// If state has an expected result, check
		// that we are not already in it
		if($states[$state]['result']) {
			if($mstate == $states[$state]['result']) {
				$response['data']['result'] = 0;
				$machine->releaseRemote();
				throw new Exception('Machine is already in requested state.');
			}
		}

		// Special case for power up
		if($state == 'powerUp' && $mstate == 'Paused') {
			return $this->__setVMState($vm,'resume',$response);
		} else if($state == 'powerUp') {
			return $this->__launchVMProcess($machine,$response);
		}

		// Open session to machine
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);

		// Lock machine
		$machine->lockMachine($this->session->handle,($states[$state]['lock'] == 'write' ? 'Write' : 'Shared'));

		// If this operation returns a progress object save progress
		$progress = null;
		if($states[$state]['progress']) {

			/* @var $progress IProgress */
			$progress = $this->session->console->$state();

			if(!$progress->handle) {

				// should never get here
				try {
					$this->session->unlockMachine();
					$this->session = null;
				} catch (Exception $e) {};

				$response['data']['result'] = 0;
				$machine->releaseRemote();
				throw new Exception('Unknown error settings machine to requested state.');
			}

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					return false;
				}
			} catch (Exception $null) {}

			// Save progress
			$this->__storeProgress($progress,array('getVMs'));

			$response['data']['progress'] = $progress->handle;

		// Operation does not return a progress object
		// Just call the function
		} else {

			$this->session->console->$state(($states[$state]['force'] ? true : null));
			$this->cache->expire('getVMs');

		}

		$vmname = $machine->name;
		$machine->releaseRemote();

		// Check for ACPI button
		if($states[$state]['acpi'] && !$this->session->console->getPowerButtonHandled()) {
			$this->session->console->releaseRemote();
			$this->session->unlockMachine();
			$this->session = null;
			throw new Exception(str_replace('%1',$vmname,trans('Failed to send the ACPI Power Button press event to the virtual machine <b>%1</b>.','UIMessageCenter')));
		}


		if(!$progress->handle) {
			$this->session->console->releaseRemote();
			$this->session->unlockMachine();
			$this->session->releaseRemote();
			unset($this->session);
		}

		return ($response['data']['result'] = 1);

	}

	/**
	 * Start a stopped virtual machine
	 *
	 * @param IMachine $machine Virtual machine instance to start
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	private function __launchVMProcess(&$machine, &$response) {

		// Connect to vboxwebsrv
		$this->connect();

		# Try opening session for VM
		try {
			// create session
			$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);

			/* @var $progress IProgress */
			$progress = $machine->launchVMProcess($this->session->handle, 'headless', '');

		} catch (Exception $e) {
			// Error opening session
			$this->errors[] = $e;
			return ($response['data']['result'] = 0);
		}

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$this->__storeProgress($progress,array('getVMs'));

		$response['data']['progress'] = $progress->handle;

		return ($response['data']['result'] = 1);
	}

	/**
	 * Get VirtualBox host memory usage information
	 *
	 * @param unused $args
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getHostMeminfo($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$response['data'] = array(
			'memoryAvailable' => $this->vbox->host->memoryAvailable
		);

		return true;
	}

	/**
	 * Get VirtualBox host details
	 *
	 * @param unsed $args
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	private function getHostDetailsCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();


		/* @var $host IHost */
		$host = &$this->vbox->host;
		$response['data'] = array(
			'id' => 'host',
			'operatingSystem' => $host->operatingSystem,
			'OSVersion' => $host->OSVersion,
			'memorySize' => $host->memorySize,
			'cpus' => array(),
			'networkInterfaces' => array(),
			'DVDDrives' => array(),
			'floppyDrives' => array()
		);

		/*
		 * Processors
		 */
		for($i = 0; $i < $host->processorCount; $i++) {
			$response['data']['cpus'][$i] = $host->getProcessorDescription($i);
		}

		/*
		 * Supported CPU features?
		 */
		$response['data']['cpuFeatures'] = array();
		foreach(array('HWVirtEx'=>'HWVirtEx','PAE'=>'PAE','NestedPaging'=>'Nested Paging','LongMode'=>'Long Mode (64-bit)') as $k=>$v) {
			$response['data']['cpuFeatures'][$v] = intval($host->getProcessorFeature($k));
		}

		/*
		 * NICs
		 */
		foreach($host->networkInterfaces as $d) { /* @var $d IHostNetworkInterface */
			$response['data']['networkInterfaces'][] = array(
				'name' => $d->name,
				'IPAddress' => $d->IPAddress,
				'networkMask' => $d->networkMask,
				'IPV6Supported' => $d->IPV6Supported,
				'IPV6Address' => $d->IPV6Address,
				'IPV6NetworkMaskPrefixLength' => $d->IPV6NetworkMaskPrefixLength,
				'status' => $d->status->__toString(),
				'mediumType' => $d->mediumType->__toString(),
				'interfaceType' => $d->interfaceType->__toString(),
				'hardwareAddress' => $d->hardwareAddress,
				'networkName' => $d->networkName,
			);
			$d->releaseRemote();
		}

		/*
		 * Medium types (DVD and Floppy)
		 */
		foreach($host->DVDDrives as $d) { /* @var $d IMedium */

			$response['data']['DVDDrives'][] = array(
				'id' => $d->id,
				'name' => $d->name,
				'location' => $d->location,
				'description' => $d->description,
				'deviceType' => 'DVD',
				'hostDrive' => true,
			);
			$d->releaseRemote();
		}

		foreach($host->floppyDrives as $d) { /* @var $d IMedium */

			$response['data']['floppyDrives'][] = array(
				'id' => $d->id,
				'name' => $d->name,
				'location' => $d->location,
				'description' => $d->description,
				'deviceType' => 'Floppy',
				'hostDrive' => true,
			);
			$d->releaseRemote();
		}
		$host->releaseRemote();
		return true;
	}

	/**
	 * Get a list of USB devices attached to the VirtualBox host
	 *
	 * @param unused $args
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getHostUSBDevices($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		foreach($this->vbox->host->USBDevices as $d) { /* @var $d IUSBDevice */

			$response['data'][] = array(
				'id' => $d->id,
				'vendorId' => sprintf('%04s',dechex($d->vendorId)),
				'productId' => sprintf('%04s',dechex($d->productId)),
				'revision' => sprintf('%04s',dechex($d->revision)),
				'manufacturer' => $d->manufacturer,
				'product' => $d->product,
				'serialNumber' => $d->serialNumber,
				'address' => $d->address,
				'port' => $d->port,
				'version' => $d->version,
				'portVersion' => $d->portVersion,
				'remote' => $d->remote,
				'state' => $d->state->__toString(),
				);
			$d->releaseRemote();
		}

		return true;
	}


	/**
	 * Get virtual machine or virtualbox host details
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @param ISnapshot $snapshot snapshot instance to use if obtaining snapshot details.
	 * @see getHostDetails()
	 * @see getHostNetworking()
	 * @see __getCachedMachineData()
	 * @return boolean true on success
	 */
	public function getVMDetails($args, &$response, $snapshot=null) {

		// Host instead of vm info
		if($args['vm'] == 'host') {

			$response['data'] = array();
			$tmpstore = array('data'=>array());

			$this->getHostDetails($args, array(&$tmpstore));
			$response['data'] = array_merge($response['data'],$tmpstore['data']);

			$this->getHostNetworking($args, array(&$tmpstore));
			$response['data'] = array_merge($response['data'],$tmpstore['data']);

			return true;
		}


		// Connect to vboxwebsrv
		$this->connect();

		//Get registered machine or snapshot machine
		if($snapshot) {

			/* @var $machine ISnapshot */
			$machine = &$snapshot;

		} else {

			/* @var $machine IMachine */
			$machine = $this->vbox->findMachine($args['vm']);


			// For correct caching, always use id
			$args['vm'] = $machine->id;

			// Check for accessibility
			if(!$machine->accessible) {

				$response['data'] = array(
					'name' => $machine->name,
					'state' => 'Inaccessible',
					'OSTypeId' => 'Other',
					'id' => $machine->id,
					'sessionState' => 'Inaccessible',
					'accessible' => 0,
					'accessError' => array(
						'resultCode' => $this->resultcodes['0x'.strtoupper(dechex($machine->accessError->resultCode))],
						'component' => $machine->accessError->component,
						'text' => $machine->accessError->text)
				);

				return true;
			}

		}

		// Basic data
		$data = $this->__getCachedMachineData('__getMachine',@$args['vm'],$machine,@$args['force_refresh']);

		if (@$this->settings->enforceVMOwnership )
		{
			$data['name'] = preg_replace('/^' . preg_quote($_SESSION['user']) . '_/', '', $data['name']);
		}

		// Network Adapters
		$data['networkAdapters'] = $this->__getCachedMachineData('__getNetworkAdapters',@$args['vm'],$machine,@$args['force_refresh']);

		// Storage Controllers
		$data['storageControllers'] = $this->__getCachedMachineData('__getStorageControllers',@$args['vm'],$machine,@$args['force_refresh']);

		// Serial Ports
		$data['serialPorts'] = $this->__getCachedMachineData('__getSerialPorts',@$args['vm'],$machine,@$args['force_refresh']);

		// LPT Ports
		$data['parallelPorts'] = $this->__getCachedMachineData('__getParallelPorts',@$args['vm'],$machine,@$args['force_refresh']);

		// Shared Folders
		$data['sharedFolders'] = $this->__getCachedMachineData('__getSharedFolders',@$args['vm'],$machine,@$args['force_refresh']);


		// USB Filters
		$data['USBController'] = $this->__getCachedMachineData('__getUSBController',@$args['vm'],$machine,@$args['force_refresh']);

		// Non-cached items when not obtaining
		// snapshot machine info
		if(!$snapshot) {

			$data['state'] = $machine->state->__toString();
			$data['currentSnapshot'] = ($machine->currentSnapshot->handle ? array('id'=>$machine->currentSnapshot->id,'name'=>$machine->currentSnapshot->name) : null);
			$data['snapshotCount'] = $machine->snapshotCount;
			$data['sessionState'] = $machine->sessionState->__toString();
			$data['currentStateModified'] = $machine->currentStateModified;

			$mdlm = floor($machine->lastStateChange/1000);

			// Get current console port
			if($data['state'] == 'Running') {

				$console = $this->cache->get('__consoleInfo'.$args['vm'],120000);

				if($console === false || intval($console['lastStateChange']) < $mdlm || @$args['force_refresh']) {
					$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
					$machine->lockMachine($this->session->handle, 'Shared');
					$data['consoleInfo'] = array();
					$data['consoleInfo']['enabled'] = intval($this->session->console->machine->VRDEServer->enabled);
					$data['consoleInfo']['consolePort'] = $this->session->console->VRDEServerInfo->port;
					$this->session->unlockMachine();
					$this->session->releaseRemote();
					unset($this->session);
					$console = $data['consoleInfo'];
					$console['lastStateChange'] = $mdlm;
					$this->cache->store('__consoleInfo'.$data['id'],$console);
				} else {
					$data['consoleInfo'] = $console;
				}
			}

			// Get removable media
			if(!@$args['force_refresh']) {

				for($a = 0; $a < count($data['storageControllers']); $a++) {
					if(!is_array($data['storageControllers'][$a]['mediumAttachments'])) continue;
					$mas = null;
					for($b = 0; $b < count(@$data['storageControllers'][$a]['mediumAttachments']); $b++) {
						if($data['storageControllers'][$a]['mediumAttachments'][$b]['type'] != 'DVD') continue;
						if($data['storageControllers'][$a]['mediumAttachments'][$b]['temporaryEject']) continue;
						if(!$mas)
							$mas = $machine->getMediumAttachmentsOfController($data['storageControllers'][$a]['name']);

						// Find controller
						foreach($mas as $ma) {
							if($ma->port != $data['storageControllers'][$a]['mediumAttachments'][$b]['port'] || $ma->device != $data['storageControllers'][$a]['mediumAttachments'][$b]['device'])
								continue;
							$mid = ($ma->medium->handle ? $ma->medium->id : null);
							if($mid != @$data['storageControllers'][$a]['mediumAttachments'][$b]['medium']['id'])
								$data['refreshMedia'] = 1;

							$data['storageControllers'][$a]['mediumAttachments'][$b]['medium'] = ($mid ? array('id'=>$mid) : null);
						}
					}
				}

				// Media changed
				if($data['refreshMedia']) {
					$this->cache->expire('getMedia');
					$this->cache->expire('__getStorageControllers'.$args['vm']);
				}

			}
		}

		$machine->releaseRemote();

		$data['accessible'] = 1;
		$response['data'] = $data;

		return true;
	}

	/**
	 * Remove a virtual machine
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function removeVM($args, &$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($args['vm']);

		$cache = array('__consoleInfo'.$args['vm'],'__getMachine'.$args['vm'],'__getNetworkAdapters'.$args['vm'],'__getStorageControllers'.$args['vm'], 'getVMs',
			'__getSharedFolders'.$args['vm'],'__getUSBController'.$args['vm'],'getMedia','__getSerialPorts'.$args['vm'],'__getParallelPorts'.$args['vm'],'getHostNetworking');

		// Only unregister or delete?
		if(!$args['delete']) {

			$machine->unregister('Full');
			$machine->releaseRemote();

		} else {

			$hds = array();
			$delete = $machine->unregister('DetachAllReturnHardDisksOnly');
			foreach($delete as $hd) {
				$hds[] = $this->vbox->findMedium($hd->id,'HardDisk')->handle;
			}

			/* @var $progress IProgress */
			if(count($hds)) $progress = $machine->delete($hds);
			else $progress = null;

			$machine->releaseRemote();

			// Does an exception exist?
			if($progress) {
				try {
					if($progress->errorInfo->handle) {
						$this->errors[] = new Exception($progress->errorInfo->text);
						$progress->releaseRemote();
						return false;
					}
				} catch (Exception $null) {}

				$this->__storeProgress($progress,$cache);

				$response['data']['progress'] = $progress->handle;

				// return here. we got a progress operation
				return true;

			}


		}

		// expire cache items
		$this->cache->expire($cache);

		return ($response['data']['result'] = 1);


	}


	/**
	 * Create a new Virtual Machine
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function createVM($args, &$response) {

		global $_SESSION;

		// Connect to vboxwebsrv
		$this->connect();

		// quota enforcement
		if ( isset($_SESSION['user']) )
		{
			if ( @isset($this->settings->vmQuotaPerUser) && @$this->settings->vmQuotaPerUser > 0 && !$_SESSION['admin'] )
			{
				$newresp = array('data' => array());
				$vmlist = $this->getVMsCached(array(), $newresp);
				if ( count($newresp['data']['vmlist']) >= $this->settings->vmQuotaPerUser )
				{
					// we're over quota!
					// delete the disk we just created
					if ( isset($args['disk']) )
					{
						$this->mediumRemove(array(
								'id' => $args['disk'],
								'type' => 'HardDisk',
								'delete' => true
							), $newresp);
					}
					throw new Exception("Sorry, you're over quota. You can only create up to {$this->settings->vmQuotaPerUser} VMs.");
				}
			}
		}

		// create machine
		if (@$this->settings->enforceVMOwnership )
			$args['name'] = $_SESSION['user'] . '_' . $args['name'];

		/* @var $m IMachine */
		$m = $this->vbox->createMachine(null,$args['name'],$args['ostype'],null,null);

		// Set memory
		$m->memorySize = intval($args['memory']);


		// Save and register
		$m->saveSettings();
		$this->vbox->registerMachine($m->handle);
		$vm = $m->id;
		$m->releaseRemote();

		try {

			$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);

			// Lock VM
			/* @var $machine IMachine */
			$machine = $this->vbox->findMachine($vm);
			$machine->lockMachine($this->session->handle,'Write');

			// OS defaults
			$defaults = $this->vbox->getGuestOSType($args['ostype']);

			// Ownership enforcement
			if ( isset($_SESSION['user']) )
			{
				$this->session->machine->setExtraData('phpvb/sso/owner', $_SESSION['user']);
			}

			// Always set
			$this->session->machine->setExtraData('GUI/SaveMountedAtRuntime', 'yes');
			try {
				$this->session->machine->USBController->enabled = true;
				$this->session->machine->USBController->enabledEhci = true;
			} catch (Exception $e) {
				// Ignore
			}

			try {
				if($this->session->machine->VRDEServer && $this->vbox->systemProperties->defaultVRDEExtPack) {
					$this->session->machine->VRDEServer->enabled = 1;
					$this->session->machine->VRDEServer->authTimeout = 5000;
					$this->session->machine->VRDEServer->setVRDEProperty('TCP/Ports','3389-4000');
				}
			} catch (Exception $e) {
				//Ignore
			}

			// Other defaults
			$this->session->machine->BIOSSettings->IOAPICEnabled = $defaults->recommendedIOAPIC;
			$this->session->machine->RTCUseUTC = $defaults->recommendedRtcUseUtc;
			$this->session->machine->firmwareType = $defaults->recommendedFirmware->__toString();
			$this->session->machine->chipsetType = $defaults->recommendedChipset->__toString();
			if(intval($defaults->recommendedVRAM) > 0) $this->session->machine->VRAMSize = intval($defaults->recommendedVRAM);
			$this->session->machine->setCpuProperty('PAE',$defaults->recommendedPae);

			// USB input devices
			if($defaults->recommendedUsbHid) {
				$this->session->machine->pointingHidType = 'USBMouse';
				$this->session->machine->keyboardHidType = 'USBKeyboard';
			}

			/* Only if acceleration configuration is enabled */
			if(@$this->settings->enableAdvancedConfig) {
				$this->session->machine->setHWVirtExProperty('Enabled',$defaults->recommendedVirtEx);
			}

			/*
			 * Hard Disk and DVD/CD Drive
			 */
			$DVDbusType = $defaults->recommendedDvdStorageBus->__toString();
			$DVDconType = $defaults->recommendedDvdStorageController->__toString();

			// Attach harddisk?
			if($args['disk']) {

				$HDbusType = $defaults->recommendedHdStorageBus->__toString();
				$HDconType = $defaults->recommendedHdStorageController->__toString();

				$bus = new StorageBus(null,$HDbusType);
				$sc = $this->session->machine->addStorageController(trans($HDbusType.' Controller','UIMachineSettingsStorage'),$bus->__toString());
				$sc->controllerType = $HDconType;
				$sc->useHostIOCache = (bool)$this->vbox->systemProperties->getDefaultIoCacheSettingForStorageController($HDconType);
				$sc->releaseRemote();

				$m = $this->vbox->findMedium($args['disk'],'HardDisk');

				$this->session->machine->attachDevice(trans($HDbusType.' Controller','UIMachineSettingsStorage'),0,0,'HardDisk',$m->handle);

				$m->releaseRemote();


			}

			// Attach DVD/CDROM
			if($DVDbusType) {

				if(!$args['disk'] || ($HDbusType != $DVDbusType)) {

					$bus = new StorageBus(null,$DVDbusType);
					$sc = $this->session->machine->addStorageController(trans($DVDbusType.' Controller','UIMachineSettingsStorage'),$bus->__toString());
					$sc->controllerType = $DVDconType;
					$sc->useHostIOCache = (bool)$this->vbox->systemProperties->getDefaultIoCacheSettingForStorageController($DVDconType);
					$sc->releaseRemote();
				}

				$this->session->machine->attachDevice(trans($DVDbusType.' Controller','UIMachineSettingsStorage'),1,0,'DVD',null);

			}

			// Auto-sata port count
			/* Disabled for now
			$this->session->machine->setExtraData('phpvb/AutoSATAPortCount','yes');
			*/

			$this->session->machine->saveSettings();
			$this->session->unlockMachine();
			$this->session = null;

			if($args['disk']) $this->cache->expire('getMedia');

			$machine->releaseRemote();

		} catch (Exception $e) {
			$this->errors[] = $e;
			return false;
		}

		$this->cache->expire('getVMs');

		return ($response['data']['result'] = 1);

	}


	/**
	 * Return a list of network adapters attached to machine (or snapshot) $m
	 *
	 * @param IMachine|ISnapshot $m virtual machine or snapshot instance
	 * @return array of network adapter information
	 */
	private function __getNetworkAdapters(&$m) {

		$adapters = array();

		for($i = 0; $i < $this->settings->nicMax; $i++) {

			/* @var $n INetworkAdapter */
			$n = $m->getNetworkAdapter($i);

			// Avoid duplicate calls
			$at = $n->attachmentType->__toString();
			if($at == 'NAT') $nd = $n->natDriver; /* @var $nd INATEngine */
			else $nd = null;

			$props = $n->getProperties();
			$props = implode("\n",array_map(create_function('$a,$b','return "$a=$b";'),$props[1],$props[0]));
			 
			$adapters[] = array(
				'adapterType' => $n->adapterType->__toString(),
				'slot' => $n->slot,
				'enabled' => $n->enabled,
				'MACAddress' => $n->MACAddress,
				'attachmentType' => $at,
				'genericDriver' => $n->genericDriver,
				'hostOnlyInterface' => $n->hostOnlyInterface,
				'bridgedInterface' => $n->bridgedInterface,
				'properties' => $props,
				'internalNetwork' => $n->internalNetwork,
				'NATNetwork' => $n->NATNetwork,
				'promiscModePolicy' => $n->promiscModePolicy->__toString(),
				'VDENetwork' => ($this->settings->enableVDE ? $n->VDENetwork : ''),
				'cableConnected' => $n->cableConnected,
				'natDriver' => ($at == 'NAT' ?
					array('aliasMode' => intval($nd->aliasMode),'dnsPassDomain' => intval($nd->dnsPassDomain), 'dnsProxy' => intval($nd->dnsProxy), 'dnsUseHostResolver' => intval($nd->dnsUseHostResolver), 'hostIP' => $nd->hostIP)
					: array('aliasMode' => 0,'dnsPassDomain' => 0, 'dnsProxy' => 0, 'dnsUseHostResolver' => 0, 'hostIP' => '')),
				'lineSpeed' => $n->lineSpeed,
				'redirects' => (
					$at == 'NAT' ?
					$nd->getRedirects()
					: array()
				)
			);

			$n->releaseRemote();
		}

		return $adapters;

	}


	/**
	 * Set virtual machine sort order
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param unused $response
	 */
	public function setVMSortOrder($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		asort($args['sortOrder']);

		$sortOrder = join(',',array_flip($args['sortOrder']));

		$this->vbox->setExtraData("GUI/SelectorVMPositions", $sortOrder);

		$this->cache->expire('getVMSortOrder');
	}

	/**
	 * Return virtual machine sort order
	 *
	 * @param unused $args
	 * @param unused $response
	 */
	public function getVMSortOrderCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$response['data']['sortOrder'] = array_flip(array_filter(explode(',',$this->vbox->getExtraData("GUI/SelectorVMPositions"))));

	}

	/**
	 * Return a list of virtual machines along with their states and other basic info
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getVMsCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$response['data']['vmlist'] = array();

		// get sort order
		$so = array();
		$this->getVMSortOrder($args,array(&$so));
		$response['data']['sortOrder'] = $so['data']['sortOrder'];

		//Get a list of registered machines
		$machines = $this->vbox->machines;


		foreach ($machines as $machine) { /* @var $machine IMachine */


			try {
				$response['data']['vmlist'][] = array(
					'name' => @$this->settings->enforceVMOwnership ? preg_replace('/^' . preg_quote($_SESSION['user']) . '_/', '', $machine->name) : $machine->name,
					'state' => $machine->state->__toString(),
					'OSTypeId' => $machine->getOSTypeId(),
					'owner' => (@$this->settings->enforceVMOwnership ? $machine->getExtraData("phpvb/sso/owner") : ''),
					'id' => $machine->id,
					'lastStateChange' => floor($machine->lastStateChange/1000),
					'sessionState' => $machine->sessionState->__toString(),
					'currentSnapshot' => ($machine->currentSnapshot->handle ? $machine->currentSnapshot->name : ''),
					'customIcon' => (@$this->settings->enableCustomIcons ? $machine->getExtraData('phpvb/icon') : ''),
				);
				if($machine->currentSnapshot->handle) $machine->currentSnapshot->releaseRemote();

			} catch (Exception $e) {

				if($machine) {

					$response['data']['vmlist'][] = array(
						'name' => $machine->id,
						'state' => 'Inaccessible',
						'OSTypeId' => 'Other',
						'id' => $machine->id,
						'sessionState' => 'Inaccessible',
						'lastStateChange' => 0,
						'currentSnapshot' => ''
					);

				} else {
					$this->errors[] = $e;
				}
			}

			try {
				$machine->releaseRemote();
			} catch (Exception $e) { }
		}
		$response['data']['server_key'] = $this->settings->key;
		$response['data']['result'] = 1;
		return true;

	}

	/**
	 * Creates a new exception so that input can be debugged.
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function debugInput($args,&$response) {
		$this->errors[] = new Exception('debug');
		return ($response['data']['result'] = 1);
	}

	/**
	 * Get a list of media registered with VirtualBox
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	private function getMediaCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$response['data'] = array();
		$mds = array($this->vbox->hardDisks,$this->vbox->DVDImages,$this->vbox->floppyImages);
		for($i=0;$i<3;$i++) {
			foreach($mds[$i] as $m) {
				/* @var $m IMedium */
				$response['data'][] = $this->__getMedium($m);
				$m->releaseRemote();
			}
		}
		return true;
	}

	/**
	 * Get USB controller information
	 *
	 * @param IMachine $m virtual machine instance
	 * @return array USB controller info
	 */
	private function __getUSBController(&$m) {

		/* @var $u IUSBController */
		$u = &$m->USBController;

		$deviceFilters = array();
		foreach($u->deviceFilters as $df) { /* @var $df IUSBDeviceFilter */

			$deviceFilters[] = array(
				'name' => $df->name,
				'active' => intval($df->active),
				'vendorId' => $df->vendorId,
				'productId' => $df->productId,
				'revision' => $df->revision,
				'manufacturer' => $df->manufacturer,
				'product' => $df->product,
				'serialNumber' => $df->serialNumber,
				'port' => $df->port,
				'remote' => $df->remote
				);
			$df->releaseRemote();
		}
		$r = array(
			'enabled' => $u->enabled,
			'enabledEhci' => $u->enabledEhci,
			'deviceFilters' => $deviceFilters);
		$u->releaseRemote();
		return $r;
	}

	/**
	 * Return top-level virtual machine or snapshot information
	 *
	 * @param IMachine|ISnapshot $m virtual machine or snapshot instance
	 * @return array vm or snapshot data
	 */
	private function __getMachine(&$m) {

		return array(
			'name' => @$this->settings->enforceVMOwnership ? preg_replace('/^' . preg_quote($_SESSION['user']) . '_/', '', $m->name) : $m->name,
			'description' => $m->description,
			'id' => $m->id,
			'settingsFilePath' => $m->settingsFilePath,
			'OSTypeId' => $m->OSTypeId,
			'OSTypeDesc' => $this->vbox->getGuestOSType($m->OSTypeId)->description,
			'CPUCount' => $m->CPUCount,
			'hpetEnabled' => $m->hpetEnabled,
			'memorySize' => $m->memorySize,
			'VRAMSize' => $m->VRAMSize,
			'pointingHidType' => $m->pointingHidType->__toString(),
			'keyboardHidType' => $m->keyboardHidType->__toString(),
			'accelerate3DEnabled' => $m->accelerate3DEnabled,
			'accelerate2DVideoEnabled' => $m->accelerate2DVideoEnabled,
			'BIOSSettings' => array(
				'ACPIEnabled' => $m->BIOSSettings->ACPIEnabled,
				'IOAPICEnabled' => $m->BIOSSettings->IOAPICEnabled,
				'timeOffset' => $m->BIOSSettings->timeOffset
				),
			'firmwareType' => $m->firmwareType->__toString(),
			'snapshotFolder' => $m->snapshotFolder,
			'monitorCount' => $m->monitorCount,
			'pageFusionEnabled' => intval($m->pageFusionEnabled),
			'VRDEServer' => (!$m->VRDEServer ? null : array(
				'enabled' => $m->VRDEServer->enabled,
				'ports' => $m->VRDEServer->getVRDEProperty('TCP/Ports'),
				'netAddress' => $m->VRDEServer->getVRDEProperty('TCP/Address'),
				'authType' => $m->VRDEServer->authType->__toString(),
				'authTimeout' => $m->VRDEServer->authTimeout,
				'allowMultiConnection' => intval($m->VRDEServer->allowMultiConnection),
				'VRDEExtPack' => (string)$m->VRDEServer->VRDEExtPack
				)),
			'audioAdapter' => array(
				'enabled' => $m->audioAdapter->enabled,
				'audioController' => $m->audioAdapter->audioController->__toString(),
				'audioDriver' => $m->audioAdapter->audioDriver->__toString(),
				),
			'RTCUseUTC' => $m->RTCUseUTC,
			'HWVirtExProperties' => array(
				'Enabled' => $m->getHWVirtExProperty('Enabled'),
				'NestedPaging' => $m->getHWVirtExProperty('NestedPaging'),
				'LargePages' => $m->getHWVirtExProperty('LargePages'),
				'Exclusive' => $m->getHWVirtExProperty('Exclusive'),
				'VPID' => $m->getHWVirtExProperty('VPID')
				),
			'CpuProperties' => array(
				'PAE' => $m->getCpuProperty('PAE')
				),
			'bootOrder' => $this->__getBootOrder($m),
			'chipsetType' => $m->chipsetType->__toString(),
			'GUI' => array('SaveMountedAtRuntime' => $m->getExtraData('GUI/SaveMountedAtRuntime')),
			// Disabled for now 'AutoSATAPortCount' => $m->getExtraData('phpvb/AutoSATAPortCount'),
			'customIcon' => (@$this->settings->enableCustomIcons ? $m->getExtraData('phpvb/icon') : ''),
			'disableHostTimeSync' => intval($m->getExtraData("VBoxInternal/Devices/VMMDev/0/Config/GetHostTimeDisabled")),
			'CPUExecutionCap' => intval($m->CPUExecutionCap)
		);

	}

	/**
	 * Get virtual machine or snapshot boot order
	 *
	 * @param IMachine|ISnapshot $m virtual machine or snapshot instance
	 * @return array boot order
	 */
	private function __getBootOrder(&$m) {
		$return = array();
		$mbp = $this->vbox->systemProperties->maxBootPosition;
		for($i = 0; $i < $mbp; $i ++) {
			if(($b = $m->getBootOrder($i + 1)->__toString()) == 'Null') continue;
			$return[] = $b;
		}
		return $return;
	}

	/**
	 * Get serial port configuration for a virtual machine or snapshot
	 *
	 * @param IMachine|ISnapshot $m virtual machine or snapshot instance
	 * @return array serial port info
	 */
	private function __getSerialPorts(&$m) {
		$ports = array();
		$max = intval($this->vbox->systemProperties->serialPortCount);
		for($i = 0; $i < $max; $i++) {
			try {
				/* @var $p ISerialPort */
				$p = $m->getSerialPort($i);
				$ports[] = array(
					'slot' => $p->slot,
					'enabled' => intval($p->enabled),
					'IOBase' => '0x'.strtoupper(sprintf('%3s',dechex($p->IOBase))),
					'IRQ' => $p->IRQ,
					'hostMode' => $p->hostMode->__toString(),
					'server' => intval($p->server),
					'path' => $p->path
				);
				$p->releaseRemote();
			} catch (Exception $e) {
				// Ignore
			}
		}
		return $ports;
	}

	/**
	 * Get parallel port configuration for a virtual machine or snapshot
	 *
	 * @param IMachine|ISnapshot $m virtual machine or snapshot instance
	 * @return array parallel port info
	 */
	private function __getParallelPorts(&$m) {
		if(!@$this->settings->enableLPTConfig) return array();
		$ports = array();
		$max = intval($this->vbox->systemProperties->parallelPortCount);
		for($i = 0; $i < $max; $i++) {
			try {
				/* @var $p IParallelPort */
				$p = $m->getParallelPort($i);
				$ports[] = array(
					'slot' => $p->slot,
					'enabled' => intval($p->enabled),
					'IOBase' => '0x'.strtoupper(sprintf('%3s',dechex($p->IOBase))),
					'IRQ' => $p->IRQ,
					'path' => $p->path
				);
				$p->releaseRemote();
			} catch (Exception $e) {
				// Ignore
			}
		}
		return $ports;
	}

	/**
	 * Get shared folder configuration for a virtual machine or snapshot
	 *
	 * @param IMachine|ISnapshot $m virtual machine or snapshot instance
	 * @return array shared folder info
	 */
	private function __getSharedFolders(&$m) {
		$sfs = &$m->sharedFolders;
		$return = array();
		foreach($sfs as $sf) { /* @var $sf ISharedFolder */
			$return[] = array(
				'name' => $sf->name,
				'hostPath' => $sf->hostPath,
				'accessible' => $sf->accessible,
				'writable' => $sf->writable,
				'autoMount' => $sf->autoMount,
				'lastAccessError' => $sf->lastAccessError,
				'type' => 'machine'
			);
		}
		return $return;
	}


	/**
	 * Get a list of transient (temporary) shared folders
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getVMTransientSharedFolders($args,&$response) {

		$this->connect();

		$response['data'] = array();

		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($args['vm']);

		// No need to continue if machine is not running
		if($machine->state->__toString() != 'Running') {
			$machine->releaseRemote();
			return true;
		}

		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle,'Shared');

		$sfs = $this->session->console->sharedFolders;

		foreach($sfs as $sf) { /* @var $sf ISharedFolder */

			$response['data'][] = array(
				'name' => $sf->name,
				'hostPath' => $sf->hostPath,
				'accessible' => $sf->accessible,
				'writable' => $sf->writable,
				'autoMount' => $sf->autoMount,
				'lastAccessError' => $sf->lastAccessError,
				'type' => 'transient'
			);
		}

		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();

		return true;
	}
	
	/**
	 * Get OS specific directory separator
	 * 
	 * @return string directory separator string
	 */
	public function getDsep() {

		$this->connect();
		
		if(stripos($this->vbox->host->operatingSystem,'windows') === false)
			return '/';
		
		return '\\';
		
	}

	/**
	 * Get medium attachment information for all medium attachments in $mas
	 *
	 * @param array $mas list of IMediumAttachment instances
	 * @return array medium attachment info
	 */
	private function __getMediumAttachments(&$mas) {

		$return = array();

		foreach($mas as $ma) { /** @var $ma IMediumAttachment */
			$return[] = array(
				'medium' => ($ma->medium->handle ? array('id'=>$ma->medium->id) : null),
				'controller' => $ma->controller,
				'port' => $ma->port,
				'device' => $ma->device,
				'type' => $ma->type->__toString(),
				'passthrough' => intval($ma->passthrough),
				'temporaryEject' => intval($ma->temporaryEject),
				'nonRotational' => intval($ma->nonRotational)
			);
		}

		usort($return,create_function('$a,$b', 'if($a["port"] == $b["port"]) { if($a["device"] < $b["device"]) { return -1; } if($a["device"] > $b["device"]) { return 1; } return 0; } if($a["port"] < $b["port"]) { return -1; } return 1;'));
		
		return $return;
	}

	/**
	 * Save snapshot details ( description or name)
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function saveSnapshot($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $vm IMachine */
		$vm = $this->vbox->findMachine($args['vm']);

		/* @var $snapshot ISnapshot */
		$snapshot = $vm->findSnapshot($args['snapshot']);
		$snapshot->name = $args['name'];
		$snapshot->description = $args['description'];

		// cleanup
		$snapshot->releaseRemote();
		$vm->releaseRemote();

		return ($response['data']['result'] = 1);
	}

	/**
	 * Get snapshot details
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getSnapshotDetails($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $vm IMachine */
		$vm = $this->vbox->findMachine($args['vm']);

		/* @var $snapshot ISnapshot */
		$snapshot = $vm->findSnapshot($args['snapshot']);

		$machine = array();
		$this->getVMDetails(array(),$machine,$snapshot->machine);

		$response['data'] = $this->__getSnapshot($snapshot,false);
		$response['data']['machine'] = $machine['data'];

		// cleanup
		$snapshot->releaseRemote();
		$vm->releaseRemote();

		return ($response['data']['result'] = 1);

	}

	/**
	 * Restore a snapshot
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function snapshotRestore($args, &$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$progress = $this->session = null;

		try {

			// Open session to machine
			$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);

			/* @var $machine IMachine */
			$machine = $this->vbox->findMachine($args['vm']);
			$machine->lockMachine($this->session->handle,'Write');

			/* @var $snapshot ISnapshot */
			$snapshot = $this->session->machine->findSnapshot($args['snapshot']);

			/* @var $progress IProgress */
			$progress = $this->session->console->restoreSnapshot($snapshot->handle);

			$snapshot->releaseRemote();
			$machine->releaseRemote();

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,array('getVMs','__getMachine'.$args['vm'],'getMedia','__getStorageControllers'.$args['vm']));

		} catch (Exception $e) {

			$this->errors[] = $e;

			if($this->session->handle) {
				try{$this->session->unlockMachine();}catch(Exception $e){}
			}
			return ($response['data']['result'] = 0);
		}

		$response['data']['progress'] = $progress->handle;

		return ($response['data']['result'] = 1);

	}

	/**
	 * Delete a snapshot
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function snapshotDelete($args, &$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$progress = $this->session = null;

		try {

			// Open session to machine
			$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);

			/* @var $machine IMachine */
			$machine = $this->vbox->findMachine($args['vm']);
			$machine->lockMachine($this->session->handle, 'Shared');

			/* @var $progress IProgress */
			$progress = $this->session->console->deleteSnapshot($args['snapshot']);

			$machine->releaseRemote();

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,array('getVMs','__getMachine'.$args['vm'],'getMedia','__getStorageControllers'.$args['vm']));


		} catch (Exception $e) {

			$this->errors[] = $e;

			if($this->session->handle) {
				try{$this->session->unlockMachine();$this->session=null;}catch(Exception $e){}
			}

			$response['data']['result'] = 0;
			return;
		}

		$response['data']['progress'] = $progress->handle;
		return ($response['data']['result'] = 1);
	}

	/**
	 * Take a snapshot
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function snapshotTake($args, &$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($args['vm']);

		$progress = $this->session = null;

		try {

			// Open session to machine
			$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
			$machine->lockMachine($this->session->handle, ($machine->sessionState->__toString() == 'Unlocked' ? 'Write' : 'Shared'));
			$machine->releaseRemote();

			/* @var $progress IProgress */
			$progress = $this->session->console->takeSnapshot($args['name'],$args['description']);

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					try{$this->session->unlockMachine(); $this->session=null;}catch(Exception $ed){}
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,array('getVMs','__getMachine'.$args['vm'],'getMedia','__getStorageControllers'.$args['vm']));

		} catch (Exception $e) {

			$this->errors[] = $e;

			$response['data']['error'][] = $e->getMessage();
			$response['data']['result'] = 0;

			if(!$progress->handle && $this->session->handle) {
				try{$this->session->unlockMachine();$this->session=null;}catch(Exception $e){}
			}


			return;
		}

		$response['data']['progress'] = $progress->handle;
		return ($response['data']['result'] = 1);
	}

	/**
	 * Get a list of snapshots for a machine
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getSnapshots($args, &$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($args['vm']);

		/* No snapshots? Empty array */
		if($machine->snapshotCount < 1) {
			$response['data'] = array();
		} else {

			/* @var $s ISnapshot */
			$s = $machine->findSnapshot(null);
			$response['data'] = $this->__getSnapshot($s,true);
			$s->releaseRemote();
		}

		$machine->releaseRemote();

		return true;
	}


	/**
	 * Return details about snapshot $s
	 *
	 * @param ISnapshot $s snapshot instance
	 * @param boolean $sninfo traverse child snapshots and include machine id
	 * @return array snapshot info
	 */
	private function __getSnapshot(&$s,$sninfo=false) {

		$children = array();

		if($sninfo)
			foreach($s->children as $c) { /* @var $c ISnapshot */
				$children[] = $this->__getSnapshot($c, true);
				$c->releaseRemote();
			}

		// Avoid multiple soap calls
		$timestamp = (string)$s->timeStamp;

		return array(
			'id' => $s->id,
			'name' => $s->name,
			'description' => $s->description,
			'timeStamp' => floor($timestamp/1000),
			'timeStampSplit' => $this->__splitTime(time() - floor($timestamp/1000)),
			'online' => $s->online,
			'machine' => ($sninfo ? $s->machine->id : null)
		) + (
			($sninfo ? array('children' => $children) : array())
		);
	}

	/**
	 * Return details about storage controllers for machine $m
	 *
	 * @param IMachine|ISnapshot $m virtual machine or snapshot instance
	 * @return array storage controllers' details
	 */
	private function __getStorageControllers(&$m) {

		$sc = array();
		$scs = $m->storageControllers;

		foreach($scs as $c) { /* @var $c IStorageController */
			$sc[] = array(
				'name' => $c->name,
				'maxDevicesPerPortCount' => $c->maxDevicesPerPortCount,
				'useHostIOCache' => $c->useHostIOCache,
				'minPortCount' => $c->minPortCount,
				'maxPortCount' => $c->maxPortCount,
				'instance' => $c->instance,
				'portCount' => $c->portCount,
				'bus' => $c->bus->__toString(),
				'controllerType' => $c->controllerType->__toString(),
				'mediumAttachments' => $this->__getMediumAttachments($m->getMediumAttachmentsOfController($c->name), $m->id)
			);
			$c->releaseRemote();
		}

		for($i = 0; $i < count($sc); $i++) {

			for($a = 0; $a < count($sc[$i]['mediumAttachments']); $a++) {

				// Value of '' means it is not applicable
				$sc[$i]['mediumAttachments'][$a]['ignoreFlush'] = '';

				// Only valid for HardDisks
				if($sc[$i]['mediumAttachments'][$a]['type'] != 'HardDisk') continue;

				// Get appropriate key
				$xtra = $this->__getIgnoreFlushKey($sc[$i]['mediumAttachments'][$a]['port'], $sc[$i]['mediumAttachments'][$a]['device'], $sc[$i]['controllerType']);

				// No such setting for this bus type
				if(!$xtra) continue;

				$sc[$i]['mediumAttachments'][$a]['ignoreFlush'] = $m->getExtraData($xtra);

				if(trim($sc[$i]['mediumAttachments'][$a]['ignoreFlush']) === '')
					$sc[$i]['mediumAttachments'][$a]['ignoreFlush'] = 1;
				else
					$sc[$i]['mediumAttachments'][$a]['ignoreFlush'] = intval($sc[$i]['mediumAttachments'][$a]['ignoreFlush']);

			}
		}

		return $sc;
	}

	/**
	 * Clone a medium
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function mediumCloneTo($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$format = strtoupper($args['format']);
		/* @var $target IMedium */
		$target = $this->vbox->createHardDisk($format,$args['location']);
		$mid = $target->id;

		/* @var $src IMedium */
		$src = $this->vbox->findMedium($args['src'],'HardDisk');

		$type = ($args['type'] == 'fixed' ? 'Fixed' : 'Standard');
		$mv = new MediumVariant();
		$type = $mv->ValueMap[$type];
		if($args['split']) $type += $mv->ValueMap['VmdkSplit2G'];

		/* @var $progress IProgress */
		$progress = $src->cloneTo($target->handle,$type,null);

		$src->releaseRemote();
		$target->releaseRemote();

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$this->__storeProgress($progress,'getMedia');

		$response['data'] = array('progress' => $progress->handle, 'id' => $mid);

		return true;
	}

	/**
	 * Set medium to a specific type
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function mediumSetType($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $m IMedium */
		$m = $this->vbox->findMedium($args['id'],'HardDisk');
		$m->type = $args['type'];
		$m->releaseRemote();

		$this->cache->expire('getMedia');

		$response['data'] = array('result' => 1,'id' => $args['id']);

		return true;
	}

	/**
	 * Add iSCSI medium
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function mediumAddISCSI($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		// {'server':server,'port':port,'intnet':intnet,'target':target,'lun':lun,'enclun':enclun,'targetUser':user,'targetPass':pass}

		// Fix LUN
		$args['lun'] = intval($args['lun']);
		if($args['enclun']) $args['lun'] = 'enc'.$args['lun'];

		// Compose name
		$name = $args['server'].'|'.$args['target'];
		if($args['lun'] != 0 && $args['lun'] != 'enc0')
			$name .= '|'.$args['lun'];

		// Create disk
		/* @var $hd IMedium */
		$hd = $this->vbox->createHardDisk('iSCSI',$name);

		if($args['port']) $args['server'] .= ':'.intval($args['port']);

		$arrProps = array();

		$arrProps["TargetAddress"] = $args['server'];
		$arrProps["TargetName"] = $args['target'];
		$arrProps["LUN"] = $args['lun'];
		if($args['targetUser']) $arrProps["InitiatorUsername"] = $args['targetUser'];
		if($args['targetPass']) $arrProps["InitiatorSecret"] = $args['targetPass'];
		if($args['intnet']) $arrProps["HostIPStack"] = '0';

		$hd->setProperties(array_keys($arrProps),array_values($arrProps));

		$this->cache->expire('getMedia');
		$response['data'] = array('result' => 1, 'id' => $hd->id);
		$hd->releaseRemote();
	}

	/**
	 * Add existing medium by file location
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function mediumAdd($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $m IMedium */
		$m = $this->vbox->openMedium($args['path'],$args['type'],'ReadWrite',false);

		$this->cache->expire('getMedia');
		$response['data'] = array('result' => 1, 'id' => $m->id);
		$m->releaseRemote();
	}

	/**
	 * Get VirtualBox generated machine configuration file name
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getComposedMachineFilename($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$response['data']['file'] = $this->vbox->composeMachineFilename($args['name'],$this->vbox->systemProperties->defaultMachineFolder);

		return true;

	}

	/**
	 * Create base storage medium (virtual hard disk)
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function mediumCreateBaseStorage($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		if (@$this->settings->enforceVMOwnership )
		{
			$dirparts = explode('/', $args['file']);
			foreach ( $dirparts as $i => &$bit )
			{
				if ( $i == count($dirparts) - 2 || $i == count($dirparts) - 1 )
				{
					// file or directory name
					$bit = "{$_SESSION['user']}_" . preg_replace('/^' . preg_quote($_SESSION['user']) . '_/', '', $bit);
				}
			}
			$args['file'] = implode('/', $dirparts);
		}

		$format = strtoupper($args['format']);
		$type = ($args['type'] == 'fixed' ? 'Fixed' : 'Standard');
		$mv = new MediumVariant();
		$type = $mv->ValueMap[$type];
		if($args['split']) $type += $mv->ValueMap['VmdkSplit2G'];

		/* @var $hd IMedium */
		$hd = $this->vbox->createHardDisk($format,$args['file']);

		/* @var $progress IProgress */
		$progress = $hd->createBaseStorage(intval($args['size'])*1024*1024,$type);

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$this->__storeProgress($progress,'getMedia');

		$response['data'] = array('progress' => $progress->handle,'id' => $hd->id);
		$hd->releaseRemote();

		return true;
	}

	/**
	 * Release medium from all attachments
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function mediumRelease($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $m IMedium */
		$m = $this->vbox->findMedium($args['id'],$args['type']);

		// connected to...
		$machines = $m->machineIds;
		$response['data']['released'] = array();
		foreach($machines as $uuid) {

			// Find medium attachment
			try {
				/* @var $mach IMachine */
				$mach = $this->vbox->findMachine($uuid);
			} catch (Exception $e) {
				continue;
			}
			$attach = $mach->mediumAttachments;
			$remove = array();
			foreach($attach as $a) {
				if($a->medium->handle && $a->medium->id == $args['id']) {
					$remove[] = array(
						'controller' => $a->controller,
						'port' => $a->port,
						'device' => $a->device);
					break;
				}
			}
			// save state
			$state = $mach->sessionState->__toString();

			if(!count($remove)) continue;

			$response['data']['released'][] = $uuid;

			// create session
			$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);

			// Hard disk requires machine to be stopped
			if($args['type'] == 'HardDisk' || $state == 'Unlocked') {

				$mach->lockMachine($this->session->handle, 'Write');

			} else {

				$mach->lockMachine($this->session->handle, 'Shared');

			}

			foreach($remove as $r) {
				if($args['type'] == 'HardDisk') {
					$this->session->machine->detachDevice($r['controller'],$r['port'],$r['device']);
				} else {
					$this->session->machine->mountMedium($r['controller'],$r['port'],$r['device'],null,true);
				}
			}

			$this->session->machine->saveSettings();
			$this->session->machine->releaseRemote();
			$this->session->unlockMachine();
			$this->session->releaseRemote();
			unset($this->session);
			$mach->releaseRemote();

			$this->cache->expire('__getStorageControllers'.$uuid);
		}
		$m->releaseRemote();

		$this->cache->expire('getMedia');

		return ($response['data']['result'] = 1);
	}

	/**
	 * Remove a medium
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function mediumRemove($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		if(!$args['type']) $args['type'] = 'HardDisk';

		/* @var $m IMedium */
		$m = $this->vbox->findMedium($args['id'],$args['type']);

		if($args['delete'] && @$this->settings->deleteOnRemove && $m->deviceType->__toString() == 'HardDisk') {

			/* @var $progress IProgress */
			$progress = $m->deleteStorage();

			$m->releaseRemote();

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					return false;
				}
			} catch (Exception $null) { }

			$this->__storeProgress($progress,'getMedia');
			$response['data']['progress'] = $progress->handle;

		} else {
			$m->close();
			$m->releaseRemote();
			$this->cache->expire('getMedia');
		}

		return($reponse['data']['result'] = 1);
	}

	/**
	 * Get a list of recent media
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getRecentMedia($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		foreach(array(
			array('type'=>'HardDisk','key'=>'GUI/RecentListHD'),
			array('type'=>'DVD','key'=>'GUI/RecentListCD'),
			array('type'=>'Floppy','key'=>'GUI/RecentListFD')) as $r) {
			$list = $this->vbox->getExtraData($r['key']);
			$response['data'][$r['type']] = array_filter(explode(';', trim($list,';')));
		}
		return $response;
	}

	/**
	 * Get a list of recent media paths
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getRecentMediaPaths($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		foreach(array(
			array('type'=>'HardDisk','key'=>'GUI/RecentFolderHD'),
			array('type'=>'DVD','key'=>'GUI/RecentFolderCD'),
			array('type'=>'Floppy','key'=>'GUI/RecentFolderFD')) as $r) {
			$response['data'][$r['type']] = $this->vbox->getExtraData($r['key']);
		}
		return $response;
	}


	/**
	 * Update recent medium path list
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function updateRecentMediumPath($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$types = array(
			'HardDisk'=>'GUI/RecentFolderHD',
			'DVD'=>'GUI/RecentFolderCD',
			'Floppy'=>'GUI/RecentFolderFD'
		);

		$this->vbox->setExtraData($types[$args['type']], $args['folder']);

		return ($response['data']['result'] = 1);
	}

	/**
	 * Update recent media list
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function mediumRecentUpdate($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$types = array(
			'HardDisk'=>'GUI/RecentListHD',
			'DVD'=>'GUI/RecentListCD',
			'Floppy'=>'GUI/RecentListFD'
		);

		$this->vbox->setExtraData($types[$args['type']], implode(';',array_unique($args['list'])).';');

		return ($response['data']['result'] = 1);

	}

	/**
	 * Mount a medium on the VM
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @param boolean $save save the VM's configuration after mounting
	 * @return boolean true on success
	 */
	public function mediumMount($args,&$response,$save=false) {

		// Connect to vboxwebsrv
		$this->connect();

		// Find medium attachment
		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($args['vm']);
		$state = $machine->sessionState->__toString();
		$save = ($save || strtolower($machine->getExtraData('GUI/SaveMountedAtRuntime')) == 'yes');

		// create session
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);

		if($state == 'Unlocked') {
			$machine->lockMachine($this->session->handle,'Write');
			$save = true; // force save on closed session as it is not a "run-time" change
		} else {

			$machine->lockMachine($this->session->handle, 'Shared');
		}

		// Empty medium / eject
		if($args['medium'] == 0) {
			$med = null;
		} else {
			// Host drive
			if(strtolower($args['medium']['hostDrive']) == 'true' || $args['medium']['hostDrive'] === true) {
				// CD / DVD Drive
				if($args['medium']['deviceType'] == 'DVD') {
					$drives = $this->vbox->host->DVDDrives;
				// floppy drives
				} else {
					$drives = $this->vbox->host->floppyDrives;
				}
				foreach($drives as $m) { /* @var $m IMedium */
					if($m->id == $args['medium']['id']) {
						/* @var $med IMedium */
						$med = &$m;
						break;
					}
					$m->releaseRemote();
				}
			// Normal medium
			} else {
				/* @var $med IMedium */
				$med = $this->vbox->findMedium($args['medium']['id'],$args['medium']['deviceType']);
			}
		}

		$this->session->machine->mountMedium($args['controller'],$args['port'],$args['device'],(is_object($med) ? $med->handle : null),true);

		if(is_object($med)) $med->releaseRemote();

		if($save) $this->session->machine->saveSettings();

		$this->session->unlockMachine();
		$this->session->releaseRemote();
		$machine->releaseRemote();
		unset($this->session);

		$this->cache->expire('getMedia');
		$this->cache->expire('__getStorageControllers'.$args['vm']);

		return ($response['data']['result'] = 1);
	}

	/**
	 * Get medium details
	 *
	 * @param IMedium $m medium instance
	 * @return array medium details
	 */
	private function __getMedium(&$m) {

		$children = array();
		$attachedTo = array();
		$machines = $m->machineIds;
		$hasSnapshots = 0;

		foreach($m->children as $c) { /* @var $c IMedium */
			$children[] = $this->__getMedium($c);
			$c->releaseRemote();
		}

		foreach($machines as $mid) {
			$sids = $m->getSnapshotIds($mid);
			try {
				/* @var $mid IMachine */
				$mid = $this->vbox->findMachine($mid);
			} catch (Exception $e) {
				continue;
			}

			$c = count($sids);
			$hasSnapshots = max($hasSnapshots,$c);
			for($i = 0; $i < $c; $i++) {
				if($sids[$i] == $mid->id) {
					unset($sids[$i]);
				} else {
					try {
						/* @var $sn ISnapshot */
						$sn = $mid->findSnapshot($sids[$i]);
						$sids[$i] = $sn->name;
						$sn->releaseRemote();
					} catch(Exception $e) { }
				}
			}
			$hasSnapshots = (count($sids) ? 1 : 0);
			$attachedTo[] = array('machine'=>$mid->name,'snapshots'=>$sids);
			$mid->releaseRemote();
		}

		// For $fixed value
		$mv = new MediumVariant();
		$variant = $m->variant;

		return array(
				'id' => $m->id,
				'description' => $m->description,
				'state' => $m->refreshState()->__toString(),
				'location' => $m->location,
				'name' => $m->name,
				'deviceType' => $m->deviceType->__toString(),
				'hostDrive' => $m->hostDrive,
				'size' => (string)$m->size, /* (string) to support large disks. Bypass integer limit */
				'format' => $m->format,
				'type' => $m->type->__toString(),
				'parent' => (($m->deviceType->__toString() == 'HardDisk' && $m->parent->handle) ? $m->parent->id : null),
				'children' => $children,
				'base' => (($m->deviceType->__toString() == 'HardDisk' && $m->base->handle) ? $m->base->id : null),
				'readOnly' => $m->readOnly,
				'logicalSize' => ($m->logicalSize/1024)/1024,
				'autoReset' => $m->autoReset,
				'hasSnapshots' => $hasSnapshots,
				'lastAccessError' => $m->lastAccessError,
				'fixed' => intval((intval($variant) & $mv->ValueMap['Fixed']) > 0),
				'split' => intval((intval($variant) & $mv->ValueMap['VmdkSplit2G']) > 0),
				'machineIds' => array(),
				'attachedTo' => $attachedTo
			);

	}

	/**
	 * Store a progress operation so that its status can be polled via getProgress()
	 *
	 * @param IProgress $progress progress operation instance
	 * @param array $expire cache items to expire when progress operation completes
	 * @return string progress operation handle / id
	 */
	private function __storeProgress(&$progress,$expire=null) {

		/* Store progress operation */
		$this->cache->lock('ProgressOperations');
		$inprogress = $this->cache->get('ProgressOperations');
		if(!is_array($inprogress)) $inprogress = array();
		if($expire && !is_array($expire)) $expire = array($expire);

		// If progress is unaccessible, let getProgress()
		// handle it. Try / catch used and errors ignored.
		try { $cancelable = $progress->cancelable; }
		catch (Exception $null) {}

		$inprogress[$progress->handle] = array(
			'session'=>$this->vbox->handle,
			'progress'=>$progress->handle,
			'cancelable'=>$cancelable,
			'expire'=> $expire,
			'started'=>time());

		$this->cache->store('ProgressOperations',$inprogress);

		/* Do not destroy login session / reference to progress operation */
		$this->progressCreated = true;

		return $progress->handle;
	}

	/**
	 * Get VirtualBox system properties
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	private function getSystemPropertiesCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$mediumFormats = array();

		// capabilities
		$mfCap = new MediumFormatCapabilities(null,'');
		foreach($this->vbox->systemProperties->mediumFormats as $mf) { /* @var $mf IMediumFormat */
			$exts = $mf->describeFileExtensions();
			$dtypes = array();
			foreach($exts[1] as $t) $dtypes[] = $t->__toString();
			$caps = array();
			foreach($mfCap->NameMap as $k=>$v) {
				if ($k & $mf->capabilities)	 $caps[] = $v;
			}
			$mediumFormats[] = array('id'=>$mf->id,'name'=>$mf->name,'extensions'=>array_map('strtolower',$exts[0]),'deviceTypes'=>$dtypes,'capabilities'=>$caps);
		}

		$response['data'] = array(
			'minGuestRAM' => (string)$this->vbox->systemProperties->minGuestRAM,
			'maxGuestRAM' => (string)$this->vbox->systemProperties->maxGuestRAM,
			'minGuestVRAM' => (string)$this->vbox->systemProperties->minGuestVRAM,
			'maxGuestVRAM' => (string)$this->vbox->systemProperties->maxGuestVRAM,
			'minGuestCPUCount' => (string)$this->vbox->systemProperties->minGuestCPUCount,
			'maxGuestCPUCount' => (string)$this->vbox->systemProperties->maxGuestCPUCount,
			'infoVDSize' => (string)$this->vbox->systemProperties->infoVDSize,
			'networkAdapterCount' => 8, // static value for now
			'maxBootPosition' => (string)$this->vbox->systemProperties->maxBootPosition,
			'defaultMachineFolder' => (string)$this->vbox->systemProperties->defaultMachineFolder,
			'defaultHardDiskFormat' => (string)$this->vbox->systemProperties->defaultHardDiskFormat,
			'homeFolder' => $this->vbox->homeFolder,
			'VRDEAuthLibrary' => (string)$this->vbox->systemProperties->VRDEAuthLibrary,
			'defaultAudioDriver' => (string)$this->vbox->systemProperties->defaultAudioDriver,
			'maxGuestMonitors' => $this->vbox->systemProperties->maxGuestMonitors,
			'defaultVRDEExtPack' => $this->vbox->systemProperties->defaultVRDEExtPack,
			'serialPortCount' => $this->vbox->systemProperties->serialPortCount,
			'parallelPortCount' => $this->vbox->systemProperties->parallelPortCount,
			'mediumFormats' => $mediumFormats
		);
		return true;
	}

	/**
	 * Get a list of VM log file names
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 */
	public function getVMLogFilesInfo($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $m IMachine */
		$m = $this->vbox->findMachine($args['vm']);

		$logs = array();

		try { $i = 0; while($l = $m->queryLogFilename($i++)) $logs[] = $l;
		} catch (Exception $null) {}

		$response['data']['path'] = $m->logFolder;
		$response['data']['logs'] = $logs;
		$m->releaseRemote();
	}

	/**
	 * Get VM log file contents
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 */
	public function getVMLogFile($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$response['data']['log'] = '';

		/* @var $m IMachine */
		$m = $this->vbox->findMachine($args['vm']);
		try {
			// Read in 8k chunks
			while($l = $m->readLog(intval($args['log']),strlen($response['data']['log']),8192)) {
				if(!count($l) || !strlen($l[0])) break;
				@$response['data']['log'] .= base64_decode($l[0]);
			}
		} catch (Exception $null) {}
		$m->releaseRemote();

		// Attempt to UTF-8 encode string or json_encode may choke
		// and return an empty string
		if(function_exists('utf8_encode'))
			$response['data']['log'] = utf8_encode($response['data']['log']);
	}

	/**
	 * Get a list of USB devices attached to a given VM
	 *
	 * @param array $args array of arguments. See function body for details.
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function getVMUSBDevices($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		/* @var $machine IMachine */
		$machine = $this->vbox->findMachine($args['vm']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');

		$response['data'] = array();
		foreach($this->session->console->USBDevices as $u) { /* @var $u IUSBDevice */
			$response['data'][$u->id] = array('id'=>$u->id,'remote'=>$u->remote);
			$u->releaseRemote();
		}
		
		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();


	}

	/**
	 * Return a string representing the VirtualBox ExtraData key
	 * for this port + device + bus type IgnoreFlush setting
	 * @param integer port medium attachment port number
	 * @param integer device medium attachment device number
	 * @param string cType controller type
	 * @return string extra data setting string
	 */
	private function __getIgnoreFlushKey($port,$device,$cType) {

		$cTypes = array(
			'piix3' => 'piix3ide',
			'piix4' => 'piix3ide',
			'ich6' => 'piix3ide',
			'intelahci' => 'ahci',
			'lsilogic' => 'lsilogicscsi',
			'buslogic' => 'buslogic',
			'lsilogicsas' => 'lsilogicsas'
		);

		if(!isset($cTypes[strtolower($cType)])) {
			$this->errors[] = new Exception('Invalid controller type: ' . $cType);
			return '';
		}

		$lun = ((intval($device)*2) + intval($port));

		return str_replace('[b]',$lun,str_replace('[a]',$cTypes[strtolower($cType)],"VBoxInternal/Devices/[a]/0/LUN#[b]/Config/IgnoreFlush"));

	}

	/**
	 * Get a newly generated MAC address from VirtualBox
	 *
	 * @param array $args array of arguments. See function body for details
	 * @param array $response response data passed byref populated by the function
	 * @return boolean true on success
	 */
	public function genMACAddress($args,&$response) {

		// Connect to vboxwebsrv
		$this->connect();

		$response['data']['mac'] = $this->vbox->host->generateMACAddress();

	}

	/**
	 * Format a time span in seconds into days / hours / minutes / seconds
	 *
	 * @param integer $t number of seconds
	 * @return array containing number of days / hours / minutes / seconds
	 */
	private function __splitTime($t) {

		$spans = array(
			'days' => 86400,
			'hours' => 3600,
			'minutes' => 60,
			'seconds' => 1);

		$time = array();

		foreach($spans as $k => $v) {
			if(!(floor($t / $v) > 0)) continue;
			$time[$k] = floor($t / $v);
			$t -= floor($time[$k] * $v);
		}

		return $time;
	}
	

}

