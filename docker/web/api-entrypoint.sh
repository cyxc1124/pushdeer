#!/bin/sh
set -eu

# Native PHP sessions carry existing client tokens; Laravel's session driver is separate.
php -r '
    $host = getenv("REDIS_HOST") ?: "redis";
    $port = getenv("REDIS_PORT") ?: "6379";
    if (!preg_match("/^[a-zA-Z0-9_.:\\[\\]-]+$/D", $host) || !filter_var($port, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 65535]])) {
        fwrite(STDERR, "Invalid Redis host or port.\n");
        exit(1);
    }
    $path = "tcp://{$host}:{$port}";
    $password = getenv("REDIS_PASSWORD");
    if ($password !== false && $password !== "") {
        $path .= "?auth=".rawurlencode($password);
    }
    file_put_contents("/usr/local/etc/php/conf.d/zzz-pushdeer-session.ini",
        "session.save_handler = redis\nsession.save_path = ".json_encode($path, JSON_UNESCAPED_SLASHES)."\n");
'
chgrp www-data /usr/local/etc/php/conf.d/zzz-pushdeer-session.ini
chmod 640 /usr/local/etc/php/conf.d/zzz-pushdeer-session.ini
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
exec "$@"
