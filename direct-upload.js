/**
 * CloudflareR2 浏览器直传脚本（自动回填版）
 *
 * 挂在 admin/write-post.php 底部（此时官方 upload 脚本已加载），
 * 覆盖 Typecho.uploadFile，把「点击选图 / 拖拽上传」改造成三步：
 *
 *   1. 调插件 API 签发预签名 PUT 地址（只传文件名/大小，几 KB，不触发服务器体积限制）
 *   2. 浏览器直接 PUT 到 R2（请求体完全不经过源站 Nginx/PHP）
 *   3. 调插件 API 登记附件记录，返回与官方一致的结构
 *
 * 兼容性说明（重要）：
 * Typecho 新版后台把 fileUploadStart/fileUploadComplete/fileUploadError 定义在
 * $(document).ready 闭包内，并不是全局函数；同时官方 ready 回调还会在页面就绪时
 * 重新覆盖 Typecho.uploadFile。因此本脚本必须：
 *   - 在 DOMContentLoaded / load 后再次声明自己的接管函数，防止被官方覆盖；
 *   - 完成上传后不依赖那些“私有回调”，而是自己维护附件列表，
 *     并直接调用 Typecho.uploadComplete 让编辑器弹窗自动填好 R2 图片地址。
 *
 * 本文件每一步都会向浏览器控制台输出以 [CloudflareR2 direct] 为前缀的日志；
 * 失败时附带 HTTP 状态码、响应正文片段与堆栈，请把 Console 面板内容发给开发者排查。
 */
