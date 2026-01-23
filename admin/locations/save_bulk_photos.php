<?php
/**
 * 일괄 사진 저장 처리
 * 수정사항:
 * 1. 출력 버퍼 제어 (JSON 깨짐 방지)
 * 2. 이미지 용량 1MB 이하 최적화 (Max 1920px, Quality 80)
 * 3. 파일명 규칙 변경: location_{id}_{uniqid}.ext (안전한 영문/숫자)
 */

// 1. 출력 버퍼링 시작
ob_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

// 2. 불필요한 출력(BOM, 공백) 제거
ob_clean();

header('Content-Type: application/json');

// 이미지 회전 보정 함수
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

// 이미지 처리 및 저장 함수 (1MB 이하 최적화)
function processAndSaveImage($src, $dest, $quality = 80) {
    ini_set('memory_limit', '512M');
    set_time_limit(300);
    
    try {
        $info = getimagesize($src);
        if (!$info) return false;
        
        list($w, $h, $type) = [$info[0], $info[1], $info['mime']];
        
        // 1MB 이하를 목표로 사이즈 조정 (Max 1920px)
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
            case 'image/jpeg': 
                $success = imagejpeg($destImg, $dest, $quality); 
                break;
            case 'image/png': 
                $success = imagepng($destImg, $dest, 8); 
                break;
            case 'image/gif': 
                $success = imagegif($destImg, $dest); 
                break;
        }
        
        imagedestroy($srcImg);
        imagedestroy($destImg);
        
        // 추가 검증: 1MB 초과 시 재압축 (JPEG만)
        if ($success && file_exists($dest) && filesize($dest) > 1048576 && $type === 'image/jpeg') {
            $tempImg = imagecreatefromjpeg($dest);
            imagejpeg($tempImg, $dest, 60); // 품질 60으로 낮춤
            imagedestroy($tempImg);
        }

        return $success;
    } catch (Exception $e) {
        return false;
    }
}

// POST 데이터 확인
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['photos'])) {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$saved_count = 0;
$failed_count = 0;
$saved_files = [];

try {
    $db->beginTransaction();
    
    $photos = $_POST['photos'];
    $upload_dir = UPLOAD_PATH;
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    foreach ($photos as $index => $photo_data) {
        try {
            $location_id = (int)$photo_data['location_id'];
            $latitude = !empty($photo_data['latitude']) ? floatval($photo_data['latitude']) : null;
            $longitude = !empty($photo_data['longitude']) ? floatval($photo_data['longitude']) : null;
            
            if (!isset($_FILES['photos']['tmp_name'][$index]['file'])) {
                $failed_count++;
                continue;
            }

            $tmp_name = $_FILES['photos']['tmp_name'][$index]['file'];
            $original_name = $_FILES['photos']['name'][$index]['file'];
            $file_error = $_FILES['photos']['error'][$index]['file'];
            
            if ($file_error !== UPLOAD_ERR_OK || empty($tmp_name)) {
                $failed_count++;
                continue;
            }
            
            $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (!in_array($file_ext, $allowed_ext)) {
                $failed_count++;
                continue;
            }
            
            // sort_order 조회
            $max_order_query = "SELECT COALESCE(MAX(sort_order), 0) as max_order 
                               FROM location_photos 
                               WHERE location_id = :location_id AND photo_type = 'image'";
            $max_order_stmt = $db->prepare($max_order_query);
            $max_order_stmt->execute([':location_id' => $location_id]);
            $sort_order = $max_order_stmt->fetch()['max_order'] + 1;
            
            // [수정] 파일명 생성 (안전한 영문/숫자 조합)
            // 예: location_15_65a7b3f4c2d1e.jpg
            $new_file_name = 'location_' . $location_id . '_' . uniqid() . '.' . $file_ext;
            $file_path = $upload_dir . $new_file_name;
            
            // 이미지 처리 및 저장 (Quality 80, Max 1920px)
            if (processAndSaveImage($tmp_name, $file_path, 80)) {
                $saved_files[] = $file_path;
                
                // DB 저장
                $insert_query = "INSERT INTO location_photos 
                                (location_id, file_path, file_name, file_size, photo_type, sort_order, 
                                 gps_latitude, gps_longitude, uploaded_by, uploaded_at) 
                                VALUES 
                                (:location_id, :file_path, :file_name, :file_size, 'image', :sort_order, 
                                 :latitude, :longitude, :uploaded_by, NOW())";
                
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->execute([
                    ':location_id' => $location_id,
                    ':file_path' => 'uploads/photos/' . $new_file_name,
                    ':file_name' => $original_name, // 원본 파일명 보존
                    ':file_size' => filesize($file_path),
                    ':sort_order' => $sort_order,
                    ':latitude' => $latitude,
                    ':longitude' => $longitude,
                    ':uploaded_by' => $_SESSION['user_id']
                ]);
                
                $saved_count++;
            } else {
                $failed_count++;
            }
            
        } catch (Exception $e) {
            $failed_count++;
            error_log('Photo save error: ' . $e->getMessage());
        }
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'saved' => $saved_count,
        'failed' => $failed_count,
        'message' => "{$saved_count}장 저장 완료, {$failed_count}장 실패"
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    foreach ($saved_files as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    
    echo json_encode([
        'success' => false,
        'message' => '저장 중 오류가 발생했습니다: ' . $e->getMessage()
    ]);
}
?>