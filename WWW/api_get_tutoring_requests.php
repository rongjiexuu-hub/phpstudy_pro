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
    $where_conditions = ["tr.status = 'active'"];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where_conditions[] = "(tr.student_name LIKE ? OR tr.location LIKE ? OR tr.requirements LIKE ?)";
        $search_param = "%{$search}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'sss';
    }
    
    if (!empty($subject)) {
        $where_conditions[] = "tr.subjects LIKE ?";
        $params[] = "%{$subject}%";
        $types .= 's';
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // 查询家教需求
    $query = "SELECT tr.*, u.username as parent_name 
              FROM tutoring_requests tr 
              LEFT JOIN simple_users u ON tr.user_id = u.id 
              {$where_clause} 
              ORDER BY tr.created_at DESC 
              LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset]));
    } else {
        $stmt->bind_param('ii', $limit, $offset);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        // 对于家教需求，显示完整联系电话供家教老师联系
        // 如果需要隐私保护，可以在这里添加登录验证逻辑
        $phone = $row['contact_phone'];
        
        $requests[] = [
            'id' => $row['id'],
            'student_name' => $row['student_name'],
            'grade' => $row['grade'],
            'subjects' => $row['subjects'],
            'location' => $row['location'],
            'schedule' => $row['schedule'],
            'salary' => $row['salary'],
            'requirements' => $row['requirements'],
            'contact_phone' => $phone, // 显示完整电话号码供家教老师联系
            'parent_name' => $row['parent_name'] ?: '匿名家长',
            'created_at' => $row['created_at']
        ];
    }
    
    // 获取总数
    $count_query = "SELECT COUNT(*) as total 
                    FROM tutoring_requests tr 
                    {$where_clause}";
    
    $count_stmt = $conn->prepare($count_query);
    if (!empty($params)) {
        // 移除limit和offset参数
        $count_params = array_slice($params, 0, -2);
        if (!empty($count_params)) {
            $count_types = substr($types, 0, -2);
            $count_stmt->bind_param($count_types, ...$count_params);
        }
    }
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'requests' => $requests,
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