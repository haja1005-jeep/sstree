<?php
/**
 * 장소 상세보기
 * 수정: 자바스크립트 데이터 출력 안정화 (json_encode 적용)
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
    header('Location: list.php');
    exit;
}

// 장소 정보 조회 (geom_polygon WKT 포함)
$query = "SELECT l.*, 
          c.category_name,
          r.region_name,
          AsText(l.geom_polygon) as geom_wkt,
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
    header('Location: list.php');
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


// 사진 목록 조회 및 분류
$regular_photos = [];
$vr_photos = [];

foreach ($photos as $photo) {
    $type = strtolower(trim((string)($photo['photo_type'] ?? '')));

	if (in_array($type, ['vr360', '360', 'vr'], true)) {
        $vr_photos[] = $photo;
    } else {
        // 나머지(빈값/null 포함)는 전부 일반 사진
        $regular_photos[] = $photo;
    }

	$type = '';
}




// GPS 좌표가 있는 사진 필터링
$gps_photos = array_filter($photos, function($p) {
    return !empty($p['gps_latitude']) && !empty($p['gps_longitude']);
});

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
/* 기존 스타일 유지 */
.action-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
.action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
.info-row { display: flex; padding: 12px 0; border-bottom: 1px solid #f0f0f0; }
.info-row:last-child { border-bottom: none; }
.info-label { width: 120px; font-weight: 600; color: #7f8c8d; font-size: 14px; }
.info-value { flex: 1; color: #2c3e50; font-size: 14px; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
.stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px; }
.stat-icon { font-size: 30px; }
.stat-info h3 { margin: 0; font-size: 24px; font-weight: 700; color: #2c3e50; }
.stat-info p { margin: 0; color: #7f8c8d; font-size: 14px; }

/* 멀티미디어 갤러리 스타일 */
.photo-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
.photo-item { aspect-ratio: 1; border-radius: 8px; overflow: hidden; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.3s; position: relative; background: #f0f0f0; }
.photo-item:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
.photo-item img { width: 100%; height: 100%; object-fit: cover; }
.vr-badge { position: absolute; top: 10px; left: 10px; background: rgba(59, 130, 246, 0.9); color: white; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; z-index: 2; }

/* 동영상 스타일 */
.video-container { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px; background: #000; }
.video-container iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }

/* 지도 스타일 */
.map-container { width: 100%; height: 450px; border-radius: 10px; overflow: hidden; border: 1px solid #e0e0e0; position: relative; }
#map { width: 100%; height: 100%; }
.map-legend { position: absolute; bottom: 10px; left: 10px; background: rgba(255,255,255,0.95); padding: 10px 15px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); font-size: 12px; z-index: 10; }
.map-legend-item { display: flex; align-items: center; gap: 8px; margin-bottom: 5px; }
.map-legend-item:last-child { margin-bottom: 0; }
.legend-line { width: 25px; height: 4px; background: #db4040; border-radius: 2px; }
.legend-marker-start { width: 12px; height: 12px; background: #e74c3c; border-radius: 50%; border: 2px solid white; box-shadow: 0 1px 3px rgba(0,0,0,0.3); }
.legend-marker-end { width: 12px; height: 12px; background: #3498db; border-radius: 50%; border: 2px solid white; box-shadow: 0 1px 3px rgba(0,0,0,0.3); }
.legend-polygon { width: 20px; height: 15px; background: rgba(255, 0, 0, 0.3); border: 2px solid #ff0000; border-radius: 3px; }

/* 라이트박스 */
.lightbox { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.9); justify-content: center; align-items: center; }
.lightbox.active { display: flex; }
.lightbox-content { max-width: 90%; max-height: 90vh; border-radius: 4px; box-shadow: 0 0 20px rgba(0,0,0,0.5); object-fit: contain; }
.lightbox-close { position: absolute; top: 20px; right: 30px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; z-index: 10000; }
.lightbox-close:hover { color: #bbb; }

/* VR 뷰어 모달 */
.vr-modal { display: none; position: fixed; z-index: 9998; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); }
.vr-modal.active { display: block; }
#panorama { width: 90%; height: 80%; margin: 5% auto; background-color: #000; border-radius: 8px; box-shadow: 0 0 20px rgba(255,255,255,0.1); }

/* 수목 현황 테이블 스타일 */
.quantity-badge { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
.species-name { font-weight: 600; color: #2c3e50; }
.scientific-name { color: #7f8c8d; font-style: italic; font-size: 12px; }
.size-spec { background: #f3f4f6; color: #4b5563; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-family: monospace; border: 1px solid #e5e7eb; }
.action-icon { text-decoration: none; margin-left: 5px; font-size: 14px; }

.route-info-box { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1px solid #86efac; border-radius: 8px; padding: 15px; margin-top: 15px; }
.route-info-box h4 { margin: 0 0 10px 0; color: #166534; font-size: 14px; }
.route-info-item { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 13px; color: #15803d; }
.route-info-item:last-child { margin-bottom: 0; }

.map-controls { position: absolute; top: 10px; right: 10px; z-index: 20; display: flex; gap: 5px; }
.map-btn { background: white; border: 1px solid #999; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; color: #333; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
.map-btn:hover { background: #f8f9fa; }
.map-btn.active { background: #4a90e2; color: white; border-color: #357abd; }

.photo-stats { display: flex; gap: 8px; align-items: center; }
.photo-stats .badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
.photo-stats .badge-total { background: #e5e7eb; color: #374151; }
.photo-stats .badge-gps { background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color: white; }
.photo-stats .badge-vr { background: linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%); color: white; }
</style>

<div class="page-header">
    <h2>📍 장소 상세보기</h2>
    <div>
        <a href="list.php" class="btn btn-secondary">← 목록으로</a>
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
        <a href="manage_trees.php?location_id=<?php echo $location_id; ?>" class="btn btn-success">🌳 수목 관리</a>
        <a href="report.php?id=<?php echo $location_id; ?>" class="btn btn-primary">📊 관리대장</a>
        <a href="edit.php?id=<?php echo $location_id; ?>" class="btn btn-primary">✏️ 수정</a>
        <a href="delete.php?id=<?php echo $location_id; ?>" class="btn btn-danger">🗑️ 삭제</a>
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
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span>📷 사진 갤러리</span>
            <div class="photo-stats">
                <span class="badge badge-total">전체 <?= count($regular_photos) ?>장</span>
                <?php 
                $gps_count = count(array_filter($regular_photos, fn($p) => $p['gps_latitude'] && $p['gps_longitude']));
                if ($gps_count > 0): 
                ?>
                <span class="badge badge-gps">📍 GPS <?= $gps_count ?>장</span>
                <?php endif; ?>
            </div>
        </div>
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
                        <img src="<?php echo BASE_URL . '/' . $photo['file_path']; ?>" alt="360 VR Photo">
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
            
            if (strpos($video_url, 'youtube.com') !== false || strpos($video_url, 'youtu.be') !== false) {
                preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\?\/]+)/', $video_url, $matches);
                if (isset($matches[1])) {
                    $embed_url = 'https://www.youtube.com/embed/' . $matches[1];
                }
            }
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
                    <a href="<?php echo htmlspecialchars($video_url); ?>" target="_blank" class="btn btn-primary">
                        🔗 동영상 보기
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php 
// [모듈을 위한 데이터 준비]
$mapPhotos = [];
foreach ($gps_photos as $photo) {
    $mapPhotos[] = [
        'lat' => (float)$photo['gps_latitude'],
        'lng' => (float)$photo['gps_longitude'],
        'url' => getSafeFileUrl($photo['file_path']),
        'name' => $photo['file_name'],
        'date' => date('Y.m.d H:i', strtotime($photo['uploaded_at'])),
        'type' => $photo['photo_type']
    ];
}

$mapData = [
    'lat' => (float)$location['latitude'],
    'lng' => (float)$location['longitude'],
    'name' => $location['location_name'],
    'geom_wkt' => $location['geom_wkt'] ?? '',
    'section_start' => $location['section_start'] ?? '',
    'section_end' => $location['section_end'] ?? '',
    'length' => (float)$location['length'],
    'photos' => $mapPhotos
];

// 모듈 불러오기
include '../../includes/map_component.php';
 ?>

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
        <div class="empty-state" style="text-align: center; padding: 40px;">
            <div class="empty-icon" style="font-size: 48px;">🌱</div>
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
// 라이트박스 함수
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

// VR 뷰어 함수
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

// 지도 초기화 및 GPS 마커 표시
<?php if ($location['latitude'] && $location['longitude']): ?>
(function() {
    var mapContainer = document.getElementById('map');
    if (!mapContainer) return;

    var centerLat = <?php echo $location['latitude']; ?>;
    var centerLng = <?php echo $location['longitude']; ?>;
    var geomWkt = <?php echo json_encode($location['geom_wkt'] ?? ''); ?>;

    var mapOption = {
        center: new kakao.maps.LatLng(centerLat, centerLng),
        level: 3
    };

    var map = new kakao.maps.Map(mapContainer, mapOption);
    window.detailMap = map; // 전역 변수 저장
    
    // WKT(경로/폴리곤) 표시 로직 (기존과 동일)
    if (geomWkt) {
        if (geomWkt.indexOf('LINESTRING') === 0) {
            // LINESTRING 파싱
            var coordsStr = geomWkt.replace('LINESTRING(', '').replace(')', '');
            var coords = coordsStr.split(',');
            var path = [];
            
            coords.forEach(function(coord) {
                var parts = coord.trim().split(' ');
                if (parts.length >= 2) {
                    path.push(new kakao.maps.LatLng(parseFloat(parts[1]), parseFloat(parts[0])));
                }
            });
            
            if (path.length > 0) {
                var polyline = new kakao.maps.Polyline({ map: map, path: path, strokeWeight: 6, strokeColor: '#db4040', strokeOpacity: 0.9, strokeStyle: 'solid' });
                var startMarker = new kakao.maps.Marker({ map: map, position: path[0], image: new kakao.maps.MarkerImage('https://www.im4u.kr/icons/uploads/icons/red_b_1765370086_8bf43d62.png', new kakao.maps.Size(50, 45), { offset: new kakao.maps.Point(15, 43) }) });
                var endMarker = new kakao.maps.Marker({ map: map, position: path[path.length - 1], image: new kakao.maps.MarkerImage('https://www.im4u.kr/icons/uploads/icons/blue_b_1765370086_8af75b2a.png', new kakao.maps.Size(50, 45), { offset: new kakao.maps.Point(15, 43) }) });
                
                var startInfo = new kakao.maps.InfoWindow({ content: '<div style="padding:5px 10px;font-size:12px;font-weight:600;color:#e74c3c;">시점</div>' });
                var endInfo = new kakao.maps.InfoWindow({ content: '<div style="padding:5px 10px;font-size:12px;font-weight:600;color:#3498db;">종점</div>' });
                
                kakao.maps.event.addListener(startMarker, 'mouseover', function() { startInfo.open(map, startMarker); });
                kakao.maps.event.addListener(startMarker, 'mouseout', function() { startInfo.close(); });
                kakao.maps.event.addListener(endMarker, 'mouseover', function() { endInfo.open(map, endMarker); });
                kakao.maps.event.addListener(endMarker, 'mouseout', function() { endInfo.close(); });
                
                var bounds = new kakao.maps.LatLngBounds();
                path.forEach(function(p) { bounds.extend(p); });
                map.setBounds(bounds, 50);
            }
        } else if (geomWkt.indexOf('POLYGON') === 0) {
            // POLYGON 파싱
            var coordsStr = geomWkt.replace('POLYGON((', '').replace('))', '');
            var coords = coordsStr.split(',');
            var path = [];
            coords.forEach(function(coord) { var parts = coord.trim().split(' '); if (parts.length >= 2) { path.push(new kakao.maps.LatLng(parseFloat(parts[1]), parseFloat(parts[0]))); } });
            
            if (path.length > 2) {
                var polygon = new kakao.maps.Polygon({ map: map, path: path, strokeWeight: 3, strokeColor: '#ff0000', strokeOpacity: 1, fillColor: '#ff0000', fillOpacity: 0.3 });
                var marker = new kakao.maps.Marker({ position: new kakao.maps.LatLng(centerLat, centerLng), map: map });
                var infowindow = new kakao.maps.InfoWindow({ content: '<div style="padding:10px;font-size:14px;font-weight:600;"><?php echo htmlspecialchars($location['location_name']); ?></div>' });
                infowindow.open(map, marker);
                var bounds = new kakao.maps.LatLngBounds();
                path.forEach(function(p) { bounds.extend(p); });
                map.setBounds(bounds, 50);
            }
        } else {
            // 일반 마커
            var marker = new kakao.maps.Marker({ position: new kakao.maps.LatLng(centerLat, centerLng), map: map });
            var infowindow = new kakao.maps.InfoWindow({ content: '<div style="padding:10px;font-size:14px;font-weight:600;"><?php echo htmlspecialchars($location['location_name']); ?></div>' });
            infowindow.open(map, marker);
        }
    } else {
        var marker = new kakao.maps.Marker({ position: new kakao.maps.LatLng(centerLat, centerLng), map: map });
        var infowindow = new kakao.maps.InfoWindow({ content: '<div style="padding:10px;font-size:14px;font-weight:600;"><?php echo htmlspecialchars($location['location_name']); ?></div>' });
        infowindow.open(map, marker);
    }
    
    // ----------------------------------------------------------------
    // GPS 사진 마커 표시 (JSON 인코딩으로 안전하게 출력)
    // ----------------------------------------------------------------
    <?php if (!empty($gps_photos)): ?>
    var photoMarkers = [
        <?php foreach ($gps_photos as $idx => $photo): ?>
        {
            photoId: <?php echo json_encode($photo['photo_id']); ?>,
            lat: <?php echo json_encode((float)$photo['gps_latitude']); ?>,
            lng: <?php echo json_encode((float)$photo['gps_longitude']); ?>,
            filePath: <?php echo json_encode(BASE_URL . '/' . $photo['file_path']); ?>,
            fileName: <?php echo json_encode($photo['file_name']); ?>,
            uploadDate: <?php echo json_encode(date('Y.m.d H:i', strtotime($photo['uploaded_at']))); ?>,
            photoType: <?php echo json_encode($photo['photo_type']); ?>
        }<?php echo ($idx < count($gps_photos) - 1) ? ',' : ''; ?>
        <?php endforeach; ?>
    ];
    
    var photoMarkerImage = new kakao.maps.MarkerImage('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAzMiAzMiIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48Y2lyY2xlIGN4PSIxNiIgY3k9IjE2IiByPSIxNCIgZmlsbD0iIzNiODJmNiIgc3Ryb2tlPSJ3aGl0ZSIgc3Ryb2tlLXdpZHRoPSIzIi8+PHRleHQgeD0iMTYiIHk9IjIxIiBmb250LXNpemU9IjE2IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSJ3aGl0ZSI+8J+TtjwvdGV4dD48L3N2Zz4=', new kakao.maps.Size(32, 32), { offset: new kakao.maps.Point(16, 16) });
    var vrMarkerImage = new kakao.maps.MarkerImage('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAzMiAzMiIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48Y2lyY2xlIGN4PSIxNiIgY3k9IjE2IiByPSIxNCIgZmlsbD0iIzhjM2FlZCIgc3Ryb2tlPSJ3aGl0ZSIgc3Ryb2tlLXdpZHRoPSIzIi8+PHRleHQgeD0iMTYiIHk9IjIxIiBmb250LXNpemU9IjE2IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSJ3aGl0ZSI+8J+UruKAnPCfkbc8L3RleHQ+PC9zdmc+', new kakao.maps.Size(32, 32), { offset: new kakao.maps.Point(16, 16) });
    
    photoMarkers.forEach(function(photoData) {
        var markerImage = photoData.photoType === 'vr360' ? vrMarkerImage : photoMarkerImage;
        var marker = new kakao.maps.Marker({ position: new kakao.maps.LatLng(photoData.lat, photoData.lng), map: map, image: markerImage, clickable: true, zIndex: 100 });
        
        var infoContent = '<div style="padding:12px;min-width:200px;max-width:250px;">' +
            '<div style="margin-bottom:8px;">' +
            '<img src="' + photoData.filePath + '" style="width:100%;height:120px;object-fit:cover;border-radius:6px;display:block;">' +
            '</div>' +
            '<div style="font-weight:600;font-size:13px;color:#2c3e50;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' +
            (photoData.photoType === 'vr360' ? '🔮 ' : '📷 ') + photoData.fileName +
            '</div>' +
            '<div style="font-size:11px;color:#7f8c8d;">' + '📅 ' + photoData.uploadDate + '</div>' +
            '<div style="font-size:10px;color:#95a5a6;margin-top:4px;">' + '📍 ' + photoData.lat.toFixed(6) + ', ' + photoData.lng.toFixed(6) + '</div>' +
            '<div style="margin-top:8px;text-align:center;">' +
            '<button onclick="' + (photoData.photoType === 'vr360' ? 'openVR' : 'openLightbox') + '(\'' + photoData.filePath + '\')" style="background:#3b82f6;color:white;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:600;">' +
            (photoData.photoType === 'vr360' ? '360° 보기' : '크게 보기') + '</button>' +
            '</div></div>';
        
        var infowindow = new kakao.maps.InfoWindow({ content: infoContent, removable: true });
        kakao.maps.event.addListener(marker, 'click', function() { if (window.currentPhotoInfowindow) { window.currentPhotoInfowindow.close(); } infowindow.open(map, marker); window.currentPhotoInfowindow = infowindow; });
        
        var tooltip = new kakao.maps.InfoWindow({ content: '<div style="padding:5px 10px;font-size:11px;font-weight:600;white-space:nowrap;">' + (photoData.photoType === 'vr360' ? '🔮 ' : '📷 ') + photoData.fileName.substring(0, 20) + (photoData.fileName.length > 20 ? '...' : '') + '</div>' });
        kakao.maps.event.addListener(marker, 'mouseover', function() { if (!window.currentPhotoInfowindow || window.currentPhotoInfowindow !== infowindow) { tooltip.open(map, marker); } });
        kakao.maps.event.addListener(marker, 'mouseout', function() { tooltip.close(); });
    });
    <?php endif; ?>
})();
<?php endif; ?>

// 지도 타입 제어 함수
function setMapType(maptype, btn) {
    var roadmapBtn = document.getElementById('btnRoadmap');
    var skyviewBtn = document.getElementById('btnSkyview');
    if (maptype === 'roadmap') { window.detailMap.setMapTypeId(kakao.maps.MapTypeId.ROADMAP); roadmapBtn.classList.add('active'); skyviewBtn.classList.remove('active'); } 
    else { window.detailMap.setMapTypeId(kakao.maps.MapTypeId.HYBRID); roadmapBtn.classList.remove('active'); skyviewBtn.classList.add('active'); }
}
function toggleVWorld(btn) {
    if (btn.classList.contains('active')) { window.detailMap.removeOverlayMapTypeId(kakao.maps.MapTypeId.USE_DISTRICT); btn.classList.remove('active'); } 
    else { window.detailMap.addOverlayMapTypeId(kakao.maps.MapTypeId.USE_DISTRICT); btn.classList.add('active'); }
}
</script>

<?php include '../../includes/footer.php'; ?>