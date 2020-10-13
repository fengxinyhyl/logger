<?php
/**
 * Created by PhpStorm.
 * User: zhangkaixiang
 * Date: 2019/7/10
 * Time: 10:53
 */

namespace Logger;


use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * email SDK
 * Class Email
 * @package Logger
 */
class Email
{
    private $projectName = '';

    private $emailUsername = '';
    private $emailPWD = '';
    private $emailHost = '';

    public function __construct($projectName, $emailUsername, $emailPWD, $emailHost)
    {
        $this->projectName   = $projectName;
        $this->emailUsername = $emailUsername;
        $this->emailPWD      = $emailPWD;
        $this->emailHost     = $emailHost;
    }


    /**
     * notes: 给系统管理员发送系统报警邮件
     * @param \Redis $redis
     * @param array $emails
     * @param $interval
     * @param $host
     * @return void
     * @create: 2019/7/10 15:39
     * @update: 2019/7/10 15:39
     * @author: zhangkaixiang
     * @editor:
     */
    public function sendSystemAlertEmail(\Redis $redis, array $emails, $interval, $host)
    {
        $cacheKey = $this->projectName . ':systemAlert';
        $exist    = $redis->get($cacheKey);
        if ($exist === false) {
            $body = date('Y-m-d H:i:s') . " redis: {$host}  连接失败。";
            try {
                $this->sendEmail('系统报警 ', $body, $emails);
            } catch (Exception $e) {
//                Logger::getLogger()->info('邮件发送失败: ' . $e->getMessage());
            }
            $redis->set($cacheKey, '1', $interval);
        }
    }


    /**
     * 邮件提醒
     * @param \Redis $redis
     * @param array $emails
     * @param $interval
     * @param $type
     * @param $content
     * @param $requestId
     * @author zhangkaixiang
     */
    public function emailRemind(\Redis $redis, array $emails, $interval, $type, $content, $requestId)
    {
        $cacheKey = $this->projectName . ':emailRemind:' . $type;
        $exist    = $redis->get($cacheKey);
        if ($exist === false) {
            $body = date('Y-m-d H:i:s') . "系统发生错误。RequestId : " . $requestId . "。\n";
            $body .= "内容 : " . $content . "。";
            try {
                $this->sendEmail($this->projectName . ' ' . $type, $body, $emails);
            } catch (Exception $e) {
                Logger::getLogger()->error('邮件发送失败: ' . $e->getMessage());
            }
            $redis->set($cacheKey, '1', $interval);
        }
    }

    /**
     * 发送邮件 目前适配阿里云邮箱
     * @param $subject
     * @param $body
     * @param array $emails
     * @return bool
     * @throws \PHPMailer\PHPMailer\Exception
     * @author zhangkaixiang
     */
    private function sendEmail($subject, $body, array $emails)
    {
        $mail = new PHPMailer();
        $mail->isSMTP();                              // Set mailer to use SMTP
        $mail->Host       = $this->emailHost;         // Specify main and backup SMTP servers
        $mail->SMTPAuth   = true;                     // Enable SMTP authentication
        $mail->Username   = $this->emailUsername;     // SMTP username
        $mail->Password   = $this->emailPWD;          // SMTP password
        $mail->SMTPSecure = 'ssl';                    // Enable TLS encryption, `ssl` also accepted
        $mail->Port       = 465;                      // TCP port to connect to
        $mail->CharSet    = "utf-8";
        $mail->setFrom($this->emailUsername, $this->projectName);
        $mail->AddReplyTo($this->emailUsername, $this->projectName);

        if ($emails) {
            foreach ($emails as $email) {
                $mail->addAddress($email, $email);                      // Add a recipient
            }
        }

        $mail->isHTML(true);                             // Set email format to HTML
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $re            = $mail->send();

        return $re;
    }
}
