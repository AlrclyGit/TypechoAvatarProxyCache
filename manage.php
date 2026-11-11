<?php

/*
 * Gravatar 头像缓存 - 后台面板
 *
 * 通过 admin/extending.php?panel=GravatarCache/manage.php 加载。
 * 进入本页会顺带强制清理一次过期缓存，并提供手动清理 / 清空的入口。
 * 这里可以直接使用 admin/common.php 初始化好的
 * $options / $user / $security / $request / $response / $menu。
 */

if (!defined('__TYPECHO_ROOT_DIR__') || !defined('__TYPECHO_ADMIN__')) {
    exit;
}

use TypechoPlugin\GravatarCache\Plugin;

/** 只有管理员可以访问 */
if (!$user->pass('administrator', true)) {
    $response->goBack();
    exit;
}

$pluginClass = Plugin::class;
$panelUrl = \Utils\Helper::url(Plugin::NAME . '/manage.php');

/**
 * CSRF token
 *
 * 表单提交目标和当前页面是同一个地址，所以用 getRequestUrl() 生成、
 * 也用 getRequestUrl() 校验，两端必然一致（参照 PageViews 等插件的做法）。
 * 同时兼容 Typecho 内置的"按来源页校验"约定。
 */
$csrfToken = $security->getToken($request->getRequestUrl());

$tokenValid = static function () use ($request, $security, $csrfToken): bool {
    $token = (string) $request->get('_');

    if ('' === $token) {
        return false;
    }

    if (hash_equals($csrfToken, $token)) {
        return true;
    }

    $referer = $request->getReferer();

    return null !== $referer && hash_equals($security->getToken($referer), $token);
};

if ($request->isPost()) {
    if (!$tokenValid()) {
        $response->goBack();
        exit;
    }

    $do = (string) $request->get('do');

    if ('cleanup' === $do) {
        $deleted = $pluginClass::cleanup(100000);
        \Widget\Notice::alloc()->set(_t('已清理 %d 个过期缓存文件', $deleted), 'success');
    } elseif ('flush' === $do) {
        $deleted = $pluginClass::flush();
        \Widget\Notice::alloc()->set(_t('已清空全部缓存，共删除 %d 个文件', $deleted), 'success');
    }

    // PRG：提交后重定向，避免刷新页面时重复执行清理
    $response->redirect($panelUrl);
    exit;
}

/** 进入面板时无条件清理一次，保证这里看到的统计是准的 */
$pluginClass::maybeCleanup(true);

$settings = $pluginClass::settings();
$stats = $pluginClass::stats();
$cacheDir = $pluginClass::cacheDir();
$diagnostics = $pluginClass::diagnostics();
$settingsUrl = $options->adminUrl('options-plugin.php?config=' . Plugin::NAME, true);

/** 字节数转可读大小 */
$humanSize = static function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;

    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return round($bytes, $index > 0 ? 2 : 0) . ' ' . $units[$index];
};

