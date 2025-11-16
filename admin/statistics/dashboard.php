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

// 기간 필터
$period = isset($_GET['period']) ? sanitize($_GET['period']) : '30days';

$date_filter = '';
switch ($period) {
    case '7days':
        $date_filter = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $period_label = '최근 7일';
        break;
    case '30days':
        $date_filter = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $period_label = '최근 30일';
        break;
    case '90days':
        $date_filter = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
        $period_label = '최근 90일';
        break;
    case '1year':
        $date_filter = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $period_label = '최근 1년';
        break;
    case 'all':
        $date_filter = "";
        $period_label = '전체 기간';
        break;
    default:
        $date_filter = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $period_label = '최근 30일';
}

// 전체 통계
$stats = [];

// 총 나무 수
$treeQuery = "SELECT COUNT(*) as total FROM trees WHERE 1=1 {$date_filter}";
$treeStmt = $db->query($treeQuery);
$stats['total_trees'] = $treeStmt->fetch()['total'];

// 총 수종 수
$speciesQuery = "SELECT COUNT(DISTINCT species_id) as total FROM trees WHERE species_id IS NOT NULL {$date_filter}";
$speciesStmt = $db->query($speciesQuery);
$stats['total_species'] = $speciesStmt->fetch()['total'];

// 총 장소 수
$locationQuery = "SELECT COUNT(*) as total FROM locations WHERE 1=1 {$date_filter}";
$locationStmt = $db->query($locationQuery);
$stats['total_locations'] = $locationStmt->fetch()['total'];

// 총 사진 수 (tree_photos는 uploaded_at 사용)
$photo_date_filter = str_replace('created_at', 'uploaded_at', $date_filter);
$photoQuery = "SELECT COUNT(*) as total FROM tree_photos WHERE 1=1 {$photo_date_filter}";
$photoStmt = $db->query($photoQuery);
$stats['total_photos'] = $photoStmt->fetch()['total'];

// 지역별 나무 현황
$tree_date_filter = str_replace('created_at', 't.created_at', $date_filter);
$regionStatsQuery = "SELECT r.region_name, COUNT(t.tree_id) as tree_count
                     FROM regions r
                     LEFT JOIN trees t ON r.region_id = t.region_id
                     WHERE 1=1 {$tree_date_filter}
                     GROUP BY r.region_id, r.region_name
                     ORDER BY tree_count DESC";
$regionStatsStmt = $db->query($regionStatsQuery);
$region_stats = $regionStatsStmt->fetchAll();

// 수종별 나무 현황 (상위 10개)
$speciesStatsQuery = "SELECT s.korean_name, s.scientific_name, COUNT(t.tree_id) as tree_count
                      FROM tree_species_master s
                      LEFT JOIN trees t ON s.species_id = t.species_id
                      WHERE t.tree_id IS NOT NULL {$tree_date_filter}
                      GROUP BY s.species_id, s.korean_name, s.scientific_name
                      ORDER BY tree_count DESC
                      LIMIT 10";
$speciesStatsStmt = $db->query($speciesStatsQuery);
$species_stats = $speciesStatsStmt->fetchAll();

// 건강상태별 나무 현황
$healthStatsQuery = "SELECT 
                         health_status,
                         COUNT(*) as count,
                         ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM trees WHERE 1=1 {$date_filter}), 1) as percentage
                     FROM trees
                     WHERE 1=1 {$date_filter}
                     GROUP BY health_status
                     ORDER BY 
                         CASE health_status
                             WHEN 'excellent' THEN 1
                             WHEN 'good' THEN 2
                             WHEN 'fair' THEN 3
                             WHEN 'poor' THEN 4
                             WHEN 'dead' THEN 5
                         END";
$healthStatsStmt = $db->query($healthStatsQuery);
$health_stats = $healthStatsStmt->fetchAll();

// 카테고리별 장소 현황
$location_date_filter = str_replace('created_at', 'l.created_at', $date_filter);
$categoryStatsQuery = "SELECT c.category_name, COUNT(l.location_id) as location_count
                       FROM categories c
                       LEFT JOIN locations l ON c.category_id = l.category_id
                       WHERE 1=1 {$location_date_filter}
                       GROUP BY c.category_id, c.category_name
                       ORDER BY location_count DESC";
$categoryStatsStmt = $db->query($categoryStatsQuery);
$category_stats = $categoryStatsStmt->fetchAll();

// 월별 등록 추이 (최근 12개월)
$monthlyStatsQuery = "SELECT 
                          DATE_FORMAT(created_at, '%Y-%m') as month,
                          COUNT(*) as count
                      FROM trees
                      WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                      GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                      ORDER BY month ASC";
$monthlyStatsStmt = $db->query($monthlyStatsQuery);
$monthly_stats = $monthlyStatsStmt->fetchAll();

// 사용자별 활동 순위 (상위 10명)
$userStatsQuery = "SELECT u.name, u.username, COUNT(t.tree_id) as tree_count
                   FROM users u
                   LEFT JOIN trees t ON u.user_id = t.created_by
                   WHERE t.tree_id IS NOT NULL {$tree_date_filter}
                   GROUP BY u.user_id, u.name, u.username
                   ORDER BY tree_count DESC
                   LIMIT 10";
