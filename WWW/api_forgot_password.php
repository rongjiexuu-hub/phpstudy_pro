<?php
/**
 * 密码找回 API
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

// 启动 session 存储验证码
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit;
}
$conn->set_charset('utf8mb4');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'send_code':
            // 发送验证码
            $email = trim($input['email'] ?? '');
            
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('请输入有效的邮箱地址');
            }
            
            // 检查邮箱是否存在
            $stmt = $conn->prepare("SELECT id FROM simple_users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows === 0) {
                throw new Exception('该邮箱未注册');
            }
            
            // 生成6位验证码
            $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            
            // 生成重置令牌
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            
            // 保存到数据库
            $update = $conn->prepare("UPDATE simple_users SET reset_token = ?, reset_token_expires = ? WHERE email = ?");
            $tokenWithCode = $token . ':' . $code;
            $update->bind_param("sss", $tokenWithCode, $expires, $email);
            $update->execute();
            
            // 存储到 session（作为备用验证方式）
            $_SESSION['reset_code'] = $code;
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_expires'] = time() + 1800; // 30分钟
            
            // 这里应该发送邮件，但由于没有配置邮件服务，暂时在响应中返回验证码（仅用于测试）
            // 生产环境应该使用邮件服务发送
            echo json_encode([
                'success' => true, 
                'message' => '验证码已发送',
                // 仅用于测试，生产环境请删除下面这行
                'debug_code' => $code
            ]);
            break;
            
        case 'verify_code':
            // 验证验证码
            $email = trim($input['email'] ?? '');
            $code = trim($input['code'] ?? '');
            
            if (!$email || !$code) {
                throw new Exception('缺少必要参数');
            }
            
            // 从数据库验证
            $stmt = $conn->prepare("SELECT reset_token, reset_token_expires FROM simple_users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if (!$result || !$result['reset_token']) {
                throw new Exception('验证码无效或已过期');
            }
            
            // 检查是否过期
            if (strtotime($result['reset_token_expires']) < time()) {
                throw new Exception('验证码已过期，请重新获取');
            }
            
            // 解析令牌和验证码
            list($storedToken, $storedCode) = explode(':', $result['reset_token']);
            
            if ($code !== $storedCode) {
                // 尝试 session 验证
                if (isset($_SESSION['reset_code']) && 
                    $_SESSION['reset_email'] === $email && 
                    $_SESSION['reset_code'] === $code &&
                    $_SESSION['reset_expires'] > time()) {
                    $storedToken = bin2hex(random_bytes(32));
                } else {
                    throw new Exception('验证码错误');
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => '验证成功',
                'token' => $storedToken
            ]);
            break;
            
        case 'reset_password':
            // 重置密码
            $email = trim($input['email'] ?? '');
            $token = trim($input['token'] ?? '');
            $new_password = $input['new_password'] ?? '';
            
            if (!$email || !$token || !$new_password) {
                throw new Exception('缺少必要参数');
            }
            
            if (strlen($new_password) < 8) {
                throw new Exception('密码长度至少为8个字符');
            }
            
            // 验证令牌
            $stmt = $conn->prepare("SELECT id, reset_token, reset_token_expires FROM simple_users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if (!$result) {
                throw new Exception('用户不存在');
            }
            
            // 检查令牌是否有效
            $isValid = false;
            if ($result['reset_token']) {
                list($storedToken, $storedCode) = explode(':', $result['reset_token']);
                if ($storedToken === $token && strtotime($result['reset_token_expires']) > time()) {
                    $isValid = true;
                }
            }
            
            if (!$isValid) {
                throw new Exception('重置链接无效或已过期');
            }
            
            // 更新密码
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE simple_users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE email = ?");
            $update->bind_param("ss", $hashed_password, $email);
            
            if ($update->execute()) {
                // 清除 session
                unset($_SESSION['reset_code']);
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_expires']);
                
                echo json_encode(['success' => true, 'message' => '密码重置成功']);
            } else {
                throw new Exception('密码重置失败');
            }
            break;
            
        default:
            throw new Exception('未知操作');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>

