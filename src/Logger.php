<?php
/**
 * Created by PhpStorm.
 * User: zhangkaixiang
 * Date: 2018/12/27
 * Time: 17:18
 */

namespace Logger;

use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\RedisHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\WebProcessor;

/**
 * 日志模块
 * Class Logger
 * @package app\common\lib
 *
 * usage example:
 *
 *  Logger::getLogger()->notice('this is a notice', array('foo' => 'bar'));
 *  Logger::getLogger()->system()->error('this is a error msg', array('mysql' => 'The ....'));
 *
 * composer安装
 * "require": {
 *   "monolog/monolog": "1.22.*",
 *   "phpmailer/phpmailer":"~6.0",
 *   "ext-redis": "*"
 *   },
 *
 * 额外的操作
 *  buildParams
 *  buildResponse
 *  CacheException
 *
 * 错误级别定义：
 * 调试/DEBUG (100): 详细的调试信息。
 * 信息/INFO (200): 有意义的事件，比如用户登录、SQL日志。
 * 提示/NOTICE (250): 正常但是值得注意的事件。
 * 警告/WARNING (300): 异常事件，但是并不是错误。比如使用了废弃了的API，错误地使用了一个API，以及其他不希望发生但是并非必要的错误。
 * 错误/ERROR (400): 运行时的错误，不需要立即注意到，但是需要被专门记录并监控到。
 * 严重/CRITICAL (500): 边界条件/危笃场景。比如应用组件不可用了，未预料到的异常。
 * 警报/ALERT (550): 必须立即采取行动。比如整个网站都挂了，数据库不可用了等。这种情况应该发送短信警报，并把你叫醒。
 * 紧急/EMERGENCY (600): 紧急请求：系统不可用了。
 */
class Logger
{
    /************************************ user config start **************************************************/
    /**
     * 当前项目的唯一标识
     * 用来区分日志系统中不同项目的日志
     * 用来创建es索引，不能出现大写字母
     */
    private static $projectName = 'user_center';

    /**
     * elk redis 配置
     * 日志传送的redis数据库
     */
    private static $elkRedisConfig = array(
        'host' => '192.168.107.107',
        'port' => 6379,
        // 配置选择的第几个redis库, 不能修改
        'select' => 0,
        // 收集日志系统通过当前队列取出日志数据
        // 此字段所有项目通用，不能修改
        'key' => 'usercenter_push_log',
    );

    /**
     * elk redis 配置
     * 日志传送的redis数据库
     */
    private static $redisConfig = array(
        'host' => '192.168.107.107',
        'port' => 6379,
        'select' => 0,
    );

    /**
     * 日志系统是否使用redis队列服务
     */
    private static $useElkRedis = true;

    /**
     * 本地日志文件位置
     */
    const LOG_DIR = '/runtime/logger';

    /**
     * 发送邮件配置 目前支持阿里云邮箱
     */
    private static $emailConfig = array(
        'host'     => 'smtp.aliyun.com',            // smtp服务器
        'username' => 'fengxinyhyl@aliyun.com',     // 发送邮件的地址(为防止拒收，把该地址加入白名单)
        'password' => 'mLVcNrWUkjjSn35',            // 发送邮件的密码

        // 接收邮件的地址
        'sendTo'   => array(
            'fengxinyhyl@qq.com',
            "602823863@qq.com",
            '969491970@qq.com'
        ),
        // 缓存系统异常报警邮箱
        'systemAlert'   => array(
            'fengxinyhyl@qq.com',
        ),
        // 是否开启常规提醒，开启后出现error,critical类型错误会发送提醒邮件
        'normalRemind'  => false,
        // 常规提醒的时间间隔(秒)
        'normalInterval' => 86400,
    );

    /**
     * 短信报警配置
     * 每小时报警次数是alertCondition的整数倍会发送报警短信
     * 每小时至多发送5条
     */
    private static $smsConfig = array(
        'phone' => array(),
        'alertCondition' => 10,
    );

    /**
     * 本地临时日志文件,脚本抓取该日志的增量内容到es中，并且每小时清空一次
     */
    const TMP_LOG = '/runtime/tmp.log';

    /************************************** user config end ***************************************************/

