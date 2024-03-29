<?php
/**
 * Created by PhpStorm.
 * User: zhangkaixiang
 * Date: 2019/7/9
 * Time: 15:57
 */

use Logger\Logger;

require_once '../vendor/autoload.php';

$config = require('config.php');
try{
    Logger::getLogger()->initLogger($config);
    $data = array('url' => '//', 'username' => 'abcd');
    Logger::getLogger()->info('bbbb'.json_encode($data));
    Logger::getLogger()->warning('aaaa', $data);
    Logger::getLogger()->error('ccc');
    Logger::getLogger()->buildParams(array('name' => 'aaa'));
    Logger::getLogger()->buildResponse(1, array('name' => 'aaa'));
    Logger::getLogger()->system()->critical('ddd');
    Logger::getLogger()->warning('aaaa', $data);

}catch (\Logger\LoggerException $e){
    var_dump($e->getMessage());
}
