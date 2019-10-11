<?php
/**
 * Created by PhpStorm.
 * User: zhangkaixiang
 * Date: 2019/7/10
 * Time: 10:54
 */

namespace Logger;

/**
 * sms SDK
 * Class Sms
 * @package Logger
 */
class Sms
{
    private $projectName = null;

    public function __construct($projectName)
    {
        $this->projectName = $projectName;
    }

    /**
     * notes: 发送报警短信
     * @param \Redis $redis
     * @param array $phones
     * @param int $condition
     * @return bool
     * @create: 2019/7/10 16:43
     * @update: 2019/7/10 16:43
     * @author: zhangkaixiang
     * @editor:
     */
    public function sendSms(\Redis $redis, array $phones, $condition)
    {
        $sendCountKey  = $this->projectName . ':sendSmsCount:' . date('H');
        $errorCountKey = $this->projectName . ':projectErrorCount:' . date('H');
        $sendCount     = $redis->get($sendCountKey) ?: 0;
        $errorCount    = $redis->get($errorCountKey);

        // 发送短信超过5次直接返回
        if ($sendCount >= 5) {
            return false;
        }
        $errorCount = $errorCount ? $errorCount + 1 : 1;
        $redis->set($errorCountKey, $errorCount, 3600);

        if ($errorCount >= $condition * ($sendCount + 1)) {
            if (is_array($phones)) {
                $phones = trim(implode(',', $phones));
            }
            $hour = date('H');
            $msg  = date('Y-m-d H:i:s') . ", 项目：{$this->projectName} 从 {$hour} 点开始, 发生错误 {$errorCount} 次, 报警条件为 {$condition} 次, 需要立即处理！";
            $url  = "http://mysms.house365.com:81/index.php/Interface/apiSendMobil/jid/145/depart/1/city/nj/mobileno/" . $phones . "/?msg=" . urlencode($msg);

            $response  = $this->curl_get_contents($url);
            $sendCount += 1;
            $redis->set($sendCountKey, $sendCount, 3600);
            if ($sendCount >= 3) {
                Logger::getLogger()->alert('系统发生严重错误');
            }
        }
        return true;
    }


    /**
     * curl get 访问
     * @param $url
     * @param array $headers
     * @return mixed
     * @author zhangkaixiang
     */
    public static function curl_get_contents($url, $headers = array())
    {
        $ch = curl_init();
        if (stripos($url, "https://") !== false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSLVERSION, 1);
        }
        curl_setopt($ch, CURLOPT_URL, $url);            //设置访问的url地址
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);           //设置超时
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);      //跟踪301
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);        //返回结果
        curl_setopt($ch, CURLOPT_HEADER, 0);
        if ($headers) {
            $_header = array();
            foreach ($headers as $key => $vo) {
                $_header[] = $key . ':' . $vo;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $_header);
        }
        $r            = curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $r;
    }
}
