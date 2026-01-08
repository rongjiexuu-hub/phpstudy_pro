<?php
/**
 * 用户API - 个人中心相关接口
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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
        case 'get_profile':
            getUserProfile($conn);
            break;
        case 'update_profile':
            updateUserProfile($conn);
            break;
        case 'get_my_tutor_profiles':
            getMyTutorProfiles($conn);
            break;
        case 'get_my_requests':
            getMyRequests($conn);
            break;
        case 'delete_tutor_profile':
            deleteTutorProfile($conn);
            break;
        case 'delete_request':
            deleteRequest($conn);
            break;
        case 'update_tutor_profile':
            updateTutorProfile($conn);
            break;
        case 'update_request':
            updateRequest($conn);
            break;
        case 'change_password':
            changePassword($conn);
            break;
        case 'get_statistics':
            getStatistics($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 获取用户资料
function getUserProfile($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    // 检查nickname列是否存在
    $has_nickname = false;
    $check_col = $conn->query("SHOW COLUMNS FROM simple_users LIKE 'nickname'");
    if ($check_col && $check_col->num_rows > 0) {
        $has_nickname = true;
    }
    
    $columns = "id, username, email, phone, avatar, user_type, is_verified, created_at";
    if ($has_nickname) {
        $columns .= ", nickname";
    }
    
    $stmt = $conn->prepare("SELECT $columns FROM simple_users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
    }
}

// 更新用户资料
function updateUserProfile($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $user_id = $input['user_id'] ?? 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    // 检查并添加必要的列
    $check_cols = ['nickname', 'phone', 'avatar', 'user_type'];
    foreach ($check_cols as $col) {
        $check = $conn->query("SHOW COLUMNS FROM simple_users LIKE '$col'");
        if ($check && $check->num_rows === 0) {
            // 列不存在，添加它
            $type = 'VARCHAR(255) DEFAULT NULL';
            $conn->query("ALTER TABLE simple_users ADD COLUMN $col $type");
        }
    }
    
    $fields = [];
    $params = [];
    $types = '';
    
    if (isset($input['nickname'])) {
        $fields[] = 'nickname = ?';
        $params[] = $input['nickname'];
        $types .= 's';
    }
    if (isset($input['email'])) {
        // 检查邮箱是否被其他用户使用
        $email_check = $conn->prepare("SELECT id FROM simple_users WHERE email = ? AND id != ?");
        $email_check->bind_param("si", $input['email'], $user_id);
        $email_check->execute();
        if ($email_check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => '该邮箱已被其他用户使用']);
            return;
        }
        $fields[] = 'email = ?';
        $params[] = $input['email'];
        $types .= 's';
    }
    if (isset($input['phone'])) {
        $fields[] = 'phone = ?';
        $params[] = $input['phone'];
        $types .= 's';
    }
    if (isset($input['avatar'])) {
        $fields[] = 'avatar = ?';
        $params[] = $input['avatar'];
        $types .= 's';
    }
    if (isset($input['user_type'])) {
        $fields[] = 'user_type = ?';
        $params[] = $input['user_type'];
        $types .= 's';
    }
    
    if (empty($fields)) {
        echo json_encode(['success' => false, 'message' => '没有要更新的字段']);
        return;
    }
    
    $params[] = $user_id;
    $types .= 'i';
    
    $sql = "UPDATE simple_users SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '资料更新成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失败']);
    }
}

// 获取我发布的家教信息
function getMyTutorProfiles($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    $stmt = $conn->prepare("SELECT * FROM tutor_profiles WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $profiles = [];
    while ($row = $result->fetch_assoc()) {
        $profiles[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $profiles]);
}

// 获取我发布的家教需求
function getMyRequests($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    $stmt = $conn->prepare("SELECT * FROM tutoring_requests WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $requests]);
}

// 删除家教信息
function deleteTutorProfile($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $profile_id = $input['profile_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    
    if (!$profile_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    $stmt = $conn->prepare("DELETE FROM tutor_profiles WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $profile_id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => '删除成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败或无权限']);
    }
}

// 删除家教需求
function deleteRequest($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $request_id = $input['request_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    
    if (!$request_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    $stmt = $conn->prepare("DELETE FROM tutoring_requests WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $request_id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => '删除成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败或无权限']);
    }
}

// 更新家教信息
function updateTutorProfile($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $profile_id = $input['profile_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    
    if (!$profile_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 验证所有权
    $check = $conn->prepare("SELECT id FROM tutor_profiles WHERE id = ? AND user_id = ?");
    $check->bind_param("ii", $profile_id, $user_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '无权限修改']);
        return;
    }
    
    $stmt = $conn->prepare("UPDATE tutor_profiles SET name=?, education=?, subjects=?, experience=?, teaching_areas=?, schedule=?, salary=?, teaching_style=?, contact_phone=? WHERE id=? AND user_id=?");
    $stmt->bind_param("sssssssssii", 
        $input['name'],
        $input['education'],
        $input['subjects'],
        $input['experience'],
        $input['teaching_areas'],
        $input['schedule'],
        $input['salary'],
        $input['teaching_style'],
        $input['contact_phone'],
        $profile_id,
        $user_id
    );
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '更新成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失败']);
    }
}

// 更新家教需求
function updateRequest($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $request_id = $input['request_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    
    if (!$request_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    $stmt = $conn->prepare("UPDATE tutoring_requests SET student_name=?, grade=?, subjects=?, location=?, schedule=?, salary=?, requirements=?, contact_phone=? WHERE id=? AND user_id=?");
    $stmt->bind_param("ssssssssii",
        $input['student_name'],
        $input['grade'],
        $input['subjects'],
        $input['location'],
        $input['schedule'],
        $input['salary'],
        $input['requirements'],
        $input['contact_phone'],
        $request_id,
        $user_id
    );
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '更新成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失败']);
    }
}

// 修改密码
function changePassword($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $user_id = $input['user_id'] ?? 0;
    $old_password = $input['old_password'] ?? '';
    $new_password = $input['new_password'] ?? '';
    
    if (!$user_id || !$old_password || !$new_password) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    if (strlen($new_password) < 8) {
        echo json_encode(['success' => false, 'message' => '新密码长度至少8位']);
        return;
    }
    
    // 验证旧密码
    $stmt = $conn->prepare("SELECT password FROM simple_users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (!password_verify($old_password, $row['password'])) {
            echo json_encode(['success' => false, 'message' => '原密码错误']);
            return;
        }
        
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE simple_users SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed, $user_id);
        
        if ($update->execute()) {
            echo json_encode(['success' => true, 'message' => '密码修改成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '密码修改失败']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
    }
}

// 获取用户统计数据
function getStatistics($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    $stats = [];
    
    // 发布的家教信息数
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tutor_profiles WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stats['tutor_profiles'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // 发布的需求数
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tutoring_requests WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stats['requests'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // 收藏数
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM favorites WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stats['favorites'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // 订单数
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM orders WHERE parent_id = ? OR tutor_id IN (SELECT id FROM tutor_profiles WHERE user_id = ?)");
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $stats['orders'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // 未读消息数
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stats['unread_messages'] = $stmt->get_result()->fetch_assoc()['count'];
    
    echo json_encode(['success' => true, 'data' => $stats]);
}
?>

