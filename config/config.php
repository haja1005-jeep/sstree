<?php
/**
 * 전역 설정 파일
 * Smart Tree Map - Sinan County
 */

// 세션 시작
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
define('UPLOAD_PATH', BASE_PATH . '/uploads/photos/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif']);

// 페이지네이션 설정
define('ITEMS_PER_PAGE', 20);

// 사이트 정보
define('SITE_NAME', '신안군 스마트 트리맵');
define('SITE_DESCRIPTION', '신안군 나무 관리 시스템');

// 데이터베이스 연결
require_once BASE_PATH . '/v2/config/database.php';

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
