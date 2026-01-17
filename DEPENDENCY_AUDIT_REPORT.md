# 🔍 Dependency Audit Report
## Smart Tree Map (신안군 스마트 트리맵)

**Audit Date:** 2026-01-17
**Project Type:** PHP + MySQL Web Application
**Auditor:** Claude Code

---

## 📊 Executive Summary

This report analyzes the dependencies, security vulnerabilities, and code bloat in the Smart Tree Map project. The analysis reveals **critical security vulnerabilities**, **outdated dependencies**, and **significant code duplication** that should be addressed.

### Key Findings:
- ✅ **No package manager used** - All dependencies loaded via CDN
- ⚠️ **3 Critical Security Issues** identified in jQuery
- ⚠️ **6 Outdated Dependencies** requiring updates
- 🔴 **Hardcoded API Keys** exposed in version control
- 💾 **~700KB of duplicate JavaScript code** found
- 🗂️ **Backup directory** should be removed from production

---

## 🎯 Dependency Inventory

### PHP Runtime Dependencies
| Component | Current Version | Minimum Required | Status |
|-----------|----------------|------------------|--------|
| PHP | Not specified | 7.4+ | ⚠️ Should specify exact version |
| MySQL/MariaDB | Not specified | 5.7+ / 10.2+ | ⚠️ Should specify exact version |
| PDO Extension | Built-in | Required | ✅ OK |
| OpenSSL Extension | Built-in | Required (password hashing) | ✅ OK |

### External JavaScript Libraries (CDN)

#### Currently Active Dependencies
| Library | Version | Latest Version | Status | Severity |
|---------|---------|----------------|--------|----------|
| **jQuery** | 1.11.0 | 3.7.1 | 🔴 CRITICAL | **HIGH** |
| **jQuery** | 3.6.0 | 3.7.1 | ⚠️ Outdated | Medium |
| Chart.js | 3.9.1 | 4.4.1 | ⚠️ Outdated | Low |
| Pannellum | 2.5.6 | 2.5.6 | ✅ Current | None |
| SortableJS | 1.15.0 | 1.15.6 | ⚠️ Minor update | Low |
| exif-js | Latest (CDN) | 2.3.0 | ⚠️ Unmaintained | Medium |
| Google Fonts | Current | Current | ✅ OK | None |

#### Inactive/Unused Dependencies
| Library | Version | Usage | Recommendation |
|---------|---------|-------|----------------|
| PhotoSwipe | 5.4.4 | Commented out | 🗑️ **REMOVE** |

### External APIs
| API | Purpose | Security Concern |
|-----|---------|------------------|
| Kakao Maps API | Mapping functionality | 🔴 **API key exposed in code** |
| Kakao REST API | Address geocoding | 🔴 **API key exposed in code** |
| Google Drive API | File storage | ⚠️ Client-side OAuth |
| Google Sheets API | Data export | ⚠️ Client-side OAuth |
| VWorld API | Korean geo data | ✅ Public API |

---

## 🔐 Security Vulnerabilities

### CRITICAL Issues

#### 1. jQuery 1.11.0 - Multiple XSS Vulnerabilities 🔴
**Location:** `admin/locations/view_360vr.php:8`

**Vulnerabilities:**
- **CVE-2015-9251**: Cross-domain Ajax XSS vulnerability
- **CVE-2020-11022**: DOM manipulation XSS in .html() and related methods
- **CVE-2020-11023**: Passing HTML from untrusted sources may execute code

**Impact:** High - Allows attackers to inject malicious scripts
**CVSS Score:** 6.1 (Medium) to 6.9 (Medium)

**Recommendation:**
```html
<!-- CURRENT (VULNERABLE): -->
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>

<!-- RECOMMENDED: -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
        crossorigin="anonymous"></script>
```

