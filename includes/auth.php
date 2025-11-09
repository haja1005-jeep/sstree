<?php
/**
 * 인증 관련 함수
 * Smart Tree Map - Sinan County
 */

require_once __DIR__ . '/../config/config.php';

// 로그인 확인
function checkAuth() {
    if (!isLoggedIn()) {
        redirect('/admin/login.php');
    }
}

// 관리자 권한 확인
function checkAdmin() {
    checkAuth();
    if (!isAdmin()) {
        $_SESSION['error'] = '관리자 권한이 필요합니다.';
        redirect('/admin/index.php');
    }
}

// 사용자 로그인 처리
function loginUser($username, $password) {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT user_id, username, email, password, role, name 
              FROM users 
              WHERE username = :username AND status = 'active'";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch();
        
        if (password_verify($password, $user['password'])) {
            // 세션 설정
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['name'];
            
            // 마지막 로그인 시간 업데이트
            $updateQuery = "UPDATE users SET last_login = NOW() WHERE user_id = :user_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':user_id', $user['user_id']);
            $updateStmt->execute();
            
            // 로그 기록
            logActivity($user['user_id'], 'login', 'user', $user['user_id'], '로그인 성공');
            
            return true;
        }
    }
    
    return false;
}

// 로그아웃
function logoutUser() {
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'logout', 'user', $_SESSION['user_id'], '로그아웃');
    }
    
    session_destroy();
    redirect('/admin/login.php');
}

// 활동 로그 기록
function logActivity($userId, $action, $targetType, $targetId, $details) {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "INSERT INTO activity_logs (user_id, action, target_type, target_id, details, ip_address) 
              VALUES (:user_id, :action, :target_type, :target_id, :details, :ip_address)";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId);
    $stmt->bindParam(':action', $action);
    $stmt->bindParam(':target_type', $targetType);
    $stmt->bindParam(':target_id', $targetId);
    $stmt->bindParam(':details', $details);
    
    $ipAddress = $_SERVER['REMOTE_ADDR'];
    $stmt->bindParam(':ip_address', $ipAddress);
    
    $stmt->execute();
}

// 비밀번호 해시 생성
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}
?>
