#!/bin/bash
# 根据 REDIS_HOST 环境变量更新 PHP session 配置
# 便于 K8s 等场景下 Redis 使用不同的 Service 名
REDIS_HOST=${REDIS_HOST:-redis}
if grep -q "session.save_path" /opt/docker/etc/php/php.webdevops.ini 2>/dev/null; then
  sed -i "s|tcp://[^:]*:6379|tcp://${REDIS_HOST}:6379|g" /opt/docker/etc/php/php.webdevops.ini
fi
