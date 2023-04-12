<?php

use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Db\Sql\Expression;
use Chopin\Support\Log;
use Laminas\Db\TableGateway\Feature\GlobalAdapterFeature;
use Chopin\Support\Registry;
use Laminas\ServiceManager\ServiceManager;
use Intervention\Image\ImageManagerStatic as Image;
use Chopin\LaminasDb\TableGateway\AbstractTableGateway;
use Laminas\I18n\Translator\Translator;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Psr\Http\Message\ServerRequestInterface;
use NoProtocol\Encryption\MySQL\AES\Crypter;

if (! function_exists('config') && is_file('config/config.php')) {

    /**
     * @desc 代入年跟月求當月有幾天
     * @param int $year
     * @param int $month
     * @return int
     */
    function daysInTheMonth($year, $month)
    {
        return \Chopin\Support\DateTools::daysInTheMonth($year, $month);
    }

    function realClientSideUri($uri = '')
    {
        if (! defined('CLIENT_SIDE_LOCALE')) {
            define('CLIENT_SIDE_LOCALE', '');
        }
        $return = '/' . CLIENT_SIDE_LOCALE . '/' . $uri;
        $return = preg_replace('/\/\//', '/', $return);
        return $return;
    }

    /**
     * @deprecated
     * @desc 資料是否已被加密過
     * @param string $str
     * @param Crypter $crypter
     * @return bool
     */
    function isEncrypted($str, Crypter $crypter) {
        return (bool) $crypter->decrypt($str);
    }

    function getAppEnvConstant()
    {
        return $_ENV["APP_ENV"];
    }

    function i18nStaticTranslator($text, $textDomain = 'default')
    {
        if (defined('BACKEND_LOCALE')) {
            $locale = BACKEND_LOCALE;
            if (! Registry::isRegistered(Translator::class)) {
                $translator = Translator::factory([]);
                Registry::set(Translator::class, $translator);
            } else {
                $translator = Registry::get(Translator::class);
            }
            $allMessages = $translator->getAllMessages($textDomain, $locale);

            if ($_ENV["APP_ENV"] === 'production') {
                $cache = Registry::get(\Laminas\Cache\Storage\StorageInterface::class);
                if ($cache instanceof Filesystem) {
                    $options = $cache->getOptions();
                    $options->setCacheDir('./storage/cache/app/i18n');
                    $cache->setOptions($options);
                }
                $translator->setCache($cache);
            }

            /**
             *
             * @var Translator $translator;
             */
            if (! $allMessages) {
                $file = PROJECT_DIR."/resources/languages/{$locale}/{$textDomain}.php";
                if (is_file($file)) {
                    $translator->addTranslationFile('phpArray', $file, $textDomain);
                    $allMessages = $translator->getAllMessages($textDomain, $locale);
                }
            }
            if (isset($allMessages[$text])) {
                return $allMessages[$text];
            }
        }
        return $text;
    }

    function mergePageJsonConfig($pageJsonConfig)
    {
        $old = Registry::get('page_json_config');
        if (! $old) {
            $old = [];
        }
        $result = array_merge($old, $pageJsonConfig);
        Registry::set('page_json_config', $result);
    }

    function moveFolder($src, $dist)
    {
        $it = new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            /**
             *
             * @var \SplFileInfo $file
             */
            if ($file->isDir()) {
                $sourceFolder = $file->getRealPath();
                $distFolder = str_replace(preg_replace('/^\./', '', $src), preg_replace('/^\./', '', $dist), $sourceFolder);
                if (! is_dir($distFolder)) {
                    mkdir($distFolder, 0775, true);
                }
            } else {
                $srcFile = $file->getRealPath();
                $distFile = str_replace(preg_replace('/^\./', '', $src), preg_replace('/^\./', '', $dist), $srcFile);
                $arr = explode('/', $distFile);
                array_pop($arr);
                $folder = implode('/', $arr);
                if (! is_dir($folder)) {
                    mkdir($folder, 0775, true);
                }
                rename($srcFile, $distFile);
            }
        }
        unset($files);
    }

    function recursiveRemoveFolder($folder)
    {
        $it = new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                if (is_file($file->getRealPath())) {
                    unlink($file->getRealPath());
                }
            }
        }
        unset($files);
    }

    function copyAdvenced($src, $dst)
    {
        // open the source directory
        $dir = opendir($src);
        // Make the destination directory if not exist
        @mkdir($dst);
        // Loop through the files in source directory
        foreach (scandir($src) as $file) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {

                    // Recursively calling custom copy function
                    // for sub directory
                    copyAdvenced($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    /**
     *
     * @param string $originailPath
     * @param number $width
     * @param number $height
     * @param number $quality
     * @throws \ErrorException
     * @return string
     */
    function thumbnail($originailPath, $width = 150, $height = null, $quality = 60)
    {
        if (preg_match('/assets\/images/', $originailPath)) {
            return $originailPath;
        }
        if (! preg_match("/public\/storage\/uploads/", $originailPath) && is_dir("./public/storage/uploads")) {
            $originailPath = "./public" . $originailPath;
        }

        if (! is_file($originailPath)) {
            if (preg_match("/^\/assets/", $originailPath)) {
                $originailPath = "./public/" . $originailPath;
            } else {
                $originailPath = "./" . $originailPath;
            }
            $originailPath = preg_replace("/\/{2,}/", "/", $originailPath);
            if (! is_file($originailPath)) {
                throw new \ErrorException('找不到圖片：' . $originailPath);
            }
        }
        $originailPath = preg_replace('/^\.\//', '', $originailPath);
        $matcher = [];
        preg_match('/(?<ext>\.\w{3,})$/', $originailPath, $matcher);
        $ext = $matcher['ext'];
        $size_text = '_w' . (isset($width) ? $width : 'auto') . '_h_' . (isset($height) ? $height : 'auto');

        $thumbPath = str_replace($ext, ('_' . $size_text . '_thumb' . $ext), $originailPath);

        if (is_file($thumbPath)) {
            if (preg_match("/public\//", $thumbPath)) {
                $thumbPath = str_replace("public/", '', $thumbPath);
            }
            $thumbPath = '/' . $thumbPath;
            $thumbPath = preg_replace("/\/{2,}/", '/', $thumbPath);
            return $thumbPath;
        } else {
            $image = Image::make($originailPath);

            if ($image->width() <= $width || ($height && $image->height() < $height)) {
                if (preg_match("/public\//", $originailPath)) {
                    $thumbPath = str_replace("public/", '', $originailPath);
                }
                $originailPath = '/' . $originailPath;
                $originailPath = preg_replace("/\/{2,}/", '/', $originailPath);
                return $originailPath;
            }

            if (is_null($width) && $height > 0) {
                // auto width
                $image->resize(null, $height, function ($constraint) {
                    $constraint->aspectRatio();
                });
            }
            if (is_null($height) && $width > 0) {
                // auto width
                $image->resize($width, null, function ($constraint) {
                    $constraint->aspectRatio();
                });
            }

            if (is_int($width) && is_int($height)) {
                $image->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                });
            }
            $image->save($thumbPath, $quality);

            if (preg_match("/public\//", $thumbPath)) {
                $thumbPath = str_replace("public/", '', $thumbPath);
            }
            $thumbPath = '/' . $thumbPath;
            $thumbPath = preg_replace("/\/{2,}/", '/', $thumbPath);
            return $thumbPath;
        }
    }

    /**
     *
     * @param string $json
     * @param boolean $isHTMLEntities
     * @param boolean $isReplaceHeadAndTailCurlyBrackets
     * @return string
     */
    function json2DataAttr($json, $isHTMLEntities = true, $isReplaceHeadAndTailCurlyBrackets = false)
    {
        if ($isReplaceHeadAndTailCurlyBrackets) {
            $json = preg_replace('/^\{/', '', $json);
            $json = preg_replace('/\}$/', '', $json);
        }

        if ($isHTMLEntities) {
            return htmlentities($json, ENT_QUOTES, 'UTF-8');
        } else {
            $json = str_replace('"', "'", $json);
            return $json;
        }
    }

    /**
     *
     * @param boolean $tailSlash
     * @return string
     */

    /**
     *
     * @param array $server_params
     * @param boolean $tailSlash
     * @return string
     */
    function siteBaseUrl($server_params = null, $tailSlash = false)
    {
        $uri = '';
        if (! $server_params) {
            $server_params = $_SERVER;
        }
        $port = intval($server_params['SERVER_PORT']);
        if ($port == 443) {
            $uri = 'https://' . $server_params['SERVER_NAME'];
        } else {
            $uri = 'http://' . $server_params['SERVER_NAME'];
        }
        $uri = preg_replace('/(\/{1,}$)/', '', $uri);
        if ($tailSlash) {
            $uri .= '/';
        }

        return $uri;
    }

    /**
     *
     * @param string|null $key
     * @return NULL|array
     */
    function config($key = null)
    {
        if (is_null($key)) {
            if (preg_match('/^production/i', $_ENV["APP_ENV"]) && is_file('storage/cache/config-cache.dat')) {
                return unserialize(file_get_contents('storage/cache/config-cache.dat'));
            } else {
                return require 'config/config.php';
            }
        }
        /**
         *
         * @var ServiceManager $serviceManager
         */
        $serviceManager = Registry::get(ServiceManager::class);
        $config = $serviceManager->get('config');
        $keyArr = explode('.', $key);
        if (preg_match('/^production/i', $_ENV["APP_ENV"]) && ! is_file('storage/cache/config-cache.dat')) {
            file_put_contents('storage/cache/config-cache.dat', serialize($config));
        }
        if (preg_match('/\*$/', $key)) {
            $top = $keyArr[0];
            $allSelectConfig = $config[$top];
            $tmp = [];
            $key = preg_replace('/\*$/', '', $keyArr[1]);
            $key .= '/';
            foreach ($allSelectConfig as $k => $c) {
                if (strpos($k, $key) !== false) {
                    $tmp[$k] = $c;
                }
            }
            unset($allSelectConfig);
            unset($keyArr);
            return $tmp;
        }
        $result = null;

        foreach ($keyArr as $index => $childKey) {
            if (! $result && $index == 0) {
                $result = isset($config[$childKey]) ? $config[$childKey] : null;
            }
            if ($index > 0 && $result) {
                $result = isset($result[$childKey]) ? $result[$childKey] : null;
            }
        }
        if (! $result) {
            $result = isset($config[$key]) ? $config[$key] : null;
        }
        unset($keyArr);
        return $result;
    }

    function Json2Props($json, $isHTMLEntities = true)
    {
        $json = preg_replace('/^\{/', '', $json);
        $json = preg_replace('/\}$/', '', $json);
        $json = preg_replace('/\r|\n/', '', $json);
        $json = preg_replace('/\s{4}/', '', $json);
        if ($isHTMLEntities) {
            return htmlentities($json, ENT_QUOTES, 'UTF-8');
        } else {
            $json = str_replace('"', "'", $json);
            return $json;
        }
    }

    function isApiRequest($options = [])
    {
        $request = ServerRequestFactory::fromGlobals();
        $method = strtolower($request->getMethod());
        $acceprHeader = $request->getHeader('Accept')[0];
        if (false !== array_search($method, [
            "put",
            "delete"
        ], true)) {
            return true;
        }
        if (isset($options['accept'])) {
            $accept = $options['accept'];
        } else {
            $accept = 'application/json';
        }
        if ($request->hasHeader('Accept')) {
            $acceprHeader = $request->getHeader('Accept')[0];
            if (false === strpos($acceprHeader, $accept)) {
                return false;
            }
        }
        return true;
    }

    function isAjax(ServerRequestInterface $request = null)
    {
        $http_x_requested_with = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) : null;
        $routeName = '';
        if($request) {
            /**
             *
             * @var \Mezzio\Router\RouteResult $route
             */
            $route = $request->getAttribute(\Mezzio\Router\RouteResult::class);
            $routeName = $route->getMatchedRouteName();
            //logger()->info($routeName);
            $serverParams = $request->getServerParams();
            if(!$http_x_requested_with) {
                $http_x_requested_with = $request->getHeader('http-x-requested-with');
                if($http_x_requested_with) {
                    $http_x_requested_with = $http_x_requested_with[0];
                }else {
                    $http_x_requested_with = null;
                }
            }
            if (isset($serverParams["HTTP_REFERER"])) {
                if (false !== strpos($serverParams["HTTP_REFERER"], $serverParams["HTTP_HOST"]) ) {
                    $header = $request->getHeader('content-type');
                    if($header && $header[0] == 'application/json') {
                        $http_x_requested_with = 'xmlhttprequest';
                    }
                    if(!$http_x_requested_with) {
                        if(preg_match('/^api\.admin|site$/', $routeName)) {
                            $http_x_requested_with = 'xmlhttprequest';
                        }
                    }
                }
            }
            if(preg_match('/^(127\.0\.0\.1)|(192\.168\.\d{1,3}.\d{1,3})/', $serverParams["REMOTE_ADDR"])) {
                if($http_x_requested_with == 'xmlhttprequest') {
                    return true;
                }
            }
        }
        $return = (defined('LOCALHOST_NODEJS_USE') || $http_x_requested_with == 'xmlhttprequest');
        return $return;
    }

    /**
     *
     * @param mixed $var
     * @param array $options
     */
    function debug($var, $options = [])
    {
        // ob_end_clean();
        if (isset($options['log']) && $options['log']) {
            $output = var_export($var, true);
            $output = preg_replace("/\n$/", '', $output);
            logger()->debug($output);
            return;
        }
        $varOutputType = isset($options['output_type']) ? $options['output_type'] : 'export';
        $varOutputType = strtolower($varOutputType);

        if (isset($options['profile'])) {
            if (! is_dir('storage/debug')) {
                mkdir('storage/debug', 0755, true);
            }
            $file = 'storage/debug/debug_profile.dat';
            $export = var_export($var, true) . PHP_EOL;
            file_put_contents($file, $export, FILE_APPEND);
            if ($options['throwException']) {
                throw new \ErrorException('test break');
            }
            return;
        }
        if (empty($options['is_console_display']) || $options['is_console_display'] == false) {
            if ($varOutputType != 'json') {
                echo '<pre>';
            }
        }
        if (isset($options['lineno'])) {
            if ($varOutputType != 'json') {
                echo ('Line: ' . $options['lineno'] . PHP_EOL);
            } else {
                $var['Line'] = $options['lineno'];
            }
        }

        if (isset($options['filename'])) {
            if ($varOutputType != 'json') {
                echo ('File: ' . $options['filename'] . PHP_EOL);
            } else {
                $var['File'] = $options['filename'];
            }
        }
        if ($varOutputType == 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($var, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo PHP_EOL;
            exit();
        } else {
            var_export($var);
        }
        if (empty($options['is_console_display']) || $options['is_console_display'] == false) {
            if ($varOutputType != 'json') {
                echo '</pre>';
            }
        }
        if (empty($options['isContinue'])) {
            exit();
        }
    }

    function debugExport($var)
    {
        debug($var, [
            "output_type" => "export",
            "isContinue" => 1
        ]);
    }

    /**
     *
     * @param array $var
     */
    function debugJson($var)
    {
        debug($var, [
            "output_type" => "json"
        ]);
    }

    function PDOBindParamRaw($raw)
    {
        return new Expression($raw);
    }

    /**
     *
     * @return \Laminas\Log\Logger
     */
    function logger($strpos = null)
    {
        return Log::log($strpos);
    }

    function loggerException($e)
    {
        $errorArr = [
            '',
            'code:    ' . $e->getCode(),
            'file:    ' . $e->getFile(),
            'line:    ' . $e->getLine(),
            'message: ' . $e->getMessage(),
            'trace:   ' . $e->getTraceAsString()
        ];
        logger()->err(implode(PHP_EOL, $errorArr));
    }

    /**
     *
     * @param string $tablegatewayClassname
     * @param string $valueField
     * @param string $lableField
     * @param array $predicateParams
     */
    function getOptions($tablegatewayClassname, $valueField, $lableField, $dataAttrs = [], $predicateParams = [])
    {
        $reflection = new ReflectionClass($tablegatewayClassname);

        /**
         *
         * @var AbstractTableGateway $tableGateway
         */
        $tableGateway = $reflection->newInstance(GlobalAdapterFeature::getStaticAdapter());
        return $tableGateway->getOptions($valueField, $lableField, $dataAttrs, $predicateParams);
    }

    function getConfigPrefixNoMVC($headReplace = '')
    {
        $scriptName = preg_replace('/^\//', '', $_SERVER["SCRIPT_NAME"]);
        $scriptName = preg_replace('/\.php$/', '', $scriptName);
        if ($headReplace) {
            $scriptName = preg_replace('/^\w+\//', $headReplace . '/', $scriptName);
        }
        return $scriptName;
    }

    /**
     *
     * @param
     *            $search
     * @return array
     */
    function fileCacheBlurSearch($search)
    {
        $pattern = sprintf('storage/cache/zfcache-*/zfcache-%s*.dat', $search);
        return glob($pattern, GLOB_NOSORT);
    }

    function traceVarsProgress($var)
    {
        if (preg_match('/^(192|128)\./', $_SERVER['SERVER_ADDR'])) {
            // REQUEST_TIME_FLOAT
            $folder = "./storage/logs/vars/" . REQUEST_TIME_FLOAT;
            if (! is_dir($folder)) {
                mkdir($folder, 0644, true);
            }
            $filename = microtime(true) . '.log';
            $path = "{$folder}/$filename";
            file_put_contents($path, var_export($var, true));
        }
    }

    /**
     *
     * @param \Throwable $e
     * @param boolean $die
     */
    function TryCatchTransToLog($e, $die = false)
    {
        $message = iconv('UTF-8', 'BIG5', $e->getMessage());
        $errorMessage = <<< ERROR_MESSAGE
        Code:            {$e->getCode()}
        File:            {$e->getFile()}
        Line:            {$e->getLine()}
        Message:         {$message}
        Previous:        {$e->getPrevious()}
        Trace as string:
        {$e->getTraceAsString()}
        ERROR_MESSAGE;

        if ($die) {
            echo '<pre>';
            echo $errorMessage;
            echo '</pre>';
            exit();
        }
        logger()->err($errorMessage);
    }

    /**
     * @deprecated
     * @param string $var
     * @param string $type
     * @param string $pattern
     * @return string
     */
    function nameMask($var, $type = null, $pattern = '*')
    {
        $matches = [];
        switch ($type) {
            case "tel":
                preg_match("/(\d{2}(\s{1}|\-{1})\d{2})/", $var, $matches, PREG_OFFSET_CAPTURE, 1);
                if ($matches) {
                    $var = str_replace($matches[0][0], "** **", $var);
                }
                break;
            case "twCellphone":
                $var = mb_convert_kana($var, 'nas');
                $replacedVar = preg_replace('/^\+886/', '', $var);
                $patterns = str_pad('', 4, $pattern);
                $replacedVar = substr_replace($replacedVar, $patterns, - 6, 4);
                if(preg_match('/^\+886/', $var)) {
                    $replacedVar = '+886'.$replacedVar;
                }
                $var = $replacedVar;
                break;
            case "chAddress":
                $replaceZipAddr = preg_replace('/^\d{3,5}/', '', $var);
                $headStr = mb_substr($replaceZipAddr, 0, 6, "UTF-8");
                $maskStr = '';
                for($i=0 ; $i < mb_strlen($replaceZipAddr)-6 ; $i++) {
                    $maskStr.= $pattern;
                }
                $var = $headStr.$maskStr;
                break;
            case "chName":
                $strlen = mb_strlen($var, 'utf-8');
                $firstStr = mb_substr($var, 0, 1, 'utf-8');
                $lastStr = mb_substr($var, - 1, 1, 'utf-8');
                if (mb_strlen($var) == 3) {
                    $lastStr = $pattern;
                }
                return $strlen == 2 ? $firstStr . str_repeat($pattern, mb_strlen($var, 'utf-8') - 1) : $firstStr . str_repeat($pattern, $strlen - 2) . $lastStr;
                break;
            case "email":
                $strlen = mb_strlen($var, 'utf-8');
                $firstStr = mb_substr($var, 0, 1, 'utf-8');
                $lastStr = mb_substr($var, - 1, 1, 'utf-8');
                if (mb_strlen($var) == 3) {
                    $lastStr = $pattern;
                }
                return $strlen == 2 ? $firstStr . str_repeat($pattern, mb_strlen($var, 'utf-8') - 1) : $firstStr . str_repeat($pattern, $strlen - 2) . $lastStr;
                break;
                
            default:
                $strlen = mb_strlen($var, 'utf-8');
                for ($i = 1; $i < ($strlen - 1); $i ++) {
                    if ($var[$i] != " ") {
                        $var[$i] = '*';
                    }
                }
                break;
        }
        return $var;
    }

    function getLezadaVars($routeName)
    {
        if ($lezada = config("lezada")) {
            $vars = [];
            if (is_file('./storage/persists/themesTemplates.json')) {
                $pageStyle = file_get_contents('./storage/persists/themesTemplates.json');
                $pageStyle = json_decode($pageStyle, true);
                foreach ($lezada["pageStyle"] as $key => $value) {
                    if (isset($pageStyle[$key])) {
                        $value = $pageStyle[$key];
                        $lezada["pageStyle"][$key] = $value;
                    }
                }
                if (isset($pageStyle["header"])) {
                    $lezada["layout"]["header"]["templates"][0] = $pageStyle["header"];
                }
                if (isset($pageStyle["footer"])) {
                    $lezada["layout"]["footer"]["templates"][0] = $pageStyle["footer"];
                }
            }

            $vars["lezada"] = $lezada;
            /*
            $separatorToCamelCase = new SeparatorToCamelCase('-');
            $templateName = $separatorToCamelCase->filter($routeName);
            $templateName = ucfirst($templateName);
            $this->defaultTemplate = "app::/lezada/Page/{$templateName}";
            */
            $vars["pageContent"] = $lezada["pageStyle"][$routeName];
            $vars["layout"] = $lezada["layout"];
            return $vars;
        }
        return [];
    }
}
