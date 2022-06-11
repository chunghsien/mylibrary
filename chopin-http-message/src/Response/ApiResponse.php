<?php

namespace Chopin\HttpMessage\Response;

use Laminas\Diactoros\Response\JsonResponse;

class ApiResponse extends JsonResponse
{
    /**
     *
     * @param int   $code
     * @param mixed $data
     * @param array $message
     * @param array $notify
     * @param number $status
     */
    public function __construct(int $code, $data, array $message, array $notify = [], int $status = 200)
    {
        $merge = [
            'code' => $code,
            'message' => $message,
            'notify' => $notify ? $notify : $message,
            'data' => $data,
        ];
        $header = [];
        parent::__construct($merge, $status, $header, JSON_NUMERIC_CHECK);
    }
}
