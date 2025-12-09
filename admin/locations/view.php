<?php
/**
 * 장소 상세보기 (지도 표시 오류 수정)
 * Smart Tree Map - Location Management
 */
require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';
checkAuth();

$page_title = '장소 상세보기';


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

// 사진 목록 조회 및 분류
$photos_query = "SELECT * FROM location_photos 
                 WHERE location_id = :location_id 
                 ORDER BY photo_type, sort_order";
$photos_stmt = $db->prepare($photos_query);
$photos_stmt->bindParam(':location_id', $location_id);
$photos_stmt->execute();
$photos = $photos_stmt->fetchAll();

$regular_photos = [];
$vr_photos = [];
foreach ($photos as $photo) {
    if ($photo['photo_type'] === 'image') {
        $regular_photos[] = $photo;
    } else {
        $vr_photos[] = $photo;
    }
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

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.css"/>

<style>
/* 기존 스타일 유지 및 보완 */
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
.action-buttons { display: flex; gap: 10px; }
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
.info-row:last-child { border-bottom: none; }
.info-label { width: 120px; font-weight: 600; color: #7f8c8d; font-size: 14px; }
.info-value { flex: 1; color: #2c3e50; font-size: 14px; }
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.stat-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 15px;
}
.stat-icon { font-size: 30px; }
.stat-info h3 { margin: 0; font-size: 24px; font-weight: 700; color: #2c3e50; }
.stat-info p { margin: 0; color: #7f8c8d; font-size: 14px; }

/* 멀티미디어 갤러리 스타일 */
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
    background: #f0f0f0;
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
    background: rgba(59, 130, 246, 0.9);
    color: white;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    z-index: 2;
}

/* 동영상 스타일 */
.video-container {
    position: relative;
    padding-bottom: 56.25%; /* 16:9 */
    height: 0;
    overflow: hidden;
    border-radius: 8px;
    background: #000;
}
.video-container iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
}

/* 지도 스타일 */
.map-container {
    width: 100%;
    height: 400px;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #e0e0e0;
}
#map { width: 100%; height: 100%; }

/* --- 라이트박스 (일반 사진용) --- */
.lightbox {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.9);
    justify-content: center;
    align-items: center;
}
.lightbox.active {
    display: flex;
}
.lightbox-content {
    max-width: 90%;
    max-height: 90vh;
    border-radius: 4px;
    box-shadow: 0 0 20px rgba(0,0,0,0.5);
    object-fit: contain;
}
.lightbox-close {
    position: absolute;
    top: 20px;
    right: 30px;
    color: #f1f1f1;
    font-size: 40px;
    font-weight: bold;
    cursor: pointer;
    z-index: 10000;
}
.lightbox-close:hover { color: #bbb; }

/* --- VR 뷰어 모달 --- */
.vr-modal {
    display: none;
    position: fixed;
    z-index: 9998; /* 라이트박스와 구분 */
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.9);
}
.vr-modal.active {
    display: block;
}
#panorama {
    width: 90%;
    height: 80%;
    margin: 5% auto;
    background-color: #000;
    border-radius: 8px;
    box-shadow: 0 0 20px rgba(255,255,255,0.1);
}
/* 수목 현황 테이블 스타일 */
.quantity-badge {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.species-name { font-weight: 600; color: #2c3e50; }
.scientific-name { color: #7f8c8d; font-style: italic; font-size: 12px; }
.size-spec {
    background: #f3f4f6;
    color: #4b5563;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-family: monospace;
    border: 1px solid #e5e7eb;
}
.action-icon { text-decoration: none; margin-left: 5px; font-size: 14px; }
</style>

<div class="page-header">
    <h2>📍 장소 상세보기</h2>
    <div>
        <a href="index.php" class="btn btn-secondary">← 목록으로</a>
    </div>
</div>

<?php if (isset($_GET['message'])): ?>
    <div class="alert alert-success">
        <?php echo htmlspecialchars($_GET['message']); ?>
    </div>
<?php endif; ?>

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

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">🌳</div>
        <div class="stat-info">
            <h3><?php echo number_format($location['total_trees']); ?></h3>
            <p>총 나무 수</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">🌲</div>
        <div class="stat-info">
            <h3><?php echo number_format($location['species_count']); ?></h3>
            <p>수종 수</p>
        </div>
    </div>
    
    <?php if ($location['area']): ?>
    <div class="stat-card">
        <div class="stat-icon">📏</div>
        <div class="stat-info">
            <h3><?php echo number_format($location['area']); ?></h3>
            <p>면적 (㎡)</p>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($location['length']): ?>
    <div class="stat-card">
        <div class="stat-icon">📐</div>
        <div class="stat-info">
            <h3><?php echo number_format($location['length']); ?></h3>
            <p>총 연장 (m)</p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($location['width']): ?>
    <div class="stat-card">
        <div class="stat-icon">↔️</div>
        <div class="stat-info">
            <h3><?php echo number_format($location['width']); ?></h3>
            <p>도로 폭 (m)</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="info-grid">
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

    <div class="card">
        <div class="card-header">👤 관리 정보</div>
        
        <?php if ($location['road_name']): ?>
        <div class="info-row">
            <div class="info-label">도로명</div>
            <div class="info-value"><?php echo htmlspecialchars($location['road_name']); ?></div>
        </div>
        <?php endif; ?>

        <?php if ($location['road_type']): ?>
        <div class="info-row">
            <div class="info-label">도로 종류</div>
            <div class="info-value"><?php echo htmlspecialchars($location['road_type']); ?></div>
        </div>
        <?php endif; ?>
        
        <?php if ($location['section_start'] || $location['section_end']): ?>
        <div class="info-row">
            <div class="info-label">구간</div>
            <div class="info-value">
                <?php echo htmlspecialchars($location['section_start'] ?? ''); ?> 
                <?php echo ($location['section_start'] && $location['section_end']) ? '~' : ''; ?>
                <?php echo htmlspecialchars($location['section_end'] ?? ''); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($location['establishment_year']): ?>
        <div class="info-row">
            <div class="info-label">조성년도</div>
            <div class="info-value"><?php echo htmlspecialchars($location['establishment_year']); ?>년</div>
        </div>
        <?php endif; ?>
        
        <?php if ($location['management_agency']): ?>
        <div class="info-row">
            <div class="info-label">관리기관</div>
            <div class="info-value"><?php echo htmlspecialchars($location['management_agency']); ?></div>
        </div>
        <?php endif; ?>

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
    
    <?php if ($location['description']): ?>
    <div class="card">
        <div class="card-header">📝 설명</div>
        <div style="color: #2c3e50; line-height: 1.6; padding: 10px 0;">
            <?php echo nl2br(htmlspecialchars($location['description'])); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (count($regular_photos) > 0): ?>
    <div class="card">
        <div class="card-header">📷 사진 갤러리 (<?php echo count($regular_photos); ?>장)</div>
        <div class="card-body">
            <div class="photo-gallery">
                <?php foreach ($regular_photos as $photo): ?>
                    <div class="photo-item" onclick="openLightbox('<?php echo BASE_URL . '/' . $photo['file_path']; ?>')">
                        <img src="<?php echo BASE_URL . '/' . $photo['file_path']; ?>" 
						     alt="<?php echo htmlspecialchars($photo['file_name']); ?>">
                    </div>
                <?php endforeach; ?>

           </div>
        </div>
    </div>
<?php endif; ?>

<?php if (count($vr_photos) > 0): ?>
    <div class="card">
        <div class="card-header">🔮 360도 VR 사진</div>
        <div class="card-body">
            <div class="photo-gallery">
                <?php foreach ($vr_photos as $photo): ?>
                    <div class="photo-item" onclick="openVR('<?php echo BASE_URL . '/' . $photo['file_path']; ?>')">
                        <img src="<?php echo BASE_URL . '/' . $photo['file_path']; ?>" 
                             alt="360 VR Photo">
                        <div class="vr-badge">360° VR</div>
                    </div>
                <?php endforeach; ?>
            </div>
            <small style="color: #6b7280; font-size: 13px; margin-top: 10px; display: block;">
                💡 클릭하면 360도 뷰어로 볼 수 있습니다.
            </small>
        </div>
    </div>
<?php endif; ?>

<?php if ($location['video_url']): ?>
    <div class="card">
        <div class="card-header">🎬 동영상</div>
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

<?php if ($location['latitude'] && $location['longitude']): ?>
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">🗺️ 위치 지도</div>
    <div class="card-body">
        <div class="map-container">
            <div id="map"></div>
        </div>
        <div style="margin-top: 10px; color: #7f8c8d; font-size: 14px;">
            📍 위도: <?php echo $location['latitude']; ?>, 경도: <?php echo $location['longitude']; ?>
            <a href="https://map.kakao.com/link/to/<?php echo urlencode($location['location_name']); ?>,<?php echo $location['latitude']; ?>,<?php echo $location['longitude']; ?>" 
            target="_blank" class="btn btn-sm btn-secondary" style="float: right;">
                카카오맵에서 보기
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title" style="margin: 0;">🌳 수목 현황 (<?php echo count($trees); ?>종)</h3>
        <a href="manage_trees.php?location_id=<?php echo $location_id; ?>" class="btn btn-success" style="float: right;">
            + 수목 추가
        </a>
    </div>
    <div class="card-body">
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
</div>

<div id="lightbox" class="lightbox" onclick="closeLightbox()">
    <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
    <img class="lightbox-content" id="lightbox-img">
</div>

<div id="vr-modal" class="vr-modal">
    <span class="lightbox-close" onclick="closeVR()">&times;</span>
    <div id="panorama"></div>
</div>

<script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.js"></script>

<script>
// 1. 유틸리티 함수 먼저 정의 (오류 방지)
function openLightbox(imageSrc) {

    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightbox-img');
    closeVR();
    lightboxImg.src = imageSrc;
    lightbox.classList.add('active');
}

function closeLightbox() {
    const lightbox = document.getElementById('lightbox');
    lightbox.classList.remove('active');
    setTimeout(() => { document.getElementById('lightbox-img').src = ''; }, 200);
}

let vrViewer = null;
function openVR(imageSrc) {
    const modal = document.getElementById('vr-modal');
    closeLightbox();
    modal.classList.add('active');
    if (vrViewer) { try { vrViewer.destroy(); } catch(e) {} document.getElementById('panorama').innerHTML = ''; }
    setTimeout(() => {
        vrViewer = pannellum.viewer('panorama', {
            "type": "equirectangular",
            "panorama": imageSrc,
            "autoLoad": true,
            "showControls": true,
            "title": "360° VR View",
            "author": "Smart Tree Map"
        });
    }, 100);
}

function closeVR() {
    const modal = document.getElementById('vr-modal');
    modal.classList.remove('active');
    if (vrViewer) { try { vrViewer.destroy(); } catch(e) {} vrViewer = null; }
    document.getElementById('panorama').innerHTML = '';
}

document.addEventListener('keydown', function(event) {
    if (event.key === "Escape") { closeLightbox(); closeVR(); }
});

// 2. 지도 초기화 (안전하게 실행)
<?php if ($location['latitude'] && $location['longitude']): ?>
(function() {
    var mapContainer = document.getElementById('map');
    if (!mapContainer) return;

    var mapOption = {
        center: new kakao.maps.LatLng(<?php echo $location['latitude']; ?>, <?php echo $location['longitude']; ?>),
        level: 3
    };

    var map = new kakao.maps.Map(mapContainer, mapOption);

    var markerPosition = new kakao.maps.LatLng(<?php echo $location['latitude']; ?>, <?php echo $location['longitude']; ?>);
    var marker = new kakao.maps.Marker({
        position: markerPosition
    });
    marker.setMap(map);

    var infowindow = new kakao.maps.InfoWindow({
        content: '<div style="padding:10px;font-size:14px;font-weight:600;"><?php echo htmlspecialchars($location['location_name']); ?></div>'
    });
    infowindow.open(map, marker);
})();
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>