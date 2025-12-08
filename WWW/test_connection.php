<?php
require_once 'config.php';

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        die("连接失败: " . $conn->connect_error);
    }
    echo "数据库连接成功！";
    
    // 检查simple_users表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'simple_users'");
    if ($table_check->num_rows === 0) {
        echo "<p style='color: orange;'>⚠️ simple_users表不存在，请先创建用户表</p>";
    } else {
        echo "<p style='color: green;'>✅ simple_users表已存在</p>";
    }
    
    // 创建tutoring_requests表（存储家教需求信息）
    $create_requests_sql = "CREATE TABLE IF NOT EXISTS tutoring_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        student_name VARCHAR(100) NOT NULL,
        grade VARCHAR(50) NOT NULL,
        subjects VARCHAR(200) NOT NULL,
        location VARCHAR(200) NOT NULL,
        schedule VARCHAR(100) NOT NULL,
        salary VARCHAR(100) NOT NULL,
        requirements TEXT,
        contact_phone VARCHAR(20) NOT NULL,
        status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES simple_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($conn->query($create_requests_sql)) {
        echo "<p style='color: green;'>✅ tutoring_requests表创建成功</p>";
    } else {
        echo "<p style='color: red;'>❌ tutoring_requests表创建失败: " . $conn->error . "</p>";
    }
    
    // 检查tutoring_requests表结构
    $requests_check = $conn->query("DESCRIBE tutoring_requests");
    if ($requests_check) {
        echo "<h3>📋 tutoring_requests表结构：</h3>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>字段名</th><th>类型</th><th>空值</th><th>键</th></tr>";
        while ($row = $requests_check->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['Field'] . "</td>";
            echo "<td>" . $row['Type'] . "</td>";
            echo "<td>" . $row['Null'] . "</td>";
            echo "<td>" . $row['Key'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 创建tutor_profiles表（存储家教老师信息）
    $create_tutor_sql = "CREATE TABLE IF NOT EXISTS tutor_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        education VARCHAR(200) NOT NULL,
        subjects VARCHAR(200) NOT NULL,
        experience VARCHAR(100) NOT NULL,
        teaching_areas VARCHAR(300) NOT NULL,
        schedule VARCHAR(100) NOT NULL,
        salary VARCHAR(100) NOT NULL,
        teaching_style TEXT,
        contact_phone VARCHAR(20) NOT NULL,
        status ENUM('active', 'inactive', 'busy') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES simple_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($conn->query($create_tutor_sql)) {
        echo "<p style='color: green;'>✅ tutor_profiles表创建成功</p>";
    } else {
        echo "<p style='color: red;'>❌ tutor_profiles表创建失败: " . $conn->error . "</p>";
    }
    
    // 检查tutor_profiles表结构
    $tutor_check = $conn->query("DESCRIBE tutor_profiles");
    if ($tutor_check) {
        echo "<h3>📋 tutor_profiles表结构：</h3>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>字段名</th><th>类型</th><th>空值</th><th>键</th></tr>";
        while ($row = $tutor_check->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['Field'] . "</td>";
            echo "<td>" . $row['Type'] . "</td>";
            echo "<td>" . $row['Null'] . "</td>";
            echo "<td>" . $row['Key'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    $conn->close();
} catch (Exception $e) {
    echo "连接错误: " . $e->getMessage();
}
?>