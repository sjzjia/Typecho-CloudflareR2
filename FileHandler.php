<?php
namespace TypechoPlugin\CloudflareR2;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Typecho 附件上传处理器
 *
 * 将 Typecho 的 Widget_Upload 各处理函数替换为「上传到 Cloudflare R2，
 * 不写入服务器本地磁盘」的实现，并接管附件 URL 的生成。
 *
 * @package CloudflareR2
 */
class FileHandler
{
    /** @var bool|array 已解析的插件配置缓存 */
    private static $config = false;

    /** @var string 最近一次错误信息 */
    private static $lastError = '';

    /** @var array 插件配置字段默认值 */
    private static $defaults = array(
        'accountId'       => '',
        'bucket'          => '',
        'accessKeyId'     => '',
        'secretAccessKey' => '',
        'endpoint'        => '',
        'region'          => 'auto',
        'publicDomain'    => '',
    );

    /**
     * 获取插件配置（读取 typecho options 表中的 plugin:CloudflareR2）
     *
     * @return array
     */
    public static function getConfig()
    {
        if (false === self::$config) {
            $plugin = null;

            // 首选：直接读 options 表（与 configHandle 写入同源，最可靠）。
            // 注意：/api/cloudflarer2/* 等由 Utils\Helper 注册的路由上下文中，
            // Widget\Options 组件可能不加载 plugin:* 配置行，导致读到全空默认值，
            // 因此必须先走数据库直读，组件读取只作兜底。
            try {
                $db = \Typecho\Db::get();
                $row = $db->fetchRow(
                    $db->select('value')->from('table.options')
                        ->where('name = ? AND user = 0', 'plugin:CloudflareR2')
                        ->limit(1)
                );
                if ($row && isset($row['value'])) {
                    $decoded = json_decode((string)$row['value'], true);
                    if (!is_array($decoded)) {
                        $decoded = @unserialize((string)$row['value']);
                    }
                    if (is_array($decoded) && !empty($decoded)) {
                        $plugin = $decoded;
                    }
                }
            } catch (\Throwable $e) {
                $plugin = null;
            }

            // 兜底：Typecho 1.2.x+ 命名空间版本的 Options
            if (null === $plugin && class_exists('Widget\\Options')) {
                try {
                    $options = \Widget\Options::alloc();
                    if ($options && method_exists($options, 'plugin')) {
                        $candidate = $options->plugin('CloudflareR2');
                        if (is_array($candidate) && !empty($candidate)) {
                            $plugin = $candidate;
                        }
                    }
                } catch (\Throwable $e) {
                    $plugin = null;
                }
            }

            // 兜底 2：通过 Typecho\Helper::options() 读取
            if (null === $plugin && class_exists('Typecho\\Helper')) {
                try {
                    $helper = \Typecho\Helper::options();
                    if ($helper && method_exists($helper, 'plugin')) {
                        $candidate = $helper->plugin('CloudflareR2');
                        if (is_array($candidate) && !empty($candidate)) {
                            $plugin = $candidate;
                        }
                    }
                } catch (\Throwable $e) {
                    $plugin = null;
                }
            }

            $config = self::$defaults;
            if (!empty($plugin) && is_array($plugin)) {
                foreach ($plugin as $key => $value) {
                    if (array_key_exists($key, $config)) {
                        $config[$key] = $value;
                    }
                }
            }

            self::$config = $config;
        }

        return self::$config;
    }

    /**
     * 读取单个配置项
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    public static function optionValue($name, $default = '')
    {
        $config = self::getConfig();

        return isset($config[$name]) && '' !== (string)$config[$name]
            ? (string)$config[$name]
            : $default;
    }

    /**
     * 当前配置面板支持的字段名（写库白名单）
     *
     * 用于清理旧版本遗留、表单已不存在的配置键。若已保存配置中含有这些键，
     * Typecho 渲染设置页回填时会在表单中找不到对应输入框而报
     * “Call to a member function value() on null” 并 500。
     *
     * @return array
     */
    public static function configFields()
    {
        return array_keys(self::$defaults);
    }

