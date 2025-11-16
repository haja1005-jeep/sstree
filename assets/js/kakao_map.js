/**
 * 카카오맵 통합
 * Smart Tree Map - Sinan County
 */

//let map;
let markers = [];
let infowindow;

// 지도 초기화
function initMap(containerId, lat = DEFAULT_LAT, lng = DEFAULT_LNG, level = DEFAULT_ZOOM) {
    const container = document.getElementById(containerId);
    const options = {
        center: new kakao.maps.LatLng(lat, lng),
        level: level
    };
    
    map = new kakao.maps.Map(container, options);
    infowindow = new kakao.maps.InfoWindow({zIndex:1});
    
    return map;
}

// 마커 추가
function addMarker(lat, lng, title, content, onClick) {
    const position = new kakao.maps.LatLng(lat, lng);
    
    // 마커 이미지 설정 (나무 아이콘)
    const imageSrc = 'https://t1.daumcdn.net/localimg/localimages/07/mapapidoc/marker_red.png';
    const imageSize = new kakao.maps.Size(40, 42);
    const imageOption = {offset: new kakao.maps.Point(20, 42)};
    const markerImage = new kakao.maps.MarkerImage(imageSrc, imageSize, imageOption);
    
    const marker = new kakao.maps.Marker({
        map: map,
        position: position,
        title: title,
        image: markerImage
    });
    
    // 인포윈도우 내용
    const infoContent = `
        <div style="padding:10px; min-width:200px;">
            <h4 style="margin:0 0 10px 0;">${title}</h4>
            ${content}
        </div>
    `;
    
    // 마커 클릭 이벤트
    kakao.maps.event.addListener(marker, 'click', function() {
        infowindow.setContent(infoContent);
        infowindow.open(map, marker);
        
        if (onClick) {
            onClick();
        }
    });
    
    markers.push(marker);
    return marker;
}

// 모든 마커 삭제
function clearMarkers() {
    for (let i = 0; i < markers.length; i++) {
        markers[i].setMap(null);
    }
    markers = [];
}

// 마커 표시/숨김
function setMarkersVisible(visible) {
    for (let i = 0; i < markers.length; i++) {
        markers[i].setMap(visible ? map : null);
    }
}

// 지도 중심 이동
function setCenter(lat, lng) {
    const moveLatLon = new kakao.maps.LatLng(lat, lng);
    map.setCenter(moveLatLon);
}

// 지도 레벨 변경
function setLevel(level) {
    map.setLevel(level);
}

// 마커 여러개 추가 및 범위 조정
function addMarkersAndFitBounds(treeData) {
    clearMarkers();
    
    if (!treeData || treeData.length === 0) {
        return;
    }
    
    const bounds = new kakao.maps.LatLngBounds();
    
    treeData.forEach(function(tree) {
        if (tree.latitude && tree.longitude) {
            const content = `
                <p><strong>수종:</strong> ${tree.species_name || '-'}</p>
                <p><strong>장소:</strong> ${tree.location_name || '-'}</p>
                ${tree.height ? `<p><strong>높이:</strong> ${tree.height}m</p>` : ''}
                ${tree.diameter ? `<p><strong>직경:</strong> ${tree.diameter}cm</p>` : ''}
                <p><strong>상태:</strong> ${getHealthStatusText(tree.health_status)}</p>
            `;
            
            const marker = addMarker(
                parseFloat(tree.latitude),
                parseFloat(tree.longitude),
                tree.location_name || '나무',
                content,
                function() {
                    if (tree.tree_id) {
                        // 상세보기 링크 등 추가 가능
                        console.log('Tree ID:', tree.tree_id);
                    }
                }
            );
            
            bounds.extend(marker.getPosition());
        }
    });
    
    // 마커가 모두 보이도록 지도 범위 조정
    if (markers.length > 0) {
        map.setBounds(bounds);
    }
}

// 주소로 좌표 검색
function searchAddressToCoordinate(address, callback) {
    const geocoder = new kakao.maps.services.Geocoder();
    
    geocoder.addressSearch(address, function(result, status) {
        if (status === kakao.maps.services.Status.OK) {
            const coords = new kakao.maps.LatLng(result[0].y, result[0].x);
            callback({
                success: true,
                lat: result[0].y,
                lng: result[0].x,
                address: result[0].address_name
            });
        } else {
            callback({
                success: false,
                message: '주소를 찾을 수 없습니다.'
            });
        }
    });
}

// 좌표로 주소 검색
function searchCoordinateToAddress(lat, lng, callback) {
    const geocoder = new kakao.maps.services.Geocoder();
    const coord = new kakao.maps.LatLng(lat, lng);
    
    geocoder.coord2Address(coord.getLng(), coord.getLat(), function(result, status) {
        if (status === kakao.maps.services.Status.OK) {
            callback({
                success: true,
                address: result[0].address.address_name,
                roadAddress: result[0].road_address ? result[0].road_address.address_name : null
            });
        } else {
            callback({
                success: false,
                message: '주소를 찾을 수 없습니다.'
            });
        }
    });
}

// 지도 클릭 이벤트로 좌표 가져오기
function enableMapClick(callback) {
    kakao.maps.event.addListener(map, 'click', function(mouseEvent) {
        const latlng = mouseEvent.latLng;
        callback({
            lat: latlng.getLat(),
            lng: latlng.getLng()
        });
    });
}

// 건강 상태 텍스트 반환
function getHealthStatusText(status) {
    const statusMap = {
        'excellent': '최상',
        'good': '양호',
        'fair': '보통',
        'poor': '나쁨',
        'dead': '고사'
    };
    return statusMap[status] || '-';
}

// 클러스터러 생성 (많은 마커 처리)
function createClusterer() {
    return new kakao.maps.MarkerClusterer({
        map: map,
        averageCenter: true,
        minLevel: 5,
        calculator: [10, 30, 50],
        styles: [{
            width: '30px',
            height: '30px',
            background: 'rgba(102, 126, 234, .8)',
            borderRadius: '15px',
            color: '#fff',
            textAlign: 'center',
            lineHeight: '31px',
            fontSize: '12px',
            fontWeight: 'bold'
        }]
    });
}