$userStatsStmt = $db->query($userStatsQuery);
$user_stats = $userStatsStmt->fetchAll();

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
.stats-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transition: transform 0.3s;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 12px rgba(0, 0, 0, 0.15);
}

.stat-card.green {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.stat-card.blue {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
}

.stat-card.orange {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}

.stat-card.purple {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
}

.stat-value {
    font-size: 36px;
    font-weight: 700;
    margin: 10px 0;
}

.stat-label {
    font-size: 14px;
    opacity: 0.9;
}

.stat-icon {
    font-size: 24px;
    opacity: 0.8;
}

.chart-container {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
}

.chart-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 20px;
    color: #1f2937;
}

.period-selector {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
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
    background: #667eea;
    border-color: #667eea;
    color: white;
}

.export-btn {
    margin-left: auto;
    padding: 10px 20px;
    background: #10b981;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s;
}

.export-btn:hover {
    background: #059669;
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.table-container {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: #f9fafb;
}

.data-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #f3f4f6;
}

.data-table tbody tr:hover {
    background: #f9fafb;
}

.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.badge-excellent { background: #d1fae5; color: #065f46; }
.badge-good { background: #dbeafe; color: #1e40af; }
.badge-fair { background: #fef3c7; color: #92400e; }
.badge-poor { background: #fee2e2; color: #991b1b; }
.badge-dead { background: #f3f4f6; color: #374151; }

@media (max-width: 768px) {
    .stats-container {
        grid-template-columns: 1fr;
    }
    
    .period-selector {
        flex-direction: column;
        align-items: stretch;
    }
    
    .export-btn {
        margin-left: 0;
        width: 100%;
    }
}
</style>

<div class="container" style="padding: 30px;">
    <h1 style="margin-bottom: 10px;">📊 통계 대시보드</h1>
    <p style="color: #6b7280; margin-bottom: 30px;">신안군 스마트 트리맵 종합 통계</p>

    <!-- 기간 선택 -->
    <div class="period-selector">
        <span style="font-weight: 600; color: #374151;">기간 선택:</span>
        <a href="?period=7days" class="period-btn <?php echo $period == '7days' ? 'active' : ''; ?>">최근 7일</a>
        <a href="?period=30days" class="period-btn <?php echo $period == '30days' ? 'active' : ''; ?>">최근 30일</a>
        <a href="?period=90days" class="period-btn <?php echo $period == '90days' ? 'active' : ''; ?>">최근 90일</a>
        <a href="?period=1year" class="period-btn <?php echo $period == '1year' ? 'active' : ''; ?>">최근 1년</a>
        <a href="?period=all" class="period-btn <?php echo $period == 'all' ? 'active' : ''; ?>">전체 기간</a>
        
        <button onclick="exportStatistics()" class="export-btn">
            📥 엑셀 다운로드
        </button>
    </div>

    <!-- 통계 카드 -->
    <div class="stats-container">
        <div class="stat-card green">
            <div class="stat-icon">🌳</div>
            <div class="stat-value"><?php echo number_format($stats['total_trees']); ?></div>
            <div class="stat-label">총 나무 수</div>
        </div>

        <div class="stat-card blue">
            <div class="stat-icon">📍</div>
            <div class="stat-value"><?php echo number_format($stats['total_locations']); ?></div>
            <div class="stat-label">총 장소 수</div>
        </div>

        <div class="stat-card orange">
            <div class="stat-icon">🌿</div>
            <div class="stat-value"><?php echo number_format($stats['total_species']); ?></div>
            <div class="stat-label">총 수종 수</div>
        </div>

        <div class="stat-card purple">
            <div class="stat-icon">📸</div>
            <div class="stat-value"><?php echo number_format($stats['total_photos']); ?></div>
            <div class="stat-label">총 사진 수</div>
        </div>
    </div>



    <!-- 지역별 통계 테이블 -->
    <div class="chart-container">
        <h3 class="chart-title">🗺️ 지역별 나무 현황</h3>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>순위</th>
                        <th>지역명</th>
                        <th>나무 수</th>
                        <th>비율</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    foreach ($region_stats as $stat): 
                        $percentage = $stats['total_trees'] > 0 ? round(($stat['tree_count'] / $stats['total_trees']) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td><strong><?php echo $rank++; ?></strong></td>
                            <td><?php echo htmlspecialchars($stat['region_name']); ?></td>
                            <td><strong><?php echo number_format($stat['tree_count']); ?>그루</strong></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="flex: 1; background: #e5e7eb; height: 8px; border-radius: 4px; overflow: hidden;">
                                        <div style="width: <?php echo $percentage; ?>%; background: #667eea; height: 100%;"></div>
                                    </div>
                                    <span style="font-weight: 600; color: #667eea;"><?php echo $percentage; ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 수종별 통계 -->
    <div class="chart-container">
        <h3 class="chart-title">🌿 수종별 나무 현황 (상위 10개)</h3>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>순위</th>
                        <th>한글명</th>
                        <th>학명</th>
                        <th>나무 수</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    foreach ($species_stats as $stat): 
                    ?>
                        <tr>
                            <td><strong><?php echo $rank++; ?></strong></td>
                            <td><?php echo htmlspecialchars($stat['korean_name']); ?></td>
                            <td style="color: #6b7280; font-style: italic;"><?php echo htmlspecialchars($stat['scientific_name']); ?></td>
                            <td><strong><?php echo number_format($stat['tree_count']); ?>그루</strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

	    <!-- 차트 영역 -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 20px; margin-bottom: 20px;">
        <!-- 건강상태별 차트 -->
        <div class="chart-container">
            <h3 class="chart-title">🏥 건강상태별 나무 현황</h3>
            <canvas id="healthChart" height="250"></canvas>
        </div>

        <!-- 카테고리별 차트 -->
        <div class="chart-container">
            <h3 class="chart-title">📂 카테고리별 장소 현황</h3>
            <canvas id="categoryChart" height="250"></canvas>
        </div>
    </div>

    <!-- 월별 추이 차트 -->
    <div class="chart-container">
        <h3 class="chart-title">📈 월별 나무 등록 추이 (최근 12개월)</h3>
        <canvas id="monthlyChart" height="100"></canvas>
    </div>

    <!-- 사용자 활동 랭킹 -->
    <?php if (count($user_stats) > 0): ?>
    <div class="chart-container">
        <h3 class="chart-title">🏆 사용자별 활동 랭킹 (상위 10명)</h3>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>순위</th>
                        <th>사용자</th>
                        <th>등록한 나무 수</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    foreach ($user_stats as $stat): 
                    ?>
                        <tr>
                            <td>
                                <?php if ($rank <= 3): ?>
                                    <span style="font-size: 20px;">
                                        <?php echo $rank == 1 ? '🥇' : ($rank == 2 ? '🥈' : '🥉'); ?>
                                    </span>
                                <?php else: ?>
                                    <strong><?php echo $rank; ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($stat['name'] ?: $stat['username']); ?></div>
                                <div style="font-size: 13px; color: #6b7280;">@<?php echo htmlspecialchars($stat['username']); ?></div>
                            </td>
                            <td><strong style="color: #667eea;"><?php echo number_format($stat['tree_count']); ?>그루</strong></td>
                        </tr>
                    <?php 
                    $rank++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
// 건강상태 차트
const healthCtx = document.getElementById('healthChart').getContext('2d');
new Chart(healthCtx, {
    type: 'doughnut',
    data: {
        labels: [
            <?php foreach ($health_stats as $stat): ?>
                '<?php echo $health_labels[$stat['health_status']]; ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            data: [
                <?php foreach ($health_stats as $stat): ?>
                    <?php echo $stat['count']; ?>,
                <?php endforeach; ?>
            ],
            backgroundColor: [
                <?php foreach ($health_stats as $stat): ?>
                    '<?php echo $health_colors[$stat['health_status']]; ?>',
                <?php endforeach; ?>
            ],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    padding: 15,
                    font: { size: 13 }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.parsed || 0;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((value / total) * 100).toFixed(1);
                        return label + ': ' + value + '그루 (' + percentage + '%)';
                    }
                }
            }
        }
    }
});

// 카테고리별 차트
const categoryCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(categoryCtx, {
    type: 'pie',
    data: {
        labels: [
            <?php foreach ($category_stats as $stat): ?>
                '<?php echo addslashes($stat['category_name']); ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            data: [
                <?php foreach ($category_stats as $stat): ?>
                    <?php echo $stat['location_count']; ?>,
                <?php endforeach; ?>
            ],
            backgroundColor: [
                '#667eea', '#764ba2', '#f093fb', '#4facfe'
            ],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    padding: 15,
                    font: { size: 13 }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.label + ': ' + context.parsed + '곳';
                    }
                }
            }
        }
    }
});

// 월별 추이 차트
const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
new Chart(monthlyCtx, {
    type: 'line',
    data: {
        labels: [
            <?php foreach ($monthly_stats as $stat): ?>
                '<?php echo date('Y년 m월', strtotime($stat['month'] . '-01')); ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            label: '나무 등록 수',
            data: [
                <?php foreach ($monthly_stats as $stat): ?>
                    <?php echo $stat['count']; ?>,
                <?php endforeach; ?>
            ],
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#667eea',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return '등록 수: ' + context.parsed.y + '그루';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});

// 엑셀 내보내기
function exportStatistics() {
    const period = '<?php echo $period; ?>';
    const periodLabel = '<?php echo $period_label; ?>';
    const exportUrl = '../export/statistics.php?period=' + period;
    
    if (confirm('[' + periodLabel + '] 통계 데이터를 엑셀로 내보내시겠습니까?\n\n포함 내용:\n- 전체 요약\n- 지역별 통계\n- 수종별 통계\n- 건강상태별 통계\n- 카테고리별 통계\n- 월별 추이')) {
        window.location.href = exportUrl;
    }
}
</script>

<?php include '../../includes/footer.php'; ?>