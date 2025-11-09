<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
checkAuth();

$page_title = '새 장소 추가';
require_once '../../includes/header.php';

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $region_id = (int)$_POST['region_id'];
    $category_id = (int)$_POST['category_id'];
    $location_name = sanitize_input($_POST['location_name']);
    $address = sanitize_input($_POST['address']);
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $area = !empty($_POST['area']) ? (float)$_POST['area'] : null;
    $length = !empty($_POST['length']) ? (float)$_POST['length'] : null;
    $width = !empty($_POST['width']) ? (float)$_POST['width'] : null;
    $establishment_year = !empty($_POST['establishment_year']) ? (int)$_POST['establishment_year'] : null;
    $management_agency = sanitize_input($_POST['management_agency']);
    $video_url = sanitize_input($_POST['video_url']);
    $description = sanitize_input($_POST['description']);
    
    if (empty($location_name) || $region_id == 0 || $category_id == 0) {
        $error = '필수 항목을 모두 입력해주세요.';
    } else {
        try {
            $db->beginTransaction();
            
            // 장소 정보 저장
            $query = "INSERT INTO locations (region_id, category_id, location_name, address, latitude, longitude, 
                                            area, length, width, establishment_year, management_agency, video_url, description) 
                      VALUES (:region_id, :category_id, :location_name, :address, :latitude, :longitude, 
                              :area, :length, :width, :establishment_year, :management_agency, :video_url, :description)";
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
            $stmt->execute();
            
            $location_id = $db->lastInsertId();
            
            // 일반 이미지 업로드 처리
            if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
                $upload_dir = UPLOAD_PATH;
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $sort_order = 1;
                foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                    if (!empty($tmp_name) && $_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['images']['name'][$key];
                        $file_size = $_FILES['images']['size'][$key];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        
                        if (in_array($file_ext, ALLOWED_EXTENSIONS) && $file_size <= MAX_FILE_SIZE) {
                            $new_file_name = 'location_' . $location_id . '_' . time() . '_' . $sort_order . '.' . $file_ext;
                            $file_path = $upload_dir . $new_file_name;
                            
                            if (move_uploaded_file($tmp_name, $file_path)) {
                                $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, 
                                                                             photo_type, sort_order, uploaded_by) 
                                               VALUES (:location_id, :file_path, :file_name, :file_size, 
                                                       'image', :sort_order, :uploaded_by)";
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
            
            // 360 VR 사진 업로드 처리
            if (isset($_FILES['vr_photo']) && !empty($_FILES['vr_photo']['tmp_name'])) {
                if ($_FILES['vr_photo']['error'] === UPLOAD_ERR_OK) {
                    $file_name = $_FILES['vr_photo']['name'];
                    $file_size = $_FILES['vr_photo']['size'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    
                    if (in_array($file_ext, ALLOWED_EXTENSIONS) && $file_size <= MAX_FILE_SIZE) {
                        $new_file_name = 'location_vr_' . $location_id . '_' . time() . '.' . $file_ext;
                        $file_path = UPLOAD_PATH . $new_file_name;
                        
                        if (move_uploaded_file($_FILES['vr_photo']['tmp_name'], $file_path)) {
                            $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, 
                                                                         photo_type, uploaded_by) 
                                           VALUES (:location_id, :file_path, :file_name, :file_size, 
                                                   'vr360', :uploaded_by)";
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
            $log_query = "INSERT INTO activity_logs (user_id, action, target_type, target_id, details, ip_address) 
                          VALUES (:user_id, 'create', 'location', :target_id, '장소 추가: {$location_name}', :ip)";
            $log_stmt = $db->prepare($log_query);
            $log_stmt->bindParam(':user_id', $_SESSION['user_id']);
            $log_stmt->bindParam(':target_id', $location_id);
            $log_stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
            $log_stmt->execute();
            
            $db->commit();
            
            redirect(BASE_URL . '/admin/locations/view.php?id=' . $location_id);
        } catch (Exception $e) {
            $db->rollBack();
            $error = '장소 추가 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}
?>

<style>
.dynamic-field {
    display: none;
}
.dynamic-field.active {
    display: block;
}
.map-container {
    width: 100%;
    height: 400px;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    margin-top: 10px;
}
.image-preview {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 10px;
}
.image-preview-item {
    width: 120px;
    height: 120px;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
    position: relative;
}
.image-preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.remove-image {
    position: absolute;
    top: 5px;
    right: 5px;
    background: rgba(239, 68, 68, 0.9);
    color: white;
    border: none;
    border-radius: 4px;
    padding: 4px 8px;
    cursor: pointer;
    font-size: 12px;
}
</style>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">새 장소 추가</h3>
        <a href="list.php" class="btn btn-secondary">← 목록으로</a>
    </div>
    <div class="card-body">
        <form method="POST" action="" enctype="multipart/form-data">
            <!-- 기본 정보 -->
            <h4 style="margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid var(--border-color);">
                📍 기본 정보
            </h4>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="region_id">지역 *</label>
                    <select id="region_id" name="region_id" class="form-control" required>
                        <option value="0">선택하세요</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo $region['region_id']; ?>">
                                <?php echo htmlspecialchars($region['region_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="category_id">카테고리 *</label>
                    <select id="category_id" name="category_id" class="form-control" required>
                        <option value="0">선택하세요</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['category_id']; ?>"
                                    data-name="<?php echo htmlspecialchars($category['category_name']); ?>">
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="location_name">장소명 *</label>
                <input type="text" 
                       id="location_name" 
                       name="location_name" 
                       class="form-control" 
                       placeholder="예: 압해읍 중앙공원" 
                       required>
            </div>
            
            <div class="form-group">
                <label for="address">주소</label>
                <input type="text" 
                       id="address" 
                       name="address" 
                       class="form-control" 
                       placeholder="예: 전남 신안군 압해읍 중앙로 123">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="establishment_year">조성년도</label>
                    <input type="number" 
                           id="establishment_year" 
                           name="establishment_year" 
                           class="form-control" 
                           min="1900" 
                           max="2100" 
                           placeholder="예: 2020">
                    <small style="color: #6b7280; font-size: 13px;">장소가 조성된 년도를 입력하세요.</small>
                </div>
                
                <div class="form-group">
                    <label for="management_agency">관리기관</label>
                    <input type="text" 
                           id="management_agency" 
                           name="management_agency" 
                           class="form-control" 
                           placeholder="예: 신안군청 산림과">
                    <small style="color: #6b7280; font-size: 13px;">관리 책임 기관을 입력하세요.</small>
                </div>
            </div>
            
            <!-- 카테고리별 동적 필드 -->
            <div id="area-field" class="form-group dynamic-field">
                <label for="area">넓이 (㎡)</label>
                <input type="number" 
                       id="area" 
                       name="area" 
                       class="form-control" 
                       step="0.01" 
                       placeholder="예: 5000.00">
                <small style="color: #6b7280; font-size: 13px;">공원 또는 생활숲의 면적을 입력하세요.</small>
            </div>
            
            <div id="length-field" class="form-group dynamic-field">
                <label for="length">길이 (m)</label>
                <input type="number" 
                       id="length" 
                       name="length" 
                       class="form-control" 
                       step="0.01" 
                       placeholder="예: 1500.00">
                <small style="color: #6b7280; font-size: 13px;">가로수 구간의 길이를 입력하세요.</small>
            </div>
            
            <div id="width-field" class="form-group dynamic-field">
                <label for="width">도로 폭 (m)</label>
                <input type="number" 
                       id="width" 
                       name="width" 
                       class="form-control" 
                       step="0.01" 
                       placeholder="예: 12.50">
                <small style="color: #6b7280; font-size: 13px;">도로의 폭을 입력하세요.</small>
            </div>
            
            <!-- 위치 정보 -->
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 2px solid var(--border-color);">
                🗺️ 위치 정보
            </h4>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="latitude">위도</label>
                    <input type="text" 
                           id="latitude" 
                           name="latitude" 
                           class="form-control" 
                           placeholder="예: 35.1234567"
                           readonly>
                </div>
                
                <div class="form-group">
                    <label for="longitude">경도</label>
                    <input type="text" 
                           id="longitude" 
                           name="longitude" 
                           class="form-control" 
                           placeholder="예: 126.1234567"
                           readonly>
                </div>
            </div>
            
            <div id="map" class="map-container"></div>
            <small style="color: #6b7280; font-size: 13px; margin-top: 5px; display: block;">
                💡 지도를 클릭하여 위치를 지정하세요. (선택사항)
            </small>
            
            <!-- 멀티미디어 -->
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 2px solid var(--border-color);">
                📸 멀티미디어
            </h4>
            
            <div class="form-group">
                <label for="images">일반 사진 (3-5장)</label>
                <input type="file" 
                       id="images" 
                       name="images[]" 
                       class="form-control" 
                       accept="image/*"
                       multiple
                       onchange="previewImages(this)">
                <small style="color: #6b7280; font-size: 13px;">JPG, PNG 형식, 최대 10MB, 3-5장 권장</small>
                <div id="image-previews" class="image-preview"></div>
            </div>
            
            <div class="form-group">
                <label for="vr_photo">360도 VR 사진</label>
                <input type="file" 
                       id="vr_photo" 
                       name="vr_photo" 
                       class="form-control" 
                       accept="image/*"
                       onchange="previewVRImage(this)">
                <small style="color: #6b7280; font-size: 13px;">360도 파노라마 사진 (선택사항)</small>
                <div id="vr-preview" class="image-preview"></div>
            </div>
            
            <div class="form-group">
                <label for="video_url">동영상 URL</label>
                <input type="url" 
                       id="video_url" 
                       name="video_url" 
                       class="form-control" 
                       placeholder="예: https://www.youtube.com/watch?v=...">
                <small style="color: #6b7280; font-size: 13px;">유튜브, 네이버TV 등 동영상 링크</small>
            </div>
            
            <!-- 설명 -->
            <div class="form-group">
                <label for="description">설명</label>
                <textarea id="description" 
                          name="description" 
                          class="form-control" 
                          rows="4" 
                          placeholder="장소에 대한 설명을 입력하세요."></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 30px;">
                <button type="submit" class="btn btn-primary">✓ 저장</button>
                <a href="list.php" class="btn btn-secondary">취소</a>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>"></script>
<script>
// 카테고리별 동적 필드 표시
document.getElementById('category_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const categoryName = selectedOption.getAttribute('data-name');
    
    // 모든 동적 필드 숨기기
    document.querySelectorAll('.dynamic-field').forEach(field => {
        field.classList.remove('active');
        field.querySelector('input').removeAttribute('required');
    });
    
    // 카테고리에 따라 필드 표시
    if (categoryName && (categoryName.includes('공원') || categoryName.includes('생활숲'))) {
        document.getElementById('area-field').classList.add('active');
    } else if (categoryName && categoryName.includes('가로수')) {
        document.getElementById('length-field').classList.add('active');
        document.getElementById('width-field').classList.add('active');
    }
});

// 카카오맵 초기화
const mapContainer = document.getElementById('map');
const mapOption = {
    center: new kakao.maps.LatLng(34.8194, 126.3794), // 신안군 중심
    level: 9
};
const map = new kakao.maps.Map(mapContainer, mapOption);

let marker = null;

// 지도 클릭 이벤트
kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
    const latlng = mouseEvent.latLng;
    
    // 기존 마커 제거
    if (marker) {
        marker.setMap(null);
    }
    
    // 새 마커 생성
    marker = new kakao.maps.Marker({
        position: latlng,
        map: map
    });
    
    // 좌표 입력
    document.getElementById('latitude').value = latlng.getLat();
    document.getElementById('longitude').value = latlng.getLng();
});

// 이미지 미리보기
function previewImages(input) {
    const preview = document.getElementById('image-previews');
    preview.innerHTML = '';
    
    if (input.files) {
        Array.from(input.files).slice(0, 5).forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                const div = document.createElement('div');
                div.className = 'image-preview-item';
                div.innerHTML = `
                    <img src="${e.target.result}" alt="Preview ${index + 1}">
                    <button type="button" class="remove-image" onclick="removeImagePreview(this, ${index})">✕</button>
                `;
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
            div.innerHTML = `
                <img src="${e.target.result}" alt="VR Preview">
                <span style="position: absolute; bottom: 5px; left: 5px; background: rgba(0,0,0,0.7); color: white; padding: 2px 6px; border-radius: 4px; font-size: 11px;">360° VR</span>
            `;
            preview.appendChild(div);
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function removeImagePreview(button, index) {
    button.parentElement.remove();
    // 파일 입력도 초기화하려면 추가 로직 필요
}
</script>

<?php require_once '../../includes/footer.php'; ?>
