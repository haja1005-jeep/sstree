// Google Drive API Configuration (config.js에서 로드)
const GOOGLE_CLIENT_ID = API_CONFIG.google.clientId;
const GOOGLE_API_KEY = API_CONFIG.google.apiKey;
const DISCOVERY_DOCS = [
    'https://www.googleapis.com/discovery/v1/apis/drive/v3/rest',
    'https://sheets.googleapis.com/$discovery/rest?version=v4'
];
const SCOPES = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/spreadsheets';

let allTreeData = []; 
let filteredData = []; 

let tokenClient;
let gapiInited = false;
let gisInited = false;
let accessToken = null;

// 구글 로그인 유지 1105 추가 - 1
let tokenExpiresAt = null;
let refreshTimer = null;
let keepSignedIn = false;
// end ------------- //

// Global variables
let images = [];
let selectedImageIndex = null;
let map = null;
let markers = [];
let pathPolyline = null;
let sortableInstance = null;


// ====================================
// 🔐 로컬 스토리지 관리 구글 로그인 유지 1105 추가 - 2
// ====================================

const AUTH_STORAGE_KEY = 'smart_tree_auth';
const USER_STORAGE_KEY = 'smart_tree_user';

function saveAuthToStorage(token, expiresIn, userEmail) {
    const checkbox = document.getElementById('keepSignedIn');
    if (!checkbox || !checkbox.checked) return;
    
    const expiresAt = Date.now() + (expiresIn * 1000);
    try {
        localStorage.setItem(AUTH_STORAGE_KEY, JSON.stringify({
            accessToken: token,
            expiresAt: expiresAt
        }));
        localStorage.setItem(USER_STORAGE_KEY, userEmail);
        console.log('✅ 인증 정보 저장');
    } catch (e) {
        console.error('저장 실패:', e);
    }
}

function loadAuthFromStorage() {
    try {
        const authStr = localStorage.getItem(AUTH_STORAGE_KEY);
        if (!authStr) return null;
        
        const auth = JSON.parse(authStr);
        if (auth.expiresAt < Date.now() + (5 * 60 * 1000)) {
            localStorage.clear();
            return null;
        }
        
        return {
            accessToken: auth.accessToken,
            userEmail: localStorage.getItem(USER_STORAGE_KEY)
        };
    } catch (e) {
        return null;
    }
}


// 자동로그인 1105
function checkSavedAuth() {
    const saved = loadAuthFromStorage();
    if (!saved) {
        console.log('💾 저장된 인증 정보 없음');
        return;
    }
    
    console.log('💾 자동 로그인 시도...', saved.userEmail);
    
    try {
        gapi.client.setToken({ access_token: saved.accessToken });
        accessToken = saved.accessToken;
        
        // ⭐ 중요: 로그인 컨테이너 숨기기 
        const loginContainer = document.getElementById('loginContainer');
        if (loginContainer) {
            loginContainer.classList.add('hidden');
        }
        
        // 사용자 정보 표시
        const userInfo = document.getElementById('userInfo');
        if (userInfo) {
            userInfo.style.display = 'block';
            userInfo.classList.add('show');
        }
        
        // 드라이브 섹션 표시
        const driveLoadSection = document.getElementById('driveLoadSection');
        if (driveLoadSection) {
            driveLoadSection.style.display = 'block';
        }
        
        const driveActions = document.getElementById('driveActions');
        if (driveActions) {
            driveActions.style.display = 'flex';
            driveActions.classList.add('show');
        }
        
        // 사용자 이름 표시
        const userName = document.getElementById('userName');
        if (userName) {
            userName.textContent = saved.userEmail || '사용자';
        }
        
        // 체크박스 체크 (선택사항)
        const checkbox = document.getElementById('keepSignedIn');
        if (checkbox) {
            checkbox.checked = true;
        }
        
        console.log('✅ 자동 로그인 완료:', saved.userEmail);
        
    } catch (error) {
        console.error('❌ 자동 로그인 실패:', error);
        localStorage.clear();
        
        // 실패 시 로그인 화면으로
        const loginContainer = document.getElementById('loginContainer');
        if (loginContainer) {
            loginContainer.classList.remove('hidden');
        }
    }
}

//----- end -------------- //


// Initialize Google API
function gapiLoaded() {
    gapi.load('client', initializeGapiClient);
}

async function initializeGapiClient() {
    try {
        const response = await fetch('./tree-select.json'); 
        if (!response.ok) {
            throw new Error('tree-select.json 파일을 불러오는 데 실패했습니다.');
        }
        
        allTreeData = await response.json();
        filteredData = [...allTreeData];
        console.log("트리 데이터 로드 성공:", allTreeData);

        await gapi.client.init({
            apiKey: GOOGLE_API_KEY,
            discoveryDocs: DISCOVERY_DOCS,
        });
        gapiInited = true;
        maybeEnableButtons();
		checkSavedAuth();  // 구글 로그인 유지 1105 추가 -3
    } catch (error) {
        console.error('API 또는 데이터 초기화 오류:', error);
    }
}

function gisLoaded() {
    tokenClient = google.accounts.oauth2.initTokenClient({
        client_id: GOOGLE_CLIENT_ID,
        scope: SCOPES,
        callback: '',
    });
    gisInited = true;
    maybeEnableButtons();
}

function maybeEnableButtons() {
    if (gapiInited && gisInited) {
        document.getElementById('googleDriveSection').classList.add('show');
    }
}


// 처음 로그인
function handleAuthClick() {
    tokenClient.callback = async (resp) => {
        if (resp.error !== undefined) {
            throw (resp);
        }
        accessToken = gapi.client.getToken().access_token;
        document.getElementById('signInBtn').style.display = 'none';
        document.getElementById('userInfo').style.display = 'block';
        document.getElementById('driveLoadSection').style.display = 'block';
        document.getElementById('driveActions').style.display = 'flex';

        // 구글 로그인 유지 1105 추가 - 7 체크박스 숨김
        document.getElementById('loginContainer').classList.add('hidden');
        // end ------------------------------ -->

        try {
            const response = await fetch('https://www.googleapis.com/oauth2/v2/userinfo', {
                headers: { Authorization: `Bearer ${accessToken}` }
            });
            const data = await response.json();

		    // 아래 코드 추가 구글 로그인 유지 1105 - 4

    // 이메일이 없을 경우 대비
    const userEmail = data.email || data.name || '사용자';
    document.getElementById('userName').textContent = userEmail;
    
    console.log('✅ 로그인 성공:', userEmail); // 로그 추가
    
    // 저장
    const expiresIn = resp.expires_in || 3600;
    saveAuthToStorage(accessToken, expiresIn, userEmail);



        } catch (error) {
            console.error('Error fetching user info:', error);
        }
    };

    if (gapi.client.getToken() === null) {
        tokenClient.requestAccessToken({prompt: 'consent'});
    } else {
        tokenClient.requestAccessToken({prompt: ''});
    }
}

/*
function handleSignoutClick() {
    const token = gapi.client.getToken();
    if (token !== null) {
        google.accounts.oauth2.revoke(token.access_token);
        gapi.client.setToken('');
        accessToken = null;
        document.getElementById('signInBtn').style.display = 'flex';
        document.getElementById('userInfo').style.display = 'none';
        document.getElementById('driveActions').style.display = 'none';
        document.getElementById('uploadProgress').innerHTML = '';
        document.getElementById('uploadProgress').classList.remove('show');
    }
    
	// 아래 코드 추가 구글 로그인 유지 1105 - 5
	if (refreshTimer) clearTimeout(refreshTimer);
    localStorage.clear();
    const checkbox = document.getElementById('keepSignedIn');
    if (checkbox) checkbox.checked = false;
	// end ------------------------------------------- //
}
*/
	
// 아래 코드 교체 구글 로그인 유지 1105 - 5
function handleSignoutClick() {
    const token = gapi.client.getToken();
    if (token !== null) {
        google.accounts.oauth2.revoke(token.access_token);
        gapi.client.setToken('');
    }
    
    // 완전한 상태 초기화
    accessToken = null;

	// ⭐ 로그인 컨테이너 다시 표시 1105 추가 - 10
const loginContainer = document.getElementById('loginContainer');
if (loginContainer) {
    loginContainer.classList.remove('hidden');
    loginContainer.style.display = 'block';
}
    
    // end ------->

    // UI 요소 가져오기
    const signInBtn = document.getElementById('signInBtn');
    const userInfo = document.getElementById('userInfo');
    const driveLoadSection = document.getElementById('driveLoadSection');
    const driveActions = document.getElementById('driveActions');
    const uploadProgress = document.getElementById('uploadProgress');
    const userName = document.getElementById('userName');
    const keepSignedInCheckbox = document.getElementById('keepSignedIn');
    
    // 로그인 버튼 표시
    if (signInBtn) signInBtn.style.display = 'flex';
    
    // 사용자 정보 숨기기
    if (userInfo) {
        userInfo.style.display = 'none';
        userInfo.classList.remove('show');
    }
    
    // 드라이브 섹션 숨기기
    if (driveLoadSection) {
        driveLoadSection.style.display = 'none';
        driveLoadSection.classList.remove('show');
    }
    
    // 드라이브 액션 숨기기
    if (driveActions) {
        driveActions.style.display = 'none';
        driveActions.classList.remove('show');
    }
    
    // 업로드 진행 초기화
    if (uploadProgress) {
        uploadProgress.innerHTML = '';
        uploadProgress.classList.remove('show');
    }
    
    // 사용자 이름 초기화
    if (userName) userName.textContent = '';
    
    // 체크박스 초기화
    if (keepSignedInCheckbox) keepSignedInCheckbox.checked = true;
    
    // localStorage 정리
    try {
        localStorage.removeItem('smart_tree_auth');
        localStorage.removeItem('smart_tree_user');
    } catch (e) {
        console.log('localStorage 정리:', e);
    }
    
    console.log('✅ 로그아웃 완료');
}

// end ------------------------------------------------------------------- //







