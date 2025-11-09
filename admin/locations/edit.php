<?php
/**
 * 장소 수정
 * Smart Tree Map - Sinan County
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

// ALLOWED_EXTENSIONS 배열 확인 (config.php에서 문자열이면 배열로 변환)
$allowed_ext_array = is_array(ALLOWED_EXTENSIONS) ? ALLOWED_EXTENSIONS : explode(',', ALLOWED_EXTENSIONS);


// 3. 폼 제출(POST) 처리 (HTML 출력 전에!)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 폼 데이터 가져오기
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
    
    // 유효성 검사
    if (empty($location_name) || $region_id == 0 || $category_id == 0) {
        $error = '필수 항목(지역, 카테고리, 장소명)을 모두 입력해주세요.';
    } else {
        try {
            $db->beginTransaction();
            
            // 장소 정보 업데이트
            $query = "UPDATE locations SET 
                        region_id = :region_id, 
                        category_id = :category_id, 
                        location_name = :location_name, 
                        address = :address, 
                        latitude = :latitude, 
                        longitude = :longitude, 
                        area = :area, 
                        length = :length, 
                        width = :width, 
                        establishment_year = :establishment_year, 
                        management_agency = :management_agency, 
                        video_url = :video_url, 
                        description = :description
                      WHERE location_id = :location_id";
            
            $stmt = $db->prepare($query);
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
            
            // --- 신규 파일 업로드 (add.php와 동일한 로직) ---
            
            // 일반 이미지 업로드
            if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
                $upload_dir = UPLOAD_PATH;
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $sort_order = 1;
                foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                    if (!empty($tmp_name) && $_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['images']['name'][$key];
                        $file_size = $_FILES['images']['size'][$key];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        
                        if (in_array($file_ext, $allowed_ext_array) && $file_size <= MAX_FILE_SIZE) {
                            $new_file_name = 'location_' . $location_id . '_' . time() . '_' . $sort_order . '.' . $file_ext;
                            $file_path = $upload_dir . $new_file_name;
                            
                            if (move_uploaded_file($tmp_name, $file_path)) {
                                $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, 
                                                                             photo_type, sort_order, uploaded_by, uploaded_at) 
                                               VALUES (:location_id, :file_path, :file_name, :file_size, 
                                                       'image', :sort_order, :uploaded_by, NOW())";
                                $photo_stmt = $db->prepare($photo_query);
                                $relative_path = 'uploads/photos/' . $new_file_name;
                                $photo_stmt->bindParam(':location_id', $location_id);
                                $photo_stmt->bindParam(':file_path', $relative_path);
                                $photo_stmt->bindParam(':file_name', $file_name);
                                $photo_stmt->bindParam(':file_size', $file_size);
                                $photo_stmt->bindParam(':sort_order', $sort_order);
                                $photo_stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                                $photo_stmt->execute();
                                $sort_order++;
                            }
                        }
                    }
                }
            }
            
            // 360 VR 사진 업로드
            if (isset($_FILES['vr_photo']) && !empty($_FILES['vr_photo']['tmp_name'])) {
                if ($_FILES['vr_photo']['error'] === UPLOAD_ERR_OK) {
                    $file_name = $_FILES['vr_photo']['name'];
                    $file_size = $_FILES['vr_photo']['size'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    
                    if (in_array($file_ext, $allowed_ext_array) && $file_size <= MAX_FILE_SIZE) {
                        $new_file_name = 'location_vr_' . $location_id . '_' . time() . '.' . $file_ext;
                        $file_path = UPLOAD_PATH . $new_file_name;
                        
                        if (move_uploaded_file($_FILES['vr_photo']['tmp_name'], $file_path)) {
                            // 기존 VR 사진이 있다면 삭제 또는 업데이트 (여기서는 추가만 함 - 교체를 원하면 로직 필요)
                            $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, 
                                                                         photo_type, uploaded_by, uploaded_at) 
                                           VALUES (:location_id, :file_path, :file_name, :file_size, 
                                                   'vr360', :uploaded_by, NOW())";
                            $photo_stmt = $db->prepare($photo_query);
                            $relative_path = 'uploads/photos/' . $new_file_name;
                            $photo_stmt->bindParam(':location_id', $location_id);
                            $photo_stmt->bindParam(':file_path', $relative_path);
                            $photo_stmt->bindParam(':file_name', $file_name);
                            $photo_stmt->bindParam(':file_size', $file_size);
                            $photo_stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                            $photo_stmt->execute();
                        }
                    }
                }
            }
            
            // 로그 기록
            logActivity($_SESSION['user_id'], 'update', 'location', $location_id, "장소 수정: {$location_name}");
            
            $db->commit();
            
            // 4. 성공 시 상세보기 페이지로 리다이렉트
            redirect('/admin/locations/view.php?id=' . $location_id . '&message=' . urlencode('장소가 수정되었습니다.'));
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = '장소 수정 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

// 5. (GET 요청이거나 POST 실패 시) 기존 데이터 조회
try {
    // 장소 정보
    $query = "SELECT * FROM locations WHERE location_id = :location_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':location_id', $location_id);
    $stmt->execute();
    $location = $stmt->fetch();

    if (!$location) {
        redirect('/admin/locations/list.php');
    }

    // 사진 목록
    $photos_query = "SELECT * FROM location_photos WHERE location_id = :location_id ORDER BY photo_type, sort_order";
    $photos_stmt = $db->prepare($photos_query);
    $photos_stmt->bindParam(':location_id', $location_id);
    $photos_stmt->execute();
    $photos = $photos_stmt->fetchAll();

    // 지역 목록 (dropdown용)
    $regions_query = "SELECT * FROM regions ORDER BY region_name";
    $regions_stmt = $db->prepare($regions_query);
    $regions_stmt->execute();
    $regions = $regions_stmt->fetchAll();

    // 카테고리 목록 (dropdown용)
    $categories_query = "SELECT * FROM categories ORDER BY category_name";
    $categories_stmt = $db->prepare($categories_query);
    $categories_stmt->execute();
    $categories = $categories_stmt->fetchAll();

} catch (Exception $e) {
    $error = "데이터를 불러오는 중 오류가 발생했습니다: " . $e->getMessage();
    $location = []; // 폼이 깨지지 않도록 빈 배열로 초기화
    $photos = [];
    $regions = [];
    $categories = [];
}

// 6. HTML 헤더 포함 (모든 PHP 로직이 끝난 후)
include '../../includes/header.php';
?>

<style>
/* add.php와 동일한 스타일 */
.dynamic-field { display: none; }
.dynamic-field.active { display: block; }
.map-container { width: 100%; height: 400px; border: 1px solid #ddd; border-radius: 8px; margin-top: 10px; }
.image-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.image-preview-item { width: 120px; height: 120px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; position: relative; }
.image-preview-item img { width: 100%; height: 100%; object-fit: cover; }
.remove-image { position: absolute; top: 5px; right: 5px; background: rgba(239, 68, 68, 0.9); color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer; font-size: 12px; }

/* 기존 사진 관리용 스타일 */
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
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (isset($_GET['message'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['message']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="" enctype="multipart/form-data">
            <h4 style="margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                기본 정보
            </h4>
            
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
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                위치 정보
            </h4>
            
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
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                멀티미디어
            </h4>

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
                <label for="images">새 일반 사진 추가 (다중 선택)</label>
                <input type="file" id="images" name="images[]" accept="image/*" multiple onchange="previewImages(this)">
                <div id="image-previews" class="image-preview"></div>
            </div>
            
            <div class="form-group">
                <label for="vr_photo">새 360도 VR 사진 추가 (기존 사진 교체)</label>
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

<?php if (defined('KAKAO_MAP_API_KEY') && KAKAO_MAP_API_KEY != ''): ?>
    <script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>"></script>
    <script>
    // 카테고리별 동적 필드 표시
    function updateDynamicFields() {
        const categorySelect = document.getElementById('category_id');
        const selectedOption = categorySelect.options[categorySelect.selectedIndex];
        const categoryName = selectedOption.getAttribute('data-name');
        
        document.querySelectorAll('.dynamic-field').forEach(field => {
            field.classList.remove('active');
        });
        
        if (categoryName && (categoryName.includes('공원') || categoryName.includes('생활숲'))) {
            document.getElementById('area-field').classList.add('active');
        } else if (categoryName && categoryName.includes('가로수')) {
            document.getElementById('length-field').classList.add('active');
            document.getElementById('width-field').classList.add('active');
        }
    }
    
    document.getElementById('category_id').addEventListener('change', updateDynamicFields);
    document.addEventListener('DOMContentLoaded', updateDynamicFields); // 페이지 로드 시 즉시 실행

    // 카카오맵 초기화 (기존 좌표 사용)
    const mapContainer = document.getElementById('map');
    const defaultLat = <?php echo defined('DEFAULT_LAT') ? DEFAULT_LAT : '34.8194'; ?>;
    const defaultLng = <?php echo defined('DEFAULT_LNG') ? DEFAULT_LNG : '126.3794'; ?>;
    
    // PHP에서 가져온 기존 좌표 사용
    let currentLat = <?php echo !empty($location['latitude']) ? $location['latitude'] : 'defaultLat'; ?>;
    let currentLng = <?php echo !empty($location['longitude']) ? $location['longitude'] : 'defaultLng'; ?>;
    let zoomLevel = <?php echo !empty($location['latitude']) ? 5 : 9; ?>; // 기존 좌표 있으면 확대

    const mapOption = {
        center: new kakao.maps.LatLng(currentLat, currentLng),
        level: zoomLevel
    };
    const map = new kakao.maps.Map(mapContainer, mapOption);
    let marker = null;

    // 기존 좌표가 있으면 마커 표시
    if (<?php echo !empty($location['latitude']) ? 'true' : 'false'; ?>) {
        marker = new kakao.maps.Marker({
            position: new kakao.maps.LatLng(currentLat, currentLng),
            map: map
        });
    }

    // 지도 클릭 이벤트
    kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
        const latlng = mouseEvent.latLng;
        
        if (marker) {
            marker.setMap(null);
        }
        
        marker = new kakao.maps.Marker({
            position: latlng,
            map: map
        });
        
        document.getElementById('latitude').value = latlng.getLat();
        document.getElementById('longitude').value = latlng.getLng();
    });

    // 이미지 미리보기 (add.php와 동일)
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
        카카오맵 API 키가 설정되지 않았습니다. config/config.php 파일에서 KAKAO_MAP_API_KEY를 확인하세요.
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>