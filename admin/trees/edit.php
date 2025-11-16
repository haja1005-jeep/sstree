<?php
/**
 * 나무 수정 (개선 버전)
 * Smart Tree Map - Sinan County
 * 
 * 개선사항:
 * - 미리보기 함수 추가
 * - 메시지 표시 기능 추가
 * - 사진 드래그 앤 드롭 순서 변경
 * - 대표 사진 설정
 */

require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';
checkAuth();

$page_title = '나무 수정';
$database = new Database();
$db = $database->getConnection();
$error = '';
$success = '';

// 수정할 ID 가져오기
$tree_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($tree_id === 0) {
    redirect('/admin/trees/list.php');
}

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
    
    $lat = convertGpsCoordinate($exif['GPSLatitude'], $exif['GPSLatitudeRef']);
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
    
    $degrees = eval('return ' . $coordinate[0] . ';');
    $minutes = eval('return ' . $coordinate[1] . ';');
    $seconds = eval('return ' . $coordinate[2] . ';');
    
    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
    
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
        
        $success_result = false;
        switch ($mime) {
            case 'image/jpeg': $success_result = imagejpeg($destination_image, $destination_path, $quality); break;
            case 'image/png': $success_result = imagepng($destination_image, $destination_path, 8); break;
            case 'image/gif': $success_result = imagegif($destination_image, $destination_path); break;
        }
        
        imagedestroy($source_image);
        imagedestroy($destination_image);
        return $success_result;

    } catch (Exception $e) {
        return false;
    }
}