async function uploadToGoogleDrive(mode) {
    if (!accessToken) {
        alert('먼저 Google 계정으로 로그인해주세요.');
        return;
    }

    let imagesToUpload = [];
    
    if (mode === 'selected') {
        if (selectedImageIndex === null) {
            alert('업로드할 이미지를 선택해주세요.');
            return;
        }
        imagesToUpload = [images[selectedImageIndex]];
    } else {
        if (images.length === 0) {
            alert('업로드할 이미지가 없습니다.');
            return;
        }
        imagesToUpload = images;
    }

    const progressContainer = document.getElementById('uploadProgress');
    progressContainer.innerHTML = '';
    progressContainer.classList.add('show');

    try {
        const folderName = `스마트 트리맵 현장사진 ${new Date().toLocaleDateString('ko-KR')}`;
        
        // 대상 폴더 ID 확인 (config.js에서 설정)
        const parentFolderId = API_CONFIG.google.targetFolderId || 'root';
        
        console.log('📁 업로드 시작:', {
            folderName: folderName,
            parentFolderId: parentFolderId,
            isRoot: parentFolderId === 'root'
        });
        
        // 날짜별 하위 폴더 생성
        const folderId = await createDriveFolder(folderName, parentFolderId);
        
        console.log('✅ 폴더 생성 완료:', {
            newFolderId: folderId,
            parentFolderId: parentFolderId
        });
        
        // 폴더 정보 표시
        const folderInfo = document.createElement('div');
        folderInfo.className = 'progress-item';
        folderInfo.innerHTML = `
            <span>📁 업로드 폴더 생성 완료</span>
            <span class="upload-status">✓</span>
        `;
        progressContainer.appendChild(folderInfo);

        // 1. 이미지 업로드
        for (let i = 0; i < imagesToUpload.length; i++) {
            const image = imagesToUpload[i];
            const imageIndex = images.indexOf(image);
            
            const progressItem = document.createElement('div');
            progressItem.className = 'progress-item';
            progressItem.innerHTML = `
                <span>이미지 ${imageIndex + 1}</span>
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-${i}" style="width: 0%"></div>
                </div>
                <span class="upload-status" id="status-${i}">0%</span>
            `;
            progressContainer.appendChild(progressItem);

            await uploadFileToDrive(image, folderId, i);
        }

        // 2. 상세 정보를 텍스트 파일로 생성
        const textProgressItem = document.createElement('div');
        textProgressItem.className = 'progress-item';
        textProgressItem.innerHTML = `
            <span>상세 정보 텍스트 파일</span>
            <div class="progress-bar">
                <div class="progress-fill" id="progress-text" style="width: 0%"></div>
            </div>
            <span class="upload-status" id="status-text">생성 중...</span>
        `;
        progressContainer.appendChild(textProgressItem);

        const detailText = generateDetailText(imagesToUpload);
        await uploadTextFile(detailText, folderId, 'progress-text', 'status-text');

        // 3. 구글 스프레드시트 생성
        const sheetProgressItem = document.createElement('div');
        sheetProgressItem.className = 'progress-item';
        sheetProgressItem.innerHTML = `
            <span>구글 스프레드시트</span>
            <div class="progress-bar">
                <div class="progress-fill" id="progress-sheet" style="width: 0%"></div>
            </div>
            <span class="upload-status" id="status-sheet">생성 중...</span>
        `;
        progressContainer.appendChild(sheetProgressItem);

        let spreadsheetId = null;
        try {
            document.getElementById('progress-sheet').style.width = '50%';
            spreadsheetId = await createSpreadsheet(imagesToUpload, folderId);
            document.getElementById('progress-sheet').style.width = '100%';
            document.getElementById('status-sheet').textContent = '완료 ✓';
        } catch (sheetError) {
            console.error('스프레드시트 생성 실패:', sheetError);
            document.getElementById('progress-sheet').style.width = '100%';
            document.getElementById('status-sheet').textContent = '실패 ✗';
            document.getElementById('status-sheet').style.color = '#ff6b6b';
            
            // 스프레드시트 실패해도 나머지는 성공했으므로 계속 진행
            const warningMsg = document.createElement('div');
            warningMsg.className = 'progress-item';
            warningMsg.style.color = '#ff9800';
            warningMsg.innerHTML = `
                <span>⚠️ 스프레드시트 생성 실패: ${sheetError.message}</span>
            `;
            progressContainer.appendChild(warningMsg);
        }

        const successMsg = document.createElement('div');
        successMsg.className = 'success-message';
        successMsg.innerHTML = `
            <span>✅</span>
            <span>${imagesToUpload.length}개의 이미지와 상세 정보 텍스트가 성공적으로 업로드되었습니다!${spreadsheetId ? ' (스프레드시트 포함)' : ''}</span>
        `;


        progressContainer.appendChild(successMsg);


        setTimeout(() => {
            let buttons = `
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button onclick="window.open('https://drive.google.com/drive/folders/${folderId}', '_blank')" 
                            style="flex: 1; padding: 12px; background: #4285f4; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px;">
                        📁 폴더 보기
                    </button>`;
            
            if (spreadsheetId) {
                buttons += `
                    <button onclick="window.open('https://docs.google.com/spreadsheets/d/${spreadsheetId}', '_blank')" 
                            style="flex: 1; padding: 12px; background: #34a853; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px;">
                        📊 스프레드시트 보기
                    </button>`;
            }
            
            buttons += `</div>`;
            successMsg.innerHTML += buttons;
        }, 500);


    } catch (error) {
        console.error('Upload error:', error);
        alert('업로드 중 오류가 발생했습니다: ' + error.message);
    }
}

async function createDriveFolder(folderName, parentFolderId = 'root') {
    const metadata = {
        name: folderName,
        mimeType: 'application/vnd.google-apps.folder',
        parents: [parentFolderId]
    };

    console.log('📤 폴더 생성 요청:', metadata);

    const response = await fetch('https://www.googleapis.com/drive/v3/files', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${accessToken}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(metadata)
    });

    const data = await response.json();
    
    if (!response.ok) {
        console.error('❌ 폴더 생성 실패:', data);
        throw new Error(`폴더 생성 실패: ${data.error?.message || '알 수 없는 오류'}`);
    }
    
    // 폴더 생성 정보 로그
    console.log('✅ 폴더 생성 완료:', {
        folderName: folderName,
        newFolderId: data.id,
        parentFolder: parentFolderId === 'root' ? 'My Drive 루트' : parentFolderId,
        parents: data.parents
    });
    
    return data.id;
}

async function uploadFileToDrive(image, folderId, progressIndex) {
    const base64Data = image.src.split(',')[1];
    const byteCharacters = atob(base64Data);
    const byteNumbers = new Array(byteCharacters.length);
    for (let i = 0; i < byteCharacters.length; i++) {
        byteNumbers[i] = byteCharacters.charCodeAt(i);
    }
    const byteArray = new Uint8Array(byteNumbers);
    const blob = new Blob([byteArray], { type: image.file.type });

    const fileName = image.file.name;
    const metadata = {
        name: fileName,
        parents: [folderId]
    };

    const form = new FormData();
    form.append('metadata', new Blob([JSON.stringify(metadata)], { type: 'application/json' }));
    form.append('file', blob);

    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
        xhr.setRequestHeader('Authorization', `Bearer ${accessToken}`);

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percentComplete = Math.round((e.loaded / e.total) * 100);
                document.getElementById(`progress-${progressIndex}`).style.width = percentComplete + '%';
                document.getElementById(`status-${progressIndex}`).textContent = percentComplete + '%';
            }
        });

        xhr.addEventListener('load', () => {
            if (xhr.status === 200) {
                document.getElementById(`status-${progressIndex}`).textContent = '완료 ✓';
                resolve(JSON.parse(xhr.responseText));
            } else {
                reject(new Error(`Upload failed: ${xhr.status}`));
            }
        });

        xhr.addEventListener('error', () => {
            reject(new Error('Upload failed'));
        });

        xhr.send(form);
    });
}

// 상세 정보를 텍스트로 생성하는 함수
function generateDetailText(imagesToUpload) {
    const selectedFilters = {
        region: document.getElementById('region-select').value,
        category: document.getElementById('category-select').value,
        place: document.getElementById('place-select').value,
        tree: document.getElementById('tree-select').value
    };
    
    const memo = document.querySelector('.prompt-textarea').value;
    const currentDate = new Date().toLocaleString('ko-KR');
    
    let textContent = `===================================\n`;
    textContent += `신안군 스마트 트리맵 현장 조사 보고서\n`;
    textContent += `===================================\n\n`;
    textContent += `작성일시: ${currentDate}\n\n`;
    
    textContent += `[선택 필터]\n`;
    textContent += `지역: ${selectedFilters.region || '선택 안 함'}\n`;
    textContent += `카테고리: ${selectedFilters.category || '선택 안 함'}\n`;
    textContent += `장소: ${selectedFilters.place || '선택 안 함'}\n`;
    textContent += `나무종류: ${selectedFilters.tree || '선택 안 함'}\n\n`;
    
    textContent += `[상세 메모]\n`;
    textContent += `${memo || '메모 없음'}\n\n`;
    
    textContent += `===================================\n`;
    textContent += `현장 사진 정보 (총 ${imagesToUpload.length}장)\n`;
    textContent += `===================================\n\n`;

    // 🌳 [수정] getTreeData() 함수를 호출하여 현재 나무 정보를 가져옵니다.  1105 제미나이 수정
    const treeData = getTreeData();
    
    imagesToUpload.forEach((img, idx) => {
        const imageIndex = images.indexOf(img);
        textContent += `[사진 ${imageIndex + 1}]\n`;
        textContent += `파일명: ${img.file.name}\n`;
        textContent += `크기: ${(img.file.size / 1024).toFixed(2)} KB\n`;


        // 🆕 나무 상세 정보 추가   1105 - 09
       if (treeData.species) {
            // ⚠️ [수정] 변수명을 'text'에서 'textContent'로 변경했습니다.
            textContent += `\n[나무 정보]\n`;
            textContent += `수종: ${treeData.species}\n`;
            if (treeData.height) textContent += `높이: ${treeData.height}\n`;
            if (treeData.thickness) textContent += `둘레(두께): ${treeData.thickness}\n`;
            if (treeData.status) textContent += `상태: ${treeData.status}\n`;
        }

        // end  나무 상세 정보 추가   1105 - 09
        
        if (img.location) {
            textContent += `GPS 좌표:\n`;
            textContent += `  - 위도: ${img.location.lat.toFixed(6)}°\n`;
            textContent += `  - 경도: ${img.location.lng.toFixed(6)}°\n`;
            
            if (img.address && img.address.display_name) {
                textContent += `주소: ${img.address.display_name}\n`;
                
                const addr = img.address.address || {};
                if (addr.state) textContent += `  - 시/도: ${addr.state}\n`;
                if (addr.city) textContent += `  - 시/군/구: ${addr.city}\n`;
                if (addr.district) textContent += `  - 동/읍/면: ${addr.district}\n`;
            }
        } else {
            textContent += `GPS 정보: 없음\n`;
        }
        
        if (img.exif) {
            textContent += `EXIF 정보:\n`;
            if (img.exif.Make || img.exif.Model) {
                textContent += `  - 카메라: ${img.exif.Make || ''} ${img.exif.Model || ''}\n`;
            }
            if (img.exif.DateTime) {
                textContent += `  - 촬영 날짜: ${img.exif.DateTime}\n`;
            }
        }
        
        textContent += `\n`;
    });
    
    return textContent;
}

