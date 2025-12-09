<?php
/**
 * AJAX: 지역 ID에 해당하는 장소 목록 반환
 */

// config.php와 database.php를 직접 포함
require_once '../config/config.php';
require_once __DIR__ . '/../config/database.php'; // 경로 수정

// DB 연결
$database = new Database();
$db = $database->getConnection();

$region_id = isset($_GET['region_id']) ? (int)$_GET['region_id'] : 0;
$options = '<option value="0">장소를 선택하세요</option>';

if ($region_id > 0) {
    try {
        $query = "SELECT location_id, location_name, category_id 
                  FROM locations 
                  WHERE region_id = :region_id 
                  ORDER BY location_name";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':region_id', $region_id);
        $stmt->execute();
        $locations = $stmt->fetchAll();
        
        foreach ($locations as $location) {
            // data 속성에 category_id를 추가하여, 장소 선택 시 카테고리가 자동 선택되도록 함
            $options .= sprintf(
                '<option value="%d" data-category-id="%d">%s</option>',
                $location['location_id'],
                $location['category_id'],
                htmlspecialchars($location['location_name'])
            );
        }
    } catch (Exception $e) {
        // 오류 발생 시에도 기본 옵션만 반환
    }
}

// 결과(HTML <option> 태그) 반환
echo $options;
?>