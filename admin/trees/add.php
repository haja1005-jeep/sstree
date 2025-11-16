<?php
/**
 * 나무 추가 (개선 버전)
 * Smart Tree Map - Sinan County
 * 
 * 개선사항:
 * - 사진 드래그 앤 드롭 순서 변경
 * - 대표 사진 설정 기능
 * - 미리보기에서 실시간 관리
 */

require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';
checkAuth();

$page_title = '나무 추가';
$database = new Database();
$db = $database->getConnection();
$error = '';

// ALLOWED_EXTENSIONS를 배열로 변환
$allowed_ext_array = array_map('trim', array_map('strtolower', explode(',', ALLOWED_EXTENSIONS)));

/**
 * EXIF 기반 자동 회전 보정 함수
 */
function autoOrientImage($image_resource, $source_path) {
    if (!function_exists('exif_read_data')) {
        return $image_resource;
    }
    
    $exif = @exif_read_data($source_path);
    if (!empty($exif['Orientation'])) {
        switch ($exif['Orientation']) {
            case 3:
                $image_resource = imagerotate($image_resource, 180, 0);
                break;
            case 6:
                $image_resource = imagerotate($image_resource, -90, 0);
                break;
            case 8:
                $image_resource = imagerotate($image_resource, 90, 0);
                break;
        }
    }
    return $image_resource;
}


/**
 * EXIF GPS 데이터를 실제 좌표로 변환
 */
function getGpsFromExif($filepath) {
    if (!function_exists('exif_read_data')) {
        return null;
    }
    
    $exif = @exif_read_data($filepath);
    if (empty($exif['GPSLatitude']) || empty($exif['GPSLongitude'])) {
        return null;
    }
    
    // 위도 변환
    $lat = convertGpsCoordinate($exif['GPSLatitude'], $exif['GPSLatitudeRef']);
    // 경도 변환
    $lng = convertGpsCoordinate($exif['GPSLongitude'], $exif['GPSLongitudeRef']);
    
    if ($lat === null || $lng === null) {
        return null;
    }
    
    return [
        'latitude' => $lat,
        'longitude' => $lng
    ];
}

/**
 * GPS 좌표 변환 (도분초 → 십진수)
 */
function convertGpsCoordinate($coordinate, $hemisphere) {
    if (!is_array($coordinate) || count($coordinate) < 3) {
        return null;
    }
    
    // 분수 형태의 값을 십진수로 변환
    $degrees = eval('return ' . $coordinate[0] . ';');
    $minutes = eval('return ' . $coordinate[1] . ';');
    $seconds = eval('return ' . $coordinate[2] . ';');
    
    // 십진수 좌표 계산
    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
    
    // 남반구(S) 또는 서경(W)이면 음수로
    if ($hemisphere == 'S' || $hemisphere == 'W') {
        $decimal *= -1;
    }
    
    return $decimal;
}



function processAndSaveImage($source_path, $destination_path, $max_width = 1920, $quality = 85) {
    ini_set('memory_limit', '512M');
    set_time_limit(300);

    try {
        $info = getimagesize($source_path);
        if (!$info) return false;
        $mime = $info['mime'];
        $width = $info[0];
        $height = $info[1];
        
        if ($width <= $max_width) {
            $new_width = $width;
            $new_height = $height;
        } else {
            $new_width = $max_width;
            $new_height = (int)(($height / $width) * $new_width);
        }
        
        $destination_image = imagecreatetruecolor((int)$new_width, (int)$new_height);
        $source_image = null;

        switch ($mime) {
            case 'image/jpeg': 
                $source_image = imagecreatefromjpeg($source_path); 
                $source_image = autoOrientImage($source_image, $source_path);
                break;
            case 'image/png': 
                $source_image = imagecreatefrompng($source_path); 
                imagealphablending($destination_image, false);
                imagesavealpha($destination_image, true);
                break;
            case 'image/gif': 
                $source_image = imagecreatefromgif($source_path); 
                break;
            default:
                imagedestroy($destination_image);
                return move_uploaded_file($source_path, $destination_path);
        }

        if ($source_image === null) return false;

        imagecopyresampled($destination_image, $source_image, 0, 0, 0, 0, (int)$new_width, (int)$new_height, $width, $height);
        
        $success = false;
        switch ($mime) {
            case 'image/jpeg': $success = imagejpeg($destination_image, $destination_path, $quality); break;
            case 'image/png': $success = imagepng($destination_image, $destination_path, 8); break;
            case 'image/gif': $success = imagegif($destination_image, $destination_path); break;
        }
        
        imagedestroy($source_image);
        imagedestroy($destination_image);
        return $success;

    } catch (Exception $e) {
        return false;
    }
}