    private $elkRedis = null;
    private $redis = null;

    /**
     * 日志分类
     * 支持日志分类扩展，需要初始化方法中实现新的日志类型，并实现该类型的调用
     */
    const MODULE_COMMON = 'common';
    const MODULE_SYSTEM = 'system';
    const MODULE_PUSH = 'push';
    const MODULE_Job = 'job';

    // 日志单例对象
    private static $instance = null;

    // 日志id
    private $requestId = null;

    // 默认调用日志子模块
    private $module = null;

    // 日志对象
    private $logs = array();


    /**
     * notes: 获取日志单例对象
     * @param array $config
     * @return null
     * @throws LoggerException
     * @create: 2019/7/9 18:07
     * @update: 2019/7/9 18:07
     * @author: zhangkaixiang
     * @editor:
     */
    public static function getLogger(array $config = array())
    {
        if (!self::$instance) {
            self::configLogger($config);
            self::$instance = new self();
        }
        return self::$instance;
    }


    /**
     * notes: 根据配置信息设置logger
     * @param array $config
     * @throws LoggerException
     * @create: 2019/7/9 17:31
     * @update: 2019/7/9 17:31
     * @author: zhangkaixiang
     * @editor:
     */
    private static function configLogger(array $config){
        if(empty($config) or !is_array($config)){
            throw new LoggerException('请传入配置参数');
        }
        // 1.项目名称
        if(isset($config['projectName']) and is_string($config['projectName'])){
            self::$projectName = strtolower($config['projectName']);
        }else{
            throw new LoggerException('配置信息，projectName不合法');
        }

        // 2.elk redis 配置，理论上配置不允许修改，但开放修改接口
        if(isset($config['elkRedisConfig']) and is_array($config['elkRedisConfig'])){
            $elkRedis = $config['elkRedisConfig'];
            if(isset($elkRedis['host'])){
                self::$elkRedisConfig['host'] = $elkRedis['host'];
            }
            if(isset($elkRedis['port'])){
                self::$elkRedisConfig['port'] = $elkRedis['port'];
            }
            if(isset($elkRedis['select'])){
                self::$elkRedisConfig['select'] = $elkRedis['select'];
            }
            if(isset($elkRedis['key'])){
                self::$elkRedisConfig['key'] = $elkRedis['key'];
            }
        }

        // 3.项目redis缓存配置
        if(isset($config['redisConfig']) and is_array($config['redisConfig'])){
            $redis = $config['redisConfig'];
            if(isset($redis['host'])){
                self::$redisConfig['host'] = $redis['host'];
            }else{
                throw new LoggerException('配置信息，redisConfig的host没有配置');
            }
            if(isset($redis['port'])){
                self::$redisConfig['port'] = $redis['port'];
            }
            if(isset($redis['select'])){
                self::$redisConfig['select'] = $redis['select'];
            }
        }

        // 4.邮件提醒
        if(isset($config['emailConfig']) and is_array($config['emailConfig'])){
            $email = $config['emailConfig'];
            // 提醒报警邮件
            if(isset($email['sendTo']) and is_array($email['sendTo'])){
                self::$emailConfig['sendTo'] = $email['sendTo'];
            }
            // 系统不可用时的报警邮件
            if(isset($email['systemAlert']) and is_array($email['systemAlert'])){
                self::$emailConfig['systemAlert'] = $email['systemAlert'];
            }
            // 是否开启普通的提醒
            if(isset($email['normalRemind'])){
                self::$emailConfig['normalRemind'] = $email['normalRemind'];
            }
            // 普通提醒的时间间隔
            if(isset($email['normalInterval'])){
                self::$emailConfig['normalInterval'] = $email['normalInterval'];
            }
        }

        // 5.短信报警
        if(isset($config['smsConfig']) and is_array($config['smsConfig'])){
            $sms = $config['smsConfig'];
            if(isset($sms['phone']) and is_array($sms['phone']) and $sms['phone']){
                self::$smsConfig['phone'] = $sms['phone'];
            }else{
                throw new LoggerException('配置信息，短信报警手机号不合法');
            }
            if(isset($sms['alertCondition']) and is_numeric($sms['alertCondition'])){
                self::$smsConfig['alertCondition'] = $sms['alertCondition'];
            }
        }else{
            throw new LoggerException('配置信息，短信报警配置为空');
        }
    }


