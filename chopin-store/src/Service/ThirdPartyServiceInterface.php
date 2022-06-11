<?php

namespace Chopin\Store\Service;

use Psr\Http\Message\ServerRequestInterface;

interface ThirdPartyServiceInterface
{
    /**
     * @desc 退款功能
     * @param string $MerchantTradeNo
     * @return array
     */
    public function refund(string $MerchantTradeNo);

    /**
     * @desc 交易完成後的訊息處理
     * @param ServerRequestInterface $request
     * @param string $type
     * @return array
     */
    public function notify(ServerRequestInterface $request, $type);

    /**
     * @desc 建立HTML FORM
     * @param ServerRequestInterface $request
     * @param array $data
     * @return string
     */
    public function buildMpgForm(ServerRequestInterface $request, $data);

    /**
     * @desc發票作廢
     * @param string $invoiceNumber
     * @param string $reason
     * @return array
     */
    public function invoiceVoid($invoiceNumber, $reason);
}
