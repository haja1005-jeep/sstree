<?php
/**
 * 장소 수정 (보안 및 안정성 업그레이드)
 */

// 1. 설정 및 인증 파일 먼저 로드
require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';
checkAuth(); // 로그인 확인

$page_title = '장소 수정';
$database = new Database();
$db = $database->getConnection();
$error = '';
$success = '';

// 2. 수정할 ID 가져오기
$location_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($location_id === 0) {
    redirect('/admin/locations/list.php');
}

// [업그레이드] ALLOWED_EXTENSIONS를 더 안전하게 배열로 변환
$allowed_ext_array = array_map('trim', array_map('strtolower', explode(',', ALLOWED_EXTENSIONS)));


/**
 * 3. [업그레이드] 자동 회전 보정 기능이 포함된 리사이징 함수
 */
function autoOrientImage($image_resource, $source_path) {
    if (!function_exists('exif_read_data')) {
        return $image_resource; // EXIF 함수가 없으면 원본 반환
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

function processAndSaveImage($source_path, $destination_path, $max_width = 1920, $quality = 85) {
    // [업그레이드] 대용량 이미지 처리를 위한 메모리 및 시간 확보
    ini_set('memory_limit', '512M');
    set_time_limit(300); // 5분

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
                // [업그레이드] EXIF 데이터 기반 자동 회전
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


// 4. 폼 제출(POST) 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ... (폼 데이터 가져오기) ...
    $region_id = (int)$_POST['region_id'];
    $category_id = (int)$_POST['category_id'];
    $location_name = sanitize($_POST['location_name']);
    $address = sanitize($_POST['address']);
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $area = !empty($_POST['area']) ? (float)$_POST['area'] : null;
    $length = !empty($_POST['length']) ? (float)$_POST['length'] : null;
    $width = !empty($_POST['width']) ? (float)$_POST['width'] : null;
    $establishment_year = !empty($_POST['establishment_year']) ? (int)$_POST['establishment_year'] : null;
    $management_agency = sanitize($_POST['management_agency']);
    $video_url = sanitize($_POST['video_url']);
    $description = sanitize($_POST['description']);
    
    // [업그레이드] 롤백 시 삭제할 파일 목록
    $saved_files = []; 
    
    if (empty($location_name) || $region_id == 0 || $category_id == 0) {
        $error = '필수 항목(지역, 카테고리, 장소명)을 모두 입력해주세요.';
    } else {
        try {
            $db->beginTransaction();
            
            // 장소 정보 업데이트
            $query = "UPDATE locations SET 
                        region_id = :region_id, category_id = :category_id, location_name = :location_name, 
                        address = :address, latitude = :latitude, longitude = :longitude, 
                        area = :area, length = :length, width = :width, 
                        establishment_year = :establishment_year, management_agency = :management_agency, 
                        video_url = :video_url, description = :description
                      WHERE location_id = :location_id";
            $stmt = $db->prepare($query);
            // ... (bindParam 생략) ...
            $stmt->bindParam(':region_id', $region_id);
            $stmt->bindParam(':category_id', $category_id);
            $stmt->bindParam(':location_name', $location_name);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':latitude', $latitude);
            $stmt->bindParam(':longitude', $longitude);
            $stmt->bindParam(':area', $area);
            $stmt->bindParam(':length', $length);
            $stmt->bindParam(':width', $width);
            $stmt->bindParam(':establishment_year', $establishment_year);
            $stmt->bindParam(':management_agency', $management_agency);
            $stmt->bindParam(':video_url', $video_url);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':location_id', $location_id);
            $stmt->execute();
            
            // --- 파일 업로드 (오류 알림 추가) ---
            $upload_error = false;
            $max_mb = MAX_FILE_SIZE / 1024 / 1024; // MB 단위
            $allowed_ext_str = implode(', ', $allowed_ext_array);
            
            // 일반 이미지 업로드 (add.php와 동일)
            if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
                $upload_dir = UPLOAD_PATH;
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $sort_order = 1; // (기존 사진 순서와 이어지게 하려면 MAX(sort_order) 조회 필요)
                foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                    if (empty($tmp_name) || $_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) continue;
                    
                    $file_name = $_FILES['images']['name'][$key];
                    $file_size = $_FILES['images']['size'][$key];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    
                    if (!in_array($file_ext, $allowed_ext_array)) {
                        $error .= "{$file_name}: 허용되지 않는 파일 형식입니다. ({$allowed_ext_str}만 가능)<br>";
                        $upload_error = true;
                    } elseif ($file_size > MAX_FILE_SIZE) {
                        $error .= "{$file_name}: 파일 용량이 너무 큽니다. ({$max_mb}MB 이하만 가능)<br>";
                        $upload_error = true;
                    } else {
                        $new_file_name = 'location_' . $location_id . '_' . uniqid() . '.' . $file_ext;
                        $file_path = $upload_dir . $new_file_name;

                        if (processAndSaveImage($tmp_name, $file_path, 1920, 85)) {
                            $saved_files[] = $file_path; // 롤백용
                            
                            $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, sort_order, uploaded_by, uploaded_at) VALUES (:location_id, :file_path, :file_name, :file_size, 'image', :sort_order, :uploaded_by, NOW())";
                            $photo_stmt = $db->prepare($photo_query);
                            $relative_path = 'uploads/photos/' . $new_file_name;
                            $file_size_after_compress = filesize($file_path);
                            
                            $photo_stmt->bindParam(':location_id', $location_id);
                            $photo_stmt->bindParam(':file_path', $relative_path);
                            $photo_stmt->bindParam(':file_name', $file_name);
                            $photo_stmt->bindParam(':file_size', $file_size_after_compress);
                            $photo_stmt->bindParam(':sort_order', $sort_order);
                            $photo_stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                            $photo_stmt->execute();
                            $sort_order++;
                        } else {
                            $error .= "{$file_name}: 파일 저장(압축) 중 오류가 발생했습니다.<br>";
                            $upload_error = true;
                        }
                    }
                }
            }
            
            // 360 VR 사진 업로드 (add.php와 동일)
            if (isset($_FILES['vr_photo']) && !empty($_FILES['vr_photo']['tmp_name'])) {
                if ($_FILES['vr_photo']['error'] === UPLOAD_ERR_OK) {
                    $file_name = $_FILES['vr_photo']['name'];
                    $file_size = $_FILES['vr_photo']['size'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                    if (!in_array($file_ext, $allowed_ext_array)) {
                        $error .= "{$file_name} (VR): 허용되지 않는 파일 형식입니다.<br>";
                        $upload_error = true;
                    } elseif ($file_size > MAX_FILE_SIZE) {
                        $error .= "{$file_name} (VR): 파일 용량이 너무 큽니다. ({$max_mb}MB 이하)<br>";
                        $upload_error = true;
                    } else {
                        $new_file_name = 'location_vr_' . $location_id . '_' . uniqid() . '.' . $file_ext;
                        $file_path = $upload_dir . $new_file_name;

                        if (processAndSaveImage($_FILES['vr_photo']['tmp_name'], $file_path, 4096, 90)) {
                            $saved_files[] = $file_path; // 롤백용
                            
                            // (참고: VR 사진 교체를 원하면, 여기서 기존 'vr360' 타입을 삭제하는 로직이 필요)
                            $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, uploaded_by, uploaded_at) VALUES (:location_id, :file_path, :file_name, :file_size, 'vr360', :uploaded_by, NOW())";
                            $photo_stmt = $db->prepare($photo_query);
                            $relative_path = 'uploads/photos/' . $new_file_name;
                            $file_size_after_compress = filesize($file_path);

                            $photo_stmt->bindParam(':location_id', $location_id);
                            $photo_stmt->bindParam(':file_path', $relative_path);
                            $photo_stmt->bindParam(':file_name', $file_name);
                            $photo_stmt->bindParam(':file_size', $file_size_after_compress);
                            $photo_stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                            $photo_stmt->execute();
                        } else {
                            $error .= "{$file_name} (VR): 파일 저장(압축) 중 오류가 발생했습니다.<br>";
                            $upload_error = true;
                        }
                    }
                }
            }
            
            if ($upload_error) {
                throw new Exception("파일 업로드 실패:<br>" . $error);
            }
            
            logActivity($_SESSION['user_id'], 'update', 'location', $location_id, "장소 수정: {$location_name}");
            
            $db->commit();
            
            redirect('/admin/locations/view.php?id=' . $location_id . '&message=' . urlencode('장소가 수정되었습니다.'));
            
        } catch (Exception $e) {
            $db->rollBack(); 
            
            // [업그레이드] 고아 파일(Orphan File) 삭제
            foreach ($saved_files as $file_to_delete) {
                if (file_exists($file_to_delete)) {
                    @unlink($file_to_delete);
                }
            }
            
            $error = $e->getMessage();
        }
    }
}

