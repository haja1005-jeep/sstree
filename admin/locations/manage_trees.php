<?php
/**
 * 수목 관리 (추가/수정/삭제)
 * Smart Tree Map - Location Management
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

$database = new Database();
$db = $database->getConnection();

/**
 * CO2 계산 유틸
 * - 입력: 평균 DBH(cm), 수량(주), (선택) 수고(m)
 * - 출력: [co2_stock_kg, co2_annual_kg, method, note]
 *
 * 주의:
 *  - 계수(a,b)가 없으면 기본계수(보수적)로 계산
 *  - annual은 annual_dbh_growth_cm 가정치가 있을 때만 계산 (없으면 null)
 */
function loadCo2Coeff(PDO $db, int $species_id): array {
    // species별 계수 조회 (없으면 기본값 사용)
    $stmt = $db->prepare("SELECT a, b, annual_dbh_growth_cm, source_note
                          FROM tree_co2_coeff
                          WHERE species_id = :sid
                          LIMIT 1");
    $stmt->bindValue(':sid', $species_id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // 기본값(임시): DBH 기반 바이오매스 추정용 보수적 계수
    // 실제 운영에서는 species별 계수로 교체 권장
    $default = [
        'a' => 0.10,                 // (kg) 계수(임시)
        'b' => 2.40,                 // 지수(임시)
        'annual_dbh_growth_cm' => null, // 없으면 연간 흡수량 계산 안 함
        'source_note' => 'DEFAULT_COEFF_V1 (임시 계수, 추정치)'
    ];

    if (!$row) return $default;

    return [
        'a' => isset($row['a']) ? (float)$row['a'] : $default['a'],
        'b' => isset($row['b']) ? (float)$row['b'] : $default['b'],
        'annual_dbh_growth_cm' => ($row['annual_dbh_growth_cm'] !== null) ? (float)$row['annual_dbh_growth_cm'] : null,
        'source_note' => $row['source_note'] ?: 'tree_co2_coeff'
    ];
}

function calcCo2FromDbh(?float $dbh_cm, int $qty, float $a, float $b): float {
    // 바이오매스(kg) = a * DBH^b (단순화)
    // 탄소(kgC) = 바이오매스 * 0.5
    // CO2(kgCO2) = 탄소 * (44/12)
    if ($dbh_cm === null || $dbh_cm <= 0 || $qty <= 0) return 0.0;

    $biomass_kg = $a * pow($dbh_cm, $b);
    $carbon_kg  = $biomass_kg * 0.5;
    $co2_kg     = $carbon_kg * (44.0 / 12.0);

    return $co2_kg * $qty;
}

function calcCo2Payload(PDO $db, int $species_id, int $qty, ?float $avg_height_m, ?float $avg_dbh_cm): array {
    if ($qty <= 0 || $avg_dbh_cm === null || $avg_dbh_cm <= 0) {
        return [
            'co2_stock_kg' => null,
            'co2_annual_kg' => null,
            'method' => null,
            'note' => 'DBH 미입력: CO2 계산 생략'
        ];
    }

    $coeff = loadCo2Coeff($db, $species_id);

    $stock = calcCo2FromDbh($avg_dbh_cm, $qty, $coeff['a'], $coeff['b']);

    // 연간 흡수량(추정) = (DBH+성장)^저장량 - 현재저장량
    $annual = null;
    if ($coeff['annual_dbh_growth_cm'] !== null && $coeff['annual_dbh_growth_cm'] > 0) {
        $next = calcCo2FromDbh($avg_dbh_cm + $coeff['annual_dbh_growth_cm'], $qty, $coeff['a'], $coeff['b']);
        $annual = max(0.0, $next - $stock);
    }

    $method = 'DBH_EQ_V1';
    $note = $coeff['source_note'];
    if ($annual === null) {
        $note .= ' / annual_dbh_growth_cm 없음 → 연간흡수량 NULL';
    }

    return [
        'co2_stock_kg' => round($stock, 2),
        'co2_annual_kg' => ($annual !== null) ? round($annual, 2) : null,
        'method' => $method,
        'note' => $note
    ];
}




// 장소 ID 확인
$location_id = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;

if (!$location_id) {
    $_SESSION['error_message'] = '잘못된 접근입니다.';
    header('Location: index.php');
    exit;
}

// 장소 정보 조회 [수정된 부분]
// location_categories -> categories 로 변경
// regions 조인을 c.region_id -> l.region_id 로 변경
$location_query = "SELECT l.*, c.category_name, r.region_name
                   FROM locations l
                   LEFT JOIN categories c ON l.category_id = c.category_id
                   LEFT JOIN regions r ON l.region_id = r.region_id
                   WHERE l.location_id = :location_id";
$location_stmt = $db->prepare($location_query);
$location_stmt->bindParam(':location_id', $location_id);
$location_stmt->execute();
$location = $location_stmt->fetch();

if (!$location) {
    $_SESSION['error_message'] = '장소를 찾을 수 없습니다.';
    header('Location: index.php');
    exit;
}

// 수정할 수목 ID
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_tree = null;

if ($edit_id) {
    $edit_query = "SELECT * FROM location_trees WHERE location_tree_id = :id AND location_id = :location_id";
    $edit_stmt = $db->prepare($edit_query);
    $edit_stmt->bindParam(':id', $edit_id);
    $edit_stmt->bindParam(':location_id', $location_id);
    $edit_stmt->execute();
    $edit_tree = $edit_stmt->fetch();
}

// 삭제 처리
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    $delete_query = "DELETE FROM location_trees WHERE location_tree_id = :id AND location_id = :location_id";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->bindParam(':id', $delete_id);
    $delete_stmt->bindParam(':location_id', $location_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['success_message'] = '수목이 삭제되었습니다.';
    } else {
        $_SESSION['error_message'] = '삭제 중 오류가 발생했습니다.';
    }
    header("Location: manage_trees.php?location_id=$location_id");
    exit;
}

// 폼 제출 처리
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	$species_id = (int)$_POST['species_id'];
    $quantity = (int)$_POST['quantity'];
    $size_spec = trim((string)$_POST['size_spec']);
    $average_height = ($_POST['average_height'] !== '' ? (float)$_POST['average_height'] : null);
    $average_diameter = ($_POST['average_diameter'] !== '' ? (float)$_POST['average_diameter'] : null);
    $root_diameter = ($_POST['root_diameter'] !== '' ? (float)$_POST['root_diameter'] : null);
    $notes = trim((string)$_POST['notes']);
    
    try {

        $co2 = calcCo2Payload($db, $species_id, $quantity, $average_height, $average_diameter);
		 
        if ($edit_id) {
            // 수정
            $update_query = "UPDATE location_trees SET
                            species_id = :species_id,
                            quantity = :quantity,
                            size_spec = :size_spec,
                            average_height = :average_height,
                            average_diameter = :average_diameter,
                            root_diameter = :root_diameter,
                            notes = :notes,
							co2_stock_kg = :co2_stock_kg,
                            co2_annual_kg = :co2_annual_kg,
                            co2_calc_method = :co2_calc_method,
                            co2_calc_note = :co2_calc_note,
                            updated_at = NOW()
                            WHERE location_tree_id = :id AND location_id = :location_id";

           
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':species_id', $species_id);
            $update_stmt->bindParam(':quantity', $quantity);
            $update_stmt->bindParam(':size_spec', $size_spec);
            $update_stmt->bindParam(':average_height', $average_height);
            $update_stmt->bindParam(':average_diameter', $average_diameter);
            $update_stmt->bindParam(':root_diameter', $root_diameter);
            $update_stmt->bindParam(':notes', $notes);
			$update_stmt->bindValue(':co2_stock_kg', $co2['co2_stock_kg']);
            $update_stmt->bindValue(':co2_annual_kg', $co2['co2_annual_kg']);
            $update_stmt->bindValue(':co2_calc_method', $co2['method']);
            $update_stmt->bindValue(':co2_calc_note', $co2['note']);
            $update_stmt->bindParam(':id', $edit_id);
            $update_stmt->bindParam(':location_id', $location_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['success_message'] = '수목 정보가 수정되었습니다.';
                header("Location: manage_trees.php?location_id=$location_id");
                exit;
            }
        } else {
            // 추가
            $insert_query = "INSERT INTO location_trees
                            (location_id, species_id, quantity, size_spec, average_height,
                             average_diameter, root_diameter, notes,
                             co2_stock_kg, co2_annual_kg, co2_calc_method, co2_calc_note,created_at, updated_at)
                                
                             VALUES
                            (:location_id, :species_id, :quantity, :size_spec, :average_height,
                             :average_diameter, :root_diameter, :notes,
                             :co2_stock_kg, :co2_annual_kg, :co2_calc_method, :co2_calc_note, NOW(), NOW())";
 
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':location_id', $location_id);
            $insert_stmt->bindParam(':species_id', $species_id);
            $insert_stmt->bindParam(':quantity', $quantity);
            $insert_stmt->bindParam(':size_spec', $size_spec);
            $insert_stmt->bindParam(':average_height', $average_height);
            $insert_stmt->bindParam(':average_diameter', $average_diameter);
            $insert_stmt->bindParam(':root_diameter', $root_diameter);
            $insert_stmt->bindParam(':notes', $notes);
			$insert_stmt->bindValue(':co2_stock_kg', $co2['co2_stock_kg']);
            $insert_stmt->bindValue(':co2_annual_kg', $co2['co2_annual_kg']);
            $insert_stmt->bindValue(':co2_calc_method', $co2['method']);
            $insert_stmt->bindValue(':co2_calc_note', $co2['note']);

            
            if ($insert_stmt->execute()) {
                $_SESSION['success_message'] = '수목이 추가되었습니다.';
                header("Location: manage_trees.php?location_id=$location_id");
                exit;
            }
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 수종 목록 조회
$species_query = "SELECT species_id, korean_name, scientific_name 
                  FROM tree_species_master 
                  ORDER BY korean_name ASC";
$species_stmt = $db->prepare($species_query);
$species_stmt->execute();
$species_list = $species_stmt->fetchAll();

// 현재 장소의 수목 목록 조회
$trees_query = "SELECT 
                lt.*,
                s.korean_name,
                s.scientific_name
                FROM location_trees lt
                JOIN tree_species_master s ON lt.species_id = s.species_id
                WHERE lt.location_id = :location_id
                ORDER BY lt.quantity DESC, s.korean_name ASC";

$trees_stmt = $db->prepare($trees_query);
$trees_stmt->bindParam(':location_id', $location_id);
$trees_stmt->execute();
$trees = $trees_stmt->fetchAll();

$total_co2_stock = array_sum(array_map(fn($t) => (float)($t['co2_stock_kg'] ?? 0), $trees));
$total_co2_annual = array_sum(array_map(fn($t) => (float)($t['co2_annual_kg'] ?? 0), $trees));


$page_title = '수목 관리';
include '../../includes/header.php';
?>

<style>
/* 추가 스타일 */
.breadcrumb {
    color: #7f8c8d;
    font-size: 14px;
    margin-bottom: 10px;
}

.breadcrumb a {
    color: #3498db;
    text-decoration: none;
}

.breadcrumb a:hover {
    text-decoration: underline;
}

.content-grid {
    display: grid;
    grid-template-columns: 450px 1fr;
    gap: 20px;
}

.form-panel {
    background: white;
    border-radius: 10px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    height: fit-content;
    position: sticky;
    top: 20px;
}

.list-panel {
    background: white;
    border-radius: 10px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.hint-text {
    font-size: 12px;
    color: #7f8c8d;
    margin-top: 5px;
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 25px;
}

.form-actions .btn {
    flex: 1;
}

.tree-list {
    max-height: 600px;
    overflow-y: auto;
}

.tree-item {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    transition: all 0.2s;
}

.tree-item:hover {
    border-color: #3498db;
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.1);
}

.tree-item.editing {
    border-color: #f39c12;
    background: #fef5e7;
}

.tree-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 15px;
}

