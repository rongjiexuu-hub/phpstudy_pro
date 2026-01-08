<?php
// 登录处理文件
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
    $json_raw = file_get_contents('php://input');
    error_log("接收到的原始数据: " . $json_raw);
    
    if (empty($json_raw)) {
        echo json_encode(['success' => false, 'message' => '没有接收到数据']);
        exit;
    }
    
    $data = json_decode($json_raw, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON解析错误: " . json_last_error_msg());
        error_log("JSON错误代码: " . json_last_error());
        echo json_encode(['success' => false, 'message' => '数据格式错误: ' . json_last_error_msg()]);
        exit;
    }
    
    error_log("解析后的数据: " . print_r($data, true));
    
    // 验证必填字段
    $required_fields = ['username', 'password'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            echo json_encode(['success' => false, 'message' => '缺少必填字段: ' . $field]);
            exit;
        }
    }
    
    // 验证数据
    $username = trim($data['username']);
    $password = $data['password'];
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => '用户名和密码不能为空']);
        exit;
    }
    
    // 连接数据库
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    
    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败: ' . $conn->connect_error]);
        exit;
    }
    
    $conn->set_charset('utf8mb4');
    
    // 检查表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'simple_users'");
    if ($table_check === false || $table_check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '用户表不存在，请先创建数据库表']);
        exit;
    }
    
    // 检查表结构 - 适配现有表结构
    $columns_check = $conn->query("SHOW COLUMNS FROM simple_users");
    if ($columns_check === false || $columns_check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '用户表结构异常']);
        exit;
    }
    
    // 验证必需字段是否存在
    $required_columns = ['id', 'username', 'email', 'password'];
    $existing_columns = [];
    
    while ($column = $columns_check->fetch_assoc()) {
        $existing_columns[] = $column['Field'];
    }
    
    $missing_columns = array_diff($required_columns, $existing_columns);
    if (!empty($missing_columns)) {
        echo json_encode(['success' => false, 'message' => '用户表缺少必需字段: ' . implode(', ', $missing_columns)]);
        exit;
    }
    
    // 查询用户（支持用户名或邮箱登录）- 适配现有表结构
    // 先检查is_admin字段是否存在
    $check_column = $conn->query("SHOW COLUMNS FROM simple_users LIKE 'is_admin'");
    $has_is_admin = $check_column && $check_column->num_rows > 0;
    
    if ($has_is_admin) {
        $sql = "SELECT id, username, email, password, is_admin FROM simple_users WHERE username = ? OR email = ?";
    } else {
        $sql = "SELECT id, username, email, password FROM simple_users WHERE username = ? OR email = ?";
    }
    error_log("SQL查询: " . $sql);
    error_log("用户名参数: " . $username);
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("SQL预处理失败: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'SQL预处理失败: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("ss", $username, $username);
    
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'SQL执行失败: ' . $stmt->error]);
        $stmt->close();
        $conn->close();
        exit;
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '用户名/邮箱或密码错误']);
        $stmt->close();
        $conn->close();
        exit;
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // 验证密码
    if (!password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => '用户名/邮箱或密码错误']);
        $conn->close();
        exit;
    }
    
    // 登录成功，设置session - 适配现有表结构
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['full_name'] = $user['username']; // 使用用户名作为显示名称
    $_SESSION['role'] = 'student'; // 默认角色
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    
    $conn->close();
    
    // 返回用户信息（不包含密码）- 适配现有表结构
    echo json_encode([
        'success' => true,
        'message' => '登录成功',
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'fullName' => $user['username'], // 使用用户名作为显示名称
            'role' => 'student', // 默认角色
            'is_admin' => isset($user['is_admin']) ? (bool)$user['is_admin'] : false
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()]);
}
?>