<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/photoswipe/5.4.4/photoswipe.min.css" />
    <!--<script src="https://cdnjs.cloudflare.com/ajax/libs/photoswipe/5.4.4/umd/photoswipe.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/photoswipe/5.4.4/umd/photoswipe-lightbox.umd.min.js"></script> -->



</head>
<body>
    <div class="admin-wrapper">
        <!-- 헤더 -->
        <header class="admin-header">
            <div class="header-left">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1 class="site-title">🌳 <?php echo SITE_NAME; ?></h1>
            </div>
            <div class="header-right">
	   
				<span class="user-name"><?php echo isset($_SESSION['name']) ? $_SESSION['name'] : $_SESSION['username']; ?></span>
                <span class="user-role">(<?php echo $_SESSION['role'] === 'admin' ? '관리자' : '일반'; ?>)</span>

                <a href="<?php echo BASE_URL; ?>/admin/logout.php" class="btn-logout">로그아웃</a>
            </div>
        </header>
        
        <div class="admin-content">
            <?php include __DIR__ . '/sidebar.php'; ?>
            
            <main class="main-content">
