# **logger**
---
### 安装方式
 * 方式一：composer require fengxinyhyl/logger
 * 方式二：在composer.json文件的require里添加 "fengxinyhyl/logger":"~0.1"，运行composer update
 ---

### 项目依赖
    "require": {
        "monolog/monolog": "1.22.*",
        "phpmailer/phpmailer":"~6.0",
        "ext-redis": "*",
        "php": ">=5.4.0",
        },
---

### 配置文件
    return array(
        /**
         * 当前项目的唯一标识
         * 用来区分日志系统中不同项目的日志
         * 用来创建es索引，不能出现大写字母
         */
        'projectName' => 'user_center',
    
        /**
         * redis缓存配置
         * 日志传送的redis数据库
         */
        'redisConfig' => array(
            'host' => '192.168.107.107',
            'port' => 6379,
            'select' => 0,
        ),
    
        /**
         * 日志系统是否开启redis队列服务发送日志
         */
        'useElkService' => true,
    
        /**
         * 发送邮件配置 目前支持阿里云邮箱
         */
        'emailConfig' => array(
            // smtp服务器
            'host'           => 'smtp.exmail.qq.com',
            // 发送邮件的地址(如果被拒收，建议把该地址加入白名单)            
            'username'       => 'zhangkaixiang@house365.com',
            // 发送邮件的密码    
            'password'       => 'xxxxxxxxxxxxxxx',               
            // 接收邮件的地址,需要在白名单中加入fengxinyhyl@aliyun.com,防止无法收到邮件
            'sendTo'   => array(
                'fengxinyhyl@qq.com',
            ),
            // 缓存系统异常报警邮箱
            'systemAlert'   => array(
                'fengxinyhyl@qq.com',
            ),
            // 是否开启常规提醒，开启后出现error,critical等类型错误会发送提醒邮件
            'normalRemind'  => true,
            // 常规提醒的时间间隔(秒)
            'normalInterval' => 86400,
        ),
    
        /**
         * 短信报警配置
         * 每小时报警次数是alertCondition的整数倍会发送报警短信
         * 每小时至多发送5条
         */
        'smsConfig' => array(
            // 报警短信接收号码必须配置
            'phones' => array(
            ),
            // 发送条件必须为正整数，默认为10，每小时发生错误的数量是报警条件的整数倍时，发送一次报警短信
            'alertCondition' => 10,
        ),
    
        /**
         * 日志文件目录,使用绝对路径,默认tmp目录
         */
        'logDir' => '/tmp',
    );
---

### 使用方法
####  1.日志分类
 * common 常规日志，默认选项
 * system 系统日志
 * push   访问外部日志
 * job    定时任务日志
####  2.使用方法
    $config = require('config.php');
    Logger::getLogger()->initLogger($config); // 必须先根据配置初始化日志对象
    Logger::getLogger()->info('bbbb');        // getLogger()返回日志单例对象，默认调用commen
    Logger::getLogger()->system()->error('bbbb');
 
### 扩展
#### 1.buildResponse，添加返回的数据
    // 应用结束（TP项目）
    'app_end'      => [
        'app\common\behavior\BuildResponse',
    ],

    /**
     * BuildResponse.php
     * 将返回数据添加到日志中
     * @param Response $response
     */
    public function run(Response $response) {
        $response = $response->getData();
        // 本次访问返回的数据
        $response = is_array($response) ? json_encode($response, JSON_UNESCAPED_UNICODE) : $response;
        // 本次访问运行的时间
        $runTime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        if($runTime > 1)){ // 时间可根据项目自行配置
            Logger::getLogger()->common()->warn('本次运行时间过长', array('runTime' => $runTime.' s'));
        }
        Logger::getLogger()->system()->getUseAge()->pushProcessor(function ($record) use ($response, $runTime) {
            $record['extra']['runTime'] = $runTime.' s';
            $record['extra']['response'] =  $response;
            return $record;
        });
        Logger::getLogger()->system()->info('success');
    }
#### 2.CacheException，捕获系统异常，报警依赖此项
[ThinkPHP 异常处理](#https://www.kancloud.cn/manual/thinkphp5/126075)

[Laravel 错误处理](https://laravelacademy.org/post/9548.html)

    /**
     - 系统级别异常处理文件
     - config配置文件中 'exception_handle' => '\\app\\common\\exception\\Http',定义
     - Class Http
     - @package app\common\exception
     */
    class Http extends Handle
    {
        public function render(Exception $e)
        {
            //TODO::开发者对异常的操作
            //可以在此交由系统处理
            Logger::getLogger()->system()->critical('file:'.$e->getFile().' line:'. $e->getLine().' msg:'.$e->getMessage());
            return parent::render($e);
        }
    }
 ---

### 错误级别定义
 * 调试/DEBUG (100): 详细的调试信息。
 * 信息/INFO (200): 有意义的事件，比如用户登录、SQL日志。
 * 提示/NOTICE (250): 正常但是值得注意的事件。
 * 警告/WARNING (300): 异常事件，但是并不是错误。比如使用了废弃了的API，错误地使用了一个API，以及其他不希望发生但是并非必要的错误。
 * 错误/ERROR (400): 运行时的错误，不需要立即注意到，但是需要被专门记录并监控到。
 * 严重/CRITICAL (500): 边界条件/危笃场景。比如应用组件不可用了，未预料到的异常。
 * 警报/ALERT (550): 必须立即采取行动。比如整个网站都挂了，数据库不可用了等。这种情况应该发送短信警报，并把你叫醒。
 * 紧急/EMERGENCY (600): 紧急请求：系统不可用了。