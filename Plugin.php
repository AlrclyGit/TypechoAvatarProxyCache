<?php

/*
 * Gravatar 头像缓存代理
 *
 * 公共 Gravatar 源在国内访问很不稳定，评论头像经常加载失败或长时间白屏。
 * 本插件把头像改由自己的站点代理输出，并在服务器本地缓存一份：
 * 浏览器只跟本站通信，头像按用户定义的源列表依次回源，全部失败时用 DiceBear 兜底。
 *
 * 工作方式：
 * 1. 启用时向 Typecho 路由表注册 /gravatar-cache/[hash] 路由，由插件自带的
 *    Widget 直接输出图片字节，头像地址因此完全落在本站域名下。
 * 2. 在请求早期定义 __TYPECHO_GRAVATAR_PREFIX__ 常量，把内核
 *    Typecho\Common::gravatarUrl() 生成的地址整体改写到本站路由，
 *    于是前台评论头像、文章作者头像、后台用户头像一起生效，主题无需改动。
 * 3. 图片按 两级目录 + md5 落盘，元数据（MIME、过期时间）存同名 json。
 *
 * 过期与清理：
 * - 每个缓存文件自带过期时间，读取时判断，过期后回源刷新（惰性过期）。
 * - 前台请求按概率、后台面板每次进入时触发一次轻量清理，删除过期文件，
 *   保证长期不会被访问的头像不会一直占着磁盘。
 */

namespace TypechoPlugin\GravatarCache;

use Typecho\Common;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Gravatar 头像本地缓存代理
 *
 * @package GravatarCache
 * @author alrcly
 * @version 1.0.0
 * @link https://github.com/alrcly/GravatarCache
 * @since 1.3.0
 */
class Plugin implements PluginInterface
{
    /**
     * 插件标识：同时用作路由名、缓存目录名、设置项名
     * 如果改了插件目录名，这里要一起改
     */
    public const NAME = 'GravatarCache';

    /**
     * 路由表中本插件的路由名
     */
    public const ROUTE_NAME = 'gravatar_cache';

    /**
     * 头像路由的路径模板
     *
     * 只匹配恰好 32 位的字母数字，也就是 md5 的长度：
     * 路径不合法的请求直接 404，不会被用来灌缓存。
     */
    public const ROUTE_URL = '/gravatar-cache/[hash:alpha:32]';

    /**
     * 空邮箱时的兜底路由
     *
     * 评论没有填邮箱时，内核 gravatarUrl() 不会追加 hash，
     * 生成的是 /gravatar-cache/?s=64。这条路由负责接住这种请求，
     * 统一按 md5('') 缓存一张默认头像，不会造成缓存膨胀。
     *
     * 注意这里不能写成 '/gravatar-cache/'：Typecho 解析路由时会
     * rtrim($regx, '/')，末尾带斜杠的纯路径会被破坏成非法正则。
     * 写成不带斜杠的形式，生成的正则同时匹配带斜杠与不带斜杠两种访问。
     */
    public const ROUTE_EMPTY_URL = '/gravatar-cache';

    /**
     * 兜底路由名
     */
    public const ROUTE_EMPTY_NAME = 'gravatar_cache_empty';

    /**
     * 空邮箱对应的方法，与 md5('') 一致
     */
    public const EMPTY_HASH = 'd41d8cd98f00b204e9800998ecf8427e';

    /**
     * 默认头像源列表，逗号分隔，按填写顺序依次尝试
     */
    public const DEFAULT_SERVERS = 'https://cn.cravatar.com,https://secure.gravatar.com';

    /**
     * DiceBear 兜底头像的默认样式
     */
    public const DEFAULT_DICEBEAR_STYLE = 'waves';

    /**
     * 缓存目录名（位于插件目录内）
     */
    private const CACHE_DIR = 'cache';

    /**
     * 回源失败后的冷却时间（秒）
     *
     * gravatar.com 挂掉时，如果不做冷却，每个头像请求都会白等一次超时，
     * 一页几十个评论就会把页面拖到几十秒。失败后短时间内直接走缓存/占位图。
     */
    private const FAIL_COOLDOWN = 60;

    /**
     * Gravatar 官方允许的尺寸上限
     */
    private const MAX_SIZE = 2048;

    /**
     * 允许的评级
     */
    private const RATINGS = ['G', 'PG', 'R', 'X'];

    /**
     * 允许的内置默认头像关键字
     */
    private const DEFAULT_KEYWORDS = [
        'mm', 'mp', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'blank', '404'
    ];

    /**
     * 请求内的设置缓存
     */
    private static ?array $settings = null;

    /**
     * 本次请求是否已经执行过清理
     */
    private static bool $cleaned = false;

    /**
     * 缓存目录诊断结果（每次请求只算一次）
     */
    private static ?array $diagnostics = null;

    /**
     * 启用插件
     *
     * 注意：缓存目录不可写时**不能**让启用失败。
     * 目录权限属于部署环境问题，而插件即使写不了缓存，
     * 也仍然可以作为纯代理正常工作（只是每次都回源）。
     * 所以这里只做尝试创建，结果留到后台面板提示用户修。
     *
     * @throws \Typecho\Plugin\Exception
     */
    public static function activate()
    {
        self::ensureCacheDir();
        self::writeCacheGuard();
        self::registerRoute();
        self::registerPanel();
        self::registerHooks();

        // 启用那一刻先清一次，把上次停用期间留下的过期文件处理掉
        self::maybeCleanup(true);
    }

    /**
     * 禁用插件
     *
     * 只撤掉路由、面板和钩子，缓存文件保留：
     * 重新启用后立刻就有头像可用，不必再回源下载一遍。
     */
    public static function deactivate()
    {
        self::removeRoute();
        self::removePanel();
    }