// 텍스트 파일을 Google Drive에 업로드하는 함수
async function uploadTextFile(textContent, folderId, progressId, statusId) {
    const fileName = `현장조사_상세정보_${new Date().toISOString().split('T')[0]}.txt`;
    const blob = new Blob([textContent], { type: 'text/plain;charset=utf-8' });
    
    const metadata = {
        name: fileName,
        parents: [folderId],
        mimeType: 'text/plain'
    };

    const form = new FormData();
    form.append('metadata', new Blob([JSON.stringify(metadata)], { type: 'application/json' }));
    form.append('file', blob);

    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
        xhr.setRequestHeader('Authorization', `Bearer ${accessToken}`);

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percentComplete = Math.round((e.loaded / e.total) * 100);
                document.getElementById(progressId).style.width = percentComplete + '%';
                document.getElementById(statusId).textContent = percentComplete + '%';
            }
        });

        xhr.addEventListener('load', () => {
            if (xhr.status === 200) {
                document.getElementById(statusId).textContent = '완료 ✓';
                resolve(JSON.parse(xhr.responseText));
            } else {
                reject(new Error(`Upload failed: ${xhr.status}`));
            }
        });

        xhr.addEventListener('error', () => {
            reject(new Error('Upload failed'));
        });

        xhr.send(form);
    });
}

// 구글 스프레드시트를 생성하는 함수 (REST API 직접 호출)
async function createSpreadsheet(imagesToUpload, folderId) {
    try {
        console.log('📊 스프레드시트 생성 시작...');
        
        const selectedFilters = {
            region: document.getElementById('region-select').value,
            category: document.getElementById('category-select').value,
            place: document.getElementById('place-select').value,
            tree: document.getElementById('tree-select').value
        };
        
        const memo = document.querySelector('.prompt-textarea').value;
        const currentDate = new Date().toLocaleString('ko-KR');
        
        // 스프레드시트 생성
        const spreadsheetTitle = `현장조사_${new Date().toISOString().split('T')[0]}`;
        
        console.log('📝 스프레드시트 생성 요청:', spreadsheetTitle);
        
        const createResponse = await gapi.client.sheets.spreadsheets.create({
            properties: {
                title: spreadsheetTitle
            }
        });
        
        const spreadsheetId = createResponse.result.spreadsheetId;

        // ⭐️ [수정됨] 
        // 생성된 스프레드시트의 실제 첫 번째 시트 이름을 가져옵니다. 
        // ('Sheet1' 대신 '시트1' 등 로케일-의존적 이름일 수 있음)
        const sheetTitle = createResponse.result.sheets[0].properties.title;
        
        console.log('✅ 스프레드시트 생성 완료:', spreadsheetId, '시트 이름:', sheetTitle);
        
        // 데이터 준비
        //const headerRow = [
        //    '사진번호', '파일명', '크기(KB)','위도', '경도', '전체주소', '시/도', '시/군/구', '동/읍/면', '카메라', '촬영날짜'
        //  ];

        // 🌳 [수정] getTreeData() 함수를 호출하여 현재 나무 정보를 가져옵니다. 제미나이 1105
        const treeData = getTreeData();

        // 데이터 준비  1105 - 09
        const headerRow = [
            '사진번호', '파일명', '수종', '높이(m)', '둘레(cm)', '상태', '크기(KB)','위도', '경도', '전체주소', '시/도', '시/군/구', '동/읍/면', '카메라', '촬영날짜'
          ];


 
        const dataRows = imagesToUpload.map((img, idx) => {
            const imageIndex = images.indexOf(img);
            const addr = img.address?.address || {};
            
            return [
                imageIndex + 1,
                img.file.name,
                treeData.species || '', 
                treeData.height || '', 
                treeData.thickness || '',
                treeData.status || '', 
                (img.file.size / 1024).toFixed(2),
                img.location ? img.location.lat.toFixed(6) : '',
                img.location ? img.location.lng.toFixed(6) : '',
                img.address?.display_name || '',
                addr.state || '',
                addr.city || '',
                addr.district || '',
                img.exif ? `${img.exif.Make || ''} ${img.exif.Model || ''}`.trim() : '',
                img.exif?.DateTime || ''
            ];
        });
        
        // 요약 정보 시트 데이터
        const summaryData = [
            ['신안군 스마트 트리맵 현장 조사'],
            [''],
            ['작성일시', currentDate],
            [''],
            ['=== 선택 필터 ==='],
            ['지역', selectedFilters.region || '선택 안 함'],
            ['카테고리', selectedFilters.category || '선택 안 함'],
            ['장소', selectedFilters.place || '선택 안 함'],
            ['나무종류', selectedFilters.tree || '선택 안 함'],
            [''],
            ['=== 상세 메모 ==='],
            [memo || '메모 없음'],
            [''],
            ['총 사진 수', imagesToUpload.length + '장']
        ];
        
        console.log('📝 데이터 입력 시작... (요약:', summaryData.length, '행, 사진 데이터:', dataRows.length, '행)');
        
        // 스프레드시트에 데이터 입력
        await gapi.client.sheets.spreadsheets.values.batchUpdate({
            spreadsheetId: spreadsheetId,
            resource: {
                valueInputOption: 'RAW',
                data: [
                    {
                        // ⭐️ [수정됨] 'Sheet1' 대신 동적 변수 `sheetTitle` 사용
                        range: `${sheetTitle}!A1`,
                        values: summaryData
                    },
                    {
                        // ⭐️ [수정됨] 'Sheet1' 대신 동적 변수 `sheetTitle` 사용
                        range: `${sheetTitle}!A${summaryData.length + 3}`,
                        values: [headerRow, ...dataRows]
                   }
                ]
            }
        });
        
        console.log('✅ 데이터 입력 완료');
        
        // 스프레드시트를 폴더로 이동
        console.log('📁 스프레드시트 이동 시작... (폴더 ID:', folderId, ')');
        
        const moveResponse = await fetch(`https://www.googleapis.com/drive/v3/files/${spreadsheetId}?addParents=${folderId}&removeParents=root&fields=id,parents`, {
            method: 'PATCH',
            headers: {
                'Authorization': `Bearer ${accessToken}`,
                'Content-Type': 'application/json'
            }
        });
        
        if (!moveResponse.ok) {
            const errorData = await moveResponse.json();
            console.error('❌ 폴더 이동 실패:', errorData);
            throw new Error(`폴더 이동 실패: ${errorData.error?.message || '알 수 없는 오류'}`);
        }
        
        console.log('✅ 스프레드시트 폴더 이동 완료');
        
        return spreadsheetId;
        
    } catch (error) {
        console.error('❌ 스프레드시트 생성 오류:', error);
        console.error('오류 상세:', {
            message: error.message,
            result: error.result,
            status: error.status
        });
        
        // 사용자에게 보여줄 메시지
        let errorMessage = '스프레드시트 생성 중 오류가 발생했습니다.';
        
        if (error.result?.error?.message) {
            errorMessage += '\n상세: ' + error.result.error.message;
        } else if (error.message) {
            errorMessage += '\n상세: ' + error.message;
        }
        
        throw new Error(errorMessage);
    }
}

// Load Google APIs
if (typeof gapi !== 'undefined') {
    gapiLoaded();
}
window.addEventListener('load', () => {
    if (typeof google !== 'undefined') {
        gisLoaded();
    }
});

// File upload handling
function initializeFileUpload() {
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('fileInput');

    uploadArea.addEventListener('click', () => {
        fileInput.click();
    });

    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.style.borderColor = '#4ade80';
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.style.borderColor = '#333';
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.style.borderColor = '#333';
        const files = Array.from(e.dataTransfer.files).filter(f => f.type.match('image.*'));
        if (files.length > 0) {
            handleFiles(files);
        }
    });

    fileInput.addEventListener('change', (e) => {
        const files = Array.from(e.target.files);
        if (files.length > 0) {
            handleFiles(files);
        }
    });
}

