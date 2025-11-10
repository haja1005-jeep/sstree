<?php
/**
 * 나무 추가
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';
checkAuth();

$page_title = '나무 추가';
$database = new Database();
$db = $database->getConnection();
$error = '';

// ALLOWED_EXTENSIONS를 배열로 변환
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

    } catch (Exception $e) {
        return false;
    }
}

// 폼 제출 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            
            $query = "INSERT INTO trees (region_id, category_id, location_id, species_id, tree_number, 
                                        planting_date, height, diameter, health_status, latitude, longitude, 
                                        notes, created_by, created_at) 
                      VALUES (:region_id, :category_id, :location_id, :species_id, :tree_number, 
                              :planting_date, :height, :diameter, :health_status, :latitude, :longitude, 
                              :notes, :created_by, NOW())";
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
            $stmt->bindParam(':created_by', $_SESSION['user_id']);
            $stmt->execute();
            
            $tree_id = $db->lastInsertId();
            
            // 파일 업로드
            $upload_error = false;
            $max_mb = MAX_FILE_SIZE / 1024 / 1024;
            $allowed_ext_str = implode(', ', $allowed_ext_array);
            
            if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
                $upload_dir = UPLOAD_PATH;
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                
                foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                    if (empty($tmp_name) || $_FILES['photos']['error'][$key] !== UPLOAD_ERR_OK) continue;
                    
                    $file_name = $_FILES['photos']['name'][$key];
                    $file_size = $_FILES['photos']['size'][$key];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $photo_type = isset($_POST['photo_types'][$key]) ? sanitize($_POST['photo_types'][$key]) : 'full';
                    
                    if (!in_array($file_ext, $allowed_ext_array)) {
                        $error .= "{$file_name}: 허용되지 않는 파일 형식입니다. ({$allowed_ext_str}만 가능)<br>";
                        $upload_error = true;
                    } elseif ($file_size > MAX_FILE_SIZE) {
                        $error .= "{$file_name}: 파일 용량이 너무 큽니다. ({$max_mb}MB 이하만 가능)<br>";
                        $upload_error = true;
                    } else {
                        $new_file_name = 'tree_' . $tree_id . '_' . uniqid() . '.' . $file_ext;
                        $file_path = $upload_dir . $new_file_name;

                        if (processAndSaveImage($tmp_name, $file_path, 1920, 85)) {
                            $saved_files[] = $file_path;
                            
                            // EXIF GPS 데이터 추출
                            $photo_lat = null;
                            $photo_lng = null;
                            if (function_exists('exif_read_data')) {
                                $exif = @exif_read_data($tmp_name);
                                if (!empty($exif['GPSLatitude']) && !empty($exif['GPSLongitude'])) {
                                    // GPS 좌표 변환 로직 (생략 가능)
                                }
                            }
                            
                            $photo_query = "INSERT INTO tree_photos (tree_id, file_path, file_name, file_size, photo_type, 
                                                                     latitude, longitude, uploaded_by, uploaded_at) 
                                           VALUES (:tree_id, :file_path, :file_name, :file_size, :photo_type, 
                                                   :latitude, :longitude, :uploaded_by, NOW())";
                            $photo_stmt = $db->prepare($photo_query);
                            $relative_path = 'uploads/photos/' . $new_file_name;
                            $file_size_after = filesize($file_path);
                            
                            $photo_stmt->bindParam(':tree_id', $tree_id);
                            $photo_stmt->bindParam(':file_path', $relative_path);
                            $photo_stmt->bindParam(':file_name', $file_name);
                            $photo_stmt->bindParam(':file_size', $file_size_after);
                            $photo_stmt->bindParam(':photo_type', $photo_type);
                            $photo_stmt->bindParam(':latitude', $photo_lat);
                            $photo_stmt->bindParam(':longitude', $photo_lng);
                            $photo_stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                            $photo_stmt->execute();
                        } else {
                            $error .= "{$file_name}: 파일 저장 중 오류가 발생했습니다.<br>";
                            $upload_error = true;
                        }
                    }
                }
            }
            
            if ($upload_error) {
                throw new Exception("파일 업로드 실패:<br>" . $error);
            }
            
            logActivity($_SESSION['user_id'], 'create', 'tree', $tree_id, "나무 추가: {$tree_number}");
            
            $db->commit();
            
            redirect('/admin/trees/view.php?id=' . $tree_id . '&message=' . urlencode('나무가 추가되었습니다.'));
            
        } catch (Exception $e) {
            $db->rollBack();
            
            // 고아 파일 삭제
            foreach ($saved_files as $file_to_delete) {
                if (file_exists($file_to_delete)) {
                    @unlink($file_to_delete);
                }
            }
            
            $error = $e->getMessage();
        }
    }
}

// 폼 데이터 조회
$regions_query = "SELECT * FROM regions ORDER BY region_name";
$regions_stmt = $db->prepare($regions_query);
$regions_stmt->execute();
$regions = $regions_stmt->fetchAll();

$categories_query = "SELECT * FROM categories ORDER BY category_name";
$categories_stmt = $db->prepare($categories_query);
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll();

$locations_query = "SELECT l.*, r.region_name, c.category_name 
                   FROM locations l 
                   LEFT JOIN regions r ON l.region_id = r.region_id
                   LEFT JOIN categories c ON l.category_id = c.category_id
                   ORDER BY location_name";
$locations_stmt = $db->prepare($locations_query);
$locations_stmt->execute();
$locations = $locations_stmt->fetchAll();

$species_query = "SELECT * FROM tree_species_master ORDER BY korean_name";
$species_stmt = $db->prepare($species_query);
$species_stmt->execute();
$species_list = $species_stmt->fetchAll();

include '../../includes/header.php';
?>

<style>
.map-container { width: 100%; height: 400px; border: 1px solid #ddd; border-radius: 8px; margin-top: 10px; }

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

.preview-item img {
    width: 100%;
    height: 150px;
    object-fit: cover;
    display: block;
}

.preview-item .file-info {
    padding: 8px;
    font-size: 12px;
    color: #6b7280;
    background: white;
    border-top: 1px solid #e5e7eb;
    text-overflow: ellipsis;
    overflow: hidden;
    white-space: nowrap;
}

.preview-item .remove-btn {
    position: absolute;
    top: 5px;
    right: 5px;
    background: rgba(239, 68, 68, 0.9);
    color: white;
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    cursor: pointer;
    font-size: 14px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.preview-item .remove-btn:hover {
    background: rgba(220, 38, 38, 1);
    transform: scale(1.1);
}

.preview-item .type-selector {
    padding: 5px 8px;
    width: 100%;
    border: none;
    border-top: 1px solid #e5e7eb;
    font-size: 11px;
    background: white;
    cursor: pointer;
}

.preview-item .type-selector:focus {
    outline: 2px solid #667eea;
    outline-offset: -2px;
}
</style>

<div class="page-header">
    <h2>🌳 나무 추가</h2>
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
                    <label for="location_id">장소 <span style="color:red;">*</span></label>
                    <select id="location_id" name="location_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['location_id']; ?>"
                                    data-region="<?php echo $location['region_id']; ?>"
                                    data-category="<?php echo $location['category_id']; ?>"
                                    <?php echo (isset($_POST['location_id']) && $_POST['location_id'] == $location['location_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($location['location_name']); ?>
                                (<?php echo htmlspecialchars($location['region_name']); ?> / 
                                 <?php echo htmlspecialchars($location['category_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #6b7280; font-size: 13px;">장소를 선택하면 지역과 카테고리가 자동으로 설정됩니다.</small>
                </div>
                
                <div class="form-group">
                    <label for="species_id">수종 <span style="color:red;">*</span></label>
                    <select id="species_id" name="species_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($species_list as $species): ?>
                            <option value="<?php echo $species['species_id']; ?>"
                                    <?php echo (isset($_POST['species_id']) && $_POST['species_id'] == $species['species_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($species['korean_name']); ?>
                                (<?php echo htmlspecialchars($species['scientific_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group" style="display: none;">
                    <label for="region_id">지역</label>
                    <select id="region_id" name="region_id">
                        <option value="0">자동 설정됨</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo $region['region_id']; ?>">
                                <?php echo htmlspecialchars($region['region_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group" style="display: none;">
                    <label for="category_id">카테고리</label>
                    <select id="category_id" name="category_id">
                        <option value="0">자동 설정됨</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['category_id']; ?>">
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">나무 정보</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="tree_number">나무 번호</label>
                    <input type="text" id="tree_number" name="tree_number" 
                           placeholder="예: T-001" 
                           value="<?php echo isset($_POST['tree_number']) ? htmlspecialchars($_POST['tree_number']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="planting_date">식재일</label>
                    <input type="date" id="planting_date" name="planting_date" 
                           value="<?php echo isset($_POST['planting_date']) ? htmlspecialchars($_POST['planting_date']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="health_status">건강상태 <span style="color:red;">*</span></label>
                    <select id="health_status" name="health_status" required>
                        <option value="excellent" <?php echo (isset($_POST['health_status']) && $_POST['health_status'] == 'excellent') ? 'selected' : ''; ?>>최상</option>
                        <option value="good" <?php echo (!isset($_POST['health_status']) || $_POST['health_status'] == 'good') ? 'selected' : ''; ?>>양호</option>
                        <option value="fair" <?php echo (isset($_POST['health_status']) && $_POST['health_status'] == 'fair') ? 'selected' : ''; ?>>보통</option>
                        <option value="poor" <?php echo (isset($_POST['health_status']) && $_POST['health_status'] == 'poor') ? 'selected' : ''; ?>>나쁨</option>
                        <option value="dead" <?php echo (isset($_POST['health_status']) && $_POST['health_status'] == 'dead') ? 'selected' : ''; ?>>고사</option>
                    </select>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="height">높이 (m)</label>
                    <input type="number" id="height" name="height" step="0.01" 
                           placeholder="예: 15.50" 
                           value="<?php echo isset($_POST['height']) ? htmlspecialchars($_POST['height']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="diameter">직경 (cm)</label>
                    <input type="number" id="diameter" name="diameter" step="0.01" 
                           placeholder="예: 35.00" 
                           value="<?php echo isset($_POST['diameter']) ? htmlspecialchars($_POST['diameter']) : ''; ?>">
                </div>
            </div>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">위치 정보</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="latitude">위도</label>
                    <input type="text" id="latitude" name="latitude" 
                           placeholder="예: 34.8265" 
                           value="<?php echo isset($_POST['latitude']) ? htmlspecialchars($_POST['latitude']) : ''; ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="longitude">경도</label>
                    <input type="text" id="longitude" name="longitude" 
                           placeholder="예: 126.1069" 
                           value="<?php echo isset($_POST['longitude']) ? htmlspecialchars($_POST['longitude']) : ''; ?>" readonly>
                </div>
            </div>
            <div id="map" class="map-container"></div>
            <small style="color: #6b7280; font-size: 13px; margin-top: 5px; display: block;">
                💡 지도를 클릭하여 나무의 정확한 위치를 지정하세요. (선택사항)
            </small>
            
            <h4 style="margin: 30px 0 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">사진 업로드</h4>
            <div class="form-group">
                <label for="photos">일반 사진 (다중 선택 가능, 최대 <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB)</label>
                <input type="file" id="photos" name="photos[]" accept="image/*" multiple onchange="previewImages(this)">
                <div id="image-previews" class="image-preview-grid"></div>
            </div>
            <small style="color: #6b7280; font-size: 13px; margin-top: 5px; display: block;">
                💡 나무의 다양한 부위를 촬영하여 업로드하세요. Ctrl(Cmd) 키를 누른 채 여러 파일을 선택할 수 있습니다.
            </small>
            
            <div class="form-group" style="margin-top: 30px;">
                <label for="notes">비고</label>
                <textarea id="notes" name="notes" rows="4" 
                          placeholder="나무에 대한 특이사항이나 관리 메모를 입력하세요."><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 30px;">
                <button type="submit" class="btn btn-primary">저장</button>
                <a href="list.php" class="btn btn-secondary">취소</a>
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
    // 장소 선택 시 지역/카테고리 자동 설정
    document.getElementById('location_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const regionId = selectedOption.getAttribute('data-region');
        const categoryId = selectedOption.getAttribute('data-category');
        
        document.getElementById('region_id').value = regionId || '0';
        document.getElementById('category_id').value = categoryId || '0';
    });
    
    // 카카오맵 초기화
    const mapContainer = document.getElementById('map');
    const defaultLat = <?php echo defined('DEFAULT_LAT') ? DEFAULT_LAT : '34.8265'; ?>;
    const defaultLng = <?php echo defined('DEFAULT_LNG') ? DEFAULT_LNG : '126.1069'; ?>;
    let currentLat = document.getElementById('latitude').value || defaultLat;
    let currentLng = document.getElementById('longitude').value || defaultLng;
    let zoomLevel = (document.getElementById('latitude').value) ? 5 : 9;
    
    const mapOption = { center: new kakao.maps.LatLng(currentLat, currentLng), level: zoomLevel };
    const map = new kakao.maps.Map(mapContainer, mapOption);
    let marker = null;
    
    if (document.getElementById('latitude').value && document.getElementById('longitude').value) {
        marker = new kakao.maps.Marker({ position: new kakao.maps.LatLng(currentLat, currentLng), map: map });
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
        
        if (input.files && input.files.length > 0) {
            Array.from(input.files).forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'preview-item';
                    div.innerHTML = `
                        <img src="${e.target.result}" alt="미리보기 ${index + 1}">
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
                };
                reader.readAsDataURL(file);
            });
        }
    }
    </script>
<?php else: ?>
    <div class="alert alert-error">
        카카오맵 API 키가 설정되지 않았습니다. config/kakao_map.php 파일을 확인하세요.
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>