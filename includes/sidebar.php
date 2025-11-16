<aside class="sidebar" id="sidebar">
    <nav class="sidebar-nav">
        <ul>
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                    <span class="icon">📊</span>
                    <span class="text">대시보드</span>
                </a>
            </li>
            
            <li class="menu-section">
                <span class="section-title">기본 데이터 관리</span>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/regions/list.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/regions/') !== false ? 'active' : ''; ?>">
                    <span class="icon">🗺️</span>
                    <span class="text">지역 관리</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/categories/list.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/categories/') !== false ? 'active' : ''; ?>">
                    <span class="icon">📁</span>
                    <span class="text">카테고리 관리</span>
                </a>
            </li>
            
            <!-- 장소 관리 (구버전) -->
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/locations_old/list.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/locations_old/') !== false ? 'active' : ''; ?>">
                    <span class="icon">📍</span>
                    <span class="text">장소 관리 (구)</span>
                </a>
            </li>
            
            <!-- 장소 관리 (신버전) - Phase 3 -->
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/locations/index.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/locations/') !== false && strpos($_SERVER['PHP_SELF'], '/locations_old/') === false ? 'active' : ''; ?>" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 20%); color: white; font-weight: 600;">
                    <span class="icon">✨</span>
                    <span class="text">장소 관리 (신) 🆕</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/species/list.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/species/') !== false ? 'active' : ''; ?>">
                    <span class="icon">🌲</span>
                    <span class="text">수종 관리</span>
                </a>
            </li>
            
            <li class="menu-section">
                <span class="section-title">나무 데이터 관리</span>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/trees/list.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/trees/') !== false ? 'active' : ''; ?>">
                    <span class="icon">🌳</span>
                    <span class="text">나무 목록</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/trees/map.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'map.php' ? 'active' : ''; ?>">
                    <span class="icon">🗺️</span>
                    <span class="text">지도 보기</span>
                </a>
            </li>
            
            <li class="menu-section">
                <span class="section-title">시스템 관리</span>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/users/list.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/users/') !== false ? 'active' : ''; ?>">
                    <span class="icon">👥</span>
                    <span class="text">회원 관리</span>
                </a>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/statistics/dashboard.php" class="<?php echo strpos($_SERVER['PHP_SELF'], '/statistics/') !== false ? 'active' : ''; ?>">
                    <span class="icon">📈</span>
                    <span class="text">통계 보기</span>
                </a>
            </li>
        </ul>
    </nav>
</aside>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('collapsed');
}
</script>