**References:**
- [CVE Details - jQuery 1.11.0](https://www.cvedetails.com/version/1143039/Jquery-Jquery-1.11.0.html)
- [Snyk Security Database](https://security.snyk.io/package/npm/jquery)

#### 2. Hardcoded API Keys in Source Code 🔴
**Location:** `config/kakao_map.php`

**Exposed Secrets:**
```php
define('KAKAO_MAP_API_KEY', '257fdd3647dd6abdb05eae8681106514');
define('KAKAO_REST_API_KEY', '9bca2b309d1524cada7d463c408e256e');
```

**Also exposed in:**
- `sstree_view/tree_15.html:10`
- Multiple tree_input HTML files

**Impact:** Critical - API keys exposed in version control and public files
**Risk:** API key theft, unauthorized usage, billing fraud

**Recommendation:**
1. **Immediately rotate all API keys**
2. Move keys to environment variables:
```php
// config/kakao_map.php
define('KAKAO_MAP_API_KEY', getenv('KAKAO_MAP_API_KEY'));
define('KAKAO_REST_API_KEY', getenv('KAKAO_REST_API_KEY'));
```
3. Add `.env` to `.gitignore`
4. Use `.env.example` for documentation
5. Implement API key restrictions in Kakao Developer Console

#### 3. Insecure HTTP in Production 🔴
**Location:** `admin/locations/view_360vr.php:8`

```html
<!-- INSECURE: -->
<script src="http://ajax.googleapis.com/..."></script>

<!-- SHOULD BE: -->
<script src="https://ajax.googleapis.com/..."></script>
```

**Impact:** Man-in-the-middle attacks, script injection
**Recommendation:** Use HTTPS for all external resources

### MEDIUM Issues

#### 4. jQuery 3.6.0 - Outdated Version ⚠️
**Location:** `admin/locations/add.php:247`

**Current:** 3.6.0
**Latest:** 3.7.1

**Known Issues:** Minor security patches in 3.7.x

**Recommendation:**
```html
<script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
        crossorigin="anonymous"></script>
```

#### 5. Unmaintained Package: exif-js ⚠️
**Status:** No releases in 12+ months, inactive maintenance

**Recommendation:** Consider migrating to actively maintained alternatives:
- `exif-reader` (actively maintained)
- `exifr` (modern, actively maintained)
- Browser native EXIF reading where supported

#### 6. Database Credentials in Source Code ⚠️
**Location:** `config/database.php:8-11`

```php
private $host = "localhost";
private $db_name = "sstree";
private $username = "sstree";
private $password = "v1dbsstree$";  // ⚠️ EXPOSED
```

**Recommendation:** Move to environment variables

---

## 🗑️ Unnecessary Bloat & Cleanup

### Code Duplication - HIGH PRIORITY

#### 1. Multiple Script Versions (700KB+ duplicate code)
**Location:** `sstree_view/tree_input/`

11 different versions of similar scripts:
```
script_01.js (43KB)
script_02.js (53KB)
script_03.js (58KB)
script_04.js (58KB)
script_05.js (59KB)
script_06.js (81KB)
script_07.js (84KB)
script_08.js (95KB)
script_09.js (102KB)
script_10.js (101KB)
script_11.js (102KB)
Total: ~736KB
```

**Impact:**
- Confused maintenance - which version is current?
- Large repository size
- Potential security inconsistencies

**Recommendation:**
1. Identify the current/active version
2. Delete all old versions
3. Use git version control instead of file versioning
4. Consider using a build system for minification

#### 2. Backup Directory in Production 🗂️
**Location:** `admin/locations_old/`

**Issue:** Old code left in production codebase

**Recommendation:**
```bash
# Remove from production
rm -rf admin/locations_old/

# If needed for reference, it's in git history
git log -- admin/locations_old/
```

#### 3. Unused Dependency: PhotoSwipe 📦
**Location:** `includes/header.php:9-11`

```php
<!-- CURRENTLY COMMENTED OUT - NOT USED -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/photoswipe/5.4.4/photoswipe.min.css" />
<!--<script src="https://cdnjs.cloudflare.com/ajax/libs/photoswipe/5.4.4/umd/photoswipe.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/photoswipe/5.4.4/umd/photoswipe-lightbox.umd.min.js"></script> -->
```

**Recommendation:** Remove entirely if not used:
```bash
# Check if PhotoSwipe is used anywhere else
grep -r "photoswipe\|PhotoSwipe" --include="*.php" --include="*.js"
# If not used, delete the references
```

---

## 📦 Missing Package Management

### Current State
- ❌ No `composer.json` for PHP dependencies
- ❌ No `package.json` for JavaScript dependencies
- ❌ All dependencies loaded via CDN
- ❌ No version locking or integrity checks (except one instance)
- ❌ No dependency security scanning

### Recommendations

#### Option 1: Keep CDN Approach (Minimal Change)
**Pros:** Simple, no build process needed
**Cons:** No offline development, no version locking, CDN dependency

**Implementation:**
1. Add Subresource Integrity (SRI) to all CDN links
2. Document exact versions in a `DEPENDENCIES.md` file
3. Consider CDN fallbacks

Example:
```html
<script
  src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
  integrity="sha384-..."
  crossorigin="anonymous"
  onerror="loadLocalFallback()">
</script>
```

#### Option 2: Implement Package Management (Recommended)
**Pros:** Better version control, offline development, automated security scanning
**Cons:** Requires build process, larger repository

**Implementation:**

1. **Add Composer for PHP (if needed in future)**
```bash
composer init
```

2. **Add npm for JavaScript dependencies**
```bash
npm init -y
npm install jquery@3.7.1 chart.js@4.4.1 sortablejs@1.15.6 pannellum@2.5.6
```

3. **Create package.json:**
```json
{
  "name": "smart-tree-map",
  "version": "1.0.0",
  "dependencies": {
    "jquery": "^3.7.1",
    "chart.js": "^4.4.1",
    "sortablejs": "^1.15.6",
    "pannellum": "^2.5.6"
  },
  "devDependencies": {
    "npm-audit": "^1.0.0"
  },
  "scripts": {
    "audit": "npm audit",
    "update": "npm update"
  }
}
```

4. **Add automated security scanning:**
```bash
npm audit
npm audit fix
```

---

## 🎯 Prioritized Action Plan

### Phase 1: CRITICAL (Do Immediately) 🔴

#### 1.1 Fix jQuery 1.11.0 XSS Vulnerability
**File:** `admin/locations/view_360vr.php:8`
```diff
- <script src="http://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
+ <script src="https://code.jquery.com/jquery-3.7.1.min.js"
+         integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
+         crossorigin="anonymous"></script>
```

#### 1.2 Secure API Keys
1. Rotate all Kakao API keys immediately
2. Create `.env` file:
```bash
KAKAO_MAP_API_KEY=your_new_key_here
KAKAO_REST_API_KEY=your_new_key_here
DB_PASSWORD=your_secure_password_here
```

3. Update `config/kakao_map.php`:
```php
<?php
// Load from environment
define('KAKAO_MAP_API_KEY', getenv('KAKAO_MAP_API_KEY') ?: '');
define('KAKAO_REST_API_KEY', getenv('KAKAO_REST_API_KEY') ?: '');

// Validate keys are set
if (empty(KAKAO_MAP_API_KEY) || empty(KAKAO_REST_API_KEY)) {
    error_log('CRITICAL: Kakao API keys not configured');
}
```

4. Add to `.gitignore`:
```
.env
config/local_*.php
```

5. Create `.env.example`:
```
KAKAO_MAP_API_KEY=your_kakao_map_api_key
KAKAO_REST_API_KEY=your_kakao_rest_api_key
DB_PASSWORD=your_database_password
```

#### 1.3 Fix HTTP to HTTPS
**File:** `admin/locations/view_360vr.php:8`
```diff
- <script src="http://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
+ <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
```

**Estimated Time:** 2-4 hours
**Risk if not done:** High - Active security vulnerabilities

---

### Phase 2: HIGH PRIORITY (This Week) ⚠️

#### 2.1 Update All jQuery Instances
**Files to update:**
- `admin/locations/add.php:247` (3.6.0 → 3.7.1)
- Verify all other instances are 3.7.1

#### 2.2 Update Outdated Dependencies
```html
<!-- Chart.js: 3.9.1 → 4.4.1 -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- SortableJS: 1.15.0 → 1.15.6 -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
```

**Note:** Chart.js 4.x has breaking changes. Review documentation before updating.

#### 2.3 Clean Up Code Duplication
```bash
# Backup first
tar -czf tree_input_backup_$(date +%Y%m%d).tar.gz sstree_view/tree_input/

# Identify active script version (check which HTML files reference which script)
grep -r "script_[0-9]" sstree_view/tree_input/*.html

# After identifying the current version, delete old versions
# Example (adjust based on findings):
cd sstree_view/tree_input/
rm script_01.js script_02.js script_03.js script_04.js script_05.js # etc.
```

#### 2.4 Remove Backup Directory
```bash
# Verify it's not referenced anywhere
grep -r "locations_old" --include="*.php" --include="*.js"

# If safe, remove
rm -rf admin/locations_old/
```

**Estimated Time:** 4-8 hours
**Risk if not done:** Medium - Code confusion, potential bugs

---

### Phase 3: MEDIUM PRIORITY (This Month) 📋

#### 3.1 Add Subresource Integrity (SRI)
Add integrity checks to all CDN resources to prevent CDN compromise attacks.

**Tool:** Use [SRI Hash Generator](https://www.srihash.org/)

Example:
```html
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
        integrity="sha384-xxxx..."
        crossorigin="anonymous"></script>
```

#### 3.2 Create Dependency Documentation
Create `DEPENDENCIES.md`:
```markdown
# Project Dependencies

## External CDN Libraries
- jQuery 3.7.1 - DOM manipulation
- Chart.js 4.4.1 - Statistical charts
- Pannellum 2.5.6 - 360° image viewer
- SortableJS 1.15.6 - Drag-and-drop sorting
- exif-js latest - EXIF data extraction

## External APIs
- Kakao Maps API - Mapping and geocoding
- Google Drive API - File storage
- Google Sheets API - Data export
- VWorld API - Korean geodata

## Update Policy
- Check for updates: Monthly
- Security updates: Apply immediately
- Major version updates: Test in staging first
```

#### 3.3 Implement Environment Configuration
Create a proper environment configuration system:

**Create:** `config/env.php`
```php
<?php
/**
 * Environment Configuration Loader
 */

// Load .env file if it exists
if (file_exists(BASE_PATH . '/.env')) {
    $lines = file(BASE_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(sprintf('%s=%s', trim($name), trim($value)));
    }
}

// Define environment-based constants
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'sstree');
define('DB_USER', getenv('DB_USER') ?: 'sstree');
define('DB_PASSWORD', getenv('DB_PASSWORD'));
define('KAKAO_MAP_API_KEY', getenv('KAKAO_MAP_API_KEY'));
define('KAKAO_REST_API_KEY', getenv('KAKAO_REST_API_KEY'));

// Validate critical env vars
if (empty(DB_PASSWORD) || empty(KAKAO_MAP_API_KEY)) {
    die('CRITICAL: Required environment variables not set. Check .env file.');
}
```

**Estimated Time:** 6-10 hours

---

### Phase 4: LOW PRIORITY (Future Improvements) 💡

#### 4.1 Consider Package Manager Migration
Evaluate moving to npm-managed dependencies for:
- Better version control
- Automated security scanning
- Offline development capability
- Dependency lock files

#### 4.2 Replace Unmaintained Libraries
- Replace `exif-js` with `exifr` or `exif-reader`

#### 4.3 Implement Automated Security Scanning
Add to CI/CD pipeline:
```bash
# If using npm
npm audit

# If using Composer
composer audit
```

#### 4.4 Add Content Security Policy (CSP)
**File:** `includes/header.php`
```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://code.jquery.com https://dapi.kakao.com https://apis.google.com; style-src 'self' https://fonts.googleapis.com;");
```

**Estimated Time:** 8-16 hours

---

## 📈 Dependency Update Strategy

### Regular Maintenance Schedule

#### Monthly
- [ ] Check for security advisories
- [ ] Review dependency updates
- [ ] Run security scans

#### Quarterly
- [ ] Update minor versions
- [ ] Review unmaintained packages
- [ ] Audit API key usage

#### Annually
- [ ] Consider major version updates
- [ ] Review architecture decisions
- [ ] Evaluate new technologies

### Update Testing Checklist

Before updating any dependency:
- [ ] Read changelog for breaking changes
- [ ] Test in development environment
- [ ] Test critical user flows:
  - [ ] Location creation with map
  - [ ] Photo upload and ordering
  - [ ] 360° VR viewing
  - [ ] Chart rendering
  - [ ] Google Drive integration
- [ ] Check browser console for errors
- [ ] Test on multiple browsers
- [ ] Deploy to staging
- [ ] Get user acceptance
- [ ] Deploy to production

---

## 🔗 References & Resources

### Security Databases
- [CVE Details - jQuery](https://www.cvedetails.com/vulnerability-list/vendor_id-6538/Jquery.html)
- [Snyk Vulnerability Database](https://security.snyk.io/)
- [GitHub Advisory Database](https://github.com/advisories)

### Best Practices
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)
- [JavaScript Security Best Practices](https://developer.mozilla.org/en-US/docs/Web/Security)

### Tools
- [SRI Hash Generator](https://www.srihash.org/)
- [npm audit](https://docs.npmjs.com/cli/v8/commands/npm-audit)
- [Composer Security](https://packagist.org/)

---

## 📝 Summary of Recommendations

### Immediate Actions (Critical)
1. ✅ Update jQuery 1.11.0 to 3.7.1 (fixes XSS vulnerabilities)
2. ✅ Rotate and secure all API keys (move to environment variables)
3. ✅ Fix HTTP to HTTPS for all external resources
4. ✅ Remove hardcoded database credentials

### Short-term Actions (High Priority)
5. ✅ Update all outdated dependencies (Chart.js, SortableJS, etc.)
6. ✅ Clean up duplicate script files (save ~700KB)
7. ✅ Remove backup directories from production
8. ✅ Remove unused PhotoSwipe dependency

### Medium-term Actions
9. ✅ Implement Subresource Integrity (SRI) for CDN resources
10. ✅ Create proper environment configuration system
11. ✅ Document all dependencies and update policy
12. ✅ Replace unmaintained packages (exif-js)

### Long-term Improvements
13. ✅ Consider implementing package manager (npm/Composer)
14. ✅ Add automated security scanning to CI/CD
15. ✅ Implement Content Security Policy headers
16. ✅ Regular dependency audit schedule

---

## 🎖️ Conclusion

This project has a **moderate to high security risk** due to:
- Critical jQuery vulnerabilities
- Exposed API keys in source control
- Outdated dependencies

**Estimated total remediation time:** 20-30 hours across all phases

**Priority:** Address Phase 1 (Critical) items within 24-48 hours to mitigate active security risks.

---

**Report Generated:** 2026-01-17
**Next Review Due:** 2026-02-17 (30 days)
