<?php
/**
 * 지역 추가
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '지역 추가';

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $region_name = sanitize($_POST['region_name']);
    $region_code = sanitize($_POST['region_code']);
    $description = sanitize($_POST['description']);
    
    if (empty($region_name)) {
        $error = '지역명을 입력해주세요.';
    } else {
        // 중복 확인
        $checkQuery = "SELECT COUNT(*) as count FROM regions WHERE region_name = :region_name";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':region_name', $region_name);
        $checkStmt->execute();
        $result = $checkStmt->fetch();
        
        if ($result['count'] > 0) {
            $error = '이미 등록된 지역명입니다.';
        } else {
            $query = "INSERT INTO regions (region_name, region_code, description, created_by) 
                     VALUES (:region_name, :region_code, :description, :created_by)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':region_name', $region_name);
            $stmt->bindParam(':region_code', $region_code);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':created_by', $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                logActivity($_SESSION['user_id'], 'create', 'region', $db->lastInsertId(), "지역 추가: {$region_name}");
                redirect('/admin/regions/list.php?message=' . urlencode('지역이 추가되었습니다.'));
            } else {
                $error = '지역 추가 중 오류가 발생했습니다.';
            }
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>지역 추가</h2>
    <a href="list.php" class="btn btn-secondary">← 목록으로</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" action="" onsubmit="return validateForm('region-form')">
        <div class="form-group">
            <label for="region_name">지역명 <span style="color: red;">*</span></label>
            <input type="text" id="region_name" name="region_name" required 
                   placeholder="예: 압해읍" 
                   value="<?php echo isset($_POST['region_name']) ? htmlspecialchars($_POST['region_name']) : ''; ?>">
        </div>
        
        <div class="form-group">
            <label for="region_code">지역코드</label>
            <input type="text" id="region_code" name="region_code" 
                   placeholder="예: APH" 
                   value="<?php echo isset($_POST['region_code']) ? htmlspecialchars($_POST['region_code']) : ''; ?>">
            <small style="color: #999;">지역을 구분하기 위한 코드 (선택사항)</small>
        </div>
        
        <div class="form-group">
            <label for="description">설명</label>
            <textarea id="description" name="description" 
                      placeholder="지역에 대한 설명을 입력하세요"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary">저장</button>
            <a href="list.php" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>

<script>
// 폼 ID 설정
document.querySelector('form').id = 'region-form';
</script>

<?php include '../../includes/footer.php'; ?>
