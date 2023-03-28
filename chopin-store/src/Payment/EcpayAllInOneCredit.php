<?php
namespace Chopin\Store\Payment;

use Ecpay\Sdk\Factories\Factory;
use Ecpay\Sdk\Services\UrlService;

class EcpayAllInOneCredit extends AbstractPayment
{

    public function requestApi($orderCommonParams): array
    {
        $config = config('third_party_service.logistics.ecpayAllInOne.payment');
        $factory = new Factory([
            'hashKey' => $config['hashKey'],
            'hashIv' => $config['hashIv']
        ]);
        /**
         *
         * @var \Ecpay\Sdk\Services\AutoSubmitFormService $autoSubmitFormService
         */
        $autoSubmitFormService = $factory->create('AutoSubmitFormWithCmvService');
        $input = [];
        $action = $config['aioCheckoutUri'];
        $orderSet = $orderCommonParams['order'];
        $itemName = '';
        foreach ($orderSet['details'] as $orderItem) {
            $name = $orderItem['alias'];
            if($orderItem['option1_name']) {
                $option1_name = $orderItem['option1_name'];
                $name.= "({$option1_name}";
            }
            if($orderItem['option2_name']) {
                $option2_name = $orderItem['option2_name'];
                $name.= ",{$option2_name}";
            }
            if($orderItem['option3_name']) {
                $option3_name = $orderItem['option3_name'];
                $name.= ",{$option3_name}";
            }
            if($orderItem['option4_name']) {
                $option4_name = $orderItem['option4_name'];
                $name.= ",{$option4_name}";
            }
            if($orderItem['option5_name']) {
                $option5_name = $orderItem['option5_name'];
                $name.= ",{$option5_name}";
            }
            if($orderItem['option1_name']) {
                $name.= ")";
            }
            $itemName.= "#{$name}\n";
        }
        if(mb_strlen($itemName, 'utf-8') > 400) {
            $itemName = mb_substr($itemName, 0, 397, 'utf-8');
            $itemName.= '...';
        }
        $tradeDesc = $orderSet['serial']. "綠界刷卡";
        
        $input = [
            'MerchantID' => $config['merchantID'],
            'MerchantTradeNo' => $orderSet['serial'],
            'MerchantTradeDate' => date('Y/m/d H:i:s'),
            'PaymentType' => 'aio',
            'TotalAmount' => intval($orderSet['total']),
            'TradeDesc' => UrlService::ecpayUrlEncode($tradeDesc),
            'ItemName' => $itemName,
            'ChoosePayment' => 'Credit',
            'EncryptType' => 1,
            'ReturnURL' => 'https://www.ecpay.com.tw/example/receive',
        ];
        logger('./storage/logs/ecpay_allin_one/credit_%s.log')->info('Request: '.json_encode($input, JSON_UNESCAPED_UNICODE));
        $content = $autoSubmitFormService->generate($input, $action);
        return [
            'orderedStatus' => true,
            'content' => $content,
            'form' => true
        ];
    }
}