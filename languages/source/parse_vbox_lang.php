<?php
/*
 * $Id$
 * 
 * Parse VirtualBox (QT) language file
 * 
 */

/*
 * Helper functino
 */
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
                $value = objectsIntoArray($value, $arrSkipIndices); // recursive call
            }
            if (in_array($index, $arrSkipIndices)) {
                continue;
            }
            $arrData[$index] = $value;
        }
    }
    return $arrData;
}

if(!@$argv[1]) {

   echo("Usage: {$argv[0]} language_file [-php : php print_r style output]\n");
   exit;
}

$phpStyle = (@strtolower($argv[2]) == '-php');

$xmlObj = simplexml_load_string(file_get_contents($argv[1]));
$arrXml = objectsIntoArray($xmlObj);


$lang = array();
$lang['contexts'] = array();

foreach($arrXml['context'] as $c) {

   $lang['contexts'][$c['name']] = array();
   $lang['contexts'][$c['name']]['messages'] = array();

    if(@$c['message']['source']) $c['message'] = array($c['message']);

    foreach($c['message'] as $m) {

       if(!is_array($m)) continue;

       $s = clean($m['source']);
       unset($m['source']);

       // Check for valid translation data
       if(is_array($m['translation']) && count($m['translation']) == 0)
          $m['translation'] = '';

       if(is_array($m['translation']) && @$m['translation']['numerusform']) {
          if(!is_array($m['translation']['numerusform'])) {
             $m['translation'] = clean($m['translation']['numerusform']);
          } else {
             foreach($m['translation']['numerusform'] as $k=>$v) {
                if(is_array($v)) continue;
                $m['translation']['numerusform'][$k] = clean($v);
             }
          }
       } else if(is_array($m['translation'])) {
          
	   // assume unfinished
           $m['translation'] = $s;
         
       } else {
          $m['translation'] = clean($m['translation']);
       }

       if($phpStyle) {
          $m['htmlized'] = htmlentities($s, ENT_NOQUOTES, 'UTF-8');
          if(strlen($m['htmlized']) == strlen($s)) unset($m['htmlized']);
       } else {
          unset($m['comment']);
       }

       $lang['contexts'][$c['name']]['messages'][$s] = $m;

       /* For debugging only
       $unk = false;
       foreach($m as $k => $v) {

          switch($k) {

             case 'source':
             case 'comment':
             case 'translation':
                continue; 
             default:
                $unk=true;
             

          }

       }
       if($unk) print_r($m);
       */

    }
}

function clean($s) {
   return preg_replace('/<\/?qt>/','',str_replace('&','',html_entity_decode(str_replace('&nbsp;',' ',$s), ENT_NOQUOTES, 'UTF-8')));
}


if($phpStyle) {
   print_r($lang);
} else {
   echo(serialize($lang));
}

