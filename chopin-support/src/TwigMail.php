<?php

namespace Chopin\Support;

use Laminas\Mail\Message as MailMessage;
use Laminas\Mime\Message as MimeMessage;
use Laminas\Mime\Mime;
use Laminas\Mime\Part as MimePart;
use Twig\Loader\FilesystemLoader;
use Twig\Environment;
use Laminas\Filter\Word\UnderscoreToCamelCase;
use Laminas\Mail\Transport\Sendmail;
use Laminas\Mail\Transport\Smtp as SmtpTransport;
use Laminas\Mail\Transport\SmtpOptions;
use Laminas\Diactoros\ServerRequest;
use Laminas\I18n\Translator\Translator;

abstract class TwigMail
{
    
    /**
     *
     * @param ServerRequest $request
     * @param array $template
     */
    public static function sendOrderedMail(ServerRequest $request, $template)
    {
        $member = $template["vars"]["member"];
        $order = $template["vars"]["order"];
        $indexLang = $request->getAttribute('lang');
        $indexLang = str_replace('-', '_', $indexLang);
        $pageConfig = Registry::get('page_json_config');
        $siteInfoConfig = $pageConfig["system_settings"]["site_info"][$indexLang]["to_config"];
        $template["vars"]["system"] = $pageConfig["system_settings"]["system"]["to_config"];
        $template["vars"]["siteInfo"] = $siteInfoConfig;
        $translator = new Translator();
        $translator->addTranslationFilePattern('phpArray', PROJECT_DIR . '/resources/languages/', '%s/site-translation.php', 'site-translation');
        $translator->setLocale($request->getAttribute('php_lang'));
        Registry::set('laminasTranslator', $translator);
        // logger()->debug(json_encode($template["vars"], JSON_UNESCAPED_UNICODE));
        $orderFullname = $member["full_name"];
        $recepientFullName = $order["fullname"];
        $recepientCellphone = nameMask($order["cellphone"], 'twCellphone');
        $recepientAddress = $order["address"];
        if (preg_match('/^zh/', $indexLang)) {
            $orderFullname = nameMask($orderFullname, "chName");
            $recepientFullName = nameMask($recepientFullName, "chName");
            $recepientAddress = nameMask($recepientAddress, "chAddress");
        }
        $template["vars"]["member"]["full_name"] = $orderFullname;
        $template["vars"]["order"]["fullname"] = $recepientFullName;
        $template["vars"]["order"]["cellphone"] = $recepientCellphone;
        $storeName = $siteInfoConfig["name"];
        $emailSubJect = $translator->translate('thanks ordered mail subject', 'site-translation');
        $emailSubJect = str_replace('%member_fullname%', $orderFullname, $emailSubJect);
        $emailSubJect = str_replace('%store_name%', $storeName, $emailSubJect);
        
        $memberMail = $member["email"];
        $orderMail = $order["email"];
        $to = [
            $memberMail
        ];
        if ($memberMail != $orderMail) {
            $to[] = $orderMail;
        }
        $mailConfig = $pageConfig["system_settings"]["mail-service"]["to_config"];
        
        $serviceEmail = $siteInfoConfig["email"];
        $serviceName = $siteInfoConfig["email_service_from_name"];
        self::mail([
            "to" => $to,
            "bcc" => $serviceEmail,
            "from" => [
                $serviceEmail,
                $serviceName
            ],
            "subject" => $emailSubJect,
            "template" => $template,
            "transport" => $mailConfig
        ]);
    }
    
    public static function mail(array $options, array $headLines = [])
    {
        /*
         * $options = [
         * 'to' => 'xxx@gmai.com',
         * 'subject' => 'Subject',
         * 'template' => [
         * 'path' => '',
         * 'name' => '',
         * 'vars' => [],
         * ],
         * 'cc' => [],
         * 'bcc' => [],
         * 'reply_to' => [],
         * 'transport' => [
         * 'method' => sendmail|smtp,
         * 'options' => []
         * ],
         * ];
         */
        $path = $options['template']['path'];
        $name = $options['template']['name'];
        $loader = new FilesystemLoader($path);
        $twig = new Environment($loader);
        $twig->addExtension(new \App\TwigExtension\Translator());
        $vars = $options['template']['vars'];
        $htmlMarkup = $twig->render($name, $vars);
        file_put_contents('./storage/recive_order.html', $htmlMarkup);
        $html = new MimePart($htmlMarkup);
        $html->type = Mime::TYPE_HTML;
        $html->charset = 'utf-8';
        $html->encoding = Mime::ENCODING_QUOTEDPRINTABLE;
        // echo $htmlMarkup;
        // exit();
        $body = new MimeMessage();
        $body->addPart($html);
        $mail = new MailMessage();
        $mail->getHeaders()->setEncoding('UTF-8');
        $mail->setBody($body);
        if ($headLines) {
            $headers = $mail->getHeaders();
            foreach ($headLines as $headLine) {
                $headers->addHeaderLine($headLine[0], $headLine[1]);
            }
        }
        $allowParams = [
            'from',
            'to',
            'subject',
            'cc',
            'bcc',
            'reply_to'
        ];
        $underscoreToCamelCase = new UnderscoreToCamelCase();
        foreach ($options as $key => $param) {
            if (false !== array_search($key, $allowParams, true)) {
                $func = 'set' . ucfirst($underscoreToCamelCase->filter($key));
                if ($func == 'setTo') {
                    foreach ($param as $to) {
                        if (is_array($to)) {
                            call_user_func_array([
                                $mail,
                                'setTo'
                            ], $to);
                        } else {
                            $mail->setTo($to);
                        }
                    }
                    continue;
                }
                if ($func == "setFrom") {
                    $mail->setFrom(trim($param[0]), $param[1]);
                    continue;
                }
                if (is_array($param)) {
                    call_user_func_array([
                        $mail,
                        $func
                    ], $param);
                }
                if (is_string($param)) {
                    $mail->{$func}($param);
                }
            }
        }
        $trsnsport = null;
        if (preg_match("/sendmail$/", $options['transport']['mail_method']) || $options['transport']['mail_method'] == 'none') {
            $trsnsport = new Sendmail();
        }
        
        if ($options['transport']['mail_method'] == 'smtp') {
            $trsnsport = new SmtpTransport();
            $transportOptions = [];
            foreach ($options['transport'] as $key => $value) {
                switch ($key) {
                    case "name":
                    case "host":
                    case "port":
                        if ($value) {
                            $transportOptions[$key] = $value;
                        }
                        break;
                    case "ssl":
                    case "username":
                    case "password":
                        if (empty($transportOptions["connection_class"])) {
                            $transportOptions["connection_class"] = \Laminas\Mail\Protocol\Smtp\Auth\Login::class;
                        }
                        if (empty($transportOptions["connection_config"])) {
                            $transportOptions["connection_config"] = [];
                        }
                        $transportOptions["connection_config"][$key] = $value;
                        break;
                }
            }
            $smtpOptions = new SmtpOptions($transportOptions);
            $trsnsport->setOptions($smtpOptions);
        }
        $trsnsport->send($mail);
    }
}
