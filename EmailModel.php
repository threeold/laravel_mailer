<?php
namespace App\Models;

use App\Common\Functions;
use App\Events\Event;
use App\Events\MailEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\MailServiceProvider;
use Swift_SmtpTransport;
use Swift_Mailer;

class EmailModel extends Model
{
    public function __construct($merchant_id, $app_id, $serial_number)
    {
        $this->Merchant = Functions::getMerchantAppConfig($merchant_id, $app_id);
        $res = $this->ChenkConfig($this->Merchant);
        if ($res["status"] != 0) {
            return $res;
        }
        $this->serial_number = $serial_number;//流水号
        $this->merchant_id = $merchant_id;
        $this->app_id = $app_id;
        $this->Email_config = $this->Merchant["emailConfig"];
        $this->addressorConf = $this->GetSuAddressorConf($this->Email_config["addressorConf"], $merchant_id, $app_id); //发件人
        if (empty($this->addressorConf)) {
            return Functions::getMessageBody('没有可用的发件人', [], 101018);
        }
        $this->AlarmReception = $this->Email_config["AlarmReception"]; //邮件挂掉接收人的邮箱
    }

    /**配置检测**/
    public function ChenkConfig($Merchant)
    {
        if (empty($Merchant)) {
            return Functions::getMessageBody('Merchant未配置', [], 101018);
        }
        if (empty($Merchant["emailConfig"])) {
            return Functions::getMessageBody('emailConfig未配置', [], 101018);
        }
        $Email_config = $Merchant["emailConfig"];
        if (empty($Email_config["addressorConf"])) {
            return Functions::getMessageBody('addressorConf未配置', [], 101018);
        }
        return Functions::getMessageBody('正常');
    }
    /**读取正常发件人**/
    public function GetSuAddressorConf($addressorConf,$merchant_id, $app_id)
    {
        $err_email = $this->GetErrEmail($merchant_id, $app_id);
        if (!empty($err_email)) {
            foreach ($addressorConf as $key => $val) {
                if (in_array($val["username"], $err_email)) {
                    unset($addressorConf[$key]);
                }
            }
        }
        if(!empty($addressorConf)){
            sort($addressorConf);
        }
        return $addressorConf;
    }

