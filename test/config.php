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
    'projectName' => 'test',

    /**
     * redis缓存配置
     */
    'redisConfig' => array(
        'host' => '192.168.107.107',
        'port' => 6379,
        'select' => 0,
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
     * 日志文件目录,建议使用绝对路径
     */
    'logDir' => '/tmp/test',
    /* 日志保留天数，最长设置30天 */
    'reservedDays' => 2,
);
