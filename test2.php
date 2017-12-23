<?php
$data = array (
   'title' => 'Police Dark Eau de Toilette, 3.4 Ounce',
  'category' => 175,
  'price' => 180,
  'presale_price' => 211,
  'inventory' => 10,
  'sell_type' => 2,
  'cover_image' => 'http://product-album-n.oss-cn-hangzhou.aliyuncs.com/album/Y16c7c4b6f4a6bde8440f899928883f22a4.jpg',
  'rank' => 1512358470,
  'status' => 1,
  'm_sku' => 664534528,
  'm_url' => 'https://www.shopstyle.com/action/loadRetailerProductPage?id=664534528',
  'm_promotionalDeal' => '',
  'm_hasFavorite' => 'false',
  'm_preOwned' => 'false',
  'm_rental' => 'false',
  'tmp_img' => '{\\"jurl\\":\\"Amazon.com\\"}',
  'store' => '22',
  'property' => '[{\\"name\\":\\"\\\\u5546\\\\u54c1\\\\u7f16\\\\u53f7\\",\\"value\\":664534528}]',
  'author' => 354,
  'position' => 0,
  'position_at' => 1512358471,
  'last_price' => '180',
  'including_mfee' => 35,
  'pdt_price' => 145,
  'description' => '[__T__]描述[__C__]Launched by the design house of police in the year 2011. this oriental floral fragrance has a blend of violet leaves, juicy mandarin, bergamot, black currant, opulent, feminine bouquet of gardenia, rose, jasmine, iris, resins, vanilla, cedar, sandalwood, and orchids notes.',
  'save_time' => '2017-12-04 11:34:31',
  'discount' => 0.84999999999999998,
);
require_once(dirname(__FILE__).'/../common/db.php');
$db = db::getIntance();
$up =  $db->update("product",$data,"id=249606", 'sql');
echo $up;die;
if(!$up || ($up == -1)){
    echo ' update product to db error.';
} else {
    echo 'success';
}

