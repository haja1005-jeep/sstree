<?php
/**
 * 지역 목록
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '지역 관리';

$database = new Database();
$db = $database->getConnection();

// 삭제 처리
if (isset($_GET['delete'])) {
    $region_id = (int)$_GET['delete'];
    
    // 해당 지역에 나무가 있는지 확인
    $checkQuery = "SELECT COUNT(*) as count FROM trees WHERE region_id = :region_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':region_id', $region_id);
    $checkStmt->execute();
    $result = $checkStmt->fetch();
    
    if ($result['count'] > 0) {
        $error = "이 지역에 등록된 나무가 있어 삭제할 수 없습니다.";
    } else {
        $deleteQuery = "DELETE FROM regions WHERE region_id = :region_id";
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->bindParam(':region_id', $region_id);
        
        if ($deleteStmt->execute()) {
            redirect('/admin/regions/list.php?message=' . urlencode('지역이 삭제되었습니다.'));
        } else {
            $error = "삭제 중 오류가 발생했습니다.";
        }
    }
}

// 지역 목록 조회
$query = "SELECT r.*, 
          (SELECT COUNT(*) FROM trees WHERE region_id = r.region_id) as tree_count,
          u.name as creator_name
          FROM regions r
          LEFT JOIN users u ON r.created_by = u.user_id
          ORDER BY r.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute();
$regions = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>🗺️ 지역 관리</h2>
    <a href="add.php" class="btn btn-primary">+ 지역 추가</a>
</div>

<?php if (isset($_GET['message'])): ?>
    <div class="alert alert-success">
        <?php echo htmlspecialchars($_GET['message']); ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-error">
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>지역코드</th>
                    <th>지역명</th>
                    <th>설명</th>
                    <th>나무 수</th>
                    <th>등록자</th>
                    <th>등록일</th>
                    <th>관리</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($regions) > 0): ?>
                    <?php foreach ($regions as $region): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($region['region_code']); ?></td>
                        <td><strong><?php echo htmlspecialchars($region['region_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars(substr($region['description'] ?? '', 0, 50)); ?><?php echo strlen($region['description'] ?? '') > 50 ? '...' : ''; ?></td>
                        <td><?php echo number_format($region['tree_count']); ?>그루</td>
                        <td><?php echo htmlspecialchars($region['creator_name'] ?? '-'); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($region['created_at'])); ?></td>
                        <td>
                            <a href="edit.php?id=<?php echo $region['region_id']; ?>" class="btn btn-sm btn-secondary">수정</a>
                            <a href="?delete=<?php echo $region['region_id']; ?>" 
                               class="btn btn-sm btn-danger" 
                               onclick="return confirmDelete('이 지역을 삭제하시겠습니까?')">삭제</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                            등록된 지역이 없습니다.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
