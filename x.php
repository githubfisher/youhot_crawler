<?php
$rate = 6.9; //汇率
$tax  = 1.03; //税
$ems  = '';  //
$brand= 1;   //使用in_brand筛选品牌
//-----------------------------------------------
$ss = new sshop($argv, $rate, $tax, $brand);
$ss->ex_url();
//-----------------------------------------------
class sshop{
    private $debug = 0;
    private $oss   = null;
    private $rate  = 0;
    private $tax   = 0;
    private $brand = 0;
    private $brand_id = 0;
    function __construct($argv, $rate, $tax, $brand){
        $this->rate = $rate;
        $this->tax  = $tax;
        $this->brand  = $brand;
        require_once(dirname(__FILE__).'/../common/oss.php');
        $this->oss = new ali_oss();
    }
    private function translator($word, $map=array()) {
	if(!empty($word)) {
	    foreach ($map as $k => $v) {
		$word = preg_replace('/\b'.$k.'\b/i', $v, $word);
	    }
	}
	return $word;
    }
    private function get_brand($brands)
    {
	$page_log_path = dirname(__FILE__).'/../common/log/page_log';
        $brand_page = dirname(__FILE__).'/../common/config/brand_page.php';
        $brandPage = @file_get_contents($brand_page);
	$this->logger(basename(dirname(__FILE__), '.php').' Read Brand Page From log: '.$brandPage."\n", 'Info', $page_log_path); //debug
        $max = count($brands);
	$log_path = dirname(__FILE__).'/../common/log/time';
        if ($brandPage >= $max) {
	    $this->logger('End At This Moment!'."\n".date('Y-m-d H:i:s', time()).' INFO: Start At This Moment!'."\n", 'Info', $log_path);
            file_put_contents($brand_page, 1);
	    $this->logger(basename(dirname(__FILE__), '.php').' Write Brand Page To log: 1'."\n", 'Info', $page_log_path); //debug
            return $brands[0];
        } else {
	    if ($brandPage == 0) {
	        $this->logger('Start At This Moment!'."\n", 'Info', $log_path);
	    }
            file_put_contents($brand_page, $brandPage+1);
            return $brands[(int)$brandPage];
        }
    }

