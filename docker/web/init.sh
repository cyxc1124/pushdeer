#!/bin/bash

# 确保 storage 和 cache 目录权限正确（构建阶段已创建，此处仅做保障）
mkdir -p /app/api/storage /app/api/bootstrap/cache
chmod -R 0777 /app/api/storage /app/api/bootstrap/cache
