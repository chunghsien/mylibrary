<?php

namespace Chopin\Newwebpay\Service;

use Chopin\Store\Service\ThirdPartyPaymentService;
use Chopin\Newwebpay\Logistics\PayMethods;
use Chopin\Newwebpay\Security;
use Chopin\Newwebpay\Logistics\Logistics;
use Chopin\Support\Registry;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Db\ResultSet\ResultSet;
use Chopin\LaminasDb\TableGateway\AbstractTableGateway;

class NewwebpayService extends ThirdPartyPaymentService
{
    public const VERSION = '1.6';

    protected function setPayMetohds()
    {
        $this->payMethods = new PayMethods();
    }

    protected function setLogistics()
    {
        $this->logistics = new Logistics();
    }

    public function notify(ServerRequest $request, $product_table='products_spec')
    {
        $post = $request->getParsedBody();
        if (! $post) {
            $post = json_decode($request->getBody()->getContents(), true);
        }

        if (strtolower($post['Status']) == 'success') {
            $phpLang = $request->getAttribute('php_lang');
            $config = $this->getConfig('newwebpay', $phpLang);
            $hashKey = $config['hash_key'];
            $hashIV = $config['hash_iv'];

            $tran_info_json_str = Security::create_aes_decrypt($post['TradeInfo'], $hashKey, $hashIV);
            /**
             *
             * @var \Laminas\Db\Adapter\Driver\Pdo\Connection $connection
             */
            $connection = $this->orderDetailTableGateway->getAdapter()->driver->getConnection();
            $connection->beginTransaction();
            try {
                $tran_info_arr = json_decode($tran_info_json_str, true);
                $this->saveNotifyLog($tran_info_arr, $post);
                $_Result = $tran_info_arr['Result'];

                $order_serail = $_Result['MerchantOrderNo'];
                $status = 1;
                if ($_Result['PaymentType'] == 'WEBATM' || $_Result['PaymentType'] == 'VACC') {
                    $status = 2;
                }
                $orderSet = [
                    'status' => $status,
                ];
                if (isset($_Result['CVSCOMName'])) {
                    $orderSet['fullname'] = $_Result['CVSCOMName'];
                }
                if (isset($_Result['CVSCOMPhone'])) {
                    $orderSet['cellphone'] = $_Result['CVSCOMPhone'];
                }
                if (isset($_Result['PayerAccount5Code'])) {
                    $orderSet['tail_number'] = $_Result['PayerAccount5Code'];
                }
                if (isset($tran_info_arr['Message'])) {
                    $orderSet['message'] = $tran_info_arr['Message'];
                }
                $this->orderTableGateway->update($orderSet, ['serial' => $order_serail]);
                $orderRow = $this->orderTableGateway->select(['serial' => $order_serail])->current();

                //修改庫存
                $select = $this->orderDetailTableGateway->getSql()
                ->select()
                ->columns(['id', 'products_id', 'quantity'])
                ->where(['order_id' => $orderRow->id]);
                $dataSource = $this->orderDetailTableGateway->getSql()->prepareStatementForSqlObject($select)->execute();
                $resultSet = new ResultSet();
                $resultSet->initialize($dataSource);
                $product_table = str_replace(AbstractTableGateway::$prefixTable, '', $product_table);
                foreach ($resultSet as $buy) {
                    if ($product_table == 'products') {
                        $productsTableGateway = $this->productsTableGateway;
                    } else {
                        $productsTableGateway = $this->productsSpecTableGateway;
                    }
                    $row = $productsTableGateway->select(['id' => $buy['products_id']])->current();
                    $row->quantity-=intval($buy['quantity']);
                    $row->save();
                }
                $connection->commit();
                unset($resultSet);
            } catch (\Exception $e) {
                $connection->rollback();
                TryCatchTransToLog($e);
                $this->orderDetailTableGateway->update(['status' => -8], ['serial' => $order_serail]);
            }
        } else {
            //交易失敗
            $this->orderDetailTableGateway->update(['status' => -8], ['serial' => $order_serail]);
        }
    }

