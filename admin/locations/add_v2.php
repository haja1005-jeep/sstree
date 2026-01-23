<?php
/**
 * 장소 추가 (Modern UI Design)
 * Smart Tree Map - Location Management
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

// [GPS 변환 및 이미지 처리 함수들은 기존 로직 그대로 유지]
function gps2Num($coordPart) {
    $parts = explode('/', $coordPart);
    if (count($parts) <= 0) return 0;
    if (count($parts) == 1) return $parts[0];
    return floatval($parts[0]) / floatval($parts[1]);
}

function getGPSFromExif($file) {
    if (!function_exists('exif_read_data')) return null;
    $exif = @exif_read_data($file);
    if (isset($exif['GPSLatitude']) && isset($exif['GPSLongitude'])) {
        $lat = $exif['GPSLatitude'];
        $lng = $exif['GPSLongitude'];
        $latitude = gps2Num($lat[0]) + (gps2Num($lat[1]) / 60) + (gps2Num($lat[2]) / 3600);
        $longitude = gps2Num($lng[0]) + (gps2Num($lng[1]) / 60) + (gps2Num($lng[2]) / 3600);
        if ($exif['GPSLatitudeRef'] == 'S') $latitude *= -1;
        if ($exif['GPSLongitudeRef'] == 'W') $longitude *= -1;
        return ['lat' => $latitude, 'lng' => $longitude];
    }
    return null;
}

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

function processAndSaveImage($src, $dest, $quality = 80) {
    ini_set('memory_limit', '512M');
    try {
        $info = getimagesize($src);
        if (!$info) return false;
        list($w, $h, $type) = [$info[0], $info[1], $info['mime']];
        
        $max_dimension = 1920;
        if ($w > $max_dimension || $h > $max_dimension) {
            $ratio = $max_dimension / max($w, $h);
            $newW = (int)($w * $ratio);
            $newH = (int)($h * $ratio);
        } else { $newW = $w; $newH = $h; }
        
        $destImg = imagecreatetruecolor($newW, $newH);
        switch ($type) {
            case 'image/jpeg': $srcImg = imagecreatefromjpeg($src); $srcImg = autoOrientImage($srcImg, $src); break;
            case 'image/png': $srcImg = imagecreatefrompng($src); imagealphablending($destImg, false); imagesavealpha($destImg, true); break;
            case 'image/gif': $srcImg = imagecreatefromgif($src); break;
            default: return move_uploaded_file($src, $dest);
        }
        if (!$srcImg) return false;
        imagecopyresampled($destImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);
        
        $success = false;
        switch ($type) {
            case 'image/jpeg': $success = imagejpeg($destImg, $dest, $quality); break;
            case 'image/png': $success = imagepng($destImg, $dest, 8); break;
            case 'image/gif': $success = imagegif($destImg, $dest); break;
        }
        imagedestroy($srcImg); imagedestroy($destImg);
        return $success;
    } catch (Exception $e) { return false; }
}

// POST 처리 로직 (기존 유지)
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

        if ($latitude && $longitude) {
            $db->prepare("UPDATE locations SET geom_point = PointFromText(:wkt) WHERE location_id = :id")
               ->execute([':wkt' => "POINT($longitude $latitude)", ':id' => $location_id]);
        }
        if (!empty($geom_path) && (strpos($geom_path, 'LINESTRING') === 0 || strpos($geom_path, 'POLYGON') === 0)) {
            $db->prepare("UPDATE locations SET geom_polygon = GeomFromText(:wkt) WHERE location_id = :id")
               ->execute([':wkt' => $geom_path, ':id' => $location_id]);
        }
        
        $upload_dir = UPLOAD_PATH;
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        
        if (!empty($_FILES['images']['name'][0])) {
            $sort = 1;
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp) {
                if (empty($tmp) || $_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($_FILES['images']['name'][$key], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext_array)) continue;
                
                $new_file_name = 'location_' . $location_id . '_' . uniqid() . '.' . $ext;
                $path = $upload_dir . $new_file_name;
                $gps = getGPSFromExif($tmp);
                
                if (processAndSaveImage($tmp, $path, 80)) {
                    $saved_files[] = $path;
                    $db->prepare("INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, sort_order, gps_latitude, gps_longitude, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, 'image', ?, ?, ?, ?, NOW())")
                       ->execute([$location_id, 'uploads/photos/'.$new_file_name, $_FILES['images']['name'][$key], filesize($path), $sort++, $gps['lat']??null, $gps['lng']??null, $_SESSION['user_id']]);
                }
            }
        }
        
        if (!empty($_FILES['vr_photo']['tmp_name']) && $_FILES['vr_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['vr_photo']['name'], PATHINFO_EXTENSION));
            $new_file_name = 'location_vr_' . $location_id . '_' . uniqid() . '.' . $ext;
            $path = $upload_dir . $new_file_name;
            $gps = getGPSFromExif($_FILES['vr_photo']['tmp_name']);
            
            if (processAndSaveImage($_FILES['vr_photo']['tmp_name'], $path, 90)) {
                $saved_files[] = $path;
                $db->prepare("INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, gps_latitude, gps_longitude, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, 'vr360', ?, ?, ?, NOW())")
                   ->execute([$location_id, 'uploads/photos/'.$new_file_name, $_FILES['vr_photo']['name'], filesize($path), $gps['lat']??null, $gps['lng']??null, $_SESSION['user_id']]);
            }
        }

        $db->commit();
        $_SESSION['success_message'] = '장소가 성공적으로 추가되었습니다.';
        header('Location: index.php');
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
:root {
    --primary: #10B981;
    --primary-dark: #059669;
    --secondary: #3B82F6;
    --bg-page: #F3F4F6;
    --surface: #FFFFFF;
    --text-main: #111827;
    --text-sub: #6B7280;
    --border: #E5E7EB;
    --radius: 12px;
}

body { background-color: var(--bg-page); color: var(--text-main); font-family: 'Pretendard', sans-serif; }

/* 레이아웃 & 카드 */
.page-container { max-width: 100%; margin: 0 auto; padding-bottom: 40px; }
.card { background: var(--surface); border-radius: var(--radius); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: none; overflow: hidden; margin-bottom: 24px; }
.card-body { padding: 30px; }

