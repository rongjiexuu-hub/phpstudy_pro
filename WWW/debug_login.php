<?php
// 登录调试脚本
require_once 'config.php';

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>登录功能调试信息</h2>";

try {
    // 测试数据库连接
    echo "<h3>1. 数据库连接测试</h3>";
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    
    if ($conn->connect_error) {
        echo "<p style='color: red;'>数据库连接失败: " . $conn->connect_error . "</p>";
        exit;
    }
    
    echo "<p style='color: green;'>数据库连接成功</p>";
    
    // 检查表是否存在
    echo "<h3>2. 检查用户表</h3>";
    $table_check = $conn->query("SHOW TABLES LIKE 'simple_users'");
    if ($table_check->num_rows === 0) {
        echo "<p style='color: red;'>用户表 simple_users 不存在</p>";
        echo "<p><a href='setup_database.php'>点击这里创建数据库表</a></p>";
    } else {
        echo "<p style='color: green;'>用户表 simple_users 存在</p>";
        
        // 检查表结构
        echo "<h3>3. 表结构</h3>";
        $columns_query = "DESCRIBE simple_users";
        $columns_result = $conn->query($columns_query);
        
        if ($columns_result === false) {
            echo "<p style='color: red;'>获取表结构失败: " . $conn->error . "</p>";
        } else {
            echo "<table border='1'><tr><th>字段名</th><th>类型</th><th>是否为空</th><th>键</th></tr>";
            $existing_columns = [];
            while ($row = $columns_result->fetch_assoc()) {
                echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td></tr>";
                $existing_columns[] = $row['Field'];
            }
            echo "</table>";
        }
        
        // 检查必需字段 - 适配现有表结构
        echo "<h3>3.1. 字段验证</h3>";
        $required_columns = ['id', 'username', 'email', 'password'];
        $missing_columns = array_diff($required_columns, $existing_columns);
        
        if (!empty($missing_columns)) {
            echo "<p style='color: red;'>✗ 缺少必需字段: " . implode(', ', $missing_columns) . "</p>";
            echo "<p>你的表应该包含以下字段: id, username, email, password</p>";
        } else {
            echo "<p style='color: green;'>✓ 所有必需字段都存在</p>";
        }
        
        // 检查用户数据
        echo "<h3>4. 用户数据</h3>";
        $users_query = "SELECT id, username, email, full_name, role, created_at FROM simple_users LIMIT 5";
        $users_result = $conn->query($users_query);
        
        if ($users_result === false) {
            echo "<p style='color: red;'>查询用户数据失败: " . $conn->error . "</p>";
            echo "<p>SQL: " . $users_query . "</p>";
        } elseif ($users_result->num_rows === 0) {
            echo "<p style='color: orange;'>表中没有用户数据</p>";
        } else {
            echo "<table border='1'><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>姓名</th><th>角色</th><th>创建时间</th></tr>";
            while ($row = $users_result->fetch_assoc()) {
                echo "<tr><td>{$row['id']}</td><td>{$row['username']}</td><td>{$row['email']}</td><td>{$row['full_name']}</td><td>{$row['role']}</td><td>{$row['created_at']}</td></tr>";
            }
            echo "</table>";
        }
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>错误: " . $e->getMessage() . "</p>";
}

echo "<h3>5. 测试登录API</h3>";
echo "<form method='post' action='login.php'>";
echo "<p>用户名: <input type='text' name='username' value='testuser'></p>";
echo "<p>密码: <input type='password' name='password' value='123456'></p>";
echo "<p><input type='submit' value='测试登录'></p>";
echo "</form>";
?>