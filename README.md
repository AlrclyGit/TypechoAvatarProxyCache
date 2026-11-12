# GravatarCache

Typecho 1.3 插件：把 Gravatar 头像改为**本站代理输出 + 服务器本地缓存**，解决 gravatar.com 访问不稳定导致的评论头像加载失败、页面长时间白屏问题。

- 适用：Typecho **1.3.0+**，PHP **8.5**（同时兼容 8.0 ~ 8.4）
- 依赖：无。有 curl 扩展时用 curl 回源，没有则自动退回 PHP 流封装
- 不需要 GD / fileinfo / imagick 等图片扩展

## 工作原理

1. 插件启用时，在 Typecho 路由表最前面注册 `/gravatar-cache/[hash]` 路由。
2. 请求早期定义 `__TYPECHO_GRAVATAR_PREFIX__` 常量，把内核 `Typecho\Common::gravatarUrl()` 生成的地址整体改写到本站路由。
   - 因为改的是内核函数，**前台评论头像、文章作者头像、后台用户头像全部一起生效**，不需要改主题。
3. 浏览器请求 `/gravatar-cache/<md5>?s=64&r=X&d=mm`：
   - 命中未过期缓存 → 直接返回本地文件（带 `ETag`，可协商 304）
   - 未命中 / 已过期 → 回源 gravatar.com，落盘后返回
   - 所有头像源都失败 → 先用 DiceBear 按邮箱生成兜底头像；DiceBear 也失败时优先用过期旧缓存，最后返回 1×1 透明占位图，**保证不出现破损图片**

### 缓存布局

```
usr/plugins/GravatarCache/cache/
  ab/cd/abcd....img    ← 图片字节
  ab/cd/abcd....json   ← 元数据（MIME / 过期时间）
  ab/cd/abcd....fail   ← 回源失败标记（冷却 60 秒）
```

按两级目录分片，避免单目录堆积几十万文件。缓存 key 包含 **邮箱 hash + 尺寸 + 评级 + 默认头像 + 头像源**，不同尺寸不会串图。

## 安装

1. 把 `GravatarCache` 整个目录上传到 `usr/plugins/`：

   ```
   usr/plugins/GravatarCache/Plugin.php
   usr/plugins/GravatarCache/Widget/Avatar.php
   usr/plugins/GravatarCache/manage.php
   ```

2. 确保缓存目录可写。插件启用时会自动创建 `cache/`，正常情况下无需手动处理。

   如果后台提示**缓存目录不可写**，说明 PHP 运行用户不是该目录的属主（Docker 部署、1Panel、宝塔上很常见）。此时有两条路：

   **方案 A（推荐）：改目录属主**（在宿主机上用 root 执行）

   ```bash
   chown -R www-data:www-data /opt/1panel/www/sites/你的站点/index/usr/plugins/GravatarCache
   ```

   > 属主名以 PHP 实际运行用户为准（面板提示里会写出来），常见是 `www-data`、`nginx`、`apache`、`www`。
   > 注意 `chmod 755` 解决不了这个问题 —— 属于 root 的 755 目录对其他人只是「可读可执行」，不能写入。

   **方案 B：把缓存目录挪到别处**

   后台 → 插件 → GravatarCache 设置 → **缓存目录**，填一个 PHP 用户可写的位置，例如：

   ```
   usr/cache/gravatar
   ```

   相对路径按站点根目录解析，也可以直接填绝对路径。目录不必预先存在，插件会自动创建。

   > 即使权限没修好，插件也不会报错停摆 —— 它会自动降级为「纯代理」模式：头像照常显示，只是每次都回源头像源，不落盘缓存。后台面板顶部会持续提示，权限修好后提示自动消失。

