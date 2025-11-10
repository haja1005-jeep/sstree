<?php
/**
 * 수종 수정
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
checkAuth();

$page_title = '수종 수정';
$database = new Database();
$db = $database->getConnection();
$error = '';

// 1. 수정할 ID 가져오기
$species_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($species_id === 0) {
    redirect('/admin/species/list.php');
}

// 2. 폼 제출(POST) 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // add.php와 동일한 폼 데이터
    $scientific_name = sanitize($_POST['scientific_name']);
    $korean_name = sanitize($_POST['korean_name']);
    $english_name = sanitize($_POST['english_name']);
    $family = sanitize($_POST['family']);
    $genus = sanitize($_POST['genus']);
    $characteristics = sanitize($_POST['characteristics']);
    $growth_info = sanitize($_POST['growth_info']);
    $care_guide = sanitize($_POST['care_guide']);
    
    if (empty($scientific_name) || empty($korean_name)) {
        $error = '학명과 한글명은 필수 입력 항목입니다.';
    } else {
        // [수정] INSERT 대신 UPDATE 쿼리
        $query = "UPDATE tree_species_master SET 
                    scientific_name = :scientific_name, 
                    korean_name = :korean_name, 
                    english_name = :english_name, 
                    family = :family, 
                    genus = :genus, 
                    characteristics = :characteristics, 
                    growth_info = :growth_info, 
                    care_guide = :care_guide
                  WHERE species_id = :species_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':scientific_name', $scientific_name);
        $stmt->bindParam(':korean_name', $korean_name);
        $stmt->bindParam(':english_name', $english_name);
        $stmt->bindParam(':family', $family);
        $stmt->bindParam(':genus', $genus);
        $stmt->bindParam(':characteristics', $characteristics);
        $stmt->bindParam(':growth_info', $growth_info);
        $stmt->bindParam(':care_guide', $care_guide);
        $stmt->bindParam(':species_id', $species_id); // [수정] ID 조건 추가
        
        if ($stmt->execute()) {
            logActivity($_SESSION['user_id'], 'update', 'species', $species_id, "수종 수정: {$korean_name}");
            redirect('/admin/species/list.php?message=' . urlencode('수종이 수정되었습니다.'));
        } else {
            $error = '수종 수정 중 오류가 발생했습니다.';
        }
    }
}

// 3. (GET 요청 시) 기존 데이터 불러오기
try {
    $query = "SELECT * FROM tree_species_master WHERE species_id = :species_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':species_id', $species_id);
    $stmt->execute();
    $species = $stmt->fetch();

    if (!$species) {
        redirect('/admin/species/list.php');
    }
} catch (Exception $e) {
    $error = "수종 정보를 불러오는 데 실패했습니다.";
    $species = []; // 폼 깨짐 방지
}

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>수종 수정</h2>
    <a href="list.php" class="btn btn-secondary">← 목록으로</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" action="">
        <h3 style="margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0;">기본 정보</h3>
        
        <div class="form-group">
            <label for="korean_name">한글명 <span style="color: red;">*</span></label>
            <input type="text" id="korean_name" name="korean_name" required 
                   value="<?php echo htmlspecialchars($species['korean_name'] ?? ''); ?>">
        </div>
        
        <div class="form-group">
            <label for="scientific_name">학명 <span style="color: red;">*</span></label>
            <input type="text" id="scientific_name" name="scientific_name" required 
                   value="<?php echo htmlspecialchars($species['scientific_name'] ?? ''); ?>">
        </div>
        
        <div class="form-group">
            <label for="english_name">영문명</label>
            <input type="text" id="english_name" name="english_name" 
                   value="<?php echo htmlspecialchars($species['english_name'] ?? ''); ?>">
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label for="family">과(Family)</label>
                <input type="text" id="family" name="family" 
                       value="<?php echo htmlspecialchars($species['family'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="genus">속(Genus)</label>
                <input type="text" id="genus" name="genus" 
                       value="<?php echo htmlspecialchars($species['genus'] ?? ''); ?>">
            </div>
        </div>
        
        <h3 style="margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0;">상세 정보</h3>
        
        <div class="form-group">
            <label for="characteristics">특징</label>
            <textarea id="characteristics" name="characteristics" rows="5"><?php echo htmlspecialchars($species['characteristics'] ?? ''); ?></textarea>
        </div>
        
        <div class="form-group">
            <label for="growth_info">생장 정보</label>
            <textarea id="growth_info" name="growth_info" rows="5"><?php echo htmlspecialchars($species['growth_info'] ?? ''); ?></textarea>
        </div>
        
        <div class="form-group">
            <label for="care_guide">관리 가이드</label>
            <textarea id="care_guide" name="care_guide" rows="5"><?php echo htmlspecialchars($species['care_guide'] ?? ''); ?></textarea>
        </div>
        
        <div style="display: flex; gap: 10px; margin-top: 30px;">
            <button type="submit" class="btn btn-primary">수정 완료</button>
            <a href="list.php" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>