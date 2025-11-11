<?php
/**
 * 통계 데이터 엑셀 내보내기 (수정본)
 * Smart Tree Map - Sinan County
 */
require_once '../../config/config.php';
require_once '../../includes/auth.php';

require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;



checkAuth();

$database = new Database();
$db = $database->getConnection();

// 기간 필터
$period = isset($_GET['period']) ? sanitize($_GET['period']) : '30days';

$date_filter = '';
switch ($period) {
    case '7days':
        $date_filter = "AND DATE(t.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $period_label = '최근 7일';
        break;
    case '30days':
        $date_filter = "AND DATE(t.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $period_label = '최근 30일';
        break;
    case '90days':
        $date_filter = "AND DATE(t.created_at) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
        $period_label = '최근 90일';
        break;
    case '1year':
        $date_filter = "AND DATE(t.created_at) >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $period_label = '최근 1년';
        break;
    case 'all':
        $date_filter = "";
        $period_label = '전체 기간';
        break;
    default:
        $date_filter = "AND DATE(t.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $period_label = '최근 30일';
}

// 스프레드시트 생성
$spreadsheet = new Spreadsheet();

// ========================================
// Sheet 1: 전체 요약
// ========================================
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('전체 요약');

// 제목
$sheet1->setCellValue('A1', '신안군 스마트 트리맵 - 통계 요약');
$sheet1->mergeCells('A1:D1');
$sheet1->getStyle('A1')->getFont()->setSize(16)->setBold(true);
$sheet1->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet1->setCellValue('A2', '기간: ' . $period_label . ' | 내보내기: ' . date('Y-m-d H:i:s'));
$sheet1->mergeCells('A2:D2');
$sheet1->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// 전체 통계
$stats = [];

// 총 나무 수
$treeQuery = "SELECT COUNT(*) as total FROM trees t WHERE 1=1 {$date_filter}";
$stats['total_trees'] = $db->query($treeQuery)->fetch()['total'];

// 총 수종 수
$speciesQuery = "SELECT COUNT(DISTINCT species_id) as total FROM trees t WHERE species_id IS NOT NULL {$date_filter}";
$stats['total_species'] = $db->query($speciesQuery)->fetch()['total'];

// 총 장소 수
$location_filter = str_replace('t.created_at', 'l.created_at', $date_filter);
$locationQuery = "SELECT COUNT(*) as total FROM locations l WHERE 1=1 {$location_filter}";
$stats['total_locations'] = $db->query($locationQuery)->fetch()['total'];

// 총 사진 수 (tree_photos의 uploaded_at 사용)
$photo_filter = str_replace('t.created_at', 'tp.uploaded_at', $date_filter);
$photoQuery = "SELECT COUNT(*) as total FROM tree_photos tp WHERE 1=1 {$photo_filter}";
$stats['total_photos'] = $db->query($photoQuery)->fetch()['total'];

// 요약 테이블
$sheet1->setCellValue('A4', '항목');
$sheet1->setCellValue('B4', '수량');
$sheet1->getStyle('A4:B4')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']]
]);

$sheet1->setCellValue('A5', '총 나무 수');
$sheet1->setCellValue('B5', number_format($stats['total_trees']) . ' 그루');

$sheet1->setCellValue('A6', '총 수종 수');
$sheet1->setCellValue('B6', number_format($stats['total_species']) . ' 종');

$sheet1->setCellValue('A7', '총 장소 수');
$sheet1->setCellValue('B7', number_format($stats['total_locations']) . ' 곳');

$sheet1->setCellValue('A8', '총 사진 수');
$sheet1->setCellValue('B8', number_format($stats['total_photos']) . ' 장');

$sheet1->getColumnDimension('A')->setWidth(20);
$sheet1->getColumnDimension('B')->setWidth(20);

// ========================================
// Sheet 2: 지역별 통계
// ========================================
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('지역별 통계');

$regionStatsQuery = "SELECT r.region_name, COUNT(t.tree_id) as tree_count
                     FROM regions r
                     LEFT JOIN trees t ON r.region_id = t.region_id
                     WHERE 1=1 {$date_filter}
                     GROUP BY r.region_id, r.region_name
                     ORDER BY tree_count DESC";
$regionStats = $db->query($regionStatsQuery)->fetchAll();

$sheet2->setCellValue('A1', '지역별 나무 현황');
$sheet2->getStyle('A1')->getFont()->setSize(14)->setBold(true);

$sheet2->setCellValue('A3', '지역명');
$sheet2->setCellValue('B3', '나무 수');
$sheet2->getStyle('A3:B3')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']]
]);

$row = 4;
foreach ($regionStats as $stat) {
    $sheet2->setCellValue('A' . $row, $stat['region_name']);
    $sheet2->setCellValue('B' . $row, number_format($stat['tree_count']));
    $row++;
}

$sheet2->getColumnDimension('A')->setWidth(20);
$sheet2->getColumnDimension('B')->setWidth(15);

// ========================================
// Sheet 3: 수종별 통계
// ========================================
$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle('수종별 통계');

$speciesStatsQuery = "SELECT s.korean_name, s.scientific_name, COUNT(t.tree_id) as tree_count
                      FROM tree_species_master s
                      LEFT JOIN trees t ON s.species_id = t.species_id
                      WHERE t.tree_id IS NOT NULL {$date_filter}
                      GROUP BY s.species_id, s.korean_name, s.scientific_name
                      ORDER BY tree_count DESC
                      LIMIT 20";
$speciesStats = $db->query($speciesStatsQuery)->fetchAll();

