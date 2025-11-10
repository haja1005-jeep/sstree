<?php
/**
 * 장소 목록
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '장소 관리';

$database = new Database();
$db = $database->getConnection();

// 삭제 처리
if (isset($_GET['delete']) && isAdmin()) {
    $location_id = (int)$_GET['delete'];
    
    try {
        // 관련 사진 삭제
        $photo_query = "SELECT file_path FROM location_photos WHERE location_id = :location_id";
        $photo_stmt = $db->prepare($photo_query);
        $photo_stmt->bindParam(':location_id', $location_id);
        $photo_stmt->execute();
        $photos = $photo_stmt->fetchAll();
        
        foreach ($photos as $photo) {
            $file_path = BASE_PATH . '/' . $photo['file_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        // 장소 삭제 (CASCADE로 사진도 자동 삭제)
        $query = "DELETE FROM locations WHERE location_id = :location_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':location_id', $location_id);
        $stmt->execute();
        
        // 로그 기록
        $log_query = "INSERT INTO activity_logs (user_id, action, target_type, target_id, details, ip_address) 
                      VALUES (:user_id, 'delete', 'location', :target_id, '장소 삭제', :ip)";
        $log_stmt = $db->prepare($log_query);
        $log_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $log_stmt->bindParam(':target_id', $location_id);
        $log_stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $log_stmt->execute();
        
        $success_message = '장소가 삭제되었습니다.';
    } catch (Exception $e) {
        $error_message = '장소 삭제 중 오류가 발생했습니다: ' . $e->getMessage();
    }
}

// 검색 및 필터
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$region_filter = isset($_GET['region']) ? (int)$_GET['region'] : 0;
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;

// 장소 목록 조회
$query = "SELECT l.*, r.region_name, c.category_name,
          (SELECT COUNT(*) FROM location_photos WHERE location_id = l.location_id AND photo_type = 'image') as image_count,
          (SELECT COUNT(*) FROM location_photos WHERE location_id = l.location_id AND photo_type = 'vr360') as vr_count,
          (SELECT COUNT(*) FROM trees WHERE location_id = l.location_id) as tree_count
          FROM locations l
          LEFT JOIN regions r ON l.region_id = r.region_id
          LEFT JOIN categories c ON l.category_id = c.category_id
          WHERE 1=1";

if ($search) {
    $query .= " AND (l.location_name LIKE :search OR l.address LIKE :search)";
}
if ($region_filter > 0) {
    $query .= " AND l.region_id = :region_id";
}
if ($category_filter > 0) {
    $query .= " AND l.category_id = :category_id";
}

$query .= " ORDER BY l.created_at DESC";

$stmt = $db->prepare($query);

if ($search) {
    $search_param = "%{$search}%";
    $stmt->bindParam(':search', $search_param);
}
if ($region_filter > 0) {
    $stmt->bindParam(':region_id', $region_filter);
}
if ($category_filter > 0) {
    $stmt->bindParam(':category_id', $category_filter);
}

$stmt->execute();
$locations = $stmt->fetchAll();

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

require_once '../../includes/header.php';
?>

<?php if (isset($success_message)): ?>
    <div class="alert alert-success"><?php echo $success_message; ?></div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger"><?php echo $error_message; ?></div>
<?php endif; ?>

<!-- 검색 및 필터 -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body">
        <form method="GET" action="" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end;">
            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 200px;">
                <label for="search">검색</label>
                <input type="text" 
                       id="search" 
                       name="search" 
                       class="form-control" 
                       placeholder="장소명, 주소 검색"
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
                <label for="region">지역</label>
                <select id="region" name="region" class="form-control">
                    <option value="0">전체</option>
                    <?php foreach ($regions as $region): ?>
                        <option value="<?php echo $region['region_id']; ?>" 
                                <?php echo $region_filter == $region['region_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($region['region_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
                <label for="category">카테고리</label>
                <select id="category" name="category" class="form-control">
                    <option value="0">전체</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['category_id']; ?>"
                                <?php echo $category_filter == $category['category_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary" style="margin-bottom: 0;">🔍 검색</button>
            <a href="list.php" class="btn btn-secondary" style="margin-bottom: 0;">초기화</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">장소 목록 (총 <?php echo count($locations); ?>개)</h3>
        <a href="add.php" class="btn btn-primary">➕ 새 장소 추가</a>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>장소명</th>
                        <th>지역</th>
                        <th>카테고리</th>
                        <th>주소</th>
                        <th>미디어</th>
                        <th>나무 수</th>
                        <th>등록일</th>
                        <th>관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($locations) > 0): ?>
                        <?php foreach ($locations as $location): ?>
                            <tr>
                                <td style="font-weight: 600;">
                                    <a href="view.php?id=<?php echo $location['location_id']; ?>" 
                                       style="color: var(--primary-color); text-decoration: none;">
                                        <?php echo htmlspecialchars($location['location_name']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($location['region_name']); ?></td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars($location['category_name']); ?></span></td>
                                <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo htmlspecialchars($location['address'] ?: '-'); ?>
                                </td>
                                <td>
                                    <?php if ($location['image_count'] > 0): ?>
                                        <span title="일반 사진">📷 <?php echo $location['image_count']; ?></span>
                                    <?php endif; ?>
                                    <?php if ($location['vr_count'] > 0): ?>
                                        <span title="360 VR 사진" style="margin-left: 5px;">🔮 <?php echo $location['vr_count']; ?></span>
                                    <?php endif; ?>
                                    <?php if ($location['video_url']): ?>
                                        <span title="동영상" style="margin-left: 5px;">🎬</span>
                                    <?php endif; ?>
                                    <?php if (!$location['image_count'] && !$location['vr_count'] && !$location['video_url']): ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--success-color); font-weight: 700;">
                                    <?php echo number_format($location['tree_count']); ?>그루
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($location['created_at'])); ?></td>
                                <td>
                                    <a href="view.php?id=<?php echo $location['location_id']; ?>" class="btn btn-sm btn-success">보기</a>
                                    <a href="edit.php?id=<?php echo $location['location_id']; ?>" class="btn btn-sm btn-secondary">수정</a>
                                    <?php if (isAdmin()): ?>
                                        <a href="?delete=<?php echo $location['location_id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('이 장소를 삭제하시겠습니까?\n연결된 모든 사진과 나무 데이터가 삭제됩니다.');">삭제</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                등록된 장소가 없습니다.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">💡 장소 관리 안내</h3>
    </div>
    <div class="card-body">
        <ul style="list-style: none; padding: 0;">
            <li style="margin-bottom: 10px;">✓ 장소는 나무가 위치한 공원, 생활숲, 가로수 구간 등을 등록합니다.</li>
            <li style="margin-bottom: 10px;">✓ 일반 사진 3-5장, 360도 VR 사진, 동영상 URL을 첨부할 수 있습니다.</li>
            <li style="margin-bottom: 10px;">✓ 카테고리에 따라 넓이(공원, 생활숲) 또는 길이(가로수)를 입력합니다.</li>
            <li style="margin-bottom: 10px;">✓ 카카오맵에서 정확한 위치(GPS 좌표)를 지정할 수 있습니다.</li>
        </ul>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