    /**
     * 清理 options 表中已保存配置里的「过期字段」（自愈）
     *
     * Typecho 的 Widget\Plugins\Config::config() 会遍历已保存的每个配置键，
     * 到表单里找同名输入框回填默认值；如果某个键对应的输入框已不存在
     * （例如旧版本的“模式选择”被移除），$form->getInput($key) 返回 null，
     * 对该 null 调用 value() 即触发 500。
     *
     * 这里把库里配置的键收敛到当前表单字段白名单，并回写数据库。
     * 调用时机：插件设置页构建表单前（Plugin::config 入口）。
     *
     * 注意：Options 组件在同一次请求内已缓存，因此本方法写入后，
     * 当前请求可能仍报一次错，再次打开设置页即恢复。
     */
    public static function pruneStaleConfig()
    {
        try {
            $db  = \Typecho\Db::get();
            $row = $db->fetchRow(
                $db->select('value')->from('table.options')
                    ->where('name = ? AND user = 0', 'plugin:CloudflareR2')
                    ->limit(1)
            );
            if (!$row) {
                return;
            }

            $decoded = json_decode((string)$row['value'], true);
            if (!is_array($decoded)) {
                $decoded = @unserialize((string)$row['value']);
            }
            if (!is_array($decoded) || empty($decoded)) {
                return;
            }

            $cleaned = array_intersect_key($decoded, array_flip(self::configFields()));
            if ($cleaned !== $decoded) {
                $db->query($db->update('table.options')
                    ->rows(array('value' => json_encode(
                        $cleaned,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )))
                    ->where('name = ? AND user = 0', 'plugin:CloudflareR2'));
            }
        } catch (\Throwable $e) {
            // 清理失败不阻断其余流程
        }
    }

    /**
     * 最近一次错误
     *
     * @return string
     */
    public static function lastError()
    {
        return self::$lastError;
    }

    /**
     * 获取最近一次错误并清空
     *
     * @return string
     */
    public static function flushError()
    {
        $error = self::$lastError;
        self::$lastError = '';

        return $error;
    }

    /**
     * 将插件运行错误写入 PHP 错误日志，便于排查上传失败原因
     *
     * @param string $message
     */
    protected static function logError($message)
    {
        if (function_exists('error_log')) {
            @error_log('[CloudflareR2] ' . $message);
        }
    }

    /**
     * 根据配置构造客户端
     *
     * @return CloudflareR2Client|null
     */
    protected static function client()
    {
        $accountId = self::optionValue('accountId');
        $bucket    = self::optionValue('bucket');
        $accessKey = self::optionValue('accessKeyId');
        $secretKey = self::optionValue('secretAccessKey');

        if ('' === $bucket || '' === $accessKey || '' === $secretKey) {
            return null;
        }

        $endpoint = self::optionValue('endpoint');
        if ('' === $endpoint) {
            if ('' === $accountId) {
                return null;
            }
            $endpoint = 'https://' . $accountId . '.r2.cloudflarestorage.com';
        }

        return new CloudflareR2Client(
            $endpoint,
            $bucket,
            $accessKey,
            $secretKey,
            self::optionValue('region', 'auto')
        );
    }

    /**
     * 构造客户端（公开别名，供 Api 等外部类使用）
     *
     * @return CloudflareR2Client|null
     */
    public static function getClient()
    {
        return self::client();
    }

    /**
     * 校验扩展名是否在允许范围内（公开别名）
     *
     * @param string $ext
     * @return bool
     */
    public static function checkExtAllowed($ext)
    {
        return self::checkAllowed(strtolower(ltrim((string)$ext, '.')));
    }

    /**
     * 判断扩展名是否为常见图片格式
     *
     * @param string $ext
     * @return bool
     */
    public static function extIsImage($ext)
    {
        return in_array(
            strtolower(ltrim((string)$ext, '.')),
            array('jpg', 'jpeg', 'gif', 'png', 'bmp', 'webp', 'avif', 'svg', 'ico', 'tiff'),
            true
        );
    }

    /**
     * 为“浏览器直传 R2”生成新的对象键与元数据（不接触服务器磁盘）
     *
     * 路径规则与 Typecho 默认结构保持一致：/usr/uploads/年/月/随机名.ext
     *
     * @param string $name 原始文件名
     * @param int    $size 文件字节数
     * @return array|null 键：name/key/path/size/type/mime；失败返回 null
     */
    public static function buildDirectMeta($name, $size)
    {
        self::$lastError = '';
        $name = (string)$name;

        if ('' === trim($name)) {
            self::$lastError = '文件名称为空';

            return null;
        }

        $ext = self::safeExtension($name);
        if ('' === $ext || !self::checkAllowed($ext)) {
            self::$lastError = '不允许的附件类型: ' . ($ext ? $ext : 'unknown');

            return null;
        }

        $size = max(1, (int)$size);

        list($year, $month) = self::uploadDirParts();
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path     = self::uploadDir() . '/' . $year . '/' . $month . '/' . $fileName;

        return array(
            'name' => $name,
            'key'  => ltrim($path, '/'),
            'path' => $path,
            'size' => $size,
            'type' => $ext,
            'mime' => self::detectMime($name, null),
        );
    }