// 사진 순서 업데이트 처리
if (isset($_POST['update_photo_order'])) {
    $photo_orders = json_decode($_POST['photo_orders'], true);
    if ($photo_orders) {
        try {
            $db->beginTransaction();
            
            // 순서 업데이트 및 첫 번째 사진 대표 설정
            $first_photo_id = null;
            foreach ($photo_orders as $photo_id => $order) {
                if ($order == 1) {
                    $first_photo_id = $photo_id;
                }
                $query = "UPDATE tree_photos SET sort_order = :sort_order WHERE photo_id = :photo_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':sort_order', $order);
                $stmt->bindParam(':photo_id', $photo_id);
                $stmt->execute();
            }
            
            // 모든 사진의 is_main을 0으로 초기화
            $tree_id_query = "SELECT tree_id FROM tree_photos WHERE photo_id = :photo_id LIMIT 1";
            $tree_id_stmt = $db->prepare($tree_id_query);
            $tree_id_stmt->bindParam(':photo_id', $first_photo_id);
            $tree_id_stmt->execute();
            $tree_id_result = $tree_id_stmt->fetch();
            
            if ($tree_id_result) {
                $current_tree_id = $tree_id_result['tree_id'];
                $reset_query = "UPDATE tree_photos SET is_main = 0 WHERE tree_id = :tree_id";
                $reset_stmt = $db->prepare($reset_query);
                $reset_stmt->bindParam(':tree_id', $current_tree_id);
                $reset_stmt->execute();
                
                // 첫 번째 사진을 대표로 설정
                if ($first_photo_id) {
                    $main_query = "UPDATE tree_photos SET is_main = 1 WHERE photo_id = :photo_id";
                    $main_stmt = $db->prepare($main_query);
                    $main_stmt->bindParam(':photo_id', $first_photo_id);
                    $main_stmt->execute();
                }
            }
            
            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// 폼 제출 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['update_photo_order'])) {
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
            
            // 나무 정보 업데이트
            $query = "UPDATE trees SET 
                        region_id = :region_id, category_id = :category_id, location_id = :location_id, 
                        species_id = :species_id, tree_number = :tree_number, planting_date = :planting_date, 
                        height = :height, diameter = :diameter, health_status = :health_status, 
                        latitude = :latitude, longitude = :longitude, notes = :notes
                      WHERE tree_id = :tree_id";
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
            $stmt->bindParam(':tree_id', $tree_id);
            $stmt->execute();
            
            // 파일 업로드
            if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
                $upload_dir = UPLOAD_PATH;
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                
                // 현재 사진 개수 확인 (첫 번째 사진 자동 대표 설정용)
                $count_query = "SELECT COUNT(*) as cnt FROM tree_photos WHERE tree_id = :tree_id";
                $count_stmt = $db->prepare($count_query);
                $count_stmt->bindParam(':tree_id', $tree_id);
                $count_stmt->execute();
                $existing_count = $count_stmt->fetch()['cnt'];
                
                // 현재 최대 sort_order 가져오기
                $max_order_query = "SELECT COALESCE(MAX(sort_order), 0) as max_order FROM tree_photos WHERE tree_id = :tree_id";
                $max_order_stmt = $db->prepare($max_order_query);
                $max_order_stmt->bindParam(':tree_id', $tree_id);
                $max_order_stmt->execute();
                $current_max_order = $max_order_stmt->fetch()['max_order'];
                
                $upload_index = 0; // 업로드 순서 카운터
                
                foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                    if (empty($tmp_name) || $_FILES['photos']['error'][$key] !== UPLOAD_ERR_OK) continue;
                    
                    $file_name = $_FILES['photos']['name'][$key];
                    $file_size = $_FILES['photos']['size'][$key];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $photo_type = isset($_POST['photo_types'][$key]) ? sanitize($_POST['photo_types'][$key]) : 'full';
                    
                    if (!in_array($file_ext, $allowed_ext_array)) continue;
                    if ($file_size > MAX_FILE_SIZE) continue;
                    
                    $new_file_name = 'tree_' . $tree_id . '_' . uniqid() . '.' . $file_ext;
                    $destination_path = $upload_dir . '/' . $new_file_name;
                    
                    if (processAndSaveImage($tmp_name, $destination_path)) {
                        // 상대 경로 계산 (BASE_PATH 제거)
                        $relative_path = str_replace(BASE_PATH . '/', '', $destination_path);
                        $current_max_order++;
                        $upload_index++;
                        
                        // ✅ 실제 저장된 파일의 크기 측정 (리사이즈 후)
                        $file_size_after = filesize($destination_path);
                        
                        // ✅ GPS 정보 추출
                        $gps_data = getGpsFromExif($tmp_name);
                        $photo_lat = $gps_data ? $gps_data['latitude'] : null;
                        $photo_lng = $gps_data ? $gps_data['longitude'] : null;
                        
                        // ✅ 기존 사진이 없고 첫 번째 업로드면 자동 대표 설정
                        $is_main = ($existing_count == 0 && $upload_index == 1) ? 1 : 0;
                        
                        $insert_query = "INSERT INTO tree_photos (tree_id, file_name, file_path, file_size, photo_type, sort_order, is_main, latitude, longitude, uploaded_at) 
                                       VALUES (:tree_id, :file_name, :file_path, :file_size, :photo_type, :sort_order, :is_main, :latitude, :longitude, NOW())";
                        $insert_stmt = $db->prepare($insert_query);
                        $insert_stmt->bindParam(':tree_id', $tree_id);
                        $insert_stmt->bindParam(':file_name', $file_name);
                        $insert_stmt->bindParam(':file_path', $relative_path);
                        $insert_stmt->bindParam(':file_size', $file_size_after);
                        $insert_stmt->bindParam(':photo_type', $photo_type);
                        $insert_stmt->bindParam(':sort_order', $current_max_order);
                        $insert_stmt->bindParam(':is_main', $is_main);
                        $insert_stmt->bindParam(':latitude', $photo_lat);
                        $insert_stmt->bindParam(':longitude', $photo_lng);
                        $insert_stmt->execute();
                        
                        $saved_files[] = $file_name;
                    }
                }
            }
            
            $db->commit();
            logActivity($_SESSION['user_id'], 'update', 'tree', $tree_id, '나무 정보 수정');
            
            $success = '나무 정보가 수정되었습니다.' . (count($saved_files) > 0 ? ' (사진 ' . count($saved_files) . '장 추가)' : '');
            
        } catch (Exception $e) {
            $db->rollBack();
            if (!empty($saved_files)) {
                foreach ($saved_files as $file) {
                    $file_path = $upload_dir . '/' . $file;
                    if (file_exists($file_path)) @unlink($file_path);
                }
            }
            $error = '나무 수정 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

// 나무 정보 조회
try {
    $query = "SELECT t.*, r.region_name, c.category_name, l.location_name,
              s.korean_name as species_name
              FROM trees t
              LEFT JOIN regions r ON t.region_id = r.region_id
              LEFT JOIN categories c ON t.category_id = c.category_id
              LEFT JOIN locations l ON t.location_id = l.location_id
              LEFT JOIN tree_species_master s ON t.species_id = s.species_id
              WHERE t.tree_id = :tree_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':tree_id', $tree_id);
    $stmt->execute();
    $tree = $stmt->fetch();
    
    if (!$tree) {
        redirect('/admin/trees/list.php');
    }
    
    // 사진 조회 (sort_order 순서로)
    $photos_query = "SELECT * FROM tree_photos 
                     WHERE tree_id = :tree_id 
                     ORDER BY sort_order ASC, uploaded_at DESC";
    $photos_stmt = $db->prepare($photos_query);
    $photos_stmt->bindParam(':tree_id', $tree_id);
    $photos_stmt->execute();
    $photos = $photos_stmt->fetchAll();
    
    // 지역 목록
    $regions_query = "SELECT * FROM regions ORDER BY region_name";
    $regions_stmt = $db->prepare($regions_query);
    $regions_stmt->execute();
    $regions = $regions_stmt->fetchAll();
    
    // 카테고리 목록
    $categories_query = "SELECT * FROM categories ORDER BY category_name";
    $categories_stmt = $db->prepare($categories_query);
    $categories_stmt->execute();
    $categories = $categories_stmt->fetchAll();
    
    // 장소 목록
    $locations_query = "SELECT l.*, r.region_name, c.category_name 
                       FROM locations l 
                       LEFT JOIN regions r ON l.region_id = r.region_id 
                       LEFT JOIN categories c ON l.category_id = c.category_id 
                       ORDER BY l.location_name";
    $locations_stmt = $db->prepare($locations_query);
    $locations_stmt->execute();
    $locations = $locations_stmt->fetchAll();
    
    // 수종 목록
    $species_query = "SELECT * FROM tree_species_master ORDER BY korean_name";
    $species_stmt = $db->prepare($species_query);
    $species_stmt->execute();
    $species_list = $species_stmt->fetchAll();
    
} catch (Exception $e) {
    $error = "데이터를 불러오는 중 오류가 발생했습니다: " . $e->getMessage();
    $tree = []; $photos = []; $regions = []; $categories = []; $locations = []; $species_list = [];
}

