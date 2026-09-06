<?php
namespace TypechoPlugin\CloudflareR2 {

    use Typecho\Plugin\PluginInterface;
    use Typecho\Widget\Helper\Form;
    use Typecho\Widget\Helper\Form\Element\Text;
    if (!defined('__TYPECHO_ROOT_DIR__')) {
        exit;
    }

    require_once __DIR__ . '/CloudflareR2Client.php';
    require_once __DIR__ . '/FileHandler.php';

    /**
     * Cloudflare R2 附件上传插件
     *
     * 附件上传时直接写入 Cloudflare R2 对象存储，不再保存到服务器本地磁盘。
     *
     * @package CloudflareR2
     * @author  CloudflareR2
     * @version 1.0.0
     * @link    https://developers.cloudflare.com/r2/
     */
    class Plugin implements PluginInterface
    {
        /**
         * 激活插件：注册 Widget_Upload 的各处理钩子
         *
         * @return string
         */
        public static function activate()
        {
            if (!function_exists('curl_init')) {
                throw new \Typecho\Plugin\Exception('CloudflareR2 插件需要 PHP cURL 扩展，请先安装并启用 cURL');
            }

            $factory = \Typecho\Plugin::factory('Widget\\Upload');
            $factory->uploadHandle         = array(__NAMESPACE__ . '\\FileHandler', 'uploadHandle');
            $factory->modifyHandle         = array(__NAMESPACE__ . '\\FileHandler', 'modifyHandle');
            $factory->deleteHandle         = array(__NAMESPACE__ . '\\FileHandler', 'deleteHandle');
            $factory->attachmentHandle     = array(__NAMESPACE__ . '\\FileHandler', 'attachmentHandle');
            $factory->attachmentDataHandle = array(__NAMESPACE__ . '\\FileHandler', 'attachmentDataHandle');

            // 后台上传页面注入浏览器直传脚本（附件一律经预签名直传 R2，不再有服务器转发模式）
            try {
                \Typecho\Plugin::factory('admin/write-post.php')
                    ->bottom = array(__NAMESPACE__ . '\\Plugin', 'adminUploadScript');
            } catch (\Throwable $e) {
                // 低版本后台无该钩子时忽略，直传脚本不注入（服务端 R2 落库逻辑仍生效）
            }

            // 注册直传 API 路由：签发预签名 / 登记附件
            if (class_exists('Utils\\Helper')) {
                try {
                    \Utils\Helper::addRoute(
                        'cloudflarer2-sign',
                        '/api/cloudflarer2/sign',
                        __NAMESPACE__ . '\\Api',
                        'sign'
                    );
                    \Utils\Helper::addRoute(
                        'cloudflarer2-register',
                        '/api/cloudflarer2/register',
                        __NAMESPACE__ . '\\Api',
                        'register'
                    );
                    \Utils\Helper::addRoute(
                        'cloudflarer2-cors',
                        '/api/cloudflarer2/cors',
                        __NAMESPACE__ . '\\Api',
                        'cors'
                    );
                } catch (\Throwable $e) {
                    // 路由注册失败不阻断激活
                }
            }

            return '插件已激活，请填写 Cloudflare R2 的账户与存储桶信息；后台上传将统一走浏览器直传 R2';
        }

        /**
         * 禁用插件
         *
         * @return string
         */
        public static function deactivate()
        {
            if (class_exists('Utils\\Helper')) {
                try {
                    \Utils\Helper::removeRoute('cloudflarer2-sign');
                    \Utils\Helper::removeRoute('cloudflarer2-register');
                    \Utils\Helper::removeRoute('cloudflarer2-cors');
                    } catch (\Throwable $e) {
                    // 忽略清理异常
                }
            }

            // Typecho 停用时会删除 plugin:CloudflareR2 整行配置，先备份以便启用时还原
            self::backupOptionRow();

            return '插件已被禁用，附件将恢复为上传到服务器本地';
        }

