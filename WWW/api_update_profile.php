<?php
/**
 * 更新用户信息 API
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
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $user_type = $input['user_type'] ?? '';
    
    if (!$user_id) {
        throw new Exception('缺少用户ID');
    }
    
    // 验证邮箱格式
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('邮箱格式不正确');
    }
    
    // 验证手机号格式
    if ($phone && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
        throw new Exception('手机号格式不正确');
    }
    
    // 检查邮箱是否已被使用
    if ($email) {
        $check = $conn->prepare("SELECT id FROM simple_users WHERE email = ? AND id != ?");
        $check->bind_param("si", $email, $user_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            throw new Exception('该邮箱已被其他用户使用');
        }
    }
    
    // 构建更新语句
    $updates = [];
    $params = [];
    $types = '';
    
    if ($email) {
        $updates[] = "email = ?";
        $params[] = $email;
        $types .= 's';
    }
    
    if ($phone) {
        $updates[] = "phone = ?";
        $params[] = $phone;
        $types .= 's';
    }
    
    if ($user_type && in_array($user_type, ['student', 'tutor', 'parent'])) {
        $updates[] = "user_type = ?";
        $params[] = $user_type;
        $types .= 's';
    }
    
    if (empty($updates)) {
        throw new Exception('没有需要更新的内容');
    }
    
    $params[] = $user_id;
    $types .= 'i';
    
    $sql = "UPDATE simple_users SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '信息更新成功']);
    } else {
        throw new Exception('更新失败');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>

