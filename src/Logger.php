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
use PHPMailer\PHPMailer\PHPMailer;

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
    /**
     * 当前项目的唯一标识
     * 用来区分日志系统中不同项目的日志
     * 用来创建es索引，不能出现大写字母
     */
    const PROJECT_NAME = 'user_center';

    /**
     * redis 配置
     * 日志传送的redis数据库
     * 默认取.env的配置值
     */
    const REDIS_HOST = '192.168.107.107';
    const REDIS_PORT = 6379;
    /**
     * 配置选择的第几个redis库
     * 不能修改
     */
    const REDIS_SELECT = 0;

    /**
     * 收集日志系统通过当前队列取出日志数据
     * 此字段所有项目通用，不能修改
     */
    const REDIS_KEY = 'usercenter_push_log';
    /**
     * 日志系统是否使用redis队列服务
     */
    const REDIS_USED = true;


    /**
     * 日志分类
     * 支持日志分类扩展，需要初始化方法中实现新的日志类型，并实现该类型的调用
     */
    const MODULE_COMMON = 'common';
    const MODULE_SYSTEM = 'system';
    const MODULE_PUSH = 'push';
    const MODULE_Job = 'job';

    /**
     * 本地日志文件位置
     */
    const LOG_DIR = '/runtime/logger';

    /**
     * 本地临时日志文件,脚本抓取该日志的增量内容到es中，并且每小时清空一次
     */
    const TMP_LOG = '/runtime/tmp.log';

    /**
     * 发送邮件配置 目前支持阿里云邮箱
     */
    private $emailConfig = array(
        'host'     => 'smtp.aliyun.com',            // smtp服务器
        'username' => 'fengxinyhyl@aliyun.com',     // 发送邮件的地址(为防止拒收，把该地址加入白名单)
        'password' => 'mLVcNrWUkjjSn35',            // 发送邮件的密码
        'sendTo'   => array(                        // 接收邮件的地址
            'fengxinyhyl@qq.com',
            "602823863@qq.com",
            '969491970@qq.com'
        ),
        'systemAlert'   => 'fengxinyhyl@qq.com',      // 系统异常报警邮箱
        'emailInterval' => 86400,                   // 发送邮件的时间间隔
    );

    private $redis = null;

    // 日志单例对象
    private static $instance = null;

    // 日志id
    private $requestId = null;

    // 默认调用日志子模块
    private $module = null;

    // 日志对象
    private $logs = array();


    /**
     * 获取日志单例对象
     * @return Logger|null
     * @author zhangkaixiang
     */
    public static function getLogger()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
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
        $this->emailRemind('error', $content);
    }

    public function critical($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        $this->getUseAge()->critical($msg, array('context' => $content));
        $this->emailRemind('critical', $content);
    }

    public function alert($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        $this->getUseAge()->alert($msg, array('context' => $content));
        $this->emailRemind('alert', $content);
    }

    public function emergency($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        $this->getUseAge()->emergency($msg, array('context' => $content));
        $this->emailRemind('emergency', $content);
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
        $formatter = new LogstashFormatter(self::PROJECT_NAME, '127.0.0.1', '', '', 1);

        /**
         * redis日志处理器
         */
        $redis        = $this->getRedisHandler();
        if(empty($redis)){
            $redisHandler = false;
        }else{
            $redisHandler = new RedisHandler($redis, self::REDIS_KEY);
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
                $record['extra']['projectName'] = self::PROJECT_NAME;
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

        if (self::REDIS_USED and $redisHandler) {
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

        if (self::REDIS_USED and $redisHandler) {
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

        if (self::REDIS_USED and $redisHandler) {
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

        if (self::REDIS_USED and $redisHandler) {
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
     * @return \Redis | boolean
     * @author zhangkaixiang
     */
    private function getRedisHandler()
    {
        if (isset($this->redis)) {
            return $this->redis;
        }
        // 创建redis处理器
        $redis = new \Redis();
        try {
//            $redis->connect(Env::get('ELK_REDIS_HOST', self::REDIS_HOST),
//                Env::get('ELK_REDIS_PORT', self::REDIS_PORT), 1);
            $redis->connect('192.168.1.1', '6379', 1);
        }catch (\Exception $e){
            $this->systemAlert();
            $redis = false;
        }

        if(empty($redis)){
            $this->redis =  false;
            return false;
        }

        $redis->select(self::REDIS_SELECT);
        $this->redis = $redis;
        return $this->redis;
    }


    /**
     * 邮件提醒
     * @param $type
     * @param $content
     * @throws \PHPMailer\PHPMailer\Exception
     * @author zhangkaixiang
     */
    private function emailRemind($type, $content)
    {
        $redis = $this->getRedisHandler();
        if(empty($redis)){
            return false;
        }
        $cacheKey = self::PROJECT_NAME . ':emailRemind:' . $type;
        $exist    = $redis->get($cacheKey);
        if ($exist === false) {
            $body = "<h1>".date('Y-m-d H:i:s') . "系统发生错误。RequestId : " . $this->requestId . "。</h1> <h1>内容 : " . $content . "。</h1>";
            $this->sendEmail(self::PROJECT_NAME . ' ' . $type, $body);
            $redis->set($cacheKey, '1', $this->emailConfig['emailInterval']);
        }
    }


    /**
     * 系统报警
     * @param $type
     * @param $content
     * @throws \PHPMailer\PHPMailer\Exception
     * @author zhangkaixiang
     */
    private function systemAlert()
    {
        $redis = new \Redis();
        $redis->connect(Env::get('CACHE_HOST', self::REDIS_HOST),
            Env::get('CACHE_PORT', self::REDIS_PORT), 1);

        if(empty($redis)){
            return false;
        }
        $cacheKey = self::PROJECT_NAME . ':systemAlert';
        $exist    = $redis->get($cacheKey);
        if ($exist === false) {
            $body = "<h1>".date('Y-m-d H:i:s') . " elk redis 链接失败。</h1>";
            $this->sendEmail('系统报警 ' , $body, true);
            $redis->set($cacheKey, '1', $this->emailConfig['emailInterval']);
        }
    }


    /**
     * 发送邮件 目前适配阿里云邮箱
     * @param $subject
     * @param $body
     * @param boolean $systemAlert
     * @return bool
     * @throws \PHPMailer\PHPMailer\Exception
     * @author zhangkaixiang
     */
    private function sendEmail($subject, $body, $systemAlert = true)
    {
        $mail = new PHPMailer();
        $mail->isSMTP();                                        // Set mailer to use SMTP
        $mail->Host       = $this->emailConfig['host'];         // Specify main and backup SMTP servers
        $mail->SMTPAuth   = true;                               // Enable SMTP authentication
        $mail->Username   = $this->emailConfig['username'];     // SMTP username
        $mail->Password   = $this->emailConfig['password'];     // SMTP password
        $mail->SMTPSecure = 'ssl';                              // Enable TLS encryption, `ssl` also accepted
        $mail->Port       = 465;                                // TCP port to connect to
        $mail->CharSet    = "utf-8";
        $mail->setFrom($this->emailConfig['username'], self::PROJECT_NAME);
        $mail->AddReplyTo($this->emailConfig['username'],self::PROJECT_NAME);
        if($systemAlert){
            $mail->addAddress($this->emailConfig['systemAlert']);
        }else{
            foreach ($this->emailConfig['sendTo'] as $email) {
                $mail->addAddress($email, $email);                      // Add a recipient
            }
        }
        $mail->isHTML(true);                             // Set email format to HTML
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $re            = $mail->send();

        return $re;
    }


    /**
     * 获取本地的日志位置，每小时一个文件
     * path: /runtime/logger/201902/25/09.log
     * @return bool|string
     * @author zhangkaixiang
     */
    private function getLogPath()
    {
        $logDir        = APP_PATH . '..' . self::LOG_DIR;
        $date          = date('Ym/d');
        $hour          = date('H');
        $currentLogDir = $logDir . '/' . $date; // APP_PATH.runtime/logger/201902/20
        if (!is_dir($currentLogDir)) {

            $mkRet = mkdir($currentLogDir, 0755, true);
            if(empty($mkRet)){
                return APP_PATH . '..' . self::TMP_LOG;
            }

            // 删除三个月之前的日志
            $beforeThreeMonth = date('Ym/d', strtotime('-3 months'));
            $deleteDir = $logDir . '/' . $beforeThreeMonth;
            $this->delDir($deleteDir);

            if ($mkRet) {
                return $currentLogDir . '/' . $hour . '.log';
            }
            return APP_PATH . '..' . self::TMP_LOG;
        } else {
            return $currentLogDir . '/' . $hour . '.log';
        }
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