        /**
         * 插件配置面板
         *
         * @param Form $form
         */
        public static function config(Form $form)
        {
            // 自愈：清掉旧版本遗留、表单中已不存在的配置键（如旧“模式选择”的 mode 等），
            // 否则 Typecho 回填默认值时会因找不到对应输入框而报 500。
            FileHandler::pruneStaleConfig();

            $accountId = new Text(
                'accountId',
                null,
                FileHandler::optionValue('accountId'),
                'Account ID',
                'Cloudflare 后台 R2 概览页中形如 <code>0f1a2b...c9d</code> 的账户 ID（必填）'
            );
            $form->addInput($accountId->addRule('required', '请填写 R2 Account ID'));

            $bucket = new Text(
                'bucket',
                null,
                FileHandler::optionValue('bucket'),
                '存储桶 Bucket',
                'R2 中已创建的存储桶名称，例如 <code>my-blog</code>（必填）。'
            );
            $form->addInput($bucket->addRule('required', '请填写 Bucket 名称'));

            $accessKeyId = new Text(
                'accessKeyId',
                null,
                FileHandler::optionValue('accessKeyId'),
                'Access Key ID',
                'R2 API 令牌中的 Access Key ID（必填）。'
            );
            $form->addInput($accessKeyId->addRule('required', '请填写 Access Key ID'));

            $secretAccessKey = new Text(
                'secretAccessKey',
                null,
                FileHandler::optionValue('secretAccessKey'),
                'Secret Access Key',
                'R2 API 令牌中的 Secret Access Key（必填）。请为令牌授予「对象读、写」权限。'
            );
            $form->addInput($secretAccessKey->addRule('required', '请填写 Secret Access Key'));

            $endpoint = new Text(
                'endpoint',
                null,
                FileHandler::optionValue('endpoint'),
                'Endpoint（可选）',
                'S3 兼容端点。留空则自动按 <code>https://&lt;Account ID&gt;.r2.cloudflarestorage.com</code> 生成。'
            );
            $form->addInput($endpoint);

            $region = new Text(
                'region',
                null,
                FileHandler::optionValue('region', 'auto'),
                'Region（区域）',
                'R2 官方约定签名区域为 <code>auto</code>，无需修改。'
            );
            $form->addInput($region);

            $publicDomain = new Text(
                'publicDomain',
                null,
                FileHandler::optionValue('publicDomain'),
                '访问域名（可选）',
                '例如 <code>https://img.example.com</code> 或 R2 公共桶 <code>https://pub-xxxx.r2.dev</code>，'
                    . '不要带结尾斜杠。留空则使用 R2 默认端点地址。'
            );
            $form->addInput($publicDomain);

        }

        /**
         * 自定义配置保存（Typecho 支持插件实现 configHandle 接管保存）
         *
         * 框架默认把表单值整表覆盖写回，与已保存的其它字段合并时机不可控，
         * 容易丢失未随表单提交的键。这里改为「按表单键合并」：
         * 无论只改下拉框还是只改文本框，都不会清掉其它已保存的配置。
         *
         * 同时处理「停用 → 启用」场景：Typecho 停用时会删除 plugin:CloudflareR2
         * 整行配置，若此时存在 deactivate() 留下的备份行（_cfr2backup:CloudflareR2），
         * 则先还原备份，避免已填写的 R2 信息被清空。
         *
         * @param mixed $settings 表单提交的键值对
         * @param mixed $isInit   是否为激活时的初始化写入
         */
        /**
         * 记录 configHandle 执行日志到 options 表（仅用于排查，不含密钥值）
         *
         * @param array $data
         */
        protected static function logConfigAttempt(array $data)
        {
            try {
                $db = \Typecho\Db::get();
                $name = '_cfr2configlog:CloudflareR2';

                // 读取既有事件队列，追加本次事件（只保留最近 20 条）
                $events = array();
                $row = $db->fetchRow(
                    $db->select('value')->from('table.options')
                        ->where('name = ? AND user = 0', $name)
                );
                if ($row) {
                    $decoded = json_decode((string)$row['value'], true);
                    if (is_array($decoded)) {
                        $events = $decoded;
                    }
                }
                $events[] = array_merge($data, array('ts' => date('c')));
                $events = array_slice($events, -20);

                $value = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $db->query($db->update('table.options')
                    ->rows(array('value' => $value))
                    ->where('name = ? AND user = 0', $name));
            } catch (\Throwable $e) {
                // 日志写入失败不阻断保存（首次插入或异常时忽略）
            }
        }

