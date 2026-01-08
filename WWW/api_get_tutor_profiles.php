<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 只允许GET请求
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '只允许GET请求'
    ]);
    exit;
}

require_once 'config.php';

// 创建数据库连接
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库连接失败: ' . $conn->connect_error
    ]);
    exit;
}

try {
    // 获取查询参数
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $subject = isset($_GET['subject']) ? trim($_GET['subject']) : '';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    
    // 构建查询条件
    $where_conditions = ["tp.status = 'active'"];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where_conditions[] = "(tp.name LIKE ? OR tp.education LIKE ? OR tp.teaching_areas LIKE ? OR tp.teaching_style LIKE ?)";
        $search_param = "%{$search}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ssss';
    }
    
    if (!empty($subject)) {
        $where_conditions[] = "tp.subjects LIKE ?";
        $params[] = "%{$subject}%";
        $types .= 's';
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // 查询家教老师信息
    $query = "SELECT tp.*, u.username as user_name 
              FROM tutor_profiles tp 
              LEFT JOIN simple_users u ON tp.user_id = u.id 
              {$where_clause} 
              ORDER BY tp.created_at DESC 
              LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset]));
    } else {
        $stmt->bind_param('ii', $limit, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $profiles = [];
    while ($row = $result->fetch_assoc()) {
        // 对于家教老师信息，显示完整联系电话供家长联系
        // 如果需要隐私保护，可以在这里添加登录验证逻辑
        $phone = $row['contact_phone'];
        
        $profiles[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'education' => $row['education'],
            'subjects' => $row['subjects'],
            'experience' => $row['experience'],
            'teaching_areas' => $row['teaching_areas'],
            'schedule' => $row['schedule'],
            'salary' => $row['salary'],
            'teaching_style' => $row['teaching_style'],
            'contact_phone' => $phone, // 显示完整电话号码供家长联系
            'user_name' => $row['user_name'] ?: '匿名用户',
            'created_at' => $row['created_at']
        ];
    }
    
    // 获取总数
    $count_query = "SELECT COUNT(*) as total 
                    FROM tutor_profiles tp 
                    {$where_clause}";
    
    $count_stmt = $conn->prepare($count_query);
    if (!empty($params) && !empty($types)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'profiles' => $profiles,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>