<?php
<?php
/**
 * 통계 대시보드
 * Smart Tree Map - Sinan County
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkAuth();

$page_title = '통계 대시보드';

$database = new Database();
$db = $database->getConnection();

// ---- 기간 해석
$period = $_GET['period'] ?? '30days';
$period_label = '최근 30일';

switch ($period) {
    case '7days':  $interval = '7 DAY';   $period_label = '최근 7일';  break;
    case '30days': $interval = '30 DAY';  $period_label = '최근 30일'; break;
    case '90days': $interval = '90 DAY';  $period_label = '최근 90일'; break;
    case '1year':  $interval = '1 YEAR';  $period_label = '최근 1년';  break;
    case 'all':    $interval = null;      $period_label = '전체 기간';  break;
    default:       $interval = '30 DAY';  $period_label = '최근 30일';
}

// ---- 유틸: 테이블에 특정 컬럼이 있는지 확인
function hasColumn(PDO $db, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c";
    $stmt = $db->prepare($sql);
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (bool)$stmt->fetchColumn();
}

// ---- 유틸: 테이블별 날짜 필터 생성 (후보 컬럼 중 존재하는 첫 번째 사용)
function buildDateFilter(PDO $db, string $table, array $candidates, ?string $interval): string {
    if ($interval === null) return ''; // 전체 기간
    foreach ($candidates as $col) {
        if (hasColumn($db, $table, $col)) {
            return " AND DATE(`$col`) >= DATE_SUB(CURDATE(), INTERVAL $interval) ";
        }
    }
    // 후보가 하나도 없으면 해당 테이블은 필터 생략
    return '';
}

// ---- 테이블별 후보 날짜 컬럼 정의
$treesDateFilter     = buildDateFilter($db, 'trees',       ['created_at','registered_at','updated_at','inserted_at'], $interval);
$speciesDateFilter   = $treesDateFilter; // 수종 수는 trees의 레코드 생성 시점을 기준으로 필터
$locationsDateFilter = buildDateFilter($db, 'locations',   ['created_at','registered_at','established_at','updated_at'], $interval);
$photosDateFilter    = buildDateFilter($db, 'tree_photos', ['uploaded_at','created_at','captured_at','updated_at'], $interval);

// ---- 전체 통계
$stats = [];

// 총 나무 수
$treeQuery = "SELECT COUNT(*) AS total FROM trees WHERE 1=1 $treesDateFilter";
$stats['total_trees'] = (int)$db->query($treeQuery)->fetch()['total'];

// 총 수종 수 (trees 기준으로 DISTINCT species_id)
$speciesQuery = "SELECT COUNT(DISTINCT species_id) AS total FROM trees WHERE species_id IS NOT NULL $speciesDateFilter";
$stats['total_species'] = (int)$db->query($speciesQuery)->fetch()['total'];

// 총 장소 수
$locationQuery = "SELECT COUNT(*) AS total FROM locations WHERE 1=1 $locationsDateFilter";
$stats['total_locations'] = (int)$db->query($locationQuery)->fetch()['total'];

// 총 사진 수 (tree_photos는 uploaded_at 등으로 필터)
$photoQuery = "SELECT COUNT(*) AS total FROM tree_photos WHERE 1=1 $photosDateFilter";
$stats['total_photos'] = (int)$db->query($photoQuery)->fetch()['total'];

// period_label은 그대로 사용


// 지역별 나무 현황
$regionStatsQuery = "
  SELECT r.region_name, COUNT(t.tree_id) AS tree_count
  FROM regions r
  LEFT JOIN trees t
    ON r.region_id = t.region_id
    $treesOnDate
  GROUP BY r.region_id, r.region_name
  ORDER BY tree_count DESC";
$region_stats = $db->query($regionStatsQuery)->fetchAll();


// 수종별 나무 현황 (상위 10개)
$speciesStatsQuery = "
  SELECT s.korean_name, s.scientific_name, COUNT(t.tree_id) AS tree_count
  FROM tree_species_master s
  LEFT JOIN trees t
    ON s.species_id = t.species_id
    $treesOnDate
  GROUP BY s.species_id, s.korean_name, s.scientific_name
  ORDER BY tree_count DESC
  LIMIT 10";
$species_stats = $db->query($speciesStatsQuery)->fetchAll();


// 건강상태별 나무 현황
$healthStatsQuery = "
  SELECT 
    t.health_status,
    COUNT(*) AS count,
    ROUND(
      COUNT(*) * 100.0 / NULLIF(
        (SELECT COUNT(*) FROM trees t2 WHERE 1=1 $treesWhereDate),
        0
      ), 1
    ) AS percentage
  FROM trees t
  WHERE 1=1 $treesWhereDate
  GROUP BY t.health_status
  ORDER BY 
    CASE t.health_status
      WHEN 'excellent' THEN 1
      WHEN 'good' THEN 2
      WHEN 'fair' THEN 3
      WHEN 'poor' THEN 4
      WHEN 'dead' THEN 5
      ELSE 6
    END";
$health_stats = $db->query($healthStatsQuery)->fetchAll();


// 카테고리별 장소 현황
$categoryStatsQuery = "
  SELECT c.category_name, COUNT(l.location_id) AS location_count
  FROM categories c
  LEFT JOIN locations l
    ON c.category_id = l.category_id
    $locsOnDate
  GROUP BY c.category_id, c.category_name
  ORDER BY location_count DESC";
$category_stats = $db->query($categoryStatsQuery)->fetchAll();

// 월별 등록 추이 (최근 12개월)
// 월별 집계용으로 trees 날짜 컬럼 하나를 선택
$treesMonthCol = $treesDateCol ?? (hasColumn($db,'trees','created_at') ? 'created_at' : null);
if ($treesMonthCol === null) {
  // 후보 더 시도
  foreach (['registered_at','inserted_at','updated_at'] as $c) {
    if (hasColumn($db,'trees',$c)) { $treesMonthCol = $c; break; }
  }
}
if ($treesMonthCol) {
  $monthlyStatsQuery = "
    SELECT DATE_FORMAT(t.`$treesMonthCol`, '%Y-%m') AS month,
           COUNT(*) AS count
    FROM trees t
    WHERE DATE(t.`$treesMonthCol`) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(t.`$treesMonthCol`, '%Y-%m')
    ORDER BY month ASC";
  $monthly_stats = $db->query($monthlyStatsQuery)->fetchAll();
} else {
  $monthly_stats = []; // 날짜컬럼이 없으면 빈 배열
}


// 사용자별 활동 순위 (상위 10명)
$userStatsQuery = "
  SELECT u.name, u.username, COUNT(t.tree_id) AS tree_count
  FROM users u
  LEFT JOIN trees t
    ON u.user_id = t.created_by
    $treesOnDate
  GROUP BY u.user_id, u.name, u.username
  ORDER BY tree_count DESC
  LIMIT 10";
$user_stats = $db->query($userStatsQuery)->fetchAll();


$health_labels = [
    'excellent' => '최상',
    'good' => '양호',
    'fair' => '보통',
    'poor' => '나쁨',
    'dead' => '고사'
];

$health_colors = [
    'excellent' => '#10b981',
    'good' => '#3b82f6',
    'fair' => '#f59e0b',
    'poor' => '#ef4444',
    'dead' => '#6b7280'
];

include '../../includes/header.php';
?>

<style>
.period-selector {
    background: white;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.period-btn {
    padding: 8px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    background: white;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    font-weight: 500;
}
.period-btn:hover {
    border-color: #667eea;
    color: #667eea;
}
.period-btn.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: #667eea;
}
.chart-container {
    background: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.progress-bar {
    background: #f3f4f6;
    border-radius: 10px;
    height: 24px;
    overflow: hidden;
    position: relative;
}
.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    padding: 0 10px;
    color: white;
    font-size: 12px;
    font-weight: 600;
    transition: width 1s ease;
}
.rank-badge {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
}
</style>

<div class="page-header">
    <h2>📊 통계 대시보드</h2>
    <div style="display: flex; gap: 10px;">
        <a href="export.php" class="btn btn-success">📥 엑셀 내보내기</a>
        <button onclick="window.print()" class="btn btn-secondary">🖨️ 인쇄</button>
    </div>
</div>

<!-- 기간 선택 -->
<div class="period-selector">
    <strong style="color: #2c3e50;">기간 선택:</strong>
    <a href="?period=7days" class="period-btn <?php echo $period == '7days' ? 'active' : ''; ?>">최근 7일</a>
    <a href="?period=30days" class="period-btn <?php echo $period == '30days' ? 'active' : ''; ?>">최근 30일</a>
    <a href="?period=90days" class="period-btn <?php echo $period == '90days' ? 'active' : ''; ?>">최근 90일</a>
    <a href="?period=1year" class="period-btn <?php echo $period == '1year' ? 'active' : ''; ?>">최근 1년</a>
    <a href="?period=all" class="period-btn <?php echo $period == 'all' ? 'active' : ''; ?>">전체 기간</a>
    <span style="margin-left: auto; color: #667eea; font-weight: 600;">현재: <?php echo $period_label; ?></span>
</div>

<!-- 전체 통계 카드 -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">🌳</div>
        <div class="stat-info">
            <h3><?php echo number_format($stats['total_trees']); ?></h3>
            <p>총 나무 수</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">🌲</div>
        <div class="stat-info">
            <h3><?php echo number_format($stats['total_species']); ?></h3>
            <p>등록 수종</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">📍</div>
        <div class="stat-info">
            <h3><?php echo number_format($stats['total_locations']); ?></h3>
            <p>관리 장소</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon purple">📷</div>
        <div class="stat-info">
            <h3><?php echo number_format($stats['total_photos']); ?></h3>
            <p>등록 사진</p>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
    <!-- 지역별 현황 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🗺️ 지역별 나무 현황</h3>
        </div>
        <div class="card-body">
            <?php if (count($region_stats) > 0): ?>
                <?php 
                $max_count = max(array_column($region_stats, 'tree_count'));
                foreach ($region_stats as $stat): 
                    $percentage = $max_count > 0 ? ($stat['tree_count'] / $max_count * 100) : 0;
                ?>
                    <div style="margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="font-weight: 600;"><?php echo htmlspecialchars($stat['region_name']); ?></span>
                            <span style="color: #667eea; font-weight: 600;"><?php echo number_format($stat['tree_count']); ?>그루</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $percentage; ?>%;">
                                <?php if ($percentage > 20): ?>
                                    <?php echo number_format($percentage, 1); ?>%
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: #999; padding: 20px;">데이터가 없습니다.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 건강상태별 현황 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">💚 건강상태별 현황</h3>
        </div>
        <div class="card-body">
            <?php if (count($health_stats) > 0): ?>
                <?php foreach ($health_stats as $stat): ?>
                    <div style="margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="font-weight: 600;"><?php echo $health_labels[$stat['health_status']]; ?></span>
                            <span style="font-weight: 600;">
                                <?php echo number_format($stat['count']); ?>그루 
                                <span style="color: #6b7280;">(<?php echo $stat['percentage']; ?>%)</span>
                            </span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" 
                                 style="width: <?php echo $stat['percentage']; ?>%; background: <?php echo $health_colors[$stat['health_status']]; ?>;">
                                <?php if ($stat['percentage'] > 10): ?>
                                    <?php echo $stat['percentage']; ?>%
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: #999; padding: 20px;">데이터가 없습니다.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 수종별 현황 -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">🌲 주요 수종 현황 (상위 10개)</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">순위</th>
                        <th>한글명</th>
                        <th>학명</th>
                        <th style="text-align: right;">나무 수</th>
                        <th style="width: 300px;">비율</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($species_stats) > 0): ?>
                        <?php 
                        $rank = 1;
                        $max_count = $species_stats[0]['tree_count'];
                        foreach ($species_stats as $stat): 
                            $percentage = $max_count > 0 ? ($stat['tree_count'] / $max_count * 100) : 0;
                        ?>
                            <tr>
                                <td style="text-align: center;">
                                    <div class="rank-badge"><?php echo $rank++; ?></div>
                                </td>
                                <td><strong><?php echo htmlspecialchars($stat['korean_name']); ?></strong></td>
                                <td><em><?php echo htmlspecialchars($stat['scientific_name']); ?></em></td>
                                <td style="text-align: right; font-weight: 600; color: #667eea;">
                                    <?php echo number_format($stat['tree_count']); ?>그루
                                </td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $percentage; ?>%;">
                                            <?php if ($percentage > 15): ?>
                                                <?php echo number_format($percentage, 1); ?>%
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: #999;">
                                데이터가 없습니다.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
    <!-- 카테고리별 장소 현황 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📁 카테고리별 장소 현황</h3>
        </div>
        <div class="card-body">
            <?php if (count($category_stats) > 0): ?>
                <?php 
                $max_count = max(array_column($category_stats, 'location_count'));
                foreach ($category_stats as $stat): 
                    $percentage = $max_count > 0 ? ($stat['location_count'] / $max_count * 100) : 0;
                ?>
                    <div style="margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="font-weight: 600;"><?php echo htmlspecialchars($stat['category_name']); ?></span>
                            <span style="color: #667eea; font-weight: 600;"><?php echo number_format($stat['location_count']); ?>개</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $percentage; ?>%;">
                                <?php if ($percentage > 20): ?>
                                    <?php echo number_format($percentage, 1); ?>%
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: #999; padding: 20px;">데이터가 없습니다.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 사용자별 활동 순위 -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🏆 사용자별 활동 순위 (상위 10명)</h3>
        </div>
        <div class="card-body">
            <?php if (count($user_stats) > 0): ?>
                <?php 
                $rank = 1;
                foreach ($user_stats as $stat): 
                ?>
                    <div style="display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f0f0;">
                        <div class="rank-badge" style="margin-right: 15px;"><?php echo $rank++; ?></div>
                        <div style="flex: 1;">
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($stat['name'] ?: $stat['username']); ?></div>
                            <div style="font-size: 13px; color: #6b7280;">@<?php echo htmlspecialchars($stat['username']); ?></div>
                        </div>
                        <div style="font-weight: 700; color: #667eea; font-size: 18px;">
                            <?php echo number_format($stat['tree_count']); ?>그루
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: #999; padding: 20px;">데이터가 없습니다.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 월별 등록 추이 -->
<?php if (count($monthly_stats) > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📈 월별 나무 등록 추이 (최근 12개월)</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; gap: 10px; align-items: flex-end; height: 200px;">
                <?php 
                $max_monthly = max(array_column($monthly_stats, 'count'));
                foreach ($monthly_stats as $stat): 
                    $height = $max_monthly > 0 ? ($stat['count'] / $max_monthly * 100) : 0;
                    $month_label = date('m월', strtotime($stat['month'] . '-01'));
                ?>
                    <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end;">
                        <div style="background: linear-gradient(180deg, #667eea 0%, #764ba2 100%); 
                                    width: 100%; 
                                    height: <?php echo $height; ?>%; 
                                    border-radius: 8px 8px 0 0;
                                    display: flex;
                                    align-items: flex-start;
                                    justify-content: center;
                                    padding-top: 5px;
                                    color: white;
                                    font-weight: 600;
                                    font-size: 12px;
                                    min-height: 30px;">
                            <?php if ($height > 15): ?>
                                <?php echo $stat['count']; ?>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 5px; font-size: 11px; color: #6b7280; font-weight: 600;">
                            <?php echo $month_label; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>