// 폼 제출 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $region_id = (int)$_POST['region_id'];
    $category_id = (int)$_POST['category_id'];
    $location_id = (int)$_POST['location_id'];
    $species_id = (int)$_POST['species_id'];
    $tree_number = sanitize($_POST['tree_number']);
    $planting_date = !empty($_POST['planting_date']) ? sanitize($_POST['planting_date']) : null;
    $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;
    $diameter = !empty($_POST['diameter']) ? (float)$_POST['diameter'] : null;
    $health_status = sanitize($_POST['health_status']);
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $notes = sanitize($_POST['notes']);
    
    $saved_files = [];
    
    if ($region_id == 0 || $category_id == 0 || $location_id == 0 || $species_id == 0) {
        $error = '지역, 카테고리, 장소, 수종은 필수 항목입니다.';
    } else {
        try {
            $db->beginTransaction();
            
            $query = "INSERT INTO trees (region_id, category_id, location_id, species_id, tree_number, 
                                        planting_date, height, diameter, health_status, latitude, longitude, 
                                        notes, created_by, created_at) 
                      VALUES (:region_id, :category_id, :location_id, :species_id, :tree_number, 
                              :planting_date, :height, :diameter, :health_status, :latitude, :longitude, 
                              :notes, :created_by, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':region_id', $region_id);
            $stmt->bindParam(':category_id', $category_id);
            $stmt->bindParam(':location_id', $location_id);
            $stmt->bindParam(':species_id', $species_id);
            $stmt->bindParam(':tree_number', $tree_number);
            $stmt->bindParam(':planting_date', $planting_date);
            $stmt->bindParam(':height', $height);
            $stmt->bindParam(':diameter', $diameter);
            $stmt->bindParam(':health_status', $health_status);
            $stmt->bindParam(':latitude', $latitude);
            $stmt->bindParam(':longitude', $longitude);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':created_by', $_SESSION['user_id']);
            $stmt->execute();
            
            $tree_id = $db->lastInsertId();
            
            // 파일 업로드
            $upload_error = false;
            $max_mb = MAX_FILE_SIZE / 1024 / 1024;
            $allowed_ext_str = implode(', ', $allowed_ext_array);
            
            if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
                $upload_dir = UPLOAD_PATH;
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $photo_count = 0; // 사진 순서 카운터
                
                foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                    if (empty($tmp_name) || $_FILES['photos']['error'][$key] !== UPLOAD_ERR_OK) continue;
                    
                    $file_name = $_FILES['photos']['name'][$key];
                    $file_size = $_FILES['photos']['size'][$key];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $photo_type = isset($_POST['photo_types'][$key]) ? sanitize($_POST['photo_types'][$key]) : 'full';
                    
                    if (!in_array($file_ext, $allowed_ext_array)) {
                        $error .= "{$file_name}: 허용되지 않는 파일 형식입니다. ({$allowed_ext_str}만 가능)<br>";
                        $upload_error = true;
                    } elseif ($file_size > MAX_FILE_SIZE) {
                        $error .= "{$file_name}: 파일 용량이 너무 큽니다. ({$max_mb}MB 이하만 가능)<br>";
                        $upload_error = true;
                    } else {
                        $new_file_name = 'tree_' . $tree_id . '_' . uniqid() . '.' . $file_ext;
                        $file_path = $upload_dir . $new_file_name;

                        if (processAndSaveImage($tmp_name, $file_path, 1920, 85)) {
                            $saved_files[] = $file_path;
                            
                            // ✅ EXIF GPS 데이터 추출
                            $gps_data = getGpsFromExif($tmp_name);
                            $photo_lat = $gps_data ? $gps_data['latitude'] : null;
                            $photo_lng = $gps_data ? $gps_data['longitude'] : null;
                            
                            // 사진 순서와 대표 사진 설정
                            $photo_count++;
                            $sort_order = $photo_count;
                            // 첫 번째 사진만 대표 사진으로 설정
                            $is_main = ($photo_count === 1) ? 1 : 0;
                            
                            $photo_query = "INSERT INTO tree_photos (tree_id, file_path, file_name, file_size, photo_type, 
                                                                     sort_order, is_main, latitude, longitude, uploaded_by, uploaded_at) 
                                           VALUES (:tree_id, :file_path, :file_name, :file_size, :photo_type, 
                                                   :sort_order, :is_main, :latitude, :longitude, :uploaded_by, NOW())";
                            $photo_stmt = $db->prepare($photo_query);
                            $relative_path = 'uploads/photos/' . $new_file_name;
                            $file_size_after = filesize($file_path);
                            
                            $photo_stmt->bindParam(':tree_id', $tree_id);
                            $photo_stmt->bindParam(':file_path', $relative_path);
                            $photo_stmt->bindParam(':file_name', $file_name);
                            $photo_stmt->bindParam(':file_size', $file_size_after);
                            $photo_stmt->bindParam(':photo_type', $photo_type);
                            $photo_stmt->bindParam(':sort_order', $sort_order);
                            $photo_stmt->bindParam(':is_main', $is_main);
                            $photo_stmt->bindParam(':latitude', $photo_lat);
                            $photo_stmt->bindParam(':longitude', $photo_lng);
                            $photo_stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                            $photo_stmt->execute();
                        } else {
                            $error .= "{$file_name}: 파일 저장 중 오류가 발생했습니다.<br>";
                            $upload_error = true;
                        }
                    }
                }
            }
            
            if ($upload_error) {
                throw new Exception("파일 업로드 실패:<br>" . $error);
            }
            
            logActivity($_SESSION['user_id'], 'create', 'tree', $tree_id, "나무 추가: {$tree_number}");
            
            $db->commit();
            
            redirect('/admin/trees/view.php?id=' . $tree_id . '&message=' . urlencode('나무가 추가되었습니다.'));
            
        } catch (Exception $e) {
            $db->rollBack();
            
            // 고아 파일 삭제
            foreach ($saved_files as $file_to_delete) {
                if (file_exists($file_to_delete)) {
                    @unlink($file_to_delete);
                }
            }
            
            $error = $e->getMessage();
        }
    }
}

