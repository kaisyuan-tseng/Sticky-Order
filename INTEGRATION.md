# Sticky Order · 前端对接说明

一份给**任何前端**看的接入文档。插件已经在服务端把置顶队列应用到前台查询和 REST 文章列表；默认情况下，前端**只需要按接口返回的顺序渲染**，不需要读字段、不需要排序。

---

## 1. 最小接入（TL;DR）

| 前端要做的 | 说明 |
|---|---|
| 按接口返回顺序渲染 | 不需要额外排序；队列已经生效 |
| 删除前端已有的 sticky/featured 排序 | 如果前端按日期或自己的字段重排，会覆盖服务端顺序 |
| 静态站/ISR 做缓存失效 | 保存排序后触发重新验证或重建（见 §6） |
| 可选：读两个字段 | 只有需要队列 key 或位置的自定义 UI 才需要（见 §4） |

**不需要**：新端点、新的请求参数、新的查询参数、额外的 REST 请求、前端排序库。

---

## 2. 插件在服务端做了什么

- **成员**仍由 WordPress 原生 sticky 决定：编辑页勾选「置顶」。
- **顺序**由后台「Sticky Order」页面拖拽保存。
- **应用范围**：前台 `WP_Query` 列表 + REST 文章集合。
- **结果**：队列内的置顶文章按保存顺序排在最前；其余文章保持原查询顺序；不属于任何分组的孤儿置顶排在队尾。
- **分组顺序**：未过滤的列表按后台分组顺序排列置顶文章，组内再按队列顺序；前端按 `sticky_order_queue` 过滤即可得到单栏顺序。
- **时机**：排序发生在 SQL 的 `LIMIT/OFFSET` 之前，因此分页、`found_posts`、REST 的 `X-WP-Total` / `X-WP-TotalPages` 都一致。
- **默认不重排**：后台列表、`get_posts()`（默认 `suppress_filters => true`）、admin-ajax 查询。
- **退出机制**：`sticky_order_apply_to_query` 过滤器，或 `WP_Query` 参数 `sticky_order_skip => true`。

---

## 3. REST 请求怎么写

现有请求不用改：

```http
GET /wp-json/wp/v2/posts?per_page=12
GET /wp-json/wp/v2/posts?sticky=true&per_page=20
GET /wp-json/wp/v2/posts?categories=123&per_page=12
```

三种都已经是队列顺序：

- `/wp/v2/posts`：队列内的置顶文章在前，其余按原查询顺序。
- `sticky=true`：只返回置顶文章，按队列顺序；该参数只对 `post` 类型有效。
- `categories=...`：该分类下的置顶文章在前，其余按原查询顺序。

### 3.1 `_fields`

用 `_fields` 裁剪响应时：

- 不需要字段：`_fields=id,slug,title` 即可，排序不受影响。
- 需要字段：把两个字段列进去，例如：

```text
_fields=id,slug,title,date,sticky_order_position,sticky_order_queue
```

### 3.2 `_embed`

嵌入响应里两个自定义字段可能因为 context 是 `view/edit` 而被过滤掉；**不要从 `_embedded` 里读字段**。列表顺序本身不受影响。

### 3.3 分页

- `per_page` 最大 100；队列顺序跨页连续，不需要在前端二次排序。
- 如果前端需要完整的置顶队列（例如全部 featured 槽位），按页拉完再按返回顺序拼接，不要按日期重排。

### 3.4 自定义 post type

REST 字段和排序只对 `sticky_order_post_types` 返回的类型生效，默认只有 `post`。如果前端查询自定义 post type：

1. 在站点侧把该类型加进 `sticky_order_post_types`；
2. 确保该类型 `show_in_rest => true`；
3. REST 的 `sticky=true` 参数不适用于 CPT，需要用 `include` / 自定义查询取置顶集合。

---

## 4. 两个可选字段

| 字段 | 类型 | 含义 |
|---|---|---|
| `sticky_order_position` | `number \| null` | 队列内位置，0 起；不在队列为 `null` |
| `sticky_order_queue` | `string \| null` | 所属队列 key；单队列模式为 `default` |

使用规则：

- `null` 表示未置顶、未发布或不属于任何分组，**不要当成 0**。
- position 只在同一个 queue 内有意义，不能跨 queue 比较。
- 一篇文章只进一条队列（插件取第一个命中的分组分类）；多分类文章请按 `sticky_order_queue` 过滤，不要只按分类过滤。
- 孤儿置顶（置顶但不属于任何分组）：字段为 `null`，服务端会把它排在队列文章之后；如果前端过滤 `queue !== null`，它不会出现在队列列表里，这是预期行为。
- 字段只读；排序仍只能在 WP 后台做。

---

## 5. 前端代码示例

### 5.1 直接渲染（推荐）

```ts
type StickyPost = {
  id: number;
  slug: string;
  title: { rendered: string };
  sticky_order_position: number | null;
  sticky_order_queue: string | null;
};

const res = await fetch(
  `${WP_URL}/wp-json/wp/v2/posts?per_page=12`,
  { next: { revalidate: 60, tags: ['sticky-order'] } },
);

const posts = (await res.json()) as StickyPost[];

// 直接按数组顺序渲染；不要再按 date 排序。
```

### 5.2 按队列拆分

```ts
const guides = posts.filter((post) => post.sticky_order_queue === 'guides');
const applications = posts.filter((post) => post.sticky_order_queue === 'applications');
```

过滤不会破坏顺序；同一 queue 内的先后已经由服务端排好。

### 5.3 只请求某条队列

