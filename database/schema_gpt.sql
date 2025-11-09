-- Smart Tree Map Database Schema (MySQL 5.5, No Triggers)
-- 신안군 스마트 트리맵 데이터베이스

CREATE DATABASE IF NOT EXISTS sstree CHARACTER SET utf8 COLLATE utf8_general_ci;
USE sstree;

-- =========================================
-- 1) USERS
-- =========================================
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    user_id     INT PRIMARY KEY AUTO_INCREMENT,
    username    VARCHAR(50)  NOT NULL,
    email       VARCHAR(100) NOT NULL,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('admin','manager','field_worker') DEFAULT 'field_worker',
    name        VARCHAR(50),
    phone       VARCHAR(20),

    -- MySQL 5.5 제약 대응: created_at은 앱에서 NOW()로 세팅
    created_at  DATETIME NULL,
    last_login  TIMESTAMP NULL DEFAULT NULL,

    status      ENUM('active','inactive') DEFAULT 'active',

    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- =========================================
-- 2) REGIONS
-- =========================================
DROP TABLE IF EXISTS regions;
CREATE TABLE regions (
    region_id    INT PRIMARY KEY AUTO_INCREMENT,
    region_name  VARCHAR(100) NOT NULL,
    region_code  VARCHAR(20),
    description  TEXT,

    created_by   INT NULL,
    -- created_at은 자동 초기화(테이블 내 유일한 TIMESTAMP 자동 컬럼)
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_regions_user
      FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,

    INDEX idx_region_name (region_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- =========================================
-- 3) CATEGORIES
-- =========================================
DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
    category_id    INT PRIMARY KEY AUTO_INCREMENT,
    category_name  VARCHAR(100) NOT NULL,
    category_code  VARCHAR(20),
    description    TEXT,

    -- 유일 자동 TIMESTAMP
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_category_name (category_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- =========================================
-- 4) LOCATIONS (멀티미디어 포함)
-- =========================================
DROP TABLE IF EXISTS locations;
CREATE TABLE locations (
    location_id        INT PRIMARY KEY AUTO_INCREMENT,
    region_id          INT,
    category_id        INT,
    location_name      VARCHAR(200) NOT NULL,
    address            TEXT,

    latitude           DECIMAL(10,8),
    longitude          DECIMAL(11,8),

    area               DECIMAL(10,2) COMMENT '넓이(㎡) - 공원, 생활숲 등',
    length             DECIMAL(10,2) COMMENT '길이(m) - 가로수 등',
    width              DECIMAL(10,2) COMMENT '폭(m) - 가로수 도로폭 등',
    establishment_year INT COMMENT '조성년도',
    management_agency  VARCHAR(200) COMMENT '관리기관',
    video_url          VARCHAR(500) COMMENT '동영상 주소 (유튜브, 네이버TV 등)',
    description        TEXT,

    -- created_at은 앱에서 NOW()로 세팅
    created_at         DATETIME NULL,
    -- 테이블 내 유일 자동 TIMESTAMP: insert/init + on update
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_locations_region
      FOREIGN KEY (region_id) REFERENCES regions(region_id) ON DELETE CASCADE,
    CONSTRAINT fk_locations_category
      FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE CASCADE,

    INDEX idx_location_name (location_name),
    INDEX idx_coordinates (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- =========================================
-- 4-1) LOCATION_PHOTOS
-- =========================================
DROP TABLE IF EXISTS location_photos;
CREATE TABLE location_photos (
    photo_id     INT PRIMARY KEY AUTO_INCREMENT,
    location_id  INT NOT NULL,
    file_path    VARCHAR(500) NOT NULL,
    file_name    VARCHAR(200),
    file_size    INT,
    photo_type   ENUM('image','vr360') DEFAULT 'image' COMMENT 'image: 일반사진, vr360: 360도 VR 사진',
    sort_order   INT DEFAULT 0 COMMENT '정렬 순서',
    description  TEXT COMMENT '사진 설명',
    uploaded_by  INT,

    -- 유일 자동 TIMESTAMP
    uploaded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_locphotos_location
      FOREIGN KEY (location_id) REFERENCES locations(location_id) ON DELETE CASCADE,
    CONSTRAINT fk_locphotos_user
      FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE SET NULL,

    INDEX idx_location_id (location_id),
    INDEX idx_photo_type (photo_type),
    INDEX idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- =========================================
-- 5) TREE_SPECIES_MASTER
-- =========================================
DROP TABLE IF EXISTS tree_species_master;
CREATE TABLE tree_species_master (
    species_id       INT PRIMARY KEY AUTO_INCREMENT,
    scientific_name  VARCHAR(200) NOT NULL,
    korean_name      VARCHAR(100) NOT NULL,
    english_name     VARCHAR(100),
    family           VARCHAR(100) COMMENT '과명',
    genus            VARCHAR(100) COMMENT '속명',
    characteristics  TEXT COMMENT '특징',
    growth_info      TEXT COMMENT '생장 정보',
    care_guide       TEXT COMMENT '관리 가이드',
    thumbnail_url    VARCHAR(500),

    -- 유일 자동 TIMESTAMP
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_korean_name (korean_name),
    INDEX idx_scientific_name (scientific_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- =========================================
-- 6) TREES (현장 필드 데이터)
-- =========================================
DROP TABLE IF EXISTS trees;
CREATE TABLE trees (
    tree_id        INT PRIMARY KEY AUTO_INCREMENT,
    region_id      INT,
    category_id    INT,
    location_id    INT,
    species_id     INT,

    tree_number    VARCHAR(50) COMMENT '나무 번호',
    planting_date  DATE COMMENT '식재일',
    height         DECIMAL(5,2) COMMENT '높이(m)',
    diameter       DECIMAL(5,2) COMMENT '직경(cm)',

    health_status  ENUM('excellent','good','fair','poor','dead') DEFAULT 'good' COMMENT '건강상태',

    latitude       DECIMAL(10,8),
    longitude      DECIMAL(11,8),

    notes          TEXT COMMENT '비고',
    created_by     INT,

    -- created_at은 앱에서 NOW()로 세팅
    created_at     DATETIME NULL,
    -- 유일 자동 TIMESTAMP
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_trees_region
      FOREIGN KEY (region_id) REFERENCES regions(region_id) ON DELETE CASCADE,
    CONSTRAINT fk_trees_category
      FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE CASCADE,
    CONSTRAINT fk_trees_location
      FOREIGN KEY (location_id) REFERENCES locations(location_id) ON DELETE CASCADE,
    CONSTRAINT fk_trees_species
      FOREIGN KEY (species_id) REFERENCES tree_species_master(species_id) ON DELETE CASCADE,
    CONSTRAINT fk_trees_user
      FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,

    INDEX idx_tree_number (tree_number),
    INDEX idx_coordinates (latitude, longitude),
    INDEX idx_health_status (health_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- =========================================
-- 7) TREE_PHOTOS
-- =========================================
DROP TABLE IF EXISTS tree_photos;
CREATE TABLE tree_photos (
    photo_id     INT PRIMARY KEY AUTO_INCREMENT,
    tree_id      INT,
    file_path    VARCHAR(500) NOT NULL,
    file_name    VARCHAR(200),
    file_size    INT,
    photo_type   ENUM('full','leaf','bark','flower','fruit','other') DEFAULT 'full',

    latitude     DECIMAL(10,8),
    longitude    DECIMAL(11,8),

    taken_date   DATETIME NULL,  -- 촬영일은 수동/앱에서 세팅
    uploaded_by  INT,

    -- 유일 자동 TIMESTAMP
    uploaded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_treephotos_tree
      FOREIGN KEY (tree_id) REFERENCES trees(tree_id) ON DELETE CASCADE,
    CONSTRAINT fk_treephotos_user
      FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE SET NULL,

    INDEX idx_tree_id (tree_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- =========================================
-- 8) ACTIVITY_LOGS
-- =========================================
DROP TABLE IF EXISTS activity_logs;
CREATE TABLE activity_logs (
    log_id      INT PRIMARY KEY AUTO_INCREMENT,
    user_id     INT,
    action      VARCHAR(100),
    target_type VARCHAR(50),
    target_id   INT,
    details     TEXT,
    ip_address  VARCHAR(45),

    -- 유일 자동 TIMESTAMP
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_logs_user
      FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,

    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- =========================================
-- 초기 데이터
-- =========================================

-- 관리자 계정 (비밀번호: admin123)
INSERT INTO users (username, email, password, role, name, status, created_at)
VALUES (
  'admin',
  'admin@sinan.go.kr',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'admin',
  '시스템관리자',
  'active',
  NOW()
);

-- 기본 카테고리
INSERT INTO categories (category_name, category_code, description)
VALUES ('공원','PARK','도시공원 및 근린공원'),
       ('생활숲','FOREST','생활권 도시숲'),
       ('가로수','STREET','도로변 가로수'),
       ('보호수','PROTECTED','보호수 및 노거수');

-- 기본 지역 (신안군 읍면)
INSERT INTO regions (region_name, region_code, description, created_by)
VALUES ('압해읍','APHAE','압해읍 지역',1),
       ('증도면','JEUNGDO','증도면 지역',1),
       ('임자면','IMJA','임자면 지역',1),
       ('자은면','JAEUN','자은면 지역',1),
       ('비금면','BIGEUM','비금면 지역',1),
       ('도초면','DOCHO','도초면 지역',1),
       ('흑산면','HEUKSAN','흑산면 지역',1),
       ('하의면','HAUI','하의면 지역',1),
       ('신의면','SINUI','신의면 지역',1),
       ('장산면','JANGSAN','장산면 지역',1),
       ('안좌면','ANJWA','안좌면 지역',1),
       ('팔금면','PALGEUM','팔금면 지역',1),
       ('암태면','AMTAE','암태면 지역',1);

-- 샘플 수종 데이터
INSERT INTO tree_species_master (scientific_name, korean_name, english_name, family, genus, characteristics)
VALUES ('Ginkgo biloba','은행나무','Ginkgo','은행나무과','은행나무속','낙엽 활엽 교목으로 가을 단풍이 아름다움'),
       ('Zelkova serrata','느티나무','Japanese Zelkova','느릅나무과','느티나무속','우리나라 대표 가로수, 수명이 길고 수형이 아름다움'),
       ('Prunus serrulata','왕벚나무','Cherry Blossom','장미과','벚나무속','봄에 화려한 꽃을 피우는 대표 수종'),
       ('Pinus densiflora','소나무','Korean Red Pine','소나무과','소나무속','한국의 대표 수종, 척박한 땅에서도 잘 자람'),
       ('Metasequoia glyptostroboides','메타세쿼이아','Dawn Redwood','낙우송과','메타세쿼이아속','가로수로 많이 심어지며 가을 단풍이 붉게 물듦');
