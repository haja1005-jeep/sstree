<?php
/**
 * 새 장소 추가 (오류 수정 및 이미지 리사이징 기능 추가)
 */

// 1. 설정 및 인증 파일 먼저 로드
require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';
checkAuth(); // 로그인 확인

$page_title = '새 장소 추가';
$database = new Database();
$db = $database->getConnection();
$error = '';

// 2. [오류 수정] ALLOWED_EXTENSIONS가 문자열일 경우 배열로 변환
$allowed_ext_array = is_array(ALLOWED_EXTENSIONS) ? ALLOWED_EXTENSIONS : explode(',', ALLOWED_EXTENSIONS);


/**
 * 3. [기능 추가] 이미지를 리사이징하고 웹용으로 압축하여 저장하는 함수
 *
 * @param string $source_path 원본 파일 경로 (임시 파일)
 * @param string $destination_path 저장될 파일 경로
 * @param int $max_width 최대 가로 크기 (이 크기를 초과하면 리사이징)
 * @param int $quality JPEG 압축 품질 (1-100)
 * @return bool 성공 여부
 */
function processAndSaveImage($source_path, $destination_path, $max_width = 1920, $quality = 85) {
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
        switch ($mime) {
            case 'image/jpeg': $source_image = imagecreatefromjpeg($source_path); break;
            case 'image/png': 
                $source_image = imagecreatefrompng($source_path); 
                imagealphablending($destination_image, false);
                imagesavealpha($destination_image, true);
                break;
            case 'image/gif': $source_image = imagecreatefromgif($source_path); break;
            default:
                imagedestroy($destination_image);
                return move_uploaded_file($source_path, $destination_path);
        }
        imagecopyresampled($destination_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
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


// 4. 폼 제출(POST) 로직 (HTML 출력 전에!)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $region_id = (int)$_POST['region_id'];
    $category_id = (int)$_POST['category_id'];
    // ... (폼 데이터 가져오기) ...
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
    
    if (empty($location_name) || $region_id == 0 || $category_id == 0) {
        $error = '필수 항목(지역, 카테고리, 장소명)을 모두 입력해주세요.';
    } else {
        try {
            $db->beginTransaction();
            
            $query = "INSERT INTO locations (region_id, category_id, location_name, address, latitude, longitude, 
                                            area, length, width, establishment_year, management_agency, video_url, description, created_at) 
                      VALUES (:region_id, :category_id, :location_name, :address, :latitude, :longitude, 
                              :area, :length, :width, :establishment_year, :management_agency, :video_url, :description, NOW())";
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
            $stmt->execute();
            
            $location_id = $db->lastInsertId();
            
            // --- [수정] 파일 업로드 (오류 알림 추가) ---
            $upload_error = false;
            $max_mb = MAX_FILE_SIZE / 1024 / 1024; // MB 단위
            $allowed_ext_str = implode(', ', $allowed_ext_array);
            
            // 일반 이미지 업로드
            if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
                $upload_dir = UPLOAD_PATH;
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $sort_order = 1;
                foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                    if (empty($tmp_name) || $_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    
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
						
					 $temp_dest_path = UPLOAD_PATH . 'temp_file';

					 if (processAndSaveImage($tmp_name, $temp_dest_path, 1920, 85)) { 
                            $new_file_name = 'location_' . $location_id . '_' . time() . '_' . $sort_order . '.' . $file_ext;
                            $file_path = UPLOAD_PATH . $new_file_name;
                            rename($temp_dest_path, $file_path); // [수정] 변수 사용
					
		                 // DB 저장
                        $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, sort_order, uploaded_by, uploaded_at) VALUES (:location_id, :file_path, :file_name, :file_size, 'image', :sort_order, :uploaded_by, NOW())";
                        $photo_stmt = $db->prepare($photo_query);
                        $relative_path = 'uploads/photos/' . $new_file_name;
                        $photo_stmt->bindParam(':location_id', $location_id);
                        $photo_stmt->bindParam(':file_path', $relative_path);
                        $photo_stmt->bindParam(':file_name', $file_name);

                        $compressed_size = filesize($file_path); // [수정] 파일 크기를 변수에 먼저 할당
                        $photo_stmt->bindParam(':file_size', $compressed_size); // [수정] 변수 전달

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
            
            // 360 VR 사진 업로드
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
                     
					 $temp_vr_dest_path = UPLOAD_PATH . 'temp_vr_file';


                     if (processAndSaveImage($_FILES['vr_photo']['tmp_name'], $temp_vr_dest_path, 4096, 90)) {
                            $new_file_name = 'location_vr_' . $location_id . '_' . time() . '.' . $file_ext;
                            $file_path = UPLOAD_PATH . $new_file_name;
                            rename($temp_vr_dest_path, $file_path); // [수정] 변수 사용
					 

                        // DB 저장
                        $photo_query = "INSERT INTO location_photos (location_id, file_path, file_name, file_size, photo_type, uploaded_by, uploaded_at) VALUES (:location_id, :file_path, :file_name, :file_size, 'vr360', :uploaded_by, NOW())";
                        $photo_stmt = $db->prepare($photo_query);
                        $relative_path = 'uploads/photos/' . $new_file_name;
                        $photo_stmt->bindParam(':location_id', $location_id);
                        $photo_stmt->bindParam(':file_path', $relative_path);
                        $photo_stmt->bindParam(':file_name', $file_name);

                        $vr_compressed_size = filesize($file_path); // [수정] 파일 크기를 변수에 먼저 할당
                        $photo_stmt->bindParam(':file_size', $vr_compressed_size); // [수정] 변수 전달

                        $photo_stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                        $photo_stmt->execute();
                    } else {
                        $error .= "{$file_name} (VR): 파일 저장(압축) 중 오류가 발생했습니다.<br>";
                        $upload_error = true;
                    }
				}

				}
            }
            
            // [수정] 업로드 오류가 발생했다면, DB 변경사항을 롤백하고 에러 메시지 표시
            if ($upload_error) {
                throw new Exception("파일 업로드 실패:<br>" . $error);
            }
            
            // 로그 기록
            logActivity($_SESSION['user_id'], 'create', 'location', $location_id, "장소 추가: {$location_name}");
            
            $db->commit();
            
            // 5. 리다이렉트
            redirect('/admin/locations/view.php?id=' . $location_id . '&message=' . urlencode('장소가 추가되었습니다.'));
            
        } catch (Exception $e) {
            $db->rollBack(); // 롤백
            $error = $e->getMessage(); // $error 변수에 오류 메시지를 담음
        }
    }
}

