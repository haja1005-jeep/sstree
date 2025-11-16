<?php
/**
 * 나무 지도 보기 - 개선 버전
 * Smart Tree Map - Sinan County
 * 개선사항: 정보창 위치 자동 조정, 필터 세분화, 디자인 개선
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
          l.region_id, l.category_id, l.location_id,
          r.region_name, c.category_name, l.location_name, s.korean_name as species_name,
          (SELECT tp.file_path FROM tree_photos tp 
           WHERE tp.tree_id = t.tree_id 
           ORDER BY tp.is_main DESC, tp.sort_order ASC, tp.uploaded_at DESC 
           LIMIT 1) as main_photo
          FROM trees t
          LEFT JOIN regions r ON t.region_id = r.region_id
          LEFT JOIN locations l ON t.location_id = l.location_id
          LEFT JOIN categories c ON l.category_id = c.category_id
          LEFT JOIN tree_species_master s ON t.species_id = s.species_id
          WHERE t.latitude IS NOT NULL AND t.longitude IS NOT NULL";

$stmt = $db->prepare($query);
$stmt->execute();
$trees = $stmt->fetchAll();

// 카테고리 데이터 가져오기 (단순 목록)
$categoryQuery = "SELECT category_id, category_name 
                  FROM categories 
                  ORDER BY category_name"; // 👈 [수정] 단순 목록만 가져옵니다.
$categoryStmt = $db->query($categoryQuery);
$categories = $categoryStmt->fetchAll();

// 장소 데이터 가져오기 (l.region_id 사용)
$locationQuery = "SELECT l.location_id, l.location_name, l.category_id, l.region_id
                  FROM locations l
                  ORDER BY l.location_name"; // 👈 [수정] locations 테이블의 l.region_id를 사용합니다.
$locationStmt = $db->query($locationQuery);
$locations = $locationStmt->fetchAll();

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

.control-group label {
    font-weight: 600;
    color: #374151;
}

.control-group select,
.control-group input {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
    transition: all 0.2s;
}

.control-group select:focus,
.control-group input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.map-info {
    background: white;
    padding: 15px;
    border-radius: 10px;
    margin-top: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

/* 정보창 스타일 개선 */
.custom-infowindow {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 16px 20px;
    min-width: 240px;
    /*box-shadow: 0 10px 25px rgba(0,0,0,0.2);*/
    color: white;
}

.custom-infowindow .title {
    font-weight: 700;
    font-size: 16px;
    margin-bottom: 10px;
    color: white;
}

.custom-infowindow .info-row {
    font-size: 13px;
    line-height: 1.8;
    display: flex;
    align-items: center;
    gap: 6px;
    opacity: 0.95;
}

.custom-infowindow .info-icon {
    font-size: 14px;
}

/* 마커 호버 효과 */
.photo-marker:hover {
    z-index: 1000 !important;
}

div.info-body {
    padding: 0 !important;
    overflow: hidden;
    background: none !important; /* 👈 [추가] 카카오맵 기본 배경을 확실히 제거합니다. */
}


</style>

<div class="page-header">
    <h2>🗺️ 나무 지도 보기</h2>
</div>

<div class="map-controls">
    <div class="control-group">
        <label>🗺️ 지역:</label>
        <select id="filter-region" onchange="updateCategoryFilter(); filterTrees();">
            <option value="">전체</option>
            <?php
            $regionQuery = "SELECT * FROM regions ORDER BY region_name";
            $regionStmt = $db->query($regionQuery);
            while ($region = $regionStmt->fetch()) {
                echo "<option value='{$region['region_id']}'>{$region['region_name']}</option>";
            }
            ?>
        </select>
        
        <label>📁 카테고리:</label>
        <select id="filter-category" onchange="updateLocationFilter(); filterTrees();">
            <option value="">전체</option>
        </select>
        
        <label>📍 장소:</label>
        <select id="filter-location" onchange="filterTrees()">
            <option value="">전체</option>
        </select>
        
        <label>💚 건강상태:</label>
        <select id="filter-health" onchange="filterTrees()">
            <option value="">전체</option>
            <option value="excellent">최상</option>
            <option value="good">양호</option>
            <option value="fair">보통</option>
            <option value="poor">나쁨</option>
            <option value="dead">고사</option>
        </select>
        
        <button onclick="resetFilters()" class="btn btn-secondary btn-sm">🔄 필터 초기화</button>
        <button onclick="refreshMap()" class="btn btn-primary btn-sm">🗺️ 지도 새로고침</button>
    </div>