    /**
     * 插件设置
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        $servers = new \Typecho\Widget\Helper\Form\Element\Text(
            'servers',
            null,
            self::DEFAULT_SERVERS,
            _t('定义头像源'),
            _t('头像按这里填写的顺序依次获取，成功一个就停止，多个源用英文逗号 , 分隔，例如：'
                . self::DEFAULT_SERVERS
                . '。全部失败时用 DiceBear 按邮箱生成一张兜底头像，保证图片永远可显示。')
        );
        $form->addInput($servers);

        $dicebearStyle = new \Typecho\Widget\Helper\Form\Element\Text(
            'dicebearStyle',
            null,
            self::DEFAULT_DICEBEAR_STYLE,
            _t('兜底头像样式'),
            _t('所有头像源都获取失败时使用的兜底头像样式，默认 waves。'
                . '可用样式见 https://www.dicebear.com/styles/。')
        );
        $form->addInput($dicebearStyle);

        $ttl = new \Typecho\Widget\Helper\Form\Element\Number(
            'ttl',
            null,
            '604800',
            _t('缓存有效期（秒）'),
            _t('超过这个时长后重新回源获取一次，默认 604800 秒（7 天）。'
                . '建议不要小于 86400（1 天），否则回源次数会明显变多。')
        );
        $ttl->addRule('isInteger', _t('缓存有效期必须是整数'));
        $form->addInput($ttl);

        $timeout = new \Typecho\Widget\Helper\Form\Element\Number(
            'timeout',
            null,
            '3',
            _t('回源超时（秒）'),
            _t('向头像源请求时最多等待的秒数，默认 3 秒。头像源不稳定时调小可以让页面更快返回。')
        );
        $timeout->addRule('isInteger', _t('回源超时必须是整数'));
        $form->addInput($timeout);

        $stale = new \Typecho\Widget\Helper\Form\Element\Radio(
            'stale',
            ['1' => _t('继续使用过期缓存'), '0' => _t('输出空白占位图')],
            '1',
            _t('回源失败时的处理'),
            _t('头像源请求失败、而本地缓存又已过期时：选第一项可以继续用旧头像，保证页面不出现破损图片；'
                . '选第二项则改用空白占位图。两种都不会让图片加载失败。')
        );
        $form->addInput($stale);

        $cleanup = new \Typecho\Widget\Helper\Form\Element\Radio(
            'autoCleanup',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '1',
            _t('自动清理过期缓存'),
            _t('开启后，前台请求会按下面的概率触发一次过期文件清理；'
                . '进入后台插件面板时也会无条件清理一次。关闭后只能手动在面板里清理。')
        );
        $form->addInput($cleanup);

        $ratio = new \Typecho\Widget\Helper\Form\Element\Number(
            'cleanupRatio',
            null,
            '5',
            _t('清理触发概率（%）'),
            _t('每次前台请求执行清理的概率，默认 5 表示 5%。建议保持在 1~10 之间：'
                . '太高会频繁扫描目录，太低则过期文件清得不及时。')
        );
        $ratio->addRule('isInteger', _t('清理概率必须是整数'));
        $form->addInput($ratio);

        $cachePath = new \Typecho\Widget\Helper\Form\Element\Text(
            'cachePath',
            null,
            '',
            _t('缓存目录'),
            _t('留空表示使用插件目录下的 cache/。如果提示目录不可写'
                . '（常见于 1Panel、宝塔这类 Docker 部署，插件目录属主与 PHP 运行用户不一致），'
                . '可以改成站点内任意可写目录，例如 usr/cache/gravatar；'
                . '也可以直接填绝对路径。目录不需要预先存在，插件会自动创建。')
        );
        $form->addInput($cachePath);
    }

    /**
     * 个人设置：本插件不需要
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 保存设置前的校验
     *
     * 返回非空字符串表示校验失败并展示提示，返回 null 表示通过。
     *
     * @param array $settings
     * @return string|null
     */
    public static function configCheck(array $settings): ?string
    {
        if (isset($settings['ttl']) && (int) $settings['ttl'] < 60) {
            return _t('缓存有效期不能小于 60 秒');
        }

        if (isset($settings['timeout']) && (int) $settings['timeout'] < 1) {
            return _t('回源超时必须大于 0 秒');
        }

        if (isset($settings['cleanupRatio'])) {
            $ratio = (int) $settings['cleanupRatio'];

            if ($ratio < 0 || $ratio > 100) {
                return _t('清理概率必须在 0 ~ 100 之间');
            }
        }

        if (isset($settings['servers'])) {
            foreach (self::splitServers((string) $settings['servers']) as $server) {
                if (!preg_match('#^https?://[^/\s]+$#i', $server)) {
                    return _t('头像源地址格式不正确：' . htmlspecialchars($server));
                }
            }
        }

        if (isset($settings['dicebearStyle'])
            && !preg_match('/^[A-Za-z0-9_-]+$/', trim((string) $settings['dicebearStyle']))) {
            return _t('兜底头像样式只能包含字母、数字、下划线和连字符');
        }

        return null;
    }

    /**
     * 保存设置
     *
     * 定义了 configHandle 后 Typecho 就不再走默认的写入流程，
     * 因此这里自己把规整后的值写回 options。
     * 这样即使有人绕过表单提交了越界值，落库的也一定是合法配置。
     *
     * @param array $settings
     * @param bool $isInit
     * @throws \Typecho\Plugin\Exception
     */
    public static function configHandle(array $settings, bool $isInit = false): void
    {
        $normalized = self::normalizeSettings($settings);

        \Widget\Plugins\Edit::configPlugin(self::NAME, $normalized);

        // 设置变了（尤其是缓存目录），本进程内的缓存全部作废，
        // 并按新目录重新尝试建一次，这样用户改完路径立刻就能看到结果
        self::$settings = $normalized;
        self::$diagnostics = null;
        self::$cleaned = false;

        self::ensureCacheDir();
        self::writeCacheGuard();
    }

