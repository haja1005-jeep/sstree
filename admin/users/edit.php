<?php
/**
 * 회원 수정
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();
checkAdmin();

$page_title = '회원 수정';

$database = new Database();
$db = $database->getConnection();

$error = '';
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($user_id === 0) {
    redirect('/admin/users/list.php');
}

// 기존 데이터 조회
$query = "SELECT * FROM users WHERE user_id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$user = $stmt->fetch();

if (!$user) {
    redirect('/admin/users/list.php');
}

// 수정 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username']);
    $email = sanitize($_POST['email']);
    $role = sanitize($_POST['role']);
    $name = sanitize($_POST['name']);
    $phone = sanitize($_POST['phone']);
    $status = sanitize($_POST['status']);
    $new_password = $_POST['new_password'];
    $password_confirm = $_POST['password_confirm'];
    
    // 유효성 검사
    if (empty($username) || empty($email)) {
        $error = '아이디와 이메일은 필수 항목입니다.';
    } elseif (!empty($new_password) && $new_password !== $password_confirm) {
        $error = '새 비밀번호가 일치하지 않습니다.';
    } elseif (!empty($new_password) && strlen($new_password) < 6) {
        $error = '비밀번호는 최소 6자 이상이어야 합니다.';
    } else {
        // 중복 확인 (자기 자신 제외)
        $checkQuery = "SELECT COUNT(*) as count FROM users 
                      WHERE (username = :username OR email = :email) 
                      AND user_id != :user_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':username', $username);
        $checkStmt->bindParam(':email', $email);
        $checkStmt->bindParam(':user_id', $user_id);
        $checkStmt->execute();
        $result = $checkStmt->fetch();
        
        if ($result['count'] > 0) {
            $error = '이미 사용중인 아이디 또는 이메일입니다.';
        } else {
            // 비밀번호 업데이트 여부 확인
            if (!empty($new_password)) {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $updateQuery = "UPDATE users 
                               SET username = :username, email = :email, password = :password,
                                   role = :role, name = :name, phone = :phone, status = :status
                               WHERE user_id = :user_id";
            } else {
                $updateQuery = "UPDATE users 
                               SET username = :username, email = :email,
                                   role = :role, name = :name, phone = :phone, status = :status
                               WHERE user_id = :user_id";
            }
            
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':username', $username);
            $updateStmt->bindParam(':email', $email);
            if (!empty($new_password)) {
                $updateStmt->bindParam(':password', $password_hash);
            }
            $updateStmt->bindParam(':role', $role);
            $updateStmt->bindParam(':name', $name);
            $updateStmt->bindParam(':phone', $phone);
            $updateStmt->bindParam(':status', $status);
            $updateStmt->bindParam(':user_id', $user_id);
            
            if ($updateStmt->execute()) {
                logActivity($_SESSION['user_id'], 'update', 'user', $user_id, "회원 수정: {$username}");
                redirect('/admin/users/list.php?message=' . urlencode('회원 정보가 수정되었습니다.'));
            } else {
                $error = '회원 정보 수정 중 오류가 발생했습니다.';
            }
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>회원 수정</h2>
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
                       value="<?php echo htmlspecialchars($user['username']); ?>">
            </div>
            
            <div class="form-group">
                <label for="email">이메일 <span style="color: red;">*</span></label>
                <input type="email" id="email" name="email" required 
                       value="<?php echo htmlspecialchars($user['email']); ?>">
            </div>
        </div>
        
        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 8px; margin: 20px 0;">
            <strong>💡 비밀번호 변경</strong>
            <p style="margin-top: 5px; color: #92400e; font-size: 14px;">
                비밀번호를 변경하지 않으려면 아래 필드를 비워두세요.
            </p>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label for="new_password">새 비밀번호</label>
                <input type="password" id="new_password" name="new_password" 
                       placeholder="새 비밀번호 (변경시에만 입력)" 
                       minlength="6">
            </div>
            
            <div class="form-group">
                <label for="password_confirm">새 비밀번호 확인</label>
                <input type="password" id="password_confirm" name="password_confirm" 
                       placeholder="새 비밀번호 재입력" 
                       minlength="6">
            </div>
        </div>
        
        <h3 style="margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0;">개인 정보</h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label for="name">이름</label>
                <input type="text" id="name" name="name" 
                       value="<?php echo htmlspecialchars($user['name']); ?>">
            </div>
            
            <div class="form-group">
                <label for="phone">연락처</label>
                <input type="tel" id="phone" name="phone" 
                       pattern="[0-9]{2,3}-[0-9]{3,4}-[0-9]{4}"
                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">

            </div>
        </div>
        
        <h3 style="margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0;">권한 설정</h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label for="role">권한 <span style="color: red;">*</span></label>
                <select id="role" name="role" required 
                        <?php echo ($user_id == $_SESSION['user_id']) ? 'disabled' : ''; ?>>
                    <option value="field_worker" <?php echo ($user['role'] == 'field_worker') ? 'selected' : ''; ?>>현장직원</option>
                    <option value="manager" <?php echo ($user['role'] == 'manager') ? 'selected' : ''; ?>>매니저</option>
                    <option value="admin" <?php echo ($user['role'] == 'admin') ? 'selected' : ''; ?>>관리자</option>
                </select>
                <?php if ($user_id == $_SESSION['user_id']): ?>
                    <input type="hidden" name="role" value="<?php echo $user['role']; ?>">
                    <small style="color: #6b7280; font-size: 13px;">자신의 권한은 변경할 수 없습니다.</small>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="status">상태 <span style="color: red;">*</span></label>
                <select id="status" name="status" required
                        <?php echo ($user_id == $_SESSION['user_id']) ? 'disabled' : ''; ?>>
                    <option value="active" <?php echo ($user['status'] == 'active') ? 'selected' : ''; ?>>활성</option>
                    <option value="inactive" <?php echo ($user['status'] == 'inactive') ? 'selected' : ''; ?>>비활성</option>
                </select>
                <?php if ($user_id == $_SESSION['user_id']): ?>
                    <input type="hidden" name="status" value="<?php echo $user['status']; ?>">
                    <small style="color: #6b7280; font-size: 13px;">자신의 상태는 변경할 수 없습니다.</small>
                <?php endif; ?>
            </div>
        </div>
        
        <div style="background: #f9fafb; padding: 15px; border-radius: 8px; margin-top: 20px;">
            <strong>계정 정보:</strong>
            <ul style="margin-top: 10px; padding-left: 20px; color: #6b7280;">
                <li>가입일: <?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></li>
                <li>최근 로그인: <?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : '기록 없음'; ?></li>
            </ul>
        </div>
        
        <div style="display: flex; gap: 10px; margin-top: 30px;">
            <button type="submit" class="btn btn-primary">수정 완료</button>
            <a href="view.php?id=<?php echo $user_id; ?>" class="btn btn-secondary">취소 (상세보기로)</a>
        </div>
    </form>
</div>

<script>
// 비밀번호 확인 검증
document.querySelector('form').addEventListener('submit', function(e) {
    const newPassword = document.getElementById('new_password').value;
    const passwordConfirm = document.getElementById('password_confirm').value;
    
    if (newPassword && newPassword !== passwordConfirm) {
        e.preventDefault();
        alert('새 비밀번호가 일치하지 않습니다.');
        document.getElementById('password_confirm').focus();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>