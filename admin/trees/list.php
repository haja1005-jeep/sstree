<?php
/**
 * 나무 목록
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '나무 관리';

$database = new Database();
$db = $database->getConnection();

// 삭제 처리
if (isset($_GET['delete']) && isAdmin()) {
    $tree_id = (int)$_GET['delete'];
    
    try {
        // 관련 사진 삭제
        $photo_query = "SELECT file_path FROM tree_photos WHERE tree_id = :tree_id";
        $photo_stmt = $db->prepare($photo_query);
        $photo_stmt->bindParam(':tree_id', $tree_id);
        $photo_stmt->execute();
        $photos = $photo_stmt->fetchAll();
        
        foreach ($photos as $photo) {
            $file_path = BASE_PATH . '/' . $photo['file_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        // 나무 삭제 (CASCADE로 사진도 자동 삭제)
        $query = "DELETE FROM trees WHERE tree_id = :tree_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':tree_id', $tree_id);
        $stmt->execute();
        
        logActivity($_SESSION['user_id'], 'delete', 'tree', $tree_id, '나무 데이터 삭제');
        
        $success_message = '나무가 삭제되었습니다.';
    } catch (Exception $e) {
        $error_message = '나무 삭제 중 오류가 발생했습니다: ' . $e->getMessage();
    }
}

// 검색 및 필터
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$region_filter = isset($_GET['region']) ? (int)$_GET['region'] : 0;
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : 0;
$species_filter = isset($_GET['species']) ? (int)$_GET['species'] : 0;
$health_filter = isset($_GET['health']) ? sanitize($_GET['health']) : '';

// 페이징 설정
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = ITEMS_PER_PAGE;
$offset = ($page - 1) * $items_per_page;

// 나무 목록 조회
$query = "SELECT t.*, 
          r.region_name, 
          c.category_name, 
          l.location_name,
          s.korean_name as species_name,
          (SELECT COUNT(*) FROM tree_photos WHERE tree_id = t.tree_id) as photo_count,
          u.name as creator_name
          FROM trees t
          LEFT JOIN regions r ON t.region_id = r.region_id
          LEFT JOIN categories c ON t.category_id = c.category_id
          LEFT JOIN locations l ON t.location_id = l.location_id
          LEFT JOIN tree_species_master s ON t.species_id = s.species_id
          LEFT JOIN users u ON t.created_by = u.user_id
          WHERE 1=1";

if ($search) {
    $query .= " AND (t.tree_number LIKE :search OR s.korean_name LIKE :search OR l.location_name LIKE :search)";
}
if ($region_filter > 0) {
    $query .= " AND t.region_id = :region_id";
}
if ($category_filter > 0) {
    $query .= " AND t.category_id = :category_id";
}
if ($location_filter > 0) {
    $query .= " AND t.location_id = :location_id";
}
if ($species_filter > 0) {
    $query .= " AND t.species_id = :species_id";
}
if ($health_filter) {
    $query .= " AND t.health_status = :health_status";
}

$query .= " ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($query);

if ($search) {
    $search_param = "%{$search}%";
    $stmt->bindParam(':search', $search_param);
}
if ($region_filter > 0) {
    $stmt->bindParam(':region_id', $region_filter);
}
if ($category_filter > 0) {
    $stmt->bindParam(':category_id', $category_filter);
}
if ($location_filter > 0) {
    $stmt->bindParam(':location_id', $location_filter);
}
if ($species_filter > 0) {
    $stmt->bindParam(':species_id', $species_filter);
}
if ($health_filter) {
    $stmt->bindParam(':health_status', $health_filter);
}

$stmt->bindParam(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$trees = $stmt->fetchAll();

// 전체 개수 조회
$count_query = "SELECT COUNT(*) as total FROM trees t WHERE 1=1";
if ($search) $count_query .= " AND (t.tree_number LIKE :search OR EXISTS(SELECT 1 FROM tree_species_master s WHERE s.species_id = t.species_id AND s.korean_name LIKE :search))";
if ($region_filter > 0) $count_query .= " AND t.region_id = :region_id";
if ($category_filter > 0) $count_query .= " AND t.category_id = :category_id";
if ($location_filter > 0) $count_query .= " AND t.location_id = :location_id";
if ($species_filter > 0) $count_query .= " AND t.species_id = :species_id";
if ($health_filter) $count_query .= " AND t.health_status = :health_status";

$count_stmt = $db->prepare($count_query);
if ($search) $count_stmt->bindParam(':search', $search_param);
if ($region_filter > 0) $count_stmt->bindParam(':region_id', $region_filter);
if ($category_filter > 0) $count_stmt->bindParam(':category_id', $category_filter);
if ($location_filter > 0) $count_stmt->bindParam(':location_id', $location_filter);
if ($species_filter > 0) $count_stmt->bindParam(':species_id', $species_filter);
if ($health_filter) $count_stmt->bindParam(':health_status', $health_filter);
$count_stmt->execute();
$total_items = $count_stmt->fetch()['total'];
$total_pages = ceil($total_items / $items_per_page);

// 필터 옵션 데이터
$regions_query = "SELECT * FROM regions ORDER BY region_name";
$regions_stmt = $db->prepare($regions_query);
$regions_stmt->execute();
$regions = $regions_stmt->fetchAll();

$categories_query = "SELECT * FROM categories ORDER BY category_name";
$categories_stmt = $db->prepare($categories_query);
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll();

$locations_query = "SELECT * FROM locations ORDER BY location_name";
$locations_stmt = $db->prepare($locations_query);
$locations_stmt->execute();
$locations = $locations_stmt->fetchAll();

$species_query = "SELECT * FROM tree_species_master ORDER BY korean_name";
$species_stmt = $db->prepare($species_query);
$species_stmt->execute();
$species_list = $species_stmt->fetchAll();

require_once '../../includes/header.php';
?>

<style>
.health-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
.health-excellent { background: #d1fae5; color: #065f46; }
.health-good { background: #dbeafe; color: #1e40af; }
.health-fair { background: #fef3c7; color: #92400e; }
.health-poor { background: #fee2e2; color: #991b1b; }
.health-dead { background: #f3f4f6; color: #4b5563; }

.pagination {
    display: flex;
    gap: 5px;
    justify-content: center;
    margin-top: 20px;
}
.pagination a, .pagination span {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
    text-decoration: none;
    color: #333;
}
.pagination a:hover {
    background: #f0f0f0;
}
.pagination .active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: #667eea;
}
</style>

<?php if (isset($success_message)): ?>
    <div class="alert alert-success"><?php echo $success_message; ?></div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="alert alert-error"><?php echo $error_message; ?></div>
<?php endif; ?>

<!-- 검색 및 필터 -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body">
        <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label for="search">검색</label>
                <input type="text" 
                       id="search" 
                       name="search" 
                       placeholder="나무번호, 수종명, 장소명"
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label for="region">지역</label>
                <select id="region" name="region">
                    <option value="0">전체</option>
                    <?php foreach ($regions as $region): ?>
                        <option value="<?php echo $region['region_id']; ?>" 
                                <?php echo $region_filter == $region['region_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($region['region_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label for="category">카테고리</label>
                <select id="category" name="category">
                    <option value="0">전체</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['category_id']; ?>"
                                <?php echo $category_filter == $category['category_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label for="species">수종</label>
                <select id="species" name="species">
                    <option value="0">전체</option>
                    <?php foreach ($species_list as $species): ?>
                        <option value="<?php echo $species['species_id']; ?>"
                                <?php echo $species_filter == $species['species_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($species['korean_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label for="health">건강상태</label>
                <select id="health" name="health">
                    <option value="">전체</option>
                    <option value="excellent" <?php echo $health_filter == 'excellent' ? 'selected' : ''; ?>>최상</option>
                    <option value="good" <?php echo $health_filter == 'good' ? 'selected' : ''; ?>>양호</option>
                    <option value="fair" <?php echo $health_filter == 'fair' ? 'selected' : ''; ?>>보통</option>
                    <option value="poor" <?php echo $health_filter == 'poor' ? 'selected' : ''; ?>>나쁨</option>
                    <option value="dead" <?php echo $health_filter == 'dead' ? 'selected' : ''; ?>>고사</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">🔍 검색</button>
                <a href="list.php" class="btn btn-secondary">초기화</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">🌳 나무 목록 (총 <?php echo number_format($total_items); ?>그루)</h3>
        <div style="display: flex; gap: 10px;">
            <a href="add.php" class="btn btn-primary">➕ 나무 추가</a>
            <a href="map.php" class="btn btn-success">🗺️ 지도 보기</a>
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>나무번호</th>
                        <th>수종</th>
                        <th>지역</th>
                        <th>장소</th>
                        <th>높이(m)</th>
                        <th>직경(cm)</th>
                        <th>건강상태</th>
                        <th>사진</th>
                        <th>등록일</th>
                        <th>관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($trees) > 0): ?>
                        <?php foreach ($trees as $tree): ?>
                            <tr>
                                <td style="font-weight: 600;">
                                    <a href="view.php?id=<?php echo $tree['tree_id']; ?>" 
                                       style="color: var(--primary-color); text-decoration: none;">
                                        <?php echo htmlspecialchars($tree['tree_number'] ?: '-'); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($tree['species_name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($tree['region_name'] ?: '-'); ?></td>
                                <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo htmlspecialchars($tree['location_name'] ?: '-'); ?>
                                </td>
                                <td><?php echo $tree['height'] ? number_format($tree['height'], 2) : '-'; ?></td>
                                <td><?php echo $tree['diameter'] ? number_format($tree['diameter'], 2) : '-'; ?></td>
                                <td>
                                    <?php
                                    $health_classes = [
                                        'excellent' => 'health-excellent',
                                        'good' => 'health-good',
                                        'fair' => 'health-fair',
                                        'poor' => 'health-poor',
                                        'dead' => 'health-dead'
                                    ];
                                    $health_labels = [
                                        'excellent' => '최상',
                                        'good' => '양호',
                                        'fair' => '보통',
                                        'poor' => '나쁨',
                                        'dead' => '고사'
                                    ];
                                    $health = $tree['health_status'] ?: 'good';
                                    ?>
                                    <span class="health-badge <?php echo $health_classes[$health]; ?>">
                                        <?php echo $health_labels[$health]; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($tree['photo_count'] > 0): ?>
                                        📷 <?php echo $tree['photo_count']; ?>장
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($tree['created_at'])); ?></td>
                                <td>
                                    <a href="view.php?id=<?php echo $tree['tree_id']; ?>" class="btn btn-sm btn-success">보기</a>
                                    <a href="edit.php?id=<?php echo $tree['tree_id']; ?>" class="btn btn-sm btn-secondary">수정</a>
                                    <?php if (isAdmin()): ?>
                                        <a href="?delete=<?php echo $tree['tree_id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('이 나무를 삭제하시겠습니까?\n연결된 모든 사진이 삭제됩니다.');">삭제</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">
                                등록된 나무가 없습니다.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 페이징 -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo $region_filter; ?>&category=<?php echo $category_filter; ?>&species=<?php echo $species_filter; ?>&health=<?php echo $health_filter; ?>">◀ 이전</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo $region_filter; ?>&category=<?php echo $category_filter; ?>&species=<?php echo $species_filter; ?>&health=<?php echo $health_filter; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo $region_filter; ?>&category=<?php echo $category_filter; ?>&species=<?php echo $species_filter; ?>&health=<?php echo $health_filter; ?>">다음 ▶</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">💡 나무 관리 안내</h3>
    </div>
    <div class="card-body">
        <ul style="list-style: none; padding: 0;">
            <li style="margin-bottom: 10px;">✓ 나무는 장소에 속하며, 수종과 건강상태 정보를 관리합니다.</li>
            <li style="margin-bottom: 10px;">✓ 나무별로 여러 장의 사진(전체/잎/수피/꽃/열매 등)을 등록할 수 있습니다.</li>
            <li style="margin-bottom: 10px;">✓ GPS 좌표를 입력하면 지도에서 위치를 확인할 수 있습니다.</li>
            <li style="margin-bottom: 10px;">✓ 건강상태는 주기적으로 업데이트하여 관리하세요.</li>
        </ul>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>