// 6. (GET 요청이거나 POST 실패 시) 기존 데이터 조회
try {
    // ... (이전 코드와 동일: 장소, 사진, 지역, 카테고리 목록 조회) ...
    $query = "SELECT * FROM locations WHERE location_id = :location_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':location_id', $location_id);
    $stmt->execute();
    $location = $stmt->fetch();
    if (!$location) redirect('/admin/locations/list.php');

    $photos_query = "SELECT * FROM location_photos WHERE location_id = :location_id ORDER BY photo_type, sort_order";
    $photos_stmt = $db->prepare($photos_query);
    $photos_stmt->bindParam(':location_id', $location_id);
    $photos_stmt->execute();
    $photos = $photos_stmt->fetchAll();

    $regions_query = "SELECT * FROM regions ORDER BY region_name";
    $regions_stmt = $db->prepare($regions_query);
    $regions_stmt->execute();
    $regions = $regions_stmt->fetchAll();

    $categories_query = "SELECT * FROM categories ORDER BY category_name";
    $categories_stmt = $db->prepare($categories_query);
    $categories_stmt->execute();
    $categories = $categories_stmt->fetchAll();
    
} catch (Exception $e) {
    $error = "데이터를 불러오는 중 오류가 발생했습니다: " . $e->getMessage();
    $location = []; $photos = []; $regions = []; $categories = [];
}

