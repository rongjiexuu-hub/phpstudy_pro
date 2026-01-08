<?php
/**
 * 初始化管理员账户
 * 访问此文件来创建或重置管理员账户
 */
header('Content-Type: text/html; charset=utf-8');
require_once 'config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    die("数据库连接失败: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

$message = '';
$success = false;

// 检查simple_users表是否存在
$table_check = $conn->query("SHOW TABLES LIKE 'simple_users'");
if ($table_check->num_rows === 0) {
    $message = "用户表不存在，请先运行 database_init.sql 初始化数据库";
} else {
    // 检查is_admin列是否存在
    $column_check = $conn->query("SHOW COLUMNS FROM simple_users LIKE 'is_admin'");
    if ($column_check->num_rows === 0) {
        $conn->query("ALTER TABLE simple_users ADD COLUMN is_admin TINYINT(1) DEFAULT 0 COMMENT '是否管理员'");
    }
    
    // 生成密码hash
    $admin_password = 'admin123';
    $password_hash = password_hash($admin_password, PASSWORD_DEFAULT);
    
    // 检查admin用户是否存在
    $check = $conn->prepare("SELECT id FROM simple_users WHERE username = 'admin'");
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        // 更新现有admin用户
        $user_id = $result->fetch_assoc()['id'];
        $stmt = $conn->prepare("UPDATE simple_users SET password = ?, is_admin = 1 WHERE id = ?");
        $stmt->bind_param("si", $password_hash, $user_id);
        if ($stmt->execute()) {
            $message = "管理员账户已更新！<br>用户名: admin<br>密码: admin123";
            $success = true;
        } else {
            $message = "更新失败: " . $stmt->error;
        }
    } else {
        // 创建新admin用户
        $stmt = $conn->prepare("INSERT INTO simple_users (username, email, password, is_admin, user_type) VALUES ('admin', 'admin@jiajiaotong.com', ?, 1, 'both')");
        $stmt->bind_param("s", $password_hash);
        if ($stmt->execute()) {
            $message = "管理员账户创建成功！<br>用户名: admin<br>密码: admin123";
            $success = true;
        } else {
            $message = "创建失败: " . $stmt->error;
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>初始化管理员</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .box { padding: 20px; border-radius: 10px; margin: 20px 0; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        a { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; }
        a:hover { background: #5a6fd6; }
    </style>
</head>
<body>
    <h1>🔐 初始化管理员账户</h1>
    <div class="box <?php echo $success ? 'success' : 'error'; ?>">
        <?php echo $message; ?>
    </div>
    <?php if ($success): ?>
    <p>现在可以使用以下信息登录：</p>
    <ul>
        <li><strong>用户名：</strong>admin</li>
        <li><strong>密码：</strong>admin123</li>
    </ul>
    <a href="login.html">去登录</a>
    <a href="admin.html">管理后台</a>
    <?php endif; ?>
</body>
</html>

