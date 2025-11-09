# 🌳 신안군 스마트 트리맵 플랫폼

PHP + MySQL 기반의 통합 나무 관리 시스템

## ✨ 주요 기능

### ✅ 현재 구현 완료

#### 1. 인증 시스템
- 로그인/로그아웃
- 역할 기반 권한 관리 (관리자/담당자/현장직원)
- 활동 로그 기록

#### 2. 지역 관리
- 신안군 읍/면 등록 및 관리
- 지역별 통계
- 완전한 CRUD 기능

#### 3. 장소 관리 ⭐
- **카테고리별 동적 입력 필드**
  - 공원/생활숲: 넓이(㎡) 입력
  - 가로수: 길이(m), 도로폭(m) 입력
- **멀티미디어 지원**
  - 일반 사진 3-5장 업로드
  - 360도 VR 사진 업로드
  - 동영상 URL (유튜브, 네이버TV)
- 카카오맵 GPS 좌표 지정
- 검색 및 필터링
- 상세보기 페이지 (갤러리, VR, 동영상, 지도)

#### 4. 관리자 대시보드
- 실시간 통계 (나무 수, 수종 수, 지역 수, 장소 수)
- 최근 등록 나무 목록
- 카테고리별 나무 현황
- 지역별 나무 현황

## 📋 데이터베이스 구조

```
users               - 회원 관리
regions             - 지역 (읍/면)
categories          - 카테고리 (공원, 생활숲, 가로수, 보호수)
locations           - 장소 (넓이/길이 + 동영상 URL)
location_photos     - 장소 사진 (일반 + 360 VR)
tree_species_master - 수종 마스터
trees               - 나무 데이터
tree_photos         - 나무 사진
activity_logs       - 활동 로그
```

## 🚀 설치 방법

### 1. 시스템 요구사항
- PHP 7.4 이상
- MySQL 5.7 이상 또는 MariaDB 10.2 이상
- Apache/Nginx 웹 서버

### 2. 데이터베이스 설정

```bash
# MySQL에 접속
mysql -u root -p

# SQL 파일 실행
source database/schema.sql
```

또는 phpMyAdmin에서:
1. "가져오기" 탭 선택
2. `database/schema.sql` 파일 업로드
3. 실행

### 3. 설정 파일 수정

**config/database.php** - 데이터베이스 정보
```php
private $host = "localhost";
private $db_name = "smart_tree_map";
private $username = "your_db_user";
private $password = "your_db_password";
```

**config/config.php** - 기본 설정
```php
// 사이트 URL
define('SITE_URL', 'http://yourdomain.com/smart-tree-map');

// 카카오맵 API 키
define('KAKAO_MAP_API_KEY', 'YOUR_KAKAO_API_KEY');
```

### 4. 카카오맵 API 키 발급

1. [카카오 개발자센터](https://developers.kakao.com/) 접속
2. "내 애플리케이션" → "애플리케이션 추가하기"
3. "플랫폼" → "Web 플랫폼 등록" → 사이트 도메인 입력
4. "앱 키" → "JavaScript 키" 복사
5. `config/config.php`에 키 입력

### 5. 권한 설정

```bash
chmod -R 755 uploads/
chown -R www-data:www-data uploads/
```

### 6. 접속

```
URL: http://yourdomain.com/smart-tree-map/admin/login.php
아이디: admin
비밀번호: admin123
```

**⚠️ 첫 로그인 후 반드시 비밀번호를 변경하세요!**

## 📁 디렉토리 구조

```
smart-tree-map/
├── admin/              # 관리자 페이지
│   ├── index.php      # 대시보드
│   ├── login.php      # 로그인
│   ├── regions/       # 지역 관리
│   └── locations/     # 장소 관리 ✨
├── assets/            # 리소스
│   ├── css/          # 스타일
│   └── js/           # 스크립트
├── config/            # 설정 파일
├── database/          # DB 스키마
├── includes/          # 공통 파일
├── tools/            # 유틸리티
└── uploads/          # 업로드 파일
```

## 🎯 주요 기능 상세

### 장소 관리 (Locations)

#### 카테고리별 동적 필드
```
공원/생활숲 → 넓이(㎡) 입력
가로수     → 길이(m), 도로폭(m) 입력
보호수     → 기본 정보만
```

#### 멀티미디어 관리
- **일반 사진**: 3-5장 업로드, 드래그 앤 드롭 정렬
- **360 VR 사진**: 파노라마 사진 업로드
- **동영상**: 유튜브/네이버TV URL 자동 임베드

#### 위치 정보
- 카카오맵 클릭으로 GPS 좌표 입력
- 지도에서 마커로 위치 표시
- 인포윈도우로 장소명 표시

## 🔧 다음 개발 예정

- [ ] 카테고리 관리 CRUD
- [ ] 수종 관리 (수종 정보 DB)
- [ ] 나무 데이터 관리 (실제 나무 등록)
- [ ] 나무 사진 업로드
- [ ] 통계 대시보드 (상세)
- [ ] 엑셀 내보내기
- [ ] 회원 관리 페이지
- [ ] 사용자 권한 세부 설정

## 💡 사용 팁

### 비밀번호 해시 생성
새 사용자 생성 시 `tools/password_hash.php`를 사용하여 비밀번호 해시를 생성하세요.

### 사진 최적화
- 권장 해상도: 1920x1080 이하
- 파일 크기: 10MB 이하
- 형식: JPG, PNG

### 동영상 URL
- 유튜브: `https://www.youtube.com/watch?v=VIDEO_ID`
- 네이버TV: `https://tv.naver.com/v/VIDEO_ID`

## 📊 통계 기능

현재 대시보드에서 제공하는 통계:
- 전체 나무 수
- 등록 수종 수
- 관리 지역 수
- 등록 장소 수
- 카테고리별 나무 분포
- 지역별 나무 현황 (상위 5개)
- 최근 등록 나무 목록

## 🔐 보안

- 비밀번호는 bcrypt로 해시화
- SQL Injection 방지 (Prepared Statements)
- XSS 방지 (htmlspecialchars)
- 역할 기반 접근 제어
- 모든 활동 로그 기록

## 📝 라이선스

이 프로젝트는 신안군 전용으로 개발되었습니다.

---

**개발 완료 항목:**
✅ 데이터베이스 설계 및 구축
✅ 로그인 시스템
✅ 관리자 대시보드
✅ 지역 관리 (완전 CRUD)
✅ 장소 관리 (완전 CRUD + 멀티미디어) ⭐

**현재 작업 가능:**
- 장소 등록/수정/삭제/조회
- 사진 갤러리 관리
- 360 VR 사진 업로드
- 동영상 URL 연결
- 카카오맵 위치 지정

---

© 2024 신안군 스마트 트리맵 플랫폼