</div>

<div id="map"></div>

<div class="map-info">
    <p><strong>총 <span id="tree-count"><?php echo count($trees); ?></span>그루</strong>의 나무가 표시되고 있습니다.</p>
</div>

<script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>&libraries=services,clusterer"></script>
<script>
// 나무 데이터
const allTrees = <?php echo json_encode($trees); ?>;
let filteredTrees = allTrees;

// 카테고리 및 장소 데이터
const allCategories = <?php echo json_encode($categories); ?>;
const allLocations = <?php echo json_encode($locations); ?>;

// 상수 정의
const DEFAULT_LAT = <?php echo DEFAULT_LAT; ?>;
const DEFAULT_LNG = <?php echo DEFAULT_LNG; ?>;
const DEFAULT_ZOOM = <?php echo DEFAULT_ZOOM; ?>;
const BASE_URL = '<?php echo BASE_URL; ?>';

// 지도 초기화
const mapContainer = document.getElementById('map');
const mapOption = {
    center: new kakao.maps.LatLng(DEFAULT_LAT, DEFAULT_LNG),
    level: DEFAULT_ZOOM
};
const map = new kakao.maps.Map(mapContainer, mapOption);
let markers = [];
let currentInfowindow = null;

// 건강상태 라벨 변환
function getHealthLabel(status) {
    const labels = {
        'excellent': '최상',
        'good': '양호',
        'fair': '보통',
        'poor': '나쁨',
        'dead': '고사'
    };
    return labels[status] || '양호';
}

// 건강상태별 색상
function getHealthColor(status) {
    const colors = {
        'excellent': '#10b981',
        'good': '#3b82f6',
        'fair': '#f59e0b',
        'poor': '#ef4444',
        'dead': '#6b7280'
    };
    return colors[status] || '#3b82f6';
}

// 카테고리 필터 업데이트 (연동 로직 수정)
function updateCategoryFilter() {
    const regionId = document.getElementById('filter-region').value;
    const categorySelect = document.getElementById('filter-category');
    
    categorySelect.innerHTML = '<option value="">전체</option>'; // 옵션 초기화
    
    let categoriesToShow = [];

    if (regionId) {
        // 1. 선택된 지역(regionId)에 속하는 장소(locations)들을 찾음
        const locationsInRegion = allLocations.filter(l => l.region_id == regionId);
        // 2. 이 장소들의 category_id만 추출 (중복 포함)
        const categoryIdsInRegion = locationsInRegion.map(l => l.category_id);
        // 3. 중복된 category_id 제거
        const uniqueCategoryIds = [...new Set(categoryIdsInRegion)];
        // 4. allCategories(전체 카테고리 목록)에서 해당 ID의 카테고리 정보를 찾아 옵션으로 추가
        categoriesToShow = allCategories.filter(c => uniqueCategoryIds.includes(c.category_id));
    } else {
        // 지역이 '전체'일 경우, 모든 카테고리를 표시
        categoriesToShow = allCategories;
    }

    categoriesToShow.forEach(category => {
        const option = document.createElement('option');
        option.value = category.category_id;
        option.textContent = category.category_name;
        categorySelect.appendChild(option);
    });
    
    // 장소 필터도 초기화
    updateLocationFilter();
}

// 장소 필터 업데이트 (연동 로직 수정)
function updateLocationFilter() {
    const regionId = document.getElementById('filter-region').value; // 👈 [추가] regionId도 필요
    const categoryId = document.getElementById('filter-category').value;
    const locationSelect = document.getElementById('filter-location');
    
    locationSelect.innerHTML = '<option value="">전체</option>'; // 옵션 초기화
    
    let filteredLocations = [];

    if (categoryId && regionId) {
        // '지역'과 '카테고리' 모두에 해당하는 장소
        filteredLocations = allLocations.filter(l => 
            l.region_id == regionId && l.category_id == categoryId
        );
    } else if (categoryId && !regionId) {
        // '카테고리'만 선택된 경우 (지역은 '전체')
        filteredLocations = allLocations.filter(l => l.category_id == categoryId);
    } else if (!categoryId && regionId) {
        // '지역'만 선택된 경우 (카테고리는 '전체')
        filteredLocations = allLocations.filter(l => l.region_id == regionId);
    } else {
        // 둘 다 '전체'면 모든 장소를 표시
        filteredLocations = allLocations;
    }

    filteredLocations.forEach(location => {
        const option = document.createElement('option');
        option.value = location.location_id;
        option.textContent = location.location_name;
        locationSelect.appendChild(option);
    });
}

