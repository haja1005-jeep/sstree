// ============================================
// API 키 설정 파일
// ============================================
// 이 파일에 발급받은 API 키를 입력하세요
// ============================================

const API_CONFIG = {
    // Google API 설정
    google: {
        // Google OAuth 클라이언트 ID
        // 예시: '123456789012-abcdefghijklmnop.apps.googleusercontent.com'
        clientId: '204515737075-4i39uitvagjk1pvqthrjdcgfuaptmjga.apps.googleusercontent.com',
        
        // Google API 키
        // 예시: 'AIzaSyABC123def456GHI789jkl012MNO345pqr'
        apiKey: 'AIzaSyBMC8rNXYHNFXMXAMteN011xfivCGbaTgo',
        
        // Google Drive 업로드 폴더 ID (선택사항)
        // 비워두면: 날짜별로 새 폴더 자동 생성
        // 입력하면: 지정한 폴더 안에 날짜별 하위 폴더 생성
        // 폴더 ID 찾는 방법: 아래 주석 참고
        targetFolderId: '1LG0LmEayx3-VT8_3M7jNqEb8gPKNXdsK',

	
        
        // 📌 폴더 ID 찾는 방법:
        // 1. Google Drive 접속 (drive.google.com)
        // 2. 원하는 폴더 열기
        // 3. 주소창 URL 확인
        //    예: https://drive.google.com/drive/u/1/folders/1D_7H8wSxIWQTuMKwRcqmpxCctwMip9ei
        //                                           ^^^^^^^^^^^^^^^^^^^^^^^^
        //                                           이 부분이 폴더 ID입니다
        // 4. 폴더 ID를 복사해서 위의 targetFolderId에 붙여넣기
        //    예: targetFolderId: '1A2B3C4D5E6F7G8H9I0J'
    },
    
    // Kakao Map API 설정
    kakao: {
        // Kakao JavaScript 키
        // 예시: 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6'
        apiKey: '257fdd3647dd6abdb05eae8681106514'
    }
};




// ============================================
// 아래 내용은 수정하지 마세요
// ============================================

// 설정 검증
function validateConfig() {
    const errors = [];
    
    if (API_CONFIG.google.clientId === 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com') {
        errors.push('❌ Google Client ID가 설정되지 않았습니다.');
    }
    
    if (API_CONFIG.google.apiKey === 'YOUR_GOOGLE_API_KEY') {
        errors.push('❌ Google API Key가 설정되지 않았습니다.');
    }
    
    if (API_CONFIG.kakao.apiKey === 'YOUR_KAKAO_API_KEY') {
        errors.push('❌ Kakao API Key가 설정되지 않았습니다.');
    }
    
    if (errors.length > 0) {
        console.warn('⚠️ API 키 설정이 필요합니다:');
        errors.forEach(error => console.warn(error));
        return false;
    }
    
    console.log('✅ 모든 API 키가 올바르게 설정되었습니다!');
    return true;
}

// 페이지 로드 시 설정 검증
window.addEventListener('DOMContentLoaded', function() {
    validateConfig();
});