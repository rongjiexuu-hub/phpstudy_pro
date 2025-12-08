<?php
// 简化的注册处理文件
session_start();

// 引入配置文件
require_once 'config.php';

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 设置响应头
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 只处理POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '只允许POST请求']);
    exit;
}

try {
    // 获取POST数据
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => '数据格式错误: ' . json_last_error_msg()]);
        exit;
    }
    
    // 验证必填字段
    $required_fields = ['username', 'email', 'password', 'confirmPassword'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            echo json_encode(['success' => false, 'message' => '缺少必填字段: ' . $field]);
            exit;
        }
    }
    
    // 验证数据
    $username = trim($data['username']);
    $email = trim($data['email']);
    $password = $data['password'];
    $confirmPassword = $data['confirmPassword'];
    
    // 验证用户名格式
    if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
        echo json_encode(['success' => false, 'message' => '用户名格式不正确，应为4-20个字符，只能包含字母、数字和下划线']);
        exit;
    }
    
    // 验证邮箱格式
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => '邮箱格式不正确']);
        exit;
    }
    
    // 验证密码
    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => '密码长度至少为8个字符']);
        exit;
    }
    
    if ($password !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => '两次输入的密码不一致']);
        exit;
    }
    
    // 连接数据库
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    
    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败: ' . $conn->connect_error]);
        exit;
    }
    
    $conn->set_charset('utf8mb4');
    
    // 检查用户名是否已存在
    $stmt = $conn->prepare("SELECT id FROM simple_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => '用户名已被使用']);
        $stmt->close();
        $conn->close();
        exit;
    }
    $stmt->close();
    
    // 检查邮箱是否已存在
    $stmt = $conn->prepare("SELECT id FROM simple_users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => '邮箱已被注册']);
        $stmt->close();
        $conn->close();
        exit;
    }
    $stmt->close();
    
    // 密码加密
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // 插入新用户
    $stmt = $conn->prepare("INSERT INTO simple_users (username, email, password) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $email, $hashed_password);
    
    if ($stmt->execute()) {
        $user_id = $conn->insert_id;
        
        // 设置session
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;
        $_SESSION['logged_in'] = false; // 注册后需要登录
        
        echo json_encode([
            'success' => true,
            'message' => '注册成功！请登录您的账户',
            'user_id' => $user_id,
            'username' => $username,
            'redirect' => 'login.html'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '注册失败: ' . $stmt->error]);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()]);
}
?>