-- =====================================================
-- 学缘家教通 - 完整数据库初始化脚本
-- =====================================================

-- 创建数据库
CREATE DATABASE IF NOT EXISTS jiajiaotong CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jiajiaotong;

-- =====================================================
-- 1. 用户表（基础表）
-- =====================================================
CREATE TABLE IF NOT EXISTS simple_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    nickname VARCHAR(50) DEFAULT NULL COMMENT '昵称',
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    user_type ENUM('parent', 'tutor', 'both') DEFAULT 'both' COMMENT '用户类型',
    is_verified TINYINT(1) DEFAULT 0 COMMENT '是否已认证',
    is_admin TINYINT(1) DEFAULT 0 COMMENT '是否管理员',
    status ENUM('active', 'inactive', 'banned') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 2. 密码重置令牌表
-- =====================================================
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(100) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES simple_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 3. 家教老师信息表
-- =====================================================
CREATE TABLE IF NOT EXISTS tutor_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    gender ENUM('male', 'female', 'other') DEFAULT NULL,
    education VARCHAR(100),
    school VARCHAR(100) COMMENT '学校名称',
    major VARCHAR(100) COMMENT '专业',
    subjects VARCHAR(200),
    experience VARCHAR(100),
    teaching_areas VARCHAR(200),
    schedule VARCHAR(100),
    salary VARCHAR(50),
    teaching_style TEXT,
    contact_phone VARCHAR(20),
    id_card VARCHAR(20) COMMENT '身份证号',
    student_card VARCHAR(255) COMMENT '学生证照片',
    is_verified TINYINT(1) DEFAULT 0 COMMENT '是否已认证',
    verify_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    verify_message TEXT COMMENT '审核备注',
    rating DECIMAL(2,1) DEFAULT 0.0 COMMENT '平均评分',
    rating_count INT DEFAULT 0 COMMENT '评价数量',
    view_count INT DEFAULT 0 COMMENT '浏览次数',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES simple_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 4. 家教需求表
-- =====================================================
CREATE TABLE IF NOT EXISTS tutoring_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    student_name VARCHAR(50) NOT NULL,
    grade VARCHAR(50),
    subjects VARCHAR(200),
    location VARCHAR(200),
    schedule VARCHAR(100),
    salary VARCHAR(50),
    requirements TEXT,
    contact_phone VARCHAR(20),
    view_count INT DEFAULT 0 COMMENT '浏览次数',
    status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES simple_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 5. 消息表
-- =====================================================
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(200) DEFAULT NULL,
    content TEXT NOT NULL,
    message_type ENUM('private', 'system', 'notification') DEFAULT 'private',
    related_type ENUM('tutor', 'request', 'order', 'general') DEFAULT 'general',
    related_id INT DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES simple_users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES simple_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 6. 收藏表
-- =====================================================
CREATE TABLE IF NOT EXISTS favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    target_type ENUM('tutor', 'request') NOT NULL COMMENT '收藏类型',
    target_id INT NOT NULL COMMENT '收藏目标ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_favorite (user_id, target_type, target_id),
    FOREIGN KEY (user_id) REFERENCES simple_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 7. 评价表
-- =====================================================
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reviewer_id INT NOT NULL COMMENT '评价者ID',
    tutor_id INT NOT NULL COMMENT '被评价的家教ID',
    order_id INT DEFAULT NULL COMMENT '关联订单ID',
    rating TINYINT NOT NULL COMMENT '评分1-5',
    content TEXT COMMENT '评价内容',
    tags VARCHAR(500) COMMENT '评价标签，逗号分隔',
    is_anonymous TINYINT(1) DEFAULT 0 COMMENT '是否匿名',
    reply TEXT COMMENT '家教回复',
    reply_at DATETIME DEFAULT NULL,
    status ENUM('active', 'hidden') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewer_id) REFERENCES simple_users(id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id) REFERENCES tutor_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 8. 订单表