    /**
     * 读取插件的当前设置，永远返回一份完整可用的配置
     *
     * 插件刚启用、或 options 里还没有对应记录时也不能影响前台渲染，
     * 所以这里吞掉异常并回落到默认值。
     *
     * @return array
     */
    public static function settings(): array
    {
        if (null !== self::$settings) {
            return self::$settings;
        }

        $raw = [];

        try {
            $config = \Widget\Options::alloc()->plugin(self::NAME);

            if ($config instanceof \Typecho\Config) {
                $raw = $config->toArray();
            }
        } catch (\Throwable $e) {
            $raw = [];
        }

        return self::$settings = self::normalizeSettings($raw);
    }

    /**
     * 默认设置
     *
     * @return array
     */
    public static function defaultSettings(): array
    {
        return [
            'servers'       => self::DEFAULT_SERVERS,
            'dicebearStyle' => self::DEFAULT_DICEBEAR_STYLE,
            'ttl'           => '604800',
            'timeout'       => '3',
            'stale'         => '1',
            'autoCleanup'   => '1',
            'cleanupRatio'  => '5',
            'cachePath'     => '',
        ];
    }

    /**
     * 把设置规整成可以安全使用的形式
     *
     * @param array $settings
     * @return array
     */
    public static function normalizeSettings(array $settings): array
    {
        $settings += self::defaultSettings();

        $ttl = (int) $settings['ttl'];
        $settings['ttl'] = (string) ($ttl >= 60 ? $ttl : 604800);

        $timeout = (int) $settings['timeout'];
        $settings['timeout'] = (string) ($timeout >= 1 ? $timeout : 3);

        // 头像源：支持逗号分隔的多个源，按填写顺序依次尝试。
        // 兼容旧版设置项 server（单源）：新值为空时迁移旧值
        $legacy = trim((string) ($settings['server'] ?? ''));
        $servers = trim((string) ($settings['servers'] ?? ''));

        if ('' === $servers) {
            $servers = $legacy;
        }

        $list = [];

        foreach (self::splitServers($servers) as $item) {
            if (preg_match('#^https?://[^/\s]+$#i', $item)) {
                $list[] = $item;
            }
        }

        // 一个合法的源都没有时回落到默认列表，保证永远有源可用
        $settings['servers'] = empty($list) ? self::DEFAULT_SERVERS : implode(',', $list);
        unset($settings['server']);

        // DiceBear 兜底样式
        $style = trim((string) ($settings['dicebearStyle'] ?? ''));
        $settings['dicebearStyle'] = preg_match('/^[A-Za-z0-9_-]+$/', $style)
            ? $style : self::DEFAULT_DICEBEAR_STYLE;

        $ratio = (int) $settings['cleanupRatio'];
        $settings['cleanupRatio'] = (string) ($ratio >= 0 && $ratio <= 100 ? $ratio : 5);

        // 缓存路径：只接受相对路径或绝对路径，剔除 .. 防止跳出站点根目录
        $cachePath = trim(str_replace('\\', '/', (string) $settings['cachePath']));
        $cachePath = str_replace("\0", '', $cachePath);

        if ('' !== $cachePath && preg_match('#(^|/)\.\.(/|$)#', $cachePath)) {
            $cachePath = '';
        }

        $settings['cachePath'] = $cachePath;

        // 旧版本设置项不再使用（开关由启用/禁用插件实现），直接丢弃
        unset($settings['enable']);

        $settings['stale'] = '1' === (string) $settings['stale'] ? '1' : '0';
        $settings['autoCleanup'] = '1' === (string) $settings['autoCleanup'] ? '1' : '0';

        return $settings;
    }

    /**
     * 是否已在本次请求加载过路由 / 钩子
     */
    private static function registerHooks(): void
    {
        // 前台入口：定义头像地址前缀 + 概率清理
        TypechoPlugin::factory('index.php')->begin = [self::class, 'onBegin'];
        // 后台入口：后台也大量用到头像（评论管理、个人设置）
        TypechoPlugin::factory('admin/common.php')->begin = [self::class, 'onBegin'];
    }

    /**
     * 请求开始时的统一处理
     *
     * 必须在任何头像地址生成之前定义 __TYPECHO_GRAVATAR_PREFIX__，
     * 这样 Common::gravatarUrl() 才会把地址指向本站。
     */
    public static function onBegin(): void
    {
        try {
            self::defineGravatarPrefix();

            // 头像请求本身要尽量快，不在这里扫描缓存目录
            if (!self::isAvatarRequest()) {
                self::maybeCleanup();
            }
        } catch (\Throwable $e) {
            // 任何异常都不应该影响页面本身
        }
    }

    /**
     * 当前请求是否就是头像代理请求
     *
     * 头像请求不应该触发目录扫描，否则一次图片请求会被拖慢。
     *
     * @return bool
     */
    private static function isAvatarRequest(): bool
    {
        $path = \Typecho\Request::getInstance()->getPathInfo();

        return is_string($path) && 0 === strpos($path, rtrim(self::routePrefix(), '/'));
    }

    /**
     * 定义头像地址前缀常量
     *
     * Typecho 的 gravatarUrl() 看到这个常量后会直接把它当作地址前缀，
     * 因此这里必须给出完整地址（含协议和站点路径）。
     */
    public static function defineGravatarPrefix(): void
    {
        if (defined('__TYPECHO_GRAVATAR_PREFIX__')) {
            return;
        }

        $options = \Widget\Options::alloc();

        define('__TYPECHO_GRAVATAR_PREFIX__', rtrim($options->index, '/') . self::routePrefix());
    }

    /**
     * 头像路由的路径前缀
     *
     * @return string
     */
    public static function routePrefix(): string
    {
        return '/gravatar-cache/';
    }

