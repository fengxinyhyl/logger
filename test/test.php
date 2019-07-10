<?php
/**
 * Created by PhpStorm.
 * User: zhangkaixiang
 * Date: 2019/7/9
 * Time: 15:57
 */

require_once '../vendor/autoload.php';

$config = require('config.php');
var_dump($config);
try{
    $logger = \Logger\Logger::getLogger($config);
}catch (\Logger\LoggerException $e){
    var_dump($e->getMessage());
}
