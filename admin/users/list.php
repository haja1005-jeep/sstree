<?php
/**
 * 회원 목록
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();
checkAdmin(); // 관리자만 접근 가능

$page_title = '회원 관리';

$database = new Database();
$db = $database->getConnection();

// 삭제 처리
if (isset($_GET['delete']) && isAdmin()) {
    $user_id = (int)$_GET['delete'];
    
    // 자기 자신은 삭제 불가
    if ($user_id == $_SESSION['user_id']) {
        $error = "자기 자신은 삭제할 수 없습니다.";
    } else {
        try {
            $query = "DELETE FROM users WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            logActivity($_SESSION['user_id'], 'delete', 'user', $user_id, '회원 삭제');
            
            $success_message = '회원이 삭제되었습니다.';
        } catch (Exception $e) {
            $error = '회원 삭제 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

// 상태 변경 처리
if (isset($_GET['toggle_status'])) {
    $user_id = (int)$_GET['toggle_status'];
    
    try {
        // 현재 상태 조회
        $statusQuery = "SELECT status FROM users WHERE user_id = :user_id";
        $statusStmt = $db->prepare($statusQuery);
        $statusStmt->bindParam(':user_id', $user_id);
        $statusStmt->execute();
        $current_status = $statusStmt->fetch()['status'];
        
        // 상태 토글
        $new_status = ($current_status === 'active') ? 'inactive' : 'active';
        
        $updateQuery = "UPDATE users SET status = :status WHERE user_id = :user_id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':status', $new_status);
        $updateStmt->bindParam(':user_id', $user_id);
        $updateStmt->execute();
        
        logActivity($_SESSION['user_id'], 'update', 'user', $user_id, "상태 변경: {$new_status}");
        
        $success_message = '회원 상태가 변경되었습니다.';
    } catch (Exception $e) {
        $error = '상태 변경 중 오류가 발생했습니다: ' . $e->getMessage();
    }
}

// 검색 및 필터
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? sanitize($_GET['role']) : '';
$status_filter = isset($_GET['status']) ? sanitize($_GET['status']) : '';

// 회원 목록 조회
$query = "SELECT u.*, 
          (SELECT COUNT(*) FROM trees WHERE created_by = u.user_id) as tree_count,
          (SELECT COUNT(*) FROM activity_logs WHERE user_id = u.user_id) as activity_count
          FROM users u
          WHERE 1=1";

if ($search) {
    $query .= " AND (u.username LIKE :search OR u.name LIKE :search OR u.email LIKE :search)";
}
if ($role_filter) {
    $query .= " AND u.role = :role";
}
if ($status_filter) {
    $query .= " AND u.status = :status";
}

$query .= " ORDER BY u.created_at DESC";

$stmt = $db->prepare($query);

if ($search) {
    $search_param = "%{$search}%";
    $stmt->bindParam(':search', $search_param);
}
if ($role_filter) {
    $stmt->bindParam(':role', $role_filter);
}
if ($status_filter) {
    $stmt->bindParam(':status', $status_filter);
}

$stmt->execute();
$users = $stmt->fetchAll();

require_once '../../includes/header.php';
?>

<style>
.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
.status-active { background: #d1fae5; color: #065f46; }
.status-inactive { background: #fee2e2; color: #991b1b; }

.role-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
.role-admin { background: #dbeafe; color: #1e40af; }
.role-manager { background: #fef3c7; color: #92400e; }
.role-field_worker { background: #f3e8ff; color: #6b21a8; }
</style>

<?php if (isset($success_message)): ?>
    <div class="alert alert-success"><?php echo $success_message; ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<!-- 검색 및 필터 -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body">
        <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label for="search">검색</label>
                <input type="text" 
                       id="search" 
                       name="search" 
                       placeholder="아이디, 이름, 이메일"
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label for="role">권한</label>
                <select id="role" name="role">
                    <option value="">전체</option>
                    <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>관리자</option>
                    <option value="manager" <?php echo $role_filter == 'manager' ? 'selected' : ''; ?>>매니저</option>
                    <option value="field_worker" <?php echo $role_filter == 'field_worker' ? 'selected' : ''; ?>>현장직원</option>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label for="status">상태</label>
                <select id="status" name="status">
                    <option value="">전체</option>
                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>활성</option>
                    <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>비활성</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">🔍 검색</button>
                <a href="list.php" class="btn btn-secondary">초기화</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">👥 회원 목록 (총 <?php echo count($users); ?>명)</h3>
        <a href="add.php" class="btn btn-primary">➕ 회원 추가</a>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>아이디</th>
                        <th>이름</th>
                        <th>이메일</th>
                        <th>권한</th>
                        <th>상태</th>
                        <th>등록 나무</th>
                        <th>활동 수</th>
                        <th>가입일</th>
                        <th>최근 로그인</th>
                        <th>관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($users) > 0): ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td style="font-weight: 600;">
                                    <?php echo htmlspecialchars($user['username']); ?>
                                    <?php if ($user['user_id'] == $_SESSION['user_id']): ?>
                                        <span style="color: #667eea; font-size: 11px;">(나)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($user['name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <?php
                                    $role_classes = [
                                        'admin' => 'role-admin',
                                        'manager' => 'role-manager',
                                        'field_worker' => 'role-field_worker'
                                    ];
                                    $role_labels = [
                                        'admin' => '관리자',
                                        'manager' => '매니저',
                                        'field_worker' => '현장직원'
                                    ];
                                    ?>
                                    <span class="role-badge <?php echo $role_classes[$user['role']]; ?>">
                                        <?php echo $role_labels[$user['role']]; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $user['status']; ?>">
                                        <?php echo $user['status'] === 'active' ? '활성' : '비활성'; ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($user['tree_count']); ?>그루</td>
                                <td><?php echo number_format($user['activity_count']); ?>건</td>
                                <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <?php 
                                    if ($user['last_login']) {
                                        echo date('Y-m-d H:i', strtotime($user['last_login']));
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="view.php?id=<?php echo $user['user_id']; ?>" class="btn btn-sm btn-success">보기</a>
                                    <a href="edit.php?id=<?php echo $user['user_id']; ?>" class="btn btn-sm btn-secondary">수정</a>
                                    
                                    <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                        <a href="?toggle_status=<?php echo $user['user_id']; ?>" 
                                           class="btn btn-sm <?php echo $user['status'] === 'active' ? 'btn-danger' : 'btn-success'; ?>"
                                           onclick="return confirm('회원 상태를 변경하시겠습니까?');">
                                            <?php echo $user['status'] === 'active' ? '비활성' : '활성'; ?>
                                        </a>
                                        
                                        <a href="?delete=<?php echo $user['user_id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('이 회원을 삭제하시겠습니까?\n모든 활동 기록이 유지되지만 로그인할 수 없게 됩니다.');">삭제</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">
                                등록된 회원이 없습니다.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">💡 회원 관리 안내</h3>
    </div>
    <div class="card-body">
        <ul style="list-style: none; padding: 0;">
            <li style="margin-bottom: 10px;">✓ <strong>관리자:</strong> 모든 기능에 접근할 수 있으며, 회원 관리가 가능합니다.</li>
            <li style="margin-bottom: 10px;">✓ <strong>매니저:</strong> 데이터 등록 및 수정이 가능하지만, 회원 관리는 불가능합니다.</li>
            <li style="margin-bottom: 10px;">✓ <strong>현장직원:</strong> 나무 데이터 등록 및 사진 업로드만 가능합니다.</li>
            <li style="margin-bottom: 10px;">✓ 비활성 상태의 회원은 로그인할 수 없습니다.</li>
            <li style="margin-bottom: 10px;">✓ 회원을 삭제해도 해당 회원이 등록한 데이터는 유지됩니다.</li>
        </ul>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>