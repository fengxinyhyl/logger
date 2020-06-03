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
    private $projectName = 'user_center';

    /**
     * elk redis 配置
     * 日志传送的redis数据库
     */
    private $elkRedisConfig = array(
        'host'   => '192.168.107.107',
        'port'   => 6379,
        // 配置选择的第几个redis库, 不能修改
        'select' => 0,
        // 收集日志系统通过当前队列取出日志数据
        // 此字段所有项目通用，不能修改
        'key'    => 'usercenter_push_log',
    );

    /**
     * redis缓存配置
     * 日志传送的redis数据库
     */
    private $redisConfig = array(
        'host'   => '192.168.107.107',
        'port'   => 6379,
        'select' => 0,
    );

    /**
     * 日志系统是否使用redis队列服务
     */
    private $useElkService = true;

    /**
     * 发送邮件配置 目前支持阿里云邮箱
     */
    private $emailConfig = array(
        'host'           => 'smtp.exmail.qq.com',            // smtp服务器
        'username'       => 'zhangkaixiang@house365.com',    // 发送邮件的地址(为防止拒收，把该地址加入白名单)
        'password'       => 'xxxxxxxx',                      // 发送邮件的密码

        // 接收邮件的地址
        'sendTo'         => array(
            'fengxinyhyl@qq.com',
        ),
        // 缓存系统异常报警邮箱
        'systemAlert'    => array(
            'fengxinyhyl@qq.com',
        ),
        // 是否开启常规提醒，开启后出现error,critical类型错误会发送提醒邮件
        'normalRemind'   => false,
        // 常规提醒的时间间隔(秒)
        'normalInterval' => 86400,
    );

    /**
     * 短信报警配置
     * 每小时报警次数是alertCondition的整数倍会发送报警短信
     * 每小时至多发送5条
     */
    private $smsConfig = array(
        'phones'         => array(),
        'alertCondition' => 10,
    );

    /**
     * 日志文件目录
     */
    private $logDir = '/tmp';
    // 日志保留天数，最长设置30天
    private $reservedDays = 7;

    /************************************** user config end ***************************************************/

    private $elkRedis = null;
    private $redis = null;

    // 是否初始化
    private $init = false;

    /**
     * @var Common
     */
    private $commonSDK = null;

    /**
     * @var Email
     */
    private $emailSDK = null;

    /**
     * @var Sms
     */
    private $smsSDK = null;
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
     * 单例模式
     */
    private function __construct()
    {
    }

    public function __clone()
    {
    }


    /**
     * notes:  获取日志单例对象
     * @return Logger
     * @create: 2018/12/27 08:56
     * @update: 2019/7/9 18:07
     * @author: zhangkaixiang
     * @editor:
     */
    public static function getLogger()
    {
        if (!self::$instance) {
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
    public function initLogger(array $config)
    {
        if (empty($config) or !is_array($config)) {
            throw new LoggerException('请传入配置参数');
        }
        // 1.项目名称
        if (isset($config['projectName']) and is_string($config['projectName'])) {
            $this->projectName = strtolower($config['projectName']);
        } else {
            throw new LoggerException('配置信息，projectName不合法');
        }

        // 2.elk redis 配置，理论上配置不允许修改，但开放修改接口
        if (isset($config['elkRedisConfig']) and is_array($config['elkRedisConfig'])) {
            $elkRedis = $config['elkRedisConfig'];
            if (isset($elkRedis['host'])) {
                $this->elkRedisConfig['host'] = $elkRedis['host'];
            }
            if (isset($elkRedis['port'])) {
                $this->elkRedisConfig['port'] = $elkRedis['port'];
            }
            if (isset($elkRedis['select'])) {
                $this->elkRedisConfig['select'] = $elkRedis['select'];
            }
            if (isset($elkRedis['key'])) {
                $this->elkRedisConfig['key'] = $elkRedis['key'];
            }
        }
        // 是否使用elk redis 做为日志传输通道
        if (isset($config['useElkService'])) {
            $this->useElkService = $config['useElkService'];
        }

        // 3.项目redis缓存配置
        if (isset($config['redisConfig']) and is_array($config['redisConfig'])) {
            $redis = $config['redisConfig'];
            if (isset($redis['host'])) {
                $this->redisConfig['host'] = $redis['host'];
            } else {
                throw new LoggerException('配置信息，redisConfig的host没有配置');
            }
            if (isset($redis['port'])) {
                $this->redisConfig['port'] = $redis['port'];
            }
            if (isset($redis['select'])) {
                $this->redisConfig['select'] = $redis['select'];
            }
        }

        // 4.邮件提醒
        if (isset($config['emailConfig']) and is_array($config['emailConfig'])) {
            $email = $config['emailConfig'];
            // 邮件服务器配置
            if(isset($email['host']) and !empty($email['host'])){
                $this->emailConfig['host'] = $email['host'];
            }
            if(isset($email['username']) and !empty($email['username'])){
                $this->emailConfig['username'] = $email['username'];
            }
            if(isset($email['password']) and !empty($email['password'])){
                $this->emailConfig['password'] = $email['password'];
            }

            // 提醒报警邮件
            if (isset($email['sendTo']) and is_array($email['sendTo'])) {
                $this->emailConfig['sendTo'] = $email['sendTo'];
            }
            // 系统不可用时的报警邮件
            if (isset($email['systemAlert']) and is_array($email['systemAlert'])) {
                $this->emailConfig['systemAlert'] = $email['systemAlert'];
            }
            // 是否开启普通的提醒
            if (isset($email['normalRemind'])) {
                $this->emailConfig['normalRemind'] = $email['normalRemind'];
            }
            // 普通提醒的时间间隔
            if (isset($email['normalInterval'])) {
                $this->emailConfig['normalInterval'] = $email['normalInterval'];
            }
        }

        // 5.短信报警
        if (isset($config['smsConfig']) and is_array($config['smsConfig'])) {
            $sms = $config['smsConfig'];
            if (isset($sms['phones']) and is_array($sms['phones']) and $sms['phones']) {
                $this->smsConfig['phones'] = $sms['phones'];
            } else {
                throw new LoggerException('配置信息，短信报警手机号不合法');
            }
            if (isset($sms['alertCondition']) and is_numeric($sms['alertCondition']) and $sms['alertCondition'] > 1) {
                $this->smsConfig['alertCondition'] = $sms['alertCondition'];
            }
        } else {
            throw new LoggerException('配置信息，短信报警配置为空');
        }

        // 6.日志文件目录
        if (isset($config['logDir'])) {
            if (!is_dir($config['logDir'])) {
                try{
                    mkdir($config['logDir'], 0777, true);
                }catch (\Exception $e){
                    throw new LoggerException('logger创建目录'.$config['logDir'].'失败, 请确认是否有权限');
                }
            }
            $this->logDir = $config['logDir'];
        }

        // 7.日志保留天数
        if (isset($config['reservedDays']) and $config['reservedDays'] > 0) {
            if($config['reservedDays'] > 30){
                $config['reservedDays'] = 30;
            }
            $this->reservedDays = $config['reservedDays'];
        }

        $this->init = true;
        $this->buildParams();
    }


    /**
     * notes:  获取当前对象是否初始化
     * @return bool
     * @create: 2019/9/29 17:47
     * @update: 2019/9/29 17:47
     * @author: zhangkaixiang
     * @editor:
     */
    public function getInit(){
        return $this->init;
    }


    /**
     * notes:   在系统日志中添加请求参数
     * @create: 2019/7/12 9:18
     * @update: 2019/7/12 9:18
     * @author: zhangkaixiang
     * @editor:
     */
    private function buildParams(){
        $params = $_REQUEST;
        $this->system()->getUseAge()->pushProcessor(function ($record) use ($params) {
            $record['extra']['params'] = json_encode($params, JSON_UNESCAPED_UNICODE);
            return $record;
        });
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
        try{
            @$this->getUseAge()->debug($msg, array('context' => json_encode($context, JSON_UNESCAPED_UNICODE)));
        }catch (\Exception $e){

        }
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
        try{
            @$this->getUseAge()->info($msg, array('context' => json_encode($context, JSON_UNESCAPED_UNICODE)));
        }catch (\Exception $e){

        }
    }

    public function notice($msg, array $context = array())
    {
        try{
            @$this->getUseAge()->notice($msg, array('context' => json_encode($context, JSON_UNESCAPED_UNICODE)));
        }catch (\Exception $e){

        }
    }

    public function warn($msg, array $context = array())
    {
        try{
            @$this->getUseAge()->warn($msg, array('context' => json_encode($context, JSON_UNESCAPED_UNICODE)));
        }catch (\Exception $e){

        }
    }

    public function error($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        try{
            @$this->getUseAge()->error($msg, array('context' => $content));
        }catch (\Exception $e){

        }
        $this->errorHandel('error', $msg . $content);
    }

    public function critical($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        try{
            @$this->getUseAge()->critical($msg, array('context' => $content));
        }catch (\Exception $e){

        }
        $this->errorHandel('critical', $msg . $content);
    }

    public function alert($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        try{
            @$this->getUseAge()->alert($msg, array('context' => $content));
        }catch (\Exception $e){

        }
        $this->errorHandel('alert', $msg . $content);
    }

    public function emergency($msg, array $context = array())
    {
        $content = json_encode($context, JSON_UNESCAPED_UNICODE);
        try{
            @$this->getUseAge()->emergency($msg, array('context' => $content));
        }catch (\Exception $e){

        }
        $this->errorHandel('emergency', $msg . $content);
    }

    /**
     * notes: 发生错误处理方法
     * @param $type
     * @param $content
     * @create: 2019/7/10 17:10
     * @update: 2019/7/10 17:10
     * @author: zhangkaixiang
     * @editor:
     */
    private function errorHandel($type, $content)
    {
        $redis = $this->getRedis();
        if ($redis) {
            if ($this->emailConfig['normalRemind']) {
                $this->getEmailSDK()->emailRemind($redis, $this->emailConfig['sendTo'],
                    $this->emailConfig['normalInterval'], $type, $content,
                    $this->getCommonSDK()->getRequestId());
            }
            $this->getSmsSDK()->sendSms($redis, $this->smsConfig['phones'], $this->smsConfig['alertCondition']);
        }
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

        // 获取当前选中的日志类型
        if($this->module){
            $module = $this->module;
        }else{
            $module = self::MODULE_COMMON;
        }

        // 恢复日志类型变量
        $this->module = null;

        return $this->logs[$module];
    }


    /**
     * 初始化日志方法
     * @author zhangkaixiang
     * @throws \Exception
     */
    private function initLogs()
    {
        if (empty($this->init)) {
            throw new LoggerException('日志系统没有配置参数');
        }

        // 日志格式化
        $formatter = new LogstashFormatter($this->projectName, '127.0.0.1', '', '', 1);

        /**
         * redis日志处理器
         */
        $redis = $this->getElkRedis();
        if (empty($redis)) {
            $redisHandler = false;
        } else {
            $redisHandler = new RedisHandler($redis, $this->elkRedisConfig['key']);
        }

        /**
         * 本地文件处理器
         */
        $logPath       = $this->getCommonSDK()->getLogPath($this->logDir);
        $streamHandler = new StreamHandler($logPath, Monolog::DEBUG);

        /**
         * tmp日志处理，用来日志抓取脚本同步到日志服务器
         */
        $tmpLog = $this->logDir . '/tmp.log';
        if (!file_exists($logPath)) {
            // 如果文件不存在，则说明已经已经超过一个小时，清理tmp日志
            if (file_exists($tmpLog)) {
                @unlink($tmpLog);
            }
        }

        $commonLog = $this->getCommonLog($formatter, $redisHandler, $streamHandler);
        $sysLog    = $this->getSysLog($formatter, $redisHandler, $streamHandler);
        $pushLog   = $this->getPushLog($formatter, $redisHandler, $streamHandler);
        $jobLog    = $this->getJobLog($formatter, $redisHandler, $streamHandler);

        $this->logs = array(
            self::MODULE_COMMON => $commonLog,
            self::MODULE_SYSTEM => $sysLog,
            self::MODULE_PUSH   => $pushLog,
            self::MODULE_Job    => $jobLog,
        );

        // 获取本次请求的唯一id,并添加到所有的日志句柄中
        $requestId = $this->requestId = $this->getCommonSDK()->getRequestId();
        foreach ($this->logs as $log) {
            $log->pushProcessor(function ($record) use ($requestId) {
                $record['extra']['requestId']   = $requestId;
                $record['extra']['ip']          = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
                $record['extra']['projectName'] = $this->projectName;
                return $record;
            });
        }
    }


    /**
     * 获取常规日志对象
     * @param $formatter
     * @param $redisHandler
     * @param StreamHandler $streamHandler
     * @return Monolog
     */
    private function getCommonLog($formatter, $redisHandler,
                                  StreamHandler $streamHandler)
    {
        // 初始化日志对象
        $logger = new Monolog(self::MODULE_COMMON);

        if ($this->useElkService and $redisHandler) {
            $redisHandler->setFormatter($formatter);
            $logger->pushHandler($redisHandler);
        }

        $streamHandler->setFormatter($formatter);
        $logger->pushHandler($streamHandler);

        // 添加打印日志的位置
        $logger->pushProcessor(new IntrospectionProcessor(Monolog::DEBUG, array(), 1));

        return $logger;
    }


    /**
     * 获取推送日志对象
     * @param $formatter
     * @param $redisHandler
     * @param StreamHandler $streamHandler
     * @return Monolog
     */
    private function getPushLog($formatter, $redisHandler,
                                StreamHandler $streamHandler)
    {
        // 初始化日志对象
        $logger = new Monolog(self::MODULE_PUSH);

        if ($this->useElkService and $redisHandler) {
            $redisHandler->setFormatter($formatter);
            $logger->pushHandler($redisHandler);
        }

        $streamHandler->setFormatter($formatter);
        $logger->pushHandler($streamHandler);

        // 添加打印日志的位置
        $logger->pushProcessor(new IntrospectionProcessor(Monolog::DEBUG, array(), 1));

        return $logger;
    }


    /**
     * 获取定时任务日志对象
     * @param $formatter
     * @param $redisHandler
     * @param StreamHandler $streamHandler
     * @return Monolog
     */
    private function getJobLog($formatter, $redisHandler,
                               StreamHandler $streamHandler)
    {
        // 初始化日志对象
        $logger = new Monolog(self::MODULE_Job);

        if ($this->useElkService and $redisHandler) {
            $redisHandler->setFormatter($formatter);
            $logger->pushHandler($redisHandler);
        }

        $streamHandler->setFormatter($formatter);
        $logger->pushHandler($streamHandler);

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
                               StreamHandler $streamHandler)
    {
        // 初始化日志对象
        $logger = new Monolog(self::MODULE_SYSTEM);

        if ($this->useElkService and $redisHandler) {
            $redisHandler->setFormatter($formatter);
            $logger->pushHandler($redisHandler);
        }

        $streamHandler->setFormatter($formatter);
        $logger->pushHandler($streamHandler);

        // 添加当前请求的相关信息
        $logger->pushProcessor(new WebProcessor(null, array(
            'url'         => 'REQUEST_URI',
            'real_ip'     => 'HTTP_X_FORWARDED_FOR',    // 获取真实的ip地址，转发地址以逗号分隔
            'http_method' => 'REQUEST_METHOD',
            'server'      => 'SERVER_NAME',
            'referrer'    => 'HTTP_REFERER',
        )));
        // 添加最大使用内存信息
        $logger->pushProcessor(new MemoryPeakUsageProcessor());

        return $logger;
    }


    /**
     * notes: 获取elkRedis
     * @return bool|null|\Redis
     * @create: 2019/7/10 11:31
     * @update: 2019/7/10 11:31
     * @author: zhangkaixiang
     * @editor:
     */
    private function getElkRedis()
    {
        if (isset($this->elkRedis)) {
            return $this->elkRedis;
        }
        $elkRedis = $this->getCommonSDK()->getRedisHandler($this->elkRedisConfig);
        if (empty($elkRedis)) {
            // 发送系统报警邮件
            $redis = $this->getRedis();
            if ($redis) {
                $this->getEmailSDK()->sendSystemAlertEmail($redis, $this->emailConfig['systemAlert'],
                    $this->emailConfig['normalInterval'], $this->elkRedisConfig['host']);
            }
        }
        $this->elkRedis = $elkRedis;
        return $this->elkRedis;
    }


    /**
     * notes:  获取redis
     * @return bool|null|\Redis
     * @create: 2019/7/10 15:26
     * @update: 2019/7/10 15:26
     * @author: zhangkaixiang
     * @editor:
     */
    private function getRedis()
    {
        if (isset($this->redis)) {
            return $this->redis;
        }
        $redis = $this->getCommonSDK()->getRedisHandler($this->redisConfig);

        $this->redis = $redis;
        return $this->redis;
    }


    /**
     * notes:  获取common对象
     * @return Common
     * @create: 2019/7/10 15:35
     * @update: 2019/7/10 15:35
     * @author: zhangkaixiang
     * @editor:
     */
    private function getCommonSDK()
    {
        if (isset($this->commonSDK)) {
            return $this->commonSDK;
        }
        
        $this->commonSDK = new Common($this->reservedDays);
        return $this->commonSDK;
    }


    /**
     * notes:  获取email对象
     * @return Email
     * @create: 2019/7/10 15:36
     * @update: 2019/7/10 15:36
     * @author: zhangkaixiang
     * @editor:
     */
    private function getEmailSDK()
    {
        if (isset($this->emailSDK)) {
            return $this->emailSDK;
        }

        $this->emailSDK = new Email($this->projectName, $this->emailConfig['username'],
            $this->emailConfig['password'], $this->emailConfig['host']);
        return $this->emailSDK;
    }


    /**
     * notes:
     * @return Sms
     * @create: 2019/7/10 16:15
     * @update: 2019/7/10 16:15
     * @author: zhangkaixiang
     * @editor:
     */
    private function getSmsSDK()
    {
        if (isset($this->smsSDK)) {
            return $this->smsSDK;
        }

        $this->smsSDK = new Sms($this->projectName);
        return $this->smsSDK;
    }
}