include __TYPECHO_ROOT_DIR__ . '/admin/header.php';
include __TYPECHO_ROOT_DIR__ . '/admin/menu.php';
?>
<main class="main">
    <div class="body container">
        <?php include __TYPECHO_ROOT_DIR__ . '/admin/page-title.php'; ?>

        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 col-tb-8">
                <?php if (!$diagnostics['writable']): ?>
                    <div class="message error">
                        <p><strong><?php _e('缓存目录不可写，插件当前以「纯代理」模式运行'); ?></strong></p>
                        <p><?php echo htmlspecialchars($diagnostics['hint']); ?></p>
                        <p class="description">
                            <?php _e('路径：'); ?><code><?php echo htmlspecialchars($diagnostics['dir']); ?></code><br>
                            <?php _e('目录属主：'); ?><?php echo htmlspecialchars(
                                '' !== $diagnostics['owner'] ? $diagnostics['owner'] : '未知'
                            ); ?><br>
                            <?php _e('PHP 运行用户：'); ?><?php echo htmlspecialchars(
                                '' !== $diagnostics['phpUser'] ? $diagnostics['phpUser'] : '未知'
                            ); ?>
                        </p>
                        <p class="description">
                            <?php _e('纯代理模式下头像仍能正常显示，但每次访问都要回源头像源，'
                                . '无法享受缓存加速。修好目录权限后本提示会自动消失，无需重新启用插件。'); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <h3><?php _e('缓存概况'); ?></h3>

                <table class="typecho-list-table">
                    <tbody>
                    <tr>
                        <th width="30%"><?php _e('已缓存头像'); ?></th>
                        <td><?php echo (int) $stats['files']; ?> <?php _e('个'); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('其中已过期'); ?></th>
                        <td><?php echo (int) $stats['expired']; ?> <?php _e('个'); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('占用磁盘'); ?></th>
                        <td><?php echo htmlspecialchars($humanSize((int) $stats['bytes'])); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('缓存有效期'); ?></th>
                        <td>
                            <?php echo (int) $settings['ttl']; ?> <?php _e('秒'); ?>
                            <small>（<?php echo round(((int) $settings['ttl']) / 86400, 1); ?> <?php _e('天'); ?>）</small>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('回源超时'); ?></th>
                        <td><?php echo (int) $settings['timeout']; ?> <?php _e('秒'); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('头像源'); ?></th>
                        <td><?php echo '' !== $settings['server']
                                ? htmlspecialchars($settings['server'])
                                : 'https://secure.gravatar.com'; ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('自动清理'); ?></th>
                        <td><?php echo '1' === $settings['autoCleanup']
                                ? _t('开启（每次请求 %d%% 概率）', (int) $settings['cleanupRatio'])
                                : _t('关闭'); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('缓存目录'); ?></th>
                        <td><code><?php echo htmlspecialchars($cacheDir); ?></code></td>
                    </tr>
                    </tbody>
                </table>

                <h3><?php _e('手动维护'); ?></h3>

                <form method="post" action="<?php echo htmlspecialchars($panelUrl); ?>">
                    <input type="hidden" name="_"
                           value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <ul class="typecho-option">
                        <li>
                            <label class="typecho-label"><?php _e('清理操作'); ?></label>
                            <button type="submit" name="do" value="cleanup" class="btn primary">
                                <?php _e('清理过期缓存'); ?>
                            </button>
                            <button type="submit" name="do" value="flush" class="btn"
                                    onclick="return confirm('<?php echo htmlspecialchars(
                                        _t('确定要清空全部头像缓存吗？清空后首次访问会重新从 Gravatar 下载，首屏可能变慢。')
                                    ); ?>');">
                                <?php _e('清空全部缓存'); ?>
                            </button>
                            <p class="description">
                                <?php _e('「清理过期缓存」只删除已超过有效期的文件；「清空全部缓存」会删除所有头像，'
                                    . '之后访问时重新下载。'); ?>
                            </p>
                        </li>
                    </ul>
                </form>
            </div>

            <div class="col-mb-12 col-tb-4">
                <h3><?php _e('使用说明'); ?></h3>

                <ul class="typecho-option">
                    <li>
                        <p class="description">
                            <?php _e('插件启用后，Typecho 生成的 Gravatar 地址会被自动改写为本站的'
                                . ' <code>/gravatar-cache/</code> 地址，评论头像、作者头像与后台头像一起生效，'
                                . '无需修改主题。'); ?>
                        </p>
                    </li>
                    <li>
                        <p class="description">
                            <?php _e('头像第一次被访问时会从 Gravatar 下载并缓存到本地；'
                                . '之后所有访客都直接由本站返回，不再依赖 gravatar.com 的连通性。'); ?>
                        </p>
                    </li>
                    <li>
                        <p class="description">
                            <?php _e('缓存过期后不会立刻删除，而是在下一次有人访问该头像时重新回源刷新；'
                                . '长期无人访问的过期文件由自动清理回收。'); ?>
                        </p>
                    </li>
                    <li>
                        <p class="description">
                            <a href="<?php echo htmlspecialchars($settingsUrl); ?>"><?php _e('修改插件设置'); ?></a>
                        </p>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</main>
<?php include __TYPECHO_ROOT_DIR__ . '/admin/common-js.php'; ?>
<?php include __TYPECHO_ROOT_DIR__ . '/admin/footer.php'; ?>
