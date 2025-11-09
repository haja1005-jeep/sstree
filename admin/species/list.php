<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
checkAuth();
$page_title = '수종 관리';
$database = new Database();
$db = $database->getConnection();

if (isset($_GET['delete'])) {
    $species_id = (int)$_GET['delete'];
    $checkQuery = "SELECT COUNT(*) as count FROM trees WHERE species_id = :species_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':species_id', $species_id);
    $checkStmt->execute();
    $result = $checkStmt->fetch();
    
    if ($result['count'] > 0) {
        $error = "이 수종에 등록된 나무가 있어 삭제할 수 없습니다.";
    } else {
        $deleteQuery = "DELETE FROM tree_species_master WHERE species_id = :species_id";
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->bindParam(':species_id', $species_id);
        if ($deleteStmt->execute()) {
            redirect('/admin/species/list.php?message=' . urlencode('수종이 삭제되었습니다.'));
        }
    }
}

$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$query = "SELECT s.*, 
          (SELECT COUNT(*) FROM trees WHERE species_id = s.species_id) as tree_count
          FROM tree_species_master s";

if ($search) {
    $query .= " WHERE s.korean_name LIKE :search OR s.scientific_name LIKE :search";
}
$query .= " ORDER BY s.korean_name ASC";

$stmt = $db->prepare($query);
if ($search) {
    $searchParam = "%{$search}%";
    $stmt->bindParam(':search', $searchParam);
}
$stmt->execute();
$species_list = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>🌲 수종 관리</h2>
    <a href="add.php" class="btn btn-primary">+ 수종 추가</a>
</div>

<?php if (isset($_GET['message'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['message']); ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <form method="GET" action="" style="margin-bottom: 20px;">
        <div style="display: flex; gap: 10px;">
            <input type="text" name="search" placeholder="수종명 또는 학명 검색" 
                   value="<?php echo htmlspecialchars($search); ?>" 
                   style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <button type="submit" class="btn btn-primary">검색</button>
            <?php if ($search): ?>
                <a href="list.php" class="btn btn-secondary">초기화</a>
            <?php endif; ?>
        </div>
    </form>
    
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>학명</th>
                    <th>한글명</th>
                    <th>영문명</th>
                    <th>과</th>
                    <th>속</th>
                    <th>등록된 나무</th>
                    <th>등록일</th>
                    <th>관리</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($species_list) > 0): ?>
                    <?php foreach ($species_list as $species): ?>
                    <tr>
                        <td><em><?php echo htmlspecialchars($species['scientific_name']); ?></em></td>
                        <td><strong><?php echo htmlspecialchars($species['korean_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($species['english_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($species['family'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($species['genus'] ?? '-'); ?></td>
                        <td><?php echo number_format($species['tree_count']); ?>그루</td>
                        <td><?php echo date('Y-m-d', strtotime($species['created_at'])); ?></td>
                        <td>
                            <a href="detail.php?id=<?php echo $species['species_id']; ?>" class="btn btn-sm btn-success">상세</a>
                            <a href="edit.php?id=<?php echo $species['species_id']; ?>" class="btn btn-sm btn-secondary">수정</a>
                            <a href="?delete=<?php echo $species['species_id']; ?>" 
                               class="btn btn-sm btn-danger" 
                               onclick="return confirmDelete('이 수종을 삭제하시겠습니까?')">삭제</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #999;">
                            <?php echo $search ? '검색 결과가 없습니다.' : '등록된 수종이 없습니다.'; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
