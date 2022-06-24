<?php
namespace Chopin\Jwt;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

abstract class JwtTools
{

    /**
     *
     * @param mixed $data
     * @param number $expPlus
     * @return mixed
     */
    public static function buildPayload($data, $expPlus = 86400)
    {
        $iat = strtotime('now');
        return [
            'iss' => $_SERVER['SERVER_NAME'],
            'aud' => $_SERVER['REMOTE_ADDR'],
            'iat' => $iat,
            'nbf' => $iat - 1,
            'exp' => ($iat + $expPlus),
            'data' => $data
        ];
    }

    /**
     *
     * @param array|\ArrayObject $data
     * @param number $expPlus
     * @return string
     */
    public static function encode($data, $expPlus = 86400)
    {
        $key = config('encryption.jwt_key');
        $alg = config('encryption.jwt_alg');
        $payload = self::buildPayload($data, $expPlus);
        return JWT::encode($payload, $key, $alg);
    }

    public static function decode(string $payload)
    {
        $jwt = $payload;
        $key = config('encryption.jwt_key');
        $alg = config('encryption.jwt_alg');
        return JWT::decode($jwt, $key, [
            $alg
        ]);
    }

    /**
     *
     * @param mixed $payload
     * @return array
     */
    public static function verify($payload)
    {
        if (is_string($payload)) {
            $jwt = $payload;
            $key = config('encryption.jwt_key');
            $alg = config('encryption.jwt_alg');
            $payload = JWT::decode($jwt, new Key($key, $alg));
        }

        $iat = strtotime('now');
        if (! isset($payload)) {
            return false;
        }

        $iss = isset($_SERVER["SERVER_PORT"]) == "443" ? 'https://' . $_SERVER["SERVER_NAME"] : 'http://' . $_SERVER["SERVER_NAME"];
        if ($payload->iss != $iss) {
            return [
                "status" => false,
                "msg" => "issuer fail"
            ];
        }

        if ($payload->nbf > $iat) {
            return [
                "status" => false,
                "msg" => "not before"
            ];
        }
        if ($payload->exp < $iat) {
            return [
                "status" => false,
                "msg" => "exp fail"
            ];
        }
        if ($_ENV["APP_ENV"] == "production") {
            if ($payload->exp > $iat + (60 * 10)) {
                return [
                    "status" => false,
                    "msg" => "exp fail"
                ];
            }
        }
        return [
            "status" => true,
            "msg" => "success"
        ];
    }
}