    /**
     * 生成某个邮箱的头像地址
     *
     * 主题里如果不想用内核的 gravatar() 方法，可以直接调用这个函数。
     *
     * @param string|null $mail
     * @param int $size
     * @param string|null $rating
     * @param string|null $default
     * @return string
     */
    public static function avatarUrl(?string $mail, int $size = 64, ?string $rating = null, ?string $default = null): string
    {
        $query = ['s' => self::clampSize($size)];

        if (null !== $rating) {
            $query['r'] = $rating;
        }

        if (null !== $default) {
            $query['d'] = $default;
        }

        return Common::url(
            self::routePrefix() . md5(strtolower(trim((string) $mail))) . '?' . http_build_query($query),
            \Widget\Options::alloc()->index
        );
    }

    /**
     * 限制头像尺寸到 Gravatar 支持的范围
     *
     * @param int $size
     * @return int
     */
    public static function clampSize(int $size): int
    {
        if ($size < 1) {
            return 1;
        }

        return $size > self::MAX_SIZE ? self::MAX_SIZE : $size;
    }

    /**
     * 规整客户端传来的参数
     *
     * 所有参数都来自 URL，必须做类型与范围校验：
     * 尺寸限制在 1~2048，评级只接受四个已知值，默认头像只接受关键字或 http(s) 地址。
     *
     * @param string $hash
     * @param int $size
     * @param mixed $rating
     * @param mixed $default
     * @return array
     */
    public static function normalizeParams(string $hash, int $size, $rating, $default): array
    {
        $hash = strtolower($hash);

        if (!preg_match('/^[0-9a-f]{32}$/', $hash)) {
            $hash = md5('');
        }

        $rating = strtoupper(is_string($rating) ? $rating : '');

        if (!in_array($rating, self::RATINGS, true)) {
            $rating = 'G';
        }

        return [
            'hash'    => $hash,
            'size'    => self::clampSize($size),
            'rating'  => $rating,
            'default' => self::safeDefault(is_string($default) ? $default : ''),
        ];
    }

    /**
     * 校验默认头像参数
     *
     * Gravatar 的 d 参数既支持内置关键字也支持图片地址，
     * 这里只放行这两类，其它值一律退回 mm，避免被拼出奇怪的协议。
     *
     * @param string $default
     * @return string
     */
    public static function safeDefault(string $default): string
    {
        $default = trim($default);

        if ('' === $default) {
            return 'mm';
        }

        $lower = strtolower($default);

        if (in_array($lower, self::DEFAULT_KEYWORDS, true)) {
            return $lower;
        }

        if (preg_match('#^https?://[^\s]+$#i', $default)) {
            return $default;
        }

        return 'mm';
    }

    /**
     * 计算缓存文件路径
     *
     * 尺寸、评级、默认头像、头像源都会影响图片内容，必须一起参与 key，
     * 否则不同尺寸会互相串图。
     *
     * @param array $params
     * @return array{data: string, meta: string, fail: string}
     */
    public static function cachePaths(array $params): array
    {
        $key = md5(implode('|', [
            $params['hash'],
            $params['size'],
            $params['rating'],
            $params['default'],
            self::settings()['servers'],
        ]));

        $dir = self::cacheDir() . '/' . substr($key, 0, 2) . '/' . substr($key, 2, 2);

        return [
            'data' => $dir . '/' . $key . '.img',
            'meta' => $dir . '/' . $key . '.json',
            'fail' => $dir . '/' . $key . '.fail',
        ];
    }

    /**
     * 读取缓存元数据
     *
     * @param array $paths
     * @return array|null
     */
    public static function readCache(array $paths): ?array
    {
        if (!is_file($paths['data']) || !is_file($paths['meta'])) {
            return null;
        }

        $raw = @file_get_contents($paths['meta']);

        if (false === $raw) {
            return null;
        }

        $meta = json_decode($raw, true);

        if (!is_array($meta) || empty($meta['mime'])) {
            return null;
        }

        $expireAt = (int) ($meta['expireAt'] ?? 0);

        return [
            'file'     => $paths['data'],
            'mime'     => (string) $meta['mime'],
            'expire'   => (int) ($meta['expire'] ?? 0),
            'expireAt' => $expireAt,
            'fresh'    => $expireAt > time(),
        ];
    }

    /**
     * 写入缓存
     *
     * 先写临时文件再原子 rename，避免并发请求读到半张图片。
     * 写失败只返回 false，不影响本次图片输出。
     *
     * @param array $paths
     * @param string $body
     * @param string $mime
     * @param int $ttl
     * @return bool
     */
    public static function writeCache(array $paths, string $body, string $mime, int $ttl): bool
    {
        $dir = dirname($paths['data']);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $now = time();

        $meta = json_encode([
            'mime'     => $mime,
            'expire'   => $ttl,
            'expireAt' => $now + $ttl,
            'bytes'    => strlen($body),
            'created'  => $now,
        ], JSON_UNESCAPED_SLASHES);

        $tmpData = $paths['data'] . '.' . getmypid() . '.tmp';
        $tmpMeta = $paths['meta'] . '.' . getmypid() . '.tmp';

        if (false === @file_put_contents($tmpData, $body)) {
            return false;
        }

        if (false === @file_put_contents($tmpMeta, $meta)) {
            @unlink($tmpData);
            return false;
        }

        if (!@rename($tmpData, $paths['data'])) {
            @unlink($tmpData);
            @unlink($tmpMeta);
            return false;
        }

        if (!@rename($tmpMeta, $paths['meta'])) {
            @unlink($tmpMeta);
            return false;
        }

        // 回源成功后清掉失败标记
        self::clearFailMark($paths);

        return true;
    }

