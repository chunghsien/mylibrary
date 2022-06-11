<?php
namespace Chopin\HttpMessage\Response;

use Laminas\Diactoros\Response\JsonResponse;
use Chopin\Support\Registry;

class ApiSuccessResponse extends JsonResponse
{

    public static $is_json_numeric_check = true;

    /**
     *
     * @paream int $code
     * @param mixed $data
     * @param array $message
     * @param array $notify
     * @param number $status
     */
    public function __construct(int $code, $data, array $message = [], array $notify = [])
    {
        $header = [];
        if (Registry::get('__csrf')) {
            $data["__csrf"] = Registry::get('__csrf');
        }
        $merge = [
            'code' => $code !== 0 ? 0 : $code,
            'message' => $message,
            'notify' => $notify ? $notify : $message,
            'data' => $data
        ];
        if (self::$is_json_numeric_check === true) {
            parent::__construct($merge, 200, $header, JSON_NUMERIC_CHECK);
        } else {
            parent::__construct($merge, 200, $header);
        }
    }
}