function handleFiles(files) {
    files.forEach(file => {
        const reader = new FileReader();
        reader.onload = (e) => {
            const imageData = {
                id: Date.now() + Math.random(),
                src: e.target.result,
                file: file,
                exif: null,
                location: null,
                address: null,
                addressLoading: false
            };

            images.push(imageData);
            
            const img = new Image();
            img.onload = function() {
                EXIF.getData(img, function() {
                    const exifData = EXIF.getAllTags(this);
                    imageData.exif = exifData;
                    
                    if (exifData.GPSLatitude && exifData.GPSLongitude) {
                        const lat = convertDMSToDD(exifData.GPSLatitude, exifData.GPSLatitudeRef);
                        const lng = convertDMSToDD(exifData.GPSLongitude, exifData.GPSLongitudeRef);
                        imageData.location = { lat, lng };
                        
                        fetchAddress(imageData);
                    }
                    
                    updateUI();
                });
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });

    fileInput.value = '';
}

async function fetchAddress(imageData) {
    if (!imageData.location) return;
    
    if (typeof kakao === 'undefined' || !kakao.maps || !kakao.maps.services) {
        console.warn('Kakao Maps API not loaded, skipping address lookup');
        return;
    }
    
    imageData.addressLoading = true;
    
    try {
        const geocoder = new kakao.maps.services.Geocoder();
        
        geocoder.coord2Address(imageData.location.lng, imageData.location.lat, function(result, status) {
            if (status === kakao.maps.services.Status.OK) {
                const address = result[0];
                const roadAddress = address.road_address;
                const jibunAddress = address.address;
                
                let displayName = '';
                if (roadAddress) {
                    displayName = roadAddress.address_name;
                } else if (jibunAddress) {
                    displayName = jibunAddress.address_name;
                }
                
                imageData.address = {
                    display_name: displayName,
                    address: {
                        country: '대한민국',
                        state: jibunAddress?.region_1depth_name || '',
                        city: jibunAddress?.region_2depth_name || '',
                        district: jibunAddress?.region_3depth_name || '',
                        road: roadAddress?.road_name || '',
                        building: roadAddress?.building_name || '',
                        postcode: roadAddress?.zone_no || jibunAddress?.zip_code || ''
                    },
                    road_address: roadAddress,
                    jibun_address: jibunAddress
                };
                
                imageData.addressLoading = false;
                
                const imageIndex = images.indexOf(imageData);
                if (imageIndex === selectedImageIndex) {
                    displayAllLocations();
                }
                
                updateMapMarkers();
            } else {
                imageData.address = { error: '주소를 가져올 수 없습니다.' };
                imageData.addressLoading = false;
            }
        });
        
    } catch (error) {
        console.error('Address fetch error:', error);
        imageData.address = { error: '주소 조회 중 오류가 발생했습니다.' };
        imageData.addressLoading = false;
    }
    
    await new Promise(resolve => setTimeout(resolve, 300));
}

function updateMapMarkers() {
    if (!map || markers.length === 0) return;
    
    const imagesWithLocation = images.filter(img => img.location);
    
    imagesWithLocation.forEach((img, idx) => {
        const imageIndex = images.indexOf(img);
        const isSelected = imageIndex === selectedImageIndex;
        
        if (markers[idx] && markers[idx].infowindow) {
            let popupContent = `<div style="padding:10px;min-width:200px;background:white;border-radius:8px;">`;
            popupContent += `<strong style="color:#333;">📷 이미지 ${imageIndex + 1}${isSelected ? ' (선택됨)' : ''}</strong><br>`;
            popupContent += `<span style="color:#666;font-size:12px;">위도: ${img.location.lat.toFixed(6)}°<br>경도: ${img.location.lng.toFixed(6)}°</span>`;
            
            if (img.address && img.address.display_name) {
                popupContent += `<br><br><span style="color:#4ade80;font-size:12px;">🏠 ${img.address.display_name}</span>`;
            } else if (img.addressLoading) {
                popupContent += `<br><br><span style="color:#999;font-size:12px;">⏳ 주소 조회 중...</span>`;
            }
            
            popupContent += `</div>`;
            
            markers[idx].infowindow.setContent(popupContent);
        }
    });
}

function updateUI() {
    const imageGallery = document.getElementById('imageGallery');
    const imageCounter = document.getElementById('imageCounter');
    const imageCount = document.getElementById('imageCount');
    const dragHint = document.getElementById('dragHint');
    const selectedImageDisplay = document.getElementById('selectedImageDisplay');

    if (images.length > 0) {
        imageGallery.classList.add('show');
        imageCounter.classList.add('show');
        dragHint.classList.add('show');
        imageCount.textContent = images.length;
        
        renderGallery();
        
        if (selectedImageIndex === null) {
            selectImage(0);
        }
    } else {
        imageGallery.classList.remove('show');
        imageCounter.classList.remove('show');
        dragHint.classList.remove('show');
        selectedImageDisplay.classList.remove('show');
    }
}

function renderGallery() {
    const imageGallery = document.getElementById('imageGallery');
    imageGallery.innerHTML = '';
    
    images.forEach((image, index) => {
        const galleryItem = document.createElement('div');
        galleryItem.className = 'gallery-item';
        galleryItem.dataset.id = image.id;
        if (index === selectedImageIndex) {
            galleryItem.classList.add('selected');
        }
        
        const img = document.createElement('img');
        img.src = image.src;
        
        const removeBtn = document.createElement('button');
        removeBtn.className = 'gallery-item-remove';
        removeBtn.innerHTML = '✕';
        removeBtn.onclick = (e) => {
            e.stopPropagation();
            removeImage(index);
        };
        
        const orderBadge = document.createElement('div');
        orderBadge.className = 'gallery-item-order';
        orderBadge.textContent = index + 1;
        
        const badge = document.createElement('div');
        badge.className = 'gallery-item-badge';
        if (image.location) {
            badge.innerHTML = '📍 GPS';
        } else {
            badge.innerHTML = '📷';
        }
        
        galleryItem.appendChild(img);
        galleryItem.appendChild(removeBtn);
        galleryItem.appendChild(orderBadge);
        galleryItem.appendChild(badge);
        
        galleryItem.onclick = () => selectImage(index);
        
        imageGallery.appendChild(galleryItem);
    });

    // Initialize Sortable for drag and drop
    if (sortableInstance) {
        sortableInstance.destroy();
    }
    
    sortableInstance = new Sortable(imageGallery, {
        animation: 150,
        ghostClass: 'sortable-ghost',
        dragClass: 'sortable-drag',
        onEnd: function(evt) {
            const oldIndex = evt.oldIndex;
            const newIndex = evt.newIndex;
            
            // Reorder images array
            const movedImage = images.splice(oldIndex, 1)[0];
            images.splice(newIndex, 0, movedImage);
            
            // Update selected index
            if (selectedImageIndex === oldIndex) {
                selectedImageIndex = newIndex;
            } else if (oldIndex < selectedImageIndex && newIndex >= selectedImageIndex) {
                selectedImageIndex--;
            } else if (oldIndex > selectedImageIndex && newIndex <= selectedImageIndex) {
                selectedImageIndex++;
            }
            
            renderGallery();
            displayAllLocations();
            
            console.log('사진 순서 변경:', {oldIndex, newIndex});
        }
    });
}

function selectImage(index) {
    selectedImageIndex = index;
    const image = images[index];
    
    const selectedImagePreview = document.getElementById('selectedImagePreview');
    const selectedImageDisplay = document.getElementById('selectedImageDisplay');
    
    selectedImagePreview.src = image.src;
    selectedImageDisplay.classList.add('show');
    
    const promptTextarea = document.querySelector('.prompt-textarea');
    if (image.exif) {
        promptTextarea.value = formatExifData(image.exif, index + 1);
    }
    
    displayAllLocations();
    renderGallery();
}

function removeImage(index) {
    images.splice(index, 1);
    
    if (selectedImageIndex === index) {
        selectedImageIndex = images.length > 0 ? 0 : null;
    } else if (selectedImageIndex > index) {
        selectedImageIndex--;
    }
    
    updateUI();
    
    if (images.length > 0) {
        selectImage(selectedImageIndex);
    } else {
        const promptTextarea = document.querySelector('.prompt-textarea');
        promptTextarea.value = '';
        const locationSection = document.getElementById('locationSection');
        locationSection.classList.remove('show');
        if (map) {
            map = null;
        }
    }
}

function clearAllImages() {
    if (confirm('모든 이미지를 삭제하시겠습니까?')) {
        images = [];
        selectedImageIndex = null;
        markers = [];
        
        updateUI();
        
        const promptTextarea = document.querySelector('.prompt-textarea');
        promptTextarea.value = '';
        
        const locationSection = document.getElementById('locationSection');
        locationSection.classList.remove('show');
        
        if (map) {
            map = null;
        }
    }
}

function displayAllLocations() {
    const locationSection = document.getElementById('locationSection');
    const locationInfo = document.getElementById('locationInfo');
    const mapContainer = document.getElementById('mapContainer');
    const locationActions = document.getElementById('locationActions');
    
    const imagesWithLocation = images.filter(img => img.location);
    
    if (imagesWithLocation.length > 0) {
        locationSection.classList.add('show');
        
        let locationHTML = `<div style="margin-bottom: 10px; color: #4ade80; font-weight: 500;">📍 ${imagesWithLocation.length}개의 위치 정보</div>`;
        
        imagesWithLocation.forEach((img, idx) => {
            const imageIndex = images.indexOf(img);
            const isSelected = imageIndex === selectedImageIndex;
            locationHTML += `<div class="location-row" style="${isSelected ? 'background-color: rgba(74, 222, 128, 0.1); border-radius: 6px; padding: 8px;' : ''}">
                <span class="location-label">${isSelected ? '🌟 ' : ''}이미지 ${imageIndex + 1}</span>
                <span class="location-value">${img.location.lat.toFixed(6)}°, ${img.location.lng.toFixed(6)}°</span>
            </div>`;
            
            if (isSelected) {
                if (img.addressLoading) {
                    locationHTML += `<div class="address-section">
                        <div class="address-header">
                            <span class="loading-spinner"></span>
                            <span>주소 조회 중...</span>
                        </div>
                    </div>`;
                } else if (img.address) {
                    if (img.address.error) {
                        locationHTML += `<div class="address-section">
                            <div class="address-header">🏠 주소 정보</div>
                            <div class="error-message">${img.address.error}</div>
                        </div>`;
                    } else {
                        locationHTML += generateAddressHTML(img.address, imageIndex);
                    }
                }
            }
        });
        
        locationInfo.innerHTML = locationHTML;
        
        mapContainer.style.display = 'block';
        locationActions.style.display = 'flex';
        
        // 버튼 상태 업데이트
        updateKakaoButtonState();
        
        setTimeout(() => {
            initMapWithMultipleLocations(imagesWithLocation);
        }, 100);
        
    } else {
        locationSection.classList.add('show');
        locationInfo.innerHTML = '<div class="no-location">업로드된 이미지에 위치 정보가 포함되어 있지 않습니다.</div>';
        mapContainer.style.display = 'none';
        locationActions.style.display = 'none';
    }
}

function generateAddressHTML(address, imageIndex) {
    let html = '<div class="address-section">';
    html += '<div class="address-header">🏠 주소 정보</div>';
    html += '<div class="address-content">';
    
    if (address.display_name) {
        html += `<div class="address-line">
            <span class="address-label">📮 전체 주소</span>
            <span class="address-value">${address.display_name}</span>
        </div>`;
    }
    
    const addr = address.address || {};
    
    if (address.road_address) {
        const road = address.road_address;
        html += `<div class="address-line">
            <span class="address-label">🛣️ 도로명</span>
            <span class="address-value">${road.address_name || ''}</span>
        </div>`;
    }
    
    if (address.jibun_address) {
        const jibun = address.jibun_address;
        html += `<div class="address-line">
            <span class="address-label">📍 지번</span>
            <span class="address-value">${jibun.address_name || ''}</span>
        </div>`;
    }
    
    if (addr.country) {
        html += `<div class="address-line">
            <span class="address-label">🌍 국가</span>
            <span class="address-value">${addr.country}</span>
        </div>`;
    }
    
    if (addr.state) {
        html += `<div class="address-line">
            <span class="address-label">📍 시/도</span>
            <span class="address-value">${addr.state}</span>
        </div>`;
    }
    
    if (addr.city) {
        html += `<div class="address-line">
            <span class="address-label">🏙️ 시/군/구</span>
            <span class="address-value">${addr.city}</span>
        </div>`;
    }
    
    if (addr.district) {
        html += `<div class="address-line">
            <span class="address-label">🏘️ 동/읍/면</span>
            <span class="address-value">${addr.district}</span>
        </div>`;
    }
    
    if (addr.building) {
        html += `<div class="address-line">
            <span class="address-label">🏢 건물명</span>
            <span class="address-value">${addr.building}</span>
        </div>`;
    }
    
    if (addr.postcode) {
        html += `<div class="address-line">
            <span class="address-label">📬 우편번호</span>
            <span class="address-value">${addr.postcode}</span>
        </div>`;
    }
    
    html += '</div>';
    html += `<button class="copy-address-btn" onclick="copyAddress(${imageIndex})">📋 주소 복사</button>`;
    html += '</div>';
    
    return html;
}

function copyAddress(imageIndex) {
    const image = images[imageIndex];
    if (image && image.address && image.address.display_name) {
        navigator.clipboard.writeText(image.address.display_name)
            .then(() => {
                alert('주소가 클립보드에 복사되었습니다! 📋');
            })
            .catch(err => {
                console.error('주소 복사 실패: ', err);
                alert('주소 복사에 실패했습니다.');
            });
    }
}

function initMapWithMultipleLocations(imagesWithLocation) {
    const mapElement = document.getElementById('locationMap');
    
    if (typeof kakao === 'undefined' || !kakao.maps) {
        mapElement.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:center;height:100%;background:#1a1a1a;color:#ff6b6b;padding:20px;text-align:center;border-radius:8px;">
                <div>
                    <div style="font-size:40px;margin-bottom:10px;">🗺️</div>
                    <div style="font-size:14px;margin-bottom:10px;"><strong>카카오맵 API 키가 필요합니다</strong></div>
                    <div style="font-size:12px;color:#999;line-height:1.6;">
                        1. <a href="https://developers.kakao.com/" target="_blank" style="color:#4ade80;">developers.kakao.com</a>에서 앱 등록<br>
                        2. JavaScript 키를 복사<br>
                        3. HTML 파일의 YOUR_APP_KEY를 교체
                    </div>
                </div>
            </div>
        `;
        return;
    }
    
    if (map) {
        map = null;
    }
    
    // 기존 경로 선 제거
    if (pathPolyline) {
        pathPolyline.setMap(null);
        pathPolyline = null;
    }
    
    markers = [];
    
    const lats = imagesWithLocation.map(img => img.location.lat);
    const lngs = imagesWithLocation.map(img => img.location.lng);
    const centerLat = lats.reduce((a, b) => a + b) / lats.length;
    const centerLng = lngs.reduce((a, b) => a + b) / lngs.length;
    
    try {
        const mapContainer = document.getElementById('locationMap');
        const mapOption = {
            center: new kakao.maps.LatLng(centerLat, centerLng),
            level: imagesWithLocation.length === 1 ? 3 : 5
        };
        
        map = new kakao.maps.Map(mapContainer, mapOption);
        
        const bounds = new kakao.maps.LatLngBounds();
        const pathPoints = [];
        
        imagesWithLocation.forEach((img, idx) => {
            const imageIndex = images.indexOf(img);
            const isSelected = imageIndex === selectedImageIndex;
            
            const position = new kakao.maps.LatLng(img.location.lat, img.location.lng);
            pathPoints.push(position);
            
            let markerImage = null;
            if (isSelected) {
                const imageSrc = 'https://t1.daumcdn.net/localimg/localimages/07/mapapidoc/marker_red.png';
                const imageSize = new kakao.maps.Size(64, 69);
                const imageOption = { offset: new kakao.maps.Point(27, 69) };
                markerImage = new kakao.maps.MarkerImage(imageSrc, imageSize, imageOption);
            }
            
            const marker = new kakao.maps.Marker({
                position: position,
                map: map,
                image: markerImage
            });
            
            let popupContent = `<div style="padding:10px;min-width:200px;background:white;border-radius:8px;">`;
            popupContent += `<strong style="color:#333;">📷 이미지 ${imageIndex + 1}${isSelected ? ' (선택됨)' : ''}</strong><br>`;
            popupContent += `<span style="color:#666;font-size:12px;">위도: ${img.location.lat.toFixed(6)}°<br>경도: ${img.location.lng.toFixed(6)}°</span>`;
            
            if (img.address && img.address.display_name) {
                popupContent += `<br><br><span style="color:#4ade80;font-size:12px;">🏠 ${img.address.display_name}</span>`;
            } else if (img.addressLoading) {
                popupContent += `<br><br><span style="color:#999;font-size:12px;">⏳ 주소 조회 중...</span>`;
            }
            
            popupContent += `</div>`;
            
            const infowindow = new kakao.maps.InfoWindow({
                content: popupContent,
                removable: true
            });
            
            kakao.maps.event.addListener(marker, 'click', function() {
                markers.forEach(m => {
                    if (m.infowindow) {
                        m.infowindow.close();
                    }
                });
                infowindow.open(map, marker);
            });
            
            if (isSelected) {
                infowindow.open(map, marker);
            }
            
            markers.push({ marker, infowindow, imageIndex });
            bounds.extend(position);
        });
        
        // 🌳 경로 선 그리기
        const category = document.getElementById('category-select').value;
        if (category === '가로수' && pathPoints.length >= 2) {
            pathPolyline = new kakao.maps.Polyline({
                path: pathPoints,
                strokeWeight: 5,
                strokeColor: '#4ade80',
                strokeOpacity: 0.8,
                strokeStyle: 'solid'
            });
            pathPolyline.setMap(map);
            console.log('🌳 가로수 경로 선 표시:', pathPoints.length + '개 지점');
        }
        
        if (imagesWithLocation.length > 1) {
            map.setBounds(bounds);
        }
    } catch (error) {
        console.error('Map initialization error:', error);
        mapElement.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:center;height:100%;background:#1a1a1a;color:#ff6b6b;padding:20px;text-align:center;">
                <div>지도 로딩 중 오류가 발생했습니다.<br><span style="font-size:12px;color:#999;">${error.message}</span></div>
            </div>
        `;
    }
}

function toggleMapFullscreen() {
    const mapContainer = document.getElementById('mapContainer');
    mapContainer.classList.toggle('fullscreen');

    if (map) {
        setTimeout(() => {
            map.relayout();
            
            const imagesWithLocation = images.filter(img => img.location);
            if (imagesWithLocation.length > 0) {
                const bounds = new kakao.maps.LatLngBounds();
                imagesWithLocation.forEach(img => {
                    bounds.extend(new kakao.maps.LatLng(img.location.lat, img.location.lng));
                });
                map.setBounds(bounds);
            }
        }, 100);
    }
}

function convertDMSToDD(dms, ref) {
    if (!dms || dms.length < 3) return 0;
    
    const degrees = dms[0];
    const minutes = dms[1];
    const seconds = dms[2];
    
    let dd = degrees + minutes/60 + seconds/3600;
    
    if (ref === 'S' || ref === 'W') {
        dd = dd * -1;
    }
    
    return dd;
}

function openKakaoDirections() { 
    const imagesWithLocation = images.filter(img => img.location);
    
    if (imagesWithLocation.length === 0) {
        alert('GPS 정보가 있는 사진이 없습니다.');
        return;
    }
    
    const firstImage = imagesWithLocation[0];
    const lastImage = imagesWithLocation[imagesWithLocation.length - 1];
    
    const startLoc = firstImage.location;
    const endLoc = lastImage.location;
    
    let startName = "가로수 시작점";
    if (firstImage.address && firstImage.address.display_name) {
        startName = firstImage.address.display_name;
    }
    
    let endName = "가로수 종료점";
    if (lastImage.address && lastImage.address.display_name) {
        endName = lastImage.address.display_name;
    }
    
    const encodedStartName = encodeURIComponent(startName);
    const encodedEndName = encodeURIComponent(endName);
    
    if (imagesWithLocation.length === 1) {
        const url = `https://map.kakao.com/link/to/${encodedEndName},${endLoc.lat},${endLoc.lng}`;
        window.open(url, '_blank');
        console.log('🗺️ 카카오맵 길찾기 (목적지만):', url);
    } else {
        const url = `https://map.kakao.com/link/from/${encodedStartName},${startLoc.lat},${startLoc.lng}/to/${encodedEndName},${endLoc.lat},${endLoc.lng}`;
        window.open(url, '_blank');
        console.log('🗺️ 카카오맵 길찾기 (출발→도착):', url);
        console.log(`  📍 출발: ${startName} (${startLoc.lat}, ${startLoc.lng})`);
        console.log(`  🎯 도착: ${endName} (${endLoc.lat}, ${endLoc.lng})`);
    }
}

function copyCoordinates() {
    const imagesWithLocation = images.filter(img => img.location);
    if (imagesWithLocation.length > 0) {
        const coords = imagesWithLocation.map((img, idx) => {
            const imageIndex = images.indexOf(img);
            let text = `이미지 ${imageIndex + 1}: ${img.location.lat.toFixed(6)}, ${img.location.lng.toFixed(6)}`;
            if (img.address && img.address.display_name) {
                text += `\n주소: ${img.address.display_name}`;
            }
            return text;
        }).join('\n\n');
        
        navigator.clipboard.writeText(coords)
            .then(() => {
                alert('모든 좌표와 주소가 클립보드에 복사되었습니다! 📋');
            })
            .catch(err => {
                console.error('좌표 복사 실패: ', err);
                alert('좌표 복사에 실패했습니다.');
            });
    }
}

function formatExifData(exif, imageNumber) {
    let exifText = `📷 이미지 ${imageNumber} EXIF 정보:\n\n`;
    
    if (exif.Make || exif.Model) {
        exifText += `카메라: ${exif.Make || ''} ${exif.Model || ''}\n`;
    }
    
    if (exif.DateTime || exif.DateTimeOriginal) {
        exifText += `촬영 날짜: ${exif.DateTime || exif.DateTimeOriginal}\n`;
    }
    
    if (exif.ExposureTime) {
        const shutterSpeed = exif.ExposureTime < 1 
            ? `1/${Math.round(1/exif.ExposureTime)}`
            : exif.ExposureTime;
        exifText += `셔터 속도: ${shutterSpeed}s\n`;
    }
    
    if (exif.FNumber) {
        exifText += `조리개: f/${exif.FNumber}\n`;
    }
    
    if (exif.ISOSpeedRatings) {
        exifText += `ISO: ${exif.ISOSpeedRatings}\n`;
    }
    
    if (exif.FocalLength) {
        exifText += `초점 거리: ${exif.FocalLength}mm\n`;
    }
    
    if (exif.LensModel) {
        exifText += `렌즈: ${exif.LensModel}\n`;
    }
    
    if (exif.PixelXDimension && exif.PixelYDimension) {
        exifText += `해상도: ${exif.PixelXDimension} × ${exif.PixelYDimension}\n`;
    }
    
    if (exif.WhiteBalance !== undefined) {
        const wb = exif.WhiteBalance === 0 ? '자동' : '수동';
        exifText += `화이트 밸런스: ${wb}\n`;
    }
    
    if (exif.Flash !== undefined) {
        const flash = exif.Flash === 0 ? '플래시 없음' : '플래시 사용';
        exifText += `플래시: ${flash}\n`;
    }
    
    if (exif.Software) {
        exifText += `\n소프트웨어: ${exif.Software}\n`;
    }
    
    if (Object.keys(exif).length === 0) {
        return `이미지 ${imageNumber}에서 EXIF 정보를 찾을 수 없습니다.\n\n수목 상태, 병충해 여부 등 상세 메모를 입력하세요.`;
    }
    
    exifText += "\n---\n수목 상태, 병충해 여부 등 상세 메모를 입력하세요."
    return exifText;
}

function generateVideo() {
    if (images.length === 0) {
        alert('먼저 현장 사진을 업로드해주세요! 📸');
        return;
    }
    
    if (accessToken && confirm('Google Drive에 모든 사진을 업로드하시겠습니까?')) {
        uploadToGoogleDrive('all');
    } else if (!accessToken) {
        alert('정보가 로컬에 임시 저장되었습니다.\n(백업을 위해 Google Drive 로그인을 권장합니다.)');
    } else {
         alert('정보가 로컬에 임시 저장되었습니다.');
    }

    console.log("--- 저장할 데이터 ---");
    
    const selectedFilters = {
        region: document.getElementById('region-select').value,
        category: document.getElementById('category-select').value,
        place: document.getElementById('place-select').value,
        tree: document.getElementById('tree-select').value
    };
    console.log("필터 선택:", selectedFilters);
    console.log("메모:", document.querySelector('.prompt-textarea').value);
    console.log("사진 정보:", images);
}

function updateKakaoButtonState() {
    const category = document.getElementById('category-select').value;
    const place = document.getElementById('place-select').value;
    const kakaoBtn = document.getElementById('kakaoDirectionsBtn');

    if (!kakaoBtn) return;

    if (category === '가로수' && place !== '') {
        kakaoBtn.disabled = false;
    } else {
        kakaoBtn.disabled = true;
    }
    
    console.log('버튼 상태 업데이트:', {category, place, disabled: kakaoBtn.disabled});
}

function onRegionChange() {
    const region = document.getElementById('region-select').value;
    const categorySelect = document.getElementById('category-select');
    const placeSelect = document.getElementById('place-select');
    const treeSelect = document.getElementById('tree-select');
    
    categorySelect.value = '';
    placeSelect.value = '';
    treeSelect.value = '';
    placeSelect.innerHTML = '<option value="">전체</option>';
    treeSelect.innerHTML = '<option value="">전체</option>';
    
    if (region) {
        categorySelect.disabled = false;
    } else {
        categorySelect.disabled = true;
        placeSelect.disabled = true;
        treeSelect.disabled = true;
    }

    updateKakaoButtonState();
}

function onCategoryChange() {
    const region = document.getElementById('region-select').value;
    const category = document.getElementById('category-select').value;
    const placeSelect = document.getElementById('place-select');
    const treeSelect = document.getElementById('tree-select');
    
    placeSelect.value = '';
    treeSelect.value = '';
    treeSelect.innerHTML = '<option value="">전체</option>';
    
    if (category) {
        placeSelect.disabled = false;
        const places = [...new Set(allTreeData 
            .filter(t => t.region === region && t.category === category)
            .map(t => t.place))];
        placeSelect.innerHTML = '<option value="">전체</option>' +
            places.map(p => `<option value="${p}">${p}</option>`).join('');
    } else {
        placeSelect.disabled = true;
        placeSelect.innerHTML = '<option value="">전체</option>';
        treeSelect.disabled = true;
    }

    updateKakaoButtonState();
    
    if (images.filter(img => img.location).length > 0) {
        displayAllLocations();
    }
}

function onPlaceChange() {
    const region = document.getElementById('region-select').value;
    const category = document.getElementById('category-select').value;
    const place = document.getElementById('place-select').value;
    const treeSelect = document.getElementById('tree-select');
    
    treeSelect.value = '';
    if (place) {
        treeSelect.disabled = false;
        const trees = [...new Set(allTreeData
            .filter(t => t.region === region && t.category === category && t.place === place)
            .map(t => t.tree))];
        treeSelect.innerHTML = '<option value="">전체</option>' +
            trees.map(tr => `<option value="${tr}">${tr}</option>`).join('');
    } else {
        treeSelect.disabled = false;
        const trees = [...new Set(allTreeData
            .filter(t => t.region === region && t.category === category)
            .map(t => t.tree))];
        treeSelect.innerHTML = '<option value="">전체</option>' +
            trees.map(tr => `<option value="${tr}">${tr}</option>`).join('');
    }

    updateKakaoButtonState();
}

function applyFilters() {
    const region = document.getElementById('region-select').value;
    const category = document.getElementById('category-select').value;
    const place = document.getElementById('place-select').value;
    const tree = document.getElementById('tree-select').value;
    
    alert(`검색 실행:\n- 지역: ${region}\n- 카테고리: ${category}\n- 장소: ${place}\n- 나무: ${tree}`);
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeFileUpload();
    
    // Recommendation items click handlers
    document.querySelectorAll('.recommendation-item').forEach(item => {
        item.addEventListener('click', function() {
            alert('추천 항목을 클릭했습니다!');
        });
    });
});

// ========================================
// 기존 데이터 불러오기 및 통합 기능
// ========================================

// 기존 폴더에서 데이터 불러오기
async function loadExistingData() {
    if (!accessToken) {
        alert('먼저 Google 계정으로 로그인해주세요.');
        return;
    }
    
    try {
        console.log('📂 기존 데이터 불러오기 시작...');
        
        const targetFolderId = API_CONFIG.google.targetFolderId || 'root';
        
        // 폴더 선택 UI 표시
        const folderListHtml = await getFolderList(targetFolderId);
        
        // 모달 생성
        showModal('기존 폴더 선택', folderListHtml);
        
    } catch (error) {
        console.error('데이터 불러오기 오류:', error);
        alert('데이터를 불러오는 중 오류가 발생했습니다: ' + error.message);
    }
}

// 폴더 목록 가져오기
async function getFolderList(parentFolderId) {
    const query = `'${parentFolderId}' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false`;
    
    const response = await fetch(
        `https://www.googleapis.com/drive/v3/files?q=${encodeURIComponent(query)}&fields=files(id,name,modifiedTime)&orderBy=modifiedTime desc`,
        {
            headers: { 'Authorization': `Bearer ${accessToken}` }
        }
    );
    
    const data = await response.json();
    const folders = data.files || [];
    
    if (folders.length === 0) {
        return '<p style="color: white; text-align: center; padding: 20px;">저장된 폴더가 없습니다.</p>';
    }
    
    let html = '<div style="max-height: 400px; overflow-y: auto;">';
    folders.forEach(folder => {
        const date = new Date(folder.modifiedTime).toLocaleString('ko-KR');
        html += `
            <div class="folder-item" onclick="loadFolderData('${folder.id}', '${folder.name}')" style="
                background: rgba(255,255,255,0.1);
                padding: 15px;
                margin: 10px 0;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.3s;
            " onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                <div style="color: white; font-size: 16px; font-weight: 500;">📁 ${folder.name}</div>
                <div style="color: rgba(255,255,255,0.7); font-size: 12px; margin-top: 5px;">수정: ${date}</div>
            </div>
        `;
    });
    html += '</div>';
    
    return html;
}

// 선택한 폴더의 데이터 불러오기
async function loadFolderData(folderId, folderName) {
    try {
        console.log('📥 폴더 데이터 불러오기:', folderName);
        
        closeModal();
        
        // 진행 상황 표시
        const progressContainer = document.getElementById('uploadProgress');
        progressContainer.innerHTML = '';
        progressContainer.classList.add('show');
        
        const loadingMsg = document.createElement('div');
        loadingMsg.className = 'progress-item';
        loadingMsg.innerHTML = `<span>📂 ${folderName} 데이터 불러오는 중...</span>`;
        progressContainer.appendChild(loadingMsg);
        
        // 폴더 내 파일 목록 가져오기
        const query = `'${folderId}' in parents and trashed=false`;
        const response = await fetch(
            `https://www.googleapis.com/drive/v3/files?q=${encodeURIComponent(query)}&fields=files(id,name,mimeType,webContentLink,thumbnailLink)`,
            {
                headers: { 'Authorization': `Bearer ${accessToken}` }
            }
        );
        
        const data = await response.json();
        const files = data.files || [];
        
        // 이미지 파일 필터링
        const imageFiles = files.filter(f => f.mimeType.startsWith('image/'));
        
        // 스프레드시트 찾기
        const spreadsheet = files.find(f => f.mimeType === 'application/vnd.google-apps.spreadsheet');
        
        console.log(`✅ 이미지 ${imageFiles.length}개, 스프레드시트 ${spreadsheet ? 1 : 0}개 발견`);
        
        // 이미지 다운로드 및 표시
        for (const imageFile of imageFiles) {
            await loadImageFromDrive(imageFile);
        }
        
        // 스프레드시트 데이터 불러오기
        if (spreadsheet) {
            await loadSpreadsheetData(spreadsheet.id);
        }
        
        const successMsg = document.createElement('div');
        successMsg.className = 'success-message';
        successMsg.innerHTML = `
            <span>✅</span>
            <span>${imageFiles.length}개의 이미지와 데이터를 불러왔습니다!</span>
        `;
        progressContainer.appendChild(successMsg);
        
        setTimeout(() => {
            progressContainer.classList.remove('show');
        }, 3000);
        
    } catch (error) {
        console.error('폴더 데이터 불러오기 오류:', error);
        alert('데이터를 불러오는 중 오류가 발생했습니다: ' + error.message);
    }
}

// Drive에서 이미지 불러오기
async function loadImageFromDrive(imageFile) {
    try {
        // 이미지 다운로드
        const response = await fetch(
            `https://www.googleapis.com/drive/v3/files/${imageFile.id}?alt=media`,
            {
                headers: { 'Authorization': `Bearer ${accessToken}` }
            }
        );
        
        const blob = await response.blob();
        const reader = new FileReader();
        
        return new Promise((resolve) => {
            reader.onload = (e) => {
                const imageData = {
                    id: Date.now() + Math.random(),
                    src: e.target.result,
                    file: new File([blob], imageFile.name, { type: blob.type }),
                    exif: null,
                    location: null,
                    address: null,
                    addressLoading: false,
                    fromDrive: true,
                    driveFileId: imageFile.id
                };
                
                images.push(imageData);
                
                // EXIF 데이터 추출
                const img = new Image();
                img.onload = function() {
                    EXIF.getData(img, function() {
                        const exifData = EXIF.getAllTags(this);
                        imageData.exif = exifData;
                        
                        if (exifData.GPSLatitude && exifData.GPSLongitude) {
                            const lat = convertDMSToDD(exifData.GPSLatitude, exifData.GPSLatitudeRef);
                            const lng = convertDMSToDD(exifData.GPSLongitude, exifData.GPSLongitudeRef);
                            imageData.location = { lat, lng };
                            fetchAddress(imageData);
                        }
                        
                        updateUI();
                        resolve();
                    });
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(blob);
        });
        
    } catch (error) {
        console.error('이미지 로드 오류:', error);
    }
}

// 스프레드시트 데이터 불러오기
// 스프레드시트 데이터 불러오기
async function loadSpreadsheetData(spreadsheetId) {
    try {
        console.log('📊 스프레드시트 데이터 불러오기...');
        
        // [수정됨] 1. 스프레드시트 정보 가져오기 (첫 번째 시트 이름 확인)
        const sheetInfoResponse = await fetch(
            `https://sheets.googleapis.com/v4/spreadsheets/${spreadsheetId}?fields=sheets(properties.title)`,
            {
                headers: { 'Authorization': `Bearer ${accessToken}` }
            }
        );
        if (!sheetInfoResponse.ok) throw new Error('스프레드시트 정보를 가져올 수 없습니다.');
        
        const sheetInfo = await sheetInfoResponse.json();
        const sheetTitle = sheetInfo.sheets[0].properties.title;
        console.log('✅ 읽어올 시트 이름:', sheetTitle);

        // [수정됨] 2. 동적 시트 이름으로 요약 정보 불러오기
        const summaryResponse = await fetch(
            `https://sheets.googleapis.com/v4/spreadsheets/${spreadsheetId}/values/${encodeURIComponent(sheetTitle)}!A1:B14`,
            {
                headers: { 'Authorization': `Bearer ${accessToken}` }
            }
        );
        
        const summaryData = await summaryResponse.json();
        
        // 메모 필드에 데이터 채우기
        if (summaryData.values) {
            const memoRow = summaryData.values.find(row => row[0] && row[0].includes('메모'));
            if (memoRow && memoRow[0]) {
                const memoIndex = summaryData.values.indexOf(memoRow);
                if (summaryData.values[memoIndex + 1]) {
                    document.querySelector('.prompt-textarea').value = summaryData.values[memoIndex + 1][0] || '';
                }
            }
        }
        
        console.log('✅ 스프레드시트 데이터 불러오기 완료');
        
    } catch (error) {
        console.error('스프레드시트 불러오기 오류:', error);
    }
}

// 스프레드시트 통합하기
async function mergeSpreadsheets() {
    if (!accessToken) {
        alert('먼저 Google 계정으로 로그인해주세요.');
        return;
    }
    
    try {
        console.log('📊 스프레드시트 통합 시작...');
        
        const targetFolderId = API_CONFIG.google.targetFolderId || 'root';
        
        // 통합할 스프레드시트 선택
        const spreadsheetListHtml = await getSpreadsheetList(targetFolderId);
        
        showModal('통합할 스프레드시트 선택', spreadsheetListHtml);
        
    } catch (error) {
        console.error('스프레드시트 통합 오류:', error);
        alert('스프레드시트 통합 중 오류가 발생했습니다: ' + error.message);
    }
}

// 스프레드시트 목록 가져오기
async function getSpreadsheetList(parentFolderId) {
    // 지정된 폴더의 모든 하위 폴더 검색
    const foldersQuery = `'${parentFolderId}' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false`;
    const foldersResponse = await fetch(
        `https://www.googleapis.com/drive/v3/files?q=${encodeURIComponent(foldersQuery)}&fields=files(id,name)`,
        {
            headers: { 'Authorization': `Bearer ${accessToken}` }
        }
    );
    
    const foldersData = await foldersResponse.json();
    const folders = foldersData.files || [];
    
    let allSpreadsheets = [];
    
    // 각 폴더에서 스프레드시트 찾기
    for (const folder of folders) {
        const sheetsQuery = `'${folder.id}' in parents and mimeType='application/vnd.google-apps.spreadsheet' and trashed=false`;
        const sheetsResponse = await fetch(
            `https://www.googleapis.com/drive/v3/files?q=${encodeURIComponent(sheetsQuery)}&fields=files(id,name,modifiedTime)`,
            {
                headers: { 'Authorization': `Bearer ${accessToken}` }
            }
        );
        
        const sheetsData = await sheetsResponse.json();
        if (sheetsData.files) {
            sheetsData.files.forEach(sheet => {
                allSpreadsheets.push({
                    ...sheet,
                    folderName: folder.name
                });
            });
        }
    }
    
    if (allSpreadsheets.length === 0) {
        return '<p style="color: white; text-align: center; padding: 20px;">통합할 스프레드시트가 없습니다.</p>';
    }
    
    let html = `
        <div style="max-height: 400px; overflow-y: auto; margin-bottom: 15px;">
            <div style="color: white; margin-bottom: 10px;">통합할 스프레드시트를 선택하세요 (여러 개 선택 가능):</div>
    `;
    
    allSpreadsheets.forEach((sheet, index) => {
        const date = new Date(sheet.modifiedTime).toLocaleDateString('ko-KR');
        html += `
            <div class="spreadsheet-item" style="
                background: rgba(255,255,255,0.1);
                padding: 12px;
                margin: 8px 0;
                border-radius: 8px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 10px;
            ">
                <input type="checkbox" id="sheet-${index}" value="${sheet.id}" style="width: 18px; height: 18px; cursor: pointer;">
                <label for="sheet-${index}" style="color: white; cursor: pointer; flex: 1;">
                    <div style="font-weight: 500;">📊 ${sheet.name}</div>
                    <div style="font-size: 12px; color: rgba(255,255,255,0.7);">📁 ${sheet.folderName} | ${date}</div>
                </label>
            </div>
        `;
    });
    
    html += `
        </div>
        <button onclick="executeMerge()" style="
            width: 100%;
            padding: 12px;
            background: white;
            color: #4285f4;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        ">선택한 스프레드시트 통합하기</button>
    `;
    
    return html;
}

// 스프레드시트 통합 실행
// 스프레드시트 통합 실행
async function executeMerge() {
    const progressContainer = document.getElementById('uploadProgress');
    
    try {
        const checkboxes = document.querySelectorAll('.spreadsheet-item input[type="checkbox"]:checked');
        
        if (checkboxes.length === 0) {
            alert('최소 1개 이상의 스프레드시트를 선택해주세요.');
            return;
        }
        
        const spreadsheetIds = Array.from(checkboxes).map(cb => cb.value);
        
        console.log('📊 스프레드시트 통합 실행:', spreadsheetIds.length, '개');
        
        closeModal();
        
        // 진행 상황 표시
        progressContainer.innerHTML = '';
        progressContainer.classList.add('show');
        
        const loadingMsg = document.createElement('div');
        loadingMsg.className = 'progress-item';
        loadingMsg.innerHTML = `<span>📊 ${spreadsheetIds.length}개의 스프레드시트 통합 중... (0%)</span>`;
        progressContainer.appendChild(loadingMsg);

        // 1. 새 통합 스프레드시트 생성
        loadingMsg.innerHTML = `<span>📊 새 통합 문서 생성 중...</span>`;
        const mergedTitle = `통합_현장조사_${new Date().toISOString().split('T')[0]}`;
        const createResponse = await fetch('https://sheets.googleapis.com/v4/spreadsheets', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${accessToken}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                properties: { title: mergedTitle }
            })
        });

        if (!createResponse.ok) {
            const errorData = await createResponse.json();
            throw new Error(`새 통합 문서 생성 실패: ${errorData.error.message}`);
        }
        
        const newSheet = await createResponse.json();
        const mergedSpreadsheetId = newSheet.spreadsheetId;
        const mergedSheetTitle = newSheet.sheets[0].properties.title; // '시트1' 등
        console.log('✅ 통합 스프레드시트 생성:', mergedSpreadsheetId, '시트명:', mergedSheetTitle);

        // 2. 각 스프레드시트에서 데이터 가져와서 통합
        let allRows = [['사진번호', '파일명', '크기(KB)', '위도', '경도', '전체주소', '시/도', '시/군/구', '동/읍/면', '카메라', '촬영날짜', '출처폴더']];
        
        for (let i = 0; i < spreadsheetIds.length; i++) {
            const sheetId = spreadsheetIds[i];
            const progressPercent = Math.round(((i + 1) / (spreadsheetIds.length + 2)) * 100);
            loadingMsg.innerHTML = `<span>📊 ${i + 1}/${spreadsheetIds.length}번째 데이터 수집 중... (${progressPercent}%)</span>`;

            // 2-1. 각 시트의 첫 번째 시트 이름 가져오기
            let sheetTitle = 'Sheet1'; // 기본값
            try {
                const sheetInfoResponse = await fetch(
                   `https://sheets.googleapis.com/v4/spreadsheets/${sheetId}?fields=sheets(properties.title)`,
                   { headers: { 'Authorization': `Bearer ${accessToken}` } }
                );
                if (sheetInfoResponse.ok) {
                    const sheetInfo = await sheetInfoResponse.json();
                    if (sheetInfo.sheets && sheetInfo.sheets.length > 0) {
                        sheetTitle = sheetInfo.sheets[0].properties.title;
                    }
                }
            } catch (infoError) {
                console.warn(`시트 이름(${sheetId}) 조회 실패, 'Sheet1'로 시도합니다.`, infoError);
            }

            // 2-2. 동적 시트 이름으로 데이터 범위 가져오기 (A17부터 K열 끝까지)
            const dataResponse = await fetch(
               `https://sheets.googleapis.com/v4/spreadsheets/${sheetId}/values/${encodeURIComponent(sheetTitle)}!A17:K`,
               { headers: { 'Authorization': `Bearer ${accessToken}` } }
            );

            if (!dataResponse.ok) {
                // 범위가 없거나 해도 오류가 나지만, 전체를 멈추진 않고 경고만 합니다.
                console.warn(`시트(${sheetId})에서 'A17:K' 범위 데이터를 읽는 데 실패했습니다. 건너뜁니다.`);
                continue; // 다음 루프로 넘어감
            }
            
            const data = await dataResponse.json();
            
            if (data.values && data.values.length > 1) {
                const rows = data.values.slice(1); // 헤더 제외
                const labelEl = checkboxes[i].closest('.spreadsheet-item').querySelector('label div:last-child');
                const sourceLabel = labelEl ? labelEl.textContent : `스프레드시트 ${i + 1}`;
                
                rows.forEach(row => {
                    const newRow = row.slice(0, 11); // A~K (11개)
                    while (newRow.length < 11) newRow.push(''); // 혹시 모를 빈 셀 채우기
                    newRow.push(sourceLabel); // L열에 출처 추가
                    allRows.push(newRow);
                });
            }
            console.log(`✅ ${i + 1}/${spreadsheetIds.length} 데이터 수집 완료 (시트명: ${sheetTitle})`);
        } // --- for loop end ---
        
        // 3. 통합된 데이터를 새 시트에 쓰기
        loadingMsg.innerHTML = `<span>📊 통합 데이터 저장 중... (${Math.round(((spreadsheetIds.length + 1) / (spreadsheetIds.length + 2)) * 100)}%)</span>`;
        const writeResponse = await fetch(
           `https://sheets.googleapis.com/v4/spreadsheets/${mergedSpreadsheetId}/values/${encodeURIComponent(mergedSheetTitle)}!A1?valueInputOption=RAW`,
           {
               method: 'PUT',
               headers: {
                   'Authorization': `Bearer ${accessToken}`,
                   'Content-Type': 'application/json'
               },
               body: JSON.stringify({ values: allRows })
           }
        );

        if (!writeResponse.ok) {
            const errorData = await writeResponse.json();
            throw new Error(`통합 데이터 쓰기 실패: ${errorData.error.message}`);
        }

        // 4. 통합 스프레드시트를 폴더로 이동
        loadingMsg.innerHTML = `<span>📁 폴더로 이동 중... (100%)</span>`;
        const targetFolderId = API_CONFIG.google.targetFolderId;
        if (targetFolderId && targetFolderId !== 'root') {
            const moveResponse = await fetch(
               `https://www.googleapis.com/drive/v3/files/${mergedSpreadsheetId}?addParents=${targetFolderId}&removeParents=root`,
               {
                   method: 'PATCH',
                   headers: {
                       'Authorization': `Bearer ${accessToken}`,
                       'Content-Type': 'application/json'
                   }
               }
            );
            if (!moveResponse.ok) {
                const errorData = await moveResponse.json();
                throw new Error(`파일 이동 실패: ${errorData.error.message}`);
            }
        }
        
        // 5. 최종 성공 메시지
        const successMsg = document.createElement('div');
        successMsg.className = 'success-message';
        successMsg.innerHTML = `
            <span>✅</span>
            <span>${spreadsheetIds.length}개의 스프레드시트가 통합되었습니다! (총 ${allRows.length - 1}개 데이터)</span>
            <button onclick="window.open('https://docs.google.com/spreadsheets/d/${mergedSpreadsheetId}', '_blank')" 
                    style="width: 100%; margin-top: 10px; padding: 10px; background: white; color: #34a853; border: none; border-radius: 6px; cursor: pointer;">
                📊 통합 스프레드시트 보기
            </button>
        `;
        loadingMsg.remove(); // 로딩 메시지 제거
        progressContainer.appendChild(successMsg);
        
    } catch (error) {
        console.error('❌ 스프레드시트 통합 실행 오류:', error);
        alert('통합 중 오류가 발생했습니다:\n\n' + error.message);
        
        // 오류 발생 시 프로그레스 바 내용 초기화
        progressContainer.innerHTML = `<div class="progress-item" style="color: #ff6b6b;">
             <span>🚫 통합 실패: ${error.message}</span>
        </div>`;
    }
}

