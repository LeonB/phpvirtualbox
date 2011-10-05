<?php
/**
 * Simple data -> filesystem caching class used by vboxconnector.php
 * to cache data returned by vboxwebsrv. Cache locking / unlocking
 * uses flock().
 * 
 * @author Ian Moore (imoore76 at yahoo dot com)
 * @copyright Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 * @version $Id$
 * @package phpVirtualBox
 * @see vboxconnector
 * @see flock()
 * 
 * <code>
 * <?php
 * 
 * cache = new cache();
 * 
 * $key = 'person_' . $_POST['pid'];
 * 
 * // Get cached person data that is no more than an hour old.
 * $person = $cache->get($key, 3600);
 * 
 * if($person === false) {
 * 
 *    // No cached person :( it's time to obtain and store new data
 *    
 *    $person = new person($_POST['pid']);
 *    $cache->lock($key);
 *    $cache->store($key,$person);
 *    
 * }
 *
 *
 * ?>
 * </code>
 *
 *
 */

class cache {

	/**
	 *  Path to temporary folder to store cache files
	 *  @var string
	 */
	var $path = '/tmp';

	/**
	 *  File extension appended to cache files
	 *  @var string
	 */	
	var $ext = 'dat';
	
	/**
	 *  Prefix string prepended to cache files to provide some level
	 *  of namespace separation.
	 *  @var string
	 */	
	var $prefix = 'pvbx';
	
	/**
	 *  List of cache items that have been locked
	 *  @access private
	 *  @var array
	 */	
	var $locked = array();
	
	/**
	 * If set, forces the refresh of cached items - it will appear
	 * that a cache item for the requested data does not exist
	 * @var boolean
	 */
	var $force_refresh = false;
	
	/**
	 * Holds list of open cache items
	 * @access private
	 * @var array
	 */
	var $open = array();
	
	/**
	 * Logfile used for debugging. If not set, or set to empty string
	 * no debugging will be performed.
	 * @var string|null
	 */
	var $logfile = null;

	/**
	 * Sets $path by checking various environment variables.
	 */
	function __construct() {
		if(@$_ENV['TEMP'] && @is_writable($_ENV['TEMP'])) {
			$this->path = $_ENV['TEMP'];
		} else if(@$_ENV['TMP'] && @is_writable($_ENV['TMP'])) {
			$this->path = $_ENV['TMP'];
		// PHP >= 5.2.1
		} else if(function_exists('sys_get_temp_dir')) {
			$this->path = sys_get_temp_dir();
		}
	}

	/**
	 * Do our best to ensure all filehandles are closed
	 * and flocks removed even in an unclean shutdown.
	 */
	function __destruct() {
		$keys = array_keys($this->open);
		foreach($keys as $k) {
			@flock($this->open[$k],LOCK_UN);
			@fclose($this->open[$k]);
		}
	}

	/**
	 * Get cached data or return false if no cached data is found.
	 * @param string $key key used to identify cached item
	 * @param integer $expire maximum age (in seconds) of cached item before it is considered invalid
	 * @return array|boolean
	 */
	function get($key,$expire=60) {
		# Is file cached?
		if(!$this->cached($key,$expire)) return false;
		$d = $this->_getCachedData($key);
		if($this->logfile) $this->_log("Returning cached data for {$key}");
		return ($d === false ? $d : unserialize($d));
	}


	/**
	 * Obtain an exclusive lock on cache item using flock(). Once an item is locked,
	 * it is added to the $open and $locked arrays. If an item was written
	 * to while waiting to be locked, return null - indicating that recent
	 * data has already been written. This must be called before writing data
	 * to a cache item. flock() will block until the cache file is available.
	 * 
	 * @see flock()
	 * @param string $key key used to identify cached item
	 * @return boolean|null
	 */
	function lock($key) {

		$fname = $this->_fname($key);

		$prelock = intval(@filemtime($fname));

		if(($fp=fopen($fname, "a")) === false) {
			if(function_exists('error_get_last')) {
				$e = error_get_last();
				throw new Exception($e['message'],vboxconnector::PHPVB_ERRNO_FATAL);
			}
			return false;
		}
			
		$this->open[$key] = &$fp;

		chmod($fname, 0600);


		flock($fp,LOCK_SH);
		flock($fp,LOCK_EX);


		/* Written while blocking ? */
		clearstatcache();
		if($prelock > 0 && @filemtime($fname) > $prelock) {
			if($this->logfile) $this->_log("{$key} prelock: {$prelock} postlock: ". filemtime($fname) ." NOT writing.");
			flock($fp,LOCK_UN);
			fclose($fp);
			unset($this->open[$key]);
			return null;
		}

		if($this->logfile) $this->_log("{$key} prelock: {$prelock} postlock: ". filemtime($fname) ." writing.");

		$this->locked[$key] = &$fp;

		return true;
	}

