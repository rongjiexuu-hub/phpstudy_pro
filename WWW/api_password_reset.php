<?php
/**
 * 密码重置API
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit;
}
$conn->set_charset('utf8mb4');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'send_code':
            sendResetCode($conn);
            break;
        case 'verify_code':
            verifyCode($conn);
            break;
        case 'reset_password':
            resetPassword($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 发送重置验证码
function sendResetCode($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => '请输入邮箱']);
        return;
    }
    
    // 检查邮箱是否存在
    $stmt = $conn->prepare("SELECT id FROM simple_users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '该邮箱未注册']);
        return;
    }
    
    $user = $result->fetch_assoc();
    $user_id = $user['id'];
    
    // 生成6位验证码
    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // 生成token
    $token = bin2hex(random_bytes(32));
    
    // 设置过期时间（15分钟后）
    $expires_at = date('Y-m-d H:i:s', time() + 900);
    
    // 删除旧的重置记录
    $del = $conn->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
    $del->bind_param("i", $user_id);
    $del->execute();
    
    // 保存新的验证码（这里token字段暂存验证码）
    $stmt = $conn->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
    $token_with_code = $code . ':' . $token;
    $stmt->bind_param("iss", $user_id, $token_with_code, $expires_at);
    
    if ($stmt->execute()) {
        // 在实际项目中，这里应该发送邮件
        // 由于是本地开发环境，我们直接返回验证码（仅用于测试）
        // 生产环境应该删除code字段的返回
        echo json_encode([
            'success' => true,
            'message' => '验证码已发送到您的邮箱',
            'code' => $code // 仅测试用，生产环境删除此行
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '发送失败，请重试']);
    }
}

// 验证验证码
function verifyCode($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $code = trim($input['code'] ?? '');
    
    if (empty($email) || empty($code)) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 查找用户
    $stmt = $conn->prepare("SELECT id FROM simple_users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
        return;
    }
    
    $user = $result->fetch_assoc();
    $user_id = $user['id'];
    
    // 查找有效的重置记录
    $stmt = $conn->prepare("SELECT token FROM password_reset_tokens WHERE user_id = ? AND expires_at > NOW() AND used = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '验证码已过期，请重新获取']);
        return;
    }
    
    $row = $result->fetch_assoc();
    $stored_data = explode(':', $row['token']);
    $stored_code = $stored_data[0];
    $stored_token = $stored_data[1] ?? '';
    
    if ($code !== $stored_code) {
        echo json_encode(['success' => false, 'message' => '验证码错误']);
        return;
    }
    
    // 验证码正确，返回token用于重置密码
    echo json_encode([
        'success' => true,
        'message' => '验证成功',
        'token' => $stored_token
    ]);
}

// 重置密码
function resetPassword($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $token = trim($input['token'] ?? '');
    $new_password = $input['new_password'] ?? '';
    
    if (empty($email) || empty($token) || empty($new_password)) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    if (strlen($new_password) < 8) {
        echo json_encode(['success' => false, 'message' => '密码长度至少8位']);
        return;
    }
    
    // 查找用户
    $stmt = $conn->prepare("SELECT id FROM simple_users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
        return;
    }
    
    $user = $result->fetch_assoc();
    $user_id = $user['id'];
    
    // 验证token
    $stmt = $conn->prepare("SELECT id, token FROM password_reset_tokens WHERE user_id = ? AND expires_at > NOW() AND used = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '重置链接已过期，请重新获取']);
        return;
    }
    
    $row = $result->fetch_assoc();
    $stored_data = explode(':', $row['token']);
    $stored_token = $stored_data[1] ?? '';
    
    if ($token !== $stored_token) {
        echo json_encode(['success' => false, 'message' => '无效的重置链接']);
        return;
    }
    
    // 更新密码
    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE simple_users SET password = ? WHERE id = ?");
    $update->bind_param("si", $hashed, $user_id);
    
    if ($update->execute()) {
        // 标记token已使用
        $mark = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?");
        $mark->bind_param("i", $row['id']);
        $mark->execute();
        
        echo json_encode(['success' => true, 'message' => '密码重置成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '密码重置失败']);
    }
}
?>

