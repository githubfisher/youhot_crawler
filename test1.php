<?php
function findKey ($des, $mapping) {
	$p = array(
	".",
	",",
	":",
	";",
	"!",
	" ",
	"'",
	'"',
	);
	$keywords = '';
	$keys = array_keys($mapping);
	for ($i=0;$i<count($keys);$i++) {
		$str = $des;
		do {
			$pos = stripos($str, $keys[$i]);
			if ($pos || ($pos === 0)) {
				$length = strlen($keys[$i]);
				if ((($pos === 0) && in_array($str[$pos+$length], $p)) || (in_array(@$str[$pos-1], $p) && ($pos+$length) == strlen($str)) || (in_array(@$str[$pos-1], $p) && in_array($str[$pos+$length], $p))) {
					if (!strpos($keywords, $mapping[$keys[$i]])) {
						$keywords .= ' '.$mapping[$keys[$i]];
						break;
					}
				}
				$str = substr($str, $pos+$length);
				$pos = true;
			}
		} while ($pos && !empty($str));
	}

	return $keywords;
}
$des = 'Ash snake-embossed leather backpack with silvertone hardware. Flat top handle, 4\\" drop. Adjustable shoulder straps, 11\\" drop. Two-way zip-around top closure. Two front and two side zip pockets. Interior, fabric lining; one zip and three slip pockets. 15.5\\"H x 11\\"W x 5\\"W; weighs 2 lb. 3.9 oz. \\"Danica\\" is imported. ';
require_once(dirname(__FILE__).'/../common/config/property.php');
$keyword = findKey($des,$mapping);
echo $keyword;
