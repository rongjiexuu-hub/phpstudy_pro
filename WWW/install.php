<?php
/**
 * 数据库安装脚本
 * 访问此文件将自动创建所有必要的数据库表
 */

require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html lang='zh-CN'>
<head>
    <meta charset='UTF-8'>
    <title>数据库安装 - 学缘家教通</title>
    <style>
        body { font-family: 'Microsoft YaHei', sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #667eea; }
        .success { color: #28a745; padding: 10px; background: #d4edda; border-radius: 5px; margin: 10px 0; }
        .error { color: #dc3545; padding: 10px; background: #f8d7da; border-radius: 5px; margin: 10px 0; }
        .info { color: #17a2b8; padding: 10px; background: #d1ecf1; border-radius: 5px; margin: 10px 0; }
        .btn { display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        .btn:hover { background: #5a6fd8; }
    </style>
</head>
<body>
<div class='container'>
<h1>🛠️ 学缘家教通 - 数据库安装</h1>";

try {
    // 连接数据库
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
    
    if ($conn->connect_error) {
        throw new Exception("数据库连接失败: " . $conn->connect_error);
    }
    
    $conn->set_charset('utf8mb4');
    
    // 创建数据库
    $sql = "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    if ($conn->query($sql)) {
        echo "<div class='success'>✅ 数据库 " . DB_NAME . " 创建成功或已存在</div>";
    }
    
    $conn->select_db(DB_NAME);
    
    // 定义所有表的SQL
    $tables = [
        // 用户表
        "simple_users" => "CREATE TABLE IF NOT EXISTS `simple_users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `email` VARCHAR(100) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `phone` VARCHAR(20) DEFAULT NULL,
            `avatar` VARCHAR(255) DEFAULT NULL,
            `user_type` ENUM('student', 'tutor', 'parent') DEFAULT 'student',
            `is_verified` TINYINT(1) DEFAULT 0,
            `reset_token` VARCHAR(100) DEFAULT NULL,
            `reset_token_expires` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 家教老师信息表
        "tutor_profiles" => "CREATE TABLE IF NOT EXISTS `tutor_profiles` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `name` VARCHAR(50) NOT NULL,
            `education` VARCHAR(100),
            `subjects` VARCHAR(200),
            `experience` VARCHAR(100),
            `teaching_areas` VARCHAR(200),
            `schedule` VARCHAR(100),
            `salary` VARCHAR(50),
            `teaching_style` TEXT,
            `contact_phone` VARCHAR(20),
            `id_card` VARCHAR(50) DEFAULT NULL COMMENT '身份证号',
            `id_card_verified` TINYINT(1) DEFAULT 0 COMMENT '身份证是否验证',
            `education_cert` VARCHAR(255) DEFAULT NULL COMMENT '学历证书图片',
            `education_verified` TINYINT(1) DEFAULT 0 COMMENT '学历是否验证',
            `rating` DECIMAL(3,2) DEFAULT 0 COMMENT '评分',
            `rating_count` INT DEFAULT 0 COMMENT '评价数量',
            `status` ENUM('active', 'inactive', 'pending') DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `simple_users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 家教需求表
        "tutoring_requests" => "CREATE TABLE IF NOT EXISTS `tutoring_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `student_name` VARCHAR(50) NOT NULL,
            `grade` VARCHAR(50),
            `subjects` VARCHAR(200),
            `location` VARCHAR(200),
            `schedule` VARCHAR(100),
            `salary` VARCHAR(50),
            `requirements` TEXT,
            `contact_phone` VARCHAR(20),
            `status` ENUM('active', 'inactive', 'matched') DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `simple_users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 消息表
        "messages" => "CREATE TABLE IF NOT EXISTS `messages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `sender_id` INT NOT NULL,
            `receiver_id` INT NOT NULL,
            `content` TEXT NOT NULL,
            `is_read` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`sender_id`) REFERENCES `simple_users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`receiver_id`) REFERENCES `simple_users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 收藏表
        "favorites" => "CREATE TABLE IF NOT EXISTS `favorites` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `item_type` ENUM('tutor', 'request') NOT NULL COMMENT '收藏类型',
            `item_id` INT NOT NULL COMMENT '收藏的项目ID',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_favorite` (`user_id`, `item_type`, `item_id`),
            FOREIGN KEY (`user_id`) REFERENCES `simple_users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 评价表
        "reviews" => "CREATE TABLE IF NOT EXISTS `reviews` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `reviewer_id` INT NOT NULL COMMENT '评价者ID',
            `tutor_profile_id` INT NOT NULL COMMENT '被评价的家教ID',
            `order_id` INT DEFAULT NULL COMMENT '关联的订单ID',
            `rating` TINYINT NOT NULL COMMENT '评分1-5',
            `content` TEXT COMMENT '评价内容',
            `reply` TEXT COMMENT '家教回复',
            `reply_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`reviewer_id`) REFERENCES `simple_users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`tutor_profile_id`) REFERENCES `tutor_profiles`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 订单表
        "orders" => "CREATE TABLE IF NOT EXISTS `orders` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_no` VARCHAR(50) NOT NULL UNIQUE COMMENT '订单编号',
            `parent_id` INT NOT NULL COMMENT '家长用户ID',
            `tutor_id` INT NOT NULL COMMENT '家教用户ID',
            `tutor_profile_id` INT NOT NULL COMMENT '家教信息ID',
            `request_id` INT DEFAULT NULL COMMENT '关联的需求ID',
            `subjects` VARCHAR(200) COMMENT '辅导科目',
            `schedule` VARCHAR(200) COMMENT '上课时间',
            `location` VARCHAR(200) COMMENT '上课地点',
            `price` DECIMAL(10,2) COMMENT '每小时价格',
            `total_hours` DECIMAL(5,1) DEFAULT 0 COMMENT '总课时',
            `total_amount` DECIMAL(10,2) DEFAULT 0 COMMENT '总金额',
            `status` ENUM('pending', 'confirmed', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
            `payment_status` ENUM('unpaid', 'partial', 'paid', 'refunded') DEFAULT 'unpaid',
            `start_date` DATE DEFAULT NULL,
            `end_date` DATE DEFAULT NULL,
            `parent_remark` TEXT COMMENT '家长备注',
            `tutor_remark` TEXT COMMENT '家教备注',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`parent_id`) REFERENCES `simple_users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`tutor_id`) REFERENCES `simple_users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`tutor_profile_id`) REFERENCES `tutor_profiles`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 课程记录表
        "lessons" => "CREATE TABLE IF NOT EXISTS `lessons` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NOT NULL,
            `lesson_date` DATE NOT NULL,
            `start_time` TIME NOT NULL,
            `end_time` TIME NOT NULL,
            `hours` DECIMAL(3,1) NOT NULL,
            `content` TEXT COMMENT '授课内容',
            `homework` TEXT COMMENT '作业',
            `status` ENUM('scheduled', 'completed', 'cancelled') DEFAULT 'scheduled',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 身份认证申请表
        "verifications" => "CREATE TABLE IF NOT EXISTS `verifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `type` ENUM('id_card', 'education', 'teacher_cert') NOT NULL COMMENT '认证类型',
            `real_name` VARCHAR(50) COMMENT '真实姓名',
            `id_number` VARCHAR(50) COMMENT '证件号码',
            `image_url` VARCHAR(255) COMMENT '证件图片',
            `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            `reject_reason` VARCHAR(255) DEFAULT NULL,
            `reviewed_by` INT DEFAULT NULL,
            `reviewed_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `simple_users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 管理员表
        "admins" => "CREATE TABLE IF NOT EXISTS `admins` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `name` VARCHAR(50),
            `role` ENUM('super_admin', 'admin', 'operator') DEFAULT 'operator',
            `last_login` DATETIME DEFAULT NULL,
            `status` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 系统设置表
        "settings" => "CREATE TABLE IF NOT EXISTS `settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(50) NOT NULL UNIQUE,
            `value` TEXT,
            `description` VARCHAR(255),
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        // 操作日志表
        "admin_logs" => "CREATE TABLE IF NOT EXISTS `admin_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `admin_id` INT NOT NULL,
            `action` VARCHAR(100) NOT NULL,
            `target_type` VARCHAR(50),
            `target_id` INT,
            `details` TEXT,
            `ip` VARCHAR(50),
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    
    // 执行创建表
    foreach ($tables as $tableName => $sql) {
        if ($conn->query($sql)) {
            echo "<div class='success'>✅ 表 {$tableName} 创建成功或已存在</div>";
        } else {
            echo "<div class='error'>❌ 表 {$tableName} 创建失败: " . $conn->error . "</div>";
        }
    }
    
    // 创建默认管理员账户
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    $admin_sql = "INSERT IGNORE INTO `admins` (`username`, `password`, `name`, `role`) 
                  VALUES ('admin', '{$admin_password}', '超级管理员', 'super_admin')";
    if ($conn->query($admin_sql)) {
        echo "<div class='info'>📌 默认管理员账户: admin / admin123</div>";
    }
    
    // 添加默认系统设置
    $settings = [
        ['site_name', '学缘家教通', '网站名称'],
        ['site_description', '连接优秀家教与需要帮助的家庭', '网站描述'],
        ['contact_email', 'admin@jiajiaotong.com', '联系邮箱'],
        ['contact_phone', '400-123-4567', '联系电话'],
        ['enable_registration', '1', '是否开放注册'],
        ['enable_verification', '1', '是否需要身份认证']
    ];
    
    $setting_stmt = $conn->prepare("INSERT IGNORE INTO `settings` (`key`, `value`, `description`) VALUES (?, ?, ?)");
    foreach ($settings as $setting) {
        $setting_stmt->bind_param("sss", $setting[0], $setting[1], $setting[2]);
        $setting_stmt->execute();
    }
    echo "<div class='success'>✅ 系统设置初始化完成</div>";
    
    $conn->close();
    
    echo "<div class='success' style='font-size: 18px; margin-top: 20px;'>
        🎉 <strong>数据库安装完成！</strong>
    </div>";
    
    echo "<a href='index.html' class='btn'>进入网站首页</a> ";
    echo "<a href='admin/login.html' class='btn' style='background: #28a745;'>进入管理后台</a>";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ 安装失败: " . $e->getMessage() . "</div>";
}

echo "</div></body></html>";
?>

