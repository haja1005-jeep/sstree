<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
checkAuth();
$page_title = '카테고리 추가';
$database = new Database();
$db = $database->getConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name = sanitize($_POST['category_name']);
    $category_code = sanitize($_POST['category_code']);
    $description = sanitize($_POST['description']);
    
    if (empty($category_name)) {
        $error = '카테고리명을 입력해주세요.';
    } else {
        $query = "INSERT INTO categories (category_name, category_code, description) 
                 VALUES (:category_name, :category_code, :description)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':category_name', $category_name);
        $stmt->bindParam(':category_code', $category_code);
        $stmt->bindParam(':description', $description);
        
        if ($stmt->execute()) {
            logActivity($_SESSION['user_id'], 'create', 'category', $db->lastInsertId(), "카테고리 추가: {$category_name}");
            redirect('/admin/categories/list.php?message=' . urlencode('카테고리가 추가되었습니다.'));
        } else {
            $error = '카테고리 추가 중 오류가 발생했습니다.';
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>카테고리 추가</h2>
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
                   placeholder="예: 공원, 생활숲, 가로수, 보호수" 
                   value="<?php echo isset($_POST['category_name']) ? htmlspecialchars($_POST['category_name']) : ''; ?>">
        </div>
        
        <div class="form-group">
            <label for="category_code">카테고리코드</label>
            <input type="text" id="category_code" name="category_code" 
                   placeholder="예: PARK" 
                   value="<?php echo isset($_POST['category_code']) ? htmlspecialchars($_POST['category_code']) : ''; ?>">
        </div>
        
        <div class="form-group">
            <label for="description">설명</label>
            <textarea id="description" name="description" 
                      placeholder="카테고리에 대한 설명을 입력하세요"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary">저장</button>
            <a href="list.php" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
