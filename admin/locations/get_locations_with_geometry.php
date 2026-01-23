<?php
/**
 * 장소 데이터 및 공간 좌표 반환 API
 * 수정: BOM(Byte Order Mark) 및 공백 제거를 위한 출력 버퍼 제어 추가
 */

// 1. 출력 버퍼링 시작
ob_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

// 2. 포함된 파일들(config.php, auth.php 등)에서 발생했을 수 있는 BOM이나 공백 제거
ob_clean();

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

try {
    $query = "SELECT 
                l.location_id,
                l.location_name,
                l.latitude,
                l.longitude,
                l.location_type,
                r.region_id,
                r.region_name,
                AsText(l.geom_polygon) as geom_wkt
              FROM locations l
              LEFT JOIN regions r ON l.region_id = r.region_id
              ORDER BY r.region_name, l.location_name";
    
    $stmt = $db->query($query);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 각 장소의 geometry 데이터 파싱
    $result = [];
    foreach ($locations as $location) {
        $item = [
            'location_id' => $location['location_id'],
            'location_name' => $location['location_name'],
            'latitude' => $location['latitude'] ? floatval($location['latitude']) : null,
            'longitude' => $location['longitude'] ? floatval($location['longitude']) : null,
            'location_type' => $location['location_type'],
            'region_id' => $location['region_id'],
            'region_name' => $location['region_name'],
            'geom_type' => null,
            'coordinates' => null
        ];
        
        // WKT 파싱
        if (!empty($location['geom_wkt'])) {
            $wkt = $location['geom_wkt'];
            
            if (strpos($wkt, 'POLYGON') === 0) {
                $item['geom_type'] = 'POLYGON';
                $item['coordinates'] = parsePolygonWKT($wkt);
            } elseif (strpos($wkt, 'LINESTRING') === 0) {
                $item['geom_type'] = 'LINESTRING';
                $item['coordinates'] = parseLineStringWKT($wkt);
            }
        }
        
        $result[] = $item;
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    // 에러 발생 시에도 JSON 형식으로 반환
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * POLYGON WKT 파싱
 */
function parsePolygonWKT($wkt) {
    // POLYGON((lng lat, lng lat, ...))
    $coords_str = str_replace('POLYGON((', '', $wkt);
    $coords_str = str_replace('))', '', $coords_str);
    
    $points = explode(',', $coords_str);
    $coordinates = [];
    
    foreach ($points as $point) {
        $parts = preg_split('/\s+/', trim($point));
        if (count($parts) >= 2) {
            $coordinates[] = [floatval($parts[0]), floatval($parts[1])];
        }
    }
    
    return $coordinates;
}

/**
 * LINESTRING WKT 파싱
 */
function parseLineStringWKT($wkt) {
    // LINESTRING(lng lat, lng lat, ...)
    $coords_str = str_replace('LINESTRING(', '', $wkt);
    $coords_str = str_replace(')', '', $coords_str);
    
    $points = explode(',', $coords_str);
    $coordinates = [];
    
    foreach ($points as $point) {
        $parts = preg_split('/\s+/', trim($point));
        if (count($parts) >= 2) {
            $coordinates[] = [floatval($parts[0]), floatval($parts[1])];
        }
    }
    
    return $coordinates;
}
?>