3. 后台 → 插件 → 启用 **GravatarCache**。

   启用后，后台 **控制台 → 头像缓存** 中会出现本插件的面板，可查看命中情况、磁盘占用，并手动清理。

   > 面板放在「控制台」菜单下，遵循 Typecho 官方文档对插件面板的建议：优先使用已有的顶级菜单（`1` 控制台、`2` 撰写、`3` 管理、`4` 设置），只有确实需要独立入口时才用 `Helper::addMenu()` 新建顶级菜单。见 [路由、Action 与后台面板](https://docs.typecho.org/develop/plugin/routes-actions/)。

## 设置项

| 设置 | 默认 | 说明 |
| --- | --- | --- |
| 缓存有效期 | 604800 秒（7 天） | 超过后下一次访问时回源刷新 |
| 回源超时 | 3 秒 | 头像源不稳定时调小可让页面更快返回 |
| 定义头像源 | `https://cn.cravatar.com,https://secure.gravatar.com` | 多个源用 `,` 分隔，按填写顺序依次获取，成功一个就停止 |
| 兜底头像样式 | `waves` | 所有头像源都失败时，用 DiceBear 按邮箱生成兜底头像，这里指定样式名 |
| 回源失败时 | 继续用过期缓存 | 也可改为输出空白占位图 |
| 自动清理 | 开启 | 前台请求按概率触发过期文件清理 |
| 清理触发概率 | 5% | 建议 1~10，太高会频繁扫目录 |
| 缓存目录 | 空（插件下 `cache/`） | 插件目录不可写时改到这里，支持相对站点根目录或绝对路径 |

## 过期与清理机制

**惰性过期**：缓存不会在到达过期时间时立刻删除，而是下次有人访问该头像时才发现过期并回源刷新。这样冷门头像不会因为过期而被反复删除重建。

**主动回收**：长期没人访问的过期文件靠清理回收，三个触发点：

1. 前台请求 —— 按 `清理触发概率`（默认 5%）触发一次，单次最多处理 500 个文件，避免拖慢请求
2. 进入后台面板 —— 无条件清理一次
3. 插件启用时 —— 清理一次

清理同时会回收异常退出残留的 `.tmp` 文件与失效的 `.fail` 标记。

## 服务器配置

插件会自动在缓存目录里写一个 `.htaccess`（Apache 下禁止直接访问）。**nginx 用户**请自行在站点配置里加上：

```nginx
location ^~ /usr/plugins/GravatarCache/cache/ {
    deny all;
}
```

> 说明：缓存目录里只有图片和元数据，不是安全敏感内容；这条规则只是不让目录内容被直接列举。头像本身是通过 `/gravatar-cache/` 路由输出的，不受影响。

## 伪静态要求

插件使用 Typecho 标准路由，**依赖伪静态**。如果站点没开 rewrite，内核生成的地址会带上 `index.php`，插件已按 `$options->index` 拼接，两种模式都能工作。建议开启伪静态获得更干净的 URL：

```
/gravatar-cache/5d41402abc4b2a76b9719d911017c592?s=64&r=X
```

## 主题里手动调用

一般不需要。如果主题想自己控制头像输出，可以：

```php
// 用内核方法（推荐），地址已经被插件接管
$this->gravatar(64);

// 或者直接生成一个地址
echo \TypechoPlugin\GravatarCache\Plugin::avatarUrl($mail, 64, 'X', 'mm');
```

## 常见问题

**Q：启用后头像还是加载很慢？**
第一次访问某个头像必然要回源一次。之后才会走缓存。如果大量头像首次都很慢，说明当前的头像源太差，建议在「定义头像源」里把更快的源排在前面，或追加一个镜像源。

**Q：磁盘占用会不会失控？**
单张 64×64 头像通常 2~8 KB。一万个评论头像约几十 MB。过期文件会被自动清理，也可以在面板里手动清空。

**Q：换域名 / 换目录后头像 404？**
头像地址由 `$options->index` 动态拼接，换域名不影响。但 `Plugin::NAME` 与插件目录名必须一致（默认 `GravatarCache`），改目录名要同步改常量。

**Q：后台提示「缓存目录不可写」怎么办？**

这是部署环境的属主问题，不是插件故障。PHP 以某个用户身份运行，而插件目录由另一个用户（或宿主机 root）创建，所以写不进去。

正确的解法是 **chown** 而不是 chmod：

```bash
chown -R www-data:www-data /path/to/usr/plugins/GravatarCache
```

`chmod 755` 无效 —— 属于 root 的 755 目录，对 www-data 只是可读可执行，不可写。

如果改属主不方便，也可以在插件设置里把 **缓存目录** 改到 PHP 用户本来就可写的位置（如 `usr/cache/gravatar`）。

无论修不修，插件都不会因此停摆：写不了缓存时它自动退化为纯代理模式，头像照常显示，只是每次都回源头像源。

**Q：禁用插件后缓存还在吗？**
在。禁用只撤掉路由、面板和钩子，缓存文件保留，重新启用后立刻可用。要彻底清理请先在面板点「清空全部缓存」。

**Q：从旧版本升级后，菜单残留 / 位置没变怎么办？**

旧版本（面板挂在独立顶级菜单或「设置」下）升级到本版后，重新启用一次即可自动清理：`registerPanel()` 会先遍历 `panelTable` 里**所有**菜单索引，删掉本插件的历史登记（含旧版 `addMenu()` 留下的空顶级菜单），再重新注册到「控制台」。

如果仍然残留，说明数据库里的 `panelTable` 选项损坏了。可以在 `typecho_options` 表中找到 `name = 'panelTable'` 那条，确认 `file` 数组里是否还有 `GravatarCache%2Fmanage.php`：

```sql
SELECT value FROM typecho_options WHERE name = 'panelTable';
```

## 目录结构

```
usr/plugins/GravatarCache/
├── Plugin.php           插件入口，生命周期 / 设置 / 缓存逻辑
├── Widget/
│   └── Avatar.php       头像输出组件，由路由调用
├── manage.php           后台管理面板
└── cache/               缓存目录（运行时创建）
```
