<?php
/**
 * 장소 목록
 * Smart Tree Map - Location Management
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '장소 관리';

// 데이터베이스 연결
$database = new Database();
$db = $database->getConnection();

// 검색 및 필터 파라미터
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$region_filter = isset($_GET['region']) ? $_GET['region'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

// 페이지네이션
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// WHERE 조건 구성
$where_conditions = ["1=1"];
$params = [];

if ($search) {
    // road_name 컬럼이 존재하는지 확인 필요하지만, 일단 포함
    $where_conditions[] = "(l.location_name LIKE :search OR l.address LIKE :search OR l.road_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($region_filter) {
    // [수정] c.region_id -> l.region_id 로 변경
    $where_conditions[] = "l.region_id = :region_id";
    $params[':region_id'] = $region_filter;
}

if ($category_filter) {
    // [수정] l.category_id 사용 (이미 맞음)
    $where_conditions[] = "l.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if ($type_filter) {
    $where_conditions[] = "l.location_type = :location_type";
    $params[':location_type'] = $type_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// 전체 개수 조회
// [수정] location_categories -> categories, regions JOIN 조건 변경
$count_query = "SELECT COUNT(*) as total
                FROM locations l
                LEFT JOIN categories c ON l.category_id = c.category_id
                LEFT JOIN regions r ON l.region_id = r.region_id
                WHERE $where_clause";

$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $per_page);

// 장소 목록 조회 (수목 수 포함)
// [수정] 테이블명 및 조인 조건 수정
$query = "SELECT 
            l.location_id,
            l.location_name,
            l.address,
            l.area,
            l.road_name,
            l.length,
            l.location_type,
            c.category_name,
            r.region_name,
            COUNT(DISTINCT lt.species_id) as species_count,
            COALESCE(SUM(lt.quantity), 0) as total_trees
          FROM locations l
          LEFT JOIN categories c ON l.category_id = c.category_id
          LEFT JOIN regions r ON l.region_id = r.region_id
          LEFT JOIN location_trees lt ON l.location_id = lt.location_id
          WHERE $where_clause
          GROUP BY l.location_id
          ORDER BY l.location_id DESC
          LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$locations = $stmt->fetchAll();

// 필터용 데이터
$regions_query = "SELECT * FROM regions ORDER BY region_name";
$regions = $db->query($regions_query)->fetchAll();

// [수정] 카테고리 쿼리 단순화 (regions와 조인 제거)
$categories_query = "SELECT * FROM categories ORDER BY category_name";
$categories = $db->query($categories_query)->fetchAll();

include '../../includes/header.php';
?>

<style>
.location-filters {
    background: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.filter-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.filter-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #374151;
    font-size: 14px;
}

.filter-group input,
.filter-group select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}

.filter-actions {
    display: flex;
    gap: 10px;
}

.location-stats {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 14px;
    opacity: 0.9;
}

.location-table {
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.location-table table {
    width: 100%;
    border-collapse: collapse;
}

.location-table thead {
    background: #f9fafb;
}

.location-table th {
    padding: 15px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.location-table td {
    padding: 15px;
    border-bottom: 1px solid #e5e7eb;
    color: #6b7280;
}

.location-table tbody tr:hover {
    background: #f9fafb;
}

.location-type-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.type-urban_forest { background: #dcfce7; color: #166534; }
.type-street_tree { background: #dbeafe; color: #1e40af; }
.type-living_forest { background: #fef3c7; color: #92400e; }
.type-school { background: #fce7f3; color: #9f1239; }
.type-park { background: #e0e7ff; color: #3730a3; }
.type-other { background: #f3f4f6; color: #374151; }

.tree-count {
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
}

.tree-count .count {
    color: #059669;
}

.tree-count .species {
    color: #7c3aed;
    font-size: 12px;
}

.action-buttons {
    display: flex;
    gap: 5px;
}

.btn-icon {
    padding: 6px 10px;
    font-size: 12px;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin-top: 20px;
    padding: 20px;
    background: white;
    border-radius: 10px;
}

.pagination a,
.pagination span {
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 5px;
    text-decoration: none;
    color: #374151;
}

.pagination a:hover {
    background: #f9fafb;
}

.pagination .current {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
}

.empty-state-icon {
    font-size: 64px;
    margin-bottom: 20px;
}
</style>

<div class="page-header">
    <div>
        <h2>📍 장소 관리</h2>
        <p>나무가 심어진 장소를 관리합니다</p>
    </div>
    <a href="add.php" class="btn btn-primary">
        ➕ 새 장소 추가
    </a>
</div>

<div class="location-stats">
    <div class="stats-grid">
        <div class="stat-item">
            <div class="stat-value"><?php echo number_format($total_records); ?></div>
            <div class="stat-label">전체 장소</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">
                <?php
                // [수정] location_trees 테이블 사용
                $total_trees_query = "SELECT SUM(quantity) as total FROM location_trees";
                $total_trees = $db->query($total_trees_query)->fetch()['total'] ?? 0;
                echo number_format($total_trees);
                ?>
            </div>
            <div class="stat-label">전체 나무</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">
                <?php
                // [수정] location_trees 테이블 사용
                $total_species_query = "SELECT COUNT(DISTINCT species_id) as total FROM location_trees";
                $total_species = $db->query($total_species_query)->fetch()['total'] ?? 0;
                echo number_format($total_species);
                ?>
            </div>
            <div class="stat-label">수종 종류</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">
                <?php
                $area_query = "SELECT SUM(area) as total FROM locations WHERE area IS NOT NULL";
                $area = $db->query($area_query)->fetch()['total'] ?? 0;
                echo number_format($area);
                ?>
            </div>
            <div class="stat-label">총 면적 (㎡)</div>
        </div>
    </div>
</div>

<div class="location-filters">
    <form method="GET" action="">
        <div class="filter-row">
            <div class="filter-group">
                <label>🔍 검색</label>
                <input type="text" name="search" placeholder="장소명, 주소, 도로명 검색..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="filter-group">
                <label>🗺️ 지역</label>
                <select name="region" onchange="this.form.submit()">
                    <option value="">전체 지역</option>
                    <?php foreach ($regions as $region): ?>
                        <option value="<?php echo $region['region_id']; ?>"
                                <?php echo $region_filter == $region['region_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($region['region_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>📁 카테고리</label>
                <select name="category" onchange="this.form.submit()">
                    <option value="">전체 카테고리</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['category_id']; ?>"
                                <?php echo $category_filter == $category['category_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>🏷️ 유형</label>
                <select name="type" onchange="this.form.submit()">
                    <option value="">전체 유형</option>
                    <option value="urban_forest" <?php echo $type_filter == 'urban_forest' ? 'selected' : ''; ?>>도시숲</option>
                    <option value="street_tree" <?php echo $type_filter == 'street_tree' ? 'selected' : ''; ?>>가로수</option>
                    <option value="living_forest" <?php echo $type_filter == 'living_forest' ? 'selected' : ''; ?>>생활숲</option>
                    <option value="school" <?php echo $type_filter == 'school' ? 'selected' : ''; ?>>학교</option>
                    <option value="park" <?php echo $type_filter == 'park' ? 'selected' : ''; ?>>공원</option>
                    <option value="other" <?php echo $type_filter == 'other' ? 'selected' : ''; ?>>기타</option>
                </select>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">검색</button>
                <a href="index.php" class="btn btn-secondary">초기화</a>
            </div>
        </div>
    </form>
</div>

<div class="location-table">
    <?php if (count($locations) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th>장소명</th>
                    <th>유형</th>
                    <th>카테고리</th>
                    <th>지역</th>
                    <th>면적/거리</th>
                    <th>수목 현황</th>
                    <th style="width: 150px;">관리</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locations as $location): ?>
                    <tr>
                        <td><?php echo $location['location_id']; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($location['location_name']); ?></strong>
                            <?php if ($location['address']): ?>
                                <br><small style="color: #9ca3af;"><?php echo htmlspecialchars($location['address']); ?></small>
                            <?php endif; ?>
                            <?php if ($location['road_name']): ?>
                                <br><small style="color: #9ca3af;">🛣️ <?php echo htmlspecialchars($location['road_name']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $type_labels = [
                                'urban_forest' => '도시숲',
                                'street_tree' => '가로수',
                                'living_forest' => '생활숲',
                                'school' => '학교',
                                'park' => '공원',
                                'other' => '기타'
                            ];
                            $type = $location['location_type'] ?? 'other';
                            ?>
                            <span class="location-type-badge type-<?php echo $type; ?>">
                                <?php echo $type_labels[$type] ?? '기타'; ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($location['category_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($location['region_name'] ?? '-'); ?></td>
                        <td>
                            <?php if ($location['area']): ?>
                                📐 <?php echo number_format($location['area'], 0); ?>㎡
                            <?php elseif ($location['length']): ?>
                                📏 <?php echo number_format($location['length'], 0); ?>m
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($location['total_trees'] > 0): ?>
                                <div class="tree-count">
                                    <span class="count">🌳 <?php echo number_format($location['total_trees']); ?>주</span>
                                    <span class="species">(<?php echo $location['species_count']; ?>종)</span>
                                </div>
                            <?php else: ?>
                                <span style="color: #d1d5db;">수목 없음</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="view.php?id=<?php echo $location['location_id']; ?>" 
                                   class="btn btn-info btn-icon" title="상세보기">
                                    👁️
                                </a>
                                <a href="edit.php?id=<?php echo $location['location_id']; ?>" 
                                   class="btn btn-primary btn-icon" title="수정">
                                    ✏️
                                </a>
                                <a href="delete.php?id=<?php echo $location['location_id']; ?>" 
                                   class="btn btn-danger btn-icon" 
                                   onclick="return confirm('정말 삭제하시겠습니까?\n관련된 수목 데이터도 모두 삭제됩니다.');"
                                   title="삭제">
                                    🗑️
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">📍</div>
            <h3>장소가 없습니다</h3>
            <p>새로운 장소를 추가해주세요.</p>
            <a href="add.php" class="btn btn-primary" style="margin-top: 20px;">
                ➕ 첫 장소 추가하기
            </a>
        </div>
    <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo $region_filter; ?>&category=<?php echo $category_filter; ?>&type=<?php echo $type_filter; ?>">
                ← 이전
            </a>
        <?php endif; ?>
        
        <?php
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        
        for ($i = $start_page; $i <= $end_page; $i++):
        ?>
            <?php if ($i == $page): ?>
                <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo $region_filter; ?>&category=<?php echo $category_filter; ?>&type=<?php echo $type_filter; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo $region_filter; ?>&category=<?php echo $category_filter; ?>&type=<?php echo $type_filter; ?>">
                다음 →
            </a>
        <?php endif; ?>
        
        <span style="margin-left: 20px; color: #6b7280;">
            전체 <?php echo number_format($total_records); ?>개 중 
            <?php echo number_format($offset + 1); ?>~<?php echo number_format(min($offset + $per_page, $total_records)); ?>
        </span>
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>