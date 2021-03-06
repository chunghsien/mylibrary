<?php

namespace Chopin\HttpMessage\Response;

use Laminas\Diactoros\Response\JsonResponse;
use Chopin\Support\Registry;

class ApiErrorResponse extends JsonResponse
{
    public static $status = 417;

    /**
     *
     * @param int   $code
     * @param mixed $data
     * @param array $message
     * @param array $notify
     * @param number $status
     */
    public function __construct(int $code, $data, array $message, array $notify = [])
    {
        if (Registry::get('__csrf')) {
            $data["__csrf"] = Registry::get('__csrf');
        }
        $merge = [
            'code' => $code === 0 ? 1 : $code,
            //'message' => $message,
            'notify' => $notify ? $notify : $message,
            'data' => $data,
        ];
        parent::__construct($merge, self::$status);
    }
}
