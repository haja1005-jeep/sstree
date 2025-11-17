<?php
/**
 * 장소 상세보기
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
          c.category_name,
          r.region_name,
          COUNT(DISTINCT lt.species_id) as species_count,
          COALESCE(SUM(lt.quantity), 0) as total_trees
          FROM locations l
          LEFT JOIN categories c ON l.category_id = c.category_id
          LEFT JOIN regions r ON l.region_id = r.region_id
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

// 수목 현황 조회
$trees_query = "SELECT 
                lt.location_tree_id,
                lt.species_id,
                lt.quantity,
                lt.size_spec,
                lt.average_height,
                lt.average_diameter,
                lt.root_diameter,
                lt.notes,
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

$page_title = '장소 상세보기';
include '../../includes/header.php';
?>

<style>
/* 추가 스타일 - 기존 admin.css 보완용 */
.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.action-buttons {
    display: flex;
    gap: 10px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.info-row {
    display: flex;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    width: 120px;
    font-weight: 600;
    color: #7f8c8d;
    font-size: 14px;
}

.info-value {
    flex: 1;
    color: #2c3e50;
    font-size: 14px;
}

.trees-section {
    background: white;
    border-radius: 10px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.quantity-badge {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 14px;
    display: inline-block;
}

.species-name {
    font-weight: 600;
    color: #27ae60;
}

.scientific-name {
    color: #7f8c8d;
    font-style: italic;
    font-size: 12px;
    margin-top: 2px;
}

.size-spec {
    background: #fef3c7;
    color: #92400e;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-family: monospace;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #95a5a6;
}

.empty-icon {
    font-size: 64px;
    margin-bottom: 20px;
}

.map-container {
    width: 100%;
    height: 400px;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #e0e0e0;
}

#map {
    width: 100%;
    height: 100%;
}

.action-icon {
    font-size: 14px;
    cursor: pointer;
    margin-left: 8px;
    text-decoration: none;
}

.action-icon:hover {
    opacity: 0.7;
}
</style>

<div class="page-header">
    <h2>📍 장소 상세보기</h2>
    <div>
        <a href="index.php" class="btn btn-secondary">← 목록으로</a>
    </div>
</div>

<!-- 액션 바 -->
<div class="action-bar">
    <div>
        <h3 style="margin: 0; color: #2c3e50;"><?php echo htmlspecialchars($location['location_name']); ?></h3>
        <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 14px;">
            <?php echo htmlspecialchars($location['region_name']); ?> · 
            <?php echo htmlspecialchars($location['category_name']); ?>
        </p>
    </div>
    <div class="action-buttons">
        <a href="manage_trees.php?location_id=<?php echo $location_id; ?>" class="btn btn-success">
            🌳 수목 관리
        </a>
        <a href="report.php?id=<?php echo $location_id; ?>" class="btn btn-primary">
            📊 관리대장
        </a>
        <a href="edit.php?id=<?php echo $location_id; ?>" class="btn btn-primary">
            ✏️ 수정
        </a>
        <a href="delete.php?id=<?php echo $location_id; ?>" class="btn btn-danger">
            🗑️ 삭제
        </a>
    </div>
</div>

<!-- 통계 카드 -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">🌳</div>
        <div class="stat-info">
            <h3><?php echo number_format($location['total_trees']); ?></h3>
            <p>총 나무 수</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">🌲</div>
        <div class="stat-info">
            <h3><?php echo number_format($location['species_count']); ?></h3>
            <p>수종 수</p>
        </div>
    </div>
    
    <?php if ($location['area']): ?>
    <div class="stat-card">
        <div class="stat-icon blue">📏</div>
        <div class="stat-info">
            <h3><?php echo number_format($location['area']); ?></h3>
            <p>면적 (㎡)</p>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($location['length']): ?>
    <div class="stat-card">
        <div class="stat-icon purple">📐</div>
        <div class="stat-info">
            <h3><?php echo number_format($location['length']); ?></h3>
            <p>총 연장 (m)</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 정보 그리드 -->
<div class="info-grid">
    <!-- 기본 정보 -->
    <div class="card">
        <div class="card-header">📍 기본 정보</div>
        
        <div class="info-row">
            <div class="info-label">장소명</div>
            <div class="info-value"><strong><?php echo htmlspecialchars($location['location_name']); ?></strong></div>
        </div>
        
        <div class="info-row">
            <div class="info-label">지역</div>
            <div class="info-value"><?php echo htmlspecialchars($location['region_name']); ?></div>
        </div>
        
        <div class="info-row">
            <div class="info-label">카테고리</div>
            <div class="info-value"><?php echo htmlspecialchars($location['category_name']); ?></div>
        </div>
        
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
        
        <?php if ($location['address']): ?>
        <div class="info-row">
            <div class="info-label">주소</div>
            <div class="info-value"><?php echo htmlspecialchars($location['address']); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- 도로 정보 -->
    <?php if ($location['road_name'] || $location['section_start'] || $location['section_end']): ?>
    <div class="card">
        <div class="card-header">🛣️ 도로 정보</div>
        
        <?php if ($location['road_name']): ?>
        <div class="info-row">
            <div class="info-label">도로명</div>
            <div class="info-value"><?php echo htmlspecialchars($location['road_name']); ?></div>
        </div>
        <?php endif; ?>
        
        <?php if ($location['section_start']): ?>
        <div class="info-row">
            <div class="info-label">시점</div>
            <div class="info-value"><?php echo htmlspecialchars($location['section_start']); ?></div>
        </div>
        <?php endif; ?>
        
        <?php if ($location['section_end']): ?>
        <div class="info-row">
            <div class="info-label">종점</div>
            <div class="info-value"><?php echo htmlspecialchars($location['section_end']); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 관리 정보 -->
    <?php if ($location['manager_name'] || $location['manager_contact']): ?>
    <div class="card">
        <div class="card-header">👤 관리 정보</div>
        
        <?php if ($location['manager_name']): ?>
        <div class="info-row">
            <div class="info-label">관리 책임자</div>
            <div class="info-value"><?php echo htmlspecialchars($location['manager_name']); ?></div>
        </div>
        <?php endif; ?>
        
        <?php if ($location['manager_contact']): ?>
        <div class="info-row">
            <div class="info-label">연락처</div>
            <div class="info-value"><?php echo htmlspecialchars($location['manager_contact']); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($location['description']): ?>
    <div class="card">
        <div class="card-header">📝 비고</div>
        <div style="color: #2c3e50; line-height: 1.6; padding: 10px 0;">
            <?php echo nl2br(htmlspecialchars($location['description'])); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- GPS 지도 -->
<?php if ($location['latitude'] && $location['longitude']): ?>
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">🗺️ 위치 지도</div>
    <div class="map-container">
        <div id="map"></div>
    </div>
    <div style="margin-top: 10px; color: #7f8c8d; font-size: 14px;">
        📍 위도: <?php echo $location['latitude']; ?>, 경도: <?php echo $location['longitude']; ?>
    </div>
</div>
<?php endif; ?>

<!-- 수목 현황 -->
<div class="trees-section">
    <div class="section-header">
        <h3 class="card-header" style="margin: 0;">🌳 수목 현황 (<?php echo count($trees); ?>종)</h3>
        <a href="manage_trees.php?location_id=<?php echo $location_id; ?>" class="btn btn-success">
            + 수목 추가
        </a>
    </div>

    <?php if (count($trees) > 0): ?>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">No</th>
                    <th>수종</th>
                    <th style="width: 120px;">수량</th>
                    <th style="width: 150px;">규격</th>
                    <th style="width: 100px;">평균 높이</th>
                    <th style="width: 100px;">평균 직경</th>
                    <th style="width: 100px;">근원직경</th>
                    <th>비고</th>
                    <th style="width: 100px;">관리</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $index = 1;
                foreach ($trees as $tree): 
                ?>
                <tr>
                    <td><?php echo $index++; ?></td>
                    <td>
                        <div class="species-name"><?php echo htmlspecialchars($tree['korean_name']); ?></div>
                        <?php if ($tree['scientific_name']): ?>
                        <div class="scientific-name"><?php echo htmlspecialchars($tree['scientific_name']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="quantity-badge">
                            <?php echo number_format($tree['quantity']); ?>주
                        </span>
                    </td>
                    <td>
                        <?php if ($tree['size_spec']): ?>
                            <span class="size-spec"><?php echo htmlspecialchars($tree['size_spec']); ?></span>
                        <?php else: ?>
                            <span style="color: #95a5a6;">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $tree['average_height'] ? number_format($tree['average_height'], 2) . 'm' : '-'; ?></td>
                    <td><?php echo $tree['average_diameter'] ? number_format($tree['average_diameter'], 2) . 'cm' : '-'; ?></td>
                    <td><?php echo $tree['root_diameter'] ? number_format($tree['root_diameter'], 2) . 'cm' : '-'; ?></td>
                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?php echo $tree['notes'] ? htmlspecialchars($tree['notes']) : '-'; ?>
                    </td>
                    <td>
                        <a href="manage_trees.php?location_id=<?php echo $location_id; ?>&edit=<?php echo $tree['location_tree_id']; ?>" 
                           class="action-icon" title="수정">✏️</a>
                        <a href="manage_trees.php?location_id=<?php echo $location_id; ?>&delete=<?php echo $tree['location_tree_id']; ?>" 
                           class="action-icon" title="삭제" 
                           onclick="return confirm('이 수목 데이터를 삭제하시겠습니까?')">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-icon">🌱</div>
        <div style="font-size: 18px; font-weight: 600; margin-bottom: 10px; color: #7f8c8d;">
            등록된 수목이 없습니다
        </div>
        <div style="font-size: 14px; color: #95a5a6; margin-bottom: 30px;">
            수목 관리 버튼을 클릭하여 수목을 추가해주세요
        </div>
        <a href="manage_trees.php?location_id=<?php echo $location_id; ?>" class="btn btn-success">
            🌳 첫 번째 수목 추가하기
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if ($location['latitude'] && $location['longitude']): ?>
<script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>"></script>
<script>
// 지도 초기화
const mapContainer = document.getElementById('map');
const mapOption = {
    center: new kakao.maps.LatLng(<?php echo $location['latitude']; ?>, <?php echo $location['longitude']; ?>),
    level: 3
};
const map = new kakao.maps.Map(mapContainer, mapOption);

// 마커 생성
const markerPosition = new kakao.maps.LatLng(<?php echo $location['latitude']; ?>, <?php echo $location['longitude']; ?>);
const marker = new kakao.maps.Marker({
    position: markerPosition,
    map: map
});

// 인포윈도우 생성
const infowindow = new kakao.maps.InfoWindow({
    content: '<div style="padding:10px;font-size:14px;font-weight:600;"><?php echo htmlspecialchars($location['location_name']); ?></div>'
});

infowindow.open(map, marker);
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