    private function logger($msg, $level = 'Info', $path = '')
    {
	if (empty($path)) {
            $logs = dirname(__FILE__).'/logs/run_log';
	} else {
	    $logs = $path;
	}
        $maxSize = 1000000;
        if (file_exists($logs) && (abs(filesize($logs)) >= $maxSize)) {
           file_put_contents($logs, 'Max Size:'.$maxSize.' log cleaned'."\n");
        }
        file_put_contents($logs, date('Y-m-d H:i:s').' '.$level.': '.$msg, FILE_APPEND);
    }
    private function count_spider_work($name, $cate, $total)
    {
        $data = array();
        $time = time();
        $data['latest_time'] = $time;
        $data['products_sum'] = $total;
        $data['times'] = 1;
	$data['name'] = $name;
        $cn = array('first','second','third','fourth','fifth','sixth');
        foreach ($cate as $k => $c) {
            $data[$cn[$k]] = $c['name'];
        }
	require_once(dirname(__FILE__).'/../common/db.php');
        $db = db::getIntance();
        $spider = $db->getRow("SELECT id,times,latest_time FROM spiders_work WHERE `name`='$name'");
        if ($spider) {
            $data['last_time'] = $spider['latest_time'];
            $data['d_value'] = $time - $data['last_time'];
            $data['times'] = $spider['times'] + 1;
            $id = $spider['id']; 
            $result = $db->update("spiders_work", $data, "id=$id");
        } else {
            $result = $db->insert("spiders_work", $data);
        }
        if ($result) {
            $this->logger('Save spider\'s work success.'."\n"); // info
        } else {
            $this->logger('Save spider\'s work failed.'."\n"); // info
        }
    }
    private function get_alll_categorys($brand)
    {
	require_once(dirname(__FILE__).'/../common/db.php');
        $db = db::getIntance();
        $categorys = $db->getAll("SELECT `name`,`first`,`second`,`third`,`fourth`,`fifth`,`products_sum` FROM spiders_work WHERE `products_sum` > 0 AND `name` LIKE '$brand%'");
        return $categorys;
    }
    private function off_shelve($startTime, $brand, $category)
    {
	require_once(dirname(__FILE__).'/../common/db.php');
        $db = db::getIntance();
        if (is_numeric($brand) && is_numeric($category) && ($brand > 0) && ($category > 0)) {
	    $query = "UPDATE `product` SET `status` = 90 WHERE `rank` < $startTime AND `category` = $category AND `author` = $brand";
	    $db->query($query);
            $data = array(
                'type' => 'update_range',
                'condition' => array(
                    'category' => $category,
                    'author' => $brand,
                    'rank' => $startTime
                ),
                'data' => array(
                    'status' => 89
                )
            );
	    //$this->logger('update_range_data:'.var_export($data, true)); //debug
	    $retry = 0;
	    do {
            	$res = $this->curl_es($data);
	    	$res = json_decode($res, true);
            	if (isset($res['failures']) && is_array($res['failures']) && count($res['failures']) == 0) {
		    $retry = 0;
            	    $this->logger('Update_Range_es_result: Update Range Success!'."\n"); //debug
            	} else {
		    sleep($retry++);
            	    $this->logger("Update_Range_es_result: Update Range Failed! Retry $retry time!\n"); //debug
	    	}
	   } while ($retry > 0 && $retry < 10);
        } else {
            $this->logger('TimeStamp:'.$startTime.', Brand:'.$brand.', Category:'.$category.' offshelve fail!'."\n"); //info
        }
    }
    private function find_category($cate, $brand, $category)
    {
        $theCate;
        $carray = explode('_', $cate['name']);
        $prevCate = trim($carray[1], ' ');  
        $prevCate = ltrim($prevCate, ' ');
        foreach ($category as $k => $v) {
            if ($v['prevCat'] == $prevCate) {
                $theCate = $v;
                break;
            }
        }
        if (empty($theCate)) {
            $this->logger('Cannot find the category by Name:'.$cate['name']."\n");
            return false;
        }
        $theCate['url'] = $theCate['url'].'/'.$brand;
	$theCate['url_without_brand'] = $theCate['url'];
        return $theCate;
    }
    private function get_proxy()
    {
        require_once(dirname(__FILE__).'/../common/db.php');
        $db = db::getIntance();
	$go = false;
        do {
	    $proxy = $db->getRow("SELECT id,ip,port,times,get_at FROM proxy WHERE status = 1 ORDER BY get_at DESC LIMIT 1");
	    $timestamp = time();
	    if (is_array($proxy) && ($timestamp - $proxy['get_at']) <= 120) {
		$this->logger('get proxy successfully! proxy is used '.$proxy['times'].".\n"); //debug
		$go = false;
	    } else {
		$this->logger('proxy is expired! proxy is used '.$proxy['times'].".\n"); //debug
                $go = true;
                sleep(15);
	    }
	} while ($go);
	$this->logger('return proxy\'s info'."\n"); // debug
        $data = array(
            'times' => $proxy['times'] + 1
        );
        $id = $proxy['id'];
        $db->update('proxy', $data, "id=$id");

        return $proxy;
    }
    private function update_proxy($id)
    {
        require_once(dirname(__FILE__).'/../common/db.php');
        $db = db::getIntance();
        $db->update('proxy', array('status' => 2), "id=$id");
    }
    private function curl_es($data, $url = 'api/coru')
    {
	if (!isset($data['data']['create_time'])) {
	    $data['data']['create_time'] = time();
	}
	$data = array('data' => $data);
	$data = json_encode($data);
        $url = 'http://10.26.95.72/index.php/'.$url;
	$ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            'Content-Length:'.strlen($data)
        ));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
	$this->logger('es_result:'.$result."\n"); //debug
	return $result;
    }
    function ex_url(){
        require_once(dirname(__FILE__).'/../common/config/brands.php');
        require_once(dirname(__FILE__).'/../common/config/c.php');
	require_once(dirname(__FILE__).'/../common/config/property.php');
	require_once(dirname(__FILE__).'/../common/config/translator.php');
	require_once(dirname(__FILE__).'/../common/config/weight.php');
        require_once(dirname(__FILE__).'/../common/config/rates.php');
	$map = array_merge($map, $mapping);
	unset($map['Golden']);
	unset($map['flare']);
	unset($map['lace']);
	unset($map['mini']);
	unset($map['Secret']);
	unset($map['coat']);
	unset($map['silk']);
	$map['blazer'] = '西服';
	$map['pencilskirt'] = '西服';
	$map['suit'] = '西服';
	$map['briefcase'] = '西服';
	$map['With silk'] = '真丝';
	$map['With virgin wool'] = '羔羊毛'; //（前置）
  	$map['Puffer coat'] = '棉外套'; //（前置）
  	$map['Puffer coats'] = '棉外套'; //（前置）
	$map['coat'] = '外套 大衣';
	$map['Navy'] = '海军蓝';
        do {
    	    $this->logger('StartTimeStamp:'.time()."\n", 'Time', 'time_log'); // time info
            $brand = $this->get_brand($brands);
            if (empty($brand['name']) || empty($brand['urlIdentifier'])) {
                $this->logger('Brand Name is empty!'."\n", 'ERROR');
                continue;
            }
            $cs = $this->get_alll_categorys($brand['urlIdentifier']);
            if (empty($cs)) {
                $this->logger(' !!! This Brand\'s all categorys is empty:'.$brand['urlIdentifier']."\n");
                continue;
            }
            $ss['device'] = "desktop";
	    $ss['fl'] = "t0";
            $ss['includeLooks'] = "true";
            $ss['includeProducts'] = "true";
            $ss['limit'] = 40;
            $ss['locales'] = "all";//"en_US";
            $ss['maxNumFilters'] = 1000;
	    $ss['numLooks'] = "20";
            $ss['pid'] = "shopstyle";
            $ss['productScore'] = "LessPopularityEPC";
            $ss['view'] = "angular";
            $sum = count($cs);
            $this->logger('Brand is '.$brand['name'].', Category Sum:'.$sum."\n"); // info
    	    $config = dirname(__FILE__) .'/../common/page/config_'.$brand['urlIdentifier'];
            for ($i=0;$i<$sum;$i++) {
		$startTime = time();
    	        $this->logger('The '.$i.'th category of '.$sum.' is going to get at:'.$startTime."\n"); // order info
    	        $cate = $this->find_category($cs[$i], $brand['urlIdentifier'], $category);
    	        if (!$cate) {
    		    continue;
    	        }
                $ss['cat'] = $cate['category'][0]['name'];
                $ss['prevCat'] = $cate['category'][1]['name'];
                $ss['url']     = $cate['url'];
		$cate_name = array_column($cate['category'], 'name');
                $cateUrl = implode('/', $cate_name);
                $this->logger('CATEGORY/BRAND:/'.$cateUrl.'/'.$cate['prevCat'].'/'.$brand['name']."\n"); 
                $pg_offset = 0;
                $maxPage = floor($cs[$i]['products_sum']/$ss['limit']);
		$fl = '';
                for($page=0; $page<=$maxPage; $page++){
                    $proxy = $this->get_proxy();
                    $ctx = stream_context_create(array(
                    	'http' => array(
                            'method' => 'GET',
                            'proxy' => 'tcp://'.$proxy['ip'].':'.$proxy['port'],
                            'request_fulluri' => True,
                            'timeout' => 10
                    	)
                    ));
		    $ss['offset'] = $page * $ss['limit'];
		    $url = "http://www.shopstyle.com/api/v2/products?" . http_build_query($ss);
		    $this->logger('PAGE:'.$page.', URL:'.$url."\n"); //debug
                    $json = @file_get_contents($url, false, $ctx);
                    $data = json_decode($json, true);
		    if (!isset($data['metadata'])) {
			$wait = 5;
			for ($x=2;$x<7;$x++) {
			    $this->logger('retry to get Json file, the '.$x.'th time!'."\n"); //debug
			    sleep($wait++);
			    $proxy = $this->get_proxy();
                            $ctx = stream_context_create(array(
                            	'http' => array(
                                    'method' => 'GET',
                                    'proxy' => 'tcp://'.$proxy['ip'].':'.$proxy['port'],
                                    'request_fulluri' => True,
                                    'timeout' => 30
                            	)
                            ));
			    $json = @file_get_contents($url, false, $ctx);
			    if (!empty($json)) {
			    	$data = json_decode($json, true);
				if (isset($data['metadata'])) {
				    break;
				}
			    }
			}
	 	    }
                    if( isset($data['metadata']) ){
                        if($data['metadata']['offset']>0 && $data['metadata']['offset']==$pg_offset){
                            $this->logger('Get the END.'."\n"); // info
                            break;
                        }
                        $pg_offset =$data['metadata']['offset'];
                        $maxPage = ceil($data['metadata']['total']/$ss['limit'])-1;
			$this->logger('Total:'.$data['metadata']['total']."\n"); //debug
                    }else{
                        $this->logger('Json doesn\'t have metadata twice.'."\n"); // info
                    }
                    if (isset($data['products'])) {
                        $catePage = $i + 1;
                        $liPage = $page + 1;
                        if ( count($data['products']) == 0) {
                            if ($page < $maxPage) {
                                file_put_contents($config, $i.' '.$liPage);
                                $this->logger('Products array is empty, continue.'."\n");
                            } else{
                                file_put_contents($config, $catePage.' 0');
                                $this->count_spider_work($brand['urlIdentifier'].'_'.$cate['prevCat'], $cate['category'], $data['metadata']['total']);
                                $this->logger('Products array is empty, return to 0.'."\n");
                                break;
                            }
                        } else {
                            if ($page == $maxPage) {
                                file_put_contents($config, $catePage.' 0');
                                $this->count_spider_work($brand['urlIdentifier'].'_'.$cate['prevCat'], $cate['category'], $data['metadata']['total']);
                                $this->logger('The last page, now return to 0.'."\n");
                            } else {
                                file_put_contents($config, $i.' '.$liPage);
                            }
                        }
                        $this->ex_data($data['products'], $brand, $cate['category'], $mapping, $map, $cfg);
                    }
                    usleep(100000);
                }
		$cate_id = end($cate['category'])['id'];
    	        $this->off_shelve($startTime, $this->brand_id, $cate_id);
		$this->logger('Off_shelve is done'."\n");
            }
    	    $this->logger('EndTimeStamp:'.time()."\n", 'Time', 'time_log');
        } while (true);
    }
    function ex_data($data, $brand, $pname, $mapping, $map, $config){
	require_once(dirname(__FILE__).'/../common/db.php');
        $db = db::getIntance();
        foreach($data AS $d){
            $product_id = $this->ex_product($db, $d, $brand, $pname, $mapping, $map, $config);
            if( is_numeric($product_id) && $product_id>0 ){
                $res = 'OK: '.$product_id ."\n";
            }else{
                $res = '--: '.$product_id ."\n";
            }
            $this->logger($res);
            if( $this->debug ){
                echo $res;
                break;
            }
        }
    }
    function ex_product($db, $d, $brand, $pname, $mapping, $map, $config){
	if (empty($brand['name'])) {
	    return 'brand is empty.';
	}
        $inventory = 10;
        $color_ids = $size_ids = $tag_ids = null;
        $ems = 0;
	if ($d['brand']['urlIdentifier'] == $brand['urlIdentifier'] ) {
            $brand_url = @$d['brand']['logo']['sizes']['default@2x']['url'];
            $author = $this->ex_user($db, $brand['name'], $brand_url);
            if(!$author){
                return ' brand insert to db error.';
            }
        } else {
	    return 'Brand uncorrect!!!';
	}
	$this->brand_id = $author;
        $m_preOwned    = isset($d['preOwned'])   ?$d['preOwned']   ?'true':'false':'';
        if($m_preOwned === 'true'){
            return ' preOwned Product.';
        }
        $pid = (int)$d['id'];
        if($pid<1) {
            return ' sku not defined!';
        }
        $sku = $pid;
        if( ! isset($d['clickUrl']) ){
            return ' url not defined!';
        }
        if( !$this->rate || !$this->tax ){
            return ' rate.tax error.';
        }
        if( $d['currency'] !='USD' ){
            return ' USD not defined!';
        }
	$last_cate = end($pname);
        $tag_ids['category_id'] = $last_cate['id'];
        $tag_ids['cate3'] = $pname[2]['id'];
        $store_id = $this->ex_store($db, $d['retailer']['name']);
        if (!$store_id) {
            return 'Store error';
        }
	$throw = [325];// throw out
	if (in_array($store_id, $throw)) {
	    return 'The store isn\'t in the list';
	} 
        $rt = bcmul($this->rate, $this->tax, 2);
        if( isset($d['salePrice']) && $d['salePrice']>0 ){
	    if (isset($d['maxSalePrice']) && $d['maxSalePrice'] > 0) {
		$price = ceil(bcmul(($d['maxSalePrice'] + $d['salePrice']) / 2, $rt, 2));
	    } else {
            	$price = ceil( bcmul($d['salePrice'], $rt, 2) );
	    }
            $oldprice = ceil( bcmul($d['price'], $rt, 2) );
        }else{
            $price = ceil( bcmul($d['price'], $rt, 2) );
            $oldprice = 0;
        }
        if($price <= 0){
            return ' price not defined!';
        }
	$shippings = $db->getAll("SELECT shipping.* FROM shipping JOIN store ON store.`id` = shipping.`store_id` WHERE store.`id`= '$store_id' AND shipping.`status` = 1");
        $weight = $db->getOne("SELECT weight FROM category WHERE id = '".$last_cate['id']."'");
        $product = array(
            'price' => $price,
            'weight' => $weight > 0 ? $weight : 0,
            'num' => 1,
        );
        $store_mail_fee = $this->getStoreCommondFee($shippings, $product, $config['rates'], $config['weights']);
        if ($store_mail_fee > 0) {
            $pdt_price = $price;
            $price += $store_mail_fee;
            if ($oldprice > 0) {
                $oldprice += $store_mail_fee;
            }
        } else {
           $pdt_price = $price;
        }
        if( is_numeric($d['favoriteCount']) && $d['favoriteCount'] ){
            $favoriteCount = $d['favoriteCount'];
        }else{
            $favoriteCount = 0;
        }
        $p['SeName'] = $d['name'];
        $pkey = 1;
        if( isset($d['image']) ){
            $pi = 'Picture'.$pkey;
            $p['Picture'][$pkey] = $d['image']['sizes']['Best']['url'];
            $pkey = $pkey+1;
        }
        if( isset($d['alternateImages']) ){
            foreach( $d['alternateImages'] AS $ci=>$cs ){
                if ($pkey>4)
                    break;
                $pi = 'Picture'.$pkey;
                $p['Picture'][$pkey] = $cs['sizes']['Best']['url'];
                $pkey = $pkey+1;
            }
        }
        if( isset($d['colors']) ){
            foreach($d['colors'] AS $ci=>$cs){
                $id = $this->ex_color($db, $cs['name']);
                if( $id )
                    $color_ids[] = $id;
            }
        }
        if( isset($d['sizes']) ){
            foreach($d['sizes'] AS $ci=>$cs){
                $id = $this->ex_size($db, $cs['name']);
                if( $id )
                    $size_ids[] = $id;
            }
        }
        $p['Name'] = $d['name'];
        $p['ShortDescription'] = $d['description'];
        $p['FullDescription'] = $d['description'];
        $p['SKU'] = $sku;
        $p['AdditionalShippingCharge'] = $ems;
        $m_url         = $d['clickUrl'];
        $m_hasFavorite = isset($d['hasFavorite'])?$d['hasFavorite']?'true':'false':'';
        $m_rental      = isset($d['rental'])     ?$d['rental']     ?'true':'false':'';
        $m_pro         = '';
        if( isset($d['promotionalDeal']) ){
            $pro['type']       = $d['promotionalDeal']['type'];
            $pro['typeLabel']  = $d['promotionalDeal']['typeLabel'];
            $pro['title']      = $d['promotionalDeal']['title'];
            $pro['shortTitle'] = $d['promotionalDeal']['shortTitle'];
            $m_pro = json_encode($pro);
        }
        $title       = $this->translator($p['Name'], $map);
        $title = empty($title) ? $p['Name'] : $title;
        $desc['k'][] = '描述';
        $desc['v'][] = addslashes(strip_tags($p['FullDescription']));
        if( $price<=500 ){
            $tagname = '< 500';
        }elseif( $price>500  && $price<=1000 ){
            $tagname = '500 - 1000';
        }elseif( $price>1000 && $price<=1500 ){
            $tagname = '1000 - 1500';
        }elseif( $price>1500 && $price<=2000 ){
            $tagname = '1500 - 2000';
        }elseif( $price>2000 && $price<=3000 ){
            $tagname = '2000 - 3000';
        }else{
            $tagname = '> 3000';
        }
        $id = $this->ex_tag($db, $tagname);
        if( $id ) $tag_ids['price_id'] = $id;
        $tag_ids['brand_id'] = $author;
        if (!is_array($pname)) {
            return ' cate info error.';
        }
        $tmp = Array();
	    if (isset($d['retailer'])) {
            $tmp['jurl'] = $d['retailer']['name'];
        }
        $tmp_img = json_encode($tmp);
	$tmp_img = addslashes($tmp_img);
        $data = array(
            'author' => $author,
            'title' =>  addslashes($title),
            'create_time' => date('Y-m-d H:i:s'),
            'sell_type' => 2,
        );
        $dr = $this->getDiscount($price, $oldprice);
        if ($dr <= 0.3) {
            $tagname = '<=3';
        } elseif (0.3 < $dr && $dr <= 0.5) {
            $tagname = '3-5';
        } elseif (0.5 < $dr && $dr <= 0.7) {
            $tagname = '5-7';
        } else {
            $tagname = '>=7';
        }
        $discount_id = $this->ex_discount($db, $tagname);
        if( $discount_id ) $tag_ids['discount_id'] = $discount_id;
	$property = $this->find_property($d['description'], $p['SKU']);
        $data2 = array(
            'product_id'  => $id,
            'rt'          => 'json',
            'status'      => 1, //Publish
            'lover_count' => $favoriteCount,
            'author'      => (int)$author,
            'title'       => addslashes($title),
            'price'       => $price,
            'presale_price' => $oldprice,
            'inventory'   => $inventory,
            'category'    => (int)$last_cate['id'],
            'tag_ids'     => '',
            'size_ids'    => '',
            'color_ids'   => '',
            'name'        => '',
            'image'       => '',
            'album_id'    => '',
            'type'        => 2,
            'sell_type'   => 2,
            'content'     => '',
            'cover_image' => '',
            'desc_title'  => $desc['k'],
            'desc_content'=> $desc['v'],
            'rank'        => time(),
            'm_sku'             =>$p['SKU'],
            'm_url'             =>$m_url,
            'm_promotionalDeal' =>$m_pro,
            'm_hasFavorite'     =>$m_hasFavorite,
            'm_rental'          =>$m_rental,
            'm_preOwned'        =>$m_preOwned,
            'tmp_img'           =>$tmp_img,
	    'store'      => $store_id,
	    'property'   => $property,
            'publish_time'      =>date('Y-m-d H:i:s'),
            'presale_end_time'  =>time(),
            'discount' => $dr,
	    'store_name' => $d['retailer']['name'],
	    'locale' => $d['locale'],   
	    'position' => 0,
	    'including_mfee' => $store_mail_fee,
            'pdt_price' => $pdt_price,
        );
        $id = $this->create($db, $data, $sku);
        $tag_ids['pid'] = @$id['id'];
        if( is_array($id) && !empty($id['id']) ){
	    if (isset($id['price']) && ($title == $id['title']) && ($price < $id['price'])) {
                $msg = ['id' => $id['id'], 'price' => $price, 'presale_price' => $id['price'], 'type' => 2];
                $send_url = "http://10.26.95.72/index.php/note?" . http_build_query($msg);
                $this->curl_note($send_url);
            }
	    $data2['last_price'] = $id['price'];
            $data2['cover_image'] = $this->ex_album($db, $id['id'], $p['Picture']);
	    if ($id['position'] == 0) {
		$data2['position_at'] = time();
	    }
	    if ($id['position'] > 0) {
		if (((int)$id['position_at'] + 7 * 86400) <= time()) {
		    $data2['position'] = 0;
		    $data2['position_at'] = time();
		} else {
		    unset($data2['position']);
		}
	    }
            $up = $this->update($db, $id['id'], $data2, $size_ids, $tag_ids, $color_ids, $brand, $pname, $mapping);
        }else{
            return ' product insert to db error.';
        }
        if( $up===true ){
            return $id['id'];
        }else{
            return $up;
        }
    }
    function curl_note($url)
    {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	$output = curl_exec($ch);
	curl_close($ch);
    }
    function createDoc($id, $data)
    {
	$data['create_time'] = time();
	$create_data = array(
            'type' => 'create',
            'condition' => $id,
            'data' => $data
        );
	$retry = 0;
        do {
            $res = $this->curl_es($create_data);
            $res = json_decode($res, true);
            if (is_array($res) && $res['_shards']['failed'] == 0) { 
                $retry = 0;
                $this->logger('Create_es_document: Create Success!'."\n"); //debug
            } else {
                sleep($retry++);
                $this->logger("Create_es_document: Create Failed! Retry $retry time .\n"); //debug
            }
        } while ($retry > 0 && $retry < 10);
    }
    function create($db, $data, $sku){
        $sku = addslashes($sku);
        $one = $db->getRow("SELECT id,price,position,title,position_at FROM product WHERE `m_sku`='$sku'");
        if( $one['id'] > 0 ){
	    $this->createDoc($one['id'], $data);
            return $one;
        }
	(int)$id = $db->insert("product", $data);
	$data['title'] = stripslashes($data['title']);
	$this->createDoc($id, $data);
        return ['id' => $id, 'price' => null, 'position' => 0, 'title' => ''];
    }
    function update($db, $id, $data, $size_ids, $tag_ids, $color_ids, $brand, $pname, $mapping){
        $filter_array = array('title', 'desc_title', 'desc_content', 'category', 'price', 'presale_price', 
            'inventory', 'sell_type', 'presale_minimum', 'presale_maximum', 'presale_days', 'production_days', 
            'cover_image', 'available_color', 'rank','status','m_sku', 'm_url', 'm_promotionalDeal', 'm_hasFavorite',
	    'm_preOwned', 'm_rental', 'tmp_img', 'store','property','author','position','position_at', 'last_price', 'including_mfee', 'pdt_price');
        $up_data = $this->filter_data($data, $filter_array);
        if (array_key_exists('desc_title', $up_data) || array_key_exists('desc_content', $up_data)) {
            $up_data['description'] = $this->desc_encode($up_data['desc_title'], $up_data['desc_content']);
            unset($up_data['desc_title']);
            unset($up_data['desc_content']);
        }
        $up_data['save_time'] = date('Y-m-d H:i:s');
        $up_data['discount'] = $this->getDiscount($up_data['price'], $up_data['presale_price']);
	//$this->logger('Update_SQL:'.var_export($up_data,true)."\n"); //debug
        $up =  $db->update("product",$up_data,"id=$id");
        if(!$up || ($up == -1)){
            return ' update product to db error.';
        }
        $up_data['keywords'] = $brand['name'].' '.$this->findKey($up_data['description'], $mapping);
        $cate_keywords = '';
	    foreach ($pname as $k => $v) {
	        if (!empty($v['chinese_name'])) {
                $cate_keywords .= ' '.$v['chinese_name'];
            }
            if (!empty($v['keywords'])) {
                $cate_keywords .= ' '.$v['keywords'];
            }
            if (!empty($v['name'])) {
                $cate_keywords .= ' '.$v['name'];
            }
	    }
        if (!empty($cate_keywords)) {
            $up_data['keywords'] .= ' '.$cate_keywords;
        }
	if (!empty($brand['chinese_name'])) {
	    $up_data['keywords'] .= ' '.$brand['chinese_name'];
	}	
	$up_data['discount_id'] = (int)$tag_ids['discount_id'];
        $up_data['price_id'] = (int)$tag_ids['price_id'];
	$up_data['cate3'] = (int)$tag_ids['cate3'];
	$up_data['locale'] = $data['locale'];
	$up_data['store_name'] = $data['store_name'];
	$up_data['is_commond'] = 0;
	$up_data['description'] = stripslashes($up_data['description']);
        $up_data['title'] = stripslashes($up_data['title']);
	$up_data['author_nickname'] = $brand['name'];
	$up_data['author_chinese_name'] = $brand['chinese_name'];
	unset($up_data['position_at']);
        $update_data = array(
            'type' => 'update',
            'condition' => $id,
            'data' => $up_data
        );
	//$this->logger('Update_ES:'.var_export($update_data,true)."\n"); //debug
	$retry = 0;
	do {
            $res = $this->curl_es($update_data);
	    $res = json_decode($res, true);
	    if (is_array($res) && $res['_shards']['failed'] == 0) { 
		$retry = 0;
	    	$this->logger('Update_es_result: Update Success!'."\n"); //debug
	    } else {
		sleep($retry++);
	    	$this->logger("Update_es_result: Update Failed! Retry $retry time .\n"); //debug
	    }
	} while ($retry > 0 && $retry < 10);
        if(is_array($size_ids)){
            $size_ids = array_map('intval', $size_ids);
            $where = "product_id='$id' AND size_id not in(". implode(',', $size_ids) .")";
            $db->deleteAll("product_size", $where);

            $vals = array();
            foreach ($size_ids as $size_id) {
                $vals[] = sprintf('(%d,%d)', $id, $size_id);
            }
            $sql = sprintf('INSERT IGNORE INTO `%s` (`product_id`, `size_id`) values %s', 'product_size', implode(',', $vals));
            if( ! $db->query($sql) ){
                return 'size error.';
            }
        }
        if (is_array($tag_ids)) {
            $keys = array_keys($tag_ids);
            $values = array_values($tag_ids);
            $sql = sprintf('INSERT IGNORE INTO `%s` (`%s`) values (%s)', 'product_tags', implode('`,`', $keys), implode(',', $values));
            if (!$db->query($sql)) {
                return 'tag error.';
            }
        }
        if(is_array($color_ids)){
            $color_ids = array_map('intval', $color_ids);
            $where = "product_id='$id' AND color_id not in (". implode(',', $color_ids) .")";
            $db->deleteAll("product_color", $where);
            $vals = array();
            foreach ($color_ids as $color_id) {
                $vals[] = sprintf('(%d,%d)', $id, $color_id);
            }
            $sql = sprintf('INSERT IGNORE INTO `%s` (`product_id`, `color_id`) values %s', 'product_color', implode(',', $vals));
            if( ! $db->query($sql) ){
                return 'color error.';
            }
        }
        return true;
    }
    function ex_album($db, $id, $imgs)
    {
        $position = 0;
        $cover_img = '';
        $images = $db->getAll("SELECT id,content,url,position FROM product_album WHERE `product_id`='$id' ORDER BY position ASC");
        if(is_array($images) && count($images)){
	    $old_imgs = [];
	    $album = [];
	    $img_sum = count($imgs);
	    foreach ($images as $k => $v) {
		$sum = 1;
		foreach ($imgs as $m => $n) {
		    if ($v['url'] != $n) {
			if ($sum == $img_sum) {
			    $old_imgs[] = $v; 
			}
			$sum++;
		    } else {
			$album[] = $v;
			break;
		    }
	  	}
	    }
	    $img_sum = count($images);
	    foreach ($imgs as $m => $n) {
		$sum = 1;
	        foreach ($images as $k => $v) {
		    if ($n != $v['url']) {
			if ($sum == $img_sum) {
			    $album[] = array(
				'id'       => 0,
				'content'  => '',
				'url'      => $n,
				'position' => 0,
			    );
			}
		 	$sum++;
		    } else {
			if ($m == 0) {
			    $cover_img = $v['content'];
			}
			break;
		    }
	   	}
	    }
	    if (is_array($old_imgs) && count($old_imgs)) {
		$oldids = array_column($old_imgs, 'id');	
                $db->deleteWhereIn("product_album", 'id', $oldids);
		$objects = array();
            	foreach ($old_imgs as $k => $v) {
                    $filename = strchr($v['content'], 'album/');
                    if (!empty($filename)) {
                    	$objects[$k] = $filename;
                    }
            	}
		if (count($objects)) {
                    $this->oss->oss_objects_del($objects);
		}
	    }
	    $backup = '';
	    if (is_array($album) && count($album)) {
		foreach ($album as $k => $v) {
		    if (empty($v['content']) || !strpos($v['content'], 'product-album-n.oss-cn-hangzhou.aliyuncs.com')) {
			$ossurl = $this->up_img($v['url']);
			if (!$ossurl) {
			    continue;
			}
			if (empty($cover_img)) {
			    $cover_img = $ossurl;
			}
			$albums = array();
            		$albums['content']    = $ossurl;
            		$albums['type']       = 1;
            		$albums['position']   = $position;
            		$albums['product_id'] = $id;
            		$albums['url'] = $v['url'];
            		(int)$aid = $db->insert("product_album", $albums);
            		if( $aid ){
                	    $position++;
            		}
		    } else {
			if (empty($backup)) {
			    $backup = $v['content'];
			}
		    }
		}
            }
	    if (empty($cover_img)) {
		$cover_img = $backup;
	    }
        } else {
            foreach($imgs AS $img){
            	if( !$img ) continue;
            	$ossurl = $this->up_img($img);  // close 
		if (!$ossurl) {
		    continue;
		}
	    	if( $cover_img=='' ){
                    $cover_img = $ossurl;
            	}
            	$album = array();
            	$album['content']    = $ossurl;
            	$album['type']       = 1;
            	$album['position']   = $position;
            	$album['product_id'] = $id;
            	$album['url'] = $img;
            	(int)$aid = $db->insert("product_album", $album);
            	if( $aid ){
                    $position++;
                }
        	}
	}
        return $cover_img;
    }
    function ex_color($db, $name){
        $name = addslashes($name);
        $one = $db->getOne("SELECT color_id FROM color WHERE `name`='$name'");
        if( $one ){
            return $one;
        }else{
            $data['name'] = $name;
            $data['author'] = 0;
            return (int)$id = $db->insert("color", $data);
        }
    }
    function ex_size($db, $name){
        $name = addslashes($name);
        $name = strtoupper($name);
        $one = $db->getOne("SELECT size_id FROM size WHERE `name`='$name'");
        if( $one ){
            return $one;
        }else{
            $data['name'] = $name;
            return (int)$id = $db->insert("size", $data);
        }
    }
    function ex_tag($db, $name){
        $name = addslashes($name);
        $name = strtoupper($name);
        $one = $db->getOne("SELECT id FROM tags WHERE `name`='$name'");
        if( $one ){
            return $one;
        }else{
            $data['name'] = $name;
            return (int)$id = $db->insert("tags", $data);
        }
    }
    function ex_cate($db, $name, $pname){
        $pname = addslashes($pname);
        $name  = addslashes($name);
        $name  = strtoupper($name);
        $one = $db->getOne("SELECT id FROM category WHERE `name`='$name' AND parent_id>'0'");
        if( $one ){
            return $one;
        }else{
            $pone = $db->getOne("SELECT id FROM category WHERE `name`='$pname' AND parent_id>'1'");
            if( ! $pone ){
                $d2['name'] = $pname;
                $d2['parent_id'] = 0;
                $pone = $db->insert("category", $d2);
            }
            $data['name'] = $name;
            $data['parent_id'] = $pone;
            return (int)$id = $db->insert("category", $data);
        }
        return false;
    }
    function ex_user($db, $name, $url){
        $new_name = addslashes($name);
        $new_name = str_replace(' ', '', $new_name);
        if (empty($new_name)) {
            return false;
        }
        $username = $new_name . '@data.st';
        $one = $db->getOne("SELECT userid FROM user WHERE `username`='$username'");
        if( $one ){
            return $one;
        }else{
            $facepic = 'http://product-album.img-cn-hangzhou.aliyuncs.com/avatar/default_avatar.png';
            if( $url ){
                $ossurl = $this->up_img($url, 'avatar');
                if( $ossurl )
                    $facepic = $ossurl;
            }
            $data['username'] = $username;
            $data['usertype'] = 2;
            $data['password'] = md5('Sjs#2016#' . '&*xc_@12');
            $data['regtime']  = date('Y-m-d H:i:s');
            $data['facepic']  = $facepic;
            $data['boost']    = ''; //权重
            $data['nickname'] = $name;
            return (int)$id = $db->insert("user", $data);
        }
        return false;
    }
    function filter_data($data, $filter_rule = NULL, $permit_null = true) {
        if ($filter_rule == NULL) {
            return $data;
        }
        $_res = array();
        foreach ($filter_rule as $key) {
            if (array_key_exists($key, $data)) {
                if(!$permit_null && $data[$key]==''){  //Don't use empty . It may be 0 sometimes;
                    continue;
                }
                $_res [$key] = $data [$key];
            }
        }
        return $_res;
    }
    function desc_encode($desc_title, $desc_content)
    {
        $title_seperator = '[__T__]';
        $content_seperator = '[__C__]';
        $data = array();
        foreach ($desc_title as $key => $row) {
            $tarr = $title_seperator . $row . $content_seperator . $this->element($key, $desc_content, '');
            $data[] = $tarr;
        }
        return implode($data);
    }
    function element($item, $array, $default = FALSE)
    {
        if ( ! isset($array[$item]) OR $array[$item] == "")
        {
            return $default;
        }
        return $array[$item];
    }
    function ex_sql($str){
        $str = str_replace("&", "&amp;",$str);
        $str = str_replace("<", "&lt;" ,$str);
        $str = str_replace(">", "&gt;" ,$str);
        if ( get_magic_quotes_gpc() ) {
            $str = str_replace("\\\"", "&quot;",$str);
            $str = str_replace("\\''", "&#039;",$str);
        } else {
            $str = str_replace("\"", "&quot;",$str);
            $str = str_replace("'", "&#039;",$str);
        }
        return $str;
    }
    function up_img($url, $type='album'){
	$this->logger('up_img'."\n"); //debug
        $uArr = explode('/', $url);
        $uArr = explode('.', $uArr[count($uArr)-1]);
        if( count($uArr)>1 ){
            $ext = $uArr[count($uArr)-1];
        }else{
            $ext = '';
        }
        $ossfile = $type.'/Y16'.md5($url) . ($ext?'.'.$ext:'');
	$this->logger('start_to_upload_image...'."\n"); //debug
        $proxy = $this->get_proxy();
        $info = $this->oss->oss_up($url, $ossfile, $proxy['ip'].':'.$proxy['port']);
        if( isset($info['url']) ){
	    $this->logger('upload_image_success!'."\n"); //debug
            return $info['url'];
        }
	$this->logger('upload_image_fail!'."\n"); //debug
        return false;
    }
    function getDiscount($price, $oldprice,$num = 2)
    {
        $discount = 1;
        if ($oldprice >0) {
            $discount = round($price/$oldprice, $num); // 保留两位小数;
        }
        return $discount;
    }
    function ex_discount($db, $name){
        $name = addslashes($name);
        $name = strtoupper($name);
        $one = $db->getOne("SELECT id FROM discount_tags WHERE `name`='$name'");
        if( $one ){
            return $one;
        }else{
            $data['name'] = $name;
            return (int)$id = $db->insert("discount_tags", $data);
        }
    }
    function findKey ($des, $mapping) {
    	$p = [".",",",":",";","!"," ","'",'"'];
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
    function ex_store($db, $store)
    {
        $name = addslashes($store);
        $name = str_replace(' ', '', $name);
	    $name = rtrim(rtrim($name, '.com'),'.COM');
        $id = $db->getOne("SELECT id FROM store WHERE `name`='$name'");
        if ($id) {
            return $id;
        } else {
            $data['name'] = $name;
            $data['show_name'] = $store;
            return (int)$id = $db->insert("store", $data);
        }
        return false;
    }
    function find_property($des, $number)
    {
        $property = array(
            0 => array(
                'name' => '商品编号',
                'value' => $number,
            ),
        );
        // material
        preg_match_all('/(virgin|Virgin|VIRGIN)\s{0,2}[A-Za-z]+\s{0,2}[1-9][0-9]{0,2}%/', $des, $material);
        preg_match_all('/(other|OTHER|Other)\s{0,2}[A-Za-z]+\s{0,2}[1-9][0-9]{0,2}%/', $des, $material1);
        preg_match_all('/[1-9][0-9]{0,2}%\s{0,2}(virgin|Virgin|VIRGIN)\s{0,2}[A-Za-z]+/', $des, $material2);
        preg_match_all('/[1-9][0-9]{0,2}%\s{0,2}(other|OTHER|Other)\s{0,2}[A-Za-z]+/', $des, $material3);
        preg_match_all('/[1-9][0-9]{0,2}%\s{0,2}[A-Za-z]+/', $des, $material4);
        preg_match_all('/[A-Za-z]+\s{0,2}[1-9][0-9]{0,2}%/', $des, $material5);
        $material[0] = array_merge($material[0], $material1[0], $material2[0], $material3[0], $material4[0], $material5[0]);
        $materials = array(
            'virgin wool' => '初剪羊毛',
            'other fibers' => '其他纤维',
            'leather' => '真皮',
            'suede' => '真皮',
            'cashmere' => '羊绒',
            'wool' => '羊毛',
            'cotton' => '棉',
            'elastane' => '氨纶',
            'acrylic' => '腈纶',
            'polyester' => '涤纶',
            'nylon' => '尼龙',
            'viscose' => '人造棉(粘纤)',
            'polyamide' => '锦纶',
            'silk' => '丝',
            'Lycra' => '莱卡',
            'PU' => '仿皮',
            'linen' => '麻',
            'fur' => '皮草',
            'shea butter' => '乳木果油',
            'spandex' => '氨纶（高弹纤维）',
            'rayon' => '人造丝',
            'Triacetate' => '醋脂纤维',
            'acetate' => '醋脂纤维',
            'rhinestone' => '水钻',
            'mohair' => '安哥拉山羊毛',
            'alpaca' => '驼羊毛',
            'mink' => '水貂毛',
            'Chiffon' => '雪纺',
            'rhinestone' => '水钻',
            'rabbit' => '兔毛',
            'fleece' => '抓绒',
            'modal' => '天然纤维',
    	);
        if (empty($material[0])) {
                $material = $this->findKey($des, $materials);
        } else {
            $keys = array_keys($materials);
            $material = array_unique($material[0]);
            foreach ($material as $k => $v) {
                foreach ($keys as $m => $n) {
                    $pos = stripos($v, $n);
                    if ($pos || ($pos === 0)) {
                        $string = str_ireplace($n, $materials[$n], $v);
                        $material[$k] = $string;
                        break;
                    } else {
                    	unset($material[$k]);
                    }
                }
            }
            $material = implode(',', $material);
        }
        if (!empty($material)) {
            $property[] = array(
                    'name' => '材质',
                    'value' => $material,
            );
        }
        // Width,Height,depth, mL
        preg_match_all('/[A-Za-z]{2,10} [1-9][0-9]{0,3}.[0-9]{0,2}\s{0,2}cm/', $des, $specs);
        preg_match_all('/[A-Za-z]{2,10}\s{0,2}[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}("|inch|in)/', $des, $specs1);
        preg_match_all('/[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}("|inch|in)\s{0,2}[A-Za-z]{1,10}/', $des, $specs2);
        preg_match_all('/[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}("|inch|in)\s{0,2}[A-Za-z]{1,10}/', $des, $specs3);
        preg_match_all('/[1-9][0-9]{0,3}.{0.1}[0-9]{0,2} m(l|L)/', $des, $specs4);
        preg_match_all('/[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}(oz.|fl. oz.)/', $des, $specs5);
        preg_match_all('/Lens measures approx\s{0,2}[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}(mm|"|in|inch)/', $des, $specs6);
        preg_match_all('/Bridge measures approx\s{0,2}[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}(mm|"|in|inch)/', $des, $specs7);
        preg_match_all('/Arm measures approx\s{0,2}[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}(mm|"|in|inch)/', $des, $specs8);
        preg_match_all('/Frame\s{0,2}[A-Za-z]+\s{0,2}[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}(mm|"|in|inch)/', $des, $specs9);
        preg_match_all('/Arm\s{0,2}[A-Za-z]+\s{0,2}[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}(mm|"|in|inch)/', $des, $specs10);
        preg_match_all('/Bridge\s{0,2}[A-Za-z]+\s{0,2}[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}(mm|"|in|inch)/', $des, $specs11);
        preg_match_all('/Lens\s{0,2}[A-Za-z]+\s{0,2}[1-9][0-9]{0,3}.{0,1}[0-9]{0,2}(mm|"|in|inch)/', $des, $specs12);
        $specs[0] = array_merge($specs[0], $specs1[0], $specs2[0], $specs3[0], $specs4[0], $specs5[0], $specs6[0], $specs7[0], $specs8[0]);
        $specs[0] = array_merge($specs[0], $specs9[0], $specs10[0], $specs11[0], $specs12[0]);
        if (!empty($specs[0])) {
            $specs = array_unique($specs[0]);
            $spec = array(
            	'Lens measures approx' => '镜片宽约',
            	'Bridge measures approx' => '鼻梁宽约',
            	'Arm measures approx' => '镜腿长约',
            	'Brim measures approx' => '镜面宽约',
            	'Lens Width' => '镜片宽',
            	'Bridge Width' => '鼻梁宽',
            	'Arm length' => '镜腿长',
            	'Temple length' => '镜腿长',
            	'Frame Width' => '镜框宽',
            	'Frame Height' => '镜框高',
            	'Frame Length' => '镜框长',
            	'Lens' => '镜片',
            	'Bridge' => '鼻梁',
            	'Arm' => '镜腿',
            	'Temple' => '镜腿',
            	'Frame' => '镜框',
                'length' => '长',
                'width' => '宽',
                'Height' => '高',
                'depth' => '深',
                'ml' => '毫升',
                'oz' => '盎司',
                'fl.oz' => '液量盎司',
                'inch' => '英寸',
                '"' => '英寸',
                'in' => '英寸',
                'H' => '高',
                'W' => '宽',
                'D' => '深',
                'L' => '长',
            );
            $keys = array_keys($spec);
            foreach ($specs as $k => $v) {
            	foreach ($keys as $m => $n) {
                    $pos = stripos($v, $n);
                    if ($pos || ($pos === 0)) {
                        $v = str_ireplace($n, $spec[$n], $v);
                        break;
                    }
                }
                $specs[$k] = $v;
            }
            $specs = implode(',', $specs);
            if (!empty($specs)){
                $property[] = array(
                    'name' => '规格',
                    'value' => str_ireplace('"', '英寸', $specs),
                );
            }
        }
        // feature
        $mapping = array(
            'Dyed' => '扎染/染色',
            'Print' => '印花',
            'Embroider' => '绣花/刺绣',
            'Regular Fit' => '标准版型',
            'Loose Fit' => '宽松版型',
            'Tight Fit' => '紧身版型',
            'Skinny Fit' => '贴身版型',
            'Lace' => '蕾丝',
            'Woven' => '梭织',
        );
        $feature = $this->findKey($des, $mapping);
        if (!empty($feature)){
            $property[] = array(
                'name' => '特性',
                'value' => $feature,
            );
        }

        $property = json_encode($property);
        $property = addslashes($property);

        return $property;
    }

    private function getStoreCommondFee($shippings, $product, $rates, $weights)
    {
        foreach ($shippings as $x => $y) {
            $rate = $rates[$y['currency']];
            if ($y['type'] == 1) {
            	$direct_fee = $this->getStoreFee($y, $product, $rate, $weights); // direct mail Store fee
            }
            if ($y['type'] >= 2) {
            	$fee = $this->getStoreFee($y, $product, $rate, $weights);
                if (isset($fee)) {
                    return ceil($fee);
                }
            }
        }
        if (isset($direct_fee)) {
            return ceil($direct_fee);
    	}

        return 0;
    }

    private function getStoreFee($y, $product, $rate, $weights)
    {
        if ($y['count_type'] == 3) {
            $wei = $weights[$y['count_unit']];
            if ((($y['high'] == 0) && (($product['weight']*$wei) >= $y['low'])) || (($y['high'] != 0) && (($product['weight']*$wei) >= $y['low']) && (($product['weight']*$wei) < $y['high']))) {
            	$store_fee  = $rate*$y['low_fee'];
            }
        } elseif ($y['count_type'] == 2) {
            if ((($y['high'] == 0) && ($product['num'] >= $y['low'])) || (($y['high'] != 0) && ($product['num'] >= $y['low']) && ($product['num'] <= $y['high']))) {
            	$store_fee  = $rate*($y['base_fee'] + $y['low_fee'] * ($product['num'] - $y['low']));
            }
        } else {
            if ((($y['high'] == 0) && ($product['price'] >= ($rate*$y['low']))) || (($y['high'] != 0) && ($product['price'] >= ($rate*$y['low'])) && ($product['price'] < ($rate*$y['high'])))) {
                $store_fee  = $rate*$y['low_fee'];
            }
        }
        if (isset($store_fee)) {
        	return ceil($store_fee);
        }

        return 0;
    }
}
?>