.tree-species {
    font-size: 16px;
    font-weight: 700;
    color: #27ae60;
    margin-bottom: 3px;
}

.tree-scientific {
    font-size: 12px;
    color: #7f8c8d;
    font-style: italic;
}

.tree-actions {
    display: flex;
    gap: 8px;
}

.tree-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.detail-label {
    font-size: 12px;
    color: #7f8c8d;
    font-weight: 600;
}

.detail-value {
    font-size: 14px;
    color: #2c3e50;
    font-weight: 600;
}

.quantity-badge {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 16px;
}

.size-badge {
    background: #fef3c7;
    color: #92400e;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-family: monospace;
}

.tree-notes {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #f0f0f0;
    font-size: 13px;
    color: #7f8c8d;
    line-height: 1.5;
}

.summary-box {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 25px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #e0e0e0;
}

.summary-row:last-child {
    border-bottom: none;
}

.summary-label {
    font-weight: 600;
    color: #7f8c8d;
}

.summary-value {
    font-weight: 700;
    color: #2c3e50;
    font-size: 18px;
}
</style>

<div class="page-header">
    <div>
        <h2>🌳 수목 관리</h2>
        <div class="breadcrumb">
            <a href="index.php">장소 목록</a> &gt; 
            <a href="view.php?id=<?php echo $location_id; ?>"><?php echo htmlspecialchars($location['location_name']); ?></a> &gt; 
            수목 관리
        </div>
    </div>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert-success">
    <?php 
    echo $_SESSION['success_message'];
    unset($_SESSION['success_message']);
    ?>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
