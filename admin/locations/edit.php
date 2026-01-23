<?php
/**
 * 장소 수정 (카카오 길찾기 기반 경로 그리기 통합)
 * Smart Tree Map - Location Management
 */

require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '장소 수정';

$database = new Database();
$db = $database->getConnection();

// 장소 ID 확인
$location_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$location_id) {
    $_SESSION['error_message'] = '잘못된 접근입니다.';
    header('Location: list.php');
    exit;
}

// 허용 확장자 설정
$allowed_ext_array = array_map('trim', array_map('strtolower', explode(',', ALLOWED_EXTENSIONS)));

// [추가] GPS 좌표 변환 헬퍼 함수
function gps2Num($coordPart) {
    $parts = explode('/', $coordPart);
    if (count($parts) <= 0) return 0;
    if (count($parts) == 1) return $parts[0];
    return floatval($parts[0]) / floatval($parts[1]);
}

// [추가] EXIF에서 GPS 추출 함수
function getGPSFromExif($file) {
    if (!function_exists('exif_read_data')) return null;
    $exif = @exif_read_data($file);
    
    if (isset($exif['GPSLatitude']) && isset($exif['GPSLatitudeRef']) && 
        isset($exif['GPSLongitude']) && isset($exif['GPSLongitudeRef'])) {
        
        $latRef = $exif['GPSLatitudeRef'];
        $lat    = $exif['GPSLatitude'];
        $lngRef = $exif['GPSLongitudeRef'];
        $lng    = $exif['GPSLongitude'];
        
        $lat_deg = count($lat) > 0 ? gps2Num($lat[0]) : 0;
        $lat_min = count($lat) > 1 ? gps2Num($lat[1]) : 0;
        $lat_sec = count($lat) > 2 ? gps2Num($lat[2]) : 0;
        
        $lng_deg = count($lng) > 0 ? gps2Num($lng[0]) : 0;
        $lng_min = count($lng) > 1 ? gps2Num($lng[1]) : 0;
        $lng_sec = count($lng) > 2 ? gps2Num($lng[2]) : 0;
        
        $latitude = $lat_deg + ($lat_min / 60) + ($lat_sec / 3600);
        $longitude = $lng_deg + ($lng_min / 60) + ($lng_sec / 3600);
        
        if ($latRef == 'S') $latitude *= -1;
        if ($lngRef == 'W') $longitude *= -1;
        
        return ['lat' => $latitude, 'lng' => $longitude];
    }
    return null;
}


/**
 * 자동 회전 보정 기능이 포함된 리사이징 함수
 */
function autoOrientImage($image_resource, $source_path) {
    if (!function_exists('exif_read_data')) {
        return $image_resource;
    }
    
    $exif = @exif_read_data($source_path);
    if (!empty($exif['Orientation'])) {
        switch ($exif['Orientation']) {
            case 3: $image_resource = imagerotate($image_resource, 180, 0); break;
            case 6: $image_resource = imagerotate($image_resource, -90, 0); break;
            case 8: $image_resource = imagerotate($image_resource, 90, 0); break;
        }
    }
    return $image_resource;
}

// [수정] 이미지 리사이즈 및 저장 (1MB 최적화 적용)
function processAndSaveImage($src, $dest, $quality = 80) {
    ini_set('memory_limit', '512M');
    set_time_limit(300);
    try {
        $info = getimagesize($src);
        if (!$info) return false;
        
        list($w, $h, $type) = [$info[0], $info[1], $info['mime']];
        
        // 1MB 이하 최적화 (Max 1920px)
        $max_dimension = 1920;
        if ($w > $max_dimension || $h > $max_dimension) {
            $ratio = $max_dimension / max($w, $h);
            $newW = (int)($w * $ratio);
            $newH = (int)($h * $ratio);
        } else {
            $newW = $w;
            $newH = $h;
        }
        
        $destImg = imagecreatetruecolor($newW, $newH);
        
        switch ($type) {
            case 'image/jpeg':
                $srcImg = imagecreatefromjpeg($src);
                $srcImg = autoOrientImage($srcImg, $src);
                break;
            case 'image/png':
                $srcImg = imagecreatefrompng($src);
                imagealphablending($destImg, false);
                imagesavealpha($destImg, true);
                break;
            case 'image/gif':
                $srcImg = imagecreatefromgif($src);
                break;
            default:
                imagedestroy($destImg);
                return move_uploaded_file($src, $dest);
        }
        
        if (!$srcImg) return false;
        imagecopyresampled($destImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);
        
        $success = false;
        switch ($type) {
            case 'image/jpeg': $success = imagejpeg($destImg, $dest, $quality); break;
            case 'image/png':  $success = imagepng($destImg, $dest, 8); break;
            case 'image/gif':  $success = imagegif($destImg, $dest); break;
        }
        
        imagedestroy($srcImg);
        imagedestroy($destImg);
        
        // 1MB 초과 시 재압축 (JPEG)
        if ($success && file_exists($dest) && filesize($dest) > 1048576 && $type === 'image/jpeg') {
            $tempImg = imagecreatefromjpeg($dest);
            imagejpeg($tempImg, $dest, 60);
            imagedestroy($tempImg);
        }

        return $success;
    } catch (Exception $e) { return false; }
}

