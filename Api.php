<?php
namespace TypechoPlugin\CloudflareR2;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Widget;

/**
 * 浏览器直传 R2 的 API 端点
 *
 * 由 Plugin::activate() 通过 Utils\Helper::addRoute 注册：
 * - GET/POST  /api/cloudflarer2/sign     为一次上传签发预签名 PUT 地址（不经服务器传文件体）
 * - GET/POST  /api/cloudflarer2/register 上传成功后登记附件记录到 contents 表
 *
 * 两个端点都必须由已登录的后台用户调用（guard() 校验）。
 *
 * @package CloudflareR2
 */
class Api extends Widget
{
    /**
     * Typecho\Widget 工厂要求存在（空实现即可）
     */
    public function execute()
    {
    }

    /**
     * 签发：根据文件名/大小生成对象键与预签名 PUT URL
     *
     * 请求体 JSON: { name, size, mime }
     * 成功返回:   { ok:true, key, url, name, size, mime }
     * 失败返回:   { ok:false, message }
     */
    public function sign()
    {
        $this->guard();

        $input = $this->input();

        // 调试：直接返回当前必填配置是否存在，便于排查
        if (!empty($input['debug']) && '1' === (string)$input['debug']) {
            $hasConfigHandle = false;
            try {
                require_once __DIR__ . '/Plugin.php';
                $hasConfigHandle = method_exists('TypechoPlugin\\CloudflareR2\\Plugin', 'configHandle');
            } catch (\Throwable $e) {
                $hasConfigHandle = false;
            }

            $this->jsonOut(array(
                'ok'    => true,
                'debug' => array(
                    'hasAccountId'     => (bool)FileHandler::optionValue('accountId'),
                    'hasBucket'        => (bool)FileHandler::optionValue('bucket'),
                    'hasAccessKeyId'   => (bool)FileHandler::optionValue('accessKeyId'),
                    'hasSecretAccessKey' => (bool)FileHandler::optionValue('secretAccessKey'),
                    'optionRows'       => $this->debugOptionRows(),
                    'lastSaveLog'      => $this->debugConfigLog(),
                    'pluginRow'        => $this->debugPluginRowInfo('plugin:CloudflareR2'),
                    'backupRow'        => $this->debugPluginRowInfo('_cfr2backup:CloudflareR2'),
                    'hasConfigHandle'  => $hasConfigHandle,
                    'pluginFileMtime'  => @filemtime(__DIR__ . '/Plugin.php'),
                    'apiFileMtime'     => @filemtime(__DIR__ . '/Api.php'),
                    'handlerFileMtime' => @filemtime(__DIR__ . '/FileHandler.php'),
                ),
            ));
        }

        $name  = isset($input['name']) ? (string)$input['name'] : '';
        $size  = isset($input['size']) ? max(0, (int)$input['size']) : 0;

        if ('' === trim($name) || $size <= 0) {
            $this->jsonOut(array('ok' => false, 'message' => '缺少文件名或文件大小'));
        }

        // R2 单对象最大 5GB（低于该值都可直传）
        if ($size > 5 * 1024 * 1024 * 1024) {
            $this->jsonOut(array('ok' => false, 'message' => '单文件不能超过 5GB'));
        }

        $meta = FileHandler::buildDirectMeta($name, $size);
        if (!$meta) {
            $this->jsonOut(array('ok' => false, 'message' => FileHandler::flushError() ?: '文件类型不允许'));
        }

        $client = FileHandler::getClient();
        if (!$client) {
            $this->jsonOut(array('ok' => false, 'message' => '插件尚未完成配置（缺少 R2 存储桶/密钥信息）'));
        }

        try {
            $url = $client->presignPutUrl($meta['key'], $meta['mime'], 900);
        } catch (\Throwable $e) {
            $this->log('sign: ' . $e->getMessage());
            $this->jsonOut(array('ok' => false, 'message' => '签发预签名地址失败：' . $e->getMessage()));
        }

        $this->jsonOut(array(
            'ok'   => true,
            'key'  => $meta['key'],
            'url'  => $url,
            'name' => $meta['name'],
            'size' => $meta['size'],
            'mime' => $meta['mime'],
        ));
    }

