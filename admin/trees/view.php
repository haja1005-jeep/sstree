<?php
require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';
checkAuth();

$page_title = '나무 상세보기';
require_once '../../includes/header.php';

$database = new Database();
$db = $database->getConnection();

// 나무 ID 확인
if (!isset($_GET['id'])) {
    redirect(BASE_URL . '/admin/trees/list.php');
}

$tree_id = (int)$_GET['id'];

// 나무 정보 조회
$query = "SELECT t.*, 
          r.region_name, 
          c.category_name, 
          l.location_name, l.address,
          s.korean_name as species_name, s.scientific_name, s.english_name,
          u.name as creator_name
          FROM trees t
          LEFT JOIN regions r ON t.region_id = r.region_id
          LEFT JOIN categories c ON t.category_id = c.category_id
          LEFT JOIN locations l ON t.location_id = l.location_id
          LEFT JOIN tree_species_master s ON t.species_id = s.species_id
          LEFT JOIN users u ON t.created_by = u.user_id
          WHERE t.tree_id = :tree_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':tree_id', $tree_id);
$stmt->execute();
$tree = $stmt->fetch();

if (!$tree) {
    redirect(BASE_URL . '/admin/trees/list.php');
}

// 사진 목록 조회
$photos_query = "SELECT * FROM tree_photos 
                 WHERE tree_id = :tree_id 
                 ORDER BY is_main DESC, sort_order ASC, uploaded_at DESC";
$photos_stmt = $db->prepare($photos_query);
$photos_stmt->bindParam(':tree_id', $tree_id);
$photos_stmt->execute();
$photos = $photos_stmt->fetchAll();

// 대표 사진 찾기 (지도 마커용)
$main_photo = null;
if (count($photos) > 0) {
    // is_main = 1인 사진 또는 첫 번째 사진
    foreach ($photos as $photo) {
        if ($photo['is_main'] == 1) {
            $main_photo = $photo;
            break;
        }
    }
    if (!$main_photo) {
        $main_photo = $photos[0];
    }
}

// 사진 분류
$photos_by_type = [
    'full' => [],
    'leaf' => [],
    'bark' => [],
    'flower' => [],
    'fruit' => [],
    'other' => []
];
foreach ($photos as $photo) {
    $type = $photo['photo_type'] ?: 'other';
    $photos_by_type[$type][] = $photo;
}

$photo_type_labels = [
    'full' => '전체',
    'leaf' => '잎',
    'bark' => '수피',
    'flower' => '꽃',
    'fruit' => '열매',
    'other' => '기타'
];

$health_labels = [
    'excellent' => '최상',
    'good' => '양호',
    'fair' => '보통',
    'poor' => '나쁨',
    'dead' => '고사'
];

$health_colors = [
    'excellent' => '#10b981',
    'good' => '#3b82f6',
    'fair' => '#f59e0b',
    'poor' => '#ef4444',
    'dead' => '#6b7280'
];
?>

<style>
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.info-item {
    background: #f9fafb;
    padding: 15px;
    border-radius: 8px;
    border-left: 4px solid var(--primary-color);
}
.info-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
}
.info-value {
    font-size: 18px;
    font-weight: 600;
    color: var(--dark-color);
}
.photo-section {
    margin-bottom: 30px;
}
.photo-section-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.photo-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
}
.photo-item {
    aspect-ratio: 1;
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: all 0.3s;
    position: relative;
}
.photo-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}
.photo-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.map-view {
    width: 100%;
    height: 400px;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.health-indicator {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    background: rgba(0,0,0,0.05);
}
.health-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
}
.species-info {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
}
.species-info h3 {
    margin: 0 0 10px 0;
    font-size: 24px;
}
.species-info p {
    margin: 5px 0;
    opacity: 0.9;
}
/* Lightbox */
.lightbox {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.95);
}
.lightbox.active {
    display: flex;
    align-items: center;
    justify-content: center;
}
.lightbox-content {
    max-width: 90%;
    max-height: 90%;
    border-radius: 8px;
}
.lightbox-close {
    position: absolute;
    top: 20px;
    right: 35px;
    color: #f1f1f1;
    font-size: 40px;
    font-weight: bold;
    cursor: pointer;
    transition: 0.3s;
}
.lightbox-close:hover {
    color: #bbb;
}
.badge-count {
    background: #667eea;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
}
</style>

