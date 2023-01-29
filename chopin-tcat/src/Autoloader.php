<?php

namespace Chopin\Tcat;

class Autoloader {
    static public function Register() {
        ///home/vagrant/code/junze/vendor/chunghsien/chopin/chopin-tcat/src/Autoloader.php
        ///home/vagrant/code/junze/vendor/chunghsien/chopin/chopin-tcat/src/Autoloader.php
        $path1 = glob(__DIR__.'/*.php');
        foreach ($path1 as $path) {
            if(is_file($path) && $path != __FILE__) {
                require_once $path;
            }
        }
    }
}