    /**
     * 日志类型调用
     * 访问系统日志对象
     * @return $this
     * @author zhangkaixiang
     */
    public function system()
    {
        $this->module = self::MODULE_SYSTEM;
        return $this;
    }


    /**
     * 日志类型调用
     * 常规日志对象
     * @return $this
     * @author zhangkaixiang
     */
    public function common()
    {
        $this->module = self::MODULE_COMMON;
        return $this;
    }


    /**
     * 日志类型调用
     * 对外推送日志对象
     * @return $this
     * @author zhangkaixiang
     */
    public function push()
    {
        $this->module = self::MODULE_PUSH;
        return $this;
    }


    /**
     * 日志类型调用
     * 定时任务日志对象
     * @return $this
     * @author zhangkaixiang
     */
    public function job()
    {
        $this->module = self::MODULE_Job;
        return $this;
    }


    /**
     * 输出日志
     * @param string $msg
     * @param array $context
     * @author zhangkaixiang
     */
    public function debug($msg, array $context = array())
    {
        $this->getUseAge()->debug($msg, array('context' => json_encode($context, JSON_UNESCAPED_UNICODE)));
    }

    public function info($msg, array $context = array())
    {
        // 处理response字段被两次encode的情况
        if (isset($context['response']) and is_string($context['response'])) {
            $response = json_decode($context['response'], true);
            if ($response) {
                $context['response'] = $response;
            }
        }
        $this->getUseAge()->info($msg, array('context' => json_encode($context, JSON_UNESCAPED_UNICODE)));
    }

    public function notice($msg, array $context = array())
    {
        $this->getUseAge()->notice($msg, array('context' => json_encode($context, JSON_UNESCAPED_UNICODE)));
    }

    public function warn($msg, array $context = array())
    {
        $this->getUseAge()->warn($msg, array('context' => json_encode($context, JSON_UNESCAPED_UNICODE)));
    }

    public function error($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        $this->getUseAge()->error($msg, array('context' => $content));
//        $this->emailRemind('error', $content);
    }

    public function critical($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        $this->getUseAge()->critical($msg, array('context' => $content));
//        $this->emailRemind('critical', $content);
    }

    public function alert($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        $this->getUseAge()->alert($msg, array('context' => $content));
//        $this->emailRemind('alert', $content);
    }

    public function emergency($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        $this->getUseAge()->emergency($msg, array('context' => $content));
//        $this->emailRemind('emergency', $content);
    }

    /**
     * 获取日志对象实例
     * @return mixed
     */
    public function getUseAge()
    {
        if (empty($this->logs)) {
            // todo 初始化日志失败处理
            $this->initLogs();
        }
        if ($this->module) {
            return $this->logs[$this->module];
        } else {
            return $this->logs[self::MODULE_COMMON];
        }
    }


    /**
     * 初始化日志方法
     * @author zhangkaixiang
     * @throws \Exception
     */
    private function initLogs()
    {
        // 日志格式化
        $formatter = new LogstashFormatter(self::$projectName, '127.0.0.1', '', '', 1);

        /**
         * redis日志处理器
         */
        $redis        = $this->getRedisHandler();
        if(empty($redis)){
            $redisHandler = false;
        }else{
            $redisHandler = new RedisHandler($redis, self::$elkRedisConfig['key']);
        }

        /**
         * 本地文件处理器
         */
        $logPath       = $this->getLogPath();
        $streamHandler = new StreamHandler($logPath, Monolog::DEBUG);

        /**
         * tmp日志处理，用来日志抓取脚本同步到日志服务器
         * @todo tmp日志逐步删除
         */
        $tmpLog = APP_PATH . '..' . self::TMP_LOG;
        if (!file_exists($logPath)) {
            // 如果文件不存在，则说明已经已经超过一个小时，清理tmp日志
            if (file_exists($tmpLog)) {
                @unlink($tmpLog);
            }
        }
        $tmpHandler = new StreamHandler($tmpLog, Monolog::DEBUG);

        $commonLog = $this->getCommonLog($formatter, $redisHandler, $streamHandler, $tmpHandler);
        $sysLog    = $this->getSysLog($formatter, $redisHandler, $streamHandler, $tmpHandler);
        $pushLog   = $this->getPushLog($formatter, $redisHandler, $streamHandler, $tmpHandler);
        $jobLog    = $this->getJobLog($formatter, $redisHandler, $streamHandler, $tmpHandler);

        $this->logs = array(
            self::MODULE_COMMON => $commonLog,
            self::MODULE_SYSTEM => $sysLog,
            self::MODULE_PUSH   => $pushLog,
            self::MODULE_Job    => $jobLog,
        );

        // 获取本次请求的唯一id,并添加到所有的日志句柄中
        $requestId = $this->requestId = $this->getRequestId();
        foreach ($this->logs as $log) {
            $log->pushProcessor(function ($record) use ($requestId) {
                $record['extra']['requestId']   = $requestId;
                $record['extra']['ip']          = $_SERVER['REMOTE_ADDR'];
                $record['extra']['projectName'] = self::$projectName;
                return $record;
            });
        }
    }