    /**
     * 记录一次回源失败
     *
     * @param array $paths
     */
    public static function markFail(array $paths): void
    {
        @file_put_contents($paths['fail'], (string) time());
    }

    /**
     * 回源失败是否还在冷却期内
     *
     * @param array $paths
     * @return bool
     */
    public static function inFailCooldown(array $paths): bool
    {
        if (!is_file($paths['fail'])) {
            return false;
        }

        $at = (int) @file_get_contents($paths['fail']);

        return $at > 0 && (time() - $at) < self::FAIL_COOLDOWN;
    }

    /**
     * 清除失败标记
     *
     * @param array $paths
     */
    private static function clearFailMark(array $paths): void
    {
        if (is_file($paths['fail'])) {
            @unlink($paths['fail']);
        }
    }

    /**
     * 回源获取头像
     *
     * 按定义头像源的顺序逐个尝试（curl 优先，没有 curl 扩展时退回 stream 封装），
     * 成功一个就停止；全部失败时用 DiceBear 按邮箱生成兜底头像。
     *
     * @param array $params
     * @return array|null 成功返回 ['body' => string, 'mime' => string]
     */
    public static function fetchAvatar(array $params): ?array
    {
        $settings = self::settings();
        $timeout = (int) $settings['timeout'];
        $query = http_build_query([
            's' => $params['size'],
            'r' => $params['rating'],
            'd' => $params['default'],
        ]);

        foreach (self::splitServers($settings['servers']) as $server) {
            $result = self::fetchUrl(rtrim($server, '/') . '/avatar/' . $params['hash'] . '?' . $query, $timeout);

            if (null !== $result) {
                return $result;
            }
        }

        // 所有头像源都失败：用 DiceBear 按邮箱 hash 生成一张兜底头像
        return self::fetchUrl(self::dicebearUrl($params, $settings), $timeout);
    }

    /**
     * 下载单个地址并校验内容确实是图片
     *
     * @param string $url
     * @param int $timeout
     * @return array|null 成功返回 ['body' => string, 'mime' => string]
     */
    private static function fetchUrl(string $url, int $timeout): ?array
    {
        $body = function_exists('curl_init')
            ? self::fetchByCurl($url, $timeout)
            : self::fetchByStream($url, $timeout);

        if (null === $body) {
            return null;
        }

        $mime = self::detectMime($body);

        if (null === $mime) {
            // 拿回来的不是图片，通常是被运营商 / 防火墙插了页面，按失败处理
            return null;
        }

        return ['body' => $body, 'mime' => $mime];
    }

    /**
     * DiceBear 兜底头像地址
     *
     * 用邮箱 hash 作为 seed，同一个邮箱永远得到同一张兜底头像。
     *
     * @param array $params
     * @param array $settings
     * @return string
     */
    public static function dicebearUrl(array $params, array $settings): string
    {
        return 'https://api.dicebear.com/10.x/' . rawurlencode($settings['dicebearStyle'])
            . '/svg?seed=' . rawurlencode($params['hash']);
    }

    /**
     * 拆分头像源列表
     *
     * @param string $servers 逗号分隔的源列表
     * @return array
     */
    public static function splitServers(string $servers): array
    {
        $list = [];

        foreach (explode(',', $servers) as $item) {
            $item = trim($item);

            if ('' !== $item) {
                $list[] = rtrim($item, '/');
            }
        }

        return $list;
    }

