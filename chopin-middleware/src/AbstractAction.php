<?php

namespace Chopin\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\Feature\GlobalAdapterFeature;
use Laminas\Db\TableGateway\AbstractTableGateway;
use Mezzio\Router\RouteResult;
use Chopin\Documents\TableGateway\DocumentsTableGateway;
use Chopin\Documents\TableGateway\BannerTableGateway;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Diactoros\Response;
use Chopin\HttpMessage\Response\ApiSuccessResponse;

/**
 * @author hsien
 *
 */
abstract class AbstractAction implements RequestHandlerInterface
{
    /**
     *
     * @var Adapter
     */
    protected $adapter;

    /**
     *
     * @var AbstractTableGateway[]
     */
    protected $tableGateways;

    public function __construct()
    {
        $this->adapter = GlobalAdapterFeature::getStaticAdapter();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtolower($request->getMethod());
        return $this->{$method}($request);
    }

    protected function get(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse(405);
    }

    protected function put(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse(405);
    }

    protected function post(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse(405);
    }

    protected function delete(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse(405);
    }

    // abstract public function getStandByVars(ServerRequestInterface $request);
    protected function getCommonVars(ServerRequestInterface $request)
    {
        $query = $request->getQueryParams();
        if (isset($query['noCommonVars'])) {
            return [];
        }
        $system_settings = $request->getAttribute('system_settings');
        $lang = str_replace('-', '_', $request->getAttribute('lang', 'zh-TW'));
        $site_info = $system_settings['site_info'][$lang]['to_config'];
        $site_info['operation'] = nl2br($site_info['operation']);
        /**
         *
         * @var RouteResult $routeResult
         */
        $routeResult = $request->getAttribute(RouteResult::class);
        $documentsRoute = "/".implode('/', $routeResult->getMatchedParams());
        $documentsRoute = preg_replace('/\/index$/', '', $documentsRoute);
        $documentsTableGateway = new DocumentsTableGateway($this->adapter);
        $documentsWhere = $documentsTableGateway->getSql()->select()->where;
        $documentsWhere->isNull('deleted_at')->equalTo('route', $documentsRoute);
        $documentsRow = $documentsTableGateway->select($documentsWhere)->current();
        $banner = null;
        if ($documentsRow) {
            $bannerTableGateway = new BannerTableGateway($this->adapter);
            $bannerWhere = $bannerTableGateway->getSql()->select()->where;
            $bannerWhere->isNull('deleted_at');
            $bannerWhere->equalTo('type', 'banner');
            $bannerWhere->equalTo('table', $documentsTableGateway->getTailTableName());
            $bannerWhere->equalTo('table_id', $documentsRow->id);
            $bannerSelect = $bannerTableGateway->getSql()->select();
            $bannerSelect->order(['sort ASC', 'id ASC']);
            $bannerSelect->where($bannerWhere);
            $banner = $bannerTableGateway->selectWith($bannerSelect)->current();
        }
        $googleService = $system_settings['google_service']['to_config'];
        unset($googleService['google_recaptcha_secret_key']);
        unset($googleService['google clooun platform_api_serect']);
        $html_lang = $request->getAttribute('html_lang');
        $currenciesTableGateway = new TableGateway(\Chopin\LaminasDb\TableGateway\AbstractTableGateway::$prefixTable.'currencies', $this->adapter);
        $currenciesItem = $currenciesTableGateway->select(["is_use" => 1])->current();
        $formatter = \NumberFormatter::create("{$html_lang}@currency={$currenciesItem->code}", \NumberFormatter::CURRENCY);

        return [
            "banner" => $banner,
            'site_info' => $site_info,
            'system' => $system_settings['system']['to_config'],
            'google_service' => $googleService,
            'site_header' => $request->getAttribute('site_header'),
            'site_footer' => $request->getAttribute('site_footer'),
            'seo' => $request->getAttribute('seo'),
            "currency_symbol" => $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL),
        ];
    }

    protected function exportAssist(
        $translateSourceFile,
        ServerRequestInterface $request,
        Response $response,
        AbstractTableGateway $tableGateway
    ) {
        $queryParams = $request->getQueryParams();
        if (empty($queryParams["export"])) {
            return $response;
        }

        $contents = json_decode($response->getBody()->getContents(), true);
        $data = $contents["data"];
        $export = [];
        $headerTitle = [];
        if (!is_file($translateSourceFile)) {
            echo "找不到檔案： ".$translateSourceFile;
            exit();
        }
        $translateArr = require $translateSourceFile;
        $headerTitle = array_values($translateArr);
        $export[] = $headerTitle;
        $translateKeys = array_keys($translateArr);
        foreach ($data as $item) {
            $tmp = [];
            foreach ($translateKeys as $key) {
                $value = $item[$key];
                $tmp[] = $value;
            }
            $export[] = $tmp;
        }
        unset($translateKeys);
        unset($data);
        return new ApiSuccessResponse(0, ["items" => $export, "table" => $tableGateway->getTailTableName()]);
    }

    /**
     * @deprecated
     * @param ServerRequestInterface $request
     * @return boolean
     */
    protected function verifyCsrf(ServerRequestInterface $request)
    {
        if ($_ENV["APP_ENV"] == 'production') {
            $server = $request->getServerParams();
            if (isset($server['HTTP_ORIGIN']) && preg_match('/localhost:\d+/', $server['HTTP_ORIGIN'])) {
                return true;
            }
        }
        return true;
    }
}