    /**
     * 获取常规日志对象
     * @param $formatter
     * @param $redisHandler
     * @param StreamHandler $streamHandler
     * @param StreamHandler $tmpHandler
     * @return Monolog
     */
    private function getCommonLog($formatter, $redisHandler,
                                  StreamHandler $streamHandler, StreamHandler $tmpHandler)
    {
        // 初始化日志对象
        $logger = new Monolog(self::MODULE_COMMON);

        if (self::$useElkRedis and $redisHandler) {
            $redisHandler->setFormatter($formatter);
            $logger->pushHandler($redisHandler);
        }

        $streamHandler->setFormatter($formatter);
        $logger->pushHandler($streamHandler);

        $tmpHandler->setFormatter($formatter);
        $logger->pushHandler($tmpHandler);

        // 添加打印日志的位置
        $logger->pushProcessor(new IntrospectionProcessor(Monolog::DEBUG, array(), 1));

        return $logger;
    }


    /**
     * 获取推送日志对象
     * @param $formatter
     * @param $redisHandler
     * @param StreamHandler $streamHandler
     * @param StreamHandler $tmpHandler
     * @return Monolog
     */
    private function getPushLog($formatter, $redisHandler,
                                StreamHandler $streamHandler, StreamHandler $tmpHandler)
    {
        // 初始化日志对象
        $logger = new Monolog(self::MODULE_PUSH);

        if (self::$useElkRedis and $redisHandler) {
            $redisHandler->setFormatter($formatter);
            $logger->pushHandler($redisHandler);
        }

        $streamHandler->setFormatter($formatter);
        $logger->pushHandler($streamHandler);

        $tmpHandler->setFormatter($formatter);
        $logger->pushHandler($tmpHandler);

        // 添加打印日志的位置
        $logger->pushProcessor(new IntrospectionProcessor(Monolog::DEBUG, array(), 1));

        return $logger;
    }


    /**
     * 获取定时任务日志对象
     * @param $formatter
     * @param $redisHandler
     * @param StreamHandler $streamHandler
     * @param StreamHandler $tmpHandler
     * @return Monolog
     */
    private function getJobLog($formatter, $redisHandler,
                               StreamHandler $streamHandler, StreamHandler $tmpHandler)
    {
        // 初始化日志对象
        $logger = new Monolog(self::MODULE_Job);

        if (self::$useElkRedis and $redisHandler) {
            $redisHandler->setFormatter($formatter);
            $logger->pushHandler($redisHandler);
        }

        $streamHandler->setFormatter($formatter);
        $logger->pushHandler($streamHandler);

        $tmpHandler->setFormatter($formatter);
        $logger->pushHandler($tmpHandler);

        // 添加打印日志的位置
        $logger->pushProcessor(new IntrospectionProcessor(Monolog::DEBUG, array(), 1));

        return $logger;
    }


