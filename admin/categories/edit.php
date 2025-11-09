<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
checkAuth();
$page_title = '카테고리 수정';
$database = new Database();
$db = $database->getConnection();
$error = '';
$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($category_id === 0) redirect('/admin/categories/list.php');

$query = "SELECT * FROM categories WHERE category_id = :category_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':category_id', $category_id);
$stmt->execute();
$category = $stmt->fetch();

if (!$category) redirect('/admin/categories/list.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name = sanitize($_POST['category_name']);
    $category_code = sanitize($_POST['category_code']);
    $description = sanitize($_POST['description']);
    
    if (empty($category_name)) {
        $error = '카테고리명을 입력해주세요.';
    } else {
        $updateQuery = "UPDATE categories 
                       SET category_name = :category_name, 
                           category_code = :category_code, 
                           description = :description 
                       WHERE category_id = :category_id";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':category_name', $category_name);
        $updateStmt->bindParam(':category_code', $category_code);
        $updateStmt->bindParam(':description', $description);
        $updateStmt->bindParam(':category_id', $category_id);
        
        if ($updateStmt->execute()) {
            logActivity($_SESSION['user_id'], 'update', 'category', $category_id, "카테고리 수정: {$category_name}");
            redirect('/admin/categories/list.php?message=' . urlencode('카테고리가 수정되었습니다.'));
        } else {
            $error = '카테고리 수정 중 오류가 발생했습니다.';
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>카테고리 수정</h2>
    <a href="list.php" class="btn btn-secondary">← 목록으로</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" action="">
        <div class="form-group">
            <label for="category_name">카테고리명 <span style="color: red;">*</span></label>
            <input type="text" id="category_name" name="category_name" required 
                   value="<?php echo htmlspecialchars($category['category_name']); ?>">
        </div>
        
        <div class="form-group">
            <label for="category_code">카테고리코드</label>
            <input type="text" id="category_code" name="category_code" 
                   value="<?php echo htmlspecialchars($category['category_code']); ?>">
        </div>
        
        <div class="form-group">
            <label for="description">설명</label>
            <textarea id="description" name="description"><?php echo htmlspecialchars($category['description']); ?></textarea>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary">수정</button>
            <a href="list.php" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