/* 섹션 타이틀 */
.section-title {
    font-size: 1.2rem; font-weight: 700; color: var(--text-main); margin-bottom: 1.5rem;
    display: flex; align-items: center; gap: 10px;
    padding-left: 15px; border-left: 5px solid var(--primary);
}

/* 폼 스타일 */
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-main); margin-bottom: 8px; }
.form-label .required { color: #EF4444; margin-left: 3px; }

.form-control {
    width: 100%; padding: 12px 16px; font-size: 0.95rem;
    border: 1px solid var(--border); border-radius: 8px;
    background-color: #fff; transition: all 0.2s ease; box-sizing: border-box;
}
.form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1); outline: none; }
.form-control[readonly] { background-color: #F9FAFB; color: #6B7280; cursor: not-allowed; }
textarea.form-control { min-height: 120px; resize: vertical; line-height: 1.6; }

/* 지도 스타일 */
#map { width: 100%; height: 500px; border-radius: var(--radius); position: relative; overflow: hidden; border: 1px solid var(--border); }
.map-overlay-controls {
    position: absolute; top: 15px; right: 15px; z-index: 20;
    display: flex; flex-direction: column; gap: 8px; align-items: flex-end;
}
.control-group-row { display: flex; gap: 5px; background: rgba(255,255,255,0.95); padding: 5px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); backdrop-filter: blur(4px); }

