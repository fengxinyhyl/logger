<?php
/**
 * Created by PhpStorm.
 * User: zhangkaixiang
 * Date: 2018/12/27
 * Time: 17:18
 */

namespace Logger;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;
use Monolog\Processor\IntrospectionProcessor;

/**
 * 日志模块
 * Class Logger
 * @package app\common\lib
 *
 * usage example:
 *
 *  Logger::getLogger()->notice('this is a notice', array('foo' => 'bar'));
 *
 * composer安装
 * "require": {
 *   "monolog/monolog": "^2.0",
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
     * redis缓存配置
     * 日志传送的redis数据库
     */
    private $redisConfig = array(
        'host'   => '192.168.107.107',
        'port'   => 6379,
        'select' => 0,
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

    private $redis = null;

    // 是否初始化
    private $init = false;

    /**
     * @var Sms
     */
    private $smsSDK = null;

    // 日志单例对象
    private static $instance = null;

    // 日志id
    private $requestId = null;

    /**
     * @var Monolog
     */
    private $log = null;

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
     * notes  返回monolog对象
     * @return Monolog|null
     * @create 2023/5/25 16:31
     * @update 2023/5/25 16:31
     * @author zhangkxiang
     * @editor
     */
    public function getLogItem(){
        return $this->log;
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

        // 2.项目redis缓存配置
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

        // 3.短信报警
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

        // 4.日志文件目录
        if (isset($config['logDir'])) {
            if (!is_dir($config['logDir'])) {
                try {
                    mkdir($config['logDir'], 0777, true);
                } catch (\Exception $e) {
                    throw new LoggerException('logger创建目录' . $config['logDir'] . '失败, 请确认是否有权限');
                }
            }
            $this->logDir = $config['logDir'];
        }

        // 5.日志保留天数
        if (isset($config['reservedDays']) and $config['reservedDays'] > 0) {
            if ($config['reservedDays'] > 30) {
                $config['reservedDays'] = 30;
            }
            $this->reservedDays = $config['reservedDays'];
        }

        $this->init = true;
        $this->initLog();
    }


    /**
     * notes:  获取当前对象是否初始化
     * @return bool
     * @create: 2019/9/29 17:47
     * @update: 2019/9/29 17:47
     * @author: zhangkaixiang
     * @editor:
     */
    public function getInit()
    {
        return $this->init;
    }


    /**
     * notes:   在系统日志中添加请求参数
     * @create: 2019/7/12 9:18
     * @update: 2019/7/12 9:18
     * @author: zhangkaixiang
     * @editor:
     */
    public function buildParams()
    {
        $params = $_REQUEST;
        $this->log->pushProcessor(function ($record) use ($params) {
            $record['extra']['params'] = $params;
            return $record;
        });
    }

    /**
     * 输出日志
     * @param string $msg
     * @param array $context
     * @author zhangkaixiang
     */
    public function debug($msg, array $context = array())
    {
        try {
            @$this->log->debug($msg, $context);
        } catch (\Exception $e) {

        }
    }

    public function info($msg, array $context = array())
    {
        try {
            @$this->log->info($msg, $context);
        } catch (\Exception $e) {

        }
    }

    public function notice($msg, array $context = array())
    {
        try {
            @$this->log->notice($msg, $context);
        } catch (\Exception $e) {

        }
    }

    public function warning($msg, array $context = array())
    {
        try {
            @$this->log->warning($msg, $context);
        } catch (\Exception $e) {

        }
    }

    public function error($msg, array $context = array())
    {
        try {
            @$this->log->error($msg, $context);
        } catch (\Exception $e) {

        }
        $this->errorHandel();
    }

    public function critical($msg, array $context = array())
    {
        try {
            @$this->log->critical($msg, $context);
        } catch (\Exception $e) {

        }
        $this->errorHandel();
    }

    public function alert($msg, array $context = array())
    {
        try {
            @$this->log->alert($msg, $context);
        } catch (\Exception $e) {

        }
        $this->errorHandel();
    }

    public function emergency($msg, array $context = array())
    {
        try {
            @$this->log->emergency($msg, $context);
        } catch (\Exception $e) {

        }
        $this->errorHandel();
    }

    /**
     * notes: 发生错误处理方法
     * @create: 2019/7/10 17:10
     * @update: 2019/7/10 17:10
     * @author: zhangkaixiang
     * @editor:
     */
    private function errorHandel()
    {
        $redis = $this->getRedis();
        if ($redis) {
            $this->getSmsSDK()->sendSms($redis, $this->smsConfig['phones'], $this->smsConfig['alertCondition']);
        }
    }


    /**
     * 初始化日志方法
     * @throws \Exception
     * @author zhangkaixiang
     */
    private function initLog()
    {
        if (empty($this->init)) {
            throw new LoggerException('日志系统没有配置参数');
        }

        // 日志格式化
//        $formatter = new LogstashFormatter($this->projectName, '127.0.0.1', '', '', 1);
        $formatter = new JsonFormatter();

        /**
         * 本地文件处理器
         */
        $logPath     = $this->getCommonSDK()->getLogPath($this->logDir);
        $fileHandler = new StreamHandler($logPath, Monolog::DEBUG);

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

        $stdoutHandler = new StreamHandler('php://stdout', Monolog::DEBUG);
        $stdoutHandler->setFormatter($formatter);

        $this->log = new Monolog('main');
        $this->log->pushHandler($stdoutHandler);
        $this->log->pushHandler($fileHandler);


        // 获取本次请求的唯一id,并添加到所有的日志句柄中
        $requestId = $this->requestId = $this->getCommonSDK()->getRequestId();
        $this->log->pushProcessor(function ($record) use ($requestId) {
            $record['extra']['requestId']   = $requestId;
            $record['extra']['ip']          = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
            $record['extra']['projectName'] = $this->projectName;
            return $record;
        });
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
