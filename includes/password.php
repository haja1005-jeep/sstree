<?php
/**
 * PHP 5.5 password_* 함수 호환 라이브러리
 * 파일명: includes/password.php
 */
if (!function_exists('password_hash')) {
    define('PASSWORD_BCRYPT', 1);
    define('PASSWORD_DEFAULT', PASSWORD_BCRYPT);

    function password_hash($password, $algo = PASSWORD_BCRYPT, $options = array()) {
        $cost = isset($options['cost']) ? $options['cost'] : 10;
        $salt = isset($options['salt']) ? $options['salt'] : substr(str_replace('+', '.', base64_encode(openssl_random_pseudo_bytes(16))), 0, 22);
        return crypt($password, sprintf('$2y$%02d$', $cost) . $salt);
    }

    function password_verify($password, $hash) {
        return crypt($password, $hash) === $hash;
    }

    function password_needs_rehash($hash, $algo = PASSWORD_BCRYPT, $options = array()) {
        $cost = isset($options['cost']) ? $options['cost'] : 10;
        return substr($hash, 0, 4) !== sprintf('$2y$%02d$', $cost);
    }

    function password_get_info($hash) {
        return array(
            'algo' => PASSWORD_BCRYPT,
            'algoName' => 'bcrypt',
            'options' => array('cost' => 10),
        );
    }
}
?>
