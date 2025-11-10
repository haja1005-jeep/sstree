<?php
/**
 * 회원 추가
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();
checkAdmin();

$page_title = '회원 추가';

$database = new Database();
$db = $database->getConnection();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username']);
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];
    $role = sanitize($_POST['role']);
    $name = sanitize($_POST['name']);
    $phone = sanitize($_POST['phone']);
    $status = sanitize($_POST['status']);
    
    // 유효성 검사
    if (empty($username) || empty($email) || empty($password)) {
        $error = '필수 항목(아이디, 이메일, 비밀번호)을 모두 입력해주세요.';
    } elseif ($password !== $password_confirm) {
        $error = '비밀번호가 일치하지 않습니다.';
    } elseif (strlen($password) < 6) {
        $error = '비밀번호는 최소 6자 이상이어야 합니다.';
    } else {
        // 중복 확인
        $checkQuery = "SELECT COUNT(*) as count FROM users WHERE username = :username OR email = :email";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':username', $username);
        $checkStmt->bindParam(':email', $email);
        $checkStmt->execute();
        $result = $checkStmt->fetch();
        
        if ($result['count'] > 0) {
            $error = '이미 사용중인 아이디 또는 이메일입니다.';
        } else {
            // 비밀번호 해시화
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $query = "INSERT INTO users (username, email, password, role, name, phone, status, created_at) 
                     VALUES (:username, :email, :password, :role, :name, :phone, :status, NOW())";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $password_hash);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':status', $status);
            
            if ($stmt->execute()) {
                $new_user_id = $db->lastInsertId();
                logActivity($_SESSION['user_id'], 'create', 'user', $new_user_id, "회원 추가: {$username}");
                redirect('/admin/users/list.php?message=' . urlencode('회원이 추가되었습니다.'));
            } else {
                $error = '회원 추가 중 오류가 발생했습니다.';
            }
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>회원 추가</h2>
    <a href="list.php" class="btn btn-secondary">← 목록으로</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" action="">
        <h3 style="margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0;">계정 정보</h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label for="username">아이디 <span style="color: red;">*</span></label>
                <input type="text" id="username" name="username" required 
                       placeholder="영문, 숫자 조합 (4-20자)" 
                       pattern="[a-zA-Z0-9]{4,20}"
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="email">이메일 <span style="color: red;">*</span></label>
                <input type="email" id="email" name="email" required 
                       placeholder="example@sinan.go.kr" 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label for="password">비밀번호 <span style="color: red;">*</span></label>
                <input type="password" id="password" name="password" required 
                       placeholder="최소 6자 이상" 
                       minlength="6">
                <small style="color: #6b7280; font-size: 13px;">영문, 숫자 조합 권장</small>
            </div>
            
            <div class="form-group">
                <label for="password_confirm">비밀번호 확인 <span style="color: red;">*</span></label>
                <input type="password" id="password_confirm" name="password_confirm" required 
                       placeholder="비밀번호 재입력" 
                       minlength="6">
            </div>
        </div>
        
        <h3 style="margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0;">개인 정보</h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label for="name">이름</label>
                <input type="text" id="name" name="name" 
                       placeholder="홍길동" 
                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="phone">연락처</label>
                <input type="tel" id="phone" name="phone" 
                       placeholder="010-1234-5678" 
                       pattern="[0-9]{2,3}-[0-9]{3,4}-[0-9]{4}"
                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
            </div>
        </div>
        
        <h3 style="margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0;">권한 설정</h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label for="role">권한 <span style="color: red;">*</span></label>
                <select id="role" name="role" required>
                    <option value="field_worker" <?php echo (!isset($_POST['role']) || $_POST['role'] == 'field_worker') ? 'selected' : ''; ?>>현장직원</option>
                    <option value="manager" <?php echo (isset($_POST['role']) && $_POST['role'] == 'manager') ? 'selected' : ''; ?>>매니저</option>
                    <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] == 'admin') ? 'selected' : ''; ?>>관리자</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="status">상태 <span style="color: red;">*</span></label>
                <select id="status" name="status" required>
                    <option value="active" <?php echo (!isset($_POST['status']) || $_POST['status'] == 'active') ? 'selected' : ''; ?>>활성</option>
                    <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] == 'inactive') ? 'selected' : ''; ?>>비활성</option>
                </select>
            </div>
        </div>
        
        <div style="background: #f9fafb; padding: 15px; border-radius: 8px; margin-top: 20px;">
            <strong>권한 설명:</strong>
            <ul style="margin-top: 10px; padding-left: 20px; color: #6b7280;">
                <li><strong>관리자:</strong> 모든 기능 접근 및 회원 관리 가능</li>
                <li><strong>매니저:</strong> 데이터 관리 가능 (회원 관리 제외)</li>
                <li><strong>현장직원:</strong> 나무 데이터 등록 및 사진 업로드만 가능</li>
            </ul>
        </div>
        
        <div style="display: flex; gap: 10px; margin-top: 30px;">
            <button type="submit" class="btn btn-primary">저장</button>
            <a href="list.php" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>

<script>
// 비밀번호 확인 검증
document.querySelector('form').addEventListener('submit', function(e) {
    const password = document.getElementById('password').value;
    const passwordConfirm = document.getElementById('password_confirm').value;
    
    if (password !== passwordConfirm) {
        e.preventDefault();
        alert('비밀번호가 일치하지 않습니다.');
        document.getElementById('password_confirm').focus();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>