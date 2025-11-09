# 🌳 스마트 트리맵 설정 정보

## ✅ 업데이트된 설정 (2024-11-08)

### 📍 서버 정보
- **도메인**: https://www.sstree.or.kr
- **설치 경로**: /v2
- **전체 URL**: https://www.sstree.or.kr/v2

### 🗄️ 데이터베이스
- **DB 이름**: `sstree` (기존 DB 사용)
- **문자셋**: UTF-8

---

## 📋 설정 파일 수정 내역

### 1. config/database.php
```php
private $db_name = "sstree";  // ✅ 기존 DB 사용
```

### 2. config/config.php
```php
define('BASE_URL', 'https://www.sstree.or.kr/v2');  // ✅ 실제 도메인
```

### 3. config/kakao_map.php
```php
define('KAKAO_MAP_API_KEY', 'YOUR_KEY');  // ⚠️ 발급 필요
```

---

## 🆕 장소 테이블 업데이트

### 추가된 필드
1. **조성년도** (`establishment_year`) - INT
2. **관리기관** (`management_agency`) - VARCHAR(200)

### 기존 DB 업데이트 방법

#### Option 1: ALTER TABLE 실행 (기존 DB에 필드만 추가)
```sql
-- database/add_location_fields.sql 실행
USE sstree;

ALTER TABLE locations 
ADD COLUMN establishment_year INT COMMENT '조성년도' AFTER width,
ADD COLUMN management_agency VARCHAR(200) COMMENT '관리기관' AFTER establishment_year;
```

#### Option 2: 전체 스키마 재생성 (테이블이 없는 경우)
```sql
-- database/schema_utf8.sql 실행
-- ⚠️ 주의: 기존 데이터가 삭제됩니다!
```

---

## 🎯 접속 URL

```
관리자 로그인: https://www.sstree.or.kr/v2/admin/login.php
대시보드:      https://www.sstree.or.kr/v2/admin/index.php
지역 관리:     https://www.sstree.or.kr/v2/admin/regions/list.php
장소 관리:     https://www.sstree.or.kr/v2/admin/locations/list.php
```

**기본 로그인:**
- 아이디: `admin`
- 비밀번호: `admin123`

---

## 📊 장소 테이블 전체 구조

```sql
locations
├── location_id          (INT, PK, AUTO_INCREMENT)
├── region_id           (INT, FK → regions)
├── category_id         (INT, FK → categories)
├── location_name       (VARCHAR(200), NOT NULL)
├── address            (TEXT)
├── latitude           (DECIMAL(10,8))
├── longitude          (DECIMAL(11,8))
├── area               (DECIMAL(10,2)) - 넓이(㎡)
├── length             (DECIMAL(10,2)) - 길이(m)
├── width              (DECIMAL(10,2)) - 도로폭(m)
├── establishment_year (INT) 🆕 - 조성년도
├── management_agency  (VARCHAR(200)) 🆕 - 관리기관
├── video_url          (VARCHAR(500)) - 동영상 URL
├── description        (TEXT)
├── created_at         (TIMESTAMP)
└── updated_at         (TIMESTAMP)
```

---

## 🔧 필수 설정 사항

### 1. DB 사용자 권한
```sql
GRANT ALL PRIVILEGES ON sstree.* TO 'your_user'@'localhost';
FLUSH PRIVILEGES;
```

### 2. 폴더 권한
```bash
chmod -R 755 v2/uploads/
chmod -R 777 v2/uploads/photos/
```

### 3. 카카오맵 API
1. https://developers.kakao.com
2. 앱 생성 → Web 플랫폼
3. 도메인: `https://www.sstree.or.kr`
4. JavaScript 키 발급

---

## 📝 설치 순서

1. ✅ 파일 업로드 → `/v2/` 폴더
2. ✅ `database/add_location_fields.sql` 실행 (기존 DB 사용)
3. ✅ `config/database.php` 수정 (DB 정보)
4. ✅ `config/kakao_map.php` 수정 (API 키)
5. ✅ `uploads/photos/` 폴더 권한 설정
6. ✅ 접속 테스트

---

## 📁 파일 목록

```
database/
├── schema_utf8.sql          - 전체 DB 생성 (신규 설치용)
└── add_location_fields.sql  - 필드 추가 (기존 DB용) 🆕

config/
├── database.php    - DB: sstree
├── config.php      - URL: https://www.sstree.or.kr/v2
└── kakao_map.php   - API 키 입력 필요

admin/locations/
├── list.php        - 장소 목록
├── add.php         - 장소 추가 (조성년도, 관리기관 포함) 🆕
└── view.php        - 장소 상세 (조성년도, 관리기관 표시) 🆕
```

---

**© 2024 신안군 스마트 트리맵**