    /**
     * $subject 邮件标题
     * $email_content 邮件内容
     * $recipients 收件人
     * $send_type 1 2
     * $email_id 指定邮箱地址发
     * $temlate 邮件模块
    **/
    public function EmailSend($subject, $email_content, $recipients, $send_type = 1, $addressorConf = [], $is_alaem = 0, $email_id = -1, $temlate = "emails.explosion_notice")
    {
        if (empty($addressorConf)) {
            $addressorConf = $this->addressorConf;
        }
        if(empty($addressorConf)){
            return Functions::getMessageBody('没有可用的发件人', [], 101018);
        }
        $email_id = $this->ChangEMail($email_id,$addressorConf);
        $mailer = $this->GetEMailHost($email_id,$addressorConf);
        if (!$mailer) {
            Functions::SysLog('EmailErr', '==Err=email_config==>', ["serial_number"=>$this->serial_number,"email_config" => $email_id, "msg" => "email_id没有对应的邮箱配置信息","is_alaem" => $is_alaem]);
            return Functions::getMessageBody('email_id:'.$email_id.'没有对应的邮箱配置信息', [], 101018);
        }

        if (empty($addressorConf[$email_id])) {
            return Functions::getMessageBody('email_id:' . $email_id . '没有对应的邮箱配置信息2', [], 101018);
        }
        $email_config = $addressorConf[$email_id];
        $from_mail = $email_config["username"]; //发件人EMAIL
        $from_name = $email_config["fromname"]; //发件人名称
        try {
            if ($send_type == 1) {
                //发多个一次发用抄送
                $res = Mail::send($temlate, compact('email_content'), function ($message) use ($from_mail, $from_name,$subject, $recipients) {
                    $message->from($from_mail, $from_name);
                    foreach ($recipients as $uk => $user) {
                        if ($uk == 0) {
                            $message->to($user);
                        } else {
                            $message->cc($user);
                        }
                    }
                    $message->subject($subject);
                }, $mailer);
                //去更新日志
            } elseif ($send_type == 2) {
                foreach ($recipients as $uk => $user) { //给多个分开发
                    $res = Mail::send($temlate, compact('email_content'), function ($message) use ($from_mail, $subject, $user) {
                        $message->from($from_mail, $from_name);
                        $message->to($user);
                        $message->subject($subject);
                    }, $mailer);
                    //去更新日志
                }
            }
            if (!$res) {
                Functions::SysLog('EmailErr', '==Err=senderr==>', ["serial_number"=>$this->serial_number,"email_config" => $email_config, "recipients" => $recipients, "subject" => $subject, "email_content" => $email_content, "res" => $res,"is_alaem" => $is_alaem]);
                return Functions::getMessageBody('发送失败', [], 101018);
            }else{
                return Functions::getMessageBody('发送成功');
            }
        } catch (\Exception $e) {
            Functions::SysLog('EmailErr', '==Err=needchang==>', ["serial_number"=>$this->serial_number,"email_config" => $email_config,$e->getFile(), $e->getLine(), $e->getMessage(),"is_alaem" => $is_alaem]);
            if ($is_alaem == 0) {
                $this->AlaemEmailSend($from_mail);
            } elseif ($is_alaem == 1) {
                $this->setErrAlaemEmail($from_mail);
            }
            return Functions::getMessageBody('发送失败', [], 101018);
        }
    }
    /**选择**/
    public function ChangEMail($email_id,$addressorConf=[])
    {
        if ($email_id == -1) {
            if (empty($addressorConf)) {
                $addressorConf = $this->addressorConf;
            }
            $mail_count = count($addressorConf);
            $mail_id = $mail_count > 1 ? rand(0, ($mail_count - 1)) : 0;
            return $mail_id;
        }
        return $email_id;
    }
    /**切换**/
    public function GetEMailHost($email_id,$addressorConf=[])
    {
        if (empty($addressorConf)) {
            $addressorConf = $this->addressorConf;
        }
        if (empty($addressorConf[$email_id])) {
            return Functions::getMessageBody('addressorConf' . $email_id . '未配置', [], 101018);
        }
        $config_info = $addressorConf[$email_id];
        $transport = Swift_SmtpTransport::newInstance($config_info["host"], $config_info["port"], $config_info["encryption"]);
        $transport->setUsername($config_info["username"]);
        $transport->setPassword($config_info["password"]);
        $mailer = new Swift_Mailer($transport);
        return $mailer;
    }
    /**报警邮件发送**/
    public function AlaemEmailSend($from_mail){

        if (empty($this->AlarmReception)) {
            return Functions::getMessageBody('报警邮件接收人未配置', [], 101018);
        }
        $alaem_merchant_id = env("EMAIL_ALAEM_MERCHANT_ID");
        $alaem_id = env("EMAIL_ALAEM_APP_ID");
        $alaem_Merchant = Functions::getMerchantAppConfig($alaem_merchant_id, $alaem_id);
        $res = $this->ChenkConfig($alaem_Merchant);
        if ($res["status"] != 0) {
            return $res;
        }
        $this->SetErrEmail($from_mail,$this->merchant_id,$this->app_id);
        $email_count = count($this->addressorConf);
        $email_count = $email_count > 1 ? $email_count - 1 : 0;
        $alaem_Email_config = $alaem_Merchant["emailConfig"];
        $alaem_addressorConf = $alaem_Email_config["addressorConf"]; //发件人
        $subject = "通知：" . $from_mail . "发不出邮件";
        $email_content = "通知：" . $from_mail . "发不出邮件,剩余可用邮箱" . $email_count . "个";

        $alaem_addressorConf = $this->GetSuAddressorConf($alaem_addressorConf, $alaem_merchant_id, $alaem_id);
        if ($alaem_addressorConf) {
            $res = $this->EmailSend($subject, $email_content, $this->AlarmReception, 1, $alaem_addressorConf, 1);
            Functions::SysLog('EmailErr', '==su=AlaemEmail==>', ["res" => $res, "from_mail" => $from_mail,"alaem_merchant_id" => $alaem_merchant_id, "alaem_app_id" => $alaem_id]);
        } else {
            Functions::SysLog('EmailErr', '==Err=AlaemEmail==>', ["msg" => "报警没有可用的发件人", "alaem_merchant_id" => $alaem_merchant_id, "alaem_app_id" => $alaem_id]);
        }
    }

    /**获取挂掉的邮箱**/
    public function GetErrEmail($merchant_id, $app_id){
        $redis_key = env('CACHE_PREFIX') . ":EmailErr:" . $merchant_id. "_" . $app_id;
        $err_emails = \RedisDB::get($redis_key);
        $err_emails = !empty($err_emails) ?explode(",",$err_emails) : [];
        return $err_emails;
    }
    /**设置挂掉的邮箱**/
    public function SetErrEmail($from_mail,$merchant_id, $app_id)
    {
        $redis_key = env('CACHE_PREFIX') . ":EmailErr:" . $merchant_id . "_" . $app_id;
        $err_emails = $this->GetErrEmail($merchant_id, $app_id);
        $err_emails[] = $from_mail;
        $err_emails = array_unique($err_emails);
        $cache_time = strtotime(date("Y-m-d") . " 23:59:59") - time();
        \RedisDB::setex($redis_key, $cache_time, implode(",",$err_emails));
    }
    /***设置报警的挂了**/
    public function setErrAlaemEmail($from_mail){
        $alaem_merchant_id = env("EMAIL_ALAEM_MERCHANT_ID");
        $alaem_id = env("EMAIL_ALAEM_APP_ID");
        $this->SetErrEmail($from_mail,$alaem_merchant_id, $alaem_id);
    }
}
