<?php
/**
 * 评价系统API
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
        case 'add':
            addReview($conn);
            break;
        case 'get_tutor_reviews':
            getTutorReviews($conn);
            break;
        case 'get_my_reviews':
            getMyReviews($conn);
            break;
        case 'reply':
            replyReview($conn);
            break;
        case 'delete':
            deleteReview($conn);
            break;
        case 'get_statistics':
            getReviewStatistics($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 添加评价
function addReview($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $reviewer_id = $input['reviewer_id'] ?? 0;
    $tutor_id = $input['tutor_id'] ?? 0;
    $order_id = $input['order_id'] ?? null;
    $rating = intval($input['rating'] ?? 0);
    $content = trim($input['content'] ?? '');
    $tags = $input['tags'] ?? '';
    $is_anonymous = $input['is_anonymous'] ?? 0;
    
    if (!$reviewer_id || !$tutor_id || $rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => '参数不完整或评分无效（1-5分）']);
        return;
    }
    
    // 检查家教是否存在
    $check = $conn->prepare("SELECT id, user_id FROM tutor_profiles WHERE id = ?");
    $check->bind_param("i", $tutor_id);
    $check->execute();
    $tutor = $check->get_result()->fetch_assoc();
    
    if (!$tutor) {
        echo json_encode(['success' => false, 'message' => '家教不存在']);
        return;
    }
    
    // 不能给自己评价
    if ($tutor['user_id'] == $reviewer_id) {
        echo json_encode(['success' => false, 'message' => '不能给自己评价']);
        return;
    }
    
    // 检查是否已评价过（同一订单只能评价一次）
    if ($order_id) {
        $exists = $conn->prepare("SELECT id FROM reviews WHERE reviewer_id = ? AND tutor_id = ? AND order_id = ?");
        $exists->bind_param("iii", $reviewer_id, $tutor_id, $order_id);
        $exists->execute();
        if ($exists->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => '该订单已评价过']);
            return;
        }
    }
    
    // 插入评价
    $stmt = $conn->prepare("INSERT INTO reviews (reviewer_id, tutor_id, order_id, rating, content, tags, is_anonymous) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiissi", $reviewer_id, $tutor_id, $order_id, $rating, $content, $tags, $is_anonymous);
    
    if ($stmt->execute()) {
        // 更新家教的平均评分
        updateTutorRating($conn, $tutor_id);
        
        echo json_encode([
            'success' => true,
            'message' => '评价成功',
            'review_id' => $conn->insert_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '评价失败']);
    }
}

// 更新家教评分
function updateTutorRating($conn, $tutor_id) {
    $stmt = $conn->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as count FROM reviews WHERE tutor_id = ? AND status = 'active'");
    $stmt->bind_param("i", $tutor_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    $avg_rating = round($result['avg_rating'], 1);
    $count = $result['count'];
    
    $update = $conn->prepare("UPDATE tutor_profiles SET rating = ?, rating_count = ? WHERE id = ?");
    $update->bind_param("dii", $avg_rating, $count, $tutor_id);
    $update->execute();
}

// 获取家教的评价列表
function getTutorReviews($conn) {
    $tutor_id = $_GET['tutor_id'] ?? 0;
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    if (!$tutor_id) {
        echo json_encode(['success' => false, 'message' => '缺少家教ID']);
        return;
    }
    
    $sql = "SELECT r.*, 
            CASE WHEN r.is_anonymous = 1 THEN '匿名用户' ELSE u.username END as reviewer_name
            FROM reviews r
            LEFT JOIN simple_users u ON r.reviewer_id = u.id
            WHERE r.tutor_id = ? AND r.status = 'active'
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $tutor_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reviews = [];
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    
    // 获取总数
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM reviews WHERE tutor_id = ? AND status = 'active'");
    $count_stmt->bind_param("i", $tutor_id);
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $reviews,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

// 获取我的评价（我评价的或评价我的）
function getMyReviews($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    $type = $_GET['type'] ?? 'given'; // given（我评价的）或 received（评价我的）
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    if ($type === 'received') {
        // 获取评价我的（我是家教）
        $sql = "SELECT r.*, 
                CASE WHEN r.is_anonymous = 1 THEN '匿名用户' ELSE u.username END as reviewer_name,
                tp.name as tutor_name
                FROM reviews r
                LEFT JOIN simple_users u ON r.reviewer_id = u.id
                LEFT JOIN tutor_profiles tp ON r.tutor_id = tp.id
                WHERE tp.user_id = ? AND r.status = 'active'
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?";
    } else {
        // 获取我评价的
        $sql = "SELECT r.*, tp.name as tutor_name
                FROM reviews r
                LEFT JOIN tutor_profiles tp ON r.tutor_id = tp.id
                WHERE r.reviewer_id = ? AND r.status = 'active'
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $user_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reviews = [];
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $reviews,
        'type' => $type
    ]);
}

// 家教回复评价
function replyReview($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $review_id = $input['review_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    $reply = trim($input['reply'] ?? '');
    
    if (!$review_id || !$user_id || empty($reply)) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 验证是否是该评价的家教
    $check = $conn->prepare("SELECT r.id FROM reviews r 
                             JOIN tutor_profiles tp ON r.tutor_id = tp.id 
                             WHERE r.id = ? AND tp.user_id = ?");
    $check->bind_param("ii", $review_id, $user_id);
    $check->execute();
    
    if ($check->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '无权回复此评价']);
        return;
    }
    
    $stmt = $conn->prepare("UPDATE reviews SET reply = ?, reply_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $reply, $review_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '回复成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '回复失败']);
    }
}

// 删除评价
function deleteReview($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $review_id = $input['review_id'] ?? 0;
    $user_id = $input['user_id'] ?? 0;
    
    if (!$review_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 只能删除自己的评价
    $check = $conn->prepare("SELECT tutor_id FROM reviews WHERE id = ? AND reviewer_id = ?");
    $check->bind_param("ii", $review_id, $user_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '无权删除此评价']);
        return;
    }
    
    $tutor_id = $result->fetch_assoc()['tutor_id'];
    
    $stmt = $conn->prepare("UPDATE reviews SET status = 'hidden' WHERE id = ?");
    $stmt->bind_param("i", $review_id);
    
    if ($stmt->execute()) {
        // 更新家教评分
        updateTutorRating($conn, $tutor_id);
        echo json_encode(['success' => true, 'message' => '删除成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败']);
    }
}

// 获取评价统计
function getReviewStatistics($conn) {
    $tutor_id = $_GET['tutor_id'] ?? 0;
    
    if (!$tutor_id) {
        echo json_encode(['success' => false, 'message' => '缺少家教ID']);
        return;
    }
    
    // 获取评分分布
    $distribution = [];
    for ($i = 5; $i >= 1; $i--) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM reviews WHERE tutor_id = ? AND rating = ? AND status = 'active'");
        $stmt->bind_param("ii", $tutor_id, $i);
        $stmt->execute();
        $distribution[$i] = $stmt->get_result()->fetch_assoc()['count'];
    }
    
    // 获取平均分和总数
    $stmt = $conn->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE tutor_id = ? AND status = 'active'");
    $stmt->bind_param("i", $tutor_id);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    
    // 获取常用标签
    $stmt = $conn->prepare("SELECT tags FROM reviews WHERE tutor_id = ? AND status = 'active' AND tags IS NOT NULL AND tags != ''");
    $stmt->bind_param("i", $tutor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tag_counts = [];
    while ($row = $result->fetch_assoc()) {
        $tags = explode(',', $row['tags']);
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag) {
                $tag_counts[$tag] = ($tag_counts[$tag] ?? 0) + 1;
            }
        }
    }
    arsort($tag_counts);
    $top_tags = array_slice($tag_counts, 0, 10, true);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'average_rating' => round($stats['avg_rating'] ?? 0, 1),
            'total_reviews' => $stats['total'] ?? 0,
            'distribution' => $distribution,
            'top_tags' => $top_tags
        ]
    ]);
}
?>
