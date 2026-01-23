<?php
/**
 * 장소 관리 (Modern UI 적용)
 * Smart Tree Map - Location Management
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '장소 관리';

// 데이터베이스 연결
$database = new Database();
$db = $database->getConnection();

// 검색 및 필터 파라미터 처리
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$region_filter = isset($_GET['region']) ? $_GET['region'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

// 페이지네이션 설정
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// WHERE 조건 구성
$where_conditions = ["1=1"];
$params = [];

if ($search) {
    $where_conditions[] = "(l.location_name LIKE :search OR l.address LIKE :search OR l.road_name LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($region_filter) {
    $where_conditions[] = "l.region_id = :region_id";
    $params[':region_id'] = $region_filter;
}
if ($category_filter) {
    $where_conditions[] = "l.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}
if ($type_filter) {
    $where_conditions[] = "l.location_type = :location_type";
    $params[':location_type'] = $type_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// 전체 개수 조회
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

// 장소 목록 조회
$query = "SELECT 
            l.location_id,
            l.location_name,
            l.address,
            l.area,
            l.road_name,
            l.length,
            l.location_type,
            l.created_at,
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

// 필터용 데이터 조회
$regions = $db->query("SELECT * FROM regions ORDER BY region_name")->fetchAll();
$categories = $db->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();

include '../../includes/header.php';
?>

<style>

/* 버튼 스타일 */
.btn { padding: 10px 18px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-size: 0.95rem; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
.btn-secondary { background: white; border: 1px solid var(--border); color: var(--text-main); }
.btn-secondary:hover { background: #F9FAFB; border-color: #9CA3AF; }
.btn-danger { background: #fee2e2; color: #991b1b; }
.btn-danger:hover { background: #fecaca; }
.btn-sm { padding: 6px 12px; font-size: 0.85rem; }

/* 테이블 스타일 */
.table-wrapper { overflow-x: auto; border-radius: 8px; border: 1px solid var(--border); }
table { width: 100%; border-collapse: collapse; background: white; }
th { background: #F9FAFB; padding: 16px; text-align: left; font-size: 0.85rem; font-weight: 600; color: var(--text-sub); text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; border-bottom: 1px solid var(--border); }
td { padding: 16px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text-main); font-size: 0.95rem; }
tr:hover td { background-color: #F0FDF4; }

/* 뱃지 스타일 */
.badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; }
.badge-info { background: #DBEAFE; color: #1E40AF; }
.type-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
.type-urban_forest { background: #dcfce7; color: #166534; }
.type-street_tree { background: #dbeafe; color: #1e40af; }
.type-living_forest { background: #fef3c7; color: #92400e; }
.type-school { background: #fce7f3; color: #9f1239; }
.type-park { background: #e0e7ff; color: #3730a3; }
.type-other { background: #f3f4f6; color: #374151; }

/* 페이지네이션 */
.pagination { display: flex; justify-content: center; gap: 5px; padding: 20px; }
.page-link { padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; color: var(--text-main); text-decoration: none; font-size: 0.9rem; }
.page-link:hover { background: #F9FAFB; }
.page-link.active { background: var(--primary); color: white; border-color: var(--primary); }
</style>

<div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h2 style="margin: 0; font-size: 1.75rem; font-weight: 800; color: #111827;">📍 장소 관리</h2>
        <p style="margin: 5px 0 0; color: #6B7280;">나무가 심어진 장소를 조회하고 관리합니다.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="add.php" class="btn btn-primary">
            <span style="font-size: 1.2em;">+</span> 새 장소 등록
        </a>
        <a href="<?= BASE_URL ?>/admin/locations/bulk_photo_upload.php" class="btn btn-secondary">
            📷 사진 일괄 업로드
        </a>

		<a href="#" class="btn btn-secondary" onclick="exportLocations()" class="btn btn-secondary">
            📥 엑셀 다운로드
        </a>
    </div>
</div>

<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <div class="card" style="margin: 0; padding: 20px; text-align: center;">
        <div style="font-size: 2rem; font-weight: 800; color: var(--primary); margin-bottom: 5px;"><?php echo number_format($total_records); ?></div>
        <div style="font-size: 0.9rem; color: var(--text-sub); font-weight: 600;">전체 장소</div>
    </div>
    <div class="card" style="margin: 0; padding: 20px; text-align: center;">
        <?php
        $total_trees_query = "SELECT SUM(quantity) as total FROM location_trees";
        $total_trees = $db->query($total_trees_query)->fetch()['total'] ?? 0;
        ?>
        <div style="font-size: 2rem; font-weight: 800; color: var(--secondary); margin-bottom: 5px;"><?php echo number_format($total_trees); ?></div>
        <div style="font-size: 0.9rem; color: var(--text-sub); font-weight: 600;">전체 나무</div>
    </div>
	<div class="card" style="margin: 0; padding: 20px; text-align: center;">
        <?php
        $total_species_query = "SELECT COUNT(DISTINCT species_id) as total FROM location_trees";
        $total_species = $db->query($total_species_query)->fetch()['total'] ?? 0;
        ?>
        <div style="font-size: 2rem; font-weight: 800; color: #F59E0B; margin-bottom: 5px;">
            <?php echo number_format($total_species); ?>
        </div>
        <div style="font-size: 0.9rem; color: var(--text-sub); font-weight: 600;">전체 수종</div>
    </div>
    <div class="card" style="margin: 0; padding: 20px; text-align: center;">
        <?php
        $area_query = "SELECT SUM(area) as total FROM locations WHERE area IS NOT NULL";
        $area = $db->query($area_query)->fetch()['total'] ?? 0;
        ?>
        <div style="font-size: 2rem; font-weight: 800; color: #8B5CF6; margin-bottom: 5px;"><?php echo number_format($area); ?></div>
        <div style="font-size: 0.9rem; color: var(--text-sub); font-weight: 600;">총 면적 (㎡)</div>
    </div>
</div>

<form method="GET" action="index.php" class="filter-bar">
    <div class="form-group" style="flex: 2;">
        <label for="search">통합 검색</label>
        <input type="text" id="search" name="search" placeholder="장소명, 주소, 도로명 검색..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    
    <div class="form-group">
        <label for="region">지역</label>
        <select id="region" name="region" onchange="this.form.submit()">
            <option value="">전체 지역</option>
            <?php foreach ($regions as $region): ?>
                <option value="<?php echo $region['region_id']; ?>" <?php echo $region_filter == $region['region_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($region['region_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="form-group">
        <label for="category">카테고리</label>
        <select id="category" name="category" onchange="this.form.submit()">
            <option value="">전체 카테고리</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?php echo $category['category_id']; ?>" <?php echo $category_filter == $category['category_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($category['category_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="type">유형</label>
        <select id="type" name="type" onchange="this.form.submit()">
            <option value="">전체 유형</option>
            <option value="urban_forest" <?php echo $type_filter == 'urban_forest' ? 'selected' : ''; ?>>도시숲</option>
            <option value="street_tree" <?php echo $type_filter == 'street_tree' ? 'selected' : ''; ?>>가로수</option>
            <option value="living_forest" <?php echo $type_filter == 'living_forest' ? 'selected' : ''; ?>>생활숲</option>
            <option value="school" <?php echo $type_filter == 'school' ? 'selected' : ''; ?>>학교</option>
            <option value="park" <?php echo $type_filter == 'park' ? 'selected' : ''; ?>>공원</option>
            <option value="other" <?php echo $type_filter == 'other' ? 'selected' : ''; ?>>기타</option>
        </select>
    </div>
    
    <div style="padding-bottom: 2px;">
        <button type="submit" class="btn btn-primary" style="height: 42px;">🔍 검색</button>
        <a href="index.php" class="btn btn-secondary" style="height: 42px; width: 42px; justify-content: center; padding: 0;">↻</a>
    </div>
</form>

<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper" style="border: none; border-radius: 0;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>장소명</th>
                        <th>유형</th>
                        <th>지역/카테고리</th>
                        <th>면적/거리</th>
                        <th>수목 현황</th>
                        <th style="width: 180px;">관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($locations) > 0): ?>
					
                        <?php 
                        // 연번(가상 번호) 시작값 계산
                        // 전체 갯수 - ((현재페이지-1) * 페이지당갯수)
                        $virtual_num = $total_records - ($offset);
                        
                        foreach ($locations as $location): 
                        ?>
						    <tr>
                                <td style="color: var(--text-sub); font-weight: 500;">
                                    <?php echo $virtual_num--; ?>
                                </td>


                                <td>
                                    <a href="view.php?id=<?php echo $location['location_id']; ?>" style="font-weight: 700; color: var(--text-main); text-decoration: none; font-size: 1.05rem;">
                                        <?php echo htmlspecialchars($location['location_name']); ?>
                                    </a>
                                    <?php if ($location['address']): ?>
                                        <div style="font-size: 0.85rem; color: #9CA3AF; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 250px;">
                                            📍 <?php echo htmlspecialchars($location['address']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $type_labels = [
                                        'urban_forest' => '도시숲', 'street_tree' => '가로수', 'living_forest' => '생활숲',
                                        'school' => '학교', 'park' => '공원', 'other' => '기타'
                                    ];
                                    $type = $location['location_type'] ?? 'other';
                                    ?>
                                    <span class="type-badge type-<?php echo $type; ?>">
                                        <?php echo $type_labels[$type] ?? '기타'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 500; color: #4B5563;"><?php echo htmlspecialchars($location['region_name']); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-sub);"><?php echo htmlspecialchars($location['category_name']); ?></div>
                                </td>
                                <td>
                                    <?php if ($location['area']): ?>
                                        <div style="font-weight: 600; color: #8B5CF6;">📐 <?php echo number_format($location['area']); ?>㎡</div>
                                    <?php elseif ($location['length']): ?>
                                        <div style="font-weight: 600; color: #F59E0B;">📏 <?php echo number_format($location['length']); ?>m</div>
                                    <?php else: ?>
                                        <span style="color: #D1D5DB;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($location['total_trees'] > 0): ?>
                                        <div style="font-weight: 700; color: var(--primary);">🌳 <?php echo number_format($location['total_trees']); ?>주</div>
                                        <div style="font-size: 0.8rem; color: #6B7280;">(<?php echo $location['species_count']; ?>종)</div>
                                    <?php else: ?>
                                        <span style="color: #D1D5DB; font-size: 0.9rem;">수목 없음</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <a href="view.php?id=<?php echo $location['location_id']; ?>" class="btn btn-secondary btn-sm">상세</a>
                                        <a href="edit.php?id=<?php echo $location['location_id']; ?>" class="btn btn-secondary btn-sm" title="수정">✏️</a>
                                        <?php if (isAdmin()): ?>
                                            <a href="delete.php?id=<?php echo $location['location_id']; ?>" 
                                               class="btn btn-danger btn-sm" 
                                               onclick="return confirm('이 장소를 삭제하시겠습니까?\n연결된 모든 데이터가 삭제됩니다.');" title="삭제">🗑️</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 60px 0;">
                                <div style="font-size: 3rem; margin-bottom: 10px;">📍</div>
                                <div style="color: #6B7280; font-size: 1.1rem;">등록된 장소가 없습니다.</div>
                                <div style="color: #9CA3AF; font-size: 0.9rem; margin-top: 5px;">새로운 장소를 등록해보세요!</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo $region_filter; ?>&category=<?php echo $category_filter; ?>&type=<?php echo $type_filter; ?>" class="page-link">←</a>
        <?php endif; ?>
        
        <?php
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        for ($i = $start_page; $i <= $end_page; $i++):
        ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo $region_filter; ?>&category=<?php echo $category_filter; ?>&type=<?php echo $type_filter; ?>" 
               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo $region_filter; ?>&category=<?php echo $category_filter; ?>&type=<?php echo $type_filter; ?>" class="page-link">→</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
function exportLocations() {
    const params = new URLSearchParams(window.location.search);
    const exportUrl = '../export/locations.php?' + params.toString();
    
    const filterText = params.toString() ? '현재 필터 조건으로' : '전체';
    if (confirm(filterText + ' 장소 데이터를 엑셀로 내보내시겠습니까?')) {
        window.location.href = exportUrl;
    }
}
</script>


<?php include '../../includes/footer.php'; ?>