如果每个栏目对应一个分类：

```http
GET /wp-json/wp/v2/posts?categories=123&per_page=12
```

返回顺序已经是该分类下的队列顺序，不需要再按 `sticky_order_position` 排。

---

## 6. 缓存与静态构建

插件保存排序时只更新 option，**不会清前端缓存、不会触发重建**。静态站/ISR 需要自己接一下：

- 给队列请求加缓存 tag（如 `sticky-order`）；
- 在 WordPress 侧监听保存，调用前端 revalidate API 或 deploy hook；
- 或者使用较短的 revalidate TTL；
- 如果用了 CDN/边缘缓存，记得一并清理。

WP 侧示例：

```php
add_action(
	'updated_option',
	static function ( $option ) {
		if ( sticky_order_option_name() !== $option ) {
			return;
		}

		wp_remote_post(
			'https://frontend.example.com/api/revalidate',
			array(
				'blocking' => false,
				'timeout'  => 5,
				'headers'  => array( 'Authorization' => 'Bearer ' . REVALIDATE_TOKEN ),
				'body'     => array( 'tag' => 'sticky-order' ),
			)
		);
	}
);
```

注意：option 名可通过 `sticky_order_option_name` 过滤器修改，所以用辅助函数判断，不要写死。`REVALIDATE_TOKEN` 请放在 `wp-config.php` 或环境变量里，不要提交到仓库。

---

## 7. 其他接入方式

### 7.1 传统主题 / PHP

- 主查询和普通 `WP_Query` 列表已经自动按队列排序，主题不需要改。
- `get_posts()` 默认 `suppress_filters => true`，不会重排；需要队列顺序时改用 `WP_Query` 并设置 `suppress_filters => false`。
- 直接读队列数据：

```php
Sticky_Order_Queue::position_of( get_the_ID() ); // int|null
Sticky_Order_Queue::queue_of( get_the_ID() );    // string|null
Sticky_Order_Queue::resolve();                   // queues / ranks / groups / posts / single
Sticky_Order_Queue::ordered_ids();               // 置顶 ID 的完整顺序
```

### 7.2 GraphQL

插件没有注册 GraphQL 字段。用 WPGraphQL 的话：

- 继续用 REST 取列表顺序；或
- 自己给 WPGraphQL 加 resolver，调用 `Sticky_Order_Queue::position_of()` / `queue_of()`。

### 7.3 跨域与 REST 可用性

- 浏览器跨域直连 REST 时确认 CORS；服务端 fetch 不受影响。
- 固定链接为「朴素」时，确认 `/wp-json/` 路由可用，或改用 `?rest_route=/wp/v2/posts`。
- 安全插件如果拦截 `/wp-json/`，把对应路由加入白名单。

---

## 8. 常见坑

- **fetch 完又按日期 sort**：会覆盖服务端顺序，必须删除。
- **`_fields` 没包含字段**：只有前端需要读字段时才需要列进去；排序本身不受影响。
- **从 `_embedded` 读字段**：可能读不到，直接请求文章列表。
- **把 `sticky_order_position` 当全局排名**：它是每个 queue 内的位置。
- **把 `null` 当 0**：未入队文章会被错误地排到最前。
- **只按分类过滤**：多分类文章可能属于另一条队列；按 `sticky_order_queue` 过滤。
- **每页单独排序**：队列顺序跨页连续，按返回顺序拼接即可。
- **依赖 REST 的 `ignore_sticky` 参数控制队列**：插件会在支持的类型上统一应用队列；要退出用 `sticky_order_apply_to_query` 或 `sticky_order_skip`。
- **后台/`get_posts()`/admin-ajax 没生效**：这些默认不重排；前端走 REST 即可。
- **保存后没变化**：优先检查静态构建/CDN/REST 缓存。

---

## 9. 验收清单

- [ ] `/wp-json/wp/v2/posts` 的前几篇顺序与后台队列一致
- [ ] `?sticky=true` 只返回置顶文章，且顺序与队列一致
- [ ] 分类请求中，该分类的置顶文章排在最前
- [ ] 第 2 页继续接续第 1 页，没有重复或遗漏
- [ ] 前端没有任何按日期 / 自己的字段的二次排序
- [ ] 使用 `_fields` 时，列表顺序仍然正确
- [ ] 后台保存新顺序后，前端缓存/构建产物已重新验证
- [ ] WordPress 后台文章列表保持原生顺序
- [ ] `get_posts()` 调用保持原生顺序（如果前端/主题用到）
- [ ] 多分类置顶文章按 `sticky_order_queue` 过滤时只出现在它被分配的那条队列

---

## 10. 本站映射

当前站点在 `wp-content/mu-plugins/sticky-order-config.php` 里自动从 WordPress 分类生成队列：
分类名作为后台栏目标题，顺序按分类创建顺序；空分类也会显示（方便先建栏目再置顶文章），
只跳过空的 Uncategorized 系统术语。新增分类会自动出现在后台；如果前端要展示新栏目，
再补对应的 slug 映射。

当前三条队列：

| queue key | 后台分栏 | 前台位置 |
|---|---|---|
| `guides` | Guides | `/resources/guides` |
| `applications` | Applications | `/resources/applications` |
| `cases` | Cases | `/resources/cases` |

前端对应位置：

- `src/lib/wordpress.ts` 的 `CATEGORY_BINDING`
- `src/lib/resources-source.ts` 的 `headerFeatured` / `featuredRank`

如果后台新增/改名分组，记得同步更新前端的 queue key 映射。
