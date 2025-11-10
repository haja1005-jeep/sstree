<?php
/**
 * 나무 사진 삭제 처리
 * Smart Tree Map - Sinan County
 */

// 1. 설정 및 인증 파일 로드
require_once '../../config/config.php';
require_once '../../includes/auth.php';

// 2. 로그인 및 관리자 권한 확인
checkAuth();
if (!isAdmin()) {
    // 관리자가 아니면 대시보드로 리다이렉트
    redirect('/admin/index.php');
}

// 3. GET 파라미터 확인 (삭제할 사진 ID, 돌아갈 나무 ID)
$photo_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tree_id = isset($_GET['tree_id']) ? (int)$_GET['tree_id'] : 0;

// 4. 필수 ID가 없으면 나무 목록으로 리다이렉트
if ($photo_id === 0 || $tree_id === 0) {
    redirect('/admin/trees/list.php');
}

$database = new Database();
$db = $database->getConnection();
$error = '';
$success = '';

try {
    // 5. DB에서 삭제할 파일의 경로(file_path)를 먼저 조회합니다.
    $query = "SELECT file_path FROM tree_photos WHERE photo_id = :photo_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':photo_id', $photo_id);
    $stmt->execute();
    $photo = $stmt->fetch();

    if ($photo) {
        // 6. DB에서 사진 레코드 삭제
        $deleteQuery = "DELETE FROM tree_photos WHERE photo_id = :photo_id";
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->bindParam(':photo_id', $photo_id);
        
        if ($deleteStmt->execute()) {
            // 7. DB 삭제 성공 시, 서버에서 실제 파일 삭제
            $full_path = BASE_PATH . '/' . $photo['file_path'];
            
            if (file_exists($full_path)) {
                @unlink($full_path); // @는 혹시 파일이 없어도 오류를 억제합니다.
            }

            // 8. 활동 로그 기록
            logActivity($_SESSION['user_id'], 'delete', 'tree_photo', $photo_id, '나무 사진 삭제 (나무 ID: ' . $tree_id . ')');
            
            $success = '사진이 삭제되었습니다.';
        } else {
            $error = '데이터베이스에서 사진 정보를 삭제하는 데 실패했습니다.';
        }
    } else {
        $error = '삭제할 사진 정보를 찾을 수 없습니다.';
    }

} catch (Exception $e) {
    $error = '사진 삭제 중 오류가 발생했습니다: ' . $e->getMessage();
}

// 9. 작업 완료 후, 메시지와 함께 나무 수정(edit.php) 페이지로 복귀
if ($error) {
    redirect('/admin/trees/edit.php?id=' . $tree_id . '&message=' . urlencode($error) . '&type=error');
} else {
    redirect('/admin/trees/edit.php?id=' . $tree_id . '&message=' . urlencode($success));
}
?>