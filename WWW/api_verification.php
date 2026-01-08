<?php
/**
 * 身份认证API
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
        case 'submit':
            submitVerification($conn);
            break;
        case 'get_status':
            getVerificationStatus($conn);
            break;
        case 'get_list':
            getVerificationList($conn);
            break;
        case 'review':
            reviewVerification($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 提交认证申请
function submitVerification($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $input['user_id'] ?? 0;
    $real_name = trim($input['real_name'] ?? '');
    $id_card = trim($input['id_card'] ?? '');
    $school = trim($input['school'] ?? '');
    $major = trim($input['major'] ?? '');
    $note = trim($input['note'] ?? '');
    
    if (!$user_id || empty($real_name) || empty($id_card)) {
        echo json_encode(['success' => false, 'message' => '请填写真实姓名和身份证号']);
        return;
    }
    
    // 验证身份证格式
    if (!preg_match('/^[1-9]\d{5}(18|19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}[\dXx]$/', $id_card)) {
        echo json_encode(['success' => false, 'message' => '身份证号格式不正确']);
        return;
    }
    
    // 检查用户是否有家教信息
    $tutor_check = $conn->prepare("SELECT id FROM tutor_profiles WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $tutor_check->bind_param("i", $user_id);
    $tutor_check->execute();
    $tutor = $tutor_check->get_result()->fetch_assoc();
    
    if (!$tutor) {
        echo json_encode(['success' => false, 'message' => '请先发布家教信息']);
        return;
    }
    
    $tutor_profile_id = $tutor['id'];
    
    // 检查是否已有待审核的申请
    $pending_check = $conn->prepare("SELECT id FROM verification_requests WHERE user_id = ? AND status = 'pending'");
    $pending_check->bind_param("i", $user_id);
    $pending_check->execute();
    
    if ($pending_check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => '您已有待审核的认证申请']);
        return;
    }
    
    // 插入认证申请
    $stmt = $conn->prepare("INSERT INTO verification_requests (user_id, tutor_profile_id, real_name, id_card) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $user_id, $tutor_profile_id, $real_name, $id_card);
    
    if ($stmt->execute()) {
        // 更新家教信息
        $update = $conn->prepare("UPDATE tutor_profiles SET school = ?, major = ?, verify_status = 'pending' WHERE id = ?");
        $update->bind_param("ssi", $school, $major, $tutor_profile_id);
        $update->execute();
        
        echo json_encode([
            'success' => true,
            'message' => '认证申请已提交，请等待审核',
            'request_id' => $conn->insert_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '提交失败']);
    }
}

// 获取认证状态
function getVerificationStatus($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    // 获取用户的认证状态
    $stmt = $conn->prepare("SELECT vr.*, tp.name as tutor_name 
                            FROM verification_requests vr 
                            LEFT JOIN tutor_profiles tp ON vr.tutor_profile_id = tp.id
                            WHERE vr.user_id = ? 
                            ORDER BY vr.created_at DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'data' => [
                'status' => $row['status'],
                'real_name' => $row['real_name'],
                'submitted_at' => $row['created_at'],
                'reviewed_at' => $row['reviewed_at'],
                'review_message' => $row['review_message'],
                'tutor_name' => $row['tutor_name']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'data' => [
                'status' => 'not_submitted',
                'message' => '尚未提交认证申请'
            ]
        ]);
    }
}

// 获取认证申请列表（管理员用）
function getVerificationList($conn) {
    $admin_id = $_GET['admin_id'] ?? 0;
    $status = $_GET['status'] ?? 'pending';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    // 验证管理员权限
    $admin_check = $conn->prepare("SELECT is_admin FROM simple_users WHERE id = ? AND is_admin = 1");
    $admin_check->bind_param("i", $admin_id);
    $admin_check->execute();
    
    if ($admin_check->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $sql = "SELECT vr.*, u.username, u.email, tp.name as tutor_name, tp.subjects, tp.education
            FROM verification_requests vr
            LEFT JOIN simple_users u ON vr.user_id = u.id
            LEFT JOIN tutor_profiles tp ON vr.tutor_profile_id = tp.id
            WHERE vr.status = ?
            ORDER BY vr.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $status, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    
    // 获取各状态数量
    $counts = [];
    foreach (['pending', 'approved', 'rejected'] as $s) {
        $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM verification_requests WHERE status = ?");
        $count_stmt->bind_param("s", $s);
        $count_stmt->execute();
        $counts[$s] = $count_stmt->get_result()->fetch_assoc()['count'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $requests,
        'counts' => $counts,
        'page' => $page
    ]);
}

// 审核认证申请（管理员用）
function reviewVerification($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $admin_id = $input['admin_id'] ?? 0;
    $request_id = $input['request_id'] ?? 0;
    $status = $input['status'] ?? ''; // approved 或 rejected
    $message = trim($input['message'] ?? '');
    
    if (!$admin_id || !$request_id || !in_array($status, ['approved', 'rejected'])) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 验证管理员权限
    $admin_check = $conn->prepare("SELECT is_admin FROM simple_users WHERE id = ? AND is_admin = 1");
    $admin_check->bind_param("i", $admin_id);
    $admin_check->execute();
    
    if ($admin_check->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    // 获取申请信息
    $request = $conn->prepare("SELECT * FROM verification_requests WHERE id = ? AND status = 'pending'");
    $request->bind_param("i", $request_id);
    $request->execute();
    $req_data = $request->get_result()->fetch_assoc();
    
    if (!$req_data) {
        echo json_encode(['success' => false, 'message' => '申请不存在或已处理']);
        return;
    }
    
    // 开始事务
    $conn->begin_transaction();
    
    try {
        // 更新申请状态
        $update_req = $conn->prepare("UPDATE verification_requests SET status = ?, reviewer_id = ?, review_message = ?, reviewed_at = NOW() WHERE id = ?");
        $update_req->bind_param("sisi", $status, $admin_id, $message, $request_id);
        $update_req->execute();
        
        // 更新家教信息的认证状态
        $verify_status = $status === 'approved' ? 'approved' : 'rejected';
        $is_verified = $status === 'approved' ? 1 : 0;
        
        $update_tutor = $conn->prepare("UPDATE tutor_profiles SET verify_status = ?, is_verified = ?, verify_message = ? WHERE id = ?");
        $update_tutor->bind_param("sisi", $verify_status, $is_verified, $message, $req_data['tutor_profile_id']);
        $update_tutor->execute();
        
        // 更新用户的认证状态
        $update_user = $conn->prepare("UPDATE simple_users SET is_verified = ? WHERE id = ?");
        $update_user->bind_param("ii", $is_verified, $req_data['user_id']);
        $update_user->execute();
        
        // 发送通知给用户
        $notify_title = $status === 'approved' ? '认证审核通过' : '认证审核未通过';
        $notify_content = $status === 'approved' 
            ? '恭喜！您的身份认证已通过审核，您现在是认证家教老师了。'
            : '很抱歉，您的身份认证未通过审核。原因：' . ($message ?: '未提供');
        
        $notify = $conn->prepare("INSERT INTO notifications (user_id, title, content, type, related_id) VALUES (?, ?, ?, 'verification', ?)");
        $notify->bind_param("issi", $req_data['user_id'], $notify_title, $notify_content, $request_id);
        $notify->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $status === 'approved' ? '已通过认证' : '已拒绝认证'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()]);
    }
}
?>