    /**
     * 登记：浏览器直传 R2 成功后写入附件记录
     *
     * 请求体 JSON: { key, name, size, mime, cid }
     * 成功返回: Typecho 附件数组 [url, {cid,title,type,size,bytes,isImage,url,permalink}]
     * 失败返回: { ok:false, message }
     */
    public function register()
    {
        $this->guard();

        $input = $this->input();

        $key  = isset($input['key']) ? ltrim((string)$input['key'], '/') : '';
        $name = isset($input['name']) ? (string)$input['name'] : '';
        $size = isset($input['size']) ? max(0, (int)$input['size']) : 0;
        $mime = isset($input['mime']) ? trim((string)$input['mime']) : '';
        $cid  = isset($input['cid']) ? max(0, (int)$input['cid']) : 0;

        if ('' === $key || '' === trim($name) || $size <= 0) {
            $this->jsonOut(array('ok' => false, 'message' => '缺少必要参数'));
        }

        if (!preg_match('#^usr/uploads/[0-9]{4}/[0-9]{2}/[0-9a-z_]+\.[a-z0-9]+$#i', $key)) {
            $this->jsonOut(array('ok' => false, 'message' => '非法的对象键：' . $key));
        }

        $meta = FileHandler::directMetaByKey($key, $name, $size, $mime);
        if (!$meta) {
            $this->jsonOut(array('ok' => false, 'message' => FileHandler::flushError() ?: '文件类型不允许'));
        }

        $isImage = FileHandler::extIsImage($meta['type']);

        $db = \Typecho\Db::get();

        // 校验 cid 是否为已存在的文章（可选参数）
        $parent = 0;
        if ($cid > 0) {
            try {
                $row = $db->fetchRow(
                    $db->select('cid')->from('table.contents')
                        ->where('cid = ? AND type <> ?', $cid, 'attachment')
                        ->limit(1)
                );
            } catch (\Throwable $e) {
                $row = null;
                $this->log('register.parent: ' . $e->getMessage());
            }

            if (!empty($row)) {
                $parent = $cid;
            }
        }

        // 补齐当前登录作者 ID：部分 Typecho 库表在严格模式下 authorId 列无默认值，
        // 不显式写入会导致 insert 报「字段没有默认值」而失败
        $uid = 0;
        try {
            $user = \Widget\User::alloc();
            if ($user) {
                if (method_exists($user, 'getUid')) {
                    $uid = (int)$user->getUid();
                } elseif (isset($user->uid)) {
                    $uid = (int)$user->uid;
                }
            }
        } catch (\Throwable $e) {
            $uid = 0;
        }

        // contents.slug 有唯一索引，同名附件重复上传会撞 Duplicate entry。
        // 附件 URL 只取决于 R2 对象键（path），slug 不参与访问，直接追加随机串保证唯一；
        // title 保留原文件名，便于后台附件列表辨认。
        $uniqueSlug = $meta['name'] . '-' . substr(md5(uniqid('', true)), 0, 8);

        $struct = array(
            'title'        => $meta['name'],
            'slug'         => $uniqueSlug,
            'created'      => time(),
            'modified'     => time(),
            'type'         => 'attachment',
            'status'       => 'publish',
            'text'         => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'allowComment' => 1,
            'allowPing'    => 0,
            'allowFeed'    => 1,
            'parent'       => $parent,
            'authorId'     => $uid,
        );

        $insertError = '';
        try {
            $insertId = $db->query($db->insert('table.contents')->rows($struct));
        } catch (\Throwable $e) {
            $insertId    = 0;
            $insertError = (string)$e->getMessage();
            $this->log('register.insert: ' . $insertError);
        }

        if (!$insertId) {
            $this->jsonOut(array(
                'ok'      => false,
                'message' => '写入附件记录失败：' . ($insertError ?: '未知错误')
                    . '。若提示列不存在/字段过长等，请把该完整报错发给我；'
                    . '同时可查看服务器 PHP 错误日志中 [CloudflareR2] 开头的记录',
            ));
        }

        $url = FileHandler::attachmentHandle((object)array('attachment' => (object)$meta));

        $this->jsonOut(array(
            $url,
            array(
                'cid'       => (int)$insertId,
                'title'     => $meta['name'],
                'type'      => $meta['type'],
                'size'      => $meta['size'],
                'bytes'     => number_format(ceil($meta['size'] / 1024)) . ' Kb',
                'isImage'   => $isImage ? 1 : 0,
                'url'       => $url,
                'permalink' => $url,
            ),
        ));
    }

    /**
     * 一键配置存储桶 CORS：放行当前后台域名，使浏览器直传 R2 通过跨域预检
     *
     * 用法：登录后台后，浏览器打开 /api/cloudflarer2/cors 一次即可。
     * 自动收集当前站点域名（含 http/https 两种写法）写入 R2 桶的 CORS 规则。
     *
     * 注意：R2 API 令牌需具备桶「Admin」级别权限，否则 R2 会返回 403，
     * 此时请在 Cloudflare 后台为令牌授予桶管理权限，或改到 R2 → 存储桶 → 设置 → CORS 手动配置。
     */
    public function cors()
    {
        $this->guard();

        $client = FileHandler::getClient();
        if (!$client) {
            $this->jsonOut(array('ok' => false, 'message' => '插件尚未完成配置（缺少 R2 存储桶/密钥信息）'));
        }

        $origins = array();
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $origin = rtrim(trim((string)$_SERVER['HTTP_ORIGIN']), '/');
            if (preg_match('#^https?://[^/]+$#i', $origin)) {
                $origins[] = $origin;
            }
        }

        // 兜底：无论从哪个入口访问，都放行当前站点的 http/https 两种协议
        if (!empty($_SERVER['HTTP_HOST'])) {
            $host = preg_replace('/:\d+$/', '', (string)$_SERVER['HTTP_HOST']);
            if ('' !== $host) {
                $origins[] = 'https://' . $host;
                $origins[] = 'http://' . $host;
            }
        }
        $origins = array_values(array_unique($origins));