$photo_type_labels = [
    'full' => '전체',
    'leaf' => '잎',
    'bark' => '수피',
    'flower' => '꽃',
    'fruit' => '열매',
    'other' => '기타'
];

include '../../includes/header.php';

// URL에서 메시지 가져오기
$url_message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';
$message_type = isset($_GET['type']) ? htmlspecialchars($_GET['type']) : 'success';
?>

<?php if ($url_message): ?>
    <div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom: 20px;">
        <?php echo $url_message; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<style>
.map-container { width: 100%; height: 400px; border: 1px solid #ddd; border-radius: 8px; margin-top: 10px; }
.existing-photos { 
    display: grid; 
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); 
    gap: 15px; 
    margin-bottom: 20px; 
}
.existing-photo-item { 
    position: relative; 
    cursor: move;
    transition: transform 0.2s;
}
.existing-photo-item:hover {
    transform: scale(1.05);
}
.existing-photo-item img { 
    width: 100%; 
    height: 150px; 
    object-fit: cover; 
    border-radius: 8px; 
    border: 2px solid #ddd; 
}
.existing-photo-item.is-main img {
    border-color: #fbbf24;
    box-shadow: 0 0 10px rgba(251, 191, 36, 0.5);
}
.existing-photo-item.gps-used img {
    border-color: #ef4444;
    box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
}
.existing-photo-item.gps-used .use-gps-btn {
    background: rgba(239, 68, 68, 0.95);
}
.existing-photo-item.gps-used .use-gps-btn:hover {
    background: rgba(220, 38, 38, 1);
}
.existing-photo-item .delete-link { 
    position: absolute; 
    top: 5px; 
    right: 5px; 
}
.existing-photo-item .main-badge {
    position: absolute;
    top: 5px;
    left: 5px;
    background: #fbbf24;
    color: #78350f;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
}
.existing-photo-item .set-main-btn {
    position: absolute;
    bottom: 5px;
    right: 5px;
    background: rgba(59, 130, 246, 0.9);
    color: white;
    border: none;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    cursor: pointer;
}
.existing-photo-item .set-main-btn:hover {
    background: rgba(37, 99, 235, 1);
}
.existing-photo-item .photo-type-badge { 
    position: absolute; 
    bottom: 5px; 
    left: 5px; 
    background: rgba(0,0,0,0.7); 
    color: white; 
    padding: 3px 8px; 
    border-radius: 4px; 
    font-size: 11px; 
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
.existing-photo-item:hover .drag-handle {
    opacity: 1;
}

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
}

