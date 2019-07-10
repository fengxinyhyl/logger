<?php
/**
 * Created by PhpStorm.
 * User: zhangkaixiang
 * Date: 2019/7/10
 * Time: 11:06
 */

namespace Logger;


/**
 * common function
 * Class Common
 * @package Logger
 */
class Common
{
    private $requestId = null;
    const REQUEST_ID_LEN = 10;

    /**
     * notes: 返回redis句柄
     * @param array $redisConfig
     * @return bool|\Redis
     * @create: 2018/12/27 15:13
     * @update: 2019/7/10 11:24
     * @author: zhangkaixiang
     * @editor:
     */
    public function getRedisHandler(array $redisConfig)
    {
        // 创建redis处理器
        $redis = new \Redis();
        try {
            $redis->connect($redisConfig['host'], $redisConfig['port'], 1);
        } catch (\Exception $e) {
            return false;
        }

        $redis->select($redisConfig['select']);
        return $redis;
    }


    /**
     * 获取本次请求的唯一id
     * 通过关联当前的唯一id，查找本次请求的所有日志
     * @return string
     * @author zhangkaixiang
     */
    public function getRequestId()
    {
        if ($this->requestId) {
            return $this->requestId;
        }
        // @todo 从header取传递过来的requestId
//        if(isset($_SERVER['HTTP_REQUESTID']) and strlen($_SERVER['HTTP_REQUESTID']) == self::REQUEST_ID_LEN+14){
//            $this->requestId = $_SERVER['HTTP_REQUESTID'];
//        }
        $string = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $retStr = '';
        $strLen = strlen($string);
        for ($i = 0; $i < self::REQUEST_ID_LEN; $i++) {
            $retStr .= $string{mt_rand(0, $strLen - 1)};
        }
        $this->requestId = date('YmdHis') . $retStr;
        return $this->requestId;
    }


    /**
     * 获取本地的日志位置，每小时一个文件
     * path: /yourLogDir/201902/25/09.log
     * @param $logDir
     * @return bool|string
     * @author zhangkaixiang
     */
    public function getLogPath($logDir)
    {
        $tmpLog        = $logDir . '/tmp.log';
        $date          = date('Ym/d');
        $hour          = date('H');
        $currentLogDir = $logDir . '/' . $date;

        if (!is_dir($currentLogDir)) {
            $mkRet = mkdir($currentLogDir, 0755, true);
            if (empty($mkRet)) {
                return $tmpLog;
            }

            // 删除三个月之前的日志
            $beforeThreeMonth = date('Ym/d', strtotime('-3 months'));
            $deleteDir        = $logDir . '/' . $beforeThreeMonth;
            @$this->delDir($deleteDir);

            if ($mkRet) {
                return $currentLogDir . '/' . $hour . '.log';
            }
            return $logDir . '/tmp.log';
        } else {
            return $currentLogDir . '/' . $hour . '.log';
        }
    }


    /**
     * 删除指定的文件夹
     * @param $dirName
     * @return bool
     */
    public function delDir($dirName)
    {
        if (!is_dir($dirName)) {
            return false;
        }
        //先删除目录下的文件：
        $dh = opendir($dirName);
        while ($file = readdir($dh)) {
            if ($file != "." && $file != "..") {
                $objPath = $dirName . "/" . $file;
                if (!is_dir($objPath)) {
                    unlink($objPath);
                } else {
                    $this->delDir($objPath);
                }
            }
        }

        closedir($dh);
        //删除当前文件夹：
        if (rmdir($dirName)) {
            return true;
        } else {
            return false;
        }
    }
}