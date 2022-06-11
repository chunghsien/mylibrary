<?php

namespace Chopin\Support;

/**
 * @filesource https://www.codeleading.com/article/22823266091/
 * @desc 字符编码转换类, ANSI、Unicode、Unicode big endian、UTF-8、UTF-8+Bom互相转换
 * Func:
 * public convert 转换
 * private convToUtf8 把编码转为UTF-8编码
 * private convFromUtf8 把UTF-8编码转换为输出编码
 */
class CharsetConv
{
    // class start
    private $_in_charset = null;

    // 源编码
    private $_out_charset = null;

    // 输出编码
    private $_allow_charset = [
        'utf-8',
        'utf-8bom',
        'ansi',
        'unicode',
        'unicodebe'
    ];

    /**
     * @desc 初始化
     * @param String $in_charset as 源编码
     * @param String $out_charset as 输出编码
     */
    public function __construct($in_charset, $out_charset)
    {
        $in_charset = strtolower($in_charset);
        $out_charset = strtolower($out_charset);
        // 检查源编码
        if (in_array($in_charset, $this->_allow_charset, true)) {
            $this->_in_charset = $in_charset;
        }
        // 检查输出编码
        if (in_array($out_charset, $this->_allow_charset, true)) {
            $this->_out_charset = $out_charset;
        }
    }

    /**
     * @desc 转换
     * @param string $str as 要转换的字符串
     * @return string 转换后的字符串
     */
    public function convert($str)
    {
        $str = $this->convToUtf8($str); // 先转为utf8
        $str = $this->convFromUtf8($str); // 从utf8转为对应的编码
        return $str;
    }

    /**
     * @desc 把编码转为UTF-8编码
     *
     * @param String $str
     * @return String
     */
    private function convToUtf8($str)
    {
        if ($this->_in_charset == 'utf-8') { // 编码已经是utf-8，不用转
            return $str;
        }
        switch ($this->_in_charset) {
            case 'utf-8bom':
                $str = substr($str, 3);
                break;
            case 'ansi':
                $str = iconv('GBK', 'UTF-8//IGNORE', $str);
                break;
            case 'unicode':
                $str = iconv('UTF-16le', 'UTF-8//IGNORE', substr($str, 2));
                break;
            case 'unicodebe':
                $str = iconv('UTF-16be', 'UTF-8//IGNORE', substr($str, 2));
                break;
            default:
                break;
        }
        return $str;
    }

    /**
     * @desc 把UTF-8编码转换为输出编码
     * @param String $str
     * @return String
     */
    private function convFromUtf8($str)
    {
        // 输出编码已经是utf-8，不用转
        if ($this->_out_charset == 'utf-8') {
            return $str;
        }

        switch ($this->_out_charset) {
            case 'utf-8bom':
                $str = "\xef\xbb\xbf" . $str;
                break;

            case 'ansi':
                $str = iconv('UTF-8', 'GBK//IGNORE', $str);
                break;

            case 'unicode':
                $str = "\xff\xfe" . iconv('UTF-8', 'UTF-16le//IGNORE', $str);
                break;

            case 'unicodebe':
                $str = "\xfe\xff" . iconv('UTF-8', 'UTF-16be//IGNORE', $str);
                break;

            default:
                break;
        }
        return $str;
    }
}
