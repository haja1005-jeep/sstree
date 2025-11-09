<?php
/**
 * 지역 수정
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '지역 수정';

$database = new Database();
$db = $database->getConnection();

$error = '';
$region_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($region_id === 0) {
    redirect('/admin/regions/list.php');
}

// 기존 데이터 조회
$query = "SELECT * FROM regions WHERE region_id = :region_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':region_id', $region_id);
$stmt->execute();
$region = $stmt->fetch();

if (!$region) {
    redirect('/admin/regions/list.php');
}

// 수정 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $region_name = sanitize($_POST['region_name']);
    $region_code = sanitize($_POST['region_code']);
    $description = sanitize($_POST['description']);
    
    if (empty($region_name)) {
        $error = '지역명을 입력해주세요.';
    } else {
        // 중복 확인 (자기 자신 제외)
        $checkQuery = "SELECT COUNT(*) as count FROM regions 
                      WHERE region_name = :region_name AND region_id != :region_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':region_name', $region_name);
        $checkStmt->bindParam(':region_id', $region_id);
        $checkStmt->execute();
        $result = $checkStmt->fetch();
        
        if ($result['count'] > 0) {
            $error = '이미 등록된 지역명입니다.';
        } else {
            $updateQuery = "UPDATE regions 
                           SET region_name = :region_name, 
                               region_code = :region_code, 
                               description = :description 
                           WHERE region_id = :region_id";
            
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':region_name', $region_name);
            $updateStmt->bindParam(':region_code', $region_code);
            $updateStmt->bindParam(':description', $description);
            $updateStmt->bindParam(':region_id', $region_id);
            
            if ($updateStmt->execute()) {
                logActivity($_SESSION['user_id'], 'update', 'region', $region_id, "지역 수정: {$region_name}");
                redirect('/admin/regions/list.php?message=' . urlencode('지역이 수정되었습니다.'));
            } else {
                $error = '지역 수정 중 오류가 발생했습니다.';
            }
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>지역 수정</h2>
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
                   value="<?php echo htmlspecialchars($region['region_name']); ?>">
        </div>
        
        <div class="form-group">
            <label for="region_code">지역코드</label>
            <input type="text" id="region_code" name="region_code" 
                   value="<?php echo htmlspecialchars($region['region_code']); ?>">
        </div>
        
        <div class="form-group">
            <label for="description">설명</label>
            <textarea id="description" name="description"><?php echo htmlspecialchars($region['description']); ?></textarea>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary">수정</button>
            <a href="list.php" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>

<script>
document.querySelector('form').id = 'region-form';
</script>

<?php include '../../includes/footer.php'; ?>
