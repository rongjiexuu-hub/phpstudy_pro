<?php
// 数据库配置
define('DB_HOST', 'localhost');
define('DB_USER', 'wei');
define('DB_PASS', '123456');
define('DB_NAME', 'jiajiaotong');
define('DB_PORT', '3307');

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 0); // 生产环境设为0
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 会话设置 - 只有在session未启动时才设置
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
}
?>