<div style="display: flex; gap: 10px; margin-bottom: 20px;">
    <a href="list.php" class="btn btn-secondary">← 목록으로</a>
    <a href="edit.php?id=<?php echo $tree_id; ?>" class="btn btn-primary">수정</a>
	<?php if ($tree['latitude'] && $tree['longitude']): ?>
        <a href="https://map.kakao.com/link/to/<?php echo urlencode($tree['tree_number'] ?: $tree['species_name']); ?>,<?php echo $tree['latitude']; ?>,<?php echo $tree['longitude']; ?>" 
           target="_blank" class="btn" style="background-color: #FEE500; color: #191919; font-weight: 500;">
            🗺️ 카카오 길찾기
        </a>
    <?php endif; ?>
    <?php if (isAdmin()): ?>
        <a href="list.php?delete=<?php echo $tree_id; ?>" 
           class="btn btn-danger" 
           style="margin-left: auto;"
           onclick="return confirm('이 나무를 삭제하시겠습니까?');">
            삭제
        </a>
    <?php endif; ?>
</div>

<!-- 수종 정보 헤더 -->
<div class="species-info">
    <h3>🌳 <?php echo htmlspecialchars($tree['species_name'] ?: '수종 미지정'); ?></h3>
    <?php if ($tree['scientific_name']): ?>
        <p><em><?php echo htmlspecialchars($tree['scientific_name']); ?></em></p>
    <?php endif; ?>
    <?php if ($tree['english_name']): ?>
        <p><?php echo htmlspecialchars($tree['english_name']); ?></p>
    <?php endif; ?>
</div>

<!-- 기본 정보 -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">기본 정보</h3>
        <div style="display: flex; gap: 10px; align-items: center;">
            <?php if ($tree['tree_number']): ?>
                <span class="badge badge-info">번호: <?php echo htmlspecialchars($tree['tree_number']); ?></span>
            <?php endif; ?>
            <div class="health-indicator">
                <div class="health-dot" style="background: <?php echo $health_colors[$tree['health_status'] ?: 'good']; ?>"></div>
                <?php echo $health_labels[$tree['health_status'] ?: 'good']; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">지역</div>
                <div class="info-value"><?php echo htmlspecialchars($tree['region_name'] ?: '-'); ?></div>
            </div>
            
            <div class="info-item">
                <div class="info-label">카테고리</div>
                <div class="info-value"><?php echo htmlspecialchars($tree['category_name'] ?: '-'); ?></div>
            </div>
            
            <div class="info-item">
                <div class="info-label">장소</div>
                <div class="info-value" style="font-size: 14px;">
                    <?php echo htmlspecialchars($tree['location_name'] ?: '-'); ?>
                </div>
            </div>
            
            <?php if ($tree['height']): ?>
                <div class="info-item">
                    <div class="info-label">높이</div>
                    <div class="info-value"><?php echo number_format($tree['height'], 2); ?> m</div>
                </div>
            <?php endif; ?>
            
            <?php if ($tree['diameter']): ?>
                <div class="info-item">
                    <div class="info-label">직경</div>
                    <div class="info-value"><?php echo number_format($tree['diameter'], 2); ?> cm</div>
                </div>
            <?php endif; ?>
            
            <?php if ($tree['planting_date']): ?>
                <div class="info-item">
                    <div class="info-label">식재일</div>
                    <div class="info-value" style="font-size: 14px;">
                        <?php echo date('Y년 m월 d일', strtotime($tree['planting_date'])); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="info-item">
                <div class="info-label">등록일</div>
                <div class="info-value" style="font-size: 14px;">
                    <?php echo date('Y-m-d H:i', strtotime($tree['created_at'])); ?>
                </div>
            </div>
            
            <?php if ($tree['creator_name']): ?>
                <div class="info-item">
                    <div class="info-label">등록자</div>
                    <div class="info-value" style="font-size: 14px;">
                        <?php echo htmlspecialchars($tree['creator_name']); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($tree['address']): ?>
            <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px;">
                <div style="font-weight: 600; margin-bottom: 5px; color: var(--dark-color);">주소</div>
                <div style="color: #4b5563;"><?php echo htmlspecialchars($tree['address']); ?></div>
            </div>
        <?php endif; ?>
        
        <?php if ($tree['notes']): ?>
            <div style="margin-top: 20px; padding: 20px; background: #f9fafb; border-radius: 8px;">
                <div style="font-weight: 600; margin-bottom: 10px; color: var(--dark-color);">비고</div>
                <div style="line-height: 1.6; color: #4b5563;">
                    <?php echo nl2br(htmlspecialchars($tree['notes'])); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 사진 갤러리 -->