.map-btn {
    background: white; border: 1px solid #E5E7EB; padding: 8px 14px; border-radius: 6px;
    cursor: pointer; font-size: 0.85rem; font-weight: 600; color: var(--text-main); transition: all 0.2s;
}
.map-btn:hover { background: #F3F4F6; }
.map-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
.map-btn.mode-active { background: var(--secondary); color: white; border-color: var(--secondary); }

.guide-box {
    position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 25;
    background: rgba(31, 41, 55, 0.9); color: white; padding: 10px 24px;
    border-radius: 30px; font-size: 0.9rem; font-weight: 500;
    box-shadow: 0 4px 15px rgba(0,0,0,0.3); pointer-events: none;
    display: flex; align-items: center; gap: 8px;
}
.guide-step { color: #FBBF24; font-weight: 800; }

.info-float-box {
    position: absolute; top: 15px; left: 15px; z-index: 20;
    background: white; padding: 12px 20px; border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.15); font-size: 0.9rem;
    display: none; border-left: 4px solid var(--secondary);
}
.info-float-box.active { display: block; animation: slideIn 0.3s ease; }
@keyframes slideIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

/* 파일 업로드 프리뷰 */
.file-upload-wrapper { border: 2px dashed #D1D5DB; border-radius: var(--radius); padding: 30px; text-align: center; transition: all 0.2s; background: #F9FAFB; cursor: pointer; }
.file-upload-wrapper:hover { border-color: var(--primary); background: #F0FDF4; }
.preview-container { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 15px; }
.preview-item { width: 100px; height: 100px; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border: 1px solid #E5E7EB; }
.preview-item img { width: 100%; height: 100%; object-fit: cover; }

/* 버튼 */
.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; }
.btn { padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; font-size: 1rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: transform 0.1s; }
.btn:active { transform: scale(0.98); }
.btn-primary { background: var(--primary); color: white; }
.btn-secondary { background: white; border: 1px solid #D1D5DB; color: var(--text-main); }
.btn-secondary:hover { border-color: #9CA3AF; background: #F9FAFB; }

.dynamic-field { display: none; }
.dynamic-field.active { display: block; animation: fadeIn 0.3s; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

image-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.image-preview-item { width: 120px; height: 120px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
.image-preview-item img { width: 100%; height: 100%; object-fit: cover; }

</style>

<div class="page-container">
    <div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="margin: 0; font-size: 1.8rem; font-weight: 800; color: #111827;">➕ 장소 등록</h2>
            <p style="margin: 5px 0 0; color: #6B7280;">새로운 장소 정보를 입력하고 지도를 클릭하여 위치를 지정하세요.</p>
        </div>
        <a href="index.php" class="btn btn-secondary">← 목록으로</a>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="card" style="background: #FEF2F2; border-left: 5px solid #EF4444;">
            <div class="card-body" style="padding: 15px; color: #991B1B;">⚠️ <?= htmlspecialchars($error_message) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="geom_path" id="geom_path">

        <div class="card">
            <div class="card-body">
                <h3 class="section-title">기본 정보</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">지역 <span class="required">*</span></label>
                        <select name="region_id" id="region_id" class="form-control" required onchange="moveToRegion(this)">
                            <option value="">선택하세요</option>
                            <?php foreach ($regions as $r): ?>
                                <option value="<?= $r['region_id'] ?>" data-name="<?= htmlspecialchars($r['region_name']) ?>"><?= htmlspecialchars($r['region_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">카테고리 <span class="required">*</span></label>
                        <select name="category_id" id="category_id" class="form-control" required>
                            <option value="">선택하세요</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['category_id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">장소명 <span class="required">*</span></label>
                        <input type="text" name="location_name" id="location_name" class="form-control" required placeholder="지도에서 선택 시 자동 입력됨">
                    </div>
                    <div class="form-group">
                        <label class="form-label">주소</label>
                        <input type="text" name="address" id="address" class="form-control" placeholder="자동 입력됨">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">장소 유형 <span class="required">*</span></label>
                    <select name="location_type" id="location_type" class="form-control" required onchange="onLocationTypeChange()">
                        <option value="urban_forest">🌳 도시숲 (면적/폴리곤)</option>
                        <option value="street_tree">🛣️ 가로수 (경로/라인)</option>
                        <option value="living_forest">🏡 생활숲</option>
                        <option value="school">🏫 학교</option>
                        <option value="park">🏞️ 공원</option>
                        <option value="other">🎸 기타</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card dynamic-field" id="area-section">
            <div class="card-body">
                <h3 class="section-title">면적 정보</h3>
                <div class="form-group">
                    <label class="form-label">면적 (㎡)</label>
                    <input type="number" name="area" id="area" step="0.01" class="form-control" placeholder="지적도 폴리곤 선택 시 자동 계산">
                </div>
            </div>
        </div>
        
        <div class="card dynamic-field" id="road-section">
            <div class="card-body">
                <h3 class="section-title">가로수/도로 정보</h3>
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">도로명</label><input type="text" name="road_name" id="road_name" class="form-control"></div>
                    <div class="form-group"><label class="form-label">도로 종류</label><input type="text" name="road_type" class="form-control"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">시점</label><input type="text" name="section_start" id="section_start" class="form-control"></div>
                    <div class="form-group"><label class="form-label">종점</label><input type="text" name="section_end" id="section_end" class="form-control"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">연장 (m)</label><input type="number" name="length" id="length" step="0.01" class="form-control"></div>
                    <div class="form-group"><label class="form-label">폭 (m)</label><input type="number" name="width" step="0.01" class="form-control"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h3 class="section-title">위치 설정</h3>
                
                <div class="form-group" style="position: relative;">
                    <input type="text" id="keyword" class="form-control" placeholder="🔍 장소명 또는 주소 검색 (엔터)" 
                           onkeypress="if(event.key==='Enter'){event.preventDefault();searchPlaces();}" style="padding-right: 80px;">
                    <button type="button" onclick="searchPlaces()" 
                            style="position: absolute; right: 5px; top: 5px; height: 38px; border: none; background: var(--primary); color: white; border-radius: 6px; padding: 0 15px; cursor: pointer;">검색</button>
                    <ul id="placesList" style="display:none; border:1px solid #ddd; position:absolute; width:100%; background:white; z-index:50; border-radius:8px; margin-top:5px; box-shadow:0 4px 10px rgba(0,0,0,0.1); max-height:200px; overflow-y:auto;"></ul>
                </div>

                <div id="map">
                    <div class="info-float-box" id="pointCounter">📍 포인트: 0개</div>
                    <div class="info-float-box" id="routeInfo" style="top: 60px;"><span style="color:#6B7280">연장:</span> <strong id="routeDistance" style="color:var(--primary)">0</strong>m</div>
                    
                    <div class="map-overlay-controls">
                        <div class="control-group-row">
                            <button type="button" class="map-btn active" id="modePoint" onclick="setMode('point')">📍 위치/면적</button>
                            <button type="button" class="map-btn" id="modeRoute" onclick="setMode('route')">🛣️ 경로</button>
                        </div>
                        <div class="control-group-row" id="routeSubGroup" style="display:none;">
                            <button type="button" class="map-btn mode-active" id="btnAutoRoute" onclick="setRouteType('auto')">🚗 자동</button>
                            <button type="button" class="map-btn" id="btnManualRoute" onclick="setRouteType('manual')">✏️ 수동</button>
                        </div>
                        <div class="control-group-row">
                            <button type="button" class="map-btn active" id="btnRoadmap" onclick="setMapType('roadmap')">일반</button>
                            <button type="button" class="map-btn" id="btnSkyview" onclick="setMapType('skyview')">위성</button>
                            <button type="button" class="map-btn" id="btnVWorld" onclick="toggleVWorld(this)">🔲 지적도</button>
                        </div>
                    </div>

                    <div class="guide-box" id="routeGuide" style="display:none;">
                        <span class="guide-step" id="routeStep">1️⃣ 시점</span>을 지도에서 클릭하세요
                    </div>
                </div>

                <div class="form-grid" style="margin-top: 15px;">
                    <div class="form-group">
                        <label class="form-label">위도</label>
                        <input type="number" name="latitude" id="latitude" step="0.00000001" readonly class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">경도</label>
                        <input type="number" name="longitude" id="longitude" step="0.00000001" readonly class="form-control">
                    </div>
                </div>
                
                <div class="form-actions" style="justify-content: flex-start; margin-top: 0;">
                    <button type="button" class="btn btn-secondary" id="btnUndo" onclick="undoLastPoint()" style="display:none;">↩️ 실행 취소</button>
                    <button type="button" class="btn btn-secondary" onclick="clearMap()" style="color: #EF4444; border-color: #FECACA; background: #FEF2F2;">🗑️ 초기화</button>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h3 class="section-title">관리 정보</h3>
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">조성년도</label><input type="number" name="establishment_year" class="form-control" placeholder="예: 2024"></div>
                    <div class="form-group"><label class="form-label">관리기관</label><input type="text" name="management_agency" class="form-control"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">관리자</label><input type="text" name="manager_name" class="form-control"></div>
                    <div class="form-group"><label class="form-label">연락처</label><input type="text" name="manager_contact" class="form-control"></div>
                </div>
                <div class="form-group"><label class="form-label">비고</label><textarea name="description" class="form-control"></textarea></div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h3 class="section-title">사진 및 영상</h3>
                <div class="form-group">
                    <label class="form-label">일반 사진 (다중 선택)</label>
                    <div class="file-upload-wrapper" onclick="document.getElementById('images').click()">
                        <div style="font-size: 2rem; margin-bottom: 10px;">📷</div>
                        <span style="color: #6B7280;">여기를 클릭하여 사진을 선택하세요</span>
                    </div>
                    <input type="file" name="images[]" id="images" accept="image/*" multiple onchange="previewImages(this,'image-previews')" style="display:none;">
                    <div id="image-previews" class="preview-container"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">360 VR 사진</label>
                    <input type="file" name="vr_photo" class="form-control" accept="image/*" onchange="previewImages(this,'vr-preview')">
                    <div id="vr-preview" class="preview-container"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">영상 URL</label>
                    <input type="url" name="video_url" class="form-control" placeholder="https://youtube.com/...">
                </div>
            </div>
        </div>

        <div class="form-actions" style="margin-bottom: 60px;">
            <button type="submit" class="btn btn-primary" style="padding: 15px 40px; font-size: 1.1rem;">💾 장소 저장하기</button>
        </div>
    </form>
</div>

<script src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?= KAKAO_MAP_API_KEY ?>&libraries=services"></script>
<script src="<?= BASE_URL ?>/assets/js/location_map_common.js"></script>

<script>
// add.php 전용 변수
var routeType = 'auto'; 

// 지도 초기화 및 UI 제어
$(function() {
    // 공통 JS에 있는 함수 호출
    if(typeof initializeMap === 'function') {
        initializeMap(<?= DEFAULT_LAT ?>, <?= DEFAULT_LNG ?>, 3);
        toggleFields();
    } else {
        console.error("location_map_common.js가 로드되지 않았습니다.");
    }
});
</script>

<?php include '../../includes/footer.php'; ?>