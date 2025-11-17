<?php
/**
 * 장소 관리대장 출력 (Excel)
 * Smart Tree Map - Location Management
 */

// 에러 출력 완전 차단
@ini_set('display_errors', 0);
@ini_set('display_startup_errors', 0);
@error_reporting(0);

// 출력 버퍼링 시작
ob_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

checkAuth();

$database = new Database();
$db = $database->getConnection();

// 장소 ID 확인
$location_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$location_id) {
    die('잘못된 접근입니다.');
}

// 장소 정보 조회
$location_query = "SELECT l.*, 
                   c.category_name,
                   r.region_name,
                   COUNT(DISTINCT lt.species_id) as species_count,
                   COALESCE(SUM(lt.quantity), 0) as total_trees
                   FROM locations l
                   LEFT JOIN categories c ON l.category_id = c.category_id
                   LEFT JOIN regions r ON l.region_id = r.region_id
                   LEFT JOIN location_trees lt ON l.location_id = lt.location_id
                   WHERE l.location_id = :location_id
                   GROUP BY l.location_id";

$location_stmt = $db->prepare($location_query);
$location_stmt->bindParam(':location_id', $location_id);
$location_stmt->execute();
$location = $location_stmt->fetch();

if (!$location) {
    die('장소를 찾을 수 없습니다.');
}

// 수목 현황 조회
$trees_query = "SELECT 
                lt.*,
                s.korean_name,
                s.scientific_name
                FROM location_trees lt
                JOIN tree_species_master s ON lt.species_id = s.species_id
                WHERE lt.location_id = :location_id
                ORDER BY lt.quantity DESC, s.korean_name ASC";

$trees_stmt = $db->prepare($trees_query);
$trees_stmt->bindParam(':location_id', $location_id);
$trees_stmt->execute();
$trees = $trees_stmt->fetchAll();

// 엑셀 생성
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 장소 유형 라벨
$type_labels = [
    'urban_forest' => '도시숲',
    'street_tree' => '가로수',
    'living_forest' => '생활숲',
    'school' => '학교',
    'park' => '공원',
    'other' => '기타'
];

// ========== 제목 및 기본 정보 ==========
$row = 1;

// 제목
$sheet->setCellValue("A{$row}", '장소 관리대장');
$sheet->mergeCells("A{$row}:H{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 18
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ]
]);
$sheet->getRowDimension($row)->setRowHeight(40);

$row += 2;

// 기본 정보 테이블
$info_data = [
    ['장소명', $location['location_name'], '지역', $location['region_name']],
    ['카테고리', $location['category_name'], '장소 유형', $type_labels[$location['location_type']] ?? '기타'],
    ['주소', $location['address'] ?? '-', '도로명', $location['road_name'] ?? '-'],
];

if ($location['area']) {
    $info_data[] = ['면적', number_format($location['area']) . '㎡', '총 연장', $location['length'] ? number_format($location['length']) . 'm' : '-'];
}

if ($location['section_start'] || $location['section_end']) {
    $info_data[] = ['시점', $location['section_start'] ?? '-', '종점', $location['section_end'] ?? '-'];
}

if ($location['manager_name'] || $location['manager_contact']) {
    $info_data[] = ['관리 책임자', $location['manager_name'] ?? '-', '연락처', $location['manager_contact'] ?? '-'];
}

foreach ($info_data as $info_row) {
    // 라벨 (A, C)
    $sheet->setCellValue("A{$row}", $info_row[0]);
    $sheet->setCellValue("C{$row}", $info_row[2]);
    
    // 값 (B, D-H)
    $sheet->setCellValue("B{$row}", $info_row[1]);
    $sheet->mergeCells("B{$row}:B{$row}");
    
    $sheet->setCellValue("D{$row}", $info_row[3]);
    $sheet->mergeCells("D{$row}:H{$row}");
    
    // 라벨 스타일
    $sheet->getStyle("A{$row}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E5E7EB']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);
    
    $sheet->getStyle("C{$row}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E5E7EB']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);
    
    // 테두리
    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000']
            ]
        ]
    ]);
    
    $row++;
}

$row += 2;

// ========== 통계 요약 ==========
$sheet->setCellValue("A{$row}", '수목 현황 요약');
$sheet->mergeCells("A{$row}:H{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 14
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'DBEAFE']
    ]
]);
$sheet->getRowDimension($row)->setRowHeight(30);

$row++;

// 통계 데이터
$stats_data = [
    ['항목', '수량'],
    ['총 수종 수', number_format($location['species_count']) . '종'],
    ['총 나무 수', number_format($location['total_trees']) . '주']
];

foreach ($stats_data as $stat_row) {
    $sheet->setCellValue("A{$row}", $stat_row[0]);
    $sheet->setCellValue("B{$row}", $stat_row[1]);
    $sheet->mergeCells("B{$row}:H{$row}");
    
    if ($stat_row[0] == '항목') {
        // 헤더
        $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F3F4F6']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);
    } else {
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);
        
        $sheet->getStyle("B{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);
    }
    
    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000']
            ]
        ]
    ]);
    
    $row++;
}

