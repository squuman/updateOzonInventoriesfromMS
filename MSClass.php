<?php
header('Content-Type: text/html; charset=utf-8');

use Gam6itko\OzonSeller\Service\V1\CategoriesService;
use Gam6itko\OzonSeller\Service\V1\ProductService;
use GuzzleHttp\Client as GuzzleClient;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;

require_once(__DIR__ . '/vendor/autoload.php');

class MSClass
{
    private $ms_login = 'admin@login';
    private $ms_password = 'pass';
    private $ozonConfig = [
        'clientId' => 'id',
        'apiKey' => 'key',
        'host' => 'https://api-seller.ozon.ru'
    ];
    private $arStocks = [
        'https://online.moysklad.ru/api/remap/1.1/entity/store/0761dcb7-e5a1-11e7-7a69-9711001254ce' => 'Склад в Финляндии',
        'https://online.moysklad.ru/api/remap/1.1/entity/store/a8639f91-3794-11ea-0a80-068b0009e1eb' => 'Товар не на маркете',
        'https://online.moysklad.ru/api/remap/1.1/entity/store/f35401f7-2116-11e7-7a69-9711001158e3' => 'ТРК "Континент" Основной склад'
    ];

    private $api;

    public function __construct()
    {
        $this->api = new GuzzleAdapter(new GuzzleClient());
    }


    public function requestMS($url = '', $data = array(), $method = 'GET')
    {
        if ($url == '') {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);

        $headers = array('Authorization: Basic ' . base64_encode($this->ms_login . ':' . $this->ms_password), 'Content-Type: application/json');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method == 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        }

        $result = curl_exec($ch);
        if ($result === false) {
            print_r('Curl error: ' . curl_error($ch));
            return false;
        }

        $result = json_decode($result, true);
        curl_close($ch);

        time_nanosleep(0, 250000000);

        return $result;
    }


    private function getInventoriesMS()
    {
        /* /Остатки */
        $page = 0;
        $limitInPage = 1000;
        $stockData = [];
        do {
            $result = $this->requestMS('https://online.moysklad.ru/api/remap/1.1/report/stock/bystore?' . http_build_query(array(
                    'limit' => $limitInPage,
                    'offset' => $limitInPage * $page,
                )));


            foreach ($result['rows'] as $product) {
                if ($product['meta']['type'] != 'product' && $product['meta']['type'] != 'variant') {
                    continue;
                }
                $inventories = 0;
                foreach ($product['stockByStore'] as $stock) {
                    if (!isset($this->arStocks[$stock['meta']['href']])) {
                        continue;
                    }

                    $inventories += ($stock['stock'] - $stock['reserve'] > 0 ? $stock['stock'] - $stock['reserve'] : 0);
                }

                $href = explode('?expand', $product['meta']['href'], 2)[0];
                $stockData[$href] = $inventories;
            }

            if (isset($result['errors'][0]['error'])) {
                $this->log('bystore ' . $page . '|' . (isset($result['errors'][0]['error']) ? $result['errors'][0]['error'] : ''), 'inventories-log.log');
            }


            $page++;

        } while (!empty($result['rows']));
        /* Остатки/ */

        $page = 0;//!!!!!!!!!
        $limitInPage = 100;
        $skladItems = [];

        do {
            $products = $this->requestMS('https://online.moysklad.ru/api/remap/1.1/entity/assortment?' . http_build_query(array(
                    'limit' => $limitInPage,
                    'offset' => $limitInPage * $page,
                    'scope' => 'variant',
                    'expand' => 'product',
                )));

            foreach ($products['rows'] as $product) {
                if ($product['meta']['type'] != 'product' && $product['meta']['type'] != 'variant') {
                    continue;
                }

                if (!isset($product['externalCode']) || empty($product['externalCode'])) {
                    continue;
                }

                $article = isset($product['article']) ? $product['article'] : $product['product']['article'];
                $href = explode('?expand', $product['meta']['href'], 2)[0];
                $stores = [];
                if (isset($stockData[$href])) {
                    $stores = $stockData[$href];

                    $skladItems[] = [
                        'article' => $article,
                        'stores' => $stores,
                    ];
                }
            }

            if (isset($products['errors'][0]['error'])) {
                $this->log('assortment ' . $page . '|' . (isset($products['errors'][0]['error']) ? $products['errors'][0]['error'] : ''), 'inventories-log.log');
            }

            $page++;

        } while (!empty($products['rows']));

        return $skladItems;
    }

    public function updateInventories()
    {
        echo '<pre>';

        $fp = fopen(__DIR__ . "/file.lock", "w+");
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            $this->log('Start update...', 'inventories-log.log');
			$skladItems = $this->getInventoriesMS();
            $stocks = [];
            foreach ($skladItems as $Ind => $data) {
                try {
                    $itemExists = $this->itemExists($data['article']);
                    if (!$itemExists)
                        continue;
                    $stocks[] = [
                        'product_id' => $itemExists['id'],
                        'stock' => $data['stores']
                    ];
                } catch (\RetailCrm\Exception\CurlException $e) {
                    $this->log('Curl error: ' . $e->getMessage(), 'inventories-log.log');
                }
            }
            $updateInventoriesOzon = $this->updateInventoriesOzon($stocks);
            $this->log($updateInventoriesOzon,'ozonInventoriesUpdate.log');
            $this->log('End update.', 'inventories-log.log');

            flock($fp, LOCK_UN);
        } else {
            echo 'lock';
        }

        fclose($fp);

        echo '</pre>';
    }


    private function log($data = array(), $file = 'ms.log')
    {
        file_put_contents(__DIR__ . '/' . $file, '[' . date('Y-m-d H:i:s', time()) . '] ' . print_r($data, true) . PHP_EOL . '===================================' . PHP_EOL, FILE_APPEND);
    }

    private function itemExists($sku)
    {
        $svc = new ProductService($this->ozonConfig, $this->api);
        try {
            $info = $svc->infoBy([
                'offer_id' => $sku
            ]);

            return $info;
        } catch (Exception $e) {
            $this->log([
                'ERROR' => 'Товар не найден',
                'offer_id' => $sku
            ],'ozonError.log');

            return false;
        }
    }

    private function updateInventoriesOzon($items)
    {
        $svc = new ProductService($this->ozonConfig, $this->api);
        try {
            $updateStocks = $svc->importStocks($items);
            return $updateStocks;
        } catch (Exception $e) {
            $error = [
                'ERROR' => 'Ошибка обновления остатков OZON'
            ];
            $this->log($error,'ozonError.log');
            return $error;
        }
    }

}


?>
