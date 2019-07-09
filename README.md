# logger
### 1.usage example:
 
* Logger::getLogger()->notice('this is a notice', array('foo' => 'bar'));
* Logger::getLogger()->system()->error('this is a error msg', array('mysql' => 'The ....'));
 
### 2.composer依赖安装
  >"require": {
  >  "monolog/monolog": "1.22.*",
  >  "phpmailer/phpmailer":"~6.0",
  >  "ext-redis": "*"
  >  },
 
### 3.额外的操作
  * 1.buildParams       // 添加请求的参数
  * 2.buildResponse     // 添加返回的数据
  * 3.CacheException    // 捕获系统异常，邮件报警依赖此项
 
### 4.错误级别定义：
 * 调试/DEBUG (100): 详细的调试信息。
 * 信息/INFO (200): 有意义的事件，比如用户登录、SQL日志。
 * 提示/NOTICE (250): 正常但是值得注意的事件。
 * 警告/WARNING (300): 异常事件，但是并不是错误。比如使用了废弃了的API，错误地使用了一个API，以及其他不希望发生但是并非必要的错误。
 * 错误/ERROR (400): 运行时的错误，不需要立即注意到，但是需要被专门记录并监控到。
 * 严重/CRITICAL (500): 边界条件/危笃场景。比如应用组件不可用了，未预料到的异常。
 * 警报/ALERT (550): 必须立即采取行动。比如整个网站都挂了，数据库不可用了等。这种情况应该发送短信警报，并把你叫醒。
 * 紧急/EMERGENCY (600): 紧急请求：系统不可用了。