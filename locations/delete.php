<?php
/**
 * 장소 삭제
 * Smart Tree Map - Location Management
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

$database = new Database();
$db = $database->getConnection();

// 장소 ID 확인
$location_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$location_id) {
    $_SESSION['error_message'] = '잘못된 접근입니다.';
    header('Location: index.php');
    exit;
}

// 장소 정보 조회
$query = "SELECT l.*, 
          COUNT(DISTINCT lt.species_id) as species_count,
          COALESCE(SUM(lt.quantity), 0) as total_trees
          FROM locations l
          LEFT JOIN location_trees lt ON l.location_id = lt.location_id
          WHERE l.location_id = :location_id
          GROUP BY l.location_id";

$stmt = $db->prepare($query);
$stmt->bindParam(':location_id', $location_id);
$stmt->execute();
$location = $stmt->fetch();

if (!$location) {
    $_SESSION['error_message'] = '장소를 찾을 수 없습니다.';
    header('Location: index.php');
    exit;
}

// 삭제 처리
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // CASCADE 설정으로 location_trees도 자동 삭제됨
        $delete_query = "DELETE FROM locations WHERE location_id = :location_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':location_id', $location_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success_message'] = '장소가 성공적으로 삭제되었습니다.';
            header('Location: index.php');
            exit;
        } else {
            throw new Exception('삭제 중 오류가 발생했습니다.');
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: index.php');
        exit;
    }
}

$page_title = '장소 삭제';
include '../../includes/header.php';
?>

<style>
.delete-container {
    max-width: 800px;
    margin: 0 auto;
}

.warning-box {
    background: #fef2f2;
    border: 2px solid #fecaca;
    border-radius: 10px;
    padding: 30px;
    margin-bottom: 30px;
}

.warning-icon {
    font-size: 64px;
    text-align: center;
    margin-bottom: 20px;
}

.warning-title {
    font-size: 24px;
    font-weight: 700;
    color: #991b1b;
    text-align: center;
    margin-bottom: 20px;
}

.warning-message {
    font-size: 16px;
    color: #7f1d1d;
    text-align: center;
    line-height: 1.6;
    margin-bottom: 30px;
}

.location-info {
    background: white;
    border-radius: 10px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.info-row {
    display: flex;
    padding: 12px 0;
    border-bottom: 1px solid #e5e7eb;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    width: 150px;
    font-weight: 600;
    color: #374151;
}

.info-value {
    flex: 1;
    color: #6b7280;
}

.impact-list {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
}

.impact-list h4 {
    color: #92400e;
    margin-bottom: 15px;
    font-size: 16px;
}

.impact-list ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.impact-list li {
    padding: 8px 0;
    color: #78350f;
    display: flex;
    align-items: center;
    gap: 10px;
}

.impact-list li:before {
    content: "⚠️";
}

.confirm-checkbox {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
}

.confirm-checkbox label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-weight: 600;
    color: #374151;
}

.confirm-checkbox input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.action-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
}

.action-buttons .btn {
    min-width: 150px;
}
</style>

<div class="delete-container">
    <div class="page-header">
        <h2>🗑️ 장소 삭제</h2>
    </div>
    
    <div class="warning-box">
        <div class="warning-icon">⚠️</div>
        <div class="warning-title">정말 삭제하시겠습니까?</div>
        <div class="warning-message">
            이 작업은 되돌릴 수 없습니다.<br>
            장소와 함께 관련된 모든 수목 데이터가 영구적으로 삭제됩니다.
        </div>
    </div>
    
    <div class="location-info">
        <h3 style="margin-bottom: 20px; color: #1f2937;">📍 삭제할 장소 정보</h3>
        
        <div class="info-row">
            <div class="info-label">장소명</div>
            <div class="info-value"><strong><?php echo htmlspecialchars($location['location_name']); ?></strong></div>
        </div>
        
        <?php if ($location['address']): ?>
        <div class="info-row">
            <div class="info-label">주소</div>
            <div class="info-value"><?php echo htmlspecialchars($location['address']); ?></div>
        </div>
        <?php endif; ?>
        
        <?php if ($location['road_name']): ?>
        <div class="info-row">
            <div class="info-label">도로명</div>
            <div class="info-value"><?php echo htmlspecialchars($location['road_name']); ?></div>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <div class="info-label">장소 유형</div>
            <div class="info-value">
                <?php
                $type_labels = [
                    'urban_forest' => '도시숲',
                    'street_tree' => '가로수',
                    'living_forest' => '생활숲',
                    'school' => '학교',
                    'park' => '공원',
                    'other' => '기타'
                ];
                echo $type_labels[$location['location_type']] ?? '기타';
                ?>
            </div>
        </div>
        
        <?php if ($location['area']): ?>
        <div class="info-row">
            <div class="info-label">면적</div>
            <div class="info-value"><?php echo number_format($location['area']); ?>㎡</div>
        </div>
        <?php endif; ?>
        
        <?php if ($location['length']): ?>
        <div class="info-row">
            <div class="info-label">총 연장거리</div>
            <div class="info-value"><?php echo number_format($location['length']); ?>m</div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if ($location['total_trees'] > 0): ?>
    <div class="impact-list">
        <h4>⚠️ 삭제 시 영향을 받는 데이터</h4>
        <ul>
            <li>
                <strong><?php echo number_format($location['total_trees']); ?>주</strong>의 나무 데이터가 삭제됩니다
            </li>
            <li>
                <strong><?php echo number_format($location['species_count']); ?>종</strong>의 수종 정보가 삭제됩니다
            </li>
            <li>
                장소와 관련된 모든 수목 현황이 영구적으로 삭제됩니다
            </li>
        </ul>
    </div>
    <?php endif; ?>
    
    <form method="POST" action="" id="deleteForm">
        <div class="confirm-checkbox">
            <label>
                <input type="checkbox" name="confirm_delete" id="confirmCheckbox" required>
                위 내용을 확인했으며 삭제에 동의합니다
            </label>
        </div>
        
        <div class="action-buttons">
            <a href="view.php?id=<?php echo $location_id; ?>" class="btn btn-secondary">
                ← 취소
            </a>
            <button type="submit" class="btn btn-danger" id="deleteButton" disabled>
                🗑️ 영구 삭제
            </button>
        </div>
    </form>
</div>

<script>
// 체크박스 확인 후 삭제 버튼 활성화
document.getElementById('confirmCheckbox').addEventListener('change', function() {
    document.getElementById('deleteButton').disabled = !this.checked;
});

// 폼 제출 시 재확인
document.getElementById('deleteForm').addEventListener('submit', function(e) {
    if (!confirm('정말로 삭제하시겠습니까?\n이 작업은 되돌릴 수 없습니다.')) {
        e.preventDefault();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
