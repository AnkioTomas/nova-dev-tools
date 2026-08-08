# TinyPHP Environment (Windows)

这是一个专为 Windows 设计的极简、无冗余、开箱即用的 PHP 8.3 + Nginx 运行环境。
没有任何多余的控制面板、服务注册或臃肿的守护进程机制，所有组件通过原生脚本直接拉起，干净、透明。

## 目录结构

- `www/`：你的网站源码根目录。入口代码（如 `index.php`）请放在这里。
- `core/`：核心环境目录，包含 PHP 和 Nginx 的二进制运行文件。
- `logs/`：统一的日志目录（包括 Nginx 访问/错误日志和 PHP 错误日志）。
- `public.conf`：Nginx 的网站根目录配置文件，启动时自动写入，默认指向 `www/public/`。
- `listen.conf`：Nginx 监听端口配置，由 `start.bat` 根据 `port.txt` 自动生成。
- `port.txt`：持久化的 HTTP 端口（首次启动在 1000–10000 中随机选取；之后复用）。不使用 80。
- `rewrite.conf`：Nginx 的全局伪静态规则文件，默认配置了转发到 `index.php`。
- `start.bat` / `stop.bat` / `status.bat`：环境管理脚本。
- `install_ext.ps1`：PHP 扩展快速挂载脚本。

## 快速使用

1. **启动环境**
   双击 `start.bat`。它会在后台静默拉起 PHP-CGI 和 Nginx 服务，并自动打开浏览器访问 `http://127.0.0.1:<port>/`（端口见 `port.txt`）。

2. **停止环境**
   双击 `stop.bat`。它会平滑退出所有相关进程，清理干净，绝不残留。

3. **查看状态**
   双击 `status.bat`，可直接查看当前 Nginx 和 PHP 进程是否存在。

## 内置拓展

环境已经默认开启了绝大部分现代 PHP 框架所依赖的核心拓展：
`curl`, `fileinfo`, `gd`, `mbstring`, `mysqli`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `sqlite3`, `zip`, `intl`, `exif`, `opcache`。

## 安装新拓展

如果你需要安装额外的扩展（如 Redis、Xdebug 等），不需要去浏览器找 DLL，更不需要手动修改 `php.ini`。直接在 PowerShell 终端中执行：

```powershell
.\install_ext.ps1 "redis,pdo_mysql,igbinary"
```

脚本会自动：
1. 检查是否已经是内置/已下载的扩展，如果是，直接开启。
2. 自动连接 PECL 官网，检索适合当前环境（PHP 8.3 TS x64）的最新编译版本。
3. 后台下载 ZIP 并提取 DLL 存放至 `ext/` 目录。
4. 在 `php.ini` 中注入启用指令。

完成后，双击 `stop.bat` 然后 `start.bat` 重启环境即可生效。

## 哲学

**“好的系统不应引入无意义的抽象层。”**
所有的配置文件、日志输出和进程生命周期对开发者都是绝对透明的。没有任何闭源的管理软件阻挡在真实的服务和开发者之间。如果它坏了，你一秒钟就能找到日志在哪里。
