<?php
/**
 * 장소 데이터 엑셀 내보내기
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

// 필터 파라미터
$region_id = isset($_GET['region_id']) ? (int)$_GET['region_id'] : 0;
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// 쿼리 생성
$query = "SELECT 
            l.location_id,
            l.location_name,
            r.region_name,
            c.category_name,
            l.address,
            l.area,
            l.length,
            l.width,
            l.establishment_year,
            l.management_agency,
            l.latitude,
            l.longitude,
            l.description,
            (SELECT COUNT(*) FROM location_photos WHERE location_id = l.location_id AND photo_type = 'image') as photo_count,
            (SELECT COUNT(*) FROM location_photos WHERE location_id = l.location_id AND photo_type = 'vr360') as vr_count,
            (SELECT COUNT(*) FROM trees WHERE location_id = l.location_id) as tree_count,
            DATE_FORMAT(l.created_at, '%Y-%m-%d %H:%i') as created_at
          FROM locations l
          LEFT JOIN regions r ON l.region_id = r.region_id
          LEFT JOIN categories c ON l.category_id = c.category_id
          WHERE 1=1";

$params = [];

if ($region_id > 0) {
    $query .= " AND l.region_id = :region_id";
    $params[':region_id'] = $region_id;
}

if ($category_id > 0) {
    $query .= " AND l.category_id = :category_id";
    $params[':category_id'] = $category_id;
}

$query .= " ORDER BY l.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$locations = $stmt->fetchAll();

// 스프레드시트 생성
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 문서 속성
$spreadsheet->getProperties()
    ->setCreator('Smart Tree Map')
    ->setTitle('신안군 장소 데이터')
    ->setSubject('장소 관리 데이터 내보내기')
    ->setDescription('신안군 스마트 트리맵 장소 데이터');

// 제목 행
$sheet->setCellValue('A1', '신안군 스마트 트리맵 - 장소 데이터');
$sheet->mergeCells('A1:P1');
$sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// 내보내기 정보
$exportInfo = '내보내기 일시: ' . date('Y-m-d H:i:s') . ' | ';
$exportInfo .= '내보낸 사용자: ' . $_SESSION['username'] . ' | ';
$exportInfo .= '총 ' . count($locations) . '개 장소';

$sheet->setCellValue('A2', $exportInfo);
$sheet->mergeCells('A2:P2');
$sheet->getStyle('A2')->getFont()->setSize(10);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// 헤더 행
$headers = [
    'A4' => '번호',
    'B4' => '장소명',
    'C4' => '지역',
    'D4' => '카테고리',
    'E4' => '주소',
    'F4' => '넓이(㎡)',
    'G4' => '길이(m)',
    'H4' => '폭(m)',
    'I4' => '조성년도',
    'J4' => '관리기관',
    'K4' => '위도',
    'L4' => '경도',
    'M4' => '일반사진',
    'N4' => 'VR사진',
    'O4' => '나무수',
    'P4' => '등록일시'
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

$sheet->getStyle('A4:P4')->applyFromArray($headerStyle);

// 데이터 행
$row = 5;
$index = 1;

foreach ($locations as $location) {
    $sheet->setCellValue('A' . $row, $index);
    $sheet->setCellValue('B' . $row, $location['location_name']);
    $sheet->setCellValue('C' . $row, $location['region_name'] ?: '-');
    $sheet->setCellValue('D' . $row, $location['category_name'] ?: '-');
    $sheet->setCellValue('E' . $row, $location['address'] ?: '-');
    $sheet->setCellValue('F' . $row, $location['area'] ?: '-');
    $sheet->setCellValue('G' . $row, $location['length'] ?: '-');
    $sheet->setCellValue('H' . $row, $location['width'] ?: '-');
    $sheet->setCellValue('I' . $row, $location['establishment_year'] ?: '-');
    $sheet->setCellValue('J' . $row, $location['management_agency'] ?: '-');
    $sheet->setCellValue('K' . $row, $location['latitude'] ?: '-');
    $sheet->setCellValue('L' . $row, $location['longitude'] ?: '-');
    $sheet->setCellValue('M' . $row, $location['photo_count'] . '장');
    $sheet->setCellValue('N' . $row, $location['vr_count'] . '장');
    $sheet->setCellValue('O' . $row, $location['tree_count'] . '그루');
    $sheet->setCellValue('P' . $row, $location['created_at']);
    
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
    
    $sheet->getStyle('A5:P' . ($row - 1))->applyFromArray($dataStyle);
    
    // 숫자 열 중앙 정렬
    $sheet->getStyle('A5:A' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('F5:O' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// 컬럼 너비 자동 조정
$columns = range('A', 'P');
foreach ($columns as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// 행 높이
$sheet->getRowDimension(1)->setRowHeight(25);
$sheet->getRowDimension(2)->setRowHeight(20);
$sheet->getRowDimension(4)->setRowHeight(20);

// 파일명
$filename = '신안군_장소데이터_' . date('Ymd_His') . '.xlsx';

// 활동 로그
logActivity($_SESSION['user_id'], 'export', 'locations', null, '장소 데이터 ' . count($locations) . '건 엑셀 내보내기');

// 다운로드
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

exit;