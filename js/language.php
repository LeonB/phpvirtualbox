<?php
/*
 * $Id$
 */

# Turn off PHP errors
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_WARNING & ~E_DEPRECATED);

require_once(dirname(dirname(__FILE__)).'/lib/language.php');

if(!is_object($_vbox_language)) $_vbox_language = new __vbox_language();

if($_GET['debug']) {
	print_r(__vbox_language::$langdata);
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
echo('var __vboxLangData = ' . json_encode(__vbox_language::$langdata) .";\n\nvar __vboxLangName = '".@constant('VBOXLANG')."';\n\n");


?>


// Failsafe wrapper
function trans(s,c,n,h) {

	if(!c) c = 'VBoxGlobal';

	var r = transreal(s,c,n,h);
	
	if(typeof r != 'string') {	
	   return s;
	}
	
	return r;
}

function transreal(w,context,number,comment) {
	
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
			
		} else if(__vboxLangData['contexts'][context]['messages'][w][0]) {
		
			if(comment) {
				for(var i in __vboxLangData['contexts'][context]['messages'][w]) {
					if(__vboxLangData['contexts'][context]['messages'][w][i]['comment'] == comment) return __vboxLangData['contexts'][context]['messages'][w][i]['translation'];
				}
			}
			return __vboxLangData['contexts'][context]['messages'][w][0]['translation'];
			
		} else {
			return w;
		}
		
	} catch(err) {
		// alert(w + ' - ' + context + ': ' + err);
		return w;
	}	
}