        public static function configHandle($settings, $isInit = false)
        {
            $main   = 'plugin:CloudflareR2';
            $backup = '_cfr2backup:CloudflareR2';

            try {
                $db = \Typecho\Db::get();
                $settings = is_array($settings) ? $settings : array();

                $checkKeys = array('accountId', 'bucket', 'accessKeyId', 'secretAccessKey');
                $filled = array();
                foreach ($checkKeys as $k) {
                    $filled[$k] = isset($settings[$k]) && '' !== trim((string)$settings[$k]);
                }

                self::logConfigAttempt(array(
                    'event'  => 'called',
                    'isInit' => (bool)$isInit,
                    'keys'   => array_keys($settings),
                    'filled' => $filled,
                ));

                // 激活初始化：无论主配置是否存在都不得写入默认值。
                // 否则框架会把 config() 表单里的非空默认项（如 region=auto）
                // 当成"用户设置"整表覆盖写回，冲掉真实配置。
                if ($isInit) {
                    $mainRow = $db->fetchRow(
                        $db->select('name')->from('table.options')
                            ->where('name = ? AND user = 0', $main)
                    );
                    if ($mainRow) {
                        self::logConfigAttempt(array('event' => 'init_main_exists_keep'));
                        return;
                    }

                    // 主配置不存在：优先还原停用前备份
                    $bak = $db->fetchRow(
                        $db->select('value')->from('table.options')
                            ->where('name = ? AND user = 0', $backup)
                    );
                    if ($bak) {
                        $db->query($db->insert('table.options')->rows(array(
                            'name'  => $main,
                            'user'  => 0,
                            'value' => $bak['value'],
                        )));
                        $db->query($db->delete('table.options')
                            ->where('name = ? AND user = 0', $backup));
                        self::logConfigAttempt(array('event' => 'init_restored_backup'));
                        return;
                    }

                    // 既无主配置也无备份：全新启用，不写入任何默认值，等用户在设置页手动保存
                    self::logConfigAttempt(array('event' => 'init_no_main_no_backup_skip'));
                    return;
                }

                // 读出已有配置并仅按表单提交的键合并
                $row = $db->fetchRow(
                    $db->select('value')->from('table.options')
                        ->where('name = ? AND user = 0', $main)
                );
                $config = array();
                if ($row) {
                    $decoded = json_decode((string)$row['value'], true);
                    if (!is_array($decoded)) {
                        $decoded = @unserialize((string)$row['value']);
                    }
                    $config = is_array($decoded) ? $decoded : array();
                }
                foreach ($settings as $key => $value) {
                    $config[$key] = (string)$value;
                }

                // 只保留当前配置面板支持的字段，避免表单变更后残留过期键
                // （否则 Typecho 回填默认值时会在表单中找不到对应输入框而报 500）
                $config = array_intersect_key($config, array_flip(FileHandler::configFields()));

                // 防呆：合并后仍是全空（没有任何一项有值）时不要写库，
                // 避免「停用→启用」时被框架用全空默认值把已有配置覆盖掉
                $hasStoredValue = false;
                foreach ($config as $v) {
                    if ('' !== trim((string)$v)) {
                        $hasStoredValue = true;
                        break;
                    }
                }
                $hasSubmittedValue = false;
                foreach ($settings as $v) {
                    if ('' !== trim((string)$v)) {
                        $hasSubmittedValue = true;
                        break;
                    }
                }
                if (!$hasStoredValue && !$hasSubmittedValue) {
                    self::logConfigAttempt(array('event' => 'skipped_all_empty'));
                    return;
                }

                $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $mergeFlags = array();
                foreach ($config as $k => $v) {
                    $mergeFlags[$k] = '' !== trim((string)$v);
                }

                self::logConfigAttempt(array(
                    'event'      => 'preWrite',
                    'rowExists'  => (bool)$row,
                    'configKeys' => array_keys($config),
                    'mergeFlags' => $mergeFlags,
                    'valueMd5'   => md5($encoded),
                ));

                if ($row) {
                    $db->query($db->update('table.options')
                        ->rows(array('value' => $encoded))
                        ->where('name = ? AND user = 0', $main));
                } else {
                    $db->query($db->insert('table.options')->rows(array(
                        'name'  => $main,
                        'user'  => 0,
                        'value' => $encoded,
                    )));
                }

                self::logConfigAttempt(array(
                    'event'    => 'write_done',
                    'valueMd5' => md5($encoded),
                ));
            } catch (\Throwable $e) {
                self::logConfigAttempt(array('event' => 'exception', 'message' => $e->getMessage()));
                if (function_exists('error_log')) {
                    @error_log('[CloudflareR2] configHandle exception: ' . $e->getMessage());
                }
            }
        }

