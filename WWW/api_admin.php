<?php
/**
 * 管理后台API
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

// 验证管理员权限
function checkAdmin($conn, $admin_id) {
    if (!$admin_id) return false;
    $stmt = $conn->prepare("SELECT is_admin FROM simple_users WHERE id = ? AND is_admin = 1");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

try {
    switch ($action) {
        case 'get_dashboard':
            getDashboard($conn);
            break;
        case 'get_users':
            getUsers($conn);
            break;
        case 'update_user':
            updateUser($conn);
            break;
        case 'delete_user':
            deleteUser($conn);
            break;
        case 'get_tutors':
            getTutors($conn);
            break;
        case 'update_tutor':
            updateTutor($conn);
            break;
        case 'delete_tutor':
            deleteTutor($conn);
            break;
        case 'get_requests':
            getRequests($conn);
            break;
        case 'delete_request':
            deleteRequest($conn);
            break;
        case 'get_orders':
            getOrders($conn);
            break;
        case 'get_verifications':
            getVerifications($conn);
            break;
        case 'review_verification':
            reviewVerification($conn);
            break;
        case 'get_reviews':
            getReviews($conn);
            break;
        case 'delete_review':
            deleteReview($conn);
            break;
        case 'send_notification':
            sendNotification($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 获取仪表盘数据
function getDashboard($conn) {
    $admin_id = $_GET['admin_id'] ?? 0;
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $stats = [];
    
    // 用户统计
    $result = $conn->query("SELECT COUNT(*) as total FROM simple_users");
    $stats['total_users'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM simple_users WHERE DATE(created_at) = CURDATE()");
    $stats['today_users'] = $result->fetch_assoc()['total'];
    
    // 家教信息统计
    $result = $conn->query("SELECT COUNT(*) as total FROM tutor_profiles WHERE status = 'active'");
    $stats['active_tutors'] = $result->fetch_assoc()['total'];
    
    // 需求统计
    $result = $conn->query("SELECT COUNT(*) as total FROM tutoring_requests WHERE status = 'active'");
    $stats['active_requests'] = $result->fetch_assoc()['total'];
    
    // 订单统计
    $result = $conn->query("SELECT COUNT(*) as total FROM orders");
    $stats['total_orders'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM orders WHERE status = 'pending'");
    $stats['pending_orders'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM orders WHERE status = 'completed'");
    $stats['completed_orders'] = $result->fetch_assoc()['total'];
    
    // 待审核认证
    $result = $conn->query("SELECT COUNT(*) as total FROM verification_requests WHERE status = 'pending'");
    $stats['pending_verifications'] = $result->fetch_assoc()['total'];
    
    // 今日消息
    $result = $conn->query("SELECT COUNT(*) as total FROM messages WHERE DATE(created_at) = CURDATE()");
    $stats['today_messages'] = $result->fetch_assoc()['total'];
    
    // 收入统计
    $result = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE status = 'completed'");
    $stats['total_revenue'] = $result->fetch_assoc()['total'];
    
    // 最近7天用户注册趋势
    $trend = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM simple_users WHERE DATE(created_at) = ?");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $trend[] = [
            'date' => $date,
            'count' => $stmt->get_result()->fetch_assoc()['count']
        ];
    }
    $stats['user_trend'] = $trend;
    
    echo json_encode(['success' => true, 'data' => $stats]);
}

// 获取用户列表
function getUsers($conn) {
    $admin_id = $_GET['admin_id'] ?? 0;
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $search = $_GET['search'] ?? '';
    
    $where = "1=1";
    $params = [];
    $types = '';
    
    if ($search) {
        $where .= " AND (username LIKE ? OR email LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'ss';
    }
    
    $sql = "SELECT id, username, email, phone, user_type, is_verified, is_admin, status, created_at 
            FROM simple_users WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    // 总数
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM simple_users WHERE $where");
    if ($search) {
        $count_stmt->bind_param('ss', $searchParam, $searchParam);
    }
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $users,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

// 更新用户
function updateUser($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $admin_id = $input['admin_id'] ?? 0;
    
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $user_id = $input['user_id'] ?? 0;
    $status = $input['status'] ?? null;
    $is_admin = $input['is_admin'] ?? null;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    $updates = [];
    $params = [];
    $types = '';
    
    if ($status !== null) {
        $updates[] = "status = ?";
        $params[] = $status;
        $types .= 's';
    }
    if ($is_admin !== null) {
        $updates[] = "is_admin = ?";
        $params[] = $is_admin;
        $types .= 'i';
    }
    
    if (empty($updates)) {
        echo json_encode(['success' => false, 'message' => '没有要更新的内容']);
        return;
    }
    
    $params[] = $user_id;
    $types .= 'i';
    
    $sql = "UPDATE simple_users SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        // 记录日志
        logAdminAction($conn, $admin_id, 'update_user', 'user', $user_id, json_encode($input));
        echo json_encode(['success' => true, 'message' => '更新成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失败']);
    }
}

// 删除用户
function deleteUser($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $admin_id = $input['admin_id'] ?? 0;
    
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $user_id = $input['user_id'] ?? 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    // 不能删除自己
    if ($user_id == $admin_id) {
        echo json_encode(['success' => false, 'message' => '不能删除自己']);
        return;
    }
    
    $stmt = $conn->prepare("DELETE FROM simple_users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        logAdminAction($conn, $admin_id, 'delete_user', 'user', $user_id, '');
        echo json_encode(['success' => true, 'message' => '删除成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败']);
    }
}

// 获取家教列表
function getTutors($conn) {
    $admin_id = $_GET['admin_id'] ?? 0;
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $sql = "SELECT tp.*, u.username, u.email 
            FROM tutor_profiles tp 
            LEFT JOIN simple_users u ON tp.user_id = u.id 
            ORDER BY tp.created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tutors = [];
    while ($row = $result->fetch_assoc()) {
        $tutors[] = $row;
    }
    
    $total = $conn->query("SELECT COUNT(*) as total FROM tutor_profiles")->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $tutors,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

// 更新家教状态
function updateTutor($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $admin_id = $input['admin_id'] ?? 0;
    
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $tutor_id = $input['tutor_id'] ?? 0;
    $status = $input['status'] ?? '';
    
    if (!$tutor_id || !$status) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    $stmt = $conn->prepare("UPDATE tutor_profiles SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $tutor_id);
    
    if ($stmt->execute()) {
        logAdminAction($conn, $admin_id, 'update_tutor', 'tutor', $tutor_id, $status);
        echo json_encode(['success' => true, 'message' => '更新成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失败']);
    }
}

// 删除家教
function deleteTutor($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $admin_id = $input['admin_id'] ?? 0;
    
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $tutor_id = $input['tutor_id'] ?? 0;
    
    $stmt = $conn->prepare("DELETE FROM tutor_profiles WHERE id = ?");
    $stmt->bind_param("i", $tutor_id);
    
    if ($stmt->execute()) {
        logAdminAction($conn, $admin_id, 'delete_tutor', 'tutor', $tutor_id, '');
        echo json_encode(['success' => true, 'message' => '删除成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败']);
    }
}

// 获取需求列表
function getRequests($conn) {
    $admin_id = $_GET['admin_id'] ?? 0;
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $sql = "SELECT tr.*, u.username 
            FROM tutoring_requests tr 
            LEFT JOIN simple_users u ON tr.user_id = u.id 
            ORDER BY tr.created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    
    $total = $conn->query("SELECT COUNT(*) as total FROM tutoring_requests")->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $requests,
        'total' => $total,
        'page' => $page
    ]);
}

// 删除需求
function deleteRequest($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $admin_id = $input['admin_id'] ?? 0;
    
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $request_id = $input['request_id'] ?? 0;
    
    $stmt = $conn->prepare("DELETE FROM tutoring_requests WHERE id = ?");
    $stmt->bind_param("i", $request_id);
    
    if ($stmt->execute()) {
        logAdminAction($conn, $admin_id, 'delete_request', 'request', $request_id, '');
        echo json_encode(['success' => true, 'message' => '删除成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败']);
    }
}

// 获取订单列表
function getOrders($conn) {
    $admin_id = $_GET['admin_id'] ?? 0;
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $status = $_GET['status'] ?? '';
    
    $where = "1=1";
    if ($status) {
        $where .= " AND o.status = '$status'";
    }
    
    $sql = "SELECT o.*, p.username as parent_name, tp.name as tutor_name 
            FROM orders o 
            LEFT JOIN simple_users p ON o.parent_id = p.id 
            LEFT JOIN tutor_profiles tp ON o.tutor_id = tp.id 
            WHERE $where
            ORDER BY o.created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    
    $total = $conn->query("SELECT COUNT(*) as total FROM orders")->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $orders,
        'total' => $total,
        'page' => $page
    ]);
}

// 获取认证申请列表
function getVerifications($conn) {
    $admin_id = $_GET['admin_id'] ?? 0;
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $status = $_GET['status'] ?? 'pending';
    
    $sql = "SELECT vr.*, u.username, u.email, tp.name as tutor_name, tp.subjects, tp.education
            FROM verification_requests vr
            LEFT JOIN simple_users u ON vr.user_id = u.id
            LEFT JOIN tutor_profiles tp ON vr.tutor_profile_id = tp.id
            WHERE vr.status = ?
            ORDER BY vr.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $status);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $verifications = [];
    while ($row = $result->fetch_assoc()) {
        $verifications[] = $row;
    }
    
    // 各状态数量
    $counts = [];
    foreach (['pending', 'approved', 'rejected'] as $s) {
        $c = $conn->query("SELECT COUNT(*) as count FROM verification_requests WHERE status = '$s'")->fetch_assoc()['count'];
        $counts[$s] = $c;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $verifications,
        'counts' => $counts
    ]);
}

// 审核认证
function reviewVerification($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $admin_id = $input['admin_id'] ?? 0;
    
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $request_id = $input['request_id'] ?? 0;
    $status = $input['status'] ?? '';
    $message = $input['message'] ?? '';
    
    if (!$request_id || !in_array($status, ['approved', 'rejected'])) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 获取申请信息
    $req = $conn->prepare("SELECT * FROM verification_requests WHERE id = ?");
    $req->bind_param("i", $request_id);
    $req->execute();
    $request = $req->get_result()->fetch_assoc();
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => '申请不存在']);
        return;
    }
    
    $conn->begin_transaction();
    
    try {
        // 更新申请状态
        $stmt = $conn->prepare("UPDATE verification_requests SET status = ?, reviewer_id = ?, review_message = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->bind_param("sisi", $status, $admin_id, $message, $request_id);
        $stmt->execute();
        
        // 更新家教认证状态
        $is_verified = $status === 'approved' ? 1 : 0;
        $stmt2 = $conn->prepare("UPDATE tutor_profiles SET is_verified = ?, verify_status = ? WHERE id = ?");
        $stmt2->bind_param("isi", $is_verified, $status, $request['tutor_profile_id']);
        $stmt2->execute();
        
        // 更新用户认证状态
        $stmt3 = $conn->prepare("UPDATE simple_users SET is_verified = ? WHERE id = ?");
        $stmt3->bind_param("ii", $is_verified, $request['user_id']);
        $stmt3->execute();
        
        // 发送通知
        $title = $status === 'approved' ? '认证已通过' : '认证未通过';
        $content = $status === 'approved' ? '恭喜！您的身份认证已通过。' : '您的认证申请未通过，原因：' . $message;
        $stmt4 = $conn->prepare("INSERT INTO notifications (user_id, title, content, type) VALUES (?, ?, ?, 'verification')");
        $stmt4->bind_param("iss", $request['user_id'], $title, $content);
        $stmt4->execute();
        
        $conn->commit();
        
        logAdminAction($conn, $admin_id, 'review_verification', 'verification', $request_id, $status);
        echo json_encode(['success' => true, 'message' => '审核完成']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => '操作失败']);
    }
}

// 获取评价列表
function getReviews($conn) {
    $admin_id = $_GET['admin_id'] ?? 0;
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $sql = "SELECT r.*, u.username as reviewer_name, tp.name as tutor_name
            FROM reviews r
            LEFT JOIN simple_users u ON r.reviewer_id = u.id
            LEFT JOIN tutor_profiles tp ON r.tutor_id = tp.id
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reviews = [];
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    
    $total = $conn->query("SELECT COUNT(*) as total FROM reviews")->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $reviews,
        'total' => $total,
        'page' => $page
    ]);
}

// 删除评价
function deleteReview($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $admin_id = $input['admin_id'] ?? 0;
    
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $review_id = $input['review_id'] ?? 0;
    
    $stmt = $conn->prepare("UPDATE reviews SET status = 'hidden' WHERE id = ?");
    $stmt->bind_param("i", $review_id);
    
    if ($stmt->execute()) {
        logAdminAction($conn, $admin_id, 'delete_review', 'review', $review_id, '');
        echo json_encode(['success' => true, 'message' => '删除成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败']);
    }
}

// 发送系统通知
function sendNotification($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $admin_id = $input['admin_id'] ?? 0;
    
    if (!checkAdmin($conn, $admin_id)) {
        echo json_encode(['success' => false, 'message' => '无管理员权限']);
        return;
    }
    
    $user_ids = $input['user_ids'] ?? []; // 空数组表示全部用户
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    
    if (empty($title) || empty($content)) {
        echo json_encode(['success' => false, 'message' => '标题和内容不能为空']);
        return;
    }
    
    if (empty($user_ids)) {
        // 发送给所有用户
        $users = $conn->query("SELECT id FROM simple_users");
        while ($u = $users->fetch_assoc()) {
            $user_ids[] = $u['id'];
        }
    }
    
    $count = 0;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, content, type) VALUES (?, ?, ?, 'system')");
    
    foreach ($user_ids as $uid) {
        $stmt->bind_param("iss", $uid, $title, $content);
        if ($stmt->execute()) $count++;
    }
    
    logAdminAction($conn, $admin_id, 'send_notification', 'notification', 0, "发送给{$count}人");
    echo json_encode(['success' => true, 'message' => "已发送给 $count 位用户"]);
}

// 记录管理员操作日志
function logAdminAction($conn, $admin_id, $action, $target_type, $target_id, $details) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississ", $admin_id, $action, $target_type, $target_id, $details, $ip);
    $stmt->execute();
}
?>

