<?php
require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';
checkAuth();

$page_title = '장소 상세보기';
require_once '../../includes/header.php';

$database = new Database();
$db = $database->getConnection();

// 장소 ID 확인
if (!isset($_GET['id'])) {
    redirect(SITE_URL . '/admin/locations/list.php');
}

$location_id = (int)$_GET['id'];

// 장소 정보 조회
$query = "SELECT l.*, r.region_name, c.category_name
          FROM locations l
          LEFT JOIN regions r ON l.region_id = r.region_id
          LEFT JOIN categories c ON l.category_id = c.category_id
          WHERE l.location_id = :location_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':location_id', $location_id);
$stmt->execute();
$location = $stmt->fetch();

if (!$location) {
    redirect(SITE_URL . '/admin/locations/list.php');
}

// 사진 목록 조회
$photos_query = "SELECT * FROM location_photos 
                 WHERE location_id = :location_id 
                 ORDER BY photo_type, sort_order";
$photos_stmt = $db->prepare($photos_query);
$photos_stmt->bindParam(':location_id', $location_id);
$photos_stmt->execute();
$photos = $photos_stmt->fetchAll();

// 사진 분류
$regular_photos = [];
$vr_photos = [];
foreach ($photos as $photo) {
    if ($photo['photo_type'] === 'image') {
        $regular_photos[] = $photo;
    } else {
        $vr_photos[] = $photo;
    }
}

// 나무 개수
$tree_query = "SELECT COUNT(*) as tree_count FROM trees WHERE location_id = :location_id";
$tree_stmt = $db->prepare($tree_query);
$tree_stmt->bindParam(':location_id', $location_id);
$tree_stmt->execute();
$tree_count = $tree_stmt->fetch()['tree_count'];
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
.photo-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
    margin: 20px 0;
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
.vr-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    background: rgba(102, 126, 234, 0.9);
    color: white;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
}
.map-view {
    width: 100%;
    height: 400px;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.video-container {
    position: relative;
    padding-bottom: 56.25%; /* 16:9 */
    height: 0;
    overflow: hidden;
    border-radius: 8px;
}
.video-container iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
}
/* Lightbox 스타일 */
.lightbox {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.9);
}
.lightbox.active {
    display: flex;
    align-items: center;
    justify-content: center;
}
.lightbox-content {
    max-width: 90%;
    max-height: 90%;
}
.lightbox-close {
    position: absolute;
    top: 20px;
    right: 35px;
    color: #f1f1f1;
    font-size: 40px;
    font-weight: bold;
    cursor: pointer;
}
</style>

<div style="display: flex; gap: 10px; margin-bottom: 20px;">
    <a href="list.php" class="btn btn-secondary">← 목록으로</a>
    <a href="edit.php?id=<?php echo $location_id; ?>" class="btn btn-primary">수정</a>
    <?php if (isAdmin()): ?>
        <a href="list.php?delete=<?php echo $location_id; ?>" 
           class="btn btn-danger" 
           style="margin-left: auto;"
           onclick="return confirm('이 장소를 삭제하시겠습니까?');">
            삭제
        </a>
    <?php endif; ?>
</div>

<!-- 기본 정보 -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?php echo htmlspecialchars($location['location_name']); ?></h3>
        <div style="display: flex; gap: 10px;">
            <span class="badge badge-info"><?php echo htmlspecialchars($location['category_name']); ?></span>
            <span class="badge badge-success"><?php echo htmlspecialchars($location['region_name']); ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="info-grid">
            <?php if ($location['address']): ?>
                <div class="info-item">
                    <div class="info-label">주소</div>
                    <div class="info-value" style="font-size: 14px;">
                        <?php echo htmlspecialchars($location['address']); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($location['area']): ?>
                <div class="info-item">
                    <div class="info-label">넓이</div>
                    <div class="info-value">
                        <?php echo number_format($location['area'], 2); ?> ㎡
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($location['length']): ?>
                <div class="info-item">
                    <div class="info-label">길이</div>
                    <div class="info-value">
                        <?php echo number_format($location['length'], 2); ?> m
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($location['width']): ?>
                <div class="info-item">
                    <div class="info-label">도로 폭</div>
                    <div class="info-value">
                        <?php echo number_format($location['width'], 2); ?> m
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="info-item">
                <div class="info-label">등록된 나무</div>
                <div class="info-value">
                    <?php echo number_format($tree_count); ?> 그루
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">등록일</div>
                <div class="info-value" style="font-size: 14px;">
                    <?php echo date('Y-m-d H:i', strtotime($location['created_at'])); ?>
                </div>
            </div>
        </div>
        
        <?php if ($location['description']): ?>
            <div style="margin-top: 20px; padding: 20px; background: #f9fafb; border-radius: 8px;">
                <div style="font-weight: 600; margin-bottom: 10px; color: var(--dark-color);">설명</div>
                <div style="line-height: 1.6; color: #4b5563;">
                    <?php echo nl2br(htmlspecialchars($location['description'])); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 일반 사진 갤러리 -->
