<?php

namespace Chopin\Store\Logistics;

use Ecpay\Sdk\Factories\Factory;
use Psr\Http\Message\ServerRequestInterface;

class EcpayCvsB2c extends AbstractLogistics {

    public function withExtraParams($extraParamsJson, $url='') {
        $extraParams = json_decode($extraParamsJson, true);
        $extraParams['withParams'] = [
            'redirect' => '/{lang}/cvs',
        ];
        return json_encode($extraParams);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \Chopin\Store\Logistics\AbstractLogistics::buildContent()
     */
    public function buildContent(ServerRequestInterface $request):string{
        $config = config('third_party_service.logistics.ecpayAllInOne.logistics');
        $factory = new Factory([
            'hashKey' => $config['hashKey'],
            'hashIv' => $config['hashIv'],
        ]);
        /**
         * 
         * @var \Ecpay\Sdk\Services\AutoSubmitFormService $autoSubmitFormService
         */
        $autoSubmitFormService = $factory->create('AutoSubmitFormWithCmvService');
        $html_lang = $request->getAttribute('html_lang');
        $serverReplyUrl = siteBaseUrl($request->getServerParams())."/{$html_lang}/checkout?cvsResponse=1";
        $serverReplyUrl = preg_replace('/([^\:]\/{2,})/', '', $serverReplyUrl);
        $input = [
            'MerchantID' => $config['merchantID'],
            'LogisticsType' => 'CVS',
            'LogisticsSubType' => 'FAMI',
            'ServerReplyURL' => $serverReplyUrl,
        ];
        //$apiUrl = $config['mapUri'];
        $apiUrl = $config['mapUri'];
        return $autoSubmitFormService->generate($input, $apiUrl);
    }
}