    /**
     * curl 回源
     *
     * @param string $url
     * @param int $timeout
     * @return string|null
     */
    private static function fetchByCurl(string $url, int $timeout): ?string
    {
        $ch = curl_init($url);

        if (false === $ch) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => max(1, min(2, $timeout)),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Typecho-GravatarCache/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: image/*'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // PHP 8.0 起 curl_close() 已是空操作，这里不再调用

        if (false === $body || 200 !== $status) {
            return null;
        }

        return $body;
    }

    /**
     * 无 curl 扩展时的流封装回源
     *
     * @param string $url
     * @param int $timeout
     * @return string|null
     */
    private static function fetchByStream(string $url, int $timeout): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => $timeout,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'header'          => "Accept: image/*\r\n",
            ],
            'ssl'  => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        return false === $body ? null : $body;
    }

    /**
     * 用文件头判断 MIME
     *
     * 只认头像源会返回的五种格式（jpeg / png / gif / webp / svg）。
     * 比 getimagesize / finfo 便宜，也不依赖 GD 或 fileinfo 扩展。
     *
     * @param string $body
     * @return string|null
     */
    public static function detectMime(string $body): ?string
    {
        if (strlen($body) < 12) {
            return null;
        }

        // SVG（DiceBear 兜底头像返回的就是这种格式）
        if (preg_match('/^\s*(?:<\?xml[\s\S]*?\?>)?\s*<svg[\s>]/i', substr($body, 0, 512))) {
            return 'image/svg+xml';
        }

        if (0 === strncmp($body, "\xFF\xD8\xFF", 3)) {
            return 'image/jpeg';
        }

        if (0 === strncmp($body, "\x89PNG\r\n\x1a\n", 8)) {
            return 'image/png';
        }

        if (0 === strncmp($body, 'GIF87a', 6) || 0 === strncmp($body, 'GIF89a', 6)) {
            return 'image/gif';
        }

        if (0 === strncmp($body, 'RIFF', 4) && 'WEBP' === substr($body, 8, 4)) {
            return 'image/webp';
        }

        return null;
    }

    /**
     * 空白占位图
     *
     * 回源失败又没有可用缓存时输出，保证 <img> 永远不会裂图。
     * 一张 1x1 透明 PNG，浏览器会按属性里的宽高拉伸。
     *
     * 兜底用的万无一失优先于好看：即使 base64 解码意外失败，
     * 也要返回一段合法的 GIF89a 最小字节，保证 Content-Type 与内容一致。
     *
     * @return array{body: string, mime: string}
     */
    public static function placeholder(): array
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            true
        );

        if (is_string($png) && '' !== $png) {
            return ['body' => $png, 'mime' => 'image/png'];
        }

        // 1x1 透明 GIF，43 字节，任何解码环境下都可用
        return ['body' => "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF!"
            . "\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;",
            'mime' => 'image/gif'];
    }

    /**
     * 按概率触发清理
     *
     * @param bool $force
     */
    public static function maybeCleanup(bool $force = false): void
    {
        if (self::$cleaned) {
            return;
        }

        try {
            $settings = self::settings();

            if (!$force) {
                if ('1' !== $settings['autoCleanup']) {
                    return;
                }

                $ratio = (int) $settings['cleanupRatio'];

                if ($ratio <= 0 || random_int(1, 100) > $ratio) {
                    return;
                }
            }

            self::$cleaned = true;
            self::cleanup();
        } catch (\Throwable $e) {
            // 清理只是维护性动作，失败不能影响页面
        }
    }

    /**
     * 删除过期缓存
     *
     * @param int $limit 单次最多处理的文件数，避免超大目录拖慢请求
     * @return int 删除的文件（含元数据）数量
     */
    public static function cleanup(int $limit = 500): int
    {
        $root = self::cacheDir();

        if (!is_dir($root)) {
            return 0;
        }

        $now = time();
        $deleted = 0;
        $level1 = @scandir($root);

        if (false === $level1) {
            return 0;
        }

        foreach ($level1 as $dir1) {
            if ('.' === $dir1 || '..' === $dir1) {
                continue;
            }

            $path1 = $root . '/' . $dir1;

            if (!is_dir($path1)) {
                continue;
            }

            $level2 = @scandir($path1);

            if (false === $level2) {
                continue;
            }

            foreach ($level2 as $dir2) {
                if ('.' === $dir2 || '..' === $dir2) {
                    continue;
                }

                $path2 = $path1 . '/' . $dir2;

                if (!is_dir($path2)) {
                    continue;
                }

                $files = @scandir($path2);

                if (false === $files) {
                    continue;
                }

                foreach ($files as $file) {
                    if ($deleted >= $limit) {
                        return $deleted;
                    }

                    if ('.' === $file || '..' === $file) {
                        continue;
                    }

                    $full = $path2 . '/' . $file;

                    if (!is_file($full)) {
                        continue;
                    }

                    // 进程异常退出留下的临时文件，超过 1 小时直接丢弃
                    if ('.tmp' === substr($file, -4)) {
                        if (@filemtime($full) < $now - 3600 && @unlink($full)) {
                            $deleted++;
                        }

                        continue;
                    }

                    // 失败标记：只对"从没成功缓存过"的头像有意义，
                    // 一旦超过冷却时间就该回收，否则回源长期失败时会无限堆积
                    if ('.fail' === substr($file, -5)) {
                        $hasMeta = is_file(substr($full, 0, -5) . '.json');

                        if (!$hasMeta && @filemtime($full) < $now - self::FAIL_COOLDOWN && @unlink($full)) {
                            $deleted++;
                        }

                        continue;
                    }

                    if ('.json' !== substr($file, -5)) {
                        continue;
                    }

                    $raw = @file_get_contents($full);
                    $meta = false === $raw ? null : json_decode($raw, true);

                    // 元数据损坏：连图片一起清掉，下次请求会重新回源
                    if (!is_array($meta)) {
                        @unlink($full);
                        @unlink(substr($full, 0, -5) . '.img');
                        $deleted++;
                        continue;
                    }

                    if ((int) ($meta['expireAt'] ?? 0) > $now) {
                        continue;
                    }

                    @unlink(substr($full, 0, -5) . '.img');
                    @unlink(substr($full, 0, -5) . '.fail');
                    @unlink($full);
                    $deleted++;
                }

                // 顺手删掉清空后的目录
                $left = @scandir($path2);

                if (is_array($left) && 2 === count($left)) {
                    @rmdir($path2);
                }
            }
        }

        return $deleted;
    }

    /**
     * 统计缓存占用
     *
     * @return array{files: int, bytes: int, expired: int}
     */
    public static function stats(): array
    {
        $result = ['files' => 0, 'bytes' => 0, 'expired' => 0];
        $root = self::cacheDir();

        if (!is_dir($root)) {
            return $result;
        }

        $now = time();
        $level1 = @scandir($root);

        if (false === $level1) {
            return $result;
        }

        foreach ($level1 as $dir1) {
            if ('.' === $dir1 || '..' === $dir1) {
                continue;
            }

            $path1 = $root . '/' . $dir1;

            if (!is_dir($path1)) {
                continue;
            }

            $level2 = @scandir($path1);

            if (false === $level2) {
                continue;
            }

            foreach ($level2 as $dir2) {
                if ('.' === $dir2 || '..' === $dir2) {
                    continue;
                }

                $path2 = $path1 . '/' . $dir2;

                if (!is_dir($path2)) {
                    continue;
                }

                $files = @scandir($path2);

                if (false === $files) {
                    continue;
                }

                foreach ($files as $file) {
                    if ('.json' !== substr($file, -5)) {
                        continue;
                    }

                    $full = $path2 . '/' . $file;
                    $dataFile = substr($full, 0, -5) . '.img';

                    $result['files']++;

                    if (is_file($dataFile)) {
                        $result['bytes'] += (int) @filesize($dataFile);
                    }

                    $raw = @file_get_contents($full);
                    $meta = false === $raw ? null : json_decode($raw, true);

                    if (!is_array($meta) || (int) ($meta['expireAt'] ?? 0) <= $now) {
                        $result['expired']++;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 清空全部缓存
     *
     * 保留 .htaccess（缓存目录的访问保护），只清图片与元数据。
     *
     * @return int 删除的文件数
     */
    public static function flush(): int
    {
        $root = self::cacheDir();

        if (!is_dir($root)) {
            return 0;
        }

        $deleted = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            if ('.htaccess' === $item->getFilename()) {
                continue;
            }

            if (@unlink($item->getPathname())) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 缓存目录的绝对路径
     *
     * 默认在插件目录下的 cache/。如果插件目录不可写
     * （常见于 1Panel / 宝塔这类用 Docker 挂载、属主与 PHP 运行用户不一致的环境），
     * 可以在插件设置里把目录改到站点下任何可写位置，或直接用绝对路径。
     *
     * @return string
     */
    public static function cacheDir(): string
    {
        $custom = trim((string) self::settings()['cachePath']);

        if ('' === $custom) {
            return __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ . '/' . self::NAME . '/' . self::CACHE_DIR;
        }

        // 绝对路径直接用；相对路径按站点根目录解析，方便填写 usr/cache/gravatar 这类目录
        if (preg_match('#^(/|[A-Za-z]:[\\\\/])#', $custom)) {
            return rtrim($custom, '/\\');
        }

        return rtrim(__TYPECHO_ROOT_DIR__, '/\\') . '/' . trim($custom, '/\\');
    }

    /**
     * 尝试建立缓存目录
     *
     * 这里刻意**不抛异常**：目录不可写是部署环境问题，
     * 不该让插件无法启用。写不了缓存时插件退化成纯代理（每次都回源），
     * 功能仍然可用，具体原因由 diagnostics() 在后台面板里提示。
     *
     * @return bool 是否可用
     */
    private static function ensureCacheDir(): bool
    {
        $dir = self::cacheDir();

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        return is_writable($dir);
    }

    /**
     * 缓存目录诊断信息
     *
     * 用户遇到"不可写"时，真正需要知道的是：
     * 路径是什么、当前 PHP 以谁的身份在跑、该 chown 给谁。
     * 这里一次性把这些都算出来，直接在后台面板显示。
     *
     * @return array{
     *     dir: string,
     *     exists: bool,
     *     writable: bool,
     *     owner: string,
     *     phpUser: string,
     *     hint: string
     * }
     */
    public static function diagnostics(): array
    {
        if (null !== self::$diagnostics) {
            return self::$diagnostics;
        }

        $dir = self::cacheDir();
        $exists = is_dir($dir);
        $writable = $exists && is_writable($dir);

        // 当前 PHP 进程的属主名，用于给出准确的 chown 目标。
        // 优先 posix（php-fpm 下最准），拿不到就留空，由提示语兜底。
        $phpUser = '';

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());

            if (is_array($info) && isset($info['name'])) {
                $phpUser = (string) $info['name'];
            }
        }

        // 目录属主
        $owner = '';

        if ($exists) {
            $uid = @fileowner($dir);

            if (false !== $uid && function_exists('posix_getpwuid')) {
                $info = @posix_getpwuid($uid);

                if (is_array($info) && isset($info['name'])) {
                    $owner = (string) $info['name'];
                }
            }

            if ('' === $owner) {
                $perms = @fileperms($dir);

                if (false !== $perms) {
                    $owner = _t('未知（权限位 %s）', substr(sprintf('%o', $perms), -4));
                } else {
                    $owner = _t('未知');
                }
            }
        }

        $hint = '';

        if (!$exists) {
            $hint = _t('缓存目录不存在且无法自动创建。请确认插件目录可写，'
                . '或手动创建该目录并把属主改为 PHP 运行用户。');
        } elseif (!$writable) {
            $parent = dirname($dir);
            $cmdUser = '' !== $phpUser ? $phpUser : 'www-data';

            $hint = _t(
                '缓存目录存在但当前 PHP 用户不可写（目录属主：%s，PHP 运行用户：%s）。'
                . '请在服务器上执行：chown -R %s:%s "%s"（若无权限，用 root 执行）。',
                '' !== $owner ? $owner : '未知',
                '' !== $phpUser ? $phpUser : '未知',
                $cmdUser,
                $cmdUser,
                $dir
            );

            if (is_writable($parent)) {
                $hint .= ' ' . _t('父目录可写，也可以删除该目录让插件自动重建。');
            }
        }

        return self::$diagnostics = [
            'dir'      => $dir,
            'exists'   => $exists,
            'writable' => $writable,
            'owner'    => $owner,
            'phpUser'  => $phpUser,
            'hint'     => $hint,
        ];
    }

    /**
     * 缓存是否可用（可写就正常缓存，否则退化为纯代理）
     *
     * @return bool
     */
    public static function cacheAvailable(): bool
    {
        return self::diagnostics()['writable'];
    }

    /**
     * 给缓存目录写一个拒绝直接访问的规则
     *
     * 缓存里只有图片，但也没必要让目录被直接列举。
     * Apache 2.4 用 Require，2.2 用 Deny，两段都用 IfModule 包住，
     * 缺少对应模块时不会因为未知指令报 500。nginx 请参考 README。
     */
    private static function writeCacheGuard(): void
    {
        $file = self::cacheDir() . '/.htaccess';

        if (is_file($file)) {
            return;
        }

        $rule = <<<'HTACCESS'
# GravatarCache：缓存目录禁止直接访问（头像通过 /gravatar-cache/ 路由输出）
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    <IfModule mod_access_compat.c>
        Order allow,deny
        Deny from all
    </IfModule>
</IfModule>
HTACCESS;

        @file_put_contents($file, $rule . PHP_EOL);
    }

    /**
     * 注册头像路由
     *
     * 放在路由表最前面，避免被主题或其它插件的通配路由抢先匹配。
     */
    private static function registerRoute(): void
    {
        self::removeRoute();

        Helper::addRoute(
            self::ROUTE_NAME,
            self::ROUTE_URL,
            '\TypechoPlugin\GravatarCache\Widget\Avatar',
            'render',
            'index'
        );

        // 空邮箱的兜底地址 /gravatar-cache/ 紧跟其后
        Helper::addRoute(
            self::ROUTE_EMPTY_NAME,
            self::ROUTE_EMPTY_URL,
            '\TypechoPlugin\GravatarCache\Widget\Avatar',
            'render',
            self::ROUTE_NAME
        );
    }

    /**
     * 移除头像路由
     */
    private static function removeRoute(): void
    {
        $routingTable = Helper::options()->routingTable;

        foreach ([self::ROUTE_NAME, self::ROUTE_EMPTY_NAME] as $name) {
            if (isset($routingTable[$name])) {
                Helper::removeRoute($name);
            }
        }
    }

    /**
     * 后台面板挂载的菜单索引：1 = 控制台
     *
     * 依据官方文档《路由、Action 与后台面板》：
     * 面板优先放进已有菜单（1 控制台、2 撰写、3 管理、4 设置），
     * 只有内容确实需要独立入口时才用 addMenu() 新建顶级菜单。
     *
     * 放在「控制台」是插件面板最常见的做法：
     * 用户启用插件后回到控制台就能直接看到入口，不必去翻别的菜单。
     */
    private const PANEL_MENU_INDEX = 1;

    /**
     * 后台面板文件（相对插件目录）
     */
    private const PANEL_FILE = self::NAME . '/manage.php';

    /**
     * 注册后台面板
     *
     * 挂到「控制台」菜单下（索引 1），与官方文档示例
     * Helper::addPanel(3, 'Guestbook/panel.php', ...) 的用法一致，
     * 只是把菜单换成了控制台。
     *
     * 注册前先把本插件在**任意菜单索引**下的历史残留全部清掉：
     * 旧版本曾把面板挂在独立顶级菜单或「设置」下，
     * Helper::removePanel() 只处理传入的那一个索引，清不干净，
     * 结果就是面板残留在旧位置、重新启用后位置也不变。
     */
    private static function registerPanel(): void
    {
        self::purgePanelEntries();

        Helper::addPanel(
            self::PANEL_MENU_INDEX,
            self::PANEL_FILE,
            _t('头像缓存'),
            _t('Gravatar 本地缓存'),
            'administrator'
        );
    }

    /**
     * 移除后台面板
     *
     * 同样按「全部索引」清理，而不是只删固定索引 1：
     * 用户可能升级自把面板挂在别的菜单下的旧版本。
     */
    private static function removePanel(): void
    {
        self::purgePanelEntries();
    }

    /**
     * 清理本插件在 panelTable 里的所有登记
     *
     * Helper::removePanel($index, $fileName) 有两个限制，直接用它无法彻底清理：
     * 1. 只遍历 `child[$index]` 这一个菜单，别的菜单下的同名条目不动；
     * 2. 只会 unset `file` 表里第一个匹配项。
     *
     * 而 `file` 表是「哪些文件被登记过」的白名单（extending.php 靠它放行），
     * `child` 表才是菜单里真正显示的那一条。两者必须一起清，
     * 否则会出现「菜单没了但地址还能访问」或「菜单还在但地址 404」。
     *
     * 这里直接操作 panelTable 选项，逐个索引扫一遍，把本插件的条目全删。
     * 同时清理旧版本（用 addMenu 建的）留下的空顶级菜单，避免左栏残留。
     */
    private static function purgePanelEntries(): void
    {
        $panelTable = \Widget\Options::alloc()->panelTable;

        // 选项损坏或被其他插件写坏时可能是 null，统一按空数组处理，
        // 避免后面出现 "Trying to access array offset on null"
        if (!is_array($panelTable)) {
            $panelTable = [];
        }

        $changed = false;

        $file = urlencode(trim(self::PANEL_FILE, '/'));
        $target = 'extending.php?panel=' . $file;

        // 1. 从 file 白名单里删掉本插件（可能有多条）
        if (!empty($panelTable['file']) && is_array($panelTable['file'])) {
            $files = [];

            foreach ($panelTable['file'] as $item) {
                if ($item === $file) {
                    $changed = true;
                    continue;
                }

                $files[] = $item;
            }

            $panelTable['file'] = array_values(array_unique($files));
        }

        // 2. 遍历所有菜单索引，删掉指向本插件面板的条目
        if (!empty($panelTable['child']) && is_array($panelTable['child'])) {
            foreach (array_keys($panelTable['child']) as $index) {
                if (!is_array($panelTable['child'][$index])) {
                    continue;
                }

                $children = [];

                foreach ($panelTable['child'][$index] as $item) {
                    if (is_array($item) && isset($item[2]) && $item[2] === $target) {
                        $changed = true;
                        continue;
                    }

                    $children[] = $item;
                }

                $panelTable['child'][$index] = $children;
            }
        }

        // 3. 清掉已经没有子面板的顶级菜单（旧版本 addMenu 留下的空壳）
        if (!empty($panelTable['parent']) && is_array($panelTable['parent'])) {
            foreach ($panelTable['parent'] as $key => $name) {
                $index = (int) $key + 10;

                if (!empty($panelTable['child'][$index])) {
                    continue;
                }

                // 只清理本插件历史上用过的菜单名，不碰其他插件的菜单
                if ($name === 'Gravatar 缓存') {
                    // 注意：这里只能 unset，绝对不能 array_values 重排。
                    // parent 的下标与 child 的索引靠 `下标 + 10` 绑定，
                    // 一旦重排，其他插件面板的菜单就会错位到别人的菜单下。
                    unset($panelTable['parent'][$key], $panelTable['child'][$index]);
                    $changed = true;
                }
            }
        }

        if ($changed) {
            Helper::setOption('panelTable', $panelTable);
        }
    }
}
