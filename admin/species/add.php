<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
checkAuth();
$page_title = '수종 추가';
$database = new Database();
$db = $database->getConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $query = "INSERT INTO tree_species_master 
                 (scientific_name, korean_name, english_name, family, genus, 
                  characteristics, growth_info, care_guide) 
                 VALUES (:scientific_name, :korean_name, :english_name, :family, :genus, 
                         :characteristics, :growth_info, :care_guide)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':scientific_name', $scientific_name);
        $stmt->bindParam(':korean_name', $korean_name);
        $stmt->bindParam(':english_name', $english_name);
        $stmt->bindParam(':family', $family);
        $stmt->bindParam(':genus', $genus);
        $stmt->bindParam(':characteristics', $characteristics);
        $stmt->bindParam(':growth_info', $growth_info);
        $stmt->bindParam(':care_guide', $care_guide);
        
        if ($stmt->execute()) {
            logActivity($_SESSION['user_id'], 'create', 'species', $db->lastInsertId(), "수종 추가: {$korean_name}");
            redirect('/admin/species/list.php?message=' . urlencode('수종이 추가되었습니다.'));
        } else {
            $error = '수종 추가 중 오류가 발생했습니다.';
        }
    }
}

include '../../includes/header.php';
?>

<div class="page-header">
    <h2>수종 추가</h2>
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
                   placeholder="예: 느티나무" 
                   value="<?php echo isset($_POST['korean_name']) ? htmlspecialchars($_POST['korean_name']) : ''; ?>">
        </div>
        
        <div class="form-group">
            <label for="scientific_name">학명 <span style="color: red;">*</span></label>
            <input type="text" id="scientific_name" name="scientific_name" required 
                   placeholder="예: Zelkova serrata" 
                   value="<?php echo isset($_POST['scientific_name']) ? htmlspecialchars($_POST['scientific_name']) : ''; ?>">
        </div>
        
        <div class="form-group">
            <label for="english_name">영문명</label>
            <input type="text" id="english_name" name="english_name" 
                   placeholder="예: Japanese Zelkova" 
                   value="<?php echo isset($_POST['english_name']) ? htmlspecialchars($_POST['english_name']) : ''; ?>">
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label for="family">과(Family)</label>
                <input type="text" id="family" name="family" 
                       placeholder="예: 느릅나무과" 
                       value="<?php echo isset($_POST['family']) ? htmlspecialchars($_POST['family']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="genus">속(Genus)</label>
                <input type="text" id="genus" name="genus" 
                       placeholder="예: 느티나무속" 
                       value="<?php echo isset($_POST['genus']) ? htmlspecialchars($_POST['genus']) : ''; ?>">
            </div>
        </div>
        
        <h3 style="margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0;">상세 정보</h3>
        
        <div class="form-group">
            <label for="characteristics">특징</label>
            <textarea id="characteristics" name="characteristics" rows="5"
                      placeholder="나무의 외형적 특징, 잎의 모양, 꽃과 열매 등의 특징을 입력하세요"><?php echo isset($_POST['characteristics']) ? htmlspecialchars($_POST['characteristics']) : ''; ?></textarea>
        </div>
        
        <div class="form-group">
            <label for="growth_info">생장 정보</label>
            <textarea id="growth_info" name="growth_info" rows="5"
                      placeholder="생육 환경, 생장 속도, 수고, 수관폭 등의 정보를 입력하세요"><?php echo isset($_POST['growth_info']) ? htmlspecialchars($_POST['growth_info']) : ''; ?></textarea>
        </div>
        
        <div class="form-group">
            <label for="care_guide">관리 가이드</label>
            <textarea id="care_guide" name="care_guide" rows="5"
                      placeholder="병해충 관리, 가지치기, 시비, 관수 등의 관리 방법을 입력하세요"><?php echo isset($_POST['care_guide']) ? htmlspecialchars($_POST['care_guide']) : ''; ?></textarea>
        </div>
        
        <div style="display: flex; gap: 10px; margin-top: 30px;">
            <button type="submit" class="btn btn-primary">저장</button>
            <a href="list.php" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