	/**
	 * Store locked cache item, then unlock it.
	 * @param string $key key used to identify cached item
	 * @param array $data data to be cached
	 * @return array
	 */
	function store($key,&$data) {

		if(!$this->locked[$key]) return false;

		if($this->logfile) $this->_log("{$key} writing at ".time());

		ftruncate($this->locked[$key],0);
		fwrite($this->locked[$key], serialize($data));
		flock($this->locked[$key],LOCK_UN);
		$this->unlock($key);
		return $data;
	}

	/**
	 * Remove exclusive lock obtained through lock()
	 * @param string $key key used to identify cached item
	 */
	function unlock($key) {
		flock($this->locked[$key],LOCK_UN);
		fclose($this->locked[$key]);
		unset($this->open[$key]);
		unset($this->locked[$key]);
	}

	/**
	 * Determine if cached item identified by $key is cached and has not expired
	 * @param string $key key used to identify cached item
	 * @param int $expire maximum age (in seconds) of cached item before it is considered invalid
	 * @return boolean
	 */
	function cached($key,$expire=60) {
		return (!$this->force_refresh && @file_exists($this->_fname($key)) && ($expire === false || (@filemtime($this->_fname($key)) > (time() - ($expire)))));
	}


	/**
	 * Expire (unlink) cached item(s)
	 * @param string|array $key key or array of keys used to identify cached item
	 */
	function expire($key) {
		if(is_array($key)) {
			foreach(array_unique($key) as $k) $this->expire($k);
			return;	
		}
		if($this->locked[$key]) $this->unlock($key);
		clearstatcache();
		if(!file_exists($this->_fname($key))) return;
		for(;file_exists($this->_fname($key)) && !@unlink($this->_fname($key));) { sleep(1); clearstatcache(); }
	}

	/**
	 * Logging used for debugging
	 * @param string $s message to log
	 */
	function _log($s) {
		if(!$this->logfile) return;
		$f = fopen($this->path.'/'.$this->logfile,'a');
		fputs($f,$s."\n");
		fclose(f);
	}

	/**
	 * Lock aware file read. Uses flock() to obtain a shared lock on
	 * cache item. Returns false if file could not be opened or contanis no data,
	 * else returns data.
	 * @access private
	 * @param string $key key used to identify cached item
	 * @return array|boolean
	 */
	private function _getCachedData($key) {

		$fname = $this->_fname($key);

		// Pre-existing locked read
		if(@$this->locked[$key]) {
			@fseek($this->locked[$key],0);
			$str = @fread($this->locked[$key],@filesize($fname));
			@fseek($this->locked[$key],0);
			return $str;
		}

		$fp=fopen($fname, "r");
		if($fp === false) return false;
		$this->open[$key] = &$fp;
		flock($fp,LOCK_SH);
		// The following 2 lines handle cases where fopen (above) was called
		// on an empty file that was created by cache::lock()
		clearstatcache();
		fseek($fp,0);
		$str = @fread($fp,@filesize($fname));
		flock($fp,LOCK_UN);
		fclose($fp);
		unset($this->open[$key]);
		if(@filesize($fname) == 0) return false;
		return $str;
	}

	/**
	 * Generates a filename for the cache file using $path, $prefix and $ext class vars
	 * @access private
	 * @param string $key key used to identify cached item
	 * @return string
	 */
	private function _fname($key) { return $this->path.'/'.$this->prefix.md5($key).'.'.$this->ext; }

}




