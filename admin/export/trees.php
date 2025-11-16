<?php
/**
 * 나무 데이터 엑셀 내보내기
 * Smart Tree Map - Sinan County
 */

// 완전한 에러 억제
@ini_set('display_errors', 0);
@ini_set('display_startup_errors', 0);
@error_reporting(0);

// 출력 버퍼 시작
ob_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';

// Composer autoload
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

checkAuth();

$database = new Database();
$db = $database->getConnection();

// 필터 파라미터
$region_id = isset($_GET['region_id']) ? (int)$_GET['region_id'] : 0;
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$location_id = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
$species_id = isset($_GET['species_id']) ? (int)$_GET['species_id'] : 0;
$health_status = isset($_GET['health_status']) ? sanitize($_GET['health_status']) : '';
$start_date = isset($_GET['start_date']) ? sanitize($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? sanitize($_GET['end_date']) : '';

// 쿼리 생성
$query = "SELECT 
            t.tree_id,
            t.tree_number,
            r.region_name,
            c.category_name,
            l.location_name,
            s.korean_name as species_korean,
            s.scientific_name as species_scientific,
            t.height,
            t.diameter,
            t.planting_date,
            CASE t.health_status
                WHEN 'excellent' THEN '최상'
                WHEN 'good' THEN '양호'
                WHEN 'fair' THEN '보통'
                WHEN 'poor' THEN '나쁨'
                WHEN 'dead' THEN '고사'
                ELSE '-'
            END as health_status_text,
            t.latitude,
            t.longitude,
            t.notes,
            u.name as created_by_name,
            DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i') as created_at,
            DATE_FORMAT(t.updated_at, '%Y-%m-%d %H:%i') as updated_at
          FROM trees t
          LEFT JOIN regions r ON t.region_id = r.region_id
          LEFT JOIN categories c ON t.category_id = c.category_id
          LEFT JOIN locations l ON t.location_id = l.location_id
          LEFT JOIN tree_species_master s ON t.species_id = s.species_id
          LEFT JOIN users u ON t.created_by = u.user_id
          WHERE 1=1";

// 필터 조건 추가
$params = [];

if ($region_id > 0) {
    $query .= " AND t.region_id = :region_id";
    $params[':region_id'] = $region_id;
}

if ($category_id > 0) {
    $query .= " AND t.category_id = :category_id";
    $params[':category_id'] = $category_id;
}

if ($location_id > 0) {
    $query .= " AND t.location_id = :location_id";
    $params[':location_id'] = $location_id;
}

if ($species_id > 0) {
    $query .= " AND t.species_id = :species_id";
    $params[':species_id'] = $species_id;
}

if (!empty($health_status)) {
    $query .= " AND t.health_status = :health_status";
    $params[':health_status'] = $health_status;
}

if (!empty($start_date)) {
    $query .= " AND DATE(t.created_at) >= :start_date";
    $params[':start_date'] = $start_date;
}

if (!empty($end_date)) {
    $query .= " AND DATE(t.created_at) <= :end_date";
    $params[':end_date'] = $end_date;
}

$query .= " ORDER BY t.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$trees = $stmt->fetchAll();

// 스프레드시트 생성
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 문서 속성
$spreadsheet->getProperties()
    ->setCreator('Smart Tree Map')
    ->setTitle('신안군 나무 데이터')
    ->setSubject('나무 관리 데이터 내보내기')
    ->setDescription('신안군 스마트 트리맵 나무 데이터');

// 제목 행
$sheet->setCellValue('A1', '신안군 스마트 트리맵 - 나무 데이터');
$sheet->mergeCells('A1:T1');
$sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// 내보내기 정보
$exportInfo = '내보내기 일시: ' . date('Y-m-d H:i:s') . ' | ';
$exportInfo .= '내보낸 사용자: ' . $_SESSION['username'] . ' | ';
$exportInfo .= '총 ' . count($trees) . '개 데이터';

$sheet->setCellValue('A2', $exportInfo);
$sheet->mergeCells('A2:T2');
$sheet->getStyle('A2')->getFont()->setSize(10);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// 헤더 행 (4행부터 시작)
$headers = [
    'A4' => '번호',
    'B4' => '나무번호',
    'C4' => '지역',
    'D4' => '카테고리',
    'E4' => '장소',
    'F4' => '수종(한글명)',
    'G4' => '수종(학명)',
    'H4' => '수고(m)',
    'I4' => '흉고직경(cm)',
    'J4' => '식재일',
    'K4' => '건강상태',
    'L4' => '위도',
    'M4' => '경도',
    'N4' => '비고',
    'O4' => '등록자',
    'P4' => '등록일시',
    'Q4' => '수정일시'
];

foreach ($headers as $cell => $value) {
    $sheet->setCellValue($cell, $value);
}

// 헤더 스타일
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 11
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '4472C4']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
];

