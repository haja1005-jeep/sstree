<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
checkAuth();
$page_title = '카테고리 관리';
$database = new Database();
$db = $database->getConnection();

if (isset($_GET['delete'])) {
    $category_id = (int)$_GET['delete'];
    $checkQuery = "SELECT COUNT(*) as count FROM locations WHERE category_id = :category_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':category_id', $category_id);
    $checkStmt->execute();
    $result = $checkStmt->fetch();
    
    if ($result['count'] > 0) {
        $error = "이 카테고리에 등록된 장소가 있어 삭제할 수 없습니다.";
    } else {
        $deleteQuery = "DELETE FROM categories WHERE category_id = :category_id";
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->bindParam(':category_id', $category_id);
        if ($deleteStmt->execute()) {
            redirect('/admin/categories/list.php?message=' . urlencode('카테고리가 삭제되었습니다.'));
        }
    }
}

$query = "SELECT c.*, 
          (SELECT COUNT(*) FROM locations WHERE category_id = c.category_id) as location_count
          FROM categories c
          ORDER BY c.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$categories = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>📁 카테고리 관리</h2>
    <a href="add.php" class="btn btn-primary">+ 카테고리 추가</a>
</div>

<?php if (isset($_GET['message'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['message']); ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>카테고리코드</th>
                    <th>카테고리명</th>
                    <th>설명</th>
                    <th>장소 수</th>
                    <th>등록일</th>
                    <th>관리</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($categories) > 0): ?>
                    <?php foreach ($categories as $category): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($category['category_code']); ?></td>
                        <td><strong><?php echo htmlspecialchars($category['category_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars(substr($category['description'] ?? '', 0, 50)); ?></td>
                        <td><?php echo number_format($category['location_count']); ?>개</td>
                        <td><?php echo date('Y-m-d', strtotime($category['created_at'])); ?></td>
                        <td>
                            <a href="edit.php?id=<?php echo $category['category_id']; ?>" class="btn btn-sm btn-secondary">수정</a>
                            <a href="?delete=<?php echo $category['category_id']; ?>" 
                               class="btn btn-sm btn-danger" 
                               onclick="return confirmDelete('이 카테고리를 삭제하시겠습니까?')">삭제</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #999;">등록된 카테고리가 없습니다.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
