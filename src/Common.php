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