# 🚀 신안군 스마트 트리맵 설치 가이드

**실제 서버 환경**
- 도메인: https://www.sstree.or.kr
- 설치 경로: /v2

---

## 📋 Step 1: 파일 업로드

모든 파일을 서버의 다음 경로에 업로드:

```
/home/계정명/public_html/v2/
또는
/var/www/html/v2/
```

업로드 후 구조:
```
/v2/
├── admin/
├── assets/
├── config/
├── database/
├── includes/
├── tools/
└── uploads/
    └── photos/  ← 빈 폴더 생성 필요!
```

---

## 🗄️ Step 2: 데이터베이스 생성

### phpMyAdmin 사용 (추천)

1. cPanel 또는 phpMyAdmin 접속
2. "데이터베이스" → "새 데이터베이스 생성"
3. 이름: `smart_tree_map` (또는 원하는 이름)
4. "SQL" 탭 선택
5. `database/schema_utf8.sql` 파일 내용 전체 복사
6. 붙여넣기 후 "실행"

### 자동 생성되는 데이터
- ✅ 관리자 계정 (admin / admin123)
- ✅ 13개 지역 (신안군 읍/면)
- ✅ 4개 카테고리
- ✅ 5개 샘플 수종

---

## ⚙️ Step 3: 설정 파일 수정

### 📝 config/database.php

**수정 필요:**
```php
private $host = "localhost";              // DB 서버 (보통 localhost)
private $db_name = "smart_tree_map";      // 생성한 DB 이름
private $username = "your_db_username";   // ⚠️ DB 사용자명 입력
private $password = "your_db_password";   // ⚠️ DB 비밀번호 입력
```

### 📝 config/config.php

**이미 설정됨:**
```php
define('BASE_URL', 'https://www.sstree.or.kr/v2');  ✅ 설정 완료
```

### 📝 config/kakao_map.php

**카카오 API 키 발급:**

1. https://developers.kakao.com 접속
2. "내 애플리케이션" → "애플리케이션 추가"
3. "플랫폼 설정" → "Web 플랫폼 추가"
   - 사이트 도메인: `https://www.sstree.or.kr`
4. "JavaScript 키" 복사

**수정:**
```php
define('KAKAO_MAP_API_KEY', '여기에_발급받은_키_입력');
```

---

## 🔐 Step 4: 권한 설정

### FTP/SSH 접속 후:

```bash
# uploads 폴더 권한 설정
chmod -R 755 v2/uploads/
chmod -R 777 v2/uploads/photos/

# 또는 소유권 변경
chown -R www-data:www-data v2/uploads/
```

### cPanel 파일관리자에서:

1. `uploads/photos` 폴더 선택
2. "권한" 또는 "Permissions" 클릭
3. 777 또는 rwxrwxrwx 설정

---

## 🌐 Step 5: 접속 테스트

### 관리자 로그인

```
URL: https://www.sstree.or.kr/v2/admin/login.php

아이디: admin
비밀번호: admin123
```

### ✅ 확인사항

로그인 성공 후:
- 대시보드 통계 표시
- 지역 13개 확인
- 카테고리 4개 확인
- 지도 정상 표시 (카카오 API 키 설정 후)

---

## 🔧 문제 해결

### ❌ "데이터베이스 연결 실패"
**원인:** DB 정보 오류  
**해결:**
1. cPanel → phpMyAdmin에서 DB 이름 확인
2. `config/database.php` 정보 재확인
3. DB 사용자에게 권한 부여 확인

### ❌ "404 Not Found"
**원인:** 경로 오류  
**해결:**
1. 파일이 `/v2/` 폴더에 있는지 확인
2. .htaccess 파일 확인
3. 서버 경로 설정 확인

### ❌ "한글 깨짐"
**원인:** charset 문제  
**해결:**
1. `schema_utf8.sql` 사용 확인
2. DB charset이 utf8인지 확인
3. PHP 파일이 UTF-8로 저장되었는지 확인

### ❌ "파일 업로드 안됨"
**원인:** 권한 문제  
**해결:**
1. `uploads/photos/` 폴더 존재 확인
2. 폴더 권한을 777로 설정
3. php.ini 설정 확인:
   ```ini
   upload_max_filesize = 10M
   post_max_size = 10M
   ```

### ❌ "지도가 안 보임"
**원인:** 카카오 API 키 문제  
**해결:**
1. API 키 발급 재확인
2. 플랫폼 도메인 설정 확인 (`https://www.sstree.or.kr`)
3. 브라우저 개발자도구(F12)에서 오류 확인

---

## 📱 접속 URL 정리

```
관리자 로그인: https://www.sstree.or.kr/v2/admin/login.php
대시보드:      https://www.sstree.or.kr/v2/admin/index.php
지역 관리:     https://www.sstree.or.kr/v2/admin/regions/list.php
장소 관리:     https://www.sstree.or.kr/v2/admin/locations/list.php
```

---

## 🔒 보안 강화 (운영 환경)

### 1. 비밀번호 변경
첫 로그인 후 **즉시** admin 비밀번호 변경

### 2. 에러 표시 끄기
`config/config.php` 수정:
```php
error_reporting(0);
ini_set('display_errors', 0);
```

### 3. tools 폴더 삭제
```bash
rm -rf v2/tools/
```

### 4. HTTPS 강제
`.htaccess` 파일 추가:
```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## ✅ 설치 완료!

모든 설정이 완료되면:

1. https://www.sstree.or.kr/v2/admin/login.php 접속
2. admin / admin123 로그인
3. 비밀번호 변경
4. 장소 등록 시작!

문제가 있으면 서버 에러 로그를 확인하세요:
```
/var/log/apache2/error.log
또는
/home/계정명/logs/error.log
```

---

**© 2024 신안군 스마트 트리맵**
