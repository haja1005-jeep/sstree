<?php
/**
 * 관리자 대시보드 (장소 관리 통계 추가)
 * Smart Tree Map - Sinan County
 */

require_once '../config/config.php';
require_once '../includes/auth.php';

// 로그인 확인
checkAuth();

$page_title = '대시보드';

// 데이터베이스 연결
$database = new Database();
$db = $database->getConnection();

// 통계 데이터 조회
try {
    // 전체 나무 수
    $treeQuery = "SELECT COUNT(*) as total FROM trees";
    $treeStmt = $db->query($treeQuery);
    $totalTrees = $treeStmt->fetch()['total'];
    
    // 지역 수
    $regionQuery = "SELECT COUNT(*) as total FROM regions";
    $regionStmt = $db->query($regionQuery);
    $totalRegions = $regionStmt->fetch()['total'];
    
    // 수종 수
    $speciesQuery = "SELECT COUNT(*) as total FROM tree_species_master";
    $speciesStmt = $db->query($speciesQuery);
    $totalSpecies = $speciesStmt->fetch()['total'];
    
    // 사용자 수
    $userQuery = "SELECT COUNT(*) as total FROM users WHERE status = 'active'";
    $userStmt = $db->query($userQuery);
    $totalUsers = $userStmt->fetch()['total'];
    
    // ========== 장소 관리 통계 추가 ==========
    // 전체 장소 수
    $locationQuery = "SELECT COUNT(*) as total FROM locations";
    $locationStmt = $db->query($locationQuery);
    $totalLocations = $locationStmt->fetch()['total'];
    
    // 장소별 총 수목 수 (location_trees)
    $locationTreesQuery = "SELECT COALESCE(SUM(quantity), 0) as total FROM location_trees";
    $locationTreesStmt = $db->query($locationTreesQuery);
    $totalLocationTrees = $locationTreesStmt->fetch()['total'];
    
    // 장소별 수종 수
    $locationSpeciesQuery = "SELECT COUNT(DISTINCT species_id) as total FROM location_trees";
    $locationSpeciesStmt = $db->query($locationSpeciesQuery);
    $totalLocationSpecies = $locationSpeciesStmt->fetch()['total'];
    
    // 최근 등록된 나무
    $recentTreesQuery = "SELECT t.*, r.region_name, l.location_name, s.korean_name, u.name as creator_name
                         FROM trees t
                         LEFT JOIN regions r ON t.region_id = r.region_id
                         LEFT JOIN locations l ON t.location_id = l.location_id
                         LEFT JOIN tree_species_master s ON t.species_id = s.species_id
                         LEFT JOIN users u ON t.created_by = u.user_id
                         ORDER BY t.created_at DESC
                         LIMIT 5";
    $recentTreesStmt = $db->query($recentTreesQuery);
    $recentTrees = $recentTreesStmt->fetchAll();
    
    // 지역별 나무 수
    $regionStatsQuery = "SELECT r.region_name, COUNT(t.tree_id) as tree_count
                        FROM regions r
                        LEFT JOIN trees t ON r.region_id = t.region_id
                        GROUP BY r.region_id, r.region_name
                        ORDER BY tree_count DESC
                        LIMIT 5";
    $regionStatsStmt = $db->query($regionStatsQuery);
    $regionStats = $regionStatsStmt->fetchAll();
    
    // 장소별 수목 현황 (상위 5개)
    $locationTreesStatsQuery = "SELECT 
                                l.location_name,
                                COUNT(DISTINCT lt.species_id) as species_count,
                                COALESCE(SUM(lt.quantity), 0) as tree_count
                                FROM locations l
                                LEFT JOIN location_trees lt ON l.location_id = lt.location_id
                                GROUP BY l.location_id, l.location_name
                                HAVING tree_count > 0
                                ORDER BY tree_count DESC
                                LIMIT 5";
    $locationTreesStatsStmt = $db->query($locationTreesStatsQuery);
    $locationTreesStats = $locationTreesStatsStmt->fetchAll();
    
} catch(PDOException $e) {
    $totalTrees = 0;
    $totalRegions = 0;
    $totalSpecies = 0;
    $totalUsers = 0;
    $totalLocations = 0;
    $totalLocationTrees = 0;
    $totalLocationSpecies = 0;
    $recentTrees = [];
    $regionStats = [];
    $locationTreesStats = [];
}

include '../includes/header.php';
?>

<div class="page-header">
    <h2>대시보드</h2>
    <div>
        <span><?php echo date('Y년 m월 d일'); ?></span>
    </div>
</div>

