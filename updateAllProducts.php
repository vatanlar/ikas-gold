<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$ikas_shopdomain = "magazam.myikas.com";
$client_id = "";
$client_secret = "";
$attribute_id = "6b5437ed-bad8-44b9-8d4f-493ecfe7b903"; // Gram tabanlı belirlenen özel alana ait id değeri. ( List products'dan gelen json'da bulabilirsiniz. )
$token = false;
$limit = 200; // Product List Page Limit (Max: 200)
$altin_kuru = 1001; // Altın kurunu aldığınız kaynaktan buraya aktarın.

$curl = curl_init();
curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://' . $ikas_shopdomain . '/api/admin/oauth/token',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials&client_id=' . $client_id . '&client_secret=' . $client_secret . '',
    CURLOPT_HTTPHEADER => array(
        'content-type: application/x-www-form-urlencoded'
    ),
));

$response = curl_exec($curl);

if ($response) {
    $response = json_decode($response, true);
    if (isset($response["access_token"])) {
        $token = $response["access_token"];

        // LOG :: Token başarıyla alındı.

    } else {
        // LOG :: Yetkilendirme access_token döndürmedi.
    }
} else {
    // LOG :: Yetkilendirme isteği alınamadı.
}

curl_close($curl);


if ($token) {

    $guncelleme_listesi = array();
    $page = 1;
    $hasNext = true;
    while ($hasNext) {


        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.myikas.com/api/v1/admin/graphql',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '{"query":"query listProducts { listProduct( pagination: {page: ' . $page . ', limit: ' . $limit . '} ) { count limit page hasNext data { id createdAt updatedAt deleted name type shortDescription description salesChannelIds productOptionSetId metaData { id createdAt updatedAt slug pageTitle description targetType targetId redirectTo translations { description locale pageTitle  } } categoryIds tagIds translations { description locale name  } brandId vendorId groupVariantsByVariantTypeId productVariantTypes { order variantTypeId variantValueIds  } variants { id createdAt updatedAt deleted sku barcodeList isActive sellIfOutOfStock weight variantValueIds { variantTypeId variantValueId  } attributes { value productAttributeId productAttributeOptionId } images { order isMain imageId  } prices { sellPrice discountPrice buyPrice currency priceListId  }  } attributes { value productAttributeId productAttributeOptionId } } }}","variables":{}}',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        $page++;
        $response = json_decode($response, true);

        if (isset($response["data"])) {
            $response = $response["data"]["listProduct"];
            $products = $response["data"];

            foreach ($products as $product) {

                $product_gram_fiyati = false;
                $variant_gram_fiyati = false;
                $prod_id = $product["id"];

                if (isset($product["attributes"])) {
                    foreach ($product["attributes"] as $attr) {
                        if ($attr["productAttributeId"] == $attribute_id) {
                            $product_gram_fiyati = $attr["value"];
                        }
                    }
                }

                foreach ($product["variants"] as $variant) {
                    $var_id = $variant["id"];
                    $var_sell_price = $variant["prices"][0]["sellPrice"];
                    $var_disc_price = $variant["prices"][0]["discountPrice"];
                    $discount = round($var_sell_price - $var_disc_price);

                    if (isset($variant["attributes"])) {
                        foreach ($variant["attributes"] as $variant_attr) {
                            $variant_gram_fiyati = false;
                            if ($variant_attr["productAttributeId"] == $attribute_id) {
                                $variant_gram_fiyati = str_replace(",", ".", $variant_attr["value"]);
                            }
                        }
                    }

                    if ($product_gram_fiyati != false) {
                        // Üründe tüm varyantlar için gram fiyatı verilmiş.
                        $guncelleme_listesi[] = array("product_id" => $prod_id, "variant_id" => $var_id, "satis_fiyati" => $altin_kuru * $product_gram_fiyati, "indirimli_fiyat" => ($altin_kuru * $product_gram_fiyati) - $discount, "update" => true);
                    } else {
                        // Üründe gram fiyatı yok varyantta var ise o kullanılacak.
                        if ($variant_gram_fiyati != false) {
                            // Varyantta gram fiyatı var. Bu kullanılacak. 
                            $guncelleme_listesi[] = array("product_id" => $prod_id, "variant_id" => $var_id, "satis_fiyati" => $altin_kuru * $variant_gram_fiyati, "indirimli_fiyat" => ($altin_kuru * $variant_gram_fiyati) - $discount, "update" => true);
                        } else {
                            // Hiçbişey yapılmayacak. Bu varyant için gram fiyatı verilmemiş. TL Fiyatı kalacak.
                            $guncelleme_listesi[] = array("product_id" => $prod_id, "variant_id" => $var_id, "satis_fiyati" => $var_sell_price, "indirimli_fiyat" => $var_disc_price, "update" => false);
                        }
                    }
                }
            }

            if ($response["hasNext"] == false) {
                $hasNext = false;
                // LOG :: İşlem tamamlandı.
            }
        } else {
            $hasNext = false;
            // LOG :: Ürün listesi alınamadı.
        }
    }
}


if ($guncelleme_listesi) {
    // LOG :: Güncelleme başlıyor.
    foreach ($guncelleme_listesi as $variant_update) {

        if ($variant_update["update"] != true) {
            continue;
        }

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.myikas.com/api/v1/admin/graphql',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '{"query":"mutation {saveVariantPrices(input: {  priceListId: null,  variantPriceInputs: [{    productId: \\"' . $variant_update["product_id"] . '\\",    variantId: \\"' . $variant_update["variant_id"] . '\\",    price: {      sellPrice: ' . $variant_update["satis_fiyati"] . ', discountPrice: ' . $variant_update["indirimli_fiyat"] . '    }  }]})\\n   }","variables":{}}',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        echo $response;
    }
}
