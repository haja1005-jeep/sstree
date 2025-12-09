<?php
/**
 * 장소 수정 (도로종류 추가 및 모든 기능 통합)
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
    header('Location: index.php');
    exit;
}

// 허용 확장자 설정
$allowed_ext_array = array_map('trim', array_map('strtolower', explode(',', ALLOWED_EXTENSIONS)));

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
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $saved_files = []; // 롤백용 파일 목록

    try {
        $region_id = isset($_POST['region_id']) ? (int)$_POST['region_id'] : 0;
        $category_id = $_POST['category_id'];
        $location_name = trim($_POST['location_name']);
        $address = trim($_POST['address']);
        $area = $_POST['area'] ? floatval($_POST['area']) : null;
        $road_name = trim($_POST['road_name']);
        $road_type = trim($_POST['road_type']); // [추가]
        $section_start = trim($_POST['section_start']);
        $section_end = trim($_POST['section_end']);
        $length = $_POST['length'] ? floatval($_POST['length']) : null;
        $width = $_POST['width'] ? floatval($_POST['width']) : null;
        $location_type = $_POST['location_type'];
        $latitude = $_POST['latitude'] ? floatval($_POST['latitude']) : null;
        $longitude = $_POST['longitude'] ? floatval($_POST['longitude']) : null;

        // [수정] 누락된 필드 변수 처리
        $establishment_year = !empty($_POST['establishment_year']) ? (int)$_POST['establishment_year'] : null;
        $management_agency = trim($_POST['management_agency']);

        $manager_name = trim($_POST['manager_name']);
        $manager_contact = trim($_POST['manager_contact']);
        $description = trim($_POST['description']);
        $video_url = trim($_POST['video_url']);
        
        // 유효성 검사
        if (empty($location_name)) throw new Exception('장소명을 입력해주세요.');
        if (empty($region_id)) throw new Exception('지역을 선택해주세요.');
        if (empty($category_id)) throw new Exception('카테고리를 선택해주세요.');
        
        // 트랜잭션 시작
        $db->beginTransaction();

        // 장소 수정 (road_type 추가)
        $update_query = "UPDATE locations SET
                         region_id = :region_id,
                         category_id = :category_id,
                         location_name = :location_name,
                         address = :address,
                         area = :area,
                         road_name = :road_name,
                         road_type = :road_type,
                         section_start = :section_start,
                         section_end = :section_end,
                         length = :length,
                         width = :width,
                         location_type = :location_type,
                         latitude = :latitude,
                         longitude = :longitude,
                         establishment_year = :establishment_year,
						 management_agency = :management_agency, 
                         manager_name = :manager_name,
                         manager_contact = :manager_contact,
                         description = :description,
                         video_url = :video_url,
                         updated_at = CURRENT_TIMESTAMP
                         WHERE location_id = :location_id";
        
        $stmt = $db->prepare($update_query);
        $stmt->bindParam(':region_id', $region_id);
        $stmt->bindParam(':category_id', $category_id);
        $stmt->bindParam(':location_name', $location_name);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':area', $area);
        $stmt->bindParam(':road_name', $road_name);
        $stmt->bindParam(':road_type', $road_type); // [추가]
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
        $stmt->bindParam(':location_id', $location_id);
        
        $stmt->execute();

        // --- 파일 업로드 처리 ---
        $upload_error = false;
        $error_details = '';
        $max_mb = MAX_FILE_SIZE / 1024 / 1024;

        // 1. 일반 이미지 업로드
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $upload_dir = UPLOAD_PATH;
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $max_order_query = "SELECT COALESCE(MAX(sort_order), 0) as max_order FROM location_photos WHERE location_id = :location_id AND photo_type = 'image'";
            $max_order_stmt = $db->prepare($max_order_query);
            $max_order_stmt->bindParam(':location_id', $location_id);
            $max_order_stmt->execute();
            $sort_order = $max_order_stmt->fetch()['max_order'] + 1;
            
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if (empty($tmp_name) || $_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) continue;
                
                $file_name = $_FILES['images']['name'][$key];
                $file_size = $_FILES['images']['size'][$key];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (!in_array($file_ext, $allowed_ext_array)) {
                    $error_details .= "{$file_name}: 허용되지 않는 파일 형식입니다.<br>";
                    $upload_error = true;
                } elseif ($file_size > MAX_FILE_SIZE) {
                    $error_details .= "{$file_name}: 파일 용량이 너무 큽니다.<br>";
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
                        $error_details .= "{$file_name}: 파일 저장 중 오류가 발생했습니다.<br>";
                        $upload_error = true;
                    }
                }
            }
        }

        // 2. 360 VR 사진 업로드
        if (isset($_FILES['vr_photo']) && !empty($_FILES['vr_photo']['tmp_name'])) {
            if ($_FILES['vr_photo']['error'] === UPLOAD_ERR_OK) {
                $file_name = $_FILES['vr_photo']['name'];
                $file_size = $_FILES['vr_photo']['size'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if (!in_array($file_ext, $allowed_ext_array)) {
                    $error_details .= "{$file_name} (VR): 허용되지 않는 파일 형식입니다.<br>";
                    $upload_error = true;
                } elseif ($file_size > MAX_FILE_SIZE) {
                    $error_details .= "{$file_name} (VR): 파일 용량이 너무 큽니다.<br>";
                    $upload_error = true;
                } else {
                    $new_file_name = 'location_vr_' . $location_id . '_' . uniqid() . '.' . $file_ext;
                    $file_path = $upload_dir . $new_file_name;

                    if (processAndSaveImage($_FILES['vr_photo']['tmp_name'], $file_path, 4096, 90)) {
                        $saved_files[] = $file_path;

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
                        $error_details .= "{$file_name} (VR): 파일 저장 중 오류가 발생했습니다.<br>";
                        $upload_error = true;
                    }
                }
            }
        }

        if ($upload_error) {
            throw new Exception("파일 업로드 실패:<br>" . $error_details);
        }

        $db->commit();
        
        $_SESSION['success_message'] = '장소가 성공적으로 수정되었습니다.';
        header('Location: view.php?id=' . $location_id);
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        foreach ($saved_files as $file_to_delete) {
            if (file_exists($file_to_delete)) {
                @unlink($file_to_delete);
            }
        }
        $error_message = $e->getMessage();
    }
}

// GET 데이터 조회
// 장소 정보
$query = "SELECT * FROM locations WHERE location_id = :location_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':location_id', $location_id);
$stmt->execute();
$location = $stmt->fetch();

if (!$location) {
    $_SESSION['error_message'] = '장소를 찾을 수 없습니다.';
    header('Location: index.php');
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

<style>
.form-container {
    background: white;
    padding: 30px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    max-width: 1200px;
    margin: 0 auto;
}
.form-section {
    margin-bottom: 30px;
    padding-bottom: 30px;
    border-bottom: 2px solid #f3f4f6;
}
.form-section:last-child { border-bottom: none; }
.form-section-title {
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}
.form-group { margin-bottom: 20px; }
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #374151;
}
.form-group label .required { color: #ef4444; margin-left: 4px; }
.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}
.form-group textarea { min-height: 100px; resize: vertical; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.form-group .help-text {
    font-size: 12px;
    color: #6b7280;
    margin-top: 5px;
}
#map {
    width: 100%;
    height: 400px;
    border-radius: 8px;
    margin-top: 10px;
}
.gps-info {
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 5px;
    padding: 12px;
    margin-top: 10px;
    font-size: 14px;
}
.gps-info strong { color: #166534; }
.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid #f3f4f6;
}
.image-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.image-preview-item { width: 120px; height: 120px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; position: relative; }
.image-preview-item img { width: 100%; height: 100%; object-fit: cover; }
.existing-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 15px; }
.existing-photo-item { position: relative; }
.existing-photo-item img { width: 100%; height: 120px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; }
.existing-photo-item .delete-link { 
    position: absolute; top: 5px; right: 5px; 
    background: rgba(239, 68, 68, 0.9); color: white; 
    padding: 4px 8px; border-radius: 4px; font-size: 11px; text-decoration: none;
}
.existing-photo-item .vr-badge { 
    position: absolute; bottom: 5px; left: 5px; 
    background: rgba(0,0,0,0.7); color: white; 
    padding: 2px 6px; border-radius: 4px; font-size: 11px; 
}
.dynamic-field { display: none; }
.dynamic-field.active { display: block; }
</style>

<div class="page-header">
    <div>
        <h2>✏️ 장소 수정</h2>
        <p><?php echo htmlspecialchars($location['location_name']); ?></p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="view.php?id=<?php echo $location_id; ?>" class="btn btn-secondary">← 상세보기</a>
        <a href="index.php" class="btn btn-secondary">목록으로</a>
    </div>
</div>

<?php if (isset($_GET['message'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['message']); ?></div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger">
        <?php echo $error_message; // html tag permitted ?>
    </div>
<?php endif; ?>

<div class="form-container">
    <form method="POST" action="" enctype="multipart/form-data">
        
        <div class="form-section">
            <div class="form-section-title">📋 기본 정보</div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>지역 <span class="required">*</span></label>
                    <select name="region_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo $region['region_id']; ?>"
                                    <?php echo ($form_data['region_id'] == $region['region_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($region['region_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>카테고리 <span class="required">*</span></label>
                    <select name="category_id" required>
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

            <div class="form-group">
                <label>장소명 <span class="required">*</span></label>
                <input type="text" name="location_name" required 
                       placeholder="예: 비금면 측금리 44-4옆 일원"
                       value="<?php echo htmlspecialchars($form_data['location_name']); ?>">
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>장소 유형 <span class="required">*</span></label>
                    <select name="location_type" id="location_type" required onchange="toggleFields()">
                        <option value="urban_forest" <?php echo ($form_data['location_type'] == 'urban_forest') ? 'selected' : ''; ?>>도시숲</option>
                        <option value="street_tree" <?php echo ($form_data['location_type'] == 'street_tree') ? 'selected' : ''; ?>>가로수</option>
                        <option value="living_forest" <?php echo ($form_data['location_type'] == 'living_forest') ? 'selected' : ''; ?>>생활숲</option>
                        <option value="school" <?php echo ($form_data['location_type'] == 'school') ? 'selected' : ''; ?>>학교</option>
                        <option value="park" <?php echo ($form_data['location_type'] == 'park') ? 'selected' : ''; ?>>공원</option>
                        <option value="other" <?php echo ($form_data['location_type'] == 'other') ? 'selected' : ''; ?>>기타</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>주소</label>
                    <input type="text" name="address" 
                           placeholder="예: 신안군 비금면 측금리 44-4"
                           value="<?php echo htmlspecialchars($form_data['address'] ?? ''); ?>">
                </div>
            </div>
        </div>
        
        <div class="form-section dynamic-field" id="area-section">
            <div class="form-section-title">📐 면적 정보</div>
            <div class="form-group">
                <label>면적 (㎡)</label>
                <input type="number" name="area" step="0.01" 
                        placeholder="예: 1162.00"
                        value="<?php echo $form_data['area'] ?? ''; ?>">
                <div class="help-text">도시숲/생활숲인 경우 입력</div>
            </div>
        </div>
        
        <div class="form-section dynamic-field" id="road-section">
            <div class="form-section-title">🛣️ 도로 정보 (가로수)</div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>도로명/노선명</label>
                    <input type="text" name="road_name" 
                           placeholder="예: 국도(2) 서남문로"
                           value="<?php echo htmlspecialchars($form_data['road_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>도로 종류</label>
                    <input type="text" name="road_type" 
                           placeholder="예: 국도, 지방도, 군도 등"
                           value="<?php echo htmlspecialchars($form_data['road_type'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>시점</label>
                    <input type="text" name="section_start" 
                           placeholder="예: 가산선착장(가산리 181-1)"
                           value="<?php echo htmlspecialchars($form_data['section_start'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label>종점</label>
                    <input type="text" name="section_end" 
                           placeholder="예: 음동마을(덕산리 138-3)"
                           value="<?php echo htmlspecialchars($form_data['section_end'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>총 연장거리 (m)</label>
                    <input type="number" name="length" step="0.01" 
                           placeholder="예: 7538.00"
                           value="<?php echo $form_data['length'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>도로 폭 (m)</label>
                    <input type="number" name="width" step="0.01" 
                           placeholder="예: 12.00"
                           value="<?php echo $form_data['width'] ?? ''; ?>">
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <div class="form-section-title">👤 관리 정보</div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>조성년도</label>
                    <input type="number" name="establishment_year" min="1900" max="2100" 
                           placeholder="예: 2020"
                           value="<?php echo $form_data['establishment_year'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>관리기관</label>
                    <input type="text" name="management_agency" 
                           placeholder="예: 신안군 산림과"
                           value="<?php echo htmlspecialchars($form_data['management_agency'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>관리 책임자</label>
                    <input type="text" name="manager_name" 
                           placeholder="예: 홍길동"
                           value="<?php echo htmlspecialchars($form_data['manager_name'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label>관리 연락처</label>
                    <input type="text" name="manager_contact" 
                           placeholder="예: 010-1234-5678"
                           value="<?php echo htmlspecialchars($form_data['manager_contact'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label>비고</label>
                <textarea name="description" 
                          placeholder="추가 설명이나 특이사항을 입력하세요"><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">📍 GPS 좌표</div>
            <div class="form-group">
                <label>지도에서 위치 선택</label>
                <div id="map"></div>
                <div class="gps-info" id="gps-info" style="<?php echo ($form_data['latitude'] && $form_data['longitude']) ? '' : 'display: none;'; ?>">
                    <strong>선택된 좌표:</strong> 
                    <span id="selected-coords">
                        <?php if ($form_data['latitude'] && $form_data['longitude']): ?>
                            위도 <?php echo number_format($form_data['latitude'], 8); ?>, 
                            경도 <?php echo number_format($form_data['longitude'], 8); ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>위도 (Latitude)</label>
                    <input type="number" name="latitude" id="latitude" step="0.00000001" 
                           placeholder="예: 34.8234567"
                           value="<?php echo $form_data['latitude'] ?? ''; ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label>경도 (Longitude)</label>
                    <input type="number" name="longitude" id="longitude" step="0.00000001" 
                           placeholder="예: 126.1234567"
                           value="<?php echo $form_data['longitude'] ?? ''; ?>" readonly>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">📷 멀티미디어</div>
            
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
                               class="delete-link" 
                               onclick="return confirm('이 사진을 삭제하시겠습니까?');">삭제</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            
            <div class="form-group">
                <label>일반 사진 추가 (다중 선택 가능, 최대 <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB)</label>
                <input type="file" name="images[]" accept="image/*" multiple onchange="previewImages(this)">
                <div id="image-previews" class="image-preview"></div>
            </div>
            
            <div class="form-group">
                <label>360도 VR 사진 추가 (최대 <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB)</label>
                <input type="file" name="vr_photo" accept="image/*" onchange="previewVRImage(this)">
                <div id="vr-preview" class="image-preview"></div>
            </div>
            
            <div class="form-group">
                <label>동영상 URL (유튜브 등)</label>
                <input type="url" name="video_url" 
                       placeholder="예: https://www.youtube.com/watch?v=..." 
                       value="<?php echo htmlspecialchars($form_data['video_url'] ?? ''); ?>">
            </div>
        </div>
        
        <div class="form-actions">
            <a href="view.php?id=<?php echo $location_id; ?>" class="btn btn-secondary">취소</a>
            <button type="submit" class="btn btn-primary">💾 수정 저장</button>
        </div>
    </form>
</div>

<script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>&libraries=services"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/kakao_map.js"></script>
<script>
// 입력 필드 표시 토글
function toggleFields() {
    const typeSelect = document.getElementById('location_type');
    const selectedType = typeSelect.value;
    
    const areaSection = document.getElementById('area-section');
    const roadSection = document.getElementById('road-section');
    
    // 초기화
    areaSection.classList.remove('active');
    roadSection.classList.remove('active');
    
    if (selectedType === 'street_tree') {
        // 가로수인 경우 도로 정보 표시
        roadSection.classList.add('active');
    } else {
        // 그 외(도시숲, 생활숲, 공원 등)인 경우 면적 정보 표시
        areaSection.classList.add('active');
    }
}

// 페이지 로드 시 초기 실행
document.addEventListener('DOMContentLoaded', toggleFields);

// 지도 초기화
const initialLat = <?php echo $form_data['latitude'] ?? DEFAULT_LAT; ?>;
const initialLng = <?php echo $form_data['longitude'] ?? DEFAULT_LNG; ?>;
const mapContainer = document.getElementById('map');
const mapOption = {
    center: new kakao.maps.LatLng(initialLat, initialLng),
    level: <?php echo DEFAULT_ZOOM; ?>
};
const map = new kakao.maps.Map(mapContainer, mapOption);
let marker = null;

// 기존 좌표가 있으면 마커 표시
<?php if ($form_data['latitude'] && $form_data['longitude']): ?>
    const existingPosition = new kakao.maps.LatLng(initialLat, initialLng);
    marker = new kakao.maps.Marker({ position: existingPosition, map: map });
<?php endif; ?>

// 지도 클릭 이벤트
kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
    const latlng = mouseEvent.latLng;
    if (marker) marker.setMap(null);
    marker = new kakao.maps.Marker({ position: latlng, map: map });
    
    document.getElementById('latitude').value = latlng.getLat();
    document.getElementById('longitude').value = latlng.getLng();
    
    document.getElementById('gps-info').style.display = 'block';
    document.getElementById('selected-coords').textContent = 
        `위도 ${latlng.getLat().toFixed(8)}, 경도 ${latlng.getLng().toFixed(8)}`;

    // 역지오코딩
    if (typeof searchCoordinateToAddress === 'function') {
        searchCoordinateToAddress(latlng.getLat(), latlng.getLng(), function(result) {
            if (result.success) {
                const addressValue = result.roadAddress ? result.roadAddress : result.address;
                const addressInput = document.querySelector('input[name="address"]');
                if (addressInput && !addressInput.value) { 
                    addressInput.value = addressValue;
                }
            }
        });
    }
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
            div.innerHTML = `<img src="${e.target.result}" alt="VR Preview">`;
            preview.appendChild(div);
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php include '../../includes/footer.php'; ?>