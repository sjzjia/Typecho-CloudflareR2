<?php
namespace TypechoPlugin\CloudflareR2;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Cloudflare R2 (S3 兼容) 轻量客户端
 *
 * 仅依赖 PHP cURL 扩展，自行实现 AWS Signature Version 4 签名，
 * 支持 putObject / deleteObject / getObject，无需安装 AWS SDK。
 *
 * 说明：
 * - R2 使用 S3 兼容接口，签名 region 官方约定为 "auto"。
 * - 默认使用路径风格 URL: https://<account_id>.r2.cloudflarestorage.com/<bucket>/<key>
 *
 * @package CloudflareR2
 */
class CloudflareR2Client
{
    /** @var string S3 兼容端点，例如 https://xxxx.r2.cloudflarestorage.com */
    private $endpoint;

    /** @var string 存储桶名称 */
    private $bucket;

    /** @var string Access Key ID */
    private $accessKey;

    /** @var string Secret Access Key */
    private $secretKey;

    /** @var string 签名区域，R2 使用 auto */
    private $region;

    /**
     * @param string $endpoint  S3 端点
     * @param string $bucket    桶名
     * @param string $accessKey Access Key ID
     * @param string $secretKey Secret Access Key
     * @param string $region    区域(默认 auto)
     */
    public function __construct($endpoint, $bucket, $accessKey, $secretKey, $region = 'auto')
    {
        $this->endpoint  = rtrim((string)$endpoint, '/');
        $this->bucket    = (string)$bucket;
        $this->accessKey = (string)$accessKey;
        $this->secretKey = (string)$secretKey;
        $this->region    = trim((string)$region) !== '' ? trim((string)$region) : 'auto';
    }

    /**
     * 桶根访问地址（路径风格）
     *
     * @return string
     */
    public function publicBase()
    {
        return $this->endpoint . '/' . $this->bucket;
    }

    /**
     * 上传对象
     *
     * @param string      $key         对象键，如 usr/uploads/2026/09/1.jpg
     * @param string|null $body        对象内容（当通过字节写入时）
     * @param string      $contentType Content-Type
     * @param string|null $filePath    本地文件路径（存在时采用流式上传，避免占用内存）
     * @return bool
     */
    public function putObject($key, $body = null, $contentType = 'application/octet-stream', $filePath = null)
    {
        $this->request('PUT', $key, $body, $contentType, $filePath);

        return true;
    }

    /**
     * 删除对象
     *
     * @param string $key
     * @return bool
     */
    public function deleteObject($key)
    {
        // R2 返回 404（对象不存在）视为删除成功，避免历史数据无法删除
        $this->request('DELETE', $key, null, null, null, true);

        return true;
    }

    /**
     * 获取对象内容
     *
     * @param string $key
     * @return string|null
     */
    public function getObject($key)
    {
        return $this->request('GET', $key);
    }

