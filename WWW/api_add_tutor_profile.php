<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '只允许POST请求'
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
    // 获取POST数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('无效的JSON数据');
    }
    
    // 验证必填字段
    $required_fields = ['user_id', 'name', 'education', 'subjects', 'experience', 'teaching_areas', 'schedule', 'salary', 'contact_phone'];
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            throw new Exception("字段 {$field} 不能为空");
        }
    }
    
    // 验证用户ID是否存在
    $user_check = $conn->prepare("SELECT id FROM simple_users WHERE id = ?");
    $user_check->bind_param("i", $input['user_id']);
    $user_check->execute();
    $user_result = $user_check->get_result();
    
    if ($user_result->num_rows === 0) {
        throw new Exception('用户不存在');
    }
    
    // 插入家教老师信息数据
    $stmt = $conn->prepare("INSERT INTO tutor_profiles 
        (user_id, name, education, subjects, experience, teaching_areas, schedule, salary, teaching_style, contact_phone) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("isssssssss", 
        $input['user_id'],
        $input['name'],
        $input['education'],
        $input['subjects'],
        $input['experience'],
        $input['teaching_areas'],
        $input['schedule'],
        $input['salary'],
        $input['teaching_style'],
        $input['contact_phone']
    );
    
    if (!$stmt->execute()) {
        throw new Exception('数据库插入失败: ' . $stmt->error);
    }
    
    $profile_id = $conn->insert_id;
    
    echo json_encode([
        'success' => true,
        'message' => '家教老师信息发布成功',
        'data' => [
            'profile_id' => $profile_id,
            'name' => $input['name'],
            'subjects' => $input['subjects'],
            'teaching_areas' => $input['teaching_areas'],
            'created_at' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>