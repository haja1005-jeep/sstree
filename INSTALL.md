# 🚀 스마트 트리맵 설치 가이드

이 가이드는 PHP + MySQL 기반 스마트 트리맵 플랫폼의 설치 과정을 설명합니다.

## 📋 Step 1: 파일 업로드

웹 서버 문서 루트에 모든 파일을 업로드합니다.

```
/var/www/html/smart-tree-map/
또는
C:\xampp\htdocs\smart-tree-map\
```

## 🗄️ Step 2: 데이터베이스 생성

### phpMyAdmin 사용 (추천)

1. phpMyAdmin 접속
2. "가져오기" 탭 선택
3. `database/schema.sql` 파일 선택
4. "실행" 버튼 클릭

### MySQL 명령어 사용

```bash
mysql -u root -p < database/schema.sql
```

## ⚙️ Step 3: 설정 파일 수정

### config/database.php
```php
private $host = "localhost";
private $db_name = "smart_tree_map";
private $username = "your_db_user";
private $password = "your_db_password";
```

### config/config.php
```php
define('SITE_URL', 'http://yourdomain.com/smart-tree-map');
define('KAKAO_MAP_API_KEY', 'YOUR_KAKAO_API_KEY');
```

## 🗺️ Step 4: 카카오맵 API 키 발급

1. https://developers.kakao.com 접속
2. "내 애플리케이션" → "애플리케이션 추가"
3. "플랫폼" → "Web 플랫폼 등록" → 도메인 입력
4. "JavaScript 키" 복사 → config.php에 입력

## 🔐 Step 5: 권한 설정

```bash
chmod -R 755 uploads/
chown -R www-data:www-data uploads/
```

## 🎯 Step 6: 첫 로그인

```
URL: http://yourdomain.com/smart-tree-map/admin/login.php
아이디: admin
비밀번호: admin123
```

⚠️ 첫 로그인 후 반드시 비밀번호를 변경하세요!

## 🔧 문제 해결

### DB 연결 오류
- MySQL 서비스 확인
- config/database.php 정보 확인
- 데이터베이스 및 사용자 권한 확인

### 파일 업로드 오류
- uploads 폴더 존재 확인
- 쓰기 권한 확인
- PHP 설정: upload_max_filesize = 10M

### 지도 안 보임
- 카카오 API 키 확인
- 플랫폼 도메인 설정 확인

---

자세한 내용은 README.md를 참고하세요.
