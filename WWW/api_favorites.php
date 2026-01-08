<?php
/**
 * 收藏功能API
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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

// 确保favorites表存在
$table_check = $conn->query("SHOW TABLES LIKE 'favorites'");
if ($table_check->num_rows === 0) {
    $create_sql = "CREATE TABLE favorites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        target_type ENUM('tutor', 'request') NOT NULL COMMENT '收藏类型',
        target_id INT NOT NULL COMMENT '收藏目标ID',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_favorite (user_id, target_type, target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($create_sql);
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'add':
            addFavorite($conn);
            break;
        case 'remove':
            removeFavorite($conn);
            break;
        case 'remove_by_item':
            removeByItem($conn);
            break;
        case 'get_list':
            getFavoriteList($conn);
            break;
        case 'check':
            checkFavorite($conn);
            break;
        case 'toggle':
            toggleFavorite($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 添加收藏
function addFavorite($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $input['user_id'] ?? 0;
    $target_type = $input['target_type'] ?? $input['type'] ?? ''; // tutor 或 request
    $target_id = $input['target_id'] ?? $input['item_id'] ?? 0;
    
    if (!$user_id || !$target_type || !$target_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    if (!in_array($target_type, ['tutor', 'request'])) {
        echo json_encode(['success' => false, 'message' => '无效的收藏类型']);
        return;
    }
    
    // 检查目标是否存在
    $table = $target_type === 'tutor' ? 'tutor_profiles' : 'tutoring_requests';
    $check = $conn->prepare("SELECT id FROM $table WHERE id = ?");
    $check->bind_param("i", $target_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '收藏目标不存在']);
        return;
    }
    
    // 检查是否已收藏
    $exists = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND target_type = ? AND target_id = ?");
    $exists->bind_param("isi", $user_id, $target_type, $target_id);
    $exists->execute();
    if ($exists->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => '已经收藏过了']);
        return;
    }
    
    $stmt = $conn->prepare("INSERT INTO favorites (user_id, target_type, target_id) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $user_id, $target_type, $target_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => '收藏成功',
            'favorite_id' => $conn->insert_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '收藏失败']);
    }
}

// 取消收藏
function removeFavorite($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $input['user_id'] ?? 0;
    $target_type = $input['target_type'] ?? $input['type'] ?? '';
    $target_id = $input['target_id'] ?? $input['item_id'] ?? 0;
    $favorite_id = $input['favorite_id'] ?? 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    if ($favorite_id) {
        // 通过收藏ID删除
        $stmt = $conn->prepare("DELETE FROM favorites WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $favorite_id, $user_id);
    } else if ($target_type && $target_id) {
        // 通过目标删除
        $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND target_type = ? AND target_id = ?");
        $stmt->bind_param("isi", $user_id, $target_type, $target_id);
    } else {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => '取消收藏成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '取消失败']);
    }
}

// 通过类型和item_id取消收藏
function removeByItem($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $input['user_id'] ?? 0;
    $target_type = $input['type'] ?? '';
    $target_id = $input['item_id'] ?? 0;
    
    if (!$user_id || !$target_type || !$target_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND target_type = ? AND target_id = ?");
    $stmt->bind_param("isi", $user_id, $target_type, $target_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => '取消收藏成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '未找到该收藏']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => '取消失败']);
    }
}

// 获取收藏列表
function getFavoriteList($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    $type = $_GET['type'] ?? 'all'; // all, tutor, request
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => '缺少用户ID']);
        return;
    }
    
    $where = "f.user_id = ?";
    $params = [$user_id];
    $types = "i";
    
    if ($type === 'tutor') {
        $where .= " AND f.target_type = 'tutor'";
    } elseif ($type === 'request') {
        $where .= " AND f.target_type = 'request'";
    }
    
    // 先获取收藏记录
    $sql = "SELECT f.* FROM favorites f WHERE $where ORDER BY f.created_at DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $favorites = [];
    while ($row = $result->fetch_assoc()) {
        $item = [
            'id' => $row['id'],
            'type' => $row['target_type'],
            'target_id' => $row['target_id'],
            'created_at' => $row['created_at']
        ];
        
        // 获取收藏目标的详细信息
        if ($row['target_type'] === 'tutor') {
            $detail = $conn->prepare("SELECT id, name, subjects, education, salary, teaching_areas FROM tutor_profiles WHERE id = ?");
            $detail->bind_param("i", $row['target_id']);
            $detail->execute();
            $target = $detail->get_result()->fetch_assoc();
            if ($target) {
                $item['title'] = $target['name'] . ' - ' . $target['subjects'];
                $item['description'] = $target['education'] . ' | ' . $target['salary'] . ' | ' . $target['teaching_areas'];
                $item['detail'] = $target;
            }
        } else {
            $detail = $conn->prepare("SELECT id, student_name, grade, subjects, location, salary FROM tutoring_requests WHERE id = ?");
            $detail->bind_param("i", $row['target_id']);
            $detail->execute();
            $target = $detail->get_result()->fetch_assoc();
            if ($target) {
                $item['title'] = $target['student_name'] . '(' . $target['grade'] . ') - ' . $target['subjects'];
                $item['description'] = $target['location'] . ' | ' . $target['salary'];
                $item['detail'] = $target;
            }
        }
        
        if (isset($item['title'])) {
            $favorites[] = $item;
        }
    }
    
    // 获取总数
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM favorites WHERE user_id = ?");
    $count_stmt->bind_param("i", $user_id);
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $favorites,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

// 检查是否已收藏
function checkFavorite($conn) {
    $user_id = $_GET['user_id'] ?? 0;
    $target_type = $_GET['target_type'] ?? $_GET['type'] ?? '';
    $target_id = $_GET['target_id'] ?? $_GET['item_id'] ?? 0;
    
    if (!$user_id || !$target_type || !$target_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND target_type = ? AND target_id = ?");
    $stmt->bind_param("isi", $user_id, $target_type, $target_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo json_encode([
        'success' => true,
        'is_favorited' => $result->num_rows > 0,
        'favorite_id' => $result->num_rows > 0 ? $result->fetch_assoc()['id'] : null
    ]);
}

// 切换收藏状态
function toggleFavorite($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $input['user_id'] ?? 0;
    $target_type = $input['target_type'] ?? '';
    $target_id = $input['target_id'] ?? 0;
    
    if (!$user_id || !$target_type || !$target_id) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }
    
    // 检查是否已收藏
    $check = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND target_type = ? AND target_id = ?");
    $check->bind_param("isi", $user_id, $target_type, $target_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        // 已收藏，取消
        $favorite_id = $result->fetch_assoc()['id'];
        $stmt = $conn->prepare("DELETE FROM favorites WHERE id = ?");
        $stmt->bind_param("i", $favorite_id);
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => '已取消收藏',
                'is_favorited' => false
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '操作失败']);
        }
    } else {
        // 未收藏，添加
        $stmt = $conn->prepare("INSERT INTO favorites (user_id, target_type, target_id) VALUES (?, ?, ?)");
        $stmt->bind_param("isi", $user_id, $target_type, $target_id);
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => '收藏成功',
                'is_favorited' => true,
                'favorite_id' => $conn->insert_id
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '操作失败']);
        }
    }
}
?>
