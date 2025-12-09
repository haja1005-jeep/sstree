// Google Drive API Configuration (config.js에서 로드) . 구글 스프레드 시트 생성 안됨 여기 까지 클로드로 작성 됨
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

// Global variables
let images = [];
let selectedImageIndex = null;
let map = null;
let markers = [];
let pathPolyline = null;
let sortableInstance = null;

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

function handleAuthClick() {
    tokenClient.callback = async (resp) => {
        if (resp.error !== undefined) {
            throw (resp);
        }
        accessToken = gapi.client.getToken().access_token;
        document.getElementById('signInBtn').style.display = 'none';
        document.getElementById('userInfo').style.display = 'block';
        document.getElementById('driveActions').style.display = 'flex';
        
        try {
            const response = await fetch('https://www.googleapis.com/oauth2/v2/userinfo', {
                headers: { Authorization: `Bearer ${accessToken}` }
            });
            const data = await response.json();
            document.getElementById('userName').textContent = data.email;
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
}

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
    
    imagesToUpload.forEach((img, idx) => {
        const imageIndex = images.indexOf(img);
        textContent += `[사진 ${imageIndex + 1}]\n`;
        textContent += `파일명: ${img.file.name}\n`;
        textContent += `크기: ${(img.file.size / 1024).toFixed(2)} KB\n`;
        
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

// 구글 스프레드시트를 생성하는 함수
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
        console.log('✅ 스프레드시트 생성 완료:', spreadsheetId);
        
        // 데이터 준비
        const headerRow = [
            '사진번호', '파일명', '크기(KB)', 
            '위도', '경도', '전체주소', '시/도', '시/군/구', '동/읍/면',
            '카메라', '촬영날짜'
        ];
        
        const dataRows = imagesToUpload.map((img, idx) => {
            const imageIndex = images.indexOf(img);
            const addr = img.address?.address || {};
            
            return [
                imageIndex + 1,
                img.file.name,
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
                        range: 'Sheet1!A1',
                        values: summaryData
                    },
                    {
                        range: 'Sheet1!A' + (summaryData.length + 3),
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