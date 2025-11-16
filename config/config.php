<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 타임존 설정
date_default_timezone_set('Asia/Seoul');

// 에러 리포팅 (개발 환경)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 기본 경로 설정
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', 'https://www.sstree.or.kr/v2');

// 업로드 설정
// [수정] rtrim을 BASE_PATH에 적용하고, DIRECTORY_SEPARATOR를 사용해 OS 호환성 확보
define('UPLOAD_PATH', rtrim(BASE_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR);
define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 10MB

// [수정] 대소문자, 공백이 섞여도 처리되도록 문자열로 변경
define('ALLOWED_EXTENSIONS', 'jpg, jpeg, png, gif');


// 페이지네이션 설정
define('ITEMS_PER_PAGE', 20);

// 사이트 정보
define('SITE_NAME', '신안군 스마트 트리맵');
define('SITE_DESCRIPTION', '신안군 나무 관리 시스템');

// 데이터베이스 연결
require_once BASE_PATH . '/config/database.php';

// 헬퍼 함수들
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit();
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function formatDate($date) {
    return date('Y-m-d H:i', strtotime($date));
}

function formatNumber($number, $decimals = 2) {
    return number_format($number, $decimals);
}
?>