    /**
     * 获取系统日志对象
     * @param $formatter
     * @param $redisHandler
     * @param StreamHandler $streamHandler
     * @param StreamHandler $tmpHandler
     * @return Monolog
     * @author zhangkaixiang
     */
    private function getSysLog($formatter, $redisHandler,
                               StreamHandler $streamHandler, StreamHandler $tmpHandler)
    {
        // 初始化日志对象
        $logger = new Monolog(self::MODULE_SYSTEM);

        if (self::$useElkRedis and $redisHandler) {
            $redisHandler->setFormatter($formatter);
            $logger->pushHandler($redisHandler);
        }

        $streamHandler->setFormatter($formatter);
        $logger->pushHandler($streamHandler);

        $tmpHandler->setFormatter($formatter);
        $logger->pushHandler($tmpHandler);

        // 添加当前请求的相关信息
        $logger->pushProcessor(new WebProcessor(null, array(
            'url'         => 'REQUEST_URI',
            'real_ip'     => 'HTTP_X_REAL_IP',
            'http_method' => 'REQUEST_METHOD',
            'server'      => 'SERVER_NAME',
            'referrer'    => 'HTTP_REFERER',
        )));
        // 添加最大使用内存信息
        $logger->pushProcessor(new MemoryPeakUsageProcessor());

        return $logger;
    }


    /**
     * 获取本次请求的唯一id
     * 通过关联当前的唯一id，查找本次请求的所有日志
     * @param int $length
     * @return string
     * @author zhangkaixiang
     */
    private function getRequestId($length = 10)
    {
        $string = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $retStr = '';
        $strLen = strlen($string);
        for ($i = 0; $i < $length; $i++) {
            $retStr .= $string{mt_rand(0, $strLen - 1)};
        }
        return date('YmdHis') . $retStr;
    }


    /**
     * 返回redis句柄
     * @param array $redisConfig
     * @return \Redis | boolean
     * @throws \PHPMailer\PHPMailer\Exception
     * @author zhangkaixiang
     */
    private function getRedisHandler(array $redisConfig = array())
    {
        if (isset($this->redis)) {
            return $this->redis;
        }
        // 创建redis处理器
        $redis = new \Redis();
        try {
            $redis->connect($redisConfig['host'], $redisConfig['port'], 1);
        }catch (\Exception $e){
//            $this->systemAlert();
            $redis = false;
        }

        if(empty($redis)){
            $this->redis =  false;
            return false;
        }

        $redis->select($redisConfig['select']);
        $this->redis = $redis;
        return $this->redis;
    }





    /**
     * 获取本地的日志位置，每小时一个文件
     * path: /runtime/logger/201902/25/09.log
     * @return bool|string
     * @author zhangkaixiang
     */
    private function getLogPath()
    {
        return './tmp.txt';
//        $logDir        = APP_PATH . '..' . self::LOG_DIR;
//        $date          = date('Ym/d');
//        $hour          = date('H');
//        $currentLogDir = $logDir . '/' . $date; // APP_PATH.runtime/logger/201902/20
//        if (!is_dir($currentLogDir)) {
//
//            $mkRet = mkdir($currentLogDir, 0755, true);
//            if(empty($mkRet)){
//                return APP_PATH . '..' . self::TMP_LOG;
//            }
//
//            // 删除三个月之前的日志
//            $beforeThreeMonth = date('Ym/d', strtotime('-3 months'));
//            $deleteDir = $logDir . '/' . $beforeThreeMonth;
//            $this->delDir($deleteDir);
//
//            if ($mkRet) {
//                return $currentLogDir . '/' . $hour . '.log';
//            }
//            return APP_PATH . '..' . self::TMP_LOG;
//        } else {
//            return $currentLogDir . '/' . $hour . '.log';
//        }
    }


    /**
     * 删除指定的文件夹
     * @param $dirName
     * @return bool
     */
    private  function delDir($dirName){
//        if (!is_dir($dirName)){
//            return false;
//        }
        //先删除目录下的文件：
        $dh=opendir($dirName);
        while ($file=readdir($dh)) {
            if($file!="." && $file!="..") {
                $objPath=$dirName."/".$file;
                if(!is_dir($objPath)) {
                    unlink($objPath);
                } else {
                    $this->delDir($objPath);
                }
            }
        }

        closedir($dh);
        //删除当前文件夹：
        if(rmdir($dirName)) {
            return true;
        } else {
            return false;
        }
    }
}