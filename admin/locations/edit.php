<?php
/**
 * 장소 수정
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

// 장소 정보 조회
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

// 폼 제출 처리
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $category_id = $_POST['category_id'];
        $location_name = trim($_POST['location_name']);
        $address = trim($_POST['address']);
        $area = $_POST['area'] ? floatval($_POST['area']) : null;
        $road_name = trim($_POST['road_name']);
        $section_start = trim($_POST['section_start']);
        $section_end = trim($_POST['section_end']);
        $length = $_POST['length'] ? floatval($_POST['length']) : null;
        $location_type = $_POST['location_type'];
        $latitude = $_POST['latitude'] ? floatval($_POST['latitude']) : null;
        $longitude = $_POST['longitude'] ? floatval($_POST['longitude']) : null;
        $manager_name = trim($_POST['manager_name']);
        $manager_contact = trim($_POST['manager_contact']);
        $description = trim($_POST['description']);
        
        // 유효성 검사
        if (empty($location_name)) {
            throw new Exception('장소명을 입력해주세요.');
        }
        
        if (empty($category_id)) {
            throw new Exception('카테고리를 선택해주세요.');
        }
        
        // 장소 수정
        $update_query = "UPDATE locations SET
                         category_id = :category_id,
                         location_name = :location_name,
                         address = :address,
                         area = :area,
                         road_name = :road_name,
                         section_start = :section_start,
                         section_end = :section_end,
                         length = :length,
                         location_type = :location_type,
                         latitude = :latitude,
                         longitude = :longitude,
                         manager_name = :manager_name,
                         manager_contact = :manager_contact,
                         description = :description,
                         updated_at = CURRENT_TIMESTAMP
                         WHERE location_id = :location_id";
        
        $stmt = $db->prepare($update_query);
        $stmt->bindParam(':category_id', $category_id);
        $stmt->bindParam(':location_name', $location_name);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':area', $area);
        $stmt->bindParam(':road_name', $road_name);
        $stmt->bindParam(':section_start', $section_start);
        $stmt->bindParam(':section_end', $section_end);
        $stmt->bindParam(':length', $length);
        $stmt->bindParam(':location_type', $location_type);
        $stmt->bindParam(':latitude', $latitude);
        $stmt->bindParam(':longitude', $longitude);
        $stmt->bindParam(':manager_name', $manager_name);
        $stmt->bindParam(':manager_contact', $manager_contact);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':location_id', $location_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = '장소가 성공적으로 수정되었습니다.';
            header('Location: view.php?id=' . $location_id);
            exit;
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// POST 데이터가 있으면 사용, 없으면 기존 데이터 사용
$form_data = $_SERVER['REQUEST_METHOD'] == 'POST' ? $_POST : $location;

// 카테고리 목록
$categories_query = "SELECT c.*, r.region_name 
                     FROM location_categories c
                     LEFT JOIN regions r ON c.region_id = r.region_id
                     ORDER BY r.region_name, c.category_name";
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

.form-section:last-child {
    border-bottom: none;
}

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

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #374151;
}

.form-group label .required {
    color: #ef4444;
    margin-left: 4px;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}

.form-group textarea {
    min-height: 100px;
    resize: vertical;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
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

.gps-info strong {
    color: #166534;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid #f3f4f6;
}
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

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($error_message); ?>
    </div>
<?php endif; ?>

<div class="form-container">
    <form method="POST" action="">
        
        <!-- 기본 정보 -->
        <div class="form-section">
            <div class="form-section-title">
                📋 기본 정보
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>장소명 <span class="required">*</span></label>
                    <input type="text" name="location_name" required 
                           placeholder="예: 비금면 측금리 44-4옆 일원"
                           value="<?php echo htmlspecialchars($form_data['location_name']); ?>">
                </div>
                
                <div class="form-group">
                    <label>카테고리 <span class="required">*</span></label>
                    <select name="category_id" required>
                        <option value="">선택하세요</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['category_id']; ?>"
                                    <?php echo ($form_data['category_id'] == $category['category_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['region_name'] . ' - ' . $category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>장소 유형 <span class="required">*</span></label>
                    <select name="location_type" required>
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
        
        <!-- 면적/거리 정보 -->
        <div class="form-section">
            <div class="form-section-title">
                📐 면적/거리 정보
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>면적 (㎡)</label>
                    <input type="number" name="area" step="0.01" 
                           placeholder="예: 1162.00"
                           value="<?php echo $form_data['area'] ?? ''; ?>">
                    <div class="help-text">도시숲/생활숲인 경우 입력</div>
                </div>
                
                <div class="form-group">
                    <label>총 연장거리 (m)</label>
                    <input type="number" name="length" step="0.01" 
                           placeholder="예: 7538.00"
                           value="<?php echo $form_data['length'] ?? ''; ?>">
                    <div class="help-text">가로수인 경우 입력</div>
                </div>
            </div>
        </div>
        
        <!-- 도로 정보 (가로수용) -->
        <div class="form-section">
            <div class="form-section-title">
                🛣️ 도로 정보 (가로수인 경우)
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>도로명/노선명</label>
                    <input type="text" name="road_name" 
                           placeholder="예: 국도(2) 서남문로"
                           value="<?php echo htmlspecialchars($form_data['road_name'] ?? ''); ?>">
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
        </div>
        
        <!-- GPS 좌표 -->
        <div class="form-section">
            <div class="form-section-title">
                📍 GPS 좌표
            </div>
            
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
        
        <!-- 관리 정보 -->
        <div class="form-section">
            <div class="form-section-title">
                👤 관리 정보
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
        
        <!-- 제출 버튼 -->
        <div class="form-actions">
            <a href="view.php?id=<?php echo $location_id; ?>" class="btn btn-secondary">취소</a>
            <button type="submit" class="btn btn-primary">💾 수정 저장</button>
        </div>
    </form>
</div>

<script type="text/javascript" src="//dapi.kakao.com/v2/maps/sdk.js?appkey=<?php echo KAKAO_MAP_API_KEY; ?>"></script>
<script>
// 기존 좌표 또는 기본 좌표
const initialLat = <?php echo $form_data['latitude'] ?? DEFAULT_LAT; ?>;
const initialLng = <?php echo $form_data['longitude'] ?? DEFAULT_LNG; ?>;

// 지도 초기화
const mapContainer = document.getElementById('map');
const mapOption = {
    center: new kakao.maps.LatLng(initialLat, initialLng),
    level: <?php echo DEFAULT_ZOOM; ?>
};
const map = new kakao.maps.Map(mapContainer, mapOption);

// 마커
let marker = null;

// 기존 좌표가 있으면 마커 표시
<?php if ($form_data['latitude'] && $form_data['longitude']): ?>
    const existingPosition = new kakao.maps.LatLng(initialLat, initialLng);
    marker = new kakao.maps.Marker({
        position: existingPosition,
        map: map
    });
<?php endif; ?>

// 지도 클릭 이벤트
kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
    const latlng = mouseEvent.latLng;
    
    // 마커가 있으면 제거
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
    
    // 좌표 정보 표시
    document.getElementById('gps-info').style.display = 'block';
    document.getElementById('selected-coords').textContent = 
        `위도 ${latlng.getLat().toFixed(8)}, 경도 ${latlng.getLng().toFixed(8)}`;
});
</script>

<?php include '../../includes/footer.php'; ?>