    /**
     * 配置存储桶 CORS（S3 PutBucketCors）
     *
     * 浏览器直传 R2 前会先发起 OPTIONS 预检，只有桶的 CORS 放行来源域名，
     * 浏览器才允许把文件直接 PUT 到 R2。本方法把允许的来源与常用方法写进桶配置。
     *
     * @param string[] $allowedOrigins 完整来源，如 ['https://example.com']
     * @return bool
     * @throws \RuntimeException
     */
    public function setBucketCors(array $allowedOrigins)
    {
        $origins = array();
        foreach ($allowedOrigins as $origin) {
            $origin = rtrim((string)$origin, '/');
            if (preg_match('#^https?://[^/]+$#i', $origin)) {
                $origins[] = $origin;
            }
        }
        $origins = array_values(array_unique($origins));
        if (!$origins) {
            throw new \RuntimeException('没有可写入的合法跨域来源，请传入 https://域名 形式的地址');
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<CORSConfiguration>' . "\n";
        $xml .= '  <CORSRule>' . "\n";
        foreach ($origins as $origin) {
            $xml .= '    <AllowedOrigin>' . htmlspecialchars($origin, ENT_XML1, 'UTF-8') . '</AllowedOrigin>' . "\n";
        }
        // 预检发生在任何 PUT 之前，需放行 PUT；GET/HEAD/POST/DELETE 一并放行以便后续功能扩展
        foreach (array('GET', 'HEAD', 'PUT', 'POST', 'DELETE') as $m) {
            $xml .= '    <AllowedMethod>' . $m . '</AllowedMethod>' . "\n";
        }
        $xml .= '    <AllowedHeader>*</AllowedHeader>' . "\n";
        $xml .= '    <ExposeHeader>ETag</ExposeHeader>' . "\n";
        $xml .= '    <MaxAgeSeconds>3000</MaxAgeSeconds>' . "\n";
        $xml .= '  </CORSRule>' . "\n";
        $xml .= '</CORSConfiguration>';

        // 桶级子资源操作：PUT /<bucket>?cors=，body 为 CORS XML
        $this->request('PUT', '', $xml, 'text/xml', null, false, array('cors' => ''));

        return true;
    }

    /**
     * 生成预签名 PUT URL（用于浏览器直传 R2，绕开服务器体积限制）
     *
     * 说明：
     * - 采用 AWS SigV4 Query 签名：X-Amz-* 参数全部放入 URL 查询串，浏览器无需携带
     *   Authorization / x-amz-date 等头即可直接 PUT。
     * - payload 按 UNSIGNED-PAYLOAD 处理，前端可用 fetch/XMLHttpRequest 直接上传文件流。
     *
     * @param string $key         对象键，如 usr/uploads/2026/09/1.jpg
     * @param string $contentType 对象 Content-Type（仅写入时使用，不参与签名）
     * @param int    $expires     有效期（秒），默认 900（15 分钟）
     * @return string 可直接用于 HTTP PUT 的完整 URL
     * @throws \RuntimeException
     */
    public function presignPutUrl($key, $contentType = 'application/octet-stream', $expires = 900)
    {
        $key      = ltrim((string)$key, '/');
        $urlParts = parse_url($this->endpoint);

        if (!$urlParts || empty($urlParts['host'])) {
            throw new \RuntimeException('Cloudflare R2 端点(endpoint)配置错误');
        }

        $scheme   = isset($urlParts['scheme']) ? strtolower($urlParts['scheme']) : 'https';
        $host     = $urlParts['host'];
        $basePath = isset($urlParts['path']) ? rtrim($urlParts['path'], '/') : '';

        $encodedKey   = implode('/', array_map('rawurlencode', explode('/', $key)));
        $canonicalUri = $basePath . '/' . $this->bucket . '/' . $encodedKey;

        $expires   = max(60, min(86400, (int)$expires));
        $amzDate   = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $scope     = $dateStamp . '/' . $this->region . '/s3/aws4_request';

        $query = array(
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $this->accessKey . '/' . $scope,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => (string)$expires,
            'X-Amz-SignedHeaders' => 'host',
        );
        ksort($query);

        $queryParts = array();
        foreach ($query as $qName => $qValue) {
            $queryParts[] = rawurlencode($qName) . '=' . rawurlencode($qValue);
        }
        $canonicalQuery = implode('&', $queryParts);

        // 预签名请求的规范化请求：仅签名 host 头，payload 视为 UNSIGNED-PAYLOAD
        $canonicalRequest = "PUT\n" . $canonicalUri . "\n" . $canonicalQuery . "\n"
            . 'host:' . $host . "\n\nhost\nUNSIGNED-PAYLOAD";

        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n"
            . hash('sha256', $canonicalRequest);

        $kDate     = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion   = hash_hmac('sha256', $this->region, $kDate, true);
        $kService  = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning  = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return $scheme . '://' . $host . $canonicalUri . '?' . $canonicalQuery
            . '&X-Amz-Signature=' . $signature;
    }

    /**
     * 发送签名请求
     *
     * @param string      $method         PUT / GET / DELETE
     * @param string      $key
     * @param string|null $body
     * @param string|null $contentType
     * @param string|null $filePath
     * @param bool        $allowNotFound
     * @param array       $query          子资源查询参数，如 ['cors' => '']（参与签名）
     * @return string|null 响应体
     * @throws \RuntimeException
     */
    protected function request($method, $key, $body = null, $contentType = null, $filePath = null, $allowNotFound = false, array $query = array())
    {
        $method   = strtoupper((string)$method);
        $key      = ltrim((string)$key, '/');
        $urlParts = parse_url($this->endpoint);

        if (!$urlParts || empty($urlParts['host'])) {
            throw new \RuntimeException('Cloudflare R2 端点(endpoint)配置错误');
        }

        $scheme   = isset($urlParts['scheme']) ? strtolower($urlParts['scheme']) : 'https';
        $host     = $urlParts['host'];
        $basePath = isset($urlParts['path']) ? rtrim($urlParts['path'], '/') : '';

        // 逐段 URL 编码对象键（保留 "/"），符合 S3 canonical URI 规范；
        // 桶级操作（如 ?cors）不带对象键，URL 停在 /bucket
        $encodedKey = '';
        if ('' !== $key) {
            $encodedKey = '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        }
        $canonicalUri = $basePath . '/' . $this->bucket . $encodedKey;

        // 规范化查询串（按键名排序、逐对编码），参与签名
        ksort($query);
        $queryParts = array();
        foreach ($query as $qName => $qValue) {
            $queryParts[] = rawurlencode((string)$qName) . '=' . rawurlencode((string)$qValue);
        }
        $canonicalQuery = implode('&', $queryParts);
        $url            = $scheme . '://' . $host . $canonicalUri
            . ('' !== $canonicalQuery ? ('?' . $canonicalQuery) : '');

        $amzDate   = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        if (null !== $filePath && is_file($filePath)) {
            $payloadHash = hash_file('sha256', $filePath);
        } elseif (null === $body) {
            $payloadHash = hash('sha256', '');
        } else {
            $payloadHash = hash('sha256', (string)$body);
        }

        if (null === $contentType || '' === (string)$contentType) {
            $contentType = 'application/octet-stream';
        }

        // 参与签名的头（小写、按键名排序）
        $headers = array(
            'content-type'          => trim((string)$contentType),
            'host'                  => $host,
            'x-amz-content-sha256'  => $payloadHash,
            'x-amz-date'            => $amzDate,
        );
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaders    = array();
        foreach ($headers as $name => $value) {
            $value = trim(preg_replace('/\s+/', ' ', (string)$value));
            $canonicalHeaders .= $name . ':' . $value . "\n";
            $signedHeaders[]    = $name;
        }
        $signedHeaderStr = implode(';', $signedHeaders);

        $canonicalRequest = $method . "\n"
            . $canonicalUri . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaderStr . "\n"
            . $payloadHash;

        $scope         = $dateStamp . '/' . $this->region . '/s3/aws4_request';
        $stringToSign  = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n"
            . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaderStr
            . ', Signature=' . $signature;

        $httpHeaders = array();
        foreach ($headers as $name => $value) {
            $httpHeaders[] = $name . ': ' . $value;
        }
        $httpHeaders[] = 'Authorization: ' . $authorization;
        // 禁止 cURL 自动追加 Expect: 100-continue，避免与部分对象存储服务通信时挂起
        $httpHeaders[] = 'Expect:';

        $ch = curl_init($url);
        if (false === $ch) {
            throw new \RuntimeException('无法初始化 cURL');
        }

        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $httpHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 600,
        ));

        $fp = null;
        if ('PUT' === $method) {
            if (null !== $filePath && is_file($filePath)) {
                $fp = @fopen($filePath, 'rb');
                if (!$fp) {
                    curl_close($ch);
                    throw new \RuntimeException('无法读取待上传文件: ' . $filePath);
                }
                curl_setopt($ch, CURLOPT_UPLOAD, true);
                curl_setopt($ch, CURLOPT_INFILE, $fp);
                curl_setopt($ch, CURLOPT_INFILESIZE, (int)filesize($filePath));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
            }
        }

        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);

        if ($fp) {
            fclose($fp);
        }
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException('cURL 错误 #' . $errno . ': ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            if ($allowNotFound && 404 === $status) {
                return '';
            }

            $message = trim((string)$response);
            if (function_exists('mb_substr')) {
                $message = mb_substr($message, 0, 400, 'UTF-8');
            } elseif (strlen($message) > 400) {
                $message = substr($message, 0, 400);
            }

            throw new \RuntimeException('Cloudflare R2 请求失败 (HTTP ' . $status . '): ' . $message);
        }

        return $response;
    }
}