// 폼 제출 처리
// 폼 제출 처리
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $saved_files = [];

    try {
        // ... (POST 변수 수신 부분 기존과 동일) ...
        $region_id = isset($_POST['region_id']) ? (int)$_POST['region_id'] : 0;
        $category_id = $_POST['category_id'];
        $location_name = trim($_POST['location_name']);
        $address = trim($_POST['address']);
        $area = $_POST['area'] ? floatval($_POST['area']) : null;
        $road_name = trim($_POST['road_name']);
        $road_type = trim($_POST['road_type']);
        $section_start = trim($_POST['section_start']);
        $section_end = trim($_POST['section_end']);
        $length = $_POST['length'] ? floatval($_POST['length']) : null;
        $width = $_POST['width'] ? floatval($_POST['width']) : null;
        $location_type = $_POST['location_type'];
        $latitude = $_POST['latitude'] ? floatval($_POST['latitude']) : null;
        $longitude = $_POST['longitude'] ? floatval($_POST['longitude']) : null;
        $establishment_year = !empty($_POST['establishment_year']) ? (int)$_POST['establishment_year'] : null;
        $management_agency = trim($_POST['management_agency']);
        $manager_name = trim($_POST['manager_name']);
        $manager_contact = trim($_POST['manager_contact']);
        $description = trim($_POST['description']);
        $video_url = trim($_POST['video_url']);
        $geom_path = isset($_POST['geom_path']) ? trim($_POST['geom_path']) : null;
        
        // 유효성 검사
        if (empty($location_name)) throw new Exception('장소명을 입력해주세요.');
        if (empty($region_id)) throw new Exception('지역을 선택해주세요.');
        if (empty($category_id)) throw new Exception('카테고리를 선택해주세요.');
        
        $db->beginTransaction();

        // 장소 정보 업데이트 쿼리 (기존과 동일)
        $update_query = "UPDATE locations SET
                         region_id = :region_id, category_id = :category_id, location_name = :location_name,
                         address = :address, area = :area, road_name = :road_name, road_type = :road_type,
                         section_start = :section_start, section_end = :section_end, length = :length, width = :width,
                         location_type = :location_type, latitude = :latitude, longitude = :longitude,
                         establishment_year = :establishment_year, management_agency = :management_agency, 
                         manager_name = :manager_name, manager_contact = :manager_contact,
                         description = :description, video_url = :video_url, updated_at = CURRENT_TIMESTAMP
                         WHERE location_id = :location_id";
        
        $stmt = $db->prepare($update_query);
        $stmt->execute([
            ':region_id' => $region_id, ':category_id' => $category_id, ':location_name' => $location_name,
            ':address' => $address, ':area' => $area, ':road_name' => $road_name, ':road_type' => $road_type,
            ':section_start' => $section_start, ':section_end' => $section_end, ':length' => $length, ':width' => $width,
            ':location_type' => $location_type, ':latitude' => $latitude, ':longitude' => $longitude,
            ':establishment_year' => $establishment_year, ':management_agency' => $management_agency,
            ':manager_name' => $manager_name, ':manager_contact' => $manager_contact, ':description' => $description,
            ':video_url' => $video_url, ':location_id' => $location_id
        ]);

        // 공간 데이터 저장 (기존과 동일)
        if ($latitude && $longitude) {
            $db->prepare("UPDATE locations SET geom_point = PointFromText(:wkt) WHERE location_id = :id")
               ->execute([':wkt' => "POINT($longitude $latitude)", ':id' => $location_id]);
        }
        if (!empty($geom_path) && (strpos($geom_path, 'LINESTRING') === 0 || strpos($geom_path, 'POLYGON') === 0)) {
            $db->prepare("UPDATE locations SET geom_polygon = GeomFromText(:wkt) WHERE location_id = :id")
               ->execute([':wkt' => $geom_path, ':id' => $location_id]);
        }

        // --- 파일 업로드 처리 (수정됨) ---
        $upload_dir = UPLOAD_PATH;
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        
        // 파일명 안전 변환을 위한 이름 준비
        $safe_loc_name = preg_replace('/[^a-zA-Z0-9가-힣_-]/u', '', str_replace(' ', '_', $location_name));
        if (empty($safe_loc_name)) $safe_loc_name = 'location';

        // 1. 일반 이미지 업로드
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $max_order_query = "SELECT COALESCE(MAX(sort_order), 0) as max_order FROM location_photos WHERE location_id = :location_id AND photo_type = 'image'";
            $max_order_stmt = $db->prepare($max_order_query);
            $max_order_stmt->execute([':location_id' => $location_id]);
            $sort_order = $max_order_stmt->fetch()['max_order'] + 1;
            
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if (empty($tmp_name) || $_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) continue;
                
                $file_name = $_FILES['images']['name'][$key];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (in_array($file_ext, $allowed_ext_array)) {
                    
					// [수정] 안전한 파일명 사용
                    $new_file_name = 'location_' . $location_id . '_' . uniqid() . '.' . $file_ext;
                    $file_path = $upload_dir . $new_file_name;


                    // [추가] GPS 추출 (원본 파일에서)
                    $gps = getGPSFromExif($tmp_name);
                    $gpsLat = $gps ? $gps['lat'] : null;
                    $gpsLng = $gps ? $gps['lng'] : null;

                    if (processAndSaveImage($tmp_name, $file_path, 80)) {
                        $saved_files[] = $file_path;
                        $relative_path = 'uploads/photos/' . $new_file_name;
                        
                        // [수정] gps_latitude, gps_longitude 추가
                        $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, sort_order, gps_latitude, gps_longitude, uploaded_by, uploaded_at) VALUES (:location_id, :file_path, :file_name, :file_size, 'image', :sort_order, :gpsLat, :gpsLng, :uploaded_by, NOW())";
                        $photo_stmt = $db->prepare($photo_query);
                        $photo_stmt->execute([
                            ':location_id' => $location_id, ':file_path' => $relative_path, ':file_name' => $file_name,
                            ':file_size' => filesize($file_path), ':sort_order' => $sort_order,
                            ':gpsLat' => $gpsLat, ':gpsLng' => $gpsLng, ':uploaded_by' => $_SESSION['user_id']
                        ]);
                        $sort_order++;
                    }
                }
            }
        }

        // 2. 360 VR 사진 업로드
        if (isset($_FILES['vr_photo']) && !empty($_FILES['vr_photo']['tmp_name'])) {
            if ($_FILES['vr_photo']['error'] === UPLOAD_ERR_OK) {
                $file_name = $_FILES['vr_photo']['name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (in_array($file_ext, $allowed_ext_array)) {
                    // [수정] 안전한 파일명 사용
                    $new_file_name = 'location_vr_' . $location_id . '_' . uniqid() . '.' . $file_ext;
                    $file_path = $upload_dir . $new_file_name;

                    // [추가] GPS 추출
                    $gps = getGPSFromExif($_FILES['vr_photo']['tmp_name']);
                    $gpsLat = $gps ? $gps['lat'] : null;
                    $gpsLng = $gps ? $gps['lng'] : null;

                    if (processAndSaveImage($_FILES['vr_photo']['tmp_name'], $file_path, 90)) {
                        $saved_files[] = $file_path;
                        $relative_path = 'uploads/photos/' . $new_file_name;

                        // [수정] gps_latitude, gps_longitude 추가
                        $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, gps_latitude, gps_longitude, uploaded_by, uploaded_at) VALUES (:location_id, :file_path, :file_name, :file_size, 'vr360', :gpsLat, :gpsLng, :uploaded_by, NOW())";
                        $photo_stmt = $db->prepare($photo_query);
                        $photo_stmt->execute([
                            ':location_id' => $location_id, ':file_path' => $relative_path, ':file_name' => $file_name,
                            ':file_size' => filesize($file_path), ':gpsLat' => $gpsLat, ':gpsLng' => $gpsLng, ':uploaded_by' => $_SESSION['user_id']
                        ]);
                    }
                }
            }
        }

        $db->commit();
        $_SESSION['success_message'] = '장소가 성공적으로 수정되었습니다.';
        header('Location: view.php?id=' . $location_id);
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        foreach ($saved_files as $file_to_delete) {
            if (file_exists($file_to_delete)) @unlink($file_to_delete);
        }
        $error_message = $e->getMessage();
    }
}

