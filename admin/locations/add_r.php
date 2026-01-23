<?php
/**
 * 장소 추가 (Smart Tree Map - Location Add)
 * - V-World 지적도(면) & 카카오 길찾기 기반 경로 그리기(선) 통합
 * - 가로수 선택 시 시점/종점 클릭으로 도로 따라 자동 경로 생성
 * - 면적(㎡) 및 연장(m) 자동 계산
 */

require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '장소 추가';

$database = new Database();
$db = $database->getConnection();

// 허용 확장자 확인
$allowed_ext_array = defined('ALLOWED_EXTENSIONS') ? array_map('trim', array_map('strtolower', explode(',', ALLOWED_EXTENSIONS))) : ['jpg','jpeg','png','gif'];

if (!defined('MAX_FILE_SIZE')) {
    define('MAX_FILE_SIZE', 10 * 1024 * 1024);
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', '../../uploads/photos/');
}

// --- 이미지 처리 함수 ---
function autoOrientImage($image_resource, $source_path) {
    if (!function_exists('exif_read_data')) return $image_resource;
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

function processAndSaveImage($source_path, $destination_path, $max_width = 1920, $quality = 85) {
    ini_set('memory_limit', '512M');
    set_time_limit(300);
    try {
        $info = getimagesize($source_path);
        if (!$info) return false;
        $mime = $info['mime'];
        $width = $info[0];
        $height = $info[1];
        
        if ($width <= $max_width) { $new_width = $width; $new_height = $height; } 
        else { $new_width = $max_width; $new_height = (int)(($height / $width) * $new_width); }
        
        $destination_image = imagecreatetruecolor((int)$new_width, (int)$new_height);
        $source_image = null;

        switch ($mime) {
            case 'image/jpeg': $source_image = imagecreatefromjpeg($source_path); $source_image = autoOrientImage($source_image, $source_path); break;
            case 'image/png': $source_image = imagecreatefrompng($source_path); imagealphablending($destination_image, false); imagesavealpha($destination_image, true); break;
            case 'image/gif': $source_image = imagecreatefromgif($source_path); break;
            default: imagedestroy($destination_image); return move_uploaded_file($source_path, $destination_path);
        }

        if ($source_image === null) return false;
        imagecopyresampled($destination_image, $source_image, 0, 0, 0, 0, (int)$new_width, (int)$new_height, $width, $height);
        
        $success = false;
        switch ($mime) {
            case 'image/jpeg': $success = imagejpeg($destination_image, $destination_path, $quality); break;
            case 'image/png': $success = imagepng($destination_image, $destination_path, 8); break;
            case 'image/gif': $success = imagegif($destination_image, $destination_path); break;
        }
        
        imagedestroy($source_image); imagedestroy($destination_image);
        return $success;
    } catch (Exception $e) { return false; }
}

// --- 폼 제출 처리 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $saved_files = [];
    try {
        $region_id = isset($_POST['region_id']) ? (int)$_POST['region_id'] : 0;
        $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
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

        if (empty($location_name)) throw new Exception('장소명을 입력해주세요.');
        if (empty($region_id)) throw new Exception('지역을 선택해주세요.');
        
        $db->beginTransaction();

        $query = "INSERT INTO locations (
                    region_id, category_id, location_name, address, area,
                    road_name, road_type, section_start, section_end, length, width,
                    location_type, latitude, longitude,
                    establishment_year, management_agency,
                    manager_name, manager_contact, description, video_url, created_at
                  ) VALUES (
                    :region_id, :category_id, :location_name, :address, :area,
                    :road_name, :road_type, :section_start, :section_end, :length, :width,
                    :location_type, :latitude, :longitude,
                    :establishment_year, :management_agency,
                    :manager_name, :manager_contact, :description, :video_url, NOW()
                  )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':region_id', $region_id);
        $stmt->bindParam(':category_id', $category_id);
        $stmt->bindParam(':location_name', $location_name);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':area', $area);
        $stmt->bindParam(':road_name', $road_name);
        $stmt->bindParam(':road_type', $road_type);
        $stmt->bindParam(':section_start', $section_start);
        $stmt->bindParam(':section_end', $section_end);
        $stmt->bindParam(':length', $length);
        $stmt->bindParam(':width', $width);
        $stmt->bindParam(':location_type', $location_type);
        $stmt->bindParam(':latitude', $latitude);
        $stmt->bindParam(':longitude', $longitude);
        $stmt->bindParam(':establishment_year', $establishment_year);
        $stmt->bindParam(':management_agency', $management_agency);
        $stmt->bindParam(':manager_name', $manager_name);
        $stmt->bindParam(':manager_contact', $manager_contact);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':video_url', $video_url);
        $stmt->execute();
        $location_id = $db->lastInsertId();

        // [공간 데이터 저장]
        if ($latitude && $longitude) {
            $sql_point = "UPDATE locations SET geom_point = PointFromText(:point_wkt) WHERE location_id = :id";
            $stmt_pt = $db->prepare($sql_point);
            $point_wkt = "POINT($longitude $latitude)";
            $stmt_pt->bindParam(':point_wkt', $point_wkt);
            $stmt_pt->bindParam(':id', $location_id);
            $stmt_pt->execute();
        }

        if ($geom_path) {
            $sql_geom = "UPDATE locations SET geom_polygon = GeomFromText(:geom_wkt) WHERE location_id = :id";
            $stmt_geom = $db->prepare($sql_geom);
            $stmt_geom->bindParam(':geom_wkt', $geom_path);
            $stmt_geom->bindParam(':id', $location_id);
            $stmt_geom->execute();
        }

        // 파일 업로드 처리
        $upload_dir = UPLOAD_PATH;
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $sort_order = 1;
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if (empty($tmp_name) || $_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) continue;
                $file_name = $_FILES['images']['name'][$key];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (in_array($file_ext, $allowed_ext_array)) {
                    $new_file_name = 'location_' . $location_id . '_' . uniqid() . '.' . $file_ext;
                    $file_path = $upload_dir . $new_file_name;
                    if (processAndSaveImage($tmp_name, $file_path, 1920, 85)) {
                        $saved_files[] = $file_path;
                        $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, sort_order, uploaded_by, uploaded_at) VALUES (:location_id, :file_path, :file_name, :file_size, 'image', :sort_order, :uploaded_by, NOW())";
                        $photo_stmt = $db->prepare($photo_query);
                        $relative_path = 'uploads/photos/' . $new_file_name;
                        $fsize = filesize($file_path);
                        $photo_stmt->execute([':location_id'=>$location_id, ':file_path'=>$relative_path, ':file_name'=>$file_name, ':file_size'=>$fsize, ':sort_order'=>$sort_order, ':uploaded_by'=>$_SESSION['user_id']]);
                        $sort_order++;
                    }
                }
            }
        }
        
        if (isset($_FILES['vr_photo']) && !empty($_FILES['vr_photo']['tmp_name']) && $_FILES['vr_photo']['error'] === UPLOAD_ERR_OK) {
            $file_name = $_FILES['vr_photo']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $new_file_name = 'location_vr_' . $location_id . '_' . uniqid() . '.' . $file_ext;
            $file_path = $upload_dir . $new_file_name;
            if (processAndSaveImage($_FILES['vr_photo']['tmp_name'], $file_path, 4096, 90)) {
                $saved_files[] = $file_path;
                $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, uploaded_by, uploaded_at) VALUES (:location_id, :file_path, :file_name, :file_size, 'vr360', :uploaded_by, NOW())";
                $photo_stmt = $db->prepare($photo_query);
                $relative_path = 'uploads/photos/' . $new_file_name;
                $fsize = filesize($file_path);
                $photo_stmt->execute([':location_id'=>$location_id, ':file_path'=>$relative_path, ':file_name'=>$file_name, ':file_size'=>$fsize, ':uploaded_by'=>$_SESSION['user_id']]);
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
/* 기본 스타일 */
.form-container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); max-width: 1200px; margin: 0 auto; }
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