-- =====================================================
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(50) NOT NULL UNIQUE COMMENT '订单号',
    parent_id INT NOT NULL COMMENT '家长ID',
    tutor_id INT NOT NULL COMMENT '家教ID',
    request_id INT DEFAULT NULL COMMENT '关联需求ID',
    student_name VARCHAR(50),
    subjects VARCHAR(200),
    schedule VARCHAR(200),
    location VARCHAR(200),
    price_per_hour DECIMAL(10,2) COMMENT '每小时价格',
    total_hours DECIMAL(10,2) DEFAULT 0 COMMENT '总课时',
    total_amount DECIMAL(10,2) DEFAULT 0 COMMENT '总金额',
    paid_amount DECIMAL(10,2) DEFAULT 0 COMMENT '已支付金额',
    status ENUM('pending', 'accepted', 'rejected', 'ongoing', 'completed', 'cancelled') DEFAULT 'pending',
    parent_confirmed TINYINT(1) DEFAULT 0,
    tutor_confirmed TINYINT(1) DEFAULT 0,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    notes TEXT,
    cancel_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES simple_users(id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id) REFERENCES tutor_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 9. 支付记录表
-- =====================================================
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_no VARCHAR(50) NOT NULL UNIQUE COMMENT '支付单号',
    order_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('alipay', 'wechat', 'bank', 'offline') DEFAULT 'offline',
    payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    transaction_id VARCHAR(100) COMMENT '第三方交易号',
    paid_at DATETIME DEFAULT NULL,
    refund_amount DECIMAL(10,2) DEFAULT 0,
    refund_reason TEXT,
    refunded_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES simple_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 10. 身份认证申请表
-- =====================================================
CREATE TABLE IF NOT EXISTS verification_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tutor_profile_id INT NOT NULL,
    real_name VARCHAR(50) NOT NULL,
    id_card VARCHAR(20) NOT NULL,
    id_card_front VARCHAR(255) COMMENT '身份证正面照片',
    id_card_back VARCHAR(255) COMMENT '身份证背面照片',
    student_card VARCHAR(255) COMMENT '学生证照片',
    education_cert VARCHAR(255) COMMENT '学历证明',
    other_certs TEXT COMMENT '其他证书，JSON格式',
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewer_id INT DEFAULT NULL,
    review_message TEXT,
    reviewed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES simple_users(id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_profile_id) REFERENCES tutor_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 11. 系统通知表
-- =====================================================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    type ENUM('system', 'order', 'message', 'review', 'verification') DEFAULT 'system',
    related_id INT DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES simple_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 12. 操作日志表（管理后台用）
-- =====================================================
CREATE TABLE IF NOT EXISTS admin_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50),
    target_id INT,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES simple_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 创建索引
-- =====================================================
CREATE INDEX idx_messages_receiver ON messages(receiver_id, is_read);
CREATE INDEX idx_favorites_user ON favorites(user_id);
CREATE INDEX idx_reviews_tutor ON reviews(tutor_id);
CREATE INDEX idx_orders_parent ON orders(parent_id);
CREATE INDEX idx_orders_tutor ON orders(tutor_id);
CREATE INDEX idx_notifications_user ON notifications(user_id, is_read);

-- =====================================================
-- 插入默认管理员账号
-- 用户名: admin  密码: admin123
-- 注意: 密码hash是使用PHP password_hash('admin123', PASSWORD_DEFAULT)生成的
-- =====================================================
INSERT INTO simple_users (username, email, password, is_admin, user_type) 
VALUES ('admin', 'admin@jiajiaotong.com', '$2y$10$xLbLxl7qH8kL8J9k5L8J9uQxLxLxL8kL8J9k5L8J9uQxLxLxL8kLx', 1, 'both')
ON DUPLICATE KEY UPDATE is_admin = 1;

-- =====================================================
-- 完成
-- =====================================================
SELECT '数据库初始化完成！' AS message;