// GET 데이터 조회
$query = "SELECT *, AsText(geom_polygon) as geom_wkt FROM locations WHERE location_id = :location_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':location_id', $location_id);
$stmt->execute();
$location = $stmt->fetch();

if (!$location) {
    $_SESSION['error_message'] = '장소를 찾을 수 없습니다.';
    header('Location: list.php');
    exit;
}

// 사진 목록 조회
$photos_query = "SELECT * FROM location_photos WHERE location_id = :location_id ORDER BY photo_type, sort_order";
$photos_stmt = $db->prepare($photos_query);
$photos_stmt->bindParam(':location_id', $location_id);
$photos_stmt->execute();
$photos = $photos_stmt->fetchAll();

// POST 데이터가 있으면 사용, 없으면 기존 데이터 사용
$form_data = $_SERVER['REQUEST_METHOD'] == 'POST' ? $_POST : $location;

// 지역 목록 조회
$regions_query = "SELECT * FROM regions ORDER BY region_name";
$regions = $db->query($regions_query)->fetchAll();

// 카테고리 목록 조회
$categories_query = "SELECT c.* FROM categories c ORDER BY c.category_name";
$categories = $db->query($categories_query)->fetchAll();

include '../../includes/header.php';
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<style>
.form-container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); max-width: 100%; margin: 0 auto; }
.form-section { margin-bottom: 30px; padding-bottom: 30px; border-bottom: 2px solid #f3f4f6; }
.form-section:last-child { border-bottom: none; }
.form-section-title { font-size: 18px; font-weight: 600; color: #1f2937; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #374151; }
.form-group label .required { color: #ef4444; margin-left: 4px; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; box-sizing: border-box; }
.form-group textarea { min-height: 100px; resize: vertical; }
.form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px; padding-top: 20px; border-top: 2px solid #f3f4f6; }
.dynamic-field { display: none; }
.dynamic-field.active { display: block; }

/* 지도 스타일 */
#map { width: 100%; height: 500px; border-radius: 8px; position: relative; overflow: hidden; border: 1px solid #ddd; }
.map-controls { position: absolute; top: 10px; right: 10px; z-index: 20; display: flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end; width: 90%; }
.map-btn { background: white; border: 1px solid #999; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; color: #333; box-shadow: 0 2px 4px rgba(0,0,0,0.2); margin-bottom:5px; }
.map-btn:hover { background: #f8f9fa; }
.map-btn.active { background: #4a90e2; color: white; border-color: #357abd; }

.mode-group { display:flex; gap:0; margin-right:10px; }
.mode-btn { background: #fff; border: 1px solid #999; padding: 6px 10px; cursor: pointer; font-size: 12px; font-weight: bold; color: #333; }
.mode-btn:first-child { border-radius: 4px 0 0 4px; border-right: none; }
.mode-btn:last-child { border-radius: 0 4px 4px 0; }
.mode-btn.selected { background: #004c80; color: white; border-color: #004c80; z-index: 1; }

.map-loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 30; background: rgba(0,0,0,0.7); color: white; padding: 10px 20px; border-radius: 5px; display: none; font-size: 14px; }
.gps-info { background: #f0fdf4; border: 1px solid #86efac; border-radius: 5px; padding: 12px; margin-top: 10px; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }

.route-guide { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); z-index: 25; background: rgba(0,0,0,0.85); color: white; padding: 10px 20px; border-radius: 8px; font-size: 13px; display: none; text-align: center; }
.route-guide.active { display: block; }
.route-guide .step { color: #fbbf24; font-weight: bold; }

#placesList { list-style: none; padding: 0; margin: 5px 0 0 0; border: 1px solid #ddd; max-height: 200px; overflow-y: auto; background: white; display: none; border-radius: 5px; z-index: 1000; position: relative;}
#placesList li { padding: 10px; border-bottom: 1px solid #eee; cursor: pointer; font-size: 13px; }
#placesList li:hover { background: #f0f9ff; }

.existing-photos { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
.existing-photo-item { position: relative; width: 120px; height: 120px; border-radius: 8px; overflow: hidden; }
.existing-photo-item img { width: 100%; height: 100%; object-fit: cover; }
.existing-photo-item .delete-link { position: absolute; top: 5px; right: 5px; background: rgba(220, 53, 69, 0.9); color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; text-decoration: none; }
.existing-photo-item .vr-badge { position: absolute; bottom: 5px; left: 5px; background: rgba(59, 130, 246, 0.9); color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; }

.image-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.image-preview-item { width: 120px; height: 120px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
.image-preview-item img { width: 100%; height: 100%; object-fit: cover; }

.toast-msg { position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.85); color: white; padding: 15px 25px; border-radius: 8px; z-index: 9999; font-size: 14px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.3); max-width: 90%; }
.toast-msg.success { background: #059669; }
.toast-msg.error { background: #dc2626; }


.route-sub-group { display: flex; gap: 5px; margin-right: 10px; }
.route-sub-btn { background: #fff; border: 1px solid #999; padding: 5px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: 600; color: #333; }
.route-sub-btn.active { background: #059669; color: white; border-color: #047857; }
.route-sub-btn.manual-active { background: #7c3aed; color: white; border-color: #6d28d9; }

.point-counter { position: absolute; top: 60px; left: 10px; z-index: 25; background: #7c3aed; color: white; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; display: none; }
.point-counter.active { display: block; }

.route-info { position: absolute; bottom: 50px; left: 10px; z-index: 25; background: rgba(255,255,255,0.95); padding: 8px 12px; border-radius: 6px; font-size: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.2); display: none; }
.route-info.active { display: block; }
.route-info .value { font-weight: bold; color: #059669; }

.route-guide .manual-hint { color: #a78bfa; }



</style>

<div class="page-header">
    <div><h2>✏️ 장소 수정</h2><p><?php echo htmlspecialchars($location['location_name']); ?></p></div>
    <a href="view.php?id=<?php echo $location_id; ?>" class="btn btn-secondary">← 상세보기</a>
</div>

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger"><?php echo $error_message; ?></div>
<?php endif; ?>

<?php if (isset($_GET['message'])): ?>
    <div class="alert alert-<?php echo $_GET['type'] === 'success' ? 'success' : 'danger'; ?>">
        <?php echo htmlspecialchars($_GET['message']); ?>
    </div>
<?php endif; ?>

<div class="form-container">
    <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="geom_path" id="geom_path" value="<?php echo htmlspecialchars($location['geom_wkt'] ?? ''); ?>">

        <div class="form-section">
            <div class="form-section-title">📋 기본 정보</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>지역 <span class="required">*</span></label>
                    <select name="region_id" id="region_id" required onchange="moveToRegion(this)">
                        <option value="">선택하세요</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo $region['region_id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($region['region_name']); ?>"
                                    <?php echo ($form_data['region_id'] == $region['region_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($region['region_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>카테고리 <span class="required">*</span></label>
                    <select name="category_id" id="category_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['category_id']; ?>"
                                    <?php echo ($form_data['category_id'] == $category['category_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>장소명 <span class="required">*</span></label>
                    <input type="text" name="location_name" id="location_name" required 
                           value="<?php echo htmlspecialchars($form_data['location_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>주소</label>
                    <input type="text" name="address" id="address" 
                           value="<?php echo htmlspecialchars($form_data['address'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>장소 유형 <span class="required">*</span></label>
                    <select name="location_type" id="location_type" required onchange="onLocationTypeChange()">
                        <option value="urban_forest" <?php echo ($form_data['location_type'] == 'urban_forest') ? 'selected' : ''; ?>>도시숲 (면적)</option>
                        <option value="street_tree" <?php echo ($form_data['location_type'] == 'street_tree') ? 'selected' : ''; ?>>가로수 (경로)</option>
                        <option value="living_forest" <?php echo ($form_data['location_type'] == 'living_forest') ? 'selected' : ''; ?>>생활숲</option>
                        <option value="school" <?php echo ($form_data['location_type'] == 'school') ? 'selected' : ''; ?>>학교</option>
                        <option value="park" <?php echo ($form_data['location_type'] == 'park') ? 'selected' : ''; ?>>공원</option>
                        <option value="other" <?php echo ($form_data['location_type'] == 'other') ? 'selected' : ''; ?>>기타</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-section dynamic-field" id="area-section">
            <div class="form-section-title">📐 면적 정보</div>
            <div class="form-group">
                <label>면적 (㎡)</label>
                <input type="number" name="area" id="area" step="0.01" 
                       value="<?php echo $form_data['area'] ?? ''; ?>">
            </div>
        </div>
        
        <div class="form-section dynamic-field" id="road-section">
            <div class="form-section-title">🛣️ 도로/가로수 정보</div>
            <div class="form-grid">
                <div class="form-group"><label>도로명</label><input type="text" name="road_name" id="road_name" value="<?php echo htmlspecialchars($form_data['road_name'] ?? ''); ?>"></div>
                <div class="form-group"><label>도로 종류</label><input type="text" name="road_type" value="<?php echo htmlspecialchars($form_data['road_type'] ?? ''); ?>"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>시점</label><input type="text" name="section_start" id="section_start" value="<?php echo htmlspecialchars($form_data['section_start'] ?? ''); ?>"></div>
                <div class="form-group"><label>종점</label><input type="text" name="section_end" id="section_end" value="<?php echo htmlspecialchars($form_data['section_end'] ?? ''); ?>"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>연장 (m)</label><input type="number" name="length" id="length" step="0.01" value="<?php echo $form_data['length'] ?? ''; ?>"></div>
                <div class="form-group"><label>폭 (m)</label><input type="number" name="width" step="0.01" value="<?php echo $form_data['width'] ?? ''; ?>"></div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">📍 위치 설정</div>
            
            <div class="form-group">
                <label>🔍 장소/주소 검색</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="keyword" placeholder="장소명 또는 주소 (예: 도초면)" 
                           onkeypress="if(event.key==='Enter'){event.preventDefault(); searchPlaces();}">
                    <button type="button" class="btn btn-secondary" onclick="searchPlaces()" style="width: 100px;">검색</button>
                </div>
                <ul id="placesList"></ul>
            </div>

<div class="form-group">
    <div id="map">
        <div class="map-loading" id="mapLoading">데이터 불러오는 중...</div>
        <div class="map-controls">
            <div class="mode-group">
                <button type="button" class="mode-btn selected" id="modePoint" onclick="setMode('point')">📍 위치/면적</button>
                <button type="button" class="mode-btn" id="modeRoute" onclick="setMode('route')">🛣️ 가로수 경로</button>
            </div>
            <!-- ⭐ 추가: 자동/수동 선택 버튼 -->
            <div class="route-sub-group" id="routeSubGroup" style="display:none;">
                <button type="button" class="route-sub-btn active" id="btnAutoRoute" onclick="setRouteType('auto')">🚗 자동</button>
                <button type="button" class="route-sub-btn" id="btnManualRoute" onclick="setRouteType('manual')">✏️ 수동</button>
            </div>
            <span style="width:1px; background:#ccc; margin:0 5px;"></span>
            <button type="button" class="map-btn active" id="btnRoadmap" onclick="setMapType('roadmap', this)">일반지도</button>
            <button type="button" class="map-btn" id="btnSkyview" onclick="setMapType('skyview', this)">위성지도</button>
            <button type="button" class="map-btn" id="btnVWorld" onclick="toggleVWorldWFS(this)">🔲 지적도</button>
        </div>
        <!-- ⭐ 추가: 포인트 카운터 -->
        <div class="point-counter" id="pointCounter">📍 0개</div>
        <!-- ⭐ 추가: 경로 정보 -->
        <div class="route-info" id="routeInfo"><span class="label">연장:</span> <span class="value" id="routeDistance">0</span>m</div>
        <div class="route-guide" id="routeGuide">
            <span class="step" id="routeStep">1️⃣ 시점</span>을 클릭하세요
        </div>
    </div>
    <div class="gps-info" id="gps-info" style="<?php echo ($form_data['latitude'] && $form_data['longitude']) ? '' : 'display: none;'; ?>">
        <span>📌 <strong>좌표:</strong> <span id="selected-coords">
            <?php if ($form_data['latitude'] && $form_data['longitude']): ?>
                위도 <?php echo number_format($form_data['latitude'], 6); ?>, 경도 <?php echo number_format($form_data['longitude'], 6); ?>
            <?php endif; ?>
        </span></span>
        <div style="display:flex;gap:8px;">
            <!-- ⭐ 추가: 되돌리기 버튼 -->
            <button type="button" class="btn btn-sm" id="btnUndo" onclick="undoLastPoint()" style="display:none;background:#7c3aed;color:white;">↩️ 되돌리기</button>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearMapSelection()">🗑️ 초기화</button>
        </div>
    </div>
</div>






            <div class="form-grid">
                <div class="form-group">
                    <label>위도 (Latitude)</label>
                    <input type="number" name="latitude" id="latitude" step="0.00000001" 
                           value="<?php echo $form_data['latitude'] ?? ''; ?>" readonly style="background:#f9fafb;">
                </div>
                <div class="form-group">
                    <label>경도 (Longitude)</label>
                    <input type="number" name="longitude" id="longitude" step="0.00000001" 
                           value="<?php echo $form_data['longitude'] ?? ''; ?>" readonly style="background:#f9fafb;">
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">👤 관리 정보</div>
            <div class="form-grid">
                <div class="form-group"><label>조성년도</label><input type="number" name="establishment_year" value="<?php echo $form_data['establishment_year'] ?? ''; ?>"></div>
                <div class="form-group"><label>관리기관</label><input type="text" name="management_agency" value="<?php echo htmlspecialchars($form_data['management_agency'] ?? ''); ?>"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>관리자</label><input type="text" name="manager_name" value="<?php echo htmlspecialchars($form_data['manager_name'] ?? ''); ?>"></div>
                <div class="form-group"><label>연락처</label><input type="text" name="manager_contact" value="<?php echo htmlspecialchars($form_data['manager_contact'] ?? ''); ?>"></div>
            </div>
            <div class="form-group"><label>비고</label><textarea name="description"><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea></div>
        </div>

        <div class="form-section">
            <div class="form-section-title">📷 멀티미디어</div>
            
            <div class="form-group">
                <label>기존 사진</label>
                <div class="existing-photos">
                    <?php if (empty($photos)): ?>
                        <p style="color: #888; font-size: 14px;">등록된 사진이 없습니다.</p>
                    <?php endif; ?>
                    <?php foreach ($photos as $photo): ?>
                        <div class="existing-photo-item">
                            <img src="<?php echo BASE_URL . '/' . htmlspecialchars($photo['file_path']); ?>" alt="">
                            <?php if ($photo['photo_type'] === 'vr360'): ?>
                                <span class="vr-badge">360° VR</span>
                            <?php endif; ?>
                            <a href="delete_photo.php?id=<?php echo $photo['photo_id']; ?>&location_id=<?php echo $location_id; ?>" 
                               class="delete-link" 
                               onclick="return confirm('이 사진을 삭제하시겠습니까?');">삭제</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            
            <div class="form-group">
                <label>사진 (다중 선택)</label>
                <input type="file" name="images[]" accept="image/*" multiple onchange="previewImages(this,'image-previews')">
                <div id="image-previews" class="image-preview"></div>

            </div>
            
            <div class="form-group">
                <label>360도 VR 사진 추가</label>
                <input type="file" name="vr_photo" accept="image/*" onchange="previewImages(this,'vr-preview')">
                <div id="vr-preview" class="image-preview"></div>
            </div>
            
            <div class="form-group">
                <label>동영상 URL</label>
                <input type="url" name="video_url" value="<?php echo htmlspecialchars($form_data['video_url'] ?? ''); ?>">
            </div>
        </div>
        
        <div class="form-actions">
            <a href="view.php?id=<?php echo $location_id; ?>" class="btn btn-secondary">취소</a>
            <button type="submit" class="btn btn-primary">💾 수정 저장</button>
        </div>
    </form>
</div>


<script src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?= KAKAO_MAP_API_KEY ?>&libraries=services"></script>
<script src="<?= BASE_URL ?>/assets/js/location_map_common.js"></script>

<script>
// edit.php 전용 변수 및 초기 데이터
const initialLat = <?= $form_data['latitude'] ?? 'null' ?> || <?= DEFAULT_LAT ?>;
const initialLng = <?= $form_data['longitude'] ?? 'null' ?> || <?= DEFAULT_LNG ?>;
const existingGeomWkt = <?= json_encode($location['geom_wkt'] ?? '') ?>;

// ⭐ 추가: 경로 타입 변수 (자동/수동 선택)
var routeType = 'auto';

// 지도 초기화
$(function() {
    initializeMap(initialLat, initialLng, 3);
    toggleFields();
    
    // 기존 마커 표시
    <?php if ($form_data['latitude'] && $form_data['longitude']): ?>
    currentMarker = new kakao.maps.Marker({
        position: new kakao.maps.LatLng(initialLat, initialLng),
        map: map
    });
    <?php endif; ?>
    
    // 기존 경로 또는 폴리곤 표시
    if (existingGeomWkt) {
        drawExistingGeometry(existingGeomWkt);
    }
    
    // 가로수인 경우 경로 모드로 설정
    if($('#location_type').val() === 'street_tree') {
        setMode('route', true);
    }
});

// 기존 geometry 표시 함수
function drawExistingGeometry(wkt) {
    if (!wkt) return;
    
    const path = [];
    
    if (wkt.startsWith('LINESTRING')) {
        const coordsStr = wkt.replace('LINESTRING(', '').replace(')', '');
        const coords = coordsStr.split(',');
        coords.forEach(coord => {
            const parts = coord.trim().split(' ');
            if (parts.length >= 2) {
                path.push(new kakao.maps.LatLng(parseFloat(parts[1]), parseFloat(parts[0])));
            }
        });
        
        if (path.length > 0) {
            routePolyline = new kakao.maps.Polyline({
                map: map, path: path,
                strokeWeight: 8, strokeColor: '#db4040',
                strokeOpacity: 0.9, strokeStyle: 'solid'
            });
            
            routeStart = { lat: path[0].getLat(), lng: path[0].getLng() };
            routeEnd = { lat: path[path.length-1].getLat(), lng: path[path.length-1].getLng() };
            
            routeStartMarker = new kakao.maps.Marker({
                map: map, position: path[0],
                image: new kakao.maps.MarkerImage('https://www.im4u.kr/icons/uploads/icons/red_b_1765370086_8bf43d62.png', 
                    new kakao.maps.Size(50, 45), { offset: new kakao.maps.Point(15, 43) })
            });
            routeEndMarker = new kakao.maps.Marker({
                map: map, position: path[path.length-1],
                image: new kakao.maps.MarkerImage('https://www.im4u.kr/icons/uploads/icons/blue_b_1765370086_8af75b2a.png', 
                    new kakao.maps.Size(50, 45), { offset: new kakao.maps.Point(15, 43) })
            });
        }
    } else if (wkt.startsWith('POLYGON')) {
        const coordsStr = wkt.replace('POLYGON((', '').replace('))', '');
        const coords = coordsStr.split(',');
        coords.forEach(coord => {
            const parts = coord.trim().split(' ');
            if (parts.length >= 2) {
                path.push(new kakao.maps.LatLng(parseFloat(parts[1]), parseFloat(parts[0])));
            }
        });
        
        if (path.length > 2) {
            selectedPolygon = new kakao.maps.Polygon({
                map: map, path: path,
                strokeWeight: 3, strokeColor: '#ff0000', strokeOpacity: 1,
                fillColor: '#ff0000', fillOpacity: 0.3
            });
        }
    }
}

// edit.php에서는 toggleVWorld 대신 toggleVWorldWFS 사용
function toggleVWorldWFS(btn) {
    toggleVWorld(btn);
}

// clearMapSelection은 clearMap과 동일
function clearMapSelection() {
    clearMap();
}
</script>

<?php include '../../includes/footer.php'; ?>