<!-- 나무 데이터 통계 카드 -->
<div style="margin-bottom: 10px;">
    <h3 style="font-size: 16px; color: #7f8c8d; margin-bottom: 15px;">🌳 나무 데이터 현황</h3>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">🌳</div>
        <div class="stat-info">
            <h3><?php echo number_format($totalTrees); ?></h3>
            <p>총 나무 수</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">🗺️</div>
        <div class="stat-info">
            <h3><?php echo number_format($totalRegions); ?></h3>
            <p>관리 지역</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">🌲</div>
        <div class="stat-info">
            <h3><?php echo number_format($totalSpecies); ?></h3>
            <p>등록 수종</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon purple">👥</div>
        <div class="stat-info">
            <h3><?php echo number_format($totalUsers); ?></h3>
            <p>활성 사용자</p>
        </div>
    </div>
</div>

<!-- 장소 관리 통계 카드 (신규 추가) -->
<div style="margin: 30px 0 10px 0;">
    <h3 style="font-size: 16px; color: #7f8c8d; margin-bottom: 15px;">📍 장소 관리 현황</h3>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">📍</div>
        <div class="stat-info">
            <h3><?php echo number_format($totalLocations); ?></h3>
            <p>등록 장소</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">🌳</div>
        <div class="stat-info">
            <h3><?php echo number_format($totalLocationTrees); ?></h3>
            <p>장소별 총 나무</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">🌲</div>
        <div class="stat-info">
            <h3><?php echo number_format($totalLocationSpecies); ?></h3>
            <p>장소별 수종</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon purple">📊</div>
        <div class="stat-info">
            <h3><?php echo $totalLocations > 0 ? number_format($totalLocationTrees / $totalLocations, 1) : 0; ?></h3>
            <p>장소당 평균 나무</p>
        </div>
    </div>
</div>

<!-- 기존 + 장소 통계 테이블 -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px;">
    <!-- 최근 등록된 나무 -->
    <div class="card">
        <div class="card-header">최근 등록된 나무</div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>지역</th>
                        <th>장소</th>
                        <th>수종</th>
                        <th>등록일</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recentTrees) > 0): ?>
                        <?php foreach ($recentTrees as $tree): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(isset($tree['region_name']) ? $tree['region_name'] : '-'); ?></td>
                            <td><?php echo htmlspecialchars(isset($tree['location_name']) ? $tree['location_name'] : '-'); ?></td>
                            <td><?php echo htmlspecialchars(isset($tree['korean_name']) ? $tree['korean_name'] : '-'); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($tree['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #999;">등록된 나무가 없습니다.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- 지역별 통계 -->
    <div class="card">
        <div class="card-header">지역별 나무 현황</div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>지역명</th>
                        <th>나무 수</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($regionStats) > 0): ?>
                        <?php foreach ($regionStats as $stat): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($stat['region_name']); ?></td>
                            <td><strong><?php echo number_format($stat['tree_count']); ?>그루</strong></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="2" style="text-align: center; color: #999;">데이터가 없습니다.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 장소별 수목 현황 (신규 추가) -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">📍 장소별 수목 현황 (상위 5개)</div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>장소명</th>
                    <th>수종 수</th>
                    <th>총 나무 수</th>
                    <th>관리</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($locationTreesStats) > 0): ?>
                    <?php foreach ($locationTreesStats as $stat): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($stat['location_name']); ?></strong></td>
                        <td><?php echo number_format($stat['species_count']); ?>종</td>
                        <td><strong><?php echo number_format($stat['tree_count']); ?>주</strong></td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>/admin/locations/view.php?id=<?php echo $stat['location_id'] ?? '#'; ?>" 
                               class="btn btn-sm btn-primary">상세보기</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: #999;">
                            장소별 수목 데이터가 없습니다.
                            <a href="<?php echo BASE_URL; ?>/admin/locations/index.php">장소 관리</a>에서 수목을 등록해주세요.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 빠른 링크 -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">빠른 실행</div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="<?php echo BASE_URL; ?>/admin/trees/add.php" class="btn btn-primary">나무 등록</a>
        <a href="<?php echo BASE_URL; ?>/admin/locations/index.php" class="btn btn-success">장소 관리</a>
        <a href="<?php echo BASE_URL; ?>/admin/trees/map.php" class="btn btn-primary">지도 보기</a>
        <a href="<?php echo BASE_URL; ?>/admin/species/list.php" class="btn btn-secondary">수종 관리</a>
        <a href="<?php echo BASE_URL; ?>/admin/statistics/dashboard.php" class="btn btn-secondary">통계 보기</a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
