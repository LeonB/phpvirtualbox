<?php
/*
 * $Id$
 */

global $_vbox_language;

require_once(dirname(dirname(__FILE__)).'/lib/language.php');

if(!is_object($_vbox_language)) $_vbox_language = new __vbox_language();

error_reporting(0);

if($_GET['debug']) {
	print_r($_vbox_language->langdata);
	return;
}


header("Content-type: text/javascript; charset=utf-8", true);

//Set no caching
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
header("Pragma: no-cache");


/*
 * Dump in JavaScript
 */
echo('var __vboxLangData = ' . json_encode($_vbox_language->langdata) .";\n\nvar __vboxLangName = '".@constant('VBOXLANG')."';\n\n");


?>

var __vboxLangContext = null;
var __vboxLangContexts = [];

// Temporary debug wrapper
function trans(s,c,n) {

	var r = transreal(s,c,n);
	
	if(typeof r != 'string') {
	   // debug
	   alert(s + ' ' + c + ' ' + typeof(r));
	   return s;
	}
	return r;
}

function transreal(w,context,number) {
	
	if(!context) context = __vboxLangContext;
	if(!context) context = 'phpVirtualBox';
	
	try {
		if(__vboxLangData['contexts'][context]['messages'][w]['translation']) {
			if(number !== undefined && __vboxLangData['contexts'][context]['messages'][w]['translation']['numerusform']) {
				var t = __vboxLangData['contexts'][context]['messages'][w]['translation']['numerusform'];
				if(number == 0 && t[0]) return t[0];
				if(number > 0 && t[1]) return t[1];
				if(t[0]) return t[0];
				return t[1];
			}
			return __vboxLangData['contexts'][context]['messages'][w]['translation'];
		} else {
			return w;
		}
	} catch(err) {
		return w;
	}	
}

function vboxSetLangContext(w) {
	__vboxLangContexts[__vboxLangContexts.length] = w;
	__vboxLangContext = w;
}

function vboxUnsetLangContext(w) {
	if(__vboxLangContexts.length > 1) {
		__vboxLangContexts.pop();
		__vboxLangContext = __vboxLangContexts[(__vboxLangContexts.length - 1)];
	} else {
		if (__vboxLangContexts.length > 0) delete __vboxLangContexts[0];
		__vboxLangContext = null;
	}

}
