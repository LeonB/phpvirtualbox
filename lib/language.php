<?php
/*
 * Returns PHP language class
 *
 * The decision to use PHP for language files instead of JS
 * was made in case the PHP back-end needs to inject translated
 * messages into the interface.
 *
 * $Id$
 *
 */


/*
 * Load language file
 */

global $_vbox_language;


// Settings contains language
require_once(dirname(__FILE__).'/config.php');
require_once(dirname(__FILE__).'/utils.php');

class __vbox_language {
	
	var $langdata = array();
	
	function __vbox_language() {
		
		$settings = new phpVBoxConfigClass();
		$lang = strtolower($settings->language);
		
		if(@$_COOKIE['vboxLanguage']) {
			$lang = str_replace(array('/','\\','.'),'',$_COOKIE['vboxLanguage']);
		}
		
		// File as specified
		if($lang && file_exists(dirname(dirname(__FILE__)).'/languages/source/'.$lang.'.dat')) {
			@define('VBOXLANG', $lang);
		
		// No lang file found
		} else {
			$lang = 'en_us';
			@define('VBOXLANG', $lang);
			$this->langdata['contexts'] = array();
			return;			
		}
		
		
		$this->langdata = unserialize(file_get_contents(dirname(dirname(__FILE__)).'/languages/source/'.$lang.'.dat'));

		$xmlObj = simplexml_load_string(file_get_contents(dirname(dirname(__FILE__)).'/languages/'.$lang.'.xml'));
		$arrXml = $this->objectsIntoArray($xmlObj);
		
		$lang = array();
		if(!@$arrXml['context'][0]) $arrXml['context'] = array($arrXml['context']);
		foreach($arrXml['context'] as $c) {

			if(!is_array($c) || !@$c['name']) continue;
			if(!@$c['message'][0]) $c['message'] = array($c['message']);
			
		   	$lang['contexts'][$c['name']] = array();
		   	$lang['contexts'][$c['name']]['messages'] = array();

			foreach($c['message'] as $m) {

		       if(!is_array($m)) continue;
	
		       $s = $m['source'];
		       unset($m['source']);
		       $lang['contexts'][$c['name']]['messages'][$s] = $m;
	    	}
		}
		$this->langdata = array_merge_recursive($this->langdata, $lang);
	}
	
	function trans($item,$context='phpVirtualBox') {
		$t = @$this->langdata['contexts'][$context]['messages'][$item]['translation'];
		return ($t ? $t : $item);
	}
	function objectsIntoArray($arrObjData, $arrSkipIndices = array())
	{
	    $arrData = array();
	
	    // if input is object, convert into array
	    if (is_object($arrObjData)) {
	        $arrObjData = get_object_vars($arrObjData);
	    }
	
	    if (is_array($arrObjData)) {
	        foreach ($arrObjData as $index => $value) {
	            if (is_object($value) || is_array($value)) {
	                $value = $this->objectsIntoArray($value, $arrSkipIndices); // recursive call
	            }
	            if (in_array($index, $arrSkipIndices)) {
	                continue;
	            }
	            $arrData[$index] = $value;
	        }
	    }
	    return $arrData;
	}
	
}

function trans($a,$context='phpVirtualBox') {
	if(!is_object($GLOBALS['_vbox_language'])) $GLOBALS['_vbox_language'] = new __vbox_language();
	return $GLOBALS['_vbox_language']->trans($a,$context);
}