// 원형 사진 마커 생성 및 표시
function addPhotoMarkers(trees) {
    // 기존 정보창 닫기
    if (currentInfowindow) {
        currentInfowindow.close();
        currentInfowindow = null;
    }
    
    // 기존 마커 제거
    markers.forEach(m => {
        if (m.overlay) {
            m.overlay.setMap(null);
        }
        if (m.marker) {
            m.marker.setMap(null);
        }
        if (m.infowindow) {
            m.infowindow.close();
        }
    });
    markers = [];
    
    if (trees.length === 0) return;
    
    const bounds = new kakao.maps.LatLngBounds();
    
    trees.forEach(tree => {
        const position = new kakao.maps.LatLng(tree.latitude, tree.longitude);
        bounds.extend(position);
        
        // 정보창 내용 생성 (개선된 디자인)
        const healthColor = getHealthColor(tree.health_status);
        const infoContent = `
            <div class="custom-infowindow">
                <div class="title">
                    ${tree.species_name || '수종 미지정'}
                </div>
                <div style="color: rgba(255,255,255,0.95); font-size: 13px; line-height: 1.8;">
                    <div class="info-row">
                        <span class="info-icon">📍</span>
                        <strong>장소:</strong> ${tree.location_name || '-'}
                    </div>
                    ${tree.height ? `<div class="info-row"><span class="info-icon">📏</span><strong>높이:</strong> ${parseFloat(tree.height).toFixed(1)}m</div>` : ''}
                    ${tree.diameter ? `<div class="info-row"><span class="info-icon">📐</span><strong>직경:</strong> ${parseFloat(tree.diameter).toFixed(1)}cm</div>` : ''}
                    <div class="info-row">
                        <span class="info-icon">💚</span>
                        <strong>상태:</strong> <span style="color: ${healthColor};">●</span> ${getHealthLabel(tree.health_status)}
                    </div>
                </div>
            </div>
        `;
        

		const infowindow = new kakao.maps.InfoWindow({
            content: infoContent,
            removable: false,
            disableAutoPan: true,
            position: position // 👈 [추가] 여기에 position을 명시합니다.
        });
        
        // 사진이 있으면 원형 사진 마커, 없으면 기본 마커
        if (tree.main_photo) {
            const photoUrl = BASE_URL + '/' + tree.main_photo;
            const customContent = `
                <div class="photo-marker" data-tree-id="${tree.tree_id}" style="position: relative; cursor: pointer;">
                    <div style="
                        width: 50px;
                        height: 50px;
                        border-radius: 50%;
                        border: 3px solid white;
                        overflow: hidden;
                        box-shadow: 0 3px 10px rgba(0,0,0,0.3);
                        background: white;
                        transition: all 0.3s ease;
                    ">
                        <img src="${photoUrl}" style="
                            width: 100%;
                            height: 100%;
                            object-fit: cover;
                        " alt="${tree.species_name || '나무'}">
                    </div>
                </div>
            `;
            
            const customOverlay = new kakao.maps.CustomOverlay({
                position: position,
                content: customContent,
                yAnchor: 1,
                zIndex: 1
            });
            customOverlay.setMap(map);
            markers.push({overlay: customOverlay, infowindow: infowindow, position: position, treeId: tree.tree_id});
            
            // DOM이 생성된 후 이벤트 리스너 추가
            setTimeout(() => {
                const markerElement = document.querySelector(`.photo-marker[data-tree-id="${tree.tree_id}"]`);
                if (markerElement) {
                    // 마우스 오버
                    markerElement.addEventListener('mouseover', function() {
                        // 현재 열려있는 정보창 닫기
                        if (currentInfowindow) {
                            currentInfowindow.close();
                        }
                        
                        // 스케일 효과
                        this.querySelector('div').style.transform = 'scale(1.15)';
                        this.querySelector('div').style.boxShadow = '0 5px 20px rgba(0,0,0,0.4)';
                        
                        // ▼▼▼ [수정] 지역 필터 값에 따라 지도 이동/줌 분기 ▼▼▼
                        const regionId = document.getElementById('filter-region').value;

                        if (regionId !== "") {
                            // "전체"가 아닌, 특정 지역이 선택된 경우에만 지도를 이동합니다.
                            map.panTo(position);
                        }
                        // "전체"일 때는 지도 크기나 위치를 변경하지 않습니다.
                        // ▲▲▲ [수정] 여기까지 ▲▲▲
                        
                        // 정보창 열기
                        infowindow.open(map);
                        currentInfowindow = infowindow;
                    });
                    
                    // 마우스 아웃
                    markerElement.addEventListener('mouseout', function() {
                        this.querySelector('div').style.transform = 'scale(1)';
                        this.querySelector('div').style.boxShadow = '0 3px 10px rgba(0,0,0,0.3)';
                        // 정보창은 마우스 아웃 시 닫지 않음 (사용자 경험 개선)
                    });
                    
                    // 클릭
                    markerElement.addEventListener('click', function() {
                        location.href = 'view.php?id=' + tree.tree_id;
                    });
                }
            }, 100);
        } else {
            // 기본 마커
            const marker = new kakao.maps.Marker({
                position: position,
                clickable: true
            });
            marker.setMap(map);
            markers.push({marker: marker, infowindow: infowindow, treeId: tree.tree_id, position: position});
            
            // 마우스 오버
            kakao.maps.event.addListener(marker, 'mouseover', function() {
                // 현재 열려있는 정보창 닫기
                if (currentInfowindow) {
                    currentInfowindow.close();
                }
                
                // ▼▼▼ [수정] 지역 필터 값에 따라 지도 이동/줌 분기 ▼▼▼
                const regionId = document.getElementById('filter-region').value;

                if (regionId !== "") {
                    // "전체"가 아닌, 특정 지역이 선택된 경우에만 지도를 이동합니다.
                    map.panTo(position);
                }
                // "전체"일 때는 지도 크기나 위치를 변경하지 않습니다.
                // ▲▲▲ [수정] 여기까지 ▲▲▲
                
                // 정보창 열기
                infowindow.open(map);
                currentInfowindow = infowindow;
            });
            
            // 마우스 아웃
            kakao.maps.event.addListener(marker, 'mouseout', function() {
                // 정보창은 마우스 아웃 시 닫지 않음
            });
            
            // 클릭 이벤트
            kakao.maps.event.addListener(marker, 'click', function() {
                location.href = 'view.php?id=' + tree.tree_id;
            });
        }
    });
    
    // 지도 범위 조정
    if (trees.length > 0) {
        map.setBounds(bounds);
    }
}

