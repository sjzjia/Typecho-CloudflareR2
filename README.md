# CloudflareR2

> **Typecho 附件上传插件** —— 把附件直接上传到 **Cloudflare R2 对象存储**，源站服务器不再保存附件文件。

附件路径结构与 Typecho 默认完全一致（`/usr/uploads/年/月/文件名`），前端体验与原来一模一样，后台上传改为「浏览器直传 R2」预签名方案，请求体不再经过源站，彻底告别 `413` 体积上限。

---

## 目录

- [特性一览](#特性一览)
- [兼容性](#兼容性)
- [目录结构](#目录结构)
- [安装](#安装)
- [R2 端准备](#r2-端准备)
- [配置项说明](#配置项说明)
- [上传流程原理](#上传流程原理)
  - [R2 存储桶 CORS（必配）](#r2-存储桶-cors必配)
  - [启用后的验证](#启用后的验证)
  - [如何确认真的绕过了服务器](#如何确认真的绕过了服务器)
- [使用效果](#使用效果)
- [常见问题（FAQ）](#常见问题faq)

---

## 特性一览

- **零第三方依赖**：自带轻量 S3 客户端（cURL + SigV4 签名），无需 Composer / AWS SDK；
- **全流程接管**：覆盖 Typecho `Widget_Upload` 的上传 / 修改 / 删除 / URL 生成；
- **路径结构不变**：`/usr/uploads/年/月/文件名`，文章里的旧链接与新附件表现完全一致；
- **浏览器直传 R2**：文件字节不经过源站 Nginx/PHP，不受服务器体积限制（不再出现 `413`）；
- **删除联动**：后台删除附件时同步删除 R2 上的对象；
- **安全签名**：前端 PUT 使用 SigV4 Query 预签名 URL，密钥永不进入浏览器。

## 兼容性

| 项目 | 要求 |
|---|---|
| Typecho | 1.2.1+（含 1.3.x 命名空间版本） |
| PHP | 7.2+，需启用 **cURL** 扩展 |

## 目录结构

将插件目录部署到 `usr/plugins/` 后应为：

```
usr/plugins/CloudflareR2/
├── Plugin.php            # 插件主文件（激活 / 设置 / 挂载上传与 API 路由）
├── FileHandler.php       # 附件上传/替换/删除处理器
├── CloudflareR2Client.php# 轻量 S3 客户端（cURL + SigV4 签名）
├── Api.php               # 浏览器直传 API（sign / register / cors）
├── direct-upload.js      # 后台直传脚本（接管 Typecho.uploadFile）
└── README.md
```

## 安装

1. 将 `CloudflareR2` 整个目录复制到 Typecho 的 `usr/plugins/` 下（结构见上文）；
2. 进入后台 **控制台 → 插件**，启用 **CloudflareR2**；
3. 点击 **设置**，按 [配置项说明](#配置项说明) 填写并保存。

> **提示**
>
> - 插件在「启用」时才会挂载直传脚本与 API 路由（`/api/cloudflarer2/*`）。启用后修改并保存设置即可生效，**无需**「停用 → 启用」；
> - Typecho 停用插件时会清空 `plugin:CloudflareR2` 配置；本插件停用前会自动备份、再次启用时自动还原，但非必要请勿反复停用。

## R2 端准备

1. 登录 Cloudflare 控制台，进入 **R2**；
2. 创建一个 Bucket（如 `my-blog`）。若要用 R2 官方子域名直接访问，可在 Bucket 的 **Settings → Public access** 开启，得到形如 `https://pub-xxxxxxxxxxxx.r2.dev` 的地址；也可以绑定自定义域名；
3. 在 **R2 → Manage API Tokens → Create API Token** 创建 API 令牌：
   - 权限选择 **Object Read & Write**（删除附件还需 **Delete** 权限）；
   - 建议把令牌范围限定到对应 Bucket；
   - 记下生成的 **Access Key ID** 与 **Secret Access Key**。

## 配置项说明

| 配置项 | 说明 |
|---|---|
| **Account ID** | R2 概览页的账户 ID，形如 `0f1a2b3c...` |
| **Bucket** | 存储桶名称 |
| **Access Key ID** | 上一步创建的 R2 API 令牌 Access Key ID |
| **Secret Access Key** | 上一步创建的 R2 API 令牌 Secret Access Key |
| **Endpoint**（可选） | 留空自动使用 `https://<Account ID>.r2.cloudflarestorage.com`；仅当使用自定义 S3 兼容端点时才需填写 |
| **Region** | 保持默认 `auto` 即可 |
| **访问域名**（可选） | 自定义域名或 R2 公共地址，如 `https://img.example.com`（不要带结尾 `/`）。留空则附件 URL 使用 R2 默认端点地址 |

> **注意**
>
> - 只有「访问域名」对应的桶开启了公共访问，附件链接才能被浏览器直接打开；
> - 未绑定域名时，默认端点返回的地址仅用于标识、无法公网访问，**请务必配置自定义域名或开启公共访问**；
> - 「访问域名」仅决定返回给前台/编辑器使用的 URL 前缀，不影响对象在桶内的存储路径。

## 上传流程原理

附件上统一为「浏览器直传 R2」，完整流程分三步，**文件请求体不经过源站**：

```
① 后台选择/拖拽文件
   │  JS 调 /api/cloudflarer2/sign（仅提交文件名、大小，几 KB）
   ▼
② 插件返回 R2 预签名 PUT URL
   │  浏览器直接 PUT 到 <Account ID>.r2.cloudflarestorage.com
   │  （SigV4 Query 签名，无需携带任何密钥，请求体不经过源站）
   ▼
③ JS 调 /api/cloudflarer2/register
   │  插件向 contents 表写入附件记录
   ▼
  编辑器自动获得与官方一致的数据并插入图片 / 文件
```

不受 Nginx/PHP 体积限制，不会出现 `413`。

### R2 存储桶 CORS（必配）

浏览器直传属于跨域请求，**必须**先放行后台域名，否则会被浏览器拦截。以下两种方式任选其一：

**方式一：自动写入（推荐）**

登录后台后，浏览器打开一次：

```
https://你的后台域名/api/cloudflarer2/cors
```

插件会用已配置的 R2 API 令牌把当前站点域名（`http` / `https` 两种写法）写入桶的 CORS 规则。

**方式二：手动配置**

打开 **R2 → 对应 Bucket → Settings → CORS Policy → 编辑**，粘贴以下内容（把 `你的后台域名` 换成实际地址）：

```json
[
  {
    "AllowedOrigins": [
      "https://你的后台域名",
      "http://localhost:端口"
    ],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["content-type", "x-amz-*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

填写要点：

- `AllowedOrigins` 必须与浏览器地址栏中的源**完全一致**（含 `http(s)://`、端口、自定义域名），否则预检（OPTIONS）失败；
- `PUT` 是上传必需；`GET` / `HEAD` 用于文章页加载附件；
- `AllowedHeaders` 中的 `content-type` 是前端 PUT 时携带的请求头，`x-amz-*` 覆盖 R2 可能附加的校验头；
- 一键写入 CORS 需要 API 令牌具备桶 **Admin** 权限；若令牌只有对象读写权限，请改用方式二手动配置。

### 启用后的验证

1. 确保已按上文配置 R2 CORS；
2. 打开 **写文章** 页面，`Ctrl+F5` 强制刷新一次；
3. 上传一张图片，确认图片能正常出现在附件列表并可直接插入。

### 如何确认真的绕过了服务器

按 `F12` 打开开发者工具 → **Network** 面板，上传一张大图，观察请求：

- 应看到一条 **`PUT`** 请求，域名是 `<Account ID>.r2.cloudflarestorage.com` —— 说明文件字节**直接**去了 R2；
- 之前出现过的 `POST /action/upload … 413` 不再出现。

一次成功上传在 Network 中只会出现三个同源小请求：

```
sign（申请预签名地址） → PUT r2（直传） → register（登记附件）
```

## 使用效果

- 上传附件时，文件通过 S3 API 直接 PUT 到 R2，服务器本地不产生附件文件；
- 附件插入文章 / 相册时使用远程 URL；
- 在文章编辑页对已有附件执行「替换」，会覆盖 R2 上的同名对象；
- 后台删除附件时，会同步调用 R2 删除对应对象。

## 常见问题（FAQ）

**Q：上传失败，提示 `R2 直传失败 HTTP 403/400`？**

通常是以下原因之一：

- R2 存储桶 CORS 未放行当前后台域名；
- 预签名 URL 过期（有效期为 15 分钟）；
- 桶与 API 令牌不匹配（Bucket / Access Key ID / Secret Access Key 填错）。

**Q：`register` 接口返回 403？**

登录态失效。刷新后台页面、重新登录后再试。

**Q：浏览器报 `Failed to fetch` 或跨域错误？**

核对 CORS 规则中的 `AllowedOrigins` 是否包含当前后台地址的**完整源**（含协议、域名、端口）。

**Q：单文件超过 5GB？**

R2 单对象上限为 5GB，超出时 API 会直接拒绝（普通博客场景不会遇到）；超大文件请改用分片上传方案。

**Q：图片 / 附件链接打不开？**

「访问域名」对应的桶未开启公共访问或未绑定自定义域名。请确认相关设置。

**Q：历史已上传的本地附件会迁移吗？**

不会自动迁移。如需迁移，请自行将 `usr/uploads/` 下的文件按相同路径复制到 R2（可用 `rclone` 等工具）。

**Q：上传仍然失败，如何排查？**

依次检查：

1. 插件配置是否已保存、后台是否已强刷（`Ctrl+F5`）；
2. PHP 是否开启 cURL 扩展；
3. 服务器能否访问 `*.r2.cloudflarestorage.com`（国内服务器请确认网络可达性）；
4. API 令牌权限是否为 **Object Read & Write**（且已限定到正确的 Bucket）；
5. 若仍有问题，按 `F12` 查看 Console 中 `[CloudflareR2 direct]` 前缀日志，并连同报错一并反馈。