// 6. 폼 표시에 필요한 데이터 조회
$regions_query = "SELECT * FROM regions ORDER BY region_name";
$regions_stmt = $db->prepare($regions_query);
$regions_stmt->execute();
$regions = $regions_stmt->fetchAll();

$categories_query = "SELECT * FROM categories ORDER BY category_name";
$categories_stmt = $db->prepare($categories_query);
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll();

// 7. 모든 PHP 로직이 끝난 후, HTML 헤더 포함
include '../../includes/header.php';
?>

<style>
/* ... (스타일 코드는 이전과 동일) ... */
.dynamic-field { display: none; }
.dynamic-field.active { display: block; }
.map-container { width: 100%; height: 400px; border: 1px solid #ddd; border-radius: 8px; margin-top: 10px; }
.image-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.image-preview-item { width: 120px; height: 120px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; position: relative; }
.image-preview-item img { width: 100%; height: 100%; object-fit: cover; }
.remove-image { position: absolute; top: 5px; right: 5px; background: rgba(239, 68, 68, 0.9); color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer; font-size: 12px; }
</style>

<div class="page-header">
    <h2>📍 새 장소 추가</h2>
    <a href="list.php" class="btn btn-secondary">← 목록으로</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
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
                            <option value="<?php echo $region['region_id']; ?>" <?php echo (isset($_POST['region_id']) && $_POST['region_id'] == $region['region_id']) ? 'selected' : ''; ?>>
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
                                    <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['category_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="location_name">장소명 <span style="color:red;">*</span></label>
                <input type="text" id="location_name" name="location_name" placeholder="예: 압해읍 중앙공원" value="<?php echo isset($_POST['location_name']) ? htmlspecialchars($_POST['location_name']) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="address">주소</label>
                <input type="text" id="address" name="address" placeholder="예: 전남 신안군 압해읍 중앙로 123" value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="establishment_year">조성년도</label>
                    <input type="number" id="establishment_year" name="establishment_year" min="1900" max="2100" placeholder="예: 2020" value="<?php echo isset($_POST['establishment_year']) ? htmlspecialchars($_POST['establishment_year']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="management_agency">관리기관</label>
                    <input type="text" id="management_agency" name="management_agency" placeholder="예: 신안군청 산림과" value="<?php echo isset($_POST['management_agency']) ? htmlspecialchars($_POST['management_agency']) : ''; ?>">
                </div>
            </div>
            
            <div id="area-field" class="form-group dynamic-field">
                <label for="area">넓이 (㎡)</label>
                <input type="number" id="area" name="area" step="0.01" placeholder="예: 5000.00" value="<?php echo isset($_POST['area']) ? htmlspecialchars($_POST['area']) : ''; ?>">
            </div>
            <div id="length-field" class="form-group dynamic-field">
                <label for="length">길이 (m)</label>
                <input type="number" id="length" name="length" step="0.01" placeholder="예: 1500.00" value="<?php echo isset($_POST['length']) ? htmlspecialchars($_POST['length']) : ''; ?>">
            </div>
            <div id="width-field" class="form-group dynamic-field">
                <label for="width">도로 폭 (m)</label>
                <input type="number" id="width" name="width" step="0.01" placeholder="예: 12.50" value="<?php echo isset($_POST['width']) ? htmlspecialchars($_POST['width']) : ''; ?>">
            </div>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">위치 정보</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="latitude">위도</label>
                    <input type="text" id="latitude" name="latitude" placeholder="예: 35.1234567" value="<?php echo isset($_POST['latitude']) ? htmlspecialchars($_POST['latitude']) : ''; ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="longitude">경도</label>
                    <input type="text" id="longitude" name="longitude" placeholder="예: 126.1234567" value="<?php echo isset($_POST['longitude']) ? htmlspecialchars($_POST['longitude']) : ''; ?>" readonly>
                </div>
            </div>
            <div id="map" class="map-container"></div>
            <small style="color: #6b7280; font-size: 13px; margin-top: 5px; display: block;">
                💡 지도를 클릭하여 위치를 지정하세요. (선택사항)
            </small>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">멀티미디어</h4>
            <div class="form-group">
                <label for="images">일반 사진 (다중 선택 가능, 최대 1920px)</label>
                <input type="file" id="images" name="images[]" accept="image/*" multiple onchange="previewImages(this)">
                <div id="image-previews" class="image-preview"></div>
            </div>
            <div class="form-group">
                <label for="vr_photo">360도 VR 사진 (최대 4096px)</label>
                <input type="file" id="vr_photo" name="vr_photo" accept="image/*" onchange="previewVRImage(this)">
                <div id="vr-preview" class="image-preview"></div>
            </div>
            <div class="form-group">
                <label for="video_url">동영상 URL</label>
                <input type="url" id="video_url" name="video_url" placeholder="예: https://www.youtube.com/watch?v=..." value="<?php echo isset($_POST['video_url']) ? htmlspecialchars($_POST['video_url']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="description">설명</label>
                <textarea id="description" name="description" rows="4" placeholder="장소에 대한 설명을 입력하세요."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 30px;">
                <button type="submit" class="btn btn-primary">저장</button>
                <a href="list.php" class="btn btn-secondary">취소</a>
            </div>
        </form>
    </div>
</div>

<?php 
// config.php의 KAKAO_MAP_API_KEY를 사용하되, kakao_map.php도 확인
$apiKey = '';
if (defined('KAKAO_MAP_API_KEY')) {
    $apiKey = KAKAO_MAP_API_KEY;
} else if (file_exists('../../config/kakao_map.php')) {
    require_once '../../config/kakao_map.php';
    $apiKey = KAKAO_MAP_API_KEY;
}
?>

<?php if ($apiKey != ''): ?>
    <script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo $apiKey; ?>"></script>
    <script>
    // 카테고리별 동적 필드 표시
    function updateDynamicFields() {
        const categorySelect = document.getElementById('category_id');
        const selectedOption = categorySelect.options[categorySelect.selectedIndex];
        if (!selectedOption) return;
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

    // 카카오맵 초기화
    const mapContainer = document.getElementById('map');
    const defaultLat = <?php echo defined('DEFAULT_LAT') ? DEFAULT_LAT : '34.8194'; ?>;
    const defaultLng = <?php echo defined('DEFAULT_LNG') ? DEFAULT_LNG : '126.3794'; ?>;
    
    let currentLat = document.getElementById('latitude').value || defaultLat;
    let currentLng = document.getElementById('longitude').value || defaultLng;
    let zoomLevel = (document.getElementById('latitude').value) ? 5 : 9; // 좌표 있으면 확대

    const mapOption = {
        center: new kakao.maps.LatLng(currentLat, currentLng),
        level: zoomLevel
    };
    const map = new kakao.maps.Map(mapContainer, mapOption);
    let marker = null;

    if (document.getElementById('latitude').value && document.getElementById('longitude').value) {
        marker = new kakao.maps.Marker({
            position: new kakao.maps.LatLng(currentLat, currentLng),
            map: map
        });
    }

    kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
        const latlng = mouseEvent.latLng;
        if (marker) marker.setMap(null);
        marker = new kakao.maps.Marker({ position: latlng, map: map });
        document.getElementById('latitude').value = latlng.getLat();
        document.getElementById('longitude').value = latlng.getLng();
    });

    // 이미지 미리보기
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
        카카오맵 API 키가 설정되지 않았습니다. config/config.php 또는 config/kakao_map.php 파일을 확인하세요.
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>