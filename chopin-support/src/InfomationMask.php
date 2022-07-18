<?php

namespace Chopin\Support;

Abstract class InfomationMask {
    
    /**
     * 
     * @param string $info
     * @param string $head 字串開頭顯示的字數
     * @param string $tail 字串結尾顯示的字數
     */
    static public function mask($info, $head = 1, $tail = 1) {
        $len = mb_strlen($info, "utf-8");
        $toShow = $head + $tail;
        $headLength = ($len <= $toShow ? 0 : $head);
        $headInfo = mb_substr($info, 0, $headLength, 'utf-8');
        $middleInfo = str_repeat('*', $len - ($len <= $toShow ? 0 : $toShow));
        $tailStart = $len - $tail;
        $tailLength = $len <= $toShow ? 0 : $tail;
        $tailInfo = mb_substr($info, $tailStart, $tailLength, 'utf-8');
        if($len == $toShow) {
            $headInfo = mb_substr($info, 0, 1, 'utf-8');
            $infoMask = $headInfo .'*';
        }else {
            $infoMask = $headInfo.$middleInfo.$tailInfo;
        }
        return $infoMask;
    }
    
    static public function maskEmail($email) {
        $mail_parts = explode("@", $email);
        $domain_parts = explode('.', $mail_parts[1]);
        
        $mail_parts[0] = self::mask($mail_parts[0], 2, 1); 
        $domain_parts[0] = self::mask($domain_parts[0], 2, 1);
        $mail_parts[1] = implode('.', $domain_parts);
        return implode("@", $mail_parts);
    }
}