$sheet->getStyle('A4:Q4')->applyFromArray($headerStyle);

// 데이터 행 (5행부터 시작)
$row = 5;
$index = 1;

foreach ($trees as $tree) {
    $sheet->setCellValue('A' . $row, $index);
    $sheet->setCellValue('B' . $row, $tree['tree_number'] ?: '-');
    $sheet->setCellValue('C' . $row, $tree['region_name'] ?: '-');
    $sheet->setCellValue('D' . $row, $tree['category_name'] ?: '-');
    $sheet->setCellValue('E' . $row, $tree['location_name'] ?: '-');
    $sheet->setCellValue('F' . $row, $tree['species_korean'] ?: '-');
    $sheet->setCellValue('G' . $row, $tree['species_scientific'] ?: '-');
    $sheet->setCellValue('H' . $row, $tree['height'] ?: '-');
    $sheet->setCellValue('I' . $row, $tree['diameter'] ?: '-');
    $sheet->setCellValue('J' . $row, $tree['planting_date'] ?: '-');
    $sheet->setCellValue('K' . $row, $tree['health_status_text']);
    $sheet->setCellValue('L' . $row, $tree['latitude'] ?: '-');
    $sheet->setCellValue('M' . $row, $tree['longitude'] ?: '-');
    $sheet->setCellValue('N' . $row, $tree['notes'] ?: '-');
    $sheet->setCellValue('O' . $row, $tree['created_by_name'] ?: '-');
    $sheet->setCellValue('P' . $row, $tree['created_at']);
    $sheet->setCellValue('Q' . $row, $tree['updated_at']);
    
    $row++;
    $index++;
}

// 데이터 영역 스타일
if ($row > 5) {
    $dataStyle = [
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'CCCCCC']
            ]
        ]
    ];
    
    $sheet->getStyle('A5:Q' . ($row - 1))->applyFromArray($dataStyle);
    
    // 숫자 열 중앙 정렬
    $sheet->getStyle('A5:A' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('H5:J' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('K5:K' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// 컬럼 너비 자동 조정
$columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q'];
foreach ($columns as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// 행 높이
$sheet->getRowDimension(1)->setRowHeight(25);
$sheet->getRowDimension(2)->setRowHeight(20);
$sheet->getRowDimension(4)->setRowHeight(20);

// 파일명 생성
$filename = '신안군_나무데이터_' . date('Ymd_His') . '.xlsx';

// 활동 로그
logActivity($_SESSION['user_id'], 'export', 'trees', null, '나무 데이터 ' . count($trees) . '건 엑셀 내보내기');

// 메모리에 엑셀 파일 생성
$writer = new Xlsx($spreadsheet);

// 임시 파일에 저장
$tempFile = tempnam(sys_get_temp_dir(), 'excel_');
$writer->save($tempFile);

// 모든 출력 버퍼 제거
while (ob_get_level()) {
    ob_end_clean();
}

// 파일 크기 확인
$fileSize = filesize($tempFile);

// 다운로드 헤더
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: max-age=0');
header('Pragma: public');

// 파일 출력
readfile($tempFile);

// 임시 파일 삭제
unlink($tempFile);

exit;