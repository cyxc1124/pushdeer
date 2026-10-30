# API 安全升级（本地验证，未部署）

维护分支的 API 独立镜像使用 PHP-FPM 8.4.27、Laravel 13.39.0、Redis 扩展 6.3.0、Composer 2.11.0。PHP 和 Composer 基础镜像均固定 digest。

主要依赖已锁定为 Symfony 8.1.10、Guzzle 7.16.1 / PSR-7 2.13.2、CommonMark 2.10.4、Flysystem 3.36.0、Carbon 3.15.1、firebase/php-jwt 7.2.1。Tinker/PsySH 仅用于开发，废弃的 Fruitcake CORS、Swiftmailer、Ignition 及内嵌旧 JWT 的 Apple 登录库已移除。

## 本地构建与回归

在仓库根目录运行：

```sh
docker build -f docker/web/Dockerfile.api --target test -t pushdeer-api:security-test .
docker run --rm --network none pushdeer-api:security-test
docker run --rm --entrypoint composer pushdeer-api:security-test audit --no-interaction
docker build -f docker/web/Dockerfile.api -t pushdeer-api:security-upgrade .
python3 api/tests/integration/runtime.py pushdeer-api:security-upgrade
```

集成测试要求本机 Docker Unix socket，使用独立内部网络和一次性 MariaDB、带密码 Redis、模拟 gorush，不发布宿主机端口；退出时删除本次创建的容器、匿名数据卷和网络。不会连接生产服务。首次运行需下载 `mariadb:11.8`、`redis:7.4-alpine`、`python:3.11-slim`。

可追加 `--legacy-image <旧API镜像digest>`，验证旧 Laravel 建立的表结构无需新增迁移，以及旧 PHP 写入的 Redis session 可被新镜像读取。合成 Redis 密码含 URI 保留字符。

生产架构的本地构建：

```sh
docker build --platform linux/amd64 -f docker/web/Dockerfile.api -t pushdeer-api:security-upgrade-amd64 .
```

## 兼容性与修复边界

- API 路径仍无 `/api` 前缀，JSON、表单、GET 推送和 `{code, content/error}` 响应保留。
- 认证仍使用原生 PHP session。Redis host/port/password、默认数据库和 `PHPREDIS_SESSION:` 前缀沿用；序列化为 `php`，有效期仍为 259200 秒。不得把这些 token 改接 Laravel session 或 Sanctum。
- Apple 登录和账号合并使用同一个受维护的 JWT 验证入口，校验签名、有效期、issuer、audience 和非空 subject。允许的 audience 默认来自 `GO_PUSH_IOS_TOPIC`、`GO_PUSH_IOS_CLIP_TOPIC`；额外的 Apple 服务 ID 须通过 `APPLE_CLIENT_IDS` 逗号列表明确配置。
- WeChat 的请求参数和响应处理保留；外部请求使用现有 Laravel HTTP client 并设置 5 秒超时。
- FPM/CLI 均关闭 `register_argc_argv` 和 session upload progress。Laravel 本身也已修复 Web 请求读取 CLI 环境参数的问题，Symfony 请求头解析使用修复后的实现。
- API 镜像通过 Supervisor 管理 Apache、仅监听 loopback 的 FPM，以及继承容器环境变量的 `artisan schedule:work`；旧消息清理仍每小时运行。
- 没有新增业务表迁移。后续上线仍需备份数据库、保持 APP_KEY 与 Redis 配置一致，并核对清理保留天数及 Apple audience；本次没有执行上线操作。

## 尚未覆盖

当前生产使用 `docker/web/Dockerfile.api` 的默认 `runtime` 目标。两个 Compose 文件改用同一 Dockerfile 的 `self-hosted` 目标，保留原有 gorush 二进制和证书路径；该二进制仅支持 amd64。上游旧 `docker/web/Dockerfile` / `Dockerfile.serverless` 不再作为维护入口。Compose 的数据库/Redis镜像版本和数据卷未变更，升级这些组件需另做备份与迁移。

第三方登录和 APNs/小米真实投递使用本地夹具/模拟服务验证协议，未使用真实账号或发送真实通知。Composer 审计不等于完整操作系统镜像 CVE 扫描；也未执行 PHP 内存漏洞的武器化 RCE 复现。