<div class="alert alert-error">
    <?php 
    echo $_SESSION['error_message'];
    unset($_SESSION['error_message']);
    ?>
</div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
<div class="alert alert-error"><?php echo $error_message; ?></div>
<?php endif; ?>

<div class="content-grid">
    <!-- 왼쪽: 입력 폼 -->
    <div class="form-panel">
        <h3 class="card-header" style="margin-bottom: 20px;">
            <?php echo $edit_id ? '✏️ 수목 수정' : '➕ 수목 추가'; ?>
        </h3>

        <form method="POST" action="">
            <div class="form-group">
                <label>수종 <span style="color: #e74c3c;">*</span></label>
                <select name="species_id" required>
                    <option value="">-- 수종 선택 --</option>
                    <?php foreach ($species_list as $species): ?>
                    <option value="<?php echo $species['species_id']; ?>"
                            <?php echo ($edit_tree && $edit_tree['species_id'] == $species['species_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($species['korean_name']); ?>
                        <?php if ($species['scientific_name']): ?>
                            (<?php echo htmlspecialchars($species['scientific_name']); ?>)
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>수량(주) <span style="color: #e74c3c;">*</span></label>
                <input type="number" name="quantity" min="1" required
                       value="<?php echo $edit_tree ? $edit_tree['quantity'] : ''; ?>"
                       placeholder="예: 100">
                <div class="hint-text">단위: 주(株)</div>
            </div>

            <div class="form-group">
                <label>규격</label>
                <input type="text" name="size_spec"
                       value="<?php echo $edit_tree ? htmlspecialchars($edit_tree['size_spec']) : ''; ?>"
                       placeholder="예: W110, H0.5 / R9, H2">
                <div class="hint-text">예: W110, H0.5 (수관폭 110cm, 높이 0.5m)</div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>평균 높이(m)</label>
                    <input type="number" name="average_height" step="0.01"
                           value="<?php echo $edit_tree ? $edit_tree['average_height'] : ''; ?>"
                           placeholder="예: 2.5">
                </div>

                <div class="form-group">
                    <label>평균 직경(cm)</label>
                    <input type="number" name="average_diameter" step="0.01"
                           value="<?php echo $edit_tree ? $edit_tree['average_diameter'] : ''; ?>"
                           placeholder="예: 10">
                </div>
            </div>

            <div class="form-group">
                <label>근원직경(cm)</label>
                <input type="number" name="root_diameter" step="0.01"
                       value="<?php echo $edit_tree ? $edit_tree['root_diameter'] : ''; ?>"
                       placeholder="예: 15">
                <div class="hint-text">지표면 기준 직경</div>
            </div>

            <div class="form-group">
                <label>비고</label>
                <textarea name="notes" placeholder="추가 정보 입력"><?php echo $edit_tree ? htmlspecialchars($edit_tree['notes']) : ''; ?></textarea>
            </div>

            <div class="form-actions">
                <?php if ($edit_id): ?>
                <a href="manage_trees.php?location_id=<?php echo $location_id; ?>" class="btn btn-secondary">
                    취소
                </a>
                <button type="submit" class="btn btn-primary">
                    ✏️ 수정
                </button>
                <?php else: ?>
                <button type="reset" class="btn btn-secondary">
                    초기화
                </button>
                <button type="submit" class="btn btn-success">
                    ➕ 추가
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- 오른쪽: 수목 목록 -->
    <div class="list-panel">
        <h3 class="card-header" style="margin-bottom: 20px;">
            📋 등록된 수목 (<?php echo count($trees); ?>종)
        </h3>

        <?php if (count($trees) > 0): ?>
        <!-- 요약 정보 -->
        <div class="summary-box">
            <div class="summary-row">
                <div class="summary-label">총 수종 수</div>
                <div class="summary-value"><?php echo number_format(count($trees)); ?>종</div>
            </div>
            <div class="summary-row">
                <div class="summary-label">총 나무 수</div>
                <div class="summary-value">
                    <?php 
                    $total_quantity = array_sum(array_column($trees, 'quantity'));
                    echo number_format($total_quantity); 
                    ?>주
                </div>
            </div>
        </div>

<div class="summary-row">
  <div class="summary-label">총 CO₂ 저장량(추정)</div>
  <div class="summary-value"><?php echo number_format($total_co2_stock, 2); ?> kg</div>
</div>
<div class="summary-row">
  <div class="summary-label">연간 CO₂ 흡수량(추정)</div>
  <div class="summary-value"><?php echo number_format($total_co2_annual, 2); ?> kg/yr</div>
</div>


        <div class="tree-list">
            <?php foreach ($trees as $tree): ?>
            <div class="tree-item <?php echo ($edit_id == $tree['location_tree_id']) ? 'editing' : ''; ?>">
                <div class="tree-header">
                    <div>
                        <div class="tree-species">
                            <?php echo htmlspecialchars($tree['korean_name']); ?>
                        </div>
                        <?php if ($tree['scientific_name']): ?>
                        <div class="tree-scientific">
                            <?php echo htmlspecialchars($tree['scientific_name']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="tree-actions">
                        <a href="manage_trees.php?location_id=<?php echo $location_id; ?>&edit=<?php echo $tree['location_tree_id']; ?>" 
                           class="btn btn-sm btn-primary">✏️</a>
                        <a href="manage_trees.php?location_id=<?php echo $location_id; ?>&delete=<?php echo $tree['location_tree_id']; ?>" 
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('정말 삭제하시겠습니까?')">🗑️</a>
                    </div>
                </div>

                <div class="tree-details">
                    <div class="detail-item">
                        <div class="detail-label">수량:</div>
                        <div class="quantity-badge"><?php echo number_format($tree['quantity']); ?>주</div>
                    </div>

                    <?php if ($tree['size_spec']): ?>
                    <div class="detail-item">
                        <div class="detail-label">규격:</div>
                        <div class="size-badge"><?php echo htmlspecialchars($tree['size_spec']); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if ($tree['average_height']): ?>
                    <div class="detail-item">
                        <div class="detail-label">평균 높이:</div>
                        <div class="detail-value"><?php echo number_format($tree['average_height'], 2); ?>m</div>
                    </div>
                    <?php endif; ?>

                    <?php if ($tree['average_diameter']): ?>
                    <div class="detail-item">
                        <div class="detail-label">평균 직경:</div>
                        <div class="detail-value"><?php echo number_format($tree['average_diameter'], 2); ?>cm</div>
                    </div>
                    <?php endif; ?>

                    <?php if ($tree['root_diameter']): ?>
                    <div class="detail-item">
                        <div class="detail-label">근원직경:</div>
                        <div class="detail-value"><?php echo number_format($tree['root_diameter'], 2); ?>cm</div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($tree['co2_stock_kg'])): ?>
<div class="detail-item">
  <div class="detail-label">CO₂ 저장:</div>
  <div class="detail-value"><?php echo number_format($tree['co2_stock_kg'], 2); ?> kg</div>
</div>
<?php endif; ?>

<?php if (!empty($tree['co2_annual_kg'])): ?>
<div class="detail-item">
  <div class="detail-label">CO₂ 연간흡수:</div>
  <div class="detail-value"><?php echo number_format($tree['co2_annual_kg'], 2); ?> kg/yr</div>
</div>
<?php endif; ?>



                </div>

                <?php if ($tree['notes']): ?>
                <div class="tree-notes">
                    📝 <?php echo nl2br(htmlspecialchars($tree['notes'])); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state" style="text-align: center; padding: 60px 20px; color: #95a5a6;">
            <div style="font-size: 64px; margin-bottom: 20px;">🌱</div>
            <div style="font-size: 18px; font-weight: 600; margin-bottom: 10px;">
                아직 등록된 수목이 없습니다
            </div>
            <div style="font-size: 14px; color: #7f8c8d;">
                왼쪽 폼을 사용하여 첫 번째 수목을 추가해주세요
            </div>
        </div>
        <?php endif; ?>

        <div style="margin-top: 25px; text-align: center;">
            <a href="view.php?id=<?php echo $location_id; ?>" class="btn btn-secondary">
                ← 장소 상세보기로 돌아가기
            </a>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