// 폼 데이터 조회
$regions_query = "SELECT * FROM regions ORDER BY region_name";
$regions_stmt = $db->prepare($regions_query);
$regions_stmt->execute();
$regions = $regions_stmt->fetchAll();

$categories_query = "SELECT * FROM categories ORDER BY category_name";
$categories_stmt = $db->prepare($categories_query);
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll();

$locations_query = "SELECT l.*, r.region_name, c.category_name 
                   FROM locations l 
                   LEFT JOIN regions r ON l.region_id = r.region_id
                   LEFT JOIN categories c ON l.category_id = c.category_id
                   ORDER BY location_name";
$locations_stmt = $db->prepare($locations_query);
$locations_stmt->execute();
$locations = $locations_stmt->fetchAll();

$species_query = "SELECT * FROM tree_species_master ORDER BY korean_name";
$species_stmt = $db->prepare($species_query);
$species_stmt->execute();
$species_list = $species_stmt->fetchAll();

// 최근 추가한 나무 5개 (자동완성용)
$recent_trees_query = "SELECT t.*, l.location_name, s.korean_name as species_name,
                       r.region_name, c.category_name
                       FROM trees t
                       LEFT JOIN locations l ON t.location_id = l.location_id
                       LEFT JOIN tree_species_master s ON t.species_id = s.species_id
                       LEFT JOIN regions r ON t.region_id = r.region_id
                       LEFT JOIN categories c ON t.category_id = c.category_id
                       ORDER BY t.created_at DESC LIMIT 5";
$recent_trees_stmt = $db->prepare($recent_trees_query);
$recent_trees_stmt->execute();
$recent_trees = $recent_trees_stmt->fetchAll();

// 장소별 수종 (JSON 형태로 JavaScript에서 사용)
$location_species_query = "SELECT t.location_id, s.species_id, s.korean_name, s.scientific_name,
                            COUNT(*) as tree_count
                            FROM trees t
                            INNER JOIN tree_species_master s ON t.species_id = s.species_id
                            GROUP BY t.location_id, s.species_id, s.korean_name, s.scientific_name
                            ORDER BY t.location_id, tree_count DESC";
$location_species_stmt = $db->prepare($location_species_query);
$location_species_stmt->execute();
$location_species_raw = $location_species_stmt->fetchAll();

// 장소별로 그룹화
$location_species_map = [];
foreach ($location_species_raw as $row) {
    $loc_id = $row['location_id'];
    if (!isset($location_species_map[$loc_id])) {
        $location_species_map[$loc_id] = [];
    }
    $location_species_map[$loc_id][] = $row;
}

// 장소별 마지막 나무번호 (자동화용)
// T-001, T-002 또는 001, 002 형식 모두 지원
$location_last_number_query = "SELECT location_id, 
                                MAX(CAST(REPLACE(REPLACE(tree_number, 'T-', ''), 't-', '') AS UNSIGNED)) as last_number
                                FROM trees
                                WHERE tree_number REGEXP '^[Tt]-?[0-9]+$' OR tree_number REGEXP '^[0-9]+$'
                                GROUP BY location_id";
$location_last_number_stmt = $db->prepare($location_last_number_query);
$location_last_number_stmt->execute();
$location_last_numbers_raw = $location_last_number_stmt->fetchAll();

// 장소별 마지막 번호 맵
$location_last_numbers = [];
foreach ($location_last_numbers_raw as $row) {
    $location_last_numbers[$row['location_id']] = $row['last_number'];
}

include '../../includes/header.php';
?>