// 초기 마커 표시
addPhotoMarkers(allTrees);

// 초기 카테고리 필터 설정
updateCategoryFilter();

// 필터링
function filterTrees() {
    const regionFilter = document.getElementById('filter-region').value;
    const categoryFilter = document.getElementById('filter-category').value;
    const locationFilter = document.getElementById('filter-location').value;
    const healthFilter = document.getElementById('filter-health').value;
    
    filteredTrees = allTrees.filter(tree => {
        let matchRegion = !regionFilter || tree.region_id == regionFilter;
        let matchCategory = !categoryFilter || tree.category_id == categoryFilter;
        let matchLocation = !locationFilter || tree.location_id == locationFilter;
        let matchHealth = !healthFilter || tree.health_status == healthFilter;
        return matchRegion && matchCategory && matchLocation && matchHealth;
    });
    
    addPhotoMarkers(filteredTrees);
    document.getElementById('tree-count').textContent = filteredTrees.length;
}

// 필터 초기화
function resetFilters() {
    document.getElementById('filter-region').value = '';
    document.getElementById('filter-category').value = '';
    document.getElementById('filter-location').value = '';
    document.getElementById('filter-health').value = '';
    filteredTrees = allTrees;
    updateCategoryFilter();
    addPhotoMarkers(allTrees);
    document.getElementById('tree-count').textContent = allTrees.length;
}

// 지도 새로고침
function refreshMap() {
    location.reload();
}

// 지도 클릭 시 정보창 닫기
kakao.maps.event.addListener(map, 'click', function() {
    if (currentInfowindow) {
        currentInfowindow.close();
        currentInfowindow = null;
    }
});
</script>

<?php include '../../includes/footer.php'; ?>