<?php if (count($regular_photos) > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📷 사진 갤러리 (<?php echo count($regular_photos); ?>장)</h3>
        </div>
        <div class="card-body">
            <div class="photo-gallery">
                <?php foreach ($regular_photos as $photo): ?>
                    <div class="photo-item" onclick="openLightbox('<?php echo SITE_URL . '/' . $photo['file_path']; ?>')">
                        <img src="<?php echo SITE_URL . '/' . $photo['file_path']; ?>" 
                             alt="<?php echo htmlspecialchars($photo['file_name']); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- 360 VR 사진 -->
<?php if (count($vr_photos) > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🔮 360도 VR 사진</h3>
        </div>
        <div class="card-body">
            <div class="photo-gallery">
                <?php foreach ($vr_photos as $photo): ?>
                    <div class="photo-item" onclick="openLightbox('<?php echo SITE_URL . '/' . $photo['file_path']; ?>')">
                        <img src="<?php echo SITE_URL . '/' . $photo['file_path']; ?>" 
                             alt="360 VR Photo">
                        <div class="vr-badge">360° VR</div>
                    </div>
                <?php endforeach; ?>
            </div>
            <small style="color: #6b7280; font-size: 13px; margin-top: 10px; display: block;">
                💡 클릭하여 확대 보기
            </small>
        </div>
    </div>
<?php endif; ?>

<!-- 동영상 -->
<?php if ($location['video_url']): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🎬 동영상</h3>
        </div>
        <div class="card-body">
            <?php
            $video_url = $location['video_url'];
            $embed_url = '';
            
            // 유튜브 URL 변환
            if (strpos($video_url, 'youtube.com') !== false || strpos($video_url, 'youtu.be') !== false) {
                preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\?\/]+)/', $video_url, $matches);
                if (isset($matches[1])) {
                    $embed_url = 'https://www.youtube.com/embed/' . $matches[1];
                }
            }
            // 네이버TV URL 변환
            elseif (strpos($video_url, 'tv.naver.com') !== false) {
                preg_match('/v\/(\d+)/', $video_url, $matches);
                if (isset($matches[1])) {
                    $embed_url = 'https://tv.naver.com/embed/' . $matches[1];
                }
            }
            ?>
            
            <?php if ($embed_url): ?>
                <div class="video-container">
                    <iframe src="<?php echo $embed_url; ?>" 
                            frameborder="0" 
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                            allowfullscreen>
                    </iframe>
                </div>
            <?php else: ?>
                <div style="padding: 20px; background: #f9fafb; border-radius: 8px; text-align: center;">
                    <a href="<?php echo htmlspecialchars($video_url); ?>" 
                       target="_blank" 
                       class="btn btn-primary">
                        🔗 동영상 보기
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- 지도 -->
<?php if ($location['latitude'] && $location['longitude']): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🗺️ 위치</h3>
        </div>
        <div class="card-body">
            <div id="map" class="map-view"></div>
            <div style="margin-top: 15px; padding: 15px; background: #f9fafb; border-radius: 8px;">
                <strong>좌표:</strong> 
                <?php echo number_format($location['latitude'], 8); ?>, 
                <?php echo number_format($location['longitude'], 8); ?>
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
<?php if ($location['latitude'] && $location['longitude']): ?>
    const mapContainer = document.getElementById('map');
    const mapOption = {
        center: new kakao.maps.LatLng(<?php echo $location['latitude']; ?>, <?php echo $location['longitude']; ?>),
        level: 3
    };
    const map = new kakao.maps.Map(mapContainer, mapOption);
    
    // 마커 표시
    const markerPosition = new kakao.maps.LatLng(<?php echo $location['latitude']; ?>, <?php echo $location['longitude']; ?>);
    const marker = new kakao.maps.Marker({
        position: markerPosition
    });
    marker.setMap(map);
    
    // 인포윈도우
    const infowindow = new kakao.maps.InfoWindow({
        content: '<div style="padding:10px;font-size:14px;font-weight:600;"><?php echo htmlspecialchars($location['location_name']); ?></div>'
    });
    infowindow.open(map, marker);
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
