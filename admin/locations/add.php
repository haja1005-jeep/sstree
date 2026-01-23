<?php
/**
 * 장소 추가 (Smart Tree Map)
 * - 위치/면적 모드: 지적도 기반 면적 자동 계산
 * - 가로수 경로 모드: 자동(카카오 길찾기) / 수동(직접 클릭) 선택
 */

require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '장소 추가';
$database = new Database();
$db = $database->getConnection();

$allowed_ext_array = defined('ALLOWED_EXTENSIONS') ? array_map('trim', array_map('strtolower', explode(',', ALLOWED_EXTENSIONS))) : ['jpg','jpeg','png','gif'];
if (!defined('UPLOAD_PATH')) define('UPLOAD_PATH', '../../uploads/photos/');

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

// 이미지 회전 보정
function autoOrientImage($img, $path) {
    if (!function_exists('exif_read_data')) return $img;
    $exif = @exif_read_data($path);
    if (!empty($exif['Orientation'])) {
        switch ($exif['Orientation']) {
            case 3: return imagerotate($img, 180, 0);
            case 6: return imagerotate($img, -90, 0);
            case 8: return imagerotate($img, 90, 0);
        }
    }
    return $img;
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


// POST 처리
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $saved_files = [];
    try {
        $region_id = (int)($_POST['region_id'] ?? 0);
        $category_id = (int)($_POST['category_id'] ?? 0);
        $location_name = trim($_POST['location_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $area = !empty($_POST['area']) ? floatval($_POST['area']) : null;
        $road_name = trim($_POST['road_name'] ?? '');
        $road_type = trim($_POST['road_type'] ?? '');
        $section_start = trim($_POST['section_start'] ?? '');
        $section_end = trim($_POST['section_end'] ?? '');
        $length = !empty($_POST['length']) ? floatval($_POST['length']) : null;
        $width = !empty($_POST['width']) ? floatval($_POST['width']) : null;
        $location_type = $_POST['location_type'] ?? '';
        $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null;
        $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
        $establishment_year = !empty($_POST['establishment_year']) ? (int)$_POST['establishment_year'] : null;
        $management_agency = trim($_POST['management_agency'] ?? '');
        $manager_name = trim($_POST['manager_name'] ?? '');
        $manager_contact = trim($_POST['manager_contact'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $video_url = trim($_POST['video_url'] ?? '');
        $geom_path = trim($_POST['geom_path'] ?? '');

        if (empty($location_name)) throw new Exception('장소명을 입력해주세요.');
        if (empty($region_id)) throw new Exception('지역을 선택해주세요.');
        
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO locations (
            region_id, category_id, location_name, address, area,
            road_name, road_type, section_start, section_end, length, width,
            location_type, latitude, longitude, establishment_year, management_agency,
            manager_name, manager_contact, description, video_url, created_at
        ) VALUES (
            :region_id, :category_id, :location_name, :address, :area,
            :road_name, :road_type, :section_start, :section_end, :length, :width,
            :location_type, :latitude, :longitude, :establishment_year, :management_agency,
            :manager_name, :manager_contact, :description, :video_url, NOW()
        )");
        
        $stmt->execute([
            ':region_id' => $region_id, ':category_id' => $category_id, ':location_name' => $location_name,
            ':address' => $address, ':area' => $area, ':road_name' => $road_name, ':road_type' => $road_type,
            ':section_start' => $section_start, ':section_end' => $section_end, ':length' => $length,
            ':width' => $width, ':location_type' => $location_type, ':latitude' => $latitude,
            ':longitude' => $longitude, ':establishment_year' => $establishment_year,
            ':management_agency' => $management_agency, ':manager_name' => $manager_name,
            ':manager_contact' => $manager_contact, ':description' => $description, ':video_url' => $video_url
        ]);
        $location_id = $db->lastInsertId();

        // 공간 데이터 저장
        if ($latitude && $longitude) {
            $db->prepare("UPDATE locations SET geom_point = PointFromText(:wkt) WHERE location_id = :id")
               ->execute([':wkt' => "POINT($longitude $latitude)", ':id' => $location_id]);
        }
        
        if (!empty($geom_path) && (strpos($geom_path, 'LINESTRING') === 0 || strpos($geom_path, 'POLYGON') === 0)) {
            $db->prepare("UPDATE locations SET geom_polygon = GeomFromText(:wkt) WHERE location_id = :id")
               ->execute([':wkt' => $geom_path, ':id' => $location_id]);
        }
        
        // 사진 업로드
        $upload_dir = UPLOAD_PATH;
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        
        // 파일명 안전 변환을 위한 이름 준비
        $safe_loc_name = preg_replace('/[^a-zA-Z0-9가-힣_-]/u', '', str_replace(' ', '_', $location_name));
        if (empty($safe_loc_name)) $safe_loc_name = 'location';
        
        if (!empty($_FILES['images']['name'][0])) {
            $sort = 1;
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp) {
                if (empty($tmp) || $_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($_FILES['images']['name'][$key], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext_array)) continue;
                
                // [수정] 안전한 파일명 사용
                //$newName = $safe_loc_name . '_' . $location_id . '_' . uniqid() . '.' . $ext;
				$new_file_name = 'location_' . $location_id . '_' . uniqid() . '.' . $ext;
                $path = $upload_dir . $newName;
                
                // [추가] GPS 추출 (원본 파일에서 추출해야 함)
                $gps = getGPSFromExif($tmp);
                $gpsLat = $gps ? $gps['lat'] : null;
                $gpsLng = $gps ? $gps['lng'] : null;
                
                if (processAndSaveImage($tmp, $path, 80)) {
                    $saved_files[] = $path;
                    // [수정] gps_latitude, gps_longitude 추가
                    $db->prepare("INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, sort_order, gps_latitude, gps_longitude, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, 'image', ?, ?, ?, ?, NOW())")
                       ->execute([$location_id, 'uploads/photos/'.$newName, $_FILES['images']['name'][$key], filesize($path), $sort++, $gpsLat, $gpsLng, $_SESSION['user_id']]);
                }
            }
        }
        
        if (!empty($_FILES['vr_photo']['tmp_name']) && $_FILES['vr_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['vr_photo']['name'], PATHINFO_EXTENSION));

			
            //$newName = $safe_loc_name . '_vr_' . $location_id . '_' . uniqid() . '.' . $ext;
			$newName = 'location_vr_' . $location_id . '_' . uniqid() . '.' . $ext;
            $path = $upload_dir . $newName;
            
            // [추가] VR 사진도 GPS가 있다면 추출
            $gps = getGPSFromExif($_FILES['vr_photo']['tmp_name']);
            $gpsLat = $gps ? $gps['lat'] : null;
            $gpsLng = $gps ? $gps['lng'] : null;
            
            if (processAndSaveImage($_FILES['vr_photo']['tmp_name'], $path, 90)) {
                $saved_files[] = $path;
                // [수정] gps_latitude, gps_longitude 추가
                $db->prepare("INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, gps_latitude, gps_longitude, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, 'vr360', ?, ?, ?, NOW())")
                   ->execute([$location_id, 'uploads/photos/'.$newName, $_FILES['vr_photo']['name'], filesize($path), $gpsLat, $gpsLng, $_SESSION['user_id']]);
            }
        }

        $db->commit();
        $_SESSION['success_message'] = '장소가 성공적으로 추가되었습니다.';
        header('Location: list.php');
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        foreach ($saved_files as $f) { if (file_exists($f)) @unlink($f); }
        $error_message = $e->getMessage();
    }
}


$regions = $db->query("SELECT * FROM regions ORDER BY region_name")->fetchAll();
$categories = $db->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();

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
.form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px; padding-top: 20px; border-top: 2px solid #f3f4f6; }
.dynamic-field { display: none; }
.dynamic-field.active { display: block; }

#map { width: 100%; height: 500px; border-radius: 8px; position: relative; overflow: hidden; border: 1px solid #ddd; }
.map-controls { position: absolute; top: 10px; right: 10px; z-index: 20; display: flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end; width: 90%; }
.map-btn { background: white; border: 1px solid #999; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; color: #333; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
.map-btn:hover { background: #f8f9fa; }
.map-btn.active { background: #4a90e2; color: white; border-color: #357abd; }

.mode-group { display: flex; gap: 0; margin-right: 10px; }
.mode-btn { background: #fff; border: 1px solid #999; padding: 6px 10px; cursor: pointer; font-size: 12px; font-weight: bold; color: #333; }
.mode-btn:first-child { border-radius: 4px 0 0 4px; border-right: none; }
.mode-btn:last-child { border-radius: 0 4px 4px 0; }
.mode-btn.selected { background: #004c80; color: white; border-color: #004c80; }

.route-sub-group { display: flex; gap: 5px; margin-right: 10px; }
.route-sub-btn { background: #fff; border: 1px solid #999; padding: 5px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: 600; color: #333; }
.route-sub-btn.active { background: #059669; color: white; border-color: #047857; }
.route-sub-btn.manual-active { background: #7c3aed; color: white; border-color: #6d28d9; }

.map-loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 30; background: rgba(0,0,0,0.7); color: white; padding: 10px 20px; border-radius: 5px; display: none; }
.gps-info { background: #f0fdf4; border: 1px solid #86efac; border-radius: 5px; padding: 12px; margin-top: 10px; font-size: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }

.route-guide { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); z-index: 25; background: rgba(0,0,0,0.85); color: white; padding: 10px 20px; border-radius: 8px; font-size: 13px; display: none; text-align: center; max-width: 90%; }
.route-guide.active { display: block; }
.route-guide .step { color: #fbbf24; font-weight: bold; }
.route-guide .manual-hint { color: #a78bfa; }

.route-info { position: absolute; bottom: 50px; left: 10px; z-index: 25; background: rgba(255,255,255,0.95); padding: 8px 12px; border-radius: 6px; font-size: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.2); display: none; }
.route-info.active { display: block; }
.route-info .value { font-weight: bold; color: #059669; }

.point-counter { position: absolute; top: 60px; left: 10px; z-index: 25; background: #7c3aed; color: white; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; display: none; }
.point-counter.active { display: block; }

#placesList { list-style: none; padding: 0; margin: 5px 0 0 0; border: 1px solid #ddd; max-height: 200px; overflow-y: auto; background: white; display: none; border-radius: 5px; }
#placesList li { padding: 10px; border-bottom: 1px solid #eee; cursor: pointer; font-size: 13px; }
#placesList li:hover { background: #f0f9ff; }

.image-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.image-preview-item { width: 120px; height: 120px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
.image-preview-item img { width: 100%; height: 100%; object-fit: cover; }

.toast-msg { position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.85); color: white; padding: 15px 25px; border-radius: 8px; z-index: 9999; font-size: 14px; text-align: center; max-width: 90%; }
.toast-msg.success { background: #059669; }
.toast-msg.error { background: #dc2626; }
</style>

<div class="page-header">
    <div><h2>➕ 장소 추가</h2><p>지도를 클릭하여 위치와 정보를 입력하세요.</p></div>
    <a href="list.php" class="btn btn-secondary">← 목록으로</a>
</div>

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
<?php endif; ?>

<div class="form-container">
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="geom_path" id="geom_path">

        <div class="form-section">
            <div class="form-section-title">📋 기본 정보</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>지역 <span class="required">*</span></label>
                    <select name="region_id" id="region_id" required onchange="moveToRegion(this)">
                        <option value="">선택하세요</option>
                        <?php foreach ($regions as $r): ?>
                            <option value="<?= $r['region_id'] ?>" data-name="<?= htmlspecialchars($r['region_name']) ?>"><?= htmlspecialchars($r['region_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>카테고리 <span class="required">*</span></label>
                    <select name="category_id" id="category_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['category_id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>장소명 <span class="required">*</span></label>
                    <input type="text" name="location_name" id="location_name" required placeholder="지도에서 선택 시 자동 입력">
                </div>
                <div class="form-group">
                    <label>주소</label>
                    <input type="text" name="address" id="address" placeholder="자동 입력">
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>장소 유형 <span class="required">*</span></label>
                    <select name="location_type" id="location_type" required onchange="onLocationTypeChange()">
                        <option value="urban_forest">도시숲 (면적)</option>
                        <option value="street_tree">가로수 (경로)</option>
                        <option value="living_forest">생활숲</option>
                        <option value="school">학교</option>
                        <option value="park">공원</option>
                        <option value="other">기타</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-section dynamic-field" id="area-section">
            <div class="form-section-title">📐 면적 정보</div>
            <div class="form-group"><label>면적 (㎡)</label><input type="number" name="area" id="area" step="0.01" placeholder="지적도 선택 시 자동 계산"></div>
        </div>
        
        <div class="form-section dynamic-field" id="road-section">
            <div class="form-section-title">🛣️ 도로/가로수 정보</div>
            <div class="form-grid">
                <div class="form-group"><label>도로명</label><input type="text" name="road_name" id="road_name"></div>
                <div class="form-group"><label>도로 종류</label><input type="text" name="road_type"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>시점</label><input type="text" name="section_start" id="section_start"></div>
                <div class="form-group"><label>종점</label><input type="text" name="section_end" id="section_end"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>연장 (m)</label><input type="number" name="length" id="length" step="0.01"></div>
                <div class="form-group"><label>폭 (m)</label><input type="number" name="width" step="0.01"></div>
            </div>
        </div>
 
        <div class="form-section">
            <div class="form-section-title">📍 위치 설정</div>
            <div class="form-group">
                <label>🔍 장소/주소 검색</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="keyword" placeholder="장소명 또는 주소" onkeypress="if(event.key==='Enter'){event.preventDefault();searchPlaces();}">
                    <button type="button" class="btn btn-secondary" onclick="searchPlaces()" style="width:100px;">검색</button>
                </div>
                <ul id="placesList"></ul>
            </div>

            <div class="form-group">
                <div id="map">
                    <div class="map-loading" id="mapLoading">로딩 중...</div>
                    <div class="map-controls">
                        <div class="mode-group">
                            <button type="button" class="mode-btn selected" id="modePoint" onclick="setMode('point')">📍 위치/면적</button>
                            <button type="button" class="mode-btn" id="modeRoute" onclick="setMode('route')">🛣️ 가로수 경로</button>
                        </div>
                        <div class="route-sub-group" id="routeSubGroup" style="display:none;">
                            <button type="button" class="route-sub-btn active" id="btnAutoRoute" onclick="setRouteType('auto')">🚗 자동</button>
                            <button type="button" class="route-sub-btn" id="btnManualRoute" onclick="setRouteType('manual')">✏️ 수동</button>
                        </div>
                        <span style="width:1px;background:#ccc;margin:0 5px;"></span>
                        <button type="button" class="map-btn active" id="btnRoadmap" onclick="setMapType('roadmap')">일반</button>
                        <button type="button" class="map-btn" id="btnSkyview" onclick="setMapType('skyview')">위성</button>
                        <button type="button" class="map-btn" id="btnVWorld" onclick="toggleVWorld(this)">🔲 지적도</button>
                    </div>
                    <div class="point-counter" id="pointCounter">📍 0개</div>
                    <div class="route-info" id="routeInfo"><span class="label">연장:</span> <span class="value" id="routeDistance">0</span>m</div>
                    <div class="route-guide" id="routeGuide"><span class="step" id="routeStep">1️⃣ 시점</span>을 클릭하세요</div>
                </div>
                <div class="gps-info" id="gps-info" style="display:none;">
                    <span>📌 <strong>좌표:</strong> <span id="selected-coords">-</span></span>
                    <div style="display:flex;gap:8px;">
                        <button type="button" class="btn btn-sm" id="btnUndo" onclick="undoLastPoint()" style="display:none;background:#7c3aed;color:white;">↩️ 되돌리기</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearMap()">🗑️ 초기화</button>
                    </div>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>위도</label><input type="number" name="latitude" id="latitude" step="0.00000001" readonly style="background:#f9fafb;"></div>
                <div class="form-group"><label>경도</label><input type="number" name="longitude" id="longitude" step="0.00000001" readonly style="background:#f9fafb;"></div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">👤 관리 정보</div>
            <div class="form-grid">
                <div class="form-group"><label>조성년도</label><input type="number" name="establishment_year" placeholder="예: 2024"></div>
                <div class="form-group"><label>관리기관</label><input type="text" name="management_agency"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>관리자</label><input type="text" name="manager_name"></div>
                <div class="form-group"><label>연락처</label><input type="text" name="manager_contact"></div>
            </div>
            <div class="form-group"><label>비고</label><textarea name="description"></textarea></div>
        </div>

        <div class="form-section">
            <div class="form-section-title">📷 사진/영상</div>
            <div class="form-group">
                <label>사진 (다중 선택)</label>
                <input type="file" name="images[]" accept="image/*" multiple onchange="previewImages(this,'image-previews')">
                <div id="image-previews" class="image-preview"></div>
            </div>
            <div class="form-group">
                <label>360 VR 사진</label>
                <input type="file" name="vr_photo" accept="image/*" onchange="previewImages(this,'vr-preview')">
                <div id="vr-preview" class="image-preview"></div>
            </div>
            <div class="form-group"><label>영상 URL</label><input type="url" name="video_url"></div>
        </div>

        <div class="form-actions">
            <a href="list.php" class="btn btn-secondary">취소</a>
            <button type="submit" class="btn btn-primary">💾 저장</button>
        </div>
    </form>
</div>

<script src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?= KAKAO_MAP_API_KEY ?>&libraries=services"></script>
<script src="<?= BASE_URL ?>/assets/js/location_map_common.js"></script>

<script>
// add.php 전용 변수
var routeType = 'auto'; // 자동/수동 경로 선택

// 지도 초기화
$(function() {
    initializeMap(<?= DEFAULT_LAT ?>, <?= DEFAULT_LNG ?>, 3);
    toggleFields();
});


</script>

<?php include '../../includes/footer.php'; ?>