// 모달 표시
function showModal(title, content) {
    const modal = document.createElement('div');
    modal.id = 'customModal';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
    `;
    
    modal.innerHTML = `
        <div style="
            background: linear-gradient(135deg, #4285f4, #34a853);
            border-radius: 12px;
            padding: 20px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        ">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: white; margin: 0;">${title}</h2>
                <button onclick="closeModal()" style="
                    background: rgba(255,255,255,0.2);
                    border: 1px solid white;
                    color: white;
                    border-radius: 50%;
                    width: 32px;
                    height: 32px;
                    cursor: pointer;
                    font-size: 18px;
                ">✕</button>
            </div>
            <div>${content}</div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

// 모달 닫기
function closeModal() {
    const modal = document.getElementById('customModal');
    if (modal) {
        modal.remove();
    }
}



// ================================================
// 🎨 개선된 UI/UX - JavaScript 수정 사항
// ================================================

// 이 코드들을 기존 script.js 파일에 추가하거나 교체하세요


// ====================================
// ✨ 성공 메시지 생성 함수 (새로 추가)
// ====================================

function createSuccessMessage(imageCount, folderId, spreadsheetId) {
    const successMsg = document.createElement('div');
    successMsg.className = 'success-message';
    
    // 헤더 부분
    const header = document.createElement('div');
    header.className = 'success-message-header';
    header.innerHTML = `
        <span class="icon">✅</span>
        <span>업로드가 완료되었습니다!</span>
    `;
    
    // 바디 부분
    const body = document.createElement('div');
    body.className = 'success-message-body';
    
    // 업로드 정보
    const info = document.createElement('div');
    info.className = 'success-info';
    info.innerHTML = `
        <span>📸</span>
        <span><strong>${imageCount}개</strong>의 이미지와 상세 정보가 저장되었습니다</span>
    `;
    body.appendChild(info);
    
    // 스프레드시트 정보 (있을 경우)
    if (spreadsheetId) {
        const sheetInfo = document.createElement('div');
        sheetInfo.className = 'success-info';
        sheetInfo.innerHTML = `
            <span>📊</span>
            <span>스프레드시트가 생성되었습니다</span>
        `;
        body.appendChild(sheetInfo);
    }
    
    // 액션 버튼들
    const actions = document.createElement('div');
    actions.className = 'success-actions';
    
    // 폴더 보기 버튼
    const folderBtn = document.createElement('button');
    folderBtn.className = 'success-btn';
    folderBtn.innerHTML = `
        <span>📁</span>
        <span>폴더 보기</span>
    `;
    folderBtn.onclick = () => window.open(`https://drive.google.com/drive/folders/${folderId}`, '_blank');
    actions.appendChild(folderBtn);
    
    // 스프레드시트 보기 버튼 (있을 경우)
    if (spreadsheetId) {
        const sheetBtn = document.createElement('button');
        sheetBtn.className = 'success-btn';
        sheetBtn.innerHTML = `
            <span>📊</span>
            <span>스프레드시트</span>
        `;
        sheetBtn.onclick = () => window.open(`https://docs.google.com/spreadsheets/d/${spreadsheetId}`, '_blank');
        actions.appendChild(sheetBtn);
    }
    
    // 새로 업로드 버튼
    const newBtn = document.createElement('button');
    newBtn.className = 'success-btn secondary';
    newBtn.innerHTML = `
        <span>🔄</span>
        <span>새로 업로드</span>
    `;
    newBtn.onclick = () => {

        // 1. 앱 상태 전체 초기화 1105 - 10
        resetAppToInitialState();

        const progressContainer = document.getElementById('uploadProgress');
        progressContainer.classList.remove('show');
        setTimeout(() => {
            progressContainer.innerHTML = '';
        }, 300);
    };


    actions.appendChild(newBtn);
    
    body.appendChild(actions);
    
    successMsg.appendChild(header);
    successMsg.appendChild(body);
    
    return successMsg;
}


// ====================================
// 📊 스프레드시트 통합 성공 메시지
// ====================================

function createMergeSuccessMessage(sheetCount, rowCount, mergedSpreadsheetId) {
    const successMsg = document.createElement('div');
    successMsg.className = 'success-message';
    
    const header = document.createElement('div');
    header.className = 'success-message-header';
    header.innerHTML = `
        <span class="icon">✅</span>
        <span>스프레드시트 통합 완료!</span>
    `;
    
    const body = document.createElement('div');
    body.className = 'success-message-body';
    
    const info1 = document.createElement('div');
    info1.className = 'success-info';
    info1.innerHTML = `
        <span>📊</span>
        <span><strong>${sheetCount}개</strong>의 스프레드시트가 통합되었습니다</span>
    `;
    body.appendChild(info1);
    
    const info2 = document.createElement('div');
    info2.className = 'success-info';
    info2.innerHTML = `
        <span>📝</span>
        <span>총 <strong>${rowCount}개</strong>의 데이터가 포함되어 있습니다</span>
    `;
    body.appendChild(info2);
    
    const actions = document.createElement('div');
    actions.className = 'success-actions';
    
    const viewBtn = document.createElement('button');
    viewBtn.className = 'success-btn';
    viewBtn.innerHTML = `
        <span>📊</span>
        <span>통합 시트 보기</span>
    `;
    viewBtn.onclick = () => window.open(`https://docs.google.com/spreadsheets/d/${mergedSpreadsheetId}`, '_blank');
    actions.appendChild(viewBtn);
    
    const closeBtn = document.createElement('button');
    closeBtn.className = 'success-btn secondary';
    closeBtn.innerHTML = `
        <span>✓</span>
        <span>확인</span>
    `;
    closeBtn.onclick = () => {
        const progressContainer = document.getElementById('uploadProgress');
        progressContainer.classList.remove('show');
        setTimeout(() => {
            progressContainer.innerHTML = '';
        }, 300);
    };
    actions.appendChild(closeBtn);
    
    body.appendChild(actions);
    
    successMsg.appendChild(header);
    successMsg.appendChild(body);
    
    return successMsg;
}


// ====================================
// 🌳 나무 상세 정보 입력 기능 (수정됨)
// ====================================

// ⭐ 수정: tree-select ID를 사용하도록 변경
const treeSelect = document.getElementById('tree-select'); // ✅ 올바른 ID
const treeDetailsSection = document.getElementById('treeDetailsSection');
const treeHeightInput = document.getElementById('treeHeight');
const treeThicknessInput = document.getElementById('treeThickness');
const treeStatusSelect = document.getElementById('treeStatus');

// ⭐ 수정: select의 change 이벤트 리스너
if (treeSelect) {
    treeSelect.addEventListener('change', function() {
        showTreeDetailsIfNeeded();
    });
}

function showTreeDetailsIfNeeded() {
    const speciesValue = treeSelect.value.trim(); // ⭐ treeSelect로 변경
    
    if (speciesValue && speciesValue !== '') {
        // 수종이 선택되면 상세 정보 섹션 표시
        if (treeDetailsSection) {
            treeDetailsSection.style.display = 'block';
        }
    } else {
        // 수종이 비어있으면 숨기고 초기화
        if (treeDetailsSection) {
            treeDetailsSection.style.display = 'none';
        }
        clearTreeDetails();
    }
}

// 나무 데이터 수집 함수도 수정
function getTreeData() {
    const data = {
        species: treeSelect ? treeSelect.value.trim() : '', // ⭐ treeSelect로 변경
        height: '',
        thickness: '',
        status: ''
    };
    
    if (treeHeightInput && treeHeightInput.value) {
        data.height = treeHeightInput.value + 'm';
    }
    
    if (treeThicknessInput && treeThicknessInput.value) {
        data.thickness = treeThicknessInput.value + 'cm';
    }
    
    if (treeStatusSelect && treeStatusSelect.value) {
        data.status = treeStatusSelect.value;
    }
    
    return data;
}

// 🌳 나무 정보 포맷팅 함수
function formatTreeInfo(treeData) {
    if (!treeData.species) return '';
    
    let info = '=== 나무 상세 정보 ===\n';
    info += `수종: ${treeData.species}\n`;
    
    if (treeData.height) {
        info += `높이: ${treeData.height}\n`;
    }
    
    if (treeData.thickness) {
        info += `둘레(두께): ${treeData.thickness}\n`;
    }
    
    if (treeData.status) {
        info += `상태: ${treeData.status}\n`;
    }
    
    return info;
}


// 헬퍼 함수: 나무 상세 정보 입력란 초기화
function clearTreeDetails() {
    const treeHeightInput = document.getElementById('treeHeight');
    const treeThicknessInput = document.getElementById('treeThickness');
    const treeStatusSelect = document.getElementById('treeStatus');
    
    if (treeHeightInput) treeHeightInput.value = '';
    if (treeThicknessInput) treeThicknessInput.value = '';
    if (treeStatusSelect) treeStatusSelect.value = '양호'; // 기본값으로 설정
}


// 앱을 초기 상태로 리셋하는 함수
function resetAppToInitialState() {
    // 1. 이미지 관련 데이터 초기화
    images = [];
    selectedImageIndex = null;
    markers = [];
    
    // 2. 맵 경로 및 마커 정리
    if (pathPolyline) {
        pathPolyline.setMap(null);
        pathPolyline = null;
    }
    map = null; 
    
    // 3. 갤러리, 선택된 이미지 UI 업데이트 (숨김)
    updateUI(); 

    // 4. 메모란 비우기
    const promptTextarea = document.querySelector('.prompt-textarea');
    if (promptTextarea) {
        promptTextarea.value = '';
    }

    // 5. 위치 정보 섹션 숨기기
    const locationSection = document.getElementById('locationSection');
    if (locationSection) {
        locationSection.classList.remove('show');
    }

    // 6. 필터 초기화 (onRegionChange가 하위 항목들을 정리)
    const regionSelect = document.getElementById('region-select');
    if (regionSelect) {
        regionSelect.value = '';
        onRegionChange(); // 이 함수가 category, place, tree select를 연쇄적으로 초기화/비활성화합니다.
    }

    // 7. 나무 상세 정보 입력란 초기화 및 숨기기
    clearTreeDetails(); 
    const treeDetailsSection = document.getElementById('treeDetailsSection');
    if (treeDetailsSection) {
        treeDetailsSection.style.display = 'none';
    }

    console.log('🔄 애플리케이션 상태가 초기화되었습니다.');
}