<style>
.map-container { width: 100%; height: 400px; border: 1px solid #ddd; border-radius: 8px; margin-top: 10px; }

.image-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.preview-item {
    position: relative;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    overflow: hidden;
    background: #f9fafb;
    cursor: move;
    transition: transform 0.2s;
}

.preview-item:hover {
    transform: scale(1.05);
}

.preview-item.is-main {
    border-color: #fbbf24;
    box-shadow: 0 0 10px rgba(251, 191, 36, 0.5);
}

.preview-item.gps-used {
    border-color: #ef4444;
    box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
}

.preview-item.gps-used .use-gps-btn {
    background: rgba(239, 68, 68, 0.95);
}

.preview-item.gps-used .use-gps-btn:hover {
    background: rgba(220, 38, 38, 1);
}

.preview-item img {
    width: 100%;
    height: 150px;
    object-fit: cover;
    display: block;
}

.preview-item .file-info {
    padding: 8px;
    font-size: 12px;
    color: #6b7280;
    background: white;
    border-top: 1px solid #e5e7eb;
    text-overflow: ellipsis;
    overflow: hidden;
    white-space: nowrap;
}

.preview-item .main-badge {
    position: absolute;
    top: 5px;
    left: 5px;
    background: #fbbf24;
    color: #78350f;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
    z-index: 10;
}

.preview-item .set-main-btn {
    position: absolute;
    bottom: 35px;
    right: 5px;
    background: rgba(59, 130, 246, 0.9);
    color: white;
    border: none;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    cursor: pointer;
    z-index: 10;
}

.preview-item .set-main-btn:hover {
    background: rgba(37, 99, 235, 1);
}

.drag-handle {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 24px;
    color: white;
    text-shadow: 0 0 4px rgba(0,0,0,0.8);
    opacity: 0;
    transition: opacity 0.2s;
    pointer-events: none;
}

.preview-item:hover .drag-handle {
    opacity: 1;
}

.sortable-ghost {
    opacity: 0.4;
}
.use-gps-btn {
    position: absolute;
    bottom: 35px;
    left: 5px;
    background: rgba(59, 130, 246, 0.95);
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 11px;
    cursor: pointer;
    z-index: 10;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    transition: all 0.2s;
}

.use-gps-btn:hover {
    background: rgba(37, 99, 235, 1);
    transform: scale(1.05);
}

.no-gps-label {
    position: absolute;
    bottom: 35px;
    left: 5px;
    background: rgba(156, 163, 175, 0.9);
    color: white;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    z-index: 10;
}

.btn-info {
    background: #0ea5e9;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    font-weight: 600;
    box-shadow: 0 2px 6px rgba(14, 165, 233, 0.3);
    transition: all 0.2s;
}

.btn-info:hover {
    background: #0284c7;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(14, 165, 233, 0.4);
}


.preview-item .remove-btn {
    position: absolute;
    top: 5px;
    right: 5px;
    background: rgba(239, 68, 68, 0.9);
    color: white;
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    cursor: pointer;
    font-size: 14px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    z-index: 10;
}

.preview-item .remove-btn:hover {
    background: rgba(220, 38, 38, 1);
    transform: scale(1.1);
}

.preview-item .type-selector {
    padding: 5px 8px;
    width: 100%;
    border: none;
    border-top: 1px solid #e5e7eb;
    font-size: 11px;
    background: white;
    cursor: pointer;
}

.preview-item .type-selector:focus {
    outline: 2px solid #667eea;
    outline-offset: -2px;
}
</style>

<div class="page-header">
    <h2>🌳 나무 추가</h2>
    <div style="display: flex; gap: 10px;">
        <button type="button" class="btn btn-info" onclick="toggleRecentList()">
            📋 최근 작업 목록
        </button>
        <a href="list.php" class="btn btn-secondary">← 목록으로</a>
    </div>
</div>

<!-- 최근 작업 목록 팝업 -->
<div id="recent-list-popup" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 10px; padding: 30px; max-width: 800px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3>📋 최근 추가한 나무</h3>
            <button onclick="toggleRecentList()" class="btn btn-sm btn-secondary">✕ 닫기</button>
        </div>
        <div class="recent-trees-list">
            <?php if (count($recent_trees) > 0): ?>
                <?php foreach ($recent_trees as $rtree): ?>
                    <div class="recent-tree-item" onclick="loadRecentTree(<?php echo htmlspecialchars(json_encode($rtree)); ?>)" 
                         style="padding: 15px; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 10px; cursor: pointer; transition: all 0.2s;">
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 5px;">
                            🌳 <?php echo htmlspecialchars($rtree['species_name'] ?: '수종 미지정'); ?>
                            <?php if ($rtree['tree_number']): ?>
                                <span style="color: #6b7280; font-size: 14px;">(<?php echo htmlspecialchars($rtree['tree_number']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 13px; color: #6b7280;">
                            📍 <?php echo htmlspecialchars($rtree['location_name'] ?: '-'); ?> • 
                            🏷️ <?php echo htmlspecialchars($rtree['category_name'] ?: '-'); ?> • 
                            📅 <?php echo date('Y-m-d', strtotime($rtree['created_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: #9ca3af; padding: 40px;">최근 추가한 나무가 없습니다.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.recent-tree-item:hover {
    background: #f3f4f6;
    border-color: #3b82f6;
    transform: translateY(-2px);
}
</style>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="" enctype="multipart/form-data">
            
            <h4 style="margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">기본 정보</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="location_id">장소 <span style="color:red;">*</span></label>
                    <select id="location_id" name="location_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['location_id']; ?>"
                                    data-region="<?php echo $location['region_id']; ?>"
                                    data-category="<?php echo $location['category_id']; ?>"
                                    <?php echo (isset($_POST['location_id']) && $_POST['location_id'] == $location['location_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($location['location_name']); ?>
                                (<?php echo htmlspecialchars($location['region_name']); ?> / 
                                 <?php echo htmlspecialchars($location['category_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #6b7280; font-size: 13px;">장소를 선택하면 지역과 카테고리가 자동으로 설정됩니다.</small>
                </div>
                
                <div class="form-group">
                    <label for="species_id">수종 <span style="color:red;">*</span></label>
                    <select id="species_id" name="species_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($species_list as $species): ?>
                            <option value="<?php echo $species['species_id']; ?>"
                                    <?php echo (isset($_POST['species_id']) && $_POST['species_id'] == $species['species_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($species['korean_name']); ?>
                                (<?php echo htmlspecialchars($species['scientific_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group" style="display: none;">
                    <label for="region_id">지역</label>
                    <select id="region_id" name="region_id">
                        <option value="0">자동 설정됨</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo $region['region_id']; ?>">
                                <?php echo htmlspecialchars($region['region_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group" style="display: none;">
                    <label for="category_id">카테고리</label>
                    <select id="category_id" name="category_id">
                        <option value="0">자동 설정됨</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['category_id']; ?>">
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">나무 정보</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="tree_number">나무 번호</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="text" id="tree_number" name="tree_number" 
                               placeholder="예: T-001" style="flex: 1;"
                               value="<?php echo isset($_POST['tree_number']) ? htmlspecialchars($_POST['tree_number']) : ''; ?>">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="autoGenerateTreeNumber()" 
                                title="장소의 다음 번호 자동생성">
                            🔢 자동
                        </button>
                    </div>
                    <small style="color: #6b7280; font-size: 12px;">장소를 먼저 선택하면 자동 번호를 생성할 수 있습니다.</small>
                </div>
                
                <div class="form-group">
                    <label for="planting_date">식재일</label>
                    <input type="date" id="planting_date" name="planting_date" 
                           value="<?php echo isset($_POST['planting_date']) ? htmlspecialchars($_POST['planting_date']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="health_status">건강상태 <span style="color:red;">*</span></label>
                    <select id="health_status" name="health_status" required>
                        <option value="excellent" <?php echo (isset($_POST['health_status']) && $_POST['health_status'] == 'excellent') ? 'selected' : ''; ?>>최상</option>
                        <option value="good" <?php echo (!isset($_POST['health_status']) || $_POST['health_status'] == 'good') ? 'selected' : ''; ?>>양호</option>
                        <option value="fair" <?php echo (isset($_POST['health_status']) && $_POST['health_status'] == 'fair') ? 'selected' : ''; ?>>보통</option>
                        <option value="poor" <?php echo (isset($_POST['health_status']) && $_POST['health_status'] == 'poor') ? 'selected' : ''; ?>>나쁨</option>
                        <option value="dead" <?php echo (isset($_POST['health_status']) && $_POST['health_status'] == 'dead') ? 'selected' : ''; ?>>고사</option>
                    </select>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="height">높이 (m)</label>
                    <input type="number" id="height" name="height" step="0.01" 
                           placeholder="예: 15.50" 
                           value="<?php echo isset($_POST['height']) ? htmlspecialchars($_POST['height']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="diameter">직경 (cm)</label>
                    <input type="number" id="diameter" name="diameter" step="0.01" 
                           placeholder="예: 35.00" 
                           value="<?php echo isset($_POST['diameter']) ? htmlspecialchars($_POST['diameter']) : ''; ?>">
                </div>
            </div>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">위치 정보</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="latitude">위도</label>
                    <input type="text" id="latitude" name="latitude" 
                           placeholder="예: 34.8265" 
                           value="<?php echo isset($_POST['latitude']) ? htmlspecialchars($_POST['latitude']) : ''; ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="longitude">경도</label>
                    <input type="text" id="longitude" name="longitude" 
                           placeholder="예: 126.1069" 
                           value="<?php echo isset($_POST['longitude']) ? htmlspecialchars($_POST['longitude']) : ''; ?>" readonly>
                </div>
            </div>
            <div id="map" class="map-container"></div>
            <small style="color: #6b7280; font-size: 13px; margin-top: 5px; display: block;">
                💡 사진에 GPS가 있으면 "📍 위치 사용" 버튼이 표시됩니다. 여러 사진의 GPS 평균값도 사용할 수 있습니다.
            </small>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">사진 업로드</h4>
            <div class="form-group">
                <label for="photos">일반 사진 (다중 선택 가능, 최대 <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB)</label>
                <input type="file" id="photos" name="photos[]" accept="image/*" multiple onchange="previewImages(this)">
                <div id="image-previews" class="image-preview-grid"></div>
            </div>
            <small style="color: #6b7280; font-size: 13px; margin-top: 5px; display: block;">
                💡 첫 번째 사진이 자동으로 대표 사진이 됩니다. GPS 정보가 있는 사진은 나무 위치로 사용할 수 있습니다.
            </small>
            
            <div class="form-group" style="margin-top: 30px;">
                <label for="notes">비고</label>
                <textarea id="notes" name="notes" rows="4" 
                          placeholder="나무에 대한 특이사항이나 관리 메모를 입력하세요."><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 30px;">
                <button type="submit" class="btn btn-primary">저장</button>
                <a href="list.php" class="btn btn-secondary">취소</a>
            </div>
        </form>
    </div>
</div>

<?php 
$apiKey = '';
if (defined('KAKAO_MAP_API_KEY')) $apiKey = KAKAO_MAP_API_KEY;
?>


<!-- Sortable.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<!-- EXIF.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/exif-js"></script>

<?php if ($apiKey != ''): ?>
    <script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo $apiKey; ?>"></script>
    <script>
    // PHP 데이터를 JavaScript 변수로 전달
    const locationSpeciesMap = <?php echo json_encode($location_species_map); ?>;
    const locationLastNumbers = <?php echo json_encode($location_last_numbers); ?>;
    const allSpecies = <?php echo json_encode($species_list); ?>;
    
    // 최근 작업 목록 팝업 토글
    function toggleRecentList() {
        const popup = document.getElementById('recent-list-popup');
        if (popup.style.display === 'none' || popup.style.display === '') {
            popup.style.display = 'flex';
        } else {
            popup.style.display = 'none';
        }
    }
    
    // 최근 나무 정보 불러오기
    function loadRecentTree(treeData) {
        // 장소
        if (treeData.location_id) {
            document.getElementById('location_id').value = treeData.location_id;
            // 장소 변경 이벤트 발생시켜서 지역/카테고리 자동 설정
            document.getElementById('location_id').dispatchEvent(new Event('change'));
        }
        
        // 수종
        if (treeData.species_id) {
            document.getElementById('species_id').value = treeData.species_id;
        }
        
        // 건강상태
        if (treeData.health_status) {
            document.getElementById('health_status').value = treeData.health_status;
        }
        
        // 높이
        if (treeData.height) {
            document.getElementById('height').value = treeData.height;
        }
        
        // 직경
        if (treeData.diameter) {
            document.getElementById('diameter').value = treeData.diameter;
        }
        
        // 팝업 닫기
        toggleRecentList();
        
        alert('✅ 최근 작업 정보를 불러왔습니다. 나무번호와 위치는 새로 입력해주세요.');
    }
    
    // 나무번호 자동생성
    function autoGenerateTreeNumber() {
        const locationId = document.getElementById('location_id').value;
        
        if (!locationId) {
            alert('⚠️ 장소를 먼저 선택해주세요.');
            return;
        }
        
        const lastNumber = locationLastNumbers[locationId] || 0;
        const nextNumber = parseInt(lastNumber) + 1;
        const paddedNumber = 'T-' + String(nextNumber).padStart(3, '0'); // T-001, T-002, ...
        
        document.getElementById('tree_number').value = paddedNumber;
        alert(`✅ 다음 번호가 생성되었습니다: ${paddedNumber}`);
    }
    
    // 장소 선택 시 수종 필터링
    function filterSpeciesByLocation() {
        const locationId = document.getElementById('location_id').value;
        const speciesSelect = document.getElementById('species_id');
        
        // 현재 선택된 수종 저장
        const currentSelected = speciesSelect.value;
        
        // 옵션 초기화
        speciesSelect.innerHTML = '<option value="">선택하세요</option>';
        
        if (locationId && locationSpeciesMap[locationId]) {
            // 해당 장소에 심어진 수종 목록
            const locationSpecies = locationSpeciesMap[locationId];
            
            // 장소별 수종 먼저 추가 (사용 빈도순)
            const optgroup1 = document.createElement('optgroup');
            optgroup1.label = '🌟 이 장소에 심어진 수종';
            locationSpecies.forEach(sp => {
                const option = document.createElement('option');
                option.value = sp.species_id;
                option.textContent = `${sp.korean_name} (${sp.tree_count}그루)`;
                if (sp.species_id == currentSelected) {
                    option.selected = true;
                }
                optgroup1.appendChild(option);
            });
            speciesSelect.appendChild(optgroup1);
            
            // 전체 수종 목록 추가
            const optgroup2 = document.createElement('optgroup');
            optgroup2.label = '📋 전체 수종';
            const locationSpeciesIds = locationSpecies.map(s => s.species_id);
            allSpecies.forEach(sp => {
                // 이미 위에 표시된 수종은 제외
                if (!locationSpeciesIds.includes(sp.species_id)) {
                    const option = document.createElement('option');
                    option.value = sp.species_id;
                    option.textContent = `${sp.korean_name} (${sp.scientific_name})`;
                    if (sp.species_id == currentSelected) {
                        option.selected = true;
                    }
                    optgroup2.appendChild(option);
                }
            });
            speciesSelect.appendChild(optgroup2);
        } else {
            // 장소 선택 안됨 - 전체 수종 표시
            allSpecies.forEach(sp => {
                const option = document.createElement('option');
                option.value = sp.species_id;
                option.textContent = `${sp.korean_name} (${sp.scientific_name})`;
                if (sp.species_id == currentSelected) {
                    option.selected = true;
                }
                speciesSelect.appendChild(option);
            });
        }
    }
    
    // 장소 선택 시 지역/카테고리 자동 설정 + 수종 필터링
    document.getElementById('location_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const regionId = selectedOption.getAttribute('data-region');
        const categoryId = selectedOption.getAttribute('data-category');
        
        document.getElementById('region_id').value = regionId || '0';
        document.getElementById('category_id').value = categoryId || '0';
        
        // 수종 필터링
        filterSpeciesByLocation();
    });
    
    // 카카오맵 초기화
    const mapContainer = document.getElementById('map');
    const defaultLat = <?php echo defined('DEFAULT_LAT') ? DEFAULT_LAT : '34.8265'; ?>;
    const defaultLng = <?php echo defined('DEFAULT_LNG') ? DEFAULT_LNG : '126.1069'; ?>;
    let currentLat = document.getElementById('latitude').value || defaultLat;
    let currentLng = document.getElementById('longitude').value || defaultLng;
    let zoomLevel = (document.getElementById('latitude').value) ? 5 : 9;
    
    const mapOption = { center: new kakao.maps.LatLng(currentLat, currentLng), level: zoomLevel };
    const map = new kakao.maps.Map(mapContainer, mapOption);
    let marker = null;
    
    if (document.getElementById('latitude').value && document.getElementById('longitude').value) {
        marker = new kakao.maps.Marker({ position: new kakao.maps.LatLng(currentLat, currentLng), map: map });
    }
    
    kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
        const latlng = mouseEvent.latLng;
        if (marker) marker.setMap(null);
        marker = new kakao.maps.Marker({ position: latlng, map: map });
        document.getElementById('latitude').value = latlng.getLat();
        document.getElementById('longitude').value = latlng.getLng();
    });
    
    // 전역 변수
    let photoGpsData = []; // 각 사진의 GPS 데이터
    let gpsMarkers = []; // 지도 마커 배열
    
    // 이미지 미리보기 및 GPS 추출
    function previewImages(input) {
        const preview = document.getElementById('image-previews');
        preview.innerHTML = '';
        photoGpsData = []; // 초기화
        
        // 기존 GPS 마커 제거
        gpsMarkers.forEach(marker => marker.setMap(null));
        gpsMarkers = [];
        
        if (input.files && input.files.length > 0) {
            let loadedCount = 0;
            const totalFiles = input.files.length;
            
            Array.from(input.files).forEach((file, index) => {
                // GPS 추출
                EXIF.getData(file, function() {
                    const lat = EXIF.getTag(this, 'GPSLatitude');
                    const latRef = EXIF.getTag(this, 'GPSLatitudeRef');
                    const lng = EXIF.getTag(this, 'GPSLongitude');
                    const lngRef = EXIF.getTag(this, 'GPSLongitudeRef');
                    
                    let gpsData = null;
                    if (lat && lng) {
                        gpsData = {
                            latitude: convertDMSToDD(lat, latRef),
                            longitude: convertDMSToDD(lng, lngRef)
                        };
                    }
                    photoGpsData[index] = gpsData;
                    
                    // 미리보기 생성
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.className = 'preview-item';
                        if (index === 0) div.classList.add('is-main'); // 첫 번째 = 대표
                        div.setAttribute('data-index', index);
                        
                        let gpsButton = '';
                        if (gpsData) {
                            gpsButton = `<button type="button" class="use-gps-btn" onclick="usePhotoGps(${index})" title="이 사진의 GPS를 나무 위치로 사용">📍 위치 사용</button>`;
                        } else {
                            gpsButton = '<span class="no-gps-label">GPS 없음</span>';
                        }
                        
                        div.innerHTML = `
                            <img src="${e.target.result}" alt="미리보기 ${index + 1}">
                            <div class="drag-handle">⋮⋮</div>
                            ${index === 0 ? '<span class="main-badge">⭐ 대표</span>' : ''}
                            ${gpsButton}
                            <div class="file-info">${file.name}</div>
                            <select name="photo_types[]" class="type-selector">
                                <option value="full">전체</option>
                                <option value="leaf">잎</option>
                                <option value="bark">수피</option>
                                <option value="flower">꽃</option>
                                <option value="fruit">열매</option>
                                <option value="other">기타</option>
                            </select>
                        `;
                        preview.appendChild(div);
                        
                        loadedCount++;
                        if (loadedCount === totalFiles) {
                            initSortable();
                            showAllGpsOnMap();
                            showGpsAverageButton();
                        }
                    };
                    reader.readAsDataURL(file);
                });
            });
        }
    }
    
    // DMS(도분초) → DD(십진수) 변환
    function convertDMSToDD(dms, ref) {
        if (!dms || dms.length < 3) return null;
        
        const degrees = dms[0];
        const minutes = dms[1];
        const seconds = dms[2];
        
        let dd = degrees + (minutes / 60) + (seconds / 3600);
        
        if (ref === 'S' || ref === 'W') {
            dd = dd * -1;
        }
        
        return dd;
    }
    
    // 사진의 GPS를 나무 위치로 사용
    function usePhotoGps(index) {
        const gps = photoGpsData[index];
        if (!gps) return;
        
        document.getElementById('latitude').value = gps.latitude.toFixed(6);
        document.getElementById('longitude').value = gps.longitude.toFixed(6);
        
        // 지도 마커 이동
        if (marker) marker.setMap(null);
        const position = new kakao.maps.LatLng(gps.latitude, gps.longitude);
        marker = new kakao.maps.Marker({ position: position, map: map });
        map.setCenter(position);
        map.setLevel(3);
        
        // 붉은 테두리 토글
        document.querySelectorAll('.preview-item').forEach(item => {
            item.classList.remove('gps-used');
        });
        const selectedItem = document.querySelector(`.preview-item[data-index="${index}"]`);
        if (selectedItem) {
            selectedItem.classList.add('gps-used');
        }
        
        alert('✅ 사진 ' + (index + 1) + '번의 GPS가 나무 위치로 설정되었습니다.');
    }
    
    // 모든 GPS를 지도에 표시
    function showAllGpsOnMap() {
        const colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8'];
        
        photoGpsData.forEach((gps, index) => {
            if (gps) {
                const position = new kakao.maps.LatLng(gps.latitude, gps.longitude);
                
                // CustomOverlay로 색상 마커 생성
                const content = `
                    <div style="
                        background: ${colors[index % colors.length]};
                        color: white;
                        padding: 8px 12px;
                        border-radius: 20px;
                        font-weight: bold;
                        font-size: 14px;
                        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                        border: 2px solid white;
                        cursor: pointer;
                        white-space: nowrap;
                    ">📷 사진 ${index + 1}</div>
                `;
                
                const customOverlay = new kakao.maps.CustomOverlay({
                    position: position,
                    content: content,
                    yAnchor: 1.3,
                    clickable: true
                });
                
                customOverlay.setMap(map);
                
                // 클릭 이벤트 (DOM 요소에 직접 추가)
                setTimeout(() => {
                    const overlayDiv = customOverlay.a;
                    if (overlayDiv) {
                        overlayDiv.onclick = function() {
                            usePhotoGps(index);
                        };
                    }
                }, 100);
                
                gpsMarkers.push(customOverlay);
            }
        });
        
        // 모든 GPS 마커가 보이도록 지도 범위 조정
        if (gpsMarkers.length > 0) {
            const bounds = new kakao.maps.LatLngBounds();
            photoGpsData.forEach(gps => {
                if (gps) {
                    bounds.extend(new kakao.maps.LatLng(gps.latitude, gps.longitude));
                }
            });
            map.setBounds(bounds);
        }
    }
    
    // GPS 평균값 버튼 표시
    function showGpsAverageButton() {
        const validGps = photoGpsData.filter(gps => gps !== null);
        if (validGps.length >= 2) {
            const mapContainer = document.getElementById('map');
            let avgButton = document.getElementById('use-average-gps');
            
            if (!avgButton) {
                avgButton = document.createElement('button');
                avgButton.id = 'use-average-gps';
                avgButton.type = 'button';
                avgButton.className = 'btn btn-info';
                avgButton.style.marginTop = '10px';
                avgButton.onclick = useAverageGps;
                mapContainer.parentNode.insertBefore(avgButton, mapContainer.nextSibling);
            }
            
            avgButton.innerHTML = `📊 GPS 평균값 사용 (${validGps.length}개 사진)`;
            avgButton.style.display = 'inline-block';
        }
    }
    
    // GPS 평균값 계산 및 적용
    function useAverageGps() {
        const validGps = photoGpsData.filter(gps => gps !== null);
        if (validGps.length === 0) return;
        
        const avgLat = validGps.reduce((sum, gps) => sum + gps.latitude, 0) / validGps.length;
        const avgLng = validGps.reduce((sum, gps) => sum + gps.longitude, 0) / validGps.length;
        
        document.getElementById('latitude').value = avgLat.toFixed(6);
        document.getElementById('longitude').value = avgLng.toFixed(6);
        
        // 지도 마커 이동
        if (marker) marker.setMap(null);
        const position = new kakao.maps.LatLng(avgLat, avgLng);
        marker = new kakao.maps.Marker({ position: position, map: map });
        map.setCenter(position);
        map.setLevel(3);
        
        alert(`✅ ${validGps.length}개 사진의 평균 GPS가 나무 위치로 설정되었습니다.`);
    }
    
    // Sortable.js 초기화
    function initSortable() {
        const preview = document.getElementById('image-previews');
        if (preview && preview.children.length > 0) {
            new Sortable(preview, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function(evt) {
                    updatePhotoIndices();
                }
            });
        }
    }
    
    // 사진 인덱스 업데이트
    function updatePhotoIndices() {
        const items = document.querySelectorAll('.preview-item');
        const newGpsData = [];
        
        items.forEach((item, newIndex) => {
            const oldIndex = parseInt(item.getAttribute('data-index'));
            item.setAttribute('data-index', newIndex);
            newGpsData[newIndex] = photoGpsData[oldIndex];
            
            // 첫 번째 사진에만 대표 배지
            const badge = item.querySelector('.main-badge');
            if (newIndex === 0) {
                item.classList.add('is-main');
                if (!badge) {
                    const newBadge = document.createElement('span');
                    newBadge.className = 'main-badge';
                    newBadge.textContent = '⭐ 대표';
                    item.insertBefore(newBadge, item.querySelector('.file-info'));
                }
            } else {
                item.classList.remove('is-main');
                if (badge) badge.remove();
            }
            
            // GPS 버튼 업데이트
            const gpsBtn = item.querySelector('.use-gps-btn');
            if (gpsBtn) {
                gpsBtn.onclick = function() { usePhotoGps(newIndex); };
            }
        });
        
        photoGpsData = newGpsData;
    }
    </script>
    </script>
<?php else: ?>
    <div class="alert alert-error">
        카카오맵 API 키가 설정되지 않았습니다. config/kakao_map.php 파일을 확인하세요.
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>