// 7. HTML 헤더 포함
include '../../includes/header.php';
?>

<style>
.dynamic-field { display: none; }
.dynamic-field.active { display: block; }
.map-container { width: 100%; height: 400px; border: 1px solid #ddd; border-radius: 8px; margin-top: 10px; }
.image-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.image-preview-item { width: 120px; height: 120px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; position: relative; }
.image-preview-item img { width: 100%; height: 100%; object-fit: cover; }
.existing-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 15px; }
.existing-photo-item { position: relative; }
.existing-photo-item img { width: 100%; height: 120px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; }
.existing-photo-item .delete-link { position: absolute; top: 5px; right: 5px; }
.existing-photo-item .vr-badge { position: absolute; bottom: 5px; left: 5px; background: rgba(0,0,0,0.7); color: white; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
</style>

<div class="page-header">
    <h2>📍 장소 수정</h2>
    <a href="list.php" class="btn btn-secondary">← 목록으로</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; // <br> 태그 허용 ?></div>
<?php endif; ?>
<?php if (isset($_GET['message'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="" enctype="multipart/form-data">
            
            <h4 style="margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">기본 정보</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="region_id">지역 <span style="color:red;">*</span></label>
                    <select id="region_id" name="region_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo $region['region_id']; ?>" 
                                <?php echo ($location['region_id'] == $region['region_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($region['region_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="category_id">카테고리 <span style="color:red;">*</span></label>
                    <select id="category_id" name="category_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['category_id']; ?>"
                                    data-name="<?php echo htmlspecialchars($category['category_name']); ?>"
                                    <?php echo ($location['category_id'] == $category['category_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="location_name">장소명 <span style="color:red;">*</span></label>
                <input type="text" id="location_name" name="location_name" 
                       value="<?php echo htmlspecialchars($location['location_name']); ?>" required>
            </div>
            <div class="form-group">
                <label for="address">주소</label>
                <input type="text" id="address" name="address" 
                       value="<?php echo htmlspecialchars($location['address']); ?>">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="establishment_year">조성년도</label>
                    <input type="number" id="establishment_year" name="establishment_year" min="1900" max="2100" 
                           value="<?php echo htmlspecialchars($location['establishment_year']); ?>">
                </div>
                <div class="form-group">
                    <label for="management_agency">관리기관</label>
                    <input type="text" id="management_agency" name="management_agency" 
                           value="<?php echo htmlspecialchars($location['management_agency']); ?>">
                </div>
            </div>
            
            <div id="area-field" class="form-group dynamic-field">
                <label for="area">넓이 (㎡)</label>
                <input type="number" id="area" name="area" step="0.01" 
                       value="<?php echo htmlspecialchars($location['area']); ?>">
            </div>
            <div id="length-field" class="form-group dynamic-field">
                <label for="length">길이 (m)</label>
                <input type="number" id="length" name="length" step="0.01" 
                       value="<?php echo htmlspecialchars($location['length']); ?>">
            </div>
            <div id="width-field" class="form-group dynamic-field">
                <label for="width">도로 폭 (m)</label>
                <input type="number" id="width" name="width" step="0.01" 
                       value="<?php echo htmlspecialchars($location['width']); ?>">
            </div>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">위치 정보</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="latitude">위도</label>
                    <input type="text" id="latitude" name="latitude" 
                           value="<?php echo htmlspecialchars($location['latitude']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="longitude">경도</label>
                    <input type="text" id="longitude" name="longitude" 
                           value="<?php echo htmlspecialchars($location['longitude']); ?>" readonly>
                </div>
            </div>
            <div id="map" class="map-container"></div>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">멀티미디어</h4>
            <div class="form-group">
                <label>기존 사진 (삭제)</label>
                <div class="existing-photos">
                    <?php if (empty($photos)): ?>
                        <p style="color: #888; font-size: 14px;">등록된 사진이 없습니다.</p>
                    <?php endif; ?>
                    <?php foreach ($photos as $photo): ?>
                        <div class="existing-photo-item">
                            <img src="<?php echo BASE_URL . '/' . htmlspecialchars($photo['file_path']); ?>" alt="<?php echo htmlspecialchars($photo['file_name']); ?>">
                            <?php if ($photo['photo_type'] === 'vr360'): ?>
                                <span class="vr-badge">360° VR</span>
                            <?php endif; ?>
                            <a href="delete_photo.php?id=<?php echo $photo['photo_id']; ?>&location_id=<?php echo $location_id; ?>" 
                               class="btn btn-sm btn-danger delete-link" 
                               onclick="return confirm('이 사진을 삭제하시겠습니까?');">삭제</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">

            <div class="form-group">
                <label for="images">새 일반 사진 추가 (다중 선택, 최대 <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB)</label>
                <input type="file" id="images" name="images[]" accept="image/*" multiple onchange="previewImages(this)">
                <div id="image-previews" class="image-preview"></div>
            </div>
            <div class="form-group">
                <label for="vr_photo">새 360도 VR 사진 추가 (최대 <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB)</label>
                <input type="file" id="vr_photo" name="vr_photo" accept="image/*" onchange="previewVRImage(this)">
                <div id="vr-preview" class="image-preview"></div>
            </div>
            <div class="form-group">
                <label for="video_url">동영상 URL</label>
                <input type="url" id="video_url" name="video_url" 
                       value="<?php echo htmlspecialchars($location['video_url']); ?>">
            </div>
            
            <div class="form-group">
                <label for="description">설명</label>
                <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($location['description']); ?></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 30px;">
                <button type="submit" class="btn btn-primary">수정 완료</button>
                <a href="view.php?id=<?php echo $location_id; ?>" class="btn btn-secondary">취소 (상세보기로)</a>
            </div>
        </form>
    </div>
</div>

<?php 
$apiKey = '';
if (defined('KAKAO_MAP_API_KEY')) $apiKey = KAKAO_MAP_API_KEY;
?>

<?php if ($apiKey != ''): ?>
    <script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo $apiKey; ?>"></script>
    <script>
    function updateDynamicFields() {
        const categorySelect = document.getElementById('category_id');
        const selectedOption = categorySelect.options[categorySelect.selectedIndex];
        if (!selectedOption) return;
        const categoryName = selectedOption.getAttribute('data-name');
        document.querySelectorAll('.dynamic-field').forEach(field => field.classList.remove('active'));
        if (categoryName && (categoryName.includes('공원') || categoryName.includes('생활숲'))) {
            document.getElementById('area-field').classList.add('active');
        } else if (categoryName && categoryName.includes('가로수')) {
            document.getElementById('length-field').classList.add('active');
            document.getElementById('width-field').classList.add('active');
        }
    }
    document.getElementById('category_id').addEventListener('change', updateDynamicFields);
    document.addEventListener('DOMContentLoaded', updateDynamicFields); 

    const mapContainer = document.getElementById('map');
    const defaultLat = <?php echo defined('DEFAULT_LAT') ? DEFAULT_LAT : '34.8194'; ?>;
    const defaultLng = <?php echo defined('DEFAULT_LNG') ? DEFAULT_LNG : '126.3794'; ?>;
    let currentLat = <?php echo !empty($location['latitude']) ? $location['latitude'] : 'defaultLat'; ?>;
    let currentLng = <?php echo !empty($location['longitude']) ? $location['longitude'] : 'defaultLng'; ?>;
    let zoomLevel = <?php echo !empty($location['latitude']) ? 5 : 9; ?>; 
    const mapOption = { center: new kakao.maps.LatLng(currentLat, currentLng), level: zoomLevel };
    const map = new kakao.maps.Map(mapContainer, mapOption);
    let marker = null;
    if (<?php echo !empty($location['latitude']) ? 'true' : 'false'; ?>) {
        marker = new kakao.maps.Marker({ position: new kakao.maps.LatLng(currentLat, currentLng), map: map });
    }
    kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
        const latlng = mouseEvent.latLng;
        if (marker) marker.setMap(null);
        marker = new kakao.maps.Marker({ position: latlng, map: map });
        document.getElementById('latitude').value = latlng.getLat();
        document.getElementById('longitude').value = latlng.getLng();
    });

    function previewImages(input) {
        const preview = document.getElementById('image-previews');
        preview.innerHTML = '';
        if (input.files) {
            Array.from(input.files).forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'image-preview-item';
                    div.innerHTML = `<img src="${e.target.result}" alt="Preview ${index + 1}">`;
                    preview.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        }
    }
    function previewVRImage(input) {
        const preview = document.getElementById('vr-preview');
        preview.innerHTML = '';
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const div = document.createElement('div');
                div.className = 'image-preview-item';
                div.innerHTML = `<img src="${e.target.result}" alt="VR Preview"><span style="position: absolute; bottom: 5px; left: 5px; background: rgba(0,0,0,0.7); color: white; padding: 2px 6px; border-radius: 4px; font-size: 11px;">360° VR</span>`;
                preview.appendChild(div);
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
    </script>
<?php else: ?>
    <div class="alert alert-error">
        카카오맵 API 키가 설정되지 않았습니다. config/kakao_map.php 파일을 확인하세요.
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>