    /**
     * 由浏览器回传的对象键重建入库元数据（登记阶段使用）
     *
     * @param string $key  对象键，如 usr/uploads/2026/09/1.jpg
     * @param string $name 原始文件名
     * @param int    $size 文件字节数
     * @param string $mime Content-Type
     * @return array|null
     */
    public static function directMetaByKey($key, $name, $size, $mime = '')
    {
        self::$lastError = '';
        $key = ltrim((string)$key, '/');

        if (!preg_match('#^usr/uploads/[0-9]{4}/[0-9]{2}/[0-9a-z_]+\.[a-z0-9]+$#i', $key)) {
            self::$lastError = '对象键格式不合法';

            return null;
        }

        $dot = strrpos($key, '.');
        $ext = false === $dot ? '' : strtolower(substr($key, $dot + 1));
        if ('' === $ext || !self::checkAllowed($ext)) {
            self::$lastError = '不允许的附件类型: ' . ($ext ? $ext : 'unknown');

            return null;
        }

        $clean = str_replace(array('"', '<', '>'), '', (string)$name);
        if ('' === trim($clean)) {
            $clean = basename($key);
        }

        if ('' === trim((string)$mime)) {
            $mime = 'application/octet-stream';
        }

        return array(
            'name' => $clean,
            'path' => '/' . $key,
            'size' => max(1, (int)$size),
            'type' => $ext,
            'mime' => trim((string)$mime),
        );
    }

