<?php

/*
 * 头像输出 Widget
 *
 * 由路由 /gravatar-cache/[hash] 调用，负责把图片字节返回给浏览器。
 * 处理顺序：本地缓存 → 回源 → 回源失败时降级（旧缓存 / 空白占位图）。
 */

namespace TypechoPlugin\GravatarCache\Widget;

use Typecho\Response;
use Typecho\Widget;
use TypechoPlugin\GravatarCache\Plugin as GravatarCache;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 头像缓存输出组件
 */
class Avatar extends Widget
{
    /**
     * 浏览器端缓存上限（秒）
     *
     * 服务端缓存可能有 7 天，但浏览器不该长期持有一张头像：
     * 用户换头像后要等太久才更新。统一压到 1 天。
     */
    private const BROWSER_MAX_AGE = 86400;

    /**
     * 降级输出（旧缓存 / 占位图）时的浏览器缓存时间，尽量短
     */
    private const FALLBACK_MAX_AGE = 300;

    /**
     * 输出头像
     *
     * 路由表中 action 配置为 render，由 Router::dispatch() 调用。
     */
    public function render()
    {
        $hash = (string) $this->request->get('hash', '');

        // 空邮箱走兜底路由 /gravatar-cache/，没有 hash 参数，统一按 md5('') 处理
        if ('' === $hash) {
            $hash = GravatarCache::EMPTY_HASH;
        }

        $params = GravatarCache::normalizeParams(
            $hash,
            (int) $this->request->get('s', 64),
            $this->request->get('r', null),
            $this->request->get('d', null)
        );

        $settings = GravatarCache::settings();

        $paths = GravatarCache::cachePaths($params);
        $cached = GravatarCache::readCache($paths);

        // 1. 命中未过期缓存
        if (null !== $cached && $cached['fresh']) {
            $this->sendFile($paths, $cached, 'HIT', $this->remaining($cached['expireAt']));
            return;
        }

        // 2. 回源。失败冷却期内不再白白等超时，直接降级
        if (!GravatarCache::inFailCooldown($paths)) {
            $fetched = GravatarCache::fetchAvatar($params);

            if (null !== $fetched) {
                $ttl = (int) $settings['ttl'];
                $now = time();
                $written = GravatarCache::writeCache($paths, $fetched['body'], $fetched['mime'], $ttl);

                // 写不进缓存说明目录权限有问题，此时插件退化成纯代理：
                // 每次请求都回源，功能仍然可用，但要通过响应头暴露出来便于排查
                $this->sendBody(
                    $fetched['body'],
                    $fetched['mime'],
                    $now,
                    $written ? 'MISS' : 'NOCACHE',
                    $this->remaining($now + ($written ? $ttl : self::FALLBACK_MAX_AGE))
                );

                return;
            }

            GravatarCache::markFail($paths);
        }

        // 3. 降级：可用的旧缓存优先，其次空白占位图
        if (null !== $cached && '1' === $settings['stale']) {
            $this->sendFile($paths, $cached, 'STALE', self::FALLBACK_MAX_AGE);
            return;
        }

        $placeholder = GravatarCache::placeholder();
        $this->sendBody($placeholder['body'], $placeholder['mime'], 0, 'FALLBACK', self::FALLBACK_MAX_AGE);
    }

    /**
     * 缓存剩余有效期换算成浏览器缓存时间
     *
     * @param int $expireAt 服务端缓存的到期时间戳
     * @return int
     */
    private function remaining(int $expireAt): int
    {
        $remaining = $expireAt - time();

        if ($remaining < 60) {
            $remaining = 60;
        }

        return $remaining > self::BROWSER_MAX_AGE ? self::BROWSER_MAX_AGE : $remaining;
    }

    /**
     * 输出缓存文件
     *
     * @param array $paths
     * @param array $cached readCache() 的返回值
     * @param string $state
     * @param int $maxAge
     */
    private function sendFile(array $paths, array $cached, string $state, int $maxAge): void
    {
        $body = @file_get_contents($cached['file']);

        if (false === $body) {
            // 缓存文件刚好被并发清理掉，退化成占位图
            $placeholder = GravatarCache::placeholder();
            $this->sendBody($placeholder['body'], $placeholder['mime'], 0, 'FALLBACK', self::FALLBACK_MAX_AGE);
            return;
        }

        $lastModified = (int) @filemtime($paths['data']);

        $this->sendBody($body, $cached['mime'], $lastModified ?: time(), $state, $maxAge);
    }

    /**
     * 输出图片字节
     *
     * 这里直接操作底层 Response，而不是 $this->response->throwContent()，
     * 因为后者会强行给 Content-Type 拼上 "; charset=UTF-8"（对图片没有意义），
     * 同时这里需要自己控制 ETag / Last-Modified / Cache-Control，
     * 才能让浏览器和 CDN 真正省掉重复下载。
     *
     * @param string $body 图片字节
     * @param string $mime
     * @param int $lastModified 内容最后修改时间戳
     * @param string $state 缓存状态，用于 X-Gravatar-Cache 响应头
     * @param int $maxAge 浏览器缓存秒数
     */
    private function sendBody(string $body, string $mime, int $lastModified, string $state, int $maxAge): void
    {
        $etag = '"' . md5($body) . '"';
        $response = Response::getInstance();

        if ($this->isNotModified($etag)) {
            $response->setStatus(304)
                ->setHeader('ETag', $etag)
                ->setHeader('Cache-Control', 'public, max-age=' . $maxAge)
                ->setHeader('X-Gravatar-Cache', $state)
                ->addResponder(static function () {
                })
                ->respond();

            return;
        }

        $response->setStatus(200)
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Length', (string) strlen($body))
            ->setHeader('Cache-Control', 'public, max-age=' . $maxAge)
            ->setHeader('ETag', $etag)
            ->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT')
            ->setHeader('X-Gravatar-Cache', $state)
            ->addResponder(static function () use ($body) {
                echo $body;
            })
            ->respond();
    }

    /**
     * 浏览器是否已经持有同一份内容
     *
     * 只做 ETag 精确比对（允许带 W/ 弱校验前缀），
     * 不做模糊的包含判断，避免不同头像之间误命中 304。
     *
     * @param string $etag
     * @return bool
     */
    private function isNotModified(string $etag): bool
    {
        $header = $this->request->getHeader('If-None-Match');

        if (null === $header || '' === trim($header)) {
            return false;
        }

        if ('*' === trim($header)) {
            return true;
        }

        foreach (explode(',', $header) as $item) {
            $item = trim($item);

            if ('W/' === substr($item, 0, 2)) {
                $item = substr($item, 2);
            }

            if ($item === $etag) {
                return true;
            }
        }

        return false;
    }
}
