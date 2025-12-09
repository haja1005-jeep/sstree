// ====================================
// 🍔 사이드 메뉴 제어 함수
// ====================================

function openSideMenu() {
    const sideMenu = document.getElementById('sideMenu');
    const overlay = document.getElementById('sideMenuOverlay');
    
    sideMenu.classList.add('active');
    overlay.classList.add('active');
    
    // body 스크롤 막기
    document.body.style.overflow = 'hidden';
    
    // 사용자 정보 업데이트
    updateSideMenuUserInfo();
}

function closeSideMenu() {
    const sideMenu = document.getElementById('sideMenu');
    const overlay = document.getElementById('sideMenuOverlay');
    
    sideMenu.classList.remove('active');
    overlay.classList.remove('active');
    
    // body 스크롤 복원
    document.body.style.overflow = '';
}

function updateSideMenuUserInfo() {
    const userName = document.getElementById('userName');
    const sideMenuUserName = document.getElementById('sideMenuUserName');
    const sideMenuUserEmail = document.getElementById('sideMenuUserEmail');
    const menuLogoutBtn = document.getElementById('menuLogoutBtn');
    
    if (accessToken && userName && userName.textContent) {
        // 로그인 상태
        sideMenuUserName.textContent = '스마트 트리맵';
        sideMenuUserEmail.textContent = userName.textContent;
        if (menuLogoutBtn) {
            menuLogoutBtn.style.display = 'block';
        }
    } else {
        // 비로그인 상태
        sideMenuUserName.textContent = '스마트 트리맵';
        sideMenuUserEmail.textContent = '로그인이 필요합니다';
        if (menuLogoutBtn) {
            menuLogoutBtn.style.display = 'none';
        }
    }
}

// ====================================
// 📋 메뉴 아이템 클릭 처리
// ====================================

function handleMenuClick(action) {
    closeSideMenu();
    
    switch(action) {
        case 'new':
            // 새 작업 시작
            if (confirm('현재 작업을 초기화하고 새로 시작하시겠습니까?')) {
                location.reload();
            }
            break;
            
        case 'recent':
            // 최근 작업
            alert('최근 작업 기능은 개발 중입니다.');
            break;
            
        case 'drafts':
            // 임시 저장
            alert('임시 저장 기능은 개발 중입니다.');
            break;
            
        case 'load':
            // 폴더에서 불러오기
            loadExistingData();
            break;
            
        case 'merge':
            // 스프레드시트 통합
            mergeSpreadsheets();
            break;
            
        case 'browse':
            // 내 드라이브 보기
            if (accessToken) {
                window.open('https://drive.google.com/drive/my-drive', '_blank');
            } else {
                alert('먼저 Google 계정에 로그인해주세요.');
            }
            break;
            
        case 'profile':
            // 프로필
            alert('프로필 기능은 개발 중입니다.');
            break;
            
        case 'settings':
            // 환경 설정
            alert('환경 설정 기능은 개발 중입니다.');
            break;
            
        case 'help':
            // 도움말
            showHelpModal();
            break;
            
        default:
            console.log('Unknown action:', action);
    }
}

function handleMenuLogout() {
    if (confirm('로그아웃 하시겠습니까?')) {
        handleSignoutClick();
        closeSideMenu();
    }
}

// ====================================
// ❓ 도움말 모달
// ====================================

function showHelpModal() {
    const modal = document.createElement('div');
    modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center; z-index: 10000;';
    modal.innerHTML = `
        <div style="background: #1a1a1a; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; border: 1px solid #333; max-height: 80vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #4ade80; font-size: 20px;">📚 사용 가이드</h3>
                <button onclick="this.closest('[style*=fixed]').remove()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer;">✕</button>
            </div>
            
            <div style="color: white; line-height: 1.8;">
                <h4 style="color: #4ade80; margin-top: 20px;">1️⃣ 현장 사진 업로드</h4>
                <p style="color: #ccc; font-size: 14px;">
                    • 드래그 앤 드롭 또는 클릭하여 사진 업로드<br>
                    • GPS 정보가 있는 사진은 자동으로 위치 표시<br>
                    • 여러 장 동시 업로드 가능
                </p>
                
                <h4 style="color: #4ade80; margin-top: 20px;">2️⃣ 필터 선택</h4>
                <p style="color: #ccc; font-size: 14px;">
                    • 지역 → 카테고리 → 장소 → 나무종류 순서로 선택<br>
                    • 나무종류 선택 시 상세 정보 입력 가능<br>
                    • 높이(m), 둘레(cm), 상태 입력
                </p>
                
                <h4 style="color: #4ade80; margin-top: 20px;">3️⃣ Google Drive 저장</h4>
                <p style="color: #ccc; font-size: 14px;">
                    • Google 계정 로그인 필요<br>
                    • 자동으로 폴더 생성 및 업로드<br>
                    • 스프레드시트 자동 생성
                </p>
                
                <h4 style="color: #4ade80; margin-top: 20px;">4️⃣ 데이터 관리</h4>
                <p style="color: #ccc; font-size: 14px;">
                    • 기존 폴더에서 데이터 불러오기<br>
                    • 여러 스프레드시트 통합 가능<br>
                    • 로그인 상태 유지 기능
                </p>
                
                <div style="background: rgba(74, 222, 128, 0.1); border: 1px solid #4ade80; border-radius: 8px; padding: 15px; margin-top: 20px;">
                    <p style="color: #4ade80; font-weight: 600; margin: 0; font-size: 14px;">
                        💡 문의사항이 있으시면 관리자에게 연락해주세요.
                    </p>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

// ====================================
// 🔄 로그인/로그아웃 시 사이드 메뉴 업데이트
// ====================================

// 기존 handleAuthClick 함수를 래핑하여 사이드 메뉴 업데이트 추가
const originalHandleAuthClick = window.handleAuthClick;
window.handleAuthClick = function() {
    originalHandleAuthClick();
    setTimeout(updateSideMenuUserInfo, 1000);
};

// 기존 handleSignoutClick 함수를 래핑하여 사이드 메뉴 업데이트 추가
const originalHandleSignoutClick = window.handleSignoutClick;
window.handleSignoutClick = function() {
    originalHandleSignoutClick();
    updateSideMenuUserInfo();
};

// ====================================
// 📱 모바일에서 스와이프로 메뉴 열기/닫기
// ====================================

let touchStartX = 0;
let touchEndX = 0;

document.addEventListener('touchstart', (e) => {
    touchStartX = e.changedTouches[0].screenX;
}, false);

document.addEventListener('touchend', (e) => {
    touchEndX = e.changedTouches[0].screenX;
    handleSwipe();
}, false);

function handleSwipe() {
    const sideMenu = document.getElementById('sideMenu');
    const swipeThreshold = 50;
    
    // 왼쪽에서 오른쪽으로 스와이프 (메뉴 열기)
    if (touchEndX > touchStartX + swipeThreshold && touchStartX < 50) {
        openSideMenu();
    }
    
    // 오른쪽에서 왼쪽으로 스와이프 (메뉴 닫기)
    if (touchStartX > touchEndX + swipeThreshold && sideMenu.classList.contains('active')) {
        closeSideMenu();
    }
}

// ====================================
// ⌨️ ESC 키로 사이드 메뉴 닫기
// ====================================

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const sideMenu = document.getElementById('sideMenu');
        if (sideMenu.classList.contains('active')) {
            closeSideMenu();
        }
    }
});

console.log('✅ 사이드 메뉴 기능이 로드되었습니다.');