<?php if (count($photos) > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📷 사진 갤러리 (<?php echo count($photos); ?>장)</h3>
        </div>
        <div class="card-body">
            <?php foreach ($photos_by_type as $type => $type_photos): ?>
                <?php if (count($type_photos) > 0): ?>
                    <div class="photo-section">
                        <div class="photo-section-title">
                            <?php echo $photo_type_labels[$type]; ?>
                            <span class="badge-count"><?php echo count($type_photos); ?></span>
                        </div>
                        <div class="photo-gallery">
                            <?php foreach ($type_photos as $photo): ?>
                                <div class="photo-item" onclick="openLightbox('<?php echo BASE_URL . '/' . $photo['file_path']; ?>')">
                                    <img src="<?php echo BASE_URL . '/' . $photo['file_path']; ?>" 
                                         alt="<?php echo htmlspecialchars($photo['file_name']); ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: 40px; color: #999;">
            등록된 사진이 없습니다.
        </div>
    </div>
<?php endif; ?>

<!-- 지도 -->
<?php if ($tree['latitude'] && $tree['longitude']): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🗺️ 위치</h3>
        </div>
        <div class="card-body">
            <div id="map" class="map-view"></div>
            <div style="margin-top: 15px; padding: 15px; background: #f9fafb; border-radius: 8px;">
                <strong>좌표:</strong> 
                <?php echo number_format($tree['latitude'], 8); ?>, 
                <?php echo number_format($tree['longitude'], 8); ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Lightbox -->
<div id="lightbox" class="lightbox" onclick="closeLightbox()">
    <span class="lightbox-close">&times;</span>
    <img class="lightbox-content" id="lightbox-img">
</div>

<script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>"></script>
<script>
// 카카오맵 초기화
<?php if ($tree['latitude'] && $tree['longitude']): ?>
    const mapContainer = document.getElementById('map');
    const mapOption = {
        center: new kakao.maps.LatLng(<?php echo $tree['latitude']; ?>, <?php echo $tree['longitude']; ?>),
        level: 3
    };
    const map = new kakao.maps.Map(mapContainer, mapOption);
    
    <?php if ($main_photo): ?>
    // 원형 사진 마커 표시
    const markerPosition = new kakao.maps.LatLng(<?php echo $tree['latitude']; ?>, <?php echo $tree['longitude']; ?>);
    const photoUrl = '<?php echo BASE_URL . '/' . $main_photo['file_path']; ?>';
    
    const customContent = `
        <div style="position: relative;">
            <div style="
                width: 60px;
                height: 60px;
                border-radius: 50%;
                border: 4px solid white;
                overflow: hidden;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                background: white;
            ">
                <img src="${photoUrl}" style="
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                " alt="나무 사진">
            </div>
        </div>
    `;
    
    const customOverlay = new kakao.maps.CustomOverlay({
        position: markerPosition,
        content: customContent,
        yAnchor: 1
    });
    customOverlay.setMap(map);
    <?php else: ?>
    // 사진이 없으면 기본 마커
    const markerPosition = new kakao.maps.LatLng(<?php echo $tree['latitude']; ?>, <?php echo $tree['longitude']; ?>);
    const marker = new kakao.maps.Marker({
        position: markerPosition
    });
    marker.setMap(map);
    <?php endif; ?>
<?php endif; ?>

// Lightbox
function openLightbox(imageSrc) {
    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightbox-img');
    lightbox.classList.add('active');
    lightboxImg.src = imageSrc;
}

function closeLightbox() {
    const lightbox = document.getElementById('lightbox');
    lightbox.classList.remove('active');
}
</script>

<?php require_once '../../includes/footer.php'; ?>