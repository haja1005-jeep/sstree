<?php
/**
 * 장소 사진 삭제 처리 (수정됨)
 * Smart Tree Map - Sinan County
 */

// 1. 설정 및 인증 파일 로드
require_once '../../config/config.php';
require_once '../../includes/auth.php';

// 2. 로그인 확인 (관리자 뿐만 아니라 매니저 등도 삭제 가능하도록 수정)
checkAuth();

// 3. GET 파라미터 확인 (삭제할 사진 ID, 돌아갈 장소 ID)
$photo_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$location_id = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;

// 4. 필수 ID가 없으면 장소 목록으로 리다이렉트
if ($photo_id === 0 || $location_id === 0) {
    redirect('/admin/locations/list.php');
}

$database = new Database();
$db = $database->getConnection();
$error = '';
$success = '';

try {
    // 5. DB에서 삭제할 파일 정보 조회
    // [보안 강화] photo_id 뿐만 아니라 location_id도 일치하는지 확인하여 잘못된 삭제 방지
    $query = "SELECT file_path FROM location_photos WHERE photo_id = :photo_id AND location_id = :location_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':photo_id', $photo_id);
    $stmt->bindParam(':location_id', $location_id);
    $stmt->execute();
    $photo = $stmt->fetch();

    if ($photo) {
        // 6. DB에서 사진 레코드 삭제
        $deleteQuery = "DELETE FROM location_photos WHERE photo_id = :photo_id";
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->bindParam(':photo_id', $photo_id);
        
        if ($deleteStmt->execute()) {
            // 7. DB 삭제 성공 시, 서버에서 실제 파일 삭제
            $file_path = BASE_PATH . '/' . $photo['file_path'];
            
            if (file_exists($file_path)) {
                @unlink($file_path); // 파일 삭제 시도 (@로 오류 억제)
            }

            // 8. 활동 로그 기록
            logActivity($_SESSION['user_id'], 'delete', 'location_photo', $photo_id, '장소 사진 삭제 (장소 ID: ' . $location_id . ')');
            
            $success = '사진이 삭제되었습니다.';
        } else {
            $error = '데이터베이스에서 사진 정보를 삭제하는 데 실패했습니다.';
        }
    } else {
        $error = '삭제할 사진 정보를 찾을 수 없거나, 해당 장소의 사진이 아닙니다.';
    }

} catch (Exception $e) {
    $error = '사진 삭제 중 오류가 발생했습니다: ' . $e->getMessage();
}

// 9. 작업 완료 후, 메시지와 함께 장소 수정 페이지로 복귀
// 성공/실패 메시지를 URL 파라미터로 전달하여 edit.php에서 표시
if ($error) {
    redirect('/admin/locations/edit.php?id=' . $location_id . '&message=' . urlencode($error) . '&type=error');
} else {
    redirect('/admin/locations/edit.php?id=' . $location_id . '&message=' . urlencode($success) . '&type=success');
}
?>