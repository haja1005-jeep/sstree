<?php
/**
 * 비밀번호 해시 생성 유틸리티
 * 새로운 사용자 생성 시 비밀번호 해시를 생성하는 도구
 */

// 사용법:
// 1. 브라우저에서 이 파일에 접속
// 2. 비밀번호 입력
// 3. 생성된 해시를 복사하여 데이터베이스에 입력

$generated_hash = '';
$password = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $password = $_POST['password'];
    $generated_hash = password_hash($password, PASSWORD_DEFAULT);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>비밀번호 해시 생성기</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            width: 100%;
            max-width: 600px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .description {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        input[type="text"],
        input[type="password"],
        textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: monospace;
            transition: all 0.3s;
        }
        
        input:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .result {
            background: #f0f9ff;
            border: 2px solid #0ea5e9;
            border-radius: 8px;
            padding: 20px;
            margin-top: 25px;
        }
        
        .result-label {
            font-weight: 600;
            color: #0369a1;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .hash-value {
            background: white;
            padding: 12px;
            border-radius: 6px;
            word-break: break-all;
            font-family: monospace;
            font-size: 12px;
            color: #333;
            border: 1px solid #bae6fd;
        }
        
        .copy-btn {
            margin-top: 10px;
            padding: 8px 16px;
            background: #0ea5e9;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        
        .copy-btn:hover {
            background: #0284c7;
        }
        
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 13px;
            color: #92400e;
        }
        
        .example {
            background: #f3f4f6;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 13px;
        }
        
        .example-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }
        
        code {
            background: #1f2937;
            color: #10b981;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 비밀번호 해시 생성기</h1>
        <p class="description">
            새로운 사용자를 생성할 때 사용할 비밀번호 해시를 생성합니다.
            생성된 해시를 데이터베이스의 password 필드에 입력하세요.
        </p>
        
        <form method="POST">
            <div class="form-group">
                <label for="password">비밀번호 입력</label>
                <input type="password" 
                       id="password" 
                       name="password" 
                       placeholder="생성할 비밀번호를 입력하세요"
                       required>
            </div>
            
            <button type="submit" class="btn">✓ 해시 생성</button>
        </form>
        
        <?php if ($generated_hash): ?>
            <div class="result">
                <div class="result-label">생성된 해시 값:</div>
                <div class="hash-value" id="hashValue"><?php echo $generated_hash; ?></div>
                <button class="copy-btn" onclick="copyHash()">📋 복사</button>
            </div>
        <?php endif; ?>
        
        <div class="example">
            <div class="example-title">사용 예시:</div>
            <p style="margin-bottom: 10px;">1. 위에서 비밀번호를 입력하고 해시를 생성합니다.</p>
            <p style="margin-bottom: 10px;">2. 생성된 해시를 복사합니다.</p>
            <p style="margin-bottom: 10px;">3. phpMyAdmin 또는 MySQL 클라이언트에서 다음 SQL 실행:</p>
            <div style="background: #1f2937; padding: 10px; border-radius: 6px; margin-top: 10px;">
                <code style="display: block; background: transparent; padding: 0;">
                    INSERT INTO users (username, email, password, role, name)<br>
                    VALUES ('testuser', 'test@sinan.go.kr', '생성된_해시', 'field_worker', '테스트사용자');
                </code>
            </div>
        </div>
        
        <div class="warning">
            ⚠️ <strong>보안 주의사항:</strong><br>
            - 이 페이지는 개발/관리 용도로만 사용하세요.<br>
            - 운영 환경에서는 이 파일을 삭제하거나 접근을 제한하세요.<br>
            - 강력한 비밀번호를 사용하세요 (최소 8자, 영문+숫자+특수문자).<br>
            - 기본 관리자 비밀번호는 반드시 변경하세요.
        </div>
    </div>
    
    <script>
        function copyHash() {
            const hashValue = document.getElementById('hashValue').textContent;
            navigator.clipboard.writeText(hashValue).then(() => {
                alert('해시가 클립보드에 복사되었습니다!');
            });
        }
    </script>
</body>
</html>
