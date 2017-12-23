<?php
// create/update ES index
    function curl_es($data, $type = 'GET')
    {
        $url = 'http://114.55.40.32/index.php/api/coru/?data'.$data;
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => $type
            )
        ));
        $res = file_get_contents($url, false, $ctx);

        return $res;
    }

$data = array(
	'data' => array(
		'type' => 'create',
		'condition' => '682785',
		'data' => array(
			'title' => 'SUPERGA Espadrilles',
        		'create_time' => "1337917311",
			'author' => 155,
			
		)
	)
);
$data = array(
    'data' => array (
  'type' => 'create',
  'condition' => '245104',
  'data' =>
  array (
    'author' => '145',
    'title' => 'Tod\\\\\\\'s Woven Suede Belt',
    'brand_id' => 0,
    'create_time' => 1495524646,
    'sell_type' => 2,
  ),
)
);
$data = json_encode($data);

$ctx = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
		//'proxy' => 'tcp://'
            )
        ));
//$data = '{"type":"create","condition":"682785","data":{"author":"155","title":"SUPERGA Espadrilles","brand_id":0,"create_time":"1337917311","sell_type":2}}';
//$data = '{"type":"create","condition":"246708","data":{"author":"132","title":"TIMBERLAND Belts","brand_id":0,"create_time":1495523477,"sell_type":2}}';
//$result = file_get_contents('http://10.26.95.72/index.php/api/coru/?data='.$data, false, $ctx);
$url = 'http://10.26.95.72/index.php/api/coru';
$ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            'Content-Length:'.strlen($data)
	   ));
	$result = curl_exec($ch);
	curl_close($ch);
print_r($result);