        if (!$origins) {
            $this->jsonOut(array('ok' => false, 'message' => '无法确定站点域名，请在浏览器中打开本地址以自动携带来源域名'));
        }

        try {
            $client->setBucketCors($origins);
        } catch (\Throwable $e) {
            $this->log('cors: ' . $e->getMessage());
            $this->jsonOut(array(
                'ok'      => false,
                'message' => '写入 R2 CORS 失败：' . $e->getMessage()
                    . '。若提示 AccessDenied，说明 R2 API 令牌缺少桶管理权限，'
                    . '请在 Cloudflare 后台给令牌勾选 Admin 权限（或改用 R2 控制台手动配置 CORS）。',
            ));
        }

        $this->jsonOut(array(
            'ok'      => true,
            'message' => '已写入 R2 存储桶 CORS，放行来源：' . implode('、', $origins)
                . '。可回到写文章页 Ctrl+F5 后重新上传测试。',
        ));
    }

    /**
     * 读取请求体（JSON 优先，兼容表单）
     *
     * @return array
     */
    protected function input()
    {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }
        }

        return array_merge($_GET, $_POST);
    }

    /**
     * 仅允许已登录且有上传权限的用户访问
     */
    protected function guard()
    {
        try {
            $user = \Widget\User::alloc();
        } catch (\Throwable $e) {
            $user = null;
        }

        $ok = false;
        if ($user && method_exists($user, 'hasLogin')) {
            if ($user->hasLogin()) {
                $ok = method_exists($user, 'pass') ? (bool)$user->pass('contributor', true) : true;
            }
        }

        if (!$ok) {
            @header('HTTP/1.1 403 Forbidden');
            $this->jsonOut(array(
                'ok'      => false,
                'message' => '未登录或无上传权限，请刷新后台页面重新登录后重试',
            ));
        }
    }

    /**
     * 输出 JSON 并终止
     *
     * @param mixed $data
     */
    protected function jsonOut($data)
    {
        if (!headers_sent()) {
            @header('Content-Type: application/json; charset=UTF-8');
            @header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            @header('Pragma: no-cache');
            @header('Expires: 0');
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * 调试辅助：列出 options 表中与 CloudflareR2 相关的所有行及其键名
     *
     * @return array
     */
    protected function debugOptionRows()
    {
        $rows = array();

        try {
            $db = \Typecho\Db::get();
            $found = $db->fetchAll(
                $db->select('name', 'value')->from('table.options')
                    ->where('name LIKE ?', '%CloudflareR2%')
            );

            foreach ((array)$found as $row) {
                $decoded = json_decode((string)$row['value'], true);
                if (!is_array($decoded)) {
                    $decoded = @unserialize((string)$row['value']);
                }

                $rows[] = array(
                    'name'   => (string)$row['name'],
                    'isJson' => is_array($decoded),
                    'keys'   => is_array($decoded) ? array_keys($decoded) : array(),
                );
            }
        } catch (\Throwable $e) {
            $rows[] = array('error' => $e->getMessage());
        }

        return $rows;
    }

    /**
     * 调试辅助：读取 configHandle 最后一次执行日志（不含密钥）
     *
     * @return array|null
     */
    protected function debugConfigLog()
    {
        try {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow(
                $db->select('value')->from('table.options')
                    ->where('name = ? AND user = 0', '_cfr2configlog:CloudflareR2')
                    ->limit(1)
            );
            if ($row && isset($row['value'])) {
                $decoded = json_decode((string)$row['value'], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
            return array('error' => $e->getMessage());
        }

        return null;
    }

    /**
     * 调试辅助：读取指定 options 行的原始内容（不泄露密钥），返回长度、md5 与
     * 各配置键是否有值的标记，用于核对「写入的内容」与「读到的内容」是否一致
     *
     * @param string $name
     * @return array|null
     */
    protected function debugPluginRowInfo($name)
    {
        try {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow(
                $db->select('value')->from('table.options')
                    ->where('name = ? AND user = 0', $name)
                    ->limit(1)
            );
            if (!$row || !isset($row['value'])) {
                return null;
            }

            $raw    = (string)$row['value'];
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = @unserialize($raw);
            }
            $decoded = is_array($decoded) ? $decoded : array();

            $flags = array();
            foreach ($decoded as $k => $v) {
                $flags[$k] = '' !== trim((string)$v);
            }

            return array(
                'exists' => true,
                'len'    => strlen($raw),
                'md5'    => md5($raw),
                'flags'  => $flags,
            );
        } catch (\Throwable $e) {
            return array('error' => $e->getMessage());
        }
    }

    /**
     * 写入服务器错误日志，便于对照浏览器端排查
     *
     * @param string $message
     */
    protected function log($message)
    {
        if (function_exists('error_log')) {
            @error_log('[CloudflareR2] Api: ' . $message);
        }
    }
}
