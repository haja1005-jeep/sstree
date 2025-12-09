<?php
/**
 * 장소 추가 (Smart Tree Map - Location Add)
 * - V-World 지적도 & WFS 연동
 * - 지역 선택 & 장소 검색 기능 통합
 * - 정밀 좌표 및 주소/장소명 자동 완성
 */

require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '장소 추가';

$database = new Database();
$db = $database->getConnection();

// 허용 확장자
$allowed_ext_array = array_map('trim', array_map('strtolower', explode(',', ALLOWED_EXTENSIONS)));

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
        if (empty($category_id)) throw new Exception('카테고리를 선택해주세요.');
        
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

        // 공간 데이터 업데이트
        if ($latitude && $longitude) {
            $sql_point = "UPDATE locations SET geom_point = PointFromText(:point_wkt) WHERE location_id = :id";
            $stmt_pt = $db->prepare($sql_point);
            $point_wkt = "POINT($longitude $latitude)";
            $stmt_pt->bindParam(':point_wkt', $point_wkt);
            $stmt_pt->bindParam(':id', $location_id);
            $stmt_pt->execute();
        }
        if ($geom_path) {
            $sql_poly = "UPDATE locations SET geom_polygon = PolyFromText(:poly_wkt) WHERE location_id = :id";
            $stmt_poly = $db->prepare($sql_poly);
            $stmt_poly->bindParam(':poly_wkt', $geom_path);
            $stmt_poly->bindParam(':id', $location_id);
            $stmt_poly->execute();
        }

        // 파일 업로드
        $upload_error = false;
        $error_details = '';
        $upload_dir = UPLOAD_PATH;
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $sort_order = 1;
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if (empty($tmp_name) || $_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) continue;
                $file_name = $_FILES['images']['name'][$key];
                $file_size = $_FILES['images']['size'][$key];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (!in_array($file_ext, $allowed_ext_array) || $file_size > MAX_FILE_SIZE) {
                    $upload_error = true; $error_details .= "파일 오류: {$file_name}<br>";
                } else {
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

        if ($upload_error) throw new Exception("파일 업로드 경고:<br>" . $error_details);
        
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
/* 기본 폼 스타일 */
.form-container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); max-width: 1200px; margin: 0 auto; }
.form-section { margin-bottom: 30px; padding-bottom: 30px; border-bottom: 2px solid #f3f4f6; }
.form-section:last-child { border-bottom: none; }
.form-section-title { font-size: 18px; font-weight: 600; color: #1f2937; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #374151; }
.form-group label .required { color: #ef4444; margin-left: 4px; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; box-sizing: border-box; }
.form-group input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
.form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px; padding-top: 20px; border-top: 2px solid #f3f4f6; }
.dynamic-field { display: none; }
.dynamic-field.active { display: block; }

/* 지도 및 컨트롤 스타일 */
#map { width: 100%; height: 500px; border-radius: 8px; position: relative; overflow: hidden; border: 1px solid #ddd; }
.map-controls { position: absolute; top: 10px; right: 10px; z-index: 20; display: flex; gap: 5px; }
.map-btn { background: white; border: 1px solid #999; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600; color: #333; box-shadow: 0 2px 4px rgba(0,0,0,0.2); transition: all 0.2s; }
.map-btn:hover { background: #f8f9fa; }
.map-btn.active { background: #4a90e2; color: white; border-color: #357abd; }

.map-loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 30; background: rgba(0,0,0,0.7); color: white; padding: 10px 20px; border-radius: 5px; display: none; font-size: 14px; }
.custom-overlay { position: absolute; background: rgba(0, 0, 0, 0.85); color: white; padding: 6px 10px; border-radius: 4px; font-size: 12px; white-space: nowrap; pointer-events: none; transform: translate(-50%, -100%); margin-top: -10px; z-index: 1001; }
.custom-overlay::after { content: ''; position: absolute; bottom: -5px; left: 50%; transform: translateX(-50%); border-top: 5px solid rgba(0,0,0,0.85); border-left: 5px solid transparent; border-right: 5px solid transparent; }

.gps-info { background: #f0fdf4; border: 1px solid #86efac; border-radius: 5px; padding: 12px; margin-top: 10px; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
.gps-info strong { color: #166534; }

/* 검색 결과 리스트 스타일 */
#placesList { list-style: none; padding: 0; margin: 5px 0 0 0; border: 1px solid #ddd; max-height: 200px; overflow-y: auto; background: white; display: none; border-radius: 5px; }
#placesList li { padding: 10px; border-bottom: 1px solid #eee; cursor: pointer; font-size: 13px; }
#placesList li:hover { background: #f0f9ff; }
#placesList li strong { display: block; color: #333; margin-bottom: 3px; }
#placesList li span { color: #666; font-size: 12px; }

.image-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.image-preview-item { width: 120px; height: 120px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
.image-preview-item img { width: 100%; height: 100%; object-fit: cover; }
</style>

<div class="page-header">
    <div><h2>➕ 장소 추가</h2><p>지도를 클릭하여 정확한 위치와 정보를 입력하세요.</p></div>
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
                    <select name="category_id" id="category_id" required onchange="toggleFields()">
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
                    <input type="text" name="location_name" id="location_name" required placeholder="지도에서 위치를 선택하거나 직접 입력">
                </div>
                <div class="form-group">
                    <label>주소</label>
                    <input type="text" name="address" id="address" placeholder="자동 입력">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>장소 유형 <span class="required">*</span></label>
                    <select name="location_type" id="location_type" required onchange="toggleFields()">
                        <option value="urban_forest">도시숲</option>
                        <option value="street_tree">가로수</option>
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
            <div class="form-group"><label>면적 (㎡)</label><input type="number" name="area" step="0.01" placeholder="예: 1162.00"></div>
        </div>
        
        <div class="form-section dynamic-field" id="road-section">
            <div class="form-section-title">🛣️ 도로 정보 (가로수)</div>
            <div class="form-grid">
                <div class="form-group"><label>도로명</label><input type="text" name="road_name"></div>
                <div class="form-group"><label>도로 종류</label><input type="text" name="road_type"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>시점</label><input type="text" name="section_start"></div>
                <div class="form-group"><label>종점</label><input type="text" name="section_end"></div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>연장 (m)</label><input type="number" name="length" step="0.01"></div>
                <div class="form-group"><label>폭 (m)</label><input type="number" name="width" step="0.01"></div>
            </div>
        </div>
 
  <!-- Map  -->
        <div class="form-section">
            <div class="form-section-title">📍 위치 정보 (GPS & 지적도)</div>
            
            <div class="form-group">
                <label>🔍 장소/주소 검색</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="keyword" placeholder="장소명 또는 주소를 입력하세요 (예: 비금면 사무소)" 
                           onkeypress="if(event.key==='Enter'){event.preventDefault(); searchPlaces();}">
                    <button type="button" class="btn btn-secondary" onclick="searchPlaces()" style="width: 100px;">검색</button>
                </div>
                <ul id="placesList"></ul>
            </div>

            <div class="form-group">
                <div id="map">
                    <div class="map-loading" id="mapLoading">데이터 불러오는 중...</div>
                    <div class="map-controls">
                        <button type="button" class="map-btn active" id="btnRoadmap" onclick="setMapType('roadmap', this)">일반지도</button>
                        <button type="button" class="map-btn" id="btnSkyview" onclick="setMapType('skyview', this)">스카이뷰</button>
                        <span style="width:1px; background:#ccc; margin:0 5px;"></span>
                        <button type="button" class="map-btn" id="btnVWorld" onclick="toggleVWorldWFS(this)">🔲 지적도 (V-World)</button>
                    </div>
                </div>
                <div class="gps-info" id="gps-info" style="display:none;">
                    <span>📌 <strong>선택 좌표:</strong> <span id="selected-coords">-</span></span>
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

<script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>&libraries=services,drawing"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/kakao_map.js"></script>

<script>
// ==========================================
// 0. 전역 설정 및 초기화
// ==========================================
const VWORLD_KEY = 'C6710AA7-5F23-3194-A1BF-A1519130E773'; 
const MAX_ZOOM_LEVEL = 5;

// 신안군 14개 읍/면사무소 실제 주소 매핑
const regionOffices = {
    '압해읍': '전남 신안군 압해읍 압해로 876-22',
    '지도읍': '전남 신안군 지도읍 읍내길 67-5',
    '증도면': '전남 신안군 증도면 문준경길 188',
    '임자면': '전남 신안군 임자면 임자로 87-63',
    '자은면': '전남 신안군 자은면 구영1길 8',
    '비금면': '전남 신안군 비금면 읍동길 29-6',
    '도초면': '전남 신안군 도초면 서남문로 1515-22',
    '흑산면': '전남 신안군 흑산면 진마을길 11',
    '하의면': '전남 신안군 하의면 곰실길 12',
    '신의면': '전남 신안군 신의면 신의로 661',
    '장산면': '전남 신안군 장산면 장산중앙길 2',
    '안좌면': '전남 신안군 안좌면 중부로 872',
    '팔금면': '전남 신안군 팔금면 삼층석탑길 161',
    '암태면': '전남 신안군 암태면 장단고길 7-53'
};

var mapContainer = document.getElementById('map'),
    mapOption = { center: new kakao.maps.LatLng(<?php echo DEFAULT_LAT; ?>, <?php echo DEFAULT_LNG; ?>), level: 3 };
var map = new kakao.maps.Map(mapContainer, mapOption);
var geocoder = new kakao.maps.services.Geocoder();
var ps = new kakao.maps.services.Places(); // 장소 검색 객체

// 변수
var currentMarker = null;
var useVWorld = false;
var vworldPolygons = [];
var hoverOverlay = null;
var selectedPolygon = null;
var isVWorldLoading = false;
var searchMarkers = [];

// ==========================================
// 1. 검색 기능 (통합)
// ==========================================
function searchPlaces() {
    var keyword = document.getElementById('keyword').value;
    if (!keyword.trim()) { alert('키워드를 입력해주세요!'); return; }

    ps.keywordSearch(keyword, placesSearchCB);
}

function placesSearchCB(data, status, pagination) {
    var listEl = document.getElementById('placesList');
    if (status === kakao.maps.services.Status.OK) {
        displayPlaces(data);
    } else if (status === kakao.maps.services.Status.ZERO_RESULT) {
        alert('검색 결과가 존재하지 않습니다.');
        listEl.style.display = 'none';
    } else if (status === kakao.maps.services.Status.ERROR) {
        alert('검색 중 오류가 발생했습니다.');
        listEl.style.display = 'none';
    }
}

// [기존 코드 수정] displayPlaces 함수 내부
function displayPlaces(places) {
    var listEl = document.getElementById('placesList');
    listEl.innerHTML = '';
    listEl.style.display = 'block';

    for (var i = 0; i < places.length; i++) {
        var itemEl = document.createElement('li');
        var title = places[i].place_name;
        var address = places[i].address_name;
        
        itemEl.innerHTML = '<strong>' + title + '</strong><span>' + address + '</span>';
        
        (function(place) {
            itemEl.onclick = function() {
                var coords = new kakao.maps.LatLng(place.y, place.x);
                
                // 1. 지도 이동 및 기본 마커 표시
                map.setCenter(coords);
                map.setLevel(3); // 지적도가 잘 보이도록 줌 레벨 조정
                
                updateLocationInfo(coords);
                fillAddressFields(place.address_name, place.road_address_name);
                document.getElementById('location_name').value = place.place_name;
                
                // 2. 검색 목록 숨김
                listEl.style.display = 'none';
                
                // [⭐️추가됨] 3. 해당 좌표의 지적도(경계)를 찾아 붉은색으로 그리기
                searchPolygonByCoord(place.x, place.y); 
            };
        })(places[i]);

        listEl.appendChild(itemEl);
    }
}

// ==========================================
// 2. 지도 컨트롤 & 지역 이동
// ==========================================

function setMapType(maptype, btn) {
    var roadmapBtn = document.getElementById('btnRoadmap');
    var skyviewBtn = document.getElementById('btnSkyview');
    
    if (maptype === 'roadmap') {
        map.setMapTypeId(kakao.maps.MapTypeId.ROADMAP);
        roadmapBtn.classList.add('active');
        skyviewBtn.classList.remove('active');
    } else {
        map.setMapTypeId(kakao.maps.MapTypeId.HYBRID);
        roadmapBtn.classList.remove('active');
        skyviewBtn.classList.add('active');
    }
}

function moveToRegion(selectObj) {
    const selectedOption = selectObj.options[selectObj.selectedIndex];
    let regionName = selectedOption.getAttribute('data-name'); 
    
    let targetAddress = null;
    for (const [key, addr] of Object.entries(regionOffices)) {
        if (regionName.includes(key)) {
            targetAddress = addr;
            break;
        }
    }

    if (targetAddress) {
        geocoder.addressSearch(targetAddress, function(result, status) {
            if (status === kakao.maps.services.Status.OK) {
                var coords = new kakao.maps.LatLng(result[0].y, result[0].x);
                map.panTo(coords);
                map.setLevel(3);
                if (useVWorld) debouncedGetData();
            }
        });
    }
}

// ==========================================
// 3. V-World 지적도 (WFS)
// ==========================================

function toggleVWorldWFS(btn) {
    useVWorld = !useVWorld;
    
    if (useVWorld) {
        btn.classList.add('active');
        if(map.getLevel() > 3) map.setLevel(3);
        getVWorldDataAll();
        kakao.maps.event.addListener(map, 'dragend', debouncedGetData);
        kakao.maps.event.addListener(map, 'zoom_changed', debouncedGetData);
        alert("🔲 지적도 모드 ON\n지도를 클릭하면 해당 구역(필지)이 선택됩니다.");
    } else {
        btn.classList.remove('active');
        removeVWorldPolygons();
        kakao.maps.event.removeListener(map, 'dragend', debouncedGetData);
        kakao.maps.event.removeListener(map, 'zoom_changed', debouncedGetData);
    }
}

function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}
const debouncedGetData = debounce(getVWorldDataAll, 800);

function getVWorldDataAll() {
    if (!useVWorld || map.getLevel() > MAX_ZOOM_LEVEL) {
        removeVWorldPolygons(); return;
    }
    if (isVWorldLoading) return;
    
    isVWorldLoading = true;
    $('#mapLoading').fadeIn();

    const bounds = map.getBounds();
    const sw = bounds.getSouthWest();
    const ne = bounds.getNorthEast();
    const bbox = `${sw.getLng()},${sw.getLat()},${ne.getLng()},${ne.getLat()}`;

    const params = {
        service: 'WFS', version: '2.0.0', request: 'GetFeature',
        typeName: 'lp_pa_cbnd_bubun', srsName: 'EPSG:4326',
        bbox: bbox, output: 'text/javascript', format_options: 'callback:parseVWorldAll',
        key: VWORLD_KEY
    };

    const url = "https://api.vworld.kr/req/wfs?" + $.param(params);
    $('#vworld-script').remove();
    const script = document.createElement('script');
    script.src = url;
    script.id = 'vworld-script';
    script.onerror = function() { isVWorldLoading = false; $('#mapLoading').hide(); };
    document.head.appendChild(script);
}

window.parseVWorldAll = function(data) {
    isVWorldLoading = false;
    $('#mapLoading').hide();
    removeVWorldPolygons();

    let features = data.features || (data.response ? data.response.result.featureCollection.features : []);
    if (!features || features.length === 0) return;

    features.forEach(drawVWorldPolygon);
};

function drawVWorldPolygon(feature) {
    var geometry = feature.geometry;
    var props = feature.properties;
    if (!geometry) return;

    var rawPath = (geometry.type === 'Polygon') ? geometry.coordinates[0] : geometry.coordinates[0][0];
    var path = rawPath.map(pt => new kakao.maps.LatLng(pt[1], pt[0]));

    var polygon = new kakao.maps.Polygon({
        map: map, path: path,
        strokeWeight: 1, strokeColor: '#004c80', strokeOpacity: 0.5,
        fillColor: '#fff', fillOpacity: 0.01 
    });

    kakao.maps.event.addListener(polygon, 'mouseover', function(mouseEvent) {
        polygon.setOptions({ fillColor: '#09f', fillOpacity: 0.3 });
        const jibun = props.jibun || props.addr || '지번정보없음';
        const content = `<div class="custom-overlay">${jibun}</div>`;
        if(hoverOverlay) hoverOverlay.setMap(null);
        hoverOverlay = new kakao.maps.CustomOverlay({ position: mouseEvent.latLng, content: content, yAnchor: 1 });
        hoverOverlay.setMap(map);
    });

    kakao.maps.event.addListener(polygon, 'mouseout', function() {
        polygon.setOptions({ fillColor: '#fff', fillOpacity: 0.01 });
        if(hoverOverlay) { hoverOverlay.setMap(null); hoverOverlay = null; }
    });

    kakao.maps.event.addListener(polygon, 'click', function(mouseEvent) {
        if (selectedPolygon) selectedPolygon.setMap(null);
        selectedPolygon = new kakao.maps.Polygon({
            map: map, path: path,
            strokeWeight: 2, strokeColor: '#ff0000', strokeOpacity: 0.8,
            fillColor: '#ff0000', fillOpacity: 0.2
        });

        var latlng = mouseEvent.latLng;
        // 지적도 클릭 시엔 장소명에 지번주소를 넣어줌
        updateLocationInfo(latlng);
        fillAddressFields(props.addr || props.jibun, props.addr || props.jibun);
        document.getElementById('location_name').value = props.jibun || props.addr; 
        
        savePolygonWKT(rawPath);
    });

    vworldPolygons.push(polygon);
}

function removeVWorldPolygons() {
    vworldPolygons.forEach(p => p.setMap(null));
    vworldPolygons = [];
    if(hoverOverlay) hoverOverlay.setMap(null);
    if(selectedPolygon) selectedPolygon.setMap(null);
}

// ==========================================
// 4. 지도 클릭 및 좌표 처리
// ==========================================

// [수정] 지도 클릭 이벤트
// [수정] 지도 클릭 이벤트
// [수정] 지도 클릭 이벤트 (건물명/장소명 우선 적용)
kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
    if (useVWorld) return; 
    
    var latlng = mouseEvent.latLng;
    
    // 1. 마커 및 좌표 업데이트
    updateLocationInfo(latlng);
    
    // 2. 좌표로 주소 및 건물명 검색
    geocoder.coord2Address(latlng.getLng(), latlng.getLat(), function(result, status) {
        if (status === kakao.maps.services.Status.OK) {
            var roadObj = result[0].road_address; // 도로명 주소 객체
            var jibunObj = result[0].address;     // 지번 주소 객체

            var fullAddress = roadObj ? roadObj.address_name : jibunObj.address_name;
            var buildingName = roadObj ? roadObj.building_name : ''; // 건물명 추출

            // 주소 필드 채우기
            fillAddressFields(jibunObj.address_name, fullAddress);

            // [⭐️핵심 수정] 장소명 자동 입력 로직
            // 1순위: 건물명 (예: 도초면사무소, OO아파트)
            // 2순위: 주소 (건물명이 없을 경우)
            if (buildingName && buildingName.trim() !== "") {
                document.getElementById('location_name').value = buildingName;
            } else {
                document.getElementById('location_name').value = fullAddress;
            }
        }
    });

    // 3. 지적도(폴리곤) 그리기
    if (typeof searchPolygonByCoord === 'function') {
        searchPolygonByCoord(latlng.getLng(), latlng.getLat());
    }
});

// [수정] 마커 생성 및 정보 업데이트 함수
function updateLocationInfo(latlng) {
    const lat = latlng.getLat();
    const lng = latlng.getLng();

    // 기존 마커 제거 및 새 마커 생성
    if (currentMarker) currentMarker.setMap(null);
    currentMarker = new kakao.maps.Marker({ position: latlng, map: map });

    // [⭐️추가됨] 마커를 클릭했을 때도 지적도 영역 다시 표시하기
    kakao.maps.event.addListener(currentMarker, 'click', function() {
        searchPolygonByCoord(lng, lat);
    });

    // 좌표 정보 입력
    document.getElementById('latitude').value = lat.toFixed(8);
    document.getElementById('longitude').value = lng.toFixed(8);
    document.getElementById('gps-info').style.display = 'flex';
    document.getElementById('selected-coords').textContent = `위도 ${lat.toFixed(6)}, 경도 ${lng.toFixed(6)}`;
}

function fillAddressFields(jibunAddr, roadAddr) {
    const addrInput = document.getElementById('address');
    addrInput.value = roadAddr || jibunAddr;
}

function savePolygonWKT(coords) {
    let wkt = "POLYGON((" + coords.map(pt => pt[0] + " " + pt[1]).join(", ") + "))";
    document.getElementById('geom_path').value = wkt;
    console.log("구역 데이터 저장됨:", wkt);
}

function clearMapSelection() {
    if (currentMarker) currentMarker.setMap(null);
    if (selectedPolygon) selectedPolygon.setMap(null);
    document.getElementById('latitude').value = '';
    document.getElementById('longitude').value = '';
    document.getElementById('geom_path').value = '';
    document.getElementById('selected-coords').textContent = '-';
    document.getElementById('gps-info').style.display = 'none';
}

function toggleFields() {
    const type = document.getElementById('location_type').value;
    document.getElementById('area-section').classList.remove('active');
    document.getElementById('road-section').classList.remove('active');
    
    if (type === 'street_tree') document.getElementById('road-section').classList.add('active');
    else document.getElementById('area-section').classList.add('active');
}
document.addEventListener('DOMContentLoaded', toggleFields);

function previewImages(input) {
    const preview = document.getElementById('image-previews');
    preview.innerHTML = '';
    if (input.files) Array.from(input.files).forEach((file,i) => {
        const reader = new FileReader();
        reader.onload = e => {
            const div = document.createElement('div');
            div.className = 'image-preview-item';
            div.innerHTML = `<img src="${e.target.result}">`;
            preview.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
}
function previewVRImage(input) {
    const preview = document.getElementById('vr-preview');
    preview.innerHTML = '';
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const div = document.createElement('div');
            div.className = 'image-preview-item';
            div.innerHTML = `<img src="${e.target.result}">`;
            preview.appendChild(div);
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// [⭐️신규 추가] 좌표로 지적도 경계 찾기 및 그리기
function searchPolygonByCoord(lng, lat) {
    // 로딩 표시
    $('#mapLoading').show();

    // 좌표 주변에 작은 영역(BBOX)을 설정하여 데이터 요청 (검색 속도 최적화)
    var margin = 0.001; // 약 100m 반경
    var bbox = `${parseFloat(lng) - margin},${parseFloat(lat) - margin},${parseFloat(lng) + margin},${parseFloat(lat) + margin}`;

    const params = {
        service: 'WFS', version: '2.0.0', request: 'GetFeature',
        typeName: 'lp_pa_cbnd_bubun', // 연속지적도
        srsName: 'EPSG:4326', // 경위도 좌표계
        bbox: bbox, 
        output: 'text/javascript', 
        format_options: 'callback:parseSelectedPolygon', // 콜백 함수 지정
        key: VWORLD_KEY
    };

    const url = "https://api.vworld.kr/req/wfs?" + $.param(params);
    
    // 기존 스크립트 제거 후 새로 요청
    $('#vworld-search-script').remove();
    const script = document.createElement('script');
    script.src = url;
    script.id = 'vworld-search-script';
    script.onerror = function() { $('#mapLoading').hide(); };
    document.head.appendChild(script);
}

// [⭐️신규 추가] V-World 응답 처리 (선택된 폴리곤 그리기)
window.parseSelectedPolygon = function(data) {
    $('#mapLoading').hide();
    
    // 기존 선택된 폴리곤이 있다면 제거
    if (selectedPolygon) selectedPolygon.setMap(null);

    let features = data.features || (data.response ? data.response.result.featureCollection.features : []);
    if (!features || features.length === 0) return;

    // 현재 선택된 마커의 좌표
    var centerLat = parseFloat(document.getElementById('latitude').value);
    var centerLng = parseFloat(document.getElementById('longitude').value);

    var targetFeature = null;

    // 가져온 여러 필지 중, 내 좌표(마커)가 실제로 포함된 필지 찾기
    for (var i = 0; i < features.length; i++) {
        var feature = features[i];
        var geometry = feature.geometry;
        var coords = (geometry.type === 'Polygon') ? geometry.coordinates[0] : geometry.coordinates[0][0];
        
        // 다각형 내부에 점이 있는지 확인 (알고리즘)
        if (containsLocation(centerLng, centerLat, coords)) {
            targetFeature = feature;
            break;
        }
    }

    // 만약 정확히 포함된 걸 못 찾았다면, 첫 번째 것을 사용 (예외 처리)
    if (!targetFeature && features.length > 0) targetFeature = features[0];

    if (targetFeature) {
        var geometry = targetFeature.geometry;
        var rawPath = (geometry.type === 'Polygon') ? geometry.coordinates[0] : geometry.coordinates[0][0];
        var path = rawPath.map(pt => new kakao.maps.LatLng(pt[1], pt[0]));

        // 붉은색 테두리와 면으로 그리기 (사진과 동일한 스타일)
        selectedPolygon = new kakao.maps.Polygon({
            map: map,
            path: path,
            strokeWeight: 3,
            strokeColor: '#ff0000', // 빨간 테두리
            strokeOpacity: 1,
            fillColor: '#ff0000',   // 빨간 채우기
            fillOpacity: 0.3        // 반투명
        });

        // DB 저장을 위해 WKT 데이터 업데이트
        savePolygonWKT(rawPath);
        
        // 지번 주소가 있다면 업데이트 (선택 사항)
        var props = targetFeature.properties;
        var jibun = props.jibun || props.addr;
        console.log("선택된 지적도:", jibun);
    }
};

// [⭐️신규 추가] 점이 다각형 안에 있는지 판별하는 함수 (Ray-Casting Algorithm)
function containsLocation(x, y, polygon) {
    var inside = false;
    for (var i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        var xi = polygon[i][0], yi = polygon[i][1];
        var xj = polygon[j][0], yj = polygon[j][1];

        var intersect = ((yi > y) != (yj > y)) &&
            (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
        if (intersect) inside = !inside;
    }
    return inside;
}
</script>

<?php include '../../includes/footer.php'; ?>