.preview-item .file-info {
    padding: 8px;
    font-size: 12px;
    color: #6b7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.preview-item .type-selector {
    width: calc(100% - 16px);
    margin: 0 8px 8px 8px;
    padding: 4px;
    font-size: 12px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
}

.sortable-ghost {
    opacity: 0.4;
}
.use-gps-btn {
    position: absolute;
    bottom: 70px;
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
    bottom: 70px;
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
    margin-top: 10px;
}

.btn-info:hover {
    background: #0284c7;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(14, 165, 233, 0.4);
}

.existing-photo-item .use-gps-btn {
    bottom: 35px;
}

</style>

<div style="display: flex; gap: 10px; margin-bottom: 20px;">
    <a href="view.php?id=<?php echo $tree_id; ?>" class="btn btn-secondary">← 상세보기</a>
    <a href="list.php" class="btn btn-secondary">목록으로</a>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">🌳 나무 정보 수정</h3>
        <span style="color: #666;">
            <?php echo htmlspecialchars($tree['species_name'] ?: '수종 미지정'); ?>
            <?php if ($tree['tree_number']): ?>
                (번호: <?php echo htmlspecialchars($tree['tree_number']); ?>)
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <h4 style="margin: 0 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">위치 선택</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="region_id">지역 <span style="color:red;">*</span></label>
                    <select id="region_id" name="region_id" required>
                        <option value="0">선택하세요</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo $region['region_id']; ?>"
                                    <?php echo $tree['region_id'] == $region['region_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($region['region_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="category_id">카테고리 <span style="color:red;">*</span></label>
                    <select id="category_id" name="category_id" required>
                        <option value="0">선택하세요</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['category_id']; ?>"
                                    <?php echo $tree['category_id'] == $category['category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="location_id">장소 <span style="color:red;">*</span></label>
                    <select id="location_id" name="location_id" required>
                        <option value="0">선택하세요</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['location_id']; ?>"
                                    data-region="<?php echo $location['region_id']; ?>"
                                    data-category="<?php echo $location['category_id']; ?>"
                                    <?php echo $tree['location_id'] == $location['location_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($location['location_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="species_id">수종 <span style="color:red;">*</span></label>
                <select id="species_id" name="species_id" required>
                    <option value="0">선택하세요</option>
                    <?php foreach ($species_list as $species): ?>
                        <option value="<?php echo $species['species_id']; ?>"
                                <?php echo $tree['species_id'] == $species['species_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($species['korean_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">나무 정보</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="tree_number">나무 번호</label>
                    <input type="text" id="tree_number" name="tree_number" 
                           value="<?php echo htmlspecialchars($tree['tree_number']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="planting_date">식재일</label>
                    <input type="date" id="planting_date" name="planting_date" 
                           value="<?php echo htmlspecialchars($tree['planting_date']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="health_status">건강상태 <span style="color:red;">*</span></label>
                    <select id="health_status" name="health_status" required>
                        <option value="excellent" <?php echo ($tree['health_status'] == 'excellent') ? 'selected' : ''; ?>>최상</option>
                        <option value="good" <?php echo ($tree['health_status'] == 'good') ? 'selected' : ''; ?>>양호</option>
                        <option value="fair" <?php echo ($tree['health_status'] == 'fair') ? 'selected' : ''; ?>>보통</option>
                        <option value="poor" <?php echo ($tree['health_status'] == 'poor') ? 'selected' : ''; ?>>나쁨</option>
                        <option value="dead" <?php echo ($tree['health_status'] == 'dead') ? 'selected' : ''; ?>>고사</option>
                    </select>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="height">높이 (m)</label>
                    <input type="number" id="height" name="height" step="0.01" 
                           value="<?php echo htmlspecialchars($tree['height']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="diameter">직경 (cm)</label>
                    <input type="number" id="diameter" name="diameter" step="0.01" 
                           value="<?php echo htmlspecialchars($tree['diameter']); ?>">
                </div>
            </div>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">위치 정보</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="latitude">위도</label>
                    <input type="text" id="latitude" name="latitude" 
                           value="<?php echo htmlspecialchars($tree['latitude']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="longitude">경도</label>
                    <input type="text" id="longitude" name="longitude" 
                           value="<?php echo htmlspecialchars($tree['longitude']); ?>" readonly>
                </div>
            </div>
            <div id="map" class="map-container"></div>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                📸 사진 관리
                <small style="font-weight: normal; color: #666; font-size: 14px;">
                    (드래그하여 순서 변경, 첫 번째 사진이 자동으로 대표)
                </small>
            </h4>
            <div class="form-group">
                <label>기존 사진</label>
                <div class="existing-photos" id="photo-sortable">
                    <?php if (empty($photos)): ?>
                        <p style="color: #888; font-size: 14px;">등록된 사진이 없습니다.</p>
                    <?php endif; ?>
                    <?php foreach ($photos as $photo): ?>
                        <div class="existing-photo-item <?php echo $photo['is_main'] ? 'is-main' : ''; ?>" 
                             data-photo-id="<?php echo $photo['photo_id']; ?>"
                             data-latitude="<?php echo $photo['latitude'] ?? ''; ?>"
                             data-longitude="<?php echo $photo['longitude'] ?? ''; ?>">
                            <img src="<?php echo BASE_URL . '/' . htmlspecialchars($photo['file_path']); ?>" 
                                 alt="<?php echo htmlspecialchars($photo['file_name']); ?>">
                            <div class="drag-handle">⋮⋮</div>
                            <?php if ($photo['is_main']): ?>
                                <span class="main-badge">⭐ 대표</span>
                            <?php endif; ?>
                            <?php if (!empty($photo['latitude']) && !empty($photo['longitude'])): ?>
                                <button type="button" class="use-gps-btn" onclick="useExistingPhotoGps(<?php echo $photo['photo_id']; ?>)">
                                    📍 위치 사용
                                </button>
                            <?php else: ?>
                                <span class="no-gps-label">GPS 없음</span>
                            <?php endif; ?>
                            <span class="photo-type-badge"><?php echo $photo_type_labels[$photo['photo_type']]; ?></span>
                            <a href="delete_photo.php?id=<?php echo $photo['photo_id']; ?>&tree_id=<?php echo $tree_id; ?>" 
                               class="btn btn-sm btn-danger delete-link" 
                               onclick="return confirm('이 사진을 삭제하시겠습니까?');">삭제</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">

            <div class="form-group">
                <label for="photos">새 사진 추가 (다중 선택, 최대 <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB)</label>
                <input type="file" id="photos" name="photos[]" accept="image/*" multiple onchange="previewImages(this)">
                <div id="image-previews" class="image-preview-grid"></div>
            </div>
            <small style="color: #6b7280; font-size: 13px; display: block; margin-top: 5px;">
                💡 Ctrl(Cmd) 키를 누른 채 여러 파일을 선택할 수 있습니다.
            </small>
            
            <div class="form-group">
                <label for="notes">비고</label>
                <textarea id="notes" name="notes" rows="4"><?php echo htmlspecialchars($tree['notes']); ?></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 30px;">
                <button type="submit" class="btn btn-primary">수정 완료</button>
                <a href="view.php?id=<?php echo $tree_id; ?>" class="btn btn-secondary">취소 (상세보기로)</a>
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
// 전역 변수
    let existingPhotoGps = []; // 기존 사진 GPS
    let newPhotoGpsData = []; // 새 사진 GPS
    let allGpsMarkers = []; // 모든 GPS 마커
    
    // 페이지 로드 시 기존 사진 GPS 수집
    window.addEventListener('DOMContentLoaded', function() {
        collectExistingPhotoGps();
        showAllGpsOnMap();
        showGpsAverageButton();
    });
    
    // 기존 사진 GPS 수집
    function collectExistingPhotoGps() {
        const items = document.querySelectorAll('.existing-photo-item');
        items.forEach((item, index) => {
            const lat = parseFloat(item.getAttribute('data-latitude'));
            const lng = parseFloat(item.getAttribute('data-longitude'));
            const photoId = item.getAttribute('data-photo-id');
            
            if (lat && lng && !isNaN(lat) && !isNaN(lng)) {
                existingPhotoGps.push({
                    latitude: lat,
                    longitude: lng,
                    photoId: photoId,
                    type: 'existing',
                    index: index
                });
            }
        });
    }
    
    // 기존 사진의 GPS를 나무 위치로 사용
    function useExistingPhotoGps(photoId) {
        const gps = existingPhotoGps.find(g => g.photoId == photoId);
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
        const items = document.querySelectorAll('.existing-photo-item');
        items.forEach(item => {
            item.classList.remove('gps-used');
        });
        const selectedItem = document.querySelector(`.existing-photo-item[data-photo-id="${photoId}"]`);
        if (selectedItem) {
            selectedItem.classList.add('gps-used');
        }
        
        alert('✅ 기존 사진의 GPS가 나무 위치로 설정되었습니다.');
    }
    
    // 새 사진 미리보기 및 GPS 추출
    function previewImages(input) {
        const preview = document.getElementById('image-previews');
        preview.innerHTML = '';
        newPhotoGpsData = [];
        
        // 기존 새 사진 마커만 제거
        allGpsMarkers.filter(m => m.type === 'new').forEach(m => m.marker.setMap(null));
        allGpsMarkers = allGpsMarkers.filter(m => m.type !== 'new');
        
        if (input.files && input.files.length > 0) {
            let loadedCount = 0;
            const totalFiles = input.files.length;
            
            Array.from(input.files).forEach((file, index) => {
                EXIF.getData(file, function() {
                    const lat = EXIF.getTag(this, 'GPSLatitude');
                    const latRef = EXIF.getTag(this, 'GPSLatitudeRef');
                    const lng = EXIF.getTag(this, 'GPSLongitude');
                    const lngRef = EXIF.getTag(this, 'GPSLongitudeRef');
                    
                    let gpsData = null;
                    if (lat && lng) {
                        gpsData = {
                            latitude: convertDMSToDD(lat, latRef),
                            longitude: convertDMSToDD(lng, lngRef),
                            type: 'new',
                            index: index
                        };
                    }
                    newPhotoGpsData[index] = gpsData;
                    
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.className = 'preview-item';
                        div.setAttribute('data-index', index);
                        
                        let gpsButton = '';
                        if (gpsData) {
                            gpsButton = `<button type="button" class="use-gps-btn" onclick="useNewPhotoGps(${index})">📍 위치 사용</button>`;
                        } else {
                            gpsButton = '<span class="no-gps-label">GPS 없음</span>';
                        }
                        
                        div.innerHTML = `
                            <img src="${e.target.result}" alt="미리보기 ${index + 1}">
                            <div class="drag-handle">⋮⋮</div>
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
                            showAllGpsOnMap();
                            showGpsAverageButton();
                        }
                    };
                    reader.readAsDataURL(file);
                });
            });
        }
    }
    
    // DMS → DD 변환
    function convertDMSToDD(dms, ref) {
        if (!dms || dms.length < 3) return null;
        const degrees = dms[0];
        const minutes = dms[1];
        const seconds = dms[2];
        let dd = degrees + (minutes / 60) + (seconds / 3600);
        if (ref === 'S' || ref === 'W') dd = dd * -1;
        return dd;
    }
    
    // 새 사진의 GPS를 나무 위치로 사용
    function useNewPhotoGps(index) {
        const gps = newPhotoGpsData[index];
        if (!gps) return;
        
        document.getElementById('latitude').value = gps.latitude.toFixed(6);
        document.getElementById('longitude').value = gps.longitude.toFixed(6);
        
        if (marker) marker.setMap(null);
        const position = new kakao.maps.LatLng(gps.latitude, gps.longitude);
        marker = new kakao.maps.Marker({ position: position, map: map });
        map.setCenter(position);
        map.setLevel(3);
        
        // 기존 사진 붉은 테두리 제거
        document.querySelectorAll('.existing-photo-item').forEach(item => {
            item.classList.remove('gps-used');
        });
        // 새 사진 붉은 테두리 추가
        document.querySelectorAll('.preview-item').forEach(item => {
            item.classList.remove('gps-used');
        });
        const selectedItem = document.querySelector(`.preview-item[data-index="${index}"]`);
        if (selectedItem) {
            selectedItem.classList.add('gps-used');
        }
        
        alert('✅ 새 사진의 GPS가 나무 위치로 설정되었습니다.');
    }
    
    // 모든 GPS를 지도에 표시
    function showAllGpsOnMap() {
        // 기존 마커 제거
        allGpsMarkers.forEach(m => m.marker.setMap(null));
        allGpsMarkers = [];
        
        const colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F38181', '#95E1D3'];
        let colorIndex = 0;
        
        // 기존 사진 GPS 마커
        existingPhotoGps.forEach((gps, index) => {
            const position = new kakao.maps.LatLng(gps.latitude, gps.longitude);
            const content = `
                <div style="
                    background: ${colors[colorIndex % colors.length]};
                    color: white;
                    padding: 8px 12px;
                    border-radius: 20px;
                    font-weight: bold;
                    font-size: 13px;
                    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                    border: 2px solid white;
                    cursor: pointer;
                    white-space: nowrap;
                ">📷 기존${index + 1}</div>
            `;
            
            const customOverlay = new kakao.maps.CustomOverlay({
                position: position,
                content: content,
                yAnchor: 1.3,
                clickable: true
            });
            
            customOverlay.setMap(map);
            
            setTimeout(() => {
                const overlayDiv = customOverlay.a;
                if (overlayDiv) {
                    overlayDiv.onclick = function() {
                        useExistingPhotoGps(gps.photoId);
                    };
                }
            }, 100);
            
            allGpsMarkers.push({ marker: customOverlay, type: 'existing', gps: gps });
            colorIndex++;
        });
        
        // 새 사진 GPS 마커
        newPhotoGpsData.forEach((gps, index) => {
            if (gps) {
                const position = new kakao.maps.LatLng(gps.latitude, gps.longitude);
                const content = `
                    <div style="
                        background: ${colors[colorIndex % colors.length]};
                        color: white;
                        padding: 8px 12px;
                        border-radius: 20px;
                        font-weight: bold;
                        font-size: 13px;
                        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                        border: 2px solid white;
                        cursor: pointer;
                        white-space: nowrap;
                    ">📷 새${index + 1}</div>
                `;
                
                const customOverlay = new kakao.maps.CustomOverlay({
                    position: position,
                    content: content,
                    yAnchor: 1.3,
                    clickable: true
                });
                
                customOverlay.setMap(map);
                
                setTimeout(() => {
                    const overlayDiv = customOverlay.a;
                    if (overlayDiv) {
                        overlayDiv.onclick = function() {
                            useNewPhotoGps(index);
                        };
                    }
                }, 100);
                
                allGpsMarkers.push({ marker: customOverlay, type: 'new', gps: gps });
                colorIndex++;
            }
        });
        
        // 지도 범위 조정
        if (allGpsMarkers.length > 0) {
            const bounds = new kakao.maps.LatLngBounds();
            allGpsMarkers.forEach(item => {
                bounds.extend(new kakao.maps.LatLng(item.gps.latitude, item.gps.longitude));
            });
            map.setBounds(bounds);
        }
    }
    
    // GPS 평균값 버튼 표시
    function showGpsAverageButton() {
        const allGps = [...existingPhotoGps, ...newPhotoGpsData.filter(g => g !== null)];
        if (allGps.length >= 2) {
            const mapContainer = document.getElementById('map');
            let avgButton = document.getElementById('use-average-gps');
            
            if (!avgButton) {
                avgButton = document.createElement('button');
                avgButton.id = 'use-average-gps';
                avgButton.type = 'button';
                avgButton.className = 'btn btn-info';
                avgButton.onclick = useAverageGps;
                mapContainer.parentNode.insertBefore(avgButton, mapContainer.nextSibling);
            }
            
            avgButton.innerHTML = `📊 GPS 평균값 사용 (${allGps.length}개 사진)`;
            avgButton.style.display = 'inline-block';
        }
    }
    
    // GPS 평균값 계산 및 적용
    function useAverageGps() {
        const allGps = [...existingPhotoGps, ...newPhotoGpsData.filter(g => g !== null)];
        if (allGps.length === 0) return;
        
        const avgLat = allGps.reduce((sum, gps) => sum + gps.latitude, 0) / allGps.length;
        const avgLng = allGps.reduce((sum, gps) => sum + gps.longitude, 0) / allGps.length;
        
        document.getElementById('latitude').value = avgLat.toFixed(6);
        document.getElementById('longitude').value = avgLng.toFixed(6);
        
        if (marker) marker.setMap(null);
        const position = new kakao.maps.LatLng(avgLat, avgLng);
        marker = new kakao.maps.Marker({ position: position, map: map });
        map.setCenter(position);
        map.setLevel(3);
        
        alert(`✅ ${allGps.length}개 사진의 평균 GPS가 나무 위치로 설정되었습니다.`);
    }
    
    // 카카오맵 초기화
    const mapContainer = document.getElementById('map');
    const defaultLat = <?php echo defined('DEFAULT_LAT') ? DEFAULT_LAT : '34.8265'; ?>;
    const defaultLng = <?php echo defined('DEFAULT_LNG') ? DEFAULT_LNG : '126.1069'; ?>;
    let currentLat = <?php echo !empty($tree['latitude']) ? $tree['latitude'] : 'defaultLat'; ?>;
    let currentLng = <?php echo !empty($tree['longitude']) ? $tree['longitude'] : 'defaultLng'; ?>;
    let zoomLevel = <?php echo !empty($tree['latitude']) ? 5 : 9; ?>;
    
    const mapOption = { center: new kakao.maps.LatLng(currentLat, currentLng), level: zoomLevel };
    const map = new kakao.maps.Map(mapContainer, mapOption);
    let marker = null;
    
    if (<?php echo !empty($tree['latitude']) ? 'true' : 'false'; ?>) {
        marker = new kakao.maps.Marker({ position: new kakao.maps.LatLng(currentLat, currentLng), map: map });
    }
    
    kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
        const latlng = mouseEvent.latLng;
        if (marker) marker.setMap(null);
        marker = new kakao.maps.Marker({ position: latlng, map: map });
        document.getElementById('latitude').value = latlng.getLat();
        document.getElementById('longitude').value = latlng.getLng();
    });
    
    // 장소 선택 시 지역/카테고리 자동 설정
    document.getElementById('location_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const regionId = selectedOption.getAttribute('data-region');
        const categoryId = selectedOption.getAttribute('data-category');
        
        document.getElementById('region_id').value = regionId || '0';
        document.getElementById('category_id').value = categoryId || '0';
    });
    
    // Sortable.js 초기화 (사진 드래그 앤 드롭)
    const photoSortable = document.getElementById('photo-sortable');
    if (photoSortable && photoSortable.children.length > 0) {
        new Sortable(photoSortable, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function(evt) {
                const photoOrders = {};
                const items = photoSortable.querySelectorAll('.existing-photo-item');
                items.forEach((item, index) => {
                    const photoId = item.getAttribute('data-photo-id');
                    photoOrders[photoId] = index + 1;
                    
                    // UI 업데이트: 첫 번째 사진만 대표 표시
                    if (index === 0) {
                        item.classList.add('is-main');
                        // 대표 배지가 없으면 추가
                        if (!item.querySelector('.main-badge')) {
                            const badge = document.createElement('span');
                            badge.className = 'main-badge';
                            badge.textContent = '⭐ 대표';
                            // 드래그 핸들 다음에 삽입
                            const dragHandle = item.querySelector('.drag-handle');
                            if (dragHandle && dragHandle.nextSibling) {
                                item.insertBefore(badge, dragHandle.nextSibling);
                            }
                        }
                    } else {
                        item.classList.remove('is-main');
                        // 대표 배지 제거
                        const badge = item.querySelector('.main-badge');
                        if (badge) {
                            badge.remove();
                        }
                    }
                });
                
                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'update_photo_order=1&photo_orders=' + encodeURIComponent(JSON.stringify(photoOrders))
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('사진 순서 및 대표 사진이 저장되었습니다.');
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        });
    }
    </script>

<?php else: ?>
    <div class="alert alert-error">
        카카오맵 API 키가 설정되지 않았습니다. config/kakao_map.php 파일을 확인하세요.
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>