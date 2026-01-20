# 卡卡聚合支付系统 - KKPay

> 程序基于Webman v2.1开发，推荐使用`PHP8.5`及以上版本，最低支持`PHP8.5`。
>
> 程序采用MySQL数据库进行数据存储，推荐使用`MySQL8.4`及以上版本，最低支持`MySQL8.0`。
>
> 程序采用Redis数据库进行缓存、队列等任务，越新越好，无最低要求，推荐使用`Redis8.4`及以上版本。
>
> 程序需搭配Nginx服务器以实现静态资源缓存以及HTTPS协议，越新越好，无最低要求，推荐使用`Nginx1.29.4`及以上版本。

> 本程序需要`Curl`+`PDO_Mysql`+`Redis`+`MbString`+`BC Math`+`Event`+`Sodium`组件/拓展，请提前检查运行的PHP版本是否已安装并启用。
>
> 请提前确保PHP运行环境禁用函数`disable_functions`中不包含`putenv`,`pcntl_signal_dispatch`,`pcntl_signal`,`pcntl_alarm`,`pcntl_fork`,`pcntl_wait`,`proc_open`,`shell_exec`,`exec`。

```ini
// 【一键】找到php.ini文件，将disable_functions中的内容修改为以下内容
disable_functions = passthru,system,chroot,chgrp,chown,popen,pcntl_exec,ini_alter,ini_restore,dl,openlog,syslog,readlink,symlink,popepassthru,pcntl_waitpid,pcntl_wifexited,pcntl_wifstopped,pcntl_wifsignaled,pcntl_wifcontinued,pcntl_wexitstatus,pcntl_wtermsig,pcntl_wstopsig,pcntl_get_last_error,pcntl_strerror,pcntl_sigprocmask,pcntl_sigwaitinfo,pcntl_sigtimedwait,pcntl_exec,pcntl_getpriority,pcntl_setpriority,imap_open,apache_setenv
```

## 安装与配置

1. 将程序源码压缩包解压至网站根目录
2. 手动修改`/config/database.php`文件中的数据库连接信息并保存
3. 手动修改`/config/redis.php`文件中的Redis连接信息并保存（一般情况下此项无需改动）
4. 打开终端，使用`cd`命令进入网站根目录
5. 终端执行命令 `composer install --no-dev` 安装程序运行所必需的依赖项（需提前自行准备好Composer工具）
6. 终端执行命令 `php ./scripts/initialization.php --reinstall` 对系统进行一键初始化（自动导入数据库、创建管理员）
   > 可选参数 `--admin=your_name` 自定义管理员账号（不传默认为 admin）
7. 终端执行命令 `php ./start.php start -d` 启动程序

### Nginx配置反向代理（含跨域解决方案）

```
location / {
    try_files $uri $uri/ @backend;
}

location @backend {
    proxy_set_header Host $http_host;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_http_version 1.1;
    proxy_set_header Connection "";

    # --- CORS 配置开始 ---
    
    # 处理 OPTIONS 预检请求 (浏览器询问服务器是否允许跨域)
    if ($request_method = 'OPTIONS') {
        add_header 'Access-Control-Allow-Origin' '*' always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS' always;
        
        # 必须允许前端发送的 Header，比如 Content-Type, Authorization
        add_header 'Access-Control-Allow-Headers' 'DNT,Keep-Alive,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Authorization' always;
        
        add_header 'Access-Control-Max-Age' 1728000 always;
        add_header 'Content-Type' 'text/plain; charset=utf-8';
        add_header 'Content-Length' 0;
        return 204;
    }

    # 处理正常的 POST/GET 响应 (proxy_pass 返回后的处理)
    add_header 'Access-Control-Allow-Origin' '*' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS' always;
    add_header 'Access-Control-Allow-Headers' 'DNT,Keep-Alive,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Authorization' always;

    # --- CORS 配置结束 ---

    proxy_pass http://127.0.0.1:6689;
}

# 拒绝访问所有以 .php 结尾的文件
location ~ \.php$ {
    return 404;
}

# 拒绝访问所有以 . 开头的文件或目录
location ~ /\. {
    return 404;
}
```

## 免责声明（作者声明）

1. 本软件（包括但不限于源代码、可执行程序、文档及相关资源）仅供学习、研究或技术交流之用，不得用于任何商业目的或非法用途。
2. 使用者在使用本软件前，应充分了解并遵守所在国家或地区的相关法律法规。**严禁将本软件用于任何违反法律法规、侵犯他人合法权益或危害网络安全的行为**。
3. 本软件按“现状”和“可用”状态提供，作者**不提供任何形式的明示或暗示的担保**，包括但不限于对适销性、特定用途适用性、无病毒、无错误或不侵权的担保。
4. 因使用本软件所引发的任何直接、间接、附带、特殊或后果性损害（包括但不限于数据丢失、业务中断、利润损失等），**作者概不负责**，一切风险由**使用者自行承担**。
5. 若您将本软件用于生产环境、商业项目或其他非学习用途，请务必自行评估其安全性、稳定性与合法性，并承担由此产生的全部法律责任。
6. 本声明的解释权归软件作者所有。如您使用本软件，即视为您已阅读、理解并同意本免责声明的全部内容。