(function () {
    'use strict';

    /* ---------------- 控制台日志辅助 ---------------- */

    function dbg(msg, detail) {
        try {
            if (window.console && console.log) {
                console.log('[CloudflareR2 direct] ' + msg, detail === undefined ? '' : detail);
            }
        } catch (e) { /* 忽略 */ }
    }

    function err(msg, detail) {
        try {
            if (window.console && console.error) {
                console.error('[CloudflareR2 direct] ' + msg, detail === undefined ? '' : detail);
            }
        } catch (e) { /* 忽略 */ }
    }

    // 截断过长的响应正文，避免控制台刷屏
    function shorten(str, n) {
        str = String(str === null || str === undefined ? '' : str);
        if (str.length > n) {
            str = str.slice(0, n) + ' …(截断，原始长度 ' + str.length + ')';
        }
        return str;
    }

    /* ---------------- 注入点与 API 地址推导 ---------------- */

    var area = null;
    var actionUrl = '';
    var root = '';
    var token = '';
    var deleteUrl = '';

    function initEndpoints() {
        area = document.querySelector('.upload-area');
        if (!area) {
            return '未找到 .upload-area 上传区域';
        }

        actionUrl = area.getAttribute('data-url') || '';
        if (!actionUrl) {
            return '.upload-area 缺少 data-url 属性';
        }

        var qIdx = actionUrl.indexOf('?');
        var path = qIdx >= 0 ? actionUrl.slice(0, qIdx) : actionUrl;
        var marker = '/action/upload';
        var mIdx = path.indexOf(marker);
        if (mIdx < 0) {
            mIdx = path.lastIndexOf(marker);
        }
        if (mIdx < 0) {
            return '无法从上传地址中识别 /action/upload：' + actionUrl;
        }

        // root 即站点入口相对路径：''（伪静态）或 /index.php（未开伪静态）等
        root = path.slice(0, mIdx);

        // 删除附件沿用同一站点入口与 CSRF 令牌
        var deletePath = path.replace('/action/upload', '/action/contents-attachment-edit');
        deleteUrl = deletePath + (qIdx >= 0 ? actionUrl.slice(qIdx) : '');

        // 取出 Typecho 的 CSRF 令牌（官方安全参数 _）
        if (qIdx >= 0) {
            var qs = actionUrl.slice(qIdx + 1).split('&');
            for (var i = 0; i < qs.length; i++) {
                var kv = qs[i].split('=');
                if (kv[0] === '_') {
                    try {
                        token = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
                    } catch (e) {
                        token = kv[1] || '';
                    }
                }
            }
        }

        return null;
    }

    function apiUrl(name) {
        // Typecho 后台上传区 data-url 通常是带域名入口的绝对地址，例如
        //   https://example.com/index.php/action/upload?_=token
        // 此时 root（data-url 中 /action/upload 之前的片段）已含 scheme://host，
        // 不能再拼接 location.origin，否则会拼出重复域名导致 DNS 解析失败。
        var base;
        if (/^[a-z][a-z0-9+.\-]*:\/\//i.test(actionUrl)) {
            base = root;
        } else {
            base = location.origin + root;
        }
        var url = base + '/api/cloudflarer2/' + name;
        return token ? (url + '?_=' + encodeURIComponent(token)) : url;
    }

    function getCid() {
        var el = document.querySelector('input[name="cid"]');
        return el ? (el.value || '') : '';
    }

    /* ---------------- 新文章页：重置历史“未关联附件”的显示 ---------------- */

    var listResetDone = false;

    // 当前是否为尚未保存过的新文章（cid 为空 / 0）
    function isFreshPost() {
        var cid = getCid();
        return '' === cid || '0' === cid;
    }

    // Typecho 官方 file-upload.php 在新文章页会把全部“未关联(parent=0)”的历史附件
    // 服务端渲染进 #file-list（每条还带 attachment[] 隐藏域）。因此上个会话上传后
    // 未发布就关闭页面时，这些附件会留在库里，下次再开一篇新文章又被列出，
    // 甚至随新文章一起发布被误挂接。
    // 这里在页面加载时把它们从列表与表单中清掉，只保留“本页面上传”的附件。
    function resetUnattachedList() {
        if (listResetDone) {
            return;
        }
        listResetDone = true;

        if (!isFreshPost()) {
            return;
        }

        var list = document.getElementById('file-list');
        if (!list) {
            return;
        }

        if (list.children.length > 0) {
            list.innerHTML = '';
            dbg('新文章页：已清空历史“未关联附件”，附件列表重置为空', {});
            ownUpdateNumber();
        }
    }

    /* ---------------- 统一请求封装 ---------------- */

    // 始终先取正文文本，再尝试解析 JSON；成功与失败都返回 {status,ok,data,text,url,method}
    function rawFetch(method, url, bodyObj, headers) {
        var init = {
            method: method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: headers || {}
        };
        if (bodyObj !== undefined) {
            init.headers = headers || {};
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(bodyObj);
        }
        return fetch(url, init).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                if (text) {
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        data = null;
                    }
                }
                return { status: res.status, ok: res.ok, data: data, text: text, url: url, method: method };
            });
        });
    }

    // 把一次 API 响应包装成可读的 Error
    function apiError(label, res) {
        var msg = label + ' 失败：HTTP ' + res.status;
        var extra = '';
        if (res.data && typeof res.data === 'object') {
            if (res.data.ok === false && res.data.message) {
                extra = '服务端提示：' + res.data.message;
            } else {
                extra = '返回内容：' + shorten(JSON.stringify(res.data), 300);
            }
        } else if (res.data) {
            extra = '返回内容：' + shorten(res.data, 300);
        } else if (res.text) {
            extra = '响应正文：' + shorten(res.text, 300);
        }
        if (extra) {
            msg += '；' + extra;
        }
        return new Error(msg);
    }

    /* ---------------- 附件列表（自维护，兼容新版私有回调） ---------------- */

    // 新版 Typecho 的 fileUploadStart/fileUploadComplete/fileUploadError 是闭包私有函数，
    // 外部拿不到。这里在它们不是全局函数时，由脚本自己维护 #file-list。
    function ownFileStart(file) {
        if (!file || !file.id) {
            return;
        }
        // 若官方版本把这些函数暴露为全局，则沿用官方实现
        if (typeof fileUploadStart === 'function') {
            try {
                fileUploadStart(file);
            } catch (e) { /* 忽略 */ }
            return;
        }
        var list = document.getElementById('file-list');
        if (!list || document.getElementById(file.id)) {
            return;
        }
        var li = document.createElement('li');
        li.id = file.id;
        li.className = 'loading';
        li.textContent = (file.name || '');
        list.appendChild(li);
    }

    function ownUpdateNumber() {
        try {
            var $ = window.jQuery;
            if (!$) {
                return;
            }
            var $btn = $('#tab-files-btn'),
                $balloon = $('.balloon', $btn),
                count = $('#file-list li .insert').length;

            if (count > 0) {
                if (!$balloon.length) {
                    $btn.html($.trim($btn.html()) + ' ');
                    $balloon = $('<span class="balloon"></span>').appendTo($btn);
                }
                $balloon.html(count);
            } else if ($balloon.length > 0) {
                $balloon.remove();
            }
        } catch (e) { /* 忽略 */ }
    }

    // 上传失败时把列表项标红后移除
    function ownFileError(file, message) {
        if (typeof fileUploadError === 'function') {
            try {
                fileUploadError('network', file);
            } catch (e) { /* 忽略 */ }
            return;
        }
        if (!file || !file.id) {
            return;
        }
        var li = document.getElementById(file.id);
        if (!li) {
            return;
        }
        var $li = window.jQuery ? jQuery(li) : null;
        if ($li) {
            $li.removeClass('loading').text((file.name || '') + ' 上传失败');
            if ($li.effect) {
                try {
                    $li.effect('highlight', { color: '#FBC2C4' }, 2000, function () {
                        $li.remove();
                    });
                } catch (e) {
                    setTimeout(function () { $li.remove(); }, 2500);
                }
            } else {
                setTimeout(function () { $li.remove(); }, 2500);
            }
        }
    }

    // 直传成功后写入附件列表。old 官方全局回调存在时交给官方处理；
    // 否则按官方 DOM 结构自建条目（含 attachment[] 隐藏域，确保保存文章时附件能关联）。
    function ownFileComplete(file, meta) {
        if (typeof fileUploadComplete === 'function') {
            try {
                fileUploadComplete(file, meta);
            } catch (e) {
                err('调用官方 fileUploadComplete 失败', e);
            }
            return;
        }

        if (!file || !file.id) {
            return;
        }

        var li = document.getElementById(file.id);
        if (!li) {
            ownFileStart(file);
            li = document.getElementById(file.id);
        }
        if (!li) {
            return;
        }

        var $ = window.jQuery;
        var $li = $ ? $(li) : null;
        if (!$li) {
            return;
        }

        $li.removeClass('loading').empty()
            .data('cid', meta.cid)
            .data('url', meta.url)
            .data('image', meta.isImage);

        $('<input type="hidden" name="attachment[]" value="' + meta.cid + '" />').appendTo($li);

        var $insert = $('<a class="insert" target="_blank" href="###" title="点击插入文件"></a>')
            .text(meta.title);
        $insert.appendTo($li);
        $insert.click(function () {
            try {
                if (window.Typecho && typeof Typecho.insertFileToEditor === 'function') {
                    Typecho.insertFileToEditor($insert.text(), $li.data('url'), $li.data('image'));
                }
            } catch (e) {
                err('点击插入失败', e);
            }
            return false;
        });

        var $info = $('<div class="info"></div>').text(meta.bytes + ' ').appendTo($li);
        var $delete = $('<a class="delete" href="###" title="删除"><i class="i-delete"></i></a>')
            .appendTo($info);
        $delete.click(function () {
            if (window.confirm('确认要删除文件 ' + meta.title + ' 吗?')) {
                $.post(deleteUrl, { 'do': 'delete', 'cid': meta.cid }, function () {
                    $li.fadeOut(function () {
                        $li.remove();
                        ownUpdateNumber();
                    });
                });
            }
            return false;
        });

        if ($li.effect) {
            try {
                $li.effect('highlight', 1000);
            } catch (e) { /* 忽略 */ }
        }
        ownUpdateNumber();
    }

    /* ---------------- 队列与上传主流程 ---------------- */

    var queue = [];
    var running = false;

    // 最近一次加入队列的时间 / 是否需要为“本批第一个文件”自动弹插入框
    var lastAddAt = 0;
    var insertArmed = true;

    function next() {
        if (running) {
            return;
        }
        var file = queue.shift();
        if (!file) {
            return;
        }
        running = true;
        uploadOne(file);
    }

    function fail(file, e) {
        var detail = e && e.stack ? e.stack : String(e);
        err('上传流程失败', detail);
        try {
            window.alert('R2 直传失败：' + (e && e.message ? e.message : String(e)));
        } catch (ignore) { /* 忽略 */ }
        ownFileError(file);
        running = false;
        next();
    }

    function uploadOne(file) {
        var mime = (file && file.type) || '';
        dbg('① 开始处理文件', {
            name: (file && file.name) || '',
            size: (file && file.size) || 0,
            type: mime
        });

        ownFileStart(file);

        var payload = {
            name: (file && file.name) || '',
            size: (file && file.size) ? file.size : 0,
            mime: mime
        };

        if (!payload.name || !payload.size) {
            fail(file, new Error('无法读取文件信息（name=' + payload.name + ', size=' + payload.size + '）'));
            return;
        }

        // 第一步：向插件 API 申请预签名 PUT 地址
        var signUrl = apiUrl('sign');
        dbg('② 申请预签名地址（sign）', { url: signUrl, payload: payload });
        rawFetch('POST', signUrl, payload)
            .then(function (res) {
                if (!res.ok || !res.data || !res.data.ok || !res.data.url) {
                    throw apiError('申请上传地址(sign)', res);
                }
                dbg('③ 已取得预签名地址，准备浏览器直传 R2', {
                    key: res.data.key,
                    url: shorten(res.data.url, 120),
                    mime: res.data.mime
                });

                // 记住本次对象键与预签名地址，登记返回 url 为空时用于兜底推导公开地址
                lastSign = res.data;

                // 第二步：浏览器直接把文件 PUT 到 R2（跨域，凭据不携带）
                return fetch(res.data.url, {
                    method: 'PUT',
                    mode: 'cors',
                    credentials: 'omit',
                    headers: { 'Content-Type': res.data.mime || mime || 'application/octet-stream' },
                    body: file
                }).then(function (putRes) {
                    return putRes.text().then(function (text) {
                        if (!putRes.ok) {
                            err('④ R2 服务器拒绝了 PUT', {
                                status: putRes.status,
                                body: shorten(text, 500)
                            });
                            var hint = '；可能原因：R2 CORS 未放行当前后台域名、'
                                + '预签名 URL 过期（15 分钟）或密钥与桶不匹配';
                            throw new Error('R2 直传失败 HTTP ' + putRes.status
                                + (text ? '：' + shorten(text, 200) : '')
                                + hint);
                        }
                        dbg('④ R2 直传成功', { status: putRes.status });
                        return res.data;
                    });
                });
            })
            .then(function (signData) {
                // 第三步：登记附件记录
                var regPayload = {
                    key: signData.key,
                    name: signData.name,
                    size: signData.size,
                    mime: signData.mime || mime,
                    cid: getCid()
                };
                dbg('⑤ 登记附件记录（register）', { url: apiUrl('register'), payload: regPayload });
                return rawFetch('POST', apiUrl('register'), regPayload).then(function (res) {
                    if (!res.ok || !Array.isArray(res.data) || !res.data[1]) {
                        throw apiError('附件登记(register)', res);
                    }
                    return res.data[1];
                });
            })
            .then(function (meta) {
                // 登记接口一般已带公开地址；万一为空则用预签名 PUT 地址去掉签名参数后兜底
                var finalUrl = meta && meta.url ? meta.url : fallbackPublicUrl(lastSign && lastSign.url);
                if (meta) {
                    if (finalUrl) {
                        meta.url = finalUrl;
                        meta.permalink = finalUrl;
                    }
                }

                dbg('⑥ 上传全流程完成', meta
                    ? { cid: meta.cid, title: meta.title, url: meta.url }
                    : meta);

                finishUpload(file, meta);
                running = false;
                next();
            })
            .catch(function (e) {
                fail(file, e);
            });
    }

    // 预签名 PUT 地址形如 https://<endpoint>/<bucket>/<key>?X-Amz-...，
    // 去掉查询参数即可作为该对象的公开访问地址（桶为公开读时有效）
    function fallbackPublicUrl(presignUrl) {
        try {
            var u = new URL(presignUrl);
            u.search = '';
            u.hash = '';
            var s = u.toString();
            return s.replace(/\/+$/, '');
        } catch (e) {
            return '';
        }
    }

    // 上传完成后的收尾：
    // 1) 老版本 Typecho 若把 fileUploadComplete 暴露为全局，交给它统一处理（列表+插入）；
    // 2) 新版 Typecho 回调是闭包私有的，这里自己更新列表，并调用 Typecho.uploadComplete
    //    让编辑器弹窗弹出且自动填好 R2 图片地址。
    function finishUpload(file, meta) {
        if (!meta) {
            return;
        }

        if (typeof fileUploadComplete === 'function') {
            ownFileComplete(file, meta);
            return;
        }

        ownFileComplete(file, meta);

        // 每“批”只自动弹一次插入框（与官方行为一致），
        // 一批里后续文件只进列表、不重复弹窗
        if (!insertArmed) {
            return;
        }
        insertArmed = false;

        try {
            if (window.Typecho && typeof Typecho.uploadComplete === 'function') {
                dbg('⑦ 自动回填编辑器：调用 Typecho.uploadComplete', {
                    title: meta.title, url: meta.url, isImage: meta.isImage
                });
                Typecho.uploadComplete(meta);
            } else if (window.Typecho && typeof Typecho.insertFileToEditor === 'function') {
                dbg('⑦ 自动回填编辑器：调用 Typecho.insertFileToEditor', {
                    title: meta.title, url: meta.url, isImage: meta.isImage
                });
                Typecho.insertFileToEditor(meta.title, meta.url, meta.isImage);
            } else {
                err('⑦ 编辑器插入回调未就绪（Typecho.uploadComplete / insertFileToEditor 均不存在），无法自动回填地址。', '');
            }
        } catch (e) {
            err('⑦ 自动回填编辑器失败', e);
        }
    }

    var lastSign = null;

    /* ---------------- 接管 Typecho.uploadFile（防覆盖） ---------------- */

    function directUpload(file) {
        if (!file || !file.name) {
            dbg('收到空文件对象，忽略', file);
            return;
        }

        var now = Date.now();
        // 间隔大于 1.2s 视为新一轮选择/拖拽，允许自动弹插入框
        if (now - lastAddAt > 1200) {
            insertArmed = true;
        }
        lastAddAt = now;

        if (!file.id) {
            file.id = 'direct-' + now + '-' + Math.floor(Math.random() * 100000);
        }
        queue.push(file);
        next();
    }

    // 官方 ready 回调会在页面就绪时用“私有实现”覆盖 Typecho.uploadFile，
    // 因此必须在 DOMContentLoaded / load 后再声明一次，确保直传接管始终生效
    function installOnce() {
        var failReason = initEndpoints();
        if (failReason) {
            err(failReason + '。');
            return;
        }

        // 新文章页加载时重置“历史未关联附件”显示（仅一次，避免误清本页面上传的条目）
        resetUnattachedList();

        if (!window.Typecho || typeof Typecho.uploadFile !== 'function') {
            err('未找到 Typecho.uploadFile，无法接管上传。');
            return;
        }

        if (Typecho.uploadFile.__cfr2direct) {
            return;
        }

        Typecho.uploadFile = directUpload;
        directUpload.__cfr2direct = true;

        dbg('脚本已注入并接管后台上传（已等待官方 ready 完成，直传接管生效）', {
            root: root,
            signApi: apiUrl('sign'),
            registerApi: apiUrl('register'),
            deleteApi: deleteUrl
        });
    }

    var installed = false;
    function scheduleInstall() {
        if (installed) {
            return;
        }
        installed = true;

        var tryInstall = function () {
            try {
                installOnce();
            } catch (e) {
                err('接管初始化异常', e);
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(tryInstall, 0);
            });
        } else {
            setTimeout(tryInstall, 0);
        }

        // 官方 $(document).ready 里的实现若在我们之后才覆盖，load 后再次抢回
        window.addEventListener('load', function () {
            setTimeout(tryInstall, 300);
        });

        // 最后兜底
        setTimeout(tryInstall, 1200);
        setTimeout(tryInstall, 2500);
    }

    scheduleInstall();

    /* ---------------- 兜底：捕获漏网的全局异常 ---------------- */
    window.addEventListener('error', function (ev) {
        err('页面脚本异常', ev.message || ev.error || ev);
    });
    if (window.addEventListener) {
        window.addEventListener('unhandledrejection', function (ev) {
            err('未捕获的 Promise 异常', ev && ev.reason ? (ev.reason && ev.reason.stack || ev.reason) : ev);
        });
    }
})();
