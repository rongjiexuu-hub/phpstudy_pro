<?php
/**
 * 消息系统API
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

// 确保messages表存在
$table_check = $conn->query("SHOW TABLES LIKE 'messages'");
if ($table_check->num_rows === 0) {
    $create_sql = "CREATE TABLE messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        subject VARCHAR(200) DEFAULT NULL,
        content TEXT NOT NULL,
        message_type ENUM('private', 'system', 'notification') DEFAULT 'private',
        related_type ENUM('tutor', 'request', 'order', 'general') DEFAULT 'general',
        related_id INT DEFAULT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($create_sql);
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_list':
            getMessageList($conn);
            break;
        case 'get_detail':
            getMessageDetail($conn);
            break;
        case 'send':
            sendMessage($conn);
            break;
        case 'mark_read':
            markAsRead($conn);
            break;
        case 'mark_all_read':
            markAllAsRead($conn);
            break;
        case 'delete':
            deleteMessage($conn);
            break;
        case 'get_unread_count':
            getUnreadCount($conn);
            break;
        case 'get_conversations':
            getConversations($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 获取消息列表
function getMessageList($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    $type = $_GET['type'] ?? 'all'; // all, private, system
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    $where = "receiver_id = ?";
    $params = [$user_id];
    $types = "i";
    
    if ($type === 'private') {
        $where .= " AND message_type = 'private'";
    } elseif ($type === 'system') {
        $where .= " AND message_type IN ('system', 'notification')";
    }
    
    $sql = "SELECT m.*, u.username as sender_name 
            FROM messages m 
            LEFT JOIN simple_users u ON m.sender_id = u.id 
            WHERE $where 
            ORDER BY m.created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    // 获取总数
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM messages WHERE receiver_id = ?");
    $count_stmt->bind_param("i", $user_id);
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $messages,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

// 获取消息详情
function getMessageDetail($conn) {
    $message_id = $_GET['message_id'] ?? 0;
    $user_id = $_GET['user_id'] ?? 0;
    
    if (!$message_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    $stmt = $conn->prepare("SELECT m.*, u.username as sender_name 
                            FROM messages m 
                            LEFT JOIN simple_users u ON m.sender_id = u.id 
                            WHERE m.id = ? AND m.receiver_id = ?");
    $stmt->bind_param("ii", $message_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // 标记为已读
        $update = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
        $update->bind_param("i", $message_id);
        $update->execute();
        
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => '消息不存在']);
    }
}

// 发送消息
function sendMessage($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $sender_id = $input['sender_id'] ?? 0;
    $receiver_id = $input['receiver_id'] ?? 0;
    $subject = trim($input['subject'] ?? '');
    $content = trim($input['content'] ?? '');
    $message_type = $input['message_type'] ?? 'private';
    $related_type = $input['related_type'] ?? 'general';
    $related_id = $input['related_id'] ?? null;
    
    if (!$sender_id || !$receiver_id || empty($content)) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 验证接收者存在
    $check = $conn->prepare("SELECT id FROM simple_users WHERE id = ?");
    $check->bind_param("i", $receiver_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '接收者不存在']);
        return;
    }
    
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, subject, content, message_type, related_type, related_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iissssi", $sender_id, $receiver_id, $subject, $content, $message_type, $related_type, $related_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => '消息发送成功',
            'message_id' => $conn->insert_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '发送失败']);
    }
}

// 标记消息已读
function markAsRead($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $message_id = $input['message_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    
    if (!$message_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?");
    $stmt->bind_param("ii", $message_id, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '已标记为已读']);
    } else {
        echo json_encode(['success' => false, 'message' => '操作失败']);
    }
}

// 标记所有消息已读
function markAllAsRead($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $user_id = $input['user_id'] ?? 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => '已全部标记为已读',
            'affected' => $stmt->affected_rows
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '操作失败']);
    }
}

// 删除消息
function deleteMessage($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $message_id = $input['message_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    
    if (!$message_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    $stmt = $conn->prepare("DELETE FROM messages WHERE id = ? AND receiver_id = ?");
    $stmt->bind_param("ii", $message_id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => '删除成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败或无权限']);
    }
}

// 获取未读消息数量
function getUnreadCount($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['count'];
    
    echo json_encode(['success' => true, 'count' => $count]);
}

// 获取会话列表
function getConversations($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    // 获取与每个用户的最新消息
    $sql = "SELECT 
                CASE 
                    WHEN sender_id = ? THEN receiver_id 
                    ELSE sender_id 
                END as other_user_id,
                MAX(created_at) as last_message_time
            FROM messages 
            WHERE sender_id = ? OR receiver_id = ?
            GROUP BY other_user_id
            ORDER BY last_message_time DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $conversations = [];
    while ($row = $result->fetch_assoc()) {
        $other_id = $row['other_user_id'];
        
        // 获取对方用户信息
        $user_stmt = $conn->prepare("SELECT id, username, avatar FROM simple_users WHERE id = ?");
        $user_stmt->bind_param("i", $other_id);
        $user_stmt->execute();
        $user_info = $user_stmt->get_result()->fetch_assoc();
        
        // 获取最新消息
        $msg_stmt = $conn->prepare("SELECT content, is_read, sender_id FROM messages 
                                    WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
                                    ORDER BY created_at DESC LIMIT 1");
        $msg_stmt->bind_param("iiii", $user_id, $other_id, $other_id, $user_id);
        $msg_stmt->execute();
        $last_msg = $msg_stmt->get_result()->fetch_assoc();
        
        // 获取未读数
        $unread_stmt = $conn->prepare("SELECT COUNT(*) as count FROM messages 
                                       WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $unread_stmt->bind_param("ii", $other_id, $user_id);
        $unread_stmt->execute();
        $unread = $unread_stmt->get_result()->fetch_assoc()['count'];
        
        $conversations[] = [
            'user' => $user_info,
            'last_message' => $last_msg['content'],
            'last_time' => $row['last_message_time'],
            'unread_count' => $unread,
            'is_mine' => $last_msg['sender_id'] == $user_id
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $conversations]);
}

// 发送系统通知（内部函数）
function sendSystemNotification($conn, $user_id, $title, $content, $type = 'system', $related_id = null) {
    $system_user_id = 0; // 系统用户ID
    
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, content, type, related_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isssi", $user_id, $title, $content, $type, $related_id);
    return $stmt->execute();
}
?>
