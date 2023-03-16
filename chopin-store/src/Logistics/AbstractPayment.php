<?php

/**
 * @desc 付款抽象類別
 */

declare(strict_types=1);

namespace Chopin\Store\Logistics;

use Psr\Http\Message\ServerRequestInterface;
use Chopin\Store\TableGateway\OrderDetailTableGateway;
use Chopin\LanguageHasLocale\TableGateway\CurrenciesTableGateway;
use Chopin\Store\TableGateway\OrderTableGateway;
use Chopin\Store\TableGateway\PaymentTableGateway;
use Laminas\Db\Adapter\AdapterInterface;
use Chopin\Store\TableGateway\LogisticsGlobalTableGateway;
use Chopin\Support\Registry;
use Chopin\Users\TableGateway\MemberTableGateway;
use Chopin\LanguageHasLocale\TableGateway\LanguageHasLocaleTableGateway;
use Chopin\Support\TwigMail;
use Laminas\Log\Logger;
use Laminas\Db\Adapter\Adapter;

abstract class AbstractPayment
{
    protected $devConfig;

    protected $config;

    /**
     *
     * @var Logger
     */
    protected $logger;

    abstract public function __construct(Adapter $adapter);

    /**
     *
     * @param ServerRequestInterface $request
     * @param array $orderData
     * @throws \ErrorException
     * @return array
     */
    abstract public function requestApi(ServerRequestInterface $request, $orderData);

    /**
     *
     * @return \Laminas\Log\Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    abstract public function getConfig($language_id = 119, $locale_id = 229);

    public function sendOrderedMail(ServerRequestInterface $request, $orderSerail, AdapterInterface $adapter)
    {
        $htmlLang = $request->getAttribute('html_lang');
        $fmtLang = preg_replace("/(\_|\-).*$/", '', $htmlLang);
        $currenciesTableGateway = new CurrenciesTableGateway($this->adapter);
        $currencieRow = $currenciesTableGateway->select([
            "is_use" => 1
        ])->current();
        $digitsAfterTheDecimalPoint = 0;
        $dollarSign = "";
        if ($currencieRow) {
            $fmt = \NumberFormatter::create("{$fmtLang}@currency={$currencieRow->code}", \NumberFormatter::CURRENCY);
            $dollarSign = $fmt->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
            $digitsAfterTheDecimalPoint = $currencieRow->digits_after;
        }
        $orderTableGateway = new OrderTableGateway($adapter);
        $orderItem = $orderTableGateway->getRow($orderSerail);
        if (! $orderItem) {
            $orderItem = $orderTableGateway->getRowFromId($orderSerail);
        }
        $orderItem = (array) $orderItem;
        $orderDetailTableGateway = new OrderDetailTableGateway($adapter);
        $orderId = $orderItem["id"];
        $orderDetailResult = $orderDetailTableGateway->getDetailResult($orderId);

        // begin of 付款方式
        $paymentTableGateway = new PaymentTableGateway($adapter);
        $paymentId = $orderItem["payment_id"];
        $paymentRow = $paymentTableGateway->select([
            "id" => $paymentId
        ])->current();
        // end of 付款方式

        // begin of 運送方式
        $logisticsGlobalTableGateway = new LogisticsGlobalTableGateway($adapter);
        $logisticsGlobalId = $orderItem["logistics_global_id"];
        $logisticsGlobalRow = $logisticsGlobalTableGateway->select([
            "id" => $logisticsGlobalId
        ])->current();
        // end of 運送方式

        $memberTableGateway = new MemberTableGateway($this->adapter);
        $memberId = $orderItem["member_id"];
        $memberItem = $memberTableGateway->getMember($memberId);

        $pageConfig = Registry::get('page_json_config');
        $thirdPartyConfig = null;
        $manufacturer = $paymentRow->manufacturer;
        if (isset($pageConfig["system_settings"][$manufacturer])) {
            $thirdPartyConfig = $pageConfig["system_settings"][$manufacturer]["children"]["{$manufacturer}-config"]["value"];
            $thirdPartyConfig = str_replace('Chopin\\Store\\Logistics\\', 'Chopin\\\\Store\\\\Logistics\\\\', $thirdPartyConfig);
            //$thirdPartyConfig = json_decode($thirdPartyConfig, true);
            $paymentRow->aes_value = $thirdPartyConfig;
        }
        $serverParams = $request->getServerParams();
        $baseUrl = $serverParams["REQUEST_SCHEME"] . "://" . $serverParams["HTTP_HOST"];
        $localeCode = '';
        $phpLocale = '';
        if (config('is_multiple_language')) {
            $languageId = $orderItem["language_id"];
            $localeId = $orderItem["locale_id"];
            $languageHasLocaleTableGateway = new LanguageHasLocaleTableGateway($adapter);
            $languageHasLocaleRow = $languageHasLocaleTableGateway->select([
                "language_id" => $languageId,
                "locale_id" => $localeId
            ])->current();
            $phpLocale = $languageHasLocaleRow->code;
            $localeCode = str_replace('_', '-', $phpLocale);
        }
        $orderInquiryUri = "{$baseUrl}/{$localeCode}/my-account#orders";
        $orderInquiryUri = str_replace('//', '/', $orderInquiryUri);
        $vars = [
            "system" => $pageConfig["system_settings"]["system"]["to_config"],
            "member" => (array) $memberItem,
            "order" => $orderItem,
            "orderDetail" => $orderDetailResult,
            "payment" => $paymentRow->toArray(),
            "is_aes_value_string" => isset($paymentRow->aes_value) && is_string($paymentRow->aes_value),
            "orderInquiryUri" => $orderInquiryUri,
            "baseUrl" => $baseUrl,
            "digitsAfterTheDecimalPoint" => $digitsAfterTheDecimalPoint,
            "dollarSign" => $dollarSign,
            "logistics_name" => $logisticsGlobalRow->name,
        ];
        TwigMail::sendOrderedMail($request, [
            "vars" => $vars,
            "path" => "./resources/email_templates/{$phpLocale}/",
            "name" => "recive_order.html.twig",
        ]);
    }
}
