<?php

require_once '../../config/config.php';
require_once '../../config/kakao_map.php';
require_once '../../includes/auth.php';

checkAuth();


//header('Content-Type: application/json; charset=utf-8');

$page_title = '일괄 사진 업로드';
$database = new Database();
$db = $database->getConnection();

// 지역 목록 조회
$regions = $db->query("SELECT * FROM regions ORDER BY region_name")->fetchAll();

include '../../includes/header.php';
?>

<style>
.upload-container { max-width: 1400px; margin: 0 auto; }
.upload-zone { border: 3px dashed #cbd5e0; border-radius: 12px; padding: 60px 20px; text-align: center; background: #f7fafc; transition: all 0.3s; cursor: pointer; margin-bottom: 30px; }
.upload-zone:hover { border-color: #4299e1; background: #ebf8ff; }
.upload-zone.dragover { border-color: #3182ce; background: #bee3f8; }
.upload-icon { font-size: 64px; margin-bottom: 20px; color: #a0aec0; }
.upload-text { font-size: 18px; color: #4a5568; margin-bottom: 10px; }
.upload-hint { font-size: 14px; color: #718096; }

.photos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin-top: 30px; }
.photo-card { background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); position: relative; }
.photo-card img { width: 100%; height: 200px; object-fit: cover; }
.photo-info { padding: 15px; }
.photo-status { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 13px; font-weight: 600; }
.status-matched { color: #059669; }
.status-unmatched { color: #dc2626; }
.status-processing { color: #f59e0b; }
.photo-location { font-size: 13px; color: #4b5563; margin-bottom: 8px; }
.photo-gps { font-size: 12px; color: #9ca3af; }
.photo-actions { display: flex; gap: 8px; margin-top: 10px; }
.photo-actions select { flex: 1; padding: 6px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; }
.photo-actions button { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }
.btn-remove { background: #fee; color: #dc2626; }
.btn-remove:hover { background: #fcc; }

.summary-box { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 30px; }
.summary-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
.stat-item { text-align: center; padding: 15px; background: #f7fafc; border-radius: 8px; }
.stat-value { font-size: 32px; font-weight: bold; margin-bottom: 5px; }
.stat-label { font-size: 14px; color: #718096; }
.stat-matched .stat-value { color: #059669; }
.stat-unmatched .stat-value { color: #dc2626; }
.stat-total .stat-value { color: #3b82f6; }

.filter-bar { display: flex; gap: 10px; margin-bottom: 20px; align-items: center; }
.filter-bar select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
.filter-bar button { padding: 8px 16px; }

.progress-bar { width: 100%; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; margin-top: 10px; }
.progress-fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #8b5cf6); transition: width 0.3s; }

#photoFileInput { display: none; }
</style>

<div class="page-header">
    <div>
        <h2>📸 일괄 사진 업로드</h2>
        <p>GPS 좌표 기반 자동 장소 매칭</p>
    </div>
    <a href="index.php" class="btn btn-secondary">← 장소 목록</a>
</div>

<div class="upload-container">
    <!-- 업로드 영역 -->
    <div class="upload-zone" id="uploadZone" onclick="document.getElementById('photoFileInput').click()">
        <div class="upload-icon">📤</div>
        <div class="upload-text">사진을 드래그하여 놓거나 클릭하여 선택하세요</div>
        <div class="upload-hint">여러 장의 사진을 한 번에 업로드할 수 있습니다 (최대 50장)</div>
        <input type="file" id="photoFileInput" accept="image/*" multiple>
    </div>

    <!-- 지역 필터 -->
    <div class="filter-bar" id="filterBar" style="display: none;">
        <label><strong>지역 필터:</strong></label>
        <select id="regionFilter" onchange="filterPhotos()">
            <option value="">전체 지역</option>
            <?php foreach ($regions as $r): ?>
                <option value="<?= $r['region_id'] ?>"><?= htmlspecialchars($r['region_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="statusFilter" onchange="filterPhotos()">
            <option value="">전체 상태</option>
            <option value="matched">매칭됨</option>
            <option value="unmatched">매칭안됨</option>
        </select>
        <button class="btn btn-primary" onclick="saveAllPhotos()">💾 전체 저장</button>
        <button class="btn btn-secondary" onclick="resetUpload()">🔄 초기화</button>
    </div>

    <!-- 통계 요약 -->
    <div class="summary-box" id="summaryBox" style="display: none;">
        <h3 style="margin-bottom: 20px;">📊 업로드 요약</h3>
        <div class="summary-stats">
            <div class="stat-item stat-total">
                <div class="stat-value" id="totalCount">0</div>
                <div class="stat-label">전체 사진</div>
            </div>
            <div class="stat-item stat-matched">
                <div class="stat-value" id="matchedCount">0</div>
                <div class="stat-label">매칭 완료</div>
            </div>
            <div class="stat-item stat-unmatched">
                <div class="stat-value" id="unmatchedCount">0</div>
                <div class="stat-label">매칭 안됨</div>
            </div>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" id="progressFill" style="width: 0%"></div>
        </div>
    </div>

    <!-- 사진 그리드 -->
    <div class="photos-grid" id="photosGrid"></div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/exif-js"></script>

<script>
let uploadedPhotos = [];
let locationsData = [];

// 드래그 앤 드롭 이벤트
const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('photoFileInput');

uploadZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadZone.classList.add('dragover');
});

uploadZone.addEventListener('dragleave', () => {
    uploadZone.classList.remove('dragover');
});

uploadZone.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadZone.classList.remove('dragover');
    const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
    if (files.length > 0) handleFiles(files);
});

fileInput.addEventListener('change', (e) => {
    const files = Array.from(e.target.files);
    if (files.length > 0) handleFiles(files);
});

// 파일 처리
async function handleFiles(files) {
    if (files.length > 50) {
        alert('한 번에 최대 50장까지 업로드 가능합니다.');
        files = files.slice(0, 50);
    }
    
    document.getElementById('summaryBox').style.display = 'block';
    document.getElementById('filterBar').style.display = 'flex';
    
    // 장소 데이터 로드
    if (locationsData.length === 0) {
        await loadLocations();
    }
    
    let processed = 0;
    for (const file of files) {
        await processPhoto(file);
        processed++;
        updateProgress(processed, files.length);
    }
    
    updateSummary();
    renderPhotos();
}

// 장소 데이터 로드
async function loadLocations() {
    try {
        const response = await fetch('get_locations_with_geometry.php');
        locationsData = await response.json();
        console.log('장소 데이터 로드됨:', locationsData.length, '개');
    } catch (error) {
        console.error('장소 데이터 로드 실패:', error);
    }
}

// 사진 처리
function processPhoto(file) {
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                EXIF.getData(img, function() {
                    const lat = getGPSCoordinate(EXIF.getTag(this, "GPSLatitude"), EXIF.getTag(this, "GPSLatitudeRef"));
                    const lng = getGPSCoordinate(EXIF.getTag(this, "GPSLongitude"), EXIF.getTag(this, "GPSLongitudeRef"));
                    
                    const photoData = {
                        id: Date.now() + Math.random(),
                        file: file,
                        preview: e.target.result,
                        latitude: lat,
                        longitude: lng,
                        hasGPS: lat !== null && lng !== null,
                        matchedLocation: null,
                        manualLocation: null,
                        status: 'processing'
                    };
                    
                    // GPS 좌표가 있으면 장소 매칭 시도
                    if (photoData.hasGPS) {
                        photoData.matchedLocation = findMatchingLocation(lat, lng);
                        photoData.status = photoData.matchedLocation ? 'matched' : 'unmatched';
                    } else {
                        photoData.status = 'unmatched';
                    }
                    
                    uploadedPhotos.push(photoData);
                    resolve();
                });
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}

// GPS 좌표 추출
function getGPSCoordinate(coords, ref) {
    if (!coords || !ref) return null;
    
    const decimal = coords[0] + coords[1] / 60 + coords[2] / 3600;
    return (ref === 'S' || ref === 'W') ? -decimal : decimal;
}

// 장소 매칭 로직
function findMatchingLocation(lat, lng) {
    for (const location of locationsData) {
        // 1. POLYGON 체크
        if (location.geom_type === 'POLYGON' && location.coordinates) {
            if (pointInPolygon(lng, lat, location.coordinates)) {
                return location;
            }
        }
        
        // 2. LINESTRING 체크 (가로수 - 50m 버퍼)
        if (location.geom_type === 'LINESTRING' && location.coordinates) {
            if (pointNearLine(lng, lat, location.coordinates, 0.0005)) { // 약 50m
                return location;
            }
        }
        
        // 3. POINT 체크 (100m 반경)
        if (location.latitude && location.longitude) {
            const distance = calculateDistance(lat, lng, location.latitude, location.longitude);
            if (distance <= 100) {
                return location;
            }
        }
    }
    return null;
}

// Point in Polygon 알고리즘
function pointInPolygon(x, y, polygon) {
    let inside = false;
    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        const xi = polygon[i][0], yi = polygon[i][1];
        const xj = polygon[j][0], yj = polygon[j][1];
        const intersect = ((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
        if (intersect) inside = !inside;
    }
    return inside;
}

// Point near Line 체크
function pointNearLine(x, y, line, threshold) {
    for (let i = 0; i < line.length - 1; i++) {
        const dist = pointToSegmentDistance(x, y, line[i][0], line[i][1], line[i+1][0], line[i+1][1]);
        if (dist <= threshold) return true;
    }
    return false;
}

// 점과 선분 사이의 거리
function pointToSegmentDistance(px, py, x1, y1, x2, y2) {
    const A = px - x1;
    const B = py - y1;
    const C = x2 - x1;
    const D = y2 - y1;
    
    const dot = A * C + B * D;
    const lenSq = C * C + D * D;
    let param = -1;
    
    if (lenSq !== 0) param = dot / lenSq;
    
    let xx, yy;
    if (param < 0) {
        xx = x1;
        yy = y1;
    } else if (param > 1) {
        xx = x2;
        yy = y2;
    } else {
        xx = x1 + param * C;
        yy = y1 + param * D;
    }
    
    const dx = px - xx;
    const dy = py - yy;
    return Math.sqrt(dx * dx + dy * dy);
}

// 두 지점 간 거리 (미터)
function calculateDistance(lat1, lng1, lat2, lng2) {
    const R = 6371000; // 지구 반경 (미터)
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLng/2) * Math.sin(dLng/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return R * c;
}

// 진행률 업데이트
function updateProgress(current, total) {
    const percent = (current / total) * 100;
    document.getElementById('progressFill').style.width = percent + '%';
}

// 요약 업데이트
function updateSummary() {
    const total = uploadedPhotos.length;
    const matched = uploadedPhotos.filter(p => p.status === 'matched').length;
    const unmatched = total - matched;
    
    document.getElementById('totalCount').textContent = total;
    document.getElementById('matchedCount').textContent = matched;
    document.getElementById('unmatchedCount').textContent = unmatched;
}

// 사진 렌더링
function renderPhotos() {
    const grid = document.getElementById('photosGrid');
    grid.innerHTML = '';
    
    const filtered = getFilteredPhotos();
    
    filtered.forEach(photo => {
        const card = createPhotoCard(photo);
        grid.appendChild(card);
    });
}

// 사진 카드 생성
function createPhotoCard(photo) {
    const card = document.createElement('div');
    card.className = 'photo-card';
    card.dataset.photoId = photo.id;
    
    const statusIcon = photo.status === 'matched' ? '✅' : photo.status === 'unmatched' ? '❌' : '⏳';
    const statusClass = photo.status === 'matched' ? 'status-matched' : photo.status === 'unmatched' ? 'status-unmatched' : 'status-processing';
    const statusText = photo.status === 'matched' ? '매칭됨' : photo.status === 'unmatched' ? '매칭 안됨' : '처리중';
    
    const location = photo.matchedLocation || photo.manualLocation;
    const locationName = location ? location.location_name : '매칭된 장소 없음';
    const regionName = location ? location.region_name : '';
    
    card.innerHTML = `
        <img src="${photo.preview}" alt="사진">
        <div class="photo-info">
            <div class="photo-status ${statusClass}">
                ${statusIcon} ${statusText}
            </div>
            <div class="photo-location">
                <strong>${locationName}</strong>
                ${regionName ? `<br><small>${regionName}</small>` : ''}
            </div>
            <div class="photo-gps">
                ${photo.hasGPS ? 
                    `📍 ${photo.latitude.toFixed(6)}, ${photo.longitude.toFixed(6)}` : 
                    '📍 GPS 정보 없음'}
            </div>
            <div class="photo-actions">
                <select onchange="changeLocation(${photo.id}, this.value)">
                    <option value="">수동 선택...</option>
                    ${locationsData.map(loc => 
                        `<option value="${loc.location_id}" ${location && location.location_id === loc.location_id ? 'selected' : ''}>
                            ${loc.location_name} (${loc.region_name})
                        </option>`
                    ).join('')}
                </select>
                <button class="btn-remove" onclick="removePhoto(${photo.id})">삭제</button>
            </div>
        </div>
    `;
    
    return card;
}

// 필터링된 사진 가져오기
function getFilteredPhotos() {
    const regionFilter = document.getElementById('regionFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    
    return uploadedPhotos.filter(photo => {
        const location = photo.matchedLocation || photo.manualLocation;
        
        if (regionFilter && (!location || location.region_id != regionFilter)) {
            return false;
        }
        
        if (statusFilter && photo.status !== statusFilter) {
            return false;
        }
        
        return true;
    });
}

// 필터 적용
function filterPhotos() {
    renderPhotos();
}

// 장소 변경
function changeLocation(photoId, locationId) {
    const photo = uploadedPhotos.find(p => p.id === photoId);
    if (!photo) return;
    
    if (locationId) {
        photo.manualLocation = locationsData.find(l => l.location_id == locationId);
        photo.status = 'matched';
    } else {
        photo.manualLocation = null;
        photo.status = photo.matchedLocation ? 'matched' : 'unmatched';
    }
    
    updateSummary();
    renderPhotos();
}

// 사진 삭제
function removePhoto(photoId) {
    uploadedPhotos = uploadedPhotos.filter(p => p.id !== photoId);
    updateSummary();
    renderPhotos();
}

// 전체 저장
async function saveAllPhotos() {
    const photosToSave = uploadedPhotos.filter(p => {
        const location = p.matchedLocation || p.manualLocation;
        return location !== null;
    });
    
    if (photosToSave.length === 0) {
        alert('저장할 사진이 없습니다.');
        return;
    }
    
    if (!confirm(`${photosToSave.length}장의 사진을 저장하시겠습니까?`)) {
        return;
    }
    
    const formData = new FormData();
    photosToSave.forEach((photo, index) => {
        const location = photo.matchedLocation || photo.manualLocation;
        formData.append(`photos[${index}][file]`, photo.file);
        formData.append(`photos[${index}][location_id]`, location.location_id);
        formData.append(`photos[${index}][latitude]`, photo.latitude || '');
        formData.append(`photos[${index}][longitude]`, photo.longitude || '');
    });
    
    try {
        const response = await fetch('save_bulk_photos.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(`✅ ${result.saved}장의 사진이 저장되었습니다!`);
            resetUpload();
        } else {
            alert('❌ 저장 중 오류가 발생했습니다: ' + result.message);
        }
    } catch (error) {
        alert('❌ 저장 중 오류가 발생했습니다.');
        console.error(error);
    }
}

// 초기화
function resetUpload() {
    uploadedPhotos = [];
    document.getElementById('photosGrid').innerHTML = '';
    document.getElementById('summaryBox').style.display = 'none';
    document.getElementById('filterBar').style.display = 'none';
    document.getElementById('photoFileInput').value = '';
    document.getElementById('progressFill').style.width = '0%';
}
</script>

<?php include '../../includes/footer.php'; ?>