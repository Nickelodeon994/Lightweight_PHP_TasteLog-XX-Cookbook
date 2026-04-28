<?php

/**
 * 轻量食谱分享社区 - 后端
 * 
 * @version 0.0.1
 * @build 2026-04-28
 * @author Nickelodeon994
 * @link https://github.com/Nickelodeon994/Lightweight_PHP_TasteLog-XX-Cookbook
 * @license Apache-2.0
 * 
 * 更新日志：
 * - 0.0.1 (2026-04-28) 初始版本
 *   * 用户系统（注册/登录/自动登录令牌）
 *   * 帖子发布（支持图片/视频，最多9个媒体）
 *   * 互动功能：点赞/点踩/收藏/关注
 *   * 打卡系统与连续打卡日历
 *   * 搜索功能（标题+内容关键词）
 *   * 举报系统与管理员后台
 *   * 响应式前端，支持亮色/暗色主题
 *   * 基于 JSON 文件存储，无需数据库
 */

/**
 * Copyright 2026 Nickelodeon994
 * 
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * 
 *     http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

session_start();

define('DATA_DIR', __DIR__ . '/data');
define('USERS_DIR', DATA_DIR . '/users');
define('POSTS_DIR', DATA_DIR . '/posts');
define('MEDIA_DIR', DATA_DIR . '/media');
define('REPORTS_DIR', DATA_DIR . '/reports');
define('EMAILS_DIR', DATA_DIR . '/emails');

define('MAX_IMAGE_SIZE', 5 * 1024 * 1024);
define('MAX_VIDEO_SIZE', 30 * 1024 * 1024);
define('MAX_BROWSE_HISTORY', 20);
define('POSTS_PER_PAGE', 10);
define('MAX_MEDIA_PER_POST', 9);
define('THUMB_WIDTH', 200);

function init_data_dir() {
    $dirs = [DATA_DIR, USERS_DIR, POSTS_DIR, MEDIA_DIR, REPORTS_DIR, EMAILS_DIR];
    foreach ($dirs as $d) {
        if (!is_dir($d)) mkdir($d, 0777, true);
    }
    
    $settings_file = DATA_DIR . '/settings.json';
    if (!file_exists($settings_file)) {
        file_put_contents($settings_file, json_encode([
            'site_title' => 'XX的食谱',
            'site_icon' => '',
            'allow_guest_browse' => true,
            'allow_register' => true,
            'theme_mode' => 'auto'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    $counter_file = DATA_DIR . '/uid_counter.txt';
    if (!file_exists($counter_file)) {
        file_put_contents($counter_file, '10000');
    }
}

init_data_dir();

function json_read($path, $default = null) {
    if (!file_exists($path)) return $default;
    $content = file_get_contents($path);
    if ($content === false) return $default;
    $data = json_decode($content, true);
    return $data !== null ? $data : $default;
}

function json_write($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $tmp = $path . '.tmp.' . uniqid();
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, $path);
    return true;
}

function sanitize($s) {
    return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $s);
}

function generate_uid() {
    $counter_file = DATA_DIR . '/uid_counter.txt';
    $fp = fopen($counter_file, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $counter = intval(trim(fgets($fp))) ?: 10000;
    $counter++;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, strval($counter));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $counter;
}

function generate_id() {
    return str_replace('.', '', microtime(true)) . '_' . rand(1000, 9999);
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function error($message, $code = 400) {
    json_response(['success' => false, 'message' => $message], $code);
}

function success($data = []) {
    json_response(array_merge(['success' => true], $data));
}

function require_login() {
    if (empty($_SESSION['uid'])) {
        if (!try_autologin()) {
            error('请先登录', 401);
        }
    }
}

function require_admin() {
    require_login();
    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') error('权限不足', 403);
}

function get_current_uid() {
    return $_SESSION['uid'] ?? null;
}

function generate_token() {
    return bin2hex(random_bytes(32));
}

function save_login_token($uid, $token) {
    $profile = get_user_profile($uid);
    if (!$profile) return false;
    $profile['login_token'] = $token;
    $profile['login_token_time'] = time();
    update_user_profile($uid, $profile);
    return true;
}

function verify_login_token($uid, $token) {
    $profile = get_user_profile($uid);
    if (!$profile) return false;
    if (empty($profile['login_token']) || $profile['login_token'] !== $token) return false;
    if (time() - ($profile['login_token_time'] ?? 0) > 365 * 24 * 3600) return false;
    return true;
}

function try_autologin() {
    if (empty($_COOKIE['auth_uid']) || empty($_COOKIE['auth_token'])) return false;
    $uid = intval($_COOKIE['auth_uid']);
    $token = $_COOKIE['auth_token'];
    if (!verify_login_token($uid, $token)) return false;
    $profile = get_user_profile($uid);
    if (!$profile) return false;
    $_SESSION['uid'] = $uid;
    $_SESSION['username'] = $profile['username'];
    $_SESSION['role'] = $profile['role'] ?? 'user';
    return true;
}

function get_user_file($uid) {
    return USERS_DIR . '/' . intval($uid) . '.json';
}

function get_user_data($uid) {
    $file = get_user_file($uid);
    return json_read($file, null);
}

function save_user_data($uid, $data) {
    $file = get_user_file($uid);
    return json_write($file, $data);
}

function get_user_profile($uid) {
    $data = get_user_data($uid);
    if (!$data) return null;
    return $data['profile'] ?? null;
}

function get_user_avatar($uid) {
    $profile = get_user_profile($uid);
    return $profile['avatar'] ?? '';
}

function get_user_badge($uid) {
    $profile = get_user_profile($uid);
    return $profile['badge'] ?? '';
}

function get_user_name($uid) {
    $profile = get_user_profile($uid);
    return $profile['username'] ?? '未知用户';
}

function get_user_posts($uid) {
    $data = get_user_data($uid);
    return $data['posts'] ?? [];
}

function get_user_favorites($uid) {
    $data = get_user_data($uid);
    return $data['favorites'] ?? [];
}

function get_user_followings($uid) {
    $data = get_user_data($uid);
    return $data['followings'] ?? [];
}

function get_user_followers($uid) {
    $data = get_user_data($uid);
    return $data['followers'] ?? [];
}

function get_user_reactions($uid) {
    $data = get_user_data($uid);
    return $data['reactions'] ?? [];
}

function get_user_browse_history($uid) {
    $data = get_user_data($uid);
    return $data['browse_history'] ?? [];
}

function get_user_checkin_dates($uid) {
    $data = get_user_data($uid);
    return $data['checkin_dates'] ?? [];
}

function create_user($uid, $username, $email, $password, $role = 'user') {
    $data = [
        'profile' => [
            'uid' => $uid,
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'avatar' => '',
            'badge' => '',
            'reg_time' => date('Y-m-d H:i:s'),
            'continuous_days' => 0,
            'role' => $role
        ],
        'posts' => [],
        'favorites' => [],
        'followings' => [],
        'followers' => [],
        'reactions' => [],
        'browse_history' => [],
        'checkin_dates' => []
    ];
    return save_user_data($uid, $data);
}

function update_user_field($uid, $field, $value) {
    $data = get_user_data($uid);
    if (!$data) return false;
    $data[$field] = $value;
    return save_user_data($uid, $data);
}

function update_user_profile_field($uid, $field, $value) {
    $data = get_user_data($uid);
    if (!$data) return false;
    $data['profile'][$field] = $value;
    return save_user_data($uid, $data);
}

function update_user_profile($uid, $profile) {
    $data = get_user_data($uid);
    if (!$data) return false;
    $data['profile'] = $profile;
    return save_user_data($uid, $data);
}

function get_email_file($email) {
    $hash = md5(strtolower(trim($email)));
    return EMAILS_DIR . '/' . $hash . '.json';
}

function get_email_uid($email) {
    $file = get_email_file($email);
    $data = json_read($file, null);
    return $data ? $data['uid'] : null;
}

function save_email_mapping($email, $uid) {
    $file = get_email_file($email);
    return json_write($file, ['uid' => $uid, 'email' => $email]);
}

function delete_email_mapping($email) {
    $file = get_email_file($email);
    if (file_exists($file)) unlink($file);
}

function get_post_file($postId) {
    return POSTS_DIR . '/' . sanitize($postId) . '.json';
}

function get_post($postId) {
    $file = get_post_file($postId);
    return json_read($file, null);
}

function save_post($postId, $post) {
    $file = get_post_file($postId);
    return json_write($file, $post);
}

function delete_post_file($postId) {
    $file = get_post_file($postId);
    if (file_exists($file)) {
        unlink($file);
        return true;
    }
    return false;
}

function get_post_detail($postId, $withAuthor = false) {
    $post = get_post($postId);
    if (!$post) return null;
    
    if ($withAuthor) {
        $post['author'] = [
            'uid' => $post['uid'],
            'username' => get_user_name($post['uid']),
            'avatar' => get_user_avatar($post['uid']),
            'badge' => get_user_badge($post['uid'])
        ];
    }
    return $post;
}

function get_all_post_ids() {
    $files = glob(POSTS_DIR . '/*.json');
    $ids = [];
    foreach ($files as $f) {
        $ids[] = basename($f, '.json');
    }
    return $ids;
}

function delete_post($postId) {
    $post = get_post($postId);
    if (!$post) return false;
    
    $uid = $post['uid'];
    
    delete_post_file($postId);
    
    $posts = get_user_posts($uid);
    $posts = array_diff($posts, [$postId]);
    update_user_field($uid, 'posts', array_values($posts));
    
    if (!empty($post['media'])) {
        foreach ($post['media'] as $m) {
            $mediaPath = __DIR__ . '/' . $m['url'];
            if (file_exists($mediaPath)) unlink($mediaPath);
            if (!empty($m['thumb'])) {
                $thumbPath = __DIR__ . '/' . $m['thumb'];
                if (file_exists($thumbPath)) unlink($thumbPath);
            }
        }
    }
    
    return true;
}

function attach_user_status(&$post, $uid = null) {
    if ($uid === null) $uid = get_current_uid();
    
    if (!$uid) {
        $post['user_reaction'] = null;
        $post['is_favorite'] = false;
        return;
    }
    
    $reactions = get_user_reactions($uid);
    $post['user_reaction'] = $reactions[$post['id']] ?? null;
    
    $favorites = get_user_favorites($uid);
    $post['is_favorite'] = in_array($post['id'], $favorites);
}

function update_checkin($uid) {
    $dates = get_user_checkin_dates($uid);
    $today = date('Y-m-d');
    
    if (in_array($today, $dates)) {
        return ['checked' => false, 'continuous_days' => count_continuous_days($dates)];
    }
    
    $dates[] = $today;
    sort($dates);
    update_user_field($uid, 'checkin_dates', $dates);
    
    $continuous = count_continuous_days($dates);
    update_user_profile_field($uid, 'continuous_days', $continuous);
    
    return ['checked' => true, 'continuous_days' => $continuous];
}

function count_continuous_days($dates) {
    if (empty($dates)) return 0;
    rsort($dates);
    $count = 1;
    $today = date('Y-m-d');
    
    $lastDate = $dates[0];
    if ($lastDate !== $today) {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($lastDate !== $yesterday) return 0;
    }
    
    for ($i = 1; $i < count($dates); $i++) {
        $expected = date('Y-m-d', strtotime($dates[$i-1] . ' -1 day'));
        if ($dates[$i] === $expected) {
            $count++;
        } else {
            break;
        }
    }
    return $count;
}

function record_browse_history($uid, $postId) {
    $history = get_user_browse_history($uid);
    $history = array_diff($history, [$postId]);
    array_unshift($history, $postId);
    $history = array_slice($history, 0, MAX_BROWSE_HISTORY);
    update_user_field($uid, 'browse_history', $history);
}

function handle_media_upload($files) {
    $result = [];
    if (empty($files) || empty($files['name'])) return $result;
    
    $count = is_array($files['name']) ? count($files['name']) : 1;
    
    for ($i = 0; $i < $count && $i < MAX_MEDIA_PER_POST; $i++) {
        if (is_array($files['name'])) {
            $name = $files['name'][$i];
            $tmp = $files['tmp_name'][$i];
            $size = $files['size'][$i];
            $error = $files['error'][$i];
        } else {
            $name = $files['name'];
            $tmp = $files['tmp_name'];
            $size = $files['size'];
            $error = $files['error'];
        }
        
        if ($error !== UPLOAD_ERR_OK || empty($tmp)) continue;
        
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed_img = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowed_vid = ['mp4', 'webm'];
        
        $isImage = in_array($ext, $allowed_img);
        $isVideo = in_array($ext, $allowed_vid);
        
        if (!$isImage && !$isVideo) continue;
        if ($isImage && $size > MAX_IMAGE_SIZE) continue;
        if ($isVideo && $size > MAX_VIDEO_SIZE) continue;
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);
        
        $validMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm'];
        if (!in_array($mime, $validMimes)) continue;
        
        $yyyy = date('Y');
        $mm = date('m');
        $mediaDir = MEDIA_DIR . '/' . $yyyy . '/' . $mm;
        if (!is_dir($mediaDir)) mkdir($mediaDir, 0777, true);
        
        $postId = generate_id();
        $filename = $postId . '_' . $i . '.' . $ext;
        $dest = $mediaDir . '/' . $filename;
        
        if (move_uploaded_file($tmp, $dest)) {
            $url = 'data/media/' . $yyyy . '/' . $mm . '/' . $filename;
            $thumb = '';
            
            if ($isImage && extension_loaded('gd')) {
                $thumbFilename = 'thumb_' . $postId . '_' . $i . '.jpg';
                $thumbPath = $mediaDir . '/' . $thumbFilename;
                if (create_thumbnail($dest, $thumbPath, THUMB_WIDTH)) {
                    $thumb = 'data/media/' . $yyyy . '/' . $mm . '/' . $thumbFilename;
                }
            }
            
            $result[] = [
                'url' => $url,
                'thumb' => $thumb,
                'type' => $isImage ? 'image' : 'video',
                'name' => $name
            ];
        }
    }
    
    return $result;
}

function create_thumbnail($src, $dest, $width) {
    $info = getimagesize($src);
    if (!$info) return false;
    
    $w = $info[0];
    $h = $info[1];
    $ratio = $width / $w;
    $newH = intval($h * $ratio);
    
    switch ($info['mime']) {
        case 'image/jpeg': $srcImg = imagecreatefromjpeg($src); break;
        case 'image/png': $srcImg = imagecreatefrompng($src); break;
        case 'image/gif': $srcImg = imagecreatefromgif($src); break;
        case 'image/webp': $srcImg = imagecreatefromwebp($src); break;
        default: return false;
    }
    
    if (!$srcImg) return false;
    
    $dstImg = imagecreatetruecolor($width, $newH);
    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $width, $newH, $w, $h);
    imagejpeg($dstImg, $dest, 85);
    imagedestroy($srcImg);
    imagedestroy($dstImg);
    return true;
}

function search_posts($keyword, $page = 1, $limit = 10) {
    $keyword = mb_strtolower(trim($keyword));
    if (empty($keyword)) return ['posts' => [], 'total' => 0];
    
    $allIds = get_all_post_ids();
    $scored = [];
    
    foreach ($allIds as $postId) {
        $post = get_post($postId);
        if (!$post) continue;
        
        $title = mb_strtolower($post['title'] ?? '');
        $content = mb_strtolower($post['content'] ?? '');
        
        $score = 0;
        if (mb_strpos($title, $keyword) !== false) $score += 2;
        if (mb_strpos($content, $keyword) !== false) $score += 1;
        
        if ($score > 0) {
            $scored[] = ['post' => $post, 'score' => $score];
        }
    }
    
    usort($scored, function($a, $b) {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        return strtotime($b['post']['created_at']) <=> strtotime($a['post']['created_at']);
    });
    
    $total = count($scored);
    $offset = ($page - 1) * $limit;
    $results = array_slice($scored, $offset, $limit);
    
    $posts = [];
    foreach ($results as $r) {
        $p = $r['post'];
        $p['author'] = [
            'uid' => $p['uid'],
            'username' => get_user_name($p['uid']),
            'avatar' => get_user_avatar($p['uid']),
            'badge' => get_user_badge($p['uid'])
        ];
        $posts[] = $p;
    }
    
    return ['posts' => $posts, 'total' => $total];
}

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'getSettings':
        $settings = json_read(DATA_DIR . '/settings.json', [
            'site_title' => 'XX的食谱',
            'site_icon' => '',
            'allow_guest_browse' => true,
            'allow_register' => true,
            'theme_mode' => 'auto'
        ]);
        success($settings);
        break;

    case 'register':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        $settings = json_read(DATA_DIR . '/settings.json', ['allow_register' => true]);
        if (empty($settings['allow_register'])) error('当前不允许注册');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error('邮箱格式不正确');
        if (mb_strlen($username) < 2 || mb_strlen($username) > 20) error('昵称长度2-20字符');
        if (strlen($password) < 6) error('密码至少6位');
        
        if (get_email_uid($email)) error('该邮箱已注册');
        
        $uid = generate_uid();
        if (!$uid) error('系统错误，请重试');
        
        $isFirst = count(glob(USERS_DIR . '/*.json')) === 0;
        $role = $isFirst ? 'admin' : 'user';
        
        create_user($uid, $username, $email, $password, $role);
        save_email_mapping($email, $uid);
        
        $_SESSION['uid'] = $uid;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $role;
        
        $token = generate_token();
        save_login_token($uid, $token);
        setcookie('auth_uid', $uid, time() + 365 * 24 * 3600, '/', '', false, true);
        setcookie('auth_token', $token, time() + 365 * 24 * 3600, '/', '', false, true);
        
        success(['uid' => $uid, 'username' => $username, 'role' => $role]);
        break;

    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        
        $uid = get_email_uid($email);
        if (!$uid) error('邮箱或密码错误');
        
        $profile = get_user_profile($uid);
        if (!$profile) error('用户不存在');
        
        if (!password_verify($password, $profile['password'])) error('邮箱或密码错误');
        
        $_SESSION['uid'] = $uid;
        $_SESSION['username'] = $profile['username'];
        $_SESSION['role'] = $profile['role'];
        
        $token = generate_token();
        save_login_token($uid, $token);
        setcookie('auth_uid', $uid, time() + 365 * 24 * 3600, '/', '', false, true);
        setcookie('auth_token', $token, time() + 365 * 24 * 3600, '/', '', false, true);
        
        success([
            'uid' => $uid,
            'username' => $profile['username'],
            'avatar' => $profile['avatar'],
            'role' => $profile['role']
        ]);
        break;

    case 'logout':
        if (!empty($_SESSION['uid'])) {
            $uid = $_SESSION['uid'];
            $profile = get_user_profile($uid);
            if ($profile) {
                $profile['login_token'] = '';
                update_user_profile($uid, $profile);
            }
        }
        session_destroy();
        setcookie('auth_uid', '', time() - 3600, '/', '', false, true);
        setcookie('auth_token', '', time() - 3600, '/', '', false, true);
        success();
        break;

    case 'getMe':
        if (empty($_SESSION['uid'])) {
            if (!try_autologin()) {
                success(['logged_in' => false]);
                break;
            }
        }
        $profile = get_user_profile($_SESSION['uid']);
        success([
            'logged_in' => true,
            'uid' => $_SESSION['uid'],
            'username' => $_SESSION['username'],
            'avatar' => $profile['avatar'] ?? '',
            'role' => $_SESSION['role'] ?? 'user'
        ]);
        break;

    case 'getPosts':
        $type = $_GET['type'] ?? 'explore';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = max(1, min(50, intval($_GET['limit'] ?? POSTS_PER_PAGE)));
        $keyword = trim($_GET['keyword'] ?? '');
        
        $posts = [];
        $total = 0;
        
        if ($type === 'search' && !empty($keyword)) {
            $result = search_posts($keyword, $page, $limit);
            $posts = $result['posts'];
            $total = $result['total'];
        } elseif ($type === 'explore') {
            $allIds = get_all_post_ids();
            $postData = [];
            foreach ($allIds as $pid) {
                $p = get_post($pid);
                if ($p) $postData[] = $p;
            }
            usort($postData, function($a, $b) {
                return strtotime($b['created_at']) <=> strtotime($a['created_at']);
            });
            $total = count($postData);
            $offset = ($page - 1) * $limit;
            $posts = array_slice($postData, $offset, $limit);
            foreach ($posts as &$p) {
                $p['author'] = [
                    'uid' => $p['uid'],
                    'username' => get_user_name($p['uid']),
                    'avatar' => get_user_avatar($p['uid']),
                    'badge' => get_user_badge($p['uid'])
                ];
            }
            unset($p);
        } elseif ($type === 'follow') {
            require_login();
            $uid = get_current_uid();
            $followingIds = get_user_followings($uid);
            $allPostIds = [];
            foreach ($followingIds as $fuid) {
                $allPostIds = array_merge($allPostIds, get_user_posts($fuid));
            }
            $postData = [];
            foreach ($allPostIds as $pid) {
                $p = get_post($pid);
                if ($p) $postData[] = $p;
            }
            usort($postData, function($a, $b) {
                return strtotime($b['created_at']) <=> strtotime($a['created_at']);
            });
            $total = count($postData);
            $offset = ($page - 1) * $limit;
            $posts = array_slice($postData, $offset, $limit);
            foreach ($posts as &$p) {
                $p['author'] = [
                    'uid' => $p['uid'],
                    'username' => get_user_name($p['uid']),
                    'avatar' => get_user_avatar($p['uid']),
                    'badge' => get_user_badge($p['uid'])
                ];
            }
            unset($p);
        } elseif ($type === 'favorite') {
            require_login();
            $uid = get_current_uid();
            $favIds = get_user_favorites($uid);
            rsort($favIds);
            $total = count($favIds);
            $offset = ($page - 1) * $limit;
            $pageIds = array_slice($favIds, $offset, $limit);
            foreach ($pageIds as $pid) {
                $p = get_post_detail($pid, true);
                if ($p) $posts[] = $p;
            }
        } elseif ($type === 'user_posts') {
            $targetUid = intval($_GET['uid'] ?? get_current_uid());
            $postIds = get_user_posts($targetUid);
            rsort($postIds);
            $total = count($postIds);
            $offset = ($page - 1) * $limit;
            $pageIds = array_slice($postIds, $offset, $limit);
            foreach ($pageIds as $pid) {
                $p = get_post_detail($pid, true);
                if ($p) $posts[] = $p;
            }
        } elseif ($type === 'liked') {
            require_login();
            $uid = get_current_uid();
            $reactions = get_user_reactions($uid);
            $likedIds = [];
            foreach ($reactions as $pid => $rtype) {
                if ($rtype === 'like') $likedIds[] = $pid;
            }
            rsort($likedIds);
            $total = count($likedIds);
            $offset = ($page - 1) * $limit;
            $pageIds = array_slice($likedIds, $offset, $limit);
            foreach ($pageIds as $pid) {
                $p = get_post_detail($pid, true);
                if ($p) $posts[] = $p;
            }
        }
        
        $currentUid = get_current_uid();
        foreach ($posts as &$p) {
            attach_user_status($p, $currentUid);
        }
        unset($p);
        
        success(['posts' => $posts, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        break;

    case 'getPostDetail':
        $postId = $_GET['postId'] ?? '';
        if (empty($postId)) error('帖子ID不能为空');
        $post = get_post_detail($postId, true);
        if (!$post) error('帖子不存在', 404);
        attach_user_status($post);
        success(['post' => $post]);
        break;

    case 'createPost':
        require_login();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $uid = get_current_uid();
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        
        if (empty($title)) error('标题不能为空');
        if (mb_strlen($title) > 200) error('标题最多200字符');
        
        $postId = generate_id();
        $now = date('Y-m-d H:i:s');
        
        $media = [];
        if (!empty($_FILES['media'])) {
            $media = handle_media_upload($_FILES['media']);
        }
        
        $post = [
            'id' => $postId,
            'uid' => $uid,
            'title' => $title,
            'content' => $content,
            'media' => $media,
            'likes' => 0,
            'dislikes' => 0,
            'favorites' => 0,
            'created_at' => $now,
            'updated_at' => $now
        ];
        
        save_post($postId, $post);
        
        $posts = get_user_posts($uid);
        $posts[] = $postId;
        update_user_field($uid, 'posts', $posts);
        
        $checkin = update_checkin($uid);
        record_browse_history($uid, $postId);
        
        success(['postId' => $postId, 'checkin' => $checkin]);
        break;

    case 'updatePost':
        require_login();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $uid = get_current_uid();
        $postId = $_POST['postId'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        
        if (empty($postId)) error('帖子ID不能为空');
        if (empty($title)) error('标题不能为空');
        if (mb_strlen($title) > 200) error('标题最多200字符');
        
        $post = get_post($postId);
        if (!$post) error('帖子不存在', 404);
        if ($post['uid'] != $uid) error('无权编辑此帖子');
        
        $post['title'] = $title;
        $post['content'] = $content;
        $post['updated_at'] = date('Y-m-d H:i:s');
        
        if (!empty($_FILES['media'])) {
            $newMedia = handle_media_upload($_FILES['media']);
            $post['media'] = array_merge($post['media'] ?? [], $newMedia);
        }
        
        save_post($postId, $post);
        success(['postId' => $postId]);
        break;

    case 'deletePost':
        require_login();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $uid = get_current_uid();
        $postId = $_POST['postId'] ?? '';
        
        if (empty($postId)) error('帖子ID不能为空');
        
        $post = get_post($postId);
        if (!$post) error('帖子不存在', 404);
        if ($post['uid'] != $uid) error('无权删除此帖子');
        
        unlink(POSTS_DIR . '/' . sanitize($postId) . '.json');
        
        $userPosts = get_user_posts($uid);
        $userPosts = array_filter($userPosts, function($p) use ($postId) {
            return $p !== $postId;
        });
        update_user_field($uid, 'posts', array_values($userPosts));
        
        success();
        break;

    case 'react':
        require_login();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $uid = get_current_uid();
        $postId = $_POST['postId'] ?? '';
        $type = $_POST['type'] ?? '';
        
        if (!in_array($type, ['like', 'dislike'])) error('无效的操作类型');
        $post = get_post($postId);
        if (!$post) error('帖子不存在', 404);
        
        $reactions = get_user_reactions($uid);
        $oldType = $reactions[$postId] ?? null;
        
        if ($oldType === $type) {
            unset($reactions[$postId]);
            $post[$type . 's'] = max(0, ($post[$type . 's'] ?? 0) - 1);
        } else {
            if ($oldType) {
                $post[$oldType . 's'] = max(0, ($post[$oldType . 's'] ?? 0) - 1);
            }
            $reactions[$postId] = $type;
            $post[$type . 's'] = ($post[$type . 's'] ?? 0) + 1;
        }
        
        update_user_field($uid, 'reactions', $reactions);
        $post['updated_at'] = date('Y-m-d H:i:s');
        save_post($postId, $post);
        
        success([
            'likes' => $post['likes'],
            'dislikes' => $post['dislikes'],
            'user_reaction' => ($oldType === $type) ? null : $type
        ]);
        break;

    case 'favorite':
        require_login();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $uid = get_current_uid();
        $postId = $_POST['postId'] ?? '';
        
        $post = get_post($postId);
        if (!$post) error('帖子不存在', 404);
        
        $favs = get_user_favorites($uid);
        
        if (in_array($postId, $favs)) {
            $favs = array_diff($favs, [$postId]);
            $post['favorites'] = max(0, ($post['favorites'] ?? 0) - 1);
            $isFav = false;
        } else {
            $favs[] = $postId;
            $post['favorites'] = ($post['favorites'] ?? 0) + 1;
            $isFav = true;
        }
        
        update_user_field($uid, 'favorites', array_values($favs));
        $post['updated_at'] = date('Y-m-d H:i:s');
        save_post($postId, $post);
        
        success(['is_favorite' => $isFav, 'favorites' => $post['favorites']]);
        break;

    case 'report':
        require_login();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $uid = get_current_uid();
        $postId = $_POST['postId'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        
        if (empty($reason)) error('举报原因不能为空');
        $post = get_post($postId);
        if (!$post) error('帖子不存在', 404);
        
        $reportId = generate_id();
        $report = [
            'id' => $reportId,
            'postId' => $postId,
            'reporterUid' => $uid,
            'reason' => $reason,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $file = REPORTS_DIR . '/' . sanitize($reportId) . '.json';
        json_write($file, $report);
        
        success(['reportId' => $reportId]);
        break;

    case 'getProfile':
        $uid = intval($_GET['uid'] ?? get_current_uid());
        $profile = get_user_profile($uid);
        if (!$profile) error('用户不存在', 404);
        
        unset($profile['password']);
        
        $isFollowing = false;
        if (!empty($_SESSION['uid']) && $_SESSION['uid'] != $uid) {
            $followings = get_user_followings($_SESSION['uid']);
            $isFollowing = in_array($uid, $followings);
        }
        
        $profile['is_following'] = $isFollowing;
        $profile['post_count'] = count(get_user_posts($uid));
        $profile['follower_count'] = count(get_user_followers($uid));
        $profile['following_count'] = count(get_user_followings($uid));
        
        success(['profile' => $profile]);
        break;

    case 'getUserPosts':
        $uid = intval($_GET['uid'] ?? get_current_uid());
        $type = $_GET['type'] ?? 'posts';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = max(1, min(50, intval($_GET['limit'] ?? POSTS_PER_PAGE)));
        
        $posts = [];
        $total = 0;
        
        if ($type === 'posts') {
            $postIds = get_user_posts($uid);
            rsort($postIds);
            $total = count($postIds);
            $offset = ($page - 1) * $limit;
            $pageIds = array_slice($postIds, $offset, $limit);
            foreach ($pageIds as $pid) {
                $p = get_post_detail($pid, true);
                if ($p) $posts[] = $p;
            }
        } elseif ($type === 'liked') {
            $reactions = get_user_reactions($uid);
            $likedIds = [];
            foreach ($reactions as $pid => $rtype) {
                if ($rtype === 'like') $likedIds[] = $pid;
            }
            rsort($likedIds);
            $total = count($likedIds);
            $offset = ($page - 1) * $limit;
            $pageIds = array_slice($likedIds, $offset, $limit);
            foreach ($pageIds as $pid) {
                $p = get_post_detail($pid, true);
                if ($p) $posts[] = $p;
            }
        } elseif ($type === 'following') {
            require_login();
            $followingIds = get_user_followings($uid);
            $total = count($followingIds);
            $offset = ($page - 1) * $limit;
            $pageIds = array_slice($followingIds, $offset, $limit);
            foreach ($pageIds as $fuid) {
                $fp = get_user_profile($fuid);
                if ($fp) {
                    unset($fp['password']);
                    $fp['avatar'] = $fp['avatar'] ?? '';
                    $posts[] = $fp;
                }
            }
        }
        
        $currentUid = get_current_uid();
        if ($type !== 'following') {
            foreach ($posts as &$p) {
                attach_user_status($p, $currentUid);
            }
            unset($p);
        }
        
        success(['posts' => $posts, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        break;

    case 'follow':
        require_login();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $uid = get_current_uid();
        $followeeUid = intval($_POST['followeeUid'] ?? 0);
        
        if ($uid == $followeeUid) error('不能关注自己');
        $target = get_user_profile($followeeUid);
        if (!$target) error('用户不存在', 404);
        
        $followings = get_user_followings($uid);
        $followers = get_user_followers($followeeUid);
        
        if (in_array($followeeUid, $followings)) {
            $followings = array_diff($followings, [$followeeUid]);
            $followers = array_diff($followers, [$uid]);
            $isFollowing = false;
        } else {
            $followings[] = $followeeUid;
            $followers[] = $uid;
            $isFollowing = true;
        }
        
        update_user_field($uid, 'followings', array_values($followings));
        update_user_field($followeeUid, 'followers', array_values($followers));
        
        success(['is_following' => $isFollowing]);
        break;

    case 'getCheckinCalendar':
        $uid = intval($_GET['uid'] ?? get_current_uid());
        $profile = get_user_profile($uid);
        if (!$profile) error('用户不存在', 404);
        
        $dates = get_user_checkin_dates($uid);
        $continuous = $profile['continuous_days'] ?? 0;
        
        success(['dates' => $dates, 'continuous_days' => $continuous]);
        break;

    case 'browseHistory':
        require_login();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $uid = get_current_uid();
        $postId = $_POST['postId'] ?? '';
        record_browse_history($uid, $postId);
        success();
        break;

    case 'getBrowseHistory':
        require_login();
        $uid = get_current_uid();
        $history = get_user_browse_history($uid);
        $posts = [];
        foreach ($history as $pid) {
            $p = get_post_detail($pid, true);
            if ($p) $posts[] = $p;
        }
        foreach ($posts as &$p) {
            attach_user_status($p, $uid);
        }
        unset($p);
        success(['posts' => $posts]);
        break;

    case 'adminUsers':
        require_admin();
        
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $users = [];
            $files = glob(USERS_DIR . '/*.json');
            foreach ($files as $f) {
                $uid = intval(basename($f, '.json'));
                $profile = get_user_profile($uid);
                if ($profile) {
                    unset($profile['password']);
                    $users[] = $profile;
                }
            }
            usort($users, function($a, $b) {
                return ($a['uid'] ?? 0) <=> ($b['uid'] ?? 0);
            });
            success(['users' => $users]);
        } else {
            $targetUid = intval($_POST['uid'] ?? 0);
            $adminAction = $_POST['action'] ?? '';
            
            $targetProfile = get_user_profile($targetUid);
            if (!$targetProfile) error('用户不存在', 404);
            
            if ($adminAction === 'delete') {
                $postIds = get_user_posts($targetUid);
                foreach ($postIds as $pid) {
                    delete_post($pid);
                }
                delete_email_mapping($targetProfile['email']);
                $userFile = get_user_file($targetUid);
                if (file_exists($userFile)) unlink($userFile);
                success();
            } elseif ($adminAction === 'setRole') {
                $newRole = $_POST['role'] ?? 'user';
                if (!in_array($newRole, ['admin', 'user'])) error('无效角色');
                update_user_profile_field($targetUid, 'role', $newRole);
                success();
            } elseif ($adminAction === 'resetPassword') {
                $newPass = $_POST['password'] ?? '';
                if (strlen($newPass) < 6) error('密码至少6位');
                update_user_profile_field($targetUid, 'password', password_hash($newPass, PASSWORD_DEFAULT));
                success();
            } else {
                error('未知操作');
            }
        }
        break;

    case 'adminPosts':
        require_admin();
        
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $posts = [];
            $allIds = get_all_post_ids();
            foreach ($allIds as $pid) {
                $p = get_post($pid);
                if ($p) {
                    $p['author_username'] = get_user_name($p['uid']);
                    $reportCount = 0;
                    $files = glob(REPORTS_DIR . '/*.json');
                    foreach ($files as $f) {
                        $r = json_read($f, null);
                        if ($r && $r['postId'] === $pid && $r['status'] === 'pending') {
                            $reportCount++;
                        }
                    }
                    $p['report_count'] = $reportCount;
                    $posts[] = $p;
                }
            }
            usort($posts, function($a, $b) {
                return strtotime($b['created_at']) <=> strtotime($a['created_at']);
            });
            success(['posts' => $posts]);
        } else {
            $postId = $_POST['postId'] ?? '';
            $adminAction = $_POST['action'] ?? '';
            
            if ($adminAction === 'delete') {
                if (delete_post($postId)) {
                    success();
                } else {
                    error('删除失败');
                }
            } else {
                error('未知操作');
            }
        }
        break;

    case 'adminReports':
        require_admin();
        
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $reports = [];
            $files = glob(REPORTS_DIR . '/*.json');
            foreach ($files as $f) {
                $r = json_read($f, null);
                if ($r) {
                    $r['reporter_name'] = get_user_name($r['reporterUid']);
                    $post = get_post($r['postId']);
                    $r['post_title'] = $post['title'] ?? '已删除';
                    $r['postId'] = $r['postId'] ?? '';
                    $reports[] = $r;
                }
            }
            usort($reports, function($a, $b) {
                return strtotime($b['created_at']) <=> strtotime($a['created_at']);
            });
            success(['reports' => $reports]);
        } else {
            $reportId = $_POST['reportId'] ?? '';
            $adminAction = $_POST['action'] ?? '';
            
            $file = REPORTS_DIR . '/' . sanitize($reportId) . '.json';
            $report = json_read($file, null);
            if (!$report) error('举报记录不存在', 404);
            
            if ($adminAction === 'resolve') {
                $report['status'] = 'resolved';
            } elseif ($adminAction === 'dismiss') {
                $report['status'] = 'dismissed';
            } else {
                error('未知操作');
            }
            
            json_write($file, $report);
            success();
        }
        break;

    case 'adminSettings':
        require_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $settings = json_read(DATA_DIR . '/settings.json', [
            'site_title' => 'XX的食谱',
            'site_icon' => '',
            'allow_guest_browse' => true,
            'allow_register' => true,
            'theme_mode' => 'auto'
        ]);
        
        if (isset($_POST['site_title'])) {
            $settings['site_title'] = trim($_POST['site_title']);
        }
        
        if (isset($_POST['allow_guest_browse'])) {
            $settings['allow_guest_browse'] = $_POST['allow_guest_browse'] === 'true' || $_POST['allow_guest_browse'] === '1';
        }
        
        if (isset($_POST['allow_register'])) {
            $settings['allow_register'] = $_POST['allow_register'] === 'true' || $_POST['allow_register'] === '1';
        }
        
        if (isset($_POST['theme_mode'])) {
            $themeMode = $_POST['theme_mode'];
            if (in_array($themeMode, ['auto', 'light', 'dark'])) {
                $settings['theme_mode'] = $themeMode;
            }
        }
        
        if (!empty($_FILES['site_icon']) && $_FILES['site_icon']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['site_icon'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'ico', 'svg', 'webp'])) {
                $iconDir = MEDIA_DIR . '/icons';
                if (!is_dir($iconDir)) mkdir($iconDir, 0777, true);
                $iconName = 'site_icon_' . time() . '.' . $ext;
                $iconPath = $iconDir . '/' . $iconName;
                if (move_uploaded_file($file['tmp_name'], $iconPath)) {
                    $settings['site_icon'] = 'data/media/icons/' . $iconName;
                }
            }
        } elseif (isset($_POST['site_icon_url'])) {
            $settings['site_icon'] = trim($_POST['site_icon_url']);
        }
        
        json_write(DATA_DIR . '/settings.json', $settings);
        success($settings);
        break;

    case 'updateAvatar':
        require_login();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $uid = get_current_uid();
        if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            error('请上传头像');
        }
        
        $file = $_FILES['avatar'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            error('仅支持图片格式');
        }
        
        $avatarDir = MEDIA_DIR . '/avatars';
        if (!is_dir($avatarDir)) mkdir($avatarDir, 0777, true);
        $avatarName = 'avatar_' . $uid . '_' . time() . '.' . $ext;
        $avatarPath = $avatarDir . '/' . $avatarName;
        
        if (move_uploaded_file($file['tmp_name'], $avatarPath)) {
            update_user_profile_field($uid, 'avatar', 'data/media/avatars/' . $avatarName);
            success(['avatar' => 'data/media/avatars/' . $avatarName]);
        } else {
            error('上传失败');
        }
        break;

    case 'updateProfile':
        require_login();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('请求方式错误');
        
        $uid = get_current_uid();
        
        if (isset($_POST['username'])) {
            $username = trim($_POST['username']);
            if (mb_strlen($username) >= 2 && mb_strlen($username) <= 20) {
                update_user_profile_field($uid, 'username', $username);
                $_SESSION['username'] = $username;
            }
        }
        if (isset($_POST['badge'])) {
            update_user_profile_field($uid, 'badge', trim($_POST['badge']));
        }
        
        success();
        break;

    default:
        error('未知接口: ' . $action);
}