$row += 2;

// ========== 수목 상세 현황 ==========
$sheet->setCellValue("A{$row}", '수목 상세 현황');
$sheet->mergeCells("A{$row}:H{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 14
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'D1FAE5']
    ]
]);
$sheet->getRowDimension($row)->setRowHeight(30);

$row++;

// 테이블 헤더
$headers = ['No', '수종', '학명', '수량(주)', '규격', '평균 높이(m)', '평균 직경(cm)', '비고'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue("{$col}{$row}", $header);
    $sheet->getStyle("{$col}{$row}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'F9FAFB']
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
    ]);
    $col++;
}

$row++;

// 데이터 행
if (count($trees) > 0) {
    $index = 1;
    foreach ($trees as $tree) {
        $sheet->setCellValue("A{$row}", $index++);
        $sheet->setCellValue("B{$row}", $tree['korean_name']);
        $sheet->setCellValue("C{$row}", $tree['scientific_name'] ?? '-');
        $sheet->setCellValue("D{$row}", number_format($tree['quantity']));
        $sheet->setCellValue("E{$row}", $tree['size_spec'] ?? '-');
        $sheet->setCellValue("F{$row}", $tree['average_height'] ? number_format($tree['average_height'], 2) : '-');
        $sheet->setCellValue("G{$row}", $tree['average_diameter'] ? number_format($tree['average_diameter'], 2) : '-');
        $sheet->setCellValue("H{$row}", $tree['notes'] ?? '-');
        
        // 수종 이름 굵게
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);
        $sheet->getStyle("B{$row}")->getFont()->getColor()->setRGB('059669');
        
        // 수량 강조
        $sheet->getStyle("D{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER
            ]
        ]);
        
        // 중앙 정렬
        foreach (['A', 'C', 'E', 'F', 'G'] as $col_center) {
            $sheet->getStyle("{$col_center}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        
        // 테두리
        $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);
        
        $row++;
    }
} else {
    $sheet->setCellValue("A{$row}", '등록된 수목이 없습니다.');
    $sheet->mergeCells("A{$row}:H{$row}");
    $sheet->getStyle("A{$row}")->applyFromArray([
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'font' => [
            'color' => ['rgb' => '9CA3AF']
        ]
    ]);
    $sheet->getRowDimension($row)->setRowHeight(50);
    $row++;
}

$row += 2;

// ========== 비고 ==========
if ($location['description']) {
    $sheet->setCellValue("A{$row}", '비고');
    $sheet->mergeCells("A{$row}:H{$row}");
    $sheet->getStyle("A{$row}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'FEF3C7']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);
    
    $row++;
    
    $sheet->setCellValue("A{$row}", $location['description']);
    $sheet->mergeCells("A{$row}:H{$row}");
    $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000']
            ]
        ]
    ]);
    
    $row += 2;
}

// ========== 푸터 ==========
$sheet->setCellValue("A{$row}", '출력일: ' . date('Y년 m월 d일'));
$sheet->mergeCells("A{$row}:H{$row}");
$sheet->getStyle("A{$row}")->applyFromArray([
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_RIGHT
    ],
    'font' => [
        'size' => 9,
        'color' => ['rgb' => '6B7280']
    ]
]);

// ========== 컬럼 너비 설정 ==========
$sheet->getColumnDimension('A')->setWidth(8);   // No
$sheet->getColumnDimension('B')->setWidth(18);  // 수종
$sheet->getColumnDimension('C')->setWidth(20);  // 학명
$sheet->getColumnDimension('D')->setWidth(12);  // 수량
$sheet->getColumnDimension('E')->setWidth(18);  // 규격
$sheet->getColumnDimension('F')->setWidth(15);  // 평균 높이
$sheet->getColumnDimension('G')->setWidth(15);  // 평균 직경
$sheet->getColumnDimension('H')->setWidth(35);  // 비고

// ========== 파일 생성 및 다운로드 ==========
// 임시 파일 생성
$tempFile = tempnam(sys_get_temp_dir(), 'location_report_');

$writer = new Xlsx($spreadsheet);
$writer->save($tempFile);

// 메모리 정리
$spreadsheet->disconnectWorksheets();
unset($spreadsheet);

// 출력 버퍼 완전 정리
while (ob_get_level()) {
    ob_end_clean();
}

// 파일명 생성 (한글 깨짐 방지)
$filename = '장소관리대장_' . preg_replace('/[^가-힣a-zA-Z0-9_-]/u', '', $location['location_name']) . '_' . date('Ymd') . '.xlsx';
$encoded_filename = urlencode($filename);

// 헤더 설정
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $encoded_filename . '"; filename*=UTF-8\'\'' . $encoded_filename);
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: max-age=0');
header('Pragma: public');

// 파일 전송
readfile($tempFile);

// 임시 파일 삭제
unlink($tempFile);

exit;
?>
