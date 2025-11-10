<?php
/**
 * 수종 상세보기
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
checkAuth();

$page_title = '수종 상세보기';
$database = new Database();
$db = $database->getConnection();
$error = '';

// 1. ID 가져오기
$species_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($species_id === 0) {
    redirect('/admin/species/list.php');
}

// 2. 데이터 불러오기
try {
    // 수종 정보
    $query = "SELECT * FROM tree_species_master WHERE species_id = :species_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':species_id', $species_id);
    $stmt->execute();
    $species = $stmt->fetch();

    if (!$species) {
        redirect('/admin/species/list.php');
    }

    // 이 수종을 사용하는 나무 수
    $treeQuery = "SELECT COUNT(*) as tree_count FROM trees WHERE species_id = :species_id";
    $treeStmt = $db->prepare($treeQuery);
    $treeStmt->bindParam(':species_id', $species_id);
    $treeStmt->execute();
    $tree_count = $treeStmt->fetch()['tree_count'];

} catch (Exception $e) {
    $error = "정보를 불러오는 데 실패했습니다: " . $e->getMessage();
    $species = [];
    $tree_count = 0;
}

include '../../includes/header.php';
?>

<style>
/* 상세보기 페이지용 간단한 스타일 */
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
.info-box {
    background: #f9f9f9;
    padding: 15px 20px;
    border-radius: 8px;
    border: 1px solid #eee;
}
.info-box label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #777;
    margin-bottom: 5px;
}
.info-box p {
    font-size: 16px;
    color: #333;
}
.info-box-full {
    grid-column: 1 / -1;
}
.info-box .description {
    white-space: pre-wrap; /* \n 줄바꿈을 그대로 표시 */
    line-height: 1.7;
    font-family: 'Noto Sans KR', sans-serif;
}
</style>

<div class="page-header">
    <h2><?php echo htmlspecialchars($species['korean_name'] ?? '수종 정보'); ?></h2>
    <div>
        <a href="list.php" class="btn btn-secondary">← 목록으로</a>
        <a href="edit.php?id=<?php echo $species_id; ?>" class="btn btn-primary">수정</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <h3>기본 정보</h3>
    <div class="detail-grid" style="margin-top: 20px;">
        <div class="info-box">
            <label>한글명</label>
            <p><strong><?php echo htmlspecialchars($species['korean_name'] ?? '-'); ?></strong></p>
        </div>
        <div class="info-box">
            <label>학명</label>
            <p><em><?php echo htmlspecialchars($species['scientific_name'] ?? '-'); ?></em></p>
        </div>
        <div class="info-box">
            <label>영문명</label>
            <p><?php echo htmlspecialchars($species['english_name'] ?? '-'); ?></p>
        </div>
        <div class="info-box">
            <label>등록된 나무 수</label>
            <p><strong><?php echo number_format($tree_count); ?>그루</strong></p>
        </div>
        <div class="info-box">
            <label>과 (Family)</label>
            <p><?php echo htmlspecialchars($species['family'] ?? '-'); ?></p>
        </div>
        <div class="info-box">
            <label>속 (Genus)</label>
            <p><?php echo htmlspecialchars($species['genus'] ?? '-'); ?></p>
        </div>
    </div>
</div>

<div class="card">
    <h3>상세 정보</h3>
    <div class="detail-grid" style="margin-top: 20px;">
        <div class="info-box info-box-full">
            <label>특징</label>
            <p class="description"><?php echo nl2br(htmlspecialchars($species['characteristics'] ?? '등록된 정보가 없습니다.')); ?></p>
        </div>
        <div class="info-box info-box-full">
            <label>생장 정보</label>
            <p class="description"><?php echo nl2br(htmlspecialchars($species['growth_info'] ?? '등록된 정보가 없습니다.')); ?></p>
        </div>
        <div class="info-box info-box-full">
            <label>관리 가이드</label>
            <p class="description"><?php echo nl2br(htmlspecialchars($species['care_guide'] ?? '등록된 정보가 없습니다.')); ?></p>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>