        /**
         * 停用前把主配置备份到独立行，避免 Typecho 删除配置后丢失
         */
        protected static function backupOptionRow()
        {
            try {
                $db = \Typecho\Db::get();
                $row = $db->fetchRow(
                    $db->select()->from('table.options')
                        ->where('name = ? AND user = 0', 'plugin:CloudflareR2')
                );
                if ($row) {
                    $bak = $db->fetchRow(
                        $db->select('name')->from('table.options')
                            ->where('name = ? AND user = 0', '_cfr2backup:CloudflareR2')
                    );
                    if ($bak) {
                        $db->query($db->update('table.options')
                            ->rows(array('value' => $row['value']))
                            ->where('name = ? AND user = 0', '_cfr2backup:CloudflareR2'));
                    } else {
                        $db->query($db->insert('table.options')->rows(array(
                            'name'  => '_cfr2backup:CloudflareR2',
                            'user'  => 0,
                            'value' => $row['value'],
                        )));
                    }
                }
            } catch (\Throwable $e) {
                // 备份失败不阻断停用
            }
        }

        /**
         * 在 Typecho 后台“写文章”页面底部注入浏览器直传脚本
         *
         * 挂在 admin/write-post.php 的 bottom 钩子上，此时官方 upload 脚本已加载完毕，
         * 覆盖其中的 Typecho.uploadFile 即可接管「点击选图、拖拽上传」全部入口。
         *
         * @param mixed $post
         */
        public static function adminUploadScript($post = null)
        {
            $file = __DIR__ . '/direct-upload.js';
            if (!is_file($file)) {
                return;
            }

            $script = trim(file_get_contents($file));
            if ('' === $script) {
                return;
            }

            echo '<script type="text/javascript">' . "\n"
                . "/* CloudflareR2 direct upload */\n"
                . $script . "\n"
                . "</script>\n";
        }

        /**
         * 个人用户配置面板（本插件无需）
         *
         * @param Form $form
         */
        public static function personalConfig(Form $form)
        {
        }
    }

    // 兼容部分引擎按“命名空间\插件名\插件名Plugin”寻类的约定
    class CloudflareR2Plugin extends Plugin
    {
    }
}

namespace {

    if (!defined('__TYPECHO_ROOT_DIR__')) {
        exit;
    }

    // 兼容以“插件名_Plugin”方式寻类的旧版 Typecho 插件引擎
    if (!class_exists('CloudflareR2_Plugin', false)) {
        class CloudflareR2_Plugin extends \TypechoPlugin\CloudflareR2\Plugin
        {
        }
    }
}
