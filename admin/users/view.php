<?php
/**
 * 회원 상세보기
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();
checkAdmin();

$page_title = '회원 상세보기';

$database = new Database();
$db = $database->getConnection();

$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($user_id === 0) {
    redirect('/admin/users/list.php');
}

// 회원 정보 조회
$query = "SELECT * FROM users WHERE user_id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$user = $stmt->fetch();

if (!$user) {
    redirect('/admin/users/list.php');
}

// 통계 정보
$stats = [];

// 등록한 나무 수
$treeQuery = "SELECT COUNT(*) as count FROM trees WHERE created_by = :user_id";
$treeStmt = $db->prepare($treeQuery);
$treeStmt->bindParam(':user_id', $user_id);
$treeStmt->execute();
$stats['trees'] = $treeStmt->fetch()['count'];

// 업로드한 사진 수
$photoQuery = "SELECT COUNT(*) as count FROM tree_photos WHERE uploaded_by = :user_id";
$photoStmt = $db->prepare($photoQuery);
$photoStmt->bindParam(':user_id', $user_id);
$photoStmt->execute();
$stats['photos'] = $photoStmt->fetch()['count'];

// 활동 로그 수
$logQuery = "SELECT COUNT(*) as count FROM activity_logs WHERE user_id = :user_id";
$logStmt = $db->prepare($logQuery);
$logStmt->bindParam(':user_id', $user_id);
$logStmt->execute();
$stats['logs'] = $logStmt->fetch()['count'];

// 최근 활동 로그
$recentLogQuery = "SELECT al.*, 
                   CASE 
                       WHEN al.action = 'login' THEN '로그인'
                       WHEN al.action = 'logout' THEN '로그아웃'
                       WHEN al.action = 'create' THEN '생성'
                       WHEN al.action = 'update' THEN '수정'
                       WHEN al.action = 'delete' THEN '삭제'
                       ELSE al.action
                   END as action_name
                   FROM activity_logs al
                   WHERE al.user_id = :user_id
                   ORDER BY al.created_at DESC
                   LIMIT 10";
$recentLogStmt = $db->prepare($recentLogQuery);
$recentLogStmt->bindParam(':user_id', $user_id);
$recentLogStmt->execute();
$recent_logs = $recentLogStmt->fetchAll();

// 최근 등록한 나무
$recentTreeQuery = "SELECT t.*, s.korean_name as species_name, l.location_name
                    FROM trees t
                    LEFT JOIN tree_species_master s ON t.species_id = s.species_id
                    LEFT JOIN locations l ON t.location_id = l.location_id
                    WHERE t.created_by = :user_id
                    ORDER BY t.created_at DESC
                    LIMIT 5";
$recentTreeStmt = $db->prepare($recentTreeQuery);
$recentTreeStmt->bindParam(':user_id', $user_id);
$recentTreeStmt->execute();
$recent_trees = $recentTreeStmt->fetchAll();

$role_labels = [
    'admin' => '관리자',
    'manager' => '매니저',
    'field_worker' => '현장직원'
];

$status_labels = [
    'active' => '활성',
    'inactive' => '비활성'
];

include '../../includes/header.php';
?>

<style>
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.info-item {
    background: #f9fafb;
    padding: 15px;
    border-radius: 8px;
    border-left: 4px solid var(--primary-color);
}
.info-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
}
.info-value {
    font-size: 18px;
    font-weight: 600;
    color: var(--dark-color);
}
.stat-box {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s;
}
.stat-box:hover {
    border-color: #667eea;
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
}
.stat-number {
    font-size: 36px;
    font-weight: 700;
    color: #667eea;
    margin-bottom: 5px;
}
.stat-label {
    font-size: 14px;
    color: #6b7280;
}
.activity-item {
    padding: 15px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.activity-item:last-child {
    border-bottom: none;
}
.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    margin-right: 15px;
}
</style>

<div style="display: flex; gap: 10px; margin-bottom: 20px;">
    <a href="list.php" class="btn btn-secondary">← 목록으로</a>
    <a href="edit.php?id=<?php echo $user_id; ?>" class="btn btn-primary">수정</a>
    <?php if ($user_id != $_SESSION['user_id']): ?>
        <a href="list.php?delete=<?php echo $user_id; ?>" 
           class="btn btn-danger" 
           style="margin-left: auto;"
           onclick="return confirm('이 회원을 삭제하시겠습니까?');">
            삭제
        </a>
    <?php endif; ?>
</div>

<!-- 기본 정보 -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">👤 <?php echo htmlspecialchars($user['name'] ?: $user['username']); ?></h3>
        <div style="display: flex; gap: 10px;">
            <span class="badge badge-info"><?php echo $role_labels[$user['role']]; ?></span>
            <span class="badge <?php echo $user['status'] === 'active' ? 'badge-success' : 'badge-danger'; ?>">
                <?php echo $status_labels[$user['status']]; ?>
            </span>
        </div>
    </div>
    <div class="card-body">
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">아이디</div>
                <div class="info-value" style="font-size: 16px;">
                    <?php echo htmlspecialchars($user['username']); ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">이메일</div>
                <div class="info-value" style="font-size: 14px;">
                    <?php echo htmlspecialchars($user['email']); ?>
                </div>
            </div>
            
            <?php if ($user['phone']): ?>
                <div class="info-item">
                    <div class="info-label">연락처</div>
                    <div class="info-value" style="font-size: 16px;">
                        <?php echo htmlspecialchars($user['phone']); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="info-item">
                <div class="info-label">가입일</div>
                <div class="info-value" style="font-size: 14px;">
                    <?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">최근 로그인</div>
                <div class="info-value" style="font-size: 14px;">
                    <?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : '기록 없음'; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 활동 통계 -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">📊 활동 통계</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['trees']); ?></div>
                <div class="stat-label">등록한 나무</div>
            </div>
            
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['photos']); ?></div>
                <div class="stat-label">업로드한 사진</div>
            </div>
            
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['logs']); ?></div>
                <div class="stat-label">총 활동 수</div>
            </div>
        </div>
    </div>
</div>

<!-- 최근 등록한 나무 -->
<?php if (count($recent_trees) > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🌳 최근 등록한 나무 (최근 5개)</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>수종</th>
                            <th>장소</th>
                            <th>높이(m)</th>
                            <th>직경(cm)</th>
                            <th>등록일</th>
                            <th>관리</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_trees as $tree): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tree['species_name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($tree['location_name'] ?: '-'); ?></td>
                                <td><?php echo $tree['height'] ? number_format($tree['height'], 2) : '-'; ?></td>
                                <td><?php echo $tree['diameter'] ? number_format($tree['diameter'], 2) : '-'; ?></td>
                                <td><?php echo date('Y-m-d', strtotime($tree['created_at'])); ?></td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/admin/trees/view.php?id=<?php echo $tree['tree_id']; ?>" 
                                       class="btn btn-sm btn-success">보기</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- 최근 활동 로그 -->
<?php if (count($recent_logs) > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📋 최근 활동 (최근 10개)</h3>
        </div>
        <div class="card-body">
            <?php foreach ($recent_logs as $log): ?>
                <div class="activity-item">
                    <div style="display: flex; align-items: center; flex: 1;">
                        <div class="activity-icon">
                            <?php
                            $icons = [
                                'login' => '🔓',
                                'logout' => '🔒',
                                'create' => '➕',
                                'update' => '✏️',
                                'delete' => '🗑️'
                            ];
                            echo $icons[$log['action']] ?? '📝';
                            ?>
                        </div>
                        <div>
                            <div style="font-weight: 600; color: #2c3e50;">
                                <?php echo htmlspecialchars($log['action_name']); ?>
                            </div>
                            <div style="font-size: 13px; color: #6b7280;">
                                <?php echo htmlspecialchars($log['details']); ?>
                            </div>
                        </div>
                    </div>
                    <div style="text-align: right; color: #6b7280; font-size: 13px;">
                        <?php echo date('Y-m-d H:i', strtotime($log['created_at'])); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>