/* 지도 컨트롤 스타일 */
#map { width: 100%; height: 500px; border-radius: 8px; position: relative; overflow: hidden; border: 1px solid #ddd; }
.map-controls { position: absolute; top: 10px; right: 10px; z-index: 20; display: flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end; width: 90%; }
.map-btn { background: white; border: 1px solid #999; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; color: #333; box-shadow: 0 2px 4px rgba(0,0,0,0.2); margin-bottom:5px; }
.map-btn:hover { background: #f8f9fa; }
.map-btn.active { background: #4a90e2; color: white; border-color: #357abd; }

/* 모드 전환 버튼 그룹 */
.mode-group { display:flex; gap:0; margin-right:10px; }
.mode-btn { background: #fff; border: 1px solid #999; padding: 6px 10px; cursor: pointer; font-size: 12px; font-weight: bold; color: #333; }
.mode-btn:first-child { border-radius: 4px 0 0 4px; border-right: none; }
.mode-btn:last-child { border-radius: 0 4px 4px 0; }
.mode-btn.selected { background: #004c80; color: white; border-color: #004c80; z-index: 1; }

.map-loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 30; background: rgba(0,0,0,0.7); color: white; padding: 10px 20px; border-radius: 5px; display: none; font-size: 14px; }
.gps-info { background: #f0fdf4; border: 1px solid #86efac; border-radius: 5px; padding: 12px; margin-top: 10px; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }

/* 경로 모드 안내 */
.route-guide { 
    position: absolute; 
    bottom: 10px; 
    left: 50%; 
    transform: translateX(-50%); 
    z-index: 25; 
    background: rgba(0,0,0,0.85); 
    color: white; 
    padding: 10px 20px; 
    border-radius: 8px; 
    font-size: 13px;
    display: none;
    text-align: center;
}
.route-guide.active { display: block; }
.route-guide .step { color: #fbbf24; font-weight: bold; }

/* 검색 리스트 */
#placesList { list-style: none; padding: 0; margin: 5px 0 0 0; border: 1px solid #ddd; max-height: 200px; overflow-y: auto; background: white; display: none; border-radius: 5px; z-index: 1000; position: relative;}
#placesList li { padding: 10px; border-bottom: 1px solid #eee; cursor: pointer; font-size: 13px; }
#placesList li:hover { background: #f0f9ff; }

/* 이미지 미리보기 스타일 */
.image-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.image-preview-item { width: 120px; height: 120px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
.image-preview-item img { width: 100%; height: 100%; object-fit: cover; }

/* 토스트 메시지 */
.toast-msg {
    position: fixed;
    bottom: 100px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,0.85);
    color: white;
    padding: 15px 25px;
    border-radius: 8px;
    z-index: 9999;
    font-size: 14px;
    text-align: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    max-width: 90%;
}
.toast-msg.success { background: #059669; }
.toast-msg.error { background: #dc2626; }
</style>

<div class="page-header">
    <div><h2>➕ 장소 추가</h2><p>지도를 클릭하여 정확한 위치(또는 경로)와 정보를 입력하세요.</p></div>
    <a href="index.php" class="btn btn-secondary">← 목록으로</a>
</div>

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<div class="form-container">
    <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="geom_path" id="geom_path">

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
                                    <?php echo (isset($_POST['region_id']) && $_POST['region_id'] == $region['region_id']) ? 'selected' : ''; ?>>
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
                            <option value="<?php echo $category['category_id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
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
                <div class="form-group"><label>도로명</label><input type="text" name="road_name" id="road_name" placeholder="자동 입력"></div>
                <div class="form-group"><label>도로 종류</label><input type="text" name="road_type"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>시점</label><input type="text" name="section_start" id="section_start" placeholder="자동 입력"></div>
                <div class="form-group"><label>종점</label><input type="text" name="section_end" id="section_end" placeholder="자동 입력"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>연장 (m)</label><input type="number" name="length" id="length" step="0.01" placeholder="경로 설정 시 자동 계산"></div>
                <div class="form-group"><label>폭 (m)</label><input type="number" name="width" step="0.01"></div>
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
                        <span style="width:1px; background:#ccc; margin:0 5px;"></span>
                        <button type="button" class="map-btn active" id="btnRoadmap" onclick="setMapType('roadmap', this)">일반지도</button>
                        <button type="button" class="map-btn" id="btnSkyview" onclick="setMapType('skyview', this)">위성지도</button>
                        <button type="button" class="map-btn" id="btnVWorld" onclick="toggleVWorldWFS(this)">🔲 지적도</button>
                    </div>
                    <div class="route-guide" id="routeGuide">
                        <span class="step" id="routeStep">1️⃣ 시점</span>을 클릭하세요
                    </div>
                </div>
                <div class="gps-info" id="gps-info" style="display:none;">
                    <span>📌 <strong>좌표:</strong> <span id="selected-coords">-</span></span>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearMapSelection()">초기화</button>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>위도 (Latitude)</label>
                    <input type="number" name="latitude" id="latitude" step="0.00000001" readonly style="background:#f9fafb;">
                </div>
                <div class="form-group">
                    <label>경도 (Longitude)</label>
                    <input type="number" name="longitude" id="longitude" step="0.00000001" readonly style="background:#f9fafb;">
                </div>
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
                <input type="file" name="images[]" accept="image/*" multiple onchange="previewImages(this)">
                <div id="image-previews" class="image-preview"></div>
            </div>
            <div class="form-group">
                <label>360 VR 사진</label>
                <input type="file" name="vr_photo" accept="image/*" onchange="previewVRImage(this)">
                <div id="vr-preview" class="image-preview"></div>
            </div>
            <div class="form-group"><label>영상 URL</label><input type="url" name="video_url"></div>
        </div>

        <div class="form-actions">
            <a href="index.php" class="btn btn-secondary">취소</a>
            <button type="submit" class="btn btn-primary">💾 저장</button>
        </div>
    </form>
</div>

<script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>&libraries=clusterer,services,drawing"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/kakao_map.js"></script>

<script>
// ==========================================
// 0. 전역 설정 & 변수
// ==========================================
const VWORLD_KEY = 'C6710AA7-5F23-3194-A1BF-A1519130E773'; 
const KAKAO_REST_API_KEY = '9bca2b309d1524cada7d463c408e256e'; // 카카오 REST API 키

// 행정구역 매핑
const regionOffices = {
    '압해읍': '전남 신안군 압해읍 압해로 876-22', '지도읍': '전남 신안군 지도읍 읍내길 67-5',
    '증도면': '전남 신안군 증도면 문준경길 188', '임자면': '전남 신안군 임자면 임자로 87-63',
    '자은면': '전남 신안군 자은면 구영1길 8', '비금면': '전남 신안군 비금면 읍동길 29-6',
    '도초면': '전남 신안군 도초면 서남문로 1515-22', '흑산면': '전남 신안군 흑산면 진마을길 11',
    '하의면': '전남 신안군 하의면 곰실길 12', '신의면': '전남 신안군 신의면 신의로 661',
    '장산면': '전남 신안군 장산면 장산중앙길 2', '안좌면': '전남 신안군 안좌면 중부로 872',
    '팔금면': '전남 신안군 팔금면 삼층석탑길 161', '암태면': '전남 신안군 암태면 장단고길 7-53'
};

var mapContainer = document.getElementById('map'),
    mapOption = { center: new kakao.maps.LatLng(<?php echo DEFAULT_LAT; ?>, <?php echo DEFAULT_LNG; ?>), level: 3 };
var map = new kakao.maps.Map(mapContainer, mapOption);
var geocoder = new kakao.maps.services.Geocoder();
var ps = new kakao.maps.services.Places();

// --- 상태 변수 ---
var currentMode = 'point'; // 'point'(기본) or 'route'(경로)
var currentMarker = null;
var useVWorld = false;
var vworldPolygons = [];
var selectedPolygon = null;

// 경로 그리기 관련 변수 (카카오 길찾기 기반)
var routeStartCoords = null;
var routeEndCoords = null;
var routeStartMarker = null;
var routeEndMarker = null;
var routePolyline = null;
var routeMode = 'start'; // 'start' 또는 'end'

// ==========================================
// 1. 모드 전환 & 초기화 함수
// ==========================================
function setMode(mode) {
    clearMapSelection();
    currentMode = mode;

    document.getElementById('modePoint').classList.remove('selected');
    document.getElementById('modeRoute').classList.remove('selected');
    document.getElementById('routeGuide').classList.remove('active');
    
    if (mode === 'point') {
        document.getElementById('modePoint').classList.add('selected');
        document.getElementById('btnVWorld').style.display = 'inline-block';
        showToast("📍 위치 선택 모드\n지도를 클릭하여 위치와 지적도를 선택하세요.", 'info');
    } else {
        document.getElementById('modeRoute').classList.add('selected');
        document.getElementById('btnVWorld').style.display = 'none';
        document.getElementById('routeGuide').classList.add('active');
        if(useVWorld) toggleVWorldWFS(document.getElementById('btnVWorld'));
        routeMode = 'start';
        updateRouteGuide();
        showToast("🛣️ 가로수 경로 모드\n\n1️⃣ 시점 클릭 → 2️⃣ 종점 클릭\n도로를 따라 자동으로 경로가 그려집니다.", 'info');
    }
}

function updateRouteGuide() {
    var guideEl = document.getElementById('routeStep');
    if (routeMode === 'start') {
        guideEl.textContent = '1️⃣ 시점';
    } else {
        guideEl.textContent = '2️⃣ 종점';
    }
}

function onLocationTypeChange() {
    toggleFields();
    var type = document.getElementById('location_type').value;
    if (type === 'street_tree') {
        setMode('route');
    } else {
        setMode('point');
    }
}

// ==========================================
// 2. 통합 지도 이벤트
// ==========================================
kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
    var latlng = mouseEvent.latLng;

    // [CASE 1] 경로 그리기 모드 - 카카오 길찾기 활용
    if (currentMode === 'route') {
        if (routeMode === 'start') {
            setRouteStart(latlng);
        } else if (routeMode === 'end') {
            setRouteEnd(latlng);
        }
    } 
    // [CASE 2] 포인트/면적 모드
    else {
        if (useVWorld) return;
        updateLocationInfo(latlng);
        searchAddressAndBuilding(latlng);
        searchPolygonByCoord(latlng.getLng(), latlng.getLat());
    }
});

// ==========================================
// 3. 시점 설정
// ==========================================
function setRouteStart(latlng) {
    // 기존 시점 마커 제거
    if (routeStartMarker) routeStartMarker.setMap(null);
    if (routePolyline) routePolyline.setMap(null);
    
    routeStartCoords = { lat: latlng.getLat(), lng: latlng.getLng() };
    
    // 시점 마커 (빨간색)
    routeStartMarker = new kakao.maps.Marker({
        map: map,
        position: latlng,
        image: new kakao.maps.MarkerImage(
            'https://t1.daumcdn.net/localimg/localimages/07/mapapidoc/red_b.png',
            new kakao.maps.Size(50, 45),
            { offset: new kakao.maps.Point(15, 43) }
        )
    });
    
    // 대표 좌표로 설정
    document.getElementById('latitude').value = latlng.getLat().toFixed(8);
    document.getElementById('longitude').value = latlng.getLng().toFixed(8);
    document.getElementById('gps-info').style.display = 'flex';
    document.getElementById('selected-coords').textContent = `위도 ${latlng.getLat().toFixed(6)}, 경도 ${latlng.getLng().toFixed(6)}`;
    
    // 시점 주소 검색
    geocoder.coord2Address(latlng.getLng(), latlng.getLat(), function(result, status) {
        if (status === kakao.maps.services.Status.OK) {
            var roadObj = result[0].road_address;
            var jibunObj = result[0].address;
            var addr = roadObj ? roadObj.address_name : jibunObj.address_name;
            var buildingName = roadObj ? roadObj.building_name : '';
            
            document.getElementById('address').value = addr;
            document.getElementById('section_start').value = addr;
            
            // 장소명 설정
            if (buildingName && buildingName.trim() !== "") {
                document.getElementById('location_name').value = buildingName;
            } else {
                document.getElementById('location_name').value = addr;
            }
        }
    });
    
    routeMode = 'end';
    updateRouteGuide();
    showToast('✅ 시점이 설정되었습니다.\n이제 종점을 클릭하세요.', 'success');
}

// ==========================================
// 4. 종점 설정 및 경로 찾기
// ==========================================
function setRouteEnd(latlng) {
    // 기존 종점 마커 제거
    if (routeEndMarker) routeEndMarker.setMap(null);
    
    routeEndCoords = { lat: latlng.getLat(), lng: latlng.getLng() };
    
    // 종점 마커 (파란색)
    routeEndMarker = new kakao.maps.Marker({
        map: map,
        position: latlng,
        image: new kakao.maps.MarkerImage(
            'https://t1.daumcdn.net/localimg/localimages/07/mapapidoc/blue_b.png',
            new kakao.maps.Size(50, 45),
            { offset: new kakao.maps.Point(15, 43) }
        )
    });
    
    // 종점 주소 검색
    geocoder.coord2Address(latlng.getLng(), latlng.getLat(), function(result, status) {
        if (status === kakao.maps.services.Status.OK) {
            var addr = result[0].road_address ? result[0].road_address.address_name : result[0].address.address_name;
            document.getElementById('section_end').value = addr;
        }
    });
    
    // 카카오 길찾기 API 호출
    findRouteByKakao();
}

// ==========================================
// 5. 카카오 길찾기 API 호출
// ==========================================
function findRouteByKakao() {
    if (!routeStartCoords || !routeEndCoords) {
        showToast('시점과 종점을 모두 설정해주세요.', 'error');
        return;
    }
    
    $('#mapLoading').show();
    
    $.ajax({
        url: 'https://apis-navi.kakaomobility.com/v1/directions',
        type: 'GET',
        headers: {
            'Authorization': `KakaoAK ${KAKAO_REST_API_KEY}`
        },
        data: {
            origin: `${routeStartCoords.lng},${routeStartCoords.lat}`,
            destination: `${routeEndCoords.lng},${routeEndCoords.lat}`,
            priority: 'RECOMMEND'
        },
        success: function(data) {
            $('#mapLoading').hide();
            drawRoutePolyline(data);
        },
        error: function(xhr, status, error) {
            $('#mapLoading').hide();
            console.error("길찾기 오류:", error);
            showToast('⚠️ 길찾기 API 오류\n직선 경로로 표시합니다.', 'error');
            drawStraightLine();
        }
    });
}

// ==========================================
// 6. 경로 폴리라인 그리기
// ==========================================
function drawRoutePolyline(data) {
    if (routePolyline) routePolyline.setMap(null);
    
    if (!data.routes || data.routes.length === 0) {
        showToast('경로를 찾을 수 없습니다.\n직선으로 표시합니다.', 'error');
        drawStraightLine();
        return;
    }
    
    const route = data.routes[0];
    const path = [];
    
    // 경로 좌표 추출
    route.sections.forEach(section => {
        section.roads.forEach(road => {
            road.vertexes.forEach((vertex, index) => {
                if (index % 2 === 0) {
                    const lng = vertex;
                    const lat = road.vertexes[index + 1];
                    path.push(new kakao.maps.LatLng(lat, lng));
                }
            });
        });
    });
    
    // 폴리라인 그리기
    routePolyline = new kakao.maps.Polyline({
        map: map,
        path: path,
        strokeWeight: 6,
        strokeColor: '#db4040',
        strokeOpacity: 0.9,
        strokeStyle: 'solid'
    });
    
    // 거리 계산 (미터 단위)
    const distance = route.summary.distance;
    document.getElementById('length').value = distance;
    
    // WKT 데이터 생성 (LINESTRING)
    const wkt = "LINESTRING(" + path.map(p => p.getLng() + " " + p.getLat()).join(", ") + ")";
    document.getElementById('geom_path').value = wkt;
    
    // 도로명 자동 입력 (첫 번째 도로명)
    if (route.sections[0] && route.sections[0].roads[0]) {
        const roadName = route.sections[0].roads[0].name;
        if (roadName) {
            document.getElementById('road_name').value = roadName;
        }
    }
    
    // 경로 안내 숨김
    document.getElementById('routeGuide').classList.remove('active');
    
    showToast(`✅ 경로가 설정되었습니다!\n연장: ${distance.toLocaleString()}m`, 'success');
    
    // 다시 시점부터 설정 가능하도록
    routeMode = 'start';
}

// ==========================================
// 7. 직선 경로 (API 실패 시 대체)
// ==========================================
function drawStraightLine() {
    if (routePolyline) routePolyline.setMap(null);
    
    const path = [
        new kakao.maps.LatLng(routeStartCoords.lat, routeStartCoords.lng),
        new kakao.maps.LatLng(routeEndCoords.lat, routeEndCoords.lng)
    ];
    
    routePolyline = new kakao.maps.Polyline({
        map: map,
        path: path,
        strokeWeight: 5,
        strokeColor: '#db4040',
        strokeOpacity: 0.9,
        strokeStyle: 'dashed'
    });
    
    const distance = Math.round(routePolyline.getLength());
    document.getElementById('length').value = distance;
    
    const wkt = "LINESTRING(" + path.map(p => p.getLng() + " " + p.getLat()).join(", ") + ")";
    document.getElementById('geom_path').value = wkt;
    
    document.getElementById('routeGuide').classList.remove('active');
    
    showToast(`⚠️ 직선 경로로 설정됨\n연장: ${distance.toLocaleString()}m`, 'info');
    routeMode = 'start';
}

// ==========================================
// 8. 토스트 메시지
// ==========================================
function showToast(message, type = 'info') {
    $('.toast-msg').remove();
    
    var typeClass = '';
    if (type === 'success') typeClass = ' success';
    else if (type === 'error') typeClass = ' error';
    
    const toast = $(`<div class="toast-msg${typeClass}">${message.replace(/\n/g, '<br>')}</div>`);
    $('body').append(toast);
    
    setTimeout(() => toast.fadeOut(300, function() { $(this).remove(); }), 3500);
}

// ==========================================
// 9. 공통/보조 함수들
// ==========================================
function updateLocationInfo(latlng) {
    const lat = latlng.getLat();
    const lng = latlng.getLng();

    if (currentMarker) currentMarker.setMap(null);
    currentMarker = new kakao.maps.Marker({ position: latlng, map: map });
    
    if (currentMode === 'point') {
        kakao.maps.event.addListener(currentMarker, 'click', function() {
            searchPolygonByCoord(lng, lat);
        });
    }

    document.getElementById('latitude').value = lat.toFixed(8);
    document.getElementById('longitude').value = lng.toFixed(8);
    document.getElementById('gps-info').style.display = 'flex';
    document.getElementById('selected-coords').textContent = `위도 ${lat.toFixed(6)}, 경도 ${lng.toFixed(6)}`;
}

function searchAddressAndBuilding(latlng) {
    geocoder.coord2Address(latlng.getLng(), latlng.getLat(), function(result, status) {
        if (status === kakao.maps.services.Status.OK) {
            var roadObj = result[0].road_address;
            var jibunObj = result[0].address;
            var fullAddress = roadObj ? roadObj.address_name : jibunObj.address_name;
            var buildingName = roadObj ? roadObj.building_name : '';

            fillAddressFields(jibunObj.address_name, fullAddress);

            if (buildingName && buildingName.trim() !== "") {
                document.getElementById('location_name').value = buildingName;
            } else {
                document.getElementById('location_name').value = fullAddress;
            }
        }
    });
}

function clearMapSelection() {
    // 마커 제거
    if (currentMarker) currentMarker.setMap(null);
    if (selectedPolygon) selectedPolygon.setMap(null);
    if (routeStartMarker) routeStartMarker.setMap(null);
    if (routeEndMarker) routeEndMarker.setMap(null);
    if (routePolyline) routePolyline.setMap(null);
    
    // 변수 초기화
    currentMarker = null;
    selectedPolygon = null;
    routeStartMarker = null;
    routeEndMarker = null;
    routePolyline = null;
    routeStartCoords = null;
    routeEndCoords = null;
    routeMode = 'start';
    
    // 폼 필드 초기화
    document.getElementById('latitude').value = '';
    document.getElementById('longitude').value = '';
    document.getElementById('geom_path').value = '';
    document.getElementById('length').value = '';
    document.getElementById('area').value = '';
    document.getElementById('section_start').value = '';
    document.getElementById('section_end').value = '';
    document.getElementById('road_name').value = '';
    document.getElementById('selected-coords').textContent = '-';
    document.getElementById('gps-info').style.display = 'none';
    
    // 경로 안내 업데이트
    if (currentMode === 'route') {
        document.getElementById('routeGuide').classList.add('active');
        updateRouteGuide();
    }
}

function fillAddressFields(jibunAddr, roadAddr) {
    document.getElementById('address').value = roadAddr || jibunAddr;
}

function toggleFields() {
    const type = document.getElementById('location_type').value;
    document.getElementById('area-section').classList.remove('active');
    document.getElementById('road-section').classList.remove('active');
    if (type === 'street_tree') document.getElementById('road-section').classList.add('active');
    else document.getElementById('area-section').classList.add('active');
}

document.addEventListener('DOMContentLoaded', function() {
    toggleFields();
    if(document.getElementById('location_type').value === 'street_tree') setMode('route');
});

// --- 검색 및 V-World 함수 ---
function searchPlaces() {
    var keyword = document.getElementById('keyword').value;
    if (!keyword.trim()) { alert('키워드를 입력해주세요!'); return; }
    ps.keywordSearch(keyword, placesSearchCB);
}

function placesSearchCB(data, status, pagination) {
    var listEl = document.getElementById('placesList');
    if (status === kakao.maps.services.Status.OK) {
        displayPlaces(data);
    } else {
        alert('검색 결과가 없습니다.'); listEl.style.display = 'none';
    }
}

function displayPlaces(places) {
    var listEl = document.getElementById('placesList');
    listEl.innerHTML = ''; listEl.style.display = 'block';
    for (var i = 0; i < places.length; i++) {
        var itemEl = document.createElement('li');
        itemEl.innerHTML = '<strong>' + places[i].place_name + '</strong><span>' + places[i].address_name + '</span>';
        (function(place) {
            itemEl.onclick = function() {
                var coords = new kakao.maps.LatLng(place.y, place.x);
                map.setCenter(coords); map.setLevel(3);
                listEl.style.display = 'none';
                
                if(currentMode === 'route') {
                    showToast('검색 위치로 이동했습니다.\n시점을 클릭하세요.', 'info');
                } else {
                    updateLocationInfo(coords);
                    searchAddressAndBuilding(coords);
                    searchPolygonByCoord(place.x, place.y);
                }
            };
        })(places[i]);
        listEl.appendChild(itemEl);
    }
}

// [지적도 검색]
function searchPolygonByCoord(lng, lat) {
    if(currentMode === 'route') return;
    
    $('#mapLoading').show();

    var margin = 0.0005; 
    var bbox = `${parseFloat(lng) - margin},${parseFloat(lat) - margin},${parseFloat(lng) + margin},${parseFloat(lat) + margin}`;

    const params = {
        service: 'WFS', 
        version: '1.1.0',
        request: 'GetFeature',
        typeName: 'lp_pa_cbnd_bubun',
        srsName: 'EPSG:4326',
        bbox: bbox, 
        output: 'text/javascript', 
        format_options: 'callback:parseSelectedPolygon', 
        key: VWORLD_KEY
    };

    const url = "https://api.vworld.kr/req/wfs?" + $.param(params);
    
    $('#vworld-search-script').remove();
    const script = document.createElement('script');
    script.src = url;
    script.id = 'vworld-search-script';
    script.onerror = function() { $('#mapLoading').hide(); };
    document.head.appendChild(script);
}

window.parseSelectedPolygon = function(data) {
    $('#mapLoading').hide();
    
    if (selectedPolygon) selectedPolygon.setMap(null);

    let features = data.features || (data.response ? data.response.result.featureCollection.features : []);
    if (!features || features.length === 0) return;

    var centerLat = parseFloat(document.getElementById('latitude').value);
    var centerLng = parseFloat(document.getElementById('longitude').value);
    var targetFeature = null;

    for (var i = 0; i < features.length; i++) {
        var feature = features[i];
        var geometry = feature.geometry;
        var coords = (geometry.type === 'Polygon') ? geometry.coordinates[0] : geometry.coordinates[0][0];
        
        if (containsLocation(centerLng, centerLat, coords)) {
            targetFeature = feature;
            break;
        }
    }

    if (!targetFeature && features.length > 0) targetFeature = features[0];

    if (targetFeature) {
        var geometry = targetFeature.geometry;
        var rawPath = (geometry.type === 'Polygon') ? geometry.coordinates[0] : geometry.coordinates[0][0];
        var path = rawPath.map(pt => new kakao.maps.LatLng(pt[1], pt[0]));

        selectedPolygon = new kakao.maps.Polygon({
            map: map,
            path: path,
            strokeWeight: 3, strokeColor: '#ff0000', strokeOpacity: 1,
            fillColor: '#ff0000', fillOpacity: 0.3
        });

        savePolygonWKT(rawPath); 
        
        var areaSize = calculatePolygonArea(path);
        document.querySelector('input[name="area"]').value = areaSize.toFixed(2);
    }
};

function containsLocation(x, y, polygon) {
    var inside = false;
    for (var i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        var xi = polygon[i][0], yi = polygon[i][1];
        var xj = polygon[j][0], yj = polygon[j][1];
        var intersect = ((yi > y) != (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
        if (intersect) inside = !inside;
    }
    return inside;
}

function calculatePolygonArea(path) {
    if (path.length < 3) return 0;
    var area = 0; var R = 6378137;
    for (var i = 0; i < path.length; i++) {
        var j = (i + 1) % path.length;
        var p1 = path[i], p2 = path[j];
        var x1 = p1.getLng() * Math.PI / 180; var y1 = p1.getLat() * Math.PI / 180;
        var x2 = p2.getLng() * Math.PI / 180; var y2 = p2.getLat() * Math.PI / 180;
        area += (x2 - x1) * (2 + Math.sin(y1) + Math.sin(y2));
    }
    return Math.abs(area * R * R / 2.0);
}

// 지적도 토글 (전체 보기)
function toggleVWorldWFS(btn) {
    if(currentMode === 'route') return;
    useVWorld = !useVWorld;
    if (useVWorld) {
        btn.classList.add('active');
        if(map.getLevel() > 3) map.setLevel(3);
        getVWorldDataAll();
        kakao.maps.event.addListener(map, 'dragend', debouncedGetData);
        kakao.maps.event.addListener(map, 'zoom_changed', debouncedGetData);
    } else {
        btn.classList.remove('active');
        removeVWorldPolygons();
        kakao.maps.event.removeListener(map, 'dragend', debouncedGetData);
        kakao.maps.event.removeListener(map, 'zoom_changed', debouncedGetData);
    }
}

function debounce(func, wait) { let timeout; return function(...args) { clearTimeout(timeout); timeout = setTimeout(() => func.apply(this, args), wait); }; }
const debouncedGetData = debounce(getVWorldDataAll, 800);

function getVWorldDataAll() {
    if (!useVWorld || map.getLevel() > 5) { removeVWorldPolygons(); return; }
    $('#mapLoading').show();
    const bounds = map.getBounds();
    const bbox = `${bounds.getSouthWest().getLng()},${bounds.getSouthWest().getLat()},${bounds.getNorthEast().getLng()},${bounds.getNorthEast().getLat()}`;
    $.ajax({
        url: "https://api.vworld.kr/req/wfs", dataType: "jsonp",
        data: { service: 'WFS', version: '2.0.0', request: 'GetFeature', typeName: 'lp_pa_cbnd_bubun', srsName: 'EPSG:4326', bbox: bbox, output: 'text/javascript', format_options: 'callback:parseVWorldAll', key: VWORLD_KEY }
    });
}

window.parseVWorldAll = function(data) {
    $('#mapLoading').hide(); removeVWorldPolygons();
    let features = data.features || []; features.forEach(drawVWorldPolygon);
};

function drawVWorldPolygon(feature) {
    var geometry = feature.geometry; var props = feature.properties;
    if (!geometry) return;
    var rawPath = (geometry.type === 'Polygon') ? geometry.coordinates[0] : geometry.coordinates[0][0];
    var path = rawPath.map(pt => new kakao.maps.LatLng(pt[1], pt[0]));
    var polygon = new kakao.maps.Polygon({ map: map, path: path, strokeWeight: 1, strokeColor: '#004c80', strokeOpacity: 0.5, fillColor: '#fff', fillOpacity: 0.01 });
    kakao.maps.event.addListener(polygon, 'click', function(mouseEvent) {
        if(currentMode === 'route') return;
        if (selectedPolygon) selectedPolygon.setMap(null);
        selectedPolygon = new kakao.maps.Polygon({ map: map, path: path, strokeWeight: 2, strokeColor: '#ff0000', strokeOpacity: 0.8, fillColor: '#ff0000', fillOpacity: 0.2 });
        updateLocationInfo(mouseEvent.latLng);
        searchAddressAndBuilding(mouseEvent.latLng);
        savePolygonWKT(rawPath);
        document.querySelector('input[name="area"]').value = calculatePolygonArea(path).toFixed(2);
    });
    vworldPolygons.push(polygon);
}

function removeVWorldPolygons() { vworldPolygons.forEach(p => p.setMap(null)); vworldPolygons = []; if(selectedPolygon) selectedPolygon.setMap(null); }

function savePolygonWKT(coords) {
    let wkt = "POLYGON((" + coords.map(pt => pt[0] + " " + pt[1]).join(", ") + "))";
    document.getElementById('geom_path').value = wkt;
}

function previewImages(input) {
    const preview = document.getElementById('image-previews'); preview.innerHTML = '';
    if (input.files) Array.from(input.files).forEach((file) => {
        const reader = new FileReader(); reader.onload = e => { const div = document.createElement('div'); div.className = 'image-preview-item'; div.innerHTML = `<img src="${e.target.result}">`; preview.appendChild(div); }; reader.readAsDataURL(file);
    });
}

function previewVRImage(input) {
    const preview = document.getElementById('vr-preview'); preview.innerHTML = '';
    if (input.files && input.files[0]) { const reader = new FileReader(); reader.onload = e => { const div = document.createElement('div'); div.className = 'image-preview-item'; div.innerHTML = `<img src="${e.target.result}">`; preview.appendChild(div); }; reader.readAsDataURL(input.files[0]); }
}

function setMapType(maptype, btn) {
    var roadmapBtn = document.getElementById('btnRoadmap'); var skyviewBtn = document.getElementById('btnSkyview');
    if (maptype === 'roadmap') { map.setMapTypeId(kakao.maps.MapTypeId.ROADMAP); roadmapBtn.classList.add('active'); skyviewBtn.classList.remove('active'); }
    else { map.setMapTypeId(kakao.maps.MapTypeId.HYBRID); roadmapBtn.classList.remove('active'); skyviewBtn.classList.add('active'); }
}

function moveToRegion(selectObj) {
    const regionName = selectObj.options[selectObj.selectedIndex].getAttribute('data-name');
    let targetAddress = null;
    for (const [key, addr] of Object.entries(regionOffices)) { if (regionName.includes(key)) { targetAddress = addr; break; } }
    if (targetAddress) {
        geocoder.addressSearch(targetAddress, function(result, status) {
            if (status === kakao.maps.services.Status.OK) { map.panTo(new kakao.maps.LatLng(result[0].y, result[0].x)); map.setLevel(3); }
        });
    }
}
</script>

<?php include '../../includes/footer.php'; ?>