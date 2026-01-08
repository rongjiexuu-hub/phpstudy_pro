<?php
/**
 * 修改密码 API
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '只允许POST请求']);
    exit;
}

require_once 'config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit;
}
$conn->set_charset('utf8mb4');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $user_id = (int)($input['user_id'] ?? 0);
    $current_password = $input['current_password'] ?? '';
    $new_password = $input['new_password'] ?? '';
    
    if (!$user_id || !$current_password || !$new_password) {
        throw new Exception('缺少必要参数');
    }
    
    if (strlen($new_password) < 8) {
        throw new Exception('新密码长度至少为8个字符');
    }
    
    // 获取用户信息
    $stmt = $conn->prepare("SELECT password FROM simple_users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('用户不存在');
    }
    
    $user = $result->fetch_assoc();
    
    // 验证当前密码
    if (!password_verify($current_password, $user['password'])) {
        throw new Exception('当前密码错误');
    }
    
    // 更新密码
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE simple_users SET password = ? WHERE id = ?");
    $update->bind_param("si", $hashed_password, $user_id);
    
    if ($update->execute()) {
        echo json_encode(['success' => true, 'message' => '密码修改成功']);
    } else {
        throw new Exception('密码修改失败');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>

