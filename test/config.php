<?php
/**
 * Created by PhpStorm.
 * User: zhangkaixiang
 * Date: 2019/7/10
 * Time: 10:34
 */
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
     * 日志系统是否使用redis队列服务
     */
    'useElkRedis' => true,

    /**
     * 发送邮件配置 目前支持阿里云邮箱
     */
    'emailConfig' => array(
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
            18362705640
        ),
        // 发送条件必须为正整数，默认为10
        'alertCondition' => 10,
    ),

    /**
     * 日志文件目录,使用绝对路径
     */
    'logDir' => '/tmp',
);