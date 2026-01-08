<?php
/**
 * 订单管理API
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
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

// 确保orders表存在
$table_check = $conn->query("SHOW TABLES LIKE 'orders'");
if ($table_check->num_rows === 0) {
    $create_sql = "CREATE TABLE orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_no VARCHAR(50) NOT NULL UNIQUE COMMENT '订单号',
        parent_id INT NOT NULL COMMENT '家长ID',
        tutor_id INT NOT NULL COMMENT '家教ID',
        request_id INT DEFAULT NULL COMMENT '关联需求ID',
        student_name VARCHAR(50),
        subjects VARCHAR(200),
        schedule VARCHAR(200),
        location VARCHAR(200),
        price_per_hour DECIMAL(10,2) COMMENT '每小时价格',
        total_hours DECIMAL(10,2) DEFAULT 0 COMMENT '总课时',
        total_amount DECIMAL(10,2) DEFAULT 0 COMMENT '总金额',
        notes TEXT COMMENT '备注',
        status ENUM('pending', 'accepted', 'rejected', 'ongoing', 'completed', 'cancelled') DEFAULT 'pending',
        parent_confirmed TINYINT(1) DEFAULT 0,
        tutor_confirmed TINYINT(1) DEFAULT 0,
        cancel_reason TEXT,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($create_sql);
}

// 确保notifications表存在
$notify_check = $conn->query("SHOW TABLES LIKE 'notifications'");
if ($notify_check->num_rows === 0) {
    $create_notify = "CREATE TABLE notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(200) NOT NULL,
        content TEXT NOT NULL,
        type ENUM('system', 'order', 'message', 'review', 'verification') DEFAULT 'system',
        related_id INT DEFAULT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($create_notify);
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            createOrder($conn);
            break;
        case 'get_list':
            getOrderList($conn);
            break;
        case 'get_detail':
            getOrderDetail($conn);
            break;
        case 'update_status':
            updateOrderStatus($conn);
            break;
        case 'cancel':
            cancelOrder($conn);
            break;
        case 'complete':
            completeOrder($conn);
            break;
        case 'get_statistics':
            getOrderStatistics($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 生成订单号
function generateOrderNo() {
    return 'JJT' . date('YmdHis') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
}

// 创建订单
function createOrder($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $parent_id = $input['parent_id'] ?? 0;
    $tutor_id = $input['tutor_id'] ?? 0;
    $request_id = $input['request_id'] ?? null;
    $student_name = trim($input['student_name'] ?? '');
    $subjects = trim($input['subjects'] ?? '');
    $schedule = trim($input['schedule'] ?? '');
    $location = trim($input['location'] ?? '');
    $price_per_hour = floatval($input['price_per_hour'] ?? 0);
    $notes = trim($input['notes'] ?? '');
    
    if (!$parent_id || !$tutor_id || empty($student_name) || empty($subjects)) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 验证家教存在
    $tutor_check = $conn->prepare("SELECT tp.id, tp.user_id, tp.name, u.username 
                                   FROM tutor_profiles tp 
                                   LEFT JOIN simple_users u ON tp.user_id = u.id 
                                   WHERE tp.id = ?");
    $tutor_check->bind_param("i", $tutor_id);
    $tutor_check->execute();
    $tutor = $tutor_check->get_result()->fetch_assoc();
    
    if (!$tutor) {
        echo json_encode(['success' => false, 'message' => '家教不存在']);
        return;
    }
    
    // 不能给自己下单
    if ($tutor['user_id'] == $parent_id) {
        echo json_encode(['success' => false, 'message' => '不能预约自己']);
        return;
    }
    
    $order_no = generateOrderNo();
    
    $stmt = $conn->prepare("INSERT INTO orders (order_no, parent_id, tutor_id, request_id, student_name, subjects, schedule, location, price_per_hour, notes) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siisssssds", $order_no, $parent_id, $tutor_id, $request_id, $student_name, $subjects, $schedule, $location, $price_per_hour, $notes);
    
    if ($stmt->execute()) {
        $order_id = $conn->insert_id;
        
        // 发送通知给家教
        $parent_stmt = $conn->prepare("SELECT username FROM simple_users WHERE id = ?");
        $parent_stmt->bind_param("i", $parent_id);
        $parent_stmt->execute();
        $parent = $parent_stmt->get_result()->fetch_assoc();
        
        $notify = $conn->prepare("INSERT INTO notifications (user_id, title, content, type, related_id) VALUES (?, ?, ?, 'order', ?)");
        $title = '收到新的家教预约';
        $content = $parent['username'] . ' 预约了您的家教服务：' . $subjects . '，请及时处理。';
        $notify->bind_param("issi", $tutor['user_id'], $title, $content, $order_id);
        $notify->execute();
        
        // 发送消息
        $msg = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, subject, content, message_type, related_type, related_id) VALUES (?, ?, ?, ?, 'system', 'order', ?)");
        $msg->bind_param("iissi", $parent_id, $tutor['user_id'], $title, $content, $order_id);
        $msg->execute();
        
        echo json_encode([
            'success' => true,
            'message' => '预约成功，等待家教确认',
            'order_id' => $order_id,
            'order_no' => $order_no
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '创建订单失败']);
    }
}

// 获取订单列表
function getOrderList($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    $role = $_GET['role'] ?? 'all'; // parent, tutor, all
    $status = $_GET['status'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    $where_conditions = [];
    $params = [];
    $types = '';
    
    if ($role === 'parent') {
        $where_conditions[] = "o.parent_id = ?";
        $params[] = $user_id;
        $types .= 'i';
    } elseif ($role === 'tutor') {
        $where_conditions[] = "tp.user_id = ?";
        $params[] = $user_id;
        $types .= 'i';
    } else {
        $where_conditions[] = "(o.parent_id = ? OR tp.user_id = ?)";
        $params[] = $user_id;
        $params[] = $user_id;
        $types .= 'ii';
    }
    
    if ($status) {
        $where_conditions[] = "o.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $where = implode(' AND ', $where_conditions);
    
    $sql = "SELECT o.*, tp.name as tutor_name, tp.contact_phone as tutor_phone,
            p.username as parent_name, p.email as parent_email
            FROM orders o
            LEFT JOIN tutor_profiles tp ON o.tutor_id = tp.id
            LEFT JOIN simple_users p ON o.parent_id = p.id
            WHERE $where
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    
    // 获取总数
    $count_sql = "SELECT COUNT(*) as total FROM orders o 
                  LEFT JOIN tutor_profiles tp ON o.tutor_id = tp.id 
                  WHERE " . implode(' AND ', array_slice($where_conditions, 0, -0));
    // 简化计数查询
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE parent_id = ? OR tutor_id IN (SELECT id FROM tutor_profiles WHERE user_id = ?)");
    $count_stmt->bind_param("ii", $user_id, $user_id);
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $orders,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

// 获取订单详情
function getOrderDetail($conn) {
    $order_id = $_GET['order_id'] ?? 0;
    $user_id = $_GET['user_id'] ?? 0;
    
    if (!$order_id) {
        echo json_encode(['success' => false, 'message' => '缺少订单ID']);
        return;
    }
    
    $sql = "SELECT o.*, 
            tp.name as tutor_name, tp.contact_phone as tutor_phone, tp.education, tp.subjects as tutor_subjects,
            tp.user_id as tutor_user_id,
            p.username as parent_name, p.email as parent_email, p.phone as parent_phone
            FROM orders o
            LEFT JOIN tutor_profiles tp ON o.tutor_id = tp.id
            LEFT JOIN simple_users p ON o.parent_id = p.id
            WHERE o.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => '订单不存在']);
        return;
    }
    
    // 验证权限（只有订单相关方可以查看）
    if ($user_id && $order['parent_id'] != $user_id && $order['tutor_user_id'] != $user_id) {
        // 检查是否管理员
        $admin_check = $conn->prepare("SELECT is_admin FROM simple_users WHERE id = ? AND is_admin = 1");
        $admin_check->bind_param("i", $user_id);
        $admin_check->execute();
        if ($admin_check->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => '无权查看此订单']);
            return;
        }
    }
    
    // 获取支付记录
    $payment_stmt = $conn->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC");
    $payment_stmt->bind_param("i", $order_id);
    $payment_stmt->execute();
    $payments = [];
    while ($p = $payment_stmt->get_result()->fetch_assoc()) {
        $payments[] = $p;
    }
    $order['payments'] = $payments;
    
    echo json_encode(['success' => true, 'data' => $order]);
}

// 更新订单状态
function updateOrderStatus($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $order_id = $input['order_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    $status = $input['status'] ?? '';
    
    $valid_statuses = ['accepted', 'rejected', 'ongoing', 'completed', 'cancelled'];
    
    if (!$order_id || !$user_id || !in_array($status, $valid_statuses)) {
        echo json_encode(['success' => false, 'message' => '参数不完整或状态无效']);
        return;
    }
    
    // 获取订单信息
    $order_stmt = $conn->prepare("SELECT o.*, tp.user_id as tutor_user_id 
                                  FROM orders o 
                                  LEFT JOIN tutor_profiles tp ON o.tutor_id = tp.id 
                                  WHERE o.id = ?");
    $order_stmt->bind_param("i", $order_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => '订单不存在']);
        return;
    }
    
    // 验证权限
    $is_parent = $order['parent_id'] == $user_id;
    $is_tutor = $order['tutor_user_id'] == $user_id;
    
    if (!$is_parent && !$is_tutor) {
        echo json_encode(['success' => false, 'message' => '无权操作此订单']);
        return;
    }
    
    // 状态流转验证
    $current = $order['status'];
    $allowed = [
        'pending' => ['accepted', 'rejected', 'cancelled'],
        'accepted' => ['ongoing', 'cancelled'],
        'ongoing' => ['completed', 'cancelled'],
    ];
    
    if (!isset($allowed[$current]) || !in_array($status, $allowed[$current])) {
        echo json_encode(['success' => false, 'message' => '当前状态不允许此操作']);
        return;
    }
    
    // 家教只能 accept/reject，家长只能 cancel
    if ($status === 'accepted' || $status === 'rejected') {
        if (!$is_tutor) {
            echo json_encode(['success' => false, 'message' => '只有家教可以接受/拒绝订单']);
            return;
        }
    }
    
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $order_id);
    
    if ($stmt->execute()) {
        // 发送通知
        $notify_user = $is_parent ? $order['tutor_user_id'] : $order['parent_id'];
        $status_text = [
            'accepted' => '已接受',
            'rejected' => '已拒绝',
            'ongoing' => '进行中',
            'completed' => '已完成',
            'cancelled' => '已取消'
        ];
        
        $notify = $conn->prepare("INSERT INTO notifications (user_id, title, content, type, related_id) VALUES (?, ?, ?, 'order', ?)");
        $title = '订单状态更新';
        $content = '订单 #' . $order['order_no'] . ' 状态已更新为：' . $status_text[$status];
        $notify->bind_param("issi", $notify_user, $title, $content, $order_id);
        $notify->execute();
        
        echo json_encode([
            'success' => true,
            'message' => '订单状态已更新为：' . $status_text[$status]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失败']);
    }
}

// 取消订单
function cancelOrder($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $order_id = $input['order_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    $reason = trim($input['reason'] ?? '');
    
    if (!$order_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 获取订单
    $order_stmt = $conn->prepare("SELECT o.*, tp.user_id as tutor_user_id 
                                  FROM orders o 
                                  LEFT JOIN tutor_profiles tp ON o.tutor_id = tp.id 
                                  WHERE o.id = ?");
    $order_stmt->bind_param("i", $order_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => '订单不存在']);
        return;
    }
    
    // 验证权限
    if ($order['parent_id'] != $user_id && $order['tutor_user_id'] != $user_id) {
        echo json_encode(['success' => false, 'message' => '无权操作']);
        return;
    }
    
    // 只有pending和accepted状态可以取消
    if (!in_array($order['status'], ['pending', 'accepted'])) {
        echo json_encode(['success' => false, 'message' => '当前状态不可取消']);
        return;
    }
    
    $stmt = $conn->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = ? WHERE id = ?");
    $stmt->bind_param("si", $reason, $order_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '订单已取消']);
    } else {
        echo json_encode(['success' => false, 'message' => '取消失败']);
    }
}

// 完成订单
function completeOrder($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $order_id = $input['order_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    $total_hours = floatval($input['total_hours'] ?? 0);
    
    if (!$order_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 获取订单
    $order_stmt = $conn->prepare("SELECT o.*, tp.user_id as tutor_user_id 
                                  FROM orders o 
                                  LEFT JOIN tutor_profiles tp ON o.tutor_id = tp.id 
                                  WHERE o.id = ?");
    $order_stmt->bind_param("i", $order_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => '订单不存在']);
        return;
    }
    
    if ($order['status'] !== 'ongoing') {
        echo json_encode(['success' => false, 'message' => '只有进行中的订单可以完成']);
        return;
    }
    
    $is_parent = $order['parent_id'] == $user_id;
    $is_tutor = $order['tutor_user_id'] == $user_id;
    
    if (!$is_parent && !$is_tutor) {
        echo json_encode(['success' => false, 'message' => '无权操作']);
        return;
    }
    
    // 更新确认状态
    if ($is_parent) {
        $stmt = $conn->prepare("UPDATE orders SET parent_confirmed = 1 WHERE id = ?");
    } else {
        $stmt = $conn->prepare("UPDATE orders SET tutor_confirmed = 1 WHERE id = ?");
    }
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    
    // 检查是否双方都确认了
    $check = $conn->prepare("SELECT parent_confirmed, tutor_confirmed FROM orders WHERE id = ?");
    $check->bind_param("i", $order_id);
    $check->execute();
    $confirms = $check->get_result()->fetch_assoc();
    
    if ($confirms['parent_confirmed'] && $confirms['tutor_confirmed']) {
        // 双方确认，完成订单
        $total_amount = $total_hours * $order['price_per_hour'];
        $complete = $conn->prepare("UPDATE orders SET status = 'completed', total_hours = ?, total_amount = ?, end_date = CURDATE() WHERE id = ?");
        $complete->bind_param("ddi", $total_hours, $total_amount, $order_id);
        $complete->execute();
        
        echo json_encode([
            'success' => true,
            'message' => '订单已完成',
            'completed' => true
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => '已确认，等待对方确认',
            'completed' => false
        ]);
    }
}

// 获取订单统计
function getOrderStatistics($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    $stats = [
        'as_parent' => [],
        'as_tutor' => []
    ];
    
    $statuses = ['pending', 'accepted', 'ongoing', 'completed', 'cancelled'];
    
    // 作为家长的订单统计
    foreach ($statuses as $s) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM orders WHERE parent_id = ? AND status = ?");
        $stmt->bind_param("is", $user_id, $s);
        $stmt->execute();
        $stats['as_parent'][$s] = $stmt->get_result()->fetch_assoc()['count'];
    }
    
    // 作为家教的订单统计
    foreach ($statuses as $s) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM orders o 
                                JOIN tutor_profiles tp ON o.tutor_id = tp.id 
                                WHERE tp.user_id = ? AND o.status = ?");
        $stmt->bind_param("is", $user_id, $s);
        $stmt->execute();
        $stats['as_tutor'][$s] = $stmt->get_result()->fetch_assoc()['count'];
    }
    
    // 总收入（作为家教）
    $income = $conn->prepare("SELECT COALESCE(SUM(o.total_amount), 0) as total FROM orders o 
                              JOIN tutor_profiles tp ON o.tutor_id = tp.id 
                              WHERE tp.user_id = ? AND o.status = 'completed'");
    $income->bind_param("i", $user_id);
    $income->execute();
    $stats['total_income'] = $income->get_result()->fetch_assoc()['total'];
    
    // 总支出（作为家长）
    $expense = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE parent_id = ? AND status = 'completed'");
    $expense->bind_param("i", $user_id);
    $expense->execute();
    $stats['total_expense'] = $expense->get_result()->fetch_assoc()['total'];
    
    echo json_encode(['success' => true, 'data' => $stats]);
}
?>