    /**
     * 兼容 Typecho 各版本读取全局配置对象的便捷方法
     *
     * @return object|null
     */
    protected static function globalOptions()
    {
        if (class_exists('Widget\\Options')) {
            try {
                return \Widget\Options::alloc();
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (class_exists('Typecho\\Helper')) {
            try {
                return \Typecho\Helper::options();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * 上传处理函数（覆盖 Widget_Upload::uploadHandle）
     *
     * @param array $file $_FILES 中的单个文件
     * @return array|false
     */
    public static function uploadHandle($file)
    {
        self::$lastError = '';

        if (!is_array($file) || empty($file['name'])) {
            return false;
        }

        // 清理文件名，并取得安全扩展名（与 Typecho 默认逻辑一致）
        $name = (string)$file['name'];
        $ext  = self::safeExtension($name);

        if ('' === $ext || !self::checkAllowed($ext)) {
            self::$lastError = '不允许的附件类型: ' . ($ext ? $ext : 'unknown');

            return false;
        }

        $filePath = null;
        $body     = null;

        if (!empty($file['tmp_name']) && is_file($file['tmp_name'])) {
            if (!empty($file['error'])) {
                return false;
            }
            $filePath = $file['tmp_name'];
            $size     = isset($file['size']) ? (int)$file['size'] : (int)filesize($filePath);
        } elseif (array_key_exists('bytes', $file)) {
            $body = (string)$file['bytes'];
            $size = strlen($body);
        } elseif (array_key_exists('bits', $file)) {
            $body = (string)$file['bits'];
            $size = strlen($body);
        } else {
            return false;
        }

        // 目录与文件名（保持 Typecho 原有 /usr/uploads/年/月/xxx.ext 结构）
        $dir       = self::uploadDir();
        list($year, $month) = self::uploadDirParts();
        $fileName  = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $relPath   = $dir . '/' . $year . '/' . $month . '/' . $fileName;

        $client = self::client();
        if (!$client) {
            self::$lastError = '插件尚未完成配置（缺少 R2 存储桶/密钥信息），请在插件设置中填写';

            return false;
        }

        $mime = self::detectMime($name, $filePath);

        try {
            // 直接上传到 R2，不写入服务器本地
            $client->putObject(ltrim($relPath, '/'), $body, $mime, $filePath);
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            self::logError('uploadHandle: ' . self::$lastError);

            return false;
        }

        return array(
            'name' => $name,
            'path' => $relPath,
            'size' => $size,
            'type' => $ext,
            'mime' => $mime,
        );
    }

    /**
     * 修改(覆盖)已存在的附件文件（覆盖 Widget_Upload::modifyHandle）
     *
     * @param array $content 旧附件内容
     * @param array $file    新文件
     * @return array|false
     */
    public static function modifyHandle($content, $file)
    {
        self::$lastError = '';

        $old = self::extractAttachment($content);
        if (!$old || empty($old['path'])) {
            self::$lastError = '无法识别原附件信息';

            return false;
        }

        if (!is_array($file) || empty($file['name'])) {
            return false;
        }

        $name = (string)$file['name'];
        $ext  = self::safeExtension($name);

        // 与 Typecho 默认行为一致：仅允许替换为相同扩展名的文件
        if ('' === $ext || strtolower($old['type']) !== $ext) {
            self::$lastError = '替换文件扩展名与原件不一致，已取消';

            return false;
        }

        $filePath = null;
        $body     = null;

        if (!empty($file['tmp_name']) && is_file($file['tmp_name'])) {
            if (!empty($file['error'])) {
                return false;
            }
            $filePath = $file['tmp_name'];
            $size     = isset($file['size']) ? (int)$file['size'] : (int)filesize($filePath);
        } elseif (array_key_exists('bytes', $file)) {
            $body = (string)$file['bytes'];
            $size = strlen($body);
        } elseif (array_key_exists('bits', $file)) {
            $body = (string)$file['bits'];
            $size = strlen($body);
        } else {
            return false;
        }

        $client = self::client();
        if (!$client) {
            self::$lastError = '插件尚未完成配置，请在插件设置中填写';

            return false;
        }

        $mime = self::detectMime($name, $filePath);

        try {
            // 直接覆盖 R2 上的同名对象
            $client->putObject(ltrim($old['path'], '/'), $body, $mime, $filePath);
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();

            return false;
        }

        return array(
            'name' => $old['name'],
            'path' => $old['path'],
            'size' => $size,
            'type' => $old['type'],
            'mime' => $mime,
        );
    }

    /**
     * 删除附件对应的 R2 对象（覆盖 Widget_Upload::deleteHandle）
     *
     * @param array $content 附件内容
     * @return bool
     */
    public static function deleteHandle($content)
    {
        self::$lastError = '';

        $old = self::extractAttachment($content);
        if (!$old || empty($old['path'])) {
            return true;
        }

        $client = self::client();
        if (!$client) {
            return true;
        }

        try {
            $client->deleteObject(ltrim($old['path'], '/'));

            return true;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();

            return false;
        }
    }

    /**
     * 生成附件的可访问 URL（覆盖 Widget_Upload::attachmentHandle）
     *
     * @param array $content 附件内容
     * @return string
     */
    public static function attachmentHandle($content)
    {
        $old = self::extractAttachment($content);
        if (!$old || empty($old['path'])) {
            return '';
        }

        $base = trim(self::optionValue('publicDomain'));
        if ('' === $base) {
            $client = self::client();
            if ($client) {
                $base = $client->publicBase();
            }
        }

        if ('' === $base) {
            $options = self::globalOptions();
            if ($options && isset($options->siteUrl)) {
                $base = rtrim((string)$options->siteUrl, '/');
            }
        }

        if ('' === $base) {
            return $old['path'];
        }

        $path = (string)$old['path'];
        if (0 !== strpos($path, '/')) {
            $path = '/' . $path;
        }

        return rtrim($base, '/') . $path;
    }

    /**
     * 获取附件文件原始数据（覆盖 Widget_Upload::attachmentDataHandle）
     *
     * @param array $content 附件内容
     * @return string
     */
    public static function attachmentDataHandle($content)
    {
        $old = self::extractAttachment($content);
        if (!$old || empty($old['path'])) {
            return '';
        }

        $client = self::client();
        if (!$client) {
            return '';
        }

        try {
            return (string)$client->getObject(ltrim($old['path'], '/'));
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();

            return '';
        }
    }

    /**
     * 从附件内容中提取 path/type/name 等信息（兼容 serialize / json 两种存储）
     *
     * @param array|object|null $content
     * @return array|null 包含 name/path/type/mime 键的数组，失败返回 null
     */
    protected static function extractAttachment($content)
    {
        $attachment = null;

        if (is_object($content) && isset($content->attachment)) {
            $attachment = $content->attachment;
        } elseif (is_array($content)) {
            if (isset($content['attachment']) && is_object($content['attachment'])) {
                $attachment = $content['attachment'];
            } elseif (!empty($content['text'])) {
                $decoded = @unserialize((string)$content['text']);
                if (!is_array($decoded)) {
                    $decoded = @json_decode((string)$content['text'], true);
                }
                if (is_array($decoded)) {
                    $attachment = (object)$decoded;
                }
            }
        }

        if (!$attachment || empty($attachment->path)) {
            return null;
        }

        return array(
            'name' => isset($attachment->name) ? (string)$attachment->name : '',
            'path' => (string)$attachment->path,
            'type' => isset($attachment->type) ? (string)$attachment->type : '',
            'mime' => isset($attachment->mime) ? (string)$attachment->mime : '',
        );
    }

    /**
     * 清洗文件名并返回安全的小写扩展名（与 Widget_Upload::getSafeName 逻辑一致）
     *
     * @param string $name 文件名，会被修改为清洗后的名称
     * @return string
     */
    protected static function safeExtension(&$name)
    {
        $name = str_replace(array('"', '<', '>'), '', (string)$name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower((string)$info['extension']) : '';
    }

    /**
     * 上传目录（优先使用常量 __TYPECHO_UPLOAD_DIR__）
     *
     * @return string 形如 /usr/uploads
     */
    protected static function uploadDir()
    {
        if (defined('__TYPECHO_UPLOAD_DIR__') && __TYPECHO_UPLOAD_DIR__) {
            return '/' . trim((string)__TYPECHO_UPLOAD_DIR__, '/');
        }

        return '/usr/uploads';
    }

    /**
     * 校验扩展名是否在 Typecho 允许范围内
     *
     * @param string $ext
     * @return bool
     */
    protected static function checkAllowed($ext)
    {
        $raw = '';
        $options = self::globalOptions();
        if ($options && isset($options->allowedAttachmentTypes)) {
            $raw = $options->allowedAttachmentTypes;
        }

        if (is_array($raw)) {
            $allowed = $raw;
        } else {
            $allowed = preg_split('/[\s,]+/', trim((string)$raw), -1, PREG_SPLIT_NO_EMPTY);
        }

        // 取不到配置时放行，避免误伤正常上传
        if (empty($allowed)) {
            return true;
        }

        return in_array('*', $allowed, true) || in_array($ext, $allowed, true);
    }

    /**
     * 按 Typecho 配置时区计算年/月目录段
     *
     * 注意：月份必须补零成两位数（09），与 Typecho 官方
     * /usr/uploads/年/月 结构与登记时的对象键校验保持一致，
     * 否则 9 月会产生 2026/9/ 与 2026/09/ 不一致的问题。
     *
     * @return array [年, 月]
     */
    protected static function uploadDirParts()
    {
        if (class_exists('Typecho\\Date')) {
            try {
                $date = new \Typecho\Date();
                if (isset($date->year, $date->month)) {
                    return array(
                        sprintf('%04d', (int)$date->year),
                        sprintf('%02d', (int)$date->month),
                    );
                }
            } catch (\Throwable $e) {
                // 忽略，退回 PHP 时区
            }
        }

        return array(date('Y'), date('m'));
    }

    /**
     * 探测文件 MIME
     *
     * @param string      $fileName 原文件名
     * @param string|null $filePath 本地临时文件（可空）
     * @return string
     */
    protected static function detectMime($fileName, $filePath = null)
    {
        if ($filePath && is_file($filePath) && function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = @finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if ($mime && 'application/octet-stream' !== $mime) {
                    return $mime;
                }
            }
        }

        $ext = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
        $map = array(
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'png'  => 'image/png',
            'bmp'  => 'image/bmp',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'tiff' => 'image/tiff',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'ogg'  => 'audio/ogg',
            'flac' => 'audio/flac',
            'aac'  => 'audio/aac',
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
            'mov'  => 'video/quicktime',
            'avi'  => 'video/x-msvideo',
            'mkv'  => 'video/x-matroska',
            'flv'  => 'video/x-flv',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip'  => 'application/zip',
            'rar'  => 'application/vnd.rar',
            '7z'   => 'application/x-7z-compressed',
            'tar'  => 'application/x-tar',
            'gz'   => 'application/gzip',
            'txt'  => 'text/plain',
            'md'   => 'text/markdown',
            'csv'  => 'text/csv',
            'xml'  => 'application/xml',
            'json' => 'application/json',
            'apk'  => 'application/vnd.android.package-archive',
            'exe'  => 'application/octet-stream',
        );

        return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
    }
}
