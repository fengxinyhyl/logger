<?php
/**
 * Created by PhpStorm.
 * User: zhangkaixiang
 * Date: 2019/7/9
 * Time: 17:24
 */

namespace Logger;

/**
 * logger异常类
 * Class LoggerException
 * @package Logger
 */
class LoggerException extends \Exception
{
    public function __construct($message = '', $code = 0, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}