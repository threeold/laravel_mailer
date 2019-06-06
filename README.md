# laravel自带mailer实现多个发件人切换
  
### 1、修改以下文件根据 Mailer.php 增加 swift_other
      /vendor/laravel/framework/src/Illuminate/Mail/Mailer.php

### 2、使用方式
      $transport = Swift_SmtpTransport::newInstance($config_info["host"], $config_info["port"], $config_info["encryption"]);
      $transport->setUsername($config_info["username"]);
      $transport->setPassword($config_info["password"]);
      $from_mail = $email_config["username"]; //发件人EMAIL
      $from_name = $email_config["fromname"]; //发件人名称
      $mailer = new Swift_Mailer($transport);
      $res = Mail::send($temlate, compact('email_content'), function ($message) use ($from_mail, $subject, $user) {
                        $message->from($from_mail, $from_name);
                        $message->to($user);
                        $message->subject($subject);
                    }, $mailer);