$sheet3->setCellValue('A1', '수종별 나무 현황 (상위 20개)');
$sheet3->getStyle('A1')->getFont()->setSize(14)->setBold(true);

$sheet3->setCellValue('A3', '한글명');
$sheet3->setCellValue('B3', '학명');
$sheet3->setCellValue('C3', '나무 수');
$sheet3->getStyle('A3:C3')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']]
]);

$row = 4;
foreach ($speciesStats as $stat) {
    $sheet3->setCellValue('A' . $row, $stat['korean_name']);
    $sheet3->setCellValue('B' . $row, $stat['scientific_name']);
    $sheet3->setCellValue('C' . $row, number_format($stat['tree_count']));
    $row++;
}

$sheet3->getColumnDimension('A')->setWidth(20);
$sheet3->getColumnDimension('B')->setWidth(30);
$sheet3->getColumnDimension('C')->setWidth(15);

// ========================================
// Sheet 4: 건강상태별 통계
// ========================================
$sheet4 = $spreadsheet->createSheet();
$sheet4->setTitle('건강상태별 통계');

$healthStatsQuery = "SELECT 
                         health_status,
                         COUNT(*) as count,
                         ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM trees t WHERE 1=1 {$date_filter}), 1) as percentage
                     FROM trees t
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
$healthStats = $db->query($healthStatsQuery)->fetchAll();

$health_labels = [
    'excellent' => '최상',
    'good' => '양호',
    'fair' => '보통',
    'poor' => '나쁨',
    'dead' => '고사'
];

$sheet4->setCellValue('A1', '건강상태별 나무 현황');
$sheet4->getStyle('A1')->getFont()->setSize(14)->setBold(true);

$sheet4->setCellValue('A3', '건강상태');
$sheet4->setCellValue('B3', '나무 수');
$sheet4->setCellValue('C3', '비율(%)');
$sheet4->getStyle('A3:C3')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']]
]);

$row = 4;
foreach ($healthStats as $stat) {
    $status_text = isset($health_labels[$stat['health_status']]) ? $health_labels[$stat['health_status']] : $stat['health_status'];
    $sheet4->setCellValue('A' . $row, $status_text);
    $sheet4->setCellValue('B' . $row, number_format($stat['count']));
    $sheet4->setCellValue('C' . $row, $stat['percentage'] . '%');
    $row++;
}

$sheet4->getColumnDimension('A')->setWidth(15);
$sheet4->getColumnDimension('B')->setWidth(15);
$sheet4->getColumnDimension('C')->setWidth(15);

// ========================================
// Sheet 5: 카테고리별 장소 통계
// ========================================
$sheet5 = $spreadsheet->createSheet();
$sheet5->setTitle('카테고리별 장소');

$categoryStatsQuery = "SELECT c.category_name, COUNT(l.location_id) as location_count, COUNT(t.tree_id) as tree_count
                       FROM categories c
                       LEFT JOIN locations l ON c.category_id = l.category_id
                       LEFT JOIN trees t ON l.location_id = t.location_id
                       WHERE 1=1 {$date_filter}
                       GROUP BY c.category_id, c.category_name
                       ORDER BY location_count DESC";
$categoryStats = $db->query($categoryStatsQuery)->fetchAll();

$sheet5->setCellValue('A1', '카테고리별 장소 및 나무 현황');
$sheet5->getStyle('A1')->getFont()->setSize(14)->setBold(true);

$sheet5->setCellValue('A3', '카테고리');
$sheet5->setCellValue('B3', '장소 수');
$sheet5->setCellValue('C3', '나무 수');
$sheet5->getStyle('A3:C3')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']]
]);

$row = 4;
foreach ($categoryStats as $stat) {
    $sheet5->setCellValue('A' . $row, $stat['category_name']);
    $sheet5->setCellValue('B' . $row, number_format($stat['location_count']));
    $sheet5->setCellValue('C' . $row, number_format($stat['tree_count']));
    $row++;
}

$sheet5->getColumnDimension('A')->setWidth(20);
$sheet5->getColumnDimension('B')->setWidth(15);
$sheet5->getColumnDimension('C')->setWidth(15);

// ========================================
// Sheet 6: 월별 추이
// ========================================
$sheet6 = $spreadsheet->createSheet();
$sheet6->setTitle('월별 추이');

$monthlyStatsQuery = "SELECT 
                          DATE_FORMAT(created_at, '%Y-%m') as month,
                          COUNT(*) as count
                      FROM trees
                      WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                      GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                      ORDER BY month ASC";
$monthlyStats = $db->query($monthlyStatsQuery)->fetchAll();

$sheet6->setCellValue('A1', '월별 나무 등록 추이 (최근 12개월)');
$sheet6->getStyle('A1')->getFont()->setSize(14)->setBold(true);

$sheet6->setCellValue('A3', '월');
$sheet6->setCellValue('B3', '등록 수');
$sheet6->getStyle('A3:B3')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']]
]);

$row = 4;
foreach ($monthlyStats as $stat) {
    $sheet6->setCellValue('A' . $row, $stat['month']);
    $sheet6->setCellValue('B' . $row, number_format($stat['count']));
    $row++;
}

$sheet6->getColumnDimension('A')->setWidth(15);
$sheet6->getColumnDimension('B')->setWidth(15);

// 활동 로그
logActivity($_SESSION['user_id'], 'export', 'statistics', null, '통계 데이터 엑셀 내보내기');

// 파일명
$filename = '신안군_통계데이터_' . date('Ymd_His') . '.xlsx';

// 다운로드
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

exit;