    protected function setConfigKeys()
    {
        $this->configKeys = [
            'merchant_id',
            'hash_key',
            'hash_iv',
            'return_url',
            'notify_url',
            'customer_url',
            'client_back_url',
        ];
    }
    public function buildMpgForm(ServerRequest $request, $data)
    {
        /**
         *
         * @var ServiceManager $serviceManager
         */
        $serviceManager = Registry::get(ServiceManager::class);
        /**
         *
         * @var \Twig\Environment $twig
         */
        $twig = $serviceManager->get(\Twig\Environment::class);
        $site_info = $this->systemSettingTableGateway->toSerialize(null, 'site_info');
        $_name = $site_info[$this->lang_code]['children']['name']['value'];

        $order_id = $data['id'];
        $orderDetailResultSet = $this->orderDetailTableGateway->select(['order_id' => $order_id]);
        $phpLang = $request->getAttribute('php_lang');
        $config = $this->getConfig('newwebpay', $phpLang);
        $detailCount = $orderDetailResultSet->count();
        $model = $orderDetailResultSet->current()->model;
        //ItemDesc
        $itemDesc = $_name . '('.$model.'...等'.$detailCount.'樣)';
        $notify_url = preg_replace('/^\//', '', $config['notify_url']);
        $return_url =  preg_replace('/^\//', '', $config['return_url']);

        if (isset($config['client_back_url']) && trim($config['client_back_url'])) {
            $client_back_url =  preg_replace('/^\//', '', $config['client_back_url']);
        }
        if (isset($config['customer_url']) && trim($config['customer_url'])) {
            $customer_url =  preg_replace('/^\//', '', $config['customer_url']);
        }
        if (preg_match('/\:order_id/', $return_url)) {
            $return_url = str_replace(':order_id', $order_id, $return_url);
        }

        $language_code = $request->getAttribute('simple_lang_code');
        $tradSource = [
            'MerchantID' => $config['merchant_id'],
            'RespondType' => 'JSON',
            'TimeStamp' => strtotime('now'),
            'Version' => self::VERSION,
            'LangType' => $this->getLangType(),
            'MerchantOrderNo' => $data['serial'],
            'Amt' => $data['total'],
            'ItemDesc' => $itemDesc,
            'ReturnURL' => siteBaseUrl($request->getServerParams(), true).$language_code.'/'.$return_url,
            'NotifyURL' => siteBaseUrl($request->getServerParams(), true).$language_code.'/'.$notify_url,
        ];

        if (isset($client_back_url)) {
            if (preg_match('/\:order_id/', $client_back_url)) {
                $client_back_url = str_replace(':order_id', $order_id, $client_back_url);
            }
            $tradSource['ClientBackURL'] = siteBaseUrl($request->getServerParams(), true).$language_code.'/'.$client_back_url;
        }
        if (isset($customer_url)) {
            $tradSource['CustomerURL'] = siteBaseUrl($request->getServerParams(), true).$language_code.'/'.$customer_url;
        }
        $orderRow = $this->orderTableGateway->select(['id' => $order_id])->current();
        //$this->logisticsTableGateway->select()->current();
        $logisticRow = $this->logisticsTableGateway->select(['id' => $orderRow->logistics_global_id])->current();
        if ($logisticRow->type == 'cvs_pickup_paid' /*|| $logisticRow->type == 'cvs_pickup_not_paid'*/) {
            $tradSource['CVSCOM'] = 2;
        }
        if ($logisticRow->type == 'cvs_pickup_not_paid') {
            $tradSource['CVSCOM'] = 1;
        }

        $pay_method = $data['pay_method'];
        $tradSource[$pay_method] = 1;
        $hashKey = $config['hash_key'];
        $hashIV = $config['hash_iv'];
        $tradInfo = $this->createMpgAesEncrypt($tradSource, $hashKey, $hashIV);
        $matcher = [];
        preg_match('/^production/i', APP_ENV, $matcher);
        if ($matcher[0] == self::PRODUCTION) {
            $action = 'https://core.newebpay.com/MPG/mpg_gateway';
        } else {
            $action = 'https://ccore.newebpay.com/MPG/mpg_gateway';
        }
        return $twig->render('third_party_payment/mpg_form.html.twig', [
            'action' => $action,
            'MerchantID' => $config['merchant_id'],
            'Version' => self::VERSION,
            'TradeInfo' => $tradInfo,
            'TradeSha' => Security::createSha256Token($hashKey, $hashIV, $tradInfo),
        ]);
    }

    /**
     *
     * @param array $parameters
     * @param string $hashKey
     * @param string $hashIV
     * @return boolean|string
     */
    public function createMpgAesEncrypt(array $parameters, $hashKey, $hashIV)
    {
        return Security::create_mpg_aes_encrypt($parameters, $hashKey, $hashIV);
    }

    /**
     *
     * @param string $parameters
     * @param string $hashKey
     * @param string $hashIV
     * @return boolean|string
     */
    public function createAesDecrypt(string $parameters, string $hashKey, string $hashIV)
    {
        return Security::create_aes_decrypt($parameters, $hashKey, $hashIV);
    }
}
