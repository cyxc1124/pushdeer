#!/bin/bash
# 根据环境变量动态更新 PHP session Redis 配置
REDIS_HOST=${REDIS_HOST:-redis}
REDIS_PORT=${REDIS_PORT:-6379}
REDIS_PASSWORD=${REDIS_PASSWORD:-}

SAVE_PATH="tcp://${REDIS_HOST}:${REDIS_PORT}"
if [ -n "$REDIS_PASSWORD" ]; then
  SAVE_PATH="${SAVE_PATH}?auth=${REDIS_PASSWORD}"
fi

INI_FILE="/opt/docker/etc/php/php.webdevops.ini"
if grep -q "session.save_path" "$INI_FILE" 2>/dev/null; then
  sed -i "s|session.save_path = .*|session.save_path = '${SAVE_PATH}'|g" "$INI_FILE"
fi
