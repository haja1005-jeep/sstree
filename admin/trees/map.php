<?php
/**
 * 나무 지도 보기
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '지도 보기';

// 데이터베이스에서 나무 데이터 가져오기
$database = new Database();
$db = $database->getConnection();

$query = "SELECT t.tree_id, t.latitude, t.longitude, t.height, t.diameter, t.health_status,
          r.region_name, l.location_name, s.korean_name as species_name
          FROM trees t
          LEFT JOIN regions r ON t.region_id = r.region_id
          LEFT JOIN locations l ON t.location_id = l.location_id
          LEFT JOIN tree_species_master s ON t.species_id = s.species_id
          WHERE t.latitude IS NOT NULL AND t.longitude IS NOT NULL";

$stmt = $db->prepare($query);
$stmt->execute();
$trees = $stmt->fetchAll();

include '../../includes/header.php';
?>

<style>
#map {
    width: 100%;
    height: calc(100vh - 200px);
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.map-controls {
    background: white;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.control-group {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.control-group select,
.control-group input {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.map-info {
    background: white;
    padding: 15px;
    border-radius: 10px;
    margin-top: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
</style>

<div class="page-header">
    <h2>🗺️ 나무 지도 보기</h2>
</div>

<div class="map-controls">
    <div class="control-group">
        <label>지역:</label>
        <select id="filter-region" onchange="filterTrees()">
            <option value="">전체</option>
            <?php
            $regionQuery = "SELECT * FROM regions ORDER BY region_name";
            $regionStmt = $db->query($regionQuery);
            while ($region = $regionStmt->fetch()) {
                echo "<option value='{$region['region_id']}'>{$region['region_name']}</option>";
            }
            ?>
        </select>
        
        <label>건강상태:</label>
        <select id="filter-health" onchange="filterTrees()">
            <option value="">전체</option>
            <option value="excellent">최상</option>
            <option value="good">양호</option>
            <option value="fair">보통</option>
            <option value="poor">나쁨</option>
            <option value="dead">고사</option>
        </select>
        
        <button onclick="resetFilters()" class="btn btn-secondary btn-sm">필터 초기화</button>
        <button onclick="refreshMap()" class="btn btn-primary btn-sm">지도 새로고침</button>
    </div>
</div>

<div id="map"></div>

<div class="map-info">
    <p><strong>총 <span id="tree-count"><?php echo count($trees); ?></span>그루</strong>의 나무가 표시되고 있습니다.</p>
</div>

<script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>&libraries=services,clusterer"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/kakao_map.js"></script>
<script>
// 나무 데이터
const allTrees = <?php echo json_encode($trees); ?>;
let filteredTrees = allTrees;

// 상수 정의
const DEFAULT_LAT = <?php echo DEFAULT_LAT; ?>;
const DEFAULT_LNG = <?php echo DEFAULT_LNG; ?>;
const DEFAULT_ZOOM = <?php echo DEFAULT_ZOOM; ?>;

// 지도 초기화
initMap('map', DEFAULT_LAT, DEFAULT_LNG, DEFAULT_ZOOM);

// 초기 마커 표시
addMarkersAndFitBounds(allTrees);

// 필터링
function filterTrees() {
    const regionFilter = document.getElementById('filter-region').value;
    const healthFilter = document.getElementById('filter-health').value;
    
    filteredTrees = allTrees.filter(tree => {
        let matchRegion = !regionFilter || tree.region_id == regionFilter;
        let matchHealth = !healthFilter || tree.health_status == healthFilter;
        return matchRegion && matchHealth;
    });
    
    addMarkersAndFitBounds(filteredTrees);
    document.getElementById('tree-count').textContent = filteredTrees.length;
}

// 필터 초기화
function resetFilters() {
    document.getElementById('filter-region').value = '';
    document.getElementById('filter-health').value = '';
    filteredTrees = allTrees;
    addMarkersAndFitBounds(allTrees);
    document.getElementById('tree-count').textContent = allTrees.length;
}

// 지도 새로고침
function refreshMap() {
    location.reload();
}
</script>

<?php include '../../includes/footer.php'; ?>
